<?php
/**
 * includes/adminalerts.php — the panel's notification centre and its alert
 * rules (5 Oct 2026, owner's Point 9).
 *
 * The `notifications` table has been written since August (every new booking
 * and cancellation) but nothing ever read it. This class is the reader - the
 * bell in the top bar, admin/notifications.php - and the writer for the money
 * and activity alerts the owner asked for:
 *
 *   cash_holding    an agent holds more ticket cash than ₹X for more than N days
 *   settlement_due  an agent's commission has been unpaid for more than N days
 *   advance_limit   an agent's outstanding advance is above ₹X
 *   low_bookings    yesterday had fewer bookings than N
 *   cancel_spike    today's cancellations are above N % of the day's bookings
 *   agent_inactive  active agents who have sold nothing for N days (or never
 *                   sold, once the account is N days old — 15 Oct 2026)
 *   online_booking  a customer booked on the website with no agent in between
 *
 * Every rule has an on/off switch and its own numbers (settings rows
 * alert_<rule>_on / alert_<rule>_<param>, edited on the notifications page).
 * An alert is a READ of the ledger and the bookings - it never changes money.
 * Each one is stored once per agent per week (or per day for the day rules),
 * claimed through EventBus so a cron that runs every 30 minutes cannot repeat it.
 * WhatsApp to the admin is a separate switch (alert_whatsapp_on, off by default).
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AdminAlerts
{
    /** rule => [label, what it watches, default on, [param => [label, default]]] */
    public const RULES = [
        'cash_holding'   => ['Ticket cash held too long', 'An agent, a counter or the office account holds more ticket cash than the amount, for longer than the days.', true,
                             ['amount' => ['More than ₹', 50000], 'days' => ['for more than (days)', 3]]],
        'settlement_due' => ['Commission unpaid', 'An agent has been owed commission for longer than this.', true,
                             ['days' => ['Unpaid for more than (days)', 15]]],
        'advance_limit'  => ['Advance above the limit', 'An agent\'s outstanding advance / loan is above this amount.', true,
                             ['limit' => ['Outstanding above ₹', 25000]]],
        'low_bookings'   => ['Low bookings', 'Yesterday had fewer bookings than this.', true,
                             ['min' => ['Fewer than (bookings in the day)', 5]]],
        'cancel_spike'   => ['Cancellation spike', 'Today\'s cancellations pass this share of the day\'s bookings.', true,
                             ['pct' => ['More than (%)', 20], 'min' => ['only when the day has at least (bookings)', 5]]],
        'agent_inactive' => ['Agent inactive', 'An active agent has sold nothing for this long — or has never sold since joining that long ago.', true,
                             ['days' => ['No sale for more than (days)', 7]]],
        'online_booking' => ['New online customer 🎉', 'A customer booked on the website by themselves, no agent in between.', true, []],
        /* 29 Oct 2026 (management upgrade 3.4). */
        'not_boarded'    => ['Passengers not boarded', 'A bus left this many minutes ago and confirmed passengers are still not marked boarded. Only on trips where boarding is being marked (at least one passenger scanned), so a coach nobody scans stays quiet.', true,
                             ['minutes' => ['Minutes after departure', 20]]],
        'trip_late'      => ['Bus running late', 'A departure today has a delay of more than this many minutes. If you also set the second number, its passengers are told on WhatsApp once the delay reaches it (once per 30 minutes of delay; 0 = never, the default).', true,
                             ['minutes' => ['Delay above (minutes)', 30], 'auto' => ['WhatsApp passengers at (minutes, 0 = never)', 0]]],
    ];

    /**
     * The saved switches and numbers, defaults filled in.
     *
     * @return array<string, array<string, bool|int>>
     */
    public static function prefs(): array
    {
        $out = [];
        foreach (self::RULES as $rule => [, , $defOn, $params]) {
            $out[$rule] = ['on' => Settings::getBool('alert_' . $rule . '_on', $defOn)];
            foreach ($params as $p => [, $def]) {
                $out[$rule][$p] = max(0, Settings::getInt('alert_' . $rule . '_' . $p, (int) $def));
            }
        }
        return $out;
    }

    /** Save the preferences form (rule => [on, param…]). Returns the number of values changed. */
    public static function savePrefs(array $in, bool $whatsapp): int
    {
        $changed = 0;
        $old     = self::prefs();
        foreach (self::RULES as $rule => [, , , $params]) {
            $on = !empty($in[$rule]['on']);
            if ($on !== $old[$rule]['on']) {
                Settings::set('alert_' . $rule . '_on', $on, 'bool', 'alerts');
                $changed++;
            }
            foreach ($params as $p => [, $def]) {
                $v = max(0, min(100000000, (int) ($in[$rule][$p] ?? $def)));
                if ($v !== $old[$rule][$p]) {
                    Settings::set('alert_' . $rule . '_' . $p, $v, 'int', 'alerts');
                    $changed++;
                }
            }
        }
        if ($whatsapp !== Settings::getBool('alert_whatsapp_on', false)) {
            Settings::set('alert_whatsapp_on', $whatsapp, 'bool', 'alerts');
            $changed++;
        }
        Settings::flush();
        return $changed;
    }

    /**
     * Store one notification for the office. With a $dedupeKey the alert is
     * stored only the first time that key is seen. Returns the new row id, or
     * 0 when it was a repeat (or could not be stored) - never throws.
     */
    public static function push(string $type, string $title, string $body, string $link = '', string $icon = '🔔', ?string $dedupeKey = null): int
    {
        try {
            if ($dedupeKey !== null && $dedupeKey !== '' && !EventBus::claim('alert:' . $dedupeKey, 'admin_alert')) {
                return 0;
            }
            /* Only a link into the panel is kept - an alert is never a way off the site. */
            if (!preg_match('~^/admin/[A-Za-z0-9_.\-/]+(\?[A-Za-z0-9_.\-=&%+]*)?$~', $link)) {
                $link = '';
            }
            return Database::insert('notifications', [
                'audience' => 'admin',
                'icon'     => mb_substr($icon, 0, 4),
                'title'    => Security::clean($title, 191),
                'body'     => Security::clean($body, 500),
                'link'     => $link !== '' ? $link : null,
            ]);
        } catch (Throwable $e) {
            try {
                Logger::warning('AdminAlerts::push failed', ['type' => $type, 'error' => $e->getMessage()]);
            } catch (Throwable $ignored) {
            }
            return 0;
        }
    }

    public static function unreadCount(): int
    {
        try {
            return (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE audience = 'admin' AND is_read = 0", [], 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @return list<array<string, mixed>> newest first */
    public static function recent(int $limit = 100, bool $unreadOnly = false): array
    {
        try {
            return Database::fetchAll(
                "SELECT n.id, n.icon, n.title, n.body, n.link, n.is_read, n.created_at, b.pnr
                   FROM notifications n LEFT JOIN bookings b ON b.id = n.booking_id
                  WHERE n.audience = 'admin'" . ($unreadOnly ? ' AND n.is_read = 0' : '') . '
                  ORDER BY n.id DESC LIMIT ' . max(1, min(500, $limit))
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function markRead(int $id): void
    {
        Database::run("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = :i AND audience = 'admin' AND is_read = 0", ['i' => $id]);
    }

    public static function markAllRead(): int
    {
        return Database::run("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE audience = 'admin' AND is_read = 0");
    }

    /** Called right after a self-booked website sale (api/book.php). Never throws. */
    public static function onlineBooking(array $booking): void
    {
        try {
            if (!Settings::getBool('alert_online_booking_on', true)) {
                return;
            }
            $pnr = (string) ($booking['pnr'] ?? '');
            if ($pnr === '') {
                return;
            }
            self::push('online_booking', '🎉 New online customer · ' . $pnr,
                'Booked on the website by the customer — no agent. ' . inr((float) ($booking['total_amount'] ?? $booking['total'] ?? 0)),
                '/admin/booking-view.php?pnr=' . rawurlencode($pnr), '🎉', 'online:' . $pnr);
        } catch (Throwable $e) {
        }
    }

    /**
     * Read the books and say what needs the owner. Returns the alerts found;
     * with $store they are also written to the notification centre (once each).
     *
     * @param  array<string, array<string, mixed>>|null $prefs null = the saved preferences
     * @return list<array{type: string, key: string, title: string, body: string, link: string, icon: string, new: bool}>
     */
    public static function evaluate(?array $prefs = null, bool $store = true): array
    {
        $prefs ??= self::prefs();
        $on    = static fn(string $rule): bool => !empty($prefs[$rule]['on']);
        $num   = static fn(string $rule, string $p): int => (int) ($prefs[$rule][$p] ?? (self::RULES[$rule][3][$p][1] ?? 0));
        $today = todayISO();
        $week  = date('o-\WW');
        $found = [];
        $add   = static function (string $type, string $key, string $title, string $body, string $link, string $icon) use (&$found): void {
            $found[] = ['type' => $type, 'key' => $key, 'title' => $title, 'body' => $body, 'link' => $link, 'icon' => $icon, 'new' => false];
        };

        /* --- agents: commission owed and ticket cash held, from the ledger --- */
        if ($on('cash_holding') || $on('settlement_due')) {
            require_once INCLUDE_PATH . '/agentsettlement.php';
            foreach (AgentSettlement::pending() as $p) {
                $who = $p['name'] . ($p['code'] !== '' ? ' (' . $p['code'] . ')' : '');
                if ($on('settlement_due') && $p['commission'] > 0.009 && $p['days'] > $num('settlement_due', 'days') && $p['open'] === null) {
                    $add('settlement_due', 'settle:' . $p['id'] . ':' . $week, 'Commission unpaid ' . $p['days'] . ' days · ' . $who,
                        inr($p['commission']) . ' is owed' . ($p['lastPaid'] !== '' ? ', last paid ' . formatDate($p['lastPaid']) : ', never paid yet') . '. Open a settlement.',
                        '/admin/settlements.php?new=' . $p['id'], '⏰');
                }
                if ($on('cash_holding') && $p['cash'] > $num('cash_holding', 'amount') && $p['cashDays'] > $num('cash_holding', 'days')) {
                    $isAgent = $p['role'] === 'agent';
                    $add('cash_holding', 'cash:' . $p['id'] . ':' . $week, 'Holding ' . inr($p['cash']) . ' ticket cash · ' . $who . ($isAgent ? '' : ' [' . $p['role'] . ']'),
                        'No cash handed over for ' . $p['cashDays'] . ' days. ' . ($isAgent ? 'Collect it or net it off in a settlement.' : 'Record the handover so the books show what is really outstanding.'),
                        $isAgent ? '/admin/agent-360.php?agent=' . $p['id'] : '/admin/settlements.php?tab=pending', '💰');
                }
            }
        }

        /* --- advances outstanding --- */
        if ($on('advance_limit')) {
            try {
                foreach (Database::fetchAll(
                    "SELECT l.agent_admin_id AS id, a.full_name, a.username, SUM(l.principal - l.recovered) AS outstanding
                       FROM agent_loans l JOIN admins a ON a.id = l.agent_admin_id
                      WHERE l.status = 'open'
                      GROUP BY l.agent_admin_id, a.full_name, a.username
                     HAVING outstanding > :lim",
                    ['lim' => $num('advance_limit', 'limit')]
                ) as $r) {
                    $add('advance_limit', 'adv:' . (int) $r['id'] . ':' . date('Y-m'), 'Advance ' . inr((float) $r['outstanding']) . ' outstanding · ' . ($r['full_name'] ?: $r['username']),
                        'Above the ' . inr((float) $num('advance_limit', 'limit')) . ' limit. Recover it from the next settlement.',
                        '/admin/agent-360.php?agent=' . (int) $r['id'], '🏦');
                }
            } catch (Throwable $e) {
            }
        }

        /* --- the day's bookings --- */
        try {
            if ($on('low_bookings')) {
                $yday = addDaysISO($today, -1);
                $n    = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE created_at >= :a AND created_at < :b', ['a' => $yday, 'b' => $today], 0);
                if ($n < $num('low_bookings', 'min')) {
                    $add('low_bookings', 'low:' . $yday, 'Only ' . $n . ' booking' . ($n === 1 ? '' : 's') . ' on ' . formatDate($yday, 'j M'),
                        'Below the ' . $num('low_bookings', 'min') . ' a day you set. Check the seat fill and remind the agents.',
                        '/admin/analytics.php?range=yesterday', '📉');
                }
            }
            if ($on('cancel_spike')) {
                $n = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE created_at >= :a', ['a' => $today], 0);
                $c = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE status = 'cancelled' AND cancelled_at >= :a", ['a' => $today], 0);
                if ($n >= max(1, $num('cancel_spike', 'min')) && $c * 100 > $num('cancel_spike', 'pct') * $n) {
                    $add('cancel_spike', 'cancel:' . $today, 'Cancellations today: ' . $c . ' against ' . $n . ' bookings (' . (int) round($c * 100 / $n) . '%)',
                        'Above the ' . $num('cancel_spike', 'pct') . '% you set. See who cancelled and why.',
                        '/admin/bookings.php?status=cancelled&from=' . $today . '&to=' . $today, '🚫');
                }
            }
            if ($on('agent_inactive')) {
                /* 15 Oct 2026: LEFT JOIN, so an active agent who has NEVER sold
                   is caught too (the plain JOIN only saw agents with a sale).
                   A brand-new account gets the same grace — it must be older
                   than the inactivity days before it counts. */
                $cut  = addDaysISO($today, -$num('agent_inactive', 'days')) . ' 00:00:00';
                $idle = Database::fetchAll(
                    "SELECT a.id, a.full_name, a.username, MAX(b.created_at) AS last_sale
                       FROM admins a LEFT JOIN bookings b ON b.sold_by_admin_id = a.id
                      WHERE a.role = 'agent' AND a.is_active = 1 AND a.created_at < :cut1
                      GROUP BY a.id, a.full_name, a.username
                     HAVING last_sale IS NULL OR last_sale < :cut2
                      ORDER BY last_sale",
                    ['cut1' => $cut, 'cut2' => $cut]
                );
                if ($idle !== []) {
                    $names = array_map(static fn(array $r): string => (string) ($r['full_name'] ?: $r['username']) . ($r['last_sale'] === null ? ' (never sold)' : ''), array_slice($idle, 0, 6));
                    $add('agent_inactive', 'idle:' . $week, count($idle) . ' agent' . (count($idle) === 1 ? ' has' : 's have') . ' not sold for ' . $num('agent_inactive', 'days') . '+ days',
                        implode(', ', $names) . (count($idle) > 6 ? ' …' : '') . '. A call or an offer may bring them back.',
                        '/admin/agents.php', '😴');
                }
            }
        } catch (Throwable $e) {
            try {
                Logger::warning('AdminAlerts day rules failed', ['error' => $e->getMessage()]);
            } catch (Throwable $ignored) {
            }
        }

        /* --- 29 Oct 2026 (management upgrade 3.4): boarding and delays --- */
        try {
            if ($on('not_boarded')) {
                $mins = max(5, $num('not_boarded', 'minutes'));
                foreach (Database::fetchAll(
                    "SELECT s.id, s.travel_date, r.from_city, r.to_city, COALESCE(s.dep_time_override, r.dep_time) AS dep,
                            SUM(bp.boarded_at IS NULL) AS missing, SUM(bp.boarded_at IS NOT NULL) AS boarded,
                            GROUP_CONCAT(CASE WHEN bp.boarded_at IS NULL THEN bp.full_name END ORDER BY bp.seat_no SEPARATOR ', ') AS names
                       FROM schedules s
                       JOIN routes r ON r.id = s.route_id
                       JOIN booking_legs bl ON bl.schedule_id = s.id
                       JOIN bookings b ON b.id = bl.booking_id AND b.status = 'confirmed'
                       JOIN booking_passengers bp ON bp.booking_id = b.id AND bp.leg_id = bl.id
                      WHERE s.status <> 'cancelled' AND s.travel_date BETWEEN :d0 AND :d1
                        AND TIMESTAMP(s.travel_date, COALESCE(s.dep_time_override, r.dep_time)) + INTERVAL :m MINUTE <= NOW()
                        AND TIMESTAMP(s.travel_date, COALESCE(s.dep_time_override, r.dep_time)) + INTERVAL 6 HOUR >= NOW()
                      GROUP BY s.id, s.travel_date, r.from_city, r.to_city, dep
                     HAVING missing > 0 AND boarded > 0",
                    ['d0' => addDaysISO($today, -1), 'd1' => $today, 'm' => $mins]
                ) as $r) {
                    $miss  = (int) $r['missing'];
                    $names = explode(', ', (string) $r['names']);
                    $add('not_boarded', 'board:' . (int) $r['id'], $miss . ' passenger' . ($miss === 1 ? '' : 's') . ' not boarded · ' . $r['from_city'] . ' → ' . $r['to_city'] . ' ' . substr((string) $r['dep'], 0, 5),
                        implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? ' …' : '') . ' — ' . (int) $r['boarded'] . ' boarded. Call them or mark them on the manifest.',
                        '/admin/manifest.php?sid=' . (int) $r['id'] . '&date=' . $r['travel_date'], '🧍');
                }
            }
            if ($on('trip_late')) {
                $mins   = max(5, $num('trip_late', 'minutes'));
                $autoAt = max(0, $num('trip_late', 'auto'));
                foreach (Database::fetchAll(
                    "SELECT s.id, s.travel_date, s.status, s.delay_minutes, s.delay_note, r.from_city, r.to_city,
                            COALESCE(s.dep_time_override, r.dep_time) AS dep
                       FROM schedules s JOIN routes r ON r.id = s.route_id
                      WHERE s.travel_date = :d AND s.status = 'scheduled' AND s.delay_minutes > :m",
                    ['d' => $today, 'm' => $mins]
                ) as $r) {
                    $dm = (int) $r['delay_minutes'];
                    // One alert per 30-minute step, so a delay that grows is reported again.
                    $add('trip_late', 'late:' . (int) $r['id'] . ':' . intdiv($dm, 30), 'Running ' . $dm . ' min late · ' . $r['from_city'] . ' → ' . $r['to_city'] . ' ' . substr((string) $r['dep'], 0, 5),
                        ((string) ($r['delay_note'] ?? '') !== '' ? (string) $r['delay_note'] . '. ' : '') . ($autoAt > 0 && $dm >= $autoAt ? 'Passengers are told on WhatsApp automatically.' : 'Tell the passengers from the trips board.'),
                        '/admin/trips.php?date=' . $r['travel_date'], '⏱️');
                    /* Opt-in (the rule's second number, 0 = off): tell the
                       passengers once per 30-minute step of delay, through the
                       same TripNotify::notifyDelay the trips board's button uses.
                       Only from the cron ($store), never from a page view. */
                    if ($store && $autoAt > 0 && $dm >= $autoAt
                        && EventBus::claim('delaynotify:' . (int) $r['id'] . ':' . intdiv($dm, 30), 'delay_auto_notify')) {
                        try {
                            require_once INCLUDE_PATH . '/tripnotify.php';
                            TripNotify::notifyDelay((int) $r['id'], $dm, (string) ($r['delay_note'] ?? ''), 0);
                        } catch (Throwable $e) {
                            Logger::warning('auto delay notice failed', ['schedule' => (int) $r['id'], 'error' => $e->getMessage()]);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            try {
                Logger::warning('AdminAlerts trip rules failed', ['error' => $e->getMessage()]);
            } catch (Throwable $ignored) {
            }
        }

        if ($store) {
            foreach ($found as $i => $a) {
                $found[$i]['new'] = self::push($a['type'], $a['title'], $a['body'], $a['link'], $a['icon'], $a['key']) > 0;
            }
        }
        return $found;
    }

    /**
     * The cron entry: evaluate, store, and - only when the owner switched it on -
     * send ONE WhatsApp with the alerts that are new in this run.
     *
     * @return array{found: int, new: int, whatsapp: string}
     */
    public static function run(): array
    {
        $found = self::evaluate(null, true);
        $new   = array_values(array_filter($found, static fn(array $a): bool => $a['new']));
        $wa    = 'off';
        if ($new !== [] && Settings::getBool('alert_whatsapp_on', false)) {
            $wa = 'skipped';
            try {
                require_once INCLUDE_PATH . '/notify.php';
                if (Notify::adminWhatsappReady()) {
                    $facts = [];
                    foreach (array_slice($new, 0, 8) as $i => $a) {
                        $facts[($i + 1) . '. ' . $a['title']] = $a['body'];
                    }
                    Notify::adminNote('🔔 ' . count($new) . ' alert' . (count($new) === 1 ? '' : 's') . ' · ' . Settings::getString('company_name', 'S Hari Global'), $facts);
                    $wa = 'sent';
                }
            } catch (Throwable $e) {
                $wa = 'failed';
            }
        }
        return ['found' => count($found), 'new' => count($new), 'whatsapp' => $wa];
    }
}
