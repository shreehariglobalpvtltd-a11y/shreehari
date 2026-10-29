<?php
/**
 * =====================================================================
 *  CommandCenter — the owner's one-screen read of the business
 *  (29 Oct 2026, management upgrade 2.4 "live counter tracking", 4.3
 *  "command center" and the pending-approvals count of 4.4).
 *
 *  READ-ONLY. Every number here is read from tables other screens
 *  already own (bookings, payments, agent_ledger, counter_shifts, …) and
 *  each figure links to the page where it is acted on. Nothing here
 *  writes, sends or settles anything.
 *
 *  Definitions follow the screens they summarise, so the dashboard can
 *  never disagree with them:
 *   revenue      bookings 'confirmed', by confirmed_at (= admin/index.php)
 *   projected    month-to-date + (average of the last 7 full days ×
 *                the days left in the month after today)
 *   agent cash   the cash account balance (agent_ledger) of ACTIVE
 *                agents holding money; office / counter staff shown apart
 *   counters     payments each person verified today (= CounterShift)
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class CommandCenter
{
    private static function sum(string $from, string $to): float
    {
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(total_amount), 0) FROM bookings WHERE status = 'confirmed' AND confirmed_at >= :a AND confirmed_at < :b",
            ['a' => $from, 'b' => $to],
            0
        ), 2);
    }

    /** @return array{today: float, mtd: float, avg7: float, projected: float, days_left: int, last_month_same: float} */
    public static function revenue(string $today): array
    {
        $tomorrow = addDaysISO($today, 1);
        $m0       = substr($today, 0, 7) . '-01';
        $daysIn   = (int) date('t', (int) strtotime($m0));
        $dayNo    = (int) date('j', (int) strtotime($today));
        $todayRev = self::sum($today, $tomorrow);
        $mtd      = self::sum($m0, $tomorrow);
        $avg7     = round(self::sum(addDaysISO($today, -7), $today) / 7, 2);
        $left     = max(0, $daysIn - $dayNo);
        // The same days of last month, for a fair "are we up or down" line.
        $lm0      = date('Y-m-01', (int) strtotime($m0 . ' -1 month'));
        $lmDays   = (int) date('t', (int) strtotime($lm0));
        $lmTo     = addDaysISO($lm0, min($dayNo, $lmDays));
        return [
            'today'           => $todayRev,
            'mtd'             => $mtd,
            'avg7'            => $avg7,
            'projected'       => round($mtd + $avg7 * $left, 2),
            'days_left'       => $left,
            'last_month_same' => self::sum($lm0, $lmTo),
        ];
    }

    /**
     * Cash the sellers hold for the company right now.
     *
     * @return array{agents_total: float, agents_n: int, top_name: string, top_amount: float, staff_total: float}
     */
    public static function cashDue(): array
    {
        $out = ['agents_total' => 0.0, 'agents_n' => 0, 'top_name' => '', 'top_amount' => 0.0, 'staff_total' => 0.0];
        try {
            foreach (Database::fetchAll(
                "SELECT l.agent_admin_id AS id, a.role, a.full_name, a.username, SUM(l.amount) AS bal
                   FROM agent_ledger l
                   JOIN admins a ON a.id = l.agent_admin_id AND a.is_active = 1
                  WHERE l.account = 'cash'
                  GROUP BY l.agent_admin_id, a.role, a.full_name, a.username
                 HAVING bal > 0.009"
            ) as $r) {
                $bal = round((float) $r['bal'], 2);
                if ((string) $r['role'] === 'agent') {
                    $out['agents_total'] += $bal;
                    $out['agents_n']++;
                    if ($bal > $out['top_amount']) {
                        $out['top_amount'] = $bal;
                        $out['top_name']   = (string) ($r['full_name'] ?: $r['username']);
                    }
                } else {
                    $out['staff_total'] += $bal;
                }
            }
        } catch (Throwable $e) {
            return $out;
        }
        $out['agents_total'] = round($out['agents_total'], 2);
        $out['staff_total']  = round($out['staff_total'], 2);
        return $out;
    }

    /**
     * Today's coaches, fullest first, with a traffic-light band:
     * red under 30 %, amber 30–70 %, green above 70 %.
     *
     * @return list<array{schedule_id: int, label: string, bus: string, sold: int, total: int, pct: int, band: string}>
     */
    public static function occupancy(string $day): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT s.id, s.total_seats, r.from_city, r.to_city, COALESCE(s.dep_time_override, r.dep_time) AS dep, COALESCE(bu.bus_number, '') AS bus
                   FROM schedules s
                   JOIN routes r ON r.id = s.route_id
                   LEFT JOIN buses bu ON bu.id = s.bus_id
                  WHERE s.travel_date = :d AND s.status <> 'cancelled'
                  ORDER BY dep",
                ['d' => $day]
            );
        } catch (Throwable $e) {
            return [];
        }
        if (!class_exists('Seats')) {
            require_once INCLUDE_PATH . '/seats.php';
        }
        $sold = $rows !== [] ? Seats::occupiedBedsFor(array_map(static fn(array $r): int => (int) $r['id'], $rows)) : [];
        $out  = [];
        foreach ($rows as $r) {
            $tot = (int) $r['total_seats'];
            $s   = (int) ($sold[(int) $r['id']] ?? 0);
            $pct = $tot > 0 ? (int) round($s * 100 / $tot) : 0;
            $out[] = [
                'schedule_id' => (int) $r['id'],
                'label'       => $r['from_city'] . ' → ' . $r['to_city'] . ($r['dep'] ? ' · ' . substr((string) $r['dep'], 0, 5) : ''),
                'bus'         => (string) $r['bus'],
                'sold'        => $s,
                'total'       => $tot,
                'pct'         => $pct,
                'band'        => $pct < 30 ? 'red' : ($pct <= 70 ? 'amber' : 'green'),
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['pct'] <=> $a['pct']);
        return $out;
    }

    /** @return list<array{id: int, name: string, role: string, tickets: int, revenue: float}> */
    public static function topSellers(string $day, int $n = 3): array
    {
        try {
            return array_map(static fn(array $r): array => [
                'id' => (int) $r['id'], 'name' => (string) ($r['full_name'] ?: $r['username']), 'role' => (string) $r['role'],
                'tickets' => (int) $r['tickets'], 'revenue' => round((float) $r['revenue'], 2),
            ], Database::fetchAll(
                "SELECT a.id, a.full_name, a.username, a.role, COUNT(*) AS tickets, COALESCE(SUM(b.total_amount), 0) AS revenue
                   FROM bookings b
                   JOIN admins a ON a.id = b.sold_by_admin_id
                  WHERE b.status = 'confirmed' AND b.confirmed_at >= :d AND b.confirmed_at < :d1
                  GROUP BY a.id, a.full_name, a.username, a.role
                  ORDER BY revenue DESC, tickets DESC
                  LIMIT " . max(1, min(10, $n)),
                ['d' => $day, 'd1' => addDaysISO($day, 1)]
            ));
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Every counter window today: who sat there, the shift, what they took.
     * People = active 'counter' logins plus anyone with an open shift.
     *
     * @return list<array<string, mixed>> grouped by desk (rows carry desk + desk_name)
     */
    public static function counterBoard(string $day): array
    {
        try {
            $people = Database::fetchAll(
                "SELECT a.id, a.full_name, a.username, a.role FROM admins a
                  WHERE a.is_active = 1 AND (a.role = 'counter'
                        OR a.id IN (SELECT admin_id FROM counter_shifts WHERE is_open = 1))
                  ORDER BY a.full_name"
            );
        } catch (Throwable $e) {
            try {
                $people = Database::fetchAll("SELECT id, full_name, username, role FROM admins WHERE is_active = 1 AND role = 'counter' ORDER BY full_name");
            } catch (Throwable $e2) {
                return [];
            }
        }
        if ($people === []) {
            return [];
        }
        $ids   = array_map(static fn(array $p): int => (int) $p['id'], $people);
        $money = [];
        foreach (Database::fetchAll(
            "SELECT p.verified_by AS id, COUNT(DISTINCT p.booking_id) AS tickets,
                    COALESCE(SUM(CASE WHEN p.method IN ('cash','cod') THEN p.amount ELSE 0 END), 0) AS cash,
                    COALESCE(SUM(CASE WHEN p.method NOT IN ('cash','cod') THEN p.amount ELSE 0 END), 0) AS upi
               FROM payments p
              WHERE p.status = 'verified' AND p.verified_at >= :d AND p.verified_at < :d1
                AND p.verified_by IN (" . implode(',', $ids) . ")
              GROUP BY p.verified_by",
            ['d' => $day, 'd1' => addDaysISO($day, 1)]
        ) as $m) {
            $money[(int) $m['id']] = $m;
        }

        if (!class_exists('CounterShift') && is_file(INCLUDE_PATH . '/countershift.php')) {
            require_once INCLUDE_PATH . '/countershift.php';
        }
        $hasShifts = class_exists('CounterShift');
        $out = [];
        foreach ($people as $p) {
            $id    = (int) $p['id'];
            $desk  = CounterDesk::forAdmin($id);
            $state = 'none';
            $since = '';
            $drawer = null;
            $shiftId = 0;
            if ($hasShifts) {
                try {
                    $open = CounterShift::current($id);
                    if ($open !== null) {
                        $state   = 'open';
                        $since   = substr((string) $open['opened_at'], 11, 5);
                        $drawer  = CounterShift::live($open)['expected'];
                        $shiftId = (int) $open['id'];
                    } else {
                        $last = Database::fetch(
                            'SELECT id, closed_at, variance FROM counter_shifts WHERE admin_id = :a AND closed_at >= :d ORDER BY closed_at DESC LIMIT 1',
                            ['a' => $id, 'd' => $day]
                        );
                        if ($last !== null) {
                            $state   = CounterShift::verdict((float) $last['variance']) === 'ok' ? 'closed' : 'closed_diff';
                            $since   = substr((string) $last['closed_at'], 11, 5);
                            $shiftId = (int) $last['id'];
                        }
                    }
                } catch (Throwable $e) {
                    $state = 'none';
                }
            }
            $m = $money[$id] ?? ['tickets' => 0, 'cash' => 0, 'upi' => 0];
            $out[] = [
                'id'        => $id,
                'name'      => (string) ($p['full_name'] ?: $p['username']),
                'desk'      => (string) ($desk['code'] ?? ''),
                'desk_name' => (string) ($desk['name'] ?? ''),
                'state'     => $state,
                'since'     => $since,
                'shift_id'  => $shiftId,
                'tickets'   => (int) $m['tickets'],
                'cash'      => round((float) $m['cash'], 2),
                'upi'       => round((float) $m['upi'], 2),
                'drawer'    => $drawer,
            ];
        }
        usort($out, static fn(array $a, array $b): int => [$a['desk'] === '' ? 1 : 0, $a['desk'], $a['name']] <=> [$b['desk'] === '' ? 1 : 0, $b['desk'], $b['name']]);
        return $out;
    }

    /**
     * What waits on a decision, each with the page that takes it.
     *
     * @return list<array{label: string, n: int, amount: float, href: string}>
     */
    public static function approvals(): array
    {
        $q = static function (string $sql, array $p = []): array {
            try {
                $r = Database::fetch($sql, $p);
                return [(int) ($r['n'] ?? 0), round((float) ($r['amt'] ?? 0), 2)];
            } catch (Throwable $e) {
                return [0, 0.0];
            }
        };
        $out = [];
        [$n, $a] = $q("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amt FROM payments WHERE status = 'pending'");
        $out[] = ['label' => 'Payments to verify', 'n' => $n, 'amount' => $a, 'href' => '/admin/payments.php'];
        [$n, $a] = $q("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amt FROM agent_payout_requests WHERE status = 'open'");
        $out[] = ['label' => 'Agent payout requests', 'n' => $n, 'amount' => $a, 'href' => '/admin/settlements.php'];
        [$n, $a] = $q("SELECT COUNT(*) AS n, 0 AS amt FROM agent_settlements WHERE status = 'draft'");
        $out[] = ['label' => 'Settlements to approve', 'n' => $n, 'amount' => $a, 'href' => '/admin/settlements.php'];
        [$n, $a] = $q("SELECT COUNT(*) AS n, 0 AS amt FROM agent_settlements WHERE status = 'approved'");
        $out[] = ['label' => 'Approved settlements to pay', 'n' => $n, 'amount' => $a, 'href' => '/admin/settlements.php'];
        [$n, $a] = $q("SELECT COUNT(*) AS n, COALESCE(SUM(refund_amount), 0) AS amt FROM bookings WHERE refund_status = 'pending'");
        $out[] = ['label' => 'Refunds waiting (settled by hand)', 'n' => $n, 'amount' => $a, 'href' => '/admin/refunds.php'];
        $apps = 0;
        foreach (["SELECT COUNT(*) AS n, 0 AS amt FROM agent_applications WHERE status IN ('new','reviewing','kyc_pending','agreement_pending')",
                  "SELECT COUNT(*) AS n, 0 AS amt FROM staff_applications WHERE status IN ('pending','under_review')"] as $sql) {
            $apps += $q($sql)[0];
        }
        $out[] = ['label' => 'Agent / staff applications', 'n' => $apps, 'amount' => 0.0, 'href' => '/admin/team.php?tab=approvals'];
        return $out;
    }

    /**
     * Actionable items, worst first. Each: level red|amber|blue, text, href.
     *
     * @return list<array{level: string, icon: string, text: string, href: string}>
     */
    public static function alerts(string $today): array
    {
        $out = [];
        $add = static function (string $level, string $icon, string $text, string $href) use (&$out): void {
            $out[] = ['level' => $level, 'icon' => $icon, 'text' => $text, 'href' => $href];
        };

        // Payments waiting more than an hour for verification.
        try {
            $n = (int) Database::scalar("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND created_at < (NOW() - INTERVAL 1 HOUR)", [], 0);
            if ($n > 0) {
                $add('red', '⏳', $n . ' payment' . ($n === 1 ? '' : 's') . ' waiting more than 1 hour for verification', '/admin/payments.php');
            }
        } catch (Throwable $e) {}

        // Agent cash unsettled for more than 3 days (oldest sale since their last handover).
        try {
            $late = [];
            foreach (Database::fetchAll(
                "SELECT l.agent_admin_id AS id, a.full_name, a.username, SUM(l.amount) AS bal,
                        MAX(CASE WHEN l.entry_type = 'cash_handover' THEN l.created_at END) AS last_ho
                   FROM agent_ledger l
                   JOIN admins a ON a.id = l.agent_admin_id AND a.role = 'agent' AND a.is_active = 1
                  WHERE l.account = 'cash'
                  GROUP BY l.agent_admin_id, a.full_name, a.username
                 HAVING bal > 0.009"
            ) as $r) {
                $oldest = Database::scalar(
                    "SELECT MIN(created_at) FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'cash_due' AND created_at > :h",
                    ['a' => (int) $r['id'], 'h' => (string) ($r['last_ho'] ?? '1970-01-01 00:00:00')],
                    null
                );
                if ($oldest !== null && (time() - (int) strtotime((string) $oldest)) > 3 * 86400) {
                    $late[] = (float) $r['bal'];
                }
            }
            if ($late !== []) {
                $add('red', '💵', count($late) . ' agent' . (count($late) === 1 ? '' : 's') . ' holding ' . inr(array_sum($late)) . ' cash for more than 3 days', '/admin/settlements.php');
            }
        } catch (Throwable $e) {}

        // Counter shifts left open from an earlier day.
        try {
            $n = (int) Database::scalar('SELECT COUNT(*) FROM counter_shifts WHERE is_open = 1 AND opened_at < :d', ['d' => $today], 0);
            if ($n > 0) {
                $add('amber', '🧾', $n . ' counter shift' . ($n === 1 ? '' : 's') . ' still open from an earlier day', '/admin/shift.php');
            }
        } catch (Throwable $e) {}

        // Identity documents expiring within 30 days (or already expired).
        try {
            $r = Database::fetch(
                "SELECT COALESCE(SUM(p.id_expires_on < :t), 0) AS gone, COUNT(*) AS n
                   FROM admin_profiles p JOIN admins a ON a.id = p.admin_id AND a.is_active = 1
                  WHERE p.id_expires_on IS NOT NULL AND p.id_expires_on <= :t30",
                ['t' => $today, 't30' => addDaysISO($today, 30)]
            );
            $n = (int) ($r['n'] ?? 0);
            if ($n > 0) {
                $gone = (int) ($r['gone'] ?? 0);
                $add($gone > 0 ? 'red' : 'amber', '🪪', $n . ' agent/staff ID' . ($n === 1 ? '' : 's') . ' expiring within 30 days' . ($gone > 0 ? ' (' . $gone . ' already expired)' : ''), '/admin/agents.php');
            }
        } catch (Throwable $e) {}

        // Agents far below their monthly target after the middle of the month.
        if ((int) date('j', (int) strtotime($today)) >= 15 && is_file(INCLUDE_PATH . '/agenttargets.php')) {
            require_once INCLUDE_PATH . '/agenttargets.php';
            try {
                $ym  = substr($today, 0, 7);
                $low = 0;
                foreach (AgentTargets::salesBoard($ym) as $agentId => $money) {
                    $t = AgentTargets::targetFor((int) $agentId, $ym);
                    if ($t['amount'] > 0 && $money < $t['amount'] * 0.5) {
                        $low++;
                    }
                }
                if ($low > 0) {
                    $add('amber', '📉', $low . ' agent' . ($low === 1 ? ' is' : 's are') . ' under 50% of the monthly target', '/admin/agent-ranking.php');
                }
            } catch (Throwable $e) {}
        }

        // Tickets on a trip whose challan has changed since it was last drawn.
        try {
            $n = self::challanChanged($today);
            if ($n > 0) {
                $add('amber', '📋', $n . ' trip' . ($n === 1 ? '' : 's') . ' today changed after the challan was made — reprint it', '/admin/chalan.php');
            }
        } catch (Throwable $e) {}

        $rank = ['red' => 0, 'amber' => 1, 'blue' => 2];
        usort($out, static fn(array $a, array $b): int => ($rank[$a['level']] ?? 9) <=> ($rank[$b['level']] ?? 9));
        return $out;
    }

    /**
     * Trips on $day whose passenger list moved (a booking confirmed or
     * cancelled) after their newest challan render — the challan in the
     * driver's hand is out of date. 0 when nothing was rendered yet.
     */
    public static function challanChanged(string $day): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM (
                SELECT c.schedule_id, MAX(c.generated_at) AS made
                  FROM challan_reports c
                 WHERE c.report_date = :d
                 GROUP BY c.schedule_id
             ) x
             WHERE EXISTS (
                SELECT 1 FROM booking_legs bl JOIN bookings b ON b.id = bl.booking_id
                 WHERE bl.schedule_id = x.schedule_id
                   AND (b.confirmed_at > x.made OR (b.status = 'cancelled' AND b.cancelled_at > x.made))
             )",
            ['d' => $day],
            0
        );
    }
}
