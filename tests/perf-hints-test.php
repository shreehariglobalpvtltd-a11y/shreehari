<?php
/**
 * perf-hints-test.php — the performance / touch / TV pass of the customer
 * app (10 Oct 2026, owner: "feel like smooth 165 Hz animation, smooth
 * scrolling, touch support optimised for desktop and mobile, Android and
 * iPhone, best performance, works on TV too, on every device").
 *
 * Pins what must not quietly regress:
 *
 *   1. loading — assets/css/perf.css is linked with the asset stamp right
 *      BEFORE depth.css (which stays last) and is precached by sw.js;
 *   2. perf.css — tokens, touch-action manipulation (never pan-x / pan-y:
 *      pinch-zoom stays), nothing on the map canvas, hover resets that keep
 *      the press, live tap-target selectors, will-change only while moving,
 *      content-visibility never on the first screen, never twice and never
 *      on a section ending in a shadowed card, dvh sheets, 16 px phone
 *      fields, TV rules (TV only, no :has()), the device tiers (flat
 *      shadows that really win), the booking-card ring on every tier,
 *      every !important explained;
 *   3. listeners — no scroll / touchstart / touchmove / wheel listener in the
 *      customer bundles without { passive }, every resize handler goes
 *      through requestAnimationFrame, the visible 1 s tickers skip hidden tabs
 *      (each guard pinned together with its own interval);
 *   4. keyframes — every @keyframes in the customer stylesheets moves only
 *      transform / opacity / background-position; the named exceptions are
 *      checked for what they claim (one-shot SVG draw, switched off by
 *      redesign.css, or a class nothing uses);
 *   5. images — every <img> in app.template.html has width + height (two
 *      previews of the passenger's own upload excepted) and no inline blur;
 *   6. device tier + TV — 01-boot.js stamps data-tier and html.shg-tv; a slow
 *      frame-rate probe is applied only while the tab is hidden.
 *
 *   Every check marked "review 10 Oct" pins a finding of the independent
 *   review of the first build (see the comment above each one).
 *
 *   php tests/perf-hints-test.php        (static checks only — no DB, no server)
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

/** The text between the "(" of a call at $open and its matching ")", strings skipped. */
function call_args(string $src, int $open): string
{
    $depth = 0; $n = strlen($src); $q = '';
    for ($i = $open; $i < $n; $i++) {
        $c = $src[$i];
        if ($q !== '') {
            if ($c === '\\') { $i++; continue; }
            if ($c === $q) $q = '';
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; continue; }
        if ($c === '(') $depth++;
        elseif ($c === ')') { $depth--; if ($depth === 0) return substr($src, $open + 1, $i - $open - 1); }
    }
    return substr($src, $open + 1);
}

/** name => [file, props] for every @keyframes in a stylesheet (comments stripped). */
function keyframes(string $css): array
{
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    $out = [];
    if (!preg_match_all('~@(?:-webkit-)?keyframes\s+([\w-]+)\s*\{~', $css, $m, PREG_OFFSET_CAPTURE)) return $out;
    foreach ($m[0] as $i => $hit) {
        $start = $hit[1] + strlen($hit[0]); $depth = 1; $j = $start; $n = strlen($css);
        while ($depth > 0 && $j < $n) { if ($css[$j] === '{') $depth++; elseif ($css[$j] === '}') $depth--; $j++; }
        $body = substr($css, $start, $j - $start - 1);
        preg_match_all('~(?:^|[{;])\s*([a-z-]+)\s*:~i', $body, $pp);
        $out[$m[1][$i][0]] = array_values(array_unique(array_map('strtolower', $pp[1])));
    }
    return $out;
}

echo "\n=== Performance, touch, TV and device tiers ===\n\n";

$read  = static fn(string $p): string => (string) @file_get_contents($root . '/' . $p);
$perf  = $read('assets/css/perf.css');
$tpl   = $read('app.template.html');
$sw    = $read('sw.js');
$boot  = $read('assets/js/01-boot.js');
$views = $read('assets/css/views.css');
$redes = $read('assets/css/redesign.css');
$home  = $read('assets/css/homeinfo.css');
preg_match("~var ASSET_VER = '([^']+)'~", $sw, $vm);
$ver = $vm[1] ?? '';

/* ---------------------------------------------------------------- 1 */
echo "-- 1. loading --\n";
check('perf.css exists and is substantial', strlen($perf) > 6000, strlen($perf) . ' bytes');
preg_match_all('~<link rel="stylesheet" href="/assets/css/([a-z-]+)\.css\?v=([^"]+)"~', $tpl, $links);
$order = $links[1];
$iP = array_search('perf', $order, true);
$iD = array_search('depth', $order, true);
check('customer app links perf.css', $iP !== false, implode(' > ', $order));
check('perf.css is the stylesheet right before depth.css', $iP !== false && $iD !== false && $iP === $iD - 1);
check('depth.css is followed only by the reviewed polish overrides', array_slice($order, -2) === ['depth', 'polish']);
check('anim < showcase < perf (themes before depth too)', ($a = array_search('anim', $order, true)) !== false && ($s = array_search('showcase', $order, true)) !== false
    && ($t = array_search('themes', $order, true)) !== false && $a < $s && $s < $iP && $t < $iD);
