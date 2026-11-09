<?php
/**
 * admin/ticket-statement.php — "Kosle kati ticket katyo" (9 Nov 2026).
 *
 * Owner: "agent haru staff haru le kosle kati ticket katyo vanera simple
 * date wise statement — 1. S Hari Global, 1 tarik, 15 ticket (click garexi
 * details ayos)". One plain page: who cut how many tickets, day by day.
 *
 * WHO CUT IT: bookings.booked_by_admin_id is the person who actually cut the
 * ticket and is never rewritten (upgrade-2026-10-31-idc-counter.sql); older
 * rows fall back to sold_by_admin_id. Both NULL = the customer booked online.
 *
 * WHAT COUNTS: a ticket = one passenger seat on the outbound leg (a round
 * trip is still one ticket per passenger). Counted: confirmed / completed,
 * plus pending when a desk cut it (cash still to be verified). An online
 * pending row is an unpaid cart, so it is not a ticket. Cancelled tickets
 * are shown separately, never in the total.
 *
 * Office needs commissions.view (same as Agent Sales); an agent / counter
 * login sees only the tickets they cut themselves.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
if (!Auth::isCounterAgent()) { Auth::requireAdmin('commissions.view'); }

$e     = static fn($s): string => Security::e((string) $s);
$today = todayISO();
$scope = Auth::bookingScopeAdminId();   // non-null = agent / counter: own tickets only

$hasBookedBy = Database::fetch("SHOW COLUMNS FROM bookings LIKE 'booked_by_admin_id'") !== null;
$cutSql      = $hasBookedBy ? 'COALESCE(b.booked_by_admin_id, b.sold_by_admin_id)' : 'b.sold_by_admin_id';
/* 9 Nov 2026 owner: "jasle jati kate ni kei hudaina, main kasko name jodeko ticket chha".
   Default = the agent whose name is on the ticket (sold_by_admin_id — the one the
   commission goes to; NULL = online). by=cut = the login that actually cut it. */
$by          = (string) ($_GET['by'] ?? 'agent');
if (!in_array($by, ['agent', 'cut'], true)) { $by = 'agent'; }
$sellerSql   = $by === 'cut' ? $cutSql : 'b.sold_by_admin_id';

/* ---- Agent commission, merged in (9 Nov 2026) ----------------------------
   Owner: "agent system ma merge gardeu — commission jati bela nikalna milos,
   agent lai per head jati pani rakhna, ghataun badhauna (200 ho), pachhi
   man lageko". The rate is the same store the ticket engine already pays
   from (TeamFinance terms > per-agent override > company rate), so a change
   here applies to the next ticket and never rewrites a commission already
   posted. Office: super-admin or staff.manage + settings. Agent: their own
   commission and a Withdraw button (AgentWallet::requestPayout). */
