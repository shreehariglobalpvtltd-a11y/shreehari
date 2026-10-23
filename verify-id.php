<?php
/**
 * verify-id.php?c=<REF>.<12 hex> — public check of an S Hari Global ID card
 * (22 Oct 2026, owner: team portal; 23 Oct 2026: the public digital card).
 *
 * The QR on every staff / agent / partner ID card and visiting card
 * (includes/idcard.php) opens this page. The code is the card number plus
 * an HMAC of it, so a made-up number is called "not genuine" without any
 * database lookup, and the numbers cannot be walked. A genuine code shows
 * the person's CURRENT standing — a printed card of someone who was
 * suspended or has left says so here.
 *
 * Shown: photo, name, role, card number, office / area, standing, issue and
 * expiry dates, the mobile with the middle digits hidden, and the office
 * helpline. 23 Oct 2026: when the person agreed to a public listing
 * (IdCard::person()['public']) the page is their digital visiting card too -
 * one-tap WhatsApp (prefilled), call, e-mail, Facebook and a vCard
 * (?vcf=1). Never an ID document, address, bank detail or date of birth.
 * Every genuine scan is written to the audit trail (idcard.verify) for the
 * office's verification log.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/idcard.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$h = static fn($v): string => Security::e((string) ($v ?? ''));
$limited = !Security::rateLimit('idcard_verify', Security::clientIp(), 60, 60);
$code = trim((string) ($_GET['c'] ?? ''));
$ref  = $limited || $code === '' ? null : IdCard::parse($code);
$p    = $ref !== null ? IdCard::person($ref) : null;
$co   = Settings::company();
$tel  = preg_replace('/[^\d+]/', '', (string) $co['phone']) ?? '';
$wa   = preg_replace('/\D/', '', (string) $co['whatsapp']) ?? '';

if ($limited) {
    http_response_code(429);
    $state = 'wait';
} elseif ($code === '') {
    $state = 'empty';
} elseif ($ref === null) {
    $state = 'forged';
} elseif ($p === null) {
    $state = 'gone';
} else {
    $state = $p['valid'] ? 'valid' : 'invalid';
}
if ($p !== null && Security::rateLimit('idcard_verify_log', Security::clientIp() . '|' . $p['ref'], 3, 600)) {
    // 23 Oct 2026: the verification log the office reads on admin/id-card.php (at most 3 lines per scanner per card per 10 min)
    Logger::audit('idcard.verify', $p['kind'], $p['ref'], null, ['valid' => $p['valid'], 'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80)], 'ID card QR scanned');
}

// 23 Oct 2026: the vCard, only with the person's consent (the same rule as the buttons)
if ($p !== null && !empty($_GET['vcf']) && $p['public'] && $p['valid'] && $p['waDigits'] !== '') {
    $esc = static fn(string $s): string => str_replace([';', ',', "\n"], ['\;', '\,', '\n'], $s);
    $v = "BEGIN:VCARD\r\nVERSION:3.0\r\nN:;" . $esc($p['name']) . ";;;\r\nFN:" . $esc($p['name']) . "\r\nORG:" . $esc((string) $co['legal']) . "\r\nTITLE:" . $esc($p['roleEn']) . "\r\n"
       . "TEL;TYPE=CELL:+" . $p['waDigits'] . "\r\n" . ($p['email'] !== '' ? 'EMAIL:' . $esc($p['email']) . "\r\n" : '')
       . 'URL:' . IdCard::verifyUrl($p['ref']) . "\r\n" . ($p['facebook'] !== '' ? 'URL:' . $p['facebook'] . "\r\n" : '')
       . 'NOTE:' . $esc('S Hari Global ' . $p['roleEn'] . ' · Card ' . $p['ref']) . "\r\nEND:VCARD\r\n";
    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="SHG-' . $p['ref'] . '.vcf"');
    echo $v;
    exit;
}
header('Content-Type: text/html; charset=utf-8');
$mask = static function (string $phone): string {
    $d = preg_replace('/\D/', '', $phone) ?? '';
    if (strlen($d) < 10) {
        return '';
    }
    $cc = substr($d, 0, strlen($d) - 10);
    return ($cc !== '' ? '+' . $cc . ' ' : '') . substr($d, -10, 2) . '•••••' . substr($d, -3);
};
$photo = $p !== null ? IdCard::thumb($p['photo'], 260) : null;
$kindNe = ['staff' => 'कर्मचारी', 'agent' => 'एजेन्ट', 'partner' => 'साझेदार कम्पनी'];
$kindK  = ['staff' => '#11703F', 'agent' => '#0054A8', 'partner' => '#C25E00'];
$waMe = $p !== null ? IdCard::waLink($p) : '';
$here = $ref !== null ? IdCard::verifyUrl($ref) : appUrl('verify-id.php');
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0a2a8f">
<title><?= $p !== null ? $h($p['name']) . ' · ' . $h($p['roleEn']) : 'ID card check · परिचयपत्र जाँच' ?> | S Hari Global</title>
<?php if ($p !== null): ?><meta property="og:title" content="<?= $h($p['name']) ?> · <?= $h($p['roleEn']) ?> · S Hari Global"><meta property="og:description" content="<?= $h($p['standing']) ?> · Card <?= $h($p['ref']) ?> · verified by S Hari Global Pvt. Ltd."><?php endif; ?>
<style>
:root{--bg:#f3f6fb;--card:#fff;--ink:#16233c;--muted:#5c6b85;--line:#e4e9f1;--royal:#0a2a8f;--ok:#0f9d6b;--bad:#d93025;--warn:#b26a00;--k:<?= $p !== null ? $kindK[$p['kind']] : '#0a2a8f' ?>}
@media (prefers-color-scheme:dark){:root{--bg:#0b1220;--card:#131d30;--ink:#e8eef8;--muted:#a9b6cb;--line:#24324b}}
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,"Noto Sans Devanagari",sans-serif;background:radial-gradient(120% 60% at 100% 0%,rgba(240,122,30,.14),transparent 60%),var(--bg);color:var(--ink);min-height:100vh;display:flex;justify-content:center;padding:16px}
.w{width:100%;max-width:440px}
.hd{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:22px 22px 0 0;background:linear-gradient(135deg,#0a2a8f,#1f4fe0);color:#fff;position:relative;overflow:hidden}
.hd::after{content:"";position:absolute;right:-40px;top:-60px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.12)}
.hd img{height:44px;width:auto;background:#fff;border-radius:10px;padding:3px;position:relative}
.hd b{display:block;font-size:17px;letter-spacing:.02em}.hd small{display:block;font-size:11.5px;color:#ffd7a0;font-weight:700;letter-spacing:.08em}
.c{background:var(--card);border:1px solid var(--line);border-top:0;border-radius:0 0 22px 22px;padding:20px 18px 18px;box-shadow:0 18px 40px -22px rgba(10,42,143,.55)}
.st{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:14px;font-weight:800;font-size:16px;margin-bottom:16px}
.st i{font-style:normal;font-size:24px}
.st.ok{background:rgba(15,157,107,.12);color:var(--ok);border:1px solid rgba(15,157,107,.4)}
.st.bad{background:rgba(217,48,37,.1);color:var(--bad);border:1px solid rgba(217,48,37,.35)}
.st.warn{background:rgba(178,106,0,.1);color:var(--warn);border:1px solid rgba(178,106,0,.35)}
.st small{display:block;font-weight:600;font-size:12.5px;opacity:.9}
.who{text-align:center}
.ring{display:inline-block;padding:4px;border-radius:50%;background:conic-gradient(from 0deg,var(--k),#f07a1e,var(--k))}
.ph{display:block;width:150px;height:150px;border-radius:50%;object-fit:cover;border:4px solid var(--card);background:#fff}
.ph.logo{object-fit:contain;padding:10px}
.ini{width:150px;height:150px;border-radius:50%;display:grid;place-items:center;font-size:54px;font-weight:900;color:var(--royal);background:#eef4fc;border:4px solid var(--card)}
h1{font-size:23px;margin:12px 0 6px;line-height:1.25}
.role{display:inline-block;padding:4px 12px;border-radius:999px;background:#ffe4c7;color:#9a4a00;font-weight:800;font-size:13px}
.vt{display:inline-flex;align-items:center;gap:5px;margin:8px 4px 0;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:800;background:rgba(15,157,107,.12);color:var(--ok)}
dl{display:grid;grid-template-columns:auto 1fr;gap:8px 14px;margin:18px 0 0;font-size:14px}
dt{color:var(--muted)}dd{margin:0;font-weight:700;overflow-wrap:anywhere}
.cta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:18px}
.cta a{display:flex;align-items:center;justify-content:center;gap:6px;min-height:48px;border-radius:14px;font-weight:800;text-decoration:none;font-size:14px;border:1.5px solid var(--line);color:var(--ink);background:var(--card)}
.cta a.wa{background:#1faa59;color:#fff;border-color:#1faa59;grid-column:1/-1;min-height:54px;font-size:16px}
.cta a.fb{color:#1877f2}.cta a.vcf{color:var(--royal)}
.acts{display:flex;gap:8px;margin-top:14px}
.acts a{flex:1;display:flex;align-items:center;justify-content:center;min-height:46px;border-radius:12px;font-weight:800;text-decoration:none;font-size:14px}
.call{background:#eef4fc;color:var(--royal);border:1px solid #d9e7fa}.wa2{background:#1faa59;color:#fff}
p.n{color:var(--muted);font-size:13.5px;line-height:1.5;margin:10px 0 0}
.ft{text-align:center;color:var(--muted);font-size:12px;margin-top:14px}
.ft a{color:inherit}
</style>
</head>
<body>
<main class="w">
  <div class="hd"><img src="/assets/img/logo-mark-96h.png" alt=""><div><b>S HARI GLOBAL™</b><small>PRIVATE LIMITED · DIGITAL ID</small></div></div>
  <div class="c">
  <?php if ($state === 'valid' || $state === 'invalid'): ?>
    <?php if ($state === 'valid'): ?>
    <div class="st ok"><i>✅</i><div>Genuine · <?= $h($p['standing']) ?><small>सही परिचयपत्र · <?= $h($p['standingNe']) ?></small></div></div>
    <?php else: ?>
    <div class="st bad"><i>⛔</i><div>Not valid now · <?= $h($p['standing']) ?><small>यो परिचयपत्र अहिले मान्य छैन · <?= $h($p['standingNe']) ?></small></div></div>
    <?php endif; ?>
    <div class="who">
      <span class="ring"><?php if ($photo !== null): ?><img class="ph<?= $p['kind'] === 'partner' ? ' logo' : '' ?>" src="<?= $h($photo) ?>" alt="Photo of <?= $h($p['name']) ?>">
      <?php else: ?><span class="ini"><?= $h(mb_strtoupper(mb_substr(trim((string) $p['name']), 0, 1))) ?></span><?php endif; ?></span>
      <h1><?= $h($p['name']) ?></h1>
      <span class="role"><?= $h($p['roleEn']) ?> · <?= $h($p['roleNe']) ?></span>
      <?php if ($state === 'valid'): ?><br><span class="vt">🛡️ Company verified · कम्पनी प्रमाणित</span><?php endif; ?>
    </div>
    <dl>
      <dt>Card No.</dt><dd><?= $h($p['ref']) ?></dd>
      <dt><?= $p['kind'] === 'staff' ? 'Office' : 'Area' ?></dt><dd><?= $h($p['place'] ?: '—') ?></dd>
      <?php if ($p['contact'] !== ''): ?><dt>Contact</dt><dd><?= $h($p['contact']) ?></dd><?php endif; ?>
      <dt>Mobile</dt><dd><?= $h($p['public'] ? $p['phone'] : ($mask((string) $p['phone']) ?: '—')) ?></dd>
      <dt>Issued</dt><dd><?= $h(date('d M Y', strtotime((string) $p['issued']) ?: time())) ?></dd>
      <?php if ((string) ($p['expires'] ?? '') !== ''): ?><dt>Valid till</dt><dd><?= $h(date('d M Y', strtotime((string) $p['expires']) ?: time())) ?></dd><?php endif; ?>
      <dt>Type</dt><dd><?= $h($kindNe[$p['kind']] ?? '') ?></dd>
    </dl>
    <?php if ($state === 'valid' && $p['public'] && $p['waDigits'] !== ''): ?>
    <div class="cta">
      <?php if ($waMe !== ''): ?><a class="wa" href="<?= $h($waMe) ?>" target="_blank" rel="noopener">💬 WhatsApp <?= $h($p['kind'] === 'partner' ? 'the company' : $p['name']) ?></a><?php endif; ?>
      <a href="tel:+<?= $h($p['waDigits']) ?>">📞 Call · फोन</a>
      <?php if ($p['email'] !== ''): ?><a href="mailto:<?= $h($p['email']) ?>?subject=<?= rawurlencode('Bus ticket enquiry · S Hari Global') ?>">✉️ E-mail</a><?php endif; ?>
      <?php if ($p['facebook'] !== ''): ?><a class="fb" href="<?= $h($p['facebook']) ?>" target="_blank" rel="noopener nofollow">📘 Facebook</a><?php endif; ?>
      <a class="vcf" href="<?= $h($here) ?>&amp;vcf=1">📇 Save contact</a>
    </div>
    <?php endif; ?>
    <p class="n">Compare the photo with the person in front of you. In doubt, call the office.<br>फोटो मिलाएर हेर्नुहोस्। शंका लागे कार्यालयमा फोन गर्नुहोस्।</p>
  <?php elseif ($state === 'forged'): ?>
    <div class="st bad"><i>❌</i><div>NOT GENUINE<small>यो परिचयपत्र नक्कली हुन सक्छ — हाम्रो कार्ड होइन।</small></div></div>
    <p class="n">This code was not issued by S Hari Global. Please do not trust this card and tell our office.<br>कृपया यो कार्डमा भरोसा नगर्नुहोस् र कार्यालयलाई खबर गर्नुहोस्।</p>
  <?php elseif ($state === 'gone'): ?>
    <div class="st bad"><i>⛔</i><div>Not valid<small>यो कार्ड अब मान्य छैन।</small></div></div>
    <p class="n">This card number is no longer active with S Hari Global. Please call the office.<br>कृपया कार्यालयमा फोन गर्नुहोस्।</p>
  <?php elseif ($state === 'wait'): ?>
    <div class="st warn"><i>⏳</i><div>Too many checks<small>धेरै पटक जाँच भयो — एक मिनेट पर्खनुहोस्।</small></div></div>
  <?php else: ?>
    <div class="st warn"><i>🪪</i><div>Scan the QR on the ID card<small>परिचयपत्रको QR स्क्यान गर्नुहोस्।</small></div></div>
    <p class="n">The QR on a real card opens only <b><?= $h(preg_replace('~^https?://~', '', appUrl('verify-id.php'))) ?></b>. No QR reader? Call the office below with the card number from the back of the card.<br>असली कार्डको QR ले यही ठेगाना मात्र खोल्छ। QR नचले कार्डको पछाडिको नम्बर सहित तलको नम्बरमा फोन गर्नुहोस्।</p>
  <?php endif; ?>
    <div class="acts">
      <?php if ($tel !== ''): ?><a class="call" href="tel:<?= $h($tel) ?>">📞 Office <?= $h($co['phone']) ?></a><?php endif; ?>
      <?php if (strlen($wa) >= 11): ?><a class="wa2" href="https://wa.me/<?= $h($wa) ?>?text=<?= rawurlencode('ID card check' . ($ref !== null ? ' ' . $ref : '')) ?>" target="_blank" rel="noopener">WhatsApp office</a><?php endif; ?>
    </div>
  </div>
  <p class="ft"><?= $h($co['legal']) ?> · <a href="/"><?= $h(preg_replace('~^(https?://)?(www\.)?~', '', (string) $co['web'])) ?></a> · <a href="/agreements/">Join us · हामीसँग जोडिनुहोस्</a></p>
</main>
<script defer src="/assets/js/wa-direct.js?v=20261022a"></script><!-- every wa.me link opens WhatsApp in one tap -->
</body>
</html>
