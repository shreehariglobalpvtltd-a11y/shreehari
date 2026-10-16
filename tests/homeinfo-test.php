<?php
/**
 * homeinfo-test.php — the home information sections (9 Oct 2026, HOMEINFO work package).
 *
 * Owner asks: #3 live fares on the home page ("fare pani naya update gareko xa tei
 * rakhdinu ... price haru update hun sath update hune system"), #9 India–Nepal
 * timeline + open border, #11 route / km / halts section, #18 places to visit,
 * tour packages and the S Hari Global ecosystem.
 *
 *   1. markup: ONE block between <!-- HOMEINFO:START --> / <!-- HOMEINFO:END -->,
 *      directly after #topHero's closing tag; exactly four <section>s (#homeFares,
 *      #homeRoute, #homeIndiaNepal, #homeExplore), no nested section; every id in
 *      the block is unique in the whole template; the route mount, track button,
 *      content links and scroll targets are there;
 *   2. order: homeinfo.css carries the four order rules verbatim (6 / 9 / 11 / 12);
 *      homeinfo.css loads before depth.css, 24-homeinfo.js is loaded, both stamped
 *      with sw.js ASSET_VER;
 *   3. i18n: the hx* keys exist in en, hi and ne with the same key set; every
 *      data-i18n key in the block and every tx('hx…') key the script reads is
 *      defined; no backslash escapes in the hx lines (index.php scrapes 04-i18n.js);
 *   4. no literal price, phone or person's name in the block or the script (the
 *      numbers come from SHG_BOOT: FACTS.fares, else locations + sharingDir);
 *   5. every /assets/ file the block or the script references exists on disk;
 *   6. money parity (DB): the script's fallback rule (stop fare, else the direction
 *      default) gives exactly Fare::board()'s numbers, and SiteFacts' ranges are the
 *      min / max of its own rows.
 *
 *   php tests/homeinfo-test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/boarding.php';
if (is_file(INCLUDE_PATH . '/sitefacts.php')) {
    require_once INCLUDE_PATH . '/sitefacts.php';     // FACTS work package (optional here)
}

$root = dirname(__DIR__);
$PASS = 0;
$FAIL = 0;

function hx_check(string $label, bool $ok, string $extra = ''): void
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

/** Strip /* *\/ and // comments from JS (good enough for our own file: no regex literals with //). */
function hx_strip_js_comments(string $src): string
{
    $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);
    return (string) preg_replace('~(^|[^:\'"\\\\])//[^\r\n]*~', '$1', $src);
}

echo "\n=== Home information sections (HOMEINFO) ===\n\n";

$tpl   = (string) file_get_contents($root . '/app.template.html');
$css   = (string) @file_get_contents($root . '/assets/css/homeinfo.css');
$js    = (string) @file_get_contents($root . '/assets/js/24-homeinfo.js');
$i18n  = (string) file_get_contents($root . '/assets/js/04-i18n.js');
$sw    = (string) file_get_contents($root . '/sw.js');

/* ---- 1. the block ------------------------------------------------------ */
$start = strpos($tpl, '<!-- HOMEINFO:START -->');
$end   = strpos($tpl, '<!-- HOMEINFO:END -->');
hx_check('block markers present once each, START before END',
    $start !== false && $end !== false && $start < $end
    && substr_count($tpl, '<!-- HOMEINFO:START -->') === 1 && substr_count($tpl, '<!-- HOMEINFO:END -->') === 1);
$block = ($start !== false && $end !== false) ? substr($tpl, $start, $end - $start) : '';

