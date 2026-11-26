<?php
/**
 * includes/waedit.php — change a ticket on WhatsApp, behind a 6-digit code
 * (10 Oct 2026).
 *
 * After a free ticket reaches a passenger (their Meta window is open) one
 * more free message offers:  [✏️ Name] [📅 Date] [⋯ More → Agent / Cancel].
 * The same actions start from typed or spoken words ("naam galat cha",
 * "miti badalnu cha", "agent change", "ticket cancel garnu cha") — a voice
 * note arrives here already transcribed (whatsapp/webhook.php → WaVoice).
 *
 * Every action asks for a 6-digit code sent in the chat (otp_codes, hashed,
 * 10 minutes, 5 tries) and only then commits. The commit is NOT new money
 * logic — it is the same tool the assistant and the desk use:
 *   name   → AiTools::run('rename_passenger')        (Ticket re-issued)
 *   date   → AiTools::run('quote_ticket_fix') preview, then 'fix_ticket'
 *            (BookingService::rebookLeg, as admin/reschedule.php)
 *   agent  → WaAgentRoute::changeAgent()             (next route agent)
 *   cancel → a REQUEST to the office only (the owner settles every cancel
 *            and refund by hand — never automated)
 * Each one is a row in ticket_edit_audit (ticket, field, old, new, by, when,
 * otp_verified). All messages are free (the person just wrote to us).
 *
 * 16 Nov 2026 (P6-2d): requestCancel() files the same cancel REQUEST for the
 * WhatsApp assistant (AiTools cancel_ticket, a passenger, wa_ai_cancel_mode
 * 'request' = the default), with the passenger's reason and the quoted refund.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaEdit
{
    /** Tests: fn(): string — the next code. */
    public static ?Closure $codeGen = null;

    private const TTL = 900;          // a half-finished edit is forgotten after 15 min
    private const OTP_MIN = 10;

    /* Words are matched as WHOLE words (a token) or as the START of a word
       (a stem: "badal" → badalnu / badlinu) — never inside another word, so
       "credited" is not "edit" and "update" is not "date". */
    private const CHANGE_STEM  = ['badal', 'badla', 'badl', 'sachya', 'sachhya', 'sachchya', 'milau', 'milaa', 'galat', 'change', 'correct', 'बदल', 'सच्या', 'सच्च्या', 'मिलाउ', 'गलत', 'चेन्ज', 'चेंज'];
    private const CHANGE_WORD  = ['edit'];
    private const NAME_WORD    = ['naam', 'nam', 'name', 'नाम'];
    private const DATE_STEM    = ['miti', 'tarikh', 'tareekh', 'मिति', 'तारीख', 'तारिख'];
    private const DATE_WORD    = ['date', 'din', 'दिन'];
    private const CANCEL_STEM  = ['cancel', 'cancle', 'cansel', 'radd', 'रद्द', 'क्यान्सल', 'कैंसल', 'कैन्सल'];
    private const AGENT_STEM   = ['agent', 'ejent', 'एजेन्ट', 'एजेंट'];
    private const OTHER_WORD   = ['arko', 'aru', 'dusra', 'dusre', 'naya', 'other', 'another', 'अर्को', 'अरू', 'दूसरा', 'नयाँ'];
    private const CONFIRM_STEM = ['confirm', 'conform', 'pakka', 'पक्का', 'कन्फर्म'];
    private const ASK_WORD     = ['garnu', 'gardinus', 'gardinu', 'garidinu', 'garidinus', 'karna', 'kardo', 'karde', 'karo', 'garau', 'garna', 'chahiyo', 'chaiyo', 'chahincha', 'please', 'गर्नु', 'गरिदिनु', 'गरिदिनुस्', 'करना', 'चाहियो'];
    /* 26 Nov 2026 (fx1): a seat change and a refund are asked in words too. A seat needs a real "change" word
       ("seat milau" is somebody ASKING for a seat, never a change); a refund needs an asking word. */
    private const SEAT_WORD        = ['seat', 'seats', 'sit', 'sitt', 'berth', 'सिट', 'सीट'];
    private const SEAT_CHANGE_STEM = ['badal', 'badla', 'badl', 'change', 'exchange', 'swap', 'बदल', 'चेन्ज', 'चेंज'];
    private const REFUND_STEM      = ['refund', 'rifand', 'rifund', 'रिफन्ड', 'रिफंड'];
    /* "firta" / "wapas" also mean a RETURN trip ("Nepal bata wapas aaune ticket chahiyo" is an order): they are a
       refund only beside a money word. */
    private const BACK_STEM        = ['firta', 'phirta', 'pharta', 'wapas', 'vapas', 'wapis', 'फिर्ता', 'वापस'];
    private const MONEY_WORD       = ['paisa', 'paise', 'paisaa', 'rupaya', 'rupaye', 'rupiya', 'rakam', 'amount', 'money', 'payment', 'पैसा', 'पैसे', 'रकम', 'रुपैयाँ'];
    private const REFUND_ASK       = ['chahiye', 'chaiye', 'dinus', 'dinu', 'dinuhos', 'deu', 'dijiye', 'dedo', 'do', 'want', 'need', 'give', 'चाहिए', 'दिनुस्', 'दिनुहोस्', 'देउ', 'दीजिए'];

    private function __construct() {}

    public static function ready(): bool
    {
        try {
            return Database::fetch("SHOW TABLES LIKE 'ticket_edit_audit'") !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    /* -----------------------------------------------------------------
     *  The buttons after a ticket
     * ----------------------------------------------------------------- */

    /**
     * A free ticket just reached $phone: offer the change buttons (once an
     * hour per ticket). $defer = only note it; cron/wa-agent-route.php sends
     * it within a minute, so a booking request never waits on Meta.
     */
    public static function afterTicket(int $bookingId, string $phone, bool $defer = false): void
    {
        try {
            if (!self::ready() || !Settings::getBool('wa_ticket_buttons_on', true)) {
                return;
            }
            require_once INCLUDE_PATH . '/waagentroute.php';
            $intl = WaAgentRoute::intl($phone);
            if ($defer) {
                if ($intl !== '' && $bookingId > 0) {
                    Database::run(
                        "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_tkbtn_due', :k, :v, 'waedit')
                         ON DUPLICATE KEY UPDATE kvalue = :v2",
                        ['k' => $bookingId . ':' . $intl, 'v' => (string) time(), 'v2' => (string) time()]
                    );
                }
                return;
            }
            $pnr  = (string) Database::scalar("SELECT pnr FROM bookings WHERE id = :b AND status IN ('confirmed','completed')", ['b' => $bookingId], '');
            if ($intl === '' || $pnr === '' || !Security::rateLimit('wa_tkbtn', $bookingId . ':' . $intl, 1, 3600)) {
                return;
            }
            WaAgentRoute::send($intl, "टिकट " . $pnr . " मा केही मिलाउनु छ? · Need a change?\nनाम वा मिति सच्याउन, एजेन्ट बदल्न वा रद्द अनुरोध गर्न तल थिच्नुहोस्।", null, self::menuUi($pnr), 'ticket_menu', $bookingId);
        } catch (Throwable $e) {
            Logger::warning('ticket buttons failed: ' . $e->getMessage(), ['booking' => $bookingId], 'whatsapp');
        }
    }

    /** Cron: send the change buttons noted by afterTicket(…, true). Returns how many were handled. */
    public static function flushDeferred(int $limit = 20): int
    {
        if (!self::ready()) {
            return 0;
        }
        $n = 0;
        foreach (Database::fetchAll("SELECT kkey, kvalue FROM kv_store WHERE kscope = 'wa_tkbtn_due' ORDER BY id ASC LIMIT " . max(1, $limit)) as $r) {
            Database::run("DELETE FROM kv_store WHERE kscope = 'wa_tkbtn_due' AND kkey = :k", ['k' => (string) $r['kkey']]);
            [$bid, $intl] = array_pad(explode(':', (string) $r['kkey'], 2), 2, '');
            if ((int) $r['kvalue'] >= time() - 1800 && (int) $bid > 0 && $intl !== '') {
                self::afterTicket((int) $bid, $intl);
                $n++;
            }
        }
        return $n;
    }

    private static function menuUi(string $pnr): array
    {
        return ['kind' => 'buttons', 'buttons' => [
            ['id' => 'shg:ed name ' . $pnr, 'title' => '✏️ नाम · Name'],
            ['id' => 'shg:ed date ' . $pnr, 'title' => '📅 मिति · Date'],
            ['id' => 'shg:ed more ' . $pnr, 'title' => '⋯ अरू · More'],
        ]];
    }

    /* -----------------------------------------------------------------
     *  The conversation
     * ----------------------------------------------------------------- */

    /**
     * @param array<string,mixed>|null $who AiTools::whoIs() of the sender
     * @return array{text:string, media?:?string, ui?:?array}|null
     */
    public static function handle(string $fromRaw, string $body, ?array $who = null): ?array
    {
        if (!self::ready()) {
            return null;
        }
        require_once INCLUDE_PATH . '/waagentroute.php';
        $intl = WaAgentRoute::intl($fromRaw);
        $body = trim($body);
        if ($intl === '' || $body === '') {
            return null;
        }
        $t = self::norm($body);

        // 1. A button: "ed name SHG-2026-00584".
        if (preg_match('/^ed\s+(name|date|more|agent|cancel|menu|stop)\s+(shg-[a-z0-9-]+)$/i', $t, $m) === 1) {
            return self::start($fromRaw, $intl, strtolower($m[1]), strtoupper($m[2]), $who);
        }

        // 2. A conversation already open.
        $st = self::state($intl);
        if ($st !== null) {
            if (in_array($t, ['stop', 'cancel edit', 'chhod', 'chod', 'chhodnus', 'rehne do', 'रद्द गर्नु पर्दैन', 'band', 'बन्द', 'exit', 'quit'], true)) {
                self::clear($intl);
                return self::say('ठिक छ, केही परिवर्तन गरिएन। · OK, nothing was changed.');
            }
            $r = self::continueStep($fromRaw, $intl, $st, $body, $t, $who);
            if ($r !== null) {
                return $r;
            }
        }

        // 3. Words (typed or spoken): "naam galat cha", "SHG-… ko miti badalnu cha", "agent change".
        $intent = self::intentOf($t, $body);
        if ($intent === '') {
            return null;
        }
        /* Staff and the office keep their own tools for these words (office
           confirm / cancel through the assistant): the word path is the
           passenger's. Buttons above still work for anyone on the booking. */
        if ($who === null) {
            try {
                require_once INCLUDE_PATH . '/aitools.php';
                $who = AiTools::whoIs($fromRaw);
            } catch (Throwable $ignored) {
            }
        }
        if (is_array($who) && in_array((string) ($who['role'] ?? ''), ['staff', 'admin'], true)) {
            return null;
        }
        /* An order still open, or a ticket sold on this number minutes ago:
           the booking conversation answers its own corrections first
           (WaBooking fixes a just-cut name at once, inside that chat). */
        try {
            require_once INCLUDE_PATH . '/ticketbot.php';
            require_once INCLUDE_PATH . '/quickticket.php';
            require_once INCLUDE_PATH . '/wabooking.php';
            $digits = normalisePhone($fromRaw);
            if (WaBooking::isOpen($digits) || WaBooking::justSold($digits)) {
                return null;
            }
        } catch (Throwable $ignored) {
        }
        $pnr = self::pnrIn($body);
        if ($pnr === '') {
            $pnr = self::latestPnr($fromRaw);
        }
        /* 26 Nov 2026 (fx1; live: tickets cut by an agent sit on the AGENT's number, so the passenger's own
           "ticket cancel garnu cha" / "seat badalnu cha" found no ticket here, fell through and was parked as a NEW
           booking draft — "our desk will send your ticket"): these words now always get the honest answer — how to
           ask (the PNR from the booking's mobile, or the office) and that the office settles it by hand. Nothing
           is started and nothing is told about anybody's ticket. Switch wa_change_words_on (0 = fall through). */
        $helpIntent = self::changeWordsOn() && self::helpWorthy($intent, $t);
        /* A COMPLAINT that also says "ticket cancel gardinus" ("staff le gali garyo, …") from a number with no ticket
           of its own stays the Help Desk's: WaLocal opens the complaint for it, as before fx1 (review fix). */
        if ($helpIntent && self::complaintTalk($body)) {
            $helpIntent = false;
        }
        if ($pnr === '') {
            return $helpIntent ? self::noTicketHelp($intent, $body) : null;   // no live ticket on this number
        }
        if ($intent === 'confirm') {
            return self::showTicket($pnr, $fromRaw, $who);
        }
        $b = Database::fetch('SELECT * FROM bookings WHERE pnr = :p LIMIT 1', ['p' => $pnr]);
        if ($b === null || !self::mayEdit($b, $fromRaw, $who)) {
            // words about a ticket that is not theirs (or no such ticket — the same words, so a PNR is never confirmed)
            return $helpIntent ? self::noTicketHelp($intent, $body) : null;
        }
        if ($intent === 'seat') {
            return self::seatHelp($b, $body);        // never self-service: the ticket page files the request
        }
        if ($intent === 'refund') {
            return self::refundHelp($b, $body);      // money is settled by the office by hand
        }
        $opened = self::start($fromRaw, $intl, $intent, $pnr, $who);
        /* 31 Oct 2026 (passenger sim: "naam galat cha, Ram Thapa hoina Ram Bahadur Thapa ho" / "miti badalnu paryo,
           3 November ma jane" were asked for the new name / date again): a new value already in the opening words is
           taken at once — old → new, then the 6-digit code, exactly as if it had been typed next. */
        if (in_array($intent, ['name', 'date'], true)) {
            $value = $intent === 'name' ? self::nameFrom($body) : (self::parseDate($body) !== '' ? $body : '');
            $st = self::state($intl);
            if ($value !== '' && $st !== null && in_array((string) ($st['step'] ?? ''), ['name_new', 'date_new'], true)) {
                $next = self::continueStep($fromRaw, $intl, $st, $value, self::norm($value), $who);
                if ($next !== null) {
                    return $next;
                }
            }
        }
        return $opened;
    }

    /** "Ram Thapa hoina Ram Bahadur Thapa ho" / "sahi naam: Ram Bahadur Thapa" → the corrected name; '' = none said. */
    private static function nameFrom(string $body): string
    {
        $b = trim((string) preg_replace('/\s+/u', ' ', $body));
        if (preg_match('/(?:hoina|hoena|होइन|nahi|नहीं)\s*,?\s*([\p{L}\p{M} .\'-]{2,60}?)\s*(?:ho|हो|hunu\s*parcha|hunuparcha|banaunus|banaideu|banau|chahiyo|hai|है)?\s*[.!।]*$/iu', $b, $m) === 1
            || preg_match('/(?:sahi|sachha|सही|correct|naya|नयाँ|new)\s*(?:naam|name|नाम)\s*[:\-]?\s*([\p{L}\p{M} .\'-]{2,60}?)\s*(?:ho|हो)?\s*[.!।]*$/iu', $b, $m) === 1) {
            $n = trim($m[1], " .'-");
            return count(preg_split('/\s+/u', $n) ?: []) <= 5 ? $n : '';
        }
        return '';
    }

    /** fx1 switch: cancel / change / refund words always get an answer (never a booking draft). Default ON. */
    public static function changeWordsOn(): bool
    {
        return Settings::getBool('wa_change_words_on', true);
    }

    /**
     * fx1: is this intent sure enough to answer a number that has NO ticket here? Cancel, refund and a seat change
     * are; a date only with a real "change" word ("2 din pachi ko ticket milau" is a booking, not a date change);
     * a name never ("mero naam Ram ho, ticket milaunus" is a booking too).
     */
    private static function helpWorthy(string $intent, string $t): bool
    {
        if (in_array($intent, ['cancel', 'refund', 'seat'], true)) {
            return true;
        }
        if ($intent !== 'date') {
            return false;
        }
        $tk = self::tokens($t);
        return self::stem($tk, self::SEAT_CHANGE_STEM) && (self::stem($tk, self::DATE_STEM) || self::word($tk, ['date']))
            && preg_match('/\d+\s*(?:seat|seats|sit|ticket|tickets|tikat|jana|jan|सिट|सीट|टिकट|जना)(?![\p{L}])/u', $t) !== 1
            && !self::wordedOrder($t);
    }

    /**
     * fx1 (review fix): an order with its seat count in WORDS — "ek seat chahiye surat se", "dui jana ko ticket
     * chahiyo" — beside a wanting word. "plan badal gaya, ab 20 tarikh ko jana hai ek seat chahiye" is a booking
     * request, not a date change. (A count in digits is caught by the callers' own \d+ rule.)
     */
    private static function wordedOrder(string $t): bool
    {
        $a = '(?<![\p{L}\p{M}])';
        $z = '(?![\p{L}\p{M}])';
        return preg_match('/' . $a . '(?:ek|euta|eauta|yeuta|auta|dui|duita|duiwata|(?<!kar\s)(?<!kara\s)(?<!de\s)do|tin|teen|tinta|tinwata|char|chaar|charwata|panch|paanch|one|two|three|four|five|एक|एउटा|दुई|दुइटा|दो|तीन|तिन|चार|पाँच|पांच)'
                . '\s+(?:(?:wota|wata|ota|jana\s+ko|jana\s+ka|logo\s+ka|log\s+ka)\s+)?(?:seat|seats|sit|ticket|tickets|tikat|tiket|jana|jan|सिट|सीट|टिकट|जना)' . $z . '/u', $t) === 1
            && preg_match('/' . $a . '(?:chahiyo|chaiyo|chahiye|chaiye|chahincha|chahinchha|book|buk|booking|jana\s+hai|jaana\s+hai|jane\s+ho|चाहियो|चाहिए|चाहिये|बुक)' . $z . '/u', $t) === 1;
    }

    /** fx1 (review fix): does the line carry a complaint signal (WaLocal's reading)? Then the Help Desk answers it. */
    private static function complaintTalk(string $raw): bool
    {
        try {
            require_once INCLUDE_PATH . '/ticketbot.php';
            require_once INCLUDE_PATH . '/walocal.php';
            return WaLocal::complaintLine($raw);
        } catch (Throwable $ignored) {
            return false;
        }
    }

    /**
     * fx1: the words of a ticket change / cancel / refund, whoever writes them and however (a question too).
     * WaBot uses it so such a line is never parked as a NEW booking draft.
     */
    public static function changeWords(string $raw): bool
    {
        $t = self::norm($raw);
        if ($t === '' || mb_strlen($t) > 160) {
            return false;
        }
        $tk = self::tokens($t);
        if (count($tk) < 2 || preg_match('/\d+\s*(?:seat|seats|sit|ticket|tickets|tikat|jana|jan|सिट|सीट|टिकट|जना)(?![\p{L}])/u', $t) === 1) {
            return false;                                  // a bare "cancel" is a chat's "no"; "2 seat chahiyo …" is an order
        }
        // a seat / date change beside an order worded in full ("… ek seat chahiyo surat bata, window seat change
        // garera dinu") is the order's: the desk draft keeps it (review fix)
        return self::stem($tk, self::CANCEL_STEM) || self::refundTalk($tk)
            || (self::stem($tk, self::SEAT_CHANGE_STEM) && !self::wordedOrder($t)
                && (self::word($tk, self::SEAT_WORD) || self::stem($tk, self::DATE_STEM) || self::word($tk, ['date'])));
    }

    /**
     * fx1: "refund …", or "paisa firta / paise wapas …" — never a bare "firta / wapas" (a return trip).
     * Review fix: also not a refund —
     *   - a refund that is NOT wanted, said right beside the word: "refund nachahiyo, ticket nai chahiyo",
     *     "paisa wapas nahi chahiye", "no refund";
     *   - "wapas / firta" as the RETURN trip in a line that also holds a money word: "Nepal bata wapas aune ticket
     *     chahiyo, paisa kati lagcha". A back-word counts for the refund when it sits with the money ("paisa
     *     firta …", "wapas paise …"); one that leads a travel word, or stands in a what-does-it-cost line, does not.
     * @param list<string> $tk
     */
    private static function refundTalk(array $tk): bool
    {
        $line = ' ' . implode(' ', $tk) . ' ';
        $ref  = '(?:refund\S*|rifand\S*|rifund\S*|रिफन्ड\S*|रिफंड\S*|firta|phirta|pharta|wapas|vapas|wapis|फिर्ता|वापस)';
        if (preg_match('/ ' . $ref . ' (?:\S+ )?(?:nachahiyo|nachaiyo|nachahine|chahidaina|chaidaina|chahinna|chahidena|chahiyena|chahindaina|चाहिँदैन|चाहिदैन|चाहिएन) /u', $line) === 1
            || preg_match('/ ' . $ref . ' (?:\S+ )?(?:nahi|nahin|नहीं|नही) (?:chahiye|chaiye|chahie|चाहिए|चाहिये) /u', $line) === 1
            || preg_match('/ (?:don t|dont|do not|no) (?:want |need )?(?:a |any |the |my )?refund /u', $line) === 1) {
            return false;
        }
        if (self::stem($tk, self::REFUND_STEM)) {
            return true;
        }
        if (!self::stem($tk, self::BACK_STEM) || !self::word($tk, self::MONEY_WORD)) {
            return false;
        }
        // what does it cost: "paisa kati lagcha", "kitna paisa lagega", "bhada kati", "how much"
        $priceAsk = preg_match('/ (?:kati|katti|kitna|kitne|kitni|कति|कितना|कितने|कितनी) (?:\S+ )?(?:lag\S*|par\S*|लाग\S*|पर्छ|लगेगा|लगता) /u', $line) === 1
            || preg_match('/ (?:bhada|bhara|bhaada|fare|kiraya|kiraaya|price|rate|भाडा|किराया) /u', $line) === 1
            || str_contains($line, ' how much ');
        $trip = ['aune', 'aaune', 'aaunu', 'aunu', 'jane', 'jaane', 'janu', 'aane', 'ane', 'aana', 'jana', 'jaana', 'farkine', 'farkane', 'pharkine', 'lautne', 'lautna',
            'आउने', 'जाने', 'आने', 'फर्किने', 'लौटने'];
        $n = count($tk);
        for ($i = 0; $i < $n; $i++) {
            if (!self::stem([$tk[$i]], self::BACK_STEM)) {
                continue;
            }
            // with the money: "paisa firta", "paisa malai firta", "wapas paise"
            if (in_array($tk[$i - 1] ?? '', self::MONEY_WORD, true) || in_array($tk[$i - 2] ?? '', self::MONEY_WORD, true)
                || in_array($tk[$i + 1] ?? '', self::MONEY_WORD, true)) {
                return true;
            }
            $after = $tk[$i + 1] ?? '';
            $tripAfter = in_array($after, $trip, true)
                || (in_array($after, ['ko', 'ka', 'ki', 'को', 'का', 'की'], true) && in_array($tk[$i + 2] ?? '', ['ticket', 'tikat', 'tiket', 'seat', 'bus', 'टिकट', 'सिट', 'बस'], true));
            if ($tripAfter || $priceAsk) {
                continue;                                  // this back-word is the return trip / a fare question
            }
            return true;                                   // the old reading: a back-word and a money word in one line
        }
        return false;
    }

    /** Which action these words ask for ('' = none). */
    public static function intentOf(string $t, string $raw): string
    {
        if (mb_strlen($t) > 90) {
            return '';
        }
        $tk = self::tokens($t);
        $change = self::stem($tk, self::CHANGE_STEM) || self::word($tk, self::CHANGE_WORD);
        $cancel = self::stem($tk, self::CANCEL_STEM);
        /* fx1: "seat badalnu cha", "mero seat change gardinus", "seat badalna milcha?" — a statement or a question,
           the answer is the same (where the request is made), so it is read before the rule-question exit below.
           Never a line with a seat COUNT ("2 seat chahiyo, plan change bhayo" stays an order). */
        if (self::changeWordsOn() && !$cancel && self::word($tk, self::SEAT_WORD) && self::stem($tk, self::SEAT_CHANGE_STEM)
            && count($tk) <= 10 && !self::word($tk, self::NAME_WORD) && !self::stem($tk, self::DATE_STEM)
            && preg_match('/\d+\s*(?:seat|seats|sit|ticket|tickets|tikat|jana|jan|सिट|सीट|टिकट|जना)(?![\p{L}])/u', $t) !== 1) {
            return 'seat';
        }
        // "ticket confirm bhayo?" is a question by nature — and exactly this one.
        if (self::stem($tk, self::CONFIRM_STEM) && !$cancel && !$change
            && (self::word($tk, ['ticket', 'टिकट', 'bhayo', 'bhaye', 'hua', 'cha', 'xa', 'हो', 'भयो']) || self::pnrIn($raw) !== '')) {
            return 'confirm';
        }
        // A question about the rule ("cancel garda kati paisa katcha?") is not an order.
        $asks = self::word($tk, self::ASK_WORD) || str_contains($t, 'kar do');
        try {
            require_once INCLUDE_PATH . '/ailearn.php';
            if (AiLearn::isQuestion($raw) && !$asks) {
                return '';
            }
        } catch (Throwable $ignored) {
        }
        if (self::stem($tk, self::AGENT_STEM) && ($change || self::word($tk, self::OTHER_WORD))) {
            return 'agent';
        }
        if (self::word($tk, self::NAME_WORD) && $change) {
            return 'name';
        }
        if ((self::stem($tk, self::DATE_STEM) || self::word($tk, self::DATE_WORD)) && $change) {
            return 'date';
        }
        if ($cancel && ($asks || self::word($tk, ['ticket', 'टिकट']) || self::pnrIn($raw) !== '')) {
            return 'cancel';
        }
        /* fx1: "refund chahiyo", "mero paisa firta dinus", "paise wapas chahiye" — a demand, not the rule question
           (that left above). A line that is a COMPLAINT ("conductor le badi paisa liyo, firta chahiyo") stays the
           Help Desk's: WaLocal opens the ticket for it. */
        if (self::changeWordsOn() && self::refundTalk($tk) && ($asks || self::word($tk, self::REFUND_ASK)) && count($tk) <= 10) {
            try {
                require_once INCLUDE_PATH . '/ticketbot.php';
                require_once INCLUDE_PATH . '/walocal.php';
                if (WaLocal::complaintLine($raw)) {
                    return '';
                }
            } catch (Throwable $ignored) {
            }
            return 'refund';
        }
        return '';
    }

    /* -----------------------------------------------------------------
     *  fx1 (26 Nov 2026): the honest answers — nothing is started here
     * ----------------------------------------------------------------- */

    /** ne | rn | hi | en, the way the person wrote (WaAnswer::lang). */
    private static function replyLang(string $body): string
    {
        try {
            require_once INCLUDE_PATH . '/waanswer.php';
            return WaAnswer::lang($body);
        } catch (Throwable $e) {
            return 'ne';
        }
    }

    private static function tr(string $lang, string $ne, string $rn, string $hi, string $en): string
    {
        return match ($lang) { 'rn' => $rn, 'hi' => $hi, 'en' => $en, default => $ne };
    }

    /** No ticket of theirs on this number: how to ask, and who settles it. Says nothing about anybody's ticket. */
    private static function noTicketHelp(string $intent, string $body): array
    {
        $l = self::replyLang($body);
        $phone = Settings::officePhone();
        $what = match ($intent) {
            'date'   => self::tr($l, 'मिति परिवर्तन', 'Miti badalne', 'तारीख बदलना', 'Date change'),
            'seat'   => self::tr($l, 'सिट परिवर्तन', 'Seat badalne', 'सीट बदलना', 'Seat change'),
            'refund' => self::tr($l, 'रकम फिर्ता', 'Paisa firta', 'रिफंड', 'Refund'),
            default  => self::tr($l, 'टिकट रद्द', 'Ticket cancel', 'टिकट रद्द', 'Ticket cancel'),
        };
        return self::say(self::tr($l,
            "ℹ️ " . $what . ": यो नम्बरमा चालु टिकट भेटिएन।\n• टिकट बुक गर्दा दिएको मोबाइलबाट PNR (जस्तै SHG-2026-00123) सहित लेख्नुहोस्, वा\n• अफिसमा फोन / WhatsApp: " . $phone
                . "\nरद्द र रकम फिर्ता अफिसले नै हातले मिलाउँछ — यहाँ आफैं केही रद्द वा फिर्ता हुँदैन।",
            "ℹ️ " . $what . ": yo number ma chalu ticket bhetiena.\n• Ticket book garda dieko mobile bata PNR (jastai SHG-2026-00123) sahit lekhnus, wa\n• Office ma phone / WhatsApp: " . $phone
                . "\nCancel ra paisa firta office le nai haat le milauchha — yaha afai kehi cancel wa firta hudaina.",
            "ℹ️ " . $what . ": इस नंबर पर कोई चालू टिकट नहीं मिला।\n• टिकट बुक करते समय दिए गए मोबाइल से PNR (जैसे SHG-2026-00123) के साथ लिखिए, या\n• ऑफिस को फ़ोन / WhatsApp: " . $phone
                . "\nरद्द और रिफंड ऑफिस खुद हाथ से करता है — यहाँ अपने आप कुछ रद्द या वापस नहीं होता।",
            "ℹ️ " . $what . ": no live ticket was found on this number.\n• Write from the mobile used for the booking, with the PNR (like SHG-2026-00123), or\n• call / WhatsApp the office: " . $phone
                . "\nCancellations and refunds are settled by the office by hand — nothing is cancelled or refunded here automatically."));
    }

    /** Their own ticket, a seat change: the ticket page files the REQUEST (admin/ticket-requests.php); the office decides. */
    private static function seatHelp(array $b, string $body): array
    {
        $l = self::replyLang($body);
        $pnr = (string) $b['pnr'];
        $phone = Settings::officePhone();
        $link = '';
        try {
            require_once INCLUDE_PATH . '/ticketrequests.php';
            require_once INCLUDE_PATH . '/ticketlink.php';
            if (TicketRequests::enabled() && (string) $b['status'] === 'confirmed') {
                $link = TicketLink::forBooking((int) $b['id']);
            }
        } catch (Throwable $e) {
            $link = '';
        }
        $head = self::tr($l,
            "💺 टिकट " . $pnr . " को सिट यहाँ आफैं बदलिँदैन — अनुरोध अफिसमा जान्छ, सिट खाली भए अफिसले मिलाउँछ।",
            "💺 Ticket " . $pnr . " ko seat yaha afai badlidaina — anurodh office ma janchha, seat khali bhaye office le milauchha.",
            "💺 टिकट " . $pnr . " की सीट यहाँ अपने आप नहीं बदलती — अनुरोध ऑफिस को जाता है, सीट खाली हो तो ऑफिस बदल देता है।",
            "💺 The seat on ticket " . $pnr . " is not changed here by itself — a request goes to the office, which changes it if the seat is free.");
        $how = $link !== ''
            ? self::tr($l,
                "🔗 टिकट पेज खोलेर \"सिट परिवर्तन माग्नुहोस्\" थिच्नुहोस्:\n" . $link,
                "🔗 Ticket page kholera \"Request seat change\" thichnus:\n" . $link,
                "🔗 टिकट पेज खोलकर \"Request seat change\" दबाइए:\n" . $link,
                "🔗 Open the ticket page and tap \"Request seat change\":\n" . $link)
            : '';
        $office = self::tr($l, "📞 अफिस (फोन / WhatsApp): ", "📞 Office (phone / WhatsApp): ", "📞 ऑफिस (फ़ोन / WhatsApp): ", "📞 Office (call / WhatsApp): ") . $phone;
        return self::say($head . ($how !== '' ? "\n" . $how : '') . "\n" . $office);
    }

    /** Their own ticket, a refund demand: never automatic. The way to ask for a cancel, the rule, the office. */
    private static function refundHelp(array $b, string $body): array
    {
        $l = self::replyLang($body);
        $pnr = (string) $b['pnr'];
        $phone = Settings::officePhone();
        return self::say(self::tr($l,
            "↩️ रकम फिर्ता अफिसले नै नियम अनुसार हातले मिलाउँछ — यहाँ आफैं फिर्ता हुँदैन।\n• टिकट " . $pnr . " रद्द गर्ने अनुरोध पठाउन लेख्नुहोस्: cancel " . $pnr
                . "\n• कति फिर्ता हुन्छ हेर्न लेख्नुहोस्: cancel garda kati firta\n📞 अफिस (फोन / WhatsApp): " . $phone,
            "↩️ Paisa firta office le nai niyam anusar haat le milauchha — yaha afai firta hudaina.\n• Ticket " . $pnr . " cancel garne anurodh pathauna lekhnus: cancel " . $pnr
                . "\n• Kati firta hunchha herna lekhnus: cancel garda kati firta\n📞 Office (phone / WhatsApp): " . $phone,
            "↩️ रिफंड ऑफिस खुद नियम के अनुसार हाथ से करता है — यहाँ अपने आप वापस नहीं होता।\n• टिकट " . $pnr . " रद्द करने का अनुरोध भेजने के लिए लिखिए: cancel " . $pnr
                . "\n• कितना वापस मिलेगा देखने के लिए लिखिए: cancel policy\n📞 ऑफिस (फ़ोन / WhatsApp): " . $phone,
            "↩️ Refunds are settled by the office by hand, by the published rule — nothing is refunded here automatically.\n• To send a cancel request for ticket " . $pnr . ", write: cancel " . $pnr
                . "\n• To see what the rule gives, write: cancel policy\n📞 Office (call / WhatsApp): " . $phone));
    }

    /** Open an action on one ticket (ownership checked here). */
    private static function start(string $fromRaw, string $intl, string $action, string $pnr, ?array $who): array
    {
        $b = Database::fetch('SELECT * FROM bookings WHERE pnr = :p LIMIT 1', ['p' => $pnr]);
        if ($b === null || !self::mayEdit($b, $fromRaw, $who)) {
            return self::say('🔒 यो टिकट यो नम्बरबाट बदल्न मिल्दैन। बुकिङमा राखेको नम्बरबाट लेख्नुहोस्, वा कार्यालयमा फोन गर्नुहोस्: ' . Settings::officePhone());
        }
        if (!in_array((string) $b['status'], ['confirmed', 'completed', 'pending'], true)) {
            return self::say('यो टिकट ' . strtoupper((string) $b['status']) . ' छ — बदल्न मिल्दैन।');
        }
        switch ($action) {
            case 'menu':
                return self::say('टिकट ' . $pnr . ' — के मिलाउने?', self::menuUi($pnr));
            case 'more':
                return self::say('टिकट ' . $pnr . ' — अरू विकल्प:', ['kind' => 'buttons', 'buttons' => [
                    ['id' => 'shg:ed agent ' . $pnr, 'title' => '🧑‍💼 एजेन्ट · Agent'],
                    ['id' => 'shg:ed cancel ' . $pnr, 'title' => '❌ रद्द · Cancel'],
                ]]);
            case 'stop':
                self::clear($intl);
                return self::say('ठिक छ। · OK.');
            case 'name':
                $pax = Database::fetchAll('SELECT id, full_name FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => (int) $b['id']]);
                if ($pax === []) {
                    return self::say('यो टिकटमा यात्रुको नाम छैन — कार्यालयमा फोन गर्नुहोस्: ' . Settings::officePhone());
                }
                if (count($pax) === 1) {
                    self::save($intl, ['pnr' => $pnr, 'action' => 'name', 'step' => 'name_new', 'old' => (string) $pax[0]['full_name']]);
                    return self::say("✏️ अहिलेको नाम: " . $pax[0]['full_name'] . "\nसही पूरा नाम लेख्नुहोस्। · Type the correct full name.");
                }
                $lines = [];
                foreach (array_slice($pax, 0, 9) as $i => $p) {
                    $lines[] = ($i + 1) . '. ' . $p['full_name'];
                }
                self::save($intl, ['pnr' => $pnr, 'action' => 'name', 'step' => 'name_pick', 'pax' => array_column(array_slice($pax, 0, 9), 'full_name')]);
                return self::say("✏️ कुन यात्रुको नाम? नम्बर लेख्नुहोस्:\n" . implode("\n", $lines));
            case 'date':
                self::save($intl, ['pnr' => $pnr, 'action' => 'date', 'step' => 'date_new']);
                return self::say("📅 नयाँ मिति लेख्नुहोस् — जस्तै 15 Oct, 2026-10-15, bholi.\nType the new travel date.\n(सिट र भाडा प्रणालीले जाँच्छ — पक्का गर्नुअघि देखाउँछ)");
            case 'agent':
                self::save($intl, ['pnr' => $pnr, 'action' => 'agent', 'step' => 'otp']);
                return self::askOtp($intl, (int) $b['id'], "🧑‍💼 टिकट " . $pnr . " को लागि अर्को एजेन्ट खोजिन्छ।");
            case 'cancel':
                self::save($intl, ['pnr' => $pnr, 'action' => 'cancel', 'step' => 'otp']);
                return self::askOtp($intl, (int) $b['id'], "❌ टिकट " . $pnr . " रद्द गर्ने अनुरोध कार्यालयमा पठाइन्छ (कार्यालयले नियम अनुसार रकम फिर्ता मिलाउँछ)।");
        }
        return self::say('बुझिएन। · Not understood.', self::menuUi($pnr));
    }

    /** The next message of an open action. Null = not an answer to it (state kept). */
    private static function continueStep(string $fromRaw, string $intl, array $st, string $body, string $t, ?array $who): ?array
    {
        $pnr = (string) $st['pnr'];
        $b   = Database::fetch('SELECT * FROM bookings WHERE pnr = :p LIMIT 1', ['p' => $pnr]);
        if ($b === null) {
            self::clear($intl);
            return null;
        }
        $bid = (int) $b['id'];

        switch ((string) $st['step']) {
            case 'name_pick':
                $n = (int) self::digits($t);
                $pax = (array) ($st['pax'] ?? []);
                if (self::digits($t) === '' || mb_strlen($t) > 4) {
                    return null;    // not a pick: the bot answers, the change stays open
                }
                if ($n < 1 || $n > count($pax)) {
                    return self::say('१ देखि ' . count($pax) . ' सम्मको नम्बर लेख्नुहोस्। (रोक्न: stop)');
                }
                $st['old']  = (string) $pax[$n - 1];
                $st['step'] = 'name_new';
                self::save($intl, $st);
                return self::say("✏️ " . $st['old'] . " को सही पूरा नाम लेख्नुहोस्।");

            case 'name_new':
                $new = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
                // A name is letters only; "ok", "ho", a question or a sentence is the person talking about something else.
                if (preg_match('/^[\p{L}\p{M} .\'-]{2,80}$/u', $new) !== 1 || str_contains($new, '?')
                    || self::word(self::tokens($t), ['ok', 'okay', 'ho', 'hoina', 'yes', 'no', 'hi', 'hello', 'namaste', 'thanks', 'thank', 'dhanyabad', 'dhanyavad', 'ticket', 'seat', 'bus', 'kaha', 'kati', 'kab', 'kahile'])
                    || count(self::tokens($t)) > 5) {
                    return null;    // not a name: the bot answers, the change stays open
                }
                $st['new']  = $new;
                $st['step'] = 'otp';
                self::save($intl, $st);
                return self::askOtp($intl, $bid, "✏️ " . $st['old'] . " → " . $new);

            case 'date_new':
                // fx1: a PNR sent while the date question waits is a lookup, not a date ("date not understood" before).
                // Only a PNR with NO date beside it: "SHG-… ko miti badalnu cha, 30 Nov ma jane" and "SHG-… 30 Nov"
                // carry the new date and are taken at once, as since 31 Oct (review fix).
                if (self::changeWordsOn() && self::pnrIn($body) !== ''
                    && self::parseDate(trim((string) preg_replace('/SHG-?\s?\d{4}-?\s?\d{3,6}/i', ' ', $body))) === '') {
                    return null;
                }
                $date = self::parseDate($body);
                if ($date === '') {
                    if (preg_match('/\d|bholi|parsi|bhol|kal|tomorrow|भोलि|पर्सि|कल/iu', $body) !== 1) {
                        return null;    // not a date at all: the bot answers, the change stays open
                    }
                    return self::say('मिति बुझिएन — जस्तै 15 Oct वा 2026-10-15 लेख्नुहोस्। (रोक्न: stop)');
                }
                // fx1: request mode — no seat is looked for and nothing is quoted; the office decides when it acts
                if (strtolower(trim(Settings::getString('wa_date_change_mode', 'self'))) === 'request') {
                    $st['new']  = $date;
                    $st['old']  = self::travelDate($bid);
                    $st['step'] = 'otp';
                    self::save($intl, $st);
                    return self::askOtp($intl, $bid, "📅 मिति परिवर्तनको अनुरोध · Date-change request\n"
                        . formatDate($st['old'], 'D, j M Y') . ' → ' . formatDate($date, 'D, j M Y') . "\n"
                        . "अनुरोध कार्यालयमा जान्छ — कार्यालयले सिट हेरेर पक्का गर्छ। · The request goes to the office, which confirms it.");
                }
                $turn = (int) (microtime(true) * 10) % 1000000000;
                $ctx  = self::ctx($fromRaw, $who, $turn, $body);
                $q    = AiTools::run('quote_ticket_fix', ['pnr' => $pnr, 'new_date' => $date, 'reason' => 'Passenger asked on WhatsApp'], $ctx);
                if (empty($q['ok'])) {
                    self::clear($intl);
                    self::audit($b, 'travel_date', self::travelDate($bid), $date, $fromRaw, false, 'refused', (string) ($q['say'] ?? ''));
                    return self::say('❌ ' . (string) ($q['say'] ?? 'यो मितिमा मिलेन।') . "\nकार्यालय: " . Settings::officePhone());
                }
                $st['new']  = $date;
                $st['old']  = self::travelDate($bid);
                $st['turn'] = $turn;
                $st['step'] = 'otp';
                self::save($intl, $st);
                $d = (array) ($q['data'] ?? []);
                $seats = implode(', ', array_map(static fn($x): string => is_scalar($x) ? (string) $x : '', (array) ($d['seats'] ?? [])));
                // round 2 (passenger sim: the preview said "Seat: L36", the ticket prints E1): the berths as printed
                try {
                    require_once INCLUDE_PATH . '/seats.php';
                    $lay = Database::fetch("SELECT b.booking_mode, l.schedule_id FROM bookings b LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
                                             WHERE b.id = :b LIMIT 1", ['b' => $bid]);
                    if ($seats !== '' && $lay !== null && (int) ($lay['schedule_id'] ?? 0) > 0) {
                        $shown = Seats::displayLabels(array_map('strval', (array) ($d['seats'] ?? [])), Seats::coachForSchedule((int) $lay['schedule_id']),
                            (string) ($lay['booking_mode'] ?? 'sharing'));
                        $seats = $shown !== '' ? $shown : $seats;
                    }
                } catch (Throwable $e) {
                    // the stored ids, as before
                }
                $pickup = trim((string) preg_replace('/\s*\[[-\d.,\s]+\]\s*$/', '', (string) ($d['pickup'] ?? '')));
                return self::askOtp($intl, $bid, "📅 मिति बदल्ने · New date\n"
                    . formatDate($st['old'], 'D, j M Y') . ' → ' . formatDate((string) ($d['date'] ?? $date), 'D, j M Y') . "\n"
                    . ($seats !== '' ? 'सिट / Seat: ' . $seats . "\n" : '')
                    . ($pickup !== '' ? 'चढ्ने / Boarding: ' . $pickup . "\n" : '')
                    . (isset($d['total']) ? 'भाडा / Fare: ' . inr((float) $d['total']) . ' (उही · unchanged)' : ''));

            case 'confirm':
                if (in_array($t, ['ho', 'हो', 'yes', 'haan', 'han', 'ok', 'confirm', 'thik cha', 'हुन्छ', 'huncha'], true)) {
                    self::clear($intl);
                    return self::commit($fromRaw, $intl, $st, $b, $who, false);
                }
                if (in_array($t, ['hoina', 'होइन', 'no', 'nahi', 'नहीं'], true)) {
                    self::clear($intl);
                    return self::say('ठिक छ, केही परिवर्तन गरिएन। · OK, nothing was changed.');
                }
                return null;

            case 'otp':
                $code = self::digits($t);
                if (strlen($code) !== 6) {
                    if (in_array($t, ['code', 'kod', 'resend', 'feri', 'pheri', 'again'], true)) {
                        return self::askOtp($intl, $bid, 'नयाँ कोड पठाइयो।');
                    }
                    return null;    // not the code: let the bot answer, keep the edit open for 15 min
                }
                $v = self::verifyOtp($intl, $code);
                if ($v !== 'ok') {
                    if ($v === 'locked') {
                        self::clear($intl);
                        return self::say('🔒 धेरै गलत कोड — परिवर्तन रोकियो। फेरि सुरु गर्न टिकटको बटन थिच्नुहोस्।');
                    }
                    return self::say($v === 'expired' ? '⌛ कोडको समय सकियो — "code" लेख्नुहोस्, नयाँ पठाइन्छ।' : '❌ कोड मिलेन — फेरि लेख्नुहोस्।');
                }
                self::clear($intl);
                return self::commit($fromRaw, $intl, $st, $b, $who, true);
        }
        self::clear($intl);
        return null;
    }

    /** Do it (the code was right, or codes are switched off). */
    private static function commit(string $fromRaw, string $intl, array $st, array $b, ?array $who, bool $otp): array
    {
        $pnr = (string) $b['pnr'];
        $bid = (int) $b['id'];
        switch ((string) $st['action']) {
            case 'name':
                $r = AiTools::run('rename_passenger', ['pnr' => $pnr, 'new_name' => (string) $st['new'], 'old_name' => (string) ($st['old'] ?? '')],
                    self::ctx($fromRaw, $who, 0, 'ho'));
                self::audit($b, 'passenger_name', (string) ($st['old'] ?? ''), (string) $st['new'], $fromRaw, $otp, !empty($r['ok']) ? 'done' : 'refused', (string) ($r['say'] ?? ''));
                return !empty($r['ok'])
                    ? ['text' => "✅ नाम सच्याइयो: " . $st['new'] . "\nनयाँ टिकट यहीँ छ। · Name corrected — new ticket attached.", 'media' => $r['media'] ?? null, 'ui' => null]
                    : self::say('❌ ' . (string) ($r['say'] ?? 'मिलेन।') . "\nकार्यालय: " . Settings::officePhone());

            case 'date':
                /* fx1 (26 Nov 2026): wa_date_change_mode = 'request' — the verified date change is filed in the
                   office queue (admin/ticket-requests.php, the same row the ticket page writes) and NOTHING is
                   rebooked here; the office confirms it. 'self' (the default) keeps the rebook below, unchanged. */
                if (strtolower(trim(Settings::getString('wa_date_change_mode', 'self'))) === 'request') {
                    return self::dateRequest($fromRaw, $st, $b, $otp);
                }
                $r = AiTools::run('fix_ticket', ['pnr' => $pnr, 'new_date' => (string) $st['new'], 'confirm' => true],
                    self::ctx($fromRaw, $who, (int) ($st['turn'] ?? 0) + 1, 'ho'));
                self::audit($b, 'travel_date', (string) ($st['old'] ?? ''), (string) $st['new'], $fromRaw, $otp, !empty($r['ok']) ? 'done' : 'refused', (string) ($r['say'] ?? ''));
                return !empty($r['ok'])
                    ? ['text' => "✅ मिति बदलियो · Date changed: " . formatDate((string) $st['new'], 'D, j M Y') . "\nनयाँ टिकट यहीँ छ। · Your new ticket is attached.", 'media' => $r['media'] ?? null, 'ui' => null]
                    : self::say('❌ ' . (string) ($r['say'] ?? 'मिलेन।') . "\nकार्यालय: " . Settings::officePhone());

            case 'agent':
                $prev = WaAgentRoute::acceptedAgent($bid);
                $out  = WaAgentRoute::changeAgent($bid, 'whatsapp:' . $intl);
                self::audit($b, 'agent', $prev !== null ? $prev['name'] : '', $out, $fromRaw, $otp, $out === 'offered' ? 'done' : 'requested', 'route outcome: ' . $out);
                return self::say($out === 'offered'
                    ? "✅ अर्को एजेन्टलाई सोधियो — उहाँले छिट्टै सम्पर्क गर्नुहुन्छ।\nAnother agent has been asked."
                    : "📝 अहिले अर्को एजेन्ट उपलब्ध छैन — कार्यालयलाई खबर गरियो।\nकार्यालय: " . Settings::officePhone());

            case 'cancel':
                self::audit($b, 'cancel_request', (string) $b['status'], 'cancel requested', $fromRaw, $otp, 'requested', 'office settles cancel + refund by hand');
                self::tellOffice($b, $intl);
                return self::say("📝 रद्द गर्ने अनुरोध कार्यालयमा पुग्यो। कार्यालयले नियम अनुसार रकम फिर्ता मिलाएर सम्पर्क गर्नेछ।\nYour cancel request reached the office — they will call you.\nकार्यालय: " . Settings::officePhone());
        }
        return self::say('बुझिएन। · Not understood.');
    }

    /** fx1: the date change as a REQUEST for the office (wa_date_change_mode = request). The booking is not touched. */
    private static function dateRequest(string $fromRaw, array $st, array $b, bool $otp): array
    {
        $new = (string) ($st['new'] ?? '');
        $old = (string) ($st['old'] ?? '');
        try {
            require_once INCLUDE_PATH . '/ticketrequests.php';
            TicketRequests::create($b, ['kind' => 'change_date', 'wanted_date' => $new,
                'note' => 'WhatsApp' . ($otp ? ', code verified' : ', confirmed with ho')], '');
        } catch (TicketRequestRefused $e) {
            self::audit($b, 'travel_date', $old, $new, $fromRaw, $otp, 'refused', $e->getMessage());
            return self::say('❌ ' . $e->getMessage() . "\nकार्यालय: " . Settings::officePhone());
        } catch (Throwable $e) {
            Logger::warning('date change request failed: ' . $e->getMessage(), ['pnr' => (string) $b['pnr']], 'whatsapp');
            self::audit($b, 'travel_date', $old, $new, $fromRaw, $otp, 'refused', 'request could not be saved');
            return self::say("अहिले अनुरोध दर्ता हुन सकेन — कार्यालयमा फोन गर्नुहोस्: " . Settings::officePhone() . "\nThe request could not be saved — please call the office.");
        }
        self::audit($b, 'travel_date', $old, $new, $fromRaw, $otp, 'requested', 'date change request; the office confirms it');
        return self::say("📝 मिति परिवर्तनको अनुरोध कार्यालयमा पुग्यो: " . formatDate($new, 'D, j M Y') . "\nटिकट अहिले बदलिएको छैन — कार्यालयले सिट हेरेर पक्का गर्छ र खबर गर्छ।\n"
            . "Your date-change request reached the office. The ticket is NOT changed yet — the office confirms it.\nकार्यालय: " . Settings::officePhone());
    }

    /**
     * A cancel request: free WhatsApp to the admin in its window, the panel bell always.
     * P6-2d (16 Nov 2026): the assistant's request also carries the passenger's reason and
     * the refund the published slabs quoted ($otp false = proven by the booking's own
     * number + the quote-then-yes, not by a code). The code path's note is unchanged.
     */
    private static function tellOffice(array $b, string $intl, string $reason = '', ?float $refund = null, bool $otp = true): void
    {
        $extra = ($reason !== '' ? "\nकारण · Reason: " . $reason : '')
            . ($refund !== null ? "\nनियम अनुसार फिर्ता · Refund by the slabs: " . inr($refund) . ' (अनुमान मात्र · quoted only, nothing paid)' : '');
        $how = $otp ? '(कोडले पुष्टि गरिएको · code verified)'
                    : "(WhatsApp AI — बुकिङकै नम्बरबाट, रकम सुनेर 'हो' भनेको · from the booking's own number, after hearing the figure)\nकेही रद्द वा फिर्ता भएको छैन · Nothing was cancelled or refunded.";
        $text = "📝 रद्द अनुरोध · Cancel request\nPNR: " . (string) $b['pnr'] . "\nयात्रु नम्बर: +" . $intl . $extra . "\n" . $how . "\nAdmin → Bookings मा हेर्नुहोस्।";
        try {
            $admin = WaAgentRoute::adminNumber();
            if ($admin !== '') {
                WaAgentRoute::send($admin, $text, null, null, 'cancel_request', (int) $b['id']);
            }
        } catch (Throwable $ignored) {
        }
        try {
            require_once INCLUDE_PATH . '/adminalerts.php';
            AdminAlerts::push('wa_cancel_request', 'Cancel request · ' . (string) $b['pnr'],
                'Passenger +' . $intl . ' asked to cancel on WhatsApp (' . ($otp ? 'code verified' : 'via the assistant') . ').'
                . ($reason !== '' ? ' Reason: ' . $reason . '.' : '')
                . ($refund !== null ? ' Quoted refund: ' . inr($refund) . '.' : '') . ' Settle by hand.',
                '/admin/booking-view.php?pnr=' . rawurlencode((string) $b['pnr']), '📝', 'wa_cancel:' . (int) $b['id']);
        } catch (Throwable $e) {
            Logger::warning('cancel request alert failed: ' . $e->getMessage(), [], 'whatsapp');
        }
    }

    /**
     * P6-2d (16 Nov 2026) — the WhatsApp assistant's cancel_ticket for a PASSENGER while
     * wa_ai_cancel_mode is 'request' (the default): the same REQUEST the ❌ button files —
     * a ticket_edit_audit row (+ audit_logs), the office WhatsApp in its window and the
     * panel bell — with the passenger's reason and the quoted refund. Nothing is cancelled
     * and no money moves: the owner settles every cancel and refund by hand. The caller
     * (AiTools::cancelTicket) has already checked the number owns the booking.
     * A second request for the same booking within 6 h is noted, not re-sent.
     *
     * @return array{ok: bool, repeat: bool, why: string}
     */
    public static function requestCancel(string $pnr, string $fromRaw, string $countryHint = '', string $reason = '', ?float $refund = null): array
    {
        $b = Database::fetch('SELECT * FROM bookings WHERE pnr = :p LIMIT 1', ['p' => strtoupper(trim($pnr))]);
        if ($b === null) {
            return ['ok' => false, 'repeat' => false, 'why' => 'No booking with that PNR.'];
        }
        if (!in_array((string) $b['status'], ['confirmed', 'completed', 'pending'], true)) {
            return ['ok' => false, 'repeat' => false, 'why' => 'Booking ' . (string) $b['pnr'] . ' is ' . strtoupper((string) $b['status']) . ' — there is nothing to cancel.'];
        }
        require_once INCLUDE_PATH . '/waagentroute.php';
        // A bare 10-digit number (the members' desk context) takes the booking's own country code.
        $hint = $countryHint !== '' ? $countryHint : (preg_replace('/\D+/', '', (string) ($b['contact_country_code'] ?? '')) ?? '');
        $intl = WaAgentRoute::intl($fromRaw, $hint);
        if ($intl === '') {
            $intl = preg_replace('/\D+/', '', $fromRaw) ?? '';
        }
        $reason = trim(mb_substr((string) preg_replace('/\s+/u', ' ', $reason), 0, 200));
        $fresh = true;
        try {
            $fresh = Security::rateLimit('wa_ai_cancel_req', (string) (int) $b['id'], 1, 21600);
        } catch (Throwable $ignored) {
        }
        if (!$fresh) {
            return ['ok' => true, 'repeat' => true, 'why' => ''];
        }
        self::audit($b, 'cancel_request', (string) $b['status'], 'cancel requested', $fromRaw, false, 'requested',
            'WhatsApp AI request; office settles cancel + refund by hand'
            . ($refund !== null ? '; quoted ' . inr($refund) : '') . ($reason !== '' ? '; reason: ' . $reason : ''));
        self::tellOffice($b, $intl, $reason, $refund, false);
        return ['ok' => true, 'repeat' => false, 'why' => ''];
    }

    /** "Ticket confirm bhayo?" — status + the ticket picture + the change buttons. */
    private static function showTicket(string $pnr, string $fromRaw, ?array $who): ?array
    {
        $b = Database::fetch('SELECT * FROM bookings WHERE pnr = :p LIMIT 1', ['p' => $pnr]);
        if ($b === null || !self::mayEdit($b, $fromRaw, $who)) {
            return null;
        }
        $ok = in_array((string) $b['status'], ['confirmed', 'completed'], true);
        $media = null;
        if ($ok) {
            if (!class_exists('Ticket')) {
                foreach (['qr', 'pdf', 'ticket'] as $f) {
                    require_once INCLUDE_PATH . '/' . $f . '.php';
                }
            }
            $media = Ticket::imageUrl($pnr);
        }
        return ['text' => ($ok ? '✅ टिकट ' . $pnr . ' पक्का छ। · Your ticket is CONFIRMED.' : '⏳ टिकट ' . $pnr . ': ' . strtoupper((string) $b['status'])),
            'media' => $media, 'ui' => $ok ? self::menuUi($pnr) : null];
    }

    /* -----------------------------------------------------------------
     *  The 6-digit code
     * ----------------------------------------------------------------- */

    private static function askOtp(string $intl, int $bid, string $what): array
    {
        if (!Settings::getBool('wa_edit_otp_on', true)) {
            // Codes switched off (wa_edit_otp_on = 0): one explicit "ho" is the consent instead.
            $st = self::state($intl);
            if ($st !== null) {
                $st['step'] = 'confirm';
                self::save($intl, $st);
            }
            return self::say($what . "

पक्का गर्न \"ho\" लेख्नुहोस्। · Reply \"ho\" to confirm. (रोक्न: stop)");
        }
        if (!Security::rateLimit('wa_edit_otp', $intl, 6, 3600)) {
            self::clear($intl);
            return self::say('⏳ धेरै पटक कोड माग्नुभयो — एक घण्टापछि फेरि प्रयास गर्नुहोस्।');
        }
        $code = self::$codeGen !== null ? (string) (self::$codeGen)() : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        try {
            Database::run("UPDATE otp_codes SET is_used = 1 WHERE identifier = :i AND purpose = 'verify' AND is_used = 0", ['i' => 'wa:' . $intl]);
            Database::insert('otp_codes', [
                'identifier'   => 'wa:' . $intl,
                'channel'      => 'whatsapp',
                'purpose'      => 'verify',
                'code_hash'    => password_hash($code, PASSWORD_DEFAULT),
                'max_attempts' => 5,
                'expires_at'   => date('Y-m-d H:i:s', time() + self::OTP_MIN * 60),
                'ip_address'   => null,
            ]);
        } catch (Throwable $e) {
            self::clear($intl);
            Logger::warning('edit otp store failed: ' . $e->getMessage(), [], 'whatsapp');
            return self::say('अहिले कोड बनाउन सकिएन — कार्यालय: ' . Settings::officePhone());
        }
        $r = WaAgentRoute::send($intl, '🔐 ' . $code . " — तपाईंको पुष्टि कोड · your code (" . self::OTP_MIN . " min). कसैलाई नदिनुहोस्।", null, null, 'otp', $bid);
        if (!$r['success']) {
            self::clear($intl);
            return self::say('कोड पठाउन सकिएन — केही बेरपछि फेरि प्रयास गर्नुहोस् वा कार्यालय: ' . Settings::officePhone());
        }
        return self::say($what . "\n\n🔐 पुष्टि गर्न अघिल्लो सन्देशको ६ अंकको कोड यहाँ लेख्नुहोस्। · Type the 6-digit code we just sent.\n(रोक्न: stop)");
    }

    /** ok | wrong | expired | locked */
    private static function verifyOtp(string $intl, string $code): string
    {
        try {
            $row = Database::fetch(
                "SELECT * FROM otp_codes WHERE identifier = :i AND purpose = 'verify' AND is_used = 0 ORDER BY id DESC LIMIT 1",
                ['i' => 'wa:' . $intl]
            );
        } catch (Throwable $e) {
            return 'wrong';
        }
        if ($row === null) {
            return 'expired';
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            Database::run('UPDATE otp_codes SET is_used = 1 WHERE id = :i', ['i' => (int) $row['id']]);
            return 'expired';
        }
        $attempts = (int) $row['attempts'] + 1;
        Database::run('UPDATE otp_codes SET attempts = :a WHERE id = :i', ['a' => $attempts, 'i' => (int) $row['id']]);
        if (password_verify($code, (string) $row['code_hash'])) {
            Database::run('UPDATE otp_codes SET is_used = 1 WHERE id = :i', ['i' => (int) $row['id']]);
            return 'ok';
        }
        if ($attempts >= (int) $row['max_attempts']) {
            Database::run('UPDATE otp_codes SET is_used = 1 WHERE id = :i', ['i' => (int) $row['id']]);
            return 'locked';
        }
        return 'wrong';
    }

    /* -----------------------------------------------------------------
     *  Who may change it
     * ----------------------------------------------------------------- */

    private static function mayEdit(array $b, string $fromRaw, ?array $who): bool
    {
        $me = normalisePhone($fromRaw);
        $contact = (string) ($b['contact_phone'] ?? '');
        // The same rule as AiTools: the contact number only, and never a walk-in placeholder (9999999999 …).
        if ($me !== '' && !isPlaceholderPhone($contact) && $me === normalisePhone($contact)) {
            return true;
        }
        if (is_array($who)) {
            $role = (string) ($who['role'] ?? '');
            if ($role === 'admin') {
                return true;
            }
            if ($role === 'staff' && (int) ($who['scopeAdminId'] ?? 0) > 0 && (int) ($who['scopeAdminId'] ?? 0) === (int) ($b['sold_by_admin_id'] ?? 0)) {
                return true;
            }
        }
        return false;
    }

    /** The assistant-tool context for this sender. */
    private static function ctx(string $fromRaw, ?array $who, int $turn, string $text): array
    {
        require_once INCLUDE_PATH . '/aitools.php';
        $ctx = is_array($who) ? $who : AiTools::whoIs($fromRaw);
        $ctx['turn']        = $turn;
        $ctx['messageText'] = $text;
        $ctx['channel']     = 'whatsapp';
        return $ctx;
    }

    /* -----------------------------------------------------------------
     *  Small helpers
     * ----------------------------------------------------------------- */

    private static function audit(array $b, string $field, string $old, string $new, string $fromRaw, bool $otp, string $outcome, string $detail): void
    {
        try {
            Database::insert('ticket_edit_audit', [
                'booking_id'    => (int) $b['id'],
                'pnr'           => (string) $b['pnr'],
                'field_changed' => $field,
                'old_value'     => mb_substr($old, 0, 255),
                'new_value'     => mb_substr($new, 0, 255),
                'changed_by'    => mb_substr('whatsapp:' . (preg_replace('/\D+/', '', $fromRaw) ?? ''), 0, 80),
                'otp_verified'  => $otp ? 1 : 0,
                'outcome'       => $outcome,
                'detail'        => mb_substr($detail, 0, 255),
            ]);
        } catch (Throwable $e) {
            Logger::warning('ticket edit audit failed: ' . $e->getMessage(), ['pnr' => (string) $b['pnr']], 'whatsapp');
        }
        if ($field !== 'passenger_name') {   // rename_passenger writes its own audit_logs row
            try {
                Logger::audit('booking.whatsapp_' . $field, 'booking', (string) $b['pnr'], [$field => $old], [$field => $new],
                    'WhatsApp ' . $field . ' (' . $outcome . ($otp ? ', code verified' : '') . ')');
            } catch (Throwable $ignored) {
            }
        }
    }

    private static function travelDate(int $bid): string
    {
        return (string) Database::scalar("SELECT travel_date FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound' ORDER BY id ASC LIMIT 1", ['b' => $bid], '');
    }

    /** The sender's next live ticket (travel today or later), else ''. */
    private static function latestPnr(string $fromRaw): string
    {
        $me = normalisePhone($fromRaw);
        if ($me === '' || isPlaceholderPhone($me)) {
            return '';
        }
        try {
            return (string) Database::scalar(
                "SELECT b.pnr FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                  WHERE b.contact_phone = :p AND b.status IN ('confirmed','pending')
                    AND bl.travel_date >= CURDATE()
                  ORDER BY bl.travel_date ASC, b.id DESC LIMIT 1",
                ['p' => $me], ''
            );
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function pnrIn(string $text): string
    {
        if (preg_match('/SHG-?\s?(\d{4})-?\s?(\d{3,6})/i', $text, $m) === 1) {
            return 'SHG-' . $m[1] . '-' . str_pad($m[2], 5, '0', STR_PAD_LEFT);
        }
        return '';
    }

    /** Y-m-d from "2026-10-15", "15/10", "15 Oct", "bholi" … ('' when unsure or past). */
    public static function parseDate(string $text): string
    {
        $t = trim($text);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $d = $m[0];
        } else {
            $d = '';
            try {
                require_once INCLUDE_PATH . '/ticketbot.php';
                $d = (string) (TicketBot::parse($t)['date'] ?? '');
            } catch (Throwable $ignored) {
            }
            if ($d === '' && preg_match('/^(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{2,4}))?$/', $t, $m) === 1) {
                $y = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) date('Y');
                $y = $y < 100 ? 2000 + $y : $y;
                if (checkdate((int) $m[2], (int) $m[1], $y)) {
                    $d = sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[1]);
                    if ($d < date('Y-m-d') && !isset($m[3])) {
                        $d = sprintf('%04d-%02d-%02d', $y + 1, (int) $m[2], (int) $m[1]);
                    }
                }
            }
        }
        return ($d !== '' && $d >= date('Y-m-d')) ? $d : '';
    }

    /**
     * 25 Nov 2026 (mp3-bot, P7: an agent's "✏️ नाम · Name" tap was read by the desk's PNR command and answered
     * "नयाँ मान बुझिएन"): is this line a ticket-menu button, or is an edit conversation open on this number? Then the
     * desk (ApWa) steps aside and this class answers.
     */
    public static function owns(string $fromRaw, string $body): bool
    {
        if (preg_match('/^\s*(?:shg:)?ed\s+(?:name|date|more|agent|cancel|menu|stop)\s+shg-[a-z0-9-]+\s*$/i', $body) === 1) {
            return true;
        }
        try {
            require_once INCLUDE_PATH . '/waagentroute.php';
            $intl = WaAgentRoute::intl($fromRaw);
        } catch (Throwable $e) {
            return false;
        }
        return $intl !== '' && self::ready() && self::state($intl) !== null;
    }

    /** @return array<string,mixed>|null */
    private static function state(string $intl): ?array
    {
        try {
            $raw = (string) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wa_edit' AND kkey = :k", ['k' => $intl], '');
        } catch (Throwable $e) {
            return null;
        }
        $st = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($st) || (int) ($st['at'] ?? 0) < time() - self::TTL) {
            return null;
        }
        return $st;
    }

    private static function save(string $intl, array $st): void
    {
        $st['at'] = time();
        $v = json_encode($st, JSON_UNESCAPED_UNICODE);
        try {
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_edit', :k, :v, 'waedit')
                 ON DUPLICATE KEY UPDATE kvalue = :v2, updated_by = 'waedit'",
                ['k' => $intl, 'v' => $v, 'v2' => $v]
            );
        } catch (Throwable $e) {
            Logger::warning('wa edit state save failed: ' . $e->getMessage(), [], 'whatsapp');
        }
    }

    private static function clear(string $intl): void
    {
        try {
            Database::run("DELETE FROM kv_store WHERE kscope = 'wa_edit' AND kkey = :k", ['k' => $intl]);
        } catch (Throwable $ignored) {
        }
    }

    private static function say(string $text, ?array $ui = null): array
    {
        return ['text' => $text, 'media' => null, 'ui' => $ui];
    }

    private static function digits(string $t): string
    {
        $t = strtr($t, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
        return preg_replace('/\D+/', '', $t) ?? '';
    }

    private static function norm(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[!.,।?]+$/u', '', $t) ?? $t;
        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /** @return list<string> the words of a normalised message */
    private static function tokens(string $t): array
    {
        return preg_split('/[^\p{L}\p{M}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** One of these is a whole word of the message. */
    private static function word(array $tokens, array $words): bool
    {
        return array_intersect($tokens, $words) !== [];
    }

    /** A word of the message STARTS with one of these stems. */
    private static function stem(array $tokens, array $stems): bool
    {
        foreach ($tokens as $tok) {
            foreach ($stems as $st) {
                if ($st !== '' && str_starts_with($tok, $st)) {
                    return true;
                }
            }
        }
        return false;
    }
}
