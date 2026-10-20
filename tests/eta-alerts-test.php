<?php
/**
 * =====================================================================
 *  eta-alerts-test.php — "the bus is ~30 min away": right person, once,
 *  and silence whenever it cannot be sure (19 Sep 2026).
 *
 *  includes/etaalerts.php turns the driver's published position into one
 *  message per waiting passenger. A wrong one sends a family to a highway
 *  at night, so this suite drives the engine with a synthetic position and
 *  a synthetic clock on a far-future trip and pins:
 *
 *    - geometry: distance, projection onto the line of stops, ETA bands
 *    - the passenger at the NEXT stop is told; the one two stops on, the
 *      one at a stop already passed, and a cancelled ticket are not
 *    - told ONCE: a second run sends nothing
 *    - silence: off-route position, stale / inaccurate fix, two buses of
 *      one route on the road, a stop whose timetable time is hours away
 *    - nothing is sent through a real channel here: the sender is injected
 *    - 20 Oct 2026: the stored ROAD line (includes/routegeo.php) - polyline,
 *      simplify, locate, build with an injected router, km to a stop along
 *      the road, and every fall-back to the line of stops
 *
 *    php -c .claude/php-dev.ini tests/eta-alerts-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/etaalerts.php';
require_once INCLUDE_PATH . '/routegeo.php';

/* The sections before "the road line" pin the line-of-stops answer, so the
   road line is off for them whatever this database has built. */
RouteGeo::$enabled = false;

const ETA_DATE = '2099-11-25';
const ETA_TAG  = 'SHG-ETA-';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$cleanup = static function (): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE '" . ETA_TAG . "%'") as $b) {
        Database::delete('trip_eta_alerts', 'booking_id = :b', ['b' => (int) $b['id']]);
        Database::delete('bookings', 'id = :b', ['b' => (int) $b['id']]);
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => ETA_DATE]);
    Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus' AND updated_by = 'eta-test'");
};

/** A fake "router": the stops joined by ~1 km steps, the first gap bowed $bulgeKm to one side. */
function fakeRoad(array $stops, float $bulgeKm = 0.0, int $shiftStop = -1, float $shiftDeg = 0.0): array {
    $pts = [];
    if ($shiftStop >= 0) { $stops[$shiftStop]['lng'] += $shiftDeg; }
    for ($i = 0, $n = count($stops) - 1; $i < $n; $i++) {
        $a = $stops[$i]; $b = $stops[$i + 1];
        $steps = max(4, (int) ceil(EtaAlerts::haversineKm($a['lat'], $a['lng'], $b['lat'], $b['lng'])));
        $kx = cos(deg2rad(($a['lat'] + $b['lat']) / 2));
        $dx = ($b['lng'] - $a['lng']) * $kx * 111.32; $dy = ($b['lat'] - $a['lat']) * 110.574; $len = max(1e-9, hypot($dx, $dy));
        for ($k = $i === 0 ? 0 : 1; $k <= $steps; $k++) {
            $t = $k / $steps; $o = $i === 0 ? $bulgeKm * sin(M_PI * $t) : 0.0;
            $pts[] = [$a['lat'] + $t * ($b['lat'] - $a['lat']) + ($dx / $len) * $o / 110.574,
                      $a['lng'] + $t * ($b['lng'] - $a['lng']) - ($dy / $len) * $o / (111.32 * $kx)];
        }
    }
    return ['ok' => true, 'pts' => $pts, 'source' => 'test', 'error' => ''];
}

$n = 0;
function rider(int $sid, string $boarding, string $status = 'confirmed'): int {
    global $n; $n++;
    $bid = Database::insert('bookings', [
        'pnr' => ETA_TAG . $n . '-' . substr(md5((string) microtime(true)), 0, 6), 'contact_phone' => '90000020' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
        'total_amount' => 2000, 'status' => $status, 'source' => 'admin',
    ]);
    Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'travel_date' => ETA_DATE, 'seat_count' => 1, 'boarding_stop' => $boarding]);
    return $bid;
}

