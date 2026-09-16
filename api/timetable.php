<?php
/**
 * GET /api/timetable.php — the public daily timetable.
 *
 * One place a passenger can read the whole run: every pickup and drop
 * with its time, in order, for each active route. Built from the SAME
 * `route_stops` rows that Boarding uses to decide what is still
 * sellable, so the board a passenger reads and the cut-off the checkout
 * enforces can never drift apart.
 *
 * Query: ?date=YYYY-MM-DD (optional, defaults to today) — used only to
 * mark which pickups are still catchable, never to filter the board. A
 * timetable that hid this morning's stop would stop being a timetable.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    $date = Security::clean($_GET['date'] ?? '', 10);
    if ($date === '' || !Security::isValidDate($date)) {
        $date = todayISO();
    }

    /* Effective dep_time follows a per-date schedule override when one
       exists: a delay-workflow reschedule on that travel_date replaces
       the printed timetable time so the public board matches what the
       messaging cron and admin dashboard already show. LEFT JOIN because
       a route may have no materialised schedule row for this date yet —
       we still want the timetable to render its default dep_time then.
       Blocked schedules are hidden from the public board just like
       api/search.php hides them from search results. */
    $routes = Database::fetchAll(
        'SELECT r.id, r.route_code, r.from_city, r.to_city,
                COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                r.arr_time, r.duration_text, r.day_offset, r.coach_type, r.base_fare
           FROM routes r
           LEFT JOIN schedules s ON s.route_id = r.id AND s.travel_date = :d AND s.slot = 1
          WHERE r.is_active = 1
            AND COALESCE(s.is_blocked, 0) = 0
          ORDER BY COALESCE(s.dep_time_override, r.dep_time) ASC, r.id ASC',
        ['d' => $date]
    );

    $out = [];

    /* One query for every route's stops (5 Sep 2026; was one per route),
       grouped in PHP. Names of placeholders must be unique (real prepares). */
    $stopsByRoute = [];
    if ($routes !== []) {
        $ph = []; $pp = [];
        foreach ($routes as $i => $r) { $ph[] = ':r' . $i; $pp['r' . $i] = (int) $r['id']; }
        foreach (Database::fetchAll(
            'SELECT route_id, stop_type, stop_name, landmark, stop_time, is_border, is_meal_halt, latitude, longitude
               FROM route_stops
              WHERE route_id IN (' . implode(',', $ph) . ')
              ORDER BY route_id, stop_type, sort_order',
            $pp
        ) as $s) {
            $stopsByRoute[(int) $s['route_id']][] = $s;
        }
    }

    foreach ($routes as $route) {
        $routeId = (int) $route['id'];

        $stops = $stopsByRoute[$routeId] ?? [];

        $board = [];
        $drop  = [];

        foreach ($stops as $s) {
            $row = [
                'name'     => (string) $s['stop_name'],
                'landmark' => $s['landmark'] !== null ? (string) $s['landmark'] : '',
                'time'     => $s['stop_time'] !== null ? substr((string) $s['stop_time'], 0, 5) : '',
                'isBorder' => (int) $s['is_border'] === 1,
                'isMeal'   => (int) $s['is_meal_halt'] === 1,
                // 4 Sep 2026: the map draws the run from these — the same
                // rows the timetable and the boarding cut-off already use,
                // instead of a hand-typed copy in the JS. Null when unknown.
                'lat'      => $s['latitude']  !== null ? (float) $s['latitude']  : null,
                'lng'      => $s['longitude'] !== null ? (float) $s['longitude'] : null,
            ];

            if ($s['stop_type'] === 'boarding') {
                // Flagged, not removed: the passenger still sees the whole
                // run, but knows which pickups today's bus has already made.
                $row['open'] = Boarding::isOpen($routeId, $date, (string) $s['stop_name']);
                $board[] = $row;
            } else {
                $drop[] = $row;
            }
        }

        $out[] = [
            'routeId'   => $routeId,
            'routeCode' => (string) $route['route_code'],
            'from'      => (string) $route['from_city'],
            'to'        => (string) $route['to_city'],
            'depTime'   => substr((string) $route['dep_time'], 0, 5),
            // A blanked duration_text means "arrival not known yet" (the old
            // Nepalgunj-leg clock was wrong for Rupaidiha) — never promise one.
            'arrTime'   => (string) ($route['duration_text'] ?? '') === '' ? '' : substr((string) $route['arr_time'], 0, 5),
            'duration'  => (string) ($route['duration_text'] ?? ''),
            'dayOffset' => (string) ($route['duration_text'] ?? '') === '' ? 0 : (int) $route['day_offset'],
            'coachType' => (string) $route['coach_type'],
            'fare'      => (float) $route['base_fare'],
            'sellable'  => Boarding::routeSellable($routeId, $date),
            'boarding'  => $board,
            'drop'      => $drop,
        ];
    }

    // Public, date-keyed, changes only when stops / schedules change: let the
    // browser and any proxy keep it for a minute (every other endpoint stays
    // no-store — this is the one opt-in, see Response::$publicCacheSeconds).
    Response::$publicCacheSeconds = 60;
    Response::success([
        'date'          => $date,
        // Master switch (4 Sep 2026): the board still lists the run, but the
        // app can say the service is paused instead of promising a departure.
        'serviceOff'    => !Settings::getBool('daily_service_on', true),
        'note'          => Settings::getString('daily_service_note', ''),
        'cutoffMinutes' => Boarding::cutoffMinutes(),
        'routes'        => $out,
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
