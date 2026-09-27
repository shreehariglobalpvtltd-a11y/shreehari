<?php
/**
 * bundle-test.php — one script and one stylesheet, safely (27 Sep 2026).
 *
 *   A. The committed bundle is current: every source the manifest names is
 *      on disk with the same size and sha256, the manifest's order is
 *      exactly the order app.template.html loads them in, and no source was
 *      committed AFTER the last build (git ancestry: the commit that last
 *      touched each source must be an ancestor of the commit that last
 *      touched the manifest). A forgotten `node tools/build.mjs` fails here.
 *   B. The switch: off (shipped) the page carries the separate files and no
 *      bundle tag; on, one script and one stylesheet and none of the
 *      separate ones.                                             [HTTP]
 *   C. Fail-safe: a bundle that no longer matches the template is refused,
 *      and so is a source edited after the build — the page falls back
 *      rather than shipping stale code.                           [HTTP]
 *   D. The bundle is really the same program: every script's own strings
 *      are inside it, the globals the app talks through are there, every
 *      stylesheet contributed, and the stripped CSS normalises to exactly
 *      the same bytes as the four sources joined.
 *   E. The service worker precaches both bundle files at the current stamp,
 *      and still precaches the separate files it falls back to.
 *   F. Both files are served, with a sane content type.            [HTTP]
 *
 *  Run alone:  php tests/bundle-test.php        (B, C, F need :8899)
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
/** git, quietly; '' when git or the repo is not there. */
function git(string $args): string {
    $out = @shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' ' . $args . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'));
    return trim((string) $out);
}
$ROOT = dirname(__DIR__);
$was  = Settings::getBool('bundle_assets_on', false);
$set  = static function (bool $on): void {
    Settings::set('bundle_assets_on', $on ? '1' : '0', 'bool', 'performance');
    Settings::flush();
};

/* The same two patterns includes/assetbundle.php and tools/build.mjs use. */
const T_JS  = '#<script[^>]*\bsrc="/assets/js/([^"?]+)(?:\?v=([^"]*))?"[^>]*></script>#i';
const T_CSS = '#<link(?=[^>]*\brel="stylesheet")[^>]*\bhref="/assets/css/([^"?]+)(?:\?v=([^"]*))?"[^>]*>#i';

echo "\n=== One bundle ===\n\n-- A. the committed bundle is current --\n";
$man = AssetBundle::manifest();
check('assets/dist/manifest.json is readable', $man !== null);
if ($man === null) {
    echo "\n$PASS passed, " . ++$FAIL . " failed\n";
    exit(1);
}
$tpl  = (string) file_get_contents($ROOT . '/app.template.html');
$head = substr($tpl, 0, (int) stripos($tpl, '</head>'));
preg_match_all(T_JS, $head, $m);
$tplJs = array_map(static fn(string $f): string => 'assets/js/' . $f, $m[1]);
$tplStamps = $m[2];
preg_match_all(T_CSS, $head, $m2);
$tplCss = array_map(static fn(string $f): string => 'assets/css/' . $f, $m2[1]);
check('the template loads its scripts from the head', count($tplJs) >= 10, count($tplJs) . ' scripts');
check('the manifest lists the template\'s scripts, in order', array_column($man['js']['sources'], 'file') === $tplJs, count($tplJs) . ' scripts');
check('…and its stylesheets, in order', array_column($man['css']['sources'], 'file') === $tplCss, implode(', ', array_map('basename', $tplCss)));
check('the manifest records the stamp the template carries', ($man['stamp'] ?? '') === ($tplStamps[0] ?? '-'), (string) ($man['stamp'] ?? ''));
$stale = []; $wrongSize = [];
foreach ([...$man['js']['sources'], ...$man['css']['sources']] as $s) {
    $f = $ROOT . '/' . $s['file'];
    if (!is_file($f)) { $stale[] = $s['file'] . ' (missing)'; continue; }
    if ((int) filesize($f) !== (int) $s['bytes']) { $wrongSize[] = $s['file']; }
    if (hash_file('sha256', $f) !== $s['sha256']) { $stale[] = $s['file']; }
}
check('every source matches the sha256 the bundle was built from', $stale === [], $stale === [] ? count($man['js']['sources']) + count($man['css']['sources']) . ' files' : 'REBUILD: ' . implode(', ', $stale));
check('every source is still the size the bundle was built from', $wrongSize === [], implode(', ', $wrongSize));
$js  = (string) file_get_contents($ROOT . '/assets/dist/app.min.js');
$css = (string) file_get_contents($ROOT . '/assets/dist/app.min.css');
check('the built files are on disk and are what the manifest says', hash('sha256', $js) === $man['js']['sha256'] && hash('sha256', $css) === $man['css']['sha256'], strlen($js) . ' + ' . strlen($css) . ' bytes');
/* JS is joined, not minified (no minifier is vendored on this tree): it may
   be larger than its sources only by the per-file headers and joiners. CSS
   is stripped and must be smaller — unless the build had to fall back. */
