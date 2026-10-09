<?php
/**
 * theme-palette-test.php — Pixel / Material You colour themes (9 Oct 2026,
 * owner ask #13: "dherai colour theme option, UI Android Google Pixel bata
 * inspired, smooth colours, sound haru pani testai").
 *
 * assets/css/themes.css adds html[data-palette="<id>"] palettes (each with a
 * light, dark and AMOLED block), the Pixel UI style (html[data-ui]) and the
 * in-app motion switch (html[data-motion]). This suite pins:
 *
 *   1. loading — themes.css loads after anim.css and BEFORE depth.css (which
 *      stays last), carries the asset stamp and is precached by sw.js;
 *   2. the CSS parses — braces and comments balance, no runtime color-mix(),
 *      the linear() springs sit behind @supports, dark before AMOLED;
 *   3. readability — for EVERY palette x light / dark / AMOLED the resolved
 *      tokens give >= 4.5:1 for on-primary/primary, on-royal/royal (the
 *      filled keys), ink/surface, ink/page, muted/surface, royal/surface
 *      (links) and on-navy/navy (the hero);
 *   4. one palette list — themes.css, SHG_PALETTES (03-accounts.js), the
 *      pre-paint whitelist (app.template.html), Admin -> Settings dropdown and
 *      m3palettes ids agree, status-bar colours agree, legacy day-palette
 *      values are not on the whitelist;
 *   5. owner defaults — the four public settings rows (SQL file + local DB),
 *      the index.php "only when nothing is saved" inject;
 *   6. the Appearance sheet + sound packs — radiogroups, focus trap, no new
 *      data-theme-set value, the pinned SHGFeel default-ON line, both packs.
 *
 *   php tests/theme-palette-test.php
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

/** WCAG relative luminance contrast of two #RGB / #RRGGBB colours (same helper as depth-display-test). */
function contrast(string $a, string $b): float
{
    $lum = static function (string $hex): float {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $c = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $c = array_map(static function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; }, $c);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $l1 = $lum($a); $l2 = $lum($b);
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

/** Top-level-ish rule blocks of a stylesheet: [[selectorList, decls], ...] (comments stripped). */
function css_blocks(string $css): array
{
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    /* conditional at-rules (@media print / forced-colors / reduced-motion,
       @supports) do not apply to the plain screen state: drop them whole */
    $flat = '';
    for ($i = 0, $n = strlen($css); $i < $n; $i++) {
        if ($css[$i] === '@' && preg_match('~\G@(media|supports)\b[^{]*\{~A', $css, $am, 0, $i)) {
            $j = $i + strlen($am[0]);
            for ($lvl = 1; $j < $n && $lvl > 0; $j++) {
                if ($css[$j] === '{') $lvl++;
                elseif ($css[$j] === '}') $lvl--;
            }
            $i = $j - 1;
            continue;
        }
        $flat .= $css[$i];
    }
    $css = $flat;
    preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $b) {
        $decl = [];
        foreach (explode(';', $b[2]) as $d) {
            $p = strpos($d, ':');
            if ($p === false) continue;
            $k = trim(substr($d, 0, $p));
            if (strncmp($k, '--', 2) === 0) $decl[$k] = trim(substr($d, $p + 1));
        }
        $out[] = [trim($b[1]), $decl];
    }
    return $out;
}

/**
 * Resolve custom properties for an <html> with the given attributes, the
 * way the cascade does for selectors made only of :root + [attr] parts:
 * higher specificity wins, then later source order. Earlier files first.
 */
