<?php
/**
 * depth-display-test.php — the pop-out depth layer and the AMOLED display
 * (4 Oct 2026, owner's AMOLED Pop-out brief).
 *
 * assets/css/depth.css gives every theme a raised, lit-from-the-top-left
 * look (rim, stacked lower edge, cast shadow; keys that press into their
 * edge; inset inputs) and adds AMOLED as a third display next to light and
 * dark. This suite pins what must not quietly regress:
 *
 *   1. loading — depth.css loads LAST in the customer app and the company
 *      app, is precached by the service worker, and carries the asset stamp;
 *   2. theme states — light / dark / AMOLED on both shells, AMOLED is
 *      data-theme="dark" + data-display="amoled" (so every dark rule still
 *      applies), the value is stored raw (the old store.set wrapped it in
 *      quotes and a saved dark theme came back light), phones get a Display
 *      control, and nothing turns AMOLED on by itself;
 *   3. readability — every declared AMOLED text/surface pair is >= 4.5:1;
 *      text is never shadowed, rotated or blurred by the layer;
 *   4. motion — static depth survives prefers-reduced-motion, movement does
 *      not; forced colours and print fall back to flat output;
 *   5. feel — sound and vibration are the passenger's choice and default
 *      OFF; no raw navigator.vibrate outside the one engine.
 *
 *   php tests/depth-display-test.php
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

/** WCAG relative luminance contrast of two #RRGGBB colours. */
function contrast(string $a, string $b): float
{
    $lum = static function (string $hex): float {
        $hex = ltrim($hex, '#');
        $c = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $c = array_map(static function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; }, $c);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $l1 = $lum($a); $l2 = $lum($b);
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

echo "\n=== Depth layer + AMOLED display ===\n\n";

$css   = (string) file_get_contents($root . '/assets/css/depth.css');
$tpl   = (string) file_get_contents($root . '/app.template.html');
$guard = (string) file_get_contents($root . '/admin/_guard.php');
$login = (string) file_get_contents($root . '/admin/login.php');
$sw    = (string) file_get_contents($root . '/sw.js');
$acc   = (string) file_get_contents($root . '/assets/js/03-accounts.js');
$feel  = (string) file_get_contents($root . '/assets/js/19-premium.js');
$pwa   = (string) file_get_contents($root . '/assets/js/17-pwa.js');
preg_match("~var ASSET_VER = '([^']+)'~", $sw, $m);
$ver = $m[1] ?? '';

echo "-- 1. loading --\n";
check('depth.css exists and is not empty', strlen($css) > 5000, strlen($css) . ' bytes');
preg_match_all('~<link rel="stylesheet" href="/assets/css/([a-z-]+)\.css\?v=([^"]+)"~', $tpl, $links);
check('customer app: depth.css is followed only by the reviewed polish overrides', array_slice($links[1], -2) === ['depth', 'polish'], implode(' > ', $links[1]));
check('customer app: depth.css carries the asset stamp', in_array('depth', $links[1], true) && $links[2][array_search('depth', $links[1], true)] === $ver, $ver);
$posAdmin = strpos($guard, '/assets/css/admin.css?v=');
$posDepth = strpos($guard, '/assets/css/depth.css?v=');
check('company app: depth.css loads after admin.css', $posAdmin !== false && $posDepth !== false && $posDepth > $posAdmin);
check('company app: <body class="shg-adm"> scopes the panel rules', str_contains($guard, '<body class="shg-adm">'));
check('service worker precaches depth.css', str_contains($sw, "'/assets/css/depth.css?v=' + ASSET_VER"));
check('depth.css braces balance', substr_count($css, '{') === substr_count($css, '}'), substr_count($css, '{') . ' blocks');
// A comment that ends in the middle of a selector list ("... */ .btn-orange,")
// makes the browser drop the WHOLE rule; every line holding a comment end
// must also hold its start, or start the comment itself.
$broken = array_values(array_filter(preg_split('~\R~', $css), static fn($l) => str_contains($l, '*/') && !str_contains($l, '/*') && preg_match('~\*/\s*[.:#a-z\[]~i', $l)));
check('no comment fragment inside a selector list', $broken === [], $broken ? substr($broken[0], 0, 80) : '');
check('selectors are plain lists (no :is() in the hot path)', !str_contains($css, ':is('));

echo "\n-- 2. theme states --\n";
check('pre-paint accepts light / dark / amoled', str_contains($tpl, "t !== 'light' && t !== 'dark' && t !== 'amoled'"));
check('pre-paint: AMOLED = data-theme dark + data-display amoled', str_contains($tpl, "d.setAttribute('data-theme', t === 'light' ? 'light' : 'dark')") && str_contains($tpl, "if (t === 'amoled') d.setAttribute('data-display', 'amoled')"));
check('pre-paint strips a JSON-quoted saved value', str_contains($tpl, "localStorage.getItem('shg:theme') || '').replace(/\"/g, '')"));
check('first frame is black for AMOLED', str_contains($tpl, ':root[data-display="amoled"],:root[data-display="amoled"] body{background:#000}'));
check('applyTheme stores the raw value (no store.set JSON wrap)', !preg_match("~store\\.set\\('shg:theme'~", $acc) && str_contains($acc, "localStorage.setItem('shg:theme', mode)"));
check('topbar button cycles light → dark → AMOLED', str_contains($acc, "cur === 'light' ? 'dark' : (cur === 'dark' ? 'amoled' : 'light')"));
check('nothing saved = follow the system light/dark, never AMOLED', str_contains($acc, "prefers-color-scheme: dark)').matches ? 'dark' : 'light';") && str_contains($acc, 'applyTheme(mode, false)'));
preg_match_all('~data-theme-set="(light|dark|amoled)"~', $tpl, $opts);
check('phone menu has the Display choice (3 radios)', $opts[1] === ['light', 'dark', 'amoled'] && str_contains($tpl, 'role="radiogroup"'));
check('company app: three displays, saved as shg_admin_theme', str_contains($guard, 't!==\"amoled\"') || str_contains($guard, 't!=="amoled"'));
check('company app: AMOLED keeps data-theme="dark" (chart colour checks still work)', str_contains($guard, 'd.setAttribute("data-theme",t==="light"?"light":"dark")'));
check('staff login follows the saved display', str_contains($login, "localStorage.getItem('shg_admin_theme')") && str_contains($login, ':root[data-display="amoled"] body{background:#000 !important}'));

echo "\n-- 3. readability --\n";
$tok = [];
if (preg_match('~:root\[data-display="amoled"\] \{(.*?)\n\}~s', $css, $am)) {
    preg_match_all('~--([a-z0-9-]+):\s*(#[0-9A-Fa-f]{6})\s*;~', $am[1], $tm, PREG_SET_ORDER);
    foreach ($tm as $t) { $tok[$t[1]] = $t[2]; }
}
check('AMOLED ground is pure black', ($tok['page'] ?? '') === '#000000');
check('AMOLED card face is opaque #101822', strtoupper($tok['surface'] ?? '') === '#101822');
$pairs = [
    ['ink', 'surface'], ['ink', 'page'], ['ink-2', 'surface'], ['muted', 'surface'], ['muted', 'page'],
    ['royal', 'surface'], ['royal', 'page'], ['success', 'success-soft'], ['warning', 'warning-soft'],
    ['danger', 'danger-soft'], ['seat-booked-ink', 'seat-booked'], ['seat-ladies-ink', 'seat-ladies'],
    ['seat-gents-ink', 'seat-gents'], ['on-navy', 'navy'], ['ink', 'surface-sunken'],
];
foreach ($pairs as [$fg, $bg]) {
    $ok = isset($tok[$fg], $tok[$bg]);
    $r = $ok ? contrast($tok[$fg], $tok[$bg]) : 0.0;
    check("AMOLED {$fg} on {$bg} ≥ 4.5:1", $ok && $r >= 4.5, $ok ? sprintf('%.2f:1', $r) : 'token missing');
}
$r = contrast('#FFFFFF', '#28579C');
check('AMOLED primary key: white on #28579C ≥ 4.5:1', $r >= 4.5, sprintf('%.2f:1', $r));
$r = contrast('#FFFFFF', '#0054A8');
check('light primary key: white on #0054A8 ≥ 4.5:1', $r >= 4.5, sprintf('%.2f:1', $r));
check('the layer never shadows, rotates or blurs text', !preg_match('~text-shadow:\s*+(?!none)~', $css) && !preg_match('~rotate[XYZ]?\(~', $css) && !preg_match('~filter:\s*blur~', $css));
check('the map canvas is never tilted or filtered', !preg_match('~maplibregl-canvas[^{]*\{[^}]*(transform|filter)~', $css));

echo "\n-- 4. motion, forced colours, print --\n";
check('pressed keys travel into their edge', str_contains($css, 'transform: translateY(var(--dp-press)) !important') && str_contains($css, '--dp-press: 3px'));
$rm = preg_match('~@media \(prefers-reduced-motion: reduce\) \{(.*?)\n\}~s', $css, $rmm) ? $rmm[1] : '';
check('reduced motion: lift and press distances go to 0', str_contains($rm, '--dp-lift: 0px') && str_contains($rm, '--dp-press: 0px'));
check('reduced motion keeps the static depth (no box-shadow reset)', $rm !== '' && !str_contains($rm, 'box-shadow: none'));
check('no looping animation in the layer', !preg_match('~animation\s*:\s*[a-z-]+\s[^;]*infinite~i', $css) && !str_contains($css, '@keyframes'));
check('forced colours: flat system colours', (bool) preg_match('~@media \(forced-colors: active\) \{\s*\* \{ box-shadow: none !important; background-image: none !important;~', $css));
check('print: flat black on white', (bool) preg_match('~@media print \{.*?--page: #FFFFFF;.*?\* \{ box-shadow: none !important;~s', $css));

echo "\n-- 5. sound and vibration --\n";
// 5 Oct 2026: owner asked for the app "with sound" — ON until switched off (a saved '0' wins).
check('SHGFeel: sound and vibration default ON (only a saved "0" is off)', str_contains($feel, "return v === null ? true : v === '1';"));
check('SHGFeel stays the one engine (setSound / setHaptics / buzz)', str_contains($feel, 'window.SHGFeel = Feel;') && str_contains($feel, 'setHaptics:'));
check('contact sheet buzz goes through the haptics switch', !str_contains($pwa, 'navigator.vibrate(') && str_contains($pwa, "window.SHGFeel.buzz('tap')"));
check('vibration respects reduced motion', str_contains($feel, 'if (reducedMotion()) return;'));

echo "\n  ----------------------------------------\n  PASSED: {$PASS}   FAILED: {$FAIL}\n\n";
exit($FAIL === 0 ? 0 : 1);
