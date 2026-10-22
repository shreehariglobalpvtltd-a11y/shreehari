<?php
/**
 * tests/admin-pw-stamp-test.php — 22 Oct 2026 (review #11, office part):
 * an office session ends when the account's password changes.
 *
 * Every office session now carries a short stamp of the password it was
 * opened with ('pw', Auth::finishAdminSession). Auth::admin() compares it
 * with the admins row on its 30-second re-check, so a changed or reset
 * password signs out the account's OTHER sessions, while the person who
 * changed their own password keeps theirs (Auth::refreshOwnPwStamp).
 * Proves:
 *   A. every real sign-in carries the stamp (and it is not the hash);
 *   B. own change: this session survives, the other one is revoked + audited;
 *   C. an office reset ends the person's sessions, not the office's own;
 *      an office account changing its OWN password keeps its session;
 *   D. a session from before this change (no stamp) is upgraded, not revoked;
 *   E. a team-portal reset: the new session has the new stamp, older ones end;
 *   F. TeamPortal::changePassword for an account: own session stays, others end;
 *   G. the 2FA door still works and its session carries the stamp;
 *   H. finishAdminSession is still the ONE place a session is written;
 *   I. (HTTP, when SHG_TEST_BASE is set) the real change-password.php, a
 *      voluntary and a forced change, two browsers each, 31 s apart — and
 *      (re-attack) the account's WhatsApp sign-ins end at the change.
 *  Re-attack (22 Oct 2026, same day):
 *   J. the stamp is the password the door CHECKED: a reset landing between
 *      the check and the session (a test trigger plays the office) no longer
 *      hands the old password's sign-in the new stamp — office, team and
 *      agent doors; such a session ends on its very next request;
 *   K. 2FA: a reset between the password step and the code refuses the code;
 *   L. WhatsApp staff sign-ins (wa_logins) end on every password change —
 *      office credentials, team change, team reset — and a WhatsApp sign-in
 *      still in flight with the old password (or its pending code) opens
 *      nothing;
 *   M. Auth::passwordChanged on this browser: new stamp + new session id,
 *      but a session that was already out of date does not adopt the new
 *      password;
 *   N. every place that writes admins.password_hash calls passwordChanged.
 *
 *   php -c .claude/php-dev.ini tests/admin-pw-stamp-test.php
 *   SHG_TEST_BASE=http://localhost:8893 php -c .claude/php-dev.ini tests/admin-pw-stamp-test.php
 *
 * TEST database only; every row it writes (zzt-pwstamp-*) is removed at the
 * end. No WhatsApp / SMS is sent (codes are minted with Auth::issueOtp, never
 * delivered). Exit 1 on any failure.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/teamportal.php';
require_once INCLUDE_PATH . '/walogin.php';
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

$PASS = 0; $FAIL = 0;
function check(string $label, bool $ok, string $why = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $label" . ($why !== '' ? "  [$why]" : '') . "\n"; }
}

const PFX    = 'zzt-pwstamp-';
const U_A    = PFX . 'mgr';
const U_BOSS = PFX . 'boss';
const U_AG   = PFX . 'agent';
const U_H1   = PFX . 'http1';
const U_H2   = PFX . 'http2';
const AG_10  = '9800077791';
const AG_INT = '977' . AG_10;
// re-attack (J-M)
const U_RACE_O = PFX . 'race-office';
const U_RACE_T = PFX . 'race-team';
const U_RACE_A = PFX . 'race-agent';
const U_WA     = PFX . 'wa';
const RT_10    = '9800077792';        // the team-door race agent's mobile (Nepal)
const WA_10    = '9800077793';        // the WhatsApp agent's registered mobile (Nepal)
const WA_INT   = '977' . WA_10;
const WA_H1    = '+919100097011';     // handsets nobody has on file
const WA_H2    = '+919100097012';
const WA_H3    = '+919100097013';
const WA_HTTP1 = '+919100097021';     // rows planted for the HTTP part
const WA_HTTP2 = '+919100097022';
const TRIGGERS = ['zzt_pwstamp_race', 'zzt_pwstamp_wa'];

/* In memory only (no settings row is written): the WhatsApp door on, no
   second factor for an agent, and no network driver. */
function waOn(): void
{
    Settings::flush();
    Settings::getBool('wa_login_on');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = $rp->getValue();
    foreach (['wa_login_on' => ['1', 'bool'], 'wa_login_otp' => ['0', 'bool'], 'wa_login_ttl_hours' => ['12', 'int'],
              'whatsapp_driver' => ['click_to_chat', 'string']] as $k => [$v, $t]) {
        $c[$k] = ['value' => $v, 'type' => $t, 'public' => false];
    }
    $rp->setValue(null, $c);
}

/** Is this handset signed in over WhatsApp as $id right now? */
function waLive(string $handset, int $id): bool
{
    waOn();
    return (int) (WaLogin::session($handset)['admin']['id'] ?? 0) === $id;
}

