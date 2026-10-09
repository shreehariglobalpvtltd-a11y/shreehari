<?php
/**
 * content-pages-test.php — the brand / travel pages (9 Oct 2026).
 *
 *   A. every page (/india-nepal, /offices, /explore, /tours, /tours/<slug>)
 *      200 in en / hi / ne: lang attribute, canonical, exactly 4 hreflang,
 *      JSON-LD types, theme pre-paint, emblem + flags, no CSRF token,
 *      cacheable, size budget                                         [HTTP]
 *   B. unknown slug 404, draft package not public                     [HTTP]
 *   C. api/explore.php shapes                                          [HTTP]
 *   D. api/tour-enquiry.php: token, 419, 422, honeypot, store, resend,
 *      draft package refused, per-phone budget                         [HTTP]
 *   E. sitemap-content.xml lists every page × language with alternates,
 *      never a draft; sitemap.xml names it
 *   F. sources: no typed phone numbers, admin pages gated, enquiry
 *      whitelist + inbox labels, tools/content-seed.php idempotent
 *
 *      SHG_TEST_BASE=http://localhost:8991 php -c .claude/php-dev.ini tests/content-pages-test.php
 *
 * Every row it makes (zz-cp-* packages/places, its enquiries, its rate-limit
 * buckets) is removed at the end. The office WhatsApp note budget is filled
 * first, so the run never sends a message.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/contentpages.php';
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8991'), '/'));

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}

/** @return array{code:int, headers:string, body:string, json:?array} */
function req(string $method, string $path, ?string $jar = null, ?array $json = null, array $headers = []): array
{
    $ch = curl_init(BASE . $path);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false];
    if ($jar !== null) { $opt[CURLOPT_COOKIEJAR] = $jar; $opt[CURLOPT_COOKIEFILE] = $jar; }
    if ($json !== null) { $opt[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE); $headers[] = 'Content-Type: application/json'; }
    if ($headers !== []) { $opt[CURLOPT_HTTPHEADER] = $headers; }
    curl_setopt_array($ch, $opt);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $hs);
    $j = json_decode($body, true);
    return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => $body, 'json' => is_array($j) ? $j : null];
}

if (stripos((string) DB_NAME, 'test') === false) {
    fwrite(STDERR, "Refusing: " . DB_NAME . " is not a test database\n");
    exit(1);
}

echo "\n=== Content pages (India–Nepal, offices, explore, tours) ===\n\n";

/* ---------------- fixtures ---------------- */
$tablesOk = true;
try {
    Database::scalar('SELECT COUNT(*) FROM tour_packages');
    Database::scalar('SELECT COUNT(*) FROM travel_places');
    Database::scalar('SELECT COUNT(ref) FROM enquiries');
} catch (Throwable $e) {
    $tablesOk = false;
}
check('migration applied (travel_places, tour_packages, enquiries.ref)', $tablesOk, $tablesOk ? '' : 'run database/upgrade-2026-10-09-content.sql');
if (!$tablesOk) {
    echo "\nPASSED: $PASS   FAILED: $FAIL\n";
    exit(1);
}

$cleanup = static function (): void {
    Database::query("DELETE FROM tour_packages WHERE slug LIKE 'zz-cp-%'");
    Database::query("DELETE FROM travel_places WHERE slug LIKE 'zz-cp-%'");
    Database::query("DELETE FROM enquiries WHERE source = 'tour' AND name LIKE 'ZZ CP %'");
    Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'trv\\_%'");
};
$cleanup();
Database::query("INSERT INTO rate_limits (bucket, identifier, hits, window_start, blocked_until) VALUES ('trv_note', 'global', 99, NOW(), NULL)");

