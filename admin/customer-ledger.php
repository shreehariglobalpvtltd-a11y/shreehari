<?php
/**
 * admin/customer-ledger.php — date-wise Customer Ledger (owner prompt, 3 Oct 2026).
 *
 * One line per booking: date, PNR / ticket, customer, mobile, passengers,
 * seats, from → to, agent / source, fare, payment, status. Filters cover
 * journey OR booking date (Today / Yesterday / range), name, mobile (any
 * part — the last 4–6 digits work), seller (agent or counter staff),
 * source, route, bus, pickup, drop, ticket status and payment status.
 *
 * Every number on this page is computed from the bookings tables at the
 * moment the page loads, through CustomerBook::ledger() / totals() — the
 * same builder the Customer 360 page and its "Download history" use — so
 * a cancellation, a refund or a seat move shows here on the next refresh
 * with nothing to recalculate. ?phone= shows one customer's whole history
 * (their linked numbers included).
 *
 * Office page: counter agents see their own sales on agent-sales.php.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require __DIR__ . '/_export.php';
require_once INCLUDE_PATH . '/customerbook.php';
$admin = admin_boot('customers.view');
if (Auth::isCounterAgent()) {
    admin_header('Customer Ledger', 'customer-ledger');
    echo '<div class="flash bad">The customer ledger is an office page — your own sales are under My Sales.</div>';
    admin_footer();
    exit;
}

$f       = CustomerBook::filtersFrom($_GET);
$range   = (string) ($_GET['range'] ?? '');
$page    = max(1, (int) ($_GET['p'] ?? 1));
$PER     = 100;
$export  = (string) ($_GET['export'] ?? '');
$totals  = CustomerBook::totals($f);
$pages   = max(1, (int) ceil($totals['bookings'] / $PER));
if ($page > $pages) { $page = $pages; }

$header = ['Journey date', 'Booked on', 'PNR', 'Ticket no', 'Customer', 'Mobile', 'Passengers', 'Seats', 'From', 'To', 'Route', 'Bus', 'Agent / source', 'Fare (Rs)', 'Paid (Rs)', 'Payment', 'Status', 'Refund (Rs)', 'Commission (Rs)'];
$flat = static function (array $r): array {
    return [
        (string) ($r['travel_date'] ?? ''), substr((string) $r['created_at'], 0, 16), (string) $r['pnr'], (string) ($r['ticket_no'] ?? ''),
        (string) ($r['lead_name'] ?? ''), (string) $r['contact_phone'], (int) $r['pax'], (string) $r['seat_labels'],
        (string) $r['from_label'], (string) $r['to_label'], (string) ($r['route_code'] ?? ''), (string) ($r['bus_number'] ?? ''),
        (string) $r['seller_label'], (float) $r['total_amount'], (float) $r['paid_amount'], (string) $r['pay_label'],
        (string) $r['status'] . ((string) $r['refund_status'] !== 'none' ? ' / refund ' . $r['refund_status'] : ''),
        (float) $r['refund_amount'],
        (float) $r['commission'],
    ];
};
if ($export === 'csv' || $export === 'xlsx') {
    $rows = CustomerBook::ledger($f, 20000, 0);
    $data = array_map($flat, $rows);
    $data[] = ['TOTAL', '', $totals['bookings'] . ' bookings', '', $totals['customers'] . ' numbers', '', $totals['pax'], $totals['seats'] . ' seats', '', '', '', '', '',
               $totals['fare'], $totals['paid'], '', $totals['sold'] . ' sold / ' . $totals['cancelled'] . ' cancelled', $totals['refunds'], $totals['commission']];
    Logger::audit('customer.ledger_export', 'customer', $export, null, ['rows' => count($rows), 'filters' => array_filter($f, static fn($v) => $v !== '' && $v !== 0 && $v !== [])], 'customer ledger exported');
    $name = 'SHG-customer-ledger-' . ($f['phones'] !== [] ? $f['phones'][0] . '-' : '') . date('Y-m-d');
    if ($export === 'xlsx') { shg_export_xlsx($name . '.xlsx', 'Customer ledger', $header, $data, [6, 13, 14, 17, 18]); }
    shg_export_csv($name . '.csv', $header, $data);
}

$rows = CustomerBook::ledger($f, $PER, ($page - 1) * $PER);

/* Filter choices. Sellers = anyone who has ever sold a booking (agents with
   their SHG code, counter / office staff) — the "Agent" and "Counter"
   filters of the owner's list are this one picker. */
