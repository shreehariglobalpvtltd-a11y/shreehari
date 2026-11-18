<?php
/**
 * =====================================================================
 *  StopTiming — when did the coach REALLY reach each stop? (19 Nov 2026,
 *  unit u8 / AI-4)
 *
 *  Read-and-derive only. Nothing here moves a coach, alerts a passenger or
 *  changes a timetable.
 *
 *  recordTrip($tripId)  reads one live trip's GPS trail (trip_locations, in
 *      time order), places every usable fix on the route (EtaAlerts::
 *      routeLine/locate — road km on the stored Valhalla line, else the
 *      line of stops) and, for each stop with coordinates and each halt
 *      with a km, finds the first time the coach got there:
 *        - a fix within 300 m (along the road) of the stop counts at once;
 *        - otherwise the two fixes either side of it are interpolated, but
 *          only when they are at most 20 min apart (a longer GPS gap → no
 *          row; a guess is worse than nothing);
 *        - fixes over 150 m accuracy or more than 2 km off the route are
 *          ignored, and a fix that jumps back more than 1 km is noise;
 *        - dwell: the fixes that stay within 200 m of the stop at under
 *          5 km/h give left_at.
 *      A driver's "stop reached" tap (driver_events) is stored as source
 *      'driver'. Rows go to trip_stop_times (INSERT IGNORE: a re-run adds
 *      nothing).
 *  learn($routeId)  over the last stop_learn_trips trips, per stop / halt
 *      with at least stop_learn_min samples: median and p80 offset from
 *      the scheduled departure, median dwell, and a suggested clock time
 *      rounded to 5 minutes (+ day offset) → route_stop_learned.
 *      Boarding stops are learned on the DEPARTURE (left_at, the arrival
 *      when the coach did not wait); at the origin a pass without a left_at
 *      is the depot start of tracking and is not used (fix of 19 Nov).
 *      Drop stops and halts are learned on the arrival.
 *      route_stops / route_halts are NEVER written: the office presses
 *      "Use this time" on admin/map.php.
 *  nightly()  cron/stop-timing.php: trips ended in the last 3 days with no
 *      rows yet, then learn() for their routes. Switch stop_learn_on
 *      (default 1 — it only writes its own two tables).
 *
 *  Never throws at its caller.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/etaalerts.php';

final class StopTiming
{
    public const MAX_ACC_M     = 150;
    public const OFF_ROUTE_KM  = 2.0;
    public const NEAR_KM       = 0.3;
    public const MAX_GAP_SEC   = 1200;
    public const DWELL_KM      = 0.2;
    public const DWELL_KMH     = 5.0;
    public const BACK_NOISE_KM = 1.0;
    public const TRAIL_READ    = 60000;

    private function __construct() {}

    public static function enabled(): bool
    {
        return Settings::getBool('stop_learn_on', true);
    }

    public static function ready(): bool
    {
        try {
            return (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                  AND table_name IN ('trip_stop_times','route_stop_learned')", [], 0) === 2;
        } catch (Throwable $e) {
            return false;
        }
    }

    /* =================================================================
     *  One trip
     * ================================================================= */

    /**
     * @return array{trip: int, fixes: int, used: int, stored: int, driver: int, note: string}
     */
    public static function recordTrip(int $tripId): array
    {
        $out = ['trip' => $tripId, 'fixes' => 0, 'used' => 0, 'stored' => 0, 'driver' => 0, 'note' => ''];
        try {
            $trip = Database::fetch(
                'SELECT lt.id, lt.route_id, lt.schedule_id, lt.departure_at, s.travel_date,
                        COALESCE(s.dep_time_override, r.dep_time) AS dep_time
                   FROM live_trips lt
                   LEFT JOIN schedules s ON s.id = lt.schedule_id
                   LEFT JOIN routes r ON r.id = COALESCE(s.route_id, lt.route_id)
                  WHERE lt.id = :t', ['t' => $tripId]);
            if ($trip === null || (int) $trip['route_id'] <= 0) {
                $out['note'] = 'no such trip';
                return $out;
            }
            $routeId = (int) $trip['route_id'];
            $depTs = $trip['travel_date'] !== null && $trip['dep_time'] !== null
                ? strtotime($trip['travel_date'] . ' ' . $trip['dep_time'])
                : ($trip['departure_at'] !== null ? strtotime((string) $trip['departure_at']) : false);
            if ($depTs === false) {
                $out['note'] = 'no departure time';
                return $out;
            }
            $out['driver'] = self::driverTaps($trip, $depTs);

            $geo = EtaAlerts::routeLine($routeId);
            if (count($geo['line']) < 2) {
                $out['note'] = 'route has fewer than two stops with coordinates';
                return $out;
            }
            $targets = self::targets($routeId, $geo);
            $rows = Database::fetchAll(
                'SELECT latitude, longitude, speed_kmh, accuracy_m, recorded_at FROM trip_locations
                  WHERE trip_id = :t ORDER BY recorded_at, seq, id LIMIT ' . self::TRAIL_READ, ['t' => $tripId]);
            $out['fixes'] = count($rows);
            $fixes = self::place($geo, $rows);
            $out['used'] = count($fixes);
            if (count($fixes) < 2 || $targets === []) {
                $out['note'] = $targets === [] ? 'no stop to match' : 'too few usable fixes';
                return $out;
            }
            foreach ($targets as $t) {
                $pass = self::passOf($fixes, (float) $t['km']);
                if ($pass === null) {
                    continue;
                }
                $out['stored'] += self::insert($trip, $routeId, $t['kind'], (int) $t['ref'], $depTs, $pass['reached'], $pass['left'], 'gps', $pass['dist_m']);
            }
        } catch (Throwable $e) {
            Logger::warning('Stop timing: trip failed: ' . $e->getMessage(), ['trip' => $tripId], 'gps');
            $out['note'] = 'error';
        }
        return $out;
    }

    /**
     * Stops (with coordinates, placed on the route) and halts (with a km).
     *
     * @return list<array{kind: string, ref: int, km: float}>
     */
    public static function targets(int $routeId, array $geo): array
    {
        $out = [];
        foreach (Database::fetchAll(
            "SELECT id, latitude, longitude FROM route_stops WHERE route_id = :r AND is_active = 1
                AND latitude IS NOT NULL AND longitude IS NOT NULL", ['r' => $routeId]) as $s) {
            if ((float) $s['latitude'] === 0.0 && (float) $s['longitude'] === 0.0) {
                continue;
            }
            $p = EtaAlerts::locate($geo, (float) $s['latitude'], (float) $s['longitude']);
            if ($p !== null && $p['off'] <= self::OFF_ROUTE_KM) {
                $out[] = ['kind' => 'stop', 'ref' => (int) $s['id'], 'km' => (float) $p['km']];
            }
        }
        try {
            foreach (Database::fetchAll(
                'SELECT id, km_from_origin FROM route_halts WHERE route_id = :r AND is_active = 1 AND km_from_origin > 0', ['r' => $routeId]) as $h) {
                $out[] = ['kind' => 'halt', 'ref' => (int) $h['id'], 'km' => (float) $h['km_from_origin']];
            }
        } catch (Throwable $e) {
            // halts table not installed: stops alone
        }
        usort($out, static fn($a, $b) => $a['km'] <=> $b['km']);
        return $out;
    }

    /**
     * Usable fixes on the route scale, in time order.
     *
     * @return list<array{ts: int, km: float, kmh: ?float}>
     */
    public static function place(array $geo, array $rows): array
    {
        $out = [];
        $max = -INF;
        foreach ($rows as $r) {
            if ($r['accuracy_m'] !== null && (float) $r['accuracy_m'] > self::MAX_ACC_M) {
                continue;
            }
            $p = EtaAlerts::locate($geo, (float) $r['latitude'], (float) $r['longitude']);
            if ($p === null || $p['off'] > self::OFF_ROUTE_KM) {
                continue;
            }
            if ($p['km'] < $max - self::BACK_NOISE_KM) {
                continue;                          // a jump backwards: GPS noise
            }
            $max = max($max, (float) $p['km']);
            $out[] = ['ts' => (int) strtotime((string) $r['recorded_at']), 'km' => (float) $p['km'],
                      'kmh' => $r['speed_kmh'] !== null ? (float) $r['speed_kmh'] : null];
        }
        return $out;
    }

    /**
     * The first pass of $km in $fixes (time order), or null.
     *
     * @param list<array{ts: int, km: float, kmh: ?float}> $fixes
     * @return array{reached: int, left: ?int, dist_m: int}|null
     */
    public static function passOf(array $fixes, float $km): ?array
    {
        $n = count($fixes);
        $at = null;
        $reached = null;
        $dist = null;
        for ($i = 0; $i < $n; $i++) {
            $d = abs($fixes[$i]['km'] - $km);
            if ($d <= self::NEAR_KM) {                                   // a fix at the stop
                $at = $i;
                $reached = $fixes[$i]['ts'];
                $dist = $d;
                if ($i > 0 && $fixes[$i - 1]['km'] < $km - self::NEAR_KM
                    && $fixes[$i]['ts'] - $fixes[$i - 1]['ts'] <= self::MAX_GAP_SEC && $fixes[$i]['km'] > $fixes[$i - 1]['km']) {
                    // reached a little before this fix: interpolate to the stop itself
                    $a = $fixes[$i - 1]; $b = $fixes[$i];
                    $f = max(0.0, min(1.0, ($km - $a['km']) / ($b['km'] - $a['km'])));
                    $reached = (int) round($a['ts'] + $f * ($b['ts'] - $a['ts']));
                }
                break;
            }
            if ($fixes[$i]['km'] > $km) {                                 // went past it between two fixes
                if ($i === 0) {
                    return null;                                         // passed before tracking began
                }
                $a = $fixes[$i - 1]; $b = $fixes[$i];
                if ($b['ts'] - $a['ts'] > self::MAX_GAP_SEC || $b['km'] <= $a['km']) {
                    return null;                                         // a GPS gap across the stop
                }
                $f = ($km - $a['km']) / ($b['km'] - $a['km']);
                $reached = (int) round($a['ts'] + $f * ($b['ts'] - $a['ts']));
                $dist = min(abs($a['km'] - $km), abs($b['km'] - $km));
                $at = $i;
                break;
            }
        }
        if ($reached === null) {
            return null;
        }
        // Dwell: the fixes that stay at the stop, slow.
        $left = null;
        for ($j = $at; $j < $n; $j++) {
            $slow = $fixes[$j]['kmh'] === null || $fixes[$j]['kmh'] < self::DWELL_KMH;
            if (abs($fixes[$j]['km'] - $km) <= self::DWELL_KM && $slow) {
                $left = $fixes[$j]['ts'];
                continue;
            }
            if ($j > $at) {
                break;
            }
        }
        if ($left !== null && $left - $reached < 60) {
            $left = null;
        }
        return ['reached' => $reached, 'left' => $left, 'dist_m' => (int) min(65000, round((float) $dist * 1000))];
    }

    /** Driver "stop reached" taps on this trip → source 'driver'. */
    private static function driverTaps(array $trip, int $depTs): int
    {
        $n = 0;
        try {
            $rows = Database::fetchAll(
                "SELECT stop_name, MIN(created_at) AS at FROM driver_events
                  WHERE live_trip_id = :t AND kind = 'stop_reached' AND stop_name IS NOT NULL GROUP BY stop_name",
                ['t' => (int) $trip['id']]);
        } catch (Throwable $e) {
            return 0;
        }
        foreach ($rows as $r) {
            $stop = Database::fetch('SELECT id FROM route_stops WHERE route_id = :r AND stop_name = :n ORDER BY is_active DESC, id LIMIT 1',
                ['r' => (int) $trip['route_id'], 'n' => (string) $r['stop_name']]);
            if ($stop !== null) {
                $n += self::insert($trip, (int) $trip['route_id'], 'stop', (int) $stop['id'], $depTs, (int) strtotime((string) $r['at']), null, 'driver', null);
            }
        }
        return $n;
    }

    private static function insert(array $trip, int $routeId, string $kind, int $ref, int $depTs, int $reached, ?int $left, string $source, ?int $distM): int
    {
        return Database::run(
            'INSERT IGNORE INTO trip_stop_times (trip_id, schedule_id, route_id, stop_kind, stop_ref, dep_at, reached_at, left_at, offset_min, source, dist_m)
             VALUES (:t, :s, :r, :k, :ref, :dep, :re, :le, :off, :src, :d)',
            ['t' => (int) $trip['id'], 's' => $trip['schedule_id'] !== null ? (int) $trip['schedule_id'] : null, 'r' => $routeId,
             'k' => $kind, 'ref' => $ref, 'dep' => date('Y-m-d H:i:s', $depTs), 're' => date('Y-m-d H:i:s', $reached),
             'le' => $left !== null ? date('Y-m-d H:i:s', $left) : null, 'off' => (int) round(($reached - $depTs) / 60),
             'src' => $source, 'd' => $distM]
        );
    }

    /* =================================================================
     *  Learning
     * ================================================================= */

    /** Nearest-rank percentile of a sorted list. */
    public static function percentile(array $sorted, float $p): int
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0;
        }
        $idx = (int) max(0, min($n - 1, (int) ceil($p * $n) - 1));
        return (int) $sorted[$idx];
    }

    public static function median(array $sorted): int
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0;
        }
        return $n % 2 === 1 ? (int) $sorted[intdiv($n, 2)] : (int) round(($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2);
    }

    /**
     * Departure HH:MM + offset minutes → [suggested 'HH:MM:00' rounded to 5 min, day offset].
     *
     * @return array{0: string, 1: int}
     */
    public static function suggest(string $depTime, int $offsetMin): array
    {
        [$h, $m] = array_map('intval', array_pad(explode(':', $depTime), 2, '0'));
        $total = $h * 60 + $m + $offsetMin;
        $total = (int) (round($total / 5) * 5);
        $day = intdiv(max(0, $total), 1440);
        $mod = (($total % 1440) + 1440) % 1440;
        return [sprintf('%02d:%02d:00', intdiv($mod, 60), $mod % 60), $day];
    }

    /** @return int rows written to route_stop_learned */
    public static function learn(int $routeId): int
    {
        $trips = max(1, min(60, Settings::getInt('stop_learn_trips', 10)));
        $min = max(1, min(30, Settings::getInt('stop_learn_min', 3)));
        $written = 0;
        try {
            $dep = (string) Database::scalar('SELECT dep_time FROM routes WHERE id = :r', ['r' => $routeId], '');
            $ids = array_map('intval', array_column(Database::fetchAll(
                'SELECT trip_id, MAX(reached_at) AS last FROM trip_stop_times WHERE route_id = :r
                  GROUP BY trip_id ORDER BY last DESC LIMIT ' . $trips, ['r' => $routeId]), 'trip_id'));
            if ($ids === [] || $dep === '') {
                return 0;
            }
            /* Fix of 19 Nov (review): a BOARDING stop's timetable time is its
               departure, so it is learned from left_at (when the coach left
               after waiting). At the origin (Surat on r2, Rupaidiha on r7)
               the first fix is only when tracking started at the depot: a
               pass there without a left_at is not used at all. Drop stops
               and halts keep the arrival. */
            $types = [];
            foreach (Database::fetchAll('SELECT id, stop_type FROM route_stops WHERE route_id = :r', ['r' => $routeId]) as $s) {
                $types[(int) $s['id']] = (string) $s['stop_type'];
            }
            $origin = self::originStop($routeId);
            // One value per trip and stop: GPS first, the driver's tap when there is no GPS pass.
            $rows = Database::fetchAll(
                'SELECT trip_id, stop_kind, stop_ref, source, offset_min, dep_at, reached_at, left_at FROM trip_stop_times
                  WHERE route_id = :r AND trip_id IN (' . implode(',', $ids) . ") ORDER BY source = 'gps' DESC",
                ['r' => $routeId]);
            $by = [];
            foreach ($rows as $r) {
                $k = $r['stop_kind'] . ':' . $r['stop_ref'];
                if (isset($by[$k]['trips'][(int) $r['trip_id']])) {
                    continue;
                }
                $isStop = (string) $r['stop_kind'] === 'stop';
                $isOrigin = $isStop && $origin > 0 && (int) $r['stop_ref'] === $origin;
                $isBoard = $isStop && ($types[(int) $r['stop_ref']] ?? '') === 'boarding';
                $off = (int) $r['offset_min'];
                if ($isOrigin || $isBoard) {
                    if ($r['left_at'] !== null && $r['dep_at'] !== null) {
                        $off = (int) round((strtotime((string) $r['left_at']) - strtotime((string) $r['dep_at'])) / 60);
                    } elseif ($isOrigin) {
                        continue;                       // the depot start of tracking, not the departure
                    }
                }
                $by[$k]['trips'][(int) $r['trip_id']] = true;
                $by[$k]['off'][] = $off;
                $by[$k]['last'] = max((string) ($by[$k]['last'] ?? ''), (string) $r['reached_at']);
                if ($r['left_at'] !== null) {
                    $by[$k]['dwell'][] = (int) round((strtotime((string) $r['left_at']) - strtotime((string) $r['reached_at'])) / 60);
                }
            }
            foreach ($by as $k => $v) {
                [$kind, $ref] = explode(':', $k);
                if (count($v['off']) < $min) {
                    continue;
                }
                sort($v['off']);
                $med = self::median($v['off']);
                $dw = $v['dwell'] ?? [];
                sort($dw);
                [$time, $day] = self::suggest(substr($dep, 0, 5), $med);
                Database::run(
                    'INSERT INTO route_stop_learned (route_id, stop_kind, stop_ref, samples, median_offset_min, p80_offset_min, median_dwell_min, suggested_time, day_offset, last_trip_at)
                     VALUES (:r, :k, :ref, :n, :med, :p80, :dw, :t, :day, :last)
                     ON DUPLICATE KEY UPDATE samples = VALUES(samples), median_offset_min = VALUES(median_offset_min),
                        p80_offset_min = VALUES(p80_offset_min), median_dwell_min = VALUES(median_dwell_min),
                        suggested_time = VALUES(suggested_time), day_offset = VALUES(day_offset), last_trip_at = VALUES(last_trip_at)',
                    ['r' => $routeId, 'k' => $kind, 'ref' => (int) $ref, 'n' => count($v['off']), 'med' => $med,
                     'p80' => self::percentile($v['off'], 0.8), 'dw' => $dw !== [] ? max(0, self::median($dw)) : null,
                     't' => $time, 'day' => min(255, $day), 'last' => $v['last'] !== '' ? $v['last'] : null]
                );
                $written++;
            }
        } catch (Throwable $e) {
            Logger::warning('Stop timing: learn failed: ' . $e->getMessage(), ['route' => $routeId], 'gps');
        }
        return $written;
    }

    /**
     * The route's origin stop: the first stop on the line (lowest road km)
     * when it is a boarding stop timed at the route's departure (or untimed).
     * 0 when the real origin has no coordinates — the first stop WITH
     * coordinates is then a mid-route stop and must not be treated as one.
     */
    public static function originStop(int $routeId): int
    {
        try {
            $geo = EtaAlerts::routeLine($routeId);
            if (count($geo['line']) < 2) {
                return 0;
            }
            foreach (self::targets($routeId, $geo) as $t) {     // sorted by km
                if ($t['kind'] !== 'stop') {
                    continue;
                }
                $s = Database::fetch(
                    'SELECT rs.stop_type, rs.stop_time, r.dep_time FROM route_stops rs JOIN routes r ON r.id = rs.route_id WHERE rs.id = :id',
                    ['id' => (int) $t['ref']]);
                if ($s === null || (string) $s['stop_type'] !== 'boarding') {
                    return 0;
                }
                $st = $s['stop_time'] !== null ? substr((string) $s['stop_time'], 0, 5) : '';
                return ($st === '' || $st === substr((string) $s['dep_time'], 0, 5)) ? (int) $t['ref'] : 0;
            }
        } catch (Throwable $e) {
            // no line: no origin rule
        }
        return 0;
    }

    /**
     * The learned rows of one route (admin/map.php), keyed "stop:<id>" / "halt:<id>".
     *
     * @return array<string, array<string,mixed>>
     */
    public static function learned(int $routeId = 0): array
    {
        try {
            $rows = Database::fetchAll(
                'SELECT * FROM route_stop_learned' . ($routeId > 0 ? ' WHERE route_id = :r' : ''), $routeId > 0 ? ['r' => $routeId] : []);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[$r['stop_kind'] . ':' . $r['stop_ref']] = $r;
        }
        return $out;
    }

    /* =================================================================
     *  The night job
     * ================================================================= */

    /** @return array<string,mixed> */
    public static function nightly(int $days = 3): array
    {
        $res = ['trips' => 0, 'stored' => 0, 'routes' => 0, 'learned' => 0, 'note' => ''];
        if (!self::enabled()) {
            $res['note'] = 'stop_learn_on is off';
            return $res;
        }
        if (!self::ready()) {
            $res['note'] = 'tables missing (database/upgrade-2026-11-19-u8-stop-timing.sql)';
            return $res;
        }
        $routes = [];
        try {
            $trips = Database::fetchAll(
                "SELECT lt.id, lt.route_id FROM live_trips lt
                  WHERE lt.status IN ('completed','cancelled') AND lt.ended_at >= NOW() - INTERVAL " . max(1, min(30, $days)) . " DAY
                    AND NOT EXISTS (SELECT 1 FROM trip_stop_times t WHERE t.trip_id = lt.id)
                  ORDER BY lt.id LIMIT 50");
        } catch (Throwable $e) {
            $res['note'] = 'live_trips unreadable';
            return $res;
        }
        foreach ($trips as $t) {
            $r = self::recordTrip((int) $t['id']);
            $res['trips']++;
            $res['stored'] += $r['stored'] + $r['driver'];
            $routes[(int) $t['route_id']] = true;
        }
        foreach (array_keys($routes) as $rid) {
            $res['learned'] += self::learn($rid);
        }
        $res['routes'] = count($routes);
        if ($trips === []) {
            $res['note'] = 'no finished trip with GPS to read';
        }
        return $res;
    }
}
