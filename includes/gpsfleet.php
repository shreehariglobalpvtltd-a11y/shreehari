<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/gpstrackers.php';

/**
 * Read-only device health. Credentials never leave the configuration page.
 *
 * 15 Nov 2026 (fleet map upgrade, admin/map.php):
 *   - heading: every device and public point carries 'brg' (0-359, null =
 *     unknown): the course of the bus's running trip, else derived from its
 *     last accurate fixes. A step under MIN_MOVE_M says nothing about heading.
 *   - statusCounts(): the count bar above the office map (running / GPS ok /
 *     GPS silent / stopped / not started), from the data the map already has.
 *   - trail(): one trip's fixes for the office replay, thinned to TRAIL_MAX.
 * Every reader degrades to "nothing" when the live-tracking tables are missing.
 */
final class GpsFleet
{
    public const SILENT_SEC  = 600;    // no fix for 10 min = GPS silent
    public const STOPPED_SEC = 900;    // inside STOPPED_M for 15 min = stopped
    public const STOPPED_M   = 200;
    public const MIN_MOVE_M  = 25;     // a smaller step is GPS jitter, not a heading
    public const TRAIL_MAX   = 2000;   // points one replay sends at most
    public const TRAIL_READ  = 60000;  // rows one replay reads at most (20 h at one fix / 1.2 s)

    /** Public bus GPS is separately opted in; no booking/trip is fabricated for a map test. */
    public static function publicPoints(array $devices, int $now): array
    {
        $byBus = [];
        foreach ($devices as $d) {
            if (empty($d['public']) || $d['state'] !== 'live' || !is_numeric($d['fixAt'] ?? null)
                || !is_numeric($d['seenAt'] ?? null) || !is_numeric($d['lat'] ?? null) || !is_numeric($d['lng'] ?? null)) { continue; }
            $age = $now - (int) floor($d['fixAt'] / 1000);
            $seenAge = $now - (int) floor($d['seenAt'] / 1000);
            if ($age < -120 || $age > 120 || $seenAge < -120 || $seenAge > 120
                || $d['lat'] < 6 || $d['lat'] > 37.5 || $d['lng'] < 68 || $d['lng'] > 98) { continue; }
            $bus = (int) $d['busId'];
            if (isset($byBus[$bus]) && $byBus[$bus]['fixAt'] >= $d['fixAt']) { continue; }
            $health = $d['diagnostic'] ?? null;
            $acc = is_array($health) && ($health['reportedAt'] ?? null) === $d['fixAt'] ? ($health['accuracyM'] ?? null) : null;
            $byBus[$bus] = ['id' => -$bus, 'bus' => $d['bus'], 'busName' => $d['name'], 'trackingOnly' => true,
                'from' => '', 'to' => '', 'dep' => '', 'lat' => (float) $d['lat'], 'lng' => (float) $d['lng'],
                'kmh' => $d['kmh'], 'brg' => self::norm($d['brg'] ?? null), 'pct' => null, 'next' => null,
                'fixAt' => (int) $d['fixAt'], 'age' => max(0, $age), 'acc' => $acc];
        }
        return array_values($byBus);
    }

    public static function state(array $r, int $now, bool $enabled): string
    {
        if (!$enabled || !(int) ($r['is_active'] ?? 0)) { return 'disabled'; }
        if (empty($r['last_seen'])) { return 'waiting'; }
        $seen = $now - (int) strtotime((string) $r['last_seen']);
        if ($seen > 120 || $seen < -120) { return 'offline'; }
        if (empty($r['last_fix_at']) || $r['last_lat'] === null || $r['last_lng'] === null) { return 'no_fix'; }
        $age = $now - (int) strtotime((string) $r['last_fix_at']);
        return $age >= -120 && $age <= 120 ? 'live' : 'stale';
    }

