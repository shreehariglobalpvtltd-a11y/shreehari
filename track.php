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
             'live' => 'The bus is on the road', 'eta' => 'about %1$d min to %2$s', 'km' => '%s km away', 'passed' => 'The bus has passed your stop — call the office if you missed it.',
             'lastKnown' => 'Last seen near %2$s at %1$s (%3$d min ago) — not live', 'gone' => 'The bus has left. Its position is not being sent right now.', 'mapsLast' => 'Last known place in Google Maps',
             'arrived' => 'The bus has arrived', 'cancelled' => 'This trip is cancelled — the office will contact you.', 'delay' => 'Running %d min late',
             'share' => 'Share with family on WhatsApp', 'shareText' => 'Follow my bus live (S Hari Global):', 'maps' => 'Open in Google Maps', 'call' => 'Call the office',
             'updated' => 'Updated %s ago', 'refresh' => 'Refreshes by itself every 15 s', 'ticketOnly' => 'Ticket not confirmed — the bus cannot be followed yet.',
             'onRoad' => 'Time on the road', 'running' => 'On the road for %s', 'pickIn' => 'Your pickup in', 'pickAt' => 'Pickup: %1$s · %2$s', 'toStop' => 'Your stop', 'toDest' => 'To %s',
             'gps' => 'Live GPS', 'tt' => 'As per timetable', 'journey' => 'Full journey', 'places' => '%1$d stops · %2$d halts', 'you' => 'You board here', 'youOff' => 'You get off here',
             'near' => 'near %s', 'mins' => '%d min', 'hrs' => '%d h', 'hm' => '%1$d h %2$d min', 'dd' => '%d d', 'liveB' => 'LIVE', 'kmOf' => '%1$s of %2$s km', 'next' => 'Next', 'done' => 'Passed',
             'approxNote' => '≈ approximate: the crew picks the exact dhaba / hotel and traffic changes the times.', 'ttNote' => 'No live GPS right now — the times and the bar follow the timetable, not the bus’s real position.',
             'border' => 'India–Nepal border', 'across' => 'Nepalgunj is across the border, in Nepal', 'arrive' => 'Arrival time being confirmed',
             'leftSub' => 'The coach has started its run, but its live position is not being sent right now. The times below follow the timetable.',
             'ttTitle' => 'As per timetable the bus is on its way — not live on GPS right now',
             'hk' => ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner', 'meal' => 'Meal', 'tea' => 'Tea break', 'fuel' => 'Fuel', 'rest' => 'Rest', 'border' => 'Border']],
    'hi' => ['title' => 'मेरी बस कहाँ है?', 'sub' => 'आपके टिकट की लाइव स्थिति', 'notFound' => 'यह लिंक सही नहीं है। हमारे भेजे संदेश से पेज खोलें।',
             'stop' => 'आपका बोर्डिंग स्टॉप', 'date' => 'यात्रा की तारीख', 'bus' => 'बस', 'pnr' => 'टिकट', 'route' => 'रूट',
             'notLive' => 'बस अभी लाइव नहीं है', 'notLiveSub' => 'ड्राइवर का फ़ोन स्थिति भेजेगा तभी दिखेगी। रवानगी के समय के पास फिर देखें।',
             'live' => 'बस रास्ते में है', 'eta' => '%2$s तक लगभग %1$d मिनट', 'km' => '%s किमी दूर', 'passed' => 'बस आपके स्टॉप से निकल चुकी है — छूट गई हो तो ऑफ़िस को कॉल करें।',
             'lastKnown' => 'आखिरी बार %2$s के पास %1$s बजे दिखी (%3$d मिनट पहले) — लाइव नहीं', 'gone' => 'बस निकल चुकी है। अभी उसकी स्थिति नहीं आ रही।', 'mapsLast' => 'आखिरी जगह Google Maps में',
             'arrived' => 'बस पहुँच गई', 'cancelled' => 'यह यात्रा रद्द है — ऑफ़िस आपसे संपर्क करेगा।', 'delay' => '%d मिनट देरी से चल रही है',
             'share' => 'परिवार को WhatsApp पर भेजें', 'shareText' => 'मेरी बस को लाइव देखें (S Hari Global):', 'maps' => 'Google Maps में खोलें', 'call' => 'ऑफ़िस को कॉल करें',
             'updated' => '%s पहले अपडेट', 'refresh' => 'हर 15 सेकंड में खुद रीफ्रेश होता है', 'ticketOnly' => 'टिकट पक्का नहीं है — अभी बस नहीं देखी जा सकती।',
             'onRoad' => 'रास्ते में', 'running' => '%s से रास्ते में', 'pickIn' => 'आपके पिकअप में बाकी', 'pickAt' => 'पिकअप: %1$s · %2$s', 'toStop' => 'आपका स्टॉप', 'toDest' => '%s तक',
             'gps' => 'लाइव GPS', 'tt' => 'समय-सारणी के अनुसार', 'journey' => 'पूरी यात्रा', 'places' => '%1$d स्टॉप · %2$d पड़ाव', 'you' => 'आप यहाँ चढ़ेंगे', 'youOff' => 'आप यहाँ उतरेंगे',
             'near' => '%s के पास', 'mins' => '%d मिनट', 'hrs' => '%d घंटे', 'hm' => '%1$d घंटे %2$d मिनट', 'dd' => '%d दिन', 'liveB' => 'लाइव', 'kmOf' => '%2$s में से %1$s किमी', 'next' => 'अगला', 'done' => 'निकल चुकी',
             'approxNote' => '≈ अनुमानित: ढाबा / होटल चालक दल चुनता है, ट्रैफ़िक से समय बदल सकता है।', 'ttNote' => 'अभी लाइव GPS नहीं — समय और पट्टी समय-सारणी के अनुसार हैं, बस की असली जगह नहीं।',
             'border' => 'भारत–नेपाल सीमा', 'across' => 'सीमा पार Nepalgunj, नेपाल में', 'arrive' => 'पहुँचने का समय पक्का हो रहा है',
             'leftSub' => 'बस अपनी यात्रा पर निकल चुकी है, पर अभी उसकी लाइव जगह नहीं आ रही। नीचे के समय समय-सारणी के अनुसार हैं।',
             'ttTitle' => 'समय-सारणी के अनुसार बस रास्ते में है — अभी लाइव नहीं',
             'hk' => ['breakfast' => 'नाश्ता', 'lunch' => 'दोपहर का खाना', 'dinner' => 'रात का खाना', 'meal' => 'खाना', 'tea' => 'चाय ब्रेक', 'fuel' => 'ईंधन', 'rest' => 'आराम', 'border' => 'सीमा']],
    'ne' => ['title' => 'मेरो बस कहाँ छ?', 'sub' => 'तपाईंको टिकटको लाइभ स्थिति', 'notFound' => 'यो लिङ्क मिलेन। हामीले पठाएको सन्देशबाट पेज खोल्नुहोस्।',
             'stop' => 'तपाईं चढ्ने ठाउँ', 'date' => 'यात्राको मिति', 'bus' => 'बस', 'pnr' => 'टिकट', 'route' => 'रुट',
             'notLive' => 'बस अहिले लाइभ छैन', 'notLiveSub' => 'ड्राइभरको फोनले स्थिति पठाएपछि मात्र देखिन्छ। छुट्ने समय नजिक फेरि हेर्नुहोस्।',
             'live' => 'बस बाटोमा छ', 'eta' => '%2$s सम्म करिब %1$d मिनेट', 'km' => '%s किमी टाढा', 'passed' => 'बस तपाईंको स्टपबाट अगाडि गइसक्यो — छुटेको भए कार्यालयलाई फोन गर्नुहोस्।',
             'lastKnown' => 'अन्तिम पटक %2$s नजिक %1$s बजे देखियो (%3$d मिनेट अघि) — लाइभ होइन', 'gone' => 'बस छुटिसक्यो। अहिले यसको स्थिति आइरहेको छैन।', 'mapsLast' => 'अन्तिम ठाउँ Google Maps मा',
             'arrived' => 'बस पुग्यो', 'cancelled' => 'यो यात्रा रद्द भयो — कार्यालयले सम्पर्क गर्नेछ।', 'delay' => '%d मिनेट ढिलो चलिरहेको छ',
             'share' => 'परिवारलाई WhatsApp मा पठाउनुहोस्', 'shareText' => 'मेरो बस लाइभ हेर्नुहोस् (S Hari Global):', 'maps' => 'Google Maps मा खोल्नुहोस्', 'call' => 'कार्यालयलाई फोन',
             'updated' => '%s अघि अपडेट', 'refresh' => 'हरेक १५ सेकेन्डमा आफैँ रिफ्रेस हुन्छ', 'ticketOnly' => 'टिकट पक्का भएको छैन — अहिले बस हेर्न मिल्दैन।',
             'onRoad' => 'बाटोमा', 'running' => '%s देखि बाटोमा', 'pickIn' => 'तपाईंको पिकअपमा बाँकी', 'pickAt' => 'पिकअप: %1$s · %2$s', 'toStop' => 'तपाईंको स्टप', 'toDest' => '%s सम्म',
             'gps' => 'लाइभ GPS', 'tt' => 'समय तालिका अनुसार', 'journey' => 'पूरा यात्रा', 'places' => '%1$d स्टप · %2$d विश्राम', 'you' => 'तपाईं यहाँ चढ्नुहुन्छ', 'youOff' => 'तपाईं यहाँ ओर्लनुहुन्छ',
             'near' => '%s नजिक', 'mins' => '%d मिनेट', 'hrs' => '%d घण्टा', 'hm' => '%1$d घण्टा %2$d मिनेट', 'dd' => '%d दिन', 'liveB' => 'लाइभ', 'kmOf' => '%2$s मध्ये %1$s किमी', 'next' => 'अर्को', 'done' => 'पार भयो',
             'approxNote' => '≈ अनुमानित: ढाबा / होटल चालक दलले छान्छ, ट्राफिकले समय फरक पर्न सक्छ।', 'ttNote' => 'अहिले लाइभ GPS छैन — समय र पट्टी समय तालिका अनुसार हुन्, बसको वास्तविक स्थिति होइन।',
             'border' => 'भारत–नेपाल सीमा', 'across' => 'सीमापारि Nepalgunj, नेपालमा', 'arrive' => 'पुग्ने समय पक्का हुँदैछ',
             'leftSub' => 'बस यात्रामा निस्किसकेको छ, तर अहिले यसको लाइभ स्थिति आइरहेको छैन। तलका समय समय तालिका अनुसार हुन्।',
             'ttTitle' => 'समय तालिका अनुसार बस बाटोमा छ — अहिले लाइभ छैन',
             'hk' => ['breakfast' => 'नास्ता', 'lunch' => 'दिउँसोको खाना', 'dinner' => 'बेलुकाको खाना', 'meal' => 'खाना', 'tea' => 'चिया ब्रेक', 'fuel' => 'इन्धन', 'rest' => 'आराम', 'border' => 'सीमा']],
][$lang];

