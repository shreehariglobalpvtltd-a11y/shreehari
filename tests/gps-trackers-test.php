<?php
/**
 * =====================================================================
 *  gps-trackers-test.php - a bus tracked by its NUMBER (22 Oct 2026).
 *
 *  includes/gpstrackers.php + LiveTrips::startForBus / ingest($keepSeq).
 *
 *  Pure part (no database, runs anywhere):
 *    - device ids: SHG + 13, never 0/O/1/I; an IMEI or a typed id needs
 *      the forward key, our code does not
 *    - fix time: unix seconds, milliseconds, ISO 8601 (zone-less = UTC)
 *    - OsmAnd query (speed in KNOTS), Traccar Client 9 JSON (speed m/s,
 *      battery 0..1), Traccar server forward json, and what is a bad request
 *    - fixes as LiveTrips points: seq = the fix second
 *
 *  Database part (local DB with both migrations; skipped cleanly without):
 *    - a parked bus records the tracker only; unknown / switched-off /
 *      keyless IMEI refused; a fix 30 min before departure feeds THE trip
 *      of that bus with no driver; re-sent = stored once; the readers see
 *      it; a driver phone on the trip keeps its device and its counter;
 *      the next departure closes the bus's older tracker-only trip; END
 *      TRIP is never reopened
 *
 *    php -c .claude/php-dev.ini tests/gps-trackers-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT       = dirname(__DIR__);
$haveConfig = is_file($ROOT . '/config/config.local.php') || is_file($ROOT . '/config/config.php');
if ($haveConfig) {
    require_once $ROOT . '/includes/bootstrap.php';
    require_once INCLUDE_PATH . '/seats.php';
    require_once INCLUDE_PATH . '/etaalerts.php';
} else {
    define('SHG_APP', true);
}
require_once $ROOT . '/includes/gpstrackers.php';

const GT_DATE1 = '2099-12-14';
const GT_DATE2 = '2099-12-15';
const GT_BUS   = 'GT-TEST-9917';
const GT_IMEI  = '990000000009917';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function finish(): never {
    global $PASS, $FAIL;
    echo "\n  $PASS passed, $FAIL failed\n";
    exit($FAIL === 0 ? 0 : 1);
}
function skip(string $why): never {
    echo "  \033[33mSKIP\033[0m  database part: $why\n";
    finish();
}

/* ================================================================
   Pure part
   ================================================================ */