/** A WhatsApp "login <username> <password>"; true when it opened a session. */
function waLogin(string $handset, string $user, string $pw): bool
{
    waOn();
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'wa_login%'");
    $r = WaLogin::handle($handset, 'login ' . $user . ' ' . $pw);
    return $r !== null && str_starts_with((string) $r['text'], '✅');
}

/** A test-only trigger (DDL does not go through prepared statements); false = the database refused it. */
function trigger(string $sql): bool
{
    try {
        Database::pdo()->exec($sql);
        return true;
    } catch (Throwable $e) {
        echo "  SKIP  could not create a test trigger: " . $e->getMessage() . "\n";
        return false;
    }
}

function dropTriggers(): void
{
    foreach (TRIGGERS as $t) {
        try { Database::pdo()->exec('DROP TRIGGER IF EXISTS ' . $t); } catch (Throwable $e) {}
    }
}

function hashOf(int $id): string
{
    return (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $id], '');
}

function fresh(): void
{
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%' OR bucket LIKE 'admin_2fa%' OR bucket LIKE 'team_%' OR bucket LIKE 'otp_%' OR bucket LIKE 'wa_verify%'");
    unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION[TeamPortal::SESSION], $_SESSION['admin_2fa_pending'], $_SESSION['team_reset']);
}

/** A real office sign-in; returns the session it left. */
function signIn(string $user, string $pw): array
{
    fresh();
    $r = Auth::adminLogin($user, $pw);
    if (empty($r['ok'])) {
        throw new RuntimeException("sign-in as $user failed: " . json_encode($r));
    }
    return $_SESSION[ADMIN_SESSION_KEY];
}

/** Put this session back in the browser as if its last re-check was $ago seconds ago; the next Auth::admin() answer. */
function resume(array $sess, int $ago = 31): ?array
{
    $_SESSION[ADMIN_SESSION_KEY] = ['checked_at' => time() - $ago, 'last_seen' => time()] + $sess;
    return Auth::admin();
}

/** The stamp of the password on file now (the private helper, so the test does not copy the formula). */
function stampNow(int $id): string
{
    return (string) (new ReflectionMethod(Auth::class, 'adminPwStamp'))->invoke(null, $id);
}

function revokedFor(int $id, string $reason): int
{
    return (int) Database::scalar(
        "SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.session_revoked' AND entity_id = :e AND detail = :d",
        ['e' => (string) $id, 'd' => $reason], 0);
}

/** Office-side password write, exactly what staff.php / an outside change does (no refresh of THIS session). */
function setPw(int $id, string $pw): void
{
    Database::update('admins', ['password_hash' => Security::hashPassword($pw)], 'id = :id', ['id' => $id]);
}

function cleanup(): void
{
    dropTriggers();
    $ids = array_map('intval', array_column(Database::fetchAll('SELECT id FROM admins WHERE username LIKE :p', ['p' => PFX . '%']), 'id'));
    foreach ($ids as $id) {
        try { Auth::setAdmin2fa($id, false, 0); } catch (Throwable $e) {}
        Database::run("DELETE FROM audit_logs WHERE entity_type = 'admin' AND entity_id = :e", ['e' => (string) $id]);
        Database::run('DELETE FROM admin_devices WHERE admin_id = :a', ['a' => $id]);
        Database::run("DELETE FROM otp_codes WHERE identifier = :i", ['i' => 'admin:' . $id]);
        Database::run('DELETE FROM wa_logins WHERE admin_id = :a', ['a' => $id]);
        Database::run("DELETE FROM rate_limits WHERE bucket = 'wa_login_acct' AND identifier = :i", ['i' => (string) $id]);
    }
    foreach ([WA_H1, WA_H2, WA_H3, WA_HTTP1, WA_HTTP2] as $h) {
        $k = WaLogin::key($h);
        Database::run('DELETE FROM wa_logins WHERE phone = :p', ['p' => $k]);
        Database::run("DELETE FROM kv_store WHERE kscope IN ('wa_login_pending','wa_agent','wa_stage','wa_turn','wa_bulk') AND kkey IN (:k, :d)", ['k' => $k, 'd' => normalisePhone($h)]);
        Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'wa_login%' AND identifier = :k", ['k' => $k]);
    }
    Database::run("DELETE FROM otp_codes WHERE identifier IN (:a, :b)", ['a' => 'walogin:' . WA_10, 'b' => 'team:' . WA_INT]);
    Database::run("DELETE FROM audit_logs WHERE entity_type = 'admin' AND entity_id LIKE :p", ['p' => PFX . '%']);
    Database::run('DELETE FROM admin_login_events WHERE username LIKE :p', ['p' => PFX . '%']);
    Database::run("DELETE FROM otp_codes WHERE identifier = :i", ['i' => 'team:' . AG_INT]);
    Database::run('DELETE FROM admins WHERE username LIKE :p', ['p' => PFX . '%']);
    Settings::flush();
}

echo "\n=== Office sessions end when the password changes (review #11) ===\n\n";

