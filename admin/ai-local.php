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
 *
 * 30 Oct 2026 (af1, owner: "AI afnai VPS ma, sajilo; haat le bhareko form
 * AI le padhos — English ra Nepali; sample dinchhu, sikauna milos"):
 *   - one clear status for the reader ladder (K7): model server up? model
 *     installed? Gemini key saved (yes / no — never shown)? Tesseract?
 *   - the recommendation counts what MySQL, PHP and the Valhalla route
 *     engine already hold, and the page prints the exact root commands
 *     (official Ollama install + a systemd drop-in with MemoryMax /
 *     CPUQuota / keep-alive / one model) — PHP cannot install anything
 *   - "Form AI training": the office's notes (ap_form_hints, seeded with
 *     the rules learnt from the owner's sample) and corrected examples
 *     (ap_form_examples); try a filled sample (photos or a PDF, turned
 *     into page pictures in the browser) through ApFormRead and save the
 *     corrections as examples in one tap. A sample is never stored.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-ai.php';
require_once INCLUDE_PATH . '/agentpro-docs.php';
require_once INCLUDE_PATH . '/docverify.php';
require_once INCLUDE_PATH . '/agentpro-formread.php';
$admin = admin_boot('dashboard.view');
$super = Auth::isSuperadmin();
$canTrain = $super || (string) ($admin['role'] ?? '') === 'manager' || Auth::can('staff.manage');
$selfId = (int) ($admin['id'] ?? 0);
$e = static fn($v): string => Security::e((string) $v);
$flash = null;
$bench = null;
$dry = null;

$switches = [
    'ap_ai_on'            => ['Use the AI on this VPS for WhatsApp', 'WhatsApp messages the desk does not recognise go to the model below. When the model server is down the desk answers with its own menu.'],
    'ap_ai_docs'          => ['Read ID photos & paper forms with the VPS model', 'Works even with the WhatsApp AI off. When the model is not answering, Gemini (if a key is saved) and then Tesseract read them.'],
    'ap_ai_replace_cloud' => ['Instead of Claude / Gemini', 'When on, the VPS model answers WhatsApp and the paid cloud models are not called.'],
    'ap_ai_customers'     => ['Also answer passengers', 'Off = only agents and staff get AI answers; passengers keep the existing bot.'],
    'ap_ai_ack'           => ['Send "⏳ working on it"', 'A short line at once when the AI will take more than a few seconds.'],
    'ap_wa_on'            => ['WhatsApp desk for agents & staff', 'LINK + OTP, balance, statement, withdraw, ID / visiting card, contacts, buses.'],
    'ap_wa_sell'          => ['Sell & change tickets on WhatsApp', 'Agents quote with a message and confirm with "ho". Off = read-only desk.'],
    'ap_ocr_on'           => ['Read ID photos with Tesseract', 'Fast first pass on the join form (needs tesseract-ocr on the VPS).'],
];

$saveExamples = static function (array $list, string $why) use ($selfId): int {
    $before = count(ApFormRead::training()['examples']);
    $list = ApFormRead::cleanExamples($list);
    Settings::set('ap_form_examples', json_encode($list, JSON_UNESCAPED_UNICODE), 'json', 'agentpro', false);
    Settings::flush();
    Logger::audit('agentpro.form_ai_examples', 'settings', 'ap_form_examples', ['count' => $before], ['count' => count($list)], $why . ' by admin #' . $selfId);
    return count($list);
};

if (Security::isPost()) {
    @set_time_limit(900);
    $act = (string) ($_POST['action'] ?? '');

    /* The trial read of a sample form answers JSON (fetch from the training panel). */
    if ($act === 'formtry') {
        if (!Security::verifyCsrf()) {
            Response::json(['ok' => false, 'error' => 'Session expired — refresh the page. / पेज रिफ्रेस गर्नुहोस्।'], 419);
        }
        if (!$canTrain) {
            Response::json(['ok' => false, 'error' => 'Only a super-admin, a manager or staff.manage can try the form AI.'], 403);
        }
        if (!Security::rateLimit('ap_formtry', 'admin:' . $selfId, 40, 3600)) {
            Response::json(['ok' => false, 'error' => 'Too many trial reads this hour — please wait a little.'], 429);
        }
        $kind = ($_POST['kind'] ?? '') === 'staff' ? 'staff' : 'agent';
        $only = (string) ($_POST['reader'] ?? 'auto');
        $readers = in_array($only, ['vps', 'gemini', 'tesseract'], true) ? [$only] : ['vps', 'gemini', 'tesseract'];
        $files = [];
        try {
            foreach (array_slice((array) ($_POST['pages'] ?? []), 0, ApFormRead::MAX_PAGES) as $uri) {
                if (!is_string($uri) || preg_match('#^data:image/(jpeg|png|webp);base64,#', $uri, $m) !== 1) {
                    continue;
                }
                $bytes = base64_decode(substr($uri, strlen($m[0])), true);
                $info = $bytes !== false && strlen($bytes) <= 3_000_000 ? @getimagesizefromstring($bytes) : false;
                if ($info === false || !in_array((int) $info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                    continue;
                }
                $f = sys_get_temp_dir() . '/apformtry-' . bin2hex(random_bytes(8)) . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
                if (@file_put_contents($f, $bytes) !== false) {
                    $files[] = $f;
                }
            }
            if ($files === []) {
                Response::json(['ok' => false, 'error' => 'Add a photo of a filled page (or a PDF). / फारमको फोटो वा PDF थप्नुहोस्।'], 422);
            }
            $r = ApFormRead::read($files, $kind, ['readers' => $readers]);
        } finally {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        // the log names only which boxes were filled — never a value (they are someone's ID / bank details)
        Logger::info('Form AI trial read', ['status' => $r['status'], 'reader' => $r['reader'], 'kind' => $kind, 'pages' => count($files),
            'filled' => array_keys($r['fields']), 'admin' => $selfId]);
        Response::json($r + ['labels' => ApFormRead::labels($kind)]);
    }

    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            if (in_array($act, ['save', 'pull'], true) && !$super) {
                throw new RuntimeException('Only the super-admin can change the AI settings.');
            }
            if (in_array($act, ['hints', 'hints_reset', 'example_add', 'corrections', 'example_delete', 'examples_clear'], true) && !$canTrain) {
                throw new RuntimeException('Only a super-admin, a manager or staff.manage can train the form AI.');
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
                Settings::set('ap_ai_doc_timeout', (string) max(20, min(600, (int) ($_POST['ap_ai_doc_timeout'] ?? 120))), 'int', 'agentpro', false);
                Settings::set('ap_ai_wa_wait', (string) max(3, min(60, (int) ($_POST['ap_ai_wa_wait'] ?? 20))), 'int', 'agentpro', false);
                Settings::set('ap_form_vps_budget', (string) max(30, min(900, (int) ($_POST['ap_form_vps_budget'] ?? 150))), 'int', 'agentpro', false);
                $ka = trim((string) ($_POST['ap_ai_keep_alive'] ?? '30m'));
                Settings::set('ap_ai_keep_alive', preg_match('/^(?:-1|0|\d{1,4}[smh])$/', $ka) === 1 ? $ka : '30m', 'string', 'agentpro', false);
                if (isset($_POST['ap_ai_api_key']) && $_POST['ap_ai_api_key'] !== '••••') {
                    Settings::set('ap_ai_api_key', trim((string) $_POST['ap_ai_api_key']), 'string', 'agentpro', false);
                }
                Settings::flush();
                ApAi::resetHealth();
                Logger::audit('agentpro.ai_settings', 'settings', 'agentpro', null, null, 'AI / WhatsApp desk settings saved');
                $flash = ['ok', 'Saved.'];
            } elseif ($act === 'recheck') {
                ApAi::resetHealth();
                $flash = ['ok', 'Checked again just now. / फेरि जाँचियो।'];
            } elseif ($act === 'pull') {
                $r = ApAi::pull((string) ($_POST['model'] ?? ''));
                ApAi::resetHealth();
                $flash = $r['ok'] ? ['ok', 'Download started on the VPS. It can take 5–20 minutes — refresh this page to see it under Installed. If it does not appear, run the "ollama pull" line below as root.'] : ['bad', (string) $r['error']];
            } elseif ($act === 'bench') {
                $bench = ApAi::benchmark((string) ($_POST['model'] ?? ''));
            } elseif ($act === 'dry') {
                require_once INCLUDE_PATH . '/agentpro-brain.php';
                $asMember = ($_POST['as'] ?? 'member') === 'member';
                $member = $asMember ? Database::fetch('SELECT id, full_name, role FROM admins WHERE id = :i', ['i' => (int) $admin['id']]) : null;
                $t0 = microtime(true);
                $dry = ['in' => (string) ($_POST['text'] ?? ''), 'out' => ApBrain::understand((string) ($_POST['text'] ?? ''), ['role' => $asMember ? 'staff' : 'customer'],
                    in_array($_POST['lang'] ?? '', ['ne', 'hi', 'en'], true) ? (string) $_POST['lang'] : 'ne', $member), 'ms' => (int) ((microtime(true) - $t0) * 1000)];
            } elseif ($act === 'hints') {
                $raw = str_replace("\r\n", "\n", (string) ($_POST['hints'] ?? ''));
                if (mb_strlen(trim($raw)) > ApFormRead::HINTS_MAX) {
                    throw new RuntimeException('The notes are too long — keep them under ' . ApFormRead::HINTS_MAX . ' characters.');
                }
                $new = ApFormRead::cleanHints($raw);
                Settings::set('ap_form_hints', $new, 'string', 'agentpro', false);
                Settings::flush();
                Logger::audit('agentpro.form_ai_hints', 'settings', 'ap_form_hints', null, ['chars' => mb_strlen($new)], 'Form AI notes saved by admin #' . $selfId);
                $flash = ['ok', 'Notes saved — the next read uses them. / सेभ भयो।'];
            } elseif ($act === 'hints_reset') {
                Database::delete('settings', 'skey = :k', ['k' => 'ap_form_hints']);
                Settings::flush();
                Logger::audit('agentpro.form_ai_hints', 'settings', 'ap_form_hints', null, null, 'Form AI notes reset to the built-in rules by admin #' . $selfId);
                $flash = ['ok', 'Back to the built-in notes. / सुरुकै निर्देशन।'];
            } elseif ($act === 'example_add') {
                $field = (string) ($_POST['field'] ?? '');
                if (!in_array($field, ApFormRead::KEYS, true)) {
                    throw new RuntimeException('Pick which box the example is for.');
                }
                if (trim((string) ($_POST['value'] ?? '')) === '') {
                    throw new RuntimeException('Type the correct value.');
                }
                $have = ApFormRead::training()['examples'];
                $have[] = ['field' => $field, 'written' => (string) ($_POST['written'] ?? ''), 'value' => (string) ($_POST['value'] ?? '')];
                $flash = ['ok', 'Example added — ' . $saveExamples($have, 'Form AI example added') . ' saved. / उदाहरण थपियो।'];
            } elseif ($act === 'corrections') {
                $in = json_decode((string) ($_POST['corrections'] ?? ''), true);
                if (!is_array($in) || $in === []) {
                    throw new RuntimeException('Type a correct value in at least one row first.');
                }
                $have = ApFormRead::training()['examples'];
                $added = 0;
                foreach (array_slice($in, 0, ApFormRead::EXAMPLES_MAX) as $row) {
                    $v = is_array($row) ? trim((string) ($row['value'] ?? '')) : '';
                    if ($v !== '' && in_array((string) ($row['field'] ?? ''), ApFormRead::KEYS, true) && $v !== trim((string) ($row['written'] ?? ''))) {
                        $have[] = ['field' => (string) $row['field'], 'written' => (string) ($row['written'] ?? ''), 'value' => $v];
                        $added++;
                    }
                }
                if ($added === 0) {
                    throw new RuntimeException('No correction differed from what the AI read — nothing to save.');
                }
                $n = $saveExamples($have, 'Form AI corrections saved (' . $added . ')');
                $flash = ['ok', $added . ' correction' . ($added === 1 ? '' : 's') . ' saved as examples — ' . $n . ' in all. The next read uses them. / सिकाइयो।'];
            } elseif ($act === 'example_delete') {
                $have = ApFormRead::training()['examples'];
                $i = (int) ($_POST['i'] ?? -1);
                if (!isset($have[$i])) {
                    throw new RuntimeException('That example is already gone.');
                }
                array_splice($have, $i, 1);
                $saveExamples($have, 'Form AI example removed');
                $flash = ['ok', 'Example removed. / हटाइयो।'];
            } elseif ($act === 'examples_clear') {
                if ((string) ($_POST['confirm'] ?? '') !== 'CLEAR') {
                    throw new RuntimeException('Type CLEAR to remove every example.');
                }
                $saveExamples([], 'Form AI examples cleared');
                $flash = ['ok', 'Every example removed. / सबै हटाइयो।'];
            }
        } catch (RuntimeException $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
    }
}

$health = ApAi::health(true);
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
$readers = ApFormRead::readers();                 // yes / no and names only
$recHave = in_array($rec['vision'], $installed, true) || in_array($rec['chat'], $installed, true);
$t = ApFormRead::training();
$hintsSaved = Settings::get('ap_form_hints', null) !== null;
$labels = ApFormRead::labels('agent');

admin_header('AI (VPS) & WhatsApp', 'ai-local.php');
echo '<link rel="stylesheet" href="/assets/agentpro/admin.css?v=' . (int) @filemtime(ASSET_PATH . '/agentpro/admin.css') . '">';
admin_page_head('Your own AI on this server — WhatsApp answers, ID photos and hand-filled forms. Gemini and Tesseract take over when it cannot.');
if ($flash !== null) {
    echo '<div class="flash ' . $e($flash[0]) . '">' . $e($flash[1]) . '</div>';
}
?>
<style>
.ai-ladder{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.ai-ladder .st{border:1px solid var(--line-2);border-radius:14px;padding:12px 14px;background:var(--card);min-height:96px}
.ai-ladder .st b{display:block;font-size:15px;margin:2px 0}
.ai-ladder .st small{display:block;color:var(--mut,#667);font-size:12.5px;line-height:1.4;word-break:break-word}
.ai-ladder .st.ok{border-color:#9bd8b4;background:color-mix(in srgb,#d7f4e3 45%,var(--card))}
.ai-ladder .st.bad{border-color:#efc2c2;background:color-mix(in srgb,#f7dcdc 45%,var(--card))}
.ai-ladder .st.warn{border-color:#f1d48a;background:color-mix(in srgb,#fff1cc 45%,var(--card))}
.ai-step{font-size:11px;letter-spacing:.12em;text-transform:uppercase;opacity:.75}
.ai-pre{background:var(--soft);padding:12px;border-radius:12px;overflow:auto;font-size:12.5px;line-height:1.5;white-space:pre;margin:8px 0 0}
.ai-btn48{min-height:48px;display:inline-flex;align-items:center;gap:6px}
.fa-f{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.fa-f .full{grid-column:1 / -1}
.fa-f label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:var(--mut,#667)}
.fa-f input,.fa-f select,.fa-f textarea,.fa-t input{min-height:48px;padding:10px 12px;border:1px solid var(--line-2);border-radius:12px;font-size:16px;color:var(--ink);background:var(--card);font-family:inherit}
.fa-f textarea{min-height:170px;line-height:1.45}
.fa-acts{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px}
.fa-pick{position:relative;cursor:pointer}
.fa-pick input[type=file]{position:absolute;width:1px;height:1px;opacity:0}
.fa-t{width:100%;border-collapse:collapse;font-size:14px}
.fa-t th,.fa-t td{padding:8px 10px;border-bottom:1px solid var(--line-2);text-align:left;vertical-align:middle}
.fa-t th{font-size:12px;opacity:.75}
.fa-t td.v{font-weight:600;word-break:break-word}
.fa-t input{width:100%;min-width:140px}
.fa-c{display:inline-block;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:800}
.fa-c.high{background:#d7f4e3;color:#0a6b3b}.fa-c.medium{background:#fff1cc;color:#7a5600}.fa-c.low{background:#f7dcdc;color:#8a1f1f}.fa-c.none{opacity:.6}
.fa-note{padding:10px 14px;border-radius:10px;background:#fff6e0;color:#6b4a00;font-size:13px;margin:10px 0}
.fa-thumbs{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.fa-thumbs img{width:90px;height:127px;object-fit:cover;object-position:top;border:1px solid var(--line-2);border-radius:8px;background:#fff}
@media(max-width:900px){.ai-ladder{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.ai-ladder,.fa-f{grid-template-columns:1fr}.fa-t thead{display:none}.fa-t tr{display:block;border-bottom:2px solid var(--line-2);padding:6px 0}.fa-t td{display:block;border:0;padding:4px 6px}.ai-pre{font-size:11.5px}}
</style>
<div class="ap-a">
<div class="kpis">
  <?= admin_kpi('Model server', $version !== '' ? 'Online' : 'Offline', $version !== '' ? 'v' . $version : ApAi::base(), 'sparkle', $health['ok'] ? 'green' : ($version !== '' ? 'orange' : 'red')) ?>
  <?= admin_kpi('WhatsApp AI', ApAi::enabled() ? 'On' : (Settings::getBool('ap_ai_on', false) ? 'On — waiting for the server' : 'Off'), $chat !== '' ? $chat : 'no model', 'bolt', ApAi::enabled() ? 'green' : 'orange') ?>
  <?= admin_kpi('Answers (24 h)', (string) $stats['calls'], $stats['failed'] . ' failed · avg ' . round($stats['avgMs'] / 1000, 1) . ' s', 'msg', $stats['failed'] > 0 ? 'orange' : 'blue') ?>
  <?= admin_kpi('Queue', (string) $queue, $seen > 0 ? 'worker ' . timeAgo(date('Y-m-d H:i:s', $seen)) : 'worker never ran — add the cron', 'clock', $seen > 0 && $seen > time() - 600 ? 'blue' : 'orange') ?>
</div>

<section class="panel"><h2><svg class="a-ic"><use href="#a-layers"/></svg> Who reads forms & ID photos · कसले पढ्छ</h2><div class="panel-body">
  <p class="ph-sub" style="margin-top:0">Tried in this order — the next one answers only when the one before it cannot. Keys are never shown here.</p>
  <div class="ai-ladder">
    <div class="st <?= $health['ok'] ? 'ok' : ($version !== '' ? 'warn' : 'bad') ?>"><span class="ai-step">1 · model server</span>
      <b><?= $health['ok'] ? '✔ Up' : ($version !== '' ? '⚠ Up, no model' : '✖ Not answering') ?></b>
      <small><?= $e(ApAi::base()) ?><?= $version !== '' ? ' · v' . $e($version) : '' ?><?= !$health['ok'] && $health['error'] !== '' ? ' — ' . $e(mb_substr($health['error'], 0, 140)) : '' ?></small></div>
    <div class="st <?= $readers['vps'] ? 'ok' : ($version !== '' ? 'warn' : 'bad') ?>"><span class="ai-step">1 · model installed</span>
      <b><?= $readers['vps'] ? '✔ ' . $e($readers['vps_model']) : ($recHave ? '✔ ' . $e($rec['chat']) : '✖ ' . $e($rec['vision']) . ' not installed') ?></b>
      <small><?= $readers['vps'] ? 'Reads pictures — used for ID photos and paper forms' . (Settings::getBool('ap_ai_docs', true) ? '' : ' (switched off below)') : $e($readers['vps_why'] !== '' ? $readers['vps_why'] : 'Install it with the commands below.') ?></small></div>
    <div class="st <?= $readers['gemini'] ? 'ok' : 'bad' ?>"><span class="ai-step">2 · Gemini (cloud)</span>
      <b><?= $readers['gemini'] ? '✔ Key saved' : '✖ No key' ?></b>
      <small><?= $readers['gemini'] ? 'Models: ' . $e(implode(' → ', $readers['gemini_models'])) . '. Pages leave the VPS only when the model above cannot read them.' : 'Optional — add gemini_api_key in Settings / One AI.' ?></small></div>
    <div class="st <?= $readers['tesseract'] ? 'ok' : 'bad' ?>"><span class="ai-step">3 · Tesseract (this VPS)</span>
      <b><?= $readers['tesseract'] ? '✔ Found' : '✖ Not installed' ?></b>
      <small><?= $readers['tesseract'] ? $e(implode(', ', $readers['tesseract_langs'])) . ' — numbers, e-mail and dates only, marked red' : '<code>apt install tesseract-ocr tesseract-ocr-hin tesseract-ocr-nep</code>' ?></small></div>
  </div>
  <form method="post" class="fa-acts"><?= Security::csrfField() ?><input type="hidden" name="action" value="recheck">
    <button class="btn ghost ai-btn48" type="submit">↻ Check again · फेरि जाँच</button>
    <span class="ph-sub">The status is cached for a minute so WhatsApp never waits on a dead server.</span></form>
</div></section>

<div class="tp-money">
  <section class="panel"><h2><svg class="a-ic"><use href="#a-cog"/></svg> This VPS</h2><div class="panel-body">
    <div class="ai-hw"><div><small>Memory</small><b><?= $e($hw['ramGb']) ?> GB</b><small><?= $e($hw['availGb']) ?> GB free</small></div>
      <div><small>CPU cores</small><b><?= (int) $hw['cpus'] ?></b><small><?= $hw['gpu'] ? 'GPU found' : 'no GPU — CPU only' ?></small></div>
      <div><small>Disk free</small><b><?= $e($hw['diskGb']) ?> GB</b></div></div>
    <p class="ph-sub" style="margin:10px 0 0">Other services hold <b><?= $e($rec['othersGb']) ?> GB</b><?= $rec['measured']
        ? ' (measured: ' . $e(implode(', ', array_map(static fn($k, $v) => $k . ' ' . $v . ' GB', array_keys($hw['services']), $hw['services']))) . ')'
        : ' (estimated: website + MySQL 2 GB, Valhalla route engine ~1 GB)' ?> → about <b><?= $e($rec['budgetGb']) ?> GB</b> is left for the model.<?=
        empty($rec['ramKnown']) ? ' This server\'s memory could not be read, so the plan is for an 8 GB / 2 vCPU VPS.' : '' ?></p>
    <div class="ai-rec" style="margin-top:14px">
      <div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;opacity:.8">Best for this VPS</div>
      <div style="font-size:20px;margin:4px 0"><b><?= $e($rec['chat']) ?></b><?= $rec['vision'] !== $rec['chat'] ? ' + <b>' . $e($rec['vision']) . '</b> for documents' : '' ?></div>
      <div style="opacity:.9;font-size:13.5px"><?= $e($rec['why']) ?></div>
      <?php if ($rec['warn'] !== ''): ?><div style="margin-top:8px;font-size:13px">⚠ <?= $e($rec['warn']) ?></div><?php endif; ?>
      <?php if ($super && $version !== ''): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
        <?php foreach (array_unique(array_merge([$rec['chat'], $rec['vision']], $rec['alternatives'])) as $mdl): $have = in_array($mdl, $installed, true); ?>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="pull"><input type="hidden" name="model" value="<?= $e($mdl) ?>">
            <button class="btn <?= $have ? 'ghost' : 'warn' ?> sm ai-btn48" <?= $have ? 'disabled' : '' ?>><?= $have ? '✓ ' : '⬇ ' ?><?= $e($mdl) ?></button></form>
        <?php endforeach; ?>
      </div><?php endif; ?>
    </div>
  </div></section>

  <section class="panel"><h2><svg class="a-ic"><use href="#a-layers"/></svg> Installed models</h2><div class="panel-body">
    <?php if ($version === ''): ?>
      <div class="flash bad">The model server at <b><?= $e(ApAi::base()) ?></b> is not answering. WhatsApp uses the desk menu and the readers use Gemini / Tesseract until it does.</div>
    <?php else: ?>
      <div class="ai-models">
        <?php if ($models === []) { echo admin_empty('No model yet', 'Run the "ollama pull" line below as root, or use the download button.', '📦'); } ?>
        <?php foreach ($models as $mdl): $isRun = in_array($mdl['name'], array_column($running, 'name'), true); ?>
          <div class="ai-model<?= $mdl['name'] === $chat ? ' on' : '' ?>"><span style="flex:1;min-width:0"><b><?= $e($mdl['name']) ?></b>
            <small class="ph-sub" style="display:block;margin:2px 0 0"><?= $e(trim($mdl['params'] . ' · ' . $mdl['quant'] . ' · ' . $mdl['gb'] . ' GB', ' ·')) ?></small></span>
            <span class="tags"><?= $mdl['name'] === $chat ? '<span class="tp-chip ok">chat</span>' : '' ?><?= $mdl['name'] === $vision ? '<span class="tp-chip ok">documents</span>' : '' ?>
              <?= $mdl['vision'] ? '<span class="tp-chip blue">vision</span>' : '' ?><?= $mdl['tools'] ? '<span class="tp-chip blue">tools</span>' : '' ?><?= $isRun ? '<span class="tp-chip warn">loaded</span>' : '' ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="ph-sub" style="margin-top:12px"><b>Install / update on the VPS</b> — this page cannot install anything itself. Copy and run as <b>root</b> (SSH):</p>
    <pre class="ai-pre" id="aiInstall"><?= $e(ApAi::installScript($rec)) ?></pre>
    <div class="fa-acts"><button class="btn ghost ai-btn48" type="button" data-copy="aiInstall">📋 Copy commands</button>
      <span class="ph-sub">MemoryMax keeps the model from pushing MySQL or Valhalla out of RAM; Nice=10 keeps the website first on the CPU.</span></div>
  </div></section>
</div>

<?php if ($canTrain): ?>
<section class="panel" id="formai"><h2><svg class="a-ic"><use href="#a-eye"/></svg> Form AI training · फारम पढ्ने AI सिकाउनुहोस्</h2><div class="panel-body">
  <p class="ph-sub" style="margin-top:0">Agents fill the printed 2-page form by hand and send photos or a PDF; the AI fills the empty online boxes (amber = check, red = surely check). Teach it here with notes and corrected examples. <?= count($t['examples']) ?> / <?= ApFormRead::EXAMPLES_MAX ?> examples · <?= mb_strlen($t['hints']) ?> / <?= ApFormRead::HINTS_MAX ?> characters of notes<?= $hintsSaved ? '' : ' (built-in)' ?>.</p>

  <h3 style="margin:14px 0 6px;font-size:15px">🧪 Try a filled sample · नमुना पढाउनुहोस्</h3>
  <div class="fa-f">
    <label>Form · फारम<select id="faKind"><option value="agent">👤 Agent form</option><option value="staff">🧑‍✈️ Staff form</option></select></label>
    <label>Reader · कसले पढ्ने<select id="faReader"><option value="auto">Auto (the ladder)</option><option value="vps">Only the VPS model</option><option value="gemini">Only Gemini</option><option value="tesseract">Only Tesseract</option></select></label>
    <label>File · फाइल<span class="btn fa-pick ai-btn48" role="button" tabindex="0" id="faPick">📄 Choose photos or a PDF<input type="file" id="faFile" accept="image/jpeg,image/png,image/webp,application/pdf" multiple></span></label>
  </div>
  <div class="fa-thumbs" id="faThumbs"></div>
  <div class="fa-acts"><button class="btn ok ai-btn48" type="button" id="faRead" disabled>🤖 Read with AI · पढ्नुहोस्</button><span class="ph-sub" id="faMsg" aria-live="polite"></span></div>
  <div id="faOut" hidden>
    <div class="fa-note" id="faNote" hidden></div>
    <div style="overflow:auto"><table class="fa-t"><thead><tr><th>Box · बाकस</th><th>AI read · AI ले पढ्यो</th><th>Sure?</th><th>Correct value · सही मान</th></tr></thead><tbody id="faRows"></tbody></table></div>
    <form method="post" id="faSave" class="fa-acts" action="#formai"><?= Security::csrfField() ?><input type="hidden" name="action" value="corrections"><input type="hidden" name="corrections" id="faCorr">
      <button class="btn ok ai-btn48" type="submit">💾 Save corrections as examples · सिकाउनुहोस्</button>
      <span class="ph-sub">Only rows where you typed a different value are saved. The sample itself is never stored.</span></form>
  </div>

  <h3 style="margin:20px 0 6px;font-size:15px">📝 Notes for the AI · AI लाई निर्देशन</h3>
  <form method="post" action="#formai"><?= Security::csrfField() ?><input type="hidden" name="action" value="hints">
    <div class="fa-f"><label class="full">How our forms are filled (Nepali or English) · हाम्रो फारम कसरी भरिन्छ
      <textarea name="hints" maxlength="<?= ApFormRead::HINTS_MAX ?>"><?= $e($t['hints']) ?></textarea></label></div>
    <div class="fa-acts"><button class="btn ok ai-btn48" type="submit">Save notes · सेभ</button></div></form>
  <?php if ($hintsSaved): ?>
  <form method="post" class="fa-acts" action="#formai" onsubmit="return confirm('Replace your notes with the built-in ones?')"><?= Security::csrfField() ?><input type="hidden" name="action" value="hints_reset">
    <button class="btn ghost ai-btn48" type="submit">↺ Back to the built-in notes</button></form>
  <?php endif; ?>

  <h3 style="margin:20px 0 6px;font-size:15px">📚 Examples the AI learns from · उदाहरण (<?= count($t['examples']) ?>)</h3>
  <?php if ($t['examples'] === []): ?>
    <p class="ph-sub">No examples yet. Read a sample above and save your corrections, or add one here.</p>
  <?php else: ?>
    <div style="overflow:auto"><table class="fa-t"><thead><tr><th>Box</th><th>Read as / written</th><th>Correct</th><th></th></tr></thead><tbody>
    <?php foreach ($t['examples'] as $i => $ex): ?>
      <tr><td><?= $e($labels[$ex['field']] ?? $ex['field']) ?> <span class="ph-sub ap-mono" style="font-size:11px"><?= $e($ex['field']) ?></span></td>
        <td class="v"><?= $ex['written'] !== '' ? $e($ex['written']) : '<span class="ph-sub">(blank)</span>' ?></td><td class="v"><?= $e($ex['value']) ?></td>
        <td><form method="post" style="margin:0" action="#formai"><?= Security::csrfField() ?><input type="hidden" name="action" value="example_delete"><input type="hidden" name="i" value="<?= (int) $i ?>">
          <button class="btn ghost ai-btn48" type="submit" aria-label="Remove this example">✕</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
  <form method="post" style="margin-top:12px" action="#formai"><?= Security::csrfField() ?><input type="hidden" name="action" value="example_add">
    <div class="fa-f">
      <label>Box · बाकस<select name="field" required><option value="">— choose —</option>
        <?php foreach ($labels as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></label>
      <label>Read as / written · जस्तो लेखिएको<input name="written" maxlength="<?= ApFormRead::EXAMPLE_LEN ?>" placeholder="e.g. Gujrati or २०४८/५/१२"></label>
      <label>Correct value · सही मान<input name="value" maxlength="<?= ApFormRead::EXAMPLE_LEN ?>" required placeholder="e.g. Gujarati or 1991-08-28"></label>
    </div>
    <div class="fa-acts"><button class="btn ai-btn48" type="submit">＋ Add example</button></div></form>
  <?php if ($t['examples'] !== []): ?>
  <form method="post" class="fa-acts" action="#formai" onsubmit="return confirm('Remove every example?')"><?= Security::csrfField() ?><input type="hidden" name="action" value="examples_clear">
    <input name="confirm" placeholder="Type CLEAR" autocomplete="off" style="min-height:48px;padding:10px 12px;border:1px solid var(--line-2);border-radius:12px;font-size:16px;background:var(--card);color:var(--ink)">
    <button class="btn ghost ai-btn48" type="submit">Remove all examples</button></form>
  <?php endif; ?>

  <details style="margin-top:16px"><summary class="ph-sub" style="cursor:pointer;min-height:48px;display:flex;align-items:center">🔍 Show the full instruction the AI gets (page 1 of the agent form, with your notes and examples)</summary>
    <pre class="ai-pre" style="white-space:pre-wrap;max-height:420px"><?= $e(ApFormRead::prompt('agent', 1, 2, $t['hints'], $t['examples'])) ?></pre></details>
</div></section>
<?php endif; ?>

<section class="panel"><h2><svg class="a-ic"><use href="#a-trophy"/></svg> Benchmark on this VPS — Nepali, Hindi, English</h2><div class="panel-body">
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><?= Security::csrfField() ?><input type="hidden" name="action" value="bench">
    <select name="model" style="min-height:48px;border-radius:10px;border:1px solid var(--line-2);background:var(--card);color:var(--ink);padding:0 10px">
      <?php foreach ($models as $mdl): ?><option<?= $mdl['name'] === $chat ? ' selected' : '' ?>><?= $e($mdl['name']) ?></option><?php endforeach; ?></select>
    <button class="btn sm ai-btn48" <?= $models === [] ? 'disabled' : '' ?> onclick="this.textContent='Running… (1–5 min)'">Run 5 tests</button>
    <span class="ph-sub">Each test is a real WhatsApp-style message; the model must read seats, date, pickup and phone exactly.</span></form>
  <?php if ($bench !== null): ?>
    <p style="font-size:16px;margin:14px 0 6px"><b><?= $e($bench['model']) ?>: <?= (int) $bench['score'] ?> / <?= (int) $bench['of'] ?></b> correct in <?= $e($bench['seconds']) ?> s
      (<?= $e(round($bench['seconds'] / max(1, $bench['of']), 1)) ?> s per message)</p>
    <table class="ai-bench" style="width:100%;border-collapse:collapse"><tr><th>Lang</th><th>Message</th><th>Result</th><th>Time</th></tr>
      <?php foreach ($bench['tasks'] as $tk): ?><tr><td><?= $e($tk['lang']) ?></td><td><?= $e($tk['q']) ?></td>
        <td><?= $tk['ok'] ? '✅' : '❌ ' . $e(implode(', ', $tk['missed']) . ($tk['error'] !== '' ? ' — ' . $tk['error'] : '')) ?></td><td><?= $e(round($tk['ms'] / 1000, 1)) ?> s</td></tr><?php endforeach; ?></table>
  <?php endif; ?>
</div></section>

<div class="tp-money">
  <section class="panel"><h2><svg class="a-ic"><use href="#a-eye"/></svg> What would the AI understand? (dry run)</h2><div class="panel-body">
    <form method="post" class="mp-form"><?= Security::csrfField() ?><input type="hidden" name="action" value="dry">
      <label class="full">Message<input name="text" value="<?= $e($dry['in'] ?? 'bholi 2 jana surat bata jane, Ram Thapa 9812345678') ?>"></label>
      <label>As<select name="as"><option value="member">An agent / staff</option><option value="customer">A passenger</option></select></label>
      <label>Reply language<select name="lang"><option value="ne">नेपाली</option><option value="hi">हिंदी</option><option value="en">English</option></select></label>
      <div class="full"><button class="btn sm ai-btn48" <?= !$health['ok'] ? 'disabled' : '' ?>>Ask the model</button> <span class="ph-sub">Nothing is booked, sent or saved.</span></div></form>
    <?php if ($dry !== null): ?><pre class="ai-pre"><?= $e(json_encode($dry['out'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: 'null — the model did not answer') ?>

<?= (int) $dry['ms'] ?> ms</pre><?php endif; ?>
  </div></section>

  <section class="panel"><h2><svg class="a-ic"><use href="#a-cog"/></svg> Switches</h2><div class="panel-body">
    <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="save">
      <?php foreach ($switches as $k => [$label, $help]): ?>
        <label class="ai-switch"><span><b><?= $e($label) ?></b><small><?= $e($help) ?></small></span>
          <input type="checkbox" name="<?= $e($k) ?>" value="1" <?= Settings::getBool($k, $k !== 'ap_ai_on') ? 'checked' : '' ?> <?= $super ? '' : 'disabled' ?> style="width:22px;height:22px"></label>
      <?php endforeach; ?>
      <div class="mp-form" style="margin-top:12px">
        <label>Model server<input name="ap_ai_base" value="<?= $e(ApAi::base()) ?>" <?= $super ? '' : 'disabled' ?>></label>
        <label>Seconds per WhatsApp answer (max)<input name="ap_ai_timeout" type="number" min="20" max="600" value="<?= (int) Settings::getInt('ap_ai_timeout', 150) ?>" <?= $super ? '' : 'disabled' ?>></label>
        <label>Seconds per document / page (max)<input name="ap_ai_doc_timeout" type="number" min="20" max="600" value="<?= ApAi::docTimeout() ?>" <?= $super ? '' : 'disabled' ?>></label>
        <label>Seconds for a whole paper form on the VPS<input name="ap_form_vps_budget" type="number" min="30" max="900" value="<?= max(30, min(900, Settings::getInt('ap_form_vps_budget', 150))) ?>" <?= $super ? '' : 'disabled' ?>></label>
        <label>WhatsApp waits at most (s) while the model is busy<input name="ap_ai_wa_wait" type="number" min="3" max="60" value="<?= ApAi::waWait() ?>" <?= $super ? '' : 'disabled' ?>></label>
        <label>Keep the model in RAM after a call (e.g. 30m, 0)<input name="ap_ai_keep_alive" value="<?= $e(ApAi::keepAlive()) ?>" <?= $super ? '' : 'disabled' ?>></label>
        <label>Chat model (empty = best installed)<input name="ap_ai_model" list="mdl" value="<?= $e(Settings::getString('ap_ai_model', '')) ?>" <?= $super ? '' : 'disabled' ?>></label>
        <label>Document model (empty = auto)<input name="ap_ai_vision_model" list="mdl" value="<?= $e(Settings::getString('ap_ai_vision_model', '')) ?>" <?= $super ? '' : 'disabled' ?>></label>
        <label class="full">API key (only for a non-Ollama server)<input name="ap_ai_api_key" value="<?= Settings::getString('ap_ai_api_key', '') !== '' ? '••••' : '' ?>" autocomplete="off" <?= $super ? '' : 'disabled' ?>></label>
        <datalist id="mdl"><?php foreach ($installed as $n): ?><option value="<?= $e($n) ?>"><?php endforeach; ?></datalist>
      </div>
      <?php if ($super): ?><button class="btn ai-btn48" type="submit" style="margin-top:10px">Save</button><?php endif; ?>
    </form>
    <p class="ph-sub" style="margin-top:14px">A document read gives way the moment a WhatsApp answer needs the model, and is read again ~20 s later.</p>
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
  <p class="ph-sub">Anything else from an agent or staff member is read by the AI above (when it is on and the server answers) — in Nepali, Hindi or English.</p>
</div></section>
</div>

<script>
(function () {
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var pre = document.getElementById(b.getAttribute('data-copy')); if (!pre) return;
      var txt = pre.textContent, done = function () { var t = b.textContent; b.textContent = '✓ Copied'; setTimeout(function () { b.textContent = t; }, 1600); };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(txt).then(done, function () {}); }
      else { var r = document.createRange(); r.selectNodeContents(pre); var s = getSelection(); s.removeAllRanges(); s.addRange(r); try { document.execCommand('copy'); done(); } catch (e) {} }
    });
  });

  var file = document.getElementById('faFile');
  if (!file) return;
  var MAX_PAGES = <?= (int) ApFormRead::MAX_PAGES ?>, CSRF_NAME = <?= json_encode(CSRF_TOKEN_NAME) ?>, CSRF = <?= json_encode(Security::csrfToken()) ?>;
  var go = document.getElementById('faRead'), msg = document.getElementById('faMsg'), thumbs = document.getElementById('faThumbs');
  var out = document.getElementById('faOut'), rows = document.getElementById('faRows'), note = document.getElementById('faNote');
  var pick = document.getElementById('faPick'), pages = [], pdfLib = null;
  pick.addEventListener('click', function (ev) { if (ev.target !== file) file.click(); });
  pick.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); file.click(); } });
  function say(t) { msg.textContent = t; }
  function canvasJpeg(c) { var q = 0.86, d = c.toDataURL('image/jpeg', q); while (d.length > 900000 && q > 0.5) { q -= 0.08; d = c.toDataURL('image/jpeg', q); } return d; }
  /* a photo: upright by the browser, at most 1600 px, JPEG */
  function shrink(f) {
    return new Promise(function (ok, bad) {
      var url = URL.createObjectURL(f), im = new Image();
      im.onload = function () {
        var k = Math.min(1, 1600 / Math.max(im.naturalWidth, im.naturalHeight)), c = document.createElement('canvas');
        c.width = Math.round(im.naturalWidth * k); c.height = Math.round(im.naturalHeight * k);
        c.getContext('2d').drawImage(im, 0, 0, c.width, c.height); URL.revokeObjectURL(url); ok(canvasJpeg(c));
      };
      im.onerror = function () { URL.revokeObjectURL(url); bad(); }; im.src = url;
    });
  }
  /* a PDF: each page (up to 4) drawn with the site's own pdf.js copy, 1600 px wide */
  function pdfPages(f) {
    if (!pdfLib) pdfLib = import('/assets/vendor/pdfjs/pdf.min.js').then(function (lib) { lib.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.js'; return lib; });
    return Promise.all([pdfLib, f.arrayBuffer()]).then(function (a) {
      return a[0].getDocument({ data: new Uint8Array(a[1]), isEvalSupported: false, standardFontDataUrl: '/assets/vendor/pdfjs/standard_fonts/', wasmUrl: '/assets/vendor/pdfjs/wasm/' }).promise;
    }).then(function (pdf) {
      var n = Math.min(MAX_PAGES, pdf.numPages), list = [], chain = Promise.resolve();
      for (var i = 1; i <= n; i++) {
        (function (p) {
          chain = chain.then(function () { return pdf.getPage(p); }).then(function (page) {
            var v1 = page.getViewport({ scale: 1 }), view = page.getViewport({ scale: 1600 / v1.width }), c = document.createElement('canvas');
            c.width = Math.round(view.width); c.height = Math.round(view.height);
            var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
            return page.render({ canvasContext: x, viewport: view }).promise.then(function () { list.push(canvasJpeg(c)); });
          });
        })(i);
      }
      return chain.then(function () { return pdf.destroy(); }).then(function () { return list; });
    });
  }
  file.addEventListener('change', function () {
    var list = Array.prototype.slice.call(file.files || []);
    pages = []; thumbs.textContent = ''; go.disabled = true; out.hidden = true;
    if (!list.length) { say(''); return; }
    say('Preparing the pages… · तयार गर्दै…');
    var jobs = list.slice(0, MAX_PAGES).map(function (f) { return (f.type === 'application/pdf' || /\.pdf$/i.test(f.name)) ? pdfPages(f) : shrink(f).then(function (d) { return [d]; }); });
    Promise.all(jobs).then(function (lists) {
      pages = [].concat.apply([], lists).slice(0, MAX_PAGES);
      pages.forEach(function (d, i) { var im = document.createElement('img'); im.src = d; im.alt = 'Page ' + (i + 1); thumbs.appendChild(im); });
      go.disabled = !pages.length; say(pages.length + ' page(s) ready. · तयार।');
    }, function () { say('That file could not be opened. · फाइल खुलेन।'); });
  });
  function cell(tr, text, cls) { var td = document.createElement('td'); if (cls) td.className = cls; td.textContent = text; tr.appendChild(td); return td; }
  go.addEventListener('click', function () {
    if (!pages.length) return;
    go.disabled = true; out.hidden = true; say('The AI is reading… (up to a few minutes on the VPS) · पढ्दैछ…');
    var fd = new FormData();
    fd.append(CSRF_NAME, CSRF); fd.append('action', 'formtry');
    fd.append('kind', document.getElementById('faKind').value); fd.append('reader', document.getElementById('faReader').value);
    pages.forEach(function (d) { fd.append('pages[]', d); });
    fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      go.disabled = false;
      if (!j || j.ok === false) { say((j && j.error) || 'The read failed.'); return; }
      var st = { read: '✔ Read', partial: '⚠ Seen as a form, but no box could be read', notform: '✖ This does not look like our form or an ID card', unavailable: '✖ No reader could answer' };
      var who = { vps: 'the VPS model', gemini: 'Gemini', tesseract: 'Tesseract (our server)' };
      say((st[j.status] || j.status) + (j.reader ? ' · ' + (who[j.reader] || j.reader) : '') + (j.tried && j.tried.length ? ' · tried: ' + j.tried.join(' → ') : ''));
      rows.textContent = '';
      Object.keys(j.labels || {}).forEach(function (id) {
        var v = (j.fields || {})[id] || '', c = (j.confidence || {})[id] || '';
        var tr = document.createElement('tr'), td = cell(tr, j.labels[id] + ' '), s = document.createElement('span');
        s.className = 'ph-sub ap-mono'; s.style.fontSize = '11px'; s.textContent = id; td.appendChild(s);
        cell(tr, v || '—', 'v');
        var p = document.createElement('span'); p.className = 'fa-c ' + (c || 'none'); p.textContent = c || '—';
        var tc = document.createElement('td'); tc.appendChild(p); tr.appendChild(tc);
        var ti = document.createElement('td'), inp = document.createElement('input');
        inp.dataset.field = id; inp.dataset.ai = v; inp.maxLength = <?= (int) ApFormRead::EXAMPLE_LEN ?>; inp.placeholder = v ? 'only if wrong' : 'if written on the paper';
        inp.setAttribute('aria-label', 'Correct value for ' + j.labels[id]);
        ti.appendChild(inp); tr.appendChild(ti); rows.appendChild(tr);
      });
      note.hidden = !j.note; note.textContent = j.note || '';
      out.hidden = false;
    }).catch(function () { go.disabled = false; say('Network error — try again. · फेरि प्रयास गर्नुहोस्।'); });
  });
  document.getElementById('faSave').addEventListener('submit', function (ev) {
    var list = [];
    Array.prototype.forEach.call(rows.querySelectorAll('input[data-field]'), function (i) {
      var v = i.value.trim(); if (v !== '' && v !== i.dataset.ai) list.push({ field: i.dataset.field, written: i.dataset.ai, value: v });
    });
    if (!list.length) { ev.preventDefault(); say('Type a correct value in at least one row first. · कम्तीमा एउटा सही मान लेख्नुहोस्।'); return; }
    document.getElementById('faCorr').value = JSON.stringify(list);
  });
})();
</script>
<?php admin_footer();
