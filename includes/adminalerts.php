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
 * 15 Nov 2026 (fleet watch, fleetWatch()): the coach on the road -
 *   driver_sos      a driver's SOS / breakdown still not marked handled N minutes
 *                   after the driver app rang it (TripEvents::alertOffice) - top of the bell
 *   gps_silent      a running trip whose newest GPS fix is older than N minutes
 *   overspeed       3+ fixes above N km/h in the last 15 minutes
 *   route_deviation the newest fix is more than N km from the route's road line
 *
 * Every rule has an on/off switch and its own numbers (settings rows
 * alert_<rule>_on / alert_<rule>_<param>, edited on the notifications page;
 * a rule may name its own setting key per number in RULES[rule][4]).
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
        'task_due'       => ['Office task due', 'An office task (Office work page) is due today or overdue — one bell a day per task.', true, []],
        'agent_low_target' => ['Agent behind target', 'From the 15th of the month, active agents whose sales are below this share of their monthly target (their own, else the company target) — one list a week.', true,
                             ['pct' => ['Below (% of target)', 50]]],
        'challan_stale'  => ['Challan out of date', 'A trip today or tomorrow gained or lost a ticket after its challan picture was drawn — reprint and resend it to the driver.', true, []],
        /* 15 Nov 2026 (fleet watch). The fifth element names the setting each number lives in. */
        'driver_sos'     => ['Driver SOS / breakdown still open 🆘', 'The driver app rings the bell (and the owner\'s WhatsApp) the moment SOS or breakdown is pressed. This rings it once more, on top of the bell, when nobody has marked it handled after this many minutes.', true,
                             ['min' => ['Still open after (minutes)', 15]]],
        'gps_silent'     => ['Bus GPS silent', 'A running trip (the bus should have left) whose newest GPS position is older than this. The phone may be off, out of signal or the app closed.', true,
                             ['min' => ['Silent for more than (minutes)', 10]], ['min' => 'gps_silent_alert_min']],
        'overspeed'      => ['Over-speed', 'A running coach sent 3 or more positions above this speed, spread over at least a minute, in the last 15 minutes (impossible GPS jumps are ignored).', true,
                             ['kmh' => ['Above (km/h)', 90]], ['kmh' => 'overspeed_kmh']],
        'route_deviation' => ['Bus off its route', 'A running coach\'s newest position is further than this from the route\'s road line. Routes without a road line are skipped.', true,
                             ['km' => ['More than (km)', 3]], ['km' => 'route_deviation_km']],
    ];

    /** The driver app's SOS / breakdown / delay rows (includes/tripevents.php, TripEvents::TABLE). Tests point it at a fixture. */
    public static string $eventsTable = 'driver_events';

    /** A press of the same kind on the same trip this soon after another is that emergency again (= TripEvents::ALERT_REPEAT_SEC). */
    private const SOS_REPEAT_SEC = 300;

    /** @var array<string, bool> table => has the incident columns */
    private static array $eventsReady = [];

    /** The setting row behind one number of one rule. */
    private static function paramKey(string $rule, string $p): string
    {
        return (string) (self::RULES[$rule][4][$p] ?? 'alert_' . $rule . '_' . $p);
    }

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
                $out[$rule][$p] = max(0, Settings::getInt(self::paramKey($rule, $p), (int) $def));
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
                    Settings::set(self::paramKey($rule, $p), $v, 'int', 'alerts');
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
            $id = Database::insert('notifications', [
                'audience' => 'admin',
                'icon'     => mb_substr($icon, 0, 4),
                'title'    => Security::clean($title, 191),
                'body'     => Security::clean($body, 500),
                'link'     => $link !== '' ? $link : null,
            ]);
            /* 26 Nov 2026 (fx1): the call-style ring. A cancel / date-change request, a driver SOS
               and a late bus are handed to the alert bus, which decides who hears it (role matrix)
               and rings until someone taps Seen. Its own try/catch: the bell row is already stored
               and the id below is what every caller relies on. */
            if ($id > 0) {
                try {
                    if (!class_exists('AlertBus') && is_file(INCLUDE_PATH . '/alertbus.php')) {
                        require_once INCLUDE_PATH . '/alertbus.php';
                    }
                    if (class_exists('AlertBus')) {
                        AlertBus::fromBell($type, $id, $title, $body, $link, $icon);
                    }
                } catch (Throwable $ignored) {
                }
            }
            return $id;
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
     * @param  int|null $now  the fleet-watch clock (tests); the day rules keep today's date
     * @return list<array{type: string, key: string, title: string, body: string, link: string, icon: string, new: bool}>
     */
    public static function evaluate(?array $prefs = null, bool $store = true, ?int $now = null): array
    {
        $prefs ??= self::prefs();
        $on    = static fn(string $rule): bool => !empty($prefs[$rule]['on']);
        $num   = static fn(string $rule, string $p): int => (int) ($prefs[$rule][$p] ?? (self::RULES[$rule][3][$p][1] ?? 0));
        $today = todayISO();
        $week  = date('o-\WW');
        /* 15 Nov 2026: the coach on the road. A driver's SOS / breakdown leads
           the list (the WhatsApp digest takes the first eight); the GPS rules
           join at the end. */
        $watch = self::fleetWatch($prefs, $now);
        $found = array_values(array_filter($watch, static fn(array $a): bool => $a['type'] === 'driver_sos'));
        $watch = array_values(array_filter($watch, static fn(array $a): bool => $a['type'] !== 'driver_sos'));
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

        /* --- 29 Oct 2026 (management upgrade 3.5): office tasks due --- */
        if ($on('task_due') && is_file(INCLUDE_PATH . '/officetasks.php')) {
            require_once INCLUDE_PATH . '/officetasks.php';
            foreach (OfficeTasks::due($today) as $t) {
                $late = (string) $t['due_date'] < $today;
                $add('task_due', 'task:' . (int) $t['id'] . ':' . $today, ($late ? 'Overdue task · ' : 'Task due today · ') . ($t['assignee_name'] ?: 'office') . ': ' . $t['title'],
                    ($late ? 'Was due ' . formatDate((string) $t['due_date'], 'j M') . '. ' : '') . ($t['priority'] === 'high' ? 'High priority. ' : '') . 'Mark it done on the Office work page when finished.',
                    '/admin/office-ops.php', '📝');
            }
        }

        /* --- 29 Oct 2026 (management upgrade 4.5): agents behind target --- */
        if ($on('agent_low_target') && (int) date('j') >= 15 && is_file(INCLUDE_PATH . '/agenttargets.php')) {
            require_once INCLUDE_PATH . '/agenttargets.php';
            try {
                $ym  = date('Y-m');
                $pct = max(1, min(100, $num('agent_low_target', 'pct')));
                $behind = [];
                foreach (AgentTargets::salesBoard($ym) as $agentId => $money) {
                    $t = AgentTargets::targetFor((int) $agentId, $ym);
                    if ($t['amount'] > 0 && $money < $t['amount'] * $pct / 100) {
                        $nm = (string) Database::scalar('SELECT COALESCE(NULLIF(full_name, \'\'), username) FROM admins WHERE id = :i', ['i' => (int) $agentId], '#' . $agentId);
                        $behind[] = $nm . ' ' . (int) floor($money * 100 / $t['amount']) . '%';
                    }
                }
                if ($behind !== []) {
                    $add('agent_low_target', 'lowtgt:' . $week, count($behind) . ' agent' . (count($behind) === 1 ? ' is' : 's are') . ' under ' . $pct . '% of the monthly target',
                        implode(', ', array_slice($behind, 0, 8)) . (count($behind) > 8 ? ' …' : '') . '. A call or an offer may help before the month ends.',
                        '/admin/agent-ranking.php', '📉');
                }
            } catch (Throwable $e) {}
        }

        /* --- 29 Oct 2026 (management upgrade 4.5): challan out of date --- */
        if ($on('challan_stale')) {
            try {
                foreach (Database::fetchAll(
                    "SELECT x.schedule_id, x.made, s.travel_date, r.from_city, r.to_city, COALESCE(s.dep_time_override, r.dep_time) AS dep
                       FROM (SELECT c.schedule_id, MAX(c.generated_at) AS made FROM challan_reports c
                              WHERE c.report_date BETWEEN :d0 AND :d1 GROUP BY c.schedule_id) x
                       JOIN schedules s ON s.id = x.schedule_id JOIN routes r ON r.id = s.route_id
                      WHERE s.status <> 'cancelled' AND EXISTS (
                            SELECT 1 FROM booking_legs bl JOIN bookings b ON b.id = bl.booking_id
                             WHERE bl.schedule_id = x.schedule_id
                               AND (b.confirmed_at > x.made OR (b.status = 'cancelled' AND b.cancelled_at > x.made)))",
                    ['d0' => $today, 'd1' => addDaysISO($today, 1)]
                ) as $r) {
                    $add('challan_stale', 'chstale:' . (int) $r['schedule_id'] . ':' . (string) $r['made'], 'Challan out of date · ' . $r['from_city'] . ' → ' . $r['to_city'] . ' ' . substr((string) $r['dep'], 0, 5) . ' · ' . formatDate((string) $r['travel_date'], 'j M'),
                        'A ticket was confirmed or cancelled after the challan was drawn at ' . substr((string) $r['made'], 11, 5) . '. Reprint it and send it to the driver again.',
                        '/admin/chalan.php?sid=' . (int) $r['schedule_id'], '📋');
                }
            } catch (Throwable $e) {}
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

        foreach ($watch as $a) {
            $found[] = $a;
        }

        if ($store) {
            /* The bell lists newest first (ORDER BY id DESC): an SOS is stored
               LAST in the run, so it is the row on top. */
            $order = array_keys($found);
            usort($order, static fn(int $x, int $y): int => [(int) ($found[$x]['type'] === 'driver_sos'), $x] <=> [(int) ($found[$y]['type'] === 'driver_sos'), $y]);
            foreach ($order as $i) {
                $a = $found[$i];
                $found[$i]['new'] = self::push($a['type'], $a['title'], $a['body'], $a['link'], $a['icon'], $a['key']) > 0;
            }
        }
        return $found;
    }

    /* =================================================================
     *  15 Nov 2026 - fleet watch: the coach on the road
     * ================================================================= */

    /**
     * The driver's SOS / breakdown rows and the GPS rules. Reads only
     * (the incident table, live_trips, trip_locations, gps_trackers,
     * route_geometry); a missing table or column is "nothing to say". In-app
     * only - nothing here ever reaches a passenger.
     *
     *   gps_silent       status 'running', the bus due on the road
     *                    (departure_at passed), newest fix - trip_locations,
     *                    the trip's own last_ping_at or the bus's tracker -
     *                    older than the minutes. Once per silence (the key
     *                    carries the last fix's time).
     *   overspeed        3+ fixes above the km/h in the last 15 minutes: the
     *                    stored speed, else derived from the fix before;
     *                    coarse fixes (> LiveTrips::MOVE_MAX_ACC_M) and
     *                    anything implying > LiveTrips::MAX_JUMP_KMH are GPS
     *                    noise. Once per trip per clock hour.
     *   route_deviation  the trip's shown position (last_lat/lng, already
     *                    accuracy- and jump-checked by LiveTrips::ingest) more
     *                    than the km from the route's stored road line
     *                    (RouteGeo); no road line = skipped. Once per trip per
     *                    hour of the fix.
     *
     * @param  array<string, array<string, mixed>> $prefs  prefs() shape
     * @return list<array{type: string, key: string, title: string, body: string, link: string, icon: string, new: bool}>
     */
    public static function fleetWatch(array $prefs, ?int $now = null): array
    {
        $now ??= time();
        $on  = static fn(string $rule): bool => !empty($prefs[$rule]['on']);
        $num = static fn(string $rule, string $p): int => (int) ($prefs[$rule][$p] ?? (self::RULES[$rule][3][$p][1] ?? 0));
        $out = [];

        /* a) The driver app already rang the bell when it was pressed; this is
              the reminder for one nobody has closed. */
        if ($on('driver_sos')) {
            $after  = max(1, min(1440, $num('driver_sos', 'min')));
            $events = self::openIncidents();   // newest first
            /* The driver app rings ONE bell for a press repeated on the same
               trip within SOS_REPEAT_SEC of an earlier one (the same
               emergency, TripEvents::alertOffice); it gets no reminder of its
               own either - one emergency, one reminder. */
            $repeat = [];
            $prevAt = [];
            foreach (array_reverse($events) as $ev) {   // oldest first
                if ($ev['live_trip_id'] === null) {
                    continue;
                }
                $g  = (int) $ev['live_trip_id'] . ':' . (string) $ev['kind'];
                $at = (int) strtotime((string) $ev['created_at']);
                if (isset($prevAt[$g]) && $prevAt[$g] >= $at - self::SOS_REPEAT_SEC) {
                    $repeat[(int) $ev['id']] = true;
                }
                $prevAt[$g] = max($prevAt[$g] ?? 0, $at);
            }
            foreach ($events as $ev) {
                if (isset($repeat[(int) $ev['id']])) {
                    continue;
                }
                $age = $now - (int) strtotime((string) $ev['created_at']);
                if ($age >= $after * 60) {
                    $out[] = self::incidentAlert($ev, intdiv($age, 60));
                }
            }
        }
        if (!$on('gps_silent') && !$on('overspeed') && !$on('route_deviation')) {
            return $out;
        }

        try {
            require_once INCLUDE_PATH . '/routegeo.php';   // + etaalerts.php, livetrips.php
            if (!LiveTrips::available()) {
                return $out;
            }
            $trips = Database::fetchAll(
                "SELECT lt.id, lt.route_id, lt.departure_at, lt.started_at, lt.last_ping_at, lt.last_lat, lt.last_lng,
                        COALESCE(lt.bus_id, s.bus_id) AS bus_ref,
                        (SELECT MAX(tl.recorded_at) FROM trip_locations tl WHERE tl.trip_id = lt.id) AS last_fix,
                        r.from_city, r.to_city, COALESCE(s.dep_time_override, r.dep_time) AS dep,
                        b.bus_number, d.full_name AS driver_name, d.phone AS driver_phone
                   FROM live_trips lt
                   JOIN routes r ON r.id = lt.route_id
                   LEFT JOIN schedules s ON s.id = lt.schedule_id
                   LEFT JOIN buses b ON b.id = COALESCE(lt.bus_id, s.bus_id)
                   LEFT JOIN drivers d ON d.id = lt.driver_id
                  WHERE lt.status = 'running'
                    AND (s.id IS NULL OR s.status NOT IN ('arrived','cancelled'))
                    AND COALESCE(lt.last_ping_at, lt.started_at, lt.departure_at) >= :since
                  ORDER BY lt.id
                  LIMIT 200",
                ['since' => date('Y-m-d H:i:s', $now - LiveTrips::STALE_TRIP_H * 3600)]
            );
        } catch (Throwable $e) {
            try {
                Logger::warning('AdminAlerts fleet watch failed', ['error' => $e->getMessage()]);
            } catch (Throwable $ignored) {
            }
            return $out;
        }
        if ($trips === []) {
            return $out;
        }

        $ts   = static fn($v): int => $v === null || $v === '' ? 0 : (int) strtotime((string) $v);
        $name = static fn(array $t): string => ((string) ($t['bus_number'] ?? '') !== '' ? 'Bus ' . $t['bus_number'] . ' · ' : '')
            . trim($t['from_city'] . ' → ' . $t['to_city']) . ((string) ($t['dep'] ?? '') !== '' ? ' ' . substr((string) $t['dep'], 0, 5) : '');
        $crew = static fn(array $t): string => (string) ($t['driver_name'] ?? '') !== ''
            ? 'Driver ' . $t['driver_name'] . ((string) ($t['driver_phone'] ?? '') !== '' ? ' (' . $t['driver_phone'] . ')' : '') . '. '
            : '';
        $span = static fn(int $min): string => $min < 120 ? $min . ' min' : intdiv($min, 60) . ' h ' . ($min % 60) . ' min';
        $link = '/admin/map.php';   // the live GPS radar: driver phones, trackers, road lines

        /* --- b) GPS silent --- */
        if ($on('gps_silent')) {
            $limit   = max(2, min(1440, $num('gps_silent', 'min')));
            $tracker = [];
            try {
                foreach (Database::fetchAll(
                    'SELECT bus_id, MAX(last_fix_at) AS f FROM gps_trackers WHERE is_active = 1 AND last_fix_at IS NOT NULL GROUP BY bus_id'
                ) as $r) {
                    $tracker[(int) $r['bus_id']] = $ts($r['f']);
                }
            } catch (Throwable $e) {
                // no tracker table on this server: the trip's own fixes decide
            }
            foreach ($trips as $t) {
                $dep = $ts($t['departure_at']);
                if ($dep <= 0 || $dep > $now) {
                    continue;   // not due on the road yet
                }
                $last = max($ts($t['last_ping_at']), $ts($t['last_fix']), $tracker[(int) ($t['bus_ref'] ?? 0)] ?? 0);
                $ref  = $last > 0 ? $last : max($ts($t['started_at']), $dep);
                $mins = intdiv($now - $ref, 60);
                if ($ref <= 0 || $now - $ref <= $limit * 60) {
                    continue;
                }
                $out[] = ['type' => 'gps_silent', 'key' => 'gpssilent:' . (int) $t['id'] . ':' . $ref,
                    'title' => 'GPS silent ' . $span($mins) . ' · ' . $name($t),
                    'body'  => ($last > 0 ? 'Last position ' . date('j M H:i', $last) . '. ' : 'No GPS position since the trip started (' . date('j M H:i', $ref) . '). ')
                             . $crew($t) . 'The phone may be off, out of signal or the app closed — call the driver.',
                    'link' => $link, 'icon' => '📡', 'new' => false];
            }
        }

        /* --- c) over-speed: the last 15 minutes of fixes --- */
        if ($on('overspeed')) {
            $limit = max(30, min(LiveTrips::MAX_JUMP_KMH, $num('overspeed', 'kmh')));
            $since = $now - 15 * 60;
            $ids   = array_map(static fn(array $t): int => (int) $t['id'], $trips);
            $byId  = array_combine($ids, $trips);
            $fixes = [];
            try {
                $in = implode(',', array_fill(0, count($ids), '?'));
                foreach (Database::fetchAll(
                    "SELECT trip_id, recorded_at, latitude, longitude, speed_kmh, accuracy_m FROM trip_locations
                      WHERE trip_id IN ($in) AND recorded_at >= ? AND recorded_at <= ?
                      ORDER BY trip_id, recorded_at, id",
                    array_merge($ids, [date('Y-m-d H:i:s', $since - 10 * 60), date('Y-m-d H:i:s', $now + 120)])
                ) as $f) {
                    $fixes[(int) $f['trip_id']][] = $f;
                }
            } catch (Throwable $e) {
            }
            foreach ($fixes as $tripId => $list) {
                $prev = null; $fast = 0; $top = 0.0; $topAt = 0; $firstFast = 0; $lastFast = 0;
                foreach ($list as $f) {
                    if ($f['accuracy_m'] !== null && (float) $f['accuracy_m'] > LiveTrips::MOVE_MAX_ACC_M) {
                        continue;   // too coarse to say anything
                    }
                    $pt  = ['lat' => (float) $f['latitude'], 'lng' => (float) $f['longitude'], 'ts' => $ts($f['recorded_at'])];
                    $kmh = $f['speed_kmh'] !== null ? (float) $f['speed_kmh'] : null;
                    if ($kmh === null && $prev !== null && $pt['ts'] - $prev['ts'] >= 10) {
                        $kmh = EtaAlerts::haversineKm($prev['lat'], $prev['lng'], $pt['lat'], $pt['lng']) / (($pt['ts'] - $prev['ts']) / 3600);
                        if ($kmh > LiveTrips::MAX_JUMP_KMH) {
                            continue;   // a jump no coach can make: this fix is noise, the one before stays the reference
                        }
                    }
                    $prev = $pt;
                    if ($pt['ts'] < $since || $kmh === null || $kmh > LiveTrips::MAX_JUMP_KMH) {
                        continue;
                    }
                    if ($kmh > $limit) {
                        $fast++;
                        $firstFast = $firstFast ?: $pt['ts'];
                        $lastFast  = $pt['ts'];
                        if ($kmh > $top) { $top = $kmh; $topAt = $pt['ts']; }
                    }
                }
                // 3+ fast fixes spread over at least a minute: a 1 Hz tracker's 3-second burst is not over-speeding.
                if ($fast >= 3 && $lastFast - $firstFast >= 60 && isset($byId[$tripId])) {
                    $t = $byId[$tripId];
                    $out[] = ['type' => 'overspeed', 'key' => 'speed:' . $tripId . ':' . date('YmdH', $now),
                        'title' => 'Over-speed ' . (int) round($top) . ' km/h · ' . $name($t),
                        'body'  => $fast . ' positions above ' . $limit . ' km/h in the last 15 minutes (fastest ' . (int) round($top) . ' km/h at ' . date('H:i', $topAt) . '). '
                                 . $crew($t) . 'Call the driver to slow down.',
                        'link' => $link, 'icon' => '🚨', 'new' => false];
                }
            }
        }

        /* --- d) off the road line --- */
        if ($on('route_deviation')) {
            $limit = max(1, min(50, $num('route_deviation', 'km')));
            foreach ($trips as $t) {
                $at = $ts($t['last_ping_at']);
                if ($t['last_lat'] === null || $t['last_lng'] === null || $at <= 0 || $at < $now - 2 * 3600 || $at > $now + 120
                    || $ts($t['departure_at']) > $now) {
                    continue;   // no recent position, or the bus is not due on the road yet (a depot off the line)
                }
                try {
                    $road = RouteGeo::load((int) $t['route_id']);
                    $pos  = $road !== null ? RouteGeo::locate($road, (float) $t['last_lat'], (float) $t['last_lng']) : null;
                } catch (Throwable $e) {
                    $pos = null;
                }
                if ($pos === null || $pos['off'] <= $limit) {
                    continue;
                }
                $out[] = ['type' => 'route_deviation', 'key' => 'offroute:' . (int) $t['id'] . ':' . date('YmdH', $at),
                    'title' => 'Off the route ' . number_format($pos['off'], 1) . ' km · ' . $name($t),
                    'body'  => 'Position at ' . date('H:i', $at) . ' is ' . number_format($pos['off'], 1) . ' km from the road line (about km '
                             . (int) round($pos['km']) . ' of the route). ' . $crew($t) . 'Check with the driver: a diversion, a wrong turn or a stop off the road.',
                    'link' => $link, 'icon' => '🧭', 'new' => false];
            }
        }
        return $out;
    }

    /**
     * Does the incident table carry the incident columns? A server without
     * database/upgrade-2026-11-15-trip-events.sql says no (as would the older
     * passenger-reminder `trip_events`, booking_id / event), and the SOS rule
     * stays quiet.
     */
    private static function incidentsReady(): bool
    {
        $t = self::$eventsTable;
        if (preg_match('/^[A-Za-z0-9_]+$/', $t) !== 1) {
            return false;
        }
        if (!array_key_exists($t, self::$eventsReady)) {
            try {
                Database::fetchAll("SELECT id, kind, live_trip_id, resolved_at FROM `$t` LIMIT 0");
                self::$eventsReady[$t] = true;
            } catch (Throwable $e) {
                self::$eventsReady[$t] = false;
            }
        }
        return self::$eventsReady[$t];
    }

    /**
     * Open SOS / breakdown rows (resolved_at NULL), newest first, with the
     * trip's route and bus and the driver who pressed it (driver_ref
     * 'D<drivers.id>', as TripEvents writes it) when those tables are there.
     *
     * @return list<array<string, mixed>>
     */
    private static function openIncidents(): array
    {
        if (!self::incidentsReady()) {
            return [];
        }
        $t     = self::$eventsTable;
        $where = "e.kind IN ('sos','breakdown') AND e.resolved_at IS NULL";
        try {
            return Database::fetchAll(
                "SELECT e.id, e.kind, e.live_trip_id, e.schedule_id, e.bus_label, e.driver_ref, e.stop_name, e.note, e.created_at,
                        r.from_city, r.to_city, COALESCE(s.dep_time_override, r.dep_time) AS dep,
                        b.bus_number, d.full_name AS driver_name, d.phone AS driver_phone
                   FROM `$t` e
                   LEFT JOIN live_trips lt ON lt.id = e.live_trip_id
                   LEFT JOIN schedules s ON s.id = COALESCE(e.schedule_id, lt.schedule_id)
                   LEFT JOIN routes r ON r.id = COALESCE(s.route_id, lt.route_id)
                   LEFT JOIN buses b ON b.id = COALESCE(lt.bus_id, s.bus_id)
                   LEFT JOIN drivers d ON e.driver_ref = CONCAT('D', d.id)
                  WHERE $where ORDER BY e.id DESC LIMIT 50"
            );
        } catch (Throwable $e) {
            try {   // live-tracking tables missing: the incident alone still rings the bell
                return Database::fetchAll(
                    "SELECT e.id, e.kind, e.live_trip_id, e.schedule_id, e.bus_label, e.driver_ref, e.stop_name, e.note, e.created_at
                       FROM `$t` e WHERE $where ORDER BY e.id DESC LIMIT 50"
                );
            } catch (Throwable $e2) {
                return [];
            }
        }
    }

    /** @param array<string, mixed> $ev  an openIncidents() row */
    private static function incidentAlert(array $ev, int $openMin): array
    {
        $sos   = (string) $ev['kind'] === 'sos';
        $at    = (int) strtotime((string) $ev['created_at']);
        $bus   = trim((string) (($ev['bus_number'] ?? '') !== '' ? $ev['bus_number'] : ($ev['bus_label'] ?? '')));
        $route = ($ev['from_city'] ?? null) !== null ? trim($ev['from_city'] . ' → ' . $ev['to_city']) . (($ev['dep'] ?? '') !== '' ? ' ' . substr((string) $ev['dep'], 0, 5) : '') : '';
        $ref   = trim((string) ($ev['driver_ref'] ?? ''));
        $who   = ($ev['driver_name'] ?? '') !== '' ? $ev['driver_name'] . (($ev['driver_phone'] ?? '') !== '' ? ' (' . $ev['driver_phone'] . ')' : '')
               : (preg_match('/^D\d+$/', $ref) === 1 ? '' : $ref);   // a bare id says nothing to the office
        $note  = trim((string) ($ev['note'] ?? ''));
        $stop  = trim((string) ($ev['stop_name'] ?? ''));
        $open  = $openMin < 120 ? $openMin . ' min' : intdiv($openMin, 60) . ' h ' . ($openMin % 60) . ' min';
        return [
            'type'  => 'driver_sos',
            'key'   => 'sos:' . (int) $ev['id'] . ':' . date('ymdHi', $at),
            'title' => ($sos ? '🆘 SOS' : '🛠️ Breakdown') . ' still open ' . $open . ($bus !== '' ? ' · Bus ' . $bus : '') . ($route !== '' ? ' · ' . $route : ''),
            'body'  => ($note !== '' ? $note . '. ' : '') . ($stop !== '' ? 'Near ' . $stop . '. ' : '') . ($who !== '' ? 'Driver ' . $who . '. ' : '')
                     . 'Pressed ' . date('j M H:i', $at) . ' and nobody has marked it handled. '
                     . ($sos ? 'Call the driver now.' : 'Arrange help for the coach.') . ' Mark it handled once dealt with.',
            'link'  => '/admin/map.php',
            'icon'  => $sos ? '🆘' : '🛠️',
            'new'   => false,
        ];
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
