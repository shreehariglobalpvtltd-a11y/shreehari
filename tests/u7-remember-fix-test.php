<?php
/**
 * =====================================================================
 *  u7-remember-fix-test.php — the review fixes to "stay signed in"
 *  (19 Nov 2026, LG-1 review):
 *    - office roles / 2FA accounts: a FIXED cap from the original sign-in
 *      (never extended by a resume), and no resume after session_timeout_min
 *      idle; their idle timeout in Auth::admin() still signs them out;
 *    - field roles: the sliding window and the idle skip stay;
 *    - a second tab's grace resume lives on the remembered row ('rmid'):
 *      no orphan 'earlier' row, and the office's End signs it out;
 *    - the person's own password change in that tab keeps the device;
 *    - the relock: a field session is locked (not signed out) and opened
 *      again with its own PIN; an office session is never relocked; a field
 *      device resumed after long idle opens locked;
 *    - _guard.php sends a field session to the relock, an office one to logout;
 *    - CustomerProfile::prune drops old ended / expired rows only.
 *
 *  Throwaway accounts 'zzu7f-*' (deleted before and after); settings are
 *  changed in memory only.
 *     php -c .claude/php-dev.ini tests/u7-remember-fix-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickunlock.php';
require_once INCLUDE_PATH . '/customerprofile.php';

ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
register_shutdown_function(static function (): void { while (ob_get_level() > 0) { ob_end_flush(); } });

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }
function memSetting(string $key, string $value, string $type): void {
    $r = new ReflectionClass(Settings::class);
    $c = $r->getProperty('cache'); $c->setAccessible(true);
    $d = $r->getProperty('decoded'); $d->setAccessible(true);
    Settings::get('company_name');
    $cache = (array) $c->getValue();
    $cache[$key] = ['value' => $value, 'type' => $type, 'public' => false];
    $c->setValue(null, $cache);
    $dec = (array) $d->getValue();
    unset($dec[$key]);
    $d->setValue(null, $dec);
}
function clearLimits(): void {
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%' OR bucket LIKE 'code_login%' OR bucket = 'admin_remember_miss' OR bucket = 'quick_unlock'");
}
function gone(): void { $_SESSION = []; }
const DEV_A = 'abababababababababababababababababababababababababababababababab';
const PW    = 'u7f-Remember-pw-61k';
const PIN   = '4826';

echo "\n=== u7 review fixes: office cap, grace row, relock, prune ===\n\n";
if (!AdminSessions::rememberReady() || !QuickUnlock::ready()) {
    echo "  SKIP  run the u7 migration (and the quick-unlock one) first\n";
    exit(1);
}

$users = ['zzu7f-agent', 'zzu7f-mgr'];
$custPhone = '9000071901';
$cleanup = static function () use ($users, $custPhone): void {
    foreach ($users as $u) {
        $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
        if ($id > 0) {
            Database::run('DELETE FROM admin_sessions WHERE admin_id = :a', ['a' => $id]);
            Database::run('DELETE FROM admins WHERE id = :a', ['a' => $id]);
        }
    }
    Database::run("DELETE FROM customer_sessions WHERE label = 'ZZTEST u7f'");
};
$cleanup();
register_shutdown_function($cleanup);

$mk = static function (string $u, string $role, string $phone): int {
    return (int) Database::insert('admins', [
        'username' => $u, 'password_hash' => Security::hashPassword(PW), 'full_name' => 'ZZTEST u7f ' . $role,
        'phone' => $phone, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0,
    ]);
};
$aAgent = $mk('zzu7f-agent', 'agent', '9000071801');
$aMgr   = $mk('zzu7f-mgr', 'manager', '9000071802');

memSetting('login_remember_days', '365', 'int');
memSetting('login_remember_max_days', '730', 'int');   // 25 Nov 2026 (mp1-lg): the 90-day cap raised for this 365-day suite
memSetting('login_remember_office_hours', '24', 'int');
memSetting('login_remember_roles', '["agent","counter","scanner","support","operations","staff"]', 'json');
memSetting('session_timeout_min', '45', 'int');
memSetting('staff_idle_lock_min', '30', 'string');
memSetting('quick_unlock_on', '1', 'bool');
foreach (['agent', 'manager'] as $r) {
    memSetting('device_limit_' . $r, '0', 'int');
}
$_COOKIE[LoginLog::DEVICE_COOKIE] = DEV_A;

$login = static function (string $user): string {
    clearLimits();
    gone();
    unset($_COOKIE[AdminSessions::REMEMBER_COOKIE]);
    Auth::adminLogin($user, PW);
    return (string) AdminSessions::$lastRememberToken;
};
$rowOf = static fn(string $t): ?array => Database::fetch('SELECT * FROM admin_sessions WHERE remember_hash = :h', ['h' => hash('sha256', $t)]);
$resumeWith = static function (string $token): bool {
    gone();
    $_COOKIE[AdminSessions::REMEMBER_COOKIE] = $token;
    return AdminSessions::resume();
};

/* ------------------------------------------------------------------ */
section('Office roles: a fixed cap, not a sliding window');
$t = $login('zzu7f-mgr');
check('manager is remembered (24 h) but not as a field account', $t !== '' && empty($_SESSION[ADMIN_SESSION_KEY]['rmf']));
$rw = $rowOf($t);
Database::run('UPDATE admin_sessions SET created_at = DATE_SUB(NOW(), INTERVAL 23 HOUR), remember_until = DATE_ADD(NOW(), INTERVAL 1 HOUR), last_seen = NOW() WHERE id = :i',
    ['i' => (int) $rw['id']]);