    public static function snapshot(?int $now = null): array
    {
        $now ??= time();
        $enabled = GpsTrackers::enabled();
        $out = [];
        if (!GpsTrackers::tableReady()) { return ['at' => $now * 1000, 'enabled' => $enabled, 'devices' => []]; }
        $rows = Database::fetchAll('SELECT g.id,g.bus_id,g.label,g.kind,g.is_active,g.last_seen,g.last_fix_at,
            g.last_lat,g.last_lng,g.last_speed,g.last_batt,b.bus_number,b.bus_name
            FROM gps_trackers g JOIN buses b ON b.id=g.bus_id WHERE b.is_active=1 ORDER BY b.bus_number,g.id');
        $heads = [];
        try { $heads = self::busHeadings(array_column($rows, 'bus_id'), $now); }
        catch (Throwable $e) { Logger::warning('GPS heading unavailable', ['e' => $e->getMessage()], 'nav'); }
        foreach ($rows as $r) {
            $state = self::state($r, $now, $enabled);
            $out[] = ['id' => (int) $r['id'], 'busId' => (int) $r['bus_id'], 'bus' => $r['bus_number'],
                'diagnostic' => GpsTrackers::health((int) $r['id']),
                'public' => Settings::getBool('gps_public_bus_' . (int) $r['bus_id'], false),
                'name' => $r['bus_name'], 'label' => $r['label'], 'kind' => $r['kind'], 'state' => $state,
                'seenAt' => $r['last_seen'] ? strtotime($r['last_seen']) * 1000 : null,
                'fixAt' => $r['last_fix_at'] ? strtotime($r['last_fix_at']) * 1000 : null,
                'lat' => $r['last_lat'] !== null ? (float) $r['last_lat'] : null,
                'lng' => $r['last_lng'] !== null ? (float) $r['last_lng'] : null,
                'kmh' => $state === 'live' && $r['last_speed'] !== null ? round((float) $r['last_speed'], 1) : null,
                'brg' => $state === 'live' ? ($heads[(int) $r['bus_id']] ?? null) : null,
                'battery' => $r['last_batt'] !== null ? (int) $r['last_batt'] : null];
        }
        return ['at' => $now * 1000, 'enabled' => $enabled, 'devices' => $out];
    }

    /* =================================================================
     *  Heading
     * ================================================================= */

    /** 0-359, or null for anything that is not a number. */
    public static function norm(mixed $deg): ?int
    {
        return is_numeric($deg) && is_finite((float) $deg) ? ((int) round((float) $deg) % 360 + 360) % 360 : null;
    }

    /** Compass bearing from A to B (0 = north, 90 = east), or null when they are too close to tell. */
    public static function bearing(float $lat1, float $lng1, float $lat2, float $lng2, float $minM = self::MIN_MOVE_M): ?int
    {
        if (EtaAlerts::haversineKm($lat1, $lng1, $lat2, $lng2) * 1000 < $minM) { return null; }
        $p1 = deg2rad($lat1); $p2 = deg2rad($lat2); $dl = deg2rad($lng2 - $lng1);
        return self::norm(rad2deg(atan2(sin($dl) * cos($p2), cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl))));
    }

    /**
     * Heading from fixes, NEWEST FIRST: from the first older fix far enough
     * from the newest one. Null when the bus has not moved that far.
     *
     * @param list<array{lat:float,lng:float}> $fixes
     */
    public static function headingFrom(array $fixes): ?int
    {
        $new = $fixes[0] ?? null;
        if ($new === null) { return null; }
        foreach (array_slice($fixes, 1) as $old) {
            $b = self::bearing((float) $old['lat'], (float) $old['lng'], (float) $new['lat'], (float) $new['lng']);
            if ($b !== null) { return $b; }
        }
        return null;
    }