function resolve_tokens(array $sheets, array $attrs): array
{
    $cands = [];   // prop => [spec, order, value]
    $order = 0;
    foreach ($sheets as $blocks) {
        foreach ($blocks as [$selList, $decl]) {
            $order++;
            $best = -1;
            foreach (explode(',', $selList) as $sel) {
                $sel = trim($sel);
                if (!preg_match('~^:root((?:\[[^\]]+\])*)$~', $sel, $sm)) continue;
                preg_match_all('~\[([a-z-]+)(?:="?([^"\]]*)"?)?\]~', $sm[1], $am, PREG_SET_ORDER);
                $ok = true;
                foreach ($am as $a) {
                    $name = $a[1];
                    if (!array_key_exists($name, $attrs)) { $ok = false; break; }
                    if (isset($a[2]) && $a[2] !== '' && $attrs[$name] !== $a[2]) { $ok = false; break; }
                }
                if ($ok) $best = max($best, 1 + count($am));
            }
            if ($best < 0) continue;
            foreach ($decl as $k => $v) {
                if (!isset($cands[$k]) || $best > $cands[$k][0] || ($best === $cands[$k][0] && $order > $cands[$k][1])) {
                    $cands[$k] = [$best, $order, $v];
                }
            }
        }
    }
    $raw = array_map(static fn($c) => $c[2], $cands);
    $get = static function (string $k, int $depth = 0) use (&$get, $raw): ?string {
        if ($depth > 12 || !isset($raw[$k])) return null;
        $v = $raw[$k];
        if (preg_match('~^var\(\s*(--[a-z0-9-]+)\s*(?:,\s*(.+))?\)$~i', $v, $vm)) {
            $r = $get($vm[1], $depth + 1);
            return $r ?? (isset($vm[2]) ? trim($vm[2]) : null);
        }
        return $v;
    };
    $out = [];
    foreach (array_keys($raw) as $k) $out[$k] = $get($k);
    return $out;
}

echo "\n=== Pixel / Material You colour themes ===\n\n";

$themes = (string) file_get_contents($root . '/assets/css/themes.css');
$ds     = (string) file_get_contents($root . '/assets/css/ds.css');
$redes  = (string) file_get_contents($root . '/assets/css/redesign.css');
$depth  = (string) file_get_contents($root . '/assets/css/depth.css');
$tpl    = (string) file_get_contents($root . '/app.template.html');
$sw     = (string) file_get_contents($root . '/sw.js');
$acc    = (string) file_get_contents($root . '/assets/js/03-accounts.js');
$feel   = (string) file_get_contents($root . '/assets/js/19-premium.js');
$index  = (string) file_get_contents($root . '/index.php');
$adm    = (string) file_get_contents($root . '/admin/settings.php');
$sqlF   = $root . '/database/upgrade-2026-10-09-themes.sql';
$sql    = is_file($sqlF) ? (string) file_get_contents($sqlF) : '';
preg_match("~var ASSET_VER = '([^']+)'~", $sw, $m);
$ver = $m[1] ?? '';

/* ---------- 1. loading ---------- */
echo "-- 1. loading --\n";
check('themes.css exists and is substantial', strlen($themes) > 20000, strlen($themes) . ' bytes');
preg_match_all('~<link rel="stylesheet" href="/assets/css/([a-z-]+)\.css\?v=([^"]+)"~', $tpl, $links);
$order = $links[1];
$iT = array_search('themes', $order, true);
$iA = array_search('anim', $order, true);
$iD = array_search('depth', $order, true);
check('customer app links themes.css', $iT !== false, implode(' > ', $order));
check('themes.css loads after anim.css (and so after premium/ds/redesign)', $iT !== false && $iA !== false && $iT > $iA);
check('themes.css loads BEFORE depth.css', $iT !== false && $iD !== false && $iT < $iD);
check('depth.css is still the LAST stylesheet', end($order) === 'depth');
check('themes.css carries the asset stamp', $iT !== false && $links[2][$iT] === $ver, $ver);
check('sw.js precaches themes.css with ASSET_VER', strpos($sw, "'/assets/css/themes.css?v=' + ASSET_VER") !== false);

