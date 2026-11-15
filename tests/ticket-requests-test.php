<?php
/**
 * =====================================================================
 *  ticket-requests-test.php — P6 (15 Nov 2026): the passenger's
 *  "change my date" / "cancel a seat" REQUESTS.
 *
 *  Pure part:
 *    - the date window (tomorrow .. ticket_request_max_days), wa.me
 *      digits, seat labels
 *
 *  Database part (shari_test only; every row it makes is deleted):
 *    - api/ticket-request.php: GET refused, bad CSRF refused, a PNR
 *      without its signed key / with a wrong key refused (same neutral
 *      403), past departure refused, cancelled ticket refused, a seat
 *      that is not on the ticket refused, dates outside the window and
 *      the current date refused
 *    - a good date-change, one-seat and whole-ticket request each write
 *      one open row + an audit row + one office bell row (link to
 *      /admin/ticket-requests.php), and the 4th open request is refused
 *    - the booking, its legs and its passengers are byte-for-byte the
 *      same after every request and after the office closes them
 *    - rate limits: per visitor IP and per ticket answer 429
 *    - seat change (P8-3e, 16 Nov 2026): no seat / not a label / more seats
 *      than the ticket refused; a good one writes one open row with the
 *      wanted labels + bell + audit, the booking unchanged; the ticket page
 *      and the office page show it
 *    - ticket/view.php shows the two buttons, the refund-rule link and
 *      the request list; a past ticket shows no buttons; at the cap the
 *      buttons give way to the "waiting" line
 *    - admin/ticket-requests.php: bad CSRF refused (no change, no audit),
 *      Done / Reject (reject needs a note) close the request with an
 *      audit row, a closed request cannot be closed again, support may
 *      read but not close, an agent cannot open the page, the menu
 *      carries the page
 *    - without the table: refusal 'off', the API answers 503, the page
 *      shows no buttons (table renamed for a moment, put back after)
 *
 *    php -c .claude/php-dev.ini tests/ticket-requests-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: one request in its own PHP process --------------------
   argv: --child <base64 json {what: api|view|admin, ...}> */
if (($argv[1] ?? '') === '--child') {
    $cfg  = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $what = (string) ($cfg['what'] ?? '');
    $_SERVER['HTTP_HOST']   = 'localhost';
    $_SERVER['REMOTE_ADDR'] = (string) ($cfg['ip'] ?? '203.0.113.60');
    $_GET  = (array) ($cfg['get'] ?? []);
    $post  = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = (string) ($cfg['method'] ?? ($post === [] ? 'GET' : 'POST'));
    if ($what === 'api') {
        $_SERVER['REQUEST_URI']  = '/api/ticket-request.php';
        $_SERVER['SCRIPT_NAME']  = '/api/ticket-request.php';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = $post;
        $_SESSION = [(string) $cfg['csrf_name'] => 'p6-session-token'];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = (string) ($cfg['csrf'] ?? 'p6-session-token');
        require dirname(__DIR__) . '/api/ticket-request.php';
        exit(0);
    }
    if ($what === 'view') {
        $_SERVER['REQUEST_URI'] = '/ticket/view.php';
        $_SERVER['SCRIPT_NAME'] = '/ticket/view.php';
        $_SESSION = [];
        require dirname(__DIR__) . '/ticket/view.php';
        exit(0);
    }
    // admin page
    $_SERVER['SCRIPT_NAME'] = '/admin/ticket-requests.php';
    $_SERVER['REQUEST_URI'] = '/admin/ticket-requests.php';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    /* counter desk isolation for THIS process only (the shared settings row is never written) */
    if (isset($cfg['desk_iso'])) {
        Settings::get('counter_desk_isolation');
        $rp = new ReflectionProperty(Settings::class, 'cache');
        $c  = (array) $rp->getValue();
        $c['counter_desk_isolation'] = ['value' => $cfg['desk_iso'] ? '1' : '0', 'type' => 'bool', 'public' => false];
        $rp->setValue(null, $c);
    }
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME]  = ($cfg['csrf'] ?? 'valid') === 'valid' ? Security::csrfToken() : 'not-the-token';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    }
    require dirname(__DIR__) . '/admin/ticket-requests.php';
    exit(0);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/ticketrequests.php';

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
/** Run one child; returns its raw output. */
function child(array $cfg): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
}
/** POST the API as a passenger from $ip; returns the decoded JSON (or ['raw' => output]). */
function api(array $post, string $ip, string $csrf = 'p6-session-token', string $method = 'POST'): array {
    $out = child(['what' => 'api', 'post' => $post, 'ip' => $ip, 'csrf' => $csrf, 'csrf_name' => CSRF_TOKEN_NAME, 'method' => $method]);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['raw' => substr($out, 0, 300)];
}

echo "\n=== P6 ticket requests ===\n\n";

