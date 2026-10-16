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
function get(string $path): array {
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => $body];
}
$serverUp = get('/index.php')['code'] === 200;

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
    $_SESSION[USER_SESSION_KEY] = ['id' => 999, 'phone' => PHONE, 'name' => 'Where Is', 'role' => 'customer', 'logged_in' => time(), 'last_seen' => time()];
    check('the customer whose number is on the ticket opens it without a key', WhereIs::authorised($row, ''));
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
    }
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    unset($_SESSION[USER_SESSION_KEY], $_SESSION[ADMIN_SESSION_KEY]);
    cleanup();
}

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
