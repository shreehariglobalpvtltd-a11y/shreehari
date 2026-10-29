<?php
/**
 * verify-agreement.php?c=<REF>.<12 hex> — public check of an S Hari Global
 * agreement (23 Oct 2026, owner: "unique agreement ID and verification QR").
 *
 * The QR on every filled A4 agreement (admin/application-print.php) opens
 * this page. A made-up code is "not genuine" without any database lookup
 * (AgreementVerify::parse). A genuine code shows the agreement's CURRENT
 * standing, so a printed copy of an agreement that was never approved, or
 * that has since ended, says so here.
 *
 * Shown: agreement number, kind, name, the mobile with the middle digits
 * hidden, signing and approval dates, and whether the office has checked
 * the KYC. Never an ID number, address, date of birth, bank detail or file.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agreementverify.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$h = static fn($v): string => Security::e((string) ($v ?? ''));
$limited = !Security::rateLimit('agreement_verify', Security::clientIp(), 60, 60);
$code = trim((string) ($_GET['c'] ?? ''));
$ref  = $limited || $code === '' ? null : AgreementVerify::parse($code);
$a    = null;
if ($ref !== null) {
    try {
        $a = AgreementVerify::standing($ref);
    } catch (Throwable $e) {
        Logger::warning('verify-agreement: ' . $e->getMessage());
    }
}
$co  = Settings::company();
$tel = preg_replace('/[^\d+]/', '', (string) $co['phone']) ?? '';
$wa  = preg_replace('/\D/', '', (string) $co['whatsapp']) ?? '';

if ($limited) {
    http_response_code(429);
    $state = 'wait';
} elseif ($code === '') {
    $state = 'empty';
} elseif ($ref === null) {
    $state = 'forged';
} elseif ($a === null) {
    $state = 'gone';
} else {
    $state = $a['state'];
}
$mask = static function (string $phone): string {
    $d = preg_replace('/\D/', '', $phone) ?? '';
    if (strlen($d) < 10) {
        return '';
    }
    $cc = substr($d, 0, strlen($d) - 10);
    return ($cc !== '' ? '+' . $cc . ' ' : '') . substr($d, -10, 2) . '•••••' . substr($d, -3);
};
$fmt = static fn(string $t): string => $t !== '' && ($ts = strtotime($t)) ? date('d M Y, H:i', $ts) : '—';
$kindL = ['staff' => 'Staff agreement · कर्मचारी सम्झौता', 'agent' => 'Agent agreement · एजेन्ट सम्झौता', 'partner' => 'Partner (Pvt. Ltd.) agreement · साझेदार कम्पनी सम्झौता'];
$banner = [
    'in_force'  => ['ok', '✅', 'Genuine · in force', 'सही सम्झौता · लागू छ'],
    'received'  => ['warn', '⏳', 'Genuine · waiting for office approval', 'सही सम्झौता · कार्यालयको स्वीकृति बाँकी — अझै लागू भएको छैन'],
    'suspended' => ['bad', '⛔', 'Genuine · suspended', 'सम्झौता निलम्बित छ'],
    'ended'     => ['bad', '⛔', 'Genuine · ended', 'सम्झौता समाप्त भइसक्यो'],
    'refused'   => ['bad', '⛔', 'Not accepted by the office', 'कार्यालयले स्वीकार गरेन — मान्य छैन'],
];
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0a2a8f">
<title>Agreement check · सम्झौता जाँच | S Hari Global</title>
<style>
:root{--bg:#f3f6fb;--card:#fff;--ink:#16233c;--muted:#5c6b85;--line:#e4e9f1;--royal:#0a2a8f;--ok:#0f9d6b;--bad:#d93025;--warn:#b26a00}
@media (prefers-color-scheme:dark){:root{--bg:#0b1220;--card:#131d30;--ink:#e8eef8;--muted:#a9b6cb;--line:#24324b}}
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,"Noto Sans Devanagari",sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex;justify-content:center;padding:16px}
.w{width:100%;max-width:460px}
.hd{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:18px 18px 0 0;background:linear-gradient(135deg,#0a2a8f,#1f4fe0);color:#fff}
.hd img{height:44px;width:auto;background:#fff;border-radius:10px;padding:3px}
.hd b{display:block;font-size:17px;letter-spacing:.02em}.hd small{display:block;font-size:11.5px;color:#ffd7a0;font-weight:700;letter-spacing:.08em}
.c{background:var(--card);border:1px solid var(--line);border-top:0;border-radius:0 0 18px 18px;padding:20px 18px 18px;box-shadow:0 10px 30px -18px rgba(10,42,143,.5)}
.st{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:14px;font-weight:800;font-size:16px;margin-bottom:16px}
.st i{font-style:normal;font-size:24px}
.st.ok{background:rgba(15,157,107,.12);color:var(--ok);border:1px solid rgba(15,157,107,.4)}
.st.bad{background:rgba(217,48,37,.1);color:var(--bad);border:1px solid rgba(217,48,37,.35)}
.st.warn{background:rgba(178,106,0,.1);color:var(--warn);border:1px solid rgba(178,106,0,.35)}
.st small{display:block;font-weight:600;font-size:12.5px;opacity:.9}
h1{font-size:21px;margin:4px 0 6px;line-height:1.25;text-align:center}
.kind{display:block;width:max-content;max-width:100%;margin:0 auto;padding:4px 12px;border-radius:999px;background:#ffe4c7;color:#9a4a00;font-weight:800;font-size:13px;text-align:center}
dl{display:grid;grid-template-columns:auto 1fr;gap:8px 14px;margin:18px 0 0;font-size:14px}
dt{color:var(--muted)}dd{margin:0;font-weight:700;overflow-wrap:anywhere}
.acts{display:flex;gap:8px;margin-top:18px}
.acts a{flex:1;display:flex;align-items:center;justify-content:center;min-height:46px;border-radius:12px;font-weight:800;text-decoration:none;font-size:14px}
.call{background:#eef4fc;color:var(--royal);border:1px solid #d9e7fa}.wa{background:#1faa59;color:#fff}
p.n{color:var(--muted);font-size:13.5px;line-height:1.5;margin:12px 0 0}
.ft{text-align:center;color:var(--muted);font-size:12px;margin-top:14px}
</style>
</head>
<body>
<main class="w">
  <div class="hd"><img src="/assets/img/logo-mark-96h-tm.png" alt=""><div><b>S HARI GLOBAL™</b><small>PRIVATE LIMITED · AGREEMENT CHECK</small></div></div>
  <div class="c">
  <?php if ($a !== null && isset($banner[$state])): [$cls, $ico, $en, $ne] = $banner[$state]; ?>
    <div class="st <?= $cls ?>"><i><?= $ico ?></i><div><?= $h($en) ?><small><?= $h($ne) ?></small></div></div>
    <h1><?= $h($a['name']) ?></h1>
    <span class="kind"><?= $h($kindL[$a['kind']] ?? '') ?></span>
    <dl>
      <dt>Agreement No.</dt><dd><?= $h($a['ref']) ?></dd>
      <dt>Document</dt><dd><?= $h($a['doc']) ?></dd>
      <dt>Mobile</dt><dd><?= $h($mask((string) $a['phone']) ?: '—') ?><?= $a['phoneProven'] ? ' · ✔ WhatsApp proven' : '' ?></dd>
      <dt>Signed</dt><dd><?= $h($fmt((string) $a['signed'])) ?></dd>
      <dt>Approved</dt><dd><?= $h($a['approved'] !== '' ? $fmt((string) $a['approved']) : 'Not yet · अझै भएको छैन') ?></dd>
      <dt>KYC</dt><dd><?= $a['kyc'] ? '✔ Checked by the office · कार्यालयले जाँच्यो' : 'Not checked yet · जाँच बाँकी' ?></dd>
    </dl>
    <p class="n">The printed copy must show the same agreement number. Only an agreement marked “in force” binds S Hari Global. In doubt, call the office.<br>छापिएको प्रतिमा यही नम्बर हुनुपर्छ। “लागू छ” लेखिएको सम्झौता मात्र मान्य हुन्छ। शंका लागे कार्यालयमा फोन गर्नुहोस्।</p>
  <?php elseif ($state === 'forged'): ?>
    <div class="st bad"><i>❌</i><div>NOT GENUINE<small>यो सम्झौता नक्कली हुन सक्छ — हामीले जारी गरेको होइन।</small></div></div>
    <p class="n">This code was not issued by S Hari Global. Please do not trust this document and tell our office.<br>कृपया यो कागजमा भरोसा नगर्नुहोस् र कार्यालयलाई खबर गर्नुहोस्।</p>
  <?php elseif ($state === 'gone'): ?>
    <div class="st bad"><i>⛔</i><div>Not found<small>यो सम्झौता अब रेकर्डमा छैन।</small></div></div>
    <p class="n">Please call the office with the agreement number.<br>सम्झौता नम्बर सहित कार्यालयमा फोन गर्नुहोस्।</p>
  <?php elseif ($state === 'wait'): ?>
    <div class="st warn"><i>⏳</i><div>Too many checks<small>धेरै पटक जाँच भयो — एक मिनेट पर्खनुहोस्।</small></div></div>
  <?php else: ?>
    <div class="st warn"><i>📄</i><div>Scan the QR on the agreement<small>सम्झौताको QR स्क्यान गर्नुहोस्।</small></div></div>
    <p class="n">The QR on a real S Hari Global agreement opens only <b><?= $h(preg_replace('~^https?://~', '', appUrl('verify-agreement.php'))) ?></b>. No QR reader? Call the office with the agreement number.<br>असली सम्झौताको QR ले यही ठेगाना मात्र खोल्छ। QR नचले सम्झौता नम्बर सहित कार्यालयमा फोन गर्नुहोस्।</p>
  <?php endif; ?>
    <div class="acts">
      <?php if ($tel !== ''): ?><a class="call" href="tel:<?= $h($tel) ?>">📞 <?= $h($co['phone']) ?></a><?php endif; ?>
      <?php if (strlen($wa) >= 11): ?><a class="wa" href="https://wa.me/<?= $h($wa) ?>?text=<?= rawurlencode('Agreement check' . ($ref !== null ? ' ' . $ref : '')) ?>" target="_blank" rel="noopener">WhatsApp office</a><?php endif; ?>
    </div>
  </div>
  <p class="ft"><?= $h($co['legal']) ?> · <a href="/" style="color:inherit"><?= $h(preg_replace('~^(https?://)?(www\.)?~', '', (string) $co['web'])) ?></a></p>
</main>
<script defer src="/assets/js/wa-direct.js?v=20261022a"></script>
</body>
</html>
