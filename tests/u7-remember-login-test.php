<?php
/**
 * =====================================================================
 *  u7-remember-login-test.php — "stay signed in on this device" for staff,
 *  the office phone as a login ID, and the mobile-first switch (19 Nov 2026,
 *  LG-1).
 *
 *  Pins (local shari_test, throwaway accounts 'zzu7-*' deleted before and
 *  after; settings are changed in memory only, never in the shared table):
 *    - a field-role sign-in is remembered for login_remember_days; an office
 *      role for login_remember_office_hours; a viewer never; days = 0 = never;
 *    - only the SHA-256 of the token is stored; the cookie value is 64 hex;
 *    - resume() re-opens the same account via 'remember' after the session
 *      file is gone, and rotates the token; the old one works only inside the
 *      2-minute grace and, after it, ends the device;
 *    - refused (and the device ended): another shg_dev, office End, a password
 *      reset, a switched-off account, an expired window, Sign out;
 *    - the person's OWN password change keeps this device;
 *    - the idle timeout does not end a remembered session (it still ends an
 *      ordinary one); the device limit is not asked on resume;
 *    - Auth::adminLogin accepts the account's mobile when exactly one active
 *      account has it; a shared or unknown number fails like an unknown user;
 *    - CodeLogin::enter: an office mobile goes to the mobile step, a field
 *      mobile keeps the "type your code" hint;
 *    - login_mobile_first_on: CodeLogin::required() false, firstSend() needs
 *      a typed mobile.
 *
 *     php -c .claude/php-dev.ini tests/u7-remember-login-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/codelogin.php';
require_once INCLUDE_PATH . '/onedoor.php';

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

/** In-memory setting (the shared settings table is never written). */
function memSetting(string $key, string $value, string $type): void {
    $r = new ReflectionClass(Settings::class);
    $c = $r->getProperty('cache'); $c->setAccessible(true);
    $d = $r->getProperty('decoded'); $d->setAccessible(true);
    Settings::get('company_name');   // make sure the cache is loaded
    $cache = (array) $c->getValue();
    $cache[$key] = ['value' => $value, 'type' => $type, 'public' => false];
    $c->setValue(null, $cache);
    $dec = (array) $d->getValue();
    unset($dec[$key]);
    $d->setValue(null, $dec);
}
function clearLimits(): void {
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%' OR bucket LIKE 'code_login%' OR bucket LIKE 'team_login%' OR bucket = 'admin_remember_miss'");
}
function gone(): void {   // the server deleted the session file: a new, empty session
    $_SESSION = [];
}
const DEV_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const DEV_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const PW    = 'u7-Remember-pw-48z';

echo "\n=== u7: stay signed in (staff), phone as login ID, mobile-first ===\n\n";
if (!AdminSessions::rememberReady()) {
    echo "  SKIP  run database/upgrade-2026-11-19-u7-remember-login.sql first\n";
    exit(1);
}

$users = ['zzu7-agent', 'zzu7-mgr', 'zzu7-viewer', 'zzu7-twin1', 'zzu7-twin2'];
$cleanup = static function () use ($users): void {
    foreach ($users as $u) {
        $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
        if ($id > 0) {
            Database::run('DELETE FROM admin_sessions WHERE admin_id = :a', ['a' => $id]);
            Database::run('DELETE FROM admins WHERE id = :a', ['a' => $id]);
        }
    }
};
$cleanup();
register_shutdown_function($cleanup);

$mk = static function (string $u, string $role, string $phone) : int {
    return (int) Database::insert('admins', [
        'username' => $u, 'password_hash' => Security::hashPassword(PW), 'full_name' => 'ZZTEST u7 ' . $role,
        'phone' => $phone, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0,
    ]);
};
$aAgent = $mk('zzu7-agent', 'agent', '9000070001');
$aMgr   = $mk('zzu7-mgr', 'manager', '9000070002');
$aView  = $mk('zzu7-viewer', 'viewer', '9000070004');
$mk('zzu7-twin1', 'counter', '9000070003');
$mk('zzu7-twin2', 'counter', '9000070003');

memSetting('login_remember_days', '365', 'int');
memSetting('login_remember_office_hours', '24', 'int');
memSetting('login_remember_roles', '["agent","counter","scanner","support","operations","staff"]', 'json');
memSetting('login_phone_id_on', '1', 'bool');
memSetting('login_mobile_first_on', '0', 'bool');
foreach (['agent', 'counter', 'manager', 'viewer'] as $r) {
    memSetting('device_limit_' . $r, '0', 'int');
}
$_COOKIE[LoginLog::DEVICE_COOKIE] = DEV_A;