    /**
     * busId => heading of the trip each bus is running now (fix within
     * SILENT_SEC): the reported course, else derived from its last fixes.
     *
     * @param list<mixed> $busIds
     * @return array<int,int>
     */
    public static function busHeadings(array $busIds, int $now): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $busIds), static fn(int $b): bool => $b > 0)));
        if ($ids === [] || !LiveTrips::available()) { return []; }
        $ph = []; $pp = ['since' => date('Y-m-d H:i:s', $now - self::SILENT_SEC)];
        foreach ($ids as $i => $b) { $ph[] = ':b' . $i; $pp['b' . $i] = $b; }
        $out = [];
        foreach (Database::fetchAll("SELECT id, bus_id, last_bearing FROM live_trips
              WHERE status = 'running' AND bus_id IN (" . implode(',', $ph) . ") AND last_ping_at >= :since
              ORDER BY last_ping_at DESC", $pp) as $r) {
            $bus = (int) $r['bus_id'];
            if (array_key_exists($bus, $out)) { continue; }   // the bus's newest trip only
            $out[$bus] = $r['last_bearing'] !== null ? self::norm($r['last_bearing']) : self::tripHeading((int) $r['id']);
        }
        return array_filter($out, static fn(?int $v): bool => $v !== null);
    }

    /** Heading from one trip's last accurate fixes (the last 8: about two minutes). */
    public static function tripHeading(int $tripId): ?int
    {
        $fixes = array_map(static fn(array $r): array => ['lat' => (float) $r['latitude'], 'lng' => (float) $r['longitude']],
            Database::fetchAll('SELECT latitude, longitude FROM trip_locations
                WHERE trip_id = :t AND (accuracy_m IS NULL OR accuracy_m <= :a)
                ORDER BY recorded_at DESC, id DESC LIMIT 8', ['t' => $tripId, 'a' => LiveTrips::MOVE_MAX_ACC_M]));
        return self::headingFrom($fixes);
    }

    /* =================================================================
     *  The count bar above the office map
     * ================================================================= */

    /**
     * Pure. Counts and the ids behind each count (trip ids of live_trips;
     * schedule ids for notStarted), so a tap can filter the map.
     *
     *   running     every trip the driver app / a bus tracker is running (LiveTrips::fleet)
     *   gpsOk       ... with a fix newer than SILENT_SEC
     *   silent      ... without one (never sent, or older)
     *   stopped     ... GPS ok, and inside STOPPED_M for STOPPED_SEC ($stopped)
     *   notStarted  today's departures past their time (delay included), not
     *               cancelled / OFF, that no trip was ever started for
     *
     * @param list<array<string,mixed>> $fleet   LiveTrips::fleet() rows
     * @param list<array{id:int,depTs:int,off:bool}> $today  todayDepartures()
     * @param array<int,mixed> $started  schedule id => (anything): a trip was started
     * @param array<int,mixed> $stopped  trip id => (anything): standing still
     * @return array{counts: array<string,int>, sets: array<string,list<int>>, at: int}
     */
    public static function statusCounts(array $fleet, array $today, array $started, array $stopped, int $now): array
    {
        $sets = ['running' => [], 'gpsOk' => [], 'silent' => [], 'stopped' => [], 'notStarted' => []];
        foreach ($fleet as $t) {
            $id = (int) ($t['tripId'] ?? 0);
            if ($id <= 0) { continue; }
            $sets['running'][] = $id;
            $age = $t['ageSec'] ?? null;
            $ok  = is_numeric($age) && (int) $age <= self::SILENT_SEC && ($t['lat'] ?? null) !== null;
            $sets[$ok ? 'gpsOk' : 'silent'][] = $id;
            if ($ok && isset($stopped[$id])) { $sets['stopped'][] = $id; }
        }
        foreach ($today as $d) {
            $sid = (int) $d['id'];
            if (empty($d['off']) && (int) $d['depTs'] > 0 && (int) $d['depTs'] <= $now && !isset($started[$sid])) {
                $sets['notStarted'][] = $sid;
            }
        }
        return ['counts' => array_map('count', $sets), 'sets' => $sets, 'at' => $now];
    }

    /** Pure. Has the bus stood inside STOPPED_M of its latest fix since the anchor (the last fix at least STOPPED_SEC old)? */
    public static function isStopped(array $latest, ?array $anchor, array $window): bool
    {
        if ($anchor === null) { return false; }   // no fix that old: it has not been anywhere for 15 min yet
        foreach (array_merge([$anchor], $window) as $p) {
            if (EtaAlerts::haversineKm((float) $latest['lat'], (float) $latest['lng'], (float) $p['lat'], (float) $p['lng']) * 1000 > self::STOPPED_M) {
                return false;
            }
        }
        return true;
    }

    /**
     * Today's departures for the bar: [id, depTs (delay included), off].
     *
     * @return list<array{id:int,depTs:int,off:bool}>
     */
    public static function todayDepartures(int $now): array
    {
        $out = [];
        foreach (Database::fetchAll("SELECT s.id, s.travel_date, s.status, s.is_blocked, s.delay_minutes,
                COALESCE(s.dep_time_override, r.dep_time) AS dep_time
              FROM schedules s JOIN routes r ON r.id = s.route_id
             WHERE s.travel_date = :d AND r.is_active = 1", ['d' => date('Y-m-d', $now)]) as $r) {
            $dep = strtotime($r['travel_date'] . ' ' . (string) $r['dep_time']);
            if ($dep === false) { continue; }
            $out[] = ['id' => (int) $r['id'], 'depTs' => $dep + (int) $r['delay_minutes'] * 60,
                      'off' => (string) $r['status'] === 'cancelled' || (int) $r['is_blocked'] === 1];
        }
        return $out;
    }

    /**
     * schedule id => the id of its live trip (one that was really started):
     * the "Replay" links and the "not started" count.
     *
     * @param list<mixed> $scheduleIds
     * @return array<int,int>
     */
    public static function liveTripIds(array $scheduleIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $scheduleIds), static fn(int $s): bool => $s > 0)));
        if ($ids === [] || !LiveTrips::available()) { return []; }
        $ph = []; $pp = [];
        foreach ($ids as $i => $s) { $ph[] = ':s' . $i; $pp['s' . $i] = $s; }
        $out = [];
        foreach (Database::fetchAll('SELECT id, schedule_id FROM live_trips
              WHERE schedule_id IN (' . implode(',', $ph) . ') AND started_at IS NOT NULL', $pp) as $r) {
            $out[(int) $r['schedule_id']] = (int) $r['id'];
        }
        return $out;
    }

    /**
     * trip id => true for every fleet trip standing still (GPS ok only).
     *
     * @param list<array<string,mixed>> $fleet
     * @return array<int,true>
     */
    public static function stoppedTrips(array $fleet, int $now): array
    {
        if (!LiveTrips::available()) { return []; }
        $since = date('Y-m-d H:i:s', $now - self::STOPPED_SEC);
        $acc   = LiveTrips::MOVE_MAX_ACC_M;
        $pt    = static fn(array $r): array => ['lat' => (float) $r['latitude'], 'lng' => (float) $r['longitude']];
        $out   = [];
        foreach ($fleet as $t) {
            $id = (int) ($t['tripId'] ?? 0);
            if ($id <= 0 || ($t['lat'] ?? null) === null || !is_numeric($t['ageSec'] ?? null) || (int) $t['ageSec'] > self::SILENT_SEC) { continue; }
            $anchor = Database::fetch('SELECT latitude, longitude FROM trip_locations
                WHERE trip_id = :t AND recorded_at <= :s AND (accuracy_m IS NULL OR accuracy_m <= :a)
                ORDER BY recorded_at DESC LIMIT 1', ['t' => $id, 's' => $since, 'a' => $acc]);
            if ($anchor === null) { continue; }
            $window = Database::fetchAll('SELECT latitude, longitude FROM trip_locations
                WHERE trip_id = :t AND recorded_at > :s AND (accuracy_m IS NULL OR accuracy_m <= :a) LIMIT 2000',
                ['t' => $id, 's' => $since, 'a' => $acc]);
            if (self::isStopped(['lat' => (float) $t['lat'], 'lng' => (float) $t['lng']], $pt($anchor), array_map($pt, $window))) {
                $out[$id] = true;
            }
        }
        return $out;
    }

    /**
     * The whole bar, from the database. $fleet = LiveTrips::fleet() when the
     * caller already has it.
     *
     * @return array{counts: array<string,int>, sets: array<string,list<int>>, at: int}
     */
    public static function statusBar(?array $fleet = null, ?int $now = null): array
    {
        $now ??= time();
        $fleet ??= LiveTrips::fleet($now);
        $today = self::todayDepartures($now);
        return self::statusCounts($fleet, $today, self::liveTripIds(array_column($today, 'id')), self::stoppedTrips($fleet, $now), $now);
    }

    /* =================================================================
     *  Trip replay
     * ================================================================= */

    /**
     * Pure. At most $max points, evenly spread over the list: the first and
     * the last are always kept, the order never changes.
     */
    public static function thin(array $pts, int $max = self::TRAIL_MAX): array
    {
        $pts = array_values($pts);
        $n   = count($pts);
        $max = max(2, $max);
        if ($n <= $max) { return $pts; }
        $out  = [];
        $step = ($n - 1) / ($max - 1);
        for ($i = 0; $i < $max; $i++) { $out[] = $pts[(int) round($i * $step)]; }
        return $out;
    }

    /**
     * Pure. trip_locations rows (oldest first) as [lng, lat, unix s, km/h|null,
     * bearing|null]: coarse fixes (> MOVE_MAX_ACC_M) dropped, and a lone
     * spike - impossible to reach AND to come back from - dropped too.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{0:float,1:float,2:int,3:?float,4:?int}>
     */
    public static function cleanTrail(array $rows): array
    {
        $pts = [];
        foreach ($rows as $r) {
            if ($r['accuracy_m'] !== null && (float) $r['accuracy_m'] > LiveTrips::MOVE_MAX_ACC_M) { continue; }
            $ts = strtotime((string) $r['recorded_at']);
            if ($ts === false || !is_numeric($r['latitude']) || !is_numeric($r['longitude'])) { continue; }
            $pts[] = [round((float) $r['longitude'], 6), round((float) $r['latitude'], 6), $ts,
                      $r['speed_kmh'] !== null ? round((float) $r['speed_kmh'], 1) : null, self::norm($r['bearing'] ?? null)];
        }
        $fast = static function (array $a, array $b): bool {
            $km = EtaAlerts::haversineKm($a[1], $a[0], $b[1], $b[0]);
            return $km > 0.5 && $km / (max(1, abs($b[2] - $a[2])) / 3600) > LiveTrips::MAX_JUMP_KMH;
        };
        $out = [];
        foreach ($pts as $i => $p) {
            $prev = $out[count($out) - 1] ?? null;
            $next = $pts[$i + 1] ?? null;
            if ($prev !== null && $next !== null && $fast($prev, $p) && $fast($p, $next)) { continue; }
            $out[] = $p;
        }
        return $out;
    }

    /** Who / where / when of one live trip, or null. */
    public static function tripMeta(int $tripId): ?array
    {
        if ($tripId <= 0 || !LiveTrips::available()) { return null; }
        $r = Database::fetch("SELECT lt.id, lt.schedule_id, lt.status, lt.started_at, lt.ended_at, lt.last_ping_at,
                r.route_code, r.from_city, r.to_city, s.travel_date, COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                b.bus_number, b.bus_name
              FROM live_trips lt JOIN routes r ON r.id = lt.route_id
              LEFT JOIN schedules s ON s.id = lt.schedule_id
              LEFT JOIN buses b ON b.id = COALESCE(lt.bus_id, s.bus_id)
             WHERE lt.id = :id", ['id' => $tripId]);
        if ($r === null) { return null; }
        return ['id' => (int) $r['id'], 'scheduleId' => $r['schedule_id'] !== null ? (int) $r['schedule_id'] : null,
            'route' => trim($r['from_city'] . ' → ' . $r['to_city']), 'routeCode' => (string) $r['route_code'],
            'date' => (string) ($r['travel_date'] ?? ''), 'dep' => substr((string) ($r['dep_time'] ?? ''), 0, 5),
            'bus' => trim((string) ($r['bus_number'] ?? '')), 'busName' => (string) ($r['bus_name'] ?? ''),
            'status' => (string) $r['status'], 'startedAt' => $r['started_at'], 'endedAt' => $r['ended_at']];
    }

    /**
     * One trip's GPS trail for the replay, or null when there is no such trip.
     *
     * @return array{trip: array<string,mixed>, total: int, points: list<array>}|null
     */
    public static function trail(int $tripId, int $max = self::TRAIL_MAX): ?array
    {
        $meta = self::tripMeta($tripId);
        if ($meta === null) { return null; }
        $pts = self::cleanTrail(Database::fetchAll('SELECT latitude, longitude, recorded_at, speed_kmh, bearing, accuracy_m
            FROM trip_locations WHERE trip_id = :t ORDER BY recorded_at, id LIMIT ' . self::TRAIL_READ, ['t' => $tripId]));
        return ['trip' => $meta, 'total' => count($pts), 'points' => self::thin($pts, $max)];
    }
}
