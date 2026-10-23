<?php
/**
 * /agreements/document.php — ONE hybrid digital document per kind: the application form AND the
 * agreement together (23 Oct 2026, owner: "3 ota chuta chutai ... tesloi combo eutai hybrid khalko
 * digital ... official form jasto ... mobile computer bata bharna milos ... KYC details location ...
 * A4 size niyam haru sabai ~4 page ... shuddha Nepali ... canva jasto colourful, tara generate garda
 * black and white matra ... print ready").
 *
 *   ?kind=staff|agent|partner
 *
 * On screen: a colourful form, filled on a phone or a computer. Nothing is sent anywhere — what is typed
 * stays in this browser (localStorage draft) until the person presses "PDF" / "Print". Then the same
 * values are poured into black-and-white A4 sheets: pages 1-2 the form (details, address + GPS location,
 * KYC, bank, documents, rules, declaration, office use), pages 3-4 the agreement in Nepali with every term
 * (AgreementPrint — the same words as the agreement pages and the office's A4 form), the parties and the
 * signatures. Sending the application to the office stays /agreements/apply.html (linked here).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/brand.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/agreementprint.php';

$kind = (string) ($_GET['kind'] ?? 'staff');
$kind = in_array($kind, ['staff', 'agent', 'partner'], true) ? $kind : 'staff';
$h    = static fn($v): string => Security::e((string) ($v ?? ''));
$co   = Settings::companyFor(null, 'IN');
$logo = Brand::markUrl(120);
try {
    $secs = AgreementPrint::sections($kind, 'ne');
} catch (Throwable $e) {
    $secs = [];
}
$apply = ['staff' => 'agreements/apply.html?kind=staff', 'agent' => 'agreements/apply.html', 'partner' => 'agreements/apply.html?kind=partner'][$kind];
try {
    $qr = QrCode::dataUri(appUrl($apply), 4, 2);
} catch (Throwable $e) {
    $qr = '';
}
$K = [
    'staff'   => ['कर्मचारी', 'कर्मचारी आवेदन फारम तथा सम्झौता-पत्र', '🧑‍✈️', 'चालक, कन्डक्टर, सहचालक, कार्यालय, लेखा, मेकानिक, सफाइ, सुरक्षा', 'STF'],
    'agent'   => ['एजेन्ट', 'एजेन्ट आवेदन फारम तथा सम्झौता-पत्र', '🤝', 'व्यक्ति — टिकट बिक्री र यात्रु ल्याउने काम, कमिसनमा', 'AGT'],
    'partner' => ['साझेदार कम्पनी (प्रा. लि.)', 'साझेदार कम्पनी आवेदन फारम तथा सम्झौता-पत्र', '🏢', 'दर्ता भएको प्राइभेट लिमिटेड कम्पनी वा फर्म', 'PTR'],
][$kind];
$shortUrl = static fn(string $p): string => (string) preg_replace('~^https?://(www\.)?~i', '', appUrl($p));

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0a2a8f">
<title><?= $h($K[1]) ?> — <?= $h($co['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700;800&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
<style>
/* ---------- the screen: colourful, one card per part (Canva-like) ---------- */
:root{--k:#0054A8;--k2:#3b82f6;--ink:#14213d;--mut:#5b6b8c;--line:#dfe6f1;--bg:#f4f7fc;
  --font:"Noto Sans Devanagari","Nirmala UI","Mangal",Arial,sans-serif;--brand:"Poppins","Noto Sans Devanagari",Arial,sans-serif}
