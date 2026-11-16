<?php
/**
 * =====================================================================
 *  role-gates-test.php — "one login door" + role gates, over real HTTP.
 *
 *  Module 1 of the 3 Sep 2026 upgrade:
 *    - /admin/login.php with no ?portal shows the 3-way chooser
 *      (Customer / Agent / Admin) and still accepts username + password;
 *    - ?portal=agent (and a deep link next=agent.php) opens the agent
 *      code + OTP form, no chooser;
 *    - a counter AGENT can no longer rewrite the fleet rotation, rename a
 *      bus, reassign a driver, set a delay or swap the bus under a trip
 *      (those now need schedules.manage) — but can still mark milestones;
 *    - a MANAGER can do all of the above, and can block a customer.
 *
 *  REQUIREMENTS — same as e2e-booking-test.php:
 *    1. SHG_TEST_BASE (or SHG_BASE) = the dev server; unset, the suite
 *       starts its OWN php -S on a free port serving this checkout
 *    2. php -c .claude/php-dev.ini -d extension=php_curl.dll tests/role-gates-test.php
 *
 *  Provisions three throwaway staff accounts (rg-agent-<run> / rg-manager-<run> /
 *  rg-super-<run>) against the LOCAL database and deletes them at the end, with
 *  their sessions, login history and audit rows (16 Nov 2026), and the one
 *  Quick Ticket it sells with every row that points at it.
 * =====================================================================
 */
declare(strict_types=1);
// 18 Sep 2026: pin the app timezone here: between 00:00 and 05:30 IST a UTC CLI computed
// 'tomorrow' one day behind the server and the natural-line check failed.
date_default_timezone_set('Asia/Kolkata');
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* 16 Nov 2026: the app is loaded for the FIXTURES only - QuickUnlock::set
   gives each account a PIN (the code-first door, 15 Nov, sends a PIN-less
   account to pin-setup.php before any page, so every gate below was
   answering "302 -> pin-setup" instead of its own verdict) and Database
   cleans up. Every check still goes over real HTTP. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/quickunlock.php';

/* SHG_TEST_BASE / SHG_BASE override the dev-server origin (a git worktree
   served on another port). 16 Nov 2026: with neither set the suite starts
   its OWN php -S on a free port serving THIS checkout and stops it on exit
   (the manifest-test.php Y7 fix) - :8899 is whichever checkout owns it. */
