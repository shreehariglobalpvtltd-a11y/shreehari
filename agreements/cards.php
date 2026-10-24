<?php
/** Own submitted application only: private applicant cards become official after office approval. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/idcard.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
$own = IdCard::applicantSession();
$p = $own !== null ? IdCard::applicant($own['type'], $own['id']) : null;
if ($p === null) {
    http_response_code($own === null ? 410 : 404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>My cards</title><p>This private download has expired or is unavailable. After approval, open <a href="/team/card.php">My digital ID in the team portal</a>.</p><p>यो निजी लिंकको समय सकियो। स्वीकृत भएपछि टिम पोर्टलमा आफ्नो कार्ड हेर्नुहोस्।</p>');
}
$side = (string)($_GET['side'] ?? ''); $vis = (string)($_GET['v'] ?? ''); $pdf = (string)($_GET['pdf'] ?? '');
if ($side !== '' || $vis !== '' || $pdf !== '') {
    $which = $side !== '' ? $side : $vis;
    if (($pdf !== '' && !in_array($pdf, ['id','visiting','all'], true)) || ($pdf === '' && !in_array($which, ['front','back','sheet'], true))) { http_response_code(400); exit('Unknown card format'); }
    Security::requireRateLimit('own_card_download', Security::clientIp(), 60, 600);
    $bytes = $pdf !== '' ? IdCard::pdf($p, $pdf) : ($vis !== '' ? IdCard::visitingPng($p, $which) : IdCard::png($p, $which));
    header('Content-Type: ' . ($pdf !== '' ? 'application/pdf' : 'image/png'));
    if ($pdf !== '' || isset($_GET['dl'])) {
        $kind = $pdf !== '' ? $pdf : ($vis !== '' ? 'visiting' : 'id');
        header('Content-Disposition: attachment; filename="SHG-' . $p['ref'] . '-' . $kind . ($pdf !== '' ? '.pdf' : '-' . $which . '.png') . '"');
    }
    header('Content-Length: ' . strlen($bytes)); echo $bytes; exit;
}
$h = static fn($s): string => Security::e((string)$s);
?><!doctype html><html lang="ne"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>My ID &amp; visiting cards | S Hari Global</title>
<style>*{box-sizing:border-box}body{margin:0;background:#edf2fa;color:#12284e;font:16px/1.6 system-ui,sans-serif}main{max-width:980px;margin:auto;padding:24px 16px 48px}h1{font-size:27px;line-height:1.4}.panel{background:#fff;border:1px solid #d5dff1;border-radius:18px;padding:22px;margin:18px 0;box-shadow:0 10px 28px #14345a0a}.state{border-left:5px solid #e6ad3b;padding:12px 16px;background:#fff7df}.buttons{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}a.b{padding:12px 17px;border-radius:10px;background:#08295a;color:#fff;font-weight:750;text-decoration:none}.id{display:block;width:260px;max-width:100%;margin:18px auto;border-radius:15px;box-shadow:0 15px 30px #112c5f30}.visit{display:block;width:100%;max-width:700px;margin:18px auto;border-radius:12px;box-shadow:0 15px 30px #112c5f25}.small{font-size:14px;color:#506786}a{color:#123e82}@media(max-width:500px){.panel{padding:16px}.buttons>a{width:100%;text-align:center}}</style></head><body><main>
<a href="/">← S Hari Global</a><h1><?= $h($p['name']) ?> · My cards</h1>
<p>Code: <b><?= $h($p['memberCode'] ?? $p['ref']) ?></b> · <?= $h($p['roleEn']) ?></p>
<?php if (!empty($p['pending'])): ?><div class="state"><b>कार्यालयको स्वीकृति बाँकी · Pending office approval</b><br>अहिले कार्ड डाउनलोड गर्न मिल्छ। यो कम्पनीको तर्फबाट काम गर्ने अनुमति होइन। स्वीकृत भएपछि यही विवरणको आधिकारिक कार्ड बन्छ।</div><?php else: ?><div class="state"><?= $h($p['standing']) ?> · QR scan गरेर हालको स्थिति हेर्नुहोस्।</div><?php endif ?>
<p class="small">तपाईंले upload गरेको फोटो भए कार्डमा त्यही आउँछ; फोटो नभए नामका अक्षर आउँछन्। · These private downloads are available in this browser for one hour after submitting. Approved members can use the team portal later.</p>
<div class="buttons"><a class="b" href="?pdf=all">⬇ ID + visiting card PDF</a><a class="b" href="/agreements/form.php?mine=1&amp;auto=pdf" target="_blank" rel="noopener">📄 My application PDF</a></div>
<section class="panel"><h2>🪪 परिचयपत्र · ID card</h2><img class="id" src="?side=front" alt="Your ID card front"><img class="id" src="?side=back" alt="Your ID card back" loading="lazy"><div class="buttons"><a class="b" href="?pdf=id">PDF</a><a class="b" href="?side=front&amp;dl=1">Front PNG</a><a class="b" href="?side=back&amp;dl=1">Back PNG</a></div></section>
<section class="panel"><h2>💼 भिजिटिङ कार्ड · Visiting card</h2><img class="visit" src="?v=front" alt="Your visiting card front" loading="lazy"><img class="visit" src="?v=back" alt="Your visiting card back" loading="lazy"><div class="buttons"><a class="b" href="?pdf=visiting">PDF</a><a class="b" href="?v=front&amp;dl=1">Front PNG</a><a class="b" href="?v=back&amp;dl=1">Back PNG</a></div></section>
<p class="small">आधिकारिक स्थिति QR ले वेबसाइटबाट जाँच्छ। · Fixed company branding is based on the company's Canva design; personal values are generated from your saved application.</p>
</main></body></html>
