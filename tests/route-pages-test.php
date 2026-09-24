<?php
/**
 * route-pages-test.php — search-facing route pages (24 Sep 2026).
 *
 *   A. Every active route has a page in en / hi / ne with the facts the
 *      tables hold: H1, timetable, boarding stops with times, fares, refund
 *      ladder, FAQ, three calls to action.                           [HTTP]
 *   B. Structured data + hreflang + canonical; Devanagari town names.  [HTTP]
 *   C. Unknown slugs 404; the index lists every route.                [HTTP]
 *   D. robots.txt, the sitemap index and both child sitemaps.         [HTTP]
 *   E. The home page title targets the queries people type.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/routepages.php';
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function get(string $path): array {
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => true]);
    $raw = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}

echo "\n=== Route pages ===\n\n";
$routes = RoutePages::routes();
check('at least one active route has a slug', $routes !== [], implode(', ', array_keys($routes)));
$slug = (string) array_key_first($routes);
$r    = $routes[$slug];
$f    = RoutePages::facts($r);
check('slug is URL-safe and describes the trip', (bool) preg_match('/^[a-z0-9-]+-to-[a-z0-9-]+-bus$/', $slug), $slug);
check('facts: boarding stops with times, a sharing fare, a refund ladder', count($f['boarding']) >= 2 && $f['sharing'] > 0 && count($f['slabs']) >= 3);

if (get('/index.php')['code'] !== 200) {
    echo "  SKIP  HTTP parts — no server at " . BASE . "\n";
} else {
    echo "\n-- A. the English page --\n";
    $p = get('/bus/' . $slug);
    check('200 text/html', $p['code'] === 200 && stripos($p['headers'], 'text/html') !== false, 'HTTP ' . $p['code']);
    check('cached for an hour', stripos($p['headers'], 'max-age=3600') !== false);
    check('H1 names the trip', str_contains($p['body'], '<h1>' . trim((string) $r['from_city']) . ' to ' . trim((string) $r['to_city']) . ' bus</h1>'));
    check('departure time from the routes table', str_contains($p['body'], substr((string) $r['dep_time'], 0, 5)));
    check('first boarding stop with its time', str_contains($p['body'], htmlspecialchars((string) $f['boarding'][0]['stop_name'])) && str_contains($p['body'], substr((string) $f['boarding'][0]['stop_time'], 0, 5)));
    check('sharing fare in rupees', str_contains($p['body'], '₹' . number_format($f['sharing'])));
    check('refund ladder rows', substr_count($p['body'], 'before departure') >= 3);
    check('six FAQ entries', substr_count($p['body'], '<details>') === 6);
    check('book online, WhatsApp and call', str_contains($p['body'], 'href="/#/"') && str_contains($p['body'], 'https://wa.me/') && str_contains($p['body'], 'href="tel:'));
    check('light: no app bundle', !str_contains($p['body'], '05-router.js') && strlen($p['body']) < 40000, strlen($p['body']) . ' bytes');

    echo "\n-- B. structured data, hreflang, Devanagari --\n";
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $p['body'], $m);
    $ld = json_decode(html_entity_decode($m[1] ?? ''), true);
    $types = array_column($ld['@graph'] ?? [], '@type');
    check('JSON-LD carries LocalBusiness + Trip + FAQPage + BreadcrumbList', $types !== [] && !array_diff(['LocalBusiness', 'Trip', 'FAQPage', 'BreadcrumbList'], $types), implode(',', $types));
    check('canonical + hreflang en/hi/ne/x-default', str_contains($p['body'], '<link rel="canonical" href="' . RoutePages::url($slug, 'en') . '">') && substr_count($p['body'], 'hreflang="') === 4);
    $hi = get('/hi/bus/' . $slug); $ne = get('/ne/bus/' . $slug);
    check('Hindi page 200 with Devanagari town in the H1', $hi['code'] === 200 && (bool) preg_match('#<h1>[^<]*से[^<]*बस</h1>#u', $hi['body']));
    check('Nepali page 200 with Devanagari town in the H1', $ne['code'] === 200 && (bool) preg_match('#<h1>[^<]*देखि[^<]*बस</h1>#u', $ne['body']));
    check('Nepali page lang=ne and its own canonical', str_contains($ne['body'], '<html lang="ne">') && str_contains($ne['body'], 'href="' . RoutePages::url($slug, 'ne') . '"'));
    check('Surat is सुरत on the Nepali page', RoutePages::town('Surat', 'ne') === 'सुरत' && RoutePages::town('Surat', 'hi') === 'सूरत' && RoutePages::town('Surat', 'en') === 'Surat');

    echo "\n-- C. 404 and the index --\n";
    check('an unknown slug is 404', get('/bus/nowhere-to-nowhere-bus')['code'] === 404);
    $ix = get('/bus');
    check('the index lists every route', $ix['code'] === 200 && substr_count($ix['body'], 'class="card link"') === count($routes));

    echo "\n-- D. robots and sitemaps --\n";
    $rb = get('/robots.txt');
    check('robots.txt allows the site, blocks admin/api, names the sitemap', $rb['code'] === 200 && str_contains($rb['body'], 'Disallow: /admin/') && str_contains($rb['body'], 'Sitemap: https://www.shreehariglobal.in/sitemap.xml'));
    $sm = get('/sitemap.xml');
    check('sitemap.xml is an index of two sitemaps', str_contains($sm['body'], '<sitemapindex') && str_contains($sm['body'], 'sitemap-routes.xml') && str_contains($sm['body'], 'sitemap-pages.xml'));
    $sr = get('/sitemap-routes.xml');
    $xml = @simplexml_load_string($sr['body']);
    check('sitemap-routes.xml is valid XML listing 3 × (routes + index) urls', $xml !== false && count($xml->url) === 3 * (count($routes) + 1), $xml !== false ? count($xml->url) . ' urls' : 'invalid');
    check('…with xhtml:link hreflang alternates', substr_count($sr['body'], 'xhtml:link') >= 9);
    $sp = get('/sitemap-pages.xml');
    check('sitemap-pages.xml lists the home and legal pages', @simplexml_load_string($sp['body']) !== false && str_contains($sp['body'], '/privacy-policy'));
}

echo "\n-- E. home title --\n";
$tpl = (string) file_get_contents(dirname(__DIR__) . '/app.template.html');
check('the home <title> says Surat to Nepal Bus', str_contains($tpl, '<title>Surat to Nepal Bus'));
check('the description carries the Hindi and Nepali queries', str_contains($tpl, 'सूरत से नेपाल बस') && str_contains($tpl, 'सुरत देखि नेपाल बस'));

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
