<?php
/**
 * admin/social-posts.php — Facebook posts for our Page (Master v2 P6-8a/b,
 * 16 Nov 2026).
 *
 * Draft → Approve → the cron posts it at its time. Three tabs:
 *   Draft      write / edit / delete; "Draft from offer" (the running
 *              offer's caption + pamphlet) and "Draft from timetable"
 *              (the active routes' pickup times) only ever make a DRAFT
 *   Approved   waiting for its time, being sent, or failed (Meta's reason
 *              shown); "Back to draft" stops it
 *   Published  what went out, with the link to the post
 *
 * Nothing is posted while social_publish_on is off (Admin → Settings →
 * Marketing; default OFF). The Page token is the one the Messenger page
 * (admin/facebook.php) holds — this page never shows or changes it.
 *
 * Seeing: dashboard.view (the same as admin/facebook.php). Writing: the
 * Settings permission, the same as admin/facebook.php. Every write is
 * CSRF-guarded and audited (includes/socialposts.php).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/socialposts.php';
require_once INCLUDE_PATH . '/offercard.php';

$admin   = admin_boot('dashboard.view');
$adminId = (int) $admin['id'];
$canEdit = Auth::canManageSettings();
$e       = static fn($v): string => Security::e((string) $v);
$ready   = SocialPosts::ready();
$tabs    = ['draft' => 'ड्राफ्ट · Draft', 'approved' => 'स्वीकृत · Approved', 'published' => 'प्रकाशित · Published'];
$tab     = isset($tabs[(string) ($_GET['tab'] ?? '')]) ? (string) $_GET['tab'] : 'draft';
$editId  = max(0, (int) ($_GET['edit'] ?? 0));
$flash   = null;
$form    = null;     // what was typed, kept when a save is refused

$done = [
    'saved'     => 'ड्राफ्ट सेभ भयो · Draft saved.',
    'approved'  => 'स्वीकृत भयो — तोकिएको समयमा पोस्ट हुन्छ (पोस्ट गर्ने स्विच खुला भए) · Approved — it goes out at its time while publishing is switched on.',
    'drafted'   => 'ड्राफ्टमा फर्कियो — अब पोस्ट हुँदैन · Back to draft — it will not be posted.',
    'deleted'   => 'मेटाइयो · Deleted.',
    'offer'     => 'अफरबाट ड्राफ्ट बन्यो — जाँचेर Approve गर्नुहोस् · Draft made from the running offer — check it, then approve.',
    'timetable' => 'समय तालिकाबाट ड्राफ्ट बन्यो — जाँचेर Approve गर्नुहोस् · Draft made from the timetable — check it, then approve.',
];
if (isset($done[(string) ($_GET['done'] ?? '')])) {
    $flash = ['ok', $done[(string) $_GET['done']]];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('पेज धेरै बेर खुला थियो — फेरि प्रयास गर्नुहोस् · Session expired, please try again.');
        }
        if (!$canEdit) {
            throw new RuntimeException('Facebook पोस्ट बदल्न Settings अनुमति चाहिन्छ · Changing Facebook posts needs the Settings permission.');
        }
        $act = (string) ($_POST['action'] ?? '');
        $id  = (int) ($_POST['id'] ?? 0);
        $go  = static function (string $tab, string $done, int $edit = 0): never {
            header('Location: /admin/social-posts.php?tab=' . $tab . ($edit > 0 ? '&edit=' . $edit : '') . '&done=' . $done, true, 303);
            exit;
        };
        switch ($act) {
            case 'save':
                $in = [
                    'topic'      => (string) ($_POST['topic'] ?? ''),
                    'text'       => (string) ($_POST['text'] ?? ''),
                    'media_path' => (string) ($_POST['media_path'] ?? ''),
                    'link_url'   => (string) ($_POST['link_url'] ?? ''),
                    'publish_at' => (string) ($_POST['publish_at'] ?? ''),
                ];
                $form = $in + ['id' => $id];
                if ($id > 0) {
                    SocialPosts::update($id, $in, $adminId);
                } else {
                    $id = SocialPosts::create($in, $adminId);
                }
                $go('draft', 'saved');
            case 'approve':
                SocialPosts::setStatus($id, 'approved', $adminId);
                $go('approved', 'approved');
            case 'to_draft':
                SocialPosts::setStatus($id, 'draft', $adminId);
                $go('draft', 'drafted');
            case 'delete':
                SocialPosts::delete($id, $adminId);
                $go($tab, 'deleted');
            case 'from_offer':
                $go('draft', 'offer', SocialPosts::fromOffer($adminId));
            case 'from_timetable':
                $go('draft', 'timetable', SocialPosts::fromTimetable($adminId));
            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        $flash = ['bad', $ex->getMessage()];
    }
}

Settings::flush();
$on       = SocialPosts::enabled();
$hasToken = SocialPosts::pageToken() !== '';
$pageName = Settings::getString('fb_page_name', '');
$cap      = max(1, Settings::getInt('social_daily_cap', 6));
$offer    = OfferCard::offer();
$counts   = SocialPosts::counts();
$rows     = SocialPosts::listTab($tab);

/* the editor: a draft being edited, or what a refused save typed, or a new post */
$edit = null;
if ($ready && $editId > 0) {
    try {
        $r = SocialPosts::get($editId);
        if (in_array((string) $r['status'], ['draft', 'cancelled'], true)) {
            $edit = $r;
        } elseif ($flash === null) {
            $flash = ['bad', 'This post is ' . $r['status'] . ' — press “Back to draft” before editing.'];
        }
    } catch (Throwable $ex) {
        $flash ??= ['bad', $ex->getMessage()];
    }
}
$fv = [
    'id'         => (int) ($form['id'] ?? ($edit['id'] ?? 0)),
    'topic'      => (string) ($form['topic'] ?? ($edit['topic'] ?? '')),
    'text'       => (string) ($form['text'] ?? ($edit !== null ? SocialPosts::caption(['caption_ne' => $edit['caption_ne'], 'caption_hi' => $edit['caption_hi'], 'caption_en' => $edit['caption_en']]) : '')),
    'media_path' => (string) ($form['media_path'] ?? (($edit['media_path'] ?? '') !== '' ? '/' . $edit['media_path'] : '')),
    'link_url'   => (string) ($form['link_url'] ?? ($edit['link_url'] ?? '')),
    'publish_at' => (string) ($form['publish_at'] ?? ($edit !== null ? date('Y-m-d\TH:i', (int) strtotime((string) $edit['publish_at'])) : date('Y-m-d\TH:i', (int) (ceil((time() + 3600) / 1800) * 1800)))),
];