$e       = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$company = Settings::getString('company_name', APP_NAME);
$phone   = Settings::officePhone();
/* The tracker's OWN key (8 Oct 2026, D7-11): the family share link and the
   language links carry Ticket::trackToken, never the download key — even
   when this page was opened from an older link that carried it — so a
   forwarded link can watch the bus and cannot download the ticket. */
$tok     = $authed ? Ticket::trackToken($pnr) : $token;
$self    = appUrl('track.php?pnr=' . urlencode($pnr) . '&k=' . urlencode($tok));
$shareUrl = 'https://wa.me/?text=' . rawurlencode($T['shareText'] . ' ' . $self);

/* One headline per state, decided here so the page and its refresh agree.
   The ETA templates are positional (%1$d minutes, %2$s stop): the Hindi and
   Nepali sentences put the stop first and used to print "25 तक लगभग 0 मिनट". */
$head = ['icon' => '🚌', 'title' => $T['notLive'], 'sub' => $T['notLiveSub'], 'tone' => 'muted'];
$meta = $T['refresh'];
if ($authed && $status !== null) {
    $st   = (string) $status['state'];
    // "Left" only once THIS passenger's own pickup time has come (review, 8 Oct 2026).
    $gone = !empty($status['pickupPassed'])
        && (in_array($st, ['departed', 'on_route'], true) || (string) ($status['tripStatus'] ?? '') === 'departed');
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
                     'sub'  => $status['etaMin'] !== null ? sprintf($T['eta'], (int) $status['etaMin'], (string) $status['stop']) . ' · ' . sprintf($T['km'], (string) $status['km']) : '',
                     'tone' => 'ok'];
        }
        if (($status['fixAge'] ?? null) !== null) {
            $fa   = (int) $status['fixAge'];
            $meta = sprintf($T['updated'], $fa < 60 ? $fa . ' s' : (int) round($fa / 60) . ' min');
        }
    } elseif (!empty($status['lastKnown'])) {
        // D7-06: a GPS gap mid-journey — where it was last seen, never "not live yet".
        $head = ['icon' => '⏸', 'title' => sprintf($T['lastKnown'], (string) $status['lastAt'], (string) $status['lastStop'], (int) $status['lastAgeMin']),
                 'sub' => $gone ? $T['gone'] : '', 'tone' => 'muted'];
    } elseif ((int) ($status['delayMin'] ?? 0) > 0) {
        $head = ['icon' => '⏰', 'title' => sprintf($T['delay'], (int) $status['delayMin']), 'sub' => (string) ($status['delayNote'] ?? ''), 'tone' => 'warn'];
    } elseif ($gone) {
        $head = ['icon' => '🚌', 'title' => $T['gone'], 'sub' => '', 'tone' => 'muted'];
    } elseif (($status['runningSinceMin'] ?? null) !== null && $st !== 'cancelled') {
        // 9 Oct 2026: the run has started (passenger's own pickup still ahead) - not "check closer to departure".
        $head['title'] = $T['ttTitle'];
        $head['sub']   = $T['leftSub'];
    }
}

