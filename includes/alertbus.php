<?php
/**
 * includes/alertbus.php — call-style alerts for the staff panel (26 Nov 2026, fx1).
 *
 * Owner: "naya ticket aayo bhane office ko phone ra khuleko panel call jastai bajnu
 * paryo, kasaile 'Seen' thichnu samma". Until now the panel played one half-second
 * chime and showed a green bar for 9 seconds - easy to miss, and only for a tab that
 * happened to be open at that moment.
 *
 * HOW IT WORKS (nothing here runs inside a booking or payment request)
 *   1. sweep()   TAILS the EventBus journal (automation_log): every booking.created /
 *                booking.approved / booking.expired the booking engine already writes
 *                becomes one alert_events row. First use only sets the cursor to the
 *                newest id, so an old ticket never rings.
 *   2. fromBell() is called by AdminAlerts::push() for the alerts that already reach
 *                the bell: cancel / date-change REQUESTS, driver SOS, bus late.
 *   3. feed()    the open panel asks api/alert-feed.php on its existing 15 s poll and
 *                gets the alerts THIS person may see (role matrix + agent / desk scope).
 *   4. ack()     "Seen" from the office writes acked_at once - the ring stops for everybody.
 *                "Seen" from an agent (and from a counter on anything but a new ticket) is
 *                personal: it stops for that person only, the office still hears it.
 *   5. deliver() web push ONLY to the phones that tapped "Ring on this phone"
 *                (alert_push_optin - an Office-chat subscription alone is never rung): one
 *                push per person per ring, again every alert_ring_repeat_min minutes up to
 *                alert_ring_repeat_max times. Optional office WhatsApp (default OFF).
 *
 * SWITCHES (settings, group 'alerts'; seeded by database/upgrade-2026-11-26-fx1-alertbus.sql)
 *   alert_bus_on (1)   off = every method returns at once, nothing is recorded
 *   alert_ring_on (1)  off = no ring card; the panel keeps its old short chime
 *   alert_push_on (1)  off = no web push from here
 *   alert_wa_on (0)    on = ringing alerts also go to the office WhatsApp (max 6 / hour)
 *   alert_ring_seconds / _repeat_min / _repeat_max / _burst / _self, alert_matrix,
 *   alert_role_prefs
 *
 * RULES KEPT
 *   - A cancel / date change is a REQUEST the owner settles by hand; no alert here says
 *     "cancelled" or "refunded", and nothing here changes a ticket or money.
 *   - No customer phone number in a title, body, push payload or log (scrub()).
 *   - An agent only ever gets alerts about tickets they sold. A counter gets its own desk's
 *     tickets, plus - where the matrix ticks it - the alerts that belong to no desk at all
 *     (a customer's own online booking, SOS); with counter_desk_isolation on, its desk only.
 *   - A viewer gets nothing and can acknowledge nothing.
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AlertBus
{
    /** event => [Nepali, English, icon, sound voice, rings by default, roles that get it by default] */
    public const EVENTS = [
        /* new_booking keeps the reach of the older px1 chime: everyone who may verify payments
           (manager, accountant, operations, official, counter) plus support. */
        'new_booking'       => ['नयाँ टिकट आयो', 'New ticket', '🎫', 'ring', true,
                                ['superadmin', 'manager', 'operations', 'accountant', 'support', 'official', 'counter']],
        'agent_ticket'      => ['एजेन्टले टिकट काट्यो', 'Agent ticket', '🧾', 'ring', true,
                                ['superadmin', 'manager', 'operations', 'accountant', 'support', 'official']],
        'desk_ticket'       => ['काउन्टरले टिकट काट्यो', 'Counter ticket', '🎟️', 'tick', false,
                                ['superadmin', 'manager', 'operations', 'counter']],
        'payment_confirmed' => ['भुक्तानी पक्का भयो', 'Payment confirmed', '✅', 'chime', false,
                                ['superadmin', 'manager', 'operations', 'accountant', 'agent', 'counter']],
        'change_request'    => ['रद्द / मिति फेर्ने अनुरोध', 'Cancel / date-change request', '📝', 'alert', false,
                                ['superadmin', 'manager', 'operations', 'support', 'official', 'agent', 'counter']],
        'hold_expired'      => ['होल्ड सकियो, सिट खुला भयो', 'Hold expired', '⏳', 'pop', false,
                                ['superadmin', 'manager', 'operations', 'agent', 'counter']],
        'bus_late'          => ['बस ढिलो छ', 'Bus running late', '🕒', 'alert', false,
                                ['superadmin', 'manager', 'operations', 'support']],
        'driver_sos'        => ['चालकको SOS', 'Driver SOS', '🆘', 'siren', true,
                                ['superadmin', 'manager', 'operations', 'support', 'official']],
        'report_ready'      => ['दिनको रिपोर्ट तयार', 'Day report ready', '📊', 'ding', false,
                                ['superadmin', 'manager', 'accountant']],
        'agent_credit_low'  => ['एजेन्टको उधारो सीमा नजिक', 'Agent credit low', '💳', 'alert', false,
                                ['superadmin', 'manager', 'operations', 'accountant', 'agent']],
    ];

    /** Roles a row of the matrix may name. 'viewer' is read-only and never gets an alert. */
    public const ROLES = ['superadmin', 'manager', 'operations', 'accountant', 'support', 'official', 'counter', 'agent', 'scanner'];

    /** Events that ARE the seller's own act - the seller is not rung for them (alert_ring_self). */
    private const SELF_MADE = ['new_booking', 'agent_ticket', 'desk_ticket'];

    /**
     * Events that never belong to a desk or a seller (a customer's own booking, the road, the
     * day report). For these the matrix alone decides whether a COUNTER gets them; everything
     * else a counter gets only for its own desk. An agent never gets them at all.
     */
    private const NEVER_OWNED = ['new_booking', 'driver_sos', 'bus_late', 'report_ready'];

    /** Events that ring through quiet hours. */
    private const LOUD = ['driver_sos'];

    /**
     * Roles whose "Seen" answers for the whole office (stops the ring for everybody). An agent
     * is an outside seller and a counter serves one desk: their Seen must never silence the
     * owner's alert about a cancel request or about their own credit (answersForAll()).
     */
    private const OFFICE = ['superadmin', 'manager', 'operations', 'accountant', 'support', 'official'];

    /** Journal rows older than this are skipped (the bus was switched off, the cron was down). */
    private const STALE_SEC = 1800;

    /** The tail re-reads this many ids behind its cursor (a row that committed late); src_key dedupes. */
    private const OVERLAP = 25;

    private const LINK_RE = '~^/admin/[A-Za-z0-9_.\-/]+(\?[A-Za-z0-9_.\-=&%+]*)?$~';

    /** Test seam: fn(int $adminId, array $payload, string $event, int $ttl, string $urgency, string $topic): array */
    public static $pusher = null;

    /** Test seam: fn(string $headline, array $facts, ?int $bookingId): void */
    public static $waSender = null;

    /** Test seam: the people who have a phone subscribed ([['id'=>, 'role'=>, 'full_name'=>], ...]); null = read push_subscriptions. */
    public static ?array $recipients = null;

    private static ?bool $ready = null;

    /** @var array<int, string> admin id => desk code */
    private static array $desk = [];

    /** @var array<int, string> booking id => channel label */
    private static array $channel = [];

    /** Forget the per-request caches (tests; after a settings save). */
    public static function flush(): void
    {
        self::$ready   = null;
        self::$desk    = [];
        self::$channel = [];
    }

    /* =================================================================
     *  Switches and numbers
     * ================================================================= */

    /** The three tables exist (the migration has run). */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                /* one statement: admin_footer() asks this on every panel page */
                Database::query('SELECT 1 FROM alert_events e, alert_deliveries d, alert_bus_state s LIMIT 0');
                self::$ready = true;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function on(): bool
    {
        try {
            return Settings::getBool('alert_bus_on', true) && self::ready();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The full-screen ring is switched on (and the bus under it). */
    public static function ringOn(): bool
    {
        try {
            return self::on() && Settings::getBool('alert_ring_on', true);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The ring numbers, clamped to sane values.
     *
     * @return array{seconds:int, repeat:int, max:int, burst:int, window:int}
     */
    public static function cfg(): array
    {
        $repeat  = max(1, min(30, Settings::getInt('alert_ring_repeat_min', 2))) * 60;
        $seconds = max(10, min(300, Settings::getInt('alert_ring_seconds', 60)));
        $seconds = min($seconds, $repeat);          // a ring never runs into the next one
        $max     = max(0, min(10, Settings::getInt('alert_ring_repeat_max', 3)));
        return [
            'seconds' => $seconds,
            'repeat'  => $repeat,
            'max'     => $max,
            'burst'   => max(1, min(50, Settings::getInt('alert_ring_burst', 6))),
            'window'  => $max * $repeat + $seconds,
        ];
    }

    /**
     * Who gets what, and which events ring: the saved matrix over the code defaults.
     *
     * @return array<string, array{roles: list<string>, ring: bool}>
     */
    public static function matrix(): array
    {
        $saved = Settings::getArray('alert_matrix', []);
        $out   = [];
        foreach (self::EVENTS as $ev => $def) {
            $row   = is_array($saved[$ev] ?? null) ? $saved[$ev] : null;
            $roles = $def[5];
            if ($row !== null && is_array($row['roles'] ?? null)) {
                $roles = array_values(array_intersect(self::ROLES, array_map('strval', $row['roles'])));
            }
            $out[$ev] = [
                'roles' => $roles,
                'ring'  => $row !== null && array_key_exists('ring', $row) ? !empty($row['ring']) : (bool) $def[4],
            ];
        }
        return $out;
    }

    /**
     * Save the matrix form: $in[event]['roles'][role] = 1, $in[event]['ring'] = 1.
     * Unknown events and roles are dropped. Returns what was stored.
     */
    public static function saveMatrix(array $in): array
    {
        $store = [];
        foreach (self::EVENTS as $ev => $def) {
            $row   = is_array($in[$ev] ?? null) ? $in[$ev] : [];
            $given = is_array($row['roles'] ?? null) ? $row['roles'] : [];
            $roles = [];
            foreach (self::ROLES as $r) {
                if (!empty($given[$r]) || in_array($r, $given, true)) {
                    $roles[] = $r;
                }
            }
            $store[$ev] = ['roles' => $roles, 'ring' => !empty($row['ring']) ? 1 : 0];
        }
        Settings::set('alert_matrix', $store, 'json', 'alerts');
        Settings::flush();
        return $store;
    }

    /**
     * Per-role sound, volume and quiet hours.
     *
     * @return array<string, array{sound: bool, volume: int, quiet_from: string, quiet_to: string}>
     */
    public static function rolePrefs(): array
    {
        $saved = Settings::getArray('alert_role_prefs', []);
        $out   = [];
        foreach (self::ROLES as $r) {
            $p = is_array($saved[$r] ?? null) ? $saved[$r] : [];
            $out[$r] = [
                'sound'      => array_key_exists('sound', $p) ? !empty($p['sound']) : true,
                'volume'     => max(0, min(100, (int) ($p['volume'] ?? 80))),
                'quiet_from' => self::hhmm((string) ($p['quiet_from'] ?? '')),
                'quiet_to'   => self::hhmm((string) ($p['quiet_to'] ?? '')),
            ];
        }
        return $out;
    }

    public static function saveRolePrefs(array $in): array
    {
        $store = [];
        foreach (self::ROLES as $r) {
            $p = is_array($in[$r] ?? null) ? $in[$r] : [];
            $store[$r] = [
                'sound'      => !empty($p['sound']) ? 1 : 0,
                'volume'     => max(0, min(100, (int) ($p['volume'] ?? 80))),
                'quiet_from' => self::hhmm((string) ($p['quiet_from'] ?? '')),
                'quiet_to'   => self::hhmm((string) ($p['quiet_to'] ?? '')),
            ];
        }
        Settings::set('alert_role_prefs', $store, 'json', 'alerts');
        Settings::flush();
        return $store;
    }

    /**
     * Save the switches and ring numbers from the settings page. Returns how many changed.
     */
    public static function saveConfig(array $in): int
    {
        $changed = 0;
        foreach (['alert_bus_on' => true, 'alert_ring_on' => true, 'alert_push_on' => true, 'alert_wa_on' => false, 'alert_ring_self' => false] as $k => $def) {
            $v = !empty($in[$k]);
            if ($v !== Settings::getBool($k, $def)) {
                Settings::set($k, $v, 'bool', 'alerts');
                $changed++;
            }
        }
        foreach (['alert_ring_seconds' => [60, 10, 300], 'alert_ring_repeat_min' => [2, 1, 30],
                  'alert_ring_repeat_max' => [3, 0, 10], 'alert_ring_burst' => [6, 1, 50]] as $k => [$def, $lo, $hi]) {
            $v = max($lo, min($hi, (int) ($in[$k] ?? $def)));
            if ($v !== Settings::getInt($k, $def)) {
                Settings::set($k, $v, 'int', 'alerts');
                $changed++;
            }
        }
        Settings::flush();
        self::flush();
        return $changed;
    }

    private static function hhmm(string $s): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($s)) === 1 ? trim($s) : '';
    }

    /** Is this role inside its quiet hours at $now? (from == to or a blank = no quiet hours) */
    public static function inQuiet(string $role, ?int $now = null, ?array $prefs = null): bool
    {
        $p = ($prefs ?? self::rolePrefs())[$role] ?? null;
        if ($p === null || $p['quiet_from'] === '' || $p['quiet_to'] === '' || $p['quiet_from'] === $p['quiet_to']) {
            return false;
        }
        $t = date('H:i', $now ?? time());
        return $p['quiet_from'] < $p['quiet_to']
            ? ($t >= $p['quiet_from'] && $t < $p['quiet_to'])
            : ($t >= $p['quiet_from'] || $t < $p['quiet_to']);     // over midnight
    }

    /* =================================================================
     *  Recording an alert
     * ================================================================= */

    /** Hide anything that looks like a phone number (PNRs and amounts are left alone). */
    public static function scrub(string $s): string
    {
        /* a mobile written in groups: 98765 43210, 98765-43210, +91 98765 43210, +977-9812345678 */
        $s = (string) preg_replace('/(?<![A-Za-z0-9\-])\+?(?:\d{2,5}[\s\-])?\d{5}[\s\-]?\d{5}(?!\d)/u', '…', $s);
        /* any other run of 9 or more digits (a PNR's digits follow a letter or a dash, so they are left alone) */
        return (string) preg_replace('/(?<![A-Za-z0-9\-])\+?\d{9,15}(?!\d)/u', '…', $s);
    }

    /**
     * Record one alert. $srcKey is the dedupe: the same key is stored once.
     * $ctx: title, body, link, icon, booking_id, pnr, actor (admin id), desk, amount, at (unix), ring (test rows only).
     * Returns the new row id, 0 for a repeat / unknown event / bus off. Never throws.
     */
    public static function raise(string $event, array $ctx, string $srcKey, ?int $now = null): int
    {
        try {
            if (!isset(self::EVENTS[$event]) || $srcKey === '' || !self::on()) {
                return 0;
            }
            $now  = $now ?? time();
            $at   = (int) ($ctx['at'] ?? $now);
            $def  = self::EVENTS[$event];
            $ring = array_key_exists('ring', $ctx) ? !empty($ctx['ring']) : self::matrix()[$event]['ring'];
            $link = (string) ($ctx['link'] ?? '');
            if (preg_match(self::LINK_RE, $link) !== 1) {
                $link = '';
            }
            $title = Security::clean(self::scrub((string) ($ctx['title'] ?? '')), 191);
            if ($title === '') {
                $title = $def[2] . ' ' . $def[0] . ' · ' . $def[1];
            }
            $pnr  = Security::clean((string) ($ctx['pnr'] ?? ''), 40);
            $desk = Security::clean((string) ($ctx['desk'] ?? ''), 16);
            return Database::insertIgnore('alert_events', [
                'event'          => $event,
                'src_key'        => mb_substr($srcKey, 0, 80),
                'booking_id'     => !empty($ctx['booking_id']) ? (int) $ctx['booking_id'] : null,
                'pnr'            => $pnr !== '' ? $pnr : null,
                'actor_admin_id' => !empty($ctx['actor']) ? (int) $ctx['actor'] : null,
                'desk_code'      => $desk !== '' ? $desk : null,
                'amount'         => isset($ctx['amount']) && is_numeric($ctx['amount']) ? round((float) $ctx['amount'], 2) : null,
                'title'          => $title,
                'body'           => Security::clean(self::scrub((string) ($ctx['body'] ?? '')), 500),
                'link'           => $link !== '' ? $link : null,
                'icon'           => mb_substr((string) ($ctx['icon'] ?? $def[2]), 0, 4),
                'ring'           => $ring ? 1 : 0,
                'created_at'     => date('Y-m-d H:i:s', $at),
                'ring_until'     => $ring ? date('Y-m-d H:i:s', $at + self::cfg()['window']) : null,
            ]);
        } catch (Throwable $e) {
            self::warn('raise failed', $e, ['event' => $event]);
            return 0;
        }
    }

    /**
     * An alert that just reached the bell (AdminAlerts::push). Only the kinds the office
     * asked to hear about become an AlertBus event; a website sale is NOT taken from here
     * (the journal already has it - it must not ring twice). Never throws.
     */
    public static function fromBell(string $type, int $notificationId, string $title, string $body, string $link = '', string $icon = ''): int
    {
        try {
            $event = match ($type) {
                'ticket_request', 'wa_cancel_request', 'ticket_edit_request' => 'change_request',
                'driver_sos', 'driver_breakdown'                             => 'driver_sos',
                'trip_late'                                                  => 'bus_late',
                default                                                      => '',
            };
            if ($event === '' || $notificationId <= 0 || !self::on()) {
                return 0;
            }
            $def = self::EVENTS[$event];
            $ctx = [
                'title' => $def[2] . ' ' . $def[0] . ' · ' . $def[1],
                'body'  => trim($title . ($body !== '' ? ' — ' . $body : '')),
                'link'  => $link,
                'icon'  => $def[2],
            ];
            /* Whose ticket is it? The seller (agent) and the desk decide who else may see it. */
            if ($event === 'change_request' && preg_match('/SHG-[A-Z0-9\-]{4,36}/i', $title . ' ' . $body . ' ' . rawurldecode($link), $m) === 1) {
                $b = Database::fetch('SELECT id, pnr, sold_by_admin_id, counter_code FROM bookings WHERE pnr = :p LIMIT 1', ['p' => strtoupper($m[0])]);
                if ($b !== null) {
                    $ctx += ['booking_id' => (int) $b['id'], 'pnr' => (string) $b['pnr'],
                             'actor' => (int) ($b['sold_by_admin_id'] ?? 0), 'desk' => (string) ($b['counter_code'] ?? '')];
                }
            }
            return self::raise($event, $ctx, 'bell:' . $notificationId);
        } catch (Throwable $e) {
            self::warn('fromBell failed', $e, ['type' => $type]);
            return 0;
        }
    }

    /* =================================================================
     *  The journal tail
     * ================================================================= */

    private static function stateGet(string $k): ?int
    {
        $v = Database::scalar('SELECT v FROM alert_bus_state WHERE k = :k', ['k' => $k], null);
        return $v === null ? null : (int) $v;
    }

    private static function stateSet(string $k, int $v): void
    {
        Database::query('INSERT INTO alert_bus_state (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['k' => $k, 'v' => $v]);
    }

    /**
     * "Is it my turn?" - true at most once per $everySec across every request and the cron.
     */
    private static function turn(string $k, int $everySec, int $now): bool
    {
        if (Database::run('UPDATE alert_bus_state SET v = :n WHERE k = :k AND v <= :cut', ['n' => $now, 'k' => $k, 'cut' => $now - $everySec]) > 0) {
            return true;
        }
        /* no row yet: whoever creates it has the turn (the table has no auto-increment id to read back) */
        return Database::run('INSERT IGNORE INTO alert_bus_state (k, v) VALUES (:k, :v)', ['k' => $k, 'v' => $now]) > 0;
    }

    /**
     * The panel's poll: sweep at most once every 4 seconds however many tabs are open.
     */
    public static function sweepThrottled(?int $now = null): array
    {
        $now = $now ?? time();
        try {
            if (!self::on() || !self::turn('sweep_at', 4, $now)) {
                return ['read' => 0, 'raised' => 0, 'skipped' => true];
            }
        } catch (Throwable $e) {
            return ['read' => 0, 'raised' => 0, 'skipped' => true];
        }
        return self::sweep(null, null, $now);
    }

    /**
     * Read new journal rows and record their alerts.
     *
     * With no arguments it continues from its own cursor (first use = "start from now").
     * With $fromId (and $toId) it reads exactly that id range and leaves the cursor
     * alone - tests use this so they never touch another session's rows.
     *
     * @return array{read:int, raised:int, baseline?:bool, off?:bool}
     */
    public static function sweep(?int $fromId = null, ?int $toId = null, ?int $now = null): array
    {
        $out = ['read' => 0, 'raised' => 0];
        if (!self::on()) {
            return $out + ['off' => true];
        }
        $now = $now ?? time();
        try {
            $own = $fromId === null;
            if ($own) {
                $cur = self::stateGet('journal');
                if ($cur === null) {
                    Database::query("INSERT IGNORE INTO alert_bus_state (k, v) SELECT 'journal', COALESCE(MAX(id), 0) FROM automation_log");
                    return $out + ['baseline' => true];
                }
                $top = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM automation_log', [], 0);
                if ($top < $cur) {                       // the journal was emptied: follow it down
                    self::stateSet('journal', $top);
                    return $out;
                }
                if ($top === $cur) {
                    return $out;
                }
                $from = max(0, $cur - self::OVERLAP);
                $to   = $top;
            } else {
                $from = max(0, (int) $fromId);
                $to   = $toId !== null ? (int) $toId : PHP_INT_MAX;
            }
            $last = $from;
            for ($page = 0; $page < 8; $page++) {
                $rows = Database::fetchAll(
                    "SELECT id, event, dedupe_key, data, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
                       FROM automation_log
                      WHERE id > :a AND id <= :b
                        AND ((dedupe_key IS NULL AND event IN ('booking.created', 'booking.approved', 'booking.expired'))
                             OR event = 'cron.admin_digest')
                      ORDER BY id LIMIT 100",
                    ['a' => $last, 'b' => $to]
                );
                $out['read'] += count($rows);
                $out['raised'] += self::process($rows, $now);
                if (count($rows) < 100) {
                    $last = $to;
                    break;
                }
                $last = (int) $rows[count($rows) - 1]['id'];
            }
            if ($own) {
                Database::run("UPDATE alert_bus_state SET v = GREATEST(v, :v) WHERE k = 'journal'", ['v' => $last]);
            }
        } catch (Throwable $e) {
            self::warn('sweep failed', $e);
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $rows journal rows */
    private static function process(array $rows, int $now): int
    {
        if ($rows === []) {
            return 0;
        }
        /* skip what an earlier pass already stored (the overlap), in one read */
        $keys = [];
        $bind = [];
        foreach ($rows as $i => $r) {
            $keys[] = ':k' . $i;
            $bind['k' . $i] = 'j:' . (int) $r['id'];
        }
        $have = [];
        foreach (Database::fetchAll('SELECT src_key FROM alert_events WHERE src_key IN (' . implode(',', $keys) . ')', $bind) as $h) {
            $have[(string) $h['src_key']] = true;
        }
        $todo = [];
        $ids  = [];
        foreach ($rows as $r) {
            $age = max(0, (int) ($r['age'] ?? 0));
            if (isset($have['j:' . (int) $r['id']]) || $age > self::STALE_SEC) {
                continue;
            }
            $d = json_decode((string) ($r['data'] ?? ''), true);
            $d = is_array($d) ? $d : [];
            $ev = (string) $r['event'];
            if ($ev === 'booking.approved' && in_array((string) ($d['via'] ?? ''), ['counter', 'cod_create'], true)) {
                continue;                                 // the sale itself already told the office
            }
            if ($ev === 'cron.admin_digest' && ($r['dedupe_key'] ?? null) === null) {
                continue;                                 // only the claim row means "the digest ran"
            }
            $bid = (int) ($d['booking.id'] ?? 0);
            if ($ev !== 'cron.admin_digest' && $bid <= 0) {
                continue;
            }
            if ($bid > 0) {
                $ids[$bid] = $bid;
            }
            $todo[] = ['id' => (int) $r['id'], 'event' => $ev, 'd' => $d, 'bid' => $bid, 'at' => $now - $age,
                       'key' => (string) ($r['dedupe_key'] ?? '')];
        }
        if ($todo === []) {
            return 0;
        }
        $book = self::bookings(array_values($ids));
        $n = 0;
        foreach ($todo as $t) {
            $ctx = self::describeJournal($t['event'], $t['d'], $book[$t['bid']] ?? null, $t['key']);
            if ($ctx === null) {
                continue;
            }
            $event = $ctx['event'];
            unset($ctx['event']);
            $ctx['at'] = $t['at'];
            if (self::raise($event, $ctx, 'j:' . $t['id'], $now) > 0) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param  list<int> $ids
     * @return array<int, array<string, mixed>> booking id => seller, desk, seats, amount
     */
    private static function bookings(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }
        $out = [];
        try {
            /* WHO CUT IT: a clerk's sale is re-credited to an agent code before the journal row is
               written (BookingService::attributeDeskSale), so sold_by_admin_id is then the agent
               and only booked_by_admin_id still names the clerk (31 Oct 2026 column; may be absent). */
            $cut = CounterDesk::hasColumn('bookings', 'booked_by_admin_id');
            $rows = Database::fetchAll(
                'SELECT b.id, b.pnr, b.source, b.sold_by_admin_id, b.counter_code, b.total_amount, b.is_cod,
                        a.full_name AS seller_name, a.role AS seller_role,'
                . ($cut ? ' b.booked_by_admin_id, c.full_name AS cutter_name, c.role AS cutter_role,' : '') . '
                        (SELECT COUNT(*) FROM booking_passengers p WHERE p.booking_id = b.id) AS seat_count,
                        (SELECT GROUP_CONCAT(DISTINCT p2.seat_no ORDER BY p2.seat_no SEPARATOR \', \')
                           FROM booking_passengers p2 WHERE p2.booking_id = b.id) AS seats
                   FROM bookings b LEFT JOIN admins a ON a.id = b.sold_by_admin_id'
                . ($cut ? ' LEFT JOIN admins c ON c.id = b.booked_by_admin_id' : '') . '
                  WHERE b.id IN (' . implode(',', $ids) . ')'
            );
            foreach ($rows as $r) {
                $out[(int) $r['id']] = $r;
            }
        } catch (Throwable $e) {
            self::warn('booking read failed', $e);
        }
        return $out;
    }

    /**
     * One journal row -> the alert it means (null = none).
     *
     * @return array<string, mixed>|null
     */
    private static function describeJournal(string $ev, array $d, ?array $b, string $claimKey): ?array
    {
        if ($ev === 'cron.admin_digest') {
            $day = preg_match('/(\d{4}-\d{2}-\d{2})$/', $claimKey, $m) === 1 ? $m[1] : '';
            return ['event' => 'report_ready', 'title' => '📊 दिनको रिपोर्ट तयार · Day report ready',
                    'body' => $day !== '' ? $day . ' को हिसाब तयार छ · The day summary for ' . $day . ' is ready.' : '',
                    'link' => '/admin/index.php'];
        }
        $pnr    = (string) ($b['pnr'] ?? $d['booking.pnr'] ?? '');
        $seller = (int) ($b['sold_by_admin_id'] ?? $d['booking.sold_by_admin_id'] ?? 0);
        $amount = (float) ($b['total_amount'] ?? $d['booking.total_amount'] ?? 0);
        $name   = trim((string) ($b['seller_name'] ?? ''));
        $ctx = [
            'booking_id' => (int) ($b['id'] ?? $d['booking.id'] ?? 0),
            'pnr'        => $pnr,
            'actor'      => $seller,
            'desk'       => (string) ($b['counter_code'] ?? ''),
            'amount'     => $amount,
            'link'       => $pnr !== '' ? '/admin/booking-view.php?pnr=' . rawurlencode($pnr) : '/admin/bookings.php',
        ];
        $money = $amount > 0 ? inr($amount) : '';
        if ($ev === 'booking.created') {
            /* The person who CUT the ticket decides what kind of sale it is and who is not rung for
               it - not the agent code a desk sale was credited to. An office / counter sale credited
               to an agent (or to the company code) is still a desk ticket: a soft tick, never a call. */
            $cutter = (int) ($b['booked_by_admin_id'] ?? 0);
            $byDesk = $cutter > 0 && $cutter !== $seller;
            $who    = $byDesk ? $cutter : $seller;
            $role   = (string) ($byDesk ? ($b['cutter_role'] ?? '') : ($b['seller_role'] ?? ''));
            $event  = $who <= 0 ? 'new_booking' : ($role === 'agent' ? 'agent_ticket' : 'desk_ticket');
            $def    = self::EVENTS[$event];
            $n      = (int) ($b['seat_count'] ?? 0);
            $seats  = mb_substr((string) ($b['seats'] ?? ''), 0, 60);
            $parts  = [];
            if ($byDesk) {
                $name = trim((string) ($b['cutter_name'] ?? ''));
            }
            $ctx['actor'] = $who;
            if ($name !== '' && $who > 0) {
                $parts[] = $name;
            }
            if ($n > 0) {
                $parts[] = $n . ' सिट' . ($seats !== '' ? ' (' . $seats . ')' : '');
            }
            if ($money !== '') {
                $parts[] = $money;
            }
            if (!empty($b['is_cod'])) {
                $parts[] = 'बसमा भुक्तानी · pay at boarding';
            }
            return $ctx + ['event' => $event, 'title' => $def[2] . ' ' . $def[0] . ' · ' . $def[1] . ($pnr !== '' ? ' · ' . $pnr : ''),
                           'body' => implode(' · ', $parts)];
        }
        if ($ev === 'booking.approved') {
            return $ctx + ['event' => 'payment_confirmed', 'title' => '✅ भुक्तानी पक्का भयो · Payment confirmed' . ($pnr !== '' ? ' · ' . $pnr : ''),
                           'body' => implode(' · ', array_filter([$money, $seller > 0 ? $name : ''], static fn(string $s): bool => $s !== ''))];
        }
        if ($ev === 'booking.expired') {
            return $ctx + ['event' => 'hold_expired', 'title' => '⏳ होल्ड सकियो · Hold expired' . ($pnr !== '' ? ' · ' . $pnr : ''),
                           'body' => 'भुक्तानी नआएकोले सिट फेरि खुला भयो · No payment came, the seats are free again.'];
        }
        return null;
    }

    /**
     * Agents at (or past) the warning line of their credit limit: one alert per agent per day.
     * Runs at most once every 10 minutes; called by the cron and after the poll's answer has left.
     */
    public static function creditWatch(?int $now = null): int
    {
        $now = $now ?? time();
        $n   = 0;
        try {
            if (!self::on() || !is_file(INCLUDE_PATH . '/agentcredit.php') || !self::turn('credit_at', 600, $now)) {
                return 0;
            }
            if (!class_exists('AgentWallet') && is_file(INCLUDE_PATH . '/agentwallet.php')) {
                require_once INCLUDE_PATH . '/agentwallet.php';
            }
            require_once INCLUDE_PATH . '/agentcredit.php';
            /* Only agents who HAVE a limit can be near it: their own (admin_profiles.credit_limit), or
               everybody when the company default is set. Keeps this to a handful of reads on live. */
            if (Settings::getFloat('agent_credit_limit_default', 0.0) > 0.009) {
                $agents = Database::fetchAll("SELECT id, full_name FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY id DESC LIMIT 500");
            } elseif (AgentCredit::hasLimitColumn()) {
                $agents = Database::fetchAll(
                    "SELECT a.id, a.full_name FROM admins a JOIN admin_profiles p ON p.admin_id = a.id
                      WHERE a.role = 'agent' AND a.is_active = 1 AND p.credit_limit > 0 ORDER BY a.id DESC LIMIT 500"
                );
            } else {
                $agents = [];
            }
            foreach ($agents as $a) {
                $id = (int) $a['id'];
                $st = AgentCredit::status($id);
                if ((float) $st['limit'] <= 0.009 || (empty($st['warn']) && empty($st['blocked']))) {
                    continue;
                }
                $body = trim((string) $a['full_name']) . ' · ' . (int) $st['pct'] . '% · बाँकी ' . inr((float) $st['outstanding'])
                      . ' / सीमा ' . inr((float) $st['limit'])
                      . (!empty($st['blocked']) ? ' · नयाँ बिक्री रोकिएको छ · new sales are stopped' : '');
                if (self::raise('agent_credit_low', ['body' => $body, 'actor' => $id, 'link' => '/admin/agent-360.php?agent=' . $id],
                                'credit:' . $id . ':' . date('Y-m-d', $now), $now) > 0) {
                    $n++;
                }
            }
        } catch (Throwable $e) {
            self::warn('creditWatch failed', $e);
        }
        return $n;
    }

    /* =================================================================
     *  Who may see an alert
     * ================================================================= */

    private static function deskOf(int $adminId): string
    {
        if (!isset(self::$desk[$adminId])) {
            try {
                self::$desk[$adminId] = (string) (CounterDesk::forAdmin($adminId)['code'] ?? '');
            } catch (Throwable $e) {
                self::$desk[$adminId] = '';
            }
        }
        return self::$desk[$adminId];
    }

    /**
     * May this staff member ($admin: id + role) be told about this alert row?
     * Decided on the server every time - the browser never filters.
     * $selfRule = false asks "may this person KNOW about it" (the log on the settings page):
     * the same matrix and scope, without "the seller is not rung for their own sale".
     */
    public static function visibleTo(array $admin, array $row, ?array $matrix = null, bool $selfRule = true): bool
    {
        $id    = (int) ($admin['id'] ?? 0);
        $role  = (string) ($admin['role'] ?? '');
        $event = (string) ($row['event'] ?? '');
        $actor = (int) ($row['actor_admin_id'] ?? 0);
        if ($id <= 0 || $role === '' || $role === 'viewer' || !isset(self::EVENTS[$event])) {
            return false;
        }
        if (str_starts_with((string) ($row['src_key'] ?? ''), 'test:')) {
            return $actor === $id;                         // a test button rings only the person who pressed it
        }
        if ($role !== 'superadmin') {                      // the owner hears everything; a matrix cannot lock him out
            $roles = ($matrix ?? self::matrix())[$event]['roles'] ?? [];
            if (!in_array($role, $roles, true)) {
                return false;
            }
        }
        if ($role === 'agent' && $actor !== $id) {
            return false;                                  // an agent: own tickets only, always
        }
        if ($role === 'counter' && $actor !== $id) {
            $desk  = self::deskOf($id);
            $mine  = $desk !== '' && strcasecmp($desk, (string) ($row['desk_code'] ?? '')) === 0;
            /* nobody's desk (an online ticket, an SOS): the matrix tick decides - unless the office
               has pinned counters to their own desk's books (counter_desk_isolation) */
            $open  = in_array($event, self::NEVER_OWNED, true) && !Settings::getBool('counter_desk_isolation', false);
            if (!$mine && !$open) {
                return false;                              // a counter: its own desk only
            }
        }
        if ($selfRule && $actor === $id && in_array($event, self::SELF_MADE, true) && !Settings::getBool('alert_ring_self', false)) {
            return false;                                  // nobody is rung for the ticket they just cut
        }
        return true;
    }

    /**
     * Does this person's "Seen" stop the ring for EVERYBODY? The office's does. An agent's never
     * does, and a counter's only for a new ticket (it serves that customer): for a cancel /
     * date-change request, a payment, a credit warning or an SOS their Seen is personal - it
     * goes quiet for them, the owner still hears it.
     */
    public static function answersForAll(array $admin, array $row): bool
    {
        $role = (string) ($admin['role'] ?? '');
        if (str_starts_with((string) ($row['src_key'] ?? ''), 'test:')) {
            return true;                                   // a sample only its sender can see
        }
        if (in_array($role, self::OFFICE, true)) {
            return true;
        }
        return $role === 'counter' && in_array((string) ($row['event'] ?? ''), self::SELF_MADE, true);
    }

    /**
     * The rings this person already answered for themself (a personal Seen: one
     * alert_deliveries row, channel 'seen').
     *
     * @param  list<array<string, mixed>> $rows alert_events rows
     * @return array<int, true>
     */
    private static function seenBy(int $adminId, array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) ($r['id'] ?? 0);
        }
        $ids = array_values(array_filter($ids, static fn(int $i): bool => $i > 0));
        if ($adminId <= 0 || $ids === []) {
            return [];
        }
        $out = [];
        foreach (Database::fetchAll(
            "SELECT alert_id FROM alert_deliveries WHERE admin_id = :a AND channel = 'seen' AND alert_id IN (" . implode(',', $ids) . ')',
            ['a' => $adminId]
        ) as $r) {
            $out[(int) $r['alert_id']] = true;
        }
        return $out;
    }

    /** May this role read (and so mark read) the office bell? Decided by role: the phone's Seen has no session. */
    private static function readsBell(string $role): bool
    {
        try {
            return $role === 'superadmin' || in_array('dashboard.view', Auth::rolePermissions($role), true);
        } catch (Throwable $e) {
            return false;
        }
    }

    /* =================================================================
     *  The panel's feed
     * ================================================================= */

    private static function channelOf(int $bookingId): string
    {
        if ($bookingId <= 0) {
            return '';
        }
        if (!isset(self::$channel[$bookingId])) {
            try {
                self::$channel[$bookingId] = BookingChannel::label(BookingChannel::of($bookingId));
            } catch (Throwable $e) {
                self::$channel[$bookingId] = '';
            }
        }
        return self::$channel[$bookingId];
    }

    /** One stored row as the panel (and a push) shows it. The sales channel is read NOW - WhatsApp is tagged after the sale. */
    private static function item(array $r): array
    {
        $event = (string) $r['event'];
        $sale  = in_array($event, self::SELF_MADE, true);
        return [
            'id'      => (int) $r['id'],
            'event'   => $event,
            'title'   => (string) $r['title'],
            'body'    => (string) $r['body'],
            'link'    => (string) ($r['link'] ?? ''),
            'icon'    => (string) ($r['icon'] ?? ''),
            'sound'   => self::EVENTS[$event][3] ?? 'pop',
            'at'      => (int) strtotime((string) $r['created_at']),
            'pnr'     => (string) ($r['pnr'] ?? ''),
            'channel' => $sale ? self::channelOf((int) ($r['booking_id'] ?? 0)) : '',
            'loud'    => in_array($event, self::LOUD, true),
            'test'    => str_starts_with((string) $r['src_key'], 'test:'),
        ];
    }

    /**
     * What the open panel of this person should show and sound now.
     *
     * @return array<string, mixed>
     */
    public static function feed(array $admin, ?int $now = null): array
    {
        $now = $now ?? time();
        $cfg = self::cfg();
        $out = ['on' => false, 'ring' => false, 'now' => $now, 'seconds' => $cfg['seconds'], 'repeat' => $cfg['repeat'], 'max' => $cfg['max'],
                'rings' => [], 'ringCount' => 0, 'soft' => [], 'sound' => true, 'volume' => 80, 'quiet' => false,
                'push' => ['on' => false]];
        try {
            if (!self::on()) {
                return $out;
            }
            $role  = (string) ($admin['role'] ?? '');
            $prefs = self::rolePrefs();
            $out['on']     = true;
            $out['me']     = (int) ($admin['id'] ?? 0);
            $out['sound']  = (bool) ($prefs[$role]['sound'] ?? true);
            $out['volume'] = (int) ($prefs[$role]['volume'] ?? 80);
            $out['quiet']  = self::inQuiet($role, $now, $prefs);
            $out['push']   = ['on' => Settings::getBool('alert_push_on', true) && Settings::getBool('push_enabled', true)];
            $matrix = self::matrix();
            $out['ring'] = Settings::getBool('alert_ring_on', true);

            if ($out['ring']) {
                $open = self::openRings($now);
                $mine = self::seenBy((int) ($admin['id'] ?? 0), $open);     // a personal Seen: quiet for this person only
                foreach ($open as $r) {
                    if (isset($mine[(int) $r['id']]) || !self::visibleTo($admin, $r, $matrix)) {
                        continue;
                    }
                    $out['ringCount']++;
                    if (count($out['rings']) < 10) {
                        $out['rings'][] = self::item($r);
                    }
                }
            }
            $soft = Database::fetchAll(
                'SELECT * FROM alert_events WHERE ring = 0 AND created_at >= :c AND created_at <= :n ORDER BY id DESC LIMIT 40',
                ['c' => date('Y-m-d H:i:s', $now - 90), 'n' => date('Y-m-d H:i:s', $now + 5)]
            );
            foreach ($soft as $r) {
                if (count($out['soft']) < 5 && self::visibleTo($admin, $r, $matrix)) {
                    $out['soft'][] = self::item($r);
                }
            }
        } catch (Throwable $e) {
            self::warn('feed failed', $e);
        }
        return $out;
    }

    /** @return list<array<string, mixed>> rings nobody has answered yet, newest first */
    private static function openRings(int $now, int $onlyId = 0): array
    {
        return Database::fetchAll(
            'SELECT * FROM alert_events WHERE ring = 1 AND acked_at IS NULL AND ring_until > :n'
            . ($onlyId > 0 ? ' AND id = ' . $onlyId : '') . ' ORDER BY id DESC LIMIT 60',
            ['n' => date('Y-m-d H:i:s', $now)]
        );
    }

    /* =================================================================
     *  "Seen"
     * ================================================================= */

    /**
     * The service worker's Seen button has no session: this short signature is its proof.
     * It names ONE alert and ONE person. $all (a merged "N new alerts" notification) is part
     * of what is signed, so a single-alert token can never be turned into "mark everything seen".
     */
    public static function ackToken(int $alertId, int $adminId, bool $all = false): string
    {
        return substr(Security::sign('alertack|' . $alertId . '|' . $adminId . ($all ? '|all' : '')), 0, 32);
    }

    public static function checkAckToken(int $alertId, int $adminId, string $token, bool $all = false): bool
    {
        return $alertId > 0 && $adminId > 0 && strlen($token) === 32 && hash_equals(self::ackToken($alertId, $adminId, $all), $token);
    }

    /** Is this alert still ringing (nobody tapped Seen, repeats not used up)? */
    public static function isOpen(int $alertId, ?int $now = null): bool
    {
        try {
            return Database::exists('SELECT 1 FROM alert_events WHERE id = :i AND ring = 1 AND acked_at IS NULL AND ring_until > :n',
                ['i' => $alertId, 'n' => date('Y-m-d H:i:s', $now ?? time())]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Mark one alert seen by this person. Idempotent: the first Seen wins, later ones
     * are a quiet success. A viewer, or someone the alert is not for, is refused.
     *
     * Only a RINGING alert can be answered (a soft line stops nothing, so nothing is written).
     * The office's Seen stops the ring for everybody. An agent's - and a counter's on anything
     * but a new ticket - is personal ('personal' => true): acked_at stays empty, the office
     * still hears it, and the office bell row is left unread (answersForAll()).
     *
     * @return array{ok: bool, first: bool, why?: string, personal?: bool}
     */
    public static function ack(int $alertId, array $admin, string $via = 'panel', ?int $now = null): array
    {
        try {
            if ($alertId <= 0 || !self::ready()) {
                return ['ok' => false, 'first' => false, 'why' => 'gone'];
            }
            $row = Database::fetch('SELECT * FROM alert_events WHERE id = :i', ['i' => $alertId]);
            if ($row === null) {
                return ['ok' => false, 'first' => false, 'why' => 'gone'];
            }
            if (!self::visibleTo($admin, $row)) {
                return ['ok' => false, 'first' => false, 'why' => 'not yours'];
            }
            if ((int) ($row['ring'] ?? 0) !== 1) {
                return ['ok' => true, 'first' => false, 'why' => 'not ringing'];
            }
            $when = date('Y-m-d H:i:s', $now ?? time());
            if (!self::answersForAll($admin, $row)) {
                if ($row['acked_at'] !== null) {
                    return ['ok' => true, 'first' => false, 'personal' => true];
                }
                $mine = Database::insertIgnore('alert_deliveries', [
                    'alert_id' => $alertId, 'admin_id' => (int) $admin['id'], 'channel' => 'seen', 'attempt' => 0,
                    'ok' => 1, 'detail' => mb_substr($via, 0, 12), 'created_at' => $when,
                ]);
                return ['ok' => true, 'first' => $mine > 0, 'personal' => true];
            }
            $n = Database::run(
                'UPDATE alert_events SET acked_at = :t, acked_by = :a, ack_via = :v WHERE id = :i AND acked_at IS NULL',
                ['t' => $when, 'a' => (int) $admin['id'], 'v' => mb_substr($via, 0, 12), 'i' => $alertId]
            );
            if ($n > 0 && str_starts_with((string) $row['src_key'], 'bell:') && self::readsBell((string) ($admin['role'] ?? ''))) {
                try {
                    Database::run("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = :i AND audience = 'admin' AND is_read = 0",
                        ['i' => (int) substr((string) $row['src_key'], 5)]);
                } catch (Throwable $ignored) {
                }
            }
            return ['ok' => true, 'first' => $n > 0];
        } catch (Throwable $e) {
            self::warn('ack failed', $e);
            return ['ok' => false, 'first' => false, 'why' => 'error'];
        }
    }

    /**
     * Seen on a merged notification / the card's "Seen all": the open rings this person can see,
     * up to and including alert $upTo (the newest one the person was actually shown). 26 Nov 2026
     * (fx4 review): without the cap, tapping an old "N new alerts" notification minutes later also
     * silenced alerts that came AFTER it - for an office role, for the whole office. A driver SOS
     * (LOUD) is never silenced by "Seen all": only its own Seen stops it.
     */
    public static function ackAll(array $admin, string $via = 'panel', ?int $now = null, int $upTo = 0): int
    {
        $n = 0;
        try {
            if (!self::ready()) {
                return 0;
            }
            $matrix = self::matrix();
            foreach (self::openRings($now ?? time()) as $r) {
                $rid = (int) $r['id'];
                if ($upTo > 0 && $rid > $upTo) {
                    continue;
                }
                if (in_array((string) ($r['event'] ?? ''), self::LOUD, true) && $rid !== $upTo) {
                    continue;
                }
                if (self::visibleTo($admin, $r, $matrix) && self::ack($rid, $admin, $via, $now)['first']) {
                    $n++;
                }
            }
        } catch (Throwable $e) {
            self::warn('ackAll failed', $e);
        }
        return $n;
    }

    /** An active staff row by id (the token path has no session). */
    public static function adminById(int $id): ?array
    {
        try {
            return Database::fetch('SELECT id, role, full_name FROM admins WHERE id = :i AND is_active = 1', ['i' => $id]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /* =================================================================
     *  Phones: web push repeats (+ optional office WhatsApp)
     * ================================================================= */

    /*
     * WHICH PHONES RING. A handset rings only after its owner tapped "Ring on this phone":
     * one alert_push_optin row per handset (endpoint_hash), naming the person and carrying the
     * stamp of that person's password at that moment. A phone that only ever allowed Office
     * chat (push_subscriptions.admin_id) is never rung. The ring is released by:
     *   - "Stop ringing on this phone" (pushOptOut - Office chat push on that phone is kept)
     *   - the office removing the phone on admin/alert-settings.php (removeRingPhone)
     *   - a password change / office reset of that account (the stamp no longer matches);
     *     the person's own phone picks the ring up again the next time they open the panel on it
     *   - releasePhonesOf() - ready for the session-end / sign-out pages to call (not wired here)
     *   - the handset being re-bound to another person (Office chat door) or deactivated
     */

    private static function pwStampOf(int $adminId): string
    {
        return Auth::pwStamp((string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $adminId], ''));
    }

    /**
     * $ask = true: the person tapped "Ring on this phone" - this handset now rings for them.
     * $ask = false: the panel's quiet sync - only a handset that ALREADY rings is moved to
     * whoever is signed in now (a shared counter phone); any other handset is left alone.
     * Returns whether this handset now rings for this person. Never throws.
     */
    public static function pushOptIn(string $endpoint, int $adminId, bool $ask): bool
    {
        try {
            $endpoint = trim($endpoint);
            if ($endpoint === '' || $adminId <= 0) {
                return false;
            }
            $h = sha1($endpoint);
            if (!$ask && !Database::exists('SELECT 1 FROM alert_push_optin WHERE endpoint_hash = :h', ['h' => $h])) {
                return false;
            }
            Database::query(
                'INSERT INTO alert_push_optin (endpoint_hash, admin_id, pw, created_at) VALUES (:h, :a, :p, :t)
                 ON DUPLICATE KEY UPDATE created_at = IF(admin_id <> VALUES(admin_id), VALUES(created_at), created_at),
                                         admin_id = VALUES(admin_id), pw = VALUES(pw)',
                ['h' => $h, 'a' => $adminId, 'p' => self::pwStampOf($adminId), 't' => date('Y-m-d H:i:s')]
            );
            return true;
        } catch (Throwable $e) {
            self::warn('pushOptIn failed', $e);
            return false;
        }
    }

    /** "Stop ringing on this phone": only the ring flag goes - the subscription (Office chat push) is kept. */
    public static function pushOptOut(string $endpoint, int $adminId): bool
    {
        try {
            $endpoint = trim($endpoint);
            return $endpoint !== '' && $adminId > 0
                && Database::run('DELETE FROM alert_push_optin WHERE endpoint_hash = :h AND admin_id = :a', ['h' => sha1($endpoint), 'a' => $adminId]) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Has this handset been switched on for the ring ($adminId 0 = for anybody)? */
    public static function phoneRings(string $endpoint, int $adminId = 0): bool
    {
        try {
            $endpoint = trim($endpoint);
            if ($endpoint === '') {
                return false;
            }
            return $adminId > 0
                ? Database::exists('SELECT 1 FROM alert_push_optin WHERE endpoint_hash = :h AND admin_id = :a', ['h' => sha1($endpoint), 'a' => $adminId])
                : Database::exists('SELECT 1 FROM alert_push_optin WHERE endpoint_hash = :h', ['h' => sha1($endpoint)]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The phones that ring (the office's list on the settings page): who, which browser, since
     * when, and whether it still rings ('live' = false after that account's password changed
     * or it was deactivated). No endpoint, no key - 'key' is only the row's handle for Remove.
     *
     * @return list<array{key: string, admin_id: int, name: string, role: string, ua: string, since: string, live: bool}>
     */
    public static function ringPhones(int $limit = 100): array
    {
        try {
            $out = [];
            foreach (Database::fetchAll(
                'SELECT o.endpoint_hash, o.admin_id, o.pw, o.created_at, p.ua, a.full_name, a.role, a.is_active, a.password_hash
                   FROM alert_push_optin o
                   JOIN push_subscriptions p ON p.endpoint_hash = o.endpoint_hash AND p.admin_id = o.admin_id AND p.is_active = 1
                   LEFT JOIN admins a ON a.id = o.admin_id
                  ORDER BY o.created_at DESC LIMIT ' . max(1, min(300, $limit))
            ) as $r) {
                $out[] = [
                    'key'      => (string) $r['endpoint_hash'],
                    'admin_id' => (int) $r['admin_id'],
                    'name'     => (string) ($r['full_name'] ?? ''),
                    'role'     => (string) ($r['role'] ?? ''),
                    'ua'       => (string) ($r['ua'] ?? ''),
                    'since'    => (string) $r['created_at'],
                    'live'     => (int) ($r['is_active'] ?? 0) === 1 && hash_equals(Auth::pwStamp((string) ($r['password_hash'] ?? '')), (string) $r['pw']),
                ];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Every phone of one account off the ring. Not called from this file: it is the one-line
     * hook for "the office ended this person's sessions" (admin/sessions.php) and for an
     * explicit sign-out, when the owner wants those to stop the ring too. Returns how many.
     */
    public static function releasePhonesOf(int $adminId): int
    {
        try {
            return $adminId > 0 ? Database::run('DELETE FROM alert_push_optin WHERE admin_id = :a', ['a' => $adminId]) : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** The office takes a phone off the ring (a lost or handed-on handset). $key = ringPhones()[..]['key']. */
    public static function removeRingPhone(string $key): bool
    {
        try {
            return preg_match('/^[a-f0-9]{40}$/', $key) === 1
                && Database::run('DELETE FROM alert_push_optin WHERE endpoint_hash = :h', ['h' => $key]) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * This person's handsets that ring now (push_subscriptions rows): switched on for the ring,
     * still bound to them, active, and switched on with the password the account has today.
     *
     * @return list<array<string, mixed>>
     */
    public static function ringSubs(int $adminId): array
    {
        if ($adminId <= 0) {
            return [];
        }
        try {
            $out = [];
            foreach (Database::fetchAll(
                'SELECT p.*, o.pw AS ring_pw, a.password_hash AS ring_hash
                   FROM alert_push_optin o
                   JOIN push_subscriptions p ON p.endpoint_hash = o.endpoint_hash AND p.admin_id = o.admin_id AND p.is_active = 1
                   JOIN admins a ON a.id = o.admin_id AND a.is_active = 1
                  WHERE o.admin_id = :a ORDER BY p.id DESC LIMIT 10',
                ['a' => $adminId]
            ) as $r) {
                $live = hash_equals(Auth::pwStamp((string) $r['ring_hash']), (string) $r['ring_pw']);
                unset($r['ring_pw'], $r['ring_hash']);
                if ($live) {
                    $out[] = $r;
                }
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> active staff with at least one handset that rings (ringSubs) */
    private static function pushRecipients(): array
    {
        if (self::$recipients !== null) {
            return self::$recipients;
        }
        try {
            $out = [];
            foreach (Database::fetchAll(
                'SELECT a.id, a.role, a.full_name, a.password_hash, o.pw
                   FROM alert_push_optin o
                   JOIN push_subscriptions p ON p.endpoint_hash = o.endpoint_hash AND p.admin_id = o.admin_id AND p.is_active = 1
                   JOIN admins a ON a.id = o.admin_id AND a.is_active = 1
                  ORDER BY a.id LIMIT 600'
            ) as $r) {
                $id = (int) $r['id'];
                if (!isset($out[$id]) && hash_equals(Auth::pwStamp((string) $r['password_hash']), (string) $r['pw'])) {
                    $out[$id] = ['id' => $id, 'role' => (string) $r['role'], 'full_name' => (string) $r['full_name']];
                }
            }
            return array_values($out);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * One ring to one person: only to the handsets that were switched on for it (ringSubs) -
     * never to a phone that merely has Office chat push.
     *
     * @return array{sent:int, failed:int, skipped:int}
     */
    private static function pushSend(int $adminId, array $payload, string $event, int $ttl, string $topic): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        if (!WebPush::enabled()) {
            $out['skipped']++;
            return $out;
        }
        $payload['event'] = $event;
        foreach (self::ringSubs($adminId) as $sub) {
            $r = WebPush::send($sub, $payload, $ttl, 'high', $topic);
            !empty($r['ok']) ? $out['sent']++ : $out['failed']++;
        }
        return $out;
    }

    /** The push channel may send now (switches first - a claim is never taken while it is off). */
    private static function pushReady(): bool
    {
        if (!Settings::getBool('alert_push_on', true) || !Settings::getBool('push_enabled', true)) {
            return false;
        }
        if (self::$pusher !== null) {
            return true;
        }
        if (!class_exists('WebPush')) {
            if (!is_file(INCLUDE_PATH . '/webpush.php')) {
                return false;
            }
            require_once INCLUDE_PATH . '/webpush.php';
        }
        return WebPush::enabled();
    }

    /**
     * Which ring of this alert is running at $now: 0 = the first, 1.. = a repeat.
     * null once every repeat has been used. 'left' = seconds until this ring's slot ends.
     *
     * @return array{k:int, left:int}|null
     */
    public static function attempt(array $row, int $now, ?array $cfg = null): ?array
    {
        $cfg = $cfg ?? self::cfg();
        $el  = max(0, $now - (int) strtotime((string) $row['created_at']));
        $k   = intdiv($el, $cfg['repeat']);
        if ($k > $cfg['max']) {
            return null;
        }
        $end = $k < $cfg['max'] ? ($k + 1) * $cfg['repeat'] : $k * $cfg['repeat'] + $cfg['seconds'];
        if ($el >= $end) {
            return null;
        }
        return ['k' => $k, 'left' => $end - $el];
    }

    /** The JSON sw.js shows. Every ring field is optional there, so an old worker still shows a plain notification. */
    public static function pushPayload(array $row, int $adminId, int $merged = 1): array
    {
        $it   = self::item($row);
        $many = $merged > 1;
        return [
            'title'              => $many ? '🔔 ' . $merged . ' नयाँ सूचना · ' . $merged . ' new alerts' : $it['title'],
            'body'               => $many ? $it['title'] : trim(($it['channel'] !== '' ? $it['channel'] . ' · ' : '') . $it['body']),
            'url'                => $many ? '/admin/bookings.php' : ($it['link'] !== '' ? $it['link'] : '/admin/notifications.php'),
            'tag'                => $many ? 'shg-ring-many' : 'shg-ring-' . $it['id'],
            'lang'               => 'ne',
            'ring'               => 1,
            'requireInteraction' => true,
            'renotify'           => true,
            'vibrate'            => [400, 200, 400, 200, 400, 600, 400, 200, 400],
            'actions'            => [
                ['action' => 'seen', 'title' => 'देखेँ · Seen'],
                ['action' => 'open', 'title' => 'खोल्नुहोस् · Open'],
            ],
            'alertId'            => $it['id'],
            'adminId'            => $adminId,
            'ackUrl'             => '/api/alert-ack.php',
            'ackToken'           => self::ackToken($it['id'], $adminId, $many),
            'ackAll'             => $many ? 1 : 0,
        ];
    }

    /**
     * Send what is due: for every ring nobody answered, one push per person per ring.
     * Call it from the cron, or after the poll's answer has left - never inside a sale.
     *
     * @return array{push:int, failed:int, wa:int, open:int}
     */
    public static function deliver(int $maxSends = 20, ?int $now = null, int $onlyAlertId = 0): array
    {
        $out = ['push' => 0, 'failed' => 0, 'wa' => 0, 'open' => 0];
        if (!self::on()) {
            return $out;
        }
        $now = $now ?? time();
        try {
            $cfg    = self::cfg();
            $events = self::openRings($now, $onlyAlertId);
            $out['open'] = count($events);
            if ($events === []) {
                return $out;
            }
            if (self::pushReady()) {
                $matrix = self::matrix();
                $prefs  = self::rolePrefs();
                $sends  = 0;
                foreach (self::pushRecipients() as $who) {
                    $aid = (int) ($who['id'] ?? 0);
                    $vis = [];
                    $quiet = self::inQuiet((string) ($who['role'] ?? ''), $now, $prefs);
                    $done  = self::seenBy($aid, $events);      // a personal Seen: no more repeats for this person
                    foreach ($events as $e) {
                        if (isset($done[(int) $e['id']]) || ($quiet && !in_array((string) $e['event'], self::LOUD, true))) {
                            continue;
                        }
                        if (self::visibleTo($who, $e, $matrix)) {
                            $vis[] = $e;
                        }
                    }
                    $many = count($vis) > $cfg['burst'];
                    foreach ($vis as $i => $e) {
                        $att = self::attempt($e, $now, $cfg);
                        if ($att === null || ($many && $i > 0)) {
                            continue;                      // a burst sends ONE merged push ("N new alerts"), for its newest alert only
                        }
                        if ($sends >= $maxSends) {
                            break 2;
                        }
                        $claim = Database::insertIgnore('alert_deliveries', [
                            'alert_id' => (int) $e['id'], 'admin_id' => $aid, 'channel' => 'push', 'attempt' => $att['k'],
                            'ok' => 0, 'detail' => 'sending', 'created_at' => date('Y-m-d H:i:s', $now),
                        ]);
                        if ($claim <= 0) {
                            continue;                      // this ring already went to this person
                        }
                        $sends++;
                        $payload = self::pushPayload($e, $aid, $many ? count($vis) : 1);
                        $ttl     = max(60, min($cfg['repeat'], $att['left']));
                        $topic   = $many ? 'ringmany' : 'ring' . (int) $e['id'];
                        $evName  = 'alert_' . (str_starts_with((string) $e['src_key'], 'test:') ? 'test' : (string) $e['event']);
                        $res = self::$pusher !== null
                            ? (self::$pusher)($aid, $payload, $evName, $ttl, 'high', $topic)
                            : self::pushSend($aid, $payload, $evName, $ttl, $topic);
                        $sent = (int) ($res['sent'] ?? 0);
                        $sent > 0 ? $out['push']++ : $out['failed']++;
                        Database::update('alert_deliveries', [
                            'ok'     => $sent > 0 ? 1 : 0,
                            'detail' => 'sent ' . $sent . ', failed ' . (int) ($res['failed'] ?? 0) . ', skipped ' . (int) ($res['skipped'] ?? 0),
                        ], 'id = :i', ['i' => $claim]);
                    }
                }
            }
            $out['wa'] = self::deliverWhatsapp($events, $now);
        } catch (Throwable $e) {
            self::warn('deliver failed', $e);
        }
        return $out;
    }

    /**
     * After a panel poll's answer has left (php-fpm only): send what is due, at most once
     * every 5 seconds however many panels are open, so a push leaves within one poll even
     * before the every-minute cron is installed. The credit check rides along (own 10-min turn).
     */
    public static function afterPoll(?int $now = null): void
    {
        $now = $now ?? time();
        try {
            if (!self::on() || !self::turn('deliver_at', 5, $now)) {
                return;
            }
            self::deliver(6, $now);
            self::creditWatch($now);
        } catch (Throwable $e) {
            self::warn('afterPoll failed', $e);
        }
    }

    /**
     * Optional office WhatsApp (alert_wa_on, default OFF): one message per ringing alert,
     * through the existing Notify::adminNote (so whatsapp_notify_admin still rules), at
     * most 6 an hour. Not for a customer's own booking or a driver SOS - both already
     * send their own office WhatsApp.
     *
     * @param list<array<string, mixed>> $events
     */
    private static function deliverWhatsapp(array $events, int $now): int
    {
        if (!Settings::getBool('alert_wa_on', false)) {
            return 0;
        }
        if (self::$waSender === null) {
            if (!class_exists('Notify')) {
                require_once INCLUDE_PATH . '/notify.php';
            }
            if (!Notify::adminWhatsappReady()) {
                return 0;
            }
        }
        $n = 0;
        foreach ($events as $e) {
            if (in_array((string) $e['event'], ['new_booking', 'driver_sos'], true) || str_starts_with((string) $e['src_key'], 'test:')
                || $now - (int) strtotime((string) $e['created_at']) > 600) {
                continue;
            }
            if (Database::exists("SELECT 1 FROM alert_deliveries WHERE alert_id = :a AND admin_id = 0 AND channel = 'whatsapp'", ['a' => (int) $e['id']])) {
                continue;
            }
            /* claim first, then the hourly limit: a duplicate claim (two pollers at once) must not use up a slot */
            $claim = Database::insertIgnore('alert_deliveries', [
                'alert_id' => (int) $e['id'], 'admin_id' => 0, 'channel' => 'whatsapp', 'attempt' => 0,
                'ok' => 1, 'detail' => 'office number', 'created_at' => date('Y-m-d H:i:s', $now),
            ]);
            if ($claim <= 0) {
                continue;
            }
            if (!Security::rateLimit('alert_wa', 'office', 6, 3600)) {
                Database::run('DELETE FROM alert_deliveries WHERE id = :i', ['i' => $claim]);   // our own claim row, so a later pass may send it
                break;
            }
            $facts = array_values(array_filter([(string) $e['body']], static fn(string $s): bool => $s !== ''));
            $bid   = !empty($e['booking_id']) ? (int) $e['booking_id'] : null;
            self::$waSender !== null
                ? (self::$waSender)((string) $e['title'], $facts, $bid)
                : Notify::adminNote((string) $e['title'], $facts, $bid);
            $n++;
        }
        return $n;
    }

    /* =================================================================
     *  Test button, log, housekeeping
     * ================================================================= */

    /**
     * The super-admin's test button: one sample alert that only the person who pressed
     * it can see. $via: inapp (shows on the open panel within seconds), push (rings this
     * person's subscribed phones now), whatsapp (one line to the office number).
     *
     * @return array{ok: bool, detail: string, id?: int}
     */
    public static function test(string $event, string $via, array $admin, ?int $now = null): array
    {
        try {
            if (!isset(self::EVENTS[$event])) {
                return ['ok' => false, 'detail' => 'थाहा नभएको सूचना · Unknown alert.'];
            }
            if (!self::on()) {
                return ['ok' => false, 'detail' => 'सूचना प्रणाली बन्द छ वा यसको तालिका छैन · The alert bus is off (alert_bus_on) or its tables are missing - run database/upgrade-2026-11-26-fx1-alertbus.sql.'];
            }
            $now = $now ?? time();
            $def = self::EVENTS[$event];
            $aid = (int) ($admin['id'] ?? 0);
            $title = $def[2] . ' [TEST] ' . $def[0] . ' · ' . $def[1];
            if ($via === 'whatsapp') {
                if (self::$waSender === null) {
                    if (!class_exists('Notify')) {
                        require_once INCLUDE_PATH . '/notify.php';
                    }
                    if (!Notify::adminWhatsappReady()) {
                        return ['ok' => false, 'detail' => 'अफिस WhatsApp नम्बर राखिएको छैन वा बन्द छ · Office WhatsApp is not set (admin_whatsapp) or whatsapp_notify_admin is off.'];
                    }
                    Notify::adminNote($title, ['यो नमुना मात्र हो · This is only a test.']);
                } else {
                    (self::$waSender)($title, ['test'], null);
                }
                return ['ok' => true, 'detail' => 'अफिस नम्बरमा WhatsApp पठाइयो, त्यो फोन हेर्नुहोस् · Handed to WhatsApp for the office number. Check that phone.'];
            }
            $id = self::raise($event, [
                'title' => $title,
                'body'  => 'यो नमुना मात्र हो, कुनै टिकट बनेको छैन · Sample only, no ticket was made.',
                'link'  => '/admin/alert-settings.php',
                'actor' => $aid,
                'ring'  => $via === 'push' ? true : self::matrix()[$event]['ring'],
            ], 'test:' . $aid . ':' . bin2hex(random_bytes(6)), $now);
            if ($id <= 0) {
                return ['ok' => false, 'detail' => 'नमुना सूचना राख्न सकिएन · Could not store the test alert.'];
            }
            if ($via === 'push') {
                if (!self::pushReady()) {
                    return ['ok' => false, 'id' => $id, 'detail' => 'फोन घण्टी (push) बन्द छ वा यो सर्भरमा छैन · Push is off (alert_push_on / push_enabled) or not available on this server.'];
                }
                $prev = self::$recipients;
                self::$recipients = [['id' => $aid, 'role' => (string) ($admin['role'] ?? ''), 'full_name' => (string) ($admin['full_name'] ?? '')]];
                try {
                    $r = self::deliver(3, $now, $id);
                } finally {
                    self::$recipients = $prev;
                }
                return $r['push'] > 0
                    ? ['ok' => true, 'id' => $id, 'detail' => 'तपाईंको फोनमा पठाइयो, सूचनामा "देखेँ" थिच्नुहोस् · Sent to your subscribed phone(s). Tap Seen on the notification.']
                    : ['ok' => false, 'id' => $id, 'detail' => 'तपाईंको कुनै फोनमा घण्टी खुलेको छैन, पहिले त्यो फोनमा "यो फोनमा घण्टी बजाउनुहोस्" थिच्नुहोस् · No phone of yours rings yet - tap "Ring on this phone" on that phone first (or the push service refused).'];
            }
            return ['ok' => true, 'id' => $id, 'detail' => 'नमुना सूचना राखियो, केही सेकेन्डमा खुला प्यानलमा देखिन्छ · Test alert stored - it shows on your open panel within a few seconds.'];
        } catch (Throwable $e) {
            self::warn('test failed', $e);
            return ['ok' => false, 'detail' => 'नमुना चलाउन सकिएन (सर्भर त्रुटि) · The test could not run (server error).'];
        }
    }

    /**
     * The newest alerts with who tapped Seen and how many pushes went out (settings page log).
     *
     * $viewer (id + role) = show only what that person may be told about - the same role
     * matrix and agent / desk scope as the feed. Without it the whole log comes back: only
     * for someone who may edit the matrix anyway.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 30, ?array $viewer = null): array
    {
        try {
            if (!self::ready()) {
                return [];
            }
            $limit = max(1, min(200, $limit));
            $rows  = Database::fetchAll(
                'SELECT e.id, e.event, e.src_key, e.actor_admin_id, e.desk_code, e.title, e.body, e.link, e.ring, e.created_at, e.acked_at, e.ack_via,
                        a.full_name AS acked_name,
                        (SELECT COUNT(*) FROM alert_deliveries d WHERE d.alert_id = e.id AND d.channel = \'push\' AND d.ok = 1) AS pushes,
                        (SELECT COUNT(*) FROM alert_deliveries d2 WHERE d2.alert_id = e.id AND d2.channel = \'whatsapp\') AS whatsapps
                   FROM alert_events e LEFT JOIN admins a ON a.id = e.acked_by
                  ORDER BY e.id DESC LIMIT ' . ($viewer === null ? $limit : min(600, $limit * 6))
            );
            if ($viewer === null) {
                return $rows;
            }
            $matrix = self::matrix();
            $out    = [];
            foreach ($rows as $r) {
                if (count($out) < $limit && self::visibleTo($viewer, $r, $matrix, false)) {
                    $out[] = $r;
                }
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Drop this feature's own rows older than $days (bounded; the cron calls it). */
    public static function prune(int $days = 30): int
    {
        $n = 0;
        try {
            if (!self::ready()) {
                return 0;
            }
            $cut = date('Y-m-d H:i:s', time() - max(7, $days) * 86400);
            $n += Database::run('DELETE FROM alert_deliveries WHERE created_at < :c LIMIT 2000', ['c' => $cut]);
            $n += Database::run('DELETE FROM alert_events WHERE created_at < :c LIMIT 2000', ['c' => $cut]);
        } catch (Throwable $e) {
            self::warn('prune failed', $e);
        }
        return $n;
    }

    private static function warn(string $what, Throwable $e, array $ctx = []): void
    {
        try {
            Logger::warning('AlertBus: ' . $what, $ctx + ['error' => $e->getMessage()]);
        } catch (Throwable $ignored) {
        }
    }
}
