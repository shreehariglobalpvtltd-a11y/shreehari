<?php
/**
 * admin/wa-templates.php — 📝 WhatsApp template library (8 Oct 2026).
 *
 * Owner master prompt 2.0, Phase 3.3 / 3.4: 30+ editable drafts across
 * customer, marketing, agent and staff messages; variables filled only
 * from records; an AI wording helper that may not invent prices, dates or
 * discounts; the numbered form for Meta approval.
 *
 * customers.view to read and preview; superadmin / marketing.send to edit,
 * use the AI helper or record a Meta status. Nothing is sent from here —
 * Campaigns and WhatsApp compose do the sending with their own consent,
 * opt-out and rate limits.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/watpllib.php';

$admin = admin_boot('customers.view');
if (Auth::isCounterAgent()) {
    admin_header('WhatsApp Templates', 'wa-templates');
    echo '<div class="flash bad">WhatsApp Templates is an office page.</div>';
    admin_footer();
    exit;
}
$me      = (int) $admin['id'];
$mayEdit = Auth::isSuperadmin() || Auth::can('marketing.send');
$e       = static fn ($v): string => Security::e((string) $v);

/* ---- JSON actions (preview / AI) ---------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!Security::verifyCsrf()) {
        echo json_encode(['ok' => false, 'error' => 'Session expired — reload the page.']);
        exit;
    }
    $act = (string) $_POST['ajax'];
    try {
        if ($act === 'preview') {
            $body = (string) ($_POST['body'] ?? '');
            $bad  = WaTplLib::unknownVars($body);
            $res  = WaTplLib::resolve($body, [
                'pnr'         => trim((string) ($_POST['pnr'] ?? '')),
                'agent_id'    => (int) ($_POST['agent_id'] ?? 0),
                'notice_text' => (string) ($_POST['notice'] ?? ''),
            ]);
            $meta = WaTplLib::forMeta($body);
            echo json_encode(['ok' => true, 'text' => $res['text'], 'missing' => $res['missing'], 'unknown' => $bad,
                              'meta' => $meta['body'], 'map' => $meta['map'], 'chars' => mb_strlen($res['text'])], JSON_UNESCAPED_UNICODE);
        } elseif ($act === 'ai') {
            if (!$mayEdit) {
                echo json_encode(['ok' => false, 'error' => 'Only the owner or a manager (marketing.send) can use the AI helper.']);
                exit;
            }
            $r = WaTplLib::aiRewrite((string) ($_POST['tkey'] ?? ''), (string) ($_POST['lang'] ?? 'en'), (string) ($_POST['length'] ?? 'medium'));
            echo json_encode(isset($r['error']) ? ['ok' => false, 'error' => $r['error']] : ['ok' => true, 'text' => $r['text'], 'provider' => $r['provider']], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
        }
    } catch (Throwable $ex) {
        Logger::error('wa-templates ' . $act . ': ' . $ex->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Could not do that — see the log.']);
    }
    exit;
}

/* ---- Save / reset -------------------------------------------------- */
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$mayEdit) {
        $flash = ['bad', 'Only the owner or a manager (marketing.send) can change templates.'];
    } else {
        $k = (string) ($_POST['tkey'] ?? '');
        $l = (string) ($_POST['lang'] ?? 'en');
        try {
            /* 19 Nov 2026 (WA-2): the Meta template registry (includes/watplmeta.php).
               Reading statuses is a GET; asking Meta for the missing templates
               is superadmin-only (free, but it is the owner's WABA). */
            $act = (string) ($_POST['action'] ?? '');
            if ($act === 'meta_sync' || $act === 'meta_submit') {
                require_once INCLUDE_PATH . '/watplmeta.php';
                if ($act === 'meta_submit' && !Auth::isSuperadmin()) {
                    $flash = ['bad', 'Only the owner (superadmin) can ask Meta for new templates.'];
                } elseif (!WaTplMeta::configured()) {
                    $flash = ['bad', 'The Meta token / WABA id is not configured on this server.'];
                } elseif ($act === 'meta_sync') {
                    $s = WaTplMeta::sync();
                    $flash = $s['ok'] ? ['ok', 'Meta statuses read: ' . implode(', ', array_map(static fn($n, $st) => $n . ' ' . $st, array_keys($s['statuses']), $s['statuses'])) . '.']
                                      : ['bad', 'Could not read Meta: ' . $s['error']];
                } else {
                    $s = WaTplMeta::submitMissing(false);
                    if ($s['ok']) {
                        $done = array_values(array_filter($s['rows'], static fn($r) => in_array($r['action'], ['submitted', 'failed', 'invalid', 'skipped'], true)));
                        $flash = ['ok', 'Asked Meta for ' . (int) $s['submitted'] . ' template(s).' . ($done !== []
                            ? ' ' . implode(' · ', array_map(static fn($r) => $r['name'] . ': ' . $r['action'] . ($r['action'] !== 'submitted' ? ' (' . $r['detail'] . ')' : ''), $done)) : ' Nothing was missing.')];
                        Logger::audit('whatsapp.templates_submit', 'setting', 'wa_tpl_meta', null, ['submitted' => (int) $s['submitted']], 'Submit missing to Meta (admin/wa-templates.php)');
                    } else {
                        $flash = ['bad', 'Meta refused the request: ' . $s['error']];
                    }
                }
                header('Location: wa-templates.php?f=' . rawurlencode($flash[0] . '|' . mb_substr($flash[1], 0, 900)) . '#meta-registry');
                exit;
            }
            if (($_POST['action'] ?? '') === 'sync') {
                $s = WaTplLib::syncMetaStatus();
                $flash = $s['error'] !== '' ? ['bad', 'Could not read Meta status: ' . $s['error']]
                    : ['ok', 'Meta status read for ' . $s['checked'] . ' template(s), ' . $s['changed'] . ' changed. Approved: ' . ($s['approved'] ? implode(', ', $s['approved']) : 'none yet') . '.'];
                header('Location: wa-templates.php?f=' . rawurlencode($flash[0] . '|' . $flash[1]));
                exit;
            }
            if (($_POST['action'] ?? '') === 'reset') {
                WaTplLib::reset($k, $l);
                $flash = ['ok', 'Back to the built-in draft.'];
            } else {
                WaTplLib::save($k, $l, (string) ($_POST['body'] ?? ''), (string) ($_POST['meta_name'] ?? ''), (string) ($_POST['meta_status'] ?? 'draft'), $me);
                $flash = ['ok', 'Template saved.'];
            }
        } catch (InvalidArgumentException $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
        header('Location: wa-templates.php?t=' . rawurlencode($k) . '&l=' . rawurlencode($l) . '&f=' . rawurlencode($flash[0] . '|' . $flash[1]) . '#tpl-' . rawurlencode($k));
        exit;
    }
}
if ($flash === null && isset($_GET['f'])) {
    $p = explode('|', (string) $_GET['f'], 2);
    if (count($p) === 2 && in_array($p[0], ['ok', 'bad'], true)) { $flash = [$p[0], $p[1]]; }
}

