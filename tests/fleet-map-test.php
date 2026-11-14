<?php
/**
 * =====================================================================
 *  fleet-map-test.php - the office fleet map upgrade (15 Nov 2026).
 *
 *  includes/gpsfleet.php, admin/api/trip-trail.php, admin/api/fleet-status.php
 *
 *  Pure part (no database):
 *    - heading: bearing() N/E/S/W, too close = null; norm(); headingFrom()
 *    - publicPoints() carries 'brg' (0-359, null when unknown)
 *    - thin(): <= max points, first + last kept, order kept
 *    - cleanTrail(): a coarse fix and a lone GPS spike are dropped
 *    - isStopped() and statusCounts() - the count bar
 *
 *  Database part (shari_test only; every row it makes is removed):
 *    - trail() of a 5000-fix trip: thinned to 2000, first + last kept
 *    - busHeadings(): derived from the last fixes, then the reported course
 *    - snapshot() gives a live tracker the bus heading
 *    - stoppedTrips(), liveTripIds(), statusBar()
 *
 *  HTTP part (php -S on 127.0.0.1:8962, started here):
 *    - trip-trail.php and fleet-status.php with no session = 401 JSON
 *    - admin/map.php?trip= with no session = to the sign-in page
 *
 *    php -c .claude/php-dev.ini tests/fleet-map-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT       = dirname(__DIR__);
$haveConfig = is_file($ROOT . '/config/config.local.php') || is_file($ROOT . '/config/config.php');
if ($haveConfig) {
    require_once $ROOT . '/includes/bootstrap.php';
    require_once INCLUDE_PATH . '/seats.php';
} else {
    define('SHG_APP', true);
}
require_once $ROOT . '/includes/gpsfleet.php';

const FM_DATE1 = '2099-12-20';
const FM_DATE2 = '2099-12-21';
const FM_BUS   = 'FM-TEST-9915';
const FM_PORT  = 8962;

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
/** A point $m metres north of (lat, lng). */
function north(float $lat, float $m): float { return $lat + $m / 111195.0; }

/* ================================================================
   Pure part
   ================================================================ */