/* ---------- 2. the CSS parses ---------- */
echo "\n-- 2. the stylesheet parses --\n";
$noCom = (string) preg_replace('~/\*.*?\*/~s', '', $themes);
check('comments balance (every /* closes)', substr_count($themes, '/*') === substr_count($themes, '*/'));
check('braces balance', substr_count($noCom, '{') === substr_count($noCom, '}'), substr_count($noCom, '{') . ' / ' . substr_count($noCom, '}'));
$depthOk = true; $lvl = 0;
for ($i = 0, $n = strlen($noCom); $i < $n; $i++) {
    if ($noCom[$i] === '{') $lvl++;
    elseif ($noCom[$i] === '}') { $lvl--; if ($lvl < 0) { $depthOk = false; break; } }
}
check('no brace ever closes before it opens', $depthOk && $lvl === 0);
check('no runtime color-mix() (older Android WebViews)', stripos($noCom, 'color-mix(') === false);
/* every linear() spring sits inside @supports (linear()), never at top level */
$top = (string) preg_replace('~@supports[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}~s', '', $noCom);
check('linear() springs only inside @supports (cubic-bezier fallback outside)', stripos($top, 'linear(0') === false && stripos($noCom, '@supports (transition-timing-function: linear(0, 1))') !== false);
check('fallback spring tokens are cubic-bezier', (bool) preg_match('~--md-spring:\s*cubic-bezier\(~', $top) && (bool) preg_match('~--md-spring-fast:\s*cubic-bezier\(~', $top));
foreach (['--md-ease-standard', '--md-ease-emph-decel', '--md-spring', '--md-r-card'] as $tok) {
    check("contract token {$tok} defined", strpos($noCom, $tok . ':') !== false);
}
check('pixel UI: cards 28px (--md-r-card)', (bool) preg_match('~:root\[data-ui="pixel"\]\s*\{[^}]*--md-r-card:\s*28px~', $noCom));
check('pixel UI: shell motion tokens re-pointed to the spring', (bool) preg_match('~:root\[data-ui="pixel"\]\s*\{[^}]*--m-spring:\s*var\(--md-spring\)~', $noCom));
check('reduced motion: data-motion mirrors the global kill switch', strpos($noCom, 'html:root[data-motion="reduced"] *') !== false && strpos($noCom, 'transition-duration: .01ms !important') !== false);
check('reduced motion: depth lift / press go to 0', (bool) preg_match('~:root\[data-motion="reduced"\]\[data-theme\]\s*\{[^}]*--dp-press:\s*0px~', $noCom));
check('themes.css does not touch ds.css (2 --accent-gold: there, pinned)', substr_count($ds, '--accent-gold:') === 2);

/* ---------- palette ids from every source ---------- */
preg_match_all('~:root\[data-palette="([a-z]+)"\]\[data-theme="light"\]~', $noCom, $pm);
$cssIds = array_values(array_unique($pm[1]));
preg_match_all("~\\['([a-z]+)',\\s*\\['(#[0-9a-f]{6})', '(#[0-9a-f]{6})', '(#[0-9a-f]{6})'\\], \\['(#[0-9a-f]{6})', '(#[0-9a-f]{6})'\\], '(#[0-9a-f]{6})'~", $acc, $am, PREG_SET_ORDER);
$jsIds = array_map(static fn($r) => $r[1], $am);
$jsTc = [];
foreach ($am as $r) $jsTc[$r[1]] = [$r[5], $r[6], $r[7]];
preg_match('~var P = \{(.*?)\};~s', $tpl, $pp);
preg_match_all("~([a-z]+): \\['(#[0-9a-f]{6})', '(#[0-9a-f]{6})', '(#[0-9a-f]{6})'\\]~", $pp[1] ?? '', $tm, PREG_SET_ORDER);
$ppIds = array_map(static fn($r) => $r[1], $tm);
$ppTc = [];
foreach ($tm as $r) $ppTc[$r[1]] = [$r[2], $r[3], $r[4]];
preg_match("~'app_theme_palette'\\s*=>\\s*\\[(.*?)\\],\\s*\n~s", $adm, $ap);
preg_match_all("~'([a-z]*)'\\s*=>~", $ap[1] ?? '', $aIds);
$admIds = $aIds[1] ?? [];

