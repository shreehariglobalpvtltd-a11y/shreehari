<?php
/**
 * admin/ai-manager.php — the AI Manager hub (24 Sep 2026).
 *
 *  Tabs: today · copilot · learn · memory · marketing
 *  Reading needs dashboard.view; every write needs settings.manage
 *  (Auth::canManageSettings): approving an example, forgetting a person,
 *  approving or cancelling a post.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');
require_once INCLUDE_PATH . '/aimemory.php';
require_once INCLUDE_PATH . '/ailearn.php';
require_once INCLUDE_PATH . '/socialposts.php';

$e     = static fn(?string $s): string => Security::e((string) $s);
$tab   = in_array($_GET['tab'] ?? '', ['today', 'copilot', 'learn', 'memory', 'marketing'], true) ? (string) $_GET['tab'] : 'today';
$flash = null;
$canManage = Auth::canManageSettings();
$draft = null;
$memPhone = Security::clean((string) ($_GET['phone'] ?? $_POST['phone'] ?? ''), 20);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canManage && $act !== 'draft') {
        http_response_code(403);
        $flash = ['bad', 'Only a manager or the owner can change what the assistant learns or publishes.'];
    } else {
        try {
            switch ($act) {
                case 'example_save':
                    $id = (int) ($_POST['id'] ?? 0) ?: null;
                    $newId = AiLearn::save(['intent' => $_POST['intent'] ?? 'general', 'language' => $_POST['language'] ?? '', 'user_text' => $_POST['user_text'] ?? '', 'bad_reply' => $_POST['bad_reply'] ?? '', 'good_reply' => $_POST['good_reply'] ?? '', 'rule_text' => $_POST['rule_text'] ?? ''], (int) $admin['id'], $id);
                    if (($_POST['approve'] ?? '') === '1') { AiLearn::setStatus($newId, 'approved', (int) $admin['id']); }
                    $flash = ['ok', ($_POST['approve'] ?? '') === '1' ? 'Saved and approved — the assistant answers this way from now.' : 'Saved as a candidate.'];
                    $tab = 'learn';
                    break;
                case 'example_status':
                    AiLearn::setStatus((int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? ''), (int) $admin['id']);
                    $flash = ['ok', 'Example ' . $e((string) $_POST['status']) . '.'];
                    $tab = 'learn';
                    break;
                case 'forget':
                    $n = AiMemory::forget($memPhone);
                    Logger::audit('ai_memory.forget', 'phone', substr(normalisePhone($memPhone), -4), null, ['rows' => $n], 'by admin #' . $admin['id']);
                    $flash = ['ok', 'Forgotten — ' . $n . ' memory row(s) removed.'];
                    $tab = 'memory';
                    break;
                case 'draft':
                    $draft = SocialPosts::draft(Security::clean((string) ($_POST['topic'] ?? ''), 300));
                    $tab = 'marketing';
                    break;
                case 'post_create':
                    $id = SocialPosts::create(['channel' => $_POST['channel'] ?? '', 'topic' => $_POST['topic'] ?? '', 'caption_en' => $_POST['caption_en'] ?? '', 'caption_hi' => $_POST['caption_hi'] ?? '', 'caption_ne' => $_POST['caption_ne'] ?? '', 'media_path' => $_POST['media_path'] ?? '', 'link_url' => $_POST['link_url'] ?? '', 'publish_at' => $_POST['publish_at'] ?? ''], (int) $admin['id']);
                    $flash = ['ok', 'Draft #' . $id . ' saved — approve it when it reads right.'];
                    $tab = 'marketing';
                    break;
                case 'post_status':
                    SocialPosts::setStatus((int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? ''), (int) $admin['id']);
                    $flash = ['ok', 'Post ' . $e((string) $_POST['status']) . '.'];
                    $tab = 'marketing';
                    break;
                default:
                    $flash = ['bad', 'Unknown action.'];
            }
        } catch (Throwable $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
    }
}

$sw = static fn(string $k): string => Settings::getBool($k, false) ? '<span class="chip" style="background:#DCFCE7;color:#166534">ON</span>' : '<span class="chip" style="background:#FEE2E2;color:#991B1B">OFF</span>';
$csrf = Security::e(Security::csrfToken()); $k = CSRF_TOKEN_NAME;

admin_header('AI Manager', 'ai-manager');
if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . $e($flash[1]) . '</div>'; }
?>
<style>
.aim-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px}
.aim-tabs a{padding:8px 14px;border-radius:999px;border:1px solid var(--line,#E2E9F4);text-decoration:none;font-weight:700;color:inherit}
.aim-tabs a.on{background:#12264E;color:#fff;border-color:#12264E}
.aim-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:14px}
.aim-kpi{background:var(--card,#fff);border:1px solid var(--line,#E2E9F4);border-radius:14px;padding:14px}
.aim-kpi b{font-size:26px;display:block}.aim-kpi small{color:var(--muted,#5C6B85)}
.chip{display:inline-block;font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;margin-left:6px}
.aim-chat{display:flex;flex-direction:column;gap:8px;max-height:60vh;overflow:auto;padding:12px;background:var(--bg,#F6F8FC);border-radius:14px;border:1px solid var(--line,#E2E9F4)}
.aim-msg{max-width:85%;padding:10px 12px;border-radius:14px;white-space:pre-wrap;line-height:1.45}
.aim-msg.me{align-self:flex-end;background:#12264E;color:#fff}.aim-msg.ai{align-self:flex-start;background:var(--card,#fff);border:1px solid var(--line,#E2E9F4)}
.aim-row{display:flex;gap:8px;margin-top:10px}.aim-row input{flex:1;min-height:44px;padding:0 12px;border:1px solid var(--line,#E2E9F4);border-radius:12px;font-size:15px}
.aim-form{display:grid;gap:8px;max-width:760px}.aim-form textarea,.aim-form input,.aim-form select{width:100%;padding:8px 10px;border:1px solid var(--line,#E2E9F4);border-radius:10px;font:inherit}
.aim-form label{font-size:12.5px;color:var(--muted,#5C6B85);font-weight:700}
.mono{font-family:ui-monospace,monospace;font-size:12.5px}
</style>
<div class="aim-tabs">
<?php foreach (['today' => '📊 Today', 'copilot' => '🤖 Copilot', 'learn' => '🎓 Learn', 'memory' => '🧠 Memory', 'marketing' => '📣 Marketing'] as $t => $label): ?>
  <a href="?tab=<?= $t ?>" class="<?= $t === $tab ? 'on' : '' ?>"><?= $label ?></a>
<?php endforeach; ?>
</div>

<?php if ($tab === 'today'):
    $calls = Database::fetch("SELECT COUNT(*) n, COALESCE(SUM(ok),0) ok, COUNT(DISTINCT phone) people FROM ai_agent_calls WHERE DATE(created_at) = CURDATE()") ?? [];
    $st = AiLearn::stats(30);
    $mem = Database::fetch("SELECT COUNT(*) people, (SELECT COUNT(*) FROM ai_memory_episodes) episodes FROM ai_memory_profile") ?? [];
    $posts = Database::fetch("SELECT SUM(status='draft') d, SUM(status='approved') a, SUM(status='published' AND DATE(published_at)=CURDATE()) today, SUM(status='failed') f FROM social_posts") ?? [];
    $unanswered = [];
    try { $unanswered = Database::fetchAll("SELECT normalized_question, frequency, language FROM ai_unanswered_questions WHERE status <> 'resolved' ORDER BY frequency DESC, updated_at DESC LIMIT 8"); } catch (Throwable $ex) {}
?>
<div class="aim-grid">
  <div class="aim-kpi"><small>Assistant today</small><b><?= (int) ($calls['n'] ?? 0) ?></b><small><?= (int) ($calls['ok'] ?? 0) ?> ok · <?= (int) ($calls['people'] ?? 0) ?> people</small></div>
  <div class="aim-kpi"><small>Feedback, 30 days</small><b>👍 <?= $st['up'] ?> · 👎 <?= $st['down'] ?></b><small><?= $st['candidates'] ?> to review · <?= $st['approved'] ?> approved</small></div>
  <div class="aim-kpi"><small>People remembered</small><b><?= (int) ($mem['people'] ?? 0) ?></b><small><?= (int) ($mem['episodes'] ?? 0) ?> memories</small></div>
  <div class="aim-kpi"><small>Marketing queue</small><b><?= (int) ($posts['a'] ?? 0) ?> approved</b><small><?= (int) ($posts['d'] ?? 0) ?> drafts · <?= (int) ($posts['today'] ?? 0) ?> sent today · <?= (int) ($posts['f'] ?? 0) ?> failed</small></div>
</div>
<div class="panel"><h2>Switches</h2>
  <p>WhatsApp assistant <?= $sw('wa_agent_on') ?> · sells <?= $sw('wa_agent_sell') ?> · knowledge base <?= $sw('ai_kb_on') ?> · memory <?= $sw('ai_memory_on') ?> · learned examples <?= $sw('ai_examples_on') ?> · nightly refresh <?= $sw('ai_refresh_on') ?> · social publishing <?= $sw('social_publish_on') ?> · live seat events <?= $sw('seat_events_on') ?></p>
  <p class="muted">Keys: Claude <?= Settings::getString('anthropic_api_key', '') !== '' ? '✅' : '—' ?> · Gemini <?= Settings::getString('gemini_api_key', '') !== '' ? '✅' : '—' ?> · Facebook page <?= Settings::getString('meta_page_id', '') !== '' ? '✅' : '—' ?> · Telegram <?= Settings::getString('telegram_bot_token', '') !== '' ? '✅' : '—' ?>. Change them in <a href="settings.php">Settings</a>.</p>
</div>
<div class="panel"><h2>Questions the assistant could not answer</h2>
<?php if ($unanswered === []): ?><p class="muted">Nothing waiting. Answers you add in <a href="ai-knowledge.php">AI Knowledge</a> stop the same question coming back.</p>
<?php else: ?><table><tr><th>Question</th><th>Asked</th><th>Lang</th></tr><?php foreach ($unanswered as $u): ?><tr><td><?= $e((string) $u['normalized_question']) ?></td><td><?= (int) $u['frequency'] ?>×</td><td><?= $e((string) $u['language']) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>

<?php elseif ($tab === 'copilot'): ?>
<div class="panel"><h2>Ask the assistant as the office</h2>
  <p class="muted">Same brain and tools as WhatsApp, in your role (<?= $e((string) ($admin['role'] ?? '')) ?>). Try: <i>aaja ko report</i> · <i>SHG-2026-00120 ko ticket</i> · <i>kun bus bhariyo</i> · <i>Sita Thapa ko booking khoj</i>.</p>
  <div class="aim-chat" id="aimChat"><div class="aim-msg ai">🙏 नमस्ते <?= $e((string) ($admin['full_name'] ?? '')) ?>! म S Hari Global को सहायक। कार्यालयको काममा के मद्दत गरूँ?</div></div>
  <form class="aim-row" id="aimForm" autocomplete="off"><input id="aimText" placeholder="Type in Nepali, Hindi or English…" maxlength="1500" required><button class="btn" type="submit">Send</button></form>
</div>
<script>
(function(){
  var chat=document.getElementById('aimChat'), form=document.getElementById('aimForm'), input=document.getElementById('aimText');
  var csrf=(document.querySelector('meta[name="csrf"]')||{}).content||'';
  function add(t,who){var d=document.createElement('div');d.className='aim-msg '+who;d.textContent=t;chat.appendChild(d);chat.scrollTop=chat.scrollHeight;return d;}
  form.addEventListener('submit',function(ev){ev.preventDefault();var t=input.value.trim();if(!t)return;add(t,'me');input.value='';var w=add('…','ai');
    fetch('/admin/api/ai-copilot.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({text:t}),credentials:'same-origin'})
      .then(function(r){return r.json();}).then(function(j){w.textContent=(j&&j.ok&&j.data)?j.data.text:((j&&j.error)||'No answer.');})
      .catch(function(){w.textContent='Network problem — try again.';});});
})();
</script>

<?php elseif ($tab === 'learn'):
    $cands = Database::fetchAll("SELECT * FROM ai_examples WHERE status = 'candidate' ORDER BY id DESC LIMIT 40");
    $appr  = Database::fetchAll("SELECT * FROM ai_examples WHERE status = 'approved' ORDER BY approved_at DESC LIMIT 40");
    $fb    = Database::fetchAll("SELECT * FROM ai_feedback_log ORDER BY id DESC LIMIT 30");
?>
<div class="panel"><h2>To review — <?= count($cands) ?></h2>
  <p class="muted">A 👎 or a "galat" from a customer lands here with what they asked and what we said. Write the right answer (and, if you like, one rule) and approve — from then on the assistant answers that way. Nothing unapproved reaches it.</p>
<?php if ($cands === []): ?><p class="muted">Nothing to review.</p><?php endif; ?>
<?php foreach ($cands as $c): ?>
  <form method="post" class="aim-form" style="border-top:1px solid var(--line,#E2E9F4);padding:12px 0">
    <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="example_save"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
    <div><label>They asked (<?= $e((string) $c['language']) ?>, <?= $e(formatDate(substr((string) $c['created_at'], 0, 10))) ?>)</label><textarea name="user_text" rows="2"><?= $e((string) $c['user_text']) ?></textarea></div>
    <div><label>We said</label><textarea name="bad_reply" rows="2" class="mono"><?= $e((string) $c['bad_reply']) ?></textarea></div>
    <div><label>We should say</label><textarea name="good_reply" rows="3" required><?= $e((string) $c['good_reply']) ?></textarea></div>
    <div style="display:grid;grid-template-columns:1fr 120px 140px;gap:8px"><div><label>Rule (optional): "When someone asks X, always Y"</label><input name="rule_text" value="<?= $e((string) $c['rule_text']) ?>" maxlength="500"></div>
      <div><label>Intent</label><input name="intent" value="<?= $e((string) $c['intent']) ?>" maxlength="50"></div>
      <div><label>Language</label><select name="language"><?php foreach (['ne' => 'Nepali', 'hi' => 'Hindi', 'en' => 'English', 'gu' => 'Gujarati'] as $lv => $ll): ?><option value="<?= $lv ?>" <?= $c['language'] === $lv ? 'selected' : '' ?>><?= $ll ?></option><?php endforeach; ?></select></div></div>
    <div class="toolbar"><button class="btn" name="approve" value="1" <?= $canManage ? '' : 'disabled' ?>>✅ Save & approve</button> <button class="btn btn-ghost" name="approve" value="0" <?= $canManage ? '' : 'disabled' ?>>💾 Save only</button>
      <button class="btn btn-ghost" formaction="?tab=learn" name="action" value="example_status" onclick="this.form.status.value='retired'" <?= $canManage ? '' : 'disabled' ?>>🗑 Retire</button><input type="hidden" name="status" value=""></div>
  </form>
<?php endforeach; ?>
</div>
<div class="panel"><h2>Approved — the assistant follows these (<?= count($appr) ?>)</h2>
<?php if ($appr === []): ?><p class="muted">None yet.</p><?php else: ?><table><tr><th>They say</th><th>We answer</th><th>Rule</th><th>Used</th><th></th></tr>
<?php foreach ($appr as $a): ?><tr><td><?= $e(mb_substr((string) $a['user_text'], 0, 120)) ?></td><td><?= $e(mb_substr((string) $a['good_reply'], 0, 160)) ?></td><td><?= $e((string) $a['rule_text']) ?></td><td><?= (int) $a['hits'] ?>×</td>
  <td><form method="post"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="example_status"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="status" value="retired"><button class="btn btn-ghost btn-sm" <?= $canManage ? '' : 'disabled' ?>>Retire</button></form></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
<div class="panel"><h2>New example by hand</h2>
  <form method="post" class="aim-form"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="example_save">
    <div><label>They ask</label><input name="user_text" required maxlength="2000"></div><div><label>We answer</label><textarea name="good_reply" rows="2" required></textarea></div>
    <div><label>Rule (optional)</label><input name="rule_text" maxlength="500"></div><input type="hidden" name="intent" value="general"><input type="hidden" name="language" value="">
    <div class="toolbar"><button class="btn" name="approve" value="1" <?= $canManage ? '' : 'disabled' ?>>✅ Save & approve</button></div></form>
</div>
<div class="panel"><h2>Feedback log (last 30)</h2>
<?php if ($fb === []): ?><p class="muted">No feedback yet.</p><?php else: ?><table><tr><th>When</th><th>Channel</th><th></th><th>They asked</th><th>We said</th><th>Note</th></tr>
<?php foreach ($fb as $f): ?><tr><td><?= $e(substr((string) $f['created_at'], 0, 16)) ?></td><td><?= $e((string) $f['channel']) ?></td><td><?= $f['verdict'] === 'up' ? '👍' : ($f['verdict'] === 'down' ? '👎' : '✏️') ?></td><td><?= $e(mb_substr((string) $f['user_text'], 0, 90)) ?></td><td><?= $e(mb_substr((string) $f['reply_text'], 0, 120)) ?></td><td><?= $e((string) $f['note']) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>

<?php elseif ($tab === 'memory'):
    $prof = $memPhone !== '' ? AiMemory::profile($memPhone) : null;
    $eps  = $memPhone !== '' ? AiMemory::recall($memPhone, 30) : [];
?>
<div class="panel"><h2>What the assistant remembers about a person</h2>
  <p class="muted">Memory is written by the register's own events (a booking, a payment, a cancellation, a correction) — never by the model — and is keyed by a hash of the number, so no phone numbers are stored here. Switch: memory <?= $sw('ai_memory_on') ?>.</p>
  <form method="get" class="toolbar"><input type="hidden" name="tab" value="memory"><input name="phone" value="<?= $e($memPhone) ?>" placeholder="Mobile number" inputmode="tel" style="padding:8px 10px;border:1px solid var(--line,#E2E9F4);border-radius:10px"> <button class="btn">Look up</button></form>
<?php if ($memPhone !== ''): ?>
  <?php if ($prof === null && $eps === []): ?><p class="muted">Nothing remembered for this number.</p><?php else: ?>
  <p><b>Profile:</b> <?= $e((string) ($prof['display_name'] ?? '—')) ?> · <?= (int) ($prof['trips'] ?? 0) ?> trip(s) · usual pickup <?= $e((string) ($prof['usual_pickup'] ?? '—')) ?> · direction <?= $e((string) ($prof['usual_direction'] ?? '—')) ?> · last trip <?= $e((string) ($prof['last_trip_date'] ?? '—')) ?> · last seen <?= $e((string) ($prof['last_seen'] ?? '—')) ?></p>
  <table><tr><th>When</th><th>Kind</th><th>Memory</th><th>Via</th></tr><?php foreach ($eps as $ep): ?><tr><td><?= $e(substr((string) $ep['happened_at'], 0, 16)) ?></td><td><?= $e((string) $ep['kind']) ?></td><td><?= $e((string) $ep['summary']) ?></td><td><?= $e((string) $ep['channel']) ?></td></tr><?php endforeach; ?></table>
  <pre class="mono" style="white-space:pre-wrap;background:var(--bg,#F6F8FC);padding:10px;border-radius:10px;margin-top:10px"><?= $e(AiMemory::brief($memPhone) ?: '(memory switch is off — the assistant reads nothing)') ?></pre>
  <form method="post" onsubmit="return confirm('Forget everything about this number?')"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="forget"><input type="hidden" name="phone" value="<?= $e($memPhone) ?>"><button class="btn btn-ghost" <?= $canManage ? '' : 'disabled' ?>>🗑 Forget this person</button></form>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php elseif ($tab === 'marketing'):
    $queue = Database::fetchAll("SELECT * FROM social_posts ORDER BY FIELD(status,'approved','draft','publishing','failed','published','cancelled'), publish_at DESC, id DESC LIMIT 60");
?>
<div class="panel"><h2>New post</h2>
  <p class="muted">Draft with the assistant from a topic (it never invents a price — fares and the phone come from Settings), read it, then approve. Publishing <?= $sw('social_publish_on') ?>, cap <?= (int) Settings::getInt('social_daily_cap', 6) ?>/day. Facebook + Instagram need meta_page_id / meta_page_token (a system-user token for your own Page needs no App Review); Telegram needs a bot in your channel.</p>
  <form method="post" class="aim-form" style="margin-bottom:10px"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="draft">
    <div style="display:flex;gap:8px"><input name="topic" placeholder="Topic — e.g. Dashain 2026: seats for 15–20 October, book early" value="<?= $e((string) ($_POST['topic'] ?? '')) ?>" maxlength="300" style="flex:1"><button class="btn btn-ghost">✨ Draft with AI</button></div></form>
  <form method="post" class="aim-form"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="post_create"><input type="hidden" name="topic" value="<?= $e((string) ($_POST['topic'] ?? '')) ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><div><label>Channel</label><select name="channel"><option value="facebook">Facebook Page</option><option value="instagram">Instagram (needs a picture)</option><option value="telegram">Telegram channel</option></select></div>
      <div><label>Publish at</label><input type="datetime-local" name="publish_at" value="<?= date('Y-m-d\TH:i', time() + 1800) ?>"></div></div>
    <div><label>Nepali</label><textarea name="caption_ne" rows="3"><?= $e($draft['ne'] ?? '') ?></textarea></div>
    <div><label>Hindi</label><textarea name="caption_hi" rows="3"><?= $e($draft['hi'] ?? '') ?></textarea></div>
    <div><label>English</label><textarea name="caption_en" rows="3"><?= $e($draft['en'] ?? '') ?></textarea></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><div><label>Picture path (under this site, e.g. /assets/img/bus-shg.webp)</label><input name="media_path" value="/assets/img/og-shg.png"></div><div><label>Link</label><input name="link_url" value="<?= $e(rtrim(APP_URL, '/')) ?>/"></div></div>
    <div class="toolbar"><button class="btn" <?= $canManage ? '' : 'disabled' ?>>💾 Save draft</button></div></form>
</div>
<div class="panel"><h2>Queue</h2>
<?php if ($queue === []): ?><p class="muted">No posts yet.</p><?php else: ?><table><tr><th>#</th><th>Channel</th><th>When</th><th>Status</th><th>Caption</th><th></th></tr>
<?php foreach ($queue as $q): ?><tr><td><?= (int) $q['id'] ?></td><td><?= $e((string) $q['channel']) ?><?= $q['kind'] === 'photo' ? ' 🖼' : '' ?></td><td><?= $e(substr((string) $q['publish_at'], 0, 16)) ?></td>
  <td><?= $e((string) $q['status']) ?><?= $q['error'] ? '<br><small style="color:#C53030">' . $e((string) $q['error']) . '</small>' : '' ?><?= $q['remote_id'] ? '<br><small class="mono">' . $e((string) $q['remote_id']) . '</small>' : '' ?></td>
  <td><?= $e(mb_substr(SocialPosts::caption($q), 0, 140)) ?></td>
  <td><?php if (in_array($q['status'], ['draft', 'failed'], true)): ?><form method="post" style="display:inline"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="post_status"><input type="hidden" name="id" value="<?= (int) $q['id'] ?>"><input type="hidden" name="status" value="approved"><button class="btn btn-sm" <?= $canManage ? '' : 'disabled' ?>>✅ Approve</button></form><?php endif; ?>
      <?php if (in_array($q['status'], ['draft', 'approved', 'failed'], true)): ?><form method="post" style="display:inline"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="post_status"><input type="hidden" name="id" value="<?= (int) $q['id'] ?>"><input type="hidden" name="status" value="cancelled"><button class="btn btn-ghost btn-sm" <?= $canManage ? '' : 'disabled' ?>>Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</div>
<?php endif; ?>
<?php admin_footer();
