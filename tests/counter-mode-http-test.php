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
 *  Needs a dev server + curl:
 *    SHG_TEST_BASE=http://localhost:<port> php -c .claude/php-dev.ini -d extension=php_curl.dll tests/counter-mode-http-test.php
 *  With SHG_TEST_BASE unset the suite starts its OWN php -S on a free port
 *  serving this checkout (16 Nov 2026, as manifest-test.php).
 *
 *  16 Nov 2026: everything is scoped to THIS run - a per-run agent and
 *  passenger mobile, a travel day no other suite uses, and a cleanup that
 *  removes only this run's bookings (with every row that points at them),
 *  the schedules this run created, the payout request / ledger / chat /
 *  audit rows and the agent with its sessions and login history. It used to
 *  delete EVERY schedule and booking leg on its two travel days, and its
 *  mobile 9100007731 sits inside cross-mode-integrity-test's 910000773%
 *  sweep, so two suites running at once deleted each other's data.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/quickunlock.php';
require_once INCLUDE_PATH . '/teamregistry.php';

/* Overridable so a second worktree can be tested without stealing :8899
   from the one that already holds it — a suite pointed at ANOTHER
   checkout's server reports green for code you did not change.
       SHG_TEST_BASE=http://localhost:8898 php ...
   16 Nov 2026: with SHG_TEST_BASE unset the suite starts its OWN php -S on
   a free port serving THIS checkout and stops it on exit (the battery
   passes no base; :8899 is whichever checkout owns it). */
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

/* Per-run names (see the header). CH_PHONE_PREFIX marks every passenger
   mobile this suite ever uses - no other suite uses 9100065…. */
const CH_PHONE_PREFIX  = '9100065';
const CH_AGENT_PREFIX  = 'cmh-agent';
const CH_PIN           = '736152';   // any valid 6-digit PIN (QuickUnlock::pinProblem)
define('CH_RUN', bin2hex(random_bytes(3)));
define('CH_PHONE', CH_PHONE_PREFIX . (string) random_int(100, 999));
define('CH_AGENT', CH_AGENT_PREFIX . '-' . CH_RUN);
define('CH_AGENT_NAME', 'Counter HTTP Agent ' . CH_RUN);

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
/** The page's CSRF token: the hidden form field, else the <meta name="csrf-token"> a staff page carries. */
function csrfOf(string $html): string {
    if (preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $html, $m) === 1) { return $m[1]; }
    return preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $m) === 1 ? $m[1] : '';
}
/** The page's result flash: [kind, text], or ['', '']. */
function flashOf(string $html): array {
    return preg_match('/<div class="flash (ok|bad)">(.*?)<\/div>/s', $html, $m) === 1 ? [$m[1], html_entity_decode(strip_tags($m[2]))] : ['', ''];
}
/**
 * Post a form to agent.php and follow its redirect-after-post (16 Nov 2026,
 * F2 refix: agent.php answers EVERY post with a 302 back to itself; the
 * result rides the session to the next view). [post, landing page].
 */
function postBack(string $jar, string $url, array $form): array {
    $p = req($jar, 'POST', $url, ['form' => $form]);
    $landed = ($p['code'] === 302 && $p['location'] !== '') ? req($jar, 'GET', (string) preg_replace('~^https?://[^/]+~', '', $p['location'])) : $p;
    return [$p, $landed];
}

