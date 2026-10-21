<?php
/**
 * GET /api/nav-weather.php?lat=..&lng=.. — weather at a SHARI NAV
 * destination for the next 12 hours (21 Oct 2026, nv4).
 *
 * Source: MET Norway Locationforecast 2.0 (free incl. commercial use,
 * CC BY 4.0 — credited on the page). Their terms want an identifying
 * User-Agent, at most 4 decimals and cached answers: the point is rounded to
 * 2 decimals (~1 km, which also keeps the rider's exact destination private)
 * and each point is cached for 30 minutes in the temp dir.
 *
 * Answer: {ok, now: {t, sym, wind}, hours: [{at, t, sym, mm}, …], rain: bool}
 *   sym  = MET symbol code (clearsky_day, rain, heavyrainandthunder, …)
 *   mm   = rain in that hour, rain = wet weather expected in the next 6 h
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$fail = static function (int $code, string $msg): never {
    http_response_code($code);
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
};

$lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_GET['lng'] ?? null, FILTER_VALIDATE_FLOAT);
if ($lat === false || $lng === false || $lat < 5 || $lat > 40 || $lng < 60 || $lng > 100) {
    $fail(400, 'Outside the service area.');
}
$lat = round((float) $lat, 2);
$lng = round((float) $lng, 2);

$dir = sys_get_temp_dir() . '/shg-navwx';
$file = $dir . '/' . sprintf('%.2f_%.2f', $lat, $lng) . '.json';
if (is_file($file) && time() - (int) filemtime($file) < 1800) {
    $cached = file_get_contents($file);
    if (is_string($cached) && $cached !== '') {
        header('Cache-Control: public, max-age=600');
        header('X-SHG-Wx: cache');
        echo $cached;
        exit;
    }
}
if (!Security::rateLimit('nav_wx', Security::clientIp(), 20, 60)) {
    $fail(429, 'Too many requests — wait a minute.');
}

$ch = curl_init('https://api.met.no/weatherapi/locationforecast/2.0/compact?lat=' . $lat . '&lon=' . $lng);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_ENCODING => '',
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_USERAGENT => 'SHARI-NAV/1.0 (+https://www.shreehariglobal.in/navigate.html)']);
$res  = curl_exec($ch);
$http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
$j = $http === 200 && is_string($res) ? json_decode($res, true) : null;
$ts = is_array($j) ? ($j['properties']['timeseries'] ?? null) : null;
if (!is_array($ts) || !$ts) {
    $fail(502, 'Weather is not available right now.');
}

$hours = [];
$rain = false;
$now = time();
foreach ($ts as $row) {
    $at = strtotime((string) ($row['time'] ?? ''));
    if (!$at || $at < $now - 3600) {
        continue;
    }
    $d = $row['data'] ?? [];
    $sym = (string) ($d['next_1_hours']['summary']['symbol_code'] ?? $d['next_6_hours']['summary']['symbol_code'] ?? '');
    $mm = (float) ($d['next_1_hours']['details']['precipitation_amount'] ?? 0);
    $hours[] = [
        'at'   => $at,
        't'    => (int) round((float) ($d['instant']['details']['air_temperature'] ?? 0)),
        'wind' => (int) round((float) ($d['instant']['details']['wind_speed'] ?? 0) * 3.6),
        'sym'  => preg_replace('/[^a-z_]/', '', $sym),
        'mm'   => round($mm, 1),
    ];
    if (count($hours) <= 6 && ($mm >= 0.3 || preg_match('/rain|sleet|snow|thunder/', $sym))) {
        $rain = true;
    }
    if (count($hours) >= 12) {
        break;
    }
}
if (!$hours) {
    $fail(502, 'Weather is not available right now.');
}
$first = $hours[0];
$out = (string) json_encode(['ok' => true, 'now' => ['t' => $first['t'], 'sym' => $first['sym'], 'wind' => $first['wind']],
    'hours' => $hours, 'rain' => $rain], JSON_UNESCAPED_SLASHES);

if (is_dir($dir) || @mkdir($dir, 0775, true)) {
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $out) !== false) {
        @rename($tmp, $file);
    }
}
header('Cache-Control: public, max-age=600');
echo $out;
