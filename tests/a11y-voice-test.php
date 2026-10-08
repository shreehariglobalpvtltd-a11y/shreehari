<?php
/**
 * =====================================================================
 *  a11y-voice-test.php — dialog focus, voice guide, track screen, theme,
 *  type floor (premium upgrade wave 2 / FC, 8 Oct 2026)
 *
 *  Static checks on the customer bundle (no browser needed). The runtime
 *  behaviour was verified in headless Chrome (Tab x30 inside the sign-in
 *  sheet, speechSynthesis.speak stubbed, geolocation wrapped):
 *
 *    • D2-08  the shared sheet (#shgModal) is named by its heading, the page
 *             behind is inert (except #toast), Tab / Shift+Tab loop inside,
 *             focus returns to the opener; the toast sits above the sheet
 *    • D7-09  Voice guide: own switch, OFF by default, hidden without
 *             speechSynthesis, t('vg_*') lines in ne / hi / en, rate 0.95,
 *             cancelled on route change / hidden tab, a pending ticket is
 *             never "ready"; the navigator's voice follows the app
 *             language, its choices are remembered, arrival is localised
 *    • D7-07  #/nav starts GPS by itself only when permission is 'granted'
 *    • D2-14  #/nav markup + 15-nav.js lines are translated; a disabled
 *             "Get directions" says why
 *    • Ph 12  the system theme is followed live while nothing is saved;
 *             a saved choice wins; "Auto" clears it
 *    • D1-08 / D2-10  type tokens in ds.css, a floor block at the END of
 *             depth.css that leaves #view-seats alone
 *    • sound / vibration stay ON by default (owner, 5 Oct)
 *
 *    php -c .claude/php-dev.ini tests/a11y-voice-test.php
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
    if ($p === false) return '';
    $b = strpos($src, '{', $p); $d = 0;
    for ($i = $b, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $d++;
        elseif ($src[$i] === '}') { $d--; if ($d === 0) return substr($src, $b, $i - $b + 1); }
    }
    return '';
}

echo "\n=== Accessibility, voice guide, track screen, theme, type floor ===\n\n";
$root  = dirname(__DIR__);
$read  = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);
$cfg   = $read('assets/js/02-config.js');
$acc   = $read('assets/js/03-accounts.js');
$i18n  = $read('assets/js/04-i18n.js');
$nav   = $read('assets/js/15-nav.js');
$prem  = $read('assets/js/19-premium.js');
$tpl   = $read('app.template.html');
$ds    = $read('assets/css/ds.css');
$depth = $read('assets/css/depth.css');

/* the three language blocks of 04-i18n.js */
$pe = strpos($i18n, "\nen: {"); $ph = strpos($i18n, "\nhi: {"); $pn = strpos($i18n, "\nne: {"); $pz = strpos($i18n, "\nlet LANG");
$lang = [
    'en' => $pe !== false && $ph !== false ? substr($i18n, $pe, $ph - $pe) : '',
    'hi' => $ph !== false && $pn !== false ? substr($i18n, $ph, $pn - $ph) : '',
    'ne' => $pn !== false && $pz !== false ? substr($i18n, $pn, $pz - $pn) : '',
];
$has = static function (string $key) use ($lang): array {
    $miss = [];
    foreach ($lang as $l => $blk) if (!preg_match('~[\s,{]' . preg_quote($key, '~') . '\s*:~', $blk)) $miss[] = $l;
    return $miss;
};
$val = static function (string $l, string $key) use ($lang): string {
    return preg_match('~[\s,{]' . preg_quote($key, '~') . "\\s*:\\s*'((?:[^'\\\\]|\\\\.)*)'~u", $lang[$l], $m) ? $m[1] : '';
};

