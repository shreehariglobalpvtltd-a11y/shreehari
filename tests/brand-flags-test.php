<?php
/**
 * brand-flags-test.php — the emblem-only brand + drawn India / Nepal flags (9 Oct 2026).
 *
 * Owner: "logo haru ma name ni join xa, background hataidinu, only logo matra
 * rakhdinu" + "har ek document haru, thau haru, Nepal India ko flag haru".
 *
 *   A. Icons / images are the emblem only: transparent where they may be,
 *      no baked name rows, no white tile; the opaque iOS / maskable icons
 *      keep the mark inside the safe zone; favicon.ico, badge, og card.
 *   B. manifest.webmanifest: parses, every icon exists, any + maskable.
 *   C. Brand (includes/brand.php): markFile / markUrl, flagSvg / flagUse /
 *      flagSprite valid XML with explicit fills and the right geometry,
 *      flagPng rasters, and the same drawing in 23-flags.js + flag-*.svg.
 *   D. assets/js/23-flags.js: the contract (shgFlag, sprite symbols, the
 *      emoji swapper's skip list, injected CSS) and node --check.
 *   E. No white tile / old logo on the surfaces BRAND owns; admin loads
 *      the flags; the push badge is the monochrome silhouette.
 *   F. [HTTP, skipped when no dev server] the pages render the emblem.
 *
 *   php -c .claude/php-dev.ini tests/brand-flags-test.php
 *   (SHG_TEST_BASE=http://localhost:8991 for section F)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/brand.php';

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function finish(): void {
    global $pass, $fail;
    echo "\n----------------------------------------\nPASSED: $pass   FAILED: $fail\n";
    exit($fail ? 1 : 0);
}

$root = dirname(__DIR__);
$src  = static fn(string $rel): string => is_file($root . '/' . $rel) ? (string) file_get_contents($root . '/' . $rel) : '';

/** rgba of one pixel (alpha 0 = opaque .. 127 = transparent). */
function px($im, int $x, int $y): array {
    $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
    return [$c['red'], $c['green'], $c['blue'], $c['alpha']];
}
/** Share (0..1) of pixels in rows [$y0,$y1) that are not (nearly) transparent. */
function opaqueShare($im, int $y0, int $y1): float {
    $w = imagesx($im); $n = 0; $t = 0;
    for ($y = $y0; $y < $y1; $y++) {
        for ($x = 0; $x < $w; $x++) { $t++; if (px($im, $x, $y)[3] < 100) { $n++; } }
    }
    return $t ? $n / $t : 0.0;
}
/** Rows (as a share of the height) that carry artwork on an opaque icon: a row
 *  counts when >1% of its pixels differ clearly from that row's left edge. */
function contentSpan($im): array {
    $w = imagesx($im); $h = imagesy($im); $min = $h; $max = -1;
    for ($y = 0; $y < $h; $y++) {
        $c0 = px($im, 1, $y); $cnt = 0;
        for ($x = 0; $x < $w; $x++) {
            $c = px($im, $x, $y);
            if (abs($c[0] - $c0[0]) + abs($c[1] - $c0[1]) + abs($c[2] - $c0[2]) > 90) { $cnt++; }
        }
        if ($cnt > $w * 0.01) { $min = min($min, $y); $max = $y; }
    }
    return [$min / $h, $max / $h];
}
function xmlOk(string $xml): ?DOMDocument {
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $d->loadXML($xml);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    return $ok ? $d : null;
}

echo "\n=== Brand: emblem-only logo, icons, SVG flags ===\n";

/* ------------------------------------------------------------------ */
echo "\n-- A. icons and images --\n";
if (!function_exists('imagecreatefrompng')) {
    check('GD available (run with -c .claude/php-dev.ini)', false);
    finish();
}