try {
    echo "-- heading --\n";
    check('due north = 0', GpsFleet::bearing(23.0, 72.0, 23.01, 72.0) === 0);
    check('due east = 90', GpsFleet::bearing(23.0, 72.0, 23.0, 72.01) === 90);
    check('due south = 180', GpsFleet::bearing(23.01, 72.0, 23.0, 72.0) === 180);
    check('due west = 270', GpsFleet::bearing(23.0, 72.01, 23.0, 72.0) === 270);
    check('10 m apart = no heading (GPS jitter)', GpsFleet::bearing(23.0, 72.0, north(23.0, 10), 72.0) === null);
    check('norm: 370 = 10, -90 = 270, junk = null', GpsFleet::norm(370) === 10 && GpsFleet::norm(-90) === 270
        && GpsFleet::norm('359.6') === 0 && GpsFleet::norm('x') === null && GpsFleet::norm(null) === null);
    $p = 23.0;
    check('headingFrom: newest first, the first older fix far enough away',
        GpsFleet::headingFrom([['lat' => north($p, 40), 'lng' => 72.0], ['lat' => north($p, 10), 'lng' => 72.0], ['lat' => $p, 'lng' => 72.0]]) === 0);
    check('headingFrom: a bus that moved 5 m has no heading',
        GpsFleet::headingFrom([['lat' => north($p, 5), 'lng' => 72.0], ['lat' => $p, 'lng' => 72.0]]) === null && GpsFleet::headingFrom([]) === null);

    echo "-- publicPoints heading --\n";
    $now = 1_800_000_000;
    $dev = static fn(int $bus, array $x = []): array => $x + ['public' => true, 'state' => 'live', 'fixAt' => $now * 1000, 'seenAt' => $now * 1000,
        'lat' => 23.0, 'lng' => 72.0, 'busId' => $bus, 'bus' => 'GJ-' . $bus, 'name' => 'Coach', 'kmh' => 40.0, 'diagnostic' => null];
    $pts = GpsFleet::publicPoints([$dev(5, ['brg' => 370]), $dev(6), $dev(7, ['public' => false, 'brg' => 90]), $dev(8, ['state' => 'stale', 'brg' => 90])], $now);
    $byBus = array_column($pts, null, 'bus');
    check('only public + live devices are points', count($pts) === 2 && isset($byBus['GJ-5'], $byBus['GJ-6']), json_encode(array_keys($byBus)));
    check('a device heading reaches the point (370 -> 10)', ($byBus['GJ-5']['brg'] ?? 'x') === 10);
    check('no device heading = brg null (the key is always there)', array_key_exists('brg', $byBus['GJ-6'] ?? []) && $byBus['GJ-6']['brg'] === null);

    echo "-- thinning --\n";
    $big = [];
    for ($i = 0; $i < 5000; $i++) { $big[] = [72.0 + $i / 1e4, 23.0, 1_700_000_000 + $i * 15, 40.0, null]; }
    $t = GpsFleet::thin($big, 2000);
    $inc = true;
    for ($i = 1; $i < count($t); $i++) { $inc = $inc && $t[$i][2] > $t[$i - 1][2]; }
    check('5000 fixes -> exactly 2000', count($t) === 2000, (string) count($t));
    check('the first and the last fix are kept', $t[0] === $big[0] && $t[1999] === $big[4999]);
    check('order kept, no fix twice', $inc);
    check('1500 fixes stay 1500 (already under the limit)', count(GpsFleet::thin(array_slice($big, 0, 1500), 2000)) === 1500);
    $two = GpsFleet::thin(array_slice($big, 0, 10), 1);
    check('a limit under 2 still keeps start and end', count($two) === 2 && $two[0] === $big[0] && $two[1] === $big[9]);
    check('the replay limit is 2000', GpsFleet::TRAIL_MAX === 2000);

    echo "-- trail cleaning --\n";
    $row = static fn(float $lat, float $lng, int $ts, ?float $acc = 8.0, ?int $brg = null): array =>
        ['latitude' => (string) $lat, 'longitude' => (string) $lng, 'recorded_at' => date('Y-m-d H:i:s', $ts),
         'speed_kmh' => '40.00', 'bearing' => $brg, 'accuracy_m' => $acc === null ? null : (string) $acc];
    $T0 = 1_700_000_000;
    $clean = GpsFleet::cleanTrail([$row(23.0, 72.0, $T0), $row(north(23.0, 150), 72.0, $T0 + 15, 450.0),
        $row(north(23.0, 160), 72.0, $T0 + 15), $row(24.0, 72.0, $T0 + 30), $row(north(23.0, 320), 72.0, $T0 + 45, null, 365)]);
    check('a coarse fix (450 m) and a lone 111 km spike are dropped', count($clean) === 3, json_encode($clean));
    check('points are [lng, lat, unix s, km/h, bearing]', ($clean[0] ?? null) === [72.0, 23.0, $T0, 40.0, null] && ($clean[2][4] ?? null) === 5);

    echo "-- count bar --\n";
    $L = ['lat' => 23.0, 'lng' => 72.0];
    check('stopped: anchor and every fix since inside 200 m', GpsFleet::isStopped($L, ['lat' => north(23.0, 50), 'lng' => 72.0],
        [['lat' => north(23.0, 30), 'lng' => 72.0], ['lat' => north(23.0, 150), 'lng' => 72.0]]));
    check('not stopped: no fix 15 min old yet', !GpsFleet::isStopped($L, null, []));
    check('not stopped: it was 500 m away in the window', !GpsFleet::isStopped($L, ['lat' => 23.0, 'lng' => 72.0], [['lat' => north(23.0, 500), 'lng' => 72.0]]));
    $fleet = [
        ['tripId' => 1, 'lat' => 23.0, 'lng' => 72.0, 'ageSec' => 30],
        ['tripId' => 2, 'lat' => 23.0, 'lng' => 72.0, 'ageSec' => 900],
        ['tripId' => 3, 'lat' => null, 'lng' => null, 'ageSec' => null],
        ['tripId' => 4, 'lat' => 23.0, 'lng' => 72.0, 'ageSec' => 60],
        ['tripId' => 5, 'lat' => 23.0, 'lng' => 72.0, 'ageSec' => 700],
        ['tripId' => 6, 'lat' => 23.0, 'lng' => 72.0, 'ageSec' => 600],
    ];
    $today = [
        ['id' => 11, 'depTs' => $now - 3600, 'off' => false],
        ['id' => 12, 'depTs' => $now - 3600, 'off' => false],
        ['id' => 13, 'depTs' => $now + 3600, 'off' => false],
        ['id' => 14, 'depTs' => $now - 3600, 'off' => true],
        ['id' => 15, 'depTs' => $now, 'off' => false],
    ];
    $bar = GpsFleet::statusCounts($fleet, $today, [12 => 99], [4 => true, 5 => true], $now);
    check('running = every fleet trip', $bar['counts']['running'] === 6 && $bar['sets']['running'] === [1, 2, 3, 4, 5, 6]);
    check('GPS ok = a fix within 10 min (600 s counts)', $bar['sets']['gpsOk'] === [1, 4, 6], json_encode($bar['sets']['gpsOk']));
    check('GPS silent = older than 10 min, or never', $bar['sets']['silent'] === [2, 3, 5], json_encode($bar['sets']['silent']));
    check('stopped counts only buses whose GPS is ok', $bar['sets']['stopped'] === [4] && $bar['counts']['stopped'] === 1);
    check('not started = due, not cancelled/OFF, no trip started', $bar['sets']['notStarted'] === [11, 15] && $bar['counts']['notStarted'] === 2,
        json_encode($bar['sets']['notStarted']));
    $empty = GpsFleet::statusCounts([], [], [], [], $now);
    check('no data = five zero counts', $empty['counts'] === ['running' => 0, 'gpsOk' => 0, 'silent' => 0, 'stopped' => 0, 'notStarted' => 0]);
} catch (Throwable $e) {
    check('pure part threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
}

/* ================================================================
   Database part
   ================================================================ */
echo "-- database --\n";
if (!$haveConfig) {
    skip('no config/config.local.php here');
}
if (DB_NAME !== 'shari_test') {
    skip('refusing to write: DB_NAME is not shari_test');
}
try {
    new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET), DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
} catch (Throwable $e) {
    skip('the database is not running');
}
if (!LiveTrips::available() || !GpsTrackers::tableReady()) {
    skip('apply the live-tracking and gps-trackers migrations');
}
$route = Database::fetch('SELECT * FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1');
if ($route === null) {
    skip('no active route');
}