echo "-- 1. the sign-in / OTP / cancel sheet (D2-08) --\n";
$open = fnBody($cfg, 'openModal'); $close = fnBody($cfg, 'closeModal');
check('openModal remembers the opener once per open', str_contains($open, 'm._ret = (ae && ae !== document.body && !m.contains(ae)) ? ae : null'));
check('the sheet is named by its first heading (aria-labelledby)', str_contains($cfg, "querySelector('h1,h2,h3,.m-title')") && str_contains($cfg, "m.setAttribute('aria-labelledby', h.id)"));
check('the page behind is inert, except the sheet, #toast and scripts', str_contains($open, "el.setAttribute('inert', '')") && str_contains($open, "el.id === 'toast'") && str_contains($open, 'el === m'));
check('closeModal removes inert and gives focus back', str_contains($close, "el.removeAttribute('inert')") && str_contains($close, 'ret.focus({ preventScroll: true })'));
check('Tab / Shift+Tab loop inside the box (works without inert too)', str_contains($cfg, "if (e.key !== 'Tab'") && str_contains($cfg, 'last.focus()') && str_contains($cfg, 'first.focus()'));
check('the history / Android-back logic is untouched', str_contains($open, "history.pushState({ shgModal: 1 }, '', location.href)") && str_contains($close, 'window._shgModalClosingBack = true; history.back();'));
check('the toast is not lifted over an open sheet', str_contains(fnBody($cfg, 'toast'), "document.body.classList.contains('shg-modal-open')"));
check('depth.css puts the toast above the sheet (z 130, top)', str_contains($depth, 'body.shg-modal-open #toast { z-index: 130; top: calc(12px + env(safe-area-inset-top, 0px)); bottom: auto !important;'));
check('dialog-name fallback key in en / hi / ne', $has('mDialog') === []);

echo "\n-- 2. Voice guide (D7-09) --\n";
check("its own key, OFF unless a saved '1'", str_contains($prem, "var LS_VOICE = 'shg:voice';") && str_contains($prem, "return localStorage.getItem(LS_VOICE) === '1'; } catch (e) { return false; }"));
check('the row exists only when the phone can speak', str_contains($prem, "if (VOICE_OK) menu.appendChild(row('🗣️ बोलेर बताउने · Voice guide', readVoice(), Feel.setVoice, 'voice'));") && str_contains($prem, "!!window.speechSynthesis && typeof window.SpeechSynthesisUtterance === 'function'"));
check('every switch is role=switch with aria-checked', str_contains($prem, "sw.setAttribute('role', 'switch');") && substr_count($prem, "sw.setAttribute('aria-checked'") >= 2);
check('speaks a fixed t(vg_*) line only - nothing filled in', str_contains($prem, "var vgKey = 'vg_' + key;") && str_contains($prem, 't(vgKey)') && !preg_match("~tf\\(\\s*['v]~", $prem));
check('rate 0.95, voice by language with a Hindi fallback for Nepali', str_contains($prem, 'u.rate = 0.95;') && str_contains($prem, "var VG_TAG = { en: 'en-IN', hi: 'hi-IN', ne: 'ne-NP' };") && str_contains($prem, "lang === 'ne' ? (find(function (l) { return l === 'hi-in'; })"));
check('cancelled on route change, hidden tab and page exit', str_contains($prem, "window.addEventListener('hashchange', function () {\n      vgCancel();") || (str_contains($prem, "window.addEventListener('hashchange', function () {") && str_contains($prem, 'vgCancel();')) );
check('...and on visibilitychange / pagehide', str_contains($prem, "document.addEventListener('visibilitychange', function () { if (document.hidden) vgCancel(); });") && str_contains($prem, "window.addEventListener('pagehide', vgCancel);"));
check('once per screen (same view = no repeat)', str_contains($prem, 'if (sig === vgLastSig) return;'));
check('a ticket is "ready" only when confirmed; pending gets its own line', str_contains($prem, "if (b.status === 'confirmed') return 'ticket';") && str_contains($prem, "return b.status === 'pending' ? 'ticketWait' : null;"));
$vg = ['vg_on', 'vg_home', 'vg_results', 'vg_seats', 'vg_checkout', 'vg_ticket', 'vg_ticketWait'];
$missVg = []; foreach ($vg as $k) foreach ($has($k) as $l) $missVg[] = "$l:$k";
check('every vg_* line exists in en / hi / ne', $missVg === [], implode(' ', $missVg));
$leak = [];
foreach ($vg as $k) foreach (['en', 'hi', 'ne'] as $l) { $v = $val($l, $k); if ($v === '' || preg_match('~\{|\d{4,}|SHG-~', $v)) $leak[] = "$l:$k"; }
check('no vg line carries a placeholder, a number or a PNR', $leak === [], implode(' ', $leak));
check('Hindi is Hindi and Nepali is Nepali (vg_home differs)', $val('hi', 'vg_home') !== '' && $val('hi', 'vg_home') !== $val('ne', 'vg_home'));

