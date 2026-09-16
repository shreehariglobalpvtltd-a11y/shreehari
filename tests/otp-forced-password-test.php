<?php
/**
 * =====================================================================
 *  FORCED PASSWORD CHANGE MUST SURVIVE THE OTP DOOR
 *
 *  staff.php can hand an agent a temporary password and tick
 *  must_change_pw. Auth::requireAdmin() then bounces them to
 *  change-password.php until they set a real one.
 *
 *  That control was silently void for one of the two agent doors.
 *  Auth::finishAdminSession() carried:
 *
 *      'must_change_pw' => $viaOtp ? false : (int) $admin['must_change_pw'] === 1,
 *
 *  and agentLoginVerify() passes $viaOtp = true. So an agent who signed in
 *  with agent-code + mobile + WhatsApp OTP was never asked to change the
 *  temporary password, and it stayed valid indefinitely.
 *
 *  The bypass existed for a real reason, not carelessness:
 *  change-password.php demands the CURRENT password, which an OTP user has
 *  never typed, so honouring the flag would have deadlocked them. The fix
 *  enforces the flag here and drops the current-password requirement for
 *  exactly that case — the WhatsApp OTP already proved control of the
 *  registered mobile.
 *
 *  Runs against the service layer rather than over HTTP: agentLoginVerify()
 *  is entirely DB-driven (no session state), so the whole door can be
 *  exercised in-process, and the assertion is about what lands in
 *  $_SESSION — which is the thing that was wrong.
 *
 *      php -c .claude/php-dev.ini tests/otp-forced-password-test.php
 *
 *  Creates one throwaway agent and deletes it again. CLI only.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/agentwallet.php';

/* finishAdminSession() calls session_regenerate_id(), which refuses both
   without an active session AND once output has been sent. bootstrap.php
   deliberately skips session_start() under CLI, so the suite starts one —
   and buffers all its own output, so PHP never considers headers sent.
   Same approach as tests/admin-2fa-test.php, which drives the same path. */
ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

const T_USER  = 'otpgate_tester';
const T_PHONE = '9198765432';   // 10 digits, unlikely to collide with a real agent
const T_TEMP  = 'TempPass123';

$PASS = 0;
$FAIL = 0;

function check(string $label, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    }
}

function noSession(): void
{
    unset($_SESSION[ADMIN_SESSION_KEY]);
}

function sessionFlag(string $key)
{
    return $_SESSION[ADMIN_SESSION_KEY][$key] ?? null;
}

/** The per-IP throttles are shared with every other suite on this machine. */
function clearThrottle(): void
{
    try {
        Database::pdo()->exec('DELETE FROM rate_limits');
    } catch (Throwable $e) {
    }
}

function cleanup(?int $id): void
{
    try {
        Database::pdo()->prepare('DELETE FROM admins WHERE username = ?')->execute([T_USER]);
    } catch (Throwable $e) {
    }
    if ($id !== null) {
        // Release the code through the same writer that took it.
        try {
            AgentWallet::setAgentCode($id, null, 0);
        } catch (Throwable $e) {
        }
    }
}

echo "\n=== Forced password change survives the agent OTP door ===\n\n";

$agentId = null;

