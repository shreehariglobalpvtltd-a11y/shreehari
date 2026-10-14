<?php
/**
 * =====================================================================
 *  nav-pro-test.php — S Hari Nav pro (10 Oct 2026): cockpit view, flight
 *  mode, device-tiered map engine, low-latency GPS, TV + keyboard, honest
 *  live-bus / CCTV status.
 *
 *  Static checks on the two navigator bundles, the loader, the service
 *  worker, the template, the stylesheet and the language table (no
 *  browser, no database):
 *
 *    • snNavLoad() loads 15-nav.js THEN 26-navpro.js with the same ?v=,
 *      a failed pro load is not fatal; sw.js precaches the pro bundle
 *    • every data-i18n key the nav markup uses, and every snT() key the
 *      pro bundle uses, exists in en, hi AND ne
 *    • no new phone / price literal in the pro bundle (the site-facts
 *      ratchet has it at 0)
 *    • node --check passes on both bundles; the pro bundle stays under
 *      ~900 lines; 15-nav.js keeps its CRLF endings
 *    • cleanupNav() removes the keyboard / visibility / resize listeners,
 *      the GPS-age timer and the compass sensor listeners (regex pins)
 *    • flight mode never calls the routing engine: the guard sits before
 *      the fetch in snRoute(), and 26-navpro.js has no fetch / route URL
 *    • every pro hook inside 15-nav.js is typeof-guarded
 *    • the device profile reads cores / memory / network / WebGL renderer /
 *      window.SHG_TIER / storage.estimate, the map is built from
 *      snProMapOpts(), the offline save is capped at 25 % of the free quota
 *    • watchPosition asks for high accuracy with no cached fix
 *    • window.SHG_NAV.onBusFix feeds the SAME path as the driver feed
 *    • cockpit overlay: aria-hidden, pointer-events none, reduced-motion
 *      rules, TV tier rules, "Coming soon" badge on the fleet / CCTV row
 *
 *    php -c .claude/php-dev.ini tests/nav-pro-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function fnBody(string $src, string $name): string {
    $p = strpos($src, 'function ' . $name . '(');
    if ($p === false) { return ''; }
    $b = strpos($src, '{', $p); $d = 0;
    for ($i = $b, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') { $d++; } elseif ($src[$i] === '}') { $d--; if ($d === 0) { return substr($src, $b, $i - $b + 1); } }
    }
    return '';
}
/* the body of a `NAME=function(){...}` assignment (SN.stopCompass is one) */
function assignBody(string $src, string $name): string {
    $p = strpos($src, $name . '=function(');
    if ($p === false) { return ''; }
    $b = strpos($src, '{', $p); $d = 0;
    for ($i = $b, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') { $d++; } elseif ($src[$i] === '}') { $d--; if ($d === 0) { return substr($src, $b, $i - $b + 1); } }
    }
    return '';
}

echo "\n=== S Hari Nav pro: cockpit, flight, device tier, GPS, TV, honest live status ===\n\n";
$root = dirname(__DIR__);
$read = static fn (string $f): string => (string) @file_get_contents($root . '/' . $f);
$nav  = $read('assets/js/15-nav.js');
$pro  = $read('assets/js/26-navpro.js');
$adm  = $read('assets/js/13-admin-routes.js');
$i18n = $read('assets/js/04-i18n.js');
$tpl  = $read('app.template.html');
$css  = $read('assets/css/views.css');
$sw   = $read('sw.js');

check('both navigator bundles exist', $nav !== '' && $pro !== '');
check('the loader, the worker, the template, the stylesheet, the language table are readable', $adm !== '' && $sw !== '' && $tpl !== '' && $css !== '' && $i18n !== '');

/* the three language blocks of 04-i18n.js (same slicing as a11y-voice-test.php) */
$pe = strpos($i18n, "\nen: {"); $ph = strpos($i18n, "\nhi: {"); $pn = strpos($i18n, "\nne: {"); $pz = strpos($i18n, "\nlet LANG");
$lang = [
    'en' => $pe !== false && $ph !== false ? substr($i18n, $pe, $ph - $pe) : '',
    'hi' => $ph !== false && $pn !== false ? substr($i18n, $ph, $pn - $ph) : '',
    'ne' => $pn !== false && $pz !== false ? substr($i18n, $pn, $pz - $pn) : '',
];
$missingIn = static function (string $key) use ($lang): array {
    $miss = [];
    foreach ($lang as $l => $blk) { if (!preg_match('~[\s,{]' . preg_quote($key, '~') . '\s*:~', $blk)) { $miss[] = $l; } }
    return $miss;
};
check('04-i18n.js has the three language blocks', $lang['en'] !== '' && $lang['hi'] !== '' && $lang['ne'] !== '');

