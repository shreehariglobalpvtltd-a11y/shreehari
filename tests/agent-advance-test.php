<?php
/**
 * Agent advance payments (Point 7) — AgentWallet::recordAdvance / advanceSummary.
 *
 * Proves an advance is money fronted BEFORE it is earned: it debits the
 * commission account (uncapped), auto-nets against future commission via the
 * same balances() SUM the panel shows, is reported as given / outstanding for
 * the employee card, and correctly interacts with the payout cap (you cannot
 * pay out while the balance is negative).
 *
 *   php -c .claude/php-dev.ini tests/agent-advance-test.php
 * Throwaway agent + far-future schedule; cleans up after itself. CLI only.
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
/* A refusal for the RIGHT reason: $why must match the message. Since the payout
   rule (a confirmed payment reference of 5+ characters before any payout) every
   payout without a reference is refused for THAT reason, so a bare expectThrow()
   proved nothing about the cap it was written for. */
function expectRefusal(string $l, callable $fn, string $why): void {
    try { $fn(); check($l . ' (expected rejection)', false); }
    catch (Throwable $e) { check($l . ' → "' . $e->getMessage() . '"', preg_match($why, $e->getMessage()) === 1); }
}
/* 16 Nov 2026 (F1-3): the EXACT refusal - "reserved for an open withdrawal" when
   one really is open, "more than the ₹X currently owed" when the balance is
   simply too low. The old /reserved|owed|balance/ let either pass for both. */
function expectMessage(string $l, callable $fn, string $exact): void {
    try { $fn(); check($l . ' (expected rejection)', false); }
    catch (Throwable $e) { check($l . ' → "' . $e->getMessage() . '"' . ($e->getMessage() === $exact ? '' : ' (wanted "' . $exact . '")'), $e->getMessage() === $exact); }
}
function money_is(float $a, float $b): bool { return abs($a - $b) < 0.005; }
const MSG_RESERVED = 'Funds are reserved for an open withdrawal. Use Team Management → Withdrawals.';

const TD      = '2099-09-22';
const AGENT_U = 'testadvance-agent';

function cleanup(int $agentId, int $sid): void {
    if ($agentId > 0) {
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $agentId]);
        try { Database::delete('agent_payout_requests', 'agent_admin_id = :a', ['a' => $agentId]); } catch (Throwable $e) {}
        foreach (pluck(Database::fetchAll('SELECT id FROM bookings WHERE sold_by_admin_id = :a', ['a' => $agentId]), 'id') as $id) {
            Database::delete('bookings', 'id = :i', ['i' => (int) $id]);
        }
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $agentId]);
        Database::delete('admins', 'id = :a', ['a' => $agentId]);
    }
    if ($sid > 0) {
        Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]);
        Database::delete('booking_legs', 'schedule_id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
        Database::delete('schedules', 'id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
    }
}

echo "\n=== Agent advance payments (recordAdvance / advanceSummary) ===\n\n";

