<?php
/**
 * admin/ai-local.php — AI on this VPS & the WhatsApp desk (Oct 2026).
 *
 * Owner: "hamro VPS jun model, tei model use hos — no extra API. Aile ko
 * model ramro chaina bhane update gardeu; version mero lagi k thik huncha?"
 *
 *   - is the model server answering, which models are installed, what
 *     each can do (vision / tools), what is loaded right now
 *   - this machine: RAM, CPU, disk → the model that suits it, one-click
 *     download (Ollama pulls in the background)
 *   - a 5-message Nepali / Hindi / English benchmark, run HERE, so a model
 *     is judged on this VPS before WhatsApp uses it
 *   - "what would the AI understand?" — a dry run, nothing is sold or sent
 *   - the switches (superadmin), queue + worker health, Tesseract (OCR)
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-ai.php';
require_once INCLUDE_PATH . '/agentpro-docs.php';
$admin = admin_boot('dashboard.view');
$super = Auth::isSuperadmin();
$e = static fn($v): string => Security::e((string) $v);
$flash = null;
$bench = null;
$dry = null;

$switches = [
    'ap_ai_on'            => ['Use the AI on this VPS', 'WhatsApp messages the desk does not recognise, and ID photos, go to the model below.'],
    'ap_ai_replace_cloud' => ['Instead of Claude / Gemini', 'When on, the VPS model answers WhatsApp and the paid cloud models are not called.'],
    'ap_ai_customers'     => ['Also answer passengers', 'Off = only agents and staff get AI answers; passengers keep the existing bot.'],
    'ap_ai_ack'           => ['Send "⏳ working on it"', 'A short line at once when the AI will take more than a few seconds.'],
    'ap_wa_on'            => ['WhatsApp desk for agents & staff', 'LINK + OTP, balance, statement, withdraw, ID / visiting card, contacts, buses.'],
    'ap_wa_sell'          => ['Sell & change tickets on WhatsApp', 'Agents quote with a message and confirm with "ho". Off = read-only desk.'],
    'ap_ocr_on'           => ['Read ID photos with Tesseract', 'Fast first pass on the join form (needs tesseract-ocr on the VPS).'],
];

if (Security::isPost()) {
    @set_time_limit(900);
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');
        try {
            if (in_array($act, ['save', 'pull'], true) && !$super) {
                throw new RuntimeException('Only the super-admin can change the AI settings.');
            }
            if ($act === 'save') {
                foreach (array_keys($switches) as $k) {
                    Settings::set($k, !empty($_POST[$k]) ? '1' : '0', 'bool', 'agentpro', false);
                }
                $base = trim((string) ($_POST['ap_ai_base'] ?? ''));
                if ($base !== '' && preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._/-]*)?$#', $base) !== 1) {
                    throw new RuntimeException('The model server address looks wrong (e.g. http://127.0.0.1:11434).');
                }
                Settings::set('ap_ai_base', $base !== '' ? $base : 'http://127.0.0.1:11434', 'string', 'agentpro', false);
                foreach (['ap_ai_model', 'ap_ai_vision_model'] as $k) {
                    Settings::set($k, preg_replace('#[^A-Za-z0-9._:/-]#', '', (string) ($_POST[$k] ?? '')) ?? '', 'string', 'agentpro', false);
                }
                Settings::set('ap_ai_timeout', (string) max(20, min(600, (int) ($_POST['ap_ai_timeout'] ?? 150))), 'int', 'agentpro', false);
                if (isset($_POST['ap_ai_api_key']) && $_POST['ap_ai_api_key'] !== '••••') {
                    Settings::set('ap_ai_api_key', trim((string) $_POST['ap_ai_api_key']), 'string', 'agentpro', false);
                }
                Settings::flush();
                Logger::audit('agentpro.ai_settings', 'settings', 'agentpro', null, null, 'AI / WhatsApp desk settings saved');
                $flash = ['ok', 'Saved.'];
            } elseif ($act === 'pull') {
                $r = ApAi::pull((string) ($_POST['model'] ?? ''));
                $flash = $r['ok'] ? ['ok', 'Download started on the VPS. It can take 5–20 minutes — refresh this page to see it under Installed.'] : ['bad', (string) $r['error']];
            } elseif ($act === 'bench') {
                $bench = ApAi::benchmark((string) ($_POST['model'] ?? ''));
            } elseif ($act === 'dry') {
                require_once INCLUDE_PATH . '/agentpro-brain.php';
                $asMember = ($_POST['as'] ?? 'member') === 'member';
                $member = $asMember ? Database::fetch('SELECT id, full_name, role FROM admins WHERE id = :i', ['i' => (int) $admin['id']]) : null;
                $t0 = microtime(true);
                $dry = ['in' => (string) ($_POST['text'] ?? ''), 'out' => ApBrain::understand((string) ($_POST['text'] ?? ''), ['role' => $asMember ? 'staff' : 'customer'],
                    in_array($_POST['lang'] ?? '', ['ne', 'hi', 'en'], true) ? (string) $_POST['lang'] : 'ne', $member), 'ms' => (int) ((microtime(true) - $t0) * 1000)];
            }
        } catch (RuntimeException $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
    }
}

$version = ApAi::version();
$models = $version !== '' ? ApAi::models(true) : [];
$running = $version !== '' ? ApAi::running() : [];
$hw = ApAi::hardware();
$rec = ApAi::recommend($hw);
$chat = $version !== '' ? ApAi::pickModel('chat') : '';
$vision = $version !== '' ? ApAi::pickModel('vision') : '';
$stats = ApAi::stats();
$queue = (int) Database::scalar("SELECT COUNT(*) FROM ap_jobs WHERE status IN ('queued','running')", [], 0);
$seen = (int) Settings::getString('ap_worker_seen', '0');
$installed = array_column($models, 'name');

admin_header('AI (VPS) & WhatsApp', 'ai-local.php');
echo '<link rel="stylesheet" href="/assets/agentpro/admin.css?v=' . (int) @filemtime(ASSET_PATH . '/agentpro/admin.css') . '">';
admin_page_head('Your own AI on this server — no paid API, nothing leaves the VPS.');
if ($flash !== null) {
    echo '<div class="flash ' . $e($flash[0]) . '">' . $e($flash[1]) . '</div>';
}
?>
<div class="ap-a">
<div class="kpis">
  <?= admin_kpi('Model server', $version !== '' ? 'Online' : 'Offline', $version !== '' ? 'v' . $version : ApAi::base(), 'sparkle', $version !== '' ? 'green' : 'red') ?>
  <?= admin_kpi('AI', Settings::getBool('ap_ai_on', false) ? 'On' : 'Off', $chat !== '' ? $chat : 'no model', 'bolt', Settings::getBool('ap_ai_on', false) ? 'green' : 'orange') ?>
  <?= admin_kpi('Answers (24 h)', (string) $stats['calls'], $stats['failed'] . ' failed · avg ' . round($stats['avgMs'] / 1000, 1) . ' s', 'msg', $stats['failed'] > 0 ? 'orange' : 'blue') ?>
  <?= admin_kpi('Queue', (string) $queue, $seen > 0 ? 'worker ' . timeAgo(date('Y-m-d H:i:s', $seen)) : 'worker never ran — add the cron', 'clock', $seen > 0 && $seen > time() - 600 ? 'blue' : 'orange') ?>
</div>

<div class="tp-money">
  <section class="panel"><h2><svg class="a-ic"><use href="#a-cog"/></svg> This VPS</h2><div class="panel-body">
    <div class="ai-hw"><div><small>Memory</small><b><?= $e($hw['ramGb']) ?> GB</b><small><?= $e($hw['availGb']) ?> GB free</small></div>
      <div><small>CPU cores</small><b><?= (int) $hw['cpus'] ?></b><small><?= $hw['gpu'] ? 'GPU found' : 'no GPU — CPU only' ?></small></div>
      <div><small>Disk free</small><b><?= $e($hw['diskGb']) ?> GB</b></div></div>
    <div class="ai-rec" style="margin-top:14px">
      <div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;opacity:.8">Best for this VPS</div>
      <div style="font-size:20px;margin:4px 0"><b><?= $e($rec['chat']) ?></b><?= $rec['vision'] !== $rec['chat'] ? ' + <b>' . $e($rec['vision']) . '</b> for documents' : '' ?></div>
      <div style="opacity:.9;font-size:13.5px"><?= $e($rec['why']) ?></div>
      <?php if ($super && $version !== ''): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
        <?php foreach (array_unique(array_merge([$rec['chat'], $rec['vision']], $rec['alternatives'])) as $mdl): $have = in_array($mdl, $installed, true); ?>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="pull"><input type="hidden" name="model" value="<?= $e($mdl) ?>">
            <button class="btn <?= $have ? 'ghost' : 'warn' ?> sm" <?= $have ? 'disabled' : '' ?>><?= $have ? '✓ ' : '⬇ ' ?><?= $e($mdl) ?></button></form>
        <?php endforeach; ?>
      </div><?php endif; ?>
    </div>
  </div></section>

  <section class="panel"><h2><svg class="a-ic"><use href="#a-layers"/></svg> Installed models</h2><div class="panel-body">
    <?php if ($version === ''): ?>
      <div class="flash bad">The model server at <b><?= $e(ApAi::base()) ?></b> is not answering.</div>
      <p class="ph-sub">On the VPS (one time, as root):</p>
      <pre style="background:var(--soft);padding:12px;border-radius:12px;overflow:auto;font-size:12.5px">curl -fsSL https://ollama.com/install.sh | sh
ollama pull <?= $e($rec['chat']) ?>

systemctl enable --now ollama      # starts on boot, listens on 127.0.0.1:11434</pre>
    <?php else: ?>
      <div class="ai-models">
        <?php if ($models === []) { echo admin_empty('No model yet', 'Use the download button on the left.', '📦'); } ?>
        <?php foreach ($models as $mdl): $isRun = in_array($mdl['name'], array_column($running, 'name'), true); ?>
          <div class="ai-model<?= $mdl['name'] === $chat ? ' on' : '' ?>"><span style="flex:1;min-width:0"><b><?= $e($mdl['name']) ?></b>
            <small class="ph-sub" style="display:block;margin:2px 0 0"><?= $e(trim($mdl['params'] . ' · ' . $mdl['quant'] . ' · ' . $mdl['gb'] . ' GB', ' ·')) ?></small></span>
            <span class="tags"><?= $mdl['name'] === $chat ? '<span class="tp-chip ok">chat</span>' : '' ?><?= $mdl['name'] === $vision ? '<span class="tp-chip ok">documents</span>' : '' ?>
              <?= $mdl['vision'] ? '<span class="tp-chip blue">vision</span>' : '' ?><?= $mdl['tools'] ? '<span class="tp-chip blue">tools</span>' : '' ?><?= $isRun ? '<span class="tp-chip warn">loaded</span>' : '' ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div></section>
</div>

<section class="panel"><h2><svg class="a-ic"><use href="#a-trophy"/></svg> Benchmark on this VPS — Nepali, Hindi, English</h2><div class="panel-body">
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><?= Security::csrfField() ?><input type="hidden" name="action" value="bench">
    <select name="model" style="height:38px;border-radius:10px;border:1px solid var(--line-2);background:var(--card);color:var(--ink);padding:0 10px">
      <?php foreach ($models as $mdl): ?><option<?= $mdl['name'] === $chat ? ' selected' : '' ?>><?= $e($mdl['name']) ?></option><?php endforeach; ?></select>
    <button class="btn sm" <?= $models === [] ? 'disabled' : '' ?> onclick="this.textContent='Running… (1–5 min)'">Run 5 tests</button>
    <span class="ph-sub">Each test is a real WhatsApp-style message; the model must read seats, date, pickup and phone exactly.</span></form>
  <?php if ($bench !== null): ?>
    <p style="font-size:16px;margin:14px 0 6px"><b><?= $e($bench['model']) ?>: <?= (int) $bench['score'] ?> / <?= (int) $bench['of'] ?></b> correct in <?= $e($bench['seconds']) ?> s
      (<?= $e(round($bench['seconds'] / max(1, $bench['of']), 1)) ?> s per message)</p>
    <table class="ai-bench" style="width:100%;border-collapse:collapse"><tr><th>Lang</th><th>Message</th><th>Result</th><th>Time</th></tr>
      <?php foreach ($bench['tasks'] as $t): ?><tr><td><?= $e($t['lang']) ?></td><td><?= $e($t['q']) ?></td>
        <td><?= $t['ok'] ? '✅' : '❌ ' . $e(implode(', ', $t['missed']) . ($t['error'] !== '' ? ' — ' . $t['error'] : '')) ?></td><td><?= $e(round($t['ms'] / 1000, 1)) ?> s</td></tr><?php endforeach; ?></table>
  <?php endif; ?>
</div></section>

<div class="tp-money">
  <section class="panel"><h2><svg class="a-ic"><use href="#a-eye"/></svg> What would the AI understand? (dry run)</h2><div class="panel-body">
    <form method="post" class="mp-form"><?= Security::csrfField() ?><input type="hidden" name="action" value="dry">
      <label class="full">Message<input name="text" value="<?= $e($dry['in'] ?? 'bholi 2 jana surat bata jane, Ram Thapa 9812345678') ?>"></label>
      <label>As<select name="as"><option value="member">An agent / staff</option><option value="customer">A passenger</option></select></label>
      <label>Reply language<select name="lang"><option value="ne">नेपाली</option><option value="hi">हिंदी</option><option value="en">English</option></select></label>
      <div class="full"><button class="btn sm" <?= $version === '' ? 'disabled' : '' ?>>Ask the model</button> <span class="ph-sub">Nothing is booked, sent or saved.</span></div></form>
    <?php if ($dry !== null): ?><pre style="background:var(--soft);padding:12px;border-radius:12px;overflow:auto;font-size:12.5px"><?= $e(json_encode($dry['out'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: 'null — the model did not answer') ?>

<?= (int) $dry['ms'] ?> ms</pre><?php endif; ?>
  </div></section>

  <section class="panel"><h2><svg class="a-ic"><use href="#a-cog"/></svg> Switches</h2><div class="panel-body">
    <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="save">
      <?php foreach ($switches as $k => [$label, $help]): ?>
        <label class="ai-switch"><span><b><?= $e($label) ?></b><small><?= $e($help) ?></small></span>
          <input type="checkbox" name="<?= $e($k) ?>" value="1" <?= Settings::getBool($k, in_array($k, ['ap_ai_on'], true) ? false : true) ? 'checked' : '' ?> <?= $super ? '' : 'disabled' ?> style="width:22px;height:22px"></label>
      <?php endforeach; ?>
      <div class="mp-form" style="margin-top:12px">
        <label>Model server<input name="ap_ai_base" value="<?= $e(ApAi::base()) ?>" <?= $super ? '' : 'disabled' ?>></label>
        <label>Seconds per answer (max)<input name="ap_ai_timeout" type="number" min="20" max="600" value="<?= (int) Settings::getInt('ap_ai_timeout', 150) ?>" <?= $super ? '' : 'disabled' ?>></label>
        <label>Chat model (empty = best installed)<input name="ap_ai_model" list="mdl" value="<?= $e(Settings::getString('ap_ai_model', '')) ?>" <?= $super ? '' : 'disabled' ?>></label>
        <label>Document model (empty = auto)<input name="ap_ai_vision_model" list="mdl" value="<?= $e(Settings::getString('ap_ai_vision_model', '')) ?>" <?= $super ? '' : 'disabled' ?>></label>
        <label class="full">API key (only for a non-Ollama server)<input name="ap_ai_api_key" value="<?= Settings::getString('ap_ai_api_key', '') !== '' ? '••••' : '' ?>" autocomplete="off" <?= $super ? '' : 'disabled' ?>></label>
        <datalist id="mdl"><?php foreach ($installed as $n): ?><option value="<?= $e($n) ?>"><?php endforeach; ?></datalist>
      </div>
      <?php if ($super): ?><button class="btn" type="submit" style="margin-top:10px">Save</button><?php endif; ?>
    </form>
    <p class="ph-sub" style="margin-top:14px">Tesseract (OCR): <?= ApDocRead::tesseractAvailable() ? '<b>installed</b> · ' . $e(implode(', ', ApDocRead::langs())) : '<b>not installed</b> — <code>apt install tesseract-ocr tesseract-ocr-hin tesseract-ocr-nep</code>' ?></p>
    <p class="ph-sub">Background worker cron (every minute): <code>* * * * * php <?= $e(ROOT_PATH) ?>/cron/agentpro-worker.php &gt;/dev/null 2&gt;&amp;1</code></p>
  </div></section>
</div>

<section class="panel"><h2><svg class="a-ic"><use href="#a-whatsapp"/></svg> What agents and staff can write on WhatsApp</h2><div class="panel-body">
  <div class="cc-grid" style="gap:10px">
    <?php foreach ([
        ['LINK SHG-0027', 'Link this WhatsApp to an agent code — an OTP goes to the registered mobile.'],
        ['2 seat bholi Mehsana, Ram Thapa 9812345678', 'Quote a ticket; "ho" sells it on the agent\'s code, the ticket goes to the passenger.'],
        ['SHG-2026-01234 naam Sita Thapa', 'Change a ticket: naam / pickup / seat / phone / date / gender / age — then "ho".'],
        ['SHG-2026-01234 cancel', 'Refund amount first, then "ho" cancels.'],
        ['balance · statement · withdraw 2000', 'Wallet: what is due, the passbook with a PDF, a payout request.'],
        ['jama 1500 UTR 430987651234', 'Tell the office you paid; it appears under Team → Money.'],
        ['id card · visiting card · me', 'The cards as pictures, and their own details.'],
        ['contacts · number Ram · office · bus · aaja · passengers bholi', 'People, company facts (CIN …), today\'s buses, the day\'s sales, the passenger list.'],
    ] as [$cmd, $what]): ?>
      <div class="tp-req"><span class="who"><b class="ap-mono" style="font-size:13px"><?= $e($cmd) ?></b><small style="white-space:normal"><?= $e($what) ?></small></span></div>
    <?php endforeach; ?>
  </div>
  <p class="ph-sub">Anything else from an agent or staff member is read by the AI above (when it is on) — in Nepali, Hindi or English.</p>
</div></section>
</div>
<?php admin_footer();
