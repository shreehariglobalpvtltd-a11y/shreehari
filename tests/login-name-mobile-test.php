<?php
/**
 * =====================================================================
 *  login-name-mobile-test.php — 4 Sep 2026: sign-in is NAME + MOBILE.
 *  8 Oct 2026 (owner: "name + phone is never proof"): that sign-in may
 *  BOOK, but it proves nothing — the WhatsApp code is what proves the
 *  number (api/otp.php 'quick', Auth::loginUser $proven = false).
 *
 *   A. api/otp.php `quick`: a brand-new number + name signs in at once
 *      (no OTP) to book, flagged verified:false / needsOtp:true and never
 *      marked phone_verified; no name → 422; a returning number signs in
 *      without a name but is NOT told the name on file - neither in the reply
 *      nor in the session the app boots from (SHG_BOOT.user.name); a typed
 *      name never renames / re-countries / verifies the account on file;
 *      suspended → 403.
 *   A2. api/otp.php `verify` (the WhatsApp code PROVES the number,
 *      Auth::loginUser $proven = true): a wrong code renames nothing; the
 *      right code with a corrected spelling replaces the name on file (the
 *      name that prints on the ticket), re-countries it and marks the number
 *      verified; a proven sign-in with no name typed is answered - reply and
 *      session - with the name on file.
 *   B. api/my-bookings.php: 401 for a guest; a name + mobile session sees
 *      only the tickets THIS browser booked (empty at first), in the
 *      track.php shape, with needsOtp:true.
 *   C. api/book.php: a signed-in customer's booking is written against the
 *      SESSION phone even when the payload carries another number.
 *   D. api/otp.php `request` is capped per IP (11th call in 5 min → 429)
 *      and never stores the code in message_logs.
 *
 *  HTTP: SHG_TEST_BASE=http://localhost:<port>, else the suite starts its
 *  own php -S on this checkout. Needs the local test DB.
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
       SHG_TEST_BASE=http://localhost:8898 php ...
   16 Nov 2026 (battery T1): with no SHG_TEST_BASE the suite starts its OWN
   `php -S` on a free port, serving THIS checkout with this run's php + ini,
   and stops it on exit (same helper as manifest-test.php) - the battery
   passes no base, and :8899 is whichever checkout happens to own it. */
function shg_test_base(): string
{
    $env = trim((string) getenv('SHG_TEST_BASE'));
    if ($env !== '') {
        return rtrim($env, '/');
    }
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        return 'http://localhost:8899';
    }
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    $ini  = (string) php_ini_loaded_file();
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $cmd  = array_merge([PHP_BINARY], $ini !== '' ? ['-c', $ini] : [], ['-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)]);
    $proc = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return 'http://localhost:8899';
    }
    register_shutdown_function(static function () use ($proc): void {
        @proc_terminate($proc);
        @proc_close($proc);
    });
    for ($i = 0; $i < 100; $i++) {   // up to ~10 s for the server to answer
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($c !== false) { fclose($c); break; }
        usleep(100000);
    }
    return 'http://127.0.0.1:' . $port;
}
define('BASE', shg_test_base());

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
/* 16 Nov 2026 (battery T1): api/config.php - where this suite read the CSRF
   token and the session's customer - does not exist on this lineage (it was
   in the recovered live copy the suite came from). The app shell (index.php)
   is what hands the page both: window.SHG_BOOT.csrf and SHG_BOOT.user, read
   by the app at boot. This reads the same thing the app reads. */
function boot(string $jar): array {
    $r = req($jar, 'GET', '/');
    if (preg_match('/window\.SHG_BOOT = (.+?);\r?\n/', $r['body'], $m) !== 1) {
        return [];
    }
    $b = json_decode($m[1], true);
    return is_array($b) ? $b : [];
}
function csrfFor(string $jar): string {
    return (string) (boot($jar)['csrf'] ?? '');
}

