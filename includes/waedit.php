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
        if ($pnr === '') {
            return null;          // no live ticket on this number: the rest of the bot answers
        }
        if ($intent === 'confirm') {
            return self::showTicket($pnr, $fromRaw, $who);
        }
        $b = Database::fetch('SELECT * FROM bookings WHERE pnr = :p LIMIT 1', ['p' => $pnr]);
        if ($b === null || !self::mayEdit($b, $fromRaw, $who)) {
            return null;          // words about a ticket that is not theirs: the rest of the bot answers
        }
        return self::start($fromRaw, $intl, $intent, $pnr, $who);
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
        return '';
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
                $date = self::parseDate($body);
                if ($date === '') {
                    if (preg_match('/\d|bholi|parsi|bhol|kal|tomorrow|भोलि|पर्सि|कल/iu', $body) !== 1) {
                        return null;    // not a date at all: the bot answers, the change stays open
                    }
                    return self::say('मिति बुझिएन — जस्तै 15 Oct वा 2026-10-15 लेख्नुहोस्। (रोक्न: stop)');
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

    /** A cancel request: free WhatsApp to the admin in its window, the panel bell always. */
    private static function tellOffice(array $b, string $intl): void
    {
        $text = "📝 रद्द अनुरोध · Cancel request\nPNR: " . (string) $b['pnr'] . "\nयात्रु नम्बर: +" . $intl . "\n(कोडले पुष्टि गरिएको · code verified)\nAdmin → Bookings मा हेर्नुहोस्।";
        try {
            $admin = WaAgentRoute::adminNumber();
            if ($admin !== '') {
                WaAgentRoute::send($admin, $text, null, null, 'cancel_request', (int) $b['id']);
            }
        } catch (Throwable $ignored) {
        }
        try {
            require_once INCLUDE_PATH . '/adminalerts.php';
            AdminAlerts::push('wa_cancel_request', 'Cancel request · ' . (string) $b['pnr'], 'Passenger +' . $intl . ' asked to cancel on WhatsApp (code verified). Settle by hand.',
                '/admin/booking-view.php?pnr=' . rawurlencode((string) $b['pnr']), '📝', 'wa_cancel:' . (int) $b['id']);
        } catch (Throwable $e) {
            Logger::warning('cancel request alert failed: ' . $e->getMessage(), [], 'whatsapp');
        }
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
