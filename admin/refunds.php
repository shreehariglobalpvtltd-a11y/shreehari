<?php
/**
 * admin/refunds.php — the refund desk (V6 §"Refund System").
 *
 * A cancelled booking that had money on it lands here automatically:
 * BookingService::cancel() computes the refund from the cancellation
 * policy and parks it as refund_status = 'pending'. This page is where
 * accounts reviews the amount, approves it against a bank/UPI reference,
 * or declines it with a reason — and the customer is messaged either way.
 *
 * Nothing here computes money on its own: the amount shown is the one the
 * policy produced at cancellation time. It stays editable because a real
 * counter sometimes settles differently (goodwill, partial cash already
 * returned), and every change is written to the audit log with who did it.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('refunds.view');

$base  = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$flash = null;

/* ---- Approve / Decline -------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            Auth::requireAdmin('refunds.process');

            $action    = (string) ($_POST['action'] ?? '');
            $bookingId = (int) ($_POST['booking_id'] ?? 0);
            $note      = Security::clean($_POST['note'] ?? '', 255);
            $ref       = Security::clean($_POST['ref'] ?? '', 80);
            $amount    = round(max(0.0, (float) ($_POST['amount'] ?? 0)), 2);

            $booking = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $bookingId]);
            if ($booking === null) {
                throw new RuntimeException('That booking no longer exists.');
            }
            if ((string) $booking['refund_status'] === 'processed') {
                throw new RuntimeException('This refund was already processed — nothing to do.');
            }
            // Only a booking that actually has a refund on it may be settled
            // here. Without this, a hand-crafted POST could stamp
            // refund_status = processed onto a live confirmed booking that
            // was never cancelled and never owed anybody money.
            if (!in_array((string) $booking['refund_status'], ['pending', 'denied'], true)) {
                throw new RuntimeException('Booking ' . $booking['pnr'] . ' has no refund to settle.');
            }

            if ($action === 'approve') {
                if ($amount <= 0) {
                    throw new RuntimeException('Enter the refund amount before approving.');
                }
                if ($amount > (float) $booking['total_amount']) {
                    throw new RuntimeException('A refund cannot be more than the ' . inr((float) $booking['total_amount']) . ' actually paid.');
                }

                Database::update('bookings', [
                    'refund_amount' => $amount,
                    'refund_status' => 'processed',
                    'refund_ref'    => $ref,
                ], 'id = :i', ['i' => $bookingId]);

                Logger::audit('refund.approve', 'booking', (string) $booking['pnr'],
                    ['amount' => (float) $booking['refund_amount'], 'status' => (string) $booking['refund_status']],
                    ['amount' => $amount, 'status' => 'processed', 'ref' => $ref],
                    $note);

                try { Notify::refundProcessed($booking, $amount, $ref); }
                catch (Throwable $e) { Logger::error('refund notify failed', ['e' => $e->getMessage()], 'notify'); }

                $flash = ['ok', 'Refund of ' . inr($amount) . ' approved for ' . $booking['pnr'] . ' — the customer has been notified.'];

            } elseif ($action === 'deny') {
                if ($note === '') {
                    throw new RuntimeException('Give a reason before declining — the customer is told what it is.');
                }

                Database::update('bookings', [
                    'refund_status' => 'denied',
                ], 'id = :i', ['i' => $bookingId]);

                Logger::audit('refund.deny', 'booking', (string) $booking['pnr'],
                    ['status' => (string) $booking['refund_status']], ['status' => 'denied'], $note);

                try { Notify::refundDenied($booking, $note); }
                catch (Throwable $e) { Logger::error('refund notify failed', ['e' => $e->getMessage()], 'notify'); }

                $flash = ['ok', 'Refund for ' . $booking['pnr'] . ' declined — the customer has been told why.'];

            } elseif ($action === 'reopen') {
                Database::update('bookings', ['refund_status' => 'pending'], 'id = :i', ['i' => $bookingId]);
                Logger::audit('refund.reopen', 'booking', (string) $booking['pnr'], null, null, $note);
                $flash = ['ok', 'Refund for ' . $booking['pnr'] . ' put back in the queue.'];

            } else {
                $flash = ['bad', 'Unknown action.'];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- The queue ----------------------------------------------------- */
$tabs   = ['pending' => 'Awaiting decision', 'processed' => 'Approved', 'denied' => 'Declined'];
$tab    = (string) ($_GET['tab'] ?? 'pending');
// Deep link from booking-view.php: highlight + scroll to this PNR's row.
$hlPnr  = Security::clean($_GET['pnr'] ?? '', 30);
if (!isset($tabs[$tab])) { $tab = 'pending'; }

