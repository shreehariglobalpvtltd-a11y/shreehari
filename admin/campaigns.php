<?php
/**
 * admin/campaigns.php — 📣 Campaigns: launch one message to a customer list (5 Oct 2026).
 *
 *   1. Audience  — any filter from the All Customers page (date, agent,
 *                  route, country, trips, lapsed…), frozen into a list.
 *   2. Message   — an approved AiSensy WhatsApp template (automatic), the
 *                  same text sent one tap at a time from your own WhatsApp
 *                  (free), or SMS.
 *   3. Test      — to your own number first.
 *   4. Launch    — now or at a set time; the worker (cron/campaigns.php)
 *                  sends N a minute, never in quiet hours, never past the
 *                  daily cap, and reads delivered / read back from AiSensy.
 *
 * Opted-out (STOP), staff and placeholder numbers are never queued.
 * customers.view to look; marketing.send (manager, superadmin) to act.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/campaigns.php';
$admin = admin_boot('customers.view');
if (Auth::isCounterAgent()) {
    admin_header('Campaigns', 'campaigns');
    echo '<div class="flash bad">Campaigns are an office page.</div>';
    admin_footer();
    exit;
}
$me     = (int) $admin['id'];
$mayAct = Auth::isSuperadmin() || Auth::can('marketing.send');
$ready  = Campaigns::ready();
$flash  = null;
$e      = static fn ($v): string => Security::e((string) $v);
$id     = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

/* ---- one-tap mode: mark a recipient as sent (fetch from the list) ---- */
if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'mark') {
    header('Content-Type: application/json');
    if (!Security::verifyCsrf() || !$mayAct) {
        http_response_code(403);
        exit('{"ok":false}');
    }
    $rid = (int) ($_POST['rid'] ?? 0);
    $st  = in_array($_POST['state'] ?? '', ['sent', 'skipped', 'optout'], true) ? (string) $_POST['state'] : 'sent';
    $r   = Database::fetch('SELECT * FROM mk_recipients WHERE id = :r AND campaign_id = :c', ['r' => $rid, 'c' => $id]);
    if ($r !== null) {
        Database::update('mk_recipients', ['state' => $st, 'sent_at' => date('Y-m-d H:i:s')], 'id = :r', ['r' => $rid]);
        if ($st === 'optout') {
            Campaigns::optOut((string) $r['phone'], 'said no (one-tap campaign)', $me);
        }
        Campaigns::recount($id);
        $c = Campaigns::get($id);
        if ($c !== null && (int) (Database::fetch("SELECT COUNT(*) n FROM mk_recipients WHERE campaign_id = :c AND state = 'queued'", ['c' => $id])['n'] ?? 0) === 0) {
            Database::update('mk_campaigns', ['status' => 'done', 'finished_at' => date('Y-m-d H:i:s')], "id = :id AND status = 'running'", ['id' => $id]);
        }
    }
    exit('{"ok":true}');
}

