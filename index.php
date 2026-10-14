<?php
/**
 * =====================================================================
 *  index.php — the front controller for the public website.
 *
 *  It boots the backend, hands the browser a small bootstrap object
 *  (public settings, a CSRF token, the API base and the signed-in user)
 *  and then serves the full single-page application, wiring its
 *  window.storage seam to the server so operational data is shared and
 *  admin-managed instead of trapped in one browser.
 *
 *  The application markup lives in app.template.html (protected from
 *  direct access by .htaccess) so this controller stays readable.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';

/* ---------------------------------------------------------------------
 *  Bootstrap payload for the browser.
 * ------------------------------------------------------------------- */
/* 18 Sep 2026: real server-rendered legal pages at plain URLs (Meta app
   review and crawlers do not run the SPA; these used to return the homepage). */
$legalPath = rtrim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'), '/');
if (in_array($legalPath, ['/privacy-policy', '/privacy', '/terms-of-service', '/data-deletion'], true)) {
    require_once INCLUDE_PATH . '/legal.php';
    LegalPages::render(LegalPages::ROUTES[$legalPath]);
}
/* 2 Oct 2026: the refund & cancellation policy at a plain URL (the page itself
   was added on live overnight as refund-policy.php; /refund-policy used to
   fall through to the homepage). */
if ($legalPath === '/refund-policy') {
    require __DIR__ . '/refund-policy.php';
    exit;
}
/* 2 Oct 2026: India ⇄ Nepal border travel info (documents, crossing, FAQ)
   at a plain URL — same pattern as the refund policy above. */
if ($legalPath === '/travel-info') {
    require __DIR__ . '/travel-info.php';
    exit;
}
/* 24 Sep 2026: server-rendered route pages (/bus, /bus/<slug>, /hi/bus/…,
   /ne/bus/…) and the two dynamic sitemaps — what a search engine reads. */
/* 8 Oct 2026 (D3-18): the Help Desk page joins the pages sitemap. The list
   itself is built in includes/routepages.php (RoutePages::sitemapPages, not
   this file's); until /help.php is added there, the served XML gets the row
   here - added once, only when it is missing, before </urlset>. */
if ($legalPath === '/sitemap-pages.xml') {
    ob_start(static function (string $xml): string {
        if ($xml === '' || str_contains($xml, '/help.php</loc>') || !str_contains($xml, '</urlset>')) {
            return $xml;
        }
        $row = '  <url><loc>' . htmlspecialchars(rtrim(APP_URL, '/') . '/help.php', ENT_XML1) . '</loc><lastmod>' . date('Y-m-d')
             . '</lastmod><changefreq>monthly</changefreq><priority>0.5</priority></url>' . "\n";
        return str_replace('</urlset>', $row . '</urlset>', $xml);
    });
}
if ($legalPath === '/bus' || str_starts_with($legalPath, '/bus/') || str_starts_with($legalPath, '/hi/bus') || str_starts_with($legalPath, '/ne/bus')
    || $legalPath === '/sitemap-routes.xml' || $legalPath === '/sitemap-pages.xml') {
    require_once INCLUDE_PATH . '/routepages.php';
    RoutePages::dispatch($legalPath);
}
/* 9 Oct 2026: the brand / travel pages (/india-nepal, /offices, /explore,
   /tours, /tours/<slug>, each also under /hi/ and /ne/) and their sitemap -
   includes/contentpages.php. Served before Auth::user(); no app bundle.
   10 Oct 2026 (BIZ): /business, the business portfolio, joins them. */
if (preg_match('#^/(?:(?:hi|ne)/)?(?:india-nepal|offices|explore|tours|business)(?:/|$)#', $legalPath) === 1 || $legalPath === '/sitemap-content.xml') {
    require_once INCLUDE_PATH . '/contentpages.php';
    ContentPages::dispatch($legalPath);
}

$user = Auth::user();

/* The country the signed-in customer chose at sign-in (users.country_code),
   as 'NP'/'IN'. The checkout pre-selects its number-country picker from this
   so a Nepali traveller's ticket is not defaulted to +91 — India and Nepal
   share 10-digit mobiles, so the country cannot be read off the number. */
$userCountry = '';
if ($user !== null) {
    $rec = Auth::userRecord();
    $cc  = preg_replace('/\D/', '', (string) ($rec['country_code'] ?? '')) ?? '';
    $userCountry = $cc === '977' ? 'NP' : ($cc === '91' ? 'IN' : '');
}

