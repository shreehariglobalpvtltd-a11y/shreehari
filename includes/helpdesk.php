<?php
/**
 * =====================================================================
 *  HelpDesk — one numbered queue for every customer problem (5 Oct 2026)
 *
 *  Before this, only the WhatsApp assistant could open a support ticket
 *  (AiHandoff, SUP-yymmdd-xxxxx). The website complaint and "call me back"
 *  forms went to `enquiries` with a reference the browser made up, so a
 *  customer quoting it got nowhere. Now every door — the Help page, the
 *  chat complaint desk, call-me-back, the counter/phone desk and the
 *  assistant — opens the same kind of ticket with a short number the
 *  person can say on the phone:
 *
 *      HD-0051   (support_tickets.id, never reused)   +   today's token #7
 *
 *  Automation, all switchable from the Help Desk page:
 *    - category + priority guessed from the words when the person did not
 *      pick one (safety words are always urgent);
 *    - the booking is linked automatically (their PNR, else their latest
 *      trip on the same number);
 *    - an SLA due time per priority, overdue tickets are escalated once
 *      (priority +1, office WhatsApp alert) by cron/helpdesk.php;
 *    - auto-assign to the least-busy support person (the desk that sold the
 *      ticket first), resolved tickets close themselves after N days;
 *    - the number is sent to the customer (AiSensy template -> WhatsApp ->
 *      SMS) and ack_sent_at records only an ACCEPTED send.
 *
 *  Money is never touched here: a refund/cancel ticket is a note for the
 *  owner, who settles those by hand.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class HelpDesk
{
    /** key => [English, Nepali, default priority] */
    public const CATEGORIES = [
        'booking_help' => ['Booking help',          'बुकिङ सहायता',          'normal'],
        'ticket'       => ['Ticket not received',   'टिकट आएन',             'high'],
        'payment'      => ['Payment / money',       'भुक्तानी / पैसा',        'high'],
        'refund'       => ['Refund / cancel',       'रिफन्ड / रद्द',          'high'],
        'bus_late'     => ['Bus late / not found',  'बस ढिलो / भेटिएन',      'urgent'],
        'seat'         => ['Seat problem',          'सिट समस्या',            'normal'],
        'luggage'      => ['Luggage',               'सामान',                'high'],
        'staff'        => ['Staff behaviour',       'स्टाफको व्यवहार',        'high'],
        'safety'       => ['Safety / emergency',    'सुरक्षा / आपतकाल',      'urgent'],
        'callback'     => ['Call me back',          'मलाई फोन गर्नुहोस्',      'normal'],
        'other'        => ['Other',                 'अन्य',                 'normal'],
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** minutes until a ticket is overdue, per priority (settings override) */
    private const SLA_DEFAULT = ['urgent' => 60, 'high' => 240, 'normal' => 1440, 'low' => 2880];

    /** same number + category + text inside this window = the same ticket */
    private const DEDUPE_SECONDS = 900;

    private function __construct() {}

    /* -----------------------------------------------------------------
     *  Schema / settings
     * ----------------------------------------------------------------- */

    /** The 5 Oct migration added due_at; without it the desk runs in "basic" mode. */
    public static function ready(bool $recheck = false): bool
    {
        static $has = null;
        if ($has === null || $recheck) {
            try {
                $has = Database::fetch("SHOW COLUMNS FROM support_tickets LIKE 'due_at'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    public static function on(): bool
    {
        return Settings::getBool('helpdesk_on', true);
    }

    public static function sla(string $priority): int
    {
        $p = in_array($priority, self::PRIORITIES, true) ? $priority : 'normal';
        $m = Settings::getInt('helpdesk_sla_' . $p, self::SLA_DEFAULT[$p]);
        return max(5, min(60 * 24 * 14, $m));
    }

    public static function label(string $category, string $lang = 'en'): string
    {
        if (isset(self::CATEGORIES[$category])) {
            return self::CATEGORIES[$category][$lang === 'ne' ? 1 : 0];
        }
        if (class_exists('AiHandoff')) {
            return AiHandoff::label($category);
        }
        return ucfirst(str_replace('_', ' ', $category));
    }

    /** HD-0051 from the row id: short, unique, easy to read out on a call. */
    public static function refFor(int $id): string
    {
        return 'HD-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * What a person typed as their number -> the stored ticket_ref, or null.
     * Accepts "HD-0051", "hd 51", "#51", "51", and the older SUP-yymmdd-XXXXX.
     */
    public static function normRef(string $in): ?string
    {
        $s = strtoupper(trim($in));
        if (preg_match('/^SUP-\d{6}-[A-Z0-9]{5}$/', $s) === 1) {
            return $s;
        }
        if (preg_match('/^(?:HD)?[\s#-]*0*(\d{1,9})$/', $s, $m) === 1 && (int) $m[1] > 0) {
            return self::refFor((int) $m[1]);
        }
        return null;
    }

    /* -----------------------------------------------------------------
     *  Guessing (only when the person did not choose)
     * ----------------------------------------------------------------- */

    public static function guessCategory(string $text): string
    {
        $t = mb_strtolower($text);
        $rules = [
            'safety'   => '/accident|emergency|police|harass|unsafe|danger|fight|drunk|दुर्घटना|आपतकाल|प्रहरी|पुलिस|खतरा|झगडा/u',
            'refund'   => '/refund|cancel|money back|paisa wapas|paisa firta|रिफन्ड|रद्द|फिर्ता|वापस/u',
            'payment'  => '/payment|paid|upi|esewa|khalti|gpay|phonepe|deducted|transaction|bhuktani|भुक्तानी|पैसा कट|पैसा/u',
            'bus_late' => '/late|delay|not come|not came|missed|bus kaha|bus kahan|where is the bus|dhilo|ढिलो|बस कहाँ|बस कहां|छुट/u',
            'ticket'   => '/ticket|टिकट|pnr/u',
            'seat'     => '/seat|सिट|सीट/u',
            'luggage'  => '/luggage|bag|saman|samaan|parcel|सामान|झोला/u',
            'staff'    => '/staff|driver|conductor|rude|behav|misbehav|चालक|कन्डक्टर|व्यवहार/u',
            'callback' => '/call me|callback|call back|phone gar|phone kar|फोन गर|फोन कर/u',
        ];
        foreach ($rules as $cat => $re) {
            if (preg_match($re, $t) === 1) {
                return $cat;
            }
        }
        return 'other';
    }

    public static function priorityFor(string $category, string $text, ?string $asked = null): string
    {
        if ($asked !== null && in_array($asked, self::PRIORITIES, true)) {
            return $asked;
        }
        if (self::guessCategory($text) === 'safety') {
            return 'urgent';
        }
        return self::CATEGORIES[$category][2] ?? 'normal';
    }

    /* -----------------------------------------------------------------
     *  Open a ticket
     * ----------------------------------------------------------------- */

    /**
     * @param array<string,mixed> $in name, phone, country (IN|NP), category, message,
     *        pnr, source (web|chat|callback|desk|admin), priority, created_by,
     *        lang, ack (bool), trusted (bool: staff typed it, PNR need not match the phone)
     * @return array{ok:bool, error?:string, id?:int, ref?:string, token?:int, status?:string,
     *               due_at?:?string, assigned?:string, ack?:bool, ack_via?:string, duplicate?:bool}
     */
    public static function open(array $in): array
    {
        $digits  = normalisePhone((string) ($in['phone'] ?? ''));
        $country = strtoupper((string) ($in['country'] ?? ''));
        if (!in_array($country, ['IN', 'NP'], true)) {
            $country = str_starts_with(preg_replace('/\D/', '', (string) ($in['phone'] ?? '')) ?? '', '977') ? 'NP' : 'IN';
        }
        if (strlen($digits) < 9 || strlen($digits) > 10) {
            return ['ok' => false, 'error' => 'phone'];
        }
        $intl    = ($country === 'NP' ? '977' : '91') . $digits;
        $message = self::redact(Security::clean((string) ($in['message'] ?? ''), 2000));
        $catIn   = (string) ($in['category'] ?? '');
        $cat     = isset(self::CATEGORIES[$catIn]) ? $catIn : self::guessCategory($message);
        if (mb_strlen($message) < 3) {
            if ($cat === 'callback') {
                $message = 'Please call me back.';
            } else {
                return ['ok' => false, 'error' => 'message'];
            }
        }
        $trusted = ($in['trusted'] ?? false) === true;
        $prio    = self::priorityFor($cat, $message, $trusted && isset($in['priority']) ? (string) $in['priority'] : null);
        $name    = trim(Security::clean((string) ($in['name'] ?? ''), 120));
        $source  = in_array((string) ($in['source'] ?? ''), ['web', 'chat', 'callback', 'desk', 'admin', 'whatsapp_ai'], true) ? (string) $in['source'] : 'web';
        $lang    = in_array((string) ($in['lang'] ?? ''), ['en', 'ne', 'hi', 'gu'], true) ? (string) $in['lang'] : 'ne';

        // Same person, same problem, same words within 15 minutes: one ticket.
        $key = hash('sha256', $digits . '|' . $cat . '|' . mb_strtolower($message));
        try {
            $dup = Database::fetch(
                "SELECT id, ticket_ref, status FROM support_tickets
                  WHERE dedupe_key = :k AND created_at >= NOW() - INTERVAL " . self::DEDUPE_SECONDS . " SECOND
                    AND status IN ('open','in_progress') ORDER BY id DESC LIMIT 1",
                ['k' => $key]
            );
        } catch (Throwable $e) {
            $dup = null;
        }
        if ($dup !== null) {
            return ['ok' => true, 'duplicate' => true, 'id' => (int) $dup['id'], 'ref' => (string) $dup['ticket_ref'],
                    'status' => (string) $dup['status'], 'token' => self::tokenOf((int) $dup['id'])];
        }

        // Link the booking: the PNR they gave (if it is theirs), else their latest trip.
        [$bookingId, $pnr, $deskCode] = self::linkBooking($digits, strtoupper(trim((string) ($in['pnr'] ?? ''))), $trusted);
        if ($name === '' && $bookingId !== null) {
            try {
                $name = (string) (Database::fetch(
                    'SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY is_primary DESC, id LIMIT 1',
                    ['b' => $bookingId])['full_name'] ?? '');
            } catch (Throwable $ignored) {
            }
        }
        if ($name === '') {
            $name = 'Customer ' . maskPhone($digits);
        }

        $ready = self::ready();
        $row = [
            'ticket_ref' => 'HD-T' . strtoupper(randomChars(10)),     // replaced by HD-<id> below
            'booking_id' => $bookingId,
            'name'       => mb_substr($name, 0, 120),
            'phone'      => $intl,
            'subject'    => mb_substr(self::label($cat) . ($pnr !== '' ? ' · ' . $pnr : ''), 0, 191),
            'message'    => $message,
            'category'   => $cat,
            'priority'   => $prio,
            'status'     => 'open',
        ];
        $extra = [
            'source'      => $source,
            'sender_role' => $source === 'desk' || $source === 'admin' ? 'staff' : 'customer',
            'language'    => $lang,
            'dedupe_key'  => $key,
        ];
        if ($ready) {
            $extra['due_at']       = date('Y-m-d H:i:s', time() + self::sla($prio) * 60);
            $extra['counter_code'] = $deskCode !== '' ? $deskCode : null;
            $extra['created_by']   = isset($in['created_by']) ? (int) $in['created_by'] : null;
        }
        try {
            $id = Database::transaction(static function () use ($row, $extra, $name, $message): int {
                try {
                    $id = (int) Database::insert('support_tickets', $row + $extra);
                } catch (Throwable $e) {
                    $id = (int) Database::insert('support_tickets', $row);   // pre-migration database
                }
                Database::update('support_tickets', ['ticket_ref' => self::refFor($id)], 'id = :id', ['id' => $id]);
                Database::insert('support_messages', [
                    'ticket_id' => $id, 'sender_type' => 'customer',
                    'sender_name' => mb_substr($name, 0, 120), 'message' => $message,
                ]);
                return $id;
            });
        } catch (Throwable $e) {
            Logger::exception($e, 'helpdesk');
            return ['ok' => false, 'error' => 'save'];
        }
        $ref   = self::refFor($id);
        $token = self::stampToken($id);

        Logger::audit('support.open', 'support_ticket', $ref, null, [
            'category' => $cat, 'priority' => $prio, 'source' => $source,
            'phone' => maskPhone($digits), 'booking' => $bookingId,
        ], $trusted ? 'opened by staff #' . (int) ($in['created_by'] ?? 0) : 'opened by the customer');

        $assigned = '';
        if ($ready && Settings::getBool('helpdesk_autoassign', true)) {
            $a = self::autoAssign($id, $deskCode);
            $assigned = $a['name'] ?? '';
        }
        if (in_array($prio, ['urgent', 'high'], true) && Settings::getBool('helpdesk_office_alert', true)) {
            self::alertOffice($id, $prio === 'urgent' ? 'URGENT' : 'High priority');
        }
        $ack = ['ok' => false, 'via' => ''];
        if (($in['ack'] ?? true) !== false && Settings::getBool('helpdesk_ack', true)) {
            $ack = self::acknowledge($id);
        }

        return ['ok' => true, 'id' => $id, 'ref' => $ref, 'token' => $token, 'status' => 'open',
                'priority' => $prio, 'category' => $cat,
                'due_at' => $extra['due_at'] ?? null, 'assigned' => $assigned,
                'ack' => $ack['ok'], 'ack_via' => $ack['via'], 'duplicate' => false];
    }

    /**
     * Give a ticket the assistant (AiHandoff) just wrote the same treatment:
     * HD number, token, SLA, auto-assign. Returns the new reference.
     */
    public static function adopt(int $id): string
    {
        $ref = self::refFor($id);
        try {
            $t = Database::fetch('SELECT t.*, b.counter_code AS bk_desk FROM support_tickets t LEFT JOIN bookings b ON b.id = t.booking_id WHERE t.id = :id', ['id' => $id]);
        } catch (Throwable $e) {
            $t = Database::fetch('SELECT * FROM support_tickets WHERE id = :id', ['id' => $id]);
        }
        if ($t === null) {
            return $ref;
        }
        $set = ['ticket_ref' => $ref];
        $desk = (string) ($t['bk_desk'] ?? '');
        if (self::ready()) {
            $set['due_at'] = date('Y-m-d H:i:s', strtotime((string) $t['created_at']) + self::sla((string) $t['priority']) * 60);
            if ($desk !== '') {
                $set['counter_code'] = $desk;
            }
        }
        try {
            Database::update('support_tickets', $set, 'id = :id', ['id' => $id]);
        } catch (Throwable $e) {
            return (string) $t['ticket_ref'];
        }
        self::stampToken($id);
        if (self::ready() && Settings::getBool('helpdesk_autoassign', true) && empty($t['assigned_to'])) {
            self::autoAssign($id, $desk);
        }
        return $ref;
    }

    /** @return array{0:?int,1:string,2:string} booking id, PNR, desk code */
    private static function linkBooking(string $digits, string $pnr, bool $trusted): array
    {
        try {
            if ($pnr !== '' && Security::isValidPnr($pnr)) {
                $b = Database::fetch('SELECT * FROM bookings WHERE pnr = :p LIMIT 1', ['p' => $pnr]);
                if ($b !== null && ($trusted || normalisePhone((string) $b['contact_phone']) === $digits
                        || normalisePhone((string) ($b['alt_phone'] ?? '')) === $digits)) {
                    return [(int) $b['id'], (string) $b['pnr'], (string) ($b['counter_code'] ?? '')];
                }
            }
            $b = Database::fetch(
                "SELECT * FROM bookings WHERE contact_phone IN (:p1, :p2, :p3)
                    AND created_at >= NOW() - INTERVAL 45 DAY ORDER BY id DESC LIMIT 1",
                ['p1' => $digits, 'p2' => '91' . $digits, 'p3' => '977' . $digits]
            );
            if ($b !== null) {
                return [(int) $b['id'], (string) $b['pnr'], (string) ($b['counter_code'] ?? '')];
            }
        } catch (Throwable $ignored) {
        }
        return [null, '', ''];
    }

    /** Today's token: the Nth ticket opened today (shown as "#7"). */
    private static function stampToken(int $id): int
    {
        $n = self::tokenOf($id);
        if (self::ready()) {
            try {
                Database::update('support_tickets', ['day_token' => $n], 'id = :id', ['id' => $id]);
            } catch (Throwable $ignored) {
            }
        }
        return $n;
    }

    private static function tokenOf(int $id): int
    {
        try {
            $r = Database::fetch(
                'SELECT COUNT(*) AS n FROM support_tickets t
                  JOIN support_tickets me ON me.id = :id
                 WHERE t.created_at >= DATE(me.created_at) AND t.id <= me.id',
                ['id' => $id]
            );
            return max(1, (int) ($r['n'] ?? 1));
        } catch (Throwable $e) {
            return 1;
        }
    }

    /* -----------------------------------------------------------------
     *  Staff + assignment
     * ----------------------------------------------------------------- */

    /**
     * Everyone who may answer tickets: active, a support.reply role (or the
     * extra grant). The owner (superadmin) is only a fallback for auto-assign.
     *
     * @return list<array{id:int,name:string,role:string,desk:string,open:int,super:bool}>
     */
    public static function staff(): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT a.id, a.full_name, a.username, a.role, a.permissions, a.last_login_at,
                        COALESCE(p.counter_code, '') AS desk,
                        (SELECT COUNT(*) FROM support_tickets s WHERE s.assigned_to = a.id AND s.status IN ('open','in_progress')) AS open_n
                   FROM admins a
                   LEFT JOIN admin_profiles p ON p.admin_id = a.id
                  WHERE a.is_active = 1 AND a.role IN ('superadmin','manager','support','official','counter')
                  ORDER BY a.role = 'superadmin', a.full_name"
            );
        } catch (Throwable $e) {
            try {
                $rows = Database::fetchAll(
                    "SELECT a.id, a.full_name, a.username, a.role, a.permissions, a.last_login_at, '' AS desk, 0 AS open_n
                       FROM admins a WHERE a.is_active = 1 AND a.role IN ('superadmin','manager','support','official','counter')"
                );
            } catch (Throwable $e2) {
                return [];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'    => (int) $r['id'],
                'name'  => trim((string) ($r['full_name'] ?: $r['username'])),
                'role'  => (string) $r['role'],
                'desk'  => strtoupper((string) $r['desk']),
                'open'  => (int) $r['open_n'],
                'super' => (string) $r['role'] === 'superadmin',
                'seen'  => (string) ($r['last_login_at'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array{id:int,name:string}|null */
    public static function autoAssign(int $id, string $deskCode = ''): ?array
    {
        $staff = array_values(array_filter(self::staff(), static function (array $s): bool {
            // Only people who actually use the panel: seen in the last 14 days.
            return !$s['super'] && $s['seen'] !== '' && strtotime($s['seen']) >= time() - 14 * 86400;
        }));
        if ($staff === []) {
            return null;                                  // nobody active: stays in the shared queue
        }
        $deskCode = strtoupper(trim($deskCode));
        if ($deskCode !== '') {
            $atDesk = array_values(array_filter($staff, static fn (array $s): bool => $s['desk'] === $deskCode));
            if ($atDesk !== []) {
                $staff = $atDesk;
            }
        }
        usort($staff, static fn (array $a, array $b): int => [$a['open'], $a['id']] <=> [$b['open'], $b['id']]);
        $pick = $staff[0];
        try {
            Database::update('support_tickets', ['assigned_to' => $pick['id']], 'id = :id AND assigned_to IS NULL', ['id' => $id]);
        } catch (Throwable $e) {
            return null;
        }
        Logger::audit('support.autoassign', 'support_ticket', self::refFor($id), null, ['to' => $pick['id']], 'help desk auto-assign');
        return ['id' => $pick['id'], 'name' => $pick['name']];
    }

    /* -----------------------------------------------------------------
     *  Messages out
     * ----------------------------------------------------------------- */

    /** Public status page for a ticket (the link sent to the customer). */
    public static function statusUrl(string $ref): string
    {
        return appUrl('/help.php?ref=' . rawurlencode($ref));
    }

    /**
     * Tell the customer their number. AiSensy template first (works outside
     * the 24-hour window once approved), then the configured WhatsApp
     * driver, then SMS. ack_sent_at is stamped only when a provider ACCEPTED
     * the message — accepted is not the same as delivered.
     *
     * @return array{ok:bool, via:string}
     */
    public static function acknowledge(int $id): array
    {
        $t = Database::fetch('SELECT * FROM support_tickets WHERE id = :id', ['id' => $id]);
        if ($t === null) {
            return ['ok' => false, 'via' => ''];
        }
        $ref   = (string) $t['ticket_ref'];
        $name  = trim((string) $t['name']);
        $cat   = self::label((string) $t['category'], 'ne');
        $link  = self::statusUrl($ref);
        $phone = (string) $t['phone'];
        $company = Settings::getString('company_name', APP_NAME);
        $text = "🙏 नमस्ते {$name}\n{$company} सहायता केन्द्र\n\nतपाईंको समस्या दर्ता भयो।\nनम्बर: *{$ref}*\nविषय: {$cat}\n\n"
              . "स्थिति हेर्न: {$link}\nफोन गर्दा यो नम्बर भन्नुहोस्।";
        $via = '';
        try {
            if (is_file(ROOT_PATH . '/whatsapp/aisensy.php')) {
                require_once ROOT_PATH . '/whatsapp/aisensy.php';
                if (AiSensyAPI::enabled()) {
                    $tpl = Settings::getString('helpdesk_wa_template', 'shg_help_ticket');
                    $r = $tpl !== '' ? AiSensyAPI::sendMessage($phone, $tpl, [$name !== '' ? $name : 'Customer', $ref, $cat, $link]) : ['success' => false];
                    if (!empty($r['success'])) {
                        $via = 'whatsapp_template';
                    } else {
                        $r = AiSensyAPI::sendText($phone, $text);       // inside the 24-hour window only
                        if (!empty($r['success'])) {
                            $via = 'whatsapp_text';
                        }
                    }
                }
            }
            if ($via === '') {
                require_once INCLUDE_PATH . '/notify.php';
                $r = Notify::whatsapp($phone, $text, null, null, [], $t['booking_id'] ? (int) $t['booking_id'] : null,
                    ['purpose' => 'support_ack']);
                if ($r === true) {
                    $via = 'whatsapp';
                } elseif (Settings::getBool('sms_enabled', false)) {
                    $sms = "{$company}: your help request number is {$ref}. Status: {$link}";
                    if (Notify::sms($phone, $sms, null, $t['booking_id'] ? (int) $t['booking_id'] : null)) {
                        $via = 'sms';
                    }
                }
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'helpdesk');
        }
        if ($via !== '' && self::ready()) {
            try {
                Database::update('support_tickets', ['ack_sent_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            } catch (Throwable $ignored) {
            }
        }
        return ['ok' => $via !== '', 'via' => $via];
    }

    /** Office WhatsApp alert for urgent/high and for escalations. */
    public static function alertOffice(int $id, string $why): bool
    {
        $office = Settings::officeWhatsApp();
        if ($office === '') {
            return false;
        }
        $t = Database::fetch('SELECT t.*, b.pnr, a.full_name AS assignee FROM support_tickets t
                                LEFT JOIN bookings b ON b.id = t.booking_id LEFT JOIN admins a ON a.id = t.assigned_to
                               WHERE t.id = :id', ['id' => $id]);
        if ($t === null) {
            return false;
        }
        $text = "🆘 Help desk — {$why}\n" . $t['ticket_ref'] . ' · ' . self::label((string) $t['category'])
              . ' · ' . $t['priority'] . "\n" . $t['name'] . ' · +' . $t['phone']
              . ((string) ($t['pnr'] ?? '') !== '' ? ' · ' . $t['pnr'] : '')
              . "\n\n" . mb_substr((string) $t['message'], 0, 400)
              . "\n\nTaken by: " . ((string) ($t['assignee'] ?? '') !== '' ? $t['assignee'] : 'nobody yet')
              . "\n" . appUrl('/admin/support-inbox.php?open=' . $id . '#t' . $id);
        try {
            require_once INCLUDE_PATH . '/notify.php';
            $ok = Notify::whatsapp($office, $text, null, null, [], $t['booking_id'] ? (int) $t['booking_id'] : null, ['purpose' => 'support_alert']) === true;
        } catch (Throwable $e) {
            $ok = false;
        }
        if ($ok) {
            try {
                Database::update('support_tickets', ['office_notified_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            } catch (Throwable $ignored) {
            }
        }
        return $ok;
    }

    /* -----------------------------------------------------------------
     *  Customer side (help.php / api/helpdesk.php)
     * ----------------------------------------------------------------- */

    /**
     * The ticket as the customer may see it — only when the phone matches.
     * "Not found" and "not yours" read the same.
     *
     * @return array<string,mixed>|null
     */
    public static function publicView(string $refIn, string $phone): ?array
    {
        $ref    = self::normRef($refIn);
        $digits = normalisePhone($phone);
        if ($ref === null || strlen($digits) < 9) {
            return null;
        }
        try {
            $t = Database::fetch('SELECT t.*, b.pnr FROM support_tickets t LEFT JOIN bookings b ON b.id = t.booking_id WHERE t.ticket_ref = :r LIMIT 1', ['r' => $ref]);
        } catch (Throwable $e) {
            return null;
        }
        if ($t === null || normalisePhone((string) $t['phone']) !== $digits) {
            return null;
        }
        try {
            $msgs = Database::fetchAll(
                "SELECT sender_type, sender_name, message, created_at FROM support_messages
                  WHERE ticket_id = :t AND (sender_type = 'customer' OR is_internal = 0) ORDER BY id",
                ['t' => (int) $t['id']]
            );
        } catch (Throwable $e) {
            $msgs = [];                                   // no is_internal column: show nothing from staff
        }
        return [
            'ref'      => (string) $t['ticket_ref'],
            'token'    => isset($t['day_token']) ? (int) $t['day_token'] : null,
            'status'   => (string) $t['status'],
            'category' => (string) $t['category'],
            'label'    => self::label((string) $t['category'], 'ne'),
            'labelEn'  => self::label((string) $t['category']),
            'pnr'      => (string) ($t['pnr'] ?? ''),
            'opened'   => (string) $t['created_at'],
            'updated'  => (string) $t['updated_at'],
            'messages' => array_map(static fn (array $m): array => [
                'from' => $m['sender_type'] === 'admin' ? 'staff' : 'you',
                'name' => $m['sender_type'] === 'admin' ? (string) $m['sender_name'] : '',
                'text' => (string) $m['message'],
                'at'   => (string) $m['created_at'],
            ], $msgs),
        ];
    }

    /** The customer adds a line to their own ticket (re-opens a resolved one). */
    public static function customerReply(string $refIn, string $phone, string $text): bool
    {
        $view = self::publicView($refIn, $phone);
        $msg  = self::redact(Security::clean($text, 1500));
        if ($view === null || mb_strlen($msg) < 2 || $view['status'] === 'closed') {
            return false;
        }
        $t = Database::fetch('SELECT id, name, status FROM support_tickets WHERE ticket_ref = :r', ['r' => $view['ref']]);
        if ($t === null) {
            return false;
        }
        Database::insert('support_messages', [
            'ticket_id' => (int) $t['id'], 'sender_type' => 'customer',
            'sender_name' => mb_substr((string) $t['name'], 0, 120), 'message' => $msg,
        ]);
        $set = ['status' => in_array((string) $t['status'], ['resolved'], true) ? 'open' : (string) $t['status']];
        if ($set['status'] === 'open' && self::ready()) {
            $set['resolved_at'] = null;
        }
        Database::update('support_tickets', $set, 'id = :id', ['id' => (int) $t['id']]);
        Logger::audit('support.customer_reply', 'support_ticket', $view['ref'], null, null, 'customer wrote on the help page');
        return true;
    }

    /* -----------------------------------------------------------------
     *  Cron: escalate overdue, close old resolved
     * ----------------------------------------------------------------- */

    /** @return array{escalated:int, closed:int} */
    public static function tick(): array
    {
        $out = ['escalated' => 0, 'closed' => 0];
        if (!self::ready()) {
            return $out;
        }
        $up = ['low' => 'normal', 'normal' => 'high', 'high' => 'urgent', 'urgent' => 'urgent'];
        try {
            $late = Database::fetchAll(
                "SELECT id, priority FROM support_tickets
                  WHERE status IN ('open','in_progress') AND due_at IS NOT NULL AND due_at < NOW()
                    AND escalated_at IS NULL ORDER BY due_at LIMIT 50"
            );
        } catch (Throwable $e) {
            $late = [];
        }
        foreach ($late as $t) {
            $p = $up[(string) $t['priority']] ?? 'high';
            Database::update('support_tickets', [
                'priority' => $p, 'escalated_at' => date('Y-m-d H:i:s'),
                'due_at'   => date('Y-m-d H:i:s', time() + self::sla($p) * 60),
            ], 'id = :id', ['id' => (int) $t['id']]);
            Logger::audit('support.escalate', 'support_ticket', self::refFor((int) $t['id']), ['priority' => $t['priority']], ['priority' => $p], 'SLA passed');
            if (Settings::getBool('helpdesk_office_alert', true)) {
                self::alertOffice((int) $t['id'], 'OVERDUE — no answer in time');
            }
            $out['escalated']++;
        }
        $days = max(1, Settings::getInt('helpdesk_autoclose_days', 3));
        try {
            $out['closed'] = Database::run(
                "UPDATE support_tickets SET status = 'closed'
                  WHERE status = 'resolved' AND resolved_at IS NOT NULL AND resolved_at < NOW() - INTERVAL {$days} DAY"
            );
        } catch (Throwable $ignored) {
        }
        return $out;
    }

    /* -----------------------------------------------------------------
     *  Quick replies + numbers for the desk page
     * ----------------------------------------------------------------- */

    /** @return list<array{t:string, b:string}> */
    public static function canned(): array
    {
        $own = Settings::getArray('helpdesk_canned', []);
        $list = [];
        foreach ($own as $c) {
            if (is_array($c) && trim((string) ($c['t'] ?? '')) !== '' && trim((string) ($c['b'] ?? '')) !== '') {
                $list[] = ['t' => (string) $c['t'], 'b' => (string) $c['b']];
            }
        }
        if ($list !== []) {
            return $list;
        }
        return [
            ['t' => 'We are checking',    'b' => "नमस्ते 🙏 तपाईंको समस्या हामीले हेर्दैछौं। केही बेरमा जवाफ दिन्छौं।\nWe are checking this and will reply shortly."],
            ['t' => 'Ticket re-sent',     'b' => "तपाईंको टिकट फेरि पठाइएको छ। WhatsApp मा 'hi' लेख्नुभयो भने तुरुन्त आउँछ।\nYour ticket has been sent again."],
            ['t' => 'Bus location',       'b' => "बस बाटोमा छ। लाइभ स्थिति: " . appUrl('/track.php') . "\nThe bus is on the way — live position at the link."],
            ['t' => 'Refund — owner',     'b' => "रिफन्डको कुरा हाम्रो कार्यालयले हेर्छ, नियम अनुसार फिर्ता हुन्छ। कार्यालयबाट फोन आउनेछ।\nThe office will contact you about the refund."],
            ['t' => 'Call us',            'b' => "कृपया हाम्रो कार्यालयमा फोन गर्नुहोस्: " . Settings::getString('company_phone', '') . "\nPlease call our office."],
            ['t' => 'Solved — thank you', 'b' => "समस्या समाधान भयो 🙏 S Hari Global रोज्नुभएकोमा धन्यवाद।\nResolved — thank you for travelling with us."],
        ];
    }

    /** @return array<string,int|float> */
    public static function stats(?int $scopeAdminId = null): array
    {
        $s = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0, 'overdue' => 0, 'today' => 0,
              'resolved_today' => 0, 'unassigned' => 0, 'first_reply_min' => 0];
        $scope = $scopeAdminId !== null ? ' AND (t.booking_id IS NULL OR b.sold_by_admin_id = ' . (int) $scopeAdminId . ')' : '';
        try {
            foreach (Database::fetchAll('SELECT t.status, COUNT(*) n FROM support_tickets t LEFT JOIN bookings b ON b.id = t.booking_id WHERE 1=1' . $scope . ' GROUP BY t.status') as $r) {
                $s[(string) $r['status']] = (int) $r['n'];
            }
            $r = Database::fetch(
                "SELECT SUM(t.created_at >= CURDATE()) today,
                        SUM(t.status IN ('resolved','closed') AND t.resolved_at >= CURDATE()) resolved_today,
                        SUM(t.status IN ('open','in_progress') AND t.assigned_to IS NULL) unassigned
                   FROM support_tickets t LEFT JOIN bookings b ON b.id = t.booking_id WHERE 1=1" . $scope);
            $s['today'] = (int) ($r['today'] ?? 0);
            $s['resolved_today'] = (int) ($r['resolved_today'] ?? 0);
            $s['unassigned'] = (int) ($r['unassigned'] ?? 0);
            if (self::ready()) {
                $r = Database::fetch("SELECT COUNT(*) n FROM support_tickets t LEFT JOIN bookings b ON b.id = t.booking_id
                                       WHERE t.status IN ('open','in_progress') AND t.due_at < NOW()" . $scope);
                $s['overdue'] = (int) ($r['n'] ?? 0);
                $r = Database::fetch("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_reply_at)) m FROM support_tickets
                                       WHERE first_reply_at IS NOT NULL AND created_at >= NOW() - INTERVAL 7 DAY");
                $s['first_reply_min'] = (int) round((float) ($r['m'] ?? 0));
            }
        } catch (Throwable $ignored) {
        }
        return $s;
    }

    /** One-time codes, card numbers and passwords never reach the queue. */
    public static function redact(string $text): string
    {
        require_once INCLUDE_PATH . '/aihandoff.php';
        return AiHandoff::redact($text);
    }
}
