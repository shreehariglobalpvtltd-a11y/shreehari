<?php
/**
 * admin/bookings.php — searchable list of every booking.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
admin_boot('bookings.view');

$base   = rtrim(APP_URL, '/');
$q      = Security::clean($_GET['q'] ?? '', 60);
$status = Security::clean($_GET['status'] ?? '', 20);
$valid  = ['pending', 'confirmed', 'cancelled', 'rejected', 'expired'];

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(b.pnr LIKE :q OR b.contact_phone LIKE :q OR b.contact_email LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if (in_array($status, $valid, true)) {
    $where[] = 'b.status = :st';
    $params['st'] = $status;
}
$sql = "SELECT b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at, b.booking_mode,
               r.from_city, r.to_city, bl.travel_date,
               (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id=b.id) AS seat_count
          FROM bookings b
          LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
          LEFT JOIN schedules s ON s.id=bl.schedule_id
          LEFT JOIN routes r ON r.id=s.route_id"
     . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY b.id DESC LIMIT 200';

$rows = Database::fetchAll($sql, $params);

admin_header('Bookings', 'bookings');
?>
<form class="toolbar" method="get">
  <input type="search" name="q" placeholder="PNR, phone or email" value="<?= Security::e($q) ?>" style="min-width:240px">
  <select name="status">
    <option value="">All statuses</option>
    <?php foreach ($valid as $s): ?>
      <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== '' || $status !== ''): ?><a class="btn ghost" href="<?= $base ?>/admin/bookings.php">Clear</a><?php endif; ?>
  <a class="btn ghost" style="margin-left:auto" href="<?= $base ?>/admin/export.php?<?= Security::e(http_build_query(['q' => $q, 'status' => $status])) ?>">⬇️ Export CSV</a>
</form>

<div class="panel">
  <h2><?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) === 200 ? ' (showing latest 200)' : '' ?></h2>
  <table>
    <thead><tr><th>PNR</th><th>Route / Date</th><th>Seats</th><th>Amount</th><th>Status</th><th>Placed</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="6" class="muted" style="padding:22px;text-align:center">No bookings match.</td></tr>
    <?php else: foreach ($rows as $b): ?>
      <tr>
        <td class="mono"><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($b['pnr']) ?>"><?= Security::e($b['pnr']) ?></a></td>
        <td><?= Security::e(($b['from_city'] ?? '—') . ' → ' . ($b['to_city'] ?? '—')) ?>
          <div class="muted"><?= Security::e(formatDate($b['travel_date'] ?? null)) ?></div>
        </td>
        <td><?= (int) $b['seat_count'] ?></td>
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
