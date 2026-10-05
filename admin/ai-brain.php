<?php
/**
 * admin/ai-brain.php — ONE AI for the whole system (5 Oct 2026).
 *
 * Owner: "my AI is one — chatbot and every other help run on the same
 * one. I will put the Gemini / Claude / GPT key, use whichever is needed;
 * 99 % of the work it handles anyway."
 *
 * One page: which brain answers first (Settings ai_provider), the three
 * keys (blank = keep, CLEAR = remove — same rule as Settings), the model
 * for each, how many answers each gave, and a one-click test. Everything
 * that talks — the WhatsApp bot, the website chat, the AI Sahayak, the
 * help desk — reads the same order (includes/aibrain.php).
 *
 * Seeing it: dashboard.view. Changing it / testing a key: settings.manage.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/aibrain.php';

$admin   = admin_boot('dashboard.view');
$canEdit = Auth::canManageSettings();
$e       = static fn($v): string => Security::e((string) $v);
$flash   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('Session expired — please try again. / फेरि प्रयास गर्नुहोस्।');
        }
        if (!$canEdit) {
            throw new RuntimeException('Changing the AI needs the Settings permission.');
        }
        $act = isset($_POST['test']) ? 'test' : (string) ($_POST['action'] ?? '');
        if ($act === 'save') {
            $pick = (string) ($_POST['ai_provider'] ?? 'auto');
            $pick = isset(AiBrain::PROVIDERS[$pick]) ? $pick : 'auto';
            Settings::set('ai_provider', $pick, 'string', 'ai');
            $changed = ['ai_provider'];
            foreach (AiBrain::PROVIDERS as $p => $cfg) {
                $k = trim((string) ($_POST['key_' . $p] ?? ''));
                if ($k === 'CLEAR') {
                    Settings::set($cfg['key'], '', 'string', 'ai');
                    $changed[] = $cfg['key'] . ' (cleared)';
                } elseif ($k !== '') {
                    if (!preg_match('/^[\x21-\x7e]{16,300}$/', $k)) {
                        throw new RuntimeException($cfg['label'] . ': that does not look like an API key (no spaces, 16+ characters).');
                    }
                    Settings::set($cfg['key'], $k, 'string', 'ai');
                    $changed[] = $cfg['key'];
                }
                if (isset($_POST['model_' . $p])) {
                    $m = trim((string) $_POST['model_' . $p]);
                    if ($m !== '' && !preg_match('/^[A-Za-z0-9._:\-\/]{2,80}$/', $m)) {
                        throw new RuntimeException($cfg['label'] . ': model name has odd characters.');
                    }
                    if ($m !== Settings::getString($cfg['model'], '')) {
                        Settings::set($cfg['model'], $m, 'string', 'ai');
                        $changed[] = $cfg['model'];
                    }
                }
            }
            // Never log a key — only which settings moved.
            Logger::audit('ai.brain', 'settings', 'ai', null, ['changed' => $changed], 'AI brain saved by admin #' . (int) $admin['id']);
            $flash = ['ok', 'Saved. / सेभ भयो। Order now: ' . (implode(' → ', AiBrain::order()) ?: 'no key yet')];
        } elseif ($act === 'test') {
            $p = (string) ($_POST['test'] ?? '');
            $r = AiBrain::test($p);
            $flash = [$r['ok'] ? 'ok' : 'bad', (AiBrain::PROVIDERS[$p]['label'] ?? $p) . ': ' . ($r['ok'] ? 'answered — ' : 'did not answer — ') . $r['detail']];
        }
    } catch (Throwable $ex) {
        $flash = ['bad', $ex->getMessage()];
    }
}

$choice = AiBrain::choice();
$order  = AiBrain::order();
$stats  = AiBrain::stats();
$uses = [
    ['WhatsApp bot', 'replies to passengers and staff on WhatsApp (bookings, PNR, fares, Nepali / Hindi)'],
    ['Website & app chat', 'the "SHG Sahayak" chat bubble — every question the rules cannot answer'],
    ['AI Sahayak (admin)', 'reports, graphs and answers for the office and agents'],
    ['Help desk & voice / photo', 'reading a voice note or a seat photo sent on WhatsApp (Gemini only)'],
];

admin_header('One AI', 'ai-brain');
admin_page_head('One AI brain for the whole system — the WhatsApp bot, the website chat and the AI Sahayak all use this. Put any key; the first one that answers wins.');
?>
<style>
.ab-order{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-weight:700;margin:0 0 14px}
.ab-order span{padding:6px 12px;border-radius:999px;background:var(--soft);border:1px solid var(--line)}
.ab-order span.first{background:var(--blue);color:#fff;border-color:var(--blue)}
.ab-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:14px;margin:0 0 16px}
.ab-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:15px;border-top:4px solid var(--line);display:grid;gap:8px}
.ab-card.on{border-top-color:#16a34a}.ab-card.off{border-top-color:#94a3b8}
.ab-card h3{margin:0;font-size:16px;display:flex;justify-content:space-between;gap:8px;align-items:center}
.ab-card label{display:grid;gap:4px;font-size:12.5px;color:var(--mut)}
.ab-card input{width:100%}
.ab-st{font-size:12.5px;color:var(--mut)}
.ab-st b{color:var(--text,inherit)}
.ab-pick{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 14px}
.ab-pick label{display:flex;gap:8px;align-items:center;padding:10px 14px;border:1px solid var(--line);border-radius:12px;background:var(--card);cursor:pointer;font-weight:600}
.ab-pick input:checked+span{color:var(--blue)}
.ab-uses{display:grid;gap:6px;font-size:13px}
.ab-uses div{display:grid;grid-template-columns:minmax(150px,auto) 1fr;gap:10px}
@media (max-width:600px){.ab-uses div{grid-template-columns:1fr}}
</style>

<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<div class="ab-order">Who answers now:
  <?php if ($order === []): ?><span>No AI key yet — the bots use their fixed answers</span><?php endif; ?>
  <?php foreach ($order as $i => $p): ?><span class="<?= $i === 0 ? 'first' : '' ?>"><?= $i + 1 ?>. <?= $e(AiBrain::PROVIDERS[$p]['label']) ?></span><?php endforeach; ?>
</div>

<form method="post">
  <?= Security::csrfField() ?>
  <input type="hidden" name="action" value="save">
  <!-- Enter in a key box saves (the first submit button is the default one) -->
  <button type="submit" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">Save</button>
  <div class="ab-pick">
    <?php foreach (['auto' => 'Auto — free Gemini first, then GPT, then Claude', 'gemini' => 'Gemini first', 'openai' => 'GPT first', 'anthropic' => 'Claude first'] as $v => $l): ?>
      <label><input type="radio" name="ai_provider" value="<?= $v ?>" <?= $choice === $v ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>><span><?= $e($l) ?></span></label>
    <?php endforeach; ?>
  </div>

  <div class="ab-grid">
    <?php foreach (AiBrain::PROVIDERS as $p => $cfg):
      $has = AiBrain::key($p) !== '';
      $st  = $stats[$p];
      $model = Settings::getString($cfg['model'], '');
    ?>
      <div class="ab-card <?= $has ? 'on' : 'off' ?>">
        <h3><?= $e($cfg['label']) ?> <span class="pill <?= $has ? 'st-ok' : 'st-muted' ?>"><?= $has ? 'key set' : 'no key' ?></span></h3>
        <label>API key <?= $has ? '(leave blank to keep · type CLEAR to remove)' : '' ?>
          <input type="password" name="key_<?= $p ?>" autocomplete="off" placeholder="<?= $has ? '•••••••• saved' : 'paste key' ?>" <?= $canEdit ? '' : 'disabled' ?>>
        </label>
        <label>Model <input type="text" name="model_<?= $p ?>" value="<?= $e($model) ?>" placeholder="<?= $e($cfg['default']) ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
        <div class="ab-st">Answers: <b><?= (int) $st['ok'] ?></b> · failed <b><?= (int) $st['fail'] ?></b><?= (int) $st['ms'] > 0 ? ' · avg <b>' . number_format((int) $st['ms'] / 1000, 1) . ' s</b>' : '' ?>
          <br>Last answer: <?= $st['last_ok'] !== '' ? $e(substr((string) $st['last_ok'], 0, 16)) : '—' ?></div>
        <?php if ($canEdit && $has): ?>
          <button class="btn ghost" type="submit" name="test" value="<?= $p ?>">Test this key</button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($canEdit): ?><p><button class="btn" type="submit">Save</button></p><?php endif; ?>
</form>

<div class="panel">
  <h2>Where this one AI works</h2>
  <div class="panel-body ab-uses">
    <?php foreach ($uses as $u): ?><div><b><?= $e($u[0]) ?></b><span class="muted"><?= $e($u[1]) ?></span></div><?php endforeach; ?>
    <p class="muted text-xs" style="margin:6px 0 0">Keys are kept server-side only and are never shown again after saving. Get keys at aistudio.google.com (Gemini, free), platform.openai.com (GPT), console.anthropic.com (Claude).</p>
  </div>
</div>
<?php
admin_footer();
