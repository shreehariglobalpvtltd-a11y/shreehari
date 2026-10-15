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
    "SELECT a.id, a.username, a.full_name, a.is_active, a.created_at,
            COALESCE(COUNT(b.id), 0) AS tickets,
            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmed,
            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled
       FROM admins a
       LEFT JOIN bookings b
              ON b.sold_by_admin_id = a.id
             AND b.created_at >= :from AND b.created_at < :to
      WHERE a.role = 'agent'
      GROUP BY a.id, a.username, a.full_name, a.is_active, a.created_at",
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

/* 15 Oct 2026 (owner, Agent Management): the ticket cash each agent holds
   RIGHT NOW (the cash account's balance, whatever the window) and the day of
   their last sale ever — the two facts the Cash held column and the Low flag
   read. Two grouped reads, nothing written. */
$cashBy = [];
try {
    foreach (Database::fetchAll(
        "SELECT agent_admin_id, COALESCE(SUM(amount), 0) AS bal FROM agent_ledger WHERE account = 'cash' GROUP BY agent_admin_id"
    ) as $c) {
        $cashBy[(int) $c['agent_admin_id']] = round((float) $c['bal'], 2);
    }
} catch (Throwable $e) {
    $cashBy = [];
}
/* 15 Oct 2026 (Smart AI upgrade, performance rings): each agent's commission
   wallet balance RIGHT NOW (all-time sum of the commission account). Read only. */
$walletBy = [];
try {
    foreach (Database::fetchAll(
        "SELECT agent_admin_id, COALESCE(SUM(amount), 0) AS bal FROM agent_ledger WHERE account = 'commission' GROUP BY agent_admin_id"
    ) as $w) {
        $walletBy[(int) $w['agent_admin_id']] = round((float) $w['bal'], 2);
    }
} catch (Throwable $e) {
    $walletBy = [];
}
$lastSaleBy = [];
foreach (Database::fetchAll(
    'SELECT sold_by_admin_id AS id, MAX(created_at) AS last_sale FROM bookings WHERE sold_by_admin_id IS NOT NULL GROUP BY sold_by_admin_id'
) as $ls) {
    $lastSaleBy[(int) $ls['id']] = (string) $ls['last_sale'];
}
require_once INCLUDE_PATH . '/adminalerts.php';
$idleDays   = max(1, (int) (AdminAlerts::prefs()['agent_inactive']['days'] ?? 7));
$idleCut    = addDaysISO($today, -$idleDays) . ' 00:00:00';
$target     = Settings::getFloat('agent_target_monthly', 0.0);
$lowPct     = max(0, min(100, Settings::getInt('agent_low_perf_pct', 30)));
// The share of the monthly target this window should have reached: the
// window's days / 30 (the open month counts only the days gone). "All time"
// has no month to scale to, so only inactivity marks an agent Low there.
$windowDays = (int) max(1, round(((int) strtotime($to) - (int) strtotime($from)) / 86400));
$targetBar  = ($target > 0 && $preset !== 'all') ? $target * $windowDays / 30 * $lowPct / 100 : 0.0;
$limBy      = AgentWallet::cashLimitMap(array_map(static fn(array $r): int => (int) $r['id'], $rows));

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
    // Cash held now, red above the agent's limit (own, else default, else the cash alert's amount).
    $r['cash']       = max(0.0, $cashBy[(int) $r['id']] ?? 0.0);
    $r['wallet']     = $walletBy[(int) $r['id']] ?? 0.0;
    $r['cash_lim']   = (float) ($limBy[(int) $r['id']] ?? 0);
    $r['over_cash']  = $r['cash_lim'] > 0.009 && $r['cash'] > $r['cash_lim'] + 0.009;
    /* Low performer (display only): an ACTIVE agent below the target share
       for this window (agent_target_monthly x days/30 x agent_low_perf_pct %,
       when a target is set), or with no sale for the inactivity days of the
       alert — counting one who never sold once they are older than those days
       (the same grace the bell gives a new agent). */
    $last            = $lastSaleBy[(int) $r['id']] ?? '';
    $r['last_sale']  = $last;
    $r['low_why']    = '';
    if ((int) $r['is_active'] === 1) {
        if ($targetBar > 0 && $r['revenue'] < $targetBar) {
            $r['low_why'] = 'below ' . $lowPct . '% of the target for this window (' . inr($targetBar) . ')';
        } elseif ($last !== '' && $last < $idleCut) {
            $r['low_why'] = 'no sale since ' . formatDate(substr($last, 0, 10));
        } elseif ($last === '' && (string) ($r['created_at'] ?? '') !== '' && (string) $r['created_at'] < $idleCut) {
            $r['low_why'] = 'has never sold';
        }
    }
    $r['low'] = $r['low_why'] !== '';
}
unset($r);

