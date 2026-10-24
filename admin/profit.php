<?php
/**
 * admin/profit.php — Profit & Loss trend (revenue vs operating expenses).
 *
 * The day-book (accounting.php) shows money IN; expenses.php logs money OUT
 * per route. This page ties them together over time: for each of the last N
 * months it sets confirmed+completed ticket revenue against the operating
 * expenses logged in trip_expenses, so the office sees the actual bottom line
 * and whether it is trending up or down. Company-wide (all routes) — the
 * per-route, single-month detail lives on the Trip Expenses page.
 *
 * Read-only and defensive: no new storage, no POST, moves no money. Revenue
 * is normalised to INR with the company peg and the confirmed/completed paid
 * set — the same basis as Accounting and Analytics, so the figures reconcile.
 * Gated on payments.view (office money scope).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('payments.view');

$base = '';
if (!defined('NPR_PER_INR')) {
    define('NPR_PER_INR', 1.6);
}
/** total_amount in INR regardless of stored currency — same fragment as analytics.php. */
const REV_INR = "SUM(CASE WHEN currency='NPR' THEN total_amount/" . NPR_PER_INR . " ELSE total_amount END)";
$paidStatuses = "'confirmed','completed'";

/* ---- Range: last N months (3 / 6 / 12), default 6 ------------------- */
$months = (int) ($_GET['months'] ?? 6);
if (!in_array($months, [3, 6, 12], true)) {
    $months = 6;
}
// Anchor to the FIRST of the current month so month subtraction can never
// overflow a shorter month (today=31st minus a month lands on day-end and
// would duplicate/drop a bucket). The 1st exists in every month.
$anchor     = new DateTimeImmutable('first day of this month');
$startMonth = $anchor->modify('-' . ($months - 1) . ' months')->format('Y-m-01');  // 1st of the earliest month

/* ---- Revenue per month --------------------------------------------- */
$rev = [];   // 'YYYY-MM' => INR
try {
    foreach (Database::fetchAll(
        "SELECT DATE_FORMAT(confirmed_at,'%Y-%m') AS ym, " . REV_INR . " AS v
           FROM bookings
          WHERE status IN ($paidStatuses) AND confirmed_at >= :s
          GROUP BY ym",
        ['s' => $startMonth . ' 00:00:00']
    ) as $r) {
        $rev[(string) $r['ym']] = (float) $r['v'];
    }
} catch (Throwable $e) {
    // Core tables are always present; degrade to zero rather than 500 a panel.
}

/* ---- Expenses per month (trip_expenses may be absent on an old DB) -- */
$exp     = [];
$haveExp = true;
try {
    foreach (Database::fetchAll(
        "SELECT DATE_FORMAT(expense_date,'%Y-%m') AS ym, SUM(total) AS v
           FROM trip_expenses WHERE expense_date >= :s GROUP BY ym",
        ['s' => $startMonth]
    ) as $r) {
        $exp[(string) $r['ym']] = (float) $r['v'];
    }
} catch (Throwable $e) {
    $haveExp = false;
}

/* ---- Build the month series (oldest → newest) ---------------------- */
$series = [];
$maxAbs = 0.0;
for ($i = $months - 1; $i >= 0; $i--) {
    $ym  = $anchor->modify('-' . $i . ' months')->format('Y-m');
    $rv  = $rev[$ym] ?? 0.0;
    $ex  = $exp[$ym] ?? 0.0;
    $net = $rv - $ex;
    $series[] = [
        'label'  => date('M Y', strtotime($ym . '-01')),
        'rev'    => $rv,
        'exp'    => $ex,
        'net'    => $net,
        'margin' => $rv > 0 ? (int) round($net * 100 / $rv) : null,
    ];
    $maxAbs = max($maxAbs, $rv, $ex);
}

$totRev = array_sum(array_column($series, 'rev'));
$totExp = array_sum(array_column($series, 'exp'));
$totNet = $totRev - $totExp;

/* Current month = the last row in the series, for the summary cards. */
$cur = $series !== [] ? $series[count($series) - 1] : ['rev' => 0.0, 'exp' => 0.0, 'net' => 0.0, 'margin' => null];

