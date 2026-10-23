<?php
/**
 * /team/card.php — "My digital ID" in the team portal (22 Oct 2026; redone
 * 23 Oct 2026, owner: premium digital ID + visiting cards, PNG / PDF,
 * mobile digital card, share).
 *
 *   /team/card.php                     the person's own card(s): a flip card on
 *                                      the phone (tap to turn), the visiting
 *                                      card, downloads, share, the QR link
 *   ?ref=…&side=front|back|sheet       the ID card PNG      (&dl=1 to save)
 *   ?ref=…&v=front|back|sheet          the visiting card PNG (&dl=1 to save)
 *   ?ref=…&pdf=id|visiting|all         the print-ready A4 PDF
 *
 * Only the signed-in person's own cards (TeamPortal::cardRefs) — never
 * someone else's number.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/teamportal.php';
require_once INCLUDE_PATH . '/teamui.php';
require_once INCLUDE_PATH . '/idcard.php';

$me = TeamPortal::current();
if ($me === null) {
    TeamUi::go('/team/');
}
$refs = TeamPortal::cardRefs($me);
$h = static fn($v): string => Security::e((string) ($v ?? ''));

$side = (string) ($_GET['side'] ?? '');
$vis  = (string) ($_GET['v'] ?? '');
$pdfW = (string) ($_GET['pdf'] ?? '');
if ($side !== '' || $vis !== '' || $pdfW !== '') {
    $ref = strtoupper((string) ($_GET['ref'] ?? ''));
    $p = in_array($ref, $refs, true) ? IdCard::person($ref) : null;
    $which = $side !== '' ? $side : $vis;
    if ($p === null || ($pdfW === '' && !in_array($which, ['front', 'back', 'sheet'], true)) || ($pdfW !== '' && !in_array($pdfW, ['id', 'visiting', 'all'], true))) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found');
    }
    header_remove('Set-Cookie');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if ($pdfW !== '') {
        $bytes = IdCard::pdf($p, $pdfW);
        Logger::audit('idcard.self', $p['kind'], $p['ref'], null, ['pdf' => $pdfW], 'Card PDF saved from the team portal');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="SHG-' . ($pdfW === 'visiting' ? 'VisitingCard' : ($pdfW === 'id' ? 'ID' : 'Cards')) . '-' . $p['ref'] . '.pdf"');
    } else {
        $bytes = $vis !== '' ? IdCard::visitingPng($p, $which) : IdCard::png($p, $which);
        if (isset($_GET['dl'])) {
            Logger::audit('idcard.self', $p['kind'], $p['ref'], null, ['side' => $which, 'card' => $vis !== '' ? 'visiting' : 'id'], 'Card PNG saved from the team portal');
            header('Content-Disposition: attachment; filename="SHG-' . ($vis !== '' ? 'VisitingCard' : 'ID') . '-' . $p['ref'] . '-' . $which . '.png"');
        }
        header('Content-Type: image/png');
    }
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

TeamUi::head('My digital ID');
?>
<style>
.dc-wrap{perspective:1400px;max-width:300px;margin:0 auto}
.dc{position:relative;width:100%;aspect-ratio:638/1011;transform-style:preserve-3d;transition:transform .8s cubic-bezier(.2,.9,.3,1.1);cursor:pointer;-webkit-tap-highlight-color:transparent}
.dc.flip{transform:rotateY(180deg)}
.dc img{position:absolute;inset:0;width:100%;height:100%;border-radius:14px;backface-visibility:hidden;-webkit-backface-visibility:hidden;box-shadow:0 18px 40px -18px rgba(10,42,143,.75);background:#fff}
.dc img.back{transform:rotateY(180deg)}
.dc-hint{text-align:center;color:var(--muted);font-size:12.5px;margin:8px 0 0}
.vc{display:block;width:100%;max-width:520px;margin:10px auto 0;border-radius:12px;box-shadow:0 14px 34px -18px rgba(10,42,143,.7);background:#fff}
.dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:12px}
.dl .b{margin:0;min-height:46px;font-size:14px}
.dl .b.full{grid-column:1/-1}
.sh{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.sh a,.sh button{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;min-height:46px;border-radius:12px;font:inherit;font-weight:800;font-size:14px;text-decoration:none;cursor:pointer;border:1.5px solid var(--line);background:var(--card);color:var(--ink)}
.sh .wa{background:#1faa59;color:#fff;border-color:#1faa59}
@media (prefers-reduced-motion:reduce){.dc{transition:none}}
</style>
<?php
if ($refs === []): ?>
<div class="tp-card"><h1>🪪 मेरो डिजिटल परिचयपत्र</h1><p class="tp-sub">कार्यालयले स्वीकृत गरेपछि यहाँ परिचयपत्र र भिजिटिङ कार्ड देखिन्छ। · Your ID card and visiting card show here once the office approves you.</p></div>
<?php endif;
foreach ($refs as $ref):
    $p = IdCard::person($ref);
    if ($p === null) {
        continue;
    }
    $q = static fn(array $x): string => '/team/card.php?' . http_build_query(['ref' => $ref] + $x);
    $verify = IdCard::verifyUrl($ref);
    $wa = IdCard::waLink($p);
    ?>
<div class="tp-card">
  <h1>🪪 <?= $h($p['roleEn']) ?> · <?= $h($p['ref']) ?></h1>
  <p class="tp-sub"><?= $p['valid'] ? '✅ ' . $h($p['standing']) . ' — QR स्क्यान गर्दा "Genuine" देखिन्छ।' : '⛔ ' . $h($p['standing']) . ' — QR ले "not valid" देखाउँछ।' ?>
    <?= (string) ($p['expires'] ?? '') !== '' ? ' · मान्य: ' . $h(date('d M Y', strtotime((string) $p['expires']) ?: time())) . ' सम्म' : '' ?></p>
  <div class="dc-wrap"><div class="dc" role="button" tabindex="0" aria-label="ID card, tap to turn over">
    <img src="<?= $h($q(['side' => 'front'])) ?>" alt="ID card front" width="638" height="1011">
    <img class="back" src="<?= $h($q(['side' => 'back'])) ?>" alt="ID card back" width="638" height="1011" loading="lazy">
  </div></div>
  <p class="dc-hint">👆 कार्ड थिच्दा पल्टिन्छ · Tap the card to turn it over</p>
  <div class="dl tp-noprint">
    <a class="b or" href="<?= $h($q(['side' => 'front', 'dl' => 1])) ?>">⬇ अगाडि PNG</a>
    <a class="b gh" href="<?= $h($q(['side' => 'back', 'dl' => 1])) ?>">⬇ पछाडि PNG</a>
    <a class="b full" href="<?= $h($q(['pdf' => 'id'])) ?>">📄 ID कार्ड PDF (print-ready A4)</a>
  </div>

  <h2 style="margin-top:22px">💼 भिजिटिङ कार्ड · Visiting card</h2>
  <p class="tp-sub">तपाईंको नाम, पद, ID नं., <?= $p['public'] ? 'सम्पर्क' : 'कार्यालयको सम्पर्क' ?> र जाँच QR — ग्राहकलाई दिन वा WhatsApp मा पठाउन। · Share it with customers; the QR proves it is yours.</p>
  <img class="vc" src="<?= $h($q(['v' => 'front'])) ?>" alt="Visiting card front" width="1050" height="602" loading="lazy">
  <img class="vc" src="<?= $h($q(['v' => 'back'])) ?>" alt="Visiting card back" width="1050" height="602" loading="lazy">
  <div class="dl tp-noprint">
    <a class="b or" href="<?= $h($q(['v' => 'front', 'dl' => 1])) ?>">⬇ अगाडि PNG</a>
    <a class="b gh" href="<?= $h($q(['v' => 'back', 'dl' => 1])) ?>">⬇ पछाडि PNG</a>
    <a class="b full" href="<?= $h($q(['pdf' => 'visiting'])) ?>">📄 भिजिटिङ कार्ड PDF (print-ready A4)</a>
  </div>

  <h2 style="margin-top:22px">🔗 मेरो सार्वजनिक प्रोफाइल · My public profile</h2>
  <p class="tp-sub"><?= $p['public'] ? 'तपाईंले अनुमति दिएकाले यो पेजमा तपाईंको WhatsApp, फोन' . ((string) $p['email'] !== '' ? ', इमेल' : '') . ((string) $p['facebook'] !== '' ? ', Facebook' : '') . ' देखिन्छ। · Your approved contact buttons show on this page.' : 'यो पेजले तपाईंको नाम, फोटो, पद र स्थिति मात्र देखाउँछ — नम्बर लुकेको छ। सम्पर्क देखाउन कार्यालयलाई भन्नुहोस्। · Shows name, photo, role and standing only; your number is hidden unless you allow it.' ?></p>
  <div class="sh tp-noprint">
    <a href="<?= $h($verify) ?>" target="_blank" rel="noopener">🔍 QR जाँच पेज · Verify page</a>
    <button type="button" data-share="<?= $h($verify) ?>" data-title="<?= $h($p['name'] . ' · ' . $p['roleEn'] . ' · S Hari Global') ?>">📤 सेयर · Share</button>
    <a class="wa" href="<?= $h('https://wa.me/?text=' . rawurlencode('🪪 ' . $p['name'] . ' · ' . $p['roleEn'] . ' · S Hari Global Pvt. Ltd.' . "\n" . 'Verify: ' . $verify)) ?>" target="_blank" rel="noopener">WhatsApp मा पठाउनुहोस्</a>
  </div>
  <p class="tp-sub" style="margin-top:12px;font-size:12.5px">PVC कार्ड छाप्न PDF वा दुवै PNG प्रिन्टरलाई दिनुहोस् (ID 54 × 85.6 mm · visiting 89 × 51 mm)। · Give the PDF or both PNGs to a card printer.</p>
</div>
<?php endforeach; ?>
<script>
(function () {
  document.querySelectorAll('.dc').forEach(function (c) {
    function flip() { c.classList.toggle('flip'); }
    c.addEventListener('click', flip);
    c.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); flip(); } });
  });
  document.querySelectorAll('[data-share]').forEach(function (b) {
    b.addEventListener('click', function () {
      var url = b.getAttribute('data-share'), title = b.getAttribute('data-title');
      if (navigator.share) { navigator.share({ title: title, text: title, url: url }).catch(function () {}); return; }
      if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { b.textContent = '✅ लिंक कपी भयो · Link copied'; }); }
      else prompt('Copy this link:', url);
    });
  });
})();
</script>
<?php
TeamUi::foot();
