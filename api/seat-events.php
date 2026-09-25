<?php
/**
 * =====================================================================
 *  api/seat-events.php — live seat changes as a Server-Sent Events stream.
 *
 *      GET /api/seat-events.php?scheduleId=123
 *      GET /api/seat-events.php?routeCode=r2&date=2026-10-12
 *
 *  The customer's seat map, the counter and the office seat map used to
 *  learn about each other's sales by polling every 8–15 s. This stream
 *  watches SeatVersion once a second and pushes "seats" the moment a
 *  booking, hold or block lands, so all three screens agree within about
 *  a second — and the client then fetches the real snapshot it already
 *  knows how to draw. Nothing about availability is decided here.
 *
 *  Shape of the stream:
 *      retry: 2000
 *      event: seats      data: {"ver":"…"}      (on every change, and once on connect)
 *      : ping                                    (every 15 s of silence)
 *      event: bye        data: {}                (after seat_events_seconds, the
 *                                                 browser reconnects by itself)
 *
 *  Each open stream holds one PHP-FPM worker for at most seat_events_seconds
 *  (25 s by default). That is why the whole feature sits behind the
 *  seat_events_on switch (OFF), why the stream ends early rather than living
 *  for hours, and why a client only opens it while its tab is visible.
 *  nginx must not buffer this location (see deploy/nginx-…conf).
 * ===================================================================== */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/seatversion.php';

// The session was opened by bootstrap; release its file lock at once so
// this long request never blocks the visitor's other calls (lock, book).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$deny = static function (int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $deny(405, 'GET only.');
}
if (!Settings::getBool('seat_events_on', false)) {
    $deny(404, 'Live seat events are switched off.');
}
if (!Security::rateLimit('seat_events', Security::clientIp(), 40, 60)) {
    $deny(429, 'Too many streams from this address — try again in a minute.');
}

try {
    $scheduleId = (int) ($_GET['scheduleId'] ?? 0);
    if ($scheduleId > 0) {
        if (!Database::exists('SELECT 1 FROM schedules WHERE id = :id', ['id' => $scheduleId])) {
            $deny(404, 'That departure no longer exists.');
        }
    } else {
        $routeId   = (int) ($_GET['routeId'] ?? 0);
        $routeCode = Security::clean((string) ($_GET['routeCode'] ?? ''), 20);
        $date      = Security::clean((string) ($_GET['date'] ?? ''), 10);
        if ($routeId <= 0 && $routeCode !== '') {
            $routeId = (int) Database::scalar('SELECT id FROM routes WHERE route_code = :c AND is_active = 1 LIMIT 1', ['c' => $routeCode], 0);
        }
        if ($routeId <= 0 || !Security::isValidDate($date)) {
            $deny(422, 'Name a departure: scheduleId, or routeCode + date.');
        }
        $scheduleId = (int) (Seats::schedule($routeId, $date)['id'] ?? 0);
        if ($scheduleId <= 0) {
            $deny(404, 'No departure on that date.');
        }
    }
} catch (Throwable $e) {
    Logger::error('seat-events: cannot resolve the departure', ['e' => $e->getMessage()]);
    $deny(500, 'Could not open the live stream.');
}

$maxSeconds = (int) ($_GET['max'] ?? 0);
if ($maxSeconds <= 0) {
    $maxSeconds = Settings::getInt('seat_events_seconds', 25);
}
$maxSeconds = max(3, min(60, $maxSeconds));

ignore_user_abort(false);
set_time_limit($maxSeconds + 15);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');      // nginx: do not buffer this response
header('Connection: keep-alive');
while (ob_get_level() > 0) {
    ob_end_flush();
}

$emit = static function (string $event, array $data): void {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
};

echo "retry: 2000\n\n";
flush();

$known = Security::clean((string) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['ver'] ?? '')), 40);
$ver   = SeatVersion::of($scheduleId);
if ($ver !== $known) {
    echo 'id: ' . $ver . "\n";
    $emit('seats', ['ver' => $ver, 'scheduleId' => $scheduleId]);
}

$started = time();
$quiet   = 0;
while ((time() - $started) < $maxSeconds) {
    usleep(1000000);
    if (connection_aborted()) {
        break;
    }
    $now = SeatVersion::of($scheduleId);
    if ($now !== $ver) {
        $ver = $now;
        $quiet = 0;
        echo 'id: ' . $ver . "\n";
        $emit('seats', ['ver' => $ver, 'scheduleId' => $scheduleId]);
        continue;
    }
    if (++$quiet >= 15) {
        $quiet = 0;
        echo ": ping\n\n";
        flush();
    }
}

$emit('bye', []);