function shg_test_base(): string
{
    $env = trim((string) (getenv('SHG_TEST_BASE') ?: getenv('SHG_BASE') ?: ''));
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

function req(string $method, string $url, array $opt = []): array {
    $ch = curl_init(BASE . $url);
    $headers = $opt['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $opt['jar'],
        CURLOPT_COOKIEFILE     => $opt['jar'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if (isset($opt['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form']));
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw  = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hsz);
    $loc  = preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '';
    return ['code' => $code, 'body' => substr($raw, $hsz), 'location' => $loc];
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
function csrfOf(string $html): string {
    // the hidden form field, else the <meta name="csrf-token"> a staff page carries (16 Nov 2026)
    if (preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $html, $m) === 1) { return $m[1]; }
    return preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $m) === 1 ? $m[1] : '';
}

const RG_PW  = 'RoleGate@12345';
const RG_PIN = '461739';   // any valid 6-digit PIN (QuickUnlock::pinProblem)
const RG_OTP = '135790';   // the WhatsApp login code section 7 seeds into otp_codes (nothing is sent)
/* 16 Nov 2026: per-run account names and passenger mobile. Several agents and
   the battery run suites against the same shari_test at once; with fixed
   names one run's cleanup deleted the other's live accounts mid-run (and
   left their sessions orphaned), and the old passenger number 9100006001 is
   wa-agent-test.php's customer too. RG_PHONE_PREFIX marks every number this
   suite ever uses (no other suite uses 9100064…). */
const RG_PHONE_PREFIX = '9100064';
const RG_STALE_PREFIXES = ['rg-agent', 'rg-manager', 'rg-super'];
$RUN = bin2hex(random_bytes(3));
define('RG_PHONE', RG_PHONE_PREFIX . (string) random_int(100, 999));   // the Quick Ticket passenger in section 7
$RG_AGENT   = 'rg-agent-' . $RUN;
$RG_MANAGER = 'rg-manager-' . $RUN;
$ACCOUNTS = [$RG_AGENT => 'agent', $RG_MANAGER => 'manager', 'rg-super-' . $RUN => 'superadmin'];

function ensureStaff(string $username, string $role): void {
    $hash = password_hash(RG_PW, PASSWORD_BCRYPT);
    $q = pdo()->prepare('SELECT id FROM admins WHERE username = ?');
    $q->execute([$username]);
    if ($id = (int) $q->fetchColumn()) {
        pdo()->prepare('UPDATE admins SET password_hash=?, role=?, is_active=1, must_change_pw=0, failed_logins=0, locked_until=NULL WHERE id=?')
            ->execute([$hash, $role, $id]);
    } else {
        pdo()->prepare('INSERT INTO admins (username, password_hash, full_name, phone, role, is_active, must_change_pw) VALUES (?,?,?,?,?,1,0)')
            ->execute([$username, $hash, 'RoleGate ' . ucfirst($role), null, $role]);
        $id = (int) pdo()->lastInsertId();
    }
    /* 16 Nov 2026: the code-first door (15 Nov) sends an account with no PIN /
       pattern to pin-setup.php before ANY page (Auth::pinSetupNeeded), which
       hid every role gate below behind the same 302 - give it one. */
    QuickUnlock::set($id, 'pin', RG_PIN);
}
function jar(string $name): string {
    $p = sys_get_temp_dir() . '/shg_rg_' . $name . '_' . getmypid() . '.cookies';
    @unlink($p);
    return $p;
}
/** A fresh browser on the customer app: [its cookie jar, the CSRF token the page hands it]. */
function customerJar(string $name): array {
    $j = jar($name);
    $h = req('GET', '/', ['jar' => $j]);
    return [$j, preg_match('/"csrf":"([a-f0-9]+)"/', $h['body'], $m) === 1 ? $m[1] : ''];
}
/** POST to a customer API with the page's CSRF header; [HTTP code, decoded JSON (or []), raw body]. */
function custPost(string $jar, string $csrf, string $url, array $form): array {
    $r = req('POST', $url, ['jar' => $jar, 'form' => $form, 'headers' => ['X-CSRF-Token: ' . $csrf]]);
    $j = json_decode($r['body'], true);
    return [$r['code'], is_array($j) ? $j : [], $r['body']];
}
/* 16 Nov 2026: the bare /admin/login.php is the code-first box (15 Nov); the
   username + password sign-in is the classic door, ?classic=1, whose post
   carries classic=1. A sign-in counts only when it lands past the door - a
   302 back to login.php or on to pin-setup.php is not "signed in". */
function login(string $user, string $jar): bool {
    $lp = req('GET', '/admin/login.php?classic=1&other=1', ['jar' => $jar]);
    $li = req('POST', '/admin/login.php', ['jar' => $jar, 'form' => [
        'shg_csrf' => csrfOf($lp['body']), 'classic' => '1', 'username' => $user, 'password' => RG_PW,
    ]]);
    return $li['code'] === 302 && !str_contains($li['location'], 'login.php') && !str_contains($li['location'], 'pin-setup');
}

/* Everything a run leaves behind, removed at the end (also after a crash):
   the three accounts with their sessions (admin_sessions has no FK to
   admins), login history, audit rows and "recent pages" menu row; the Quick
   Ticket passenger's booking with the rows that point at it without a
   foreign key (the event journal names it by PNR), its ticket files, and the
   passenger's users row, customer-master profile, WhatsApp code and
   per-number rate-limit rows. */
function rgRemoveBooking(int $bid, string $pnr): void {
    try { Seats::releaseBooking($bid); } catch (Throwable $e) {}   // the berth is sellable again, counts recomputed
    foreach (['agent_ledger', 'message_logs', 'delivery_log', 'ticket_links', 'wa_outbox', 'wa_ticket_queue', 'msg_ledger', 'payment_events'] as $t) {
        try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
    }
    try { Database::query("DELETE FROM audit_logs WHERE entity_type = 'booking' AND entity_id IN (:p, :i)", ['p' => $pnr, 'i' => (string) $bid]); } catch (Throwable $e) {}
    rgForgetPnr($pnr);
    try { Database::delete('bookings', 'id = :i', ['i' => $bid]); } catch (Throwable $e) {}   // seats, legs, payments, tickets cascade
    foreach (glob(dirname(__DIR__) . '/tickets/ticket_' . $pnr . '.*') ?: [] as $f) { @unlink($f); }
}
/* Rows that name a PNR without a foreign key (16 Nov 2026): the EventBus
   journal (booking.created / approved / cancelled carry it in `data`, the
   office-bell claims in `dedupe_key`, e.g. alert:online:<PNR>) and an
   office-bell entry whose booking_id is NULL but whose link opens the PNR. */
function rgForgetPnr(string $pnr): void {
    if ($pnr === '') { return; }
    try { Database::query('DELETE FROM automation_log WHERE data LIKE :d OR dedupe_key LIKE :k1 OR dedupe_key LIKE :k2',
        ['d' => '%"' . $pnr . '"%', 'k1' => '%:' . $pnr, 'k2' => '%:' . $pnr . ':%']); } catch (Throwable $e) {}
    try { Database::query('DELETE FROM notifications WHERE booking_id IS NULL AND (link LIKE :l1 OR link LIKE :l2)',
        ['l1' => '%pnr=' . $pnr, 'l2' => '%pnr=' . $pnr . '&%']); } catch (Throwable $e) {}
}
function rgRemoveAccount(int $aid): void {
    try { Database::delete('admin_sessions', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
    try { Database::query("DELETE FROM audit_logs WHERE entity_type = 'admin' AND entity_id = :a", ['a' => (string) $aid]); } catch (Throwable $e) {}
    // the "recent pages" menu row admin/_guard.php keeps per account (OpsHub::trackPage; no FK to admins)
    try { Database::query("DELETE FROM kv_store WHERE kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $aid]); } catch (Throwable $e) {}
    try { Database::delete('admins', 'id = :a', ['a' => $aid]); } catch (Throwable $e) {}   // admin_devices cascades
}
/** The passenger number's own rows outside bookings (users, the customer master, the WhatsApp code, its rate-limit buckets). */
function rgRemovePassenger(string $phone): void {
    try { Database::delete('message_logs', 'to_number LIKE :p', ['p' => '%' . $phone]); } catch (Throwable $e) {}
    try { Database::delete('users', 'phone = :p', ['p' => $phone]); } catch (Throwable $e) {}
    try { Database::delete('user_profiles', 'phone = :p', ['p' => $phone]); } catch (Throwable $e) {}
    try { Database::delete('otp_codes', 'identifier = :p', ['p' => $phone]); } catch (Throwable $e) {}
    try { Database::delete('rate_limits', 'identifier = :p', ['p' => $phone]); } catch (Throwable $e) {}
}
function rgCleanup(array $accounts): void {
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => RG_PHONE]) as $b) {
        rgRemoveBooking((int) $b['id'], (string) $b['pnr']);
    }
    rgRemovePassenger(RG_PHONE);
    foreach (array_keys($accounts) as $u) {
        $aid = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
        if ($aid > 0) {
            rgRemoveAccount($aid);
        }
        try { Database::delete('admin_login_events', 'username = :u', ['u' => $u]); } catch (Throwable $e) {}
    }
}
/* What an EARLIER run left (it died, or ran an older copy with fixed names):
   only rows over an hour old, so a run going on right now is never touched.
   Accounts are found by name, and accounts already deleted (whose sessions
   were orphaned) through their login history. */
function rgSweepStale(): void {
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE :p AND created_at < NOW() - INTERVAL 1 HOUR", ['p' => RG_PHONE_PREFIX . '%']) as $b) {
        rgRemoveBooking((int) $b['id'], (string) $b['pnr']);
    }
    foreach (['users', 'user_profiles'] as $t) {
        try { Database::query("DELETE FROM $t WHERE phone LIKE :p AND created_at < NOW() - INTERVAL 1 HOUR", ['p' => RG_PHONE_PREFIX . '%']); } catch (Throwable $e) {}
    }
    try { Database::query('DELETE FROM otp_codes WHERE identifier LIKE :p AND created_at < NOW() - INTERVAL 1 HOUR', ['p' => RG_PHONE_PREFIX . '%']); } catch (Throwable $e) {}
    foreach (RG_STALE_PREFIXES as $pre) {
        $like = $pre . '%';
        foreach (Database::fetchAll('SELECT id FROM admins WHERE username LIKE :u AND created_at < NOW() - INTERVAL 1 HOUR', ['u' => $like]) as $a) {
            rgRemoveAccount((int) $a['id']);
        }
        $gone = Database::fetchAll('SELECT DISTINCT e.admin_id AS id FROM admin_login_events e LEFT JOIN admins a ON a.id = e.admin_id
                                     WHERE e.username LIKE :u AND e.admin_id IS NOT NULL AND a.id IS NULL', ['u' => $like]);
        $gone = array_merge($gone, Database::fetchAll("SELECT DISTINCT l.actor_id AS id FROM audit_logs l LEFT JOIN admins a ON a.id = l.actor_id
                                     WHERE l.action = 'admin.login' AND l.actor_name LIKE :u AND l.actor_id IS NOT NULL AND a.id IS NULL", ['u' => $like]));
        foreach ($gone as $o) {
            rgRemoveAccount((int) $o['id']);   // the account is gone; its sessions / audit rows are not
        }
        try { Database::query('DELETE e FROM admin_login_events e LEFT JOIN admins a ON a.id = e.admin_id
                                WHERE e.username LIKE :u AND a.id IS NULL', ['u' => $like]); } catch (Throwable $e) {}
    }
}

try { pdo()->exec("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
// The public Quick Ticket buckets are per IP; repeated local runs from
// 127.0.0.1 would otherwise hit the sale limit and fail with 429.
try { pdo()->exec("DELETE FROM rate_limits WHERE bucket LIKE 'quick_ticket%' OR bucket LIKE 'login_quick%' OR bucket LIKE 'cancel%'"); } catch (Throwable $e) {}
rgSweepStale();
register_shutdown_function(static fn() => rgCleanup($ACCOUNTS));   // also after a crash half-way
foreach ($ACCOUNTS as $u => $r) { ensureStaff($u, $r); }

/* ---------------------------------------------------------------- */
echo "\n=== 1. One login door — role chooser ===\n";
$guest = jar('guest');
$door = req('GET', '/admin/login.php', ['jar' => $guest]);
check('bare /admin/login.php renders', $door['code'] === 200, 'HTTP ' . $door['code']);
/* 30 Oct 2026 (owner: one door for everyone): with settings login_one_door_on (code default ON) the
   page is ONE box "Mobile number or ID" — no role tiles, and every ?portal= / ?auth= link shows the
   same door. Switched off, the role chooser below is checked exactly as before. */
$oneDoor = str_contains($door['body'], 'data-door="one"');
if ($oneDoor) {
    check('one door: no role chooser', !str_contains($door['body'], 'class="choose"'));
    /* 15 Nov 2026 (code-first login, settings login_code_first_on): the bare door is ONE
       "Agent / Staff / Admin code" box and nothing else (the "bare code screen", 76b2f5b3);
       the "mobile number or ID" door with its passenger link moved to ?classic=1, the
       office's recovery route. Each box is checked where it now lives. */
    if (str_contains($door['body'], 'name="code"')) {
        check('one door (code-first): one "Agent / Staff / Admin code" box + CSRF', csrfOf($door['body']) !== '' && !str_contains($door['body'], 'name="who"'));
        $classicDoor = req('GET', '/admin/login.php?classic=1&other=1', ['jar' => $guest]);
        check('the classic door (?classic=1): one "mobile number or ID" box + CSRF',
            $classicDoor['code'] === 200 && str_contains($classicDoor['body'], 'name="who"') && csrfOf($classicDoor['body']) !== '', 'HTTP ' . $classicDoor['code']);
        check('the classic door: a passenger link back to the website', str_contains($classicDoor['body'], 'Passenger?'));
    } else {
        check('one door: one "mobile number or ID" box + CSRF', str_contains($door['body'], 'name="who"') && csrfOf($door['body']) !== '');
        check('one door: a passenger link back to the website', str_contains($door['body'], 'Passenger?'));
    }
    check('the dead /?portal=switch link is gone', !str_contains($door['body'], '?portal=switch'));
    foreach (['?portal=agent', '?portal=agent&auth=otp', '?portal=admin', '?auth=pw'] as $q) {
        check("one door: $q shows the same door", str_contains(req('GET', '/admin/login.php' . $q, ['jar' => $guest])['body'], 'data-door="one"'));
    }
    $deep = req('GET', '/admin/login.php?next=agent.php', ['jar' => $guest]);
    check('one door: keeps the deep link', str_contains($deep['body'], 'name="next" value="agent.php"'));
} else {
check('chooser shown when no portal is chosen', str_contains($door['body'], 'class="choose"'));
check('chooser links to the agent portal', str_contains($door['body'], '?portal=agent'));
check('chooser links to the admin portal', str_contains($door['body'], '?portal=admin'));
check('chooser links the customer back to the website', preg_match('~class="ch ch-customer" href="[^"]*/#/my"~', $door['body']) === 1);
check('username + password form still present under the chooser', str_contains($door['body'], 'name="username"'));
check('the dead /?portal=switch link is gone', !str_contains($door['body'], '?portal=switch'));

/* The agent door changed on 2026-09-04: the DEFAULT is now email +
   username + password (Auth::agentPasswordLogin), and the older
   agent-code + mobile-OTP door moved behind ?auth=otp. These two checks
   still asserted name="agent_code" on the default portal, so they had
   been failing since that change — unnoticed, because role-gates-test is
   not in the documented eight-suite battery and e2e-booking-test (which
   is) exited 0 no matter what. Updated to the current intent, with the
   OTP door still covered rather than dropped. */
$ag = req('GET', '/admin/login.php?portal=agent', ['jar' => $guest]);
check('?portal=agent shows the password form (email+username+password)',
    str_contains($ag['body'], 'name="email"')
    && str_contains($ag['body'], 'name="username"')
    && str_contains($ag['body'], 'name="password"'));
check('?portal=agent hides the chooser (existing URL unchanged)', !str_contains($ag['body'], 'class="choose"'));

$agOtp = req('GET', '/admin/login.php?portal=agent&auth=otp', ['jar' => $guest]);
check('?auth=otp still offers the legacy agent-code + mobile OTP door',
    str_contains($agOtp['body'], 'name="agent_code"')
    && str_contains($agOtp['body'], 'name="mobile"'));

$deep = req('GET', '/admin/login.php?next=agent.php', ['jar' => $guest]);
check('next=agent.php infers the agent portal (password form)',
    str_contains($deep['body'], 'name="username"')
    && str_contains($deep['body'], 'name="password"'));
check('inferred agent portal keeps the deep link', str_contains($deep['body'], 'name="next" value="agent.php"'));

$adm = req('GET', '/admin/login.php?portal=admin', ['jar' => $guest]);
check('?portal=admin shows username + password, no chooser',
    str_contains($adm['body'], 'name="username"') && !str_contains($adm['body'], 'class="choose"'));
}

$bounce = req('GET', '/admin/agent.php', ['jar' => $guest]);
check('signed-out /admin/agent.php redirects to the login door with next=', $bounce['code'] === 302 && str_contains($bounce['location'], 'login.php?next=agent.php'),
    'HTTP ' . $bounce['code'] . ' → ' . $bounce['location']);

/* ---------------------------------------------------------------- */
echo "\n=== 2. Role-based redirect ===\n";
$agentJar = jar('agent');
check('agent signs in with username + password (fallback path)', login($RG_AGENT, $agentJar));
$idx = req('GET', '/admin/index.php', ['jar' => $agentJar]);
check('agent is bounced off the finance dashboard to ' . ($oneDoor ? 'the one home' : 'agent.php'),
    $idx['code'] === 302 && str_contains($idx['location'], $oneDoor ? 'home.php' : 'agent.php'),
    'HTTP ' . $idx['code'] . ' → ' . $idx['location']);
$agHome = req('GET', '/admin/agent.php', ['jar' => $agentJar]);
check('agent dashboard opens', $agHome['code'] === 200, 'HTTP ' . $agHome['code']);

$mgrJar = jar('manager');
check('manager signs in', login($RG_MANAGER, $mgrJar));
$mIdx = req('GET', '/admin/index.php', ['jar' => $mgrJar]);
check('manager lands on the dashboard', $mIdx['code'] === 200, 'HTTP ' . $mIdx['code']);

/* ---------------------------------------------------------------- */
echo "\n=== 3. Agent cannot manage the fleet (schedules.manage) ===\n";
$tb = req('GET', '/admin/trips.php', ['jar' => $agentJar]);
check('agent can open the Trips Board (schedules.view)', $tb['code'] === 200, 'HTTP ' . $tb['code']);
check('rotation / bus-details panel is hidden from the agent', !str_contains($tb['body'], 'name="action" value="rotation"'));
check('driver-assign form is hidden from the agent', !str_contains($tb['body'], 'name="action" value="assign_driver"'));
check('delay form is hidden from the agent', !str_contains($tb['body'], 'name="action" value="set_delay"'));
$csrfA = csrfOf($tb['body']);
check('agent page carries a CSRF token to test the POST gates', $csrfA !== '');

foreach (['rotation', 'bus', 'assign_driver', 'set_delay'] as $act) {
    $r = req('POST', '/admin/trips.php', ['jar' => $agentJar, 'form' => [
        'shg_csrf' => $csrfA, 'action' => $act, 'schedule_id' => 0, 'bus_id' => 0, 'delay_minutes' => 30,
    ]]);
    check("agent POST trips.php action=$act is refused (403)", $r['code'] === 403, 'HTTP ' . $r['code']);
}
$ms = req('POST', '/admin/trips.php', ['jar' => $agentJar, 'form' => [
    'shg_csrf' => $csrfA, 'action' => 'milestone', 'schedule_id' => 0, 'event' => 'departed',
]]);
check('agent may still press a journey milestone (not 403; bad id → flash)', $ms['code'] === 200, 'HTTP ' . $ms['code']);

$td = req('GET', '/admin/trip-dashboard.php', ['jar' => $agentJar]);
check('agent can open the Date View', $td['code'] === 200, 'HTTP ' . $td['code']);
check('bus-swap dropdown is hidden from the agent', !str_contains($td['body'], 'name="action" value="assign_bus"'));
$sw = req('POST', '/admin/trip-dashboard.php', ['jar' => $agentJar, 'form' => [
    // The agent's Date View now renders no form at all, so borrow the CSRF
    // token from the same session's Trips Board page (token is per session).
    'shg_csrf' => $csrfA, 'action' => 'assign_bus', 'schedule_id' => 0, 'bus_id' => 0,
]]);
check('agent POST assign_bus is refused (403)', $sw['code'] === 403, 'HTTP ' . $sw['code']);

/* ---------------------------------------------------------------- */
echo "\n=== 4. Manager keeps fleet management + can block a customer ===\n";
$mtb = req('GET', '/admin/trips.php', ['jar' => $mgrJar]);
check('manager sees the rotation panel', str_contains($mtb['body'], 'name="action" value="rotation"'));
$csrfM = csrfOf($mtb['body']);
$md = req('POST', '/admin/trips.php', ['jar' => $mgrJar, 'form' => [
    'shg_csrf' => $csrfM, 'action' => 'set_delay', 'schedule_id' => 0, 'delay_minutes' => 0,
]]);
check('manager POST set_delay passes the gate (200, bad id → flash)', $md['code'] === 200, 'HTTP ' . $md['code']);
$mtd = req('GET', '/admin/trip-dashboard.php', ['jar' => $mgrJar]);
check('manager sees the bus-swap control', str_contains($mtd['body'], 'name="action" value="assign_bus"') || str_contains($mtd['body'], 'name="bus_id"'));

$cu = req('GET', '/admin/customers.php', ['jar' => $mgrJar]);
check('manager opens Customers', $cu['code'] === 200, 'HTTP ' . $cu['code']);
$blk = req('POST', '/admin/customers.php', ['jar' => $mgrJar, 'form' => [
    'shg_csrf' => csrfOf($cu['body']), 'action' => 'block', 'uid' => 0, 'reason' => 'role-gate test',
]]);
check('manager block attempt is no longer refused as super-admin-only',
    $blk['code'] === 200 && !str_contains($blk['body'], 'super-admin can block'), 'HTTP ' . $blk['code']);
check('… it reaches the customer lookup (uid 0 → "Customer not found")', str_contains($blk['body'], 'Customer not found'));

/* ---------------------------------------------------------------- */
echo "\n=== 5. Customer app and the staff door ===\n";
$home = req('GET', '/', ['jar' => $guest]);
check('customer home renders', $home['code'] === 200, 'HTTP ' . $home['code']);
/* 13 Nov 2026 (two apps, 45695000: "customer page carries no staff sign-in
   link unless a selling staff session is on it"): the footer + menu links
   are wrapped in <!--staff--> and index.php strips them for everyone else.
   This asserted >= 2 links on the GUEST page, the rule the split reversed;
   both sides of the new rule are checked instead. */
check('a guest\'s customer page carries no staff sign-in link (two apps)', substr_count($home['body'], 'href="/admin/login.php"') === 0,
    'links=' . substr_count($home['body'], 'href="/admin/login.php"'));
$staffHome = req('GET', '/', ['jar' => $agentJar]);
check('a signed-in selling agent\'s page keeps the footer + menu links to /admin/login.php', substr_count($staffHome['body'], 'href="/admin/login.php"') >= 2,
    'HTTP ' . $staffHome['code'] . ' links=' . substr_count($staffHome['body'], 'href="/admin/login.php"'));
check('no splash agent/admin pills came back', !str_contains($home['body'], 'data-portal="agent"'));

/* ---------------------------------------------------------------- */
echo "\n=== 7. Quick Ticket desk (6 Sep 2026) ===\n";
$qtGuest = req('GET', '/admin/quick-ticket.php', ['jar' => $guest]);
check('signed-out /admin/quick-ticket.php bounces to the login door',
    $qtGuest['code'] === 302 && str_contains($qtGuest['location'], 'login.php'), 'HTTP ' . $qtGuest['code']);
/* 16 Oct 2026 (owner: "3 ta booking system lai eutai ma"): SmartBook is the one
   booking screen for every desk - /admin/quick-ticket.php sends a signed-in
   desk there, and ?legacy=1 keeps the QuickBot page as the unlinked
   emergency fallback. Both are checked: the redirect, SmartBook opening for
   the agent, and the legacy desk (whose CSRF token the API calls below use). */
$qtGo = req('GET', '/admin/quick-ticket.php', ['jar' => $agentJar]);
check('an agent opening /admin/quick-ticket.php is sent to SmartBook (the one booking screen)',
    $qtGo['code'] === 302 && str_contains($qtGo['location'], '/admin/smartbook.php'), 'HTTP ' . $qtGo['code'] . ' → ' . $qtGo['location']);
$sbAgent = req('GET', '/admin/smartbook.php', ['jar' => $agentJar]);
check('…and SmartBook opens for the agent', $sbAgent['code'] === 200, 'HTTP ' . $sbAgent['code'] . ($sbAgent['location'] !== '' ? ' → ' . $sbAgent['location'] : ''));
$qtAgent = req('GET', '/admin/quick-ticket.php?legacy=1', ['jar' => $agentJar]);
check('an agent opens the QuickBot Ticket desk (?legacy=1 fallback)', $qtAgent['code'] === 200 && str_contains($qtAgent['body'], 'QuickBot') && str_contains($qtAgent['body'], 'id="qtLine"'), 'HTTP ' . $qtAgent['code']);
$qtApiGuest = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'plan']]);
check('signed-out /api/quick-ticket.php is refused', in_array($qtApiGuest['code'], [401, 403, 419], true), 'HTTP ' . $qtApiGuest['code']);
$qtCsrf = csrfOf($qtAgent['body']);
$qtPlan = req('POST', '/api/quick-ticket.php', ['jar' => $agentJar, 'form' => ['action' => 'plan'], 'headers' => ['X-CSRF-Token: ' . $qtCsrf]]);
$qtJson = json_decode($qtPlan['body'], true);
check('an agent gets a plan (next bus · pickup · seat · fare)',
    $qtPlan['code'] === 200 && is_array($qtJson) && ($qtJson['ok'] ?? false) === true && !empty($qtJson['data']['seats']),
    'HTTP ' . $qtPlan['code'] . ' ' . substr($qtPlan['body'], 0, 120));

/* Quick Ticket for everyone + the AI Ticket Bot (6 Sep 2026): the customer
   door is public (CSRF from the app page), the bot is staff-only, and a
   staff session may NOT use the customer door (its sales must be attributed). */
$appHome = req('GET', '/', ['jar' => $guest]);
$appCsrf = preg_match('/"csrf":"([a-f0-9]+)"/', $appHome['body'], $mm) ? $mm[1] : '';
check('the app page hands a guest a CSRF token', $appCsrf !== '', 'HTTP ' . $appHome['code']);
$cPlan = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'customer_plan'], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$cJson = json_decode($cPlan['body'], true);
check('a signed-out passenger gets a customer plan (public caps)',
    $cPlan['code'] === 200 && is_array($cJson) && ($cJson['ok'] ?? false) === true && !empty($cJson['data']['seats']),
    'HTTP ' . $cPlan['code'] . ' ' . substr($cPlan['body'], 0, 120));
$cBot = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'bot', 'text' => 'Ram 9876543210'], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
check('the AI Ticket Bot is staff-only (guest refused)', in_array($cBot['code'], [401, 403], true), 'HTTP ' . $cBot['code']);
$aBot = req('POST', '/api/quick-ticket.php', ['jar' => $agentJar, 'form' => ['action' => 'bot', 'text' => 'Ram Bahadur 9876543210 2 seats'], 'headers' => ['X-CSRF-Token: ' . $qtCsrf]]);
$aJson = json_decode($aBot['body'], true);
check('an agent gets a bot proposal (prefill from the typed line + live plan)',
    $aBot['code'] === 200 && is_array($aJson) && ($aJson['ok'] ?? false) === true
    && (int) ($aJson['data']['prefill']['seats'] ?? 0) === 2 && ($aJson['data']['prefill']['name'] ?? '') === 'Ram Bahadur',
    'HTTP ' . $aBot['code'] . ' ' . substr($aBot['body'], 0, 160));
$aCust = req('POST', '/api/quick-ticket.php', ['jar' => $agentJar, 'form' => ['action' => 'customer_plan'], 'headers' => ['X-CSRF-Token: ' . $qtCsrf]]);
check('staff cannot use the customer door (sales must be attributed)', $aCust['code'] === 403, 'HTTP ' . $aCust['code']);

/* A real passenger sale over HTTP. 8 Oct 2026 (D4-03, api/quick-ticket.php):
   the sale NO LONGER signs the browser in as the number typed on the card
   (a son booking for his mother, or one mistyped digit, pulled that
   number's whole history into this phone). The new PNR is bound to THIS
   session instead (Auth::rememberOwnPnr), so the keyless ticket image /
   PDF and the free undo work for this ticket and nothing else; "save to my
   account" is the WhatsApp code. The checks below follow that rule. */
$rgPhone = RG_PHONE;
try { pdo()->prepare("DELETE FROM users WHERE phone = ?")->execute([$rgPhone]); } catch (Throwable $e) {}
/* One click (6 Sep 2026): the sale is PINNED to the card — a card that showed
   another fare / day / pickup is refused with plan_changed + the fresh plan,
   never quietly sold. Checked before this passenger holds any ticket. */
$cj = $cJson['data'] ?? [];
$stale = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'customer_sell', 'name' => 'Role Gate Pax', 'phone' => $rgPhone, 'date' => $cj['date'] ?? '', 'direction' => $cj['direction'] ?? '', 'seats' => 1,
    'expect' => ['date' => $cj['date'] ?? '', 'direction' => $cj['direction'] ?? '', 'boardingCode' => $cj['boardingCode'] ?? '', 'seats' => 1, 'total' => (float) ($cj['fare']['total'] ?? 0) + 999]], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$sJ = json_decode($stale['body'], true);
check('a stale card (other fare shown) is refused with plan_changed + the fresh plan', $stale['code'] === 409 && ($sJ['fields']['code'] ?? '') === 'plan_changed' && !empty($sJ['fields']['plan']['seats']), 'HTTP ' . $stale['code'] . ' ' . substr($stale['body'], 0, 120));
/* QuickBot (7 Sep 2026): the natural line is read by the same plan call. */
$lPlan = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'customer_plan', 'seats' => 0, 'text' => 'Ram Gate ' . $rgPhone . ', 2 seats, tomorrow shg-27'], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$lJ = json_decode($lPlan['body'], true);
check('a passenger\'s natural line is read: name, mobile, 2 seats, tomorrow, agent code',
    $lPlan['code'] === 200 && ($lJ['data']['parsed']['name'] ?? '') === 'Ram Gate' && ($lJ['data']['parsed']['phone'] ?? '') === $rgPhone
    && (int) ($lJ['data']['seatCount'] ?? 0) === 2 && ($lJ['data']['prefill']['agentCode'] ?? '') === 'SHG-0027' && ($lJ['data']['date'] ?? '') === date('Y-m-d', strtotime('+1 day')),
    'HTTP ' . $lPlan['code'] . ' ' . json_encode($lJ['data']['parsed'] ?? null));
$cSell = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'customer_sell', 'name' => 'Role Gate Pax', 'phone' => $rgPhone, 'agentCode' => 'SHG-9999'], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$sJson = json_decode($cSell['body'], true);
$rgPnr = (string) ($sJson['data']['pnr'] ?? '');
check('a signed-out passenger sells themselves a Quick Ticket', $cSell['code'] === 200 && ($sJson['ok'] ?? false) === true && $rgPnr !== '', 'HTTP ' . $cSell['code'] . ' ' . substr($cSell['body'], 0, 140));
check('  an unknown agent code never blocks it (direct sale, notice says so)', ($sJson['data']['agent']['applied'] ?? true) === false, json_encode($sJson['data']['agent'] ?? null));
check('  the response carries the app payload and signs nobody in (no `user` key — D4-03)',
    !empty($sJson['data']['customer']['pnr']) && is_array($sJson['data'] ?? null) && !array_key_exists('user', $sJson['data']),
    json_encode(['customerPnr' => $sJson['data']['customer']['pnr'] ?? null, 'user' => $sJson['data']['user'] ?? null]));
