<?php
/**
 * admin/extra-seats.php — Extra seats register (owner ask, 8 Oct 2026).
 *
 * Passengers also ride outside the berths (balcony, the driver's front
 * cabin). Counter / agent now sell them as EX1..EX20 on every bus through
 * the normal booking path, so each one is a real booking with a ticket,
 * a payment and agent commission. This page is the money view of them:
 * every extra-seat passenger in a date range, per bus totals, CSV export,
 * and (super-admin) how many extra seats a bus carries.
 *
 * Permission: bookings.view. An agent sees only the extra seats they sold
 * (Auth::bookingScopeAdminId — the same rule as the bookings list).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentwallet.php';
$admin = admin_boot('bookings.view');

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!Auth::isSuperadmin()) {
        $flash = ['bad', 'Only a super-admin can change the number of extra seats.'];
    } else {
        $n = max(0, min(40, (int) ($_POST['extra_seats_per_bus'] ?? 20)));
        Settings::set('extra_seats_per_bus', (string) $n, 'int', 'booking');
        Logger::audit('settings.extra_seats', 'settings', 'extra_seats_per_bus', null, ['n' => $n], '');
        $flash = ['ok', $n > 0 ? ('Every bus now carries ' . $n . ' extra seats (EX1–EX' . $n . ').') : 'Extra seats switched off. Seats already sold stay on their tickets.'];
    }
}

$today = todayISO();
$from  = Security::clean($_GET['from'] ?? '', 10);
$to    = Security::clean($_GET['to'] ?? '', 10);
if (!Security::isValidDate($from)) { $from = date('Y-m-d', strtotime($today . ' -6 days')); }
if (!Security::isValidDate($to))   { $to   = date('Y-m-d', strtotime($today . ' +7 days')); }
if ($to < $from) { [$from, $to] = [$to, $from]; }
$format  = (string) ($_GET['format'] ?? '') === 'csv' ? 'csv' : 'html';
$scopeId = Auth::bookingScopeAdminId();

/* One row per extra-seat passenger. The seat's money = the booking total
   shared equally over the booking's live seats (a party may mix berths and
   extra seats in one ticket), so the column adds up to real cash. */
$params = ['f' => $from, 't' => $to];
$scopeSql = '';
if ($scopeId !== null) {
    $scopeSql = ' AND b.sold_by_admin_id = :me';
    $params['me'] = $scopeId;
}
$rows = Database::fetchAll(
    "SELECT bs.seat_no, s.id AS schedule_id, s.travel_date, s.slot,
            r.route_code, r.from_city, r.to_city,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
            bu.bus_number,
            b.id AS booking_id, b.pnr, b.status, b.source, b.is_cod, b.total_amount, b.contact_phone, b.created_at,
            b.sold_by_admin_id,
            bp.full_name, bp.gender,
            a.full_name AS seller_name, a.role AS seller_role,
            (SELECT COUNT(*) FROM booking_seats x WHERE x.booking_id = b.id AND x.released_at IS NULL) AS seats_in_booking,
            (SELECT p.method FROM payments p WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS pay_method,
            (SELECT p.status FROM payments p WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS pay_status
       FROM booking_seats bs
       JOIN schedules s   ON s.id = bs.schedule_id
       JOIN routes r      ON r.id = s.route_id
       JOIN bookings b    ON b.id = bs.booking_id
       LEFT JOIN buses bu ON bu.id = COALESCE(s.bus_id, r.bus_id)
       LEFT JOIN booking_passengers bp ON bp.booking_id = bs.booking_id AND bp.seat_no = bs.seat_no
       LEFT JOIN admins a ON a.id = b.sold_by_admin_id
      WHERE bs.released_at IS NULL AND bs.seat_no LIKE 'EX%'
        AND s.travel_date BETWEEN :f AND :t" . $scopeSql . "
      ORDER BY s.travel_date, dep_time, s.slot, LENGTH(bs.seat_no), bs.seat_no",
    $params
);

$share = static function (array $r): float {
    $n = max(1, (int) ($r['seats_in_booking'] ?? 1));
    return round((float) ($r['total_amount'] ?? 0) / $n, 2);
};
$seller = static function (array $r): string {
    if (empty($r['sold_by_admin_id'])) {
        return 'Online';
    }
    $code = AgentWallet::agentCodeLabel((int) $r['sold_by_admin_id']);
    return trim((string) ($r['seller_name'] ?? '') . ($code !== '' ? ' (' . $code . ')' : ''));
};
$paid = static fn(array $r): bool => in_array((string) ($r['pay_status'] ?? ''), ['verified', 'success', 'paid'], true)
    || (string) ($r['source'] ?? '') !== 'web';

/* ---- CSV ---------------------------------------------------------- */
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="extra-seats-' . $from . '-to-' . $to . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    csv_put($out, ['Travel date', 'Route', 'Bus', 'Seat', 'Passenger', 'Gender', 'Phone', 'PNR', 'Status',
                   'Seat amount', 'Payment', 'Sold by', 'Booked at']);
    foreach ($rows as $r) {
        csv_put($out, [
            $r['travel_date'], $r['from_city'] . ' -> ' . $r['to_city'] . ((int) $r['slot'] > 1 ? ' (extra bus)' : ''),
            (string) ($r['bus_number'] ?? ''), $r['seat_no'], $r['full_name'], $r['gender'], $r['contact_phone'],
            $r['pnr'], $r['status'], number_format($share($r), 2, '.', ''),
            trim((string) ($r['pay_method'] ?? '') . ' ' . (string) ($r['pay_status'] ?? '')), $seller($r), $r['created_at'],
        ]);
    }
    fclose($out);
    Logger::audit('extra_seats.export', 'report', $from . '..' . $to, null, null, count($rows) . ' rows');
    exit;
}

