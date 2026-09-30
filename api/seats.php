<?php
/**
 * GET/POST /api/seats.php — seat availability for a route + date.
 * Body: { routeId | routeCode, date, bookingMode? }
 *
 * The booking app addresses buses by their route_code ('r1', 'r2', …) while
 * the database keys them by numeric id, so either identifier is accepted.
 * Without routeCode the app would have to call /search.php first just to
 * translate the id, and every seat map would cost two round-trips.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    $routeId     = (int) Response::field('routeId', 0);
    $routeCode   = Security::clean((string) Response::field('routeCode', ''), 20);
    $date        = Security::clean(Response::field('date', ''), 10);
    $bookingMode = Response::field('bookingMode', 'sharing');
    $bookingMode = in_array($bookingMode, ['sharing', 'private'], true) ? $bookingMode : 'sharing';
    // An extra bus on the same date is addressed by its own schedule id
    // (Bus Calendar, 5 Sep 2026); absent/0 = the daily bus, exactly as before.
    $scheduleId  = (int) Response::field('scheduleId', 0);
    if ($scheduleId > 0) {
        $srow = Database::fetch('SELECT route_id, travel_date FROM schedules WHERE id = :id', ['id' => $scheduleId]);
        if ($srow === null) {
            Response::invalid(['scheduleId' => 'That departure no longer exists.']);
        }
        if ($routeId <= 0 && $routeCode === '') { $routeId = (int) $srow['route_id']; }
        if ($date === '') { $date = (string) $srow['travel_date']; }
    }

    if ($routeId <= 0 && $routeCode !== '') {
        $routeId = (int) Database::scalar(
            'SELECT id FROM routes WHERE route_code = :c AND is_active = 1 LIMIT 1',
            ['c' => $routeCode],
            0
        );
    }

    if ($routeId <= 0) {
        Response::invalid(['routeId' => 'Choose a bus first.']);
    }
    if (!Security::isValidDate($date)) {
        Response::invalid(['date' => 'Choose a valid travel date.']);
    }

    /* A client that already holds this departure's seat version (from its
       last snapshot or the live stream) gets a 200-byte answer instead of
       the whole map when nothing changed — the 8 s poll used to re-send
       ~4 KB per open seat map whether or not anything had moved. */
    require_once INCLUDE_PATH . '/seatversion.php';
    $verIn = Security::clean((string) Response::field('ver', ''), 40);
    if ($verIn !== '') {
        $sidFor = $scheduleId > 0 ? $scheduleId : (int) (Seats::schedule($routeId, $date)['id'] ?? 0);
        if ($sidFor > 0 && SeatVersion::of($sidFor) === $verIn) {
            Response::success(['unchanged' => true, 'ver' => $verIn, 'scheduleId' => $sidFor]);
        }
    }

    $availability = Seats::availability($routeId, $date, $bookingMode, $scheduleId > 0 ? $scheduleId : null);

    Response::success([
        'ver'            => SeatVersion::of((int) $availability['scheduleId']),
        'routeId'        => $routeId,
        'routeCode'      => $routeCode,
        'date'           => $date,
        'scheduleId'     => $availability['scheduleId'],
        'allSeats'       => $availability['all'],
        'booked'         => $availability['booked'],
        'locked'         => $availability['locked'],
        'blocked'        => $availability['blocked'],
        'staff'          => $availability['staff'],
        // Mode-aware emergency berth(s) held back for this booking mode
        // (Private Sleeper -> L3). Empty for sharing/seater.
        'emergency'      => $availability['emergency'],
        'crossMode'      => $availability['crossMode'] ?? [],
        'available'      => $availability['available'],
        'female'         => $availability['female'],
        'units'          => $availability['units'],
        'availableCount' => $availability['availableCount'],
        // Physical seat geometry — decks / rows / left+aisle+right. Clients
        // that don't know about this field will simply ignore it (added
        // 29 Aug 2026, layout-unification round 1).
        'layout'         => $availability['layout'],
        'holdMinutes'    => Settings::getInt('seat_hold_minutes', SEAT_HOLD_MINUTES),
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
