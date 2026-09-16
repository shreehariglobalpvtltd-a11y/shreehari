<?php
/**
 * admin/agent-ranking.php — the agent leaderboard.
 *
 * Ranks counter agents over a chosen window by three different measures,
 * because "top agent" means different things to the people who ask for it:
 *
 *   revenue    — confirmed ticket value sold. What the month is judged on.
 *   tickets    — number of confirmed sales. Rewards a busy small-fare
 *                counter that a revenue table would bury.
 *   commission — what the agent actually earned, read from the ledger.
 *
 * A supervisor view: gated on dashboard.view, which counter agents are
 * deliberately not granted. An agent must never see another agent's takings,
 * and a leaderboard is exactly that.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$base  = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$today = todayISO();

/* ---- Window --------------------------------------------------------- */
$presets = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'all' => 'All time', 'custom' => 'Custom range'];
$preset  = (string) ($_GET['range'] ?? 'month');
if (!isset($presets[$preset])) {
    $preset = 'month';
}

$customFrom = Security::clean($_GET['from'] ?? '', 10);
$customTo   = Security::clean($_GET['to'] ?? '', 10);
$isDate     = static fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

switch ($preset) {
    case 'today':  $from = $today; $to = addDaysISO($today, 1); break;
    case 'week':   $from = date('Y-m-d', strtotime('monday this week')); $to = addDaysISO($today, 1); break;
    case 'all':    $from = '1970-01-01'; $to = addDaysISO($today, 1); break;
    case 'custom':
        $from = $isDate($customFrom) ? $customFrom : date('Y-m-01');
        $to   = $isDate($customTo) ? addDaysISO($customTo, 1) : addDaysISO($today, 1);
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        break;
    default:       $from = date('Y-m-01'); $to = addDaysISO($today, 1); break;
}

/* ---- Sales per agent for the window ---------------------------------
   LEFT JOIN from admins so an agent who sold nothing still appears with a
   zero rather than vanishing — a missing agent reads as a data bug, and
   "sold nothing this month" is exactly what a supervisor needs to see. */
$rows = Database::fetchAll(
    "SELECT a.id, a.username, a.full_name, a.is_active,
            COALESCE(COUNT(b.id), 0) AS tickets,
            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmed,
            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled
       FROM admins a
       LEFT JOIN bookings b
              ON b.sold_by_admin_id = a.id
             AND b.created_at >= :from AND b.created_at < :to
      WHERE a.role = 'agent'
      GROUP BY a.id, a.username, a.full_name, a.is_active",
    ['from' => $from, 'to' => $to]
);

/* ---- Commission earned per agent, same window, from the ledger -------- */
$commissionRows = Database::fetchAll(
    "SELECT agent_admin_id, COALESCE(SUM(amount), 0) AS earned
       FROM agent_ledger
      WHERE account = 'commission'
        AND entry_type IN ('commission','commission_void')
        AND created_at >= :from AND created_at < :to
      GROUP BY agent_admin_id",
    ['from' => $from, 'to' => $to]
);
$commissionBy = [];
foreach ($commissionRows as $c) {
    $commissionBy[(int) $c['agent_admin_id']] = (float) $c['earned'];
}

foreach ($rows as &$r) {
    $r['commission'] = $commissionBy[(int) $r['id']] ?? 0.0;
    $r['revenue']    = (float) $r['revenue'];
    $r['confirmed']  = (int) $r['confirmed'];
    // Average ticket value — separates "sold a lot" from "sold expensive".
    $r['avg'] = $r['confirmed'] > 0 ? $r['revenue'] / $r['confirmed'] : 0.0;
}
unset($r);

$metric  = (string) ($_GET['by'] ?? 'revenue');
$metrics = ['revenue' => 'Top revenue', 'confirmed' => 'Top sales (tickets)', 'commission' => 'Top commission'];
if (!isset($metrics[$metric])) {
    $metric = 'revenue';
}

usort($rows, static function (array $x, array $y) use ($metric): int {
    // Ties break on revenue so the order is stable and never arbitrary.
    return [$y[$metric], $y['revenue']] <=> [$x[$metric], $x['revenue']];
});

$totalRevenue = 0.0;
$totalTickets = 0;
foreach ($rows as $r) { $totalRevenue += $r['revenue']; $totalTickets += $r['confirmed']; }

$leader = $rows[0] ?? null;
$hasAny = $totalTickets > 0 || $totalRevenue > 0.0;

/* ---- PDF download (§15/§28) ------------------------------------------- */
if (($_GET['format'] ?? '') === 'pdf') {
    $pdfFrom = ($preset === 'custom' && $isDate($customFrom)) ? $customFrom : substr((string) $from, 0, 10);
    $pdfTo   = ($preset === 'custom' && $isDate($customTo))   ? $customTo   : $today;
    ReportPdf::commissionReport($pdfFrom, $pdfTo);
    // ^ never returns (streams + exit)
}