/* ---------- 3. readability ---------- */
echo "\n-- 3. readability: every palette x light / dark / AMOLED >= 4.5:1 --\n";
check('12 palettes in themes.css', count($cssIds) === 12, implode(' ', $cssIds));
$sheets = [css_blocks($ds), css_blocks($redes), css_blocks($themes), css_blocks($depth)];
$pairs = [
    ['--md-on-primary', '--md-primary', 'on-primary/primary'],
    ['--on-royal', '--royal', 'on-royal/royal (filled keys)'],
    ['--ink', '--surface', 'ink/surface'],
    ['--ink', '--page', 'ink/page'],
    ['--muted', '--surface', 'muted/surface'],
    ['--royal', '--surface', 'royal/surface (links)'],
    ['--on-navy', '--navy', 'on-navy/navy (hero)'],
    ['--md-on-surface', '--md-surface', 'md on-surface/surface'],
    ['--md-on-primary-container', '--md-primary-container', 'md on-primary-container/container'],
];
$hex = static fn(?string $v): bool => $v !== null && (bool) preg_match('~^#([0-9a-f]{3}|[0-9a-f]{6})$~i', $v);
$worst = [99.0, ''];
$bad = [];
$checked = 0;
foreach ($cssIds as $pid) {
    foreach (['light', 'dark', 'amoled'] as $mode) {
        $attrs = ['data-palette' => $pid, 'data-theme' => $mode === 'light' ? 'light' : 'dark', 'data-ui' => 'pixel'];
        if ($mode === 'amoled') $attrs['data-display'] = 'amoled';
        $t = resolve_tokens($sheets, $attrs);
        foreach ($pairs as [$fg, $bg, $lab]) {
            $a = $t[$fg] ?? null; $b = $t[$bg] ?? null;
            if (!$hex($a) || !$hex($b)) { $bad[] = "{$pid}/{$mode} {$lab}: unresolved {$fg}=" . var_export($a, true) . " {$bg}=" . var_export($b, true); continue; }
            $r = contrast($a, $b);
            $checked++;
            if ($r < $worst[0]) $worst = [$r, "{$pid}/{$mode} {$lab}"];
            if ($r < 4.5) $bad[] = sprintf('%s/%s %s %s on %s = %.2f', $pid, $mode, $lab, $a, $b, $r);
        }
        if ($mode === 'amoled') {
            $pg = $t['--page'] ?? '';
            if (strtolower((string) $pg) !== '#000000' && strtolower((string) $pg) !== '#000') $bad[] = "{$pid}/amoled page is {$pg}, not black";
            $sf = (string) ($t['--surface'] ?? '#fff');
            if ($hex($sf) && contrast($sf, '#000000') > 1.35) $bad[] = "{$pid}/amoled surface {$sf} is not near-black";
        }
    }
}
check("every pair resolves and is >= 4.5:1 ({$checked} pairs)", $bad === [], $bad === [] ? sprintf('worst %.2f (%s)', $worst[0], $worst[1]) : implode(' | ', array_slice($bad, 0, 6)));
/* the brand look (no data-palette) is untouched: royal is still the ds royal */
$brand = resolve_tokens($sheets, ['data-theme' => 'light', 'data-ui' => 'pixel']);
check('no data-palette = today\'s brand royal (#0054A8)', strtolower((string) ($brand['--royal'] ?? '')) === '#0054a8', (string) ($brand['--royal'] ?? '?'));
$brandA = resolve_tokens($sheets, ['data-theme' => 'dark', 'data-display' => 'amoled', 'data-ui' => 'pixel']);
check('no data-palette AMOLED = depth.css page #000000 / surface #101822', strtolower((string) ($brandA['--page'] ?? '')) === '#000000' && strtolower((string) ($brandA['--surface'] ?? '')) === '#101822');
/* status / seat / brand-red meanings never move */
$palBlocks = '';
if (preg_match_all('~:root\[data-palette[^{]*\{[^}]*\}~', $noCom, $pb)) $palBlocks = implode("\n", $pb[0]);
$keep = ['--red:', '--green', '--success', '--danger', '--warn', '--seat-', '--status-', '--ok:', '--err'];
$moved = array_values(array_filter($keep, static fn($k) => strpos($palBlocks, $k) !== false));
check('palettes never re-point status / seat / brand-red tokens', $moved === [], implode(' ', $moved));
/* dark block before AMOLED block, per palette and for the shared part */
$orderOk = true;
foreach (array_merge([''], $cssIds) as $pid) {
    $sel = $pid === '' ? ':root[data-palette]' : ':root[data-palette="' . $pid . '"]';
    $pd = strpos($noCom, $sel . '[data-theme="dark"]');
    $pa = strpos($noCom, $sel . '[data-display="amoled"]');
    if ($pd === false || $pa === false || $pd > $pa) { $orderOk = false; break; }
}
check('every dark block comes before its AMOLED block', $orderOk);

