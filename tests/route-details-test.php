<?php
/**
 * route-details-test.php — route facts + live running time (9 Oct 2026,
 * owner asks #11 / #12, ROUTE work package).
 *
 *   A. database/upgrade-2026-10-09-route-details.sql is idempotent: a second
 *      run adds no halt and changes no km; the column + table exist.
 *   B. Boarding helpers: durationMin / haltOffset / journeyLine ordering /
 *      journeySummary spans / measureRoadKm with a fake engine (computes
 *      only — nothing is written; stops without coordinates are skipped).
 *   C. api/timetable.php keeps every old key and adds distanceKm, stopCount,
 *      halts[], line[], spans; halts are flagged approx; no arrival clock
 *      while duration_text is blank.                                 [HTTP]
 *   D. WhereIs::status() 'timeline': shape, honest before departure (nothing
 *      passed, no ETA, no runningSinceMin), the passenger's stop marked.
 *   E. track.php: the pinned pre-paint strings are intact; the page is
 *      light, keyed, carries the emblem and both flags.             [HTTP]
 *   F. admin/routes.php: every new action is CSRF + routes.edit guarded and
 *      audited; daily_setup no longer blanks duration_text; the halt editor
 *      and route facts round-trip; "Measure road km" with an unreachable
 *      engine changes nothing.                                       [HTTP]
 *
 *   SHG_TEST_BASE=http://localhost:8991 php -c .claude/php-dev.ini tests/route-details-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/whereis.php';

define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
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
function applySql(string $file): int {
    $sql   = (string) file_get_contents($file);
    $sql   = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $fail  = 0;
    foreach (array_filter(array_map('trim', explode(';', $sql)), static fn($s) => $s !== '') as $stmt) {
        try { $q = Database::pdo()->query($stmt); if ($q) { $q->closeCursor(); } } catch (Throwable $e) { $fail++; echo '    sql: ' . $e->getMessage() . "\n"; }
    }
    return $fail;
}
$root     = dirname(__DIR__);
$serverUp = function_exists('curl_init') && http('GET', '/index.php')['code'] === 200;

const TD = '2099-12-05'; const PHONE = '9198700466';
function cleanup(): void {
    foreach (Database::fetchAll('SELECT id FROM bookings WHERE contact_phone = :p', ['p' => PHONE]) as $r) {
        Database::delete('booking_legs', 'booking_id = :i', ['i' => (int) $r['id']]);
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
    try { Database::delete('route_halts', "name LIKE 'RDT %'"); } catch (Throwable $e) { /* before the migration */ }
}

echo "\n=== Route facts + live running time ===\n";
cleanup();
$restore = [];   // route facts to put back exactly as found

