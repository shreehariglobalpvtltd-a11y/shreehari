<?php
declare(strict_types=1);

/**
 * Agent statement — on screen and as branded PNG pages (Oct 2026, owner brief §5–§7).
 *
 *   /admin/agent-statement.php?agent=ID&from=Y-m-d&to=Y-m-d          the statement page
 *   …&page=N                                                          PNG page N (inline)
 *   …&page=N&dl=1                                                     PNG page N (download)
 *
 * One dataset (AgentStatement::data, from the agent ledger) feeds the screen
 * and the images, so their amounts are always the same. An agent sees and
 * exports only their own statement; office roles need commissions.view.
 * Read-only: nothing on this page writes to the ledger.
 */

require_once __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentreport.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentstatement.php';

admin_boot('bookings.view');
if (!Auth::isCounterAgent()) { Auth::requireAdmin('commissions.view'); }

$e = static fn($v): string => Security::e((string) $v);

/* ---- whose statement --------------------------------------------- */
$isAgent = Auth::isCounterAgent();
$agentId = $isAgent ? (int) Auth::bookingScopeAdminId() : (int) ($_GET['agent'] ?? 0);
$agents  = [];
if (!$isAgent) {
    $agents = Database::fetchAll("SELECT id, full_name, username, is_active FROM admins WHERE role = 'agent' ORDER BY is_active DESC, full_name, username");
    $agentIds = array_map(static fn(array $a): int => (int) $a['id'], $agents);
    if (!in_array($agentId, $agentIds, true)) { $agentId = $agentIds[0] ?? 0; }   // only real agent accounts
}
if ($agentId > 0 && !AgentStatement::mayView($agentId)) {
    http_response_code(403);
    admin_header('Agent statement', 'agent-statement');
    echo '<div class="flash err">You can only open your own statement.</div>';
    admin_footer();
    exit;
}

/* ---- period ------------------------------------------------------- */
$preset = (string) ($_GET['p'] ?? '');
$from = (string) ($_GET['from'] ?? '');
$to   = (string) ($_GET['to'] ?? '');
$today = todayISO();
if ($preset === 'last_month') {
    $from = date('Y-m-01', strtotime('first day of last month'));
    $to   = date('Y-m-t', strtotime('last day of last month'));
} elseif ($preset === '7d') {
    $from = addDaysISO($today, -6);
    $to   = $today;
} elseif (!Security::isValidDate($from) || !Security::isValidDate($to)) {
    $from = date('Y-m-01');
    $to   = $today;
}
if ($from > $to) { [$from, $to] = [$to, $from]; }
if ((strtotime($to) - strtotime($from)) > 400 * 86400) { $from = addDaysISO($to, -400); }   // one statement covers at most ~13 months

$d = $agentId > 0 ? AgentStatement::data($agentId, $from, $to) : null;

/* ---- PNG page ------------------------------------------------------ */
if ($d !== null && isset($_GET['page'])) {
    try {
        $png = AgentStatementPng::render($d, (int) $_GET['page']);
    } catch (Throwable $ex) {
        http_response_code(409);
        header('Content-Type: text/plain; charset=utf-8');
        echo $ex->getMessage();
        exit;
    }
    $name = 'SHG-statement-' . preg_replace('/[^A-Za-z0-9-]/', '', $d['agent']['code']) . '-' . $from . '-to-' . $to . '-p' . $png['page'] . 'of' . $png['pages'] . '.png';
    if (($_GET['dl'] ?? '') === '1') {
        Response::download($png['path'], $name, 'image/png');
    }
    Response::inline($png['path'], 'image/png');
}

/* ---- commission change history (office view only) ------------------ */
$history = [];
if (!$isAgent && $agentId > 0) {
    try {
        $history = Database::fetchAll(
            "SELECT created_at, actor_name, actor_role, action, old_value, new_value, detail, reason
               FROM audit_logs
              WHERE (entity_type = 'admin' AND entity_id = :id
                     AND action IN ('agent.commission_override', 'agent.commission_rate', 'agent.rate', 'agent.adjustment', 'agent.commission_adjust', 'agent.kind_changed', 'agent.type_changed'))
                 OR action = 'agent.tier_rates'
              ORDER BY id DESC LIMIT 40",
            ['id' => (string) $agentId]
        );
    } catch (Throwable $ex) { $history = []; }
}

$qs = static fn(array $extra = []): string => '?' . http_build_query(array_merge(['agent' => $agentId, 'from' => $from, 'to' => $to], $extra));
$pages = $d ? count(AgentStatementPng::pages($d['rows'])) : 0;

