<?php
/**
 * =====================================================================
 *  locations-consistency-test.php — ONE location source + per-pickup
 *  fare + ON/OFF (owner ask, 1 Oct 2026; master brief R3g).
 *
 *  1. database/upgrade-2026-10-01-stop-fares.sql applied: route_stops.fare
 *     and route_stops.is_active exist.
 *  2. Seeds: Rajkot is an ON boarding stop of the Gujarat -> Rupaidiha route
 *     at 2200; Emli/Bhupal and Chiloda/Ahmedabad pickups 2000; every other
 *     boarding stop of that route 2200.
 *  3. Boarding::locations()['india'] lists every ON pickup (Rajkot included)
 *     and drops a pickup switched OFF (then back ON).
 *  4. Fare::perSeatFor(): Chiloda 2000, Surat / Rajkot 2200, a return drop
 *     the direction default.
 *  5. HTTP (SHG_TEST_BASE): /api/search.php from Rajkot finds the bus with
 *     Rajkot's fare (2200) aligned to its stop; a town switched OFF is gone;
 *     the page boot ships Rajkot in SHG_BOOT.locations.
 *  6. BookingService::create(): Chiloda = 2000 x seats, Surat = 2200 x seats;
 *     a stop switched OFF or unknown is refused. QuickTicket quotes the same.
 *  7. HTTP admin (Admin -> Routes): the stop editor shows the fare inputs and
 *     ON/OFF, saving a fare and toggling a stop write the DB + audit, delete
 *     is refused (switch OFF instead).
 *
 *   SHG_TEST_BASE=http://localhost:8951 php tests/locations-consistency-test.php
 * Far-future throwaway schedule; restores every row and setting it touches.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/quickticket.php';

define('BASE', getenv('SHG_TEST_BASE') ?: 'http://localhost:8899');
const TD     = '2099-10-21';
const PHONE0 = '91000071';   // + 2 digits per booking

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function expectThrow(string $l, callable $fn, string $needle): void {
    try { $fn(); check($l . ' (expected a refusal)', false); }
    catch (Throwable $e) { check($l, stripos($e->getMessage(), $needle) !== false, $e->getMessage()); }
}
function http(string $method, string $url, ?array $form = null, ?string $jar = null): array {
    $ch = curl_init(BASE . $url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 60];
    if ($jar !== null) { $opt[CURLOPT_COOKIEJAR] = $jar; $opt[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $opt);
    if ($form !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

/* ---- settings pinned so 2099 is bookable; put back exactly as found ---- */
$PINNED = ['booking_horizon_days', 'daily_service_on'];
$prior  = [];
foreach ($PINNED as $k) {
    $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$restoreSettings = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        try {
            if ($row === null) {
                Database::delete('settings', 'skey = :k', ['k' => $k]);
            } else {
                Database::update('settings', ['svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'],
                    'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public']], 'skey = :k', ['k' => $k]);
            }
        } catch (Throwable $e) {}
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};