$sellers = Database::fetchAll(
    "SELECT a.id, a.full_name, a.username, a.role, COUNT(b.id) AS n
       FROM admins a JOIN bookings b ON b.sold_by_admin_id = a.id
      GROUP BY a.id, a.full_name, a.username, a.role
      ORDER BY a.role = 'agent' DESC, a.full_name"
);
$routes = Database::fetchAll('SELECT id, route_code, from_city, to_city FROM routes ORDER BY is_active DESC, id');
$buses  = Database::fetchAll('SELECT id, bus_number FROM buses ORDER BY is_active DESC, bus_number');

$qs = static function (array $over = []) use ($f, $range): string {
    $base = [
        'date_mode' => $f['date_mode'] === 'booking' ? 'booking' : null,
        'range' => $range ?: null,
        'from' => $range === '' ? ($f['from'] ?: null) : null, 'to' => $range === '' ? ($f['to'] ?: null) : null,
        'q' => $f['q'] ?: null, 'name' => $f['name'] ?: null, 'mobile' => $f['mobile'] ?: null,
        'agent' => $f['agent'] ?: null, 'source' => $f['source'] ?: null, 'route' => $f['route'] ?: null, 'bus' => $f['bus'] ?: null,
        'pickup' => $f['pickup'] ?: null, 'drop' => $f['drop'] ?: null, 'status' => $f['status'] ?: null, 'pay' => $f['pay'] ?: null,
        'phone' => $f['phones'] !== [] ? $f['phones'][0] : null,
    ];
    return 'customer-ledger.php?' . http_build_query(array_filter(array_merge($base, $over), static fn($v) => $v !== null && $v !== ''));
};
$e = static fn($v): string => Security::e((string) ($v ?? ''));
$sel = static fn(string $a, string $b): string => $a === $b ? ' selected' : '';
$hasFilter = $range !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['q'] !== '' || $f['name'] !== '' || $f['mobile'] !== ''
          || $f['agent'] || $f['source'] !== '' || $f['route'] || $f['bus'] || $f['pickup'] !== '' || $f['drop'] !== ''
          || $f['status'] !== '' || $f['pay'] !== '' || $f['phones'] !== [];