check('perf.css carries the asset stamp', $iP !== false && $links[2][$iP] === $ver, $ver);
$pPre = strpos($sw, "'/assets/css/perf.css?v=' + ASSET_VER");
$dPre = strpos($sw, "'/assets/css/depth.css?v=' + ASSET_VER");
check('sw.js precaches perf.css with ASSET_VER, next to depth.css', $pPre !== false && $dPre !== false && $pPre < $dPre);
check('perf.css braces balance', substr_count($perf, '{') === substr_count($perf, '}'), substr_count($perf, '{') . ' blocks');
$broken = array_values(array_filter(preg_split('~\R~', $perf), static fn($l) => str_contains($l, '*/') && !str_contains($l, '/*') && preg_match('~\*/\s*[.:#a-z\[]~i', $l)));
check('no comment fragment inside a selector list', $broken === [], $broken ? substr($broken[0], 0, 80) : '');
check('no external URL in perf.css', !preg_match('~url\(|https?://~i', $perf));

/* ---------------------------------------------------------------- 2 */
echo "\n-- 2. perf.css --\n";
$code = (string) preg_replace('~/\*.*?\*/~s', '', $perf);   // rules only
check('no hex / rgb brand colour outside the shadow tokens', !preg_match('~#[0-9a-f]{3,8}\b~i', $code)
    && preg_match_all('~rgba?\(~', $code) === preg_match_all('~rgba\(0, 0, 0, \.\d+\)|rgba\(255, 255, 255, \.55\)~', $code));
check('controls: touch-action manipulation + no tap flash', str_contains($code, 'touch-action: manipulation;') && str_contains($code, '-webkit-tap-highlight-color: transparent;'));
/* review 10 Oct: `pan-x pan-y` also refuses a pinch-zoom that starts on the
   strip; `manipulation` pans both ways AND zooms */
check('strips: manipulation (pans both ways, still pinch-zooms), never pan-x / pan-y', !preg_match('~touch-action:\s*pan-[xy]~', $code));
check('date strip in the panning list', (bool) preg_match('~\.date-strip-30,[^{]*\{\s*touch-action: manipulation;~', $code));
check('nothing on the map canvas (#sn-map / maplibregl)', !preg_match('~#sn-map|maplibregl|canvas~i', $code));
check('sheets keep their overscroll (contain)', str_contains($code, 'overscroll-behavior: contain;'));
check('hover lifts reset under @media (hover: none)', (bool) preg_match('~@media \(hover: none\) \{[^@]*\.hx \.hx-tile:hover:not\(:active\) \{ transform: none;~s', $code));
/* review 10 Oct: a tap sets :hover AND :active, so a reset of equal
   specificity loading later wiped the press sink of every card below */
preg_match('~@media \(hover: none\) \{(.*?)\n\}~s', $code, $hn);
$hoverBlock = $hn[1] ?? '';
$pressKept = [];
foreach (['.hx .hx-tile' => 'assets/css/homeinfo.css', '.hx .hx-card' => 'assets/css/homeinfo.css', '#homeFeat .sc-perk.sc-go' => 'assets/css/showcase.css'] as $sel => $src) {
    $hasPress = (bool) preg_match('~' . preg_quote($sel, '~') . ':active \{ transform: scale~', $read($src));
    /* the card itself (or its ::after), not a child such as ".sc-pk-go" */
    $bare = (bool) preg_match('~' . preg_quote($sel, '~') . ':hover(?=\s*[{,]|::)~', $hoverBlock);
    if (!$hasPress || $bare) $pressKept[] = $sel;
}
check('...but a press still sinks (:hover:not(:active) where the card has a press rule)', $hoverBlock !== '' && $pressKept === [], implode(', ', $pressKept));
check('...and the open contact FAB keeps its turn', str_contains($hoverBlock, '.ct-fab:not([aria-expanded="true"]):hover') && !preg_match('~\.ct-fab:hover~', $code)
    && str_contains($views, '.ct-fab[aria-expanded="true"]{transform:rotate(12deg) scale(1.04)}'));