/* #topHero's closing tag: walk the <div> depth from its opening tag */
$thOpen  = strpos($tpl, 'id="topHero"');
$thClose = false;
if ($thOpen !== false) {
    $tagStart = strrpos(substr($tpl, 0, $thOpen), '<');
    $tagName  = preg_match('~^<([a-z]+)~', substr($tpl, (int) $tagStart, 12), $tm) ? $tm[1] : 'div';
    if (preg_match_all('~<(/?)' . $tagName . '\b[^>]*>~', $tpl, $mm, PREG_OFFSET_CAPTURE, (int) $tagStart)) {
        $depth = 0;
        foreach ($mm[0] as $i => $hit) {
            $depth += $mm[1][$i][0] === '/' ? -1 : 1;
            if ($depth === 0) { $thClose = $hit[1] + strlen($hit[0]); break; }
        }
    }
}
hx_check('#topHero found and its closing tag located', $thClose !== false);
$between = ($thClose !== false && $start !== false) ? substr($tpl, (int) $thClose, $start - (int) $thClose) : 'x';
hx_check('the block starts directly after #topHero closes', trim($between) === '', $thClose !== false ? 'gap: ' . strlen(trim($between)) . ' chars' : '');

/* 10 Oct 2026: #homeLive (HD pack, live bus tracking) after the route; #homeBiz (BIZ, business tiles) closes the block. */
$ids = ['homeFares', 'homeRoute', 'homeLive', 'homeIndiaNepal', 'homeExplore', 'homeBiz'];
foreach ($ids as $id) {
    hx_check("#{$id} is a <section> in the block and unique in the template",
        (bool) preg_match('~<section\b[^>]*\bid="' . $id . '"~', $block) && substr_count($tpl, 'id="' . $id . '"') === 1);
}
hx_check('exactly six <section> tags in the block (no nested sections)', substr_count($block, '<section') === 6 && substr_count($block, '</section>') === 6);   // 10 Oct 2026: + #homeLive (HD) + #homeBiz (BIZ)
preg_match_all('~\bid="([^"]+)"~', $block, $bi);
$dupes = [];
foreach (array_unique($bi[1]) as $id) {
    if (substr_count($tpl, 'id="' . $id . '"') !== 1) { $dupes[] = $id; }
}
hx_check('every id in the block is unique in the template', $dupes === [] && count($bi[1]) === count(array_unique($bi[1])), $dupes === [] ? count($bi[1]) . ' ids' : implode(', ', $dupes));
hx_check('section order in the source: fares, route, live, India–Nepal, explore, business',
    strpos($block, 'id="homeFares"') < strpos($block, 'id="homeRoute"')
    && strpos($block, 'id="homeRoute"') < strpos($block, 'id="homeLive"')
    && strpos($block, 'id="homeLive"') < strpos($block, 'id="homeIndiaNepal"')
    && strpos($block, 'id="homeIndiaNepal"') < strpos($block, 'id="homeExplore"')
    && strpos($block, 'id="homeExplore"') < strpos($block, 'id="homeBiz"'));
/* BIZ (10 Oct 2026): six tiles to /business#<section>, two LIVE + four COMING SOON badges, translated; the brand name is a fact. */
$biz = preg_match('~<section\b[^>]*\bid="homeBiz".*?</section>~s', $block, $bm) ? $bm[0] : '';
preg_match_all('~href="/business#([a-z-]+)" data-hx-href="/business#\1"~', $biz, $bt);
hx_check('#homeBiz: six tiles link to /business#<section> (localised by data-hx-href)', $bt[1] === ['bus', 'tours', 'import-export', 'truck-bulk', 'packaging', 'shop-to-nepal'], implode(',', $bt[1]));
hx_check('#homeBiz: two LIVE badges, four COMING SOON badges, both translated',
    substr_count($biz, 'class="hx-badge hx-badge-live" data-i18n="hxBizLive"') === 2 && substr_count($biz, 'class="hx-badge hx-badge-soon" data-i18n="hxBizSoon"') === 4);
