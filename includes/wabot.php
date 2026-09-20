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
            /* A seat request is a conversation, not a form: WaBooking keeps
               what it understood, asks for the one thing still missing in the
               writer's language, shows the fare, and sells only after an
               explicit yes. It returns null for anything that is not a
               booking chat, and then the older paths below answer. */
            try {
                require_once INCLUDE_PATH . '/ticketbot.php';
                require_once INCLUDE_PATH . '/quickticket.php';
                require_once INCLUDE_PATH . '/wabooking.php';
                $booking = WaBooking::handle($senderDigits, $body);
                if ($booking !== null) {
                    return self::out($booking['text'], $booking['media'] ?? null);
                }
            } catch (Throwable $e) {
                Logger::exception($e);      // fall through to the old reply
            }

            /* Anything that is not a seat request is a question, and the
               assistant answers those from the live timetable, fares and
               refund rules. It returns null when no AI key is configured or
               the number hit its limit, and then the older paths below run
               exactly as they used to. */
            try {
                require_once INCLUDE_PATH . '/aichat.php';
                $ai = AiChat::whatsappReply($senderDigits, $body);
                if ($ai !== null) {
                    return self::out($ai);
                }
            } catch (Throwable $e) {
                Logger::exception($e);
            }

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
                    $got[] = (int) $draft['seats'] . ' सिट';
                }
                if (($draft['date'] ?? '') !== '') {
                    $got[] = formatDate((string) $draft['date'], 'D, j M');
                }
                if (($draft['direction'] ?? '') !== '') {
                    $got[] = QuickTicket::directionLabel((string) $draft['direction']);
                }
                if (($draft['boarding'] ?? '') !== '') {
                    $got[] = Boarding::stopDisplay((string) $draft['boarding'])['name'] . ' बाट';
                }

                return self::out(
                    "🙏 नमस्ते! तपाईंको अनुरोध प्राप्त भयो।\n\n"
                    . ($got !== [] ? "मैले बुझेँ: " . implode(' · ', $got) . "\n\n" : '')
                    . "हाम्रो डेस्कले बस, सिट र भाडा पक्का गरेर तपाईंको टिकट यहीँ छिट्टै पठाउनेछ।"
                    . (($draft['date'] ?? '') === '' ? "\n\nतपाईं कुन मितिमा यात्रा गर्न चाहनुहुन्छ?" : '')
                    . ($phone !== '' ? "\n\nहतार छ? फोन गर्नुहोस्: " . $phone . "।" : '')
                );
            }

            return self::out(
                "🙏 नमस्ते! म " . $company . " को टिकट सहायक हुँ।\n\n"
                . "आफ्नो बुकिङ PNR (जस्तै SHG-XXXX-XXXX-XXXX) पठाउनुहोस्, म तुरुन्तै तपाईंको टिकट "
                . "स्थिति र PDF पठाउँछु।\n\n"
                . "यात्रा गर्नु छ? मलाई मिति, कति सिट र कहाँबाट चढ्ने भन्नुहोस् — "
                . "जस्तै \"2 सिट Nepal 15 Sep, Mehsana\"।\n\n"
                . ($phone !== '' ? "मान्छेसँग कुरा गर्नु छ? फोन गर्नुहोस्: " . $phone . "।" : "")
            );
        }

        try {
            require_once INCLUDE_PATH . '/aichat.php';
            AiChat::forget($senderDigits);
        } catch (Throwable $e) {
            // memory cleanup is best effort
        }

        $detail = BookingService::detail($pnr);
        if ($detail === null) {
            return self::out(
                "❌ PNR " . $pnr . " को कुनै बुकिङ भेटिएन।\n"
                . "कृपया कोड जाँचेर फेरि पठाउनुहोस्।"
            );
        }

        // Only the booking's own number gets full detail + the ticket —
        // and the office's own numbers, so the desk can check any PNR from
        // WhatsApp instead of opening the admin panel. Staff numbers come
        // from Settings, so adding a manager is a settings change.
        $staff = [];
        foreach (['admin_whatsapp', 'company_whatsapp', 'company_phone', 'office_phone'] as $k) {
            $d = normalisePhone(Settings::getString($k, ''));
            if ($d !== '') {
                $staff[$d] = true;
            }
        }
        $d = normalisePhone(Settings::officePhone());
        if ($d !== '') {
            $staff[$d] = true;
        }

        $isStaff = $senderDigits !== '' && isset($staff[$senderDigits]);
        $owns    = $isStaff
            || ($senderDigits !== '' && normalisePhone((string) $detail['contact_phone']) === $senderDigits);
        $status = strtoupper((string) $detail['status']);

        if (!$owns) {
            return self::out(
                "🔒 बुकिङ " . $detail['pnr'] . " — स्थिति: " . $status . "।\n"
                . "पूरा विवरण र टिकट PDF को लागि, बुकिङमा प्रयोग गरेको मोबाइल नम्बरबाट सन्देश पठाउनुहोस्।"
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
            "स्थिति: " . $status,
        ];
        if ($leg !== []) {
            $lines[] = "बाटो: " . ($leg['from_city'] ?? '') . " -> " . ($leg['to_city'] ?? '');
            $date = formatDate((string) ($leg['travel_date'] ?? ''), 'D, j M Y');
            if ($date !== '') {
                $lines[] = "मिति: " . $date;
            }
            $dep = substr((string) ($leg['dep_time'] ?? ''), 0, 5);
            if ($dep !== '') {
                $lines[] = "प्रस्थान: " . $dep;
            }
        }
        if ($seats !== '') {
            $lines[] = "सिट: " . $seats;
        }
        $lines[] = "यात्रु: " . count($detail['passengers']);
        $lines[] = "जम्मा: " . inr((float) $detail['total_amount']);

        $mediaUrl = null;
        if ($detail['status'] === 'confirmed') {
            // PNG first (5 Sep 2026): the image opens straight in WhatsApp, no
            // PDF viewer needed.
            $ticketUrl = Ticket::imageUrl((string) $detail['pnr']);

            if (Settings::getBool('whatsapp_send_pdf', true)) {
                $mediaUrl = $ticketUrl;
                $lines[]  = "\n✅ तपाईंको ई-टिकट तल संलग्न छ। राम्रो यात्रा होस्!";
            } else {
                // Media attachments switched off (a Twilio TRIAL account rejects
                // them) — send the keyed links instead.
                $lines[] = "\n✅ तपाईंको ई-टिकट: " . $ticketUrl;
                $lines[] = "प्रिन्ट गर्ने (PDF): " . Ticket::downloadUrl((string) $detail['pnr']);
                $lines[] = "राम्रो यात्रा होस्!";
            }
        } elseif ($detail['status'] === 'pending') {
            $lines[] = "\n⏳ भुक्तानी जाँच भइरहेको छ। पक्का भएपछि तपाईंको टिकट यहीँ आउनेछ।";
            /* Not paid yet? Then the most useful reply is the way to pay: the
               QR image carries the exact amount, the link opens a UPI app. */
            if (Settings::getString('upi_id', '') !== '' && (float) $detail['total_amount'] > 0) {
                $mediaUrl = appUrl('pay-image.php?pnr=' . urlencode((string) $detail['pnr']));
                $lines[]  = "\n💰 तिर्न बाँकी छ भने माथिको QR स्क्यान गर्नुहोस्, वा यहाँ थिच्नुहोस्:";
                $lines[]  = appUrl('pay.php?pnr=' . urlencode((string) $detail['pnr']));
            }
        } elseif (in_array($detail['status'], ['cancelled', 'rejected'], true)) {
            $stTxt = $detail['status'] === 'rejected' ? 'अस्वीकृत' : 'रद्द';
            $lines[] = "\nयो बुकिङ " . $stTxt . " भएको छ। अनपेक्षित लागेमा हामीलाई फोन गर्नुहोस्।";
        }

        return self::out(implode("\n", $lines), $mediaUrl);
    }

    /** Greeting for a plain GET on a webhook URL. */
    public static function greeting(): string
    {
        return "🙏 नमस्ते! यो " . Settings::getString('company_name', APP_NAME) . " को टिकट सहायक हो।\n"
            . "आफ्नो बुकिङ PNR (जस्तै SHG-XXXX-XXXX-XXXX) पठाएर टिकट जाँच्नुहोस्।";
    }

    /** @return array{text: string, media: ?string} */
    private static function out(string $text, ?string $media = null): array
    {
        return ['text' => $text, 'media' => $media];
    }
}
