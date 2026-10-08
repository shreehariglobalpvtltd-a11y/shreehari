<?php
/**
 * home-animation-test.php — the three homepage animation scenes (4 Oct 2026).
 *
 * The owner's premium animation pack became three sections of #view-home:
 * #animJourney (coach on the highway, 7 s), #animRoute (Gujarat → Rupaidiha
 * corridor, 10 s) and #animBooking (sample booking on a phone, 8 s). This
 * suite pins the brief's rules so a later edit cannot quietly break them:
 *
 *   1. placement — the booking panel stays first; the scenes come after it,
 *      replace the old hidden #routeAnim / #homeRouteMap, and each has a
 *      Pause control;
 *   2. weight — only the artwork the scenes use ships (WebP/SVG, no pack PNG
 *      fallbacks), phones get the mobile files, everything is lazy with a
 *      reserved size, and each file stays inside its budget;
 *   3. honesty — the coach is never mirrored (separate left/right renders),
 *      the amenities are exactly AC / mobile charging / first-aid-medicine,
 *      the map says "illustrative" and keeps Rupaidiha on the India side,
 *      the booking is marked as a sample and its controller makes no
 *      network or storage call;
 *   4. motion — transform/opacity only, reduced motion respected, off-screen
 *      and background-tab pause, everything scoped under .shg-animation;
 *   5. language — every label exists in en, hi, ne and the Gujarati override.
 *
 *   php tests/home-animation-test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root = dirname(__DIR__);
$PASS = 0;
$FAIL = 0;

function check(string $label, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    }
}

echo "\n=== Home: animation scenes ===\n\n";

$tpl  = (string) file_get_contents($root . '/app.template.html');
$css  = (string) file_get_contents($root . '/assets/css/anim.css');
$js   = (string) file_get_contents($root . '/assets/js/20-anim.js');
$i18n = (string) file_get_contents($root . '/assets/js/04-i18n.js');
$sw   = (string) file_get_contents($root . '/sw.js');
$dir  = $root . '/assets/img/anim';

$sections = [];
foreach (['animJourney', 'animRoute', 'animBooking'] as $id) {
    $sections[$id] = preg_match('~<section class="container shg-animation(?: sa-top)?" id="' . $id . '"(.*?)</section>~s', $tpl, $m) ? $m[1] : '';
}
$all = implode("\n", $sections);

/* ---- 1. placement ---------------------------------------------------- */
foreach ($sections as $id => $html) {
    check("#{$id} exists as a scoped .shg-animation section", $html !== '');
    check("#{$id} has one scene with a Pause control (translated action label, no contradictory aria-pressed)",
        substr_count($html, 'data-anim="') === 1
        && (bool) preg_match('~<button type="button" class="sa-pause" data-paused="false" data-i18n-title="animPause"~', $html)
        && !str_contains($html, 'aria-pressed'));
}
$posSearch = strpos($tpl, 'id="search-anchor"');
$posFirst  = strpos($tpl, 'id="animJourney"');
// 8 Oct 2026 (owner prompt Phase 4, D3-09): booking first - the coach banner (with the offices card) now
// sits BELOW the booking panel and QuickBot; the route + booking scenes stay below it.
$posQuickA = strpos($tpl, 'id="quick-ticket"');
check('the coach banner sits below the booking panel and QuickBot, the other scenes below it', $posSearch !== false && $posFirst !== false && $posQuickA !== false
    && $posSearch < $posQuickA && $posQuickA < $posFirst
    && strpos($tpl, 'id="animRoute"') > $posFirst && str_contains($tpl, 'class="container shg-animation sa-top" id="animJourney"'));
check('the old hidden highway scene and route card are gone', !str_contains($tpl, 'id="routeAnim"') && !str_contains($tpl, 'id="homeRouteMap"'));
check('the coach is first (order -3), the other scenes after the brand band (order 6–7)',
    (bool) preg_match('~#view-home > #animJourney \{ order: -3 !important; \}~', $css)
    && (bool) preg_match('~#view-home > #animRoute \{ order: 6 !important; \}~', $css)
    && (bool) preg_match('~#view-home > #animBooking \{ order: 7 !important; \}~', $css));
check('no blocking splash: nothing in the scenes delays or hides the booking panel',
    !preg_match('~search-anchor|searchForm|splash~i', $js));

