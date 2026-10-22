<?php
/**
 * =====================================================================
 *  home-premium-test.php — the premium booking-first home, the five-tab
 *  bottom bar and the shell fixes (8 Oct 2026, owner prompt Phases 2, 4,
 *  10, 12, 13; audit D3 / D1 / D2 / D8 / D7-08 / D9-08 / D4-05 front).
 *
 *  Static checks on the template, the bundle and the stylesheets, plus
 *  HTTP checks against SHG_TEST_BASE (anonymous home, a staff session, the
 *  pages sitemap through the front controller):
 *
 *    • the hero: official logo, the localized headline + promise line,
 *      BOOK / TRACK MY BUS / MY TICKET, and the search card right after it
 *    • one facilities strip of 8; no duplicate Quick Ticket / WhatsApp cards
 *    • bottom bar HOME | BOOK | TRACK | TICKETS | HELP; Help opens #homeMore;
 *      Track opens the keyed tracker or My Tickets - never the anonymous map
 *    • toast: pointer-events none, lifted over the bar, length-based time
 *    • 14-counter.js only for staff; /help.php in the pages sitemap;
 *      the offer ribbon server-rendered while an offer runs
 *    • sw.js cache branches guarded; no '₹2,090'; +977 Nepal defaults;
 *      QuickBot +91 / +977 picker sent as `country`
 *
 *    SHG_TEST_BASE=http://localhost:8985 php -c .claude/php-dev.ini tests/home-premium-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function fnBody(string $src, string $name): string {
    $p = strpos($src, 'function ' . $name . '(');
    if ($p === false) { return ''; }
    $b = strpos($src, '{', $p); $d = 0;
    for ($i = $b, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') { $d++; } elseif ($src[$i] === '}') { $d--; if ($d === 0) { return substr($src, $b, $i - $b + 1); } }
    }
    return '';
}

echo "\n=== Premium home, bottom bar, shell ===\n\n";
$root   = dirname(__DIR__);
$tpl    = (string) file_get_contents($root . '/app.template.html');
$router = (string) file_get_contents($root . '/assets/js/05-router.js');
$i18n   = (string) file_get_contents($root . '/assets/js/04-i18n.js');
$cfg    = (string) file_get_contents($root . '/assets/js/02-config.js');
$adm    = (string) file_get_contents($root . '/assets/js/13-admin-routes.js');
$prem   = (string) file_get_contents($root . '/assets/js/19-premium.js');
$appCss = (string) file_get_contents($root . '/assets/css/app.css');
$ds     = (string) file_get_contents($root . '/assets/css/ds.css');
$depth  = (string) file_get_contents($root . '/assets/css/depth.css');
$sw     = (string) file_get_contents($root . '/sw.js');
$index  = (string) file_get_contents($root . '/index.php');

$iEn = strpos($i18n, "\nen: {"); $iHi = strpos($i18n, "\nhi: {"); $iNe = strpos($i18n, "\nne: {");
$iEnd = $iNe === false ? false : strpos($i18n, "\n};", $iNe);
$L = [
    'en' => substr($i18n, (int) $iEn, (int) $iHi - (int) $iEn),
    'hi' => substr($i18n, (int) $iHi, (int) $iNe - (int) $iHi),
    'ne' => substr($i18n, (int) $iNe, (int) $iEnd - (int) $iNe),
];
$val = static fn (string $lang, string $k): string => preg_match("~(?<![\\w\$])" . preg_quote($k, '~') . ":\\s*'([^']*)'~u", $L[$lang], $m) ? $m[1] : '';

echo "-- 1. The hero --\n";
$hero = preg_match('~<section[^>]*id="homeHero"[^>]*>(.*?)</section>~s', $tpl, $hm) ? $hm[1] : '';
check('#homeHero exists and comes before the search card', $hero !== '' && strpos($tpl, 'id="homeHero"') < strpos($tpl, 'id="search-anchor"'));
check('the official logo (a resample of logo.png), never redrawn', str_contains($hero, 'src="/assets/img/logo-nav.png?v=') && is_file($root . '/assets/img/logo-nav.png') && filesize($root . '/assets/img/logo-nav.png') < 20000);
check('the headline is the page h1', (bool) preg_match('~<h1 class="hh-title" id="homeHeadline" data-i18n="hhTitle">~', $hero));
check('en headline', $val('en', 'hhTitle') === 'INDIA ⇄ NEPAL PREMIUM AC SLEEPER BUS', $val('en', 'hhTitle'));
check('ne headline', $val('ne', 'hhTitle') === 'भारत ⇄ नेपाल प्रिमियम एसी स्लीपर बस', $val('ne', 'hhTitle'));
check('hi headline is Hindi', $val('hi', 'hhTitle') !== '' && $val('hi', 'hhTitle') !== $val('en', 'hhTitle') && str_contains($val('hi', 'hhTitle'), 'प्रीमियम'));
check('the promise line in en / hi / ne', str_starts_with($val('en', 'hhTag'), 'Safe Journey. Easy Booking.') && str_contains($val('ne', 'hhTag'), 'सजिलो बुकिङ') && str_contains($val('hi', 'hhTag'), 'आसान बुकिंग'));
foreach (['hhBook', 'hhTrack', 'hhMy'] as $k) {
    check("{$k} is localized (hi + ne differ from en)", $val('hi', $k) !== '' && $val('ne', $k) !== '' && $val('hi', $k) !== $val('en', $k) && $val('ne', $k) !== $val('en', $k));
}
check('BOOK scrolls to the search card', (bool) preg_match('~class="hh-act hh-book" href="#/" data-scroll="search-anchor"~', $hero));
check('TRACK MY BUS runs openTrackMyBus', (bool) preg_match('~class="hh-act hh-track" href="#/my" data-track-bus~', $hero));
check('MY TICKET opens #/my', str_contains($hero, 'class="hh-act hh-my" href="#/my"'));

echo "-- 2. One feature strip, no duplicate entries --\n";
$feat = preg_match('~<section[^>]*id="homeFeat"[^>]*>(.*?)</section>~s', $tpl, $fm) ? $fm[1] : '';
check('eight facilities, each with an icon and a caption', substr_count($feat, '<li>') === 8 && substr_count($feat, 'class="hf-ic"') === 8 && substr_count($feat, 'class="hf-tx" data-i18n=') === 8);
foreach (['hfAc', 'hfCharge', 'hfAid', 'hfGps', 'hfWa', 'hfHelp', 'hfBerth', 'hfSecure'] as $k) {
    check("{$k} in en / hi / ne", $val('en', $k) !== '' && $val('hi', $k) !== '' && $val('ne', $k) !== '');
}
check('the old brand band and amenity chips are gone', !str_contains($tpl, 'id="brandBand"') && !str_contains($tpl, '<ul class="sa-amen">'));
check('no duplicate Quick Ticket / WhatsApp cards (#entryCards)', !str_contains($tpl, 'id="entryCards"'));
check('the four duplicate hero-action cards are gone', !str_contains($tpl, 'id="heroActions"'));
check('offices card / Nepal team / animation sit below the search card and QuickBot',
    strpos($tpl, 'id="topHero"') > strpos($tpl, 'id="quick-ticket"') && strpos($tpl, 'id="quick-ticket"') > strpos($tpl, 'id="search-anchor"'));
check('no stale ₹2,090 literal', !str_contains((string) preg_replace('~<!--.*?-->~s', '', $tpl), '₹2,090'));
check('the style card price is filled from SHG_BOOT.locations', str_contains($tpl, 'id="styleShareFrom"') && str_contains($router, "\$('#styleShareFrom')"));
check('flPerPerson says per person, no "online"', $val('en', 'flPerPerson') === 'Per person' && $val('ne', 'flPerPerson') === 'प्रति व्यक्ति' && $val('hi', 'flPerPerson') === 'प्रति व्यक्ति');
check('FAQ + flag card say IST; Rupaidiha is in India', str_contains($tpl, 'India time (IST); Nepal (NPT) is 15 minutes ahead') && str_contains($tpl, 'India · Rupaidiha border (drop)') && !str_contains($tpl, 'arrival times are shown in Nepal time'));
check('launch countdown neutralised; AC sleeper coach', !str_contains($tpl, 'Launching 2 September 2026') && !str_contains($tpl, 'AC seater &amp; sleeper'));
check('remembered search (shg:lastSearch) saved on search, applied without a deep link',
    str_contains($router, "'shg:lastSearch'") && str_contains(fnBody($router, 'initSimpleSearch'), 'if (!applyCampaignLink()) applyLastSearch();'));

echo "-- 3. Bottom bar --\n";
$bn = preg_match('~<nav class="bottom-nav[^"]*"[^>]*>(.*?)</nav>~s', $tpl, $bm) ? $bm[1] : '';
preg_match_all('~data-bn="([a-z]+)"~', $bn, $bk);
// 22 Oct 2026, owner: "ticket paxi priority map lai deu"
check('five tabs in the owner\'s order HOME | BOOK | TICKETS | MAP | TRACK', $bk[1] === ['home', 'book', 'trips', 'nav', 'track'], implode(',', $bk[1]));
check('the Map tab opens the AI map page', str_contains($router, "if (k === 'nav') { location.href = '/navigate.html'; return; }"));
check('every tab has an icon and a label', substr_count($bn, '<svg class="ic ic-lg"') === 5 && substr_count($bn, 'class="bl" data-i18n=') === 5);
check('bnTrack in en / hi / ne', $val('en', 'bnTrack') === 'Track' && $val('ne', 'bnTrack') !== '' && $val('hi', 'bnTrack') !== '');
$sht = fnBody($router, 'scrollHomeTarget');
check('scrollHomeTarget opens #homeMore before scrolling into it', str_contains($sht, 'window.openHomeMore()') && str_contains($sht, "getElementById('homeMore')"));
check('the Help tab scrolls to #contact through scrollHomeTarget', str_contains($router, "scrollHomeTarget(k === 'book' ? 'search-anchor' : (k === 'help' ? 'contact' : 'top'))"));
$otb = fnBody($router, 'openTrackMyBus');
check('Track opens only a KEYED tracker (k=), else My Tickets with the PNR box', str_contains($otb, '[?&]k=') && str_contains($otb, "'#/my'") && str_contains($otb, "getElementById('trackId')"));
check('Track never sends a customer to the anonymous map as "my bus"', !str_contains($otb, '#/nav'));
check('the lit tab is announced (aria-current)', str_contains(fnBody($router, 'shgLightTab'), "setAttribute('aria-current', 'page')"));
check('the trkHint line exists in all three languages', $val('ne', 'trkHint') === 'PNR र मोबाइल हालेर बस ट्र्याक गर्नुहोस्' && $val('en', 'trkHint') !== '' && $val('hi', 'trkHint') !== '');

echo "-- 4. Toast, focus, titles, sounds --\n";
check('a showing toast takes no taps', (bool) preg_match('~#toast\.show\{[^}]*pointer-events:none~', $appCss));
check('the toast is wide enough (max-content, capped)', (bool) preg_match('~#toast\{[^}]*width:max-content;max-width:min\(90vw,480px\)~', $appCss));
$tb = fnBody($cfg, 'toast');
check('toast() lifts itself above the bottom bar / flow bar', str_contains($tb, ".bottom-nav") && str_contains($tb, '.flow-actions') && str_contains($tb, 't.style.bottom'));
check('toast() stays up longer for long text (3.2-9 s)', str_contains($tb, 'Math.min(9000, Math.max(3200'));
check('a tap on the toast dismisses it', str_contains($cfg, "document.addEventListener('pointerdown'") && str_contains($cfg, "t.classList.remove('show')"));
check('view changes set the tab title and move focus', str_contains(fnBody($router, 'showView'), 'shgViewTitle();') && str_contains(fnBody($router, 'showView'), 'shgFocusView(name);'));
check('following a menu link resets the hamburger aria-expanded', str_contains(fnBody($router, 'router'), "\$('#hamburger').setAttribute('aria-expanded', 'false')"));
check('error toasts play the error tone, not the info chime (D7-08)', str_contains($adm, 'if (shgToastLooksBad(msg)) SFX.error(); else SFX.notify();'));
check('sound + vibration stay ON by default (owner, 5 Oct)', str_contains($prem, "return v === null ? true : v === '1';"));
check('the phone language pill reads the live language (D2-06)', str_contains(fnBody($prem, 'currentLang'), "typeof LANG === 'string'") && str_contains($i18n, 'window.shgLangMiniLabel()'));
check('static translation runs before init (D2-05)', str_contains($i18n, 'function applyStaticLang()') && str_contains($i18n, "try { applyStaticLang(); } catch (e)"));
check('the template is served lang="ne" (the default visitor)', str_contains($tpl, '<html lang="ne"'));
check('qtDefaults names the plan\'s real stop ({stop}) in en / hi / ne', str_contains($val('en', 'qtDefaults'), '{stop}') && str_contains($val('hi', 'qtDefaults'), '{stop}') && str_contains($val('ne', 'qtDefaults'), '{stop}') && str_contains($router, "tf('qtDefaults', { stop:"));
check('the SW never reloads a passenger on results / seats / checkout (D8-11)', str_contains($adm, "h.indexOf('#/results') === 0 || h.indexOf('#/seats') === 0 || h.indexOf('#/checkout') === 0"));
check('the counter screen follows the panel theme when no customer theme is saved (D9-08)', str_contains($tpl, "localStorage.getItem('shg_admin_theme')") && str_contains((string) file_get_contents($root . '/assets/js/03-accounts.js'), "localStorage.getItem('shg_admin_theme')"));

echo "-- 5. Contacts + QuickBot country --\n";
$dcn = fnBody($router, 'defaultContactNumbers');
/* 9 Oct 2026 (site facts): the defaults now come from window.FACTS + SHG_BOOT.npTeam
   (Admin -> Company details); only the offline fallback keeps one typed Nepal line. */