$boot = [
    'apiBase'  => '/api',
    'appUrl'   => APP_URL,
    'csrf'     => Security::csrfToken(),
    'csrfName' => CSRF_TOKEN_NAME,
    'env'      => APP_ENV,
    // Whether this visitor may write the shared company store. Mirrors
    // kv_may_manage() in api/kv.php, and lets the bridge below skip a POST
    // that would only ever come back 403 — a guest was firing five of those
    // on every single page load.
    'canSync'  => Auth::can('dashboard.view'),
    // v4.0: is the Sahayak AI fallback configured? Boolean only — the key
    // itself stays server-side (api/ai-proxy.php). Drives the "AI" badge
    // and whether the bot escalates unmatched questions to the proxy.
    // 5 Oct 2026: any of the one AI's keys (Gemini / GPT / Claude, includes/aibrain.php) -
    // live has only Gemini, so this was false and the widget never asked the AI.
    'ai'       => Settings::getString('anthropic_api_key', '') !== '' || Settings::getString('gemini_api_key', '') !== ''
                  || Settings::getString('openai_api_key', '') !== '',
    // The contact button on every screen (24 Sep 2026): call / WhatsApp the
    // office without hunting for the number.
    'contact'  => ['phone' => Settings::officePhone(), 'wa' => Settings::officeWhatsApp()],
    // 24 Sep 2026: the assistant WITH HANDS (api/ai-chat.php) — tools, role
    // by sign-in, reports and graphs. Either brain's key will do. When true
    // the widget asks the agent first and keeps the rule engine as its
    // offline fallback; when false it behaves exactly as before.
    'aiAgent'  => Settings::getBool('ai_web_agent_on', false)
                  && (Settings::getString('anthropic_api_key', '') !== '' || Settings::getString('gemini_api_key', '') !== ''
                      || Settings::getString('openai_api_key', '') !== ''),
    'settings' => Settings::publicSettings(),
    // 6 Oct 2026: the office offers running TODAY (Admin -> Offers, auto-apply) -
    // the top ribbon shows them and hides itself the day after valid_until.
    'offers'   => (static function (): array {
        try {
            require_once INCLUDE_PATH . '/fare.php';
            return array_map(static fn (array $o): array => [
                'code' => $o['code'], 'title' => $o['title'], 'type' => $o['type'], 'value' => $o['value'],
                'max' => $o['max'], 'min' => $o['min'], 'ahead' => (int) ($o['ahead'] ?? 0), 'until' => $o['until'],
            ], array_slice(Fare::runningOffers(), 0, 2));
        } catch (Throwable $e) {
            return [];
        }
    })(),
    // The ONE pickup list (Admin -> Routes, ON stops of active routes, with
    // time + per-pickup fare) — the homepage picker and fare board read it.
    'locations' => Boarding::bootLocations(),
    'user'     => $user !== null ? [
        'phone'  => $user['phone'] ?? '',
        'name'   => $user['full_name'] ?? ($user['name'] ?? ''),
        'role'   => $user['role'] ?? 'customer',
        'points' => (int) ($user['loyalty_points'] ?? 0),
        'tier'   => $user['loyalty_tier'] ?? 'Silver',
        'country'=> $userCountry,
    ] : null,
];

/* Counter mode (3 Sep 2026): when a staff member who may sell is signed in,
   tell the app so it runs the SAME search -> seats -> checkout as a counter
   (attribution, cash/UPI-received payment, counter discount; see
   assets/js/14-counter.js). The key is only added when staff is present, so
   the anonymous page stays byte-identical. */
/* The help numbers the app's own fallback ticket prints (4 Oct 2026) - the
   same list the server ticket uses, instead of four numbers typed into JS. */
/* 5 Oct 2026: the ticket goes out by itself on the approved WhatsApp template
   the second it is sold (all 25 of today's did, none by "hi"), so the app no
   longer asks every customer to "send hi" - and it could not have worked: no
   message from a customer has ever reached us (the inbound webhook is a paid
   add-on that is not switched on). The "hi" button and the counter's QR come
   back by themselves once a real inbound message has been seen in the last
   14 days, or with setting wa_hi_prompt = 1. waDirect tells the ticket screen
   to say "sent to your WhatsApp" instead. */
$shgHiLive = Settings::getBool('wa_hi_prompt', false);
if (!$shgHiLive) {
    try {
        $shgHiLive = Database::exists('SELECT 1 FROM wa_windows WHERE last_in_at >= NOW() - INTERVAL 14 DAY LIMIT 1');
    } catch (Throwable $e) {
        $shgHiLive = false;   // table not there: no "hi" flow at all
    }
}
/* 10 Oct 2026: "hi" now goes to the META number (SiteFacts::chatDigits), whose
   webhook is live and whose free 24 h window carries the ticket PNG at once
   (includes/waqueue.php hiNumber()). The paid template may not arrive (AiSensy
   wallet / Meta billing), "hi" always does - so the button is always shown.
   wa_hi_meta = 0 restores the old AiSensy-number behaviour. */
$shgHiMeta = Settings::getBool('wa_hi_meta', true) && strtolower(Settings::getString('wa_policy', 'meta_first')) !== 'aisensy_only';
if ($shgHiMeta) {
    $shgHiLive = true;
}
$boot['waHi'] = $shgHiLive
    ? ($shgHiMeta
        ? (static function (): string { require_once INCLUDE_PATH . '/sitefacts.php'; return SiteFacts::chatDigits(); })()
        : (preg_replace('/\D+/', '', Settings::getString('aisensy_wa_number', '919157001507')) ?: '919157001507'))
    : '';
$boot['waDirect'] = !$shgHiLive;
$boot['npr'] = Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6);   // the one NPR rate the app estimates with
// 5 Oct 2026: the Nepalgunj office line on the home banner (Company details -> Nepal phone).
$boot['npOffice'] = [
    'place' => trim((string) preg_replace('/,?\s*Nepalgunj$/i', '', Settings::nepalOfficePlace())) ?: Settings::nepalOfficePlace(),
    'phone' => trim(Settings::getString('nepal_phone', '')),
];
/* 9 Oct 2026 - SITE FACTS: the one facts object (company, CEO, offices,
   phones, the fare board) every surface of the page reads - window.FACTS in
   02-config.js. Built from the same helpers the tickets and the checkout use
   (includes/sitefacts.php), so an Admin edit reaches the whole site on the
   next load. A failure ships no facts and the app keeps its packaged
   fallbacks - never a broken page. */
require_once INCLUDE_PATH . '/sitefacts.php';
try {
    $boot['facts'] = SiteFacts::boot();
    /* The pickup list's per-stop fare (SHG_BOOT.locations, read by the fare
       strip, the picker and checkout) is re-stamped from the board, which
       prices each row with the route that sells it: Boarding::locations()
       lists a return route's own to_city first with no fare, which hid that
       drop's own price (e.g. Surat) behind the flat return default. */
    $shgOwn = [];
    foreach (['go', 'back'] as $shgSide) {
        foreach ($boot['facts']['fares'][$shgSide] ?? [] as $shgRow) {
            $shgOwn[$shgSide][(string) $shgRow['name']] = !empty($shgRow['own']) ? (float) $shgRow['fare'] : null;
        }
        foreach ($boot['locations'][$shgSide] ?? [] as $shgI => $shgLoc) {
            $shgName = (string) ($shgLoc['name'] ?? '');
            if (array_key_exists($shgName, $shgOwn[$shgSide] ?? [])) {
                $boot['locations'][$shgSide][$shgI]['fare'] = $shgOwn[$shgSide][$shgName];
            }
        }
    }
} catch (Throwable $e) {
    Logger::error('SiteFacts boot failed: ' . $e->getMessage());
}
// 5 Oct 2026: the Nepal office's people (CEO / Operations / Parking) for the home card.
try {
    $boot['npTeam'] = Settings::nepalTeam();
} catch (Throwable $e) {
    $boot['npTeam'] = [];
}
/* 5 Oct 2026: Google Ads conversion ids (Admin -> Settings -> Website). The
   app loads Google's tag ONLY when the booking id is set, and never for a
   signed-in staff member (a counter sale is not an ad conversion). */
