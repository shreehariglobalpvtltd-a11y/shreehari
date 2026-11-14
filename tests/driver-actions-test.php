<?php
/**
 * =====================================================================
 *  driver-actions-test.php - SHARI NAV driver app buttons (15 Nov 2026).
 *
 *  includes/tripevents.php + api/driver.php (action=event, ?action=trip,
 *  battery on ping). On a far-future date, on the test database only:
 *
 *    - "Stop pugyo" / "Dhilo" / rest / SOS / breakdown each store ONE
 *      driver_events row; a re-sent tap is the same row
 *    - "Dhilo" moves schedules.delay_minutes like the office's Set delay
 *      (audited) and its switch turns that off
 *    - SOS / breakdown: the bell + WhatsApp to admin_whatsapp / ceo_whatsapp
 *      (one per person), bus + Google Maps place + time in the text, once
 *      per kind per trip per 5 min, WhatsApp switch honoured. The sender is
 *      a stub - nothing is sent.
 *    - rate limits per driver (taps, and a separate SOS budget)
 *    - manifest: grouped by pickup in route order, first names, no ID
 *      numbers / fares, walk-in stand-in phone not callable
 *    - Google Maps directions URL: stops ahead with coordinates, <= 9
 *      waypoints, destination = last, travelmode=driving
 *    - over HTTP (php -S): ?action=trip, action=event, ping + battery,
 *      `me` crew settings, 401 / 409 / 422
 *
 *    php -c .claude/php-dev.ini tests/driver-actions-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/etaalerts.php';
require_once INCLUDE_PATH . '/livetrips.php';
require_once INCLUDE_PATH . '/driverauth.php';
require_once INCLUDE_PATH . '/tripevents.php';
require_once INCLUDE_PATH . '/routegeo.php';

if (DB_NAME !== 'shari_test') { exit("Refusing to run: DB_NAME is not shari_test.\n"); }

const DA_DATE  = '2099-09-14';
const DA_TAG   = 'SHG-DA-';
const DA_PHONE = '9000009941';
const DA_ADMIN = '919000009951';
const DA_CEO   = '919000009952';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? ' — ' . mb_substr($extra, 0, 400) : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }
/** report() that answers [result, http code of the refusal or 0] */
function tap(array $driver, int $tripId, array $in): array {
    $trip = LiveTrips::find($tripId);
    try { return [TripEvents::report($driver, $trip, $in), 0]; }
    catch (RuntimeException $e) { return [null, (int) $e->getCode()]; }
}
/** @return array{0: int, 1: mixed} */
function http(string $url, string $token = '', ?array $json = null): array {
    $ch = curl_init($url);
    $h  = ['Accept: application/json'];
    if ($token !== '') { $h[] = 'X-Driver-Token: ' . $token; }
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_NOPROXY => '*'];
    if ($json !== null) {
        $h[] = 'Content-Type: application/json';
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = json_encode($json);
    }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opt);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($body, true) ?? $body];
}

if (!TripEvents::available()) {
    check('driver_events table exists (apply database/upgrade-2026-11-15-trip-events.sql)', false);
    echo "\n  $PASS passed, $FAIL failed\n";
    exit(1);
}
if (!LiveTrips::available()) {
    check('live tracking tables exist', false);
    exit(1);
}

/* ---------------- settings this test changes: snapshot, put back exactly ---------------- */
$KEYS = ['admin_whatsapp', 'ceo_whatsapp', 'driver_actions_on', 'driver_sos_whatsapp_on', 'driver_delay_to_schedule', 'driver_speed_warn_kmh'];
$saved = [];
foreach ($KEYS as $k) { $saved[$k] = Database::fetch('SELECT skey, svalue, stype, sgroup, label, is_public FROM settings WHERE skey = :k', ['k' => $k]); }
$restoreSettings = static function () use ($KEYS, $saved): void {
    foreach ($KEYS as $k) {
        Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        if ($saved[$k] !== null) { Database::insert('settings', $saved[$k]); }
    }
};

$bells = [];
$made  = ['did' => 0, 'sid' => 0, 'trip' => 0, 'resolved' => []];
$cleanup = static function () use (&$made, &$bells): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE '" . DA_TAG . "%'") as $b) {
        Database::delete('booking_passengers', 'booking_id = :b', ['b' => (int) $b['id']]);
        Database::delete('booking_legs', 'booking_id = :b', ['b' => (int) $b['id']]);
        Database::delete('bookings', 'id = :b', ['b' => (int) $b['id']]);
    }
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => DA_DATE]) as $s) {
        foreach (Database::fetchAll('SELECT id FROM live_trips WHERE schedule_id = :s', ['s' => (int) $s['id']]) as $t) {
            Database::delete('driver_events', 'live_trip_id = :t', ['t' => (int) $t['id']]);
            Database::run("DELETE FROM kv_store WHERE kscope = 'driver_battery' AND kkey = :k", ['k' => 'trip:' . (int) $t['id']]);
        }
        Database::delete('live_trips', 'schedule_id = :s', ['s' => (int) $s['id']]);   // trip_locations cascade
        Database::run("DELETE FROM audit_logs WHERE entity_type = 'schedule' AND entity_id = :s", ['s' => (string) $s['id']]);
        Database::delete('schedules', 'id = :s', ['s' => (int) $s['id']]);
    }
    foreach (Database::fetchAll('SELECT id FROM drivers WHERE phone = :p', ['p' => DA_PHONE]) as $d) {
        Database::delete('driver_events', 'driver_ref = :r', ['r' => 'D' . (int) $d['id']]);
        Database::run('DELETE FROM rate_limits WHERE identifier = :i', ['i' => 'drv' . (int) $d['id']]);
        foreach (Database::fetchAll('SELECT id FROM driver_devices WHERE driver_id = :d', ['d' => (int) $d['id']]) as $dev) {
            Database::run('DELETE FROM rate_limits WHERE identifier = :i', ['i' => 'dev' . (int) $dev['id']]);
        }
        Database::run("DELETE FROM audit_logs WHERE entity_type = 'driver' AND entity_id = :i", ['i' => (string) $d['id']]);
        Database::delete('driver_devices', 'driver_id = :d', ['d' => (int) $d['id']]);
        Database::delete('drivers', 'id = :d', ['d' => (int) $d['id']]);
    }
    foreach ($made['resolved'] as $eid) {
        Database::run("DELETE FROM audit_logs WHERE entity_type = 'driver_event' AND entity_id = :i", ['i' => (string) $eid]);
    }
    foreach (array_filter($bells) as $nid) {   // the bells this run rang
        Database::run("DELETE FROM notifications WHERE id = :i AND audience = 'admin'", ['i' => (int) $nid]);
    }
};

