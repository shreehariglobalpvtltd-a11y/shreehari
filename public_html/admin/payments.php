<?php
/**
 * admin/payments.php — the payment verification queue.
 *
 * Staff see every booking awaiting verification with its UTR and payment
 * screenshot, then Approve (confirm + issue ticket) or Reject (release
 * seats). Both actions run through BookingService so the rules live in
 * one place.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('payments.view');

$base  = rtrim(APP_URL, '/');
$flash = null;

/* ---- Handle Approve / Reject ------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $action    = (string) ($_POST['action'] ?? '');
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $note      = Security::clean($_POST['note'] ?? '', 255);

        try {
            if ($action === 'approve') {
                Auth::requireAdmin('payments.verify');
                $b = BookingService::confirm($bookingId, (int) $admin['id'], $note);
                $flash = ['ok', 'Payment verified — ticket issued for ' . ($b['pnr'] ?? '') . '.'];
            } elseif ($action === 'reject') {
                Auth::requireAdmin('payments.reject');
                if ($note === '') { $note = 'Payment could not be verified.'; }
                $b = BookingService::reject($bookingId, (int) $admin['id'], $note);
                $flash = ['ok', 'Booking ' . ($b['pnr'] ?? '') . ' rejected and seats released.'];
            } else {
                $flash = ['bad', 'Unknown action.'];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Load the queue ---------------------------------------------- */
$queue = Database::fetchAll(
    "SELECT b.id, b.pnr, b.total_amount, b.contact_phone, b.booking_mode, b.created_at, b.is_cod,
            p.utr_number, p.payer_name, p.method, p.mode, p.status AS pay_status,
            r.from_city, r.to_city, bl.travel_date,
            (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id=b.id) AS seat_count,
            (SELECT file_path FROM payment_screenshots ps WHERE ps.booking_id=b.id ORDER BY ps.id DESC LIMIT 1) AS shot
       FROM bookings b
       JOIN payments p ON p.id=(SELECT id FROM payments WHERE booking_id=b.id ORDER BY id DESC LIMIT 1)
       LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
       LEFT JOIN schedules s ON s.id=bl.schedule_id
       LEFT JOIN routes r ON r.id=s.route_id
      WHERE b.status='pending'
      ORDER BY b.created_at ASC"
);

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

admin_header('Verify payments', 'payments');

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}
?>
<div class="panel">
  <h2><?= count($queue) ?> booking<?= count($queue) === 1 ? '' : 's' ?> awaiting verification</h2>
  <table>
    <thead><tr>
      <th>PNR</th><th>Route / Date</th><th>Seats</th><th>Amount</th>
      <th>UTR / Payer</th><th>Proof</th><th style="width:250px">Decision</th>
    </tr></thead>
    <tbody>
    <?php if ($queue === []): ?>
      <tr><td colspan="7" class="muted" style="padding:26px;text-align:center">🎉 Nothing pending. All caught up.</td></tr>
    <?php else: foreach ($queue as $q): ?>
      <tr>
        <td class="mono">
          <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($q['pnr']) ?>"><?= Security::e($q['pnr']) ?></a>
          <div class="muted"><?= Security::e(timeAgo((string) $q['created_at'])) ?></div>
        </td>
        <td>
          <?= Security::e(($q['from_city'] ?? '—') . ' → ' . ($q['to_city'] ?? '—')) ?>
          <div class="muted"><?= Security::e(formatDate($q['travel_date'] ?? null)) ?> · <?= Security::e(ucfirst((string) ($q['booking_mode'] ?: 'seat'))) ?></div>
        </td>
        <td><?= (int) $q['seat_count'] ?></td>
        <td><strong><?= Security::e(inr((float) $q['total_amount'])) ?></strong>
          <?php if ((int) $q['is_cod'] === 1): ?><div class="muted">Pay at counter</div><?php endif; ?>
        </td>
        <td>
          <span class="mono"><?= Security::e($q['utr_number'] ?: '—') ?></span>
          <div class="muted"><?= Security::e($q['payer_name'] ?: '') ?> · <?= Security::e(strtoupper((string) $q['method'])) ?></div>
        </td>
        <td>
          <?php if (!empty($q['shot'])): ?>
            <a href="<?= $base ?>/admin/screenshot.php?id=<?= (int) $q['id'] ?>" target="_blank">View 🖼️</a>
          <?php else: ?><span class="muted">none</span><?php endif; ?>
        </td>
        <td>
          <form method="post" class="row-actions">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
            <input type="hidden" name="booking_id" value="<?= (int) $q['id'] ?>">
            <input type="text" name="note" placeholder="Note (optional)" style="flex:1;min-width:90px;padding:7px 9px;border:1px solid var(--line);border-radius:8px;font-size:12px">
            <button class="btn ok" type="submit" name="action" value="approve"
                    onclick="return confirm('Verify this payment and issue the ticket?')">✓ Approve</button>
            <button class="btn bad" type="submit" name="action" value="reject"
                    onclick="return confirm('Reject this booking and release its seats? The customer will be notified.')">✕ Reject</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php
admin_footer();
