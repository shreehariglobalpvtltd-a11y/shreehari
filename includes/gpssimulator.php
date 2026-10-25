<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/routegeo.php';

/** Private demo only: never creates fleet buses, trips, trackers, or GPS reports. */
final class GpsSimulator
{
    public static function options(array $d): array
    {
        $count = (int) ($d['count'] ?? 3); $speed = (float) ($d['speed'] ?? 60); $route = (int) ($d['route_id'] ?? 0);
        if ($count < 1 || $count > 3 || !is_finite($speed) || $speed < 5 || $speed > 120 || $route < 1) {
            throw new RuntimeException('Choose a route, 1–3 demo buses, and a speed between 5 and 120 km/h.');
        }
        return ['routeId' => $route, 'count' => $count, 'speed' => $speed];
    }

    public static function state(): array
    {
        return Settings::getArray('gps_simulator', []) + ['routeId' => 0, 'count' => 3, 'speed' => 60.0, 'running' => false, 'elapsed' => 0, 'changedAt' => time()];
    }

    public static function elapsed(array $s, float $now): float
    {
        return max(0.0, (float) ($s['elapsed'] ?? 0)) + (!empty($s['running']) ? max(0.0, $now - (float) $s['changedAt']) : 0.0);
    }

    public static function save(string $action, array $d, int $by, ?int $now = null): array
    {
        $now ??= time(); $s = self::state();
        if ($action === 'start') {
            $options = self::options($d); self::route($options['routeId']);
            $elapsed = $s['routeId'] === $options['routeId'] ? self::elapsed($s, $now) * (float) $s['speed'] / $options['speed'] : 0;
            $s = $options + ['elapsed' => $elapsed, 'running' => true, 'changedAt' => $now];
        } elseif ($action === 'pause') {
            $s['elapsed'] = self::elapsed($s, $now); $s['running'] = false; $s['changedAt'] = $now;
        } elseif ($action === 'reset') {
            $s['elapsed'] = 0; $s['running'] = false; $s['changedAt'] = $now;
        } else { throw new RuntimeException('Unknown simulation action.'); }
        Settings::set('gps_simulator', $s, 'json', 'automation'); Settings::flush();
        Logger::audit('gps_simulation.' . $action, 'settings', 'gps_simulator', null,
            ['route' => $s['routeId'], 'count' => $s['count'], 'speed' => $s['speed']], 'private demo by admin #' . $by);
        return $s;
    }

    public static function route(int $id): array
    {
        $route = Database::fetch('SELECT id,from_city,to_city FROM routes WHERE id=:r AND is_active=1', ['r' => $id]);
        if ($route === null) { throw new RuntimeException('Active route not found.'); }
        $road = RouteGeo::load($id); $pts = [];
        if ($road !== null) { foreach ($road['lat'] as $i => $lat) { $pts[] = [$lat, $road['lng'][$i]]; } }
        else {
            foreach (Database::fetchAll("SELECT latitude,longitude FROM route_stops WHERE route_id=:r AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY FIELD(stop_type,'boarding','drop'),sort_order,id", ['r' => $id]) as $stop) {
                if ((float) $stop['latitude'] !== 0.0 && (float) $stop['longitude'] !== 0.0) { $pts[] = [(float) $stop['latitude'], (float) $stop['longitude']]; }
            }
        }
        if (count($pts) < 2) { throw new RuntimeException('This route needs at least two mapped stops.'); }
        $cum = RouteGeo::cumulativeKm($pts);
        if (end($cum) <= 0) { throw new RuntimeException('Route has no usable length.'); }
        return ['id' => $id, 'name' => $route['from_city'] . ' → ' . $route['to_city'], 'points' => $pts, 'cum' => $cum,
            'source' => $road !== null ? 'Road geometry' : 'Straight lines between stops (demo preview)'];
    }

    public static function point(array $route, float $distance): array
    {
        $total = (float) end($route['cum']); $cycle = fmod(max(0.0, $distance), $total * 2); $reverse = $cycle > $total;
        $km = $reverse ? $total * 2 - $cycle : $cycle; $lo = 0; $hi = count($route['cum']) - 1;
        while ($lo + 1 < $hi) { $mid = intdiv($lo + $hi, 2); if ($route['cum'][$mid] <= $km) { $lo = $mid; } else { $hi = $mid; } }
        $a = $route['points'][$lo]; $b = $route['points'][$hi]; $span = $route['cum'][$hi] - $route['cum'][$lo];
        $f = $span > 0 ? max(0.0, min(1.0, ($km - $route['cum'][$lo]) / $span)) : 0;
        $dy = deg2rad($b[1] - $a[1]); $la = deg2rad($a[0]); $lb = deg2rad($b[0]);
        $bearing = rad2deg(atan2(sin($dy) * cos($lb), cos($la) * sin($lb) - sin($la) * cos($lb) * cos($dy)));
        return ['lat' => round($a[0] + ($b[0] - $a[0]) * $f, 7), 'lng' => round($a[1] + ($b[1] - $a[1]) * $f, 7),
            'bearing' => (int) round(fmod($bearing + ($reverse ? 180 : 0) + 360, 360)) % 360, 'km' => round($km, 3)];
    }

    public static function frame(array $s, array $route, float $now): array
    {
        $elapsed = self::elapsed($s, $now); $buses = [];
        for ($i = 0; $i < min(3, max(1, (int) $s['count'])); $i++) {
            $point = self::point($route, $elapsed * (float) $s['speed'] / 3600 + $i * 0.8);
            $buses[] = $point + ['id' => 'DEMO-' . ($i + 1), 'bus' => 'DEMO-0' . ($i + 1), 'simulation' => true,
                'kmh' => !empty($s['running']) ? (float) $s['speed'] : 0, 'fixAt' => (int) round($now * 1000)];
        }
        return ['simulation' => true, 'running' => !empty($s['running']), 'at' => (int) round($now * 1000),
            'elapsed' => round($elapsed, 1), 'route' => $route['name'], 'source' => $route['source'], 'buses' => $buses];
    }
}