$P_NEW   = '9000000101';   // brand-new number
$P_OLD   = '9000000102';   // returning number with a name on file
$P_BLK   = '9000000103';   // suspended
$P_OTHER = '9000000104';   // the number a payload tries to book for
$allPhones = [$P_NEW, $P_OLD, $P_BLK, $P_OTHER];

/* 16 Nov 2026 (battery T1): the throttles are cleared for THIS machine and the
   test numbers only (not every caller's bucket on the shared test DB), and the
   customer-vault row (user_profiles, GemVault::upsert at every sign-in) goes too.
   A booking made over HTTP also leaves rows in side tables (ticket_links,
   delivery_log, ... - every table with a booking_id column) and, by PNR, the
   automation log and the office bell (notifications): all removed with it. */
$bookingTables = array_values(array_filter(array_map(static fn(array $r): string => (string) $r['t'], Database::fetchAll(
    "SELECT TABLE_NAME t FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'booking_id' AND TABLE_NAME <> 'bookings'
        AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE')")),
    static fn(string $t): bool => preg_match('/^[a-z0-9_]+$/', $t) === 1));
$cleanup = static function () use ($allPhones, $bookingTables): void {
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone IN (' . implode(',', array_fill(0, count($allPhones), '?')) . ')', $allPhones) as $b) {
        Seats::releaseBooking((int) $b['id']);
        foreach (array_unique(array_merge(['booking_passengers', 'booking_legs', 'booking_seats', 'payments', 'tickets', 'agent_ledger'], $bookingTables)) as $t) {
            try { Database::query("DELETE FROM {$t} WHERE booking_id = :b", ['b' => (int) $b['id']]); } catch (Throwable $e) {}
        }
        $pnr = (string) $b['pnr'];
        if ($pnr !== '') {
            try { Database::query('DELETE FROM automation_log WHERE dedupe_key LIKE :k OR data LIKE :d', ['k' => '%' . $pnr, 'd' => '%"' . $pnr . '"%']); } catch (Throwable $e) {}
            try { Database::query('DELETE FROM notifications WHERE link LIKE :l', ['l' => '%pnr=' . $pnr]); } catch (Throwable $e) {}
        }
        Database::query('DELETE FROM bookings WHERE id = :b', ['b' => (int) $b['id']]);
    }
    /* 19 Nov 2026 (u7): a proven sign-in now also leaves a remembered-device row (customer_sessions,
       keyed by user_id) - removed with its user, before the user row goes. */
    try {
        Database::query('DELETE FROM customer_sessions WHERE user_id IN (SELECT id FROM users WHERE phone IN (' . implode(',', array_fill(0, count($allPhones), '?')) . '))', $allPhones);
    } catch (Throwable $e) {}   // table missing on an older test DB
    Database::query('DELETE FROM users WHERE phone IN (' . implode(',', array_fill(0, count($allPhones), '?')) . ')', $allPhones);
    try { Database::query('DELETE FROM user_profiles WHERE phone IN (' . implode(',', array_fill(0, count($allPhones), '?')) . ')', $allPhones); } catch (Throwable $e) {}
    // otp_verify: A2's code checks (per number) - 10 in 10 min, so a few runs in a row would lock P_OLD out
    Database::query("DELETE FROM rate_limits WHERE bucket IN ('login_quick','otp_request_ip','my_bookings','book_create','search','otp_send_login','otp_hourly_login','otp_send_any','otp_verify')
                        AND (identifier IN ('127.0.0.1', '::1') OR identifier REGEXP '900000010[1-4]$')");
    // only this suite's four numbers (other suites use 9000000xxx numbers on the same DB)
    Database::query("DELETE FROM message_logs WHERE to_number REGEXP '900000010[1-4]$'");
    Database::query("DELETE FROM otp_codes WHERE identifier IN ('9000000101','9000000102','9000000103','9000000104')");
};
$cleanup();

// Seed a returning user (wrong spelling on file) and a suspended one.
/* 16 Nov 2026 (T1 refix): the returning number is seeded NOT yet proven
   (phone_verified 0, was 1), so both sides are visible on it: name + mobile
   leaves it unverified, the WhatsApp code (A2) marks it verified. */
Database::insert('users', ['phone' => $P_OLD, 'full_name' => 'Ramesh Thapa', 'role' => 'customer', 'country_code' => '91', 'phone_verified' => 0]);
Database::insert('users', ['phone' => $P_BLK, 'full_name' => 'Blocked Person', 'role' => 'customer', 'country_code' => '91', 'phone_verified' => 1, 'is_blocked' => 1, 'blocked_reason' => 'test']);

echo "A. quick sign-in = name + mobile\n";
$jA = jar('a'); $csrfA = csrfFor($jA);
check('the app shell hands out a csrf token (SHG_BOOT.csrf)', $csrfA !== '');
$r = req($jA, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => $P_NEW, 'name' => '', 'country' => 'IN'], 'csrf' => $csrfA]);
check('new number without a name → 422', $r['code'] === 422, 'HTTP ' . $r['code']);
$r = req($jA, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => $P_NEW, 'name' => 'Sita Kumari', 'country' => 'NP'], 'csrf' => $csrfA]);
check('new number + name → signed in to book, no OTP asked first', $r['code'] === 200 && !empty($r['json']['data']['signedIn']), 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 120));
/* 16 Nov 2026 (battery T1): was "verified and no needsOtp" (the 4 Sep rule). Since
   8 Oct 2026 name + mobile is never proof: the session may book, but it is flagged
   unproven and the app is told the WhatsApp code is still needed for anything private. */