/* ---- 2. weight --------------------------------------------------------- */
preg_match_all('~/assets/img/anim/([a-z0-9-]+\.(?:webp|svg|png))~', $tpl . "\n" . $css, $refs);
$used = array_values(array_unique($refs[1]));
// the left-facing coach is swapped in by the controller (name derived from the right-facing one)
foreach (['shg-bus-left.webp', 'shg-bus-left-mobile.webp'] as $f) {
    if (!in_array($f, $used, true)) { $used[] = $f; }
}
sort($used);
$onDisk = array_values(array_filter(scandir($dir) ?: [], static fn($f) => $f[0] !== '.'));
sort($onDisk);
check('every artwork file the page references exists', array_diff($used, $onDisk) === [], implode(', ', array_diff($used, $onDisk)));
check('only the artwork the scenes use is shipped (no unused pack files)', array_diff($onDisk, $used) === [], implode(', ', array_diff($onDisk, $used)));
check('no PNG fallback is shipped or loaded', !preg_grep('~\.png$~', $onDisk) && !preg_match('~/assets/img/anim/[^"\')]+\.png~', $tpl . $css));
$budget = ['-mobile.webp' => 50 * 1024, '.webp' => 180 * 1024, '.svg' => 12 * 1024];
$sizes = [];
$over = [];
foreach ($onDisk as $f) {
    $b = (int) filesize($dir . '/' . $f);
    $sizes[$f] = $b;
    foreach ($budget as $suffix => $max) {
        if (str_ends_with($f, $suffix)) { if ($b > $max) { $over[] = "{$f} " . round($b / 1024) . ' KB'; } break; }
    }
}
check('each artwork file is inside its budget (mobile ≤ 50 KB, desktop ≤ 180 KB, SVG ≤ 12 KB)', $over === [], implode(', ', $over));
$mobileTotal = 0;
foreach (['shg-highway-bg-mobile.webp', 'shg-distant-scenery-mobile.webp', 'shg-bus-main-mobile.webp', 'shg-bus-shadow.svg', 'shg-road-foreground.svg', 'shg-map-bg.svg', 'shg-route-bus-marker.webp', 'shg-phone-frame.svg'] as $f) {
    $mobileTotal += $sizes[$f] ?? 0;
}
check('a phone downloads at most 150 KB of scene artwork', $mobileTotal <= 150 * 1024, round($mobileTotal / 1024) . ' KB');
preg_match_all('~<img\b[^>]*>~', $all, $imgs);
$badImg = array_filter($imgs[0], static fn($t) => !str_contains($t, 'loading="lazy"') || !str_contains($t, 'decoding="async"') || !preg_match('~\bwidth="\d+"~', $t) || !preg_match('~\bheight="\d+"~', $t) || !str_contains($t, 'alt="'));
check('every scene image is lazy, async, sized and has an alt', count($imgs[0]) > 0 && $badImg === [], count($imgs[0]) . ' images');
preg_match_all('~<picture><source media="\(max-width: 759px\)" data-srcset="/assets/img/anim/([a-z0-9-]+)\.webp~', $sections['animJourney'], $pics);
check('phones get the 800 px mobile renders of landscape, scenery and coach', $pics[1] === ['shg-highway-bg-mobile', 'shg-distant-scenery-mobile', 'shg-bus-main-mobile'], implode(', ', $pics[1]));
check('the coach artwork waits for the controller (no src until the scene is near)',
    substr_count($sections['animJourney'], 'data-srcset="') === 3 && substr_count($sections['animJourney'], ' data-src="') === 3
    && !preg_match('~<img class="sa-(?:land|scenery)" src=~', $sections['animJourney'])
    && str_contains($js, "i.src = i.getAttribute('data-src')") && str_contains($js, "s.srcset = s.getAttribute('data-srcset')"));
check('map artwork is requested only near the screen (data-href, set by the controller)',
    (bool) preg_match('~<image class="sa-map-bg" data-href=~', $sections['animRoute']) && !preg_match('~<image[^>]*\shref=~', $sections['animRoute'])
    && str_contains($js, "setAttribute('href', im.getAttribute('data-href'))"));
check('no GIF, video or third-party animation library', !preg_match('~\.gif|<video|lottie|gsap|anime\.min|three\.js~i', $all . $css . $js));

/* ---- 3. honesty -------------------------------------------------------- */
check('the coach is never mirrored (no negative scale / 180° flip)', !preg_match('~scaleX\(\s*-|scale\(\s*-|rotateY\(\s*180~', $css . $js));
check('left and right coaches are separate renders swapped by name',
    is_file($dir . '/shg-bus-left.webp') && is_file($dir . '/shg-bus-main.webp') && str_contains($js, "replace(/shg-bus-main/g, 'shg-bus-left')"));
