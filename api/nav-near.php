<?php
/**
 * GET /api/nav-near.php — "<any kind of place> near me" for the SHARI NAV
 * guide (22 Oct 2026). Answers from our own place index (nav_places, 1.5 M
 * named places of India + Nepal) with one indexed box query; no AI key.
 *
 *   ?q=suggest me restaurant near me&lat=21.17&lng=72.83&lang=en
 *   ?q=hair salon in pokhara               (a town in the question wins)
 *   ?q=dominos near me&lat=…&lng=…         (no kind named: the NAME is searched)
 *
 * -> {ok:true, cat:{id,e,l}|null, ask:bool, at:{lat,lng,src:'me'|'town',name?}|null,
 *     km, results:[{name, local, kind, what, lat, lng, m}]}
 *    ask false = not surely a "near me" question ("office phone number"
 *    names a kind of shop but asks something else): the guide asks the
 *    assistant instead. at null = needs the rider's position.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/navcats.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!Security::rateLimit('nav_near', Security::clientIp(), 120, 60)) {
    http_response_code(429);
    header('Retry-After: 30');
    exit('{"ok":false,"reason":"rate"}');
}

$q    = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 160));
$lang = in_array($_GET['lang'] ?? '', ['ne', 'hi', 'en'], true) ? (string) $_GET['lang'] : 'en';
$lat  = isset($_GET['lat']) && is_numeric($_GET['lat']) ? (float) $_GET['lat'] : null;
$lng  = isset($_GET['lng']) && is_numeric($_GET['lng']) ? (float) $_GET['lng'] : null;
if ($lat !== null && ($lat < 5 || $lat > 40 || $lng === null || $lng < 60 || $lng > 100)) {
    $lat = $lng = null;                                   // outside India + Nepal: no "near me"
}

$out = ['ok' => true, 'cat' => null, 'ask' => false, 'at' => null, 'km' => 0, 'results' => []];
try {
    $p = NavCats::parse($q);
    $cat = $p['cat'];
    if ($cat !== null) {
        $c = NavCats::CATS[$cat];
        $out['cat'] = ['id' => $cat, 'e' => $c[0], 'l' => NavCats::label($cat, $lang)];
    }
    if ($cat === null && (!$p['near'] || $p['rest'] === [])) {
        header('Cache-Control: public, max-age=300');
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
    // "salon in pokhara": a town named in the question is the centre, unless
    // the rest is a brand searched "near me" (no kind named)
    $town = $p['rest'] !== [] && ($cat !== null || $lat === null) ? NavCats::town($p['rest']) : null;
    $words = $p['rest'];
    if ($town !== null) {
        $out['at'] = ['lat' => $town['lat'], 'lng' => $town['lng'], 'src' => 'town', 'name' => $town['name']];
        $words = array_values(array_diff($p['rest'], $town['words']));
    } elseif ($lat !== null) {
        $out['at'] = ['lat' => round($lat, 4), 'lng' => round($lng, 4), 'src' => 'me'];
    }
    // a place question for sure: "near" said, or nothing left but the kind (+ town).
    // "office phone number" / "price of gold" name a kind too, but ask something else.
    $out['ask'] = $p['near'] || ($cat !== null && $words === []);
    if ($out['at'] !== null) {
        $r = NavCats::search($cat, $cat === null ? $words : [], (float) $out['at']['lat'], (float) $out['at']['lng'], 12);
        $out['results'] = $r['rows'];
        $out['km'] = $r['km'];
    }
} catch (Throwable $e) {
    $out['results'] = [];                                 // nav_places not imported (a dev box): the guide falls back
}
header('Cache-Control: public, max-age=120');
echo json_encode($out, JSON_UNESCAPED_UNICODE);