/* ---- cleanup: only what THIS run (or an older run of this suite) made ---- */
function chRemoveBooking(int $bid, string $pnr): void {
    try { Seats::releaseBooking($bid); } catch (Throwable $e) {}   // seats sellable again, counts + cabin locks recomputed
    foreach (['agent_ledger', 'message_logs', 'delivery_log', 'ticket_links', 'wa_outbox', 'wa_ticket_queue', 'msg_ledger', 'payment_events'] as $t) {
        try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
    }
    try { Database::query("DELETE FROM audit_logs WHERE entity_type = 'booking' AND entity_id IN (:p, :i)", ['p' => $pnr, 'i' => (string) $bid]); } catch (Throwable $e) {}
    chForgetPnr($pnr);
    try { Database::delete('bookings', 'id = :i', ['i' => $bid]); } catch (Throwable $e) {}   // seats, legs, payments, tickets cascade
    foreach (glob(dirname(__DIR__) . '/tickets/ticket_' . $pnr . '.*') ?: [] as $f) { @unlink($f); }
}
/* Rows that name a PNR without a foreign key (16 Nov 2026): the EventBus
   journal (booking.created / approved carry it in `data`, office-bell claims
   in `dedupe_key`) and an office-bell entry whose booking_id is NULL but
   whose link opens the PNR. */