$cat   = isset(WaTplLib::CATS[$_GET['c'] ?? '']) ? (string) $_GET['c'] : '';
$openK = (string) ($_GET['t'] ?? '');
$openL = isset(WaTplLib::LANGS[$_GET['l'] ?? '']) ? (string) $_GET['l'] : 'en';
$aiOn  = false;
try { require_once INCLUDE_PATH . '/aibrain.php'; $aiOn = AiBrain::configured(); } catch (Throwable $ex) { $aiOn = false; }

$counts = ['approved' => 0, 'submitted' => 0, 'edited' => 0];
foreach (array_keys(WaTplLib::BUILTIN) as $k) {
    foreach (array_keys(WaTplLib::LANGS) as $l) {
        $st = WaTplLib::state($k, $l);
        if ($st['meta_status'] === 'approved') { $counts['approved']++; }
        if ($st['meta_status'] === 'submitted') { $counts['submitted']++; }
        if ($st['edited']) { $counts['edited']++; }
    }
}
$live = WaTplLib::liveValues();
$csrf = Security::e(Security::csrfToken());
$ck   = CSRF_TOKEN_NAME;

admin_header('WhatsApp Templates', 'wa-templates');
if ($flash !== null) { echo '<div class="flash ' . $e($flash[0]) . '">' . $e($flash[1]) . '</div>'; }
?>
<div class="panel wt-head">
  <h2>📝 WhatsApp template library</h2>
  <p class="muted"><?= count(WaTplLib::BUILTIN) ?> drafts in English, नेपाली and हिन्दी (ગુજરાતી by the AI helper). Every <code>{{variable}}</code> is filled from records only — a booking by PNR, an agent's ledger, the live fares and the running offer. A value the records do not have stays shown as <code>{{missing}}</code>; nothing is guessed. Business-initiated messages need Meta approval: copy the numbered form, submit it in AiSensy, then record the status here. Sending happens in <a href="campaigns.php">Campaigns</a> and <a href="wa-compose.php">WhatsApp compose</a> (opt-out and limits apply there).</p>
  <div class="wt-kpis">
    <span class="wt-kpi"><b><?= count(WaTplLib::BUILTIN) ?></b> drafts</span>
    <span class="wt-kpi"><b><?= (int) $counts['edited'] ?></b> edited</span>
    <span class="wt-kpi"><b><?= (int) $counts['submitted'] ?></b> sent to Meta</span>
    <span class="wt-kpi ok"><b><?= (int) $counts['approved'] ?></b> approved</span>
    <span class="wt-kpi <?= $aiOn ? 'ok' : '' ?>">AI helper: <b><?= $aiOn ? 'on' : 'no key' ?></b></span>
  </div>
  <?php if ($mayEdit): ?>
  <form method="post" style="margin:6px 0 10px"><input type="hidden" name="<?= $e($ck) ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="sync">
    <button class="btn ghost" type="submit">🔄 Refresh approval status from Meta (AiSensy)</button></form>
  <?php endif; ?>
  <p class="muted wt-live">Live facts now: <?php foreach (['fare_to_nepal', 'fare_to_india', 'discount_code', 'offer_until', 'support_phone'] as $lv): ?><span class="wt-chip"><?= $e($lv) ?> = <b><?= $e($live[$lv] ?? '— none') ?></b></span><?php endforeach; ?></p>
  <nav class="sg-chips">
    <a class="sg-chip <?= $cat === '' ? 'on' : '' ?>" href="wa-templates.php">All</a>
    <?php foreach (WaTplLib::CATS as $ck2 => $cl): ?><a class="sg-chip <?= $cat === $ck2 ? 'on' : '' ?>" href="wa-templates.php?c=<?= $e($ck2) ?>"><?= $e($cl) ?></a><?php endforeach; ?>
  </nav>
