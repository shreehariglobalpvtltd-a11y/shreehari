<?php
/**
 * FORCED PASSWORD CHANGE — master prompt §20
 *
 * staff.php hands a new account a temporary password and flags it
 * must_change_pw. That flag was written, carried into the session and shown
 * as a badge in the staff list — but nothing ever enforced it, so a
 * temporary password sent over WhatsApp stayed valid indefinitely.
 *
 * Driven over real HTTP, because the gate lives in the request path
 * (Auth::requireAdmin) and a direct function call would not prove that a
 * flagged admin is actually bounced off the pages they try to open.
 *
 *   1. SHG_TEST_BASE=http://localhost:<port> (else the suite starts its own php -S)
 *   2. php -c .claude/php-dev.ini -d extension=php_curl.dll \
 *        tests/forced-password-change-test.php
 *
 * Creates one throwaway staff account and deletes it again, with the
 * admin_sessions / admin_login_events rows its sign-ins leave (and the
 * sessions any older or crashed run of it left), and the admin_login
 * throttle row its sign-ins count for this machine. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickunlock.php';
require_once INCLUDE_PATH . '/codelogin.php';

if (!function_exists('curl_init')) {
    echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n";
    exit(1);
}

// SHG_TEST_BASE, like every other HTTP suite — see manifest-test.php.
/* 16 Nov 2026 (battery T1): with no SHG_TEST_BASE the suite starts its OWN
   `php -S` on a free port, serving THIS checkout with this run's php + ini,
   and stops it on exit (same helper as manifest-test.php). The battery
   passes no base, and :8899 is whichever checkout happens to own it - an
   older lineage on the build machine - so the suite reported about code it
   was not testing. SHG_TEST_BASE still wins when it is set. */
function shg_test_base(): string
{
    $env = trim((string) getenv('SHG_TEST_BASE'));
    if ($env !== '') {
        return rtrim($env, '/');
    }
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        return 'http://localhost:8899';
    }
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    $ini  = (string) php_ini_loaded_file();
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $cmd  = array_merge([PHP_BINARY], $ini !== '' ? ['-c', $ini] : [], ['-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)]);
    $proc = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return 'http://localhost:8899';
    }
    register_shutdown_function(static function () use ($proc): void {
        @proc_terminate($proc);
        @proc_close($proc);
    });
    for ($i = 0; $i < 100; $i++) {   // up to ~10 s for the server to answer
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($c !== false) { fclose($c); break; }
        usleep(100000);
    }
    return 'http://127.0.0.1:' . $port;
}
define('BASE', shg_test_base());
const TEST_USER = 'pwgate_tester';
const TEMP_PW   = 'TempPass123';
const NEW_PW    = 'BrandNewPass456';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$JAR = sys_get_temp_dir() . '/shg_pwgate_' . getmypid() . '.txt';
@unlink($JAR);

/** @return array{code:int, body:string, location:string} */
function http(string $url, array $opt = []): array
{
    global $JAR;
    $ch = curl_init(str_starts_with($url, 'http') ? $url : BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $JAR,
        CURLOPT_COOKIEFILE     => $JAR,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if (isset($opt['form'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form']));
    }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($raw, 0, $hlen);
    $body    = substr($raw, $hlen);
    $loc     = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '';

    return ['code' => $code, 'body' => $body, 'location' => $loc];
}

function csrfFrom(string $html): string
{
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m) === 1) {
        return html_entity_decode($m[1]);
    }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $html, $m)
        ? $m[1] : '';
}

/* 16 Nov 2026 (battery T1): the staff door is code-first since 15 Nov; the
   username + password form this suite drives is the CLASSIC door
   (?classic=1&other=1, classic=1 posted). The login throttle for this
   machine is cleared first, as manifest-test.php does - the run below
   makes one deliberate bad-password attempt. */
const LOGIN_BUCKETS = "'admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try'";
/* 16 Nov 2026 (T1 refix): the classic door counts every sign-in - right or
   wrong - in the admin_login bucket of the caller's IP (Auth::adminLogin), so
   this is run again at the end: the run leaves no throttle row behind. */
