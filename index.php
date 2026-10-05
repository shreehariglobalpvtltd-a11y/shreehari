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
if ($legalPath === '/bus' || str_starts_with($legalPath, '/bus/') || str_starts_with($legalPath, '/hi/bus') || str_starts_with($legalPath, '/ne/bus')
    || $legalPath === '/sitemap-routes.xml' || $legalPath === '/sitemap-pages.xml') {
    require_once INCLUDE_PATH . '/routepages.php';
    RoutePages::dispatch($legalPath);
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
    'ai'       => Settings::getString('anthropic_api_key', '') !== '',
    // The contact button on every screen (24 Sep 2026): call / WhatsApp the
    // office without hunting for the number.
    'contact'  => ['phone' => Settings::officePhone(), 'wa' => Settings::officeWhatsApp()],
    // 24 Sep 2026: the assistant WITH HANDS (api/ai-chat.php) — tools, role
    // by sign-in, reports and graphs. Either brain's key will do. When true
    // the widget asks the agent first and keeps the rule engine as its
    // offline fallback; when false it behaves exactly as before.
    'aiAgent'  => Settings::getBool('ai_web_agent_on', false)
                  && (Settings::getString('anthropic_api_key', '') !== '' || Settings::getString('gemini_api_key', '') !== ''),
    'settings' => Settings::publicSettings(),
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
$boot['waHi'] = preg_replace('/\D+/', '', Settings::getString('aisensy_wa_number', '919157001507')) ?: '919157001507';   // "hi" for the free WhatsApp ticket
$boot['npr'] = Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6);   // the one NPR rate the app estimates with
// 5 Oct 2026: the Nepalgunj office line on the home banner (Company details -> Nepal phone).
$boot['npOffice'] = [
    'place' => trim((string) preg_replace('/,?\s*Nepalgunj$/i', '', Settings::getString('nepal_office', 'Puspalal Chowk'))) ?: 'Puspalal Chowk',
    'phone' => trim(Settings::getString('nepal_phone', '')),
];
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
            "SELECT id, username, full_name FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY full_name, username"
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

$ld = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'     => 'Organization',
            '@id'       => $baseUrl . '/#org',
            'name'      => 'S Hari Global Pvt Ltd',
            'url'       => $baseUrl . '/',
            'logo'      => $baseUrl . '/assets/img/logo.png',
            'email'     => 'shreehariglobalpvtltd@gmail.com',
            'telephone' => '+919104801507',
            'address'   => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => 'Near Shilpa Garage, Silver Complex',
                'addressLocality' => 'Mehsana',
                'postalCode'      => '384002',
                'addressRegion'   => 'Gujarat',
                'addressCountry'  => 'IN',
            ],
        ],
        [
            '@type'      => 'BusOrCoach',
            'name'       => 'S Hari Global — Gujarat ⇄ Rupaidiha (India–Nepal) Bus',
            'provider'   => ['@type' => 'Organization', '@id' => $baseUrl . '/#org', 'name' => 'S Hari Global Pvt Ltd'],
            'areaServed' => ['India', 'Nepal'],
            'telephone'  => '+919104801507',
        ],
    ],
];
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
/* 5 Oct 2026: an ad click (Google Ads / Facebook) sees the booking card first - set before first paint. */
try { if (/[?&](gclid|gbraid|wbraid|gad_source|gad_campaignid|fbclid)=/.test(location.search)) document.documentElement.classList.add('shg-from-ad'); } catch (e) {}
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

$firstPaint = shg_first_paint_strings(['spTitle', 'spSub']);
if ($firstPaint !== []) {
    $fp = '<script>(function(){try{var M=' . json_encode($firstPaint, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
        . ',l=null;try{l=localStorage.getItem("shg:lang");}catch(e){}'
        . 'if(l==="en"||l==="gu")return;'
        . 'if(!l||!M[l]){l="ne";var n=navigator.languages||[navigator.language||""];for(var i=0;i<n.length;i++){var c=String(n[i]||"").toLowerCase().slice(0,2);if(c==="ne"||c==="hi"){l=c;break;}}}'
        . 'var d=M[l];if(!d)return;'
        . 'for(var k in d){var e=document.querySelector("[data-i18n=\""+k+"\"]");if(e)e.innerHTML=d[k];}}catch(e){}})();</script>';
    $p1 = strpos($html, 'data-i18n="spSub"');
    $p2 = $p1 === false ? false : strpos($html, '</p>', $p1);
    if ($p2 !== false) {
        $html = substr($html, 0, $p2 + 4) . $fp . substr($html, $p2 + 4);
    }
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