$ok = $resumeWith($t);
$after = Database::fetch('SELECT * FROM admin_sessions WHERE id = :i', ['i' => (int) $rw['id']]);
$u = strtotime((string) $after['remember_until']);
check('signed in 23 h ago: resumed…', $ok && (int) (Auth::admin()['id'] ?? 0) === $aMgr);
check('…but remember_until is NOT moved forward (still about 1 h left)', $u <= time() + 3600 + 5 && $u > time() + 3500, (string) $after['remember_until']);
check('…and the session\'s rm is that same cap', (int) ($_SESSION[ADMIN_SESSION_KEY]['rm'] ?? 0) === $u);
check('…and it is not a field session (idle timeout applies)', empty($_SESSION[ADMIN_SESSION_KEY]['rmf']));
$t2 = (string) AdminSessions::$lastRememberToken;
Database::run('UPDATE admin_sessions SET created_at = DATE_SUB(NOW(), INTERVAL 25 HOUR), remember_until = DATE_ADD(NOW(), INTERVAL 3 HOUR), last_seen = NOW() WHERE id = :i',
    ['i' => (int) $rw['id']]);
check('signed in 25 h ago (cap passed) is refused even if remember_until says later', $resumeWith($t2) === false);

$t = $login('zzu7f-mgr');
Database::run('UPDATE admin_sessions SET last_seen = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE remember_hash = :h', ['h' => hash('sha256', $t)]);
check('office device idle 2 h (> session_timeout_min) is not let back in', $resumeWith($t) === false);
check('…and the row is ended', Database::scalar('SELECT ended_at FROM admin_sessions WHERE remember_hash = :h', ['h' => hash('sha256', $t)], null) !== null);

$login('zzu7f-mgr');
$_SESSION[ADMIN_SESSION_KEY]['last_seen'] = time() - 6 * 3600;
check('an open office session still hits the idle timeout although remembered', Auth::admin() === null);

/* ------------------------------------------------------------------ */
section('Field roles: sliding window and idle skip stay');
$t = $login('zzu7f-agent');
check('agent is a field account (rmf)', !empty($_SESSION[ADMIN_SESSION_KEY]['rmf']));
Database::run('UPDATE admin_sessions SET remember_until = DATE_ADD(NOW(), INTERVAL 1 DAY), last_seen = NOW() WHERE remember_hash = :h', ['h' => hash('sha256', $t)]);
$ok = $resumeWith($t);
check('field resume slides the window again (~365 days)', $ok && (int) ($_SESSION[ADMIN_SESSION_KEY]['rm'] ?? 0) > time() + 364 * 86400);
$_SESSION[ADMIN_SESSION_KEY]['last_seen'] = time() - 6 * 3600;
check('a remembered field session is not ended by the idle timeout', Auth::admin() !== null);