check('  ...but name + mobile is no proof: verified:false, needsOtp:true',
    ($r['json']['data']['verified'] ?? null) === false && ($r['json']['data']['needsOtp'] ?? null) === true,
    json_encode(['verified' => $r['json']['data']['verified'] ?? null, 'needsOtp' => $r['json']['data']['needsOtp'] ?? null]));
check('response carries the name', ($r['json']['data']['user']['name'] ?? '') === 'Sita Kumari');
$u = Database::fetch('SELECT full_name, country_code, phone_verified FROM users WHERE phone = :p', ['p' => $P_NEW]);
check('users row created with the name', $u !== null && $u['full_name'] === 'Sita Kumari', json_encode($u));
check('  ...and the number is NOT marked verified on file', $u !== null && (int) $u['phone_verified'] === 0, 'phone_verified=' . ($u['phone_verified'] ?? '?'));
check('country code stored from the picker (977)', ($u['country_code'] ?? '') === '977');
$cfg = boot($jA);
check('the session now knows the customer (SHG_BOOT.user)', ($cfg['user']['phone'] ?? '') === $P_NEW, json_encode($cfg['user'] ?? null));
check('  ...and hands the checkout the picked country (NP)', ($cfg['user']['country'] ?? '') === 'NP', (string) ($cfg['user']['country'] ?? ''));

$jB = jar('b'); $csrfB = csrfFor($jB);
$r = req($jB, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => $P_OLD, 'name' => '', 'country' => 'IN'], 'csrf' => $csrfB]);
/* 16 Nov 2026 (battery T1): was "signed in WITH the name on file". Since 8 Oct 2026
   an unproven session is never answered with the name on file - that made every
   mobile number a lookup of its owner's name. Signed in, name blank. */
check('returning number, no name typed → signed in', $r['code'] === 200 && !empty($r['json']['data']['signedIn']), 'HTTP ' . $r['code']);
check('  ...and the name on file is NOT disclosed in the reply', ($r['json']['data']['user']['name'] ?? null) === '',
    json_encode($r['json']['data']['user']['name'] ?? null));
