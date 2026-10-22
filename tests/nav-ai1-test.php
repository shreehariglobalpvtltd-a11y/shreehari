<?php
/**
 * =====================================================================
 *  nav-ai1-test.php — SHARI NAV ai1 (21 Oct 2026): KB, AI guide, temple
 *  layer, border assistant, S Hari bus line, two new endpoints.
 *
 *  Static checks on navigate.html AFTER the module splice and on the two
 *  endpoints (no browser, no database). The spec's §9 (A1–A19) and §10
 *  (P1–P25) amendments override the earlier text, so where a check and an
 *  amendment differ the amendment wins (P2 replaces A8, P12 replaces the
 *  §7 rate limits, P13 replaces A5's wa, P16 replaces the border facts):
 *
 *    • every required @@MODULE:X@@ anchor is closed by its @@END marker
 *      (= spliced); the CSS / HTML / I18N anchors are optional and only
 *      reported. Before the splice the five JS modules FAIL "not spliced"
 *      — that is the expected picture until the splice has run
 *    • node --check on the page's inline script (written to the temp dir)
 *      and on every spliced module block wrapped in (function(){…})();
 *    • php -l on api/nav-ai.php and api/nav-temples.php
 *    • every hook() a module registers is a fire() point of the core, and
 *      every fire() point is documented in the HOOKS comment
 *    • module hygiene: no console.log, no ?. / ??, no bare fetch(), no
 *      history.pushState except the P2 open state in ai.js, no fire(),
 *      no window.x outside ?debug, no core name redeclared or reassigned,
 *      module-tag prefixes, LS keys under shg:nm:, no phone literal, no
 *      Google / Overpass / Open-Meteo, cross-module calls typeof-guarded,
 *      DOM markers wrapped (wrapM)
 *    • KB evaluated with node (a fake S = {lang:'ne'} + the block): ≥ 28
 *      temples inside lat 5–37 / lng 68–98 with unique ids, the required
 *      pilgrimage set + the P18 corridor temples, the 7 crossings, the P3
 *      Rupaidiha gate, P16 border facts, P5 IST tips, P25 SIM lines,
 *      trilingual ui / quick / cats, P15 intents, no phone in KB.shg,
 *      Nepal polygon ray-cast self-test incl. the P23 points
 *    • per-module contracts (exports, hooks, A1–A19, P1–P22)
 *    • the endpoints refuse without the expected params (regex on the
 *      400 / 403 / 405 / 429 branches, in the A19 order), the P11 role
 *      override, the P12 limits, the P13 facts, the P10 rounding
 *    • navigate.html keeps CRLF and stays UTF-8 without BOM
 *
 *    php -c .claude/php-dev.ini tests/nav-ai1-test.php
 *    php tests/nav-ai1-test.php --modules <dir>
 *        splices <dir>/kb.js … in memory first (same rules as splice.py)
 *        so the suite can run before the real splice
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
function skip(string $l, string $why = ''): void { echo "  \033[33mskip\033[0m  $l" . ($why !== '' ? " — $why" : '') . "\n"; }
function firstLine(string $s): string { return trim(explode("\n", str_replace("\r", '', $s))[0] ?? ''); }

/* run a program WITHOUT a shell: an array command bypasses cmd.exe, so a
   path with spaces (C:\Program Files\nodejs\node.exe) needs no quoting games */
function run(array $argv): array {
    $p = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ['rc' => 127, 'out' => 'cannot start ' . $argv[0]]; }
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['rc' => proc_close($p), 'out' => trim($out)];
}
function nodeBin(): string {
    $win = 'C:\\Program Files\\nodejs\\node.exe';
    if (PHP_OS_FAMILY === 'Windows' && is_file($win)) { return $win; }
    return run(['node', '--version'])['rc'] === 0 ? 'node' : '';
}

/* the splice markers, exactly as splice.py writes them */
const MARK = [
    'MODULE' => ['/* @@MODULE:%s@@ */',   '/* @@END:MODULE:%s@@ */'],
    'CSS'    => ['/* @@CSS:%s@@ */',      '/* @@END:CSS:%s@@ */'],
    'HTML'   => ['<!-- @@HTML:%s@@ -->',  '<!-- @@END:HTML:%s@@ -->'],
];
/* [state, body]: missing = no anchor, open = anchor without END (not spliced), spliced = both */
function blockOf(string $page, string $kind, string $name): array {
    [$af, $ef] = MARK[$kind];
    $a = sprintf($af, $name); $e = sprintf($ef, $name);
    $i = strpos($page, $a);
    if ($i === false) { return ['missing', '']; }
    $j = strpos($page, $e, $i);
    if ($j === false) { return ['open', '']; }
    return ['spliced', substr($page, $i + strlen($a), $j - $i - strlen($a))];
}
/* --modules <dir>: the same insertion splice.py does, in memory (LF inside, CRLF out) */
function vsplice(string $page, string $dir): string {
    $s = str_replace("\r\n", "\n", $page);
    foreach (['MODULE' => 'js', 'CSS' => 'css', 'HTML' => 'html'] as $kind => $ext) {
        [$af, $ef] = MARK[$kind];
        if (!preg_match_all('/@@' . $kind . ':([A-Z0-9_]+)@@/', $s, $m)) { continue; }
        foreach (array_unique($m[1]) as $name) {
            $f = rtrim($dir, '/\\') . '/' . strtolower($name) . '.' . $ext;
            if (!is_file($f)) { continue; }
            $body = trim(str_replace("\r\n", "\n", (string) file_get_contents($f)), "\n");
            $a = sprintf($af, $name); $e = sprintf($ef, $name);
            if (str_contains($s, $e)) {
                $i = (int) strpos($s, $a); $j = (int) strpos($s, $e) + strlen($e);
                $s = substr($s, 0, $i) . $a . "\n" . $body . "\n" . $e . substr($s, $j);
            } else {
                $s = str_replace($a, $a . "\n" . $body . "\n" . $e, $s);
            }
        }
    }
    return str_replace("\n", "\r\n", $s);
}
/* JS with its string literals and comments blanked, so `?.` / `??` are looked for in code only
   (regex literals are left alone — a `/\?\?/` would show up as a false alarm, which is visible) */
function stripJs(string $s): string {
    $out = ''; $n = strlen($s); $i = 0;
    while ($i < $n) {
        $c = $s[$i];
        if ($c === '/' && $i + 1 < $n && $s[$i + 1] === '/') { $j = strpos($s, "\n", $i); $i = $j === false ? $n : $j; continue; }
        if ($c === '/' && $i + 1 < $n && $s[$i + 1] === '*') { $j = strpos($s, '*/', $i + 2); $i = $j === false ? $n : $j + 2; continue; }
        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c; $i++;
            while ($i < $n && $s[$i] !== $q) { if ($s[$i] === '\\') { $i++; } $i++; }
            $i++; $out .= $q . $q; continue;
        }
        $out .= $c; $i++;
    }
    return $out;
}
/* column-0 `var a = …, b = …` / `function c(` names = what a block adds to the IIFE scope */
function topNames(string $src): array {
    $names = [];
    if (preg_match_all('/^function\s+([A-Za-z_$][\w$]*)/m', $src, $m)) { $names = $m[1]; }
    if (preg_match_all('/^var\s+[^\n]*/m', $src, $m)) {
        foreach ($m[0] as $line) {
            $line = (string) preg_replace('~/\*.*?\*/|//.*$~', '', $line);
            if (preg_match_all('/(?:^var\s+|,\s*)([A-Za-z_$][\w$]*)\s*(?==|,|;|$)/', $line, $mm)) { foreach ($mm[1] as $n) { $names[] = $n; } }
        }
    }
    return array_values(array_unique($names));
}
/* the selectors of a CSS block (at-rules and keyframe steps left out) */
function selectors(string $css): array {
    if (!preg_match_all('/([^{}]+)\{/', $css, $m)) { return []; }
    $out = [];
    foreach ($m[1] as $sel) {
        $sel = trim($sel);
        if ($sel === '' || $sel[0] === '@' || preg_match('/^(\d+%|from|to)(\s*,\s*(\d+%|from|to))*$/', $sel)) { continue; }
        $out[] = $sel;
    }
    return $out;
}
function tri($v): bool {
    return is_array($v) && isset($v['ne'], $v['hi'], $v['en'])
        && is_string($v['ne']) && is_string($v['hi']) && is_string($v['en'])
        && trim($v['ne']) !== '' && trim($v['hi']) !== '' && trim($v['en']) !== '';
}
/* every leaf of $v must be a {ne,hi,en} object (ui, quick, cats); $bad collects the paths that are not */
function triDeep($v, string $path, array &$bad): void {
    if (is_array($v) && (isset($v['ne']) || isset($v['hi']) || isset($v['en']))) { if (!tri($v)) { $bad[] = $path; } return; }
    if (is_array($v)) { foreach ($v as $k => $x) { triDeep($x, $path . '.' . $k, $bad); } return; }
    $bad[] = $path;
}
/* ray casting on a [lng,lat] ring — the same test BORDER.inNepal() runs */
function inPoly(array $ring, float $x, float $y): bool {
    $in = false; $n = count($ring);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = (float) $ring[$i][0]; $yi = (float) $ring[$i][1]; $xj = (float) $ring[$j][0]; $yj = (float) $ring[$j][1];
        if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) { $in = !$in; }
    }
    return $in;
}
function isPair($p): bool { return is_array($p) && count($p) === 2 && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null); }
/* great-circle km between two [lng,lat]-ish points (P3 gate distances) */
function havKm(float $lng1, float $lat1, float $lng2, float $lat2): float {
    $dLa = deg2rad($lat2 - $lat1); $dLo = deg2rad($lng2 - $lng1);
    $h = sin($dLa / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLo / 2) ** 2;
    return 12742.0 * asin(min(1.0, sqrt($h)));
}
/* first offset of a regex in a source, -1 when absent (branch-order checks) */
function posOf(string $src, string $re): int { return preg_match($re, $src, $m, PREG_OFFSET_CAPTURE) ? (int) $m[0][1] : -1; }
/* where an HTTP status is answered: $fail(CODE, …), http_response_code(CODE) or a ['code' => CODE, …] result (nav-temples) */
function codePos(string $src, int $code): int { return posOf($src, '/\$fail\(\s*' . $code . '\b|http_response_code\(\s*' . $code . '\s*\)|[\'"]code[\'"]\s*=>\s*' . $code . '\b/'); }
/* the text from the first match of $startRe up to the first "});" after it (one hook body) */
function region(string $src, string $startRe): string {
    $p = posOf($src, $startRe);
    if ($p < 0) { return ''; }
    $e = strpos($src, '});', $p);
    return $e === false ? substr($src, $p) : substr($src, $p, $e - $p + 3);
}

echo "\n=== SHARI NAV ai1: KB, AI guide, temples, border, S Hari line, endpoints ===\n\n";
$t0 = microtime(true);
$root = dirname(__DIR__);
$read = static fn (string $f): string => (string) @file_get_contents($root . '/' . $f);
$page = $read('navigate.html');
$modDir = '';
foreach ($argv as $i => $a) { if ($a === '--modules' && isset($argv[$i + 1])) { $modDir = (string) $argv[$i + 1]; } }
if ($modDir !== '') { $page = vsplice($page, $modDir); echo "  (modules spliced in memory from $modDir)\n"; }
$pageLF = str_replace("\r\n", "\n", $page);
$aiPhp = $read('api/nav-ai.php');
$tpPhp = $read('api/nav-temples.php');
$node = nodeBin();
$tmpDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'shg-navai1-' . getmypid();
@mkdir($tmpDir, 0700, true);
register_shutdown_function(static function () use ($tmpDir): void {
    foreach ((array) glob($tmpDir . DIRECTORY_SEPARATOR . '*') as $f) { @unlink((string) $f); }
    @rmdir($tmpDir);
});