$myB = req('GET', '/api/my-bookings.php?tab=all', ['jar' => $guest]);
check('  My Bookings stays closed on that session (the typed number is not proof — D4-03)', $myB['code'] === 401 && !str_contains($myB['body'], $rgPnr), 'HTTP ' . $myB['code']);
$img = req('GET', '/download-ticket.php?pnr=' . rawurlencode($rgPnr) . '&img=1&view=1', ['jar' => $guest]);
check('  the keyless ticket image is served to its owner (no 302)', $img['code'] === 200 && str_starts_with($img['body'], "\x89PNG"), 'HTTP ' . $img['code'] . ' ' . $img['location']);
$pdf = req('GET', '/download-ticket.php?pnr=' . rawurlencode($rgPnr), ['jar' => $guest]);
check('  …and so is the PDF', $pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF'), 'HTTP ' . $pdf['code']);
/* One-click defaults: a passenger's history (usual pickup, party, NAME) is
   read only for a session that proved its number with the WhatsApp code
   (8 Oct 2026, CustomerProfile::verifiedUserId in api/quick-ticket.php).
   The selling session is signed in as nobody (D4-03) and a fresh visitor
   only TYPES the number, so neither even reaches that gate: these two show
   that the sale and a typed number reveal nothing. The gate itself is
   exercised just below by a session that IS signed in as the number. */
$pPlan = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'customer_plan', 'seats' => 0], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$pJson = json_decode($pPlan['body'], true);
check('  the selling session (signed in as nobody) gets no history in its next plan',
    $pPlan['code'] === 200 && ($pJson['ok'] ?? false) === true && empty($pJson['data']['personal']),
    'HTTP ' . $pPlan['code'] . ' ' . json_encode($pJson['data']['personal'] ?? null));
