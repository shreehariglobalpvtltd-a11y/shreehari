<?php
/**
 * admin/agent-aging.php — how long each agent has held the company's cash
 * (15 Nov 2026, P4 desk tools).
 *
 * The money: agent_ledger rows with account = 'cash' (AgentWallet). A sale
 * taken in cash writes +amount (cash_due); a deposit at the office writes
 * −amount (cash_handover); an adjustment may go either way. The balance is
 * what the agent owes the company today.
 *
 * The aging: DeskTools::agingBuckets — first in, first out. Every handover
 * pays off the agent's OLDEST cash first, and whatever is left of each sale
 * is aged by the day it was taken: 0-7, 8-15, 16-30 and 30+ days. The four
 * buckets add up to the balance. A cancelled ticket's cash stays owed (the
 * wallet never reverses cash by itself — only a handover or an adjustment
 * settles it), exactly as on the settlements page.
 *
 * Who: counter agents (role agent) by default; "office sellers too" adds the
 * owner's / counters' own sales, which also carry cash rows on live.
 *
 * Read-only. reports.view, office roles only; CSV needs reports.export.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/desktools.php';
$admin = admin_boot('reports.view');
if (Auth::isCounterAgent() || (string) ($admin['role'] ?? '') === 'counter') {
    admin_header('Agent cash aging', 'agent-aging');
    echo '<div class="flash bad">This is an office page. · यो अफिसको पेज हो।</div>';
    admin_footer();
    exit;
}

$e    = static fn($v): string => Security::e((string) $v);
$asof = Security::isValidDate((string) ($_GET['asof'] ?? '')) ? (string) $_GET['asof'] : todayISO();
if ($asof > todayISO()) { $asof = todayISO(); }
$all  = ($_GET['all'] ?? '') === '1';
$end  = addDaysISO($asof, 1);                       // rows up to the end of the as-of day
$now  = (int) strtotime($asof . ' 23:59:59');
$dueDays = Settings::getInt('agent_settlement_due_days', 0);

$ledger = [];
try {
    $ledger = Database::fetchAll(
        "SELECT l.agent_admin_id AS aid, l.amount, l.created_at
           FROM agent_ledger l
           JOIN admins a ON a.id = l.agent_admin_id
          WHERE l.account = 'cash' AND l.created_at < :e" . ($all ? '' : " AND a.role = 'agent'") . '
          ORDER BY l.agent_admin_id, l.created_at, l.id',
        ['e' => $end]
    );
} catch (Throwable $ex) {
    Logger::warning('agent-aging query failed: ' . $ex->getMessage());
}
$byAgent = [];
foreach ($ledger as $r) {
    $byAgent[(int) $r['aid']][] = $r;
}

$people = [];
if ($byAgent !== []) {
    $ids = array_keys($byAgent);
    $in  = implode(',', array_map('intval', $ids));   // integers only - built from our own keys
    foreach (Database::fetchAll("SELECT id, full_name, username, role, is_active FROM admins WHERE id IN ($in)") as $p) {
        $people[(int) $p['id']] = $p;
    }
}

$rows = [];
$tot  = array_fill_keys(DeskTools::AGING_BUCKETS, 0.0) + ['total' => 0.0, 'credit' => 0.0];
foreach ($byAgent as $aid => $list) {
    $ag = DeskTools::agingBuckets($list, $now);
    if ($ag['total'] <= 0 && $ag['credit'] <= 0) {
        continue;   // settled to the rupee
    }
    $p = $people[$aid] ?? ['full_name' => '', 'username' => '#' . $aid, 'role' => '', 'is_active' => 0];
    $rows[] = ['id' => $aid, 'name' => (string) ($p['full_name'] ?: $p['username']), 'role' => (string) $p['role'],
               'active' => (int) $p['is_active'] === 1, 'code' => AgentWallet::agentCodeLabel($aid)] + $ag;
    foreach (DeskTools::AGING_BUCKETS as $bk) { $tot[$bk] += $ag['buckets'][$bk]; }
    $tot['total']  += $ag['total'];
    $tot['credit'] += $ag['credit'];
}
usort($rows, static fn(array $x, array $y): int => [$y['total'], $y['buckets']['30+']] <=> [$x['total'], $x['buckets']['30+']]);
$holding = count(array_filter($rows, static fn(array $r): bool => $r['total'] > 0));

/* ---- CSV ------------------------------------------------------------- */
if (($_GET['export'] ?? '') === 'csv') {
    if (!Auth::can('reports.export')) {
        http_response_code(403);
        exit('Your role cannot download exports. / तपाईंको भूमिकाले export गर्न मिल्दैन।');
    }
    require __DIR__ . '/_export.php';
    Logger::audit('report.export', 'report', 'agent-aging', null, ['asof' => $asof, 'rows' => count($rows), 'all' => $all], 'Agent cash aging exported');
    $data = [];
    foreach ($rows as $r) {
        $data[] = [$r['name'], $r['code'], $r['role'],
            number_format($r['buckets']['0-7'], 2, '.', ''), number_format($r['buckets']['8-15'], 2, '.', ''),
            number_format($r['buckets']['16-30'], 2, '.', ''), number_format($r['buckets']['30+'], 2, '.', ''),
            number_format($r['total'], 2, '.', ''), $r['oldestDays'] ?? '', number_format($r['credit'], 2, '.', '')];
    }
    $data[] = ['TOTAL', '', '', number_format($tot['0-7'], 2, '.', ''), number_format($tot['8-15'], 2, '.', ''),
        number_format($tot['16-30'], 2, '.', ''), number_format($tot['30+'], 2, '.', ''), number_format($tot['total'], 2, '.', ''), '',
        number_format($tot['credit'], 2, '.', '')];
    shg_export_csv('agent-cash-aging-' . $asof . '.csv',
        ['Agent', 'Code', 'Role', '0-7 days', '8-15 days', '16-30 days', '30+ days', 'Total due', 'Oldest (days)', 'Handed in ahead'], $data);
}