echo "\n-- 3. the navigator's own voice (D7-09) + GPS on open (D7-07) --\n";
check('voice language starts from the app language, a chip pick is remembered', str_contains($nav, "var SN_VLANG_KEY='shg:navVoiceLang', SN_VON_KEY='shg:navVoice';") && str_contains($nav, "l=(typeof LANG==='string'&&/^(en|hi|ne)$/.test(LANG))?LANG:'en';") && str_contains($nav, 'localStorage.setItem(SN_VLANG_KEY,SN.voiceLang)'));
check('the 🔊 switch is remembered and painted from it', str_contains($nav, "localStorage.setItem(SN_VON_KEY,SN.voiceOn?'1':'0')") && str_contains($nav, "SN.voiceOn=on!=='0';"));
check('the arrival line is spoken in the voice language', str_contains($nav, "speak(snPhrase('arrive'));") && !str_contains($nav, "speak('You have arrived at your destination')"));
check('Nepali voice falls back to a Hindi voice', str_contains($nav, "if(pre==='ne'){ for(var k=0;k<vs.length;k++){ if((vs[k].lang||'').toLowerCase().indexOf('hi')===0) return vs[k]; } }"));
check("GPS starts by itself only when permission is 'granted'", str_contains($nav, "if(p&&p.state==='granted'&&SN.watchId==null){ SN.gpsAuto=true; s('#snLocate').click(); }") && !str_contains($nav, "if(p.state!=='denied')s('#snLocate').click();"));
check('no fallback auto-start when the Permissions API is missing', !preg_match("~}else if\\(navigator\\.geolocation\\)\\{\\s*s\\('#snLocate'\\)\\.click\\(\\);~", $nav));
check('the map-load line says how to start GPS, and why', str_contains($nav, "snToast(snT(SN.watchId!=null?'snReady':'snLocHint'));") && $has('snLocHint') === []);

echo "\n-- 4. the track / map screen in the visitor's language (D2-14) --\n";
preg_match('~<section class="view" id="view-nav">(.*?)</section>~s', $tpl, $nv);
$navHtml = $nv[1] ?? '';
check('#view-nav markup found', $navHtml !== '');
foreach (['snPlanT', 'snPlanS', 'snSaved', 'snExplore', 'snPoiFood', 'snDriver', 'snTrackLive', 'snGo', 'snClear', 'snModeCar', 'snArrived', 'snContinue', 'snLiveTitle'] as $k) {
    check("data-i18n=\"$k\" on the screen", str_contains($navHtml, 'data-i18n="' . $k . '"'));
}
check('placeholders translated (search, start, destination)', str_contains($navHtml, 'data-i18n-ph="snQPh"') && str_contains($navHtml, 'data-i18n-ph="snFromPh"') && str_contains($navHtml, 'data-i18n-ph="snToPh"'));
check('icon buttons have translated names', str_contains($navHtml, 'data-i18n-title="snLocateT"') && str_contains($navHtml, 'data-i18n-title="snLayersT"') && str_contains($navHtml, 'data-i18n-title="snZinT"'));
check('a disabled "Get directions" says why', str_contains($navHtml, 'id="snGoWhy"') && str_contains($navHtml, 'aria-describedby="snGoWhy"') && str_contains($nav, "why.textContent=wk?snT(wk):''; why.hidden=!wk;"));
check('15-nav.js peek / button lines come from snT()', str_contains($nav, "snPeekSet(snT('snPlanT'),snT('snPlanS'))") && str_contains($nav, "s('#snGo').textContent=snT('snGo')") && str_contains($nav, "snPeekSet(snT('snPresetPeek'),snT('snPresetSub'))"));
check('no English peek / button literals left', !str_contains($nav, "snPeekSet('Plan your journey'") && !str_contains($nav, "textContent='Get directions'") && !str_contains($nav, "'Tap to start navigation'"));
check('a language switch re-writes the JS-set lines (renderNav -> SN.relabel)', str_contains($nav, "if(typeof SN.relabel==='function'){ try{ SN.relabel(); }catch(e){} }") && str_contains($nav, 'SN.relabel=function(){'));
preg_match_all("~snT\\('([A-Za-z0-9]+)'~", $nav, $um);
preg_match_all("~'(sn[A-Z][A-Za-z0-9]+)'~", $nav, $um2);
preg_match_all('~data-i18n(?:-ph|-title)?="(sn[A-Za-z0-9]+)"~', $navHtml, $um3);
$keys = array_unique(array_merge($um[1], $um2[1], $um3[1]));
$ids = ['snFcEta', 'snNcEta', 'snFcDist', 'snNcDist', 'snFcRoad', 'snNcRoad', 'snGoWhy'];   // element ids, not keys
$miss = [];
foreach ($keys as $k) { if (in_array($k, $ids, true)) continue; foreach ($has($k) as $l) $miss[] = "$l:$k"; }
check('every sn* key used exists in en / hi / ne (' . count($keys) . ' keys)', $miss === [], implode(' ', array_slice($miss, 0, 12)));
check('"S Hari Nav" stays a Roman name in Nepali', str_contains($val('ne', 'snReady'), 'S Hari Nav'));

