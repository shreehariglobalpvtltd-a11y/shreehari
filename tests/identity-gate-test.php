<?php
/**
 * =====================================================================
 *  identity-gate-test.php — name + phone is never proof (8 Oct 2026)
 *
 *  The owner's identity rule (COMMON rule 7): booking stays open to
 *  anyone, but PRIVATE access — booking history, the keyless ticket,
 *  verify, cancel, undo, the AI's private tools, payment proof — needs
 *  the keyed link, an OTP-verified session, the PNR having been booked in
 *  THIS browser session, or staff. This suite pins:
 *
 *   A. (CLI) Auth::customerMayOpen / rememberOwnPnr / loginUser($proven)
 *      and the AI web identity (whoIsWeb verified, catalogue, mayRead,
 *      bus_eta never answers for "a bus")
 *   B. (HTTP, SHG_TEST_BASE) quick sign-in never renames the account or
 *      echoes the name on file; my-bookings = own-session PNRs + needsOtp
 *      for a quick session, everything after the WhatsApp code; keyless
 *      ticket refused for a quick session, served for an own-session PNR;
 *      cancel without the code → 403 needs_otp; payment proof from a
 *      stranger → 403; pay.php / pay-image.php ask only for what is due
 *      and nothing on a cancelled / paid booking; the OTP global ceiling
 *      answers sent:false; customer_otp_skip OFF closes the quick door;
 *      a Quick Ticket sale signs nobody in but its ticket still opens.
 *
 *  Cleans up every row it creates.
 *    SHG_TEST_BASE=http://localhost:8981 php -c .claude/php-dev.ini tests/identity-gate-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/customerprofile.php';
require_once INCLUDE_PATH . '/aitools.php';

// Auth::loginUser() regenerates the session id, which needs a live session (CLI has none by default).
// It also refuses once anything was printed, so the report is buffered and printed at the end.
ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

const IG_P1   = '9000000301';   // a returning customer with a name on file
const IG_P2   = '9000000302';   // a Quick Ticket passenger
const IG_P3   = '9000000303';   // a stranger
const IG_P4   = '9000000304';   // OTP-ceiling probe numbers 304..306
const IG_LIKE = '90000003%';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  {$l}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  {$l}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; }
}

$BASE = rtrim((string) (getenv('SHG_TEST_BASE') ?: ''), '/');
function req(string $jar, string $method, string $url, array $opt = []): array {
    global $BASE;
    $ch = curl_init($BASE . $url);
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 90]);
    if (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
        $headers[] = 'Content-Type: application/json';
    }
    if (!empty($opt['csrf'])) { $headers[] = 'X-CSRF-Token: ' . $opt['csrf']; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hs);
    $body = substr($raw, $hs);
    $loc  = preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '';
    $ctype = preg_match('/^Content-Type:\s*(.+)$/mi', $head, $m2) ? trim($m2[1]) : '';
    $json = json_decode($body, true);
    return ['code' => $code, 'json' => is_array($json) ? $json : null, 'body' => $body, 'location' => $loc, 'ctype' => $ctype, 'head' => $head];
}
function jar(string $n): string { $p = sys_get_temp_dir() . '/shg_ig_' . $n . '_' . getmypid() . '.cookies'; @unlink($p); return $p; }
function csrfFor(string $jar): string {
    $r = req($jar, 'GET', '/api/config.php');
    return (string) ($r['json']['data']['csrfToken'] ?? '');
}

/* ---- settings this suite pins; restored exactly as found ---- */
$PINNED = ['customer_otp_skip', 'otp_global_hourly_cap', 'allow_cod', 'quick_ticket_max_open', 'quick_ticket_customer_on'];
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
    Settings::flush();
};

$BUCKETS = ['login_quick', 'otp_request_ip', 'my_bookings', 'book_create', 'cancel', 'payment_proof', 'pay_open', 'pay_image',
            'ticket_dl', 'ticket_verify', 'quick_ticket_plan', 'quick_ticket_public', 'otp_send_global_h', 'otp_send_global_d', 'search'];
