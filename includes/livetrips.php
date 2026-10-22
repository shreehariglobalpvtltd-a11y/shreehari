<?php
/**
 * =====================================================================
 *  includes/livetrips.php - every running coach has its OWN position.
 *  (20 Oct 2026, database/upgrade-2026-10-live-tracking.sql)
 *
 *  Until today the company had one position: the `livebus` kv key, written
 *  by whichever staff phone had the navigator open. r2 leaves Surat every
 *  day and runs ~20 h, so two coaches of one route are on the road most
 *  afternoons - and one anonymous position cannot say which coach it is,
 *  so the track page and the "bus is near" WhatsApp stayed silent.
 *
 *  Now the driver's phone (driver/ app, phone + PIN, includes/driverauth
 *  .php) starts THE trip it is driving and sends fixes for it:
 *
 *    live_trips      one row per schedule (trip_ref 'S<schedule_id>'), the
 *                    latest usable fix in last_* - one primary-key read for
 *                    every page that asks "where is this coach?"
 *    trip_locations  every fix, for replay. UNIQUE(trip_id, seq): a batch
 *                    the phone re-sends after a dead zone is stored once.
 *
 *  The SAME honesty rules as EtaAlerts: a fix moves the coach only when it
 *  is accurate (MOVE_MAX_ACC_M) and physically possible (MAX_JUMP_KMH). A
 *  late fix from the offline queue is kept for history but never pulls the
 *  live coach backwards in time. Readers (EtaAlerts::fixForTrip, WhereIs)
 *  still refuse a stale fix.
 *
 *  Switch: driver_app_on (kill switch, default on - nothing happens until
 *  the office gives a driver a PIN in Admin -> Drivers).
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/etaalerts.php';

final class LiveTrips
{
    public const MOVE_MAX_ACC_M = 100;     // a coarser fix is stored, never shown as the coach
    public const MAX_JUMP_KMH   = 150;     // faster than this between two fixes = a GPS glitch
    public const MAX_BATCH      = 300;     // points per request (the phone sends oldest first)
    public const STALE_TRIP_H   = 36;      // a "running" trip silent this long is over
    public const WINDOW_BACK_H  = 30;      // a trip may be started up to 30 h after departure…
    public const WINDOW_AHEAD_H = 12;      // …and up to 12 h before it

    private static ?bool $available = null;

    public static function enabled(): bool
    {
        return Settings::getBool('driver_app_on', true);
    }

    public static function pingSeconds(): int
    {
        return max(10, min(30, Settings::getInt('live_ping_sec', 15)));
    }

    /**
     * Is the migration on this database? Readers call this first so a
     * missing table can never break the track page.
     */
    public static function available(): bool
    {
        if (self::$available === null) {
            try {
                Database::fetch('SELECT last_seq, device_id FROM live_trips LIMIT 1');
                Database::fetch('SELECT seq FROM trip_locations LIMIT 1');
                self::$available = true;
            } catch (Throwable $e) {
                self::$available = false;
            }
        }
        return self::$available;
    }

    /** For tests: forget the cached availability answer. */
    public static function resetCache(): void
    {
        self::$available = null;
    }

    public static function tripRef(int $scheduleId): string
    {
        return 'S' . $scheduleId;
    }

    /**
     * Departures a driver may start right now: not cancelled, on an active
     * route, leaving between WINDOW_BACK_H ago and WINDOW_AHEAD_H from now.
     * Newest first, each with its live-trip state.
     *
     * @return list<array<string,mixed>>
     */
    public static function choosable(?int $now = null): array
    {
        $now  = $now ?? time();
        $rows = Database::fetchAll(
            "SELECT s.id, s.route_id, s.travel_date, s.slot, s.status, s.delay_minutes,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                    r.route_code, r.from_city, r.to_city, b.bus_number,
                    lt.id AS trip_id, lt.status AS trip_status, lt.driver_id AS trip_driver_id, lt.last_ping_at
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses b ON b.id = s.bus_id
               LEFT JOIN live_trips lt ON lt.trip_ref = CONCAT('S', s.id)
              WHERE s.status IN ('scheduled','departed')
                AND s.is_blocked = 0 AND r.is_active = 1
                AND s.travel_date BETWEEN :a AND :b",
            ['a' => date('Y-m-d', $now - self::WINDOW_BACK_H * 3600 - 86400),
             'b' => date('Y-m-d', $now + self::WINDOW_AHEAD_H * 3600)]
        );
        $out = [];
        foreach ($rows as $r) {
            $dep = (int) strtotime($r['travel_date'] . ' ' . $r['dep_time']) + (int) $r['delay_minutes'] * 60;
            if ($dep <= 0 || $dep < $now - self::WINDOW_BACK_H * 3600 || $dep > $now + self::WINDOW_AHEAD_H * 3600) {
                continue;
            }
            $out[] = [
                'scheduleId' => (int) $r['id'],
                'routeId'    => (int) $r['route_id'],
                'routeCode'  => (string) $r['route_code'],
                'route'      => trim($r['from_city'] . ' → ' . $r['to_city']),
                'date'       => (string) $r['travel_date'],
                'dep'        => substr((string) $r['dep_time'], 0, 5),
                'depTs'      => $dep,
                'bus'        => (string) ($r['bus_number'] ?? ''),
                'tripId'     => $r['trip_id'] !== null ? (int) $r['trip_id'] : null,
                'tripStatus' => $r['trip_status'] !== null ? (string) $r['trip_status'] : null,
                'lastPingAt' => $r['last_ping_at'] !== null ? (string) $r['last_ping_at'] : null,
            ];
        }
        usort($out, static fn(array $a, array $b): int => abs($a['depTs'] - $now) <=> abs($b['depTs'] - $now));
        return $out;
    }

    /**
     * The driver starts (or resumes) THE trip of one schedule. A second
     * phone - the co-driver taking over - simply takes the trip over.
     *
     * @return array<string,mixed> the live_trips row
     */
    public static function start(int $driverId, int $deviceId, int $scheduleId, ?int $now = null): array
    {
        $now = $now ?? time();
        $ok  = null;
        foreach (self::choosable($now) as $c) {
            if ($c['scheduleId'] === $scheduleId) { $ok = $c; break; }
        }
        if ($ok === null) {
            throw new RuntimeException('This departure cannot be started now. Pick today\'s bus from the list.');
        }
        $nowSql = date('Y-m-d H:i:s', $now);
        Database::query(
            "INSERT INTO live_trips (trip_ref, schedule_id, route_id, driver_id, device_id, departure_at, started_at, status)
                  VALUES (:ref, :s, :r, :d, :dev, :dep, :st, 'running')
             ON DUPLICATE KEY UPDATE status = 'running', driver_id = VALUES(driver_id), device_id = VALUES(device_id),
                  ended_at = NULL, started_at = COALESCE(started_at, VALUES(started_at))",
            ['ref' => self::tripRef($scheduleId), 's' => $scheduleId, 'r' => $ok['routeId'], 'd' => $driverId,
             'dev' => $deviceId, 'dep' => date('Y-m-d H:i:s', $ok['depTs']), 'st' => $nowSql]
        );
        $bus = Database::scalar('SELECT bus_id FROM schedules WHERE id = :s', ['s' => $scheduleId]);
        if ($bus !== null) {
            Database::update('live_trips', ['bus_id' => (int) $bus], 'trip_ref = :ref', ['ref' => self::tripRef($scheduleId)]);
        }
        $row = Database::fetch('SELECT * FROM live_trips WHERE trip_ref = :ref', ['ref' => self::tripRef($scheduleId)]);
        if ($row === null) {
            throw new RuntimeException('Could not start the trip.');
        }
        Logger::audit('trip.live_start', 'schedule', (string) $scheduleId, null,
            ['trip' => (int) $row['id'], 'driver' => $driverId, 'device' => $deviceId], 'driver app');
        return $row;
    }

    /**
     * A GPS tracker of the BUS (includes/gpstrackers.php, 22 Oct 2026) feeds
     * the trip of the departure that bus runs: the same row as start(), but
     * nobody signed in - driver_id / device_id stay NULL. A driver phone that
     * already runs the trip keeps it (its device_id is never touched), and a
     * trip the driver ENDED stays ended (the caller sees the status and feeds
     * nothing). Older trips of the same bus that only a tracker fed are over
     * once the bus is on its next departure.
     *
     * @return array<string,mixed> the live_trips row
     */
    public static function startForBus(int $scheduleId, int $busId, ?int $now = null): array
    {
        $now = $now ?? time();
        $ref = self::tripRef($scheduleId);
        $row = Database::fetch('SELECT * FROM live_trips WHERE trip_ref = :ref', ['ref' => $ref]);
        if ($row !== null && (string) $row['status'] !== 'pending') {
            if ($row['bus_id'] === null) {
                Database::update('live_trips', ['bus_id' => $busId], 'id = :id', ['id' => (int) $row['id']]);
                $row['bus_id'] = $busId;
            }
            return $row;   // the usual case: every fix after the first one
        }
        $ok = null;
        foreach (self::choosable($now) as $c) {
            if ($c['scheduleId'] === $scheduleId) { $ok = $c; break; }
        }
        if ($ok === null) {
            throw new RuntimeException('This departure cannot be started now.');
        }
        $nowSql = date('Y-m-d H:i:s', $now);
        $depSql = date('Y-m-d H:i:s', $ok['depTs']);
        Database::query(
            "INSERT INTO live_trips (trip_ref, schedule_id, route_id, bus_id, departure_at, started_at, status)
                  VALUES (:ref, :s, :r, :b, :dep, :st, 'running')
             ON DUPLICATE KEY UPDATE status = IF(status = 'pending', 'running', status),
                  bus_id = COALESCE(bus_id, VALUES(bus_id)), started_at = COALESCE(started_at, VALUES(started_at))",
            ['ref' => $ref, 's' => $scheduleId, 'r' => $ok['routeId'], 'b' => $busId, 'dep' => $depSql, 'st' => $nowSql]
        );
        Database::query(
            "UPDATE live_trips SET status = 'completed', ended_at = :e
              WHERE bus_id = :b AND status IN ('running','paused') AND device_id IS NULL
                AND trip_ref <> :ref AND departure_at < :dep",
            ['e' => $nowSql, 'b' => $busId, 'ref' => $ref, 'dep' => $depSql]
        );
        $row = Database::fetch('SELECT * FROM live_trips WHERE trip_ref = :ref', ['ref' => $ref]);
        if ($row === null) {
            throw new RuntimeException('Could not start the trip.');
        }
        Logger::audit('trip.live_start', 'schedule', (string) $scheduleId, null,
            ['trip' => (int) $row['id'], 'bus' => $busId], 'gps tracker');
        return $row;
    }

    public static function end(int $tripId, ?int $now = null): void
    {
        Database::update('live_trips', ['status' => 'completed', 'ended_at' => date('Y-m-d H:i:s', $now ?? time())],
            "id = :id AND status IN ('running','paused')", ['id' => $tripId]);
    }

    /** One live trip by id, or null. */
    public static function find(int $tripId): ?array
    {
        return Database::fetch('SELECT * FROM live_trips WHERE id = :id', ['id' => $tripId]);
    }

    /**
     * Store a batch of fixes from the phone and move the coach to the newest
     * usable one. Invalid points are acknowledged too, so the phone drops
     * them instead of re-sending them for ever.
     *
     * A GPS tracker (includes/gpstrackers.php) passes $keepSeq = true: its seq
     * is the fix's unix second, and writing that into last_seq would make a
     * driver phone on the same trip jump its own counter to it (the app bumps
     * to lastSeq) and drop its unsent queue on the next ack.
     *
     * @param array<string,mixed> $trip  live_trips row
     * @param list<mixed> $points  [{seq, t (ms), lat, lng, kmh?, brg?, acc?}]
     * @return array{ack: int, stored: int, moved: bool}
     */
    public static function ingest(array $trip, array $points, ?int $now = null, bool $keepSeq = false): array
    {
        $now     = $now ?? time();
        $tripId  = (int) $trip['id'];
        $ack     = (int) ($trip['last_seq'] ?? 0);
        $stored  = 0;
        $latestTs  = $trip['last_ping_at'] !== null ? (int) strtotime((string) $trip['last_ping_at']) : 0;
        $latestLat = $trip['last_lat'] !== null ? (float) $trip['last_lat'] : null;
        $latestLng = $trip['last_lng'] !== null ? (float) $trip['last_lng'] : null;
        $best      = null;

        $clean = [];
        foreach (array_slice($points, 0, self::MAX_BATCH) as $p) {
            if (!is_array($p)) { continue; }
            $seq = (int) ($p['seq'] ?? 0);
            if ($seq <= 0) { continue; }
            $ack = max($ack, $seq);
            $pt  = self::cleanPoint($p, $now);
            if ($pt !== null) {
                $clean[] = ['seq' => $seq] + $pt;
            }
        }
        usort($clean, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);

        /* The previous accurate fix the phone sent (stored or not): two fixes
           that agree with EACH OTHER outvote one old outlier, so a single
           bad fix can never pin the coach in place. */
        $prev = Database::fetch(
            'SELECT latitude, longitude, recorded_at FROM trip_locations
              WHERE trip_id = :t AND (accuracy_m IS NULL OR accuracy_m <= :a)
              ORDER BY recorded_at DESC LIMIT 1',
            ['t' => $tripId, 'a' => self::MOVE_MAX_ACC_M]
        );
        $prevPt = $prev === null ? null : ['lat' => (float) $prev['latitude'], 'lng' => (float) $prev['longitude'], 'ts' => (int) strtotime((string) $prev['recorded_at'])];
        $plausible = static function (?array $a, array $b): bool {
            if ($a === null) { return false; }
            $km = EtaAlerts::haversineKm($a['lat'], $a['lng'], $b['lat'], $b['lng']);
            return $km <= 0.5 || $km / (max(1, abs($b['ts'] - $a['ts'])) / 3600) <= self::MAX_JUMP_KMH;
        };

        $recvSql = date('Y-m-d H:i:s', $now);
        foreach ($clean as $pt) {
            $stored += Database::insertIgnore('trip_locations', [   // new id, or 0 = already stored
                'trip_id'     => $tripId,
                'seq'         => $pt['seq'],
                'latitude'    => $pt['lat'],
                'longitude'   => $pt['lng'],
                'bearing'     => $pt['brg'],
                'speed_kmh'   => $pt['kmh'],
                'accuracy_m'  => $pt['acc'],
                'recorded_at' => date('Y-m-d H:i:s', $pt['ts']),
                'received_at' => $recvSql,
            ]) > 0 ? 1 : 0;
            if ($pt['acc'] !== null && $pt['acc'] > self::MOVE_MAX_ACC_M) { continue; } // too coarse to move the coach
            $prevOk = $prevPt !== null && $prevPt['ts'] > $latestTs && $prevPt['ts'] < $pt['ts'] && $plausible($prevPt, $pt);
            if ($prevPt === null || $pt['ts'] > $prevPt['ts']) { $prevPt = $pt; }
            if ($pt['ts'] <= $latestTs) { continue; }                                  // older than the coach already shown
            $shown = $latestLat !== null && $latestTs > 0 ? ['lat' => $latestLat, 'lng' => (float) $latestLng, 'ts' => $latestTs] : null;
            if ($shown !== null && !$plausible($shown, $pt) && !$prevOk) {
                continue;   // a jump no coach can make - unless the fix before it agrees (then the shown one was wrong)
            }
            $best = $pt; $latestTs = $pt['ts']; $latestLat = $pt['lat']; $latestLng = $pt['lng'];
        }

        $upd = $keepSeq ? [] : ['last_seq' => $ack];
        if ($best !== null) {
            $upd += [
                'last_lat'     => $best['lat'],
                'last_lng'     => $best['lng'],
                'last_bearing' => $best['brg'],
                'last_speed'   => $best['kmh'],
                'last_acc_m'   => $best['acc'] !== null ? (int) round($best['acc']) : null,
                'last_ping_at' => date('Y-m-d H:i:s', $best['ts']),
            ];
        }
        if ($upd !== []) {
            Database::update('live_trips', $upd, 'id = :id', ['id' => $tripId]);
        }
        return ['ack' => $ack, 'stored' => $stored, 'moved' => $best !== null];
    }

    /**
     * One phone point, checked. Null = not a usable position at all.
     *
     * @param array<string,mixed> $p
     * @return array{ts:int,lat:float,lng:float,kmh:?float,brg:?int,acc:?float}|null
     */
    public static function cleanPoint(array $p, int $now): ?array
    {
        if (!is_numeric($p['lat'] ?? null) || !is_numeric($p['lng'] ?? null)) {
            return null;
        }
        $lat = (float) $p['lat']; $lng = (float) $p['lng'];
        // India + Nepal, generously. Anything else is a broken fix (0,0 above all).
        if ($lat < 6.0 || $lat > 37.5 || $lng < 68.0 || $lng > 98.0) {
            return null;
        }
        $ts = is_numeric($p['t'] ?? null) ? (int) floor((float) $p['t'] / 1000) : $now;
        if ($ts > $now + 120) { $ts = $now; }            // a phone clock running ahead
        if ($ts < $now - 4 * 86400) { return null; }     // days old: not this trip
        $kmh = is_numeric($p['kmh'] ?? null) ? max(0.0, min(200.0, (float) $p['kmh'])) : null;
        $brg = is_numeric($p['brg'] ?? null) ? ((int) round((float) $p['brg']) % 360 + 360) % 360 : null;
        $acc = is_numeric($p['acc'] ?? null) ? max(0.0, min(99999.0, (float) $p['acc'])) : null;
        return ['ts' => $ts, 'lat' => round($lat, 7), 'lng' => round($lng, 7),
                'kmh' => $kmh !== null ? round($kmh, 2) : null, 'brg' => $brg, 'acc' => $acc !== null ? round($acc, 2) : null];
    }

    /** The live_trips row of one schedule while the driver app runs it (or ran it lately). */
    public static function forSchedule(int $scheduleId): ?array
    {
        if (!self::available()) {
            return null;
        }
        $row = Database::fetch(
            "SELECT * FROM live_trips WHERE trip_ref = :ref AND status IN ('running','paused','completed')",
            ['ref' => self::tripRef($scheduleId)]
        );
        if ($row === null) {
            return null;
        }
        $last = $row['last_ping_at'] ?? $row['started_at'] ?? null;
        if ($last !== null && time() - (int) strtotime((string) $last) > self::STALE_TRIP_H * 3600) {
            return null;   // an old trip nobody ended: it says nothing about today
        }
        return $row;
    }

    /**
     * The driver app's position for one schedule, in the EtaAlerts::fix()
     * shape, plus whether the trip HAS a driver app at all (then nobody may
     * fall back to the anonymous `livebus` key for it).
     *
     * @return array{0: ?array{lat:float,lng:float,kmh:?float}, 1: string, 2: ?int, 3: bool}
     *         [fix, why-not, age in seconds, trip has a driver app]
     */
    public static function fixFor(int $scheduleId, ?int $now = null): array
    {
        $now = $now ?? time();
        $row = self::forSchedule($scheduleId);
        if ($row === null) {
            return [null, 'no driver app on this trip', null, false];
        }
        if ((string) $row['status'] === 'completed') {
            return [null, 'the driver ended this trip', null, true];
        }
        if ($row['last_ping_at'] === null || $row['last_lat'] === null) {
            return [null, 'the driver has not sent a position yet', null, true];
        }
        $age = $now - (int) strtotime((string) $row['last_ping_at']);
        if ($age > EtaAlerts::FIX_MAX_AGE_SEC || $age < -120) {
            return [null, 'position is ' . max(0, (int) round($age / 60)) . ' min old', max(0, $age), true];
        }
        if ($row['last_acc_m'] !== null && (int) $row['last_acc_m'] > EtaAlerts::FIX_MAX_ACC_M) {
            return [null, 'GPS accuracy ' . (int) $row['last_acc_m'] . ' m', max(0, $age), true];
        }
        return [[
            'lat' => (float) $row['last_lat'],
            'lng' => (float) $row['last_lng'],
            'kmh' => $row['last_speed'] !== null ? (float) $row['last_speed'] : null,
        ], '', max(0, $age), true];
    }

    /**
     * Every coach the driver app is running now with a usable fix, as the
     * schedule rows EtaAlerts::candidates() returns plus 'fix'.
     *
     * @return list<array<string,mixed>>
     */
    public static function runningWithFix(?int $now = null): array
    {
        $now = $now ?? time();
        if (!self::available()) {
            return [];
        }
        $rows = Database::fetchAll(
            "SELECT s.id, s.route_id, s.travel_date, s.delay_minutes,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time, r.from_city, r.to_city
               FROM live_trips lt
               JOIN schedules s ON s.id = lt.schedule_id
               JOIN routes r ON r.id = s.route_id
              WHERE lt.status = 'running' AND lt.last_ping_at >= :since",
            ['since' => date('Y-m-d H:i:s', $now - EtaAlerts::FIX_MAX_AGE_SEC)]
        );
        $out = [];
        foreach ($rows as $r) {
            [$fix] = self::fixFor((int) $r['id'], $now);
            if ($fix === null) {
                continue;
            }
            $dep   = (int) strtotime($r['travel_date'] . ' ' . $r['dep_time']) + (int) $r['delay_minutes'] * 60;
            $out[] = $r + ['dep_ts' => $dep, 'fix' => $fix];
        }
        return $out;
    }

    /**
     * Schedule ids the driver app is running (fresh or not) - the anonymous
     * `livebus` key must not answer for these.
     *
     * @return array<int,true>
     */
    public static function runningScheduleIds(?int $now = null): array
    {
        $now = $now ?? time();
        if (!self::available()) {
            return [];
        }
        $ids = [];
        foreach (Database::fetchAll(
            "SELECT schedule_id FROM live_trips WHERE status IN ('running','paused') AND schedule_id IS NOT NULL
                AND COALESCE(last_ping_at, started_at) >= :since",
            ['since' => date('Y-m-d H:i:s', $now - self::STALE_TRIP_H * 3600)]
        ) as $r) {
            $ids[(int) $r['schedule_id']] = true;
        }
        return $ids;
    }

    /**
     * The office map: every trip the driver app is running, newest fix first.
     *
     * @return list<array<string,mixed>>
     */
    public static function fleet(?int $now = null): array
    {
        $now = $now ?? time();
        if (!self::available()) {
            return [];
        }
        $rows = Database::fetchAll(
            "SELECT lt.id, lt.schedule_id, lt.route_id, lt.status, lt.last_lat, lt.last_lng, lt.last_bearing,
                    lt.last_speed, lt.last_acc_m, lt.last_ping_at, lt.started_at,
                    r.route_code, r.from_city, r.to_city, s.travel_date,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                    b.bus_number, d.full_name AS driver_name, d.phone AS driver_phone,
                    (SELECT COUNT(*) FROM booking_seats bs WHERE bs.schedule_id = lt.schedule_id AND bs.released_at IS NULL) AS pax
               FROM live_trips lt
               JOIN routes r ON r.id = lt.route_id
               LEFT JOIN schedules s ON s.id = lt.schedule_id
               LEFT JOIN buses b ON b.id = COALESCE(lt.bus_id, s.bus_id)
               LEFT JOIN drivers d ON d.id = lt.driver_id
              WHERE lt.status IN ('running','paused')
                AND COALESCE(lt.last_ping_at, lt.started_at) >= :since
              ORDER BY lt.last_ping_at DESC",
            ['since' => date('Y-m-d H:i:s', $now - self::STALE_TRIP_H * 3600)]
        );
        $out = [];
        foreach ($rows as $r) {
            $age = $r['last_ping_at'] !== null ? max(0, $now - (int) strtotime((string) $r['last_ping_at'])) : null;
            $out[] = [
                'tripId'     => (int) $r['id'],
                'scheduleId' => (int) $r['schedule_id'],
                'routeId'    => (int) $r['route_id'],
                'route'      => trim($r['from_city'] . ' → ' . $r['to_city']),
                'routeCode'  => (string) $r['route_code'],
                'date'       => (string) ($r['travel_date'] ?? ''),
                'dep'        => substr((string) ($r['dep_time'] ?? ''), 0, 5),
                'bus'        => (string) ($r['bus_number'] ?? ''),
                'driver'     => (string) ($r['driver_name'] ?? ''),
                'driverPhone'=> (string) ($r['driver_phone'] ?? ''),
                'pax'        => (int) $r['pax'],
                'lat'        => $r['last_lat'] !== null ? (float) $r['last_lat'] : null,
                'lng'        => $r['last_lng'] !== null ? (float) $r['last_lng'] : null,
                'bearing'    => $r['last_bearing'] !== null ? (int) $r['last_bearing'] : null,
                'kmh'        => $r['last_speed'] !== null ? (int) round((float) $r['last_speed']) : null,
                'acc'        => $r['last_acc_m'] !== null ? (int) $r['last_acc_m'] : null,
                'ageSec'     => $age,
                'live'       => $age !== null && $age <= EtaAlerts::FIX_MAX_AGE_SEC,
            ];
        }
        return $out;
    }
}