$pubId = Database::insert('tour_packages', [
    'slug' => 'zz-cp-pub', 'status' => 'published', 'country' => 'Nepal', 'title_en' => 'ZZ CP Lumbini Trip', 'title_ne' => 'ZZ CP लुम्बिनी यात्रा',
    'summary_en' => 'ZZ CP summary.', 'days' => 4, 'nights' => 3, 'highlights_en' => json_encode(['ZZ CP one', 'ZZ CP two']),
    'itinerary_json' => json_encode([['day' => 1, 'en' => 'ZZ CP day one', 'ne' => 'ZZ CP दिन एक']], JSON_UNESCAPED_UNICODE), 'coach_leg' => 'both',
    'route_en' => 'Nepalgunj → ZZ CP Lumbini → Nepalgunj',
]);
Database::insert('tour_packages', ['slug' => 'zz-cp-draft', 'status' => 'draft', 'country' => 'India', 'title_en' => 'ZZ CP Draft Trip', 'days' => 2, 'nights' => 1]);
Database::insert('tour_packages', ['slug' => 'zz-cp-guj', 'status' => 'published', 'country' => 'India', 'title_en' => 'ZZ CP Gujarat Trail', 'days' => 3, 'nights' => 2,
    'route_en' => 'Vadodara → ZZ CP Fort → Mehsana', 'coach_leg' => 'none']);
Database::insert('travel_places', [
    'slug' => 'zz-cp-near', 'country' => 'NP', 'region' => 'Banke', 'category' => 'pilgrimage', 'name_en' => 'ZZ CP Near Temple', 'blurb_en' => 'ZZ CP near.',
    'lat' => 28.0617, 'lng' => 81.6239, 'near_stop' => 'Nepalgunj', 'is_featured' => 0, 'is_active' => 1,
]);
Database::insert('travel_places', [
    'slug' => 'zz-cp-place', 'country' => 'NP', 'region' => 'ZZ', 'category' => 'pilgrimage', 'name_en' => 'ZZ CP Place', 'name_ne' => 'ZZ CP ठाउँ',
    'blurb_en' => 'ZZ CP blurb.', 'unesco' => 1, 'lat' => 27.4833, 'lng' => 83.2767, 'near_stop' => 'Nepalgunj', 'km_hint' => 300, 'is_featured' => 1, 'is_active' => 1,
]);

