<?php
/**
 * /team/agreement.php — "My agreement" in the team portal (22 Oct 2026,
 * owner: agreements fully digital and professional).
 *
 *   GET              each agreement this person signs (staff / agent /
 *                    partner): the full text to read (the same page the
 *                    office prints, built from the owner's paper forms),
 *                    "I have read and accept", a finger signature, Sign
 *   POST             sign (CSRF) -> agreement_signatures (event portal);
 *                    an agent / partner card turns "signed" for the office
 *                    (22 Oct 2026 review, #17: once per term, TeamPortal::
 *                    termSign; an expired agreement stays expired for the
 *                    office to renew)
 *   ?print=<doc>     the signed copy: parties, version, date, IP, mobile
 *                    proof, both signatures, link to the full text
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/teamportal.php';
require_once INCLUDE_PATH . '/teamui.php';

$me = TeamPortal::current();
if ($me === null) {
    TeamUi::go('/team/');
}
$h   = static fn($v): string => Security::e((string) ($v ?? ''));
$ags = TeamPortal::agreementsFor($me);
$err = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $err = 'यो पेज धेरै बेर खुला थियो — फेरि सही गर्नुहोस् · This page was open too long, please sign again.';
    } else {
        try {
            $done = TeamPortal::sign($me, (string) ($_POST['doc'] ?? ''), (string) ($_POST['signature'] ?? ''), isset($_POST['agree']));
            TeamUi::setFlash('ok', '✅ सम्झौतामा सही भयो · Agreement signed (' . $done['title'] . ', v' . $done['version'] . ').'
                // 22 Oct 2026 (review, #17): an expired agreement is renewed by the office, never by the portal alone
                . (!empty($done['renew']) ? ' सम्झौताको अवधि सकिएको थियो — कार्यालयले नवीकरण गर्नेछ · Your agreement had expired: the office renews it.' : ''));
            TeamUi::go('/team/agreement.php');
        } catch (TeamPortalError $e) {
            $err = $e->getMessage();
        } catch (Throwable $e) {
            Logger::exception($e);
            $err = 'सही सेभ हुन सकेन — फेरि प्रयास गर्नुहोस् · Could not save the signature, please try again.';
        }
    }
}

/* ---------------- the signed copy ---------------- */
$print = (string) ($_GET['print'] ?? '');
if ($print !== '') {
    $ag = null;
    foreach ($ags as $a) {
        if ($a['doc'] === $print) {
            $ag = $a;
        }
    }
    $sign = $ag !== null ? TeamPortal::lastSign($ag) : null;
    if ($ag === null || $sign === null) {
        TeamUi::go('/team/agreement.php');
    }
    $co  = Settings::company();
    $img = TeamPortal::signImage($sign);
    TeamUi::head('Signed agreement · ' . $ag['ref']);
    ?>
<div class="tp-card" style="border-top:6px solid var(--orange)">
  <div class="tp-me" style="justify-content:space-between">
    <div><h1 style="margin:0"><?= $h($ag['title']) ?></h1>
      <p class="tp-sub" style="margin:4px 0 0">Version <?= $h($ag['version']) ?> · signed online · अनलाइन सही गरिएको</p></div>
    <img src="/assets/img/logo-mark-96h-tm.png" alt="" style="height:56px">
  </div>
  <h3>Parties · पक्षहरू</h3>
  <dl class="cv-dl">
    <dt>Company</dt><dd><?= $h($co['legal']) ?> (CIN <?= $h($co['cin']) ?>)<br><span class="muted" style="font-weight:400"><?= $h($co['address']) ?></span></dd>
    <dt><?= $ag['doc'] === 'partner' ? 'Partner company' : ($ag['doc'] === 'agent' ? 'Agent' : 'Staff member') ?></dt><dd><?= $h($ag['name']) ?> · <?= $h($ag['ref']) ?></dd>
    <dt>Mobile</dt><dd><?= $h(substr($ag['phone'], 0, 2) . '•••••' . substr($ag['phone'], -3)) ?> <?= (int) $sign['phone_verified'] === 1 ? '· ✓ proven on WhatsApp' : '' ?></dd>
  </dl>
  <h3>Signature record · सहीको प्रमाण</h3>
  <dl class="cv-dl">
    <dt>Signed at</dt><dd><?= $h(date('d M Y, H:i', strtotime((string) $sign['signed_at']) ?: time())) ?> IST</dd>
    <dt>From</dt><dd class="mono" style="font-weight:400"><?= $h($sign['ip_address'] ?? '') ?></dd>
    <dt>Record No.</dt><dd class="mono">SIG-<?= str_pad((string) (int) $sign['id'], 6, '0', STR_PAD_LEFT) ?></dd>
    <dt>Full text</dt><dd><a href="<?= $h($ag['url']) ?>" target="_blank" rel="noopener"><?= $h(preg_replace('~^https?://~', '', appUrl(ltrim($ag['url'], '/')))) ?></a></dd>
  </dl>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px">
    <div style="border:1px solid var(--line);border-radius:12px;padding:10px;text-align:center;background:#fff">
      <?php if ($img !== null): ?><img src="<?= $h($img) ?>" alt="Signature" style="max-width:100%;height:90px;object-fit:contain"><?php endif; ?>
      <div style="border-top:1px solid #ccd;margin-top:6px;padding-top:4px;font-size:12px;color:#5c6b85"><?= $h($ag['name']) ?></div>
    </div>
    <div style="border:1px solid var(--line);border-radius:12px;padding:10px;text-align:center;background:#fff">
      <img src="/assets/img/logo-mark-96h-tm.png" alt="" style="height:90px;object-fit:contain;opacity:.9">
      <div style="border-top:1px solid #ccd;margin-top:6px;padding-top:4px;font-size:12px;color:#5c6b85">For <?= $h($co['legal']) ?></div>
    </div>
  </div>
  <p class="tp-sub" style="margin-top:14px;font-size:12.5px">This record shows that the person above accepted version <?= $h($ag['version']) ?> of the agreement at the link above by signing on their phone in the S Hari Global team portal, signed in with their registered mobile. · माथिको व्यक्तिले दर्ता गरेको मोबाइलबाट टिम पोर्टलमा यो सम्झौता स्वीकार गरी सही गर्नुभएको प्रमाण।</p>
  <div class="tp-noprint"><button class="b" type="button" onclick="window.print()">🖨️ Print / Save PDF</button><a class="b gh" href="/team/agreement.php">← Back</a></div>
</div>
<?php
    TeamUi::foot();
    exit;
}

