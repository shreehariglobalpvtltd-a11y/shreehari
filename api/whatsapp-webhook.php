<?php
/**
 * =====================================================================
 *  api/whatsapp-webhook.php — inbound WhatsApp bot (Twilio).
 *
 *  Point your Twilio WhatsApp Sandbox / Sender "WHEN A MESSAGE COMES IN"
 *  webhook (HTTP POST) at:
 *
 *      https://yourdomain.com/api/whatsapp-webhook.php
 *
 *  A customer messages your WhatsApp number with their PNR and instantly
 *  gets back the booking status — plus the ticket PDF when the booking is
 *  confirmed and they message from the number used at booking.
 *
 *  Security:
 *   - Every request is checked against the Twilio X-Twilio-Signature
 *     header (HMAC-SHA1 over the URL + sorted params, keyed by the auth
 *     token). A forged request is rejected with 403.
 *   - Full passenger detail + the PDF are only released to the sender when
 *     their WhatsApp number matches the booking's contact number — a
 *     guessed PNR from a stranger's phone leaks nothing but the status.
 *   - The endpoint always answers Twilio with valid TwiML and HTTP 200 on
 *     success (and an empty <Response> on error) so it never retry-storms.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';

/**
 * Build one <Message> block, XML-escaping the body and optional media URL.
 */
function wa_message(string $body, ?string $mediaUrl = null): string
{
    $out = '<Message><Body>' . htmlspecialchars($body, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</Body>';
    if ($mediaUrl !== null && $mediaUrl !== '') {
        $out .= '<Media>' . htmlspecialchars($mediaUrl, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</Media>';
    }
    return $out . '</Message>';
}

/**
 * Emit a TwiML document (already-escaped message blocks) and stop.
 */
function wa_reply(string ...$messages): never
{
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/xml; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Response>' . implode('', $messages) . '</Response>';
    exit;
}

/**
 * Validate the Twilio request signature. Twilio signs the exact webhook URL
 * with every POST parameter appended (sorted by key, no separators),
 * HMAC-SHA1 keyed by the account auth token, base64-encoded.
 */
function wa_validate_signature(string $authToken): bool
{
    $sig = (string) ($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '');
    if ($sig === '') {
        return false;
    }

    $override = Settings::getString('twilio_webhook_url', '');
    if ($override !== '') {
        $url = $override;
    } else {
        $scheme = Security::isHttps() ? 'https' : 'http';
        $url    = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '') . (string) ($_SERVER['REQUEST_URI'] ?? '');
    }

    $params = $_POST;
    ksort($params);

    $data = $url;
    foreach ($params as $key => $value) {
        $data .= $key . (is_array($value) ? implode('', $value) : (string) $value);
    }

    $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));
    return hash_equals($expected, $sig);
}

