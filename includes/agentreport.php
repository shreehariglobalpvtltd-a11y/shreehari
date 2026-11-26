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
     * The office a member should ring: the company number, or the Nepal office
     * for a member whose profile says 977 (when that office has a number).
     * Same rule as the Help strip on admin/agent.php (19 Nov 2026, u5 AG-5).
     *
     * @return array{call:string, wa:string}  call = as people read it, wa = digits for wa.me
     */
    public static function officeContact(int $agentId): array
    {
        $call = Settings::officePhone();
        $wa   = Settings::officeWhatsApp();
        try {
            if ((string) (AgentWallet::profile($agentId)['country_code'] ?? '') === '977') {
                $npPh = trim(Settings::getString('nepal_phone', ''));
                if ($npPh !== '') {
                    $call = trim((string) (preg_split('#\s*/\s*#', $npPh)[0] ?? $npPh));
                }
                $npWa = preg_replace('/\D/', '', Settings::getString('nepal_whatsapp', '')) ?? '';
                if (strlen($npWa) === 10) {
                    $npWa = '977' . $npWa;
                }
                if (preg_match('/^[1-9]\d{9,14}$/', $npWa) === 1) {
                    $wa = $npWa;
                }
            }
        } catch (Throwable $e) {
            // the company number stands
        }
        return ['call' => $call, 'wa' => $wa];
    }

    /**
     * Which of these agents have NO verified KYC (26 Nov 2026, fx1) - for the
     * "KYC बाँकी" marker on the agent lists, one read for the whole list.
     * The one rule: only admin_profiles.kyc_status = 'verified' is verified;
     * no profile row, none, submitted and rejected are all pending. Only
     * role = agent rows are judged (office staff carry no agent KYC). Empty on
     * a database without the KYC columns, so nobody is marked by mistake.
     *
     * @param  list<int> $ids
     * @return array<int,string> admin id => 'none' | 'submitted' | 'rejected'
     */
    public static function kycPending(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if ($ids === [] || !AgentWallet::kycAvailable()) {
            return [];
        }
        $out = [];
        try {
            foreach (Database::fetchAll(
                "SELECT a.id, COALESCE(p.kyc_status, 'none') AS k
                   FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id
                  WHERE a.role = 'agent' AND a.id IN (" . implode(',', $ids) . ')'      // ints only - safe to inline
            ) as $r) {
                $k = strtolower(trim((string) $r['k']));
                if ($k !== 'verified') {
                    $out[(int) $r['id']] = $k !== '' ? $k : 'none';
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /** The small "KYC बाँकी" pill for a list row; '' for a verified agent (or a non-agent). */
    public static function kycPill(array $pending, int $id): string
    {
        if (!isset($pending[$id])) {
            return '';
        }
        return $pending[$id] === 'rejected'
            ? ' <span class="pill" data-kyc-pill style="background:#f7dcdc;color:#8a1f1f;font-size:10.5px">KYC ✗ मिलेन</span>'
            : ' <span class="pill" data-kyc-pill style="background:#fef3c7;color:#92400e;font-size:10.5px" title="No verified ID on file">KYC बाँकी</span>';
    }

    /**
     * Everything admin/agent-home.php shows for ONE agent (26 Nov 2026, fx1).
     *
     * READ-ONLY and no money logic of its own - every figure is another
     * helper's figure, so the home can never disagree with the statement:
     *   today / month  tickets, people and ticket value: AgentWallet::headcount()
     *                  (by the day the ticket was CUT; ticket value includes
     *                  pending and pay-at-boarding, so it is never "collected")
     *   commission     AgentStatement::data() for 1st..today: earned less
     *                  reversed, by LEDGER date - the statement's own lines.
     *                  Today's figure is the same rows, today's date only.
     *   net / owes     AgentWallet::netPosition() + AgentStatement::owes()
     *   credit         AgentCredit::status(); 'refusal' also carries the main
     *                  agent's hold for a sub-agent (AgentCredit::refusal)
     * The only SQL here is the last-five list (the query of admin/agent.php's
     * "recent sales", plus the NPR frozen on the sale when the columns exist).
     *
     * @return array<string,mixed>
     */
    public static function home(int $agentId): array
    {
        require_once __DIR__ . '/agentstatement.php';
        require_once __DIR__ . '/agentcredit.php';
        require_once __DIR__ . '/sellerday.php';      // CounterDesk + the BS / AD date label

        $today = todayISO();
        $first = date('Y-m-01', (int) strtotime($today . ' 12:00:00'));

        $hToday = AgentWallet::headcount($agentId, $today, $today);
        $hMonth = AgentWallet::headcount($agentId, $first, $today);

        $commMonth = 0.0;
        $commToday = 0.0;
        $balanced  = true;
        $closing   = null;
        try {
            $st        = AgentStatement::data($agentId, $first, $today);
            $commMonth = round(-1 * ((float) $st['sum']['earned']['amount'] + (float) $st['sum']['reversed']['amount']), 2);
            foreach ($st['rows'] as $row) {
                if ((string) $row['date'] === $today && in_array((string) $row['kind'], ['earned', 'reversed'], true)) {
                    $commToday = round($commToday + (float) $row['cr'] - (float) $row['dr'], 2);
                }
            }
            $balanced = (bool) $st['balanced'];
            $closing  = (float) $st['closing'];
        } catch (Throwable $e) {
            Logger::error('AgentReport::home statement failed', ['agent' => $agentId, 'e' => $e->getMessage()]);
            $balanced = false;
        }

        $net    = AgentWallet::netPosition($agentId);
        $credit = AgentCredit::status($agentId);
        $why    = AgentCredit::refusal($agentId);

        $desk = CounterDesk::forAdmin($agentId)['code'];
        $cur  = $desk !== '' ? CounterDesk::currency($desk) : 'INR';
        $rate = $desk !== '' ? CounterDesk::rate($desk) : 1.0;

        $recent = [];
        try {
            $fx = CounterDesk::hasColumn('bookings', 'fx_currency') && CounterDesk::hasColumn('bookings', 'fx_total')
                ? ', b.fx_currency, b.fx_total' . (CounterDesk::hasColumn('bookings', 'fx_rate') ? ', b.fx_rate' : '')
                : '';
            $recent = Database::fetchAll(
                "SELECT b.pnr, b.status, b.total_amount, b.created_at, b.booking_mode" . $fx . ",
                        r.from_city, r.to_city, bl.travel_date,
                        (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
                           FROM booking_seats bs WHERE bs.booking_id = b.id AND bs.released_at IS NULL) AS seats,
                        (SELECT bp.full_name FROM booking_passengers bp
                          WHERE bp.booking_id = b.id AND bp.is_primary = 1 LIMIT 1) AS passenger
                   FROM bookings b
                   LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                   LEFT JOIN schedules s ON s.id = bl.schedule_id
                   LEFT JOIN routes r ON r.id = s.route_id
                  WHERE b.sold_by_admin_id = :a
                  ORDER BY b.id DESC LIMIT 5",
                ['a' => $agentId]
            );
        } catch (Throwable $e) {
            Logger::error('AgentReport::home recent failed', ['agent' => $agentId, 'e' => $e->getMessage()]);
            $recent = [];
        }

        $kyc = 'none';
        try {
            $kyc = (string) (AgentWallet::profile($agentId)['kyc_status'] ?? 'none');
        } catch (Throwable $e) {
            $kyc = 'none';
        }
        $rateLabel = '';
        try {
            $rateLabel = (string) (self::rateCard($agentId)['label'] ?? '');
        } catch (Throwable $e) {
            $rateLabel = '';
        }

        return [
            'agentId'   => $agentId,
            'date'      => $today,
            'dateLabel' => SellerDay::dateLabel($today),
            'monthFrom' => $first,
            'code'      => AgentWallet::agentCodeLabel($agentId),
            'rate'      => $rateLabel,
            'kyc'       => $kyc !== '' ? $kyc : 'none',
            'kycOk'     => $kyc === 'verified',
            'today'     => ['tickets' => (int) $hToday['tickets'], 'heads' => (int) $hToday['heads'], 'sales' => (float) $hToday['sales'], 'commission' => $commToday],
            'month'     => ['tickets' => (int) $hMonth['tickets'], 'heads' => (int) $hMonth['heads'], 'sales' => (float) $hMonth['sales'], 'commission' => $commMonth],
            'net'       => $net,
            'owesNe'    => AgentStatement::owes((float) $net['net'], 'ne'),
            'owesEn'    => AgentStatement::owes((float) $net['net'], 'en'),
            'credit'    => $credit,
            'refusal'   => $why,
            'parentId'  => AgentCredit::parentOf($agentId),
            'payoutReq' => AgentWallet::openPayoutRequests($agentId)[0] ?? null,
            'recent'    => $recent,
            'desk'      => ['code' => $desk, 'currency' => $cur, 'rate' => $rate],
            'balanced'  => $balanced,
            'closing'   => $closing,
            'office'    => self::officeContact($agentId),
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
        require_once __DIR__.'/teamfinance.php';$term=TeamFinance::term($agentId);if($term){$label=match($term['basis']){'percent'=>$term['rate'].'% of eligible fare','flat'=>inr((float)$term['rate']).' / pax','booking'=>inr((float)$term['rate']).' / ticket',default=>'Salary only'};return ['mode'=>$term['basis'],'flat'=>(float)$term['rate'],'label'=>$label,'sub'=>'effective '.$term['effective_at'],'tier'=>(float)$term['rate']];}
        $ov   = AgentWallet::commissionOverrideFor($agentId);
        $tier = AgentWallet::flatRateFor($agentId);
        $pct  = AgentWallet::commissionPercentFor($agentId);
        $pctL = rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%';

        if ($ov['mode'] === 'booking') {
            return ['mode' => 'booking', 'flat' => (float) $ov['flat'], 'label' => inr((float) $ov['flat']) . ' / ticket', 'sub' => 'one PNR, any passenger count', 'tier' => $tier];
        }
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
