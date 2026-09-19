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
 * Switch: eta_alert_on (default off) · threshold: eta_alert_minutes (30).
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/boarding.php';

final class EtaAlerts
{
    public const FIX_MAX_AGE_SEC  = 300;
    public const FIX_MAX_ACC_M    = 500;
    public const OFF_ROUTE_KM     = 25.0;
    public const ROAD_FACTOR      = 1.25;
    public const SCHEDULE_SLACK_H = 4;
    public const MAX_ALERT_KM     = 60.0;
    public const MIN_ETA_MIN      = 3;      // closer than this, the bus is in sight

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
     * @return array{line: list<array{name:string,type:string,time:?string,lat:float,lng:float,km:float}>, missing: list<string>}
     */
    public static function routeLine(int $routeId): array
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
        ], ''];
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
        $pos = self::project($geo['line'], (float) $fix['lat'], (float) $fix['lng']);
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

        if ($fix === null) {
            [$fix, $why] = self::fix($nowTs);
            if ($fix === null) {
                return $result + ['idle' => $why];
            }
        }

        $byRoute = [];
        foreach (self::candidates($nowTs) as $c) {
            $byRoute[(int) $c['route_id']][] = $c;
        }

        $limit = self::thresholdMinutes();
        foreach ($byRoute as $routeId => $trips) {
            if (count($trips) > 1) {
                $result['skipped'][] = 'route ' . $routeId . ': ' . count($trips) . ' buses on the road, one position - not guessing';
                continue;
            }
            $trip = $trips[0];
            $geo  = self::routeLine($routeId);
            $pos  = self::project($geo['line'], $fix['lat'], $fix['lng']);
            if ($pos === null || $pos['off'] > self::OFF_ROUTE_KM) {
                continue;   // this coach is not on this route
            }
            $result['trips']++;
            foreach ($geo['missing'] as $m) {
                $result['skipped'][] = 'stop "' . $m . '" has no coordinates';
            }

            foreach ($geo['line'] as $stop) {
                if ($stop['type'] !== 'boarding') {
                    continue;
                }
                $ahead = $stop['km'] - $pos['km'];
                if ($ahead <= 0.5 || $ahead > self::MAX_ALERT_KM) {
                    continue;
                }
                $eta = self::etaMinutes($ahead, $fix['kmh']);
                if ($eta > $limit || $eta < self::MIN_ETA_MIN) {
                    continue;
                }
                /* Timetable sanity: this stop is due around now on THIS trip. */
                if ($stop['time'] !== null) {
                    $due = strtotime($trip['travel_date'] . ' ' . $stop['time']);
                    if ($due < $trip['dep_ts'] - (int) $trip['delay_minutes'] * 60 - 3600) { $due += 86400; }   // past midnight
                    $due += (int) $trip['delay_minutes'] * 60;
                    if (abs($due - $nowTs) > self::SCHEDULE_SLACK_H * 3600) {
                        continue;
                    }
                }
                self::alertStop($trip, $stop, $eta, $ahead, $send, $result);
            }
        }
        return $result;
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
}