// 8 Oct 2026: the banner's three amenity chips moved into the one facilities strip (#homeFeat, 8 items)
// - AC, mobile charging and first aid are still there, nothing invented on the banner itself.
preg_match_all('~data-i18n="(animAm[A-Za-z]+)"~', $sections['animJourney'], $am);
$featHtml = preg_match('~<section[^>]*id="homeFeat"[^>]*>(.*?)</section>~s', $tpl, $fh) ? $fh[1] : '';
check('no amenity claims on the banner; AC, mobile charging and first aid live in the facilities strip',
    $am[1] === [] && str_contains($featHtml, 'data-i18n="hfAc"') && str_contains($featHtml, 'data-i18n="hfCharge"') && str_contains($featHtml, 'data-i18n="hfAid"'), implode(', ', $am[1]));
check('no extra amenity claims (toilet, Wi-Fi, meals, fares, seats left)', !preg_match('~toilet|wi-?fi|meal|dining|₹|seats? left~i', $all));
check('the map says it is an illustrative corridor, not a road map or GPS',
    str_contains($sections['animRoute'], 'data-i18n="animMNote"') && (bool) preg_match("~animMNote: \"Illustrative corridor — not a road map, distance or live GPS position\.\"~", $i18n));
$rup = preg_match('~<text class="sa-lbl" x="([\d.]+)" y="([\d.]+)"[^>]*data-i18n="animMRup"~', $sections['animRoute'], $rm) ? (float) $rm[2] : 0.0;
check('the Rupaidiha label sits below the border point, on the India side', $rup > 228.12, (string) $rup);
check('the corridor is the pack\'s illustrative path (Surat · Vadodara · Ahmedabad → Rupaidiha)',
    substr_count($sections['animRoute'], 'M229.61,374.43 L238.71,350.55 L222.86,335.53 C277.86,260.53 377.99,238.12 457.99,228.12') === 2);
foreach (['animB4Badge' => 'Demo ticket', 'animB4F' => 'Preview only · no booking created', 'animBWaF' => 'Sample preview · not sent', 'animBDemo' => 'Sample animation only'] as $k => $text) {
    check("the booking scene says \"{$text}\"", str_contains($sections['animBooking'], 'data-i18n="' . $k . '"') && str_contains($i18n, $k . ': "' . $text));
}
check('the sample shows sleeper berths, not chairs', substr_count($sections['animBooking'], 'class="sa-berth"') === 8 && !preg_match('~chair~i', $sections['animBooking']));
check('the sample uses the passenger-facing seat labels (B6 = storage L12), never storage ids',
    str_contains($sections['animBooking'], '<span>B6</span>') && !preg_match('~>L\d+<|: L\d+~', $sections['animBooking']));
check('the WhatsApp card shows how a message looks and promises no delivery',
    str_contains($i18n, 'animBWaS: "This is how a ticket message looks."') && !preg_match('~animBWa[TS]: "[^"]*(?:ready|sent to|delivered)~i', $i18n));
check('the scenery layer is not squeezed by the site-wide img{max-width:100%}', str_contains($css, '.shg-animation .sa-journey img { max-width: none; }'));
check('the phone mock-up does not depend on container-query units', !str_contains($css, 'cqw') && str_contains($css, '--u: calc(var(--phone-w) * 0.881 / 360)'));
check('the ticket appears only after the payment check in the timeline',
    (bool) preg_match("~show\(st, q\('\.sa-pay-ok'\), 0\.55,~", $js) && (bool) preg_match("~\{ opacity: 0, offset: 0\.64 \}, \{ opacity: 1, offset: 0\.68 \}~", $js));
check('the controller makes no network or storage call',
    !preg_match('~\bfetch\s*\(|XMLHttpRequest|sendBeacon|WebSocket|localStorage|sessionStorage|document\.cookie|import\s*\(~', $js));
check('the scenes carry no form, link or real booking control', !preg_match('~<form|<a\s|<input|<select|data-bkmode|searchBtn~', $all));

/* ---- 4. motion --------------------------------------------------------- */
check('plays only on screen (IntersectionObserver) and pauses in a background tab',
    str_contains($js, 'new IntersectionObserver') && str_contains($js, "addEventListener('visibilitychange', sync)") && str_contains($js, 'document.hidden'));
check('reduced motion stops everything and shows the final frame',
    str_contains($js, "matchMedia('(prefers-reduced-motion: reduce)')") && str_contains($js, 'a.currentTime = st.finalMs')
    && (bool) preg_match('~@media \(prefers-reduced-motion: reduce\)~', $css));
