<?php
/**
 * api/track-map.php?pnr=…&k=…[&part=line&v=N]  — the map on track.php
 * (20 Oct 2026, live tracking phase 2).
 *
 * The same door as track.php and api/whereis.php (WhereIs::authorised).
 *
 *   (default)      the route's road-line version and length, its stops that
 *                  have coordinates (km along the road, the passenger's own
 *                  pickup / drop marked) and the coach's position from
 *                  WhereIs::status() - live, or "last seen", or neither.
 *   part=line&v=N  the road line itself (encoded polyline, precision 6).
 *                  When N is the stored version the answer is cacheable for
 *                  30 days: a rebuild bumps the version, so the page asks a
 *                  new URL. Sent once per phone per version (~60-100 KB).
 *
 * Nothing here is a guess: no road line = no line; a stop without
 * coordinates is not drawn (mineMissing tells the page to say so); the bus
 * is drawn only where WhereIs::status() says it is.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/whereis.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$fail = static function (int $code, string $msg): never {
    header('Cache-Control: no-store');
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};

try {
    Security::requireRateLimit('trackmap', Security::clientIp(), 60, 60);
    $pnr   = strtoupper(Security::clean((string) ($_GET['pnr'] ?? ''), 40));
    $token = Security::clean((string) ($_GET['k'] ?? ''), 64);
    $booking = $pnr !== '' ? BookingService::findByPnr($pnr) : null;
    if ($booking === null || !WhereIs::authorised($booking, $token)) {
        $fail(404, 'Not found.');
    }
    $leg = WhereIs::leg($booking);
    if ($leg === null) {
        $fail(404, 'No trip on this ticket.');
    }
    $routeId = $leg['route_id'];
    $road    = RouteGeo::load($routeId);

    if (($_GET['part'] ?? '') === 'line') {
        if ($road === null) {
            $fail(404, 'This route has no road line yet.');
        }
        $asked = (int) ($_GET['v'] ?? 0);
        header($asked === $road['version'] ? 'Cache-Control: private, max-age=2592000, immutable' : 'Cache-Control: no-store');
        echo json_encode(['ok' => true, 'data' => ['v' => $road['version'], 'line' => $road['polyline6'],
            'km' => round($road['length_m'] / 1000, 1)]], JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Cache-Control: no-store');
    $geo     = EtaAlerts::routeLine($routeId);
    $board   = Boarding::townKey($leg['boarding_stop']);
    $drop    = $leg['drop_stop'] !== '' ? Boarding::townKey($leg['drop_stop']) : '';
    $stops   = [];
    $mineHas = false;
    foreach ($geo['line'] as $s) {
        $k    = Boarding::townKey($s['name']);
        $mine = $s['type'] === 'boarding' && $board !== '' && $k === $board ? 'board'
              : ($s['type'] === 'drop' && $drop !== '' && $k === $drop ? 'drop' : '');
        if ($mine === 'board') {
            $mineHas = true;
        }
        $stops[] = ['name' => (string) Boarding::stopDisplay($s['name'])['name'], 'type' => $s['type'],
                    'lat' => round($s['lat'], 6), 'lng' => round($s['lng'], 6), 'km' => round((float) $s['km'], 1), 'mine' => $mine];
    }
    // No drop on the ticket = the end of the line. A drop that has no coordinates is simply not drawn.
    if ($drop === '' && $stops !== [] && $stops[count($stops) - 1]['type'] === 'drop') {
        $stops[count($stops) - 1]['mine'] = 'drop';
    }

    $st  = WhereIs::status($booking);
    $pos = array_intersect_key($st, array_flip(['live', 'state', 'lat', 'lng', 'kmh', 'fixAge', 'road', 'passed',
        'onBoard', 'drop', 'dropEtaMin', 'dropKm', 'lastKnown', 'lastAt', 'lastLat', 'lastLng', 'lastStop', 'etaMin', 'km', 'stop', 'progress', 'updatedAt']));

    echo json_encode(['ok' => true, 'data' => [
        'v'           => $road !== null ? $road['version'] : null,
        'km'          => $road !== null ? round($road['length_m'] / 1000, 1) : null,
        'onRoad'      => isset($geo['road']),          // false: a stop moved since the line was built
        'stops'       => $stops,
        'mineMissing' => !$mineHas,
        'pos'         => $pos,
    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Logger::error('track-map failed', ['e' => $e->getMessage()]);
    $fail(500, 'Could not read the map right now.');
}
