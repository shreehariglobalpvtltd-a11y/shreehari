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
    'settings' => Settings::publicSettings(),
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
$staffRow = Auth::admin();
if ($staffRow !== null) {
    require_once INCLUDE_PATH . '/agentwallet.php';
    $staffId = (int) ($staffRow['id'] ?? 0);
    $boot['staff'] = [
        'id'             => $staffId,
        'name'           => (string) ($staffRow['full_name'] ?? ($staffRow['username'] ?? '')),
        'role'           => (string) ($staffRow['role'] ?? ''),
        'code'           => AgentWallet::agentCodeLabel($staffId) ?: null,
        'canSell'        => Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin(),
        'maxDiscountPct' => Settings::getFloat('counter_max_discount_pct', 15.0),
        // Staff bulk cap (5 Sep 2026): one party, one name, up to this many
        // seats. Deliberately NOT a public setting — guests never see it.
        'maxSeats'       => Settings::getInt('counter_max_seats_per_booking', 20),
        'panelUrl'       => Auth::isCounterAgent() ? '/admin/agent.php' : '/admin/',
    ];
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

header('Content-Type: text/html; charset=UTF-8');
echo $html;
