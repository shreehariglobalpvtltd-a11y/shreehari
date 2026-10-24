<?php
/**
 * admin/fill-rate.php — seat fill-rate trend (all scheduled trips).
 *
 * Analytics shows a current per-route occupancy snapshot; this page shows how
 * the OVERALL fill rate moves month to month, so the office can see whether the
 * core "how full are the buses" number is improving. The denominator is EVERY
 * scheduled (non-cancelled) departure's seat capacity — empty ones included —
 * so the figure reflects the true network fill, not just trips that sold. Seats
 * sold = confirmed/completed, non-released booking_seats on those departures.
 *
 * Read-only, no writes, no money. Each query is guarded so a missing table/
 * column degrades to zero rather than 500-ing the page. Gated on dashboard.view,
 * the same as Analytics.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$base = '';
$months = (int) ($_GET['months'] ?? 12);
if (!in_array($months, [6, 12], true)) { $months = 12; }
// Anchor to the 1st of the month so month subtraction can't overflow a short month.
$anchor = new DateTimeImmutable('first day of this month');
$start  = $anchor->modify('-' . ($months - 1) . ' months')->format('Y-m-01');
$end    = $anchor->modify('+1 month')->format('Y-m-01');   // exclusive upper bound

/* Capacity per month — ALL non-cancelled scheduled departures (incl. empty),
   read off the bus the schedule runs: its own, else the route's default, else
   the schedule's recorded seat count as a last resort (so an unbused but
   scheduled departure still contributes to this all-trips denominator —
   analytics.php uses a sold-trips basis and deliberately skips total_seats). */
$cap = [];
try {
    foreach (Database::fetchAll(
        "SELECT DATE_FORMAT(s.travel_date,'%Y-%m') AS ym,
                SUM(COALESCE(bus.total_seats, rb.total_seats, NULLIF(s.total_seats,0), 0)) AS cap
           FROM schedules s
           JOIN routes r ON r.id = s.route_id
      LEFT JOIN buses bus ON bus.id = s.bus_id
      LEFT JOIN buses rb  ON rb.id  = r.bus_id
          WHERE s.travel_date >= :s AND s.travel_date < :e AND s.status <> 'cancelled'
          GROUP BY ym",
        ['s' => $start, 'e' => $end]
    ) as $row) { $cap[(string) $row['ym']] = (int) $row['cap']; }
} catch (Throwable $e) { $cap = []; }

/* Seats sold per month — confirmed/completed, non-released booking_seats on
   departures travelling that month. */
$sold = [];
try {
    foreach (Database::fetchAll(
        "SELECT DATE_FORMAT(s.travel_date,'%Y-%m') AS ym, COUNT(*) AS sold
           FROM booking_seats bs
           JOIN schedules s ON s.id = bs.schedule_id
           JOIN bookings  b ON b.id = bs.booking_id
          WHERE bs.released_at IS NULL
            AND b.status IN ('confirmed','completed')
            AND s.status <> 'cancelled'
            AND s.travel_date >= :s AND s.travel_date < :e
          GROUP BY ym",
        ['s' => $start, 'e' => $end]
    ) as $row) { $sold[(string) $row['ym']] = (int) $row['sold']; }
} catch (Throwable $e) { $sold = []; }

/* Month series (oldest → newest). */
$series = [];
for ($i = $months - 1; $i >= 0; $i--) {
    $ym = $anchor->modify('-' . $i . ' months')->format('Y-m');
    $c  = $cap[$ym]  ?? 0;
    $sd = $sold[$ym] ?? 0;
    $series[] = [
        'label' => date('M Y', strtotime($ym . '-01')),
        'cap'   => $c,
        'sold'  => $sd,
        'occ'   => $c > 0 ? round($sd * 100 / $c, 1) : null,
    ];
}
$totCap  = array_sum(array_column($series, 'cap'));
$totSold = array_sum(array_column($series, 'sold'));
$avgOcc  = $totCap > 0 ? round($totSold * 100 / $totCap, 1) : null;
$cur     = $series !== [] ? $series[count($series) - 1] : ['cap' => 0, 'sold' => 0, 'occ' => null];

admin_header('Fill Rate', 'fill-rate');
?>
<style>.fr-bar{height:8px;border-radius:4px;background:#2E5FA8;min-width:2px;margin-top:4px}</style>

<div class="cards">
  <div class="card"><div class="k">Fill rate · this month</div><div class="v"><?= $cur['occ'] === null ? '—' : ($cur['occ'] . '%') ?></div></div>
  <div class="card"><div class="k">Fill rate · <?= (int) $months ?>-mo avg</div><div class="v"><?= $avgOcc === null ? '—' : ($avgOcc . '%') ?></div></div>
  <div class="card"><div class="k">Seats sold · <?= (int) $months ?> mo</div><div class="v"><?= number_format($totSold) ?></div></div>
  <div class="card"><div class="k">Seats scheduled · <?= (int) $months ?> mo</div><div class="v"><?= number_format($totCap) ?></div></div>
</div>

<form method="get" class="toolbar">
  <label>Window
    <select name="months" onchange="this.form.submit()">
      <?php foreach ([6 => 'Last 6 months', 12 => 'Last 12 months'] as $m => $lbl): ?>
        <option value="<?= $m ?>" <?= $m === $months ? 'selected' : '' ?>><?= Security::e($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn ghost" type="submit">Load</button>
</form>

<div class="panel">
  <h2>Monthly fill rate · last <?= (int) $months ?> months</h2>
  <table>
    <thead><tr><th>Month</th><th style="text-align:right">Seats scheduled</th><th style="text-align:right">Seats sold</th><th style="text-align:right">Fill rate</th><th style="width:180px"></th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($series) as $row):
      $barW = $row['occ'] === null ? 0 : max(2, min(100, (int) round($row['occ'])));
    ?>
      <tr>
        <td><?= Security::e($row['label']) ?></td>
        <td style="text-align:right"><?= number_format($row['cap']) ?></td>
        <td style="text-align:right"><?= number_format($row['sold']) ?></td>
        <td style="text-align:right;font-weight:700"><?= $row['occ'] === null ? '—' : ($row['occ'] . '%') ?></td>
        <td><?php if ($row['occ'] !== null): ?><div class="fr-bar" style="width:<?= $barW ?>%"></div><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr style="border-top:2px solid var(--line);font-weight:700">
      <td>Total · <?= (int) $months ?> months</td>
      <td style="text-align:right"><?= number_format($totCap) ?></td>
      <td style="text-align:right"><?= number_format($totSold) ?></td>
      <td style="text-align:right"><?= $avgOcc === null ? '—' : ($avgOcc . '%') ?></td>
      <td></td>
    </tr></tfoot>
  </table>
  <p class="muted" style="font-size:12px;padding:8px 2px 0">
    Fill rate = confirmed/completed seats sold ÷ total seats on EVERY non-cancelled scheduled departure that month (empty departures included), so this is the true network fill — not just trips that sold. Capacity is read from each departure's bus (its own, the route's default, or its recorded seat count).
    A month can read slightly over 100% if staff/EX seats were sold beyond the coach's listed capacity or a bus was swapped to a smaller one after selling.
  </p>
</div>

<p class="muted">Read-only — this page changes nothing. Per-route occupancy and revenue live on <a href="<?= $base ?>/admin/analytics.php">Analytics</a>.</p>
<?php
admin_footer();