/* ---- 1. Pure part ------------------------------------------------------ */
echo "-- Date window, WhatsApp digits, seat labels\n";
$fixedNow = (int) strtotime('2026-11-15 10:00:00');
$win = TicketRequests::dateWindow($fixedNow);
$max = TicketRequests::maxDays();
check('window opens tomorrow', $win['from'] === '2026-11-16', $win['from']);
check('window closes today + ticket_request_max_days', $win['to'] === date('Y-m-d', (int) strtotime('2026-11-15 +' . $max . ' days')), $win['to'] . " (max $max)");
check('max days is clamped to 1..365', $max >= 1 && $max <= 365);
check('10-digit phone takes the ticket country code', TicketRequests::waDigits('9812345678', '977') === '9779812345678');
check('10-digit phone without a code falls back to 91 (as the booking page)', TicketRequests::waDigits('98123 45678', '') === '919812345678');
check('a full international number is kept', TicketRequests::waDigits('+977 9812345678', '91') === '9779812345678');
check('junk is no WhatsApp link', TicketRequests::waDigits('12', '91') === '');
check('stored seats print as the ticket labels', TicketRequests::seatLabels('L1,L2', 'sharing') === Seats::displayLabels(['L1', 'L2'], 'sleeper', 'sharing'), TicketRequests::seatLabels('L1,L2', 'sharing'));

/* ---- 2. Database part -------------------------------------------------- */
echo "\n-- Database part\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
    echo "  \033[33mSKIP\033[0m  database part: DB_NAME is not shari_test — refusing to write\n";
    finish();
}
TicketRequests::resetReady();
if (!TicketRequests::ready()) {
    echo "  \033[31mFAIL\033[0m  ticket_requests table missing: run tests/apply-sql.php database/upgrade-2026-11-15-ticket-requests.sql\n";
    $FAIL++;
    finish();
}
$super = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);
$route = Database::fetch('SELECT id, dep_time FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1');
if ($super <= 0 || $route === null) {
    echo "  \033[33mSKIP\033[0m  database part: needs an active super-admin and an active route\n";
    finish();
}

$tag      = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$IPS      = [];
$ip       = static function (int $n) use (&$IPS): string { $a = '203.0.113.' . (100 + $n); $IPS[$a] = true; return $a; };
$startAudit = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$startNote  = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM notifications', [], 0);
$made = ['sched' => [], 'book' => [], 'admin' => [], 'link' => [], 'profile' => []];
$renamed = false;
$savedOn = Database::fetch("SELECT svalue FROM settings WHERE skey = 'ticket_requests_on'");