$idA = $idBoss = $idAg = 0;
try {
    cleanup();
    $mk = static fn(string $u, string $pw, string $role, ?string $phone = null, int $must = 0): int => (int) Database::insert('admins', [
        'username' => $u, 'password_hash' => Security::hashPassword($pw), 'full_name' => 'ZZT ' . $u,
        'email' => $u . '@test.local', 'phone' => $phone, 'role' => $role, 'permissions' => '[]',
        'is_active' => 1, 'must_change_pw' => $must, 'created_at' => date('Y-m-d H:i:s'),
    ]);
    $idA    = $mk(U_A, 'Mgr#pass2026', 'manager', '919812300791');
    $idBoss = $mk(U_BOSS, 'Boss#pass2026', 'superadmin');
    $idAg   = $mk(U_AG, 'Old#stamp2026', 'agent', '+977 ' . AG_10);
    check('three throwaway accounts exist', $idA > 0 && $idBoss > 0 && $idAg > 0);

    echo "\n== A. a real sign-in carries the stamp ==\n";
    $a1 = signIn(U_A, 'Mgr#pass2026');
    $st = (string) ($a1['pw'] ?? '');
    check('the session has a 16-character stamp', strlen($st) === 16 && ctype_xdigit($st), $st);
    check('… which is the stamp of the password on file', hash_equals(stampNow($idA), $st));
    check('… and is not the hash (or a piece of it)', !str_contains((string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $idA]), $st));
    $a2 = signIn(U_A, 'Mgr#pass2026');
    check('a second sign-in with the same password gets the same stamp', ($a2['pw'] ?? '') === $st);
    check('nothing changed: the 30-second re-check keeps the session', resume($a1) !== null && ($_SESSION[ADMIN_SESSION_KEY]['pw'] ?? '') === $st);

    echo "\n== B. own change: this session stays, the other one ends ==\n";
    resume($a2, 0);
    setPw($idA, 'Mgr#pass2027');                 // what change-password.php writes …
    Auth::refreshOwnPwStamp($idA);                // … and then this
    $a2 = $_SESSION[ADMIN_SESSION_KEY];
    check('this session took the new stamp', ($a2['pw'] ?? '') === stampNow($idA) && $a2['pw'] !== $st);
    check('this session survives the re-check', resume($a2) !== null);
    $before = revokedFor($idA, 'password changed');
    check('within 30 s the other session is still trusted (no query per click)', resume($a1, 5) !== null);
    check('after 30 s the other session is signed out', resume($a1) === null && !isset($_SESSION[ADMIN_SESSION_KEY]));
    check('… audited as admin.session_revoked "password changed"', revokedFor($idA, 'password changed') === $before + 1);
    check('the new password still signs in normally', (signIn(U_A, 'Mgr#pass2027')['id'] ?? 0) === $idA);

    echo "\n== C. an office reset ends their sessions, not the office's ==\n";
    $a3   = signIn(U_A, 'Mgr#pass2027');
    $boss = signIn(U_BOSS, 'Boss#pass2026');
    Auth::setStaffCredentials($idA, ['password' => 'Mgr#pass2028'], $idBoss);
    $boss = $_SESSION[ADMIN_SESSION_KEY];
    check('the office session that did the reset carries on', resume($boss) !== null);
    check('the reset account\'s open session is signed out', resume($a3) === null);
    $boss2 = signIn(U_BOSS, 'Boss#pass2026');     // the office on a second device
    resume($boss, 0);
    Auth::setStaffCredentials($idBoss, ['password' => 'Boss#pass2027'], $idBoss);
    $boss = $_SESSION[ADMIN_SESSION_KEY];
    check('changing your OWN password in Staff keeps this session', resume($boss) !== null);
    check('… and ends your other device', resume($boss2) === null);
    $a4 = signIn(U_A, 'Mgr#pass2028');
    resume($boss, 0);
    Auth::refreshOwnPwStamp($idA);               // called for somebody else: must not touch this session
    check('refreshOwnPwStamp for another account leaves this session alone', ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) === $idBoss && ($_SESSION[ADMIN_SESSION_KEY]['pw'] ?? '') === $boss['pw']);

    echo "\n== D. a session from before the stamp is upgraded, not signed out ==\n";
    $old = $a4;
    unset($old['pw']);
    $beforeAll = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.session_revoked' AND entity_id = :e", ['e' => (string) $idA], 0);
    check('a stampless session passes its re-check', resume($old) !== null);
    $old = $_SESSION[ADMIN_SESSION_KEY];
    check('… and now carries the current stamp', ($old['pw'] ?? '') === stampNow($idA));
    check('… with no revocation written', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.session_revoked' AND entity_id = :e", ['e' => (string) $idA], 0) === $beforeAll);
    setPw($idA, 'Mgr#pass2029');
    check('once stamped, a later password change ends it like any other', resume($old) === null);
    $hand = ['id' => $idA, 'username' => U_A, 'role' => 'manager', 'permissions' => [], 'last_seen' => time()];
    $_SESSION[ADMIN_SESSION_KEY] = $hand;
    check('a hand-built session (no checked_at) is still left as written', (Auth::admin()['id'] ?? 0) === $idA && !isset($_SESSION[ADMIN_SESSION_KEY]['pw']));

    echo "\n== E. a team-portal reset: the new session stays, older ones end ==\n";
    if (!TeamPortal::ready()) {
        check('team portal tables present (run database/upgrade-2026-10-22-team-login.sql)', false);
    } else {
        fresh();
        $r = TeamPortal::login(AG_10, 'Old#stamp2026', '977');
        $t1 = $_SESSION[ADMIN_SESSION_KEY] ?? [];
        check('the agent signs in at /team/ (office session)', !empty($r['ok']) && ($r['kind'] ?? '') === 'admin' && (int) ($t1['id'] ?? 0) === $idAg && ($t1['pw'] ?? '') === stampNow($idAg), json_encode($r));
        fresh();
        $c = (string) (Auth::issueOtp('team:' . AG_INT, 'reset', 'whatsapp')['code'] ?? '');
        $r = TeamPortal::resetPassword(AG_10, $c, 'New#stamp2026', 'New#stamp2026');
        $t2 = $_SESSION[ADMIN_SESSION_KEY] ?? [];
        check('a WhatsApp-code reset opens a session', !empty($r['ok']) && ($r['kind'] ?? '') === 'admin' && (int) ($t2['id'] ?? 0) === $idAg, json_encode($r));
        check('… stamped with the NEW password', ($t2['pw'] ?? '') === stampNow($idAg) && ($t2['pw'] ?? '') !== ($t1['pw'] ?? ''));
        check('the reset session survives its re-check', resume($t2) !== null);
        check('the session opened before the reset is signed out', resume($t1) === null);
        check('… audited "password changed"', revokedFor($idAg, 'password changed') >= 1);

        echo "\n== F. TeamPortal::changePassword (account): own session stays, others end ==\n";
        fresh();
        TeamPortal::login(AG_10, 'New#stamp2026', '977');
        $t3 = $_SESSION[ADMIN_SESSION_KEY] ?? [];
        resume($t2, 0);
        $me = TeamPortal::current();
        $okChange = true;
        try {
            TeamPortal::changePassword($me, 'New#stamp2026', 'Newer#stamp2026', 'Newer#stamp2026');
        } catch (Throwable $e) {
            $okChange = false;
        }
        $t2 = $_SESSION[ADMIN_SESSION_KEY] ?? [];
        check('the change goes through', $okChange && $me !== null && $me['via'] === 'admin');
        check('the session that changed it carries on', resume($t2) !== null && ($_SESSION[ADMIN_SESSION_KEY]['pw'] ?? '') === stampNow($idAg));
        check('the agent\'s other session is signed out', resume($t3) === null);
    }

    echo "\n== G. the 2FA door still works, and its session is stamped ==\n";
    fresh();
    Auth::setAdmin2fa($idA, true, $idA);
    Settings::flush();
    $_SESSION['admin_2fa_pending'] = ['id' => $idA, 'ip' => '', 'at' => time()];
    $iss = Auth::issueOtp('admin:' . $idA, 'login', 'whatsapp');   // minted, never sent
    $v = Auth::adminLogin2faVerify((string) ($iss['code'] ?? ''));
    $g = $_SESSION[ADMIN_SESSION_KEY] ?? [];
    check('the right code signs in', !empty($v['ok']) && (int) ($g['id'] ?? 0) === $idA, json_encode($v));
    check('… as a password sign-in (not via_otp)', ($g['via_otp'] ?? true) === false);
    check('… with the stamp of the password on file', ($g['pw'] ?? '') === stampNow($idA));
    check('… which survives its re-check', resume($g) !== null);
    setPw($idA, 'Mgr#pass2030');
    check('… and ends when the password changes elsewhere', resume($g) === null);
    Auth::setAdmin2fa($idA, false, $idA);
    Settings::flush();

    echo "\n== H. one place opens an office session, and it stamps it ==\n";
    $writers = [];
    $skip = ['tests', 'vendor', 'node_modules', '.git', '.claude', 'assets', 'uploads', 'logs', 'backup', 'docs', 'design', 'tickets', 'invoice', 'qr', 'live'];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS),
        static fn(SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), $skip, true))
    ));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', (string) $f);
        if (!str_ends_with($p, '.php')) {
            continue;
        }
        if (preg_match('/\$_SESSION\[ADMIN_SESSION_KEY\]\s*=(?!=)/', (string) file_get_contents($p))) {
            $writers[] = substr($p, strlen(str_replace('\\', '/', dirname(__DIR__))) + 1);
        }
    }
    check('only includes/auth.php writes a whole office session', $writers === ['includes/auth.php'], implode(', ', $writers));
    $auth = (string) file_get_contents(INCLUDE_PATH . '/auth.php');
    // re-attack: the stamp is the one the door hands over ($pwStamp), the row is read only to compare
    check('finishAdminSession puts the stamp in it', (bool) preg_match("/'pw'\s*=>\s*\\\$stamp,/", $auth) && str_contains($auth, '$stamp   = $pwStamp ?? $current;'));
    check('change-password.php keeps the changer signed in', str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/change-password.php'), 'Auth::passwordChanged('));
    check('TeamPortal::changePassword keeps the changer signed in', str_contains((string) file_get_contents(INCLUDE_PATH . '/teamportal.php'), 'Auth::passwordChanged((int) $me[\'admin\'][\'id\']'));

    echo "\n== J. the stamp is the password the door CHECKED (a reset in the same instant) ==\n";
    /* A test trigger plays the office: it swaps the password the moment the
       door writes last_login_at — after the password was checked, before the
       session is written. That is the window a real reset can land in (about
       50 ms of bcrypt on the live box). Before the fix the session took the
       NEW password's stamp there and was never signed out. */
    $oldHash = Security::hashPassword('Race#old2026');
    $newHash = Security::hashPassword('Race#new2026');
    $idRO = $mk(U_RACE_O, 'unused-pw', 'manager');
    $idRT = $mk(U_RACE_T, 'unused-pw', 'agent', '+977 ' . RT_10);
    $idRA = $mk(U_RACE_A, 'unused-pw', 'agent');
    Database::run('UPDATE admins SET password_hash = :h WHERE username LIKE :p', ['h' => $oldHash, 'p' => PFX . 'race-%']);
    if (trigger("CREATE TRIGGER zzt_pwstamp_race BEFORE UPDATE ON admins FOR EACH ROW SET NEW.password_hash = "
        . "IF(OLD.username LIKE 'zzt-pwstamp-race-%' AND OLD.password_hash = '$oldHash' AND NOT (NEW.last_login_at <=> OLD.last_login_at), '$newHash', NEW.password_hash)")) {
        $doors = [
            'office login (Auth::adminLogin)'       => [$idRO, static fn(): array => Auth::adminLogin(U_RACE_O, 'Race#old2026')],
            'team door (TeamPortal::login)'         => [$idRT, static fn(): array => TeamPortal::ready() ? TeamPortal::login(RT_10, 'Race#old2026', '977') : ['ok' => false, 'error' => 'team tables missing']],
            'agent door (Auth::agentPasswordLogin)' => [$idRA, static fn(): array => Auth::agentPasswordLogin(U_RACE_A . '@test.local', U_RACE_A, 'Race#old2026')],
        ];
        foreach ($doors as $label => [$rid, $door]) {
            fresh();
            $r = $door();
            $s = $_SESSION[ADMIN_SESSION_KEY] ?? [];
            check("$label: the old password was right when checked, and the reset landed", !empty($r['ok']) && (int) ($s['id'] ?? 0) === $rid && hashOf($rid) === $newHash, json_encode($r));
            check('  the session carries the stamp of the password it CHECKED, not the new one', ($s['pw'] ?? '') === Auth::pwStamp($oldHash) && ($s['pw'] ?? '') !== stampNow($rid));
            check('  and is signed out on its very next request (no 30 s grace)', Auth::admin() === null && !isset($_SESSION[ADMIN_SESSION_KEY]));
            check('  audited "password changed"', revokedFor($rid, 'password changed') === 1);
            fresh();
            $again = $door();
            check('  the old password no longer signs in at all', empty($again['ok']) && !isset($_SESSION[ADMIN_SESSION_KEY]), json_encode($again));
        }
        dropTriggers();
        $ok = signIn(U_RACE_O, 'Race#new2026');
        check('a sign-in with nothing in between is checked on the normal 30 s clock', ($ok['checked_at'] ?? 0) >= time() - 2 && Auth::admin() !== null);
    }
    dropTriggers();

    echo "\n== K. 2FA: a reset between the password and the code ==\n";
    fresh();
    Auth::setAdmin2fa($idA, true, $idA);
    Settings::flush();
    // what adminLogin() leaves at step 1 — built by hand, because the real step 1 sends the code on WhatsApp
    $_SESSION['admin_2fa_pending'] = ['id' => $idA, 'ip' => '', 'at' => time(), 'pw' => Auth::pwStamp(hashOf($idA))];
    setPw($idA, 'Mgr#pass2031');                 // the office resets the password during the 10 minutes
    $iss = Auth::issueOtp('admin:' . $idA, 'login', 'whatsapp');   // minted, never sent
    $v = Auth::adminLogin2faVerify((string) ($iss['code'] ?? ''));
    check('the right code is refused once the password changed', empty($v['ok']) && str_contains((string) ($v['error'] ?? ''), 'password was changed'), json_encode($v));
    check('  no session was opened, and the pending step is gone', !isset($_SESSION[ADMIN_SESSION_KEY]) && empty($_SESSION['admin_2fa_pending']));
    check('  audited admin.2fa.stale_password', Database::exists(
        "SELECT 1 FROM audit_logs WHERE action = 'admin.2fa.stale_password' AND entity_id = :e", ['e' => (string) $idA]));
    fresh();
    $_SESSION['admin_2fa_pending'] = ['id' => $idA, 'ip' => '', 'at' => time(), 'pw' => Auth::pwStamp(hashOf($idA))];
    $iss = Auth::issueOtp('admin:' . $idA, 'login', 'whatsapp');
    $v = Auth::adminLogin2faVerify((string) ($iss['code'] ?? ''));
    check('with the password unchanged the code signs in, stamped with that password',
        !empty($v['ok']) && ($_SESSION[ADMIN_SESSION_KEY]['pw'] ?? '') === stampNow($idA) && resume($_SESSION[ADMIN_SESSION_KEY]) !== null, json_encode($v));
    $p0 = strpos($auth, "\$_SESSION['admin_2fa_pending'] = [");
    $p1 = $p0 === false ? false : strpos($auth, '];', $p0);
    check('adminLogin() writes the checked password\'s stamp into the pending step', $p0 !== false && $p1 !== false
        && str_contains(substr($auth, $p0, $p1 - $p0), "'pw' => self::pwStamp((string) \$admin['password_hash'])"));
    Auth::setAdmin2fa($idA, false, $idA);
    Settings::flush();

    echo "\n== L. WhatsApp staff sign-ins end when the password changes ==\n";
    $idWa = $mk(U_WA, 'Wa#stamp2026', 'agent', '+977 ' . WA_10);
    fresh();
    check('an agent signs in over WhatsApp from a handset not on file', waLogin(WA_H1, U_WA, 'Wa#stamp2026') && waLive(WA_H1, $idWa));
    Auth::setStaffCredentials($idWa, ['password' => 'Wa#stamp2027'], $idBoss);
    check('an office reset (Staff / agent credentials) ends it at once', !waLive(WA_H1, $idWa));
    check('  the row remembers who reset it', (int) Database::scalar(
        'SELECT revoked_by FROM wa_logins WHERE phone = :p AND admin_id = :a ORDER BY id DESC LIMIT 1', ['p' => WaLogin::key(WA_H1), 'a' => $idWa], 0) === $idBoss);
    check('  the old password cannot open a new one', !waLogin(WA_H1, U_WA, 'Wa#stamp2026') && !waLive(WA_H1, $idWa));
    if (TeamPortal::ready()) {
        check('signed in again with the new password', waLogin(WA_H1, U_WA, 'Wa#stamp2027') && waLive(WA_H1, $idWa));
        fresh();
        $r = TeamPortal::login(WA_10, 'Wa#stamp2027', '977');
        $me = TeamPortal::current();
        $okChange = true;
        try {
            TeamPortal::changePassword((array) $me, 'Wa#stamp2027', 'Wa#stamp2028', 'Wa#stamp2028');
        } catch (Throwable $e) {
            $okChange = false;
        }
        check('the agent changes the password at /team/', !empty($r['ok']) && $okChange, json_encode($r));
        check('  that ends the WhatsApp sign-in', !waLive(WA_H1, $idWa));
        check('  while the browser that changed it stays signed in', resume($_SESSION[ADMIN_SESSION_KEY] ?? []) !== null);
        check('signed in again over WhatsApp', waLogin(WA_H1, U_WA, 'Wa#stamp2028') && waLive(WA_H1, $idWa));
        fresh();
        $c = (string) (Auth::issueOtp('team:' . WA_INT, 'reset', 'whatsapp')['code'] ?? '');   // minted, never sent
        $r = TeamPortal::resetPassword(WA_10, $c, 'Wa#stamp2029', 'Wa#stamp2029');
        check('a team-portal reset with a WhatsApp code', !empty($r['ok']), json_encode($r));
        check('  ends the WhatsApp sign-in too', !waLive(WA_H1, $idWa));
    } else {
        check('team portal tables present (run database/upgrade-2026-10-22-team-login.sql)', false);
        setPw($idWa, 'Wa#stamp2029');
    }

    /* In flight: the password is checked, then the office resets it before
       the row is written — a trigger on wa_logins plays the office. */
    $cur = hashOf($idWa);
    $waNew = Security::hashPassword('Wa#stamp2030');
    if (trigger("CREATE TRIGGER zzt_pwstamp_wa AFTER INSERT ON wa_logins FOR EACH ROW UPDATE admins SET password_hash = '$waNew' "
        . "WHERE id = NEW.admin_id AND username = '" . U_WA . "' AND password_hash = '$cur'")) {
        check('a WhatsApp sign-in whose password is reset mid-way opens nothing', !waLogin(WA_H2, U_WA, 'Wa#stamp2029') && hashOf($idWa) === $waNew);
        check('  no live row is left for that handset', (int) Database::scalar(
            'SELECT COUNT(*) FROM wa_logins WHERE phone = :p AND revoked_at IS NULL', ['p' => WaLogin::key(WA_H2)], 0) === 0 && !waLive(WA_H2, $idWa));
        dropTriggers();
    }
    dropTriggers();
    setPw($idWa, 'Wa#stamp2031');

    /* The code step: a pending sign-in made with the old password (what
       login() leaves when a code goes to the registered mobile — planted by
       hand, since the real one sends it on WhatsApp). */
    $plant = static function (?string $pw) use ($idWa): void {
        $k = WaLogin::key(WA_H3);
        Database::run("DELETE FROM kv_store WHERE kscope = 'wa_login_pending' AND kkey = :k", ['k' => $k]);
        Database::insert('kv_store', ['kscope' => 'wa_login_pending', 'kkey' => $k, 'updated_by' => 'walogin',
            'kvalue' => (string) json_encode(['adminId' => $idWa, 'registered' => WA_10, 'at' => time()] + ($pw !== null ? ['pw' => $pw] : []))]);
        Database::insert('otp_codes', ['identifier' => 'walogin:' . WA_10, 'channel' => 'whatsapp', 'purpose' => 'login',
            'code_hash' => password_hash('246810', PASSWORD_BCRYPT), 'expires_at' => date('Y-m-d H:i:s', time() + 300), 'ip_address' => '127.0.0.1']);
    };
    $otpIn = static function (): bool {
        waOn();
        $r = WaLogin::handle(WA_H3, 'otp 246810');
        return $r !== null && str_starts_with((string) $r['text'], '✅');
    };
    $plant(Auth::pwStamp(hashOf($idWa)));
    check('control: a pending code with the password still on file signs in', $otpIn() && waLive(WA_H3, $idWa));
    WaLogin::revokePhone(WA_H3, null);
    $plant(Auth::pwStamp(hashOf($idWa)));
    setPw($idWa, 'Wa#stamp2032');
    check('a pending code whose password was changed meanwhile opens nothing', !$otpIn() && !waLive(WA_H3, $idWa));
    $plant(null);
    check('a pending code from before this fix (no password stamp) opens nothing', !$otpIn() && !waLive(WA_H3, $idWa));

    echo "\n== M. Auth::passwordChanged on this browser ==\n";
    $m = signIn(U_BOSS, 'Boss#pass2027');
    $sid = session_id();
    $was = hashOf($idBoss);
    setPw($idBoss, 'Boss#pass2028');
    Auth::passwordChanged($idBoss, $idBoss, $was);
    $m = $_SESSION[ADMIN_SESSION_KEY] ?? [];
    check('own change: this session takes the new stamp', ($m['pw'] ?? '') === stampNow($idBoss));
    check('  and a new session id (a copied cookie stays behind)', $sid !== '' && session_id() !== '' && session_id() !== $sid, $sid . ' -> ' . session_id());
    check('  and survives its re-check', resume($m) !== null);
    $stale = $_SESSION[ADMIN_SESSION_KEY];
    setPw($idBoss, 'Boss#pass2029');             // changed somewhere else; this session has not re-checked yet …
    $mid = hashOf($idBoss);
    setPw($idBoss, 'Boss#pass2030');             // … and inside its 30 s it resets its OWN password again (Staff)
    resume($stale, 0);
    Auth::passwordChanged($idBoss, $idBoss, $mid);
    check('a session already out of date does NOT adopt the new password', ($_SESSION[ADMIN_SESSION_KEY]['pw'] ?? '') === $stale['pw']);
    check('  and ends on its re-check like the others', resume($_SESSION[ADMIN_SESSION_KEY] ?? $stale) === null);

    echo "\n== N. every password write calls Auth::passwordChanged ==\n";
    $gaps = [];
    $root = str_replace('\\', '/', dirname(__DIR__));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', (string) $f);
        if (!str_ends_with($p, '.php')) {
            continue;
        }
        $src = (string) file_get_contents($p);
        $writes = preg_match_all("/Database::update\(\s*'admins'\s*,\s*\[[^\]]*'password_hash'/s", $src);
        $calls  = substr_count($src, 'passwordChanged(') - substr_count($src, 'function passwordChanged(');
        if ($writes > $calls) {
            $gaps[] = substr($p, strlen($root) + 1) . " ($writes writes, $calls calls)";
        }
    }
    check('no file writes admins.password_hash without calling passwordChanged', $gaps === [], implode('; ', $gaps));
    check('Auth::setStaffCredentials calls it with the old hash', str_contains($auth, "self::passwordChanged(\$adminId, \$by, (string) \$row['password_hash'])"));
    check('Staff → reset password calls it', str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/staff.php'), 'Auth::passwordChanged($id, (int) $admin[\'id\'], (string) $target[\'password_hash\'])'));
    $tp = (string) file_get_contents(INCLUDE_PATH . '/teamportal.php');
    check('the team reset hands the NEW hash to the session it opens', str_contains($tp, "'password_hash' => \$hash] + \$open"));
    check('a staff-portal session is stamped with the hash its door checked (not re-read)', !preg_match('/function openStaffSession\([^)]*\)[^{]*\{[^}]*SELECT password_hash/s', $tp)
        && str_contains($tp, "self::openStaffSession((int) \$s['id'], (string) \$s['password_hash'])"));
    $wl = (string) file_get_contents(INCLUDE_PATH . '/walogin.php');
    check('WaLogin::open re-reads the password AFTER writing the row', ($ins = strpos($wl, "Database::insert('wa_logins'")) !== false
        && ($re = strpos($wl, 'SELECT password_hash FROM admins WHERE id = :id', $ins)) !== false && $re - $ins < 600);

    echo "\n== I. over HTTP: the real change-password.php, two browsers each ==\n";
    $base = rtrim((string) getenv('SHG_TEST_BASE'), '/');
    if ($base === '' || !function_exists('curl_init')) {
        echo "  SKIP  set SHG_TEST_BASE (and load curl) to run the HTTP part\n";
    } else {
        $idH1 = $mk(U_H1, 'Http#pass2026', 'manager');
        $idH2 = $mk(U_H2, 'Temp#pass2026', 'support', null, 1);
        // re-attack: a live WhatsApp sign-in for each (planted rows: an office role needs a code to open one for real)
        $waRow = static fn(string $h, int $id): int => (int) Database::insert('wa_logins', ['phone' => WaLogin::key($h), 'admin_id' => $id,
            'method' => 'password+otp', 'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'last_seen_at' => date('Y-m-d H:i:s')]);
        $waH1 = $waRow(WA_HTTP1, $idH1);
        $waH2 = $waRow(WA_HTTP2, $idH2);
        $waOpen = static fn(int $row): bool => Database::scalar('SELECT revoked_at FROM wa_logins WHERE id = :i', ['i' => $row], 'gone') === null;
        check('each account also has a live WhatsApp sign-in', $waOpen($waH1) && $waOpen($waH2));
        $jars = [];
        $http = static function (string $jar, string $path, ?array $form = null) use ($base, &$jars): array {
            $jars[$jar] ??= sys_get_temp_dir() . '/shg_pwstamp_' . getmypid() . '_' . $jar . '.txt';
            $ch = curl_init($base . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_COOKIEJAR => $jars[$jar], CURLOPT_COOKIEFILE => $jars[$jar], CURLOPT_TIMEOUT => 30]);
            if ($form !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
            }
            $raw = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hl = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            $loc = preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $hl), $m) ? trim($m[1]) : '';
            return ['code' => $code, 'body' => substr($raw, $hl), 'location' => $loc];
        };
        $csrf = static fn(string $html): string => preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
        $login = static function (string $jar, string $u, string $pw) use ($http, $csrf): bool {
            fresh();
            $p = $http($jar, '/admin/login.php');
            $r = $http($jar, '/admin/login.php', [CSRF_TOKEN_NAME => $csrf($p['body']), 'username' => $u, 'password' => $pw]);
            return $r['code'] === 302;
        };
        check('voluntary: two browsers sign in to one account', $login('a', U_H1, 'Http#pass2026') && $login('b', U_H1, 'Http#pass2026')
            && $http('a', '/admin/index.php')['code'] === 200 && $http('b', '/admin/index.php')['code'] === 200);
        $f = $http('b', '/admin/change-password.php');
        $r = $http('b', '/admin/change-password.php', [CSRF_TOKEN_NAME => $csrf($f['body']),
            'current_password' => 'Http#pass2026', 'new_password' => 'Http#pass2027', 'confirm_password' => 'Http#pass2027']);
        check('browser B changes the password', stripos($r['body'], 'has been changed') !== false);
        check('forced: two browsers sign in with the temporary password', $login('c', U_H2, 'Temp#pass2026') && $login('d', U_H2, 'Temp#pass2026')
            && str_contains($http('c', '/admin/index.php')['location'], 'change-password.php'));
        $f = $http('d', '/admin/change-password.php');
        $r = $http('d', '/admin/change-password.php', [CSRF_TOKEN_NAME => $csrf($f['body']),
            'current_password' => 'Temp#pass2026', 'new_password' => 'Real#pass2026', 'confirm_password' => 'Real#pass2026']);
        check('browser D makes the forced change', stripos($r['body'], 'has been changed') !== false);
        check('both changes ended the account\'s WhatsApp sign-in at once (no 30 s wait)', !$waOpen($waH1) && !$waOpen($waH2));
        echo "  …   waiting 31 s for the re-check window\n";
        sleep(31);
        $ra = $http('a', '/admin/index.php');
        $rb = $http('b', '/admin/index.php');
        $rc = $http('c', '/admin/index.php');
        $rd = $http('d', '/admin/index.php');
        check('voluntary: browser A is sent to the login page', $ra['code'] === 302 && str_contains($ra['location'], 'login.php'), 'HTTP ' . $ra['code'] . ' ' . $ra['location']);
        check('voluntary: browser B (who changed it) still opens the dashboard', $rb['code'] === 200, 'HTTP ' . $rb['code'] . ' ' . $rb['location']);
        check('forced: browser C is sent to the login page', $rc['code'] === 302 && str_contains($rc['location'], 'login.php'), 'HTTP ' . $rc['code'] . ' ' . $rc['location']);
        check('forced: browser D (who changed it) opens the dashboard', $rd['code'] === 200, 'HTTP ' . $rd['code'] . ' ' . $rd['location']);
        $h1 = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => U_H1], 0);
        check('the server audited the revocation', revokedFor($h1, 'password changed') >= 1);
        foreach ($jars as $j) {
            @unlink($j);
        }
    }
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    fresh();
    cleanup();
    $left = (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE username LIKE :p', ['p' => PFX . '%'], 0);
    check('clean-up left no zzt-pwstamp-* accounts', $left === 0);
}

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n";
ob_end_flush();
exit($FAIL === 0 ? 0 : 1);