/* ---- Totals ------------------------------------------------------- */
$totalAmt = 0.0;
$byBus    = [];
$bySeller = [];
foreach ($rows as $r) {
    $amt = $share($r);
    $totalAmt += $amt;
    $k = (int) $r['schedule_id'];
    $byBus[$k] ??= ['date' => $r['travel_date'], 'route' => $r['from_city'] . ' → ' . $r['to_city'], 'slot' => (int) $r['slot'],
                    'bus' => (string) ($r['bus_number'] ?? ''), 'dep' => substr((string) $r['dep_time'], 0, 5), 'n' => 0, 'amt' => 0.0,
                    'route_id' => 0, 'sid' => $k];
    $byBus[$k]['n']++;
    $byBus[$k]['amt'] += $amt;
    $sk = $seller($r);
    $bySeller[$sk] ??= ['n' => 0, 'amt' => 0.0];
    $bySeller[$sk]['n']++;
    $bySeller[$sk]['amt'] += $amt;
}
uasort($bySeller, static fn($a, $b) => $b['amt'] <=> $a['amt']);
$perBus = Seats::extraSeatCount();

admin_header('Extra seats', 'extra-seats');
$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;
$qs   = static fn(array $extra = []): string => Security::e(http_build_query(array_merge(['from' => $from, 'to' => $to], $extra)));
?>
<style>
.xs-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px}
.xs-kpi{border:1px solid var(--line,#d6deea);border-radius:12px;padding:10px 12px;background:var(--card,#fff)}
.xs-kpi b{display:block;font-size:20px}
.xs-kpi span{font-size:12px;color:var(--muted,#64748b)}
table.xs{width:100%;border-collapse:collapse;font-size:13px}
table.xs th,table.xs td{padding:7px 8px;border-bottom:1px solid var(--line,#e5e9f0);text-align:left;vertical-align:top}
table.xs td.num,table.xs th.num{text-align:right;white-space:nowrap}
.xs-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:end}
.xs-filter label{display:grid;gap:3px;font-size:12px}
</style>
<?php admin_page_head('Balcony / front-cabin passengers sold as EX1–EX' . max(1, $perBus) . ' on every bus — counter & agent only, not counted in the berth fill.', ['Seat management' => '', 'Extra seats' => '']); ?>

<?php if ($flash !== null): ?><div class="flash <?= Security::e($flash[0]) ?>"><?= Security::e($flash[1]) ?></div><?php endif; ?>

<div class="panel">
  <form method="get" class="xs-filter">
    <label>From <input type="date" name="from" value="<?= Security::e($from) ?>"></label>
    <label>To <input type="date" name="to" value="<?= Security::e($to) ?>"></label>
    <button class="btn" type="submit">Show</button>
    <a class="btn ghost" href="?<?= $qs(['format' => 'csv']) ?>">⬇ CSV / Excel</a>
  </form>
</div>

<div class="xs-kpis">
  <div class="xs-kpi"><b><?= count($rows) ?></b><span>extra-seat passengers</span></div>
  <div class="xs-kpi"><b><?= Security::e(inr($totalAmt)) ?></b><span>their fare (share of each ticket)</span></div>
  <div class="xs-kpi"><b><?= count($byBus) ?></b><span>buses carried extra passengers</span></div>
  <div class="xs-kpi"><b><?= $perBus ?></b><span>extra seats per bus (EX1–EX<?= max(1, $perBus) ?>)</span></div>
</div>

<?php if ($byBus !== []): ?>
<div class="panel">
  <h2>🚌 By bus</h2>
  <div style="overflow-x:auto"><table class="xs">
    <thead><tr><th>Date</th><th>Route</th><th>Bus</th><th class="num">Extra pax</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($byBus as $b): ?>
      <tr>
        <td><?= Security::e(formatDate((string) $b['date'])) ?></td>
        <td><?= Security::e($b['route']) ?><?= $b['slot'] > 1 ? ' <span class="muted">(extra bus)</span>' : '' ?> · <?= Security::e($b['dep']) ?></td>
        <td><?= Security::e($b['bus'] !== '' ? $b['bus'] : '—') ?></td>
        <td class="num"><?= (int) $b['n'] ?> / <?= $perBus ?></td>
        <td class="num"><?= Security::e(inr($b['amt'])) ?></td>
        <td><a href="/admin/seatmap.php?sid=<?= (int) $b['sid'] ?>">Seat map →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="panel">
  <h2>🧾 By seller</h2>
  <div style="overflow-x:auto"><table class="xs">
    <thead><tr><th>Sold by</th><th class="num">Extra pax</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($bySeller as $name => $s): ?>
      <tr><td><?= Security::e($name) ?></td><td class="num"><?= (int) $s['n'] ?></td><td class="num"><?= Security::e(inr($s['amt'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="panel">
  <h2>👥 Passengers · <?= Security::e(formatDate($from)) ?> – <?= Security::e(formatDate($to)) ?></h2>
  <?php if ($rows === []): ?>
    <p class="muted">No extra seats sold in these dates. Sell one from the app's counter mode (the ➕ Extra seats strip under the seat map) or Admin → Seat Map → Counter booking.</p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="xs">
    <thead><tr><th>Date</th><th>Seat</th><th>Passenger</th><th>PNR</th><th>Route</th><th class="num">Amount</th><th>Payment</th><th>Sold by</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= Security::e(formatDate((string) $r['travel_date'])) ?></td>
        <td><b><?= Security::e((string) $r['seat_no']) ?></b></td>
        <td><?= Security::e((string) ($r['full_name'] ?? '')) ?><?= !empty($r['gender']) ? ' <span class="muted">' . Security::e((string) $r['gender']) . '</span>' : '' ?></td>
        <td><a class="mono" href="/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= Security::e((string) $r['pnr']) ?></a>
            <?= (string) $r['status'] !== 'confirmed' ? ' <span class="muted">' . Security::e((string) $r['status']) . '</span>' : '' ?></td>
        <td><?= Security::e($r['from_city'] . ' → ' . $r['to_city']) ?></td>
        <td class="num"><?= Security::e(inr($share($r))) ?></td>
        <td><?= Security::e(trim((string) ($r['pay_method'] ?? '') . ' ' . (string) ($r['pay_status'] ?? '')) ?: '—') ?></td>
        <td><?= Security::e($seller($r)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if (Auth::isSuperadmin()): ?>
<div class="panel">
  <h2>⚙️ Extra seats per bus</h2>
  <form method="post" class="xs-filter">
    <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
    <label>Seats (0 = off, max 40) <input type="number" name="extra_seats_per_bus" min="0" max="40" value="<?= $perBus ?>" style="width:110px"></label>
    <button class="btn ok" type="submit">Save</button>
  </form>
  <p class="muted" style="font-size:12px;margin:8px 0 0">Applies to every bus and date at once. Fare = the normal seat fare (pickup / bus price); give a counter discount when the extra seat is sold cheaper. Lowering the number never removes seats already sold.</p>
</div>
<?php endif; ?>
<?php
admin_footer();
