<?php
/**
 * =====================================================================
 *  u8-stop-timing-test.php — real stop times from GPS trails, learned
 *  suggestions, and the learned ETA switch (19 Nov 2026, unit u8 / AI-4).
 *
 *  shari_test only. A switched-OFF synthetic route (route_code zzu8st…,
 *  three stops on a straight line, measured on the line of stops — no
 *  Valhalla) with live trips on 2099-03-0x; every row it makes is deleted
 *  at the end. Settings are flipped in this process's cache only.
 *
 *    php -c .claude/php-dev.ini tests/u8-stop-timing-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/stoptiming.php';
require_once INCLUDE_PATH . '/routegeo.php';

if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test — refusing to write\n"; exit(0); }
RouteGeo::$enabled = false;

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; } else { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function cacheSet(array $kv): void {
    Settings::get('company_name');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    foreach ($kv as $k => [$v, $t]) { $c[$k] = ['value' => $v, 'type' => $t, 'public' => false]; }
    $rp->setValue(null, $c);
}

echo "\n=== u8 AI-4: stop timing from GPS ===\n\n";
if (!StopTiming::ready()) { echo "  FAIL  tables missing — apply database/upgrade-2026-11-19-u8-stop-timing.sql\n"; exit(1); }

$code = 'zzu8st' . substr(bin2hex(random_bytes(3)), 0, 5);
$rid = (int) Database::insert('routes', ['route_code' => $code, 'from_city' => 'ZZTEST u8 Alpha', 'to_city' => 'ZZTEST u8 Gamma',
    'dep_time' => '13:00:00', 'arr_time' => '16:00:00', 'day_offset' => 0, 'is_active' => 0, 'sort_order' => 999]);
$names = ['ZZTEST u8 Alpha', 'ZZTEST u8 Beta', 'ZZTEST u8 Gamma'];
$lats = [22.0, 22.5, 23.0];
$sid = [];
foreach ($names as $i => $n) {
    $sid[$i] = (int) Database::insert('route_stops', ['route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => $n,
        'stop_time' => $i === 0 ? '13:00:00' : null, 'latitude' => $lats[$i], 'longitude' => 72.8, 'sort_order' => $i + 1, 'is_active' => 1]);
}
$trips = [];
$before = md5(json_encode(Database::fetchAll('SELECT * FROM route_stops WHERE route_id = :r ORDER BY id', ['r' => $rid])));

/**
 * A trail: A at 13:00 (+$late0), B at 13:00 + $bMin, dwell $dwell min, C at B-left + 80 min. One fix a minute.
 * $gap = [fromMin, toMin] → no fixes in that window. $extra = extra raw fixes.
 */
$makeTrip = static function (string $day, int $bMin, int $dwell, ?array $gap = null, array $extra = []) use ($rid, $lats, &$trips): int {
    $tid = (int) Database::insert('live_trips', ['trip_ref' => 'ZZU8-' . bin2hex(random_bytes(4)), 'route_id' => $rid,
        'departure_at' => $day . ' 13:00:00', 'status' => 'completed', 'started_at' => $day . ' 12:55:00', 'ended_at' => $day . ' 18:00:00']);
    $trips[] = $tid;
    $t0 = strtotime($day . ' 13:00:00');
    $pts = [];
    $leaveB = $bMin + $dwell;
    $end = $leaveB + 80;
    for ($m = 0; $m <= $end; $m++) {
        if ($gap !== null && $m > $gap[0] && $m < $gap[1]) { continue; }
        if ($m <= $bMin) { $lat = $lats[0] + ($lats[1] - $lats[0]) * $m / $bMin; $kmh = 55.0; }
        elseif ($m <= $leaveB) { $lat = $lats[1]; $kmh = 0.0; }
        else { $lat = $lats[1] + ($lats[2] - $lats[1]) * ($m - $leaveB) / 80; $kmh = 50.0; }
        $pts[] = [$t0 + $m * 60, $lat, 72.8, $kmh, 12.0];
    }
    foreach ($extra as $e) { $pts[] = $e; }
    usort($pts, static fn($a, $b) => $a[0] <=> $b[0]);
    foreach ($pts as $i => [$ts, $la, $ln, $v, $acc]) {
        Database::insert('trip_locations', ['trip_id' => $tid, 'seq' => $i + 1, 'latitude' => $la, 'longitude' => $ln, 'speed_kmh' => $v,
            'accuracy_m' => $acc, 'recorded_at' => date('Y-m-d H:i:s', $ts), 'received_at' => date('Y-m-d H:i:s', $ts)]);
    }
    return $tid;
};
$row = static fn(int $tid, int $stop, string $src = 'gps') => Database::fetch(
    "SELECT * FROM trip_stop_times WHERE trip_id = :t AND stop_kind = 'stop' AND stop_ref = :s AND source = :src", ['t' => $tid, 's' => $stop, 'src' => $src]);