$cleanup = static function () use ($BUCKETS): void {
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone LIKE :p', ['p' => IG_LIKE]) as $b) {
        try { Seats::releaseBooking((int) $b['id']); } catch (Throwable $e) {}
        foreach (['booking_passengers', 'booking_legs', 'booking_seats', 'payment_screenshots', 'payments', 'tickets', 'agent_ledger', 'notifications', 'payment_events'] as $t) {
            try { Database::query("DELETE FROM {$t} WHERE booking_id = :b", ['b' => (int) $b['id']]); } catch (Throwable $e) {}
        }
        Database::query('DELETE FROM bookings WHERE id = :b', ['b' => (int) $b['id']]);
        foreach (glob(dirname(__DIR__) . '/tickets/*' . $b['pnr'] . '*') ?: [] as $f) { @unlink($f); }
    }
    try { Database::query('DELETE FROM user_profiles WHERE phone LIKE :p', ['p' => IG_LIKE]); } catch (Throwable $e) {}
    Database::query('DELETE FROM users WHERE phone LIKE :p', ['p' => IG_LIKE]);
    Database::query('DELETE FROM otp_codes WHERE identifier LIKE :p', ['p' => IG_LIKE]);
    Database::query('DELETE FROM rate_limits WHERE bucket IN (' . implode(',', array_fill(0, count($BUCKETS), '?')) . ')', $BUCKETS);
    Database::query("DELETE FROM rate_limits WHERE identifier LIKE '90000003%'");
    try { Database::query("DELETE FROM message_logs WHERE to_number LIKE '%90000003%'"); } catch (Throwable $e) {}
    try { Database::query("DELETE FROM notifications WHERE title = 'WhatsApp login codes paused — too many requests'"); } catch (Throwable $e) {}
    try { Database::query("DELETE FROM automation_log WHERE dedupe_key LIKE 'alert:otp_cap:%'"); } catch (Throwable $e) {}
};

echo "\n=== Identity gate — name + phone is never proof ===\n\n";
$cleanup();
Database::run('UPDATE coupons SET auto_apply = 5 WHERE auto_apply = 1');   // a running offer would change fares; restored below

