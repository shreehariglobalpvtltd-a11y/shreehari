<?php
/**
 * POST /api/nav-limits.php — speed limits along the next stretch of a SHARI
 * NAV route (20 Oct 2026, "drive like Google Maps" step 4).
 *
 * Body: {shape: [[lat, lon], …] (2…1500 points, the next ~40 km of the
 *        drawn route), costing: auto | motorcycle | bus}
 * Answer: {ok, km, limits: [[fromKm, toKm, kmh], …]} measured from the first
 *        point. A stretch whose limit is not in OpenStreetMap is simply not
 *        listed, and the navigator hides the sign there: never a guessed
 *        number on a speed sign.
 *
 * Valhalla /trace_attributes on our own engine (nav_engine_url), the public
 * server as fallback, exactly as api/nav-route.php does for routes. Only the
 * point list and the costing are forwarded.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$fail = static function (int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
};

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    $fail(405, 'POST only.');
}
if (!Security::rateLimit('nav_limits', Security::clientIp(), 30, 60)) {
    $fail(429, 'Too many requests — wait a minute.');
}
$raw = file_get_contents('php://input', false, null, 0, 120001);
if (!is_string($raw) || strlen($raw) > 120000) {
    $fail(413, 'Too large.');
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    $fail(400, 'Bad request.');
}

$pts = [];
foreach (array_slice((array) ($in['shape'] ?? []), 0, 1500) as $p) {
    if (!is_array($p) || !is_numeric($p[0] ?? null) || !is_numeric($p[1] ?? null)) {
        continue;
    }
    $lat = (float) $p[0];
    $lon = (float) $p[1];
    if ($lat < 5 || $lat > 40 || $lon < 60 || $lon > 100) {        // India + Nepal and around
        $fail(400, 'Outside the service area.');
    }
    $pts[] = ['lat' => round($lat, 6), 'lon' => round($lon, 6)];
}
if (count($pts) < 2) {
    $fail(400, 'Need a stretch of road.');
}
$costing = in_array($in['costing'] ?? '', ['auto', 'motorcycle', 'bus'], true) ? (string) $in['costing'] : 'auto';

$body = (string) json_encode([
    'shape'       => $pts,
    'costing'     => $costing,
    'shape_match' => 'map_snap',
    'units'       => 'kilometers',
    'filters'     => ['attributes' => ['edge.speed_limit', 'edge.length'], 'action' => 'include'],
]);

$call = static function (string $base, int $timeout) use ($body): array {
    $ch = curl_init(rtrim($base, '/') . '/trace_attributes');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_USERAGENT => 'SHARI-NAV/1.0 (+https://www.shreehariglobal.in)']);
    $res  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$http, is_string($res) ? $res : ''];
};

[$http, $res] = $call(Settings::getString('nav_engine_url', 'http://127.0.0.1:8002'), 8);
$engine = 'own';
if ($http !== 200 && ($http === 0 || $http >= 500 || $http === 400) && Settings::getBool('nav_fallback_public', true)) {
    [$http, $res] = $call('https://valhalla1.openstreetmap.de', 12);
    $engine = 'public';
}
header('X-SHG-Nav: ' . $engine);
$j = $http === 200 ? json_decode($res, true) : null;
if (!is_array($j) || !is_array($j['edges'] ?? null)) {
    $fail(502, 'The route engine did not answer.');
}

/* Edges come in travel order: their lengths laid end to end give the km of
   each stretch from the first point. Neighbouring stretches with the same
   limit are merged, so the answer stays a few dozen rows long. */
$out = [];
$km  = 0.0;
foreach ($j['edges'] as $e) {
    $len = max(0.0, (float) ($e['length'] ?? 0));
    $lim = $e['speed_limit'] ?? null;
    $lim = (is_int($lim) && $lim > 0 && $lim <= 150) ? $lim : null;
    if ($lim !== null) {
        $last = count($out) - 1;
        if ($last >= 0 && $out[$last][2] === $lim && abs($out[$last][1] - $km) < 0.001) {
            $out[$last][1] = round($km + $len, 3);
        } else {
            $out[] = [round($km, 3), round($km + $len, 3), $lim];
        }
    }
    $km += $len;
}
echo json_encode(['ok' => true, 'km' => round($km, 3), 'limits' => $out], JSON_UNESCAPED_SLASHES);
