<?php
/**
 * admin/routes.php — routes overview with live seat availability.
 *
 * Route content (stops, timings, fares) is managed from the in-app admin
 * console; this page is the server-side operational view: which routes
 * are active and how full the next few departures are.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/schedulemaker.php';
$admin = admin_boot('routes.view');

$base  = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$flash = null;
$kmSuggest = null;   // set by measure_km: the route-facts distance suggestion (16 Nov 2026, P4-3c)

/* ---------------------------------------------------------------------
 *  Operational setup - "one daily coach each way".
 *
 *  The seeded demo fleet leaves ~13 routes active, so the app offers buses
 *  the company does not run. This puts the real operation in place in one
 *  click: ONE sleeper out, ONE sleeper back, the five permanent Gujarat
 *  stops with their fixed times, and Rupaidiha as the only Nepal-side
 *  point (the onward legs are not licensed yet).
 *
 *  It lives behind the admin login and the CSRF token rather than in a
 *  secret URL, it only flips `is_active` and rewrites `route_stops` for
 *  the two chosen routes, and it is one transaction - so no booking,
 *  ticket, payment or seat can be lost, and it is reversible by switching
 *  a route active again.
 * ------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'daily_setup') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');

            // Pick the real coaches rather than trusting ids: the daily
            // service is a SLEEPER (72 berths); a seater carries only 40.
            $outR = Database::fetch(
                "SELECT id FROM routes WHERE coach_type='sleeper'
                   AND LOWER(to_city) IN ('rupaidiha','nepalgunj')
                   AND LOWER(from_city) NOT IN ('rupaidiha','nepalgunj')
                 ORDER BY id LIMIT 1"
            );
            $retR = Database::fetch(
                "SELECT id FROM routes WHERE coach_type='sleeper'
                   AND LOWER(from_city) IN ('rupaidiha','nepalgunj')
                   AND LOWER(to_city) NOT IN ('rupaidiha','nepalgunj')
                 ORDER BY id LIMIT 1"
            );
            if ($outR === null || $retR === null) {
                throw new RuntimeException('Could not find one sleeper route in each direction - nothing changed.');
            }

            $OUT = (int) $outR['id'];
            $RET = (int) $retR['id'];
            $stops = [
                // Owner's timetable poster, 19 Sep 2026 (Mehsana is no longer a pickup).
                ['Surat',                              'Bus stand / designated point', '13:00:00', 21.1702, 72.8311, 1],
                ['Kamrej',                             'Shiv Shakti Hotel',            '13:30:00', null,    null,    2],
                ['Ankleshwar',                         'Ada Bridge',                   '15:00:00', null,    null,    3],
                ['Bharuch',                            'Somnath Mahadev Mandir',       '16:00:00', null,    null,    4],
                ['Vadodara',                           'Golden Chokdi',                '17:00:00', 22.3072, 73.1812, 5],
                ['Anand',                              'Pipal Chautra',                '18:30:00', null,    null,    6],
                ['Nadiad',                             'Nadiad Bridge – under bridge', '20:00:00', null,    null,    7],
                ['Emli Bhupal',                        'Taj Hotel',                    '21:00:00', null,    null,    8],
                ['S Hari Parking, Nana Chiloda (Amd)', null,                           '23:00:00', 23.1710, 72.6230, 9],
            ];

            // 16 Nov 2026: keep which routes were ON, so the audit row says what to undo.
            $wasOn = array_map('intval', array_column(Database::fetchAll('SELECT id FROM routes WHERE is_active = 1 ORDER BY id'), 'id'));
            Database::transaction(static function () use ($OUT, $RET, $stops): void {
                Database::query('UPDATE routes SET is_active = 0 WHERE id NOT IN (:a, :b)', ['a' => $OUT, 'b' => $RET]);
                Database::query('UPDATE routes SET is_active = 1 WHERE id IN (:c, :d)',     ['c' => $OUT, 'd' => $RET]);
                /* 9 Oct 2026: duration_text is no longer blanked here. It is the
                   office's "show the arrival to customers" switch (Route facts
                   below); this button used to wipe a confirmed arrival on every press. */
                Database::query("UPDATE routes SET from_city='Surat', to_city='Rupaidiha', dep_time='13:00:00' WHERE id=:i", ['i' => $OUT]);
                Database::query("UPDATE routes SET from_city='Rupaidiha', to_city='Surat', dep_time='18:00:00' WHERE id=:i", ['i' => $RET]);

                /* 1 Oct 2026: stops are SEEDED here only when a route has none of
                   that type. This button used to DELETE every pickup and
                   re-insert a typed list — which would wipe Rajkot, every
                   per-pickup fare and every ON/OFF the office set in the stop
                   editor below. Existing stops are now left exactly as they are. */
                $none = static fn(int $rid, string $type): bool => (int) Database::scalar(
                    'SELECT COUNT(*) FROM route_stops WHERE route_id = :i AND stop_type = :t',
                    ['i' => $rid, 't' => $type], 0
                ) === 0;
                if ($none($OUT, 'boarding')) {
                    foreach ($stops as $st) {
                        Database::insert('route_stops', [
                            'route_id' => $OUT, 'stop_type' => 'boarding', 'stop_name' => $st[0],
                            'landmark' => $st[1], 'stop_time' => $st[2], 'latitude' => $st[3], 'longitude' => $st[4],
                            'is_border' => 0, 'is_meal_halt' => 0, 'sort_order' => $st[5],
                        ]);
                    }
                }
                if ($none($OUT, 'drop')) {
                    Database::insert('route_stops', [
                        'route_id' => $OUT, 'stop_type' => 'drop', 'stop_name' => 'Rupaidiha',
                        'landmark' => 'India-Nepal border checkpoint', 'stop_time' => null,
                        'latitude' => 28.0600, 'longitude' => 81.6170, 'is_border' => 1, 'is_meal_halt' => 0, 'sort_order' => 1,
                    ]);
                }
                if ($none($RET, 'boarding')) {
                    Database::insert('route_stops', [
                        'route_id' => $RET, 'stop_type' => 'boarding', 'stop_name' => 'Rupaidiha',
                        'landmark' => 'India-Nepal border checkpoint', 'stop_time' => '18:00:00',
                        'latitude' => 28.0600, 'longitude' => 81.6170, 'is_border' => 1, 'is_meal_halt' => 0, 'sort_order' => 1,
                    ]);
                }
                if ($none($RET, 'drop')) {
                    // Owner, 1 Oct 2026: the return trip drops at "Gujarat" only.
                    Database::insert('route_stops', [
                        'route_id' => $RET, 'stop_type' => 'drop', 'stop_name' => 'Gujarat',
                        'landmark' => null, 'stop_time' => null, 'latitude' => null, 'longitude' => null,
                        'is_border' => 0, 'is_meal_halt' => 0, 'sort_order' => 1,
                    ]);
                }
            });
            Boarding::flush();

            $wentOff = array_values(array_diff($wasOn, [$OUT, $RET]));
            Logger::audit('routes.daily_setup', 'routes', (string) $OUT, ['active' => $wasOn],
                ['outbound' => $OUT, 'return' => $RET, 'switched_off' => $wentOff], 'Daily service applied from Admin - Routes');
            $offCodes = $wentOff === [] ? [] : array_column(Database::fetchAll(
                'SELECT route_code FROM routes WHERE id IN (' . implode(',', array_map('intval', $wentOff)) . ') ORDER BY id'), 'route_code');
            $flash = ['ok', 'Daily service set: one sleeper out (13:00) and one back (18:00). Existing pickup points, fares and ON/OFF were kept.'
                . ($offCodes !== [] ? ' Switched OFF: ' . implode(', ', $offCodes) . ' (Activate any of them below to undo).' : '')];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---------------------------------------------------------------------
 *  Per-route on/off. "One daily bus" is today's operation, not a cage:
 *  the owner adds a coach back (or retires one) with one click, without
 *  waiting for a developer. Flips is_active only — nothing is deleted.
 * ------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'toggle_route') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            $rid = (int) ($_POST['route_id'] ?? 0);
            $row = Database::fetch('SELECT id, route_code, from_city, to_city, is_active FROM routes WHERE id = :i', ['i' => $rid]);
            if ($row === null) {
                throw new RuntimeException('That route no longer exists.');
            }
            $to = ((int) $row['is_active'] === 1) ? 0 : 1;
            Database::update('routes', ['is_active' => $to], 'id = :i', ['i' => $rid]);
            Logger::audit('routes.toggled', 'routes', (string) $rid,
                ['is_active' => (int) $row['is_active']], ['is_active' => $to],
                $row['route_code'] . ' ' . $row['from_city'] . ' → ' . $row['to_city']);
            $flash = ['ok', $row['route_code'] . ' (' . $row['from_city'] . ' → ' . $row['to_city'] . ') is now ' . ($to === 1 ? 'ACTIVE and bookable' : 'switched off') . '.'];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---------------------------------------------------------------------
 *  Inline default departure-time edit. The "Apply daily service" button
 *  above rewrites both routes AND their stop timings from a hardcoded
 *  block; that's the right hammer when the operation is being re-seeded,
 *  but heavy for a five-minute schedule change. This handler flips a
 *  single column on ONE route so the operator can nudge the default
 *  time without touching stops, fares or the sliding schedules window.
 *  CSRF-guarded, routes.edit-gated, audited with before/after.
 * ------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'update_dep_time') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            $rid = (int) ($_POST['route_id'] ?? 0);
            $new = trim((string) ($_POST['dep_time'] ?? ''));
            // Accept "HH:MM" or "HH:MM:SS"; normalise to HH:MM:SS for the column.
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $new) !== 1) {
                throw new RuntimeException('Enter a valid departure time as HH:MM.');
            }
            if (strlen($new) === 5) { $new .= ':00'; }
            $row = Database::fetch('SELECT id, route_code, from_city, to_city, dep_time FROM routes WHERE id = :i', ['i' => $rid]);
            if ($row === null) {
                throw new RuntimeException('That route no longer exists.');
            }
            $before = (string) ($row['dep_time'] ?? '');
            if ($before === $new) {
                $flash = ['ok', $row['route_code'] . ' departure time unchanged.'];
            } else {
                Database::update('routes', ['dep_time' => $new], 'id = :i', ['i' => $rid]);
                Logger::audit('route.dep_time_updated', 'routes', (string) $rid,
                    ['dep_time' => $before], ['dep_time' => $new],
                    $row['route_code'] . ' ' . $row['from_city'] . ' → ' . $row['to_city']);
                $flash = ['ok', $row['route_code'] . ' (' . $row['from_city'] . ' → ' . $row['to_city'] . ') default departure updated to ' . substr($new, 0, 5) . '.'];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---------------------------------------------------------------------
 *  Edit a route's identity + fare (3 Sep 2026, Module 2). Until now the
 *  panel could switch a route on/off and move its departure time, but
 *  from/to/coach/base_fare were seed-only. Same shape as update_dep_time:
 *  CSRF, routes.edit, audited before/after. Existing bookings/tickets are
 *  untouched - they carry their own copy of the fare and the cities.
 * ------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'update_route') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            $rid   = (int) ($_POST['route_id'] ?? 0);
            $fromC = trim(Security::clean((string) ($_POST['from_city'] ?? ''), 80));
            $toC   = trim(Security::clean((string) ($_POST['to_city'] ?? ''), 80));
            $coach = (string) ($_POST['coach_type'] ?? '');
            $fare  = (float) ($_POST['base_fare'] ?? -1);
            if ($fromC === '' || $toC === '') { throw new RuntimeException('Enter both the from and to city.'); }
            if (strcasecmp($fromC, $toC) === 0) { throw new RuntimeException('From and to cannot be the same city.'); }
            if (!in_array($coach, ['seater', 'sleeper'], true)) { throw new RuntimeException('Coach type must be seater or sleeper.'); }
            if ($fare < 0 || $fare > 100000) { throw new RuntimeException('Enter a base fare between 0 and 1,00,000.'); }
            $row = Database::fetch('SELECT id, route_code, from_city, to_city, coach_type, base_fare FROM routes WHERE id = :i', ['i' => $rid]);
            if ($row === null) { throw new RuntimeException('That route no longer exists.'); }
            $before = ['from_city' => (string) $row['from_city'], 'to_city' => (string) $row['to_city'], 'coach_type' => (string) $row['coach_type'], 'base_fare' => round((float) $row['base_fare'], 2)];
            $after  = ['from_city' => $fromC, 'to_city' => $toC, 'coach_type' => $coach, 'base_fare' => round($fare, 2)];
            if ($before == $after) {
                $flash = ['ok', $row['route_code'] . ' unchanged.'];
            } else {
                Database::update('routes', $after, 'id = :i', ['i' => $rid]);
                Logger::audit('route.updated', 'routes', (string) $rid, $before, $after, $row['route_code'] . ' ' . $fromC . ' -> ' . $toC);
                $flash = ['ok', $row['route_code'] . ' updated: ' . $fromC . ' → ' . $toC . ' · ' . ucfirst($coach) . ' · ' . inr($fare) . '.'
                    . ($before['coach_type'] !== $coach ? ' Coach type changed — check the seat map for dates that already have bookings.' : '')];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---------------------------------------------------------------------
 *  Route facts (9 Oct 2026, owner ask #11): distance, arrival clock, the
 *  arrival day and the "show arrival to customers" text. duration_text
 *  is what search / the timetable gate the arrival on — blank keeps the
 *  arrival hidden everywhere a customer looks. CSRF, routes.edit, audited.
 * ------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'update_route_facts') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            $rid = (int) ($_POST['route_id'] ?? 0);
            $row = Database::fetch('SELECT id, route_code, from_city, to_city, dep_time, arr_time, day_offset, duration_text, distance_km FROM routes WHERE id = :i', ['i' => $rid]);
            if ($row === null) { throw new RuntimeException('That route no longer exists.'); }
            $km = trim((string) ($_POST['distance_km'] ?? ''));
            if ($km !== '' && (!ctype_digit($km) || (int) $km < 1 || (int) $km > 9999)) {
                throw new RuntimeException('Enter the distance as whole km between 1 and 9999, or leave it blank.');
            }
            $arr = trim((string) ($_POST['arr_time'] ?? ''));
            if ($arr !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $arr) !== 1) {
                throw new RuntimeException('Enter the arrival time as HH:MM.');
            }
            if (strlen($arr) === 5) { $arr .= ':00'; }
            $day = (int) ($_POST['day_offset'] ?? 0);
            if ($day < 0 || $day > 3) { throw new RuntimeException('Arrival day must be the same day, +1, +2 or +3.'); }
            $show = !empty($_POST['show_arrival']);
            $dur  = trim(Security::clean((string) ($_POST['duration_text'] ?? ''), 40));
            $mins = $arr !== '' ? Boarding::durationMin((string) $row['dep_time'], $arr, $day) : null;
            if ($show) {
                if ($arr === '') { throw new RuntimeException('Enter the arrival time before showing it to customers.'); }
                if ($mins === null) { throw new RuntimeException('Departure ' . substr((string) $row['dep_time'], 0, 5) . ' to arrival ' . substr($arr, 0, 5) . ' (+' . $day . ' day) is not a plausible journey - check the arrival day.'); }
                if ($dur === '') { $dur = intdiv($mins, 60) . 'h' . ($mins % 60 ? ' ' . sprintf('%02d', $mins % 60) . 'm' : ''); }
            } else {
                $dur = '';   // hidden: the customer screens show no arrival clock
            }
            $before = ['distance_km' => $row['distance_km'] !== null ? (int) $row['distance_km'] : null,
                       'arr_time' => (string) ($row['arr_time'] ?? ''), 'day_offset' => (int) $row['day_offset'],
                       'duration_text' => (string) ($row['duration_text'] ?? '')];
            $after  = ['distance_km' => $km !== '' ? (int) $km : null,
                       'arr_time' => $arr !== '' ? $arr : (string) ($row['arr_time'] ?? ''), 'day_offset' => $day,
                       'duration_text' => $dur];
            if ($before == $after) {
                $flash = ['ok', $row['route_code'] . ' route facts unchanged.'];
            } else {
                $set = $after;
                if ($arr === '') { unset($set['arr_time']); }   // arr_time is NOT NULL on older schemas - keep the stored clock
                Database::update('routes', $set, 'id = :i', ['i' => $rid]);
                Boarding::flush();
                Logger::audit('route.facts_updated', 'routes', (string) $rid, $before, $after,
                    $row['route_code'] . ' ' . $row['from_city'] . ' -> ' . $row['to_city'] . ' route facts');
                $flash = ['ok', $row['route_code'] . ' route facts saved' . ($dur !== '' ? ' - arrival ' . substr($after['arr_time'], 0, 5) . ($day > 0 ? ' (+' . $day . ' day)' : '') . ' is now shown to customers.' : ' - the arrival stays hidden from customers.')];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---------------------------------------------------------------------
 *  En-route halts (9 Oct 2026, owner ask #11): tea / breakfast / lunch /
 *  dinner / fuel / rest / border stops that are not pickups. Their own
 *  table (route_halts) so search, the cut-off and the seat map never see
 *  them. Times are stored as minutes after departure (a retimed bus moves
 *  its halts) from the clock + journey day the office types. Never
 *  deleted: OFF hides one. CSRF, routes.edit, audited, Boarding::flush().
 * ------------------------------------------------------------------- */
$haltIn = static function (array $p, string $depTime): array {
    $name = trim(Security::clean((string) ($p['name'] ?? ''), 120));
    if ($name === '') { throw new RuntimeException('Enter the halt place (a corridor town, e.g. Kota).'); }
    $kind = (string) ($p['kind'] ?? 'rest');
    if (!in_array($kind, Boarding::HALT_KINDS, true)) { throw new RuntimeException('Pick what kind of halt this is.'); }
    $clock = trim((string) ($p['clock_time'] ?? ''));
    $day   = max(0, min(3, (int) ($p['day'] ?? 0)));
    $off   = null;
    if ($clock !== '') {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $clock) !== 1) { throw new RuntimeException('Enter the halt time as HH:MM.'); }
        $off = Boarding::haltOffset($clock, $day, $depTime);
        if ($off === null) { throw new RuntimeException('That halt time is before the departure (' . substr($depTime, 0, 5) . ') - pick the right journey day.'); }
        $clock = substr($clock, 0, 5) . ':00';
    }
    $hm = (int) ($p['halt_min'] ?? 15);
    if ($hm < 0 || $hm > 600) { throw new RuntimeException('Halt length must be 0 to 600 minutes.'); }
    $km = trim((string) ($p['km'] ?? ''));
    if ($km !== '' && (!is_numeric($km) || (float) $km < 0 || (float) $km > 9999)) { throw new RuntimeException('Enter the km from the route start (0 to 9999), or leave it blank.'); }
    return [
        'name' => $name, 'kind' => $kind, 'clock_time' => $clock !== '' ? $clock : null, 'offset_min' => $off,
        'halt_min' => $hm, 'km_from_origin' => $km !== '' ? round((float) $km, 1) : null,
        'landmark' => ($l = trim(Security::clean((string) ($p['landmark'] ?? ''), 191))) !== '' ? $l : null,
        'note' => ($n = trim(Security::clean((string) ($p['note'] ?? ''), 255))) !== '' ? $n : null,
        'is_approx' => !empty($p['is_approx']) ? 1 : 0,
    ];
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($_POST['action'] ?? '', ['add_halt', 'edit_halt', 'toggle_halt'], true)) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            if (!Boarding::hasRouteDetails()) {
                throw new RuntimeException('Run database/upgrade-2026-10-09-route-details.sql first - the halts table does not exist yet.');
            }
            $act = (string) $_POST['action'];
            if ($act === 'add_halt') {
                $rid   = (int) ($_POST['route_id'] ?? 0);
                $route = Database::fetch('SELECT id, route_code, dep_time FROM routes WHERE id = :r', ['r' => $rid]);
                if ($route === null) { throw new RuntimeException('Route not found.'); }
                $row = $haltIn($_POST, (string) $route['dep_time']);
                $row['route_id']   = $rid;
                $row['sort_order'] = (int) Database::scalar('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM route_halts WHERE route_id = :r', ['r' => $rid], 1);
                $row['is_active']  = 1;
                Database::insert('route_halts', $row);
                Logger::audit('route_halt.add', 'routes', (string) $rid, null, $row, 'Added ' . $row['kind'] . ' halt: ' . $row['name']);
                $flash = ['ok', 'Added halt: ' . $row['name'] . '.'];
            } else {
                $hid = (int) ($_POST['halt_id'] ?? 0);
                $old = Database::fetch('SELECT h.*, r.dep_time FROM route_halts h JOIN routes r ON r.id = h.route_id WHERE h.id = :h', ['h' => $hid]);
                if ($old === null) { throw new RuntimeException('Halt not found.'); }
                $keep = array_intersect_key($old, array_flip(['name', 'kind', 'clock_time', 'offset_min', 'halt_min', 'km_from_origin', 'landmark', 'note', 'is_approx', 'is_active']));
                if ($act === 'toggle_halt') {
                    $set = ['is_active' => (int) $old['is_active'] === 1 ? 0 : 1];
                    $msg = ($set['is_active'] === 1 ? 'ON: ' : 'OFF: ') . $old['name'] . ($set['is_active'] === 1 ? ' is shown again.' : ' is hidden from customers.');
                } else {
                    $set = $haltIn($_POST, (string) $old['dep_time']);
                    $msg = 'Halt updated: ' . $set['name'] . '.';
                }
                Database::update('route_halts', $set, 'id = :h', ['h' => $hid]);
                Logger::audit('route_halt.' . ($act === 'toggle_halt' ? 'toggle' : 'edit'), 'routes', (string) $old['route_id'],
                    array_intersect_key($keep, $set), $set, ($act === 'toggle_halt' ? 'Toggled' : 'Edited') . ' halt: ' . $old['name']);
                $flash = ['ok', $msg];
            }
            Boarding::flush();
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---------------------------------------------------------------------
 *  "Measure road km" (9 Oct 2026): asks OUR routing engine (Settings
 *  nav_engine_url, Valhalla, bus costing, <= 5 locations per call) for the
 *  road km of every stop that has a map pin and stores km_from_origin.
 *  On press only - never on a page view. Stops without coordinates are
 *  skipped and named; their typed km is left alone.
 * ------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'measure_km') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            if (!Boarding::hasRouteDetails()) {
                throw new RuntimeException('Run database/upgrade-2026-10-09-route-details.sql first - the km column does not exist yet.');
            }
            $rid = (int) ($_POST['route_id'] ?? 0);
            @set_time_limit(90);
            $m = Boarding::measureRoadKm($rid);
            if (!$m['ok']) { throw new RuntimeException($m['error']); }
            $before = [];
            foreach (Database::fetchAll('SELECT id, stop_name, km_from_origin FROM route_stops WHERE route_id = :r', ['r' => $rid]) as $s) {
                if (isset($m['rows'][(int) $s['id']])) {
                    $before[(string) $s['stop_name']] = $s['km_from_origin'] !== null ? (float) $s['km_from_origin'] : null;
                }
            }
            Database::transaction(static function () use ($m, $rid): void {
                foreach ($m['rows'] as $sid => $km) {
                    Database::update('route_stops', ['km_from_origin' => $km], 'id = :id AND route_id = :r', ['id' => (int) $sid, 'r' => $rid]);
                }
            });
            Boarding::flush();
            Logger::audit('route_stop.km_measured', 'routes', (string) $rid, $before, $m['names'],
                'Road km measured on the route engine (' . $m['calls'] . ' call' . ($m['calls'] === 1 ? '' : 's') . ', total ' . $m['totalKm'] . ' km)');
            /* 16 Nov 2026 (P4-3c): the measured end-to-end km is put INTO the
               route-facts form as a suggestion (with the engine's driving time
               and the stored road line, when there is one). Saving stays manual. */
            $roadKm = null;
            try {
                require_once INCLUDE_PATH . '/routegeo.php';
                $gi = RouteGeo::info($rid);
                $roadKm = $gi !== null && (int) $gi['length_m'] > 0 ? round((int) $gi['length_m'] / 1000, 1) : null;
            } catch (Throwable $e) {
                $roadKm = null;
            }
            $kmSuggest = ['rid' => $rid, 'km' => (int) round((float) $m['totalKm']), 'min' => $m['totalMin'] ?? null, 'road' => $roadKm];
            $flash = ['ok', 'Measured ' . count($m['names']) . ' stop' . (count($m['names']) === 1 ? '' : 's') . ' on the road engine: ' . number_format((float) $m['totalKm'], 0) . ' km end to end.'
                . ($m['skipped'] !== [] ? ' Skipped (no map position - add latitude/longitude, or type the km): ' . implode(', ', array_unique($m['skipped'])) . '.' : '')
                . ' The route distance box below now shows ' . number_format((float) $kmSuggest['km']) . ' km - press 💾 Save route facts to keep it.'];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---------------------------------------------------------------------
 *  Add a route (3 Sep 2026). Created SWITCHED OFF so nothing sells until
 *  the office adds its stops below and presses Activate; "Refill schedule
 *  rows" then seeds the 30-day window for it.
 * ------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'add_route') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            $code   = strtolower(trim((string) ($_POST['route_code'] ?? '')));
            $fromC  = trim(Security::clean((string) ($_POST['from_city'] ?? ''), 80));
            $toC    = trim(Security::clean((string) ($_POST['to_city'] ?? ''), 80));
            $coach  = (string) ($_POST['coach_type'] ?? 'sleeper');
            $dep    = trim((string) ($_POST['dep_time'] ?? ''));
            $arr    = trim((string) ($_POST['arr_time'] ?? ''));
            $fare   = (float) ($_POST['base_fare'] ?? -1);
            $dayOff = max(0, min(3, (int) ($_POST['day_offset'] ?? 1)));
            if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,19}$/', $code) !== 1) { throw new RuntimeException('Route code: letters, digits, - or _ only (e.g. r3).'); }
            if ($fromC === '' || $toC === '') { throw new RuntimeException('Enter both the from and to city.'); }
            if (strcasecmp($fromC, $toC) === 0) { throw new RuntimeException('From and to cannot be the same city.'); }
            if (!in_array($coach, ['seater', 'sleeper'], true)) { throw new RuntimeException('Coach type must be seater or sleeper.'); }
            foreach (['departure' => $dep, 'arrival' => $arr] as $lbl => $t) {
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t) !== 1) { throw new RuntimeException('Enter a valid ' . $lbl . ' time as HH:MM.'); }
            }
            if (strlen($dep) === 5) { $dep .= ':00'; }
            if (strlen($arr) === 5) { $arr .= ':00'; }
            if ($fare < 0 || $fare > 100000) { throw new RuntimeException('Enter a base fare between 0 and 1,00,000.'); }
            if (Database::exists('SELECT 1 FROM routes WHERE route_code = :c', ['c' => $code])) { throw new RuntimeException('Route code ' . $code . ' already exists.'); }
            $sort  = (int) Database::scalar('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM routes', [], 1);
            $newId = Database::insert('routes', [
                'route_code' => $code, 'from_city' => $fromC, 'to_city' => $toC, 'coach_type' => $coach,
                'dep_time' => $dep, 'arr_time' => $arr, 'day_offset' => $dayOff, 'base_fare' => round($fare, 2),
                'is_active' => 0, 'sort_order' => $sort,
            ]);
            Logger::audit('route.created', 'routes', (string) $newId, null,
                ['route_code' => $code, 'from_city' => $fromC, 'to_city' => $toC, 'coach_type' => $coach, 'base_fare' => $fare],
                $code . ' ' . $fromC . ' -> ' . $toC . ' (switched off)');
            $flash = ['ok', 'Route ' . $code . ' (' . $fromC . ' → ' . $toC . ') created and switched OFF. Add its stops below, then Activate and press "Refill schedule rows".'];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---------------------------------------------------------------------
 *  Refill the sliding 30-day schedules window on demand. The nightly
 *  cron (cron/daily-schedule.php) does this automatically, but the
 *  operator gets a one-click way to trigger it — useful right after
 *  activating a new route, or on a fresh VPS where the cron has not
 *  fired yet. INSERT IGNORE on UNIQUE(route_id, travel_date) means a
 *  double-click is a no-op; no booking, ticket or seat is touched.
 * ------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'refill_schedules') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            $r = ScheduleMaker::ensureNextDays(Database::pdo(), 30, 0);
            Logger::audit('schedules.refill', 'schedules', $r['from'] . '..' . $r['to'],
                null, $r, 'Refill triggered from Admin - Routes');
            $flash = ['ok', 'Refill done: created ' . (int) $r['created']
                . ' new schedule row' . ((int) $r['created'] === 1 ? '' : 's')
                . ' (skipped ' . (int) $r['skipped_existing'] . ' already there) across '
                . count($r['routes']) . ' route' . (count($r['routes']) === 1 ? '' : 's')
                . ' — window ' . $r['from'] . ' to ' . $r['to'] . '.'];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---------------------------------------------------------------------
 *  Stop management — add, edit, delete, reorder (Section D)
 *
 *  Stops are stored in `route_stops` with a sort_order integer.
 *  All mutations run inside transactions, validate time sequencing,
 *  and audit. These replace the "in-app admin console" note.
 * ------------------------------------------------------------------- */