$cleanup = static function () use (&$made, &$IPS, &$renamed, $startAudit, $startNote, $savedOn): void {
    if ($renamed) {
        try { Database::query('RENAME TABLE ticket_requests_p6hold TO ticket_requests'); } catch (Throwable $e) { echo "  !! could not put ticket_requests back: " . $e->getMessage() . "\n"; }
        $renamed = false;
    }
    foreach ($made['book'] as $bid) {
        try { Database::delete('ticket_requests', 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        Database::delete('notifications', 'id > :s AND booking_id = :b', ['s' => $startNote, 'b' => $bid]);
        Database::delete('ticket_links', 'booking_id = :b', ['b' => $bid]);
        Database::delete('rate_limits', "bucket = 'ticket_request_bk' AND identifier = :i", ['i' => (string) $bid]);
        Database::delete('bookings', 'id = :b', ['b' => $bid]);   // legs + passengers cascade
    }
    foreach ($made['sched'] as $sid) {
        Database::delete('schedules', 'id = :s', ['s' => $sid]);
    }
    foreach ($made['profile'] as $aid) {
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aid]);
    }
    foreach ($made['admin'] as $aid) {
        try { Database::delete('kv_store', "kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $aid]); } catch (Throwable $e) {}
        Database::delete('admins', 'id = :a', ['a' => $aid]);
    }
    foreach (array_keys($IPS) as $addr) {
        Database::delete('rate_limits', 'identifier = :i', ['i' => $addr]);
    }
    Database::delete('audit_logs', "id > :s AND action LIKE 'ticket_request.%' AND entity_id LIKE 'SHG-TRQ%'", ['s' => $startAudit]);
    Database::delete('notifications', "id > :s AND link = '/admin/ticket-requests.php' AND title LIKE '%SHG-TRQ%'", ['s' => $startNote]);
    if ($savedOn !== null) {
        Database::update('settings', ['svalue' => $savedOn['svalue']], "skey = 'ticket_requests_on'");
        Settings::flush();
    }
};

/** A blocked (hidden) schedule on $date for the fixture route, in a free slot. */
$mkSched = static function (string $date) use (&$made, $route): int {
    for ($slot = 97; $slot >= 80; $slot--) {
        if (Database::scalar('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = :s', ['r' => (int) $route['id'], 'd' => $date, 's' => $slot]) === null) {
            $id = Database::insert('schedules', ['route_id' => (int) $route['id'], 'travel_date' => $date, 'slot' => $slot,
                'dep_time_override' => '13:00:00', 'total_seats' => 40, 'status' => 'scheduled', 'is_blocked' => 1]);
            $made['sched'][] = $id;
            return $id;
        }
    }
    throw new RuntimeException('no free slot for a fixture schedule on ' . $date);
};
/** A throwaway booking with two passengers on L1 / L2. */
$mkBooking = static function (string $suffix, int $schedId, string $date, string $status) use (&$made, $tag): array {
    $pnr = 'SHG-TRQ-' . $tag . '-' . $suffix;
    $id  = Database::insert('bookings', ['pnr' => $pnr, 'contact_phone' => '9812345678', 'contact_country_code' => '977',
        'booking_mode' => 'sharing', 'fare_per_seat' => 2500, 'base_total' => 5000, 'total_amount' => 5000,
        'status' => $status, 'source' => 'admin', 'confirmed_at' => date('Y-m-d H:i:s')]);
    $made['book'][] = $id;
    $leg = Database::insert('booking_legs', ['booking_id' => $id, 'schedule_id' => $schedId, 'travel_date' => $date,
        'boarding_stop' => 'Surat', 'drop_stop' => 'Rupaidiha', 'fare_per_seat' => 2500, 'seat_count' => 2, 'leg_total' => 5000]);
    foreach (['L1' => 'Ram Bahadur Test', 'L2' => 'Sita Kumari Test'] as $seat => $name) {
        Database::insert('booking_passengers', ['booking_id' => $id, 'leg_id' => $leg, 'passenger_ref' => 'TRQ' . $tag . $suffix . $seat,
            'seat_no' => $seat, 'full_name' => $name, 'gender' => 'Other', 'is_primary' => $seat === 'L1' ? 1 : 0]);
    }
    return ['id' => $id, 'pnr' => $pnr, 'k' => Ticket::downloadToken($pnr)];
};
/** Everything a request must never change: the booking row, its legs, its passengers. */
$snap = static function (int $bid): string {
    return json_encode([
        Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $bid]),
        Database::fetchAll('SELECT * FROM booking_legs WHERE booking_id = :b ORDER BY id', ['b' => $bid]),
        Database::fetchAll('SELECT * FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => $bid]),
    ]) ?: '';
};
$rowsOf = static fn(int $bid): array => Database::fetchAll('SELECT * FROM ticket_requests WHERE booking_id = :b ORDER BY id', ['b' => $bid]);
$mkLink = static function (int $bid) use (&$made): string {
    $abc = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $code = '';
    for ($i = 0; $i < 10; $i++) { $code .= $abc[random_int(0, strlen($abc) - 1)]; }
    $made['link'][] = Database::insert('ticket_links', ['booking_id' => $bid, 'short_code' => $code, 'full_url' => 'https://example.invalid/t',
        'expires_at' => date('Y-m-d H:i:s', time() + 7 * 86400)]);
    return $code;
};

try {
    // the buttons must be ON for this test (restored after)
    if ($savedOn !== null && (string) $savedOn['svalue'] !== '1') {
        Database::update('settings', ['svalue' => '1'], "skey = 'ticket_requests_on'");
        Settings::flush();
    }
    $future = date('Y-m-d', strtotime('+5 days'));
    $past   = date('Y-m-d', strtotime('-2 days'));
    $sFut   = $mkSched($future);
    $sPast  = $mkSched($past);
    $B1 = $mkBooking('A', $sFut, $future, 'confirmed');     // the happy path
    $B2 = $mkBooking('P', $sPast, $past, 'confirmed');      // bus already left
    $B3 = $mkBooking('C', $sFut, $future, 'cancelled');     // cancelled ticket
    $B4 = $mkBooking('R', $sFut, $future, 'confirmed');     // rate-limit ticket
    $snapB1 = $snap($B1['id']);
    $wanted = date('Y-m-d', strtotime('+9 days'));

    /* 2a. who may ask */
    echo "\n-- The ticket key, CSRF and the method\n";
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'change_date', 'wanted_date' => $wanted], $ip(1), 'p6-session-token', 'GET');
    check('GET is refused (405)', ($r['code'] ?? 0) === 405, json_encode($r));
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'change_date', 'wanted_date' => $wanted], $ip(1), 'stale-token');
    check('a bad CSRF token is refused (419)', ($r['code'] ?? 0) === 419, json_encode($r));
    $r = api(['pnr' => $B1['pnr'], 'kind' => 'change_date', 'wanted_date' => $wanted], $ip(1));
    check('the PNR alone (no key) is refused (403)', ($r['code'] ?? 0) === 403 && empty($r['ok']), json_encode($r));
    $r = api(['pnr' => $B1['pnr'], 'k' => 'AAAAAAAAAAAAAAAAAAAA', 'kind' => 'change_date', 'wanted_date' => $wanted], $ip(1));
    $r2 = api(['pnr' => 'SHG-TRQ-NOSUCH-' . $tag, 'k' => 'AAAAAAAAAAAAAAAAAAAA', 'kind' => 'change_date', 'wanted_date' => $wanted], $ip(1));
    check('a wrong key is refused (403)', ($r['code'] ?? 0) === 403, json_encode($r));
    check('an unknown PNR gets the very same answer (no PNR probing)', ($r2['code'] ?? 0) === 403 && ($r2['error'] ?? 'x') === ($r['error'] ?? 'y'));
    $r = api(['pnr' => $B2['pnr'], 'k' => $B1['k'], 'kind' => 'change_date', 'wanted_date' => $wanted], $ip(1));
    check("another ticket's key does not open this one (403)", ($r['code'] ?? 0) === 403);
    check('nothing was written by the refused calls', $rowsOf($B1['id']) === [] && $rowsOf($B2['id']) === []);

    /* 2b. what may be asked */
    echo "\n-- The rules\n";
    $r = api(['pnr' => $B2['pnr'], 'k' => $B2['k'], 'kind' => 'change_date', 'wanted_date' => $wanted], $ip(2));
    check('the bus already left: refused (409)', ($r['code'] ?? 0) === 409 && str_contains((string) ($r['error'] ?? ''), 'already left'), json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = api(['pnr' => $B3['pnr'], 'k' => $B3['k'], 'kind' => 'cancel_seats', 'whole' => '1'], $ip(2));
    check('a cancelled ticket: refused (409)', ($r['code'] ?? 0) === 409 && str_contains((string) ($r['error'] ?? ''), 'cancelled'), json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'cancel_seats', 'seats' => ['L1', 'L9']], $ip(2));
    check('a seat that is not on this ticket: refused (422)', ($r['code'] ?? 0) === 422 && isset($r['fields']['seats']), json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'cancel_seats', 'seats' => 'L1,X;1'], $ip(2));
    check('a malformed seat id: refused (422)', ($r['code'] ?? 0) === 422);
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'cancel_seats'], $ip(2));
    check('no seat ticked: refused (422)', ($r['code'] ?? 0) === 422);
    // (each refused call above still counts against the ticket's budget - start it again)
    Database::delete('rate_limits', "bucket = 'ticket_request_bk' AND identifier = :i", ['i' => (string) $B1['id']]);
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'change_date', 'wanted_date' => date('Y-m-d')], $ip(7));
    check('today is outside the window (from tomorrow): refused (422)', ($r['code'] ?? 0) === 422 && isset($r['fields']['wanted_date']));
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'change_date', 'wanted_date' => date('Y-m-d', strtotime('+' . ($max + 1) . ' days'))], $ip(7));
    check('past the last allowed day: refused (422)', ($r['code'] ?? 0) === 422);
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'change_date', 'wanted_date' => $future], $ip(7));
    check('the date the ticket already has: refused (422)', ($r['code'] ?? 0) === 422 && str_contains((string) ($r['error'] ?? ''), 'already your travel date'));
    Database::delete('rate_limits', "bucket = 'ticket_request_bk' AND identifier = :i", ['i' => (string) $B1['id']]);
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'refund_me'], $ip(7));
    check('an unknown kind: refused (422)', ($r['code'] ?? 0) === 422);
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'change_date', 'wanted_date' => $wanted, 'contact_phone' => '12345'], $ip(7));
    check('a phone that is not a phone: refused (422)', ($r['code'] ?? 0) === 422 && isset($r['fields']['contact_phone']));
    check('still nothing written', $rowsOf($B1['id']) === [] && $rowsOf($B2['id']) === [] && $rowsOf($B3['id']) === []);
    Database::delete('rate_limits', "bucket = 'ticket_request_bk' AND identifier = :i", ['i' => (string) $B1['id']]);

    /* 2c. good requests */
    echo "\n-- Good requests\n";
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'change_date', 'wanted_date' => $wanted, 'note' => 'Morning bus <b>please</b>'], $ip(3));
    $rows = $rowsOf($B1['id']);
    check('date change accepted', !empty($r['ok']) && (int) ($r['data']['id'] ?? 0) > 0, json_encode($r, JSON_UNESCAPED_UNICODE));
    check('one open row with the wanted date, the ticket phone and the IP',
        count($rows) === 1 && $rows[0]['status'] === 'open' && $rows[0]['kind'] === 'change_date' && $rows[0]['wanted_date'] === $wanted
        && $rows[0]['contact_phone'] === '9812345678' && $rows[0]['ip'] === '203.0.113.103' && $rows[0]['pnr'] === $B1['pnr'], json_encode($rows[0] ?? null));
    check('the note is stored as typed (escaped only at output)', ($rows[0]['note'] ?? '') === 'Morning bus <b>please</b>');
    $bell = Database::fetchAll("SELECT * FROM notifications WHERE id > :s AND link = '/admin/ticket-requests.php' AND title LIKE :t", ['s' => $startNote, 't' => '%' . $B1['pnr'] . '%']);
    check('the office bell rang once, linked to the requests page and the booking',
        count($bell) === 1 && $bell[0]['audience'] === 'admin' && (int) $bell[0]['booking_id'] === $B1['id'] && (int) $bell[0]['is_read'] === 0, (string) count($bell));
    check('the audit log has the request', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action = 'ticket_request.create' AND entity_id = :p", ['s' => $startAudit, 'p' => $B1['pnr']], 0) === 1);
    check('the booking, legs and passengers are unchanged', $snap($B1['id']) === $snapB1);

    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'cancel_seats', 'seats' => ['L2'], 'contact_phone' => '+977 98000 11223'], $ip(4));
    $rows = $rowsOf($B1['id']);
    check('one-seat cancel accepted with the typed phone', !empty($r['ok']) && ($rows[1]['seats'] ?? '') === 'L2' && ($rows[1]['contact_phone'] ?? '') === '9779800011223', json_encode($rows[1] ?? null));
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'cancel_seats', 'whole' => '1'], $ip(5));
    $rows = $rowsOf($B1['id']);
    check('whole-ticket cancel stores every seat', !empty($r['ok']) && ($rows[2]['seats'] ?? '') === 'L1,L2', json_encode($rows[2] ?? null));
    check('three bell rows for three requests', (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE id > :s AND booking_id = :b AND link = '/admin/ticket-requests.php'", ['s' => $startNote, 'b' => $B1['id']], 0) === 3);
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => 'change_date', 'wanted_date' => $wanted], $ip(6));
    check('a 4th open request is refused (cap ' . TicketRequests::MAX_OPEN . ')', ($r['code'] ?? 0) === 409 && count($rowsOf($B1['id'])) === 3, json_encode($r, JSON_UNESCAPED_UNICODE));
    $why = TicketRequests::refusal(Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $B1['id']]) ?? []);
    check('refusal() names the cap', ($why['reason'] ?? '') === 'cap');
    check('the booking is still unchanged after three requests', $snap($B1['id']) === $snapB1);
    // review 15 Nov: a field sent as a list is read as blank - a plain refusal, never a 500
    $r = api(['pnr' => [$B1['pnr']], 'k' => $B1['k'], 'kind' => 'change_date', 'wanted_date' => $wanted], $ip(8));
    check('pnr[] (a list) is refused like a missing key (403), not a 500', ($r['code'] ?? 0) === 403, json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = api(['pnr' => $B1['pnr'], 'k' => $B1['k'], 'kind' => ['change_date'], 'wanted_date' => $wanted, 'seats' => [['L1']]], $ip(8));
    check('kind[] / nested seats get the rule answer (here the cap, 409), not a 500', ($r['code'] ?? 0) === 409 && count($rowsOf($B1['id'])) === 3, json_encode($r, JSON_UNESCAPED_UNICODE));

    /* 2d. rate limits */
    echo "\n-- Rate limits\n";
    $ipX = $ip(20);
    Database::insert('rate_limits', ['bucket' => 'ticket_request', 'identifier' => $ipX, 'hits' => TicketRequests::RL_IP[0], 'window_start' => date('Y-m-d H:i:s')]);
    $r = api(['pnr' => $B4['pnr'], 'k' => $B4['k'], 'kind' => 'change_date', 'wanted_date' => $wanted], $ipX);
    check('one visitor IP past its budget gets 429', ($r['code'] ?? 0) === 429, json_encode($r, JSON_UNESCAPED_UNICODE));
    Database::delete('rate_limits', "bucket = 'ticket_request_bk' AND identifier = :i", ['i' => (string) $B4['id']]);
    Database::insert('rate_limits', ['bucket' => 'ticket_request_bk', 'identifier' => (string) $B4['id'], 'hits' => TicketRequests::RL_BOOKING[0], 'window_start' => date('Y-m-d H:i:s')]);
    $r = api(['pnr' => $B4['pnr'], 'k' => $B4['k'], 'kind' => 'change_date', 'wanted_date' => $wanted], $ip(21));
    check('one ticket past its budget gets 429 from a fresh IP', ($r['code'] ?? 0) === 429 && str_contains((string) ($r['error'] ?? ''), 'this ticket'), json_encode($r, JSON_UNESCAPED_UNICODE));
    check('no row for the rate-limited ticket', $rowsOf($B4['id']) === []);

    /* 2e. the ticket page */
    echo "\n-- Ticket page\n";
    $code4 = $mkLink($B4['id']);
    $v = child(['what' => 'view', 'get' => ['code' => $code4], 'ip' => $ip(30)]);
    check('a confirmed future ticket shows both request buttons', str_contains($v, 'Request date change') && str_contains($v, 'Request seat cancel'));
    check('it says it is only a request and links the refund rules', str_contains($v, 'only a <b>request</b>') && str_contains($v, 'href="/refund-policy"'));
    check('the forms carry the signed key and the CSRF token, never the phone',
        str_contains($v, 'data-k="' . $B4['k'] . '"') && str_contains($v, 'data-csrf="') && !str_contains($v, '9812345678'));
    check('the date picker runs tomorrow .. the last allowed day',
        str_contains($v, 'min="' . TicketRequests::dateWindow()['from'] . '"') && str_contains($v, 'max="' . TicketRequests::dateWindow()['to'] . '"'));
    check('each seat is a tick box, plus "whole ticket"', substr_count($v, 'type="checkbox" name="seats[]"') === 2 && str_contains($v, 'type="checkbox" name="whole"'));
    check('no PHP warning on the page', !preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $v));
    $code1 = $mkLink($B1['id']);
    $v1 = child(['what' => 'view', 'get' => ['code' => $code1], 'ip' => $ip(31)]);
    check('at the cap the buttons give way to the waiting line', !str_contains($v1, 'Request date change') && str_contains($v1, 'requests waiting'));
    check('the request list shows all three with their status', str_contains($v1, 'Your requests') && substr_count($v1, 'With the office') === 3 && str_contains($v1, 'whole ticket'));
    check("the passenger's note is not echoed raw", !str_contains($v1, '<b>please</b>'));
    check('dark theme: the status pills get readable colours', str_contains($v1, '.rq-list .badge.ok{color:#5fd39a}') && str_contains($v1, '.rq-list .badge.wait{color:#ffc56b}'));
    $code2 = $mkLink($B2['id']);
    $v2 = child(['what' => 'view', 'get' => ['code' => $code2], 'ip' => $ip(32)]);
    check('a ticket whose bus left shows no request buttons', !str_contains($v2, 'Request date change') && !str_contains($v2, 'id="treq"') && str_contains($v2, 'CONFIRMED'));

    /* 2e2. seat change (P8-3e, 16 Nov 2026) - a request row only, like the other two */
    echo "\n-- Seat change requests\n";
    TicketRequests::resetReady();
    check('the seat-change migration has run (kind change_seat + wanted_seats)', TicketRequests::seatChangeReady(),
        'run tests/apply-sql.php database/upgrade-2026-11-16-ticket-seat-change.sql');
    check('wanted seats are read as printed labels, each once', TicketRequests::parseWantedSeats(' a5, A6 a5 ') === ['A5', 'A6']
        && TicketRequests::parseWantedSeats('A5; <b>') === null && TicketRequests::parseWantedSeats('   ') === []);
    $B6 = $mkBooking('S', $sFut, $future, 'confirmed');
    $snapB6 = $snap($B6['id']);
    $r = api(['pnr' => $B6['pnr'], 'k' => $B6['k'], 'kind' => 'change_seat', 'wanted_seats' => ''], $ip(50));
    check('no seat written: refused (422)', ($r['code'] ?? 0) === 422 && isset($r['fields']['wanted_seats']), json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = api(['pnr' => $B6['pnr'], 'k' => $B6['k'], 'kind' => 'change_seat', 'wanted_seats' => 'A5 <script>'], $ip(50));
    check('something that is not a seat label: refused (422)', ($r['code'] ?? 0) === 422 && isset($r['fields']['wanted_seats']));
    $r = api(['pnr' => $B6['pnr'], 'k' => $B6['k'], 'kind' => 'change_seat', 'wanted_seats' => 'A1, A2, A3'], $ip(50));
    check('more seats than the ticket holds: refused (422)', ($r['code'] ?? 0) === 422 && str_contains((string) ($r['error'] ?? ''), 'at most 2'), json_encode($r, JSON_UNESCAPED_UNICODE));
    check('nothing written by the refused seat-change calls', $rowsOf($B6['id']) === []);
    Database::delete('rate_limits', "bucket = 'ticket_request_bk' AND identifier = :i", ['i' => (string) $B6['id']]);
    $r = api(['pnr' => $B6['pnr'], 'k' => $B6['k'], 'kind' => 'change_seat', 'wanted_seats' => 'a5, a6', 'note' => 'Window side <i>please</i>'], $ip(51));
    $rows = $rowsOf($B6['id']);
    check('seat change accepted: one open row with the wanted labels',
        !empty($r['ok']) && count($rows) === 1 && $rows[0]['kind'] === 'change_seat' && $rows[0]['wanted_seats'] === 'A5,A6'
        && $rows[0]['seats'] === null && $rows[0]['wanted_date'] === null && $rows[0]['status'] === 'open', json_encode($rows[0] ?? $r, JSON_UNESCAPED_UNICODE));
    $bell6 = Database::fetchAll("SELECT * FROM notifications WHERE id > :s AND booking_id = :b AND link = '/admin/ticket-requests.php'", ['s' => $startNote, 'b' => $B6['id']]);
    check('the office bell names the seat change and the wanted seats',
        count($bell6) === 1 && str_contains((string) $bell6[0]['title'], 'Seat change request') && str_contains((string) $bell6[0]['body'] . (string) ($bell6[0]['message'] ?? ''), 'A5, A6'), json_encode($bell6[0] ?? null, JSON_UNESCAPED_UNICODE));
    check('the audit log has the seat-change request', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action = 'ticket_request.create' AND entity_id = :p", ['s' => $startAudit, 'p' => $B6['pnr']], 0) === 1);
    check('the booking, legs and passengers are unchanged by a seat-change request', $snap($B6['id']) === $snapB6);
    $code6 = $mkLink($B6['id']);
    $v6 = child(['what' => 'view', 'get' => ['code' => $code6], 'ip' => $ip(52)]);
    check('the ticket page offers the seat-change form', str_contains($v6, 'Request seat change') && str_contains($v6, 'name="wanted_seats"') && str_contains($v6, 'data-kind="change_seat"'));
    check('the request list shows the wanted seats', str_contains($v6, 'सिट परिवर्तन · Seat change') && str_contains($v6, '→ A5, A6'));
    check('no PHP warning on the page with a seat-change request', !preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $v6));

    /* 2f. the office page */
    echo "\n-- Office page\n";
    $rows   = $rowsOf($B1['id']);
    $idDate = (int) $rows[0]['id'];
    $idSeat = (int) $rows[1]['id'];
    $idAll  = (int) $rows[2]['id'];
    $page = child(['what' => 'admin', 'admin' => $super]);
    check('the open list shows the PNR with a link to the booking', str_contains($page, 'booking-view.php?pnr=' . urlencode($B1['pnr'])) && str_contains($page, 'id="tr-' . $idDate . '"'));
    check('tap-to-call and WhatsApp links (ticket country code)', str_contains($page, 'href="tel:+9779812345678"') && str_contains($page, 'https://wa.me/9779812345678'));
    check('the wanted date and the seats are shown', str_contains($page, formatDate($wanted, 'D, j M Y')) && str_contains($page, 'whole ticket'));
    check('a seat change shows its label, the wanted seats and the seats held now',
        str_contains($page, 'सिट परिवर्तन · Seat change') && str_contains($page, 'Wants seat: <b>A5, A6</b>')
        && str_contains($page, 'now: ' . TicketRequests::seatLabels('L1,L2', 'sharing')) && str_contains($page, 'Window side &lt;i&gt;please&lt;/i&gt;'));
    check("the passenger's note is escaped", str_contains($page, 'Morning bus &lt;b&gt;please&lt;/b&gt;') && !str_contains($page, 'Morning bus <b>please</b>'));
    check('the menu carries the page', str_contains($page, 'ticket-requests.php"'));
    check('no PHP warning on the office page', !preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $page));
    check('Enter in the staff note does not submit (it would press Done)', str_contains($page, "onkeydown=\"if(event.key==='Enter'){event.preventDefault();}\""));
    $odd = child(['what' => 'admin', 'admin' => $super, 'get' => ['status' => ['open']]]);
    check('?status[]= (a list) opens the Open tab, not a 500', str_contains($odd, 'id="tr-' . $idDate . '"') && !preg_match('/(Warning|Fatal error|Something went wrong)/', $odd));

    $auditCount = static fn(string $action): int => (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action = :a AND entity_id = :p", ['s' => $startAudit, 'a' => $action, 'p' => $B1['pnr']], 0);
    $o = child(['what' => 'admin', 'admin' => $super, 'post' => ['action' => 'done', 'id' => $idDate, 'staff_note' => 'moved'], 'csrf' => 'bad']);
    $st = (string) Database::scalar('SELECT status FROM ticket_requests WHERE id = :i', ['i' => $idDate], '');
    check('Done with a bad CSRF token is refused: still open, no audit row', str_contains($o, 'Session expired') && $st === 'open' && $auditCount('ticket_request.done') === 0);
    $o = child(['what' => 'admin', 'admin' => $super, 'post' => ['action' => 'done', 'id' => $idDate, 'staff_note' => 'Moved to ' . $wanted . ' on the booking page, fare same']]);
    $row = Database::fetch('SELECT * FROM ticket_requests WHERE id = :i', ['i' => $idDate]) ?? [];
    check('Done closes it with the note, who and when', ($row['status'] ?? '') === 'done' && (int) ($row['handled_by'] ?? 0) === $super
        && !empty($row['handled_at']) && str_contains((string) ($row['staff_note'] ?? ''), 'Moved to'), json_encode($row));
    check('Done is in the audit log', $auditCount('ticket_request.done') === 1);
    $o = child(['what' => 'admin', 'admin' => $super, 'post' => ['action' => 'reject', 'id' => $idSeat, 'staff_note' => '']]);
    check('Reject without a note is refused', str_contains($o, 'Write why') && (string) Database::scalar('SELECT status FROM ticket_requests WHERE id = :i', ['i' => $idSeat], '') === 'open');
    $o = child(['what' => 'admin', 'admin' => $super, 'post' => ['action' => 'reject', 'id' => $idSeat, 'staff_note' => 'Passenger called back, keeps both seats']]);
    check('Reject with a note closes it + audit row', (string) Database::scalar('SELECT status FROM ticket_requests WHERE id = :i', ['i' => $idSeat], '') === 'rejected' && $auditCount('ticket_request.rejected') === 1);
    $o = child(['what' => 'admin', 'admin' => $super, 'post' => ['action' => 'reject', 'id' => $idDate, 'staff_note' => 'again']]);
    check('a closed request cannot be closed again', str_contains($o, 'already closed') && (string) Database::scalar('SELECT status FROM ticket_requests WHERE id = :i', ['i' => $idDate], '') === 'done');
    $o = child(['what' => 'admin', 'admin' => $super, 'post' => ['action' => 'approve_refund', 'id' => $idAll]]);
    check('an unknown action changes nothing', str_contains($o, 'Unknown action') && (string) Database::scalar('SELECT status FROM ticket_requests WHERE id = :i', ['i' => $idAll], '') === 'open');
    $done = child(['what' => 'admin', 'admin' => $super, 'get' => ['status' => 'done']]);
    check('the Done tab shows who closed it and the note', str_contains($done, 'id="tr-' . $idDate . '"') && str_contains($done, 'Moved to') && !str_contains($done, 'id="tr-' . $idAll . '"'));
    check('the booking is unchanged after the office closed requests', $snap($B1['id']) === $snapB1);
    $v1b = child(['what' => 'view', 'get' => ['code' => $code1], 'ip' => $ip(33)]);
    check('with a slot free again the passenger may ask once more', str_contains($v1b, 'Request date change') && str_contains($v1b, 'Done') && !str_contains($v1b, 'Moved to'));

    // roles
    $mkAdmin = static function (string $role) use (&$made, $tag): int {
        $id = Database::insert('admins', ['username' => 'p6-' . $role . '-' . strtolower($tag), 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
            'full_name' => 'P6 ' . ucfirst($role) . ' Test', 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
        $made['admin'][] = $id;
        return $id;
    };
    $sup = $mkAdmin('support');
    $agt = $mkAdmin('agent');
    $o = child(['what' => 'admin', 'admin' => $sup]);
    check('support may read the list (and is told who closes it)', str_contains($o, 'id="tr-' . $idAll . '"') && !str_contains($o, 'value="done"'));
    $o = child(['what' => 'admin', 'admin' => $sup, 'post' => ['action' => 'done', 'id' => $idAll, 'staff_note' => 'x']]);
    check('support cannot close a request', str_contains($o, 'may read these requests but not close') && (string) Database::scalar('SELECT status FROM ticket_requests WHERE id = :i', ['i' => $idAll], '') === 'open');
    $o = child(['what' => 'admin', 'admin' => $agt]);
    check('a field agent cannot open the page', !str_contains($o, 'id="tr-' . $idAll . '"') && str_contains($o, 'not for your role'));

    /* review 15 Nov: counter desk isolation (counter_desk_isolation) - a clerk
       sees and closes only their own desk's tickets, as on booking-view /
       payments. Switched on inside the child process only. */
    echo "\n-- Counter desk isolation\n";
    $desk = 'P6' . $tag;
    $ctr  = $mkAdmin('counter');
    Database::insert('admin_profiles', ['admin_id' => $ctr, 'counter_code' => $desk]);
    $made['profile'][] = $ctr;
    $B5 = $mkBooking('D', $sFut, $future, 'confirmed');
    Database::update('bookings', ['counter_code' => $desk], 'id = :b', ['b' => $B5['id']]);
    $idDesk = Database::insert('ticket_requests', ['booking_id' => $B5['id'], 'pnr' => $B5['pnr'], 'kind' => 'cancel_seats',
        'seats' => 'L1', 'contact_phone' => '9812345678', 'status' => 'open', 'ip' => '203.0.113.150']);
    check('counts() / listFor() honour a desk scope', TicketRequests::counts($desk)['all'] === 1
        && array_map('intval', array_column(TicketRequests::listFor('all', 50, $desk), 'id')) === [$idDesk]);
    $o = child(['what' => 'admin', 'admin' => $ctr, 'desk_iso' => true, 'get' => ['status' => 'all']]);
    check("isolation on: the counter sees its own desk's request and no other",
        str_contains($o, 'id="tr-' . $idDesk . '"') && !str_contains($o, 'id="tr-' . $idAll . '"') && !str_contains($o, 'id="tr-' . $idDate . '"')
        && str_contains($o, 'सबै · All (1)'));
    $o = child(['what' => 'admin', 'admin' => $ctr, 'desk_iso' => true, 'post' => ['action' => 'done', 'id' => $idAll, 'staff_note' => 'not mine']]);
    check("isolation on: the counter cannot close another desk's request",
        str_contains($o, 'another desk') && (string) Database::scalar('SELECT status FROM ticket_requests WHERE id = :i', ['i' => $idAll], '') === 'open');
    $o = child(['what' => 'admin', 'admin' => $ctr, 'desk_iso' => true, 'post' => ['action' => 'done', 'id' => $idDesk, 'staff_note' => 'Seat L1 cancelled at the desk']]);
    check("isolation on: the counter closes its own desk's request",
        (string) Database::scalar('SELECT status FROM ticket_requests WHERE id = :i', ['i' => $idDesk], '') === 'done');
    $o = child(['what' => 'admin', 'admin' => $ctr, 'desk_iso' => false, 'get' => ['status' => 'all']]);
    check('isolation off: the counter sees every request', str_contains($o, 'id="tr-' . $idAll . '"') && str_contains($o, 'id="tr-' . $idDesk . '"'));

    /* 2g. before the migration */
    echo "\n-- Without the table\n";
    Database::query('RENAME TABLE ticket_requests TO ticket_requests_p6hold');
    $renamed = true;
    TicketRequests::resetReady();
    check('ready() / enabled() / seatChangeReady() are false', !TicketRequests::ready() && !TicketRequests::enabled() && !TicketRequests::seatChangeReady());
    check("refusal() is 'off' and the lists are empty", (TicketRequests::refusal(Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $B4['id']]) ?? [])['reason'] ?? '') === 'off'
        && TicketRequests::forBooking($B4['id']) === [] && TicketRequests::listFor('open') === [] && TicketRequests::counts()['all'] === 0);
    Database::delete('rate_limits', "bucket = 'ticket_request_bk' AND identifier = :i", ['i' => (string) $B4['id']]);
    $r = api(['pnr' => $B4['pnr'], 'k' => $B4['k'], 'kind' => 'change_date', 'wanted_date' => $wanted], $ip(40));
    check('the API answers 503 "not available"', ($r['code'] ?? 0) === 503, json_encode($r, JSON_UNESCAPED_UNICODE));
    $v = child(['what' => 'view', 'get' => ['code' => $code4], 'ip' => $ip(41)]);
    check('the ticket page still renders, with no request buttons', str_contains($v, 'CONFIRMED') && !str_contains($v, 'id="treq"') && !preg_match('/(Warning|Fatal error):/', $v));
    $o = child(['what' => 'admin', 'admin' => $super]);
    check('the office page says to run the migration', str_contains($o, 'upgrade-2026-11-15-ticket-requests.sql') && !preg_match('/(Warning|Fatal error):/', $o));
    Database::query('RENAME TABLE ticket_requests_p6hold TO ticket_requests');
    $renamed = false;
    TicketRequests::resetReady();
    check('table back in place', TicketRequests::ready());
} catch (Throwable $e) {
    check('test ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
}

$left = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE pnr LIKE :p", ['p' => 'SHG-TRQ-' . $tag . '%'], 0)
      + (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND entity_id LIKE :p", ['s' => $startAudit, 'p' => 'SHG-TRQ-' . $tag . '%'], 0);
check('cleanup left nothing behind', $left === 0, (string) $left);
finish();
