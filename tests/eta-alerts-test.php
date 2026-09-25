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
 *
 *    php -c .claude/php-dev.ini tests/eta-alerts-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/etaalerts.php';

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
        /* Read the first alert only if one was sent. When the scenario does
           not fire (a fixture whose stop times moved, say), $told is empty and
           $told[0] used to be a PHP fatal that ended the whole suite with
           "Undefined array key 0" — a crash where a plain FAIL naming the
           real problem belongs. (25 Sep 2026.) */
        $first = $told[0]['facts'] ?? null;
        check('the message says a rounded "about N minutes", never a false-precise figure',
            $first !== null && (int) $first['etaMin'] % 5 === 0 && (int) $first['etaMin'] >= $expectEta,
            $first === null ? 'nothing was sent, so there is no message to read' : (string) $first['etaMin'] . ' min');
        check('...and names the passenger\'s own stop', $first !== null && trim((string) $first['boarding']) !== '');
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
    } catch (Throwable $e) {
        check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        $cleanup();
    }
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