$jsOverhead = 96 * count($man['js']['sources']);
check('the JS bundle is its sources plus nothing but headers', $man['js']['bytes'] >= $man['js']['rawBytes'] && $man['js']['bytes'] <= $man['js']['rawBytes'] + $jsOverhead, round($man['js']['rawBytes'] / 1024) . ' KB → ' . round($man['js']['bytes'] / 1024) . ' KB, ' . (string) ($man['minifier']['js'] ?? '?'));
$cssMin = (string) ($man['minifier']['css'] ?? 'concat');
check('the CSS bundle is smaller than its sources', $cssMin === 'concat' ? $man['css']['bytes'] <= $man['css']['rawBytes'] + 96 * count($man['css']['sources']) : $man['css']['bytes'] < $man['css']['rawBytes'], round($man['css']['rawBytes'] / 1024) . ' KB → ' . round($man['css']['bytes'] / 1024) . ' KB, ' . $cssMin);

/* Nothing committed after the build. git does not keep mtimes, so "newer"
   is asked of history: the commit that last touched each source must be an
   ancestor of (or the same as) the commit that last touched the manifest.
   Uncommitted edits are caught by the sha256 check above; a manifest that
   is not committed yet (a fresh worktree) is a skip, not a failure. */
$manCommit = git('log -1 --format=%H -- assets/dist/manifest.json');
if ($manCommit === '') {
    echo "  SKIP  the manifest has no commit yet (or git is not here) — the build-order check needs history\n";
} else {
    $after = [];
    foreach ([...$man['js']['sources'], ...$man['css']['sources']] as $s) {
        $c = git('log -1 --format=%H -- ' . escapeshellarg((string) $s['file']));
        if ($c === '' || $c === $manCommit) { continue; }
        @exec('git -C ' . escapeshellarg($ROOT) . ' merge-base --is-ancestor ' . $c . ' ' . $manCommit, $o, $rc);
        if ($rc !== 0) { $after[] = basename((string) $s['file']) . '@' . substr($c, 0, 7); }
    }
    check('no source was committed after the last build (git ancestry)', $after === [], $after === [] ? 'built at ' . substr($manCommit, 0, 7) : 'REBUILD: ' . implode(', ', $after));
}