$other = sys_get_temp_dir() . '/rg-other.jar'; @unlink($other);
$oHome = req('GET', '/', ['jar' => $other]);
$oCsrf = preg_match('/"csrf":"([a-f0-9]+)"/', $oHome['body'], $mm2) ? $mm2[1] : '';
$oPlan = req('POST', '/api/quick-ticket.php', ['jar' => $other, 'form' => ['action' => 'customer_plan', 'phone' => $rgPhone], 'headers' => ['X-CSRF-Token: ' . $oCsrf]]);
$oJson = json_decode($oPlan['body'], true);
check('  a different visitor typing that number learns NOTHING of its history', $oPlan['code'] === 200 && empty($oJson['data']['personal']), json_encode($oJson['data']['personal'] ?? null));
@unlink($other);

/* 16 Nov 2026 (review): the gate on a session that is SIGNED IN as the
   number. Name + mobile without the code ("continue without code",
   api/otp.php action=quick) signs in, but proves nothing - its plan must not
   carry the number's history, not even the name on the earlier ticket
   ('Role Gate Pax'; this browser typed another name). Without the
   verifiedUserId() gate in api/quick-ticket.php this plan WOULD read that
   history. The ticket is still confirmed here, so there is history to leak. */
[$quickJar, $quickCsrf] = customerJar('quick');
[$qc, $qj, $qb] = custPost($quickJar, $quickCsrf, '/api/otp.php', ['action' => 'quick', 'name' => 'Typed Stranger', 'phone' => $rgPhone]);
check('  a name + mobile sign-in for that number (no code) is signed in but not verified',
    $qc === 200 && ($qj['data']['signedIn'] ?? false) === true && ($qj['data']['verified'] ?? true) === false, 'HTTP ' . $qc . ' ' . substr($qb, 0, 140));