/* the nav section of the template */
$ns = strpos($tpl, '<section class="view" id="view-nav">');
$ne = $ns !== false ? strpos($tpl, '</section>', $ns) : false;
$navTpl = ($ns !== false && $ne !== false) ? substr($tpl, $ns, $ne - $ns) : '';
check('template has section#view-nav', $navTpl !== '');

echo "\n-- 1. loader + service worker --\n";
$load = fnBody($adm, 'snNavLoad');
check('snNavLoad() loads 15-nav.js', str_contains($load, "'15-nav.js'"));
check('snNavLoad() loads 26-navpro.js', str_contains($load, "'26-navpro.js'"));
check('…the pro bundle AFTER 15-nav.js resolved (.then chain)', (bool) preg_match("/snScript\\('15-nav\\.js'\\)\\s*\\.then\\(.{0,120}?snScript\\('26-navpro\\.js'\\)/s", $load));
check('…with the same ?v= stamp as 13-admin-routes.js', str_contains($load, "'/assets/js/'+name+(ver?'?v='+encodeURIComponent(ver):'')"));
check('…a failed pro load is swallowed (navigator still opens)', (bool) preg_match("/snScript\\('26-navpro\\.js'\\)\\.catch\\(function\\(\\)\\{\\}\\)/", $load));
check('…SN_NAV_LOADED is set once 15-nav.js is in', str_contains($load, 'SN_NAV_LOADED=true'));
check('sw.js precaches 26-navpro.js next to 15-nav.js', str_contains($sw, "'/assets/js/26-navpro.js?v=' + ASSET_VER") && strpos($sw, '26-navpro.js') > strpos($sw, '15-nav.js'));
check('renderNav() re-arms the pro listeners on re-entry', str_contains(fnBody($nav, 'renderNav'), 'snProAttach()'));
check('initNavApp() hands its helpers to the pro bundle and calls snProInit()', str_contains($nav, 'SN.ease=snEase; SN.toast=snToast; SN.kick=snMotionKick; SN.navCamOn=snNavCamOn;') && str_contains($nav, 'snProInit()'));