function chForgetPnr(string $pnr): void {
    if ($pnr === '') { return; }
    try { Database::query('DELETE FROM automation_log WHERE data LIKE :d OR dedupe_key LIKE :k1 OR dedupe_key LIKE :k2',
        ['d' => '%"' . $pnr . '"%', 'k1' => '%:' . $pnr, 'k2' => '%:' . $pnr . ':%']); } catch (Throwable $e) {}
    try { Database::query('DELETE FROM notifications WHERE booking_id IS NULL AND (link LIKE :l1 OR link LIKE :l2)',
        ['l1' => '%pnr=' . $pnr, 'l2' => '%pnr=' . $pnr . '&%']); } catch (Throwable $e) {}
}
function chRemoveAgent(int $aid, string $name): void {
    foreach (['agent_ledger', 'agent_loans', 'agent_payout_requests'] as $t) {
        try { Database::delete($t, 'agent_admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
    }
    // the payout chat thread StaffMsg::payoutRequested opens (its messages cascade; the read marks do not)
    try { Database::query('DELETE r FROM staff_thread_reads r JOIN staff_threads t ON t.id = r.thread_id WHERE t.agent_admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
    try { Database::delete('staff_threads', 'agent_admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
    if ($name !== '') {   // the office WhatsApp the payout request sent names the agent
        try { Database::delete('message_logs', 'body LIKE :b', ['b' => '%' . $name . '%']); } catch (Throwable $e) {}
    }
    try { Database::delete('admin_sessions', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
    try { Database::query("DELETE FROM audit_logs WHERE entity_type = 'admin' AND entity_id = :a", ['a' => (string) $aid]); } catch (Throwable $e) {}
    // the "recent pages" menu row admin/_guard.php keeps per account (OpsHub::trackPage; no FK to admins)
    try { Database::query("DELETE FROM kv_store WHERE kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $aid]); } catch (Throwable $e) {}
    try { Database::delete('admins', 'id = :a', ['a' => $aid]); } catch (Throwable $e) {}   // admin_devices cascades
}
/** Schedules on $dates that did not exist before the run and carry no booking any more. */
function chRemoveNewSchedules(array $dates, array $before): void {
    foreach ($dates as $d) {
        foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $d]) as $s) {
            $sid = (int) $s['id'];
            if (in_array($sid, $before, true)) {
                continue;
            }
            $used = (int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE schedule_id = :s', ['s' => $sid], 0)
                  + (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE schedule_id = :s', ['s' => $sid], 0);
            if ($used === 0) {
                try { Database::delete('schedules', 'id = :s', ['s' => $sid]); } catch (Throwable $e) {}   // seat / unit locks cascade
            }
        }
    }
}
function chCleanup(array $dates, array $before): void {
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => CH_PHONE]) as $r) {
        chRemoveBooking((int) $r['id'], (string) $r['pnr']);
    }
    try { Database::delete('message_logs', 'to_number LIKE :p', ['p' => '%' . CH_PHONE]); } catch (Throwable $e) {}
    // the customer-master row the counter sales opened for the passenger number (16 Nov 2026)
    try { Database::delete('user_profiles', 'phone = :p', ['p' => CH_PHONE]); } catch (Throwable $e) {}
    $aid = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => CH_AGENT], 0);
    if ($aid > 0) {
        chRemoveAgent($aid, CH_AGENT_NAME);
    }
    try { Database::delete('admin_login_events', 'username = :u', ['u' => CH_AGENT]); } catch (Throwable $e) {}
    chRemoveNewSchedules($dates, $before);
}
/* What an EARLIER run left (it died, or ran an older copy with the fixed
   'cmh-agent' name): only rows over an hour old, so a run going on right now
   is never touched; an agent already deleted (whose sessions were orphaned)
   is found through its login history. */
function chSweepStale(): void {
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone LIKE :p AND created_at < NOW() - INTERVAL 1 HOUR', ['p' => CH_PHONE_PREFIX . '%']) as $r) {
        chRemoveBooking((int) $r['id'], (string) $r['pnr']);
    }
    try { Database::query('DELETE FROM user_profiles WHERE phone LIKE :p AND created_at < NOW() - INTERVAL 1 HOUR', ['p' => CH_PHONE_PREFIX . '%']); } catch (Throwable $e) {}
    $like = CH_AGENT_PREFIX . '%';
    foreach (Database::fetchAll('SELECT id, full_name FROM admins WHERE username LIKE :u AND created_at < NOW() - INTERVAL 1 HOUR', ['u' => $like]) as $a) {
        $n = (string) $a['full_name'];
        chRemoveAgent((int) $a['id'], str_starts_with($n, 'Counter HTTP Agent ') ? $n : '');
    }
    $gone = Database::fetchAll('SELECT DISTINCT e.admin_id AS id FROM admin_login_events e LEFT JOIN admins a ON a.id = e.admin_id
                                 WHERE e.username LIKE :u AND e.admin_id IS NOT NULL AND a.id IS NULL', ['u' => $like]);
    $gone = array_merge($gone, Database::fetchAll("SELECT DISTINCT l.actor_id AS id FROM audit_logs l LEFT JOIN admins a ON a.id = l.actor_id
                                 WHERE l.action = 'admin.login' AND l.actor_name LIKE :u AND l.actor_id IS NOT NULL AND a.id IS NULL", ['u' => $like]));
    foreach ($gone as $o) {
        chRemoveAgent((int) $o['id'], '');
    }
    try { Database::query('DELETE e FROM admin_login_events e LEFT JOIN admins a ON a.id = e.admin_id WHERE e.username LIKE :u AND a.id IS NULL', ['u' => $like]); } catch (Throwable $e) {}
}

/* Fixtures: a throwaway agent + a travel day no other suite books (from + 29;
   the others use +22 … +28), and two days ago for the any-date case. */
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
if ($route === null) { echo "  SKIP  no active sleeper route\n"; exit(0); }
$rid = (int) $route['id'];
$w = bookingWindow(); $D = addDaysISO($w['from'], 29);
$D_PAST = addDaysISO(todayISO(), -2);   // the any-date staff case books here
$DATES = [$D, $D_PAST];
$pw = 'CmHttp@12345';
try { Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%' OR bucket LIKE 'book_create%'"); } catch (Throwable $e) {}
chSweepStale();
$SCHED_BEFORE = array_map('intval', array_column(Database::fetchAll('SELECT id FROM schedules WHERE travel_date IN (:a, :b)', ['a' => $D, 'b' => $D_PAST]), 'id'));
register_shutdown_function(static function () use ($DATES, &$SCHED_BEFORE): void { chCleanup($DATES, $SCHED_BEFORE); });   // also after a crash

$agentId = (int) Database::insert('admins', ['username' => CH_AGENT, 'password_hash' => password_hash($pw, PASSWORD_BCRYPT),
    'full_name' => CH_AGENT_NAME, 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0]);
/* 16 Nov 2026: the code-first door (15 Nov) sends an account with no PIN / pattern
   to pin-setup.php before any page (Auth::pinSetupNeeded) - give it one. */
QuickUnlock::set($agentId, 'pin', CH_PIN);
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
    /* 8 Oct 2026 (D8-08, index.php): the counter desk bundle (14-counter.js, ~70 KB)
       is a no-op without SHG_BOOT.staff, so a customer page no longer downloads it;
       a staff page still does (checked in section 2). This asserted it on the GUEST
       page, the rule D8-08 reversed. */
    check('the guest shell does NOT load 14-counter.js (D8-08)', $home['code'] === 200 && !str_contains($home['body'], '/assets/js/14-counter.js'));
    $csrfG = (string) ($gb['csrf'] ?? '');
    $forb = req($g, 'POST', '/api/book.php', ['json' => $payload(['U9'], $csrfG, ['counterPayment' => 'cash'])]);
    check('guest sending counterPayment is refused (403)', $forb['code'] === 403, 'HTTP ' . $forb['code']);
    check('… and no booking was written', (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => CH_PHONE], 0) === 0);

    echo "\n=== 2. Agent session: counter mode ===\n";
    $a = jar('agent');
    /* 16 Nov 2026: the bare door is the code-first box (15 Nov); the username +
       password sign-in is the classic door, ?classic=1, whose post carries classic=1. */
    $lp = req($a, 'GET', '/admin/login.php?classic=1&other=1');
    $li = req($a, 'POST', '/admin/login.php', ['form' => ['shg_csrf' => csrfOf($lp['body']), 'classic' => '1', 'username' => CH_AGENT, 'password' => $pw]]);
    check('agent signs in', $li['code'] === 302 && !str_contains($li['location'], 'login.php') && !str_contains($li['location'], 'pin-setup'), 'HTTP ' . $li['code'] . ' → ' . $li['location']);
    $ah = req($a, 'GET', '/admin/agent.php');
    check('agent panel "+ New Booking" points at counter mode', str_contains($ah['body'], 'href="/index.php?counter=1#/"'), 'HTTP ' . $ah['code']);
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
    check('14-counter.js is loaded by the staff shell', str_contains($sh['body'], '/assets/js/14-counter.js'));
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
    check('the sale appears on the agent\'s own My Bookings', $pnr !== '' && str_contains($mine['body'], $pnr), 'HTTP ' . $mine['code']);
    $bv = req($a, 'GET', '/admin/booking-view.php?pnr=' . urlencode($pnr));
    check('booking-view shows the ledger commission block', $bv['code'] === 200 && str_contains($bv['body'], 'Agent commission'), 'HTTP ' . $bv['code']);

    echo "\n=== 2b. Self-serve payout request ===\n";
    /* 16 Nov 2026 (F2): the request is an agent_payout_requests row through
       AgentWallet::requestPayout() (same 'agent.payout_request' audit row as
       before), and agent.php answers every post with a redirect back to itself
       (PRG): the flash shows on the page the browser lands on. */
    $ah2 = req($a, 'GET', '/admin/agent.php');
    check('agent panel offers "Request a payout" once commission is due', str_contains($ah2['body'], 'name="action" value="payout_request"'), 'HTTP ' . $ah2['code']);
    $csrfP = csrfOf($ah2['body']);
    [$prPost, $pr] = postBack($a, '/admin/agent.php', ['shg_csrf' => $csrfP, 'action' => 'payout_request', 'amount' => 100, 'note' => 'http test']);
    [$fk, $ft] = flashOf($pr['body']);
    check('the post redirects back to the panel (PRG, no re-send on refresh)',
        $prPost['code'] === 302 && (string) preg_replace('~^https?://[^/]+~', '', $prPost['location']) === '/admin/agent.php', 'HTTP ' . $prPost['code'] . ' → ' . $prPost['location']);
    $reqs = Database::fetchAll('SELECT amount, status, note FROM agent_payout_requests WHERE agent_admin_id = :a', ['a' => $agentId]);
    if (TeamRegistry::ready()) {
        /* A database with the team registry (team_identity) holds every withdrawal
           until the office has verified the agent's payout recipient details
           (AgentWallet::requestPayout). This throwaway agent has no verified
           identity there, so the right answer is the refusal - and no row. */
        check('payout request refused until the payout details are verified (team registry present)',
            $fk === 'bad' && str_contains($ft, 'verify your payout recipient details') && $reqs === [], "$fk: " . mb_substr($ft, 0, 120));
    } else {
        check('payout request accepted', $pr['code'] === 200 && $fk === 'ok' && str_contains($ft, 'sent to the office'), 'HTTP ' . $pr['code'] . " $fk: " . mb_substr($ft, 0, 120));
        check('…as one OPEN agent_payout_requests row for ₹100 with the note',
            count($reqs) === 1 && $reqs[0]['status'] === 'open' && (float) $reqs[0]['amount'] === 100.0 && $reqs[0]['note'] === 'http test', json_encode($reqs));
        check('audit row written', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'agent.payout_request' AND entity_id = :a", ['a' => (string) $agentId], 0) === 1);
        check('panel now shows the request as waiting', str_contains($pr['body'], 'waiting for the office'));
        [, $pr2] = postBack($a, '/admin/agent.php', ['shg_csrf' => $csrfP, 'action' => 'payout_request', 'amount' => 100]);
        [$fk2, $ft2] = flashOf($pr2['body']);
        check('a second request is refused while one is open', $fk2 === 'bad' && str_contains($ft2, 'still with the office')
            && (int) Database::scalar('SELECT COUNT(*) FROM agent_payout_requests WHERE agent_admin_id = :a', ['a' => $agentId], 0) === 1, "$fk2: " . mb_substr($ft2, 0, 120));
    }
    $bl = req($a, 'GET', '/admin/bookings.php?q=' . urlencode('SHG-999'));
    check('bookings search accepts an SHG code without error', $bl['code'] === 200 && !str_contains($bl['body'], 'Application error'), 'HTTP ' . $bl['code']);

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
    $runPnrs = array_map('strval', array_column(Database::fetchAll('SELECT pnr FROM bookings WHERE contact_phone = :p', ['p' => CH_PHONE]), 'pnr'));
    chCleanup($DATES, $SCHED_BEFORE);
    $journal = 0;
    foreach ($runPnrs as $p) {
        $journal += (int) Database::scalar('SELECT COUNT(*) FROM automation_log WHERE data LIKE :d OR dedupe_key LIKE :k', ['d' => '%"' . $p . '"%', 'k' => '%:' . $p . '%'], 0)
                  + (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE link LIKE :l', ['l' => '%pnr=' . $p . '%'], 0);
    }
    $leftBy = [
        'bookings'       => (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => CH_PHONE], 0),
        'agent'          => (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE username = :u', ['u' => CH_AGENT], 0),
        'sessions'       => (int) Database::scalar('SELECT COUNT(*) FROM admin_sessions WHERE admin_id = :a', ['a' => $agentId], 0),
        'payout request' => (int) Database::scalar('SELECT COUNT(*) FROM agent_payout_requests WHERE agent_admin_id = :a', ['a' => $agentId], 0),
        'login history'  => (int) Database::scalar('SELECT COUNT(*) FROM admin_login_events WHERE username = :u', ['u' => CH_AGENT], 0),
        // 16 Nov 2026: the rows that name the run without a foreign key
        'nav_use'        => (int) Database::scalar("SELECT COUNT(*) FROM kv_store WHERE kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $agentId], 0),
        'profile'        => (int) Database::scalar('SELECT COUNT(*) FROM user_profiles WHERE phone = :p', ['p' => CH_PHONE], 0),
        'journal / bell' => $journal,
    ];
    $left = array_sum($leftBy);
    check('cleanup: the run\'s bookings (+ journal / bell rows), agent (+ menu row), sessions, payout request, login history and passenger profile are gone',
        $left === 0, $left . ' row(s) left ' . json_encode(array_filter($leftBy)));
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
