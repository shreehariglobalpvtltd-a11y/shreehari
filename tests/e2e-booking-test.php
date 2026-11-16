<?php
/**
 * =====================================================================
 *  End-to-end truth test — the whole ticket lifecycle over real HTTP.
 *
 *  Unlike the other suites in here (which call the service classes
 *  directly), this one drives the running site the way a browser and a
 *  member of staff would, and then checks the database behind it. It is
 *  the test that answers "did the customer's ticket actually reach the
 *  admin panel", which is the question no unit test can settle:
 *
 *      guest books, no login  →  bookings row  →  admin sees it
 *      COD stays unpaid       →  admin records the cash  →  settled
 *      guest is locked out of every admin page
 *      an agent is locked out of everything that is not theirs
 *
 *  REQUIREMENTS
 *    1. SHG_TEST_BASE=http://localhost:<port> names the dev server; with it
 *       unset the suite starts its OWN php -S on a free port serving THIS
 *       checkout and stops it on exit (16 Nov 2026, as manifest-test.php).
 *    2. curl must be loaded — the dev ini does not enable it:
 *
 *       php -c .claude/php-dev.ini -d extension=php_curl.dll \
 *           tests/e2e-booking-test.php
 *
 *    3. Local staff passwords. The suite assumes the two dev accounts use
 *       the passwords below; override with SHG_ADMIN_PW / SHG_AGENT_PW, or
 *       set them once against the LOCAL test database:
 *
 *       UPDATE admins SET password_hash = '<bcrypt of Admin@12345>'
 *        WHERE username = 'superadmin';
 *
 *  It writes one real booking per run (a COD sale on the next free seat),
 *  which is why it points at the local shari_test database and must never
 *  be aimed at production. Since 16 Nov 2026 it removes that booking (and
 *  its message / delivery / audit rows and ticket files), the two sign-in
 *  accounts and their sessions / login history again on exit.
 * =====================================================================
 */
declare(strict_types=1);

/* CLI only. This file creates a real booking and its cleanup deletes rows,
   so it must never be reachable as a URL. Every other suite in here carries
   the same guard; this one was missing it, which left a public endpoint that
   writes to whichever database the server it is uploaded to is pointed at. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* 16 Nov 2026: the app is loaded for the FIXTURES only - QuickUnlock::set
   gives the sign-in accounts a PIN (the code-first door, 15 Nov, sends a
   PIN-less account to pin-setup.php before any page) and Seats /
   Database remove the run's booking cleanly at the end. Every check still
   goes over real HTTP and reads the DB through pdo() below. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/quickunlock.php';

/* Overridable so a second worktree can be tested without stealing :8899
   from the one that already holds it — a suite pointed at ANOTHER
   checkout's server reports green for code you did not change.
       SHG_TEST_BASE=http://localhost:8898 php ...
   16 Nov 2026: with SHG_TEST_BASE unset the suite starts its OWN php -S on
   a free port serving THIS checkout (same php + ini as this run) and stops
   it on exit - the battery passes no base, and :8899 is whichever checkout
   owns it (the manifest-test.php Y7 fix). SHG_TEST_BASE still wins. */
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
$jar = sys_get_temp_dir() . '/shg_e2e_' . getmypid() . '.cookies';
$adminJar = sys_get_temp_dir() . '/shg_e2e_admin_' . getmypid() . '.cookies';
@unlink($jar); @unlink($adminJar);

function req(string $method, string $url, array $opt = []): array {
    $ch = curl_init(BASE . $url);
    $headers = $opt['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $opt['jar'],
        CURLOPT_COOKIEFILE     => $opt['jar'],
        CURLOPT_FOLLOWLOCATION => $opt['follow'] ?? false,
        CURLOPT_HEADER         => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
        $headers[] = 'Content-Type: application/json';
    } elseif (isset($opt['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form']));
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    $loc  = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);   // where a 302 points (not followed)
    curl_close($ch);
    return ['code' => $code, 'body' => (string) $body, 'err' => $err, 'location' => $loc];
}

/* The page's CSRF token: the <meta name="csrf-token"> every staff page
   carries, else the hidden form field. */
function csrfOfPage(string $html): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m) === 1) { return html_entity_decode($m[1]); }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

/* 16 Nov 2026: the staff door is code-first (15 Nov) - the bare
   /admin/login.php is the code box. The username + password sign-in is the
   classic door, ?classic=1 (the office's recovery route, and the one every
   HTTP suite uses); its post carries classic=1. Returns [page, post]. */
