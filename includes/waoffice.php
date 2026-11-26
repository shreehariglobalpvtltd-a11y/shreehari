<?php
/**
 * =====================================================================
 *  includes/waoffice.php — WaOffice: the owner's questions on WhatsApp,
 *  answered before the staff desk (26 Nov 2026, fx1).
 *
 *  Live gap: the staff desk (ApWa) answers EVERY message from a number
 *  that is a staff record, so the office words WaBot and WaLocal already
 *  have ("agents", "revenue", the agent board, pending payments, where the
 *  bus is) never ran for the owner. "kun agent le kati kate?", "pending
 *  payment?", "sanjh ko report" got "no sure answer"; "agents" was read as
 *  a directory search ("contacts (0)"); "bus 3 kaha cha?" got the bus list
 *  with seats sold, not where the bus is.
 *
 *  This class is a small READ-ONLY router in front of the desk:
 *    seller board    "kun agent le kati kate?", "agents", "agent report mahina"
 *    pending money   "pending payment?", "payment baki kati cha", "verify baki"
 *    where the bus   "bus 3 kaha cha?", "bus kaha pugyo", "gadi kaha cha"
 *    revenue         "revenue", "kamai", "aaja ko kamai kati"
 *    day report      "sanjh ko report", "evening report", "din ko report"
 *  Every answer is built by the code that already answers it elsewhere
 *  (WaBot::topAgentsText / revenueText, OfficeReport::build, WaLocal's
 *  staff_board / pending / bus_live). Nothing is changed, sold or paid.
 *
 *  It never takes a line that is the desk's:
 *    - anything waiting for a "ho" on this number (a sale quote, a money
 *      preview, a link code) — the desk keeps its yes / no (owner rules R1-R3);
 *    - a PNR or an agent code, a mobile number, a line with a seat count,
 *      a hold / no line, an open ticket edit or guided order;
 *    - anything it does not recognise for sure (null — the desk answers).
 *
 *  Who: the office only — a manager / super-admin number (AiTools::whoIs
 *  role admin, the same trust the desk's own company report has), or a
 *  number on the office-report list (WaBot::isOfficeNumber, the existing
 *  "report / agents / revenue" gate; those get today's figures and the bus,
 *  not the name lists). An agent / counter gets two hints only ("seat
 *  badalnu cha", "day report"). A passenger never reaches it.
 *
 *  Step-up: with wa_ops_stepup_on a staff / office ACCOUNT gets the money
 *  answers (boards, pending, revenue, day report) only while its panel
 *  verification is fresh — the gate the desk's company report (office_day)
 *  has. Unverified = the one-time link, never a figure. The bus is not gated.
 *
 *  Switch: wa_office_ask_on (default 1) — off = every line goes to the desk
 *  exactly as before. The words that already had wa_office_words_on keep it.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

final class WaOffice
{
    /** The desk forgets a waiting "ho" after this long (ApWa::PENDING_TTL). */
    private const DESK_PENDING_TTL = 900;

    /** Which intent answered the last handle() ('' = none) — for tests and the ai_turns row. */
    public static string $lastIntent = '';

    public static function on(): bool
    {
        return Settings::getBool('wa_office_ask_on', true);
    }

    /**
     * @return array{text: string, media: ?string, role: string}|null null = not mine, the desk answers
     */
    public static function handle(string $from, string $digits, string $body, string $kind = 'text'): ?array
    {
        self::$lastIntent = '';
        $body = trim($body);
        if (!self::on() || $kind !== 'text' || $digits === '' || mb_strlen($body) < 3 || mb_strlen($body) > 80) {
            return null;
        }
        $t = self::norm($body);
        // a PNR / an agent code / a mobile number / a count of seats: the desk's (a lookup, a sale)
        if (preg_match('/shg[\s-]*[a-z]?[\s-]*\d/u', $t) === 1 || preg_match('/\d{5,}/', $t) === 1
            || preg_match('/\d+\s*(?:seat|seats|sit|ticket|tickets|tikat|jana|jan|log|people|berth|सिट|सीट|टिकट|जना)(?![\p{L}])/u', $t) === 1) {
            return null;
        }
        $intent = self::intentOf($t);
        if ($intent === '') {
            return null;
        }
        try {
            require_once INCLUDE_PATH . '/aitools.php';
            $who = AiTools::whoIs($from);
        } catch (Throwable $e) {
            return null;
        }
        $role = (string) ($who['role'] ?? 'customer');
        $listed = false;
        if ($role !== 'admin' && $role !== 'staff') {
            try {
                $listed = Settings::getBool('wa_office_words_on', true) && WaBot::isOfficeNumber($from !== '' ? $from : $digits);
            } catch (Throwable $e) {
                $listed = false;
            }
            if (!$listed) {
                return null;                               // a passenger: never an office answer
            }
        }
        // a line that says no / not now / wait never starts anything here either (owner rule R1)
        try {
            require_once INCLUDE_PATH . '/ticketbot.php';
            if (TicketBot::cmdHoldOrNo($body)) {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }
        if (self::deskBusy($from, $digits)) {
            return null;                                   // the desk is waiting for its own answer
        }

        if ($role === 'staff') {
            // "aaja din ko report", "report daily", "today day report": the desk already answers these with the
            // agent's own day (ApWa's today / report words) — the hint is only for a line the desk does not read
            if ($intent === 'day_report' && self::deskDayWords($t)) {
                return null;
            }
            return self::staffHint($intent);               // an agent / counter: two hints, never company figures
        }
        if ($intent === 'seat_hint') {
            return $role === 'admin' ? self::staffHint($intent, 'admin') : null;
        }
        /* The step-up gate (review fix, 26 Nov 2026): with wa_ops_stepup_on the desk's own company report
           (AiTools office_day) waits for a fresh panel verification of a staff number. The same class of figures
           here — revenue, the office report, the seller boards, payments waiting — waits for it too, so rewording
           the question never passes the gate. Where the bus is carries no money and is not gated (as on the desk).
           A number that is only on the office-report list has no account to verify: unchanged (the existing gate). */
        if ($intent !== 'bus' && (int) ($who['adminId'] ?? 0) > 0) {
            $locked = self::stepUp($who);
            if ($locked !== null) {
                self::$lastIntent = 'stepup';
                return $locked;
            }
        }

        $office = $who;
        $office['role'] = 'admin';                         // WaLocal's office answers read the role
        $office['scopeAdminId'] = null;
        try {
            $r = match ($intent) {
                'board_today'  => self::wordsOn() ? ['text' => WaBot::topAgentsText(todayISO()), 'media' => null] : null,
                'board_month'  => $role === 'admin' ? self::local($from, 'agent report mahina', $office, 'staff_board') : null,
                'pending'      => $role === 'admin' ? self::local($from, 'pending', $office, 'pending') : null,
                'bus'          => self::busAnswer($from, $t, $office),
                'revenue'      => self::wordsOn() ? ['text' => WaBot::revenueText(todayISO()), 'media' => null] : null,
                'day_report'   => self::wordsOn() ? self::dayReport() : null,
                default        => null,
            };
        } catch (Throwable $e) {
            Logger::warning('WaOffice ' . $intent . ' failed: ' . $e->getMessage(), [], 'whatsapp');
            return null;                                   // the desk still answers
        }
        if ($r === null || trim((string) ($r['text'] ?? '')) === '') {
            return null;
        }
        self::$lastIntent = $intent;
        return ['text' => (string) $r['text'], 'media' => $r['media'] ?? null, 'role' => 'admin'];
    }

    /* =================================================================
     *  Reading the line (strict: when unsure, '' — the desk answers)
     * ================================================================= */

    /** board_today | board_month | pending | bus | revenue | day_report | seat_hint | '' */
    public static function intentOf(string $t): string
    {
        $a = '(?<![\p{L}\p{M}])';
        $z = '(?![\p{L}\p{M}])';
        $hasDigit = preg_match('/\d/', $t) === 1;
        // Roman words stand alone ("agent le"); Devanagari carries its postposition ("एजेन्टले", "एजेन्टहरूले")
        $agentWord = '(?:' . $a . '(?:agents?|agentle|agentne|ejents?|agent\s*haru|counters?|kaunter|sellers?)' . $z . '|(?:एजेन्ट|एजेंट|काउन्टर|काउंटर)\S*)';
        $month = preg_match('/' . $a . '(?:month|monthly|mahina|mahine|mahinako|महिना|महीना|महीने|मास)' . $z . '/u', $t) === 1;

        // where is the bus — the only question that may carry a number ("bus 3", "3 number bus")
        if (preg_match('/' . $a . '(?:bus|gadi|gaadi|gari|coach|बस|गाडी|गाड़ी)' . $z . '/u', $t) === 1
            && preg_match('/' . $a . '(?:kaha|kahaa|kata|kahan|kidhar|where|location|live|pugyo|pugyo|pugi|pugeko|pahunchi|pahuncha|pahunchi|कहाँ|कहां|कता|किधर|पुग्यो|पुगेको|पहुँची|पहुंची|लोकेसन|लोकेशन)' . $z . '/u', $t) === 1
            && preg_match('/' . $a . '(?:office|counter|parking|pickup|stand|stop|ticket|tikat|seat|sit|driver|khali|अफिस|काउन्टर|पार्किङ|टिकट|सिट)' . $z . '/u', $t) !== 1
            && count(self::tokens($t)) <= 8) {
            return 'bus';
        }
        if ($hasDigit) {
            return '';                                     // every other office question is words only
        }
        $n = count(self::tokens($t));

        // seller board: the exact words the office was told to type …
        if (preg_match('/^(?:top\s+)?(?:agents?|ejent|एजेन्ट|एजेंट|एजेन्टहरू)(?:\s+(?:ko\s+)?(?:report|hisab|hisaab|bikri|sales?|रिपोर्ट|हिसाब|बिक्री))?(?:\s+(?:aaja|aja|aaj|today|yo\s+mahina|is\s+mahine|this\s+month|mahina|mahine|month|monthly|आज|महिना|महीना))?$/u', $t) === 1
            || preg_match('/^(?:counter|kaunter|काउन्टर)\s+(?:ko\s+)?(?:report|hisab|hisaab|रिपोर्ट|हिसाब)(?:\s+(?:aaja|aja|aaj|today|mahina|mahine|month|आज|महिना))?$/u', $t) === 1) {
            return $month ? 'board_month' : 'board_today';
        }
        // … and the question: "kun agent le kati kate?", "kaun agent ne kitne ticket kaate", "which agent sold how many"
        if ($n <= 10 && preg_match('/' . $agentWord . '/u', $t) === 1
            && preg_match('/' . $a . '(?:kati|katti|kitna|kitne|kitni|how\s+many|how\s+much|कति|कितने|कितना|कितनी)' . $z . '/u', $t) === 1
            // "kate / katyo / kateko / kaate" (cut) — never "kati" (how many), which every such line also holds
            && preg_match('/(?:' . $a . '(?:kat(?:e|yo|eko|ne|nu|cha|chha|xa|ay)\w*|kaat\w*|bech\w*|bik\w*|sale|sales|sold|sell|tickets?|tikat|tiket)' . $z . '|काट\S*|काटे\S*|बेच\S*|बिक\S*|टिकट)/u', $t) === 1) {
            return $month ? 'board_month' : 'board_today';
        }
        // pending money: "pending payment?", "payment baki kati cha", "verify baki", "bhuktani janch baki"
        if ($n <= 7 && preg_match('/' . $agentWord . '/u', $t) !== 1
            && preg_match('/' . $a . '(?:pending|baki|baaki|banki|unverified|unpaid|बाँकी|बाकी|पेन्डिङ|पेंडिंग)' . $z . '/u', $t) === 1
            && preg_match('/' . $a . '(?:payments?|pement|bhuktani|bhuktan|paisa|paise|verify|verification|janch|jaanch|भुक्तानी|भुगतान|पैसा|पेमेन्ट|पेमेंट|जाँच)' . $z . '/u', $t) === 1) {
            return 'pending';
        }
        // revenue: "revenue", "kamai", "aaja ko kamai kati", "aamdani kati bhayo"
        if ($n <= 6 && preg_match('/' . $a . '(?:revenue|income|kamai|kamaai|aamdani|amdani|आम्दानी|कमाइ|कमाई|आमदनी)' . $z . '/u', $t) === 1) {
            return 'revenue';
        }
        // the day's report by another name: "sanjh ko report", "evening report", "din ko report", "day report"
        if ($n <= 6 && preg_match('/' . $a . '(?:report|reports|riport|रिपोर्ट)' . $z . '/u', $t) === 1
            && preg_match('/' . $a . '(?:evening|sanjh|saanjh|sanjha|sajh|saajh|beluka|beluki|shaam|sham|din|day|daily|dinbhar|साँझ|सांझ|बेलुका|शाम|दिन|दिनको|दिनभर)' . $z . '/u', $t) === 1
            && preg_match('/' . $agentWord . '/u', $t) !== 1) {
            return 'day_report';
        }
        // "seat badalnu cha" with no PNR: how the desk does it
        if ($n <= 7 && preg_match('/' . $a . '(?:seats?|sit|berth|सिट|सीट)' . $z . '/u', $t) === 1
            && preg_match('/' . $a . '(?:badal\w*|badl\w*|change|swap|exchange|बदल\S*|चेन्ज|चेंज)' . $z . '/u', $t) === 1) {
            return 'seat_hint';
        }
        return '';
    }

    /* =================================================================
     *  The gates
     * ================================================================= */

    private static function wordsOn(): bool
    {
        return Settings::getBool('wa_office_words_on', true);
    }

    /**
     * The desk's own day-report words: a today-word anywhere ("aaja din ko report" — ApWa::canon reads today +
     * report as the seller's day) or a line that opens with its report command ("report daily", "sales …").
     */
    private static function deskDayWords(string $t): bool
    {
        return preg_match('/(?<![\p{L}\p{M}])(?:today|todays|aaja|aaj|aajako|aja|आज|आजको)(?![\p{L}\p{M}])/u', $t) === 1
            || preg_match('/^(?:report|reports|sales?|bikri|बिक्री|रिपोर्ट|my\s*day|मेरो\s*दिन)(?![\p{L}\p{M}])/u', $t) === 1;
    }

    /**
     * Company figures for a staff / office account wait for the same fresh verification the desk's company
     * report needs (AiVerify, tool office_day). null = no gate applies or the number is verified; otherwise the
     * reply that carries the one-time link (the same link the desk's "report" hands out — never a figure).
     *
     * @return array{text: string, media: ?string, role: string}|null
     */
    private static function stepUp(array $who): ?array
    {
        try {
            require_once INCLUDE_PATH . '/aiverify.php';
            $ctx = $who + ['channel' => 'whatsapp'];
            if (!AiVerify::needs('office_day', $ctx) || AiVerify::isFresh($ctx)) {
                return null;
            }
            $c = AiVerify::challenge($ctx);
        } catch (Throwable $e) {
            Logger::warning('WaOffice step-up failed: ' . $e->getMessage(), [], 'whatsapp');
            // the gate could not be read: never a figure — the panel has them
            return ['text' => "🔐 अहिले प्रमाणीकरण जाँच्न सकिएन — कम्पनीको हिसाब स्टाफ प्यानलमा हेर्नुहोस्।\nVerification could not be checked right now — please see the company figures in the staff panel.",
                'media' => null, 'role' => 'admin'];
        }
        $d = (array) ($c['data'] ?? []);
        $link = (string) ($d['link'] ?? '');
        if ($link !== '') {
            $text = "🔐 कम्पनीको हिसाब हेर्न पहिले प्रमाणित गर्नुहोस् — यो लिंक स्टाफ प्यानलमा साइन-इन भएको फोन वा कम्प्युटरमा खोल्नुहोस्:\n" . $link
                . "\n(एक पटक मात्र चल्छ, १० मिनेटसम्म। पासवर्ड यो च्याटमा कहिल्यै नलेख्नुहोस्।)\n"
                . "Verify first to see company figures: open this link where you are signed in to the staff panel (works once, 10 minutes), then ask again.";
        } elseif (!empty($d['already_sent'])) {
            $until = date('H:i', (int) strtotime((string) ($d['expires_at'] ?? 'now')));
            $text = "🔐 प्रमाणीकरणको लिंक यही च्याटमा पहिल्यै पठाइएको छ (" . $until . " सम्म चल्छ) — त्यो खोलेर फेरि सोध्नुहोस्।\n"
                . "A verification link was already sent in this chat (valid until " . $until . "). Open it while signed in to the staff panel, then ask again.";
        } elseif (!empty($d['rate_limited'])) {
            $text = "🔐 यो नम्बरबाट धेरै पटक लिंक मागियो — १५ मिनेट पर्खनुहोस् वा स्टाफ प्यानलमै हेर्नुहोस्।\n"
                . "Too many verification links were requested from this number — wait 15 minutes or use the staff panel.";
        } else {
            $text = "🔐 अहिले प्रमाणीकरणको लिंक बन्न सकेन — कम्पनीको हिसाब स्टाफ प्यानलमा हेर्नुहोस्।\n"
                . "The verification link could not be issued right now — please see the company figures in the staff panel.";
        }
        return ['text' => $text, 'media' => null, 'role' => 'admin'];
    }

    /**
     * Is the desk (or an edit / a guided order) waiting for this number's answer? Then this line is theirs.
     * A finished action the desk only remembers ("done") does not count.
     */
    private static function deskBusy(string $from, string $digits): bool
    {
        try {
            $raw = (string) Database::scalar('SELECT pending FROM ap_wa_state WHERE phone = :p', ['p' => $digits], '');
            if (trim($raw) !== '') {
                $p = json_decode($raw, true);
                if (!is_array($p)) {
                    return true;                           // unreadable = assume something waits
                }
                if ((string) ($p['kind'] ?? '') !== 'done' && (int) ($p['at'] ?? 0) >= time() - self::DESK_PENDING_TTL) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            // no desk table: nothing waits there
        }
        try {
            require_once INCLUDE_PATH . '/waedit.php';
            if (WaEdit::owns($from, '')) {
                return true;
            }
        } catch (Throwable $e) {
        }
        try {
            require_once INCLUDE_PATH . '/ticketbot.php';
            require_once INCLUDE_PATH . '/quickticket.php';
            require_once INCLUDE_PATH . '/wabooking.php';
            if (WaBooking::isOpen($digits)) {
                return true;
            }
        } catch (Throwable $e) {
            return true;
        }
        return false;
    }

    /* =================================================================
     *  The answers (all read-only, all built by existing code)
     * ================================================================= */

    /** One of WaLocal's office answers, and only that one (its intents allow-list). */
    private static function local(string $from, string $ask, array $office, string $intent): ?array
    {
        require_once INCLUDE_PATH . '/ticketbot.php';
        require_once INCLUDE_PATH . '/walocal.php';
        $r = WaLocal::answer($from, $ask, $office + ['intents' => [$intent]]);
        if ($r === null || (string) ($r['intent'] ?? '') !== $intent) {
            return null;
        }
        return ['text' => (string) $r['text'], 'media' => $r['media'] ?? null];
    }

    /** "bus 3 kaha cha?" — that bus when the words name one we know; otherwise every bus on the road. Never a guess. */
    private static function busAnswer(string $from, string $t, array $office): ?array
    {
        $numbered = preg_match('/\d/', $t) === 1;
        $bus = null;
        try {
            require_once INCLUDE_PATH . '/aiops.php';
            $bus = AiOps::busFor($t);                      // "bus 3" / "Bus 03" / a plate / a bus name — the settings reading
        } catch (Throwable $e) {
            $bus = null;
        }
        if ($bus !== null) {
            $office['busId'] = (int) $bus['id'];
            $office['busLabel'] = trim((string) $bus['bus_name'] . ' · ' . (string) $bus['bus_number'], ' ·');
        }
        $r = self::local($from, 'bus kaha cha', $office, 'bus_live');
        if ($r === null) {
            return null;
        }
        if ($numbered && $bus === null) {
            $r['text'] = "ℹ️ त्यो नम्बरको बस चिनिएन — अहिले बाटोमा भएका सबै बस तल छन्।\nThat bus number is not known — every bus on the road is below.\n\n" . $r['text'];
        }
        return $r;
    }

    /** The office report, now (the same text the scheduled report sends). */
    private static function dayReport(): ?array
    {
        require_once INCLUDE_PATH . '/officereport.php';
        $r = OfficeReport::build(todayISO());
        return ['text' => (string) ($r['text'] ?? ''), 'media' => $r['media'] ?? null];
    }

    /** An agent / counter (or the office, for the seat line): how the desk already does it. */
    private static function staffHint(string $intent, string $role = 'staff'): ?array
    {
        $text = match ($intent) {
            'seat_hint' => "💺 सिट बदल्न: PNR र नयाँ सिट एउटै लाइनमा लेख्नुहोस् — जस्तै *SHG-2026-00123 seat A5* — अनि *ho*।\n"
                . "To change a seat: write the PNR and the new seat in one line (SHG-2026-00123 seat A5), then *ho*.",
            'day_report' => "📊 आजको रिपोर्ट: *report* वा *hisab* लेख्नुहोस्।\nToday's report: write *report* or *hisab*.",
            default => '',
        };
        if ($text === '') {
            return null;
        }
        self::$lastIntent = $intent;
        return ['text' => $text, 'media' => null, 'role' => $role];
    }

    /* =================================================================
     *  Helpers
     * ================================================================= */

    private static function norm(string $text): string
    {
        $t = mb_strtolower(strtr(trim($text), ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']));
        $t = preg_replace('/[?？!.,;:।॥"\'()*_~]+/u', ' ', $t) ?? $t;
        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /** @return list<string> */
    private static function tokens(string $t): array
    {
        return preg_split('/[^\p{L}\p{M}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
