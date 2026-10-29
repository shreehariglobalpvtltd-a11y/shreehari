<?php
/**
 * admin/pnl.php — Profit & loss by route and by agent (29 Oct 2026,
 * management upgrade 4.4 "Route-wise P&L, Agent-wise P&L").
 *
 * ROUTE (by TRAVEL date, the day the coach ran):
 *   revenue      booking_legs.leg_total of confirmed / completed tickets on
 *                the route's departures in the window (a return ticket's
 *                two legs land on their own routes)
 *   commission   the agents' commission on those tickets (agent_ledger,
 *                earned less reversed — the money the company gives away)
 *   expenses     trip_expenses: diesel, toll, driver pay, misc
 *   net          revenue − commission − expenses
 * AGENT (by BOOKING date, the day they sold):
 *   revenue, commission (ledger, net of reversals), and what the company
 *   keeps; plus the cash they still hold right now.
 *
 * Read-only. reports.view. CSV of either table.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('reports.view');

$e    = static fn($v): string => Security::e((string) $v);
$to   = Security::isValidDate((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : todayISO();
$from = Security::isValidDate((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-01', (int) strtotime($to));
if ($from > $to) { [$from, $to] = [$to, $from]; }
$toEnd = addDaysISO($to, 1);

$safe = static function (string $sql, array $p): array {
    try {
        return Database::fetchAll($sql, $p);
    } catch (Throwable $ex) {
        Logger::warning('pnl query failed: ' . $ex->getMessage());
        return [];
    }
};

/* ---- By route ---------------------------------------------------------- */
$routes = [];
foreach (Database::fetchAll('SELECT id, route_code, from_city, to_city, is_active FROM routes ORDER BY is_active DESC, id') as $r) {
    $routes[(int) $r['id']] = $r + ['trips' => 0, 'tickets' => 0, 'seats' => 0, 'revenue' => 0.0, 'commission' => 0.0,
        'diesel' => 0.0, 'toll' => 0.0, 'driver' => 0.0, 'misc' => 0.0, 'expenses' => 0.0];
}
foreach ($safe("SELECT route_id, COUNT(*) AS n FROM schedules WHERE travel_date BETWEEN :f AND :t AND status <> 'cancelled' GROUP BY route_id",
               ['f' => $from, 't' => $to]) as $r) {
    if (isset($routes[(int) $r['route_id']])) { $routes[(int) $r['route_id']]['trips'] = (int) $r['n']; }
}
foreach ($safe("SELECT s.route_id, COUNT(DISTINCT b.id) AS tickets, COALESCE(SUM(bl.seat_count), 0) AS seats, COALESCE(SUM(bl.leg_total), 0) AS revenue
                  FROM booking_legs bl
                  JOIN schedules s ON s.id = bl.schedule_id
                  JOIN bookings b  ON b.id = bl.booking_id
                 WHERE b.status IN ('confirmed','completed') AND bl.travel_date BETWEEN :f AND :t
                 GROUP BY s.route_id", ['f' => $from, 't' => $to]) as $r) {
    if (isset($routes[(int) $r['route_id']])) {
        $routes[(int) $r['route_id']]['tickets'] = (int) $r['tickets'];
        $routes[(int) $r['route_id']]['seats']   = (int) $r['seats'];
        $routes[(int) $r['route_id']]['revenue'] = round((float) $r['revenue'], 2);
    }
}
foreach ($safe("SELECT s.route_id, COALESCE(SUM(l.amount), 0) AS comm
                  FROM agent_ledger l
                  JOIN booking_legs bl ON bl.booking_id = l.booking_id AND bl.leg_type = 'outbound'
                  JOIN schedules s ON s.id = bl.schedule_id
                 WHERE l.account = 'commission' AND l.entry_type IN ('commission','commission_void')
                   AND bl.travel_date BETWEEN :f AND :t
                 GROUP BY s.route_id", ['f' => $from, 't' => $to]) as $r) {
    if (isset($routes[(int) $r['route_id']])) { $routes[(int) $r['route_id']]['commission'] = round((float) $r['comm'], 2); }
}
foreach ($safe('SELECT route_id, SUM(diesel) AS diesel, SUM(toll) AS toll, SUM(driver_pay) AS driver, SUM(misc) AS misc, SUM(total) AS total
                  FROM trip_expenses WHERE expense_date BETWEEN :f AND :t GROUP BY route_id', ['f' => $from, 't' => $to]) as $r) {
    if (isset($routes[(int) $r['route_id']])) {
        foreach (['diesel', 'toll', 'driver', 'misc'] as $k) { $routes[(int) $r['route_id']][$k] = round((float) $r[$k], 2); }
        $routes[(int) $r['route_id']]['expenses'] = round((float) $r['total'], 2);
    }
}
$routes = array_values(array_filter($routes, static fn(array $r): bool => (int) $r['is_active'] === 1 || $r['tickets'] > 0 || $r['expenses'] > 0));
foreach ($routes as &$r) {
    $r['net'] = round($r['revenue'] - $r['commission'] - $r['expenses'], 2);
}
unset($r);
$rt = ['trips' => 0, 'tickets' => 0, 'seats' => 0, 'revenue' => 0.0, 'commission' => 0.0, 'expenses' => 0.0, 'net' => 0.0];
foreach ($routes as $r) { foreach ($rt as $k => $_) { $rt[$k] += $r[$k]; } }

/* ---- By agent ---------------------------------------------------------- */
$agents = $safe("SELECT a.id, a.full_name, a.username,
                        COUNT(b.id) AS tickets,
                        COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_amount ELSE 0 END), 0) AS revenue,
                        COALESCE(SUM(b.status = 'cancelled'), 0) AS cancelled
                   FROM admins a
                   JOIN bookings b ON b.sold_by_admin_id = a.id AND b.created_at >= :f AND b.created_at < :t
                  WHERE a.role = 'agent'
                  GROUP BY a.id, a.full_name, a.username", ['f' => $from, 't' => $toEnd]);
$comm = [];
foreach ($safe("SELECT l.agent_admin_id AS id, COALESCE(SUM(l.amount), 0) AS c
                  FROM agent_ledger l JOIN bookings b ON b.id = l.booking_id
                 WHERE l.account = 'commission' AND l.entry_type IN ('commission','commission_void')
                   AND b.created_at >= :f AND b.created_at < :t
                 GROUP BY l.agent_admin_id", ['f' => $from, 't' => $toEnd]) as $c) {
    $comm[(int) $c['id']] = round((float) $c['c'], 2);
}
$cashNow = [];
foreach ($safe("SELECT agent_admin_id AS id, COALESCE(SUM(amount), 0) AS bal FROM agent_ledger WHERE account = 'cash' GROUP BY agent_admin_id", []) as $c) {
    $cashNow[(int) $c['id']] = round((float) $c['bal'], 2);
}
foreach ($agents as &$a) {
    $a['revenue']    = round((float) $a['revenue'], 2);
    $a['commission'] = $comm[(int) $a['id']] ?? 0.0;
    $a['company']    = round($a['revenue'] - $a['commission'], 2);
    $a['cash']       = max(0.0, $cashNow[(int) $a['id']] ?? 0.0);
}
unset($a);
usort($agents, static fn(array $x, array $y): int => $y['revenue'] <=> $x['revenue']);

/* ---- CSV ---------------------------------------------------------------- */
$csv = (string) ($_GET['export'] ?? '');
if ($csv === 'routes' || $csv === 'agents') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pnl-' . $csv . '-' . $from . '_to_' . $to . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($csv === 'routes') {
        fputcsv($out, ['Route', 'Code', 'Trips', 'Tickets', 'Seats', 'Revenue', 'Agent commission', 'Diesel', 'Toll', 'Driver pay', 'Misc', 'Expenses', 'Net']);
        foreach ($routes as $r) {
            fputcsv($out, [$r['from_city'] . ' → ' . $r['to_city'], $r['route_code'], $r['trips'], $r['tickets'], $r['seats'],
                number_format($r['revenue'], 2, '.', ''), number_format($r['commission'], 2, '.', ''), number_format($r['diesel'], 2, '.', ''),
                number_format($r['toll'], 2, '.', ''), number_format($r['driver'], 2, '.', ''), number_format($r['misc'], 2, '.', ''),
                number_format($r['expenses'], 2, '.', ''), number_format($r['net'], 2, '.', '')]);
        }
    } else {
        fputcsv($out, ['Agent', 'Code', 'Tickets', 'Cancelled', 'Revenue', 'Commission', 'Company keeps', 'Cash held now']);
        foreach ($agents as $a) {
            fputcsv($out, [$a['full_name'] ?: $a['username'], AgentWallet::agentCodeLabel((int) $a['id']), (int) $a['tickets'], (int) $a['cancelled'],
                number_format($a['revenue'], 2, '.', ''), number_format($a['commission'], 2, '.', ''), number_format($a['company'], 2, '.', ''),
                number_format($a['cash'], 2, '.', '')]);
        }
    }
    fclose($out);
    exit;
}
$qs = static fn(array $x): string => $e(http_build_query(['from' => $from, 'to' => $to] + $x));

admin_header('Profit & loss', 'pnl');
?>
<style>
.pl-k{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 16px}
.pl-k div{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px}
.pl-k small{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--mut)}
.pl-k b{font-size:21px;font-variant-numeric:tabular-nums}
.pl-neg{color:#b02a2a}.pl-pos{color:#0a6b3b}
@media(max-width:760px){.pl-k{grid-template-columns:1fr 1fr}}
</style>

<form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:0 0 14px">
  <label class="muted" style="font-size:11px">From<input type="date" name="from" value="<?= $e($from) ?>" style="display:block;padding:8px 10px;border:1px solid var(--line);border-radius:8px"></label>
  <label class="muted" style="font-size:11px">To<input type="date" name="to" value="<?= $e($to) ?>" style="display:block;padding:8px 10px;border:1px solid var(--line);border-radius:8px"></label>
  <button class="btn" type="submit">Show</button>
  <a class="btn ghost" href="?<?= $e(http_build_query(['from' => date('Y-m-01', strtotime(date('Y-m-01') . ' -1 month')), 'to' => date('Y-m-t', strtotime(date('Y-m-01') . ' -1 month'))])) ?>">Last month</a>
  <a class="btn ghost" href="?">This month</a>
</form>

<div class="pl-k">
  <div><small>Revenue (travel date)</small><b><?= $e(inr($rt['revenue'])) ?></b></div>
  <div><small>Agent commission</small><b><?= $e(inr($rt['commission'])) ?></b></div>
  <div><small>Trip expenses</small><b><?= $e(inr($rt['expenses'])) ?></b></div>
  <div><small>Net</small><b class="<?= $rt['net'] < 0 ? 'pl-neg' : 'pl-pos' ?>"><?= $e(inr($rt['net'])) ?></b></div>
</div>

<div class="panel">
  <h2>🚌 By route · <?= $e(formatDate($from)) ?> – <?= $e(formatDate($to)) ?> <a class="btn ghost" style="margin-left:auto;padding:5px 11px;font-size:12px" href="?<?= $qs(['export' => 'routes']) ?>">⬇ CSV</a></h2>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Route</th><th class="num">Trips</th><th class="num">Tickets · seats</th><th class="num">Revenue</th><th class="num">Commission</th><th class="num">Expenses</th><th class="num">Net</th><th class="num">Per trip</th></tr></thead>
    <tbody>
    <?php foreach ($routes as $r): ?>
      <tr>
        <td data-label="Route"><b><?= $e($r['from_city'] . ' → ' . $r['to_city']) ?></b> <span class="muted mono" style="font-size:11px"><?= $e($r['route_code']) ?></span></td>
        <td data-label="Trips" class="num"><?= (int) $r['trips'] ?></td>
        <td data-label="Tickets · seats" class="num"><?= (int) $r['tickets'] ?> · <?= (int) $r['seats'] ?></td>
        <td data-label="Revenue" class="num"><?= $e(inr($r['revenue'])) ?></td>
        <td data-label="Commission" class="num"><?= $r['commission'] != 0.0 ? '−' . $e(inr(abs($r['commission']))) : '<span class="muted">—</span>' ?></td>
        <td data-label="Expenses" class="num" title="<?= $e('Diesel ' . inr($r['diesel']) . ' · toll ' . inr($r['toll']) . ' · driver ' . inr($r['driver']) . ' · misc ' . inr($r['misc'])) ?>"><?= $r['expenses'] > 0 ? '−' . $e(inr($r['expenses'])) : '<span class="muted">—</span>' ?></td>
        <td data-label="Net" class="num <?= $r['net'] < 0 ? 'pl-neg' : 'pl-pos' ?>"><b><?= $e(inr($r['net'])) ?></b></td>
        <td data-label="Per trip" class="num"><?= $r['trips'] > 0 ? $e(inr($r['net'] / $r['trips'])) : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;padding:0 16px 12px;margin:0">Revenue counts each ticket leg on the day its coach ran. Expenses come from the trip-expense entries for each route<?= $rt['expenses'] <= 0 ? ' — none entered for these dates yet' : '' ?>.</p>
</div>

<div class="panel">
  <h2>🧑‍💼 By agent · sold <?= $e(formatDate($from)) ?> – <?= $e(formatDate($to)) ?> <a class="btn ghost" style="margin-left:auto;padding:5px 11px;font-size:12px" href="?<?= $qs(['export' => 'agents']) ?>">⬇ CSV</a></h2>
  <?php if ($agents === []): ?>
    <p class="muted" style="padding:0 18px 16px;margin:0">No agent sale in these dates.</p>
  <?php else: ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Agent</th><th class="num">Tickets</th><th class="num">Revenue</th><th class="num">Commission</th><th class="num">Company keeps</th><th class="num">Cash held now</th></tr></thead>
    <tbody>
    <?php foreach ($agents as $a): ?>
      <tr>
        <td data-label="Agent"><a href="/admin/agent-360.php?agent=<?= (int) $a['id'] ?>"><b><?= $e($a['full_name'] ?: $a['username']) ?></b></a> <span class="muted mono" style="font-size:11px"><?= $e(AgentWallet::agentCodeLabel((int) $a['id'])) ?></span></td>
        <td data-label="Tickets" class="num"><?= (int) $a['tickets'] ?><?= (int) $a['cancelled'] > 0 ? ' <span class="muted">(' . (int) $a['cancelled'] . ' cancelled)</span>' : '' ?></td>
        <td data-label="Revenue" class="num"><?= $e(inr($a['revenue'])) ?></td>
        <td data-label="Commission" class="num"><?= $e(inr($a['commission'])) ?></td>
        <td data-label="Company keeps" class="num"><b><?= $e(inr($a['company'])) ?></b></td>
        <td data-label="Cash held now" class="num" style="color:<?= $a['cash'] > 0.009 ? '#b02a2a' : 'inherit' ?>"><?= $e(inr($a['cash'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php admin_footer();
