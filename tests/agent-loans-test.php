<?php
/**
 * Agent loans & advances register (17 Sep 2026) — AgentWallet::issueLoan /
 * repayLoan / writeOffLoan / loans / loan / loanEntries / syncLoanRecovery.
 *
 * Proves the register and the ledger never disagree about money: issuing a
 * loan is the same recordAdvance() debit as before (tagged 'ADVANCE L<id>'),
 * a repayment is the matching credit, commission earned afterwards is shown
 * as recovering the OLDEST open loan first, a settled / written-off loan can
 * not be touched again, and the company cap (agent_advance_max) is enforced
 * before anything is written.
 *
 *   php tests/agent-loans-test.php
 * Throwaway agent + far-future schedule; cleans up after itself and puts the
 * settings it pins back exactly as found. CLI only.
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
function money_is(float $a, float $b): bool { return abs($a - $b) < 0.005; }

const TD      = '2099-09-23';
const AGENT_U = 'testloans-agent';

/* ---- settings pinned for the run, restored raw at the end ------------- */
$PINNED = ['agent_advance_max', 'agent_commission_percent', 'agent_commission_mode', 'agent_wallet_enabled'];
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

function cleanup(int $agentId, int $sid): void {
    if ($agentId > 0) {
        try { Database::delete('agent_loans', 'agent_admin_id = :a', ['a' => $agentId]); } catch (Throwable $e) {}
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $agentId]);
        foreach (pluck(Database::fetchAll('SELECT id FROM bookings WHERE sold_by_admin_id = :a', ['a' => $agentId]), 'id') as $id) {
            Database::delete('bookings', 'id = :i', ['i' => (int) $id]);
        }
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $agentId]);
        Database::delete('admins', 'id = :a', ['a' => $agentId]);
        Database::delete('audit_logs', "action LIKE 'agent.loan_%' AND entity_id = :a", ['a' => (string) $agentId]);
        Database::delete('audit_logs', "action = 'agent.advance' AND entity_id = :a", ['a' => (string) $agentId]);
    }
    if ($sid > 0) {
        Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]);
        Database::delete('booking_legs', 'schedule_id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
        Database::delete('schedules', 'id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
    }
}

echo "\n=== Agent loans & advances register (issueLoan / repayLoan / writeOffLoan) ===\n\n";

