<?php
/**
 * Agent wallet + offline paper tickets — integration test.
 *
 * Proves the money rules that the panel only displays:
 * commission accrues once per sale at the agent's own rate, a cancellation
 * reverses the commission but never the cash, a payout can't exceed what is
 * owed, and a paper ticket entered with seats produces ONE commission, not
 * two.
 *
 *   php -c .claude/php-dev.ini tests/agent-wallet-test.php
 *
 * Writes only throwaway rows (a 'testwallet' agent, PNRs on a far-future
 * date) and cleans up after itself. CLI only.
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
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}
function expectThrow(string $l, callable $fn): void {
    try { $fn(); check($l . ' (expected rejection)', false); }
    catch (RuntimeException $e) { check($l . ' → "' . $e->getMessage() . '"', true); }
}
/** Money compare — DECIMAL round-trips make === unreliable. */
function money_is(float $a, float $b): bool { return abs($a - $b) < 0.005; }

const TD       = '2099-09-20';
const AGENT_U  = 'testwallet-agent';
const PAPER_A  = 'TESTPAPER-A1';
const PAPER_B  = 'TESTPAPER-B2';

$agentId = 0; $sid = 0;

function cleanup(int $agentId, int $sid): void {
    if ($agentId > 0) {
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $agentId]);
        Database::delete('offline_tickets', 'agent_admin_id = :a', ['a' => $agentId]);
        foreach (pluck(Database::fetchAll(
            'SELECT id FROM bookings WHERE sold_by_admin_id = :a', ['a' => $agentId]
        ), 'id') as $id) {
            Database::delete('bookings', 'id = :i', ['i' => (int) $id]);   // legs/seats/pax cascade
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

echo "\n=== Agent wallet + offline tickets ===\n\n";

try {
    foreach (['agent_ledger', 'offline_tickets', 'admin_profiles'] as $t) {
        if (!Database::exists("SELECT 1 FROM information_schema.tables
                                WHERE table_schema = DATABASE() AND table_name = :t", ['t' => $t])) {
            echo "  $t missing — run database/upgrade-2026-08-agent-wallet.sql first\n";
            exit(1);
        }
    }

    // A throwaway agent, and a seater route so seat numbers are predictable.
    $old = Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => AGENT_U]);
    if ($old !== null) { cleanup((int) $old['id'], 0); }

    $agentId = Database::insert('admins', [
        'username'      => AGENT_U,
        'password_hash' => password_hash('not-a-real-login', PASSWORD_BCRYPT),
        'full_name'     => 'Wallet Test Agent',
        'role'          => 'agent',
        'is_active'     => 1,
    ]);

    $route = Database::fetch("SELECT * FROM routes WHERE coach_type = 'seater' AND is_active = 1 ORDER BY id LIMIT 1")
          ?? Database::fetch("SELECT * FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no active route\n"; exit(1); }

    $sch = Seats::schedule((int) $route['id'], TD);
    $sid = (int) $sch['id'];

    $free  = Seats::availability((int) $route['id'], TD)['available'] ?? [];
    if (count($free) < 4) { echo "not enough free seats on the test schedule\n"; exit(1); }
    $seatA = $free[0]; $seatB = $free[1]; $seatC = $free[2];

    Settings::set('agent_commission_percent', '5', 'float', 'agent');
    Settings::set('agent_wallet_enabled', '1', 'bool', 'agent');
    Settings::set('agent_offline_tickets', '1', 'bool', 'agent');
    // The company now pays a FLAT per-passenger commission by default (26 Aug
    // 2026). The percent engine is still supported and is what the cases below
    // exercise, so pin this suite to it explicitly; the flat tier gets its own
    // section at the end.
    Settings::set('agent_commission_mode', 'percent', 'string', 'agent');
    Settings::flush();

    /* ---- 1. A counter sale credits commission AND cash -------------- */
    $b1 = BookingService::counterSale($route, $sid, TD, [$seatA], [
        'name' => 'Counter Passenger', 'phone' => '9800000001',
        'amount' => 2000.00, 'paymentMethod' => 'cash',
    ], $agentId, 'agent');

    $bal = AgentWallet::balances($agentId);
    check('counter sale credits 5% commission (₹100)', money_is($bal['commission'], 100.00));
    check('  and books the ₹2,000 cash as owed to the company', money_is($bal['cash'], 2000.00));
    check('  the sale is attributed to the agent', (int) $b1['sold_by_admin_id'] === $agentId);
    check('  and the booking is confirmed with a ticket',
        (string) $b1['status'] === 'confirmed'
        && Database::exists('SELECT 1 FROM tickets WHERE booking_id = :b', ['b' => (int) $b1['id']]));

    /* ---- 2. Accruing twice must not pay twice ----------------------- */
    AgentWallet::accrue($b1);
    AgentWallet::accrue($b1);
    check('re-accruing the same sale pays nothing extra',
        money_is(AgentWallet::balances($agentId)['commission'], 100.00));

    /* ---- 3. A per-agent rate beats the company rate ----------------- */
    AgentWallet::saveProfile($agentId, ['commission_percent' => '12.5']);
    check('per-agent override is used', money_is(AgentWallet::commissionPercentFor($agentId), 12.5));

    $b2 = BookingService::counterSale($route, $sid, TD, [$seatB], [
        'name' => 'Second Passenger', 'phone' => '9800000002',
        'amount' => 1000.00, 'paymentMethod' => 'upi',
    ], $agentId, 'agent');

    $bal = AgentWallet::balances($agentId);
    check('  second sale earns 12.5% (₹125), total ₹225', money_is($bal['commission'], 225.00));
    check('  a UPI sale adds NO cash to hold', money_is($bal['cash'], 2000.00));

    /* ---- 4. Cancelling reverses commission but never cash ----------- */
    BookingService::cancel((string) $b1['pnr'], 'Test cancellation', false);
    $bal = AgentWallet::balances($agentId);
    check('cancelling takes the ₹100 commission back', money_is($bal['commission'], 125.00));
    check('  but the ₹2,000 cash stays on the agent', money_is($bal['cash'], 2000.00));

    /* ---- 5. Payout and handover are bounded by the balance ---------- */
    expectThrow('a payout bigger than what is owed is refused',
        fn() => AgentWallet::record($agentId, 'payout', 5000, ['by' => $agentId]));
    AgentWallet::record($agentId, 'payout', 100, ['by' => $agentId, 'note' => 'Part payment']);
    check('a valid payout reduces what we owe', money_is(AgentWallet::balances($agentId)['commission'], 25.00));

    expectThrow('handing over more cash than held is refused',
        fn() => AgentWallet::record($agentId, 'cash_handover', 9999, ['by' => $agentId]));
    AgentWallet::record($agentId, 'cash_handover', 2000, ['by' => $agentId, 'ref' => 'VOUCHER-1']);
    check('handing the cash over clears the cash balance',
        money_is(AgentWallet::balances($agentId)['cash'], 0.00));

    /* ---- 6. Paper ticket, register-only (no seats) ------------------ */
    $r1 = AgentWallet::recordOfflineTicket([
        'agentId' => $agentId, 'paperNo' => PAPER_A, 'name' => 'Paper Passenger',
        'phone' => '9800000003', 'travelDate' => TD, 'amount' => 800.00,
        'paymentMode' => 'cash', 'seatText' => 'back row',
    ], $agentId);

    $bal = AgentWallet::balances($agentId);
    check('register-only paper ticket earns commission (12.5% of ₹800 = ₹100)',
        $r1['mode'] === 'register' && money_is($bal['commission'], 125.00));
    check('  and its cash is owed to the company', money_is($bal['cash'], 800.00));
    check('  no booking was created', $r1['pnr'] === '');

    /* ---- 7. The same paper number cannot be claimed twice ----------- */
    expectThrow('re-entering the same paper ticket is refused', fn() => AgentWallet::recordOfflineTicket([
        'agentId' => $agentId, 'paperNo' => PAPER_A, 'name' => 'Paper Passenger',
        'travelDate' => TD, 'amount' => 800.00,
    ], $agentId));

    /* ---- 8. Paper ticket WITH a seat becomes a real booking --------- */
    $before = AgentWallet::balances($agentId)['commission'];
    $r2 = AgentWallet::recordOfflineTicket([
        'agentId' => $agentId, 'paperNo' => PAPER_B, 'name' => 'Seated Paper Passenger',
        'phone' => '9800000004', 'travelDate' => TD, 'amount' => 1600.00,
        'paymentMode' => 'cash', 'routeId' => (int) $route['id'], 'seats' => [$seatC],
    ], $agentId);

    check('paper ticket with a seat creates a real booking', $r2['mode'] === 'booking' && $r2['pnr'] !== '');
    check('  the seat is now sold on the map',
        !in_array($seatC, Seats::availability((int) $route['id'], TD)['available'] ?? [], true));
    check('  commission was paid ONCE, not twice (₹200 for ₹1,600)',
        money_is(AgentWallet::balances($agentId)['commission'] - $before, 200.00));

    /* ---- 9. A seat already sold cannot be re-sold on paper ---------- */
    expectThrow('a paper entry onto a sold seat is refused', fn() => AgentWallet::recordOfflineTicket([
        'agentId' => $agentId, 'paperNo' => 'TESTPAPER-C3', 'name' => 'Clash',
        'travelDate' => TD, 'amount' => 500.00,
        'routeId' => (int) $route['id'], 'seats' => [$seatC],
    ], $agentId));

    /* ---- 10. The summary adds up to the balance -------------------- */
    $s   = AgentWallet::summary($agentId);
    $bal = AgentWallet::balances($agentId);
    check('summary reconciles: earned − reversed − paid = commission balance',
        money_is($s['earned'] - $s['reversed'] - $s['paidOut'], $bal['commission']));
    check('summary reconciles: collected − handed over = cash balance',
        money_is($s['collected'] - $s['handedOver'], $bal['cash']));

    /* ---- 11. FLAT per-passenger commission (owner rule, 26 Aug 2026) ---
       Direct agent ₹200 per passenger, team/organisation ₹400 — and the
       amount must scale with the head count, not the ticket value. */
    Settings::set('agent_commission_mode', 'flat_per_seat', 'string', 'agent');
    Settings::set('agent_flat_direct', '200', 'float', 'agent');
    Settings::set('agent_flat_joint',  '400', 'float', 'agent');
    Settings::flush();

    AgentWallet::setAgentType($agentId, 'direct', $agentId);
    check('flat: a direct agent earns ₹200 for 1 passenger',
        money_is(AgentWallet::commissionForBooking(2000.00, $agentId, 1), 200.00));
    check('flat: ₹200 is PER PASSENGER — 4 seats earn ₹800',
        money_is(AgentWallet::commissionForBooking(8000.00, $agentId, 4), 800.00));
    check('flat: the ticket value does not change it (₹9,999 sale, 1 seat → ₹200)',
        money_is(AgentWallet::commissionForBooking(9999.00, $agentId, 1), 200.00));

    AgentWallet::setAgentType($agentId, 'joint', $agentId);
    check('flat: a team/organisation agent earns ₹400 per passenger',
        money_is(AgentWallet::commissionForBooking(2000.00, $agentId, 1), 400.00));
    check('flat: a team of 5 under one name earns ₹2,000',
        money_is(AgentWallet::commissionForBooking(10000.00, $agentId, 5), 2000.00));
    check('  and the tier is what was saved', AgentWallet::agentTypeFor($agentId) === 'joint');

    // A seat-count hiccup must never silently pay zero.
    check('flat: a zero seat count still pays for one passenger',
        money_is(AgentWallet::commissionForBooking(2000.00, $agentId, 0), 400.00));

    // The owner must be able to move the rates later without a deploy.
    Settings::set('agent_flat_joint', '450', 'float', 'agent');
    Settings::flush();
    check('flat: changing the rate in Settings takes effect immediately (₹450)',
        money_is(AgentWallet::commissionForBooking(2000.00, $agentId, 1), 450.00));

    // Switching back to percent must restore the original engine untouched.
    Settings::set('agent_commission_mode', 'percent', 'string', 'agent');
    Settings::flush();
    check('percent mode still works after the flat tier (12.5% of ₹800 = ₹100)',
        money_is(AgentWallet::commissionForBooking(800.00, $agentId, 4), 100.00));

    AgentWallet::setAgentType($agentId, 'direct', $agentId);

    /* ---- 11b. PER-AGENT commission override (owner ask, 27 Aug 2026) ---
       One agent can be paid their own flat ₹ per passenger, or forced onto
       the percent engine, no matter what the company scheme says. */
    Settings::set('agent_commission_mode', 'flat_per_seat', 'string', 'agent');
    Settings::set('agent_flat_direct', '200', 'float', 'agent');
    Settings::set('agent_flat_joint',  '400', 'float', 'agent');
    Settings::flush();

    AgentWallet::setCommissionOverride($agentId, 'flat', 250.0, $agentId);
    check('override: flat ₹250 beats the ₹200 direct tier',
        money_is(AgentWallet::commissionForBooking(2000.00, $agentId, 1), 250.00));
    check('  and is PER PASSENGER — 3 pax earn ₹750',
        money_is(AgentWallet::commissionForBooking(6000.00, $agentId, 3), 750.00));
    check('  the wallet note says it was an override',
        str_contains(AgentWallet::commissionNoteFor($agentId, 3, 6000.00), 'agent override'));
    check('  and it reads back as saved',
        AgentWallet::commissionOverrideFor($agentId) === ['mode' => 'flat', 'flat' => 250.0]);

    // A percent override forces the percent engine even while the company is flat.
    AgentWallet::setCommissionOverride($agentId, 'percent', null, $agentId);
    check('override: percent override pays the agent\'s own 12.5% in FLAT company mode (₹2,000 → ₹250)',
        money_is(AgentWallet::commissionForBooking(2000.00, $agentId, 1), 250.00));

    Settings::set('agent_commission_mode', 'percent', 'string', 'agent');
    Settings::flush();
    check('override: percent override in percent company mode pays the same 12.5% (₹800 → ₹100)',
        money_is(AgentWallet::commissionForBooking(800.00, $agentId, 4), 100.00));

    // Clearing the override falls straight back to the company tier.
    Settings::set('agent_commission_mode', 'flat_per_seat', 'string', 'agent');
    Settings::flush();
    AgentWallet::setCommissionOverride($agentId, null, null, $agentId);
    check('override cleared: falls back to the ₹200 direct tier',
        money_is(AgentWallet::commissionForBooking(2000.00, $agentId, 1), 200.00));
    check('  and reads back as none', AgentWallet::commissionOverrideFor($agentId)['mode'] === null);

    // Editing the tier AMOUNTS changes what the tier pays — the owner's
    // editable ₹200/₹400 (the Agent Portal "Tier rates" form calls this).
    AgentWallet::setFlatRates(220, 440, $agentId);
    check('tier rates edited: a direct agent now earns ₹220',
        money_is(AgentWallet::commissionForBooking(2000.00, $agentId, 1), 220.00));
    AgentWallet::setAgentType($agentId, 'joint', $agentId);
    check('tier rates edited: a team agent now earns ₹440',
        money_is(AgentWallet::commissionForBooking(2000.00, $agentId, 1), 440.00));
    AgentWallet::setAgentType($agentId, 'direct', $agentId);
    AgentWallet::setFlatRates(200, 400, $agentId);

    // Guard rails.
    expectThrow('override: a flat override with no amount is refused',
        fn() => AgentWallet::setCommissionOverride($agentId, 'flat', null, $agentId));
    expectThrow('override: a flat override above ₹10,000 is refused',
        fn() => AgentWallet::setCommissionOverride($agentId, 'flat', 10001.0, $agentId));
    expectThrow('override: a negative flat override is refused',
        fn() => AgentWallet::setCommissionOverride($agentId, 'flat', -5.0, $agentId));
    expectThrow('override: an unknown mode is refused',
        fn() => AgentWallet::setCommissionOverride($agentId, 'bogus', 100.0, $agentId));
    expectThrow('tier rates: 10001 is refused',
        fn() => AgentWallet::setFlatRates(10001, 400, $agentId));
    expectThrow('tier rates: a negative rate is refused',
        fn() => AgentWallet::setFlatRates(200, -1, $agentId));

    /* ---- 11c. OFFLINE (paper) tickets use the SAME flat engine ---------
       Regression (fixed): recordOfflineTicket() computed commission with the
       OLD percent engine (commissionOn), so in flat company mode a register-
       only paper ticket paid a percentage of the fare (here 12.5% of ₹5,000 =
       ₹625) instead of the flat ₹200/₹400 per passenger every other sale
       earns. It must now match the ledger the agent is actually paid from.
       Company is flat_per_seat here, direct tier ₹200, no override. */
    AgentWallet::setAgentType($agentId, 'direct', $agentId);
    $beforeFlat = AgentWallet::balances($agentId)['commission'];
    $ofFlat = AgentWallet::recordOfflineTicket([
        'agentId' => $agentId, 'paperNo' => 'TESTPAPER-FLAT1', 'name' => 'Flat Paper Pax',
        'travelDate' => TD, 'amount' => 5000.00, 'paymentMode' => 'upi', 'paxCount' => 1,
        'seatText' => 'register only',
    ], $agentId);
    check('flat: a register-only paper ticket earns the flat ₹200, not a % of the fare',
        $ofFlat['mode'] === 'register' && money_is((float) $ofFlat['commission'], 200.00)
        && money_is(AgentWallet::balances($agentId)['commission'] - $beforeFlat, 200.00));

    $beforeTeam = AgentWallet::balances($agentId)['commission'];
    $ofTeam = AgentWallet::recordOfflineTicket([
        'agentId' => $agentId, 'paperNo' => 'TESTPAPER-FLAT2', 'name' => 'Flat Team Pax',
        'travelDate' => TD, 'amount' => 6000.00, 'paymentMode' => 'cash', 'paxCount' => 3,
        'seatText' => 'register only',
    ], $agentId);
    check('flat: a register-only paper ticket pays PER PASSENGER (3 × ₹200 = ₹600)',
        money_is((float) $ofTeam['commission'], 600.00)
        && money_is(AgentWallet::balances($agentId)['commission'] - $beforeTeam, 600.00));

    // Restore the state the next section started from (percent mode, direct).
    Settings::set('agent_commission_mode', 'percent', 'string', 'agent');
    Settings::flush();

    /* ---- 12. Agent number (1..1000) printed on every ticket ---------- */
    AgentWallet::setAgentCode($agentId, 27, $agentId);
    check('agent number: is stored and read back', AgentWallet::agentCodeFor($agentId) === 27);
    check('agent number: prints as SHG-0027', AgentWallet::agentCodeLabel($agentId) === 'SHG-0027');
    check('agent number: resolves back to the agent', AgentWallet::adminForAgentCode(27) === $agentId);

    // Uniqueness is the whole point — two agents must never share a number.
    $dupBlocked = false;
    try { AgentWallet::setAgentCode($agentId + 99999, 27, $agentId); }
    catch (Throwable $e) { $dupBlocked = str_contains($e->getMessage(), 'already used'); }
    check('agent number: a duplicate is refused', $dupBlocked);

    // Re-setting an agent to the number they already hold must NOT self-clash.
    $sameOk = true;
    try { AgentWallet::setAgentCode($agentId, 27, $agentId); }
    catch (Throwable $e) { $sameOk = false; }
    check('agent number: re-saving your own number is allowed', $sameOk);

    foreach ([0 => 'zero', 1001 => 'over 1000', -5 => 'negative'] as $bad => $label) {
        if ($bad === 0) { continue; }   // 0 means "clear", tested below
        $rejected = false;
        try { AgentWallet::setAgentCode($agentId + 88888, $bad, $agentId); }
        catch (Throwable $e) { $rejected = str_contains($e->getMessage(), 'between'); }
        check("agent number: {$label} is refused", $rejected);
    }

    check('agent number: next free number skips the taken one',
        AgentWallet::nextFreeAgentCode() !== 27 && AgentWallet::nextFreeAgentCode() >= 1);

    AgentWallet::setAgentCode($agentId, null, $agentId);
    check('agent number: clearing it works', AgentWallet::agentCodeFor($agentId) === null);
    check('  and the label is then empty', AgentWallet::agentCodeLabel($agentId) === '');
    check('  and the number is free again', AgentWallet::adminForAgentCode(27) === null);

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage(), false);
} finally {
    cleanup($agentId, $sid);
    // The override lives in a settings map keyed by agent id — deleting the
    // agent row does not clear it, so drop it explicitly or a re-run of the
    // suite could inherit a stale ₹250 override.
    if ($agentId > 0) {
        try { AgentWallet::setCommissionOverride($agentId, null, null); } catch (Throwable $e) {}
    }
    Settings::set('agent_commission_percent', '5', 'float', 'agent');
    // Leave the company back on its real default (flat per passenger) rather
    // than on whatever mode a failing case happened to stop in.
    Settings::set('agent_commission_mode', 'flat_per_seat', 'string', 'agent');
    Settings::set('agent_flat_direct', '200', 'float', 'agent');
    Settings::set('agent_flat_joint', '400', 'float', 'agent');
    Settings::flush();
}

echo "\n  {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