check('48 px targets on coarse pointers via --tap-min', (bool) preg_match('~@media \(pointer: coarse\) \{[^}]*min-height: var\(--tap-min, 48px\)~s', $code));
/* review 10 Oct: no selector that a stronger rule always beats */
preg_match('~@media \(pointer: coarse\) \{([^}]*)\{~', $code, $cm);
$deadTap = array_values(array_filter(['.deck-tab', '.my-tab', '.pm-tab', 'legend-sticky'], static fn($s) => str_contains($cm[1] ?? '', $s)));
if (preg_match('~(^|,)\s*\.lang-btn\b~', $cm[1] ?? '')) $deadTap[] = 'bare .lang-btn';
check('...no tap-target selector another rule always beats (deck / my / pm tabs, topbar lang keys)', ($cm[1] ?? '') !== '' && $deadTap === [] && !str_contains($code, '.glass'), implode(' ', $deadTap));
check('...the phone language menu items reach 48 px (premium.css has 40)', str_contains($cm[1] ?? '', '.lang-mini-pop .lang-btn') && str_contains($read('assets/css/premium.css'), 'min-height:40px;'));
check('pulsing keys: the loop steps aside on press, and on mouse hover only (hover: hover)',
    str_contains($code, '.sos-fab.show:active, .ai-mic.listening:active, #view-nav .sn-cb.locating:active { animation: none; }')
    && (bool) preg_match('~@media \(hover: hover\) \{\s*\.sos-fab\.show:hover, \.ai-mic\.listening:hover, #view-nav \.sn-cb\.locating:hover \{ animation: none; \}~', $code));
check('.btn and .seat lose their permanent will-change', (bool) preg_match('~(^|\n)\.btn, \.seat,[^{]*\{ will-change: auto; \}~', $code));
check('will-change only on :hover / :active / running scopes', !preg_match('~will-change: (transform|opacity)~', (string) preg_replace('~[^{}]*(:hover|:active|\.is-running|\.sc-live|\.sc-tilting|\.suggested|\.show|\.listening|\.locating)[^{}]*\{[^}]*\}~', '', $code)));
preg_match_all('~#view-home > #([\w-]+) \{ content-visibility: auto;~', $code, $cv);
$first = ['homeHero', 'search-anchor', 'nextTripBox', 'homeFeat', 'quick-ticket'];
/* 16 Oct 2026: #animBooking was the one home-page section perf.css gave a
   content-visibility rule; it left the page with the booking demo. */
check('perf.css adds no home-page content-visibility rule of its own', $cv[1] === [], implode(', ', $cv[1]));
check('...never on the first screen', array_intersect($cv[1], $first) === []);
/* review 10 Oct: content-visibility = paint containment, which clipped the
   shadow of the card each of these sections ends in (no vertical padding) */
$clips = ['bookHelp', 'homeCeo', 'topHero', 'homeExtra', 'homeMore'];
check('...never on a section that ends in a shadowed card (bookHelp / homeCeo / topHero / brochure)', array_intersect($cv[1], $clips) === [], implode(', ', array_intersect($cv[1], $clips)));
$allCss = '';
foreach (glob($root . '/assets/css/*.css') ?: [] as $f) $allCss .= (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($f));
check('...an animation scene still ends in a clean card: overflow hidden, no outer shadow', str_contains($allCss, '.shg-animation .sa-scene { position: relative; overflow: hidden;')
    && !preg_match('~\.sa-(?:scene|grid)(?:[.:\[][\w="-]+\]?)*\s*(?:,[^{]*)?\{[^}]*box-shadow~', $allCss));
$others = '';
foreach (glob($root . '/assets/css/*.css') ?: [] as $f) { if (basename($f) !== 'perf.css') $others .= (string) file_get_contents($f); }
$twice = array_values(array_filter($cv[1], static fn($id) => (bool) preg_match('~#view-home > #' . preg_quote($id, '~') . '[^{]*\{[^}]*content-visibility~', $others)));
check('...and never a second rule for one element (premium.css note)', $twice === [] && !in_array('homeMore', $cv[1], true), implode(', ', $twice));
$cvLines = array_filter(preg_split('~\R~', $code), static fn($l) => str_contains($l, 'content-visibility: auto;'));
check('every content-visibility has contain-intrinsic-size: auto', array_filter($cvLines, static fn($l) => !str_contains($l, 'contain-intrinsic-size: auto ')) === []);
check('anchors clear the sticky header on phones', str_contains($code, 'scroll-padding-top: calc(var(--appbar-h, 56px) + env(safe-area-inset-top, 0px)'));
check('30-day strip snaps gently (proximity)', str_contains($code, '.date-strip-30 { scroll-snap-type: x proximity; }'));
check('dvh sheets behind @supports (height: 1dvh)', str_contains($code, '@supports (height: 1dvh)') && str_contains($code, '.ai-panel { height: min(480px, 62dvh); }') && str_contains($code, '.ai-panel.max { height: auto; }'));
check('16 px fields on phones (no iOS zoom-on-focus)', (bool) preg_match('~@media \(max-width: 760px\) \{\s*\.ai-head select, \.fm-search input, \.pv-field input, \.qt-plan-row select,\s*#view-nav \.sn-sbar input, #view-nav \.sn-fld input \{ font-size: 16px; \}~', $code));
check('text-size-adjust 100%', str_contains($code, '-webkit-text-size-adjust: 100%;'));
/* review 10 Oct: a 1920 px desktop monitor is not a TV - its layout stays */
check('desktops keep their layout: no width-based block, the 1400 px container is TV only', !preg_match('~@media \(min-width: (1[0-9]{3}|[2-9][0-9]{3})px\)~', $code)
    && preg_match_all('~max-width: 1400px~', $code) === 1 && str_contains($code, 'html.shg-tv .container { max-width: 1400px; }'));