// "Low performers only" (15 Oct 2026) — hides the other rows of the board
// (each keeps its real rank); totals, the leader and the comparison are unchanged.
$lowOnly = ($_GET['low'] ?? '') === '1';
$lowN    = count(array_filter($rows, static fn(array $r): bool => $r['low']));

$metric  = (string) ($_GET['by'] ?? 'revenue');
$metrics = ['revenue' => 'Top revenue', 'confirmed' => 'Top sales (tickets)', 'commission' => 'Top commission', 'seats' => 'Most passengers', 'lowcancel' => 'Lowest cancellation', 'repeaters' => 'Most repeat customers', 'cash' => 'Most cash held'];
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

/* Achievement score (display only): revenue against this window's share of
   agent_target_monthly when a target is set, else against the window's best
   seller. Colour bands: <40 red, <75 amber, <100 green, 100+ gold. */
$scoreGoal = ($target > 0 && $preset !== 'all') ? $target * $windowDays / 30 : 0.0;
$topRev    = $rows === [] ? 0.0 : max(array_map(static fn(array $r): float => (float) $r['revenue'], $rows));
foreach ($rows as &$r) {
    $den        = $scoreGoal > 0 ? $scoreGoal : $topRev;
    $r['score'] = $den > 0 ? (int) round($r['revenue'] * 100 / $den) : 0;
    $r['band']  = $r['score'] >= 100 ? 'gold' : ($r['score'] >= 75 ? 'good' : ($r['score'] >= 40 ? 'mid' : 'low'));
}
unset($r);

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
  <label style="display:inline-flex;flex-direction:row;gap:8px;align-items:center;font-size:13px;min-height:44px" title="<?= Security::e($targetBar > 0 ? 'Below ' . $lowPct . '% of the target for this window, or no sale for ' . $idleDays . '+ days' : 'No sale for ' . $idleDays . '+ days (set agent_target_monthly to also flag sellers below target)') ?>">
    <input type="checkbox" name="low" value="1" style="width:20px;height:20px;min-height:0;flex:none"<?= $lowOnly ? ' checked' : '' ?>> Low performers only<?= $lowN > 0 ? ' (' . $lowN . ')' : '' ?></label>
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

<?php if ($rows !== [] && $hasAny):
    $ringC  = 2 * M_PI * 42;
    $ringBy = ['gold' => '#C9971C', 'good' => '#1FA35B', 'mid' => '#E08A00', 'low' => '#DC3B3B'];
    $shown  = 0; ?>
