<?php
/**
 * =====================================================================
 *  track.php?pnr=…&k=…  — "Where is my bus?" — मेरो बस कहाँ छ?
 *
 *  A public, keyed page for ONE booking: the trip's state, the coach's
 *  live position, minutes to the passenger's own boarding stop, and a
 *  button to share the same page with the family at home. Stand-alone
 *  (no app bundle, no map tiles, ~9 KB), so it opens in a second on any
 *  phone from the WhatsApp ticket link.
 *
 *  Honest by construction: a stale, inaccurate or off-route position is
 *  shown as "not live" with the reason — never a guessed dot. The same
 *  engine feeds the WhatsApp "bus is near" alert (includes/whereis.php).
 * ===================================================================== */

declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/whereis.php';

Security::requireRateLimit('track_page', Security::clientIp(), 60, 60);

$pnr   = strtoupper(Security::clean((string) ($_GET['pnr'] ?? ''), 40));
$token = Security::clean((string) ($_GET['k'] ?? ''), 64);
$lang  = in_array($_GET['lang'] ?? '', ['en', 'hi', 'ne'], true) ? (string) $_GET['lang'] : '';

$booking = $pnr !== '' ? BookingService::findByPnr($pnr) : null;
$authed  = $booking !== null && WhereIs::authorised($booking, $token);
$status  = $authed ? WhereIs::status($booking) : null;

if ($lang === '') {
    // A +977 number reads Nepali first; everyone else Hindi first. English is one tap away.
    $lang = str_starts_with(normalisePhone((string) ($booking['contact_phone'] ?? '')), '977') ? 'ne' : 'hi';
}

$T = [
    'en' => ['title' => 'Where is my bus?', 'sub' => 'Live position for your ticket', 'notFound' => 'This link is not valid. Open the page from the message we sent you.',
             'stop' => 'Your boarding stop', 'date' => 'Travel date', 'bus' => 'Bus', 'pnr' => 'Ticket', 'route' => 'Route',
             'notLive' => 'The bus is not live yet', 'notLiveSub' => 'We show the position only when the driver\'s phone is sending it. Check again closer to departure.',
             'live' => 'The bus is on the road', 'eta' => 'about %d min to %s', 'km' => '%s km away', 'passed' => 'The bus has passed your stop — call the office if you missed it.',
             'arrived' => 'The bus has arrived', 'cancelled' => 'This trip is cancelled — the office will contact you.', 'delay' => 'Running %d min late',
             'share' => 'Share with family on WhatsApp', 'shareText' => 'Follow my bus live (S Hari Global):', 'maps' => 'Open in Google Maps', 'call' => 'Call the office',
             'updated' => 'Updated %s ago', 'refresh' => 'Refreshes by itself every 15 s', 'ticketOnly' => 'Ticket not confirmed — the bus cannot be followed yet.'],
    'hi' => ['title' => 'मेरी बस कहाँ है?', 'sub' => 'आपके टिकट की लाइव स्थिति', 'notFound' => 'यह लिंक सही नहीं है। हमारे भेजे संदेश से पेज खोलें।',
             'stop' => 'आपका बोर्डिंग स्टॉप', 'date' => 'यात्रा की तारीख', 'bus' => 'बस', 'pnr' => 'टिकट', 'route' => 'रूट',
             'notLive' => 'बस अभी लाइव नहीं है', 'notLiveSub' => 'ड्राइवर का फ़ोन स्थिति भेजेगा तभी दिखेगी। रवानगी के समय के पास फिर देखें।',
             'live' => 'बस रास्ते में है', 'eta' => '%s तक लगभग %d मिनट', 'km' => '%s किमी दूर', 'passed' => 'बस आपके स्टॉप से निकल चुकी है — छूट गई हो तो ऑफ़िस को कॉल करें।',
             'arrived' => 'बस पहुँच गई', 'cancelled' => 'यह यात्रा रद्द है — ऑफ़िस आपसे संपर्क करेगा।', 'delay' => '%d मिनट देरी से चल रही है',
             'share' => 'परिवार को WhatsApp पर भेजें', 'shareText' => 'मेरी बस को लाइव देखें (S Hari Global):', 'maps' => 'Google Maps में खोलें', 'call' => 'ऑफ़िस को कॉल करें',
             'updated' => '%s पहले अपडेट', 'refresh' => 'हर 15 सेकंड में खुद रीफ्रेश होता है', 'ticketOnly' => 'टिकट पक्का नहीं है — अभी बस नहीं देखी जा सकती।'],
    'ne' => ['title' => 'मेरो बस कहाँ छ?', 'sub' => 'तपाईंको टिकटको लाइभ स्थिति', 'notFound' => 'यो लिङ्क मिलेन। हामीले पठाएको सन्देशबाट पेज खोल्नुहोस्।',
             'stop' => 'तपाईं चढ्ने ठाउँ', 'date' => 'यात्राको मिति', 'bus' => 'बस', 'pnr' => 'टिकट', 'route' => 'रुट',
             'notLive' => 'बस अहिले लाइभ छैन', 'notLiveSub' => 'ड्राइभरको फोनले स्थिति पठाएपछि मात्र देखिन्छ। छुट्ने समय नजिक फेरि हेर्नुहोस्।',
             'live' => 'बस बाटोमा छ', 'eta' => '%s सम्म करिब %d मिनेट', 'km' => '%s किमी टाढा', 'passed' => 'बस तपाईंको स्टपबाट अगाडि गइसक्यो — छुटेको भए कार्यालयलाई फोन गर्नुहोस्।',
             'arrived' => 'बस पुग्यो', 'cancelled' => 'यो यात्रा रद्द भयो — कार्यालयले सम्पर्क गर्नेछ।', 'delay' => '%d मिनेट ढिलो चलिरहेको छ',
             'share' => 'परिवारलाई WhatsApp मा पठाउनुहोस्', 'shareText' => 'मेरो बस लाइभ हेर्नुहोस् (S Hari Global):', 'maps' => 'Google Maps मा खोल्नुहोस्', 'call' => 'कार्यालयलाई फोन',
             'updated' => '%s अघि अपडेट', 'refresh' => 'हरेक १५ सेकेन्डमा आफैँ रिफ्रेस हुन्छ', 'ticketOnly' => 'टिकट पक्का भएको छैन — अहिले बस हेर्न मिल्दैन।'],
][$lang];