/* 16 Nov 2026 (T1 refix): the reply above is built from the TYPED name, so it
   cannot show a leak through the session. The session is what every later
   page load hands the app (Auth::loginUser stores 'name', index.php puts it in
   SHG_BOOT.user.name): read it there too - the same number, still the same
   session, must not have learnt 'Ramesh Thapa'. */
$bt = boot($jB);
check('  ...nor in the session the app boots from (SHG_BOOT.user.name = \'\')',
    ($bt['user']['phone'] ?? '') === $P_OLD && ($bt['user']['name'] ?? null) === '',
    json_encode(['phone' => $bt['user']['phone'] ?? null, 'name' => $bt['user']['name'] ?? null]));
$r = req($jB, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => $P_OLD, 'name' => 'Ramesh Thapa Magar', 'country' => 'NP'], 'csrf' => $csrfB]);
$u = Database::fetch('SELECT full_name, country_code, phone_verified FROM users WHERE phone = :p', ['p' => $P_OLD]);
/* 16 Nov 2026 (battery T1): was "a corrected name replaces the one on file" (4 Sep).
   Since 8 Oct 2026 only a code-proven sign-in may rename (Auth::loginUser $proven);
   name + mobile never renames or re-countries an existing account. The rename
   itself is still checked - on the code-proven door, in A2 below. */
check('a typed name does not rename the account on file (no proof)', ($u['full_name'] ?? '') === 'Ramesh Thapa', (string) ($u['full_name'] ?? ''));
check('  ...nor re-country it (still 91 after a NP pick)', ($u['country_code'] ?? '') === '91', (string) ($u['country_code'] ?? ''));
check('  ...nor mark the existing number verified', $u !== null && (int) $u['phone_verified'] === 0, 'phone_verified=' . ($u['phone_verified'] ?? '?'));
check('  ...while the session keeps the name it typed', $r['code'] === 200 && ($r['json']['data']['user']['name'] ?? '') === 'Ramesh Thapa Magar', 'HTTP ' . $r['code']);
$bt = boot($jB);
check('  ...in the session too (SHG_BOOT.user.name = the typed name, not the one on file)',
    ($bt['user']['name'] ?? null) === 'Ramesh Thapa Magar', json_encode($bt['user']['name'] ?? null));

/* 16 Nov 2026 (T1 refix): the 4 Sep promises "a corrected name replaces the one
   on file" and "a returning number is signed in WITH the name on file" still
   hold - on the door that PROVES the number (api/otp.php 'verify' ->
   Auth::loginUser $proven = true). The code is issued in-process by
   Auth::issueOtp - the very store 'request' writes to (hashed in otp_codes) -
   so this works with APP_DEBUG off and sends no WhatsApp / SMS (the HTTP
   'request' path and its caps are part D). The verify itself goes over HTTP. */
echo "A2. the WhatsApp code proves the number (verify)\n";
$issue = static function (string $phone): string {
    // the one-code-a-minute resend gate is per number: this suite's own number, its own bucket
    Database::query("DELETE FROM rate_limits WHERE bucket = 'otp_send_login' AND identifier = :p", ['p' => $phone]);
    $o = Auth::issueOtp($phone, 'login', 'sms');
    return !empty($o['ok']) ? (string) $o['code'] : '';
};
$jV = jar('v'); $csrfV = csrfFor($jV);
$code1 = $issue($P_OLD);
check('a login code is issued for the returning number', $code1 !== '' && strlen($code1) === OTP_LENGTH);
$bad = str_pad((string) (((int) $code1 + 1) % (10 ** OTP_LENGTH)), OTP_LENGTH, '0', STR_PAD_LEFT);
$r = req($jV, 'POST', '/api/otp.php', ['json' => ['action' => 'verify', 'phone' => $P_OLD, 'code' => $bad, 'name' => 'Wrong Code Name', 'country' => 'NP', 'purpose' => 'login'], 'csrf' => $csrfV]);
$u = Database::fetch('SELECT full_name, country_code, phone_verified FROM users WHERE phone = :p', ['p' => $P_OLD]);
check('a wrong code → 401, and nothing on file changes', $r['code'] === 401 && ($u['full_name'] ?? '') === 'Ramesh Thapa'
    && ($u['country_code'] ?? '') === '91' && (int) ($u['phone_verified'] ?? -1) === 0, 'HTTP ' . $r['code'] . ' ' . json_encode($u));