echo "\n-- 5. theme follows the system live (Phase 12) --\n";
check('a matchMedia change listener (with the addListener fallback)', str_contains($acc, "mq.addEventListener('change', onChange);") && str_contains($acc, 'mq.addListener(onChange);'));
check('a saved choice wins, and the counter keeps the panel theme', str_contains($acc, 'if (readSavedTheme() || isCounterThemed()) return;'));
check('switching only re-stamps <html> (applyTheme, not saved)', str_contains($acc, 'applyTheme(systemTheme(), false);'));
check('"Auto" clears the saved choice', str_contains($acc, "if (v === 'auto') {") && str_contains($acc, "localStorage.removeItem('shg:theme');"));
check('the menu offers Auto + Light + Dark + AMOLED as radios', (bool) preg_match('~data-theme-set="auto".*data-theme-set="light".*data-theme-set="dark".*data-theme-set="amoled"~s', $tpl));
check('Auto is the lit radio while nothing is saved', str_contains($acc, "const picked = (save === false && !readSavedTheme()) ? 'auto' : mode;"));

echo "\n-- 6. type scale + floor (D1-08, D2-10) --\n";
check('type tokens in ds.css', str_contains($ds, ':root{--fs-micro:12px;--fs-caption:13px;--fs-label:14px;--fs-body:16px;'));
$fc = ($p = strpos($depth, 'FC — 8 Oct 2026: type floor')) !== false ? substr($depth, $p) : '';
check('the floor block is the last block of depth.css', $fc !== '' && !str_contains($fc, 'FB —') && substr_count($depth, 'FC — 8 Oct 2026: type floor') === 1);
check('date strip 12px, bottom bar 12.5px, form labels 14px', str_contains($fc, '.search-card .d-btn .d-bs { font-size: var(--fs-micro, 12px) !important; }') && str_contains($fc, '.bottom-nav.bn5 .bn-item .bl { font-size: 12.5px !important; }') && str_contains($fc, '#view-checkout .field label, .shg-modal .field label { font-size: var(--fs-label, 14px) !important; }'));
check('fares and dates are 13px', str_contains($fc, '.search-card .fare-live .fl-lbl, .search-card .fare-live .fl-npr { font-size: var(--fs-caption, 13px) !important; }') && str_contains($fc, '.qt-plan-facts em, .qt-plan-head span { font-size: var(--fs-caption, 13px) !important; }'));
check('the floor never touches the seat map', !str_contains($fc, '#view-seats'));
check('nothing in the floor is under 12px', !preg_match('~font-size:\s*(?:[0-9]|1[01])(?:\.\d+)?px~', $fc));
check('depth.css braces balance', substr_count($depth, '{') === substr_count($depth, '}'));
check('no text-shadow / rotate / blur / keyframes added', !preg_match('~text-shadow|rotate\(|filter:\s*blur|@keyframes~', $fc));

echo "\n-- 7. owner defaults kept --\n";
check('sound and vibration stay ON by default (a saved "0" wins)', str_contains($prem, "return v === null ? true : v === '1';"));
check('vibration still respects reduced motion', str_contains($prem, 'if (reducedMotion()) return;'));

echo "\n  ----------------------------------------\n  PASSED: {$PASS}   FAILED: {$FAIL}\n\n";
exit($FAIL === 0 ? 0 : 1);