try {
    echo "-- device ids --\n";
    $seen = []; $allOk = true; $u = '';
    for ($i = 0; $i < 200; $i++) {
        $u = GpsTrackers::generateUid();
        $allOk = $allOk && strlen($u) === 16 && GpsTrackers::isGeneratedUid($u) && preg_match('/[0O1I]/', substr($u, 3)) === 0;
        $seen[$u] = true;
    }
    check('200 new codes: SHG + 13, never 0/O/1/I, all different', $allOk && count($seen) === 200, $u);
    check('a code typed in small letters with spaces is still ours', GpsTrackers::isGeneratedUid(GpsTrackers::normaliseUid('  ' . strtolower($u) . ' ')));
    check('a code with an O or a 1 in it is not ours', !GpsTrackers::isGeneratedUid('SHGABCDEFGHJKLO1') && !GpsTrackers::isGeneratedUid('SHGABCDEFGHJK'));
    check('junk is no device id', GpsTrackers::normaliseUid('a b') === '' && GpsTrackers::normaliseUid('abc') === ''
        && GpsTrackers::normaliseUid(['x']) === '' && GpsTrackers::normaliseUid(null) === '' && GpsTrackers::normaliseUid(str_repeat('A', 65)) === '');
    $imei = '358240051111110';
    $key  = 'Kq7mX2pR9tY4wZ8nB3vC6dF5';
    check('an IMEI needs the forward key, our code does not', GpsTrackers::needsKey($imei) && !GpsTrackers::needsKey($u));
    check('a typed id that is not our code (BUS-6011) needs the key too', GpsTrackers::needsKey('BUS-6011'));
    check('IMEI + the right key: accepted', GpsTrackers::keyOk($imei, $key, $key));
    check('IMEI + a wrong key, no key, or no key set up: refused',
        !GpsTrackers::keyOk($imei, 'wrong', $key) && !GpsTrackers::keyOk($imei, '', $key) && !GpsTrackers::keyOk($imei, '', ''));
    check('our code with no key at all: accepted', GpsTrackers::keyOk($u, '', $key) && GpsTrackers::keyOk($u, '', ''));

    echo "-- fix time --\n";
    $T = gmmktime(5, 30, 0, 10, 22, 2026);
    check('unix seconds (number or text)', GpsTrackers::parseTime($T) === $T && GpsTrackers::parseTime((string) $T) === $T);
    check('unix milliseconds (Traccar {fixTime})', GpsTrackers::parseTime($T * 1000 + 789) === $T && GpsTrackers::parseTime((string) ($T * 1000)) === $T);
    check('ISO 8601: Z, +05:30 and milliseconds', GpsTrackers::parseTime('2026-10-22T05:30:00Z') === $T
        && GpsTrackers::parseTime('2026-10-22T11:00:00+05:30') === $T && GpsTrackers::parseTime('2026-10-22T05:30:00.000Z') === $T);
    check('a date-time without a zone is UTC (GPS time)', GpsTrackers::parseTime('2026-10-22 05:30:00') === $T);
    check('nothing, junk or a tiny number is no time', GpsTrackers::parseTime(null) === null && GpsTrackers::parseTime('') === null
        && GpsTrackers::parseTime('abc') === null && GpsTrackers::parseTime(12345) === null && GpsTrackers::parseTime(true) === null);

    echo "-- OsmAnd (Traccar Client 6-8, forward URL) --\n";
    $p = GpsTrackers::parseOsmAnd(['id' => strtolower($u), 'lat' => '21.170240', 'lon' => '72.831062', 'timestamp' => (string) $T,
        'speed' => '10', 'bearing' => '90.5', 'accuracy' => '8', 'altitude' => '12', 'batt' => '77']);
    $f = $p['fixes'][0] ?? [];
    check('device id upper-cased, one fix, not bad', $p['bad'] === '' && $p['uid'] === $u && count($p['fixes']) === 1, $p['bad']);
    check('speed is KNOTS: 10 kn = 18.52 km/h', ($f['kmh'] ?? null) === 18.52, json_encode($f['kmh'] ?? null));
    check('lat / lon / time / bearing / accuracy / battery read', ($f['lat'] ?? null) === 21.17024 && ($f['lng'] ?? null) === 72.831062
        && ($f['ts'] ?? null) === $T && ($f['brg'] ?? null) === 90.5 && ($f['acc'] ?? null) === 8.0 && $p['batt'] === 77, json_encode($p));
    $pa = GpsTrackers::parseOsmAnd(['id' => $u, 'location' => '21.1,72.8']);
    $pb = GpsTrackers::parseOsmAnd(['deviceid' => $u, 'lat' => 21.1, 'lng' => 72.8]);
    check('"location=lat,lon", "lng" and "deviceid" are read too', ($pa['fixes'][0]['lng'] ?? null) === 72.8 && ($pb['fixes'][0]['lng'] ?? null) === 72.8 && $pb['uid'] === $u);
    $pf = GpsTrackers::parseOsmAnd(['id' => $imei, 'lat' => '21.2', 'lon' => '72.9', 'timestamp' => (string) ($T * 1000),
        'speed' => '0', 'bearing' => '0', 'key' => " $key "]);
    check('forward URL: IMEI, {fixTime} in ms, key trimmed, speed 0 = 0 km/h', $pf['uid'] === $imei && ($pf['fixes'][0]['ts'] ?? null) === $T
        && $pf['key'] === $key && ($pf['fixes'][0]['kmh'] ?? null) === 0.0);
    check('no lat / lon = bad request', GpsTrackers::parseOsmAnd(['id' => $u])['bad'] !== '');
    check('lat "abc" or 95 = bad request', GpsTrackers::parseOsmAnd(['id' => $u, 'lat' => 'abc', 'lon' => '72'])['bad'] !== ''
        && GpsTrackers::parseOsmAnd(['id' => $u, 'lat' => '95', 'lon' => '72'])['bad'] !== '');
    check('no device id = bad request', GpsTrackers::parseOsmAnd(['lat' => '21', 'lon' => '72'])['bad'] !== '');
    $pv = GpsTrackers::parseOsmAnd(['id' => $u, 'lat' => '21', 'lon' => '72', 'valid' => 'false']);
    check('valid=false: the fix is dropped, the request is not bad', $pv['bad'] === '' && $pv['fixes'] === []);
    $pn = GpsTrackers::parseOsmAnd(['id' => $u, 'lat' => '21', 'lon' => '72', 'speed' => '-1', 'bearing' => '-1']);
    $isNull = static fn(array $p, string $k): bool => isset($p['fixes'][0]) && array_key_exists($k, $p['fixes'][0]) && $p['fixes'][0][$k] === null;
    check('a negative speed / bearing = unknown, not 0', $isNull($pn, 'kmh') && $isNull($pn, 'brg'), json_encode($pn['fixes']));

    echo "-- Traccar Client 9 JSON --\n";
    $loc = ['timestamp' => '2026-10-22T05:30:00.000Z', 'coords' => ['latitude' => 21.17, 'longitude' => 72.83, 'speed' => 10,
            'heading' => 180, 'accuracy' => 5, 'altitude' => 10], 'battery' => ['level' => 0.53, 'is_charging' => true], 'is_moving' => true];
    $pj = GpsTrackers::parseOsmAnd(['device_id' => $u, 'location' => $loc]);
    $f  = $pj['fixes'][0] ?? [];
    check('speed is m/s: 10 m/s = 36 km/h', $pj['bad'] === '' && ($f['kmh'] ?? null) === 36.0, json_encode($pj));
    check('ISO time, heading, accuracy; battery level 0.53 = 53 %', ($f['ts'] ?? null) === $T && ($f['brg'] ?? null) === 180.0
        && ($f['acc'] ?? null) === 5.0 && $pj['batt'] === 53);
    $loc2 = $loc; $loc2['coords']['speed'] = -1; $loc2['coords']['heading'] = -1;
    $pj2 = GpsTrackers::parseOsmAnd(['device_id' => $u, 'location' => $loc2]);
    check('speed / heading -1 (unknown) = no value', $isNull($pj2, 'kmh') && $isNull($pj2, 'brg'), json_encode($pj2['fixes']));
    $loc3 = $loc; $loc3['timestamp'] = '2026-10-22T05:30:10Z';
    $pj3 = GpsTrackers::parseOsmAnd(['device_id' => $u, 'location' => [$loc, $loc3]]);
    check('a list of locations = one fix each', count($pj3['fixes']) === 2 && $pj3['fixes'][1]['ts'] === $T + 10);
    check('a location without coords = bad request', GpsTrackers::parseOsmAnd(['device_id' => $u, 'location' => ['timestamp' => 'x']])['bad'] !== ''
        && GpsTrackers::parseOsmAnd(['device_id' => $u, 'location' => []])['bad'] !== '');

    echo "-- Traccar server forward json --\n";
    $pt = GpsTrackers::parseOsmAnd(['key' => $key, 'device' => ['uniqueId' => $imei, 'name' => 'Box'],
        'position' => ['latitude' => 21.2, 'longitude' => 72.9, 'speed' => 20, 'course' => 45, 'accuracy' => 0,
                       'fixTime' => '2026-10-22T05:30:00.000+00:00', 'valid' => true, 'attributes' => ['batteryLevel' => 64]]]);
    $f = $pt['fixes'][0] ?? [];
    check('IMEI from device.uniqueId, knots, course, fixTime, battery', $pt['uid'] === $imei && ($f['kmh'] ?? null) === 37.04
        && ($f['brg'] ?? null) === 45.0 && ($f['ts'] ?? null) === $T && $pt['batt'] === 64 && $pt['key'] === $key, json_encode($pt));

    echo "-- as LiveTrips points --\n";
    $now = $T + 30;
    $pts = GpsTrackers::toPoints([
        ['ts' => $T, 'lat' => 21.1, 'lng' => 72.8, 'kmh' => 18.52, 'brg' => 90.5, 'acc' => 8.0],
        ['ts' => null, 'lat' => 21.2, 'lng' => 72.8, 'kmh' => null, 'brg' => null, 'acc' => null],
        ['ts' => $now + 3600, 'lat' => 21.3, 'lng' => 72.8, 'kmh' => null, 'brg' => null, 'acc' => null],
    ], $now);
    check('seq = the fix second, t in ms, oldest first', ($pts[0]['seq'] ?? 0) === $T && ($pts[0]['t'] ?? 0) === $T * 1000);
    check('no time / a clock running ahead = now (one point for that second)', count($pts) === 2 && $pts[1]['seq'] === $now);
    $c = LiveTrips::cleanPoint($pts[0], $now);
    check('LiveTrips takes such a point as it is', $c !== null && $c['ts'] === $T && $c['kmh'] === 18.52 && $c['acc'] === 8.0, json_encode($c));
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
}

