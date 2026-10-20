<?php
/**
 * whereis-test.php — "Where is my bus?" (24 Sep 2026).
 *
 *   A. WhereIs::status() is honest: no published position → live=false with
 *      the reason; a confirmed booking carries its stop, date and route.
 *   B. The three doors: keyed link, the customer's own number, staff scope.
 *   C. track.php: 404 + "not valid" for a wrong key, 200 with the PNR, the
 *      share button and three languages for the right key.        [HTTP]
 *   D. api/whereis.php: JSON shape, 404 for a wrong key.            [HTTP]
 *   E. The app payload carries trackUrl for a confirmed booking.
 *   F. The road map (20 Oct 2026): "Show map" only with a road line, the
 *      keyed api/track-map.php (stops on road km, position, the line
 *      cacheable by version), nothing loaded before the tap.     [HTTP]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/whereis.php';
require_once INCLUDE_PATH . '/routegeo.php';

define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function get(string $path): array {
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => $body];
}
$serverUp = get('/index.php')['code'] === 200;
function headersOf(string $path): string {
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => true, CURLOPT_NOBODY => false]);
    $raw = (string) curl_exec($ch); $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return strtolower(substr($raw, 0, $hs));
}
/** A fake "router" for the road-line checks: the stops joined by straight steps. */
function straightRoad(array $st): array {
    $pts = [[$st[0]['lat'], $st[0]['lng']]];
    for ($i = 1, $n = count($st); $i < $n; $i++) {
        for ($k = 1; $k <= 20; $k++) {
            $t = $k / 20;
            $pts[] = [$st[$i - 1]['lat'] + $t * ($st[$i]['lat'] - $st[$i - 1]['lat']), $st[$i - 1]['lng'] + $t * ($st[$i]['lng'] - $st[$i - 1]['lng'])];
        }
    }
    return ['ok' => true, 'pts' => $pts, 'source' => 'test', 'error' => ''];
}

const TD = '2099-12-03'; const PHONE = '9198700088';
function cleanup(): void {
    foreach (pluck(Database::fetchAll("SELECT id FROM bookings WHERE contact_phone = :p", ['p' => PHONE]), 'id') as $id) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $id]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
    Database::delete("kv_store", "kscope = 'global' AND kkey = 'livebus' AND kvalue LIKE '%wi-test%'");
}

echo "\n=== Where is my bus? ===\n\n";
cleanup();
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
$admin = Database::fetch("SELECT id FROM admins WHERE role='superadmin' AND is_active=1 ORDER BY id LIMIT 1");
check('fixtures: an active route and the owner exist', $route !== null && $admin !== null);
$sid = (int) Seats::schedule((int) $route['id'], TD)['id'];
$b = BookingService::counterSale($route, $sid, TD, ['L14'], ['name' => 'Where Is', 'phone' => PHONE, 'gender' => 'Male', 'paymentMethod' => 'cash'], (int) $admin['id'], 'counter');
$pnr = (string) $b['pnr'];
$row = BookingService::findByPnr($pnr);
check('a confirmed booking exists', $row !== null && $row['status'] === 'confirmed', $pnr);