check('TV: 3 px focus ring on every control', str_contains($code, 'html.shg-tv :focus-visible { outline: 3px solid var(--royal) !important; outline-offset: 3px !important; }'));
check('TV: five-tab bar with labels, not during a booking', str_contains($code, 'html.shg-tv body:not(.in-flow) .bottom-nav { display: flex;') && str_contains($code, 'html.shg-tv .bottom-nav.bn5 .bn-item .bl { display: block;'));
/* review 10 Oct: :has() needs Chromium 105+; 2019-2022 TV browsers drop it */
check('...hidden on checkout without :has() (05-router sets body.in-flow on checkout)', !str_contains($code, ':has(')
    && str_contains($read('assets/js/05-router.js'), "document.body.classList.toggle('in-flow', name === 'results' || name === 'seats' || name === 'checkout');"));
check('TV: 20 px base type and hover lifts off (a press still sinks)', str_contains($code, 'html.shg-tv { font-size: 20px; }') && str_contains($code, 'html.shg-tv a:hover:not(:active)')
    && !preg_match('~html\.shg-tv [^{,]*:hover(?!:not\(:active\))~', $code));
check('low tier: blur off, softer shadows, decorative loops off', str_contains($code, 'html[data-tier="low"] *, html[data-tier="low"] *::before')
    && str_contains($code, '--shadow-3: 0 2px 6px') && str_contains($code, 'html[data-tier="low"] .shg-animation .sa-journey .sa-scenery'));
/* review 10 Oct: the flat shadows lost to the default (0,3,0) token sets
   (:root[data-ui="pixel"][data-theme], :root[data-palette][data-theme]).
   Every stacked shadow token any sheet declares is flattened with
   !important, and no other sheet declares one with !important. */
$TOK = ['--dp-panel', '--dp-card', '--dp-btn', '--dp-act', '--shadow-1', '--shadow-2', '--shadow-3'];
preg_match('~html\[data-tier="low"\] \{([^}]*)\}~', $code, $lt);
$lowBody = $lt[1] ?? '';
$weak = array_values(array_filter($TOK, static fn($t) => !preg_match('~' . preg_quote($t, '~') . ': [^;]+ !important;~', $lowBody)));
$otherImp = [];
foreach (glob($root . '/assets/css/*.css') ?: [] as $f) {
    if (basename($f) === 'perf.css') continue;
    $c = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($f));
    foreach ($TOK as $t) if (preg_match('~' . preg_quote($t, '~') . '\s*:[^;}]*!important~', $c)) $otherImp[] = basename($f) . ' ' . $t;
}
$declared = [];
foreach (glob($root . '/assets/css/*.css') ?: [] as $f) {
    if (basename($f) === 'perf.css') continue;
    if (preg_match_all('~(--dp-[a-z-]+|--shadow-\d)\s*:~', (string) file_get_contents($f), $dm)) $declared = array_merge($declared, $dm[1]);
}
check('...the flat shadow tokens win over every theme / palette set (!important, nobody else uses it)', $lowBody !== '' && $weak === [] && $otherImp === [], implode(', ', array_merge($weak, $otherImp)));
check('...and they cover the stacked slabs the sheets declare (panel, card, btn, act, shadow-1..3)', array_diff(['--dp-panel', '--dp-card', '--dp-btn', '--dp-act', '--shadow-1', '--shadow-2', '--shadow-3'], $declared) === []);
check('low tier keeps spinners / typing / listening loops', !preg_match('~html\[data-tier="low"\][^{]*(spin|skel|typing|listening|locating|loading)[^{]*\{[^}]*animation: none~', $code));
/* review 10 Oct: the "ring the booking card" cue reaches every tier */
check('no rule is high-tier only any more', !preg_match('~data-tier="high"~', $allCss));
check('booking-card ring: one ::after for both taps, every tier, opacity / transform only', str_contains($code, '#view-home .search-card.bk-flash::after, #view-home #search-anchor.hx-flash .search-card::after {')
    && str_contains($code, 'content: ""; display: block !important; position: absolute; inset: 0; pointer-events: none;')
    && str_contains($code, 'box-shadow: inset 0 0 0 3px var(--royal); opacity: 0;')
    && str_contains($code, '#view-home .search-card.bk-flash, #view-home #search-anchor.hx-flash .search-card { position: relative; }')
    && str_contains($redes, '#view-home .search-card::before, #view-home .search-card::after { display: none !important; }'));
$strip = static fn(string $s): string => (string) preg_replace('~/\*.*?\*/~s', '', $s);
check('...the old rings are gone (bkFlash never painted, hx-ring repainted the card)', !str_contains($strip($views), 'bkFlash') && !str_contains($strip($home), 'hx-ring')
    && str_contains($read('assets/js/05-router.js'), "sc.classList.add('bk-flash')") && str_contains($read('assets/js/24-homeinfo.js'), "anchor.classList.add('hx-flash')"));