/* ---------- 4. one palette list ---------- */
echo "\n-- 4. one palette list everywhere --\n";
$sorted = static function (array $a): array { sort($a); return $a; };
check('SHG_PALETTES (03-accounts.js) == themes.css', $sorted($jsIds) === $sorted($cssIds), implode(' ', $jsIds));
check('pre-paint whitelist (app.template.html) == themes.css', $sorted($ppIds) === $sorted($cssIds), implode(' ', $ppIds));
check('Admin -> Settings dropdown == themes.css + blank (brand)', $sorted($admIds) === $sorted(array_merge([''], $cssIds)), implode(' ', $admIds));
$tcSame = true;
foreach ($cssIds as $pid) {
    if (!isset($jsTc[$pid], $ppTc[$pid]) || $jsTc[$pid] !== $ppTc[$pid]) { $tcSame = false; break; }
}
check('status-bar colours + dark page agree (pre-paint == SHG_PALETTES)', $tcSame);
$legacy = ['0', '1', '2', '3', '4', '5', '6', 'brand', 'logo', 'auto', 'shg'];
check('legacy day-palette values are NOT on the whitelist', array_intersect($legacy, $ppIds) === [] && array_intersect($legacy, $jsIds) === []);
check('pre-paint whitelist uses hasOwnProperty (no prototype keys)', strpos($tpl, "Object.prototype.hasOwnProperty.call(P, p)") !== false);
check('pre-paint reads shg:palette, shg:ui and shg:motion', strpos($tpl, "g('shg:palette')") !== false && strpos($tpl, "g('shg:ui')") !== false && strpos($tpl, "g('shg:motion')") !== false);
check('pre-paint keeps the pinned theme statements', strpos($tpl, "var d = document.documentElement, t = (localStorage.getItem('shg:theme') || '').replace(/\"/g, '');") !== false && strpos($tpl, "d.removeAttribute('data-day');") !== false);
check('pre-paint palette block comes after the theme block (reads data-theme)', strpos($tpl, "g('shg:palette')") > strpos($tpl, "d.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');"));
check('the dead day-palette picker is gone from 03-accounts.js', strpos($acc, "setAttribute('data-day'") === false && strpos($acc, 'const PAL ') === false);

/* ---------- 5. owner defaults ---------- */
echo "\n-- 5. owner defaults --\n";
foreach (['app_theme_palette' => "''", 'app_ui_style' => "'pixel'", 'app_sound_pack' => "'pixel'", 'app_motion_on' => "'1'"] as $k => $dflt) {
    check("SQL: INSERT IGNORE {$k} default {$dflt}, public", (bool) preg_match("~\\('{$k}',\\s*" . preg_quote($dflt, '~') . ",\\s*'(string|bool)',\\s*'app',\\s*'[^']*',\\s*1\\)~", $sql));
}
check('SQL: explicit is_public UPDATE for the four keys', (bool) preg_match("~UPDATE `settings` SET `is_public` = 1\\s+WHERE `skey` IN \\('app_theme_palette', 'app_ui_style', 'app_sound_pack', 'app_motion_on'\\)~", $sql));
check('SQL: INSERT IGNORE (idempotent, keeps an owner value)', stripos($sql, 'INSERT IGNORE INTO `settings`') !== false);
check('Admin -> Settings: dropdowns for style + sound pack', strpos($adm, "'app_ui_style'         => ['pixel'") !== false && strpos($adm, "'app_sound_pack'       => ['pixel'") !== false);
check('Admin -> Settings: help text for all four', substr_count($adm, "'app_theme_palette'") >= 2 && strpos($adm, "'app_motion_on'                       =>") !== false);
check('index.php: owner default applied only where nothing is saved', strpos($index, 'window.SHG_PAL_TC') !== false && strpos($index, "if (u !== 'pixel' && u !== 'classic')") !== false && strpos($index, "if (mo !== 'full' && mo !== 'reduced'") !== false);
check('index.php: data-off head script untouched', strpos($index, "btn_wa_fab_on: 'wafab'") !== false);
/* the local DB rows (skipped when no DB is reachable) */
$dbNote = '';
try {
    require_once $root . '/includes/bootstrap.php';
    $rows = Database::fetchAll("SELECT skey, svalue, is_public FROM settings WHERE skey IN ('app_theme_palette','app_ui_style','app_sound_pack','app_motion_on')");
    $have = [];
    foreach ($rows as $r) $have[$r['skey']] = (int) $r['is_public'];
    check('DB: the four settings rows exist', count($have) === 4, implode(', ', array_keys($have)));
    check('DB: all four are public', array_sum($have) === 4);
    $pub = Settings::publicSettings();
    check('DB: SHG_BOOT.settings would carry app_ui_style + app_sound_pack', array_key_exists('app_ui_style', $pub) && array_key_exists('app_sound_pack', $pub));
    $op = (string) ($pub['app_theme_palette'] ?? '');
    check('DB: app_theme_palette is blank or a known id', $op === '' || in_array($op, $cssIds, true), $op === '' ? '(brand)' : $op);
} catch (Throwable $e) {
    $dbNote = $e->getMessage();
    echo "  SKIP  DB checks — " . $dbNote . "\n";
}