function staffSignIn(string $jar, string $user, string $pw): array {
    $page = req('GET', '/admin/login.php?classic=1&other=1', ['jar' => $jar]);
    $post = req('POST', '/admin/login.php', ['jar' => $jar, 'form' => [
        CSRF_TOKEN_NAME => csrfOfPage($page['body']), 'classic' => '1', 'username' => $user, 'password' => $pw,
    ]]);
    return [$page, $post];
}

function pdo(): PDO {
    static $p = null;
    if ($p === null) {
        $p = new PDO('mysql:host=127.0.0.1;port=3307;dbname=shari_test;charset=utf8mb4', 'root', '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $p;
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label" . ($detail ? " — $detail" : '') . "\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail ? " — $detail" : '') . "\n"; }
}

/* Stop before a single assertion runs if the dev server is not up.
   Without this the suite reports 24 confusing failures — and, worse, some
   assertions PASS for the wrong reason: "GET /admin/pending-count.php
   refuses guest — HTTP 0" is scored a pass when the request never
   connected at all. A security assertion that succeeds because nothing
   answered is more dangerous than a failing one. */
(function (): void {
    $ch = curl_init(BASE . '/index.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $code === 0) {
        fwrite(STDERR, "\n  CANNOT RUN — no server answering at " . BASE . "\n\n");
        fwrite(STDERR, "  This suite drives the site over real HTTP. Start it first:\n");
        fwrite(STDERR, "      Claude Code:  preview_start \"shari-php\"\n");
        fwrite(STDERR, "      or:  php -c .claude/php-dev.ini -S localhost:8899 -t public_html\n\n");
        exit(2);
    }
})();

/* Deterministic sign-in accounts for TEST 4 / TEST 8. The suite drives real
   HTTP sign-ins, so it needs accounts whose passwords it knows; rather than
   depend on (and risk clobbering) the operator's own 'superadmin' / 'agent1'
   dev passwords, it provisions two DEDICATED throwaway accounts with known
   passwords and must_change_pw=0, and deletes them at the end. Passwords may
   still be overridden with SHG_ADMIN_PW / SHG_AGENT_PW.
   16 Nov 2026: the names carry a per-run tag. Several agents and the battery
   run suites against the same shari_test at once; with fixed names one run's
   cleanup deleted the other's live accounts mid-run and orphaned their
   sessions. E2E_STALE_PREFIXES finds what an older run left behind. */
const E2E_STALE_PREFIXES = ['e2e-superadmin', 'e2e-agent'];
define('E2E_RUN', bin2hex(random_bytes(3)));
define('E2E_ADMIN_USER', 'e2e-superadmin-' . E2E_RUN);
define('E2E_AGENT_USER', 'e2e-agent-' . E2E_RUN);
$E2E_ADMIN_PW = getenv('SHG_ADMIN_PW') ?: 'Admin@12345';
$E2E_AGENT_PW = getenv('SHG_AGENT_PW') ?: 'Agent@12345';

const E2E_PIN        = '582614';   // any valid 6-digit PIN (QuickUnlock::pinProblem)
const E2E_EMAIL      = 'e2e@example.com';
const E2E_PAX        = 'E2E Guest Tester';

function ensureStaff(string $username, string $role, string $password, ?string $phone): int {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $existing = pdo()->prepare('SELECT id FROM admins WHERE username = ?');
    $existing->execute([$username]);
    $id = (int) $existing->fetchColumn();
    if ($id > 0) {
        $u = pdo()->prepare('UPDATE admins SET password_hash=?, role=?, phone=?, is_active=1, must_change_pw=0, failed_logins=0, locked_until=NULL WHERE id=?');
        $u->execute([$hash, $role, $phone, $id]);
    } else {
        $i = pdo()->prepare('INSERT INTO admins (username, password_hash, full_name, phone, role, is_active, must_change_pw) VALUES (?,?,?,?,?,1,0)');
        $i->execute([$username, $hash, 'E2E ' . ucfirst($role), $phone, $role]);
        $id = (int) pdo()->lastInsertId();
    }
    /* 16 Nov 2026: the code-first door (15 Nov) sends an account with no PIN /
       pattern to pin-setup.php before any page (Auth::pinSetupNeeded) - give
       it one, as every real office / agent login now has. */
    QuickUnlock::set($id, 'pin', E2E_PIN);
    return $id;
}

