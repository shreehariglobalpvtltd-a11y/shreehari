<?php
/**
 * =====================================================================
 *  help.php — Help Desk / सहायता केन्द्र (5 Oct 2026)
 *
 *  The customer's door to the Help Desk: pick what happened, write two
 *  lines, get a number (HD-0051 + today's token) in one tap. The same page
 *  shows the ticket's status and the desk's replies once the person types
 *  the mobile they opened it with (?ref=HD-0051 comes from the WhatsApp /
 *  SMS message). Stand-alone (no app bundle, no map), so it opens in a
 *  second on any phone. Talks to /api/helpdesk.php.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/helpdesk.php';
require_once INCLUDE_PATH . '/brand.php';   // emblem (no baked name, no white tile) + SVG flags — 9 Oct 2026

Security::requireRateLimit('help_page', Security::clientIp(), 60, 60);

$lang = in_array($_GET['lang'] ?? '', ['en', 'hi', 'ne'], true) ? (string) $_GET['lang'] : 'ne';
$ref  = HelpDesk::normRef((string) ($_GET['ref'] ?? '')) ?? '';
$pnr  = strtoupper(Security::clean((string) ($_GET['pnr'] ?? ''), 30));
$cat0 = isset(HelpDesk::CATEGORIES[(string) ($_GET['topic'] ?? '')]) ? (string) $_GET['topic'] : '';

$T = [
    'ne' => ['title' => 'सहायता केन्द्र', 'sub' => 'समस्या लेख्नुहोस् — तुरुन्त नम्बर पाउनुहुन्छ। हाम्रो टिमले त्यही नम्बरबाट हेर्छ।',
             'tabNew' => 'नयाँ समस्या', 'tabCheck' => 'मेरो नम्बर हेर्ने', 'what' => 'के भयो?', 'msg' => 'छोटकरीमा लेख्नुहोस्',
             'msgPh' => 'जस्तै: टिकट आएन, बस कहाँ छ, पैसा कट्यो तर टिकट आएन…', 'name' => 'नाम', 'phone' => 'मोबाइल नम्बर', 'pnr' => 'टिकट / PNR (भए)',
             'send' => 'पठाउनुहोस् र नम्बर लिनुहोस्', 'yourNo' => 'तपाईंको सहायता नम्बर', 'token' => 'आजको टोकन',
             'keep' => 'यो नम्बर सुरक्षित राख्नुहोस्। फोन गर्दा यही नम्बर भन्नुहोस्।', 'check' => 'स्थिति हेर्नुहोस्', 'ref' => 'सहायता नम्बर (HD-…)',
             'status' => ['open' => 'दर्ता भयो — टिमले हेर्दैछ', 'in_progress' => 'काम भइरहेको छ', 'resolved' => 'समाधान भयो', 'closed' => 'बन्द भयो'],
             'replies' => 'कुराकानी', 'you' => 'तपाईं', 'staff' => 'S Hari टिम', 'add' => 'थप कुरा लेख्नुहोस्', 'addBtn' => 'पठाउनुहोस्',
             'call' => 'कार्यालयमा फोन', 'wa' => 'WhatsApp', 'urgent' => 'आपतकाल भए तुरुन्त फोन गर्नुहोस्:', 'sent' => 'नम्बर तपाईंको WhatsApp/SMS मा पनि पठाइयो।',
             'dup' => 'यही समस्या पहिले नै दर्ता छ:', 'save' => 'स्क्रिनसट लिनुहोस्', 'shareWa' => 'नम्बर WhatsApp मा राख्नुहोस्', 'none' => 'अहिलेसम्म जवाफ आएको छैन — टिमले हेर्दैछ।'],
    'hi' => ['title' => 'सहायता केंद्र', 'sub' => 'समस्या लिखें — तुरंत नंबर मिलेगा। हमारी टीम उसी नंबर से देखेगी।',
             'tabNew' => 'नई समस्या', 'tabCheck' => 'मेरा नंबर देखें', 'what' => 'क्या हुआ?', 'msg' => 'थोड़ा लिखें',
             'msgPh' => 'जैसे: टिकट नहीं आया, बस कहाँ है, पैसा कटा पर टिकट नहीं आया…', 'name' => 'नाम', 'phone' => 'मोबाइल नंबर', 'pnr' => 'टिकट / PNR (हो तो)',
             'send' => 'भेजें और नंबर पाएं', 'yourNo' => 'आपका सहायता नंबर', 'token' => 'आज का टोकन',
             'keep' => 'यह नंबर संभाल कर रखें। फ़ोन करते समय यही नंबर बताएं।', 'check' => 'स्थिति देखें', 'ref' => 'सहायता नंबर (HD-…)',
             'status' => ['open' => 'दर्ज हो गई — टीम देख रही है', 'in_progress' => 'काम चल रहा है', 'resolved' => 'हल हो गई', 'closed' => 'बंद'],
             'replies' => 'बातचीत', 'you' => 'आप', 'staff' => 'S Hari टीम', 'add' => 'और लिखें', 'addBtn' => 'भेजें',
             'call' => 'ऑफ़िस को कॉल', 'wa' => 'WhatsApp', 'urgent' => 'आपातकाल हो तो तुरंत कॉल करें:', 'sent' => 'नंबर आपके WhatsApp/SMS पर भी भेजा गया।',
             'dup' => 'यही समस्या पहले से दर्ज है:', 'save' => 'स्क्रीनशॉट ले लें', 'shareWa' => 'नंबर WhatsApp में सेव करें', 'none' => 'अभी तक जवाब नहीं — टीम देख रही है।'],
    'en' => ['title' => 'Help Desk', 'sub' => 'Tell us the problem — you get a number straight away. Our team works on it under that number.',
             'tabNew' => 'New problem', 'tabCheck' => 'Check my number', 'what' => 'What happened?', 'msg' => 'A few words',
             'msgPh' => 'e.g. ticket not received, where is the bus, money deducted but no ticket…', 'name' => 'Name', 'phone' => 'Mobile number', 'pnr' => 'Ticket / PNR (if any)',
             'send' => 'Send & get my number', 'yourNo' => 'Your help desk number', 'token' => 'Today’s token',
             'keep' => 'Keep this number. Say it when you call us.', 'check' => 'Check status', 'ref' => 'Help desk number (HD-…)',
             'status' => ['open' => 'Registered — the team is looking', 'in_progress' => 'Being worked on', 'resolved' => 'Resolved', 'closed' => 'Closed'],
             'replies' => 'Conversation', 'you' => 'You', 'staff' => 'S Hari team', 'add' => 'Add something', 'addBtn' => 'Send',
             'call' => 'Call the office', 'wa' => 'WhatsApp', 'urgent' => 'Emergency? Call now:', 'sent' => 'The number was also sent to your WhatsApp/SMS.',
             'dup' => 'This problem is already registered:', 'save' => 'Take a screenshot', 'shareWa' => 'Keep the number in WhatsApp', 'none' => 'No reply yet — the team is on it.'],
][$lang];

$e       = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$company = Settings::getString('company_name', APP_NAME);
$phone   = Settings::officePhone();
$waOff   = Settings::officeWhatsApp();
$cats    = HelpDesk::CATEGORIES;
$icons   = ['booking_help' => '🎫', 'ticket' => '📩', 'payment' => '💳', 'refund' => '↩️', 'bus_late' => '🚌', 'seat' => '💺',
            'luggage' => '🧳', 'staff' => '🧑‍✈️', 'safety' => '🆘', 'callback' => '📞', 'other' => '💬'];
$on      = HelpDesk::on();

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
?>
<!doctype html>
<html lang="<?= $e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#12264E">
<meta name="csrf" content="<?= $e(Security::csrfToken()) ?>">
<title><?= $e($T['title']) ?> · <?= $e($company) ?></title>
<?= Brand::iconLinks() ?>
<script>/* The app's saved theme before the first frame (8 Oct 2026): localStorage 'shg:theme' = light | dark | amoled, else the phone's setting. */
(function(){try{var d=document.documentElement,t=(localStorage.getItem('shg:theme')||'').replace(/"/g,'');if(t!=='light'&&t!=='dark'&&t!=='amoled')t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';d.setAttribute('data-theme',t==='light'?'light':'dark');if(t==='amoled')d.setAttribute('data-display','amoled')}catch(e){}})();</script>
<style>
:root{--blue:#2E5FA8;--navy:#12264E;--orange:#F07C1F;--ok:#178A50;--warn:#B7791F;--bad:#C53030;--ink:#16233C;--muted:#5C6B85;--line:#E2E9F4;--bg:#F6F8FC;--card:#fff;--chip:#EEF3FB}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--ink:#F1F5FB;--muted:#A9B6CC;--line:#243352;--bg:#0E1626;--card:#16213A;--chip:#1C2A47}}
:root[data-theme="dark"]{--ink:#F1F5FB;--muted:#A9B6CC;--line:#243352;--bg:#0E1626;--card:#16213A;--chip:#1C2A47;color-scheme:dark}
:root[data-display="amoled"]{--ink:#EEF4FF;--muted:#ACBBD0;--line:#2C3D55;--bg:#000;--card:#101822;--chip:#1A2638;--blue:#28579C}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Noto Sans Devanagari','Noto Sans',sans-serif;background:var(--bg);color:var(--ink);line-height:1.5;-webkit-font-smoothing:antialiased}
.wrap{max-width:560px;margin:0 auto;padding:16px 16px calc(28px + env(safe-area-inset-bottom))}
.top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;color:var(--navy);text-decoration:none}
.brand img{height:34px;width:auto;max-width:70px;object-fit:contain;flex:none;filter:drop-shadow(0 2px 4px rgba(18,38,78,.22))}
.brand .flg-pair{gap:3px}
@media (max-width:380px){.brand .flg-pair{display:none!important}}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]) .brand{color:#fff}}
:root[data-theme="dark"] .brand{color:#fff}
.langs a{font-size:12px;font-weight:700;color:var(--muted);text-decoration:none;padding:4px 8px;border-radius:999px;border:1px solid var(--line);margin-left:4px}
.langs a.on{background:var(--blue);color:#fff;border-color:var(--blue)}
h1{font-size:24px;margin:4px 0 2px}
.sub{color:var(--muted);font-size:14px;margin-bottom:14px}
.tabs{display:grid;grid-template-columns:1fr 1fr;gap:6px;background:var(--chip);padding:5px;border-radius:14px;margin-bottom:12px}
.tabs button{border:0;background:transparent;color:var(--ink);font:inherit;font-weight:700;padding:10px;border-radius:10px;cursor:pointer;min-height:44px}
.tabs button.on{background:var(--card);box-shadow:0 2px 10px rgba(18,38,78,.12)}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:16px;box-shadow:0 6px 24px rgba(18,38,78,.08);margin-bottom:12px}
.lbl{display:block;font-size:12.5px;font-weight:700;color:var(--muted);margin:12px 0 6px}
.cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,150px),1fr));gap:8px}
.cats label{display:flex;align-items:center;gap:8px;padding:10px;border:1.5px solid var(--line);border-radius:12px;cursor:pointer;font-size:13.5px;font-weight:600;min-height:48px;background:var(--card)}
.cats label{position:relative}
.cats input{position:absolute;left:0;top:0;width:1px;height:1px;min-height:0;padding:0;border:0;opacity:0;pointer-events:none}
.cats label:has(input:checked){border-color:var(--blue);background:var(--chip)}
.cats label.sos:has(input:checked){border-color:var(--bad)}
.cats .ic{font-size:20px}
input,textarea,select{width:100%;padding:12px;border:1.5px solid var(--line);border-radius:12px;background:var(--card);color:var(--ink);font:inherit;font-size:16px;min-height:48px}
textarea{min-height:96px;resize:vertical}
input:focus,textarea:focus,select:focus{outline:none;border-color:var(--blue)}
.row{display:grid;grid-template-columns:110px minmax(0,1fr);gap:8px}
.btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:52px;margin-top:14px;border-radius:14px;font-weight:800;font-size:16px;text-decoration:none;border:1px solid var(--line);color:var(--ink);background:var(--card);cursor:pointer;font-family:inherit}
.btn.blue{background:var(--blue);border-color:var(--blue);color:#fff}
.btn.wa{background:#25D366;border-color:#25D366;color:#fff}
.btn[disabled]{opacity:.6}
.err{color:var(--bad);font-size:13px;margin-top:6px;min-height:1px}
.done{text-align:center;padding:22px 16px}
.done .big{font-size:40px;font-weight:900;letter-spacing:1px;color:var(--navy);margin:6px 0}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]) .done .big{color:#fff}}
:root[data-theme="dark"] .done .big{color:#fff}
.done .tok{display:inline-block;background:var(--orange);color:#fff;font-weight:800;border-radius:999px;padding:4px 14px;font-size:14px}
.muted{color:var(--muted);font-size:13.5px}
.st{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}
.dot{width:12px;height:12px;border-radius:50%;background:var(--warn)}
.dot.in_progress{background:var(--blue)}.dot.resolved,.dot.closed{background:var(--ok)}
.msgs{display:flex;flex-direction:column;gap:8px;margin-top:12px}
.m{max-width:88%;padding:9px 12px;border-radius:14px;font-size:14px;white-space:pre-wrap;word-break:break-word}
.m.you{align-self:flex-end;background:var(--chip)}
.m.staff{align-self:flex-start;background:#E7F6EE;color:#0E3B24}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]) .m.staff{background:#163A28;color:#DDF5E6}}
:root[data-theme="dark"] .m.staff{background:#163A28;color:#DDF5E6}
.m small{display:block;font-size:11px;opacity:.7;margin-top:3px}
.foot{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:6px}
.foot .btn{margin-top:0;font-size:14px;min-height:46px}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.hidden{display:none}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <a class="brand" href="/"><img src="<?= $e(Brand::markUrl(34)) ?>" alt="" width="65" height="34"><span><?= $e($company) ?></span><?= Brand::flagPair(12) ?></a>
    <div class="langs">
      <?php foreach (['ne' => 'ने', 'hi' => 'हि', 'en' => 'EN'] as $l => $lab): ?>
        <a class="<?= $l === $lang ? 'on' : '' ?>" href="?lang=<?= $l ?><?= $ref !== '' ? '&amp;ref=' . $e(urlencode($ref)) : '' ?>"><?= $lab ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <h1>🛟 <?= $e($T['title']) ?></h1>
  <p class="sub"><?= $e($T['sub']) ?></p>

  <div class="tabs" role="tablist">
    <button type="button" role="tab" id="tNew" class="<?= $ref === '' ? 'on' : '' ?>" aria-selected="<?= $ref === '' ? 'true' : 'false' ?>"><?= $e($T['tabNew']) ?></button>
    <button type="button" role="tab" id="tCheck" class="<?= $ref !== '' ? 'on' : '' ?>" aria-selected="<?= $ref !== '' ? 'true' : 'false' ?>"><?= $e($T['tabCheck']) ?></button>
  </div>

  <!-- New ticket -->
  <section id="pNew" class="<?= $ref !== '' ? 'hidden' : '' ?>">
    <?php if (!$on): ?>
      <div class="card"><p><?= $e($T['urgent']) ?> <b><?= $e($phone) ?></b></p></div>
    <?php else: ?>
    <form class="card" id="fNew" novalidate>
      <span class="lbl"><?= $e($T['what']) ?></span>
      <div class="cats">
        <?php foreach ($cats as $k => $c): ?>
          <label class="<?= $k === 'safety' ? 'sos' : '' ?>"><input type="radio" name="category" value="<?= $k ?>" <?= $k === $cat0 ? 'checked' : '' ?>><span class="ic"><?= $icons[$k] ?? '💬' ?></span><span><?= $e($lang === 'en' ? $c[0] : $c[1]) ?></span></label>
        <?php endforeach; ?>
      </div>
      <label class="lbl" for="hMsg"><?= $e($T['msg']) ?></label>
      <textarea id="hMsg" name="message" maxlength="1500" placeholder="<?= $e($T['msgPh']) ?>"></textarea>
      <label class="lbl" for="hName"><?= $e($T['name']) ?></label>
      <input id="hName" name="name" maxlength="120" autocomplete="name">
      <label class="lbl" for="hPhone"><?= $e($T['phone']) ?></label>
      <div class="row">
        <select name="country" aria-label="Country"><option value="IN">🇮🇳 +91</option><option value="NP">🇳🇵 +977</option></select>
        <input id="hPhone" name="phone" inputmode="tel" autocomplete="tel" maxlength="15" placeholder="98XXXXXXXX" required>
      </div>
      <label class="lbl" for="hPnr"><?= $e($T['pnr']) ?></label>
      <input id="hPnr" name="pnr" maxlength="30" value="<?= $e($pnr) ?>" style="text-transform:uppercase">
      <div class="hp" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
      <div class="err" id="eNew" role="alert"></div>
      <button class="btn blue" type="submit" id="bNew">🎫 <?= $e($T['send']) ?></button>
    </form>
    <?php endif; ?>
    <div class="card done hidden" id="dNew" aria-live="polite">
      <div class="muted" id="dLbl"><?= $e($T['yourNo']) ?></div>
      <div class="big" id="dRef">HD-0000</div>
      <span class="tok" id="dTok"></span>
      <p class="muted" style="margin-top:12px"><?= $e($T['keep']) ?> 📸 <?= $e($T['save']) ?></p>
      <p class="muted hidden" id="dSent">✅ <?= $e($T['sent']) ?></p>
      <a class="btn wa" id="dWa" href="#" target="_blank" rel="noopener">💬 <?= $e($T['shareWa']) ?></a>
      <button class="btn" type="button" id="dCheck"><?= $e($T['check']) ?></button>
    </div>
  </section>

  <!-- Check a ticket -->
  <section id="pCheck" class="<?= $ref === '' ? 'hidden' : '' ?>">
    <form class="card" id="fCheck" novalidate>
      <label class="lbl" for="cRef"><?= $e($T['ref']) ?></label>
      <input id="cRef" name="ref" value="<?= $e($ref) ?>" placeholder="HD-0051" style="text-transform:uppercase" required>
      <label class="lbl" for="cPhone"><?= $e($T['phone']) ?></label>
      <input id="cPhone" name="phone" inputmode="tel" maxlength="15" placeholder="98XXXXXXXX" required>
      <div class="err" id="eCheck" role="alert"></div>
      <button class="btn blue" type="submit" id="bCheck">🔎 <?= $e($T['check']) ?></button>
    </form>
    <div class="card hidden" id="vCard" aria-live="polite">
      <div class="st"><span class="dot" id="vDot"></span><span id="vSt"></span></div>
      <p class="muted" id="vMeta"></p>
      <div class="lbl"><?= $e($T['replies']) ?></div>
      <div class="msgs" id="vMsgs"></div>
      <form id="fAdd" style="margin-top:12px">
        <label class="lbl" for="aMsg"><?= $e($T['add']) ?></label>
        <textarea id="aMsg" maxlength="1500" style="min-height:70px"></textarea>
        <div class="err" id="eAdd" role="alert"></div>
        <button class="btn" type="submit"><?= $e($T['addBtn']) ?></button>
      </form>
    </div>
  </section>

  <div class="card">
    <p class="muted"><?= $e($T['urgent']) ?></p>
    <div class="foot">
      <a class="btn" href="tel:<?= $e(preg_replace('/[^0-9+]/', '', $phone)) ?>">📞 <?= $e($T['call']) ?></a>
      <?php if ($waOff !== ''): ?><a class="btn wa" href="https://wa.me/<?= $e($waOff) ?>" target="_blank" rel="noopener">💬 <?= $e($T['wa']) ?></a><?php endif; ?>
    </div>
  </div>
</div>
<script>
(function () {
  'use strict';
  var T = <?= json_encode(['status' => $T['status'], 'token' => $T['token'], 'you' => $T['you'], 'staff' => $T['staff'], 'dup' => $T['dup'], 'none' => $T['none'], 'yourNo' => $T['yourNo']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var CSRF = document.querySelector('meta[name=csrf]').content;
  var LANG = <?= json_encode($lang) ?>;
  function $(id) { return document.getElementById(id); }
  function api(body) {
    return fetch('/api/helpdesk.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Network error' }; }); });
  }
  function errText(j) {
    if (j && j.fields) { for (var k in j.fields) { return j.fields[k]; } }
    return (j && (j.error || j.message)) || 'Error';
  }
  function tab(which) {
    var n = which === 'new';
    $('tNew').classList.toggle('on', n); $('tCheck').classList.toggle('on', !n);
    $('tNew').setAttribute('aria-selected', n); $('tCheck').setAttribute('aria-selected', !n);
    $('pNew').classList.toggle('hidden', !n); $('pCheck').classList.toggle('hidden', n);
  }
  $('tNew').onclick = function () { tab('new'); };
  $('tCheck').onclick = function () { tab('check'); };
  try { var saved = JSON.parse(localStorage.getItem('shg:help') || '{}'); if (saved.phone) { if ($('hPhone')) $('hPhone').value = saved.phone; $('cPhone').value = saved.phone; } if (saved.name && $('hName')) $('hName').value = saved.name; } catch (e) {}

  var fNew = $('fNew');
  if (fNew) fNew.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var fd = new FormData(fNew), body = { action: 'open', lang: LANG };
    fd.forEach(function (v, k) { body[k] = String(v).trim(); });
    $('eNew').textContent = '';
    if (!body.category && body.message.length < 3) { $('eNew').textContent = '⚠ ' + (LANG === 'en' ? 'Pick what happened or write a few words.' : 'के भयो छान्नुहोस् वा केही शब्द लेख्नुहोस्।'); return; }
    $('bNew').disabled = true;
    api(body).then(function (j) {
      $('bNew').disabled = false;
      if (!j || !j.ok) { $('eNew').textContent = '⚠ ' + errText(j); return; }
      var d = j.data || {};
      try { localStorage.setItem('shg:help', JSON.stringify({ phone: body.phone, name: body.name, last: d.ref })); } catch (e) {}
      fNew.classList.add('hidden');
      $('dNew').classList.remove('hidden');
      $('dLbl').textContent = d.duplicate ? T.dup : T.yourNo;
      $('dRef').textContent = d.ref;
      $('dTok').textContent = d.token ? T.token + ' #' + d.token : '';
      $('dTok').classList.toggle('hidden', !d.token);
      $('dSent').classList.toggle('hidden', !d.acked);
      $('dWa').href = 'https://wa.me/?text=' + encodeURIComponent('S Hari Global help desk: ' + d.ref + '\n' + d.url);
      $('cRef').value = d.ref; $('cPhone').value = body.phone;
      $('dNew').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });
  $('dCheck').onclick = function () { tab('check'); check(); };

  function fmt(s) { return String(s || '').replace('T', ' ').slice(0, 16); }
  function render(v) {
    $('vCard').classList.remove('hidden');
    $('vDot').className = 'dot ' + v.status;
    $('vSt').textContent = v.ref + ' · ' + (T.status[v.status] || v.status);
    $('vMeta').textContent = (LANG === 'en' ? v.labelEn : v.label) + (v.pnr ? ' · ' + v.pnr : '') + ' · ' + fmt(v.opened) + (v.token ? ' · #' + v.token : '');
    var box = $('vMsgs'); box.innerHTML = '';
    var staffSaid = false;
    (v.messages || []).forEach(function (m) {
      var el = document.createElement('div'); el.className = 'm ' + m.from;
      el.textContent = m.text;
      var sm = document.createElement('small'); sm.textContent = (m.from === 'staff' ? T.staff : T.you) + ' · ' + fmt(m.at);
      el.appendChild(sm); box.appendChild(el);
      if (m.from === 'staff') staffSaid = true;
    });
    if (!staffSaid) { var p = document.createElement('p'); p.className = 'muted'; p.textContent = T.none; box.appendChild(p); }
    $('fAdd').classList.toggle('hidden', v.status === 'closed');
  }
  function check() {
    var ref = $('cRef').value.trim(), ph = $('cPhone').value.trim();
    $('eCheck').textContent = '';
    if (!ref || ph.replace(/\D/g, '').length < 9) { $('eCheck').textContent = '⚠ ' + (LANG === 'en' ? 'Enter the number and your mobile.' : 'नम्बर र मोबाइल दुवै लेख्नुहोस्।'); return; }
    $('bCheck').disabled = true;
    api({ action: 'status', ref: ref, phone: ph }).then(function (j) {
      $('bCheck').disabled = false;
      if (!j || !j.ok) { $('eCheck').textContent = '⚠ ' + errText(j); $('vCard').classList.add('hidden'); return; }
      try { var s = JSON.parse(localStorage.getItem('shg:help') || '{}'); s.phone = ph; localStorage.setItem('shg:help', JSON.stringify(s)); } catch (e) {}
      render(j.data);
    });
  }
  $('fCheck').addEventListener('submit', function (ev) { ev.preventDefault(); check(); });
  $('fAdd').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var t = $('aMsg').value.trim(); if (t.length < 2) return;
    api({ action: 'reply', ref: $('cRef').value.trim(), phone: $('cPhone').value.trim(), message: t }).then(function (j) {
      if (!j || !j.ok) { $('eAdd').textContent = '⚠ ' + errText(j); return; }
      $('aMsg').value = ''; $('eAdd').textContent = ''; render(j.data);
    });
  });
  if ($('cRef').value && $('cPhone').value) check();
})();
</script>
</body>
</html>
