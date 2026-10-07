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
$presets = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'lastmonth' => 'Last month', 'all' => 'All time', 'custom' => 'Custom range'];
$preset  = (string) ($_GET['range'] ?? 'month');
if (!isset($presets[$preset])) {
    $preset = 'month';
}

$customFrom = Security::clean($_GET['from'] ?? '', 10);
$customTo   = Security::clean($_GET['to'] ?? '', 10);
$isDate     = static fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

switch ($preset) {
    case 'today':  $from = $today; $to = addDaysISO($today, 1); break;
    case 'yesterday': $from = addDaysISO($today, -1); $to = $today; break;
    case 'lastmonth': $to = date('Y-m-01'); $from = date('Y-m-01', strtotime('-1 month', strtotime($to))); break;
    case 'week':   $from = date('Y-m-d', strtotime('monday this week')); $to = addDaysISO($today, 1); break;
    case 'all':    $from = '1970-01-01'; $to = addDaysISO($today, 1); break;
    case 'custom':
        $from = $isDate($customFrom) ? $customFrom : date('Y-m-01');
        $to   = $isDate($customTo) ? $customTo : $today;
        // A backwards range would silently return nothing; swap BEFORE the
        // end is pushed to the next day, or both end days drop out.
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        $to = addDaysISO($to, 1);
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

/* 5 Oct 2026 (owner's Point 4): passengers carried, repeat customers and the
   cancellation rate per agent, same window. A repeat customer = a phone number
   this agent has confirmed more than one booking for (ever), with at least one
   of them inside the window; the desk's "no number" placeholder never counts. */
$seatsBy = [];
foreach (Database::fetchAll(
    "SELECT b.sold_by_admin_id AS id, COUNT(*) AS seats
       FROM bookings b JOIN booking_seats bs ON bs.booking_id = b.id AND bs.released_at IS NULL
      WHERE b.status IN ('confirmed','completed') AND b.created_at >= :from AND b.created_at < :to AND b.sold_by_admin_id IS NOT NULL
      GROUP BY b.sold_by_admin_id",
    ['from' => $from, 'to' => $to]
) as $s) {
    $seatsBy[(int) $s['id']] = (int) $s['seats'];
}
$repeatBy = [];
try {
    foreach (Database::fetchAll(
        "SELECT x.id, COUNT(*) AS customers, COALESCE(SUM(x.total_n > 1), 0) AS repeaters
           FROM (SELECT b.sold_by_admin_id AS id, b.contact_phone,
                        COUNT(*) AS total_n,
                        SUM(b.created_at >= :from AND b.created_at < :to) AS in_window
                   FROM bookings b
                  WHERE b.status IN ('confirmed','completed') AND b.sold_by_admin_id IS NOT NULL
                    AND b.contact_phone REGEXP '^[1-9][0-9]{9,12}$'
                  GROUP BY b.sold_by_admin_id, b.contact_phone
                 HAVING in_window > 0) x
          GROUP BY x.id",
        ['from' => $from, 'to' => $to]
    ) as $rp) {
        $repeatBy[(int) $rp['id']] = ['customers' => (int) $rp['customers'], 'repeaters' => (int) $rp['repeaters']];
    }
} catch (Throwable $e) {
    $repeatBy = [];
}

foreach ($rows as &$r) {
    $r['commission'] = $commissionBy[(int) $r['id']] ?? 0.0;
    $r['revenue']    = (float) $r['revenue'];
    $r['confirmed']  = (int) $r['confirmed'];
    // Average ticket value — separates "sold a lot" from "sold expensive".
    $r['avg'] = $r['confirmed'] > 0 ? $r['revenue'] / $r['confirmed'] : 0.0;
    $r['seats']      = $seatsBy[(int) $r['id']] ?? 0;
    $r['customers']  = $repeatBy[(int) $r['id']]['customers'] ?? 0;
    $r['repeaters']  = $repeatBy[(int) $r['id']]['repeaters'] ?? 0;
    $r['cancelPct']  = (int) $r['tickets'] > 0 ? round((int) $r['cancelled'] * 100 / (int) $r['tickets'], 1) : 0.0;
    $r['repeatPct']  = $r['customers'] > 0 ? round($r['repeaters'] * 100 / $r['customers'], 1) : 0.0;
}
unset($r);

$metric  = (string) ($_GET['by'] ?? 'revenue');
$metrics = ['revenue' => 'Top revenue', 'confirmed' => 'Top sales (tickets)', 'commission' => 'Top commission', 'seats' => 'Most passengers', 'lowcancel' => 'Lowest cancellation', 'repeaters' => 'Most repeat customers'];
if (!isset($metrics[$metric])) {
    $metric = 'revenue';
}

usort($rows, static function (array $x, array $y) use ($metric): int {
    if ($metric === 'lowcancel') {
        // Lowest cancellation rate first - among agents who actually sold in the window.
        return [(int) $y['tickets'] > 0, -$y['cancelPct'], $y['revenue']] <=> [(int) $x['tickets'] > 0, -$x['cancelPct'], $x['revenue']];
    }
    // Ties break on revenue so the order is stable and never arbitrary.
    return [$y[$metric], $y['revenue']] <=> [$x[$metric], $x['revenue']];
});

/* ---- Side-by-side comparison: ?cmp[]=<id>, two or three agents ---------- */
$cmpIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['cmp'] ?? [])), static fn(int $i): bool => $i > 0)));
$cmpIds = array_slice($cmpIds, 0, 3);
$cmp    = array_values(array_filter($rows, static fn(array $r): bool => in_array((int) $r['id'], $cmpIds, true)));
$cmpTrend = ['labels' => [], 'series' => []];
if (count($cmp) >= 2) {
    $tFrom = max($from, addDaysISO($today, -89));          // the chart shows at most the last 90 days of the window
    $tTo   = min(addDaysISO($to, -1), $today);
    $byDay = [];
    foreach (Database::fetchAll(
        "SELECT sold_by_admin_id AS id, DATE(created_at) AS d, COUNT(*) AS n
           FROM bookings
          WHERE status IN ('confirmed','completed') AND created_at >= :f AND created_at < :t
            AND sold_by_admin_id IN (" . implode(',', array_map('intval', array_column($cmp, 'id'))) . ")
          GROUP BY sold_by_admin_id, DATE(created_at)",
        ['f' => $tFrom, 't' => addDaysISO($tTo, 1)]
    ) as $d) {
        $byDay[(int) $d['id']][(string) $d['d']] = (int) $d['n'];
    }
    foreach ($cmp as $c) {
        $cmpTrend['series'][] = ['name' => (string) ($c['full_name'] ?: $c['username']), 'data' => []];
    }
    for ($day = $tFrom, $g = 0; $day <= $tTo && $g < 100; $day = addDaysISO($day, 1), $g++) {
        $cmpTrend['labels'][] = date('j M', (int) strtotime($day));
        foreach ($cmp as $i => $c) {
            $cmpTrend['series'][$i]['data'][] = $byDay[(int) $c['id']][$day] ?? 0;
        }
    }
}

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
  <?php foreach ($cmpIds as $__c): ?><input type="hidden" name="cmp[]" value="<?= (int) $__c ?>"><?php endforeach; ?>
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