$gadsOk = static fn(string $v): bool => (bool) preg_match('~^AW-\d{6,14}/[A-Za-z0-9_-]{4,60}$~', $v);
$gadsBook = trim(Settings::getString('google_ads_conversion', ''));
$gadsContact = trim(Settings::getString('google_ads_contact_conversion', ''));
if ($gadsOk($gadsBook) && Auth::admin() === null) {
    $boot['gads'] = ['book' => $gadsBook, 'contact' => $gadsOk($gadsContact) ? $gadsContact : ''];
}
try {
    $boot['help'] = Settings::helpContacts(Settings::companyFor(null, null), 4);
} catch (Throwable $e) {
    $boot['help'] = [];
}

$staffRow = Auth::admin();
if ($staffRow !== null) {
    require_once INCLUDE_PATH . '/agentwallet.php';
    $staffId = (int) ($staffRow['id'] ?? 0);
    /* The desk this clerk sits at (3 Oct 2026): a Nepal-side desk (NPJ,
       Rupaidiha …) sells Nepal → India by default — 14-counter.js opens on
       the return run and shows the NPR to collect. */
    $deskCode = '';
    $deskName = '';
    try {
        $dRow = Database::fetch('SELECT counter_code, counter_name FROM admin_profiles WHERE admin_id = :a', ['a' => $staffId]);
        $deskCode = (string) ($dRow['counter_code'] ?? '');
        $deskName = (string) ($dRow['counter_name'] ?? '');
    } catch (Throwable $e) {
        try {   // counter_code arrives with upgrade-2026-09-counter-locations.sql
            $deskName = (string) Database::scalar('SELECT counter_name FROM admin_profiles WHERE admin_id = :a', ['a' => $staffId], '');
        } catch (Throwable $e2) {
            $deskName = '';
        }
    }
    $boot['staff'] = [
        'id'             => $staffId,
        'name'           => (string) ($staffRow['full_name'] ?? ($staffRow['username'] ?? '')),
        'role'           => (string) ($staffRow['role'] ?? ''),
        'code'           => AgentWallet::agentCodeLabel($staffId) ?: null,
        'canSell'        => Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin(),
        'maxDiscountPct' => Settings::getFloat('counter_max_discount_pct', 15.0),
        'maxSeats'       => Settings::getInt('counter_max_seats_per_booking', 20),
        'panelUrl'       => Auth::isCounterAgent() ? '/admin/agent.php' : '/admin/',
        'commFlat'       => AgentWallet::flatMode(),
        'commDirect'     => Settings::getFloat('agent_flat_direct', 200.0),
        'commJoint'      => Settings::getFloat('agent_flat_joint', 400.0),
        'desk'           => [
            'code' => $deskCode,
            'name' => Settings::counterLabel($deskCode, $deskName),
            'side' => Settings::isNepalDesk($deskCode, $deskName) ? 'nepal' : 'india',
            /* 4 Oct 2026 - SIDE and MONEY are separate: Rupaidiha opens on the
               return run but takes rupees. currency/rate drive the NPR hints,
               methods the pay chips, lang the screen language. */
            'currency' => CounterDesk::currency($deskCode),
            'rate'     => CounterDesk::rate($deskCode),
            'methods'  => CounterDesk::allowedMethods($deskCode),
            'entity'   => CounterDesk::entity($deskCode),
            'lang'     => CounterDesk::lang($deskCode),
            'from'     => CounterDesk::defaultFrom($deskCode),
            // The account this desk's customer pays into (Company details page).
            'pay'      => (static function () use ($deskCode): array {
                $co = Settings::companyFor($deskCode, null);
                return ['upi' => (string) $co['payUpi'], 'esewa' => (string) $co['payEsewa'],
                        'esewaName' => (string) $co['payEsewaName'], 'bank' => (string) $co['payBank']];
            })(),
        ],
        'nprRate'        => Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6),
    ];
    try {
        $agentRows = Database::fetchAll(
            "SELECT a.id, a.username, a.full_name, COALESCE(p.counter_name, '') AS city
               FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id
              WHERE a.role = 'agent' AND a.is_active = 1 ORDER BY a.full_name, a.username"
        );
        $agentList = [];
        foreach ($agentRows as $ar) {
            $aid  = (int) $ar['id'];
            $aCode = AgentWallet::agentCodeLabel($aid);
            if ($aCode) {
                $agentList[] = [
                    'code' => $aCode,
                    'name' => (string) ($ar['full_name'] ?: $ar['username']),
                    'type' => AgentWallet::agentTypeFor($aid),
                    'co'   => AgentWallet::isCompanyCode($aCode),
                    'city' => trim((string) ($ar['city'] ?? '')),   // shown in the agent search box (5 Oct 2026)
                ];
            }
        }
        usort($agentList, static fn(array $x, array $y): int => strnatcmp($x['code'], $y['code']));
        $boot['staff']['agents'] = $agentList;
    } catch (Throwable $e) {
        $boot['staff']['agents'] = [];
    }
}