foreach (['assets/img/icon-512.png' => 512, 'assets/img/icon-192.png' => 192] as $rel => $sz) {
    $im = @imagecreatefrompng($root . '/' . $rel);
    if (!$im) { check("$rel readable", false); continue; }
    imagesavealpha($im, true);
    check("$rel is {$sz}x{$sz}", imagesx($im) === $sz && imagesy($im) === $sz, imagesx($im) . 'x' . imagesy($im));
    check("$rel corners transparent (no white tile)", px($im, 0, 0)[3] === 127 && px($im, $sz - 1, $sz - 1)[3] === 127);
    $band = opaqueShare($im, (int) round($sz * 0.80), $sz);
    check("$rel bottom band empty (no baked name rows)", $band < 0.005, sprintf('%.2f%% opaque in rows 80-100%%', $band * 100));
    check("$rel carries the emblem", opaqueShare($im, (int) round($sz * 0.35), (int) round($sz * 0.60)) > 0.25);
}
foreach (['assets/img/icon-maskable-512.png' => 512, 'assets/img/apple-touch-icon.png' => 180] as $rel => $sz) {
    $im = @imagecreatefrompng($root . '/' . $rel);
    if (!$im) { check("$rel readable", false); continue; }
    $c = px($im, 0, 0);
    check("$rel is {$sz}x{$sz} and opaque (iOS paints transparency black)", imagesx($im) === $sz && $c[3] === 0);
    check("$rel background is not a white tile", !($c[0] > 235 && $c[1] > 235 && $c[2] > 235), "corner rgb($c[0],$c[1],$c[2])");
    [$a, $b] = contentSpan($im);
    check("$rel artwork is the emblem only, inside the safe zone", ($b - $a) < 0.42 && $a > 0.12 && $b < 0.80, sprintf('rows %.2f-%.2f', $a, $b));
}
$fav = @imagecreatefrompng($root . '/assets/img/favicon-32.png');
check('favicon-32.png 32x32 with transparent corners', $fav && imagesx($fav) === 32 && px($fav, 0, 0)[3] === 127);

$ico = $src('favicon.ico');
$icoN = strlen($ico) >= 6 ? unpack('vres/vtype/vcount', substr($ico, 0, 6)) : [];
$sizes = [];
for ($i = 0; $i < (int) ($icoN['count'] ?? 0); $i++) {
    $e = unpack('Cw/Ch', substr($ico, 6 + 16 * $i, 2));
    $sizes[] = ($e['w'] ?: 256);
}
check('/favicon.ico is an icon with 16 + 32 px images', ($icoN['type'] ?? 0) === 1 && in_array(16, $sizes, true) && in_array(32, $sizes, true), implode('/', $sizes));
// the 16 px image must be transparent at the corner (no white tile)
$icoTransparent = false;
for ($i = 0; $i < count($sizes); $i++) {
    $e = unpack('Cw/Ch/Cc/Cr/vp/vb/Vlen/Voff', substr($ico, 6 + 16 * $i, 16));
    $blob = substr($ico, $e['off'], $e['len']);
    if (str_starts_with($blob, "\x89PNG") && ($im = @imagecreatefromstring($blob))) {
        imagesavealpha($im, true);
        if (px($im, 0, 0)[3] === 127) { $icoTransparent = true; }
    }
}
check('/favicon.ico images are transparent (PNG entries)', $icoTransparent);

$badge = @imagecreatefrompng($root . '/assets/img/badge-96.png');
$mono = $badge !== false;
if ($badge) {
    imagesavealpha($badge, true);
    for ($y = 0; $y < 96 && $mono; $y += 2) {
        for ($x = 0; $x < 96; $x += 2) {
            $c = px($badge, $x, $y);
            if ($c[3] < 64 && ($c[0] < 200 || $c[1] < 200 || $c[2] < 200)) { $mono = false; break; }
        }
    }
}
check('badge-96.png: 96x96 white silhouette on transparent (Android status bar)', $badge && imagesx($badge) === 96 && px($badge, 0, 0)[3] === 127 && $mono && opaqueShare($badge, 0, 96) > 0.1);

foreach (['assets/img/logo-mark.png', 'assets/img/logo-nav.png'] as $rel) {
    $im = @imagecreatefrompng($root . '/' . $rel);
    if ($im) { imagesavealpha($im, true); }
    check("$rel is the transparent emblem (no tile)", $im && px($im, 0, 0)[3] === 127 && px($im, imagesx($im) - 1, imagesy($im) - 1)[3] === 127 && imagesx($im) > imagesy($im));
}
check('logo-nav.png stays under 20 KB (home hero pin)', (int) @filesize($root . '/assets/img/logo-nav.png') < 20000);