</div>

<?php
/* 19 Nov 2026 (WA-2): the templates the SITE sends on the Meta number
   (includes/watplmeta.php) - one row each, with Meta's last known status. */
$regRows = [];
$regSync = 0;
$regConf = false;
try {
    require_once INCLUDE_PATH . '/watplmeta.php';
    $regRows = WaTplMeta::overview();
    $regSync = WaTplMeta::lastSync();
    $regConf = WaTplMeta::configured();
} catch (Throwable $ex) {
    $regRows = [];
}
$regDefs = class_exists('WaTplMeta') ? WaTplMeta::defs() : [];
?>
<div class="panel" id="meta-registry">
  <h2>📨 Meta templates on the bot number</h2>
  <p class="muted">The small UTILITY templates this site uses outside the 24-hour window (language English). A template is used only once Meta marks it <b>APPROVED</b>; until then the message waits for the customer's next WhatsApp (free) as before. Last read from Meta: <b><?= $regSync > 0 ? $e(date('j M Y, H:i', $regSync)) : 'never' ?></b>.</p>
  <?php if ($regRows !== []): ?>
  <div class="table-wrap"><table class="table wt-reg">
    <thead><tr><th>Name</th><th>Used for</th><th>Meta status</th><th>In use</th></tr></thead>
    <tbody>
    <?php foreach ($regRows as $r):
      $st = $r['status'] !== '' ? $r['status'] : 'not read';
      $cls = $r['status'] === 'APPROVED' ? 'st-approved' : ($r['status'] === 'REJECTED' || $r['status'] === 'MISSING' ? 'st-rejected' : ($r['status'] === 'PENDING' ? 'st-submitted' : ''));
      $d = $regDefs[$r['name']] ?? null;
    ?>
      <tr>
        <td data-label="Name"><code><?= $e($r['name']) ?></code><br><small class="muted"><?= $e($r['kind'] === 'known' ? 'made by hand in WhatsApp Manager' : $r['title']) ?></small>
          <?php if ($d !== null): ?><details><summary class="muted" style="cursor:pointer">Wording</summary><pre class="wt-out" style="display:block"><?= $e(($d['header'] !== '' ? '[' . $d['header'] . " header]\n" : '') . $d['body'] . (isset($d['button']) ? "\n[" . $d['button']['type'] . ' button: ' . $d['button']['text'] . ']' : '')) ?></pre></details><?php endif; ?></td>
        <td data-label="Used for"><?= $e($r['purpose']) ?></td>
        <td data-label="Meta status"><span class="wt-badge <?= $e($cls) ?>"><?= $e($st) ?></span></td>
        <td data-label="In use"><?= $r['ready'] ? '✅' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php if ($mayEdit): ?>
  <div class="wt-row">
    <form method="post"><input type="hidden" name="<?= $e($ck) ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="meta_sync">
      <button class="btn ghost" type="submit" <?= $regConf ? '' : 'disabled title="Meta token / WABA id not configured"' ?>>🔄 Refresh status from Meta</button></form>
    <?php if (Auth::isSuperadmin()): ?>
    <form method="post" onsubmit="return confirm('Ask Meta to review the templates that are missing from the WhatsApp account? (Free. Existing ones are not touched.)')"><input type="hidden" name="<?= $e($ck) ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="meta_submit">
      <button class="btn" type="submit" <?= $regConf ? '' : 'disabled title="Meta token / WABA id not configured"' ?>>📤 Submit missing to Meta</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php foreach (WaTplLib::BUILTIN as $k => $tpl):
  if ($cat !== '' && $tpl['cat'] !== $cat) { continue; }
  $open = $openK === $k;
