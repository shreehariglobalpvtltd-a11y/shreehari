<?php
/**
 * =====================================================================
 *  office-reset-test.php — 14 Nov 2026 (owner: "OTP aayena").
 *
 *  A WhatsApp reset code for an office account is allowed ONLY for the
 *  owner's / CEO's own number (Settings admin_whatsapp, ceo_whatsapp),
 *  ONLY for superadmin / manager, and ONLY while office_reset_by_whatsapp
 *  is on. Everything else stays closed exactly as before.
 *
 *  Local test database. Makes three throw-away admin rows and removes
 *  them; puts back the three settings it changes. Sends nothing.
 *
 *    php tests/office-reset-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
chdir(dirname(__DIR__));
require 'includes/bootstrap.php';
require_once INCLUDE_PATH . '/teamportal.php';

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
};
if (!str_contains((string) DB_NAME, 'test')) { exit("Refusing to run: DB_NAME is not a test database.\n"); }

$OWNER = '9811100001'; $CEO = '9811100002'; $OTHER = '9811100003';
$old = [];
foreach (['admin_whatsapp', 'ceo_whatsapp', 'office_reset_by_whatsapp'] as $k) { $old[$k] = Settings::getString($k, ''); }
$tpl = Database::fetch("SELECT * FROM admins WHERE role = 'superadmin' ORDER BY id LIMIT 1");
if ($tpl === null) { exit("No superadmin row to copy in the test database.\n"); }
$cols = array_keys($tpl);
$mk = static function (string $user, string $role, string $phone) use ($tpl): int {
    Database::run('DELETE FROM admins WHERE username = :u', ['u' => $user]);
    $row = $tpl; unset($row['id']);
    $row['username'] = $user; $row['full_name'] = 'Reset test ' . $role; $row['role'] = $role; $row['phone'] = $phone;
    $row['is_active'] = 1; $row['password_hash'] = password_hash('x' . bin2hex(random_bytes(6)), PASSWORD_DEFAULT);
    if (array_key_exists('email', $row)) { $row['email'] = null; }
    $id = (int) Database::insert('admins', $row);
    // the reset code goes to the number WITH its country code, read from the profile
    Database::run('DELETE FROM admin_profiles WHERE admin_id = :a', ['a' => $id]);
    Database::insert('admin_profiles', ['admin_id' => $id, 'country_code' => '91']);
    return $id;
};
$ids = [
    'owner' => $mk('qa-reset-owner', 'superadmin', $OWNER),
    'ceo'   => $mk('qa-reset-ceo', 'manager', $CEO),
    'other' => $mk('qa-reset-other', 'superadmin', $OTHER),
];
$targets = static function (string $phone, bool $any): int {
    $m = new ReflectionMethod('TeamPortal', 'resetTargets');
    $m->setAccessible(true);
    $n = 0;
    foreach ($m->invoke(null, $phone, $any) as $g) { $n += count($g['admins']); }
    return $n;
};
$row = static fn(string $role): array => ['id' => 0, 'role' => $role];

try {
    Settings::set('admin_whatsapp', '91' . $OWNER, 'string', 'whatsapp');
    Settings::set('ceo_whatsapp', '+91 ' . substr($CEO, 0, 5) . ' ' . substr($CEO, 5), 'string', 'whatsapp');

    echo "\n1. Switch off (the default): exactly as before\n";
    Settings::set('office_reset_by_whatsapp', '0', 'bool', 'security');
    $ok(TeamPortal::officeResetAllowed($OWNER, $row('superadmin')) === false, 'owner number + superadmin: not allowed while the switch is off');
    $ok($targets($OWNER, true) === 0 && $targets($CEO, true) === 0, 'no office account gets a reset code');

    echo "\n2. Switch on: only the owner's and the CEO's own number\n";
    Settings::set('office_reset_by_whatsapp', '1', 'bool', 'security');
    $ok(TeamPortal::officeResetAllowed($OWNER, $row('superadmin')) === true, 'owner number + superadmin: allowed');
    $ok(TeamPortal::officeResetAllowed($CEO, $row('manager')) === true, 'CEO number (typed with +91 and spaces in Settings) + manager: allowed');
    $ok(TeamPortal::officeResetAllowed('91' . $OWNER, $row('superadmin')) === true, 'the same number with its country code still matches');
    $ok(TeamPortal::officeResetAllowed($OTHER, $row('superadmin')) === false, 'a superadmin on any OTHER number: still closed');
    $ok(TeamPortal::officeResetAllowed($OWNER, $row('accountant')) === false && TeamPortal::officeResetAllowed($OWNER, $row('support')) === false, 'other office roles on the owner number: still closed');
    $ok(TeamPortal::officeResetAllowed('', $row('superadmin')) === false && TeamPortal::officeResetAllowed('12345', $row('superadmin')) === false, 'an empty or short number never matches');
    $ok($targets($OWNER, true) === 1, 'the reset flow now finds the owner account on the owner number');
    $ok($targets($CEO, true) === 1, 'and the CEO account on the CEO number');
    $ok($targets($OTHER, true) === 0, 'and nothing for the other superadmin');
    $ok($targets($OWNER, false) === 0, 'the /team/ reset list (not the one door) is unchanged: no office account');

    echo "\n3. An empty Settings number opens nothing\n";
    Settings::set('admin_whatsapp', '', 'string', 'whatsapp');
    $ok(TeamPortal::officeResetAllowed($OWNER, $row('superadmin')) === false && $targets($OWNER, true) === 0, 'owner number removed from Settings: closed again');

    echo "\n4. The passwordless code door is untouched\n";
    $ok(Auth::OTP_DOOR_ROLES === ['agent', 'counter', 'scanner'], 'OTP_DOOR_ROLES still holds member roles only');
    $src = (string) file_get_contents('includes/auth.php');
    $ok(!str_contains($src, 'officeResetAllowed'), 'Auth (sign-in without a password) does not use the exception');
} finally {
    foreach ($ids as $id) { Database::run('DELETE FROM admin_profiles WHERE admin_id = :i', ['i' => $id]); Database::run('DELETE FROM admins WHERE id = :i', ['i' => $id]); }
    foreach ($old as $k => $v) {
        Settings::set($k, $k === 'office_reset_by_whatsapp' ? ($v === '1' ? '1' : '0') : $v, $k === 'office_reset_by_whatsapp' ? 'bool' : 'string', $k === 'office_reset_by_whatsapp' ? 'security' : 'whatsapp');
    }
}

echo "\n" . ($fail === 0 ? "ALL $pass PASSED" : "$fail FAILED, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