admin_header('Agent Ranking', 'agent-ranking');
?>
<form class="toolbar" method="get">
  <select name="range" onchange="document.getElementById('cd').style.display = this.value === 'custom' ? 'contents' : 'none'">
    <?php foreach ($presets as $key => $label): ?>
      <option value="<?= $key ?>" <?= $preset === $key ? 'selected' : '' ?>><?= Security::e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <span id="cd" style="display:<?= $preset === 'custom' ? 'contents' : 'none' ?>">
    <input type="date" name="from" value="<?= Security::e($customFrom ?: date('Y-m-01')) ?>">
    <input type="date" name="to" value="<?= Security::e($customTo ?: $today) ?>">
  </span>
  <select name="by">
    <?php foreach ($metrics as $key => $label): ?>
      <option value="<?= $key ?>" <?= $metric === $key ? 'selected' : '' ?>><?= Security::e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Apply</button>
  <?php
    $pdfQs = ['range' => $preset, 'from' => $customFrom, 'to' => $customTo, 'by' => $metric, 'format' => 'pdf'];
    $pdfQs = array_filter($pdfQs, static fn($v) => $v !== '' && $v !== null);
  ?>
  <a class="btn ghost" style="margin-left:auto"
     href="<?= $base ?>/admin/agent-ranking.php?<?= Security::e(http_build_query($pdfQs)) ?>">📄 Commission PDF</a>
  <span class="muted" style="font-size:12px">
    <?= Security::e(formatDate($from)) ?> — <?= Security::e(formatDate(addDaysISO($to, -1))) ?>
  </span>
</form>

<?php if ($leader !== null && $hasAny): ?>
<div class="cards">
  <div class="card" style="border-color:#d4af37">
    <div class="k">🏆 <?= Security::e($metrics[$metric]) ?></div>
    <div class="v" style="font-size:20px"><?= Security::e((string) ($leader['full_name'] ?: $leader['username'])) ?></div>
    <div class="muted" style="font-size:11px">
      <?= Security::e(inr((float) $leader['revenue'])) ?> ·
      <?= (int) $leader['confirmed'] ?> ticket<?= (int) $leader['confirmed'] === 1 ? '' : 's' ?> ·
      <?= Security::e(inr((float) $leader['commission'])) ?> commission
    </div>
  </div>
  <div class="card"><div class="k">Team revenue</div><div class="v"><?= Security::e(inr($totalRevenue)) ?></div>
    <div class="muted" style="font-size:11px">across <?= count($rows) ?> agent<?= count($rows) === 1 ? '' : 's' ?></div></div>
  <div class="card"><div class="k">Team tickets</div><div class="v"><?= $totalTickets ?></div>
    <div class="muted" style="font-size:11px">confirmed in this window</div></div>
</div>
<?php endif; ?>

<div class="panel">
  <h2>📊 Leaderboard — <?= Security::e($metrics[$metric]) ?></h2>
  <table>
    <thead><tr>
      <th style="width:56px">#</th><th>Agent</th><th>Code</th><th>Tickets</th><th>Revenue</th>
      <th>Avg ticket</th><th>Commission</th><th style="width:22%">Share of revenue</th>
    </tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="8" class="muted" style="padding:22px;text-align:center">
        No agents yet. Create one under Staff &amp; Agents.
      </td></tr>
    <?php elseif (!$hasAny): ?>
      <tr><td colspan="8" class="muted" style="padding:22px;text-align:center">
        No sales in this window — try a wider range.
      </td></tr>
    <?php else:
      $medals = ['🥇', '🥈', '🥉'];
      foreach ($rows as $i => $r):
        $share = $totalRevenue > 0 ? (int) round($r['revenue'] * 100 / $totalRevenue) : 0;
    ?>
      <tr>
        <td style="font-size:17px"><?= $medals[$i] ?? '<span class="muted" style="font-size:14px">' . ($i + 1) . '</span>' ?></td>
        <td><strong><?= Security::e((string) ($r['full_name'] ?: $r['username'])) ?></strong>
          <div class="muted mono" style="font-size:11px"><?= Security::e((string) $r['username']) ?><?= (int) $r['is_active'] === 1 ? '' : ' · inactive' ?></div></td>
        <td class="mono"><?= Security::e(AgentWallet::agentCodeLabel((int) $r['id'])) ?></td>
        <td><?= (int) $r['confirmed'] ?>
          <?php if ((int) $r['cancelled'] > 0): ?>
            <div class="muted" style="font-size:11px"><?= (int) $r['cancelled'] ?> cancelled</div>
          <?php endif; ?></td>
        <td><strong><?= Security::e(inr((float) $r['revenue'])) ?></strong></td>
        <td class="muted"><?= Security::e(inr((float) $r['avg'])) ?></td>
        <td style="color:#0a6b3b;font-weight:700"><?= Security::e(inr((float) $r['commission'])) ?></td>
        <td>
          <div style="height:7px;border-radius:99px;background:var(--line);overflow:hidden" title="<?= $share ?>%">
            <div style="width:<?= $share ?>%;height:100%;background:<?= $i === 0 ? '#d4af37' : '#2E5FA8' ?>"></div>
          </div>
          <div class="muted" style="font-size:11px;margin-top:2px"><?= $share ?>%</div>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<p class="muted" style="font-size:12.5px">
  Revenue and ticket counts are confirmed sales attributed through
  <span class="mono">bookings.sold_by_admin_id</span>; commission is read from the wallet ledger, so it is the
  amount actually owed rather than a percentage recomputed at page load. Sales made before an agent's account
  existed are unattributed and appear under no one.
</p>
<?php
admin_footer();