/* ---- actions ---- */
if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$mayAct) {
        $flash = ['bad', 'Only the owner or a manager (marketing.send) can create or launch campaigns.'];
    } else {
        try {
            if ($act === 'save' || $act === 'build') {
                $id = Campaigns::save($_POST, $me, $id);
                $n  = Campaigns::buildAudience($id);
                Logger::audit('marketing.campaign_save', 'mk_campaign', (string) $id, null, ['recipients' => $n], 'by admin #' . $me);
                $flash = ['ok', 'Saved. ' . $n . ' people are on the list (STOP / staff / placeholder numbers removed).'];
            } elseif ($act === 'test') {
                $c = Campaigns::get($id) ?? throw new RuntimeException('Save the campaign first.');
                $to = preg_replace('/\D/', '', (string) ($_POST['test_phone'] ?? '')) ?? '';
                if (strlen($to) === 10) {
                    $to = '91' . $to;
                }
                if (strlen($to) < 11) {
                    throw new RuntimeException('Enter your own mobile for the test.');
                }
                $sample = ['phone' => $to, 'name' => (string) ($admin['full_name'] ?? 'Test')];
                if ($c['channel'] === 'wa_manual') {
                    $flash = ['ok', 'One-tap test: open https://wa.me/' . $to . '?text=… — the preview below shows the exact text.'];
                } else {
                    $x = Campaigns::sendOne($c, $sample);
                    $flash = $x['ok'] ? ['ok', 'Test accepted by the provider (ref ' . ($x['ref'] ?: '—') . '). Check your phone — accepted is not yet delivered.']
                                      : ['bad', 'Test NOT sent: ' . $x['error']];
                }
                Logger::audit('marketing.campaign_test', 'mk_campaign', (string) $id, null, ['to' => maskPhone($to)], 'by admin #' . $me);
            } elseif ($act === 'launch') {
                $c = Campaigns::get($id) ?? throw new RuntimeException('Campaign not found.');
                if (!in_array((string) $c['status'], ['draft', 'paused', 'scheduled'], true)) {
                    throw new RuntimeException('This campaign is already ' . $c['status'] . '.');
                }
                if ((int) $c['total'] === 0) {
                    throw new RuntimeException('The list is empty — build the audience first.');
                }
                if (($_POST['confirm'] ?? '') !== '1') {
                    throw new RuntimeException('Tick the confirmation box first.');
                }
                if ($c['channel'] === 'wa_template' && (string) $c['template_name'] === '') {
                    throw new RuntimeException('Pick an approved WhatsApp template.');
                }
                if ($c['channel'] !== 'wa_template' && trim((string) $c['message']) === '') {
                    throw new RuntimeException('Write the message first.');
                }
                $at = trim((string) ($_POST['start_at'] ?? ''));
                $startAt = $at !== '' && strtotime($at) !== false && strtotime($at) > time() + 60 ? date('Y-m-d H:i:s', (int) strtotime($at)) : null;
                Database::update('mk_campaigns', [
                    'status' => $startAt !== null ? 'scheduled' : 'running', 'start_at' => $startAt ?? date('Y-m-d H:i:s'),
                    'launched_by' => $me, 'launched_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => $id]);
                Logger::audit('marketing.campaign_launch', 'mk_campaign', (string) $id, null, ['recipients' => (int) $c['total'], 'channel' => $c['channel'], 'at' => $startAt], 'by admin #' . $me);
                $flash = ['ok', $startAt !== null ? 'Scheduled for ' . $startAt . '.' : ($c['channel'] === 'wa_manual' ? 'Launched — send from the one-tap list below.' : 'Launched — the worker sends ' . (int) $c['rate_per_min'] . ' a minute (outside quiet hours).')];
            } elseif (in_array($act, ['pause', 'resume', 'cancel'], true)) {
                $to = ['pause' => 'paused', 'resume' => 'running', 'cancel' => 'cancelled'][$act];
                Database::update('mk_campaigns', ['status' => $to], 'id = :id', ['id' => $id]);
                Logger::audit('marketing.campaign_' . $act, 'mk_campaign', (string) $id, null, null, 'by admin #' . $me);
                $flash = ['ok', 'Campaign ' . $to . '.'];
            } elseif ($act === 'optout_add') {
                $flash = Campaigns::optOut((string) ($_POST['phone'] ?? ''), mb_substr(trim((string) ($_POST['reason'] ?? 'office')), 0, 120), $me)
                    ? ['ok', 'Number blocked from campaigns.'] : ['bad', 'Enter a 10-digit number.'];
            } elseif ($act === 'optout_del') {
                Database::run('DELETE FROM mk_optout WHERE phone = :p', ['p' => substr(preg_replace('/\D/', '', (string) ($_POST['phone'] ?? '')) ?? '', -10)]);
                $flash = ['ok', 'Number allowed again.'];
            }
        } catch (Throwable $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
    }
}

$c = $ready && $id > 0 ? Campaigns::get($id) : null;
$isNew = $ready && $c === null && isset($_GET['new']);
$audQs = $c !== null ? (string) $c['audience'] : (string) ($_GET['aud'] ?? '');
$templates = [];
if ($ready && ($c !== null || $isNew) && $mayAct) {
    try {
        require_once ROOT_PATH . '/whatsapp/aisensy.php';
        if (AiSensyAPI::enabled()) {
            $templates = AiSensyAPI::templates();
        }
    } catch (Throwable $ignored) {
    }
}
$list = $ready ? Database::fetchAll('SELECT c.*, a.full_name AS by_name FROM mk_campaigns c LEFT JOIN admins a ON a.id = c.created_by ORDER BY c.id DESC LIMIT 50') : [];
$recips = $c !== null ? Database::fetchAll('SELECT * FROM mk_recipients WHERE campaign_id = :c ORDER BY FIELD(state,\'queued\',\'sending\',\'failed\',\'sent\',\'delivered\',\'read\',\'optout\',\'skipped\'), id LIMIT 300', ['c' => (int) $c['id']]) : [];
$optouts = $ready ? Database::fetchAll('SELECT * FROM mk_optout ORDER BY created_at DESC LIMIT 100') : [];
$audLabel = static function (string $qs): string {
    parse_str($qs, $q);
    $bits = [];
    if (!empty($q['from']) || !empty($q['to'])) {
        $bits[] = (($q['mode'] ?? '') === 'travel' ? 'travelled ' : 'booked ') . ($q['from'] ?? '…') . ' → ' . ($q['to'] ?? 'today');
    }
    if (!empty($q['agent'])) {
        $bits[] = 'seller ' . $q['agent'];
    }
    if (!empty($q['route'])) {
        $bits[] = 'route #' . $q['route'];
    }
    if (!empty($q['country'])) {
        $bits[] = $q['country'] === 'NP' ? 'Nepal numbers' : 'India numbers';
    }
    if (!empty($q['min'])) {
        $bits[] = $q['min'] . '+ trips';
    }
    if (!empty($q['lapsed'])) {
        $bits[] = 'not travelled ' . $q['lapsed'] . ' days';
    }
    if (!empty($q['leads'])) {
        $bits[] = '+ leads';
    }
    return $bits === [] ? 'All customers' : implode(' · ', $bits);
};
$csrf = '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . Security::csrfToken() . '">';
$stTone = ['draft' => 'var(--mut)', 'scheduled' => 'var(--warn)', 'running' => 'var(--ok)', 'paused' => 'var(--warn)', 'done' => 'var(--ink)', 'cancelled' => 'var(--bad)'];

admin_header('Campaigns', 'campaigns');
?>
<style>
  .mk-note{font-size:13px;color:var(--mut);margin:0 0 12px;line-height:1.5}
  .mk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:10px;margin:8px 0}
  .mk-grid label,.mk-full{display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--mut)}
  .mk-grid input,.mk-grid select,.mk-full textarea,.mk-full input,.mk-full select{padding:9px 10px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font:inherit;min-height:40px}
  .mk-full textarea{min-height:110px}
  .mk-ch{display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));margin:8px 0}
  .mk-ch label{display:flex;gap:8px;align-items:flex-start;border:1.5px solid var(--line);border-radius:12px;padding:10px;cursor:pointer;font-size:13px}
  .mk-ch label:has(input:checked){border-color:var(--ok);background:var(--ok-bg)}
  .mk-prev{white-space:pre-wrap;background:#E7F6EE;color:#0E3B24;border-radius:12px;padding:12px;font-size:14px;max-width:420px}
  .mk-bar{height:8px;border-radius:999px;background:var(--line);overflow:hidden;min-width:90px}
  .mk-bar i{display:block;height:100%;background:var(--ok)}
  .mk-steps{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
  .mk-one{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .mk-tpl{font-size:12px;color:var(--mut);white-space:pre-wrap;border-left:3px solid var(--line);padding-left:8px;margin:4px 0}
  .mk-inline{display:inline}
</style>

<?php if ($flash !== null): ?>
  <p style="display:block;padding:10px 12px;border-radius:10px;background:<?= $flash[0] === 'ok' ? 'var(--ok-bg)' : 'var(--bad-bg)' ?>;color:<?= $flash[0] === 'ok' ? 'var(--ok)' : 'var(--bad)' ?>"><?= $e($flash[1]) ?></p>
<?php endif; ?>

<?php if (!$ready): ?>
  <div class="panel"><p class="mk-note"><b style="color:var(--bad)">Apply database/upgrade-2026-10-05-campaigns.sql to switch campaigns on.</b></p></div>
<?php else: ?>

<p class="mk-note">Send one message to a customer list. Choose who on <a href="/admin/customer-directory.php">📇 All Customers</a> (date, agent, route, country, trips…),
  then press “Launch a campaign to this list”. Automatic WhatsApp needs an <b>approved</b> AiSensy template (MARKETING type for offers); one-tap WhatsApp
  sends from your own phone and needs nothing. Quiet hours <?= Settings::getInt('mk_quiet_from', 21) ?>:00–<?= Settings::getInt('mk_quiet_to', 8) ?>:00 and a daily cap of
  <?= Settings::getInt('mk_daily_cap', 300) ?> apply to automatic sends. People who reply STOP never get another one.</p>

<?php if ($c !== null || $isNew): $cc = $c ?? ['id' => 0, 'name' => '', 'channel' => 'wa_manual', 'template_name' => '', 'template_params' => '[]', 'header_image' => '', 'message' => "नमस्ते {first} 🙏\nS Hari Global बाट विशेष अफर…\nबुक गर्न: {link}", 'link_url' => '', 'rate_per_min' => 20, 'status' => 'draft', 'total' => 0, 'sent' => 0, 'failed' => 0, 'delivered' => 0, 'read_n' => 0];
  $editable = in_array((string) $cc['status'], ['draft', 'paused', 'scheduled'], true) && $mayAct;
  $params = json_decode((string) $cc['template_params'], true) ?: []; ?>
<div class="panel">
  <h2><?= $c !== null ? '📣 ' . $e($cc['name']) . ' <small style="color:' . ($stTone[$cc['status']] ?? 'var(--ink)') . '">· ' . $e($cc['status']) . '</small>' : '📣 New campaign' ?></h2>
  <p class="mk-note">Audience: <b><?= $e($audLabel($audQs)) ?></b> — <a href="/admin/customer-directory.php?<?= $e($audQs) ?>">see / change this list</a></p>
  <form method="post"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $cc['id'] ?>"><input type="hidden" name="audience" value="<?= $e($audQs) ?>">
    <fieldset <?= $editable ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
    <div class="mk-grid">
      <label>Campaign name<input name="name" maxlength="120" required value="<?= $e($cc['name']) ?>" placeholder="Dashain offer — Nepal customers"></label>
      <label>Offer / booking link (src=cmp<?= (int) $cc['id'] ?: 'N' ?> is added)<input name="link_url" value="<?= $e($cc['link_url']) ?>" placeholder="<?= $e(appUrl('/')) ?>"></label>
      <label>Automatic speed (messages a minute)<input type="number" name="rate_per_min" min="1" max="60" value="<?= (int) $cc['rate_per_min'] ?>"></label>
    </div>
    <div class="mk-ch">
      <?php foreach (Campaigns::CHANNELS as $k => $lbl): ?>
        <label><input type="radio" name="channel" value="<?= $k ?>" <?= $cc['channel'] === $k ? 'checked' : '' ?>><span><b><?= $e(explode(' — ', $lbl)[0]) ?></b><br><small><?= $e(explode(' — ', $lbl)[1] ?? '') ?></small></span></label>
      <?php endforeach; ?>
    </div>
    <div class="mk-grid">
      <label>WhatsApp template (AiSensy)
        <select name="template_name" id="mkTpl"><option value="">— pick an approved template —</option>
          <?php foreach ($templates as $t): ?><option value="<?= $e($t['name']) ?>" data-vars="<?= (int) $t['vars'] ?>" data-text="<?= $e($t['text']) ?>" <?= $t['status'] !== 'APPROVED' ? 'disabled' : '' ?> <?= $cc['template_name'] === $t['name'] ? 'selected' : '' ?>><?= $e($t['name'] . ' · ' . $t['category'] . ' · ' . $t['status']) ?></option><?php endforeach; ?>
          <?php if ($cc['template_name'] !== '' && $cc['template_name'] !== null && !in_array($cc['template_name'], array_column($templates, 'name'), true)): ?><option value="<?= $e($cc['template_name']) ?>" selected><?= $e($cc['template_name']) ?></option><?php endif; ?>
        </select></label>
      <label>Header image (https, IMAGE templates only)<input name="header_image" value="<?= $e($cc['header_image']) ?>" placeholder="https://…"></label>
    </div>
    <div class="mk-tpl" id="mkTplText"><?= $templates === [] ? 'No templates loaded — AiSensy is not connected, or no template is approved yet. One-tap WhatsApp works without one.' : '' ?></div>
    <label class="mk-full">Template variables — one per line, in order {{1}}, {{2}}… (you can use {name} {first} {link} {phone})
      <textarea name="params" style="min-height:80px" placeholder="{first}&#10;Dashain special: ₹200 off till 15 Oct&#10;{link}"><?= $e(implode("\n", $params)) ?></textarea></label>
    <label class="mk-full">Message for one-tap WhatsApp / SMS ({name} {first} {link} {phone})
      <textarea name="message" id="mkMsg" maxlength="1500"><?= $e($cc['message']) ?></textarea></label>
    </fieldset>
    <?php if ($editable): ?>
      <div class="mk-steps"><button class="btn" name="action" value="build" type="submit">💾 Save &amp; build the list</button></div>
    <?php endif; ?>
  </form>
  <p class="mk-note" style="margin-top:8px">Preview (first person):</p>
  <div class="mk-prev" id="mkPrev"><?= $e(Campaigns::textFor($cc + ['id' => (int) $cc['id']], ['name' => (string) ($recips[0]['name'] ?? 'Ram Bahadur')])) ?></div>

  <?php if ($c !== null): ?>
  <div class="kpis" style="margin-top:14px">
    <div class="kpi tone-navy"><span class="ki"><svg class="a-ic"><use href="#a-users"/></svg></span><div class="kt"><div class="kk">On the list</div><div class="kv"><?= (int) $c['total'] ?></div><div class="ks"><?= $e(Campaigns::CHANNELS[$c['channel']] ?? $c['channel']) ?></div></div></div>
    <div class="kpi tone-green"><span class="ki"><svg class="a-ic"><use href="#a-send"/></svg></span><div class="kt"><div class="kk">Sent</div><div class="kv"><?= (int) $c['sent'] ?></div><div class="ks"><?= (int) $c['delivered'] ?> delivered · <?= (int) $c['read_n'] ?> read</div></div></div>
    <div class="kpi tone-red"><span class="ki"><svg class="a-ic"><use href="#a-x-circle"/></svg></span><div class="kt"><div class="kk">Failed</div><div class="kv"><?= (int) $c['failed'] ?></div><div class="ks">see the list below</div></div></div>
  </div>
  <?php if ($mayAct): ?>
  <div class="mk-steps">
    <form method="post" class="mk-one"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="action" value="test">
      <input name="test_phone" inputmode="tel" placeholder="your mobile" style="padding:8px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);min-height:38px">
      <button class="btn ghost" type="submit">🧪 Test to my number</button></form>
    <?php if (in_array($c['status'], ['draft', 'paused', 'scheduled'], true)): ?>
      <form method="post" class="mk-one"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="action" value="launch">
        <label style="font-size:13px"><input type="checkbox" name="confirm" value="1"> send to <?= (int) $c['total'] ?> people</label>
        <input type="datetime-local" name="start_at" title="Leave empty to start now" style="padding:7px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);min-height:38px">
        <button class="btn" type="submit">🚀 Launch</button></form>
    <?php endif; ?>
    <?php if ($c['status'] === 'running'): ?><form method="post" class="mk-inline"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn ghost" name="action" value="pause" type="submit">⏸ Pause</button></form><?php endif; ?>
    <?php if ($c['status'] === 'paused'): ?><form method="post" class="mk-inline"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn ghost" name="action" value="resume" type="submit">▶ Resume</button></form><?php endif; ?>
    <?php if (!in_array($c['status'], ['done', 'cancelled'], true)): ?><form method="post" class="mk-inline" onsubmit="return confirm('Cancel this campaign? Nobody else will get it.')"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn ghost" name="action" value="cancel" type="submit">✖ Cancel</button></form><?php endif; ?>
  </div>
  <?php endif; ?>

  <h3 style="margin-top:14px"><?= $c['channel'] === 'wa_manual' && $c['status'] === 'running' ? '📲 One-tap list — tap WhatsApp, send, come back for the next' : 'People on this list' ?></h3>
  <div class="dt-wrap">
    <table class="dt card-table" id="mkList">
      <thead><tr><th>Name</th><th>Mobile</th><th>State</th><th>When</th><th data-nosort></th></tr></thead>
      <tbody>
      <?php foreach ($recips as $r): $txt = Campaigns::textFor($c, $r); ?>
        <tr data-rid="<?= (int) $r['id'] ?>">
          <td data-label="Name"><?= $e($r['name'] ?: '—') ?></td>
          <td data-label="Mobile">+<?= $e($r['phone']) ?></td>
          <td data-label="State" class="st"><?= $e($r['state']) ?><?= $r['error'] ? '<br><small style="color:var(--bad)">' . $e($r['error']) . '</small>' : '' ?></td>
          <td data-label="When"><?= $e(substr((string) ($r['sent_at'] ?? ''), 5, 11)) ?></td>
          <td data-label="">
            <?php if ($c['channel'] === 'wa_manual' && $c['status'] === 'running' && $r['state'] === 'queued' && $mayAct): ?>
              <a class="btn mk-go" target="_blank" rel="noopener" href="https://wa.me/<?= $e($r['phone']) ?>?text=<?= $e(rawurlencode($txt)) ?>" data-rid="<?= (int) $r['id'] ?>">💬 WhatsApp</a>
              <button class="btn ghost mk-skip" type="button" data-rid="<?= (int) $r['id'] ?>" data-state="skipped">Skip</button>
              <button class="btn ghost mk-skip" type="button" data-rid="<?= (int) $r['id'] ?>" data-state="optout" title="They do not want offers">No offers</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Campaigns</h2>
  <?php if ($mayAct): ?><p><a class="btn" href="/admin/customer-directory.php">＋ New campaign — choose who first</a></p><?php endif; ?>
  <?php if ($list === []): ?><p class="mk-note">No campaigns yet.</p><?php else: ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Campaign</th><th>Channel</th><th>Audience</th><th>Status</th><th>Progress</th><th>Created</th></tr></thead>
    <tbody>
    <?php foreach ($list as $l): $pct = (int) $l['total'] > 0 ? (int) round(100 * ((int) $l['sent'] + (int) $l['failed']) / (int) $l['total']) : 0; ?>
      <tr>
        <td data-label="Campaign"><a href="?id=<?= (int) $l['id'] ?>"><b><?= $e($l['name']) ?></b></a></td>
        <td data-label="Channel"><?= $e(explode(' — ', Campaigns::CHANNELS[$l['channel']] ?? $l['channel'])[0]) ?></td>
        <td data-label="Audience" class="wrap"><?= $e($audLabel((string) $l['audience'])) ?> · <?= (int) $l['total'] ?></td>
        <td data-label="Status"><b style="color:<?= $stTone[$l['status']] ?? 'var(--ink)' ?>"><?= $e($l['status']) ?></b><?= $l['status'] === 'scheduled' ? '<br><small>' . $e($l['start_at']) . '</small>' : '' ?></td>
        <td data-label="Progress"><div class="mk-bar"><i style="width:<?= $pct ?>%"></i></div><small><?= (int) $l['sent'] ?> sent · <?= (int) $l['delivered'] ?> delivered · <?= (int) $l['failed'] ?> failed</small></td>
        <td data-label="Created"><?= $e(substr((string) $l['created_at'], 0, 10)) ?><br><small><?= $e($l['by_name'] ?? '') ?></small></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>🚫 No-offers list (<?= count($optouts) ?>)</h2>
  <p class="mk-note">Numbers here never get a campaign. WhatsApp “STOP” adds them automatically.</p>
  <?php if ($mayAct): ?>
  <form method="post" class="mk-one"><?= $csrf ?><input type="hidden" name="action" value="optout_add">
    <input name="phone" inputmode="tel" placeholder="98XXXXXXXX" required style="padding:8px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);min-height:38px">
    <input name="reason" placeholder="reason (optional)" style="padding:8px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);min-height:38px">
    <button class="btn ghost" type="submit">Block from offers</button></form>
  <?php endif; ?>
  <?php if ($optouts !== []): ?>
  <div class="dt-wrap"><table class="dt"><thead><tr><th>Mobile</th><th>Why</th><th>When</th><th></th></tr></thead><tbody>
    <?php foreach ($optouts as $o): ?><tr><td><?= $e(maskPhone((string) $o['phone'])) ?></td><td><?= $e($o['reason']) ?></td><td><?= $e(substr((string) $o['created_at'], 0, 10)) ?></td>
      <td><?php if ($mayAct): ?><form method="post" class="mk-inline"><?= $csrf ?><input type="hidden" name="action" value="optout_del"><input type="hidden" name="phone" value="<?= $e($o['phone']) ?>"><button class="btn ghost" type="submit">Allow</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
  var csrf = (document.querySelector('meta[name=csrf]') || {}).content || '';
  var cid = <?= (int) ($c['id'] ?? 0) ?>;
  function mark(rid, state, row) {
    var fd = new FormData();
    fd.append('action', 'mark'); fd.append('id', cid); fd.append('rid', rid); fd.append('state', state);
    fd.append('<?= CSRF_TOKEN_NAME ?>', csrf);
    fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function () {});
    if (row) { var st = row.querySelector('.st'); if (st) st.textContent = state; row.querySelectorAll('.mk-go,.mk-skip').forEach(function (b) { b.remove(); }); }
  }
  document.querySelectorAll('#mkList .mk-go').forEach(function (a) {
    a.addEventListener('click', function () { mark(a.dataset.rid, 'sent', a.closest('tr')); });
  });
  document.querySelectorAll('#mkList .mk-skip').forEach(function (b) {
    b.addEventListener('click', function () { mark(b.dataset.rid, b.dataset.state, b.closest('tr')); });
  });
  var sel = document.getElementById('mkTpl'), box = document.getElementById('mkTplText');
  function showTpl() { if (!sel || !box) return; var o = sel.options[sel.selectedIndex]; if (o && o.dataset.text) box.textContent = o.dataset.text + '\n(' + o.dataset.vars + ' variable' + (o.dataset.vars === '1' ? '' : 's') + ')'; }
  if (sel) { sel.addEventListener('change', showTpl); showTpl(); }
})();
</script>
<?php admin_footer(); ?>
