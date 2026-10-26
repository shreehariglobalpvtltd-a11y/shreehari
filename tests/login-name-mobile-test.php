<?php
/**
 * =====================================================================
 *  login-name-mobile-test.php — 4 Sep 2026: sign-in is NAME + MOBILE.
 *
 *   A. api/otp.php `quick`: a brand-new number + name registers and signs
 *      in at once (no OTP); no name → 422; a returning number with a name
 *      on file signs in without a name; a suspended account → 403; the
 *      typed name replaces a wrong spelling on file.
 *   B. api/my-bookings.php: 401 for a guest; the signed-in number's
 *      bookings (by user_id OR contact_phone) come back newest first in
 *      the track.php shape.
 *   C. api/book.php: a signed-in customer's booking is written against the
 *      SESSION phone even when the payload carries another number.
 *   D. api/otp.php `request` is capped per IP (11th call in 5 min → 429)
 *      and never stores the code in message_logs.
 *
 *  Needs the dev server on :8899 (php -S) and the local test DB.
 *  Run: php -c .claude/php-dev.ini tests/login-name-mobile-test.php
 *  Cleans up every row it creates.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/booking.php';

/* Overridable so a second worktree can be tested without stealing :8899
   from the one that already holds it — a suite pointed at ANOTHER
   checkout's server reports green for code you did not change.
       SHG_TEST_BASE=http://localhost:8898 php ... */
define('BASE', getenv('SHG_TEST_BASE') ?: 'http://localhost:8899');

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  {$l}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  {$l}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; }
}
function req(string $jar, string $method, string $url, array $opt = []): array {
    $ch = curl_init(BASE . $url);
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
        $headers[] = 'Content-Type: application/json';
        if (!empty($opt['csrf'])) { $headers[] = 'X-CSRF-Token: ' . $opt['csrf']; }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $hs);
    $json = json_decode($body, true);
    return ['code' => $code, 'json' => is_array($json) ? $json : null, 'body' => $body];
}
function jar(string $n): string { $p = sys_get_temp_dir() . '/shg_login_' . $n . '_' . getmypid() . '.cookies'; @unlink($p); return $p; }
function csrfFor(string $jar): string {
    $r = req($jar, 'GET', '/api/config.php');
    return (string) ($r['json']['data']['csrfToken'] ?? '');
}

$P_NEW   = '9000000101';   // brand-new number
$P_OLD   = '9000000102';   // returning number with a name on file
$P_BLK   = '9000000103';   // suspended
$P_OTHER = '9000000104';   // the number a payload tries to book for
$allPhones = [$P_NEW, $P_OLD, $P_BLK, $P_OTHER];

$cleanup = static function () use ($allPhones): void {
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone IN (' . implode(',', array_fill(0, count($allPhones), '?')) . ')', $allPhones) as $b) {
        Seats::releaseBooking((int) $b['id']);
        foreach (['booking_passengers', 'booking_legs', 'booking_seats', 'payments', 'tickets', 'agent_ledger'] as $t) {
            try { Database::query("DELETE FROM {$t} WHERE booking_id = :b", ['b' => (int) $b['id']]); } catch (Throwable $e) {}
        }
        Database::query('DELETE FROM bookings WHERE id = :b', ['b' => (int) $b['id']]);
    }
    Database::query('DELETE FROM users WHERE phone IN (' . implode(',', array_fill(0, count($allPhones), '?')) . ')', $allPhones);
    Database::query("DELETE FROM rate_limits WHERE bucket IN ('login_quick','otp_request_ip','my_bookings','book_create','search','otp_send_login','otp_hourly_login')");
    Database::query("DELETE FROM message_logs WHERE to_number LIKE '%9000000%'");
    Database::query("DELETE FROM otp_codes WHERE identifier IN ('9000000101','9000000102','9000000103','9000000104')");
};
$cleanup();

// Seed a returning user (wrong spelling on file) and a suspended one.
Database::insert('users', ['phone' => $P_OLD, 'full_name' => 'Ramesh Thapa', 'role' => 'customer', 'country_code' => '91', 'phone_verified' => 1]);
Database::insert('users', ['phone' => $P_BLK, 'full_name' => 'Blocked Person', 'role' => 'customer', 'country_code' => '91', 'phone_verified' => 1, 'is_blocked' => 1, 'blocked_reason' => 'test']);