/* Pickup fare / coordinates from a stop form (1 Oct 2026). Blank = NULL
   (the direction default from Admin -> Settings for a fare). */
$stopFareIn = static function (mixed $v): ?float {
    $v = Boarding::parseRupees(is_string($v) ? $v : (string) $v);   // 16 Nov 2026: "2,200" / "₹2200" read as 2200
    if ($v === null) {
        return null;
    }
    if ($v === false || $v < 1 || $v > 100000) {
        throw new RuntimeException('Enter the pickup fare as a rupee amount between 1 and 100000, or leave it blank for the default.');
    }
    return round($v, 2);
};
$stopCoordIn = static function (mixed $v, float $limit, string $what): ?float {
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    if (!is_numeric($v) || abs((float) $v) > $limit) {
        throw new RuntimeException('Enter a valid ' . $what . ' (decimal degrees), or leave it blank.');
    }
    return round((float) $v, 7);
};
/* Road km from the route's first stop (9 Oct 2026). Blank = not measured. */
$stopKmIn = static function (mixed $v): ?float {
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    if (!is_numeric($v) || (float) $v < 0 || (float) $v > 9999) {
        throw new RuntimeException('Enter the road km from the route start (0 to 9999), or leave it blank.');
    }
    return round((float) $v, 1);
};
/* 16 Nov 2026 (P4-2b2): a pickup an hour or more BEFORE the route's departure
   clock is read as after midnight on the same run (ticket time and booking
   cut-off). Say so when such a time is saved, so a pickup the bus really
   passes before it leaves is not sold for a day longer unnoticed. */
