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
    public static function reply(string $from, string $body, string $kind = 'text'): array
    {
        $company      = Settings::getString('company_name', APP_NAME);
        $phone        = Settings::officePhone();
        $body         = trim($body);
        $senderDigits = normalisePhone($from);
        // +91 / +977 as the transport delivered it — a Nepali sender's ticket
        // must be addressed to +977, and ten normalised digits cannot say so.
        $senderCountry = resolvePhoneCountry('', $from);

        /* STAFF SIGN-IN (24 Sep 2026, wa_login_on). "login SHG-0027 <password>",
           "otp 123456", "logout", "ma ko hu" — answered here, FIRST, so a
           password never travels on into the model, the booking engine, the
           drafts queue or a log. With the switch off the login line is still
           swallowed and answered with "switched off". */
        if ($senderDigits !== '' && $body !== '') {
            try {
                require_once INCLUDE_PATH . '/walogin.php';
                $auth = WaLogin::handle($from, $body);   // the raw sender: +91 / +977 keeps the session apart
                if ($auth !== null) {
                    return self::out($auth['text'], $auth['media'] ?? null);
                }
            } catch (Throwable $e) {
                Logger::exception($e, 'whatsapp');
            }
        }

        /* A photo or a file with no caption: on this number that is a
           payment screenshot nine times out of ten. The generic menu told
           the sender nothing about the money they had just sent, so say
           what happens next and ask for the one thing we need to match it. */
        if ($body === '' && in_array($kind, ['image', 'document', 'video'], true)) {
            return self::out(
                "📩 " . $company . "\n"
                . "तपाईंले पठाउनुभएको फोटो प्राप्त भयो।\n\n"
                . "यो भुक्तानीको प्रमाण हो भने आफ्नो बुकिङ नं. (जस्तै SHG-2026-00123) पनि पठाउनुहोस् — "
                . "हाम्रो टोलीले जाँचेर टिकट यहीँ पठाउँछ।\n\n"
                . "Photo received. If this is a payment proof, please also send your booking number "
                . "(like SHG-2026-00123) so we can match it."
                . ($phone !== '' ? "\n\nसहयोग: " . $phone : '')
            );
        }

        /* A voice note: nobody at the desk can act on it automatically. */
        if ($body === '' && in_array($kind, ['audio', 'voice'], true)) {
            return self::out(
                "🎤 " . $company . "\n"
                . "अहिले म आवाज सुन्न सक्दिनँ। कृपया लेखेर पठाउनुहोस् — "
                . "मिति, कति सिट र कहाँबाट चढ्ने।"
                . ($phone !== '' ? "\n\nवा फोन गर्नुहोस्: " . $phone : '')
            );
        }

        // Pull a PNR out of the message text.
        $pnr = '';
        if (preg_match('/SHG-[A-Z0-9]+(?:-[A-Z0-9]+)+/i', $body, $m)) {
            $pnr = strtoupper($m[0]);
        }

        /* THE ASSISTANT WITH TOOLS (20 Sep 2026, wa_agent_on).
           Everything below this block is the bot of 19 Sep and stays exactly
           as it was: with the switch off, or with no AI key, AiAgent::handle()
           returns null and nothing here changes.

           One message is deliberately NOT given to the model: a bare PNR and
           nothing else. That is the commonest message this number receives,
           the answer is a database read, and the deterministic path below
           answers it in milliseconds for nothing. A PNR inside a SENTENCE
           ("SHG-… ko naam galat cha") is a request, not a lookup, so that one
           does go to the assistant. */
        $strip   = static fn (string $s): string => (string) preg_replace('/[^\p{L}\p{N}]/u', '', $s);
        $pnrOnly = $pnr !== '' && $strip(str_ireplace($pnr, '', $body)) === '';

        /* ---------------------------------------------------------------
         *  LOCAL FIRST (21 Sep 2026, owner: "mero website VPS ma ticket
         *  katna ko lagi use hune AI bandeu").
         *
         *  Selling used to sit BEHIND the model: every "bholi 2 seat
         *  chahiyo" went to Gemini, and a ticket could only be cut while a
         *  third party's quota held. On this account that quota is the free
         *  tier — an afternoon of ordinary traffic returns HTTP 429 and the
         *  sale simply stops. Paying for a seat is the one thing on this
         *  number that must never depend on somebody else's API.
         *
         *  WaBooking is the same conversation entirely on this VPS:
         *  TicketBot::parse reads the message (Nepali, Hindi, Gujarati,
         *  English, and Devanagari / Gujarati digits), TicketBot::suggest
         *  merges it with live availability, and QuickTicket::sellCustomer
         *  makes the sale under every customer rule. No network, no key, no
         *  quota, answered in milliseconds.
         *
         *  It is safe in front because it claims only what it is sure of:
         *  with no conversation open and nothing bookable in the message it
         *  returns null, and a question asked mid-summary is handed on
         *  deliberately. Everything it does not claim — company questions,
         *  complaints, corrections, small talk — still reaches the model
         *  below, which now spends its quota on conversation instead of
         *  on the sale.
         *
         *  wa_local_first = 0 puts the model back in front.
         * ------------------------------------------------------------- */
        $localFirst = Settings::getBool('wa_local_first', true);

        /* WHO IS WRITING (24 Sep 2026). Resolved once here — a staff record,
           or a WhatsApp sign-in (WaLogin) — and handed to the assistant, so
           the admins table is read once per message, not twice. */
        $who = null;
        if ($senderDigits !== '' && (Settings::getBool('wa_agent_on', false)
                || Settings::getBool('wa_bulk_on', false) || Settings::getBool('wa_login_on', false))) {
            /* Only while a switch that cares is on — with everything off this
               number behaves exactly as it did on 19 Sep, at no extra cost. */
            try {
                require_once INCLUDE_PATH . '/aitools.php';
                $who = AiTools::whoIs($from);
            } catch (Throwable $e) {
                Logger::exception($e, 'whatsapp');
            }
        }
        $isStaff = is_array($who) && in_array((string) ($who['role'] ?? ''), ['staff', 'admin'], true);

        /* BULK TICKETS (24 Sep 2026, wa_bulk_on, staff only). "FORMAT" gives the
           template; a pasted list is quoted; "ho" on an open quote sells every
           booking through QuickTicket::sell(). Read by code, not by a model —
           see includes/wabulk.php for why. */
        if ($isStaff && Settings::getBool('wa_bulk_on', false)) {
            try {
                require_once INCLUDE_PATH . '/ticketbot.php';
                require_once INCLUDE_PATH . '/quickticket.php';
                require_once INCLUDE_PATH . '/wabulk.php';
                $bulk = WaBulk::handle($who, $body);
                if ($bulk !== null) {
                    return self::out($bulk['text'], $bulk['media'] ?? null);
                }
            } catch (Throwable $e) {
                Logger::exception($e, 'whatsapp');
            }
        }

        $tryLocalBooking = function () use ($senderDigits, $senderCountry, $body): ?array {
            if ($senderDigits === '') {
                return null;
            }
            try {
                require_once INCLUDE_PATH . '/ticketbot.php';
                require_once INCLUDE_PATH . '/quickticket.php';
                require_once INCLUDE_PATH . '/wabooking.php';
                return WaBooking::handle($senderDigits, $body, $senderCountry);
            } catch (Throwable $e) {
                Logger::exception($e);      // the model and the old paths still answer
                return null;
            }
        };

        /* A member of staff is served by the assistant with the staff tools
           (24 Sep 2026): the local engine below sells to the SENDER's own
           number as a customer, which for a seller saying "bholi 2 seat" is
           the wrong ticket on the wrong number. Only while the assistant is
           actually on — with it off, the local engine still answers everyone. */
        $staffToAssistant = false;
        if ($isStaff && !$pnrOnly) {
            try {
                require_once INCLUDE_PATH . '/aiagent.php';
                $staffToAssistant = AiAgent::enabled();
            } catch (Throwable $e) {
                $staffToAssistant = false;
            }
        }

        if ($localFirst && !$pnrOnly && !$staffToAssistant) {
            $booking = $tryLocalBooking();
            if ($booking !== null) {
                return self::out($booking['text'], $booking['media'] ?? null);
            }
        }

        if (!$pnrOnly) {
            try {
                require_once INCLUDE_PATH . '/aiagent.php';
                $agent = AiAgent::handle($from, $body, 'whatsapp', $who);
                if ($agent !== null) {
                    return self::out($agent['text'], $agent['media']);
                }
            } catch (Throwable $e) {
                Logger::exception($e);          // the proven bot below still answers
            }
            /* The assistant could not answer a member of staff (provider
               down, deadline, cap). The paths below are for PASSENGERS —
               they would park a customer draft under the agent's own number
               or sell them a ticket — so staff get a plain "try again". */
            if ($staffToAssistant) {
                return self::out(
                    "⏳ सहायक अहिले व्यस्त छ — एक मिनेटपछि फेरि पठाउनुहोस्, वा app को ⚡ Quick Ticket प्रयोग गर्नुहोस्।"
                    . ($phone !== '' ? "\nहतार छ भने अफिस: " . $phone : '')
                );
            }
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
            /* When wa_local_first is on, this already ran ABOVE the model —
               calling it twice would re-enter the same conversation state
               and could answer the same message two different ways. So this
               position is now only for the legacy order (wa_local_first = 0),
               where the model gets first refusal and the local engine picks
               up whatever it left. */
            if (!$localFirst && !$staffToAssistant) {
                $booking = $tryLocalBooking();
                if ($booking !== null) {
                    return self::out($booking['text'], $booking['media'] ?? null);
                }
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
            // A bare PNR is a fresh subject: drop the assistant's thread and any
            // fare it had parked, so an old quote can never attach itself to it.
            require_once INCLUDE_PATH . '/aiagent.php';
            AiAgent::forget($senderDigits);
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

        $isOffice = $senderDigits !== '' && isset($staff[$senderDigits]);
        /* 24 Sep 2026: the office (a staff record or a WhatsApp sign-in) reads
           any PNR here too, and a seller their own sale — the same rule
           find_ticket applies, so a bare PNR is not the one message that
           answers an agent with the customer lock line. */
        if (!$isOffice && is_array($who)) {
            $role  = (string) ($who['role'] ?? '');
            $scope = $who['scopeAdminId'] ?? null;
            $isOffice = $role === 'admin'
                || ($role === 'staff' && ($scope === null || (int) ($detail['sold_by_admin_id'] ?? 0) === (int) $scope));
        }
        $owns    = $isOffice
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
