<?php
/**
 * Admin two-factor sign-in (Point 10) — opt-in, backward-compatible, no-lockout.
 *
 * Proves: default OFF leaves adminLogin() unchanged; enabling holds the login
 * at a pending step and issues a code; the correct code opens the session (as a
 * password login, not viaOtp); a wrong/expired step is refused; and the
 * availability guarantee — an account with 2FA on but no phone falls back to
 * password-only rather than locking out. Drives the REAL Auth methods.
 *   php -c .claude/php-dev.ini tests/admin-2fa-test.php
 * Throwaway admin; cleans up after itself. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';

// adminLogin()/finishAdminSession() call session_regenerate_id(); buffer so the
// first echo doesn't count as "headers sent" and refuse it (same as the
// admin-security suite).
ob_start();

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; } }
function expectThrow(string $l, callable $fn): void { try { $fn(); check($l . ' (expected rejection)', false); } catch (Throwable $e) { check($l . ' → "' . $e->getMessage() . '"', true); } }

const U  = 'test2fa-admin';
const PW = '2fa-test-pw-7Qm2';
const PHONE = '919812345678';

function clearThrottle(): void {
    try { Database::delete('rate_limits', "bucket LIKE 'admin_login%' OR bucket LIKE 'otp_send%' OR bucket LIKE 'admin_2fa%'"); } catch (Throwable $e) {}
}
function noSession(): void { unset($_SESSION[ADMIN_SESSION_KEY]); }
function sessionId(): int { return (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0); }

echo "\n=== Admin 2FA (opt-in second factor) ===\n\n";

$id = 0;
try {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    Database::delete('admins', 'username = :u', ['u' => U]);
    $id = Database::insert('admins', [
        'username' => U, 'password_hash' => password_hash(PW, PASSWORD_DEFAULT),
        'full_name' => '2FA Test', 'role' => 'manager', 'phone' => PHONE,
        'is_active' => 1, 'must_change_pw' => 0,
    ]);
    Auth::setAdmin2fa($id, false, 0); // ensure clean start
    Settings::flush();

    // ---- 1. Default OFF: login is exactly as before ----
    clearThrottle(); noSession();
    check('2FA reports OFF by default', Auth::admin2faEnabled($id) === false);
    $r = Auth::adminLogin(U, PW);
    check('password login succeeds with 2FA off', ($r['ok'] ?? false) === true);
    check('  session opened normally', sessionId() === $id);
    check('  no twofa flag returned', empty($r['twofa']));

    // ---- 2. Enable requires a phone; enabling flips the flag ----
    Auth::setAdmin2fa($id, true, $id); Settings::flush();
    check('2FA now reports ON', Auth::admin2faEnabled($id) === true);

    // ---- 3. With 2FA on, password alone does NOT open a session ----
    clearThrottle(); noSession();
    $r = Auth::adminLogin(U, PW);
    check('login held for second factor (twofa=true)', ($r['twofa'] ?? false) === true && empty($r['ok']));
    check('  NO session opened on password step', sessionId() === 0);
    check('  pending marker holds this admin', (int) ($_SESSION['admin_2fa_pending']['id'] ?? 0) === $id);

    // ---- 4. Correct code opens the session (as a password login) ----
    clearThrottle();
    $_SESSION['admin_2fa_pending'] = ['id' => $id, 'ip' => '', 'at' => time()];
    $iss = Auth::issueOtp('admin:' . $id, 'login', 'whatsapp');
    check('a code can be issued for the admin identifier', ($iss['ok'] ?? false) === true && !empty($iss['code']));
    noSession();
    $v = Auth::adminLogin2faVerify((string) $iss['code']);
    check('correct second-factor code signs in', ($v['ok'] ?? false) === true);
    check('  session now open for the right admin', sessionId() === $id);
    check('  NOT flagged via_otp (password gate still applies)', ($_SESSION[ADMIN_SESSION_KEY]['via_otp'] ?? true) === false);
    check('  pending marker consumed', empty($_SESSION['admin_2fa_pending']));

    // ---- 5. Wrong code is refused and keeps the step ----
    clearThrottle(); noSession();
    $_SESSION['admin_2fa_pending'] = ['id' => $id, 'ip' => '', 'at' => time()];
    Auth::issueOtp('admin:' . $id, 'login', 'whatsapp');
    $v = Auth::adminLogin2faVerify('000000');
    check('a wrong code is refused', ($v['ok'] ?? true) === false && ($v['twofa'] ?? false) === true);
    check('  no session opened on a wrong code', sessionId() === 0);

    // ---- 6. Expired pending step is refused ----
    clearThrottle(); noSession();
    $_SESSION['admin_2fa_pending'] = ['id' => $id, 'ip' => '', 'at' => time() - 700];
    $v = Auth::adminLogin2faVerify('123456');
    check('an expired sign-in step is refused', ($v['ok'] ?? true) === false);
    check('  and expired pending is cleared', empty($_SESSION['admin_2fa_pending']));

    // ---- 7. Availability: 2FA on but NO phone → password-only, never locked out ----
    clearThrottle(); noSession();
    Database::update('admins', ['phone' => null], 'id = :id', ['id' => $id]);
    $r = Auth::adminLogin(U, PW);
    check('2FA-on but no phone falls back to password-only (no lockout)', ($r['ok'] ?? false) === true && empty($r['twofa']));
    check('  session opened via the fallback', sessionId() === $id);
    $audited = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action='admin.2fa.no_phone' AND entity_id=:e", ['e' => (string) $id], 0);
    check('  the fallback is audited', $audited >= 1);

    // ---- 8. Enabling without a phone is refused ----
    Auth::setAdmin2fa($id, false, 0); Settings::flush();
    expectThrow('cannot enable 2FA without a phone on file',
        fn() => Auth::setAdmin2fa($id, true, $id));

    // ---- 9. Disable → login returns to plain password ----
    Database::update('admins', ['phone' => PHONE], 'id = :id', ['id' => $id]);
    Auth::setAdmin2fa($id, false, $id); Settings::flush();
    clearThrottle(); noSession();
    $r = Auth::adminLogin(U, PW);
    check('after disabling, plain password signs in again', ($r['ok'] ?? false) === true && empty($r['twofa']));

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    if ($id > 0) {
        try { Auth::setAdmin2fa($id, false, 0); } catch (Throwable $e) {}
        Database::delete('otp_codes', "identifier = :i", ['i' => 'admin:' . $id]);
        Database::delete('audit_logs', "entity_type='admin' AND entity_id = :e AND action LIKE 'admin.2fa%'", ['e' => (string) $id]);
        Database::delete('admins', 'id = :id', ['id' => $id]);
    }
    clearThrottle();
    unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION['admin_2fa_pending']);
    ob_end_flush(); // flush the buffered PASS/FAIL lines (buffer existed so session_regenerate_id could run)
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
