<?php
/**
 * showcase-test.php — the home-page showcase (9 Oct 2026, HOME work package).
 *
 * Owner asks: #1 emblem only, no white tile, no baked name; #2 3D Track / My-ticket
 * keys; #4 colourful how-to-book guide; #5 CEO introduction; #6 animated amenities;
 * #7 the booking demo back on the page; #8 an attractive, smooth home; #16 flags.
 *
 *   1. sections: #homeHero, #homeFeat (8 cards), #quick-ticket 1-2-3, #demoHead +
 *      #animBooking (a direct child of #view-home, no longer inside the guide),
 *      #topHero offices card, #homeCeo, #bookHelp; no nested <section> inside
 *      #homeHero / #homeCeo; every id unique;
 *   2. order: showcase.css carries the PLAN.md order map verbatim; the pinned
 *      redesign.css order lines are untouched; showcase.css loads before depth.css;
 *   3. logo: no white tile / plate rules (nav, hero, offices card, demo phone,
 *      footer, checkout operator, ticket head); the SPA body never loads logo.png
 *      (it carries the baked name); the emblem files exist and are transparent;
 *   4. i18n: every data-i18n key used by the showcase blocks exists in en, hi, ne;
 *   5. facts: no typed phone number in the template body, no typed price in the
 *      home view / footer / WhatsApp sheet (tokens and data-fact only); no
 *      data-fact inside a [data-i18n] element; CEO card reads the facts;
 *   6. motion: keyframes in showcase.css move transform / opacity only; reduced
 *      motion is honoured both ways; the loops run only while on screen;
 *      depth.css stays free of rotate / blur / :is / @keyframes.
 *
 *   php tests/showcase-test.php        (static checks only — no DB, no server)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root = dirname(__DIR__);
$PASS = 0;
$FAIL = 0;

function sc_check(string $label, bool $ok, string $extra = ''): void
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

function sc_read(string $path): string
{
    $s = @file_get_contents($path);
    return $s === false ? '' : $s;
}

$tpl      = sc_read($root . '/app.template.html');
$css      = sc_read($root . '/assets/css/showcase.css');
$js       = sc_read($root . '/assets/js/21-showcase.js');
$depth    = sc_read($root . '/assets/css/depth.css');
$redesign = sc_read($root . '/assets/css/redesign.css');
$anim     = sc_read($root . '/assets/css/anim.css');
$app      = sc_read($root . '/assets/css/app.css');
$views    = sc_read($root . '/assets/css/views.css');
$i18n     = sc_read($root . '/assets/js/04-i18n.js');

sc_check('files present', $tpl !== '' && $css !== '' && $js !== '' && $depth !== '' && $i18n !== '');

/* index.php strips every HTML comment before serving: test what ships. */
$served = (string) preg_replace('/<!--.*?-->/s', '', $tpl);
$bodyPos = stripos($served, '<body');
$body = $bodyPos === false ? '' : substr($served, $bodyPos);