$og = @imagecreatefrompng($root . '/assets/img/og-shg.png');
$white = 0; $tot = 0;
if ($og) {
    for ($y = 60; $y < 340; $y += 2) {
        for ($x = 40; $x < 520; $x += 2) {
            $c = px($og, $x, $y); $tot++;
            if ($c[0] > 240 && $c[1] > 240 && $c[2] > 240) { $white++; }
        }
    }
}
check('og-shg.png is 1200x630', $og && imagesx($og) === 1200 && imagesy($og) === 630);
check('og-shg.png: the emblem sits on navy, no white logo tile', $tot > 0 && $white / $tot < 0.12, sprintf('%.1f%% near-white in the logo area', $tot ? 100 * $white / $tot : 0));
check('og-shg.png under 400 KB', (int) @filesize($root . '/assets/img/og-shg.png') < 400000);

$svgIcon = $src('icon-shg.svg');
$svgDoc = xmlOk($svgIcon);
check('icon-shg.svg parses as XML', $svgDoc !== null);
check('icon-shg.svg is the real emblem (embedded transparent PNG), not the old bus + Om mark', str_contains($svgIcon, 'data:image/png;base64,') && !preg_match('/\x{0950}|Om\b/u', $svgIcon));
check('icon-shg.svg stays small (favicon)', strlen($svgIcon) < 40000, strlen($svgIcon) . ' bytes');
check('mask-icon.svg (monochrome silhouette) parses', xmlOk($src('assets/img/mask-icon.svg')) !== null);

/* ------------------------------------------------------------------ */
echo "\n-- B. manifest.webmanifest --\n";
$man = json_decode($src('manifest.webmanifest'), true);
check('manifest parses', is_array($man));
$icons = is_array($man['icons'] ?? null) ? $man['icons'] : [];
$missing = []; $purposes = [];
foreach ($icons as $ic) {
    $p = parse_url((string) ($ic['src'] ?? ''), PHP_URL_PATH);
    if (!is_string($p) || !is_file($root . $p)) { $missing[] = (string) ($ic['src'] ?? '?'); }
    $purposes[(string) ($ic['purpose'] ?? 'any') . ':' . (string) ($ic['sizes'] ?? '')] = true;
}
check('every manifest icon exists on disk', $icons !== [] && $missing === [], $missing === [] ? count($icons) . ' icons' : implode(', ', $missing));
check('manifest has any 192 + any 512 + maskable 512', isset($purposes['any:192x192'], $purposes['any:512x512'], $purposes['maskable:512x512']));
check('manifest does not offer a low-res raster SVG as "any" size', !isset($purposes['any:any']));
check('manifest theme + background colours set', preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($man['theme_color'] ?? '')) === 1 && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($man['background_color'] ?? '')) === 1);

/* ------------------------------------------------------------------ */
echo "\n-- C. Brand helper --\n";
$mf = Brand::markFile();
check('Brand::markFile() returns a readable PNG', is_string($mf) && is_file($mf) && strtolower(pathinfo($mf, PATHINFO_EXTENSION)) === 'png', (string) $mf);
if (is_string($mf) && ($im = @imagecreatefrompng($mf))) {
    imagesavealpha($im, true);
    check('Brand::markFile() is transparent (draws on navy bands without a chip)', px($im, 0, 0)[3] === 127);
}
check('Brand::markFile() ignores remote URLs / bad types', Brand::markFile('https://evil.example/x.png') === $mf && Brand::markFile('assets/img/flag-in.svg') === $mf);
$mu = Brand::markUrl(34);
check('Brand::markUrl() is a root-relative, cache-stamped image URL', (bool) preg_match('#^/[A-Za-z0-9_./-]+\.(png|jpe?g)\?m=\d+$#', $mu), $mu);
check('Brand::iconLinks() lists favicon + svg + apple-touch', substr_count(Brand::iconLinks(), '<link rel=') === 3 && str_contains(Brand::iconLinks(), 'apple-touch-icon'));

check("Brand::cc maps names and codes", Brand::cc('Nepal') === 'NP' && Brand::cc('ind') === 'IN' && Brand::cc('XX') === '');
check("Brand::flagSvg('XX') is empty", Brand::flagSvg('XX') === '' && Brand::flagUse('') === '');

