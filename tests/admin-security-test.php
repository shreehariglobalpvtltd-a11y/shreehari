<?php
/**
 * Login history + device tracking — security regression test.
 *
 * The gap this closes: successful sign-ins reached audit_logs, failed ones
 * went to app_logs, so the security view could show a sign-in but never the
 * failures immediately before it. These assertions pin that EVERY outcome is
 * recorded, and that a device is only "new" when it genuinely is.
 *
 *   php -c .claude/php-dev.ini tests/admin-security-test.php
 *
 * Drives the real Auth::adminLogin() rather than re-implementing it, so the
 * assertions cover the code that actually runs. Writes only throwaway rows
 * and cleans up after itself. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

// Auth::adminLogin() calls session_regenerate_id(), which refuses once
// headers are "sent" — and under the CLI SAPI the first echo counts as that.
// Buffer everything and flush at the very end so the real sign-in path can
// run here exactly as it does over HTTP.
ob_start();

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}

const S_USER = 'testsec-admin';
const S_PW   = 'sectest-pw-9xk2';

/** Latest recorded outcome for our test user. */
function lastOutcome(): string {
    $r = Database::fetch(
        'SELECT outcome FROM admin_login_events WHERE username = :u ORDER BY id DESC LIMIT 1',
        ['u' => S_USER]
    );
    return (string) ($r['outcome'] ?? '');
}

/** Pretend this request arrives from a given browser. */
function useDevice(string $seed): void {
    $_COOKIE[LoginLog::DEVICE_COOKIE] = hash('sha256', $seed);   // 64 hex chars, the shape deviceToken() accepts
}

$adminId = 0;

try {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

    // Rate limiting would refuse the repeated attempts below on the second
    // pass of the day, so clear this IP's counters first.
    try { Database::delete('rate_limits', "bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}

    Database::delete('admins', 'username = :u', ['u' => S_USER]);
    $adminId = Database::insert('admins', [
        'username'       => S_USER,
        'password_hash'  => password_hash(S_PW, PASSWORD_DEFAULT),
        'full_name'      => 'Security Test Admin',
        'role'           => 'manager',
        'is_active'      => 1,
        'must_change_pw' => 0,
    ]);
    Database::delete('admin_login_events', 'username = :u', ['u' => S_USER]);

    useDevice('device-one');

    // ---- Failures are recorded, which they never used to be --------------
    $r = Auth::adminLogin(S_USER, 'definitely-not-the-password');
    check('a wrong password is refused',        ($r['ok'] ?? true) === false);
    check('a wrong password is RECORDED',       lastOutcome() === 'bad_password');

    $r = Auth::adminLogin('no-such-account-here', 'whatever');
    check('an unknown username is refused',     ($r['ok'] ?? true) === false);
    check('an unknown username is recorded',
        (string) (Database::fetch(
            'SELECT outcome FROM admin_login_events WHERE username = :u ORDER BY id DESC LIMIT 1',
            ['u' => 'no-such-account-here']
        )['outcome'] ?? '') === 'unknown_user');

    check('failures are countable per username', LoginLog::recentFailures(S_USER) === 1);

    // ---- Success, and the device is new ----------------------------------
    $r = Auth::adminLogin(S_USER, S_PW);
    check('the right password signs in',        ($r['ok'] ?? false) === true);
    check('the success is recorded',            lastOutcome() === 'success');
    check('the first sign-in flags a NEW device',
        ($_SESSION[ADMIN_SESSION_KEY]['new_device'] ?? null) === true);

    $devices = LoginLog::devices($adminId);
    check('the device was stored',              count($devices) === 1);
    check('the raw cookie is NOT stored',
        $devices !== [] && (string) $devices[0]['device_hash'] !== $_COOKIE[LoginLog::DEVICE_COOKIE]);
    check('the device carries a readable label',
        $devices !== [] && (string) $devices[0]['label'] !== '');

    // ---- Same device again is not new -------------------------------------
    Auth::adminLogout();
    check('signing out is recorded',            lastOutcome() === 'logout');

    Auth::adminLogin(S_USER, S_PW);
    check('the SAME device is not flagged new',
        ($_SESSION[ADMIN_SESSION_KEY]['new_device'] ?? null) === false);
    $devices = LoginLog::devices($adminId);
    check('the device was not duplicated',      count($devices) === 1);
    check('its sign-in count went up',          (int) $devices[0]['sign_ins'] === 2);

    // ---- A different browser IS new ---------------------------------------
    useDevice('device-two');
    Auth::adminLogin(S_USER, S_PW);
    check('a different device IS flagged new',
        ($_SESSION[ADMIN_SESSION_KEY]['new_device'] ?? null) === true);
    check('both devices are now listed',        count(LoginLog::devices($adminId)) === 2);

    // ---- Revoking makes a device count as new again -----------------------
    // Revoking is how you say "I don't recognise that browser", so it must
    // not silently keep its history.
    $two = null;
    foreach (LoginLog::devices($adminId) as $d) {
        if ((string) $d['device_hash'] === hash('sha256', hash('sha256', 'device-two'))) { $two = $d; }
    }
    check('the current device is identifiable',
        $two !== null && LoginLog::isCurrentDevice((string) $two['device_hash']) === true);

    LoginLog::revokeDevice($adminId, (int) $two['id']);
    Auth::adminLogin(S_USER, S_PW);
    check('a revoked device is treated as new again',
        ($_SESSION[ADMIN_SESSION_KEY]['new_device'] ?? null) === true);

    // ---- A throttled IP is recorded too ------------------------------------
    // The attempts above have used up this IP's allowance, which is what the
    // next assertion depends on.
    $r = Auth::adminLogin(S_USER, S_PW);
    check('a throttled IP is refused',          ($r['ok'] ?? true) === false);
    check('the throttled attempt is recorded',  lastOutcome() === 'locked');

    // ---- A disabled account is refused, and recorded as such --------------
    Auth::adminLogout();
    Database::delete('rate_limits', "bucket LIKE 'admin_login%'");   // past the throttle
    Database::update('admins', ['is_active' => 0], 'id = :i', ['i' => $adminId]);
    $r = Auth::adminLogin(S_USER, S_PW);
    check('a disabled account cannot sign in',  ($r['ok'] ?? true) === false);
    check('the disabled attempt is recorded',   lastOutcome() === 'disabled');

    // ---- History reads back newest first ----------------------------------
    $hist = LoginLog::history($adminId, 50);
    check('history is returned',                $hist !== []);
    check('history is newest first',
        count($hist) > 1 && (int) $hist[0]['id'] > (int) $hist[1]['id']);
    check('history joins the account name',
        $hist !== [] && (string) ($hist[0]['full_name'] ?? '') === 'Security Test Admin');
    check('history can be filtered to failures',
        LoginLog::history($adminId, 50, 'bad_password') !== []);

} catch (Throwable $e) {
    check('unexpected exception: ' . $e->getMessage(), false);
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    Database::delete('admin_login_events', 'username = :u', ['u' => S_USER]);
    Database::delete('admin_login_events', 'username = :u', ['u' => 'no-such-account-here']);
    if ($adminId > 0) {
        // admin_devices cascades off the admin row, but be explicit so a
        // failed run leaves nothing behind either.
        Database::delete('admin_devices', 'admin_id = :a', ['a' => $adminId]);
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $adminId]);
        Database::delete('admins', 'id = :a', ['a' => $adminId]);
    }
    try { Database::delete('rate_limits', "bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
}

echo "\n  $PASS passed, $FAIL failed\n\n";
ob_end_flush();
exit($FAIL > 0 ? 1 : 0);