$rows = Database::fetchAll(
    "SELECT b.id, b.pnr, b.contact_phone, b.contact_email, b.total_amount, b.refund_amount,
            b.refund_status, b.refund_ref, b.cancel_reason, b.cancelled_at, b.created_at,
            r.from_city, r.to_city, bl.travel_date,
            (SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id) AS seat_count
       FROM bookings b
       LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
       LEFT JOIN schedules s ON s.id = bl.schedule_id
       LEFT JOIN routes    r ON r.id = s.route_id
      WHERE b.refund_status = :st
      ORDER BY b.cancelled_at DESC, b.id DESC
      LIMIT 300",
    ['st' => $tab]
);

/* Counts for the tab labels — one grouped query, not three. */
$counts = ['pending' => 0, 'processed' => 0, 'denied' => 0];
foreach (Database::fetchAll(
    "SELECT refund_status, COUNT(*) AS n FROM bookings
      WHERE refund_status <> 'none' GROUP BY refund_status"
) as $c) {
    $counts[(string) $c['refund_status']] = (int) $c['n'];
}

$pendingValue = (float) Database::scalar(
    "SELECT COALESCE(SUM(refund_amount), 0) FROM bookings WHERE refund_status = 'pending'", [], 0
);
$paidThisMonth = (float) Database::scalar(
    "SELECT COALESCE(SUM(refund_amount), 0) FROM bookings
      WHERE refund_status = 'processed' AND cancelled_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", [], 0
);

/* Decision trail — who approved or declined what, most recent first. */
$trail = Database::fetchAll(
    "SELECT action, entity_id, actor_name, detail, created_at
       FROM audit_logs
      WHERE action IN ('refund.approve','refund.deny','refund.reopen')
      ORDER BY id DESC LIMIT 25"
);

$canProcess = Auth::can('refunds.process');
$csrf       = Security::e(Security::csrfToken());
$k          = CSRF_TOKEN_NAME;

admin_header('Refunds', 'refunds');

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}
?>
<div class="cards">
  <div class="card"><div class="k">Awaiting decision</div><div class="v"><?= $counts['pending'] ?></div></div>
  <div class="card"><div class="k">Value pending</div><div class="v"><?= Security::e(inr($pendingValue)) ?></div></div>
  <div class="card"><div class="k">Approved this month</div><div class="v"><?= Security::e(inr($paidThisMonth)) ?></div></div>
  <div class="card"><div class="k">Declined (all time)</div><div class="v"><?= $counts['denied'] ?></div></div>
</div>

<div class="toolbar">
  <?php foreach ($tabs as $tk => $tlabel): ?>
    <a class="btn <?= $tk === $tab ? '' : 'ghost' ?>" href="?tab=<?= Security::e($tk) ?>">
      <?= Security::e($tlabel) ?> (<?= $counts[$tk] ?>)
    </a>
  <?php endforeach; ?>
</div>

