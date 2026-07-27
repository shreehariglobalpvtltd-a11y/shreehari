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

    $routeId = (int) Response::field('routeId', 0);
    $date    = Security::clean(Response::field('date', ''), 10);
    $seats   = Response::field('seats', []);
    $seats   = is_array($seats) ? array_map('strval', $seats) : [];

    if ($routeId <= 0 || !Security::isValidDate($date)) {
        Response::invalid(['routeId' => 'Select a bus and date first.']);
    }
    if ($seats === []) {
        Response::invalid(['seats' => 'No seats specified.']);
    }

    Security::requireRateLimit('seat_lock', Security::clientIp(), 120, 60);

    $schedule   = Seats::schedule($routeId, $date);
    $scheduleId = (int) $schedule['id'];

    if ($action === 'release') {
        $count = Seats::release($scheduleId, $seats, $token);
        Response::success(['released' => $count]);
    }

    // Default: hold
    $result = Seats::lock($scheduleId, $seats, $token);

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
