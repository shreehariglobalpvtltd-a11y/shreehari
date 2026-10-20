<?php
/**
 * =====================================================================
 *  live-trips-test.php - every coach has its own position (20 Oct 2026).
 *
 *  includes/livetrips.php + includes/driverauth.php + the per-trip path
 *  of includes/etaalerts.php. On a far-future date with a synthetic clock
 *  this suite pins:
 *
 *    - the driver's PIN sign-in: wrong PIN refused, token works, a new PIN
 *      signs the old phone out, only a hash of the token is stored
 *    - start: only a departure inside the window, one live trip per schedule
 *    - ingest: stored once even when re-sent, coarse / impossible / older
 *      fixes never move the coach, broken points are acknowledged
 *    - two coaches of ONE route on the road: the one with the driver app
 *      gets its own ETA and its passengers are told, the other stays silent
 *      (the old one-position rule still holds for it)
 *    - nothing is sent through a real channel: the sender is injected
 *
 *    php -c .claude/php-dev.ini tests/live-trips-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/etaalerts.php';
require_once INCLUDE_PATH . '/livetrips.php';
require_once INCLUDE_PATH . '/driverauth.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/whereis.php';

const LT_DATE  = '2099-12-10';
const LT_PREV  = '2099-12-09';
const LT_TAG   = 'SHG-LT-';
const LT_PHONE = '9000009917';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$cleanup = static function (): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE '" . LT_TAG . "%'") as $b) {
        Database::delete('trip_eta_alerts', 'booking_id = :b', ['b' => (int) $b['id']]);
        Database::delete('booking_legs', 'booking_id = :b', ['b' => (int) $b['id']]);
        Database::delete('bookings', 'id = :b', ['b' => (int) $b['id']]);
    }
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date IN (:a, :b)', ['a' => LT_DATE, 'b' => LT_PREV]) as $s) {
        Database::delete('live_trips', 'schedule_id = :s', ['s' => (int) $s['id']]);   // trip_locations cascade
        Database::delete('schedules', 'id = :s', ['s' => (int) $s['id']]);
    }
    foreach (Database::fetchAll('SELECT id FROM drivers WHERE phone = :p', ['p' => LT_PHONE]) as $d) {
        Database::delete('driver_devices', 'driver_id = :d', ['d' => (int) $d['id']]);
        Database::delete('drivers', 'id = :d', ['d' => (int) $d['id']]);
    }
};

$n = 0;
function rider(int $sid, string $date, string $boarding): int {
    global $n; $n++;
    $bid = Database::insert('bookings', [
        'pnr' => LT_TAG . $n . '-' . substr(md5((string) microtime(true)), 0, 6), 'contact_phone' => '90000031' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
        'total_amount' => 2000, 'status' => 'confirmed', 'source' => 'admin',
    ]);
    Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'travel_date' => $date, 'seat_count' => 1, 'boarding_stop' => $boarding]);
    return $bid;
}

if (!LiveTrips::available()) {
    check('live tracking tables exist (apply database/upgrade-2026-10-live-tracking.sql)', false);
    echo "\n  $PASS passed, $FAIL failed\n";
    exit(1);
}

$route = null;
foreach (Database::fetchAll('SELECT * FROM routes WHERE is_active = 1 ORDER BY id') as $r) {
    $geoStops = array_values(array_filter(EtaAlerts::routeLine((int) $r['id'])['line'], static fn($s) => $s['type'] === 'boarding'));
    if (count($geoStops) >= 3) { $route = $r; break; }
}
if ($route === null) {
    echo "  \033[33mSKIP\033[0m  needs a route with three boarding stops that have coordinates\n";
    exit(0);
}

$cleanup();
try {
    echo "-- driver sign-in --\n";
    $did = Database::insert('drivers', ['full_name' => 'LT Test Driver', 'phone' => LT_PHONE, 'role' => 'driver', 'is_active' => 1]);
    $threw = false;
    try { DriverAuth::setPin($did, '12a4'); } catch (RuntimeException $e) { $threw = true; }
    check('a PIN that is not 4-6 digits is refused', $threw);
    check('no PIN yet = nobody can sign in', DriverAuth::login(LT_PHONE, '4821') === null);
    DriverAuth::setPin($did, '4821');
    check('a wrong PIN is refused', DriverAuth::login(LT_PHONE, '4822') === null);
    $res = DriverAuth::login('+91 ' . LT_PHONE, '4821', 'test phone');
    check('mobile (any format) + right PIN gives a device token', $res !== null && strlen($res['token']) === 48);
    $tok = $res['token'];
    $me  = DriverAuth::current($tok);
    check('the token signs this driver in', $me !== null && $me['driverId'] === $did);
    check('only a hash of the token is stored',
        !Database::exists('SELECT 1 FROM driver_devices WHERE token_hash = :t', ['t' => $tok])
        && Database::exists('SELECT 1 FROM driver_devices WHERE token_hash = :h', ['h' => hash('sha256', $tok)]));
    DriverAuth::setPin($did, '7310');
    check('a new PIN signs the old phone out', DriverAuth::current($tok) === null);
    $res = DriverAuth::login(LT_PHONE, '7310', 'test phone');
    $me  = DriverAuth::current($res['token']);

    echo "-- start --\n";
    $routeId = (int) $route['id'];
    $sid     = (int) Seats::schedule($routeId, LT_DATE)['id'];
    $sidY    = (int) Seats::schedule($routeId, LT_PREV)['id'];
    Database::update('schedules', ['delay_minutes' => 14 * 60], 'id = :i', ['i' => $sidY]);   // yesterday's coach, 14 h late: also on the road
    $stops = array_values(array_filter(EtaAlerts::routeLine($routeId)['line'], static fn($s) => $s['type'] === 'boarding'));
    [$first, $mid] = [$stops[0], $stops[1]];
    $now = strtotime(LT_DATE . ' ' . ($mid['time'] ?? '17:00:00')) - 20 * 60;

    $threw = false;
    try { LiveTrips::start($did, $me['deviceId'], $sid, $now - 5 * 86400); } catch (RuntimeException $e) { $threw = true; }
    check('a departure days away cannot be started', $threw);
    $trip = LiveTrips::start($did, $me['deviceId'], $sid, $now);
    check('today\'s departure starts a running live trip', (string) $trip['status'] === 'running' && (int) $trip['schedule_id'] === $sid);
    $again = LiveTrips::start($did, $me['deviceId'], $sid, $now);
    check('starting again resumes the SAME trip (one per schedule)', (int) $again['id'] === (int) $trip['id']);

    echo "-- ingest --\n";
    $at = static fn(float $f): array => ['lat' => $first['lat'] + $f * ($mid['lat'] - $first['lat']), 'lng' => $first['lng'] + $f * ($mid['lng'] - $first['lng'])];
    $p  = static fn(int $seq, int $ts, float $f, int $acc = 12, float $kmh = 45.0): array => ['seq' => $seq, 't' => $ts * 1000, 'kmh' => $kmh, 'brg' => 30, 'acc' => $acc] + $at($f);
    $batch = [$p(1, $now - 30, 0.88), $p(2, $now - 15, 0.89), $p(3, $now, 0.90)];
    $r = LiveTrips::ingest(LiveTrips::find((int) $trip['id']), $batch, $now);
    check('a batch of three fixes is stored and moves the coach', $r['stored'] === 3 && $r['ack'] === 3 && $r['moved'], json_encode($r));
    $r = LiveTrips::ingest(LiveTrips::find((int) $trip['id']), $batch, $now);
    check('the same batch re-sent after a dead zone is stored once', $r['stored'] === 0 && $r['ack'] === 3, json_encode($r));
    $row = LiveTrips::find((int) $trip['id']);
    $r = LiveTrips::ingest($row, [$p(4, $now + 5, 0.95, 600)], $now + 5);
    $row2 = LiveTrips::find((int) $trip['id']);
    check('a 600 m-accuracy fix is kept for history but does not move the coach', $r['stored'] === 1 && !$r['moved'] && $row2['last_lat'] === $row['last_lat']);
    $r = LiveTrips::ingest($row2, [['seq' => 5, 't' => ($now + 10) * 1000, 'lat' => $mid['lat'] + 0.5, 'lng' => $mid['lng'], 'acc' => 10]], $now + 10);
    check('a 55 km jump in 10 seconds does not move the coach', !$r['moved']);
    $r = LiveTrips::ingest(LiveTrips::find((int) $trip['id']), [$p(6, $now - 300, 0.5)], $now + 12);
    check('a late fix from the offline queue is stored but never pulls the coach back', $r['stored'] === 1 && !$r['moved']);
    $r = LiveTrips::ingest(LiveTrips::find((int) $trip['id']), [['seq' => 9, 't' => $now * 1000, 'lat' => 0, 'lng' => 0]], $now + 12);
    check('a broken (0,0) point is acknowledged so the phone drops it, and not stored', $r['ack'] === 9 && $r['stored'] === 0);
    check('every stored fix carries its sequence number',
        (int) Database::scalar('SELECT COUNT(*) FROM trip_locations WHERE trip_id = :t', ['t' => (int) $trip['id']]) === 6);

    echo "-- two coaches of one route --\n";
    [$f, $why, $age, $src] = EtaAlerts::fixForTrip($sid, $routeId, $now + 12);
    check('the coach with the driver app gets its OWN position', $f !== null && $src === 'trip', $why);
    [$f2, $why2] = EtaAlerts::fixForTrip($sidY, $routeId, $now + 12);
    check('the other coach of the route is not given that position', $f2 === null, $why2);
    [$f3, $why3, , $src3] = EtaAlerts::fixForTrip($sid, $routeId, $now + 900);
    check('15 minutes without a fix: refused, and no fall-back to the shared key', $f3 === null && $src3 === 'trip' && str_contains($why3, 'old'), $why3);

    $bMine  = rider($sid, LT_DATE, $mid['name']);
    $bOther = rider($sidY, LT_PREV, $mid['name']);
    $told = [];
    $send = static function (array $b, array $facts) use (&$told): array {
        $told[] = (int) $b['id'];
        return ['ok' => true, 'channels' => 'test', 'detail' => ''];
    };
    $res = EtaAlerts::run(null, $now + 12, $send);
    check('"bus is near": the driver-app coach\'s passenger at the next stop is told', in_array($bMine, $told, true), json_encode($res));
    check('...and the other coach\'s passenger at the same stop is not', !in_array($bOther, $told, true));
    $told = [];
    EtaAlerts::run(null, $now + 200, $send);
    check('...once', $told === []);

    $st = WhereIs::status(BookingService::findByPnr((string) Database::scalar('SELECT pnr FROM bookings WHERE id = :b', ['b' => $bMine])), $now + 12);
    check('the passenger\'s track page shows the coach live with minutes to their stop', !empty($st['live']) && $st['etaMin'] !== null, json_encode(['live' => $st['live'], 'eta' => $st['etaMin'], 'reason' => $st['reason']]));

    $r = LiveTrips::ingest(LiveTrips::find((int) $trip['id']),
        [['seq' => 10, 't' => ($now + 20) * 1000, 'lat' => $mid['lat'] + 0.5005, 'lng' => $mid['lng'], 'acc' => 10]], $now + 20);
    check('a second fix that agrees with the "jump" moves the coach - one bad fix can never pin it in place', $r['moved'], json_encode($r));

    echo "-- office --\n";
    LiveTrips::end((int) $trip['id'], $now + 60);
    check('END TRIP closes it', (string) LiveTrips::find((int) $trip['id'])['status'] === 'completed');
    [$f4, $why4] = EtaAlerts::fixForTrip($sid, $routeId, $now + 70);
    check('an ended trip gives no live position', $f4 === null, $why4);
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    $cleanup();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