echo "A. quick sign-in = name + mobile\n";
$jA = jar('a'); $csrfA = csrfFor($jA);
check('config.php hands out a csrf token', $csrfA !== '');
$r = req($jA, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => $P_NEW, 'name' => '', 'country' => 'IN'], 'csrf' => $csrfA]);
check('new number without a name → 422', $r['code'] === 422, 'HTTP ' . $r['code']);
$r = req($jA, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => $P_NEW, 'name' => 'Sita Kumari', 'country' => 'NP'], 'csrf' => $csrfA]);
check('new number + name → signed in, no OTP', $r['code'] === 200 && !empty($r['json']['data']['verified']) && empty($r['json']['data']['needsOtp']), 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 120));
check('response carries the name', ($r['json']['data']['user']['name'] ?? '') === 'Sita Kumari');
$u = Database::fetch('SELECT full_name, country_code, phone_verified FROM users WHERE phone = :p', ['p' => $P_NEW]);
check('users row created with the name', $u !== null && $u['full_name'] === 'Sita Kumari', json_encode($u));
check('country code stored from the picker (977)', ($u['country_code'] ?? '') === '977');
$cfg = req($jA, 'GET', '/api/config.php');
check('the session now knows the customer', ($cfg['json']['data']['user']['phone'] ?? '') === $P_NEW, json_encode($cfg['json']['data']['user'] ?? null));

$jB = jar('b'); $csrfB = csrfFor($jB);
$r = req($jB, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => $P_OLD, 'name' => '', 'country' => 'IN'], 'csrf' => $csrfB]);
check('returning number, no name typed → signed in with the name on file', $r['code'] === 200 && ($r['json']['data']['user']['name'] ?? '') === 'Ramesh Thapa', 'HTTP ' . $r['code']);
$r = req($jB, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => $P_OLD, 'name' => 'Ramesh Thapa Magar', 'country' => 'IN'], 'csrf' => $csrfB]);
$u = Database::fetch('SELECT full_name FROM users WHERE phone = :p', ['p' => $P_OLD]);
check('a corrected name replaces the one on file', ($u['full_name'] ?? '') === 'Ramesh Thapa Magar', (string) ($u['full_name'] ?? ''));

$jC = jar('c'); $csrfC = csrfFor($jC);
$r = req($jC, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => $P_BLK, 'name' => 'Blocked Person', 'country' => 'IN'], 'csrf' => $csrfC]);
check('suspended account → 403', $r['code'] === 403, 'HTTP ' . $r['code']);
$r = req($jC, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => '12', 'name' => 'X Y'], 'csrf' => $csrfC]);
check('garbage phone → 422', $r['code'] === 422, 'HTTP ' . $r['code']);

echo "B. my-bookings.php\n";
$jG = jar('g');
$r = req($jG, 'GET', '/api/my-bookings.php');
check('guest → 401', $r['code'] === 401, 'HTTP ' . $r['code']);
$r = req($jA, 'GET', '/api/my-bookings.php');
check('signed-in, no bookings yet → empty list', $r['code'] === 200 && ($r['json']['data']['bookings'] ?? null) === [] && ($r['json']['data']['phone'] ?? '') === $P_NEW, 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 160));