$r = req($jV, 'POST', '/api/otp.php', ['json' => ['action' => 'verify', 'phone' => $P_OLD, 'code' => $code1, 'name' => 'Ramesh Thapa Magar', 'country' => 'NP', 'purpose' => 'login'], 'csrf' => $csrfV]);
check('the right code signs in, proven (verified:true, needsOtp:false)', $r['code'] === 200 && !empty($r['json']['data']['signedIn'])
    && ($r['json']['data']['verified'] ?? null) === true && ($r['json']['data']['needsOtp'] ?? null) === false,
    'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 160));
$u = Database::fetch('SELECT full_name, country_code, phone_verified FROM users WHERE phone = :p', ['p' => $P_OLD]);
check('  ...the corrected spelling replaces the name on file (it prints on the ticket)', ($u['full_name'] ?? '') === 'Ramesh Thapa Magar', (string) ($u['full_name'] ?? ''));
check('  ...the picked country replaces the one on file (977 after a NP pick)', ($u['country_code'] ?? '') === '977', (string) ($u['country_code'] ?? ''));
check('  ...and the number is marked verified on file', $u !== null && (int) $u['phone_verified'] === 1, 'phone_verified=' . ($u['phone_verified'] ?? '?'));
check('  ...the reply carries the name on file', ($r['json']['data']['user']['name'] ?? null) === ($u['full_name'] ?? '?'), json_encode($r['json']['data']['user']['name'] ?? null));
$bt = boot($jV);
check('  ...and so does the session (SHG_BOOT.user.name)', ($bt['user']['name'] ?? null) === 'Ramesh Thapa Magar', json_encode($bt['user']['name'] ?? null));

// a proven sign-in with NO name typed, in a fresh browser: answered with the name on file, which it keeps
$jW = jar('w'); $csrfW = csrfFor($jW);
$code2 = $issue($P_OLD);
$r = req($jW, 'POST', '/api/otp.php', ['json' => ['action' => 'verify', 'phone' => $P_OLD, 'code' => $code2, 'name' => '', 'purpose' => 'login'], 'csrf' => $csrfW]);
check('returning number, code proven, no name typed → signed in WITH the name on file',
    $r['code'] === 200 && ($r['json']['data']['user']['name'] ?? null) === 'Ramesh Thapa Magar',
    'HTTP ' . $r['code'] . ' ' . json_encode($r['json']['data']['user']['name'] ?? null));
$u = Database::fetch('SELECT full_name, country_code FROM users WHERE phone = :p', ['p' => $P_OLD]);
check('  ...a blank name never wipes the one on file, nor a blank country the 977', ($u['full_name'] ?? '') === 'Ramesh Thapa Magar' && ($u['country_code'] ?? '') === '977', json_encode($u));
$bt = boot($jW);
check('  ...and the session boots with it (SHG_BOOT.user.name)', ($bt['user']['name'] ?? null) === 'Ramesh Thapa Magar', json_encode($bt['user']['name'] ?? null));

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
check('  ...flagged needsOtp (the WhatsApp code unlocks the whole number)', ($r['json']['data']['needsOtp'] ?? null) === true);

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
        // 8 Oct 2026: a name + mobile session lists the tickets THIS browser booked - this one
        check('my-bookings lists it (the ticket this browser booked)', count($list) === 1 && ($list[0]['pnr'] ?? '') === $pnr, 'HTTP ' . $r['code'] . ' n=' . count($list));
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
Database::query("DELETE FROM rate_limits WHERE bucket = 'otp_request_ip' AND identifier IN ('127.0.0.1', '::1')");
/* 16 Nov 2026 (battery T1): the global paid-send ceiling (otp_send_global_h / _d,
   identifier 'global') counts these 11 requests too; put it back as it was. */
$globalPrior = [];
foreach (['otp_send_global_h', 'otp_send_global_d'] as $gb) {
    $globalPrior[$gb] = Database::fetch("SELECT hits FROM rate_limits WHERE bucket = :b AND identifier = 'global'", ['b' => $gb]);
}
$jD = jar('d'); $csrfD = csrfFor($jD);
$codes = [];
for ($i = 1; $i <= 11; $i++) {
    $ph = '90000009' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);   // a different number each time
    $r = req($jD, 'POST', '/api/otp.php', ['json' => ['action' => 'request', 'phone' => $ph, 'purpose' => 'login', 'country' => 'IN'], 'csrf' => $csrfD]);
    $codes[$i] = $r['code'];
}
// 16 Nov 2026 (battery T1): "never 500" alone passed on 11 x 419 (no CSRF token) - the requests must really be served
check('first 10 requests are served (200 or a provider 429, never 500 / 419)',
    array_diff(array_slice($codes, 0, 10, true), [200, 429]) === [], json_encode($codes));