$min = static fn(?array $r, string $col): ?int => $r === null || $r[$col] === null ? null : (int) round((strtotime((string) $r[$col]) - strtotime((string) $r['dep_at'])) / 60);

try {
    cacheSet(['stop_learn_on' => ['1', 'bool'], 'stop_learn_trips' => ['10', 'int'], 'stop_learn_min' => ['3', 'int'], 'eta_learned_on' => ['0', 'bool']]);

    /* trip 1: B at 60 min, 20-min dwell, one off-route fix and one inaccurate fix in the middle */
    $off = [strtotime('2099-03-01 13:30:30'), 25.0, 76.0, 50.0, 10.0];
    $bad = [strtotime('2099-03-01 13:40:30'), 22.95, 72.8, 50.0, 400.0];
    $t1 = $makeTrip('2099-03-01', 60, 20, null, [$off, $bad]);
    Database::insert('driver_events', ['live_trip_id' => $t1, 'kind' => 'stop_reached', 'stop_name' => $names[2], 'created_at' => '2099-03-01 15:21:00']);
    $r = StopTiming::recordTrip($t1);
    check('trip read: fixes placed, off-route and 400 m fixes ignored', $r['fixes'] === $r['used'] + 2, json_encode($r));
    $a = $row($t1, $sid[0]); $b = $row($t1, $sid[1]); $c = $row($t1, $sid[2]);
    check('A reached at departure (offset 0)', $a !== null && (int) $a['offset_min'] === 0, json_encode($a));
    check('B reached 60 min after departure (±1)', $b !== null && abs((int) $b['offset_min'] - 60) <= 1, json_encode($b));
    check('B dwell: left ~20 min later (±1)', $b !== null && abs((int) $min($b, 'left_at') - 80) <= 1, json_encode($b));
    check('C reached 160 min after departure (±1)', $c !== null && abs((int) $c['offset_min'] - 160) <= 1, json_encode($c));
    check('driver tap stored as source driver', ($d = $row($t1, $sid[2], 'driver')) !== null && (int) $d['offset_min'] === 141);
    $again = StopTiming::recordTrip($t1);
    check('re-run stores nothing new (idempotent)', $again['stored'] === 0 && $again['driver'] === 0
        && (int) Database::scalar('SELECT COUNT(*) FROM trip_stop_times WHERE trip_id = :t', ['t' => $t1], 0) === 4);

    /* trip 2: a 30-min GPS gap across B → no B row */
    $t2 = $makeTrip('2099-03-02', 60, 0, [45, 75]);
    StopTiming::recordTrip($t2);
    check('a 30-min gap across a stop gives no row for it', $row($t2, $sid[1]) === null && $row($t2, $sid[0]) !== null && $row($t2, $sid[2]) !== null);

    /* trips 3 and 4, then learn */
    $t3 = $makeTrip('2099-03-03', 70, 10);
    $t4 = $makeTrip('2099-03-04', 50, 10);
    StopTiming::recordTrip($t3); StopTiming::recordTrip($t4);
    $n = StopTiming::learn($rid);
    $L = StopTiming::learned($rid);
    $lb = $L['stop:' . $sid[1]] ?? null;
    // B is a BOARDING stop: learned on its departure (left 80, 80, 60), not the arrival (60, 70, 50)
    check('learn: boarding stop B learned on the departure — 3 samples, median 80, p80 80', $lb !== null && (int) $lb['samples'] === 3
        && abs((int) $lb['median_offset_min'] - 80) <= 1 && abs((int) $lb['p80_offset_min'] - 80) <= 1, json_encode($lb));
    check('learn: B suggested 14:20, day 0', $lb !== null && $lb['suggested_time'] === '14:20:00' && (int) $lb['day_offset'] === 0, json_encode($lb));
    $lc = $L['stop:' . $sid[2]] ?? null;
    check('learn: boarding stop C where the coach did not wait keeps the arrival (median 150)', $lc !== null && abs((int) $lc['median_offset_min'] - 150) <= 1, json_encode($lc));
    check('learn: origin A with no recorded departure (no left_at) is NOT learned', !isset($L['stop:' . $sid[0]]), json_encode($L['stop:' . $sid[0]] ?? null));
    check('originStop() = A', StopTiming::originStop($rid) === $sid[0]);
    check('learn: median dwell recorded', $lb !== null && $lb['median_dwell_min'] !== null && (int) $lb['median_dwell_min'] >= 9);
    check('suggest(): 13:00 + 1620 min = 16:00 next day', StopTiming::suggest('13:00', 1620) === ['16:00:00', 1]);
    check('suggest(): rounds to 5 minutes', StopTiming::suggest('13:00', 62) === ['14:00:00', 0] && StopTiming::suggest('13:00', 63) === ['14:05:00', 0]);
    check('median / p80 helpers', StopTiming::median([50, 60, 70]) === 60 && StopTiming::percentile([50, 60, 70], 0.8) === 70 && StopTiming::median([1, 2, 3, 5]) === 3);
    Database::run('DELETE FROM route_stop_learned WHERE route_id = :r', ['r' => $rid]);
    cacheSet(['stop_learn_min' => ['5', 'int']]);
    check('fewer samples than stop_learn_min → no suggestion', StopTiming::learn($rid) === 0);
    cacheSet(['stop_learn_min' => ['3', 'int']]);
    StopTiming::learn($rid);
    check('route_stops never written by recording or learning',
        md5(json_encode(Database::fetchAll('SELECT * FROM route_stops WHERE route_id = :r ORDER BY id', ['r' => $rid]))) === $before);

    /* learned ETA */
    $fix = ['lat' => 22.25, 'lng' => 72.8, 'kmh' => null];
    EtaAlerts::resetLearned();
    $offEta = EtaAlerts::stopEtas($rid, $fix);
    $gKey = Boarding::townKey($names[2]);
    check('eta_learned_on = 0 → the speed rule', isset($offEta[$gKey]) && $offEta[$gKey]['eta'] === EtaAlerts::etaMinutes($offEta[$gKey]['km'], null), json_encode($offEta));
    cacheSet(['eta_learned_on' => ['1', 'bool']]);
    EtaAlerts::resetLearned();
    $onEta = EtaAlerts::stopEtas($rid, $fix);
    // half way A→B: A anchors at its timetable departure (0), B at departure 80 - dwell 10 = 70 → clock 35;
    // C median of 160,140,160,140 = 150 → 115 min; floor 70 km/h ≈ 90 min
    check('eta_learned_on = 1 → learned minutes (115 ±2)', isset($onEta[$gKey]) && abs($onEta[$gKey]['eta'] - 115) <= 2, json_encode($onEta[$gKey] ?? null));
    check('learned ETA never earlier than 70 km/h', $onEta[$gKey]['eta'] >= (int) ceil($onEta[$gKey]['km'] / 70 * 60));
    Database::run("DELETE FROM route_stop_learned WHERE route_id = :r AND stop_ref = :s", ['r' => $rid, 's' => $sid[2]]);
    EtaAlerts::resetLearned();
    $miss = EtaAlerts::stopEtas($rid, $fix);
    check('a target without a learned time falls back to the speed rule', $miss[$gKey]['eta'] === EtaAlerts::etaMinutes($miss[$gKey]['km'], null));

    /* origin with a depot wait: tracking starts 12:25 at A, the coach leaves 13:00 */
    $depot = [];
    for ($m = 35; $m >= 1; $m--) { $depot[] = [strtotime('2099-03-05 13:00:00') - $m * 60, 22.0, 72.8, 0.0, 10.0]; }
    $t5 = $makeTrip('2099-03-05', 60, 10, null, $depot);
    StopTiming::recordTrip($t5);
    $a5 = $row($t5, $sid[0]);
    check('depot wait: A reached at 12:25 (-35), left ~13:00', $a5 !== null && (int) $a5['offset_min'] === -35 && abs((int) $min($a5, 'left_at')) <= 1, json_encode($a5));
    cacheSet(['stop_learn_min' => ['1', 'int']]);
    StopTiming::learn($rid);
    $la = StopTiming::learned($rid)['stop:' . $sid[0]] ?? null;
    check('origin learned from the departure: suggests 13:00, never the 12:25 depot start', $la !== null && (int) $la['samples'] === 1
        && $la['suggested_time'] === '13:00:00' && abs((int) $la['median_offset_min']) <= 1, json_encode($la));
    cacheSet(['stop_learn_min' => ['3', 'int']]);

    /* nightly: switch off */
    cacheSet(['stop_learn_on' => ['0', 'bool']]);
    check('stop_learn_on = 0 → nightly does nothing', StopTiming::nightly()['note'] === 'stop_learn_on is off');
} finally {
    if ($trips !== []) {
        $in = implode(',', array_map('intval', $trips));
        Database::run("DELETE FROM trip_stop_times WHERE trip_id IN ($in)");
        Database::run("DELETE FROM trip_locations WHERE trip_id IN ($in)");
        Database::run("DELETE FROM driver_events WHERE live_trip_id IN ($in)");
        Database::run("DELETE FROM live_trips WHERE id IN ($in)");
    }
    Database::run('DELETE FROM route_stop_learned WHERE route_id = :r', ['r' => $rid]);
    Database::run('DELETE FROM route_stops WHERE route_id = :r', ['r' => $rid]);
    Database::run('DELETE FROM routes WHERE id = :r', ['r' => $rid]);
    Settings::flush();
}
check('test rows removed', (int) Database::scalar("SELECT COUNT(*) FROM routes WHERE route_code LIKE 'zzu8st%'", [], 0) === 0);
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