$cleanup = static function (): void {
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date IN (:a, :b)', ['a' => FM_DATE1, 'b' => FM_DATE2]) as $s) {
        foreach (Database::fetchAll('SELECT id FROM live_trips WHERE schedule_id = :s', ['s' => (int) $s['id']]) as $t) {
            Database::delete('trip_locations', 'trip_id = :t', ['t' => (int) $t['id']]);
            Database::delete('live_trips', 'id = :t', ['t' => (int) $t['id']]);
        }
        Database::delete('schedules', 'id = :s', ['s' => (int) $s['id']]);
    }
    foreach (Database::fetchAll('SELECT id FROM buses WHERE bus_number = :n', ['n' => FM_BUS]) as $b) {
        Database::delete('gps_trackers', 'bus_id = :b', ['b' => (int) $b['id']]);
        Database::delete('buses', 'id = :b', ['b' => (int) $b['id']]);
    }
};
/** Many trip_locations rows in one statement per 400. */
$insertFixes = static function (int $tripId, array $fixes): void {
    foreach (array_chunk($fixes, 400) as $chunk) {
        $vals = []; $pp = [];
        foreach ($chunk as $i => $f) {
            $vals[] = "(:t$i, :q$i, :la$i, :lo$i, :b$i, :s$i, :a$i, :r$i)";
            $pp += ["t$i" => $tripId, "q$i" => $f['seq'], "la$i" => $f['lat'], "lo$i" => $f['lng'], "b$i" => $f['brg'] ?? null,
                    "s$i" => $f['kmh'] ?? null, "a$i" => $f['acc'] ?? null, "r$i" => date('Y-m-d H:i:s', $f['ts'])];
        }
        Database::query('INSERT INTO trip_locations (trip_id, seq, latitude, longitude, bearing, speed_kmh, accuracy_m, recorded_at) VALUES '
            . implode(',', $vals), $pp);
    }
};