check('Nepal team defaults dial +977', str_contains($dcn, "'+977 986-6201375'") && str_contains($dcn, 'SHG_BOOT.npTeam') && !str_contains($dcn, '+91 98662') && !str_contains($dcn, '+91 98481'));
check('QuickBot has a +91 / +977 picker with a visible label', (bool) preg_match('~<select id="qtCC"[^>]*>\s*<option value="91">[^<]*\+91</option><option value="977">[^<]*\+977</option>~', $tpl) && str_contains($tpl, '<label class="qt-lbl" for="qtPhone"'));
check('name and one-line inputs have visible labels (D2-07)', str_contains($tpl, '<label class="qt-lbl" for="qtName"') && str_contains($tpl, '<label class="qt-lbl" for="qtLine"'));
check('the sale payload carries country', str_contains($router, "action: 'customer_sell', name: qt.name, phone: qt.phone, country: qtCountry(),"));
check('the picker starts on the signed-in account\'s country', str_contains($router, 'qtPresetCountry((window.SHG_BOOT && window.SHG_BOOT.user && window.SHG_BOOT.user.country)'));
check('the proof re-submit form posts the booking phone (D8-03)', str_contains($adm, "fd.append('phone', String(ownPh)"));
check('the assistant input has a name', str_contains($tpl, 'id="aiInput"') && (bool) preg_match('~id="aiInput"[^>]*aria-label=~', $tpl));