hx_check('#homeBiz: the brand name comes from the facts, the eyebrow is translated', str_contains($biz, 'data-fact="brand.short">{{f:brand.short}}</span>') && str_contains($biz, 'data-i18n="hxBizEyebrow"'));
hx_check('#homeBiz: no date and no price promised', !preg_match('~\b20[2-9]\d\b|₹|Rs\.?\s?\d~u', (string) preg_replace('~<!--.*?-->~s', '', $biz)));
hx_check('showcase.css orders #homeBiz at 12 (ties with #homeExplore, DOM order)', str_contains((string) @file_get_contents($root . '/assets/css/showcase.css'), '#view-home > #homeBiz { order: 12 !important; }'));
hx_check('24-homeinfo.js localises /business links and renders the audience chip', str_contains($js, '|business)(?=') && str_contains($js, "hxAudSolo") && str_contains($js, 'hx-pkg-aud'));
hx_check('#homeRoute carries the ROUTE mount [data-routeline="home"] once', substr_count($block, 'data-routeline="home"') === 1 && substr_count($tpl, 'data-routeline="home"') === 1);
hx_check('#homeRoute keeps the contract classes "container home-route"', (bool) preg_match('~<section class="[^"]*\bcontainer\b[^"]*\bhome-route\b[^"]*" id="homeRoute"~', $block));
hx_check('route mount has a skeleton so nothing jumps', str_contains($block, 'data-hx-skel') && str_contains($css, '.hx-route-skel') && str_contains($css, 'min-height: 160px'));
hx_check('Track my bus uses the router\'s [data-track-bus] handler', substr_count($block, 'data-track-bus') >= 2);
foreach (['/india-nepal', '/travel-info', '/explore', '/tours', '/agreements/'] as $href) {
    hx_check("link to {$href}", str_contains($block, 'href="' . $href . '"'));
}
preg_match_all('~data-scroll="([^"]+)"~', $block, $ds);
$missingScroll = array_values(array_filter(array_unique($ds[1]), static fn(string $t): bool => !str_contains($tpl, 'id="' . $t . '"')));
hx_check('every data-scroll target exists (search-anchor, quick-ticket)', $ds[1] !== [] && $missingScroll === [], implode(', ', array_unique($ds[1])));
hx_check('fares body has a placeholder the script replaces', str_contains($block, 'id="hxFaresBody"') && str_contains($js, "q('#hxFaresBody')"));
/* 10 Oct 2026 (HD pack): the band is the 2:1 heritage montage (nepal-heritage, 1600x800), no longer
   the 1080x300 nepal-band strip (those files stay on disk for the documents). */
hx_check('heritage band image is lazy, sized and has alt text',
    (bool) preg_match('~<img[^>]*nepal-heritage[^>]*>~', $block, $band)
    && str_contains($band[0], 'loading="lazy"') && str_contains($band[0], 'width="1600"') && str_contains($band[0], 'height="800"')
    && (bool) preg_match('~alt="[^"]{10,}"~', $band[0]));
hx_check('heritage band keeps the picture\'s own 2:1 shape (no fixed-height crop)', str_contains($css, '.hx-in .hx-band { position: relative; aspect-ratio: 2 / 1;'));
/* 10 Oct 2026 (HD review): an illustration is never passed off as a photo - the static place cards
   AND the cards 24-homeinfo.js draws from /api/explore.php both carry the "Illustration" tag
   (aria-hidden: the picture itself is decorative), and the API says which pictures are illustrations. */
$api = (string) @file_get_contents($root . '/includes/contentpages.php');
hx_check('static place cards: each HD thumbnail has an aria-hidden "Illustration" tag',
    preg_match_all('~<img class="hx-card-img" src="/assets/img/hd/~', $block) === 3
    && substr_count($block, '<span class="hx-card-ill" aria-hidden="true" data-i18n="hxIllus">') === 3);
hx_check('JS place cards: <img class="hx-card-img"> + the hxIllus tag when the API marks an illustration',
    str_contains($js, '<img class="hx-card-img" src="') && str_contains($js, "p.imageIllus === true")
    && str_contains($js, "'<span class=\"hx-card-ill\" aria-hidden=\"true\">' + h(tx('hxIllus'"));
hx_check('API featured(): places and packages carry imageIllus from ContentPages::isIllustration()',
    substr_count($api, "'imageIllus' => self::isIllustration(") === 2 && str_contains($api, 'public static function isIllustration('));
