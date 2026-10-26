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
require_once INCLUDE_PATH . '/sitefacts.php';

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
                COALESCE(s.dep_time_override, r.dep_time) AS dep_time, r.dep_time AS route_dep,
                r.arr_time, r.duration_text, r.day_offset, r.coach_type, r.base_fare, r.distance_km
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
            'SELECT id, route_id, stop_type, stop_name, landmark, stop_time, is_border, is_meal_halt, latitude, longitude, '
              . (Boarding::hasStopControls() ? 'fare' : 'NULL AS fare') . ', ' . Boarding::kmSql() . '
               FROM route_stops
              WHERE route_id IN (' . implode(',', $ph) . ')' . Boarding::activeSql() . '
              ORDER BY route_id, stop_type, sort_order, id',
            $pp
        ) as $s) {
            $stopsByRoute[(int) $s['route_id']][] = $s;
        }
    }

    /* En-route halts (9 Oct 2026): one query for every route, [] before the
       route-details migration. */
    $haltsByRoute = Boarding::haltsForRoutes(array_map(static fn(array $r): int => (int) $r['id'], $routes));

    foreach ($routes as $route) {
        $routeId = (int) $route['id'];

        $stops = $stopsByRoute[$routeId] ?? [];

        $board = [];
        $drop  = [];
        $lineIn = [];   // the same rows, for Boarding::journeyLine()

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
                // The pickup's own per-seat sharing fare set in Admin -> Routes
                // (1 Oct 2026); null = the direction default.
                'fare'     => $s['fare'] !== null && (float) $s['fare'] > 0 ? (float) $s['fare'] : null,
                // Road km from the route's origin (9 Oct 2026, Admin -> Routes
                // or "Measure road km"); null = not measured, never guessed.
                'km'       => $s['km_from_origin'] !== null ? round((float) $s['km_from_origin'], 1) : null,
            ];

            if ($s['stop_type'] === 'boarding') {
                // Flagged, not removed: the passenger still sees the whole
                // run, but knows which pickups today's bus has already made.
                $row['open'] = Boarding::isOpen($routeId, $date, (string) $s['stop_name']);
                $board[] = $row;
            } else {
                $drop[] = $row;
            }
            $lineIn[] = [
                'id' => (int) $s['id'], 'type' => $s['stop_type'] === 'boarding' ? 'boarding' : 'drop',
                'name' => (string) $s['stop_name'], 'landmark' => $row['landmark'], 'time' => $s['stop_time'],
                'km' => $row['km'], 'isBorder' => $row['isBorder'], 'lat' => $row['lat'], 'lng' => $row['lng'],
                'open' => $row['open'] ?? null,
            ];
        }

        /* The run in travel order — pickups, halts, border — with km and
           times (9 Oct 2026): what the home route rail and the timetable
           box draw. Stop times are the timetable clock (routes.dep_time),
           halts are offsets from it. */
        $halts   = $haltsByRoute[$routeId] ?? [];
        $line    = Boarding::journeyLine($lineIn, $halts, (string) $route['route_dep']);
        $summary = Boarding::journeySummary($line, ['dep_time' => $route['route_dep']] + $route);
        $confirmed = (bool) $summary['durationConfirmed'];
        $lastStop  = null;
        foreach ($line as $li => $it) {
            if ($it['kind'] === 'stop') { $lastStop = $li; }
        }
        // The arrival clock only once the office has confirmed it (duration_text set).
        if ($confirmed && $lastStop !== null && $line[$lastStop]['role'] === 'drop' && $line[$lastStop]['time'] === '') {
            $line[$lastStop]['time']    = substr((string) $route['arr_time'], 0, 5);
            $line[$lastStop]['dayPlus'] = (int) $route['day_offset'];
            $line[$lastStop]['offMin']  = $summary['durationMin'];
        }
        $lineOut = array_map(static fn(array $it): array => [
            'kind'     => $it['kind'],
            'role'     => $it['role'],
            'name'     => $it['name'],
            'code'     => $it['code'],
            'landmark' => $it['landmark'],
            'time'     => $it['time'],
            'dayPlus'  => $it['dayPlus'],
            'km'       => $it['km'],
            'border'   => $it['border'],
            'halt'     => $it['halt'],
            'haltMin'  => $it['haltMin'],
            'approx'   => $it['approx'],
            'note'     => $it['note'],
            'open'     => $it['open'],
        ], $line);
        $haltsOut = [];
        foreach ($line as $it) {
            if ($it['kind'] !== 'halt') { continue; }
            $haltsOut[] = [
                'name'     => $it['name'],
                'kind'     => $it['halt'],
                'time'     => $it['time'],
                'timeText' => $it['time'],
                'dayPlus'  => $it['dayPlus'],
                'haltMin'  => $it['haltMin'],
                'km'       => $it['km'],
                'approx'   => $it['approx'],
                'landmark' => $it['landmark'],
                'note'     => $it['note'],
            ];
        }
        $dm = $summary['durationMin'];

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
            /* Route facts (9 Oct 2026) — additive; every key above is unchanged.
               distanceKm: road km of the furthest stop, else routes.distance_km.
               durationMin is the timetable's dep -> arr + day_offset, dropped
               when implausible for the distance; durationApprox stays true
               until the office confirms the arrival (duration_text), and the
               arrival CLOCK (arrTime) stays hidden until then. */
            'distanceKm'     => $summary['distanceKm'],
            /* D9 (15 Oct 2026): the distance the page PRINTS is the one
               corridor figure; distanceKm stays the measured scale the rail
               positions its ticks on. */
            'displayKm'      => SiteFacts::routeKm(),
            'distanceSource' => $summary['distanceSource'],
            'spans'          => $summary['spans'],
            'stopCount'      => $summary['stopCount'],
            'haltCount'      => $summary['haltCount'],
            'durationMin'    => $dm,
            'durationText'   => $dm !== null ? intdiv($dm, 60) . 'h' . ($dm % 60 > 0 ? ' ' . sprintf('%02d', $dm % 60) . 'm' : '') : '',
            'durationApprox' => !$confirmed,
            'halts'          => $haltsOut,
            'line'           => $lineOut,
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
