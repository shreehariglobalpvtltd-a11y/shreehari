<?php
/**
 * admin/api/trip-trail.php - one trip's GPS trail, for the replay on
 * admin/map.php?trip=<id> (15 Nov 2026).
 *
 *     GET /admin/api/trip-trail.php?trip=<live_trips.id>
 *
 * Answers the trip (bus, route, date, status) and its fixes as
 * [lng, lat, unix seconds, km/h|null, bearing|null], oldest first: coarse
 * fixes and lone GPS spikes dropped, thinned to GpsFleet::TRAIL_MAX points
 * (first and last always kept). 'total' = usable fixes before thinning.
 *
 * Read-only. Same gate as the map and admin/api/live-fleet.php
 * (schedules.view); no session = 401 JSON (Auth::requireAdmin).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/_guard.php';
admin_boot('schedules.view');
require_once INCLUDE_PATH . '/gpsfleet.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

$out = static function (array $body, int $code = 200): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $out(['ok' => false, 'error' => 'GET only.'], 405);
}
$tripId = (int) ($_GET['trip'] ?? 0);
if ($tripId <= 0) {
    $out(['ok' => false, 'error' => 'Choose a trip.'], 422);
}

try {
    $trail = GpsFleet::trail($tripId);
} catch (Throwable $e) {
    Logger::error('trip-trail failed', ['trip' => $tripId, 'e' => $e->getMessage()]);
    $out(['ok' => false, 'error' => 'Could not read this trip\'s GPS right now.'], 500);
}
if ($trail === null) {
    $out(['ok' => false, 'error' => 'No GPS record for this trip.'], 404);
}
$out(['ok' => true, 'data' => $trail]);