<?php if (count($cmp) >= 2):
    $cmpLines = [
        ['Bookings placed',        static fn(array $r): string => (string) (int) $r['tickets'],                         'tickets',   true],
        ['Confirmed',              static fn(array $r): string => (string) (int) $r['confirmed'],                       'confirmed', true],
        ['Cancelled',              static fn(array $r): string => (int) $r['cancelled'] . ' (' . $r['cancelPct'] . '%)', 'cancelPct', false],
        ['Passengers (seats)',     static fn(array $r): string => (string) (int) $r['seats'],                           'seats',     true],
        ['Revenue',                static fn(array $r): string => inr((float) $r['revenue']),                           'revenue',   true],
        ['Commission earned',      static fn(array $r): string => inr((float) $r['commission']),                        'commission', true],
        ['Average booking value',  static fn(array $r): string => inr((float) $r['avg']),                               'avg',       true],
        ['Customers · repeat',     static fn(array $r): string => (int) $r['customers'] . ' · ' . (int) $r['repeaters'] . ' (' . $r['repeatPct'] . '%)', 'repeaters', true],
    ]; ?>
<div class="panel">
  <h2 style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">⚖️ Side by side
    <a class="btn ghost sm" style="margin-left:auto" href="<?= $base ?>/admin/agent-ranking.php?<?= Security::e(http_build_query(array_filter(['range' => $preset, 'from' => $customFrom, 'to' => $customTo, 'by' => $metric]))) ?>">✕ Close comparison</a></h2>
  <div class="tbl-scroll"><table class="no-card cmp-tbl">
    <thead><tr><th></th><?php foreach ($cmp as $c): ?><th><?= Security::e((string) ($c['full_name'] ?: $c['username'])) ?><div class="muted mono" style="font-size:11px;font-weight:400"><?= Security::e(AgentWallet::agentCodeLabel((int) $c['id'])) ?></div></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($cmpLines as [$lbl, $fmt, $key, $highWins]):
        $vals = array_map(static fn(array $r): float => (float) $r[$key], $cmp);
        $best = $highWins ? max($vals) : min($vals);
        $tie  = count(array_keys($vals, $best, true)) === count($vals); ?>
      <tr><td class="muted"><?= Security::e($lbl) ?></td>
        <?php foreach ($cmp as $i => $c): ?><td<?= !$tie && $vals[$i] === $best ? ' class="cmp-best"' : '' ?>><?= Security::e($fmt($c)) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($cmpTrend['labels'] !== []): ?>
  <div style="height:260px;margin-top:14px"><canvas id="cmpChart" aria-label="Confirmed bookings per day, agents compared"></canvas></div>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
  <script>
  (function ready() {
    if (typeof Chart === 'undefined') { return setTimeout(ready, 60); }
    var D = <?= json_encode($cmpTrend, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    var dark = document.documentElement.getAttribute('data-theme') === 'dark', C = ['#2F6FD0', '#F08423', '#1FA35B'];
    Chart.defaults.color = dark ? '#c7d2e5' : '#4a5568'; Chart.defaults.borderColor = dark ? '#22314A' : '#E4E9F1';
    new Chart(document.getElementById('cmpChart'), {
      type: 'line',
      data: { labels: D.labels, datasets: D.series.map(function (s, i) { return { label: s.name, data: s.data, borderColor: C[i], backgroundColor: C[i], tension: 0.25, borderWidth: 2, pointRadius: D.labels.length <= 31 ? 3 : 0 }; }) },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
        scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } }, y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
  })();
  </script>
  <?php endif; ?>
</div>
<?php endif; ?>
<style>
.cmp-tbl td,.cmp-tbl th{padding:10px 12px;text-align:right;white-space:nowrap}.cmp-tbl td:first-child,.cmp-tbl th:first-child{text-align:left}
.cmp-tbl td{font-weight:700;font-variant-numeric:tabular-nums}.cmp-tbl td.cmp-best{color:#0a6b3b;background:rgba(31,163,91,.10)}
.cmp-tbl td.cmp-best::after{content:" ★";color:#d4af37}
:root[data-theme="dark"] .cmp-tbl td.cmp-best{color:#5FD39A}
.cmp-pick{width:20px;height:20px;accent-color:#2F6FD0}
.cmp-bar{position:sticky;bottom:calc(var(--mnav-h,0px) + 10px);z-index:5;display:none;gap:10px;align-items:center;margin-top:12px;padding:10px 14px;border-radius:14px;background:var(--card);border:1px solid var(--line);box-shadow:0 4px 0 rgba(16,38,77,.1),0 14px 30px rgba(16,38,77,.2)}
.cmp-bar.on{display:flex}
</style>

<form method="get" id="cmpForm" class="panel">
  <input type="hidden" name="range" value="<?= Security::e($preset) ?>"><input type="hidden" name="by" value="<?= Security::e($metric) ?>">
  <?php if ($preset === 'custom'): ?><input type="hidden" name="from" value="<?= Security::e($customFrom) ?>"><input type="hidden" name="to" value="<?= Security::e($customTo) ?>"><?php endif; ?>
  <h2>📊 Leaderboard — <?= Security::e($metrics[$metric]) ?> <span class="muted" style="font-size:12.5px;font-weight:400">· tick 2 or 3 agents to compare them side by side</span></h2>
  <div class="tbl-scroll"><table>
    <thead><tr>
      <th style="width:34px"><span class="sr-only">Compare</span>⚖️</th><th style="width:56px">#</th><th>Agent</th><th>Code</th><th>Tickets</th><th>Pax</th><th>Revenue</th>
      <th>Avg ticket</th><th>Cancel %</th><th>Repeat customers</th><th>Commission</th><th style="width:16%">Share of revenue</th>
    </tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="12" class="muted" style="padding:22px;text-align:center">
        No agents yet. Create one under Staff &amp; Agents.
      </td></tr>
    <?php elseif (!$hasAny): ?>
      <tr><td colspan="12" class="muted" style="padding:22px;text-align:center">
        No sales in this window — try a wider range.
      </td></tr>
    <?php else:
      $medals = ['🥇', '🥈', '🥉'];
      foreach ($rows as $i => $r):
        $share = $totalRevenue > 0 ? (int) round($r['revenue'] * 100 / $totalRevenue) : 0;
    ?>
      <tr>
        <td data-label="Compare"><input type="checkbox" class="cmp-pick" name="cmp[]" value="<?= (int) $r['id'] ?>"<?= in_array((int) $r['id'], $cmpIds, true) ? ' checked' : '' ?> aria-label="Compare <?= Security::e((string) ($r['full_name'] ?: $r['username'])) ?>"></td>
        <td style="font-size:17px"><?= $medals[$i] ?? '<span class="muted" style="font-size:14px">' . ($i + 1) . '</span>' ?></td>
        <td><strong><?= Security::e((string) ($r['full_name'] ?: $r['username'])) ?></strong>
          <div class="muted mono" style="font-size:11px"><?= Security::e((string) $r['username']) ?><?= (int) $r['is_active'] === 1 ? '' : ' · inactive' ?></div></td>
        <td class="mono"><?= Security::e(AgentWallet::agentCodeLabel((int) $r['id'])) ?></td>
        <td><?= (int) $r['confirmed'] ?>
          <?php if ((int) $r['cancelled'] > 0): ?>
            <div class="muted" style="font-size:11px"><?= (int) $r['cancelled'] ?> cancelled</div>
          <?php endif; ?></td>
        <td><?= (int) $r['seats'] ?></td>
        <td><strong><?= Security::e(inr((float) $r['revenue'])) ?></strong></td>
        <td class="muted"><?= Security::e(inr((float) $r['avg'])) ?></td>
        <td style="font-weight:700;color:<?= $r['cancelPct'] > 20 ? '#DC3B3B' : ($r['cancelPct'] > 0 ? '#B26A00' : 'inherit') ?>"><?= (int) $r['tickets'] > 0 ? $r['cancelPct'] . '%' : '—' ?></td>
        <td><?= $r['customers'] > 0 ? (int) $r['repeaters'] . ' <span class="muted">of ' . (int) $r['customers'] . ' · ' . $r['repeatPct'] . '%</span>' : '—' ?></td>
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
  </table></div>
  <div class="cmp-bar" id="cmpBar"><b id="cmpN">0 selected</b><button class="btn" type="submit" id="cmpGo" disabled>⚖️ Compare</button>
    <span class="muted" style="font-size:12.5px">two or three agents</span></div>
</form>
<script>
(function () {
  var picks = document.querySelectorAll('.cmp-pick'), bar = document.getElementById('cmpBar'), go = document.getElementById('cmpGo'), n = document.getElementById('cmpN');
  function sync(e) {
    var on = document.querySelectorAll('.cmp-pick:checked').length;
    if (on > 3 && e && e.target) { e.target.checked = false; on = 3; }
    bar.classList.toggle('on', on > 0); go.disabled = on < 2; n.textContent = on + ' selected';
  }
  picks.forEach(function (p) { p.addEventListener('change', sync); });
  sync();
})();
</script>

<p class="muted" style="font-size:12.5px">
  Revenue and ticket counts are confirmed sales attributed through
  <span class="mono">bookings.sold_by_admin_id</span>; commission is read from the wallet ledger, so it is the
  amount actually owed rather than a percentage recomputed at page load. Sales made before an agent's account
  existed are unattributed and appear under no one.
</p>
<?php
admin_footer();