$qMy = req('GET', '/api/my-bookings.php?tab=all', ['jar' => $quickJar]);
$qMyJ = json_decode($qMy['body'], true);
check('  …its My Bookings is open but lists none of that number\'s tickets (booked in another browser)',
    $qMy['code'] === 200 && ($qMyJ['data']['verified'] ?? true) === false && !str_contains($qMy['body'], $rgPnr), 'HTTP ' . $qMy['code'] . ' ' . substr($qMy['body'], 0, 120));
[$qc, $qj, $qb] = custPost($quickJar, $quickCsrf, '/api/quick-ticket.php', ['action' => 'customer_plan', 'seats' => 0]);
check('  …its plan reads NO history of the number (the WhatsApp-code gate), not even the name on file',
    $qc === 200 && ($qj['ok'] ?? false) === true && empty($qj['data']['personal'])
    && ($qj['data']['fields']['name']['source'] ?? '') !== 'history' && !str_contains($qb, 'Role Gate Pax'),
    'HTTP ' . $qc . ' personal=' . json_encode($qj['data']['personal'] ?? null) . ' name=' . json_encode($qj['data']['fields']['name'] ?? null));

/* …and the positive side: a browser that proves the number with the
   WhatsApp code gets that number's own history (the old HTTP check, which
   the selling session can no longer give since D4-03). The code is seeded
   straight into otp_codes, as agent-pro-http-test.php does - nothing is sent. */
