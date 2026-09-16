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
                ['Surat',                          'Departure',      '13:00:00', 21.1702, 72.8311, 1],
                ['Baroda',                         null,             '17:00:00', 22.3072, 73.1812, 2],
                ['Emli Bhupal',                    null,             '19:00:00', null,    null,    3],
                ['S Hari Parking, Nana Chiloda',   null,             '21:00:00', 23.1710, 72.6230, 4],
                ['Mehsana — Silver Complex',       null,             '23:00:00', 23.5880, 72.3693, 5],
            ];

            Database::transaction(static function () use ($OUT, $RET, $stops): void {
                Database::query('UPDATE routes SET is_active = 0 WHERE id NOT IN (:a, :b)', ['a' => $OUT, 'b' => $RET]);
                Database::query('UPDATE routes SET is_active = 1 WHERE id IN (:c, :d)',     ['c' => $OUT, 'd' => $RET]);
                Database::query("UPDATE routes SET from_city='Surat', to_city='Rupaidiha', dep_time='13:00:00', duration_text='' WHERE id=:i", ['i' => $OUT]);
                Database::query("UPDATE routes SET from_city='Rupaidiha', to_city='Surat', dep_time='18:00:00', duration_text='' WHERE id=:i", ['i' => $RET]);

                Database::query("DELETE FROM route_stops WHERE route_id=:i AND stop_type='boarding'", ['i' => $OUT]);
                foreach ($stops as $st) {
                    Database::insert('route_stops', [
                        'route_id' => $OUT, 'stop_type' => 'boarding', 'stop_name' => $st[0],
                        'landmark' => $st[1], 'stop_time' => $st[2], 'latitude' => $st[3], 'longitude' => $st[4],
                        'is_border' => 0, 'is_meal_halt' => 0, 'sort_order' => $st[5],
                    ]);
                }
                Database::query("DELETE FROM route_stops WHERE route_id=:i AND stop_type='drop'", ['i' => $OUT]);
                Database::insert('route_stops', [
                    'route_id' => $OUT, 'stop_type' => 'drop', 'stop_name' => 'Rupaidiha',
                    'landmark' => 'India-Nepal border checkpoint', 'stop_time' => null,
                    'latitude' => 28.0600, 'longitude' => 81.6170, 'is_border' => 1, 'is_meal_halt' => 0, 'sort_order' => 1,
                ]);

                Database::query("DELETE FROM route_stops WHERE route_id=:i AND stop_type='boarding'", ['i' => $RET]);
                Database::insert('route_stops', [
                    'route_id' => $RET, 'stop_type' => 'boarding', 'stop_name' => 'Rupaidiha',
                    'landmark' => 'India-Nepal border checkpoint', 'stop_time' => '18:00:00',
                    'latitude' => 28.0600, 'longitude' => 81.6170, 'is_border' => 1, 'is_meal_halt' => 0, 'sort_order' => 1,
                ]);
                Database::query("DELETE FROM route_stops WHERE route_id=:i AND stop_type='drop'", ['i' => $RET]);
                $ord = 1;
                foreach (array_reverse($stops) as $st) {
                    Database::insert('route_stops', [
                        'route_id' => $RET, 'stop_type' => 'drop', 'stop_name' => $st[0],
                        'landmark' => $st[1], 'stop_time' => null, 'latitude' => $st[3], 'longitude' => $st[4],
                        'is_border' => 0, 'is_meal_halt' => 0, 'sort_order' => $ord++,
                    ]);
                }
            });

            Logger::audit('routes.daily_setup', 'routes', (string) $OUT, null,
                ['outbound' => $OUT, 'return' => $RET], 'Daily service applied from Admin - Routes');
            $flash = ['ok', 'Daily service set: one sleeper out (13:00) and one back (18:00), with the five permanent stops.'];
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
            $landmark = Security::clean($_POST['landmark'] ?? '', 191);
            $time     = trim($_POST['stop_time'] ?? '');
            if ($name === '') { throw new RuntimeException('Stop name is required.'); }

            // Validate time format if provided
            $timeVal = null;
            if ($time !== '') {
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time) !== 1) {
                    throw new RuntimeException('Enter stop time as HH:MM.');
                }
                $timeVal = strlen($time) === 5 ? $time . ':00' : $time;
            }

            // Get the next sort_order for this route+type
            $maxOrder = (int) Database::scalar(
                'SELECT COALESCE(MAX(sort_order), 0) FROM route_stops WHERE route_id = :r AND stop_type = :t',
                ['r' => $rid, 't' => $stopType], 0
            );

            Database::insert('route_stops', [
                'route_id'     => $rid,
                'stop_type'    => $stopType,
                'stop_name'    => $name,
                'landmark'     => $landmark !== '' ? $landmark : null,
                'stop_time'    => $timeVal,
                'latitude'     => null,
                'longitude'    => null,
                'is_border'    => !empty($_POST['is_border']) ? 1 : 0,
                'is_meal_halt' => !empty($_POST['is_meal_halt']) ? 1 : 0,
                'sort_order'   => $maxOrder + 1,
            ]);

            Logger::audit('route_stop.add', 'routes', (string) $rid, null,
                ['stop' => $name, 'type' => $stopType, 'time' => $timeVal],
                'Added ' . $stopType . ' stop: ' . $name);
            $flash = ['ok', 'Added ' . $stopType . ' stop: ' . $name . '.'];
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

            Database::update('route_stops', [
                'stop_name'    => $name,
                'landmark'     => $landmark !== '' ? $landmark : null,
                'stop_time'    => $timeVal,
                'is_border'    => !empty($_POST['is_border']) ? 1 : 0,
                'is_meal_halt' => !empty($_POST['is_meal_halt']) ? 1 : 0,
            ], 'id = :id', ['id' => $stopId]);

            Logger::audit('route_stop.edit', 'routes', (string) ($old['route_id'] ?? 0),
                ['stop' => $old['stop_name'], 'time' => $old['stop_time']],
                ['stop' => $name, 'time' => $timeVal],
                'Edited stop: ' . $old['stop_name'] . ' → ' . $name);
            $flash = ['ok', 'Stop updated: ' . $name . '.'];
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
      <b>one back at 18:00</b>, with the five permanent stops
      (Surat &middot; Baroda &middot; S Hari Parking &middot; Nana Chiloda &middot;
      Mehsana) and <b>Rupaidiha as the last point</b>.
      Every other route is switched off &mdash; nothing is deleted, and no booking, ticket
      or payment is touched. Reversible: switch a route back on any time.
    </p>
    <form method="post" onsubmit="return confirm('Set one daily coach each way and switch every other route off? Nothing is deleted and this can be reversed.')" style="display:inline-block;margin-right:8px">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::e(Security::csrfToken()) ?>">
      <input type="hidden" name="action" value="daily_setup">
      <button class="btn" type="submit">Apply daily service</button>
    </form>
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
      $avail = Seats::availability((int) $r['id'], $today, $r['coach_type'] === 'sleeper' ? 'sharing' : 'seater');
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
        <td data-label="Seats free today"><strong><?= (int) ($avail['availableCount'] ?? 0) ?></strong><span class="muted"> / <?= (int) ($avail['total'] ?? 0) ?></span></td>
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
.stop-node .stop-badges .border-badge{background:#fff4d1;color:#8a6d00}
.stop-node .stop-badges .meal-badge{background:#e6f7e6;color:#0a6b3b}
:root[data-theme="dark"] .stop-node .stop-badges .border-badge{background:#4a3d00;color:#ffe28a}
:root[data-theme="dark"] .stop-node .stop-badges .meal-badge{background:#0e3d0e;color:#8fe68f}
.time-warn{color:#c00;font-size:11px;font-weight:700;margin-left:4px}

/* ── Stop controls ── */
.stop-controls{display:flex;gap:4px;margin-left:auto}
.stop-controls form{margin:0}
.stop-controls .btn{font-size:11px;padding:3px 8px;min-height:32px}
.stop-add-form{display:grid;grid-template-columns:1fr 1fr auto auto;gap:8px;align-items:end;padding:12px 0;border-top:1px solid var(--line);margin-top:8px}
.stop-edit-form{display:none;grid-template-columns:1fr 1fr auto;gap:8px;align-items:end;padding:10px 12px;margin:2px 0 6px;background:var(--hover);border:1px dashed var(--line);border-radius:10px}
.stop-edit-form.open{display:grid}
.stop-edit-form label{font-size:11px;color:var(--mut);display:block;margin-bottom:2px}
.stop-edit-form input[type=text],.stop-edit-form input[type=time]{width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px}
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

<?php foreach ($routes as $r):
  $rid = (int) $r['id'];
  $boarding = $routeStops[$rid]['boarding'] ?? [];
  $drops    = $routeStops[$rid]['drop'] ?? [];
  $canEdit  = Auth::can('routes.edit');
  $csrf_val = Security::e(Security::csrfToken());
?>
<div class="panel stop-editor" id="stops-<?= $rid ?>">
  <h2><?= Security::e($r['route_code'] . ' · ' . $r['from_city'] . ' → ' . $r['to_city']) ?> — Stops</h2>

  <?php foreach (['boarding' => $boarding, 'drop' => $drops] as $type => $stops): ?>
  <div style="padding:0 18px 18px">
    <h3>
      <?= $type === 'boarding' ? '🟢 Boarding points / चढ्ने ठाउँ' : '🔴 Drop points / ओर्लिने ठाउँ' ?>
      <span class="muted" style="font-weight:400;font-size:13px">(<?= count($stops) ?>)</span>
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
        <div class="stop-node">
          <span class="stop-time">
            <?= $st['stop_time'] !== null ? Security::e(substr((string) $st['stop_time'], 0, 5)) : '—' ?>
            <?php if ($timeWarn): ?><span class="time-warn">⚠️ Out of sequence!</span><?php endif; ?>
          </span>
          <span class="stop-name"><?= Security::e((string) $st['stop_name']) ?></span>
          <?php if (!empty($st['landmark'])): ?>
            <span class="stop-landmark"><?= Security::e((string) $st['landmark']) ?></span>
          <?php endif; ?>
          <span class="stop-badges">
            <?php if ((int) ($st['is_border'] ?? 0) === 1): ?><span class="border-badge">Border</span><?php endif; ?>
            <?php if ((int) ($st['is_meal_halt'] ?? 0) === 1): ?><span class="meal-badge">Meal</span><?php endif; ?>
          </span>
          <?php if ($canEdit): ?>
          <div class="stop-controls">
            <?php if ($idx > 0): ?>
            <form method="post"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>"><input type="hidden" name="action" value="move_stop"><input type="hidden" name="stop_id" value="<?= (int) $st['id'] ?>"><input type="hidden" name="direction" value="up"><button class="btn" type="submit" title="Move up">▲</button></form>
            <?php endif; ?>
            <?php if ($idx < count($stops) - 1): ?>
            <form method="post"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>"><input type="hidden" name="action" value="move_stop"><input type="hidden" name="stop_id" value="<?= (int) $st['id'] ?>"><input type="hidden" name="direction" value="down"><button class="btn" type="submit" title="Move down">▼</button></form>
            <?php endif; ?>
            <button class="btn" type="button" title="Edit stop" onclick="toggleStopEdit(<?= (int) $st['id'] ?>)">✎</button>
            <form method="post" onsubmit="return confirm('Remove stop <?= Security::e((string) $st['stop_name']) ?>?')"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>"><input type="hidden" name="action" value="delete_stop"><input type="hidden" name="stop_id" value="<?= (int) $st['id'] ?>"><button class="btn bad" type="submit" title="Delete">✕</button></form>
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
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <details>
      <summary style="font-size:13px;font-weight:700;cursor:pointer;color:var(--blue);padding:6px 0">+ Add <?= $type ?> stop</summary>
      <form method="post" class="stop-add-form">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf_val ?>">
        <input type="hidden" name="action" value="add_stop">
        <input type="hidden" name="route_id" value="<?= $rid ?>">
        <input type="hidden" name="stop_type" value="<?= $type ?>">
        <div><label>Stop name</label><input type="text" name="stop_name" required maxlength="191" placeholder="e.g. Mehsana — Silver Complex"></div>
        <div><label>Landmark</label><input type="text" name="landmark" maxlength="191" placeholder="Optional landmark"></div>
        <div><label>Time (HH:MM)</label><input type="time" name="stop_time" placeholder="13:00"></div>
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
<script>
function toggleStopEdit(id){
  var f = document.getElementById('stop-edit-' + id);
  if (f) { f.classList.toggle('open'); if (f.classList.contains('open')) { var i = f.querySelector('input[name="stop_name"]'); if (i) i.focus(); } }
}
</script>
<?php
admin_footer();
