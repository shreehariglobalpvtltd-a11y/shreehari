<?php
/**
 * Agent KYC (17 Sep 2026) — AgentWallet::kycVerify / kycAvailable /
 * saveKycDoc guards, the kyc_* profile keys, and the agent_kyc_required
 * selling gate in assertMaySell().
 *
 * Proves: a fresh agent is 'none'; the office verdict stamps who / when;
 * a rejection keeps the reason and clears the stamp; unknown statuses are
 * refused (never silently ignored); a bad upload never touches the profile;
 * and with agent_kyc_required ON an unverified agent cannot sell while a
 * verified one can — with the switch OFF (the default) nothing changes.
 *
 *   php tests/agent-kyc-test.php
 * Throwaway agent; settings pinned for the run are restored raw. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/booking.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; } }
function expectThrow(string $l, callable $fn): void { try { $fn(); check($l . ' (expected rejection)', false); } catch (Throwable $e) { check($l . ' → "' . $e->getMessage() . '"', true); } }

const AGENT_U = 'testkyc-agent';

$PINNED = ['agent_kyc_required', 'agent_cash_limit_enforce'];
$prior  = [];
foreach ($PINNED as $k) {
    $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$restoreSettings = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        if ($row === null) {
            try { Database::delete('settings', 'skey = :k', ['k' => $k]); } catch (Throwable $e) {}
        } else {
            try {
                Database::update('settings', [
                    'svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'],
                    'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public'],
                ], 'skey = :k', ['k' => $k]);
            } catch (Throwable $e) {}
        }
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};

function cleanup(int $agentId): void {
    if ($agentId > 0) {
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $agentId]);
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $agentId]);
        Database::delete('admins', 'id = :a', ['a' => $agentId]);
        Database::delete('audit_logs', "action LIKE 'agent.kyc%' AND entity_id = :a", ['a' => (string) $agentId]);
    }
}

echo "\n=== Agent KYC (kycVerify / selling gate) ===\n\n";

$agentId = 0;
try {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    unset($_SESSION[ADMIN_SESSION_KEY]);

    check('kycAvailable() — database/upgrade-2026-09-agent-kyc.sql has been applied', AgentWallet::kycAvailable());
    if (!AgentWallet::kycAvailable()) { throw new RuntimeException('KYC migration missing'); }

    $old = Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => AGENT_U]);
    if ($old !== null) { cleanup((int) $old['id']); }
    $agentId = Database::insert('admins', [
        'username' => AGENT_U, 'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'full_name' => 'KYC Test Agent', 'role' => 'agent', 'is_active' => 1,
    ]);
    $superId = (int) Database::scalar("SELECT id FROM admins WHERE role='superadmin' ORDER BY id LIMIT 1", [], 1);

    /* 1) Fresh agent: no KYC on file. */
    $p = AgentWallet::profile($agentId);
    check('a fresh agent has kyc_status none and no stamp',
        (string) $p['kyc_status'] === 'none' && $p['kyc_verified_by'] === null && $p['kyc_verified_at'] === null);
    check('KYC_STATUSES = none / submitted / verified / rejected',
        AgentWallet::KYC_STATUSES === ['none', 'submitted', 'verified', 'rejected']);

    /* 2) ID details travel with the profile. */
    AgentWallet::saveProfile($agentId, ['id_type' => 'Aadhaar', 'id_number' => '1234 5678 9012', 'id_expires_on' => '2031-01-31']);
    $p = AgentWallet::profile($agentId);
    check('id_type / id_number are stored', (string) $p['id_type'] === 'Aadhaar' && (string) $p['id_number'] === '1234 5678 9012');
    check('id_expires_on is stored', (string) ($p['id_expires_on'] ?? '') === '2031-01-31');

    /* 3) The office verifies. */
    AgentWallet::kycVerify($agentId, 'verified', 'Aadhaar checked at the counter', $superId);
    $p = AgentWallet::profile($agentId);
    check('verified: status, note, who and when are stamped',
        (string) $p['kyc_status'] === 'verified'
        && (int) $p['kyc_verified_by'] === $superId
        && !empty($p['kyc_verified_at'])
        && (string) $p['kyc_note'] === 'Aadhaar checked at the counter');
    check('verification is audited as agent.kyc_verified',
        (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'agent.kyc_verified' AND entity_id = :a", ['a' => (string) $agentId], 0) === 1);

    /* 4) A rejection keeps the reason and clears the stamp. */
    AgentWallet::kycVerify($agentId, 'rejected', 'Photo unreadable — resend the front side', $superId);
    $p = AgentWallet::profile($agentId);
    check('rejected: reason kept, stamp cleared',
        (string) $p['kyc_status'] === 'rejected'
        && $p['kyc_verified_by'] === null && $p['kyc_verified_at'] === null
        && str_contains((string) $p['kyc_note'], 'resend'));
    check('a non-verified verdict is audited as agent.kyc_status',
        (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'agent.kyc_status' AND entity_id = :a", ['a' => (string) $agentId], 0) === 1);

    /* 5) Guards. */
    expectThrow('an unknown KYC status is refused, not ignored', fn() => AgentWallet::kycVerify($agentId, 'approved', '', $superId));
    expectThrow('agent id 0 is refused', fn() => AgentWallet::kycVerify(0, 'verified', '', $superId));
    check('the refused status left the profile at rejected', (string) AgentWallet::profile($agentId)['kyc_status'] === 'rejected');
    try {
        AgentWallet::saveProfile($agentId, ['kyc_status' => 'bogus']);
    } catch (Throwable $e) {
        // refusing is also fine — what matters is that 'bogus' never lands
    }
    check("saveProfile never writes a status outside the whitelist", (string) AgentWallet::profile($agentId)['kyc_status'] === 'rejected');
    expectThrow('a missing upload is refused before the profile is touched',
        fn() => AgentWallet::saveKycDoc($agentId, ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0], '1'));
    $p = AgentWallet::profile($agentId);
    check('no document path was recorded by the refused upload', (string) ($p['kyc_doc_path'] ?? '') === '' && (string) ($p['kyc_doc2_path'] ?? '') === '');

    /* 6) The selling gate — as the agent, in a CLI session. */
    $routeId = (int) Database::scalar('SELECT id FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1', [], 0);
    check('an active route exists for the gate check', $routeId > 0);
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => $agentId, 'username' => AGENT_U, 'full_name' => 'KYC Test Agent',
        'role' => 'agent', 'permissions' => [], 'must_change_pw' => false,
        'logged_in_at' => time(), 'last_seen' => time(),
    ];
    check('the CLI session is seen as a counter agent', Auth::isCounterAgent());

    Settings::set('agent_kyc_required', '0', 'bool', 'agent');
    Settings::set('agent_cash_limit_enforce', '0', 'bool', 'agent');
    Settings::flush();
    $ok = true;
    try { AgentWallet::assertMaySell($agentId, $routeId); } catch (Throwable $e) { $ok = false; }
    check('switch OFF (default): a rejected-KYC agent may still sell', $ok);

    Settings::set('agent_kyc_required', '1', 'bool', 'agent');
    Settings::flush();
    expectThrow('switch ON: a rejected-KYC agent is stopped at the gate', fn() => AgentWallet::assertMaySell($agentId, $routeId));

    AgentWallet::kycVerify($agentId, 'submitted', 'resent', $superId);
    expectThrow('switch ON: submitted-but-unverified is still stopped', fn() => AgentWallet::assertMaySell($agentId, $routeId));

    AgentWallet::kycVerify($agentId, 'verified', 'ok', $superId);
    $ok = true;
    try { AgentWallet::assertMaySell($agentId, $routeId); } catch (Throwable $e) { $ok = false; }
    check('switch ON: a verified agent sells', $ok);

    unset($_SESSION[ADMIN_SESSION_KEY]);
    Settings::set('agent_kyc_required', '1', 'bool', 'agent');
    Settings::flush();
    AgentWallet::kycVerify($agentId, 'none', '', $superId);
    $ok = true;
    try { AgentWallet::assertMaySell($agentId, $routeId); } catch (Throwable $e) { $ok = false; }
    check('the gate only ever applies to a signed-in counter agent (office sales unaffected)', $ok);

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    cleanup($agentId);
    $restoreSettings();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