$nightHint = static function (?string $stopTime, ?string $depTime): string {
    $s = Boarding::hmToMin($stopTime);
    $d = Boarding::hmToMin($depTime);
    if ($s === null || $d === null || $s > $d - 60) {
        return '';
    }
    return ' ' . Boarding::minToHm($s) . ' is read as the NEXT day (after midnight) on this ' . Boarding::minToHm($d)
         . ' run - right for a night pickup; if the bus passes here BEFORE ' . Boarding::minToHm($d) . ', change the route\'s departure time instead.';
};

/* ── Pickup fares: one Save for a whole list (1 Oct 2026, owner ask) ──
   fare[<stop id>] => rupees, blank = the direction default. Only rows of the
   posted route + type are touched; the audit row carries every change. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_stop_fares') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            if (!Boarding::hasStopControls()) {
                throw new RuntimeException('Run database/upgrade-2026-10-01-stop-fares.sql first - the fare column does not exist yet.');
            }
            $rid      = (int) ($_POST['route_id'] ?? 0);
            $stopType = ($_POST['stop_type'] ?? '') === 'drop' ? 'drop' : 'boarding';
            $posted   = is_array($_POST['fare'] ?? null) ? $_POST['fare'] : [];
            /* 16 Nov 2026: fare_was[<id>] = the value this page was drawn with. A
               box the office did not touch is skipped, so a fare changed meanwhile
               (inline ₹ edit, Settings, another desk) is never put back. */
            $was      = is_array($_POST['fare_was'] ?? null) ? $_POST['fare_was'] : null;
            $rows     = Database::fetchAll(
                'SELECT id, stop_name, fare FROM route_stops WHERE route_id = :r AND stop_type = :t',
                ['r' => $rid, 't' => $stopType]
            );
            $before = $after = [];
            $plan   = [];
            foreach ($rows as $row) {
                $sid = (int) $row['id'];
                if (!array_key_exists((string) $sid, $posted) && !array_key_exists($sid, $posted)) {
                    continue;
                }
                if ($was !== null && array_key_exists($sid, $was)
                    && trim((string) ($posted[$sid] ?? '')) === trim((string) $was[$sid])) {
                    continue;
                }
                $new = $stopFareIn($posted[$sid] ?? $posted[(string) $sid] ?? '');
                $old = $row['fare'] !== null ? round((float) $row['fare'], 2) : null;
                if ($new === $old) {
                    continue;
                }
                $plan[$sid] = $new;
                $before[(string) $row['stop_name']] = $old;
                $after[(string) $row['stop_name']]  = $new;
            }
            if ($plan === []) {
                $flash = ['ok', 'No fare changed.'];
            } else {
                Database::transaction(static function () use ($plan): void {
                    foreach ($plan as $sid => $fare) {
                        Database::update('route_stops', ['fare' => $fare], 'id = :id', ['id' => $sid]);
                    }
                });
                Boarding::flush();
                Logger::audit('route_stop.fares', 'routes', (string) $rid, $before, $after,
                    'Pickup fares changed on route ' . $rid . ' (' . count($plan) . ' stop' . (count($plan) === 1 ? '' : 's') . ')');
                // 16 Nov 2026 (BUG1): name each row and its old -> new price.
                $said = [];
                foreach ($after as $nm => $v) {
                    $said[] = $nm . ' ' . ($before[$nm] === null ? 'default' : inr((float) $before[$nm])) . ' → ' . ($v === null ? 'default' : inr((float) $v));
                }
                $rcode = (string) Database::scalar('SELECT route_code FROM routes WHERE id = :r', ['r' => $rid], (string) $rid);
                $flash = ['ok', 'Saved ' . count($plan) . ' ' . ($stopType === 'drop' ? 'drop-point (ड्रप)' : 'pickup (पिकअप)') . ' fare' . (count($plan) === 1 ? '' : 's')
                    . ' on ' . $rcode . ': ' . implode(', ', $said) . '. New bookings are charged the new price; existing bookings keep theirs.'];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ── Pickup ON / OFF (1 Oct 2026, owner ask) ──
   OFF hides the stop from search, the pickers, the counter, QuickTicket and
   the AI, and create() refuses it; the row and every booking that names it
   stay. The last ON stop of a list cannot be switched off (the route would
   have nowhere to sell) — switch the route off instead. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'toggle_stop') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            if (!Boarding::hasStopControls()) {
                throw new RuntimeException('Run database/upgrade-2026-10-01-stop-fares.sql first - the ON/OFF column does not exist yet.');
            }
            $stopId = (int) ($_POST['stop_id'] ?? 0);
            $stop   = Database::fetch('SELECT * FROM route_stops WHERE id = :id', ['id' => $stopId]);
            if ($stop === null) { throw new RuntimeException('Stop not found.'); }
            $turnOn = (int) $stop['is_active'] !== 1;
            if (!$turnOn) {
                $onLeft = (int) Database::scalar(
                    'SELECT COUNT(*) FROM route_stops WHERE route_id = :r AND stop_type = :t AND is_active = 1 AND id <> :id',
                    ['r' => (int) $stop['route_id'], 't' => (string) $stop['stop_type'], 'id' => $stopId], 0
                );
                if ($onLeft === 0) {
                    throw new RuntimeException('"' . $stop['stop_name'] . '" is the last ' . $stop['stop_type'] . ' point switched ON on this route - keep at least one ON (switch the whole route off instead).');
                }
            }
            Database::update('route_stops', ['is_active' => $turnOn ? 1 : 0], 'id = :id', ['id' => $stopId]);
            Boarding::flush();
            Logger::audit('route_stop.toggle', 'routes', (string) $stop['route_id'],
                ['stop' => $stop['stop_name'], 'is_active' => (int) $stop['is_active']],
                ['stop' => $stop['stop_name'], 'is_active' => $turnOn ? 1 : 0],
                ($turnOn ? 'Switched ON stop: ' : 'Switched OFF stop: ') . $stop['stop_name']);
            $flash = ['ok', ($turnOn ? 'ON: ' : 'OFF: ') . $stop['stop_name'] . ($turnOn ? ' is on sale again.' : ' is no longer offered (existing bookings are unchanged).')];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ── Add a stop ── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'add_stop') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            $rid      = (int) ($_POST['route_id'] ?? 0);
            $stopType = in_array($_POST['stop_type'] ?? '', ['boarding', 'drop'], true) ? (string) $_POST['stop_type'] : 'boarding';
            $name     = Security::clean($_POST['stop_name'] ?? '', 191);
            $time     = trim((string) ($_POST['stop_time'] ?? ''));

            /* 16 Nov 2026 (C1): one writer for every "add a pickup / drop" door -
               Boarding::addStop validates, appends (MAX+1), flushes and audits. */
            Boarding::addStop([
                'route_id'     => $rid,
                'stop_type'    => $stopType,
                'stop_name'    => $name,
                'landmark'     => Security::clean($_POST['landmark'] ?? '', 191),
                'stop_time'    => $time,
                'fare'         => (string) ($_POST['fare'] ?? ''),
                'latitude'     => (string) ($_POST['latitude'] ?? ''),
                'longitude'    => (string) ($_POST['longitude'] ?? ''),
                'is_border'    => !empty($_POST['is_border']),
                'is_meal_halt' => !empty($_POST['is_meal_halt']),
                'km'           => (string) ($_POST['km'] ?? ''),
            ], (int) ($admin['id'] ?? 0));

            /* Say at once why a saved point would not show where the office
               looks for it (BUG2): the route is OFF, the booking card (SmartBook)
               reads only the first active route of a direction, or a pickup has
               no clock time (it then closes with the route's departure). */
            $route = Database::fetch('SELECT id, route_code, from_city, to_city, dep_time, is_active FROM routes WHERE id = :r', ['r' => $rid]) ?? [];
            $why   = '';
            if ((int) ($route['is_active'] ?? 0) !== 1) {
                $why .= ' This route is OFF - the point is not on sale until the route is switched ON.';
            } else {
                require_once INCLUDE_PATH . '/quickticket.php';
                $dir = QuickTicket::directionOf($route);
                if ($stopType === ($dir === 'toNepal' ? 'boarding' : 'drop')) {
                    foreach (QuickTicket::routes() as $qr) {
                        if ($qr['direction'] !== $dir) { continue; }
                        if ((int) $qr['id'] !== $rid) {
                            $why .= ' Note: the booking card (SmartBook) lists the points of ' . $qr['route_code'] . ' only, so this point will not show in the booking card - add it on ' . $qr['route_code'] . ' too to sell it there.';
                        }
                        break;
                    }
                }
            }
            if ($time === '' && $stopType === 'boarding') {
                $why .= ' No time set: on the travel day it stops selling ' . Boarding::cutoffMinutes() . ' min before the route leaves ('
                      . substr((string) ($route['dep_time'] ?? ''), 0, 5) . ') - set its time with ✎.';
            } elseif ($stopType === 'boarding') {
                $why .= $nightHint($time, isset($route['dep_time']) ? (string) $route['dep_time'] : null);
            }
            $flash = ['ok', 'Added ' . ($stopType === 'drop' ? 'drop (ड्रप)' : 'pickup (पिकअप)') . ' stop: ' . trim($name)
                . ' on ' . (string) ($route['route_code'] ?? $rid) . '.' . $why];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ── Edit a stop ── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'edit_stop') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            $stopId   = (int) ($_POST['stop_id'] ?? 0);
            $name     = Security::clean($_POST['stop_name'] ?? '', 191);
            $landmark = Security::clean($_POST['landmark'] ?? '', 191);
            $time     = trim($_POST['stop_time'] ?? '');
            if ($name === '') { throw new RuntimeException('Stop name is required.'); }

            $timeVal = null;
            if ($time !== '') {
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time) !== 1) {
                    throw new RuntimeException('Enter stop time as HH:MM.');
                }
                $timeVal = strlen($time) === 5 ? $time . ':00' : $time;
            }

            $old = Database::fetch('SELECT * FROM route_stops WHERE id = :id', ['id' => $stopId]);
            if ($old === null) { throw new RuntimeException('Stop not found.'); }

            $set = [
                'stop_name'    => $name,
                'landmark'     => $landmark !== '' ? $landmark : null,
                'stop_time'    => $timeVal,
                'is_border'    => !empty($_POST['is_border']) ? 1 : 0,
                'is_meal_halt' => !empty($_POST['is_meal_halt']) ? 1 : 0,
            ];
            // Map position (1 Oct 2026) — only when the form carries the fields.
            if (array_key_exists('latitude', $_POST) || array_key_exists('longitude', $_POST)) {
                $set['latitude']  = $stopCoordIn($_POST['latitude'] ?? '', 90.0, 'latitude');
                $set['longitude'] = $stopCoordIn($_POST['longitude'] ?? '', 180.0, 'longitude');
            }
            if (array_key_exists('fare', $_POST) && Boarding::hasStopControls()) {
                $set['fare'] = $stopFareIn($_POST['fare']);
            }
            // Road km (9 Oct 2026) — only when the form carries the field.
            if (array_key_exists('km', $_POST) && Boarding::hasRouteDetails()) {
                $set['km_from_origin'] = $stopKmIn($_POST['km']);
            }
            Database::update('route_stops', $set, 'id = :id', ['id' => $stopId]);
            Boarding::flush();
            /* 19 Nov 2026 (TK-2): the ticket's pickup-map QR reads this pin (row first),
               so a moved pin redraws every cached ticket once on its next open. */
            if (array_key_exists('latitude', $set)
                && (round((float) ($set['latitude'] ?? 0), 7) !== round((float) ($old['latitude'] ?? 0), 7)
                    || round((float) ($set['longitude'] ?? 0), 7) !== round((float) ($old['longitude'] ?? 0), 7)
                    || ($set['latitude'] === null) !== ($old['latitude'] === null))) {
                Settings::touchTicketIdentity();
            }

            Logger::audit('route_stop.edit', 'routes', (string) ($old['route_id'] ?? 0),
                ['stop' => $old['stop_name'], 'landmark' => $old['landmark'], 'time' => $old['stop_time'],
                 'lat' => $old['latitude'], 'lng' => $old['longitude'], 'fare' => $old['fare'] ?? null,
                 'km' => $old['km_from_origin'] ?? null],
                ['stop' => $name, 'landmark' => $set['landmark'], 'time' => $timeVal,
                 'lat' => $set['latitude'] ?? $old['latitude'], 'lng' => $set['longitude'] ?? $old['longitude'],
                 'fare' => array_key_exists('fare', $set) ? $set['fare'] : ($old['fare'] ?? null),
                 'km' => array_key_exists('km_from_origin', $set) ? $set['km_from_origin'] : ($old['km_from_origin'] ?? null)],
                'Edited stop: ' . $old['stop_name'] . ' → ' . $name);
            $flash = ['ok', 'Stop updated: ' . $name . '.'
                . (($old['stop_type'] ?? '') === 'boarding' && $timeVal !== null && $timeVal !== $old['stop_time']
                    ? $nightHint($timeVal, (string) Database::scalar('SELECT dep_time FROM routes WHERE id = :r', ['r' => (int) $old['route_id']], ''))
                    : '')];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ── Delete a stop ── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete_stop') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            $stopId = (int) ($_POST['stop_id'] ?? 0);
            $old = Database::fetch('SELECT * FROM route_stops WHERE id = :id', ['id' => $stopId]);
            if ($old === null) { throw new RuntimeException('Stop not found.'); }
            /* 1 Oct 2026: pickups are switched OFF, not deleted — bookings,
               tickets and the seat-map colours name the stop by its text. */
            if (Boarding::hasStopControls()) {
                throw new RuntimeException('Stops are not deleted any more - switch "' . $old['stop_name'] . '" OFF instead (bookings that name it stay intact).');
            }

            Database::delete('route_stops', 'id = :id', ['id' => $stopId]);

            // Re-number remaining stops
            $remaining = Database::fetchAll(
                'SELECT id FROM route_stops WHERE route_id = :r AND stop_type = :t ORDER BY sort_order',
                ['r' => (int) $old['route_id'], 't' => (string) $old['stop_type']]
            );
            foreach ($remaining as $i => $rr) {
                Database::update('route_stops', ['sort_order' => $i + 1], 'id = :id', ['id' => (int) $rr['id']]);
            }

            Logger::audit('route_stop.delete', 'routes', (string) ($old['route_id'] ?? 0),
                ['stop' => $old['stop_name']], null,
                'Deleted stop: ' . $old['stop_name']);
            $flash = ['ok', 'Stop deleted: ' . $old['stop_name'] . '.'];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ── Move a stop up or down ── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'move_stop') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } else {
        try {
            Auth::requireAdmin('routes.edit');
            $stopId   = (int) ($_POST['stop_id'] ?? 0);
            $dir      = (string) ($_POST['direction'] ?? '');

            $stop = Database::fetch('SELECT * FROM route_stops WHERE id = :id', ['id' => $stopId]);
            if ($stop === null) { throw new RuntimeException('Stop not found.'); }

            $siblings = Database::fetchAll(
                'SELECT id, sort_order FROM route_stops WHERE route_id = :r AND stop_type = :t ORDER BY sort_order',
                ['r' => (int) $stop['route_id'], 't' => (string) $stop['stop_type']]
            );

            $idx = null;
            foreach ($siblings as $i => $s) {
                if ((int) $s['id'] === $stopId) { $idx = $i; break; }
            }
            if ($idx === null) { throw new RuntimeException('Stop not found in list.'); }

            $swapIdx = $dir === 'up' ? $idx - 1 : $idx + 1;
            if ($swapIdx < 0 || $swapIdx >= count($siblings)) {
                throw new RuntimeException('Cannot move further ' . $dir . '.');
            }

            // Swap sort_orders
            Database::transaction(static function () use ($siblings, $idx, $swapIdx): void {
                $a = $siblings[$idx];
                $b = $siblings[$swapIdx];
                Database::update('route_stops', ['sort_order' => (int) $b['sort_order']], 'id = :id', ['id' => (int) $a['id']]);
                Database::update('route_stops', ['sort_order' => (int) $a['sort_order']], 'id = :id', ['id' => (int) $b['id']]);
            });

            Boarding::flush();
            // Validate time sequence after move
            $updated = Database::fetchAll(
                'SELECT stop_name, stop_time FROM route_stops WHERE route_id = :r AND stop_type = :t ORDER BY sort_order',
                ['r' => (int) $stop['route_id'], 't' => (string) $stop['stop_type']]
            );
            $warning = '';
            $prevTime = null;
            foreach ($updated as $u) {
                if ($u['stop_time'] !== null && $prevTime !== null && $u['stop_time'] < $prevTime) {
                    $warning = ' ⚠️ Warning: stop times are now out of sequence — ' . $u['stop_name'] . ' (' . substr((string)$u['stop_time'], 0, 5) . ') is earlier than the previous stop.';
                    break;
                }
                if ($u['stop_time'] !== null) { $prevTime = $u['stop_time']; }
            }

            Logger::audit('route_stop.move', 'routes', (string) ($stop['route_id'] ?? 0),
                null, ['stop' => $stop['stop_name'], 'direction' => $dir],
                'Moved stop ' . $dir . ': ' . $stop['stop_name']);
            $flash = ['ok', 'Stop moved ' . $dir . ': ' . $stop['stop_name'] . '.' . $warning];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

$routes = Database::fetchAll(
    "SELECT r.*, b.bus_name, b.bus_number
       FROM routes r LEFT JOIN buses b ON b.id=r.bus_id
      ORDER BY r.is_active DESC, r.sort_order, r.id"
);

// Load stops for each route
$routeStops = [];
$allStops = Database::fetchAll('SELECT * FROM route_stops ORDER BY route_id, stop_type, sort_order');
foreach ($allStops as $st) {
    $rid = (int) $st['route_id'];
    $type = (string) $st['stop_type'];
    $routeStops[$rid][$type][] = $st;
}

$today = todayISO();

admin_header('Routes', 'routes');
if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>'; }

$activeCount = 0;
foreach ($routes as $rr) { if ((int) $rr['is_active'] === 1) { $activeCount++; } }
?>
<?php if (Auth::can('routes.edit')): ?>
<div class="panel">
  <h2>&#128652; Daily service <span class="muted" style="font-weight:400">&middot; one coach out, one back</span></h2>
  <div style="padding:14px 18px">
    <p class="muted" style="font-size:13px;margin:0 0 12px">
      <b><?= (int) $activeCount ?></b> route<?= $activeCount === 1 ? '' : 's' ?> active right now.
      This sets the real operation: <b>one sleeper Surat &rarr; Rupaidiha at 13:00</b> and
      <b>one back at 18:00</b>, with <b>Rupaidiha as the last point</b>.
      Pickup points are only created for a route that has none &mdash; the pickups, fares
      and ON/OFF you set in <a href="#pickups">Pickup points &amp; fares</a> below are kept.
      Every other route is switched off &mdash; nothing is deleted, and no booking, ticket
      or payment is touched. Reversible: switch a route back on any time.
    </p>
    <?php
      /* 16 Nov 2026: the confirm names every route this switches OFF (on 15 Nov
         it took b02-up/b02-dn/b03-up/b03-dn off while the bus rotation still
         gave b02 every third day - no bus on sale on those days). Same pick
         of the two kept routes as the handler above. */
      $dsKeep = array_map('intval', array_filter([
          Database::scalar("SELECT id FROM routes WHERE coach_type='sleeper' AND LOWER(to_city) IN ('rupaidiha','nepalgunj') AND LOWER(from_city) NOT IN ('rupaidiha','nepalgunj') ORDER BY id LIMIT 1", [], 0),
          Database::scalar("SELECT id FROM routes WHERE coach_type='sleeper' AND LOWER(from_city) IN ('rupaidiha','nepalgunj') AND LOWER(to_city) NOT IN ('rupaidiha','nepalgunj') ORDER BY id LIMIT 1", [], 0),
      ]));
      $dsOff = [];
      $dsOffIds = [];
      foreach ($routes as $rr) {
          if ((int) $rr['is_active'] === 1 && !in_array((int) $rr['id'], $dsKeep, true)) {
              $dsOff[] = (string) $rr['route_code'];
              $dsOffIds[] = (int) $rr['id'];
          }
      }
      $dsRot = [];
      try {
          $rot = Fleet::rotation();
          if ($rot['enabled']) {
              foreach ($rot['routes'] as $pair) {
                  foreach ([(int) $pair['out'], (int) $pair['back']] as $rotRid) {
                      if ($rotRid > 0 && in_array($rotRid, $dsOffIds, true)) {
                          $dsRot[$rotRid] = $dsOff[array_search($rotRid, $dsOffIds, true)];
                      }
                  }
              }
          }
      } catch (Throwable $e) {
          $dsRot = [];
      }
      $dsMsg = 'Set one daily coach each way?'
             . ($dsOff !== [] ? "\n\nThese routes will be switched OFF: " . implode(', ', $dsOff) . '.' : "\n\nNo other route is ON, so nothing else is switched off.")
             . ($dsRot !== [] ? "\n\n⚠ Bus rotation is ON and uses " . implode(', ', $dsRot) . ' - the days it gives to those routes will have NO bus on sale.' : '')
             . "\n\nThis does not add a pickup point. Nothing is deleted; Activate a route again to undo.";
    ?>
    <form method="post" onsubmit="return confirm(<?= Security::e((string) json_encode($dsMsg, JSON_UNESCAPED_UNICODE)) ?>)" style="display:inline-block;margin-right:8px">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::e(Security::csrfToken()) ?>">
      <input type="hidden" name="action" value="daily_setup">
      <button class="btn" type="submit">Apply daily service<?= $dsOff !== [] ? ' (' . count($dsOff) . ' route' . (count($dsOff) === 1 ? '' : 's') . ' OFF)' : '' ?></button>
    </form>
    <?php if ($dsRot !== []): ?>
    <p style="font-size:12.5px;margin:8px 0 0;color:var(--bad,#b91c1c)">⚠ Bus rotation uses <?= Security::e(implode(', ', $dsRot)) ?>: "Apply daily service" would switch them OFF and leave the rotation's days for them with no bus on sale.</p>
    <?php endif; ?>
    <form method="post" style="display:inline-block" title="Insert one schedule row per active route for the next 30 days. Runs nightly on its own; use this after adding a route or on a fresh install.">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::e(Security::csrfToken()) ?>">
      <input type="hidden" name="action" value="refill_schedules">
      <button class="btn" type="submit">Refill schedule rows for next 30 days</button>
    </form>
    <p class="muted" style="font-size:12px;margin:10px 0 0">
      A nightly cron (<code>cron/daily-schedule.php</code>, recommended <code>30 0 * * *</code>) keeps this rolling window filled automatically.
    </p>
  </div>
</div>
<?php endif; ?>

<?php if (Auth::can('routes.edit')): ?>
<details class="panel" id="addRoute">
  <summary style="padding:14px 18px;font-weight:800;font-size:15px;cursor:pointer;background:var(--head);list-style:none">➕ Add a route <span class="muted" style="font-weight:400">· created switched off; add its stops, then Activate</span></summary>
  <form method="post" class="rt-form">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="action" value="add_route">
    <label>Code <input name="route_code" required maxlength="20" placeholder="r3" pattern="[A-Za-z0-9_\-]{1,20}"></label>
    <label>From city <input name="from_city" required maxlength="80" placeholder="Surat"></label>
    <label>To city <input name="to_city" required maxlength="80" placeholder="Rupaidiha"></label>
    <label>Coach <select name="coach_type"><option value="sleeper">Sleeper</option><option value="seater">Seater</option></select></label>
    <label>Departs <input type="time" name="dep_time" required></label>
    <label>Arrives <input type="time" name="arr_time" required></label>
    <label>Arrival day <select name="day_offset"><option value="0">Same day</option><option value="1" selected>Next day (+1)</option><option value="2">+2 days</option></select></label>
    <label>Base fare (₹) <input type="number" name="base_fare" min="0" max="100000" step="1" required placeholder="2000"></label>
    <button class="btn ok" type="submit">Create route</button>
  </form>
</details>
<style>
.rt-form{padding:14px 18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end}
.rt-form label{display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}
.rt-form input,.rt-form select{padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:16px;font-weight:500;background:var(--card);color:var(--ink);text-transform:none;letter-spacing:0;min-width:0}
.rt-edit summary{cursor:pointer;list-style:none;font-weight:600}
.rt-edit summary::after{content:' ✏️';font-size:11px}
.rt-edit form{margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;align-items:end}
.rt-edit label{display:flex;flex-direction:column;gap:2px;font-size:10px;font-weight:700;color:var(--mut);text-transform:uppercase}
.rt-edit input,.rt-edit select{padding:6px 8px;border:1px solid var(--line);border-radius:7px;font-size:16px;background:var(--card);color:var(--ink);text-transform:none;min-width:0}
.rt-edit input[type=text]{width:130px}.rt-edit input[type=number]{width:96px}
</style>
<?php endif; ?>
<div class="panel">
  <h2><?= count($routes) ?> route<?= count($routes) === 1 ? '' : 's' ?> · availability for today (<?= Security::e(formatDate($today)) ?>)</h2>
  <table class="card-table">
    <thead><tr><th>Code</th><th>Route</th><th>Bus</th><th>Type</th><th>Departs</th><th>Base fare</th><th>Seats free today</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($routes as $r):
      // 29 Oct 2026 (audit): availability() materialises today's schedule row,
      // so asking it for a switched-off route created a phantom trip on every
      // visit to this page (11 a day on live, later flipped to "departed" by
      // the lifecycle cron). A route that is off has no seats to show.
      $avail = (int) $r['is_active'] === 1
          ? Seats::availability((int) $r['id'], $today, $r['coach_type'] === 'sleeper' ? 'sharing' : 'seater')
          : null;
    ?>
      <tr style="<?= (int) $r['is_active'] === 1 ? '' : 'opacity:.5' ?>">
        <td class="mono" data-label="Code"><?= Security::e((string) $r['route_code']) ?></td>
        <td data-label="Route">
          <?php if (Auth::can('routes.edit')): ?>
          <details class="rt-edit">
            <summary><?= Security::e($r['from_city'] . ' → ' . $r['to_city']) ?></summary>
            <form method="post" onsubmit="return confirm('Save changes to this route? Existing bookings keep their own fare and cities.')">
              <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::e(Security::csrfToken()) ?>">
              <input type="hidden" name="action" value="update_route">
              <input type="hidden" name="route_id" value="<?= (int) $r['id'] ?>">
              <label>From <input type="text" name="from_city" value="<?= Security::e((string) $r['from_city']) ?>" required maxlength="80"></label>
              <label>To <input type="text" name="to_city" value="<?= Security::e((string) $r['to_city']) ?>" required maxlength="80"></label>
              <label>Coach <select name="coach_type"><option value="sleeper" <?= (string) $r['coach_type'] === 'sleeper' ? 'selected' : '' ?>>Sleeper</option><option value="seater" <?= (string) $r['coach_type'] === 'seater' ? 'selected' : '' ?>>Seater</option></select></label>
              <label>Fare ₹ <input type="number" name="base_fare" value="<?= (int) round((float) $r['base_fare']) ?>" min="0" max="100000" step="1" required></label>
              <button class="btn btn-ok" type="submit" style="font-size:11px;padding:6px 10px">Save</button>
            </form>
          </details>
          <?php else: ?>
          <?= Security::e($r['from_city'] . ' → ' . $r['to_city']) ?>
          <?php endif; ?>
        </td>
        <td data-label="Bus"><?= Security::e($r['bus_name'] ?? '—') ?><div class="muted mono"><?= Security::e($r['bus_number'] ?? '') ?></div></td>
        <td data-label="Type"><?= Security::e(ucfirst((string) $r['coach_type'])) ?></td>
        <td data-label="Departs">
          <?php if (Auth::can('routes.edit')): ?>
          <form method="post" style="display:flex;gap:4px;align-items:center;margin:0" title="Edit the default departure time for this route. Saves without touching stops, fares or existing schedules.">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::e(Security::csrfToken()) ?>">
            <input type="hidden" name="action" value="update_dep_time">
            <input type="hidden" name="route_id" value="<?= (int) $r['id'] ?>">
            <input type="time" name="dep_time" value="<?= Security::e(substr((string) ($r['dep_time'] ?? ''), 0, 5)) ?>" required style="padding:3px 6px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--ink);font-size:12px;width:90px">
            <button class="btn btn-ok" type="submit" style="font-size:11px;padding:3px 8px" title="Save departure time">Save</button>
          </form>
          <?php else: ?>
          <?= Security::e(formatTime($r['dep_time'] ?? null)) ?>
          <?php endif; ?>
        </td>
        <td data-label="Base fare"><?= Security::e(inr((float) $r['base_fare'])) ?></td>
        <td data-label="Seats free today"><?php if ($avail === null): ?><span class="muted">— off</span><?php else: ?><strong><?= (int) ($avail['availableCount'] ?? 0) ?></strong><span class="muted"> / <?= (int) ($avail['total'] ?? 0) ?></span><?php endif; ?></td>
        <td data-label="Status"><?= (int) $r['is_active'] === 1 ? admin_pill('confirmed') : admin_pill('expired') ?></td>
        <td data-label="Action">
          <?php if (Auth::can('routes.edit')): ?>
          <form method="post" style="margin:0" onsubmit="return confirm('<?= (int) $r['is_active'] === 1 ? 'Switch this route OFF? It stops selling immediately; existing bookings are untouched.' : 'Switch this route ON? It becomes bookable right away.' ?>')">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::e(Security::csrfToken()) ?>">
            <input type="hidden" name="action" value="toggle_route">
            <input type="hidden" name="route_id" value="<?= (int) $r['id'] ?>">
            <button class="btn <?= (int) $r['is_active'] === 1 ? '' : 'ok' ?>" type="submit" style="font-size:11px;padding:4px 10px">
              <?= (int) $r['is_active'] === 1 ? 'Switch off' : 'Activate' ?>
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php /* ══════════════════════════════════════════════════════════════
 *  Stop Editor — per-route, reorderable, with visual timeline
 *  (Admin Panel Upgrade · Section D)
 * ═══════════════════════════════════════════════════════════════ */ ?>
<style>
.stop-editor{margin-bottom:28px}
.stop-editor h3{font-size:15px;margin:0 0 14px;display:flex;align-items:center;gap:8px}

/* ── Visual timeline ── */
.stop-timeline{position:relative;padding:0 0 0 32px;margin:0 0 20px}
.stop-timeline::before{content:'';position:absolute;left:13px;top:8px;bottom:8px;width:3px;background:var(--blue);border-radius:2px}
.stop-node{position:relative;padding:8px 0;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.stop-node::before{content:'';position:absolute;left:-24px;top:50%;transform:translateY(-50%);width:12px;height:12px;border-radius:50%;background:var(--blue);border:3px solid var(--card);z-index:1}
.stop-node:first-child::before{background:#0a6b3b;width:14px;height:14px;left:-25px}
.stop-node:last-child::before{background:#c00;width:14px;height:14px;left:-25px}
.stop-node .stop-name{font-weight:700;font-size:14px;min-width:140px}
.stop-node .stop-time{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;color:var(--mut);min-width:50px}
.stop-node .stop-landmark{font-size:12px;color:var(--mut);font-style:italic}
.stop-node .stop-badges span{font-size:10px;padding:2px 6px;border-radius:4px;margin-right:4px}
.stop-node.off .stop-name,.stop-node.off .stop-time,.stop-node.off .stop-landmark{opacity:.5;text-decoration:line-through}
.stop-node .stop-fare{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;color:var(--mut)}
.stop-node .stop-fare input{width:92px;padding:6px 8px;border:1px solid var(--line);border-radius:7px;font-size:14px;font-weight:700;background:var(--card);color:var(--ink)}
.stop-node .stop-fare input::placeholder{font-weight:400;color:var(--mut)}
.stop-onoff{font-weight:800;min-width:54px}
.stop-onoff.on{background:#0a6b3b;color:#fff;border-color:#0a6b3b}
.stop-onoff.offb{background:#8a8a8a;color:#fff;border-color:#8a8a8a}
.off-badge{background:#eee;color:#555}
:root[data-theme="dark"] .off-badge{background:#333;color:#bbb}
.stop-fare-save{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:-8px 0 14px}
.pickup-intro{padding:12px 18px;font-size:13px}
.stop-node .stop-badges .border-badge{background:#fff4d1;color:#8a6d00}
.stop-node .stop-badges .meal-badge{background:#e6f7e6;color:#0a6b3b}
:root[data-theme="dark"] .stop-node .stop-badges .border-badge{background:#4a3d00;color:#ffe28a}
:root[data-theme="dark"] .stop-node .stop-badges .meal-badge{background:#0e3d0e;color:#8fe68f}
.time-warn{color:#c00;font-size:11px;font-weight:700;margin-left:4px}

/* ── Stop controls ── */
.stop-controls{display:flex;gap:4px;margin-left:auto}
.stop-controls form{margin:0}
.stop-controls .btn{font-size:11px;padding:3px 8px;min-height:32px}
.stop-add-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;align-items:end;padding:12px 0;border-top:1px solid var(--line);margin-top:8px}
.stop-edit-form{display:none;grid-template-columns:1fr 1fr auto;gap:8px;align-items:end;padding:10px 12px;margin:2px 0 6px;background:var(--hover);border:1px dashed var(--line);border-radius:10px}
.stop-edit-form.open{display:grid}
.stop-edit-form label{font-size:11px;color:var(--mut);display:block;margin-bottom:2px}
.stop-edit-form input[type=text],.stop-edit-form input[type=time],.stop-edit-form input[type=number]{width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px}
.stop-edit-form .chk-row{display:flex;gap:12px;font-size:13px;align-items:center}
.stop-add-form label{font-size:11px;font-weight:700;color:var(--mut);display:block;margin-bottom:2px}
.stop-add-form input,.stop-add-form select{padding:7px 10px;border:1px solid var(--line);border-radius:7px;font-size:13px;background:var(--card);color:var(--ink);width:100%}
.stop-add-form .chk-row{display:flex;gap:12px;align-items:center;font-size:12px}
.stop-add-form .chk-row input[type="checkbox"]{width:auto}

/* ── Mobile ── */
@media(max-width:820px){
  .stop-add-form{grid-template-columns:1fr}
  .stop-node{flex-direction:column;align-items:flex-start;gap:4px}
  .stop-controls{margin-left:0;margin-top:4px}
  .stop-edit-form{grid-template-columns:1fr !important}
}
@media(pointer:coarse){
  .stop-controls .btn{min-height:44px;padding:6px 12px}
}
</style>

<?php
$stopControls = Boarding::hasStopControls();
$dirFaresNow  = Fare::dirFares();
// Route facts + halts (9 Oct 2026): km per stop, en-route halts, measured on press.
$hasDetails   = Boarding::hasRouteDetails();
$haltsAll     = $hasDetails ? Boarding::haltsForRoutes(array_map(static fn(array $r): int => (int) $r['id'], $routes), false) : [];
$navEngine    = Settings::getString('nav_engine_url', 'http://127.0.0.1:8002');
$haltKindLab  = ['breakfast' => '🍳 Breakfast', 'lunch' => '🍛 Lunch', 'dinner' => '🍽️ Dinner', 'meal' => '🍽️ Meal', 'tea' => '☕ Tea / toilet', 'fuel' => '⛽ Fuel', 'rest' => '🛏️ Rest', 'border' => '🛂 Border'];
?>
<style>
.rf-box{margin:0 18px 18px;padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--hover)}
.rf-box h3{font-size:14px;margin:0 0 8px}
.rf-sum{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px}
.rf-sum span{font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px;background:var(--card);border:1px solid var(--line)}
.rf-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;align-items:end}
.rf-form label{display:flex;flex-direction:column;gap:3px;font-size:11px;font-weight:700;color:var(--mut)}
.rf-form label.chk{flex-direction:row;align-items:center;gap:6px;font-size:12px;color:var(--ink)}
.rf-form input,.rf-form select{padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:16px;background:var(--card);color:var(--ink);min-width:0;width:100%}
.rf-form label.chk input{width:auto}
.rf-form .btn{min-height:44px}
.halt-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:8px 0;border-top:1px dashed var(--line);font-size:13px}
.halt-row:first-of-type{border-top:0}
.halt-row.off{opacity:.5}
.halt-row b{min-width:120px}
.halt-row .muted{font-size:12px}
.halt-row .stop-controls{margin-left:auto}
.halt-edit{display:none;margin:4px 0 10px}
.halt-edit.open{display:grid}
.km-badge{background:#e8f0ff;color:#1F4FA3}
:root[data-theme="dark"] .km-badge{background:#1b2a47;color:#9cc0ff}
/* 16 Nov 2026: the top "add a point" box, the saved-fare tag, the map picker */
.np-add{margin:0 18px 16px;padding:12px 14px;border:1px solid var(--line);border-radius:12px;background:var(--hover)}
.np-add h3{font-size:14.5px;margin:0 0 4px}
.np-form{border-top:0;margin-top:0}
.np-form .btn{min-height:44px;width:100%}
.stop-fare-now{font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;background:#e6f4ea;color:#0a6b3b;white-space:nowrap}
.stop-fare-now.is-def{background:#fff4d1;color:#8a5a00}
:root[data-theme="dark"] .stop-fare-now{background:#0e3d1e;color:#9be0b0}
:root[data-theme="dark"] .stop-fare-now.is-def{background:#4a3d00;color:#ffe28a}
[data-ie-entity]{cursor:pointer;border-bottom:1px dashed currentColor}
.mp-modal{position:fixed;inset:0;z-index:1000;background:rgba(10,20,40,.6);display:flex;align-items:center;justify-content:center;padding:12px}
.mp-modal[hidden]{display:none}
.mp-box{background:var(--card,#fff);color:var(--ink);border-radius:14px;width:100%;max-width:720px;max-height:calc(100vh - 24px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35)}
.mp-head{display:flex;gap:8px;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--line)}
.mp-head b{font-size:14px}
.mp-map{height:min(60vh,460px);min-height:260px;background:#dfe7ef}
.mp-foot{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;padding:10px 12px;border-top:1px solid var(--line);font-size:13px}
.mp-foot .btn,.mp-head .btn{min-height:44px}
.mp-pin{width:22px;height:22px;border-radius:50% 50% 50% 0;background:#E8652E;border:3px solid #fff;transform:rotate(-45deg);box-shadow:0 2px 6px rgba(0,0,0,.4)}
@media(pointer:coarse){.halt-row .btn{min-height:44px;padding:6px 12px}}
</style>
<div class="panel" id="pickups">
  <h2>📍 Pickup points &amp; fares <span class="muted" style="font-weight:400">· चढ्ने ठाउँ र भाडा</span></h2>
  <div class="pickup-intro muted">
    Every pickup the website, the counter, agents, QuickTicket and the AI sell comes from the lists below.
    Type a <b>fare</b> for a pickup (blank = the default fare from <a href="settings.php#faresPanel">Settings</a>:
    Gujarat → Rupaidiha <b><?= Security::e(inr($dirFaresNow['toNepal'])) ?></b>, Rupaidiha → Gujarat <b><?= Security::e(inr($dirFaresNow['toIndia'])) ?></b>),
    switch a pickup <b>ON / OFF</b>, reorder it with ▲▼ or edit it with ✎. Stops are never deleted — OFF keeps old bookings intact.
    <?php if (!$stopControls): ?><br><b style="color:var(--bad)">Run database/upgrade-2026-10-01-stop-fares.sql to enable fares and ON/OFF.</b><?php endif; ?>
  </div>
  <?php if (Auth::can('routes.edit')):
    /* 16 Nov 2026 (BUG2, owner: "location add nahi ho raha"): the add form was
       a folded "+ Add boarding stop" ~4,000 px down, once per route. This box
       is at the top; the route defaults to the one the booking card
       (SmartBook) sells for that direction. Posts to the same add_stop. */
    require_once INCLUDE_PATH . '/quickticket.php';
    $npDef = ['boarding' => 0, 'drop' => 0];
    try {
        foreach (QuickTicket::routes() as $qr) {
            $t = $qr['direction'] === 'toNepal' ? 'boarding' : 'drop';
            if ($npDef[$t] === 0) { $npDef[$t] = (int) $qr['id']; }
        }
    } catch (Throwable $e) {
        // no default: the first route in the list is selected
    }
    $npCsrf = Security::e(Security::csrfToken());
  ?>
  <div class="np-add" id="add-stop">
    <h3>➕ नयाँ पिकअप / ड्रप · Add pickup / drop point</h3>
    <form method="post" class="stop-add-form np-form" id="npAddForm" data-def-boarding="<?= $npDef['boarding'] ?>" data-def-drop="<?= $npDef['drop'] ?>">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $npCsrf ?>">
      <input type="hidden" name="action" value="add_stop">
      <div><label for="npType">Type · प्रकार</label>
        <select name="stop_type" id="npType">
          <option value="boarding">पिकअप (जाने) · Pickup, Gujarat → Rupaidiha</option>
          <option value="drop">ड्रप (फर्कने) · Drop, Rupaidiha → Gujarat</option>
        </select></div>
      <div><label for="npRoute">Route · रुट</label>
        <select name="route_id" id="npRoute">
          <?php foreach ($routes as $ar): ?>
            <option value="<?= (int) $ar['id'] ?>" data-dir="<?= Fare::routeDir($ar) ?>" <?= (int) $ar['id'] === $npDef['boarding'] ? 'selected' : '' ?>><?= Security::e($ar['route_code'] . ' · ' . $ar['from_city'] . ' → ' . $ar['to_city'] . ((int) $ar['is_active'] === 1 ? '' : ' (OFF)') . ((int) $ar['id'] === $npDef['boarding'] || (int) $ar['id'] === $npDef['drop'] ? ' ★ booking card' : '')) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label for="npName">Place name · ठाउँको नाम</label><input type="text" id="npName" name="stop_name" required maxlength="191" placeholder="e.g. Gandhinagar"></div>
      <div><label for="npLand">Landmark · चिनारी</label><input type="text" id="npLand" name="landmark" maxlength="191" placeholder="optional"></div>
      <div><label for="npTime">Time · समय (HH:MM)</label><input type="time" id="npTime" name="stop_time"></div>
      <div><label for="npFare">Fare ₹ / seat · भाडा</label><input type="text" id="npFare" name="fare" inputmode="numeric" maxlength="12" placeholder="blank = default <?= Security::e(inr($dirFaresNow['toNepal'])) ?>" data-def-boarding="<?= Security::e(inr($dirFaresNow['toNepal'])) ?>" data-def-drop="<?= Security::e(inr($dirFaresNow['toIndia'])) ?>"></div>
      <div><label for="npLat">Latitude</label><input type="number" id="npLat" name="latitude" step="any" min="-90" max="90" inputmode="decimal" placeholder="optional"></div>
      <div><label for="npLng">Longitude</label><input type="number" id="npLng" name="longitude" step="any" min="-180" max="180" inputmode="decimal" placeholder="optional"></div>
      <div><label>&nbsp;</label><button class="btn" type="button" data-pick-map>📍 नक्सामा छान्नुहोस् · Pick on map</button></div>
      <div><label>&nbsp;</label><button class="btn ok" type="submit">➕ Add point · थप्नुहोस्</button></div>
    </form>
    <p class="muted" style="font-size:12px;margin:4px 0 0">★ = the route the booking card (SmartBook) sells for that direction; a point on another route does not show there. No time = it stops selling when the route leaves. Fare blank = the default.</p>
  </div>
  <?php endif; ?>
</div>
<?php
/* 26 Nov 2026 (fx2): a switched-OFF route prints a one-line stub with a
   "Show stops" link (?route=<id>) instead of its whole stop editor - 11 off
   routes were a third of this page. ?off=1 opens all of them. A POST always
   renders everything, so "route created OFF - add its stops below" and any
   save on an off route look exactly as before. */
$rtShowOff = ($_GET['off'] ?? '') === '1' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$rtOpenRid = is_scalar($_GET['route'] ?? null) ? (int) $_GET['route'] : 0;
$rtFolded  = [];
foreach ($routes as $r):
  $rid = (int) $r['id'];
  $boarding = $routeStops[$rid]['boarding'] ?? [];
  $drops    = $routeStops[$rid]['drop'] ?? [];
  $canEdit  = Auth::can('routes.edit');
  $csrf_val = Security::e(Security::csrfToken());
  // The list that carries the per-pickup fare: boarding going to Nepal, drop coming back.
  $pricedType = Fare::routeDir($r) === 'toNepal' ? 'boarding' : 'drop';
  $defFare    = (float) $dirFaresNow[Fare::routeDir($r)];
  if ((int) $r['is_active'] !== 1 && !$rtShowOff && $rid !== $rtOpenRid): $rtFolded[] = $rid; ?>
<div class="panel stop-editor" id="stops-<?= $rid ?>">
  <h2><?= Security::e($r['route_code'] . ' · ' . $r['from_city'] . ' → ' . $r['to_city']) ?> — Stops <span class="muted" style="font-weight:400">(route OFF)</span></h2>
  <p style="padding:0 18px 16px;margin:0"><a class="btn" href="?route=<?= $rid ?>#stops-<?= $rid ?>" style="min-height:44px">स्टप देखाउनुहोस् · Show stops (<?= count($boarding) + count($drops) ?>)</a></p>
</div>
<?php continue; endif;
?>
<div class="panel stop-editor" id="stops-<?= $rid ?>">
  <h2><?= Security::e($r['route_code'] . ' · ' . $r['from_city'] . ' → ' . $r['to_city']) ?> — Stops<?= (int) $r['is_active'] === 1 ? '' : ' <span class="muted" style="font-weight:400">(route OFF)</span>' ?></h2>
  <?php
    /* Route facts (9 Oct 2026): what the website prints — km, stops,
       halts, journey time — computed from the same journey line the home
       route rail and the live tracker draw. */
    $rHalts = $haltsAll[$rid] ?? [];
    $rLine  = Boarding::journeyLine(Boarding::lineStops($rid), array_values(array_filter($rHalts, static fn(array $h): bool => $h['active'])), (string) $r['dep_time']);
    $rSum   = Boarding::journeySummary($rLine, $r);
    $rDur   = Boarding::durationMin((string) $r['dep_time'], (string) ($r['arr_time'] ?? ''), (int) ($r['day_offset'] ?? 0));
    $rShown = trim((string) ($r['duration_text'] ?? '')) !== '';
  ?>
  <div class="rf-box" id="facts-<?= $rid ?>">
    <h3>📏 Route facts <span class="muted" style="font-weight:400">· दूरी, स्टप, विश्राम</span></h3>
    <div class="rf-sum">
      <?php foreach ($rSum['spans'] as $sp): ?><span><?= Security::e($sp['from'] . ' → ' . $sp['to']) ?> ≈ <?= Security::e(number_format((int) $sp['km'])) ?> km</span><?php endforeach; ?>
      <?php if ($rSum['spans'] === []): ?><span><?= $rSum['distanceKm'] !== null ? '≈ ' . Security::e(number_format((int) $rSum['distanceKm'])) . ' km (route setting)' : 'km not measured yet' ?></span><?php endif; ?>
      <span><?= (int) $rSum['stopCount'] ?> stops · <?= (int) $rSum['haltCount'] ?> halts</span>
      <span><?= $rDur !== null ? '≈ ' . intdiv($rDur, 60) . 'h ' . sprintf('%02d', $rDur % 60) . 'm on the timetable' : 'journey time not set' ?></span>
      <span><?= $rShown ? '✅ arrival shown to customers' : '🙈 arrival hidden from customers' ?></span>
    </div>
    <?php if ($canEdit): ?>
    <form method="post" class="rf-form">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>">
      <input type="hidden" name="action" value="update_route_facts">
      <input type="hidden" name="route_id" value="<?= $rid ?>">
      <?php $kmS = ($kmSuggest !== null && (int) $kmSuggest['rid'] === $rid && (int) $kmSuggest['km'] >= 1 && (int) $kmSuggest['km'] <= 9999) ? $kmSuggest : null; ?>
      <label>Distance km (route setting) <input type="number" name="distance_km" min="1" max="9999" step="1" value="<?= $kmS !== null ? (int) $kmS['km'] : ($r['distance_km'] !== null ? (int) $r['distance_km'] : '') ?>" placeholder="e.g. 1375"<?= $kmS !== null ? ' style="outline:2px solid var(--warning,#d97706)"' : '' ?>></label>
      <label>Arrives (HH:MM) <input type="time" name="arr_time" value="<?= Security::e(substr((string) ($r['arr_time'] ?? ''), 0, 5)) ?>"></label>
      <label>Arrival day <select name="day_offset"><?php foreach ([0 => 'Same day', 1 => 'Next day (+1)', 2 => '+2 days', 3 => '+3 days'] as $dv => $dl): ?><option value="<?= $dv ?>" <?= (int) ($r['day_offset'] ?? 0) === $dv ? 'selected' : '' ?>><?= $dl ?></option><?php endforeach; ?></select></label>
      <label>Journey text <input type="text" name="duration_text" maxlength="40" value="<?= Security::e((string) ($r['duration_text'] ?? '')) ?>" placeholder="auto, e.g. 35h 39m"></label>
      <label class="chk"><input type="checkbox" name="show_arrival" value="1" <?= $rShown ? 'checked' : '' ?>> Show arrival to customers</label>
      <button class="btn ok" type="submit">💾 Save route facts</button>
    </form>
    <?php if ($kmS !== null): ?>
    <p class="rf-suggest" role="status" style="font-size:12.5px;margin:8px 0 0;padding:8px 10px;border-radius:8px;border:1px dashed var(--warning,#d97706)">
      📏 Suggested from the road engine (not saved yet): <b><?= Security::e(number_format((int) $kmS['km'])) ?> km</b> to the last stop with a map position
      (was <?= $r['distance_km'] !== null ? Security::e(number_format((int) $r['distance_km'])) . ' km' : 'blank' ?>)<?php if ($kmS['min'] !== null): ?>,
      engine driving time ≈ <b><?= intdiv((int) $kmS['min'], 60) ?>h <?= sprintf('%02d', (int) $kmS['min'] % 60) ?>m</b> without halts<?php endif; ?><?php if ($kmS['road'] !== null): ?>,
      stored road line <b><?= Security::e(number_format((float) $kmS['road'], 1)) ?> km</b><?php endif; ?>.
      Press <b>💾 Save route facts</b> to keep the distance; arrival times stay yours to set.
    </p>
    <?php endif; ?>
    <p class="muted" style="font-size:12px;margin:8px 0 0">The website shows km from the stop list below (Surat → Rupaidiha and Ahmedabad → Rupaidiha). The "Distance km" setting is only a fallback and is what the SEO route pages print. Unticked = no arrival clock on search, timetable or tracker (journey time shows as "≈").</p>
    <?php if ($hasDetails): ?>
    <form method="post" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Measuring…'">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>">
      <input type="hidden" name="action" value="measure_km">
      <input type="hidden" name="route_id" value="<?= $rid ?>">
      <button class="btn" type="submit" style="min-height:44px">📏 Measure road km</button>
      <small class="muted">Asks our route engine (<?= Security::e($navEngine) ?>, bus) for every stop with a map position and saves the km. Stops without latitude/longitude are skipped and listed.</small>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($hasDetails): ?>
  <div class="rf-box" id="halts-<?= $rid ?>">
    <h3>🍽️ Halts on the way <span class="muted" style="font-weight:400">· खाना / चिया / विश्राम (<?= count($rHalts) ?>)</span></h3>
    <p class="muted" style="font-size:12px;margin:0 0 6px">Tea, breakfast, lunch, dinner and fuel halts shown to customers on the home route line and the live tracker. "≈ approximate" = typical plan; the crew picks the exact dhaba. Times follow the departure: a retimed bus moves its halts.</p>
    <?php if ($rHalts === []): ?><p class="muted" style="font-size:13px">No halts yet.</p><?php endif; ?>
    <?php
      /* 19 Nov 2026 (u8 AI-4 follow-up): a halt time learned from the GPS trail at night
         (route_stop_learned, cron/stop-timing.php) is shown beside the halt. "Use this time"
         only re-posts the normal edit_halt form above with the halt's own values and the
         suggested clock - routes.edit, CSRF and the route_halt.edit audit as before; nothing
         changes a timetable by itself. The clock is worked from TODAY's departure time and
         the learned median offset, so a retimed bus is not given a stale time. */
      $rLearn = [];
      if ($rHalts !== [] && is_file(INCLUDE_PATH . '/stoptiming.php')) {
          try {
              require_once INCLUDE_PATH . '/stoptiming.php';
              foreach (StopTiming::learned($rid) as $lk => $lr) {
                  if (str_starts_with((string) $lk, 'halt:') && $lr['suggested_time'] !== null) {
                      $rLearn[(int) $lr['stop_ref']] = $lr;
                  }
              }
          } catch (Throwable $e) {
              $rLearn = [];   // stop timing not installed
          }
      }
    ?>
    <?php foreach ($rHalts as $h):
      $hDay   = $h['offsetMin'] !== null ? intdiv(Boarding::hmToMin((string) $r['dep_time']) + $h['offsetMin'], 1440) : 0;
      $hClock = $h['offsetMin'] !== null ? Boarding::minToHm((int) Boarding::hmToMin((string) $r['dep_time']) + $h['offsetMin']) : (string) ($h['clock'] ?? '');
    ?>
    <div class="halt-row<?= $h['active'] ? '' : ' off' ?>">
      <span class="stop-time" style="font-family:ui-monospace,Menlo,Consolas,monospace"><?= $h['approx'] ? '≈' : '' ?><?= Security::e($hClock !== '' ? $hClock : '—') ?><?= $hDay > 0 ? '<sup>+' . $hDay . '</sup>' : '' ?></span>
      <b><?= Security::e($h['name']) ?></b>
      <span><?= Security::e($haltKindLab[$h['kind']] ?? $h['kind']) ?> · <?= (int) $h['haltMin'] ?> min<?= $h['km'] !== null ? ' · ' . Security::e(number_format((float) $h['km'], 0)) . ' km' : '' ?></span>
      <?php if ($h['note'] !== ''): ?><span class="muted"><?= Security::e($h['note']) ?></span><?php endif; ?>
      <?php if (!$h['active']): ?><span class="off-badge" style="font-size:10px;padding:2px 6px;border-radius:4px">OFF</span><?php endif; ?>
      <?php $hl = $rLearn[(int) $h['id']] ?? null; if ($hl !== null):
        [$hSug, $hSugDay] = StopTiming::suggest((string) $r['dep_time'], (int) $hl['median_offset_min']);
        $hSug = substr($hSug, 0, 5); ?>
      <span class="muted" data-learned-halt="<?= (int) $h['id'] ?>">GPS ≈ <?= Security::e($hSug) ?><?= $hSugDay > 0 ? '<sup>+' . $hSugDay . '</sup>' : '' ?> · <?= (int) $hl['samples'] ?> trips<?= $hl['median_dwell_min'] !== null ? ' · रोकिने · stops ' . (int) $hl['median_dwell_min'] . ' min' : '' ?></span>
        <?php if ($canEdit && $hSugDay <= 3 && ($hSug !== $hClock || $hSugDay !== $hDay)): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('समय <?= Security::e($hSug) ?> राख्ने? · Set this halt to <?= Security::e($hSug) ?><?= $hSugDay > 0 ? ' (+' . $hSugDay . ')' : '' ?>?')">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>">
        <input type="hidden" name="action" value="edit_halt">
        <input type="hidden" name="halt_id" value="<?= (int) $h['id'] ?>">
        <input type="hidden" name="name" value="<?= Security::e($h['name']) ?>">
        <input type="hidden" name="kind" value="<?= Security::e($h['kind']) ?>">
        <input type="hidden" name="clock_time" value="<?= Security::e($hSug) ?>">
        <input type="hidden" name="day" value="<?= (int) $hSugDay ?>">
        <input type="hidden" name="halt_min" value="<?= (int) $h['haltMin'] ?>">
        <input type="hidden" name="km" value="<?= $h['km'] !== null ? Security::e((string) $h['km']) : '' ?>">
        <input type="hidden" name="landmark" value="<?= Security::e($h['landmark']) ?>">
        <input type="hidden" name="note" value="<?= Security::e($h['note']) ?>">
        <?php if ($h['approx']): ?><input type="hidden" name="is_approx" value="1"><?php endif; ?>
        <button class="btn ghost" type="submit" style="font-size:11.5px;padding:3px 8px">यो समय राख्नुहोस् · Use this time</button>
      </form>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($canEdit): ?>
      <div class="stop-controls">
        <form method="post"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>"><input type="hidden" name="action" value="toggle_halt"><input type="hidden" name="halt_id" value="<?= (int) $h['id'] ?>"><button class="btn stop-onoff <?= $h['active'] ? 'on' : 'offb' ?>" type="submit"><?= $h['active'] ? 'ON' : 'OFF' ?></button></form>
        <button class="btn" type="button" title="Edit halt" onclick="toggleHaltEdit(<?= (int) $h['id'] ?>)">✎</button>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
    <form method="post" class="rf-form halt-edit" id="halt-edit-<?= (int) $h['id'] ?>">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>">
      <input type="hidden" name="action" value="edit_halt">
      <input type="hidden" name="halt_id" value="<?= (int) $h['id'] ?>">
      <label>Place <input type="text" name="name" required maxlength="120" value="<?= Security::e($h['name']) ?>"></label>
      <label>Kind <select name="kind"><?php foreach ($haltKindLab as $kv => $kl): ?><option value="<?= $kv ?>" <?= $h['kind'] === $kv ? 'selected' : '' ?>><?= Security::e($kl) ?></option><?php endforeach; ?></select></label>
      <label>Time <input type="time" name="clock_time" value="<?= Security::e($hClock) ?>"></label>
      <label>Journey day <select name="day"><?php foreach ([0 => 'Departure day', 1 => 'Day 2 (+1)', 2 => 'Day 3 (+2)', 3 => 'Day 4 (+3)'] as $dv => $dl): ?><option value="<?= $dv ?>" <?= $hDay === $dv ? 'selected' : '' ?>><?= $dl ?></option><?php endforeach; ?></select></label>
      <label>Stands (min) <input type="number" name="halt_min" min="0" max="600" step="5" value="<?= (int) $h['haltMin'] ?>"></label>
      <label>Km from start <input type="number" name="km" min="0" max="9999" step="0.1" value="<?= $h['km'] !== null ? Security::e((string) $h['km']) : '' ?>"></label>
      <label>Landmark <input type="text" name="landmark" maxlength="191" value="<?= Security::e($h['landmark']) ?>"></label>
      <label>Note <input type="text" name="note" maxlength="255" value="<?= Security::e($h['note']) ?>"></label>
      <label class="chk"><input type="checkbox" name="is_approx" value="1" <?= $h['approx'] ? 'checked' : '' ?>> ≈ approximate</label>
      <button class="btn ok" type="submit">Save halt</button>
    </form>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($canEdit): ?>
    <details style="margin-top:8px">
      <summary style="font-size:13px;font-weight:700;cursor:pointer;color:var(--blue);padding:6px 0">+ Add a halt</summary>
      <form method="post" class="rf-form">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>">
        <input type="hidden" name="action" value="add_halt">
        <input type="hidden" name="route_id" value="<?= $rid ?>">
        <label>Place <input type="text" name="name" required maxlength="120" placeholder="e.g. Kota"></label>
        <label>Kind <select name="kind"><?php foreach ($haltKindLab as $kv => $kl): ?><option value="<?= $kv ?>"><?= Security::e($kl) ?></option><?php endforeach; ?></select></label>
        <label>Time <input type="time" name="clock_time"></label>
        <label>Journey day <select name="day"><option value="0">Departure day</option><option value="1" selected>Day 2 (+1)</option><option value="2">Day 3 (+2)</option><option value="3">Day 4 (+3)</option></select></label>
        <label>Stands (min) <input type="number" name="halt_min" min="0" max="600" step="5" value="15"></label>
        <label>Km from start <input type="number" name="km" min="0" max="9999" step="0.1" placeholder="optional"></label>
        <label>Landmark <input type="text" name="landmark" maxlength="191" placeholder="optional"></label>
        <label>Note <input type="text" name="note" maxlength="255" placeholder="optional"></label>
        <label class="chk"><input type="checkbox" name="is_approx" value="1" checked> ≈ approximate</label>
        <button class="btn ok" type="submit">Add halt</button>
      </form>
    </details>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php foreach (['boarding' => $boarding, 'drop' => $drops] as $type => $stops):
    $priced   = $stopControls && $type === $pricedType;
    $fareForm = 'fares-' . $rid . '-' . $type;
  ?>
  <div style="padding:0 18px 18px">
    <h3>
      <?= $type === 'boarding' ? '🟢 Boarding points / चढ्ने ठाउँ' : '🔴 Drop points / ओर्लिने ठाउँ' ?>
      <span class="muted" style="font-weight:400;font-size:13px">(<?= count($stops) ?>)<?= $priced ? ' · fare per seat, blank = default ' . Security::e(inr($defFare)) : '' ?></span>
    </h3>

    <?php if ($stops === []): ?>
      <p class="muted" style="font-size:13px">No <?= $type ?> stops configured yet.</p>
    <?php else: ?>
      <div class="stop-timeline">
        <?php
        $prevTime = null;
        foreach ($stops as $idx => $st):
          $timeWarn = false;
          if ($st['stop_time'] !== null && $prevTime !== null && $st['stop_time'] < $prevTime) {
              $timeWarn = true;
          }
          if ($st['stop_time'] !== null) { $prevTime = $st['stop_time']; }
        ?>
        <?php $stOn = !$stopControls || (int) ($st['is_active'] ?? 1) === 1;
          /* 16 Nov 2026 (C2): click-to-edit for the time, landmark and fare
             (assets/js/admin-inline-edit.js -> admin/api/inline-save.php). */
          $ie = static fn(string $field, string $value, string $type, bool $confirm = false): string => $canEdit
              ? ' data-ie-entity="route_stop" data-ie-field="' . $field . '" data-ie-id="' . (int) $st['id'] . '" data-ie-value="' . Security::e($value) . '" data-ie-type="' . $type . '"' . ($confirm ? ' data-ie-confirm="1"' : '') . ' title="Click to edit · थिचेर बदल्नुहोस्"'
              : ''; ?>
        <div class="stop-node<?= $stOn ? '' : ' off' ?>">
          <span class="stop-time">
            <span<?= $ie('stop_time', $st['stop_time'] !== null ? substr((string) $st['stop_time'], 0, 5) : '', 'time') ?>><?= $st['stop_time'] !== null ? Security::e(substr((string) $st['stop_time'], 0, 5)) : '—' ?></span>
            <?php if ($timeWarn): ?><span class="time-warn">⚠️ Out of sequence!</span><?php endif; ?>
          </span>
          <span class="stop-name"><?= Security::e((string) $st['stop_name']) ?></span>
          <?php if (!empty($st['landmark'])): ?>
            <span class="stop-landmark"<?= $ie('landmark', (string) $st['landmark'], 'text') ?>><?= Security::e((string) $st['landmark']) ?></span>
          <?php endif; ?>
          <span class="stop-badges">
            <?php if ($hasDetails): ?><span class="km-badge" title="Road km from the route start"><?= ($st['km_from_origin'] ?? null) !== null ? Security::e(number_format((float) $st['km_from_origin'], 0)) . ' km' : 'km —' ?></span><?php endif; ?>
            <?php if ((int) ($st['is_border'] ?? 0) === 1): ?><span class="border-badge">Border</span><?php endif; ?>
            <?php if ((int) ($st['is_meal_halt'] ?? 0) === 1): ?><span class="meal-badge">Meal</span><?php endif; ?>
            <?php if (!$stOn): ?><span class="off-badge">OFF</span><?php endif; ?>
          </span>
          <?php if ($priced):
            $fNow = ($st['fare'] ?? null) !== null ? (string) (int) round((float) $st['fare']) : ''; ?>
          <span class="stop-fare-now<?= $fNow === '' ? ' is-def' : '' ?>"<?= $ie('fare', $fNow, 'money', true) ?>><?= $fNow !== '' ? 'saved ' . Security::e(inr((float) $fNow)) : 'not set · default ' . Security::e(inr($defFare)) ?></span>
          <label class="stop-fare" title="Per-seat sharing fare for this pickup - blank = default <?= Security::e(inr($defFare)) ?>">₹
            <input type="number" form="<?= $fareForm ?>" name="fare[<?= (int) $st['id'] ?>]" min="1" max="100000" step="1" inputmode="numeric"
                   value="<?= $fNow ?>" placeholder="<?= (int) round($defFare) ?>" <?= $canEdit ? '' : 'readonly' ?>>
            <?php if ($canEdit): ?><input type="hidden" form="<?= $fareForm ?>" name="fare_was[<?= (int) $st['id'] ?>]" value="<?= $fNow ?>"><?php endif; ?>
          </label>
          <?php endif; ?>
          <?php if ($canEdit): ?>
          <div class="stop-controls">
            <?php if ($stopControls): ?>
            <form method="post"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>"><input type="hidden" name="action" value="toggle_stop"><input type="hidden" name="stop_id" value="<?= (int) $st['id'] ?>"><button class="btn stop-onoff <?= $stOn ? 'on' : 'offb' ?>" type="submit" title="<?= $stOn ? 'On sale - click to switch OFF' : 'Switched off - click to switch ON' ?>"><?= $stOn ? 'ON' : 'OFF' ?></button></form>
            <?php endif; ?>
            <?php if ($idx > 0): ?>
            <form method="post"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>"><input type="hidden" name="action" value="move_stop"><input type="hidden" name="stop_id" value="<?= (int) $st['id'] ?>"><input type="hidden" name="direction" value="up"><button class="btn" type="submit" title="Move up">▲</button></form>
            <?php endif; ?>
            <?php if ($idx < count($stops) - 1): ?>
            <form method="post"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>"><input type="hidden" name="action" value="move_stop"><input type="hidden" name="stop_id" value="<?= (int) $st['id'] ?>"><input type="hidden" name="direction" value="down"><button class="btn" type="submit" title="Move down">▼</button></form>
            <?php endif; ?>
            <button class="btn" type="button" title="Edit stop" onclick="toggleStopEdit(<?= (int) $st['id'] ?>)">✎</button>
            <?php if (!$stopControls): ?>
            <form method="post" onsubmit="return confirm('Remove stop <?= Security::e((string) $st['stop_name']) ?>?')"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>"><input type="hidden" name="action" value="delete_stop"><input type="hidden" name="stop_id" value="<?= (int) $st['id'] ?>"><button class="btn bad" type="submit" title="Delete">✕</button></form>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($canEdit): ?>
        <?php /* Inline edit — wires the existing edit_stop handler (Point 4:
                 change a boarding-point time/name in place, no delete + re-add). */ ?>
        <form method="post" class="stop-edit-form" id="stop-edit-<?= (int) $st['id'] ?>">
          <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>">
          <input type="hidden" name="action" value="edit_stop">
          <input type="hidden" name="stop_id" value="<?= (int) $st['id'] ?>">
          <div><label>Stop name</label><input type="text" name="stop_name" required maxlength="191" value="<?= Security::e((string) $st['stop_name']) ?>"></div>
          <div><label>Landmark</label><input type="text" name="landmark" maxlength="191" value="<?= Security::e((string) ($st['landmark'] ?? '')) ?>"></div>
          <div><label>Time (HH:MM)</label><input type="time" name="stop_time" value="<?= $st['stop_time'] !== null ? Security::e(substr((string) $st['stop_time'], 0, 5)) : '' ?>"></div>
          <div><label>Latitude</label><input type="number" name="latitude" step="any" min="-90" max="90" inputmode="decimal" value="<?= $st['latitude'] !== null ? Security::e(sprintf('%.6f', (float) $st['latitude'])) : '' ?>" placeholder="22.303900"></div>
          <div><label>Longitude</label><input type="number" name="longitude" step="any" min="-180" max="180" inputmode="decimal" value="<?= $st['longitude'] !== null ? Security::e(sprintf('%.6f', (float) $st['longitude'])) : '' ?>" placeholder="70.802200"></div>
          <div><label>&nbsp;</label><button class="btn" type="button" data-pick-map>📍 नक्सामा छान्नुहोस् · Pick on map</button></div>
          <?php if ($hasDetails): ?>
          <div><label>Road km from route start</label><input type="number" name="km" step="0.1" min="0" max="9999" inputmode="decimal" value="<?= ($st['km_from_origin'] ?? null) !== null ? Security::e((string) (float) $st['km_from_origin']) : '' ?>" placeholder="not measured"></div>
          <?php endif; ?>
          <div>
            <label>Flags</label>
            <div class="chk-row">
              <label><input type="checkbox" name="is_border" <?= (int) ($st['is_border'] ?? 0) === 1 ? 'checked' : '' ?>> Border</label>
              <label><input type="checkbox" name="is_meal_halt" <?= (int) ($st['is_meal_halt'] ?? 0) === 1 ? 'checked' : '' ?>> Meal</label>
            </div>
          </div>
          <div style="display:flex;gap:6px;align-items:end">
            <button class="btn ok" type="submit">Save</button>
            <button class="btn" type="button" onclick="toggleStopEdit(<?= (int) $st['id'] ?>)">Cancel</button>
          </div>
        </form>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php if ($priced && $canEdit): ?>
      <form method="post" id="<?= $fareForm ?>" class="stop-fare-save">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>">
        <input type="hidden" name="action" value="save_stop_fares">
        <input type="hidden" name="route_id" value="<?= $rid ?>">
        <input type="hidden" name="stop_type" value="<?= $type ?>">
        <button class="btn ok" type="submit">💾 Save <?= $type === 'drop' ? 'drop-point' : 'pickup' ?> fares</button>
        <small class="muted">blank = default <?= Security::e(inr($defFare)) ?> · new bookings only — existing tickets keep the price they were sold at</small>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <details id="add-<?= $rid ?>-<?= $type ?>">
      <summary style="font-size:13px;font-weight:700;cursor:pointer;color:var(--blue);padding:6px 0">+ Add <?= $type === 'drop' ? 'drop (ड्रप)' : 'pickup (पिकअप)' ?> stop</summary>
      <form method="post" class="stop-add-form">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>">
        <input type="hidden" name="action" value="add_stop">
        <input type="hidden" name="route_id" value="<?= $rid ?>">
        <input type="hidden" name="stop_type" value="<?= $type ?>">
        <div><label>Stop name</label><input type="text" name="stop_name" required maxlength="191" placeholder="e.g. Mehsana — Silver Complex"></div>
        <div><label>Landmark</label><input type="text" name="landmark" maxlength="191" placeholder="Optional landmark"></div>
        <div><label>Time (HH:MM)</label><input type="time" name="stop_time" placeholder="13:00"></div>
        <?php if ($priced): ?>
        <div><label>Fare ₹ / seat</label><input type="text" name="fare" inputmode="numeric" maxlength="12" placeholder="blank = default <?= Security::e(inr($defFare)) ?>"></div>
        <?php endif; ?>
        <div><label>Latitude</label><input type="number" name="latitude" step="any" min="-90" max="90" inputmode="decimal" placeholder="optional"></div>
        <div><label>Longitude</label><input type="number" name="longitude" step="any" min="-180" max="180" inputmode="decimal" placeholder="optional"></div>
        <div><label>&nbsp;</label><button class="btn" type="button" data-pick-map>📍 नक्सामा छान्नुहोस् · Pick on map</button></div>
        <?php if ($hasDetails): ?>
        <div><label>Road km from route start</label><input type="number" name="km" step="0.1" min="0" max="9999" inputmode="decimal" placeholder="optional"></div>
        <?php endif; ?>
        <div>
          <label>&nbsp;</label>
          <div class="chk-row">
            <label><input type="checkbox" name="is_border"> Border</label>
            <label><input type="checkbox" name="is_meal_halt"> Meal halt</label>
          </div>
          <button class="btn ok" type="submit" style="margin-top:6px">Add stop</button>
        </div>
      </form>
    </details>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php if (count($rtFolded) > 1): /* 26 Nov 2026 (fx2): one link that opens every folded (switched-off) route */ ?>
<p class="muted" style="margin:0 0 28px"><a class="btn ghost" href="?off=1#stops-<?= (int) $rtFolded[0] ?>" style="min-height:44px">बन्द रुटका सबै स्टप देखाउनुहोस् · Show stops of all <?= count($rtFolded) ?> switched-off routes</a></p>
<?php endif; ?>
<script>
function toggleStopEdit(id){
  var f = document.getElementById('stop-edit-' + id);
  if (f) { f.classList.toggle('open'); if (f.classList.contains('open')) { var i = f.querySelector('input[name="stop_name"]'); if (i) i.focus(); } }
}
function toggleHaltEdit(id){
  var f = document.getElementById('halt-edit-' + id);
  if (f) { f.classList.toggle('open'); if (f.classList.contains('open')) { var i = f.querySelector('input[name="name"]'); if (i) i.focus(); } }
}
/* 16 Nov 2026: the top "add a point" box - the type picks the route the
   booking card sells for that direction, and the fare hint follows it. */
(function () {
  var f = document.getElementById('npAddForm'); if (!f) return;
  var type = f.querySelector('#npType'), route = f.querySelector('#npRoute'), fare = f.querySelector('#npFare');
  type.addEventListener('change', function () {
    var def = f.getAttribute('data-def-' + type.value);
    if (def && def !== '0') { route.value = def; }
    if (fare) { fare.placeholder = 'blank = default ' + (fare.getAttribute('data-def-' + type.value) || ''); }
  });
})();
/* 15 Nov 2026 (Mehsana fare report): a ₹ typed beside a stop belongs to the
   "💾 Save … fares" form under its list. Any other button (✎ Save, ON/OFF,
   ▲▼) reloads the page and drops the typed fare - say so first. */
document.addEventListener('submit', function (e) {
  var dirty = Array.prototype.filter.call(document.querySelectorAll('.stop-fare input[type="number"]'), function (i) { return i.value !== i.defaultValue; });
  if (!dirty.length) return;
  var own = e.target.id && dirty.every(function (i) { return i.getAttribute('form') === e.target.id; });
  if (!own && !confirm('The fare you typed is NOT saved yet - press "💾 Save pickup fares" under that list first.\n\nतपाईंले लेखेको भाडा सेभ भएको छैन। Continue and lose it?')) { e.preventDefault(); e.stopPropagation(); }
}, true);
/* C2: when a saved-fare tag is changed by the click-to-edit box, keep the ₹
   box of the batch form in step (its fare_was too), so a later
   "💾 Save fares" press never puts the old price back. */
if (window.MutationObserver) {
  document.querySelectorAll('.stop-fare-now[data-ie-id]').forEach(function (tag) {
    new MutationObserver(function () {
      var id = tag.getAttribute('data-ie-id'), v = tag.getAttribute('data-ie-value') || '';
      var box = document.querySelector('.stop-fare input[name="fare[' + id + ']"]'), was = document.querySelector('input[name="fare_was[' + id + ']"]');
      if (box && box.value === box.defaultValue) { box.value = v; box.defaultValue = v; }
      if (was) { was.value = v; }
      tag.classList.toggle('is-def', v === '');
    }).observe(tag, { attributes: true, attributeFilter: ['data-ie-value'] });
  });
  /* ...and the ✎ form of that stop: its time / landmark boxes were drawn with
     the old value, so a later ✎ Save would quietly put the old one back. */
  document.querySelectorAll('[data-ie-entity="route_stop"][data-ie-field="stop_time"],[data-ie-entity="route_stop"][data-ie-field="landmark"]').forEach(function (el) {
    new MutationObserver(function () {
      var f = el.getAttribute('data-ie-field'), v = el.getAttribute('data-ie-value') || '';
      var inp = document.querySelector('#stop-edit-' + el.getAttribute('data-ie-id') + ' input[name="' + f + '"]');
      if (inp && inp.value === inp.defaultValue) { inp.value = v; inp.defaultValue = v; }
    }).observe(el, { attributes: true, attributeFilter: ['data-ie-value'] });
  });
}
/* P4-3b (16 Nov 2026): "📍 Pick on map" beside latitude / longitude. Opens the
   vendored MapLibre (assets/vendor, unpkg only if that fails) in a box; a tap
   drops the pin and fills both inputs of THAT form. Same style fallback as
   admin/map.php: OpenFreeMap first, the plain OpenStreetMap raster if not. */
(function () {
  var btns = document.querySelectorAll('[data-pick-map]');
  if (!btns.length) return;
  var MLG = '4.7.1', OWN = '/assets/vendor/maplibre-gl-' + MLG + '/', CDN = 'https://unpkg.com/maplibre-gl@' + MLG + '/dist/';
  var OSM = { version: 8, sources: { osm: { type: 'raster', tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'], tileSize: 256, maxzoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors' } },
    layers: [{ id: 'osm', type: 'raster', source: 'osm' }] };
  var modal = null, map = null, marker = null, target = null, picked = null, libP = null;
  function loadLib() {
    if (libP) return libP;
    libP = new Promise(function (res, rej) {
      if (window.maplibregl) return res();
      var css = document.createElement('link'); css.rel = 'stylesheet'; css.href = OWN + 'maplibre-gl.css';
      css.onerror = function () { css.onerror = null; css.href = CDN + 'maplibre-gl.css'; };
      document.head.appendChild(css);
      function add(src, last) {
        var s = document.createElement('script'); s.src = src;
        s.onload = function () { if (window.maplibregl) res(); else if (!last) add(CDN + 'maplibre-gl.js', true); else rej(); };
        s.onerror = function () { if (!last) add(CDN + 'maplibre-gl.js', true); else rej(); };
        document.head.appendChild(s);
      }
      add(OWN + 'maplibre-gl.js', false);
    });
    return libP;
  }
  function pickStyle() {
    return new Promise(function (res) {
      var done = false, url = 'https://tiles.openfreemap.org/styles/liberty';
      var tm = setTimeout(function () { if (!done) { done = true; res(OSM); } }, 4000);
      fetch(url, { mode: 'cors' }).then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) { if (!done) { done = true; clearTimeout(tm); res(j && j.layers ? url : OSM); } })
        .catch(function () { if (!done) { done = true; clearTimeout(tm); res(OSM); } });
    });
  }
  function build() {
    modal = document.createElement('div');
    modal.className = 'mp-modal'; modal.hidden = true;
    modal.innerHTML = '<div class="mp-box" role="dialog" aria-modal="true" aria-label="Pick on map">'
      + '<div class="mp-head"><b>📍 नक्सामा छान्नुहोस् · Tap the exact pickup spot</b><button type="button" class="btn" data-mp-close>✕</button></div>'
      + '<div class="mp-map" id="mpMap"></div>'
      + '<div class="mp-foot"><span class="mp-out muted">Tap the map to drop the pin.</span>'
      + '<span style="display:flex;gap:8px"><button type="button" class="btn" data-mp-close>Cancel</button><button type="button" class="btn ok" data-mp-use disabled>✓ Use this point</button></span></div></div>';
    document.body.appendChild(modal);
    modal.addEventListener('click', function (e) { if (e.target === modal || e.target.closest('[data-mp-close]')) close(); });
    modal.querySelector('[data-mp-use]').addEventListener('click', function () {
      if (!picked || !target) return;
      var lat = target.querySelector('input[name="latitude"]'), lng = target.querySelector('input[name="longitude"]');
      if (lat) { lat.value = picked.lat.toFixed(7); lat.dispatchEvent(new Event('input', { bubbles: true })); }
      if (lng) { lng.value = picked.lng.toFixed(7); lng.dispatchEvent(new Event('input', { bubbles: true })); }
      close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal && !modal.hidden) close(); });
  }
  function close() { if (modal) modal.hidden = true; }
  function setPin(ll) {
    picked = { lat: ll.lat, lng: ll.lng };
    // MapLibre writes its own transform on the marker element, so the rotated pin sits inside a wrapper
    if (!marker) { var el = document.createElement('div'); el.innerHTML = '<div class="mp-pin"></div>'; marker = new maplibregl.Marker({ element: el, anchor: 'bottom' }); }
    marker.setLngLat([ll.lng, ll.lat]).addTo(map);
    modal.querySelector('.mp-out').textContent = picked.lat.toFixed(7) + ', ' + picked.lng.toFixed(7);
    modal.querySelector('[data-mp-use]').disabled = false;
  }
  function open(form) {
    target = form; picked = null;
    if (!modal) build();
    modal.hidden = false;
    modal.querySelector('[data-mp-use]').disabled = true;
    var lat = parseFloat((form.querySelector('input[name="latitude"]') || {}).value), lng = parseFloat((form.querySelector('input[name="longitude"]') || {}).value);
    var has = isFinite(lat) && isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180;
    modal.querySelector('.mp-out').textContent = 'Loading map…';
    Promise.all([loadLib(), map ? Promise.resolve(null) : pickStyle()]).then(function (r) {
      if (!map) {
        // Gujarat -> Rupaidiha corridor
        map = new maplibregl.Map({ container: 'mpMap', style: r[1], center: [76.5, 25.5], zoom: 4.6, attributionControl: { compact: true } });
        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        map.on('click', function (e) { setPin(e.lngLat); });
      }
      setTimeout(function () { map.resize(); }, 50);
      if (has) { setPin({ lat: lat, lng: lng }); map.jumpTo({ center: [lng, lat], zoom: 14 }); }
      else { if (marker) marker.remove(); modal.querySelector('.mp-out').textContent = 'Tap the map to drop the pin (zoom in with + or two fingers).'; }
    }).catch(function () {
      modal.querySelector('.mp-out').textContent = 'The map could not load - type the latitude / longitude instead (Google Maps: long-press the spot, copy the numbers).';
    });
  }
  btns.forEach(function (b) { b.addEventListener('click', function () { var f = b.closest('form'); if (f) open(f); }); });
})();
/* 19 Nov 2026 (TK-2): "paste a Google Maps link" beside latitude / longitude - the
   office copies the share link (or the numbers) from Google Maps and both boxes
   fill with 6 decimals (~10 cm). Understands @lat,lng / q= / query= / ll= /
   !3d..!4d.. / a plain "lat, lng". A short maps.app.goo.gl link has no numbers in
   it: open it once, then copy the long link from the address bar. */
(function () {
  function pick(t) {
    t = String(t || '').trim();
    try { t = decodeURIComponent(t); } catch (e) {}
    var m;
    var pats = [/!3d(-?\d{1,3}\.\d+)!4d(-?\d{1,3}\.\d+)/, /@(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/,
                /[?&](?:q|query|ll|destination|center)=(-?\d{1,3}\.\d+),\s*(-?\d{1,3}\.\d+)/,
                /\/place\/(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/, /^(-?\d{1,3}\.\d+)\s*[, ]\s*(-?\d{1,3}\.\d+)$/];
    for (var i = 0; i < pats.length; i++) {
      m = t.match(pats[i]);
      if (m) {
        var la = parseFloat(m[1]), ln = parseFloat(m[2]);
        if (isFinite(la) && isFinite(ln) && Math.abs(la) <= 90 && Math.abs(ln) <= 180 && !(la === 0 && ln === 0)) return [la, ln];
      }
    }
    return null;
  }
  window.shgPickLatLng = pick;
  document.querySelectorAll('form').forEach(function (f) {
    var lat = f.querySelector('input[name="latitude"]'), lng = f.querySelector('input[name="longitude"]');
    if (!lat || !lng || f.querySelector('[data-maps-paste]')) return;
    var box = document.createElement('div');
    box.innerHTML = '<label>Google Maps link · नक्सा लिंक</label><input type="text" data-maps-paste inputmode="url" autocomplete="off" placeholder="paste a Google Maps link or lat, lng"><small class="muted" data-maps-paste-out style="display:block;font-size:11.5px;margin-top:3px"></small>';
    var holder = lng.closest('div') || lng.parentNode;
    holder.parentNode.insertBefore(box, holder.nextSibling);
    var inp = box.querySelector('input'), out = box.querySelector('small');
    inp.addEventListener('input', function () {
      if (inp.value.trim() === '') { out.textContent = ''; return; }
      var ll = pick(inp.value);
      if (!ll) {
        out.textContent = /goo\.gl|maps\.app/i.test(inp.value) ? 'Short link: open it, then copy the long link from the address bar.' : 'No position found in this text.';
        return;
      }
      lat.value = ll[0].toFixed(6); lng.value = ll[1].toFixed(6);
      lat.dispatchEvent(new Event('input', { bubbles: true })); lng.dispatchEvent(new Event('input', { bubbles: true }));
      out.textContent = '✓ ' + lat.value + ', ' + lng.value;
    });
  });
})();
</script>
<?php if (Auth::can('routes.edit')): ?>
<script src="<?= e(admin_asset_url('assets/js/admin-inline-edit.js')) ?>" defer></script>
<?php endif; ?>
<?php
admin_footer();
