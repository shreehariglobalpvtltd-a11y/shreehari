<?php
/**
 * tests/teamportal-test.php — 22 Oct 2026, owner: one team-portal login
 * for partners, agents and staff, tied to the registered mobile; password,
 * and "forgot password" by a WhatsApp code or "hi VERIFY".
 *
 *   php -c .claude/php-dev.ini tests/teamportal-test.php
 *
 * Runs against a TEST database only, after
 * database/upgrade-2026-10-22-team-login.sql. Every row it writes is
 * removed at the end. Exit 1 on any failure.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/teamportal.php';
// the sign-in paths regenerate the session id, which needs headers unsent: hold the output
ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$DBN = (string) DB_NAME;
if (!str_contains($DBN, 'test') && !str_starts_with($DBN, 'lt_')) {
    fwrite(STDERR, "refusing to run against '$DBN' (not a test database)\n");
    exit(2);
}
if (!TeamPortal::ready()) {
    fwrite(STDERR, "run database/upgrade-2026-10-22-team-login.sql first\n");
    exit(2);
}

$PASS = 0; $FAIL = 0;
function check(string $label, bool $ok, string $why = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $label" . ($why !== '' ? "  [$why]" : '') . "\n"; }
}
function fresh(): void
{
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'team_%' OR bucket LIKE 'otp_%' OR bucket LIKE 'wa_verify%'");
    unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION[TeamPortal::SESSION], $_SESSION['wa_verified'], $_SESSION['wa_tokens']);
}
function code(string $phone): string
{
    $r = Auth::issueOtp('team:' . $phone, 'reset', 'whatsapp');
    return (string) ($r['code'] ?? '');
}

$S = '9800077701'; $A = '9800077702'; $B = '9800077703';
$sid = 0; $ids = [];
try {
    Database::run("DELETE FROM staff_applications WHERE phone IN ('$S','$A')");
    Database::run("DELETE FROM admins WHERE username IN ('tp-agent','tp-boss','tp-2fa')");
    fresh();

    check('phone10 reads +977 / +91 / spaces', TeamPortal::phone10('+977 980-007-7701') === $S && TeamPortal::phone10('91 98000 77701') === $S && TeamPortal::phone10('123') === '');

    // ---- a staff member with no account ----
    $sid = (int) Database::insert('staff_applications', ['full_name' => 'TP Test Driver', 'phone' => $S, 'phone_country' => 'NP',
        'role_applied' => 'driver', 'id_type' => 'citizenship', 'id_number' => 'TP-1', 'status' => 'approved']);
    $r = TeamPortal::login($S, 'whatever123');
    check('approved staff without a password is told to make one', !$r['ok'] && !empty($r['first']), json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = TeamPortal::login('9800077799', 'whatever123');
    check('an unknown number gets the generic answer', !$r['ok'] && empty($r['first']));

    fresh();
    $r = TeamPortal::resetPassword($S, '000000', 'Secret#2026', 'Secret#2026');
    check('reset with a wrong code is refused', !$r['ok']);
    $r = TeamPortal::resetPassword($S, code($S), 'short', 'short');
    check('a short password is refused', !$r['ok']);
    $r = TeamPortal::resetPassword($S, code($S), 'Secret#2026', 'Other#2026');
    check('two different passwords are refused', !$r['ok']);
    fresh();
    $r = TeamPortal::resetPassword($S, code($S), 'Secret#2026', 'Secret#2026');
    check('reset with the WhatsApp code sets the password and signs in (staff)', $r['ok'] && $r['kind'] === 'staff' && (int) ($_SESSION[TeamPortal::SESSION]['staff_id'] ?? 0) === $sid, json_encode($r));
    $me = TeamPortal::current();
    check('current() is that staff member', $me !== null && $me['via'] === 'staff' && (int) $me['staff']['id'] === $sid && TeamPortal::cardRefs($me) === [StaffApps::refFor($sid)]);
    check('a staff member without an account has no "sell tickets"', TeamPortal::sellUrl($me['admin'] ?? null) === null);

    fresh();
    $r = TeamPortal::login($S, 'Secret#2026');
    check('mobile + password signs the staff member in', $r['ok'] && $r['kind'] === 'staff');
    fresh();
    for ($i = 0; $i < MAX_LOGIN_ATTEMPTS; $i++) {
        TeamPortal::login($S, 'wrong-pass-' . $i);
        Database::run("DELETE FROM rate_limits WHERE bucket = 'team_login'");
    }
    $r = TeamPortal::login($S, 'Secret#2026');
    check('five wrong passwords lock the staff login', !$r['ok'] && !empty(Database::scalar('SELECT locked_until FROM staff_applications WHERE id = :i', ['i' => $sid])));
    fresh();
    $r = TeamPortal::resetPassword($S, code($S), 'Secret#2027', 'Secret#2027');
    check('a WhatsApp reset unlocks it', $r['ok'] && (int) Database::scalar('SELECT failed_logins FROM staff_applications WHERE id = :i', ['i' => $sid]) === 0);
    Database::run("UPDATE staff_applications SET status = 'suspended' WHERE id = :i", ['i' => $sid]);
    check('a suspended staff member is signed out on the next page', TeamPortal::current() === null);
    fresh();
    check('… and cannot sign in', !TeamPortal::login($S, 'Secret#2027')['ok']);
    Database::run("UPDATE staff_applications SET status = 'approved' WHERE id = :i", ['i' => $sid]);

    // ---- an agent account (admins) ----
    $ids[] = $aid = (int) Database::insert('admins', ['username' => 'tp-agent', 'password_hash' => Security::hashPassword('Old#pass2026'),
        'full_name' => 'TP Test Agent', 'phone' => '+977 ' . $A, 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 1]);
    $ids[] = (int) Database::insert('admins', ['username' => 'tp-boss', 'password_hash' => Security::hashPassword('Boss#pass2026'),
        'full_name' => 'TP Boss', 'phone' => $B, 'role' => 'superadmin', 'is_active' => 1, 'must_change_pw' => 0]);
    check('a super-admin is never a team account', Auth::teamAccounts($B) === []);
    fresh();
    check('a super-admin cannot use the team door', !TeamPortal::login($B, 'Boss#pass2026')['ok']);
    fresh();
    $r = TeamPortal::login($A, 'Old#pass2026');
    check('agent: mobile + existing password opens the staff session', $r['ok'] && $r['kind'] === 'admin' && (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) === $aid);
    check('… and "sell tickets" is the agent panel', TeamPortal::sellUrl(Auth::admin()) === '/admin/agent.php');
    fresh();
    $r = TeamPortal::resetPassword($A, code($A), 'New#pass2026', 'New#pass2026');
    check('agent: a WhatsApp reset sets the new password and signs in', $r['ok'] && $r['kind'] === 'admin'
        && Security::verifyPassword('New#pass2026', (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $aid])));
    check('… and clears "must change"', (int) Database::scalar('SELECT must_change_pw FROM admins WHERE id = :i', ['i' => $aid]) === 0);

    // ---- one mobile, staff card + agent account ----
    Database::run('UPDATE staff_applications SET phone = :p WHERE id = :i', ['p' => $A, 'i' => $sid]);
    fresh();
    $r = TeamPortal::resetPassword($A, code($A), 'Both#pass2026', 'Both#pass2026');
    check('one reset sets the same password on the account and the staff card', $r['ok'] && $r['count'] === 2
        && Security::verifyPassword('Both#pass2026', (string) Database::scalar('SELECT password_hash FROM staff_applications WHERE id = :i', ['i' => $sid])));
    $me = TeamPortal::current();
    check('signed in through the account, the staff card comes along', $me !== null && $me['via'] === 'admin' && $me['staff'] !== null && (int) $me['staff']['id'] === $sid);

    // ---- 2FA accounts go to the office login ----
    $ids[] = $tf = (int) Database::insert('admins', ['username' => 'tp-2fa', 'password_hash' => Security::hashPassword('Twofa#2026'),
        'full_name' => 'TP 2FA', 'phone' => '9800077704', 'role' => 'counter', 'is_active' => 1, 'must_change_pw' => 0]);
    $old2fa = Settings::get('admin_2fa_ids', null);
    Auth::setAdmin2fa($tf, true, 0);
    fresh();
    $r = TeamPortal::login('9800077704', 'Twofa#2026');
    check('an account with 2FA is sent to the office login', !$r['ok'] && !empty($r['twofa']) && empty($_SESSION[ADMIN_SESSION_KEY]));
    Auth::setAdmin2fa($tf, false, 0);

    // ---- "hi VERIFY" for a reset: the full number must match ----
    fresh();
    Database::run('UPDATE staff_applications SET phone = :p WHERE id = :i', ['p' => $S, 'i' => $sid]);
    $v = TeamPortal::startVerify($S);
    $wrong = WaVerify::inbound('91' . $S, $v['text']);
    check('a reset VERIFY from the same digits in another country is refused', str_contains((string) $wrong, 'another number'));
    WaVerify::inbound('977' . $S, $v['text']);
    check('from the registered WhatsApp it proves the number', WaVerify::check($v['token'])['status'] === 'verified' && WaVerify::sessionVerified($S));
    $r = TeamPortal::resetPassword($S, '', 'Verify#2026', 'Verify#2026');
    check('…then a new password needs no code', $r['ok'] && $r['kind'] === 'staff');

    // ---- CV ----
    $cv = TeamPortal::cleanCv(['edu_level' => ['plus2', '', 'bogus'], 'edu_school' => ['Mahendra Campus', '', 'X'], 'edu_year' => ['2015a', '', '1999'],
        'exp_org' => ['Sajha <b>Yatayat</b>', ''], 'exp_role' => ['Driver', ''], 'exp_years' => ['6', ''], 'langs' => "Nepali\nHindi",
        'org' => ['reg_no' => '123/045'], 'about' => str_repeat('a', 900)], false);
    check('CV keeps real rows only, years as digits', count($cv['edu']) === 2 && $cv['edu'][0]['year'] === '2015' && $cv['edu'][1]['level'] === 'other' && count($cv['exp']) === 1);
    check('CV text is one line, tags stripped, capped', !str_contains($cv['langs'], "\n") && !str_contains($cv['exp'][0]['org'], '<') && mb_strlen($cv['about']) <= 500 && $cv['org'] === []);
    TeamPortal::saveCv('staff', $sid, $cv);
    $back = TeamPortal::cv(StaffApps::find($sid), 'staff');
    check('CV saves and reads back', $back['edu'][0]['school'] === 'Mahendra Campus');
    check('CV HTML escapes', !str_contains(TeamPortal::cvHtml(['edu' => [], 'exp' => [], 'langs' => '<script>', 'skills' => '', 'about' => '', 'org' => []], false), '<script>'));
    if ($old2fa !== null) {
        Settings::set('admin_2fa_ids', $old2fa);
    }
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($sid > 0) { Database::run('DELETE FROM staff_applications WHERE id = :i', ['i' => $sid]); }
    foreach ($ids as $i) { Database::run('DELETE FROM admins WHERE id = :i', ['i' => $i]); }
    Database::run("DELETE FROM otp_codes WHERE identifier LIKE 'team:98000777%'");
    fresh();
}

echo "\n  $PASS passed, $FAIL failed\n\n";
ob_end_flush();
exit($FAIL === 0 ? 0 : 1);