/* Everything one run leaves behind, removed on exit (also after a crash):
   the booking (seats released first, so the berth is sellable again; its
   children cascade) plus the rows that point at it without a foreign key
   (message / delivery logs, the ticket link, its audit trail) and the
   ticket files; then the two accounts with their sessions (admin_sessions
   has no FK to admins), login history and audit rows. */
function e2eRemoveBooking(int $id, string $pnr): void {
    if ($id <= 0) { return; }
    $row = Database::fetch('SELECT contact_phone, created_at FROM bookings WHERE id = :i', ['i' => $id]);
    try { Seats::releaseBooking($id); } catch (Throwable $e) {}
    foreach (['message_logs', 'delivery_log', 'ticket_links', 'wa_outbox', 'wa_ticket_queue', 'msg_ledger', 'payment_events'] as $t) {
        try { Database::delete($t, 'booking_id = :b', ['b' => $id]); } catch (Throwable $e) {}
    }
    try { Database::query("DELETE FROM audit_logs WHERE entity_type = 'booking' AND entity_id IN (:p, :i)", ['p' => $pnr, 'i' => (string) $id]); } catch (Throwable $e) {}
    e2eForgetPnr($pnr);
    try { Database::delete('bookings', 'id = :i', ['i' => $id]); } catch (Throwable $e) {}
    if ($row !== null) {
        e2eForgetProfile((string) $row['contact_phone'], (string) $row['created_at']);
    }
    if ($pnr !== '') {
        foreach (glob(dirname(__DIR__) . '/tickets/ticket_' . $pnr . '.*') ?: [] as $f) { @unlink($f); }
    }
}
/* Rows that name the PNR without a foreign key (16 Nov 2026): the guest
   booking rings the office bell "New online customer · <PNR>"
   (AdminAlerts::onlineBooking - a notifications row with booking_id NULL,
   so deleting the booking does not cascade to it, plus its automation_log
   claim alert:online:<PNR>), and the EventBus journal names the PNR in the
   booking.created / approved / cod_settled rows' `data`. */
function e2eForgetPnr(string $pnr): void {
    if ($pnr === '') { return; }
    try { Database::query('DELETE FROM automation_log WHERE data LIKE :d OR dedupe_key LIKE :k1 OR dedupe_key LIKE :k2',
        ['d' => '%"' . $pnr . '"%', 'k1' => '%:' . $pnr, 'k2' => '%:' . $pnr . ':%']); } catch (Throwable $e) {}
    try { Database::query('DELETE FROM notifications WHERE booking_id IS NULL AND (link LIKE :l1 OR link LIKE :l2)',
        ['l1' => '%pnr=' . $pnr, 'l2' => '%pnr=' . $pnr . '&%']); } catch (Throwable $e) {}
}
/* The customer-master row (user_profiles) the booking opened for its contact
   number - only one the booking itself created (not older than the booking)
   and only when no other booking is on that number. */
