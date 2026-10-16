<?php
/**
 * POST /api/search.php  — find buses for a route + date.
 * Body: { from, to, date }
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    $from = Security::clean(Response::field('from', ''), 80);
    $to   = Security::clean(Response::field('to', ''), 80);
    $date = Security::clean(Response::field('date', ''), 10);

    if ($from === '' || $to === '') {
        Response::invalid(['from' => 'Choose your route.']);
    }
    if (!Security::isValidDate($date)) {
        Response::invalid(['date' => 'Choose a valid travel date.']);
    }
    if ($date < todayISO()) {
        Response::invalid(['date' => 'Travel date cannot be in the past.']);
    }

    Security::requireRateLimit('search', Security::clientIp(), 60, 60);

    $routes = Database::fetchAll(
        'SELECT r.*, b.bus_name AS bus, b.bus_number, b.amenities AS bus_amenities
           FROM routes r
           LEFT JOIN buses b ON b.id = r.bus_id
          WHERE r.is_active = 1
            AND LOWER(r.from_city) = LOWER(:from)
            AND LOWER(r.to_city) = LOWER(:to)
          ORDER BY r.sort_order, r.dep_time',
        ['from' => $from, 'to' => $to]
    );

    $results = [];

    foreach ($routes as $route) {
        $routeId  = (int) $route['id'];
        $schedule = Seats::schedule($routeId, $date);

        $availability = Seats::availability($routeId, $date, 'sharing');

        $stops = Database::fetchAll(
            'SELECT stop_type, stop_name, landmark, stop_time, latitude, longitude, is_border, is_meal_halt
               FROM route_stops WHERE route_id = :r ORDER BY stop_type, sort_order',
            ['r' => $routeId]
        );

        $boarding = array_values(array_filter($stops, static fn(array $s): bool => $s['stop_type'] === 'boarding'));
        $drop     = array_values(array_filter($stops, static fn(array $s): bool => $s['stop_type'] === 'drop'));

        $results[] = [
            'routeId'      => $routeId,
            'routeCode'    => $route['route_code'],
            'scheduleId'   => (int) $schedule['id'],
            'from'         => $route['from_city'],
            'to'           => $route['to_city'],
            'busName'      => $route['bus'] ?? $route['bus_name'] ?? '',
            'busNumber'    => $route['bus_number'] ?? '',
            'coachType'    => $route['coach_type'],
            'depTime'      => substr((string) $route['dep_time'], 0, 5),
            'arrTime'      => substr((string) $route['arr_time'], 0, 5),
            'duration'     => $route['duration_text'],
            'dayOffset'    => (int) $route['day_offset'],
            'fare'         => (float) $route['base_fare'],
            'amenities'    => jsonColumn($route['amenities']),
            'crewName'     => $route['crew_name'],
            'seatsLeft'    => $availability['availableCount'],
            'totalSeats'   => $availability['total'],
            'boarding'     => $boarding,
            'drop'         => $drop,
            'status'       => $schedule['status'],
            'delayMinutes' => (int) $schedule['delay_minutes'],
        ];
    }

    Response::success([
        'date'    => $date,
        'from'    => $from,
        'to'      => $to,
        'count'   => count($results),
        'results' => $results,
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
