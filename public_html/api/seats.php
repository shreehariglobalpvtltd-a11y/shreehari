<?php
/**
 * GET/POST /api/seats.php — seat availability for a route + date.
 * Body: { routeId, date, bookingMode? }
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    $routeId     = (int) Response::field('routeId', 0);
    $date        = Security::clean(Response::field('date', ''), 10);
    $bookingMode = Response::field('bookingMode', 'sharing');
    $bookingMode = in_array($bookingMode, ['sharing', 'private'], true) ? $bookingMode : 'sharing';

    if ($routeId <= 0) {
        Response::invalid(['routeId' => 'Choose a bus first.']);
    }
    if (!Security::isValidDate($date)) {
        Response::invalid(['date' => 'Choose a valid travel date.']);
    }

    $availability = Seats::availability($routeId, $date, $bookingMode);

    Response::success([
        'routeId'        => $routeId,
        'date'           => $date,
        'scheduleId'     => $availability['scheduleId'],
        'allSeats'       => $availability['all'],
        'booked'         => $availability['booked'],
        'locked'         => $availability['locked'],
        'available'      => $availability['available'],
        'female'         => $availability['female'],
        'availableCount' => $availability['availableCount'],
        'holdMinutes'    => Settings::getInt('seat_hold_minutes', SEAT_HOLD_MINUTES),
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
