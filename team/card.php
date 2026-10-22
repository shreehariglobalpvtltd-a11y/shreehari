<?php
/**
 * /team/card.php — "My ID card" in the team portal (22 Oct 2026).
 *
 *   /team/card.php                    the person's own card(s): staff and / or
 *                                     agent / partner, front + back, print
 *   /team/card.php?ref=…&side=front   the PNG (back | sheet), &dl=1 to save
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
if ($side !== '') {
    $ref = strtoupper((string) ($_GET['ref'] ?? ''));
    $p = in_array($ref, $refs, true) && in_array($side, ['front', 'back', 'sheet'], true) ? IdCard::person($ref) : null;
    if ($p === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found');
    }
    $png = IdCard::png($p, $side);
    if (isset($_GET['dl'])) {
        Logger::audit('idcard.self', $p['kind'], $p['ref'], null, ['side' => $side], 'ID card saved from the team portal');
        header('Content-Disposition: attachment; filename="SHG-ID-' . $p['ref'] . '-' . $side . '.png"');
    }
    header_remove('Set-Cookie');
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($png));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $png;
    exit;
}

TeamUi::head('My ID card');
if ($refs === []): ?>
<div class="tp-card"><h1>🪪 मेरो परिचयपत्र</h1><p class="tp-sub">कार्यालयले स्वीकृत गरेपछि यहाँ परिचयपत्र देखिन्छ। · Your ID card shows here once the office approves you.</p></div>
<?php endif;
foreach ($refs as $ref):
    $p = IdCard::person($ref);
    if ($p === null) {
        continue;
    }
    $q = static fn(array $x): string => '/team/card.php?' . http_build_query(['ref' => $ref] + $x);
    ?>
<div class="tp-card">
  <h1>🪪 <?= $h($p['roleEn']) ?> · <?= $h($p['ref']) ?></h1>
  <p class="tp-sub"><?= $p['valid'] ? '✅ ' . $h($p['standing']) . ' — QR स्क्यान गर्दा "Genuine" देखिन्छ।' : '⛔ ' . $h($p['standing']) . ' — QR ले "not valid" देखाउँछ।' ?></p>
  <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center">
    <img src="<?= $h($q(['side' => 'front'])) ?>" alt="ID card front" style="width:min(46%,220px);border-radius:12px;box-shadow:0 8px 22px -12px rgba(10,42,143,.7)">
    <img src="<?= $h($q(['side' => 'back'])) ?>" alt="ID card back" style="width:min(46%,220px);border-radius:12px;box-shadow:0 8px 22px -12px rgba(10,42,143,.7)">
  </div>
  <div class="tp-noprint">
    <a class="b or" href="<?= $h($q(['side' => 'front', 'dl' => 1])) ?>">⬇ अगाडि PNG · Front</a>
    <a class="b gh" href="<?= $h($q(['side' => 'back', 'dl' => 1])) ?>">⬇ पछाडि PNG · Back</a>
    <a class="b gh" href="<?= $h(IdCard::verifyUrl($ref)) ?>" target="_blank" rel="noopener">🔍 QR जाँच पेज · Verify page</a>
  </div>
  <p class="tp-sub" style="margin-top:12px">PVC कार्ड छाप्न दुवै PNG प्रिन्टरलाई दिनुहोस् (54 × 85.6 mm)। · Give both PNGs to a card printer.</p>
</div>
<?php endforeach;
TeamUi::foot();