$qs = static fn(array $x): string => $e(http_build_query(['asof' => $asof] + ($all ? ['all' => '1'] : []) + $x));

admin_header('Agent cash aging', 'agent-aging');
?>
<style>
.ag-k{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 16px}
.ag-k div{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px;min-width:0}
.ag-k small{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--mut)}
.ag-k b{font-size:20px;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.ag-f{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:0 0 14px}
.ag-f input[type=date]{display:block;min-height:44px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);box-sizing:border-box;max-width:100%}
.ag-f label.ck{display:inline-flex;align-items:center;gap:8px;min-height:44px;font-size:13px}
.ag-f .btn{min-height:44px}
.ag-old{color:#b02a2a;font-weight:700}
.ag-z{color:var(--mut)}
@media(max-width:760px){.ag-k{grid-template-columns:1fr 1fr}}
</style>

<form method="get" class="ag-f">
  <label class="muted" style="font-size:11px">As of · मिति<input type="date" name="asof" value="<?= $e($asof) ?>" max="<?= $e(todayISO()) ?>"></label>
  <label class="ck"><input type="checkbox" name="all" value="1"<?= $all ? ' checked' : '' ?>> Office / counter sellers too</label>
  <button class="btn" type="submit">Show</button>
  <?php if (Auth::can('reports.export')): ?><a class="btn ghost" href="?<?= $qs(['export' => 'csv']) ?>">⬇ CSV</a><?php endif; ?>
</form>

<div class="ag-k">
  <div><small>Cash owed · बाँकी नगद</small><b><?= $e(inr($tot['total'])) ?></b></div>
  <div><small>Older than 30 days</small><b class="<?= $tot['30+'] > 0 ? 'ag-old' : '' ?>"><?= $e(inr($tot['30+'])) ?></b></div>
  <div><small>16 – 30 days</small><b><?= $e(inr($tot['16-30'])) ?></b></div>
  <div><small>Holding cash</small><b><?= (int) $holding ?></b></div>
</div>

<div class="panel">
  <h2>⏳ Cash held by agents · as of <?= $e(formatDate($asof)) ?></h2>
  <?php if ($rows === []): ?>
    <p class="muted" style="padding:0 18px 16px;margin:0">No agent holds the company's cash on this date. · कसैसँग बाँकी नगद छैन।</p>
  <?php else: ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Agent</th><th class="num">0-7 days</th><th class="num">8-15 days</th><th class="num">16-30 days</th><th class="num">30+ days</th><th class="num">Total due</th><th class="num">Oldest</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $late = $dueDays > 0 && $r['oldestDays'] !== null && $r['oldestDays'] > $dueDays; ?>
      <tr>
        <td data-label="Agent"><a href="/admin/agent-360.php?agent=<?= (int) $r['id'] ?>"><b><?= $e($r['name']) ?></b></a>
          <span class="muted mono" style="font-size:11px"><?= $e(implode(' · ', array_filter([$r['code'], $r['role'] !== 'agent' ? $r['role'] : '', $r['active'] ? '' : 'inactive'], static fn($s): bool => $s !== ''))) ?></span>
          <?php if ($r['credit'] > 0): ?><div class="muted" style="font-size:11.5px">Handed in ahead: <?= $e(inr($r['credit'])) ?></div><?php endif; ?></td>
        <?php foreach (DeskTools::AGING_BUCKETS as $bk): $v = $r['buckets'][$bk]; ?>
          <td data-label="<?= $e($bk . ' days') ?>" class="num<?= $v <= 0 ? ' ag-z' : ($bk === '30+' ? ' ag-old' : '') ?>"><?= $v > 0 ? $e(inr($v)) : '—' ?></td>
        <?php endforeach; ?>
        <td data-label="Total due" class="num"><b><?= $e(inr($r['total'])) ?></b></td>
        <td data-label="Oldest" class="num<?= $late ? ' ag-old' : '' ?>"><?= $r['oldestDays'] !== null ? (int) $r['oldestDays'] . ' d' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <th>Total · जम्मा</th>
      <?php foreach (DeskTools::AGING_BUCKETS as $bk): ?><th class="num"><?= $e(inr($tot[$bk])) ?></th><?php endforeach; ?>
      <th class="num"><?= $e(inr($tot['total'])) ?></th><th></th>
    </tr></tfoot>
  </table></div>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;padding:0 16px 12px;margin:0">First in, first out: every deposit at the office clears the agent's oldest cash first; what is left of each sale is aged by the day it was taken. The buckets add up to the cash owed.<?= $dueDays > 0 ? ' Red "Oldest" = past the ' . (int) $dueDays . '-day settlement window.' : '' ?> Settle on <a href="/admin/settlements.php">Commission &amp; Wallet Settlements</a>.</p>
</div>
<?php admin_footer();
