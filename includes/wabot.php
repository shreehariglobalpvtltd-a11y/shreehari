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
    public static function reply(string $from, string $body, string $kind = 'text', array $media = []): array
    {
        $company      = Settings::getString('company_name', APP_NAME);
        $phone        = Settings::officePhone();
        $body         = trim($body);
        $senderDigits = normalisePhone($from);
        // Agent Pro (Oct 2026): the agents' and staff WhatsApp desk
        if (is_file(INCLUDE_PATH . '/agentpro-wa.php')) {
            require_once INCLUDE_PATH . '/agentpro-wa.php';
            $apReply = ApWa::reply($from, $body, $kind);
            if ($apReply !== null) {
                return self::out($apReply['text'], $apReply['media'] ?? null);
            }
        }
        /* Post-deploy probe A1 (31 Oct 2026, owner's rule R1): a passenger's name correction right after a sale is a
           preview; the very next line answers it — a clear yes renames, "no" keeps the name, anything else drops the
           preview and is read as itself below. Answered first, so no other path can swallow that yes. */
        if ($senderDigits !== '' && $body !== '') {
            try {
                require_once INCLUDE_PATH . '/ticketbot.php';
                require_once INCLUDE_PATH . '/quickticket.php';
                require_once INCLUDE_PATH . '/wabooking.php';
                $nameFix = WaBooking::answerNameFix($senderDigits, $body);
                if ($nameFix !== null) {
                    return self::out($nameFix['text'], $nameFix['media'] ?? null, $nameFix['ui'] ?? null);
                }
            } catch (Throwable $e) {
                Logger::exception($e, 'whatsapp');
            }
        }
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

        /* 10 Oct 2026 — before the daily-job "ho" below, so an agent's ✅ on a
           ticket hand-over and a passenger's 6-digit code are never read as
           something else:
             - an agent answering a ticket hand-over, or saying available /
               busy / offline (includes/waagentroute.php);
             - the office teaching the bot: sikau / prashna / jawaf
               (includes/wateach.php);
             - a ticket change behind a 6-digit code: the buttons after a
               ticket, or "naam badal / miti badal / agent change / cancel"
               typed or spoken (includes/waedit.php).
           Each returns null for anything it does not own. */
        if ($senderDigits !== '' && $body !== '') {
            $early = null;
            try {
                require_once INCLUDE_PATH . '/waagentroute.php';
                $early = WaAgentRoute::handle($from, $body);
                if ($early === null) {
                    require_once INCLUDE_PATH . '/aitools.php';
                    $whoEarly = AiTools::whoIs($from);
                    require_once INCLUDE_PATH . '/wateach.php';
                    $early = WaTeach::handle($from, $body, $whoEarly);
                    if ($early === null) {
                        require_once INCLUDE_PATH . '/waedit.php';
                        $early = WaEdit::handle($from, $body, $whoEarly);
                    }
                }
            } catch (Throwable $e) {
                Logger::exception($e, 'whatsapp');
                $early = null;
            }
            if ($early !== null) {
                return self::out((string) $early['text'], $early['media'] ?? null, $early['ui'] ?? null);
            }
        }

        /* 9 Oct 2026 — "ho" / "hoina" to a daily-job offer, "habit band",
           "mero habit" (includes/wahabit.php). Null for everything else. */
        if ($senderDigits !== '' && $body !== '') {
            try {
                require_once INCLUDE_PATH . '/wahabit.php';
                $habit = WaHabit::handle($from, $body);
                if ($habit !== null) {
                    return self::out($habit['text'], $habit['media'] ?? null, $habit['ui'] ?? null);
                }
            } catch (Throwable $e) {
                Logger::exception($e, 'whatsapp');
            }
        }

        /* 5 Oct 2026 — a plain STOP / UNSUBSCRIBE / बन्द also takes the number
           off every Campaigns list (mk_optout), whether or not the older
           WhatsApp marketing engine below is switched on. Recorded quietly;
           the reply below (or the bot) still answers the person. */
        if ($senderDigits !== '' && preg_match('/^\s*(stop|unsubscribe|stop offers|offers off|बन्द|बंद|रोक्नुहोस्)\s*[.!]*\s*$/iu', $body) === 1) {
            try {
                if (is_file(INCLUDE_PATH . '/campaigns.php')) {
                    require_once INCLUDE_PATH . '/campaigns.php';
                    if (Campaigns::ready()) {
                        Campaigns::optOut($senderDigits, 'said STOP on WhatsApp', 0);
                        if (!Settings::getBool('wa_marketing_on', false)) {
                            return self::out("✅ ठिक छ — अब यस्ता अफर/सूचना सन्देश पठाइने छैन।\nDone — you will not get offer messages any more.");
                        }
                    }
                }
            } catch (Throwable $e) {
                Logger::exception($e, 'whatsapp');
            }
        }

        /* 24 Sep 2026 — marketing consent words (START OFFERS / STOP) are a
           record, not a conversation: they are written down first, exactly,
           before any engine gets to interpret them. Only when the marketing
           engine is on; otherwise the words fall through like any other. */
        if ($body !== '' && Settings::getBool('wa_marketing_on', false)) {
            try {
                require_once INCLUDE_PATH . '/wamarketing.php';
                $consent = WaMarketing::consentMessage($from, $body);
                if ($consent !== null) {
                    // "STOP" / "बन्द" also means: whatever was half-way — a seat
                    // conversation waiting for names, a parked quote — is over.
                    // Otherwise the next message would be read as the answer to
                    // a question the person has already walked away from.
                    if ($senderDigits !== '') {
                        try {
                            require_once INCLUDE_PATH . '/wabooking.php';
                            WaBooking::clear($senderDigits);
                            require_once INCLUDE_PATH . '/aiagent.php';
                            AiAgent::forget($senderDigits);
                        } catch (Throwable $e) {
                            Logger::exception($e);
                        }
                    }
                    return self::out((string) $consent['text'], $consent['media'] ?? null);
                }
            } catch (Throwable $e) {
                Logger::exception($e);
            }
        }

        /* 24 Sep 2026 — a voice note that was transcribed a moment ago is
           waiting for the person to confirm the words. "ho" turns the
           transcript into this message; anything else drops it and is
           handled as the fresh text it is. */
        if ($body !== '' && $senderDigits !== '') {
            $pending = self::takePendingVoice($senderDigits, $body);
            if ($pending !== null) {
                $body = $pending;
                $kind = 'text';
            }
        }

        /* 24 Sep 2026 — an attachment beside the words (or instead of them).
           With wa_ops_media_on the assistant is TOLD about it (kind + mime,
           never the content) and a copy is kept, encrypted, as evidence for a
           handoff. Off, or no assistant: the fixed replies below stand. */
        /* 29 Oct 2026 — a payment screenshot is READ (wa_pay_ocr_on,
           includes/wapayproof.php): UTR + amount, matched to this number's
           booking that is waiting for money, sent to the Verify Payments
           queue — never confirmed here. Not a payment, no waiting booking or
           switch off: null, and everything below runs as before. */
        if ($media !== [] && $kind === 'image' && $senderDigits !== '') {
            try {
                require_once INCLUDE_PATH . '/wapayproof.php';
                $proof = WaPayProof::handle($senderDigits, $body, $media);
                if ($proof !== null) {
                    return self::out($proof);
                }
            } catch (Throwable $e) {
                Logger::exception($e);
            }
        }

        $attachment = [];
        if ($media !== [] && in_array($kind, ['image', 'document', 'video', 'sticker'], true)) {
            $attachment = self::attachmentContext($senderDigits, $kind, $media);
            if ($body === '' && $attachment !== []) {
                try {
                    require_once INCLUDE_PATH . '/aiagent.php';
                    if (AiAgent::enabled()) {
                        $agent = AiAgent::handle($from, '[' . $kind . ' attached without any words]', 'whatsapp', ['attachment' => $attachment]);
                        if ($agent !== null) {
                            return self::out($agent['text'], $agent['media']);
                        }
                    }
                } catch (Throwable $e) {
                    Logger::exception($e);
                }
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

        /* A voice note. With wa_ops_voice_on the words are transcribed and READ
           BACK for confirmation — a wrong name on a ticket is exactly the mistake
           a transcriber makes, so nothing is acted on until the person says ho.
           Otherwise (or when the transcriber is unsure) the old ask-to-type reply. */
        if ($body === '' && in_array($kind, ['audio', 'voice'], true) && $media !== [] && $senderDigits !== '') {
            $heard = self::transcribeVoice($senderDigits, $media);
            if ($heard !== null) {
                return self::out($heard);
            }
        }
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
                || Settings::getBool('wa_bulk_on', false) || Settings::getBool('wa_login_on', false)
                || Settings::getBool('wa_order_staff_on', true))) {
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

        /* 6 Oct 2026 - a customer's question the knowledge base cannot answer
           yet is written down; tonight the AI drafts the article (AiLearn). */
        /* 9 Oct 2026: staff questions are observed too ("repeated kaam le
           sikos") - the knowledge base learns what the office keeps asking. */
        if ($kind === 'text' && $body !== '') {
            try {
                require_once INCLUDE_PATH . '/ailearn.php';
                AiLearn::observe($body);
            } catch (Throwable $e) {
                Logger::exception($e, 'whatsapp');
            }
        }

        /* THE ORDER MENU (5 Oct 2026, owner: "khana order gare jasto ticket
           order"). "menu" shows the buttons, "mero ticket" lists the sender's
           own tickets - both answered from the tables, no model. And a
           SELLER (agent / counter / office number) who taps the order button,
           or has an order open, is walked through the sale here: the model
           and the bulk list keep everything else a seller writes. */
        if ($senderDigits !== '' && !$pnrOnly && Settings::getBool('wa_order_menu_on', true)) {
            try {
                require_once INCLUDE_PATH . '/ticketbot.php';
                require_once INCLUDE_PATH . '/quickticket.php';
                require_once INCLUDE_PATH . '/wabooking.php';
                require_once INCLUDE_PATH . '/waorder.php';
                $menu = WaOrder::handle($senderDigits, $body, $who);
                if ($menu !== null) {
                    return self::out($menu['text'], $menu['media'] ?? null, $menu['ui'] ?? null);
                }
                if ($isStaff && Settings::getBool('wa_order_staff_on', true)) {
                    $order = WaOrder::sellerOrder($senderDigits, $body, $senderCountry, $who);
                    if ($order !== null) {
                        return self::out($order['text'], $order['media'] ?? null, $order['ui'] ?? null);
                    }
                }
            } catch (Throwable $e) {
                Logger::exception($e, 'whatsapp');
            }
        }

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

        /* THE LOCAL BRAIN (9 Oct 2026, owner: "website ko har ek detail —
           time table, bus live ma kaha, seat kati baki, driver, ticket — Nepali
           ma, VPS ma, free"). Bus position and ETA, seats left, the whole
           timetable, bus & crew, the ticket picture again, how to pay, the
           refund rule, the Nepal office, and the office's own summaries are
           read from the live tables in milliseconds — includes/walocal.php.
           It sits BEFORE the sale engine because "khali seat bholi nepal" is
           a question, not an order; it steps aside the moment a sale
           conversation is open, and it answers only what it recognises —
           everything else goes on exactly as before. Switch: wa_local_brain_on. */
        if (!$pnrOnly && $senderDigits !== '') {
            try {
                require_once INCLUDE_PATH . '/walocal.php';
                $local = WaLocal::answer($from, $body, $who);
                if ($local !== null) {
                    return self::out($local['text'], $local['media'] ?? null, $local['ui'] ?? null);
                }
            } catch (Throwable $e) {
                Logger::exception($e, 'whatsapp');   // the sale engine, the everyday answers and the assistant still run
            }
        }

        $tryLocalBooking = function () use ($senderDigits, $senderCountry, $body, $who, $isStaff): ?array {
            if ($senderDigits === '') {
                return null;
            }
            try {
                require_once INCLUDE_PATH . '/ticketbot.php';
                require_once INCLUDE_PATH . '/quickticket.php';
                require_once INCLUDE_PATH . '/wabooking.php';
                /* A seller's number orders as a SELLER (5 Oct 2026): asked whose
                   ticket it is, sold under their account. Before this the
                   engine sold the seller a passenger ticket on their own number. */
                return WaBooking::handle($senderDigits, $body, $senderCountry,
                    ($isStaff && Settings::getBool('wa_order_staff_on', true)) ? $who : null);
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
                /* round 2 (passenger sim: "bholi 1 seat Surat bata, Kathmandu jane ho" was answered "our bus does not
                   go there" and the booking was lost): the booking goes on, with one line on where the bus ends. */
                try {
                    require_once INCLUDE_PATH . '/walocal.php';
                    $note = WaLocal::routeEndNote($body);
                    if ($note !== '' && !str_contains((string) $booking['text'], $note)) {
                        $booking['text'] = (string) $booking['text'] . "\n\n" . $note;
                    }
                } catch (Throwable $e) {
                    Logger::exception($e);
                }
                return self::out($booking['text'], $booking['media'] ?? null, $booking['ui'] ?? null);
            }
        }

        /* THE EVERYDAY QUESTIONS, ON THIS VPS (23 Sep 2026, owner: "95 % kam
           afai garne"). Hello, fare, bus time, offers, website, office, and
           a strong knowledge-base match are answered from the live tables in
           milliseconds with no AI call. Anything personal or unsure returns
           null and falls through to the assistant below. wa_faq_on = 0 turns
           it off without touching anything else. */
        if (!$pnrOnly && $senderDigits !== '') {
            try {
                require_once INCLUDE_PATH . '/wafaq.php';
                $faq = WaFaq::answer($from, $body);
                if ($faq !== null) {
                    return self::out($faq['text'], $faq['media'], $faq['ui'] ?? null);
                }
            } catch (Throwable $e) {
                Logger::exception($e);          // the assistant still answers
            }
        }

        if (!$pnrOnly) {
            try {
                require_once INCLUDE_PATH . '/aiagent.php';
                $extra = $attachment !== [] ? ['attachment' => $attachment] : [];
                if (is_array($who)) {
                    $extra['who'] = $who; // already resolved for routing, incl. a WhatsApp login
                }
                $agent = AiAgent::handle($from, $body, 'whatsapp', $extra);
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
                    return self::out($booking['text'], $booking['media'] ?? null, $booking['ui'] ?? null);
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

            /* 31 Oct 2026 (passenger sim: "Surat to Rupaidiha bhada kati ho?", "kal ki bus kitne baje hai Surat se"
               were parked below as a desk booking draft with a fixed Nepali "the desk will confirm" and no fare):
               a fare / time question is answered from the fare board and the timetable (WaFaq's own answers),
               even with wa_faq_on off — only a real booking request is left to the draft queue. */
            try {
                require_once INCLUDE_PATH . '/wafaq.php';
                $fact = WaFaq::factAnswer($from, $body);
                if ($fact !== null) {
                    return self::out($fact['text'], $fact['media'] ?? null);
                }
                // round 2: a bare question is answered by the office line — it is never parked as a ticket on its way
                $ask = WaFaq::questionFallback($body);
                if ($ask !== null) {
                    return self::out($ask);
                }
            } catch (Throwable $e) {
                Logger::exception($e);          // the draft below still answers
            }

            $draft = null;
            try {
                require_once INCLUDE_PATH . '/ticketbrain.php';
                $draft = TicketBrain::capture($senderDigits, $body, 'whatsapp');
            } catch (Throwable $e) {
                Logger::exception($e);          // a parser hiccup must never eat the reply
            }

            // post-deploy probe B4: the draft reply and the welcome in the writer's language (they were Nepali for everyone)
            $fl = self::fallbackLang($body);
            if ($draft !== null) {
                $got = [];
                if (($draft['seats'] ?? 0) > 0) {
                    $got[] = (int) $draft['seats'] . self::tr($fl, ' सिट', ' सीट', ' seat(s)');
                }
                if (($draft['date'] ?? '') !== '') {
                    $got[] = formatDate((string) $draft['date'], 'D, j M');
                }
                if (($draft['direction'] ?? '') !== '') {
                    $got[] = QuickTicket::directionLabel((string) $draft['direction']);
                }
                if (($draft['boarding'] ?? '') !== '') {
                    $stop = Boarding::stopDisplay((string) $draft['boarding'])['name'];
                    $got[] = self::tr($fl, $stop . ' बाट', $stop . ' से', 'from ' . $stop);
                }

                return self::out(
                    self::tr($fl, "🙏 नमस्ते! तपाईंको अनुरोध प्राप्त भयो।\n\n", "🙏 नमस्ते! आपका अनुरोध मिल गया।\n\n", "🙏 Hello! We have your request.\n\n")
                    . ($got !== [] ? self::tr($fl, "मैले बुझेँ: ", "मैंने समझा: ", "I understood: ") . implode(' · ', $got) . "\n\n" : '')
                    . self::tr($fl, "हाम्रो डेस्कले बस, सिट र भाडा पक्का गरेर तपाईंको टिकट यहीँ छिट्टै पठाउनेछ।",
                        "हमारा डेस्क बस, सीट और किराया पक्का करके आपका टिकट यहीं जल्दी भेजेगा।",
                        "Our desk will confirm the bus, the seat and the fare and send your ticket here soon.")
                    . (($draft['date'] ?? '') === '' ? self::tr($fl, "\n\nतपाईं कुन मितिमा यात्रा गर्न चाहनुहुन्छ?", "\n\nआप किस तारीख को यात्रा करना चाहते हैं?",
                        "\n\nWhich date do you want to travel?") : '')
                    . ($phone !== '' ? self::tr($fl, "\n\nहतार छ? फोन गर्नुहोस्: " . $phone . "।", "\n\nजल्दी है? फ़ोन कीजिए: " . $phone . "।",
                        "\n\nIn a hurry? Call: " . $phone . ".") : '')
                );
            }

            return self::out(
                self::tr($fl,
                    "🙏 नमस्ते! म " . $company . " को टिकट सहायक हुँ।\n\n"
                    . "आफ्नो बुकिङ PNR (जस्तै SHG-XXXX-XXXX-XXXX) पठाउनुहोस्, म तुरुन्तै तपाईंको टिकट "
                    . "स्थिति र PDF पठाउँछु।\n\n"
                    . "यात्रा गर्नु छ? मलाई मिति, कति सिट र कहाँबाट चढ्ने भन्नुहोस् — "
                    . "जस्तै \"2 सिट Nepal 15 Sep, Mehsana\"।\n\n"
                    . ($phone !== '' ? "मान्छेसँग कुरा गर्नु छ? फोन गर्नुहोस्: " . $phone . "।" : ""),
                    "🙏 नमस्ते! मैं " . $company . " का टिकट सहायक हूँ।\n\n"
                    . "अपना बुकिंग PNR (जैसे SHG-XXXX-XXXX-XXXX) भेजिए, मैं तुरंत आपके टिकट की "
                    . "स्थिति और PDF भेजूँगा।\n\n"
                    . "यात्रा करनी है? मुझे तारीख, कितनी सीट और कहाँ से चढ़ना है बताइए — "
                    . "जैसे \"2 सीट Nepal 15 Sep, Mehsana\"।\n\n"
                    . ($phone !== '' ? "किसी इंसान से बात करनी है? फ़ोन कीजिए: " . $phone . "।" : ""),
                    "🙏 Hello! I am the ticket assistant of " . $company . ".\n\n"
                    . "Send your booking PNR (like SHG-XXXX-XXXX-XXXX) and I will send your ticket "
                    . "status and PDF at once.\n\n"
                    . "Want to travel? Tell me the date, how many seats and where you board — "
                    . "like \"2 seats Nepal 15 Sep, Mehsana\".\n\n"
                    . ($phone !== '' ? "Want to talk to a person? Call: " . $phone . "." : ""))
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
        $fl = self::fallbackLang($body);                 // post-deploy probe B4: the writer's language
        if ($detail === null) {
            return self::out(self::tr($fl,
                "❌ PNR " . $pnr . " को कुनै बुकिङ भेटिएन।\nकृपया कोड जाँचेर फेरि पठाउनुहोस्।",
                "❌ PNR " . $pnr . " की कोई बुकिंग नहीं मिली।\nकृपया कोड जाँचकर फिर भेजिए।",
                "❌ No booking was found for PNR " . $pnr . ".\nPlease check the code and send it again."));
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
            return self::out(self::tr($fl,
                "🔒 बुकिङ " . $detail['pnr'] . " — स्थिति: " . $status . "।\n"
                . "पूरा विवरण र टिकट PDF को लागि, बुकिङमा प्रयोग गरेको मोबाइल नम्बरबाट सन्देश पठाउनुहोस्।",
                "🔒 बुकिंग " . $detail['pnr'] . " — स्थिति: " . $status . "।\n"
                . "पूरी जानकारी और टिकट PDF के लिए, बुकिंग वाले मोबाइल नंबर से मैसेज भेजिए।",
                "🔒 Booking " . $detail['pnr'] . " — status: " . $status . ".\n"
                . "For the full details and the ticket PDF, message us from the mobile number used for the booking."));
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

    /* =================================================================
     *  Attachments and voice notes (24 Sep 2026)
     * ================================================================= */

    /** Words that mean "yes, that is what I said" in the languages this desk receives. */
    private const YES_WORDS = ['ho', 'हो', 'yes', 'y', 'haan', 'han', 'ha', 'हाँ', 'हां', 'ok', 'okay', 'thik', 'thik cha', 'thik xa',
        'ठिक छ', 'ठीक', 'ठीक है', 'hunxa', 'huncha', 'hunchha', 'हुन्छ', 'sahi', 'सही', 'barabar', 'બરાબર', 'હા', 'confirm', 'yes ho'];

    /** How long a transcript waits for its "ho". */
    private const VOICE_TTL = 600;

    /**
     * Metadata (and, when the office allows, an encrypted copy) of an inbound
     * attachment — data about the conversation, never an instruction.
     *
     * @param array<string,mixed> $media from WaMedia::describe()
     * @return array<string,mixed>
     */
    private static function attachmentContext(string $senderDigits, string $kind, array $media): array
    {
        try {
            require_once INCLUDE_PATH . '/wamedia.php';
            if (!WaMedia::enabled()) {
                return [];
            }
            $ctx = [
                'kind'     => $kind,
                'mime'     => (string) ($media['mime'] ?? ''),
                'id'       => (string) ($media['id'] ?? ''),
                'filename' => (string) ($media['filename'] ?? ''),
                'stash'    => '',
            ];
            // A handful of files per number per day is evidence; more is a disk
            // filler. Past the cap the assistant still learns a file arrived,
            // but nothing is fetched or kept.
            if ($ctx['id'] !== '' && $senderDigits !== '' && in_array($kind, ['image', 'document'], true)
                && Security::rateLimit('wa_media_stash', $senderDigits, 6, 86400)) {
                $bytes = WaMedia::download($ctx['id']);
                if ($bytes !== null) {
                    $ctx['stash'] = (string) (WaMedia::stash($bytes, $senderDigits) ?? '');
                    $ctx['mime']  = (string) $bytes['mime'];
                }
            }
            return $ctx;
        } catch (Throwable $e) {
            Logger::exception($e);
            return [];
        }
    }

    /**
     * Transcribe a voice note and ask the person to confirm the words.
     * Returns the reply text, or null when transcription is off / unsure
     * (the caller then sends the ask-to-type line).
     */
    private static function transcribeVoice(string $senderDigits, array $media): ?string
    {
        try {
            require_once INCLUDE_PATH . '/wamedia.php';
            if (!WaMedia::voiceEnabled() || (string) ($media['id'] ?? '') === '') {
                return null;
            }
            $bytes = WaMedia::download((string) $media['id']);
            if ($bytes === null) {
                return null;
            }
            $text = WaMedia::transcribe($bytes);
            if ($text === null || $text === '') {
                return null;
            }
            self::keepPendingVoice($senderDigits, $text);

            require_once INCLUDE_PATH . '/ticketbot.php';
            $lang = TicketBot::detectLang($text);
            $ask  = match ($lang) {
                'hi' => "🎤 मैंने सुना: “%s”\n\nसही है? “हाँ” लिखें, मैं इसी पर काम करूँगा। गलत हो तो सही बात लिखकर भेजें।",
                'gu' => "🎤 મેં સાંભળ્યું: “%s”\n\nબરાબર છે? “હા” લખો, હું એ પ્રમાણે કરીશ. ખોટું હોય તો સાચી વાત લખીને મોકલો.",
                'en' => "🎤 I heard: “%s”\n\nIs that right? Reply “yes” and I will act on it. If not, please type the correct details.",
                default => "🎤 मैले सुनेँ: “%s”\n\nठिक हो? “हो” लेख्नुहोस्, म त्यही अनुसार गर्छु। गलत भए सही कुरा लेखेर पठाउनुहोस्।",
            };
            return sprintf($ask, mb_substr($text, 0, 600));
        } catch (Throwable $e) {
            Logger::exception($e);
            return null;
        }
    }

    private static function keepPendingVoice(string $who, string $text): void
    {
        $json = json_encode(['text' => mb_substr($text, 0, 1500), 'at' => time()], JSON_UNESCAPED_UNICODE);
        try {
            $done = Database::update('kv_store', ['kvalue' => $json, 'updated_by' => 'wabot'],
                'kscope = :s AND kkey = :k', ['s' => 'wa_voice', 'k' => $who]);
            if ($done === 0) {
                Database::insertIgnore('kv_store', ['kscope' => 'wa_voice', 'kkey' => $who, 'kvalue' => $json, 'updated_by' => 'wabot']);
            }
        } catch (Throwable $e) {
            Logger::exception($e);
        }
    }

    /**
     * If a transcript is waiting and this message is a plain yes, return the
     * transcript (and forget it). Any other message forgets it and returns null.
     */
    private static function takePendingVoice(string $who, string $body): ?string
    {
        try {
            $row = Database::fetch('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => 'wa_voice', 'k' => $who]);
        } catch (Throwable $e) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        try {
            Database::delete('kv_store', 'kscope = :s AND kkey = :k', ['s' => 'wa_voice', 'k' => $who]);
        } catch (Throwable $ignored) {
        }
        $saved = json_decode((string) $row['kvalue'], true);
        if (!is_array($saved) || (time() - (int) ($saved['at'] ?? 0)) > self::VOICE_TTL) {
            return null;
        }
        $norm = mb_strtolower(trim(preg_replace('/[\s\p{P}]+/u', ' ', $body) ?? $body));
        if (!in_array($norm, self::YES_WORDS, true)) {
            return null;
        }
        $text = trim((string) ($saved['text'] ?? ''));
        return $text !== '' ? $text : null;
    }

    /** Post-deploy probe B4: the language of a fallback line — the writer's (Nepali when unsure); Gujarati reads English. */
    private static function fallbackLang(string $body): string
    {
        try {
            require_once INCLUDE_PATH . '/ticketbot.php';
            require_once INCLUDE_PATH . '/walocal.php';
            $l = WaLocal::replyLang($body);
        } catch (Throwable $e) {
            $l = 'ne';
        }
        return in_array($l, ['ne', 'hi', 'en'], true) ? $l : ($l === 'gu' ? 'en' : 'ne');
    }

    private static function tr(string $lang, string $ne, string $hi, string $en): string
    {
        return match ($lang) { 'hi' => $hi, 'en' => $en, default => $ne };
    }

    /** Greeting for a plain GET on a webhook URL. */
    public static function greeting(): string
    {
        return "🙏 नमस्ते! यो " . Settings::getString('company_name', APP_NAME) . " को टिकट सहायक हो।\n"
            . "आफ्नो बुकिङ PNR (जस्तै SHG-XXXX-XXXX-XXXX) पठाएर टिकट जाँच्नुहोस्।";
    }

    /**
     * @param array<string,mixed>|null $ui tappable answers (buttons / list); a
     *        transport that cannot show them sends $text, which always asks
     *        the same thing with numbered answers
     * @return array{text: string, media: ?string, ui: ?array}
     */
    private static function out(string $text, ?string $media = null, ?array $ui = null): array
    {
        return ['text' => $text, 'media' => $media, 'ui' => $ui];
    }
}