try {
    $company = Settings::getString('company_name', APP_NAME);
    $phone   = Settings::officePhone();

    // A GET (e.g. someone opening the URL, or a Twilio console probe).
    if (!Security::isPost()) {
        wa_reply(wa_message(
            "🙏 Namaste! This is the " . $company . " ticket assistant.\n"
            . "Send your booking PNR (looks like SHG-XXXX-XXXX-XXXX) to check your ticket."
        ));
    }

    // Reject forged callers. This FAILS CLOSED: with no auth token configured
    // a request cannot be proven to come from Twilio, so it is rejected rather
    // than trusted — otherwise anyone could POST a victim's number + a guessed
    // PNR and read back the booking detail. Configure twilio_auth_token before
    // pointing Twilio's webhook here (the setup guide does this first).
    $authToken = Settings::getString('twilio_auth_token', '');
    if ($authToken === '' || !wa_validate_signature($authToken)) {
        Logger::warning('Twilio webhook rejected', [
            'ip'     => Security::clientIp(),
            'reason' => $authToken === '' ? 'no auth token configured' : 'signature mismatch',
        ], 'whatsapp');
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Invalid signature.');
    }

    $from = (string) ($_POST['From'] ?? '');       // e.g. "whatsapp:+9198XXXXXXXX"
    $body = trim((string) ($_POST['Body'] ?? ''));
    $senderDigits = normalisePhone($from);

    // Abuse guard, keyed on the sender (falls back to IP).
    Security::requireRateLimit('wa_bot', $senderDigits !== '' ? $senderDigits : Security::clientIp(), 20, 60);

    // Pull a PNR out of the message text.
    $pnr = '';
    if (preg_match('/SHG-[A-Z0-9]+(?:-[A-Z0-9]+)+/i', $body, $m)) {
        $pnr = strtoupper($m[0]);
    }

    // No PNR — is this a booking REQUEST rather than a status check?
    //
    // "bhai 2 seat chahiye nepal 15th" is a sale waiting to happen, not a
    // typo'd PNR. TicketBrain parses it into the same option set the desk
    // would type and parks it in the drafts queue; the desk confirms it in
    // one tap and the ticket goes out from there.
    //
    // What this reply must NOT do is imply a booking exists: no seat is held
    // and no fare is fixed until a human confirms. So it acknowledges what we
    // understood and says a person will confirm — nothing more.
    if ($pnr === '' || !Security::isValidPnr($pnr)) {
        $draft = null;
        try {
            require_once INCLUDE_PATH . '/ticketbrain.php';
            $draft = TicketBrain::capture($senderDigits, $body, 'whatsapp');
        } catch (Throwable $e) {
            Logger::exception($e);          // a parser hiccup must never eat the reply
        }

        if ($draft !== null) {
            $got = [];
            if (($draft['seats'] ?? 0) > 0) {
                $got[] = (int) $draft['seats'] . ' seat' . ((int) $draft['seats'] === 1 ? '' : 's');
            }
            if (($draft['date'] ?? '') !== '') {
                $got[] = formatDate((string) $draft['date'], 'D, j M');
            }
            if (($draft['direction'] ?? '') !== '') {
                $got[] = QuickTicket::directionLabel((string) $draft['direction']);
            }
            if (($draft['boarding'] ?? '') !== '') {
                $got[] = 'from ' . Boarding::stopDisplay((string) $draft['boarding'])['name'];
            }

            wa_reply(wa_message(
                "🙏 Namaste! Got your request.\n\n"
                . ($got !== [] ? "I understood: " . implode(' · ', $got) . "\n\n" : '')
                . "Our desk will confirm the bus, seat and fare and send your ticket here shortly."
                . (($draft['date'] ?? '') === '' ? "\n\nWhich date do you want to travel?" : '')
                . ($phone !== '' ? "\n\nIn a hurry? Call " . $phone . "." : '')
            ));
        }

        wa_reply(wa_message(
            "🙏 Namaste! I'm the " . $company . " ticket assistant.\n\n"
            . "Send your booking PNR (looks like SHG-XXXX-XXXX-XXXX) and I'll reply with your ticket "
            . "status and PDF instantly.\n\n"
            . "Want to travel? Just tell me the date, how many seats and where you board — "
            . "for example \"2 seat Nepal 15 Sep, Mehsana\".\n\n"
            . ($phone !== '' ? "Need a person? Call " . $phone . "." : "")
        ));
    }

    $detail = BookingService::detail($pnr);
    if ($detail === null) {
        wa_reply(wa_message(
            "❌ No booking found for PNR " . $pnr . ".\n"
            . "Please double-check the code and send it again."
        ));
    }

    // Only the booking's own number gets full detail + the PDF.
    $owns = $senderDigits !== '' && normalisePhone((string) $detail['contact_phone']) === $senderDigits;
    $status = strtoupper((string) $detail['status']);

    if (!$owns) {
        wa_reply(wa_message(
            "🔒 Booking " . $detail['pnr'] . " — status: " . $status . ".\n"
            . "For full details and your ticket PDF, message me from the mobile number used at booking."
        ));
    }

    // Owner — build the full summary.
    $leg   = $detail['legs'][0] ?? [];
    $seats = implode(', ', array_map(
        static fn ($s) => Seats::displayLabel((string) $s, 'sleeper', (string) ($detail['booking_mode'] ?? 'sharing')),
        $detail['seats']
    ));

    $lines = [
        "🎫 " . $company,
        "PNR: " . $detail['pnr'],
        "Status: " . $status,
    ];
    if ($leg !== []) {
        $lines[] = "Route: " . ($leg['from_city'] ?? '') . " -> " . ($leg['to_city'] ?? '');
        $date = formatDate((string) ($leg['travel_date'] ?? ''), 'D, j M Y');
        if ($date !== '') {
            $lines[] = "Date: " . $date;
        }
        $dep = substr((string) ($leg['dep_time'] ?? ''), 0, 5);
        if ($dep !== '') {
            $lines[] = "Departs: " . $dep;
        }
    }
    if ($seats !== '') {
        $lines[] = "Seats: " . $seats;
    }
    $lines[] = "Passengers: " . count($detail['passengers']);
    $lines[] = "Total: " . inr((float) $detail['total_amount']);

    $mediaUrl = null;
    if ($detail['status'] === 'confirmed') {
        // PNG first (5 Sep 2026): the image opens straight in WhatsApp, no
        // PDF viewer needed. This reply still sent downloadUrl() — the PDF —
        // and was missed by the PNG-first migration.
        $ticketUrl = Ticket::imageUrl((string) $detail['pnr']);

        if (Settings::getBool('whatsapp_send_pdf', true)) {
            $mediaUrl = $ticketUrl;
            $lines[]  = "\n✅ Your e-ticket is attached below. Safe journey!";
        } else {
            // Media attachments are switched off — a Twilio TRIAL account
            // rejects them outright ("trial accounts have limited parameter
            // access"), and an attachment Twilio refuses would take the whole
            // reply down with it. Send the keyed links instead so the customer
            // can still open and save the ticket.
            $lines[] = "\n✅ Your e-ticket: " . $ticketUrl;
            $lines[] = "Print copy (PDF): " . Ticket::downloadUrl((string) $detail['pnr']);
            $lines[] = "Safe journey!";
        }
    } elseif ($detail['status'] === 'pending') {
        $lines[] = "\n⏳ Payment is being verified. Your ticket will arrive here the moment it's confirmed.";
    } elseif (in_array($detail['status'], ['cancelled', 'rejected'], true)) {
        $lines[] = "\nThis booking is " . strtolower($status) . ". Contact us if that's unexpected.";
    }

    wa_reply(wa_message(implode("\n", $lines), $mediaUrl));

} catch (Throwable $e) {
    // Never leak internals to Twilio — acknowledge quietly so it doesn't retry.
    Logger::exception($e);
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/xml; charset=utf-8');
    }
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
    exit;
}
