<?php
/**
 * admin/ai-center.php — AI Control Center (13 Nov 2026, Super AI).
 *
 * One screen for the whole assistant: what it spent and how fast it was
 * (ai_usage, AiMeter), what waits for a human, which tools each role may
 * use, what the knowledge base holds, how often tools succeed, and the
 * latest AI warnings. The two controls here are the daily spending
 * ceiling and the prompt-cache switch; everything else links to the page
 * that already owns it (AI Brain, Knowledge, Activity, Verify, Ledger).
 *
 * Read-only apart from those two settings. It shows counts and outcomes,
 * never a passenger's own words. Office only (dashboard.view); saving
 * needs the Settings permission.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/aibrain.php';
require_once INCLUDE_PATH . '/aimeter.php';
require_once INCLUDE_PATH . '/aitools.php';

$admin   = admin_boot('dashboard.view');
$canEdit = Auth::canManageSettings();
$e       = static fn($v): string => Security::e((string) $v);
$flash   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again. / फेरि प्रयास गर्नुहोस्।'];
    } elseif (!$canEdit) {
        $flash = ['bad', 'Changing the AI needs the Settings permission.'];
    } else {
        $before = ['budget' => AiMeter::budget(), 'cache' => Settings::getBool('ai_claude_cache_on', true)];
        $budget = round(max(0.0, min(500.0, (float) ($_POST['ai_daily_budget_usd'] ?? 0))), 2);
        $cache  = isset($_POST['ai_claude_cache_on']);
        Settings::set('ai_daily_budget_usd', (string) $budget, 'string', 'ai');
        Settings::set('ai_claude_cache_on', $cache ? '1' : '0', 'bool', 'ai');
        Logger::audit('ai.center_saved', 'settings', 'ai', $before, ['budget' => $budget, 'cache' => $cache], 'AI Control Center');
        AiMeter::reset();
        $flash = ['ok', 'Saved. Daily ceiling: ' . ($budget > 0 ? '$' . number_format($budget, 2) : 'none')
            . ' · prompt cache ' . ($cache ? 'on' : 'off') . '.'];
    }
}

/* ---------------------------------------------------------------- data */
$q = static function (callable $fn, mixed $default) {
    try { return $fn(); } catch (Throwable $ex) { return $default; }
};
$today = date('Y-m-d') . ' 00:00:00';
$week  = date('Y-m-d', strtotime('-6 days')) . ' 00:00:00';

$haveUsage = $q(static fn() => Database::scalar('SELECT 1 FROM ai_usage LIMIT 1', [], 0) !== null || true, false);

