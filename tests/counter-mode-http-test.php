<?php
/**
 * =====================================================================
 *  counter-mode-http-test.php — Module 3 over real HTTP: the customer
 *  app + /api/book.php behave as the counter for a signed-in staff
 *  session and exactly as before for a guest.
 *
 *    • index.php: guest boot has NO `staff` key; a signed-in agent's boot
 *      carries staff {code, canSell, panelUrl} + the shared seat cap
 *    • /api/search.php: staff see the board without an agent code
 *    • /api/book.php: guest sending counterPayment → 403; agent session
 *      with counterPayment → confirmed + counter:true + adminUrl; the
 *      booking lands on the agent's own bookings.php and booking-view
 *    • admin nav: "+ New Booking" points at counter mode; new-booking.php
 *      redirects there (?legacy=1 still serves the old form)
 *
 *  Needs the dev server on :8899 + curl:
 *    php -c .claude/php-dev.ini -d extension=php_curl.dll tests/counter-mode-http-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';

/* Overridable so a second worktree can be tested without stealing :8899
   from the one that already holds it — a suite pointed at ANOTHER
   checkout's server reports green for code you did not change.
       SHG_TEST_BASE=http://localhost:8898 php ... */
define('BASE', getenv('SHG_TEST_BASE') ?: 'http://localhost:8899');
const CH_PHONE = '9100007731';
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function req(string $jar, string $method, string $url, array $opt = []): array {
    $ch = curl_init(BASE . $url);
    $headers = $opt['headers'] ?? [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
        $headers[] = 'Content-Type: application/json';
        // The JSON API reads the CSRF token from this header (Security::verifyCsrf), like shgApi.post does.
        if (!empty($opt['json']['shg_csrf'])) { $headers[] = 'X-CSRF-Token: ' . $opt['json']['shg_csrf']; }
    }
    elseif (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hsz = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $head = substr($raw, 0, $hsz); $body = substr($raw, $hsz);
    $loc = preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '';
    $json = null; if ($body !== '' && ($body[0] === '{' || $body[0] === '[')) { $json = json_decode($body, true); }
    return ['code' => $code, 'body' => $body, 'location' => $loc, 'json' => $json];
}
function bootOf(string $html): ?array {
    return preg_match('/window\.SHG_BOOT = (\{.*?\});/s', $html, $m) ? json_decode($m[1], true) : null;
}
function jar(string $n): string { $p = sys_get_temp_dir() . '/shg_cmh_' . $n . '_' . getmypid() . '.cookies'; @unlink($p); return $p; }

/* Fixtures: throwaway agent + a far-future travel day. */
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
if ($route === null) { echo "  SKIP  no active sleeper route\n"; exit(0); }
$rid = (int) $route['id'];
$w = bookingWindow(); $D = addDaysISO($w['from'], 28);
$D_PAST = addDaysISO(todayISO(), -2);   // the any-date staff case books here
$pw = 'CmHttp@12345';
$agentId = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => 'cmh-agent'], 0);
if ($agentId === 0) {
    $agentId = (int) Database::insert('admins', ['username' => 'cmh-agent', 'password_hash' => password_hash($pw, PASSWORD_BCRYPT),
        'full_name' => 'Counter HTTP Agent', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0]);
} else {
    Database::update('admins', ['password_hash' => password_hash($pw, PASSWORD_BCRYPT), 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0, 'failed_logins' => 0, 'locked_until' => null], 'id = :i', ['i' => $agentId]);
}
try { Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%' OR bucket LIKE 'book_create%'"); } catch (Throwable $e) {}
$cleanup = function () use ($D, $D_PAST, $agentId): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone = '" . CH_PHONE . "'") as $r) {
        try { Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    foreach ([$D, $D_PAST] as $d) {
        Database::delete('booking_legs', 'travel_date = :d', ['d' => $d]);
        foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $d]) as $s) {
            Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
            try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $s['id']]); } catch (Throwable $e) {}
        }
        Database::delete('schedules', 'travel_date = :d', ['d' => $d]);
    }
};
$cleanup();
Seats::schedule($rid, $D);

$payload = function (array $seats, string $csrf, array $extra = []) use ($rid, $D, $route): array {
    $pax = [];
    foreach ($seats as $i => $s) { $pax[] = ['seat' => $s, 'name' => 'HTTP Pax ' . ($i + 1), 'age' => 25 + $i, 'gender' => 'Female']; }
    return array_merge([
        'shg_csrf' => $csrf, 'routeId' => $rid, 'travelDate' => $D, 'seats' => $seats, 'passengers' => $pax,
        'contact' => ['phone' => CH_PHONE], 'bookingMode' => 'sharing', 'boarding' => '', 'originTown' => (string) $route['from_city'],
        'paymentMethod' => 'upi', 'isCod' => false,
    ], $extra);
};