$agentId = 0; $sid = 0;
try {
    if (!Database::exists("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_loans'")) {
        check('agent_loans table exists (run database/upgrade-2026-09-agent-loans.sql)', false);
        throw new RuntimeException('migration missing');
    }

    $old = Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => AGENT_U]);
    if ($old !== null) { cleanup((int) $old['id'], 0); }
    $agentId = Database::insert('admins', [
        'username' => AGENT_U, 'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'full_name' => 'Loans Test Agent', 'role' => 'agent', 'is_active' => 1,
    ]);

    $route = Database::fetch("SELECT * FROM routes WHERE coach_type='seater' AND is_active=1 ORDER BY id LIMIT 1")
          ?? Database::fetch("SELECT * FROM routes WHERE is_active=1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no active route\n"; exit(1); }
    $sid  = (int) Seats::schedule((int) $route['id'], TD)['id'];
    $free = Seats::availability((int) $route['id'], TD)['available'] ?? [];
    if (count($free) < 1) { echo "no free seats\n"; exit(1); }

    Settings::set('agent_commission_percent', '5', 'float', 'agent');
    Settings::set('agent_commission_mode', 'percent', 'string', 'agent');
    Settings::set('agent_wallet_enabled', '1', 'bool', 'agent');
    Settings::set('agent_advance_max', '0', 'float', 'agent');
    Settings::flush();

    /* 1) Issue a ₹5,000 advance: one register row + one ledger debit. */
    $loan1 = AgentWallet::issueLoan($agentId, 'advance', 5000.0, 'full', 0.0, 'Diwali advance', 1);
    check('issueLoan returns a register id', $loan1 > 0);
    $l = AgentWallet::loan($loan1);
    check('register row is open with the full principal outstanding',
        $l !== null && (string) $l['status'] === 'open' && money_is((float) $l['outstanding'], 5000.0) && (string) $l['kind'] === 'advance');
    check('loanLabel reads Advance #<id>', AgentWallet::loanLabel($l ?? []) === 'Advance #' . $loan1);
    $bal = AgentWallet::balances($agentId);
    check('the ledger debits commission by 5,000 (same money model as recordAdvance)', money_is($bal['commission'], -5000.0));
    check('the cash account is untouched', money_is($bal['cash'], 0.0));
    $tagged = (int) Database::scalar(
        "SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a AND ref = :r",
        ['a' => $agentId, 'r' => 'ADVANCE L' . $loan1], 0);
    check("the ledger row is tagged 'ADVANCE L$loan1'", $tagged === 1);
    $audited = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'agent.loan_issued' AND entity_id = :a", ['a' => (string) $agentId], 0);
    check('issuing is audited as agent.loan_issued', $audited === 1);
    check('advanceSummary agrees (outstanding 5,000)', money_is(AgentWallet::advanceSummary($agentId)['outstanding'], 5000.0));

    /* 2) Guards — nothing below writes a row. */
    expectThrow('unknown kind refused', fn() => AgentWallet::issueLoan($agentId, 'gift', 100.0, 'full', 0.0, '', 1));
    expectThrow('zero principal refused', fn() => AgentWallet::issueLoan($agentId, 'loan', 0.0, 'full', 0.0, '', 1));
    expectThrow('percent recovery above 100 refused', fn() => AgentWallet::issueLoan($agentId, 'loan', 100.0, 'percent', 150.0, '', 1));
    expectThrow('fixed recovery of ₹0 refused', fn() => AgentWallet::issueLoan($agentId, 'loan', 100.0, 'fixed', 0.0, '', 1));
    expectThrow('unknown recovery mode refused', fn() => AgentWallet::issueLoan($agentId, 'loan', 100.0, 'weekly', 10.0, '', 1));
    $superId = (int) Database::scalar("SELECT id FROM admins WHERE role='superadmin' ORDER BY id LIMIT 1", [], 0);
    if ($superId > 0) {
        expectThrow('a loan to a non-agent account is refused', fn() => AgentWallet::issueLoan($superId, 'loan', 100.0, 'full', 0.0, '', 1));
    }
    check('no guard wrote a register row', (int) Database::scalar('SELECT COUNT(*) FROM agent_loans WHERE agent_admin_id = :a', ['a' => $agentId], 0) === 1);

    /* 3) The company cap: agent_advance_max counts what is still outstanding. */
    Settings::set('agent_advance_max', '6000', 'float', 'agent');
    Settings::flush();
    expectThrow('₹2,000 more would breach the ₹6,000 cap (5,000 outstanding)',
        fn() => AgentWallet::issueLoan($agentId, 'loan', 2000.0, 'fixed', 500.0, '', 1));
    $loan2 = AgentWallet::issueLoan($agentId, 'loan', 1000.0, 'fixed', 500.0, 'Phone loan', 1);
    check('₹1,000 inside the cap is issued as a second (loan) row', $loan2 > $loan1);
    $l2 = AgentWallet::loan($loan2);
    check('fixed-mode loan keeps its ₹500 per-payout figure',
        $l2 !== null && (string) $l2['recover_mode'] === 'fixed' && money_is((float) $l2['recover_value'], 500.0) && (string) $l2['kind'] === 'loan');
    check('balance now −6,000', money_is(AgentWallet::balances($agentId)['commission'], -6000.0));
    Settings::set('agent_advance_max', '0', 'float', 'agent');
    Settings::flush();

    /* 4) Cash repayment against loan 1. */
    $ledgerId = AgentWallet::repayLoan($loan1, 1000.0, 'first instalment', 1);
    check('repayLoan returns the ledger row id', $ledgerId > 0);
    $l = AgentWallet::loan($loan1);
    check('register shows 1,000 recovered / 4,000 outstanding, still open',
        $l !== null && money_is((float) $l['recovered'], 1000.0) && money_is((float) $l['outstanding'], 4000.0) && (string) $l['status'] === 'open');
    check('the ledger credit lands on the commission account (−5,000)', money_is(AgentWallet::balances($agentId)['commission'], -5000.0));
    expectThrow('repaying more than the outstanding is refused', fn() => AgentWallet::repayLoan($loan1, 4000.01, '', 1));
    expectThrow('a zero repayment is refused', fn() => AgentWallet::repayLoan($loan1, 0.0, '', 1));
    expectThrow('repaying an unknown loan is refused', fn() => AgentWallet::repayLoan(999999999, 10.0, '', 1));

    /* 5) Commission earned afterwards recovers the OLDEST open loan first. */
    $b1 = BookingService::counterSale($route, $sid, TD, [$free[0]], [
        'name' => 'Loan Passenger', 'phone' => '9800000019',
        'amount' => 2000.00, 'paymentMethod' => 'cash',
    ], $agentId, 'agent');
    check('a sale by the agent is confirmed', (string) $b1['status'] === 'confirmed');
    AgentWallet::syncLoanRecovery($agentId);
    $l  = AgentWallet::loan($loan1);
    $l2 = AgentWallet::loan($loan2);
    check('₹100 commission is shown against loan 1 (1,000 cash + 100 commission = 1,100)',
        $l !== null && money_is((float) $l['recovered'], 1100.0));
    check('loan 2 (younger) gets nothing until loan 1 is covered',
        $l2 !== null && money_is((float) $l2['recovered'], 0.0));
    check('sync is display-only: the ledger balance is −4,900', money_is(AgentWallet::balances($agentId)['commission'], -4900.0));

    /* 6) Settle loan 1 with the remaining cash. */
    AgentWallet::repayLoan($loan1, 3900.0, 'closing instalment', 1);
    $l = AgentWallet::loan($loan1);
    check('loan 1 settles itself once recovered reaches the principal',
        $l !== null && (string) $l['status'] === 'settled' && !empty($l['settled_at']) && money_is((float) $l['outstanding'], 0.0));
    expectThrow('a settled loan cannot take another repayment', fn() => AgentWallet::repayLoan($loan1, 1.0, '', 1));
    expectThrow('a settled loan cannot be written off', fn() => AgentWallet::writeOffLoan($loan1, '', 1));

    /* 7) Write off loan 2: the ledger gets the matching credit. */
    AgentWallet::writeOffLoan($loan2, 'agent left', 1);
    $l2 = AgentWallet::loan($loan2);
    check('loan 2 is written off with the note kept', $l2 !== null && (string) $l2['status'] === 'written_off' && (string) $l2['note'] === 'agent left');
    check('the forgiven ₹1,000 is credited back, balance 0', money_is(AgentWallet::balances($agentId)['commission'], 0.0));
    expectThrow('writing off twice is refused', fn() => AgentWallet::writeOffLoan($loan2, '', 1));
    $audited = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'agent.loan_written_off' AND entity_id = :a", ['a' => (string) $agentId], 0);
    check('the write-off is audited', $audited === 1);

    /* 8) Reading the register. */
    $all = AgentWallet::loans($agentId);
    check('loans() lists both rows', count($all) === 2);
    check('loans(status) filters: 1 settled, 1 written off, 0 open',
        count(AgentWallet::loans($agentId, 'settled')) === 1
        && count(AgentWallet::loans($agentId, 'written_off')) === 1
        && count(AgentWallet::loans($agentId, 'open')) === 0);
    $entries = AgentWallet::loanEntries($loan1);
    check('loanEntries(loan 1) = the debit + two repayments, oldest first',
        count($entries) === 3 && (float) $entries[0]['amount'] < 0 && (float) $entries[1]['amount'] > 0 && (float) $entries[2]['amount'] > 0);
    check('loanEntries(loan 2) = the debit + the write-off credit', count(AgentWallet::loanEntries($loan2)) === 2);
    check('loan() on an unknown id is null', AgentWallet::loan(999999999) === null);
    check('advanceSummary: given 6,000, outstanding 0', money_is(AgentWallet::advanceSummary($agentId)['given'], 6000.0)
        && money_is(AgentWallet::advanceSummary($agentId)['outstanding'], 0.0));

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanup($agentId, $sid);
    $restoreSettings();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