/* The first paint of the live screen (9 Oct 2026): the same answer
   api/whereis.php gives every 15 s, minus what the page never reads, and
   with empty / false / null fields dropped from each timeline row so the
   page stays light on a 2G phone. */
$slim = null;
if ($authed && $status !== null) {
    $slim = array_diff_key($status, array_flip(['reason', 'label', 'updatedAt', 'pnr', 'bus', 'kmh']));
    $slim['timeline'] = array_map(
        static fn(array $r): array => array_filter($r, static fn($v): bool => $v !== null && $v !== false && $v !== ''),
        (array) ($status['timeline'] ?? [])
    );
}
$stopCnt = 0; $haltCnt = 0;
foreach ((array) ($status['timeline'] ?? []) as $r) {
    if (($r['kind'] ?? '') === 'halt') { $haltCnt++; } else { $stopCnt++; }
}
$routeEnds = explode(' → ', (string) ($status['route'] ?? ''));

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
<script>/* The app's saved theme, before the first frame (8 Oct 2026): localStorage 'shg:theme' = light | dark | amoled, else the phone's setting. */
(function(){try{var d=document.documentElement,t=(localStorage.getItem('shg:theme')||'').replace(/"/g,'');if(t!=='light'&&t!=='dark'&&t!=='amoled')t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';d.setAttribute('data-theme',t==='light'?'light':'dark');if(t==='amoled')d.setAttribute('data-display','amoled');if(t!=='light'){var m=document.querySelector('meta[name="theme-color"]');if(m)m.setAttribute('content',t==='amoled'?'#000000':'#0E1626')}}catch(e){}})();</script>
<style>
/* 9 Oct 2026: the live screen - hero state, real progress, the whole run. Tokens follow the app (light / dark / AMOLED). */
:root{--pri:#1F4FA3;--orange:#F07C1F;--ink:#14213D;--muted:#56657F;--line:#E2E9F4;--bg:#F2F5FB;--card:#fff;--soft:#EAF0FB;--h1:#0F2550;--h2:#1F4FA3;--h3:#3D7BE0}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--pri:#8DB4FF;--ink:#EEF3FB;--muted:#A6B4CB;--line:#24324F;--bg:#0B1220;--card:#141E33;--soft:#1B2A47;--h1:#0B1A38;--h2:#173C82;--h3:#2559B8}}
:root[data-theme="dark"]{--pri:#8DB4FF;--ink:#EEF3FB;--muted:#A6B4CB;--line:#24324F;--bg:#0B1220;--card:#141E33;--soft:#1B2A47;--h1:#0B1A38;--h2:#173C82;--h3:#2559B8;color-scheme:dark}
:root[data-display="amoled"]{--bg:#000;--card:#0A0E15;--line:#1E2A3C;--soft:#121B2B;--h1:#000;--h2:#06122A;--h3:#0E2A5C}
body{margin:0;background:var(--bg);color:var(--ink);font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Devanagari',sans-serif}
.pulse{display:inline-block;width:10px;height:10px;border-radius:50%;background:#4ADE80;box-shadow:0 0 0 0 rgba(74,222,128,.6);margin-right:8px;vertical-align:middle}
@media (prefers-reduced-motion:no-preference){.pulse{animation:p 1.6s infinite}.r.nx i::after{animation:p2 1.8s infinite}.tick.go circle{animation:tk 15s linear forwards}.bar>i,.bar>b{transition:all .8s}}
@keyframes p{70%{box-shadow:0 0 0 12px rgba(74,222,128,0)}100%{box-shadow:0 0 0 0 rgba(74,222,128,0)}}
@keyframes p2{0%{box-shadow:0 0 0 0 rgba(240,124,31,.55)}70%{box-shadow:0 0 0 9px rgba(240,124,31,0)}100%{box-shadow:0 0 0 0 rgba(240,124,31,0)}}
@keyframes tk{to{stroke-dashoffset:0}}
</style>
<link rel="stylesheet" href="/assets/css/track-live.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/track-live.css') ?>">
</head>
<body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><symbol id="fIN" viewBox="0 0 30 20"><rect width="30" height="20" fill="#fff"/><rect width="30" height="6.7" fill="#FF9933"/><rect y="13.3" width="30" height="6.7" fill="#138808"/><circle cx="15" cy="10" r="2.8" fill="none" stroke="#000080" stroke-width=".9"/><circle cx="15" cy="10" r=".7" fill="#000080"/></symbol><symbol id="fNP" viewBox="-1.2 -1.2 22.4 29.4"><path d="M0 0L20 12.5H6L20 27H0Z" fill="#DC143C" stroke="#003893" stroke-width="1.8" stroke-linejoin="round"/><path d="M2.3 7.2a3.7 3.7 0 0 0 7.4 0a3.7 2.6 0 0 1-7.4 0z" fill="#fff"/><circle cx="6" cy="8.7" r="1.3" fill="#fff"/><circle cx="6" cy="20" r="3" fill="#fff"/></symbol></svg>
<div class="wrap">
  <div class="top">
    <span class="brand"><picture><source srcset="/assets/img/logo-mark-96h.webp" type="image/webp"><img src="/assets/img/logo-mark-96h.png" width="65" height="34" alt=""></picture><span><?= $e($company) ?></span></span>
    <span aria-hidden="true"><svg class="fl"><use href="#fIN"/></svg><svg class="fl np"><use href="#fNP"/></svg></span>
    <div class="langs">
      <?php foreach (['ne' => 'ने', 'hi' => 'हि', 'en' => 'EN'] as $l => $lab): ?>
        <a class="<?= $l === $lang ? 'on' : '' ?>" href="?pnr=<?= $e(urlencode($pnr)) ?>&amp;k=<?= $e(urlencode($tok)) ?>&amp;lang=<?= $l ?>"><?= $lab ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <h1><?= $e($T['title']) ?></h1>
  <p class="sub"><?= $e($T['sub']) ?></p>

<?php if (!$authed): ?>
  <div class="card hero tone-bad"><div class="hrow"><div class="ic">🔒</div><div><h2><?= $e($T['notFound']) ?></h2></div></div></div>
  <a class="btn" href="tel:<?= $e(preg_replace('/[^0-9+]/', '', $phone)) ?>">📞 <?= $e($T['call']) ?> · <?= $e($phone) ?></a>
<?php else: ?>
  <section class="card hero tone-<?= $e($head['tone']) ?>" id="headCard" data-lang="<?= $e($lang) ?>" aria-live="polite">
    <span class="clk" id="clk"></span>
    <div class="hrow"><div class="ic" id="hIcon"><?= $head['icon'] ?></div>
      <div><h2 id="hTitle"><?= !empty($status['live']) ? '<i class="pulse"></i>' : '' ?><?= $e($head['title']) ?></h2><p id="hSub"><?= $e($head['sub']) ?></p></div></div>
    <div class="chips" id="chips"></div>
    <div class="big hidden" id="big"><small id="bigL"></small><b id="bigV"></b><span id="bigS"></span></div>
  </section>

  <section class="card">
    <div class="pl"><span id="pKm"></span><span id="pTag"></span></div>
    <div class="bar" id="bar" aria-hidden="true"><i id="fill" style="width:0"></i><b id="busDot" class="hidden">🚌</b></div>
    <div class="ends"><span><?= $e($routeEnds[0] ?? '') ?></span><span><?= $e($routeEnds[1] ?? '') ?></span></div>
    <div class="tiles"><div><small><?= $e($T['toStop']) ?></small><b id="tS">—</b><em id="tSe"></em></div><div><small id="tDl"><?= $e(sprintf($T['toDest'], (string) ($routeEnds[1] ?? ''))) ?></small><b id="tD">—</b><em id="tDe"></em></div></div>
    <p class="note hidden" id="ttNote"><?= $e($T['ttNote']) ?></p>
  </section>

  <section class="card">
    <div class="grid">
      <div><small><?= $e($T['route']) ?></small><b><?= $e((string) ($status['route'] ?? '')) ?></b></div>
      <div><small><?= $e($T['date']) ?></small><b><?= $e(formatDate((string) ($status['date'] ?? ''))) ?></b></div>
      <div><small><?= $e($T['stop']) ?></small><b><?= $e((string) ($status['stop'] ?? '')) ?><?= ($status['stopTime'] ?? '') !== '' ? ' · ' . $e(substr((string) $status['stopTime'], 0, 5)) : '' ?></b></div>
      <div><small><?= $e($T['pnr']) ?></small><b><?= $e($pnr) ?><?= ($status['bus'] ?? '') !== '' ? ' · ' . $e((string) $status['bus']) : '' ?></b></div>
    </div>
  </section>

  <section class="card<?= $stopCnt + $haltCnt === 0 ? ' hidden' : '' ?>" id="jr">
    <h3><?= $e($T['journey']) ?> <span id="jrCnt"><?= $e(sprintf($T['places'], $stopCnt, $haltCnt)) ?><?= !empty($status['totalKm']) ? ' · ' . number_format((int) $status['totalKm']) . ' km' : '' ?></span></h3>
    <ol class="rail" id="rail"></ol>
    <?php if ($haltCnt > 0): ?><p class="note"><?= $e($T['approxNote']) ?></p><?php endif; ?>
    <p class="note"><svg class="fl"><use href="#fIN"/></svg> Rupaidiha — <?= $e($T['border']) ?> · <svg class="fl np"><use href="#fNP"/></svg> <?= $e($T['across']) ?></p>
  </section>

  <div class="btns">
    <a class="btn wa" href="<?= $e($shareUrl) ?>" target="_blank" rel="noopener">💬 <?= $e($T['share']) ?></a>
    <?php $lkMap = empty($status['live']) && !empty($status['lastKnown']); ?>
    <a class="btn blue <?= empty($status['live']) && !$lkMap ? 'hidden' : '' ?>" id="mapsBtn" href="<?= !empty($status['live']) ? 'https://maps.google.com/?q=' . $e((string) $status['lat']) . ',' . $e((string) $status['lng']) : ($lkMap ? 'https://maps.google.com/?q=' . $e((string) $status['lastLat']) . ',' . $e((string) $status['lastLng']) : '#') ?>" target="_blank" rel="noopener">🗺️ <span id="mapsTxt"><?= $e($lkMap ? $T['mapsLast'] : $T['maps']) ?></span></a>
    <a class="btn" href="tel:<?= $e(preg_replace('/[^0-9+]/', '', $phone)) ?>">📞 <?= $e($T['call']) ?> · <?= $e($phone) ?></a>
  </div>
  <p class="meta"><svg class="tick" id="tk" viewBox="0 0 14 14" aria-hidden="true"><circle cx="7" cy="7" r="6"/></svg><span id="meta"><?= $e($meta) ?></span></p>

<script>
(function(){
  var T = <?= json_encode(array_diff_key($T, array_flip(['title', 'sub', 'notFound', 'stop', 'date', 'bus', 'pnr', 'route', 'share', 'shareText', 'call', 'toStop', 'journey', 'approxNote', 'ttNote', 'across'])), JSON_UNESCAPED_UNICODE) ?>;
  var url = '/api/whereis.php?pnr=<?= $e(rawurlencode($pnr)) ?>&k=<?= $e(rawurlencode($tok)) ?>';
  var $ = function(id){ return document.getElementById(id); };
  /* Positional tokens, like the server's sprintf: %1$d / %2$s / %3$d (and a bare %s / %d). */
  function fill(s, a){ return String(s).replace(/%(\d)\$[ds]/g, function(m, n){ var v = a[+n - 1]; return v === undefined || v === null ? '' : String(v); }).replace(/%[ds]/, function(){ var v = a[0]; return v == null ? '' : String(v); }); }
  /* The rest of the live screen (chips, bar, tiles, rail, clock) is assets/js/track-live.js. */
  var S = window.SHG_TRACK = {T: T, fill: fill, D: <?= json_encode($slim, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>};
  function paint(d){
    S.D = d;
    var card = $('headCard'), icon = '🚌', title = T.notLive, sub = T.notLiveSub, tone = 'muted', live = !!d.live;
    var gone = !!d.pickupPassed && (d.state === 'departed' || d.state === 'on_route' || d.tripStatus === 'departed');
    if (d.bookingStatus !== 'confirmed') { icon = '⏳'; title = T.ticketOnly; sub = ''; }
    else if (d.state === 'cancelled') { icon = '❌'; title = T.cancelled; sub = ''; tone = 'bad'; }
    else if (d.state === 'arrived' || d.state === 'completed') { icon = '🏁'; title = T.arrived; sub = ''; tone = 'ok'; }
    else if (live && d.passed) { icon = '⚠️'; title = T.passed; sub = ''; tone = 'warn'; }
    else if (live) { icon = '📍'; title = T.live; tone = 'ok';
      sub = d.etaMin !== null ? (fill(T.eta, [d.etaMin, d.stop]) + ' · ' + T.km.replace('%s', d.km)) : ''; }
    else if (d.lastKnown) { icon = '⏸'; title = fill(T.lastKnown, [d.lastAt, d.lastStop, d.lastAgeMin]); sub = gone ? T.gone : ''; }
    else if (d.delayMin > 0) { icon = '⏰'; title = T.delay.replace('%d', d.delayMin); sub = d.delayNote || ''; tone = 'warn'; }
    else if (gone) { title = T.gone; sub = ''; }
    else if (d.runningSinceMin != null && d.state !== 'cancelled') { title = T.ttTitle; sub = T.leftSub; }
    card.className = 'card hero tone-' + tone;
    $('hIcon').textContent = icon;
    $('hTitle').innerHTML = (live ? '<i class="pulse"></i>' : '') + title.replace(/[<>&]/g, function(c){ return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]; });
    $('hSub').textContent = sub;
    var maps = $('mapsBtn');
    if (live && d.lat) { maps.classList.remove('hidden'); maps.href = 'https://maps.google.com/?q=' + d.lat + ',' + d.lng; $('mapsTxt').textContent = T.maps; }
    else if (!live && d.lastKnown && d.lastLat) { maps.classList.remove('hidden'); maps.href = 'https://maps.google.com/?q=' + d.lastLat + ',' + d.lastLng; $('mapsTxt').textContent = T.mapsLast; }
    else { maps.classList.add('hidden'); }
    $('meta').textContent = live && d.fixAge !== null ? T.updated.replace('%s', d.fixAge < 60 ? d.fixAge + ' s' : Math.round(d.fixAge / 60) + ' min') : T.refresh;
    if (S.extra) S.extra(d);
  }
  /* the small ring beside "refreshes every 15 s" fills up to the next refresh */
  function spin(){ var k = $('tk'); k.classList.remove('go'); void k.getBoundingClientRect(); k.classList.add('go'); }
  function tick(){
    if (document.hidden) return;
    fetch(url, {cache: 'no-store'}).then(function(r){ return r.ok ? r.json() : null; })
      .then(function(j){ if (j && j.ok && j.data) paint(j.data); spin(); }).catch(function(){});
  }
  spin();
  setInterval(tick, 15000);
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) tick(); });
})();
</script>
<script defer src="/assets/js/track-live.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/track-live.js') ?>"></script>
<?php endif; ?>
</div>
</body>
</html>