$login = static function (string $user): array {
    clearLimits();
    gone();
    unset($_COOKIE[AdminSessions::REMEMBER_COOKIE]);
    return Auth::adminLogin($user, PW);
};
$row = static function (): ?array {
    $t = (string) (AdminSessions::$lastRememberToken ?? '');
    return $t === '' ? null : Database::fetch('SELECT * FROM admin_sessions WHERE remember_hash = :h', ['h' => hash('sha256', $t)]);
};
$resumeWith = static function (?string $token): bool {
    gone();
    if ($token === null) {
        unset($_COOKIE[AdminSessions::REMEMBER_COOKIE]);
    } else {
        $_COOKIE[AdminSessions::REMEMBER_COOKIE] = $token;
    }
    return AdminSessions::resume();
};

/* ------------------------------------------------------------------ */
section('Minting');
$r = $login('zzu7-agent');
$tok = (string) AdminSessions::$lastRememberToken;
check('agent signs in', ($r['ok'] ?? false) === true && (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) === $aAgent);
check('a remember token is handed to the browser (64 hex)', preg_match('/^[a-f0-9]{64}$/', $tok) === 1);
check('…and set as the shg_rm cookie value', ($_COOKIE[AdminSessions::REMEMBER_COOKIE] ?? '') === $tok);
$rw = $row();
check('only its SHA-256 is stored, on the session\'s own row', $rw !== null && $rw['remember_hash'] !== $tok && (int) $rw['admin_id'] === $aAgent
    && AdminSessions::isCurrent($rw));
check('field role: about 365 days', (int) ($_SESSION[ADMIN_SESSION_KEY]['rm'] ?? 0) > time() + 364 * 86400);
check('the row is bound to this device', $rw !== null && hash_equals((string) $rw['device_hash'], hash('sha256', DEV_A)));

$r = $login('zzu7-mgr');
$rm = (int) ($_SESSION[ADMIN_SESSION_KEY]['rm'] ?? 0);
check('office role: about 24 hours', ($r['ok'] ?? false) && $rm > time() + 23 * 3600 && $rm < time() + 25 * 3600);
$r = $login('zzu7-viewer');
check('viewer: never remembered', ($r['ok'] ?? false) && AdminSessions::$lastRememberToken === null && empty($_SESSION[ADMIN_SESSION_KEY]['rm']));
memSetting('login_remember_days', '0', 'int');
$r = $login('zzu7-agent');
check('login_remember_days = 0: not remembered', ($r['ok'] ?? false) && AdminSessions::$lastRememberToken === null);
memSetting('login_remember_days', '365', 'int');
check('rememberWindow: a 2FA-free agent is a field role', AdminSessions::rememberWindow(['id' => $aAgent, 'role' => 'agent']) >= 364 * 86400);
check('rememberWindow: superadmin is an office role even if listed', (function () use ($aMgr) {
    memSetting('login_remember_roles', '["agent","superadmin"]', 'json');
    $w = AdminSessions::rememberWindow(['id' => $aMgr, 'role' => 'superadmin']);
    memSetting('login_remember_roles', '["agent","counter","scanner","support","operations","staff"]', 'json');
    return $w === 24 * 3600;
})());

/* ------------------------------------------------------------------ */
section('Resume + rotation');
$r = $login('zzu7-agent');
$t1 = (string) AdminSessions::$lastRememberToken;
$sk1 = (string) $_SESSION[ADMIN_SESSION_KEY]['sk'];
check('no cookie: nothing to resume', $resumeWith(null) === false && Auth::admin() === null);
check('a malformed cookie is refused', $resumeWith('not-a-token') === false && !isset($_COOKIE[AdminSessions::REMEMBER_COOKIE]));
check('an unknown token is refused', $resumeWith(str_repeat('c', 64)) === false);
$ok = $resumeWith($t1);
$me = Auth::admin();
check('session file gone + cookie: the same account is back', $ok && $me !== null && (int) $me['id'] === $aAgent);
check('…via "remember"', ($me['via'] ?? '') === 'remember' && ($me['via_otp'] ?? true) === false);
$t2 = (string) AdminSessions::$lastRememberToken;
check('…and the token was rotated', preg_match('/^[a-f0-9]{64}$/', $t2) === 1 && $t2 !== $t1 && ($_COOKIE[AdminSessions::REMEMBER_COOKIE] ?? '') === $t2);
check('…and the session key too (same registry row)', ($me['sk'] ?? '') !== '' && $me['sk'] !== $sk1
    && (int) Database::scalar('SELECT COUNT(*) FROM admin_sessions WHERE admin_id = :a AND ended_at IS NULL AND remember_hash IS NOT NULL', ['a' => $aAgent], 0) >= 1);