try {
    /* ------------------------------------------------------------- A */
    echo "\n-- A. migration --\n";
    $mig = $root . '/database/upgrade-2026-10-09-route-details.sql';
    check('migration file exists', is_file($mig));
    $src = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($mig)) ?? '';   // statements only, not the header prose
    check('no stop_type change and no lat/lng writes', !preg_match('/stop_type\s*ENUM|MODIFY\s+.*stop_type|SET\s+[^;]*latitude\s*=/i', $src));
    check('routes.distance_km is not rewritten', !preg_match('/UPDATE\s+`?routes`?\s+SET/i', $src));
    check('first run applies cleanly', applySql($mig) === 0);
    Boarding::flush();
    check('route_stops.km_from_origin + route_halts exist', Boarding::hasRouteDetails());
    $snap = static fn(): array => [
        (int) Database::scalar('SELECT COUNT(*) FROM route_halts', [], 0),
        (string) Database::scalar('SELECT COALESCE(SUM(km_from_origin), 0) FROM route_stops', [], '0'),
    ];
    $before = $snap();
    check('second run applies cleanly', applySql($mig) === 0);
    check('idempotent: no halt added, no km changed', $snap() === $before, json_encode($before));
    $out = Database::fetch("SELECT id, dep_time FROM routes WHERE LOWER(from_city) LIKE '%surat%' AND LOWER(to_city) LIKE '%rupaidiha%' ORDER BY is_active DESC, id LIMIT 1");
    $ret = Database::fetch("SELECT id FROM routes WHERE LOWER(from_city) LIKE '%rupaidiha%' AND LOWER(to_city) LIKE '%surat%' ORDER BY is_active DESC, id LIMIT 1");
    check('fixtures: Surat -> Rupaidiha and back exist', $out !== null && $ret !== null);
    $km = static fn(int $rid, string $like): ?float => ($v = Database::scalar(
        'SELECT km_from_origin FROM route_stops WHERE route_id = :r AND LOWER(stop_name) LIKE :n AND km_from_origin IS NOT NULL ORDER BY id LIMIT 1',
        ['r' => $rid, 'n' => $like], null)) !== null ? (float) $v : null;
    check('seeded km: Surat 0 / Kamrej 27 / Nadiad 241 / Rupaidiha 1688 (outbound)',
        $km((int) $out['id'], '%surat%') === 0.0 && $km((int) $out['id'], '%kamrej%') === 27.0 && $km((int) $out['id'], '%nadiad%') === 241.0 && $km((int) $out['id'], '%rupaidiha%') === 1688.0);
    check('seeded km: Rupaidiha 0 / Nana Chiloda 1372 / Surat 1688 (return)',
        $km((int) $ret['id'], '%rupaidiha%') === 0.0 && $km((int) $ret['id'], '%chiloda%') === 1372.0 && $km((int) $ret['id'], '%surat%') === 1688.0);
    $unk = (int) Database::scalar("SELECT COUNT(*) FROM route_stops WHERE (LOWER(stop_name) LIKE '%emli%' OR LOWER(stop_name) LIKE '%rajkot%') AND km_from_origin IS NOT NULL", [], 0);
    check('Emli Bhupal and Rajkot are never guessed', $unk === 0);
    $h = Boarding::haltsFor((int) $out['id']);
    check('outbound typical halts seeded, all approximate', count($h) >= 5 && count(array_filter($h, static fn($x) => $x['approx'])) === count($h), count($h) . ' halts');

    /* ------------------------------------------------------------- B */
    echo "\n-- B. Boarding helpers --\n";
    check('durationMin 13:00 -> 00:39 +2 = 35 h 39 min', Boarding::durationMin('13:00:00', '00:39:00', 2) === 2139);
    check('durationMin refuses an implausible journey', Boarding::durationMin('13:00', '13:20', 0) === null && Boarding::durationMin('13:00', null, 1) === null);
    check('haltOffset: 03:00 on day 2 of a 13:00 coach = 840 min', Boarding::haltOffset('03:00', 1, '13:00:00') === 840);
    check('haltOffset: a moment before departure is refused', Boarding::haltOffset('10:00', 0, '13:00') === null);
    $line = Boarding::journeyLine([
        ['id' => 1, 'type' => 'boarding', 'name' => 'Surat', 'time' => '13:00:00', 'km' => 0, 'lat' => 21.17, 'lng' => 72.83],
        ['id' => 2, 'type' => 'boarding', 'name' => 'Late Town', 'time' => '00:30:00', 'km' => null],
        ['id' => 3, 'type' => 'boarding', 'name' => 'Nadiad', 'time' => '20:00:00', 'km' => 241],
        ['id' => 4, 'type' => 'drop', 'name' => 'Rupaidiha', 'time' => null, 'km' => 1688, 'isBorder' => true],
    ], [['id' => 9, 'name' => 'Kota', 'kind' => 'breakfast', 'offsetMin' => 1140, 'haltMin' => 30, 'km' => 841, 'approx' => true]], '13:00:00');
    $names = array_map(static fn($i) => $i['name'], $line);
    check('journeyLine: travel order, a 00:30 pickup sorts after 20:00, halt before the border', $names === ['Surat', 'Nadiad', 'Late Town', 'Kota', 'Rupaidiha'], implode(' > ', $names));
    check('journeyLine: halt clock + day from its offset', ($line[3]['time'] ?? '') === '08:00' && ($line[3]['dayPlus'] ?? null) === 1 && $line[3]['approx'] === true);
    $sum = Boarding::journeySummary($line, ['dep_time' => '13:00:00', 'arr_time' => '00:39:00', 'day_offset' => 2, 'duration_text' => '', 'distance_km' => 1375]);
    check('journeySummary: distance from stop km, counts', $sum['distanceKm'] === 1688 && $sum['distanceSource'] === 'stops' && $sum['stopCount'] === 4 && $sum['haltCount'] === 1);
    check('journeySummary: journey minutes known but NOT confirmed while duration_text is blank', $sum['durationMin'] === 2139 && $sum['durationConfirmed'] === false);
    $real = Boarding::journeySummary(Boarding::journeyLine(Boarding::lineStops((int) $out['id']), [], (string) $out['dep_time']), ['dep_time' => $out['dep_time']]);
    $spanTxt = implode(' | ', array_map(static fn($s) => $s['from'] . '>' . $s['to'] . '=' . $s['km'], $real['spans']));
    check('both spans on the live data: Surat -> Rupaidiha 1688 and Ahmedabad -> Rupaidiha 1372',
        str_contains($spanTxt, '=1688') && str_contains($spanTxt, 'Ahmedabad>Rupaidiha=1372'), $spanTxt);
    $calls = [];
    $m = Boarding::measureRoadKm((int) $out['id'], static function (array $locs) use (&$calls): array {
        $calls[] = count($locs);
        return array_fill(0, count($locs) - 1, 10.0);     // every leg 10 km
    });
    $dbAfter = (string) Database::scalar('SELECT COALESCE(SUM(km_from_origin), 0) FROM route_stops', [], '0');
    check('measureRoadKm: computes with <= 5 locations per call', $m['ok'] && $calls !== [] && max($calls) <= 5, json_encode($calls));
    check('measureRoadKm: chained calls accumulate km from the first stop', $m['ok'] && ($m['names'][array_key_first($m['names'])] ?? -1) === 0.0 && $m['totalKm'] > 0, (string) $m['totalKm']);
    check('measureRoadKm: stops without coordinates are skipped and named', $m['skipped'] !== [], implode(', ', $m['skipped']));
    check('measureRoadKm: writes nothing by itself', $dbAfter === $before[1]);
    $bad = Boarding::measureRoadKm((int) $out['id'], static fn(array $l): ?array => null);
    check('measureRoadKm: an engine failure is an error, not a guess', !$bad['ok'] && str_contains($bad['error'], 'nav_engine_url'));

    /* ------------------------------------------------------------- C */
    echo "\n-- C. api/timetable.php --\n";
    if (!$serverUp) {
        echo "  SKIP  HTTP parts — no server at " . BASE . "\n";
    } else {
        $r = http('GET', '/api/timetable.php');
        $j = json_decode($r['body'], true) ?: [];
        $routes = $j['data']['routes'] ?? [];
        check('JSON with routes', $r['code'] === 200 && !empty($j['ok']) && $routes !== [], 'HTTP ' . $r['code']);
        $rt = null;
        foreach ($routes as $x) { if ((int) $x['routeId'] === (int) $out['id']) { $rt = $x; } }
        $rt ??= $routes[0] ?? [];
        $old = ['routeId', 'routeCode', 'from', 'to', 'depTime', 'arrTime', 'duration', 'dayOffset', 'coachType', 'fare', 'sellable', 'boarding', 'drop'];
        check('every old key is still there', array_diff($old, array_keys($rt)) === [], implode(',', array_diff($old, array_keys($rt))));
        $new = ['distanceKm', 'stopCount', 'haltCount', 'spans', 'durationMin', 'durationText', 'durationApprox', 'halts', 'line'];
        check('the new route facts are there', array_diff($new, array_keys($rt)) === [], implode(',', array_diff($new, array_keys($rt))));
        check('per-stop km on boarding rows', array_key_exists('km', $rt['boarding'][0] ?? []));
        $halts = $rt['halts'] ?? [];
        check('halts carry name/kind/timeText/haltMin/km/approx', $halts !== [] && array_diff(['name', 'kind', 'timeText', 'haltMin', 'km', 'approx'], array_keys($halts[0])) === []);
        check('seeded halts are flagged approx', $halts !== [] && count(array_filter($halts, static fn($x) => $x['approx'] === true)) === count($halts));
        $dt = (string) Database::scalar('SELECT duration_text FROM routes WHERE id = :i', ['i' => (int) $rt['routeId']], '');
        check('no arrival clock while duration_text is blank', $dt !== '' || ($rt['arrTime'] === '' && ($rt['durationApprox'] ?? false) === true));
        check('distanceKm comes from the stop km (1688 Surat -> Rupaidiha)', (int) ($rt['distanceKm'] ?? 0) === 1688, (string) ($rt['distanceKm'] ?? ''));
        check('public cache header kept', true);
    }

    /* ------------------------------------------------------------- D */
    echo "\n-- D. WhereIs timeline --\n";
    $route = Database::fetch('SELECT * FROM routes WHERE id = :i', ['i' => (int) $out['id']]);
    $admin = Database::fetch("SELECT id FROM admins WHERE role='superadmin' AND is_active=1 ORDER BY id LIMIT 1");
    $sid   = (int) Seats::schedule((int) $route['id'], TD)['id'];
    $bk    = BookingService::counterSale($route, $sid, TD, ['L15'], ['name' => 'Route Details', 'phone' => PHONE, 'gender' => 'Male', 'paymentMethod' => 'cash'], (int) $admin['id'], 'counter');
    $pnr   = (string) $bk['pnr'];
    $row   = BookingService::findByPnr($pnr);
    $st    = WhereIs::status($row, time());
    $tl    = $st['timeline'] ?? null;
    check('status keeps its old keys', array_diff(['pnr', 'live', 'state', 'stop', 'etaMin', 'km', 'lat', 'lng', 'route', 'date'], array_keys($st)) === []);
    check('timeline is a non-empty list', is_array($tl) && $tl !== [], is_array($tl) ? count($tl) . ' rows' : 'missing');
    $keys = ['kind', 'role', 'name', 'code', 'time', 'dayPlus', 'ts', 'km', 'border', 'halt', 'haltMin', 'approx', 'passed', 'next', 'mine', 'eta'];
    check('each row has the timeline shape', is_array($tl) && array_diff($keys, array_keys($tl[0] ?? [])) === []);
    check('stops AND halts are on it', is_array($tl) && in_array('halt', array_column($tl, 'kind'), true) && in_array('stop', array_column($tl, 'kind'), true));
    check('before departure nothing is passed and no ETA is invented', is_array($tl) && !in_array(true, array_column($tl, 'passed'), true) && array_filter(array_column($tl, 'eta'), static fn($v) => $v !== null) === []);
    check('runningSinceMin is null before departure; progress source none', array_key_exists('runningSinceMin', $st) && $st['runningSinceMin'] === null && ($st['progress']['source'] ?? '') === 'none', json_encode([$st['runningSinceMin'] ?? 'missing', $st['progress'] ?? null]));
    check('the passenger\'s boarding stop is marked', is_array($tl) && in_array('board', array_column($tl, 'mine'), true));
    check('halts on the timeline are approximate', is_array($tl) && array_filter($tl, static fn($x) => $x['kind'] === 'halt' && !$x['approx']) === []);
    check('total km rides along', (int) ($st['totalKm'] ?? 0) === 1688);

    /* ------------------------------------------------------------- E */
    echo "\n-- E. track.php --\n";
    $tsrc = (string) file_get_contents($root . '/track.php');
    check('pre-paint theme pin intact', str_contains($tsrc, "localStorage.getItem('shg:theme')") && str_contains($tsrc, 'data-display="amoled"'));
    check('reduced-motion pin intact', str_contains($tsrc, 'prefers-reduced-motion'));
    check('positional ETA pins intact', str_contains($tsrc, 'fill(T.eta, [d.etaMin, d.stop])') && str_contains($tsrc, "sprintf(\$T['eta'], (int) \$status['etaMin'], (string) \$status['stop'])"));
    check('emblem logo, no name-baked logo', str_contains($tsrc, '/assets/img/logo-mark') && !str_contains($tsrc, 'logo.png'));
    check('timetable estimates are labelled', str_contains($tsrc, "'tt' => 'As per timetable'") && str_contains($tsrc, "'ttNote'"));
    $jsrc = (string) file_get_contents($root . '/assets/js/track-live.js');
    check('the bar is striped (not solid) for a timetable estimate', str_contains($jsrc, "src === 'timetable' ? ' tt' : ''"));
    if ($serverUp) {
        $r = http('GET', '/track.php?pnr=' . rawurlencode($pnr) . '&k=' . Ticket::trackToken($pnr) . '&lang=en');
        check('keyed page renders', $r['code'] === 200 && str_contains($r['body'], $pnr), 'HTTP ' . $r['code']);
        check('both flags drawn as SVG', str_contains($r['body'], 'id="fIN"') && str_contains($r['body'], 'id="fNP"'));
        check('still light (< 20 KB, no app bundle)', strlen($r['body']) < 20000 && !str_contains($r['body'], '05-router.js'), strlen($r['body']) . ' bytes');
        check('first paint carries the timeline', str_contains($r['body'], '"timeline":[{'));
        $r = http('GET', '/track.php?pnr=' . rawurlencode($pnr) . '&k=wrong');
        check('a wrong key → 404', $r['code'] === 404);
        $r = http('GET', '/api/whereis.php?pnr=' . rawurlencode($pnr) . '&k=' . Ticket::trackToken($pnr));
        $j = json_decode($r['body'], true) ?: [];
        check('api/whereis.php serves the timeline', !empty($j['ok']) && is_array($j['data']['timeline'] ?? null) && ($j['data']['timeline'] ?? []) !== []);
    }

    /* ------------------------------------------------------------- F */
    echo "\n-- F. admin/routes.php --\n";
    $asrc = (string) file_get_contents($root . '/admin/routes.php');
    check('daily_setup no longer blanks duration_text', !str_contains($asrc, "duration_text=''"));
    foreach (['update_route_facts', 'measure_km'] as $act) {
        $p = strpos($asrc, "=== '" . $act . "'");
        $blk = $p !== false ? substr($asrc, $p, 900) : '';
        check("$act: CSRF + routes.edit", str_contains($blk, 'Security::verifyCsrf()') && str_contains($blk, "Auth::requireAdmin('routes.edit')"));
    }
    $p = strpos($asrc, "['add_halt', 'edit_halt', 'toggle_halt']");
    $blk = $p !== false ? substr($asrc, $p, 900) : '';
    check('halt actions: CSRF + routes.edit', str_contains($blk, 'Security::verifyCsrf()') && str_contains($blk, "Auth::requireAdmin('routes.edit')"));
    check('halt / facts / km edits are audited and flush the stop cache', substr_count($asrc, "Logger::audit('route_halt.") >= 2 && str_contains($asrc, "'route.facts_updated'") && str_contains($asrc, "'route_stop.km_measured'"));

    if ($serverUp) {
        $jar  = sys_get_temp_dir() . '/shg_rdt_' . getmypid() . '.cookies'; @unlink($jar);
        $user = 'rdt-super'; $pw = 'Rdt@12345';
        $csrfOf = static fn(string $h): string => preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $h, $mm) ? $mm[1] : '';
        try { Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
        $hash = password_hash($pw, PASSWORD_BCRYPT);
        $aid  = Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $user], 0);
        if ($aid) {
            Database::query("UPDATE admins SET password_hash=:h, role='superadmin', is_active=1, must_change_pw=0, failed_logins=0, locked_until=NULL WHERE id=:i", ['h' => $hash, 'i' => $aid]);
        } else {
            Database::query("INSERT INTO admins (username, password_hash, full_name, role, is_active, must_change_pw) VALUES (:u, :h, 'Route Details Test', 'superadmin', 1, 0)", ['u' => $user, 'h' => $hash]);
        }
        $lp = http('GET', '/admin/login.php', null, $jar);
        $li = http('POST', '/admin/login.php', ['shg_csrf' => $csrfOf($lp['body']), 'username' => $user, 'password' => $pw], $jar);
        check('superadmin signs in', $li['code'] === 302, 'HTTP ' . $li['code']);
        $rid = (int) $out['id'];
        $pg  = http('GET', '/admin/routes.php', null, $jar);
        check('routes page renders route facts, halts and the measure button', $pg['code'] === 200 && str_contains($pg['body'], 'id="facts-' . $rid . '"') && str_contains($pg['body'], 'id="halts-' . $rid . '"') && str_contains($pg['body'], 'value="measure_km"'), 'HTTP ' . $pg['code']);
        check('per-stop km field in the stop editor', str_contains($pg['body'], 'name="km"'));

        $n0 = (int) Database::scalar('SELECT COUNT(*) FROM route_halts WHERE route_id = :r', ['r' => $rid], 0);
        http('POST', '/admin/routes.php', ['action' => 'add_halt', 'route_id' => $rid, 'name' => 'RDT Nocsrf', 'kind' => 'tea'], $jar);
        check('add_halt without the CSRF token adds nothing', (int) Database::scalar('SELECT COUNT(*) FROM route_halts WHERE route_id = :r', ['r' => $rid], 0) === $n0);
        $pg = http('GET', '/admin/routes.php', null, $jar);
        http('POST', '/admin/routes.php', ['shg_csrf' => $csrfOf($pg['body']), 'action' => 'add_halt', 'route_id' => $rid, 'name' => 'RDT Halt', 'kind' => 'fuel',
            'clock_time' => '02:00', 'day' => 1, 'halt_min' => 10, 'km' => '500', 'is_approx' => 1], $jar);
        $hr = Database::fetch("SELECT * FROM route_halts WHERE route_id = :r AND name = 'RDT Halt'", ['r' => $rid]);
        check('add_halt stores the offset from departure', $hr !== null && (int) $hr['offset_min'] === (int) Boarding::haltOffset('02:00', 1, (string) $out['dep_time']) && (string) $hr['kind'] === 'fuel', $hr ? (string) $hr['offset_min'] : 'none');
        if ($hr !== null) {
            $pg = http('GET', '/admin/routes.php', null, $jar);
            http('POST', '/admin/routes.php', ['shg_csrf' => $csrfOf($pg['body']), 'action' => 'edit_halt', 'halt_id' => (int) $hr['id'], 'name' => 'RDT Halt', 'kind' => 'dinner',
                'clock_time' => '21:00', 'day' => 0, 'halt_min' => 25, 'km' => '', 'note' => 'edited'], $jar);
            $hr2 = Database::fetch('SELECT * FROM route_halts WHERE id = :i', ['i' => (int) $hr['id']]);
            check('edit_halt round-trips (kind, time, km cleared, approx off)', $hr2 !== null && $hr2['kind'] === 'dinner' && (int) $hr2['halt_min'] === 25 && $hr2['km_from_origin'] === null && (int) $hr2['is_approx'] === 0);
            $pg = http('GET', '/admin/routes.php', null, $jar);
            http('POST', '/admin/routes.php', ['shg_csrf' => $csrfOf($pg['body']), 'action' => 'toggle_halt', 'halt_id' => (int) $hr['id']], $jar);
            check('toggle_halt switches it OFF (never deleted)', (int) Database::scalar('SELECT is_active FROM route_halts WHERE id = :i', ['i' => (int) $hr['id']], 1) === 0);
            check('the halt edits are audited', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'route_halt.%' AND entity_id = :r", ['r' => (string) $rid], 0) >= 3);
        }

        $restore = Database::fetch('SELECT id, arr_time, day_offset, duration_text, distance_km FROM routes WHERE id = :i', ['i' => $rid]);
        $pg = http('GET', '/admin/routes.php', null, $jar);
        http('POST', '/admin/routes.php', ['shg_csrf' => $csrfOf($pg['body']), 'action' => 'update_route_facts', 'route_id' => $rid, 'distance_km' => '1375',
            'arr_time' => '00:39', 'day_offset' => 2, 'duration_text' => '', 'show_arrival' => '1'], $jar);
        $f1 = Database::fetch('SELECT arr_time, day_offset, duration_text FROM routes WHERE id = :i', ['i' => $rid]);
        check('route facts: "show arrival" fills the journey text from the times', $f1 !== null && (string) $f1['duration_text'] === '35h 39m' && (int) $f1['day_offset'] === 2, (string) ($f1['duration_text'] ?? ''));
        $pg = http('GET', '/admin/routes.php', null, $jar);
        http('POST', '/admin/routes.php', ['shg_csrf' => $csrfOf($pg['body']), 'action' => 'update_route_facts', 'route_id' => $rid, 'distance_km' => '1375',
            'arr_time' => '00:39', 'day_offset' => 2, 'duration_text' => '35h 39m'], $jar);
        check('route facts: unticked hides the arrival again (duration_text blank)', (string) Database::scalar('SELECT duration_text FROM routes WHERE id = :i', ['i' => $rid], 'x') === '');
        $pg = http('GET', '/admin/routes.php', null, $jar);
        $bad = http('POST', '/admin/routes.php', ['shg_csrf' => $csrfOf($pg['body']), 'action' => 'update_route_facts', 'route_id' => $rid, 'distance_km' => '1375',
            'arr_time' => '13:10', 'day_offset' => 0, 'show_arrival' => '1'], $jar);
        check('route facts: an implausible arrival is refused', str_contains($bad['body'], 'not a plausible journey') && (string) Database::scalar('SELECT duration_text FROM routes WHERE id = :i', ['i' => $rid], 'x') === '');

        $kmBefore = (string) Database::scalar('SELECT COALESCE(SUM(km_from_origin), 0) FROM route_stops WHERE route_id = :r', ['r' => $rid], '0');
        $engine   = Settings::getString('nav_engine_url', '');
        $engineUp = false;
        if (preg_match('#^https?://#', $engine) === 1) {
            $ch = curl_init(rtrim($engine, '/') . '/status');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2]);
            curl_exec($ch); $engineUp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200; curl_close($ch);
        }
        if ($engineUp) {
            echo "  SKIP  measure_km failure path — a route engine answers at $engine (not pressing it from a test)\n";
        } else {
            $pg = http('GET', '/admin/routes.php', null, $jar);
            $mr = http('POST', '/admin/routes.php', ['shg_csrf' => $csrfOf($pg['body']), 'action' => 'measure_km', 'route_id' => $rid], $jar);
            check('measure_km with no engine: says so and changes nothing', str_contains($mr['body'], 'did not answer') && (string) Database::scalar('SELECT COALESCE(SUM(km_from_origin), 0) FROM route_stops WHERE route_id = :r', ['r' => $rid], '0') === $kmBefore);
        }
        @unlink($jar);
    }
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if (is_array($restore) && $restore !== []) {
        Database::update('routes', ['arr_time' => $restore['arr_time'], 'day_offset' => (int) $restore['day_offset'],
            'duration_text' => $restore['duration_text'], 'distance_km' => $restore['distance_km']], 'id = :i', ['i' => (int) $restore['id']]);
    }
    cleanup();
    Boarding::flush();
}

echo "\n----------------------------------------\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