/* ---------- 6. the Appearance sheet + sound packs ---------- */
echo "\n-- 6. Appearance sheet + sound packs --\n";
check('sheet is a modal dialog', strpos($acc, "sheet.setAttribute('role', 'dialog')") !== false && strpos($acc, "sheet.setAttribute('aria-modal', 'true')") !== false);
check('sheet: radiogroups with roving tabindex + arrow keys', substr_count($acc, 'role="radiogroup"') >= 5 && strpos($acc, 'ArrowRight: 1') !== false);
check('sheet: focus trap, Esc and the back key', strpos($acc, "e.key === 'Tab'") !== false && strpos($acc, "e.key === 'Escape'") !== false && strpos($acc, "history.pushState({ shgAppear: 1 }") !== false);
check('sheet: colour swatches use data-palette-set (data-theme-set stays light/dark/amoled/auto)', strpos($acc, 'data-palette-set="') !== false);
preg_match_all('~data-theme-set="([a-z]+)"~', $acc . $tpl, $dts);
check('no new data-theme-set value', array_diff(array_unique($dts[1]), ['auto', 'light', 'dark', 'amoled']) === [], implode(' ', array_unique($dts[1])));
check('sheet: S Hari (default) + every palette, named in ne / en / hi', strpos($acc, "brand: ['S Hari (मूल)', 'S Hari (default)'") !== false && count($am) === 12);
check('entry points: phone-menu row + topbar 🎨 button', strpos($acc, "b.className = 'mm-appear'") !== false && strpos($acc, "closest('.palette-btn')") !== false && strpos($themes, 'html .topbar .palette-wrap { display: inline-flex !important; }') !== false);
check('style / motion / pack / sound / vibration controls', strpos($acc, 'data-ap-ui="pixel"') !== false && strpos($acc, 'data-ap-motion="reduced"') !== false && strpos($acc, 'data-ap-pack="pixel"') !== false && strpos($acc, "toggle('sound'") !== false && strpos($acc, "toggle('haptics'") !== false);
check('saves shg:palette / shg:ui / shg:motion', strpos($acc, "lsSet('shg:palette'") !== false && strpos($acc, "lsSet('shg:ui'") !== false && strpos($acc, "lsSet('shg:motion'") !== false);
check('status bar (meta theme-color) follows the palette', strpos($acc, "pal ? pal[2][dark ? 1 : 0]") !== false);
check('window.shgReducedMotion honours data-motion + the OS setting', strpos($acc, "window.shgReducedMotion = function") !== false);
check('SHGFeel default-ON read() line kept verbatim', strpos($feel, "return v === null ? true : v === '1';") !== false);
check('sound packs: pixel + classic, whitelisted shg:soundpack', strpos($feel, "var PACKS = { pixel: PIXEL, classic: VOICES };") !== false && strpos($feel, "var LS_PACK = 'shg:soundpack';") !== false && strpos($feel, 'hasOwnProperty.call(PACKS, v)') !== false);
check('sound packs: owner default from SHG_BOOT.settings.app_sound_pack', strpos($feel, 'window.SHG_BOOT.settings.app_sound_pack') !== false);
foreach (['tap', 'select', 'deselect', 'toggleOn', 'toggleOff', 'nav', 'sheetOpen', 'sheetClose', 'success', 'error', 'notify', 'ticket'] as $v) {
    $inPixel = (bool) preg_match('~var PIXEL = \{.*?\n\s+' . $v . ': function~s', $feel);
    $inClassic = (bool) preg_match('~var VOICES = \{.*?\n\s+' . $v . ': function~s', $feel);
    check("voice '{$v}' in both packs", $inPixel && $inClassic);
}
check('no audio files: synthesis only (no new Audio / audio element)', strpos($feel, 'new Audio(') === false && stripos($feel, "createElement('audio')") === false && !preg_match('~\.(mp3|ogg|wav|m4a)[\'"]~i', $feel));
check('45 ms limiter and hidden-tab guard kept', strpos($feel, 'now - lastAt < 45') !== false && strpos($feel, 'if (document.hidden) return;') !== false);
check('data-sfx="own" keeps the generic .btn tap quiet', strpos($feel, "closest('[data-sfx=\"own\"]')") !== false);
check('SFX.deselect exists for the seat map', strpos($feel, "SFX.deselect = function () { Feel.play('deselect'); };") !== false);

echo "\n" . ($FAIL === 0 ? "\033[32m" : "\033[31m") . "theme-palette: {$PASS} passed, {$FAIL} failed\033[0m\n";
exit($FAIL === 0 ? 0 : 1);
