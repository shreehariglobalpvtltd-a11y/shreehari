<?php
/**
 * admin/messenger-inbox.php — the Facebook Messenger inbox (16 Nov 2026,
 * Master v2 P6-6a).
 *
 * The AI already answers the Page's Messenger by itself
 * (includes/fbmessenger.php). This page lets the office SEE those
 * conversations — what the person wrote, what the assistant or a colleague
 * answered — and answer by hand. Meta lets a Page reply freely only within
 * 24 hours of the person's last message, so the reply box is open only
 * then; nothing is sent outside it (no message tag, no paid route). Every
 * reply is audited (fb.inbox_reply).
 *
 * Reads fb_inbound (database/upgrade-2026-11-16-fb-inbound.sql). Seeing it
 * and replying: customers.view — the same as the WhatsApp inbox.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/fbmessenger.php';
require_once INCLUDE_PATH . '/wainbox.php';

$admin   = admin_boot('customers.view');
$adminId = (int) $admin['id'];
$e       = static fn($v): string => Security::e((string) $v);
$open    = (string) ($_GET['p'] ?? '');
$open    = FbMessenger::validPsid($open) ? $open : '';
$flash   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('पेज धेरै बेर खुला थियो — फेरि प्रयास गर्नुहोस् · Session expired, please try again.');
        }
        $who = (string) ($_POST['psid'] ?? '');
        if ((string) ($_POST['action'] ?? '') === 'reply' && FbMessenger::validPsid($who)) {
            $r = FbMessenger::inboxReply($who, (string) ($_POST['text'] ?? ''), $adminId);
            $flash = $r['ok'] ? ['ok', 'पठाइयो · Sent on Messenger.'] : ['bad', $r['why']];
            $open = $who;
        }
    } catch (Throwable $ex) {
        $flash = ['bad', $ex->getMessage()];
    }
}

$ready   = FbMessenger::logReady();
$live    = FbMessenger::enabled();
$canSet  = Auth::can('dashboard.view');          // admin/facebook.php boots with dashboard.view
$canDesk = Auth::can('support.view');

admin_header('Messenger Inbox', 'messenger-inbox');
admin_page_head('Facebook Messenger chats to the Page, with what the AI answered — reply by hand within 24 hours of the person\'s last message.');
?>
<style>
.mi-grid{display:grid;grid-template-columns:minmax(260px,360px) 1fr;gap:14px;align-items:start}
@media (max-width:820px){.mi-grid{grid-template-columns:1fr}}
.mi-list{display:grid;gap:6px;max-height:70vh;overflow:auto}
.mi-row{display:grid;gap:2px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:var(--card);text-decoration:none;color:inherit;min-width:0}
.mi-row.on{border-color:var(--blue);box-shadow:0 0 0 2px rgba(31,79,224,.15)}
.mi-row b{display:flex;justify-content:space-between;gap:8px;font-size:14px}
.mi-row span{font-size:12.5px;color:var(--mut);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mi-badge{background:#dc2626;color:#fff;border-radius:999px;padding:0 7px;font-size:11.5px;line-height:18px}
.mi-open{color:#15803d;font-size:11.5px;font-weight:700}
.mi-chat{display:grid;gap:8px;max-height:60vh;overflow:auto;padding:4px}
.mi-msg{max-width:min(520px,88%);padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-break:break-word;font-size:13.5px}
.mi-msg.in{background:var(--soft);border:1px solid var(--line);justify-self:start}
.mi-msg.out{background:#dbeafe;color:#1e3a8a;justify-self:end}
.mi-msg.out.failed{background:#fee2e2;color:#7f1d1d}
.mi-msg small{display:block;margin-top:4px;font-size:11px;opacity:.75}
.mi-reply{display:grid;gap:8px;margin-top:10px}
.mi-reply textarea{width:100%;min-height:70px}
.mi-head{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.mi-head h2{margin:0;min-width:0;overflow-wrap:anywhere}
</style>

<?php if (method_exists('WaInbox', 'channelTabs')): ?>
  <?= WaInbox::channelTabs('messenger') ?>
<?php else: ?>
  <p><a class="btn btn-ghost btn-sm" href="/admin/wa-inbox.php">💬 WhatsApp inbox</a></p>
<?php endif; ?>

<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<?php if (!$ready): ?>
  <div class="panel"><div class="panel-body"><p class="muted" style="margin:0">
    Messenger chats are not recorded on this server yet — the table <code>fb_inbound</code> is missing
    (database/upgrade-2026-11-16-fb-inbound.sql). The AI keeps answering on Messenger as before.
    · यो सर्भरमा Messenger कुराकानी अझै राखिएको छैन।</p></div></div>
<?php else:
    if (!$live): ?>
  <div class="flash bad">Messenger AI बन्द छ · Messenger AI is off or has no Page token — new messages are recorded only while it is on.
    <?php if ($canSet): ?><a href="/admin/facebook.php">Facebook Messenger AI →</a><?php endif; ?></div>
<?php endif;
    $threads = FbMessenger::threads($adminId);
    $unread  = array_sum(array_column($threads, 'unread'));
    if ($open === '' && $threads !== []) {
        $open = $threads[0]['psid'];
    }
    $chat   = $open !== '' ? FbMessenger::thread($open) : [];
    $winOn  = $open !== '' && FbMessenger::windowOpen($open);
    $phone  = $open !== '' ? FbMessenger::provenPhone($open) : '';
    FbMessenger::markSeen($adminId);
    $label  = static fn(string $psid): string => 'Messenger · …' . substr($psid, -6);
?>
  <div class="mi-grid">
    <div class="panel"><h2>पछिल्लो ७ दिन · Last 7 days<?= $unread > 0 ? ' · <span class="mi-badge">' . (int) $unread . ' new</span>' : '' ?></h2>
      <div class="panel-body mi-list">
        <?php if ($threads === []): ?><p class="muted" style="margin:0">Nobody wrote on Messenger in the last 7 days. · कसैले लेखेको छैन।</p><?php endif; ?>
        <?php foreach ($threads as $t): ?>
          <a class="mi-row <?= $t['psid'] === $open ? 'on' : '' ?>" href="?p=<?= $e(rawurlencode($t['psid'])) ?>">
            <b><span style="color:inherit;font-size:14px"><?= $e($label($t['psid'])) ?><?= $t['phone'] !== '' ? ' · +' . $e($t['phone']) : '' ?></span>
              <?php if ($t['unread'] > 0): ?><span class="mi-badge"><?= (int) $t['unread'] ?></span><?php endif; ?></b>
            <span><?= $e($t['last_text'] !== '' ? $t['last_text'] : '—') ?></span>
            <span><?= $e(substr($t['last_at'], 0, 16)) ?> · <?= (int) $t['msgs'] ?> msg<?= $t['open'] ? ' · <b class="mi-open">24 h खुला · open</b>' : '' ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="panel">
      <?php if ($open === ''): ?>
        <div class="panel-body"><p class="muted" style="margin:0">Choose a conversation. · कुराकानी छान्नुहोस्।</p></div>
      <?php else: ?>
        <div class="panel-body mi-head">
          <h2><?= $e($label($open)) ?></h2>
          <?php if ($phone !== ''): ?><span class="pill st-ok">+<?= $e($phone) ?> · प्रमाणित · proven</span>
            <a class="btn btn-ghost btn-sm" href="/admin/customers.php?q=<?= $e(substr($phone, -10)) ?>">Customer 360</a>
          <?php else: ?><span class="pill st-muted">नम्बर छैन · no number proven</span><?php endif; ?>
          <?php if ($canDesk): ?><a class="btn btn-ghost btn-sm" href="/admin/support-inbox.php?q=<?= $e(rawurlencode($open)) ?>">Help desk</a><?php endif; ?>
        </div>
        <div class="panel-body">
          <div class="mi-chat" id="miChat">
            <?php if ($chat === []): ?><p class="muted">पछिल्लो ७ दिनमा सन्देश छैन · No messages in the last 7 days.</p><?php endif; ?>
            <?php foreach ($chat as $m):
                $who = $m['dir'] === 'out'
                    ? ($m['purpose'] === 'inbox_reply' ? 'कार्यालय · Office' . ($m['by'] !== '' ? ' (' . $m['by'] . ')' : '') : 'AI') . ' · ' . $m['status']
                    : $m['kind']; ?>
              <div class="mi-msg <?= $m['dir'] === 'out' ? 'out' : 'in' ?><?= $m['dir'] === 'out' && str_starts_with($m['status'], 'failed') ? ' failed' : '' ?>"><?= $e(trim($m['text'] !== '' ? $m['text'] : '[' . $m['kind'] . ']')) ?><small><?= $e(substr($m['at'], 0, 16)) ?> · <?= $e($who) ?></small></div>
            <?php endforeach; ?>
          </div>
          <?php if ($winOn && $live): ?>
          <form method="post" class="mi-reply" action="?p=<?= $e(rawurlencode($open)) ?>">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="reply"><input type="hidden" name="psid" value="<?= $e($open) ?>">
            <label>कार्यालयको तर्फबाट जवाफ · Reply as the office (free within 24 h of their last message)
              <textarea name="text" maxlength="1000" required placeholder="नमस्ते, ..."></textarea></label>
            <p><button class="btn" type="submit">Messenger मा पठाउने · Send</button></p>
          </form>
          <?php elseif (!$live): ?>
            <p class="muted">Replies need Messenger AI on with a Page token. · जवाफ पठाउन Messenger AI चालु हुनुपर्छ।</p>
          <?php else: ?>
            <p class="muted">२४ घण्टाको झ्याल बन्द छ — उहाँले फेरि लेखेपछि मात्र जवाफ जान्छ · Their 24-hour window is closed: a reply can go only after they write again.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <script>(function(){var c=document.getElementById('miChat');if(c){c.scrollTop=c.scrollHeight;}})();</script>
<?php endif; ?>
<?php
admin_footer();