check('...and stops for reduced motion (system + in-app switch)', (bool) preg_match('~@media \(prefers-reduced-motion: reduce\) \{\s*#view-home \.search-card\.bk-flash::after, #view-home #search-anchor\.hx-flash \.search-card::after \{ animation: none; \}~', $code)
    && str_contains($code, 'html[data-motion="reduced"] #view-home #search-anchor.hx-flash .search-card::after { animation: none; }'));
/* An !important on a rule line is explained by the comment right above its
   rule (rule bodies may run a few lines under that comment). */
$lines = preg_split('~\R~', $perf);
$why = []; $inComment = false;
foreach ($lines as $n => $l) {
    /* only rule lines count: skip every line that is (part of) a comment */
    $wasIn = $inComment;
    if (str_contains($l, '/*')) $inComment = true;
    if (str_contains($l, '*/')) { $inComment = false; continue; }
    if ($wasIn || $inComment) continue;
    if (!str_contains($l, '!important')) continue;
    $end = $n - 1;
    while ($end >= 0 && $n - $end <= 12 && !str_contains($lines[$end], '*/')) $end--;
    $start = $end;
    while ($start >= 0 && !str_contains($lines[$start], '/*')) $start--;
    $ctx = ($end >= 0 && $start >= 0) ? implode("\n", array_slice($lines, $start, $end - $start + 1)) : '';
    if (!str_contains($ctx, '!important:')) $why[] = 'line ' . ($n + 1);
}
check('every !important says what it overrides', $why === [], implode(', ', $why));
check('perf.css adds one animation only, opacity / transform only', keyframes($perf) === ['shg-perf-ring' => ['opacity', 'transform']], json_encode(keyframes($perf)));

/* ---------------------------------------------------------------- 3 */
echo "\n-- 3. listeners and tickers (customer bundles) --\n";
$bundles = array_merge(glob($root . '/assets/js/[0-2][0-9]-*.js') ?: [], [$root . '/assets/js/agent-picker.js', $root . '/assets/js/track-live.js']);
/* MapLibre's own gestures may need a non-passive listener in the map
   bundle; none is used today, the door stays documented. */
$allowNonPassive = ['15-nav.js' => 0];
$nonPassive = []; $resizeNoRaf = []; $listeners = 0; $allowUsed = [];
foreach ($bundles as $f) {
    $src = (string) file_get_contents($f);
    $base = basename($f);
    /* direct form: addEventListener('scroll', ...) */
    if (preg_match_all('~addEventListener\(\s*([\'"])(scroll|touchstart|touchmove|wheel|resize)\1~', $src, $hm, PREG_OFFSET_CAPTURE)) {
        foreach ($hm[0] as $k => $h) {
            $listeners++;
            $ev = $hm[2][$k][0];
            $args = call_args($src, $h[1] + strlen('addEventListener'));
            $line = substr_count(substr($src, 0, $h[1]), "\n") + 1;
            if ($ev === 'resize') {
                $ok = str_contains($args, 'requestAnimationFrame');
                if (!$ok && preg_match('~^\s*[\'"]resize[\'"]\s*,\s*([A-Za-z_$][\w$]*)\s*[,)]?~', $args . ')', $nm)) {
                    $p = preg_match('~(?:var|let|const)\s+' . preg_quote($nm[1], '~') . '\s*=\s*function|function\s+' . preg_quote($nm[1], '~') . '\s*\(~', $src, $dm, PREG_OFFSET_CAPTURE) ? $dm[0][1] : -1;
                    $ok = $p >= 0 && str_contains(substr($src, $p, 400), 'requestAnimationFrame');
                }
                if (!$ok) $resizeNoRaf[] = "$base:$line";
            } elseif (!preg_match('~passive\s*:\s*true~', $args)) {
                if (isset($allowNonPassive[$base])) { $allowUsed[] = "$base:$line"; continue; }
                $nonPassive[] = "$base:$line $ev";
            }
        }
    }
    /* list form: ['pointerdown', 'touchstart'].forEach(function (ev) { x.addEventListener(ev, ..., { passive: true }) }) */
    if (preg_match_all('~\[[^\]]*[\'"](?:scroll|touchstart|touchmove|wheel)[\'"][^\]]*\]\s*\.forEach\(~', $src, $lm, PREG_OFFSET_CAPTURE)) {
        foreach ($lm[0] as $h) {
            $listeners++;
            $body = call_args($src, $h[1] + strlen($h[0]) - 1);
            $line = substr_count(substr($src, 0, $h[1]), "\n") + 1;
            if (!preg_match('~addEventListener\(.*?passive\s*:\s*true~s', $body)) $nonPassive[] = "$base:$line (list)";
        }
    }
}
check('scroll / touch / wheel / resize listeners found to check', $listeners >= 5, $listeners . ' listeners');
check('every scroll / touchstart / touchmove / wheel listener is passive', $nonPassive === [], implode('; ', $nonPassive));
check('the map bundle allow-list is not silently growing', count($allowUsed) <= array_sum($allowNonPassive), implode('; ', $allowUsed));
check('every resize handler runs through requestAnimationFrame', $resizeNoRaf === [], implode('; ', $resizeNoRaf));
$cfg = $read('assets/js/02-config.js');
check('location search: one re-aim per frame, capturing + passive scroll', str_contains($cfg, "window.addEventListener('scroll', placeSoon, { capture: true, passive: true });")
    && str_contains($cfg, 'placeRaf = requestAnimationFrame(function(){ placeRaf = 0; if(open) place(); });') && !str_contains($cfg, "if(open) place(); }, true);"));