try {
    /* =============================================================
     *  A. CLI — the one customer rule
     * ============================================================= */
    echo "-- A1. Auth::loginUser(proven: false) never takes over an existing account --\n";
    Database::insert('users', ['phone' => IG_P1, 'full_name' => 'Stored Name', 'role' => 'customer', 'country_code' => '977', 'phone_verified' => 0]);
    $uid1 = (int) Database::scalar('SELECT id FROM users WHERE phone = :p', ['p' => IG_P1]);
    $u = Auth::loginUser(IG_P1, 'Typed Stranger', 'IN', false);
    $row = Database::fetch('SELECT full_name, country_code, phone_verified FROM users WHERE id = :i', ['i' => $uid1]) ?? [];
    check('the name on file is not renamed', ($row['full_name'] ?? '') === 'Stored Name', (string) ($row['full_name'] ?? ''));
    check('  the country on file is not changed', ($row['country_code'] ?? '') === '977', (string) ($row['country_code'] ?? ''));
    check('  the number is not marked verified', (int) ($row['phone_verified'] ?? 1) === 0);
    check('  the session shows the TYPED name, never the stored one', (Auth::user()['name'] ?? '') === 'Typed Stranger', (string) (Auth::user()['name'] ?? ''));
    $u2 = Auth::loginUser(IG_P3, 'New Person', 'NP', false);
    $row3 = Database::fetch('SELECT full_name, country_code, phone_verified FROM users WHERE phone = :p', ['p' => IG_P3]) ?? [];
    check('a brand-new number still stores what was typed (unverified)', ($row3['full_name'] ?? '') === 'New Person' && ($row3['country_code'] ?? '') === '977' && (int) ($row3['phone_verified'] ?? 1) === 0, json_encode($row3));
    $uv = Auth::loginUser(IG_P1, '', 'NP', true);
    check('a proven sign-in marks the number verified', (int) Database::scalar('SELECT phone_verified FROM users WHERE id = :i', ['i' => $uid1]) === 1);
    unset($_SESSION[USER_SESSION_KEY], $_SESSION['shg_cust_otp']);
    Auth::forgetOwnPnrs();

    echo "-- A2. Auth::customerMayOpen --\n";
    $fake = ['pnr' => 'SHG-2026-99901', 'contact_phone' => IG_P1, 'user_id' => null];
    check('nobody signed in, nothing in the session → refused', Auth::customerMayOpen($fake) === false);
    Auth::rememberOwnPnr('shg-2026-99901');
    check('the PNR this session booked → allowed (case-insensitive)', Auth::customerMayOpen($fake) === true);
    check('  …but never a walk-in placeholder booking', Auth::customerMayOpen(['pnr' => 'SHG-2026-99901', 'contact_phone' => '0000000000']) === false);
    for ($i = 0; $i < 25; $i++) { Auth::rememberOwnPnr(sprintf('SHG-2026-9%04d', $i)); }
    Auth::rememberOwnPnr('SHG-2026-90024');
    $own = Auth::ownPnrs();
    check('rememberOwnPnr keeps the last 20, unique', count($own) === 20 && end($own) === 'SHG-2026-90024' && count(array_unique($own)) === 20 && !in_array('SHG-2026-99901', $own, true), count($own) . ' kept');
    Auth::forgetOwnPnrs();
    $_SESSION[USER_SESSION_KEY] = ['id' => $uid1, 'phone' => IG_P1, 'name' => 'x', 'role' => 'customer', 'last_seen' => time()];
    check('a name + mobile session on the right number → still refused', Auth::customerMayOpen($fake) === false);
    $_SESSION['shg_cust_otp'] = $uid1;
    check('the same session after the WhatsApp code → allowed (phone match)', Auth::customerMayOpen($fake) === true);
    check('  …and by user_id even when the contact is another number', Auth::customerMayOpen(['pnr' => 'X', 'contact_phone' => IG_P3, 'user_id' => $uid1]) === true);
    check('  …but not another number\'s booking', Auth::customerMayOpen(['pnr' => 'X', 'contact_phone' => IG_P3, 'user_id' => null]) === false);
    $_SESSION['shg_cust_otp'] = $uid1 + 1000;
    check('a code proven for ANOTHER account does not count', Auth::customerMayOpen($fake) === false);

    echo "-- A3. AI web assistant: unverified = guest --\n";
    $_SESSION['shg_cust_otp'] = 0;
    $q = AiTools::whoIsWeb();
    $qNames = array_column(AiTools::catalogue($q), 'name');
    check('a name + mobile session has NO number and is not verified', $q['phone'] === '' && $q['verified'] === false && $q['userId'] === $uid1, json_encode(['p' => $q['phone'], 'v' => $q['verified']]));
    check('  its memory is keyed on the browser session, never the typed number', str_starts_with((string) $q['stageKey'], 'web-guest-'), (string) $q['stageKey']);
    check('  no cancel / rename / ticket-fix tools', !array_intersect(['cancel_ticket', 'rename_passenger', 'quote_ticket_fix', 'fix_ticket'], $qNames), implode(',', array_intersect(['cancel_ticket', 'rename_passenger', 'quote_ticket_fix', 'fix_ticket'], $qNames)));
    $cancelTry = AiTools::run('cancel_ticket', ['pnr' => 'SHG-2026-99901', 'confirm' => true], $q + ['turn' => 1]);
    check('  naming cancel_ticket anyway is refused', $cancelTry['ok'] === false);
    $fixTry = (new ReflectionMethod('AiTools', 'fixTicket'));
    $fixTry->setAccessible(true);
    $fx = $fixTry->invoke(null, ['pnr' => 'SHG-2026-99901', 'confirm' => true], $q);
    check('  defence in depth: fixTicket itself says verify first', $fx['ok'] === false && str_contains((string) $fx['say'], 'WhatsApp code'), (string) $fx['say']);
    $_SESSION['shg_cust_otp'] = $uid1;
    $v = AiTools::whoIsWeb();
    $vNames = array_column(AiTools::catalogue($v), 'name');
    check('after the WhatsApp code: the number, verified, and cancel_ticket offered', $v['phone'] === IG_P1 && $v['verified'] === true && in_array('cancel_ticket', $vNames, true));
    $eta = AiTools::run('bus_eta', [], $v + ['turn' => 2]);
    check('bus_eta with no PNR asks for one — never answers for "the first route"', $eta['ok'] === true && !empty($eta['data']['needPnr']) && empty($eta['data']['stops']), (string) $eta['say']);
    $prompt = (string) file_get_contents(dirname(__DIR__) . '/includes/aiprompt.php') . (string) file_get_contents(dirname(__DIR__) . '/includes/aiagent.php');
    check('the prompts no longer advertise #/nav as the live bus map', !str_contains($prompt, 'live bus map at #/nav') && !str_contains($prompt, 'Track bus #/nav') && !str_contains($prompt, '#/nav (live bus map)'));
    unset($_SESSION[USER_SESSION_KEY], $_SESSION['shg_cust_otp']);

    echo "-- A4. static guards --\n";
    $src = static fn(string $f): string => (string) file_get_contents(dirname(__DIR__) . '/' . $f);
    foreach (['download-ticket.php', 'verify-ticket.php', 'api/cancel.php', 'includes/quickticket.php'] as $f) {
        check("$f uses Auth::customerMayOpen", str_contains($src($f), 'Auth::customerMayOpen('));
    }
    check('the login code greets with the name ON FILE, never the request\'s name', !preg_match("/sendLoginOtp\([^;]*Response::field\('name'/", $src('api/otp.php')));
    check('download-ticket has ONE void check, and a cancelled message', substr_count($src('download-ticket.php'), 'MAX(is_void)') === 1 && str_contains($src('download-ticket.php'), 'यो टिकट रद्द भएको छ'));

    /* =============================================================
     *  B. HTTP
     * ============================================================= */
    if ($BASE === '') {
        echo "\n  (B skipped: SHG_TEST_BASE not set)\n";
    } else {
        Settings::set('allow_cod', true, 'bool', 'payment', true);
        Settings::set('quick_ticket_max_open', 3, 'int', 'booking', false);
        Settings::set('quick_ticket_customer_on', true, 'bool', 'booking', true);
        Settings::set('customer_otp_skip', true, 'bool', 'customers', true);
        Settings::flush();
        Database::update('users', ['phone_verified' => 0], 'id = :i', ['i' => $uid1]);

        echo "\n-- B1. quick sign-in --\n";
        $jQ = jar('q'); $csQ = csrfFor($jQ);
        $r = req($jQ, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => IG_P1, 'name' => 'Typed Stranger', 'country' => 'IN'], 'csrf' => $csQ]);
        $d = $r['json']['data'] ?? [];
        check('quick sign-in works for booking (200, signedIn)', $r['code'] === 200 && !empty($d['signedIn']), 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 120));
        check('  but is NOT verified (top level and user payload)', ($d['verified'] ?? true) === false && ($d['user']['verified'] ?? true) === false && ($d['needsOtp'] ?? false) === true);
        check('  the reply never carries the name on file', !str_contains($r['body'], 'Stored Name') && ($d['user']['name'] ?? '') === 'Typed Stranger', (string) ($d['user']['name'] ?? ''));
        check('  the country on file comes back as the picker value', ($d['user']['country'] ?? '') === 'NP', (string) ($d['user']['country'] ?? ''));
        $cfg = req($jQ, 'GET', '/api/config.php');
        check('  /api/config.php does not leak the stored name either', !str_contains($cfg['body'], 'Stored Name'));
        $row = Database::fetch('SELECT full_name, country_code, phone_verified FROM users WHERE id = :i', ['i' => $uid1]) ?? [];
        check('  the account is not renamed, re-countried or verified', ($row['full_name'] ?? '') === 'Stored Name' && ($row['country_code'] ?? '') === '977' && (int) ($row['phone_verified'] ?? 1) === 0, json_encode($row));
        $r = req($jQ, 'GET', '/api/my-bookings.php');
        check('my-bookings for a quick session with no own tickets → [] + needsOtp', $r['code'] === 200 && ($r['json']['data']['bookings'] ?? null) === [] && ($r['json']['data']['needsOtp'] ?? false) === true, 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 120));
        $r = req(jar('g'), 'GET', '/api/my-bookings.php');
        check('a guest → 401 that names the WhatsApp code', $r['code'] === 401 && str_contains($r['body'], 'WhatsApp'), 'HTTP ' . $r['code']);

        echo "-- B2. bookings: one in each of two browsers, one pending --\n";
        $route = Database::fetch("SELECT id, from_city FROM routes WHERE route_code = 'r2' AND is_active = 1 LIMIT 1");
        $pnrA = $pnrB = $pnrPend = '';
        if ($route === null) {
            check('test route r2 exists', false);
        } else {
            $w = bookingWindow();
            $D = max($w['from'], addDaysISO(todayISO(), 5));
            if ($D > $w['to']) { $D = $w['to']; }
            Seats::schedule((int) $route['id'], $D);
            $avail = Seats::availability((int) $route['id'], $D, 'sharing');
            $free  = array_values(array_filter(array_diff($avail['available'] ?? [], $avail['blocked'] ?? [], $avail['locked'] ?? []),
                static fn($s) => str_starts_with((string) $s, 'U')));
            check('three free upper berths', count($free) >= 3, implode(',', array_slice($free, 0, 6)));
            $book = static function (string $jar, string $csrf, string $seat, bool $cod) use ($route, $D): array {
                return req($jar, 'POST', '/api/book.php', ['csrf' => $csrf, 'json' => [
                    'routeId' => (int) $route['id'], 'travelDate' => $D, 'seats' => [$seat],
                    'passengers' => [['seat' => $seat, 'name' => 'Gate Test', 'age' => 30, 'gender' => 'Male']],
                    'contact' => ['phone' => IG_P1, 'email' => '', 'idType' => '', 'idNum' => '', 'country' => 'NP'],
                    'bookingMode' => 'sharing', 'boarding' => '', 'drop' => '', 'originTown' => (string) $route['from_city'],
                    'paymentMethod' => $cod ? 'cod' : 'upi', 'isCod' => $cod,
                ]]);
            };
            $jA = jar('a'); $csA = csrfFor($jA);
            $r = $book($jA, $csA, $free[0] ?? 'U1', true);
            $pnrA = (string) ($r['json']['data']['pnr'] ?? '');
            check('guest browser A books (pay at counter → confirmed)', $r['code'] === 200 && ($r['json']['data']['status'] ?? '') === 'confirmed', 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 140));
            $jB = jar('b'); $csB = csrfFor($jB);
            $r = $book($jB, $csB, $free[1] ?? 'U2', true);
            $pnrB = (string) ($r['json']['data']['pnr'] ?? '');
            check('guest browser B books on the same number', $r['code'] === 200 && $pnrB !== '', 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 140));
            $r = $book($jA, $csA, $free[2] ?? 'U3', false);
            $pnrPend = (string) ($r['json']['data']['pnr'] ?? '');
            check('browser A books a second one by UPI (pending)', $r['code'] === 200 && ($r['json']['data']['status'] ?? '') === 'pending', 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 140));

            echo "-- B3. my-bookings --\n";
            $r = req($jA, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => IG_P1, 'name' => 'Gate Test', 'country' => 'NP'], 'csrf' => $csA]);
            $r = req($jA, 'GET', '/api/my-bookings.php?tab=all');
            $pnrs = array_column($r['json']['data']['bookings'] ?? [], 'pnr');
            sort($pnrs); $exp = [$pnrA, $pnrPend]; sort($exp);
            check('quick session in browser A sees ONLY its own two tickets + needsOtp', $r['code'] === 200 && $pnrs === $exp && ($r['json']['data']['needsOtp'] ?? false) === true, json_encode($pnrs));
            $r = req($jQ, 'GET', '/api/my-bookings.php?tab=all');
            check('the earlier quick session on the same number still sees none of them', ($r['json']['data']['bookings'] ?? null) === []);
            $jV = jar('v'); $csV = csrfFor($jV);
            $r = req($jV, 'POST', '/api/otp.php', ['json' => ['action' => 'request', 'phone' => IG_P1, 'purpose' => 'login', 'country' => 'NP'], 'csrf' => $csV]);
            $code = (string) ($r['json']['data']['debugCode'] ?? '');
            check('OTP request answers (debug code under APP_DEBUG)', $r['code'] === 200 && $code !== '', 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 120));
            $r = req($jV, 'POST', '/api/otp.php', ['json' => ['action' => 'verify', 'phone' => IG_P1, 'code' => $code, 'purpose' => 'login', 'country' => 'NP', 'name' => ''], 'csrf' => $csV]);
            check('the WhatsApp code verifies (user.verified true)', $r['code'] === 200 && ($r['json']['data']['user']['verified'] ?? false) === true && ($r['json']['data']['user']['name'] ?? '') === 'Stored Name', 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 120));
            $r = req($jV, 'GET', '/api/my-bookings.php?tab=all');
            $pnrsV = array_column($r['json']['data']['bookings'] ?? [], 'pnr');
            check('the verified session sees every booking on the number', in_array($pnrA, $pnrsV, true) && in_array($pnrB, $pnrsV, true) && in_array($pnrPend, $pnrsV, true) && ($r['json']['data']['needsOtp'] ?? true) === false, json_encode($pnrsV));

            echo "-- B4. keyless ticket + verify page --\n";
            $img = '/download-ticket.php?pnr=' . rawurlencode($pnrA) . '&img=1&view=1';
            $r = req($jQ, 'GET', $img);
            check('a quick session is refused the keyless ticket (302 to My Bookings)', $r['code'] === 302 && str_contains($r['location'], '#/my'), 'HTTP ' . $r['code'] . ' ' . $r['location']);
            $r = req($jA, 'GET', $img);
            check('the browser that booked it gets the image', $r['code'] === 200 && str_starts_with($r['body'], "\x89PNG"), 'HTTP ' . $r['code']);
            $r = req($jV, 'GET', $img);
            check('the verified owner gets it from any browser', $r['code'] === 200 && str_starts_with($r['body'], "\x89PNG"), 'HTTP ' . $r['code']);
            $r = req($jQ, 'GET', '/verify-ticket.php?pnr=' . rawurlencode($pnrA));
            check('verify page: a quick session gets "open it from the message"', $r['code'] === 200 && str_contains($r['body'], 'Verification unavailable') && !str_contains($r['body'], 'Gate T'));
            $r = req($jA, 'GET', '/verify-ticket.php?pnr=' . rawurlencode($pnrA));
            check('verify page: own-session PNR → Valid, cash due row, bilingual labels, no raw stop tokens',
                str_contains($r['body'], 'Valid') && str_contains($r['body'], 'नगद बाँकी · Cash due') && str_contains($r['body'], 'यात्रु · Passenger')
                && !preg_match('/class="v">[^<]*\[\d/', $r['body']) && !preg_match('/class="v">[^<]*@ \d/', $r['body']) && str_contains($r['body'], "localStorage.getItem('shg:theme')"));

            echo "-- B5. cancel --\n";
            $r = req($jQ, 'POST', '/api/cancel.php', ['json' => ['pnr' => $pnrB, 'phone' => IG_P1], 'csrf' => $csQ]);
            check('cancel with the right phone but no code → 403 needs_otp', $r['code'] === 403 && ($r['json']['fields']['code'] ?? '') === 'needs_otp', 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 140));
            check('  …and the booking is untouched', (string) Database::scalar('SELECT status FROM bookings WHERE pnr = :p', ['p' => $pnrB]) === 'confirmed');
            $r = req($jV, 'POST', '/api/cancel.php', ['json' => ['pnr' => $pnrB, 'reason' => 'gate test'], 'csrf' => $csV]);
            check('the verified owner may cancel', $r['code'] === 200 && (string) Database::scalar('SELECT status FROM bookings WHERE pnr = :p', ['p' => $pnrB]) === 'cancelled', 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 140));
            $r = req($jV, 'GET', '/download-ticket.php?pnr=' . rawurlencode($pnrB) . '&img=1');
            check('a cancelled ticket answers 403 "cancelled", not "awaiting payment"', $r['code'] === 403 && str_contains($r['body'], 'cancelled'), 'HTTP ' . $r['code'] . ' ' . $r['body']);

            echo "-- B6. pay.php / pay-image.php --\n";
            $pe0 = (int) Database::scalar('SELECT COUNT(*) FROM payment_events WHERE pnr = :p', ['p' => $pnrB], 0);
            $r = req(jar('p1'), 'GET', '/pay.php?pnr=' . rawurlencode($pnrB));
            check('pay.php on a cancelled booking → 200, no Location, "nothing due"', $r['code'] === 200 && $r['location'] === '' && str_contains($r['body'], 'No payment is due') && !str_contains($r['body'], 'upi://'), 'HTTP ' . $r['code'] . ' ' . $r['location']);
            check('  …and logs no pay_open event', (int) Database::scalar('SELECT COUNT(*) FROM payment_events WHERE pnr = :p', ['p' => $pnrB], 0) === $pe0);
            $totA = (float) Database::scalar('SELECT total_amount FROM bookings WHERE pnr = :p', ['p' => $pnrA]);
            $r = req(jar('p2'), 'GET', '/pay.php?pnr=' . rawurlencode($pnrA));
            check('an unpaid pay-at-counter ticket → 302 upi:// for the amount due', $r['code'] === 302 && str_starts_with($r['location'], 'upi://') && str_contains($r['location'], 'am=' . number_format($totA, 2, '.', '')), 'HTTP ' . $r['code'] . ' ' . $r['location']);
            $idA = (int) Database::scalar('SELECT id FROM bookings WHERE pnr = :p', ['p' => $pnrA]);
            Database::query("UPDATE payments SET status = 'verified', amount = :a WHERE booking_id = :b", ['a' => $totA, 'b' => $idA]);
            $r = req(jar('p3'), 'GET', '/pay.php?pnr=' . rawurlencode($pnrA));
            check('once the cash is settled → 200, no Location', $r['code'] === 200 && $r['location'] === '', 'HTTP ' . $r['code'] . ' ' . $r['location']);
            $r = req(jar('p4'), 'GET', '/pay-image.php?pnr=' . rawurlencode($pnrB));
            $sz = @getimagesizefromstring($r['body']);
            check('pay-image on a cancelled booking → a small "Nothing to pay" PNG (no QR)', $r['code'] === 200 && is_array($sz) && $sz[0] === 900 && $sz[1] === 600 && str_contains(strtolower($r['head']), 'no-store'), 'HTTP ' . $r['code'] . ' ' . json_encode(is_array($sz) ? [$sz[0], $sz[1]] : null));
            $r = req(jar('p5'), 'GET', '/pay-image.php?pnr=' . rawurlencode($pnrPend));
            $sz = @getimagesizefromstring($r['body']);
            check('pay-image on a pending booking → the full payment ticket', $r['code'] === 200 && is_array($sz) && $sz[1] === 1250, 'HTTP ' . $r['code']);

            echo "-- B7. payment proof --\n";
            $idP = (int) Database::scalar('SELECT id FROM bookings WHERE pnr = :p', ['p' => $pnrPend]);
            $n0  = (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE booking_id = :b AND utr_number IS NOT NULL AND utr_number <> \'\'', ['b' => $idP]);
            $jS = jar('s'); $csS = csrfFor($jS);
            $r = req($jS, 'POST', '/api/payment.php', ['json' => ['pnr' => $pnrPend, 'utr' => '123456789012', 'method' => 'upi'], 'csrf' => $csS]);
            check('proof from a stranger\'s browser → 403', $r['code'] === 403 && str_contains($r['body'], 'mobile number used for this booking'), 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 120));
            $r = req($jS, 'POST', '/api/payment.php', ['json' => ['pnr' => $pnrPend, 'utr' => '123456789012', 'method' => 'upi', 'phone' => IG_P3], 'csrf' => $csS]);
            check('  …and with the wrong mobile → 403', $r['code'] === 403);
            check('  nothing was recorded for the stranger', (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE booking_id = :b AND utr_number IS NOT NULL AND utr_number <> \'\'', ['b' => $idP]) === $n0);
            $r = req($jS, 'POST', '/api/payment.php', ['json' => ['pnr' => $pnrPend, 'utr' => '123456789012', 'method' => 'upi', 'phone' => '+977 ' . IG_P1], 'csrf' => $csS]);
            check('the booking\'s own mobile typed on another device → accepted', $r['code'] === 200, 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 120));

            echo "-- B8. Quick Ticket: no sign-in, own ticket still opens --\n";
            $jT = jar('t'); $csT = csrfFor($jT);
            $r = req($jT, 'POST', '/api/quick-ticket.php', ['json' => ['action' => 'customer_sell', 'name' => 'Quick Gate', 'phone' => IG_P2, 'country' => '977', 'seats' => 1], 'csrf' => $csT]);
            $qPnr = (string) ($r['json']['data']['pnr'] ?? '');
            check('a guest sells a Quick Ticket', $r['code'] === 200 && $qPnr !== '', 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 160));
            if ($qPnr !== '') {
                check('  the reply carries no signed-in user', !array_key_exists('user', (array) ($r['json']['data'] ?? [])));
                check('  +977 picked on the card → stamped 977', (string) Database::scalar('SELECT contact_country_code FROM bookings WHERE pnr = :p', ['p' => $qPnr]) === '977');
                $r2 = req($jT, 'GET', '/api/my-bookings.php');
                check('  the browser is NOT signed in (my-bookings 401)', $r2['code'] === 401, 'HTTP ' . $r2['code']);
                check('  no account was created or renamed for the typed number', Database::scalar('SELECT id FROM users WHERE phone = :p', ['p' => IG_P2]) === null);
                $r2 = req($jT, 'GET', '/download-ticket.php?pnr=' . rawurlencode($qPnr) . '&img=1&view=1');
                check('  its ticket image still opens in this browser', $r2['code'] === 200 && str_starts_with($r2['body'], "\x89PNG"), 'HTTP ' . $r2['code']);
                $jT2 = jar('t2'); $csT2 = csrfFor($jT2);
                $r2 = req($jT2, 'POST', '/api/quick-ticket.php', ['json' => ['action' => 'customer_undo', 'pnr' => $qPnr], 'csrf' => $csT2]);
                check('  another browser cannot undo it', $r2['code'] !== 200, 'HTTP ' . $r2['code']);
                $r2 = req($jT, 'POST', '/api/quick-ticket.php', ['json' => ['action' => 'customer_undo', 'pnr' => $qPnr], 'csrf' => $csT]);
                check('  the browser that bought it undoes it free', $r2['code'] === 200 && (string) Database::scalar('SELECT status FROM bookings WHERE pnr = :p', ['p' => $qPnr]) === 'cancelled', 'HTTP ' . $r2['code'] . ' ' . substr($r2['body'], 0, 120));
            }
        }

        echo "-- B9. the code request --\n";
        Database::query("DELETE FROM rate_limits WHERE bucket = 'otp_request_ip'");
        $jO = jar('o'); $csO = csrfFor($jO);
        $r = req($jO, 'POST', '/api/otp.php', ['json' => ['action' => 'request', 'phone' => IG_P3, 'purpose' => 'login', 'country' => 'IN', 'name' => 'CALL 9999999999 NOW'], 'csrf' => $csO]);
        check('a code request never echoes the typed name', $r['code'] === 200 && !str_contains($r['body'], 'CALL 9999'), 'HTTP ' . $r['code']);
        Settings::set('otp_global_hourly_cap', 1, 'int', 'security', false); Settings::flush();
        Database::query("DELETE FROM rate_limits WHERE bucket IN ('otp_send_global_h','otp_send_global_d')");
        $r1 = req($jO, 'POST', '/api/otp.php', ['json' => ['action' => 'request', 'phone' => IG_P4, 'purpose' => 'login', 'country' => 'IN'], 'csrf' => $csO]);
        $r2 = req($jO, 'POST', '/api/otp.php', ['json' => ['action' => 'request', 'phone' => '9000000305', 'purpose' => 'login', 'country' => 'IN'], 'csrf' => $csO]);
        check('under the global ceiling a code is issued', $r1['code'] === 200 && empty($r1['json']['data']['capped']) && !empty($r1['json']['data']['debugCode']), 'HTTP ' . $r1['code'] . ' ' . substr($r1['body'], 0, 100));
        check('past the global ceiling: 200 with sent:false, no code issued', $r2['code'] === 200 && ($r2['json']['data']['sent'] ?? true) === false && !empty($r2['json']['data']['capped']) && empty($r2['json']['data']['debugCode'])
            && (int) Database::scalar("SELECT COUNT(*) FROM otp_codes WHERE identifier = '9000000305'") === 0, substr($r2['body'], 0, 140));
        check('  the office bell rang once', (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE title = 'WhatsApp login codes paused — too many requests'") === 1);
        $restoreSettings();
        Database::query("DELETE FROM rate_limits WHERE bucket IN ('otp_send_global_h','otp_send_global_d')");

        echo "-- B10. customer_otp_skip OFF closes the quick door --\n";
        Settings::set('customer_otp_skip', false, 'bool', 'customers', true); Settings::flush();
        $jK = jar('k'); $csK = csrfFor($jK);
        $r = req($jK, 'POST', '/api/otp.php', ['json' => ['action' => 'quick', 'phone' => IG_P3, 'name' => 'Some One'], 'csrf' => $csK]);
        check('quick → 403 "verify with the WhatsApp code"', $r['code'] === 403 && str_contains($r['body'], 'WhatsApp'), 'HTTP ' . $r['code']);
        $restoreSettings();
    }
} catch (Throwable $e) {
    check('suite ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    unset($_SESSION[USER_SESSION_KEY], $_SESSION['shg_cust_otp'], $_SESSION['shg_own_pnrs']);
    $restoreSettings();
    Database::run('UPDATE coupons SET auto_apply = 1 WHERE auto_apply = 5');
    $cleanup();
    foreach (glob(sys_get_temp_dir() . '/shg_ig_*_' . getmypid() . '.cookies') ?: [] as $f) { @unlink($f); }
}

echo "\n----------------------------------------\n";
echo "{$PASS} passed, {$FAIL} failed\n";
exit($FAIL > 0 ? 1 : 0);