$cleanup = static function (int $routeId): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . PHONE0 . "%'") as $r) {
        try { Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        try { Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]); } catch (Throwable $e) {}
    }
    if ($routeId > 0) {
        foreach (Database::fetchAll('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d', ['r' => $routeId, 'd' => TD]) as $s) {
            $sid = (int) $s['id'];
            try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
            try { Database::delete('seat_locks', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
            try { Database::delete('booking_legs', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
            try { Database::delete('schedules', 'id = :s', ['s' => $sid]); } catch (Throwable $e) {}
        }
    }
};

echo "\n=== 1. Migration applied ===\n";
$cols = array_map(static fn(array $r): string => (string) $r['COLUMN_NAME'], Database::fetchAll(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'route_stops' AND COLUMN_NAME IN ('fare','is_active')"
));
check('route_stops.fare exists', in_array('fare', $cols, true));
check('route_stops.is_active exists', in_array('is_active', $cols, true));
check('Boarding::hasStopControls() sees both', Boarding::hasStopControls());
if (!Boarding::hasStopControls()) {
    echo "\n  Run: php tests/apply-sql.php database/upgrade-2026-10-01-stop-fares.sql\n\n  $PASS passed, $FAIL failed\n";
    exit(1);
}

/* The Gujarat -> Rupaidiha route and the return route. */
$out = $ret = null;
foreach (Database::fetchAll('SELECT * FROM routes WHERE is_active = 1 ORDER BY sort_order, id') as $r) {
    if ($out === null && Boarding::routeDirection($r) === 'toNepal') { $out = $r; }
    if ($ret === null && Boarding::routeDirection($r) === 'toIndia') { $ret = $r; }
}
check('an active Gujarat -> Nepal route exists', $out !== null);
if ($out === null) { echo "\n  $PASS passed, $FAIL failed\n"; exit(1); }
$outId = (int) $out['id'];

$rows   = Boarding::allStopsFor($outId, 'boarding');
$lower  = static fn(array $s): string => mb_strtolower($s['name']);
$is2000 = static fn(array $s): bool => preg_match('/emli|bhupal|bhopal|chiloda|hari parking|ahmedabad/u', mb_strtolower($s['name'])) === 1;
$find   = static function (string $re) use ($rows): ?array {
    foreach ($rows as $s) { if (preg_match($re, mb_strtolower($s['name'])) === 1) { return $s; } }
    return null;
};
$rajkot  = $find('/^rajkot/u');
$surat   = $find('/^surat/u');
$chiloda = $find('/chiloda|hari parking|ahmedabad/u');
$emli    = $find('/emli|bhupal|bhopal/u');
$target  = null;   // an ordinary pickup to switch OFF and back
foreach ($rows as $s) {
    if ($s['active'] && !$is2000($s) && preg_match('/^(rajkot|surat)/u', $lower($s)) !== 1) { $target = $s; break; }
}

echo "\n=== 2. Seeded fares + Rajkot ===\n";
check('Rajkot is a boarding stop of ' . $out['route_code'], $rajkot !== null);
check('Rajkot is ON with fare 2200', $rajkot !== null && $rajkot['active'] && (int) $rajkot['fare'] === 2200, json_encode($rajkot));
// 2 Oct 2026 (lead): Rajkot is appended LAST so the default pickup and the picker's first town stay Surat.
check('Rajkot is added after the existing pickups (Surat stays first)', $rajkot !== null && $surat !== null && $rajkot['order'] > $surat['order']);
check('Emli / Bhupal pickup fare 2000', $emli !== null && (int) $emli['fare'] === 2000, json_encode($emli, JSON_UNESCAPED_UNICODE));
check('Chiloda / Ahmedabad pickup fare 2000', $chiloda !== null && (int) $chiloda['fare'] === 2000, json_encode($chiloda, JSON_UNESCAPED_UNICODE));
$wrong = [];
foreach ($rows as $s) {
    $want = $is2000($s) ? 2000 : 2200;
    if ((int) round((float) ($s['fare'] ?? 0)) !== $want) { $wrong[] = $s['name'] . '=' . var_export($s['fare'], true); }
}
check('every other boarding stop of the route is 2200 (and the two cheap ones 2000)', $wrong === [], implode(', ', $wrong));
if ($ret !== null) {
    $retDrops = Boarding::allStopsFor((int) $ret['id'], 'drop');
    check('return-route drops keep no fare of their own (direction default)', array_filter($retDrops, static fn(array $s): bool => $s['fare'] !== null) === []);
}

echo "\n=== 3. Boarding::locations() is the one list ===\n";
Boarding::flush();
$loc = Boarding::locations();
$keys = array_map([Boarding::class, 'townKey'], $loc['india']);
$missing = [];
foreach ($rows as $s) {
    if ($s['active'] && !in_array(Boarding::townKey($s['name']), $keys, true)) { $missing[] = $s['name']; }
}
check('india lists every ON boarding town of the outbound route', $missing === [], implode(', ', $missing));
check('india includes Rajkot', in_array('rajkot', $keys, true));
check('nepal side is Rupaidiha', in_array('rupaidiha', array_map([Boarding::class, 'townKey'], $loc['nepal']), true), implode(',', $loc['nepal']));
check('Fare::mainPoints()[india] = Boarding::locations()[india] (no main_points override set)',
    Settings::getArray('main_points', []) !== [] || Fare::mainPoints()['india'] === $loc['india']);
$goRaj = null;
foreach ($loc['go'] as $g) { if (Boarding::townKey($g['name']) === 'rajkot') { $goRaj = $g; } }
check('go list carries Rajkot with fare 2200 and no time', $goRaj !== null && (int) $goRaj['fare'] === 2200 && $goRaj['time'] === null, json_encode($goRaj));

$targetId = $target !== null ? (int) $target['id'] : 0;
$restoreTarget = static function () use ($targetId): void {
    if ($targetId > 0) { Database::update('route_stops', ['is_active' => 1], 'id = :id', ['id' => $targetId]); Boarding::flush(); }
};
if ($target === null) {
    check('an ordinary pickup exists to switch OFF', false);
} else {
    Database::update('route_stops', ['is_active' => 0], 'id = :id', ['id' => $targetId]);
    Boarding::flush();
    $k = Boarding::townKey($target['name']);
    $goKeys = static fn(): array => array_map(static fn(array $g): string => Boarding::townKey($g['name']), Boarding::locations()['go']);
    check('switched OFF: ' . $target['name'] . ' leaves the pickup list (locations()[go])', !in_array($k, $goKeys(), true));
    check('switched OFF: gone from stopsFor() (search / counter / QuickTicket list)', array_filter(Boarding::stopsFor($outId), static fn(array $s): bool => Boarding::townKey($s['name']) === $k) === []);
    check('switched OFF: servesTown() false', !Boarding::servesTown($outId, 'boarding', $target['name']));
    check('switched OFF: labelStatus() = off', Boarding::labelStatus($out, $target['name'], 'boarding') === 'off');
    check('switched OFF: allStopsFor() still has the row (display of old bookings)', array_filter(Boarding::allStopsFor($outId, 'boarding'), static fn(array $s): bool => $s['id'] === $targetId) !== []);
    $restoreTarget();
    check('switched back ON: in the pickup list again', in_array($k, $goKeys(), true));
}

echo "\n=== 4. Fare::perSeatFor() ===\n";
$dir = Fare::dirFares();
if ($chiloda !== null) { check('Chiloda boarding = 2000', (int) Fare::perSeatFor($out, $chiloda['name'] . ' @ 21:00', 'Rupaidiha') === 2000); }
if ($surat !== null)   { check('Surat boarding = 2200', (int) Fare::perSeatFor($out, 'Surat', 'Rupaidiha') === 2200); }
check('Rajkot boarding = 2200', (int) Fare::perSeatFor($out, 'Rajkot · Rajkot office pickup', 'Rupaidiha') === 2200);
check('an "Other" pickup = the direction default', (int) Fare::perSeatFor($out, '__other__', '') === (int) $dir['toNepal']);
if ($ret !== null) {
    $retDrop = (Boarding::allStopsFor((int) $ret['id'], 'drop')[0]['name'] ?? (string) $ret['to_city']);
    check('a return drop (' . $retDrop . ') = the direction default ' . (int) $dir['toIndia'], (int) Fare::perSeatFor($ret, 'Rupaidiha', $retDrop) === (int) $dir['toIndia']);
}

echo "\n=== 5. HTTP: search + page boot ===\n";
if (!function_exists('curl_init')) {
    check('curl extension loaded', false);
} else {
    try { Database::query('DELETE FROM rate_limits'); } catch (Throwable $e) {}
    $date = addDaysISO(todayISO(), 3);
    $s = json_decode(http('GET', '/api/search.php?from=Rajkot&to=Rupaidiha&date=' . $date)['body'], true);
    $res = $s['data']['results'][0] ?? null;
    check('search Rajkot -> Rupaidiha finds a bus', (int) ($s['data']['count'] ?? 0) >= 1, 'count=' . ($s['data']['count'] ?? '?'));
    $ri = -1;
    foreach ((array) ($res['boarding'] ?? []) as $i => $lbl) { if (Boarding::townKey((string) $lbl) === 'rajkot') { $ri = $i; break; } }
    check('results[0].boarding contains Rajkot', $ri >= 0);
    check('boardingFares[Rajkot] = 2200', $ri >= 0 && (int) ($res['boardingFares'][$ri] ?? 0) === 2200, json_encode($res['boardingFares'] ?? null));
    check('boardingIdx points at Rajkot', $ri >= 0 && (int) ($res['boardingIdx'] ?? -1) === $ri);
    check('fareDefault / fareFrom present', isset($res['fareDefault'], $res['fareFrom']) && (int) $res['fareFrom'] === 2000, json_encode([$res['fareDefault'] ?? null, $res['fareFrom'] ?? null]));
    check('boardingFares aligned with boarding', count((array) ($res['boardingFares'] ?? [])) === count((array) ($res['boarding'] ?? [])));

    if ($target !== null) {
        Database::update('route_stops', ['is_active' => 0], 'id = :id', ['id' => $targetId]);
        $s2 = json_decode(http('GET', '/api/search.php?from=' . rawurlencode($target['name']) . '&to=Rupaidiha&date=' . $date)['body'], true);
        $still = false;
        foreach ((array) ($s2['data']['results'] ?? []) as $r2) {
            foreach ((array) ($r2['boarding'] ?? []) as $lbl) { if (Boarding::townKey((string) $lbl) === Boarding::townKey($target['name'])) { $still = true; } }
        }
        check('a pickup switched OFF (' . $target['name'] . ') is absent from search', !$still, 'count=' . ($s2['data']['count'] ?? '?'));
        $restoreTarget();
    }
    $home = http('GET', '/')['body'];
    check('page boot ships SHG_BOOT.locations with Rajkot', preg_match('/"locations":\{"india":\[[^\]]*"Rajkot"/', $home) === 1);
}

echo "\n=== 6. BookingService::create() charges the pickup fare ===\n";
Settings::set('booking_horizon_days', 40000, 'int', 'booking', true);
Settings::set('daily_service_on', true, 'bool', 'booking', true);
Settings::flush();
$cleanup($outId);
try {
    $sched = Seats::schedule($outId, TD);
    $free  = array_values(Seats::availability($outId, TD, 'sharing', (int) $sched['id'])['available'] ?? []);
    check('7+ free sharing berths on ' . TD, count($free) >= 7, (string) count($free));
    $n = 0;
    $book = static function (string $boarding, array $seats) use ($outId, &$n): array {
        $n++;
        $suffix = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        $pax = [];
        foreach ($seats as $i => $seat) { $pax[] = ['seat' => $seat, 'name' => 'Loc Test ' . $suffix . '-' . $i, 'age' => 30, 'gender' => 'Female']; }
        return BookingService::create([
            'routeId' => $outId, 'travelDate' => TD, 'seats' => $seats, 'passengers' => $pax,
            'contact' => ['phone' => PHONE0 . $suffix, 'email' => '', 'idType' => '', 'idNum' => ''],
            'bookingMode' => 'sharing', 'cabinType' => 'single', 'paymentMethod' => 'cod', 'isCod' => true,
            'boarding' => $boarding, 'drop' => '', 'originTown' => '', 'couponCode' => '',
        ]);
    };
    $row = static fn(array $b): ?array => Database::fetch('SELECT fare_per_seat, base_total FROM bookings WHERE pnr = :p', ['p' => (string) $b['pnr']]);
    if ($chiloda !== null) {
        $b = $row($book($chiloda['name'], [$free[0], $free[1]]));
        check('S Hari Parking / Chiloda x2 = 2000 x 2', $b !== null && (int) $b['fare_per_seat'] === 2000 && (int) $b['base_total'] === 4000, json_encode($b));
    }
    if ($surat !== null) {
        $b = $row($book('Surat', [$free[2], $free[3]]));
        check('Surat x2 = 2200 x 2', $b !== null && (int) $b['fare_per_seat'] === 2200 && (int) $b['base_total'] === 4400, json_encode($b));
    }
    $b = $row($book('Rajkot · Rajkot office pickup (+91 94268 27507)', [$free[4]]));
    check('Rajkot x1 = 2200 (canonical label from search)', $b !== null && (int) $b['fare_per_seat'] === 2200 && (int) $b['base_total'] === 2200, json_encode($b));
    if ($target !== null) {
        Database::update('route_stops', ['is_active' => 0], 'id = :id', ['id' => $targetId]);
        Boarding::flush();
        expectThrow('a pickup switched OFF is refused', fn() => $book($target['name'], [$free[5]]), 'not available');
        $restoreTarget();
    }
    expectThrow('an unknown pickup is refused', fn() => $book('Timbuktu Bus Stand', [$free[5]]), 'not available');

    $plan = QuickTicket::plan(['date' => TD, 'direction' => 'toNepal', 'boarding' => 'Rajkot', 'seats' => 1]);
    check('QuickTicket quotes Rajkot at 2200 (same as the sale)', (int) round((float) ($plan['fare']['perSeat'] ?? 0)) === 2200, json_encode($plan['fare'] ?? null));
    if ($chiloda !== null) {
        $plan = QuickTicket::plan(['date' => TD, 'direction' => 'toNepal', 'boarding' => $chiloda['name'], 'seats' => 1]);
        check('QuickTicket quotes Chiloda at 2000', (int) round((float) ($plan['fare']['perSeat'] ?? 0)) === 2000, json_encode($plan['fare'] ?? null));
    }
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    $restoreTarget();
    $cleanup($outId);
    $restoreSettings();
}

echo "\n=== 7. HTTP: Admin -> Routes stop editor ===\n";
if (function_exists('curl_init') && $surat !== null && $target !== null) {
    $jar  = sys_get_temp_dir() . '/shg_loc_' . getmypid() . '.cookies'; @unlink($jar);
    $user = 'loc-super'; $pw = 'Loc@12345';
    $csrfOf = static fn(string $h): string => preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $h, $m) ? $m[1] : '';
    try { Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
    $hash = password_hash($pw, PASSWORD_BCRYPT);
    $aid  = Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $user], 0);
    if ($aid) {
        Database::query("UPDATE admins SET password_hash=:h, role='superadmin', is_active=1, must_change_pw=0, failed_logins=0, locked_until=NULL WHERE id=:i", ['h' => $hash, 'i' => $aid]);
    } else {
        Database::query("INSERT INTO admins (username, password_hash, full_name, role, is_active, must_change_pw) VALUES (:u, :h, 'Locations Test', 'superadmin', 1, 0)", ['u' => $user, 'h' => $hash]);
    }
    try {
        $lp = http('GET', '/admin/login.php', null, $jar);
        $li = http('POST', '/admin/login.php', ['shg_csrf' => $csrfOf($lp['body']), 'username' => $user, 'password' => $pw], $jar);
        check('superadmin signs in', $li['code'] === 302, 'HTTP ' . $li['code']);
        $pg = http('GET', '/admin/routes.php', null, $jar);
        check('routes page renders with the Pickup points & fares section', $pg['code'] === 200 && str_contains($pg['body'], 'id="pickups"'), 'HTTP ' . $pg['code']);
        check('a fare input per priced pickup (Surat)', str_contains($pg['body'], 'name="fare[' . (int) $surat['id'] . ']"'));
        check('ON/OFF toggle rendered', str_contains($pg['body'], 'name="action" value="toggle_stop"'));
        check('delete button no longer offered', !str_contains($pg['body'], 'value="delete_stop"'));

        $before = (float) $surat['fare'];
        $sv = http('POST', '/admin/routes.php', ['shg_csrf' => $csrfOf($pg['body']), 'action' => 'save_stop_fares',
            'route_id' => $outId, 'stop_type' => 'boarding', 'fare' => [(int) $surat['id'] => '2300']], $jar);
        $now = Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => (int) $surat['id']], null);
        check('Save pickup fares writes Surat = 2300', (int) (float) $now === 2300, 'db=' . var_export($now, true) . ' HTTP ' . $sv['code']);
        check('audit row written for the fare change', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'route_stop.fares' AND created_at > (NOW() - INTERVAL 5 MINUTE)", [], 0) >= 1);
        $pg2 = http('GET', '/admin/routes.php', null, $jar);
        http('POST', '/admin/routes.php', ['shg_csrf' => $csrfOf($pg2['body']), 'action' => 'save_stop_fares',
            'route_id' => $outId, 'stop_type' => 'boarding', 'fare' => [(int) $surat['id'] => (string) (int) $before]], $jar);
        check('fare restored via the form', (int) (float) Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => (int) $surat['id']], 0) === (int) $before);

        $pg3 = http('GET', '/admin/routes.php', null, $jar);
        http('POST', '/admin/routes.php', ['shg_csrf' => $csrfOf($pg3['body']), 'action' => 'toggle_stop', 'stop_id' => $targetId], $jar);
        check('toggle_stop switches ' . $target['name'] . ' OFF', (int) Database::scalar('SELECT is_active FROM route_stops WHERE id = :i', ['i' => $targetId], 1) === 0);
        $pg4 = http('GET', '/admin/routes.php', null, $jar);
        http('POST', '/admin/routes.php', ['shg_csrf' => $csrfOf($pg4['body']), 'action' => 'toggle_stop', 'stop_id' => $targetId], $jar);
        check('toggle_stop switches it back ON', (int) Database::scalar('SELECT is_active FROM route_stops WHERE id = :i', ['i' => $targetId], 0) === 1);

        $pg5 = http('GET', '/admin/routes.php', null, $jar);
        $del = http('POST', '/admin/routes.php', ['shg_csrf' => $csrfOf($pg5['body']), 'action' => 'delete_stop', 'stop_id' => $targetId], $jar);
        check('delete_stop is refused (switch OFF instead) and the row stays',
            Database::fetch('SELECT id FROM route_stops WHERE id = :i', ['i' => $targetId]) !== null && str_contains($del['body'], 'switch'));

        $st = http('GET', '/admin/settings.php', null, $jar);
        check('Settings fares panel links to the pickup editor', str_contains($st['body'], 'routes.php#pickups'));
    } catch (Throwable $e) {
        check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        Database::update('route_stops', ['is_active' => 1], 'id = :id', ['id' => $targetId]);
        Database::update('route_stops', ['fare' => $surat['fare']], 'id = :id', ['id' => (int) $surat['id']]);
        try { Database::query('DELETE FROM admins WHERE username = :u', ['u' => $user]); } catch (Throwable $e) {}
        @unlink($jar);
    }
} else {
    echo "  SKIP  curl missing or no Surat / ordinary pickup on the route\n";
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