/** Every drawn shape must carry an explicit fill (itself or its <g>). */
function fillsExplicit(DOMDocument $d): bool {
    foreach (['path', 'circle', 'rect', 'polygon', 'ellipse'] as $tag) {
        foreach ($d->getElementsByTagName($tag) as $el) {
            $n = $el; $ok = false;
            while ($n instanceof DOMElement && $n->tagName !== 'svg' && $n->tagName !== 'symbol') {
                if ($n->hasAttribute('fill')) { $ok = true; break; }
                $n = $n->parentNode;
            }
            if (!$ok) { return false; }
        }
    }
    return true;
}
$want = ['IN' => ['#FF9933', '#FFFFFF', '#138808', '#000080'], 'NP' => ['#DC143C', '#003893', '#fff']];
foreach (['IN', 'NP'] as $cc) {
    $svg = Brand::flagSvg($cc, 20);
    $d = xmlOk($svg);
    check("flagSvg('$cc') is valid SVG XML", $d !== null && $d->documentElement->tagName === 'svg');
    if ($d === null) { continue; }
    $root2 = $d->documentElement;
    check("flagSvg('$cc') has class flg + flg-" . strtolower($cc) . ' and an accessible name', str_contains($root2->getAttribute('class'), 'flg flg-' . strtolower($cc)) && $root2->getAttribute('role') === 'img' && $root2->getAttribute('aria-label') !== '');
    check("flagSvg('$cc') every shape has an explicit fill (themes never repaint it)", fillsExplicit($d));
    $miss = array_filter($want[$cc], static fn(string $c): bool => stripos($svg, '"' . $c . '"') === false);
    check("flagSvg('$cc') official colours", $miss === [], $miss === [] ? implode(' ', $want[$cc]) : 'missing ' . implode(' ', $miss));
    $g = Brand::flagGeometry($cc);
    $w = (float) $root2->getAttribute('width'); $h = (float) $root2->getAttribute('height');
    check("flagSvg('$cc') keeps the flag ratio", $h === 20.0 && abs($w - 20 * $g['w'] / $g['h']) < 0.02, "{$w}x{$h}");
    $dec = xmlOk(Brand::flagSvg($cc, 16, 'en', true));
    check("flagSvg('$cc', decorative) is aria-hidden", $dec !== null && $dec->documentElement->getAttribute('aria-hidden') === 'true');
    check("flagLabel('$cc','ne') is Nepali", Brand::flagLabel($cc, 'ne') === ($cc === 'IN' ? 'भारत' : 'नेपाल'));
}
// geometry details: 24 Ashoka Chakra spokes (12 diameters) / 12-ray sun / Nepal ratio
$inBody = Brand::flagGeometry('IN')['body'];
check('India: Ashoka Chakra has 24 spokes (12 diameters)', preg_match('/stroke-width="\.2" d="([^"]+)"/', $inBody, $m) === 1 && substr_count($m[1], 'M') === 12);
check('India: 3 : 2', Brand::flagGeometry('IN')['w'] / Brand::flagGeometry('IN')['h'] === 1.5);
$npBody = Brand::flagGeometry('NP')['body'];
check('Nepal: the sun has 12 rays (24 vertices)', preg_match('/<path d="(M17\.108[^"]+)"/', $npBody, $m) === 1 && substr_count($m[1], 'L') + 1 === 24);
check('Nepal: the moon has 8 rays', preg_match('/<path d="(M20\.561[^"]+)"/', $npBody, $m) === 1 && substr_count($m[1], 'Z') === 8);
$npr = Brand::flagGeometry('NP')['h'] / Brand::flagGeometry('NP')['w'];
check('Nepal: height : width with the border ≈ 1.219', abs($npr - 1.219) < 0.004, sprintf('%.4f', $npr));

$use = xmlOk(Brand::flagUse('NP', 18));
check('flagUse() draws from the sprite (#flag-np)', $use !== null && str_contains(Brand::flagUse('NP', 18), '<use href="#flag-np"/>'));
$sp = xmlOk(Brand::flagSprite());
$ids = [];
if ($sp) { foreach ($sp->getElementsByTagName('symbol') as $s) { $ids[] = $s->getAttribute('id'); } }
check('flagSprite() holds <symbol id="flag-in"> + <symbol id="flag-np">', $ids === ['flag-in', 'flag-np']);
check('flagSprite() symbols have explicit fills', $sp !== null && fillsExplicit($sp));
check('flagFor() resolves a desk / country like CounterDesk::flag()', str_contains(Brand::flagFor('NP'), 'flg-np') && str_contains(Brand::flagFor('India'), 'flg-in'));
check('flagPair() = India then Nepal, decorative', (bool) preg_match('/flg-in.*flg-np/s', Brand::flagPair(14)) && substr_count(Brand::flagPair(14), 'aria-hidden="true"') >= 2);