function e2eForgetProfile(string $phone, string $bookedAt): void {
    if ($phone === '' || $bookedAt === '') { return; }
    try { Database::query('DELETE FROM user_profiles WHERE phone = :p AND created_at >= :c - INTERVAL 1 MINUTE
                            AND NOT EXISTS (SELECT 1 FROM bookings WHERE contact_phone = :p2)', ['p' => $phone, 'p2' => $phone, 'c' => $bookedAt]); } catch (Throwable $e) {}
}
function e2eRemoveAccount(int $aid): void {
    try { Database::delete('admin_sessions', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
    try { Database::query("DELETE FROM audit_logs WHERE entity_type = 'admin' AND entity_id = :a", ['a' => (string) $aid]); } catch (Throwable $e) {}
    // the "recent pages" menu row admin/_guard.php keeps per account (OpsHub::trackPage; no FK to admins)
    try { Database::query("DELETE FROM kv_store WHERE kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $aid]); } catch (Throwable $e) {}
    try { Database::delete('admins', 'id = :a', ['a' => $aid]); } catch (Throwable $e) {}   // admin_devices cascades
}
function e2eCleanup(string $pnr): void {
    if ($pnr !== '') {
        foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE pnr = :p', ['p' => $pnr]) as $r) {
            e2eRemoveBooking((int) $r['id'], (string) $r['pnr']);
        }
    }
    foreach ([E2E_ADMIN_USER, E2E_AGENT_USER] as $u) {
        $aid = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
        if ($aid > 0) {
            e2eRemoveAccount($aid);
        }
        try { Database::delete('admin_login_events', 'username = :u', ['u' => $u]); } catch (Throwable $e) {}
    }
}
/* What an EARLIER run left (it died, or ran an older copy with fixed names):
   only bookings / accounts over an hour old, so a run going on right now is
   never touched; accounts already deleted (whose sessions were orphaned) are
   found through their login history. */
function e2eSweepStale(): void {
    foreach (Database::fetchAll(
        "SELECT DISTINCT b.id, b.pnr FROM bookings b JOIN booking_passengers p ON p.booking_id = b.id
          WHERE b.contact_email = :e AND p.full_name = :n AND b.created_at < NOW() - INTERVAL 1 HOUR",
        ['e' => E2E_EMAIL, 'n' => E2E_PAX]) as $r) {
        e2eRemoveBooking((int) $r['id'], (string) $r['pnr']);
    }
    foreach (E2E_STALE_PREFIXES as $pre) {
        $like = $pre . '%';
        foreach (Database::fetchAll('SELECT id FROM admins WHERE username LIKE :u AND created_at < NOW() - INTERVAL 1 HOUR', ['u' => $like]) as $a) {
            e2eRemoveAccount((int) $a['id']);
        }
        $gone = Database::fetchAll('SELECT DISTINCT e.admin_id AS id FROM admin_login_events e LEFT JOIN admins a ON a.id = e.admin_id
                                     WHERE e.username LIKE :u AND e.admin_id IS NOT NULL AND a.id IS NULL', ['u' => $like]);
        $gone = array_merge($gone, Database::fetchAll("SELECT DISTINCT l.actor_id AS id FROM audit_logs l LEFT JOIN admins a ON a.id = l.actor_id
                                     WHERE l.action = 'admin.login' AND l.actor_name LIKE :u AND l.actor_id IS NOT NULL AND a.id IS NULL", ['u' => $like]));
        foreach ($gone as $o) {
            e2eRemoveAccount((int) $o['id']);   // the account is gone; its sessions / audit rows are not
        }
        try { Database::query('DELETE e FROM admin_login_events e LEFT JOIN admins a ON a.id = e.admin_id
                                WHERE e.username LIKE :u AND a.id IS NULL', ['u' => $like]); } catch (Throwable $e) {}
    }
}
$pnr = '';
register_shutdown_function(static function (): void {
    global $pnr;
    e2eCleanup((string) $pnr);
});

// Clear this IP's login throttle (repeated e2e runs would otherwise trip it —
// a known local trap) and provision the sign-in accounts before TEST 4 runs.
try { pdo()->exec("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
e2eSweepStale();
$E2E_ADMIN_ID = ensureStaff(E2E_ADMIN_USER, 'superadmin', $E2E_ADMIN_PW, null);
$E2E_AGENT_ID = ensureStaff(E2E_AGENT_USER, 'agent', $E2E_AGENT_PW, '919812300000');

// TEST 11 compares against this: the run must not LOSE a legacy row.
$legacyBefore = (int) pdo()->query("SELECT COUNT(*) FROM bookings WHERE pnr LIKE 'SHG-R%'")->fetchColumn();

echo "\n=== TEST 1 — guest opens the site, no login ===\n";
$home = req('GET', '/', ['jar' => $jar]);
check('GET / returns 200', $home['code'] === 200, 'HTTP ' . $home['code']);
preg_match('/window\.SHG_BOOT = (\{.*?\});/s', $home['body'], $m);
$boot = $m ? json_decode($m[1], true) : null;
check('boot payload present', is_array($boot));
check('guest has no user session', ($boot['user'] ?? null) === null);
$csrf = $boot['csrf'] ?? '';
check('CSRF token issued to guest', $csrf !== '');

echo "\n=== search (public) ===\n";
$date = date('Y-m-d', strtotime('+3 days'));

/* Discover a LIVE route straight from the DB rather than hard-coding demo
   cities. The operation is now one daily Surat -> Rupaidiha sleeper and the
   old Ahmedabad -> Nepalgunj seater is switched off, so a fixed search would
   test a route the company no longer runs. Searching by an ACTIVE route's own
   endpoints keeps this honest whatever the configured service is. */
$activeRoute = pdo()->query(
    "SELECT from_city, to_city, coach_type FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1"
)->fetch();
check('an active route exists to book against', $activeRoute !== false,
    $activeRoute ? ($activeRoute['from_city'] . ' -> ' . $activeRoute['to_city'])
                 : 'no active routes — run Admin -> Routes -> Apply daily service');
$searchFrom = $activeRoute['from_city'] ?? 'Surat';
$searchTo   = $activeRoute['to_city'] ?? 'Rupaidiha';

$s = req('POST', '/api/search.php', ['jar' => $jar, 'json' => ['from' => $searchFrom, 'to' => $searchTo, 'date' => $date]]);
$sj = json_decode($s['body'], true);
check('search.php ok', ($sj['ok'] ?? false) === true, 'HTTP ' . $s['code'] . ' ' . substr($s['body'], 0, 160));
$route = $sj['data']['results'][0] ?? null;
check('a bookable route came back', $route !== null,
    $route ? ('routeId=' . $route['routeId'] . ' ' . $route['coachType'] . ' seatsLeft=' . $route['seatsLeft']) : '');

/* A sleeper sells as a shared cabin (the gender rule applies); a seater has no
   cabin mode. Board/drop must be REAL stops on this coach or the pickup
   cut-off refuses the sale, so take them from the search result itself. */
$isSleeper   = ($route['coachType'] ?? '') === 'sleeper';
$bookingMode = $isSleeper ? 'sharing' : null;
// api/search.php returns each stop as a canonical string
// ("Name · Landmark @ HH:MM [lat,lng]"); older code shipped raw
// route_stops rows. Accept either shape so the suite still runs
// against a server on either side of the fix.
$firstStop = static function ($stops, string $fallback): string {
    if (!is_array($stops) || $stops === []) return $fallback;
    $s = $stops[0];
    if (is_string($s)) return $s;
    if (is_array($s) && isset($s['stop_name'])) return (string) $s['stop_name'];
    return $fallback;
};
$boardStop = $firstStop($route['boarding'] ?? [], $searchFrom);
$dropStop  = $firstStop($route['drop']     ?? [], $searchTo);

echo "\n=== seat availability (public) ===\n";
$av = req('POST', '/api/seats.php', ['jar' => $jar, 'json' => ['routeId' => $route['routeId'], 'date' => $date]]);
$avj = json_decode($av['body'], true);
check('seats.php ok', ($avj['ok'] ?? false) === true, substr($av['body'], 0, 160));
$free = $avj['data']['available'] ?? [];
check('server reports free seats', count($free) > 0, count($free) . ' free; booked=' . json_encode($avj['data']['booked'] ?? []));
/* 4 Sep 2026: the operator's women-only seats are enforced for a male
   passenger, so the guest test books the first free seat that is NOT on
   that list (L1..L3 by default). */
$femList = $avj['data']['female'] ?? [];
$seat = null;
foreach ($free as $cand) { if (!in_array($cand, $femList, true)) { $seat = $cand; break; } }
$seat = $seat ?? $free[0];

echo "\n=== TEST 10 — guest books with CASH ON DELIVERY ===\n";
/* 16 Nov 2026: a number nobody has yet (no booking, account or customer
   profile), so the profile the booking opens is this run's own to remove. */
do {
    $phone = '98' . random_int(10000000, 99999999);
} while ((int) Database::scalar('SELECT (SELECT COUNT(*) FROM bookings WHERE contact_phone = :a) + (SELECT COUNT(*) FROM users WHERE phone = :b)
                                  + (SELECT COUNT(*) FROM user_profiles WHERE phone = :c)', ['a' => $phone, 'b' => $phone, 'c' => $phone], 0) > 0);
$bk = req('POST', '/api/book.php', [
    'jar' => $jar,
    'headers' => ['X-CSRF-Token: ' . $csrf],
    'json' => [
        'routeId' => $route['routeId'], 'travelDate' => $date, 'seats' => [$seat],
        'passengers' => [['seat' => $seat, 'name' => E2E_PAX, 'age' => 30, 'gender' => 'Male']],
        'contact' => ['phone' => $phone, 'email' => E2E_EMAIL, 'idType' => 'Voter ID', 'idNum' => 'GJE2E0001'],
        'bookingMode' => $bookingMode, 'boarding' => $boardStop, 'drop' => $dropStop,
        'paymentMethod' => 'cod', 'isCod' => true,
    ],
]);
$bj = json_decode($bk['body'], true);
check('book.php accepted a GUEST booking (no login)', ($bj['ok'] ?? false) === true,
    'HTTP ' . $bk['code'] . ' ' . substr($bk['body'], 0, 300));
$pnr = $bj['data']['pnr'] ?? '';
echo "\n  >>> TEST TICKET ID: $pnr <<<\n\n";

echo "=== TEST 2 — the ticket is a real database record ===\n";
$row = pdo()->prepare('SELECT * FROM bookings WHERE pnr = ?');
$row->execute([$pnr]);
$b = $row->fetch();
check('bookings row exists', $b !== false, $b ? ('id=' . $b['id'] . ' status=' . $b['status']) : 'MISSING');
$payQ = pdo()->prepare('SELECT * FROM payments WHERE booking_id = ?');
$payQ->execute([$b['id'] ?? 0]);
$pay = $payQ->fetch();
check('payments row exists', $pay !== false, $pay ? ('method=' . $pay['method'] . ' status=' . $pay['status']) : 'MISSING');
check('COD: is_cod = 1', (int) ($b['is_cod'] ?? 0) === 1);
check('COD: payment.method = cod', ($pay['method'] ?? '') === 'cod');
check('COD: payment NOT auto-paid', in_array($pay['status'] ?? '', ['cod_pending', 'pending'], true), 'status=' . ($pay['status'] ?? ''));
$seatQ = pdo()->prepare('SELECT seat_no FROM booking_seats WHERE booking_id = ?');
$seatQ->execute([$b['id'] ?? 0]);
check('seat claimed in booking_seats', $seatQ->rowCount() === 1, implode(',', $seatQ->fetchAll(PDO::FETCH_COLUMN)));
$paxQ = pdo()->prepare('SELECT full_name FROM booking_passengers WHERE booking_id = ?');
$paxQ->execute([$b['id'] ?? 0]);
check('passenger stored', $paxQ->rowCount() === 1, (string) $paxQ->fetchColumn());

echo "\n=== TEST 3 — customer can read the ticket back with no account ===\n";
$tr = req('POST', '/api/track.php', ['jar' => $jar, 'json' => ['pnr' => $pnr, 'phone' => $phone]]);
$trj = json_decode($tr['body'], true);
check('track.php returns the booking', ($trj['ok'] ?? false) === true && ($trj['data']['pnr'] ?? '') === $pnr, substr($tr['body'], 0, 200));
check('track shows COD payment state', ($trj['data']['payment']['method'] ?? '') === 'cod', json_encode($trj['data']['payment'] ?? []));

echo "\n=== TEST 7 — admin endpoints refuse an unauthenticated caller ===\n";
foreach (['/admin/index.php', '/admin/bookings.php', '/admin/payments.php', '/admin/agent.php'] as $p) {
    $r = req('GET', $p, ['jar' => $jar]);
    check("GET $p not served to a guest", $r['code'] === 302 || $r['code'] === 401 || $r['code'] === 403, 'HTTP ' . $r['code']);
}
$pc = req('GET', '/admin/pending-count.php', ['jar' => $jar]);
check('GET /admin/pending-count.php refuses guest', $pc['code'] !== 200 || !str_contains($pc['body'], 'pending"'), 'HTTP ' . $pc['code'] . ' ' . substr($pc['body'], 0, 120));

echo "\n=== TEST 4 — ADMIN signs in and fetches the new ticket ===\n";
/* 16 Nov 2026: the bare door is the code box now (code-first, 15 Nov; the
   one "mobile or ID" box while login_code_first_on is switched off) - it
   still renders with its CSRF token; the password sign-in is ?classic=1. */
$door = req('GET', '/admin/login.php', ['jar' => $adminJar]);
check('the staff door renders (the code box, or the one-door box, + CSRF)',
    $door['code'] === 200 && (str_contains($door['body'], 'name="code"') || str_contains($door['body'], 'name="who"')) && csrfOfPage($door['body']) !== '',
    'HTTP ' . $door['code']);
[$lp, $li] = staffSignIn($adminJar, E2E_ADMIN_USER, $E2E_ADMIN_PW);
$loginCsrf = csrfOfPage($lp['body']);
check('admin login page renders', $lp['code'] === 200 && $loginCsrf !== '', 'HTTP ' . $lp['code'] . ' csrf=' . ($loginCsrf !== '' ? 'yes' : 'NO'));
$dash = req('GET', '/admin/index.php', ['jar' => $adminJar]);
$loggedIn = $li['code'] === 302 && !str_contains($li['location'], 'login.php') && !str_contains($li['location'], 'pin-setup')
    && $dash['code'] === 200
    && (str_contains($dash['body'], 'Recent Bookings') || str_contains($dash['body'], 'Latest bookings') || str_contains($dash['body'], 'Recent tickets'));
check('admin session established', $loggedIn,
    'login HTTP ' . $li['code'] . ' -> ' . $li['location'] . ', dash HTTP ' . $dash['code'] . ($dash['location'] !== '' ? ' -> ' . $dash['location'] : ''));

if ($loggedIn) {
    check('TEST 4: dashboard "Latest bookings" contains the new PNR', str_contains($dash['body'], $pnr));
    $list = req('GET', '/admin/bookings.php', ['jar' => $adminJar]);
    check('TEST 4: /admin/bookings.php contains the new PNR', str_contains($list['body'], $pnr), 'HTTP ' . $list['code']);
    $view = req('GET', '/admin/booking-view.php?pnr=' . urlencode($pnr), ['jar' => $adminJar]);
    check('TEST 4: booking-view opens the ticket', str_contains($view['body'], 'E2E Guest Tester'), 'HTTP ' . $view['code']);
    check('TEST 4: booking-view shows COD method', stripos($view['body'], '>COD<') !== false || stripos($view['body'], 'COD ·') !== false,
        'method cell rendered');
    $codVisible = preg_match('/Pay at counter|cod_pending|COD/i', $view['body']) === 1;
    check('TEST 14: admin session survives a second request', $list['code'] === 200);

    echo "\n=== TEST 10b — admin settles the COD cash ===\n";
    // COD cash-outstanding lives on the dedicated 'cod' tab (the default
    // 'pending' tab is the UPI verification queue), so target it explicitly.
    $q = req('GET', '/admin/payments.php?tab=cod', ['jar' => $adminJar]);
    check('COD booking is listed as cash outstanding', str_contains($q['body'], $pnr),
        'if FAIL: no admin screen can settle this COD payment');
    preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $q['body'], $qc);
    $settle = req('POST', '/admin/payments.php', ['jar' => $adminJar, 'form' => [
        'shg_csrf' => $qc[1] ?? '', 'action' => 'cod_settle',
        'booking_id' => (int) $b['id'], 'note' => 'E2E counter test',
    ]]);
    $payQ->execute([$b['id']]);
    $payAfter = $payQ->fetch();
    check('payment moves cod_pending → verified', ($payAfter['status'] ?? '') === 'verified',
        'status=' . ($payAfter['status'] ?? '?'));
    check('the collector is recorded', (int) ($payAfter['verified_by'] ?? 0) > 0 && !empty($payAfter['verified_at']),
        'by=' . ($payAfter['verified_by'] ?? '-') . ' at=' . ($payAfter['verified_at'] ?? '-'));
    check('settling is audit-logged', (int) pdo()->query(
        "SELECT COUNT(*) FROM audit_logs WHERE action='booking.cod.settle' AND entity_id=" . pdo()->quote($pnr))->fetchColumn() === 1);
    $q2 = req('GET', '/admin/payments.php?tab=cod', ['jar' => $adminJar]);
    check('it drops off the outstanding list', !str_contains($q2['body'], $pnr));
    $again = req('POST', '/admin/payments.php', ['jar' => $adminJar, 'form' => [
        'shg_csrf' => $qc[1] ?? '', 'action' => 'cod_settle',
        'booking_id' => (int) $b['id'], 'note' => 'double click',
    ]]);
    check('settling twice is harmless (idempotent)', $again['code'] === 200 && !str_contains($again['body'], 'flash bad'));

    echo "\n=== TEST 6 — logout destroys the session ===\n";
    req('GET', '/admin/logout.php', ['jar' => $adminJar]);
    $after = req('GET', '/admin/bookings.php', ['jar' => $adminJar]);
    check('after logout the admin list is refused', $after['code'] !== 200 || !str_contains($after['body'], $pnr), 'HTTP ' . $after['code']);
}

echo "\n=== TEST 8/9 — AGENT is separated from ADMIN ===\n";
$agentJar = sys_get_temp_dir() . '/shg_e2e_agent_' . getmypid() . '.cookies';
@unlink($agentJar);
// 16 Nov 2026: ?portal=agent is the same one door; the password sign-in is ?classic=1 (see staffSignIn)
[, $ag] = staffSignIn($agentJar, E2E_AGENT_USER, $E2E_AGENT_PW);
$agHome = req('GET', '/admin/agent.php', ['jar' => $agentJar]);
$agentIn = $agHome['code'] === 200;
check('agent can sign in', $agentIn, 'login HTTP ' . $ag['code'] . ' -> ' . $ag['location'] . ', agent.php HTTP ' . $agHome['code']
    . ($agHome['location'] !== '' ? ' -> ' . $agHome['location'] : '') . ' (set SHG_AGENT_PW if the password differs)');
if ($agentIn) {
    $agDash = req('GET', '/admin/index.php', ['jar' => $agentJar, 'follow' => false]);
    check('TEST 8: agent is redirected off the finance dashboard', $agDash['code'] === 302, 'HTTP ' . $agDash['code']);
    $agSettings = req('GET', '/admin/settings.php', ['jar' => $agentJar]);
    check('TEST 9: agent refused admin-only settings', $agSettings['code'] === 403, 'HTTP ' . $agSettings['code']);
    $agBook = req('GET', '/admin/booking-view.php?pnr=' . urlencode($pnr), ['jar' => $agentJar]);
    check('TEST 9: agent cannot open a booking they did not sell',
        str_contains($agBook['body'], 'No booking found'), 'scope check');
    $agKv = req('POST', '/api/kv.php', ['jar' => $agentJar, 'json' => ['action' => 'set', 'key' => 'settings', 'value' => '{}']]);
    check('TEST 9: agent cannot write shared KV', $agKv['code'] === 403 || $agKv['code'] === 419, 'HTTP ' . $agKv['code']);
    $agPay = req('GET', '/admin/payments.php', ['jar' => $agentJar]);
    check('TEST 9: agent refused the payments desk (so cannot settle cash)', $agPay['code'] === 403, 'HTTP ' . $agPay['code']);
}

echo "\n=== TEST 11 — existing data untouched ===\n";
$cnt = (int) pdo()->query("SELECT COUNT(*) FROM bookings WHERE pnr LIKE 'SHG-R%'")->fetchColumn();
/* 19 Sep 2026: this asserted ">= 5", a fact about one developer's seed, not
   about this run - shari_test on the VPS holds 3, so the suite failed for ever.
   What the test is FOR: the run must not lose a legacy row. */
check('legacy bookings still present', $cnt === $legacyBefore, $legacyBefore . ' before, ' . $cnt . ' after');

// Remove what this run made: the booking first (its payment names the admin
// who settled it), then the throwaway sign-in accounts and their sessions.
e2eCleanup($pnr);
$leftBy = [
    'booking'       => (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE pnr = :p', ['p' => $pnr], 0),
    'accounts'      => (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE username IN (:a, :b)', ['a' => E2E_ADMIN_USER, 'b' => E2E_AGENT_USER], 0),
    'login history' => (int) Database::scalar('SELECT COUNT(*) FROM admin_login_events WHERE username IN (:a, :b)', ['a' => E2E_ADMIN_USER, 'b' => E2E_AGENT_USER], 0),
    // 16 Nov 2026: the rows that name the run without a foreign key
    'nav_use'       => (int) Database::scalar("SELECT COUNT(*) FROM kv_store WHERE kscope = 'nav_use' AND kkey IN (:a, :b)", ['a' => 'a' . $E2E_ADMIN_ID, 'b' => 'a' . $E2E_AGENT_ID], 0),
    'profile'       => (int) Database::scalar('SELECT COUNT(*) FROM user_profiles WHERE phone = :p', ['p' => $phone], 0),
    'event journal' => $pnr === '' ? 0 : (int) Database::scalar('SELECT COUNT(*) FROM automation_log WHERE data LIKE :d OR dedupe_key LIKE :k', ['d' => '%"' . $pnr . '"%', 'k' => '%:' . $pnr . '%'], 0),
    'office bell'   => $pnr === '' ? 0 : (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE link LIKE :l', ['l' => '%pnr=' . $pnr . '%'], 0),
];
$left = array_sum($leftBy);
check('cleanup: the run\'s booking (+ its bell / journal rows and customer profile), sign-in accounts (+ menu rows) and login history are gone', $left === 0,
    $left . ' row(s) left ' . json_encode(array_filter($leftBy)));

echo "\n----------------------------------------\n";
echo "TEST TICKET ID: $pnr\n";
echo "PASSED: $pass   FAILED: $fail\n";
echo "$pass passed, $fail failed\n";   // 16 Nov 2026: the line run-all.php's tally() reads, as every other suite prints
@unlink($jar); @unlink($adminJar); @unlink($agentJar);

/* This suite had no exit() and fell off the end, so PHP returned 0 — it
   reported success no matter how many assertions failed. Every other suite
   in tests/ ends with this exact line; this one was the omission, and it is
   the suite the deploy checklist leans on hardest ("did the customer's
   ticket actually reach the admin panel"). It has been reporting green
   while failing 24 of 28 checks. */
exit($fail === 0 ? 0 : 1);