$e       = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$company = Settings::getString('company_name', APP_NAME);
$phone   = Settings::officePhone();
$self    = appUrl('track.php?pnr=' . urlencode($pnr) . '&k=' . urlencode($token));
$shareUrl = 'https://wa.me/?text=' . rawurlencode($T['shareText'] . ' ' . $self);

/* One headline per state, decided here so the page and its refresh agree. */
$head = ['icon' => '🚌', 'title' => $T['notLive'], 'sub' => $T['notLiveSub'], 'tone' => 'muted'];
if ($authed && $status !== null) {
    $st = (string) $status['state'];
    if (($booking['status'] ?? '') !== 'confirmed') {
        $head = ['icon' => '⏳', 'title' => $T['ticketOnly'], 'sub' => '', 'tone' => 'muted'];
    } elseif ($st === 'cancelled') {
        $head = ['icon' => '❌', 'title' => $T['cancelled'], 'sub' => '', 'tone' => 'bad'];
    } elseif (in_array($st, ['arrived', 'completed'], true)) {
        $head = ['icon' => '🏁', 'title' => $T['arrived'], 'sub' => '', 'tone' => 'ok'];
    } elseif (!empty($status['live'])) {
        if (!empty($status['passed'])) {
            $head = ['icon' => '⚠️', 'title' => $T['passed'], 'sub' => '', 'tone' => 'warn'];
        } else {
            $head = ['icon' => '📍', 'title' => $T['live'],
                     'sub'  => $status['etaMin'] !== null ? sprintf($T['eta'], $status['etaMin'], $status['stop']) . ' · ' . sprintf($T['km'], (string) $status['km']) : '',
                     'tone' => 'ok'];
        }
    } elseif ((int) ($status['delayMin'] ?? 0) > 0) {
        $head = ['icon' => '⏰', 'title' => sprintf($T['delay'], (int) $status['delayMin']), 'sub' => (string) ($status['delayNote'] ?? ''), 'tone' => 'warn'];
    }
}