/* ================================================================
   Database part
   ================================================================ */
echo "-- database --\n";
if (!$haveConfig) {
    skip('no config/config.local.php here');
}
try {
    new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET), DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
} catch (Throwable $e) {
    skip('the database is not running');
}
if (!GpsTrackers::tableReady()) {
    skip('apply database/upgrade-2026-10-22-gps-trackers.sql');
}
if (!LiveTrips::available()) {
    skip('apply database/upgrade-2026-10-live-tracking.sql');
}
$route = Database::fetch('SELECT * FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1');
if ($route === null) {
    skip('no active route');
}

$hadKey = Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => GpsTrackers::KEY_SETTING]);
$cleanup = static function (): void {
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date IN (:a, :b)', ['a' => GT_DATE1, 'b' => GT_DATE2]) as $s) {
        foreach (Database::fetchAll('SELECT id FROM live_trips WHERE schedule_id = :s', ['s' => (int) $s['id']]) as $t) {
            Database::delete('trip_locations', 'trip_id = :t', ['t' => (int) $t['id']]);
            Database::delete('live_trips', 'id = :t', ['t' => (int) $t['id']]);
        }
        Database::delete('schedules', 'id = :s', ['s' => (int) $s['id']]);
    }
    Database::delete('gps_trackers', 'device_uid = :u', ['u' => GT_IMEI]);
    foreach (Database::fetchAll('SELECT id FROM buses WHERE bus_number = :n', ['n' => GT_BUS]) as $b) {
        Database::delete('gps_trackers', 'bus_id = :b', ['b' => (int) $b['id']]);
        Database::delete('buses', 'id = :b', ['b' => (int) $b['id']]);
    }
};