try {
    echo "\n-- A. honest status --\n";
    $st = WhereIs::status($row, time());
    check('not live without a published position', $st['live'] === false && $st['reason'] !== '', (string) $st['reason']);
    check('the passenger\'s stop, date and route are named', $st['stop'] !== '' && $st['date'] === TD && str_contains((string) $st['route'], '→'), $st['stop'] . ' · ' . $st['route']);
    check('a trip state from the same ladder as the office board', in_array($st['state'], ['upcoming', 'departing', 'boarding', 'departed', 'on_route', 'delayed', 'arrived', 'completed', 'cancelled', 'sold_out'], true), (string) $st['state']);
    check('no coordinates are invented', $st['lat'] === null && $st['lng'] === null && $st['etaMin'] === null);

    echo "\n-- B. doors --\n";
    $key = Ticket::downloadToken($pnr);
    check('the keyed link opens', WhereIs::authorised($row, $key));
    check('a wrong key does not', !WhereIs::authorised($row, 'nope') && !WhereIs::authorised($row, ''));
    check('the tracker\'s own key opens too (8 Oct 2026)', WhereIs::authorised($row, Ticket::trackToken($pnr)));
    /* 8 Oct 2026: a phone match alone is no longer proof once
       Auth::customerMayOpen() exists — the session must be OTP-verified
       (CustomerProfile marks it under 'shg_cust_otp'), so the test marks it. */
    $_SESSION[USER_SESSION_KEY] = ['id' => 999, 'phone' => PHONE, 'name' => 'Where Is', 'role' => 'customer', 'logged_in' => time(), 'last_seen' => time()];
    $_SESSION['shg_cust_otp'] = 999;
    check('the verified customer whose number is on the ticket opens it without a key', WhereIs::authorised($row, ''));
    unset($_SESSION['shg_cust_otp']);
    $_SESSION[USER_SESSION_KEY] = ['id' => 998, 'phone' => '9100000000', 'name' => 'Stranger', 'role' => 'customer', 'logged_in' => time(), 'last_seen' => time()];
    check('another signed-in customer does not', !WhereIs::authorised($row, ''));
    unset($_SESSION[USER_SESSION_KEY]);
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => 999997, 'username' => 'office', 'role' => 'manager', 'permissions' => [], 'last_seen' => time()];
    check('the office opens any ticket', WhereIs::authorised($row, ''));
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => 999996, 'username' => 'other-agent', 'role' => 'agent', 'permissions' => [], 'last_seen' => time()];
    check('another agent does not', !WhereIs::authorised($row, ''));
    unset($_SESSION[ADMIN_SESSION_KEY]);

    echo "\n-- E. app payload --\n";
    $detail  = BookingService::detail($pnr);
    $payload = shg_customer_payload($detail);
    check('trackUrl rides with the ticket link', str_contains((string) ($payload['trackUrl'] ?? ''), 'track.php?pnr=' . rawurlencode($pnr)) && str_contains((string) $payload['trackUrl'], '&k='), (string) ($payload['trackUrl'] ?? 'none'));

    echo "\n-- G. on board (21 Oct 2026) --\n";
    $rid  = (int) $route['id'];
    $L    = EtaAlerts::routeLine($rid)['line'];
    $leg  = WhereIs::leg($row);
    $pi   = null;
    foreach ($L as $i => $s) {
        if ($s['type'] === 'boarding' && Boarding::townKey($s['name']) === Boarding::townKey($leg['boarding_stop'])) { $pi = $i; break; }
    }
    $pickTs = (int) (WhereIs::status($row, time())['pickupTs'] ?? 0);
    if ($pi === null || !isset($L[$pi + 1]) || $pickTs <= 0) {
        echo "  SKIP  the test booking's pickup has no coordinates (or no stop after it)\n";
    } else {
        $savedBus = Database::fetch("SELECT * FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus'");
        $bus = static function (float $lat, float $lng, int $ts): void {
            Database::delete('kv_store', "kscope = 'global' AND kkey = 'livebus'");
            Database::insert('kv_store', ['kscope' => 'global', 'kkey' => 'livebus', 'updated_at' => date('Y-m-d H:i:s', $ts),
                'kvalue' => json_encode(['lat' => $lat, 'lng' => $lng, 'spd' => 13.9, 'acc' => 15, 'tag' => 'wi-test'])]);
        };
        try {
            $A = $L[$pi]; $B = $L[$pi + 1];
            $t = $pickTs + 3600;
            $bus($A['lat'] + 0.5 * ($B['lat'] - $A['lat']), $A['lng'] + 0.5 * ($B['lng'] - $A['lng']), $t);
            $st = WhereIs::status($row, $t);
            check('coach past the pickup after its time: live, with its position', !empty($st['live']) && $st['lat'] !== null, (string) $st['reason']);
            check('...on board and on the way to the drop - not "passed your stop"', !empty($st['onBoard']) && empty($st['passed']) && (string) ($st['drop'] ?? '') !== '' && $st['etaMin'] === null, (string) ($st['drop'] ?? ''));
            check('...km to the drop only when it has coordinates, minutes only within ' . (int) WhereIs::ETA_MAX_KM . ' km',
                ($st['dropKm'] === null && $st['dropEtaMin'] === null) || ($st['dropKm'] > 0 && ($st['dropEtaMin'] === null) === ($st['dropKm'] > WhereIs::ETA_MAX_KM)),
                json_encode([$st['dropKm'], $st['dropEtaMin']]));
            $bus($L[0]['lat'] - 3.0, $L[0]['lng'], $t);   // ~330 km south of the start of the line
            $st = WhereIs::status($row, $t);
            check('a coach far off the route: silence, no position, no "on board"', empty($st['live']) && $st['lat'] === null && empty($st['onBoard']), (string) $st['reason']);
            if ($pi > 0) {
                $P = $L[$pi - 1];
                $bus($P['lat'] + 0.5 * ($A['lat'] - $P['lat']), $P['lng'] + 0.5 * ($A['lng'] - $P['lng']), $pickTs - 1800);
                $st = WhereIs::status($row, $pickTs - 1800);
                check('pickup still ahead: minutes to it, as before', !empty($st['live']) && empty($st['onBoard']) && empty($st['passed']) && $st['km'] > 0, json_encode([$st['etaMin'], $st['km']]));
            }
        } finally {
            Database::delete('kv_store', "kscope = 'global' AND kkey = 'livebus'");
            if ($savedBus !== null) { Database::insert('kv_store', $savedBus); }
        }
    }
    $src = (string) file_get_contents(dirname(__DIR__) . '/track.php');
    check('track.php says "on the way" in all three languages, and its refresh does too',
        substr_count($src, "'onWay' =>") === 3 && substr_count($src, "'onWayEta' =>") === 3 && str_contains($src, 'live && d.onBoard'));

    if (!$serverUp) {
        echo "\n  SKIP  HTTP parts — no server at " . BASE . "\n";
    } else {
        echo "\n-- C. track.php --\n";
        $r = get('/track.php?pnr=' . rawurlencode($pnr) . '&k=wrong');
        check('a wrong key → 404 and no ticket facts', $r['code'] === 404 && !str_contains($r['body'], 'L14'), 'HTTP ' . $r['code']);
        $r = get('/track.php?pnr=' . rawurlencode($pnr) . '&k=' . $key);
        check('the right key → 200', $r['code'] === 200, 'HTTP ' . $r['code']);
        check('the page names the ticket', str_contains($r['body'], $pnr));
        check('share-with-family button (wa.me)', str_contains($r['body'], 'https://wa.me/?text='));
        check('honest "not live" headline', str_contains($r['body'], 'not live') || str_contains($r['body'], 'लाइव नहीं') || str_contains($r['body'], 'लाइभ छैन'));
        check('three languages one tap away', str_contains($r['body'], '&amp;lang=ne') && str_contains($r['body'], '&amp;lang=hi') && str_contains($r['body'], '&amp;lang=en'));
        check('search engines are told to stay out', str_contains($r['body'], 'name="robots" content="noindex"'));
        $r = get('/track.php?pnr=' . rawurlencode($pnr) . '&k=' . $key . '&lang=en');
        check('lang=en renders the English headline', str_contains($r['body'], 'Where is my bus?'));
        check('stand-alone and light (no app bundle)', !str_contains($r['body'], '05-router.js') && strlen($r['body']) < 20000, strlen($r['body']) . ' bytes');

        echo "\n-- D. api/whereis.php --\n";
        $r = get('/api/whereis.php?pnr=' . rawurlencode($pnr) . '&k=' . $key);
        $j = json_decode($r['body'], true) ?: [];
        check('JSON with the status shape', $r['code'] === 200 && !empty($j['ok']) && array_key_exists('live', $j['data'] ?? []) && isset($j['data']['stop']), substr($r['body'], 0, 90));
        $r = get('/api/whereis.php?pnr=' . rawurlencode($pnr) . '&k=wrong');
        check('a wrong key → 404', $r['code'] === 404, 'HTTP ' . $r['code']);

        echo "\n-- F. the road map --\n";
        if (!RouteGeo::available()) {
            check('route_geometry exists (apply database/upgrade-2026-10-route-geometry.sql)', false);
        } else {
            $rid = (int) $route['id'];
            $savedGeo = Database::fetch('SELECT * FROM route_geometry WHERE route_id = :r', ['r' => $rid]);
            try {
                Database::delete('route_geometry', 'route_id = :r', ['r' => $rid]);
                RouteGeo::resetCache();
                $r = get('/track.php?pnr=' . rawurlencode($pnr) . '&k=' . $key);
                check('no road line = no "Show map" button', $r['code'] === 200 && !str_contains($r['body'], 'id="mapBtn"'));
                $bg = RouteGeo::build($rid, 'straightRoad');
                check('a road line is built for the test route', $bg['ok'], $bg['error'] ?? '');
                $r = get('/track.php?pnr=' . rawurlencode($pnr) . '&k=' . $key);
                check('with a road line the page offers "Show map" - and loads no map until it is tapped',
                    str_contains($r['body'], 'id="mapBtn"') && !str_contains($r['body'], 'maplibre-gl') && !str_contains($r['body'], 'src="/assets/js/track-map.js'), strlen($r['body']) . ' bytes');
                check('...and the page stays light', strlen($r['body']) < 20000, strlen($r['body']) . ' bytes');
                $r = get('/api/track-map.php?pnr=' . rawurlencode($pnr) . '&k=wrong');
                check('api/track-map.php: a wrong key → 404', $r['code'] === 404, 'HTTP ' . $r['code']);
                $r = get('/api/track-map.php?pnr=' . rawurlencode($pnr) . '&k=' . $key);
                $j = json_decode($r['body'], true) ?: [];
                $d = $j['data'] ?? [];
                $S = (array) ($d['stops'] ?? []);
                check('the right key → the line version, the stops on road km and the coach position',
                    $r['code'] === 200 && ($d['v'] ?? null) === $bg['version'] && count($S) >= 2 && $S[0]['km'] < $S[1]['km']
                    && array_key_exists('live', (array) ($d['pos'] ?? [])) && $d['pos']['live'] === false, substr($r['body'], 0, 120));
                $have = [];
                foreach (EtaAlerts::stopLine($rid)['line'] as $s) { $have[] = round($s['lat'], 6) . ',' . round($s['lng'], 6); }
                $inv = array_filter($S, static fn(array $s): bool => !in_array(round($s['lat'], 6) . ',' . round($s['lng'], 6), $have, true));
                check('every stop drawn is a stop with stored coordinates - none invented', $inv === [], json_encode(array_values($inv)));
                check('the passenger\'s own pickup is marked, or the page is told it has no coordinates',
                    (bool) ($d['mineMissing'] ?? true) === !in_array('board', array_column($S, 'mine'), true), json_encode(array_column($S, 'mine')));
                check('no live fix = no bus position in the map answer', ($d['pos']['lat'] ?? null) === null && !isset($d['pos']['road']));
                $r = get('/api/track-map.php?pnr=' . rawurlencode($pnr) . '&k=' . $key . '&part=line&v=' . $bg['version']);
                $j = json_decode($r['body'], true) ?: [];
                check('part=line: the stored road line itself', $r['code'] === 200 && ($j['data']['line'] ?? '') === RouteGeo::load($rid)['polyline6'] && ($j['data']['v'] ?? 0) === $bg['version']);
                check('...cacheable for its version (a rebuild changes the URL)',
                    str_contains(headersOf('/api/track-map.php?pnr=' . rawurlencode($pnr) . '&k=' . $key . '&part=line&v=' . $bg['version']), 'immutable'));
                check('...an old version is never cached',
                    str_contains(headersOf('/api/track-map.php?pnr=' . rawurlencode($pnr) . '&k=' . $key . '&part=line&v=' . ($bg['version'] + 7)), 'no-store'));
                $r = get('/api/track-map.php?pnr=' . rawurlencode($pnr) . '&k=wrong&part=line&v=' . $bg['version']);
                check('...and behind the same door', $r['code'] === 404);
                $r = get('/assets/js/track-map.js');
                check('the map script is served (loaded only on the tap)', $r['code'] === 200 && str_contains($r['body'], 'mapUpdate'));
            } finally {
                Database::delete('route_geometry', 'route_id = :r', ['r' => $rid]);
                if ($savedGeo !== null) { Database::insert('route_geometry', $savedGeo); }
                RouteGeo::resetCache();
            }
        }
    }
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    unset($_SESSION[USER_SESSION_KEY], $_SESSION[ADMIN_SESSION_KEY]);
    cleanup();
}

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