/* ---------------- read + sign ---------------- */
TeamUi::head('My agreement');
TeamUi::flash();
if ($err !== null) {
    echo '<div class="tp-msg bad" role="alert">' . $h($err) . '</div>';
}
if ($ags === []): ?>
<div class="tp-card"><h1>📝 मेरो सम्झौता</h1><p class="tp-sub">कार्यालयले स्वीकृत गरेपछि यहाँ सम्झौता सही गर्न मिल्छ। · Once the office approves you, you sign your agreement here.</p></div>
<?php endif;
foreach ($ags as $ag):
    $sign = TeamPortal::termSign($ag);   // 22 Oct 2026 (review, #17): the same once-per-term rule sign() keeps
    ?>
<div class="tp-card">
  <h1>📝 <?= $h($ag['title']) ?></h1>
  <p class="tp-sub">Version <?= $h($ag['version']) ?> · <?= $h($ag['ref']) ?> · <?= $h($ag['name']) ?></p>
  <a class="b gh" href="<?= $h($ag['url']) ?>" target="_blank" rel="noopener">📖 पूरा सम्झौता पढ्नुहोस् · Read the full agreement</a>
  <?php if ($sign !== null): ?>
  <div class="tp-msg ok" style="margin-top:14px">✅ सही भइसक्यो · Signed on <?= $h(date('d M Y, H:i', strtotime((string) $sign['signed_at']) ?: time())) ?></div>
  <a class="b" href="/team/agreement.php?print=<?= $h($ag['doc']) ?>">🖨️ सही गरिएको प्रति · Signed copy</a>
  <?php else: ?>
  <form method="post" action="/team/agreement.php" class="sigform" style="margin-top:14px">
    <?= Security::csrfField() ?><input type="hidden" name="doc" value="<?= $h($ag['doc']) ?>"><input type="hidden" name="signature" value="">
    <p class="tp-sub" style="margin:0 0 6px">औंलाले तलको बाकसमा सही गर्नुहोस् · Sign with your finger:</p>
    <div style="position:relative;border:2px dashed var(--line);border-radius:14px;background:#fff;touch-action:none">
      <canvas width="600" height="200" style="display:block;width:100%;height:160px;border-radius:12px" aria-label="Sign here"></canvas>
      <button type="button" class="sig-clear" style="position:absolute;top:6px;right:6px;border:0;border-radius:10px;padding:6px 10px;font:inherit;font-size:12px;background:#eef2f8;color:#16233c">↺ Clear</button>
    </div>
    <label style="display:flex;gap:10px;align-items:flex-start;margin-top:12px;font-weight:700;font-size:14px"><input type="checkbox" name="agree" value="1" style="width:22px;height:22px;flex:0 0 22px">
      <span>मैले पूरा सम्झौता (संस्करण <?= $h($ag['version']) ?>) पढें, बुझें र स्वीकार गर्छु। · I have read, understood and accept this agreement.</span></label>
    <button class="b or" type="submit">✍️ सही गर्नुहोस् · Sign</button>
  </form>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<script>
(function () {
  document.querySelectorAll('form.sigform').forEach(function (f) {
    var c = f.querySelector('canvas'), x = c.getContext('2d'), ink = 0, down = false, last = null;
    x.lineWidth = 3.2; x.lineCap = 'round'; x.lineJoin = 'round'; x.strokeStyle = '#0a2a8f';
    function pt(e) { var r = c.getBoundingClientRect(); return { x: (e.clientX - r.left) * c.width / r.width, y: (e.clientY - r.top) * c.height / r.height }; }
    c.addEventListener('pointerdown', function (e) { down = true; last = pt(e); c.setPointerCapture(e.pointerId); e.preventDefault(); });
    c.addEventListener('pointermove', function (e) {
      if (!down) return;
      var p = pt(e); x.beginPath(); x.moveTo(last.x, last.y); x.lineTo(p.x, p.y); x.stroke();
      ink += Math.abs(p.x - last.x) + Math.abs(p.y - last.y); last = p; e.preventDefault();
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) { c.addEventListener(t, function () { down = false; }); });
    f.querySelector('.sig-clear').addEventListener('click', function () { x.clearRect(0, 0, c.width, c.height); ink = 0; });
    f.addEventListener('submit', function (e) {
      if (ink < 60) { e.preventDefault(); alert('बाकसभित्र औंलाले सही गर्नुहोस् · Please sign inside the box.'); return; }
      if (!f.querySelector('[name=agree]').checked) { e.preventDefault(); alert('"मैले पढें र मान्छु" मा ✓ लगाउनुहोस्।'); return; }
      f.querySelector('[name=signature]').value = c.toDataURL('image/png');
    });
  });
})();
</script>
<?php
TeamUi::foot();
