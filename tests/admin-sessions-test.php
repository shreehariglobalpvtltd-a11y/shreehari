<?php
/**
 * Sessions registry + permission matrix — regression test (29 Oct 2026).
 *
 *   php -c .claude/php-dev.ini tests/admin-sessions-test.php
 *
 * Pins: a real sign-in registers its browser; the 30-second re-check keeps
 * it open and signs it out once the office ends it; a session from before
 * the registry is registered, not signed out; the role matrix override adds
 * and removes a permission through Auth::can(); every permission a role has
 * in code appears in the catalogue the matrix shows. Writes only throwaway
 * rows and cleans up after itself. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
ob_start();

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}

const T_USER = 'testsess-counter';
const T_PW   = 'sesstest-pw-4qz8';

/** Make the next Auth::admin() call run its database re-check. */
function forceRecheck(): void {
    if (isset($_SESSION[ADMIN_SESSION_KEY])) {
        $_SESSION[ADMIN_SESSION_KEY]['checked_at'] = time() - 120;
    }
}

$adminId = 0;
$overrideBefore = Settings::get('role_permissions_override', null);

try {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    try { Database::delete('rate_limits', "bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}

    Database::delete('admins', 'username = :u', ['u' => T_USER]);
    $adminId = Database::insert('admins', [
        'username'       => T_USER,
        'password_hash'  => password_hash(T_PW, PASSWORD_DEFAULT),
        'full_name'      => 'Session Test Counter',
        'role'           => 'counter',
        'is_active'      => 1,
        'must_change_pw' => 0,
    ]);
    $_COOKIE[LoginLog::DEVICE_COOKIE] = hash('sha256', 'sess-device');

    // ---- the registry --------------------------------------------------
    $r = Auth::adminLogin(T_USER, T_PW);
    check('sign-in succeeds', ($r['ok'] ?? false) === true);
    $sk = (string) ($_SESSION[ADMIN_SESSION_KEY]['sk'] ?? '');
    check('the session carries a registry token', strlen($sk) === 48);
    $live = AdminSessions::active($adminId);
    check('the browser is listed as live', count($live) === 1);
    check('the row is recognised as this browser', $live !== [] && AdminSessions::isCurrent($live[0]));
    check('only the hash of the token is stored', $live !== [] && (string) $live[0]['token_hash'] !== $sk);

    forceRecheck();
    check('the re-check keeps an open session', Auth::admin() !== null);

    AdminSessions::end((int) $live[0]['id'], 1, 'test');
    forceRecheck();
    check('an ended session is signed out on its re-check', Auth::admin() === null);
    check('it is no longer listed', AdminSessions::active($adminId) === []);

    // A session from before the registry (no token) is adopted, not ended.
    $r = Auth::adminLogin(T_USER, T_PW);
    unset($_SESSION[ADMIN_SESSION_KEY]['sk']);
    forceRecheck();
    check('a pre-registry session survives its re-check', Auth::admin() !== null);
    check('…and is registered with a fresh token', strlen((string) ($_SESSION[ADMIN_SESSION_KEY]['sk'] ?? '')) === 48);

    $n = AdminSessions::endAll($adminId, 1, 'test all');
    check('sign out everywhere ends the open browsers', $n >= 1 && AdminSessions::active($adminId) === []);
    forceRecheck();
    check('…and this one is signed out', Auth::admin() === null);

    // ---- the permission matrix ----------------------------------------
    $r = Auth::adminLogin(T_USER, T_PW);
    check('counter can view bookings by default', Auth::can('bookings.view') === true);
    check('counter cannot see reports by default', Auth::can('reports.view') === false);

    Settings::set('role_permissions_override',
        ['counter' => ['reports.view' => true, 'bookings.view' => false, 'made.up' => true]], 'json', 'security');
    $ref = new ReflectionProperty(Settings::class, 'cache');
    $ref->setValue(null, null);
    $dec = new ReflectionProperty(Settings::class, 'decoded');
    $dec->setValue(null, []);
    check('a ticked box grants the permission', Auth::can('reports.view') === true);
    check('an unticked box removes it', Auth::can('bookings.view') === false);
    check('an unknown permission in the setting is ignored', Auth::can('made.up') === false);
    check('other roles are untouched', in_array('bookings.view', Auth::rolePermissions('agent'), true));

    // Every permission a role holds in code is in the catalogue.
    $rp = new ReflectionClassConstant(Auth::class, 'ROLE_PERMISSIONS');
    $missing = [];
    foreach ($rp->getValue() as $perms) {
        foreach ($perms as $p) {
            if (!isset(Auth::permissionList()[$p])) {
                $missing[] = $p;
            }
        }
    }
    check('the catalogue covers every role permission' . ($missing ? ' (missing: ' . implode(', ', array_unique($missing)) . ')' : ''), $missing === []);
} catch (Throwable $e) {
    check('unexpected exception: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    if ($overrideBefore === null) {
        Database::delete('settings', 'skey = :k', ['k' => 'role_permissions_override']);
    } else {
        Database::update('settings', ['svalue' => is_string($overrideBefore) ? $overrideBefore : json_encode($overrideBefore)],
            'skey = :k', ['k' => 'role_permissions_override']);
    }
    if ($adminId > 0) {
        try { Database::delete('admin_sessions', 'admin_id = :a', ['a' => $adminId]); } catch (Throwable $e) {}
        Database::delete('admin_login_events', 'username = :u', ['u' => T_USER]);
        Database::delete('admin_devices', 'admin_id = :a', ['a' => $adminId]);
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $adminId]);
        Database::delete('admins', 'id = :a', ['a' => $adminId]);
    }
    try { Database::delete('rate_limits', "bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
}

echo "\n  $PASS passed, $FAIL failed\n\n";
ob_end_flush();
exit($FAIL > 0 ? 1 : 0);