/* ------------------------------------------------------------------ */
section('Second tab (grace) lives on the remembered row');
$t1 = $login('zzu7f-agent');
$rid = (int) $rowOf($t1)['id'];
$resumeWith($t1);                                   // tab A rotates
$tA = (string) AdminSessions::$lastRememberToken;
$rows0 = (int) Database::scalar('SELECT COUNT(*) FROM admin_sessions WHERE admin_id = :a', ['a' => $aAgent], 0);
$ok = $resumeWith($t1);                             // tab B inside the grace
check('tab B resumes inside the grace with no key of its own but the row id', $ok && ($_SESSION[ADMIN_SESSION_KEY]['sk'] ?? 'x') === ''
    && (int) ($_SESSION[ADMIN_SESSION_KEY]['rmid'] ?? 0) === $rid);
unset($_SESSION[ADMIN_SESSION_KEY]['locked']);      // (no PIN on this account yet anyway)
$_SESSION[ADMIN_SESSION_KEY]['checked_at'] = time() - 120;
check('its re-check keeps it open', Auth::admin() !== null);
check('…without registering an orphan "earlier" row', (int) Database::scalar('SELECT COUNT(*) FROM admin_sessions WHERE admin_id = :a', ['a' => $aAgent], 0) === $rows0);
$tabB = $_SESSION;

// own password change in tab B keeps the device (restamp by rmid / cookie)
$old = (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $aAgent], '');
Database::run('UPDATE admins SET password_hash = :h WHERE id = :i', ['h' => Security::hashPassword('own-new-pw-u7f'), 'i' => $aAgent]);
$_COOKIE[AdminSessions::REMEMBER_COOKIE] = $t1;     // tab B's browser still sends the token it resumed with
Auth::passwordChanged($aAgent, $aAgent, $old);
check('own password change in tab B: the device stays remembered', $resumeWith($tA) === true && (int) (Auth::admin()['id'] ?? 0) === $aAgent);
$tA = (string) AdminSessions::$lastRememberToken;
Database::run('UPDATE admins SET password_hash = :h WHERE id = :i', ['h' => Security::hashPassword(PW), 'i' => $aAgent]);

// office End on the remembered row signs tab B out
$_SESSION = $tabB;
$_SESSION[ADMIN_SESSION_KEY]['pw'] = Auth::pwStamp((string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $aAgent], ''));
AdminSessions::end($rid, 1, 'test: office End');
$_SESSION[ADMIN_SESSION_KEY]['checked_at'] = time() - 120;
$_SESSION[ADMIN_SESSION_KEY]['last_seen'] = time() - 2 * 3600;
check('office End on the "stays signed in" row signs the grace tab out too', Auth::admin() === null);

/* ------------------------------------------------------------------ */
section('Relock (idle lock asks the PIN, keeps the session)');
QuickUnlock::set($aAgent, 'pin', PIN);
QuickUnlock::set($aMgr, 'pin', PIN);
$t = $login('zzu7f-agent');
check('field + PIN: relockable', Auth::relockable());
check('Auth::relock() locks it', Auth::relock() && Auth::lockedSession() !== null);
check('while locked nothing opens (admin() null)…', Auth::admin() === null);
check('…but the session is kept', (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) === $aAgent && Auth::lockedSession() !== null);
clearLimits();
$r = Auth::unlockSession('pin', '9157');
check('a wrong PIN does not open it', ($r['ok'] ?? true) === false && Auth::lockedSession() !== null);
clearLimits();
$r = Auth::unlockSession('pin', PIN);
check('its own PIN opens it again', ($r['ok'] ?? false) === true && Auth::lockedSession() === null && (int) (Auth::admin()['id'] ?? 0) === $aAgent);
check('…and the remember row is still live', Database::scalar('SELECT ended_at FROM admin_sessions WHERE remember_hash = :h', ['h' => hash('sha256', $t)], 'x') === null);
Database::run('UPDATE admins SET unlock_failed = 0, unlock_locked_until = NULL WHERE id = :i', ['i' => $aAgent]);

