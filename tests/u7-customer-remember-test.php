<?php
/**
 * =====================================================================
 *  u7-customer-remember-test.php — passengers stay signed in (and verified)
 *  on their phone after a PROVEN door (19 Nov 2026, LG-1 / LG-2).
 *
 *  Pins (local shari_test, throwaway passengers 9000071xxx 'ZZTEST u7',
 *  deleted before and after; settings changed in memory only):
 *    - the name + mobile "quick" door never remembers; a proven door
 *      (CustomerProfile::markVerified) does, and only the SHA-256 is stored;
 *    - resume() brings back the passenger AND the "verified" mark, so
 *      Auth::customerMayOpen() opens their own booking again; the token rotates;
 *    - refused: another device, a blocked account, Sign out (Auth::logoutUser),
 *      another passenger signing in on the phone, customer_remember_days = 0;
 *    - the idle timeout does not end a remembered passenger;
 *    - bootstrap resumes a passenger only with no staff session;
 *    - the app shows one "Verify on WhatsApp" card after an app booking
 *      (never for a staff sale), prefilled with the number and country.
 *
 *     php -c .claude/php-dev.ini tests/u7-customer-remember-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
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
    Settings::get('company_name');
    $cache = (array) $c->getValue();
    $cache[$key] = ['value' => $value, 'type' => $type, 'public' => false];
    $c->setValue(null, $cache);
}
function gone(): void { $_SESSION = []; }

const P1 = '9000071001';
const P2 = '9000071002';
const DEV_A = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
const DEV_B = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';

echo "\n=== u7: passengers stay signed in after a proven door ===\n\n";
if (!CustomerProfile::rememberReady()) {
    echo "  SKIP  run database/upgrade-2026-11-19-u7-remember-login.sql first\n";
    exit(1);
}
$cleanup = static function (): void {
    foreach ([P1, P2] as $p) {
        $id = (int) Database::scalar('SELECT id FROM users WHERE phone = :p', ['p' => $p], 0);
        if ($id > 0) {
            Database::run('DELETE FROM customer_sessions WHERE user_id = :u', ['u' => $id]);
        }
        Database::run('DELETE FROM users WHERE phone = :p', ['p' => $p]);
        try { Database::run('DELETE FROM user_profiles WHERE phone = :p', ['p' => $p]); } catch (Throwable $e) {}
    }
};
$cleanup();
register_shutdown_function($cleanup);
memSetting('customer_remember_days', '365', 'int');
memSetting('session_timeout_min', '45', 'int');
$_COOKIE[LoginLog::DEVICE_COOKIE] = DEV_A;
unset($_COOKIE[CustomerProfile::REMEMBER_COOKIE]);

$resumeWith = static function (?string $t): bool {
    gone();
    if ($t === null) { unset($_COOKIE[CustomerProfile::REMEMBER_COOKIE]); } else { $_COOKIE[CustomerProfile::REMEMBER_COOKIE] = $t; }
    return CustomerProfile::resume();
};
$proven = static function (string $phone): array {
    gone();
    $u = Auth::loginUser($phone, 'ZZTEST u7 Passenger', 'NP');
    CustomerProfile::markVerified((int) $u['id'], '', [], 'otp');
    return $u;
};

/* ------------------------------------------------------------------ */
section('Minting');
gone();
$u = Auth::loginUser(P1, 'ZZTEST u7 Passenger', 'IN', false);
check('quick (name + mobile) door: not remembered', CustomerProfile::$lastRememberToken === null
    && (int) Database::scalar('SELECT COUNT(*) FROM customer_sessions WHERE user_id = :u', ['u' => (int) $u['id']], 0) === 0
    && !isset($_COOKIE[CustomerProfile::REMEMBER_COOKIE]));
$u = $proven(P1);
$uid = (int) $u['id'];
$t1 = (string) CustomerProfile::$lastRememberToken;
check('proven door: a 64-hex token in shg_crm', preg_match('/^[a-f0-9]{64}$/', $t1) === 1 && ($_COOKIE[CustomerProfile::REMEMBER_COOKIE] ?? '') === $t1);
$row = Database::fetch('SELECT * FROM customer_sessions WHERE token_hash = :h', ['h' => hash('sha256', $t1)]);
check('only its SHA-256 is stored, bound to this device', $row !== null && (int) $row['user_id'] === $uid
    && hash_equals((string) $row['device_hash'], hash('sha256', DEV_A)) && (string) $row['via'] === 'otp');
check('about 365 days', (int) ($_SESSION[USER_SESSION_KEY]['rm'] ?? 0) > time() + 364 * 86400);
memSetting('customer_remember_days', '0', 'int');
$proven(P1);
check('customer_remember_days = 0: not remembered', CustomerProfile::$lastRememberToken === null);
memSetting('customer_remember_days', '365', 'int');
$proven(P1);
$t1 = (string) CustomerProfile::$lastRememberToken;
check('a new proof replaces this phone\'s older row (one live row)',
    (int) Database::scalar('SELECT COUNT(*) FROM customer_sessions WHERE user_id = :u AND ended_at IS NULL', ['u' => $uid], 0) === 1);

