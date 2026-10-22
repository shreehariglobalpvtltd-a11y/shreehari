<?php
/**
 * POST /api/nav-attrs.php {shape: "<encoded polyline, precision 6>", costing}
 *
 * SHARI NAV Phase 3 (22 Oct 2026): a road check for a route our own engine
 * drew - Valhalla /trace_attributes with shape_match edge_walk, so the shape
 * is the route itself, not a guess. Answers what a car / coach / scooter
 * driver wants to know before leaving:
 *   toll  {km}                      tolled road
 *   narrow{km, at:[{lat,lng,name}]} roads tagged under 4 m wide, or a
 *                                   single-lane road (for a coach, also
 *                                   service / track roads)
 *   rough {km}                      unpaved / poor surface
 * Routes up to 400 km (8000 points). Our engine only - no public fallback.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Cache-Control: no-store');

try {
    Security::requirePost();
    Security::requireRateLimit('nav_attrs', Security::clientIp(), 12, 60);
    $shape = (string) Response::field('shape', '');
    $costing = (string) Response::field('costing', 'auto');
    $costing = in_array($costing, ['auto', 'bus', 'motorcycle'], true) ? $costing : 'auto';
    if ($shape === '' || strlen($shape) > 200000 || preg_match('/^[\x21-\x7e]+$/', $shape) !== 1) {
        Response::invalid(['shape' => 'No route.']);
    }
    $body = (string) json_encode([
        'encoded_polyline' => $shape, 'shape_match' => 'edge_walk', 'costing' => $costing,
        'filters' => ['attributes' => ['edge.length', 'edge.names', 'edge.road_class', 'edge.use', 'edge.toll', 'edge.unpaved',
                                       'edge.surface', 'edge.width', 'edge.lane_count', 'edge.begin_shape_index', 'shape'],
                      'action' => 'include'],
    ]);
    $base = rtrim(Settings::getString('nav_engine_url', 'http://127.0.0.1:8002'), '/');
    $ch = curl_init($base . '/trace_attributes');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json']]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if ($http !== 200 || !is_array($j) || !is_array($j['edges'] ?? null)) {
        Response::error('The road check is not available for this route.', 502);
    }

    /* the matched shape (precision 6) gives each edge a place on the map */
    $pts = [];
    $s = (string) ($j['shape'] ?? '');
    for ($i = 0, $la = 0, $lo = 0, $n = strlen($s); $i < $n;) {
        foreach ([0, 1] as $axis) {
            $r = 0; $sh = 0;
            do { $b = ord($s[$i++]) - 63; $r |= ($b & 0x1f) << $sh; $sh += 5; } while ($b >= 0x20 && $i < $n);
            $d = ($r & 1) ? ~($r >> 1) : ($r >> 1);
            if ($axis === 0) { $la += $d; } else { $lo += $d; }
        }
        $pts[] = [$la / 1e6, $lo / 1e6];
    }
    $coach = $costing === 'bus';
    $toll = 0.0; $narrow = 0.0; $rough = 0.0; $at = []; $lastAt = -1;
    foreach ($j['edges'] as $e) {
        $km = (float) ($e['length'] ?? 0);
        if (!empty($e['toll'])) {
            $toll += $km;
        }
        $w = (float) ($e['width'] ?? 0);
        $rc = (string) ($e['road_class'] ?? '');
        $use = (string) ($e['use'] ?? '');
        $isNarrow = ($w > 0 && $w < 4.0) || (int) ($e['lane_count'] ?? 0) === 1
            || ($coach && (in_array($use, ['track', 'driveway', 'alley'], true) || $rc === 'service_other'));
        if ($isNarrow && $km > 0.05) {
            $narrow += $km;
            $si = (int) ($e['begin_shape_index'] ?? -1);
            if (count($at) < 5 && isset($pts[$si]) && $si - $lastAt > 20) {
                $at[] = ['lat' => round($pts[$si][0], 5), 'lng' => round($pts[$si][1], 5), 'name' => (string) (($e['names'] ?? [])[0] ?? '')];
                $lastAt = $si;
            }
        }
        $surf = (string) ($e['surface'] ?? '');
        if (!empty($e['unpaved']) || in_array($surf, ['impassable', 'path', 'dirt', 'gravel'], true)) {
            $rough += $km;
        }
    }
    Response::success(['toll' => ['km' => round($toll, 1)], 'narrow' => ['km' => round($narrow, 1), 'at' => $at], 'rough' => ['km' => round($rough, 1)]]);
} catch (Throwable $e) {
    Response::serverError($e);
}
