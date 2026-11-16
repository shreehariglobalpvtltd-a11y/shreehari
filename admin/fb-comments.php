<?php
/**
 * admin/fb-comments.php — the comments under our Facebook Page's posts
 * (16 Nov 2026, Master v2 P6-6b + P6-7b).
 *
 * cron/fb-comments.php reads them (only while fb_comments_on is on, the
 * default OFF) into fb_comments. Three tabs, newest first:
 *   Comments  what people wrote (not our own Page's comments)
 *   Flagged   complaints / abuse / angry comments — never answered by the
 *             AI, the office looks at them
 *   All       everything, our Page's own replies included
 * A reply written here is posted IN PUBLIC on the Page, under the comment's
 * thread (CSRF, audited fb.comment_reply). A suggested draft from the
 * WhatsApp FAQ desk (side-effect free) may be copied into the box; nothing
 * is ever posted without the office pressing Send.
 *
 * Seeing and replying: customers.view — the same as admin/messenger-inbox.php.
 * The comms strip (WaInbox::channelTabs, P6-9a) sits under the page head, so
 * WhatsApp | Messenger | Comments | Sent are one tap apart.
 * The Page token is the Messenger page's (admin/facebook.php): never shown
 * or changed here.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/fbcomments.php';
require_once INCLUDE_PATH . '/wainbox.php';       // P6-9a: the WhatsApp | Messenger | Comments | Sent strip

$admin   = admin_boot('customers.view');
$adminId = (int) $admin['id'];
$e       = static fn($v): string => Security::e((string) $v);
$tabs    = ['comments' => 'टिप्पणी · Comments', 'flagged' => 'फ्ल्याग · Flagged', 'all' => 'सबै · All'];
$tab     = isset($tabs[(string) ($_GET['tab'] ?? '')]) ? (string) $_GET['tab'] : 'comments';
$openId  = max(0, (int) ($_GET['c'] ?? 0));
$flash   = null;
$typed   = '';

$done = [
    'sent'    => 'जवाफ Facebook मा गयो · Your reply is posted on the Page (public).',
    'recheck' => 'अर्को पटक (१० मिनेटभित्र) फेरि जाँचिन्छ · Meta will be asked again at the next run (within 10 minutes).',
];
if (isset($done[(string) ($_GET['done'] ?? '')])) {
    $flash = ['ok', $done[(string) $_GET['done']]];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('पेज धेरै बेर खुला थियो — फेरि प्रयास गर्नुहोस् · Session expired, please try again.');
        }
        $act = (string) ($_POST['action'] ?? '');
        if ($act === 'reply') {
            $cid   = (int) ($_POST['id'] ?? 0);
            $typed = (string) ($_POST['text'] ?? '');
            $r = FbComments::officeReply($cid, $typed, $adminId);
            if ($r['ok']) {
                // Post / redirect / get: a page refresh must never post the reply twice.
                header('Location: /admin/fb-comments.php?tab=' . rawurlencode($tab) . '&done=sent#c' . $cid, true, 303);
                exit;
            }
            $openId = $cid;
            $flash  = ['bad', $r['why']];
        } elseif ($act === 'recheck') {
            FbComments::clearError($adminId);
            header('Location: /admin/fb-comments.php?tab=' . rawurlencode($tab) . '&done=recheck', true, 303);
            exit;
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        $flash = ['bad', $ex->getMessage()];
    }
}

$ready    = FbComments::ready();
$on       = FbComments::on();
$aiOn     = FbComments::aiOn();
$hasToken = FbComments::token() !== '';
$pageName = Settings::getString('fb_page_name', '');
$state    = FbComments::state();
$counts   = FbComments::counts();
$rows     = $ready ? FbComments::listTab($tab) : [];
$canSet   = Auth::can('dashboard.view');          // admin/facebook.php boots with dashboard.view

$when = static fn(?string $d): string => $d !== null && $d !== '' ? date('j M, g:i A', (int) strtotime($d)) : '—';
$pill = static function (array $r): string {
    if ((int) $r['is_page'] === 1) {
        return '<span class="fc-pill fc-page">हाम्रो पेज · Our Page</span>';
    }
    [$cls, $lbl] = match ((string) $r['status']) {
        'replied' => ['fc-on', 'जवाफ गयो · Replied'],
        'hidden'  => ['fc-off', 'लुकाइएको · Hidden on FB'],
        'flagged' => ['fc-bad', 'फ्ल्याग · Flagged'],
        default   => ['fc-warn', 'नयाँ · New'],
    };
    return '<span class="fc-pill ' . $cls . '">' . Security::e($lbl) . '</span>';
};

admin_header('FB comments', 'fb-comments');
admin_page_head('Facebook पेजका पोस्टमा आएका कमेन्ट — हेर्ने र सार्वजनिक जवाफ दिने · Comments under our Facebook Page posts: read them, and reply in public.');
echo WaInbox::channelTabs('comments');
?>
<style>
.fc-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin:0 0 16px;display:grid;gap:10px;min-width:0}
.fc-card h2{margin:0;font-size:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.fc-note{font-size:13px;color:var(--mut);margin:0;line-height:1.55;overflow-wrap:anywhere}
.fc-pill{font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;white-space:nowrap}
.fc-on{background:var(--ok-bg,rgba(23,138,80,.14));color:var(--ok,#178A50)} .fc-off{background:var(--soft,rgba(107,118,136,.14));color:var(--mut)}
.fc-warn{background:var(--warn-bg,rgba(183,121,31,.16));color:var(--warn,#B7791F)} .fc-bad{background:#f7dcdc;color:#8a1f1f}
.fc-page{background:#dbeafe;color:#1e3a8a}
.fc-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px}
.fc-tabs a{padding:8px 14px;border:1px solid var(--line);border-radius:999px;text-decoration:none;color:var(--ink);background:var(--card);font-weight:600;font-size:13.5px}
.fc-tabs a.on{background:var(--ink);color:var(--card);border-color:var(--ink)}
.fc-tabs .n{margin-left:6px;opacity:.75}
.fc-list{display:grid;gap:12px}
.fc-item{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;display:grid;gap:8px;min-width:0}
.fc-item.page{background:var(--soft,var(--head))}
.fc-item.open{border-color:var(--blue,#1f4fe0);box-shadow:0 0 0 2px rgba(31,79,224,.15)}
.fc-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:space-between}
.fc-head b{overflow-wrap:anywhere}
.fc-msg{white-space:pre-wrap;word-break:break-word;font-size:14px;line-height:1.5}
.fc-meta{font-size:12.5px;color:var(--mut);display:flex;gap:4px 12px;flex-wrap:wrap}
.fc-meta a{overflow-wrap:anywhere}
.fc-reply{font-size:13px;background:#dbeafe;color:#1e3a8a;border-radius:10px;padding:8px 10px;white-space:pre-wrap;word-break:break-word}
.fc-err{font-size:13px;background:#f7dcdc;color:#8a1f1f;border-radius:10px;padding:8px 10px;overflow-wrap:anywhere}
.fc-flag{font-size:12.5px;background:#f7dcdc;color:#8a1f1f;font-weight:600;border-radius:10px;padding:6px 10px;overflow-wrap:anywhere}
.fc-form{display:grid;gap:8px}
.fc-form textarea{width:100%;box-sizing:border-box;min-height:90px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font-size:14px;font-family:inherit;resize:vertical}
.fc-public{font-size:13px;font-weight:700;background:var(--warn-bg,rgba(183,121,31,.16));color:var(--warn,#B7791F);border-radius:10px;padding:8px 10px}
.fc-sug{font-size:13px;border:1px dashed var(--line);border-radius:10px;padding:8px 10px;display:grid;gap:6px}
.fc-sug pre{margin:0;white-space:pre-wrap;word-break:break-word;font-family:inherit;max-height:160px;overflow:auto}
.fc-acts{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
</style>

<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<div class="fc-card">
  <h2>स्थिति · Status
    <?php if ($on && $hasToken): ?><span class="fc-pill fc-on">● कमेन्ट पढ्ने खुला · Reading ON</span>
    <?php elseif ($on): ?><span class="fc-pill fc-warn">ON, तर Page token छैन · ON, but no Page token</span>
    <?php else: ?><span class="fc-pill fc-off">कमेन्ट पढ्ने बन्द · Reading OFF</span><?php endif; ?>
    <span class="fc-pill <?= $aiOn ? 'fc-on' : 'fc-off' ?>"><?= $aiOn ? 'AI जवाफ खुला · AI replies ON' : 'AI जवाफ बन्द · AI replies OFF' ?></span>
  </h2>
  <p class="fc-note">
    Page: <strong><?= $pageName !== '' ? $e($pageName) : '—' ?></strong> ·
    Page token: <strong><?= $hasToken ? 'saved (from Facebook Messenger AI)' : 'not saved' ?></strong> ·
    नयाँ · New: <strong><?= (int) $counts['new'] ?></strong> · फ्ल्याग · Flagged: <strong><?= (int) $counts['flagged'] ?></strong>
  </p>
  <p class="fc-note">
    <?php if (!$on): ?>स्विच <code>fb_comments_on</code> बन्द छ: Facebook बाट केही पढिँदैन, केही पोस्ट हुँदैन · The switch <code>fb_comments_on</code> is OFF — nothing is read from or posted to Facebook.<br><?php endif; ?>
    AI ले भाडा सोध्नेलाई भाडा पेजको लिङ्क, बुक गर्ने तरिका सोध्नेलाई बुकिङ लिङ्क + अफिस नम्बर मात्र लेख्छ (<code>fb_comment_ai_on</code>, थ्रेडमा एक पटक, घण्टामा २० सम्म); गुनासो / अपशब्दमा कहिल्यै जवाफ दिँदैन — फ्ल्याग मात्र ·
    With <code>fb_comment_ai_on</code>, the AI writes only one fixed line: the fare page for a fare question, the booking link + office number for a how-to-book question (once per thread, max 20 an hour). It never answers a complaint or abuse — those are flagged for you.
    <?php if ($canSet): ?><a href="/admin/settings.php">Settings</a><?php endif; ?>
  </p>
  <p class="fc-note">Page token मा <code>pages_read_engagement</code> (र नामका लागि <code>pages_read_user_content</code>) पढ्न, <code>pages_manage_engagement</code> जवाफ दिन चाहिन्छ · The Page token needs <code>pages_read_engagement</code> (and <code>pages_read_user_content</code> for names) to read, and <code>pages_manage_engagement</code> to reply.
    <?php if (!$hasToken && $canSet): ?><a href="/admin/facebook.php">Facebook Messenger AI →</a><?php endif; ?></p>
  <?php if ((string) ($state['error'] ?? '') !== ''): ?>
    <div class="fc-err">
      Meta ले रोक्यो · Meta refused (<?= $e((string) ($state['where'] ?? '')) ?>, <?= $e($when((string) ($state['at'] ?? ''))) ?><?= (int) ($state['code'] ?? 0) > 0 ? ', code ' . (int) $state['code'] : '' ?>):
      <?= $e((string) $state['error']) ?><br>
      Meta app मा माथिका अनुमति थपेर नयाँ Page token राख्नुहोस्; ६ घण्टापछि आफैं फेरि जाँचिन्छ · Add the permissions above in the Meta app and save a fresh Page token; it is checked again by itself after 6 hours.
      <form method="post" style="margin-top:6px"><?= Security::csrfField() ?><input type="hidden" name="action" value="recheck">
        <button class="btn ghost" type="submit">अहिले फेरि जाँच्ने · Check again</button></form>
    </div>
  <?php endif; ?>
</div>

<?php if (!$ready): ?>
  <div class="flash bad">कमेन्टको टेबल यो सर्भरमा अझै छैन · The comments table is not on this server yet — run <code>database/upgrade-2026-11-16c-X4-fb-comments.sql</code>. Nothing is read or posted until then.</div>
<?php else: ?>

<nav class="fc-tabs" aria-label="Comment tabs">
  <?php foreach ($tabs as $k => $lbl): ?>
    <a class="<?= $tab === $k ? 'on' : '' ?>" href="/admin/fb-comments.php?tab=<?= $k ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= $e($lbl) ?><span class="n"><?= (int) ($counts[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>

<div class="fc-list">
  <?php if ($rows === []): ?>
    <p class="fc-note"><?= $on ? 'यहाँ केही छैन · Nothing here yet.' : 'केही छैन — स्विच बन्द छ · Nothing here — reading is switched off.' ?></p>
  <?php endif; ?>
  <?php foreach ($rows as $r):
      $rid    = (int) $r['id'];
      $isPage = (int) $r['is_page'] === 1;
      $isOpen = $rid === $openId && !$isPage;
      $post   = (string) $r['post_id'];
      $kind   = (string) ($r['reply_kind'] ?? '');
  ?>
    <article class="fc-item<?= $isPage ? ' page' : '' ?><?= $isOpen ? ' open' : '' ?>" id="c<?= $rid ?>">
      <div class="fc-head">
        <b><?= $e((string) ($r['from_name'] ?? '') !== '' ? $r['from_name'] : ($isPage ? ($pageName !== '' ? $pageName : 'Our Page') : 'Facebook user')) ?><?= (string) ($r['parent_id'] ?? '') !== '' ? ' <span class="fc-note">↳ reply</span>' : '' ?></b>
        <?= $pill($r) ?>
      </div>
      <div class="fc-msg"><?= $e((string) ($r['message'] ?? '') !== '' ? $r['message'] : '[sticker / photo]') ?></div>
      <?php if ((string) $r['status'] === 'flagged' && (string) ($r['flagged_reason'] ?? '') !== ''): ?>
        <div class="fc-flag">⚑ <?= $e($r['flagged_reason']) ?> — AI ले जवाफ दिँदैन · the AI never answers this</div>
      <?php endif; ?>
      <div class="fc-meta">
        <span><?= $e($when($r['created_time'] ?? null)) ?></span>
        <?php if ((string) ($r['post_text'] ?? '') !== ''): ?><span>पोस्ट · Post: “<?= $e(mb_substr((string) $r['post_text'], 0, 80)) ?>”</span><?php endif; ?>
        <?php if (FbComments::validId($post)): ?><a href="https://www.facebook.com/<?= $e($post) ?>" target="_blank" rel="noopener">Facebook मा हेर्ने · View post</a><?php endif; ?>
      </div>
      <?php if ((string) ($r['our_reply_id'] ?? '') !== '' || (string) ($r['reply_text'] ?? '') !== ''): ?>
        <div class="fc-reply"><?= $e((string) ($r['reply_text'] ?? '')) ?>
          <br><small><?= $kind === 'auto' ? '🤖 AI' : '👤 ' . $e((string) ($r['replied_by_name'] ?? 'Office')) ?> · <?= $e($when($r['replied_at'] ?? null)) ?><?= (string) ($r['our_reply_id'] ?? '') === '' ? ' · पठाइएन · not posted' : '' ?></small></div>
      <?php endif; ?>
      <?php if ((string) ($r['reply_error'] ?? '') !== ''): ?><div class="fc-err">Facebook: <?= $e($r['reply_error']) ?></div><?php endif; ?>

      <?php if (!$isPage && $on && $hasToken): ?>
        <?php if (!$isOpen): ?>
          <div class="fc-acts"><a class="btn ghost" href="/admin/fb-comments.php?tab=<?= $e($tab) ?>&amp;c=<?= $rid ?>#c<?= $rid ?>">जवाफ लेख्ने · Reply</a></div>
        <?php else:
            $sug = FbComments::suggest((string) ($r['message'] ?? '')); ?>
          <form method="post" class="fc-form" action="/admin/fb-comments.php?tab=<?= $e($tab) ?>&amp;c=<?= $rid ?>#c<?= $rid ?>"
                onsubmit="return confirm('यो जवाफ Facebook पेजमा सबैले देख्नेगरी जान्छ। पठाउने? · This reply is PUBLIC on the Page. Post it?')">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?= $rid ?>">
            <div class="fc-public">⚠ यो जवाफ पेजमा सार्वजनिक हुन्छ — सबैले देख्छन् · This reply is public on the Page — everyone can see it.</div>
            <?php if ($sug !== null): ?>
              <div class="fc-sug"><span class="fc-note">💡 सुझाव (WhatsApp FAQ बाट) — पढेर सच्याउनुहोस् · Suggested from the WhatsApp FAQ — read and edit before posting:</span>
                <pre id="fcSug<?= $rid ?>"><?= $e($sug) ?></pre>
                <div><button class="btn ghost" type="button" data-sug="fcSug<?= $rid ?>" data-to="fcText<?= $rid ?>">यही राख्ने · Use this</button></div></div>
            <?php endif; ?>
            <label class="fc-note">कार्यालयको तर्फबाट जवाफ · Reply as the Page
              <textarea id="fcText<?= $rid ?>" name="text" maxlength="<?= FbComments::MAX_REPLY ?>" required placeholder="नमस्ते, ..."><?= $e($typed) ?></textarea></label>
            <div class="fc-acts">
              <button class="btn primary" type="submit">Facebook मा पोस्ट गर्ने · Post reply</button>
              <a class="btn ghost" href="/admin/fb-comments.php?tab=<?= $e($tab) ?>#c<?= $rid ?>">रद्द · Cancel</a>
            </div>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>
<script>
document.addEventListener('click', function (ev) {
  var b = ev.target.closest ? ev.target.closest('[data-sug]') : null;
  if (!b) { return; }
  var s = document.getElementById(b.getAttribute('data-sug'));
  var t = document.getElementById(b.getAttribute('data-to'));
  if (s && t) { t.value = s.textContent; t.focus(); }
});
</script>

<?php endif; ?>

<?php admin_footer(); ?>