hx_check('heritage band alt (hxBandAlt, applied by syncAlt) says illustration in all three languages',
    (bool) preg_match("~hxBandAlt: 'Illustration of~", $i18n) && substr_count($i18n, "hxBandAlt: '") === 3
    && (bool) preg_match("~hxBandAlt: '[^']*चित्रण[^']*'[\s\S]*hxBandAlt: '[^']*चित्रण~u", $i18n));
hx_check('India and Nepal flags shown as SVG (not emoji)', str_contains($block, 'flag-in.svg') && str_contains($block, 'flag-np.svg') && !preg_match('~\x{1F1EE}\x{1F1F3}|\x{1F1F3}\x{1F1F5}~u', $block));
hx_check('timeline static fallback: the 1950 treaty and the open border', str_contains($block, 'hxTl1950') && str_contains($block, 'hxTlOpen') && str_contains($block, '>1950<'));
hx_check('no inline event handlers in the block', !preg_match('~\son[a-z]+\s*=~i', $block));

/* ---- 2. order rules + asset wiring ------------------------------------- */
foreach (['homeFares' => 7, 'homeRoute' => 9, 'homeIndiaNepal' => 11, 'homeExplore' => 12] as $id => $n) {
    hx_check("order rule #{$id} = {$n}", str_contains($css, "#view-home > #{$id} { order: {$n} !important; }"));
}
preg_match("/^var ASSET_VER = '([^']+)';/m", $sw, $av);
$ver = $av[1] ?? '';
$cssTag = '<link rel="stylesheet" href="/assets/css/homeinfo.css?v=' . $ver . '">';
$depPos = strpos($tpl, 'href="/assets/css/depth.css');
hx_check('homeinfo.css linked with ASSET_VER, before depth.css', $ver !== '' && str_contains($tpl, $cssTag) && $depPos !== false && strpos($tpl, $cssTag) < $depPos);
hx_check('24-homeinfo.js loaded (defer) with ASSET_VER', $ver !== '' && str_contains($tpl, '<script defer src="/assets/js/24-homeinfo.js?v=' . $ver . '"></script>'));
hx_check('homeinfo.css has dark + reduced-motion + data-motion rules',
    str_contains($css, ':root[data-theme="dark"]') && str_contains($css, 'prefers-reduced-motion: reduce') && str_contains($css, 'html[data-motion="reduced"]'));
hx_check('homeinfo.css uses Material roles with token fallbacks', str_contains($css, 'var(--md-primary, var(--royal))') && str_contains($css, 'var(--md-surface-container-low, var(--surface))'));
hx_check('homeinfo.css braces balance', substr_count($css, '{') === substr_count($css, '}'), substr_count($css, '{') . ' / ' . substr_count($css, '}'));
hx_check('tap targets: buttons and rows are at least 44px', (bool) preg_match('~\.hx \.hx-btn \{[^}]*min-height: 48px~s', $css) && (bool) preg_match('~\.hx \.hx-row \{[^}]*min-height: 50px~s', $css) && (bool) preg_match('~\.hx \.hx-more \{[^}]*min-height: 46px~s', $css));

/* ---- 3. i18n ------------------------------------------------------------ */
$blocks = [];
if (preg_match('~\nen: \{(.*?)\n\},\r?\nhi: \{(.*?)\n\},\r?\nne: \{(.*?)\n\}\r?\n\};~s', $i18n, $lm)) {
    $blocks = ['en' => $lm[1], 'hi' => $lm[2], 'ne' => $lm[3]];
}
hx_check('04-i18n.js language blocks found', count($blocks) === 3);
$keys = [];
foreach ($blocks as $l => $b) {
    preg_match_all('~(?<![A-Za-z0-9_])(hx[A-Z][A-Za-z0-9]*)\s*:~', $b, $km);
    $keys[$l] = array_values(array_unique($km[1]));
    sort($keys[$l]);
}
$en = $keys['en'] ?? [];
hx_check('hx keys exist (en)', count($en) >= 40, count($en) . ' keys');
hx_check('hx key set identical in en / hi / ne', $en !== [] && $en === ($keys['hi'] ?? null) && $en === ($keys['ne'] ?? null),
    'en ' . count($en) . ', hi ' . count($keys['hi'] ?? []) . ', ne ' . count($keys['ne'] ?? []));
