<?php
/**
 * bundle-test.php — one script and one stylesheet, safely (25 Sep 2026).
 *
 *   A. The committed bundle is current: every source the manifest names is
 *      on disk with the same sha256, and the manifest's order is exactly
 *      the order app.template.html loads them in.
 *   B. The switch: off (shipped) the page carries the separate files and no
 *      bundle tag; on, one script and one stylesheet and none of the
 *      separate ones.                                             [HTTP]
 *   C. Fail-safe: a bundle that no longer matches the template is refused,
 *      and so is a bundle older than one of its sources — the page falls
 *      back rather than shipping stale code.
 *   D. The bundle is really the same program: every source's own marker is
 *      inside it, in order, and the globals the app talks through survive
 *      minification.
 *   E. The service worker precaches both bundle files at the current stamp,
 *      and still precaches the separate files it falls back to.
 *   F. Both files are served, with a sane content type.            [HTTP]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/assetbundle.php';

define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function get(string $path): array {
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $b = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $t = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['code' => $c, 'body' => $b, 'type' => $t];
}
$ROOT = dirname(__DIR__);
$was  = Settings::getBool('bundle_assets_on', false);
$set  = static function (bool $on): void {
    Settings::set('bundle_assets_on', $on ? '1' : '0', 'bool', 'performance');
    Settings::flush();
};

echo "\n=== One bundle ===\n\n-- A. the committed bundle is current --\n";
$man = AssetBundle::manifest();
check('assets/dist/manifest.json is readable', $man !== null);
if ($man === null) {
    echo "\n$PASS passed, " . ++$FAIL . " failed\n";
    exit(1);
}
$tpl  = (string) file_get_contents($ROOT . '/app.template.html');
$head = substr($tpl, 0, (int) stripos($tpl, '</head>'));
preg_match_all('#<script[^>]*\bsrc="/assets/js/([^"?]+)#', $head, $m);
$tplJs = array_map(static fn(string $f): string => 'assets/js/' . $f, $m[1]);
preg_match_all('#<link[^>]*\bhref="/assets/css/([^"?]+)#', $head, $m2);
$tplCss = array_map(static fn(string $f): string => 'assets/css/' . $f, $m2[1]);
check('the manifest lists the template\'s scripts, in order', array_column($man['js']['sources'], 'file') === $tplJs, count($tplJs) . ' scripts');
check('…and its stylesheets, in order', array_column($man['css']['sources'], 'file') === $tplCss, implode(', ', $tplCss));
$stale = [];
foreach ([...$man['js']['sources'], ...$man['css']['sources']] as $s) {
    $f = $ROOT . '/' . $s['file'];
    if (!is_file($f) || hash('sha256', (string) file_get_contents($f)) !== $s['sha256']) {
        $stale[] = $s['file'];
    }
}
check('every source matches the sha256 the bundle was built from', $stale === [], $stale === [] ? count($man['js']['sources']) + count($man['css']['sources']) . ' files' : implode(', ', $stale));
$js  = (string) file_get_contents($ROOT . '/assets/dist/app.min.js');
$css = (string) file_get_contents($ROOT . '/assets/dist/app.min.css');
check('the built files are on disk and are what the manifest says', hash('sha256', $js) === $man['js']['sha256'] && hash('sha256', $css) === $man['css']['sha256']);
check('the bundle is smaller than its sources', $man['js']['bytes'] < $man['js']['rawBytes'] * 0.8 && $man['css']['bytes'] < $man['css']['rawBytes'], round($man['js']['rawBytes'] / 1024) . ' KB → ' . round($man['js']['bytes'] / 1024) . ' KB');
$newest = 0;
foreach ([...$man['js']['sources'], ...$man['css']['sources']] as $s) { $newest = max($newest, (int) @filemtime($ROOT . '/' . $s['file'])); }
check('the built files are not older than their sources', (int) filemtime($ROOT . '/assets/dist/app.min.js') >= $newest);

echo "\n-- D. the same program, minified --\n";
$missing = [];
foreach ($man['js']['sources'] as $s) {
    /* Each source's first distinctive string literal must still be in the
       bundle: a concatenation that lost a file would not show up in a size
       check, but it shows up here. */
    $src = (string) file_get_contents($ROOT . '/' . $s['file']);
    if (preg_match_all("/'([A-Za-z][A-Za-z0-9 :._#\\/-]{12,60})'/", $src, $lits) && $lits[1] !== []) {
        $found = false;
        foreach (array_slice($lits[1], 0, 25) as $lit) {
            if (str_contains($js, $lit)) { $found = true; break; }
        }
        if (!$found) { $missing[] = basename($s['file']); }
    }
}
check('every script contributed its own strings to the bundle', $missing === [], $missing === [] ? count($man['js']['sources']) . ' scripts' : implode(', ', $missing));
/* The app's files talk to each other through globals, and esbuild leaves
   top-level names alone in a classic script — that is the whole reason the
   bundle is a concatenation and not a module build. Each name is looked for
   in either shape it can take: a function declaration, or a const/var
   binding (inr is an arrow function, so "function inr(" would never match
   however healthy the bundle is). */