/* Home page (12 Sep 2026): the QuickBot card shows how many tickets the
   system has issued so far - a real number, counted here (bookings is a
   few thousand rows at most; the COUNT is sub-millisecond). Never blocks
   the page: any DB hiccup just leaves the counter hidden. */
try {
    $stRow = Database::fetchAll("SELECT COUNT(*) AS n FROM bookings WHERE status IN ('confirmed','completed')");
    $boot['stats'] = ['tickets' => (int) ($stRow[0]['n'] ?? 0)];
} catch (Throwable $e) {
    $boot['stats'] = ['tickets' => 0];
}

/* Web Push (13 Sep 2026): the VAPID public key the phone needs to subscribe
   for delay alerts / reminders / "ticket ready". The pair is generated once
   on first use and lives in the settings table (never public); only the
   PUBLIC half travels here. Any failure simply leaves push off. */
try {
    require_once INCLUDE_PATH . '/webpush.php';
    $pushOn = WebPush::enabled();
    $boot['push'] = ['on' => $pushOn, 'key' => $pushOn ? WebPush::publicKey() : ''];
} catch (Throwable $e) {
    $boot['push'] = ['on' => false, 'key' => ''];
}

$bootJson = Security::jsonForHtml($boot);

/* ---------------------------------------------------------------------
 *  SEO head — canonical + Open Graph/Twitter URLs and JSON-LD, built
 *  from APP_URL so the absolute URLs are correct in every environment
 *  (localhost in dev, the real domain in production). This avoids
 *  hardcoding a production domain in the static template. Business
 *  facts below are ground-truth (CIN U52291GJ2026PTC174029).
 * ------------------------------------------------------------------- */
$baseUrl = rtrim(APP_URL, '/');
$ogImage = $baseUrl . '/assets/img/og-shg.png';   // 1200x630 share card (20 Sep 2026); icon-shg.svg never existed
$e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/* 9 Oct 2026: every business fact below comes from SiteFacts (Admin ->
   Company details / Counters / Fares) - the name, phone, address, offices and
   the fare range follow an Admin edit on the next load. */
