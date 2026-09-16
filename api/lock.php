<?php
/**
 * POST /api/lock.php — hold or release seats for this visitor.
 * Body: { action:'hold'|'release'|'releaseAll', routeId, date, seats[] }
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    $action  = Security::clean(Response::field('action', 'hold'), 20);
    $token   = Auth::lockToken();

    if ($action === 'releaseAll') {
        $count = Seats::releaseAll($token);
        Response::success(['released' => $count]);
    }

    $routeId   = (int) Response::field('routeId', 0);
    $routeCode = Security::clean((string) Response::field('routeCode', ''), 20);
    $date      = Security::clean(Response::field('date', ''), 10);
    $seats     = Response::field('seats', []);
    $seats     = is_array($seats) ? array_map('strval', $seats) : [];
    // Booking mode lets the hold reason in physical space (Task 4): a private
    // cabin blocks the sharing beds it occupies and vice-versa. Optional — an
    // absent/unknown value falls back to sharing (identity), unchanged behaviour.
    $bookingMode = Response::field('bookingMode', null);
    $bookingMode = in_array($bookingMode, ['sharing', 'private'], true) ? $bookingMode : null;

    /* The booking app addresses buses by route_code ('r1', 'r2', …) while the
       database keys them by numeric id — same reason /api/seats.php accepts
       both. Without this the seat map could read availability but never hold
       a seat, because it has no numeric id to send. */
    if ($routeId <= 0 && $routeCode !== '') {
        $routeId = (int) Database::scalar(
            'SELECT id FROM routes WHERE route_code = :c AND is_active = 1 LIMIT 1',
            ['c' => $routeCode],
            0
        );
    }

    if ($routeId <= 0 || !Security::isValidDate($date)) {
        Response::invalid(['routeId' => 'Select a bus and date first.']);
    }
    if ($seats === []) {
        Response::invalid(['seats' => 'No seats specified.']);
    }

    Security::requireRateLimit('seat_lock', Security::clientIp(), 120, 60);

    // Extra bus on the same date by its own id (Bus Calendar, 5 Sep 2026);
    // must belong to this route + date. 0 = the daily bus, as before.
    $reqSid     = (int) Response::field('scheduleId', 0);
    $schedule   = Seats::scheduleFor($routeId, $date, $reqSid > 0 ? $reqSid : null);
    $scheduleId = (int) $schedule['id'];

    if ($action === 'release') {
        // The mode says which physical beds a label stands for (private L4 =
        // beds L7 + L8); holds are stored as beds since 4 Sep 2026.
        $count = Seats::release($scheduleId, $seats, $token, $bookingMode);
        Response::success(['released' => $count]);
    }

    // Default: hold
    $result = Seats::lock($scheduleId, $seats, $token, false, $bookingMode);

    if (!$result['ok']) {
        Response::json([
            'ok'      => false,
            'error'   => 'Some seats were just taken: ' . implode(', ', $result['failed']),
            'code'    => 409,
            'data'    => $result,
        ], 409);
    }

    Response::success($result);
} catch (Throwable $e) {
    Response::serverError($e);
}