admin_header('Agent statement', 'agent-statement');
?>
<div class="sg stmt">
  <p class="stmt-lede">Every entry from the agent ledger for the period, with who owes whom. The images below carry exactly these numbers.</p>

  <form class="stmt-filter" method="get">
    <?php if (!$isAgent): ?>
    <label class="sg-field"><span class="sg-field__label">Agent</span>
      <select class="sg-select" name="agent" onchange="this.form.submit()">
        <?php foreach ($agents as $a): ?>
        <option value="<?= (int) $a['id'] ?>"<?= (int) $a['id'] === $agentId ? ' selected' : '' ?>><?= $e(($a['full_name'] ?: $a['username']) . ' · ' . AgentWallet::agentCodeLabel((int) $a['id']) . ((int) $a['is_active'] ? '' : ' (inactive)')) ?></option>
        <?php endforeach; ?>
      </select></label>
    <?php endif; ?>
    <div class="sg-chips stmt-presets" role="group" aria-label="Period">
      <a class="sg-chip" aria-pressed="<?= $preset === '' && $from === date('Y-m-01') ? 'true' : 'false' ?>" href="<?= $e('?' . http_build_query(['agent' => $agentId])) ?>">This month</a>
      <a class="sg-chip" aria-pressed="<?= $preset === 'last_month' ? 'true' : 'false' ?>" href="<?= $e('?' . http_build_query(['agent' => $agentId, 'p' => 'last_month'])) ?>">Last month</a>
      <a class="sg-chip" aria-pressed="<?= $preset === '7d' ? 'true' : 'false' ?>" href="<?= $e('?' . http_build_query(['agent' => $agentId, 'p' => '7d'])) ?>">Last 7 days</a>
    </div>
    <div class="stmt-range">
      <label class="sg-field"><span class="sg-field__label">From</span><input class="sg-input" type="date" name="from" value="<?= $e($from) ?>" max="<?= $e($today) ?>"></label>
      <label class="sg-field"><span class="sg-field__label">To</span><input class="sg-input" type="date" name="to" value="<?= $e($to) ?>" max="<?= $e($today) ?>"></label>
      <button class="sg-btn sg-btn--secondary" type="submit">Show</button>
    </div>
  </form>

<?php if ($d === null): ?>
  <div class="sg-card"><div class="sg-empty"><h3 class="sg-empty__title">No agent selected</h3><p class="sg-empty__text">There are no agent accounts yet.</p></div></div>