/** The markup of one element by id: from its opening tag to the matching close tag. */
function sc_block(string $html, string $id): string
{
    if (!preg_match('/<([a-z0-9]+)\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $tag = strtolower($m[1][0]);
    $start = $m[0][1];
    $pos = $start + strlen($m[0][0]);
    $depth = 1;
    $re = '/<(\/?)' . $tag . '\b[^>]*>/i';
    while ($depth > 0 && preg_match($re, $html, $t, PREG_OFFSET_CAPTURE, $pos)) {
        $depth += $t[1][0] === '/' ? -1 : 1;
        $pos = $t[0][1] + strlen($t[0][0]);
    }
    return substr($html, $start, $pos - $start);
}

/* ---------------------------------------------------------------------- */
echo "-- 1. Sections --\n";
$home = sc_block($body, 'view-home');
sc_check('#view-home found', $home !== '' && strlen($home) > 10000);

/* direct children of #view-home (depth-1 tags) */
$kids = [];
if ($home !== '') {
    $inner = substr($home, (int) strpos($home, '>') + 1);
    $depthN = 0;
    $void = ['img', 'br', 'hr', 'input', 'meta', 'link', 'source', 'use', 'path', 'circle', 'rect', 'polyline', 'line'];
    if (preg_match_all('/<(\/?)([a-z0-9-]+)\b([^>]*?)(\/?)>/i', $inner, $all, PREG_SET_ORDER)) {
        foreach ($all as $t) {
            $name = strtolower($t[2]);
            if ($t[1] === '/') { $depthN--; continue; }
            if ($depthN === 0 && preg_match('/\bid="([^"]+)"/', $t[3], $idm)) {
                $kids[] = $idm[1];
            } elseif ($depthN === 0 && preg_match('/\bclass="([^"]+)"/', $t[3], $cm)) {
                $kids[] = '.' . $cm[1];
            }
            if ($t[4] === '/' || in_array($name, $void, true)) continue;
            $depthN++;
        }
    }
}
foreach (['homeHero', 'homeFeat', 'quick-ticket', 'demoHead', 'animBooking', 'topHero', 'homeCeo', 'animRoute', 'bookHelp', 'trust', 'agrBar', 'realReviews', 'homeMoreBar', 'homeExtra', 'homeMore'] as $id) {
    sc_check("#{$id} is a direct child of #view-home", in_array($id, $kids, true));
}
foreach (['homeFares', 'homeRoute', 'homeIndiaNepal', 'homeExplore'] as $id) {
    if (str_contains($body, 'id="' . $id . '"')) {
        sc_check("HOMEINFO #{$id} is a direct child of #view-home", in_array($id, $kids, true));
    }
}
$panel = sc_block($body, 'bookHelpPanel');
sc_check('the booking demo is no longer folded inside the guide', $panel !== '' && !str_contains($panel, 'animBooking') && !str_contains($panel, 'shg-animation'));
sc_check('the demo keeps its exact scene class attribute', str_contains($body, '<section class="container shg-animation" id="animBooking"'));
$demoHead = sc_block($body, 'demoHead');
sc_check('#demoHead labels the demo and offers QuickBot + seat map', str_contains($demoHead, 'data-i18n="dmEyebrow"') && str_contains($demoHead, 'data-scroll="quick-ticket"') && str_contains($demoHead, 'data-scroll="search-anchor"'));
sc_check('#demoHead sits before #animBooking in the DOM', $demoHead !== '' && strpos($body, 'id="demoHead"') < strpos($body, 'id="animBooking"'));

$hero = sc_block($body, 'homeHero');
sc_check('#homeHero holds no nested <section>', $hero !== '' && substr_count(strtolower($hero), '<section') === 1);
sc_check('hero: India + Nepal flags beside the headline', str_contains($hero, 'data-flag="IN"') && str_contains($hero, 'data-flag="NP"'));
sc_check('hero: live company name (token + data-fact)', str_contains($hero, 'data-fact="brand.name"') && str_contains($hero, '{{f:brand.name}}'));
sc_check('hero: Track key has the radar pings, My-ticket key the ticket icon', (bool) preg_match('/hh-track.*?sc-ping.*?#i-radar/s', $hero) && (bool) preg_match('/hh-my.*?#i-tkt2/s', $hero));
sc_check('hero: three keys, Book stays primary', substr_count($hero, 'class="hh-act ') === 3 && str_contains($hero, 'class="hh-act hh-book"'));

$feat = sc_block($body, 'homeFeat');
sc_check('#homeFeat keeps exactly 8 <li>', substr_count($feat, '<li>') + substr_count($feat, '<li ') === 8);
sc_check('#homeFeat: 8 icons + 8 translated labels', substr_count($feat, 'class="hf-ic"') === 8 && preg_match_all('/class="hf-tx" data-i18n="[A-Za-z0-9]+"/', $feat) === 8);
sc_check('#homeFeat: QuickBot card scrolls to QuickBot, tracking card opens the tracker', str_contains($feat, 'data-scroll="quick-ticket"') && str_contains($feat, 'data-track-bus'));
$claims = ['wi-fi', 'wifi', 'cctv', 'meal', 'snack', 'dinner', 'breakfast'];
$featLow = strtolower(strip_tags($feat));
$bad = array_values(array_filter($claims, fn ($w) => str_contains($featLow, $w)));
sc_check('#homeFeat makes no unverifiable claim (Wi-Fi / CCTV / meals)', $bad === [], implode(',', $bad));

$qt = sc_block($body, 'qtSteps');
sc_check('QuickBot 1-2-3 is an animated strip again', str_contains($qt, 'sc-qsteps') && substr_count($qt, 'class="sc-qs"') === 3);
sc_check('redesign.css no longer hides .qt-steps', !str_contains($redesign, '#quick-ticket .qt-ai, #quick-ticket .qt-steps { display: none !important; }'));

$ceo = sc_block($body, 'homeCeo');
sc_check('#homeCeo exists, no nested <section>', $ceo !== '' && substr_count(strtolower($ceo), '<section') === 1);
sc_check('CEO: lazy ceo-sm.webp photo', (bool) preg_match('#<img[^>]+/assets/img/ceo-sm\.webp\?v=[^"]+"[^>]*loading="lazy"#', $ceo));
sc_check('CEO: name / Nepali name from the facts', str_contains($ceo, 'data-fact="ceo.name"') && str_contains($ceo, 'data-fact="ceo.nameNe"'));
sc_check('CEO: call + WhatsApp from the facts', str_contains($ceo, 'data-fact-tel="ceo.tel"') && str_contains($ceo, 'data-fact-wa="ceo.wa"') && str_contains($ceo, 'href="tel:+{{f:ceo.tel}}"'));
sc_check('CEO: India + Nepal flags, translated role and message', str_contains($ceo, 'data-flag="IN"') && str_contains($ceo, 'data-flag="NP"') && str_contains($ceo, 'data-i18n="ceoRole"') && str_contains($ceo, 'data-i18n="ceoMsg"'));

$top = sc_block($body, 'topHero');
sc_check('#topHero keeps .th-brand (the fab-quiet observer watches it)', str_contains($top, 'class="th-brand"'));
sc_check('#topHero offices list comes from the facts', str_contains($top, 'id="saOffices">{{f:html:offices}}</ul>'));
sc_check('#topHero links to /offices', str_contains($top, 'href="/offices"'));

$help = sc_block($body, 'bookHelp');
sc_check('#bookHelp: 7 translated steps', preg_match_all('/data-i18n="bhS[1-7]T"/', $help) === 7 && preg_match_all('/data-i18n="bhS[1-7]D"/', $help) === 7);
sc_check('#bookHelp: title, tips and five colour buttons translated', str_contains($help, 'data-i18n="bhTitle"') && preg_match_all('/data-i18n="bhTip[1-3]"/', $help) === 3
    && str_contains($help, 'class="bh-cta"') && str_contains($help, 'class="bh-wa"') && str_contains($help, 'class="bh-quick"') && str_contains($help, 'class="bh-help"') && str_contains($help, 'class="bh-demo"'));
/** $html with every [data-i18n] element (and everything inside it) cut out. */
function sc_strip_i18n(string $html): string
{
    while (preg_match('/<([a-z0-9]+)\b[^>]*\bdata-i18n="[^"]+"[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $tag = strtolower($m[1][0]);
        $start = $m[0][1];
        $pos = $start + strlen($m[0][0]);
        $d = 1;
        while ($d > 0 && preg_match('/<(\/?)' . $tag . '\b[^>]*>/i', $html, $t, PREG_OFFSET_CAPTURE, $pos)) {
            $d += $t[1][0] === '/' ? -1 : 1;
            $pos = $t[0][1] + strlen($t[0][0]);
        }
        $html = substr($html, 0, $start) . substr($html, $pos);
    }
    return $html;
}
$helpText = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#<script\b.*?</script>#s', '', sc_strip_i18n($help)))));
sc_check('#bookHelp: no untranslated copy left outside i18n', !preg_match('/[\x{0900}-\x{097F}]/u', $helpText), mb_substr($helpText, 0, 80));

preg_match_all('/\bid="([^"]+)"/', $body, $ids);
$dupes = array_keys(array_filter(array_count_values($ids[1]), fn ($n) => $n > 1));
sc_check('every id in the body is unique', $dupes === [], implode(',', array_slice($dupes, 0, 8)));

/* ---------------------------------------------------------------------- */
echo "-- 2. Display order --\n";
$map = ['demoHead' => 5, 'animBooking' => 5, 'topHero' => 7, 'homeCeo' => 8, 'animRoute' => 13, 'bookHelp' => 14,
        'trust' => 30, 'agrBar' => 31, 'realReviews' => 32, 'homeMoreBar' => 33, 'homeExtra' => 34, 'homeMore' => 35];
foreach ($map as $id => $n) {
    sc_check("showcase.css: #{$id} order {$n}", (bool) preg_match('/#view-home > #' . preg_quote($id, '/') . '\b[^{]*\{ order: ' . $n . ' !important; \}/', $css)
        || (bool) preg_match('/#view-home > #' . preg_quote($id, '/') . ',\s*#view-home > #\w+ \{ order: ' . $n . ' !important; \}/', $css));
}
foreach (['#view-home > * { order: 10 !important; }', '#view-home > #homeHero { order: 0 !important; }'] as $pin) {
    sc_check('redesign.css keeps the pinned line ' . $pin, str_contains($redesign, $pin));
}
$pShow = strpos($tpl, '/assets/css/showcase.css');
$pDepth = strpos($tpl, '/assets/css/depth.css');
$pAnim = strpos($tpl, '/assets/css/anim.css');
sc_check('showcase.css loads after anim.css and before depth.css', $pShow !== false && $pDepth !== false && $pAnim !== false && $pAnim < $pShow && $pShow < $pDepth);
sc_check('21-showcase.js is loaded', (bool) preg_match('#<script defer src="/assets/js/21-showcase\.js\?v=[^"]+"></script>#', $tpl));

/* ---------------------------------------------------------------------- */
echo "-- 3. Logo: emblem only, no white tile --\n";
sc_check('SPA body never loads logo.png (baked name)', !preg_match('#/assets/img/logo\.png#', $body));
sc_check('footer uses the emblem (logo-mark)', (bool) preg_match('#id="logoFoot" src="/assets/img/logo-mark\.png#', $body));
sc_check('offices card uses the emblem', (bool) preg_match('#class="th-logo" src="/assets/img/logo-mark#', $body));
sc_check('demo phone uses the emblem', substr_count($body, 'class="sa-logo" src="/assets/img/logo-mark') === 4);
sc_check('hero + nav use logo-nav.png (the emblem, 240x125)', str_contains($hero, 'logo-nav.png') && str_contains($body, 'id="logoNav" src="/assets/img/logo-nav.png'));
foreach (['logo-nav.png' => 20000, 'logo-mark.png' => 200000, 'logo-mark-96h.png' => 60000] as $f => $max) {
    $p = $root . '/assets/img/' . $f;
    $info = is_file($p) ? @getimagesize($p) : false;
    sc_check("{$f} exists, is small and is a PNG", is_file($p) && filesize($p) < $max && $info !== false && $info[2] === IMAGETYPE_PNG, is_file($p) ? (string) filesize($p) : 'missing');
}
foreach (['logo-mark.webp', 'logo-mark-96h.webp', 'ceo-sm.webp', 'flag-in.svg', 'flag-np.svg'] as $f) {
    sc_check("{$f} exists", is_file($root . '/assets/img/' . $f));
}
if (function_exists('imagecreatefrompng')) {
    $im = @imagecreatefrompng($root . '/assets/img/logo-nav.png');
    $transparent = false;
    if ($im) {
        $c = imagecolorsforindex($im, imagecolorat($im, 0, 0));
        $transparent = $c['alpha'] > 100;
        imagedestroy($im);
    }
    sc_check('logo-nav.png corner is transparent (no white plate baked in)', $transparent);
}
$whiteTile = [
    'redesign .nav .brand-tile'   => [$redesign, '/\.nav \.brand-tile \{[^}]*background: #FFFFFF/i'],
    'depth .hh-logo-tile'         => [$depth, '/\.home-hero \.hh-logo-tile \{[^}]*background: #FFFFFF/i'],
    'redesign .th-logo'           => [$redesign, '/\.th-logo \{[^}]*background: #fff\b/i'],
    'anim .sa-logo'               => [$anim, '/\.sa-logo \{[^}]*background: #fff\b/i'],
    'app .f-grid img'             => [$app, '/\.f-grid img\{[^}]*background:rgba\(255,255,255,\.9\)/'],
    'views .co-op-logo'           => [$views, '/\.co-op-logo\{[^}]*background:#fff/'],
    'views .tk2-brand img'        => [$views, '/\.tk2-brand img\{[^}]*background:var\(--blue-50\)/'],
    'views premium ticket head'   => [$views, '/\.premium-ticket-head \.tk2-brand img\{[^}]*background:white/'],
];
foreach ($whiteTile as $label => [$src, $re]) {
    sc_check("no white logo tile: {$label}", !preg_match($re, $src));
}
sc_check('depth .nav .brand-tile slab shadow removed', !str_contains($depth, '.nav .brand-tile { box-shadow: inset 0 1px 0 #FFFFFF'));
sc_check('the emblem gets a soft drop-shadow so it reads on navy', str_contains($redesign, 'drop-shadow(') && str_contains($css, '#homeHero .hh-logo { position: relative; filter: drop-shadow('));

/* ---------------------------------------------------------------------- */
echo "-- 4. i18n --\n";
/** key => value pairs of one language block (en/hi/ne) */
function sc_lang_keys(string $i18n, string $lang): array
{
    $starts = [];
    foreach (['en', 'hi', 'ne'] as $l) {
        if (preg_match('/^' . $l . ': \{/m', $i18n, $m, PREG_OFFSET_CAPTURE)) $starts[$l] = $m[0][1];
    }
    if (!isset($starts[$lang])) return [];
    asort($starts);
    $order = array_keys($starts);
    $i = array_search($lang, $order, true);
    $end = isset($order[$i + 1]) ? $starts[$order[$i + 1]] : (int) (strpos($i18n, 'I18N.gu') ?: strlen($i18n));
    $blk = substr($i18n, $starts[$lang], $end - $starts[$lang]);
    preg_match_all('/(?:^|[\s,{])([A-Za-z_][A-Za-z0-9_]*)\s*:\s*[\'"]/', $blk, $mm);
    return array_flip($mm[1]);
}
$K = ['en' => sc_lang_keys($i18n, 'en'), 'hi' => sc_lang_keys($i18n, 'hi'), 'ne' => sc_lang_keys($i18n, 'ne')];
sc_check('three language blocks parsed', count($K['en']) > 500 && count($K['hi']) > 500 && count($K['ne']) > 500);
$scope = $hero . $feat . $qt . $demoHead . $top . $ceo . $help . sc_block($body, 'footer');
if ($scope === '' || !str_contains($scope, 'fTagline')) {
    if (preg_match('#<footer\b.*?</footer>#s', $body, $fm)) $scope .= $fm[0];
}
preg_match_all('/data-i18n="([A-Za-z0-9_]+)"/', $scope, $used);
$used = array_values(array_unique($used[1]));
$missing = [];
foreach ($used as $k) {
    foreach (['en', 'hi', 'ne'] as $l) {
        if (!isset($K[$l][$k])) $missing[] = "{$l}:{$k}";
    }
}
sc_check('every showcase data-i18n key exists in en / hi / ne (' . count($used) . ' keys)', $missing === [], implode(' ', array_slice($missing, 0, 12)));
foreach (['pkTitle', 'dmEyebrow', 'ceoMsg', 'bhTitle', 'bhS7D', 'offTitle', 'fTagline'] as $k) {
    sc_check("key {$k} in en / hi / ne", isset($K['en'][$k], $K['hi'][$k], $K['ne'][$k]));
}
/* the first-paint parser reads hh* keys from hi/ne: no backslash escapes there */
$fp = preg_match_all('/\bhh(?:Title|Tag|ActLbl|Book|BookSub|Track|TrackSub|My|MySub)\s*:\s*\'[^\']*\\\\/', $i18n);
sc_check('no escapes in the first-paint hero strings', $fp === 0);
sc_check('CEO message names no company literally (the name is a fact)', !preg_match('/ceoMsg:\s*\'[^\']*S Hari Global/', $i18n));

/* ---------------------------------------------------------------------- */
echo "-- 5. Facts: no typed phones / prices --\n";
$phones = [];
if (preg_match_all('/(?:\+91|\+977)[\s-]?\d[\d\s-]{7,}\d|wa\.me\/\d{6,}|tel:\+?\d{6,}/', $body, $pm)) $phones = array_values(array_unique($pm[0]));
sc_check('no typed phone number anywhere in the SPA body', $phones === [], implode(' | ', array_slice($phones, 0, 6)));
$money = [];
$waSheet = sc_block($body, 'waSheet');
$footer = preg_match('#<footer\b.*?</footer>#s', $body, $fm2) ? $fm2[0] : '';
foreach (['home' => $home, 'footer' => $footer, 'waSheet' => $waSheet] as $where => $html) {
    if (preg_match_all('/₹\s?\d[\d,]{2,}|NPR\s?\d+\.\d+|Rs\.?\s?\d{3,}/u', $html, $mm)) {
        foreach ($mm[0] as $v) $money[] = "{$where}:{$v}";
    }
}
sc_check('no typed price in the home view / footer / WhatsApp sheet', $money === [], implode(' | ', array_slice($money, 0, 6)));
sc_check('no typed company name in the hero / CEO / offices / footer name line', !str_contains($hero . $ceo . $top, 'S Hari Global Pvt Ltd') && !str_contains($footer, '>S Hari Global Pvt Ltd<'));
$nested = 0;
if (preg_match_all('/<([a-z0-9]+)\b[^>]*\bdata-i18n="[^"]+"[^>]*>(.*?)<\/\1>/s', $body, $im, PREG_SET_ORDER)) {
    foreach ($im as $x) {
        if (str_contains($x[2], 'data-fact')) $nested++;
    }
}
sc_check('no data-fact inside a [data-i18n] element', $nested === 0, (string) $nested);
sc_check('no element carries both data-i18n and data-fact', !preg_match('/<[^>]*\bdata-i18n="[^"]*"[^>]*\bdata-fact(?:-money)?="|<[^>]*\bdata-fact(?:-money)?="[^"]*"[^>]*\bdata-i18n="/', $body));
preg_match_all('/\{\{f:([a-z.:]+)\}\}/i', $body, $tok);
$known = ['brand.name', 'brand.legal', 'brand.cin', 'brand.email', 'brand.short', 'ceo.name', 'ceo.nameNe', 'ceo.phone', 'ceo.tel', 'ceo.wa',
          'head.phone', 'head.tel', 'head.wa', 'head.address', 'npr', 'fares.go.min', 'fares.go.max', 'fares.back.min', 'fares.back.max',
          'fares.private.single', 'fares.private.double', 'agent.direct', 'html:offices',
          'nepal.phone', 'nepal.tel', 'support.tel'];
$unknown = array_values(array_diff(array_unique($tok[1]), $known));
sc_check('every {{f:…}} token in the body is a known fact', $unknown === [], implode(',', $unknown));
/* with the DB up, ask SiteFacts itself: every token the template uses must be filled */
$tokMap = null;
if (is_file($root . '/includes/sitefacts.php') && is_file($root . '/includes/bootstrap.php')) {
    try {
        require_once $root . '/includes/bootstrap.php';
        require_once $root . '/includes/sitefacts.php';
        if (class_exists('SiteFacts') && method_exists('SiteFacts', 'tokens')) $tokMap = SiteFacts::tokens();
    } catch (Throwable $e) {
        echo "  (SiteFacts not available here: " . $e->getMessage() . " — token fill check skipped)\n";
    }
}
if (is_array($tokMap)) {
    $notServed = array_values(array_filter(array_unique($tok[1]), fn ($t) => !array_key_exists('{{f:' . $t . '}}', $tokMap)));
    sc_check('SiteFacts fills every token the template uses', $notServed === [], implode(',', $notServed));
    $empty = array_values(array_filter(array_unique($tok[1]), fn ($t) => array_key_exists('{{f:' . $t . '}}', $tokMap) && trim((string) $tokMap['{{f:' . $t . '}}']) === ''));
    sc_check('…and none of them renders empty on this database', $empty === [], implode(',', $empty));
}
sc_check('WhatsApp rows that carry a greeting keep their ?text (no data-fact-wa rewrite)', !preg_match('/wa\.me\/[^"]*\?text=[^"]*"[^>]*data-fact-wa=/', $body));
sc_check('footer: flags + live name + four new pages', str_contains($footer, 'data-flag="IN"') && str_contains($footer, 'data-fact="brand.name"')
    && str_contains($footer, 'href="/offices"') && str_contains($footer, 'href="/india-nepal"') && str_contains($footer, 'href="/explore"') && str_contains($footer, 'href="/tours"'));

/* ---------------------------------------------------------------------- */
echo "-- 6. Motion + smoothness --\n";
$cssNoComments = (string) preg_replace('#/\*.*?\*/#s', '', $css);
$badProps = [];
if (preg_match_all('/@keyframes\s+([\w-]+)\s*\{((?:[^{}]*\{[^{}]*\})*)\s*\}/', $cssNoComments, $kf, PREG_SET_ORDER)) {
    foreach ($kf as $k) {
        preg_match_all('/([a-z-]+)\s*:/', (string) preg_replace('/^[^{]*\{/m', '{', $k[2]), $props);
        foreach (array_unique($props[1]) as $p) {
            if (!in_array($p, ['transform', 'opacity'], true)) $badProps[] = $k[1] . ':' . $p;
        }
    }
}
sc_check('showcase keyframes found', isset($kf) && count($kf) >= 10, (string) (isset($kf) ? count($kf) : 0));
sc_check('keyframes move transform / opacity only', $badProps === [], implode(',', $badProps));
sc_check('prefers-reduced-motion honoured', str_contains($css, '@media (prefers-reduced-motion: reduce)'));
sc_check('html[data-motion="reduced"] honoured', substr_count($css, 'html[data-motion="reduced"]') >= 6);
sc_check('loops run only while on screen (.sc-live)', str_contains($css, '#homeHero.sc-live') && str_contains($js, "classList.toggle('sc-live'"));
sc_check('reveal hides nothing without IntersectionObserver', str_contains($css, 'html.sc-io .sc-reveal:not(.sc-in)') && str_contains($js, "de.classList.add('sc-io')"));
sc_check('21-showcase.js checks reduced motion (system + in-app)', str_contains($js, "getAttribute('data-motion') === 'reduced'") && str_contains($js, 'prefers-reduced-motion: reduce'));
sc_check('21-showcase.js plays SHGFeel tap sounds', str_contains($js, "SHGFeel.play") && str_contains($js, "feel('tap')"));
sc_check('pointer tilt lives in showcase.css (perspective + rotateX/Y)', str_contains($css, 'perspective(') && str_contains($css, 'rotateX(var(--sc-rx') && str_contains($css, 'rotateY(var(--sc-ry'));
sc_check('depth.css free of rotate / blur / :is / @keyframes', !preg_match('/rotate[XYZ]?\(|filter:\s*blur|:is\(|@keyframes/', $depth));
sc_check('new animation classes avoid the redesign.css kill list (sc- / pk- prefixes)', !preg_match('/\.(sc|pk)-[\w-]+[^{]*\{[^}]*animation:\s*none\s*!important/', (string) preg_replace('#/\*.*?\*/#s', '', $redesign)));
sc_check('below-fold sections skip rendering until near (content-visibility)', str_contains($css, 'content-visibility: auto'));
sc_check('dark + AMOLED token sets for the showcase', str_contains($css, ':root[data-theme="dark"] {') && str_contains($css, ':root[data-display="amoled"] {'));
sc_check('no external URL in showcase.css', !preg_match('#url\(\s*["\']?https?:#i', $css));

echo "\n";
echo $FAIL === 0 ? "PASSED: {$PASS}   FAILED: 0\n" : "PASSED: {$PASS}   FAILED: {$FAIL}\n";
exit($FAIL === 0 ? 0 : 1);
