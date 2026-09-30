<?php
/**
 * admin/ai-usage.php — which AI answered, how often, how fast, what it cost.
 * 26 Sep 2026 (Prompt 2 section 7).
 *
 * Reads ai_provider_usage, which AiClient writes one row per call: provider
 * (local = Ollama on this server, gemini, anthropic), model, tokens,
 * latency, ok/error. No prompt and no customer text is stored there, so
 * nothing on this screen can show one.
 *
 * The cost column is an estimate from two settings (ai_cost_in_per_1k and
 * ai_cost_out_per_1k, rupees per 1,000 tokens, default 0 = free tier);
 * local calls are always free.
 *
 * Office only (dashboard.view) and read-only.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$have = true;
try {
    Database::scalar('SELECT 1 FROM ai_provider_usage LIMIT 1', [], null);
} catch (Throwable $e) {
    $have = false;
}

$rateIn  = Settings::getFloat('ai_cost_in_per_1k', 0.0);
$rateOut = Settings::getFloat('ai_cost_out_per_1k', 0.0);
$cost    = static fn (string $provider, int $in, int $out): float
    => $provider === 'local' ? 0.0 : ($in / 1000 * $rateIn) + ($out / 1000 * $rateOut);

$days = [];
$rows = [];
if ($have) {
    $days = Database::fetchAll(
        "SELECT DATE(created_at) AS d, provider, COUNT(*) AS calls,
                COALESCE(SUM(status = 'ok'), 0) AS ok,
                COALESCE(SUM(input_tokens), 0) AS tin, COALESCE(SUM(output_tokens), 0) AS tout,
                COALESCE(ROUND(AVG(elapsed_ms)), 0) AS ms
           FROM ai_provider_usage
          WHERE created_at >= CURDATE() - INTERVAL 6 DAY
          GROUP BY DATE(created_at), provider
          ORDER BY d DESC, calls DESC");
    $rows = Database::fetchAll(
        "SELECT created_at, provider, model, operation, status, input_tokens, output_tokens, elapsed_ms
           FROM ai_provider_usage ORDER BY id DESC LIMIT 100");
}

admin_header('AI Usage', 'ai-usage');
?>
<style>
  .au-note{font-size:13px;color:var(--mut);margin:0 0 14px;line-height:1.5}
  .au-ok{color:var(--ok);font-weight:700} .au-no{color:var(--warn);font-weight:700}
</style>

<?php if (!$have): ?>
  <div class="panel"><p class="au-note" style="color:var(--bad)">The usage table <code>ai_provider_usage</code>
    is not installed on this server yet. It ships with <code>database/upgrade-2026-09-ai-manager.sql</code>.</p></div>
  <?php admin_footer(); return; ?>
<?php endif; ?>

<p class="au-note">Every AI call made through the AI client: the local model on this server
  (<code>ai_local_on</code> = <?= Settings::getBool('ai_local_on', false) ? 'on' : 'off' ?>) first, the cloud
  model only when the local one does not answer. No message text is stored.
  Cost is an estimate from <code>ai_cost_in_per_1k</code> / <code>ai_cost_out_per_1k</code>.</p>

<div class="panel">
  <h2>Last 7 days</h2>
  <?php if ($days === []): ?>
    <p class="au-note">No AI calls recorded yet.</p>
  <?php else: ?>
  <div class="dt-wrap">
    <table class="dt card-table">
      <thead><tr><th>Day</th><th>Provider</th><th data-type="num">Calls</th><th data-type="num">OK</th><th data-type="num">Tokens in</th><th data-type="num">Tokens out</th><th data-type="num">Avg ms</th><th data-type="num">Est. cost</th></tr></thead>
      <tbody>
      <?php foreach ($days as $d): ?>
        <tr>
          <td data-label="Day"><?= Security::e((string) $d['d']) ?></td>
          <td data-label="Provider"><?= Security::e((string) $d['provider']) ?></td>
          <td data-label="Calls" class="num"><?= (int) $d['calls'] ?></td>
          <td data-label="OK" class="num"><?= (int) $d['ok'] ?></td>
          <td data-label="Tokens in" class="num"><?= (int) $d['tin'] ?></td>
          <td data-label="Tokens out" class="num"><?= (int) $d['tout'] ?></td>
          <td data-label="Avg ms" class="num"><?= (int) $d['ms'] ?></td>
          <td data-label="Est. cost" class="num"><?= Security::e(inr($cost((string) $d['provider'], (int) $d['tin'], (int) $d['tout']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Recent calls (<?= count($rows) ?>)</h2>
  <?php if ($rows !== []): ?>
  <div class="dt-wrap">
    <table class="dt card-table">
      <thead><tr><th>When</th><th>Provider</th><th>Model</th><th>Operation</th><th>Result</th><th data-type="num">In</th><th data-type="num">Out</th><th data-type="num">ms</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td data-label="When"><?= Security::e(substr((string) $r['created_at'], 0, 16)) ?></td>
          <td data-label="Provider"><?= Security::e((string) $r['provider']) ?></td>
          <td data-label="Model"><?= Security::e((string) $r['model']) ?></td>
          <td data-label="Operation"><?= Security::e((string) $r['operation']) ?></td>
          <td data-label="Result"><span class="<?= (string) $r['status'] === 'ok' ? 'au-ok' : 'au-no' ?>"><?= Security::e((string) $r['status']) ?></span></td>
          <td data-label="In" class="num"><?= (int) $r['input_tokens'] ?></td>
          <td data-label="Out" class="num"><?= (int) $r['output_tokens'] ?></td>
          <td data-label="ms" class="num"><?= (int) $r['elapsed_ms'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