foreach (['IN' => 1.5, 'NP' => 282 / 344] as $cc => $ratio) {
    $p = Brand::flagPng($cc);
    $im = $p ? @imagecreatefrompng($p) : false;
    if ($im) { imagesavealpha($im, true); }
    check("flagPng('$cc') is a transparent raster at the flag ratio", $im && abs(imagesx($im) / imagesy($im) - $ratio) < 0.03 && ($cc === 'IN' || px($im, imagesx($im) - 2, 2)[3] === 127), $p ? basename($p) : 'missing');
}
check("flagPng('XX') is null", Brand::flagPng('XX') === null);

// one drawing in three places
$js = $src('assets/js/23-flags.js');
$jsFlat = (string) preg_replace("/'\s*\+\s*'/", '', $js);
foreach (['IN', 'NP'] as $cc) {
    $body = Brand::flagGeometry($cc)['body'];
    check("23-flags.js draws the same $cc flag as Brand", str_contains($jsFlat, $body));
    $file = $src('assets/img/flag-' . strtolower($cc) . '.svg');
    check("assets/img/flag-" . strtolower($cc) . ".svg draws the same $cc flag", str_contains(str_replace(["\r", "\n"], '', $file), $body) && xmlOk($file) !== null);
}

/* ------------------------------------------------------------------ */
echo "\n-- D. assets/js/23-flags.js --\n";
check('23-flags.js exists', $js !== '');
check('window.shgFlag exported (+ .el / .scan)', str_contains($js, 'window.shgFlag = shgFlag') && str_contains($js, 'shgFlag.el =') && str_contains($js, 'shgFlag.scan ='));
check('sprite symbols flag-in / flag-np injected', str_contains($js, "'flag-' + c.toLowerCase()") && str_contains($js, '<symbol id="'));
check('placeholders .flg[data-flag] filled', str_contains($js, ".flg[data-flag]"));
check('emoji swapper uses a TreeWalker + MutationObserver', str_contains($js, 'createTreeWalker') && str_contains($js, 'MutationObserver'));
$skipOk = preg_match('/var SKIP = \{([^}]+)\}/', $js, $m) === 1;
foreach (['OPTION', 'SELECT', 'INPUT', 'TEXTAREA', 'SCRIPT', 'STYLE'] as $t) {
    $skipOk = $skipOk && str_contains($m[1], $t . ':');
}
check('swapper skips OPTION / SELECT / INPUT / TEXTAREA / SCRIPT / STYLE', $skipOk);
check('swapper skips svg + contenteditable', str_contains($js, 'namespaceURI === SVGNS') && str_contains($js, "getAttribute('contenteditable')"));
check('component CSS injected as <style id="shg-flags-css">', str_contains($js, "'shg-flags-css'"));
check('a second copy is a no-op (admin + app)', str_contains($js, 'window.shgFlag.ready'));
$node = trim((string) @shell_exec('node --version 2>&1'));
if (preg_match('/^v\d+/', $node)) {
    $out = (string) shell_exec('node --check ' . escapeshellarg($root . '/assets/js/23-flags.js') . ' 2>&1');
    check('node --check 23-flags.js', trim($out) === '', trim($out));
} else {
    echo "  (node not found — syntax check skipped)\n";
}

