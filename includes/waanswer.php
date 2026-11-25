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
        $hits = 0;
        foreach ([
            '/thank(?:s| you) for (?:using|contacting|reaching|messaging|choosing|connecting|your (?:message|interest|enquiry|inquiry))/u',
            '/(?:this is an?|is an) (?:automated|automatic|auto[- ]?generated) (?:message|reply|response)/u',
            '/\b(?:auto[- ]?reply|autoreply|auto[- ]?response|automatic reply|out of (?:the )?office)\b/u',
            '/\b(?:do not|don\'?t|please do not) reply\b|\bno[- ]?reply\b/u',
            '/\bjust type\s*["\']?(?:hi|hello|menu)\b/u',
            '/\b(?:we will|we\'ll|our (?:team|executive|agent) will) (?:get back|contact you|reply|respond|call you)/u',
            '/play\.google\.com\/store|apps\.apple\.com\//u',
            '/\balways for your support\b/u',
            '/\bplease let us inquiry\b/u',
            '/\b(?:press|reply|type|send) [0-9]\b.{0,20}\b(?:for|to)\b/u',
            '/\bwhatsapp services\b/u',
            '/\bbusiness hours\b|\bcurrently (?:closed|unavailable|away)\b/u',
        ] as $re) {
            if (preg_match($re, $l) === 1) {
                $hits++;
            }
        }
        if ($hits === 0) {
            return false;
        }
        // a real passenger's line with a booking in it is never silenced by one stray phrase
        if ($hits === 1 && preg_match('/(?<![\p{L}])(?:seat|sit|ticket|tikat|bus|gadi|book|bhada|fare|kati|kaha|chahiyo|chahiye|सिट|टिकट|बस|भाडा)(?![\p{L}])/u', $l) === 1
            && preg_match('/thank(?:s| you) for (?:using|contacting)|whatsapp services|always for your support|please let us inquiry/u', $l) !== 1) {
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
        if (!self::on() || $senderDigits === '' || trim($body) === '' || mb_strlen($body) > 400) {
            return null;
        }
        if (in_array((string) ($who['role'] ?? 'customer'), ['staff', 'admin'], true)) {
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
        if (preg_match('/\d+\s*(?:seat|seats|sit|ticket|tickets|tikat|jana|jan|log|people|persons?|berth|सिट|सीट|टिकट|जना)(?![\p{L}])/u', $t) === 1) {
            return true;
        }
        if (preg_match('/(?<![\p{L}\p{M}])(?:ticket|tickets|tikat|tiket|tkt|seat|seats|sit|berth|booking|book|टिकट|सिट|सीट|बुक)(?![\p{L}\p{M}])/u', $t) === 1) {
            return true;
        }
        return preg_match('/(?<![\p{L}\p{M}])(?:jane|jaane|janu|jaanu|jana\s*cha|jaana\s*hai|travel|yatra|aaunu|aune|chahiyo|chahiye|chaiyo|chaiye|जाने|जानु|यात्रा|चाहियो|चाहिए)(?![\p{L}\p{M}])/u', $t) === 1
            && self::tripSignal($text);
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