foreach (['t', 'toast', 'inr', 'fmtDate', 'CONFIG', 'I18N'] as $name) {
    $asFunction = str_contains($js, 'function ' . $name . '(');
    $asBinding  = (bool) preg_match('/(?:^|[;,{}\s])(?:var |const |let )?' . preg_quote($name, '/') . '\s*=/', $js);
    check("the global {$name} survived minification", $asFunction || $asBinding, $asFunction ? 'function' : ($asBinding ? 'binding' : 'GONE'));
}
check('the CSS bundle carries both stylesheets', str_contains($css, '.ai-msg') && str_contains($css, '.trust'), '');

echo "\n-- E. the service worker --\n";
$sw = (string) file_get_contents($ROOT . '/sw.js');
preg_match("/^var ASSET_VER = '([^']+)';/m", $sw, $mv);
$ver = $mv[1] ?? '';
check('sw.js precaches both bundle files', str_contains($sw, "'/assets/dist/app.min.js?v=' + ASSET_VER") && str_contains($sw, "'/assets/dist/app.min.css?v=' + ASSET_VER"));
check('…and still precaches the separate files it falls back to', str_contains($sw, "'/assets/js/01-boot.js?v=' + ASSET_VER") && str_contains($sw, "'/assets/css/app.css?v=' + ASSET_VER"));
check('the template and the worker agree on the stamp', $ver !== '' && str_contains($head, '?v=' . $ver), $ver);

$home = get('/');
if ($home['code'] !== 200) {
    echo "\n  SKIP  no server at " . BASE . " — B, C(HTTP) and F not run\n";
} else {
    echo "\n-- B. the switch --\n";
    $set(false);
    $off = get('/');
    check('off: the page loads the separate scripts', substr_count($off['body'], '/assets/js/0') + substr_count($off['body'], '/assets/js/1') === count($tplJs), (string) (substr_count($off['body'], '/assets/js/0') + substr_count($off['body'], '/assets/js/1')));
    check('off: no bundle tag at all', !str_contains($off['body'], '/assets/dist/'));
    $set(true);
    $on = get('/');
    check('on: one bundle script and one bundle stylesheet', substr_count($on['body'], '/assets/dist/app.min.js') === 1 && substr_count($on['body'], '/assets/dist/app.min.css') === 1);
    check('on: none of the separate files are requested', substr_count($on['body'], '"/assets/js/0') === 0 && substr_count($on['body'], '"/assets/css/app.css') === 0);
    check('on: the bundle carries the release stamp', str_contains($on['body'], '/assets/dist/app.min.js?v=' . $ver));
    check('on: the rest of the head is untouched', str_contains($on['body'], 'window.SHG_BOOT') && str_contains($on['body'], 'rel="manifest"') && str_contains($on['body'], '/assets/img/logo.png'));

    echo "\n-- C. it refuses a stale bundle --\n";
    $mf   = $ROOT . '/assets/dist/manifest.json';
    $keep = (string) file_get_contents($mf);
    $bad  = json_decode($keep, true);
    array_pop($bad['js']['sources']);          // as if a script had been added to the template
    file_put_contents($mf, json_encode($bad));
    AssetBundle::__reset();
    $r = get('/');
    check('a bundle missing one of the template\'s scripts is refused', !str_contains($r['body'], '/assets/dist/app.min.js') && substr_count($r['body'], '/assets/js/0') > 0);
    file_put_contents($mf, $keep);
    AssetBundle::__reset();
    check('…and with the manifest restored it is used again', str_contains(get('/')['body'], '/assets/dist/app.min.js'));
    touch($ROOT . '/assets/js/01-boot.js');    // as if a script had been edited without a rebuild
    $r = get('/');
    check('a bundle older than a source is refused', !str_contains($r['body'], '/assets/dist/app.min.js'));
    touch($ROOT . '/assets/dist/app.min.js');
    touch($ROOT . '/assets/dist/app.min.css');
    check('…and a rebuilt bundle is used again', str_contains(get('/')['body'], '/assets/dist/app.min.js'));

    echo "\n-- F. served --\n";
    $b = get('/assets/dist/app.min.js');
    check('the JS bundle is served', $b['code'] === 200 && strlen($b['body']) === $man['js']['bytes'], $b['code'] . ' · ' . strlen($b['body']) . ' bytes · ' . $b['type']);
    check('…as JavaScript', str_contains(strtolower($b['type']), 'javascript'), $b['type']);
    $b = get('/assets/dist/app.min.css');
    check('the CSS bundle is served as CSS', $b['code'] === 200 && str_contains(strtolower($b['type']), 'css'), $b['code'] . ' · ' . $b['type']);
    $set($was);
}

echo "\n----------------------------------------\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
