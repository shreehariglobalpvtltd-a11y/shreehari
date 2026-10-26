<?php
/**
 * POST /api/nav-elevation.php — ground height along a SHARI NAV route
 * (21 Oct 2026, nv4: bike / walk elevation profile).
 *
 * Body: {shape: [[lat, lon], …]} (2…300 points, sampled evenly along the
 *        drawn route by the page)
 * Answer: {ok, h: [metres|null, …]} one height per point, same order.
 *
 * Valhalla /height on our own engine (SRTM tiles in
 * /srv/shg-nav/valhalla-full/elevation_data), the public server as fallback,
 * exactly as api/nav-limits.php does. Only the point list is forwarded.
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
if (!Security::rateLimit('nav_elev', Security::clientIp(), 20, 60)) {
    $fail(429, 'Too many requests — wait a minute.');
}
$raw = file_get_contents('php://input', false, null, 0, 30001);
if (!is_string($raw) || strlen($raw) > 30000) {
    $fail(413, 'Too large.');
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    $fail(400, 'Bad request.');
}

$pts = [];
foreach (array_slice((array) ($in['shape'] ?? []), 0, 300) as $p) {
    if (!is_array($p) || !is_numeric($p[0] ?? null) || !is_numeric($p[1] ?? null)) {
        continue;
    }
    $lat = (float) $p[0];
    $lon = (float) $p[1];
    if ($lat < 5 || $lat > 40 || $lon < 60 || $lon > 100) {        // India + Nepal and around
        $fail(400, 'Outside the service area.');
    }
    $pts[] = ['lat' => round($lat, 5), 'lon' => round($lon, 5)];
}
if (count($pts) < 2) {
    $fail(400, 'Need a stretch of road.');
}
$body = (string) json_encode(['shape' => $pts]);

$call = static function (string $base, int $timeout) use ($body): array {
    $ch = curl_init(rtrim($base, '/') . '/height');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_USERAGENT => 'SHARI-NAV/1.0 (+https://www.shreehariglobal.in)']);
    $res  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$http, is_string($res) ? $res : ''];
};
$heights = static function (array $j): ?array {
    $h = $j['height'] ?? null;
    if (!is_array($h)) {
        return null;
    }
    $out = [];
    $known = 0;
    foreach ($h as $v) {
        if (is_numeric($v) && $v > -500 && $v < 9000) {
            $out[] = (int) round((float) $v);
            $known++;
        } else {
            $out[] = null;
        }
    }
    return $known ? $out : null;                   // all nulls = engine has no terrain data there
};

[$http, $res] = $call(Settings::getString('nav_engine_url', 'http://127.0.0.1:8002'), 6);
$engine = 'own';
$h = $http === 200 ? $heights((array) json_decode($res, true)) : null;
if ($h === null && Settings::getBool('nav_fallback_public', true)) {
    [$http, $res] = $call('https://valhalla1.openstreetmap.de', 10);
    $engine = 'public';
    $h = $http === 200 ? $heights((array) json_decode($res, true)) : null;
}
header('X-SHG-Nav: ' . $engine);
if ($h === null) {
    $fail(502, 'No height data for this route.');
}
echo json_encode(['ok' => true, 'h' => $h], JSON_UNESCAPED_SLASHES);