[$verJar, $verCsrf] = customerJar('verified');
Database::insert('otp_codes', ['identifier' => $rgPhone, 'channel' => 'whatsapp', 'purpose' => 'login',
    'code_hash' => password_hash(RG_OTP, PASSWORD_BCRYPT), 'expires_at' => date('Y-m-d H:i:s', time() + 600), 'ip_address' => '127.0.0.1']);
[$vc, $vj, $vb] = custPost($verJar, $verCsrf, '/api/otp.php', ['action' => 'verify', 'purpose' => 'login', 'phone' => $rgPhone, 'code' => RG_OTP, 'name' => 'Role Gate Pax']);
check('  the WhatsApp code proves the number (verify: signed in AND verified)',
    $vc === 200 && ($vj['data']['signedIn'] ?? false) === true && ($vj['data']['verified'] ?? false) === true, 'HTTP ' . $vc . ' ' . substr($vb, 0, 140));
[$vc, $vj, $vb] = custPost($verJar, $verCsrf, '/api/quick-ticket.php', ['action' => 'customer_plan', 'seats' => 0]);
if (($vj['data']['learning'] ?? null) === false) {
    // ai_bot_on OFF: TicketBot keeps no memory for anybody, so there is no history to show (or hide)
    echo "  SKIP  the verified session's plan is personalised - ai_bot_on is OFF on this database (no passenger memory)\n";
} else {
    check('  …and only that session\'s plan is personalised from the number\'s own history (1 trip, Role Gate Pax)',
        $vc === 200 && (int) ($vj['data']['personal']['trips'] ?? 0) === 1 && ($vj['data']['personal']['name'] ?? '') === 'Role Gate Pax',
        'HTTP ' . $vc . ' personal=' . json_encode($vj['data']['personal'] ?? null));
}
/* "Not you?" on the VERIFIED browser drops the code-proven mark
   (unset shg_cust_otp in api/otp.php): when someone then types the same
   number on that browser without the code, the history stays shut. Checked
   while the ticket is still confirmed, so a kept mark would show it. */
