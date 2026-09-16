<?php
/**
 * Agent reassignment — integration test (requirement 3: change who sold a
 * ticket after it exists).
 *
 * Locks in the adversarial-review fixes for AgentWallet::reassignSeller():
 *   • a CONFIRMED sale moves its commission to the new seller, re-rated to
 *     the new seller's tier — and CASH is never moved;
 *   • a CANCELLED sale mints NOTHING for the new seller (no unearned credit,
 *     no phantom cash) — the earlier delete-and-re-accrue bug did both;
 *   • detaching to the office reverses the old seller's commission to zero;
 *   • the booking's sold_by_admin_id follows the change.
 *
 *   php -c .claude/php-dev.ini tests/agent-reassign-test.php
 *
 * Writes only throwaway rows and cleans up after itself. CLI only.
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
require_once INCLUDE_PATH . '/agentwallet.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}
function money_is(float $a, float $b): bool { return abs($a - $b) < 0.005; }

const TD = '2099-11-11';
const UA = 'testreassign-a';
const UB = 'testreassign-b';

/** Net commission credited to one agent for one booking. */
function bookingComm(int $bid, int $agentId): float {
    return (float) Database::scalar(
        "SELECT COALESCE(SUM(amount),0) FROM agent_ledger
          WHERE booking_id = :b AND account = 'commission' AND agent_admin_id = :a",
        ['b' => $bid, 'a' => $agentId], 0
    );
}
function soldBy(int $bid): ?int {
    $v = Database::scalar('SELECT sold_by_admin_id FROM bookings WHERE id = :i', ['i' => $bid]);
    return $v === null ? null : (int) $v;
}
function cleanupAgent(int $id): void {
    if ($id <= 0) return;
    Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $id]);
    foreach (pluck(Database::fetchAll('SELECT id FROM bookings WHERE sold_by_admin_id = :a', ['a' => $id]), 'id') as $b) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $b]);
    }
    Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]);
    Database::delete('admins', 'id = :a', ['a' => $id]);
}

echo "\n=== Agent reassignment ===\n\n";