echo "-- 0. files --\n";
check('navigate.html is readable and the full page', strlen($page) > 100000, number_format(strlen($page)) . ' bytes');
$script = '';
if (preg_match('~<script>\n(.*?)</script>\n~s', $pageLF, $m)) { $script = $m[1]; }
check('the inline script is found (one <script> before </body>)', $script !== '' && substr_count($pageLF, '<script>') === 1, substr_count($script, "\n") . ' lines');
check('api/nav-ai.php exists', $aiPhp !== '', $aiPhp === '' ? 'missing' : number_format(strlen($aiPhp)) . ' bytes');
check('api/nav-temples.php exists', $tpPhp !== '', $tpPhp === '' ? 'missing' : number_format(strlen($tpPhp)) . ' bytes');
if ($node === '') { echo "  (node not found — the node --check and KB checks are skipped)\n"; }

echo "\n-- 1. splice markers --\n";
$ANCH = [
    ['MODULE', 'KB', true], ['MODULE', 'AI', true], ['MODULE', 'TEMPLES', true], ['MODULE', 'BORDER', true], ['MODULE', 'SHGROUTE', true],
    ['MODULE', 'I18N', false],                                                          /* later phase */
    ['CSS', 'AI', false], ['CSS', 'TEMPLES', false], ['CSS', 'BORDER', false], ['CSS', 'SHGROUTE', false], ['CSS', 'I18N', false],
    ['HTML', 'AI', false], ['HTML', 'TEMPLES', false], ['HTML', 'BORDER', false], ['HTML', 'SHGROUTE', false],
];
$B = [];                                                                                /* 'MODULE:AI' => body ('' unless spliced) */
$ST = [];                                                                               /* 'MODULE:AI' => state */
foreach ($ANCH as [$kind, $name, $required]) {
    [$state, $body] = blockOf($pageLF, $kind, $name);
    $key = $kind . ':' . $name; $B[$key] = $body; $ST[$key] = $state;
    $tag = '@@' . $kind . ':' . $name . '@@';
    $n = $state === 'spliced' ? (substr_count(trim($body, "\n"), "\n") + 1) . ' lines' : '';
    if ($state === 'missing') { check("$tag anchor exists in navigate.html", false, 'anchor missing'); continue; }
    if ($required) { check("$tag is spliced (END marker present)", $state === 'spliced', $state === 'spliced' ? $n : 'not spliced'); }
    elseif ($state === 'spliced') { check("$tag is spliced (optional)", true, $n); }
    else { skip("$tag not spliced", 'optional'); }
}
$JS = [];
foreach (['KB', 'AI', 'TEMPLES', 'BORDER', 'SHGROUTE', 'I18N'] as $n) { if ($ST['MODULE:' . $n] === 'spliced') { $JS[$n] = $B['MODULE:' . $n]; } }
$allJs = implode("\n", $JS);
$allMod = $allJs;
foreach ($B as $k => $v) { if (!str_starts_with($k, 'MODULE:')) { $allMod .= "\n" . $v; } }
$core = (string) preg_replace('~/\* @@MODULE:([A-Z0-9_]+)@@ \*/[\s\S]*?/\* @@END:MODULE:\1@@ \*/~', '', $script);
$pU = strpos($script, "'use strict'"); $pK = strpos($script, '/* @@MODULE:KB@@ */'); $pL = strpos($script, 'function loadScript'); $pB = strpos($script, "\nboot();\n})();");
check('the JS anchors sit inside the IIFE, after the core and before boot()', $pU !== false && $pK !== false && $pL !== false && $pB !== false && $pU < $pK && $pK < $pL && $pL < $pB);
$order = array_map(static fn (string $n): int => (int) strpos($script, '/* @@MODULE:' . $n . '@@ */'), ['KB', 'AI', 'TEMPLES', 'BORDER', 'SHGROUTE', 'I18N']);
check('module order: KB first (data), then AI, TEMPLES, BORDER, SHGROUTE, I18N', $order === array_values($order) && $order[0] < $order[1] && $order[1] < $order[2] && $order[2] < $order[3] && $order[3] < $order[4] && $order[4] < $order[5]);
$pSt = strpos($pageLF, '<style>'); $pSe = strpos($pageLF, '</style>'); $pC = strpos($pageLF, '/* @@CSS:AI@@ */');
check('the CSS anchors sit inside the page <style>', $pSt !== false && $pSe !== false && $pC !== false && $pSt < $pC && $pC < $pSe);
$pH = strpos($pageLF, '<!-- @@HTML:AI@@ -->'); $pM = strpos($pageLF, '<div class="modal" id="modal"'); $pN = strpos($pageLF, '<section class="nav-bot');
check('the HTML anchors sit after the nav bar and before the modal / toast', $pH !== false && $pM !== false && $pN !== false && $pN < $pH && $pH < $pM);

echo "\n-- 2. syntax: node --check on the inline script (+ each module wrapped), php -l on the endpoints --\n";
if ($node !== '' && $script !== '') {
    $jsFile = $tmpDir . DIRECTORY_SEPARATOR . 'navigate-inline.js';
    file_put_contents($jsFile, $script);
    $r = run([$node, '--check', $jsFile]);
    check('node --check navigate.html inline script', $r['rc'] === 0, $r['rc'] === 0 ? number_format(strlen($script)) . ' bytes' : firstLine($r['out']) . ' | ' . (explode("\n", $r['out'])[1] ?? ''));
    /* §1 rule 11: every module block passes on its own inside (function(){…})(); — names the culprit when the page fails */
    foreach ($JS as $n => $blk) {
        $mf = $tmpDir . DIRECTORY_SEPARATOR . 'check_' . strtolower($n) . '.js';
        file_put_contents($mf, "(function(){\n'use strict';\n" . $blk . "\n})();\n");
        $r = run([$node, '--check', $mf]);
        check("node --check module $n (wrapped in an IIFE, strict)", $r['rc'] === 0, $r['rc'] === 0 ? '' : firstLine($r['out']) . ' | ' . (explode("\n", $r['out'])[1] ?? ''));
    }
} else { skip('node --check navigate.html inline script', 'node not found'); }
foreach (['api/nav-ai.php' => $aiPhp, 'api/nav-temples.php' => $tpPhp] as $f => $src) {
    if ($src === '') { skip("php -l $f", 'file missing'); continue; }
    $r = run([PHP_BINARY, '-n', '-l', $root . '/' . $f]);
    check("php -l $f", $r['rc'] === 0, $r['rc'] === 0 ? '' : firstLine($r['out']));
}

echo "\n-- 3. hooks: every hook() a module registers is a fire() point of the core --\n";
$fires = preg_match_all("/\\bfire\\(\\s*'([A-Za-z]+)'/", $core, $m) ? array_values(array_unique($m[1])) : [];
check('the core fires the documented hook points', count($fires) >= 16 && in_array('styleLoad', $fires, true) && in_array('placeCard', $fires, true) && in_array('dirSheet', $fires, true) && in_array('navMoreHtml', $fires, true), count($fires) . ' fire() names');
$docS = strpos($core, 'HOOKS  ('); $docE = $docS !== false ? strpos($core, '*/', $docS) : false;
$doc = ($docS !== false && $docE !== false) ? substr($core, $docS, $docE - $docS) : '';
$undoc = array_values(array_filter($fires, static fn (string $h): bool => !preg_match('/^\s+' . preg_quote($h, '/') . '\s{2,}/m', $doc)));
check('every fire() point is listed in the HOOKS comment', $doc !== '' && $undoc === [], $undoc === [] ? '' : implode(', ', $undoc));
check('hook() and fire() are defined once, in the core', substr_count($core, 'function hook(name, fn)') === 1 && substr_count($core, 'function fire(name, a, b, c)') === 1 && !str_contains($allJs, 'function hook(') && !str_contains($allJs, 'function fire('));
$usedHooks = [];
foreach ($JS as $n => $blk) {
    $hs = preg_match_all("/\\bhook\\(\\s*'([A-Za-z]+)'/", $blk, $m) ? array_values(array_unique($m[1])) : [];
    $usedHooks[$n] = $hs;
    $bad = array_values(array_diff($hs, $fires));
    check("$n registers only hook names the core fires", $bad === [], $bad === [] ? (count($hs) . ' hooks: ' . implode(' ', $hs)) : 'unknown: ' . implode(', ', $bad));
}
if ($JS === []) { skip('module hook names', 'no module spliced'); }