/* ------------------------------------------------------------------ */
echo "\n-- E. surfaces BRAND owns --\n";
$whiteBg = '/background(?:-color)?\s*:\s*(?:#fff\b|#ffffff\b|white\b|rgba?\(\s*255\s*,\s*255\s*,\s*255)/i';
/** The declarations of every rule whose selector contains $needle. */
function rulesFor(string $css, string $needle): array {
    $out = [];
    if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $r) {
            if (str_contains($r[1], $needle)) { $out[] = $r[2]; }
        }
    }
    return $out;
}
$surfaces = [
    'assets/css/admin.css' => '.brand-logo',
    'admin/_guard.php'     => '.brand-logo',
    'admin/login.php'      => '.logo img',
    'agent-signup.php'     => 'img.logo',
    'help.php'             => '.brand img',
    'offline.html'         => '.hdr img',
    'pay-share.php'        => '.hdr img',
    'pay.php'              => '.pb img',
    'travel-info.php'      => '.header img',
    'admin/agent-receipt.php' => '.head img',
    'admin/settlements.php'   => '.top img',
];
foreach ($surfaces as $rel => $sel) {
    $rules = rulesFor($src($rel), $sel);
    $bad = array_filter($rules, static fn(string $r): bool => preg_match($GLOBALS['whiteBg'], $r) === 1);
    check("$rel: $sel has no white tile", $rules !== [] && $bad === [], count($rules) . ' rule(s)');
}
$oldLogo = '#src=["\']/assets/img/(?:logo\.png|icon-192\.png)#';
foreach (['admin/_guard.php', 'admin/login.php', 'agent-signup.php', 'help.php', 'offline.html', 'pay-share.php', 'travel-info.php', 'admin/agent-receipt.php', 'admin/settlements.php'] as $rel) {
    $s = $src($rel);
    check("$rel no longer shows the name-baked logo", $s !== '' && preg_match($oldLogo, $s) !== 1 && !str_contains($s, "'/assets/img/logo.png'"));
}
check('pay-share.php logo is not squashed into 40x40', !preg_match('/\.hdr img\{width:40px;height:40px/', $src('pay-share.php')));
$g = $src('admin/_guard.php');
check('admin header loads 23-flags.js', str_contains($g, '/assets/js/23-flags.js?v='));
check('admin header prints the flag sprite + emblem', str_contains($g, 'echo Brand::flagSprite();') && str_contains($g, 'Brand::markUrl(34)'));
check('admin header favicon links come from Brand::iconLinks()', str_contains($g, 'echo Brand::iconLinks();'));
check('offline.html is self-contained: emblem inlined, flags drawn', str_contains($src('offline.html'), 'src="data:image/png;base64,') && substr_count($src('offline.html'), 'class="flg flg-') === 2);
check('offline-desk.html: flags drawn, emoji kept out of the buttons', str_contains($src('offline-desk.html'), '<symbol id="flag-in"') && !str_contains($src('offline-desk.html'), '🇮🇳 +91'));
check('push notification badge = monochrome badge-96.png', str_contains($src('includes/webpush.php'), "'badge' => '/assets/img/badge-96.png'"));
check('CounterDesk::flag() untouched (emoji for <option> / WhatsApp)', str_contains($src('includes/counterdesk.php'), 'public static function flag('));

/* ------------------------------------------------------------------ */
echo "\n-- F. pages over HTTP --\n";
$base = rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/');
$get = static function (string $path) use ($base): array {
    if (!function_exists('curl_init')) { return ['code' => 0, 'body' => '']; }
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 3]);
    $b = (string) curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $c, 'body' => $b];
};
$probe = $get('/manifest.webmanifest');
if ($probe['code'] !== 200) {
    echo "  (no dev server at $base — HTTP checks skipped; set SHG_TEST_BASE)\n";
    finish();
}
$noise = '/(Warning|Fatal error|Parse error|Notice|Deprecated|Uncaught)\b/';
foreach (['/admin/login.php' => 'admin login', '/help.php' => 'help', '/travel-info' => 'travel info', '/agent-signup.php' => 'agent signup'] as $path => $lbl) {
    $p = $get($path);
    $emblem = preg_match('#src="/assets/img/(?:logo-nav|logo-mark)\.png\?m=\d+"#', $p['body']) === 1;
    check("$lbl: 200, emblem logo, favicon links, no PHP noise", $p['code'] === 200 && $emblem && str_contains($p['body'], 'apple-touch-icon') && !preg_match($noise, $p['body']), 'HTTP ' . $p['code']);
}
$h = $get('/help.php');
check('help: India + Nepal flags drawn', str_contains($h['body'], 'flg flg-in') && str_contains($h['body'], 'flg flg-np'));
$o = $get('/offline.html');
check('/offline.html serves the inlined emblem', $o['code'] === 200 && str_contains($o['body'], 'data:image/png;base64,'));
$f = $get('/assets/js/23-flags.js');
check('/assets/js/23-flags.js served', $f['code'] === 200 && str_contains($f['body'], 'shgFlag'));

finish();