body.k-staff{--k:#11703F;--k2:#22c55e}body.k-partner{--k:#C25E00;--k2:#f59e0b}
*{box-sizing:border-box}
[hidden]{display:none!important}
html,body{margin:0}
body{background:var(--bg);color:var(--ink);font:16px/1.55 var(--font);-webkit-text-size-adjust:100%;overflow-wrap:anywhere}
.wrap{max-width:900px;margin:0 auto;padding:0 16px}
.hero{position:relative;overflow:hidden;color:#fff;padding:18px 0 26px;background:linear-gradient(135deg,var(--k),var(--k2) 70%,#f07a1e 130%)}
.hero::before,.hero::after{content:"";position:absolute;border-radius:50%;background:rgba(255,255,255,.13)}
.hero::before{width:260px;height:260px;right:-70px;top:-90px}.hero::after{width:160px;height:160px;left:-50px;bottom:-80px}
.hero .wrap{position:relative;z-index:1}
.htop{display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:space-between}
.hbrand{display:flex;align-items:center;gap:10px;color:#fff;text-decoration:none}
.hbrand img{height:42px;width:auto;background:#fff;border-radius:10px;padding:4px}
.hbrand b{font:800 16px/1.15 var(--brand);letter-spacing:.02em}
.hbrand small{display:block;font-size:11.5px;opacity:.9}
.tabs{display:flex;gap:6px;flex-wrap:wrap}
.tabs a{padding:7px 12px;border-radius:999px;background:rgba(255,255,255,.18);color:#fff;text-decoration:none;font-size:13.5px;font-weight:700;border:1px solid rgba(255,255,255,.35)}
.tabs a.on{background:#fff;color:var(--k)}
.hero h1{margin:18px 0 4px;font:800 clamp(22px,5vw,32px)/1.25 var(--brand)}
.hero p{margin:0;max-width:62ch;opacity:.95;font-size:15px}
.steps{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.steps span{display:inline-flex;gap:6px;align-items:center;padding:6px 11px;border-radius:12px;background:rgba(255,255,255,.16);font-size:13px;font-weight:600}
.prog{position:sticky;top:0;z-index:20;background:#fff;border-bottom:1px solid var(--line);box-shadow:0 6px 18px -16px rgba(13,27,62,.6)}
.prog .wrap{display:flex;align-items:center;gap:12px;padding-top:9px;padding-bottom:9px}
.prog .bar{flex:1;height:10px;border-radius:999px;background:#e8eef7;overflow:hidden}
.prog .bar i{display:block;height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,var(--k),var(--k2));transition:width .3s}
.prog b{font-size:14px;color:var(--k);white-space:nowrap}
main{padding:16px 0 120px}
.card{position:relative;background:#fff;border:1px solid var(--line);border-radius:22px;padding:18px 16px 16px;margin:0 0 16px;box-shadow:0 14px 30px -24px rgba(13,27,62,.6)}
.card::before{content:"";position:absolute;left:0;top:18px;bottom:18px;width:5px;border-radius:0 6px 6px 0;background:linear-gradient(var(--k),var(--k2))}
.ch{display:flex;align-items:center;gap:10px;margin:0 0 12px}
.ch .ic{flex:0 0 44px;width:44px;height:44px;border-radius:14px;display:grid;place-items:center;font-size:22px;background:color-mix(in srgb,var(--k) 12%,#fff)}
.ch h2{margin:0;font:700 18px/1.3 var(--font);color:var(--k)}
.ch small{display:block;font-size:12.5px;color:var(--mut);font-weight:400}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 14px}
.f{display:flex;flex-direction:column;gap:4px;min-width:0}
.f.full{grid-column:1/-1}
.f label{font-size:14px;font-weight:600;color:#26324d}
.f label em{color:#d4340c;font-style:normal}
.f input,.f select,.f textarea{width:100%;min-height:48px;padding:10px 12px;border:1.5px solid #cfd8e6;border-radius:12px;font:16px var(--font);color:var(--ink);background:#fbfcfe}
.f textarea{min-height:76px;resize:vertical}
.f input:focus,.f select:focus,.f textarea:focus{outline:none;border-color:var(--k);box-shadow:0 0 0 4px color-mix(in srgb,var(--k) 16%,transparent);background:#fff}
.f .hint{font-size:12px;color:var(--mut)}
.f.bad input,.f.bad select{border-color:#d4340c}
.pics{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
.pic{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;min-height:150px;padding:10px;border:2px dashed #c5d1e4;border-radius:16px;background:#fbfcfe;text-align:center;cursor:pointer;font-size:13px;color:var(--mut)}
.pic b{color:#26324d;font-size:13.5px}
.pic span.big{font-size:28px}
.pic img{position:absolute;inset:6px;width:calc(100% - 12px);height:calc(100% - 12px);object-fit:contain;border-radius:11px;background:#fff}
.pic input{position:absolute;inset:0;opacity:0;cursor:pointer}
.pic .x{position:absolute;top:6px;right:6px;z-index:2;width:30px;height:30px;border-radius:50%;border:0;background:#d4340c;color:#fff;font-weight:800;cursor:pointer}
.pic.done{border-style:solid;border-color:var(--k)}
.gps{display:flex;gap:10px;align-items:center;flex-wrap:wrap;grid-column:1/-1;padding:12px;border-radius:14px;background:color-mix(in srgb,var(--k) 7%,#fff);border:1px solid color-mix(in srgb,var(--k) 25%,#fff)}
.gps button{min-height:44px;padding:0 14px;border-radius:12px;border:0;background:var(--k);color:#fff;font:700 14px var(--font);cursor:pointer}
.gps span{font-size:13.5px;color:#26324d}
.sig{grid-column:1/-1}
.sig canvas{display:block;width:100%;height:170px;border:2px solid #cfd8e6;border-radius:16px;background:#fff;touch-action:none;cursor:crosshair}
.sig .row{display:flex;justify-content:space-between;align-items:center;margin-top:6px;font-size:12.5px;color:var(--mut)}
.sig button{min-height:36px;padding:0 12px;border-radius:10px;border:1.5px solid #cfd8e6;background:#fff;font:600 13px var(--font);cursor:pointer}
details.terms{border:1px solid var(--line);border-radius:16px;background:#fbfcfe}
details.terms summary{cursor:pointer;padding:12px 14px;font-weight:700;color:var(--k)}
details.terms .tb{max-height:420px;overflow:auto;padding:0 14px 12px;font-size:14px}
details.terms .sec{margin:12px 0 4px;font-weight:800;color:var(--k)}
details.terms p{margin:0 0 6px}
details.terms b.n{color:var(--k)}
.agree{display:flex;gap:10px;align-items:flex-start;margin-top:12px;padding:12px;border-radius:14px;background:#fff8ec;border:1px solid #f3d29b;font-size:14.5px}
.agree input{width:22px;height:22px;flex:none;margin-top:2px}
.rules{margin:0;padding-left:20px;font-size:14.5px}
.rules li{margin:0 0 5px}
.acts{position:fixed;left:0;right:0;bottom:0;z-index:30;background:rgba(255,255,255,.96);backdrop-filter:blur(6px);border-top:1px solid var(--line);padding:10px 0 calc(10px + env(safe-area-inset-bottom))}
.acts .wrap{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
.acts button,.acts a{display:flex;align-items:center;justify-content:center;gap:6px;min-height:50px;padding:0 8px;border-radius:14px;border:0;font:700 14px/1.2 var(--font);text-align:center;cursor:pointer;text-decoration:none}
.acts .pri{background:linear-gradient(135deg,#ff8a2b,#d4570c);color:#fff;box-shadow:0 10px 20px -12px rgba(212,87,12,.9)}
.acts .sec2{background:var(--k);color:#fff}
.acts .ghost{background:#eef2f8;color:var(--ink)}
#msg{margin:0 0 6px;text-align:center;font-size:13.5px;color:var(--k);font-weight:700;min-height:1px}
#previewWrap{background:#dfe4ec;border-radius:22px;padding:8px 0 16px;margin:0 0 16px}
#previewWrap h2{margin:10px 16px 0;font-size:16px;color:#26324d}
@media (max-width:640px){.grid{grid-template-columns:minmax(0,1fr)}.pics{grid-template-columns:repeat(2,minmax(0,1fr))}.pic{min-height:130px}.acts .wrap{grid-template-columns:repeat(2,minmax(0,1fr))}.hbrand img{height:36px}}

/* ---------- the paper: A4, black and white only ---------- */
@page{size:A4;margin:0}
#pages{padding:4px 0}
.sheet{width:210mm;height:297mm;margin:10px auto;background:#fff;color:#000;box-shadow:0 6px 26px rgba(0,0,0,.25);position:relative;overflow:hidden;display:flex;flex-direction:column;padding:9mm 11mm 7mm;font:10.5px/1.45 "Noto Sans Devanagari","Nirmala UI",Arial,sans-serif}
.sheet .body{flex:1 1 auto;min-height:0;overflow:hidden;padding-top:2.5mm}
.lh{display:flex;align-items:center;gap:12px;border-bottom:2.5px solid #000;padding-bottom:6px}
.lh img{height:54px;width:auto;filter:grayscale(1)}
.lh .co{flex:1;min-width:0}
.lh .co b{display:block;font:800 17px/1.15 "Poppins",Arial,sans-serif;letter-spacing:.4px}
.lh .co span{display:block;font-size:9.5px;color:#222}
.lh .kb{border:1.5px solid #000;border-radius:4px;padding:3px 8px;font-weight:800;font-size:10.5px;white-space:nowrap;text-align:center}
.lh .kb small{display:block;font-weight:600;font-size:8.5px}
.sheet.cont .lh img{height:26px}.sheet.cont .lh .co b{font-size:12px}.sheet.cont .lh .co span{display:none}
.rule2{height:0;border-top:1px solid #000;margin-top:2px}
.pf{display:flex;justify-content:space-between;gap:8px;border-top:1.5px solid #000;padding-top:3px;margin-top:3px;font-size:8.8px;color:#222}
.dt{display:flex;gap:10px;align-items:flex-start;margin:0 0 4px}
.dt .t{flex:1;min-width:0}
.dt h1{margin:0;font-size:16px;font-weight:800;letter-spacing:.2px}
.dt .sub{font-size:9.8px;color:#222}
.dt .no{margin-top:4px;font-size:10px}
.dt .no b{font-family:Consolas,monospace;font-size:11px}
.ph{width:30mm;height:37mm;border:1.5px solid #000;display:grid;place-items:center;text-align:center;font-size:8.5px;color:#444;overflow:hidden;flex:none;padding:2px}
.ph div{width:100%;height:100%;background:#fff center/cover no-repeat}
.qr{width:21mm;text-align:center;font-size:7.5px;flex:none}
.qr img{width:21mm;height:21mm;display:block;image-rendering:pixelated}
.sh{margin:6px 0 2px;padding:2px 6px;background:#000;color:#fff;font-weight:800;font-size:10.5px;letter-spacing:.3px}
.tb2{width:100%;border-collapse:collapse;table-layout:fixed}
.tb2 td{border:1px solid #000;padding:2.2px 5px;vertical-align:top;font-size:10px;height:6.6mm}
.tb2 td.l{width:23%;font-weight:600;background:#fff;font-size:9.4px}
.tb2 td.v{font-weight:600;white-space:pre-wrap;word-break:break-word}
.docs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:4px;margin-top:3px}
.docs figure{margin:0;border:1px solid #000;height:30mm;display:flex;flex-direction:column}
.docs figure div{flex:1;background:#fff center/contain no-repeat}
.docs figcaption{border-top:1px solid #000;font-size:8.4px;text-align:center;padding:1px}
.nr{margin:2px 0 0;padding-left:15px;font-size:9.3px}
.nr li{margin:0 0 1px}
.decl{margin-top:5px;border:1.5px solid #000;padding:5px 7px;font-size:9.8px}
.sgr{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:5px}
.sb{border:1px solid #000;padding:5px 7px;font-size:9.6px;min-height:28mm;position:relative}
.sb b{display:block;font-size:10px;margin-bottom:2px}
.sb .ln{display:flex;gap:5px;align-items:flex-end;margin:3px 0}
.sb .ln span{white-space:nowrap}
.sb .ln i{flex:1;border-bottom:1px solid #000;min-height:5.5mm;font-style:normal;font-weight:700;padding:0 3px 1px;display:flex;align-items:flex-end}
.sb .sgi{height:15mm;background:#fff left center/contain no-repeat;border-bottom:1px solid #000}
.thr{display:flex;gap:8px;margin-top:4px}
.thr div{flex:1;text-align:center;font-size:8.6px}
.thr .tbx{width:20mm;height:23mm;margin:0 auto 1px;border:1px dashed #000;border-radius:45%;background:#fff center/cover no-repeat}
.off{display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px;margin-top:5px}
.off div{border:1px dashed #000;height:15mm;padding:3px 5px;font-size:8.8px}
.agh{text-align:center;margin:0 0 4px}
.agh .big{font-size:16px;font-weight:800;letter-spacing:1px}
.agh .ti{font-size:12px;font-weight:700}
.agh .sub{font-size:9.6px}
.sec{margin:5px 0 1px;font-size:10.4px;font-weight:800;border-bottom:1px solid #000}
.pt{margin:0 0 1.5px;font-size:9.5px;line-height:1.42;text-align:justify}
.pt b.n{display:inline-block;min-width:22px}
.pt .fill,.fill{display:inline-block;min-width:22mm;border-bottom:1px solid #000;height:1.05em;vertical-align:-3px}
.acc{margin:5px 0 4px;padding:4px 7px;border:1.5px solid #000;font-size:9.8px}
.agsep{border-top:1.5px dashed #000;margin:6px 0 4px}
#flow{position:absolute;left:-10000px;top:0;width:188mm}
@media print{
  html,body{background:#fff!important}
  body>*:not(main){display:none!important}
  main>*:not(#previewWrap){display:none!important}
  main{padding:0}
  #previewWrap{background:none;padding:0;margin:0;border-radius:0}
  #previewWrap h2{display:none}
  #pages{zoom:1!important;padding:0}
  .sheet{margin:0;box-shadow:none;break-after:page;page-break-after:always}
  .sheet:last-child{break-after:auto;page-break-after:auto}
}
</style>
</head>
<body class="k-<?= $h($kind) ?>">
<header class="hero">
  <div class="wrap">
    <div class="htop">
      <a class="hbrand" href="/"><img src="<?= $h($logo) ?>" alt=""><span><b>S HARI GLOBAL</b><small>प्राइभेट लिमिटेड · आधिकारिक फारम</small></span></a>
      <nav class="tabs" aria-label="फारमको प्रकार">
        <a href="?kind=staff"<?= $kind === 'staff' ? ' class="on"' : '' ?>>🧑‍✈️ कर्मचारी</a>
        <a href="?kind=agent"<?= $kind === 'agent' ? ' class="on"' : '' ?>>🤝 एजेन्ट</a>
        <a href="?kind=partner"<?= $kind === 'partner' ? ' class="on"' : '' ?>>🏢 प्रा. लि.</a>
      </nav>
    </div>
    <h1><?= $h($K[2] . ' ' . $K[1]) ?></h1>
    <p><?= $h($K[3]) ?> — फारम र सम्झौता एउटै कागजमा। मोबाइल वा कम्प्युटरमै भर्नुहोस्, अनि कालो-सेतो A4 PDF बनाएर छाप्नुहोस् वा पठाउनुहोस्।</p>
    <div class="steps"><span>① विवरण भर्नुहोस्</span><span>② KYC र स्थान</span><span>③ सर्त पढेर सही</span><span>④ PDF / प्रिन्ट</span></div>
  </div>
</header>
<div class="prog"><div class="wrap"><div class="bar"><i id="pbar"></i></div><b id="ptxt">०% भरियो</b></div></div>

<main class="wrap">
  <form id="doc" autocomplete="on" novalidate></form>

  <section class="card" id="termsCard">
    <div class="ch"><span class="ic">📜</span><h2>सम्झौताका नियम र सर्तहरू<small>सबै सर्त पढ्नुहोस् — PDF मा यिनै सर्त पूरा छापिन्छन्</small></h2></div>
    <details class="terms"><summary>सबै सर्त खोल्नुहोस् (<?= (int) array_sum(array_map(static fn($s) => count($s['points']), $secs)) ?> बुँदा)</summary><div class="tb">
    <?php foreach ($secs as $sx): ?>
      <div class="sec"><?= $h(trim(($sx['no'] !== '' ? $sx['no'] . '. ' : '') . $sx['title'])) ?></div>
      <?php foreach ($sx['points'] as $pt): ?><p><?php if ($pt['num'] !== ''): ?><b class="n"><?= $h($pt['num']) ?></b> <?php endif; ?><?= $pt['html'] ?></p><?php endforeach; ?>
    <?php endforeach; ?>
    <?php if ($secs === []): ?><p>सर्तहरू अहिले खुलेनन् — <?= $h($shortUrl('agreements/')) ?> मा पढ्नुहोस्।</p><?php endif; ?>
    </div></details>
    <label class="agree"><input type="checkbox" id="agreeBox"> <span>मैले माथिका सबै नियम र सर्त पढें (वा पढेर सुनाइयो), बुझें र आफ्नै इच्छाले स्वीकार गर्छु।</span></label>
  </section>

  <section class="card">
    <div class="ch"><span class="ic">✅</span><h2>अर्को कदम<small>यो कागज यो फोन/कम्प्युटरमै बन्छ, कतै पठाइँदैन</small></h2></div>
    <ol class="rules">
      <li><b>PDF बनाउनुहोस्</b> — ४ पानाको कालो-सेतो A4 कागज (फारम + सम्झौता) डाउनलोड हुन्छ।</li>
      <li>छापेर हरेक पानामा छोटो सही र अन्तिममा पूरा सही र औंठाछाप लगाउनुहोस्।</li>
      <li>कार्यालयमा सक्कल परिचयपत्र देखाएर बुझाउनुहोस्, वा अनलाइन पठाउनुहोस्: <a href="/<?= $h($apply) ?>"><?= $h($shortUrl($apply)) ?></a></li>
      <li>कार्यालयले KYC जाँचेर स्वीकृत गरेपछि मात्र सम्झौता लागू हुन्छ र सम्झौता नं. दिइन्छ।</li>
    </ol>
  </section>

  <section id="previewWrap" hidden>
    <h2>👁 छाप्ने कागजको झलक — कालो-सेतो A4</h2>
    <div id="pages" aria-label="A4 पानाहरू"></div>
  </section>
</main>

<div class="acts">
  <p id="msg" aria-live="polite"></p>
  <div class="wrap">
    <button type="button" class="pri" id="doPdf">📄 PDF बनाउनुहोस्</button>
    <button type="button" class="sec2" id="doPrint">🖨 छाप्नुहोस्</button>
    <button type="button" class="ghost" id="doView">👁 झलक</button>
    <a class="ghost" href="/<?= $h($apply) ?>">📤 अनलाइन पठाउनुहोस्</a>
  </div>
</div>

<template id="chrome">
  <div class="sheet">
    <div class="lh">
      <img src="<?= $h($logo) ?>" alt="">
      <div class="co"><b><?= $h(mb_strtoupper((string) $co['legal'])) ?></b>
        <span><?= $h($co['address']) ?></span>
        <span>फोन <?= $h($co['phone']) ?> · इमेल <?= $h($co['email']) ?> · <?= $h($co['web']) ?><?= (string) ($co['cin'] ?? '') !== '' ? ' · CIN ' . $h($co['cin']) : '' ?></span></div>
      <div class="kb"><?= $h($K[0]) ?><small>आवेदन तथा सम्झौता</small></div>
    </div>
    <div class="rule2"></div>
    <div class="body"></div>
    <div class="pf"><span class="fno"></span><span><?= $h($co['web']) ?></span><span class="pno"></span></div>
  </div>
</template>

<template id="agrTerms">
  <div class="blk pgbreak"></div>
  <div class="blk agh">
    <div class="big">सम्झौता-पत्र</div>
    <div class="ti"><?= $h(['staff' => 'कर्मचारी सम्झौता', 'agent' => 'एजेन्ट सम्झौता', 'partner' => 'साझेदार कम्पनी सम्झौता'][$kind]) ?></div>
    <div class="sub">यो सम्झौता <?= $h($co['legal']) ?> (पहिलो पक्ष — कम्पनी) र तल लेखिएका <?= $h($K[0]) ?> (दोस्रो पक्ष) बीच तल लेखिएका नियम र सर्तमा भएको हो।</div>
  </div>
  <div class="blk" data-slot="parties"></div>
  <?php foreach ($secs as $sx): $first = true; ?>
    <?php foreach ($sx['points'] as $pt): ?>
  <div class="blk"><?php if ($first): $first = false; ?><div class="sec"><?= $h(trim(($sx['no'] !== '' ? $sx['no'] . '. ' : '') . $sx['title'])) ?></div><?php endif; ?><p class="pt"><?php if ($pt['num'] !== ''): ?><b class="n"><?= $h($pt['num']) ?></b> <?php endif; ?><?= $pt['html'] ?></p></div>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <div class="blk acc" data-slot="accept"></div>
  <div class="blk" data-slot="signs"></div>
</template>

<script>
(function () {
  'use strict';
  var KIND = <?= json_encode($kind) ?>, PREFIX = <?= json_encode($K[4]) ?>, KNAME = <?= json_encode($K[0], JSON_UNESCAPED_UNICODE) ?>;
  var TITLE = <?= json_encode($K[1], JSON_UNESCAPED_UNICODE) ?>, CO = <?= json_encode((string) $co['legal'], JSON_UNESCAPED_UNICODE) ?>;
  var QR = <?= json_encode($qr, JSON_UNESCAPED_SLASHES) ?>, APPLY = <?= json_encode($shortUrl($apply), JSON_UNESCAPED_SLASHES) ?>;
  var STORE = 'shg:hybrid:' + KIND;

  /* ---------- the fields: one list feeds the screen form AND the paper ---------- */
  var ID_TYPES = ['नागरिकता प्रमाणपत्र', 'राष्ट्रिय परिचयपत्र', 'आधार कार्ड', 'राहदानी (पासपोर्ट)', 'मतदाता परिचयपत्र', 'सवारी चालक अनुमतिपत्र'];
  var EDU = ['साक्षर', 'कक्षा ८', 'एसईई / एसएलसी (कक्षा १०)', 'कक्षा १२', 'डिप्लोमा', 'स्नातक', 'स्नातकोत्तर'];
  var ADDR = function (pre, title) {
    return [
      { k: pre + 'country', l: 'देश', t: 'select', o: ['नेपाल', 'भारत'], r: 1 },
      { k: pre + 'state', l: 'प्रदेश / राज्य', r: 1 },
      { k: pre + 'district', l: 'जिल्ला', r: 1 },
      { k: pre + 'local', l: 'नगरपालिका / गाउँपालिका', r: 1 },
      { k: pre + 'ward', l: 'वडा नं.', t: 'num' },
      { k: pre + 'tole', l: 'टोल / गाउँ / सडक' }
    ];
  };
  var S = [];
  if (KIND === 'partner') {
    S.push({ id: 'co', ic: '🏢', t: 'कम्पनीको विवरण', s: 'दर्ता प्रमाणपत्रमा लेखिए जस्तै', f: [
      { k: 'co_name', l: 'कम्पनीको पूरा नाम', r: 1, w: 'full' },
      { k: 'co_type', l: 'कम्पनीको प्रकार', t: 'select', o: ['प्राइभेट लिमिटेड', 'पब्लिक लिमिटेड', 'साझेदारी फर्म', 'एकल फर्म'], r: 1 },
      { k: 'co_reg', l: 'दर्ता नं.', r: 1 },
      { k: 'co_regdate', l: 'दर्ता मिति', t: 'date' },
      { k: 'co_regoffice', l: 'दर्ता भएको कार्यालय' },
      { k: 'co_pan', l: 'PAN / VAT / GST नं.', r: 1 },
      { k: 'co_biz', l: 'मुख्य कारोबार' },
      { k: 'co_phone', l: 'कम्पनीको फोन', t: 'tel' },
      { k: 'co_email', l: 'कम्पनीको इमेल', t: 'email' }
    ] });
    S.push({ id: 'rep', ic: '🧑‍💼', t: 'अधिकृत प्रतिनिधि', s: 'कम्पनीको तर्फबाट सही गर्ने व्यक्ति', f: [
      { k: 'name_ne', l: 'पूरा नाम (देवनागरीमा)', r: 1 },
      { k: 'name_en', l: 'पूरा नाम (अंग्रेजी ठूलो अक्षरमा)' },
      { k: 'rep_post', l: 'पद', r: 1 },
      { k: 'mobile', l: 'मोबाइल नम्बर', t: 'tel', r: 1 },
      { k: 'wa', l: 'WhatsApp नम्बर', t: 'tel' },
      { k: 'email', l: 'इमेल', t: 'email' }
    ] });
  } else {
    S.push({ id: 'me', ic: '👤', t: 'व्यक्तिगत विवरण', s: 'परिचयपत्रमा लेखिए जस्तै', f: [
      { k: 'name_ne', l: 'पूरा नाम (देवनागरीमा)', r: 1 },
      { k: 'name_en', l: 'पूरा नाम (अंग्रेजी ठूलो अक्षरमा)', r: 1 },
      { k: 'father', l: 'बुबाको नाम', r: 1 },
      { k: 'grandfather', l: 'बाजेको नाम' },
      { k: 'spouse', l: 'पति / पत्नीको नाम' },
      { k: 'dob', l: 'जन्म मिति', t: 'date', r: 1 },
      { k: 'gender', l: 'लिङ्ग', t: 'select', o: ['पुरुष', 'महिला', 'अन्य'], r: 1 },
      { k: 'nation', l: 'नागरिकता', t: 'select', o: ['नेपाली', 'भारतीय'], r: 1 },
      { k: 'mobile', l: 'मोबाइल नम्बर', t: 'tel', r: 1 },
      { k: 'wa', l: 'WhatsApp नम्बर', t: 'tel' },
      { k: 'email', l: 'इमेल', t: 'email' },
      { k: 'edu', l: 'शैक्षिक योग्यता', t: 'select', o: EDU }
    ] });
    if (KIND === 'staff') {
      S.push({ id: 'job', ic: '🚌', t: 'पद र अनुभव', s: 'कुन कामका लागि आवेदन दिँदै हुनुहुन्छ', f: [
        { k: 'post', l: 'पद', t: 'select', o: ['चालक', 'कन्डक्टर', 'सहचालक / हेल्पर', 'कार्यालय सहायक', 'लेखापाल', 'मेकानिक', 'सफाइकर्मी', 'सुरक्षा गार्ड', 'अन्य'], r: 1 },
        { k: 'base', l: 'काम गर्ने कार्यालय / स्थान', r: 1 },
        { k: 'exp', l: 'अनुभव (वर्ष)', t: 'num' },
        { k: 'prev', l: 'पहिले काम गरेको ठाउँ' },
        { k: 'lic_no', l: 'सवारी चालक अनुमतिपत्र नं. (चालकका लागि)' },
        { k: 'lic_cat', l: 'अनुमतिपत्रको वर्ग' },
        { k: 'lic_exp', l: 'अनुमतिपत्रको म्याद', t: 'date' },
        { k: 'health', l: 'स्वास्थ्य / रक्त समूह' }
      ] });
    } else {
      S.push({ id: 'work', ic: '🎫', t: 'कामको विवरण', s: 'कहाँबाट टिकट बेच्नुहुन्छ', f: [
        { k: 'shop', l: 'पसल / कार्यालयको नाम' },
        { k: 'area', l: 'काम गर्ने सहर / क्षेत्र', r: 1 },
        { k: 'pax', l: 'महिनामा अनुमानित यात्रु', t: 'num' },
        { k: 'prev', l: 'पहिले काम गरेको बस / ट्राभल कम्पनी' }
      ] });
    }
  }
  S.push({ id: 'addr', ic: '📍', t: KIND === 'partner' ? 'कम्पनीको ठेगाना र स्थान' : 'ठेगाना र स्थान', s: 'स्थायी ठेगाना, हाल बस्ने ठाउँ र GPS स्थान', f: ADDR('p_').concat([
    { k: 'now_addr', l: KIND === 'partner' ? 'शाखा / कार्यालय रहेको ठाउँ' : 'हाल बसोबास गरेको ठेगाना', t: 'area', w: 'full' },
    { k: 'landmark', l: 'नजिकको चिनिने ठाउँ', w: 'full' },
    { k: 'gps', t: 'gps', l: 'GPS स्थान' }
  ]) });
  S.push({ id: 'kyc', ic: '🪪', t: 'KYC — पहिचान र कागजात', s: 'सक्कल कागजात कार्यालयमा देखाउनुपर्छ', f: (KIND === 'partner'
    ? [{ k: 'id_type', l: 'प्रतिनिधिको परिचयपत्र', t: 'select', o: ID_TYPES, r: 1 }, { k: 'id_no', l: 'परिचयपत्र नं.', r: 1 },
       { k: 'id_office', l: 'जारी गर्ने कार्यालय / जिल्ला' }, { k: 'id_date', l: 'जारी मिति', t: 'date' }]
    : [{ k: 'id_type', l: 'परिचयपत्रको प्रकार', t: 'select', o: ID_TYPES, r: 1 }, { k: 'id_no', l: 'परिचयपत्र नं.', r: 1 },
       { k: 'id_office', l: 'जारी गर्ने कार्यालय / जिल्ला' }, { k: 'id_date', l: 'जारी मिति', t: 'date' }]
  ).concat([{ t: 'pics', k: 'pics' }]) });
  S.push({ id: 'bank', ic: '🏦', t: KIND === 'staff' ? 'तलब जाने बैंक खाता' : 'भुक्तानी — बैंक खाता', s: 'खाता आफ्नै नाममा हुनुपर्छ', f: [
    { k: 'bank', l: 'बैंकको नाम' }, { k: 'acc_name', l: 'खातावालाको नाम' },
    { k: 'acc_no', l: 'खाता नं.' }, { k: 'branch', l: 'शाखा' },
    { k: 'ifsc', l: 'IFSC / SWIFT कोड' }, { k: 'upi', l: 'UPI / eSewa / Khalti' }
  ] });
  S.push(KIND === 'partner'
    ? { id: 'wit', ic: '👥', t: 'दुई जना साक्षी', s: 'सम्झौतामा साक्षी बस्ने व्यक्ति', f: [
        { k: 'w1_name', l: 'साक्षी १ — नाम' }, { k: 'w1_addr', l: 'साक्षी १ — ठेगाना / फोन' },
        { k: 'w2_name', l: 'साक्षी २ — नाम' }, { k: 'w2_addr', l: 'साक्षी २ — ठेगाना / फोन' }] }
    : { id: 'ref', ic: '☎️', t: 'आपत्कालीन सम्पर्क', s: 'काम परे खबर गर्ने व्यक्ति', f: [
        { k: 'ref_name', l: 'नाम', r: KIND === 'staff' ? 1 : 0 }, { k: 'ref_rel', l: 'नाता' },
        { k: 'ref_phone', l: 'मोबाइल', t: 'tel', r: KIND === 'staff' ? 1 : 0 }, { k: 'ref_addr', l: 'ठेगाना' }] });
  S.push({ id: 'sign', ic: '✍️', t: 'सही र औंठाछाप', s: 'औंलाले वा माउसले सही गर्नुहोस्', f: [
    { k: 'sign_place', l: 'सही गरेको स्थान', r: 1 }, { k: 'sign_date', l: 'मिति', t: 'date', r: 1 },
    { t: 'sig', k: 'sig' }
  ] });

  var PICS = KIND === 'partner'
    ? [['photo', 'प्रतिनिधिको फोटो', '🤳', 1], ['logo', 'कम्पनीको लोगो', '🏷️', 0], ['reg_cert', 'दर्ता प्रमाणपत्र', '📄', 1], ['pan_cert', 'PAN / VAT प्रमाणपत्र', '🧾', 1], ['id_front', 'प्रतिनिधिको परिचयपत्र', '🪪', 1], ['thumb', 'दायाँ औंठाछाप (फोटो)', '👍', 0]]
    : [['photo', 'पासपोर्ट साइज फोटो', '🤳', 1], ['id_front', 'परिचयपत्र — अगाडि', '🪪', 1], ['id_back', 'परिचयपत्र — पछाडि', '🪪', 0], ['cert', KIND === 'staff' ? 'मार्कसिट / लाइसेन्स' : 'मार्कसिट / प्रमाणपत्र', '🎓', 0], ['thumb', 'दायाँ औंठाछाप (फोटो)', '👍', 0]];

  /* ---------- state: a draft in this browser only ---------- */
  var V = {}, IMG = {};
  function lsGet(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); return true; } catch (e) { return false; } }
  var saved = lsGet(STORE); if (saved && typeof saved === 'object') { V = saved.v || {}; IMG = saved.i || {}; }
  var tSave = 0;
  function save() {
    clearTimeout(tSave);
    tSave = setTimeout(function () { if (!lsSet(STORE, { v: V, i: IMG })) lsSet(STORE, { v: V, i: {} }); }, 300);
  }

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function today() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  if (!V.sign_date) V.sign_date = today();
  if (!V.form_no) V.form_no = PREFIX + '-' + today().replace(/-/g, '') + '-' + Math.random().toString(36).slice(2, 6).toUpperCase();

  /* ---------- the screen form ---------- */
  var form = document.getElementById('doc'), REQ = [];
  S.forEach(function (sec, si) {
    var c = el('section', 'card'); c.id = 's-' + sec.id;
    var ch = el('div', 'ch'), ic = el('span', 'ic', sec.ic), h2 = el('h2', '', (si + 1) + '. ' + sec.t);
    h2.appendChild(el('small', '', sec.s)); ch.appendChild(ic); ch.appendChild(h2); c.appendChild(ch);
    var g = el('div', 'grid');
    sec.f.forEach(function (f) {
      if (f.t === 'gps') { g.appendChild(gpsBox()); return; }
      if (f.t === 'pics') { var pw = el('div', 'f full'); pw.appendChild(picsBox()); g.appendChild(pw); return; }
      if (f.t === 'sig') { g.appendChild(sigBox()); return; }
      var w = el('div', 'f' + (f.w === 'full' || f.t === 'area' ? ' full' : '')), id = 'f_' + f.k;
      var lb = el('label', '', f.l); lb.htmlFor = id;
      if (f.r) { lb.appendChild(document.createTextNode(' ')); lb.appendChild(el('em', '', '*')); REQ.push(f.k); }
      w.appendChild(lb);
      var inp;
      if (f.t === 'select') {
        inp = el('select'); inp.appendChild(el('option', '', '— छान्नुहोस् —')).value = '';
        f.o.forEach(function (o) { var op = el('option', '', o); op.value = o; inp.appendChild(op); });
      } else if (f.t === 'area') {
        inp = el('textarea'); inp.rows = 2;
      } else {
        inp = el('input');
        inp.type = f.t === 'tel' ? 'tel' : f.t === 'email' ? 'email' : f.t === 'date' ? 'date' : 'text';
        if (f.t === 'num') inp.inputMode = 'numeric';
        if (f.t === 'tel') { inp.inputMode = 'tel'; inp.placeholder = '+९७७ / +९१ सहित'; }
      }
      inp.id = id; inp.name = f.k; inp.value = V[f.k] || '';
      inp.addEventListener('input', function () { V[f.k] = inp.value; w.classList.remove('bad'); save(); progress(); });
      inp.addEventListener('change', function () { V[f.k] = inp.value; save(); progress(); });
      w.appendChild(inp); w.dataset.k = f.k;
      g.appendChild(w);
    });
    c.appendChild(g); form.appendChild(c);
  });
  PICS.forEach(function (p) { if (p[3]) REQ.push('img:' + p[0]); });
  REQ.push('img:sig');

  function gpsBox() {
    var b = el('div', 'gps'), btn = el('button', '', '📍 मेरो हालको स्थान लिनुहोस्'), out = el('span');
    btn.type = 'button';
    function show() { out.textContent = V.gps ? 'GPS: ' + V.gps + (V.gps_acc ? ' (±' + V.gps_acc + ' मि.)' : '') : 'स्थान अझै लिइएको छैन — नक्सामा ठ्याक्कै ठाउँ देखाउन मद्दत गर्छ।'; }
    btn.addEventListener('click', function () {
      if (!navigator.geolocation) { out.textContent = 'यो ब्राउजरले स्थान दिँदैन।'; return; }
      out.textContent = '⏳ स्थान खोज्दै…';
      navigator.geolocation.getCurrentPosition(function (p) {
        V.gps = p.coords.latitude.toFixed(6) + ', ' + p.coords.longitude.toFixed(6); V.gps_acc = Math.round(p.coords.accuracy);
        save(); show(); progress();
      }, function () { out.textContent = 'स्थान लिन सकिएन — फोनमा Location खोलेर फेरि थिच्नुहोस्।'; }, { enableHighAccuracy: true, timeout: 15000 });
    });
    b.appendChild(btn); b.appendChild(out); show();
    return b;
  }

  function shrink(file, max) {
    return new Promise(function (ok, bad) {
      var r = new FileReader();
      r.onload = function () {
        var im = new Image();
        im.onload = function () {
          var s = Math.min(1, max / Math.max(im.width, im.height)), cv = document.createElement('canvas');
          cv.width = Math.round(im.width * s); cv.height = Math.round(im.height * s);
          var x = cv.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, cv.width, cv.height); x.drawImage(im, 0, 0, cv.width, cv.height);
          ok(cv.toDataURL('image/jpeg', 0.82));
        };
        im.onerror = bad; im.src = r.result;
      };
      r.onerror = bad; r.readAsDataURL(file);
    });
  }
  function picsBox() {
    var g = el('div', 'pics');
    PICS.forEach(function (p) {
      var box = el('label', 'pic'), inp = el('input');
      inp.type = 'file'; inp.accept = 'image/*'; if (p[0] === 'photo' || p[0] === 'thumb') inp.setAttribute('capture', p[0] === 'photo' ? 'user' : 'environment');
      function paint() {
        box.textContent = '';
        box.appendChild(el('span', 'big', p[2])); box.appendChild(el('b', '', p[1] + (p[3] ? ' *' : '')));
        box.appendChild(el('small', '', IMG[p[0]] ? '✔ राखियो — फेर्न थिच्नुहोस्' : 'फोटो खिच्नुहोस् वा छान्नुहोस्'));
        box.appendChild(inp);
        box.classList.toggle('done', !!IMG[p[0]]);
        if (IMG[p[0]]) {
          var im = el('img'); im.src = IMG[p[0]]; im.alt = p[1]; box.appendChild(im);
          var x = el('button', 'x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'हटाउनुहोस्');
          x.addEventListener('click', function (e) { e.preventDefault(); delete IMG[p[0]]; save(); paint(); progress(); });
          box.appendChild(x);
        }
      }
      inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0]; if (!f) return;
        shrink(f, p[0] === 'photo' || p[0] === 'thumb' || p[0] === 'logo' ? 700 : 1300).then(function (d) { IMG[p[0]] = d; save(); paint(); progress(); }, function () { say('यो फोटो खुलेन — अर्को फोटो छान्नुहोस्।'); });
      });
      paint(); g.appendChild(box);
    });
    return g;
  }

  function sigBox() {
    var w = el('div', 'sig'), cv = el('canvas'), row = el('div', 'row'), clr = el('button', '', '🧽 मेटाउनुहोस्');
    clr.type = 'button';
    row.appendChild(el('span', '', 'यहाँ सही गर्नुहोस् *')); row.appendChild(clr);
    w.appendChild(cv); w.appendChild(row);
    var ctx, drawing = false, last = null, dirty = false;
    function size() {
      var r = cv.getBoundingClientRect(); if (!r.width) return;
      cv.width = Math.round(r.width * 2); cv.height = Math.round(r.height * 2);
      ctx = cv.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cv.width, cv.height);
      ctx.lineWidth = 4.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0a1a3a';
      if (IMG.sig) { var im = new Image(); im.onload = function () { ctx.drawImage(im, 0, 0, cv.width, cv.height); }; im.src = IMG.sig; }
    }
    function pos(e) { var r = cv.getBoundingClientRect(); return { x: (e.clientX - r.left) * cv.width / r.width, y: (e.clientY - r.top) * cv.height / r.height }; }
    cv.addEventListener('pointerdown', function (e) { if (!ctx) size(); drawing = true; last = pos(e); cv.setPointerCapture(e.pointerId); e.preventDefault(); });
    cv.addEventListener('pointermove', function (e) {
      if (!drawing) return; var p = pos(e);
      ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; dirty = true;
    });
    function end() { if (!drawing) return; drawing = false; if (dirty) { IMG.sig = cv.toDataURL('image/png'); save(); progress(); } }
    cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end); cv.addEventListener('pointerleave', end);
    clr.addEventListener('click', function () { delete IMG.sig; dirty = false; size(); save(); progress(); });
    requestAnimationFrame(size);
    var rw = 0; window.addEventListener('resize', function () { var x = cv.getBoundingClientRect().width; if (Math.abs(x - rw) > 4) { rw = x; size(); } });
    return w;
  }

  var agree = document.getElementById('agreeBox');
  agree.checked = !!V.agree;
  agree.addEventListener('change', function () { V.agree = agree.checked ? '1' : ''; save(); progress(); });

  function progress() {
    var all = REQ.length + 1, done = 0;
    REQ.forEach(function (k) { if (k.indexOf('img:') === 0 ? IMG[k.slice(4)] : String(V[k] || '').trim()) done++; });
    if (V.agree) done++;
    var p = Math.round(done * 100 / all);
    document.getElementById('pbar').style.width = p + '%';
    document.getElementById('ptxt').textContent = nep(p) + '% भरियो';
  }
  function nep(n) { return String(n).replace(/\d/g, function (d) { return '०१२३४५६७८९'[d]; }); }
  progress();

  /* ---------- the paper: black and white A4 ---------- */
  var msg = document.getElementById('msg'), pages = document.getElementById('pages'), tpl = document.getElementById('chrome'), LH = '';
  function say(t) { msg.textContent = t; }
  function gray(src) {
    return new Promise(function (ok) {
      if (!src) { ok(''); return; }
      var im = new Image();
      im.onload = function () {
        var cv = document.createElement('canvas'); cv.width = im.width; cv.height = im.height;
        var x = cv.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, cv.width, cv.height); x.drawImage(im, 0, 0);
        try {
          var d = x.getImageData(0, 0, cv.width, cv.height), a = d.data;
          for (var i = 0; i < a.length; i += 4) { var y = Math.round(a[i] * 0.299 + a[i + 1] * 0.587 + a[i + 2] * 0.114); a[i] = a[i + 1] = a[i + 2] = y; }
          x.putImageData(d, 0, 0); ok(cv.toDataURL('image/jpeg', 0.85));
        } catch (e) { ok(src); }
      };
      im.onerror = function () { ok(''); }; im.src = src;
    });
  }
  function val(k) { return String(V[k] || '').trim(); }
  function bg(div, src) { if (src) div.style.backgroundImage = 'url("' + src + '")'; return div; }
  function table(rows) {
    var t = el('table', 'tb2'), tr = null;
    rows.forEach(function (r, i) {
      if (r.full) { tr = el('tr'); var l = el('td', 'l', r.l), v = el('td', 'v', r.v); v.colSpan = 3; tr.appendChild(l); tr.appendChild(v); t.appendChild(tr); tr = null; return; }
      if (!tr) { tr = el('tr'); t.appendChild(tr); }
      tr.appendChild(el('td', 'l', r.l)); tr.appendChild(el('td', 'v', r.v));
      if (tr.children.length >= 4) tr = null;
    });
    if (tr && tr.children.length === 2) { tr.appendChild(el('td', 'l', '')); tr.appendChild(el('td', 'v', '')); }
    return t;
  }
  function secRows(sec) {
    var rows = [];
    sec.f.forEach(function (f) {
      if (f.t === 'pics' || f.t === 'sig') return;
      if (f.t === 'gps') { rows.push({ l: 'GPS स्थान (अक्षांश, देशान्तर)', v: val('gps'), full: 1 }); return; }
      rows.push({ l: f.l, v: val(f.k), full: f.w === 'full' || f.t === 'area' });
    });
    return rows;
  }
  function blk(cls) { return el('div', 'blk' + (cls ? ' ' + cls : '')); }

  function build(G) {
    var flow = el('div'); flow.id = 'flow';
    var sn = 0;
    // page 1 head: title, form no., QR to the online form, photo
    var hd = blk('dt'), t = el('div', 't');
    t.appendChild(el('h1', '', TITLE));
    t.appendChild(el('div', 'sub', CO + ' — आधिकारिक फारम। ठूला अक्षरमा, सफा र साँचो विवरण लेख्नुहोस्। * चिन्ह भएका विवरण अनिवार्य।'));
    var no = el('div', 'no'); no.appendChild(document.createTextNode('फारम सन्दर्भ नं.: ')); no.appendChild(el('b', '', V.form_no));
    no.appendChild(document.createTextNode('   ·   बुझाएको मिति: ' + (val('sign_date') || '____________'))); t.appendChild(no);
    hd.appendChild(t);
    if (QR) { var q = el('div', 'qr'), qi = el('img'); qi.src = QR; qi.alt = ''; q.appendChild(qi); q.appendChild(document.createTextNode('अनलाइन फारम')); hd.appendChild(q); }
    var ph = el('div', 'ph'); if (G.photo) ph.appendChild(bg(el('div'), G.photo)); else ph.textContent = 'पासपोर्ट साइज फोटो टाँस्नुहोस्'; hd.appendChild(ph);
    if (KIND === 'partner' && G.logo) { var lg = el('div', 'ph'); lg.style.width = '24mm'; lg.style.height = '24mm'; var ld = bg(el('div'), G.logo); ld.style.backgroundSize = 'contain'; lg.appendChild(ld); hd.appendChild(lg); }
    flow.appendChild(hd);

    S.forEach(function (sec) {
      if (sec.id === 'sign') return;
      sn++;
      var b = blk(); b.appendChild(el('div', 'sh', sn + '. ' + sec.t));
      var rows = secRows(sec);
      if (rows.length) b.appendChild(table(rows));
      if (sec.id === 'kyc') {
        var d = el('div', 'docs');
        PICS.forEach(function (p) {
          if (p[0] === 'photo' || p[0] === 'thumb' || p[0] === 'logo') return;
          var fg = el('figure'), dv = bg(el('div'), G[p[0]]); fg.appendChild(dv); fg.appendChild(el('figcaption', '', p[1])); d.appendChild(fg);
        });
        b.appendChild(d);
      }
      flow.appendChild(b);
    });

    var r = blk(); r.appendChild(el('div', 'sh', (++sn) + '. फारम भर्ने र स्वीकृतिका नियम'));
    var ul = el('ol', 'nr');
    ['सबै विवरण साँचो र परिचयपत्रसँग मिल्ने हुनुपर्छ; झुटो विवरण वा नक्कली कागजात भेटिए आवेदन रद्द हुन्छ र कानुनी कारबाही हुन सक्छ।',
     'KYC का लागि सक्कल परिचयपत्र कार्यालयमा देखाउनुपर्छ; कम्पनीले प्रतिलिपि सुरक्षित राख्छ र कानुनले माग्दा बाहेक अरूलाई दिँदैन।',
     'मोबाइल र WhatsApp नम्बर आफ्नै र चालु हुनुपर्छ; कम्पनीका सबै सूचना यही नम्बरमा पठाइन्छ।',
     'बैंक खाता आवेदक' + (KIND === 'partner' ? ' कम्पनी' : '') + 'को आफ्नै नाममा हुनुपर्छ; अरूको खातामा भुक्तानी गरिँदैन।',
     'कार्यालयले जाँचेर स्वीकृत गरेपछि मात्र सम्झौता लागू हुन्छ र सम्झौता नं. दिइन्छ; त्यसअघि कम्पनीको नाममा काम गर्न पाइँदैन।',
     'हरेक पानामा छोटो सही र अन्तिम पानामा पूरा सही र औंठाछाप अनिवार्य छ; केरमेट भएको ठाउँमा पनि सही गर्नुपर्छ।',
     'विवरण (फोन, ठेगाना, बैंक) फेरिए ७ दिनभित्र कार्यालयलाई लिखित जानकारी दिनुपर्छ।',
     'यो फारमसँगै छापिएका सम्झौताका सबै नियम र सर्त दुवै पक्षलाई मान्य हुन्छन्।'
    ].forEach(function (x) { ul.appendChild(el('li', '', x)); });
    r.appendChild(ul); flow.appendChild(r);

    var dc = blk('decl');
    dc.appendChild(el('b', '', 'घोषणा: '));
    dc.appendChild(document.createTextNode('म/हामी माथि लेखिएका सबै विवरण साँचो हुन् र संलग्न कागजात मेरै/हाम्रै हुन् भनी घोषणा गर्छु/गर्छौं। ' + (KIND === 'partner' ? 'म यो कम्पनीको तर्फबाट सही गर्न अधिकृत छु। ' : '') + 'यो फारम र सम्झौतामा लेखिएका नियम पालना गर्न मञ्जुर छु।'));
    flow.appendChild(dc);

    var off = blk('off');
    ['कार्यालय प्रयोग — जाँच गर्नेको नाम', 'सक्कल परिचयपत्र देखियो ☐   KYC प्रमाणित ☐   स्वीकृत ☐', 'सम्झौता नं. · सही · मिति · छाप'].forEach(function (x) { off.appendChild(el('div', '', x)); });
    flow.appendChild(off);

    // the agreement (pages 3-4)
    var ag = document.getElementById('agrTerms').content.cloneNode(true);
    var who = val('name_ne') || val('name_en');
    var pt = ag.querySelector('[data-slot="parties"]');
    pt.appendChild(el('div', 'sec', 'पक्षहरू'));
    pt.appendChild(table([
      { l: 'पहिलो पक्ष — कम्पनी', v: CO + ' · ' + <?= json_encode((string) $co['address'], JSON_UNESCAPED_UNICODE) ?>, full: 1 },
      KIND === 'partner' ? { l: 'दोस्रो पक्ष — कम्पनी', v: val('co_name') + (val('co_reg') ? ' · दर्ता नं. ' + val('co_reg') : '') + (val('co_pan') ? ' · PAN/VAT ' + val('co_pan') : ''), full: 1 } : { l: 'दोस्रो पक्ष — ' + KNAME, v: who + (val('father') ? ' · बुबा: ' + val('father') : ''), full: 1 },
      KIND === 'partner' ? { l: 'प्रतिनिधि', v: who + (val('rep_post') ? ' · ' + val('rep_post') : '') } : { l: 'परिचयपत्र', v: [val('id_type'), val('id_no')].filter(Boolean).join(' · ') },
      { l: 'मोबाइल', v: val('mobile') },
      { l: 'ठेगाना', v: [val('p_tole'), val('p_ward') ? 'वडा ' + val('p_ward') : '', val('p_local'), val('p_district'), val('p_state'), val('p_country')].filter(Boolean).join(', '), full: 1 }
    ]));
    ag.querySelector('[data-slot="accept"]').textContent = 'मैले/हामीले माथिका सबै नियम र सर्त पढें (वा पढेर सुनाइयो), बुझें र आफ्नै इच्छाले स्वीकार गर्छु/गर्छौं।' + (V.agree ? '  ☑ अनलाइन फारममा स्वीकार गरियो।' : '');
    var sg = ag.querySelector('[data-slot="signs"]'), grid = el('div', 'sgr');
    var a = el('div', 'sb'); a.appendChild(el('b', '', 'पहिलो पक्ष — कम्पनीको तर्फबाट'));
    [['सही', ''], ['नाम · पद', ''], ['मिति', '']].forEach(function (x) { var ln = el('div', 'ln'); ln.appendChild(el('span', '', x[0])); ln.appendChild(el('i', '', x[1])); a.appendChild(ln); });
    var st = el('div', 'thr'), sd = el('div'); var bx = el('div', 'tbx'); bx.style.borderRadius = '4px'; bx.style.width = '30mm'; sd.appendChild(bx); sd.appendChild(document.createTextNode('कम्पनीको छाप')); st.appendChild(sd); a.appendChild(st);
    var b2 = el('div', 'sb'); b2.appendChild(el('b', '', 'दोस्रो पक्ष — ' + KNAME));
    if (G.sig) { b2.appendChild(bg(el('div', 'sgi'), G.sig)); } else { var l0 = el('div', 'ln'); l0.appendChild(el('span', '', 'सही')); l0.appendChild(el('i')); b2.appendChild(l0); }
    [['नाम', who], ['स्थान · मिति', [val('sign_place'), val('sign_date')].filter(Boolean).join(' · ')]].forEach(function (x) { var ln = el('div', 'ln'); ln.appendChild(el('span', '', x[0])); ln.appendChild(el('i', '', x[1])); b2.appendChild(ln); });
    var th = el('div', 'thr');
    [['दायाँ औंठा', G.thumb], ['बायाँ औंठा', '']].forEach(function (x) { var d = el('div'); d.appendChild(bg(el('div', 'tbx'), x[1])); d.appendChild(document.createTextNode(x[0])); th.appendChild(d); });
    b2.appendChild(th);
    grid.appendChild(a); grid.appendChild(b2); sg.appendChild(grid);
    if (KIND === 'partner') {
      var wg = el('div', 'sgr');
      [1, 2].forEach(function (n) {
        var w = el('div', 'sb'); w.style.minHeight = '0'; w.appendChild(el('b', '', 'साक्षी ' + nep(n)));
        [['नाम', val('w' + n + '_name')], ['ठेगाना / फोन', val('w' + n + '_addr')], ['सही', '']].forEach(function (x) { var ln = el('div', 'ln'); ln.appendChild(el('span', '', x[0])); ln.appendChild(el('i', '', x[1])); w.appendChild(ln); });
        wg.appendChild(w);
      });
      sg.appendChild(wg);
    }
    flow.appendChild(ag);
    return flow;
  }

  function sheetBody() {
    var s = tpl.content.firstElementChild.cloneNode(true);
    if (pages.querySelector('.sheet')) s.classList.add('cont');
    s.querySelector('.fno').textContent = 'फारम सन्दर्भ नं. ' + V.form_no;
    if (LH) s.querySelector('.lh img').src = LH;   // a grey logo: html2canvas does not apply CSS filters
    pages.appendChild(s);
    return s.querySelector('.body');
  }
  function paginate(flow) {
    pages.textContent = ''; pages.style.zoom = '';
    var body = sheetBody();
    Array.prototype.slice.call(flow.children).forEach(function (b) {
      // the agreement opens on a fresh sheet only when this one is already over half full, otherwise a dashed rule
      // parts them - so every kind stays about 4 pages (measure the last child: scrollHeight is never < clientHeight)
      if (b.classList.contains('pgbreak')) {
        var last = body.lastElementChild, used = last ? last.getBoundingClientRect().bottom - body.getBoundingClientRect().top : 0;
        if (body.children.length && used > body.clientHeight * 0.5) body = sheetBody();
        else if (body.children.length) body.appendChild(el('div', 'agsep'));
        return;
      }
      body.appendChild(b);
      if (body.scrollHeight > body.clientHeight + 1 && body.children.length > 1) { body = sheetBody(); body.appendChild(b); }
    });
    flow.remove();
    var all = pages.querySelectorAll('.sheet');
    for (var i = 0; i < all.length; i++) all[i].querySelector('.pno').textContent = 'पाना ' + nep(i + 1) + ' / ' + nep(all.length);
  }
  function fit() {
    var s = pages.querySelector('.sheet'); if (!s) return;
    var z = Math.min(1, (pages.clientWidth - 8) / s.offsetWidth);
    pages.style.zoom = z < 0.999 ? String(z) : '';
  }
  window.addEventListener('resize', fit);

  function missing() {
    var m = [];
    REQ.forEach(function (k) {
      if (k.indexOf('img:') === 0) { if (!IMG[k.slice(4)]) m.push(k); return; }
      if (!String(V[k] || '').trim()) { m.push(k); var w = form.querySelector('[data-k="' + k + '"]'); if (w) w.classList.add('bad'); }
    });
    return m;
  }
  var fonts = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
  function render() {
    var keys = ['photo', 'logo', 'id_front', 'id_back', 'cert', 'reg_cert', 'pan_cert', 'thumb', 'sig'], G = {};
    var lh = LH ? Promise.resolve() : gray(tpl.content.querySelector('.lh img').src).then(function (d) { LH = d; });
    return Promise.all(keys.map(function (k) { return gray(IMG[k]).then(function (d) { G[k] = d; }); }).concat([lh])).then(function () { return fonts; }).then(function () {
      var wrap = document.getElementById('previewWrap'); wrap.hidden = false;
      var flow = build(G); document.body.appendChild(flow);
      paginate(flow); fit();
      var m = missing();
      say(m.length ? '⚠ केही अनिवार्य विवरण बाँकी छन् (रातो) — खाली ठाउँ छापेर हातले भर्न पनि सकिन्छ।' : '✔ सबै अनिवार्य विवरण भरिए।');
    });
  }

  function load(urls) {
    return new Promise(function (ok, bad) {
      var i = 0;
      (function next() {
        if (i >= urls.length) { bad(new Error('load')); return; }
        var s = document.createElement('script'); s.src = urls[i++]; s.onload = ok; s.onerror = next; document.head.appendChild(s);
      })();
    });
  }
  function libs() {
    var a = window.html2canvas ? Promise.resolve() : load(['https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js']);
    var b = window.jspdf && window.jspdf.jsPDF ? Promise.resolve() : load(['https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js', 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js']);
    return Promise.all([a, b]);
  }
  var busy = false;
  function fileName() { return 'SHG-' + KIND + '-' + (val('name_en') || val('co_name') || 'form').replace(/[^A-Za-z0-9]+/g, '-').slice(0, 30) + '-' + V.form_no + '.pdf'; }
  document.getElementById('doPdf').addEventListener('click', function () {
    if (busy) return; busy = true;
    var btn = this, label = btn.textContent; btn.disabled = true; btn.textContent = '⏳ बन्दै…';
    render().then(libs).then(function () {
      var all = Array.prototype.slice.call(pages.querySelectorAll('.sheet')), pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
      pages.style.zoom = '';
      var chain = Promise.resolve();
      all.forEach(function (sh, i) {
        chain = chain.then(function () {
          say('⏳ पाना ' + nep(i + 1) + ' / ' + nep(all.length) + ' बन्दै…');
          return window.html2canvas(sh, { scale: 2, backgroundColor: '#ffffff', logging: false });
        }).then(function (cv) {
          if (i > 0) pdf.addPage();
          pdf.addImage(cv.toDataURL('image/jpeg', 0.86), 'JPEG', 0, 0, 210, 297);
        });
      });
      return chain.then(function () { return pdf.output('blob'); });
    }).then(function (blob) {
      var name = fileName(), file = null;
      try { file = new File([blob], name, { type: 'application/pdf' }); } catch (e) { file = null; }
      var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name;
      document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
      say('✔ PDF डाउनलोड भयो — ' + name);
      if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
        var sh = document.getElementById('doShare');
        if (!sh) { sh = el('button', 'pri', '📤 PDF सेयर / WhatsApp'); sh.type = 'button'; sh.id = 'doShare'; msg.appendChild(document.createTextNode(' ')); }
        sh.onclick = function () { navigator.share({ files: [file], title: TITLE }).catch(function () {}); };
        msg.appendChild(sh);
      }
    }).catch(function () {
      say('अहिले PDF बन्न सकेन — "🖨 छाप्नुहोस्" थिचेर "Save as PDF" रोज्नुहोस्।');
    }).then(function () { fit(); busy = false; btn.disabled = false; btn.textContent = label; });
  });
  document.getElementById('doPrint').addEventListener('click', function () { render().then(function () { pages.style.zoom = ''; setTimeout(function () { window.print(); fit(); }, 250); }); });
  document.getElementById('doView').addEventListener('click', function () { render().then(function () { document.getElementById('previewWrap').scrollIntoView({ behavior: 'instant', block: 'start' }); }); });
})();
</script>
<script src="/assets/js/wa-direct.js?v=20261022a"></script>
</body>
</html>
