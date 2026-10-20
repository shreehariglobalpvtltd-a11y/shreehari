<?php
/**
 * =====================================================================
 *  includes/routegeo.php — the road line of a route (20 Oct 2026,
 *  live tracking phase 2).
 *
 *  Until today "how far is the bus from Vadodara" was measured on a
 *  straight line from stop to stop x ROAD_FACTOR. This file keeps ONE
 *  real road line per route (table route_geometry, migration
 *  database/upgrade-2026-10-route-geometry.sql):
 *
 *    build()   asks the Valhalla router (the same engine api/nav-route.php
 *              uses: our own first, the public server only when ours does
 *              not answer) for the road through the route's stops that HAVE
 *              coordinates, in travel order, simplifies it to ~15 m and
 *              stores it as an encoded polyline. Each rebuild bumps version.
 *    load()    the stored line, decoded, with the running km at every point.
 *    locate()  where a GPS fix lies along that line: {km, off}.
 *
 *  Never invents a coordinate: a stop without one is left out (and named
 *  in the result), and the router decides the road between the stops it
 *  was given. A stop that ends up far from the road (STOP_SNAP_KM) makes
 *  the build fail rather than store a line that does not pass it.
 *
 *  Readers (EtaAlerts::routeLine, track.php, api/track-map.php) treat a
 *  missing table or row as "no road line" and use the old line of stops.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/etaalerts.php';

final class RouteGeo
{
    /** Douglas-Peucker tolerance for the stored line. */
    public const TOLERANCE_M = 15.0;
    /** A stop further than this from the routed road = refuse the line. */
    public const STOP_SNAP_KM = 3.0;

    /** Tests of the old stop-line path switch the road line off. */
    public static bool $enabled = true;

    private static ?bool $available = null;
    /** @var array<int, array<string,mixed>|null> */
    private static array $cache = [];

    /** Is the migration on this database? A missing table never breaks a page. */
    public static function available(): bool
    {
        if (self::$available === null) {
            try {
                Database::fetch('SELECT route_id, version FROM route_geometry LIMIT 1');
                self::$available = true;
            } catch (Throwable $e) {
                self::$available = false;
            }
        }
        return self::$available;
    }

    /** For tests and after a build: forget what this request has read. */
    public static function resetCache(): void
    {
        self::$available = null;
        self::$cache = [];
    }

    /**
     * The stored row without the line itself (for the office list).
     *
     * @return array{route_id:int, version:int, length_m:int, source:string, built_at:string, bytes:int}|null
     */
    public static function info(int $routeId): ?array
    {
        if (!self::available()) {
            return null;
        }
        $r = Database::fetch(
            'SELECT route_id, version, length_m, source, built_at, CHAR_LENGTH(polyline6) AS bytes
               FROM route_geometry WHERE route_id = :r',
            ['r' => $routeId]
        );
        if ($r === null) {
            return null;
        }
        return ['route_id' => (int) $r['route_id'], 'version' => (int) $r['version'], 'length_m' => (int) $r['length_m'],
                'source' => (string) $r['source'], 'built_at' => (string) $r['built_at'], 'bytes' => (int) $r['bytes']];
    }

    /**
     * The road line of a route, decoded, or null when none is stored (or the
     * road line is switched off for a test).
     *
     * @return array{routeId:int, version:int, length_m:int, source:string, built_at:string, polyline6:string,
     *               lat:list<float>, lng:list<float>, cum:list<float>}|null   cum = km from the first point
     */
    public static function load(int $routeId): ?array
    {
        if (!self::$enabled || !self::available()) {
            return null;
        }
        if (array_key_exists($routeId, self::$cache)) {
            return self::$cache[$routeId];
        }
        $r = Database::fetch('SELECT route_id, version, polyline6, length_m, source, built_at FROM route_geometry WHERE route_id = :r', ['r' => $routeId]);
        $out = null;
        if ($r !== null) {
            $pts = self::decode((string) $r['polyline6']);
            if (count($pts) >= 2) {
                $out = ['routeId' => $routeId, 'version' => (int) $r['version'], 'length_m' => (int) $r['length_m'],
                        'source' => (string) $r['source'], 'built_at' => (string) $r['built_at'], 'polyline6' => (string) $r['polyline6'],
                        'lat' => array_column($pts, 0), 'lng' => array_column($pts, 1), 'cum' => self::cumulativeKm($pts)];
            }
        }
        return self::$cache[$routeId] = $out;
    }

    /* ---------------------------------------------------------------
     *  Polyline (Google / Valhalla encoding, precision 6 by default)
     * ------------------------------------------------------------- */

    /** @param list<array{0:float,1:float}> $pts  [lat, lng] */
    public static function encode(array $pts, int $precision = 6): string
    {
        $f = 10 ** $precision;
        $out = '';
        $pLat = 0; $pLng = 0;
        foreach ($pts as $p) {
            $lat = (int) round($p[0] * $f);
            $lng = (int) round($p[1] * $f);
            foreach ([$lat - $pLat, $lng - $pLng] as $v) {
                $v = $v < 0 ? ~($v << 1) : ($v << 1);
                while ($v >= 0x20) {
                    $out .= chr((0x20 | ($v & 0x1f)) + 63);
                    $v >>= 5;
                }
                $out .= chr($v + 63);
            }
            $pLat = $lat; $pLng = $lng;
        }
        return $out;
    }

    /** @return list<array{0:float,1:float}>  [lat, lng] */
    public static function decode(string $s, int $precision = 6): array
    {
        $f = 10 ** $precision;
        $pts = [];
        $i = 0; $n = strlen($s); $lat = 0; $lng = 0;
        while ($i < $n) {
            $vals = [0, 0];
            for ($k = 0; $k < 2; $k++) {
                $shift = 0; $res = 0;
                do {
                    if ($i >= $n) {
                        return $pts;   // truncated string: keep what decoded cleanly
                    }
                    $b = ord($s[$i++]) - 63;
                    $res |= ($b & 0x1f) << $shift;
                    $shift += 5;
                } while ($b >= 0x20);
                $vals[$k] = ($res & 1) ? ~($res >> 1) : ($res >> 1);
            }
            $lat += $vals[0];
            $lng += $vals[1];
            $pts[] = [$lat / $f, $lng / $f];
        }
        return $pts;
    }

    /* ---------------------------------------------------------------
     *  Geometry
     * ------------------------------------------------------------- */

    /**
     * Running km from the first point.
     *
     * @param list<array{0:float,1:float}> $pts
     * @return list<float>
     */
    public static function cumulativeKm(array $pts): array
    {
        $cum = [];
        $km = 0.0;
        foreach ($pts as $i => $p) {
            if ($i > 0) {
                $km += EtaAlerts::haversineKm($pts[$i - 1][0], $pts[$i - 1][1], $p[0], $p[1]);
            }
            $cum[] = $km;
        }
        return $cum;
    }

    /**
     * Douglas-Peucker on a local flat projection (exact enough for a tolerance
     * of metres). Iterative: a 50 000-point road must not blow the stack.
     *
     * @param list<array{0:float,1:float}> $pts
     * @return list<array{0:float,1:float}>
     */
    public static function simplify(array $pts, float $tolM = self::TOLERANCE_M): array
    {
        $n = count($pts);
        if ($n < 3) {
            return $pts;
        }
        $keep = array_fill(0, $n, false);
        $keep[0] = $keep[$n - 1] = true;
        $stack = [[0, $n - 1]];
        $tol2 = $tolM * $tolM;
        while ($stack !== []) {
            [$a, $b] = array_pop($stack);
            // metres on a flat plane centred on this chord's start
            $kx = 111320.0 * cos(deg2rad($pts[$a][0]));
            $dx = ($pts[$b][1] - $pts[$a][1]) * $kx; $dy = ($pts[$b][0] - $pts[$a][0]) * 110574.0;
            $len2 = $dx * $dx + $dy * $dy;
            $best = -1.0; $idx = -1;
            for ($i = $a + 1; $i < $b; $i++) {
                $px = ($pts[$i][1] - $pts[$a][1]) * $kx; $py = ($pts[$i][0] - $pts[$a][0]) * 110574.0;
                if ($len2 > 0) {
                    $t = max(0.0, min(1.0, ($px * $dx + $py * $dy) / $len2));
                    $qx = $px - $t * $dx; $qy = $py - $t * $dy;
                } else {
                    $qx = $px; $qy = $py;
                }
                $d2 = $qx * $qx + $qy * $qy;
                if ($d2 > $best) { $best = $d2; $idx = $i; }
            }
            if ($idx > 0 && $best > $tol2) {
                $keep[$idx] = true;
                $stack[] = [$a, $idx];
                $stack[] = [$idx, $b];
            }
        }
        $out = [];
        foreach ($pts as $i => $p) {
            if ($keep[$i]) { $out[] = [round($p[0], 6), round($p[1], 6)]; }
        }
        return $out;
    }

    /**
     * Where along the road line is this point? Nearest segment on a local
     * flat projection, clamped to the segment; the distance off the line is
     * then measured properly.
     *
     * @param array{lat:list<float>, lng:list<float>, cum:list<float>} $road
     * @return array{km: float, off: float}|null
     */
    public static function locate(array $road, float $lat, float $lng): ?array
    {
        $n = count($road['lat']);
        if ($n < 2) {
            return null;
        }
        $kx = cos(deg2rad($lat));
        $L = $road['lat']; $G = $road['lng']; $C = $road['cum'];
        $best = INF; $bi = 0; $bt = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            $ax = ($G[$i] - $lng) * $kx;          $ay = $L[$i] - $lat;
            $bx = ($G[$i + 1] - $lng) * $kx - $ax; $by = $L[$i + 1] - $lat - $ay;
            $len2 = $bx * $bx + $by * $by;
            $t = $len2 > 0 ? max(0.0, min(1.0, -($ax * $bx + $ay * $by) / $len2)) : 0.0;
            $qx = $ax + $t * $bx; $qy = $ay + $t * $by;
            $d2 = $qx * $qx + $qy * $qy;
            if ($d2 < $best) { $best = $d2; $bi = $i; $bt = $t; }
        }
        $qLat = $L[$bi] + $bt * ($L[$bi + 1] - $L[$bi]);
        $qLng = $G[$bi] + $bt * ($G[$bi + 1] - $G[$bi]);
        return ['km' => $C[$bi] + $bt * ($C[$bi + 1] - $C[$bi]),
                'off' => EtaAlerts::haversineKm($lat, $lng, $qLat, $qLng)];
    }

    /**
     * The point $km along the line (clamped to its ends).
     *
     * @param array{lat:list<float>, lng:list<float>, cum:list<float>} $road
     * @return array{lat: float, lng: float}
     */
    public static function pointAt(array $road, float $km): array
    {
        $C = $road['cum'];
        $n = count($C);
        if ($km <= 0 || $n < 2) {
            return ['lat' => $road['lat'][0], 'lng' => $road['lng'][0]];
        }
        if ($km >= $C[$n - 1]) {
            return ['lat' => $road['lat'][$n - 1], 'lng' => $road['lng'][$n - 1]];
        }
        $lo = 0; $hi = $n - 1;
        while ($hi - $lo > 1) {
            $mid = intdiv($lo + $hi, 2);
            if ($C[$mid] <= $km) { $lo = $mid; } else { $hi = $mid; }
        }
        $span = $C[$hi] - $C[$lo];
        $t = $span > 0 ? ($km - $C[$lo]) / $span : 0.0;
        return ['lat' => $road['lat'][$lo] + $t * ($road['lat'][$hi] - $road['lat'][$lo]),
                'lng' => $road['lng'][$lo] + $t * ($road['lng'][$hi] - $road['lng'][$lo])];
    }

    /* ---------------------------------------------------------------
     *  Build
     * ------------------------------------------------------------- */

    /**
     * Ask Valhalla for the road through these points (in order). Our own
     * engine first (setting nav_engine_url, as api/nav-route.php); the public
     * server only when ours does not answer and nav_fallback_public is on.
     *
     * @param list<array{lat:float,lng:float}> $stops
     * @return array{ok:bool, pts:list<array{0:float,1:float}>, source:string, error:string, routerKm:?float}
     */
    public static function valhalla(array $stops): array
    {
        $body = json_encode([
            'locations'          => array_map(static fn(array $s): array => ['lat' => round((float) $s['lat'], 6), 'lon' => round((float) $s['lng'], 6), 'type' => 'break'], $stops),
            'costing'            => 'bus',
            'directions_options' => ['units' => 'kilometers'],
            'directions_type'    => 'none',
        ], JSON_UNESCAPED_SLASHES);
        $call = static function (string $base, int $timeout) use ($body): array {
            $ch = curl_init(rtrim($base, '/') . '/route');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_USERAGENT => 'SHARI-NAV/1.0 (+https://www.shreehariglobal.in)']);
            $raw  = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);
            return [$http, is_string($raw) && $raw !== '' ? $raw : (string) json_encode(['error' => $cerr !== '' ? $cerr : 'no answer'])];
        };
        $own = Settings::getString('nav_engine_url', 'http://127.0.0.1:8002');
        [$http, $raw] = $call($own, 30);
        $source = 'valhalla:own';
        if ($http !== 200) {
            $err = json_decode($raw, true);
            $outside = $http === 0 || $http >= 500 || in_array((int) ($err['error_code'] ?? 0), [170, 171, 442, 443], true);
            if (!$outside || !Settings::getBool('nav_fallback_public', true)) {
                return ['ok' => false, 'pts' => [], 'source' => $source, 'routerKm' => null,
                        'error' => 'the route engine said: ' . (string) ($err['error'] ?? ('HTTP ' . $http))];
            }
            [$http, $raw] = $call('https://valhalla1.openstreetmap.de', 45);
            $source = 'valhalla:public';
        }
        $j = json_decode($raw, true);
        $legs = $j['trip']['legs'] ?? null;
        if ($http !== 200 || !is_array($legs) || $legs === []) {
            return ['ok' => false, 'pts' => [], 'source' => $source, 'routerKm' => null,
                    'error' => 'the route engine said: ' . (string) ($j['error'] ?? ('HTTP ' . $http))];
        }
        $pts = [];
        foreach ($legs as $leg) {
            $p = self::decode((string) ($leg['shape'] ?? ''));
            if ($pts !== [] && $p !== []) {
                array_shift($p);   // each leg starts where the last one ended
            }
            array_push($pts, ...$p);
        }
        return ['ok' => count($pts) >= 2, 'pts' => $pts, 'source' => $source, 'error' => count($pts) >= 2 ? '' : 'the route engine returned no road',
                'routerKm' => isset($j['trip']['summary']['length']) ? (float) $j['trip']['summary']['length'] : null];
    }

    /**
     * Build and store the road line of one route.
     *
     * @param callable(list<array{lat:float,lng:float}>):array|null $router  test override; default valhalla()
     * @return array{ok:bool, error?:string, version?:int, km?:float, points?:int, rawPoints?:int, stops:list<string>, missing:list<string>, source?:string}
     */
    public static function build(int $routeId, ?callable $router = null): array
    {
        if (!self::available()) {
            return ['ok' => false, 'error' => 'the route_geometry table is missing (apply database/upgrade-2026-10-route-geometry.sql)', 'stops' => [], 'missing' => []];
        }
        $geo   = EtaAlerts::stopLine($routeId);
        $stops = [];
        foreach ($geo['line'] as $s) {
            $last = end($stops);
            if ($last !== false && EtaAlerts::haversineKm($last['lat'], $last['lng'], $s['lat'], $s['lng']) < 0.05) {
                continue;   // the same place twice (a pickup and its landmark): one router point
            }
            $stops[] = $s;
        }
        $names = array_map(static fn(array $s): string => $s['name'], $stops);
        if (count($stops) < 2) {
            return ['ok' => false, 'error' => 'fewer than two stops of this route have coordinates', 'stops' => $names, 'missing' => $geo['missing']];
        }

        $r = $router !== null ? $router($stops) : self::valhalla($stops);
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => (string) ($r['error'] ?? 'no road'), 'stops' => $names, 'missing' => $geo['missing']];
        }
        $raw  = (array) $r['pts'];
        $pts  = self::simplify($raw, self::TOLERANCE_M);
        $cum  = self::cumulativeKm($pts);
        $road = ['lat' => array_column($pts, 0), 'lng' => array_column($pts, 1), 'cum' => $cum];

        // Every stop must sit on the road, in travel order.
        $prev = -1.0;
        foreach ($stops as $s) {
            $at = self::locate($road, $s['lat'], $s['lng']);
            if ($at === null || $at['off'] > self::STOP_SNAP_KM) {
                return ['ok' => false, 'stops' => $names, 'missing' => $geo['missing'],
                        'error' => 'stop "' . $s['name'] . '" is ' . ($at !== null ? round($at['off'], 1) : '?') . ' km from the road the router found - check its coordinates'];
            }
            if ($at['km'] + 0.5 < $prev) {
                return ['ok' => false, 'stops' => $names, 'missing' => $geo['missing'],
                        'error' => 'stop "' . $s['name'] . '" comes before the previous stop on the road - check the stop order'];
            }
            $prev = $at['km'];
        }

        $len    = (int) round(end($cum) * 1000);
        $poly   = self::encode($pts);
        $source = mb_substr((string) ($r['source'] ?? 'valhalla'), 0, 40);
        Database::run(
            'INSERT INTO route_geometry (route_id, version, polyline6, length_m, source, built_at)
             VALUES (:r, 1, :p, :l, :s, :t)
             ON DUPLICATE KEY UPDATE version = version + 1, polyline6 = VALUES(polyline6), length_m = VALUES(length_m),
                                     source = VALUES(source), built_at = VALUES(built_at)',
            ['r' => $routeId, 'p' => $poly, 'l' => $len, 's' => $source, 't' => date('Y-m-d H:i:s')]
        );
        self::resetCache();
        $info = self::info($routeId);
        return ['ok' => true, 'version' => (int) ($info['version'] ?? 1), 'km' => round($len / 1000, 1),
                'points' => count($pts), 'rawPoints' => count($raw), 'bytes' => strlen($poly),
                'stops' => $names, 'missing' => $geo['missing'], 'source' => $source];
    }
}