<div class="panel ar-panel">
  <h2>🎯 Performance — <?= Security::e($presets[$preset]) ?>
    <span class="muted" style="font-size:12.5px;font-weight:400">· score = revenue vs <?= $scoreGoal > 0 ? 'target ' . Security::e(inr($scoreGoal)) : 'the top seller' ?></span></h2>
  <div class="ar-grid">
  <?php foreach ($rows as $i => $r):
      if ($lowOnly && !$r['low']) { continue; }
      if (++$shown > 24) { break; }
      $pct  = min(100, max(0, (int) $r['score']));
      $col  = $ringBy[$r['band']];
      $name = (string) ($r['full_name'] ?: $r['username']); ?>
    <div class="ar-card ar-<?= $r['band'] ?>">
      <div class="ar-top">
        <svg class="ar-ring" viewBox="0 0 100 100" aria-hidden="true">
          <circle cx="50" cy="50" r="42" class="ar-bg"/>
          <circle cx="50" cy="50" r="42" class="ar-fg" style="stroke:<?= $col ?>" stroke-dasharray="<?= round($ringC, 2) ?>" stroke-dashoffset="<?= round($ringC, 2) ?>" data-off="<?= round($ringC * (1 - $pct / 100), 2) ?>"/>
        </svg>
        <div class="ar-mid"><b style="color:<?= $col ?>" data-n="<?= (int) $r['score'] ?>">0</b><small>%</small></div>
        <span class="ar-rank"><?= ['🥇', '🥈', '🥉'][$i] ?? '#' . ($i + 1) ?></span>
      </div>
      <a class="ar-name" href="<?= $base ?>/admin/agent-360.php?agent=<?= (int) $r['id'] ?>"><?= Security::e($name) ?></a>
      <div class="muted mono ar-code"><?= Security::e(AgentWallet::agentCodeLabel((int) $r['id'])) ?><?= $r['low'] ? ' · <span class="pill st-bad" style="font-size:10px">Low</span>' : '' ?></div>
      <dl class="ar-kv">
        <div><dt>Passengers</dt><dd><?= (int) $r['seats'] ?></dd></div>
        <div><dt>Bookings</dt><dd><?= (int) $r['confirmed'] ?></dd></div>
        <div><dt>Revenue</dt><dd><?= Security::e(inr((float) $r['revenue'])) ?></dd></div>
        <div><dt>Commission</dt><dd style="color:#0a6b3b"><?= Security::e(inr((float) $r['commission'])) ?></dd></div>
        <div><dt>Wallet</dt><dd><?= Security::e(inr((float) $r['wallet'])) ?></dd></div>
        <div><dt>Cash held</dt><dd<?= $r['over_cash'] ? ' style="color:#DC3B3B"' : '' ?>><?= Security::e(inr((float) $r['cash'])) ?></dd></div>
      </dl>
      <div class="ar-act">
        <a class="btn ghost sm" href="<?= $base ?>/admin/agent-360.php?agent=<?= (int) $r['id'] ?>&amp;tab=profile" title="Edit agent">✏️ Edit</a>
        <a class="btn ghost sm" href="<?= $base ?>/admin/settlements.php?new=<?= (int) $r['id'] ?>" title="Start a settlement">💸 Settle</a>
        <a class="btn ghost sm" href="<?= $base ?>/admin/agent-360.php?agent=<?= (int) $r['id'] ?>" title="Wallet, sales and history">📜 History</a>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if ($shown > 24): ?><p class="muted" style="font-size:12px;margin:8px 0 0">Top 24 shown — every agent is in the leaderboard below.</p><?php endif; ?>
