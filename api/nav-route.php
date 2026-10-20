<?php
/**
 * GET /api/nav-route.php?json={…Valhalla route request…}  — SHARI NAV routing (5 Oct 2026).
 *
 * Our own routing engine: Valhalla running on this server (docker
 * `shg-valhalla`, 127.0.0.1:8002, built by tools/navdata/02-build.sh from
 * OpenStreetMap for Gujarat + the Nepalgunj border area). The answer is the
 * exact Valhalla JSON assets/js/15-nav.js already reads, so the navigator
 * needs no other change.
 *
 * Outside our map area (error 171 "no suitable edges") or if the engine is
 * down, the same request goes to the public Valhalla server — the rider
 * still gets a route — and X-SHG-Nav says which engine answered.
 * Only a vetted subset of the request is forwarded.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$fail = static function (int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['error' => $msg, 'status_code' => $code], JSON_UNESCAPED_SLASHES);
    exit;
};

if (!Security::rateLimit('nav_route', Security::clientIp(), 90, 60)) {
    $fail(429, 'Too many route requests — wait a minute.');
}
$in = json_decode((string) ($_GET['json'] ?? ''), true);
if (!is_array($in)) {
    $fail(400, 'Bad request.');
}

/* ---- rebuild the request from known fields only ---- */
$locs = [];
foreach (array_slice((array) ($in['locations'] ?? []), 0, 5) as $l) {
    $lat = (float) ($l['lat'] ?? 0);
    $lon = (float) ($l['lon'] ?? 0);
    if ($lat < 5 || $lat > 40 || $lon < 60 || $lon > 100) {        // India + Nepal and around
        $fail(400, 'Location outside the service area.');
    }
    $loc = ['lat' => round($lat, 6), 'lon' => round($lon, 6)];
    // the direction the rider is moving (a re-route while driving): Valhalla
    // then starts on the road ahead, not on the lane going the other way
    if (is_numeric($l['heading'] ?? null)) {
        $loc['heading'] = ((int) $l['heading'] % 360 + 360) % 360;
        $loc['heading_tolerance'] = 60;
    }
    $locs[] = $loc;
}
if (count($locs) < 2) {
    $fail(400, 'Need a start and a destination.');
}
$costing = (string) ($in['costing'] ?? 'auto');
if (!in_array($costing, ['auto', 'motorcycle', 'bus', 'bicycle', 'pedestrian', 'motor_scooter'], true)) {
    $costing = 'auto';
}
$body = ['locations' => $locs, 'costing' => $costing, 'directions_options' => ['units' => 'kilometers']];
$lang = (string) ($in['directions_options']['language'] ?? '');
if (preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $lang) === 1) {
    $body['directions_options']['language'] = $lang;
}
$alt = (int) ($in['alternates'] ?? 0);
if ($alt > 0) {
    $body['alternates'] = min(2, $alt);
}
/* navigate.html (20 Oct 2026) asks for the OSRM-shaped answer: steps with
   lanes, banner and voice instructions, GeoJSON lines. Omitted, the answer
   stays the plain Valhalla JSON assets/js/15-nav.js reads. */
if (($in['format'] ?? '') === 'osrm') {
    $body['format'] = 'osrm';
    $body['shape_format'] = ($in['shape_format'] ?? '') === 'geojson' ? 'geojson' : 'polyline6';
    $body['banner_instructions'] = true;
    $body['voice_instructions'] = true;
}
if (is_array($in['costing_options'] ?? null)) {
    $co = [];
    foreach ($in['costing_options'] as $mode => $opts) {
        if (!is_string($mode) || !is_array($opts) || !in_array($mode, ['auto', 'motorcycle', 'bus', 'bicycle', 'pedestrian'], true)) {
            continue;
        }
        foreach ($opts as $k => $v) {
            if (is_string($k) && preg_match('/^[a-z_]{2,30}$/', $k) === 1 && (is_int($v) || is_float($v) || (is_string($v) && strlen($v) < 30))) {
                $co[$mode][$k] = $v;
            }
        }
    }
    if ($co !== []) {
        $body['costing_options'] = $co;
    }
}
$q = '/route?json=' . rawurlencode((string) json_encode($body));

$call = static function (string $base, string $q, int $timeout): array {
    $ch = curl_init($base . $q);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'SHARI-NAV/1.0 (+https://www.shreehariglobal.in)']);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$http, is_string($raw) ? $raw : ''];
};

$own = rtrim(Settings::getString('nav_engine_url', 'http://127.0.0.1:8002'), '/');
[$http, $raw] = $call($own, $q, 8);
if ($http === 200) {
    header('X-SHG-Nav: own');
    echo $raw;
    exit;
}
$err = json_decode($raw, true);
$outside = $http === 0 || $http >= 500 || in_array((int) ($err['error_code'] ?? 0), [170, 171, 442, 443], true)
    || in_array((string) ($err['code'] ?? ''), ['NoSegment', 'NoRoute'], true);              // the same errors, OSRM-shaped
if ($outside && Settings::getBool('nav_fallback_public', true)) {
    [$http2, $raw2] = $call('https://valhalla1.openstreetmap.de', $q, 12);
    if ($http2 > 0) {
        header('X-SHG-Nav: public');
        http_response_code($http2);
        echo $raw2;
        exit;
    }
}
header('X-SHG-Nav: own');
http_response_code($http > 0 ? $http : 503);
echo $raw !== '' ? $raw : json_encode(['error' => 'The route engine is not answering.', 'status_code' => 503]);
