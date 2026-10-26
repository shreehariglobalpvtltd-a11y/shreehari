<?php
/**
 * admin/cancellations.php — why bookings are cancelled.
 *
 * Analytics shows revenue and the status mix, but nothing breaks down WHY
 * money is lost to cancellations. This read-only report, over a selectable
 * window, shows the cancellation count + rate, the refund value, how long
 * people hold a booking before cancelling, the top cancellation reasons, and
 * which routes see the most cancellations — so the office can act on the
 * patterns instead of guessing.
 *
 * Read-only, no writes, no money movement — aggregate reporting only. Gated on
 * dashboard.view, the same as Analytics. Guarded so a missing table/column
 * degrades to zero rather than 500-ing the page.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$base = '';
$days = (int) ($_GET['days'] ?? 90);
if (!in_array($days, [30, 90, 365], true)) { $days = 90; }
$start = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

/* ---- Headline figures (guarded) ------------------------------------ */
$cancelled = 0; $refundValue = 0.0; $createdInWin = 0; $avgHeld = null;
try {
    $cancelled    = (int)   Database::scalar("SELECT COUNT(*) FROM bookings WHERE status='cancelled' AND cancelled_at >= :s", ['s' => $start], 0);
    $refundValue  = (float) Database::scalar("SELECT COALESCE(SUM(refund_amount),0) FROM bookings WHERE status='cancelled' AND cancelled_at >= :s", ['s' => $start], 0);
    $createdInWin = (int)   Database::scalar("SELECT COUNT(*) FROM bookings WHERE created_at >= :s", ['s' => $start], 0);
    $avgHeldRaw   = Database::scalar("SELECT AVG(DATEDIFF(cancelled_at, created_at)) FROM bookings WHERE status='cancelled' AND cancelled_at >= :s AND created_at IS NOT NULL", ['s' => $start], null);
    $avgHeld      = $avgHeldRaw === null ? null : (int) round((float) $avgHeldRaw);
} catch (Throwable $e) { /* degrade to zeros */ }
$rate = $createdInWin > 0 ? round($cancelled * 100 / $createdInWin, 1) : null;

/* ---- Top cancellation reasons -------------------------------------- */
$reasons = [];
try {
    $reasons = Database::fetchAll(
        "SELECT COALESCE(NULLIF(TRIM(cancel_reason), ''), '(no reason given)') AS reason,
                COUNT(*) AS c, COALESCE(SUM(refund_amount), 0) AS v
           FROM bookings
          WHERE status='cancelled' AND cancelled_at >= :s
          GROUP BY reason
          ORDER BY c DESC, v DESC
          LIMIT 15",
        ['s' => $start]
    );
} catch (Throwable $e) { $reasons = []; }
$reasonMax = 0;
foreach ($reasons as $r) { $reasonMax = max($reasonMax, (int) $r['c']); }

/* ---- Cancellations by route ---------------------------------------- */
$byRoute = [];
try {
    $byRoute = Database::fetchAll(
        "SELECT r.route_code, r.from_city, r.to_city,
                COUNT(DISTINCT b.id) AS c, COALESCE(SUM(b.refund_amount), 0) AS v
           FROM bookings b
           JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
           JOIN schedules s ON s.id = bl.schedule_id
           JOIN routes    r ON r.id = s.route_id
          WHERE b.status='cancelled' AND b.cancelled_at >= :s
          GROUP BY r.id, r.route_code, r.from_city, r.to_city
          ORDER BY c DESC, v DESC
          LIMIT 15",
        ['s' => $start]
    );
} catch (Throwable $e) { $byRoute = []; }

admin_header('Cancellations', 'cancellations');
?>
<style>
.cx-bar{height:8px;border-radius:4px;background:#c0392b;min-width:2px;margin-top:4px}
</style>

<div class="cards">
  <div class="card"><div class="k">Cancellations · <?= (int) $days ?>d</div><div class="v"><?= $cancelled ?></div></div>
  <div class="card"><div class="k">Cancellation rate</div><div class="v"><?= $rate === null ? '—' : ($rate . '%') ?></div></div>
  <div class="card"><div class="k">Refund value</div><div class="v"><?= Security::e(inr($refundValue)) ?></div></div>
  <div class="card"><div class="k">Avg days held before cancel</div><div class="v"><?= $avgHeld === null ? '—' : $avgHeld ?></div></div>
</div>

<form method="get" class="toolbar">
  <label>Window
    <select name="days" onchange="this.form.submit()">
      <?php foreach ([30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'] as $d => $lbl): ?>
        <option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>><?= Security::e($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn ghost" type="submit">Load</button>
</form>

<div class="panel">
  <h2>Top reasons · last <?= (int) $days ?> days</h2>
  <table>
    <thead><tr><th>Reason</th><th style="text-align:right">Count</th><th style="text-align:right">Share</th><th style="text-align:right">Refund value</th><th style="width:160px"></th></tr></thead>
    <tbody>
    <?php if ($reasons === []): ?>
      <tr><td colspan="5" class="muted" style="padding:22px;text-align:center">No cancellations in this window. 🎉</td></tr>
    <?php else: foreach ($reasons as $r):
      $c = (int) $r['c'];
      $pct = $cancelled > 0 ? round($c * 100 / $cancelled) : 0;
      $barW = $reasonMax > 0 ? max(2, round($c * 100 / $reasonMax)) : 0;
    ?>
      <tr>
        <td><?= Security::e((string) $r['reason']) ?></td>
        <td style="text-align:right"><?= $c ?></td>
        <td style="text-align:right" class="muted"><?= $pct ?>%</td>
        <td style="text-align:right"><?= Security::e(inr((float) $r['v'])) ?></td>
        <td><div class="cx-bar" style="width:<?= $barW ?>%"></div></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <h2>Most-cancelled routes · last <?= (int) $days ?> days</h2>
  <table>
    <thead><tr><th>Route</th><th style="text-align:right">Cancellations</th><th style="text-align:right">Refund value</th></tr></thead>
    <tbody>
    <?php if ($byRoute === []): ?>
      <tr><td colspan="3" class="muted" style="padding:22px;text-align:center">Nothing to show for this window.</td></tr>
    <?php else: foreach ($byRoute as $r): ?>
      <tr>
        <td><?= Security::e((string) $r['route_code']) ?><div class="muted" style="font-size:11px"><?= Security::e(((string) ($r['from_city'] ?? '—')) . ' → ' . ((string) ($r['to_city'] ?? '—'))) ?></div></td>
        <td style="text-align:right"><?= (int) $r['c'] ?></td>
        <td style="text-align:right"><?= Security::e(inr((float) $r['v'])) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<p class="muted">
  Read-only. Cancellations and refund value are counted by cancellation date; "Cancellation rate" is cancellations in the
  window against bookings made in the same window (a rough indicator, since the two can span different bookings).
  "Avg days held" is the average gap between booking and cancellation. Refund value is the amount the policy produced
  (settled on the <a href="<?= $base ?>/admin/refunds.php">Refund desk</a>).
</p>
<?php
admin_footer();
