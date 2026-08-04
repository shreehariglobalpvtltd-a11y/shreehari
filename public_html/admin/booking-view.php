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

/* ---- Resend WhatsApp ticket (confirmed bookings only) ------------- */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'resend_wa') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (($b['status'] ?? '') !== 'confirmed') {
        $flash = ['bad', 'Tickets can only be re-sent for confirmed bookings.'];
    } elseif (!Auth::can('payments.verify')) {
        $flash = ['bad', 'Your role cannot re-send tickets.'];
    } else {
        try {
            $r = Notify::resendTicketWhatsApp($b);
            $flash = [$r['ok'] ? 'ok' : 'bad', $r['detail'] . (!empty($r['link']) ? ' ' . $r['link'] : '')];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Reversible payment review (§4) ------------------------------
   An authorised admin can move a booking between confirmed and rejected at
   any time (e.g. undo a mistaken approval). Every transition is audit-logged
   with old→new state inside BookingService, and the customer's ticket
   re-locks / unlocks on their next status refresh. */
if ($b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'review') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $decision = (string) ($_POST['decision'] ?? '');
        $note     = Security::clean($_POST['note'] ?? '', 255);
        try {
            if ($decision === 'approve') {
                Auth::requireAdmin('payments.verify');
                $r = BookingService::confirm((int) $b['id'], (int) $admin['id'], $note);
                $flash = ['ok', 'Payment approved — ticket issued for ' . ($r['pnr'] ?? $pnr) . '.'];
            } elseif ($decision === 'reject') {
                Auth::requireAdmin('payments.reject');
                if ($note === '') { $note = 'Payment could not be verified.'; }
                $r = BookingService::reject((int) $b['id'], (int) $admin['id'], $note);
                $flash = ['ok', 'Booking ' . ($r['pnr'] ?? $pnr) . ' marked rejected — seats released.'];
            } else {
                $flash = ['bad', 'Unknown decision.'];
            }
            $b = BookingService::detail($pnr); // refresh so the page shows the new state
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

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

<?php if ((Auth::can('payments.verify') || Auth::can('payments.reject')) && in_array($b['status'], ['pending', 'confirmed', 'rejected'], true)): ?>
<div class="panel">
  <h2>Payment review</h2>
  <p class="muted" style="margin-top:-6px">
    Booking is <?= admin_pill((string) $b['status']) ?> · payment <?= admin_pill((string) ($pay['status'] ?? 'pending')) ?>.
    You can change this decision at any time — every change is logged and the customer's ticket re-locks or unlocks on their next refresh.
  </p>
  <div class="row-actions" style="gap:10px;flex-wrap:wrap;align-items:center">
    <?php if (Auth::can('payments.verify') && in_array($b['status'], ['pending', 'rejected'], true)): ?>
      <form method="post" style="display:flex;gap:8px;align-items:center"
            onsubmit="return confirm('Approve this payment and issue the ticket? Any released seats will be re-claimed.')">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="decision" value="approve">
        <input type="text" name="note" placeholder="Note (optional)" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
        <button class="btn ok" type="submit" name="action" value="review">✓ Approve payment</button>
      </form>
    <?php endif; ?>
    <?php if (Auth::can('payments.reject') && in_array($b['status'], ['pending', 'confirmed'], true)): ?>
      <form method="post" style="display:flex;gap:8px;align-items:center"
            onsubmit="return confirm('Reject this payment and release its seats? The customer is notified and their ticket re-locks.')">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="decision" value="reject">
        <input type="text" name="note" placeholder="Reason (shown to customer)" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
        <button class="btn bad" type="submit" name="action" value="review">✕ Reject payment</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($b['status'] === 'confirmed'): ?>
    <p class="muted" style="font-size:12px;margin-top:10px">⚠️ Rejecting a confirmed booking frees the seats and re-locks the ticket, but does not auto-void the issued ticket PDF, referral commission or loyalty points — handle those manually if needed.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="toolbar">
  <?php if ($b['status'] === 'confirmed'): ?>
    <a class="btn" href="<?= $base ?>/download-ticket.php?pnr=<?= urlencode($pnr) ?>" target="_blank">⬇️ Ticket PDF</a>
    <a class="btn ghost" href="<?= $base ?>/download-ticket.php?pnr=<?= urlencode($pnr) ?>&invoice=1" target="_blank">⬇️ Invoice</a>
    <?php if (Auth::can('payments.verify')): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Re-send the ticket to <?= Security::e((string) $b['contact_phone']) ?> on WhatsApp?')">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <button class="btn ok" type="submit" name="action" value="resend_wa">📲 Resend WhatsApp ticket</button>
      </form>
    <?php endif; ?>
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