check('a resume is not a fresh sign-in (pin-setup proof)', (int) ($me['logged_in_at'] ?? time()) <= time() - 0 && ($me['new_device'] ?? true) === false);
check('the re-check keeps a resumed session', (function () { $_SESSION[ADMIN_SESSION_KEY]['checked_at'] = time() - 120; return Auth::admin() !== null; })());
check('no device-limit hold on resume', Auth::deviceHold() === null);
$ok = $resumeWith($t1);
check('the previous token still opens inside the 2-minute grace (a second tab)', $ok && (int) (Auth::admin()['id'] ?? 0) === $aAgent);
$rowNow = Database::fetch('SELECT * FROM admin_sessions WHERE remember_hash = :h', ['h' => hash('sha256', $t2)]);
Database::run('UPDATE admin_sessions SET remember_rotated_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = :i', ['i' => (int) $rowNow['id']]);
check('after the grace the old token is refused…', $resumeWith($t1) === false);
check('…and the device is ended (a copy is in use)', Database::scalar('SELECT ended_at FROM admin_sessions WHERE id = :i', ['i' => (int) $rowNow['id']], null) !== null);
check('…so the current token is refused too', $resumeWith($t2) === false);

/* ------------------------------------------------------------------ */
section('Revocation');
$fresh = static function () use ($login): string {
    $login('zzu7-agent');
    return (string) AdminSessions::$lastRememberToken;
};
$t = $fresh();
$_COOKIE[LoginLog::DEVICE_COOKIE] = DEV_B;
check('another device (shg_dev) is refused', $resumeWith($t) === false);
$_COOKIE[LoginLog::DEVICE_COOKIE] = DEV_A;
check('…and that ended the remembered row', $resumeWith($t) === false);

$t = $fresh();
$id = (int) Database::scalar('SELECT id FROM admin_sessions WHERE remember_hash = :h', ['h' => hash('sha256', $t)], 0);
AdminSessions::end($id, 1, 'test: office End');
check('office End (Sessions) refuses the device', $resumeWith($t) === false);

$t = $fresh();
AdminSessions::endAll($aAgent, 1, 'test: everywhere');
check('office "sign out everywhere" refuses it', $resumeWith($t) === false);

$t = $fresh();
Database::run('UPDATE admins SET password_hash = :h WHERE id = :i', ['h' => Security::hashPassword('another-pw-77x'), 'i' => $aAgent]);
check('a password reset by the office refuses it', $resumeWith($t) === false);
Database::run('UPDATE admins SET password_hash = :h WHERE id = :i', ['h' => Security::hashPassword(PW), 'i' => $aAgent]);

$t = $fresh();
Database::run('UPDATE admins SET is_active = 0 WHERE id = :i', ['i' => $aAgent]);
check('a switched-off account is refused', $resumeWith($t) === false);
Database::run('UPDATE admins SET is_active = 1 WHERE id = :i', ['i' => $aAgent]);

$t = $fresh();
Database::run('UPDATE admin_sessions SET remember_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE remember_hash = :h', ['h' => hash('sha256', $t)]);
check('an expired window is refused', $resumeWith($t) === false);

$t = $fresh();
memSetting('login_remember_roles', '["counter"]', 'json');
memSetting('login_remember_office_hours', '0', 'int');
check('a role taken off the list is refused', $resumeWith($t) === false);
memSetting('login_remember_roles', '["agent","counter","scanner","support","operations","staff"]', 'json');
memSetting('login_remember_office_hours', '24', 'int');

$t = $fresh();
Auth::adminLogout();
check('Sign out on this device expires the cookie', !isset($_COOKIE[AdminSessions::REMEMBER_COOKIE]));
check('…and the token no longer opens', $resumeWith($t) === false);