echo "-- 6. Shell, service worker, tokens --\n";
check('logo preload has no fetchpriority=high', !preg_match('~rel="preload" as="image"[^>]*fetchpriority="high"~', $tpl) && str_contains($tpl, '<link rel="preload" as="image" href="/assets/img/logo-nav.png?v='));
check('the small logo is precached', str_contains($sw, "'/assets/img/logo-nav.png?v=' + ASSET_VER"));
$swFetch = (string) preg_replace('~^.*?self\.addEventListener\(\'fetch\'(.*?)\nself\.addEventListener\(.*$~s', '$1', $sw);
check('every cache-first branch survives a broken CacheStorage', str_contains($sw, 'async function plainNetwork(req)')
    && substr_count($swFetch, 'catch (e) { return plainNetwork(req); }') === 3 && !preg_match('~^\s*var cache = await caches\.open\(~m', $swFetch)
    && str_contains($swFetch, 'try { cache = await caches.open(TICKET_CACHE); }'));
check('the mp3 that 404s is gone from the footer', !str_contains($tpl, 'om-namo-narayanaya.mp3'));
check('footer: Help Desk, Track and the Nepal office line', str_contains($tpl, '<a href="/help.php" data-i18n="fHelpDesk">') && str_contains($tpl, 'data-track-bus data-i18n="mmTrack"') && str_contains($tpl, 'data-i18n="fNepalOffice"'));
check('gold tokens in light + dark (ds.css) and AMOLED (depth.css)', substr_count($ds, '--accent-gold:') === 2 && substr_count($ds, '--seat-private-ink:') === 2 && str_contains($depth, ':root[data-display="amoled"] { --accent-gold: #FFCF86;'));
check('brand primaries untouched (no #1876F2 primary)', !str_contains(strtolower($ds . $depth), '#1876f2'));
check('index.php strips 14-counter.js for non-staff', str_contains($index, "if (empty(\$boot['staff'])) {") && str_contains($index, '14-counter\\.js'));

