<?php
/**
 * money-guards-test.php — six money-path guards from the 24 Sep 2026 audit.
 *
 *   M1 a register-only paper ticket cannot mint commission (party cap, fare floor)
 *   M2 only a counter AGENT accrues commission / cash_due — office sales never
 *   M3 payment proof on a paid + verified booking is refused, never downgraded
 *   M4 a coupon that priced a booking is redeemed with it (used_count, redemptions)
 *   M5 the second per-seat cancel on one booking still voids its commission
 *   M6 one visitor may hold only one party's worth of seats
 *
 *     php tests/money-guards-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/agentwallet.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function refused(callable $fn, string $needle = ''): ?string {
    try { $fn(); return null; }
    catch (Throwable $e) { return $needle === '' || str_contains($e->getMessage(), $needle) ? $e->getMessage() : 'WRONG: ' . $e->getMessage(); }
}

const TD    = '2099-10-10';
const PHONE = '9198700077';

function cleanup(): void {
    foreach (pluck(Database::fetchAll("SELECT id FROM bookings WHERE contact_phone = :p OR pnr LIKE 'SHG-MG%'", ['p' => PHONE]), 'id') as $id) {
        Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $id]);
        Database::delete('coupon_redemptions', 'booking_id = :b', ['b' => (int) $id]);
        Database::delete('bookings', 'id = :i', ['i' => (int) $id]);
    }
    Database::delete('offline_tickets', "paper_ticket_no LIKE 'MG-%'");
    Database::delete('coupons', "code = 'MGTEST50'");
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
    Database::delete('seat_locks', "lock_token LIKE 'mg-%'");
    Database::delete('admins', "username IN ('mg-agent', 'mg-office')");
}

echo "\n=== Money guards (24 Sep 2026) ===\n\n";
cleanup();

$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
check('an active sleeper route exists', $route !== null);
$routeId = (int) $route['id'];
$sch     = Seats::schedule($routeId, TD);
$sid     = (int) $sch['id'];

$agentId = (int) Database::insert('admins', ['username' => 'mg-agent', 'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'full_name' => 'MG Agent', 'email' => 'mg-agent@test.local', 'phone' => '+919000000501', 'role' => 'agent', 'permissions' => '[]', 'is_active' => 1, 'must_change_pw' => 0, 'created_at' => date('Y-m-d H:i:s')]);
$officeId = (int) Database::insert('admins', ['username' => 'mg-office', 'password_hash' => password_hash('x', PASSWORD_BCRYPT), 'full_name' => 'MG Office', 'email' => 'mg-office@test.local', 'phone' => '+919000000502', 'role' => 'manager', 'permissions' => '[]', 'is_active' => 1, 'must_change_pw' => 0, 'created_at' => date('Y-m-d H:i:s')]);
check('an agent and an office member exist', $agentId > 0 && $officeId > 0);

$pax = static fn(string $n) => ['name' => $n, 'phone' => PHONE, 'gender' => 'Male', 'paymentMethod' => 'cash'];

try {
    /* ---------------------------------------------------------------- */
    echo "\n-- M1: register-only paper ticket --\n";
    $offOn = Settings::getBool('agent_offline_tickets', true);
    Settings::set('agent_offline_tickets', true, 'bool', 'agents', false);
    $r = refused(fn() => AgentWallet::recordOfflineTicket(['agentId' => $agentId, 'paperNo' => 'MG-1', 'name' => 'Greedy', 'travelDate' => TD, 'amount' => 1, 'paxCount' => 500], $officeId), 'at most');
    check('500 passengers on one paper ticket is refused', $r !== null && !str_starts_with($r, 'WRONG'), (string) $r);
    $r = refused(fn() => AgentWallet::recordOfflineTicket(['agentId' => $agentId, 'paperNo' => 'MG-2', 'name' => 'Greedy', 'travelDate' => TD, 'amount' => 10, 'paxCount' => 5], $officeId), 'too low');
    check('₹10 for five passengers is refused (fare floor)', $r !== null && !str_starts_with($r, 'WRONG'), (string) $r);
    $ok = AgentWallet::recordOfflineTicket(['agentId' => $agentId, 'paperNo' => 'MG-3', 'name' => 'Honest', 'travelDate' => TD, 'amount' => 3600, 'paxCount' => 2], $officeId);
    check('two passengers at a real fare is recorded', (int) ($ok['id'] ?? 0) > 0, 'commission ' . ($ok['commission'] ?? '?'));
    check('no ledger row for the refused tickets', (int) Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a AND ref IN ('MG-1','MG-2')", ['a' => $agentId], 0) === 0);
    Settings::set('agent_offline_tickets', $offOn, 'bool', 'agents', false);

    /* ---------------------------------------------------------------- */
    echo "\n-- M2: commission only for an agent (commission_agent_only) --\n";
    $agentOnly = Settings::getBool('commission_agent_only', false);
    Settings::set('commission_agent_only', false, 'bool', 'agents', false);
    $bOff0 = BookingService::counterSale($route, $sid, TD, ['L12'], $pax('Office Sale Off'), $officeId, 'counter');
    $rows0 = (int) Database::scalar('SELECT COUNT(*) FROM agent_ledger WHERE booking_id = :b', ['b' => (int) $bOff0['id']], 0);
    check('switch OFF (default): an office sale keeps its ledger rows, as live does today', $rows0 > 0, "rows=$rows0");
    Settings::set('commission_agent_only', true, 'bool', 'agents', false);
    $bOffice = BookingService::counterSale($route, $sid, TD, ['L3'], $pax('Office Sale'), $officeId, 'counter');
    $rows = (int) Database::scalar('SELECT COUNT(*) FROM agent_ledger WHERE booking_id = :b', ['b' => (int) $bOffice['id']], 0);
    check('an office (manager) sale writes NO agent ledger rows', $rows === 0, "rows=$rows");
    $bAgent = BookingService::counterSale($route, $sid, TD, ['L4'], $pax('Agent Sale'), $agentId, 'agent');
    $comm = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission'", ['b' => (int) $bAgent['id']], 0);
    check('an agent sale accrues commission', $comm > 0, "₹$comm");
    Settings::set('commission_agent_only', $agentOnly, 'bool', 'agents', false);
    check('AgentWallet::isAgentRow tells them apart', AgentWallet::isAgentRow($agentId) && !AgentWallet::isAgentRow($officeId) && !AgentWallet::isAgentRow(0));

    /* ---------------------------------------------------------------- */
    echo "\n-- M3: proof on a paid booking --\n";
    $pay = Database::fetch('SELECT id, status FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $bOffice['id']]);
    Database::update('payments', ['status' => 'verified'], 'id = :id', ['id' => (int) $pay['id']]);
    $r = refused(fn() => BookingService::submitPaymentProof((string) $bOffice['pnr'], ['utr' => '999999999999']), 'already paid');
    check('a second proof on a verified payment is refused', $r !== null && !str_starts_with($r, 'WRONG'), (string) $r);
    $st = (string) Database::scalar('SELECT status FROM payments WHERE id = :id', ['id' => (int) $pay['id']], '');
    check('…and the payment stays verified', $st === 'verified', $st);

    /* ---------------------------------------------------------------- */
    echo "\n-- M4: coupon redemption --\n";
    $cid = (int) Database::insert('coupons', ['code' => 'MGTEST50', 'title' => 'MG test', 'discount_type' => 'flat', 'discount_value' => 50, 'max_discount' => null, 'min_amount' => 0, 'route_id' => null, 'usage_limit' => 1, 'used_count' => 0, 'per_user_limit' => 1, 'valid_from' => null, 'valid_until' => null, 'is_active' => 1]);
    $req = ['routeId' => $routeId, 'travelDate' => TD, 'seats' => ['L7'], 'passengers' => [['name' => 'Coupon One', 'gender' => 'Male', 'age' => 30]],
            'contact' => ['phone' => PHONE], 'bookingMode' => 'sharing', 'cabinType' => 'single', 'isCod' => true, 'paymentMethod' => 'cod', 'couponCode' => 'MGTEST50'];
    $b1 = BookingService::create($req, ['adminId' => $officeId, 'source' => 'admin', 'paymentMethod' => 'cash']);
    $used = (int) Database::scalar('SELECT used_count FROM coupons WHERE id = :id', ['id' => $cid], 0);
    $red  = (int) Database::scalar('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = :id AND booking_id = :b', ['id' => $cid, 'b' => (int) $b1['id']], 0);
    check('the coupon priced the booking', (float) $b1['coupon_discount'] > 0, 'discount ' . $b1['coupon_discount']);
    check('used_count is 1 after one booking', $used === 1, "used_count=$used");
    check('a coupon_redemptions row names the booking', $red === 1);
    $req['seats'] = ['L8']; $req['passengers'][0]['name'] = 'Coupon Two';
    $b2 = BookingService::create($req, ['adminId' => $officeId, 'source' => 'admin', 'paymentMethod' => 'cash']);
    check('a second booking gets no discount from a fully redeemed coupon', (float) $b2['coupon_discount'] === 0.0, 'discount ' . $b2['coupon_discount']);

    /* ---------------------------------------------------------------- */
    echo "\n-- M5: two per-seat cancels on one family ticket --\n";
    $bFam = BookingService::counterSale($route, $sid, TD, ['L9', 'L10', 'L11'], $pax('Family'), $agentId, 'agent');
    $full = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission'", ['b' => (int) $bFam['id']], 0);
    BookingService::cancelSeat((int) $bFam['id'], 'L9', $officeId, 'test');
    BookingService::cancelSeat((int) $bFam['id'], 'L10', $officeId, 'test');
    $void = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission_void'", ['b' => (int) $bFam['id']], 0);
    $per  = AgentWallet::commissionForBooking((float) $bFam['fare_per_seat'], $agentId, 1);
    check('the void row carries BOTH cancelled seats', abs($void + 2 * $per) < 0.01, "void=$void per-seat=$per full=$full");
    check('…in one row (UNIQUE respected)', (int) Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission_void'", ['b' => (int) $bFam['id']], 0) === 1);

    /* ---------------------------------------------------------------- */
    echo "\n-- M6: hold cap --\n";
    $cap = Settings::getInt('max_seats_per_booking', MAX_SEATS_BOOKING);
    $free = array_values(array_filter(['U1','U2','U3','U4','U5','U6','U7','U8','U9','U10'], static fn($s) => true));
    $r = refused(fn() => Seats::lock($sid, array_slice($free, 0, $cap + 1), 'mg-greedy'), 'at most');
    check("holding cap+1 ($cap+1) seats at once is refused", $r !== null && !str_starts_with($r, 'WRONG'), (string) $r);
    $ok = Seats::lock($sid, array_slice($free, 0, min(2, $cap)), 'mg-fair');
    check('two seats hold normally', count($ok['locked'] ?? []) === min(2, $cap));
    $r = refused(fn() => Seats::lock($sid, array_slice($free, 2, $cap), 'mg-fair'), 'at most');
    check('…and topping up past the cap with the same token is refused', $r !== null && !str_starts_with($r, 'WRONG'));
    $staff = Seats::lock($sid, array_slice($free, 0, min(8, count($free))), 'mg-staff', true);
    check('staff (counter party cap) may hold more', count($staff['locked'] ?? []) >= min(8, count($free)) || ($staff['failed'] ?? []) !== []);
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    cleanup();
}

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