<?php else: ?>
  <?php if (!$d['balanced']): ?>
  <div class="sg-alert sg-alert--danger" role="alert"><div class="sg-alert__body"><span class="sg-alert__title">This statement does not add up</span>The entries do not reproduce the ledger balance, so no image is offered. Open Ledger मिलान to find the row, or tell the office.</div></div>
  <?php endif; ?>

  <section class="sg-card stmt-head">
    <div>
      <h2 class="sg-card__title"><?= $e($d['agent']['name']) ?></h2>
      <p class="sg-card__cap">Agent ID <b class="sg-code"><?= $e($d['agent']['code']) ?></b><?= $d['agent']['phone'] !== '' ? ' · ' . $e($d['agent']['phone']) : '' ?> · <?= $e(formatDate($from, 'j M Y')) ?> – <?= $e(formatDate($to, 'j M Y')) ?></p>
      <?php if (!empty($d['rate']['label'])): ?><p class="sg-card__cap">Current commission rate: <b><?= $e($d['rate']['label']) ?></b> (<?= $e($d['rate']['sub'] ?? '') ?>) — applies to new sales only; past entries keep the rate they were earned at.</p><?php endif; ?>
    </div>
  </section>

  <div class="sg-stats stmt-stats">
    <div class="sg-stat"><span class="sg-stat__label">Opening · सुरु बाँकी</span><span class="sg-stat__value stmt-owe"><?= $e(AgentStatement::owes($d['opening'])) ?></span><span class="sg-stat__def">Balance carried in on <?= $e(formatDate($from, 'j M')) ?></span></div>
    <div class="sg-stat"><span class="sg-stat__label">Commission earned</span><span class="sg-stat__value"><?= $e(inr(-$d['sum']['earned']['amount'])) ?></span><span class="sg-stat__def"><?= (int) $d['sum']['earned']['count'] ?> booking<?= $d['sum']['earned']['count'] === 1 ? '' : 's' ?> (Cr)</span></div>
    <div class="sg-stat"><span class="sg-stat__label">Cash received from agent</span><span class="sg-stat__value"><?= $e(inr(-$d['sum']['cash_in']['amount'])) ?></span><span class="sg-stat__def"><?= (int) $d['sum']['cash_in']['count'] ?> hand-over<?= $d['sum']['cash_in']['count'] === 1 ? '' : 's' ?> (Cr)</span></div>
    <div class="sg-stat <?= $d['closing'] > 0.5 ? 'sg-stat--attention' : '' ?>"><span class="sg-stat__label">Closing · अन्तिम बाँकी</span><span class="sg-stat__value stmt-owe"><?= $e(AgentStatement::owes($d['closing'])) ?></span><span class="sg-stat__def">On <?= $e(formatDate($to, 'j M Y')) ?></span></div>
  </div>

  <section class="sg-card stmt-png">
    <div class="sg-card__head"><div><h2 class="sg-card__title">Statement images</h2><p class="sg-card__cap"><?= (int) $pages ?> page<?= $pages === 1 ? '' : 's' ?> · readable on a phone · the last page has the summary</p></div></div>
    <?php if ($d['balanced']): ?>
    <div class="stmt-actions">
      <button type="button" class="sg-btn sg-btn--primary" id="stmtShare" data-pages="<?= (int) $pages ?>" data-base="<?= $e($qs()) ?>" data-name="<?= $e('SHG-statement-' . $d['agent']['code'] . '-' . $from . '-to-' . $to) ?>">Share images</button>
      <button type="button" class="sg-btn sg-btn--secondary" id="stmtAll" data-pages="<?= (int) $pages ?>" data-base="<?= $e($qs()) ?>">Download all (<?= (int) $pages ?>)</button>
    </div>
    <p class="stmt-status" id="stmtStatus" role="status" aria-live="polite"></p>
    <div class="stmt-pages">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
      <figure class="stmt-page">
        <a href="<?= $e($qs(['page' => $p])) ?>" target="_blank" rel="noopener"><img src="<?= $e($qs(['page' => $p])) ?>" alt="Statement page <?= $p ?> of <?= (int) $pages ?>" loading="lazy" width="540" height="860"></a>
        <figcaption>Page <?= $p ?> of <?= (int) $pages ?> · <a href="<?= $e($qs(['page' => $p, 'dl' => 1])) ?>">Download</a></figcaption>
      </figure>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </section>

  <section class="sg-card stmt-rows">
    <div class="sg-card__head"><div><h2 class="sg-card__title">Entries (<?= count($d['rows']) ?>)</h2><p class="sg-card__cap">Dr = agent owes more · Cr = company owes more</p></div></div>
    <?php if (!$d['rows']): ?>
      <div class="sg-empty"><h3 class="sg-empty__title">No entries in this period</h3><p class="sg-empty__text">Pick another period above.</p></div>
    <?php else: ?>
    <ol class="stmt-list">
      <?php foreach ($d['rows'] as $r): $dr = $r['dr'] > 0; ?>
      <li class="stmt-item">
        <div class="stmt-l1"><span class="stmt-date"><?= $e(formatDate($r['date'], 'j M')) ?> <small><?= $e($r['time']) ?></small></span><b><?= $e($r['label']) ?></b><span class="stmt-amt <?= $dr ? 'is-dr' : 'is-cr' ?>"><?= $e(inr($dr ? $r['dr'] : $r['cr'])) ?> <?= $dr ? 'Dr' : 'Cr' ?></span></div>
        <div class="stmt-l2"><?= $e($r['labelNe']) ?><?php if ($r['ref'] !== ''): ?> · <span class="sg-code"><?= $e($r['ref']) ?></span><?php endif; ?><?php if ($r['travel'] !== ''): ?> · Travel <?= $e(formatDate($r['travel'], 'j M')) ?><?php endif; ?><?php if ($r['pax'] !== null): ?> · <?= (int) $r['pax'] ?> जना<?php endif; ?></div>
        <div class="stmt-l3"><span><?= $e($r['note']) ?></span><span class="stmt-run"><?= $e(AgentStatement::owes($r['running'])) ?></span></div>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </section>

  <?php if (!$isAgent): ?>
  <section class="sg-card stmt-hist">
    <div class="sg-card__head"><div><h2 class="sg-card__title">Commission changes</h2><p class="sg-card__cap">Who changed this agent's rate or posted an adjustment, when, from what to what, and why. Changes apply from then on — earlier entries are never recalculated.</p></div>
      <a class="sg-btn sg-btn--secondary sg-btn--sm" href="/admin/agent-360.php?agent=<?= (int) $agentId ?>&amp;tab=report">Edit rate / adjust</a></div>
    <?php if (!$history): ?>
      <p class="sg-muted">No changes recorded.</p>
    <?php else: ?>
    <ol class="stmt-list">
      <?php foreach ($history as $h):
        $old = json_decode((string) $h['old_value'], true); $new = json_decode((string) $h['new_value'], true);
        $fmt = static function ($v): string { if (!is_array($v)) { return '—'; } $o = []; foreach ($v as $k => $x) { $o[] = $k . ': ' . (is_scalar($x) || $x === null ? (string) ($x ?? '—') : json_encode($x)); } return implode(', ', $o); }; ?>
      <li class="stmt-item">
        <div class="stmt-l1"><span class="stmt-date"><?= $e(formatDate(substr((string) $h['created_at'], 0, 10), 'j M Y')) ?> <small><?= $e(substr((string) $h['created_at'], 11, 5)) ?></small></span><b><?= $e(str_replace(['agent.', '_'], ['', ' '], (string) $h['action'])) ?><?= $h['action'] === 'agent.tier_rates' ? ' (company-wide)' : '' ?></b><span class="sg-muted"><?= $e(($h['actor_name'] ?: 'system') . ($h['actor_role'] ? ' · ' . $h['actor_role'] : '')) ?></span></div>
        <div class="stmt-l2">Before: <?= $e($fmt($old)) ?> → After: <?= $e($fmt($new)) ?></div>
        <div class="stmt-l3"><span>Reason: <?= $e(trim((string) ($h['reason'] ?: $h['detail'])) ?: 'not given') ?></span></div>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </section>
  <?php endif; ?>