$login('zzu7f-mgr');
check('office role with a PIN is never relocked (its idle timer signs out)', !Auth::relockable() && Auth::relock() === false && Auth::lockedSession() === null);

$t = $login('zzu7f-agent');
Database::run('UPDATE admin_sessions SET last_seen = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE remember_hash = :h', ['h' => hash('sha256', $t)]);
$ok = $resumeWith($t);
check('a field device back after > staff_idle_lock_min opens LOCKED', $ok && Auth::lockedSession() !== null && Auth::admin() === null);
$t = (string) AdminSessions::$lastRememberToken;
Database::run('UPDATE admin_sessions SET last_seen = NOW() WHERE remember_hash = :h', ['h' => hash('sha256', $t)]);
$ok = $resumeWith($t);
check('…back after a short while opens unlocked', $ok && Auth::lockedSession() === null && Auth::admin() !== null);
memSetting('staff_idle_lock_min', '0', 'string');
$t = (string) AdminSessions::$lastRememberToken;
Database::run('UPDATE admin_sessions SET last_seen = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE remember_hash = :h', ['h' => hash('sha256', $t)]);
check('staff_idle_lock_min = 0: never locked', $resumeWith($t) && Auth::lockedSession() === null);
memSetting('staff_idle_lock_min', '30', 'string');

$t = (string) AdminSessions::$lastRememberToken;
Auth::relock();
Auth::adminLogout();
check('Sign out from the locked screen ends the device', $resumeWith($t) === false);

/* ------------------------------------------------------------------ */
section('Shapes');
$guard = (string) file_get_contents(dirname(__DIR__) . '/admin/_guard.php');
check('_guard.php: field session -> relock, others -> logout', str_contains($guard, "'/admin/login.php?relock=1'") && str_contains($guard, "\$idleGo = '/admin/logout.php'")
    && str_contains($guard, "['rmf']"));
$lp = (string) file_get_contents(dirname(__DIR__) . '/admin/login.php');
check('login.php: relock screen posts mode=relock with CSRF and offers Sign out', str_contains($lp, 'Auth::unlockSession(') && str_contains($lp, 'name="mode" value="relock"')
    && str_contains($lp, 'href="/admin/logout.php"'));

/* ------------------------------------------------------------------ */
section('customer_sessions housekeeping');
if (CustomerProfile::rememberReady()) {
    $ins = static function (string $tag, string $ended, string $expires): void {
        Database::insert('customer_sessions', [
            'user_id' => 0, 'token_hash' => hash('sha256', 'zzu7f-' . $tag . microtime()), 'device_hash' => null, 'label' => 'ZZTEST u7f',
            'via' => 'otp', 'created_at' => date('Y-m-d H:i:s', time() - 200 * 86400), 'last_seen' => date('Y-m-d H:i:s'),
            'expires_at' => $expires, 'ended_at' => $ended !== '' ? $ended : null,
        ]);
    };
    $ins('old-ended', date('Y-m-d H:i:s', time() - 120 * 86400), date('Y-m-d H:i:s', time() + 200 * 86400));
    $ins('old-expired', '', date('Y-m-d H:i:s', time() - 120 * 86400));
    $ins('recent-ended', date('Y-m-d H:i:s', time() - 5 * 86400), date('Y-m-d H:i:s', time() + 200 * 86400));
    $ins('live', '', date('Y-m-d H:i:s', time() + 200 * 86400));
    CustomerProfile::prune(90);
    $left = (int) Database::scalar("SELECT COUNT(*) FROM customer_sessions WHERE label = 'ZZTEST u7f'", [], 0);
    check('prune drops ended / expired rows older than 90 days, keeps recent and live ones', $left === 2, "left $left");
} else {
    check('customer_sessions table present', false);
}

gone();
unset($_COOKIE[AdminSessions::REMEMBER_COOKIE]);
echo "\n" . ($FAIL === 0 ? "\033[32mALL PASSED\033[0m" : "\033[31mFAILED: $FAIL\033[0m") . " ($PASS passed)\n";
exit($FAIL === 0 ? 0 : 1);
