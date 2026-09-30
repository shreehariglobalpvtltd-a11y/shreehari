<?php
/**
 * admin/api/seatmap-poll.php — lightweight JSON endpoint for seat map polling.
 *
 * Returns current seat statuses and summary counts so the visual map can
 * refresh without a full page reload.  Protected by the same admin gate
 * as the seat map page itself.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
admin_boot('schedules.view');

header('Content-Type: application/json; charset=utf-8');

$routeId = (int) ($_GET['route'] ?? 0);
$date    = Security::clean($_GET['date'] ?? '', 10);

if (!$routeId || !Security::isValidDate($date)) {
    echo json_encode(['error' => 'invalid']);
    exit;
}

$route = Database::fetch("SELECT coach_type FROM routes WHERE id = :id", ['id' => $routeId]);
if (!$route) {
    echo json_encode(['error' => 'no route']);
    exit;
}

$sidReq   = (int) ($_GET['sid'] ?? 0);   // extra bus on the same date (Bus Calendar, 5 Sep 2026)
$schedule = Seats::scheduleFor($routeId, $date, $sidReq > 0 ? $sidReq : null);
$map      = Seats::adminSeatMap((int) $schedule['id'], Seats::effectiveCoach($schedule, $route));

$seats = [];
foreach ($map as $s) {
    $entry = [
        'status' => $s['status'],
        'gender' => $s['gender'] ?? null,
        // Pickup / drop short code (MSN, STV…). Ungated for the same reason as
        // status and gender — see the note in Seats::adminSeatMap(). Shipped on
        // every tick because the poller rewrites the tile wholesale; omit it
        // here and the code renders on load and vanishes 15 seconds later.
        'stop'   => (string) ($s['stop'] ?? ''),
        'stopHue' => (int) ($s['stopHue'] ?? -1),
        'drop'   => (string) ($s['drop'] ?? ''),
    ];
    if (($s['stopName'] ?? '') !== '') {
        $entry['stopName'] = (string) $s['stopName'];
        $entry['stopTime'] = $s['stopTime'] ?? null;
    }
    if (($s['dropName'] ?? '') !== '') {
        $entry['dropName'] = (string) $s['dropName'];
    }
    // Respect the same privacy model as the table: only expose passenger
    // details for seats the viewer "owns" (their own bookings / their scope).
    if (!empty($s['mine'])) {
        $entry['pnr']       = $s['pnr'] ?? null;
        $entry['passenger'] = $s['passenger'] ?? null;
        $entry['channel']   = $s['channel'] ?? null;
        // SHG-### code — precomputed by adminSeatMap, only present on mine.
        $entry['agentCode'] = (string) ($s['agentCode'] ?? '');
    }
    if ($s['status'] === 'held' && !empty($s['holdUntil'])) {
        $entry['holdUntil'] = substr((string) $s['holdUntil'], 11, 5);
    }
    $seats[$s['seat']] = $entry;
}

$counts = ['open' => 0, 'booked' => 0, 'held' => 0, 'blocked' => 0, 'staff' => 0];
foreach ($map as $s) {
    $counts[$s['status']] = ($counts[$s['status']] ?? 0) + 1;
}

/* Conditional answer: the office seat map polls every 15 s (and at once on a
   live "seats" event). When nothing changed the browser's If-None-Match hits
   the ETag and the reply is an empty 304 instead of the whole map. */
$json = json_encode(['seats' => $seats, 'counts' => $counts]);
$etag = '"' . md5((string) $json) . '"';
header('Cache-Control: private, no-cache, must-revalidate');
header('ETag: ' . $etag);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
echo $json;