echo "-- geometry --\n";
$d = EtaAlerts::haversineKm(21.1702, 72.8311, 22.3072, 73.1812);
check('Surat -> Baroda is ~131 km in a straight line', $d > 126 && $d < 136, (string) round($d, 1));
$line = [
    ['name' => 'A', 'type' => 'boarding', 'time' => null, 'lat' => 22.0, 'lng' => 73.0, 'km' => 0.0],
    ['name' => 'B', 'type' => 'boarding', 'time' => null, 'lat' => 23.0, 'lng' => 73.0, 'km' => 139.0],
];
$p = EtaAlerts::project($line, 22.5, 73.0);
check('a point half way along is at half the road-km and on the line', $p !== null && abs($p['km'] - 69.5) < 0.6 && $p['off'] < 0.1, json_encode($p));
$p = EtaAlerts::project($line, 22.5, 73.5);
check('a point 50 km to the side is reported as off the line', $p !== null && $p['off'] > 45, (string) round($p['off'], 1));
check('a single stop is not a line', EtaAlerts::project([$line[0]], 22.5, 73.0) === null);
check('ETA: 20 km at 40 km/h = 30 min', EtaAlerts::etaMinutes(20, 40.0) === 30);
check('ETA: a crawl or no speed is read as 40 km/h, never as "hours"', EtaAlerts::etaMinutes(20, 3.0) === 30 && EtaAlerts::etaMinutes(20, null) === 30);
check('ETA: 120 km/h is capped at 70', EtaAlerts::etaMinutes(35, 120.0) === 30);

$hasTable = Database::exists("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'trip_eta_alerts'");
$route = null;
foreach (Database::fetchAll("SELECT * FROM routes WHERE is_active = 1 ORDER BY id") as $r) {
    $geoStops = array_values(array_filter(EtaAlerts::routeLine((int) $r['id'])['line'], static fn($s) => $s['type'] === 'boarding'));
    if (count($geoStops) >= 3) { $route = $r; break; }
}