[$lc] = custPost($verJar, $verCsrf, '/api/otp.php', ['action' => 'logout']);
[$qc2] = custPost($verJar, $verCsrf, '/api/otp.php', ['action' => 'quick', 'name' => 'Typed Stranger', 'phone' => $rgPhone]);
[$vc, $vj, $vb] = custPost($verJar, $verCsrf, '/api/quick-ticket.php', ['action' => 'customer_plan', 'seats' => 0]);
check('"Not you?" on the verified browser drops the WhatsApp-code mark (same number typed again: no history)',
    $lc === 200 && $qc2 === 200 && $vc === 200 && empty($vj['data']['personal']) && !str_contains($vb, 'Role Gate Pax'),
    'logout ' . $lc . ', quick ' . $qc2 . ', plan ' . $vc . ' personal=' . json_encode($vj['data']['personal'] ?? null));

/* One click (6 Sep 2026): a mistaken tap is undone free; "Not you?" ends the
   server session. (The pinned-sale check runs above, before the passenger
   holds a ticket, or the "already booked" guard answers first.) */
$undo = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'customer_undo', 'pnr' => $rgPnr], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$uJ = json_decode($undo['body'], true);
$uStatus = (string) pdo()->query('SELECT status FROM bookings WHERE pnr = ' . pdo()->quote($rgPnr))->fetchColumn();
check('the owner undoes the one-click ticket free (cancelled, nothing owed)', $undo['code'] === 200 && ($uJ['ok'] ?? false) === true && $uStatus === 'cancelled' && (float) ($uJ['data']['refundAmount'] ?? 1) === 0.0, 'HTTP ' . $undo['code'] . ' status ' . $uStatus);
/* "Not you?" (api/otp.php action=logout) on the browser that sold the
   ticket. 16 Nov 2026 (review): that session was never signed in (D4-03),
   so "My Bookings is 401 after logout" held before the logout too and a
   logout that did nothing passed. The browser now signs in first (name +
   mobile), so there IS a session to end: My Bookings 200 with its own
   ticket, then 401 after "Not you?" (Auth::logoutUser), and signed in again
   the ticket is no longer this browser's (Auth::forgetOwnPnrs). */