preg_match_all('~@keyframes\s+[\w-]+\s*\{(.*?)\}\s*\}?~s', $css, $kf);
$props = [];
preg_match_all('~([a-z-]+)\s*:~', implode(' ', $kf[1]), $pp);
foreach ($pp[1] as $p) { $props[$p] = true; }
check('CSS keyframes move only transform / opacity', array_diff(array_keys($props), ['transform', 'opacity']) === [], implode(', ', array_keys($props)));
preg_match_all("~\{ ?(transform|opacity|offset|easing):~", $js, $wp);
preg_match_all("~\{ ([a-zA-Z]+): [^}]*offset~", $js, $wk);
$waapi = array_unique(array_merge($wp[1], $wk[1]));
check('Web Animations keyframes use only transform / opacity', array_diff($waapi, ['transform', 'opacity', 'offset', 'easing']) === [], implode(', ', $waapi));
check('CSS layers are paused unless the controller says run', str_contains($css, '.shg-animation .sa-scene:not(.is-running) * { animation-play-state: paused !important; }'));
check('the controller can release every observer, listener and animation', str_contains($js, 'destroy: function') && str_contains($js, 'disconnect()') && str_contains($js, 'a.cancel()'));
$bad = [];
foreach (preg_split('~\R~', preg_replace('~/\*.*?\*/~s', '', $css)) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '}' || $line[0] === '@' || !str_contains($line, '{')) { continue; }
    if (preg_match('~^(?:\d+%|to|from)[\s,{]~', $line)) { continue; }   // keyframe steps
    $sel = trim(substr($line, 0, (int) strpos($line, '{')));
    foreach (explode(',', $sel) as $s) {
        $s = trim($s);
        if ($s === '' || preg_match('~^\d+%$~', $s)) { continue; }
        if (!preg_match('~^(?:\.shg-animation\b|#view-home > #anim(?:Journey|Route|Booking)\b|html:not\(\[lang="en"\]\) \.shg-animation\b)~', $s)) { $bad[] = $s; }
    }
}
check('every rule in anim.css is scoped to the scenes', $bad === [], implode(' | ', array_slice($bad, 0, 4)));
check('the stylesheet and controller are linked and pre-cached with the release stamp',
    (bool) preg_match('~href="/assets/css/anim\.css\?v=(\w+)"~', $tpl, $c1) && (bool) preg_match('~src="/assets/js/20-anim\.js\?v=(\w+)"~', $tpl, $c2)
    && (bool) preg_match("~var ASSET_VER = '(\w+)'~", $sw, $sv) && $c1[1] === $sv[1] && $c2[1] === $sv[1]
    && str_contains($sw, "'/assets/css/anim.css?v=' + ASSET_VER") && str_contains($sw, "'/assets/js/20-anim.js?v=' + ASSET_VER"));

/* ---- 5. language ------------------------------------------------------- */
preg_match_all('~data-i18n(?:-title)?="([A-Za-z0-9]+)"~', $all, $keys);
$keys = array_values(array_unique(array_merge($keys[1], ['animPlay'])));
$blocks = [];
foreach (['en' => "\nen: {", 'hi' => "\nhi: {", 'ne' => "\nne: {"] as $lang => $open) {
    $a = strpos($i18n, $open);
    $b = $a === false ? false : strpos($i18n, "\n}", $a);     // ne is the last block: its "}" has no comma
    $blocks[$lang] = ($a !== false && $b !== false) ? substr($i18n, $a, $b - $a) : '';
}
$gu = preg_match('~I18N\.gu = Object\.assign\(\{\}, I18N\.en, \{(.*?)\}\);~s', $i18n, $gm) ? $gm[1] : '';
foreach (['en', 'hi', 'ne'] as $lang) {
    $missing = array_values(array_filter($keys, static fn($k) => !preg_match('~[\s,{]' . preg_quote($k, '~') . '\s*:~', $blocks[$lang])));
    check("every scene label exists in {$lang}", $blocks[$lang] !== '' && $missing === [], count($keys) . ' keys' . ($missing ? ' — missing ' . implode(', ', $missing) : ''));
}
$missingGu = array_values(array_filter($keys, static fn($k) => !str_contains($gu, '"' . $k . '":')));
check('every scene label exists in the Gujarati override', $missingGu === [], $missingGu ? 'missing ' . implode(', ', $missingGu) : count($keys) . ' keys');

echo "\n  ----------------------------------------\n  PASSED: {$PASS}   FAILED: {$FAIL}\n\n";
exit($FAIL === 0 ? 0 : 1);