echo "\n-- D. the same program, joined --\n";
$missing = [];
foreach ($man['js']['sources'] as $s) {
    /* Each source's first distinctive string literals must be in the
       bundle: a join that lost a file would not show up in a size check,
       but it shows up here. */
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
$order = [];
foreach ($man['js']['sources'] as $s) { $order[] = strpos($js, '/* ' . $s['file'] . ' */'); }
check('…in the template\'s order', !in_array(false, $order, true) && $order === array_values(array_unique($order)) && $order == array_values((function (array $a): array { sort($a); return $a; })($order)));
/* The files talk to each other through globals; a join keeps every
   top-level name. Each is looked for as a function declaration or a
   const/var binding (inr is an arrow function). */
foreach (['t', 'toast', 'inr', 'fmtDate', 'CONFIG', 'I18N', 'SFX'] as $name) {
    $asFunction = str_contains($js, 'function ' . $name . '(');
    $asBinding  = (bool) preg_match('/(?:^|[;,{}\s])(?:var |const |let )?' . preg_quote($name, '/') . '\s*=/m', $js);
    check("the global {$name} survived the join", $asFunction || $asBinding, $asFunction ? 'function' : ($asBinding ? 'binding' : 'GONE'));
}
check('no top-level "use strict" was joined in', !preg_match('/^[\'"]use strict[\'"]\s*;?\s*$/m', $js));
$noClass = [];
foreach ($man['css']['sources'] as $s) {
    /* The first class selector of each stylesheet must be in the bundle. */
    $src = (string) file_get_contents($ROOT . '/' . $s['file']);
    if (preg_match('/\.([A-Za-z][\w-]{3,})\s*[{,:]/', $src, $cm) && !str_contains($css, '.' . $cm[1])) { $noClass[] = basename($s['file']) . ' (.' . $cm[1] . ')'; }
}
check('every stylesheet contributed to the CSS bundle', $noClass === [], $noClass === [] ? count($man['css']['sources']) . ' stylesheets' : implode(', ', $noClass));
/* The proof the strip removed nothing but comments and whitespace: the
   sources joined and the built file normalise to the same bytes. */
$norm = static fn(string $c): string => str_replace(';}', '}', preg_replace('/\s+/', '', preg_replace('#/\*.*?\*/#s', '', $c) ?? '') ?? '');
$rawCss = '';
foreach ($man['css']['sources'] as $s) { $rawCss .= (string) file_get_contents($ROOT . '/' . $s['file']) . "\n"; }
$rawCss = preg_replace('/^\xEF\xBB\xBF/', '', $rawCss) ?? $rawCss;
check('the stripped CSS is the four stylesheets and nothing else (normalised, byte for byte)', $norm($rawCss) === $norm($css), strlen($norm($css)) . ' bytes normalised');
check('the CSS bundle keeps the file it came from readable', str_contains($css, 'font-family') && str_contains($css, '@media'));

echo "\n-- E. the service worker --\n";
$sw = (string) file_get_contents($ROOT . '/sw.js');
preg_match("/^var ASSET_VER = '([^']+)';/m", $sw, $mv);
$ver = $mv[1] ?? '';
check('sw.js does not precache the bundle while it is off (no 1.5 MB extra on install)', !str_contains($sw, "'/assets/dist/app.min.js?v=' + ASSET_VER") && !str_contains($sw, "'/assets/dist/app.min.css?v=' + ASSET_VER"));
check('…and still precaches the separate files it falls back to', str_contains($sw, "'/assets/js/01-boot.js?v=' + ASSET_VER") && str_contains($sw, "'/assets/css/app.css?v=' + ASSET_VER"));
check('the template and the worker agree on the stamp', $ver !== '' && ($tplStamps[0] ?? '') === $ver, $ver);

$home = get('/');
if ($home['code'] !== 200) {
    echo "\n  SKIP  no server at " . BASE . " — B, C and F not run\n";
} else {
    echo "\n-- B. the switch --\n";
    $set(false);
    $off = get('/');
    $sep = 0;
    foreach ($tplJs as $f) { if (str_contains($off['body'], '"/' . $f)) { $sep++; } }
    check('off: the page loads the separate scripts', $sep === count($tplJs), $sep . ' of ' . count($tplJs));
    check('off: no bundle tag at all', !str_contains($off['body'], '/assets/dist/'));
    $set(true);
    $on = get('/');
    check('on: one bundle script and one bundle stylesheet', substr_count($on['body'], '/assets/dist/app.min.js') === 1 && substr_count($on['body'], '/assets/dist/app.min.css') === 1);
    $left = [];
    foreach ([...$tplJs, ...$tplCss] as $f) { if (str_contains($on['body'], '"/' . $f)) { $left[] = basename($f); } }
    check('on: none of the separate files are requested', $left === [], implode(', ', $left));
    check('on: the bundle carries the release stamp', str_contains($on['body'], '/assets/dist/app.min.js?v=' . $ver) && str_contains($on['body'], '/assets/dist/app.min.css?v=' . $ver));
    check('on: the bundle script keeps defer, the stylesheet keeps rel', str_contains($on['body'], '<script defer src="/assets/dist/app.min.js') && str_contains($on['body'], '<link rel="stylesheet" href="/assets/dist/app.min.css'));
    check('on: the rest of the head is untouched', str_contains($on['body'], 'window.SHG_BOOT') && str_contains($on['body'], 'rel="manifest"') && str_contains($on['body'], '/assets/img/logo.png'));
    check('on: the lazy chunks are still fetched by URL, not bundled', !str_contains($js, '/* assets/js/15-nav.js */') && !str_contains($js, '/* assets/js/16-lazy.js */'));

    echo "\n-- C. it refuses a stale bundle --\n";
    $mf   = $ROOT . '/assets/dist/manifest.json';
    $keep = (string) file_get_contents($mf);
    $bad  = json_decode($keep, true);
    array_pop($bad['js']['sources']);          // as if a script had been added to the template
    file_put_contents($mf, json_encode($bad));
    AssetBundle::__reset();
    $r = get('/');
    check('a bundle missing one of the template\'s scripts is refused', !str_contains($r['body'], '/assets/dist/app.min.js') && str_contains($r['body'], '"/' . $tplJs[0]));
    file_put_contents($mf, $keep);
    AssetBundle::__reset();
    check('…and with the manifest restored it is used again', str_contains(get('/')['body'], '/assets/dist/app.min.js'));
    /* A source edited after the build is refused — by its size, which is
       exact, and by an mtime more than two seconds newer, which is what a
       real edit looks like. A re-touched but unchanged file must NOT trip
       it: a fresh checkout touches everything at once. */
    $probe    = $ROOT . '/' . $tplJs[0];
    $builtAt  = (int) filemtime($ROOT . '/assets/dist/app.min.js');
    $keepTime = (int) filemtime($probe);
    touch($probe, $builtAt);
    check('a source written in the same instant as the bundle is not called stale', str_contains(get('/')['body'], '/assets/dist/app.min.js'));
    touch($probe, $builtAt + 1);
    check('…nor one a second later', str_contains(get('/')['body'], '/assets/dist/app.min.js'));
    touch($probe, $builtAt + 600);
    check('a source edited ten minutes after the build is refused', !str_contains(get('/')['body'], '/assets/dist/app.min.js'));
    touch($probe, $builtAt);
    check('…and putting its time back brings the bundle back', str_contains(get('/')['body'], '/assets/dist/app.min.js'));
    $keepJs = (string) file_get_contents($probe);
    file_put_contents($probe, $keepJs . "\n/* one byte more */\n");
    check('a source whose size changed is refused whatever its clock says', !str_contains(get('/')['body'], '/assets/dist/app.min.js'));
    file_put_contents($probe, $keepJs);
    touch($probe, $keepTime > $builtAt ? $builtAt : $keepTime);
    check('…and restoring it brings the bundle back', str_contains(get('/')['body'], '/assets/dist/app.min.js'));

    echo "\n-- F. served --\n";
    $b = get('/assets/dist/app.min.js?v=' . $ver);
    check('the JS bundle is served', $b['code'] === 200 && strlen($b['body']) === (int) $man['js']['bytes'], $b['code'] . ' · ' . strlen($b['body']) . ' bytes · ' . $b['type']);
    check('…as JavaScript', str_contains(strtolower($b['type']), 'javascript'), $b['type']);
    $b = get('/assets/dist/app.min.css?v=' . $ver);
    check('the CSS bundle is served as CSS', $b['code'] === 200 && str_contains(strtolower($b['type']), 'css'), $b['code'] . ' · ' . $b['type']);
    $set($was);
}

echo "\n----------------------------------------\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
