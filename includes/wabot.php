<?php
/**
 * =====================================================================
 *  includes/wabot.php — the inbound WhatsApp ticket assistant.
 *
 *  Pure "message in -> reply out" logic, shared by both transports:
 *    - api/whatsapp-webhook.php   (Twilio, replies as TwiML)
 *    - whatsapp/webhook.php       (Meta Cloud API, replies via Graph API)
 *  Moved out of api/whatsapp-webhook.php on 18 Sep 2026 unchanged, so the
 *  two channels can never drift apart.
 *
 *  Security (unchanged): full passenger detail + the ticket image are only
 *  released when the sender's WhatsApp number matches the booking's contact
 *  number — a guessed PNR from a stranger's phone leaks only the status.
 *  The caller must have authenticated the request (signature) first.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';

final class WaBot
{
    /**
     * Build the reply for one inbound message.
     *
     * @param string $from raw sender ("whatsapp:+9198…", "+9198…" or "9198…")
     * @param string $body message text
     * @return array{text: string, media: ?string}
     */
    public static function reply(string $from, string $body): array
    {
        $company      = Settings::getString('company_name', APP_NAME);
        $phone        = Settings::officePhone();
        $body         = trim($body);
        $senderDigits = normalisePhone($from);

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

                return self::out(
                    "🙏 Namaste! Got your request.\n\n"
                    . ($got !== [] ? "I understood: " . implode(' · ', $got) . "\n\n" : '')
                    . "Our desk will confirm the bus, seat and fare and send your ticket here shortly."
                    . (($draft['date'] ?? '') === '' ? "\n\nWhich date do you want to travel?" : '')
                    . ($phone !== '' ? "\n\nIn a hurry? Call " . $phone . "." : '')
                );
            }

            return self::out(
                "🙏 Namaste! I'm the " . $company . " ticket assistant.\n\n"
                . "Send your booking PNR (looks like SHG-XXXX-XXXX-XXXX) and I'll reply with your ticket "
                . "status and PDF instantly.\n\n"
                . "Want to travel? Just tell me the date, how many seats and where you board — "
                . "for example \"2 seat Nepal 15 Sep, Mehsana\".\n\n"
                . ($phone !== '' ? "Need a person? Call " . $phone . "." : "")
            );
        }

        $detail = BookingService::detail($pnr);
        if ($detail === null) {
            return self::out(
                "❌ No booking found for PNR " . $pnr . ".\n"
                . "Please double-check the code and send it again."
            );
        }

        // Only the booking's own number gets full detail + the ticket.
        $owns   = $senderDigits !== '' && normalisePhone((string) $detail['contact_phone']) === $senderDigits;
        $status = strtoupper((string) $detail['status']);

        if (!$owns) {
            return self::out(
                "🔒 Booking " . $detail['pnr'] . " — status: " . $status . ".\n"
                . "For full details and your ticket PDF, message me from the mobile number used at booking."
            );
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
            // PDF viewer needed.
            $ticketUrl = Ticket::imageUrl((string) $detail['pnr']);

            if (Settings::getBool('whatsapp_send_pdf', true)) {
                $mediaUrl = $ticketUrl;
                $lines[]  = "\n✅ Your e-ticket is attached below. Safe journey!";
            } else {
                // Media attachments switched off (a Twilio TRIAL account rejects
                // them) — send the keyed links instead.
                $lines[] = "\n✅ Your e-ticket: " . $ticketUrl;
                $lines[] = "Print copy (PDF): " . Ticket::downloadUrl((string) $detail['pnr']);
                $lines[] = "Safe journey!";
            }
        } elseif ($detail['status'] === 'pending') {
            $lines[] = "\n⏳ Payment is being verified. Your ticket will arrive here the moment it's confirmed.";
        } elseif (in_array($detail['status'], ['cancelled', 'rejected'], true)) {
            $lines[] = "\nThis booking is " . strtolower($status) . ". Contact us if that's unexpected.";
        }

        return self::out(implode("\n", $lines), $mediaUrl);
    }

    /** Greeting for a plain GET on a webhook URL. */
    public static function greeting(): string
    {
        return "🙏 Namaste! This is the " . Settings::getString('company_name', APP_NAME) . " ticket assistant.\n"
            . "Send your booking PNR (looks like SHG-XXXX-XXXX-XXXX) to check your ticket.";
    }

    /** @return array{text: string, media: ?string} */
    private static function out(string $text, ?string $media = null): array
    {
        return ['text' => $text, 'media' => $media];
    }
}
