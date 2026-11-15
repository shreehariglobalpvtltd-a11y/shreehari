<?php
/**
 * =====================================================================
 *  u1-login-test.php — master upgrade v2, unit U1-login (16 Nov 2026).
 *
 *    P1-6a  bcrypt cost 12 (Security::hashPassword / needsRehash); an older
 *           hash is re-hashed after a verified password (Auth::adminLogin),
 *           PIN (QuickUnlock::match) and driver PIN (DriverAuth::login); the
 *           session stays signed in on its re-check after the re-hash.
 *    P1-1b  a PIN set before 15 Nov (pin_len NULL) learns its length on a
 *           correct unlock.
 *    P1-1d  CodeLogin::unlock answers a lock with 'until'; the browser session
 *           keeps it (lockUntil) and drops it once the office clears the lock.
 *    P1-1f  QuickUnlock::set ends the run of locks (unlock_strikes = 0).
 *    P1-6d  admin_login_events.via (pin / pattern / password); PIN misses on
 *           the history (not mirrored twice into audit_logs); admin_sessions.via
 *           = 'pin'; a PIN sign-in under a forced change is NOT via_otp.
 *    P1-1g  driver PIN: failed tries audited; /api/driver.php answers 429
 *           after 5 tries per phone in 15 minutes (HTTP, when a dev server
 *           runs: U1_BASE, default http://127.0.0.1:8981 — else SKIP).
 *
 *  Throwaway rows on the LOCAL shari_test database (prefix u1t-), removed
 *  before and after. Settings it touches are put back in `finally`.
 *
 *     php -c .claude/php-dev.ini tests/u1-login-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/codelogin.php';
require_once INCLUDE_PATH . '/driverauth.php';
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
function cost(string $hash): int { return (int) substr($hash, 4, 2); }

echo "\n=== U1-login: hashing, PIN length, lock timer, login history, driver PIN ===\n\n";

// this run's own "IP", so the per-IP budgets are not shared with other suites running now
$_SERVER['REMOTE_ADDR'] = '10.61.' . random_int(1, 250) . '.' . random_int(1, 250);
$IP = Security::clientIp();
$PFX = 'u1t-';
$PHONE = '98000' . random_int(10000, 99999);   // the throwaway driver's phone

$setKeys = ['quick_unlock_on', 'login_code_first_on'];
$oldSet = [];
foreach ($setKeys as $k) {
    $oldSet[$k] = Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k], null);
}
$cleanup = static function () use ($PFX, $IP, $PHONE): void {
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
    Database::run("DELETE FROM rate_limits WHERE bucket = 'code_login_try' AND identifier LIKE 'U1T-%'");
    $p = normalisePhone($PHONE);
    foreach (Database::fetchAll('SELECT id FROM drivers WHERE phone = :p', ['p' => $p]) as $d) {
        try { Database::delete('driver_devices', 'driver_id = :d', ['d' => (int) $d['id']]); } catch (Throwable $e) {}
        Database::run("DELETE FROM audit_logs WHERE entity_type = 'driver' AND entity_id = :i", ['i' => (string) $d['id']]);
        Database::delete('drivers', 'id = :d', ['d' => (int) $d['id']]);
    }
    Database::run("DELETE FROM audit_logs WHERE entity_type = 'driver' AND entity_id = :e", ['e' => '…' . substr($p, -4)]);
    Database::run("DELETE FROM rate_limits WHERE bucket IN ('driver_login', 'driver_login_phone') AND identifier IN (:p, '127.0.0.1')", ['p' => $p]);
};

$mk = static function (string $u, string $role, string $pw, int $cost, int $mustChange = 0) use ($PFX): int {
    return (int) Database::insert('admins', [
        'username' => $PFX . $u, 'password_hash' => password_hash($pw, PASSWORD_BCRYPT, ['cost' => $cost]),
        'full_name' => 'U1 Test ' . $u, 'role' => $role, 'phone' => null, 'is_active' => 1, 'must_change_pw' => $mustChange,
    ]);
};
$forceRecheck = static function (): void {
    if (isset($_SESSION[ADMIN_SESSION_KEY])) {
        $_SESSION[ADMIN_SESSION_KEY]['checked_at'] = time() - 120;
    }
};

try {
    $cleanup();
    Settings::set('quick_unlock_on', true, 'bool', 'security', false);
    Settings::set('login_code_first_on', true, 'bool', 'security', false);
    CodeLogin::$evenTiming = false;
    $_COOKIE[LoginLog::DEVICE_COOKIE] = hash('sha256', 'u1-login-device');

    /* ---------------- P1-6a: cost 12 ---------------- */
    echo "-- bcrypt cost 12 --\n";
    $h = Security::hashPassword('x-Secret-9');
    check('Security::hashPassword makes a cost-12 bcrypt hash', str_starts_with($h, '$2y$12$') && password_verify('x-Secret-9', $h));
    check('needsRehash: cost 10 yes, cost 12 no',
        Security::needsRehash(password_hash('a', PASSWORD_DEFAULT)) && !Security::needsRehash($h) && !Security::needsRehash(''));

    $pw = 'U1t@Pass-77';
    $aPw = $mk('pw', 'manager', $pw, 10);
    $r = Auth::adminLogin($PFX . 'pw', $pw);
    $nowHash = (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $aPw], '');
    check('password sign-in works with the old cost-10 hash', ($r['ok'] ?? false) === true);
    check('…and the hash on file moved to cost 12', cost($nowHash) === 12 && password_verify($pw, $nowHash), 'cost ' . cost($nowHash));
    check('…the session is stamped with the NEW hash', ($_SESSION[ADMIN_SESSION_KEY]['pw'] ?? '') === Auth::pwStamp($nowHash));
    $forceRecheck();
    check('…so the 30 s re-check keeps it signed in', Auth::admin() !== null);
    check('…via = password', ($_SESSION[ADMIN_SESSION_KEY]['via'] ?? '') === 'password');
    $r = Auth::adminLogin($PFX . 'pw', $pw);
    check('a cost-12 hash is left alone on the next sign-in',
        ($r['ok'] ?? false) && (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $aPw], '') === $nowHash);
    unset($_SESSION[ADMIN_SESSION_KEY]);

    /* ---------------- P1-1f / P1-1b / rehash on QuickUnlock ---------------- */
    echo "-- PIN: set, length backfill, re-hash --\n";
    $aLeg = $mk('leg', 'agent', 'Leg@pass-1', 12);
    QuickUnlock::set($aLeg, 'pin', '4826');
    Database::run('UPDATE admins SET unlock_strikes = 3, pin_len = NULL, pin_hash = :h WHERE id = :i',
        ['h' => password_hash('4826', PASSWORD_BCRYPT, ['cost' => 10]), 'i' => $aLeg]);
    check('an older PIN (pin_len NULL) draws 6 boxes + OK', QuickUnlock::pinLen($aLeg) === 0);
    $m = QuickUnlock::match([$aLeg], 'pin', '4826');
    $row = Database::fetch('SELECT pin_len, pin_hash, unlock_strikes FROM admins WHERE id = :i', ['i' => $aLeg]) ?? [];
    check('a correct unlock opens it', $m['ok'] === true);
    check('…and stores the PIN length (4) — auto-submit from now on', QuickUnlock::pinLen($aLeg) === 4);
    check('…and re-hashes the cost-10 PIN at cost 12', cost((string) $row['pin_hash']) === 12 && password_verify('4826', (string) $row['pin_hash']));
    check('…and the run of locks is back to 0', (int) $row['unlock_strikes'] === 0);
    Database::run('UPDATE admins SET unlock_strikes = 2 WHERE id = :i', ['i' => $aLeg]);
    QuickUnlock::set($aLeg, 'pattern', '0-4-8-5');
    check('QuickUnlock::set also resets unlock_strikes', (int) Database::scalar('SELECT unlock_strikes FROM admins WHERE id = :i', ['i' => $aLeg], 9) === 0);

    /* ---------------- P1-1d + P1-6d: the code door ---------------- */
    echo "-- code door: lock timer + login history --\n";
    $aLk = $mk('lk', 'agent', 'Lk@pass-2', 12);
    QuickUnlock::set($aLk, 'pin', '602718');
    $code = CodeLogin::codeFor($aLk);
    $r = CodeLogin::enter($code);
    check('the code opens the PIN screen', $r['step'] === 'c_unlock');
    $audBad0 = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.login.bad_password' AND entity_id = :u", ['u' => $code], 0);
    $audFail0 = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.login.code_fail' AND entity_id = :i", ['i' => (string) $aLk], 0);
    $last = [];
    for ($i = 0; $i < QuickUnlock::MAX_FAILS; $i++) {
        $last = CodeLogin::unlock('pin', '9' . $i . '1735');
    }
    $until = (int) ($last['until'] ?? 0);
    check(QuickUnlock::MAX_FAILS . ' wrong PINs: the answer carries until (~15 min ahead)',
        $until >= time() + 14 * 60 && $until <= time() + 15 * 60 + 5, 'in ' . ($until - time()) . ' s');
    check('…and the same lock as on the account', $until === QuickUnlock::lockedUntil($aLk));
    check('…kept in this browser session for a reload', CodeLogin::lockUntil() === $until);
    $ev = Database::fetchAll("SELECT outcome, via FROM admin_login_events WHERE admin_id = :a ORDER BY id", ['a' => $aLk]);
    $viaOk = LoginLog::viaReady();
    check('every miss is on the login history', count($ev) === QuickUnlock::MAX_FAILS && $ev[0]['outcome'] === 'bad_password', count($ev) . ' rows');
    if ($viaOk) {
        check('…marked via = pin', array_unique(array_column($ev, 'via')) === ['pin']);
    } else {
        skip('via column missing: run database/upgrade-2026-11-16-login-via.sql');
    }
    check('PIN misses are audited once (code_fail), not twice (no bad_password mirror)',
        (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.login.bad_password' AND entity_id = :u", ['u' => $code], 0) === $audBad0
        && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.login.code_fail' AND entity_id = :i", ['i' => (string) $aLk], 0) === $audFail0 + QuickUnlock::MAX_FAILS);
    $r = CodeLogin::unlock('pin', '602718');
    check('while locked even the right PIN waits, with until', !isset($r['go']) && (int) ($r['until'] ?? 0) === $until);
    $lastEv = Database::fetch('SELECT outcome FROM admin_login_events WHERE admin_id = :a ORDER BY id DESC LIMIT 1', ['a' => $aLk]) ?? [];
    check('…a try during the lock is logged as locked', ($lastEv['outcome'] ?? '') === 'locked');
    Database::run('UPDATE admins SET unlock_locked_until = NULL, unlock_failed = 0, unlock_strikes = 0 WHERE id = :i', ['i' => $aLk]);   // Sessions → Unlock
    check('the office clears the lock: the timer goes from the session too', CodeLogin::lockUntil() === 0);

    // a correct PIN: the session says how it was opened
    $r = CodeLogin::unlock('pin', '602718');
    $sess = $_SESSION[ADMIN_SESSION_KEY] ?? [];
    check('the right PIN opens the dashboard', ($r['go'] ?? '') === 'home' && (int) ($sess['id'] ?? 0) === $aLk);
    check('…session via = pin', ($sess['via'] ?? '') === 'pin');
    check('…no forced change on file: via_otp stays as before (no dead "change password" link)', ($sess['via_otp'] ?? null) === true);
    $as = Database::fetch("SELECT via FROM admin_sessions WHERE admin_id = :a AND ended_at IS NULL ORDER BY id DESC LIMIT 1", ['a' => $aLk]);
    check('…admin_sessions.via = pin (Sessions → Live "How")', ($as['via'] ?? '') === 'pin');
    $ok = Database::fetch("SELECT outcome, via FROM admin_login_events WHERE admin_id = :a ORDER BY id DESC LIMIT 1", ['a' => $aLk]) ?? [];
    check('…success on the history' . ($viaOk ? ' with via = pin' : ''), ($ok['outcome'] ?? '') === 'success' && (!$viaOk || ($ok['via'] ?? '') === 'pin'));
    // the office ticks "must change" (no new password) while this PIN session is open
    Database::run('UPDATE admins SET must_change_pw = 1 WHERE id = :i', ['i' => $aLk]);
    $forceRecheck();
    $still = Auth::admin();
    check('a forced change switched on mid-session: the PIN session stays…', $still !== null && !empty($still['must_change_pw']));
    check('…but is no longer via_otp (change-password.php asks the current password)', ($_SESSION[ADMIN_SESSION_KEY]['via_otp'] ?? null) === false);
    Database::run('UPDATE admins SET must_change_pw = 0 WHERE id = :i', ['i' => $aLk]);
    Auth::adminLogout();
    $lo = Database::fetch("SELECT outcome, via FROM admin_login_events WHERE admin_id = :a ORDER BY id DESC LIMIT 1", ['a' => $aLk]) ?? [];
    check('sign-out is logged with the same via', ($lo['outcome'] ?? '') === 'logout' && (!$viaOk || ($lo['via'] ?? '') === 'pin'));

    // forced password change + PIN: the current password is still asked (via_otp false)
    $aMc = $mk('mc', 'manager', 'Mc@temp-3', 12, 1);
    QuickUnlock::set($aMc, 'pin', '715302');
    CodeLogin::enter(CodeLogin::codeFor($aMc));
    $r = CodeLogin::unlock('pin', '715302');
    $sess = $_SESSION[ADMIN_SESSION_KEY] ?? [];
    check('a PIN sign-in under a forced change opens…', ($r['go'] ?? '') === 'home' && (int) ($sess['id'] ?? 0) === $aMc);
    check('…but is NOT via_otp: change-password.php asks the current password', ($sess['via_otp'] ?? null) === false && !empty($sess['must_change_pw']));
    unset($_SESSION[ADMIN_SESSION_KEY]);

    // an unknown code: on the history as unknown_user
    CodeLogin::enter('U1T-NOPE-' . random_int(100, 999));
    CodeLogin::unlock('pin', '482613');
    $uk = Database::fetch("SELECT outcome, via FROM admin_login_events WHERE username LIKE 'U1T-NOPE-%' ORDER BY id DESC LIMIT 1") ?? [];
    check('a PIN on a code that names no account is logged as unknown_user', ($uk['outcome'] ?? '') === 'unknown_user');
    Database::run("DELETE FROM admin_login_events WHERE username LIKE 'U1T-NOPE-%'");
    Database::run("DELETE FROM audit_logs WHERE action = 'admin.login.unknown_user' AND entity_id LIKE 'U1T-NOPE-%'");
    CodeLogin::clear();

    // LoginLog::record keeps via tidy
    LoginLog::record($PFX . 'raw', 'bad_password', null, "PIN'; DROP--");
    $raw = Database::fetch("SELECT via FROM admin_login_events WHERE username = :u ORDER BY id DESC LIMIT 1", ['u' => $PFX . 'raw']) ?? [];
    check('LoginLog::record stores only [a-z0-9_] in via', !$viaOk || ($raw['via'] ?? '') === 'pindrop');

    /* ---------------- P1-1g: driver PIN ---------------- */
    echo "-- driver app PIN --\n";
    $p = normalisePhone($PHONE);
    $did = (int) Database::insert('drivers', ['full_name' => 'U1 Test Driver', 'phone' => $p, 'role' => 'driver', 'is_active' => 1]);
    DriverAuth::setPin($did, '5937');
    $dh = (string) Database::scalar('SELECT pin_hash FROM drivers WHERE id = :i', ['i' => $did], '');
    check('a driver PIN is hashed at cost 12', cost($dh) === 12 && password_verify('5937', $dh));
    $a0 = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'driver.app_login_fail' AND entity_id = :i", ['i' => (string) $did], 0);
    check('a wrong driver PIN signs nobody in', DriverAuth::login($PHONE, '1111', 'test') === null);
    check('…and is audited (driver.app_login_fail)',
        (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'driver.app_login_fail' AND entity_id = :i", ['i' => (string) $did], 0) === $a0 + 1);
    Database::run('UPDATE drivers SET pin_hash = :h WHERE id = :i', ['h' => password_hash('5937', PASSWORD_DEFAULT), 'i' => $did]);
    $res = DriverAuth::login($PHONE, '5937', 'test');
    $dh = (string) Database::scalar('SELECT pin_hash FROM drivers WHERE id = :i', ['i' => $did], '');
    check('an older cost-10 driver PIN still signs in…', $res !== null);
    check('…and is re-hashed at cost 12', cost($dh) === 12 && password_verify('5937', $dh));

    $base = rtrim((string) (getenv('U1_BASE') ?: 'http://127.0.0.1:8981'), '/');
    $post = static function (string $pin) use ($base, $PHONE): int {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['action' => 'login', 'phone' => $PHONE, 'pin' => $pin]), 'ignore_errors' => true, 'timeout' => 15]]);
        $body = @file_get_contents($base . '/api/driver.php', false, $ctx);
        if ($body === false || !isset($http_response_header[0])) {
            return 0;
        }
        return preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $mm) === 1 ? (int) $mm[1] : 0;
    };
    Database::run("DELETE FROM rate_limits WHERE bucket IN ('driver_login', 'driver_login_phone') AND identifier IN (:p, '127.0.0.1')", ['p' => $p]);
    $first = $post('2468');
    if ($first === 0 || $first === 503) {
        skip('driver API not reachable at ' . $base . ' (start php -S there to run the 429 check)');
    } else {
        $codes = [$first];
        for ($i = 0; $i < 5; $i++) {
            $codes[] = $post('2468');
        }
        check('/api/driver.php: 5 wrong tries per phone answer 401…', array_slice($codes, 0, 5) === [401, 401, 401, 401, 401], implode(',', $codes));
        check('…the 6th answers 429 (15-minute pause)', $codes[5] === 429);
        check('…and even the right PIN waits during the pause', $post('5937') === 429);
    }
} catch (Throwable $e) {
    check('unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    CodeLogin::clear();
    foreach ($oldSet as $k => $v) {
        $v === null ? Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k])
                    : Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => (string) $v, 'k' => $k]);
    }
    $cleanup();
}

echo "\n  " . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . ($SKIP > 0 ? " ($SKIP skipped)" : '') . "\n\n";
exit($FAIL === 0 ? 0 : 1);
