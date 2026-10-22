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
    unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION[TeamPortal::SESSION], $_SESSION['wa_verified'], $_SESSION['wa_tokens'], $_SESSION['team_reset']);
}
/** 22 Oct 2026 (review): a reset code belongs to the FULL number it was sent to ('team:<country><10 digits>'). */
function code(string $intl): string
{
    $r = Auth::issueOtp('team:' . $intl, 'reset', 'whatsapp');
    return (string) ($r['code'] ?? '');
}

$S = '9800077701'; $A = '9800077702'; $B = '9800077703';
$sid = 0; $ids = [];
try {
    Database::run("DELETE FROM staff_applications WHERE phone IN ('$S','$A')");
    Database::run("DELETE FROM admins WHERE username IN ('tp-agent','tp-boss','tp-2fa','tp-intl','tp-pick')");
    fresh();

    check('phone10 reads +977 / +91 / spaces', TeamPortal::phone10('+977 980-007-7701') === $S && TeamPortal::phone10('91 98000 77701') === $S && TeamPortal::phone10('123') === '');

    // ---- a staff member with no account ----
    $sid = (int) Database::insert('staff_applications', ['full_name' => 'TP Test Driver', 'phone' => $S, 'phone_country' => 'NP',
        'role_applied' => 'driver', 'id_type' => 'citizenship', 'id_number' => 'TP-1', 'status' => 'approved']);
    // 22 Oct 2026 (review): "no password yet" is no longer told apart from "no such number" (it listed who is in the team)
    $r = TeamPortal::login($S, 'whatever123');
    $u = TeamPortal::login('9800077799', 'whatever123');
    check('approved staff without a password gets the same answer as an unknown number', !$r['ok'] && !$u['ok'] && empty($r['first']) && $r === $u, json_encode($r, JSON_UNESCAPED_UNICODE));
    check('… which says how to make a first password', str_contains((string) $r['error'], 'First time'));

    fresh();
    $r = TeamPortal::resetPassword($S, '000000', 'Secret#2026', 'Secret#2026');
    check('reset with a wrong code is refused', !$r['ok']);
    $r = TeamPortal::resetPassword($S, code('977' . $S), 'short', 'short');
    check('a short password is refused', !$r['ok']);
    $r = TeamPortal::resetPassword($S, code('977' . $S), 'Secret#2026', 'Other#2026');
    check('two different passwords are refused', !$r['ok']);
    fresh();
    $r = TeamPortal::resetPassword($S, code('977' . $S), 'Secret#2026', 'Secret#2026');
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
    $r = TeamPortal::resetPassword($S, code('977' . $S), 'Secret#2027', 'Secret#2027');
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
    $r = TeamPortal::resetPassword($A, code('977' . $A), 'New#pass2026', 'New#pass2026');
    check('agent: a WhatsApp reset sets the new password and signs in', $r['ok'] && $r['kind'] === 'admin'
        && Security::verifyPassword('New#pass2026', (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $aid])));
    check('… and clears "must change"', (int) Database::scalar('SELECT must_change_pw FROM admins WHERE id = :i', ['i' => $aid]) === 0);

    // ---- one mobile, staff card + agent account ----
    Database::run('UPDATE staff_applications SET phone = :p WHERE id = :i', ['p' => $A, 'i' => $sid]);
    fresh();
    $r = TeamPortal::resetPassword($A, code('977' . $A), 'Both#pass2026', 'Both#pass2026');
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
    // 22 Oct 2026 (review): refused without naming the account's number (it would tell a stranger the country)
    check('a reset VERIFY from the same digits in another country is refused', str_contains((string) $wrong, 'not for this WhatsApp number')
        && !str_contains((string) $wrong, maskPhone('977' . $S)) && WaVerify::check($v['token'])['status'] === 'mismatch');
    WaVerify::inbound('977' . $S, $v['text']);
    // 22 Oct 2026 (review): a reset proof only reports "verified" to the page - it is never a form's proof of the number
    check('from the registered WhatsApp it proves the number (for the reset only)', WaVerify::check($v['token'])['status'] === 'verified' && !WaVerify::sessionVerified($S));
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

    // ---- agreements signed in the portal ----
    $im = imagecreatetruecolor(400, 140); imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagesetthickness($im, 4); for ($i = 0; $i < 20; $i++) { imageline($im, 10 + $i * 18, 30 + ($i % 2) * 80, 28 + $i * 18, 110 - ($i % 2) * 80, 0); }
    ob_start(); imagepng($im); $sigUri = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    fresh();
    TeamPortal::login($S, 'Verify#2026');
    $me = TeamPortal::current();
    $ags = TeamPortal::agreementsFor($me);
    check('an approved staff member signs the staff agreement', count($ags) === 1 && $ags[0]['doc'] === 'staff' && $ags[0]['version'] === StaffApps::AGREEMENT_VERSION);
    $e = '';
    try { TeamPortal::sign($me, 'staff', $sigUri, false); } catch (TeamPortalError $x) { $e = $x->getMessage(); }
    check('signing without "I accept" is refused', $e !== '');
    $e = '';
    try { TeamPortal::sign($me, 'staff', 'data:image/png;base64,iVBORw0KGgo=', true); } catch (TeamPortalError $x) { $e = $x->getMessage(); }
    check('an empty signature is refused', $e !== '');
    $e = '';
    try { TeamPortal::sign($me, 'partner', $sigUri, true); } catch (TeamPortalError $x) { $e = $x->getMessage(); }
    check('someone else\'s agreement cannot be signed', $e !== '');
    $done = TeamPortal::sign($me, 'staff', $sigUri, true);
    $row = TeamPortal::lastSign($ags[0]);
    check('the signature is recorded (portal event, file for staff)', $row !== null && (string) $row['event'] === 'portal' && !empty($row['signature_path'])
        && TeamPortal::signImage($row) !== null && (!TeamPortal::signReady() || (string) $row['party_ref'] === StaffApps::refFor($sid)));
    Database::run('DELETE FROM agreement_signatures WHERE party_type = :t AND party_id = :i', ['t' => 'staff', 'i' => $sid]);
    if (!empty($row['signature_path']) && ($abs = StaffApps::absPath((string) $row['signature_path'])) !== null) { @unlink($abs); }

    // ---- the quick form's education + experience start an agent / partner CV ----
    $clean = AgentApplications::cleanFields(['p_name' => 'X', 'cv_qual' => 'bachelor', 'cv_exp' => '4', 'cv_prev' => 'Ticket counter', 'zz_unknown' => 'dropped']);
    check('the quick form keeps cv_qual / cv_exp / cv_prev, drops unknown names', ($clean['cv_qual'] ?? '') === 'bachelor' && ($clean['cv_prev'] ?? '') === 'Ticket counter' && !isset($clean['zz_unknown']));
    $cvA = TeamPortal::cv(['fields_json' => json_encode($clean), 'cv_json' => null], 'agent');
    check('an agent CV starts from the form (education + experience)', ($cvA['edu'][0]['level'] ?? '') === 'bachelor' && ($cvA['exp'][0]['org'] ?? '') === 'Ticket counter' && ($cvA['exp'][0]['years'] ?? '') === '4');
    check('the office reads the level as words', AgentApplications::displayValue('cv_qual', 'bachelor') === 'Bachelor · स्नातक');
    $cvB = TeamPortal::cv(['fields_json' => json_encode($clean), 'cv_json' => json_encode(['edu' => [['level' => 'master', 'school' => 'TU', 'year' => '2020']]])], 'agent');
    check('a CV saved in the portal wins over the form', ($cvB['edu'][0]['level'] ?? '') === 'master' && count($cvB['edu']) === 1);

    // ---- the WhatsApp country comes from the office's card, not "91" an import put in front ----
    fresh();
    $ids[] = $ia = (int) Database::insert('admins', ['username' => 'tp-intl', 'password_hash' => Security::hashPassword('Intl#pass2026'),
        'full_name' => 'TP Intl', 'phone' => '919800077705', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0]);
    $v1 = TeamPortal::startVerify('9800077705');
    check('without a card, the account\'s own country is used', (string) Database::scalar('SELECT intl FROM wa_verify WHERE token = :t', ['t' => $v1['token']]) === '919800077705');
    $cardId = (int) Database::insert('agent_applications', ['type' => 'ind', 'lang' => 'ne', 'name' => 'TP Intl', 'phone' => '9800077705',
        'phone_raw' => '+977 9800077705', 'fields_json' => '{}', 'status' => 'approved', 'kyc_status' => 'none', 'admin_id' => $ia]);
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'wa_verify%'");
    $v2 = TeamPortal::startVerify('9800077705');
    check('the card\'s "+977" wins over an imported "91" (code and hi VERIFY go to Nepal)', (string) Database::scalar('SELECT intl FROM wa_verify WHERE token = :t', ['t' => $v2['token']]) === '9779800077705');
    Database::run('DELETE FROM agent_applications WHERE id = :i', ['i' => $cardId]);
    Database::run("DELETE FROM wa_verify WHERE phone = '9800077705'");

    // ---- the country picker (owner: +91 first, +977 option) narrows, never redirects ----
    fresh();
    check('the picker defaults to India', TeamPortal::cc('') === '91' && TeamPortal::cc('977') === '977' && TeamPortal::cc('1') === '91');
    $ids[] = $pa = (int) Database::insert('admins', ['username' => 'tp-pick', 'password_hash' => Security::hashPassword('Pick#pass2026'),
        'full_name' => 'TP Pick', 'phone' => '+977 9800077706', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0]);
    $r = TeamPortal::login('9800077706', 'Pick#pass2026', '91');
    check('a Nepal account is not opened when India is picked', !$r['ok'] && empty($_SESSION[ADMIN_SESSION_KEY]));
    fresh();
    $r = TeamPortal::login('9800077706', 'Pick#pass2026', '977');
    check('… and is opened when Nepal is picked', $r['ok'] && (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) === $pa);
    fresh();
    $r = TeamPortal::login('9800077706', 'Pick#pass2026');
    check('no picker (old forms, API) behaves as before', $r['ok']);
    fresh();
    Database::run("DELETE FROM otp_codes WHERE identifier LIKE 'team:%9800077706'");
    TeamPortal::sendResetCode('9800077706', '91');
    check('India picked: no code is made for the Nepal number (and none for the Indian mirror)',
        (int) Database::scalar("SELECT COUNT(*) FROM otp_codes WHERE identifier LIKE 'team:%9800077706'", [], 0) === 0);
    fresh();
    TeamPortal::sendResetCode('9800077706', '977');
    check('Nepal picked: the code is made for the Nepal number on file only',
        (int) Database::scalar("SELECT COUNT(*) FROM otp_codes WHERE identifier = 'team:9779800077706'", [], 0) === 1
        && (int) Database::scalar("SELECT COUNT(*) FROM otp_codes WHERE identifier = 'team:919800077706'", [], 0) === 0);
    fresh();
    TeamPortal::startVerify('9800077706', '91');
    check('India picked: "hi VERIFY" is bound to no number (nobody can prove it)', (string) ($_SESSION['team_reset']['wa']['intl'] ?? 'x') === '');
    fresh();
    TeamPortal::startVerify('9800077706', '977');
    check('Nepal picked: "hi VERIFY" is bound to the Nepal number on file', (string) ($_SESSION['team_reset']['wa']['intl'] ?? '') === '9779800077706');
    Database::run("DELETE FROM otp_codes WHERE identifier LIKE 'team:%9800077706'");
    Database::run("DELETE FROM wa_verify WHERE phone = '9800077706'");
    if ($old2fa !== null) {
        Settings::set('admin_2fa_ids', $old2fa);
    }
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($sid > 0) { Database::run('DELETE FROM staff_applications WHERE id = :i', ['i' => $sid]); }
    foreach ($ids as $i) { Database::run('DELETE FROM admins WHERE id = :i', ['i' => $i]); }
    Database::run("DELETE FROM otp_codes WHERE identifier LIKE 'team:%98000777%'");
    Database::run("DELETE FROM wa_verify WHERE phone LIKE '98000777%'");
    fresh();
}

echo "\n  $PASS passed, $FAIL failed\n\n";
ob_end_flush();
exit($FAIL === 0 ? 0 : 1);
