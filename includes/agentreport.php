<?php
/**
 * =====================================================================
 *  includes/agentreport.php — one agent's sales & commission for a date
 *  window (3 Oct 2026, owner: "agent lai click garera kati banyo,
 *  commission kati, filter mode, fast image statement").
 *
 *  The Report tab of admin/agent-360.php reads everything from here:
 *  totals, day-by-day, bus-by-bus and the ticket list for ONE window, on
 *  the same two bases admin/agent-sales.php already offers:
 *    journey — the day the passenger travels (default: how the desk, the
 *              chalani and the owner count),
 *    sale    — the day the ticket was cut.
 *
 *  Money rules (kept identical to agent-sales.php so the two pages agree):
 *    - sale value counts CONFIRMED bookings only; passengers count live
 *      seats on confirmed / pending / completed bookings;
 *    - commission is READ from agent_ledger (commission + commission_void
 *      rows of THIS agent for each booking), never recomputed from today's
 *      rate — a rate change only ever moves future sales;
 *    - manual corrections / payouts are window figures off the ledger's
 *      own created_at, shown beside, never mixed into per-ticket numbers.
 *
 *  Every statement binds each placeholder once (native prepares).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AgentReport
{
    public const PRESETS = [
        'today'     => 'Today',
        'yesterday' => 'Yesterday',
        'week'      => 'This week',
        'month'     => 'This month',
        'lastmonth' => 'Last month',
        'all'       => 'All time',
        'custom'    => 'Custom',
    ];

    /**
     * Resolve a preset (or a custom from/to) into a half-open [from, to)
     * window plus the human label the statement prints.
     *
     * @return array{preset:string, from:string, to:string, last:string, label:string, basis:string}
     */
    public static function window(string $preset, string $from = '', string $to = '', string $basis = 'journey'): array
    {
        $basis  = $basis === 'sale' ? 'sale' : 'journey';
        $preset = isset(self::PRESETS[$preset]) ? $preset : 'month';
        $today  = todayISO();
        $isDate = static fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;

        switch ($preset) {
            case 'today':
                $f = $today; $t = addDaysISO($today, 1);
                break;
            case 'yesterday':
                $f = addDaysISO($today, -1); $t = $today;
                break;
            case 'week':
                $f = date('Y-m-d', (int) strtotime('monday this week'));
                // A journey can be in the future: the whole week, not "till today".
                $t = $basis === 'journey' ? addDaysISO($f, 7) : addDaysISO($today, 1);
                break;
            case 'lastmonth':
                $f = date('Y-m-01', (int) strtotime('first day of last month'));
                $t = date('Y-m-01');
                break;
            case 'all':
                $f = '2020-01-01';
                $t = $basis === 'journey' ? addDaysISO($today, 400) : addDaysISO($today, 1);
                break;
            case 'custom':
                $f = $isDate($from) ? $from : date('Y-m-01');
                $l = $isDate($to) ? $to : $today;
                if ($l < $f) { [$f, $l] = [$l, $f]; }   // a backwards range would silently return nothing
                $t = addDaysISO($l, 1);
                break;
            case 'month':
            default:
                $f = date('Y-m-01');
                $t = $basis === 'journey' ? date('Y-m-01', (int) strtotime('first day of next month')) : addDaysISO($today, 1);
                break;
        }

        $last = addDaysISO($t, -1);
        if ($preset === 'all') {
            $label = 'All time';
        } elseif ($f === $last) {
            $label = formatDate($f, 'j M Y');
        } else {
            $label = formatDate($f, 'j M') . ' – ' . formatDate($last, 'j M Y');
        }

        return ['preset' => $preset, 'from' => $f, 'to' => $t, 'last' => $last, 'label' => $label, 'basis' => $basis];
    }

    /**
     * Everything the Report tab and the image statement show for one window.
     *
     * @param array{from:string, to:string, basis:string} $win
     * @return array{totals:array<string,float|int>, days:list<array>, buses:list<array>, tickets:list<array>, truncated:bool}
     */
    public static function build(int $agentId, array $win): array
    {
        $basis    = ($win['basis'] ?? 'journey') === 'sale' ? 'sale' : 'journey';
        $col      = $basis === 'sale' ? 'b.created_at' : 'blx.travel_date';
        $join     = ($basis === 'sale' ? ' LEFT' : '') . " JOIN booking_legs blx ON blx.booking_id = b.id AND blx.leg_type = 'outbound'";
        $from     = (string) $win['from'];
        $to       = (string) $win['to'];

        // Per-booking ledger commission of THIS agent (credit minus void).
        $commSub  = "(SELECT COALESCE(SUM(l.amount), 0) FROM agent_ledger l
                       WHERE l.booking_id = b.id AND l.agent_admin_id = :ca AND l.account = 'commission'
                         AND l.entry_type IN ('commission','commission_void'))";
        $paxSub   = "(SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id AND bs.released_at IS NULL)";

        $rows = [];
        try {
            $rows = Database::fetchAll(
                "SELECT b.id, b.pnr, b.status, b.total_amount, b.created_at,
                        blx.travel_date, blx.schedule_id,
                        r.from_city, r.to_city, COALESCE(s.slot, 1) AS slot,
                        COALESCE(bus.bus_number, '') AS bus_number,
                        $paxSub AS pax,
                        (SELECT GROUP_CONCAT(bs2.seat_no ORDER BY bs2.seat_no SEPARATOR ' ')
                           FROM booking_seats bs2 WHERE bs2.booking_id = b.id AND bs2.released_at IS NULL) AS seats,
                        (SELECT bp.full_name FROM booking_passengers bp
                          WHERE bp.booking_id = b.id AND bp.is_primary = 1 LIMIT 1) AS passenger,
                        $commSub AS commission
                   FROM bookings b" . $join . "
                   LEFT JOIN schedules s ON s.id = blx.schedule_id
                   LEFT JOIN routes r ON r.id = s.route_id
                   LEFT JOIN buses bus ON bus.id = COALESCE(s.bus_id, r.bus_id)
                  WHERE b.sold_by_admin_id = :agent AND $col >= :from AND $col < :to
                  ORDER BY " . ($basis === 'sale' ? 'b.created_at DESC, b.id DESC' : 'blx.travel_date DESC, b.id DESC') . "
                  LIMIT 2000",
                ['ca' => $agentId, 'agent' => $agentId, 'from' => $from, 'to' => $to]
            );
        } catch (Throwable $e) {
            Logger::error('AgentReport::build failed', ['agent' => $agentId, 'e' => $e->getMessage()]);
            $rows = [];
        }

        $totals = [
            'tickets' => 0, 'confirmed' => 0, 'cancelled' => 0, 'pending' => 0,
            'pax' => 0, 'sale' => 0.0, 'commission' => 0.0,
        ];
        $days = [];
        $buses = [];
        $tickets = [];

        foreach ($rows as $r) {
            $status = (string) $r['status'];
            $live   = in_array($status, ['confirmed', 'pending', 'completed'], true);
            $conf   = $status === 'confirmed' || $status === 'completed';
            $pax    = $live ? (int) $r['pax'] : 0;
            $amt    = $conf ? (float) $r['total_amount'] : 0.0;
            $comm   = round((float) $r['commission'], 2);

            $totals['tickets']++;
            if ($conf) { $totals['confirmed']++; }
            if ($status === 'cancelled') { $totals['cancelled']++; }
            if ($status === 'pending') { $totals['pending']++; }
            $totals['pax']        += $pax;
            $totals['sale']       += $amt;
            $totals['commission'] += $comm;

            $day = $basis === 'sale' ? substr((string) $r['created_at'], 0, 10) : (string) ($r['travel_date'] ?? '');
            if ($day !== '') {
                $days[$day] ??= ['d' => $day, 'tickets' => 0, 'pax' => 0, 'sale' => 0.0, 'commission' => 0.0];
                $days[$day]['tickets']++;
                $days[$day]['pax']        += $pax;
                $days[$day]['sale']       += $amt;
                $days[$day]['commission'] += $comm;
            }

            $sid = (int) ($r['schedule_id'] ?? 0);
            if ($sid > 0) {
                $buses[$sid] ??= [
                    'd'     => (string) ($r['travel_date'] ?? ''),
                    'route' => trim((string) ($r['from_city'] ?? '') . ' → ' . (string) ($r['to_city'] ?? ''), ' →'),
                    'slot'  => (int) $r['slot'],
                    'bus'   => (string) $r['bus_number'],
                    'tickets' => 0, 'pax' => 0, 'sale' => 0.0, 'commission' => 0.0,
                ];
                $buses[$sid]['tickets']++;
                $buses[$sid]['pax']        += $pax;
                $buses[$sid]['sale']       += $amt;
                $buses[$sid]['commission'] += $comm;
            }

            $tickets[] = [
                'pnr'        => (string) $r['pnr'],
                'status'     => $status,
                'travel'     => (string) ($r['travel_date'] ?? ''),
                'sold'       => (string) $r['created_at'],
                'route'      => trim((string) ($r['from_city'] ?? '') . ' → ' . (string) ($r['to_city'] ?? ''), ' →'),
                'passenger'  => (string) ($r['passenger'] ?? ''),
                'seats'      => (string) ($r['seats'] ?? ''),
                'pax'        => $pax,
                'amount'     => (float) $r['total_amount'],
                'commission' => $comm,
            ];
        }

        krsort($days);
        uasort($buses, static fn(array $a, array $b): int => [$b['d'], $a['slot']] <=> [$a['d'], $b['slot']]);

        // Window figures straight off the ledger's own date: manual
        // corrections / bonuses and payouts the office made in the window.
        $ledger = ['adjust' => 0.0, 'payout' => 0.0, 'cash_in' => 0.0];
        try {
            $lrow = Database::fetch(
                "SELECT COALESCE(SUM(CASE WHEN account = 'commission' AND entry_type IN ('adjustment','commission') AND booking_id IS NULL THEN amount ELSE 0 END), 0) AS adj,
                        COALESCE(SUM(CASE WHEN entry_type = 'payout' THEN -amount ELSE 0 END), 0) AS pay,
                        COALESCE(SUM(CASE WHEN entry_type = 'cash_handover' THEN -amount ELSE 0 END), 0) AS cin
                   FROM agent_ledger
                  WHERE agent_admin_id = :a AND created_at >= :f AND created_at < :t",
                ['a' => $agentId, 'f' => $from, 't' => $to]
            );
            if ($lrow !== null) {
                $ledger = ['adjust' => (float) $lrow['adj'], 'payout' => (float) $lrow['pay'], 'cash_in' => (float) $lrow['cin']];
            }
        } catch (Throwable $e) {
            // ledger table missing on a very old database — the page still renders
        }

        $totals['sale']       = round($totals['sale'], 2);
        $totals['commission'] = round($totals['commission'], 2);
        $totals['adjust']     = round($ledger['adjust'], 2);
        $totals['payout']     = round($ledger['payout'], 2);
        $totals['cash_in']    = round($ledger['cash_in'], 2);
        $totals['company']    = round($totals['sale'] - $totals['commission'], 2);   // what stays with the company

        return [
            'totals'    => $totals,
            'days'      => array_values($days),
            'buses'     => array_values($buses),
            'tickets'   => $tickets,
            'truncated' => count($rows) >= 2000,
        ];
    }

    /**
     * What this agent is paid right now, as one record the rate card and
     * the statement both print.
     *
     * @return array{mode:string, flat:float, label:string, sub:string, tier:float}
     */
    public static function rateCard(int $agentId): array
    {
        $ov   = AgentWallet::commissionOverrideFor($agentId);
        $tier = AgentWallet::flatRateFor($agentId);
        $pct  = AgentWallet::commissionPercentFor($agentId);
        $pctL = rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%';

        if ($ov['mode'] === 'flat') {
            return ['mode' => 'flat', 'flat' => (float) $ov['flat'], 'label' => inr((float) $ov['flat']) . ' / pax', 'sub' => 'this agent only', 'tier' => $tier];
        }
        if ($ov['mode'] === 'percent') {
            return ['mode' => 'percent', 'flat' => $tier, 'label' => $pctL . ' of fare', 'sub' => 'this agent only', 'tier' => $tier];
        }
        if (AgentWallet::flatMode()) {
            return ['mode' => 'tier', 'flat' => $tier, 'label' => inr($tier) . ' / pax',
                    'sub' => (AgentWallet::agentTypeFor($agentId) === 'joint' ? 'team/organisation' : 'direct') . ' company tier', 'tier' => $tier];
        }
        return ['mode' => 'tier', 'flat' => $tier, 'label' => $pctL . ' of fare', 'sub' => 'company rate', 'tier' => $tier];
    }
}