$aId = 0; $bId = 0; $sid = 0;
try {
    foreach ([UA, UB] as $u) {
        $old = Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => $u]);
        if ($old !== null) { cleanupAgent((int) $old['id']); }
    }
    $mk = static fn(string $u, string $n): int => Database::insert('admins', [
        'username' => $u, 'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'full_name' => $n, 'role' => 'agent', 'is_active' => 1,
    ]);
    $aId = $mk(UA, 'Reassign Agent A');
    $bId = $mk(UB, 'Reassign Agent B');

    $route = Database::fetch("SELECT * FROM routes WHERE coach_type = 'seater' AND is_active = 1 ORDER BY id LIMIT 1")
          ?? Database::fetch("SELECT * FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no active route\n"; exit(1); }
    $sid = (int) Seats::schedule((int) $route['id'], TD)['id'];
    $free = Seats::availability((int) $route['id'], TD)['available'] ?? [];
    if (count($free) < 4) { echo "not enough free seats\n"; exit(1); }

    // Flat company mode, both agents direct ₹200.
    Settings::set('agent_commission_mode', 'flat_per_seat', 'string', 'agent');
    Settings::set('agent_flat_direct', '200', 'float', 'agent');
    Settings::set('agent_flat_joint', '400', 'float', 'agent');
    Settings::set('agent_wallet_enabled', '1', 'bool', 'agent');
    Settings::flush();
    AgentWallet::setAgentType($aId, 'direct', $aId);
    AgentWallet::setAgentType($bId, 'direct', $bId);

    /* ---- 1. CONFIRMED sale: commission moves, cash does NOT --------- */
    $b1 = BookingService::counterSale($route, $sid, TD, [$free[0]], [
        'name' => 'Pax One', 'phone' => '9800000101', 'amount' => 2000.00, 'paymentMethod' => 'cash',
    ], $aId, 'agent');
    $bid1 = (int) $b1['id'];
    check('setup: A earns ₹200 and holds ₹2,000 cash', money_is(bookingComm($bid1, $aId), 200.00)
        && money_is(AgentWallet::balances($aId)['cash'], 2000.00));

    $aCashBefore = AgentWallet::balances($aId)['cash'];
    AgentWallet::reassignSeller(BookingService::detail((string) $b1['pnr']), $bId, $aId);

    check('confirmed: commission moved off A', money_is(bookingComm($bid1, $aId), 0.00));
    check('confirmed: commission (₹200) now on B', money_is(bookingComm($bid1, $bId), 200.00));
    check('confirmed: A still holds the cash — cash is NOT moved', money_is(AgentWallet::balances($aId)['cash'], $aCashBefore));
    check('confirmed: B is given no phantom cash', money_is(AgentWallet::balances($bId)['cash'], 0.00));
    check('confirmed: the booking is now attributed to B', soldBy($bid1) === $bId);

    /* ---- 2. Re-rate to the NEW agent's tier ------------------------- */
    AgentWallet::setAgentType($bId, 'joint', $bId);   // B now ₹400/pax
    $b2 = BookingService::counterSale($route, $sid, TD, [$free[1]], [
        'name' => 'Pax Two', 'phone' => '9800000102', 'amount' => 2000.00, 'paymentMethod' => 'upi',
    ], $aId, 'agent');
    $bid2 = (int) $b2['id'];
    AgentWallet::reassignSeller(BookingService::detail((string) $b2['pnr']), $bId, $aId);
    check('re-rate: a direct→joint reassignment pays the joint ₹400, not A\'s ₹200',
        money_is(bookingComm($bid2, $bId), 400.00) && money_is(bookingComm($bid2, $aId), 0.00));
    AgentWallet::setAgentType($bId, 'direct', $bId);

    /* ---- 3. CANCELLED sale: reassignment mints NOTHING -------------- */
    $b3 = BookingService::counterSale($route, $sid, TD, [$free[2]], [
        'name' => 'Pax Three', 'phone' => '9800000103', 'amount' => 2000.00, 'paymentMethod' => 'cash',
    ], $aId, 'agent');
    $bid3 = (int) $b3['id'];
    BookingService::cancel((string) $b3['pnr'], 'Test cancel', false);
    check('setup: cancelling nets A\'s commission on the booking to zero', money_is(bookingComm($bid3, $aId), 0.00));

    $bCommBefore = AgentWallet::balances($bId)['commission'];
    $bCashBefore = AgentWallet::balances($bId)['cash'];
    AgentWallet::reassignSeller(BookingService::detail((string) $b3['pnr']), $bId, $aId);
    check('cancelled: B is credited NO new commission (net 0 on the booking)', money_is(bookingComm($bid3, $bId), 0.00));
    check('cancelled: B\'s commission balance is unchanged', money_is(AgentWallet::balances($bId)['commission'], $bCommBefore));
    check('cancelled: B is given NO phantom cash', money_is(AgentWallet::balances($bId)['cash'], $bCashBefore));

    /* ---- 4. Detach to the office ----------------------------------- */
    $b4 = BookingService::counterSale($route, $sid, TD, [$free[3]], [
        'name' => 'Pax Four', 'phone' => '9800000104', 'amount' => 2000.00, 'paymentMethod' => 'upi',
    ], $aId, 'agent');
    $bid4 = (int) $b4['id'];
    check('setup: A earns ₹200 on the fourth sale', money_is(bookingComm($bid4, $aId), 200.00));
    AgentWallet::reassignSeller(BookingService::detail((string) $b4['pnr']), 0, $aId);
    check('detach: A\'s commission on the booking is reversed to zero', money_is(bookingComm($bid4, $aId), 0.00));
    check('detach: the booking has no selling agent', soldBy($bid4) === null);

    /* ---- 4b. Add an agent to a no-agent (customer/online-style) ticket --
       Owner ask: put an agent code on a customer ticket LATER so settlement
       is easy. b4 is now agent-less + confirmed — assigning B must credit B. */
    AgentWallet::reassignSeller(BookingService::detail((string) $b4['pnr']), $bId, $aId);
    check('add-agent: a previously agent-less confirmed ticket credits B ₹200', money_is(bookingComm($bid4, $bId), 200.00));
    check('add-agent: the ticket is now attributed to B', soldBy($bid4) === $bId);

    /* ---- 5. Same agent is a no-op --------------------------------- */
    $r = AgentWallet::reassignSeller(BookingService::detail((string) $b1['pnr']), $bId, $aId);
    check('no-op: reassigning to the current seller changes nothing', $r['old'] === $bId && $r['new'] === $bId);

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage(), false);
} finally {
    cleanupAgent($aId);
    cleanupAgent($bId);
    if ($sid > 0) {
        Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]);
        Database::delete('booking_legs', 'schedule_id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
        Database::delete('schedules', 'id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
    }
    Settings::set('agent_commission_mode', 'flat_per_seat', 'string', 'agent');
    Settings::flush();
}

echo "\n  {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