$agentId = 0; $sid = 0;
try {
    $old = Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => AGENT_U]);
    if ($old !== null) { cleanup((int) $old['id'], 0); }
    $agentId = Database::insert('admins', [
        'username' => AGENT_U, 'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'full_name' => 'Advance Test Agent', 'role' => 'agent', 'is_active' => 1,
    ]);

    $route = Database::fetch("SELECT * FROM routes WHERE coach_type='seater' AND is_active=1 ORDER BY id LIMIT 1")
          ?? Database::fetch("SELECT * FROM routes WHERE is_active=1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no active route\n"; exit(1); }
    $sid = (int) Seats::schedule((int) $route['id'], TD)['id'];
    $free = Seats::availability((int) $route['id'], TD)['available'] ?? [];
    if (count($free) < 1) { echo "no free seats\n"; exit(1); }

    Settings::set('agent_commission_percent', '5', 'float', 'agent');
    Settings::set('agent_commission_mode', 'percent', 'string', 'agent');
    Settings::set('agent_wallet_enabled', '1', 'bool', 'agent');
    Settings::flush();

    // 1) Record a ₹5,000 advance → commission account goes to −5,000.
    AgentWallet::recordAdvance($agentId, 5000.0, ['note' => 'Diwali advance', 'ref' => 'V-101', 'by' => 0]);
    $bal = AgentWallet::balances($agentId);
    $adv = AgentWallet::advanceSummary($agentId);
    check('advance debits commission balance to −5,000', money_is($bal['commission'], -5000.0));
    check('advanceSummary given = 5,000', money_is($adv['given'], 5000.0));
    check('advanceSummary outstanding = 5,000', money_is($adv['outstanding'], 5000.0));
    check('advance never touches the cash account', money_is($bal['cash'], 0.0));

    // 2) A payout cannot run while the balance is negative (nothing owed yet).
    //    Every payout carries a confirmed payment reference (UTR / receipt no.,
    //    5+ characters, never reused) - without one it is refused before the cap.
    $utr = static fn(int $n): string => 'UTR-ADV-' . $agentId . '-' . $n;
    expectRefusal('a payout with no confirmed payment reference is refused',
        fn() => AgentWallet::record($agentId, 'payout', 1000.0, ['by' => 0]), '/payment reference is required/i');
    $openReqs = static fn(): int => (int) Database::scalar(
        "SELECT COUNT(*) FROM agent_payout_requests WHERE agent_admin_id = :a AND status = 'open'", ['a' => $agentId], 0);
    check('no withdrawal is open for this agent (so nothing is "reserved")', $openReqs() === 0);
    expectMessage('payout refused while an advance is outstanding: nothing is owed, nothing reserved',
        fn() => AgentWallet::record($agentId, 'payout', 1000.0, ['by' => 0, 'ref' => $utr(1)]),
        'That is more than the ₹0 currently owed to this agent.');

    // 3) A real sale accrues commission and nets against the advance automatically.
    $b1 = BookingService::counterSale($route, $sid, TD, [$free[0]], [
        'name' => 'Adv Passenger', 'phone' => '9800000009',
        'amount' => 2000.00, 'paymentMethod' => 'cash',
    ], $agentId, 'agent');
    check('sale confirmed', (string) $b1['status'] === 'confirmed');
    $adv = AgentWallet::advanceSummary($agentId);
    check('₹100 commission auto-nets the advance (outstanding 4,900)', money_is($adv['outstanding'], 4900.0));
    check('lifetime advance given unchanged (5,000)', money_is($adv['given'], 5000.0));

    // 4) Simulate enough further earnings to clear the advance (+5,000 commission).
    AgentWallet::record($agentId, 'commission', 5000.0, ['note' => 'more sales', 'by' => 0]);
    $bal = AgentWallet::balances($agentId);
    $adv = AgentWallet::advanceSummary($agentId);
    check('commission now positive once earnings exceed advance', money_is($bal['commission'], 100.0));
    check('advance fully recovered → outstanding 0', money_is($adv['outstanding'], 0.0));

    // 5) Payout now works but is still capped at the (advance-adjusted) balance.
    expectMessage('payout still capped at the net balance (no withdrawal open: the owed amount, not "reserved")',
        fn() => AgentWallet::record($agentId, 'payout', 200.0, ['by' => 0, 'ref' => $utr(2)]),
        'That is more than the ₹100 currently owed to this agent.');
    AgentWallet::record($agentId, 'payout', 100.0, ['by' => 0, 'ref' => $utr(3)]);
    check('exact-balance payout succeeds', money_is(AgentWallet::balances($agentId)['commission'], 0.0));

    // 6) A repayment (agent returns cash) is a credit tagged ADVANCE.
    AgentWallet::recordAdvance($agentId, -2000.0, ['note' => 'agent returned cash', 'by' => 0]);
    $bal = AgentWallet::balances($agentId);
    $adv = AgentWallet::advanceSummary($agentId);
    check('repayment credits commission by 2,000', money_is($bal['commission'], 2000.0));
    check('advanceSummary repaid = 2,000', money_is($adv['repaid'], 2000.0));
    expectRefusal('the same payment reference cannot settle a second payout', fn() => AgentWallet::record($agentId, 'payout', 50.0, ['by' => 0, 'ref' => $utr(3)]), '/already recorded/i');
    check('  …and nothing was paid by the refused one', money_is(AgentWallet::balances($agentId)['commission'], 2000.0));

    // 6b) An OPEN withdrawal really reserves its amount: ₹1,500 of the ₹2,000 owed.
    $reqId = (int) Database::insert('agent_payout_requests', [
        'agent_admin_id' => $agentId, 'amount' => 1500.00, 'note' => 'F1-3 test request', 'status' => 'open',
    ]);
    expectMessage('with ₹1,500 of ₹2,000 reserved, a ₹1,000 payout is refused as RESERVED',
        fn() => AgentWallet::record($agentId, 'payout', 1000.0, ['by' => 0, 'ref' => $utr(4)]), MSG_RESERVED);
    expectMessage('…but ₹2,500 is more than is owed at all: the owed message, even with a withdrawal open',
        fn() => AgentWallet::record($agentId, 'payout', 2500.0, ['by' => 0, 'ref' => $utr(5)]),
        'That is more than the ₹2,000 currently owed to this agent.');
    check('  …and neither refused payout moved the balance', money_is(AgentWallet::balances($agentId)['commission'], 2000.0));
    Database::delete('agent_payout_requests', 'id = :i', ['i' => $reqId]);

    // 7) Advance rows are tagged ADVANCE and audited as agent.advance.
    $tagged = (int) Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id=:a AND ref LIKE 'ADVANCE%'", ['a' => $agentId], 0);
    check('advance ledger rows tagged ADVANCE', $tagged === 2);
    $audited = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action='agent.advance' AND entity_id=:a", ['a' => (string) $agentId], 0);
    check('advances written to the audit log', $audited === 2);

    // 8) Guards.
    expectThrow('zero advance rejected', fn() => AgentWallet::recordAdvance($agentId, 0.0, []));
    $superId = (int) Database::scalar("SELECT id FROM admins WHERE role='superadmin' ORDER BY id LIMIT 1", [], 0);
    if ($superId > 0) {
        expectThrow('advance refused for a non-agent account', fn() => AgentWallet::recordAdvance($superId, 500.0, []));
    }

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanup($agentId, $sid);
    Database::delete('audit_logs', "action IN ('agent.advance', 'agent.payout', 'agent.commission') AND entity_id = :a", ['a' => (string) $agentId]);
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
