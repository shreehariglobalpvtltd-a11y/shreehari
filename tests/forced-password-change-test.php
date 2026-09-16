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
 *   1. dev server running on :8899
 *   2. php -c .claude/php-dev.ini -d extension=php_curl.dll \
 *        tests/forced-password-change-test.php
 *
 * Creates one throwaway staff account and deletes it again. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) {
    echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n";
    exit(1);
}

// SHG_TEST_BASE, like every other HTTP suite — see manifest-test.php.
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
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
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $html, $m)
        ? $m[1] : '';
}

function cleanup(): void {
    Database::delete('admins', 'username = :u', ['u' => TEST_USER]);
}

echo "\n=== Forced password change (§20) ===\n\n";
cleanup();

try {
    /* ---- a fresh staff account, exactly as staff.php creates one ------- */
    Database::insert('admins', [
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
    $login = http('/admin/login.php');
    $csrf  = csrfFrom($login['body']);
    check('login page renders with a CSRF token', $csrf !== '');

    $post = http('/admin/login.php', ['form' => [
        CSRF_TOKEN_NAME => $csrf,
        'username'      => TEST_USER,
        'password'      => TEMP_PW,
    ]]);
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
    $dash = http('/admin/index.php');
    check('the dashboard opens normally afterwards', $dash['code'] === 200,
          'HTTP ' . $dash['code']);

    /* ---- old password no longer works ---------------------------------- */
    http('/admin/logout.php');
    $l2   = http('/admin/login.php');
    $bad2 = http('/admin/login.php', ['form' => [
        CSRF_TOKEN_NAME => csrfFrom($l2['body']),
        'username'      => TEST_USER,
        'password'      => TEMP_PW,
    ]]);
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
@unlink($JAR);
echo "\n----------------------------------------\n";
echo "PASSED: $PASS   FAILED: $FAIL\n\n";
exit($FAIL === 0 ? 0 : 1);
