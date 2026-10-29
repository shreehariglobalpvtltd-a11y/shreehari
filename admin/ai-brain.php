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
require_once INCLUDE_PATH . '/aimaster.php';
require_once INCLUDE_PATH . '/nameguess.php';

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
                        if ($p === 'anthropic') {
                            // The WhatsApp agent reads its own key; one choice here sets both (29 Oct 2026).
                            Settings::set('ai_agent_model', $m, 'string', 'ai');
                            $changed[] = 'ai_agent_model';
                        }
                    }
                }
            }
            // Never log a key — only which settings moved.
            Logger::audit('ai.brain', 'settings', 'ai', null, ['changed' => $changed], 'AI brain saved by admin #' . (int) $admin['id']);
            $flash = ['ok', 'Saved. / सेभ भयो। Order now: ' . (implode(' → ', AiBrain::order()) ?: 'no key yet')];
        } elseif ($act === 'booking_ai') {
            /* 15 Oct 2026: the booking brain - name -> F / M guess and the
               one-message WhatsApp sale. */
            $inst = (string) ($_POST['wa_instant_sale'] ?? 'staff');
            if (!in_array($inst, ['off', 'staff', 'all'], true)) {
                throw new RuntimeException('Unknown WhatsApp one-message setting.');
            }
            $before = ['ai_name_guess_on' => NameGuess::on() ? 1 : 0, 'wa_instant_sale' => Settings::getString('wa_instant_sale', 'staff')];
            $after  = ['ai_name_guess_on' => (string) ($_POST['ai_name_guess_on'] ?? '0') === '1' ? 1 : 0, 'wa_instant_sale' => $inst];
            Settings::set('ai_name_guess_on', $after['ai_name_guess_on'] ? '1' : '0', 'bool', 'ai');
            Settings::set('wa_instant_sale', $inst, 'string', 'whatsapp');
            Logger::audit('ai.booking', 'settings', 'ai', $before, $after, 'Booking AI saved by admin #' . (int) $admin['id']);
            $flash = ['ok', 'Booking AI saved. / सेभ भयो।'];
        } elseif ($act === 'master') {
            /* 29 Oct 2026 - S HARI AI master brief + the Claude Opus 5.5 knobs
               (includes/aimaster.php, AiBrain::claudeShape). */
            $effort = (string) ($_POST['ai_claude_effort'] ?? 'low');
            if (!in_array($effort, AiBrain::CLAUDE_EFFORTS, true)) {
                throw new RuntimeException('Unknown effort level.');
            }
            $text = str_replace("\r\n", "\n", (string) ($_POST['ai_master_prompt'] ?? ''));
            if (isset($_POST['reset_text']) || trim($text) === trim(AiMaster::defaultText())) {
                $text = '';                                   // blank = the built-in brief, kept up to date by code
            }
            if (mb_strlen($text) > AiMaster::MAX_LEN) {
                throw new RuntimeException('The master brief is too long — keep it under ' . AiMaster::MAX_LEN . ' characters.');
            }
            $before = [
                'ai_master_prompt_on' => AiMaster::on() ? 1 : 0, 'ai_claude_effort' => AiBrain::claudeEffort(),
                'ai_claude_fallback' => Settings::getBool('ai_claude_fallback', true) ? 1 : 0,
                'ai_master_prompt' => Settings::getString('ai_master_prompt', '') === '' ? 'built-in' : 'custom',
            ];
            $after = [
                'ai_master_prompt_on' => (string) ($_POST['ai_master_prompt_on'] ?? '0') === '1' ? 1 : 0, 'ai_claude_effort' => $effort,
                'ai_claude_fallback' => (string) ($_POST['ai_claude_fallback'] ?? '0') === '1' ? 1 : 0,
                'ai_master_prompt' => trim($text) === '' ? 'built-in' : 'custom',
            ];
            Settings::set('ai_master_prompt_on', $after['ai_master_prompt_on'] ? '1' : '0', 'bool', 'ai');
            Settings::set('ai_claude_effort', $effort, 'string', 'ai');
            Settings::set('ai_claude_fallback', $after['ai_claude_fallback'] ? '1' : '0', 'bool', 'ai');
            Settings::set('ai_master_prompt', trim($text), 'string', 'ai');
            Logger::audit('ai.master', 'settings', 'ai', $before, $after, 'S HARI AI master brief saved by admin #' . (int) $admin['id']);
            $flash = ['ok', 'Master brief saved. / सेभ भयो।' . ($after['ai_master_prompt_on'] ? ' S HARI AI is ON.' : ' It stays OFF until you tick it.')];
        } elseif ($act === 'opus55') {
            Settings::set('ai_model', AiBrain::CLAUDE_DEFAULT, 'string', 'ai');
            Settings::set('ai_agent_model', AiBrain::CLAUDE_DEFAULT, 'string', 'ai');
            Logger::audit('ai.brain', 'settings', 'ai', null, ['ai_model' => AiBrain::CLAUDE_DEFAULT, 'ai_agent_model' => AiBrain::CLAUDE_DEFAULT], 'Claude set to Opus 5.5 by admin #' . (int) $admin['id']);
            $flash = ['ok', 'Claude now uses ' . AiBrain::CLAUDE_DEFAULT . ' (website chat and WhatsApp agent). Order: ' . (implode(' → ', AiBrain::order()) ?: 'no key yet')];
        } elseif ($act === 'relearn') {
            $r = NameGuess::rebuild();
            $flash = ['ok', 'Relearned ' . $r['names'] . ' names from ' . $r['passengers'] . ' passengers. / नाम फेरि सिकियो।'];
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

<?php
/* 29 Oct 2026 - S HARI AI master brief (owner's "Opus 5.5 Max" prompt, section 12). */
$mOn      = AiMaster::on();
$mText    = Settings::getString('ai_master_prompt', '');
$mEffort  = AiBrain::claudeEffort();
$mFallbk  = Settings::getBool('ai_claude_fallback', true);
$claudeM  = Settings::getString('ai_model', '') ?: AiBrain::CLAUDE_DEFAULT;
$agentM   = Settings::getString('ai_agent_model', '') ?: $claudeM;
$pvRole   = in_array($_GET['brief'] ?? '', ['customer', 'staff', 'admin'], true) ? (string) $_GET['brief'] : 'customer';
$pvTools  = [];
try {
    require_once INCLUDE_PATH . '/aitools.php';
    $pvTools = AiTools::catalogue(['role' => $pvRole, 'channel' => 'whatsapp', 'phone' => '9800000000']);
} catch (Throwable $ignored) {
    // the preview still shows the text and the mode
}
$efforts = [
    'low'    => 'low — fast WhatsApp answers (recommended)',
    'medium' => 'medium — Opus 5.5 default, a little slower',
    'high'   => 'high — careful, slower',
    'xhigh'  => 'xhigh — very careful, slow',
    'max'    => 'max — deepest reasoning, slowest and costliest',
];
?>
<div class="panel" id="masterBrief">
  <h2>S HARI AI — master brief (Claude Opus 5.5)</h2>
  <div class="panel-body ab-uses">
    <p class="muted" style="margin:0">One assistant on the website chat, WhatsApp and the office panel, with the <b>four security gates</b>
      (role from the verified number · own bookings only · money only while switched on · quote first, act on the next "ho").
      The gates are enforced by the system either way; this brief tells the AI about them, gives it the name <b>S HARI AI</b> and
      lists only the tools that really exist for each person. It sits in front of today's tuned briefing — nothing there is removed.
      <b>Status: <?= $mOn ? '<span class="pill st-ok">ON</span>' : '<span class="pill st-muted">OFF</span>' ?></b></p>
    <p class="muted" style="margin:0">Claude model now: <b><?= $e($claudeM) ?></b> (chat) · <b><?= $e($agentM) ?></b> (WhatsApp agent).
      Claude answers only when it is first in the order above or the ones before it fail.</p>
    <?php if ($canEdit): ?>
      <?php if ($claudeM !== AiBrain::CLAUDE_DEFAULT || $agentM !== AiBrain::CLAUDE_DEFAULT): ?>
        <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="opus55">
          <button class="btn btn-ghost" type="submit">Use Claude Opus 5.5 for both</button></form>
      <?php endif; ?>
      <form method="post" style="display:grid;gap:10px">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="master">
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="ai_master_prompt_on" value="1" <?= $mOn ? 'checked' : '' ?>> Turn on the S HARI AI master brief</label>
        <label>Claude thinking effort (Opus 5.5 always thinks; this sets how much)
          <select name="ai_claude_effort">
            <?php foreach ($efforts as $v => $l): ?><option value="<?= $v ?>" <?= $mEffort === $v ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
          </select></label>
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="ai_claude_fallback" value="1" <?= $mFallbk ? 'checked' : '' ?>> If Claude's safety filter wrongly declines a message, let Anthropic answer it with its recommended backup model (recommended)</label>
        <label>Master brief text — blank uses the built-in one. <code>{company}</code> and <code>{office_phone}</code> are filled in; the mode and the tool list are always added after it.
          <textarea name="ai_master_prompt" rows="12" style="width:100%;font-family:ui-monospace,monospace;font-size:12.5px" maxlength="<?= AiMaster::MAX_LEN ?>"><?= $e($mText !== '' ? $mText : AiMaster::defaultText()) ?></textarea></label>
        <p style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn" type="submit">Save</button>
          <button class="btn btn-ghost" type="submit" name="reset_text" value="1">Save with the built-in text</button></p>
      </form>
    <?php endif; ?>
    <details>
      <summary>Preview what the AI reads first —
        <?php foreach (['customer' => 'passenger', 'staff' => 'agent', 'admin' => 'office'] as $r => $l): ?>
          <a href="?brief=<?= $r ?>#masterBrief"<?= $pvRole === $r ? ' style="font-weight:700"' : '' ?>><?= $e($l) ?></a>
        <?php endforeach; ?></summary>
      <pre style="white-space:pre-wrap;font-size:12px;max-height:420px;overflow:auto;background:var(--soft);padding:10px;border-radius:10px"><?= $e(AiMaster::compose(['role' => $pvRole], $pvTools)) ?></pre>
    </details>
  </div>
</div>

<div class="panel">
  <h2>Where this one AI works</h2>
  <div class="panel-body ab-uses">
    <?php foreach ($uses as $u): ?><div><b><?= $e($u[0]) ?></b><span class="muted"><?= $e($u[1]) ?></span></div><?php endforeach; ?>
    <p class="muted text-xs" style="margin:6px 0 0">Keys are kept server-side only and are never shown again after saving. Get keys at aistudio.google.com (Gemini, free), platform.openai.com (GPT), console.anthropic.com (Claude).</p>
  </div>
</div>
<?php
/* 15 Oct 2026 - the booking brain: what it learned, the switches, a try box. */
$ng     = NameGuess::stats(16);
$try    = Security::clean((string) ($_GET['try'] ?? ''), 120);
$tryRes = $try !== '' ? NameGuess::gender($try) : null;
$inst   = Settings::getString('wa_instant_sale', 'staff');
?>
<div class="panel" id="bookingAi">
  <h2>Booking AI — learns from every ticket</h2>
  <div class="panel-body ab-uses">
    <p class="muted" style="margin:0">The same brain works on all three booking doors — the customer app, the office SmartBook / counter and WhatsApp.
      It guesses <b>Female / Male from the name</b> (Mrs, Kumari, Devi, Bahadur, -ben, -bhai, and every name your passengers already carried),
      the <b>usual pickup and direction from the mobile's own history</b>, and the <b>nearest pickup from the phone's location</b>.
      A guess is always shown with 🤖 and one tap changes it. Learned now: <b><?= (int) $ng['names'] ?></b> names from <b><?= (int) $ng['passengers'] ?></b> passengers (relearned every night).</p>
    <?php if ($ng['top'] !== []): ?>
      <div style="display:flex;flex-wrap:wrap;gap:6px"><?php foreach ($ng['top'] as $t): ?>
        <span class="chip" title="<?= (int) $t['male'] ?> male · <?= (int) $t['female'] ?> female"><?= $e(ucfirst($t['name'])) ?> <?= $t['female'] > $t['male'] ? '♀' : '♂' ?> <?= (int) ($t['male'] + $t['female']) ?></span>
      <?php endforeach; ?></div>
    <?php endif; ?>
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <label>Try a name <input name="try" value="<?= $e($try) ?>" placeholder="Sita Kumari Thapa" maxlength="120"></label>
      <button class="btn btn-ghost" type="submit">Guess</button>
      <?php if ($tryRes !== null): ?>
        <span><b><?= $tryRes['gender'] !== '' ? $e($tryRes['gender']) : 'No guess' ?></b>
          <?php if ($tryRes['gender'] !== ''): ?> · <?= (int) round($tryRes['confidence'] * 100) ?>% · <?= $e($tryRes['why']) ?>
            · <?= $tryRes['confidence'] >= NameGuess::AUTO ? 'used everywhere' : ($tryRes['confidence'] >= NameGuess::SHOW ? 'shown on the card only' : 'too weak - not used') ?><?php endif; ?></span>
      <?php endif; ?>
    </form>
    <?php if ($canEdit): ?>
    <form method="post" style="display:grid;gap:8px">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="booking_ai">
      <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="ai_name_guess_on" value="1" <?= NameGuess::on() ? 'checked' : '' ?>> Guess Female / Male from the name</label>
      <label>WhatsApp — a complete order in ONE message (name, mobile, pickup, date, seats)
        <select name="wa_instant_sale">
          <?php foreach (['staff' => 'Agents / counter numbers: ticket at once, no "yes" step', 'all' => 'Everyone (customers too): ticket at once', 'off' => 'Always show the summary and wait for "yes"'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= $inst === $v ? 'selected' : '' ?>><?= $e($l) ?></option>
          <?php endforeach; ?>
        </select></label>
      <p><button class="btn" type="submit">Save</button></p>
    </form>
    <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="relearn"><button class="btn btn-ghost" type="submit">Relearn names now</button></form>
    <?php endif; ?>
  </div>
</div>
<?php
admin_footer();