try {
    if (req('GET', '/offices')['code'] === 0) {
        echo "  SKIP  HTTP parts — no server at " . BASE . "\n";
    } else {
        echo "\n-- A. pages in three languages --\n";
        $expect = [
            '/india-nepal'     => ['Article', 'BreadcrumbList'],
            '/offices'         => ['TravelAgency', 'BreadcrumbList', 'Organization'],
            '/explore'         => ['ItemList', 'BreadcrumbList'],
            '/tours'           => ['ItemList', 'BreadcrumbList'],
            '/tours/zz-cp-pub' => ['TouristTrip', 'BreadcrumbList'],
        ];
        foreach ($expect as $path => $types) {
            foreach (['en', 'hi', 'ne'] as $lang) {
                $url = $lang === 'en' ? $path : '/' . $lang . $path;
                $p = req('GET', $url);
                $b = $p['body'];
                $tag = "$url";
                $ok200 = $p['code'] === 200 && stripos($p['headers'], 'text/html') !== false;
                check("$tag 200 text/html", $ok200, 'HTTP ' . $p['code']);
                if (!$ok200) { continue; }
                $canon = PublicLayout::url($path, $lang);
                check("$tag lang + canonical + 4 hreflang", str_contains($b, '<html lang="' . $lang . '">') && str_contains($b, '<link rel="canonical" href="' . $canon . '">')
                    && substr_count($b, 'hreflang="') - substr_count($b, ' hreflang="' . 'en" lang=') - substr_count($b, ' hreflang="hi" lang=') - substr_count($b, ' hreflang="ne" lang=') === 4
                    && str_contains($b, 'hreflang="x-default"'));
                preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $b, $m);
                $ld = json_decode($m[1] ?? '', true);
                $got = array_column($ld['@graph'] ?? [], '@type');
                check("$tag JSON-LD " . implode('+', $types), $got !== [] && !array_diff($types, $got), implode(',', $got));
                if ($lang === 'en') {
                    check("$tag pre-paints the theme + AMOLED", str_contains($b, "localStorage.getItem('shg:theme')") && str_contains($b, '[data-display="amoled"]'));
                    check("$tag emblem (no white tile) + India and Nepal flags", str_contains($b, '/assets/img/logo-mark') && !str_contains($b, 'icon-192.png')
                        && substr_count($b, 'class="flg') >= 2);
                    check("$tag embeds no CSRF token, cacheable 10 min", !str_contains($b, 'name="' . CSRF_TOKEN_NAME . '"') && !preg_match('/"csrf"\s*:/', $b)
                        && stripos($p['headers'], 'max-age=600') !== false);
                    check("$tag size budget < 150 KB", strlen($b) < 150000, strlen($b) . ' bytes');
                    check("$tag no PHP warnings in the output", !preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|Stack trace:/', $b));
                }
            }
        }
        $off = req('GET', '/offices')['body'];
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $off, $m);
        $ld = json_decode($m[1] ?? '', true);
        $agencies = array_values(array_filter($ld['@graph'] ?? [], static fn(array $n): bool => ($n['@type'] ?? '') === 'TravelAgency'));
        check('offices: every TravelAgency links parentOrganization #org', $agencies !== [] && !array_filter($agencies, static fn(array $a): bool => !str_ends_with((string) ($a['parentOrganization']['@id'] ?? ''), '/#org')), count($agencies) . ' offices');
        $offs = PublicLayout::offices();
        check('offices: the head office is listed first', ($offs[0]['kind'] ?? '') === 'head');
        $head = $offs[0] ?? [];
        check('offices: head-office number on the page comes from the data', ($head['phone'] ?? '') !== '' && str_contains($off, Security::e((string) $head['phone'])));
        check('offices: "expanding" band with partner + agent links', str_contains($off, 'id="expanding"') && str_contains($off, 'href="/agreements/"') && str_contains($off, 'href="/agent-signup.php"'));
        $ne = req('GET', '/ne/offices')['body'];
        check('offices (ne): नयाँ कार्यालय छिट्टै once', substr_count($ne, 'नयाँ कार्यालय छिट्टै') >= 1);
        $inp = req('GET', '/india-nepal')['body'];
        check('india-nepal: heritage band art, timeline, disclaimer, /travel-info link', str_contains($inp, 'nepal-band') && str_contains($inp, 'class="tl"')
            && str_contains($inp, 'official government sources') && str_contains($inp, 'href="/travel-info"'));
        check('india-nepal: reviewed date printed', (bool) preg_match('/Last reviewed: \d{1,2} \w+ \d{4}/', $inp));
        $ex = req('GET', '/explore')['body'];
        check('explore: the place card with UNESCO badge, flag and map link', str_contains($ex, 'id="place-zz-cp-place"') && str_contains($ex, 'UNESCO') && str_contains($ex, 'google.com/maps/search'));
        check('explore: category filter works without JS (?cat=)', req('GET', '/explore?cat=pilgrimage')['code'] === 200 && str_contains(req('GET', '/explore?cat=pilgrimage')['body'], 'zz-cp-place'));
        $tp = req('GET', '/tours/zz-cp-pub')['body'];
        check('tour: price on request, coach-leg note, itinerary, enquiry form + honeypot', str_contains($tp, 'Price on request') && str_contains($tp, 'Rupaidiha border')
            && str_contains($tp, 'ZZ CP day one') && str_contains($tp, 'id="trvForm"') && str_contains($tp, 'name="website"') && str_contains($tp, '/api/tour-enquiry.php'));
        check('tour: "your journey" = our bus out + the route + our bus home', str_contains($tp, 'class="journey"') && substr_count($tp, 'class="j-bus"') === 2 && str_contains($tp, 'ZZ CP Lumbini'));
        $tg = req('GET', '/tours/zz-cp-guj')['body'];
        check('tour without our bus: partner-only note, no bus step, no "Rupaidiha border" promise', str_contains($tg, 'our bus is not part of it') && !str_contains($tg, 'class="j-bus"')
            && !str_contains($tg, 'Our bus runs up to the Rupaidiha border'));
        $nb = strpos($ex, 'id="near-border"');
        $np = strpos($ex, 'id="nepal"');
        $pn = strpos($ex, 'id="place-zz-cp-near"');
        $pf = strpos($ex, 'id="place-zz-cp-place"');
        check('explore: a place by the crossing sits under "Near the border", a far one under Nepal', $nb !== false && $pn !== false && $np !== false && $nb < $pn && $pn < $np && $pf > $np);
        $offPage = $off;
        $withMapData = count(array_filter(PublicLayout::offices(), static fn(array $o): bool => $o['status'] === 'open' && ($o['address'] !== '' || $o['mapUrl'] !== '')));
        check('offices: a Map button only where the office has an address or map link', substr_count($offPage, '📍 ' . 'Map</a>') === $withMapData, $withMapData . ' with data');
        check('india-nepal: rules open one by one (accordion), sources listed once each', str_contains($inp, 'class="acc"') || !str_contains($inp, 'class="accs"'));
        if (preg_match('#<section class="sec" id="sources">(.*?)</section>#s', $inp, $sm2) === 1) {
            preg_match_all('/href="([^"]+)"/', $sm2[1], $hrefs);
            check('india-nepal: the sources list has no duplicates', count($hrefs[1]) === count(array_unique($hrefs[1])), count($hrefs[1]) . ' sources');
        }
        check('tours list shows the published package, not the draft', str_contains(req('GET', '/tours')['body'], 'ZZ CP Lumbini Trip') && !str_contains(req('GET', '/tours')['body'], 'ZZ CP Draft Trip'));

        echo "\n-- B. 404s and drafts --\n";
        check('unknown tour slug is 404', req('GET', '/tours/zz-cp-nowhere')['code'] === 404);
        check('a slug under /explore is 404', req('GET', '/explore/zz-cp-place')['code'] === 404);
        check('draft package is 404 to the public', req('GET', '/tours/zz-cp-draft')['code'] === 404);
        check('draft ?preview=1 without a staff session is 404', req('GET', '/tours/zz-cp-draft?preview=1')['code'] === 404);
        check('/ne/tours/<draft> is 404 too', req('GET', '/ne/tours/zz-cp-draft')['code'] === 404);

        echo "\n-- C. api/explore.php --\n";
        $f = req('GET', '/api/explore.php?what=featured&lang=ne');
        $d = $f['json']['data'] ?? [];
        $pl = array_values(array_filter($d['places'] ?? [], static fn(array $x): bool => ($x['slug'] ?? '') === 'zz-cp-place'));
        $pk = array_values(array_filter($d['packages'] ?? [], static fn(array $x): bool => ($x['slug'] ?? '') === 'zz-cp-pub'));
        check('featured: ok + places/packages lists', ($f['json']['ok'] ?? false) === true && is_array($d['places'] ?? null) && is_array($d['packages'] ?? null));
        check('featured: place keys per contract', $pl !== [] && !array_diff(['slug', 'name', 'nameNe', 'country', 'category', 'blurb', 'blurbNe', 'url'], array_keys($pl[0])) && $pl[0]['url'] === '/ne/explore#place-zz-cp-place');
        check('featured: package keys per contract', $pk !== [] && !array_diff(['slug', 'title', 'titleNe', 'days', 'nights', 'url'], array_keys($pk[0])) && $pk[0]['url'] === '/ne/tours/zz-cp-pub');
        check('featured: no draft package, no phone numbers', !str_contains($f['body'], 'zz-cp-draft') && !preg_match('/\d{5}\s?\d{5}/', $f['body']));
        check('featured: cacheable 5 min', (bool) preg_match('/Cache-Control:\s*(public|private), max-age=300/i', $f['headers']));
        $t = req('GET', '/api/explore.php?what=timeline&limit=6');
        $ev = $t['json']['data']['events'] ?? null;
        check('timeline: events with year/title/titleNe/icon', is_array($ev) && $ev !== [] && count($ev) <= 6 && !array_diff(['year', 'title', 'titleNe', 'icon'], array_keys($ev[0])));
        check('unknown what= is 400', req('GET', '/api/explore.php?what=zz')['code'] === 400);

        echo "\n-- D. api/tour-enquiry.php --\n";
        $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shg_cp_' . getmypid() . '.cookies';
        @unlink($jar);
        $g = req('GET', '/api/tour-enquiry.php', $jar);
        $tok = (string) ($g['json']['csrf'] ?? '');
        check('GET hands out a CSRF token', $g['code'] === 200 && strlen($tok) >= 32);
        $phone = '98' . '0000' . '4471';
        $good = ['package' => 'zz-cp-pub', 'name' => 'ZZ CP Tester', 'phone' => $phone, 'adults' => 2, 'children' => 1, 'month' => date('Y-m', strtotime('+1 month')),
                 'from_city' => 'Surat', 'message' => 'ZZ CP please call', 'lang' => 'en', 'website' => ''];
        check('POST without a token is 419', req('POST', '/api/tour-enquiry.php', $jar, $good)['code'] === 419);
        $h = ['X-CSRF-Token: ' . $tok];
        $bad = req('POST', '/api/tour-enquiry.php', $jar, ['name' => 'Z', 'phone' => '12', 'adults' => 0, 'lang' => 'ne'], $h);
        check('bad fields = 422 naming name/phone/adults', $bad['code'] === 422 && isset($bad['json']['fields']['name'], $bad['json']['fields']['phone'], $bad['json']['fields']['adults']));
        $past = req('POST', '/api/tour-enquiry.php', $jar, ['date' => '2020-01-01'] + $good, $h);
        check('a past date = 422', $past['code'] === 422 && isset($past['json']['fields']['date']));
        $dr = req('POST', '/api/tour-enquiry.php', $jar, ['package' => 'zz-cp-draft'] + $good, $h);
        check('a draft package = 422 package', $dr['code'] === 422 && isset($dr['json']['fields']['package']));
        $before = (int) Database::scalar("SELECT COUNT(*) FROM enquiries WHERE source = 'tour' AND name LIKE 'ZZ CP %'", [], 0);
        $hp = req('POST', '/api/tour-enquiry.php', $jar, ['website' => 'http://spam.example', 'name' => 'ZZ CP Bot'] + $good, $h);
        $after = (int) Database::scalar("SELECT COUNT(*) FROM enquiries WHERE source = 'tour' AND name LIKE 'ZZ CP %'", [], 0);
        check('honeypot: same answer shape, a TRV ref, nothing stored', $hp['code'] === 200 && ($hp['json']['ok'] ?? false) === true
            && preg_match('/^TRV-\d{6}$/', (string) ($hp['json']['ref'] ?? '')) === 1 && $after === $before);
        $ok = req('POST', '/api/tour-enquiry.php', $jar, $good, $h);
        $ref = (string) ($ok['json']['ref'] ?? '');
        check('valid enquiry = ok + TRV ref, no payment words', $ok['code'] === 200 && preg_match('/^TRV-\d{6}$/', $ref) === 1 && ($ok['json']['duplicate'] ?? true) === false, $ref);
        $row = Database::fetch('SELECT * FROM enquiries WHERE ref = :r', ['r' => $ref]);
        check('stored: source tour, package, pax, from, lang, status new', $row !== null && $row['source'] === 'tour' && (int) $row['package_id'] === $pubId
            && (int) $row['pax_adults'] === 2 && (int) $row['pax_children'] === 1 && $row['from_city'] === 'Surat' && $row['lang'] === 'en' && $row['status'] === 'new'
            && (int) $row['seats'] === 3 && str_contains((string) $row['details'], 'ZZ CP please call') && mb_strlen((string) $row['note']) <= 255);
        $again = req('POST', '/api/tour-enquiry.php', $jar, $good, $h);
        check('exact resend = same ref, duplicate, nothing new stored', ($again['json']['ref'] ?? '') === $ref && ($again['json']['duplicate'] ?? false) === true
            && (int) Database::scalar("SELECT COUNT(*) FROM enquiries WHERE ref = :r", ['r' => $ref], 0) === 1);
        $phone2 = '98' . '0000' . '4472';
        Database::query("INSERT INTO rate_limits (bucket, identifier, hits, window_start, blocked_until) VALUES ('trv_phone', :p, 3, NOW(), NULL)", ['p' => $phone2]);
        $bud = req('POST', '/api/tour-enquiry.php', $jar, ['phone' => $phone2, 'message' => 'ZZ CP other'] + $good, $h);
        check('per-phone budget (3 a day) = 429', $bud['code'] === 429);
        $gen = req('POST', '/api/tour-enquiry.php', $jar, ['package' => '', 'phone' => '98' . '0000' . '4473', 'name' => 'ZZ CP Custom', 'lang' => 'ne'] + $good, $h);
        check('custom trip (no package) accepted, Nepali answer', ($gen['json']['ok'] ?? false) === true && str_contains((string) ($gen['json']['message'] ?? ''), 'धन्यवाद'));
        check('GET is the only other verb (PUT = 405)', req('PUT', '/api/tour-enquiry.php', $jar)['code'] === 405);
        @unlink($jar);

        echo "\n-- admin gates --\n";
        foreach (['/admin/tours.php', '/admin/places.php'] as $ap) {
            $r = req('GET', $ap);
            check("$ap sends a stranger to sign in", in_array($r['code'], [302, 303], true) && stripos($r['headers'], 'login') !== false, 'HTTP ' . $r['code']);
        }
    }

    echo "\n-- E. sitemaps --\n";
    $sm = req('GET', '/sitemap-content.xml');
    $xmlBody = $sm['body'];
    if ($sm['code'] !== 200 || !str_contains($xmlBody, '<urlset')) {
        // php -S without tests/http-router.php 404s dotted paths before index.php — render in a child process instead.
        $php = escapeshellarg(PHP_BINARY);
        $ini = php_ini_loaded_file();
        $cmd = $php . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' -r ' . escapeshellarg("define('SHG_APP', true); require 'includes/bootstrap.php'; require 'includes/contentpages.php'; ContentPages::sitemap();");
        $xmlBody = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && ' . $cmd);
        echo "  (served by a child process — this dev server has no router for dotted paths)\n";
    }
    $xml = @simplexml_load_string($xmlBody);
    $paths = ContentPages::sitemapPaths();
    check('sitemap-content.xml is valid XML, 3 × pages urls', $xml !== false && count($xml->url) === 3 * count($paths), $xml !== false ? count($xml->url) . ' urls for ' . count($paths) . ' pages' : 'invalid');
    $all = true;
    foreach (['/india-nepal', '/offices', '/explore', '/tours', '/tours/zz-cp-pub'] as $p) {
        foreach (['en', 'hi', 'ne'] as $l) {
            $all = $all && str_contains($xmlBody, '<loc>' . htmlspecialchars(PublicLayout::url($p, $l), ENT_XML1) . '</loc>');
        }
    }
    check('…lists every page in en/hi/ne (incl. the published package)', $all);
    check('…never the draft', !str_contains($xmlBody, 'zz-cp-draft'));
    check('…with xhtml:link alternates incl. x-default', substr_count($xmlBody, 'xhtml:link') >= 4 * 3 * count($paths) && str_contains($xmlBody, 'hreflang="x-default"'));
    check('sitemap.xml index names sitemap-content.xml', str_contains((string) file_get_contents(dirname(__DIR__) . '/sitemap.xml'), '/sitemap-content.xml</loc>'));

    echo "\n-- F. sources --\n";
    $root = dirname(__DIR__);
    foreach (['includes/publiclayout.php', 'includes/contentpages.php', 'includes/content/indianepal.php', 'api/explore.php', 'api/tour-enquiry.php', 'admin/tours.php', 'admin/places.php', 'tools/content-seed.php'] as $f) {
        // Source links (article ids like …-120012100729) are not phone numbers: drop URLs first.
        $src = (string) preg_replace('#https?://[^\s\'"<>]+#', '', (string) file_get_contents($root . '/' . $f));
        check("$f: no typed phone number", !preg_match('/\d{5}\s?\d{5}|wa\.me\/\d|\+(91|977)[\s-]?\d{3}/', $src));
    }
    $idx = (string) file_get_contents($root . '/index.php');
    $rp = strpos($idx, 'RoutePages::dispatch($legalPath);');
    $cp = strpos($idx, 'ContentPages::dispatch($legalPath);');
    $au = strpos($idx, '$user = Auth::user();');
    check('index.php dispatches content pages after RoutePages, before Auth::user()', $rp !== false && $cp !== false && $au !== false && $rp < $cp && $cp < $au);
    foreach (['admin/tours.php', 'admin/places.php'] as $f) {
        $src = (string) file_get_contents($root . '/' . $f);
        check("$f: admin_boot('dashboard.view') + canManageSettings + verifyCsrf + audit", str_contains($src, "admin_boot('dashboard.view')") && str_contains($src, 'Auth::canManageSettings()')
            && str_contains($src, 'Security::verifyCsrf()') && str_contains($src, 'Logger::audit('));
    }
    check('admin nav lists Tours + Places under Website content', (bool) preg_match("/'tours\.php'.*Website content/", (string) file_get_contents($root . '/admin/_guard.php'))
        && (bool) preg_match("/'places\.php'.*Website content/", (string) file_get_contents($root . '/admin/_guard.php')));
    check("api/enquiry.php whitelist has 'tour'", (bool) preg_match("/'callback', 'tour'\]/", (string) file_get_contents($root . '/api/enquiry.php')));
    $enq = (string) file_get_contents($root . '/admin/enquiries.php');
    check("admin/enquiries.php labels 'tour' and 'callback'", str_contains($enq, "'tour' => 'Tour enquiry'") && str_contains($enq, "'callback' => "));
    check('tour enquiry never touches payments or bookings', !preg_match('/BookingService|Booking::|payments|confirm/i', (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($root . '/api/tour-enquiry.php'))));

    // tools/content-seed.php: twice with the same file = no change the second time.
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shg_cp_seed_' . getmypid() . '.json';
    file_put_contents($tmp, json_encode([
        'timeline' => ['key_numbers' => [['en' => '1,751 km of open border (ZZ)', 'ne' => 'खुला सीमा: १,७५१ किमी'], ['en' => 'Guarded by the Sashastra Seema Bal (SSB)', 'ne' => 'SSB'], ['en' => 'no figure here', 'ne' => '']]],
        'nepal' => ['places' => [['name' => 'ZZ CP Seed Lake', 'slug' => 'zz-cp-seed-lake', 'name_ne' => 'ZZ CP ताल', 'type' => 'Lake', 'blurb_en' => 'x', 'blurb_ne' => 'y', 'unesco' => false, 'lat' => 28.2, 'lon' => 83.9, 'from_nepalgunj_hint' => '≈ 400 km', 'sources' => []]]],
        'india' => ['places' => [], 'packages' => [['code' => 'ZZ1', 'slug' => 'zz-cp-seed-pkg', 'title_en' => 'ZZ CP Seed Pkg', 'title_ne' => 'ZZ CP प्याकेज', 'days' => 3, 'nights' => 2, 'route_en' => 'A → B', 'route_ne' => 'क → ख', 'highlights_en' => ['a'], 'highlights_ne' => ['क'], 'best_for_en' => 'all', 'best_for_ne' => 'सबै', 'country' => 'India']]],
    ], JSON_UNESCAPED_UNICODE));
    $php = escapeshellarg(PHP_BINARY) . (php_ini_loaded_file() ? ' -c ' . escapeshellarg((string) php_ini_loaded_file()) : '');
    $run = static fn(): string => (string) shell_exec('cd ' . escapeshellarg($root) . ' && ' . $php . ' tools/content-seed.php ' . escapeshellarg($tmp) . ' --no-php 2>&1');
    $o1 = $run();
    $o2 = $run();
    @unlink($tmp);
    check('content-seed: key numbers lifted from the sentences (2 of 3 have a figure)', str_contains($o1, '2 key numbers'), trim(strtok($o1, "\n")));
    check('content-seed: first run adds 1 place + 1 package', str_contains($o1, 'places: {"new":1') && str_contains($o1, 'packages: {"new":1'), trim(str_replace("\n", ' | ', $o1)));
    check('content-seed: second run changes nothing (idempotent)', str_contains($o2, '"same":1') && str_contains($o2, 'places: {"new":0,"updated":0') && str_contains($o2, 'packages: {"new":0,"updated":0'));
    $sp = Database::fetch("SELECT * FROM tour_packages WHERE slug = 'zz-cp-seed-pkg'");
    check('content-seed: package published, price on request, country India', $sp !== null && $sp['status'] === 'published' && $sp['price_from'] === null && $sp['country'] === 'India');
    $pl = Database::fetch("SELECT * FROM travel_places WHERE slug = 'zz-cp-seed-lake'");
    check('content-seed: place category/near/km from the hint', $pl !== null && $pl['category'] === 'lake' && $pl['near_stop'] === 'Nepalgunj' && (int) $pl['km_hint'] === 400);
    check('tools/content-seed.php refuses the web (CLI only)', str_contains((string) file_get_contents($root . '/tools/content-seed.php'), "PHP_SAPI !== 'cli'"));
} finally {
    $cleanup();
}

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