try {
    cleanup(null);
    clearThrottle();

    /* ---- an agent holding a temporary password ---------------------- */
    $agentId = (int) Database::insert('admins', [
        'username'       => T_USER,
        'password_hash'  => Security::hashPassword(T_TEMP),
        'full_name'      => 'OTP Gate Tester',
        'phone'          => T_PHONE,
        'role'           => 'agent',
        'is_active'      => 1,
        'must_change_pw' => 1,
    ]);
    check('throwaway agent created with must_change_pw = 1', $agentId > 0, 'id ' . $agentId);

    /* Agent codes are not a column: they live in the agent_codes settings
       map, keyed adminId => code, and AgentWallet::setAgentCode is the only
       writer (it range-checks 1..1000 and refuses a code another agent
       holds). Take the highest free number so a real agent is never
       disturbed. */
    $code = null;
    for ($c = AgentWallet::AGENT_CODE_MAX; $c >= AgentWallet::AGENT_CODE_MIN; $c--) {
        if (AgentWallet::adminForAgentCode($c) === null) { $code = $c; break; }
    }
    check('a free agent code exists to test with', $code !== null);
    AgentWallet::setAgentCode($agentId, $code, 0);
    check('agent code mapped', AgentWallet::adminForAgentCode((int) $code) === $agentId, 'SHG-' . $code);

    /* ---- 1. the OTP door ------------------------------------------- */
    clearThrottle();
    noSession();

    $iss = Auth::issueOtp('agent:' . normalisePhone(T_PHONE), 'login', 'whatsapp');
    check('an OTP can be issued for the agent', ($iss['ok'] ?? false) === true && !empty($iss['code']));

    $v = Auth::agentLoginVerify((string) $code, T_PHONE, (string) $iss['code']);
    check('agent signs in through the code + mobile + OTP door', ($v['ok'] ?? false) === true,
        (string) ($v['error'] ?? ''));

    check('  the session is flagged via_otp', sessionFlag('via_otp') === true);

    /* THE REGRESSION. Before the fix this was false and the agent sailed
       past the forced change with the temporary password still live. */
    check('  must_change_pw is CARRIED, not dropped, on an OTP login',
        sessionFlag('must_change_pw') === true,
        'session says ' . var_export(sessionFlag('must_change_pw'), true));

    /* ---- 2. the password door, for comparison ----------------------- */
    clearThrottle();
    noSession();

    Database::update('admins', ['email' => T_USER . '@example.test'], 'id = :id', ['id' => $agentId]);
    $p = Auth::agentPasswordLogin(T_USER . '@example.test', T_USER, T_TEMP);
    check('agent signs in through the password door', ($p['ok'] ?? false) === true,
        (string) ($p['error'] ?? ''));
    check('  must_change_pw is carried there too (unchanged behaviour)',
        sessionFlag('must_change_pw') === true);
    check('  and it is NOT flagged via_otp', sessionFlag('via_otp') === false);

    /* ---- 3. once the password is real, neither door nags ------------ */
    Database::update('admins', ['must_change_pw' => 0], 'id = :id', ['id' => $agentId]);

    clearThrottle();
    noSession();
    $iss2 = Auth::issueOtp('agent:' . normalisePhone(T_PHONE), 'login', 'whatsapp');
    $v2   = Auth::agentLoginVerify((string) $code, T_PHONE, (string) $iss2['code']);
    check('agent signs in again after clearing the flag', ($v2['ok'] ?? false) === true);
    check('  must_change_pw is false when the account no longer needs it',
        sessionFlag('must_change_pw') === false);

    /* ---- 4. the form must not ask for a password they never had ----- */
    $cp = (string) @file_get_contents(dirname(__DIR__) . '/admin/change-password.php');
    check('change-password.php carves out the forced-OTP case',
        str_contains($cp, 'via_otp') && str_contains($cp, '$otpForced'));
    check('  the current-password field is conditional, not unconditional',
        str_contains($cp, 'if (!$otpForcedView)'));
    check('  a forced OTP change is recorded distinctly in the audit log',
        str_contains($cp, 'old password not required'));

    /* ---- 5. and the bypass itself is gone --------------------------- */
    $au = (string) @file_get_contents(dirname(__DIR__) . '/includes/auth.php');
    check('Auth no longer drops must_change_pw for OTP sessions',
        !str_contains($au, "\$viaOtp ? false : (int) \$admin['must_change_pw']"));

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage(), false);
} finally {
    noSession();
    cleanup($agentId);
    clearThrottle();
}

echo "\n----------------------------------------\n";
echo "PASSED: {$PASS}   FAILED: {$FAIL}\n";
echo "----------------------------------------\n\n";

ob_end_flush();
exit($FAIL === 0 ? 0 : 1);
