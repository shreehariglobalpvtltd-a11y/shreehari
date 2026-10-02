<?php
/**
 * =====================================================================
 *  includes/aiwatch.php — AI Watch: "what needs you now" (2 Oct 2026)
 *
 *  Owner: "sap chizma AI le watch garos, help garos, suggest deos".
 *
 *  One read-only pass over the live tables that turns the state of the
 *  business into a short, ranked list of things a person should DO, each
 *  with a one-tap button to the screen that does it:
 *
 *    office — payments to verify, buses/drivers missing on a running
 *             trip, a bus that is filling slowly (or nearly full), failed
 *             WhatsApp tickets, agents sitting on cash, refunds due,
 *             support / offline-desk / enquiry queues, yesterday's
 *             expenses not entered, a pickup with no time, unpaid staff.
 *    seller — their own bookings waiting for payment, cash to hand over,
 *             passengers travelling tomorrow, free berths on the next bus,
 *             commission earned this month.
 *
 *  Rules, not a language model: every line is a fact read from the DB a
 *  moment ago, so it is instant, free, never wrong about a number and
 *  works when every AI provider is down. Each watcher runs in its own
 *  try/catch — a missing table or column silences that one watcher, never
 *  the panel and never the page. Each is gated on the permission of the
 *  screen it points at, so nobody is told to open a page that refuses them.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiWatch
{
    /** Most suggestions a panel shows before "show all". */
    public const SHOW = 6;

    private const RANK = ['high' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];

    /** @var null|callable(string):bool */
    private static $can = null;

    /**
     * The office list (owner, manager, accountant …).
     *
     * @param null|callable(string):bool $can permission probe (tests inject one)
     * @return list<array{key:string,level:string,icon:string,title:string,detail:string,href:string,cta:string}>
     */
    public static function forOffice(?callable $can = null): array
    {
        self::$can = $can;
        $out = [];
        foreach ([
            'pendingPayments', 'tripsMissingCrew', 'seatFill', 'waFailed',
            'agentCash', 'refundsDue', 'supportOpen', 'offlineDesk',
            'enquiriesNew', 'expensesMissing', 'pickupTimes', 'payrollDue',
        ] as $watcher) {
            try {
                foreach (self::$watcher() as $s) {
                    $out[] = $s;
                }
            } catch (Throwable $e) {
                // one blind watcher never hides the rest
            }
        }
        self::$can = null;
        return self::rank($out);
    }

    /**
     * One seller's own list (agent / counter): only their book, their cash.
     *
     * @return list<array{key:string,level:string,icon:string,title:string,detail:string,href:string,cta:string}>
     */
    public static function forSeller(int $adminId): array
    {
        $out = [];
        foreach (['sellerPending', 'sellerCash', 'sellerTomorrow', 'nextBusFree', 'sellerMonth'] as $watcher) {
            try {
                foreach (self::$watcher($adminId) as $s) {
                    $out[] = $s;
                }
            } catch (Throwable $e) {
                // see forOffice()
            }
        }
        return self::rank($out);
    }

    /* =================================================================
     *  Office watchers — each returns 0..n suggestions
     * ================================================================= */

    /** Payment proofs (UTR / screenshot) waiting for a person. */
    private static function pendingPayments(): array
    {
        if (!self::can('payments.view')) {
            return [];
        }
        $r = Database::fetch("SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM payments WHERE status = 'pending'");
        $n = (int) ($r['n'] ?? 0);
        if ($n === 0) {
            return [];
        }
        $mins = self::minutesSince((string) ($r['oldest'] ?? ''));
        return [self::item(
            'pay', $mins >= 15 ? 'high' : 'warn', '💳',
            $n . ' payment' . self::s($n) . ' waiting to be verified',
            'Oldest ' . self::ago($mins) . '. The passenger has no confirmed ticket until someone checks the UTR / screenshot.',
            '/admin/payments.php', 'Verify now'
        )];
    }

    /**
     * Trips today / tomorrow with no coach or no driver on them — ONE line
     * per day, not one per trip. The driver check only runs once the office
     * keeps a driver list (Drivers & Crew); a company that never assigns
     * drivers in the system is not nagged about it every day.
     */
    private static function tripsMissingCrew(): array
    {
        if (!self::can('schedules.view')) {
            return [];
        }
        $today = todayISO();
        $rows = Database::fetchAll(
            "SELECT s.id, s.travel_date, r.from_city, r.to_city,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep,
                    COALESCE(s.bus_id, r.bus_id) AS bus,
                    s.driver_id, r.crew_name
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.travel_date BETWEEN :d0 AND :d1
                AND s.status = 'scheduled' AND s.is_blocked = 0 AND r.is_active = 1
              ORDER BY s.travel_date, dep",
            ['d0' => $today, 'd1' => addDaysISO($today, 1)]
        );
        $useDrivers = (int) Database::scalar('SELECT COUNT(*) FROM drivers WHERE is_active = 1') > 0;
        $byDay = [];
        foreach ($rows as $t) {
            $noBus    = (int) ($t['bus'] ?? 0) <= 0;
            $noDriver = $useDrivers && (int) ($t['driver_id'] ?? 0) <= 0 && trim((string) ($t['crew_name'] ?? '')) === '';
            if (!$noBus && !$noDriver) {
                continue;
            }
            $day = (string) $t['travel_date'];
            $byDay[$day]['trips'][] = self::hm((string) $t['dep']) . ' ' . $t['from_city'] . ' → ' . $t['to_city']
                . ' (' . ($noBus && $noDriver ? 'bus + driver' : ($noBus ? 'bus' : 'driver')) . ')';
            $byDay[$day]['bus']    = ($byDay[$day]['bus'] ?? false) || $noBus;
            $byDay[$day]['driver'] = ($byDay[$day]['driver'] ?? false) || $noDriver;
        }
        $out = [];
        foreach ($byDay as $day => $g) {
            $what  = $g['bus'] && $g['driver'] ? 'bus / driver' : ($g['bus'] ? 'bus' : 'driver');
            $n     = count($g['trips']);
            $isNow = $day === $today;
            $out[] = self::item(
                'crew-' . $day, $isNow ? 'high' : 'warn', '🚌',
                ($isNow ? 'Today' : 'Tomorrow') . ': ' . $n . ' trip' . self::s($n) . ' without a ' . $what,
                implode(' · ', $g['trips']) . '. The manifest, chalan and "where is my bus" need it.',
                '/admin/trips.php', 'Assign ' . $what
            );
        }
        return $out;
    }

    /** The next two days' departures: slow sellers and nearly-full coaches. */
    private static function seatFill(): array
    {
        if (!self::can('dashboard.view')) {
            return [];
        }
        $trips = self::upcomingTrips(2);
        if ($trips === []) {
            return [];
        }
        $sold = Seats::occupiedBedsFor(array_column($trips, 'id'));
        $out  = [];
        $slow = [];
        foreach ($trips as $t) {
            $cap  = (int) $t['_cap'];
            $have = (int) ($sold[(int) $t['id']] ?? 0);
            $free = max(0, $cap - $have);
            $pct  = (int) round($have * 100 / $cap);
            $when = self::dayWord((string) $t['travel_date']) . ' ' . self::hm((string) $t['dep']);
            $trip = $t['from_city'] . ' → ' . $t['to_city'];
            $hrs  = (int) $t['_hours'];
            if ($pct >= 90) {
                $out[] = self::item(
                    'full-' . (int) $t['id'], 'info', '🔥',
                    $when . ' ' . $trip . ' is ' . $pct . '% full — only ' . $free . ' berth' . self::s($free) . ' left',
                    'Demand is high. Run an extra coach on this date, or keep the last berths for the office.',
                    '/admin/buses.php', 'Plan extra bus'
                );
            } elseif ($pct < 40 && $hrs <= 48 && self::can('payments.view')) {
                $slow[] = ['id' => (int) $t['id'], 'line' => $when . ' ' . $trip . ': ' . $have . '/' . $cap . ' sold (' . $pct . '%)',
                           'free' => $free, 'hrs' => $hrs];
            }
        }
        // Slow sellers fold into ONE line — one nudge to market, not one per bus.
        if (count($slow) === 1) {
            $out[] = self::item(
                'slow-' . $slow[0]['id'], 'warn', '📣', $slow[0]['line'],
                $slow[0]['free'] . ' berths still free, departs in ' . self::hours($slow[0]['hrs']) . '. Share a promo card / offer on WhatsApp and remind your agents.',
                '/admin/promo-card.php', 'Make promo card'
            );
        } elseif (count($slow) > 1) {
            $out[] = self::item(
                'slow', 'warn', '📣', count($slow) . ' departures in the next 48 h are under 40% full',
                implode(' · ', array_column($slow, 'line')) . '. Share a promo card / offer on WhatsApp and remind your agents.',
                '/admin/promo-card.php', 'Make promo card'
            );
        }
        return $out;
    }

    /** WhatsApp ticket sends that failed — the desk hands those over by hand. */
    private static function waFailed(): array
    {
        if (!self::can('bookings.view')) {
            return [];
        }
        $n = (int) Database::scalar(
            "SELECT COUNT(DISTINCT m.booking_id) FROM message_logs m
               JOIN bookings b ON b.id = m.booking_id AND b.status = 'confirmed'
              WHERE m.channel = 'whatsapp' AND m.status = 'failed'
                AND m.created_at >= NOW() - INTERVAL 1 DAY
                AND NOT EXISTS (SELECT 1 FROM message_logs m2
                                 WHERE m2.booking_id = m.booking_id AND m2.channel = 'whatsapp'
                                   AND m2.status = 'sent' AND m2.id > m.id)"
        );
        if ($n === 0) {
            return [];
        }
        return [self::item(
            'wa', 'warn', '💬',
            $n . ' ticket' . self::s($n) . ' did not reach WhatsApp (last 24 h)',
            'The passenger has no ticket on the phone yet. Send each one with one tap from the hand-over list.',
            '/admin/wa-pending.php', 'Send by hand'
        )];
    }

    /** Cash agents collected and still hold. */
    private static function agentCash(): array
    {
        if (!self::can('commissions.view')) {
            return [];
        }
        $rows = Database::fetchAll(
            "SELECT l.agent_admin_id AS id, SUM(l.amount) AS bal, a.full_name, a.username
               FROM agent_ledger l JOIN admins a ON a.id = l.agent_admin_id AND a.is_active = 1
              WHERE l.account = 'cash'
              GROUP BY l.agent_admin_id, a.full_name, a.username
             HAVING bal > 0.009
              ORDER BY bal DESC
              LIMIT 25"
        );
        if ($rows === []) {
            return [];
        }
        $overdue = [];
        $total   = 0.0;
        foreach ($rows as $r) {
            $total += (float) $r['bal'];
            $days = AgentWallet::settlementOverdueDays((int) $r['id']);
            if ($days > 0) {
                $overdue[] = $r + ['days' => $days];
            }
        }
        if ($overdue !== []) {
            if (count($overdue) === 1) {
                $o = $overdue[0];
                return [self::item(
                    'cash-' . (int) $o['id'], 'warn', '💰',
                    ($o['full_name'] ?: $o['username']) . ' is holding ' . inr((float) $o['bal']) . ' — ' . (int) $o['days'] . ' day' . self::s((int) $o['days']) . ' past the hand-over date',
                    'Collect the cash or send the payment reminder from the agent\'s page.',
                    '/admin/agent.php?agent=' . (int) $o['id'], 'Open agent'
                )];
            }
            $sum = array_sum(array_map(static fn(array $o): float => (float) $o['bal'], $overdue));
            return [self::item(
                'cash', 'warn', '💰',
                count($overdue) . ' agents are past the hand-over date with ' . inr($sum) . ' cash',
                'Oldest first in Accounting → "Agents holding our cash".',
                '/admin/accounting.php', 'See who'
            )];
        }
        return [self::item(
            'cash', 'info', '💰',
            'Agents are holding ' . inr($total) . ' of ticket cash',
            count($rows) . ' agent' . self::s(count($rows)) . ' — collect it at the next hand-over.',
            '/admin/accounting.php', 'See who'
        )];
    }

    private static function refundsDue(): array
    {
        if (!self::can('refunds.view')) {
            return [];
        }
        $r = Database::fetch("SELECT COUNT(*) AS n, COALESCE(SUM(refund_amount), 0) AS amt FROM bookings WHERE refund_status = 'pending'");
        $n = (int) ($r['n'] ?? 0);
        if ($n === 0) {
            return [];
        }
        return [self::item(
            'refund', 'warn', '↩️',
            $n . ' refund' . self::s($n) . ' due · ' . inr((float) $r['amt']),
            'Cancelled passengers are waiting for their money. Mark each one paid or refused.',
            '/admin/refunds.php', 'Open refunds'
        )];
    }

    private static function supportOpen(): array
    {
        if (!self::can('support.view')) {
            return [];
        }
        $r = Database::fetch(
            "SELECT COUNT(*) AS n, COALESCE(SUM(priority IN ('high','urgent')), 0) AS hot, MIN(created_at) AS oldest
               FROM support_tickets WHERE status = 'open'"
        );
        $n = (int) ($r['n'] ?? 0);
        if ($n === 0) {
            return [];
        }
        $hot = (int) ($r['hot'] ?? 0);
        return [self::item(
            'support', $hot > 0 ? 'high' : 'warn', '🙋',
            $n . ' customer request' . self::s($n) . ' waiting' . ($hot > 0 ? ' (' . $hot . ' urgent)' : ''),
            'Oldest ' . self::ago(self::minutesSince((string) ($r['oldest'] ?? ''))) . '. The WhatsApp assistant handed these to a person.',
            '/admin/support-inbox.php', 'Reply'
        )];
    }

    private static function offlineDesk(): array
    {
        if (!self::can('bookings.view')) {
            return [];
        }
        $n = (int) Database::scalar("SELECT COUNT(*) FROM offline_requests WHERE status IN ('received','failed')");
        if ($n === 0) {
            return [];
        }
        return [self::item(
            'offline', 'warn', '📴',
            $n . ' offline sale' . self::s($n) . ' still to turn into tickets',
            'Sold while the desk had no internet — the seats are not reserved until they are ticketed.',
            '/admin/offline-requests.php', 'Ticket them'
        )];
    }

    private static function enquiriesNew(): array
    {
        if (!self::can('customers.view')) {
            return [];
        }
        $n = (int) Database::scalar("SELECT COUNT(*) FROM enquiries WHERE status = 'new'");
        if ($n === 0) {
            return [];
        }
        return [self::item(
            'enq', 'info', '📞',
            $n . ' new enquir' . ($n === 1 ? 'y' : 'ies') . ' to call back',
            'People who asked about a seat from the website. A quick call usually becomes a ticket.',
            '/admin/enquiries.php', 'Call them'
        )];
    }

    /** Yesterday's coach ran (it sold seats) but no expense was written down. */
    private static function expensesMissing(): array
    {
        if (!self::can('payments.view')) {
            return [];
        }
        $y = addDaysISO(todayISO(), -1);
        $ran = (int) Database::scalar(
            "SELECT COUNT(*) FROM schedules s
              WHERE s.travel_date = :d AND s.status <> 'cancelled'
                AND EXISTS (SELECT 1 FROM booking_seats bs WHERE bs.schedule_id = s.id AND bs.released_at IS NULL)",
            ['d' => $y]
        );
        if ($ran === 0) {
            return [];
        }
        $have = (int) Database::scalar('SELECT COUNT(*) FROM trip_expenses WHERE expense_date = :d', ['d' => $y]);
        if ($have > 0) {
            return [];
        }
        return [self::item(
            'exp', 'info', '🧾',
            'Yesterday\'s bus expenses are not entered',
            'Diesel, toll, driver pay — two minutes now keeps the month\'s profit honest.',
            '/admin/accounting.php#expenses', 'Add expenses'
        )];
    }

    /** A pickup customers can choose that has no boarding time. */
    private static function pickupTimes(): array
    {
        if (!self::can('routes.view')) {
            return [];
        }
        $names = Database::fetchAll(
            "SELECT DISTINCT rs.stop_name FROM route_stops rs JOIN routes r ON r.id = rs.route_id
              WHERE r.is_active = 1 AND rs.is_active = 1 AND rs.stop_type = 'boarding' AND rs.stop_time IS NULL
              ORDER BY rs.stop_name LIMIT 5"
        );
        if ($names === []) {
            return [];
        }
        $list = implode(', ', array_map(static fn(array $r): string => (string) $r['stop_name'], $names));
        return [self::item(
            'pickup', 'info', '📍',
            'Pickup without a time: ' . $list,
            'Passengers boarding there see no time on the ticket. Add the time and a landmark.',
            '/admin/routes.php#pickups', 'Set time'
        )];
    }

    /** Staff (no panel login) not yet paid for last month. */
    private static function payrollDue(): array
    {
        // Same two gates as admin/payroll.php: a counter desk holds
        // payments.view but must not see salaries.
        if (!self::can('payments.view') || !self::can('reports.view')) {
            return [];
        }
        $period = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $n = (int) Database::scalar(
            "SELECT COUNT(*) FROM staff_members m
              WHERE m.is_active = 1 AND m.monthly_salary > 0
                AND (m.joined_on IS NULL OR m.joined_on <= LAST_DAY(:p0))
                AND NOT EXISTS (SELECT 1 FROM staff_salary_payments p WHERE p.staff_id = m.id AND p.period = :p1)",
            ['p0' => $period . '-01', 'p1' => $period]
        );
        if ($n === 0) {
            return [];
        }
        return [self::item(
            'payroll', (int) date('j') > 7 ? 'warn' : 'info', '👷',
            $n . ' staff not paid for ' . date('F', strtotime($period . '-01')),
            'Pay salary — advances are deducted automatically and a WhatsApp slip is ready.',
            '/admin/payroll.php?m=' . $period, 'Pay salary'
        )];
    }

    /* =================================================================
     *  Seller watchers (scoped to one admin id)
     * ================================================================= */

    private static function sellerPending(int $adminId): array
    {
        $n = (int) Database::scalar(
            "SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id = :a AND status = 'pending'",
            ['a' => $adminId]
        );
        if ($n === 0) {
            return [];
        }
        return [self::item(
            'my-pending', 'warn', '⏳',
            $n . ' of your booking' . self::s($n) . ' still wait for payment',
            'The seat is only held — collect the payment or the hold runs out.',
            '/admin/bookings.php?status=pending', 'Open'
        )];
    }

    private static function sellerCash(int $adminId): array
    {
        $cash = (float) (AgentWallet::balances($adminId)['cash'] ?? 0);
        if ($cash <= 0.009) {
            return [];
        }
        $days = AgentWallet::settlementOverdueDays($adminId);
        return [self::item(
            'my-cash', $days > 0 ? 'warn' : 'info', '💰',
            'You hold ' . inr($cash) . ' ticket cash' . ($days > 0 ? ' — ' . $days . ' day' . self::s($days) . ' past hand-over' : ''),
            'Hand it to the office (or pay by UPI) so your commission can be paid out.',
            '/admin/agent.php', 'My wallet'
        )];
    }

    private static function sellerTomorrow(int $adminId): array
    {
        $r = Database::fetch(
            "SELECT COUNT(DISTINCT b.id) AS n FROM bookings b
               JOIN booking_legs bl ON bl.booking_id = b.id
              WHERE b.sold_by_admin_id = :a AND b.status = 'confirmed' AND bl.travel_date = :t",
            ['a' => $adminId, 't' => addDaysISO(todayISO(), 1)]
        );
        $n = (int) ($r['n'] ?? 0);
        if ($n === 0) {
            return [];
        }
        return [self::item(
            'my-tomorrow', 'info', '🧳',
            $n . ' of your booking' . self::s($n) . ' travel tomorrow',
            'A quick reminder call: pickup point, time, ID card. Fewer missed buses, happier passengers.',
            '/admin/agent-passengers.php', 'See passengers'
        )];
    }

    private static function nextBusFree(int $adminId): array
    {
        $trips = self::upcomingTrips(1);
        if ($trips === []) {
            return [];
        }
        $t    = $trips[0];
        $cap  = (int) $t['_cap'];
        $free = max(0, $cap - (int) (Seats::occupiedBedsFor([(int) $t['id']])[(int) $t['id']] ?? 0));
        if ($free === 0) {
            return [];
        }
        return [self::item(
            'next-bus', 'ok', '⚡',
            self::dayWord((string) $t['travel_date']) . ' ' . self::hm((string) $t['dep']) . ' ' . $t['from_city'] . ' → ' . $t['to_city'] . ': ' . $free . ' berth' . self::s($free) . ' free',
            'Name + mobile is enough — QuickBot picks the seat, fare and pickup.',
            '/admin/quick-ticket.php', 'Sell a ticket'
        )];
    }

    private static function sellerMonth(int $adminId): array
    {
        $earned = (float) (AgentWallet::summary($adminId)['earnedMonth'] ?? 0);
        if ($earned <= 0.009) {
            return [];
        }
        return [self::item(
            'my-month', 'ok', '🏆',
            'You earned ' . inr($earned) . ' commission this month',
            'Keep going — every confirmed sale adds to it the moment it is confirmed.',
            '/admin/agent-sales.php', 'My sales'
        )];
    }

    /* =================================================================
     *  Rendering
     * ================================================================= */

    /**
     * The panel. Empty list → a calm "all clear" line, never a blank box.
     *
     * @param list<array<string,string>> $items
     */
    public static function render(array $items, string $title = '🤖 AI Watch'): string
    {
        $e = static fn(string $v): string => Security::e($v);
        $html = self::css()
            . '<section class="aw" aria-label="AI Watch">'
            . '<div class="aw-head"><b>' . $e($title) . '</b><span class="aw-sub">AI ले हेरिरहेको छ · what needs you now</span>'
            . (Auth::can('bookings.view') ? '<a class="aw-ask" href="/admin/ai-copilot.php">🤖 Ask AI Sahayak</a>' : '')
            . '</div>';
        if ($items === []) {
            return $html . '<div class="aw-clear">✅ All clear — nothing is waiting on you right now. सबै ठीक छ।</div></section>';
        }
        $html .= '<div class="aw-list">';
        foreach ($items as $i => $s) {
            $more = $i >= self::SHOW ? ' aw-more' : '';
            $html .= '<div class="aw-item aw-' . $e($s['level']) . $more . '">'
                . '<span class="aw-ic" aria-hidden="true">' . $e($s['icon']) . '</span>'
                . '<div class="aw-txt"><b>' . $e($s['title']) . '</b><small>' . $e($s['detail']) . '</small></div>'
                . '<a class="btn aw-go" href="' . $e($s['href']) . '">' . $e($s['cta']) . ' →</a>'
                . '</div>';
        }
        $html .= '</div>';
        if (count($items) > self::SHOW) {
            $html .= '<button type="button" class="aw-all" onclick="this.closest(\'.aw\').classList.add(\'aw-open\');this.remove()">Show all ' . count($items) . '</button>';
        }
        return $html . '</section>';
    }

    private static function css(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return '<style>'
            . '.aw{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);box-shadow:var(--sh-1);margin:0 0 18px;overflow:hidden}'
            . '.aw-head{display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;padding:12px 16px;background:linear-gradient(90deg,var(--violet-bg),var(--card));border-bottom:1px solid var(--line)}'
            . '.aw-head b{font-size:15.5px}.aw-sub{font-size:12px;color:var(--mut)}'
            . '.aw-ask{margin-left:auto;font-weight:700;font-size:13px;padding:8px 12px;border-radius:999px;background:var(--violet);color:#fff;min-height:40px;display:inline-flex;align-items:center}'
            . '.aw-ask:hover{color:#fff;filter:brightness(1.08)}'
            . '.aw-list{display:flex;flex-direction:column}'
            . '.aw-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-top:1px solid var(--line);border-left:4px solid var(--line-2)}'
            . '.aw-item:first-child{border-top:0}'
            . '.aw-high{border-left-color:var(--bad);background:linear-gradient(90deg,var(--bad-bg),transparent 55%)}'
            . '.aw-warn{border-left-color:var(--orange)}.aw-info{border-left-color:var(--blue)}.aw-ok{border-left-color:var(--ok)}'
            . '.aw-ic{font-size:24px;width:40px;height:40px;border-radius:12px;background:var(--soft);display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto}'
            . '.aw-txt{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:2px}'
            . '.aw-txt b{font-size:14px;line-height:1.35}.aw-txt small{font-size:12.5px;color:var(--mut);line-height:1.4}'
            . '.aw-go{flex:0 0 auto}.aw-high .aw-go{background:var(--bad)}.aw-warn .aw-go{background:var(--orange)}.aw-ok .aw-go{background:var(--ok)}'
            . '.aw-more{display:none}.aw-open .aw-more{display:flex}'
            . '.aw-all{display:block;width:100%;min-height:46px;border:0;border-top:1px solid var(--line);background:var(--soft);color:var(--blue);font:700 14px var(--f-ui);cursor:pointer}'
            . '.aw-clear{padding:14px 16px;font-weight:600;color:var(--ok)}'
            . '@media(max-width:820px){.aw-item{flex-wrap:wrap;padding:12px}.aw-txt{flex-basis:calc(100% - 56px)}.aw-go{width:100%;min-height:48px}.aw-ask{margin-left:0}}'
            . '</style>';
    }

    /* =================================================================
     *  Helpers
     * ================================================================= */

    /**
     * Next departures (today … +$days) that have not left yet, soonest first,
     * with `_hours` = hours until departure.
     *
     * @return list<array<string,mixed>>
     */
    private static function upcomingTrips(int $days): array
    {
        $today = todayISO();
        $rows = Database::fetchAll(
            "SELECT s.id, s.travel_date, r.from_city, r.to_city,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep,
                    COALESCE(s.coach_type_override, r.coach_type) AS coach
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.travel_date BETWEEN :d0 AND :d1
                AND s.status = 'scheduled' AND s.is_blocked = 0 AND r.is_active = 1
              ORDER BY s.travel_date, dep",
            ['d0' => $today, 'd1' => addDaysISO($today, $days)]
        );
        $now = time();
        $out = [];
        foreach ($rows as $r) {
            $at = strtotime($r['travel_date'] . ' ' . ($r['dep'] ?: '00:00:00'));
            if ($at === false || $at <= $now) {
                continue;
            }
            $r['_hours'] = (int) floor(($at - $now) / 3600);
            $r['_cap']   = self::berths((string) ($r['coach'] ?? ''));
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Berths that physically exist on a coach type — the set every sale is
     * validated against. NOT schedules.total_seats: that column is
     * bookkeeping (default 40) and never a seat cap.
     */
    private static function berths(string $coach): int
    {
        static $memo = [];
        $coach = $coach !== '' ? $coach : 'sleeper';
        return $memo[$coach] ??= max(1, count(Seats::seatIds($coach, 'sharing')));
    }

    private static function can(string $perm): bool
    {
        $probe = self::$can;
        return $probe !== null ? (bool) $probe($perm) : Auth::can($perm);
    }

    /** @return array{key:string,level:string,icon:string,title:string,detail:string,href:string,cta:string} */
    private static function item(string $key, string $level, string $icon, string $title, string $detail, string $href, string $cta): array
    {
        return compact('key', 'level', 'icon', 'title', 'detail', 'href', 'cta');
    }

    /** Most urgent first; the order watchers ran in breaks ties. */
    private static function rank(array $items): array
    {
        $i = 0;
        foreach ($items as &$s) {
            $s['_o'] = $i++;
        }
        unset($s);
        usort($items, static fn(array $a, array $b): int =>
            [self::RANK[$a['level']] ?? 9, $a['_o']] <=> [self::RANK[$b['level']] ?? 9, $b['_o']]);
        return array_map(static function (array $s): array { unset($s['_o']); return $s; }, $items);
    }

    private static function minutesSince(string $ts): int
    {
        $t = $ts !== '' ? strtotime($ts) : false;
        return $t === false ? 0 : max(0, (int) floor((time() - $t) / 60));
    }

    private static function ago(int $mins): string
    {
        if ($mins < 1) {
            return 'just now';
        }
        if ($mins < 60) {
            return $mins . ' min ago';
        }
        if ($mins < 48 * 60) {
            return floor($mins / 60) . ' h ago';
        }
        return floor($mins / 1440) . ' days ago';
    }

    private static function hours(int $h): string
    {
        return $h < 24 ? $h . ' h' : floor($h / 24) . ' d ' . ($h % 24) . ' h';
    }

    private static function hm(string $time): string
    {
        return substr($time, 0, 5);
    }

    private static function dayWord(string $iso): string
    {
        $today = todayISO();
        if ($iso === $today) {
            return 'Today';
        }
        if ($iso === addDaysISO($today, 1)) {
            return 'Tomorrow';
        }
        return date('D j M', (int) strtotime($iso));
    }

    private static function s(int $n): string
    {
        return $n === 1 ? '' : 's';
    }
}
