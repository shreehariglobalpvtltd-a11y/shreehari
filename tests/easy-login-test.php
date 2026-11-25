<?php
/* Easy login (25 Nov 2026): mode -> code or mobile -> password; FirstName@2026 until the
   person changes it; office reset puts it back; superadmin never; drivers too.
   php -c .claude/php-dev.ini tests/easy-login-test.php */
define('SHG_APP', true);
require __DIR__ . '/../includes/bootstrap.php';
require_once INCLUDE_PATH . '/easylogin.php';
require_once INCLUDE_PATH . '/driverauth.php';
$ok = 0; $bad = 0;
$chk = function ($name, $cond) use (&$ok, &$bad) { if ($cond) { $ok++; echo "  PASS  $name\n"; } else { $bad++; echo "  FAIL  $name\n"; } };
$root = dirname(__DIR__);

$aid = 0; $did = 0; $sid = 0;
$phone = '98' . substr((string) (time() % 100000000 + 10000000), 0, 8);
try {
    /* names -> default */
    $chk('Ram Bahadur Thapa -> Ram@2026', EasyLogin::defaultFor('Ram Bahadur Thapa') === 'Ram@2026');
    $chk('lower / extra spaces -> capitalised', EasyLogin::defaultFor('  dalbir  bohara') === 'Dalbir@2026');
    $chk('punctuation dropped', EasyLogin::defaultFor('Dilip. Soni') === 'Dilip@2026');
    $chk('no name -> no default', EasyLogin::defaultFor('') === '');

    /* an agent-like account */
    $aid = (int) Database::insert('admins', ['username' => 'wxeasy_' . substr($phone, -6), 'full_name' => 'Wxeasy Tester', 'phone' => $phone,
        'role' => 'agent', 'is_active' => 1, 'password_hash' => Security::hashPassword('OwnSecret#9'), 'must_change_pw' => 0]);
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $aid]);
    $chk('default opens a fresh account (any case)', EasyLogin::adminPasswordOk($a, 'Wxeasy@2026') && EasyLogin::adminPasswordOk($a, 'wxeasy@2026'));
    $chk('own password opens too', EasyLogin::adminPasswordOk($a, 'OwnSecret#9'));
    $chk('wrong password refused', !EasyLogin::adminPasswordOk($a, 'Wxeasy@2025') && !EasyLogin::adminPasswordOk($a, ''));
    $chk('found by mobile', (int) (EasyLogin::findAdmin('agent', $phone)['id'] ?? 0) === $aid);
    $chk('found by username', (int) (EasyLogin::findAdmin('admin', (string) $a['username'])['id'] ?? 0) === $aid);

    /* a real agent code, if this DB has one: "SHG-0007" and "7" are the same person */
    require_once INCLUDE_PATH . '/agentwallet.php';
    $any = Database::fetch("SELECT id FROM admins WHERE role = 'agent' AND is_active = 1 AND id <> :i ORDER BY id LIMIT 30", ['i' => $aid]);
    $codeHit = false;
    foreach (Database::fetchAll("SELECT id FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY id LIMIT 60") as $r) {
        $lbl = AgentWallet::agentCodeLabel((int) $r['id']);
        if (preg_match('/^SHG-0*(\d+)$/', $lbl, $m) === 1) {
            $codeHit = true;
            $chk('agent code: bare number "' . $m[1] . '" = ' . $lbl, (int) (EasyLogin::findAdmin('agent', $m[1])['id'] ?? 0) === (int) $r['id']);
            $chk('agent code: "SHG' . $m[1] . '" = ' . $lbl, (int) (EasyLogin::findAdmin('agent', 'SHG' . $m[1])['id'] ?? 0) === (int) $r['id']);
            break;
        }
    }
    if (!$codeHit) { echo "  SKIP  no agent code on this DB\n"; }

    /* own change ends the default; office reset brings it back and kills the old password */
    Auth::passwordChanged($aid, $aid);
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $aid]);
    $chk('after own change: default no longer opens', !EasyLogin::adminPasswordOk($a, 'Wxeasy@2026') && EasyLogin::adminPasswordOk($a, 'OwnSecret#9'));
    $pw = EasyLogin::resetAdmin($aid, 1);
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $aid]);
    $chk('reset returns the default', $pw === 'Wxeasy@2026');
    $chk('after reset: default opens, old one does not', EasyLogin::adminPasswordOk($a, 'Wxeasy@2026') && !EasyLogin::adminPasswordOk($a, 'OwnSecret#9'));

    /* the owner's account never opens with a guessed default */
    $sid = (int) Database::insert('admins', ['username' => 'wxeasy_sa_' . substr($phone, -5), 'full_name' => 'Wxboss Owner', 'role' => 'superadmin',
        'is_active' => 1, 'password_hash' => Security::hashPassword('BossOnly#7'), 'must_change_pw' => 0]);
    $s = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $sid]);
    $chk('superadmin: FirstName@2026 never opens', !EasyLogin::adminPasswordOk($s, 'Wxboss@2026') && EasyLogin::adminPasswordOk($s, 'BossOnly#7'));
    $threw = false; try { EasyLogin::resetAdmin($sid, 1); } catch (RuntimeException) { $threw = true; }
    $chk('superadmin cannot be reset to the default', $threw);

    /* switch off (an account whose own password is not the default) */
    Database::update('admins', ['password_hash' => Security::hashPassword('OwnSecret#9'), 'pw_is_default' => 1], 'id = :i', ['i' => $aid]);
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $aid]);
    Settings::set('login_default_pw_on', '0', 'bool', 'security', false); Settings::flush();
    $chk('login_default_pw_on = 0: default stops', !EasyLogin::adminPasswordOk($a, 'Wxeasy@2026') && EasyLogin::adminPasswordOk($a, 'OwnSecret#9'));
    Database::run("DELETE FROM settings WHERE skey = 'login_default_pw_on'"); Settings::flush();

    /* drivers / conductors */
    $did = (int) Database::insert('drivers', ['full_name' => 'Wxdrive Conductor', 'phone' => '97' . substr($phone, 2), 'role' => 'conductor', 'is_active' => 1]);
    $chk('driver found by number and by mobile', (int) (EasyLogin::findDriver((string) $did)['id'] ?? 0) === $did && (int) (EasyLogin::findDriver('97' . substr($phone, 2))['id'] ?? 0) === $did);
    $res = DriverAuth::login((string) $did, 'wxdrive@2026', 'test');
    $chk('driver app login: number + FirstName@2026', is_array($res) && (int) ($res['driver']['id'] ?? 0) === $did && !isset($res['driver']['pin_hash']));
    DriverAuth::setPin($did, '4321');
    Database::run('UPDATE drivers SET pw_is_default = 0 WHERE id = :i', ['i' => $did]);
    $chk('PIN works, default off once changed', DriverAuth::login('97' . substr($phone, 2), '4321', 'test') !== null && DriverAuth::login((string) $did, 'Wxdrive@2026', 'test') === null);
    $chk('driver reset -> default back, PIN gone', EasyLogin::resetDriver($did, 1) === 'Wxdrive@2026'
        && DriverAuth::login((string) $did, 'Wxdrive@2026', 'test') !== null && DriverAuth::login((string) $did, '4321', 'test') === null);

    /* the page */
    $lp = (string) file_get_contents($root . '/admin/login.php');
    $chk('login.php shows the easy door by default, old doors on ?classic=1', str_contains($lp, "require __DIR__ . '/_easy-door.php';") && str_contains($lp, "'classic', 'step'"));
    $ed = (string) file_get_contents($root . '/admin/_easy-door.php');
    $chk('easy door: 5 modes, CSRF, no default printed on the page', str_contains($ed, "'driver'  =>") && str_contains($ed, 'Security::csrfField()') && !str_contains($ed, '@2026'));
} catch (Throwable $e) {
    $chk('no exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    if ($aid) { Database::run('DELETE FROM admins WHERE id = :i', ['i' => $aid]); }
    if ($sid) { Database::run('DELETE FROM admins WHERE id = :i', ['i' => $sid]); }
    if ($did) { Database::run('DELETE FROM driver_devices WHERE driver_id = :i', ['i' => $did]); Database::run('DELETE FROM drivers WHERE id = :i', ['i' => $did]); }
    try { Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'easy_login%'"); } catch (Throwable) {}
}
echo "\n  easy-login: $ok passed, $bad failed\n";
exit($bad > 0 ? 1 : 0);
