<?php
/**
 * travel-info-test.php — the India ⇄ Nepal border travel-info page (2 Oct 2026).
 *
 *   A. Wiring: nginx exact location (fastcgi_params), dev router, sitemap
 *      source, and no phone number typed into the page.
 *   B. /travel-info and /travel-info.php answer 200, light, no PHP noise.  [HTTP]
 *   C. Three language blocks with the same structure, the switcher, the
 *      SPA's 'shg:lang' key, ?lang= honoured server-side.                [HTTP]
 *   D. FAQPage JSON-LD parses and matches the English FAQ.               [HTTP]
 *   E. Contact comes from Settings; NPR peg from Settings; key facts.    [HTTP]
 *   F. The pages sitemap lists the URL.                                  [HTTP]
 *
 * Needs the dev server (SHG_TEST_BASE, default http://localhost:8899).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function get(string $path): array {
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => true]);
    $raw = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}
/** @return array<string,string> lang => that language's block */
function blocks(string $html): array {
    $out = [];
    foreach (['en', 'hi', 'ne'] as $l) {
        $out[$l] = preg_match('#<div class="L" lang="' . $l . '">(.*?)</div>\n#s', $html, $m) ? $m[1] : '';
    }
    return $out;
}

$root = dirname(__DIR__);
$src  = (string) file_get_contents($root . '/travel-info.php');

echo "\n=== Travel info page ===\n\n-- A. wiring --\n";
$lint = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/travel-info.php') . ' 2>&1');
check('travel-info.php lints', str_contains($lint, 'No syntax errors'), trim($lint));
$ngx = (string) file_get_contents($root . '/deploy/nginx-shreehariglobal.in.conf');
$loc = preg_match('#location = /travel-info \{(.*?)\}#s', $ngx, $m) ? $m[1] : '';
check('nginx: exact location for /travel-info', $loc !== '');
check('nginx: fastcgi_params + explicit SCRIPT_FILENAME, no fastcgi-php.conf', str_contains($loc, 'include fastcgi_params;') && str_contains($loc, 'SCRIPT_FILENAME $document_root/travel-info.php') && !str_contains($loc, 'fastcgi-php.conf') && !str_contains($loc, 'fastcgi.conf'));
check('dev router maps /travel-info', str_contains((string) file_get_contents($root . '/tests/http-router.php'), "'/travel-info' => '/travel-info.php'"));
check('sitemap source lists /travel-info', str_contains((string) file_get_contents($root . '/includes/routepages.php'), "['/travel-info', "));
check('no phone number typed into the page', !preg_match('/\d{5}\s?\d{5}|wa\.me\/\d/', $src));
check('contact read from Settings', str_contains($src, 'Settings::officePhone()') && str_contains($src, 'Settings::officeWhatsApp()'));

$probe = get('/travel-info.php');
if ($probe['code'] === 0) {
    check('dev server reachable at ' . BASE, false, 'start it, or set SHG_TEST_BASE');
    echo "\n----------------------------------------\nPASSED: $pass   FAILED: $fail\n";
    exit($fail ? 1 : 0);
}

echo "\n-- B. both URLs --\n";
$p = get('/travel-info');
check('/travel-info 200 text/html', $p['code'] === 200 && stripos($p['headers'], 'text/html') !== false, 'HTTP ' . $p['code']);
check('/travel-info.php 200, same page', $probe['code'] === 200 && str_contains($probe['body'], '<div class="L" lang="ne">'), 'HTTP ' . $probe['code']);
$noise = '/(Warning|Fatal error|Parse error|Notice|Deprecated|Stack trace|Uncaught)\b/';
check('no PHP warnings or fatals', !preg_match($noise, $p['body']) && !preg_match($noise, $probe['body']));
check('light: under 40 KB, no SPA bundle', strlen($p['body']) < 40000 && !str_contains($p['body'], '05-router.js') && !str_contains($p['body'], 'app.css'), strlen($p['body']) . ' bytes');
check('canonical is the pretty URL', str_contains($p['body'], '<link rel="canonical" href="' . rtrim(APP_URL, '/') . '/travel-info">'));
check('title + meta description (bilingual)', (bool) preg_match('#<title>Travel Info[^<]+</title>#u', $p['body']) && (bool) preg_match('#<meta name="description" content="[^"]*नेपाल[^"]*">#u', $p['body']));

