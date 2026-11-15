<?php
/**
 * includes/etaalerts.php — "the bus is about 30 minutes from your stop".
 *
 * A passenger boarding at Mehsana at 23:00 stands at the roadside from 22:30
 * whatever the bus is doing. The driver's phone already publishes the coach's
 * position every ~10 s (the shared `livebus` key, 15-nav.js), so the server
 * can tell each waiting passenger ONCE when the bus is really close.
 *
 * BETTER SILENT THAN WRONG. A wrong "bus in 25 minutes" sends a family to a
 * highway at night for nothing, so every doubt ends in silence, not a guess:
 *   - the fix must be fresh (FIX_MAX_AGE_SEC) and accurate (FIX_MAX_ACC_M);
 *   - it must lie ON the route (within OFF_ROUTE_KM of the line of stops);
 *   - the trip must plausibly be on the road (departure −30 min … +16 h);
 *   - two buses of the same route in that window = ambiguous = nobody told,
 *     because `livebus` is one position and cannot say which coach it is;
 *   - the stop's timetable time must be within SCHEDULE_SLACK_H of now, which
 *     is what stops the RETURN coach passing Mehsana from alerting tonight's
 *     outbound passengers;
 *   - a stop without coordinates is skipped and named in the result.
 *
 * AT MOST ONCE. trip_eta_alerts has UNIQUE(booking_id, schedule_id) and the
 * row is claimed BEFORE the send, so a slow provider and an overlapping cron
 * run cannot produce two messages.
 *
 * Not on the driver's publish path: api/kv.php is untouched; cron/eta-alerts
 * .php reads the key. Distance is the line of stops × ROAD_FACTOR — a road is
 * never shorter than the straight line, so the ETA errs late-side honest
 * rather than promising a bus that is still 20 km of bends away.
 *
 * 20 Oct 2026: a coach whose driver runs the driver app (driver/, includes/
 * livetrips.php) has its OWN position, so it is judged on that alone and
 * the "two buses on the road" silence no longer applies to it. fixForTrip()
 * is the one place every page asks "where is THIS coach?".
 *
 * 20 Oct 2026 (phase 2): where the route has a stored ROAD line
 * (includes/routegeo.php, table route_geometry), km are measured along that
 * road - every stop and the coach are placed on it - instead of the line of
 * stops x ROAD_FACTOR. No road line, or a stop that does not sit on it, and
 * the old line of stops answers exactly as before (routeLine / locate).
 *
 * Switch: eta_alert_on (default off) · threshold: eta_alert_minutes (30).
 *
 * 15 Nov 2026 (P8): "bus about 15 min / 5 min from your stop" as WEB PUSH
 * only (runSteps), to the phones that turned on alerts for the ticket
 * (push_subscriptions.booking_id, set by api/push.php from the ticket's
 * PNR, or the ticket's verified mobile). Same coaches, same fixes, same
 * silence rules as run() (coaches() + dueStops() are shared). A step is
 * said only while the ETA is inside its honest window (stepFloor: 15 =
 * 10..15 min, 5 = 3..5 min; between windows nothing). Each step
 * goes once per booking and trip (eta_alert_steps, claimed before the
 * push), a farther step never follows a closer one, and a step the
 * 30-minute alert already covered (it said "about N min", N <= step) is
 * not repeated. WhatsApp is never used for the steps (cost + template
 * rules). Switch: eta_push_steps_on (default off) · steps: eta_push_steps
 * ('15,5').
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/boarding.php';
require_once __DIR__ . '/livetrips.php';   // 20 Oct 2026: each coach's own position (driver app)
require_once __DIR__ . '/routegeo.php';    // 20 Oct 2026: the stored road line of a route

final class EtaAlerts
{
    public const FIX_MAX_AGE_SEC  = 300;
    public const FIX_MAX_ACC_M    = 500;
    public const OFF_ROUTE_KM     = 25.0;
    public const ROAD_FACTOR      = 1.25;
    public const SCHEDULE_SLACK_H = 4;
    public const MAX_ALERT_KM     = 60.0;
    public const MIN_ETA_MIN      = 3;      // closer than this, the bus is in sight
    public const STEP_MIN_MIN     = 5;      // the closest push step (its window is MIN_ETA_MIN..5)
    public const STEP_MAX_MIN     = 60;     // the farthest push step

    public static function enabled(): bool
    {
        return Settings::getBool('eta_alert_on', false);
    }

    public static function thresholdMinutes(): int
    {
        return max(10, min(90, Settings::getInt('eta_alert_minutes', 30)));
    }

    /* ---------------------------------------------------------------
     *  Geometry
     * ------------------------------------------------------------- */

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r    = 6371.0088;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * The route as a line of its stops that HAVE coordinates, in travel
     * order (boarding stops, then drops), each with its road-km from the
     * first. Stops without coordinates are returned in 'missing'.
     *
     * 20 Oct 2026: with a stored road line the km are ROAD km along it (each
     * stop placed on the road), 'lineKm' keeps the stop-line km, and 'road'
     * carries the line for locate(). Without one this is stopLine().
     *
     * @return array{line: list<array{name:string,type:string,time:?string,lat:float,lng:float,km:float,lineKm?:float}>, missing: list<string>, road?: array<string,mixed>}
     */
    public static function routeLine(int $routeId): array
    {
        $geo  = self::stopLine($routeId);
        $road = RouteGeo::load($routeId);
        if ($road === null || count($geo['line']) < 2) {
            return $geo;
        }
        static $snap = [];
        $line = $geo['line'];
        $prev = -1.0;
        foreach ($line as $i => $s) {
            $k = $routeId . ':' . $road['version'] . ':' . $s['lat'] . ':' . $s['lng'];
            $at = $snap[$k] ??= RouteGeo::locate($road, $s['lat'], $s['lng']);
            if ($at === null || $at['off'] > RouteGeo::STOP_SNAP_KM || $at['km'] + 0.5 < $prev) {
                return $geo;   // a stop moved since the line was built: the line of stops, until it is rebuilt
            }
            $prev = $at['km'];
            $line[$i]['lineKm'] = $s['km'];
            $line[$i]['km']     = round($at['km'], 2);
        }
        return ['line' => $line, 'missing' => $geo['missing'], 'road' => $road];
    }

    /**
     * Where along this route is a point? On the road line when there is one
     * and the point is near it; otherwise on the line of stops - mapped onto
     * road km between the two stops either side when a road line exists, so
     * every answer for one route is on ONE scale.
     *
     * @param array{line: list<array<string,mixed>>, road?: array<string,mixed>} $geo  routeLine()
     * @return array{km: float, off: float, on: string}|null   on = 'road' | 'line'
     */
    public static function locate(array $geo, float $lat, float $lng): ?array
    {
        if (!isset($geo['road'])) {
            $p = self::project($geo['line'], $lat, $lng);
            return $p === null ? null : $p + ['on' => 'line'];
        }
        $p = RouteGeo::locate($geo['road'], $lat, $lng);
        if ($p !== null && $p['off'] <= self::OFF_ROUTE_KM) {
            return $p + ['on' => 'road'];
        }
        $line = $geo['line'];
        $flat = array_map(static fn(array $s): array => ['lat' => $s['lat'], 'lng' => $s['lng'], 'km' => (float) $s['lineKm']], $line);
        $q = self::project($flat, $lat, $lng);
        if ($q === null) {
            return null;
        }
        $km = $line[count($line) - 1]['km'];
        for ($i = 0, $n = count($line) - 1; $i < $n; $i++) {
            if ($q['km'] <= $flat[$i + 1]['km'] || $i === $n - 1) {
                $span = $flat[$i + 1]['km'] - $flat[$i]['km'];
                $f    = $span > 0 ? max(0.0, min(1.0, ($q['km'] - $flat[$i]['km']) / $span)) : 0.0;
                $km   = $line[$i]['km'] + $f * ($line[$i + 1]['km'] - $line[$i]['km']);
                break;
            }
        }
        return ['km' => $km, 'off' => $q['off'], 'on' => 'line'];
    }

    /**
     * The line of stops alone (the pre-road-line answer): stops with
     * coordinates in travel order, km = straight line x ROAD_FACTOR.
     *
     * @return array{line: list<array{name:string,type:string,time:?string,lat:float,lng:float,km:float}>, missing: list<string>}
     */
    public static function stopLine(int $routeId): array
    {
        $rows = Database::fetchAll(
            "SELECT stop_name, stop_type, stop_time, latitude, longitude
               FROM route_stops WHERE route_id = :r
              ORDER BY FIELD(stop_type,'boarding','drop'), sort_order, id",
            ['r' => $routeId]
        );
        $line = []; $missing = []; $km = 0.0; $prev = null;
        foreach ($rows as $r) {
            if ($r['latitude'] === null || $r['longitude'] === null || ((float) $r['latitude'] === 0.0 && (float) $r['longitude'] === 0.0)) {
                if ((string) $r['stop_type'] === 'boarding') { $missing[] = (string) $r['stop_name']; }
                continue;
            }
            $lat = (float) $r['latitude']; $lng = (float) $r['longitude'];
            if ($prev !== null) {
                $km += self::haversineKm($prev[0], $prev[1], $lat, $lng) * self::ROAD_FACTOR;
            }
            $prev   = [$lat, $lng];
            $line[] = ['name' => (string) $r['stop_name'], 'type' => (string) $r['stop_type'],
                       'time' => $r['stop_time'] !== null ? (string) $r['stop_time'] : null,
                       'lat' => $lat, 'lng' => $lng, 'km' => round($km, 2)];
        }
        return ['line' => $line, 'missing' => $missing];
    }

    /**
     * Where along the line is this point? Nearest segment, flat-earth
     * projection (exact enough over one segment), clamped to the segment.
     *
     * @param list<array{lat:float,lng:float,km:float}> $line
     * @return array{km: float, off: float}|null  null = fewer than two stops
     */
    public static function project(array $line, float $lat, float $lng): ?array
    {
        if (count($line) < 2) {
            return null;
        }
        $best = null;
        for ($i = 0, $n = count($line) - 1; $i < $n; $i++) {
            $a = $line[$i]; $b = $line[$i + 1];
            $kx = cos(deg2rad(($a['lat'] + $b['lat']) / 2));
            $bx = ($b['lng'] - $a['lng']) * $kx; $by = $b['lat'] - $a['lat'];
            $px = ($lng - $a['lng']) * $kx;      $py = $lat - $a['lat'];
            $len2 = $bx * $bx + $by * $by;
            $t = $len2 > 0 ? max(0.0, min(1.0, ($px * $bx + $py * $by) / $len2)) : 0.0;
            $qLat = $a['lat'] + $t * ($b['lat'] - $a['lat']);
            $qLng = $a['lng'] + $t * ($b['lng'] - $a['lng']);
            $off  = self::haversineKm($lat, $lng, $qLat, $qLng);
            if ($best === null || $off < $best['off']) {
                $best = ['km' => $a['km'] + $t * ($b['km'] - $a['km']), 'off' => $off];
            }
        }
        return $best;
    }

    /** Minutes to cover $km. A crawling or unknown speed is read as 40 km/h; the band is 25–70. */
    public static function etaMinutes(float $km, ?float $speedKmh): int
    {
        $v = ($speedKmh !== null && $speedKmh >= 15) ? $speedKmh : 40.0;
        $v = max(25.0, min(70.0, $v));
        return (int) ceil($km / $v * 60);
    }

    /* ---------------------------------------------------------------
     *  Inputs
     * ------------------------------------------------------------- */

    /**
     * The published position, or null with the reason it cannot be used.
     *
     * @return array{0: ?array{lat:float,lng:float,kmh:?float}, 1: string}
     */
    public static function fix(?int $nowTs = null): array
    {
        $nowTs = $nowTs ?? time();
        $row = Database::fetch("SELECT kvalue, updated_at FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus' LIMIT 1");
        if ($row === null) {
            return [null, 'no position published'];
        }
        $age = $nowTs - (int) strtotime((string) $row['updated_at']);
        if ($age > self::FIX_MAX_AGE_SEC || $age < -120) {
            return [null, 'position is ' . max(0, (int) round($age / 60)) . ' min old'];
        }
        $v = json_decode((string) $row['kvalue'], true);
        if (is_string($v)) { $v = json_decode($v, true); }     // the client store double-encodes
        if (!is_array($v) || !is_numeric($v['lat'] ?? null) || !is_numeric($v['lng'] ?? null)) {
            return [null, 'position has no coordinates'];
        }
        if (is_numeric($v['acc'] ?? null) && (float) $v['acc'] > self::FIX_MAX_ACC_M) {
            return [null, 'GPS accuracy ' . (int) $v['acc'] . ' m'];
        }
        return [[
            'lat' => (float) $v['lat'], 'lng' => (float) $v['lng'],
            'kmh' => is_numeric($v['spd'] ?? null) ? (float) $v['spd'] * 3.6 : null,   // the phone reports m/s
            'bearing' => is_numeric($v['bearing'] ?? null) ? (int) $v['bearing'] : (is_numeric($v['hd'] ?? null) ? (int) $v['hd'] : null),
        ], ''];
    }

    /**
     * The position of ONE trip (20 Oct 2026). The driver app's own fix for
     * this schedule wins. A trip that HAS a driver app never falls back to
     * the anonymous `livebus` key, even when its own fix is stale, because
     * that key may be another coach. Without a driver app the old rule
     * holds: `livebus` answers only when this is the one coach of its route
     * on the road.
     *
     * @return array{0: ?array{lat:float,lng:float,kmh:?float}, 1: string, 2: ?int, 3: string}
     *         [fix, why-not, age in seconds, source 'trip' | 'livebus' | '']
     */
    public static function fixForTrip(int $scheduleId, int $routeId, ?int $nowTs = null): array
    {
        $nowTs = $nowTs ?? time();
        [$own, $why, $age, $hasApp] = LiveTrips::fixFor($scheduleId, $nowTs);
        if ($own !== null) {
            return [$own, '', $age, 'trip'];
        }
        if ($hasApp) {
            return [null, $why, $age, 'trip'];
        }
        $appRun  = LiveTrips::runningScheduleIds($nowTs);
        $running = array_filter(self::candidates($nowTs), static fn(array $c): bool => (int) $c['route_id'] === $routeId);
        if (count($running) !== 1 || (int) reset($running)['id'] !== $scheduleId) {
            return [null, count($running) > 1 ? 'two buses on this route, not guessing which' : 'this trip is not on the road yet', null, ''];
        }
        [$fix, $why] = self::fix($nowTs);
        if ($fix === null) {
            return [null, $why, null, ''];
        }
        if ($appRun !== []) {
            // Some coach runs the driver app. If its fix is on THIS route the
            // anonymous key could be that coach's phone, so do not use it.
            foreach (LiveTrips::runningWithFix($nowTs) as $t) {
                if ((int) $t['route_id'] === $routeId) {
                    return [null, 'another coach of this route sends its own position', null, ''];
                }
            }
        }
        $row = Database::fetch("SELECT updated_at FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus' LIMIT 1");
        $age = $row !== null ? max(0, $nowTs - (int) strtotime((string) $row['updated_at'])) : null;
        return [$fix, '', $age, 'livebus'];
    }

    /**
     * Trips that can plausibly be on the road now: departure −30 min … +16 h.
     *
     * @return list<array<string,mixed>>
     */
    public static function candidates(int $nowTs): array
    {
        $rows = Database::fetchAll(
            "SELECT s.id, s.route_id, s.travel_date, s.delay_minutes,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time, r.from_city, r.to_city
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.status IN ('scheduled','departed')
                AND s.travel_date BETWEEN :a AND :b",
            ['a' => date('Y-m-d', $nowTs - 86400), 'b' => date('Y-m-d', $nowTs)]
        );
        $out = [];
        foreach ($rows as $r) {
            $dep = strtotime($r['travel_date'] . ' ' . $r['dep_time']) + (int) $r['delay_minutes'] * 60;
            if ($nowTs >= $dep - 1800 && $nowTs <= $dep + 16 * 3600) {
                $out[] = $r + ['dep_ts' => $dep];
            }
        }
        return $out;
    }

    /**
     * Minutes from the live bus to each boarding stop still ahead of it on
     * this route, for a screen (the office / agent map). Same rules as the
     * alert: a stale, inaccurate or off-route fix gives an empty answer, never
     * a guess. Keyed by Boarding::townKey() so a booking's label finds its stop.
     *
     * @return array<string, array{name:string, eta:int, km:float}>
     */
    public static function stopEtas(int $routeId, ?array $fix = null, ?int $nowTs = null): array
    {
        if ($fix === null) {
            [$fix] = self::fix($nowTs);
            if ($fix === null) {
                return [];
            }
        }
        $geo = self::routeLine($routeId);
        $pos = self::locate($geo, (float) $fix['lat'], (float) $fix['lng']);
        if ($pos === null || $pos['off'] > self::OFF_ROUTE_KM) {
            return [];
        }
        $out = [];
        foreach ($geo['line'] as $stop) {
            $ahead = $stop['km'] - $pos['km'];
            if ($stop['type'] !== 'boarding' || $ahead <= 0.5 || $ahead > 400) {
                continue;
            }
            $out[Boarding::townKey($stop['name'])] = ['name' => $stop['name'], 'eta' => self::etaMinutes($ahead, $fix['kmh'] ?? null), 'km' => round($ahead, 1)];
        }
        return $out;
    }

    /* ---------------------------------------------------------------
     *  The run
     * ------------------------------------------------------------- */

    /**
     * @param array{lat:float,lng:float,kmh:?float}|null $fix  test override
     * @param callable(array,array):array|null $send  fn(booking, facts) → tripEvent-shaped result; default Notify
     * @return array<string,mixed> one JSON-able line for cron_done()
     */
    public static function run(?array $fix = null, ?int $nowTs = null, ?callable $send = null): array
    {
        $nowTs  = $nowTs ?? time();
        $result = ['sent' => 0, 'claimed' => 0, 'trips' => 0, 'skipped' => []];
        $limit  = self::thresholdMinutes();

        foreach (self::coaches($fix, $nowTs, $result) as [$trip, $tripFix]) {
            self::alertTrip($trip, $tripFix, $limit, $nowTs, $send, $result);
        }
        return $result;
    }

    /**
     * Every coach this run may speak for, each with the ONE position that
     * is its own (15 Nov 2026: shared by run() and runSteps(), so the push
     * steps can never be told by a looser rule than the 30-minute alert).
     *
     * @param array{lat:float,lng:float,kmh:?float}|null $fix  test override: the old single position
     * @param array<string,mixed> $result  gets 'skipped' lines and, with nothing to judge, 'idle'
     * @return list<array{0: array<string,mixed>, 1: array<string,mixed>}>  [candidates()-shaped trip, fix]
     */
    private static function coaches(?array $fix, int $nowTs, array &$result): array
    {
        $out = [];

        /* 20 Oct 2026: every coach running the driver app first, each with its
           OWN fix - two coaches of one route are no longer ambiguous. (A test
           passing $fix keeps the old single-position behaviour exactly.) */
        $ownApp = [];
        if ($fix === null) {
            foreach (LiveTrips::runningWithFix($nowTs) as $trip) {
                $ownApp[(int) $trip['id']] = true;
                $out[] = [$trip, $trip['fix']];
            }
            $ownApp += LiveTrips::runningScheduleIds($nowTs);
            [$fix, $why] = self::fix($nowTs);
            if ($fix === null) {
                if ($ownApp === []) {
                    $result['idle'] = $why;
                }
                return $out;
            }
        }

        /* The anonymous `livebus` key: only for a route with ONE coach on
           the road, and never for a coach that has its own driver app. */
        $byRoute = [];
        foreach (self::candidates($nowTs) as $c) {
            $byRoute[(int) $c['route_id']][] = $c;
        }
        foreach ($byRoute as $routeId => $trips) {
            if (count($trips) > 1) {
                $result['skipped'][] = 'route ' . $routeId . ': ' . count($trips) . ' buses on the road, one position - not guessing';
                continue;
            }
            if (isset($ownApp[(int) $trips[0]['id']])) {
                continue;   // that coach answers for itself
            }
            $out[] = [$trips[0], $fix];
        }
        return $out;
    }

    /**
     * One coach, one fix: tell the passengers of every boarding stop it is
     * now about $limit minutes from. Silent when the fix is off this route.
     *
     * @param array<string,mixed> $trip  a candidates()-shaped schedule row
     * @param array{lat:float,lng:float,kmh:?float} $fix
     * @param array<string,mixed> $result
     */
    private static function alertTrip(array $trip, array $fix, int $limit, int $nowTs, ?callable $send, array &$result): void
    {
        foreach (self::dueStops($trip, $fix, $limit, $nowTs, $result) as $d) {
            self::alertStop($trip, $d['stop'], $d['eta'], $d['km'], $send, $result);
        }
    }

    /**
     * The boarding stops this coach is now MIN_ETA_MIN..$maxEta minutes
     * from, after every "better silent than wrong" check: the fix lies on
     * this route, the stop is ahead and within MAX_ALERT_KM, and its
     * timetable time is within SCHEDULE_SLACK_H of now on THIS trip.
     *
     * @param array<string,mixed> $trip  a candidates()-shaped schedule row
     * @param array{lat:float,lng:float,kmh:?float} $fix
     * @param array<string,mixed> $result  'trips' counted, stops without coordinates named
     * @return list<array{stop: array<string,mixed>, eta: int, km: float}>
     */
    private static function dueStops(array $trip, array $fix, int $maxEta, int $nowTs, array &$result): array
    {
        $geo = self::routeLine((int) $trip['route_id']);
        $pos = self::locate($geo, (float) $fix['lat'], (float) $fix['lng']);
        if ($pos === null || $pos['off'] > self::OFF_ROUTE_KM) {
            return [];   // this coach is not on this route
        }
        $result['trips']++;
        foreach ($geo['missing'] as $m) {
            $result['skipped'][] = 'stop "' . $m . '" has no coordinates';
        }

        $due = [];
        foreach ($geo['line'] as $stop) {
            if ($stop['type'] !== 'boarding') {
                continue;
            }
            $ahead = $stop['km'] - $pos['km'];
            if ($ahead <= 0.5 || $ahead > self::MAX_ALERT_KM) {
                continue;
            }
            $eta = self::etaMinutes($ahead, $fix['kmh'] ?? null);
            if ($eta > $maxEta || $eta < self::MIN_ETA_MIN) {
                continue;
            }
            /* Timetable sanity: this stop is due around now on THIS trip. */
            if ($stop['time'] !== null) {
                $at = strtotime($trip['travel_date'] . ' ' . $stop['time']);
                if ($at < $trip['dep_ts'] - (int) $trip['delay_minutes'] * 60 - 3600) { $at += 86400; }   // past midnight
                $at += (int) $trip['delay_minutes'] * 60;
                if (abs($at - $nowTs) > self::SCHEDULE_SLACK_H * 3600) {
                    continue;
                }
            }
            $due[] = ['stop' => $stop, 'eta' => $eta, 'km' => $ahead];
        }
        return $due;
    }

    /** Tell every confirmed passenger boarding at $stop on $trip — once. */
    private static function alertStop(array $trip, array $stop, int $eta, float $km, ?callable $send, array &$result): void
    {
        $town = Boarding::townKey($stop['name']);
        $legs = Database::fetchAll(
            "SELECT b.*, l.boarding_stop
               FROM booking_legs l JOIN bookings b ON b.id = l.booking_id
              WHERE l.schedule_id = :s AND b.status = 'confirmed'",
            ['s' => (int) $trip['id']]
        );
        $said = (int) (ceil($eta / 5) * 5);   // "about 25 minutes", never a false-precise 23

        foreach ($legs as $b) {
            $label = (string) ($b['boarding_stop'] ?? '');
            if ($label === '' || Boarding::townKey($label) !== $town) {
                continue;
            }
            $claimed = Database::insertIgnore('trip_eta_alerts', [
                'booking_id'  => (int) $b['id'],
                'schedule_id' => (int) $trip['id'],
                'stop_name'   => mb_substr($stop['name'], 0, 191),
                'eta_min'     => $eta,
                'dist_km'     => round($km, 1),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            if ($claimed === 0) {
                continue;   // already told (or another run is telling them now)
            }
            $result['claimed']++;

            $facts = [
                'route'    => trim($trip['from_city'] . ' → ' . $trip['to_city']),
                'date'     => (string) $trip['travel_date'],
                'boarding' => Boarding::stopDisplay($label, $stop['name'])['name'],
                'etaMin'   => (string) $said,
            ];
            try {
                if ($send !== null) {
                    $r = $send($b, $facts);
                } else {
                    require_once __DIR__ . '/notify.php';
                    $r = Notify::tripEvent($b, 'bus_near', $facts);
                }
            } catch (Throwable $e) {
                $r = ['ok' => false, 'channels' => '', 'detail' => $e->getMessage()];
            }
            Database::update('trip_eta_alerts', [
                'channels' => mb_substr((string) ($r['channels'] ?? ''), 0, 40),
                'ok'       => !empty($r['ok']) ? 1 : 0,
            ], 'booking_id = :b AND schedule_id = :s', ['b' => (int) $b['id'], 's' => (int) $trip['id']]);
            if (!empty($r['ok'])) {
                $result['sent']++;
            }
        }
    }

    /* ---------------------------------------------------------------
     *  15 and 5 minutes: web push steps (15 Nov 2026, P8)
     * ------------------------------------------------------------- */

    public static function stepsEnabled(): bool
    {
        return Settings::getBool('eta_push_steps_on', false);
    }

    /**
     * The push steps, farthest first: eta_push_steps ('15,5'), each 5..60,
     * at most four. Anything unreadable falls back to 15 and 5.
     *
     * @return list<int>
     */
    public static function steps(): array
    {
        $out = [];
        foreach (explode(',', Settings::getString('eta_push_steps', '15,5')) as $p) {
            $p = trim($p);
            if ($p !== '' && ctype_digit($p) && (int) $p >= self::STEP_MIN_MIN && (int) $p <= self::STEP_MAX_MIN) {
                $out[(int) $p] = (int) $p;
            }
        }
        if ($out === []) {
            $out = [15 => 15, 5 => 5];
        }
        krsort($out);
        return array_slice(array_values($out), 0, 4);
    }

    /**
     * The closest ETA a step may still be told at. "About 15 minutes" is
     * honest down to 10, "about 5" down to MIN_ETA_MIN (3); below that the
     * banner would send a family out late, so it stays silent (review fix,
     * 15 Nov 2026: an ETA of 6 used to be told "about 15", and with steps
     * '30,5' an ETA of 6 was told "about 30").
     */
    public static function stepFloor(int $step): int
    {
        return max(self::MIN_ETA_MIN, $step - max(2, intdiv($step, 3)));
    }

    /**
     * The step an ETA belongs to: the closest step at or above it whose
     * honest window (stepFloor .. step) holds it (ETA 12 with steps 15,5 =
     * the 15 step; ETA 4 = the 5 step; ETA 7 = no step - between the two
     * windows nothing is said). Null = no step fits.
     *
     * @param list<int> $steps
     */
    public static function stepFor(int $eta, array $steps): ?int
    {
        $hit = null;
        foreach ($steps as $s) {
            if ($eta <= $s && $eta >= self::stepFloor($s) && ($hit === null || $s < $hit)) {
                $hit = $s;
            }
        }
        return $hit;
    }

    /** True once database/upgrade-2026-11-15-eta-steps.sql has run. */
    public static function stepsAvailable(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = Database::exists("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'eta_alert_steps'");
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Seconds the push service may hold the message for a phone that is
     * off. The banner names the step, so it may only arrive while the coach
     * is still inside that step's honest window: told at ETA $eta, it
     * expires once the ETA would have fallen below stepFloor() (15 told at
     * 15 -> 6 min, at 12 -> 3 min; 5 told at 5 -> 3 min, at 3 -> 1 min).
     * (Review fix, 15 Nov 2026: a flat 10 minutes let "about 15 minutes"
     * show with the coach 2-5 minutes away.)
     */
    public static function stepTtl(int $step, ?int $eta = null): int
    {
        $eta = min($eta ?? $step, $step);
        return max(60, ($eta - self::stepFloor($step) + 1) * 60);
    }

    /**
     * The lock-screen banner. Nepali first, English kept; one tap opens the
     * ticket. The tag is the 30-minute alert's own, so each step replaces
     * the last banner instead of stacking three.
     *
     * @return array{title:string, body:string, url:string, tag:string, lang:string}
     */
    public static function stepPayload(int $step, string $stop, string $pnr): array
    {
        $ne   = strtr((string) $step, ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४',
                                       '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९']);
        $stop = trim($stop) !== '' ? trim($stop) : 'तपाईंको स्टप · your stop';
        $note = $step <= self::STEP_MIN_MIN
            ? 'कृपया अहिले नै स्टपमा आइपुग्नुहोस् · Please be at your stop now.'
            : 'कृपया तयार भएर स्टपमा पुग्नुहोस् · Please get ready and walk to your stop.';
        return [
            'title' => mb_substr('🚌 बस ' . $ne . ' मिनेटमा ' . $stop . ' पुग्दैछ · Bus about ' . $step . ' min from ' . $stop, 0, 120),
            'body'  => mb_substr($note . ' ' . $pnr, 0, 400),
            'url'   => rtrim(APP_URL, '/') . '/#/ticket/' . rawurlencode($pnr),
            'tag'   => 'shg-bus_near-' . strtolower($pnr),
            'lang'  => 'ne',
        ];
    }

    /**
     * The 15 / 5 minute web push run (cron/eta-alerts.php, after run()).
     * Nothing at all while eta_push_steps_on is off, the table is missing,
     * or (real sends) web push is off on this server.
     *
     * @param array{lat:float,lng:float,kmh:?float}|null $fix  test override, as run()
     * @param callable(array,array,int):array|null $send  fn(booking, payload, step) -> {ok, pushed, detail}; default WebPush
     * @return array<string,mixed> one JSON-able line for cron_done()
     */
    public static function runSteps(?array $fix = null, ?int $nowTs = null, ?callable $send = null): array
    {
        $nowTs  = $nowTs ?? time();
        $steps  = self::steps();
        $result = ['steps' => implode(',', $steps), 'sent' => 0, 'claimed' => 0, 'trips' => 0, 'noPhone' => 0, 'skipped' => []];
        if (!self::stepsEnabled()) {
            return $result + ['idle' => 'eta_push_steps_on = 0'];
        }
        if (!self::stepsAvailable()) {
            return $result + ['idle' => 'eta_alert_steps missing (run database/upgrade-2026-11-15-eta-steps.sql)'];
        }
        require_once __DIR__ . '/webpush.php';
        if ($send === null && !WebPush::enabled()) {
            return $result + ['idle' => 'web push is off on this server'];
        }

        foreach (self::coaches($fix, $nowTs, $result) as [$trip, $tripFix]) {
            foreach (self::dueStops($trip, $tripFix, max($steps), $nowTs, $result) as $d) {
                $step = self::stepFor($d['eta'], $steps);
                if ($step !== null) {
                    self::stepStop($trip, $d['stop'], $step, $d['eta'], $d['km'], $send, $result);
                }
            }
        }
        return $result;
    }

    /** Push one step to every confirmed passenger boarding at $stop on $trip — once each. */
    private static function stepStop(array $trip, array $stop, int $step, int $eta, float $km, ?callable $send, array &$result): void
    {
        $sid  = (int) $trip['id'];
        $town = Boarding::townKey($stop['name']);
        $legs = Database::fetchAll(
            "SELECT b.*, l.boarding_stop
               FROM booking_legs l JOIN bookings b ON b.id = l.booking_id
              WHERE l.schedule_id = :s AND b.status = 'confirmed'",
            ['s' => $sid]
        );
        foreach ($legs as $b) {
            $label = (string) ($b['boarding_stop'] ?? '');
            if ($label === '' || Boarding::townKey($label) !== $town) {
                continue;
            }
            $bid = (int) $b['id'];
            /* This step went already, or a CLOSER one did: a GPS wobble
               must never follow "5 minutes" with "15 minutes". */
            if (Database::exists(
                'SELECT 1 FROM eta_alert_steps WHERE booking_id = :b AND schedule_id = :s AND step_min <= :m',
                ['b' => $bid, 's' => $sid, 'm' => $step]
            )) {
                continue;
            }
            if (self::mainAlertCovered($bid, $sid, $step)) {
                continue;
            }
            /* Only a phone that turned on alerts for this ticket (or its
               verified mobile). No phone = no claim, so a passenger who
               turns alerts on between the steps still gets the next one. */
            if (WebPush::forBooking($bid) === []) {
                $result['noPhone']++;
                continue;
            }
            $claimed = Database::insertIgnore('eta_alert_steps', [
                'booking_id'  => $bid,
                'schedule_id' => $sid,
                'step_min'    => $step,
                'stop_name'   => mb_substr($stop['name'], 0, 191),
                'eta_min'     => $eta,
                'dist_km'     => round($km, 1),
                'sent_at'     => date('Y-m-d H:i:s'),
            ]);
            if ($claimed === 0) {
                continue;   // another run is pushing this step now
            }
            $result['claimed']++;

            $payload = self::stepPayload($step, Boarding::stopDisplay($label, $stop['name'])['name'], (string) $b['pnr']);
            try {
                if ($send !== null) {
                    $r = $send($b, $payload, $step);
                } else {
                    $p = WebPush::sendToBooking($bid, $payload, 'bus_near_' . $step, self::stepTtl($step, $eta));
                    $r = ['ok' => $p['sent'] > 0, 'pushed' => $p['sent'], 'detail' => $p['failed'] > 0 ? $p['failed'] . ' failed' : ''];
                }
            } catch (Throwable $e) {
                $r = ['ok' => false, 'pushed' => 0, 'detail' => $e->getMessage()];
            }
            Database::update('eta_alert_steps', [
                'pushed' => max(0, min(255, (int) ($r['pushed'] ?? 0))),
                'ok'     => !empty($r['ok']) ? 1 : 0,
            ], 'booking_id = :b AND schedule_id = :s AND step_min = :m', ['b' => $bid, 's' => $sid, 'm' => $step]);
            if (!empty($r['ok'])) {
                $result['sent']++;
            }
        }
    }

    /**
     * Did the 30-minute alert already tell this passenger "about N minutes"
     * with N at or under this step (it fired late, e.g. the driver switched
     * the app on near the stop)? Then the step would only repeat it.
     */
    private static function mainAlertCovered(int $bookingId, int $scheduleId, int $step): bool
    {
        try {
            $eta = Database::scalar(
                'SELECT eta_min FROM trip_eta_alerts WHERE booking_id = :b AND schedule_id = :s AND ok = 1',
                ['b' => $bookingId, 's' => $scheduleId]
            );
        } catch (Throwable $e) {
            return false;
        }
        return $eta !== null && $eta !== false && (int) $eta <= $step;
    }
}