echo "\n-- 2. syntax, size, line endings --\n";
$node = trim((string) @shell_exec('node --version 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
if ($node !== '') {
    foreach (['assets/js/15-nav.js', 'assets/js/26-navpro.js'] as $f) {
        $out = []; $rc = 1;
        @exec('node --check ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $rc);
        check("node --check $f", $rc === 0, implode(' ', array_slice($out, 0, 2)));
    }
} else {
    echo "  skip node --check: node not found\n";
}
$lines = substr_count($pro, "\n") + 1;
check('26-navpro.js stays under ~900 lines', $lines <= 900, $lines . ' lines');
check('15-nav.js keeps CRLF line endings (no whole-file rewrite)', substr_count($nav, "\r\n") > 2000 && substr_count($nav, "\n") - substr_count($nav, "\r\n") < 5);
check('26-navpro.js is a classic script (no import / export / top-level await)', !preg_match('/^\s*(import|export)\s/m', $pro) && !str_contains($pro, 'await '));
check('26-navpro.js documents window.SHG_NAV.onBusFix in its header', (bool) preg_match('/window\.SHG_NAV\.onBusFix\(\{lat, lng, bearing, spd, ts\}\)/', substr($pro, 0, 5000)));
check('every pro hook in 15-nav.js is typeof-guarded', (function () use ($nav): bool {
    foreach (explode("\n", $nav) as $ln) {
        if (preg_match('/\bsnPro[A-Z]\w*\(/', $ln) && !str_contains($ln, 'typeof snPro')) { return false; }
    }
    return true;
})());

echo "\n-- 3. i18n: markup keys + the pro bundle's snT() keys in en / hi / ne --\n";
$used = [];
if (preg_match_all('/data-i18n(?:-[a-z]+)?\s*=\s*"([^"]+)"/', preg_replace('/<!--[\s\S]*?-->/', '', $navTpl), $m)) { $used = $m[1]; }
if (preg_match_all("/\\bsnT\\(\\s*'([A-Za-z0-9_]+)'/", $pro, $m)) { $used = array_merge($used, $m[1]); }
if (preg_match_all("/\\bsnProToast\\(\\s*'([A-Za-z0-9_]+)'/", $pro, $m)) { $used = array_merge($used, $m[1]); }
if (preg_match("/\\{low:'(\\w+)',mid:'(\\w+)',high:'(\\w+)'\\}/", $pro, $m)) { $used = array_merge($used, [$m[1], $m[2], $m[3]]); }
$used = array_values(array_unique($used));
$gaps = [];
foreach ($used as $k) { $mi = $missingIn($k); if ($mi !== []) { $gaps[] = $k . ' (' . implode(',', $mi) . ')'; } }
check('every nav markup + pro bundle key exists in all three languages', $gaps === [], $gaps === [] ? count($used) . ' keys' : implode('; ', $gaps));
foreach (['snCockpitT', 'snLyCockpit', 'snModeFlight', 'snFlightNote', 'snDeviceRow', 'snHudGps', 'snGpsFix', 'snGpsAcc',
          'snLiveDriverRow', 'snLiveFleetRow', 'snComingSoon', 'snKeysHint', 'snSaveQuota', 'snSaveNoRoom',
          'snCockpitNoDev', 'snLiteLocked', 'snLiveLocal'] as $k) {
    check("key $k present in en / hi / ne", $missingIn($k) === []);
}
foreach (['snCockpitT', 'snModeFlight', 'snDeviceRow', 'snComingSoon', 'snLiteLocked'] as $k) {
    check("$k is used by the markup", in_array($k, $used, true));
}
check('the mode name map in 15-nav.js knows flight', str_contains(fnBody($nav, 'snModeName'), "flight:'snModeFlight'"));

echo "\n-- 4. honesty: no new facts, no fake live feed --\n";
check('26-navpro.js has no phone number literal', !preg_match('/\+91|\+977|\b\d{10}\b|\b9\d{4}\s?\d{5}\b/', $pro));
check('26-navpro.js has no price literal', !preg_match('/₹|\bRs\.?\s?\d|\bINR\b|\bNPR\b/u', $pro));
check('26-navpro.js has no e-mail / address literal', !preg_match('/@[a-z0-9-]+\.[a-z]{2,}|Mehsana|Shilpa Garage/i', $pro));
check('fleet GPS unit + CCTV row carries the Coming-soon badge (track card AND layers)', substr_count($navTpl, 'data-i18n="snLiveFleetRow"') === 2 && substr_count($navTpl, 'class="sn-soon" data-i18n="snComingSoon"') === 2);   /* review fix 10 Oct: the flight card's badge went (no booking promise) */
check('the live-bus row reflects the real feed only (driver / kv timestamps)', str_contains(fnBody($pro, 'snProLiveStatus'), 'SN.trackLastTs') && str_contains(fnBody($pro, 'snProLiveStatus'), 'SN.driverBroadcast') && str_contains(fnBody($pro, 'snProLiveStatus'), "snT('snLiveWait')"));
check('no fabricated position or video anywhere in the pro bundle', !preg_match('/<video|\.mp4|\bhls\b|fakeFix|demoFix|Math\.random\(\)\s*\*\s*\d+\s*[,+]\s*\d/i', $pro));
/* review fix 10 Oct: the owner has not decided on a flight product - no booking promise anywhere */
$fcS = strpos($navTpl, 'id="snFlightCard"'); $fcE = $fcS !== false ? strpos($navTpl, 'id="snTrackCard"', $fcS) : false;
$flightCard = ($fcS !== false && $fcE !== false) ? substr($navTpl, $fcS, $fcE - $fcS) : '';
check('flight card says estimate and makes NO booking promise', str_contains($lang['en'], 'estimate') && $flightCard !== '' && str_contains($flightCard, 'snFlightNote') && !str_contains($flightCard, 'snComingSoon') && !str_contains($navTpl, 'snFlightSoon') && !str_contains($i18n, 'snFlightSoon'));
check('no "booking" wording in the flight lines (en / hi / ne)', (function () use ($lang): bool {
    foreach ($lang as $blk) {
        if (preg_match("/snFlight\\w*: '[^']*(booking|बुकिंग|बुकिङ)/u", $blk)) { return false; }
    }
    return true;
})());
/* review fix 10 Oct: "this phone is sharing" only when it is true for other phones */
$self = fnBody($pro, 'snProSelfLive');
check('snLiveSelf needs staff + a running GPS watch + a server-accepted publish < 30 s old', str_contains($self, 'SN.driverBroadcast') && str_contains($self, 'snProIsStaff()') && str_contains($self, 'SN.watchId!=null') && str_contains($self, '(Date.now()-SN.kvPushOkAt)<30000'));
check('a non-staff broadcast reads "this browser only", never live', str_contains(fnBody($pro, 'snProLiveStatus'), "if(SN.driverBroadcast&&!snProIsStaff()){ txt=snT('snLiveLocal'); st='wait'; }") && !preg_match("/SN\\.driverBroadcast\\)\\{\\s*txt=snT\\('snLiveSelf'\\)/", $pro));
check('15-nav.js stamps SN.kvPushOkAt only when the server accepted the publish', str_contains($nav, ".then(function(){ SN.kvPushOkAt=Date.now(); }).catch(function(){});"));

echo "\n-- 5. flight mode never calls the routing engine --\n";
$route = fnBody($nav, 'snRoute');
$guardPos = strpos($route, "if(SN.mode==='flight'){ if(typeof snProFlight==='function') snProFlight(); return; }");
$fetchPos = strpos($route, 'fetch(SN_ROUTE_URL');
check('snRoute() returns for flight BEFORE the engine fetch', $guardPos !== false && $fetchPos !== false && $guardPos < $fetchPos);
check('26-navpro.js never fetches, never names the route URL', !str_contains($pro, 'fetch(') && !str_contains($pro, 'SN_ROUTE_URL') && !str_contains($pro, 'nav-route.php') && !str_contains($pro, 'valhalla'));
check('snProFlight() leaves SN.routeGeo null (turn-by-turn never starts)', str_contains(fnBody($pro, 'snProDropRoadRoute'), 'SN.routeGeo=null') && str_contains(fnBody($pro, 'snProFlight'), 'snProDropRoadRoute()') && !preg_match('/SN\.routeGeo\s*=\s*(?!null)/', $pro));
check('the arc is a 64-point great circle drawn as a dashed line', str_contains(fnBody($pro, 'snProFlight'), 'snProArc(SN.origin,SN.dest,64)') && str_contains(fnBody($pro, 'snProFlight'), "'line-dasharray'"));
check('cruise estimate at 750 km/h, labelled estimate', str_contains($pro, 'SNP_FLIGHT_KMH = 750') && str_contains($lang['en'], 'snFlightDist') && str_contains($lang['en'], '(estimate)'));
check('the flight chip exists (data-snmode="flight", ✈️)', (bool) preg_match('/data-snmode="flight"[^>]*>.*?✈️/s', $navTpl));
check('the flight arc is cleared on Clear and on a road re-route', str_contains($nav, 'snProFlightClear') && substr_count($nav, 'snProFlightClear()') >= 2);

echo "\n-- 6. cleanupNav removes every new listener --\n";
$clean = fnBody($nav, 'cleanupNav');
check('cleanupNav() calls SN.stopCompass() and snProCleanup()', str_contains($clean, 'SN.stopCompass()') && str_contains($clean, 'snProCleanup()'));
$pc = fnBody($pro, 'snProCleanup');
check('snProCleanup() removes the keydown listener', (bool) preg_match("/removeEventListener\\('keydown',SNP\\.keyFn\\)/", $pc));
check('snProCleanup() removes the visibilitychange listener', (bool) preg_match("/removeEventListener\\('visibilitychange',SNP\\.visFn\\)/", $pc));
check('snProCleanup() removes the resize listener', (bool) preg_match("/removeEventListener\\('resize',SNP\\.resizeFn\\)/", $pc));
check('snProCleanup() clears the GPS-age timer and hides the cockpit', str_contains($pc, 'clearInterval(SNP.ageT)') && str_contains($pc, 'snProCockpitShow(false)'));
$sc = assignBody($nav, 'SN.stopCompass');
check('SN.stopCompass() removes both deviceorientation listeners', str_contains($sc, "removeEventListener('deviceorientationabsolute', snOnOrient, true)") && str_contains($sc, "removeEventListener('deviceorientation', snOnOrient, true)"));
$att = fnBody($pro, 'snProAttach');
check('snProAttach() adds exactly what cleanup removes (keydown, visibilitychange, resize, timer)', str_contains($att, "addEventListener('keydown',SNP.keyFn)") && str_contains($att, "addEventListener('visibilitychange',SNP.visFn)") && str_contains($att, "addEventListener('resize',SNP.resizeFn,{passive:true})") && str_contains($att, 'SNP.ageT=setInterval'));
check('the compass permission is asked only from a tap (📍 / 🧭), never on load', str_contains($nav, "s('#snCompass').onclick=function(){\r\n    snStartCompass();") && str_contains($nav, "s('#snLocate').onclick=function(){\r\n    if(!navigator.geolocation){ snToast(snT('snNoGps')); return; }\r\n    snStartCompass();") && substr_count($nav, 'snStartCompass();') === 2);
check('the pro bundle adds no rAF loop of its own (reuses SN.motionAnim)', !str_contains($pro, 'requestAnimationFrame'));

echo "\n-- 7. device tier, map options, offline quota --\n";
$dp = fnBody($pro, 'snDeviceProfile');
check('snDeviceProfile() reads cores / memory / connection', str_contains($dp, 'hardwareConcurrency') && str_contains($dp, 'deviceMemory') && str_contains($dp, 'effectiveType') && str_contains($dp, 'saveData'));
check('…the WebGL renderer string, guarded', str_contains($dp, "getExtension('WEBGL_debug_renderer_info')") && str_contains($dp, 'try{'));
check('…window.SHG_TIER wins when the perf package set it', str_contains($dp, 'window.SHG_TIER'));
check('…storage.estimate() (async) is read', str_contains(fnBody($pro, 'snProStorage'), 'navigator.storage.estimate()'));
check('…and the profile is cached', str_contains($dp, 'if(SNP.profile) return SNP.profile;'));
check('tier -> tile cache 60 / 150 / 400', str_contains($dp, "tier==='low'?60:(tier==='mid'?150:400)"));
$mo = fnBody($pro, 'snProMapOpts');
check('snProMapOpts() sets maxTileCacheSize, pixelRatio cap, antialias, fadeDuration', str_contains($mo, 'o.maxTileCacheSize=') && str_contains($mo, "t==='low'?1.5:") && str_contains($mo, 'o.antialias=') && str_contains($mo, 'o.fadeDuration='));
check('15-nav.js builds the map from snProMapOpts(mapOpts)', str_contains($nav, 'mapOpts=snProMapOpts(mapOpts)||mapOpts;') && str_contains($nav, 'map=new maplibregl.Map(mapOpts);'));
check('a low tier forces Fast mode', str_contains($nav, 'snProForceLite()) SN.lite=true;') && str_contains($pro, "function snProForceLite(){ return snDeviceProfile().tier==='low'; }"));
/* review fix 10 Oct: on a basic device nothing offers a choice that cannot work */
$ll = fnBody($pro, 'snProLockLow');
check('low tier: the Fast-mode switch is ticked + disabled and its note shown', str_contains($ll, 'if(!snProForceLite()) return;') && str_contains($ll, 'lt.checked=true; lt.disabled=true;') && str_contains($ll, 'note.hidden=false') && str_contains($navTpl, 'id="snLyLiteNote" data-i18n="snLiteLocked" hidden'));
check('low tier: the ⚡ pill and the switch only say why (no reload loop)', str_contains($ll, "fp.onclick=function(){ snProToast('snLiteLocked'); }") && str_contains($ll, "lt.onchange=function(){ lt.checked=true; snProToast('snLiteLocked'); }") && !str_contains($ll, 'location.reload'));
check('low tier: the cockpit button and switch are hidden', str_contains($ll, "b.style.display='none'") && str_contains($ll, "cl.style.display='none'"));
check('snProInit() runs the low-tier lock', str_contains(fnBody($pro, 'snProInit'), 'snProLockLow();'));
check('the cockpit toast says "untick Fast mode" only where that can work', str_contains(fnBody($pro, 'snProSetCockpit'), "(SN.weak||snDeviceProfile().tier==='low')?'snCockpitNoDev':'snCockpitLite'"));
check('the device row: memory "8+" at the browser cap, storage called site data, "device" not "phone"', str_contains(fnBody($pro, 'snProDeviceRow'), "p.mem>=8?'8+'") && str_contains($lang['en'], "snDeviceRowFmt: '{c} cores · {m} GB RAM · {t} · {mb} MB site data on this device'") && !preg_match("/snDeviceRowFmt: '[^']*phone/", $lang['en']));
check('MapLibre keyboard handler off - snProKey is the one key driver', str_contains($mo, 'o.keyboard=false;'));
$sp = fnBody($pro, 'snProSavePlan');
check('offline save capped at 25 % of the free quota, MB said first', str_contains($sp, 'st.free/1048576*0.25') && str_contains($sp, "snT('snSaveQuota'") && str_contains($sp, "snProToast('snSaveNoRoom')"));
check('#snOffSave asks snProSavePlan() before posting to the worker', (bool) preg_match('/snProSavePlan\(urls\).{0,400}postMessage\(\{type:\'shg-prefetch\'/s', $nav));
check('the "This device" row shows cores · GB · tier · MB', str_contains($navTpl, 'id="snDeviceV"') && str_contains(fnBody($pro, 'snProDeviceRow'), "snT('snDeviceRowFmt'") && str_contains($lang['en'], 'snDeviceRowFmt'));
check('terrain / 3D stays mid+ (maxPitch 60 on low, Fast mode never builds them)', str_contains($mo, "o.maxPitch=t==='low'?60:70"));

echo "\n-- 8. low-latency GPS --\n";
check('watchPosition: enableHighAccuracy true, maximumAge 0', (bool) preg_match('/watchPosition\(.*?\{enableHighAccuracy:true,maximumAge:0,timeout:\d+\}\)/s', $nav));
check('HUD has the GPS fix cell', str_contains($navTpl, 'id="snGps"') && str_contains($navTpl, 'data-i18n="snHudGps"'));
check('cadence + accuracy written on every fix ("GPS 1.0 s · ±8 m")', str_contains(fnBody($pro, 'snProGpsCell'), "snT('snGpsFix',{ s:(gap/1000).toFixed(1)") && str_contains($lang['en'], "snGpsFix: 'GPS {s} s · ±{m} m'"));
/* review fix 10 Oct: only a measured cadence is ever shown */
check('no cadence before two real fixes (accuracy only), never the 1000 ms default', str_contains(fnBody($pro, 'snProGpsCell'), "gap>0?snT('snGpsFix'") && str_contains(fnBody($pro, 'snProGpsCell'), "snT('snGpsAcc',{ m:m })") && !str_contains((string) preg_replace('~/\*.*?\*/~s', '', fnBody($pro, 'snProGpsCell')), 'gpsIntervalMs'));
check('gaps under 200 ms are ignored, a pause over a minute resets', str_contains(fnBody($pro, 'snProOnFix'), 'if(gap>60000) SNP.fixGapMs=0; else if(gap>=200) SNP.fixGapMs=gap;'));
check('snProCleanup() resets the fix clock (no "GPS 300 s" after a visit away)', str_contains(fnBody($pro, 'snProCleanup'), 'SNP.lastFixAt=0; SNP.fixGapMs=0;'));
check('onSnPos() calls snProOnFix and snProFuseHeading', str_contains($nav, 'snProOnFix(c, hdg)') && str_contains($nav, 'hdg=snProFuseHeading(c, gpsHdg, hdg)'));
$fh = fnBody($pro, 'snProFuseHeading');
check('below 2 m/s the compass (low-passed) or the held heading is used', str_contains($fh, 'if(spd<2){') && str_contains($fh, 'SNP.compassLP') && str_contains($fh, 'SNP.heldHdg'));
check('a stale fix (> 4 s) is said so in the HUD', str_contains(fnBody($pro, 'snProGpsAge'), "snT('snGpsOld'") && str_contains($css, '#snGps.stale'));

echo "\n-- 9. cockpit view --\n";
check('#snCockpit button in the right stack with i18n title', (bool) preg_match('/<button class="sn-cb" id="snCockpit" title="[^"]*" data-i18n-title="snCockpitT" aria-pressed="false">🚘<\/button>/', $navTpl));
check('#snLyCockpit checkbox in Layers', str_contains($navTpl, 'id="snLyCockpit"') && str_contains($navTpl, 'data-i18n="snLyCockpit"'));
check('#snCockpitLayer is aria-hidden and pointer-events:none', str_contains($navTpl, '<div class="sn-cockpit" id="snCockpitLayer" aria-hidden="true"></div>') && (bool) preg_match('/#view-nav \.sn-cockpit\{[^}]*pointer-events:none/', $css));
check('per-mode presets: car 62/17, bike 66/17.5, bus 55/16, cycle 45/18, walk 45/18, flight 30/9', str_contains($pro, 'auto:       { pitch:62, zoom:17,') && str_contains($pro, 'motorcycle: { pitch:66, zoom:17.5,') && str_contains($pro, 'bus:        { pitch:55, zoom:16,') && str_contains($pro, 'bicycle:    { pitch:45, zoom:18,') && str_contains($pro, 'pedestrian: { pitch:45, zoom:18,') && str_contains($pro, 'flight:     { pitch:30, zoom:9,'));
check('the motion loop reads snProCamTarget() with the existing time constants', str_contains($nav, 'snProCamTarget():null;') && str_contains($nav, 'SN_TAU_ZM') && str_contains($nav, 'SN_TAU_PITCH') && str_contains($nav, 'pc?pc.zoom:snTargetZoom()') && str_contains($nav, 'pc?pc.pitch:52'));
check('snProCamTarget() is pure arithmetic (no DOM read per frame)', !preg_match('/getElementById|querySelector|snProEl\(/', fnBody($pro, 'snProCamTarget')));
check('the bus preset looks further ahead, read live for the current mode (snProLook)', str_contains($nav, "var look=(typeof snProLook==='function')?snProLook():null;") && str_contains($nav, 'look||(walk?0.025:0.07)') && str_contains($pro, 'bus:        { pitch:55, zoom:16,   look:0.12 }') && !str_contains($pro, 'SN.proLook') && !str_contains($nav, 'SN.proLook'));
check('snProLook() is pure arithmetic over SN.mode', str_contains(fnBody($pro, 'snProLook'), '(SNP_CAM[SN.mode]||SNP_CAM.auto).look') && !preg_match('/getElementById|querySelector|snProEl\(/', fnBody($pro, 'snProLook')));
/* review fix 10 Oct: phone HUD, Layers height, attribution, flight gauges */
check('the cockpit moves the HUD on wide screens only (phone HUD keeps top:132px)', str_contains($css, '@media(min-width:641px){#view-nav.sn-cockpit-on .sn-hud{bottom:calc(26% + 10px)}}') && !preg_match('/^#view-nav\.sn-cockpit-on \.sn-hud\{bottom/m', $css));
check('Layers scrolls inside the viewport', (bool) preg_match('/#view-nav \.sn-layers\{max-width:min\(320px,calc\(100vw - 28px\)\);\s*\/\*[^*]*\*\/\s*max-height:calc\(100vh - 96px\);max-height:calc\(100dvh - 96px\);overflow-y:auto;overscroll-behavior:contain\}/', $css));
check('attribution + scale stay above the cockpit band', str_contains($css, '#view-nav.sn-cockpit-on .maplibregl-ctrl-bottom-left,#view-nav.sn-cockpit-on .maplibregl-ctrl-bottom-right{z-index:15}'));
check('the flight cockpit shows no speed / heading gauges', str_contains($css, '#view-nav .sn-cockpit[data-ck="flight"] .sn-ck-gauge,#view-nav .sn-cockpit[data-ck="flight"] .sn-ck-head{display:none}'));
check('silhouettes for car / motorcycle / bus / bicycle / pedestrian / flight', (function () use ($pro): bool {
    $b = fnBody($pro, 'snProCockpitSvg');
    foreach (['motorcycle', 'bus', 'bicycle', 'pedestrian', 'flight'] as $m) { if (!str_contains($b, "mode==='$m'")) { return false; } }
    return str_contains($b, '/* car (auto) */');
})());
check('overlay carries speed, heading and the next-turn arrow, mode-coloured (--ck)', str_contains($pro, 'id="snCkSpeed"') && str_contains($pro, 'id="snCkHead"') && str_contains($pro, 'id="snCkArrow"') && str_contains($pro, "L.style.setProperty('--ck',SNP_CLR[mode]") && str_contains($css, 'stroke:var(--ck)'));
check('default ON for a high tier on the full map, OFF in Fast mode / low tier', str_contains(fnBody($pro, 'snProCockpitWanted'), "tier==='high'&&!SN.lite") && str_contains(fnBody($pro, 'snProCockpitArmed'), "!SN.lite&&!SN.weak&&snDeviceProfile().tier!=='low'"));
check('sway honours prefers-reduced-motion + html[data-motion=reduced] + hidden tab', str_contains($pro, "'sn-ck-moving',spd>=5&&!snProReduced()") && str_contains(fnBody($pro, 'snProReduced'), "data-motion')==='reduced'") && str_contains(fnBody($pro, 'snProReduced'), 'prefers-reduced-motion: reduce') && str_contains($css, 'html[data-motion="reduced"] #view-nav .sn-cockpit .sn-ck-art{animation:none}') && (bool) preg_match('/@media \(prefers-reduced-motion:reduce\)\{#view-nav \.sn-cockpit \.sn-ck-art\{animation:none\}/', $css) && str_contains($css, '.sn-ck-paused .sn-ck-art{animation-play-state:paused}'));
check('the sway animates transform only', (bool) preg_match('/@keyframes snCkSway\{0%,100%\{transform:[^}]*\}50%\{transform:[^}]*\}\}/', $css));
check('cockpit hidden while the tab is hidden (visibilitychange -> sync)', str_contains($att, "'visibilitychange'") && str_contains(fnBody($pro, 'snProCockpitSync'), '!document.hidden'));
check('cockpit shows only while the camera is inside the vehicle (navCamOn or flight)', str_contains(fnBody($pro, 'snProCockpitSync'), 'SN.navCamOn()') && str_contains(fnBody($pro, 'snProCockpitSync'), "SNP.flightOn&&SN.mode==='flight'"));

echo "\n-- 10. TV + keyboard --\n";
check('html.shg-tv or a big no-hover / no-pointer screen gives #view-nav the sn-tv class', str_contains(fnBody($pro, 'snProTvCheck'), "classList.contains('shg-tv')") && str_contains(fnBody($pro, 'snProTvCheck'), 'matchMedia(SNP_TV_MQ)') && str_contains(fnBody($pro, 'snProTvCheck'), "'sn-tv'"));
check('a 1920 px mouse desktop is NOT a TV (width alone never triggers it)', str_contains($pro, "var SNP_TV_MQ = '(min-width:1800px) and (hover:none), (min-width:1200px) and (any-pointer:none)';") && !str_contains($pro, "matchMedia('(min-width:1800px)')"));
check('sn-tv: 1.5x controls, 20 px type, strong focus ring', str_contains($css, '#view-nav.sn-tv .sn-cb{width:66px;height:66px') && (bool) preg_match('/#view-nav\.sn-tv [^{]*\{font-size:20px\}/', $css) && str_contains($css, '#view-nav.sn-tv :focus-visible{outline:4px solid var(--sg)'));
$key = fnBody($pro, 'snProKey');
foreach ([["'ArrowUp'", 'pan'], ["'+'", 'zoomIn'], ["'-'", 'zoomOut'], ["'r'", 'bearing'], ["'l'", 'bearing'], ["'3'", "click('sn3D')"], ["'c'", 'snProSetCockpit'], ["'Enter'", "click('snGo')"], ["'Escape'", "click('snBack')"]] as [$k, $act]) {
    check("key $k -> $act", str_contains($key, 'case ' . $k) && str_contains($key, $act));
}
check('keys only while #view-nav is active and no input is focused', str_contains($key, "nv.classList.contains('active')") && str_contains($key, '/^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName)'));
check('a key another handler already used is left alone (defaultPrevented)', str_contains($key, 'e.defaultPrevented'));
check('Enter = Get directions only when both fields are set', str_contains($key, 'if(SN.origin&&SN.dest&&'));
check('Esc = exit focus mode / back', str_contains($key, "click('snFocusExit')") && str_contains($key, "click('snBack')"));

echo "\n-- 11. honest live status + the fleet-GPS entry point --\n";
check('status rows in the track card and in Layers (driver feed + fleet/CCTV)', str_contains($navTpl, 'id="snTcDriverV"') && str_contains($navTpl, 'id="snStDriverV"') && substr_count($navTpl, 'data-i18n="snLiveDriverRow"') === 2);
check('window.SHG_NAV.onBusFix is exposed', str_contains($pro, 'window.SHG_NAV.onBusFix=snProBusFix;'));
check('onBusFix feeds SN.busFixSink = the driver feed\'s onBusFix in 15-nav.js', str_contains($nav, 'SN.busFixSink=onBusFix;') && str_contains(fnBody($pro, 'snProBusFix'), 'SN.busFixSink({ lat:fix.lat, lng:fix.lng,'));
check('onBusFix validates lat / lng and returns false otherwise', str_contains(fnBody($pro, 'snProBusFix'), "typeof fix.lat!=='number'||typeof fix.lng!=='number'||!isFinite(fix.lat)||!isFinite(fix.lng)) return false;"));
check('the sink is dropped when tracking is switched off', str_contains($nav, 'SN.busFixSink=null;'));
check('snTrackTick() refreshes the status rows', str_contains(fnBody($nav, 'snTrackTick'), 'snProLiveStatus(false)'));
check('a language switch re-writes the pro lines (SN.relabel -> snProRelabel)', str_contains($nav, 'snProRelabel()') && str_contains(fnBody($pro, 'snProRelabel'), 'snProLiveStatus(false)') && str_contains(fnBody($pro, 'snProRelabel'), 'snProDeviceRow()'));

echo "\n-- 12. the template keeps its nav structure --\n";
check('six mode chips (car, bike, bus, cycle, walk, flight)', preg_match_all('/data-snmode="(auto|motorcycle|bus|bicycle|pedestrian|flight)"/', $navTpl) === 6);
check('new ids are unique in the template', (function () use ($tpl): bool {
    foreach (['snCockpit', 'snCockpitLayer', 'snLyCockpit', 'snFlightCard', 'snFlightDist', 'snGps', 'snDeviceV', 'snStDriverV', 'snTcDriverV'] as $id) {
        if (substr_count($tpl, 'id="' . $id . '"') !== 1) { return false; }
    }
    return true;
})());
check('no inline event handler added to the nav markup by this package', !preg_match('/id="(snCockpit|snLyCockpit|snFlightCard)"[^>]*\bon[a-z]+=/', $navTpl));
check('depth.css is still the last stylesheet', (function () use ($tpl): bool {
    preg_match_all('/<link[^>]+rel="stylesheet"[^>]+href="([^"]+)"/', $tpl, $m);
    $last = end($m[1]);
    return is_string($last) && str_contains($last, 'depth.css');
})());

echo "\n----------------------------------------\n";
echo "PASSED: {$PASS}   FAILED: {$FAIL}\n";
echo "----------------------------------------\n\n";
exit($FAIL === 0 ? 0 : 1);