try {
    echo "\n=== 1. Guest: unchanged ===\n";
    $g = jar('guest');
    $home = req($g, 'GET', '/');
    $gb = bootOf($home['body']);
    check('guest home renders with a boot payload', $home['code'] === 200 && is_array($gb));
    check('guest boot has NO staff key', is_array($gb) && !array_key_exists('staff', $gb));
    check('guest boot ships the shared seat cap', is_array($gb) && (int) ($gb['settings']['max_seats_per_booking'] ?? 0) > 0, 'cap=' . ($gb['settings']['max_seats_per_booking'] ?? '?'));
    check('14-counter.js is loaded by the shell', str_contains($home['body'], '/assets/js/14-counter.js'));
    $csrfG = (string) ($gb['csrf'] ?? '');
    $forb = req($g, 'POST', '/api/book.php', ['json' => $payload(['U9'], $csrfG, ['counterPayment' => 'cash'])]);
    check('guest sending counterPayment is refused (403)', $forb['code'] === 403, 'HTTP ' . $forb['code']);
    check('… and no booking was written', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone = '" . CH_PHONE . "'", [], 0) === 0);

    echo "\n=== 2. Agent session: counter mode ===\n";
    $a = jar('agent');
    $lp = req($a, 'GET', '/admin/login.php');
    preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m);
    $li = req($a, 'POST', '/admin/login.php', ['form' => ['shg_csrf' => $m[1] ?? '', 'username' => 'cmh-agent', 'password' => $pw]]);
    check('agent signs in', $li['code'] === 302, 'HTTP ' . $li['code']);
    $ah = req($a, 'GET', '/admin/agent.php');
    check('agent panel "+ New Booking" points at counter mode', str_contains($ah['body'], 'href="/index.php?counter=1#/"'));
    check('agent panel "Sell seats" links carry from/to/date', preg_match('~/index\.php\?counter=1&amp;from=[^"]+&amp;to=[^"]+&amp;date=\d{4}-\d{2}-\d{2}#/~', $ah['body']) === 1 || str_contains($ah['body'], 'No upcoming trips'));
    $nb = req($a, 'GET', '/admin/new-booking.php?route=' . $rid . '&date=' . $D);
    check('new-booking.php redirects to counter mode with route + date', $nb['code'] === 302 && str_contains($nb['location'], 'counter=1') && str_contains($nb['location'], 'date=' . $D), 'HTTP ' . $nb['code'] . ' → ' . $nb['location']);
    $nbl = req($a, 'GET', '/admin/new-booking.php?legacy=1');
    check('?legacy=1 still serves the old form', $nbl['code'] === 200 && str_contains($nbl['body'], 'name="seats[]"') || ($nbl['code'] === 200 && str_contains($nbl['body'], 'Choose trip')), 'HTTP ' . $nbl['code']);

    $sh = req($a, 'GET', '/index.php?counter=1');
    $ab = bootOf($sh['body']);
    check('staff boot present for the agent', is_array($ab) && is_array($ab['staff'] ?? null));
    check('staff.canSell true, panelUrl = agent.php', !empty($ab['staff']['canSell']) && ($ab['staff']['panelUrl'] ?? '') === '/admin/agent.php', json_encode($ab['staff'] ?? null));
    check('staff boot ships the bulk cap (maxSeats)', (int) ($ab['staff']['maxSeats'] ?? 0) >= 7, 'maxSeats=' . ($ab['staff']['maxSeats'] ?? '?'));
    $csrfA = (string) ($ab['csrf'] ?? '');

    $srch = req($a, 'POST', '/api/search.php', ['json' => ['shg_csrf' => $csrfA, 'from' => $route['from_city'], 'to' => $route['to_city'], 'date' => $D]]);
    check('search works for the staff session', $srch['code'] === 200 && !empty($srch['json']['ok']), 'HTTP ' . $srch['code'] . ' ' . substr($srch['body'], 0, 120));

    $bk = req($a, 'POST', '/api/book.php', ['json' => $payload(['L11', 'L12', 'L13', 'L14'], $csrfA, ['counterPayment' => 'upi', 'discountType' => 'flat', 'discountValue' => 200, 'note' => 'http counter test'])]);
    $j = $bk['json'] ?? [];
    check('4-seat counter sale accepted', $bk['code'] === 200 && !empty($j['ok']), 'HTTP ' . $bk['code'] . ' ' . substr($bk['body'], 0, 160));
    $data = $j['data'] ?? $j;
    check('response says counter:true with an adminUrl', !empty($data['counter']) && str_contains((string) ($data['adminUrl'] ?? ''), 'booking-view.php?pnr='), json_encode(['counter' => $data['counter'] ?? null, 'adminUrl' => $data['adminUrl'] ?? null]));
    check('status confirmed in the response', ($data['status'] ?? '') === 'confirmed', (string) ($data['status'] ?? ''));
    $pnr = (string) ($data['pnr'] ?? '');
    $row = $pnr !== '' ? Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $pnr]) : null;
    check('DB: confirmed, source agent, sold by the agent', $row !== null && $row['status'] === 'confirmed' && $row['source'] === 'agent' && (int) $row['sold_by_admin_id'] === $agentId);
    check('DB: ₹200 counter discount stored', $row !== null && (float) $row['coupon_discount'] === 200.0, 'discount=' . ($row['coupon_discount'] ?? '?'));
    $pay = $row ? Database::fetch('SELECT method, status, verified_by, admin_note FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $row['id']]) : null;
    check('DB: payment UPI / verified by the agent / note kept', $pay !== null && $pay['method'] === 'upi' && $pay['status'] === 'verified' && (int) $pay['verified_by'] === $agentId && $pay['admin_note'] === 'http counter test', json_encode($pay));
    $led = $row ? Database::fetch("SELECT amount FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission'", ['b' => (int) $row['id']]) : null;
    check('DB: commission accrued for 4 seats', $led !== null && (float) $led['amount'] > 0, 'amount=' . ($led['amount'] ?? 'none'));

    $mine = req($a, 'GET', '/admin/bookings.php');
    check('the sale appears on the agent\'s own My Bookings', $pnr !== '' && str_contains($mine['body'], $pnr));
    $bv = req($a, 'GET', '/admin/booking-view.php?pnr=' . urlencode($pnr));
    check('booking-view shows the ledger commission block', $bv['code'] === 200 && str_contains($bv['body'], 'Agent commission'));

    echo "
=== 2b. Self-serve payout request ===
";
    $ah2 = req($a, 'GET', '/admin/agent.php');
    check('agent panel offers "Request a payout" once commission is due', str_contains($ah2['body'], 'name="action" value="payout_request"'));
    preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $ah2['body'], $cm);
    $pr = req($a, 'POST', '/admin/agent.php', ['form' => ['shg_csrf' => $cm[1] ?? '', 'action' => 'payout_request', 'amount' => 100, 'note' => 'http test']]);
    check('payout request accepted', $pr['code'] === 200 && str_contains($pr['body'], 'sent to the office'), 'HTTP ' . $pr['code']);
    check('audit row written', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'agent.payout_request' AND entity_id = :a", ['a' => (string) $agentId], 0) === 1);
    check('panel now shows the request as waiting', str_contains($pr['body'], 'waiting for the office'));
    $pr2 = req($a, 'POST', '/admin/agent.php', ['form' => ['shg_csrf' => $cm[1] ?? '', 'action' => 'payout_request', 'amount' => 100]]);
    check('a second request is refused while one is open', str_contains($pr2['body'], 'still with the office'));
    $bl = req($a, 'GET', '/admin/bookings.php?q=' . urlencode('SHG-999'));
    check('bookings search accepts an SHG code without error', $bl['code'] === 200);

    echo "\n=== 3. Split caps + date floor over HTTP (bulk booking, 5 Sep 2026) ===\n";
    $bulk = req($a, 'POST', '/api/book.php', ['json' => $payload(['L21', 'L22', 'L23', 'L24', 'L25', 'L26', 'L27'], $csrfA, ['counterPayment' => 'cash'])]);
    $bj = ($bulk['json']['data'] ?? $bulk['json']) ?? [];
    check('7-seat BULK counter sale accepted over HTTP (staff cap 20)', $bulk['code'] === 200 && ($bj['status'] ?? '') === 'confirmed', 'HTTP ' . $bulk['code'] . ' ' . substr($bulk['body'], 0, 120));
    $over = array_map(static fn(int $i): string => 'U' . $i, range(10, 30));   // 21 seats
    $cap = req($a, 'POST', '/api/book.php', ['json' => $payload($over, $csrfA, ['counterPayment' => 'cash'])]);
    check('21 seats refused for the counter (409, cap 20)', $cap['code'] === 409 && stripos($cap['body'], 'at most 20') !== false, 'HTTP ' . $cap['code']);
    /* 6 Sep 2026: selling staff may record a ticket for ANY travel date. The
       old floor of "yesterday" (the 24h departed-bus grace) is gone for
       staff — a paper ticket from three days ago now goes straight in. The
       customer floor is untouched (see staff-any-date-test.php). */
    $old = req($a, 'POST', '/api/book.php', ['json' => $payload(['L28'], $csrfA, ['counterPayment' => 'cash', 'travelDate' => $D_PAST])]);
    $oj  = ($old['json']['data'] ?? $old['json']) ?? [];
    check('two days ago is ACCEPTED for staff over HTTP (any-date counter)', $old['code'] === 200 && ($oj['status'] ?? '') === 'confirmed', 'HTTP ' . $old['code'] . ' ' . substr($old['body'], 0, 100));
} catch (Throwable $e) {
    check('unexpected exception: ' . $e->getMessage(), false);
} finally {
    $cleanup();
    try { Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $agentId]); } catch (Throwable $e) {}
    try { Database::query("DELETE FROM audit_logs WHERE action = 'agent.payout_request' AND entity_id = :a", ['a' => (string) $agentId]); } catch (Throwable $e) {}
    try { Database::delete('admins', 'id = :i', ['i' => $agentId]); } catch (Throwable $e) {}
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