preg_match_all('~data-i18n="(hx[A-Za-z0-9]+)"~', $block, $di);
$missI = array_values(array_diff(array_unique($di[1]), $en));
hx_check('every data-i18n key in the block is defined', $di[1] !== [] && $missI === [], $missI === [] ? count(array_unique($di[1])) . ' keys' : implode(', ', $missI));
preg_match_all("~\btxf?\('(hx[A-Za-z0-9]+)'~", $js, $jk);
preg_match_all("~'(hxCat[A-Za-z]+)'~", $js, $jc);
$jsKeys = array_unique(array_merge($jk[1], $jc[1]));
$missJ = array_values(array_diff($jsKeys, $en));
hx_check('every hx key the script reads is defined', $jsKeys !== [] && $missJ === [], $missJ === [] ? count($jsKeys) . ' keys' : implode(', ', $missJ));
$bad = [];
foreach (preg_split('~\R~', $i18n) as $n => $line) {
    if (preg_match('~(?<![A-Za-z0-9_])hx[A-Z][A-Za-z0-9]*\s*:~', $line) && str_contains($line, '\\')) { $bad[] = $n + 1; }
}
hx_check('hx lines carry no backslash escapes (index.php scrapes 04-i18n.js)', $bad === [], $bad === [] ? '' : 'lines ' . implode(', ', $bad));
hx_check('no data-fact inside the block\'s data-i18n elements', !preg_match('~data-i18n="[^"]*"[^>]*>[^<]*<[^>]*data-fact~', $block));

/* ---- 4. no literal money / phones / names -------------------------------- */
$blockText = (string) preg_replace('~<!--.*?-->~s', '', $block);
hx_check('block: no rupee / NPR amount typed', !preg_match('~(₹|Rs\.?|INR|NPR|रु\.?)\s?\d~u', $blockText));
hx_check('block: no phone number typed', !preg_match('~\d{5}\s?\d{5}|\+\d{2,3}[\s-]?\d{3,5}|tel:|wa\.me~', $blockText));
hx_check('block: no fare figure typed', !preg_match('~\b(1,?800|2,?000|2,?050|2,?200|2,?300|3,?800|7,?600)\b~', $blockText));
$jsCode = hx_strip_js_comments($js);
hx_check('script: no rupee amount or fare figure typed',
    !preg_match('~₹\s?\d~u', $jsCode) && !preg_match('~\b(1800|2000|2200|3800|7600|1,800|2,000|2,200|3,800|7,600)\b~', $jsCode));
hx_check('script: no phone number or wa.me link', !preg_match('~\d{5}\s?\d{5}|wa\.me|tel:~', $jsCode));
$names = '~Sher Bahadur|Lok Bahadur|Bishwokarma|Bishwakarma|Pus?h?palal Chowk|Near Shilpa Garage~i';
hx_check('block + script: no person\'s name or address typed', !preg_match($names, $blockText) && !preg_match($names, $jsCode));
hx_check('script reads fares from FACTS, else locations + sharingDir',
    str_contains($js, 'window.FACTS') && str_contains($js, 'boot().locations') && str_contains($js, 'sharingDir()') && str_contains($js, 'cabinPricing.private'));
hx_check('script only follows same-origin links from the API', str_contains($js, 'function safeUrl') && str_contains($js, 'a.origin === location.origin'));
preg_match_all('~window\.([A-Za-z_$][\w$]*)\s*=(?!=)~', $jsCode, $wg);
hx_check('script is an IIFE that assigns only window.shgHomeInfo',
    str_starts_with(ltrim($jsCode), '(function () {') && array_values(array_unique($wg[1])) === ['shgHomeInfo'], implode(', ', array_unique($wg[1])));

