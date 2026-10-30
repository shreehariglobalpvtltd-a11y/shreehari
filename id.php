<?php
/**
 * id.php?c=<card token> — "is this person really ours?" The QR on every
 * ID card opens this page. It shows only what the card itself shows
 * (photo, name, role, ID number, validity) and a big live stamp:
 * VALID · SUSPENDED · EXPIRED · REVOKED (a re-issued or cancelled card)
 * · NOT ACTIVE. Nothing private, never indexed.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';

Security::sendHeaders();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$ok = true;
try {
    Security::requireRateLimit('apid_ip', Security::clientIp(), 60, 300);
} catch (Throwable $e) {
    $ok = false;
}
$m = $ok && AgentPro::available() ? AgentPro::memberByToken((string) ($_GET['c'] ?? '')) : null;
$co = Settings::company();
$e = static fn($v): string => Security::e((string) $v);
[$stamp, $cls, $line] = match ($m['status'] ?? 'none') {
    'valid'     => ['✓ VALID', 'valid', 'This person works with ' . $co['name'] . '.'],
    'suspended' => ['SUSPENDED', 'warn', 'This card is suspended right now. Please check with our office.'],
    'expired'   => ['EXPIRED', 'warn', 'This card has expired. Please check with our office.'],
    'revoked'   => ['REVOKED', 'bad', 'This card was cancelled or replaced. Do not accept it.'],
    'inactive'  => ['NOT ACTIVE', 'bad', 'This person is not working with us now.'],
    default     => ['NOT VALID', 'bad', 'We could not find this card. It may be fake — please call our office.'],
};
$css = '/assets/agentpro/ap.css?v=' . (is_file(ASSET_PATH . '/agentpro/ap.css') ? filemtime(ASSET_PATH . '/agentpro/ap.css') : 1);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>ID check · <?= $e($co['name']) ?></title><meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#13224F">
<link rel="icon" type="image/png" href="/assets/img/favicon-32.png"><link rel="stylesheet" href="<?= $e($css) ?>"></head>
<body>
<header class="ap-top"><div class="ap-top-in"><img class="ap-crest" src="/assets/agentpro/crest-dark.svg" alt="" width="42" height="52">
<div class="ap-brand"><b>S HARI GLOBAL</b><small>ID verification</small></div></div></header>
<main class="ap-verify">
  <section class="ap-card" style="text-align:center">
    <span class="ap-stamp <?= $e($cls) ?>"><?= $e($stamp) ?></span>
    <p class="lead" style="margin:14px 0 0"><?= $e($line) ?></p>
  </section>
  <?php if ($m !== null): ?>
  <section class="ap-card">
    <div class="ap-person">
      <?php if ($m['photoUrl'] !== ''): ?><img src="<?= $e($m['photoUrl']) ?>" alt=""><?php else: ?><div class="ph"></div><?php endif; ?>
      <div><b><?= $e($m['name']) ?></b><?php if ($m['contact'] !== ''): ?><div class="ap-hint"><?= $e($m['contact']) ?></div><?php endif; ?>
        <div class="ap-caps" style="margin-top:6px"><?= $e($m['designation']) ?></div>
        <div class="ap-mono" style="margin-top:8px;font-weight:700;font-size:18px"><?= $e($m['idNo'] !== '' ? $m['idNo'] : '—') ?></div>
      </div>
    </div>
    <dl class="ap-review" style="margin-top:16px">
      <?php if ($m['branch'] !== ''): ?><dt>Branch</dt><dd><?= $e($m['branch']) ?></dd><?php endif; ?>
      <dt>Valid till</dt><dd><?= $e($m['validUntil'] !== '' ? formatDate($m['validUntil'], 'j M Y') : '—') ?></dd>
      <dt>Checked</dt><dd><?= $e(date('j M Y, H:i')) ?></dd>
    </dl>
  </section>
  <?php endif; ?>
  <section class="ap-card">
    <div class="ap-caps">Issued by</div>
    <p style="margin:6px 0 2px;font-weight:700"><?= $e($co['legal']) ?></p>
    <p class="ap-hint" style="margin:0">CIN <?= $e($co['cin']) ?> · <?= $e($co['address']) ?></p>
    <div class="ap-actions"><a class="ap-btn sm" href="tel:<?= $e(preg_replace('/[^\d+]/', '', $co['phone'])) ?>">☎ <?= $e($co['phone']) ?></a>
      <a class="ap-btn wa sm" href="<?= $e(whatsappLink($co['whatsapp'], 'ID check: ' . (string) ($_GET['c'] ?? '') . ' — ' . ($m['name'] ?? 'unknown card'))) ?>" target="_blank" rel="noopener">💬 Report / ask</a></div>
  </section>
</main>
<p class="ap-footer"><?= $e($co['web']) ?></p>
</body></html>