check('11th request from the same IP → 429 (per-IP cap)', $codes[11] === 429, 'HTTP ' . $codes[11]);
$leak = Database::fetch("SELECT COUNT(*) AS n FROM message_logs WHERE to_number LIKE '%9000000%' AND body REGEXP 'code[^0-9]{0,12}[0-9]{4,8}'");
check('no OTP digits stored in message_logs', (int) ($leak['n'] ?? 0) === 0, 'rows=' . ($leak['n'] ?? '?'));
Database::query("DELETE FROM otp_codes WHERE identifier LIKE '90000009%'");
Database::query("DELETE FROM rate_limits WHERE (bucket IN ('otp_send_login','otp_hourly_login','otp_send_any') AND identifier LIKE '%90000009%')
                    OR (bucket = 'otp_request_ip' AND identifier IN ('127.0.0.1', '::1'))");
foreach ($globalPrior as $gb => $prior) {
    if ($prior === null) {
        Database::query("DELETE FROM rate_limits WHERE bucket = :b AND identifier = 'global'", ['b' => $gb]);
    } else {
        Database::query("UPDATE rate_limits SET hits = :h WHERE bucket = :b AND identifier = 'global'", ['h' => (int) $prior['hits'], 'b' => $gb]);
    }
}
Database::query("DELETE FROM message_logs WHERE to_number LIKE '%90000009%'");

$cleanup();
foreach (['a', 'b', 'c', 'd', 'g', 'v', 'w'] as $n) { @unlink(sys_get_temp_dir() . '/shg_login_' . $n . '_' . getmypid() . '.cookies'); }
// 16 Nov 2026 (T1 refix): nothing this run made for its numbers may outlive it (A2 added codes + a verify bucket)
$inPh = implode(',', array_fill(0, count($allPhones), '?'));
$left = [
    'users'        => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE phone IN ({$inPh})", $allPhones, 0),
    'otp_codes'    => (int) Database::scalar("SELECT COUNT(*) FROM otp_codes WHERE identifier IN ({$inPh}) OR identifier LIKE '90000009%'", $allPhones, 0),
    'rate_limits'  => (int) Database::scalar("SELECT COUNT(*) FROM rate_limits WHERE identifier REGEXP '900000010[1-4]$' OR identifier LIKE '%90000009%'", [], 0),
    'bookings'     => (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone IN ({$inPh})", $allPhones, 0),
];
check('cleanup leaves no user, code, throttle or booking row for its numbers', array_sum($left) === 0, json_encode($left));
echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
