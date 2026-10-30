<?php
/**
 * c.php?u=<card token> — the visiting card as a page: the card itself,
 * one tap to WhatsApp / call, "save contact" (vCard), and "book a ticket"
 * credited to this agent (?ref=SHG-0027, which the booking site honours).
 * &vcf=1 downloads the contact.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';

Security::sendHeaders();
try {
    Security::requireRateLimit('apc_ip', Security::clientIp(), 90, 300);
} catch (Throwable $e) {
    http_response_code(429);
    exit('Too many requests.');
}
$token = (string) ($_GET['u'] ?? '');
$m = AgentPro::available() ? AgentPro::memberByToken($token) : null;
if ($m === null || in_array($m['status'], ['revoked', 'inactive'], true)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><p style="font-family:system-ui;padding:24px">This card is no longer active. <a href="/">' . Security::e(Settings::getString('company_name', APP_NAME)) . '</a></p>';
    exit;
}
$co = Settings::company();
if (!empty($_GET['vcf'])) {
    $esc = static fn(string $s): string => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $s);
    $lines = ['BEGIN:VCARD', 'VERSION:3.0', 'FN:' . $esc($m['name']), 'ORG:' . $esc($co['legal']), 'TITLE:' . $esc($m['designation'] . ($m['idNo'] !== '' ? ' · ' . $m['idNo'] : ''))];
    if ($m['wa'] !== '')   { $lines[] = 'TEL;TYPE=CELL,VOICE:+' . $m['wa']; }
    if ($m['call'] !== '' && $m['call'] !== $m['wa']) { $lines[] = 'TEL;TYPE=WORK:+' . $m['call']; }
    if ($m['email'] !== '') { $lines[] = 'EMAIL:' . $esc($m['email']); }
    $lines[] = 'URL:' . appUrl('c.php?u=' . rawurlencode($token));
    if ($m['branch'] !== '') { $lines[] = 'ADR;TYPE=WORK:;;' . $esc($m['branch']) . ';;;;'; }
    $lines[] = 'NOTE:' . $esc($co['name'] . ' · ' . $co['phone']);
    $lines[] = 'END:VCARD';
    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9]+/', '-', $m['name']) . '.vcf"');
    echo implode("\r\n", $lines) . "\r\n";
    exit;
}
$e = static fn($v): string => Security::e((string) $v);
$css = '/assets/agentpro/ap.css?v=' . (is_file(ASSET_PATH . '/agentpro/ap.css') ? filemtime(ASSET_PATH . '/agentpro/ap.css') : 1);
$img  = '/api/agentpro-card.php?u=' . rawurlencode($token) . '&s=vc-front&v=' . $m['photoVer'];
$back = '/api/agentpro-card.php?u=' . rawurlencode($token) . '&s=vc-back&v=' . $m['photoVer'];
$brand = trim((string) preg_replace('/[\s,]+(private\s+limited|pvt\.?\s*ltd\.?)$/iu', '', (string) $co['legal']));
$chat  = (string) ($co['chat'] ?? Settings::chatWhatsApp());
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $e($m['name']) ?> · <?= $e($co['name']) ?></title>
<meta name="description" content="<?= $e($m['designation'] . ' · ' . $co['name'] . ' · WhatsApp ' . $m['waDisplay']) ?>">
<meta property="og:title" content="<?= $e($m['name'] . ' — ' . $co['name']) ?>"><meta property="og:image" content="<?= $e(appUrl(ltrim($img, '/'))) ?>">
<meta name="robots" content="noindex"><meta name="theme-color" content="#13224F">
<link rel="icon" type="image/png" href="/assets/img/favicon-32.png"><link rel="stylesheet" href="<?= $e($css) ?>"></head>
<body>
<header class="ap-top"><div class="ap-top-in"><img src="/assets/img/logo-mark-tm.webp" alt="<?= $e($co['legal']) ?> logo" width="88" height="46" style="flex:0 0 auto;width:88px;height:auto;background:#fff;border-radius:10px;padding:4px 6px">
<div class="ap-brand"><b><?= $e(mb_strtoupper($brand !== '' ? $brand : $co['name'])) ?></b><small><?= $e($co['service']) ?></small></div></div>
<div class="ap-hero"><h1><?= $e($m['name']) ?></h1><p><?= $e($m['designation']) ?><?= $m['idNo'] !== '' ? ' · ' . $e($m['idNo']) : '' ?></p></div></header>
<main class="ap-wrap">
  <div class="ap-vc"><img src="<?= $e($img) ?>" alt="Visiting card of <?= $e($m['name']) ?>" width="889" height="508"></div>
  <div class="ap-vc" style="margin-top:12px"><img src="<?= $e($back) ?>" alt="Back of the visiting card" width="889" height="508" loading="lazy"></div>
  <div class="ap-actions" style="grid-template-columns:1fr 1fr">
    <?php if ($m['waLink'] !== ''): ?><a class="ap-btn wa" href="<?= $e($m['waLink']) ?>">💬 WhatsApp</a><?php endif; ?>
    <?php if ($m['call'] !== ''): ?><a class="ap-btn" href="tel:+<?= $e($m['call']) ?>">☎ Call</a><?php endif; ?>
    <a class="ap-btn ghost" href="?u=<?= $e(rawurlencode($token)) ?>&amp;vcf=1">👤 Save contact</a>
    <a class="ap-btn gold" href="/<?= $m['isAgent'] && $m['idNo'] !== '' ? '?ref=' . $e(rawurlencode($m['idNo'])) : '' ?>">🎫 Book a ticket</a>
  </div>
  <section class="ap-card" style="margin-top:18px">
    <dl class="ap-review"><dt>WhatsApp</dt><dd class="ap-mono"><?= $e($m['waDisplay']) ?></dd>
      <?php if ($m['email'] !== ''): ?><dt>Email</dt><dd><?= $e($m['email']) ?></dd><?php endif; ?>
      <?php if ($m['branch'] !== ''): ?><dt>Branch</dt><dd><?= $e($m['branch']) ?></dd><?php endif; ?>
      <dt>Company</dt><dd><?= $e($co['legal']) ?> · CIN <?= $e($co['cin']) ?></dd>
      <dt>24×7 help</dt><dd><a href="tel:<?= $e(preg_replace('/[^\d+]/', '', $co['phone247'])) ?>"><?= $e($co['phone247']) ?></a> · CEO &amp; Director <?= $e($co['ceo']) ?></dd>
      <dt>Book on WhatsApp</dt><dd class="ap-mono"><a href="<?= $e(whatsappLink($chat, 'Namaste')) ?>" target="_blank" rel="noopener"><?= $e(AgentPro::displayPhone($chat)) ?></a></dd></dl>
  </section>
</main>
<p class="ap-footer"><a href="/"><?= $e($co['web']) ?></a> · <a href="/id.php?c=<?= $e(rawurlencode($token)) ?>">Verify</a></p>
</body></html>