$cleanup();
try {
    $busId   = Database::insert('buses', ['bus_number' => GT_BUS, 'bus_name' => 'GT Test Coach', 'total_seats' => 72, 'is_active' => 1]);
    $routeId = (int) $route['id'];
    $sid1    = (int) Seats::schedule($routeId, GT_DATE1)['id'];
    $sid2    = (int) Seats::schedule($routeId, GT_DATE2)['id'];
    foreach ([$sid1, $sid2] as $s) {
        Database::update('schedules', ['bus_id' => $busId, 'delay_minutes' => 0, 'status' => 'scheduled', 'is_blocked' => 0], 'id = :i', ['i' => $s]);
    }
    $depOf = static function (int $sid, string $date): int {
        foreach (LiveTrips::choosable((int) strtotime($date . ' 12:00:00')) as $c) {
            if ($c['scheduleId'] === $sid) { return (int) $c['depTs']; }
        }
        return 0;
    };
    $dep1 = $depOf($sid1, GT_DATE1);
    $dep2 = $depOf($sid2, GT_DATE2);
    if ($dep1 === 0 || $dep2 === 0) {
        throw new RuntimeException('test departures are not choosable');
    }

    echo "-- trackers --\n";
    $phone = GpsTrackers::add($busId, 'GT phone', 'phone', '');
    $pUid  = (string) $phone['device_uid'];
    check('a phone tracker gets a new SHG code', GpsTrackers::isGeneratedUid($pUid) && $phone['kind'] === 'phone' && (int) $phone['is_active'] === 1);
    $threw = false;
    try { GpsTrackers::add($busId, 'x', 'phone', 'BUS-6011'); } catch (RuntimeException $e) { $threw = true; }
    check('a phone with a typed (guessable) id is refused', $threw);
    $hw = GpsTrackers::add($busId, 'GT box', 'hardware', ' ' . GT_IMEI . ' ');
    check('a GPS box is added by its IMEI', $hw['device_uid'] === GT_IMEI && $hw['kind'] === 'hardware');
    $threw = false;
    try { GpsTrackers::add($busId, 'dup', 'hardware', GT_IMEI); } catch (RuntimeException $e) { $threw = true; }
    check('one IMEI cannot be on two trackers', $threw);

    $q = static fn(string $uid, int $ts, float $dLat = 0.0, array $extra = []): array => $extra + [
        'id' => $uid, 'lat' => (string) (21.170240 + $dLat), 'lon' => '72.831062', 'timestamp' => (string) $ts,
        'speed' => '20', 'bearing' => '30', 'accuracy' => '10', 'batt' => '81'];
    $ref = static fn(int $sid): ?array => Database::fetch('SELECT * FROM live_trips WHERE trip_ref = :r', ['r' => LiveTrips::tripRef($sid)]);

    echo "-- parked --\n";
    $early = $dep1 - 7200;
    $r = GpsTrackers::ingestOsmAnd($q($pUid, $early), $early);
    check('2 h before departure the bus is parked: OK, no trip', $r['code'] === 200 && $r['trip'] === null, json_encode($r));
    $row = GpsTrackers::findByUid($pUid);
    check('...the tracker row keeps place, speed (knots -> km/h), battery, last seen',
        abs((float) $row['last_lat'] - 21.17024) < 1e-6 && (int) $row['last_batt'] === 81 && abs((float) $row['last_speed'] - 37.04) < 0.01
        && $row['last_seen'] === date('Y-m-d H:i:s', $early) && $row['last_fix_at'] === date('Y-m-d H:i:s', $early), json_encode($row));
    check('...and no live trip is made', $ref($sid1) === null);

    echo "-- refused --\n";
    $now = $dep1 - 1800;
    check('an unknown device: 403', GpsTrackers::ingestOsmAnd($q('SHGZZZZZZZZZZZZZ', $now), $now)['code'] === 403);
    check('the IMEI without the key: 403', GpsTrackers::ingestOsmAnd($q(GT_IMEI, $now), $now)['code'] === 403);
    check('the IMEI with a wrong key: 403', GpsTrackers::ingestOsmAnd($q(GT_IMEI, $now, 0.0, ['key' => 'wrong-key-123456']), $now)['code'] === 403);
    check('no position: 400', GpsTrackers::ingestOsmAnd(['id' => $pUid], $now)['code'] === 400);
    GpsTrackers::setActive((int) $phone['id'], false);
    check('a switched-off tracker: 403', GpsTrackers::ingestOsmAnd($q($pUid, $now), $now)['code'] === 403);
    GpsTrackers::setActive((int) $phone['id'], true);
    check('...and nothing of those reached a trip', $ref($sid1) === null);

    echo "-- on the road --\n";
    $r = GpsTrackers::ingestOsmAnd($q($pUid, $now), $now);
    check('30 min before departure the fix feeds THE trip of this bus', $r['code'] === 200 && $r['trip'] === $sid1 && $r['moved'], json_encode($r));
    $t1 = $ref($sid1);
    check('...a running live trip with the bus, no driver, no device, last_seq untouched', $t1 !== null && $t1['status'] === 'running'
        && (int) $t1['bus_id'] === $busId && $t1['driver_id'] === null && $t1['device_id'] === null && (int) $t1['last_seq'] === 0, json_encode($t1));
    $r = GpsTrackers::ingestOsmAnd($q($pUid, $now), $now + 5);
    check('the same fix sent again is stored once', !$r['moved']
        && (int) Database::scalar('SELECT COUNT(*) FROM trip_locations WHERE trip_id = :t', ['t' => (int) $t1['id']]) === 1);
    $fk = GpsTrackers::forwardKey();
    $r = GpsTrackers::ingestOsmAnd($q(GT_IMEI, ($now + 10) * 1000, 0.001, ['key' => $fk]), $now + 12);
    check('the IMEI with the forward key ({fixTime} in ms) moves the same coach', $r['code'] === 200 && $r['trip'] === $sid1 && $r['moved'], json_encode($r));
    [$fix, $why, , $src] = EtaAlerts::fixForTrip($sid1, $routeId, $now + 15);
    check('the track page / WhatsApp / NAV reader sees the coach live', $fix !== null && $src === 'trip' && abs($fix['lat'] - 21.17124) < 1e-6, $why);

    echo "-- next departure --\n";
    $now2 = $dep2 - 1800;
    $r = GpsTrackers::ingestOsmAnd($q($pUid, $now2), $now2);
    $t1b = $ref($sid1);
    check('next day the bus feeds its NEXT departure', $r['trip'] === $sid2 && $r['moved'], json_encode($r));
    check('...and its older trip that only a tracker fed is closed', $t1b !== null && $t1b['status'] === 'completed', (string) ($t1b['status'] ?? ''));

    echo "-- driver phone on the same trip --\n";
    $t2 = $ref($sid2);
    Database::update('live_trips', ['device_id' => 987654321, 'last_seq' => 7], 'id = :i', ['i' => (int) $t2['id']]);
    $r = GpsTrackers::ingestOsmAnd($q($pUid, $now2 + 20, 0.002), $now2 + 22);
    $t2b = LiveTrips::find((int) $t2['id']);
    check('the tracker still moves the coach, the phone keeps its device and its seq counter',
        $r['moved'] && (int) $t2b['device_id'] === 987654321 && (int) $t2b['last_seq'] === 7, json_encode([$r, $t2b['device_id'], $t2b['last_seq']]));

    echo "-- END TRIP --\n";
    LiveTrips::end((int) $t2['id'], $now2 + 30);
    $r = GpsTrackers::ingestOsmAnd($q($pUid, $now2 + 40, 0.003), $now2 + 42);
    check('after END TRIP a tracker fix is OK but feeds nothing', $r['code'] === 200 && $r['trip'] === null && !$r['moved'], json_encode($r));
    check('...and the trip stays ended', (string) LiveTrips::find((int) $t2['id'])['status'] === 'completed');
    check('...while the tracker row still follows the bus', GpsTrackers::findByUid($pUid)['last_seen'] === date('Y-m-d H:i:s', $now2 + 42));

    echo "-- office list --\n";
    $mine = null;
    foreach (GpsTrackers::busesWithTrackers() as $b) { if ((int) $b['id'] === $busId) { $mine = $b; } }
    check('the bus shows with both its trackers', $mine !== null && count($mine['trackers']) === 2 && count(GpsTrackers::forBus($busId)) === 2);
    GpsTrackers::remove((int) $hw['id']);
    check('a deleted tracker is gone', GpsTrackers::findByUid(GT_IMEI) === null);
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    $cleanup();
    if (!$hadKey) {
        Database::delete('settings', 'skey = :k', ['k' => GpsTrackers::KEY_SETTING]);
    }
}

finish();