echo "-- 7. HTTP --\n";
$base = rtrim((string) (getenv('SHG_TEST_BASE') ?: ''), '/');
if ($base === '') {
    echo "  (skipped: SHG_TEST_BASE not set)\n";
} else {
    $get = static function (string $url, ?string $jar = null, ?array $post = null): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
        if ($jar !== null) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        return ['code' => $code, 'head' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
    };
    $anon = $get($base . '/?ref=qa');
    check('anonymous home is 200', $anon['code'] === 200, (string) $anon['code']);
    check('no 14-counter.js tag for an anonymous visitor', !str_contains($anon['body'], '/assets/js/14-counter.js'));
    check('the headline + three keys are served', str_contains($anon['body'], 'id="homeHeadline"') && str_contains($anon['body'], 'class="hh-act hh-track"'));

    // The ribbon: server-rendered while an offer runs (a temporary auto-apply coupon when none does).
    require_once INCLUDE_PATH . '/fare.php';
    $tmpCoupon = 0;
    if (Fare::runningOffers() === []) {
        try {
            Database::run("INSERT INTO coupons (code, title, discount_type, discount_value, is_active, auto_apply, valid_from, valid_until) VALUES ('FBHOMETEST', 'FB test offer', 'percent', 5, 1, 1, CURDATE(), CURDATE() + INTERVAL 3 DAY)");
            $tmpCoupon = (int) Database::scalar("SELECT id FROM coupons WHERE code = 'FBHOMETEST'", [], 0);
        } catch (Throwable $e) { $tmpCoupon = -1; }
    }
    if ($tmpCoupon === -1) {
        echo "  (skipped ribbon: no running offer and the coupon table did not take a test row)\n";
    } else {
        $withOffer = $get($base . '/?ref=qa');
        check('the offer ribbon is server-rendered (no late reveal)', str_contains($withOffer['body'], '<a class="offer-ribbon" id="offerRibbon"') && (bool) preg_match('~id="offerRibbonText">[^<]+</span>~u', $withOffer['body']));
        check('...with the hi / en wording ready before paint', str_contains($withOffer['body'], 'Book now →') && str_contains($withOffer['body'], 'अभी बुक करें →'));
        if ($tmpCoupon > 0) { Database::run('DELETE FROM coupons WHERE id = :i', ['i' => $tmpCoupon]); }
    }

    // A staff session keeps the desk bundle.
    try { Database::run("DELETE FROM rate_limits WHERE action LIKE '%login%'"); } catch (Throwable $e) {}
    $jar = sys_get_temp_dir() . '/shg_fbhome_' . getmypid() . '.cookies'; @unlink($jar);
    $lp = $get($base . '/admin/login.php', $jar);
    preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $cm);
    $get($base . '/admin/login.php', $jar, ['shg_csrf' => $cm[1] ?? '', 'username' => 'superadmin', 'password' => 'admin123']);
    $staff = $get($base . '/', $jar);
    $isStaff = str_contains($staff['body'], '"staff":{');
    if (!$isStaff) {
        echo "  (staff login did not take on this server - skipped the staff check)\n";
    } else {
        check('a staff session keeps the 14-counter.js tag', str_contains($staff['body'], '<script defer src="/assets/js/14-counter.js?v='));
    }
    @unlink($jar);

    // /sitemap-pages.xml through the front controller (php -S serves .xml statically, so run it in-process).
    $ini = php_ini_loaded_file();
    $runner = sys_get_temp_dir() . '/shg_fb_sitemap_' . getmypid() . '.php';
    file_put_contents($runner, '<?php $_SERVER["REQUEST_URI"] = "/sitemap-pages.xml"; $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["HTTP_HOST"] = "localhost"; chdir(' . var_export($root, true) . '); include ' . var_export($root . '/index.php', true) . ';');
    $xml = (string) shell_exec(escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg($runner));
    @unlink($runner);
    check('the pages sitemap lists /help.php exactly once', substr_count($xml, '/help.php</loc>') === 1 && str_contains($xml, '</urlset>'), substr_count($xml, '<url>') . ' urls');
}

echo "\n----------------------------------------\n";
echo "$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
