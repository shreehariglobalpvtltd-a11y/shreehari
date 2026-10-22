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
 *      voluntary and a forced change, two browsers each, 31 s apart.
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
    $ids = array_map('intval', array_column(Database::fetchAll('SELECT id FROM admins WHERE username LIKE :p', ['p' => PFX . '%']), 'id'));
    foreach ($ids as $id) {
        try { Auth::setAdmin2fa($id, false, 0); } catch (Throwable $e) {}
        Database::run("DELETE FROM audit_logs WHERE entity_type = 'admin' AND entity_id = :e", ['e' => (string) $id]);
        Database::run('DELETE FROM admin_devices WHERE admin_id = :a', ['a' => $id]);
        Database::run("DELETE FROM otp_codes WHERE identifier = :i", ['i' => 'admin:' . $id]);
    }
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
    check('finishAdminSession puts the stamp in it', (bool) preg_match("/'pw'\s*=>\s*self::adminPwStamp/", $auth));
    check('change-password.php keeps the changer signed in', str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/change-password.php'), 'Auth::refreshOwnPwStamp('));
    check('TeamPortal::changePassword keeps the changer signed in', str_contains((string) file_get_contents(INCLUDE_PATH . '/teamportal.php'), 'Auth::refreshOwnPwStamp('));

    echo "\n== I. over HTTP: the real change-password.php, two browsers each ==\n";
    $base = rtrim((string) getenv('SHG_TEST_BASE'), '/');
    if ($base === '' || !function_exists('curl_init')) {
        echo "  SKIP  set SHG_TEST_BASE (and load curl) to run the HTTP part\n";
    } else {
        $mk(U_H1, 'Http#pass2026', 'manager');
        $mk(U_H2, 'Temp#pass2026', 'support', null, 1);
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
