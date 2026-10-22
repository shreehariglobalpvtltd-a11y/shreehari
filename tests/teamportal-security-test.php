<?php
/**
 * tests/teamportal-security-test.php — 22 Oct 2026 (review): the team
 * portal's reset / sign-in / "hi VERIFY" rules after the security review.
 *
 *   php -c .claude/php-dev.ini tests/teamportal-security-test.php
 *
 * The /api/wa-verify.php checks go over HTTP to SHG_TEST_BASE (default
 * http://localhost:8893) and are skipped when no server answers there.
 *
 * Runs against a TEST database only, after the 22 Oct team-portal and
 * wa-verify migrations. No WhatsApp is sent (TeamPortal::$deliverHook takes
 * the codes). Every row it writes is removed at the end. Exit 1 on any
 * failure.
 *
 * Also (cards fixer): #15 a selling account's staff card comes by the
 * office's link or the FULL number, never the last 10 digits; #17 an
 * agreement is signed once per term and an office 'expired' is never
 * turned back.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/teamportal.php';
ob_start();   // the sign-in paths regenerate the session id, which needs headers unsent
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$DBN = (string) DB_NAME;
if (!str_contains($DBN, 'test') && !str_starts_with($DBN, 'lt_')) {
    fwrite(STDERR, "refusing to run against '$DBN' (not a test database)\n");
    exit(2);
}
if (!TeamPortal::ready() || !WaVerify::ready()) {
    fwrite(STDERR, "run database/upgrade-2026-10-22-team-login.sql and upgrade-2026-10-22-wa-verify.sql first\n");
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
    unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION[TeamPortal::SESSION], $_SESSION['wa_verified'], $_SESSION['wa_tokens'],
        $_SESSION['wa_tried'], $_SESSION['team_reset'], $_SESSION['staff_otp_ok']);
    $GLOBALS['SENT'] = [];
}
function code(string $intl): string
{
    return (string) (Auth::issueOtp('team:' . $intl, 'reset', 'whatsapp')['code'] ?? '');
}
function pwIs(string $table, int $id, string $pw): bool
{
    return Security::verifyPassword($pw, (string) Database::scalar("SELECT password_hash FROM $table WHERE id = :i", ['i' => $id], ''));
}
function staffRow(string $phone, string $cc, string $status, string $name, ?int $adminId = null, ?string $pw = null): int
{
    return (int) Database::insert('staff_applications', ['full_name' => $name, 'phone' => $phone, 'phone_country' => $cc, 'role_applied' => 'driver',
        'id_type' => 'citizenship', 'id_number' => 'ZZSEC-' . $phone, 'status' => $status, 'admin_id' => $adminId,
        'password_hash' => $pw !== null ? Security::hashPassword($pw) : null]);
}
function adminRow(string $user, string $phone, string $role, string $pw, int $mustChange = 0): int
{
    return (int) Database::insert('admins', ['username' => $user, 'password_hash' => $pw !== '' ? Security::hashPassword($pw) : '',
        'full_name' => 'ZZ ' . $user, 'phone' => $phone, 'role' => $role, 'is_active' => 1, 'must_change_pw' => $mustChange]);
}
function waIntl(string $token): string
{
    return (string) Database::scalar('SELECT intl FROM wa_verify WHERE token = :t', ['t' => $token], '?');
}

$SENT = [];
TeamPortal::$deliverHook = static function (string $intl, string $code): bool {
    $GLOBALS['SENT'][] = [$intl, $code];
    return true;
};

$P = [];
for ($i = 1; $i <= 9; $i++) {
    $P[$i] = '980006660' . $i;
}
$IN = '9000066601';   // a bare number outside Nepal's mobile ranges
$UNKNOWN = '9800066698';
$sids = []; $ids = []; $apps = [];
try {
    Database::run("DELETE FROM staff_applications WHERE phone LIKE '98000666%' OR phone = '$IN'");
    Database::run("DELETE FROM admins WHERE username LIKE 'zzsec-%'");
    fresh();

    /* ---------- #0 #9 #16 #21: a form's proof never opens a reset ---------- */
    $sids[] = $s1 = staffRow($P[1], 'NP', 'approved', 'ZZ Sec One', null, 'Old#pass2026');
    $v = WaVerify::start('staff', $P[1], 'IN');            // what /api/wa-verify.php made with cc=91
    WaVerify::inbound('91' . $P[1], $v['text']);
    check('a staff-form "hi VERIFY" still proves the number for the forms', WaVerify::check($v['token'])['status'] === 'verified' && WaVerify::sessionVerified($P[1]));
    $_SESSION['team_reset'] = ['phone' => $P[1], 't' => time()];
    $r = TeamPortal::resetPassword($P[1], '', 'Attack#2026', 'Attack#2026');
    check('… but it does not reset a team password', !$r['ok'] && pwIs('staff_applications', $s1, 'Old#pass2026'), json_encode($r, JSON_UNESCAPED_UNICODE));
    fresh();
    $v = WaVerify::start('reset', $P[1], 'IN');            // a reset token with a country the caller picked
    WaVerify::inbound('91' . $P[1], $v['text']);
    $_SESSION['team_reset'] = ['phone' => $P[1], 'wa' => ['token' => $v['token'], 'intl' => '91' . $P[1]]];
    $r = TeamPortal::resetPassword($P[1], '', 'Attack#2026', 'Attack#2026');
    check('a reset token for +91 does not reset the +977 account', !$r['ok'] && pwIs('staff_applications', $s1, 'Old#pass2026'));
    check('… and is no proof for the account\'s own number either', !WaVerify::consumeReset($v['token'], '977' . $P[1]));
    check('a reset token\'s "verified" never stamps the forms\' proof', WaVerify::check($v['token'])['status'] === 'verified' && !WaVerify::sessionVerified($P[1]));
    fresh();
    $v = TeamPortal::startVerify($P[1]);
    check('the portal binds its reset token to the account\'s full number', waIntl($v['token']) === '977' . $P[1]
        && ($_SESSION['team_reset']['wa']['intl'] ?? '') === '977' . $P[1]);
    WaVerify::inbound('977' . $P[1], $v['text']);
    check('consumeReset: true once for the proven number …', WaVerify::consumeReset($v['token'], '977' . $P[1]));
    check('… and never twice', !WaVerify::consumeReset($v['token'], '977' . $P[1]));
    fresh();
    $v = TeamPortal::startVerify($P[1]);
    WaVerify::inbound('977' . $P[1], $v['text']);
    unset($_SESSION['wa_tokens']);
    check('consumeReset: a token another session made is refused', !WaVerify::consumeReset($v['token'], '977' . $P[1]));

    /* ---------- HTTP: /api/wa-verify.php start refuses 'reset' ---------- */
    fresh();
    $base = rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8893'), '/');
    $jar = tempnam(sys_get_temp_dir(), 'tpsec');
    $http = static function (string $method, string $url, ?array $json = null, string $csrf = '') use ($base, $jar): array {
        $ch = curl_init($base . $url);
        $h = ['Accept: application/json'];
        if ($json !== null) {
            $h[] = 'Content-Type: application/json';
            $h[] = 'X-CSRF-Token: ' . $csrf;
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h] + ($json !== null ? [CURLOPT_POSTFIELDS => json_encode($json)] : []));
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    };
    [$hc, $page] = $http('GET', '/team/');
    if ($hc === 200 && preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m) === 1) {
        [$c1] = $http('POST', '/api/wa-verify.php', ['action' => 'start', 'purpose' => 'reset', 'phone' => $P[1], 'cc' => '977'], $m[1]);
        check('HTTP: start with purpose "reset" is refused (400)', $c1 === 400, (string) $c1);
        [$c2] = $http('POST', '/api/wa-verify.php', ['action' => 'start', 'purpose' => 'staff', 'phone' => $P[1], 'cc' => '977'], $m[1]);
        check('HTTP: the staff form\'s start still works', $c2 === 200 || $c2 === 500, (string) $c2);   // 500 = no company number on this box
        [$c3, $b3] = $http('POST', '/api/wa-verify.php', ['action' => 'check', 'token' => 'ZZZZZZ'], $m[1]);
        check('HTTP: check still answers', $c3 === 200 && str_contains($b3, '"status"'), (string) $c3);
    } else {
        echo "  SKIP  HTTP checks ($base/team/ answered $hc)\n";
    }
    @unlink($jar);

    /* ---------- #1 #10 #8: two people, one 10 digits, two countries ---------- */
    fresh();
    $ids[] = $a2 = adminRow('zzsec-india', '+91 ' . $P[2], 'agent', 'IndiaA#2026', 1);
    $sids[] = $s2 = staffRow($P[2], 'NP', 'approved', 'ZZ Sec Nepal');
    $r = TeamPortal::sendResetCode($P[2]);
    $to = array_column($SENT, 0);
    sort($to);
    check('each person gets a code on their own full number', $r['ok'] && $to === ['91' . $P[2], '977' . $P[2]], json_encode($SENT));
    $sentTo = static fn(string $intl): string => (string) (array_values(array_filter($SENT, static fn(array $s): bool => $s[0] === $intl))[0][1] ?? '');
    $nepal = $sentTo('977' . $P[2]);
    $india = $sentTo('91' . $P[2]);
    $r = TeamPortal::resetPassword($P[2], $nepal, 'NepalB#2026', 'NepalB#2026');
    check('the Nepali code changes only the Nepali card', $r['ok'] && $r['kind'] === 'staff' && $r['count'] === 1
        && pwIs('staff_applications', $s2, 'NepalB#2026') && pwIs('admins', $a2, 'IndiaA#2026') && empty($_SESSION[ADMIN_SESSION_KEY]), json_encode($r));
    $r = TeamPortal::resetPassword($P[2], $india, 'IndiaA#2027', 'IndiaA#2027');
    check('the Indian code changes only the Indian agent and signs in there', $r['ok'] && $r['kind'] === 'admin' && $r['count'] === 1
        && (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) === $a2 && pwIs('staff_applications', $s2, 'NepalB#2026'), json_encode($r));
    check('#8 the new session does not ask for yet another password', ($_SESSION[ADMIN_SESSION_KEY]['must_change_pw'] ?? null) === false);
    fresh();
    $v = TeamPortal::startVerify($P[2]);
    check('with two people on the digits, "hi VERIFY" is bound to no number', waIntl($v['token']) === '');
    $rep = (string) WaVerify::inbound('977' . $P[2], $v['text']);
    check('… so neither can prove it', str_contains($rep, 'not for this WhatsApp number') && WaVerify::check($v['token'])['status'] === 'mismatch');

    /* ---------- #2: suspended / exited staff and their selling account ---------- */
    fresh();
    $ids[] = $c3 = adminRow('zzsec-counter', '+977 ' . $P[3], 'counter', 'OfficeLock#2026');
    $sids[] = $x3 = staffRow($P[3], 'NP', 'exited', 'ZZ Sec Exited', $c3);
    TeamPortal::sendResetCode($P[3]);
    check('an exited clerk gets no code for the linked counter account', $SENT === []);
    $r = TeamPortal::resetPassword($P[3], code('977' . $P[3]), 'ExClerk#2026', 'ExClerk#2026');
    check('… and cannot reset it', !$r['ok'] && pwIs('admins', $c3, 'OfficeLock#2026'), json_encode($r));
    check('… nor sign in with it at /team/', !TeamPortal::login($P[3], 'OfficeLock#2026')['ok']);
    Database::run("UPDATE staff_applications SET admin_id = NULL, status = 'suspended' WHERE id = :i", ['i' => $x3]);
    fresh();
    $r = TeamPortal::resetPassword($P[3], code('977' . $P[3]), 'ExClerk#2026', 'ExClerk#2026');
    check('a suspended card on the same full number blocks the account too', !$r['ok'] && pwIs('admins', $c3, 'OfficeLock#2026'));
    Database::run("UPDATE staff_applications SET phone_country = 'IN' WHERE id = :i", ['i' => $x3]);
    fresh();
    check('a suspended card in the OTHER country does not', TeamPortal::login($P[3], 'OfficeLock#2026')['ok']);
    Database::run("UPDATE staff_applications SET phone_country = 'NP', status = 'approved', admin_id = :a WHERE id = :i", ['a' => $c3, 'i' => $x3]);
    fresh();
    check('approved again (re-hired), the linked account works again', TeamPortal::login($P[3], 'OfficeLock#2026')['ok']);

    /* ---------- #3: 2FA ---------- */
    fresh();
    $ids[] = $t4 = adminRow('zzsec-2fa', '+91 ' . $P[4], 'counter', 'Twofa#2026');
    $sids[] = staffRow($P[4], 'IN', 'approved', 'ZZ Sec Twofa');
    Auth::setAdmin2fa($t4, true, 0);
    $r = TeamPortal::resetPassword($P[4], code('91' . $P[4]), 'NewTwo#2026', 'NewTwo#2026');
    check('a 2FA account: the reset opens NO session (office login + 2FA next)', $r['ok'] && !empty($r['twofa'])
        && empty($_SESSION[ADMIN_SESSION_KEY]) && empty($_SESSION[TeamPortal::SESSION]), json_encode($r));
    fresh();
    $r = TeamPortal::login($P[4], 'NewTwo#2026');
    check('… and /team/ still sends it to the office login', !$r['ok'] && !empty($r['twofa']));
    Auth::setAdmin2fa($t4, false, 0);

    /* ---------- #4 #20: forgot tells nobody who is in the team ---------- */
    fresh();
    $ids[] = $o5 = adminRow('zzsec-off', '+977 ' . $P[5], 'agent', 'Off#pass2026');
    Database::run('UPDATE admins SET is_active = 0 WHERE id = :i', ['i' => $o5]);
    $strip = static fn(array $x): array => array_diff_key($x, ['debugCode' => 1]);
    $ru = $strip(TeamPortal::sendResetCode($UNKNOWN));
    $ro = $strip(TeamPortal::sendResetCode($P[5]));
    $rr = $strip(TeamPortal::sendResetCode($P[1]));
    check('unknown, switched-off and registered numbers get the same answer', $ru === ['ok' => true] && $ro === $ru && $rr === $ru,
        json_encode([$ru, $ro, $rr], JSON_UNESCAPED_UNICODE));
    check('… while only the registered one was sent a code', array_column($SENT, 0) === ['977' . $P[1]]);
    fresh();
    for ($i = 0; $i < 5; $i++) { TeamPortal::sendResetCode($UNKNOWN); }
    $r = TeamPortal::sendResetCode($UNKNOWN);
    check('the forgot step has a budget per number (before the lookup)', !$r['ok'] && str_contains((string) $r['error'], 'Too many'));
    fresh();
    for ($i = 0; $i < 8; $i++) { TeamPortal::sendResetCode('98000667' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)); }
    $r = TeamPortal::sendResetCode('9800066790');
    check('… and per IP', !$r['ok'] && str_contains((string) $r['error'], 'Too many'));
    fresh();
    $wu = TeamPortal::resetPassword($UNKNOWN, '123456', 'Guess#2026', 'Guess#2026');
    $wr = TeamPortal::resetPassword($P[1], '123456', 'Guess#2026', 'Guess#2026');
    check('a wrong code reads the same with or without an account', !$wu['ok'] && $wu === $wr, json_encode([$wu, $wr], JSON_UNESCAPED_UNICODE));

    /* ---------- #5: signing out forgets this browser's proofs ---------- */
    fresh();
    TeamPortal::login($P[1], 'Old#pass2026');
    $_SESSION['wa_verified'][$P[1]] = time();
    $_SESSION['wa_tokens']['ZZZZZZ'] = $P[1];
    $_SESSION['wa_tried'][$P[1]] = time();
    $_SESSION['team_reset'] = ['phone' => $P[1]];
    $_SESSION['staff_otp_ok'][$P[1]] = time();
    TeamPortal::logout();
    check('sign-out clears wa_verified, wa_tokens, wa_tried, team_reset, staff_otp_ok', TeamPortal::current() === null
        && !isset($_SESSION['wa_verified']) && !isset($_SESSION['wa_tokens']) && !isset($_SESSION['wa_tried'])
        && !isset($_SESSION['team_reset']) && !isset($_SESSION['staff_otp_ok']) && !WaVerify::sessionVerified($P[1]));

    /* ---------- #6: a reset says so, and a relayed lure does not approve one ---------- */
    fresh();
    $v = TeamPortal::startVerify($P[1]);
    check('the reset message says PASSWORD RESET (both languages)', str_contains($v['text'], 'PASSWORD RESET') && str_contains($v['text'], 'पासवर्ड')
        && str_contains(rawurldecode($v['url']), 'PASSWORD RESET'));
    $rep = (string) WaVerify::inbound('977' . $P[1], 'Namaste S Hari, please verify ' . strtolower($v['token']) . ' for my October commission');
    check('a lure without the reset words is not a proof, and the reply warns', WaVerify::check($v['token'])['status'] === 'waiting'
        && str_contains($rep, 'PASSWORD RESET') && str_contains($rep, 'अरूले मागे नपठाउनुहोस्'));
    $rep = (string) WaVerify::inbound('977' . $P[1], $v['text']);
    check('the page\'s own message proves it, and the reply says it was a password reset', WaVerify::check($v['token'])['status'] === 'verified'
        && str_contains($rep, 'PASSWORD RESET') && str_contains($rep, 'call the office'));
    $f = WaVerify::start('staff', $P[1], 'NP');
    check('the forms\' message stays exactly "hi VERIFY <token>"', $f['text'] === 'hi VERIFY ' . $f['token']);

    /* ---------- #7: the AiSensy plain-URL door never proves a number ---------- */
    $src = (string) file_get_contents(dirname(__DIR__) . '/whatsapp/aisensy-webhook.php');
    check('AiSensy webhook: WaVerify::inbound only behind $trusted (the ?token= door)',
        preg_match('/\$verifyReply\s*=\s*\$trusted\s*\?\s*WaVerify::inbound\(/', $src) === 1
        && preg_match('/if\s*\(\s*!\$trusted\s*&&\s*WaVerify::isVerifyText\(.*?\)\s*\{[^}]*?exit;/s', $src) === 1
        && substr_count($src, 'WaVerify::inbound(') === 1);
    check('isVerifyText sees "hi VERIFY K7Q2M9", not "hi"', WaVerify::isVerifyText('hi VERIFY K7Q2M9') && !WaVerify::isVerifyText('hi'));

    /* ---------- #11: a new password ends the other staff-portal sessions ---------- */
    fresh();
    TeamPortal::login($P[1], 'Old#pass2026');
    $other = $_SESSION[TeamPortal::SESSION];
    check('a staff session carries a password stamp', !empty($other['pw']));
    fresh();
    TeamPortal::resetPassword($P[1], code('977' . $P[1]), 'Next#pass2026', 'Next#pass2026');
    $_SESSION[TeamPortal::SESSION] = $other;   // the session still open on another device
    check('after a reset, the older session is signed out', TeamPortal::current() === null);
    fresh();
    TeamPortal::login($P[1], 'Next#pass2026');
    $other = $_SESSION[TeamPortal::SESSION];
    TeamPortal::changePassword(TeamPortal::current(), 'Next#pass2026', 'Third#pass2026', 'Third#pass2026');
    check('changing the password keeps THIS session', TeamPortal::current() !== null);
    $_SESSION[TeamPortal::SESSION] = $other;
    check('… and ends the one opened with the old password', TeamPortal::current() === null);

    /* ---------- #12: wrong codes lock the code way ---------- */
    fresh();
    for ($i = 0; $i < TeamPortal::RESET_FAILS; $i++) {
        Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'otp_%'");
        TeamPortal::resetPassword($P[1], '000000', 'Brute#2026', 'Brute#2026');
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'otp_%' OR bucket = 'team_reset_phone'");
    $r = TeamPortal::resetPassword($P[1], code('977' . $P[1]), 'Brute#2026', 'Brute#2026');
    check(TeamPortal::RESET_FAILS . ' wrong codes lock the code way (even the right code is refused)', !$r['ok'] && str_contains((string) $r['error'], '24 hours')
        && pwIs('staff_applications', $s1, 'Third#pass2026'), json_encode($r, JSON_UNESCAPED_UNICODE));
    $SENT = [];
    $r = TeamPortal::sendResetCode($P[1]);
    check('… no more codes go out (same answer)', $r['ok'] && $SENT === []);
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'wa_verify%'");
    $v = TeamPortal::startVerify($P[1]);
    WaVerify::inbound('977' . $P[1], $v['text']);
    $r = TeamPortal::resetPassword($P[1], '', 'Owner#pass2026', 'Owner#pass2026');
    check('… while the owner\'s own WhatsApp ("hi VERIFY") still works and lifts the lock', $r['ok']
        && Database::scalar("SELECT COUNT(*) FROM rate_limits WHERE bucket = 'team_reset_fail' AND identifier = :i", ['i' => $P[1]], 0) == 0);

    /* ---------- #22: never guess the country of a bare number ---------- */
    fresh();
    $ids[] = $b6 = adminRow('zzsec-bare', $P[6], 'agent', '');
    TeamPortal::sendResetCode($P[6]);
    $v = TeamPortal::startVerify($P[6]);
    check('a bare 98… account: no code, and "hi VERIFY" bound to no number', $SENT === [] && waIntl($v['token']) === '');
    Database::insert('admin_profiles', ['admin_id' => $b6, 'country_code' => '977']);
    fresh();
    TeamPortal::sendResetCode($P[6]);
    check('… the agent profile\'s +977 sends it to Nepal', array_column($SENT, 0) === ['977' . $P[6]], json_encode($SENT));
    Database::run('DELETE FROM admin_profiles WHERE admin_id = :i', ['i' => $b6]);
    $sids[] = staffRow($P[6], 'IN', 'approved', 'ZZ Sec Linked', $b6);
    fresh();
    TeamPortal::sendResetCode($P[6]);
    check('… the linked staff card\'s country is used', array_column($SENT, 0) === ['91' . $P[6]], json_encode($SENT));
    $ids[] = adminRow('zzsec-india2', $IN, 'agent', '');
    fresh();
    TeamPortal::sendResetCode($IN);
    check('a bare number outside 96/97/98 can only be Indian', array_column($SENT, 0) === ['91' . $IN], json_encode($SENT));

    /* ---------- #15: a selling account's staff card — the office's link or the FULL number ---------- */
    fresh();
    $ids[] = $i7 = adminRow('zzsec-card-in', '+91 ' . $P[7], 'agent', 'CardIn#2026');
    $sids[] = $n7 = staffRow($P[7], 'NP', 'approved', 'ZZ Sec Nepal Card', null, 'CardNp#2026');
    TeamPortal::login($P[7], 'CardIn#2026');
    $me = TeamPortal::current();
    check('an Indian agent does not get the Nepali staff card on the same 10 digits', $me !== null && $me['via'] === 'admin'
        && $me['staff'] === null && TeamPortal::cardRefs($me) === [] && TeamPortal::agreementsFor($me) === [],
        json_encode([$me['staff']['id'] ?? null, TeamPortal::cardRefs((array) $me + ['staff' => null, 'agent' => null])]));
    TeamPortal::changePassword($me, 'CardIn#2026', 'CardIn#2027', 'CardIn#2027');
    check('… so a password change there leaves the staff member\'s password alone', pwIs('staff_applications', $n7, 'CardNp#2026') && pwIs('admins', $i7, 'CardIn#2027'));
    Database::run("UPDATE staff_applications SET phone_country = 'IN' WHERE id = :i", ['i' => $n7]);
    $me = TeamPortal::current();
    check('the same FULL number (+91 on both) still brings the card along', $me['staff'] !== null && (int) $me['staff']['id'] === $n7
        && TeamPortal::cardRefs($me) === [StaffApps::refFor($n7)]);
    Database::run("UPDATE staff_applications SET phone_country = 'NP', admin_id = :a WHERE id = :i", ['a' => $i7, 'i' => $n7]);
    $me = TeamPortal::current();
    check('the office\'s link (linkAccount) decides over the country', $me['staff'] !== null && (int) $me['staff']['id'] === $n7);
    Database::run("UPDATE staff_applications SET phone_country = 'IN', admin_id = :a WHERE id = :i", ['a' => $c3, 'i' => $n7]);
    $me = TeamPortal::current();
    check('a card the office linked to ANOTHER account is never taken by the number', $me['staff'] === null);
    Database::run("UPDATE staff_applications SET admin_id = NULL, status = 'exited' WHERE id = :i", ['i' => $n7]);
    $me = TeamPortal::current();
    check('a card that has left (exited) comes only by the link (a reused number gets nothing)', $me['staff'] === null);
    Database::run('UPDATE staff_applications SET admin_id = :a WHERE id = :i', ['a' => $i7, 'i' => $n7]);
    $me = TeamPortal::current();
    check('… linked, the person still sees it (marked not valid)', $me['staff'] !== null && TeamPortal::cardRefs($me) === [StaffApps::refFor($n7)]);
    fresh();
    $ids[] = $b8 = adminRow('zzsec-card-bare', $P[8], 'counter', 'CardBare#2026');
    $sids[] = $n8 = staffRow($P[8], 'NP', 'approved', 'ZZ Sec Bare Card');
    TeamPortal::login($P[8], 'CardBare#2026');
    $me = TeamPortal::current();
    check('a bare 98… account (country unknown) gets no card by the number', $me !== null && $me['staff'] === null);
    TeamPortal::linkAccount($n8, 'zzsec-card-bare', 0);
    $me = TeamPortal::current();
    check('… until the office links it', $me['staff'] !== null && (int) $me['staff']['id'] === $n8);

    /* ---------- #17: an agreement is signed once per term ---------- */
    fresh();
    $im = imagecreatetruecolor(400, 140); imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagesetthickness($im, 4); for ($i = 0; $i < 20; $i++) { imageline($im, 10 + $i * 18, 30 + ($i % 2) * 80, 28 + $i * 18, 110 - ($i % 2) * 80, 0); }
    ob_start(); imagepng($im); $sigUri = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    $signs = static fn(string $t, int $id): int => (int) Database::scalar('SELECT COUNT(*) FROM agreement_signatures WHERE party_type = :t AND party_id = :i', ['t' => $t, 'i' => $id], 0);
    $appRow = static fn(int $id): array => (array) Database::fetch('SELECT agreement_status, agreement_signed_at, agreement_end, reviewed_by FROM agent_applications WHERE id = :i', ['i' => $id]);
    $tryErr = static function (array $me, string $doc) use ($sigUri): string {
        try { TeamPortal::sign($me, $doc, $sigUri, true); } catch (TeamPortalError $x) { return $x->getMessage(); }
        return '';
    };
    $ids[] = $p9 = adminRow('zzsec-partner', '+977 ' . $P[9], 'agent', 'Partner#2026');
    $apps[] = $g9 = (int) Database::insert('agent_applications', ['type' => 'org', 'lang' => 'ne', 'name' => 'ZZ Sec Partner Pvt. Ltd.', 'phone' => $P[9],
        'phone_raw' => '+977 ' . $P[9], 'fields_json' => '{}', 'status' => 'approved', 'kyc_status' => 'verified', 'admin_id' => $p9,
        'agreement_status' => 'expired', 'agreement_signed_at' => '2024-01-10', 'agreement_end' => '2024-12-10']);
    Database::run('UPDATE agent_applications SET ref = :r WHERE id = :i', ['r' => AgentApplications::refFor($g9), 'i' => $g9]);
    TeamPortal::login($P[9], 'Partner#2026');
    $me = TeamPortal::current();
    $ag = TeamPortal::agreementsFor($me)[0] ?? [];
    check('an expired partner sees the sign form (no signature this term)', ($ag['doc'] ?? '') === 'partner' && TeamPortal::termSign($ag) === null);
    $d = TeamPortal::sign($me, 'partner', $sigUri, true);
    $a = $appRow($g9);
    check('signing keeps the signature but never turns the office\'s "expired" back', !empty($d['renew']) && $signs('agent', $g9) === 1
        && $a['agreement_status'] === 'expired' && $a['agreement_signed_at'] === '2024-01-10' && $a['agreement_end'] === '2024-12-10', json_encode($a));
    $e = $tryErr($me, 'partner');
    check('a replayed POST is refused — no second row', str_contains($e, 'already signed') && $signs('agent', $g9) === 1 && TeamPortal::termSign($ag) !== null);
    // the office asks for a new signature ('sent'), after the old one
    Database::run('UPDATE agreement_signatures SET signed_at = signed_at - INTERVAL 1 HOUR WHERE party_type = :t AND party_id = :i', ['t' => 'agent', 'i' => $g9]);
    AgentApplications::update($g9, ['agreement_status' => 'sent'], 0);
    check('the office sending it again opens one new signature', TeamPortal::termSign($ag) === null);
    $d = TeamPortal::sign($me, 'partner', $sigUri, true);
    $a = $appRow($g9);
    check('… which starts the term (signed today, 11 months)', empty($d['renew']) && $a['agreement_status'] === 'signed' && $a['agreement_signed_at'] === date('Y-m-d')
        && $a['agreement_end'] === AgentApplications::agreementEnd(date('Y-m-d')) && $signs('agent', $g9) === 2, json_encode($a));
    $e = $tryErr($me, 'partner');
    check('… and a replay neither adds a row nor moves the term', str_contains($e, 'already signed') && $signs('agent', $g9) === 2 && $appRow($g9) === $a);
    Database::run("UPDATE agreement_signatures SET signed_at = '2026-01-05 10:00:00' WHERE party_type = :t AND party_id = :i", ['t' => 'agent', 'i' => $g9]);
    AgentApplications::update($g9, ['agreement_status' => 'signed', 'agreement_signed_at' => '2026-02-01'], 0);   // the office renewed on paper
    $a = $appRow($g9);
    TeamPortal::sign($me, 'partner', $sigUri, true);
    check('a term the office set (paper): one portal signature, the term is not pushed forward', $signs('agent', $g9) === 3 && $appRow($g9) === $a
        && $a['agreement_signed_at'] === '2026-02-01' && str_contains($tryErr($me, 'partner'), 'already signed'));
    Database::run("DELETE FROM rate_limits WHERE bucket = 'team_sign'");
    Database::run("UPDATE agreement_signatures SET signed_at = '2026-01-05 10:00:00' WHERE party_type = :t AND party_id = :i", ['t' => 'agent', 'i' => $g9]);
    AgentApplications::update($g9, ['agreement_status' => 'signed', 'agreement_signed_at' => date('Y-m-d', strtotime('+1 day'))], 0);
    TeamPortal::sign($me, 'partner', $sigUri, true);
    check('a term the office dated tomorrow still takes ONE signature today', $signs('agent', $g9) === 4 && str_contains($tryErr($me, 'partner'), 'already signed')
        && $signs('agent', $g9) === 4);
    Database::run("DELETE FROM rate_limits WHERE bucket = 'team_sign'");
    for ($i = 0; $i < TeamPortal::SIGN_TRIES; $i++) {
        $tryErr($me, 'partner');
    }
    check('at most ' . TeamPortal::SIGN_TRIES . ' sign tries an hour per person', str_contains($tryErr($me, 'partner'), 'Too many'));
    // staff: one per agreement version, no file for a refused try
    fresh();
    TeamPortal::login($P[1], 'Owner#pass2026');
    $me = TeamPortal::current();
    $files = static fn(int $id): int => count(glob(UPLOAD_PATH . '/staff/' . $id . '/*') ?: []);
    TeamPortal::sign($me, 'staff', $sigUri, true);
    $e = $tryErr($me, 'staff');
    check('staff: one signature per version; a replay writes no second row or file', str_contains($e, 'already signed') && $signs('staff', $s1) === 1 && $files($s1) === 1);
    check('the page shows "signed" by the same rule', TeamPortal::termSign(TeamPortal::agreementsFor($me)[0]) !== null);
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    TeamPortal::$deliverHook = null;
    foreach ($apps as $i) {
        Database::run("DELETE FROM agreement_signatures WHERE party_type = 'agent' AND party_id = :i", ['i' => $i]);
        Database::run('DELETE FROM agent_applications WHERE id = :i', ['i' => $i]);
    }
    foreach ($sids as $i) {
        Database::run("DELETE FROM agreement_signatures WHERE party_type = 'staff' AND party_id = :i", ['i' => $i]);
        $dir = UPLOAD_PATH . '/staff/' . $i;
        if (is_dir($dir)) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }
    foreach ($ids as $i) {
        Auth::setAdmin2fa($i, false, 0);
        Database::run('DELETE FROM admin_profiles WHERE admin_id = :i', ['i' => $i]);
        Database::run('DELETE FROM admins WHERE id = :i', ['i' => $i]);
    }
    foreach ($sids as $i) { Database::run('DELETE FROM staff_applications WHERE id = :i', ['i' => $i]); }
    Database::run("DELETE FROM staff_applications WHERE phone LIKE '98000666%' OR phone = '$IN'");
    Database::run("DELETE FROM admins WHERE username LIKE 'zzsec-%'");
    Database::run("DELETE FROM wa_verify WHERE phone LIKE '98000666%' OR phone LIKE '98000667%' OR phone = '$IN'");
    Database::run("DELETE FROM otp_codes WHERE identifier LIKE 'team:%98000666%' OR identifier LIKE 'team:%98000667%' OR identifier LIKE 'team:%$IN'");
    Database::run("DELETE FROM rate_limits WHERE identifier LIKE '%98000666%' OR identifier LIKE '%98000667%' OR identifier LIKE '%$IN%'");
    fresh();
}

echo "\n  $PASS passed, $FAIL failed\n\n";
ob_end_flush();
exit($FAIL === 0 ? 0 : 1);
