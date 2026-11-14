<?php
/**
 * =====================================================================
 *  fleet-watch-test.php - the coach on the road (15 Nov 2026, package P2).
 *
 *  includes/adminalerts.php (fleetWatch + the four new rules),
 *  includes/gpsretention.php (cron/rotate.php's GPS history) and
 *  WhereIs::authorised / linkExpired (the tracking link runs out).
 *
 *  On two inactive fixture routes, a far-future clock for the alert rules
 *  (2099-11-20) and a far-past one for retention (2020-06-01), so no real
 *  row can match. Pins:
 *
 *    - SOS / breakdown still open after N min (the driver app rang it when
 *      pressed) = one reminder, first in the list, stored LAST (top of the
 *      bell); resolved / delay / just-pressed rows and a press repeated on
 *      the same trip within 5 min (the same emergency) stay quiet; the
 *      passenger-reminder trip_events (no `kind`) and a missing
 *      driver_events are quiet, never a crash
 *    - GPS silent: stale trip, never-a-fix trip; not before departure, not
 *      when the bus's tracker still hears it, not for a finished trip
 *    - over-speed: stored speeds, derived speeds (a GPS jump ignored), and
 *      what is NOT counted (2 fast fixes, >150 km/h, coarse fixes)
 *    - off the road line: alert past the km; on the line, no road line, or
 *      not yet departed = quiet
 *    - a second run stores nothing new; the numbers live in the named
 *      settings (gps_silent_alert_min, overspeed_kmh, route_deviation_km)
 *    - retention: OFF at 0; at 7 days old fixes of finished trips go in
 *      batches (5003 rows = 2 batches), a running / paused trip is never
 *      touched, a finished trip with an end time is removed, without one kept
 *    - tracking link: refused once the last travel date is > track_link_days
 *      ago (a return leg keeps it alive); 0 = never; staff unaffected
 *
 *    php -c .claude/php-dev.ini tests/fleet-watch-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (DB_NAME !== 'shari_test') { exit("refuse: DB_NAME is not shari_test\n"); }
require_once INCLUDE_PATH . '/adminalerts.php';
require_once INCLUDE_PATH . '/routegeo.php';
require_once INCLUDE_PATH . '/livetrips.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/whereis.php';
require_once INCLUDE_PATH . '/gpsretention.php';

const FW_CODE  = 'FWT';
const FW_FROM  = 'FW Test A';
const FW_BUS   = 'FW-TEST-9921';
const FW_UID   = 'FWTEST9921TRACKER';
const FW_PNR   = 'FWT-';
const FW_EVT   = 'fw_test_driver_events';
const FW_PHONE = '9000009921';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$sql = static fn(int $ts): string => date('Y-m-d H:i:s', $ts);

/* Settings this suite changes, restored at the end exactly as they were. */
$touched = ['gps_history_days', 'track_link_days', 'gps_silent_alert_min', 'overspeed_kmh', 'route_deviation_km', 'alert_driver_sos_min'];
$saved   = [];
foreach ($touched as $k) {
    $saved[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$claims = [];

$cleanup = static function () use (&$claims): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE '" . FW_PNR . "%'") as $b) {
        Database::delete('bookings', 'id = :b', ['b' => (int) $b['id']]);          // legs cascade
    }
    foreach (Database::fetchAll("SELECT id FROM routes WHERE route_code LIKE '" . FW_CODE . "%' AND from_city = :f", ['f' => FW_FROM]) as $r) {
        Database::delete('live_trips', 'route_id = :r', ['r' => (int) $r['id']]);  // trip_locations cascade
        Database::delete('schedules', 'route_id = :r', ['r' => (int) $r['id']]);
        Database::delete('routes', 'id = :r', ['r' => (int) $r['id']]);            // route_geometry cascades
    }
    Database::delete('buses', 'bus_number = :b', ['b' => FW_BUS]);                // gps_trackers cascade
    Database::delete('drivers', 'phone = :p', ['p' => FW_PHONE]);
    Database::run("DELETE FROM notifications WHERE audience = 'admin' AND (title LIKE '%FW Test%' OR body LIKE '%FW Test%')");
    foreach (array_keys($claims) as $k) {
        Database::run('DELETE FROM automation_log WHERE dedupe_key = :k', ['k' => 'alert:' . $k]);
    }
};
$restore = static function () use ($saved): void {
    foreach ($saved as $k => $row) {
        if ($row === null) {
            Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        } else {
            Database::run('INSERT INTO settings (skey, svalue, stype, sgroup, is_public) VALUES (:k, :v, :t, :g, :p)
                 ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), stype = VALUES(stype)',
                ['k' => $k, 'v' => $row['svalue'], 't' => $row['stype'], 'g' => $row['sgroup'], 'p' => (int) $row['is_public']]);
        }
    }
    Settings::flush();
};

