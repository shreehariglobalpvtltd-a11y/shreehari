<?php
/**
 * =====================================================================
 *  includes/waanswer.php — WaAnswer: answer what was asked (25 Nov 2026,
 *  mp3-bot).
 *
 *  Owner: "chatbot lai bises training deu — msg history hera ta ulto ans
 *  dinchha, khali ticket matra sodhchha; j sodhchha user le tei ko answer
 *  din paryo". The live history (15–25 Nov) showed why:
 *    P1 a bank's and a shop's AUTO-REPLIES were answered 94 times, parked
 *       as 101 desk drafts and once sold as a confirmed ticket;
 *    P2 every staff / agent / office question that was not an exact
 *       command got the numbered ticket menu (the desk never yielded);
 *    P3 no answer for "chalani deu", "<name> ka xan", "k xa halkhabar",
 *       "Nepalgunj office ko number", a passenger by name;
 *    E  every fallback after the AI ended with "send the date and seats"
 *       or "the desk will send your ticket".
 *
 *  This class is the small, deterministic layer in front of all that:
 *    - automated()     a machine's message (auto-reply, "thank you for
 *                      contacting", "type Hi", app-store links, an
 *                      unsupported message type) → no reply, no draft, no
 *                      sale (wa_auto_sender_guard_on, default 1);
 *    - forCustomer()   a passenger's PNR (gated, WaLookup), a name search
 *                      (refused politely), a place / contact question
 *                      (WaDirectory), and — while a booking chat is open —
 *                      a fare / time / luggage / documents question,
 *                      answered with the booking's waiting line under it;
 *    - looksLikeBooking() / unsure()  the last fallback: a ticket draft only
 *                      for a line that IS a booking request; anything else
 *                      gets an honest "I do not have that answer" + office.
 *  The desk (ApWa) calls the member pieces from its own answerFirst().
 *
 *  Switch: wa_answer_first_on (default 1) — off = null everywhere and every
 *  path answers exactly as before.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

final class WaAnswer
{
    public static function on(): bool
    {
        return Settings::getBool('wa_answer_first_on', true);
    }

    /* =================================================================
     *  Language: mirror the writer
     * ================================================================= */

    /** ne (Devanagari Nepali) | rn (Roman Nepali) | hi | en — the way the person wrote. */
    public static function lang(string $text): string
    {
        try {
            require_once INCLUDE_PATH . '/ticketbot.php';
            require_once INCLUDE_PATH . '/walocal.php';
            $l = WaLocal::replyLang($text);
        } catch (Throwable $e) {
            $l = 'ne';
        }
        if ($l === 'gu') {
            $l = 'en';
        }
        return self::mirror(in_array($l, ['ne', 'hi', 'en'], true) ? $l : 'ne', $text);
    }

    /** A Nepali reply to a line written in Latin letters is Roman Nepali ("rn"). */
    public static function mirror(string $lang, string $text): string
    {
        if ($lang === 'ne' && preg_match('/\p{Devanagari}/u', $text) !== 1 && preg_match('/\p{Latin}/u', $text) === 1) {
            return 'rn';
        }
        return in_array($lang, ['ne', 'rn', 'hi', 'en'], true) ? $lang : 'ne';
    }

    /* =================================================================
     *  P1: machines
     * ================================================================= */

    /** Is this a machine's message (an auto-reply, a bot's menu)? Then nothing is answered, drafted or sold. */
    public static function automated(string $text, string $kind = 'text'): bool
    {
        if (!Settings::getBool('wa_auto_sender_guard_on', true)) {
            return false;
        }
        $t = trim($text);
        if ($t === '') {
            // an unsupported / system message with no words (a bank bot's card): nothing to answer
            return in_array($kind, ['unsupported', 'unknown', 'system', 'ephemeral'], true);
        }
        $l = mb_strtolower((string) preg_replace('/\s+/u', ' ', $t));
        $count = static function (array $res) use ($l): int {
            $n = 0;
            foreach ($res as $re) {
                $n += preg_match($re, $l) === 1 ? 1 : 0;
            }
            return $n;
        };
        // a machine for sure, whatever else the line holds (the LIC loop, the shop's greeting)
        $very = $count([
            '/thank(?:s| you) for (?:using|contacting)\b/u',
            '/\bwhatsapp services\b/u',
            '/\balways for your support\b/u',
            '/\bplease let us inquiry\b/u',
        ]);
        if ($very > 0) {
            return true;
        }
        // one is enough — unless the line also reads like a person (a booking word, a question, a date / seat count)
        $strong = $count([
            '/thank(?:s| you) for (?:reaching|messaging|choosing|connecting|your (?:message|interest|enquiry|inquiry))/u',
            '/(?:this is an?|is an) (?:automated|automatic|auto[- ]?generated) (?:message|reply|response)/u',
            '/\b(?:auto[- ]?reply|autoreply|auto[- ]?response|automatic reply|out of (?:the )?office)\b/u',
            '/\bjust type\s*["\']?(?:hi|hello|menu)\b/u',
            '/play\.google\.com\/store|apps\.apple\.com\//u',
            '/\b(?:press|reply|type) [0-9]\b.{0,20}\b(?:for|to)\b/u',
        ]);
        // what a person also writes ("why you don't reply", "we will call you after payment", "business hours?"):
        // only two of these together, or one with a machine phrase above, count
        $weak = $count([
            '/\b(?:do not|don\'?t|please do not) reply\b|\bno[- ]?reply\b/u',
            '/\b(?:we will|we\'ll|our (?:team|executive|agent) will) (?:get back|contact you|reply|respond|call you)/u',
            '/\bbusiness hours\b|\bcurrently (?:closed|unavailable|away)\b/u',
        ]);
        if ($strong === 0 && $weak < 2) {
            return false;
        }
        // a real passenger's line with a booking or a question in it is never silenced by one stray phrase
        $person = preg_match('/(?<![\p{L}])(?:seats?|sits?|tickets?|tikat|tikats|tiket|bus|buses|gadi|book|booking|bhada|fare|kati|kaha|chahiyo|chahiye|सिट|टिकट|बस|भाडा)(?![\p{L}])/u', $l) === 1
            || self::isQuestion($t) || self::tripSignal($t);
        if ($person && $strong + $weak <= 2) {
            return false;
        }
        return true;
    }

    /* =================================================================
     *  A passenger
     * ================================================================= */

    /**
     * @param array<string,mixed> $who AiTools::whoIs()
     * @return array{text:string, media:?string, router:string}|null null = not mine, the bot goes on as before
     */
    public static function forCustomer(string $fromRaw, string $senderDigits, string $body, array $who): ?array
    {
        if ($senderDigits === '' || trim($body) === '' || mb_strlen($body) > 400) {
            return null;
        }
        if (in_array((string) ($who['role'] ?? 'customer'), ['staff', 'admin'], true)) {
            return null;
        }
        /* 26 Nov 2026 (fx1): "manche sanga kura garnu cha", "call me", "kisi se baat karni hai" — a person is asked
           for. Its own switch (wa_human_ask_on), so it answers even with wa_answer_first_on off. */
        $human = self::humanAsk($fromRaw, $senderDigits, $body);
        if ($human !== null) {
            return $human;
        }
        if (!self::on()) {
            return null;
        }
        require_once INCLUDE_PATH . '/walookup.php';
        require_once INCLUDE_PATH . '/wadirectory.php';
        $lang = self::lang($body);
        try {
            WaLocal::noteChatLang($senderDigits, $body);      // a later line with no language of its own (a code) keeps this one
        } catch (Throwable $e) {
        }

        if (WaLookup::on()) {
            // 1. the "last 4 digits" the lookup asked for
            $ch = WaLookup::challengeReply($senderDigits, $body, $who, $lang);
            if ($ch !== null) {
                return ['text' => $ch['text'], 'media' => $ch['media'] ?? null, 'router' => 'lookup'];
            }
            // 2. a PNR and nothing else ("SHG-2026-00963", "Hi SHG-…", "PNR 963 status?")
            $pnr = WaLookup::pnrIn($body);
            if ($pnr !== '' && WaLookup::isLookupLine($body, $pnr)) {
                try {
                    require_once INCLUDE_PATH . '/aichat.php';
                    require_once INCLUDE_PATH . '/aiagent.php';
                    AiChat::forget($senderDigits);           // a PNR is a fresh subject (the old path did the same)
                    AiAgent::forget($senderDigits);
                } catch (Throwable $e) {
                }
                $r = WaLookup::pnrAnswer($pnr, $who, $senderDigits, $lang);
                return ['text' => $r['text'], 'media' => $r['media'] ?? null, 'router' => 'lookup'];
            }
            // 3. someone else's ticket by name: refused, with the way to find their own
            $name = WaLookup::nameIn($body);
            if ($name !== '') {
                $r = WaLookup::byName($name, $who, $senderDigits, $lang);
                return ['text' => $r['text'], 'media' => null, 'router' => 'lookup'];
            }
        }

        require_once INCLUDE_PATH . '/ticketbot.php';
        require_once INCLUDE_PATH . '/quickticket.php';
        require_once INCLUDE_PATH . '/wabooking.php';
        $open = WaBooking::isOpen($senderDigits);

        // 4a. "How do I book a ticket?" — the ways to book, never a draft with a made-up seat count
        if (!$open && self::asksHowToBook($body)) {
            $how = self::howToBook($body, $lang);
            self::remember($senderDigits, $body, $how);
            return ['text' => $how, 'media' => null, 'router' => 'howto'];
        }

        // 4. a place / a contact: "Nepalgunj office ko number?", "Ahmedabad pickup kaha?", "CEO ko number", "Surat agent"
        $dir = WaDirectory::lookup($body, 'customer', $lang);
        if ($dir !== null) {
            self::remember($senderDigits, $body, $dir['text']);
            return ['text' => $dir['text'] . ($open ? WaBooking::waitingNote($senderDigits) : ''), 'media' => null, 'router' => 'directory'];
        }

        // 5. luggage / documents / a child's fare — and, mid-booking, a fare or time question — answered, the booking waits
        if (self::isQuestion($body) && !self::tripSignal($body)) {
            try {
                require_once INCLUDE_PATH . '/wafaq.php';
                $c = WaFaq::common($fromRaw, $body);
                if ($c === null && $open) {
                    $c = WaFaq::factAnswer($fromRaw, $body);
                }
                if ($c !== null) {
                    return ['text' => (string) $c['text'] . ($open ? WaBooking::waitingNote($senderDigits) : ''), 'media' => null, 'router' => 'wafaq'];
                }
            } catch (Throwable $e) {
                Logger::warning('WaAnswer fact failed: ' . $e->getMessage(), [], 'whatsapp');
            }
        }
        return null;
    }

    /* =================================================================
     *  fx1 (26 Nov 2026): "talk to a human"
     *
     *  Live gap: "manche sanga kura garnu cha", "human", "call me", "kisi se
     *  baat karni hai" all got the generic welcome — the only hand-off was a
     *  tool the cloud model may call (off by default). Now, with no model:
     *  ONE call-back request in the Help Desk (category "callback", the same
     *  queue the website's "call me back" form fills) + the office bell, and
     *  the office number in the writer's language. While a request of the
     *  last hour is still open a later ask gets the numbers again, no new row
     *  (and at most 3 new requests an hour from one number).
     *  If the Help Desk is off or the save fails the reply gives the numbers
     *  and never claims that staff were told.
     *  Switch: wa_human_ask_on (default 1) — off = the line goes on as before.
     * ================================================================= */

    /**
     * Does this line ASK for a person? Explicit phrases only (Roman / Devanagari Nepali, Hindi, English); a
     * statement about a talk that already happened ("staff sanga kura bhayo") and a line with a seat count or a
     * date (an order) never count.
     */
    public static function asksHuman(string $text): bool
    {
        $t = ' ' . trim((string) preg_replace('/\s+/u', ' ', mb_strtolower((string) preg_replace('/[\p{P}]+/u', ' ', TicketBotAscii::digits($text))))) . ' ';
        if (mb_strlen($t) < 5 || mb_strlen($t) > 140) {
            return false;
        }
        $a = '(?<![\p{L}\p{M}])';
        $z = '(?![\p{L}\p{M}])';
        // it already happened: a statement, not a request
        // (review fix: "mujhe call karke bola tha ki …" — "bola / kaha tha", "bataya" tell of a past call)
        if (preg_match('/' . $a . '(?:bhayo|bhaisakyo|bhako|bhaeko|bhayeko|gareko|garisake|garisakeko|garyo|gare|hua|hui|ho\s*gaya|ho\s*gayi|ho\s*chuki|thiyo|kiya\s*tha|ki\s*thi|already\s+(?:talked|spoke|called)|talked|spoke'
                . '|bola\s+th[aie]|bole\s+th[aie]|boli\s+thi|kaha\s+th[aie]|kahi\s+thi|bataya|batayi|called\s+me|phoned\s+me'
                . '|भयो|भइसक्यो|गरेको|गरिसकें|हुआ|हुई|हो\s*गया|हो\s*गई|की\s*थी|बोला\s*था|कहा\s*था|बताया)' . $z . '/u', $t) === 1) {
            return false;
        }
        // "don't call me", "malai phone nagarnus", "call mat karo": the opposite of a request
        // (review fix: "malai phone garnu pardaina", "call chahidaina", "phone karne ki zarurat nahi" — no need to)
        if (preg_match('/' . $a . '(?:don\s*t|dont|do\s+not|never|mat|nagarnu\w*|nagarnus|nagara|nagar|nakara|nakaro'
                . '|pardaina|pardena|pardaena|pardain|parena|naparne|napar\w*|chahidaina|chaidaina|chahinna|chahidena|chahindaina|nahi\s+chahiye|nahin\s+chahiye|nahi\s+karn[ai]|nahin\s+karn[ai]'
                . '|zarurat\s+nahi\w*|jarurat\s+nahi\w*|zaroorat\s+nahi\w*|jarurat\s+chaina|jaruri\s+chaina|no\s+need|not\s+needed|need\s+not'
                . '|नगर्नु\S*|नगर|मत|पर्दैन|चाहिँदैन|चाहिदैन|जरुरत\s*नहीं|जरूरत\s*नहीं|नहीं\s*चाहिए|नहीं\s*करन[ाी])' . $z . '/u', $t) === 1) {
            return false;
        }
        $want = '(?:garn\w*|garna|garau\w*|garidinu\w*|gardinu\w*|gara\w*|gar|paryo|parcha|parchha|chahiyo|chaiyo|chahanchu|chahanchhu|man\s*cha|man\s*xa|cha|chha|xa|milcha|milchha|milxa|sakincha|sakinchha|hai|hoga|karni|karna|karni\s*hai|karna\s*hai|karwa\w*|kara\w*|krna|krni)';
        $hit =
            // Roman Nepali: "manche sanga kura garnu cha", "staff sanga kura garna paryo", "office ko manche sanga kura garnu paryo"
            preg_match('/' . $a . '(?:manche|manchhe|manxe|manchey|maanche|manis|maanis|manxey|staff|office|karmachari|operator|representative|sir)(?:\s+ko\s+manche|\s+ko\s+manchhe)?\s+(?:sanga|sang|sita|sanghai|saga)\s+(?:kura|kuro|baat|bat|kurakani)\s+' . $want . $z . '/u', $t) === 1
            // "malai phone garnus", "malai call garidinu", "hamilai phone gara na"; "mujhe call karo", "mujhe phone kijiye"
            || preg_match('/' . $a . '(?:malai|mlai|hamilai|mujhe|mujhko|mujhse|humko|hume|hamein|hamko)\s+(?:ek\s*(?:choti|patak|baar)\s+)?(?:phone|fon|call|kal|callback|call\s*back)\s+(?:gar\w*|kar\w*|kijiye|kariye|kijie)' . $z . '/u', $t) === 1
            || preg_match('/^\s(?:please\s+|pls\s+|plz\s+|kripaya\s+)?(?:phone|fon|call)\s+(?:garidinu\w*|gardinu\w*|garnus|garnuhos|garnu\s*hola|gara\s*na|garnu\s*na|kijiye|kariye|karo|kar\s*do|kardo|kar\s*dijiye)(?:\s+(?:na|hai|ta|please|pls|plz|sir|ji|hajur|dai))?\s$/u', $t) === 1
            // Roman Hindi: "kisi se baat karni hai", "insaan se baat karwa do", "baat karni hai", "staff se baat karna hai"
            || preg_match('/' . $a . '(?:kisi|kisise|insaan|insan|aadmi|admi|staff|office|manager|executive|operator)\s*se\s+baat\s+(?:karni|karna|karwa\w*|kara\w*|krni|krna|ho\s*sakti|ho\s*sakta)' . $z . '/u', $t) === 1
            || preg_match('/^\s(?:mujhe\s+|muje\s+|hame\s+|hamein\s+)?baat\s+(?:karni|karna|krni|krna)\s+hai\s$/u', $t) === 1
            // English: "human", "real person", "talk to a person", "I want to speak with someone", "call me (back)"
            || preg_match('/^\s(?:a\s+|an\s+)?(?:human|real\s+person|live\s+agent|live\s+person|human\s+please|human\s+agent)\s$/u', $t) === 1
            || preg_match('/' . $a . '(?:talk|speak|chat|connect)(?:\s+me)?\s+(?:to|with)\s+(?:a\s+|an\s+|any\s+|some\s+|the\s+|your\s+|real\s+)*(?:person|human|someone|somebody|staff|representative|operator|executive|manager|real\s+person|human\s+being|live\s+agent)' . $z . '/u', $t) === 1
            // (review fix: "they call me Ram" is a name — "call me" counts only as the line's own ask: at its start,
            //  after a please / can you, or after "… someone / staff / office (to) …", "I want you to …")
            || preg_match('/^\s(?:(?:ok|okay|hi|hello|hey|namaste|sir|dai|hajur)\s+)*(?:(?:please|pls|plz|kindly)\s+)?(?:(?:can|could|will|would)\s+(?:you|u|someone|somebody|anyone)\s+)?(?:(?:please|pls|plz|kindly)\s+)?(?:call\s+me(?:\s+back)?|give\s+me\s+a\s+call|ring\s+me)' . $z . '/u', $t) === 1
            || preg_match('/' . $a . '(?:please|pls|plz|kindly|someone|somebody|anyone|staff|office|manager|operator|(?:want|need)\s+(?:you|u))\s+(?:(?:to|should|can|could|will|would|please|pls)\s+){0,2}(?:call\s+me(?:\s+back)?|give\s+me\s+a\s+call|ring\s+me)' . $z . '/u', $t) === 1
            || preg_match('/' . $a . '(?:call\s*back\s+(?:please|pls|plz|chahiyo|chaiyo|chahiye)|callback\s+(?:please|pls|plz|chahiyo|chaiyo|chahiye))' . $z . '/u', $t) === 1
            // Devanagari Nepali / Hindi
            || preg_match('/(?:मान्छे|मानिस|स्टाफ|अफिस|कर्मचारी)\s*(?:को\s*मान्छे\s*)?(?:सँग|संग|सित)\s*(?:कुरा|कुराकानी)\s*(?:गर्न|गर्नु|गराइ|गरौ|गरौं)/u', $t) === 1
            || preg_match('/(?:मलाई|हामीलाई)\s*(?:एक\s*पटक\s*)?(?:फोन|कल)\s*गर/u', $t) === 1
            || preg_match('/(?:फोन|कल)\s*गर्नु\S*\s*मलाई/u', $t) === 1
            || preg_match('/(?:किसी|इंसान|इन्सान|आदमी|स्टाफ|ऑफिस|मैनेजर)\s*से\s*बात\s*(?:करनी|करना|करवा|करा)/u', $t) === 1
            || preg_match('/^\s(?:मुझे\s*)?बात\s*(?:करनी|करना)\s*है\s$/u', $t) === 1
            || preg_match('/(?:मुझे|हमें)\s*(?:फोन|कॉल|कल)\s*(?:कर|कीजिए|करिए)/u', $t) === 1;
        if (!$hit) {
            return false;
        }
        // an order in the same line ("call me, bholi 2 seat chahiyo") stays the booking's
        if (preg_match('/\d+\s*(?:seat|seats|sit|ticket|tickets|tikat|jana|jan|log|people|persons?|berth|सिट|सीट|टिकट|जना)(?![\p{L}])/u', $t) === 1) {
            return false;
        }
        return true;
    }

    /**
     * The answer to "talk to a human" (see the block comment above). null = not such a line / switched off.
     *
     * @return array{text:string, media:?string, router:string}|null
     */
    public static function humanAsk(string $fromRaw, string $senderDigits, string $body): ?array
    {
        if (!Settings::getBool('wa_human_ask_on', true) || $senderDigits === '' || !self::asksHuman($body)) {
            return null;
        }
        $lang = self::lang($body);
        $phone = Settings::officePhone();
        $ref = '';
        $state = 'none';                                  // none | new | repeat
        try {
            require_once INCLUDE_PATH . '/helpdesk.php';
            if (HelpDesk::on()) {
                // asked in the last hour: the request is already with the office — no second row
                $ref = (string) Database::scalar(
                    "SELECT ticket_ref FROM support_tickets WHERE phone LIKE :p AND category = 'callback' AND source = 'chat'
                        AND status IN ('open','in_progress') AND created_at >= NOW() - INTERVAL 1 HOUR ORDER BY id DESC LIMIT 1", ['p' => '%' . $senderDigits], '');
                if ($ref !== '') {
                    $state = 'repeat';
                } elseif (!Security::rateLimit('wa_human_ask', $senderDigits, 3, 3600)) {
                    $state = 'none';                      // a number hammering the line: the office number only
                } else {
                    $pnr = (string) Database::scalar('SELECT pnr FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => $senderDigits], '');
                    $r = HelpDesk::open(['name' => '', 'phone' => $senderDigits,
                        'country' => str_starts_with((string) preg_replace('/\D/', '', $fromRaw), '977') ? 'NP' : 'IN',
                        'category' => 'callback', 'message' => 'WhatsApp: ' . $body, 'pnr' => $pnr, 'source' => 'chat',
                        'lang' => in_array($lang, ['hi', 'en'], true) ? $lang : 'ne', 'ack' => false]);
                    if (!empty($r['ok'])) {
                        $ref = (string) ($r['ref'] ?? '');
                        $state = 'new';
                        if (empty($r['duplicate'])) {
                            try {
                                require_once INCLUDE_PATH . '/adminalerts.php';
                                AdminAlerts::push('wa_human_ask', 'Call back · WhatsApp ' . $ref,
                                    'A passenger (' . maskPhone($senderDigits) . ') asked to talk to a person on WhatsApp. Please call them back — Help Desk ' . $ref . '.',
                                    '/admin/support-inbox.php', '📞', 'wa_human:' . $senderDigits . ':' . date('YmdH'));
                            } catch (Throwable $e) {
                                // the Help Desk row is the record; the bell is a convenience
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Logger::warning('WaAnswer human ask failed: ' . $e->getMessage(), [], 'whatsapp');
            $state = 'none';
        }
        $tag = $ref !== '' ? ' (' . $ref . ')' : '';
        $text = match ($state) {
            'new' => match ($lang) {
                'rn' => "🙏 Huncha — tapai ko anurodh office ma darta bhayo" . $tag . ". Office ko manche le yahi number ma phone garnuhunchha.\n📞 Hatar bhaye ahile nai phone / WhatsApp: " . $phone,
                'hi' => "🙏 ठीक है — आपका अनुरोध ऑफिस में दर्ज हो गया" . $tag . "। ऑफिस का स्टाफ इसी नंबर पर फ़ोन करेगा।\n📞 जल्दी हो तो अभी फ़ोन / WhatsApp कीजिए: " . $phone,
                'en' => "🙏 Sure — your request is recorded with the office" . $tag . ". A member of staff will call you on this number.\n📞 In a hurry? Call / WhatsApp now: " . $phone,
                default => "🙏 हुन्छ — तपाईंको अनुरोध अफिसमा दर्ता भयो" . $tag . "। अफिसको मान्छेले यही नम्बरमा फोन गर्नुहुन्छ।\n📞 हतार भए अहिले नै फोन / WhatsApp: " . $phone,
            },
            'repeat' => match ($lang) {
                'rn' => "🙏 Tapai ko anurodh office ma pugisakeko chha" . $tag . " — office le phone garnechha.\n📞 Hatar bhaye: " . $phone,
                'hi' => "🙏 आपका अनुरोध ऑफिस तक पहुँच चुका है" . $tag . " — ऑफिस फ़ोन करेगा।\n📞 जल्दी हो तो: " . $phone,
                'en' => "🙏 Your request is already with the office" . $tag . " — they will call you.\n📞 In a hurry? Call / WhatsApp: " . $phone,
                default => "🙏 तपाईंको अनुरोध अफिसमा पुगिसकेको छ" . $tag . " — अफिसले फोन गर्नेछ।\n📞 हतार भए: " . $phone,
            },
            default => match ($lang) {
                'rn' => "🙏 Manche sanga kura garna office ma phone / WhatsApp garnus: " . $phone,
                'hi' => "🙏 किसी से बात करने के लिए ऑफिस को फ़ोन / WhatsApp कीजिए: " . $phone,
                'en' => "🙏 To talk to a person, please call / WhatsApp the office: " . $phone,
                default => "🙏 मान्छेसँग कुरा गर्न अफिसमा फोन / WhatsApp गर्नुहोस्: " . $phone,
            },
        };
        $text .= "\n" . match ($lang) {
            'rn' => 'Aru office ko number: "office ko number" lekhnus.',
            'hi' => 'दूसरे ऑफिस के नंबर: "office ka number" लिखिए।',
            'en' => 'Other offices: write "office number".',
            default => 'अरू अफिसको नम्बर: "office ko number" लेख्नुहोस्।',
        };
        try {
            require_once INCLUDE_PATH . '/ticketbot.php';
            require_once INCLUDE_PATH . '/quickticket.php';
            require_once INCLUDE_PATH . '/wabooking.php';
            if (WaBooking::isOpen($senderDigits)) {
                $text .= WaBooking::waitingNote($senderDigits);   // the half-made booking keeps waiting, said under the answer
            }
        } catch (Throwable $e) {
        }
        self::remember($senderDigits, $body, $text);
        return ['text' => $text, 'media' => null, 'router' => 'human'];
    }

    /* =================================================================
     *  Reading a line
     * ================================================================= */

    /** A question, in the ways this desk receives them (Roman / Devanagari Nepali, Hindi, English). */
    public static function isQuestion(string $text): bool
    {
        if (str_contains($text, '?') || str_contains($text, '？')) {
            return true;
        }
        $t = ' ' . mb_strtolower((string) preg_replace('/[\p{P}]+/u', ' ', $text)) . ' ';
        return preg_match('/(?<![\p{L}\p{M}])(?:k|ke|kya|kati|kitna|kitne|kun|kaun|kasari|kaise|kahile|kaile|kab|kaha|kahan|kata|kina|kyun|kasto|kesto|hola|milcha|milchha|milxa|milega|lagcha|lagchha|lagxa|chalcha|chalchha|chalxa|chalega|janchha|jancha|janxa|parcha|parchha|huncha|hunchha|how|what|when|where|which|why|who|can|does|do\s+you|is\s+there|के|कति|कुन|कसरी|कहिले|कहाँ|कहां|किन|कस्तो|कितना|कितने|कैसे|कब|क्या|क्यों)(?![\p{L}\p{M}])/u', $t) === 1;
    }

    /** A seat count, a date or a pickup in the line: the booking's, never a side question. */
    public static function tripSignal(string $text): bool
    {
        try {
            require_once INCLUDE_PATH . '/ticketbot.php';
            $p = TicketBot::parse($text);
            return (int) ($p['seats'] ?? 0) > 0 || (string) ($p['date'] ?? '') !== '' || (string) ($p['phone'] ?? '') !== '';
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Is this line a request to TRAVEL (a seat, a ticket, a date + a trip)? Only such a line may become a desk draft. */
    public static function looksLikeBooking(string $text): bool
    {
        $t = ' ' . mb_strtolower(TicketBotAscii::digits($text)) . ' ';
        // "How do I book a ticket?", "booking kasari garne?": a question about booking, not a request (code map E)
        if (self::asksHowToBook($text)) {
            return false;
        }
        if (preg_match('/\d+\s*(?:seat|seats|sit|ticket|tickets|tikat|jana|jan|log|people|persons?|berth|सिट|सीट|टिकट|जना)(?![\p{L}])/u', $t) === 1) {
            return true;
        }
        if (preg_match('/(?<![\p{L}\p{M}])(?:ticket|tickets|tikat|tiket|tkt|seat|seats|sit|berth|booking|book|टिकट|सिट|सीट|बुक)(?![\p{L}\p{M}])/u', $t) === 1) {
            return true;
        }
        return preg_match('/(?<![\p{L}\p{M}])(?:jane|jaane|janu|jaanu|jana\s*cha|jaana\s*hai|travel|yatra|aaunu|aune|chahiyo|chahiye|chaiyo|chaiye|जाने|जानु|यात्रा|चाहियो|चाहिए)(?![\p{L}\p{M}])/u', $t) === 1
            && self::tripSignal($text);
    }

    /** "How do I book a ticket?", "booking kasari garne?", "ticket kaise milega" — HOW to book, with no trip in the line. */
    public static function asksHowToBook(string $text): bool
    {
        $t = ' ' . mb_strtolower((string) preg_replace('/[\p{P}]+/u', ' ', TicketBotAscii::digits($text))) . ' ';
        if (mb_strlen($t) > 120 || preg_match('/\d+\s*(?:seats?|sit|tickets?|tikat|jana|jan|log|people|persons?|berth|सिट|सीट|टिकट|जना)(?![\p{L}])/u', $t) === 1) {
            return false;
        }
        $how = preg_match('/(?<![\p{L}\p{M}])(?:kasari|kasri|kaise|kese|how|process|tarika|tariqa|procedure|steps|कसरी|कैसे|तरिका|प्रक्रिया)(?![\p{L}\p{M}])/u', $t) === 1;
        $book = preg_match('/(?<![\p{L}\p{M}])(?:tickets?|tikat|tiket|tkt|seats?|booking|book|reserve|reservation|katne|katnu|kaatne|kinne|kharidne|टिकट|सिट|बुक|बुकिङ|बुकिंग)(?![\p{L}\p{M}])/u', $t) === 1;
        if (!$how || !$book) {
            return false;
        }
        try {
            require_once INCLUDE_PATH . '/ticketbot.php';
            $p = TicketBot::parse($text);
            return (int) ($p['seats'] ?? 0) === 0 && (string) ($p['date'] ?? '') === '' && (string) ($p['phone'] ?? '') === '';
        } catch (Throwable $e) {
            return true;
        }
    }

    /** The answer to "how do I book?": the three ways, in the writer's language. */
    public static function howToBook(string $text, string $lang = ''): string
    {
        $lang = $lang !== '' ? self::mirror($lang, $text) : self::lang($text);
        $web = trim((string) (Settings::company()['web'] ?? ''));
        $web = $web !== '' ? $web : appUrl('');
        $phone = Settings::officePhone();
        return match ($lang) {
            'rn' => "🎫 Ticket katne tarika:\n1. Yahi WhatsApp ma miti + seat sankhya + chadne thau lekhnus (jastai: *bholi 2 seat Surat*)\n2. Website: " . $web . "\n3. Office / counter: " . $phone . " (phone / WhatsApp)",
            'hi' => "🎫 टिकट बुक करने का तरीका:\n1. यहीं WhatsApp पर तारीख + सीट संख्या + चढ़ने की जगह लिखिए (जैसे: *कल 2 सीट सूरत*)\n2. वेबसाइट: " . $web . "\n3. ऑफिस / काउंटर: " . $phone . " (फ़ोन / WhatsApp)",
            'en' => "🎫 How to book:\n1. Here on WhatsApp: send the date + number of seats + pickup point (like: *tomorrow 2 seats Surat*)\n2. Website: " . $web . "\n3. Office / counter: " . $phone . " (call / WhatsApp)",
            default => "🎫 टिकट काट्ने तरिका:\n1. यहीँ WhatsApp मा मिति + सिट संख्या + चढ्ने ठाउँ लेख्नुहोस् (जस्तै: *भोलि २ सिट सुरत*)\n2. वेबसाइट: " . $web . "\n3. अफिस / काउन्टर: " . $phone . " (फोन / WhatsApp)",
        };
    }

    /** The honest last line: no answer here, the office number, and what can be asked — in the writer's language. */
    public static function unsure(string $text, bool $staff = false, string $lang = ''): string
    {
        $lang = $lang !== '' ? self::mirror($lang, $text) : self::lang($text);
        $phone = Settings::officePhone();
        return match ($lang) {
            'rn' => "🙏 Yo kura ko pakka jawaf ma sanga ahile chhaina. Office: " . $phone . " (phone / WhatsApp)\n"
                . ($staff ? "*menu* lekhnus — ma k k garna sakchhu herna." : "Sodhna milchha: bhada · bus kati baje · office ko number · mero ticket (PNR)"),
            'hi' => "🙏 इसका पक्का जवाब अभी मेरे पास नहीं है। ऑफिस: " . $phone . " (फ़ोन / WhatsApp)\n"
                . ($staff ? "*menu* लिखिए — मैं क्या-क्या कर सकता हूँ।" : "पूछ सकते हैं: किराया · बस कितने बजे · ऑफिस का नंबर · मेरा टिकट (PNR)"),
            'en' => "🙏 I do not have a sure answer to that right now. Office: " . $phone . " (call / WhatsApp)\n"
                . ($staff ? "Type *menu* to see what I can do." : "You can ask: fare · bus time · office number · my ticket (PNR)"),
            default => "🙏 यो कुराको पक्का जवाफ अहिले मसँग छैन। अफिस: " . $phone . " (फोन / WhatsApp)\n"
                . ($staff ? "*menu* लेख्नुहोस् — म के के गर्न सक्छु हेर्न।" : "सोध्न मिल्छ: भाडा · बस कति बजे · अफिसको नम्बर · मेरो टिकट (PNR)"),
        };
    }

    /* ---- the desk's own questions (ApWa::answerFirst) ---- */

    /** "chalani deu", "Chalni deu", "मलाई आजको चलानी पठाइदेउ त", "10 tarik ko chalani", "chalani manifest". */
    public static function asksChalani(string $text): bool
    {
        return preg_match('/(?<![\p{L}\p{M}])(?:chalani|chalaani|chalni|chalan|chaalan|challan|chalanee|chalaanee|चलानी|चालानी|चलान|चालान|manifest|menifest)(?![\p{L}\p{M}])/iu', $text) === 1;
    }

    /** "k xa halkhabar", "के छ हालखबर", "aaja kasto cha", "news", "update k cha". */
    public static function asksNews(string $text): bool
    {
        $t = ' ' . mb_strtolower((string) preg_replace('/[\p{P}]+/u', ' ', $text)) . ' ';
        return preg_match('/(?<![\p{L}\p{M}])(?:halkhabar|halkhbr|halkhabr|haalkhabar|halkhaber|hal\s*khabar|हालखबर|हाल\s*खबर|khabar|khabr|खबर|news|update|updates|aaja\s*kasto|aaj\s*kasto|aja\s*kasto|आज\s*कस्तो|kya\s*haal|kya\s*hal|क्या\s*हाल)(?![\p{L}\p{M}])/u', $t) === 1
            && mb_strlen(trim($t)) <= 60;
    }

    /**
     * "<name> ka xan", "<name> kaha cha", "where is <name>", "<name> kata chha" — a PERSON (never a town, the bus or
     * a place). The name, or ''.
     */
    public static function personWhere(string $text): string
    {
        $l = trim(mb_strtolower((string) preg_replace('/[?？!.,;:।"\'()]+/u', ' ', $text)));
        $l = (string) preg_replace('/\s+/u', ' ', $l);
        $name = '';
        if (preg_match('/^where\s+is\s+(\p{L}[\p{L}\p{M}.]*(?:\s+\p{L}[\p{L}\p{M}.]*){0,2})$/u', $l, $m) === 1) {
            $name = $m[1];
        } elseif (preg_match('/^(\p{L}[\p{L}\p{M}.]*(?:\s+\p{L}[\p{L}\p{M}.]*){0,2})\s+(?:ji\s+|sir\s+|dai\s+|bhai\s+)?(?:ka|kaha|kata|kahan|कहाँ|कता)\s*(?:xan|chan|chhan|xa|cha|chha|hunuhunchha|hunuhuncha|hunu\s*huncha|hai|hain|छन्|छ|हुनुहुन्छ)?$/u', $l, $m) === 1) {
            $name = $m[1];
        }
        if ($name === '') {
            return '';
        }
        $stop = ['bus', 'gadi', 'gaadi', 'office', 'counter', 'ticket', 'mero', 'my', 'tapai', 'hamro', 'yo', 'tyo', 'k', 'ke', 'sabai', 'parking', 'pickup',
            'driver', 'conductor', 'agent', 'staff', 'manager', 'ceo', 'malik', 'location', 'map', 'aaja', 'bholi'];
        foreach (preg_split('/\s+/u', $name) ?: [] as $w) {
            if (in_array($w, $stop, true)) {
                return '';
            }
        }
        require_once INCLUDE_PATH . '/wadirectory.php';
        if (WaDirectory::townIn($name) !== '') {
            return '';
        }
        return mb_strlen($name) >= 3 ? $name : '';
    }

    /** The reply joins the assistant's thread, so the next question has its context (no passwords, no vault here). */
    public static function remember(string $digits, string $user, string $reply): void
    {
        try {
            require_once INCLUDE_PATH . '/aiagent.php';
            AiAgent::remember($digits, $user, $reply);
        } catch (Throwable $e) {
        }
    }
}

/** Devanagari digits → ASCII without loading TicketBot first. */
final class TicketBotAscii
{
    public static function digits(string $s): string
    {
        return strtr($s, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
    }
}