echo "\n-- 4. module hygiene --\n";
if ($JS === []) {
    skip('module hygiene', 'no module spliced');
} else {
    $coreNames = topNames($core);
    $coreFns = preg_match_all('/^function\s+([A-Za-z_][\w]*)/m', $core, $m) ? array_values(array_unique($m[1])) : [];
    $PREFIX = ['KB' => '/^kb/i', 'AI' => '/^ai/i', 'TEMPLES' => '/^(tp|temples)/i', 'BORDER' => '/^(bd|border|cost)/i', 'SHGROUTE' => '/^(sr|shg)/i', 'I18N' => '/^(ui|i18n)/i'];
    $GLOBALS_OF = ['AI' => 'AI', 'TEMPLES' => 'TEMPLES', 'BORDER' => 'BORDER', 'SHGROUTE' => 'SHG'];
    /* P2: the one history write a module may do — ai.js pushes {lvl, d, ai: 1} when the panel opens */
    $P2_PUSH = '/pushState\s*\(\s*\{[^}]*\blvl\s*:\s*S\.level\b[^}]*\bd\s*:\s*S\.depth\b[^}]*\bai\s*:\s*1\b[^}]*\}\s*,\s*\'\'\s*\)/';
    $seen = [];
    foreach ($JS as $n => $blk) {
        $names = topNames($blk);
        $clash = array_values(array_intersect($names, $coreNames));
        check("$n redeclares no core name", $clash === [], $clash === [] ? count($names) . ' top-level names' : implode(', ', $clash));
        $dup = [];
        foreach ($names as $x) { if (isset($seen[$x])) { $dup[] = $x . ' (also ' . $seen[$x] . ')'; } else { $seen[$x] = $n; } }
        check("$n declares no name another module declares", $dup === [], implode(', ', $dup));
        $unpref = array_values(array_filter($names, static fn (string $x): bool => !preg_match($PREFIX[$n], $x)));
        check("$n prefixes its names with its module tag", $unpref === [], implode(', ', array_slice($unpref, 0, 8)));
        if ($coreFns !== []) {
            $re = '/^\s*(?:' . implode('|', array_map(static fn (string $f): string => preg_quote($f, '/'), $coreFns)) . ')\s*=(?!=)/m';
            check("$n never reassigns a core function (no monkey-patching)", !preg_match($re, $blk, $mm), isset($mm[0]) ? trim($mm[0]) : '');
        }
        check("$n has no console.log", !preg_match('/console\.log\(/', $blk));
        $plain = stripJs($blk);
        $oc = preg_match('/[\w\)\]]\?\.(?=[A-Za-z_$\[\(])/', $plain); $nc = preg_match('/\?\?/', $plain);
        check("$n uses no optional chaining / nullish coalescing (ES2017)", !$oc && !$nc, ($oc ? '?. ' : '') . ($nc ? '??' : ''));
        check("$n never calls fetch() directly (fetchT with a timeout)", !preg_match('/(?<![\w$.])fetch\s*\(/', $blk));
        if (str_contains($blk, 'fetchT(')) {
            check("$n sends same-origin credentials and catches every fetchT", str_contains($blk, "credentials: 'same-origin'") && str_contains($blk, '.catch('));
        }
        check("$n never calls fire() (modules only hook())", !preg_match('/(?<![\w$.])fire\s*\(/', $blk));
        $hist = preg_match_all('/\b(?:pushState|replaceState)\s*\([^;]{0,200}/', $blk, $mm) ? $mm[0] : [];
        if ($n === 'AI') { $hist = array_values(array_filter($hist, static fn (string $s): bool => !preg_match($P2_PUSH, $s))); }
        check("$n writes no history" . ($n === 'AI' ? ' except the P2 open state pushState({lvl: S.level, d: S.depth, ai: 1}, \'\')' : ' (P2: only ai.js may, with ai: 1)'), $hist === [], $hist === [] ? '' : trim($hist[0]));
        $wins = preg_match_all('/^.*\bwindow\.[A-Za-z_$][\w$]*\s*=(?!=).*$/m', $blk, $mm) ? $mm[0] : [];
        $leak = array_values(array_filter($wins, static fn (string $l): bool => !str_contains($l, 'debug')));
        check("$n writes window.x only for the ?debug handle", $leak === [], $leak === [] ? '' : trim($leak[0]));
        $keys = preg_match_all("/\\bLS\\.(?:get|set)\\(\\s*'([^']*)'/", $blk, $mm) ? array_values(array_unique($mm[1])) : [];
        $badKeys = array_values(array_filter($keys, static fn (string $k): bool => !str_starts_with($k, 'shg:nm:')));
        check("$n keeps its storage keys under shg:nm:", $badKeys === [], $badKeys === [] ? (count($keys) . ' keys') : implode(', ', $badKeys));
        $kd = preg_match_all("/addEventListener\\(\\s*'keydown'[\\s\\S]{0,1500}?\\}\\s*(?:,\\s*(?:true|\\{[^}]*\\}))?\\s*\\)\\s*;/", $blk, $mm) ? $mm[0] : [];
        $kdBad = array_values(array_filter($kd, static fn (string $s): bool => !preg_match('/\btrue\s*\)|capture\s*:\s*true/', $s)));
        if ($kd !== []) { check("$n adds its keydown listener with capture (A9: Escape before the core's back())", $kdBad === []); }
        if (str_contains($blk, 'maplibregl.Marker')) {
            check("$n wraps its DOM markers (wrapM) — no transform on the Marker element", str_contains($blk, 'wrapM('));
        }
        if (str_contains($blk, 'addSource(')) {
            check("$n re-adds its map source on styleLoad", in_array('styleLoad', $usedHooks[$n] ?? [], true));
        }
        if ($n !== 'KB') {
            $unguarded = [];
            foreach ($GLOBALS_OF as $other => $g) {
                if ($other === $n) { continue; }
                if (preg_match('/(?<![\w$.])' . $g . '\s*\./', $blk) && !preg_match('/typeof\s+' . $g . '\b/', $blk)) { $unguarded[] = $g; }
            }
            if ($n !== 'BORDER' && preg_match('/(?<![\w$.])COST\s*\./', $blk) && !preg_match('/typeof\s+COST\b/', $blk)) { $unguarded[] = 'COST'; }
            check("$n guards its calls into other modules with typeof", $unguarded === [], implode(', ', $unguarded));
        }
    }
    check('no phone number literal in any module block', !preg_match('/(?<![\d.])9\d{9}(?![\d.])/', $allMod, $mm), isset($mm[0]) ? $mm[0] : '');
    check('no Google / Overpass / Open-Meteo URL in any module', !preg_match('~googleapis\.com|maps\.google|google\.com/maps|overpass|open-meteo~i', $allMod, $mm), isset($mm[0]) ? $mm[0] : '');
    check('no placeCard hook replaces #placeX (A16: append with insertAdjacentHTML)', !preg_match('/placeX[\'"]?\)?\.innerHTML\s*=/', $allJs));
    $CSS_PREFIX = ['AI' => '/\.ai-|#ai[A-Z]|body\[data-lvl|:root/', 'TEMPLES' => '/\.tp-|#cTemples|:root/', 'BORDER' => '/\.bd-|#bd[A-Z]/', 'SHGROUTE' => '/\.sr-|#sr[A-Z]/', 'I18N' => '/\.i18n-|\.ui-|body\.|html\[lang|:root/'];
    foreach ($CSS_PREFIX as $n => $re) {
        if ($ST['CSS:' . $n] !== 'spliced') { continue; }
        $sels = selectors($B['CSS:' . $n]);
        $foreign = array_values(array_filter($sels, static fn (string $s): bool => !preg_match($re, $s)));
        check("$n css styles only its own classes", $foreign === [], $foreign === [] ? count($sels) . ' selectors' : implode(' | ', array_slice($foreign, 0, 4)));
    }
}