$tick = [
    /* the needles pin each guard together with its own interval (review 10 Oct) */
    '05-router.js QuickTicket undo countdown'  => ['assets/js/05-router.js', "var iv = setInterval(function () {\n      if (document.hidden) { if (Date.now() >= r.until) { box.remove(); clearInterval(iv); } return; }\n      if (!tick()) clearInterval(iv);\n    }, 1000);"],
    '05-router.js hold pills'                  => ['assets/js/05-router.js', 'holdInt = setInterval(function () { if (!document.hidden) updateHoldPills(); }, 1000);'],
    '08-signin.js OTP resend countdown'        => ['assets/js/08-signin.js', "if (document.hidden) return;\n    const left = Math.ceil((until - Date.now()) / 1000);"],
    '13-admin-routes.js live clock'            => ['assets/js/13-admin-routes.js', 'setInterval(function () { if (!document.hidden && el.isConnected'],
    '10-track.js home live coaches'            => ['assets/js/10-track.js', "function load() {\n    if (document.hidden || !seen) return;"],
    '16-lazy.js pickup countdown'              => ['assets/js/16-lazy.js', 'TC.countdownT = setInterval(function () { if (!document.hidden) tick(); }, 1000);'],
    '16-lazy.js where-is poll'                 => ['assets/js/16-lazy.js', 'TC.manualT = setInterval(function () { if (!document.hidden) pull(); }, 45000);'],
    '22-routeline.js next-departure countdown' => ['assets/js/22-routeline.js', "ticking = setInterval(function () {\n      if (D.hidden) return;"],
    'agent-picker.js keep-in-step'             => ['assets/js/agent-picker.js', "var tick = setInterval(function () {\n      if (!sel.isConnected) { clearInterval(tick); return; }\n      if (document.hidden) return;"],
    'track-live.js clock'                      => ['assets/js/track-live.js', 'setInterval(function () { if (!document.hidden) clock(); }, 1000);'],
];
foreach ($tick as $label => [$file, $needle]) {
    $src = str_replace("\r\n", "\n", $read($file));
    check("ticker skips hidden tabs: {$label}", str_contains($src, $needle));
}

