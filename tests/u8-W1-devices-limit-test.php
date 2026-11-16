<?php
/**
 * =====================================================================
 *  u8-W1-devices-limit-test.php — master upgrade v2.0, unit W1-devices
 *  (P1-5b + P1-5c, 16 Nov 2026): per-role device limit for staff sign-in.
 *
 *    - AdminSessions::deviceLimit: setting device_limit_<role>, 0 / missing
 *      = no limit; a superadmin (and an unknown role) is never limited.
 *    - limit 0: a second device signs in exactly as before (no hold).
 *    - limit 1: a second device is HELD (Auth::deviceHold), nothing opens,
 *      the other device is offered (label + last used), audited
 *      admin.login.device_limit; the same device signing in again is not.
 *    - deviceForce: a wrong key opens nothing; the right key ends that
 *      device's sessions (its next re-check signs it out), audits
 *      admin.login.force with old / new and opens the held session.
 *    - Cancel opens nothing and keeps the other device signed in.
 *    - a password change while held drops the hold; the hold expires.
 *    - still over the limit after one sign-out -> held again with what is left.
 *    - the code door (CodeLogin::unlock with a PIN) answers step 'devices';
 *      the session it then opens says via 'pin'.
 *    - HTTP (when a dev server runs; U8W1_BASE, default
 *      http://127.0.0.1:8971 — else SKIP): the classic password post and
 *      the code + PIN door both show the device step, and "sign the other
 *      device out and continue" opens the session.
 *
 *  Throwaway rows on the LOCAL shari_test database (prefix u8w1-), removed
 *  before and after. The settings it touches are put back in `finally`.
 *
 *     php -c .claude/php-dev.ini tests/u8-W1-devices-limit-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/codelogin.php';
ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
register_shutdown_function(static function (): void { while (ob_get_level() > 0) { ob_end_flush(); } });

$PASS = 0; $FAIL = 0; $SKIP = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function skip(string $l): void { global $SKIP; $SKIP++; echo "  SKIP  $l\n"; }

/** A new HTTP request: the per-request "this request held a sign-in" flag starts false. */
function newRequest(): void {
    (new ReflectionProperty(Auth::class, 'heldThisRequest'))->setValue(null, false);
}
/** Be one browser: its device cookie + user agent, a fresh (signed-out) session. */
function asDevice(string $name, string $ua): void {
    newRequest();
    $_COOKIE[LoginLog::DEVICE_COOKIE] = hash('sha256', 'u8w1-device-' . $name);
    $_SERVER['HTTP_USER_AGENT'] = $ua;
    unset($_SESSION[ADMIN_SESSION_KEY]);
    Auth::clearDoorState();
}
function setLimit(string $role, ?int $n): void {
    if ($n === null) {
        Database::run('DELETE FROM settings WHERE skey = :k', ['k' => 'device_limit_' . $role]);
    } else {
        Database::run("INSERT INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES (:k, :v, 'int', 'security', 'test', 0)
                       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)", ['k' => 'device_limit_' . $role, 'v' => (string) $n]);
    }
    (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, null);
}
/** Auth::adminLogin with this run's per-IP sign-in budget reset (every call counts against it). */
function signIn(string $user): array {
    global $IP, $PW;
    Database::run("DELETE FROM rate_limits WHERE bucket = 'admin_login' AND identifier = :ip", ['ip' => $IP]);
    return Auth::adminLogin($user, $PW);
}
function audits(string $action, int $id): int {
    return (int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE action = :a AND entity_id = :i', ['a' => $action, 'i' => (string) $id], 0);
}

echo "\n=== W1-devices: per-role device limit (P1-5b + P1-5c) ===\n\n";

$_SERVER['REMOTE_ADDR'] = '10.81.' . random_int(1, 250) . '.' . random_int(1, 250);
$IP  = Security::clientIp();
$PFX = 'u8w1-';
$PW  = 'U8w1-pass-7731!';
$PIN = '582941';
CodeLogin::$evenTiming = false;
CodeLogin::$deliverHook = static fn(string $intl, string $code): bool => true;

$UA_A = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36';
$UA_B = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36';
$UA_C = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1';

$keys = ['device_limit_counter', 'device_limit_agent', 'device_limit_superadmin', 'quick_unlock_on', 'login_code_first_on', 'session_timeout_min'];
$old = [];
foreach ($keys as $k) {
    $old[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
}
$cleanup = static function () use ($PFX, $IP): void {
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE '{$PFX}%'") as $r) {
        $id = (int) $r['id'];
        foreach (['admin_sessions', 'admin_devices', 'admin_profiles', 'login_recovery_requests', 'admin_login_events'] as $t) {
            try { Database::delete($t, 'admin_id = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        try { Database::run("DELETE FROM audit_logs WHERE entity_type = 'admin' AND entity_id = :i", ['i' => (string) $id]); } catch (Throwable $e) {}
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    Database::run("DELETE FROM admin_login_events WHERE username LIKE '{$PFX}%'");
    Database::run("DELETE FROM audit_logs WHERE entity_id LIKE '{$PFX}%'");
    Database::run('DELETE FROM rate_limits WHERE identifier = :ip', ['ip' => $IP]);
    Database::run("DELETE FROM rate_limits WHERE identifier LIKE 'U8W1-%' OR identifier LIKE 'u8w1-%'");
};
$cleanup();

$mk = static function (string $user, string $role) use ($PW): int {
    return Database::insert('admins', [
        'username' => $user, 'password_hash' => Security::hashPassword($PW), 'full_name' => 'U8W1 ' . $role,
        'role' => $role, 'is_active' => 1, 'must_change_pw' => 0,
    ]);
};
$liveRows = static fn(int $id): array => Database::fetchAll(
    'SELECT id, device_hash, label, via, ended_at, end_reason FROM admin_sessions WHERE admin_id = :a ORDER BY id', ['a' => $id]);

try {
    setLimit('counter', null);
    setLimit('agent', null);
    Settings::set('quick_unlock_on', true, 'bool', 'security');
    Settings::set('login_code_first_on', true, 'bool', 'security');
    (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, null);

    $cid = $mk($PFX . 'counter', 'counter');
    $sid = $mk($PFX . 'super', 'superadmin');
    $aid = $mk($PFX . 'agent', 'agent');
    QuickUnlock::set($cid, 'pin', $PIN);

    // ---- the setting ----------------------------------------------------
    check('no row = no limit', AdminSessions::deviceLimit('counter') === 0);
    setLimit('counter', 1);
    check('device_limit_counter = 1 is read', AdminSessions::deviceLimit('counter') === 1);
    setLimit('superadmin', 3);
    check('a superadmin is never limited (even with a row)', AdminSessions::deviceLimit('superadmin') === 0);
    check('an unknown role is never limited', AdminSessions::deviceLimit('nosuchrole') === 0);
    setLimit('counter', 0);
    check('0 = no limit', AdminSessions::deviceLimit('counter') === 0);

    // ---- limit 0: as before ---------------------------------------------
    asDevice('A', $UA_A);
    $r = signIn($PFX . 'counter');
    check('limit 0: device A signs in', !empty($r['ok']) && empty($r['devices']) && Auth::admin() !== null);
    $sessA = $_SESSION[ADMIN_SESSION_KEY];
    asDevice('B', $UA_B);
    $r = signIn($PFX . 'counter');
    check('limit 0: device B signs in too (no hold)', !empty($r['ok']) && empty($r['devices']) && Auth::admin() !== null && Auth::deviceHold() === null);
    AdminSessions::endAll($cid, 0, 'test reset');

    // ---- limit 1: the hold ----------------------------------------------
    setLimit('counter', 1);
    asDevice('A', $UA_A);
    $r = signIn($PFX . 'counter');
    check('limit 1: the first device signs in', !empty($r['ok']) && empty($r['devices']) && Auth::admin() !== null);
    $sessA = $_SESSION[ADMIN_SESSION_KEY];

    asDevice('A', $UA_A);
    $r = signIn($PFX . 'counter');
    check('the SAME device signing in again is not held', empty($r['devices']) && Auth::admin() !== null);
    $sessA = $_SESSION[ADMIN_SESSION_KEY];
    Database::run('UPDATE admin_sessions SET last_seen = DATE_SUB(NOW(), INTERVAL 4 MINUTE) WHERE admin_id = :a AND ended_at IS NULL', ['a' => $cid]);

    $held0 = audits('admin.login.device_limit', $cid);
    asDevice('B', $UA_B);
    $r = signIn($PFX . 'counter');
    $hold = Auth::deviceHold();
    check('device B: the door passes but the sign-in is held', !empty($r['ok']) && !empty($r['devices']));
    check('…nothing is open in this browser', Auth::admin() === null && empty($_SESSION[ADMIN_SESSION_KEY]));
    $hashB = hash('sha256', (string) $_COOKIE[LoginLog::DEVICE_COOKIE]);
    check('…no session row was opened for device B',
        array_filter($liveRows($cid), static fn(array $s): bool => $s['ended_at'] === null && (string) $s['device_hash'] === $hashB) === []);
    check('…the hold names the account, the limit and one other device',
        $hold !== null && (int) $hold['id'] === $cid && (int) $hold['limit'] === 1 && count($hold['offer']) === 1);
    $d1 = $hold['offer']['d1'] ?? [];
    check('…offered as "Chrome on Android" + last used', ($d1['label'] ?? '') === 'Chrome on Android' && ($d1['last_seen'] ?? '') !== '');
    check('…the offer carries no session token', !str_contains(json_encode($hold), (string) ($sessA['sk'] ?? 'x')));
    check('…audited admin.login.device_limit', audits('admin.login.device_limit', $cid) === $held0 + 1);

    // a wrong key opens nothing
    $f = Auth::deviceForce('d9');
    check('a key that was not offered opens nothing', empty($f['ok']) && Auth::admin() === null && Auth::deviceHold() !== null);

    // the right key: A ends, B opens
    $force0 = audits('admin.login.force', $cid);
    $f = Auth::deviceForce('d1');
    check('"sign the other device out and continue" opens the session', !empty($f['ok']) && Auth::admin() !== null && (int) Auth::admin()['id'] === $cid);
    check('…the hold is used up', Auth::deviceHold() === null);
    $rows = $liveRows($cid);
    $endedA = array_values(array_filter($rows, static fn(array $s): bool => $s['ended_at'] !== null && str_contains((string) $s['end_reason'], 'device limit')));
    check('…device A\'s session is ended (reason: device limit)', count($endedA) >= 1);
    check('…device B now has the one live session', count(AdminSessions::liveDevices($cid)) === 1
        && (string) (AdminSessions::liveDevices($cid)[0]['label'] ?? '') === 'Chrome on Windows');
    $copyA = $sessA;
    check('…device A is signed out on its next re-check', AdminSessions::stillOpen($copyA) === false);
    $au = Database::fetch("SELECT old_value, new_value FROM audit_logs WHERE action = 'admin.login.force' AND entity_id = :i ORDER BY id DESC LIMIT 1", ['i' => (string) $cid]);
    check('…audited admin.login.force with the old and new device', audits('admin.login.force', $cid) === $force0 + 1
        && str_contains((string) ($au['old_value'] ?? ''), 'Chrome on Android') && str_contains((string) ($au['new_value'] ?? ''), 'Chrome on Windows'));

    // ---- Cancel -----------------------------------------------------------
    asDevice('C', $UA_C);
    $r = signIn($PFX . 'counter');
    check('device C is held while B is signed in', !empty($r['devices']) && Auth::deviceHold() !== null);
    $c0 = audits('admin.login.device_cancel', $cid);
    Auth::deviceCancel();
    check('Cancel: nothing opens, the hold is gone', Auth::admin() === null && Auth::deviceHold() === null);
    check('…device B stays signed in', count(AdminSessions::liveDevices($cid)) === 1);
    check('…audited admin.login.device_cancel', audits('admin.login.device_cancel', $cid) === $c0 + 1);
    check('a force after Cancel opens nothing', empty(Auth::deviceForce('d1')['ok']) && Auth::admin() === null);

    // ---- start again drops the hold (a DOOR_KEY) --------------------------
    $r = signIn($PFX . 'counter');
    Auth::clearDoorState();
    check('the request that held it keeps the hold through the door\'s own clean-up', Auth::deviceHold() !== null);
    newRequest();
    Auth::clearDoorState();
    check('"start again" on a later request (clearDoorState) drops a hold', Auth::deviceHold() === null);

    // ---- a password change while held -------------------------------------
    $r = signIn($PFX . 'counter');
    $hash = (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $cid], '');
    Database::update('admins', ['password_hash' => Security::hashPassword('Other-pass-1199!')], 'id = :i', ['i' => $cid]);
    $f = Auth::deviceForce('d1');
    check('a password changed while held: nothing opens, sign in again', empty($f['ok']) && !empty($f['expired']) && Auth::admin() === null && Auth::deviceHold() === null);
    check('…and the other device was left alone', count(AdminSessions::liveDevices($cid)) === 1);
    Database::update('admins', ['password_hash' => $hash], 'id = :i', ['i' => $cid]);

    // ---- the hold runs out ------------------------------------------------
    $r = signIn($PFX . 'counter');
    $_SESSION[Auth::DEVICE_HOLD_KEY]['at'] = time() - Auth::DEVICE_HOLD_TTL - 5;
    check('a hold older than 5 minutes is gone', Auth::deviceHold() === null);

    // ---- still over the limit after one sign-out --------------------------
    // B is live; open A again with limit 0, then limit 1 -> C sees two others
    setLimit('counter', 0);
    asDevice('A', $UA_A);
    signIn($PFX . 'counter');
    Database::run("UPDATE admin_sessions SET last_seen = DATE_SUB(NOW(), INTERVAL 9 MINUTE) WHERE admin_id = :a AND ended_at IS NULL AND label = 'Chrome on Android'", ['a' => $cid]);
    setLimit('counter', 1);
    asDevice('C', $UA_C);
    $r = signIn($PFX . 'counter');
    $hold = Auth::deviceHold();
    check('limit lowered: two other devices are offered', $hold !== null && count($hold['offer']) === 2);
    check('…oldest use first (pre-ticked)', ($hold['offer']['d1']['label'] ?? '') === 'Chrome on Android');
    $f = Auth::deviceForce('d1');
    check('…one sign-out is not enough: held again, nothing opens', empty($f['ok']) && empty($f['expired']) && Auth::admin() === null && Auth::deviceHold() !== null);
    $hold = Auth::deviceHold();
    check('…the step now offers only what is left', $hold !== null && count($hold['offer']) === 1 && ($hold['offer']['d1']['label'] ?? '') === 'Chrome on Windows');
    $f = Auth::deviceForce('d1');
    check('…the second sign-out opens the session', !empty($f['ok']) && Auth::admin() !== null);
    check('…and only this device is live now', count(AdminSessions::liveDevices($cid)) === 1
        && (string) (AdminSessions::liveDevices($cid)[0]['label'] ?? '') === 'Safari on iPhone');

    // ---- the code door: code + PIN ----------------------------------------
    asDevice('A', $UA_A);
    $e = CodeLogin::enter($PFX . 'counter');
    check('code door: the code leads to the PIN', ($e['step'] ?? '') === 'c_unlock');
    $u = CodeLogin::unlock('pin', $PIN);
    check('code door: the right PIN on one device too many answers step "devices"', ($u['step'] ?? '') === 'devices' && Auth::admin() === null && Auth::deviceHold() !== null);
    check('…the half-done code door is cleared', CodeLogin::state() === null);
    $f = Auth::deviceForce('d1');
    check('…continue opens the session', !empty($f['ok']) && Auth::admin() !== null);
    $via = (string) Database::scalar('SELECT via FROM admin_sessions WHERE admin_id = :a AND ended_at IS NULL ORDER BY id DESC LIMIT 1', ['a' => $cid], '');
    check('…which says it was opened by PIN', $via === 'pin' && (string) ($_SESSION[ADMIN_SESSION_KEY]['via'] ?? '') === 'pin');
    check('…the sign-in audit (admin.login, "(pin)") is written once the session opens',
        (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.login' AND entity_id = :i AND detail LIKE '%(pin)%'", ['i' => (string) $cid], 0) >= 1);

    // ---- the classic door: ID + PIN quick unlock (OneDoor::quick) ---------
    require_once INCLUDE_PATH . '/onedoor.php';
    asDevice('B', $UA_B);
    $w = OneDoor::who($PFX . 'counter', '91');
    $pin0 = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.login.pin' AND entity_id = :i", ['i' => (string) $cid], 0);
    $q = OneDoor::quick('pin', $PIN);
    check('classic door: ID + right PIN on one device too many opens nothing', ($w['step'] ?? '') === 'id' && Auth::admin() === null);
    check('…the hold survives the door\'s own clean-up (OneDoor::clear)', Auth::deviceHold() !== null && (int) Auth::deviceHold()['id'] === $cid);
    check('…no "signed in" audit while held',
        (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.login.pin' AND entity_id = :i", ['i' => (string) $cid], 0) === $pin0);
    $f = Auth::deviceForce('d1');
    check('…continue opens it (via pin)', !empty($f['ok']) && Auth::admin() !== null && (string) ($_SESSION[ADMIN_SESSION_KEY]['via'] ?? '') === 'pin');

    // ---- superadmin: never limited ---------------------------------------
    asDevice('A', $UA_A);
    signIn($PFX . 'super');
    asDevice('B', $UA_B);
    $r = signIn($PFX . 'super');
    asDevice('C', $UA_C);
    $r2 = signIn($PFX . 'super');
    check('a superadmin signs in on a third device without a hold', empty($r['devices']) && empty($r2['devices']) && Auth::admin() !== null);

    // ---- another role keeps its own limit ---------------------------------
    asDevice('A', $UA_A);
    signIn($PFX . 'agent');
    asDevice('B', $UA_B);
    $r = signIn($PFX . 'agent');
    check('the counter limit does not touch an agent (agent limit 0)', empty($r['devices']) && Auth::admin() !== null);
    setLimit('agent', 2);
    asDevice('C', $UA_C);
    $r = signIn($PFX . 'agent');
    check('agent limit 2 with two devices live: the third is held', !empty($r['devices']) && Auth::admin() === null);
    Auth::deviceCancel();

    // ---- HTTP: both doors show the step (needs a dev server) --------------
    $base = rtrim((string) (getenv('U8W1_BASE') ?: 'http://127.0.0.1:8971'), '/');
    $jar = [];
    $http = static function (string $method, string $path, array $form = []) use ($base, &$jar, $UA_B): array {
        $hdr = "User-Agent: $UA_B\r\n";
        if ($jar !== []) {
            $hdr .= 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($jar), $jar)) . "\r\n";
        }
        $opt = ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0];
        if ($method === 'POST') {
            $opt['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $opt['content'] = http_build_query($form);
        }
        $body = @file_get_contents($base . $path, false, stream_context_create(['http' => $opt]));
        $code = 0; $loc = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int) $m[1]; }
            if (stripos($h, 'Location:') === 0) { $loc = trim(substr($h, 9)); }
            if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $h, $m)) { $jar[$m[1]] = $m[2]; }
        }
        return ['code' => $code, 'body' => (string) ($body === false ? '' : $body), 'loc' => $loc];
    };
    $csrfOf = static fn(string $html): string => preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m) === 1 ? html_entity_decode($m[1]) : '';

    $g = $http('GET', '/admin/login.php?classic=1&other=1');
    if ($g['code'] !== 200) {
        skip('login page not reachable at ' . $base . ' (start php -S there to run the HTTP checks)');
    } else {
        Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1')"
            . " AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try')");
        // device A (this CLI) is live for the counter; the HTTP client is a new device
        AdminSessions::endAll($cid, 0, 'test reset');
        asDevice('A', $UA_A);
        signIn($PFX . 'counter');
        check('HTTP: device A is live before the web sign-in', count(AdminSessions::liveDevices($cid)) === 1);

        // the classic door: the plain username + password post
        $p = $http('POST', '/admin/login.php', [CSRF_TOKEN_NAME => $csrfOf($g['body']), 'classic' => '1', 'username' => $PFX . 'counter', 'password' => $PW]);
        check('HTTP classic door: the right password shows the device step', $p['code'] === 200
            && str_contains($p['body'], 'value="dev_force"') && str_contains($p['body'], 'Sign the other device out and continue')
            && str_contains($p['body'], 'Chrome on Android'));
        check('…with Cancel', str_contains($p['body'], 'value="dev_cancel"'));
        $g2 = $http('GET', '/admin/login.php');
        check('…a reload still shows the step', str_contains($g2['body'], 'value="dev_force"'));
        $c = $http('POST', '/admin/login.php', [CSRF_TOKEN_NAME => $csrfOf($g2['body']), 'mode' => 'dev_cancel']);
        check('…Cancel goes back to the door, nothing opened', $c['code'] === 200 && !str_contains($c['body'], 'value="dev_force"')
            && count(AdminSessions::liveDevices($cid)) === 1);

        // the classic one door as people use it (?classic=1): ID, then password (OneDoor::password)
        Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket = 'admin_login'");
        $w1 = $http('POST', '/admin/login.php', [CSRF_TOKEN_NAME => $csrfOf($c['body']), 'classic' => '1', 'mode' => 'who', 'who' => $PFX . 'counter', 'cc' => '91']);
        $w2 = $http('POST', '/admin/login.php', [CSRF_TOKEN_NAME => $csrfOf($w1['body']), 'classic' => '1', 'mode' => 'pw', 'password' => $PW]);
        check('HTTP classic one door (ID + password): the device step', $w2['code'] === 200 && str_contains($w2['body'], 'value="dev_force"'));
        $c2 = $http('POST', '/admin/login.php', [CSRF_TOKEN_NAME => $csrfOf($w2['body']), 'classic' => '1', 'mode' => 'dev_cancel']);
        check('…Cancel: nothing opened', !str_contains($c2['body'], 'value="dev_force"') && count(AdminSessions::liveDevices($cid)) === 1);
        $c = $c2;

        // the code-first door: code, then PIN
        $s1 = $http('POST', '/admin/login.php', [CSRF_TOKEN_NAME => $csrfOf($c['body']), 'mode' => 'c_code', 'code' => $PFX . 'counter']);
        $s2 = $http('POST', '/admin/login.php', [CSRF_TOKEN_NAME => $csrfOf($s1['body']), 'mode' => 'c_unlock', 'qk_kind' => 'pin', 'qk_val' => $PIN]);
        check('HTTP code door: code + right PIN shows the device step', $s2['code'] === 200 && str_contains($s2['body'], 'value="dev_force"'));
        $bad = $http('POST', '/admin/login.php', ['mode' => 'dev_force', 'dev' => 'd1']);
        check('…a post without the CSRF token signs nobody out', $bad['code'] === 200 && count(AdminSessions::liveDevices($cid)) === 1
            && (string) (AdminSessions::liveDevices($cid)[0]['label'] ?? '') === 'Chrome on Android');
        $g3 = $http('GET', '/admin/login.php');
        check('…and the step is still there', str_contains($g3['body'], 'value="dev_force"'));
        $s3 = $http('POST', '/admin/login.php', [CSRF_TOKEN_NAME => $csrfOf($g3['body']), 'mode' => 'dev_force', 'dev' => 'd1']);
        check('…"sign the other device out and continue" opens the session (redirect)', $s3['code'] === 302 && $s3['loc'] !== '' && !str_contains($s3['loc'], 'login.php'), $s3['code'] . ' ' . $s3['loc']);
        $live = AdminSessions::liveDevices($cid);
        check('…device A is ended, the web browser is the one live device', count($live) === 1 && (string) $live[0]['label'] === 'Chrome on Windows');
        $via = (string) Database::scalar('SELECT via FROM admin_sessions WHERE admin_id = :a AND ended_at IS NULL ORDER BY id DESC LIMIT 1', ['a' => $cid], '');
        check('…opened as a PIN sign-in', $via === 'pin');

        // review (W1): this browser now REMEMBERS the account (QuickUnlock cookie). A GET of the
        // door (a reload, or another door's page bouncing back via ?next=) must keep the hold,
        // not restart the remembered-phone lock screen and silently drop it.
        $http('GET', '/admin/logout.php');
        Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'quick_unlock', 'code_login_try')");
        asDevice('A', $UA_A);
        signIn($PFX . 'counter');
        $r1 = $http('GET', '/admin/login.php');
        check('HTTP remembered phone: the door opens on its lock screen', str_contains($r1['body'], 'value="c_unlock"') && !str_contains($r1['body'], 'value="dev_force"'));
        $r2 = $http('POST', '/admin/login.php', [CSRF_TOKEN_NAME => $csrfOf($r1['body']), 'mode' => 'c_unlock', 'qk_kind' => 'pin', 'qk_val' => $PIN]);
        check('…the right PIN on one device too many shows the device step', str_contains($r2['body'], 'value="dev_force"'));
        $r3 = $http('GET', '/admin/login.php?next=home.php');
        check('…a GET of the door (another page bounced back) still shows the step', str_contains($r3['body'], 'value="dev_force"'));
        $r4 = $http('POST', '/admin/login.php', [CSRF_TOKEN_NAME => $csrfOf($r3['body']), 'mode' => 'dev_cancel']);
        check('…Cancel: nothing opened, device A still the one live device', !str_contains($r4['body'], 'value="dev_force"')
            && count(AdminSessions::liveDevices($cid)) === 1 && (string) (AdminSessions::liveDevices($cid)[0]['label'] ?? '') === 'Chrome on Android');
    }
} catch (Throwable $e) {
    check('unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION[Auth::DEVICE_HOLD_KEY]);
    CodeLogin::clear();
    foreach ($old as $k => $row) {
        $row === null ? Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k])
                      : Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => (string) $row['svalue'], 'k' => $k]);
    }
    Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try')");
    $cleanup();
}

echo "\n  " . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . ($SKIP > 0 ? " ($SKIP skipped)" : '') . "\n\n";
exit($FAIL === 0 ? 0 : 1);