try {
    $F = SiteFacts::all();
} catch (Throwable $shgErr) {   // never $e: that name is the escaper above
    $F = null;
    Logger::error('SiteFacts head failed: ' . $shgErr->getMessage());
}
$shgCo   = Settings::company();
$shgAddr = (string) ($F['head']['address'] ?? $shgCo['address']);
$shgPin  = preg_match('/\b(\d{6})\b/', $shgAddr, $shgM) === 1 ? $shgM[1] : '';
$shgCity = (string) ($F['head']['city'] ?? '');
$shgStreet = trim((string) preg_split('/,\s*' . preg_quote($shgCity !== '' ? $shgCity : '~~', '/') . '\b/u', $shgAddr)[0], " ,");
$shgTel  = static fn(string $digits): string => $digits !== '' ? '+' . $digits : '';
$shgOrg  = [
    '@type'     => 'Organization',
    '@id'       => $baseUrl . '/#org',
    'name'      => (string) ($F['brand']['name'] ?? $shgCo['name']),
    'legalName' => (string) ($F['brand']['legal'] ?? $shgCo['legal']),
    'url'       => $baseUrl . '/',
    'logo'      => $baseUrl . '/assets/img/logo-mark.png',
    'email'     => (string) ($F['brand']['email'] ?? $shgCo['email']),
    'telephone' => $shgTel((string) ($F['head']['tel'] ?? Settings::officeWhatsApp())),
    'address'   => array_filter([
        '@type'           => 'PostalAddress',
        'streetAddress'   => $shgStreet !== '' ? $shgStreet : $shgAddr,
        'addressLocality' => $shgCity,
        'postalCode'      => $shgPin,
        'addressRegion'   => stripos($shgAddr, 'Gujarat') !== false ? 'Gujarat' : '',
        'addressCountry'  => 'IN',
    ], static fn($v): bool => $v !== ''),
];
if (!empty($F['brand']['cin'])) {
    $shgOrg['identifier'] = ['@type' => 'PropertyValue', 'propertyID' => 'CIN', 'value' => (string) $F['brand']['cin']];
}
if (!empty($F['ceo']['name'])) {
    $shgOrg['founder'] = ['@type' => 'Person', 'name' => (string) $F['ceo']['name'], 'jobTitle' => (string) ($F['ceo']['title'] ?? '')];
}
$shgDepts = [];
foreach ((array) ($F['offices'] ?? []) as $shgO) {
    if (($shgO['kind'] ?? '') === 'head' || ($shgO['status'] ?? '') !== 'open') {
        continue;
    }
    $shgDepts[] = array_filter([
        '@type'     => 'Organization',
        'name'      => (string) ($F['brand']['short'] ?? '') . ' — ' . (string) $shgO['city'] . ' office',
        'telephone' => $shgTel((string) ($shgO['tel'] ?? '')),
        'address'   => ['@type' => 'PostalAddress', 'addressLocality' => (string) $shgO['city'], 'addressCountry' => (string) $shgO['country']],
    ], static fn($v): bool => $v !== '');
}
if ($shgDepts !== []) {
    $shgOrg['department'] = $shgDepts;
}
$ld = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        $shgOrg,
        [
            '@type'      => 'BusOrCoach',
            'name'       => (string) ($F['brand']['short'] ?? $shgCo['name']) . ' — Gujarat ⇄ Rupaidiha (India–Nepal) Bus',
            'provider'   => ['@type' => 'Organization', '@id' => $baseUrl . '/#org', 'name' => (string) ($F['brand']['name'] ?? $shgCo['name'])],
            'areaServed' => ['India', 'Nepal'],
            'telephone'  => $shgOrg['telephone'],
        ],
    ],
];
/* The sharing-berth price range, from the board the checkout bills with. */
if ($F !== null) {
    $shgLo = min((float) $F['fares']['range']['go']['min'], (float) $F['fares']['range']['back']['min']);
    $shgHi = max((float) $F['fares']['range']['go']['max'], (float) $F['fares']['range']['back']['max']);
    $ld['@graph'][] = [
        '@type'    => 'BusTrip',
        'name'     => 'Gujarat ⇄ Rupaidiha (India–Nepal border) — AC sleeper',
        'provider' => ['@id' => $baseUrl . '/#org'],
        'offers'   => [
            '@type'         => 'AggregateOffer',
            'priceCurrency' => 'INR',
            'lowPrice'      => (string) (int) round($shgLo),
            'highPrice'     => (string) (int) round($shgHi),
            'offerCount'    => (string) (count($F['fares']['go']) + count($F['fares']['back'])),
            'url'           => $baseUrl . '/',
        ],
    ];
}
$ldJson = json_encode(
    $ld,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$seoHead = '<link rel="canonical" href="' . $e($baseUrl) . '/">'
    . '<meta property="og:url" content="' . $e($baseUrl) . '/">'
    . '<meta property="og:image" content="' . $e($ogImage) . '">'
    . '<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta property="og:image:type" content="image/png">'
    . '<meta name="twitter:url" content="' . $e($baseUrl) . '/">'
    . '<meta name="twitter:image" content="' . $e($ogImage) . '">'
    . '<script type="application/ld+json">' . $ldJson . '</script>';

/* ---------------------------------------------------------------------
 *  The injected head script: exposes SHG_BOOT and points the app's
 *  window.storage seam at /api/kv.php. The bridge always keeps a local
 *  copy so the site keeps working if the network (or the server ACL)
 *  says no — guests browse and draft locally, admins write to the server.
 * ------------------------------------------------------------------- */
$inject = <<<HTML
{$seoHead}
<script>
window.SHG_BOOT = {$bootJson};
/* 8 Oct 2026 (D2-05): <html lang> is the visitor's language from the first frame (saved choice, else a Hindi / Nepali phone, else Nepali) - the same pick 04-i18n.js makes. */
try { (function () { var l = null; try { l = localStorage.getItem('shg:lang'); } catch (e) {} if (!/^(en|hi|ne|gu)$/.test(l || '')) { l = 'ne'; var n = navigator.languages || [navigator.language || '']; for (var i = 0; i < n.length; i++) { var c = String(n[i] || '').toLowerCase().slice(0, 2); if (c === 'ne' || c === 'hi') { l = c; break; } } } document.documentElement.lang = l; })(); } catch (e) {}
/* 5 Oct 2026: an ad click (Google Ads / Facebook) sees the booking card first - set before first paint. */
try { if (/[?&](gclid|gbraid|wbraid|gad_source|gad_campaignid|fbclid)=/.test(location.search)) document.documentElement.classList.add('shg-from-ad'); } catch (e) {}
/* 8 Oct 2026: Admin -> Settings -> Website buttons. A switched-off button is hidden before first paint (depth.css html[data-off~=...]). */
try { (function (s) { var off = [], m = { btn_wa_fab_on: 'wafab', btn_ai_fab_on: 'aifab', btn_sos_on: 'sos', btn_offer_ribbon_on: 'ribbon', btn_wa_book_on: 'wabook' }; for (var k in m) { if (s[k] === false || s[k] === 0 || s[k] === '0') off.push(m[k]); } if (off.length) document.documentElement.setAttribute('data-off', off.join(' ')); })((window.SHG_BOOT && window.SHG_BOOT.settings) || {}); } catch (e) {}
/* 9 Oct 2026 (THEME): Admin -> Settings -> Website & app owner defaults for the colour palette (app_theme_palette, '' = S Hari brand), UI style (app_ui_style, pixel | classic) and motion (app_motion_on) - stamped before first paint, ONLY where this device saved nothing (the template's pre-paint script already stamped a saved choice; window.SHG_PAL_TC is its palette whitelist). */
try { (function (s, d) { var P = window.SHG_PAL_TC || {}, has = function (k) { return Object.prototype.hasOwnProperty.call(P, k); }, g = function (k) { try { return (localStorage.getItem(k) || '').replace(/"/g, ''); } catch (e) { return ''; } }; var p = g('shg:palette'), u = g('shg:ui'), mo = g('shg:motion'), op = String(s.app_theme_palette || ''); if (p !== 'shg' && !has(p) && has(op)) { d.setAttribute('data-palette', op); var dk = d.getAttribute('data-theme') === 'dark', am = d.getAttribute('data-display') === 'amoled', m = document.querySelector('meta[name="theme-color"]'); if (m && !am) m.setAttribute('content', P[op][dk ? 1 : 0]); if (dk && !am) d.style.background = P[op][2]; } if (u !== 'pixel' && u !== 'classic') d.setAttribute('data-ui', String(s.app_ui_style || 'pixel') === 'classic' ? 'classic' : 'pixel'); if (mo !== 'full' && mo !== 'reduced' && (s.app_motion_on === false || s.app_motion_on === 0 || s.app_motion_on === '0')) d.setAttribute('data-motion', 'reduced'); })((window.SHG_BOOT && window.SHG_BOOT.settings) || {}, document.documentElement); } catch (e) {}
/* 10 Oct 2026 (THEME, stale-stylesheet guard): this HTML is always fetched fresh, the stylesheets are cached by their ?v= stamp. If a phone ever runs this page against an OLDER themes.css that does not know the palette just stamped (a new owner default such as 'professional', or a choice saved on a newer build), the generic palette alias and the base alias point at each other (--royal <-> --md-primary), both go invalid and every button and link loses its colour. This script runs after the stylesheets, so one computed read tells: a palette the CSS knows always resolves --md-primary. Unknown -> drop the attribute and the S Hari brand look paints exactly as before. Never fires on a normal deploy (the stamp bump ships CSS and HTML together; docs/UPGRADE-2026-10-10-themes.md, deploy order). */
try { (function (d) { if (!d.hasAttribute('data-palette') || !window.getComputedStyle) return; if (getComputedStyle(d).getPropertyValue('--md-primary').trim()) return; d.removeAttribute('data-palette'); if (d.getAttribute('data-theme') === 'dark' && d.getAttribute('data-display') !== 'amoled') { d.style.background = '#0B1220'; var m = document.querySelector('meta[name="theme-color"]'); if (m) m.setAttribute('content', '#0F1A2E'); } })(document.documentElement); } catch (e) {}
(function () {
  var BOOT = window.SHG_BOOT || {};
  var API  = (BOOT.apiBase || '/api');

  function localGet(key) {
    try { var v = localStorage.getItem(key); return v != null ? { value: v } : null; }
    catch (e) { return null; }
  }
  function localSet(key, value) {
    try { localStorage.setItem(key, value); } catch (e) {}
  }

  // The seam the single-page app already looks for. Server first for
  // shared data, this browser second — never losing the user's own work.
  window.storage = {
    get: async function (key) {
      try {
        var r = await fetch(API + '/kv.php?action=get&key=' + encodeURIComponent(key), {
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        });
        if (r.ok) {
          var j = await r.json();
          if (j && j.ok && j.data && j.data.found && j.data.value != null) {
            localSet(key, j.data.value);        // refresh the local cache
            return { value: j.data.value };
          }
        }
      } catch (e) { /* offline or blocked — fall through */ }
      return localGet(key);
    },
    set: async function (key, value) {
      localSet(key, value);                     // never lose local work
      // Only staff who see the whole company may write the shared store.
      // Asking anyway just earned five 403s per page load.
      if (!BOOT.canSync) return true;
      try {
        await fetch(API + '/kv.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': BOOT.csrf || ''
          },
          body: JSON.stringify({ action: 'set', key: key, value: value })
        });
      } catch (e) { /* server declined (guest) — the local copy stands */ }
      return true;
    }
  };
})();
</script>
HTML;

/* ---------------------------------------------------------------------
 *  Serve the application, splicing the bootstrap into the real <head>
 *  (the second </head> in the file lives inside a JS string, so we only
 *  touch the first one).
 * ------------------------------------------------------------------- */
/**
 * The hi / ne strings for a few i18n keys, read from assets/js/04-i18n.js
 * (its "hi: {" and "ne: {" sections, key: 'value' or key: "value"). Cached in
 * the system temp dir by the bundle's mtime, so it costs one stat per request.
 *
 * @param list<string> $keys
 * @return array<string,array<string,string>> lang => key => text
 */
function shg_first_paint_strings(array $keys): array
{
    $src = __DIR__ . '/assets/js/04-i18n.js';
    $mt  = (int) @filemtime($src);
    if ($mt <= 0) {
        return [];
    }
    $cache = sys_get_temp_dir() . '/shg-firstpaint-' . md5(implode(',', $keys) . '|' . $mt . '|' . __DIR__) . '.json';
    $hit = @file_get_contents($cache);
    if (is_string($hit) && is_array($dec = json_decode($hit, true))) {
        return $dec;
    }
    $js   = (string) @file_get_contents($src);
    $hiAt = strpos($js, "\nhi: {");
    $neAt = strpos($js, "\nne: {");
    if ($hiAt === false || $neAt === false || $neAt < $hiAt) {
        return [];
    }
    $end  = strpos($js, "\n};", $neAt);
    $out  = [];
    $sections = ['hi' => substr($js, $hiAt, $neAt - $hiAt), 'ne' => substr($js, $neAt, ($end === false ? strlen($js) : $end) - $neAt)];
    foreach ($sections as $lang => $body) {
        foreach ($keys as $k) {
            // key: 'plain text'  or  key: "plain text" - no escapes, nothing to reinterpret
            if (preg_match('/(?<![\w$])' . preg_quote($k, '/') . '\s*:\s*(?:\'([^\'\\\\]*)\'|"([^"\\\\]*)")/u', $body, $m) === 1) {
                $raw = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
                if ($raw !== '') {
                    $out[$lang][$k] = $raw;
                }
            }
        }
    }
    @file_put_contents($cache . '.tmp', json_encode($out, JSON_UNESCAPED_UNICODE));
    @rename($cache . '.tmp', $cache);
    return $out;
}

$templatePath = __DIR__ . '/app.template.html';
$html = @file_get_contents($templatePath);

if ($html === false) {
    http_response_code(500);
    exit('Application template is missing.');
}

/* 9 Oct 2026 - SITE FACTS token pass: {{f:brand.name}}, {{f:head.phone}},
   {{f:fares.go.min}} … in the template become the live values (HTML-escaped,
   money as "₹2,200"); an unknown token renders empty, never literally. Runs
   BEFORE the comment strip and every string splice below, so a token may sit
   anywhere in the markup. Contract: includes/sitefacts.php tokens(). */
try {
    $html = SiteFacts::apply($html);
} catch (Throwable $shgErr) {
    $html = (string) (preg_replace('/\{\{f:[A-Za-z0-9_.:\-]{1,80}\}\}/', '', $html) ?? $html);
    Logger::error('SiteFacts token pass failed: ' . $shgErr->getMessage());
}

/* The share / search descriptions from the same facts (9 Oct 2026): the
   pickups the bus really serves and the fare FROM the board - the old copy
   claimed "one fixed fare price" while pickups paid different fares, and
   listed the pickups by hand. The <title> stays the template's. */
if ($F !== null) {
    $shgTowns = [];
    foreach ((array) $F['fares']['go'] as $shgRow) {
        $shgT = (string) (Boarding::stopDisplay((string) $shgRow['name'])['name'] ?? '');
        if ($shgT !== '' && !in_array($shgT, $shgTowns, true)) {
            $shgTowns[] = $shgT;
        }
    }
    $shgList = $shgTowns !== [] ? implode(', ', array_slice($shgTowns, 0, 10)) : 'Gujarat';
    $shgFrom = SiteFacts::money((float) $F['fares']['range']['go']['min']);   // the copy describes Gujarat -> Rupaidiha
    $shgPriv = (float) $F['fares']['private']['single'] > 0 ? ' · private cabins from ' . SiteFacts::money((float) $F['fares']['private']['single']) : '';
    $shgShare = 'Direct AC sleeper coach from Gujarat (India) to the Rupaidiha (India–Nepal) border. Boarding at ' . $shgList
              . '. Sharing berth from ' . $shgFrom . ' per person' . $shgPriv . '. Book online, on WhatsApp or by phone.';
    $shgDesc  = 'सूरत से नेपाल बस · सुरत देखि नेपाल बस — daily AC sleeper from ' . $shgList . ' to the Rupaidiha (Nepalgunj) border, from '
              . $shgFrom . '. Book online, on WhatsApp or by phone. Registered Indian company, ' . ($shgCity !== '' ? $shgCity : 'Gujarat') . '.';
    $shgMeta = static function (string $doc, string $attr, string $key, string $content): string {
        return (string) (preg_replace_callback(
            '~<meta\s+' . $attr . '="' . preg_quote($key, '~') . '"\s+content="[^"]*"\s*/?>~u',
            static fn(): string => '<meta ' . $attr . '="' . $key . '" content="' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '">',
            $doc,
            1
        ) ?? $doc);
    };
    $html = $shgMeta($html, 'property', 'og:description', $shgShare);
    $html = $shgMeta($html, 'name', 'twitter:description', $shgShare);
    $html = $shgMeta($html, 'name', 'description', $shgDesc);
}

/* Perf pass (11 Sep 2026): the template carries ~27 KB of HTML comments -
   15% of the document, the engineering notes that explain each block. They
   are for the people editing the file, not the phone downloading it on
   every open (network-first, no-store). Dropped at serve time: 52 KB -> 40 KB
   gzipped. Conditional comments are kept; there are none inside <script> or
   <style> (verified), so this cannot touch code. ~0.5 ms per request. */
$stripped = preg_replace('/<!--(?!\[if).*?-->/s', '', $html);
if (is_string($stripped) && $stripped !== '') {
    $html = preg_replace('/\n[ \t]*\n(?:[ \t]*\n)+/', "\n\n", $stripped) ?: $stripped;
}

$pos = stripos($html, '</head>');
if ($pos !== false) {
    $html = substr($html, 0, $pos) . $inject . "\n" . substr($html, $pos);
}

/* Speed (4 Oct 2026): the booking heading is the page's largest paint. It
   used to arrive in English and be swapped to Nepali by 04-i18n.js ~5 s later
   on a phone, and that late swap WAS the LCP. A few-line inline script right
   after the heading now writes the visitor's language at first paint, with
   the same choice 04-i18n.js makes (saved language, else a Hindi / Nepali
   phone, else Nepali). The strings are read from 04-i18n.js itself, so there
   is no second copy to drift; applyLang() later writes the same text. */
/* Real reviews (4 Oct 2026, point 9): only feedback the office has marked
   public on Admin -> Ratings, newest first, first name + initial only. No
   review, no section - nothing is ever invented. Server-rendered so search
   engines and slow phones see it without waiting for the app. */
try {
    $revs = Database::fetchAll(
        "SELECT name, rating, comment, created_at FROM feedback
          WHERE is_public = 1 AND comment IS NOT NULL AND TRIM(comment) <> ''
          ORDER BY created_at DESC LIMIT 6"
    );
} catch (Throwable $e) {
    $revs = [];
}
if ($revs !== []) {
    $rv = '<section class="section" id="realReviews"><div class="container"><div class="sec-head center">'
        . '<h2 data-i18n="revTitle">What our passengers say</h2>'
        . '<p class="muted" data-i18n="revNote">Real reviews from passengers who travelled with us, shown with their permission.</p></div>'
        . '<div class="testi-grid">';
    foreach ($revs as $r) {
        $parts = preg_split('/\s+/u', trim((string) ($r['name'] ?? ''))) ?: [];
        $who   = $parts !== [] && $parts[0] !== '' ? $parts[0] . (isset($parts[1]) ? ' ' . mb_substr($parts[1], 0, 1) . '.' : '') : 'Passenger';
        $stars = str_repeat('★', max(1, min(5, (int) $r['rating']))) . str_repeat('☆', 5 - max(1, min(5, (int) $r['rating'])));
        $rv .= '<div class="testi-card"><div class="tc-text">' . htmlspecialchars(mb_substr(trim((string) $r['comment']), 0, 400), ENT_QUOTES, 'UTF-8') . '</div>'
             . '<div class="tc-who"><span aria-label="' . (int) $r['rating'] . ' of 5">' . $stars . '</span> · ' . htmlspecialchars($who, ENT_QUOTES, 'UTF-8')
             . ' · ' . htmlspecialchars(date('M Y', (int) strtotime((string) $r['created_at'])), ENT_QUOTES, 'UTF-8') . '</div></div>';
    }
    $rv .= '</div></div></section>';
    $html = str_replace('<section class="section" id="realReviews" hidden></section>', $rv, $html);
}

/* 8 Oct 2026: the premium hero (#homeHero) now leads the page and its h1 is
   the LCP - its headline, promise line and three keys get the same
   first-paint treatment, in a script placed right after the hero. */
$shgFpScript = static function (array $strings): string {
    return '<script>(function(){try{var M=' . json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
        . ',l=null;try{l=localStorage.getItem("shg:lang");}catch(e){}'
        . 'if(l==="en"||l==="gu")return;'
        . 'if(!l||!M[l]){l="ne";var n=navigator.languages||[navigator.language||""];for(var i=0;i<n.length;i++){var c=String(n[i]||"").toLowerCase().slice(0,2);if(c==="ne"||c==="hi"){l=c;break;}}}'
        . 'var d=M[l];if(!d)return;'
        . 'for(var k in d){var e=document.querySelector("[data-i18n=\""+k+"\"]");if(e)e.innerHTML=d[k];}}catch(e){}})();</script>';
};
$heroPaint = shg_first_paint_strings(['hhTitle', 'hhTag', 'hhBook', 'hhBookSub', 'hhTrack', 'hhTrackSub', 'hhMy', 'hhMySub']);
if ($heroPaint !== []) {
    $h1 = strpos($html, 'id="homeHero"');
    $h2 = $h1 === false ? false : strpos($html, '</section>', $h1);
    if ($h2 !== false) {
        $html = substr($html, 0, $h2 + 10) . $shgFpScript($heroPaint) . substr($html, $h2 + 10);
    }
}
$firstPaint = shg_first_paint_strings(['spTitle', 'spSub']);
if ($firstPaint !== []) {
    $fp = $shgFpScript($firstPaint);
    $p1 = strpos($html, 'data-i18n="spSub"');
    $p2 = $p1 === false ? false : strpos($html, '</p>', $p1);
    if ($p2 !== false) {
        $html = substr($html, 0, $p2 + 4) . $fp . substr($html, $p2 + 4);
    }
}

/* 8 Oct 2026 (D8-08): the counter desk bundle (14-counter.js, ~70 KB) is a
   no-op without SHG_BOOT.staff, so a customer page does not download it.
   sw.js still precaches it for the desk, and a staff page is never the
   cached anonymous shell (X-SHG-Personal below). */
if (empty($boot['staff'])) {
    $html = preg_replace('#<script defer src="/assets/js/14-counter\.js\?v=[^"]*"></script>\s*#', '', $html, 1) ?? $html;
}

/* 8 Oct 2026 (D8-10): the running-offer ribbon is server-rendered when an
   offer runs, so it is in the first frame instead of being revealed by late
   JS (a 0.19 layout shift that pushed the booking card down on slow phones).
   Same wording as renderOfferRibbon() in 05-router.js, which keeps
   re-localising it; html[data-off~=ribbon] still hides it before paint. */
$shgOffer = $boot['offers'][0] ?? null;
if (is_array($shgOffer) && (float) ($shgOffer['value'] ?? 0) > 0) {
    $fest = (bool) preg_match('/dashain|tihar|dasain|दशैं|तिहार/iu', (string) ($shgOffer['title'] ?? '') . ' ' . (string) ($shgOffer['code'] ?? ''));
    $v    = 0 + (float) $shgOffer['value'];
    $amt  = ($shgOffer['type'] ?? '') === 'percent' ? (rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . '%') : ('₹' . number_format($v));
    $ahead = (int) ($shgOffer['ahead'] ?? 0);
    $until = '';
    if (!empty($shgOffer['until']) && ($ts = strtotime((string) $shgOffer['until'] . ' 00:00:00')) !== false) {
        $until = date('j', $ts) . ' ' . date('M', $ts);
    }
    $title = (string) ($shgOffer['title'] ?? '');
    $rib = [];
    foreach (['ne', 'hi', 'en'] as $L) {
        $name = $fest ? ['ne' => 'दशैं–तिहार अफर', 'hi' => 'दशहरा–दिवाली ऑफ़र', 'en' => 'Dashain–Tihar offer'][$L] : $title;
        $how  = $ahead > 0
            ? ['ne' => 'यात्रा भन्दा ' . $ahead . ' घण्टा अगाडि बुक गर्दा ' . $amt . ' छुट', 'hi' => 'यात्रा से ' . $ahead . ' घंटे पहले बुक करें, ' . $amt . ' छूट', 'en' => 'book ' . $ahead . '+ hours before departure and save ' . $amt][$L]
            : ['ne' => $amt . ' छुट', 'hi' => $amt . ' छूट', 'en' => $amt . ' off'][$L];
        $till = $until !== '' ? ['ne' => ' · ' . $until . ' सम्म', 'hi' => ' · ' . $until . ' तक', 'en' => ' · till ' . $until][$L] : '';
        $rib[$L] = ['t' => $name . ' — ' . $how . $till, 'c' => ['ne' => 'अहिले बुक गर्नुहोस् →', 'hi' => 'अभी बुक करें →', 'en' => 'Book now →'][$L]];
    }
    $ribHtml = '<a class="offer-ribbon" id="offerRibbon" href="#/home" aria-live="polite">'
        . "\n" . '  <span class="or-lamp" aria-hidden="true">🪔</span>'
        . "\n" . '  <span class="or-text" id="offerRibbonText">' . htmlspecialchars($rib['ne']['t'], ENT_QUOTES, 'UTF-8') . '</span>'
        . "\n" . '  <span class="or-cta" id="offerRibbonCta">' . htmlspecialchars($rib['ne']['c'], ENT_QUOTES, 'UTF-8') . '</span>'
        . "\n" . '</a><script>(function(){try{var R=' . json_encode($rib, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ',l=document.documentElement.lang,d=R[l]||R.en;if(l==="ne"||!d)return;'
        . 'document.getElementById("offerRibbonText").textContent=d.t;document.getElementById("offerRibbonCta").textContent=d.c;}catch(e){}})();</script>';
    $html = (string) preg_replace('~<a class="offer-ribbon hide" id="offerRibbon"[^>]*>.*?</a>~s', str_replace(['\\', '$'], ['\\\\', '\\$'], $ribHtml), $html, 1);
}

header('Content-Type: text/html; charset=UTF-8');
/* Oct 2026: a page that carries a signed-in customer's or staff member's data
   (phone, name, CSRF token, the staff agent list) is marked personal, and
   sw.js never stores it as the offline shell — only the anonymous page is
   kept. Never cache it anywhere else either. */
if (!empty($boot['user']) || !empty($boot['staff'])) {
    header('X-SHG-Personal: 1');
    header('Cache-Control: private, no-store');
}
echo $html;
