<?php
/**
 * =====================================================================
 *  ASSET VERSION DISCIPLINE
 *
 *      php -c .claude/php-dev.ini tests/asset-version-test.php
 *
 *  Every cache-busting ?v= stamp in the tree has to match sw.js's
 *  ASSET_VER, because the service worker serves /assets/* CACHE-FIRST and
 *  relies entirely on the URL changing to invalidate a build. Miss one and
 *  installed PWAs keep the old file forever, which surfaces as "the fix
 *  didn't work" from someone at a counter rather than as an error anywhere.
 *
 *  This is not hypothetical. Four stamps had drifted when this was written
 *  — terms-data.js at 20260822b, the ticket logo at 20260903e, the manifest
 *  icons at 20260823m, the CEO photo at 20260828 — while everything else
 *  was on 20260905l. And during the same session a locally cached
 *  views.css was served by the worker and a new stylesheet simply did not
 *  appear until the SW was unregistered by hand.
 *
 *  The alternative fix was to template app.template.html through index.php
 *  so the stamp is injected once. That is a runtime change to a 179 KB
 *  template on a live SPA to solve what is a discipline problem, so this
 *  file exists instead: same guarantee, no runtime risk, and it fails the
 *  battery instead of reaching a passenger.
 * =====================================================================
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

echo "\n=== Asset version discipline ===\n\n";

/* ---- the single source of truth ----------------------------------- */
$sw = (string) @file_get_contents($root . '/sw.js');
check('sw.js is readable', $sw !== '');

preg_match("/^var ASSET_VER = '([^']+)';/m", $sw, $m);
$assetVer = $m[1] ?? '';
check('sw.js declares ASSET_VER', $assetVer !== '', $assetVer);

preg_match("/^var VERSION = '([^']+)';/m", $sw, $mv);
$version = $mv[1] ?? '';
check('sw.js declares VERSION', $version !== '', $version);

if ($assetVer === '') {
    echo "\n  cannot continue without ASSET_VER\n";
    exit(1);
}

/* ---- every ?v= in every file that carries one ---------------------- */
$files = array_merge(
    [$root . '/app.template.html', $root . '/manifest.webmanifest', $root . '/index.php'],
    glob($root . '/assets/js/*.js') ?: [],
    glob($root . '/assets/css/*.css') ?: []
);

$bad   = [];
$seen  = 0;
foreach ($files as $f) {
    if (!is_file($f)) { continue; }
    $src = (string) file_get_contents($f);
    if (!preg_match_all('/\?v=([0-9a-zA-Z._-]+)/', $src, $hits)) { continue; }
    foreach ($hits[1] as $v) {
        $seen++;
        if ($v !== $assetVer) {
            $bad[] = basename($f) . ' → ?v=' . $v;
        }
    }
}

check('at least one ?v= stamp exists to check', $seen > 0, $seen . ' found');
check('every ?v= stamp matches sw.js ASSET_VER',
    $bad === [],
    $bad === [] ? ('all ' . $seen . ' on ' . $assetVer) : implode('; ', array_unique($bad)));

/* ---- the precache list must point at files that exist -------------- */
preg_match_all("/'(\/assets\/[^']+?)\?v='\s*\+\s*ASSET_VER/", $sw, $pm);
$missing = [];
foreach ($pm[1] as $rel) {
    if (!is_file($root . $rel)) { $missing[] = $rel; }
}
check('every precached asset exists on disk',
    $missing === [],
    $missing === [] ? count($pm[1]) . ' entries' : implode(', ', $missing));

/* ---- the SW cache version must move when the assets do -------------
   Not a matching-string rule (they use different formats on purpose);
   just that neither is a placeholder someone forgot to touch. */
check('VERSION looks like a real build tag', (bool) preg_match('/^shg-v\d+$/', $version), $version);
check('ASSET_VER looks like a real date tag', (bool) preg_match('/^\d{8}[a-z]?$/', $assetVer), $assetVer);

/* ---- the template must actually load the bundles ------------------- */
$tpl = (string) @file_get_contents($root . '/app.template.html');
preg_match_all('/assets\/js\/(\d\d-[a-z-]+\.js)/', $tpl, $tm);
$loaded = array_unique($tm[1]);
check('the template loads the JS bundles', count($loaded) >= 10, count($loaded) . ' referenced');

echo "\n----------------------------------------\n";
echo "PASSED: {$PASS}   FAILED: {$FAIL}\n";
echo "----------------------------------------\n\n";

exit($FAIL === 0 ? 0 : 1);