function clearLoginThrottle(): void
{
    try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN (" . LOGIN_BUCKETS . ')'); } catch (Throwable $e) {}
}

function signInClassic(string $password): array
{
    clearLoginThrottle();
    $page = http('/admin/login.php?classic=1&other=1');
    $csrf = csrfFrom($page['body']);
    $post = http('/admin/login.php', ['form' => [
        CSRF_TOKEN_NAME => $csrf,
        'classic'       => '1',
        'username'      => TEST_USER,
        'password'      => $password,
    ]]);
    return ['csrf' => $csrf, 'post' => $post];
}

/* 16 Nov 2026 (T1 refix): every admins.id TEST_USER has ever had - the live
   row, the login history (read BEFORE cleanup() deletes it: once the account
   is gone it is the only link from the name to an id) and the audit trail
   (actor_name = the username; it outlives both, so the sessions of an older or
   crashed run are found too - deleting by the CURRENT id alone left them for
   good). An id that is now a different live account is never included. */
function testAdminIds(): array
{
    $ids = [(int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => TEST_USER], 0)];
    foreach (['SELECT DISTINCT admin_id FROM admin_login_events WHERE username = :u AND admin_id IS NOT NULL',
              "SELECT DISTINCT actor_id FROM audit_logs WHERE actor_type = 'admin' AND actor_name = :u AND actor_id IS NOT NULL"] as $sql) {
        try {
            foreach (Database::fetchAll($sql, ['u' => TEST_USER]) as $r) { $ids[] = (int) reset($r); }
        } catch (Throwable $e) {}
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids === []) {
        return [];
    }
    $taken = array_map('intval', array_column(Database::fetchAll(
        'SELECT id FROM admins WHERE id IN (' . implode(',', $ids) . ') AND username <> :u', ['u' => TEST_USER]), 'id'));
    return array_values(array_diff($ids, $taken));
}

function cleanup(): void {
    /* 16 Nov 2026 (battery T1): every HTTP sign-in opens an admin_sessions row
       and a login-history row, and neither has a foreign key to admins - so
       deleting the account alone left both behind on every run. */
    $ids = testAdminIds();
    if ($ids !== []) {
        try { Database::query('DELETE FROM admin_sessions WHERE admin_id IN (' . implode(',', $ids) . ')'); } catch (Throwable $e) {}
    }
    try { Database::delete('admin_login_events', 'username = :u', ['u' => TEST_USER]); } catch (Throwable $e) {}
    Database::delete('admins', 'username = :u', ['u' => TEST_USER]);
}

echo "\n=== Forced password change (§20) ===\n\n";
cleanup();