require_once dirname(__DIR__) . '/includes/teamfinance.php';
$selfId   = (int) ($admin['id'] ?? 0);
$isAgent  = (string) ($admin['role'] ?? '') === 'agent';
$canComm  = $scope === null && (Auth::isSuperadmin() || (Auth::can('staff.manage') && Auth::canManageSettings()));
$pctTxt   = static fn(float $p): string => rtrim(rtrim(number_format($p, 2), '0'), '.') . '%';
$rateOf   = static function (int $aid) use ($pctTxt): array {   // [amount, label, source]
    $t = TeamFinance::term($aid);
    if ($t !== null) {
        $r = (float) $t['rate'];
        return match ((string) $t['basis']) {
            'flat'    => [$r, inr($r) . ' / passenger', 'terms'],
            'booking' => [$r, inr($r) . ' / booking', 'terms'],
            'percent' => [$r, $pctTxt($r) . ' of fare', 'terms'],
            default   => [0.0, 'Salary only', 'terms'],
        };
    }
    $ov = AgentWallet::commissionOverrideFor($aid);
    if ($ov['mode'] === 'flat' || ($ov['mode'] === 'booking')) {
        $r = (float) ($ov['flat'] ?? AgentWallet::flatRateFor($aid));
        return [$r, inr($r) . ($ov['mode'] === 'booking' ? ' / booking' : ' / passenger'), 'own'];
    }
    if ($ov['mode'] === 'percent' || !AgentWallet::flatMode()) {
        $p = (float) AgentWallet::commissionPercentFor($aid);
        return [$p, $pctTxt($p) . ' of fare', $ov['mode'] === 'percent' ? 'own' : 'company'];
    }
    $r = AgentWallet::flatRateFor($aid);
    return [$r, inr($r) . ' / passenger', 'company'];
};
$payoutOk = static function (int $aid): bool {
    require_once dirname(__DIR__) . '/includes/teamregistry.php';
    if (!TeamRegistry::ready()) { return true; }
    return Database::exists(
        "SELECT 1 FROM team_identity i JOIN agent_applications a ON i.person_key = CONCAT('agent:', a.id)
          WHERE a.admin_id = :a AND i.payout_verified = 1", ['a' => $aid]);
};

if (Security::isPost()) {
    $back = 'admin/ticket-statement.php' . ((string) ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    $act  = (string) ($_POST['act'] ?? '');
    try {
        if (!Security::verifyCsrf()) { throw new RuntimeException('Page was open too long — try again.'); }
        if ($act === 'rate') {
            if (!$canComm) { throw new RuntimeException('Only the super-admin / a settings manager sets commission.'); }
            $aid = (int) ($_POST['id'] ?? 0);
            if (!Database::exists("SELECT 1 FROM admins WHERE id = :i AND role = 'agent'", ['i' => $aid])) { throw new RuntimeException('Not an agent.'); }
            $raw  = trim((string) ($_POST['rate'] ?? ''));
            $rate = $raw === '' ? null : (float) preg_replace('/[^0-9.]/', '', $raw);
            if ($rate !== null && ($rate < 0 || $rate > 10000)) { throw new RuntimeException('Commission must be ₹0 – ₹10,000 per passenger.'); }
            $term = TeamFinance::term($aid);
            if ($term !== null) {
                // A signed terms row beats the override, so the change must be a new terms row (from now on).
                AgentWallet::setCommissionOverride($aid, null, null, $selfId);
                $use = $rate ?? AgentWallet::flatRateFor($aid);
                TeamFinance::terms($aid, ['basis' => 'flat', 'rate' => $use, 'salary' => (float) ($term['salary'] ?? 0),
                    'effective_at' => date('Y-m-d'), 'reason' => 'Ticket Statement: ' . inr($use) . ' per passenger'], $selfId);
            } elseif ($rate === null) {
                AgentWallet::setCommissionOverride($aid, null, null, $selfId);
            } else {
                AgentWallet::setCommissionOverride($aid, 'flat', $rate, $selfId);
            }
            $who = trim(AgentWallet::agentCodeLabel($aid) . ' ' . (string) Database::scalar("SELECT COALESCE(NULLIF(full_name, ''), username) FROM admins WHERE id = :i", ['i' => $aid], ''));
            $_SESSION['shg_ts_flash'] = ['ok', $who . ': ' . $rateOf($aid)[1] . ' — from the next ticket'];
        } elseif ($act === 'company_rate') {
            if (!$canComm) { throw new RuntimeException('Only the super-admin / a settings manager sets commission.'); }
            $d = (int) ($_POST['direct'] ?? 0); $j = (int) ($_POST['joint'] ?? 0);
            if ($d < 0 || $d > 10000 || $j < 0 || $j > 10000) { throw new RuntimeException('Rate must be ₹0 – ₹10,000.'); }
            AgentWallet::setFlatRates($d, $j, $selfId, 'Ticket Statement');
            $_SESSION['shg_ts_flash'] = ['ok', 'Company commission: ' . inr($d) . ' direct · ' . inr($j) . ' team, per passenger — from the next ticket'];
        } elseif ($act === 'withdraw') {
            if (!$isAgent) { throw new RuntimeException('Only an agent withdraws commission.'); }
            $amt = (float) preg_replace('/[^0-9.]/', '', (string) ($_POST['amount'] ?? ''));
            AgentWallet::requestPayout($selfId, $amt, 'Ticket Statement');
            $_SESSION['shg_ts_flash'] = ['ok', 'Withdrawal request sent to the office · पैसा निकाल्ने अनुरोध पठाइयो'];
        }
    } catch (Throwable $ex) {
        $_SESSION['shg_ts_flash'] = ['bad', $ex->getMessage()];
    }
    Response::redirect($back);
}
$flash = $_SESSION['shg_ts_flash'] ?? null;
unset($_SESSION['shg_ts_flash']);

/* ---- Date window ---------------------------------------------------- */
$isDate  = static fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
$presets = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'lastmonth' => 'Last month', 'custom' => 'Pick dates'];
$range   = (string) ($_GET['range'] ?? 'month');
if (!isset($presets[$range])) { $range = 'month'; }
$fromIn = Security::clean($_GET['from'] ?? '', 10);
$toIn   = Security::clean($_GET['to'] ?? '', 10);
switch ($range) {
    case 'today':     $from = $today; $toIncl = $today; break;
    case 'yesterday': $from = addDaysISO($today, -1); $toIncl = $from; break;
    case 'week':      $from = date('Y-m-d', strtotime('monday this week', strtotime($today))); $toIncl = $today; break;
    case 'lastmonth': $from = date('Y-m-01', strtotime('first day of last month', strtotime($today))); $toIncl = date('Y-m-t', strtotime($from)); break;
    case 'custom':
        $from   = $isDate($fromIn) ? $fromIn : date('Y-m-01', strtotime($today));
        $toIncl = $isDate($toIn) ? $toIn : $today;
        if ($from > $toIncl) { [$from, $toIncl] = [$toIncl, $from]; }
        break;
    default:          $from = date('Y-m-01', strtotime($today)); $toIncl = $today; break;
}
$toExcl = addDaysISO($toIncl, 1);
$period = $from === $toIncl ? formatDate($from, 'j M Y') : formatDate($from, 'j M') . ' – ' . formatDate($toIncl, 'j M Y');

/* ---- Who: type filter + one person ----------------------------------- */
$types = ['all' => 'All', 'agent' => 'Agents', 'staff' => 'Staff', 'counter' => 'Counter', 'online' => 'Online'];
$type  = (string) ($_GET['type'] ?? 'all');
if (!isset($types[$type]) || $scope !== null) { $type = 'all'; }
$sellerPick = $scope ?? max(0, (int) ($_GET['seller'] ?? 0));   // 0 = everyone, -1 = online only
if (isset($_GET['seller']) && (string) $_GET['seller'] === 'online' && $scope === null) { $sellerPick = -1; }

$typeOf = static function (?string $role, int $id): string {
    if ($id <= 0) { return 'online'; }
    return match ((string) $role) { 'agent' => 'agent', 'counter' => 'counter', default => 'staff' };
};
$typeLabel = ['agent' => 'Agent', 'staff' => 'Staff', 'counter' => 'Counter', 'online' => 'Online'];
$typeTone  = ['agent' => 'info', 'staff' => 'ok', 'counter' => 'warn', 'online' => 'mute'];

/* ---- One SQL shape for every view ------------------------------------
   seats = outbound-leg passengers; falls back to 1 so a booking with no
   leg row still shows as a ticket instead of vanishing. */
$where  = "b.created_at >= :f AND b.created_at < :t
           AND ( b.status IN ('confirmed','completed','cancelled')
                 OR (b.status = 'pending' AND $cutSql IS NOT NULL) )";
$params = ['f' => $from . ' 00:00:00', 't' => $toExcl . ' 00:00:00'];
if ($sellerPick > 0)      { $where .= " AND $sellerSql = :sp"; $params['sp'] = $sellerPick; }
elseif ($sellerPick < 0)  { $where .= " AND $sellerSql IS NULL"; }
if ($type === 'online')   { $where .= " AND $sellerSql IS NULL"; }
elseif ($type === 'agent')   { $where .= " AND a.role = 'agent'"; }
elseif ($type === 'counter') { $where .= " AND a.role = 'counter'"; }
elseif ($type === 'staff')   { $where .= " AND a.id IS NOT NULL AND a.role NOT IN ('agent','counter')"; }

$seatSql = "GREATEST(1, COALESCE((SELECT SUM(l.seat_count) FROM booking_legs l WHERE l.booking_id = b.id AND l.leg_type = 'outbound'), 0))";
$from_   = "FROM bookings b LEFT JOIN admins a ON a.id = $sellerSql";

/* ---- Details (opened on click) -------------------------------------- */
if (($_GET['ajax'] ?? '') === 'rows') {
    $d = Security::clean($_GET['d'] ?? '', 10);
    $s = (string) ($_GET['s'] ?? '');
    if (!$isDate($d)) { http_response_code(400); exit('bad date'); }
    $w = $where . ' AND DATE(b.created_at) = :d';
    $p = $params + ['d' => $d];
    if ($s === 'online') { $w .= " AND $sellerSql IS NULL"; }
    elseif ((int) $s > 0) { $w .= " AND $sellerSql = :s1"; $p['s1'] = (int) $s; }
    $rows = Database::fetchAll(
        "SELECT b.id, b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at, $seatSql AS seats,
                (SELECT COALESCE(NULLIF(x.full_name, ''), x.username) FROM admins x WHERE x.id = b.sold_by_admin_id) AS agent_name,
                (SELECT COALESCE(NULLIF(y.full_name, ''), y.username) FROM admins y WHERE y.id = $cutSql) AS cut_name,
                (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS pax,
                (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ', ') FROM booking_seats bs JOIN booking_legs l2 ON l2.id = bs.leg_id
                  WHERE bs.booking_id = b.id AND l2.leg_type = 'outbound') AS seat_nos,
                (SELECT CONCAT(l.travel_date, '|', COALESCE(NULLIF(l.boarding_stop, ''), r.from_city, ''), '|', COALESCE(NULLIF(l.drop_stop, ''), r.to_city, ''))
                   FROM booking_legs l LEFT JOIN schedules sc ON sc.id = l.schedule_id LEFT JOIN routes r ON r.id = sc.route_id
                  WHERE l.booking_id = b.id ORDER BY l.leg_type = 'outbound' DESC, l.id LIMIT 1) AS trip
           $from_ WHERE $w ORDER BY b.created_at",
        $p
    );
    header('Content-Type: text/html; charset=utf-8');
    if ($rows === []) { echo '<p class="pc-note">No ticket.</p>'; exit; }
    echo '<div style="overflow-x:auto"><table class="pc-table ts-det"><thead><tr><th>#</th><th>Time</th><th>PNR</th><th>Passenger</th><th>Journey</th><th>Seats</th><th>Amount</th><th>Agent name · Cut by</th><th>Status</th></tr></thead><tbody>';
    $i = 0;
    foreach ($rows as $r) {
        $i++;
        [$td, $bf, $dr] = array_pad(explode('|', (string) $r['trip']), 3, '');
        $cx   = (string) $r['status'] === 'cancelled';
        $st   = match ((string) $r['status']) {
            'cancelled' => pc_badge('Cancelled', 'bad'), 'pending' => pc_badge('Pending', 'warn'),
            'completed' => pc_badge('Travelled', 'info'), default => pc_badge('Confirmed', 'ok'),
        };
        echo '<tr' . ($cx ? ' class="ts-cx"' : '') . '><td>' . $i . '</td>'
           . '<td>' . $e(date('h:i A', strtotime((string) $r['created_at']))) . '</td>'
           . '<td><a href="/admin/booking-view.php?pnr=' . rawurlencode((string) $r['pnr']) . '"><b>' . $e($r['pnr']) . '</b></a></td>'
           . '<td>' . $e($r['pax'] ?: '—') . '<br><small class="pc-note">' . $e($r['contact_phone']) . '</small></td>'
           . '<td>' . ($bf . $dr !== '' ? $e($bf) . ' → ' . $e($dr) : '—') . ($td !== '' ? '<br><small class="pc-note">' . $e(formatDate($td, 'j M Y')) . '</small>' : '') . '</td>'
           . '<td><b>' . (int) $r['seats'] . '</b>' . ($r['seat_nos'] ? '<br><small class="pc-note">' . $e($r['seat_nos']) . '</small>' : '') . '</td>'
           . '<td>' . $e(inr((float) $r['total_amount'])) . '</td>'
           . '<td><b>' . $e($r['agent_name'] ?: 'Online') . '</b><br><small class="pc-note">cut by ' . $e($r['cut_name'] ?: 'customer (online)') . '</small></td>'
           . '<td>' . $st . '</td></tr>';
    }
    echo '</tbody></table></div>';
    exit;
}

/* ---- Day x person totals ------------------------------------------------ */
$grid = Database::fetchAll(
    "SELECT DATE(b.created_at) AS d, $sellerSql AS sid, a.full_name, a.username, a.role,
            SUM(CASE WHEN b.status <> 'cancelled' THEN $seatSql ELSE 0 END) AS tickets,
            SUM(CASE WHEN b.status <> 'cancelled' THEN 1 ELSE 0 END) AS bookings,
            SUM(CASE WHEN b.status <> 'cancelled' THEN b.total_amount ELSE 0 END) AS amount,
            SUM(CASE WHEN b.status = 'cancelled' THEN $seatSql ELSE 0 END) AS cancelled
       $from_ WHERE $where
      GROUP BY DATE(b.created_at), $sellerSql, a.full_name, a.username, a.role",
    $params
);

$byDay = []; $byPerson = []; $tot = ['tickets' => 0, 'bookings' => 0, 'amount' => 0.0, 'cancelled' => 0];
foreach ($grid as $g) {
    $sid  = (int) ($g['sid'] ?? 0);
    $key  = $sid > 0 ? (string) $sid : 'online';
    $name = $sid > 0 ? (string) ($g['full_name'] ?: $g['username'] ?: ('#' . $sid)) : 'Online · Website / App';
    $row  = ['key' => $key, 'sid' => $sid, 'name' => $name, 'type' => $typeOf($g['role'] ?? null, $sid),
             'tickets' => (int) $g['tickets'], 'bookings' => (int) $g['bookings'], 'amount' => (float) $g['amount'], 'cancelled' => (int) $g['cancelled']];
    $byDay[(string) $g['d']][] = $row;
    if (!isset($byPerson[$key])) { $byPerson[$key] = $row; $byPerson[$key]['days'] = 0; }
    else { foreach (['tickets', 'bookings', 'amount', 'cancelled'] as $k) { $byPerson[$key][$k] += $row[$k]; } }
    if ($row['tickets'] > 0) { $byPerson[$key]['days']++; }
    foreach (['tickets', 'bookings', 'amount', 'cancelled'] as $k) { $tot[$k] += $row[$k]; }
}
krsort($byDay);
$rank = static fn(array $x, array $y): int => [$y['tickets'], $y['amount']] <=> [$x['tickets'], $x['amount']];
foreach ($byDay as &$list) { usort($list, $rank); }
unset($list);
uasort($byPerson, $rank);

/* ---- Commission earned in the window (credited agent, from the ledger) ---- */
$commBy = [];
try {
    $cp = ['f' => $params['f'], 't' => $params['t']];
    $cw = '';
    if ($scope !== null) { $cw = ' AND agent_admin_id = :me'; $cp['me'] = $scope; }
    foreach (Database::fetchAll(
        "SELECT agent_admin_id AS a, COALESCE(SUM(amount), 0) AS s FROM agent_ledger
          WHERE account = 'commission' AND entry_type IN ('commission','commission_void')
            AND created_at >= :f AND created_at < :t$cw GROUP BY agent_admin_id", $cp) as $c) {
        $commBy[(int) $c['a']] = round((float) $c['s'], 2);
    }
} catch (Throwable $ex) { $commBy = []; }

/* One agent's commission card: rate (editable for the office), earned in
   this period, due now, and how to take it out. */
$agentCard = static function (int $aid, string $name, bool $self) use ($e, $rateOf, $payoutOk, $commBy, $canComm, $period): string {
    [$amt, $lbl, $src] = $rateOf($aid);
    $due   = AgentWallet::balances($aid)['commission'] ?? 0.0;
    $open  = AgentWallet::openPayoutRequests($aid);
    $okPay = $payoutOk($aid);
    $srcTx = ['company' => 'company rate', 'own' => 'own rate', 'terms' => 'agreement rate'][$src] ?? '';
    $h  = '<section class="pc-card ts-day ts-comm"><div class="pc-card-h"><h3>' . ($self ? 'My commission · मेरो कमिसन' : 'Commission · ' . $e($name)) . '</h3>'
        . '<small>' . $e($srcTx) . '</small></div><div class="pc-card-b">';
    $h .= '<div class="pc-kpis" style="margin:0 0 10px">'
        . '<div class="pc-kpi"><small>Per head · प्रति यात्री</small><b>' . $e($lbl) . '</b><i>new tickets</i></div>'
        . '<div class="pc-kpi ok"><small>Earned · कमाइ</small><b>' . $e(inr($commBy[$aid] ?? 0)) . '</b><i>' . $e($period) . '</i></div>'
        . '<div class="pc-kpi warn"><small>Can withdraw · निकाल्न मिल्ने</small><b>' . $e(inr(max(0, (float) $due))) . '</b><i>commission due now</i></div></div>';
    if ($canComm && !$self) {
        $h .= '<form method="post" class="pc-form ts-pick">' . Security::csrfField()
            . '<input type="hidden" name="act" value="rate"><input type="hidden" name="id" value="' . $aid . '">'
            . '<label>₹ per passenger <input class="pc-in" name="rate" inputmode="numeric" style="width:110px" value="' . ($src !== 'company' ? (int) $amt : '') . '" placeholder="' . (int) AgentWallet::flatRateFor($aid) . '"></label>'
            . '<button class="pc-btn sm pri" type="submit">' . pc_icon('check') . 'Save rate</button>'
            . '<small class="pc-note">Blank = company rate. Badhauna / ghatauna jati bela pani — next ticket dekhi lagu.</small></form>';
    }
    if ($open !== []) {
        $h .= '<p class="pc-note" style="margin-top:8px">' . pc_badge('Withdrawal requested', 'warn') . ' ' . $e(inr((float) $open[0]['amount'])) . ' · ' . $e(formatDate(substr((string) $open[0]['created_at'], 0, 10), 'j M')) . ' — '
            . ($self ? 'office le pathaune kram ma chha.' : '<a href="/admin/agent-360.php?agent=' . $aid . '">Pay now →</a>') . '</p>';
    } elseif ($self) {
        if (!$okPay) {
            $h .= '<p class="pc-note" style="margin-top:8px">' . pc_badge('Bank / UPI not verified', 'bad') . ' Office lai bank / UPI details verify garna bhannus, tyaspachhi jati bela pani nikalna milchha.</p>';
        } elseif ($due > 0) {
            $h .= '<form method="post" class="pc-form ts-pick" style="margin-top:8px">' . Security::csrfField()
                . '<input type="hidden" name="act" value="withdraw">'
                . '<label>Amount <input class="pc-in" name="amount" inputmode="numeric" style="width:130px" placeholder="' . (int) $due . ' (all)"></label>'
                . '<button class="pc-btn pri" type="submit" onclick="return confirm(\'Withdraw request pathaune?\')">' . pc_icon('wallet') . 'Withdraw · निकाल्नुहोस्</button>'
                . '<small class="pc-note">Khali = sabai.</small></form>';
        }
    } else {
        $h .= '<p class="pc-note" style="margin-top:8px">' . ($okPay ? pc_badge('Can withdraw any time', 'ok') : pc_badge('Bank / UPI not verified — cannot withdraw', 'bad') . ' <a href="/admin/team.php">Verify on Team →</a>')
            . ' · <a href="/admin/agent-360.php?agent=' . $aid . '">Wallet &amp; payouts →</a></p>';
    }
    return $h . '</div></section>';
};

/* ---- CSV download -------------------------------------------------------- */
if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ticket-statement-' . $from . '-to-' . $toIncl . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'No.', 'Name', 'Type', 'Tickets', 'Bookings', 'Amount (INR)', 'Cancelled tickets']);
    foreach ($byDay as $d => $list) {
        $n = 0;
        foreach ($list as $r) { fputcsv($out, [$d, ++$n, $r['name'], $typeLabel[$r['type']], $r['tickets'], $r['bookings'], number_format($r['amount'], 2, '.', ''), $r['cancelled']]); }
    }
    fputcsv($out, []);
    fputcsv($out, ['TOTAL', '', '', '', $tot['tickets'], $tot['bookings'], number_format($tot['amount'], 2, '.', ''), $tot['cancelled']]);
    fclose($out);
    exit;
}

/* ---- People for the picker (office only) ---------------------------------- */
$people = $scope === null
    ? Database::fetchAll("SELECT id, full_name, username, role FROM admins WHERE is_active = 1 ORDER BY role = 'agent' DESC, full_name")
    : [];

$keep = ['range' => $range, 'type' => $type, 'by' => $by];
if ($range === 'custom') { $keep['from'] = $from; $keep['to'] = $toIncl; }
if ($sellerPick > 0) { $keep['seller'] = (string) $sellerPick; } elseif ($sellerPick < 0) { $keep['seller'] = 'online'; }
$q = static fn(array $over): string => '?' . http_build_query(array_filter($over + $keep, static fn($v) => $v !== null && $v !== ''));

admin_header('Ticket Statement', 'ticket-statement');
?>
<style>
.ts-chips{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px}
.ts-chips a{padding:6px 12px;border:1px solid var(--line,#d5dbe5);border-radius:999px;text-decoration:none;color:inherit;font-size:13px;background:var(--card,#fff)}
.ts-chips a.on{background:#0b3b8c;border-color:#0b3b8c;color:#fff}
.ts-day{margin:0 0 12px}
.ts-day > .pc-card-h{display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap}
.ts-row{border-top:1px solid var(--line,#e5e9f0)}
.ts-row > summary{list-style:none;cursor:pointer;display:grid;grid-template-columns:28px 1fr auto auto;gap:10px;align-items:center;padding:10px 14px}
.ts-row > summary::-webkit-details-marker{display:none}
.ts-row > summary:hover{background:rgba(11,59,140,.05)}
.ts-row[open] > summary{background:rgba(11,59,140,.07)}
.ts-n{font-weight:700;color:#64748b;text-align:right}
.ts-nm b{display:block}
.ts-big{font-size:18px;font-weight:800;white-space:nowrap}
.ts-big small{font-size:12px;font-weight:500;color:#64748b}
.ts-amt{white-space:nowrap;color:#475569;font-size:13px;text-align:right}
.ts-body{padding:4px 14px 14px}
.ts-det td,.ts-det th{font-size:13px}
.ts-cx td{opacity:.55;text-decoration:line-through}
.ts-cx td:last-child{text-decoration:none}
.ts-pick{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
@media (max-width:560px){.ts-row > summary{grid-template-columns:22px 1fr auto}.ts-amt{display:none}}
@media print{.pc-head-a,.ts-chips,.ts-pick,nav,aside,.sidebar{display:none!important}.ts-row > summary{padding:6px 8px}}
</style>
<div class="pc">
<?= pc_head('Ticket Statement · कसले कति टिकट काट्यो',
    $period . ($by === 'agent' ? ($scope !== null ? ' — tickets in your name' : ' — tickets by the agent name on the ticket, day by day') : ($scope !== null ? ' — tickets you cut' : ' — who cut how many tickets, day by day')),
    '<a class="pc-btn" href="' . $e($q(['format' => 'csv'])) . '">' . pc_icon('download') . 'Excel / CSV</a>'
  . '<button class="pc-btn" type="button" onclick="tsOpenAll().then(function(){window.print()})">' . pc_icon('printer') . 'Print</button>') ?>

<div class="ts-chips" aria-label="Count by">
  <a href="<?= $e($q(['by' => 'agent'])) ?>" class="<?= $by === 'agent' ? 'on' : '' ?>">Agent name on ticket · कसको नाममा</a>
  <a href="<?= $e($q(['by' => 'cut'])) ?>" class="<?= $by === 'cut' ? 'on' : '' ?>">Who cut it · कसले काट्यो</a>
</div>
<div class="ts-chips" aria-label="Period">
  <?php foreach ($presets as $k => $lbl): if ($k === 'custom') { continue; } ?>
    <a href="<?= $e($q(['range' => $k, 'from' => null, 'to' => null])) ?>" class="<?= $range === $k ? 'on' : '' ?>"><?= $e($lbl) ?></a>
  <?php endforeach; ?>
</div>
<form method="get" class="pc-form ts-pick" style="margin:0 0 10px">
  <input type="hidden" name="range" value="custom"><input type="hidden" name="type" value="<?= $e($type) ?>"><input type="hidden" name="by" value="<?= $e($by) ?>">
  <?php if (isset($keep['seller'])): ?><input type="hidden" name="seller" value="<?= $e($keep['seller']) ?>"><?php endif; ?>
  <label>From <input class="pc-in" type="date" name="from" value="<?= $e($from) ?>"></label>
  <label>To <input class="pc-in" type="date" name="to" value="<?= $e($toIncl) ?>"></label>
  <button class="pc-btn sm" type="submit"><?= pc_icon('check') ?>Show</button>
</form>

<?php if ($scope === null): ?>
<div class="ts-chips" aria-label="Who">
  <?php foreach ($types as $k => $lbl): ?>
    <a href="<?= $e($q(['type' => $k, 'seller' => null])) ?>" class="<?= $type === $k && $sellerPick === 0 ? 'on' : '' ?>"><?= $e($lbl) ?></a>
  <?php endforeach; ?>
</div>
<form method="get" class="pc-form ts-pick" style="margin:0 0 12px">
  <?php foreach ($keep as $k => $v): if ($k === 'seller' || $k === 'type') { continue; } ?><input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>"><?php endforeach; ?>
  <select class="pc-in" name="seller" onchange="this.form.submit()" aria-label="One person" style="min-width:240px">
    <option value="">Everyone · सबै</option>
    <option value="online"<?= $sellerPick < 0 ? ' selected' : '' ?>>Online · Website / App</option>
    <?php foreach ($people as $p): $pt = $typeOf($p['role'], (int) $p['id']); ?>
      <option value="<?= (int) $p['id'] ?>"<?= $sellerPick === (int) $p['id'] ? ' selected' : '' ?>><?= $e(($p['full_name'] ?: $p['username']) . ' · ' . $typeLabel[$pt]) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($sellerPick !== 0): ?><a class="pc-btn sm" href="<?= $e($q(['seller' => null])) ?>"><?= pc_icon('x') ?>Clear</a><?php endif; ?>
</form>
<?php endif; ?>

<?php if (is_array($flash)): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e(admin_plain((string) $flash[1])) ?></div><?php endif; ?>

<?php if ($scope !== null && $isAgent): ?>
  <?= $agentCard($selfId, '', true) ?>
<?php elseif ($sellerPick > 0 && Database::exists("SELECT 1 FROM admins WHERE id = :i AND role = 'agent'", ['i' => $sellerPick])): ?>
  <?= $agentCard($sellerPick, (string) Database::scalar('SELECT COALESCE(NULLIF(full_name, \'\'), username) FROM admins WHERE id = :i', ['i' => $sellerPick], ''), false) ?>
<?php endif; ?>

<?php if ($canComm && $sellerPick === 0): ?>
<form method="post" class="pc-card pc-card-b pc-form ts-pick" style="margin:0 0 12px">
  <?= Security::csrfField() ?><input type="hidden" name="act" value="company_rate">
  <b>Agent commission · कमिसन (company rate)</b>
  <label>Direct ₹ <input class="pc-in" name="direct" inputmode="numeric" style="width:90px" value="<?= (int) Settings::getFloat('agent_flat_direct', 200.0) ?>"></label>
  <label>Team ₹ <input class="pc-in" name="joint" inputmode="numeric" style="width:90px" value="<?= (int) Settings::getFloat('agent_flat_joint', 400.0) ?>"></label>
  <span class="pc-note">per passenger</span>
  <button class="pc-btn sm pri" type="submit" onclick="return confirm('Company commission change garne? Next ticket dekhi lagu hunchha.')"><?= pc_icon('check') ?>Save</button>
  <small class="pc-note">One agent different? Click the agent's name below and set their own ₹.</small>
</form>
<?php endif; ?>

<div class="pc-kpis">
  <div class="pc-kpi ok"><small>Tickets cut · टिकट</small><b><?= (int) $tot['tickets'] ?></b><i><?= (int) $tot['bookings'] ?> bookings</i></div>
  <div class="pc-kpi"><small>Amount · रकम</small><b><?= $e(inr($tot['amount'])) ?></b><i><?= $e($period) ?></i></div>
  <div class="pc-kpi"><small>People · जना</small><b><?= count(array_filter($byPerson, static fn($p) => $p['tickets'] > 0)) ?></b><i><?= $by === 'agent' ? 'names on tickets' : 'who cut tickets' ?></i></div>
  <div class="pc-kpi bad"><small>Cancelled</small><b><?= (int) $tot['cancelled'] ?></b><i>not in the total</i></div>
</div>

<?php if ($byDay === []): ?>
  <div class="pc-card pc-empty">No ticket in <?= $e($period) ?>.</div>
<?php else: ?>

<?php if ($scope === null && $sellerPick === 0 && $byPerson !== []): ?>
<section class="pc-card ts-day">
  <div class="pc-card-h"><h3>Total by person · जम्मा</h3><small><?= $e($period) ?> — click a name to see only their days</small></div>
  <?php $n = 0; foreach ($byPerson as $p): ?>
    <a class="ts-row" style="display:block;text-decoration:none;color:inherit" href="<?= $e($q(['seller' => $p['sid'] > 0 ? (string) $p['sid'] : 'online'])) ?>">
      <div style="display:grid;grid-template-columns:28px 1fr auto auto;gap:10px;align-items:center;padding:10px 14px">
        <span class="ts-n"><?= ++$n ?>.</span>
        <span class="ts-nm"><b><?= $e($p['name']) ?></b><?= pc_badge($typeLabel[$p['type']], $typeTone[$p['type']]) ?> <small class="pc-note"><?= (int) $p['days'] ?> day<?= $p['days'] === 1 ? '' : 's' ?></small>
          <?php if ($p['type'] === 'agent'): ?><br><small class="pc-note">Commission <?= $e($rateOf($p['sid'])[1]) ?> · earned <?= $e(inr($commBy[$p['sid']] ?? 0)) ?> · due <?= $e(inr(max(0, (float) (AgentWallet::balances($p['sid'])['commission'] ?? 0)))) ?></small><?php endif; ?></span>
        <span class="ts-big"><?= (int) $p['tickets'] ?> <small>ticket<?= $p['tickets'] === 1 ? '' : 's' ?></small></span>
        <span class="ts-amt"><?= $e(inr($p['amount'])) ?><?= $p['cancelled'] > 0 ? '<br><small style="color:#b91c1c">' . (int) $p['cancelled'] . ' cancelled</small>' : '' ?></span>
      </div>
    </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<h3 style="margin:16px 0 8px">Date-wise · मितिअनुसार</h3>
<?php foreach ($byDay as $d => $list):
    $dt = array_sum(array_column($list, 'tickets')); $da = array_sum(array_column($list, 'amount')); ?>
<section class="pc-card ts-day">
  <div class="pc-card-h"><h3><?= $e(formatDate($d, 'j M Y, l')) ?><?= $d === $today ? ' · Today' : '' ?></h3><small><b><?= (int) $dt ?> ticket<?= (int) $dt === 1 ? '' : 's' ?></b> · <?= $e(inr($da)) ?></small></div>
  <?php $n = 0; foreach ($list as $r): ?>
    <details class="ts-row" data-d="<?= $e($d) ?>" data-s="<?= $e($r['key']) ?>">
      <summary>
        <span class="ts-n"><?= ++$n ?>.</span>
        <span class="ts-nm"><b><?= $e($r['name']) ?></b><?= pc_badge($typeLabel[$r['type']], $typeTone[$r['type']]) ?><?= $r['cancelled'] > 0 ? ' <small style="color:#b91c1c">' . (int) $r['cancelled'] . ' cancelled</small>' : '' ?></span>
        <span class="ts-big"><?= (int) $r['tickets'] ?> <small>ticket<?= $r['tickets'] === 1 ? '' : 's' ?></small></span>
        <span class="ts-amt"><?= $e(inr($r['amount'])) ?><br><small><?= (int) $r['bookings'] ?> booking<?= $r['bookings'] === 1 ? '' : 's' ?></small></span>
      </summary>
      <div class="ts-body"><p class="pc-note">Loading…</p></div>
    </details>
  <?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
</div>
<script>
(function () {
  var base = <?= json_encode($keep, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  function load(det) {
    if (det.dataset.done) { return Promise.resolve(); }
    det.dataset.done = '1';
    var p = new URLSearchParams(base); p.set('ajax', 'rows'); p.set('d', det.dataset.d); p.set('s', det.dataset.s);
    return fetch('?' + p.toString(), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
      .then(function (h) { det.querySelector('.ts-body').innerHTML = h; })
      .catch(function () { det.dataset.done = ''; det.querySelector('.ts-body').innerHTML = '<p class="pc-note">Could not load — open again.</p>'; });
  }
  document.querySelectorAll('details.ts-row').forEach(function (det) {
    det.addEventListener('toggle', function () { if (det.open) { load(det); } });
  });
  window.tsOpenAll = function () {
    var all = [];
    document.querySelectorAll('details.ts-row').forEach(function (det) { det.open = true; all.push(load(det)); });
    return Promise.all(all);
  };
})();
</script>
<?php admin_footer();