/* ---------------------------------------------------------------- 4 */
echo "\n-- 4. keyframes move transform / opacity only --\n";
$OK_PROPS = ['transform', 'opacity', 'background-position', 'visibility'];
/* The named exceptions, each with what it claims and how that is checked. */
$EXC = [
    // a one-shot SVG stroke drawing: paint of one small path, never a loop
    'ckDraw'           => ['svg', ''], 'ckCircle' => ['svg', ''], 'motifDraw' => ['svg', ''],
    'routeDraw'        => ['svg', ''], 'rovTrace' => ['svg', ''], 'rovFill'   => ['svg', ''], 'smDraw' => ['svg', ''],
    // switched off by redesign.css "2. Decoration off" (animation: none !important)
    'shgBlink'         => ['off', '.shg-live i'],
    'shgGlow'          => ['off', '.shg-stop i'],
    'shgBus'           => ['off', '.shg-bus'],
    'shgDash'          => ['off', '.shg-dash'],
    'waFabPulse'       => ['off', '.whatsapp-fab'],
    'vipGlow'          => ['off', '.premium-mode .vip-badge'],
    'splashMotifPulse' => ['off', '.splash-motif'],
    'logoGlow'         => ['off', '.topbar-logo img'],
    'ceoRing'          => ['off', '.f-ceo img'],
    'introIconPulse'   => ['off', '.intro-icon-big'],
    'tcUrgent'         => ['off', '.tc-card.urgent'],
    'rovDash'          => ['off', '.rov-line'],
    'psPulse'          => ['off', '.paystep li.now i'],
    // the class that would run it is in no page and no script
    'splashLogoIn'     => ['unused', 'splash-logo'],
    'jtHighlight'      => ['unused', 'hl-stop'],
    'jtPulse'          => ['unused', 'jt-node'],
    'gpsPulse'         => ['unused', 'gps-pulse-dot'],
];
$killStart = strpos($redes, '2. Decoration off');
$killEnd = $killStart !== false ? strpos($redes, 'animation: none !important;', $killStart) : false;
$kill = ($killStart !== false && $killEnd !== false) ? substr($redes, $killStart, $killEnd - $killStart) : '';
$used = $tpl . $read('index.php');
foreach (glob($root . '/assets/js/*.js') ?: [] as $f) $used .= (string) file_get_contents($f);
$bad = []; $exUsed = []; $total = 0;
foreach (['anim', 'showcase', 'premium', 'app', 'views', 'homeinfo', 'themes', 'ds', 'redesign', 'depth', 'journey', 'perf'] as $sheet) {
    $css = $read("assets/css/{$sheet}.css");
    foreach (keyframes($css) as $name => $props) {
        $total++;
        $extra = array_values(array_diff($props, $OK_PROPS));
        if ($extra === []) continue;
        if (!isset($EXC[$name])) { $bad[] = "{$sheet}.css {$name}: " . implode(',', $extra); continue; }
        [$kind, $arg] = $EXC[$name];
        $exUsed[$name] = true;
        $flat = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        if ($kind === 'svg') {
            $odd = array_diff($extra, ['stroke-dashoffset', 'stroke-dasharray', 'fill-opacity']);
            $loop = (bool) preg_match('~animation:[^;}]*\b' . preg_quote($name, '~') . '\b[^;}]*infinite~', $flat);
            if ($odd !== [] || $loop) $bad[] = "{$sheet}.css {$name}: not a one-shot SVG draw";
        } elseif ($kind === 'off') {
            if ($kill === '' || !str_contains($kill, $arg)) $bad[] = "{$sheet}.css {$name}: {$arg} is not in redesign.css's decoration-off list";
        } elseif ($kind === 'unused') {
            if (str_contains($used, $arg)) $bad[] = "{$sheet}.css {$name}: {$arg} is used again - convert it to transform / opacity";
        }
    }
}
check('keyframes found in the customer stylesheets', $total >= 60, $total . ' @keyframes');
check('every @keyframes moves transform / opacity / background-position (or is a checked exception)', $bad === [], implode('; ', $bad));
$stale = array_diff(array_keys($EXC), array_keys($exUsed));
check('no stale exception left in the list', $stale === [], implode(', ', $stale));
check('converted: SOS / mic / trip / locating / seat pulses', str_contains($views, '@keyframes sosPulse{0%,100%{transform:scale(1)}')
    && str_contains($views, '@keyframes tcPulse{0%,100%{transform:scale(1)}')
    && str_contains(str_replace("\r\n", "\n", $views), "@keyframes snLocPulse{\n  0%,100%{transform:scale(1)}")
    && str_contains($read('assets/css/app.css'), '@keyframes seatSuggestPulse{0%,100%{transform:scale(1)}'));
/* review 10 Oct: redesign.css's box-shadow: none !important always hid the
   old glow, so the badge looked still - it must not start moving now */
check('QuickBot badge stays still (no qtPulse loop)', !preg_match('~qtPulse~', $strip($views)) && !preg_match('~\.qt-badge\{[^}]*animation:(?!none)~', $views)
    && str_contains($redes, '#quick-ticket .qt-badge'));
check('aiPick lights up with opacity, not filter', str_contains($views, '@keyframes aiPick{0%{transform:scale(.6) rotate(-8deg);opacity:.6}'));
$show = $read('assets/js/21-showcase.js');
$paint = preg_match('~function paint\(\) \{(.*?)\n    \}~s', str_replace("\r\n", "\n", $show), $pm) ? $pm[1] : '';
check('pointer tilt: one rect read per frame, inside requestAnimationFrame', str_contains($show, 'if (!raf) raf = window.requestAnimationFrame(paint);')
    && substr_count($paint, 'getBoundingClientRect') === 1 && strpos($paint, 'getBoundingClientRect') < strpos($paint, 'setProperty'));
$anim = $read('assets/js/20-anim.js');
check('scenes animate with Web Animations (transform / opacity), paused off screen', str_contains($anim, 'a.pause()') && !preg_match('~\{\s*(width|height|top|left|filter|boxShadow)\s*:~', $anim));

/* ---------------------------------------------------------------- 5 */
echo "\n-- 5. images and fonts --\n";
$html = (string) preg_replace(['~<!--.*?-->~s', '~<script\b.*?</script>~is'], '', $tpl);
preg_match_all('~<img\b[^>]*>~i', $html, $im);
/* the passenger's own upload, shown after they pick it: no size exists yet.
   17 Oct 2026: #h3Bus is the coach in the 3D band above the booking card - the
   first picture on every screen, so it is eager (fetchpriority="high") on purpose. */
