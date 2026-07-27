<?php
/**
 * admin/booking-view.php?pnr=... — one booking in full.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');

$base  = rtrim(APP_URL, '/');
$pnr   = Security::clean($_GET['pnr'] ?? '', 40);
$flash = null;

/* ---- Cancel action ----------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            Auth::requireAdmin('bookings.cancel');
            $reason = Security::clean($_POST['reason'] ?? 'Cancelled by staff', 255);
            $res = BookingService::cancel($pnr, $reason, false);
            $flash = ['ok', 'Booking cancelled. Refund: ' . inr((float) ($res['refund']['amount'] ?? 0))
                          . ' (' . (int) ($res['refund']['percent'] ?? 0) . '%).'];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

$b = BookingService::detail($pnr);

admin_header('Booking ' . $pnr, 'bookings');

if ($b === null) {
    echo '<div class="flash bad">No booking found with reference ' . Security::e($pnr) . '.</div>';
    echo '<p><a class="btn ghost" href="' . $base . '/admin/bookings.php">← Back to bookings</a></p>';
    admin_footer();
    exit;
}

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}

$leg = $b['legs'][0] ?? [];
$pay = $b['payment'] ?? [];
$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;
?>
<p style="margin-top:-8px">
  <a class="muted" href="<?= $base ?>/admin/bookings.php">← All bookings</a>
  &nbsp;·&nbsp; <?= admin_pill((string) $b['status']) ?>
</p>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start">
  <div class="panel">
    <h2>Journey</h2>
    <table>
      <tr><th>Route</th><td><?= Security::e(($leg['from_city'] ?? '—') . ' → ' . ($leg['to_city'] ?? '—')) ?></td></tr>
      <tr><th>Bus</th><td><?= Security::e($leg['bus_name'] ?? '—') ?> <span class="muted">(<?= Security::e($leg['route_code'] ?? '') ?>)</span></td></tr>
      <tr><th>Travel date</th><td><?= Security::e(formatDate($leg['travel_date'] ?? null)) ?> · <?= Security::e(formatTime($leg['dep_time'] ?? null)) ?></td></tr>
      <tr><th>Boarding</th><td><?= Security::e($leg['boarding_stop'] ?? '—') ?></td></tr>
      <tr><th>Drop</th><td><?= Security::e($leg['drop_stop'] ?? '—') ?></td></tr>
      <tr><th>Seats</th><td class="mono"><?= Security::e(implode(', ', $b['seats'] ?? [])) ?></td></tr>
      <tr><th>Contact</th><td class="mono"><?= Security::e((string) $b['contact_phone']) ?><?= !empty($b['contact_email']) ? ' · ' . Security::e((string) $b['contact_email']) : '' ?></td></tr>
    </table>
  </div>

  <div class="panel">
    <h2>Fare &amp; payment</h2>
    <table>
      <tr><th>Base total</th><td><?= Security::e(inr((float) $b['base_total'])) ?></td></tr>
      <?php foreach ([['group_discount','Group discount'],['coupon_discount','Coupon'],['tier_discount','Member discount'],['points_value','Loyalty points']] as $d): ?>
        <?php if ((float) ($b[$d[0]] ?? 0) > 0): ?>
          <tr><th><?= $d[1] ?></th><td>− <?= Security::e(inr((float) $b[$d[0]])) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ((float) ($b['tax_amount'] ?? 0) > 0): ?><tr><th>Tax</th><td><?= Security::e(inr((float) $b['tax_amount'])) ?></td></tr><?php endif; ?>
      <tr><th>Total</th><td><strong style="font-size:17px"><?= Security::e(inr((float) $b['total_amount'])) ?></strong></td></tr>
      <tr><th>Method</th><td><?= Security::e(strtoupper((string) ($pay['method'] ?? '—'))) ?> · <?= admin_pill((string) ($pay['status'] ?? 'pending')) ?></td></tr>
      <tr><th>UTR / Ref</th><td class="mono"><?= Security::e($pay['utr_number'] ?? '—') ?></td></tr>
    </table>
  </div>
</div>

<div class="panel">
  <h2>Passengers</h2>
  <table>
    <thead><tr><th>Seat</th><th>Name</th><th>Age</th><th>Gender</th></tr></thead>
    <tbody>
    <?php foreach ($b['passengers'] as $p): ?>
      <tr>
        <td class="mono"><?= Security::e((string) $p['seat_no']) ?></td>
        <td><?= Security::e((string) $p['full_name']) ?></td>
        <td><?= $p['age'] !== null ? (int) $p['age'] : '—' ?></td>
        <td><?= Security::e((string) ($p['gender'] ?? '—')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="toolbar">
  <?php if ($b['status'] === 'confirmed'): ?>
    <a class="btn" href="<?= $base ?>/download-ticket.php?pnr=<?= urlencode($pnr) ?>" target="_blank">⬇️ Ticket PDF</a>
    <a class="btn ghost" href="<?= $base ?>/download-ticket.php?pnr=<?= urlencode($pnr) ?>&invoice=1" target="_blank">⬇️ Invoice</a>
  <?php elseif ($b['status'] === 'pending'): ?>
    <a class="btn ok" href="<?= $base ?>/admin/payments.php">Go to verification queue →</a>
  <?php endif; ?>

  <?php if (in_array($b['status'], ['pending', 'confirmed'], true) && Auth::can('bookings.cancel')): ?>
    <form method="post" class="row-actions" style="margin-left:auto"
          onsubmit="return confirm('Cancel this booking? Seats will be released and the refund slab applied.')">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="text" name="reason" placeholder="Reason" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
      <button class="btn bad" type="submit" name="action" value="cancel">Cancel booking</button>
    </form>
  <?php endif; ?>
</div>
<?php
admin_footer();