</div>
<style>
.ar-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,210px),1fr));gap:12px}
.ar-card{border:1px solid var(--line);border-radius:16px;padding:12px;background:var(--card);display:flex;flex-direction:column;gap:4px;min-width:0}
.ar-card.ar-gold{border-color:#d4af37;box-shadow:0 0 0 1px rgba(212,175,55,.35)}
.ar-top{position:relative;width:92px;height:92px;margin:0 auto 4px}
.ar-ring{width:100%;height:100%;transform:rotate(-90deg)}
.ar-ring circle{fill:none;stroke-width:8}.ar-ring .ar-bg{stroke:var(--line)}
.ar-ring .ar-fg{stroke-linecap:round;transition:stroke-dashoffset 1.1s cubic-bezier(.2,.8,.2,1)}
.ar-mid{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:1px}
.ar-mid b{font-size:22px;font-weight:800;font-variant-numeric:tabular-nums}.ar-mid small{font-size:11px;opacity:.7}
.ar-rank{position:absolute;top:-4px;right:-18px;font-size:15px;font-weight:800}
.ar-name{font-weight:800;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:inherit;text-decoration:none}
.ar-code{text-align:center;font-size:11px}
.ar-kv{display:grid;grid-template-columns:1fr 1fr;gap:4px 10px;margin:6px 0 0}
.ar-kv div{min-width:0}.ar-kv dt{font-size:10.5px;color:var(--muted,#6b7a90)}.ar-kv dd{margin:0;font-weight:700;font-size:13px;font-variant-numeric:tabular-nums;overflow:hidden;text-overflow:ellipsis}
.ar-act{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.ar-act .btn{flex:1;justify-content:center;padding:6px 4px;font-size:12px}
@media (prefers-reduced-motion:reduce){.ar-ring .ar-fg{transition:none}}
</style>
<script>
(function () {
  var rm = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  requestAnimationFrame(function () { setTimeout(function () {
    document.querySelectorAll('.ar-fg').forEach(function (c) { c.setAttribute('stroke-dashoffset', c.getAttribute('data-off')); });
    document.querySelectorAll('.ar-mid b[data-n]').forEach(function (b) {
      var n = +b.getAttribute('data-n') || 0, t0 = null;
      if (rm || !n) { b.textContent = n; return; }
      (function step(t) { t0 = t0 || t; var k = Math.min(1, (t - t0) / 1100); b.textContent = Math.round(n * (1 - Math.pow(1 - k, 3))); if (k < 1) requestAnimationFrame(step); })(performance.now());
    });
  }, 60); });
})();
</script>
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
  <?php if ($lowOnly): ?><input type="hidden" name="low" value="1"><?php endif; ?>
  <?php if ($preset === 'custom'): ?><input type="hidden" name="from" value="<?= Security::e($customFrom) ?>"><input type="hidden" name="to" value="<?= Security::e($customTo) ?>"><?php endif; ?>
  <h2>📊 Leaderboard — <?= Security::e($metrics[$metric]) ?> <span class="muted" style="font-size:12.5px;font-weight:400">· tick 2 or 3 agents to compare them side by side</span></h2>
  <div class="tbl-scroll"><table>
    <thead><tr>
      <th style="width:34px"><span class="sr-only">Compare</span>⚖️</th><th style="width:56px">#</th><th>Agent</th><th>Code</th><th>Tickets</th><th>Pax</th><th>Revenue</th>
      <th>Avg ticket</th><th>Cancel %</th><th>Repeat customers</th><th>Commission</th><th>Cash held</th><th style="width:16%">Share of revenue</th>
    </tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="13" class="muted" style="padding:22px;text-align:center">
        No agents yet. Create one with Quick add on the Agents register.
      </td></tr>
    <?php elseif (!$hasAny && !$lowOnly): ?>
      <tr><td colspan="13" class="muted" style="padding:22px;text-align:center">
        No sales in this window — try a wider range.
      </td></tr>
    <?php elseif ($lowOnly && $lowN === 0): ?>
      <tr><td colspan="13" class="muted" style="padding:22px;text-align:center">
        No low performer in this window. 🎉
      </td></tr>
    <?php else:
      $medals = ['🥇', '🥈', '🥉'];
      foreach ($rows as $i => $r):
        if ($lowOnly && !$r['low']) { continue; }
        $share = $totalRevenue > 0 ? (int) round($r['revenue'] * 100 / $totalRevenue) : 0;
    ?>
      <tr>
        <td data-label="Compare"><input type="checkbox" class="cmp-pick" name="cmp[]" value="<?= (int) $r['id'] ?>"<?= in_array((int) $r['id'], $cmpIds, true) ? ' checked' : '' ?> aria-label="Compare <?= Security::e((string) ($r['full_name'] ?: $r['username'])) ?>"></td>
        <td style="font-size:17px"><?= $medals[$i] ?? '<span class="muted" style="font-size:14px">' . ($i + 1) . '</span>' ?></td>
        <td><strong><?= Security::e((string) ($r['full_name'] ?: $r['username'])) ?></strong><?php if ($r['low']): ?> <span class="pill st-bad" style="font-size:10.5px" title="<?= Security::e('Low: ' . $r['low_why']) ?>">Low</span><?php endif; ?>
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
        <td<?= $r['over_cash'] ? ' style="color:#DC3B3B;font-weight:800" title="' . Security::e('Over the ' . inr($r['cash_lim']) . ' cash limit') . '"' : '' ?>><?= $r['cash'] > 0.009 ? Security::e(inr($r['cash'])) : '<span class="muted">—</span>' ?><?= $r['over_cash'] ? '<div style="font-size:11px">over ' . Security::e(inr($r['cash_lim'])) . '</div>' : '' ?></td>
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
