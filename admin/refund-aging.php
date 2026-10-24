<?php
/**
 * admin/refund-aging.php — how old is the refund backlog?
 *
 * The refund desk (admin/refunds.php) is the processing queue — newest
 * cancellations first — and settlement is deliberately MANUAL (the owner
 * pays each refund by hand and records the reference). This page is the
 * read-only companion that answers "which customers have waited longest?":
 * it lists every pending refund OLDEST first, shows days waiting, and
 * buckets the backlog by age with a count + value per bucket, so the oldest
 * (and the money stuck > 30 days) can be cleared first.
 *
 * Read-only: no POST, no writes, moves no money — it only reads the pending
 * rows the cancellation policy already parked. Settle on the Refund desk.
 * Gated on refunds.view, the same as that desk.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('refunds.view');

$base  = '';
$today = new DateTimeImmutable('today');

/* Pending refunds, oldest first — same join as the refund desk for route/date. */
$rows = Database::fetchAll(
    "SELECT b.pnr, b.contact_phone, b.total_amount, b.refund_amount,
            b.cancelled_at, b.created_at,
            r.from_city, r.to_city, bl.travel_date
       FROM bookings b
       LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
       LEFT JOIN schedules s ON s.id = bl.schedule_id
       LEFT JOIN routes    r ON r.id = s.route_id
      WHERE b.refund_status = 'pending'
      ORDER BY COALESCE(b.cancelled_at, b.created_at) ASC, b.pnr ASC
      LIMIT 500"
);

/* Authoritative backlog totals — uncapped, off bookings alone, so the two
   headline cards match the Refund desk's "Value pending" tile exactly (the
   oldest-first list below is capped at 500 rows for display). */
$agg          = Database::fetch("SELECT COUNT(*) AS cnt, COALESCE(SUM(refund_amount), 0) AS val FROM bookings WHERE refund_status = 'pending'");
$pendingCount = (int) ($agg['cnt'] ?? 0);
$pendingValue = (float) ($agg['val'] ?? 0.0);

/* Age each row (days since it was cancelled, falling back to created). */
$ageOf = static function (array $q) use ($today): ?int {
    $when = (string) ($q['cancelled_at'] ?? '') ?: (string) ($q['created_at'] ?? '');
    if ($when === '') { return null; }
    $ts = strtotime($when);
    if ($ts === false) { return null; }
    $when0 = (new DateTimeImmutable())->setTimestamp($ts)->setTime(0, 0);
    return (int) $when0->diff($today)->format('%r%a');   // +days in the past (− if somehow future)
};

/* Buckets: label, min-days, max-days (inclusive). */
$bucketDefs = [
    ['0–7 days',    0,   7],
    ['8–30 days',   8,   30],
    ['31–59 days',  31,  59],
    ['60+ days',    60,  PHP_INT_MAX],
];
$buckets = [];
foreach ($bucketDefs as $i => $b) { $buckets[$i] = ['label' => $b[0], 'count' => 0, 'value' => 0.0]; }

$oldCount   = 0;      // waiting > 30 days
$oldValue   = 0.0;
$listValue  = 0.0;    // sum of refund_amount over the (capped) list below
$list       = [];     // rows with age attached

foreach ($rows as $q) {
    $age = $ageOf($q);
    $amt = (float) $q['refund_amount'];
    $listValue += $amt;
    if ($age !== null) {
        foreach ($bucketDefs as $i => $b) {
            if ($age >= $b[1] && $age <= $b[2]) { $buckets[$i]['count']++; $buckets[$i]['value'] += $amt; break; }
        }
        if ($age > 30) { $oldCount++; $oldValue += $amt; }
    }
    $q['_age'] = $age;
    $list[]    = $q;
}
$listCount = count($list);

admin_header('Refund Aging', 'refund-aging');
?>
<div class="cards">
  <div class="card"><div class="k">Pending refunds</div><div class="v"><?= $pendingCount ?></div></div>
  <div class="card"><div class="k">Value pending</div><div class="v"><?= Security::e(inr($pendingValue)) ?></div></div>
  <div class="card"><div class="k">Waiting &gt; 30 days</div><div class="v" style="color:<?= $oldCount > 0 ? '#c0392b' : 'inherit' ?>"><?= $oldCount ?></div></div>
  <div class="card"><div class="k">Value &gt; 30 days</div><div class="v" style="color:<?= $oldValue > 0 ? '#c0392b' : 'inherit' ?>"><?= Security::e(inr($oldValue)) ?></div></div>
</div>

<div class="panel">
  <h2>Backlog by age</h2>
  <table>
    <thead><tr><th>Age</th><th style="text-align:right">Count</th><th style="text-align:right">Value</th></tr></thead>
    <tbody>
    <?php foreach ($buckets as $b): ?>
      <tr>
        <td><?= Security::e($b['label']) ?></td>
        <td style="text-align:right"><?= $b['count'] ?></td>
        <td style="text-align:right"><?= Security::e(inr($b['value'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr style="border-top:2px solid var(--line);font-weight:700">
      <td>Total</td>
      <td style="text-align:right"><?= $listCount ?></td>
      <td style="text-align:right"><?= Security::e(inr($listValue)) ?></td>
    </tr></tfoot>
  </table>
</div>

<div class="panel">
  <h2>Pending refunds · oldest first<?= $pendingCount > $listCount ? ' · showing oldest ' . $listCount . ' of ' . $pendingCount : '' ?></h2>
  <table>
    <thead><tr>
      <th>PNR</th><th>Route / Date</th><th style="text-align:right">Refund due</th>
      <th style="text-align:right">Waiting</th><th>Cancelled</th><th></th>
    </tr></thead>
    <tbody>
    <?php if ($list === []): ?>
      <tr><td colspan="6" class="muted" style="padding:26px;text-align:center">🎉 No refunds waiting — all settled.</td></tr>
    <?php else: foreach ($list as $q):
      $age = $q['_age'];
    ?>
      <tr>
        <td class="mono">
          <?= Security::e((string) $q['pnr']) ?>
          <div class="muted"><?= Security::e((string) ($q['contact_phone'] ?? '')) ?></div>
        </td>
        <td>
          <?= Security::e(((string) ($q['from_city'] ?? '—')) . ' → ' . ((string) ($q['to_city'] ?? '—'))) ?>
          <div class="muted"><?= Security::e(formatDate($q['travel_date'] ?? null)) ?></div>
        </td>
        <td style="text-align:right;font-weight:700"><?= Security::e(inr((float) $q['refund_amount'])) ?></td>
        <td style="text-align:right">
          <?php if ($age === null): ?><span class="muted">—</span>
          <?php else: ?><span style="font-weight:700;color:<?= $age > 30 ? '#c0392b' : ($age > 7 ? '#8a6d00' : 'inherit') ?>"><?= $age ?> day<?= $age === 1 ? '' : 's' ?></span><?php endif; ?>
        </td>
        <td class="muted"><?= Security::e($q['cancelled_at'] ? timeAgo((string) $q['cancelled_at']) : '—') ?></td>
        <td><a class="btn ghost" style="padding:5px 10px;font-size:12px" href="<?= $base ?>/admin/refunds.php?tab=pending&pnr=<?= urlencode((string) $q['pnr']) ?>">Settle</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<p class="muted">
  Read-only — this page moves no money. Approve or decline each refund on the
  <a href="<?= $base ?>/admin/refunds.php">Refund desk</a>; the amount comes from the cancellation policy and the office settles it by hand.
  "Waiting" counts days since the booking was cancelled.
</p>
<?php
admin_footer();