if (!LiveTrips::available() || !RouteGeo::available()) {
    check('live tracking + route geometry tables exist', false);
    echo "\n  $PASS passed, $FAIL failed\n";
    exit(1);
}

$cleanup();
try {
    /* ---------------- fixtures ---------------- */
    $tag    = substr(strtoupper(bin2hex(random_bytes(3))), 0, 6);
    $route  = Database::insert('routes', ['route_code' => FW_CODE . $tag, 'from_city' => FW_FROM, 'to_city' => 'FW Test B',
        'dep_time' => '10:00:00', 'arr_time' => '20:00:00', 'is_active' => 0]);
    $route2 = Database::insert('routes', ['route_code' => FW_CODE . 'N' . substr($tag, 0, 5), 'from_city' => FW_FROM, 'to_city' => 'FW Test C',
        'dep_time' => '10:00:00', 'arr_time' => '20:00:00', 'is_active' => 0]);
    $line = [];
    for ($i = 0; $i <= 25; $i++) { $line[] = [26.0, 82.0 + $i * 0.02]; }   // ~50 km east along 26 N
    Database::insert('route_geometry', ['route_id' => $route, 'version' => 1, 'polyline6' => RouteGeo::encode($line),
        'length_m' => 50000, 'source' => 'test', 'built_at' => date('Y-m-d H:i:s')]);
    RouteGeo::resetCache();
    $bus = Database::insert('buses', ['bus_number' => FW_BUS, 'bus_name' => 'FW Test coach', 'is_active' => 0]);

    $now = strtotime('2099-11-20 12:00:00');
    $nTrip = 0;
    $trip = static function (array $f) use (&$nTrip, $route, $sql, $now): int {
        $nTrip++;
        return Database::insert('live_trips', $f + [
            'trip_ref' => 'FWT' . $nTrip . '-' . bin2hex(random_bytes(4)), 'route_id' => $route,
            'departure_at' => $sql($now - 2 * 3600), 'started_at' => $sql($now - 2 * 3600), 'status' => 'running',
        ]);
    };
    $fix = static function (int $tripId, int $ts, float $lat, float $lng, ?float $kmh = null, ?float $acc = 10.0) use ($sql): void {
        static $seq = 0; $seq++;
        Database::insert('trip_locations', ['trip_id' => $tripId, 'seq' => $seq, 'latitude' => $lat, 'longitude' => $lng,
            'speed_kmh' => $kmh, 'accuracy_m' => $acc, 'recorded_at' => $sql($ts), 'received_at' => $sql($ts)]);
    };
    $ping = static fn(int $ts, float $lat = 26.0, float $lng = 82.2): array => ['last_ping_at' => date('Y-m-d H:i:s', $ts), 'last_lat' => $lat, 'last_lng' => $lng];

    $tSilent  = $trip($ping($now - 25 * 60));                               $fix($tSilent, $now - 25 * 60, 26.0, 82.2, 60.0);
    $tFast    = $trip($ping($now - 60, 26.0, 82.3));
    foreach ([[12, 100.0], [9, 104.0], [6, 98.0], [3, 60.0]] as [$m, $k]) { $fix($tFast, $now - $m * 60, 26.0, 82.3, $k); }
    $tDerived = $trip($ping($now - 9 * 60, 26.0, 82.1 + 3 * 0.01832));
    $step = 0.01832;                                                        // ~1.83 km at 26 N = 110 km/h in 60 s
    $fix($tDerived, $now - 12 * 60, 26.0, 82.1, null);
    $fix($tDerived, $now - 11 * 60, 26.0, 82.1 + $step, null);
    $fix($tDerived, $now - 11 * 60 + 30, 26.2, 82.1, null);                 // a GPS jump: ~22 km in 30 s
    $fix($tDerived, $now - 10 * 60, 26.0, 82.1 + 2 * $step, null);
    $fix($tDerived, $now - 9 * 60, 26.0, 82.1 + 3 * $step, null);
    $tCalm    = $trip($ping($now - 60, 26.0, 82.4));
    foreach ([[14, 95.0, 10.0], [13, 95.0, 10.0], [12, 180.0, 10.0], [11, 180.0, 10.0], [10, 180.0, 10.0],
              [8, 120.0, 500.0], [7, 120.0, 500.0], [6, 120.0, 500.0], [5, 50.0, 10.0], [40, 120.0, 10.0], [39, 120.0, 10.0]] as [$m, $k, $a]) {
        $fix($tCalm, $now - $m * 60, 26.0, 82.4, $k, $a);                  // 2 fast · 3 impossible · 3 coarse · 2 too old
    }
    $tOff     = $trip($ping($now - 120, 26.06, 82.2));                       $fix($tOff, $now - 120, 26.06, 82.2, 50.0);
    $tTracker = $trip($ping($now - 30 * 60) + ['bus_id' => $bus]);           $fix($tTracker, $now - 30 * 60, 26.0, 82.2, 40.0);
    Database::insert('gps_trackers', ['bus_id' => $bus, 'label' => 'FW test box', 'device_uid' => FW_UID, 'kind' => 'hardware',
        'is_active' => 1, 'last_seen' => $sql($now - 60), 'last_fix_at' => $sql($now - 60), 'last_lat' => 26.0, 'last_lng' => 82.2]);
    $tLater   = $trip($ping($now - 40 * 60, 26.08, 82.2) + ['departure_at' => $sql($now + 3 * 3600), 'started_at' => $sql($now - 50 * 60)]);
    $tDone    = $trip($ping($now - 5 * 3600) + ['status' => 'completed', 'ended_at' => $sql($now - 4 * 3600)]);
    $tNoFix   = $trip(['departure_at' => $sql($now - 20 * 60), 'started_at' => $sql($now - 30 * 60)]);
    $tNoLine  = $trip($ping($now - 60, 26.09, 82.2) + ['route_id' => $route2]);

    $prefs = [];
    foreach (AdminAlerts::RULES as $rule => [, , , $params]) {
        $prefs[$rule] = ['on' => in_array($rule, ['driver_sos', 'gps_silent', 'overspeed', 'route_deviation'], true)];
        foreach ($params as $p => [, $def]) { $prefs[$rule][$p] = (int) $def; }
    }
    $mine = static function (array $alerts, string $type, int $tripId): array {
        return array_values(array_filter($alerts, static fn(array $a): bool => $a['type'] === $type
            && preg_match('/^[a-z]+:' . $tripId . ':/', $a['key']) === 1));
    };

    echo "-- rules and settings --\n";
    foreach (['driver_sos', 'gps_silent', 'overspeed', 'route_deviation'] as $r) {
        check("rule $r is listed and on by default (bell only)", isset(AdminAlerts::RULES[$r]) && AdminAlerts::RULES[$r][2] === true);
    }
    Database::run("DELETE FROM settings WHERE skey IN ('gps_silent_alert_min', 'overspeed_kmh', 'route_deviation_km', 'alert_driver_sos_min')");
    Settings::flush();
    $p0 = AdminAlerts::prefs();
    check('defaults: SOS reminder 15 min, 10 min silent, 90 km/h, 3 km',
        $p0['driver_sos']['min'] === 15 && $p0['gps_silent']['min'] === 10 && $p0['overspeed']['kmh'] === 90 && $p0['route_deviation']['km'] === 3,
        json_encode([$p0['driver_sos'], $p0['gps_silent'], $p0['overspeed'], $p0['route_deviation']]));
    Settings::set('gps_silent_alert_min', 17, 'int', 'alerts');
    check('the silent minutes are read from gps_silent_alert_min', AdminAlerts::prefs()['gps_silent']['min'] === 17);
    $in = [];
    foreach (AdminAlerts::prefs() as $rule => $vals) { $in[$rule] = ['on' => $vals['on'] ? '1' : ''] + array_diff_key($vals, ['on' => 1]); }
    $in['overspeed']['kmh'] = 95;
    $changed = AdminAlerts::savePrefs($in, Settings::getBool('alert_whatsapp_on', false));
    check('saving the form writes overspeed_kmh and nothing else', $changed === 1
        && (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'overspeed_kmh'") === '95', 'changed ' . $changed);
    $restore();

    echo "-- GPS silent --\n";
    $all = AdminAlerts::fleetWatch($prefs, $now);
    $s = $mine($all, 'gps_silent', $tSilent);
    check('a running trip quiet for 25 min is reported', count($s) === 1 && str_contains($s[0]['title'], '25 min'), $s[0]['title'] ?? 'none');
    check('...once per silence (the key carries the last fix time)', ($s[0]['key'] ?? '') === 'gpssilent:' . $tSilent . ':' . ($now - 25 * 60));
    $s = $mine($all, 'gps_silent', $tNoFix);
    check('a trip that never sent a fix, 20 min after departure, is reported', count($s) === 1 && str_contains($s[0]['body'], 'No GPS position since'), $s[0]['body'] ?? 'none');
    check('a trip whose bus tracker still hears it is not silent', $mine($all, 'gps_silent', $tTracker) === []);
    check('a trip not due on the road yet is not silent', $mine($all, 'gps_silent', $tLater) === []);
    check('a finished trip is not watched', array_filter($all, static fn(array $a): bool => preg_match('/:' . $tDone . ':/', $a['key']) === 1) === []);
    check('fresh trips are not silent', $mine($all, 'gps_silent', $tFast) === [] && $mine($all, 'gps_silent', $tOff) === []);
    $p30 = $prefs; $p30['gps_silent']['min'] = 30;
    check('with 30 minutes set, 25 min of silence is fine', $mine(AdminAlerts::fleetWatch($p30, $now), 'gps_silent', $tSilent) === []);

    echo "-- over-speed --\n";
    $o = $mine($all, 'overspeed', $tFast);
    check('3 stored speeds above 90 in 15 min = over-speed, fastest named', count($o) === 1 && str_contains($o[0]['title'], '104 km/h'), $o[0]['title'] ?? 'none');
    $o = $mine($all, 'overspeed', $tDerived);
    check('no stored speed: derived from the fixes, the GPS jump ignored', count($o) === 1 && str_contains($o[0]['body'], '3 positions'), $o[0]['body'] ?? 'none');
    check('2 fast fixes, impossible speeds, coarse fixes and old fixes do not count', $mine($all, 'overspeed', $tCalm) === []);
    $p110 = $prefs; $p110['overspeed']['kmh'] = 110;
    check('with 110 km/h set, the 98-104 km/h coach is fine', $mine(AdminAlerts::fleetWatch($p110, $now), 'overspeed', $tFast) === []);

    echo "-- off the road line --\n";
    $d = $mine($all, 'route_deviation', $tOff);
    check('6.7 km from the road line = off the route', count($d) === 1 && str_contains($d[0]['title'], '6.7 km'), $d[0]['title'] ?? 'none');
    check('on the line = quiet', $mine($all, 'route_deviation', $tFast) === [] && $mine($all, 'route_deviation', $tSilent) === []);
    check('a route without a road line is skipped', $mine($all, 'route_deviation', $tNoLine) === []);
    check('a bus not due on the road yet (depot) is skipped', $mine($all, 'route_deviation', $tLater) === []);
    $p8 = $prefs; $p8['route_deviation']['km'] = 8;
    check('with 8 km set, 6.7 km is fine', $mine(AdminAlerts::fleetWatch($p8, $now), 'route_deviation', $tOff) === []);
    $off = $prefs; foreach (['gps_silent', 'overspeed', 'route_deviation'] as $r) { $off[$r]['on'] = false; }
    check('switched off = nothing', array_filter(AdminAlerts::fleetWatch($off, $now), static fn(array $a): bool => $a['type'] !== 'driver_sos') === []);
    check('every fleet alert stays in the panel (link /admin/...)', array_filter($all, static fn(array $a): bool => !str_starts_with($a['link'], '/admin/')) === []);

    echo "-- SOS / breakdown still open --\n";
    $isSos = static fn(array $a): bool => $a['type'] === 'driver_sos';
    check('the incident table is the driver app\'s driver_events by default', AdminAlerts::$eventsTable === 'driver_events');
    AdminAlerts::$eventsTable = 'trip_events';   // the passenger-reminder ledger: no kind / resolved_at
    if (!Database::exists("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_events' AND COLUMN_NAME = 'kind'")) {
        check('pointed at the older trip_events (no kind column): quiet, no crash', array_filter(AdminAlerts::fleetWatch($prefs, $now), $isSos) === []);
    }
    AdminAlerts::$eventsTable = 'fw_no_such_table';
    check('a missing incident table (migration not run): quiet, no crash', array_filter(AdminAlerts::fleetWatch($prefs, $now), $isSos) === []);
    Database::run('CREATE TEMPORARY TABLE ' . FW_EVT . ' (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, live_trip_id BIGINT UNSIGNED NULL, schedule_id BIGINT UNSIGNED NULL,
        bus_label VARCHAR(40) NULL, driver_ref VARCHAR(60) NULL, kind VARCHAR(20) NOT NULL, stop_name VARCHAR(120) NULL,
        minutes INT NULL, note VARCHAR(255) NULL, lat DECIMAL(10,7) NULL, lng DECIMAL(10,7) NULL, created_at DATETIME NOT NULL,
        resolved_at DATETIME NULL, resolved_by BIGINT UNSIGNED NULL, KEY (kind, resolved_at), KEY (live_trip_id), KEY (created_at))');
    AdminAlerts::$eventsTable = FW_EVT;
    $drv = Database::insert('drivers', ['full_name' => 'FW Test Driver', 'phone' => FW_PHONE, 'role' => 'driver', 'is_active' => 1]);
    $ev  = static fn(array $f): int => Database::insert(FW_EVT, $f + ['created_at' => $sql($now - 20 * 60)]);
    $eSos   = $ev(['live_trip_id' => $tFast, 'kind' => 'sos', 'driver_ref' => 'D' . $drv, 'stop_name' => 'Km 30', 'note' => 'Engine smoke']);
    $eBreak = $ev(['kind' => 'breakdown', 'bus_label' => 'FW Test bus', 'driver_ref' => 'D999999999', 'note' => 'Flat tyre']);
    $ev(['live_trip_id' => $tFast, 'kind' => 'sos', 'note' => 'FW Test handled', 'resolved_at' => $sql($now - 60)]);
    $ev(['live_trip_id' => $tFast, 'kind' => 'delay', 'minutes' => 40, 'note' => 'FW Test jam']);
    $ev(['live_trip_id' => $tFast, 'kind' => 'sos', 'note' => 'FW Test just pressed', 'created_at' => $sql($now - 5 * 60)]);
    $ev(['live_trip_id' => $tFast, 'kind' => 'sos', 'note' => 'FW Test pressed again', 'created_at' => $sql($now - 18 * 60)]);   // 2 min after $eSos: the same emergency
    $sos = array_values(array_filter(AdminAlerts::fleetWatch($prefs, $now), $isSos));
    check('open 20 min: SOS + breakdown remind; resolved, delay, a press repeated 2 min later and a 5-min-old SOS (just rung by the app) do not',
        count($sos) === 2, implode(' | ', array_column($sos, 'title')));
    check('the SOS names how long it is open, its coach, the driver, the note and the stop',
        isset($sos[1]) && str_contains($sos[1]['title'], '🆘 SOS still open 20 min') && str_contains($sos[1]['title'], 'FW Test A')
        && str_contains($sos[1]['body'], 'FW Test Driver (' . FW_PHONE . ')') && str_contains($sos[1]['body'], 'Engine smoke') && str_contains($sos[1]['body'], 'Km 30'),
        ($sos[1]['title'] ?? '') . ' | ' . ($sos[1]['body'] ?? ''));
    check('a breakdown without a trip still reminds, by its bus label (an unknown driver id is not shown)',
        isset($sos[0]) && str_contains($sos[0]['title'], 'Breakdown') && str_contains($sos[0]['title'], 'FW Test bus') && !str_contains($sos[0]['body'], 'D999999999'),
        ($sos[0]['title'] ?? '') . ' | ' . ($sos[0]['body'] ?? ''));
    $p5 = $prefs; $p5['driver_sos']['min'] = 3;
    check('with 3 minutes set, the 5-min-old SOS reminds too', count(array_filter(AdminAlerts::fleetWatch($p5, $now), $isSos)) === 3);
    $evalOnly = AdminAlerts::evaluate($prefs, false, $now);
    check('SOS rows lead the evaluate() list (the WhatsApp digest takes the first 8)',
        ($evalOnly[0]['type'] ?? '') === 'driver_sos' && ($evalOnly[1]['type'] ?? '') === 'driver_sos' && ($evalOnly[2]['type'] ?? '') !== 'driver_sos');

    echo "-- stored once, SOS on top --\n";
    $run1 = AdminAlerts::evaluate($prefs, true, $now);
    foreach ($run1 as $a) { $claims[$a['key']] = true; }
    $myRun   = array_values(array_filter($run1, static fn(array $a): bool => str_contains($a['title'] . $a['body'], 'FW Test')));
    $newKeys = array_values(array_map(static fn(array $a): string => $a['key'], array_filter($myRun, static fn(array $a): bool => $a['new'])));
    check('the run stores 2 silent + 2 speed + 1 off-route + 2 SOS reminders', count($newKeys) === 7, count($newKeys) . ' new: ' . implode(', ', $newKeys));
    $top = Database::fetch("SELECT title FROM notifications WHERE audience = 'admin' AND title LIKE '%FW Test%' ORDER BY id DESC LIMIT 1");
    check('the newest bell row is the SOS (stored last in the run)', $top !== null && str_contains((string) $top['title'], 'SOS still open'), (string) ($top['title'] ?? ''));
    $run2 = AdminAlerts::evaluate($prefs, true, $now);
    foreach ($run2 as $a) { $claims[$a['key']] = true; }
    $again = array_filter($run2, static fn(array $a): bool => $a['new'] && str_contains($a['title'] . $a['body'], 'FW Test'));
    check('a second run stores nothing new', $again === [], implode(', ', array_column($again, 'key')));
    $later = AdminAlerts::fleetWatch($prefs, $now + 10 * 60);
    $s10   = $mine($later, 'gps_silent', $tSilent);
    check('10 min later the same silence keeps its key (not rung again)', ($s10[0]['key'] ?? '') === 'gpssilent:' . $tSilent . ':' . ($now - 25 * 60)
        && str_contains($s10[0]['title'], '35 min'), $s10[0]['title'] ?? 'none');
    $sos10 = array_values(array_filter($later, static fn(array $a): bool => $a['type'] === 'driver_sos' && !isset($claims[$a['key']])));
    check('...and the just-pressed SOS reminds once it is 15 min open', count($sos10) === 1 && str_contains($sos10[0]['title'], 'still open 15 min'),
        implode(', ', array_column($sos10, 'title')));
    Database::run('UPDATE ' . FW_EVT . " SET resolved_at = :r WHERE kind = 'sos' OR kind = 'breakdown'", ['r' => $sql($now)]);
    check('once marked handled, no SOS reminder', array_filter(AdminAlerts::fleetWatch($prefs, $now + 3600), $isSos) === []);
    AdminAlerts::$eventsTable = 'driver_events';

    echo "-- GPS retention --\n";
    $rNow = strtotime('2020-06-01 12:00:00');
    $old  = strtotime('2020-05-01 10:00:00');
    $r1 = $trip(['status' => 'completed', 'departure_at' => $sql($old), 'started_at' => $sql($old), 'ended_at' => $sql($old + 86400)]);
    $r2 = $trip(['status' => 'completed', 'departure_at' => $sql($old + 19 * 86400), 'started_at' => $sql($old + 19 * 86400), 'ended_at' => $sql($old + 27 * 86400)]);
    $r3 = $trip(['status' => 'running', 'departure_at' => $sql($old), 'started_at' => $sql($old)]);
    $r4 = $trip(['status' => 'completed', 'departure_at' => $sql($old), 'started_at' => $sql($old)]);   // no end time
    $r5 = $trip(['status' => 'paused', 'departure_at' => $sql($old), 'started_at' => $sql($old)]);
    foreach ([$r1, $r3, $r4, $r5] as $t) { $fix($t, $old + 600, 26.0, 82.2, 50.0); $fix($t, $old + 900, 26.0, 82.21, 50.0); }
    $bulk = [];
    for ($i = 1; $i <= 5003; $i++) { $bulk[] = [$r2, 100000 + $i, $sql($old + 19 * 86400 + $i)]; }
    foreach (array_chunk($bulk, 1000) as $chunk) {
        Database::run('INSERT INTO trip_locations (trip_id, seq, latitude, longitude, recorded_at) VALUES '
            . implode(',', array_fill(0, count($chunk), '(?, ?, 26.0, 82.2, ?)')), array_merge(...$chunk));
    }
    $fix($r2, $old + 27 * 86400, 26.0, 82.3, 40.0);                          // after the cut-off: kept
    $fix($r2, $old + 27 * 86400 + 60, 26.0, 82.31, 40.0);
    $cnt = static fn(int $t): int => (int) Database::scalar('SELECT COUNT(*) FROM trip_locations WHERE trip_id = :t', ['t' => $t]);

    Settings::set('gps_history_days', 0, 'int', 'tracking');
    $res = GpsRetention::prune(null, $rNow);
    check('gps_history_days = 0: OFF, nothing deleted', $res === ['on' => false] && $cnt($r2) === 5005 && $cnt($r1) === 2);
    Settings::set('gps_history_days', 3, 'int', 'tracking');
    check('a window under 7 days is read as 7', GpsRetention::prune(null, strtotime('2000-01-01'))['days'] === 7);
    Settings::set('gps_history_days', 7, 'int', 'tracking');
    $res = GpsRetention::prune(null, $rNow);
    check('7 days: old fixes of finished trips deleted in batches (5003 + 2 + 2)', ($res['points'] ?? 0) === 5007 && $cnt($r2) === 2 && $cnt($r4) === 0, json_encode($res));
    check('a running trip and a paused trip keep every fix', $cnt($r3) === 2 && $cnt($r5) === 2);
    check('a finished trip that ended before the window is removed', LiveTrips::find($r1) === null && ($res['trips'] ?? 0) === 1, json_encode($res));
    check('a finished trip without an end time is kept (its row)', LiveTrips::find($r4) !== null);
    check('a trip that ended inside the window is kept with its new fixes', LiveTrips::find($r2) !== null && $cnt($r2) === 2);
    check('the live alert fixtures (2099) were not touched', $cnt($tFast) === 4 && LiveTrips::find($tSilent) !== null);
    $again = GpsRetention::prune(null, $rNow);
    check('a second run finds nothing more', ($again['points'] ?? -1) === 0 && ($again['trips'] ?? -1) === 0, json_encode($again));
    $restore();

    echo "-- tracking link expiry --\n";
    $today = todayISO();
    $sched = static function (string $date) use ($route): int {
        return Database::insert('schedules', ['route_id' => $route, 'travel_date' => $date, 'slot' => 1, 'total_seats' => 40]);
    };
    $nb = 0;
    $book = static function (array $dates) use (&$nb, $sched): array {
        $nb++;
        $pnr = FW_PNR . $nb . strtoupper(bin2hex(random_bytes(3)));
        $bid = Database::insert('bookings', ['pnr' => $pnr, 'contact_phone' => '90000099' . str_pad((string) $nb, 2, '0', STR_PAD_LEFT),
            'total_amount' => 1000, 'status' => 'confirmed', 'source' => 'admin']);
        foreach ($dates as $i => $d) {
            Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sched($d), 'travel_date' => $d,
                'leg_type' => $i === 0 ? 'outbound' : 'return', 'seat_count' => 1, 'boarding_stop' => 'FW Test A']);
        }
        return BookingService::findByPnr($pnr);
    };
    $bOld    = $book([addDaysISO($today, -5)]);
    $bEdge   = $book([addDaysISO($today, -2)]);
    $bRecent = $book([addDaysISO($today, -1)]);
    $bReturn = $book([addDaysISO($today, -9), addDaysISO($today, 1)]);
    $tok = static fn(array $b): string => Ticket::trackToken((string) $b['pnr']);
    unset($_SESSION[ADMIN_SESSION_KEY]);
    Settings::set('track_link_days', 2, 'int', 'tracking');

    check('a trip 5 days ago: the tracking link is refused', !WhereIs::authorised($bOld, $tok($bOld)));
    check('...the old download key is refused too', !WhereIs::authorised($bOld, Ticket::downloadToken((string) $bOld['pnr'])));
    check('2 days ago (not MORE than 2): still open', WhereIs::authorised($bEdge, $tok($bEdge)));
    check('yesterday: open', WhereIs::authorised($bRecent, $tok($bRecent)));
    check('a return ticket lives until its return leg', WhereIs::authorised($bReturn, $tok($bReturn)));
    check('a wrong key is still refused', !WhereIs::authorised($bRecent, 'x' . substr($tok($bRecent), 1)));
    check('linkExpired() follows the clock', WhereIs::linkExpired($bRecent, time() + 5 * 86400) && !WhereIs::linkExpired($bRecent));
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => 1, 'username' => 'fw-test', 'full_name' => 'FW Test', 'role' => 'superadmin',
        'permissions' => [], 'must_change_pw' => false, 'logged_in_at' => time(), 'last_seen' => time()];
    check('staff still open an old trip (no key needed)', WhereIs::authorised($bOld, '') && WhereIs::authorised($bOld, $tok($bOld)));
    unset($_SESSION[ADMIN_SESSION_KEY]);
    Settings::set('track_link_days', 0, 'int', 'tracking');
    check('track_link_days = 0: links never run out', WhereIs::authorised($bOld, $tok($bOld)));
    $restore();
    check('default (no setting row) is 2 days', Database::fetch("SELECT 1 FROM settings WHERE skey = 'track_link_days'") !== null
        || (!WhereIs::authorised($bOld, $tok($bOld)) && WhereIs::authorised($bEdge, $tok($bEdge))));
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    AdminAlerts::$eventsTable = 'driver_events';
    unset($_SESSION[ADMIN_SESSION_KEY]);
    try { Database::run('DROP TEMPORARY TABLE IF EXISTS ' . FW_EVT); } catch (Throwable $e) {}
    $restore();
    $cleanup();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