admin_header('Profit & Loss', 'profit');
?>
<style>
.pl-barcell{min-width:160px}
.pl-bars{display:flex;flex-direction:column;gap:3px;margin-top:3px}
.pl-bar{height:8px;border-radius:4px;min-width:2px}
.pl-bar.rev{background:#2E5FA8}
.pl-bar.exp{background:#c99200}
.pl-legend{display:flex;gap:16px;font-size:12px;color:var(--mut);margin:2px 2px 12px}
.pl-legend span{display:inline-flex;align-items:center;gap:6px}
.pl-swatch{width:12px;height:12px;border-radius:3px;display:inline-block}
</style>

<div class="cards">
  <div class="card"><div class="k">Revenue · this month</div><div class="v"><?= Security::e(inr($cur['rev'])) ?></div></div>
  <div class="card"><div class="k">Expenses · this month</div><div class="v"><?= Security::e(inr($cur['exp'])) ?></div></div>
  <div class="card">
    <div class="k">Net · this month</div>
    <div class="v" style="color:<?= $cur['net'] >= 0 ? '#0a6b3b' : '#c0392b' ?>">
      <?= $cur['net'] < 0 ? '−' : '' ?><?= Security::e(inr(abs($cur['net']))) ?>
    </div>
  </div>
  <div class="card"><div class="k">Margin · this month</div><div class="v"><?= $cur['margin'] === null ? '—' : ($cur['margin'] . '%') ?></div></div>
</div>

<form method="get" class="toolbar">
  <label>Window
    <select name="months" onchange="this.form.submit()">
      <?php foreach ([3, 6, 12] as $m): ?>
        <option value="<?= $m ?>" <?= $m === $months ? 'selected' : '' ?>>Last <?= $m ?> months</option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn ghost" type="submit">Load</button>
</form>

<?php if (!$haveExp): ?>
<div class="flash bad" style="margin-bottom:14px">
  Expenses aren't being counted yet — the <span class="mono">trip_expenses</span> table isn't in this database.
  Apply <span class="mono">database/upgrade-2026-10-trip-expenses.sql</span> (see the Trip Expenses page), then this P&amp;L will include costs.
</div>
<?php endif; ?>

<div class="pl-legend">
  <span><span class="pl-swatch" style="background:#2E5FA8"></span> Revenue</span>
  <span><span class="pl-swatch" style="background:#c99200"></span> Expenses</span>
</div>

<div class="panel">
  <h2>Monthly profit / loss · last <?= (int) $months ?> months</h2>
  <table>
    <thead><tr>
      <th>Month</th><th style="text-align:right">Revenue</th><th style="text-align:right">Expenses</th>
      <th style="text-align:right">Net</th><th style="text-align:right">Margin</th><th class="pl-barcell">Revenue vs expenses</th>
    </tr></thead>
    <tbody>
    <?php foreach (array_reverse($series) as $row):
      $revW = $maxAbs > 0 ? max(2, round($row['rev'] * 100 / $maxAbs)) : 0;
      $expW = $maxAbs > 0 ? max(2, round($row['exp'] * 100 / $maxAbs)) : 0;
    ?>
      <tr>
        <td><?= Security::e($row['label']) ?></td>
        <td style="text-align:right"><?= Security::e(inr($row['rev'])) ?></td>
        <td style="text-align:right"><?= Security::e(inr($row['exp'])) ?></td>
        <td style="text-align:right;font-weight:700;color:<?= $row['net'] >= 0 ? '#0a6b3b' : '#c0392b' ?>">
          <?= $row['net'] < 0 ? '−' : '' ?><?= Security::e(inr(abs($row['net']))) ?>
        </td>
        <td style="text-align:right" class="muted"><?= $row['margin'] === null ? '—' : ($row['margin'] . '%') ?></td>
        <td class="pl-barcell">
          <div class="pl-bars">
            <div class="pl-bar rev" style="width:<?= $revW ?>%" title="Revenue <?= Security::e(inr($row['rev'])) ?>"></div>
            <div class="pl-bar exp" style="width:<?= $expW ?>%" title="Expenses <?= Security::e(inr($row['exp'])) ?>"></div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr style="border-top:2px solid var(--line);font-weight:700">
      <td>Total · <?= (int) $months ?> months</td>
      <td style="text-align:right"><?= Security::e(inr($totRev)) ?></td>
      <td style="text-align:right"><?= Security::e(inr($totExp)) ?></td>
      <td style="text-align:right;color:<?= $totNet >= 0 ? '#0a6b3b' : '#c0392b' ?>">
        <?= $totNet < 0 ? '−' : '' ?><?= Security::e(inr(abs($totNet))) ?>
      </td>
      <td style="text-align:right" class="muted"><?= $totRev > 0 ? (int) round($totNet * 100 / $totRev) . '%' : '—' ?></td>
      <td></td>
    </tr></tfoot>
  </table>
  <p class="muted" style="font-size:12px;padding:8px 2px 0">
    Revenue = confirmed/completed ticket money (NPR converted to ₹), by the month it was confirmed — the same basis as Accounting and Analytics.
    Expenses = what's logged on the <a href="<?= $base ?>/admin/expenses.php">Trip Expenses</a> page, by expense date. Net = revenue − expenses.
    (The Trip Expenses page totals revenue by travel date per route, so its monthly figure can differ slightly from this confirmation-date view.)
  </p>
</div>

<p class="muted">
  This is read-only reporting — it moves no money and changes nothing. Log a route's costs on the
  <a href="<?= $base ?>/admin/expenses.php">Trip Expenses</a> page and they flow into this P&amp;L.
</p>
<?php
admin_footer();