$pill = static function (string $status): string {
    $map = [
        'draft'      => ['sp-off', 'ड्राफ्ट · Draft'],
        'cancelled'  => ['sp-off', 'रोकिएको · Stopped'],
        'approved'   => ['sp-warn', 'स्वीकृत · Approved'],
        'publishing' => ['sp-warn', 'पठाउँदै · Sending'],
        'failed'     => ['sp-bad', 'असफल · Failed'],
        'published'  => ['sp-on', 'प्रकाशित · Published'],
    ];
    [$cls, $lbl] = $map[$status] ?? ['sp-off', $status];
    return '<span class="sp-pill ' . $cls . '">' . Security::e($lbl) . '</span>';
};
$when = static fn(?string $d): string => $d !== null && $d !== '' ? date('j M Y, g:i A', (int) strtotime($d)) : '—';

admin_header('FB posts', 'social-posts');
admin_page_head('Facebook पेजका पोस्ट — ड्राफ्ट, स्वीकृत, प्रकाशित · Posts for our Facebook Page: write a draft, approve it, and it goes out at its time.');
?>
<style>
.sp-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin:0 0 16px;display:grid;gap:10px;min-width:0}
.sp-card h2{margin:0;font-size:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.sp-note{font-size:13px;color:var(--mut);margin:0;line-height:1.55}
.sp-pill{font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;white-space:nowrap}
.sp-on{background:var(--ok-bg,rgba(23,138,80,.14));color:var(--ok,#178A50)} .sp-off{background:var(--soft,rgba(107,118,136,.14));color:var(--mut)}
.sp-warn{background:var(--warn-bg,rgba(183,121,31,.16));color:var(--warn,#B7791F)} .sp-bad{background:#f7dcdc;color:#8a1f1f}
.sp-form{display:grid;gap:10px}
.sp-form label{display:grid;gap:4px;font-size:12.5px;color:var(--mut)}
.sp-form input,.sp-form textarea{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font-size:14px;font-family:inherit}
.sp-form textarea{min-height:160px;resize:vertical}
.sp-two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (max-width:640px){.sp-two{grid-template-columns:1fr}}
.sp-acts{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.sp-acts form{display:inline}
.sp-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px}
.sp-tabs a{padding:8px 14px;border:1px solid var(--line);border-radius:999px;text-decoration:none;color:var(--ink);background:var(--card);font-weight:600;font-size:13.5px}
.sp-tabs a.on{background:var(--ink);color:var(--card);border-color:var(--ink)}
.sp-tabs .n{margin-left:6px;opacity:.75}
.sp-list{display:grid;gap:12px}
.sp-post{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;display:grid;gap:8px;min-width:0}
.sp-post-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:space-between}
.sp-post-head b{overflow-wrap:anywhere}
.sp-text{white-space:pre-wrap;word-break:break-word;font-size:13.5px;line-height:1.5;max-height:260px;overflow:auto;background:var(--soft,var(--head));border-radius:10px;padding:10px}
.sp-img{max-width:min(240px,100%);height:auto;border-radius:10px;border:1px solid var(--line)}
.sp-meta{font-size:12.5px;color:var(--mut);display:flex;gap:6px 14px;flex-wrap:wrap}
.sp-meta a{overflow-wrap:anywhere}
.sp-err{font-size:13px;background:#f7dcdc;color:#8a1f1f;border-radius:10px;padding:8px 10px;overflow-wrap:anywhere}
</style>

<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<div class="sp-card">
  <h2>स्थिति · Status
    <?php if ($on && $hasToken): ?><span class="sp-pill sp-on">● पोस्ट गर्ने खुला · Publishing ON</span>
    <?php elseif ($on): ?><span class="sp-pill sp-warn">ON, तर Page token छैन · ON, but no Page token</span>
    <?php else: ?><span class="sp-pill sp-off">पोस्ट गर्ने बन्द · Publishing OFF</span><?php endif; ?>
  </h2>
  <p class="sp-note">
    Page: <strong><?= $pageName !== '' ? $e($pageName) : '—' ?></strong> ·
    Page token: <strong><?= $hasToken ? 'saved (from Facebook Messenger AI)' : 'not saved' ?></strong> ·
    दिनमा बढीमा · Max a day: <strong><?= (int) $cap ?></strong>
  </p>
  <p class="sp-note">
    <?php if (!$on): ?>स्विच बन्द छ: स्वीकृत पोस्ट पनि कुर्छन्, Facebook मा केही जाँदैन · The switch <code>social_publish_on</code> is OFF — approved posts wait and nothing reaches Facebook.
      <?php if ($canEdit): ?><a href="/admin/settings.php">Settings → Marketing</a><?php endif; ?><br><?php endif; ?>
    पोस्ट गर्न Page token मा <code>pages_manage_posts</code> अनुमति चाहिन्छ; नभए पोस्ट “असफल” हुन्छ र कारण यहीँ देखिन्छ ·
    The Page token needs the <code>pages_manage_posts</code> permission; without it a post is marked failed with Meta’s reason, and nobody is messaged.
    <?php if (!$hasToken): ?><a href="/admin/facebook.php">Facebook Messenger AI →</a><?php endif; ?>
  </p>
</div>

<?php if (!$ready): ?>
  <div class="flash bad">पोस्टको टेबल यो सर्भरमा अझै छैन · The posts table is not on this server yet — run <code>database/upgrade-2026-11-16b-W6-facebook.sql</code>. Nothing is posted until then.</div>
<?php else: ?>

<?php if ($canEdit): ?>
<div class="sp-card">
  <h2>छिटो ड्राफ्ट · Quick drafts</h2>
  <p class="sp-note">यी बटनले ड्राफ्ट मात्र बनाउँछन् — कहिल्यै आफैं पोस्ट गर्दैनन् · These buttons only make a DRAFT; nothing is posted until you approve it.</p>
  <div class="sp-acts">
    <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="from_offer">
      <button class="btn ghost" type="submit" <?= $offer === null ? 'disabled' : '' ?>>🪔 अफरबाट ड्राफ्ट · Draft from offer</button></form>
    <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="from_timetable">
      <button class="btn ghost" type="submit">🕒 समय तालिकाबाट ड्राफ्ट · Draft from timetable</button></form>
  </div>
  <p class="sp-note"><?= $offer !== null
      ? 'चलिरहेको अफर · Running offer: <strong>' . $e($offer['title']) . '</strong> (' . $e($offer['code']) . ', ' . $e(OfferCard::amount($offer)) . ')'
      : 'अहिले कुनै अफर चलिरहेको छैन · No offer is running (Offers &amp; Discounts).' ?></p>
</div>

<div class="sp-card" id="editor">
  <h2><?= $fv['id'] > 0 ? 'ड्राफ्ट सच्याउनुहोस् · Edit draft #' . (int) $fv['id'] : 'नयाँ पोस्ट · New post' ?></h2>
  <form method="post" class="sp-form"><?= Security::csrfField() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $fv['id'] ?>">
    <label>शीर्षक (भित्री मात्र) · Title (for the office only)
      <input type="text" name="topic" maxlength="60" value="<?= $e($fv['topic']) ?>" placeholder="e.g. Tihar offer"></label>
    <label>पोस्टको लेख · Post text
      <textarea name="text" maxlength="<?= SocialPosts::MAX_TEXT ?>" required><?= $e($fv['text']) ?></textarea></label>
    <div class="sp-two">
      <label>फोटो (हाम्रै साइटको) · Picture from our own site (optional)
        <input type="text" name="media_path" maxlength="255" value="<?= $e($fv['media_path']) ?>" placeholder="/uploads/social/offer.png"></label>
      <label>लिङ्क · Link (optional)
        <input type="url" name="link_url" maxlength="255" value="<?= $e($fv['link_url']) ?>" placeholder="https://"></label>
    </div>
    <label>कहिले पोस्ट गर्ने · Publish at
      <input type="datetime-local" name="publish_at" value="<?= $e($fv['publish_at']) ?>" required></label>
    <p class="sp-note">बितिसकेको समय राखे Approve पछि अर्को पटक (१० मिनेटभित्र) जान्छ · A time already past goes out at the next run after approval (within 10 minutes), while publishing is on.</p>
    <div class="sp-acts">
      <button class="btn primary" type="submit">ड्राफ्ट सेभ · Save draft</button>
      <?php if ($fv['id'] > 0): ?><a class="btn ghost" href="/admin/social-posts.php?tab=draft">रद्द · Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<nav class="sp-tabs" aria-label="Post tabs">
  <?php foreach ($tabs as $k => $lbl): ?>
    <a class="<?= $tab === $k ? 'on' : '' ?>" href="/admin/social-posts.php?tab=<?= $k ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= $e($lbl) ?><span class="n"><?= (int) ($counts[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>

<div class="sp-list">
  <?php if ($rows === []): ?>
    <p class="sp-note">यहाँ केही छैन · Nothing here.</p>
  <?php endif; ?>
  <?php foreach ($rows as $r): $rid = (int) $r['id']; $st = (string) $r['status']; $remote = (string) ($r['remote_id'] ?? ''); ?>
    <article class="sp-post">
      <div class="sp-post-head">
        <b>#<?= $rid ?> · <?= $e((string) ($r['topic'] ?? '') !== '' ? $r['topic'] : 'Post') ?></b>
        <?= $pill($st) ?>
      </div>
      <div class="sp-text"><?= $e(SocialPosts::caption($r)) ?></div>
      <?php if ((string) ($r['media_path'] ?? '') !== ''): ?>
        <img class="sp-img" src="/<?= $e(ltrim((string) $r['media_path'], '/')) ?>" alt="" loading="lazy">
      <?php endif; ?>
      <div class="sp-meta">
        <span>पोस्ट समय · Publish at: <strong><?= $e($when((string) $r['publish_at'])) ?></strong></span>
        <?php if ($st === 'published'): ?><span>गयो · Went out: <strong><?= $e($when($r['published_at'] ?? null)) ?></strong></span><?php endif; ?>
        <?php if ((int) $r['attempts'] > 0 && $st !== 'published'): ?><span>प्रयास · Tries: <?= (int) $r['attempts'] ?></span><?php endif; ?>
        <?php if ($st === 'published' && preg_match('/^[0-9_]+$/', $remote) === 1): ?>
          <a href="https://www.facebook.com/<?= $e($remote) ?>" target="_blank" rel="noopener">Facebook मा हेर्नुहोस् · View on Facebook</a>
        <?php endif; ?>
      </div>
      <?php if ((string) ($r['error'] ?? '') !== ''): ?><div class="sp-err"><?= $e($r['error']) ?></div><?php endif; ?>
      <?php if ($canEdit && $st !== 'published' && $st !== 'publishing'): ?>
      <div class="sp-acts">
        <?php if (in_array($st, ['draft', 'cancelled'], true)): ?>
          <a class="btn ghost" href="/admin/social-posts.php?tab=draft&amp;edit=<?= $rid ?>#editor">सच्याउनुहोस् · Edit</a>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $rid ?>">
            <button class="btn ok" type="submit" onclick="return confirm('यो पोस्ट Facebook मा जाने गरी स्वीकृत गर्ने? · Approve this post for Facebook?')">स्वीकृत · Approve</button></form>
        <?php else: ?>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="to_draft"><input type="hidden" name="id" value="<?= $rid ?>">
            <button class="btn ghost" type="submit">ड्राफ्टमा फर्काउनुहोस् · Back to draft</button></form>
        <?php endif; ?>
        <?php if (in_array($st, ['draft', 'cancelled', 'failed'], true)): ?>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $rid ?>">
            <button class="btn ghost danger" type="submit" onclick="return confirm('यो ड्राफ्ट मेटाउने? · Delete this draft?')">मेटाउनुहोस् · Delete</button></form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<?php admin_footer(); ?>
