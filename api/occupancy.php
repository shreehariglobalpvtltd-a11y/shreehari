<?php
/**
 * =====================================================================
 *  GET /api/occupancy.php?from=Surat&to=Rupaidiha&start=YYYY-MM-DD&days=7
 *
 *  Seat fill for the next N days of one direction — the "pick a less
 *  crowded date" chart on the results screen (13 Sep 2026).
 *
 *  Reply: { from, to, start, days: [ { date, total, booked, left, pct,
 *           sellable, soldOut, off } ] }
 *
 *  Same route matching as api/search.php (a town matches when it is the
 *  endpoint city OR a stop the coach calls at) and the same seat engine
 *  (Seats::availability, physical inventory across sharing/private), so
 *  the bar and the board can never disagree about a date. Read-only,
 *  publicly cacheable for two minutes — a chart, not a booking gate.
 * =====================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    $from  = Security::clean((string) ($_GET['from'] ?? Response::field('from', '')), 80);
    $to    = Security::clean((string) ($_GET['to'] ?? Response::field('to', '')), 80);
    $start = Security::clean((string) ($_GET['start'] ?? Response::field('start', '')), 10);
    $days  = (int) ($_GET['days'] ?? Response::field('days', 7));
    $days  = max(1, min(14, $days));

    if ($from === '' || $to === '') {
        Response::invalid(['from' => 'Choose your route.']);
    }
    Security::requireRateLimit('occupancy', Security::clientIp(), 60, 60);

    $window = bookingWindow();
    if ($start === '' || !Security::isValidDate($start) || $start < $window['from']) {
        $start = max(todayISO(), $window['from']);
    }

    /* ---- the same town → route matching search.php uses -------------- */
    $expand = static function (string $city): array {
        $pair = ['nepalgunj', 'rupaidiha'];
        return in_array(strtolower($city), $pair, true) ? $pair : [strtolower($city)];
    };
    $fromList = $expand($from);
    $toList   = $expand($to);

    $candidates = Database::fetchAll(
        'SELECT r.id, r.from_city, r.to_city, r.route_code
           FROM routes r
           LEFT JOIN buses b ON b.id = r.bus_id
          WHERE r.is_active = 1 AND (b.id IS NULL OR b.is_active = 1)
          ORDER BY r.sort_order, r.dep_time'
    );
    $servesTown = static function (int $routeId, string $type, string $town): bool {
        static $cache = [];
        $key = $routeId . '|' . $type;
        if (!isset($cache[$key])) {
            $cache[$key] = array_map(
                static fn(array $r): string => Boarding::townKey((string) $r['stop_name']),
                Database::fetchAll('SELECT stop_name FROM route_stops WHERE route_id = :r AND stop_type = :t', ['r' => $routeId, 't' => $type])
            );
        }
        return in_array(Boarding::townKey($town), $cache[$key], true);
    };
    $matches = static function (array $route, string $side, array $wanted) use ($servesTown): bool {
        $cityCol = $side === 'boarding' ? 'from_city' : 'to_city';
        foreach ($wanted as $town) {
            if (strcasecmp((string) $route[$cityCol], $town) === 0 || $servesTown((int) $route['id'], $side, $town)) {
                return true;
            }
        }
        return false;
    };
    $routes = array_values(array_filter(
        $candidates,
        static fn(array $r): bool => $matches($r, 'boarding', $fromList) && $matches($r, 'drop', $toList)
    ));

    $serviceOn = Settings::getBool('daily_service_on', true);
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $date = addDaysISO($start, $i);
        if ($date > $window['to']) {
            break;
        }
        $total = 0; $left = 0; $sellable = false; $off = !$serviceOn || $routes === [];
        foreach ($routes as $route) {
            $routeId = (int) $route['id'];
            try {
                $schedule = Seats::schedule($routeId, $date);
                if ((int) ($schedule['is_blocked'] ?? 0) === 1 || (string) ($schedule['status'] ?? 'scheduled') === 'cancelled') {
                    continue;
                }
                $av = Seats::availability($routeId, $date, 'sharing', (int) $schedule['id']);
                $total += (int) $av['total'];
                $left  += (int) $av['availableCount'];
                if (Boarding::routeSellable($routeId, $date)) {
                    $sellable = true;
                }
            } catch (Throwable $e) {
                // one bad row must not blank the whole chart
            }
        }
        $booked = max(0, $total - $left);
        $out[] = [
            'date'     => $date,
            'total'    => $total,
            'booked'   => $booked,
            'left'     => $left,
            'pct'      => $total > 0 ? (int) round($booked * 100 / $total) : 0,
            'sellable' => $sellable && !$off && $total > 0,
            'soldOut'  => $total > 0 && $left <= 0,
            'off'      => $off || $total === 0,
        ];
    }

    Response::$publicCacheSeconds = 120;
    Response::success(['from' => $from, 'to' => $to, 'start' => $start, 'days' => $out]);
} catch (Throwable $e) {
    Response::serverError($e);
}