/* ---- 5. referenced assets exist -------------------------------------------- */
preg_match_all('~/assets/[A-Za-z0-9_./-]+?\.(?:webp|png|svg|jpg|css|js)~', $block . "\n" . $js, $am);
$missingA = [];
foreach (array_unique($am[0]) as $rel) {
    if (!is_file($root . $rel)) { $missingA[] = $rel; }
}
hx_check('every /assets/ file referenced exists', $am[0] !== [] && $missingA === [], $missingA === [] ? count(array_unique($am[0])) . ' files' : implode(', ', $missingA));
foreach (['flag-in.svg', 'flag-np.svg'] as $f) {
    hx_check("script's flag {$f} exists", str_contains($js, "flag-' + (np ? 'np' : 'in') + '.svg") && is_file($root . '/assets/img/' . $f));
}
preg_match_all('~\?v=([0-9a-zA-Z._-]+)~', $block . $js, $vm);
hx_check('every ?v= stamp in the block / script is ASSET_VER', $vm[1] !== [] && count(array_unique($vm[1])) === 1 && $vm[1][0] === $ver, implode(',', array_unique($vm[1])));

/* ---- 6. money parity with the server --------------------------------------- */
try {
    $locs = Boarding::bootLocations();
    $dir  = Fare::dirFares();
    if (method_exists('Fare', 'board')) {
        $board = Fare::board();
        foreach (['go' => 'toNepal', 'back' => 'toIndia'] as $side => $d) {
            $want = [];
            foreach ((array) ($locs[$side] ?? []) as $e) {
                $n = trim((string) ($e['name'] ?? ''));
                if ($n === '') { continue; }
                $f = (float) ($e['fare'] ?? 0);
                $want[$n] = (int) round($f > 0 ? $f : (float) $dir[$d]);   // the script's fallback rule
            }
            $got = [];
            foreach ((array) ($board[$side] ?? []) as $r) { $got[(string) $r['name']] = (int) round((float) $r['fare']); }
            $diff = [];
            foreach ($want as $n => $f) {
                if (array_key_exists($n, $got) && $got[$n] !== $f) { $diff[] = "{$n}: fallback {$f} vs board {$got[$n]}"; }
            }
            hx_check("fallback rule == Fare::board() ({$side})", $want !== [] && $diff === [], $diff === [] ? count($want) . ' rows' : implode('; ', $diff));
        }
        if (class_exists('SiteFacts')) {
            $sf = SiteFacts::boot();
            $fa = $sf['fares'] ?? [];
            foreach (['go', 'back'] as $side) {
                $fs = array_map(static fn($r) => (float) ($r['fare'] ?? 0), (array) ($fa[$side] ?? []));
                $rg = $fa['range'][$side] ?? null;
                hx_check("SiteFacts range.{$side} = min/max of its rows",
                    $fs !== [] && is_array($rg) && (float) $rg['min'] === (float) min($fs) && (float) $rg['max'] === (float) max($fs),
                    $fs !== [] ? (int) min($fs) . '–' . (int) max($fs) : 'no rows');
            }
            hx_check('SiteFacts private cabin prices are positive', (float) ($fa['private']['single'] ?? 0) > 0 && (float) ($fa['private']['double'] ?? 0) > 0);
        }
    } else {
        hx_check('Fare::board() not shipped yet — fallback path only (skip)', true);
    }
} catch (Throwable $e) {
    hx_check('money parity block ran', false, $e->getMessage());
}

/* ---- 7. syntax (when node is on PATH) ---------------------------------------- */
$node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
if ($node !== '') {
    $out = []; $rc = 0;
    exec('node --check ' . escapeshellarg($root . '/assets/js/24-homeinfo.js') . ' 2>&1', $out, $rc);
    hx_check('24-homeinfo.js parses (node --check)', $rc === 0, $rc === 0 ? '' : implode(' ', $out));
}

echo "\n----------------------------------------\n";
echo "PASSED: {$PASS}   FAILED: {$FAIL}\n";
echo "----------------------------------------\n\n";

exit($FAIL === 0 ? 0 : 1);