admin_header('Customer Ledger', 'customer-ledger');
?>
<style>
.cl-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.cl-filters + .cl-filters{margin-top:8px}
.cl-f{display:flex;flex-direction:column;gap:3px;min-width:0;flex:1 1 140px;max-width:240px}
.cl-f.wide{flex:2 1 220px;max-width:none}
.cl-f span{font-size:11px;color:var(--mut);font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.cl-f input,.cl-f select{padding:8px 10px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font-size:14px;min-height:40px;width:100%;box-sizing:border-box}
.cl-quick{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
.cl-quick a{padding:6px 12px;border:1px solid var(--line);border-radius:999px;font-size:13px;color:var(--ink);background:var(--card);text-decoration:none}
.cl-quick a.on{background:var(--navy,#12305a);border-color:transparent;color:#fff}
.cl-tot td{font-weight:700;background:var(--head)!important}
.cl-sub{font-size:11.5px;color:var(--mut)}
@media(pointer:coarse){.cl-f input,.cl-f select,.cl-quick a{min-height:44px}}
@media(max-width:600px){.cl-f{max-width:none;flex:1 1 46%}}
</style>

<?php if ($f['phones'] !== []): ?>
<p style="margin:-6px 0 12px"><a href="customer-view.php?phone=<?= urlencode($f['phones'][0]) ?>">← Customer profile +<?= $e($f['phones'][0]) ?></a>
  <?php if (count($f['phones']) > 1): ?><span class="muted"> · with linked number<?= count($f['phones']) > 2 ? 's' : '' ?> <?= $e(implode(', ', array_slice($f['phones'], 1))) ?></span><?php endif; ?>
  · <a href="customer-ledger.php">All customers</a></p>
<?php endif; ?>

<div class="cards">
  <div class="card"><div class="k">Bookings</div><div class="v"><?= number_format($totals['bookings']) ?></div></div>
  <div class="card"><div class="k">Passengers</div><div class="v"><?= number_format($totals['pax']) ?></div></div>
  <div class="card"><div class="k">Customers (numbers)</div><div class="v"><?= number_format($totals['customers']) ?></div></div>
  <div class="card"><div class="k">Fare (sold)</div><div class="v"><?= $e(inr($totals['fare'])) ?></div></div>
  <div class="card"><div class="k">Paid (verified)</div><div class="v"><?= $e(inr($totals['paid'])) ?></div></div>
  <div class="card"><div class="k">Cancelled · refunds</div><div class="v"><?= (int) $totals['cancelled'] ?> · <?= $e(inr($totals['refunds'])) ?></div></div>
  <div class="card"><div class="k">Agent commission (net)</div><div class="v"><?= $e(inr($totals['commission'])) ?></div></div>
</div>

<div class="cl-quick" role="group" aria-label="Quick dates">
  <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'tomorrow' => 'Tomorrow', '7d' => 'Last 7 days', 'month' => 'This month'] as $k => $lbl): ?>
    <a href="<?= $e($qs(['range' => $k, 'from' => null, 'to' => null, 'p' => null])) ?>" class="<?= $range === $k ? 'on' : '' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
  <a href="<?= $e($qs(['range' => null, 'from' => null, 'to' => null])) ?>" class="<?= $range === '' && $f['from'] === '' && $f['to'] === '' ? 'on' : '' ?>">All dates</a>
  <span class="muted" style="align-self:center;font-size:12.5px">· dates are <b><?= $f['date_mode'] === 'booking' ? 'booking' : 'journey' ?></b> dates</span>
</div>

<form method="get" class="panel" style="padding:14px 16px;margin-bottom:14px">
  <?php if ($f['phones'] !== []): ?><input type="hidden" name="phone" value="<?= $e($f['phones'][0]) ?>"><?php endif; ?>
  <div class="cl-filters">
    <label class="cl-f"><span>Date is</span>
      <select name="date_mode"><option value="journey">Journey date</option><option value="booking"<?= $sel($f['date_mode'], 'booking') ?>>Booking date</option></select></label>
    <label class="cl-f"><span>From</span><input type="date" name="from" value="<?= $e($f['from']) ?>"></label>
    <label class="cl-f"><span>To</span><input type="date" name="to" value="<?= $e($f['to']) ?>"></label>
    <label class="cl-f wide"><span>PNR / ticket / name / mobile</span><input type="search" name="q" value="<?= $e($f['q']) ?>" placeholder="SHG-2026-00453 · Ram · 4321"></label>
    <label class="cl-f"><span>Customer name</span><input type="search" name="name" value="<?= $e($f['name']) ?>" placeholder="Name"></label>
    <label class="cl-f"><span>Mobile (any digits)</span><input type="search" name="mobile" inputmode="numeric" value="<?= $e($f['mobile']) ?>" placeholder="last 4–6 digits"></label>
  </div>
  <div class="cl-filters">
    <label class="cl-f"><span>Agent / counter</span>
      <select name="agent"><option value="">Everyone</option>
        <?php $grp = ''; foreach ($sellers as $s):
          $g = $s['role'] === 'agent' ? 'Agents' : 'Counter / office staff';
          if ($g !== $grp) { echo $grp !== '' ? '</optgroup>' : ''; echo '<optgroup label="' . $e($g) . '">'; $grp = $g; }
          $code = AgentWallet::agentCodeLabel((int) $s['id']); ?>
          <option value="<?= (int) $s['id'] ?>"<?= $f['agent'] === (int) $s['id'] ? ' selected' : '' ?>><?= $e(($code !== '' ? $code . ' · ' : '') . ($s['full_name'] ?: $s['username'])) ?> (<?= (int) $s['n'] ?>)</option>
        <?php endforeach; echo $grp !== '' ? '</optgroup>' : ''; ?>
      </select></label>
    <label class="cl-f"><span>Source</span>
      <select name="source"><option value="">All</option>
        <?php foreach (['web' => 'Online (website)', 'app' => 'App', 'counter' => 'Counter', 'agent' => 'Agent', 'admin' => 'Office'] as $k => $lbl): ?>
          <option value="<?= $k ?>"<?= $sel($f['source'], $k) ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="cl-f"><span>Route</span>
      <select name="route"><option value="">All routes</option>
        <?php foreach ($routes as $rt): ?><option value="<?= (int) $rt['id'] ?>"<?= $f['route'] === (int) $rt['id'] ? ' selected' : '' ?>><?= $e($rt['route_code'] . ' · ' . $rt['from_city'] . ' → ' . $rt['to_city']) ?></option><?php endforeach; ?>
      </select></label>
    <label class="cl-f"><span>Bus</span>
      <select name="bus"><option value="">All buses</option>
        <?php foreach ($buses as $bu): ?><option value="<?= (int) $bu['id'] ?>"<?= $f['bus'] === (int) $bu['id'] ? ' selected' : '' ?>><?= $e($bu['bus_number']) ?></option><?php endforeach; ?>
      </select></label>
    <label class="cl-f"><span>Pickup</span><input type="search" name="pickup" value="<?= $e($f['pickup']) ?>" placeholder="e.g. Surat"></label>
    <label class="cl-f"><span>Drop</span><input type="search" name="drop" value="<?= $e($f['drop']) ?>" placeholder="e.g. Rupaidiha"></label>
    <label class="cl-f"><span>Ticket status</span>
      <select name="status"><option value="">All</option>
        <?php foreach (['active' => 'Active (confirmed + pending)', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'pending' => 'Pending', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'expired' => 'Expired', 'rejected' => 'Rejected'] as $k => $lbl): ?>
          <option value="<?= $k ?>"<?= $sel($f['status'], $k) ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="cl-f"><span>Payment</span>
      <select name="pay"><option value="">All</option>
        <?php foreach (CustomerBook::PAY_FILTERS as $k => $lbl): ?><option value="<?= $k ?>"<?= $sel($f['pay'], $k) ?>><?= $e($lbl) ?></option><?php endforeach; ?>
      </select></label>
  </div>
  <div class="cl-filters" style="margin-top:12px">
    <button class="btn" type="submit">🔎 Show</button>
    <?php if ($hasFilter): ?><a class="btn ghost" href="customer-ledger.php<?= $f['phones'] !== [] ? '?phone=' . urlencode($f['phones'][0]) : '' ?>">Clear</a><?php endif; ?>
    <span style="margin-left:auto;display:inline-flex;gap:8px;flex-wrap:wrap">
      <a class="btn ghost" href="<?= $e($qs(['export' => 'xlsx'])) ?>">⬇ Excel</a>
      <a class="btn ghost" href="<?= $e($qs(['export' => 'csv'])) ?>">⬇ CSV</a>
    </span>
  </div>
</form>

<div class="dt-bar" id="clCtl">
  <input class="dt-q" type="search" placeholder="Filter this page…" aria-label="Filter this page">
  <span class="dt-count"></span>
  <span class="muted" style="font-size:12px">· <?= number_format($totals['bookings']) ?> booking<?= $totals['bookings'] === 1 ? '' : 's' ?><?= $pages > 1 ? ' · page ' . $page . ' of ' . $pages : '' ?></span>
</div>
<div class="dt-wrap">
<table class="dt no-card" data-controls="clCtl">
  <thead><tr>
    <th>Date</th><th>Ticket / PNR</th><th>Customer</th><th>Mobile</th><th data-type="num">Pax</th><th>Seats</th>
    <th>From</th><th>To</th><th>Agent / source</th><th data-type="num">Fare ₹</th><th data-type="num">Comm. ₹</th><th>Payment</th><th>Status</th>
  </tr></thead>
  <tbody>
  <?php if ($rows === []): ?>
    <tr><td colspan="13" class="muted" style="padding:22px;white-space:normal">No bookings match these filters.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r):
    $dateMain = $f['date_mode'] === 'booking' ? substr((string) $r['created_at'], 0, 10) : (string) ($r['travel_date'] ?? '');
    $dateSub  = $f['date_mode'] === 'booking' ? 'journey ' . ($r['travel_date'] ? formatDate((string) $r['travel_date'], 'j M') : '—') : 'booked ' . formatDate(substr((string) $r['created_at'], 0, 10), 'j M');
    $nonCust  = CustomerBook::nonCustomerReason((string) $r['contact_phone']);
  ?>
    <tr>
      <td data-sort="<?= $e($dateMain) ?>"><b><?= $dateMain !== '' ? $e(formatDate($dateMain, 'd-m-Y')) : '—' ?></b><div class="cl-sub"><?= $e($dateSub) ?></div></td>
      <td class="mono"><a href="booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= $e($r['pnr']) ?></a><?php if (!empty($r['ticket_no']) && $r['ticket_no'] !== $r['pnr']): ?><div class="cl-sub"><?= $e($r['ticket_no']) ?></div><?php endif; ?></td>
      <td><?= $e($r['lead_name'] ?: '—') ?></td>
      <td class="mono"><?php if ($nonCust === ''): ?><a href="customer-view.php?phone=<?= urlencode((string) $r['contact_phone']) ?>" title="Open customer profile"><?= $e($r['contact_phone']) ?></a><?php else: ?><span title="<?= $e($nonCust) ?>"><?= $e($r['contact_phone']) ?></span><div class="cl-sub"><?= $e($nonCust) ?></div><?php endif; ?></td>
      <td class="num"><?= (int) $r['pax'] ?></td>
      <td class="mono"><?= $e($r['seat_labels'] ?: '—') ?></td>
      <td><?= $e($r['from_label'] ?: '—') ?></td>
      <td><?= $e($r['to_label'] ?: '—') ?><?php if (!empty($r['bus_number'])): ?><div class="cl-sub">🚌 <?= $e($r['bus_number']) ?></div><?php endif; ?></td>
      <td><?= $e($r['seller_label']) ?></td>
      <td class="num" data-sort="<?= (float) $r['total_amount'] ?>"><?= number_format((float) $r['total_amount']) ?><?php if ((float) $r['refund_amount'] > 0): ?><div class="cl-sub">refund <?= number_format((float) $r['refund_amount']) ?></div><?php endif; ?></td>
      <td class="num" data-sort="<?= (float) $r['commission'] ?>"><?= (float) $r['commission'] != 0.0 ? number_format((float) $r['commission']) : '<span class="muted">—</span>' ?></td>
      <td><?= $e($r['pay_label']) ?></td>
      <td><?= admin_pill((string) $r['status']) ?><?php if ((string) $r['refund_status'] !== 'none'): ?><div class="cl-sub">refund <?= $e($r['refund_status']) ?></div><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <?php if ($rows !== []): ?>
  <tfoot>
    <tr class="cl-tot">
      <td colspan="4">Total · all <?= number_format($totals['bookings']) ?> matching bookings</td>
      <td class="num"><?= number_format($totals['pax']) ?></td>
      <td><?= number_format($totals['seats']) ?> seats</td>
      <td colspan="3"><?= (int) $totals['sold'] ?> sold · <?= (int) $totals['cancelled'] ?> cancelled</td>
      <td class="num"><?= number_format($totals['fare']) ?></td>
      <td class="num"><?= number_format($totals['commission']) ?></td>
      <td colspan="2">paid <?= $e(inr($totals['paid'])) ?><?= $totals['refunds'] > 0 ? ' · refunded ' . $e(inr($totals['refunds'])) : '' ?></td>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>
</div>
<?php if ($pages > 1): ?>
<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 2px;font-size:13px">
  <span class="muted">Page <?= $page ?> / <?= $pages ?></span>
  <span style="margin-left:auto;display:inline-flex;gap:6px">
    <?php if ($page > 1): ?><a class="btn ghost" href="<?= $e($qs(['p' => $page - 1])) ?>">‹ Prev</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="btn ghost" href="<?= $e($qs(['p' => $page + 1])) ?>">Next ›</a><?php endif; ?>
  </span>
</div>
<?php endif; ?>
<p class="muted" style="font-size:12px;margin-top:8px">Fare counts confirmed and completed tickets only; Paid counts verified payments; Commission is the seller's net credit on each ticket from the agent ledger (choose an agent above for that agent's date-wise sales and commission). Every figure is read live from the bookings — a cancellation, refund or seat change shows here on the next refresh. Customer and mobile are as written on the ticket; open a mobile for the customer's full profile.</p>
<?php admin_footer(); ?>