<?php endif; ?>
</div>

<style>
.stmt { display: grid; gap: 14px; max-width: 980px; }
.stmt-lede { margin: -8px 0 0; color: var(--muted); }
.stmt-filter { display: grid; gap: 10px; }
.stmt-range { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: end; }
.stmt-presets .sg-chip { text-decoration: none; }
.stmt-stats .stmt-owe { font-size: 18px; line-height: 24px; }
.stmt-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.stmt-status { margin: 6px 0 0; color: var(--muted); font-size: 13px; min-height: 18px; }
.stmt-pages { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; margin-top: 10px; }
.stmt-page { margin: 0; }
.stmt-page img { width: 100%; height: auto; border: 1px solid var(--line); border-radius: 10px; background: var(--surface-sunken); display: block; }
.stmt-page figcaption { font-size: 12.5px; color: var(--muted); margin-top: 4px; }
.stmt-list { list-style: none; margin: 0; padding: 0; }
.stmt-item { padding: 10px 0; border-top: 1px solid var(--line); display: grid; gap: 3px; }
.stmt-item:first-child { border-top: 0; }
.stmt-l1 { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 10px; align-items: baseline; }
.stmt-date { color: var(--muted); font-size: 13px; white-space: nowrap; }
.stmt-amt { font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }
.stmt-amt.is-dr { color: var(--danger); }
.stmt-amt.is-cr { color: var(--success); }
.stmt-l2, .stmt-l3 { color: var(--muted); font-size: 13px; overflow-wrap: anywhere; }
.stmt-l3 { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.stmt-run { color: var(--ink); font-weight: 600; }
@media (max-width: 560px) { .stmt-range { grid-template-columns: 1fr 1fr; } .stmt-range .sg-btn { grid-column: 1 / -1; } }
</style>
<script>
(function () {
  var share = document.getElementById('stmtShare'), all = document.getElementById('stmtAll'), st = document.getElementById('stmtStatus');
  function urls(btn) { var n = +btn.getAttribute('data-pages'), b = btn.getAttribute('data-base'), o = []; for (var i = 1; i <= n; i++) o.push(b + '&page=' + i); return o; }
  function say(t) { if (st) st.textContent = t; }
  if (all) all.addEventListener('click', function () {
    urls(all).forEach(function (u, i) { setTimeout(function () { var a = document.createElement('a'); a.href = u + '&dl=1'; a.download = ''; document.body.appendChild(a); a.click(); a.remove(); }, i * 700); });
    say('Downloading ' + urls(all).length + ' image(s)…');
  });
  if (share) share.addEventListener('click', async function () {
    share.disabled = true; say('Preparing images…');
    try {
      var name = share.getAttribute('data-name'), files = [];
      var list = urls(share);
      for (var i = 0; i < list.length; i++) {
        var r = await fetch(list[i], { credentials: 'same-origin' });
        if (!r.ok) throw new Error(await r.text() || ('HTTP ' + r.status));
        files.push(new File([await r.blob()], name + '-p' + (i + 1) + '.png', { type: 'image/png' }));
      }
      if (navigator.canShare && navigator.canShare({ files: files })) {
        await navigator.share({ files: files, title: name });
        say('Shared ' + files.length + ' image(s).');
      } else {
        say('This browser cannot share images directly — use Download all, then send them from your gallery.');
      }
    } catch (err) {
      if (err && err.name === 'AbortError') say('Share cancelled.');
      else say('Could not prepare the images: ' + (err && err.message ? err.message : 'network error') + '. Try again.');
    } finally { share.disabled = false; }
  });
})();
</script>
<?php admin_footer();