$t = $haveUsage ? ($q(static fn() => Database::fetch(
    'SELECT COUNT(*) AS n, COALESCE(SUM(ok), 0) AS oks, COALESCE(SUM(cost_usd), 0) AS cost,
            COALESCE(SUM(in_tokens), 0) AS tin, COALESCE(SUM(out_tokens), 0) AS tout,
            COALESCE(SUM(cache_read), 0) AS cr, COALESCE(SUM(cache_write), 0) AS cw,
            COALESCE(AVG(CASE WHEN ok = 1 THEN ms END), 0) AS ms
       FROM ai_usage WHERE created_at >= :d', ['d' => $today]), null) ?? []) : [];
$calls   = (int) ($t['n'] ?? 0);
$fails   = $calls - (int) ($t['oks'] ?? 0);
$spent   = (float) ($t['cost'] ?? 0);
$promptT = (int) ($t['tin'] ?? 0) + (int) ($t['cr'] ?? 0) + (int) ($t['cw'] ?? 0);
$cacheP  = $promptT > 0 ? (int) round((int) ($t['cr'] ?? 0) * 100 / $promptT) : null;
$avgMs   = (int) round((float) ($t['ms'] ?? 0));
$budget  = AiMeter::budget();
$cacheOn = Settings::getBool('ai_claude_cache_on', true);

$byModel = $haveUsage ? $q(static fn() => Database::fetchAll(
    'SELECT provider, model, COUNT(*) AS n, SUM(ok) AS oks, SUM(in_tokens) AS tin, SUM(out_tokens) AS tout,
            SUM(cache_read) AS cr, SUM(cost_usd) AS cost, AVG(CASE WHEN ok = 1 THEN ms END) AS ms
       FROM ai_usage WHERE created_at >= :d GROUP BY provider, model ORDER BY n DESC LIMIT 12', ['d' => $week]), []) : [];
$byDay = $haveUsage ? $q(static fn() => Database::fetchAll(
    'SELECT DATE(created_at) AS d, COUNT(*) AS n, SUM(ok) AS oks, SUM(cost_usd) AS cost,
            SUM(in_tokens + cache_read + cache_write) AS tin, SUM(out_tokens) AS tout
       FROM ai_usage WHERE created_at >= :d GROUP BY DATE(created_at) ORDER BY d DESC', ['d' => $week]), []) : [];
$byChannel = $haveUsage ? $q(static fn() => Database::fetchAll(
    "SELECT CONCAT(channel, IF(role <> '', CONCAT(' · ', role), '')) AS who, COUNT(*) AS n, SUM(cost_usd) AS cost
       FROM ai_usage WHERE created_at >= :d GROUP BY channel, role ORDER BY n DESC LIMIT 8", ['d' => $week]), []) : [];

/* What waits for a human */
require_once INCLUDE_PATH . '/aiwatch.php';
$proofs = $q(static fn() => AiWatch::proofsWaiting(), ['n' => 0, 'await' => 0]);
$waits = [
    ['Payments to verify', (int) ($proofs['n'] ?? 0), 'payments.php', 'a booking stays pending until the office approves it'],
    ['Refunds to settle', (int) $q(static fn() => Database::scalar("SELECT COUNT(*) FROM bookings WHERE refund_status = 'pending'", [], 0), 0), 'reconcile.php', 'settled by the owner, never by the AI'],
    ['Handed to staff', (int) $q(static fn() => Database::scalar("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')", [], 0), 0), 'support-inbox.php', 'conversations the AI passed to a person'],
    ['Knowledge drafts', (int) $q(static fn() => Database::scalar("SELECT COUNT(*) FROM ai_kb_articles WHERE publication_status <> 'published'", [], 0), 0), 'ai-knowledge.php', 'the AI answers only from published articles'],
    ['Questions it could not answer', (int) $q(static fn() => Database::scalar("SELECT COUNT(*) FROM ai_unanswered_questions WHERE status = 'open'", [], 0), 0), 'ai-knowledge.php', 'write an article and the next person gets an answer'],
];

/* Tool permissions, straight from the catalogue each role is actually handed */
$roles = [];
foreach (['customer' => 'Customer', 'staff' => 'Agent / staff', 'admin' => 'Office'] as $r => $label) {
    $roles[$label] = $q(static fn() => array_column(
        AiTools::catalogue(['role' => $r, 'channel' => 'whatsapp', 'phone' => '0000000000', 'verified' => true]), 'name'), []);
}

$kb = $q(static fn() => Database::fetchAll(
    "SELECT category, SUM(publication_status = 'published') AS live, COUNT(*) AS n, MAX(updated_at) AS upd
       FROM ai_kb_articles GROUP BY category ORDER BY n DESC LIMIT 12"), []);

$tq = $q(static fn() => Database::fetch(
    'SELECT COUNT(*) AS n, COALESCE(SUM(ok), 0) AS oks, COUNT(DISTINCT phone) AS people
       FROM ai_agent_calls WHERE created_at >= :d', ['d' => $week]), null) ?? [];
$toolBad = $q(static fn() => Database::fetchAll(
    'SELECT tool, COUNT(*) AS n, SUM(ok = 0) AS bad FROM ai_agent_calls WHERE created_at >= :d
      GROUP BY tool HAVING bad > 0 ORDER BY bad DESC LIMIT 6', ['d' => $week]), []);
$toolN  = (int) ($tq['n'] ?? 0);
$toolOk = $toolN > 0 ? (int) round((int) ($tq['oks'] ?? 0) * 100 / $toolN) : null;

/* The latest AI warnings in today's log — message only, no context payload */
$errors = [];
$logFile = LOG_PATH . '/' . date('Y-m-d') . '.log';
if (is_file($logFile) && filesize($logFile) < 20 * 1024 * 1024) {
    foreach (array_reverse(@file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
        if (preg_match('/^\[(\d{4}-\d\d-\d\d (\d\d:\d\d)):\d\d\] \w+\.(WARNING|ERROR): (AI [^{\[]{0,140})/', $line, $m)) {
            $errors[] = [$m[2], $m[3], trim($m[4])];
            if (count($errors) >= 12) { break; }
        }
    }
}

$brains = AiBrain::order();
$stats  = AiBrain::stats();
$csrf   = $e(Security::csrfToken());
$money  = static fn(float $v): string => '$' . number_format($v, $v > 0 && $v < 1 ? 4 : 2);
$num    = static fn($v): string => number_format((int) $v);

admin_header('AI Control Center', 'ai-center');
?>
<style>
  .ac{--ac-royal:#1A44C8;--ac-navy:#0A2A8F;--ac-orange:#F07800;--ac-gold:#FFB81C;max-width:1180px}
  .ac-hero{background:linear-gradient(135deg,var(--ac-navy),var(--ac-royal) 62%,#2B5BE0);color:#fff;border-radius:18px;
    padding:18px 16px 16px;position:relative;overflow:hidden;box-shadow:0 10px 28px rgba(10,42,143,.22)}
  .ac-hero::after{content:"";position:absolute;inset:auto 0 0 0;height:3px;background:linear-gradient(90deg,var(--ac-gold),var(--ac-orange))}
  .ac-hero h1{margin:0;font-size:21px;line-height:1.2;letter-spacing:-.01em;color:#fff}
  .ac-hero p{margin:6px 0 0;font-size:13.5px;line-height:1.5;color:#D3DDF2;max-width:62ch}
  .ac-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
  .ac-chip{font-size:12px;font-weight:650;padding:5px 10px;border-radius:999px;background:rgba(255,255,255,.14);color:#fff;white-space:nowrap}
  .ac-chip.gold{background:var(--ac-gold);color:#1b2436}
  .ac-chip.warn{background:var(--ac-orange);color:#fff}
  .ac-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:12px 0}
  .ac-stat{background:var(--card,#fff);border:1px solid var(--line,#e5e9f0);border-radius:14px;padding:12px;min-width:0}
  .ac-stat .k{font-size:12px;color:var(--mut,#6b7688);font-weight:600}
  .ac-stat .v{font-size:24px;font-weight:750;line-height:1.15;margin-top:4px;font-variant-numeric:tabular-nums;color:var(--ink,#1b2436)}
  .ac-stat .s{font-size:12px;color:var(--mut,#6b7688);margin-top:2px}
  .ac-stat.hot{border-color:var(--ac-orange)} .ac-stat.hot .v{color:var(--ac-orange)}
  .ac-meter{height:6px;border-radius:99px;background:var(--line,#e5e9f0);margin-top:8px;overflow:hidden}
  .ac-meter i{display:block;height:100%;border-radius:99px;background:var(--ac-royal)}
  .ac-meter i.over{background:var(--ac-orange)}
  .ac-cols{display:grid;grid-template-columns:minmax(0,1fr);gap:12px}
  .ac .panel{margin:0;min-width:0;padding-bottom:14px}
  .ac .panel > :not(h2){margin-left:14px;margin-right:14px}
  .ac .panel h2{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .ac-note{font-size:13px;color:var(--mut,#6b7688);margin:0 0 10px;line-height:1.5}
  .ac-wait{display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--line,#e5e9f0);text-decoration:none;color:inherit}
  .ac-wait:first-of-type{border-top:0}
  .ac-wait b{flex:none;min-width:38px;text-align:center;font-size:16px;font-variant-numeric:tabular-nums;padding:5px 8px;border-radius:10px;
    background:var(--royal-tint,#EEF2FF);color:var(--ac-navy)}
  .ac-wait.has b{background:var(--ac-orange);color:#fff}
  .ac-wait span{min-width:0;font-size:14px;font-weight:650}
  .ac-wait small{display:block;font-weight:400;font-size:12.5px;color:var(--mut,#6b7688)}
  .ac-tools{display:flex;flex-wrap:wrap;gap:5px;margin:6px 0 12px}
  .ac-tool{font-size:11.5px;padding:3px 8px;border-radius:999px;border:1px solid var(--line,#e5e9f0);background:var(--card,#fff);
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
  .ac-role{font-size:13px;font-weight:700;margin:10px 0 0}
  .ac-role em{font-style:normal;font-weight:500;color:var(--mut,#6b7688)}
  .ac-form{display:grid;gap:12px}
  .ac-form label{font-size:13px;font-weight:650;display:grid;gap:5px}
  .ac-form input[type=number]{font-size:16px;padding:10px 12px;border:1px solid var(--line,#e5e9f0);border-radius:10px;width:100%;max-width:220px;
    background:var(--card,#fff);color:inherit}
  .ac-form .chk{display:flex;gap:9px;align-items:flex-start;font-weight:500}
  .ac-form .chk .ac-note{font-weight:400}
  .ac-form .chk input{width:20px;height:20px;flex:none;margin-top:1px}
  .ac-links{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
  .ac-links a{font-size:13px;font-weight:650;padding:8px 12px;border-radius:10px;border:1px solid var(--line,#e5e9f0);text-decoration:none;
    color:var(--ac-navy);background:var(--card,#fff);min-height:38px;display:inline-flex;align-items:center}
  .ac-log{font-size:12.5px;line-height:1.5;padding:6px 0;border-top:1px solid var(--line,#e5e9f0);overflow-wrap:anywhere}
  .ac-log:first-of-type{border-top:0}
  .ac-log time{color:var(--mut,#6b7688);font-variant-numeric:tabular-nums;margin-right:6px}
  .ac-log .lv{font-weight:700;color:var(--ac-orange);margin-right:6px}
  .ac table.dt{min-width:0}
  .ac td.num,.ac th.num{text-align:right;font-variant-numeric:tabular-nums}
  @media (min-width:720px){
    .ac-hero{padding:24px 26px 22px}.ac-hero h1{font-size:26px}
    .ac-grid{grid-template-columns:repeat(4,minmax(0,1fr))}
    .ac-cols{grid-template-columns:repeat(2,minmax(0,1fr))}
    .ac-cols .wide{grid-column:1 / -1}
  }
  @media (prefers-color-scheme:dark){.ac-wait b{color:#fff;background:rgba(255,255,255,.12)}.ac-links a{color:inherit}}
</style>

<div class="ac">
<?php if ($flash !== null): ?><div class="flash <?= $flash[0] ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<div class="ac-hero">
  <h1>S Hari Super AI</h1>
  <p>What the assistant spent, how fast it answered, what waits for a person, and what each role is allowed to do.
     Prices, seats and money always come from the register — the AI reads them, it never decides them.</p>
  <div class="ac-chips">
    <?php if ($brains === []): ?>
      <span class="ac-chip warn">No AI key set — only the built-in replies answer</span>
    <?php else: foreach ($brains as $i => $b): ?>
      <span class="ac-chip<?= $i === 0 ? ' gold' : '' ?>"><?= $i === 0 ? 'Answers first: ' : 'Then: ' ?><?= $e(AiBrain::PROVIDERS[$b]['label'] ?? $b) ?></span>
    <?php endforeach; endif; ?>
    <span class="ac-chip">Prompt cache <?= $cacheOn ? 'on' : 'off' ?></span>
    <span class="ac-chip<?= AiMeter::overBudget() ? ' warn' : '' ?>"><?= $budget > 0
        ? (AiMeter::overBudget() ? 'Daily ceiling reached — Claude paused' : 'Daily ceiling ' . $money($budget))
        : 'No daily ceiling' ?></span>
  </div>
</div>

<?php if (!$haveUsage): ?>
  <div class="flash warn" style="margin-top:12px">The usage table <code>ai_usage</code> is not installed on this server yet
    (database/upgrade-2026-11-13-super-ai.sql). Spend and speed appear here once it is.</div>
<?php endif; ?>

<div class="ac-grid">
  <div class="ac-stat"><div class="k">Model calls today</div><div class="v"><?= $num($calls) ?></div>
    <div class="s"><?= $fails > 0 ? $num($fails) . ' failed or declined' : 'none failed' ?></div></div>
  <div class="ac-stat<?= $budget > 0 && $spent >= $budget ? ' hot' : '' ?>"><div class="k">Spent today</div><div class="v"><?= $money($spent) ?></div>
    <?php if ($budget > 0): $pct = (int) min(100, round($spent * 100 / $budget)); ?>
      <div class="ac-meter" role="img" aria-label="<?= $pct ?>% of the daily ceiling"><i class="<?= $pct >= 100 ? 'over' : '' ?>" style="width:<?= $pct ?>%"></i></div>
      <div class="s"><?= $pct ?>% of <?= $money($budget) ?></div>
    <?php else: ?><div class="s">priced models only</div><?php endif; ?></div>
  <div class="ac-stat"><div class="k">Read from cache</div><div class="v"><?= $cacheP === null ? '—' : $cacheP . '%' ?></div>
    <div class="s"><?= $cacheP === null ? 'no prompt tokens counted yet' : 'of prompt tokens, cheaper and faster' ?></div></div>
  <div class="ac-stat"><div class="k">Average answer time</div><div class="v"><?= $avgMs > 0 ? number_format($avgMs / 1000, 1) . ' s' : '—' ?></div>
    <div class="s">per model call that answered</div></div>
</div>

<div class="ac-cols">

  <div class="panel">
    <h2>Waiting for a person</h2>
    <p class="ac-note">The AI prepares; a person approves. Nothing here is settled by the assistant.</p>
    <?php foreach ($waits as [$label, $n, $href, $why]): ?>
      <a class="ac-wait<?= $n > 0 ? ' has' : '' ?>" href="/admin/<?= $e($href) ?>">
        <b><?= $num($n) ?></b><span><?= $e($label) ?><small><?= $e($why) ?></small></span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h2>Spending ceiling &amp; cache</h2>
    <form method="post" class="ac-form">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
      <label>Most to spend in one day (US dollars)
        <input type="number" name="ai_daily_budget_usd" min="0" max="500" step="0.5" inputmode="decimal"
               value="<?= $e(rtrim(rtrim(number_format($budget, 2, '.', ''), '0'), '.')) ?>" <?= $canEdit ? '' : 'disabled' ?>>
        <span class="ac-note" style="margin:0;font-weight:400">0 means no ceiling. When the day's spend reaches it, Claude pauses until
          midnight and the next brain — or the built-in replies — keeps answering, so nobody is left without a reply.</span>
      </label>
      <label class="chk"><input type="checkbox" name="ai_claude_cache_on" <?= $cacheOn ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
        <span>Prompt cache for Claude<br><span class="ac-note" style="margin:0">The briefing and tool list are re-read from cache
          instead of paid for again on every message. Leave on.</span></span></label>
      <?php if ($canEdit): ?><div><button class="btn btn-primary" type="submit">Save</button></div>
      <?php else: ?><p class="ac-note">Changing these needs the Settings permission.</p><?php endif; ?>
    </form>
    <div class="ac-links">
      <a href="/admin/ai-brain.php">Keys &amp; models</a>
      <a href="/admin/ai-knowledge.php">Knowledge</a>
      <a href="/admin/ai-activity.php">Every action</a>
      <a href="/admin/wa-inbox.php">Conversations</a>
      <a href="/admin/ledger-check.php">Ledger check</a>
    </div>
  </div>

  <div class="panel wide">
    <h2>Last 7 days by model</h2>
    <?php if ($byModel === []): ?>
      <p class="ac-note">No model call has been recorded yet. Once a key is set on <a href="/admin/ai-brain.php">Keys &amp; models</a>
        and the assistant answers a message, each call appears here with its tokens and cost.</p>
    <?php else: ?>
    <div class="dt-wrap"><table class="dt card-table">
      <thead><tr><th>Brain</th><th>Model</th><th class="num">Calls</th><th class="num">Failed</th><th class="num">Tokens in</th>
        <th class="num">From cache</th><th class="num">Tokens out</th><th class="num">Avg time</th><th class="num">Cost</th></tr></thead>
      <tbody>
      <?php foreach ($byModel as $r): $hasPrice = AiMeter::price((string) $r['model']) !== null; ?>
        <tr>
          <td data-label="Brain"><?= $e(AiBrain::PROVIDERS[(string) $r['provider']]['label'] ?? $r['provider']) ?></td>
          <td data-label="Model"><?= $e((string) $r['model'] !== '' ? $r['model'] : '—') ?></td>
          <td data-label="Calls" class="num"><?= $num($r['n']) ?></td>
          <td data-label="Failed" class="num"><?= $num((int) $r['n'] - (int) $r['oks']) ?></td>
          <td data-label="Tokens in" class="num"><?= $num((int) $r['tin'] + (int) $r['cr']) ?></td>
          <td data-label="From cache" class="num"><?= $num($r['cr']) ?></td>
          <td data-label="Tokens out" class="num"><?= $num($r['tout']) ?></td>
          <td data-label="Avg time" class="num"><?= (float) $r['ms'] > 0 ? number_format((float) $r['ms'] / 1000, 1) . ' s' : '—' ?></td>
          <td data-label="Cost" class="num"><?= $hasPrice ? $money((float) $r['cost']) : 'not priced' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="ac-note" style="margin-top:10px">"Not priced" means this model has no price in the system (for example Gemini's free tier),
      so no cost is shown rather than a guess. The daily ceiling counts priced models only.</p>
    <?php endif; ?>
  </div>

  <?php if ($byDay !== []): ?>
  <div class="panel">
    <h2>Day by day</h2>
    <div class="dt-wrap"><table class="dt card-table">
      <thead><tr><th>Day</th><th class="num">Calls</th><th class="num">Failed</th><th class="num">Tokens</th><th class="num">Cost</th></tr></thead>
      <tbody>
      <?php foreach ($byDay as $r): ?>
        <tr><td data-label="Day"><?= $e(date('D j M', strtotime((string) $r['d']))) ?></td>
          <td data-label="Calls" class="num"><?= $num($r['n']) ?></td>
          <td data-label="Failed" class="num"><?= $num((int) $r['n'] - (int) $r['oks']) ?></td>
          <td data-label="Tokens" class="num"><?= $num((int) $r['tin'] + (int) $r['tout']) ?></td>
          <td data-label="Cost" class="num"><?= $money((float) $r['cost']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="panel">
    <h2>Who used it (7 days)</h2>
    <div class="dt-wrap"><table class="dt card-table">
      <thead><tr><th>Where · who</th><th class="num">Calls</th><th class="num">Cost</th></tr></thead>
      <tbody>
      <?php foreach ($byChannel as $r): ?>
        <tr><td data-label="Where"><?= $e((string) $r['who'] !== '' ? $r['who'] : '—') ?></td>
          <td data-label="Calls" class="num"><?= $num($r['n']) ?></td>
          <td data-label="Cost" class="num"><?= $money((float) $r['cost']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Answer quality (7 days)</h2>
    <div class="ac-grid" style="margin:0 0 10px">
      <div class="ac-stat"><div class="k">Actions that worked</div><div class="v"><?= $toolOk === null ? '—' : $toolOk . '%' ?></div>
        <div class="s"><?= $num($toolN) ?> actions · <?= $num($tq['people'] ?? 0) ?> people</div></div>
      <div class="ac-stat"><div class="k">Could not answer</div><div class="v"><?= $num($waits[4][1]) ?></div>
        <div class="s">open questions</div></div>
    </div>
    <?php if ($toolBad !== []): ?>
      <p class="ac-note">Actions refused or failed most often — a refusal is often correct (a customer asking for someone else's ticket),
        so read the reasons in <a href="/admin/ai-activity.php">Every action</a>.</p>
      <div class="ac-tools">
        <?php foreach ($toolBad as $r): ?><span class="ac-tool"><?= $e($r['tool']) ?> · <?= $num($r['bad']) ?> of <?= $num($r['n']) ?></span><?php endforeach; ?>
      </div>
    <?php else: ?><p class="ac-note">No action was refused or failed in the last 7 days.</p><?php endif; ?>
    <?php foreach ($stats as $p => $s): if ((int) $s['ok'] + (int) $s['fail'] === 0) { continue; } ?>
      <div class="ac-log"><b><?= $e(AiBrain::PROVIDERS[$p]['label'] ?? $p) ?></b> — <?= $num($s['ok']) ?> answered, <?= $num($s['fail']) ?> failed
        (all time)<?= (string) $s['last_ok'] !== '' ? ', last answer ' . $e(substr((string) $s['last_ok'], 0, 16)) : '' ?></div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h2>Latest AI warnings today</h2>
    <?php if ($errors === []): ?>
      <p class="ac-note">No AI warning or error in today's log.</p>
    <?php else: foreach ($errors as [$time, $level, $msg]): ?>
      <div class="ac-log"><time><?= $e($time) ?></time><span class="lv"><?= $e(ucfirst(strtolower($level))) ?></span><?= $e($msg) ?></div>
    <?php endforeach; endif; ?>
    <div class="ac-links"><a href="/admin/health.php">System health</a><a href="/admin/messages-log.php">Message log</a></div>
  </div>

  <div class="panel wide">
    <h2>What each role may ask the AI to do</h2>
    <p class="ac-note">This is the live list the assistant is handed for each kind of sender — a tool that is not on a role's list cannot be
      run by that role, whatever the message says. Selling, cancelling and money actions also need the step-up check.</p>
    <?php foreach ($roles as $label => $names): ?>
      <p class="ac-role"><?= $e($label) ?> <em>· <?= count($names) ?> tools</em></p>
      <div class="ac-tools"><?php foreach ($names as $n): ?><span class="ac-tool"><?= $e($n) ?></span><?php endforeach; ?></div>
    <?php endforeach; ?>
  </div>

  <div class="panel wide">
    <h2>Knowledge the AI answers from</h2>
    <?php if ($kb === []): ?>
      <p class="ac-note">No knowledge article yet. Add the company's own rules and FAQs on <a href="/admin/ai-knowledge.php">Knowledge</a>;
        fares, seats and bookings are always read live from the register, not from articles.</p>
    <?php else: ?>
    <div class="dt-wrap"><table class="dt card-table">
      <thead><tr><th>Subject</th><th class="num">Published</th><th class="num">All</th><th>Last change</th></tr></thead>
      <tbody>
      <?php foreach ($kb as $r): ?>
        <tr><td data-label="Subject"><?= $e((string) $r['category'] !== '' ? $r['category'] : '—') ?></td>
          <td data-label="Published" class="num"><?= $num($r['live']) ?></td>
          <td data-label="All" class="num"><?= $num($r['n']) ?></td>
          <td data-label="Last change"><?= $e(substr((string) $r['upd'], 0, 10)) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

</div>
</div>
<?php admin_footer(); ?>