$cleanup();
$server = null;
try {
    $now     = time();
    $busId   = Database::insert('buses', ['bus_number' => FM_BUS, 'bus_name' => 'FM Test Coach', 'total_seats' => 72, 'is_active' => 1]);
    $routeId = (int) $route['id'];
    $sid1    = (int) Seats::schedule($routeId, FM_DATE1)['id'];
    $sid2    = (int) Seats::schedule($routeId, FM_DATE2)['id'];
    foreach ([$sid1, $sid2] as $s) {
        Database::update('schedules', ['bus_id' => $busId], 'id = :i', ['i' => $s]);
    }

    /* trip A: a finished trip with 5000 fixes, one coarse, one spike */
    $baseTs = $now - 5000 * 15 - 7200;
    $tA = Database::insert('live_trips', ['trip_ref' => LiveTrips::tripRef($sid1), 'schedule_id' => $sid1, 'route_id' => $routeId, 'bus_id' => $busId,
        'departure_at' => date('Y-m-d H:i:s', $baseTs), 'started_at' => date('Y-m-d H:i:s', $baseTs), 'ended_at' => date('Y-m-d H:i:s', $baseTs + 5000 * 15),
        'status' => 'completed']);
    $fixes = [];
    for ($i = 0; $i < 5000; $i++) {
        $fixes[] = ['seq' => $i + 1, 'lat' => $i === 2500 ? 24.0 : 23.0, 'lng' => 72.0 + $i * 0.0005, 'ts' => $baseTs + $i * 15,
                    'kmh' => 12.0, 'acc' => $i === 100 ? 500.0 : 8.0];
    }
    $insertFixes($tA, $fixes);

    echo "-- trail --\n";
    $trail = GpsFleet::trail($tA);
    $tp = $trail['points'] ?? [];
    check('trail() answers the trip', $trail !== null && $trail['trip']['id'] === $tA && $trail['trip']['bus'] === FM_BUS
        && $trail['trip']['scheduleId'] === $sid1, json_encode($trail['trip'] ?? null));
    check('the coarse fix and the spike are not in the trail', ($trail['total'] ?? 0) === 4998, (string) ($trail['total'] ?? 'null'));
    check('thinned to 2000 points', count($tp) === 2000, (string) count($tp));
    check('first and last fix kept', ($tp[0][2] ?? 0) === $baseTs && ($tp[1999][2] ?? 0) === $baseTs + 4999 * 15);
    check('no point of the spike (lat 24) is drawn', array_filter($tp, static fn(array $p): bool => $p[1] > 23.5) === []);
    check('a trip that does not exist = null', GpsFleet::trail(999999999) === null && GpsFleet::tripMeta(0) === null);

    /* trip B: running now, crawling north - 40 m a step, inside 200 m for 20 min */
    $P = 23.1;
    $tB = Database::insert('live_trips', ['trip_ref' => LiveTrips::tripRef($sid2), 'schedule_id' => $sid2, 'route_id' => $routeId, 'bus_id' => $busId,
        'departure_at' => date('Y-m-d H:i:s', $now - 3600), 'started_at' => date('Y-m-d H:i:s', $now - 3600), 'status' => 'running',
        'last_lat' => north($P, 120), 'last_lng' => 72.5, 'last_speed' => 2.0, 'last_ping_at' => date('Y-m-d H:i:s', $now - 30)]);
    $insertFixes($tB, [
        ['seq' => 1, 'lat' => $P, 'lng' => 72.5, 'ts' => $now - 1200],
        ['seq' => 2, 'lat' => north($P, 40), 'lng' => 72.5, 'ts' => $now - 600],
        ['seq' => 3, 'lat' => north($P, 80), 'lng' => 72.5, 'ts' => $now - 300],
        ['seq' => 4, 'lat' => north($P, 120), 'lng' => 72.5, 'ts' => $now - 30],
    ]);

    echo "-- heading from the database --\n";
    check('no reported course: derived from the last fixes (north = 0)', GpsFleet::busHeadings([$busId], $now) === [$busId => 0],
        json_encode(GpsFleet::busHeadings([$busId], $now)));
    Database::update('live_trips', ['last_bearing' => 123], 'id = :i', ['i' => $tB]);
    check('the reported course wins (123)', GpsFleet::busHeadings([$busId, 0, -4], $now) === [$busId => 123]);
    check('no bus = no query, no heading', GpsFleet::busHeadings([], $now) === []);
    $trk = GpsTrackers::add($busId, 'FM phone', 'phone', '');
    Database::update('gps_trackers', ['last_seen' => date('Y-m-d H:i:s', $now), 'last_fix_at' => date('Y-m-d H:i:s', $now),
        'last_lat' => north($P, 120), 'last_lng' => 72.5, 'last_speed' => 2.0], 'id = :i', ['i' => (int) $trk['id']]);
    $mine = array_values(array_filter(GpsFleet::snapshot($now)['devices'], static fn(array $d): bool => $d['id'] === (int) $trk['id']))[0] ?? null;
    $liveDev = $mine !== null && $mine['state'] === 'live';
    check('snapshot(): a live tracker carries its bus heading', $mine !== null && ($liveDev ? $mine['brg'] === 123 : $mine['brg'] === null),
        json_encode($mine === null ? null : ['state' => $mine['state'], 'brg' => $mine['brg']]));

    echo "-- stopped / started --\n";
    $fl = [['tripId' => $tB, 'lat' => north($P, 120), 'lng' => 72.5, 'ageSec' => 30]];
    check('a bus inside 200 m for 15 min is stopped', GpsFleet::stoppedTrips($fl, $now) === [$tB => true]);
    $fl[0]['ageSec'] = 900;
    check('...but not when its GPS is silent', GpsFleet::stoppedTrips($fl, $now) === []);
    $fl[0]['ageSec'] = 30; $fl[0]['lat'] = north($P, 600);
    check('...and not when the latest fix is 600 m on', GpsFleet::stoppedTrips($fl, $now) === []);
    check('liveTripIds(): schedule -> live trip, for Replay', GpsFleet::liveTripIds([$sid1, $sid2, 0]) === [$sid1 => $tA, $sid2 => $tB]
        || GpsFleet::liveTripIds([$sid1, $sid2, 0]) === [$sid2 => $tB, $sid1 => $tA]);
    $sb = GpsFleet::statusBar(null, $now);
    check('statusBar() runs on the real tables', isset($sb['counts']['running'], $sb['sets']['notStarted']) && in_array($tB, $sb['sets']['running'], true)
        && in_array($tB, $sb['sets']['gpsOk'], true), json_encode($sb['counts']));

    /* ---------------- HTTP: no session ---------------- */
    echo "-- http, no session --\n";
    if (!function_exists('curl_init')) {
        echo "  \033[33mSKIP\033[0m  http part: curl extension not loaded\n";
    } else {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $cmd  = [PHP_BINARY];
        if (($ini = php_ini_loaded_file()) !== false) { $cmd[] = '-c'; $cmd[] = $ini; }
        array_push($cmd, '-S', '127.0.0.1:' . FM_PORT, '-t', $ROOT);
        $server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        $get = static function (string $path): array {
            $ch = curl_init('http://127.0.0.1:' . FM_PORT . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20]);
            $raw  = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            $head = substr($raw, 0, $hs);
            return ['code' => $code, 'body' => substr($raw, $hs), 'loc' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : ''];
        };
        $up = false;
        for ($i = 0; $i < 50 && !$up; $i++) { usleep(200000); $up = $get('/robots.txt')['code'] > 0; }
        if (!$up) {
            check('php -S started on 127.0.0.1:' . FM_PORT, false);
        } else {
            $r = $get('/admin/api/trip-trail.php?trip=' . $tA);
            $j = json_decode($r['body'], true);
            check('trip-trail.php without a session: 401 JSON, no points', $r['code'] === 401 && is_array($j) && ($j['ok'] ?? null) === false
                && !str_contains($r['body'], 'points'), $r['code'] . ' ' . substr($r['body'], 0, 120));
            $r = $get('/admin/api/fleet-status.php');
            $j = json_decode($r['body'], true);
            check('fleet-status.php without a session: 401 JSON', $r['code'] === 401 && is_array($j) && ($j['ok'] ?? null) === false,
                $r['code'] . ' ' . substr($r['body'], 0, 120));
            $r = $get('/admin/map.php?trip=' . $tA);
            check('admin/map.php?trip= without a session: to the sign-in page', in_array($r['code'], [301, 302, 303], true) && str_contains($r['loc'], 'login.php'),
                $r['code'] . ' ' . $r['loc']);
        }
    }
} catch (Throwable $e) {
    check('database part threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    if ($server !== null) { proc_terminate($server); proc_close($server); }
    try {
        $cleanup();
        $left = (int) Database::scalar('SELECT COUNT(*) FROM buses WHERE bus_number = :n', ['n' => FM_BUS], 0)
              + (int) Database::scalar('SELECT COUNT(*) FROM schedules WHERE travel_date IN (:a, :b)', ['a' => FM_DATE1, 'b' => FM_DATE2], 0);
        check('nothing left behind', $left === 0);
    } catch (Throwable $e) {
        check('clean-up', false, $e->getMessage());
    }
}
finish();