$t = $fresh();
$old = (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $aAgent], '');
Database::run('UPDATE admins SET password_hash = :h WHERE id = :i', ['h' => Security::hashPassword('own-new-pw-55q'), 'i' => $aAgent]);
Auth::passwordChanged($aAgent, $aAgent, $old);
check('own password change: this device stays remembered', $resumeWith($t) === true && (int) (Auth::admin()['id'] ?? 0) === $aAgent);
Database::run('UPDATE admins SET password_hash = :h WHERE id = :i', ['h' => Security::hashPassword(PW), 'i' => $aAgent]);

/* ------------------------------------------------------------------ */
section('Idle timeout');
$login('zzu7-agent');
$_SESSION[ADMIN_SESSION_KEY]['last_seen'] = time() - 6 * 3600;
check('a remembered session is not ended by the idle timeout', Auth::admin() !== null);
$login('zzu7-viewer');
$_SESSION[ADMIN_SESSION_KEY]['last_seen'] = time() - 6 * 3600;
check('an ordinary session still is', Auth::admin() === null);

/* ------------------------------------------------------------------ */
section('Office phone as the login ID');
clearLimits(); gone();
$r = Auth::adminLogin('9000070002', PW);
check('the one account on that mobile signs in with its password', ($r['ok'] ?? false) === true && (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) === $aMgr);
clearLimits(); gone();
$r = Auth::adminLogin('+91 90000 70002', 'wrong-password');
check('a wrong password on the phone fails generically', ($r['ok'] ?? true) === false && str_contains((string) $r['error'], 'Incorrect username or password'));
clearLimits(); gone();
$r = Auth::adminLogin('9000070003', PW);
check('a mobile shared by two accounts never picks one', ($r['ok'] ?? true) === false && str_contains((string) $r['error'], 'Incorrect username or password'));
clearLimits(); gone();
$r = Auth::adminLogin('9000079999', PW);
check('an unknown mobile fails like an unknown username', ($r['ok'] ?? true) === false && str_contains((string) $r['error'], 'Incorrect username or password'));
memSetting('login_phone_id_on', '0', 'bool');
clearLimits(); gone();
check('login_phone_id_on = 0 switches it off', (Auth::adminLogin('9000070002', PW)['ok'] ?? true) === false);
memSetting('login_phone_id_on', '1', 'bool');

clearLimits(); gone();
$r = CodeLogin::enter('9000070002');
check('code box: an office mobile goes to the mobile (password) step', ($r['step'] ?? '') === 'mobile' && !empty($r['classic'])
    && (OneDoor::state()['kind'] ?? '') === 'mobile' && (OneDoor::state()['phone'] ?? '') === '9000070002');
clearLimits(); gone();
$r = CodeLogin::enter('9000070001');
check('code box: a field-role mobile keeps the "type your code" hint', ($r['step'] ?? '') === 'c_code' && str_contains((string) ($r['error'] ?? ''), 'ID card'));
clearLimits(); gone();
$r = CodeLogin::enter('9000079999');
check('code box: an unknown mobile gets the same hint', ($r['step'] ?? '') === 'c_code' && str_contains((string) ($r['error'] ?? ''), 'ID card'));

/* ------------------------------------------------------------------ */
section('Mobile-first switch');
check('off (default): PIN still required by the code door', CodeLogin::required() === (CodeLogin::on() && Settings::getBool('login_pin_required', true)));
check('off: OneDoor::mobileFirstOn() false', OneDoor::mobileFirstOn() === false);
memSetting('login_mobile_first_on', '1', 'bool');
check('on: PIN / pattern optional (CodeLogin::required false)', CodeLogin::required() === false);
check('on: OneDoor::mobileFirstOn() true', OneDoor::mobileFirstOn() === Auth::oneDoorOn());
gone();
check('firstSend without a typed mobile asks for it (no code sent)', (OneDoor::firstSend()['step'] ?? '') === 'start');
memSetting('login_mobile_first_on', '0', 'bool');

/* ------------------------------------------------------------------ */
section('Security questions');
check('date of birth is no longer offered for a new setup', !isset(QuickUnlock::SEC_QUESTIONS_OFFERED['dob']) && count(QuickUnlock::SEC_QUESTIONS_OFFERED) === 3);
check('…but an answer saved with it still has its question', isset(QuickUnlock::SEC_QUESTIONS['dob']));

gone();
unset($_COOKIE[AdminSessions::REMEMBER_COOKIE]);
echo "\n" . ($FAIL === 0 ? "\033[32mALL PASSED\033[0m" : "\033[31mFAILED: $FAIL\033[0m") . " ($PASS passed)\n";
exit($FAIL === 0 ? 0 : 1);