echo "C. book.php binds the booking to the session phone\n";
$route = Database::fetch("SELECT id, route_code, from_city, to_city FROM routes WHERE route_code = 'r2' AND is_active = 1 LIMIT 1");
check('test route r2 exists', $route !== null);
if ($route !== null) {
    $w = bookingWindow();
    $D = max($w['from'], addDaysISO(todayISO(), 3));
    if ($D > $w['to']) { $D = $w['to']; }
    $sched = Seats::schedule((int) $route['id'], $D);
    $avail = Seats::availability((int) $route['id'], $D, 'sharing');
    $free  = array_values(array_diff($avail['available'] ?? [], $avail['blocked'] ?? [], $avail['locked'] ?? []));
    $pick  = array_slice(array_filter($free, static fn($s) => str_starts_with((string) $s, 'U')), -2, 2);
    check('two free upper-deck seats found', count($pick) === 2, implode(',', $pick));
    if (count($pick) === 2) {
        $payload = [
            'routeId' => (int) $route['id'], 'travelDate' => $D, 'seats' => $pick,
            'passengers' => [['seat' => $pick[0], 'name' => 'Sita Kumari', 'age' => 30, 'gender' => 'Female'],
                             ['seat' => $pick[1], 'name' => 'Sita Kumari', 'age' => 0, 'gender' => 'Female']],
            'contact' => ['phone' => $P_OTHER, 'email' => '', 'idType' => '', 'idNum' => ''],
            'bookingMode' => 'sharing', 'boarding' => '', 'drop' => '', 'originTown' => (string) $route['from_city'],
            'paymentMethod' => 'cod', 'isCod' => true,
        ];
        $r = req($jA, 'POST', '/api/book.php', ['json' => $payload, 'csrf' => $csrfA]);
        check('booking accepted', $r['code'] === 200 && !empty($r['json']['data']['pnr']), 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 160));
        $pnr = (string) ($r['json']['data']['pnr'] ?? '');
        $row = $pnr !== '' ? Database::fetch('SELECT contact_phone, user_id FROM bookings WHERE pnr = :p', ['p' => $pnr]) : null;
        check('contact_phone is the SESSION number, not the payload one', $row !== null && $row['contact_phone'] === $P_NEW, json_encode($row));
        $uid = (int) (Database::fetch('SELECT id FROM users WHERE phone = :p', ['p' => $P_NEW])['id'] ?? 0);
        check('user_id stamped on the booking', $row !== null && (int) $row['user_id'] === $uid);
        $r = req($jA, 'GET', '/api/my-bookings.php');
        $list = $r['json']['data']['bookings'] ?? [];
        check('my-bookings lists it', count($list) === 1 && ($list[0]['pnr'] ?? '') === $pnr, 'HTTP ' . $r['code'] . ' n=' . count($list));
        check('entry has the track.php shape (legs, passengers, payment, farePerSeat)',
            isset($list[0]['legs'][0]['routeCode'], $list[0]['passengers'][0]['name'], $list[0]['payment']['status']) && ($list[0]['farePerSeat'] ?? 0) > 0,
            json_encode(array_keys($list[0] ?? [])));
        check('createdAt is epoch milliseconds', ($list[0]['createdAt'] ?? 0) > 1_700_000_000_000);
        // A second account must not see it.
        $r = req($jB, 'GET', '/api/my-bookings.php');
        check('another signed-in customer does not see it', $r['code'] === 200 && ($r['json']['data']['bookings'] ?? null) === []);
        // Counter sale is exempt from the binding (staff types the passenger's number) — covered by counter-mode-http-test.
    }
}

echo "D. OTP request per-IP cap + no code at rest\n";
Database::query("DELETE FROM rate_limits WHERE bucket = 'otp_request_ip'");
$jD = jar('d'); $csrfD = csrfFor($jD);
$codes = [];
for ($i = 1; $i <= 11; $i++) {
    $ph = '90000009' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);   // a different number each time
    $r = req($jD, 'POST', '/api/otp.php', ['json' => ['action' => 'request', 'phone' => $ph, 'purpose' => 'login', 'country' => 'IN'], 'csrf' => $csrfD]);
    $codes[$i] = $r['code'];
}
check('first 10 requests are served (200 or a provider 429, never 500)', !in_array(500, array_slice($codes, 0, 10, true), true), json_encode($codes));
check('11th request from the same IP → 429 (per-IP cap)', $codes[11] === 429, 'HTTP ' . $codes[11]);
$leak = Database::fetch("SELECT COUNT(*) AS n FROM message_logs WHERE to_number LIKE '%9000000%' AND body REGEXP 'code[^0-9]{0,12}[0-9]{4,8}'");
check('no OTP digits stored in message_logs', (int) ($leak['n'] ?? 0) === 0, 'rows=' . ($leak['n'] ?? '?'));
Database::query("DELETE FROM otp_codes WHERE identifier LIKE '90000009%'");
Database::query("DELETE FROM rate_limits WHERE bucket IN ('otp_send_login','otp_hourly_login','otp_request_ip')");
Database::query("DELETE FROM message_logs WHERE to_number LIKE '%90000009%'");

$cleanup();
echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