echo "\n-- C. three languages --\n";
$b = blocks($p['body']);
check('en / hi / ne blocks all present', $b['en'] !== '' && $b['hi'] !== '' && $b['ne'] !== '');
$faqN = array_map(static fn(string $s): int => substr_count($s, '<details>'), $b);
check('6+ FAQ entries in each language, same count', min($faqN) >= 6 && count(array_unique($faqN)) === 1, json_encode($faqN));
$liN = array_map(static fn(string $s): int => substr_count($s, '<li>'), $b);
$h4N = array_map(static fn(string $s): int => substr_count($s, '<h4>'), $b);
check('same structure in each language (lists, sub-heads)', count(array_unique($liN)) === 1 && count(array_unique($h4N)) === 1, json_encode($liN));
check('Hindi is Hindi, Nepali is Nepali', str_contains($b['hi'], 'करें') && str_contains($b['hi'], 'बॉर्डर') && str_contains($b['ne'], 'गर्नुहोस्') && str_contains($b['ne'], 'हुन्छ'));
check('switcher: EN / हिं / ने with ?lang links', str_contains($p['body'], 'href="?lang=en" data-l="en"') && str_contains($p['body'], '>हिं</a>') && str_contains($p['body'], '>ने</a>'));
check('reuses the SPA language key shg:lang', substr_count($p['body'], "'shg:lang'") >= 2);
check('default server language is English', str_contains($p['body'], '<html lang="en" data-lang="en">'));
foreach (['hi', 'ne'] as $l) {
    $q = get('/travel-info?lang=' . $l);
    check("?lang=$l renders data-lang=\"$l\"", $q['code'] === 200 && str_contains($q['body'], '<html lang="' . $l . '" data-lang="' . $l . '">'));
}
check('?lang=xx falls back to English', str_contains(get('/travel-info?lang=xx')['body'], 'data-lang="en"'));

echo "\n-- D. FAQ JSON-LD --\n";
preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $p['body'], $m);
$ld = json_decode($m[1] ?? '', true);
check('JSON-LD parses as JSON', is_array($ld), json_last_error_msg());
$faq = null;
foreach ((array) ($ld['@graph'] ?? []) as $node) { if (($node['@type'] ?? '') === 'FAQPage') { $faq = $node; } }
$qs = (array) ($faq['mainEntity'] ?? []);
check('FAQPage with 6+ questions', count($qs) >= 6, count($qs) . ' questions');
$allIn = $qs !== [];
foreach ($qs as $q) {
    $allIn = $allIn && ($q['@type'] ?? '') === 'Question' && trim((string) ($q['acceptedAnswer']['text'] ?? '')) !== ''
        && !str_contains((string) $q['acceptedAnswer']['text'], '<')
        && str_contains(html_entity_decode($b['en'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), (string) $q['name']);
}
check('every JSON-LD question is on the page (English), plain-text answers', $allIn);
check('FAQ covers Aadhaar, children, border time, NPR, missed bus, finding the ticket', (bool) preg_match('/Aadhaar.*children.*border.*Nepali rupees.*miss the bus.*find my ticket/is', implode(' ', array_column($qs, 'name'))));

echo "\n-- E. contact and facts --\n";
$tel = preg_replace('/[^0-9+]/', '', Settings::officePhone());
check('tel: link is Settings::officePhone()', str_contains($p['body'], 'href="tel:' . $tel . '"'), $tel);
check('WhatsApp is Settings::officeWhatsApp()', str_contains($p['body'], 'https://wa.me/' . Settings::officeWhatsApp() . '?text='));
check('email is the company email', str_contains($p['body'], 'mailto:' . Settings::company()['email']));
$peg = Settings::getFloat('npr_per_inr', 1.6);
check('NPR peg from Settings', $peg <= 0 || substr_count($p['body'], 'NPR ' . number_format($peg, 2)) >= 6, 'NPR ' . number_format($peg, 2));
check('Book a seat → / and WhatsApp CTA in each language', substr_count($p['body'], '<a class="btn hot" href="/">') === 3 && substr_count($p['body'], 'class="btn wa"') === 3);
check('rules-change note in each language', substr_count($p['body'], 'class="card alert" role="note"') === 3);
check('Aadhaar caution in all three', str_contains($b['en'], 'Aadhaar alone is often not accepted') && str_contains($b['hi'], 'आधार') && str_contains($b['ne'], 'आधार'));
check('missed-bus answer links the refund policy (all three)', substr_count($p['body'], 'href="/refund-policy"') >= 6);
check('no link to the keyed track.php', !str_contains($p['body'], 'track.php'));
check('coach ends at Rupaidiha (route data)', str_contains($b['en'], 'Our coach ends at <b>Rupaidiha</b>') && (int) (Database::fetch("SELECT COUNT(*) AS n FROM routes WHERE is_active = 1 AND to_city = 'Rupaidiha'")['n'] ?? 0) > 0);

echo "\n-- F. sitemap --\n";
$sp = get('/sitemap-pages.xml');
$xml = @simplexml_load_string($sp['body']);
check('sitemap-pages.xml is valid XML and lists /travel-info', $xml !== false && str_contains($sp['body'], '<loc>' . rtrim(APP_URL, '/') . '/travel-info</loc>'));
check('sitemap.xml still indexes sitemap-pages.xml', str_contains(get('/sitemap.xml')['body'], 'sitemap-pages.xml'));

echo "\n----------------------------------------\nPASSED: $pass   FAILED: $fail\n";
exit($fail ? 1 : 0);