if (!$hasTable) {
    check('trip_eta_alerts exists (apply database/upgrade-2026-09-eta-alerts.sql)', false);
} elseif ($route === null) {
    echo "  \033[33mSKIP\033[0m  needs a route with three boarding stops that have coordinates\n";
} else {
    $cleanup();
    try {
        $routeId = (int) $route['id'];
        $sid     = (int) Seats::schedule($routeId, ETA_DATE)['id'];
        $stops   = array_values(array_filter(EtaAlerts::routeLine($routeId)['line'], static fn($s) => $s['type'] === 'boarding'));
        [$first, $mid, $last] = [$stops[0], $stops[1], $stops[2]];

        /* The coach is 90 % of the way from the first stop to the middle one,
           doing 45 km/h, at the middle stop's timetable time minus 20 min. */
        $fix = ['lat' => $first['lat'] + 0.9 * ($mid['lat'] - $first['lat']),
                'lng' => $first['lng'] + 0.9 * ($mid['lng'] - $first['lng']), 'kmh' => 45.0];
        $ahead = ($mid['km'] - $first['km']) * 0.1;
        $now   = strtotime(ETA_DATE . ' ' . ($mid['time'] ?? '17:00:00')) - 20 * 60;
        $expectEta = EtaAlerts::etaMinutes($ahead, 45.0);
        echo "   (scenario: " . round($ahead, 1) . " km before \"" . $mid['name'] . "\", ETA " . $expectEta . " min)\n";
        if ($expectEta > 30 || $expectEta < 3) {
            throw new RuntimeException('test geometry does not land inside the 30-minute window on this route: ' . $expectEta);
        }

        $bMid  = rider($sid, $mid['name'] . ' @ ' . substr((string) $mid['time'], 0, 5));
        $bMid2 = rider($sid, $mid['name']);
        $bLast = rider($sid, $last['name'] . ' @ ' . substr((string) $last['time'], 0, 5));
        $bPast = rider($sid, $first['name']);
        $bDead = rider($sid, $mid['name'], 'cancelled');

        $told = [];
        $send = static function (array $b, array $facts) use (&$told): array {
            $told[] = ['id' => (int) $b['id'], 'facts' => $facts];
            return ['ok' => true, 'channels' => 'test', 'detail' => ''];
        };

        echo "-- who is told --\n";
        $r = EtaAlerts::run($fix, $now, $send);
        $ids = array_column($told, 'id');
        sort($ids);
        check('both passengers boarding at the NEXT stop are told', $ids === [$bMid, $bMid2], json_encode($r));
        check('...not the stop after it, not the stop already passed, not a cancelled ticket',
            !in_array($bLast, $ids, true) && !in_array($bPast, $ids, true) && !in_array($bDead, $ids, true));
        check('the message says a rounded "about N minutes", never a false-precise figure',
            (int) $told[0]['facts']['etaMin'] % 5 === 0 && (int) $told[0]['facts']['etaMin'] >= $expectEta);
        check('...and names the passenger\'s own stop', trim((string) $told[0]['facts']['boarding']) !== '');
        $row = Database::fetch('SELECT * FROM trip_eta_alerts WHERE booking_id = :b', ['b' => $bMid]);
        check('the alert is recorded with what went', $row !== null && (int) $row['ok'] === 1 && (string) $row['channels'] === 'test');

        $told = [];
        $r2 = EtaAlerts::run($fix, $now + 180, $send);
        check('a second run three minutes later tells NOBODY again', $told === [] && $r2['sent'] === 0 && $r2['claimed'] === 0, json_encode($r2));

        echo "-- silence when unsure --\n";
        Database::run('DELETE FROM trip_eta_alerts WHERE schedule_id = :s', ['s' => $sid]);
        $told = [];
        EtaAlerts::run(['lat' => $fix['lat'] + 1.5, 'lng' => $fix['lng'] + 1.5, 'kmh' => 45.0], $now, $send);
        check('a position ~200 km off the route alerts nobody', $told === []);

        EtaAlerts::run($fix, $now + 9 * 3600, $send);
        check('the right place at the wrong hour (timetable 9 h away) alerts nobody - this is the return coach', $told === []);

        $twin = Database::fetch('SELECT id FROM routes WHERE id = :r', ['r' => $routeId]);
        $yesterday = date('Y-m-d', strtotime(ETA_DATE . ' -1 day'));
        $lateDep = Database::fetch('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d', ['r' => $routeId, 'd' => $yesterday]);
        if ($twin !== null && $lateDep === null) {
            // a second coach of the SAME route inside the window: yesterday's trip running 14 h late
            $sidY = (int) Seats::schedule($routeId, $yesterday)['id'];
            Database::update('schedules', ['delay_minutes' => 14 * 60], 'id = :i', ['i' => $sidY]);
            $rr = EtaAlerts::run($fix, $now, $send);
            check('two coaches of one route on the road and one position = nobody is told',
                $told === [] && $rr['skipped'] !== [] && str_contains(implode(' ', $rr['skipped']), 'not guessing'), json_encode($rr['skipped']));
            Database::delete('schedules', 'id = :i', ['i' => $sidY]);
        }

        echo "-- the published position --\n";
        $put = static function (array $v, string $at): void {
            Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus' AND updated_by = 'eta-test'");
            if (Database::exists("SELECT 1 FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus'")) { throw new RuntimeException('a real livebus row exists on this DB'); }
            Database::insert('kv_store', ['kscope' => 'global', 'kkey' => 'livebus', 'kvalue' => json_encode($v), 'updated_by' => 'eta-test']);
            Database::run("UPDATE kv_store SET updated_at = :t WHERE kscope = 'global' AND kkey = 'livebus'", ['t' => $at]);
        };
        $t = time();
        $put(['gps' => true, 'lat' => 23.1, 'lng' => 72.6, 'spd' => 12.5, 'acc' => 20], date('Y-m-d H:i:s', $t - 30));
        [$f, $why] = EtaAlerts::fix($t);
        check('a fresh, accurate fix is accepted and m/s becomes km/h', $f !== null && abs($f['kmh'] - 45.0) < 0.01, json_encode($f));
        $put(['gps' => true, 'lat' => 23.1, 'lng' => 72.6, 'acc' => 20], date('Y-m-d H:i:s', $t - 900));
        [$f, $why] = EtaAlerts::fix($t);
        check('a 15-minute-old position is refused', $f === null && str_contains($why, 'old'), $why);
        $put(['gps' => true, 'lat' => 23.1, 'lng' => 72.6, 'acc' => 1800], date('Y-m-d H:i:s', $t - 30));
        [$f, $why] = EtaAlerts::fix($t);
        check('a 1.8 km-accuracy fix is refused', $f === null && str_contains($why, 'accuracy'), $why);
        $put(['stop' => 'Baroda', 'next' => 'Ahmedabad'], date('Y-m-d H:i:s', $t - 30));
        [$f, $why] = EtaAlerts::fix($t);
        check('the office\'s hand-typed "bus is at Baroda" (no coordinates) is refused', $f === null, $why);

        echo "-- wiring --\n";
        require_once INCLUDE_PATH . '/notify.php';
        check('bus_near is a trip event, and OFF until the owner switches it on',
            in_array('bus_near', Notify::TRIP_EVENTS, true) && Notify::tripEventEnabled('bus_near') === Settings::getBool('eta_alert_on', false));
        check('the driver\'s publish path (api/kv.php) does not call the alert engine',
            !str_contains((string) file_get_contents(ROOT_PATH . '/api/kv.php'), 'EtaAlerts'));

        echo "-- the road line (20 Oct 2026) --\n";
        $ref = RouteGeo::decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@', 5);
        check('polyline: the published reference string decodes to its three points',
            count($ref) === 3 && abs($ref[0][0] - 38.5) < 1e-9 && abs($ref[1][1] + 120.95) < 1e-9 && abs($ref[2][1] + 126.453) < 1e-9, json_encode($ref));
        $pp = [[21.1702, 72.8311], [21.2729662, 72.9555969], [28.06, 81.617], [27.999999, 81.000001]];
        $rt = RouteGeo::decode(RouteGeo::encode($pp));
        $okRt = count($rt) === 4;
        foreach ($pp as $i => $q) { $okRt = $okRt && abs($rt[$i][0] - $q[0]) < 6e-7 && abs($rt[$i][1] - $q[1]) < 6e-7; }
        check('polyline6: encode then decode gives the points back to 1e-6 degree', $okRt);
        $straight = [];
        for ($i = 0; $i <= 500; $i++) { $straight[] = [22.0 + $i * 0.002, 73.0]; }
        check('simplify: 501 points on one straight road become its two ends', count(RouteGeo::simplify($straight)) === 2);
        $ell = array_merge($straight, [[23.0, 73.5]]);
        check('...and a real bend is kept', count(RouteGeo::simplify($ell)) === 3);
        $road = ['lat' => array_column($straight, 0), 'lng' => array_column($straight, 1), 'cum' => RouteGeo::cumulativeKm($straight)];
        $mLoc = RouteGeo::locate($road, 22.5, 73.0);
        check('locate: the middle of a 111 km road is at ~55 km and on it', $mLoc !== null && abs($mLoc['km'] - end($road['cum']) / 2) < 0.2 && $mLoc['off'] < 0.01, json_encode($mLoc));
        $sLoc = RouteGeo::locate($road, 22.5, 73.0485);
        check('locate: a point ~5 km to the side is ~5 km off the road', $sLoc !== null && abs($sLoc['off'] - 5.0) < 0.3, json_encode($sLoc));
        $back = RouteGeo::pointAt($road, $mLoc['km']);
        check('pointAt(locate(p)) is p again', EtaAlerts::haversineKm($back['lat'], $back['lng'], 22.5, 73.0) < 0.05);

        if (!RouteGeo::available()) {
            check('route_geometry exists (apply database/upgrade-2026-10-route-geometry.sql)', false);
        } else {
            $saved = Database::fetch('SELECT * FROM route_geometry WHERE route_id = :r', ['r' => $routeId]);
            RouteGeo::$enabled = true;
            try {
                Database::delete('route_geometry', 'route_id = :r', ['r' => $routeId]);
                RouteGeo::resetCache();
                $sl  = EtaAlerts::stopLine($routeId);
                $res = RouteGeo::build($routeId, static fn(array $st): array => fakeRoad($st, 30.0));
                check('build: the line through the stops that HAVE coordinates is stored as version 1',
                    $res['ok'] && $res['version'] === 1 && $res['stops'] === array_column($sl['line'], 'name') && $res['points'] < $res['rawPoints'],
                    json_encode(array_diff_key($res, ['stops' => 1, 'missing' => 1])));
                check('...and the stops without coordinates are named, never guessed', $res['missing'] === $sl['missing'], implode(', ', $res['missing']));
                $geo = EtaAlerts::routeLine($routeId);
                $L   = $geo['line'];
                check('every stop is placed on the road, in travel order',
                    isset($geo['road']) && count($L) === count($sl['line']) && $L[0]['km'] < 0.5 && $L[1]['km'] < $L[2]['km']);
                $straightKm = ($L[1]['lineKm'] - $L[0]['lineKm']) / EtaAlerts::ROAD_FACTOR;
                check('km between two stops are ROAD km (the bend counts), not the straight line x 1.25',
                    $L[1]['km'] - $L[0]['km'] > $straightKm + 5, round($L[1]['km'] - $L[0]['km'], 1) . ' road km vs ' . round($straightKm, 1) . ' straight');

                $peak = RouteGeo::pointAt($geo['road'], ($L[0]['km'] + $L[1]['km']) / 2);
                $onR  = EtaAlerts::locate($geo, $peak['lat'], $peak['lng']);
                check('a coach on the bend is ON the road', $onR !== null && $onR['on'] === 'road' && $onR['off'] < 0.5, json_encode($onR));
                /* on its own two-stop route, so no other part of this database's road can be nearer */
                $two  = [['lat' => 22.0, 'lng' => 73.0], ['lat' => 23.0, 'lng' => 73.0]];
                $tr   = array_slice(fakeRoad($two, 40.0)['pts'], 0);
                $trR  = ['lat' => array_column($tr, 0), 'lng' => array_column($tr, 1), 'cum' => RouteGeo::cumulativeKm($tr)];
                $tKm  = end($trR['cum']);
                $tGeo = ['line' => [['name' => 'A', 'lat' => 22.0, 'lng' => 73.0, 'lineKm' => 0.0, 'km' => 0.0],
                                    ['name' => 'B', 'lat' => 23.0, 'lng' => 73.0, 'lineKm' => 139.0, 'km' => $tKm]], 'road' => $trR];
                $cut  = EtaAlerts::locate($tGeo, 22.5, 73.0);
                check('a coach 40 km off the road but on the line of stops falls back to that line, still in road km',
                    $cut !== null && $cut['on'] === 'line' && $cut['off'] < 1 && abs($cut['km'] - $tKm / 2) < 1, json_encode($cut) . ' of ' . round($tKm, 1));

                Database::run('DELETE FROM trip_eta_alerts WHERE schedule_id = :s', ['s' => $sid]);
                $told = [];
                $rp = RouteGeo::pointAt($geo['road'], $L[1]['km'] - 12.0);
                EtaAlerts::run(['lat' => $rp['lat'], 'lng' => $rp['lng'], 'kmh' => 45.0], $now, $send);
                $ids = array_column($told, 'id'); sort($ids);
                $row = Database::fetch('SELECT dist_km, eta_min FROM trip_eta_alerts WHERE booking_id = :b', ['b' => $bMid]);
                check('"bus is near" measures the 12 ROAD km to the stop and tells the right passengers',
                    $ids === [$bMid, $bMid2] && $row !== null && abs((float) $row['dist_km'] - 12.0) < 0.6 && abs((int) $row['eta_min'] - EtaAlerts::etaMinutes(12.0, 45.0)) <= 1, json_encode($row));
                $etas = EtaAlerts::stopEtas($routeId, ['lat' => $rp['lat'], 'lng' => $rp['lng'], 'kmh' => 45.0]);
                check('the office map\'s minutes use the same road km', abs((float) ($etas[Boarding::townKey($L[1]['name'])]['km'] ?? -1) - 12.0) < 0.6, json_encode($etas));

                $res2 = RouteGeo::build($routeId, static fn(array $st): array => fakeRoad($st, 30.0));
                check('a rebuild bumps the version (phones fetch the new line)', $res2['ok'] && $res2['version'] === 2);
                $res3 = RouteGeo::build($routeId, static fn(array $st): array => fakeRoad($st, 0.0, 1, 0.15));
                check('a road that misses a stop by ~15 km is refused and the stored line is kept',
                    !$res3['ok'] && str_contains($res3['error'], 'km from the road') && RouteGeo::info($routeId)['version'] === 2, $res3['error'] ?? '');
                check('a router error is reported, nothing stored',
                    !RouteGeo::build($routeId, static fn(array $st): array => ['ok' => false, 'error' => 'engine down'])['ok'] && RouteGeo::info($routeId)['version'] === 2);

                $moved = array_map(static fn(array $q): array => [$q[0] + 0.1, $q[1]],
                    RouteGeo::decode((string) Database::scalar('SELECT polyline6 FROM route_geometry WHERE route_id = :r', ['r' => $routeId])));
                Database::update('route_geometry', ['polyline6' => RouteGeo::encode($moved)], 'route_id = :r', ['r' => $routeId]);
                RouteGeo::resetCache();
                check('stops that no longer sit on the stored road (a stop was moved): the line of stops answers until a rebuild',
                    !isset(EtaAlerts::routeLine($routeId)['road']));
                RouteGeo::$enabled = false;
                check('no road line = exactly the old line of stops', EtaAlerts::routeLine($routeId) === EtaAlerts::stopLine($routeId));
            } finally {
                Database::delete('route_geometry', 'route_id = :r', ['r' => $routeId]);
                if ($saved !== null) { Database::insert('route_geometry', $saved); }
                RouteGeo::resetCache();
                RouteGeo::$enabled = false;
            }
        }
    } catch (Throwable $e) {
        check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        $cleanup();
    }
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