<div class="panel">
  <h2><?= Security::e($tabs[$tab]) ?> · <?= count($rows) ?> booking<?= count($rows) === 1 ? '' : 's' ?></h2>
  <table>
    <thead><tr>
      <th>PNR</th><th>Route / Date</th><th>Paid</th><th>Refund due</th>
      <th>Cancelled</th><th style="width:300px"><?= $canProcess ? 'Decision' : 'Status' ?></th>
    </tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="6" class="muted" style="padding:26px;text-align:center">
        <?= $tab === 'pending' ? '🎉 No refunds waiting. All settled.' : 'Nothing here yet.' ?>
      </td></tr>
    <?php else: foreach ($rows as $q): ?>
      <tr data-pnr="<?= Security::e($q['pnr']) ?>">
        <td class="mono">
          <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($q['pnr']) ?>"><?= Security::e($q['pnr']) ?></a>
          <div class="muted"><?= Security::e($q['contact_phone']) ?></div>
        </td>
        <td>
          <?= Security::e(($q['from_city'] ?? '—') . ' → ' . ($q['to_city'] ?? '—')) ?>
          <div class="muted"><?= Security::e(formatDate($q['travel_date'] ?? null)) ?> · <?= (int) $q['seat_count'] ?> seat<?= (int) $q['seat_count'] === 1 ? '' : 's' ?></div>
        </td>
        <td><?= Security::e(inr((float) $q['total_amount'])) ?></td>
        <td><strong><?= Security::e(inr((float) $q['refund_amount'])) ?></strong>
          <?php if ((float) $q['total_amount'] > 0): ?>
            <div class="muted"><?= (int) round((float) $q['refund_amount'] * 100 / (float) $q['total_amount']) ?>% of fare</div>
          <?php endif; ?>
        </td>
        <td>
          <?= Security::e($q['cancelled_at'] ? timeAgo((string) $q['cancelled_at']) : '—') ?>
          <div class="muted"><?= Security::e(truncate((string) ($q['cancel_reason'] ?? ''), 48)) ?></div>
        </td>
        <td>
          <?php if (!$canProcess): ?>
            <?= admin_pill($tab === 'processed' ? 'confirmed' : ($tab === 'denied' ? 'rejected' : 'pending')) ?>
            <?php if (!empty($q['refund_ref'])): ?><div class="muted mono"><?= Security::e($q['refund_ref']) ?></div><?php endif; ?>
          <?php elseif ($tab === 'pending'): ?>
            <form method="post" class="row-actions" style="align-items:center">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
              <input type="hidden" name="booking_id" value="<?= (int) $q['id'] ?>">
              <input type="number" name="amount" step="0.01" min="0" max="<?= Security::e((string) $q['total_amount']) ?>"
                     value="<?= Security::e(number_format((float) $q['refund_amount'], 2, '.', '')) ?>"
                     title="Amount to refund" aria-label="Refund amount"
                     style="width:92px;padding:7px 9px;border:1px solid var(--line);border-radius:8px;font-size:12px">
              <input type="text" name="ref" placeholder="UPI / bank ref" maxlength="80"
                     style="width:110px;padding:7px 9px;border:1px solid var(--line);border-radius:8px;font-size:12px">
              <input type="text" name="note" placeholder="Reason (needed to decline)" maxlength="255"
                     style="flex:1;min-width:120px;padding:7px 9px;border:1px solid var(--line);border-radius:8px;font-size:12px">
              <button class="btn ok" type="submit" name="action" value="approve"
                      onclick="return confirm('Approve this refund and message the customer?')">✓ Approve</button>
              <button class="btn bad" type="submit" name="action" value="deny"
                      onclick="return confirm('Decline this refund? The customer is told the reason you typed.')">✕ Decline</button>
            </form>
          <?php else: ?>
            <?= admin_pill($tab === 'processed' ? 'confirmed' : 'rejected') ?>
            <?php if (!empty($q['refund_ref'])): ?>
              <div class="muted mono">ref <?= Security::e($q['refund_ref']) ?></div>
            <?php endif; ?>
            <?php if ($tab === 'denied'): ?>
              <form method="post" style="margin-top:6px">
                <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
                <input type="hidden" name="booking_id" value="<?= (int) $q['id'] ?>">
                <input type="hidden" name="note" value="Re-opened for review">
                <button class="btn ghost" type="submit" name="action" value="reopen" style="padding:5px 9px;font-size:12px">↩ Re-open</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ($trail !== []): ?>
<div class="panel">
  <h2>Decision trail</h2>
  <table>
    <thead><tr><th>When</th><th>PNR</th><th>Decision</th><th>By</th><th>Note</th></tr></thead>
    <tbody>
    <?php foreach ($trail as $t): ?>
      <tr>
        <td class="muted"><?= Security::e(timeAgo((string) $t['created_at'])) ?></td>
        <td class="mono"><?= Security::e((string) $t['entity_id']) ?></td>
        <td>
          <?php $a = (string) $t['action']; ?>
          <?= $a === 'refund.approve' ? admin_pill('confirmed') : ($a === 'refund.deny' ? admin_pill('rejected') : admin_pill('pending')) ?>
        </td>
        <td><?= Security::e((string) ($t['actor_name'] ?: 'system')) ?></td>
        <td class="muted"><?= Security::e(truncate((string) ($t['detail'] ?? ''), 70)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<p class="muted">
  A refund appears here the moment a paid booking is cancelled — the amount comes from the
  cancellation policy in <span class="mono">Fare::refundFor()</span>. Approving messages the customer
  on WhatsApp, SMS and email; declining tells them the reason you typed. Both are written to the audit log.
</p>
<?php
/* Deep link from booking-view.php (?pnr=): highlight + scroll to that row. */
if ($hlPnr !== '') {
    echo '<script>(function(){var p=' . json_encode($hlPnr) . ';var tds=document.querySelectorAll("td");'
       . 'for(var i=0;i<tds.length;i++){if(tds[i].textContent.trim()===p){var tr=tds[i].closest("tr");'
       . 'if(tr){tr.classList.add("row-highlight");tr.scrollIntoView({block:"center"});}break;}}})();</script>';
}
admin_footer();