$route = null;
foreach (Database::fetchAll('SELECT * FROM routes WHERE is_active = 1 ORDER BY id') as $r) {
    $b = array_values(array_filter(Boarding::lineStops((int) $r['id']), static fn($s) => $s['type'] === 'boarding' && $s['lat'] !== null));
    if (count($b) >= 3) { $route = $r; break; }
}
if ($route === null) {
    echo "  \033[33mSKIP\033[0m  needs a route with three boarding stops that have coordinates\n";
    exit(0);
}
RouteGeo::$enabled = false;   // positions are measured on the line of stops here

$server = null;
$cleanup();
try {
    /* ================================================================= */
    section('fixtures: a driver, a departure, a running trip');
    $routeId = (int) $route['id'];
    $did = Database::insert('drivers', ['full_name' => 'DA Test Driver', 'phone' => DA_PHONE, 'role' => 'driver', 'is_active' => 1]);
    $made['did'] = $did;
    DriverAuth::setPin($did, '5813');
    $login = DriverAuth::login(DA_PHONE, '5813', 'test phone');
    $me    = DriverAuth::current($login['token']);
    $sched = Seats::schedule($routeId, DA_DATE);
    $sid   = (int) $sched['id'];
    $made['sid'] = $sid;
    $bus = Database::fetch('SELECT id, bus_number FROM buses WHERE bus_number IS NOT NULL AND bus_number <> \'\' ORDER BY id LIMIT 1');
    if ($bus !== null) { Database::update('schedules', ['bus_id' => (int) $bus['id']], 'id = :i', ['i' => $sid]); }
    $depTs = (int) strtotime(DA_DATE . ' ' . ($sched['dep_time_override'] ?? $route['dep_time']));
    $row   = LiveTrips::start($did, $me['deviceId'], $sid, $depTs + 1800);
    $tripId = (int) $row['id'];
    $made['trip'] = $tripId;
    check('a running trip for the test departure', (string) $row['status'] === 'running' && $tripId > 0);
    $stops = TripEvents::routeStops($routeId, (string) ($sched['dep_time_override'] ?? $route['dep_time']));
    check('the route\'s stops come in travel order (the timetable clock), not the office\'s sort order', (static function () use ($stops): bool {
        $prev = -1;   // pickups only: minutes after the first pickup, a past-midnight clock is the next day
        $first = null;
        foreach ($stops as $s) {
            if ($s['type'] !== 'boarding' || $s['time'] === null) { continue; }
            $m = (int) substr((string) $s['time'], 0, 2) * 60 + (int) substr((string) $s['time'], 3, 2);
            $first ??= $m;
            $off = (($m - $first) % 1440 + 1440) % 1440;
            if ($off < $prev) { return false; }
            $prev = $off;
        }
        return true;
    })(), json_encode(array_map(static fn($s) => $s['name'] . ' ' . $s['time'], $stops), JSON_UNESCAPED_UNICODE));
    $pick  = array_values(array_filter($stops, static fn($s) => $s['type'] === 'boarding' && $s['lat'] !== null));
    $idxOf = static function (string $name) use ($stops): int { foreach ($stops as $i => $s) { if ($s['name'] === $name) { return $i; } } return -1; };

    /* ================================================================= */
    section('Google Maps directions URL');
    check('no stop with coordinates = no link', TripEvents::mapsUrl(null, [['name' => 'x', 'lat' => null, 'lng' => null], ['lat' => 0, 'lng' => 0]]) === '');
    $u = TripEvents::mapsUrl(null, [['lat' => 27.05, 'lng' => 81.58]]);
    parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
    check('one stop: destination only, no waypoints, driving', str_starts_with($u, 'https://www.google.com/maps/dir/?api=1')
        && ($q['destination'] ?? '') === '27.050000,81.580000' && !isset($q['waypoints']) && !isset($q['origin']) && ($q['travelmode'] ?? '') === 'driving', $u);
    $many = [];
    for ($i = 0; $i < 14; $i++) { $many[] = ['lat' => 22.0 + $i * 0.3, 'lng' => 73.0 + $i * 0.4]; }
    array_splice($many, 3, 0, [['name' => 'no coords', 'lat' => null, 'lng' => null]]);
    $u = TripEvents::mapsUrl(['lat' => 21.5, 'lng' => 72.8], $many);
    parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
    $wp = explode('|', (string) ($q['waypoints'] ?? ''));
    check('14 stops ahead: 9 waypoints at most, destination = the last stop', count($wp) === 9 && ($q['destination'] ?? '') === sprintf('%.6f,%.6f', 22.0 + 13 * 0.3, 73.0 + 13 * 0.4), $u);
    check('…the next stop is the first waypoint, the stop without coordinates is skipped', $wp[0] === '22.000000,73.000000' && !in_array('', $wp, true));
    check('…origin = the coach when known', ($q['origin'] ?? '') === '21.500000,72.800000');
    check('…waypoints in road order', $wp === array_values(array_unique($wp)) && (float) explode(',', $wp[8])[0] < 22.0 + 13 * 0.3);

    /* ================================================================= */
    section('Stop pugyo');
    $A = $pick[0]['name']; $B = $pick[1]['name']; $C = $pick[2]['name'];
    [$r, $code] = tap($me, $tripId, ['kind' => 'stop_reached', 'stop' => 'Nowhere Town']);
    check('a stop that is not on the route is refused (422)', $r === null && $code === 422);
    $t1 = (time() - 60) * 1000;
    [$r, $code] = tap($me, $tripId, ['kind' => 'stop_reached', 'stop' => mb_strtoupper($B), 'lat' => $pick[1]['lat'], 'lng' => $pick[1]['lng'], 't' => $t1]);
    $ev = $r['event'] ?? [];
    check('a route stop (any case) is stored under the route\'s own name', $code === 0 && ($ev['stop'] ?? '') === $B && ($r['repeat'] ?? true) === false, json_encode($r));
    $dbRow = Database::fetch('SELECT * FROM driver_events WHERE id = :i', ['i' => (int) ($ev['id'] ?? 0)]);
    check('…one row: trip, schedule, driver ref, bus, position, phone time', $dbRow !== null && (int) $dbRow['live_trip_id'] === $tripId
        && (int) $dbRow['schedule_id'] === $sid && $dbRow['driver_ref'] === 'D' . $did && (string) $dbRow['kind'] === 'stop_reached'
        && abs((float) $dbRow['lat'] - (float) $pick[1]['lat']) < 1e-6 && (string) $dbRow['created_at'] === date('Y-m-d H:i:s', intdiv($t1, 1000))
        && ($bus === null || (string) $dbRow['bus_label'] === (string) $bus['bus_number']), json_encode($dbRow));
    check('…live_trips.current_stop follows', (string) Database::scalar('SELECT current_stop FROM live_trips WHERE id = :i', ['i' => $tripId], '') === $B);
    check('…the answer carries the next stop', ($r['nav']['passedIdx'] ?? -9) === $idxOf($B) && ($r['nav']['next'] ?? '') === ($stops[$idxOf($B) + 1]['name'] ?? null), json_encode($r['nav'] ?? null));
    $n = (int) Database::scalar('SELECT COUNT(*) FROM driver_events WHERE live_trip_id = :t', ['t' => $tripId], 0);
    [$r2] = tap($me, $tripId, ['kind' => 'stop_reached', 'stop' => $B, 't' => $t1]);
    check('the same tap re-sent from the queue is the same row (repeat)', ($r2['repeat'] ?? false) === true && ($r2['event']['id'] ?? 0) === ($ev['id'] ?? -1)
        && (int) Database::scalar('SELECT COUNT(*) FROM driver_events WHERE live_trip_id = :t', ['t' => $tripId], 0) === $n);
    check('clientTime: the future and day-old phone clocks are read as now', TripEvents::clientTime((time() + 3600) * 1000, time()) === time()
        && TripEvents::clientTime((time() - 3 * 86400) * 1000, time()) === time() && TripEvents::clientTime('x', 5) === 5);

    /* ================================================================= */
    section('Dhilo');
    Database::update('schedules', ['delay_minutes' => 0, 'delay_note' => null], 'id = :i', ['i' => $sid]);
    [$r, $code] = tap($me, $tripId, ['kind' => 'delay', 'minutes' => 3]);
    check('under 5 minutes is refused (422)', $r === null && $code === 422);
    [$r, $code] = tap($me, $tripId, ['kind' => 'delay', 'minutes' => 601]);
    check('over 600 minutes is refused (422)', $r === null && $code === 422);
    [$r, $code] = tap($me, $tripId, ['kind' => 'delay', 'minutes' => '45', 'note' => 'जाम · Traffic — jam at the bridge']);
    $s = Database::fetch('SELECT delay_minutes, delay_note FROM schedules WHERE id = :i', ['i' => $sid]);
    check('a delay is stored and moves the schedule like the office\'s Set delay', $code === 0 && ($r['event']['minutes'] ?? 0) === 45
        && (int) $s['delay_minutes'] === 45 && (string) $s['delay_note'] === 'जाम · Traffic — jam at the bridge' && ($r['schedule'] ?? false) === true, json_encode([$r, $s]));
    check('…audited as trip.set_delay by the driver app', Database::exists(
        "SELECT 1 FROM audit_logs WHERE action = 'trip.set_delay' AND entity_type = 'schedule' AND entity_id = :s AND detail LIKE '%driver app%'", ['s' => (string) $sid]));
    check('…no passenger message is queued from here', !Database::exists(
        "SELECT 1 FROM automation_log WHERE dedupe_key LIKE :k", ['k' => 'delaynotify:' . $sid . ':%']));
    Settings::set('driver_delay_to_schedule', false, 'bool', 'driver');
    [$r] = tap($me, $tripId, ['kind' => 'delay', 'minutes' => 90]);
    check('switch driver_delay_to_schedule off: stored, the schedule stays', ($r['schedule'] ?? true) === false
        && (int) Database::scalar('SELECT delay_minutes FROM schedules WHERE id = :i', ['i' => $sid], 0) === 45);
    Settings::set('driver_delay_to_schedule', true, 'bool', 'driver');
    $tD = (time() - 30) * 1000;
    [$d1] = tap($me, $tripId, ['kind' => 'delay', 'minutes' => 20, 't' => $tD]);
    [$d2] = tap($me, $tripId, ['kind' => 'delay', 'minutes' => 20, 't' => $tD]);
    [$d3] = tap($me, $tripId, ['kind' => 'delay', 'minutes' => 25, 't' => $tD]);
    check('a re-sent delay (same phone time, same minutes) is one row; other minutes in that second are a new tap',
        ($d2['repeat'] ?? false) === true && ($d2['event']['id'] ?? 0) === ($d1['event']['id'] ?? -1)
        && ($d3['repeat'] ?? true) === false && ($d3['event']['id'] ?? 0) !== ($d1['event']['id'] ?? 0));

    /* ================================================================= */
    section('rest');
    check('not resting at the start', TripEvents::restState($tripId)['resting'] === false);
    [$r] = tap($me, $tripId, ['kind' => 'rest_start']);
    check('Aaram suru: resting since now', ($r['rest']['resting'] ?? false) === true && abs(($r['rest']['sinceTs'] ?? 0) - time()) < 5);
    $n = (int) Database::scalar("SELECT COUNT(*) FROM driver_events WHERE live_trip_id = :t AND kind LIKE 'rest%'", ['t' => $tripId], 0);
    [$r] = tap($me, $tripId, ['kind' => 'rest_start']);
    check('a second Aaram suru while resting adds nothing', ($r['repeat'] ?? false) === true
        && (int) Database::scalar("SELECT COUNT(*) FROM driver_events WHERE live_trip_id = :t AND kind LIKE 'rest%'", ['t' => $tripId], 0) === $n);
    [$r] = tap($me, $tripId, ['kind' => 'rest_end', 't' => (time() + 1) * 1000]);
    check('Aaram sakiyo: driving again, the timer restarts at the rest end', ($r['rest']['resting'] ?? true) === false && ($r['rest']['lastEndTs'] ?? 0) >= time() - 5);

    /* ================================================================= */
    section('SOS and breakdown: the owner and the office at once (sender stubbed)');
    Settings::set('admin_whatsapp', DA_ADMIN, 'string', 'whatsapp');
    Settings::set('ceo_whatsapp', '+91 ' . substr(DA_CEO, 2, 5) . ' ' . substr(DA_CEO, 7), 'string', 'whatsapp');
    Settings::set('driver_sos_whatsapp_on', true, 'bool', 'driver');
    $sent = [];
    TripEvents::$send = static function (string $to, string $text, string $purpose) use (&$sent): bool { $sent[] = [$to, $text, $purpose]; return true; };
    check('office numbers: owner + CEO, one each', TripEvents::officeNumbers() === [DA_ADMIN, DA_CEO], json_encode(TripEvents::officeNumbers()));
    Settings::set('ceo_whatsapp', '0091 ' . substr(DA_ADMIN, 2), 'string', 'whatsapp');
    check('…the CEO typed the owner\'s number another way: one message', TripEvents::officeNumbers() === [DA_ADMIN]);
    Settings::set('ceo_whatsapp', DA_CEO, 'string', 'whatsapp');

    $lat = (float) $pick[2]['lat']; $lng = (float) $pick[2]['lng'];
    [$r, $code] = tap($me, $tripId, ['kind' => 'sos', 'note' => 'Accident near the river', 'lat' => $lat, 'lng' => $lng]);
    $bells[] = (int) ($r['alert']['bell'] ?? 0);
    check('SOS is stored and alerts: one bell, one WhatsApp per office number', $code === 0 && ($r['alert']['bell'] ?? 0) > 0
        && ($r['alert']['whatsapp'] ?? 0) === 2 && count($sent) === 2, json_encode($r));
    $txt = $sent[0][1] ?? '';
    check('…to the owner and the CEO, purpose trip_sos', ($sent[0][0] ?? '') === DA_ADMIN && ($sent[1][0] ?? '') === DA_CEO && ($sent[0][2] ?? '') === 'trip_sos');
    check('…the text says SOS, the bus, the driver, the place as a Google Maps link and the time',
        str_contains($txt, 'SOS') && str_contains($txt, 'DA Test Driver') && str_contains($txt, DA_PHONE)
        && str_contains($txt, 'https://www.google.com/maps?q=' . sprintf('%.6f,%.6f', $lat, $lng))
        && str_contains($txt, 'Time: ' . date('j M H:i')) && str_contains($txt, 'Accident near the river')
        && ($bus === null || str_contains($txt, (string) $bus['bus_number'])), $txt);
    $bell = Database::fetch('SELECT * FROM notifications WHERE id = :i', ['i' => (int) ($r['alert']['bell'] ?? 0)]);
    check('…the bell is an office (admin) notification linking the live map', $bell !== null && (string) $bell['audience'] === 'admin'
        && str_contains((string) $bell['title'], 'SOS') && (string) $bell['link'] === '/admin/map.php');
    check('…it is an open alert for the office, with the driver', in_array((int) ($r['event']['id'] ?? 0), array_map(static fn($a) => (int) $a['id'], TripEvents::openAlerts()), true)
        && ($r['event']['open'] ?? false) === true);
    [$r] = tap($me, $tripId, ['kind' => 'sos', 'lat' => $lat, 'lng' => $lng]);
    check('SOS pressed again within 5 minutes: stored, but no second alert', ($r['event']['id'] ?? 0) > 0 && ($r['alert']['skipped'] ?? '') === 'repeat' && count($sent) === 2);
    [$r] = tap($me, $tripId, ['kind' => 'breakdown', 'note' => 'Puncture']);
    $bells[] = (int) ($r['alert']['bell'] ?? 0);
    check('breakdown is its own alert: bell + WhatsApp (purpose trip_breakdown)', ($r['alert']['bell'] ?? 0) > 0 && count($sent) === 4 && ($sent[2][2] ?? '') === 'trip_breakdown'
        && str_contains((string) ($sent[2][1] ?? ''), 'breakdown'), json_encode($r));
    check('…no GPS from the phone: the coach\'s last fix or "GPS not available"', str_contains((string) ($sent[2][1] ?? ''), 'GPS not available'), (string) ($sent[2][1] ?? ''));
    Database::update('live_trips', ['last_lat' => $lat, 'last_lng' => $lng, 'last_ping_at' => date('Y-m-d H:i:s', time() - 600)], 'id = :i', ['i' => $tripId]);
    Database::run("UPDATE driver_events SET created_at = created_at - INTERVAL 10 MINUTE WHERE live_trip_id = :t AND kind = 'breakdown'", ['t' => $tripId]);
    Settings::set('driver_sos_whatsapp_on', false, 'bool', 'driver');
    [$r] = tap($me, $tripId, ['kind' => 'breakdown']);
    $bells[] = (int) ($r['alert']['bell'] ?? 0);
    check('switch driver_sos_whatsapp_on off: the bell still rings, no WhatsApp', ($r['alert']['bell'] ?? 0) > 0 && ($r['alert']['skipped'] ?? '') === 'whatsapp off' && count($sent) === 4);
    check('…the place falls back to the coach\'s last fix', abs((float) Database::scalar('SELECT lat FROM driver_events WHERE id = :i', ['i' => (int) ($r['event']['id'] ?? 0)], 0) - $lat) < 1e-6);
    $m = TripEvents::message(Database::fetch('SELECT * FROM driver_events WHERE id = :i', ['i' => (int) ($r['event']['id'] ?? 0)]),
        TripEvents::context($tripId) + ['driver' => $me, 'approx' => true, 'fixAt' => date('Y-m-d H:i:s', time() - 600)]);
    check('…and the text says it is the last GPS', str_contains($m['text'], '(last GPS ' . date('H:i', time() - 600) . ')'), $m['text']);
    Settings::set('driver_sos_whatsapp_on', true, 'bool', 'driver');
    $openId = (int) (TripEvents::openAlerts()[0]['id'] ?? 0);
    $made['resolved'][] = $openId;
    check('the office closes an alert (resolve) once', $openId > 0 && TripEvents::resolve($openId, 1) && !TripEvents::resolve($openId, 1)
        && !in_array($openId, array_map(static fn($a) => (int) $a['id'], TripEvents::openAlerts()), true));
    foreach (TripEvents::openAlerts(200) as $a) {   // the office closes this trip's SOS ...
        if ((int) $a['live_trip_id'] === $tripId && (string) $a['kind'] === 'sos' && TripEvents::resolve((int) $a['id'], 1)) {
            $made['resolved'][] = (int) $a['id'];
        }
    }
    $n0 = count($sent);
    [$r] = tap($me, $tripId, ['kind' => 'sos', 'lat' => $lat, 'lng' => $lng]);   // ... and a new one comes within 5 min
    $bells[] = (int) ($r['alert']['bell'] ?? 0);
    check('…a new SOS after the office closed the last one alerts again (closed = no longer "the office knows")',
        ($r['alert']['bell'] ?? 0) > 0 && ($r['alert']['skipped'] ?? 'x') === '' && count($sent) === $n0 + 2, json_encode($r['alert'] ?? null));

    /* ================================================================= */
    section('refusals and rate limits');
    Settings::set('driver_actions_on', false, 'bool', 'driver');
    [$r, $code] = tap($me, $tripId, ['kind' => 'sos']);
    check('buttons switched off by the office: 503', $r === null && $code === 503);
    Settings::set('driver_actions_on', true, 'bool', 'driver');
    [$r, $code] = tap($me, $tripId, ['kind' => 'party']);
    check('an unknown button: 422', $r === null && $code === 422);
    try { TripEvents::report($me, LiveTrips::find($tripId), ['kind' => 'party']); $cls = ''; } catch (Throwable $e) { $cls = get_class($e); }
    check('…a refusal is a TripEventRefused (api/driver.php answers only those as 4xx; a PDOException stays a 500 the phone re-sends)',
        $cls === 'TripEventRefused' && !is_subclass_of('PDOException', 'TripEventRefused'), $cls);
    Database::run('DELETE FROM rate_limits WHERE identifier = :i', ['i' => 'drv' . $did]);
    $base = time() - 3000; $ok30 = true;
    for ($i = 0; $i < 30; $i++) {
        [$r, $code] = tap($me, $tripId, ['kind' => 'stop_reached', 'stop' => $A, 't' => ($base + $i) * 1000]);
        $ok30 = $ok30 && $code === 0;
    }
    [$r, $code] = tap($me, $tripId, ['kind' => 'stop_reached', 'stop' => $A, 't' => ($base + 40) * 1000]);
    check('30 taps in 10 minutes pass, the 31st is refused (429)', $ok30 && $r === null && $code === 429);
    $sentBefore = count($sent);
    [$r, $code] = tap($me, $tripId, ['kind' => 'sos', 't' => ($base + 41) * 1000]);
    check('…SOS has its own budget: still goes through', $code === 0 && ($r['event']['id'] ?? 0) > 0);
    $okS = $code === 0;
    for ($i = 0; $i < 9; $i++) {
        [, $code] = tap($me, $tripId, ['kind' => 'sos', 't' => ($base + 50 + $i) * 1000]);
        $okS = $okS && $code === 0;
    }
    [$r, $code] = tap($me, $tripId, ['kind' => 'sos', 't' => ($base + 70) * 1000]);
    check('…10 SOS in 10 minutes pass, the 11th is refused (429)', $okS && $code === 429);
    check('…and none of those repeats messaged anyone again', count($sent) === $sentBefore, (string) (count($sent) - $sentBefore));
    Database::run('DELETE FROM rate_limits WHERE identifier = :i', ['i' => 'drv' . $did]);

    /* ================================================================= */
    section('manifest');
    $mk = static function (string $status, string $phone, string $boarding, array $pax) use ($sid): void {
        static $n = 0; $n++;
        $bid = Database::insert('bookings', ['pnr' => DA_TAG . $n . '-' . bin2hex(random_bytes(3)), 'contact_phone' => $phone,
            'total_amount' => 2500, 'status' => $status, 'source' => 'admin']);
        $leg = Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'travel_date' => DA_DATE,
            'seat_count' => count($pax), 'boarding_stop' => $boarding, 'fare_per_seat' => 2500]);
        foreach ($pax as [$seat, $name, $boarded]) {
            Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $leg, 'passenger_ref' => 'DA' . bin2hex(random_bytes(6)),
                'seat_no' => $seat, 'full_name' => $name, 'id_type' => 'Citizenship', 'id_number' => 'IDNO-77123',
                'boarded_at' => $boarded ? date('Y-m-d H:i:s') : null]);
        }
    };
    $mk('confirmed', '9000009961', $B . ' @ 18:00 [' . $pick[1]['lat'] . ',' . $pick[1]['lng'] . ']', [['A3', 'Mr. Ram Bahadur Thapa', true], ['A4', 'Sita Thapa', false]]);
    $mk('pending', '0000000000', $A, [['B1', 'Hari Lal', false]]);
    $mk('cancelled', '9000009962', $A, [['B2', 'Gone Person', false]]);
    $mk('confirmed', '+977 9000009963', 'Unknownpur Bazaar @ 10:00', [['C1', 'Maya', false]]);
    $mf = TripEvents::manifest($sid, $routeId);
    $names = array_map(static fn($g) => $g['stop'], $mf['groups']);
    check('grouped by pickup in route order, an unknown pickup last', $names === [$A, $B, 'Unknownpur Bazaar'], json_encode($names));
    check('totals: booked 4 (cancelled left out), boarded 1, left 3', $mf['totals'] === ['booked' => 4, 'boarded' => 1, 'left' => 3], json_encode($mf['totals']));
    $g2 = $mf['groups'][1]['pax'] ?? [];
    check('seat, first name (no "Mr."), boarded tick', ($g2[0]['seat'] ?? '') === 'A3' && ($g2[0]['name'] ?? '') === 'Ram' && ($g2[0]['boarded'] ?? false) === true
        && ($g2[1]['name'] ?? '') === 'Sita' && ($g2[1]['boarded'] ?? true) === false && $mf['groups'][1]['boarded'] === 1);
    check('phone to call; a walk-in stand-in (0000000000) is not callable; a +977 number keeps its code',
        ($g2[0]['phone'] ?? '') === '9000009961' && ($mf['groups'][0]['pax'][0]['phone'] ?? 'x') === ''
        && ($mf['groups'][2]['pax'][0]['phone'] ?? '') === '+9779000009963');
    check('a pending booking is listed and flagged', ($mf['groups'][0]['pax'][0]['pending'] ?? false) === true);
    $js = (string) json_encode($mf, JSON_UNESCAPED_UNICODE);
    check('no ID numbers, no fares, no surnames', !str_contains($js, 'IDNO-77123') && !str_contains($js, '2500') && !str_contains($js, 'fare')
        && !str_contains($js, 'Thapa') && !str_contains($js, 'Gone Person'), $js);
    check('tap-to-call: the country stamped on the booking prefixes a bare number (India and Nepal share 10 digits); a trunk 0 is dropped',
        TripEvents::callable('9800009964', '+977') === '+9779800009964' && TripEvents::callable('9000009961', '91') === '+919000009961'
        && TripEvents::callable('9000009961', '') === '9000009961' && TripEvents::callable('09000009961') === '9000009961'
        && TripEvents::callable('00977 9800009964') === '+9779800009964' && TripEvents::callable('919000009961', '977') === '+919000009961'
        && TripEvents::callable('0000000000', '91') === '');
    check('firstName / stopShort helpers', TripEvents::firstName('  Smt. Gita Devi ') === 'Gita' && TripEvents::firstName('') === ''
        && TripEvents::stopShort('Surat · Departure @ 13:00 [21.17,72.83]') === 'Surat · Departure');

    /* ================================================================= */
    section('nav by position, trip info');
    Database::run('DELETE FROM driver_events WHERE live_trip_id = :t AND kind = \'stop_reached\'', ['t' => $tripId]);
    Database::update('live_trips', ['last_lat' => $pick[2]['lat'], 'last_lng' => $pick[2]['lng'], 'last_ping_at' => date('Y-m-d H:i:s')], 'id = :i', ['i' => $tripId]);
    $nav = TripEvents::nav(LiveTrips::find($tripId));
    check('a fresh fix at the third pickup: the first two are behind the coach', $nav['passedIdx'] >= $idxOf($B) && $nav['passedIdx'] < $idxOf($C)
        && $nav['next'] === $stops[$nav['passedIdx'] + 1]['name'] && $nav['stops'][0]['passed'] === true
        && $nav['stops'][$idxOf($C)]['passed'] === false, json_encode([$nav['passedIdx'], $nav['next']]));
    parse_str((string) parse_url($nav['mapsUrl'], PHP_URL_QUERY), $q);
    check('…the Maps link starts at the coach and leads through the stops ahead', ($q['origin'] ?? '') === sprintf('%.6f,%.6f', $pick[2]['lat'], $pick[2]['lng'])
        && str_contains((string) ($q['destination'] ?? '') . '|' . (string) ($q['waypoints'] ?? ''), sprintf('%.6f,%.6f', $pick[2]['lat'], $pick[2]['lng'])), $nav['mapsUrl']);
    Database::update('live_trips', ['last_ping_at' => date('Y-m-d H:i:s', time() - 3600)], 'id = :i', ['i' => $tripId]);
    check('an hour-old fix places nothing (no stop passed, no origin)', TripEvents::nav(LiveTrips::find($tripId))['passedIdx'] === -1
        && !str_contains(TripEvents::nav(LiveTrips::find($tripId))['mapsUrl'], 'origin='));
    $info = TripEvents::tripInfo(LiveTrips::find($tripId));
    check('trip info: nav, manifest, rest, recent, the trip start and the server clock', isset($info['nav']['stops'], $info['manifest']['groups'], $info['rest'])
        && count($info['recent']) > 0 && count($info['recent']) <= 6 && $info['startedTs'] > 0 && abs($info['now'] - time()) < 5);
    $cfg = TripEvents::config();
    check('crew settings: speed warning default 80 km/h, rest 4 h / 5 h, office phone to call', $cfg['speedWarnKmh'] === 80 && $cfg['restSoftMin'] === 240
        && $cfg['restHardMin'] === 300 && $cfg['actions'] === true && str_starts_with($cfg['officeTel'], '+'));
    Settings::set('driver_speed_warn_kmh', 500, 'int', 'driver');
    check('…a silly speed limit is held to 150', TripEvents::speedWarnKmh() === 150);
    Settings::set('driver_speed_warn_kmh', 80, 'int', 'driver');

    /* ================================================================= */
    section('battery');
    $now = time();
    check('a level is kept for the trip', TripEvents::noteBattery($tripId, ['pct' => 64, 'charging' => true], $now) && (TripEvents::battery($tripId)['pct'] ?? 0) === 64);
    check('…the same level again within 5 min is not written again', !TripEvents::noteBattery($tripId, ['pct' => 62, 'charging' => true], $now + 60));
    check('…charger pulled out: written', TripEvents::noteBattery($tripId, ['pct' => 62, 'charging' => false], $now + 70) && TripEvents::battery($tripId)['charging'] === false);
    check('…5 points down: written', TripEvents::noteBattery($tripId, ['pct' => 57, 'charging' => false], $now + 80));
    TripEvents::noteBattery($tripId, ['pct' => 21, 'charging' => false], $now + 400);
    check('…crossing below 20 % is written at once', TripEvents::noteBattery($tripId, ['pct' => 19, 'charging' => false], $now + 410) && TripEvents::battery($tripId)['pct'] === 19);
    check('…garbage is ignored', !TripEvents::noteBattery($tripId, ['pct' => 140], $now) && !TripEvents::noteBattery($tripId, 'x', $now) && !TripEvents::noteBattery($tripId, null, $now));

    /* ================================================================= */
    section('over HTTP: api/driver.php');
    Settings::set('driver_sos_whatsapp_on', false, 'bool', 'driver');   // the server process must never WhatsApp anyone
    Database::run("DELETE FROM driver_events WHERE live_trip_id = :t AND kind IN ('sos','breakdown')", ['t' => $tripId]);
    Database::run("DELETE FROM kv_store WHERE kscope = 'driver_battery' AND kkey = :k", ['k' => 'trip:' . $tripId]);
    $port = random_int(18100, 18900);
    $BASE = 'http://127.0.0.1:' . $port;
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $cmd  = [PHP_BINARY];
    if (($ini = php_ini_loaded_file()) !== false) { $cmd[] = '-c'; $cmd[] = $ini; }
    array_push($cmd, '-S', '127.0.0.1:' . $port, '-t', ROOT_PATH);
    $server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) { usleep(200000); $up = http($BASE . '/api/driver.php?action=me')[0] > 0; }
    check('php -S is up', $up);
    $tok = $login['token'];
    [$c, $j] = http($BASE . '/api/driver.php?action=me');
    check('no token: 401', $c === 401);
    [$c, $j] = http($BASE . '/api/driver.php?action=me', $tok);
    check('me: the running trip with its start, and the crew settings', $c === 200 && ($j['data']['trip']['tripId'] ?? 0) === $tripId
        && ($j['data']['trip']['startedTs'] ?? 0) > 0 && ($j['data']['crew']['speedWarnKmh'] ?? 0) === 80 && ($j['data']['crew']['actions'] ?? false) === true, json_encode($j));
    [$c, $j] = http($BASE . '/api/driver.php?action=trip&tripId=' . $tripId, $tok);
    check('?action=trip: stops, manifest, rest', $c === 200 && count($j['data']['nav']['stops'] ?? []) === count($stops)
        && ($j['data']['manifest']['totals']['booked'] ?? 0) === 4 && array_key_exists('resting', $j['data']['rest'] ?? []), is_array($j) ? json_encode($j['data']['manifest']['totals'] ?? $j) : (string) $j);
    $other = DriverAuth::login(DA_PHONE, '5813', 'second phone');
    [$c] = http($BASE . '/api/driver.php?action=trip&tripId=' . $tripId, $other['token']);
    check('another phone of the same driver cannot read this trip (409)', $c === 409);
    [$c, $j] = http($BASE . '/api/driver.php', $tok, ['action' => 'event', 'tripId' => $tripId, 'kind' => 'stop_reached', 'stop' => $C,
        'lat' => $pick[2]['lat'], 'lng' => $pick[2]['lng'], 't' => time() * 1000]);
    check('event stop_reached: stored, next stop back', $c === 200 && ($j['data']['event']['stop'] ?? '') === $C && array_key_exists('next', $j['data']['nav'] ?? []), json_encode($j));
    [$c, $j] = http($BASE . '/api/driver.php', $tok, ['action' => 'event', 'tripId' => $tripId, 'kind' => 'sos', 'lat' => $pick[2]['lat'], 'lng' => $pick[2]['lng']]);
    $bells[] = (int) ($j['data']['alert']['bell'] ?? 0);
    check('event sos: the bell rings, WhatsApp is off in this test', $c === 200 && ($j['data']['alert']['bell'] ?? 0) > 0 && ($j['data']['alert']['skipped'] ?? '') === 'whatsapp off', json_encode($j));
    [$c, $j] = http($BASE . '/api/driver.php', $tok, ['action' => 'event', 'tripId' => $tripId, 'kind' => 'stop_reached', 'stop' => 'Nowhere']);
    check('event with a stop off the route: 422 with a message', $c === 422 && is_string($j['error'] ?? null));
    [$c, $j] = http($BASE . '/api/driver.php', $other['token'], ['action' => 'event', 'tripId' => $tripId, 'kind' => 'sos']);
    check('event from a phone that does not run the trip: 409', $c === 409);
    [$c, $j] = http($BASE . '/api/driver.php', $tok, ['action' => 'ping', 'tripId' => $tripId,
        'points' => [['seq' => 900001, 't' => time() * 1000, 'lat' => $pick[2]['lat'], 'lng' => $pick[2]['lng'], 'acc' => 12, 'kmh' => 52]],
        'battery' => ['pct' => 17, 'charging' => false]]);
    check('ping with the battery: positions acked, battery kept', $c === 200 && ($j['data']['ack'] ?? 0) === 900001
        && (TripEvents::battery($tripId)['pct'] ?? 0) === 17, json_encode($j));
    [$c, $j] = http($BASE . '/api/driver.php', $tok, ['action' => 'ping', 'tripId' => $tripId,
        'points' => [['seq' => 900002, 't' => time() * 1000, 'lat' => $pick[2]['lat'], 'lng' => $pick[2]['lng'], 'acc' => 12]]]);
    check('ping without a battery still works (the field is optional)', $c === 200 && ($j['data']['ack'] ?? 0) === 900002);
    LiveTrips::end($tripId);
    [$c, $j] = http($BASE . '/api/driver.php', $tok, ['action' => 'event', 'tripId' => $tripId, 'kind' => 'stop_reached', 'stop' => $C]);
    check('a button on an ended trip: 409', $c === 409);
    [$c] = http($BASE . '/api/driver.php?action=trip&tripId=' . $tripId, $tok);
    check('?action=trip on an ended trip: 409 (its passengers\' names and phones are not the phone\'s any more)', $c === 409);
    [$c, $j] = http($BASE . '/api/driver.php', $tok, ['action' => 'event', 'tripId' => $tripId, 'kind' => 'breakdown',
        'note' => 'saved in a dead zone', 't' => (time() - 120) * 1000]);
    $bells[] = (int) ($j['data']['alert']['bell'] ?? 0);
    check('…but an SOS / breakdown queued before the office ended the trip still gets through and rings the bell',
        $c === 200 && ($j['data']['event']['kind'] ?? '') === 'breakdown' && ($j['data']['alert']['bell'] ?? 0) > 0, json_encode($j));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    TripEvents::$send = null;
    if ($server !== null) { proc_terminate($server); proc_close($server); }
    $restoreSettings();
    $cleanup();
    $left = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE pnr LIKE '" . DA_TAG . "%'", [], 0)
          + (int) Database::scalar('SELECT COUNT(*) FROM schedules WHERE travel_date = :d', ['d' => DA_DATE], 0)
          + (int) Database::scalar('SELECT COUNT(*) FROM drivers WHERE phone = :p', ['p' => DA_PHONE], 0)
          + ($made['trip'] > 0 ? (int) Database::scalar('SELECT COUNT(*) FROM driver_events WHERE live_trip_id = :t', ['t' => $made['trip']], 0) : 0);
    check('cleanup: no test rows left behind', $left === 0, (string) $left);
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