[$gc, $gj, $gb] = custPost($guest, $appCsrf, '/api/otp.php', ['action' => 'quick', 'name' => 'Role Gate Pax', 'phone' => $rgPhone]);
$myIn = req('GET', '/api/my-bookings.php?tab=all', ['jar' => $guest]);
check('  the selling browser, signed in by name + mobile, sees My Bookings with its own ticket',
    $gc === 200 && $myIn['code'] === 200 && str_contains($myIn['body'], $rgPnr), 'quick ' . $gc . ', my-bookings ' . $myIn['code'] . ' ' . substr($myIn['body'], 0, 100));
$out = req('POST', '/api/otp.php', ['jar' => $guest, 'form' => ['action' => 'logout'], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$myAfter = req('GET', '/api/my-bookings.php?tab=all', ['jar' => $guest]);
check('"Not you?" signs the server session out (My Bookings 200 before, 401 after)',
    $myIn['code'] === 200 && $out['code'] === 200 && $myAfter['code'] === 401, 'HTTP ' . $out['code'] . ', my-bookings ' . $myIn['code'] . ' → ' . $myAfter['code']);
[$gc] = custPost($guest, $appCsrf, '/api/otp.php', ['action' => 'quick', 'name' => 'Role Gate Pax', 'phone' => $rgPhone]);
$myAgain = req('GET', '/api/my-bookings.php?tab=all', ['jar' => $guest]);
check('  …and forgets this browser\'s own tickets (signed in again: the ticket is no longer listed)',
    $gc === 200 && $myAgain['code'] === 200 && !str_contains($myAgain['body'], $rgPnr), 'quick ' . $gc . ', my-bookings ' . $myAgain['code'] . ' ' . substr($myAgain['body'], 0, 100));
/* ---------------------------------------------------------------- */
// the Quick Ticket booking (+ the rows that point at it), the passenger, the accounts + their sessions
$accIn = implode(',', array_map([Database::pdo(), 'quote'], array_keys($ACCOUNTS)));
$navKeys = array_map(static fn(array $a): string => 'a' . (int) $a['id'], Database::fetchAll("SELECT id FROM admins WHERE username IN ($accIn)"));
rgCleanup($ACCOUNTS);
$leftBy = [
    'bookings'          => (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => RG_PHONE], 0),
    'admins'            => (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE username IN ($accIn)", [], 0),
    'login history'     => (int) Database::scalar("SELECT COUNT(*) FROM admin_login_events WHERE username IN ($accIn)", [], 0),
    'nav_use'           => $navKeys === [] ? 0 : (int) Database::scalar("SELECT COUNT(*) FROM kv_store WHERE kscope = 'nav_use' AND kkey IN ("
                                . implode(',', array_map([Database::pdo(), 'quote'], $navKeys)) . ')', [], 0),
    'users/profile/otp' => (int) Database::scalar('SELECT (SELECT COUNT(*) FROM users WHERE phone = :p1) + (SELECT COUNT(*) FROM user_profiles WHERE phone = :p2)
                                + (SELECT COUNT(*) FROM otp_codes WHERE identifier = :p3)', ['p1' => RG_PHONE, 'p2' => RG_PHONE, 'p3' => RG_PHONE], 0),
    'event journal'     => $rgPnr === '' ? 0 : (int) Database::scalar('SELECT COUNT(*) FROM automation_log WHERE data LIKE :d OR dedupe_key LIKE :k',
                                ['d' => '%"' . $rgPnr . '"%', 'k' => '%:' . $rgPnr . '%'], 0),
    'office bell'       => $rgPnr === '' ? 0 : (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE link LIKE :l', ['l' => '%pnr=' . $rgPnr . '%'], 0),
];
$left = array_sum($leftBy);
check('cleanup: the run\'s booking (+ journal / bell rows), accounts (+ menu rows), login history and passenger rows are gone', $left === 0,
    $left . ' row(s) left ' . json_encode(array_filter($leftBy)));
foreach ([$guest, $agentJar, $mgrJar, $quickJar, $verJar] as $j) { @unlink($j); }

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
