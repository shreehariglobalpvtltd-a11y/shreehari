<?php
/**
 * admin/index.php — dashboard.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$today     = todayISO();
$todayEnd  = addDaysISO($today, 1);
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-01', strtotime('+1 month'));

/* Range predicates (>= start AND < end) instead of DATE()/YEAR()/MONTH()
   wrapping — a wrapped column can't use the ix_bookings_created /
   ix_bookings_status indexes, so these were full table scans. */
$stats = [
    'pendingPay'   => (int) Database::scalar(
        "SELECT COUNT(*) FROM bookings WHERE status='pending'"),
    'todayBookings'=> (int) Database::scalar(
        "SELECT COUNT(*) FROM bookings WHERE created_at>=:d0 AND created_at<:d1",
        ['d0' => $today, 'd1' => $todayEnd]),
    'confirmed'    => (int) Database::scalar(
        "SELECT COUNT(*) FROM bookings WHERE status='confirmed'"),
    'revenueToday' => (float) Database::scalar(
        "SELECT COALESCE(SUM(total_amount),0) FROM bookings WHERE status='confirmed' AND confirmed_at>=:d0 AND confirmed_at<:d1",
        ['d0' => $today, 'd1' => $todayEnd]),
    'revenueMonth' => (float) Database::scalar(
        "SELECT COALESCE(SUM(total_amount),0) FROM bookings WHERE status='confirmed' AND confirmed_at>=:m0 AND confirmed_at<:m1",
        ['m0' => $monthStart, 'm1' => $monthEnd]),
    'seatsToday'   => (int) Database::scalar(
        "SELECT COUNT(*) FROM booking_seats bs JOIN bookings b ON b.id=bs.booking_id WHERE b.status='confirmed' AND b.confirmed_at>=:d0 AND b.confirmed_at<:d1",
        ['d0' => $today, 'd1' => $todayEnd]),
];

$recent = Database::fetchAll(
    "SELECT b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at,
            r.from_city, r.to_city
       FROM bookings b
       LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
       LEFT JOIN schedules s ON s.id=bl.schedule_id
       LEFT JOIN routes r ON r.id=s.route_id
      ORDER BY b.id DESC LIMIT 10"
);

admin_header('Dashboard', 'index');
?>
<div class="cards">
  <div class="card"><div class="k">Awaiting verification</div>
    <div class="v"><?= $stats['pendingPay'] ?></div>
    <?php if ($stats['pendingPay'] > 0): ?><a class="muted" href="<?= rtrim(APP_URL,'/') ?>/admin/payments.php">Review now →</a><?php endif; ?>
  </div>
  <div class="card"><div class="k">Bookings today</div><div class="v"><?= $stats['todayBookings'] ?></div></div>
  <div class="card"><div class="k">Confirmed (all time)</div><div class="v"><?= $stats['confirmed'] ?></div></div>
  <div class="card"><div class="k">Revenue today</div><div class="v"><?= Security::e(inr($stats['revenueToday'])) ?></div></div>
  <div class="card"><div class="k">Revenue this month</div><div class="v"><?= Security::e(inr($stats['revenueMonth'])) ?></div></div>
  <div class="card"><div class="k">Seats sold today</div><div class="v"><?= $stats['seatsToday'] ?></div></div>
</div>

<div class="panel">
  <h2>Latest bookings</h2>
  <table>
    <thead><tr><th>PNR</th><th>Route</th><th>Phone</th><th>Amount</th><th>Status</th><th>Placed</th></tr></thead>
    <tbody>
    <?php if ($recent === []): ?>
      <tr><td colspan="6" class="muted" style="padding:22px">No bookings yet.</td></tr>
    <?php else: foreach ($recent as $b): ?>
      <tr>
        <td class="mono"><a href="<?= rtrim(APP_URL,'/') ?>/admin/booking-view.php?pnr=<?= urlencode($b['pnr']) ?>"><?= Security::e($b['pnr']) ?></a></td>
        <td><?= Security::e(($b['from_city'] ?? '—') . ' → ' . ($b['to_city'] ?? '—')) ?></td>
        <td class="mono"><?= Security::e(maskPhone((string) $b['contact_phone'])) ?></td>
        <td><?= Security::e(inr((float) $b['total_amount'])) ?></td>
        <td><?= admin_pill((string) $b['status']) ?></td>
        <td class="muted"><?= Security::e(timeAgo((string) $b['created_at'])) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php
admin_footer();