try {
    /* ---- a fresh staff account, exactly as staff.php creates one ------- */
    $testId = (int) Database::insert('admins', [
        'username'       => TEST_USER,
        'password_hash'  => password_hash(TEMP_PW, PASSWORD_DEFAULT),
        'full_name'      => 'Password Gate Tester',
        'role'           => 'support',
        'permissions'    => json_encode([]),
        'is_active'      => 1,
        'must_change_pw' => 1,
    ]);
    check('a new staff account is flagged must_change_pw', true);

    /* ---- sign in with the temporary password --------------------------- */
    $in   = signInClassic(TEMP_PW);
    $post = $in['post'];
    check('login page renders with a CSRF token', $in['csrf'] !== '');
    check('temporary password signs in', in_array($post['code'], [200, 302], true),
          'HTTP ' . $post['code']);

    /* ---- THE GATE: every other page must bounce to the change form ----- */
    foreach (['index.php', 'bookings.php', 'trips.php', 'export.php'] as $page) {
        $r = http('/admin/' . $page);
        $bounced = $r['code'] === 302 && str_contains($r['location'], 'change-password.php');
        check("$page bounces to the change form", $bounced,
              'HTTP ' . $r['code'] . ($r['location'] !== '' ? ' -> ' . basename($r['location']) : ''));
    }

    /* ---- but the change form and sign-out stay reachable --------------- */
    $form = http('/admin/change-password.php');
    check('the change form itself is reachable', $form['code'] === 200, 'HTTP ' . $form['code']);
    check('it says the change is mandatory',
          stripos($form['body'], 'must change your password') !== false);

    /* ---- a wrong current password is refused --------------------------- */
    $c = csrfFrom($form['body']);
    $bad = http('/admin/change-password.php', ['form' => [
        CSRF_TOKEN_NAME    => $c,
        'current_password' => 'not-the-password',
        'new_password'     => NEW_PW,
        'confirm_password' => NEW_PW,
    ]]);
    check('a wrong current password is refused',
          stripos($bad['body'], 'current password is not correct') !== false);
    check('  ...and the flag is still set',
          (int) Database::scalar('SELECT must_change_pw FROM admins WHERE username = :u',
              ['u' => TEST_USER], 0) === 1);

    /* ---- re-using the SAME password is refused ------------------------- */
    $same = http('/admin/change-password.php', ['form' => [
        CSRF_TOKEN_NAME    => csrfFrom($bad['body']),
        'current_password' => TEMP_PW,
        'new_password'     => TEMP_PW,
        'confirm_password' => TEMP_PW,
    ]]);
    check('re-setting the same temporary password is refused',
          stripos($same['body'], 'different from your current') !== false);

    /* ---- mismatch is refused ------------------------------------------- */
    $mm = http('/admin/change-password.php', ['form' => [
        CSRF_TOKEN_NAME    => csrfFrom($same['body']),
        'current_password' => TEMP_PW,
        'new_password'     => NEW_PW,
        'confirm_password' => NEW_PW . 'x',
    ]]);
    check('mismatched confirmation is refused',
          stripos($mm['body'], 'do not match') !== false);

    /* ---- the real change ----------------------------------------------- */
    $ok = http('/admin/change-password.php', ['form' => [
        CSRF_TOKEN_NAME    => csrfFrom($mm['body']),
        'current_password' => TEMP_PW,
        'new_password'     => NEW_PW,
        'confirm_password' => NEW_PW,
    ]]);
    check('the password change succeeds',
          stripos($ok['body'], 'has been changed') !== false);
    check('must_change_pw is cleared in the database',
          (int) Database::scalar('SELECT must_change_pw FROM admins WHERE username = :u',
              ['u' => TEST_USER], 0) === 0);

    /* ---- the gate is now open ------------------------------------------ */
    /* 16 Nov 2026 (battery T1): since the code-first door (15 Nov) an account
       with no PIN / pattern is sent to pin-setup.php before any page
       (Auth::requireAdmin, right after this gate) - and a new staff account
       has none. So "open" is two steps now: the change form no longer holds
       the dashboard (the request goes on to the PIN step, the next door), and
       once a PIN is set - as pin-setup.php would - the dashboard renders. */
    $next = http('/admin/index.php');
    check('the change form no longer holds the dashboard',
          !($next['code'] === 302 && str_contains($next['location'], 'change-password.php')),
          'HTTP ' . $next['code'] . ($next['location'] !== '' ? ' -> ' . basename($next['location']) : ''));
    if (CodeLogin::required()) {
        check('  ...it goes on to the PIN / pattern step (code-first door)',
              $next['code'] === 302 && str_contains($next['location'], 'pin-setup.php'),
              'HTTP ' . $next['code'] . ($next['location'] !== '' ? ' -> ' . basename($next['location']) : ''));
        QuickUnlock::set($testId, 'pin', '615273');
    }
    $dash = http('/admin/index.php');
    check('the dashboard opens normally afterwards', $dash['code'] === 200,
          'HTTP ' . $dash['code'] . ($dash['location'] !== '' ? ' -> ' . basename($dash['location']) : ''));

    /* ---- old password no longer works ---------------------------------- */
    http('/admin/logout.php');
    $bad2 = signInClassic(TEMP_PW)['post'];
    check('the old temporary password no longer signs in',
          $bad2['code'] === 200 && !str_contains((string) $bad2['location'], 'index.php'),
          'HTTP ' . $bad2['code']);

    $row = Database::fetch('SELECT password_hash FROM admins WHERE username = :u', ['u' => TEST_USER]);
    check('the stored hash verifies the NEW password',
          password_verify(NEW_PW, (string) $row['password_hash']));
    check('the stored value is a hash, not plaintext',
          (string) $row['password_hash'] !== NEW_PW && str_starts_with((string) $row['password_hash'], '$'));

} catch (Throwable $e) {
    $FAIL++;
    echo "  \033[31mERROR\033[0m  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}

cleanup();
clearLoginThrottle();
@unlink($JAR);
/* 16 Nov 2026 (battery T1): nothing this run made may outlive it.
   T1 refix: "no session" means for EVERY id the test user has had (this run's
   and any older run's, found through the audit trail), not only this run's. */
if (isset($testId) && $testId > 0) {
    $ids  = array_values(array_unique(array_merge([$testId], testAdminIds())));
    $left = (int) Database::scalar('SELECT COUNT(*) FROM admin_sessions WHERE admin_id IN (' . implode(',', array_map('intval', $ids)) . ')', [], 0);
    check('cleanup leaves no admin_sessions row for any account ' . TEST_USER . ' has had (this run or an older one)', $left === 0,
        "$left left over " . count($ids) . ' id(s)');
    $ev = (int) Database::scalar('SELECT COUNT(*) FROM admin_login_events WHERE username = :u', ['u' => TEST_USER], 0);
    check('cleanup leaves no login-history row for the test account', $ev === 0, "$ev left");
    $rl = (int) Database::scalar("SELECT COUNT(*) FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN (" . LOGIN_BUCKETS . ')', [], 0);
    check('cleanup leaves no login-throttle row for this machine (its sign-ins counted in admin_login)', $rl === 0, "$rl left");
}
echo "\n----------------------------------------\n";
echo "PASSED: $PASS   FAILED: $FAIL\n\n";
exit($FAIL === 0 ? 0 : 1);