/* ------------------------------------------------------------------ */
section('Resume');
check('no cookie: nothing', $resumeWith(null) === false && Auth::user() === null);
check('an unknown token: nothing', $resumeWith(str_repeat('d', 64)) === false);
$ok = $resumeWith($t1);
check('session gone + cookie: the passenger is back', $ok && (int) (Auth::user()['id'] ?? 0) === $uid);
check('…and still verified (the number was proven on this phone)', CustomerProfile::verifiedUserId() === $uid);
check('…so their own booking opens again', Auth::customerMayOpen(['pnr' => 'ZZU7-NONE', 'contact_phone' => P1, 'user_id' => $uid]));
$t2 = (string) CustomerProfile::$lastRememberToken;
check('the token rotated', preg_match('/^[a-f0-9]{64}$/', $t2) === 1 && $t2 !== $t1);
check('the old token opens inside the 2-minute grace', $resumeWith($t1) === true);
Database::run('UPDATE customer_sessions SET rotated_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE user_id = :u', ['u' => $uid]);
check('after the grace the old token is refused and the phone forgotten', $resumeWith($t1) === false && $resumeWith($t2) === false);

/* ------------------------------------------------------------------ */
section('Refusals');
$proven(P1); $t = (string) CustomerProfile::$lastRememberToken;
$_COOKIE[LoginLog::DEVICE_COOKIE] = DEV_B;
check('another device is refused', $resumeWith($t) === false);
$_COOKIE[LoginLog::DEVICE_COOKIE] = DEV_A;

$proven(P1); $t = (string) CustomerProfile::$lastRememberToken;
Database::run('UPDATE users SET is_blocked = 1 WHERE id = :u', ['u' => $uid]);
check('a blocked account is refused', $resumeWith($t) === false);
Database::run('UPDATE users SET is_blocked = 0 WHERE id = :u', ['u' => $uid]);

$proven(P1); $t = (string) CustomerProfile::$lastRememberToken;
Auth::logoutUser();
check('Sign out on this phone expires the cookie and the token', !isset($_COOKIE[CustomerProfile::REMEMBER_COOKIE]) && $resumeWith($t) === false);

$proven(P1); $t = (string) CustomerProfile::$lastRememberToken;
gone();
Auth::loginUser(P2, 'ZZTEST u7 Other', 'IN', false);
check('another passenger signing in on the phone ends the first one\'s remember', $resumeWith($t) === false);

$proven(P1); $t = (string) CustomerProfile::$lastRememberToken;
memSetting('customer_remember_days', '0', 'int');
check('switched off later: refused', $resumeWith($t) === false);
memSetting('customer_remember_days', '365', 'int');

/* ------------------------------------------------------------------ */
section('Idle timeout');
$proven(P1);
$_SESSION[USER_SESSION_KEY]['last_seen'] = time() - 6 * 3600;
check('a remembered passenger is not timed out', Auth::user() !== null);
gone();
Auth::loginUser(P2, 'ZZTEST u7 Other', 'IN', false);
$_SESSION[USER_SESSION_KEY]['last_seen'] = time() - 6 * 3600;
check('an ordinary passenger session still is', Auth::user() === null);

/* ------------------------------------------------------------------ */
section('Bootstrap + app (shape)');
$boot = (string) file_get_contents(INCLUDE_PATH . '/bootstrap.php');
check('bootstrap resumes staff first, a passenger only with no staff and no passenger session',
    str_contains($boot, 'AdminSessions::resume()') && str_contains($boot, 'CustomerProfile::resume()')
    && strpos($boot, 'AdminSessions::resume()') < strpos($boot, 'CustomerProfile::resume()')
    && str_contains($boot, "empty(\$_SESSION[ADMIN_SESSION_KEY]['id']) && empty(\$_SESSION[USER_SESSION_KEY]['id'])"));
$js = (string) file_get_contents(ROOT_PATH . '/assets/js/08-signin.js');
check('one "वाट्सएपमा पुष्टि गर्नुहोस् · Verify on WhatsApp" card, prefilled sign-in sheet',
    str_contains($js, 'वाट्सएपमा पुष्टि गर्नुहोस् · Verify on WhatsApp') && str_contains($js, 'window.shgVerifyNudge = shgVerifyNudge')
    && str_contains($js, "openLoginModal({ u: { phone: p") && str_contains($js, 'u.verified'));
$co = (string) file_get_contents(ROOT_PATH . '/assets/js/07-checkout.js');
check('checkout shows it after an app booking, never for a staff sale', str_contains($co, '!staffSale && typeof window.shgVerifyNudge'));

gone();
unset($_COOKIE[CustomerProfile::REMEMBER_COOKIE]);
echo "\n" . ($FAIL === 0 ? "\033[32mALL PASSED\033[0m" : "\033[31mFAILED: $FAIL\033[0m") . " ($PASS passed)\n";
exit($FAIL === 0 ? 0 : 1);
