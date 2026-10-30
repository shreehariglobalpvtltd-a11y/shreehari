<?php
/**
 * admin/home.php — "🏠 गृह · Home", the ONE dashboard (30 Oct 2026).
 *
 * Owner: "ONE dashboard — agent, admin, staff sabai euta dashboard bhitra,
 * khali kholda pharak. Counter le counter ko kam, agent le agent ko kam,
 * sabai le ticket katna milos, admin le admin ko kam. Euta mobile ma 2-3 ID
 * bhaye sahi ma jaos."
 *
 * Every sign-in lands here (Auth::homeUrl(), admin/login.php — a next= deep
 * link still wins). Same page for everyone, opened differently:
 *   - who you are: photo, name, role, ID number, card status, My ID card,
 *     Switch ID (only when your own mobile carries another active ID),
 *     Admin panel (dashboard.view)
 *   - your numbers: an agent's money + today's sales, a counter's drawer,
 *     the office's "needs you" list — never a company figure to a field role
 *   - big tiles in your role's order, the rest under "＋ थप काम · More"
 * Each tile carries the check its page boots with (includes/homeboard.php),
 * so nothing here opens a door that was shut before.
 *
 * Switch ID: signs this ID out and opens the one door on the same mobile —
 * the other ID's own password, the WhatsApp code or "hi" (then "which ID?").
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/homeboard.php';
require_once INCLUDE_PATH . '/teamportal.php';
if (is_file(INCLUDE_PATH . '/agentpro.php')) {
    require_once INCLUDE_PATH . '/agentpro.php';
}
$admin = admin_boot();
$me    = (int) ($admin['id'] ?? 0);
$e     = static fn($v): string => Security::e((string) ($v ?? ''));
$flash = null;

/* ---- Switch ID: out of this one, into the door with the same mobile ready ---- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'यो पेज धेरै बेर खुला थियो — फेरि प्रयास गर्नुहोस् · This page was open too long, please try again.'];
    } elseif ((string) ($_POST['action'] ?? '') === 'switch') {
        $row = Database::fetch('SELECT id, phone FROM admins WHERE id = :i', ['i' => $me]);
        $p10 = TeamPortal::phone10((string) ($row['phone'] ?? ''));
        if ($p10 === '' || HomeBoard::otherIds($me) === 0) {
            $flash = ['bad', 'यो ID मा अर्को ID भएको मोबाइल छैन — कार्यालयलाई भन्नुहोस् · No other ID on this mobile — ask the office.'];
        } else {
            $intl = TeamPortal::intlFor($p10, $row);
            Logger::audit('admin.switch', 'admin', (string) $me, null, null, 'Switch ID: signed out to open another ID on the same mobile');
            Auth::adminLogout();
            $_SESSION['one_door'] = ['kind' => 'mobile', 'phone' => $p10, 'cc' => str_starts_with($intl, '977') ? '977' : '91', 'id' => '', 't' => time()];
            Response::redirect('admin/login.php?step=mobile');
        }
    }
}
if ($flash === null && is_string($_SESSION['home_flash'] ?? null)) {
    $flash = ['ok', (string) $_SESSION['home_flash']];
}
unset($_SESSION['home_flash']);

$m = null;
if (class_exists('AgentPro') && AgentPro::available()) {
    try {
        $m = AgentPro::member($me, false);
    } catch (Throwable $ex) {
        $m = null;
    }
}
$office = HomeBoard::isOffice();
$counts = [];
if ($office && class_exists('AgentPro')) {
    $counts = AgentPro::counts();   // company numbers: the office only
}
$tiles  = HomeBoard::tiles($admin, $m, $counts);
$strip  = HomeBoard::strip($admin, $counts);
$others = (string) ($admin['role'] ?? '') === 'viewer' ? 0 : HomeBoard::otherIds($me);

$name   = trim((string) ($m['name'] ?? ($admin['full_name'] ?? ''))) ?: (string) ($admin['username'] ?? '');
$first  = explode(' ', $name)[0] ?? $name;
$roleL  = (string) ($m['roleLabel'] ?? ucfirst((string) ($admin['role'] ?? '')));
$idNo   = (string) ($m['idNo'] ?? '');
$status = (string) ($m['status'] ?? '');
$chip   = match ($status) {
    'valid'   => ['ok', '✅ मान्य · Valid'],
    'expired' => ['warn', '⏳ म्याद सकियो · Expired'],
    'suspended', 'revoked', 'inactive' => ['bad', '⛔ रोकिएको · Suspended'],
    default   => null,
};
$photo  = (string) ($m['photoUrl'] ?? '');
$viaOtp = !empty($admin['via_otp']) && empty($admin['must_change_pw']);   // same rule as the top bar (_guard.php)
$tile = static function (array $t) use ($e): string {
    return '<a class="hb-tile" href="' . $e($t['href']) . '"><i aria-hidden="true">' . $e($t['icon']) . '</i>'
         . '<b>' . $e($t['ne']) . '</b><small>' . $e($t['en']) . '</small>'
         . ($t['badge'] > 0 ? '<span class="hb-badge">' . (int) $t['badge'] . '</span>' : '') . '</a>';
};

admin_header('🏠 गृह · Home', 'home.php');
echo '<link rel="stylesheet" href="/assets/agentpro/admin.css?v=' . (int) @filemtime(ASSET_PATH . '/agentpro/admin.css') . '">';
?>
<style>
.hb{max-width:880px;margin:0 auto}
.hb-me{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:16px;border:1px solid var(--line);border-radius:16px;background:var(--card);margin-bottom:12px}
.hb-av{width:58px;height:58px;border-radius:50%;object-fit:cover;flex:0 0 auto;border:2px solid #0054A8}
.hb-ini{width:58px;height:58px;border-radius:50%;flex:0 0 auto;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:900;color:#fff;background:linear-gradient(135deg,#1F6CC8,#0A2A8F)}
.hb-who{flex:1 1 200px;min-width:0}
.hb-who h2{margin:0;font-size:20px;font-weight:800}
.hb-who p{margin:3px 0 0;color:var(--mut);font-size:13.5px}
.hb-chip{display:inline-block;margin-top:6px;padding:3px 9px;border-radius:99px;font-size:12px;font-weight:800}
.hb-chip.ok{background:#DEF4E4;color:#14602E}.hb-chip.warn{background:#FFF4E0;color:#7A4A00}.hb-chip.bad{background:#F7DCDC;color:#8A1F1F}
.hb-acts{display:flex;gap:8px;flex-wrap:wrap;width:100%}
.hb-acts a,.hb-acts button{display:inline-flex;align-items:center;gap:6px;min-height:44px;padding:0 14px;border-radius:11px;border:1.5px solid var(--line);background:var(--card);color:inherit;font:inherit;font-size:13.5px;font-weight:700;text-decoration:none;cursor:pointer}
.hb-acts .pri{border-color:#0054A8;color:#0054A8}
.hb-strip{display:flex;flex-direction:column;gap:8px;margin:0 0 14px}
.hb-strip a{display:block;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:inherit;text-decoration:none;font-weight:700;font-size:14.5px}
.hb-strip a.hot{border-color:#F07C1F;background:linear-gradient(0deg,rgba(240,124,31,.08),rgba(240,124,31,.08)),var(--card)}
.hb-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
@media (min-width:640px){.hb-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
.hb-tile{position:relative;display:flex;flex-direction:column;justify-content:center;gap:3px;min-height:104px;padding:14px;border-radius:16px;border:1px solid var(--line);background:var(--card);color:inherit;text-decoration:none;box-shadow:0 2px 0 rgba(12,48,108,.12)}
.hb-tile:active{transform:translateY(2px)}
.hb-tile i{font-style:normal;font-size:28px;line-height:1}
.hb-tile b{font-size:15px;line-height:1.25}
.hb-tile small{font-size:12px;color:var(--mut)}
.hb-badge{position:absolute;top:8px;right:8px;min-width:24px;height:24px;padding:0 7px;border-radius:99px;background:#D7263D;color:#fff;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center}
details.hb-more{margin-top:14px}
details.hb-more>summary{cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:center;min-height:48px;border-radius:12px;border:1.5px dashed var(--line);font-weight:800}
details.hb-more>summary::-webkit-details-marker{display:none}
details.hb-more[open]>summary{margin-bottom:10px}
.hb-foot{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin:22px 0 6px}
.hb-foot a{min-height:44px;display:inline-flex;align-items:center;padding:0 14px;border-radius:11px;border:1px solid var(--line);text-decoration:none;color:inherit;font-weight:700;font-size:13.5px}
.hb-flash{padding:11px 14px;border-radius:11px;font-weight:700;margin-bottom:12px}
.hb-flash.ok{background:#DEF4E4;color:#14602E}.hb-flash.bad{background:#F7DCDC;color:#8A1F1F}
@media (prefers-reduced-motion:reduce){.hb-tile:active{transform:none}}
</style>
<div class="ap-a hb">
  <?php if ($flash !== null): ?><div class="hb-flash <?= $e($flash[0]) ?>" role="status"><?= $e($flash[1]) ?></div><?php endif; ?>

  <section class="hb-me" aria-label="तपाईं · You">
    <?php if ($photo !== ''): ?><img class="hb-av" src="<?= $e($photo) ?>" alt="" width="58" height="58">
    <?php else: ?><span class="hb-ini" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($name, 0, 1)) ?: '★') ?></span><?php endif; ?>
    <div class="hb-who">
      <h2>नमस्ते, <?= $e($first) ?> 🙏</h2>
      <p><?= $e($roleL) ?><?= $idNo !== '' ? ' · <b>' . $e($idNo) . '</b>' : '' ?></p>
      <?php if ($chip !== null): ?><span class="hb-chip <?= $e($chip[0]) ?>"><?= $e($chip[1]) ?></span><?php endif; ?>
    </div>
    <div class="hb-acts">
      <?php if ((string) ($admin['role'] ?? '') !== 'viewer'): ?><a href="/me/#id">🪪 मेरो ID कार्ड · My ID card</a><?php endif; ?>
      <?php if ($office): ?><a class="pri" href="/admin/index.php">🏢 एडमिन प्यानल · Admin panel</a><?php endif; ?>
      <?php if ($others > 0): ?>
      <form method="post" action="/admin/home.php" style="display:contents"><?= Security::csrfField() ?><input type="hidden" name="action" value="switch">
        <button type="submit" title="यो मोबाइलको अर्को ID खोल्नुहोस् · Open another ID on this mobile">🔁 ID बदल्नुहोस् · Switch ID</button></form>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($strip !== []): ?>
  <div class="hb-strip">
    <?php foreach ($strip as $s): ?><a class="<?= $s['hot'] ? 'hot' : '' ?>" href="<?= $e($s['href']) ?>"><?= $e($s['text']) ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($tiles['hero'] === []): ?>
    <div class="hb-strip"><a href="/me/">तपाईंको पदका लागि यहाँ काम छैन — ID र कागजात /me/ मा · Nothing to do here for your role — your ID and papers are at /me/</a></div>
  <?php else: ?>
  <div class="hb-grid"><?php foreach ($tiles['hero'] as $t) { echo $tile($t); } ?></div>
  <?php endif; ?>

  <?php if ($tiles['more'] !== []): ?>
  <details class="hb-more">
    <summary>＋ थप काम · More (<?= count($tiles['more']) ?>)</summary>
    <div class="hb-grid"><?php foreach ($tiles['more'] as $t) { echo $tile($t); } ?></div>
  </details>
  <?php endif; ?>

  <div class="hb-foot">
    <?php if (!$viaOtp): ?><a href="/admin/change-password.php">🔑 पासवर्ड बदल्नुहोस् · Change password</a><?php endif; ?>
    <a href="/admin/logout.php">🚪 बाहिर निस्कनुहोस् · Sign out</a>
  </div>
</div>
<?php admin_footer();
