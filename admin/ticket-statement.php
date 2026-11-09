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
$sellerSql   = $hasBookedBy ? 'COALESCE(b.booked_by_admin_id, b.sold_by_admin_id)' : 'b.sold_by_admin_id';

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
                 OR (b.status = 'pending' AND $sellerSql IS NOT NULL) )";
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
                (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS pax,
                (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ', ') FROM booking_seats bs JOIN booking_legs l2 ON l2.id = bs.leg_id
                  WHERE bs.booking_id = b.id AND l2.leg_type = 'outbound') AS seat_nos,
                (SELECT CONCAT(l.travel_date, '|', COALESCE(l.boarding_stop, r.from_city, ''), '|', COALESCE(l.drop_stop, r.to_city, ''))
                   FROM booking_legs l LEFT JOIN schedules sc ON sc.id = l.schedule_id LEFT JOIN routes r ON r.id = sc.route_id
                  WHERE l.booking_id = b.id ORDER BY l.leg_type = 'outbound' DESC, l.id LIMIT 1) AS trip
           $from_ WHERE $w ORDER BY b.created_at",
        $p
    );
    header('Content-Type: text/html; charset=utf-8');
    if ($rows === []) { echo '<p class="pc-note">No ticket.</p>'; exit; }
    echo '<div style="overflow-x:auto"><table class="pc-table ts-det"><thead><tr><th>#</th><th>Time</th><th>PNR</th><th>Passenger</th><th>Journey</th><th>Seats</th><th>Amount</th><th>Status</th></tr></thead><tbody>';
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
           . '<td>' . $e(inr((float) $r['total_amount'])) . '</td><td>' . $st . '</td></tr>';
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

$keep = ['range' => $range, 'type' => $type];
if ($range === 'custom') { $keep['from'] = $from; $keep['to'] = $toIncl; }
if ($sellerPick > 0) { $keep['seller'] = (string) $sellerPick; } elseif ($sellerPick < 0) { $keep['seller'] = 'online'; }
$q = static fn(array $over): string => '?' . http_build_query(array_filter($over + $keep, static fn($v) => $v !== null && $v !== ''));
$period = $from === $toIncl ? formatDate($from, 'j M Y') : formatDate($from, 'j M') . ' – ' . formatDate($toIncl, 'j M Y');

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
    $period . ($scope !== null ? ' — tickets you cut' : ' — who cut how many tickets, day by day'),
    '<a class="pc-btn" href="' . $e($q(['format' => 'csv'])) . '">' . pc_icon('download') . 'Excel / CSV</a>'
  . '<button class="pc-btn" type="button" onclick="tsOpenAll().then(function(){window.print()})">' . pc_icon('printer') . 'Print</button>') ?>

<div class="ts-chips" aria-label="Period">
  <?php foreach ($presets as $k => $lbl): if ($k === 'custom') { continue; } ?>
    <a href="<?= $e($q(['range' => $k, 'from' => null, 'to' => null])) ?>" class="<?= $range === $k ? 'on' : '' ?>"><?= $e($lbl) ?></a>
  <?php endforeach; ?>
</div>
<form method="get" class="pc-form ts-pick" style="margin:0 0 10px">
  <input type="hidden" name="range" value="custom"><input type="hidden" name="type" value="<?= $e($type) ?>">
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

<div class="pc-kpis">
  <div class="pc-kpi ok"><small>Tickets cut · टिकट</small><b><?= (int) $tot['tickets'] ?></b><i><?= (int) $tot['bookings'] ?> bookings</i></div>
  <div class="pc-kpi"><small>Amount · रकम</small><b><?= $e(inr($tot['amount'])) ?></b><i><?= $e($period) ?></i></div>
  <div class="pc-kpi"><small>People · जना</small><b><?= count(array_filter($byPerson, static fn($p) => $p['tickets'] > 0)) ?></b><i>who cut tickets</i></div>
  <div class="pc-kpi bad"><small>Cancelled</small><b><?= (int) $tot['cancelled'] ?></b><i>not in the total</i></div>
</div>

<?php if ($byDay === []): ?>
  <div class="pc-card pc-empty">No ticket in <?= $e($period) ?>.</div>
<?php else: ?>

<?php if ($scope === null && $sellerPick === 0 && count($byPerson) > 1): ?>
<section class="pc-card ts-day">
  <div class="pc-card-h"><h3>Total by person · जम्मा</h3><small><?= $e($period) ?> — click a name to see only their days</small></div>
  <?php $n = 0; foreach ($byPerson as $p): ?>
    <a class="ts-row" style="display:block;text-decoration:none;color:inherit" href="<?= $e($q(['seller' => $p['sid'] > 0 ? (string) $p['sid'] : 'online'])) ?>">
      <div style="display:grid;grid-template-columns:28px 1fr auto auto;gap:10px;align-items:center;padding:10px 14px">
        <span class="ts-n"><?= ++$n ?>.</span>
        <span class="ts-nm"><b><?= $e($p['name']) ?></b><?= pc_badge($typeLabel[$p['type']], $typeTone[$p['type']]) ?> <small class="pc-note"><?= (int) $p['days'] ?> day<?= $p['days'] === 1 ? '' : 's' ?></small></span>
        <span class="ts-big"><?= (int) $p['tickets'] ?> <small>tickets</small></span>
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