http_response_code($authed ? 200 : 404);
header('Content-Type: text/html; charset=UTF-8');
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
<title><?= $e($T['title']) ?> · <?= $e($company) ?></title>
<link rel="icon" href="/assets/img/favicon-32.png">
<style>
:root{--blue:#2E5FA8;--navy:#12264E;--orange:#F07C1F;--ok:#178A50;--warn:#B7791F;--bad:#C53030;--ink:#16233C;--muted:#5C6B85;--line:#E2E9F4;--bg:#F6F8FC;--card:#fff}
@media (prefers-color-scheme:dark){:root{--ink:#F1F5FB;--muted:#A9B6CC;--line:#243352;--bg:#0E1626;--card:#16213A}}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Noto Sans Devanagari','Noto Sans',sans-serif;background:var(--bg);color:var(--ink);line-height:1.5;-webkit-font-smoothing:antialiased}
.wrap{max-width:520px;margin:0 auto;padding:16px 16px calc(24px + env(safe-area-inset-bottom))}
.top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;color:var(--navy)}
.brand img{width:36px;height:36px;border-radius:9px}
@media (prefers-color-scheme:dark){.brand{color:#fff}}
.langs a{font-size:12px;font-weight:700;color:var(--muted);text-decoration:none;padding:4px 7px;border-radius:999px;border:1px solid var(--line);margin-left:4px}
.langs a.on{background:var(--blue);color:#fff;border-color:var(--blue)}
h1{font-size:22px;margin:4px 0 2px}
.sub{color:var(--muted);font-size:13.5px;margin-bottom:14px}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px;box-shadow:0 6px 24px rgba(18,38,78,.08);margin-bottom:12px}
.head{display:flex;gap:14px;align-items:flex-start}
.head .ic{font-size:34px;line-height:1}
.head h2{font-size:19px;line-height:1.25}
.head p{color:var(--muted);font-size:14px;margin-top:4px}
.tone-ok h2{color:var(--ok)}.tone-warn h2{color:var(--warn)}.tone-bad h2{color:var(--bad)}.tone-muted h2{color:var(--ink)}
.pulse{display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--ok);box-shadow:0 0 0 0 rgba(23,138,80,.5);animation:p 1.6s infinite;margin-right:6px;vertical-align:middle}
@keyframes p{70%{box-shadow:0 0 0 12px rgba(23,138,80,0)}100%{box-shadow:0 0 0 0 rgba(23,138,80,0)}}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;font-size:14px}
.grid small{display:block;color:var(--muted);font-size:11.5px;text-transform:uppercase;letter-spacing:.04em}
.grid b{font-size:15px}
.btns{display:grid;gap:10px;margin-top:6px}
.btn{display:flex;align-items:center;justify-content:center;gap:8px;min-height:48px;border-radius:14px;font-weight:800;font-size:15px;text-decoration:none;border:1px solid var(--line);color:var(--ink);background:var(--card)}
.btn.wa{background:#25D366;border-color:#25D366;color:#fff}
.btn.blue{background:var(--blue);border-color:var(--blue);color:#fff}
.meta{text-align:center;color:var(--muted);font-size:12px;margin-top:10px}
.strip{position:relative;height:8px;border-radius:999px;background:var(--line);margin:14px 0 6px;overflow:visible}
.strip i{position:absolute;top:-6px;width:20px;height:20px;border-radius:50%;background:var(--orange);border:3px solid var(--card);box-shadow:0 2px 8px rgba(0,0,0,.25);transform:translateX(-50%);transition:left .6s ease}
.strip span{position:absolute;top:-2px;width:12px;height:12px;border-radius:50%;background:var(--muted);transform:translateX(-50%)}
.stops{display:flex;justify-content:space-between;font-size:11px;color:var(--muted)}
.hidden{display:none}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="brand"><img src="/assets/img/icon-192.png" alt=""><span><?= $e($company) ?></span></div>
    <div class="langs">
      <?php foreach (['ne' => 'ने', 'hi' => 'हि', 'en' => 'EN'] as $l => $lab): ?>
        <a class="<?= $l === $lang ? 'on' : '' ?>" href="?pnr=<?= $e(urlencode($pnr)) ?>&amp;k=<?= $e(urlencode($token)) ?>&amp;lang=<?= $l ?>"><?= $lab ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <h1><?= $e($T['title']) ?></h1>
  <p class="sub"><?= $e($T['sub']) ?></p>

<?php if (!$authed): ?>
  <div class="card head tone-bad"><div class="ic">🔒</div><div><h2><?= $e($T['notFound']) ?></h2></div></div>
  <a class="btn" href="tel:<?= $e(preg_replace('/[^0-9+]/', '', $phone)) ?>">📞 <?= $e($T['call']) ?> · <?= $e($phone) ?></a>
<?php else: ?>
  <div class="card head tone-<?= $e($head['tone']) ?>" id="headCard" data-lang="<?= $e($lang) ?>">
    <div class="ic" id="hIcon"><?= $head['icon'] ?></div>
    <div><h2 id="hTitle"><?= !empty($status['live']) ? '<i class="pulse"></i>' : '' ?><?= $e($head['title']) ?></h2><p id="hSub"><?= $e($head['sub']) ?></p></div>
  </div>

  <div class="card">
    <div class="grid">
      <div><small><?= $e($T['route']) ?></small><b><?= $e((string) ($status['route'] ?? '')) ?></b></div>
      <div><small><?= $e($T['date']) ?></small><b><?= $e(formatDate((string) ($status['date'] ?? ''))) ?></b></div>
      <div><small><?= $e($T['stop']) ?></small><b><?= $e((string) ($status['stop'] ?? '')) ?><?= ($status['stopTime'] ?? '') !== '' ? ' · ' . $e(substr((string) $status['stopTime'], 0, 5)) : '' ?></b></div>
      <div><small><?= $e($T['pnr']) ?></small><b><?= $e($pnr) ?><?= ($status['bus'] ?? '') !== '' ? ' · ' . $e((string) $status['bus']) : '' ?></b></div>
    </div>
    <div class="strip" id="strip" aria-hidden="true"><span style="left:0"></span><span style="left:100%"></span><i id="busDot" class="<?= empty($status['live']) ? 'hidden' : '' ?>" style="left:<?= !empty($status['live']) && isset($status['km']) ? max(4, min(96, 100 - min(100, (float) $status['km'] / 4))) : 50 ?>%"></i></div>
    <div class="stops"><span><?= $e(explode(' → ', (string) ($status['route'] ?? ''))[0] ?? '') ?></span><span><?= $e((string) ($status['stop'] ?? '')) ?></span></div>
  </div>

  <div class="btns">
    <a class="btn wa" href="<?= $e($shareUrl) ?>" target="_blank" rel="noopener">💬 <?= $e($T['share']) ?></a>
    <a class="btn blue <?= empty($status['live']) ? 'hidden' : '' ?>" id="mapsBtn" href="<?= !empty($status['live']) ? 'https://maps.google.com/?q=' . $e((string) $status['lat']) . ',' . $e((string) $status['lng']) : '#' ?>" target="_blank" rel="noopener">🗺️ <?= $e($T['maps']) ?></a>
    <a class="btn" href="tel:<?= $e(preg_replace('/[^0-9+]/', '', $phone)) ?>">📞 <?= $e($T['call']) ?> · <?= $e($phone) ?></a>
  </div>
  <p class="meta" id="meta"><?= $e($T['refresh']) ?></p>

<script>
(function(){
  var T = <?= json_encode($T, JSON_UNESCAPED_UNICODE) ?>;
  var url = '/api/whereis.php?pnr=<?= $e(rawurlencode($pnr)) ?>&k=<?= $e(rawurlencode($token)) ?>';
  var $ = function(id){ return document.getElementById(id); };
  function fmt(s, a, b){ return s.replace('%d', a).replace('%s', b); }
  function paint(d){
    var card = $('headCard'), icon = '🚌', title = T.notLive, sub = T.notLiveSub, tone = 'muted', live = !!d.live;
    if (d.bookingStatus !== 'confirmed') { icon = '⏳'; title = T.ticketOnly; sub = ''; }
    else if (d.state === 'cancelled') { icon = '❌'; title = T.cancelled; sub = ''; tone = 'bad'; }
    else if (d.state === 'arrived' || d.state === 'completed') { icon = '🏁'; title = T.arrived; sub = ''; tone = 'ok'; }
    else if (live && d.passed) { icon = '⚠️'; title = T.passed; sub = ''; tone = 'warn'; }
    else if (live) { icon = '📍'; title = T.live; tone = 'ok';
      sub = d.etaMin !== null ? (T.eta.replace('%d', d.etaMin).replace('%s', d.stop) + ' · ' + T.km.replace('%s', d.km)) : ''; }
    else if (d.delayMin > 0) { icon = '⏰'; title = T.delay.replace('%d', d.delayMin); sub = d.delayNote || ''; tone = 'warn'; }
    card.className = 'card head tone-' + tone;
    $('hIcon').textContent = icon;
    $('hTitle').innerHTML = (live ? '<i class="pulse"></i>' : '') + title.replace(/[<>&]/g, function(c){ return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]; });
    $('hSub').textContent = sub;
    var dot = $('busDot'), maps = $('mapsBtn');
    if (live && d.km !== null) { dot.classList.remove('hidden'); dot.style.left = Math.max(4, Math.min(96, 100 - Math.min(100, d.km / 4))) + '%'; }
    else { dot.classList.add('hidden'); }
    if (live && d.lat) { maps.classList.remove('hidden'); maps.href = 'https://maps.google.com/?q=' + d.lat + ',' + d.lng; } else { maps.classList.add('hidden'); }
    $('meta').textContent = live && d.fixAge !== null ? fmt(T.updated, '', d.fixAge < 60 ? d.fixAge + ' s' : Math.round(d.fixAge / 60) + ' min') : T.refresh;
  }
  function tick(){
    if (document.hidden) return;
    fetch(url, {cache: 'no-store'}).then(function(r){ return r.ok ? r.json() : null; })
      .then(function(j){ if (j && j.ok && j.data) paint(j.data); }).catch(function(){});
  }
  setInterval(tick, 15000);
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) tick(); });
})();
</script>
<?php endif; ?>
</div>
</body>
</html>