?>
<details class="panel wt-card" id="tpl-<?= $e($k) ?>" <?= $open ? 'open' : '' ?> data-tkey="<?= $e($k) ?>">
  <summary>
    <span class="wt-title"><?= $e($tpl['title']) ?></span>
    <span class="wt-badge"><?= $e(WaTplLib::CATS[$tpl['cat']]) ?></span>
    <span class="wt-badge <?= $tpl['meta'] === 'MARKETING' ? 'mk' : '' ?>"><?= $e($tpl['meta']) ?></span>
    <?php foreach (array_keys(WaTplLib::LANGS) as $l): $st = WaTplLib::state($k, $l); if ($st['meta_status'] === 'draft') { continue; } ?>
      <span class="wt-badge st-<?= $e($st['meta_status']) ?>"><?= $e(strtoupper($l)) ?> <?= $e($st['meta_status']) ?></span>
    <?php endforeach; ?>
  </summary>
  <div class="wt-langs" role="tablist">
    <?php foreach (WaTplLib::LANGS as $l => $ln): ?>
      <button type="button" class="wt-lang <?= ($open ? $openL : 'en') === $l ? 'on' : '' ?>" data-lang="<?= $e($l) ?>"><?= $e($ln) ?></button>
    <?php endforeach; ?>
  </div>
  <?php foreach (WaTplLib::LANGS as $l => $ln):
    $body = WaTplLib::body($k, $l);
    $st   = WaTplLib::state($k, $l);
  ?>
  <form method="post" class="wt-form <?= ($open ? $openL : 'en') === $l ? '' : 'hide' ?>" data-lang="<?= $e($l) ?>">
    <input type="hidden" name="<?= $e($ck) ?>" value="<?= $csrf ?>">
    <input type="hidden" name="tkey" value="<?= $e($k) ?>">
    <input type="hidden" name="lang" value="<?= $e($l) ?>">
    <textarea name="body" rows="5" class="wt-body" <?= $mayEdit ? '' : 'readonly' ?> placeholder="<?= $l === 'gu' ? 'No Gujarati draft yet — use the AI helper (Gujarati) or type one.' : '' ?>"><?= $e($body) ?></textarea>
    <div class="wt-vars"><?php foreach (WaTplLib::varsIn($body !== '' ? $body : WaTplLib::body($k, 'en')) as $v): ?><span class="wt-chip" title="<?= $e(WaTplLib::VARS[$v][1] ?? 'unknown') ?>">{{<?= $e($v) ?>}} <small><?= $e(WaTplLib::VARS[$v][0] ?? '?') ?></small></span><?php endforeach; ?></div>
    <div class="wt-row">
      <label>Meta name <input type="text" name="meta_name" value="<?= $e($st['meta_name']) ?>" placeholder="shg_<?= $e($k) ?>" maxlength="80" <?= $mayEdit ? '' : 'readonly' ?>></label>
      <label>Meta status
        <select name="meta_status" <?= $mayEdit ? '' : 'disabled' ?>>
          <?php foreach (['draft' => 'Draft', 'submitted' => 'Submitted to Meta', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $sv => $sl): ?>
            <option value="<?= $e($sv) ?>" <?= $st['meta_status'] === $sv ? 'selected' : '' ?>><?= $e($sl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($mayEdit): ?>
        <button class="btn" type="submit" name="action" value="save">Save</button>
        <?php if ($st['edited']): ?><button class="btn ghost" type="submit" name="action" value="reset" onclick="return confirm('Go back to the built-in draft?')">Reset</button><?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="wt-row wt-tools">
      <label>Preview with PNR <input type="text" class="wt-pnr" placeholder="SHG-2026-00123" maxlength="30"></label>
      <label>Agent id <input type="number" class="wt-agent" min="0" placeholder="e.g. 12" style="width:90px"></label>
      <label>Notice text <input type="text" class="wt-notice" placeholder="for {{notice_text}}" maxlength="600"></label>
      <button type="button" class="btn ghost wt-prev">👁 Preview</button>
      <?php if ($mayEdit && $aiOn): ?>
        <select class="wt-len" aria-label="AI length"><option value="short">Short</option><option value="medium" selected>Medium</option><option value="detailed">Detailed</option></select>
        <button type="button" class="btn ghost wt-ai">✨ AI wording</button>
      <?php endif; ?>
    </div>
    <div class="wt-out hide" aria-live="polite"></div>
  </form>
  <?php endforeach; ?>
</details>
<?php endforeach; ?>

<style>
.wt-head h2 { margin: 0 0 6px; }
.wt-kpis { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
.wt-kpi { padding: 6px 12px; border-radius: 999px; background: var(--surface-sunken); font-size: 13px; }
.wt-kpi.ok { background: rgba(22,163,74,.12); color: #15803d; }
.wt-live { font-size: 12.5px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.wt-chip { display: inline-block; padding: 3px 8px; border-radius: 8px; background: var(--surface-sunken); font-size: 12px; font-family: ui-monospace, monospace; }
.wt-chip small { color: var(--muted); font-family: inherit; }
.wt-card summary { cursor: pointer; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; min-height: 44px; }
.wt-title { font-weight: 800; font-size: 15px; margin-right: auto; }
.wt-badge { font-size: 11.5px; padding: 3px 8px; border-radius: 999px; background: var(--surface-sunken); font-weight: 700; }
.wt-badge.mk { background: rgba(240,124,31,.15); color: #b45309; }
.wt-badge.st-approved { background: rgba(22,163,74,.15); color: #15803d; }
.wt-badge.st-submitted { background: rgba(37,99,235,.15); color: #1d4ed8; }
.wt-badge.st-rejected { background: rgba(220,38,38,.15); color: #b91c1c; }
.wt-langs { display: flex; gap: 6px; margin: 12px 0 8px; flex-wrap: wrap; }
.wt-lang { min-height: 40px; padding: 6px 14px; border-radius: 10px; border: 1.5px solid var(--line-control); background: var(--surface); cursor: pointer; font-weight: 700; color: var(--ink); }
.wt-lang.on { background: var(--royal); color: var(--on-royal); border-color: var(--royal); }
.wt-body { width: 100%; font-size: 15px; line-height: 1.5; padding: 10px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); color: var(--ink); }
.wt-vars { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; }
.wt-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-top: 8px; }
.wt-row label { display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; color: var(--muted); }
.wt-row input, .wt-row select { min-height: 40px; padding: 6px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); color: var(--ink); }
.wt-out { margin-top: 10px; padding: 12px; border-radius: 10px; background: var(--surface-sunken); font-size: 14px; white-space: pre-wrap; }
.wt-out .wt-miss { color: #b91c1c; font-weight: 700; }
.wt-out .wt-meta { margin-top: 8px; font-family: ui-monospace, monospace; font-size: 12.5px; }
.hide { display: none !important; }
@media (max-width: 640px) { .wt-row label { width: 100%; } .wt-row input { width: 100% !important; } }
</style>
<script>
(function () {
  var csrfName = <?= json_encode($ck) ?>, csrf = <?= json_encode(Security::csrfToken()) ?>;
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function post(data) {
    var fd = new FormData(); fd.append(csrfName, csrf);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('wa-templates.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  document.querySelectorAll('.wt-card').forEach(function (card) {
    card.querySelectorAll('.wt-lang').forEach(function (b) {
      b.addEventListener('click', function () {
        var l = b.getAttribute('data-lang');
        card.querySelectorAll('.wt-lang').forEach(function (x) { x.classList.toggle('on', x === b); });
        card.querySelectorAll('.wt-form').forEach(function (f) { f.classList.toggle('hide', f.getAttribute('data-lang') !== l); });
      });
    });
    card.querySelectorAll('.wt-form').forEach(function (f) {
      var out = f.querySelector('.wt-out');
      var show = function (html) { out.innerHTML = html; out.classList.remove('hide'); };
      f.querySelector('.wt-prev').addEventListener('click', function () {
        show('…');
        post({ ajax: 'preview', body: f.querySelector('.wt-body').value, pnr: f.querySelector('.wt-pnr').value,
               agent_id: f.querySelector('.wt-agent').value || 0, notice: f.querySelector('.wt-notice').value })
          .then(function (j) {
            if (!j.ok) { show('<span class="wt-miss">' + esc(j.error) + '</span>'); return; }
            var h = esc(j.text) + '\n\n<small>' + j.chars + ' characters</small>';
            if (j.unknown.length) h += '\n<span class="wt-miss">Unknown variable: {{' + esc(j.unknown.join('}}, {{')) + '}} — fix before saving.</span>';
            if (j.missing.length) h += '\n<span class="wt-miss">Not in the records yet: {{' + esc(j.missing.join('}}, {{')) + '}} — give a PNR / agent / notice, or this message cannot go out.</span>';
            h += '<div class="wt-meta">For Meta / AiSensy:\n' + esc(j.meta) + '\n' + j.map.map(function (v, i) { return '{{' + (i + 1) + '}} = ' + esc(v); }).join(' · ') + '</div>';
            show(h);
          }).catch(function () { show('<span class="wt-miss">Network error</span>'); });
      });
      var ai = f.querySelector('.wt-ai');
      if (ai) ai.addEventListener('click', function () {
        ai.disabled = true; show('✨ …');
        post({ ajax: 'ai', tkey: f.querySelector('[name=tkey]').value, lang: f.getAttribute('data-lang'), length: f.querySelector('.wt-len').value })
          .then(function (j) {
            ai.disabled = false;
            if (!j.ok) { show('<span class="wt-miss">' + esc(j.error) + '</span>'); return; }
            show(esc(j.text) + '\n\n<small>by ' + esc(j.provider) + ' · checked: same variables, no invented numbers or offers</small>\n<button type="button" class="btn ghost wt-use">Use this wording</button> <small>(then Save)</small>');
            out.querySelector('.wt-use').addEventListener('click', function () { f.querySelector('.wt-body').value = j.text; out.classList.add('hide'); });
          }).catch(function () { ai.disabled = false; show('<span class="wt-miss">Network error</span>'); });
      });
    });
  });
})();
</script>
<?php admin_footer();