echo "\n-- 5. KB (evaluated with node: fake S = {lang:'ne'} + the spliced block) --\n";
$kb = null; $probe = [];
if (!isset($JS['KB'])) {
    skip('KB checks', 'KB not spliced');
} elseif ($node === '') {
    skip('KB checks', 'node not found');
} else {
    $blk = $JS['KB'];
    check('KB is data only: no hook(), fetch, DOM or window access', !preg_match('/\bhook\(|\bfetch|\bdocument\.|\$\(|\bwindow\b|addEventListener/', (string) preg_replace('~/\*[\s\S]*?\*/~', '', $blk), $mm), isset($mm[0]) ? $mm[0] : '');
    check("KB.v is '20261021a'", (bool) preg_match("/\\bv:\\s*'20261021a'/", $blk));
    $evalJs = "'use strict';\nvar S = { lang: 'ne' };\n" . $blk . "\n" . <<<'JS'
(function () {
  var fs = require('fs');
  var p = { ne: 'क', hi: 'ख', en: 'c' }, probe = {};
  S.lang = 'ne'; probe.tNe = KB.t(p);
  S.lang = 'hi'; probe.tHi = KB.t(p);
  S.lang = 'xx'; probe.tFallback = KB.t(p);
  probe.tStr = KB.t('plain'); probe.tNull = KB.t(null);
  var rep = function (k, v) { return v instanceof RegExp ? v.source : (typeof v === 'function' ? '[fn]' : v); };
  fs.writeFileSync(process.argv[2], JSON.stringify({ probe: probe, kb: KB }, rep));
})();
JS;
    $evalFile = $tmpDir . DIRECTORY_SEPARATOR . 'kb-eval.js'; $outFile = $tmpDir . DIRECTORY_SEPARATOR . 'kb.json';
    file_put_contents($evalFile, $evalJs);
    $r = run([$node, $evalFile, $outFile]);
    $dump = $r['rc'] === 0 && is_file($outFile) ? json_decode((string) file_get_contents($outFile), true) : null;
    check('the KB block evaluates standalone (no core dependency at load time)', is_array($dump) && is_array($dump['kb'] ?? null), is_array($dump) ? '' : firstLine($r['out']) . ' | ' . (explode("\n", $r['out'])[1] ?? ''));
    $kb = is_array($dump) && is_array($dump['kb'] ?? null) ? $dump['kb'] : null;
    $probe = is_array($dump) ? ($dump['probe'] ?? []) : [];
}
if (is_array($kb)) {
    check("KB.t() picks S.lang and falls back to en; plain strings and null pass through", ($probe['tNe'] ?? '') === 'क' && ($probe['tHi'] ?? '') === 'ख' && ($probe['tFallback'] ?? '') === 'c' && ($probe['tStr'] ?? '') === 'plain' && ($probe['tNull'] ?? 'x') === '');
    /* temples */
    $T = is_array($kb['temples'] ?? null) ? $kb['temples'] : [];
    $ids = []; $dupIds = []; $badCoord = []; $badTri = []; $badCountry = []; $badAlias = []; $badLists = []; $np = 0; $in = 0;
    foreach ($T as $i => $t) {
        $id = is_string($t['id'] ?? null) ? $t['id'] : ('#' . $i);
        if (isset($ids[$id])) { $dupIds[] = $id; } $ids[$id] = true;
        $lat = $t['lat'] ?? null; $lng = $t['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng) || $lat < 5 || $lat > 37 || $lng < 68 || $lng > 98) { $badCoord[] = $id; }
        foreach (['name', 'city', 'deity', 'timing', 'entry'] as $k) { if (!tri($t[$k] ?? null)) { $badTri[] = "$id.$k"; } }
        if (($t['country'] ?? '') === 'NP') { $np++; } elseif (($t['country'] ?? '') === 'IN') { $in++; } else { $badCountry[] = $id; }
        if (!is_array($t['aliases'] ?? null) || $t['aliases'] === []) { $badAlias[] = $id; }
        foreach (['rules', 'festivals', 'nearby', 'aarti'] as $k) {
            if (!array_key_exists($k, $t)) { if ($k !== 'aarti' && $k !== 'nearby') { $badLists[] = "$id.$k"; } continue; }
            if (!isset($t[$k]) && $k === 'nearby') { continue; }
            if (!is_array($t[$k] ?? null)) { $badLists[] = "$id.$k"; continue; }
            foreach ($t[$k] as $j => $e) { if (!tri($e)) { $badLists[] = "$id.$k[$j]"; break; } }
        }
        foreach (['tips', 'season'] as $k) { if (isset($t[$k]) && !tri($t[$k])) { $badLists[] = "$id.$k"; } }
    }
    check('KB.temples has ≥ 28 entries (P18 prefers ~38)', count($T) >= 28, count($T) . " temples (NP $np, IN $in)");
    check('temple ids are unique slugs', $dupIds === [] && !preg_match('/[^a-z0-9-]/', implode('', array_keys($ids))), implode(', ', $dupIds));
    check('every temple sits inside lat 5–37 / lng 68–98', $badCoord === [], implode(', ', $badCoord));
    check('every temple has name, city, deity, timing, entry in ne / hi / en', $badTri === [], implode(', ', array_slice($badTri, 0, 6)));
    check("every temple carries country 'NP' or 'IN'", $badCountry === [], implode(', ', $badCountry));
    check('every temple has search aliases', $badAlias === [], implode(', ', $badAlias));
    check('rules / festivals / nearby (and aarti, tips, season when present) are trilingual', $badLists === [], implode(', ', array_slice($badLists, 0, 6)));
    $hay = array_map(static fn (array $t): string => strtolower((string) json_encode([$t['id'] ?? '', $t['name'] ?? '', $t['aliases'] ?? ''], JSON_UNESCAPED_UNICODE)), $T);
    $findT = static function (array $need) use ($hay): array { $miss = []; foreach ($need as $label => $re) { $hit = false; foreach ($hay as $h) { if (preg_match('/' . $re . '/', $h)) { $hit = true; break; } } if (!$hit) { $miss[] = $label; } } return $miss; };
    $NEED_T = ['Pashupatinath' => 'pashupati', 'Swayambhunath' => 'swayambhu', 'Boudhanath' => 'boudha|bouddha|baudha', 'Budhanilkantha' => 'budhanil', 'Dakshinkali' => 'dakshin', 'Changu Narayan' => 'changu',
        'Manakamana' => 'manakamana', 'Bindhyabasini' => 'bindh?ya|bindab', 'Muktinath' => 'muktinath', 'Janaki Mandir' => 'janaki', 'Lumbini / Maya Devi' => 'lumbini|maya ?devi', 'Bageshwori' => 'bagesh',
        'Devghat' => 'devghat', 'Gadhimai' => 'gadhimai',
        'Somnath' => 'somnath', 'Dwarkadhish' => 'dwarka', 'Nageshwar' => 'nagesh', 'Akshardham' => 'akshardham', 'Ambaji' => 'ambaji', 'Pavagadh' => 'pavagadh', 'Modhera' => 'modhera',
        'Bahucharaji' => 'bahuchar', 'Umiya Mata' => 'umiya', 'Dakor' => 'dakor', 'Salangpur' => 'salangpur|sarangpur', 'Kashi Vishwanath' => 'vishwanath|vishvanath|kashi', 'Ayodhya Ram Mandir' => 'ayodhya', 'Gorakhnath' => 'gorakhnath', 'Vindhyavasini' => 'vindhya'];
    $missT = $findT($NEED_T);
    check('the required pilgrimage set is present (14 Nepal + 15 India)', $missT === [], $missT === [] ? count($NEED_T) . ' found' : 'missing: ' . implode(', ', $missT));
    /* P18: the corridor Surat → Udaipur → Kota → Jhansi → Kanpur → Lucknow → Rupaidiha gets its temples; the far-east Nepal three may go */
    $NEED_P18 = ['Nathdwara Shrinathji' => 'nathdwara|shrinath', 'Eklingji' => 'eklingj|eklinji', 'Orchha Ram Raja' => 'orchha|ram ?raja', 'Hanuman Setu (Lucknow)' => 'hanuman ?setu', 'JK Temple (Kanpur)' => 'jk ?temple|j\.k\. ?temple|jk-temple|radhakrishna',
        'Naimisharanya' => 'naimish', 'Khatu Shyamji' => 'khatu', 'Mehandipur Balaji' => 'mehandipur|mehndipur'];
    $missP = $findT($NEED_P18);
    check('P18: the corridor temples are present (Nathdwara, Eklingji, Orchha, Hanuman Setu, JK Temple, Naimisharanya, Khatu Shyamji, Mehandipur Balaji)', $missP === [], $missP === [] ? count($NEED_P18) . ' found' : 'missing: ' . implode(', ', $missP));
    $optT = $findT(['Pathibhara' => 'pathibhara', 'Halesi' => 'halesi', 'Baraha Kshetra' => 'baraha']);
    echo "  (east-Nepal three: " . ($optT === [] ? 'all kept' : 'dropped ' . implode(', ', $optT) . ' — allowed by P18') . ")\n";
    /* P17: the facts the review pinned */
    $byId = []; foreach ($T as $t) { if (is_string($t['id'] ?? null)) { $byId[$t['id']] = $t; } }
    $pick = static function (string $re) use ($T): ?array { foreach ($T as $t) { if (preg_match('/' . $re . '/i', (string) json_encode([$t['id'] ?? '', $t['name'] ?? '', $t['aliases'] ?? ''], JSON_UNESCAPED_UNICODE))) { return $t; } } return null; };
    $pash = $pick('pashupati'); $bet = $pick('bet ?dwarka|beyt'); $gad = $pick('gadhimai'); $muk = $pick('muktinath');
    $pashRule0 = is_array($pash) && is_array($pash['rules'] ?? null) && isset($pash['rules'][0]) ? strtolower((string) json_encode($pash['rules'][0], JSON_UNESCAPED_UNICODE)) : '';
    check('P17: Pashupatinath rules[0] = only Hindus enter the main temple; aarti listed; approx flagged', $pashRule0 !== '' && (bool) preg_match('/hindu|हिन्दू|हिंदू/u', $pashRule0) && is_array($pash['aarti'] ?? null) && $pash['aarti'] !== [] && ($pash['approx'] ?? false) === true);
    $betJ = is_array($bet) ? strtolower((string) json_encode($bet, JSON_UNESCAPED_UNICODE)) : '';
    check('P17: Bet Dwarka says Sudarshan Setu bridge (no boat); Gadhimai says the fair is five-yearly (next ≈ 2029); Muktinath 3,710 m + ACAP', (bool) preg_match('/sudarshan|सुदर्शन/u', $betJ) && is_array($gad) && str_contains((string) json_encode($gad, JSON_UNESCAPED_UNICODE), '2029') && is_array($muk) && (int) ($muk['altitude'] ?? 0) === 3710 && (bool) preg_match('/acap|annapurna|अन्नपूर्ण/iu', (string) json_encode($muk, JSON_UNESCAPED_UNICODE)), is_array($bet) ? '' : 'Bet Dwarka entry not found');
    /* borders */
    $Bd = is_array($kb['borders'] ?? null) ? $kb['borders'] : [];
    $bIds = []; $bDup = []; $bBad = []; $shgCount = 0; $rupShg = false; $rup = null; $noApprox = []; $noIst = [];
    foreach ($Bd as $i => $b) {
        $id = is_string($b['id'] ?? null) ? $b['id'] : ('#' . $i);
        if (isset($bIds[$id])) { $bDup[] = $id; } $bIds[$id] = true;
        foreach (['india', 'nepal'] as $side) {
            $s = $b[$side] ?? null;
            if (!is_array($s) || !is_numeric($s['lat'] ?? null) || !is_numeric($s['lng'] ?? null) || $s['lat'] < 26 || $s['lat'] > 30.5 || $s['lng'] < 80 || $s['lng'] > 89) { $bBad[] = "$id.$side"; }
            elseif (!tri($s['name'] ?? null)) { $bBad[] = "$id.$side.name"; }
        }
        if (!isPair($b['gate'] ?? null) || $b['gate'][0] < 80 || $b['gate'][0] > 89 || $b['gate'][1] < 26 || $b['gate'][1] > 30.5) { $bBad[] = "$id.gate"; }
        foreach (['name', 'hours', 'vehicles', 'money'] as $k) { if (!tri($b[$k] ?? null)) { $bBad[] = "$id.$k"; } }
        foreach (['docs', 'tips'] as $k) {
            if (!is_array($b[$k] ?? null) || $b[$k] === []) { $bBad[] = "$id.$k"; continue; }
            foreach ($b[$k] as $j => $e) { if (!tri($e)) { $bBad[] = "$id.$k[$j]"; break; } }
        }
        $atmOk = static fn ($v): bool => is_string($v) || tri($v);
        if (!is_array($b['atm'] ?? null) || !$atmOk($b['atm']['india'] ?? null) || !$atmOk($b['atm']['nepal'] ?? null)) { $bBad[] = "$id.atm"; }
        if (!is_array($b['aliases'] ?? null) || $b['aliases'] === []) { $bBad[] = "$id.aliases"; }
        if (($b['approx'] ?? false) !== true) { $noApprox[] = $id; }
        $tipsJ = (string) json_encode($b['tips'] ?? [], JSON_UNESCAPED_UNICODE);
        if (!preg_match('/15|१५/u', $tipsJ) || !preg_match('/IST|NPT|भारतीय समय|नेपाली समय|नेपाल समय/u', $tipsJ)) { $noIst[] = $id; }
        $isRup = (bool) preg_match('/rupaidiha|nepalgunj|रूपैडिहा|रुपईडीहा|नेपालगञ्ज|नेपालगंज/iu', (string) json_encode([$id, $b['name'] ?? '', $b['aliases'] ?? ''], JSON_UNESCAPED_UNICODE));
        if ($isRup && $rup === null) { $rup = $b; }
        if (($b['shg'] ?? false) === true) { $shgCount++; if ($isRup) { $rupShg = true; } }
    }
    check('KB.borders has the 7 crossings', count($Bd) >= 7 && $bDup === [], count($Bd) . ' crossings' . ($bDup ? ' dup: ' . implode(', ', $bDup) : ''));
    check('every crossing has india / nepal points, gate [lng,lat], hours, vehicles, money, docs, tips, atm, aliases', $bBad === [], implode(', ', array_slice($bBad, 0, 6)));
    $NEED_B = ['Rupaidiha–Nepalgunj' => 'rupaidiha|nepalgunj', 'Sunauli–Bhairahawa' => 'sunauli|bhairahawa|belahiya', 'Raxaul–Birgunj' => 'raxaul|birgunj', 'Jogbani–Biratnagar' => 'jogbani|biratnagar',
        'Panitanki–Kakarbhitta' => 'panitanki|kakar', 'Banbasa–Mahendranagar' => 'banbasa|gaddachauki|mahendranagar|bhimdatta', 'Gauriphanta–Dhangadhi' => 'gauriphanta|dhangadhi'];
    $bHay = array_map(static fn (array $b): string => strtolower((string) json_encode([$b['id'] ?? '', $b['name'] ?? '', $b['aliases'] ?? ''], JSON_UNESCAPED_UNICODE)), $Bd);
    $missB = [];
    foreach ($NEED_B as $label => $re) { $hit = false; foreach ($bHay as $h) { if (preg_match('/' . $re . '/', $h)) { $hit = true; break; } } if (!$hit) { $missB[] = $label; } }
    check('the 7 named crossings are all there', $missB === [], 'missing: ' . implode(', ', $missB));
    check('Rupaidiha–Nepalgunj is flagged shg: true (the S Hari crossing); only that one', $rupShg && $shgCount === 1, $shgCount . ' shg crossings');
    /* P3: the route_stops pin 28.0600, 81.6170 sits inside Nepalgunj; the real crossing is near 28.02 N 81.62 E */
    if (is_array($rup) && isPair($rup['gate'] ?? null)) {
        $g = $rup['gate']; $dWrong = havKm(81.617, 28.06, (float) $g[0], (float) $g[1]); $dReal = havKm(81.62, 28.02, (float) $g[0], (float) $g[1]);
        check('P3: the Rupaidiha gate is NOT the route_stops pin (28.06, 81.617) but near 28.02 N 81.62 E', $dWrong > 2.0 && $dReal < 4.0, sprintf('gate %.4f N %.4f E — %.1f km from the wrong pin, %.1f km from the real crossing', $g[1], $g[0], $dWrong, $dReal));
        $iLat = (float) ($rup['india']['lat'] ?? 0); $nLat = (float) ($rup['nepal']['lat'] ?? 0);
        check('P3: india point (Rupaidiha bazaar) south of the gate, nepal point (Jamunaha / Bhansar) north of it', $iLat < (float) $g[1] && $nLat > (float) $g[1], sprintf('india %.4f < gate %.4f < nepal %.4f', $iLat, $g[1], $nLat));
    } else { check('P3: the Rupaidiha crossing with a gate [lng,lat] exists', false, 'not found'); }
    check('P16: every crossing is marked approx: true', $noApprox === [], implode(', ', $noApprox));
    check('P5: every crossing\'s tips carry "Nepal time = IST + 15 min"', $noIst === [], implode(', ', $noIst));
    $bj = strtolower((string) json_encode($Bd, JSON_UNESCAPED_UNICODE));
    check('P16 documents: passport / voter ID; Aadhaar only under 15 and over 65; bhansar on the Nepal side', (bool) preg_match('/passport|पासपोर्ट/u', $bj) && (bool) preg_match('/voter|मतदाता|election/u', $bj) && (bool) preg_match('/aadhaa?r|आधार/u', $bj) && (bool) preg_match('/\b15\b|१५/u', $bj) && (bool) preg_match('/\b65\b|६५/u', $bj) && (bool) preg_match('/bhansar|भन्सार|भंसार/u', $bj));
    check('P16 money: ₹200 / ₹500 not accepted, carry ₹100 notes — and NO ₹2000 anywhere (withdrawn 2023)', (bool) preg_match('/\b500\b|५००/u', $bj) && (bool) preg_match('/\b200\b|२००/u', $bj) && (bool) preg_match('/\b100\b|१००/u', $bj) && !preg_match('/2000|२०००/u', $bj), preg_match('/2000|२०००/u', $bj) ? 'mentions 2000' : '');
    check('P16 third-country: visa on arrival, USD 30 / 50 / 125, authorised ICP', (bool) preg_match('/visa|भिसा|वीज़ा|वीजा/u', $bj) && (bool) preg_match('/\b125\b|१२५/u', $bj) && (bool) preg_match('/\bicp\b|check ?post|चेक ?पोस्ट/u', $bj));
    /* emergency, fx, shg, cats, lex, ui, quick */
    $em = is_array($kb['emergency'] ?? null) ? $kb['emergency'] : [];
    $emBad = []; $walk = static function ($v, string $p, array &$bad, int &$n) use (&$walk): void {
        if (is_array($v) && (isset($v['ne']) || isset($v['hi']) || isset($v['en']))) { $n++; if (!tri($v)) { $bad[] = $p; } return; }
        if (is_array($v)) { foreach ($v as $k => $x) { $walk($x, "$p.$k", $bad, $n); } }
    };
    $emN = 0; $walk($em, 'emergency', $emBad, $emN);
    $emj = (string) json_encode($em, JSON_UNESCAPED_UNICODE);
    check('KB.emergency has NP and IN with trilingual labels and the real numbers (100, 102, 112, 1144)', isset($em['NP'], $em['IN']) && $emBad === [] && $emN >= 8 && str_contains($emj, '100') && str_contains($emj, '102') && str_contains($emj, '112') && str_contains($emj, '1144'), $emBad ? implode(', ', $emBad) : "$emN labels");
    check('P25: the SIM line per country (112 from any Indian SIM; Nepal numbers need a Nepali SIM / roaming)', (bool) preg_match('/\bsim\b|सिम/iu', $emj) && (bool) preg_match('/roaming|रोमिङ|रोमिंग/iu', $emj));
    $fx = is_array($kb['fx'] ?? null) ? $kb['fx'] : [];
    check('KB.fx: 1 INR = 1.60 NPR, trilingual text', is_numeric($fx['inrToNpr'] ?? null) && abs((float) $fx['inrToNpr'] - 1.6) < 1e-9 && tri($fx['text'] ?? null));
    $shg = is_array($kb['shg'] ?? null) ? $kb['shg'] : [];
    check("KB.shg carries NO phone / WhatsApp number (phone '' and wa '' — facts come from nav-ai.php)", ($shg['phone'] ?? 'x') === '' && ($shg['wa'] ?? 'x') === '');
    check("KB.shg: name 'S Hari Global' (P13, company overrides at runtime), site, book '/' and track '/#/track'", ($shg['name'] ?? '') === 'S Hari Global' && str_contains((string) ($shg['site'] ?? ''), 'shreehariglobal.in') && ($shg['book'] ?? '') === '/' && ($shg['track'] ?? '') === '/#/track');
    $cor = $shg['corridor'] ?? null;
    check("P4: KB.shg.corridor is a trilingual '{from} – {to}' template (the timetable fills it)", tri($cor) && str_contains($cor['ne'], '{from}') && str_contains($cor['ne'], '{to}') && str_contains($cor['hi'], '{from}') && str_contains($cor['hi'], '{to}') && str_contains($cor['en'], '{from}') && str_contains($cor['en'], '{to}'));
    $cats = is_array($kb['cats'] ?? null) ? $kb['cats'] : [];
    $ALLOW_Q = ['restaurant', 'dhaba', 'petrol', 'atm', 'hospital', 'pharmacy', 'police', 'hotel', 'parking', 'toilet', 'bus stand', 'temple', 'bank'];
    $catBad = [];
    foreach ($cats as $k => $c) {
        if (!is_array($c) || !in_array($c['q'] ?? '', $ALLOW_Q, true) || !is_string($c['e'] ?? null) || $c['e'] === '' || !tri($c['l'] ?? null)) { $catBad[] = (string) $k; }
    }
    $catMiss = array_values(array_diff(['food', 'fuel', 'atm', 'hospital', 'pharmacy', 'police', 'hotel', 'parking', 'toilet', 'busstand', 'temple'], array_keys($cats)));
    check('KB.cats: the 11 categories, each {q (a nav-search word), e, l{ne,hi,en}}', $catMiss === [] && $catBad === [], ($catMiss ? 'missing: ' . implode(', ', $catMiss) . ' ' : '') . ($catBad ? 'bad: ' . implode(', ', $catBad) : count($cats) . ' cats'));
    $lex = is_array($kb['lex'] ?? null) ? $kb['lex'] : [];
    $lexMiss = [];
    foreach (['temple', 'border', 'food', 'fuel', 'atm', 'hospital', 'police', 'hotel', 'toilet', 'parking', 'emergency', 'weather', 'currency', 'distance', 'howto', 'nearest', 'bus', 'time', 'greet', 'thanks', 'help', 'cancel', 'track'] as $k) {
        if (!is_array($lex[$k] ?? null) || $lex[$k] === []) { $lexMiss[] = $k; }
    }
    $lexJ = strtolower((string) json_encode($lex, JSON_UNESCAPED_UNICODE));
    check('KB.lex covers the 21 intents + cancel + track (P15), each a non-empty keyword list', $lexMiss === [], $lexMiss === [] ? count($lex) . ' intents' : 'missing: ' . implode(', ', $lexMiss));
    check('KB.lex mixes Devanagari, Romanised and English (मन्दिर / mandir / temple, सीमा / naka / border)', str_contains($lexJ, 'मन्दिर') && str_contains($lexJ, 'mandir') && str_contains($lexJ, 'temple') && str_contains($lexJ, 'naka') && str_contains($lexJ, 'border'));
    check('P15 words: refund / रद्द, where is my bus, kati baje / बजे, aadhar / passport, aarti / आरती, meal halt', (bool) preg_match('/refund|रद्द|radda|रिफन्ड/u', $lexJ) && (bool) preg_match('/where is my bus|मेरो बस|meri bus|bus kaha/u', $lexJ) && (bool) preg_match('/baje|बजे/u', $lexJ) && (bool) preg_match('/aadhaa?r|आधार|passport|पासपोर्ट/u', $lexJ) && (bool) preg_match('/aa?rti|आरती/u', $lexJ) && (bool) preg_match('/halt|रोकिन्छ|rukti/u', $lexJ));
    $ui = is_array($kb['ui'] ?? null) ? $kb['ui'] : [];
    $uiBad = []; triDeep($ui, 'ui', $uiBad);
    check('KB.ui: every panel string exists in ne, hi AND en', $ui !== [] && count($ui) >= 15 && $uiBad === [], $uiBad === [] ? count($ui) . ' strings' : implode(', ', array_slice($uiBad, 0, 6)));
    $uiJ = strtolower((string) json_encode($ui, JSON_UNESCAPED_UNICODE));
    check('KB.ui carries the WhatsApp / privacy / offline lines', str_contains($uiJ, 'whatsapp') && (str_contains($uiJ, 'location') || str_contains($uiJ, 'लोकेसन') || str_contains($uiJ, 'स्थान')) && (str_contains($uiJ, 'offline') || str_contains($uiJ, 'अफलाइन') || str_contains($uiJ, 'ऑफलाइन')));
    $uiNeed = array_values(array_filter(['cta', 'privacy', 'micOffline', 'wxOffline'], static fn (string $k): bool => !tri($ui[$k] ?? null)));
    check("KB.ui.cta 'Navigate?' (P19), privacy '≈ 1 km' (P10), micOffline + wxOffline (P20)", $uiNeed === [] && (bool) preg_match('/navigate/i', (string) json_encode($ui['cta'] ?? '')) && (bool) preg_match('/1 ?km|१ ?किमी|१ ?km/u', (string) json_encode($ui['privacy'] ?? '', JSON_UNESCAPED_UNICODE)), $uiNeed ? 'missing: ' . implode(', ', $uiNeed) : '');
    $quick = is_array($kb['quick'] ?? null) ? $kb['quick'] : [];
    $qBad = [];
    foreach ($quick as $i => $q) { if (!is_array($q) || !is_string($q['id'] ?? null) || !is_string($q['e'] ?? null) || !tri($q['l'] ?? null) || !tri($q['q'] ?? null)) { $qBad[] = (string) ($q['id'] ?? $i); } }
    $qIds = array_map(static fn ($q): string => (string) ($q['id'] ?? ''), $quick); sort($qIds);
    check('KB.quick: the 6 buttons (temple, border, food, fuel, emergency, bus) with trilingual label + question', count($quick) === 6 && $qBad === [] && $qIds === ['border', 'bus', 'emergency', 'food', 'fuel', 'temple'], $qBad ? 'bad: ' . implode(', ', $qBad) : implode(' ', $qIds));
    /* the Nepal polygon and BORDER.inNepal()'s self-test */
    $ring = is_array($kb['nepal'] ?? null) ? $kb['nepal'] : [];
    $ringOk = count($ring) >= 50;
    foreach ($ring as $p) { if (!isPair($p) || $p[0] < 80 || $p[0] > 88.3 || $p[1] < 26.3 || $p[1] > 30.5) { $ringOk = false; break; } }
    check('KB.nepal is a [lng,lat] ring of ≥ 50 vertices inside Nepal\'s bounding box', $ringOk, count($ring) . ' vertices');
    if ($ringOk) {
        $FIX = [['Kathmandu', 85.3240, 27.7172, true], ['Pokhara', 83.9856, 28.2096, true], ['Nepalgunj', 81.6167, 28.0500, true], ['Biratnagar', 87.2718, 26.4525, true], ['Janakpur', 85.9250, 26.7288, true], ['Dhangadhi', 80.5898, 28.6833, true],
            ['Lucknow', 80.9462, 26.8467, false], ['Gorakhpur', 83.3732, 26.7606, false], ['Patna', 85.1376, 25.5941, false], ['Siliguri', 88.4280, 26.7271, false], ['Bahraich', 81.5940, 27.5742, false], ['Motihari', 84.9167, 26.6500, false], ['Pithoragarh', 80.2167, 29.5833, false], ['Darjeeling', 88.2636, 27.0410, false], ['Shigatse', 88.8800, 29.2700, false]];
        $fixBad = [];
        foreach ($FIX as [$nm, $x, $y, $want]) { if (inPoly($ring, $x, $y) !== $want) { $fixBad[] = $nm; } }
        check('ray-cast: Kathmandu, Pokhara, Nepalgunj, Biratnagar, Janakpur, Dhangadhi inside; Lucknow, Gorakhpur, Patna, Siliguri, Bahraich, Motihari, Pithoragarh, Darjeeling, Shigatse outside', $fixBad === [], implode(', ', $fixBad));
        /* P23: ≥ 1 km accuracy at the S Hari crossing */
        $p23 = [];
        if (!inPoly($ring, 81.6016, 28.0200)) { $p23[] = 'Nepalgunj Bhansar office 28.0200, 81.6016 should be NP'; }
        if (inPoly($ring, 81.62, 28.01)) { $p23[] = 'Rupaidiha bazaar 28.01, 81.62 should be IN'; }
        check('P23 ray-cast: Nepalgunj Bhansar office (28.0200, 81.6016) inside, Rupaidiha bazaar (28.01, 81.62) outside', $p23 === [], implode('; ', $p23));
        $sideBad = [];
        foreach ($T as $t) {
            if (!is_numeric($t['lat'] ?? null) || !is_numeric($t['lng'] ?? null)) { continue; }
            $inside = inPoly($ring, (float) $t['lng'], (float) $t['lat']);
            if ((($t['country'] ?? '') === 'NP') !== $inside) { $sideBad[] = (string) ($t['id'] ?? '?'); }
        }
        check('ray-cast: every NP temple is inside the polygon, every IN temple outside', $sideBad === [], implode(', ', $sideBad));
        $gateBad = [];
        foreach ($Bd as $b) {
            $id = (string) ($b['id'] ?? '?');
            if (is_numeric($b['india']['lat'] ?? null) && inPoly($ring, (float) $b['india']['lng'], (float) $b['india']['lat'])) { $gateBad[] = "$id.india"; }
            if (is_numeric($b['nepal']['lat'] ?? null) && !inPoly($ring, (float) $b['nepal']['lng'], (float) $b['nepal']['lat'])) { $gateBad[] = "$id.nepal"; }
        }
        check("ray-cast (P23): every crossing's india town is outside and nepal town inside (14 gate towns)", $gateBad === [], implode(', ', $gateBad));
    }
}

echo "\n-- 6. per-module contracts --\n";
$exposes = static function (string $blk, string $obj, string $key): bool {
    return (bool) preg_match('/(?<![\w$.])' . preg_quote($key, '/') . '\s*:\s*(?:function\b|[A-Za-z_$!])|\b' . preg_quote($obj, '/') . '\.' . preg_quote($key, '/') . '\s*=(?!=)|defineProperty\(\s*' . preg_quote($obj, '/') . '\s*,\s*[\'"]' . preg_quote($key, '/') . '[\'"]/', $blk);
};
$hasAll = static function (string $src, array $res): array { $miss = []; foreach ($res as $label => $re) { if (!preg_match($re, $src)) { $miss[] = $label; } } return $miss; };
$aiAll = ($JS['AI'] ?? '') . "\n" . $B['HTML:AI'] . "\n" . $B['CSS:AI'];
$CONTRACT = [
    'AI' => [
        'exports' => ['AI' => ['open', 'close', 'ask', 'answer', 'isOpen']],
        'hooks'   => ['boot', 'show', 'back', 'lang', 'navMoreHtml', 'navMoreBind'],
        'has'     => [
            'asks the server through fetchT(AI_API …)' => ['fetchT(AI_API' => '/fetchT\(\s*AI_API/', 'facts=1' => '/facts=1/', 'POST' => "/method:\\s*'POST'/"],
            'caches facts (shg:nm:ai:facts) and the chat log (shg:nm:ai:log)' => ['facts' => '/shg:nm:ai:facts/', 'log' => '/shg:nm:ai:log/'],
            'P2 open: history.pushState({lvl: S.level, d: S.depth, ai: 1}, \'\') + AI._hist = true' => ['pushState ai:1' => '/pushState\s*\(\s*\{[^}]*\blvl\s*:\s*S\.level\b[^}]*\bai\s*:\s*1\b/', '_hist = true' => '/_hist\s*=\s*true/'],
            'P2 close: ✕ / Escape / closing action → if (AI._hist) { AI._hist = false; history.back(); }' => ['_hist=false; history.back()' => '/_hist\s*=\s*false;?\s*history\.back\(\)|if\s*\(\s*AI\._hist\s*\)\s*history\.back\(\)/'],
            'language switch mirrors the core (A13: shg:nm:lang, voStop, voPrune, relabel, loadPack(voPack()))' => ['LS' => "/LS\\.set\\(\\s*'shg:nm:lang'/", 'voStop' => '/\bvoStop\(/', 'voPrune' => '/\bvoPrune\(/', 'relabel' => '/\brelabel\(/', 'loadPack(voPack())' => '/loadPack\(\s*voPack\(\)\s*\)/'],
            'voice: TTS through sayText(), mic through SR, spoken while driving only when !muted(true) (P7)' => ['sayText' => '/\bsayText\(/', 'SR' => '/(?<![\w$.])SR\b/', 'muted(true)' => '/\bmuted\(\s*true\s*\)/'],
            'Navigate action sets S.from = {me:true…}, clears stops, then computeRoutes() on dir else push(\'dir\') (A2)' => ['from' => '/S\.from\s*=\s*\{\s*me:\s*true/', 'stops' => '/S\.stops\s*=\s*\[\]/', 'computeRoutes' => '/\bcomputeRoutes\(\)/', "push('dir')" => "/push\\('dir'\\)/"],
            'P1 never breaks a live drive: S.nav && !S.nav.preview guard, along-route rows, stopNav() only after the confirm bubble' => ['S.nav' => '/S\.nav\b/', 'preview' => '/\.preview\b/', 'stopNav' => '/\bstopNav\(/', 'alongSearch or 4-point SEARCH_API sampling' => '/\balongSearch\(|SEARCH_API/'],
            'P9: hook(show) closes the panel on a level change unless AI._self' => ['_self' => '/_self\b/'],
            'P10: the position sent to the server is rounded to 2 dp' => ['2 dp' => '/toFixed\(\s*2\s*\)|Math\.round\([^)]*\*\s*100\s*\)\s*\/\s*100/'],
            'P14: Devanagari tokens — NFC normalise, nukta stripped, ज़→ज, ं/ँ equal' => ['normalize NFC' => '/normalize\(\s*[\'"]NFC[\'"]\s*\)/', 'nukta' => '/\\\\u093[cC]|़/u', 'chandrabindu/anusvara' => '/\\\\u090[12]|ँ|ं/u'],
            'P15: cancel / refund → fixed office answer (never the server); track → /#/track' => ['/#/track' => '~/#/track~', 'cancel intent' => '/\bcancel\b/i'],
            'P19: KB.ui.cta is the primary action label' => ['KB.ui.cta' => '/KB\.ui\.cta\b|\bk:\s*\'cta\'/'],
            'P20: offline → answer from KB at once (navigator.onLine); server fetch 10 s; mic errors → KB.ui.micOffline' => ['navigator.onLine' => '/navigator\.onLine/', '10 s' => '/fetchT\(\s*AI_API[\s\S]{0,400}?\b(?:10000|10e3|1e4)\b/', 'micOffline' => '/micOffline/', 'not-allowed' => '/not-allowed/', 'language-not-supported' => '/language-not-supported/'],
            'P12: message cap 400 chars client-side' => ['400' => '/maxlength\s*=\s*["\']?400|slice\(\s*0\s*,\s*400\s*\)|substr\(\s*0\s*,\s*400\s*\)|length\s*>\s*400/'],
            'escapes every value it puts in HTML' => ['esc(' => '/\besc\(/'],
            'WhatsApp fallback uses the facts number (wa.me)' => ['wa.me' => '~wa\.me/~'],
        ],
        'hasAll'  => [
            '#aiBtn and #aiPanel exist (ai.html or built in JS), role=dialog, aria-live' => ['aiBtn' => '/aiBtn/', 'aiPanel' => '/aiPanel/', 'dialog' => '/role="dialog"|role=\'dialog\'|setAttribute\(\s*\'role\'\s*,\s*\'dialog\'/', 'aria-live' => '/aria-live/'],
            'P8 fab: bottom calc(100vh - var(--sheet-top) + 36px), hidden on data-lvl nav / search / pick' => ['calc' => '/calc\(\s*100vh\s*-\s*var\(--sheet-top\)\s*\+\s*36px\s*\)/', 'nav' => '/data-lvl\s*=\s*["\']?nav|body\[data-lvl=home\][^{]*\.ai-fab[^{]*\{display:/', 'search' => '/data-lvl\s*=\s*["\']?search|body\[data-lvl=home\][^{]*\.ai-fab[^{]*\{display:/', 'pick' => '/data-lvl\s*=\s*["\']?pick|body\[data-lvl=home\][^{]*\.ai-fab[^{]*\{display:/'],
            'P7 / P8 panel: ≤ 45 vh while driving, input row ≥ 56 px, auto-close 20 s after the answer' => ['45vh' => '/45\s*vh/', '56px' => '/56\s*px/', '20 s' => '/\b(?:20000|20e3|2e4)\b/'],
        ],
    ],
    'TEMPLES' => [
        'exports' => ['TEMPLES' => ['nearest', 'show', 'byId', 'focus', 'on']],
        'hooks'   => ['styleLoad', 'layersHtml', 'placeCard', 'show'],
        'has'     => [
            '#cTemples button, state in shg:nm:temples' => ['cTemples' => '/cTemples/', 'LS' => '/shg:nm:temples/'],
            'index temples from TEMPLE_API (bbox + near)' => ['TEMPLE_API' => '/fetchT\(\s*TEMPLE_API/', 'bbox=' => '/bbox=/', 'near=' => '/near=/'],
            'redraws on moveend (debounced) and skips the nav screen' => ['moveend' => "/'moveend'/", 'nav' => "/S\\.level\\s*===?\\s*'nav'/"],
            'place card appends for p.temple only (A16)' => ['insertAdjacentHTML' => "/insertAdjacentHTML\\(\\s*'beforeend'/", 'p.temple' => '/\.temple\b/'],
            'pins .tp-pin / labels .tp-label' => ['tp-pin' => '/tp-pin/', 'tp-label' => '/tp-label/'],
            'nearest() merges curated + index and sorts by hav()' => ['hav' => '/\bhav\(/'],
            'P22 budget: index pins ≤ 40, only at zoom ≥ 11, curated first' => ['40' => '/\b40\b/', '11' => '/\b11\b/'],
            'P17: the approx line "लगभग · चाडपर्वमा फरक हुन सक्छ" on the card (here or in KB.ui)' => ['approx' => '/\bapprox\b/'],
        ],
    ],
    'BORDER' => [
        'exports' => ['BORDER' => ['inNepal', 'country', 'crossing'], 'COST' => ['forRoute']],
        'hooks'   => ['dirSheet', 'navStart', 'navFix', 'navStop'],
        'has'     => [
            'inNepal() ray-casts KB.nepal' => ['KB.nepal' => '/KB\.nepal/'],
            'dirSheet fills #costBox (.bd-cost) and #xBox (.bd-card), pin .bd-pin' => ['costBox' => '/costBox/', 'xBox' => '/xBox/', 'bd-cost' => '/bd-cost/', 'bd-card' => '/bd-card/', 'bd-pin' => '/bd-pin/'],
            'crossing cached on r._bd' => ['_bd' => '/\._bd\b/'],
            'alerts at 5 km and 800 m: toast + vib + sayText, muted() respected, voStop() first, none in preview (A17)' => ['5000' => '/\b5000\b/', '800' => '/\b800\b/', 'toast' => '/\btoast\(/', 'vib' => '/\bvib\(/', 'sayText' => '/\bsayText\(/', 'muted' => '/\bmuted\(/', 'voStop' => '/\bvoStop\(/', 'preview' => '/\.preview\b/', '_bdDone' => '/_bdDone/'],
            'COST uses KB.fx.inrToNpr, kcal for walking, S Hari fare through SHG.fareBetween' => ['inrToNpr' => '/KB\.fx\.inrToNpr/', 'kcal' => '/kcal/', 'fareBetween' => '/fareBetween\(/'],
            'P6: car "fuel only" + tolls when r.tolls, 35 kcal/km walking, bicycle free' => ['r.tolls' => '/\.tolls\b/', '35' => '/\b35\b/'],
            'Details opens the modal' => ['openModal' => '/\bopenModal\(/'],
        ],
    ],
    'SHGROUTE' => [
        'exports' => ['SHG' => ['data', 'stops', 'fareBetween', 'nextDepartures', 'show']],
        'hooks'   => ['styleLoad', 'layersHtml', 'placeCard'],
        'has'     => [
            'timetable from TT_API, cached in shg:nm:tt; line in shg:nm:shgline; toggle in shg:nm:shg' => ['TT_API' => '/fetchT\(\s*TT_API/', 'tt' => '/shg:nm:tt\b/', 'shgline' => '/shg:nm:shgline/', 'shg' => "/shg:nm:shg'/"],
            'line layers shg-line-case + shg-line below rt-case, dashed' => ['shg-line' => "/'shg-line'/", 'case' => '/shg-line-case/', 'rt-case' => "/'rt-case'/", 'dasharray' => '/line-dasharray/', 'getSource' => '/getSource\(/'],
            'corridor geometry through ROUTE_API (costing bus, geojson shape)' => ['ROUTE_API' => '/ROUTE_API/', 'costing' => "/costing:\\s*'bus'/", 'shape' => '/shape_format/'],
            'timetable shape (A11): serviceOff honoured, stops without lat filtered' => ['serviceOff' => '/serviceOff/', 'lat null' => '/lat\s*[!=]==?\s*null/'],
            'P4: arrTime only when set, sellable + boarding open respected, note shown' => ['arrTime' => '/arrTime/', 'sellable' => '/sellable/', 'open' => '/\.open\b/'],
            'P3: the final isBorder stop snaps to the KB gate when its pin is > 2 km off' => ['isBorder' => '/isBorder/', 'gate' => '/\.gate\b/', '2 km' => '/\b2000\b|\b2\s*\*\s*1000\b/'],
            'pins .sr-pin at zoom ≥ 6.5 (zoomend)' => ['sr-pin' => '/sr-pin/', 'zoomend' => "/'zoomend'/"],
            'place card appends for p.shg only (A16) with Book / Track / Call (the phone read from KB.shg at runtime)' => ['insertAdjacentHTML' => "/insertAdjacentHTML\\(\\s*'beforeend'/", 'p.shg' => '/\.shg\b/', 'track' => '~/#/track~', 'KB.shg' => '/KB\.shg\b/', '.phone' => '/\.phone\b/', 'tel:' => '/tel:/'],
        ],
    ],
];
foreach ($CONTRACT as $n => $c) {
    if (!isset($JS[$n])) { skip("$n contract", 'not spliced'); continue; }
    $blk = $JS[$n];
    foreach ($c['exports'] as $obj => $keys) {
        $miss = array_values(array_filter($keys, static fn (string $k): bool => !$exposes($blk, $obj, $k)));
        check("$n exposes var $obj with " . implode('/', $keys), preg_match('/^var\s+' . $obj . '\b/m', $blk) === 1 && $miss === [], $miss ? 'missing: ' . implode(', ', $miss) : '');
    }
    $hs = $usedHooks[$n] ?? [];
    $missH = array_values(array_diff($c['hooks'], $hs));
    check("$n registers hook " . implode(', ', $c['hooks']), $missH === [], $missH ? 'missing: ' . implode(', ', $missH) : '');
    if ($n === 'TEMPLES' || $n === 'SHGROUTE') { check("$n builds on boot or mapReady", in_array('boot', $hs, true) || in_array('mapReady', $hs, true)); }
    foreach ($c['has'] as $label => $res) { $miss = $hasAll($blk, $res); check("$n: $label", $miss === [], $miss ? 'missing: ' . implode(', ', $miss) : ''); }
    foreach ($c['hasAll'] ?? [] as $label => $res) { $miss = $hasAll($aiAll, $res); check("$n: $label", $miss === [], $miss ? 'missing: ' . implode(', ', $miss) : ''); }
}
if (isset($JS['AI'])) {
    $ai = $JS['AI'];
    /* P2 replaces A8: the back hook returns FALSE so the core restores the level from the popped state */
    $bk = region($ai, "/hook\\(\\s*'back'/");
    check("AI back hook (P2): if (!AI.isOpen()) return false; AI._hist = false; AI.close(true); return false — never return true", $bk !== '' && (bool) preg_match('/_hist\s*=\s*false/', $bk) && (bool) preg_match('/(?:\.close|aiClose)\(\s*true\s*\)/', $bk) && (bool) preg_match('/return false/', $bk) && !preg_match('/return true/', $bk), $bk === '' ? "no hook('back')" : (preg_match('/return true/', $bk) ? 'returns true (A8 — superseded)' : ''));
    if (str_contains($ai, 'nearby(')) { check("AI calls the core nearby() with an object {q, l: KB.t(…), e} (A1)", (bool) preg_match('/\bnearby\(\s*\{[^}]*KB\.t\(|\{\s*q:\s*\w+\.q,\s*l:\s*(?:KB\.t|aiPick)\(/', $ai)); }
    check('AI never reads S.cache for other-mode ETAs (A10: getRoute per mode, quiet)', !preg_match('/S\.cache\b/', $ai) && (!str_contains($ai, 'getRoute(') || str_contains($ai, 'quiet: true')));
    $drive = (string) ($JS['AI'] . "\n" . ($JS['KB'] ?? ''));
    check('P1: the confirm bubble "Stop this trip and go to X?" exists in ne / hi / en (AI or KB.ui)', (bool) preg_match('/रोकेर/u', $drive) && (bool) preg_match('/रोककर/u', $drive) && (bool) preg_match('/Stop this trip/i', $drive));
    check('P5: bus clocks are IST — now = Date.now() + (330 + getTimezoneOffset()) · 60000, labelled IST (AI or SHGROUTE)', (bool) preg_match('/getTimezoneOffset\(\)/', $allJs) && (bool) preg_match('/\b330\b/', $allJs) && (bool) preg_match('/\bIST\b/', $allMod));
}
if (isset($JS['TEMPLES'])) {
    $tcss = $B['CSS:TEMPLES'];
    check('temples.css: #cTemples.on is gold and --temple is #FF6B00 (A15)', $tcss !== '' && preg_match('/#cTemples\.on\s*\{[^}]*--gold/', $tcss) && preg_match('/--temple\s*:\s*#FF6B00/i', $tcss), $tcss === '' ? 'temples.css not spliced' : '');
    check('TEMPLES asks the AI only when the AI module exists', !str_contains($JS['TEMPLES'], 'AI.ask(') || str_contains($JS['TEMPLES'], 'typeof AI'));
}
if (isset($JS['BORDER'])) {
    check('border.css styles .bd-card / .bd-cost / .bd-pin', $B['CSS:BORDER'] !== '' && str_contains($B['CSS:BORDER'], '.bd-card') && str_contains($B['CSS:BORDER'], '.bd-cost') && str_contains($B['CSS:BORDER'], '.bd-pin'), $B['CSS:BORDER'] === '' ? 'border.css not spliced' : '');
    check('BORDER keeps one module-level pin and removes it before adding (A17)', (bool) preg_match('/\.remove\(\)/', $JS['BORDER']));
    $bdAll = $JS['BORDER'] . "\n" . ($JS['KB'] ?? '');
    check('P6 wording: "fuel only" line, bicycle "नि:शुल्क / मुफ़्त / free", every figure "approx." (BORDER or KB.ui)', (bool) preg_match('/fuel only|इन्धन मात्र|ईंधन मात्र|सिर्फ़ ईंधन|सिर्फ ईंधन/iu', $bdAll) && (bool) preg_match('/निःशुल्क|नि:शुल्क/u', $bdAll) && (bool) preg_match('/मुफ़्त|मुफ्त/u', $bdAll) && (bool) preg_match('/\bfree\b/i', $bdAll) && (bool) preg_match('/\bapprox\b/i', $bdAll));
    check('P4: the border card\'s SHG line says the coach ends / starts at Rupaidiha — never "bus to Nepalgunj"', !preg_match('/bus to Nepalgunj|नेपालगञ्जसम्म बस|नेपालगंज तक बस/iu', $allMod));
}
if (isset($JS['SHGROUTE'])) {
    check('shgroute.css styles .sr-pin', $B['CSS:SHGROUTE'] !== '' && str_contains($B['CSS:SHGROUTE'], '.sr-pin'), $B['CSS:SHGROUTE'] === '' ? 'shgroute.css not spliced' : '');
    check('the corridor line is simplified and capped before it is cached (≤ 150 KB)', (bool) preg_match('/15\d{4}|150\s*\*\s*1024|simplif/i', $JS['SHGROUTE']));
}
if (isset($JS['KB'])) {
    check('KB registers no hook and exposes var KB', !str_contains($JS['KB'], 'hook(') && preg_match('/^var\s+KB\s*=/m', $JS['KB']) === 1);
}

echo "\n-- 7. api/nav-ai.php refuses without the expected request (regex on the source) --\n";
if ($aiPhp === '') {
    check('api/nav-ai.php exists', false, 'missing');
} else {
    $a = $aiPhp;
    $body = substr($a, (int) strpos($a, 'require_once'));                                  /* the request part; pure helpers may sit above it */
    check('bootstrap in the house style: strict_types, SHG_APP, bootstrap.php, JSON + nosniff headers', str_contains($a, 'declare(strict_types=1)') && str_contains($a, "define('SHG_APP', true)") && str_contains($a, "/includes/bootstrap.php'") && str_contains($a, 'application/json') && str_contains($a, 'X-Content-Type-Options: nosniff'));
    check('loads aitools.php, aiagent.php and walocal.php itself (A3: bootstrap does not)', str_contains($a, "/aitools.php'") && str_contains($a, "/aiagent.php'") && str_contains($a, "/walocal.php'"));
    check('a $fail(code, reason) closure answers {ok:false, reason} flat (A4), JSON_UNESCAPED_UNICODE', (bool) preg_match('/\$fail\s*=\s*(?:static\s+)?function\s*\(/', $a) && str_contains($a, "'ok' => false") && str_contains($a, "'reason'") && str_contains($a, 'JSON_UNESCAPED_UNICODE'));
    check('never Response::success / Response::error, never Security::requireCsrf, never wa_request_to (A3, A4, P13)', !str_contains($a, 'Response::success') && !str_contains($a, 'Response::error') && !str_contains($a, 'requireCsrf') && !str_contains($a, 'wa_request_to'));
    check('GET ?facts=1 → office phone / tel, ai flag, max-age=3600', str_contains($a, 'facts') && str_contains($a, 'Settings::officePhone()') && str_contains($a, "'tel'") && str_contains($a, 'AiAgent::webEnabled()') && str_contains($a, 'max-age=3600'));
    check('tel digits get the 91 prefix on a 10-digit office number (A5)', (bool) preg_match("/'91'\\s*\\./", $a) && (bool) preg_match('/\b10\b/', $a));
    check('P13 facts: wa = SiteFacts::chatDigits() with Settings::officeWhatsApp() as the fallback; company, routeKm, journeyHours returned', str_contains($a, 'SiteFacts::chatDigits()') && str_contains($a, 'Settings::officeWhatsApp()') && str_contains($a, "'company'") && str_contains($a, "'routeKm'") && str_contains($a, 'SiteFacts::routeKm()') && str_contains($a, "'journeyHours'") && str_contains($a, 'SiteFacts::journeyHours()') && (bool) preg_match('/company_name|Settings::company\(/', $a));
    check('non-POST → 405', codePos($body, 405) >= 0 && (str_contains($body, 'REQUEST_METHOD') || str_contains($body, 'Security::isPost()')));
    check("foreign Origin / Referer → 403 'origin'", codePos($body, 403) >= 0 && str_contains($body, 'HTTP_ORIGIN') && str_contains($body, "'origin'"));
    check("P12 rate limits: nav_ai 30 per 300 s + nav_ai_day 600 per 86400 → 429 'rate' with Retry-After: 60", codePos($body, 429) >= 0 && (bool) preg_match("/rateLimit\\(\\s*'nav_ai'\\s*,[^)]*,\\s*30\\s*,\\s*300\\s*\\)/", $body) && (bool) preg_match("/rateLimit\\(\\s*'nav_ai_day'\\s*,[^)]*,\\s*600\\s*,\\s*86400\\s*\\)/", $body) && str_contains($body, "'rate'") && (bool) preg_match('/Retry-After:\s*60/', $body), preg_match("/rateLimit\\(\\s*'nav_ai'\\s*,[^)]*,\\s*(\\d+)\\s*,\\s*(\\d+)\\s*\\)/", $body, $mm) ? "has nav_ai {$mm[1]}/{$mm[2]} s" : '');
    check("bad JSON / empty message → 400 'bad'", codePos($body, 400) >= 0 && str_contains($body, "'bad'") && (str_contains($body, 'Response::input()') || str_contains($body, 'php://input')));
    check('P12 message cap: 400 characters server-side (was 600)', (bool) preg_match('/(?:MESSAGE|MSG|message|msg|chars?|characters)[^\n;]{0,40}\b400\b|\b400\b[^\n;]{0,40}(?:chars?|characters)/i', $a), preg_match('/(?:MAX_MESSAGE|maxMessage|MSG_MAX)\s*=\s*(\d+)/', $a, $mm) ? "cap is {$mm[1]}" : '');
    check("WaLocal::answerWeb(\$ctx, …) first → source 'local'", str_contains($body, 'WaLocal::answerWeb(') && str_contains($body, 'AiTools::whoIsWeb()') && str_contains($body, "'local'"));
    /* P11: a logged-in office phone must not reach office tools through the public nav chat */
    $pCtx = posOf($body, '/AiTools::whoIsWeb\(\)/'); $pRole = posOf($body, "/\\\$ctx\\['role'\\]\\s*=\\s*'customer'/"); $pW = posOf($body, '/WaLocal::answerWeb\(/'); $pA = posOf($body, '/AiAgent::handleWeb\(/');
    $roleAll = (bool) preg_match("/\\\$ctx\\['role'\\]\\s*=\\s*'customer'/", $body) && (bool) preg_match("/\\\$ctx\\['admin'\\]\\s*=\\s*null/", $body) && (bool) preg_match("/\\\$ctx\\['adminId'\\]\\s*=\\s*0\\b/", $body) && (bool) preg_match("/\\\$ctx\\['scopeAdminId'\\]\\s*=\\s*null/", $body);
    check("P11: after whoIsWeb() the context is forced to a customer (role, admin, adminId, scopeAdminId) BEFORE WaLocal and AiAgent; never 'reset'", $roleAll && $pRole > $pCtx && $pCtx >= 0 && $pRole < $pW && $pRole < $pA && !preg_match("/'reset'/", $body), $roleAll ? '' : 'override missing');
    check("AiAgent::webEnabled() else 503 'off'; handleWeb null → 502 'busy'; source 'ai'", codePos($body, 503) >= 0 && str_contains($body, "'off'") && codePos($body, 502) >= 0 && str_contains($body, "'busy'") && str_contains($body, 'AiAgent::handleWeb(') && str_contains($body, "'ai'"));
    check('per-rider stageKey from a sha256 of ip | user agent (A6), set before BOTH brains (P11)', str_contains($body, 'stageKey') && str_contains($body, "hash('sha256'") && str_contains($body, 'HTTP_USER_AGENT') && posOf($body, "/hash\\('sha256'/") < $pW);
    check('agent actions mapped to {l, act:url, p}; /admin hrefs dropped; # hrefs made absolute (A7)', (bool) preg_match('~/admin~', $a) && str_contains($a, "'url'") && (bool) preg_match("/'#'|'\\/' \\. /", $a) && (bool) preg_match("/'href'/", $a));
    check('the agent prompt names the app, position, destination, route and language', str_contains($a, 'SHARI NAV') && str_contains($a, 'destination') && str_contains($a, 'route') && (bool) preg_match('/Nepali|Hindi|English/', $a));
    /* round($lat, 2) written out, or a point helper whose dp argument is 2 at the 'me' call site (navai_point($in['me'] ?? null, 2)) */
    $me2 = (bool) preg_match('/round\(\s*\$\w+\s*,\s*2\s*\)/', $a) || ((bool) preg_match('/round\(\s*\$\w+\s*,\s*\$(\w+)\s*\)/', $a, $mm) && (bool) preg_match("/\\[\\s*'me'\\s*\\][^\\n;]{0,60}\\b2\\s*\\)|'me'\\s*=>\\s*\\w+\\([^\\n;]{0,60}\\b2\\s*\\)/", $a));
    check('P10: the rider position is rounded to 2 dp (~1 km) before it reaches the agent; the body is never logged', $me2 && !preg_match('/error_log\([^)]*\$(?:q|in|raw|msg|message|body)\b|Logger::\w+\([^)]*\$(?:q|in|raw|msg|message|body)\b/', $a), $me2 ? '' : 'no 2-dp rounding of me found');
    $pF = posOf($body, '/facts/'); $p405 = codePos($body, 405); $p403 = codePos($body, 403); $p429 = codePos($body, 429); $p400 = codePos($body, 400);
    check('order (A19): facts → 405 → 403 → 429 → 400 → WaLocal → AiAgent', $pF >= 0 && $pF < $p405 && $p405 < $p403 && $p403 < $p429 && $p429 < $p400 && $p400 < $pW && $pW < $pA, "facts@$pF 405@$p405 403@$p403 429@$p429 400@$p400 wa@$pW ai@$pA");
    check('no phone number literal, no console / var_dump / error_log of the message', !preg_match('/(?<![\d.])9\d{9}(?![\d.])/', $a) && !preg_match('/var_dump|print_r\(|error_log\([^)]*\$(msg|message|text)/', $a));
    check('api/nav-ai.php is UTF-8', preg_match('//u', $a) === 1);
}

echo "\n-- 8. api/nav-temples.php refuses without bbox / near (regex on the source) --\n";
if ($tpPhp === '') {
    check('api/nav-temples.php exists', false, 'missing');
} else {
    $t = $tpPhp;
    check('bootstrap in the house style: strict_types, SHG_APP, bootstrap.php, JSON + nosniff headers', str_contains($t, 'declare(strict_types=1)') && str_contains($t, "define('SHG_APP', true)") && str_contains($t, "/includes/bootstrap.php'") && str_contains($t, 'application/json') && str_contains($t, 'X-Content-Type-Options: nosniff'));
    check('GET ?bbox=S,W,N,E or ?near=lat,lng — anything else → 400', str_contains($t, "'bbox'") && str_contains($t, "'near'") && codePos($t, 400) >= 0);
    check('bbox span capped at 2.5° each way, near radius capped at 80 km', (bool) preg_match('/\b2\.5\b/', $t) && (bool) preg_match('/\b80\b/', $t));
    check("rate limit nav_temples 60/min → 429 {\"results\":[]}", (bool) preg_match("/rateLimit\\(\\s*'nav_temples'\\s*,[^)]*,\\s*60\\s*,\\s*60\\s*\\)/", $t) && codePos($t, 429) >= 0 && (bool) preg_match('/429[\s\S]{0,300}results/', $t));
    check("nav_places query: kind = 'amenity:place_of_worship' with a lat / lng BETWEEN box, limited (A12)", str_contains($t, 'nav_places') && str_contains($t, "'amenity:place_of_worship'") && (bool) preg_match('/lat\s+BETWEEN/i', $t) && (bool) preg_match('/lng\s+BETWEEN/i', $t) && (bool) preg_match('/LIMIT\s+(400|\?|:\w+)/i', $t));
    $sql = preg_match('/SELECT[\s\S]*?nav_places[\s\S]*?(?:;|\]\s*\))/i', $t, $mm) ? $mm[0] : '';
    check('the query binds its parameters (no $_GET inside the SQL)', $sql !== '' && !str_contains($sql, '$_GET') && (bool) preg_match('/\?|:\w+/', $sql));
    check('excludes mosques / churches / gurudwaras / dargahs by name (both columns)', (bool) preg_match('/masjid\|mosque/i', $t) && str_contains($t, 'church') && (bool) preg_match('/gurud?wara/i', $t) && str_contains($t, 'dargah') && str_contains($t, 'मस्जिद'));
    check("classifies rel: buddhist (gompa/gumba/stupa/vihar), jain (jain/derasar), else hindu", str_contains($t, "'buddhist'") && str_contains($t, "'jain'") && str_contains($t, "'hindu'") && (bool) preg_match('/gompa\|gumba/i', $t) && str_contains($t, 'derasar'));
    check('dedupes same name within ~150 m and sorts nearest first for near (haversine)', (bool) preg_match('/\b150\b|0\.15\b/', $t) && (bool) preg_match('/\b(hav|haversine|asin|atan2)\b/i', $t));
    check('answers {results:[{name, nameEn, lat, lng, rel}]}, max-age=600, JSON_UNESCAPED_UNICODE', str_contains($t, "'results'") && str_contains($t, "'nameEn'") && str_contains($t, "'rel'") && str_contains($t, 'max-age=600') && str_contains($t, 'JSON_UNESCAPED_UNICODE'));
    check('no phone number literal, no var_dump', !preg_match('/(?<![\d.])9\d{9}(?![\d.])/', $t) && !str_contains($t, 'var_dump'));
    check('api/nav-temples.php is UTF-8', preg_match('//u', $t) === 1);
}

echo "\n-- 9. the page keeps its line endings and encoding --\n";
check('navigate.html keeps CRLF line endings (the splice converts LF module files)', substr_count($page, "\r\n") > 3000 && substr_count($page, "\n") - substr_count($page, "\r\n") < 5, substr_count($page, "\r\n") . ' CRLF, ' . (substr_count($page, "\n") - substr_count($page, "\r\n")) . ' bare LF');
check('navigate.html is UTF-8 without a BOM', substr($page, 0, 3) !== "\xEF\xBB\xBF" && preg_match('//u', $page) === 1);
check('the page still ends with boot(); })(); </script></body></html>', (bool) preg_match('~\nboot\(\);\n\}\)\(\);\n</script>\n(?:<script src="[^"]+"[^>]*></script>\n)*</body>\n</html>\s*$~', $pageLF));
check('one IIFE in strict mode wraps the whole script', substr_count($script, "'use strict';") === 1 && str_starts_with(ltrim($script), '(function () {'));

echo "\n----------------------------------------\n";
echo "PASSED: {$PASS}   FAILED: {$FAIL}   (" . number_format(microtime(true) - $t0, 2) . " s)\n";
echo "----------------------------------------\n\n";
exit($FAIL === 0 ? 0 : 1);
