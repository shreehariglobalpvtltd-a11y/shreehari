<?php
/**
 * tests/public-agent-code-resolver-test.php
 *
 * Master-prompt §3 — customer-typed agent code on the PUBLIC checkout
 * must be normalised, validated, and resolved to admins.id server-side.
 * A disabled / locked / bogus code must silently return null so the
 * booking still completes as a normal direct sale — never a 500.
 *
 * Runs against the local dev DB (db shari_test, per launch.json), so
 * every touched row is created inside a rolled-back transaction and no
 * production data is modified.
 */

declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/agentwallet.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \e[32mPASS\e[0m  $label" . ($detail ? " → $detail" : '') . "\n"; }
    else     { $fail++; echo "  \e[31mFAIL\e[0m  $label" . ($detail ? " → $detail" : '') . "\n"; }
}

/* ---------------------------------------------------------------------
 *  1. Pattern parsing — the resolver must accept every reasonable form
 *     the customer might type, and reject nonsense.
 * ------------------------------------------------------------------- */
echo "== Pattern parsing ==\n";

// Set up a fresh test agent that owns code 42.
Database::run("START TRANSACTION");
try {
    $adminId = (int) Database::insert('admins', [
        'username'      => 'test_agent_' . bin2hex(random_bytes(3)),
        'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        'full_name'     => 'Resolver Test Agent',
        'phone'         => '9999900042',
        'role'          => 'agent',
        'is_active'     => 1,
    ]);

    // Assign code 42 via the same public method the admin UI uses.
    AgentWallet::setAgentCode($adminId, 42, 0);

    // Now build the resolver's expected behaviour.
    check('SHG-042 (canonical form) resolves',   AgentWallet::resolveAgentCodeFromString('SHG-042') === $adminId);
    check('SHG042  (no dash) resolves',          AgentWallet::resolveAgentCodeFromString('SHG042')  === $adminId);
    check('shg-42  (lowercase, unpadded) resolves', AgentWallet::resolveAgentCodeFromString('shg-42') === $adminId);
    check('  SHG-42  (surrounding whitespace) resolves', AgentWallet::resolveAgentCodeFromString('  SHG-42  ') === $adminId);
    check('empty string returns null',           AgentWallet::resolveAgentCodeFromString('') === null);
    check('whitespace-only returns null',        AgentWallet::resolveAgentCodeFromString('   ') === null);
    check('SHGABCD (letters, legacy referral) returns null (not a counter-agent code)',
        AgentWallet::resolveAgentCodeFromString('SHGABCD') === null);
    check('JUNK-STRING returns null',            AgentWallet::resolveAgentCodeFromString('JUNK-STRING') === null);
    check('SHG-9999 (out of range 1..1000) returns null',
        AgentWallet::resolveAgentCodeFromString('SHG-9999') === null);
    check('SHG-0000 (below range) returns null', AgentWallet::resolveAgentCodeFromString('SHG-0000') === null);
    check('SHG-999 (in range, unassigned) returns null',
        AgentWallet::resolveAgentCodeFromString('SHG-999') === null);

    /* ---------------------------------------------------------------
     *  2. Active-agent guard — a suspended/locked code must not silently
     *     keep earning commissions on public bookings.
     * ------------------------------------------------------------- */
    echo "\n== Active-agent guard ==\n";

    // Disable the agent — resolver must return null.
    Database::update('admins', ['is_active' => 0], 'id = :id', ['id' => $adminId]);
    check('a disabled agent\'s code returns null',
        AgentWallet::resolveAgentCodeFromString('SHG-042') === null);

    // Re-enable, then lock — locked_until in the future must also block.
    Database::update('admins', [
        'is_active'   => 1,
        'locked_until'=> date('Y-m-d H:i:s', time() + 3600),
    ], 'id = :id', ['id' => $adminId]);
    check('a locked agent\'s code returns null',
        AgentWallet::resolveAgentCodeFromString('SHG-042') === null);

    // Unlock — should resolve again.
    Database::update('admins', ['locked_until' => null], 'id = :id', ['id' => $adminId]);
    check('an unlocked agent\'s code resolves again',
        AgentWallet::resolveAgentCodeFromString('SHG-042') === $adminId);

    // Change role to support — resolver must refuse (only role='agent').
    Database::update('admins', ['role' => 'support'], 'id = :id', ['id' => $adminId]);
    check('a non-agent role does NOT resolve',
        AgentWallet::resolveAgentCodeFromString('SHG-042') === null);
} catch (Throwable $e) {
    // Roll back and re-throw with visible line so the harness prints it.
    try { Database::run("ROLLBACK"); } catch (Throwable $r) {}
    fwrite(STDERR, "  \033[31mERROR\033[0m " . $e->getMessage() . "\n"
        . "  at " . $e->getFile() . ':' . $e->getLine() . "\n");
    $fail++;
    goto done;
}
Database::run("ROLLBACK");
done:

echo "\n";
echo "\e[1m$pass passed, $fail failed\e[0m\n";
exit($fail > 0 ? 1 : 0);
