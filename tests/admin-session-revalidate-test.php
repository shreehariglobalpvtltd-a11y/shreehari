<?php
/**
 * admin-session-revalidate-test.php — a staff session follows the admins row.
 *
 * Until 24 Sep 2026 role, permissions, is_active and must_change_pw were
 * copied into the session at sign-in and never read again, so deactivating
 * a staff member (or demoting a manager to counter) changed nothing until
 * they signed out by themselves. Auth::admin() now re-reads the row at most
 * once every 30 s for any session that carries the checked_at stamp a real
 * sign-in leaves. Sessions built by hand (every other suite) carry no stamp
 * and are untouched — pinned here as well, so a future change cannot make
 * the whole battery depend on live admin rows.
 *
 *     php tests/admin-session-revalidate-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$PASS = 0; $FAIL = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

const U = 'reval-probe';
Database::delete('admins', 'username = :u', ['u' => U]);
$id = (int) Database::insert('admins', [
    'username'      => U,
    'password_hash' => password_hash('Probe@12345', PASSWORD_BCRYPT),
    'full_name'     => 'Revalidation Probe',
    'email'         => 'reval@test.local',
    'phone'         => '+919000000777',
    'role'          => 'manager',
    'permissions'   => '[]',
    'is_active'     => 1,
    'must_change_pw'=> 0,
    'created_at'    => date('Y-m-d H:i:s'),
]);
check('a probe manager exists', $id > 0, "id $id");

/** A session shaped exactly like Auth::finishAdminSession() leaves it. */
$stamped = static function (int $checkedAgo) use ($id): void {
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => $id, 'username' => U, 'full_name' => 'Revalidation Probe',
        'role' => 'manager', 'permissions' => [], 'must_change_pw' => false,
        'via_otp' => false, 'logged_in_at' => time(), 'last_seen' => time(),
        'checked_at' => time() - $checkedAgo,
    ];
};

echo "\n== A. a fresh stamp is trusted (no query storm) ==\n";
$stamped(5);
Database::update('admins', ['role' => 'support'], 'id = :id', ['id' => $id]);
$a = Auth::admin();
check('within 30 s the session role is still what sign-in wrote', ($a['role'] ?? '') === 'manager', (string) ($a['role'] ?? 'null'));

echo "\n== B. after 30 s the row wins ==\n";
$stamped(31);
$a = Auth::admin();
check('the demotion is picked up', ($a['role'] ?? '') === 'support', (string) ($a['role'] ?? 'null'));
check('the stamp is renewed', (time() - (int) ($_SESSION[ADMIN_SESSION_KEY]['checked_at'] ?? 0)) < 5);
check('can() answers for the NEW role (support has no schedules.manage)', !Auth::can('schedules.manage'));

echo "\n== C. permissions and must_change_pw follow too ==\n";
Database::update('admins', ['permissions' => json_encode(['schedules.manage']), 'must_change_pw' => 1], 'id = :id', ['id' => $id]);
$stamped(31);
$a = Auth::admin();
check('an extra permission granted in staff.php is live', Auth::can('schedules.manage'));
check('must_change_pw raised by the office is live', ($a['must_change_pw'] ?? false) === true);

echo "\n== D. deactivated → signed out on the next request ==\n";
Database::update('admins', ['is_active' => 0, 'must_change_pw' => 0], 'id = :id', ['id' => $id]);
$stamped(31);
check('Auth::admin() is null for a deactivated account', Auth::admin() === null);
check('the session entry is gone', !isset($_SESSION[ADMIN_SESSION_KEY]));
Database::update('admins', ['is_active' => 1], 'id = :id', ['id' => $id]);

echo "\n== E. locked → signed out; lock expired → allowed ==\n";
Database::update('admins', ['locked_until' => date('Y-m-d H:i:s', time() + 600)], 'id = :id', ['id' => $id]);
$stamped(31);
check('a locked account is signed out', Auth::admin() === null);
Database::update('admins', ['locked_until' => date('Y-m-d H:i:s', time() - 600)], 'id = :id', ['id' => $id]);
$stamped(31);
check('an expired lock is not a lock', Auth::admin() !== null);

echo "\n== F. a deleted row → signed out ==\n";
$stamped(31);
Database::delete('admins', 'id = :id', ['id' => $id]);
check('a deleted account is signed out', Auth::admin() === null);

echo "\n== G. hand-built sessions (no stamp) are left alone ==\n";
$_SESSION[ADMIN_SESSION_KEY] = ['id' => 999999, 'username' => 'ghost', 'role' => 'counter', 'permissions' => [], 'last_seen' => time()];
$a = Auth::admin();
check('no checked_at → no revalidation, session kept as written', ($a['role'] ?? '') === 'counter' && (int) ($a['id'] ?? 0) === 999999);
unset($_SESSION[ADMIN_SESSION_KEY]);

echo "\n== H. canManageSettings ==\n";
$_SESSION[ADMIN_SESSION_KEY] = ['id' => 999998, 'username' => 'sup', 'role' => 'support', 'permissions' => [], 'last_seen' => time()];
check('support may not manage settings', !Auth::canManageSettings());
$_SESSION[ADMIN_SESSION_KEY]['role'] = 'manager';
check('a manager may (settings.manage in the role table)', Auth::canManageSettings());
$_SESSION[ADMIN_SESSION_KEY]['role'] = 'accountant';
$_SESSION[ADMIN_SESSION_KEY]['permissions'] = ['settings.manage'];
check('an explicit grant works for any role', Auth::canManageSettings());
$_SESSION[ADMIN_SESSION_KEY] = ['id' => 999997, 'username' => 'boss', 'role' => 'superadmin', 'permissions' => [], 'last_seen' => time()];
check('the owner always may', Auth::canManageSettings());
unset($_SESSION[ADMIN_SESSION_KEY]);

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