$exempt = ['ppPreviewImg', 'pvPreview', 'h3Bus'];
$noSize = []; $noAsync = [];
foreach ($im[0] as $tag) {
    $id = preg_match('~\bid="([^"]+)"~', $tag, $x) ? $x[1] : '';
    if (in_array($id, $exempt, true)) continue;
    if (!preg_match('~\bwidth="\d+"~', $tag) || !preg_match('~\bheight="\d+"~', $tag)) $noSize[] = substr($tag, 0, 70);
    if (!str_contains($tag, 'decoding="async"')) $noAsync[] = substr($tag, 0, 70);
}
check('images found in the template', count($im[0]) >= 25, count($im[0]) . ' <img>');
check('every <img> has width + height (CLS)', $noSize === [], implode(' | ', $noSize));
check('every <img> decodes off the main thread', $noAsync === [], implode(' | ', $noAsync));
$eager = array_values(array_filter($im[0], static fn($t) => !str_contains($t, 'loading="lazy"') && !in_array(preg_match('~\bid="([^"]+)"~', $t, $x) ? $x[1] : '', $exempt, true)));
check('only the logo (one file) is eager; everything else is lazy', array_filter($eager, static fn($t) => !str_contains($t, 'logo-nav.png') && !str_contains($t, 'data-logo-clone')) === [], (string) count($eager));
check('no inline backdrop blur in the template (#nextDepBox)', !str_contains($tpl, 'backdrop-filter'));
check('self-hosted brand font: font-display swap + one preload', substr_count($tpl, 'font-display:swap') >= 4 && substr_count($tpl, 'rel="preload" as="font"') === 1 && !str_contains($tpl, 'fonts.googleapis.com/css'));

/* ---------------------------------------------------------------- 6 */
echo "\n-- 6. device tier + TV (01-boot.js) --\n";
check('01-boot.js stamps data-tier low | mid | high', str_contains($boot, "d.setAttribute('data-tier', t.tier);") && str_contains($boot, "tier = 'low'") && str_contains($boot, "tier = 'mid'") && str_contains($boot, "var tier = 'high';"));
check('signals: cores, memory, Save-Data / 2G / 3G, reduced motion, fps probe', str_contains($boot, 'nav.hardwareConcurrency') && str_contains($boot, 'nav.deviceMemory')
    && str_contains($boot, 'conn.saveData') && str_contains($boot, "et === '3g'") && str_contains($boot, '(prefers-reduced-motion: reduce)') && str_contains($boot, 'fps < 40'));
check('fps probe: ~20 frames, once per visit, idle + visible, kept in sessionStorage', str_contains($boot, 'times.length < 21') && str_contains($boot, "FPS_KEY = 'shg:fps'")
    && str_contains($boot, 'requestIdleCallback') && str_contains($boot, 'if (document.hidden) return;') && str_contains($boot, 'sessionStorage.setItem(k, v)'));
/* review 10 Oct: a slow probe must not flatten the page under the user's eyes */
$probe = preg_match('~var probe = function \(\) \{(.*?)\n      \};~s', str_replace("\r\n", "\n", $boot), $pb) ? $pb[1] : '';
check('a slow probe is stored and applied only once the tab is hidden (or next load)', $probe !== '' && !str_contains($probe, 'apply(cur)')
    && str_contains($probe, "document.addEventListener('visibilitychange', onHide);") && str_contains($probe, 'if (!document.hidden) return;')
    && str_contains($probe, 'apply(next);') && str_contains($probe, "write(KEY, JSON.stringify(next));"));
check('window.SHG_TIER = { tier, cores, memGB, fps, tv }', str_contains($boot, 'window.SHG_TIER = t;') && str_contains($boot, 'return { tier: tier, cores: cores, memGB: memGB, fps: fps, tv: tv };'));
$tvRe = preg_match('~var TV_RE = (/[^\n]+/);~', $boot, $tr) ? $tr[1] : '';
$tvOk = true;
foreach (['SmartTV', 'SMART-TV', 'BRAVIA', 'Tizen', 'Web0S', 'WebOS', 'NetCast', 'AFTT', 'AFTM', 'CrKey', 'Roku', 'Viera', 'HbbTV', 'AndroidTV', 'Android TV', 'GoogleTV'] as $ua) {
    if (!str_contains($tvRe, $ua)) $tvOk = false;
}
check('TV user agents (Tizen, webOS, Fire TV, Chromecast, Roku, HbbTV, Android TV ...)', $tvRe !== '' && $tvOk, $tvRe);
$phpRe = $tvRe !== '' ? '~' . str_replace('~', '\\~', substr($tvRe, 1, -1)) . '~' : '~^$~';
check('...matches a Samsung TV and not an iPhone', (bool) preg_match($phpRe, 'Mozilla/5.0 (SMART-TV; LINUX; Tizen 6.0) AppleWebKit/537.36 (KHTML, like Gecko) 76.0.3809.146/6.0 TV Safari/537.36')
    && !preg_match($phpRe, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'));
check('html.shg-tv is set by the boot script and styled by perf.css', str_contains($boot, "d.classList.add('shg-tv')") && str_contains($perf, 'html.shg-tv'));
check('the tier block cannot take the app down (own try/catch)', (bool) preg_match('~\(function \(\) \{\s*try \{\s*var d = document\.documentElement, nav = window\.navigator~', $boot) && str_contains($boot, "/* no tier: perf.css treats the page as mid"));
check('Safari core count trusted only next to a memory figure', str_contains($boot, '(memGB && cores && cores <= 2)'));

echo "\n  ----------------------------------------\n  PASSED: {$PASS}   FAILED: {$FAIL}\n\n";
exit($FAIL === 0 ? 0 : 1);
