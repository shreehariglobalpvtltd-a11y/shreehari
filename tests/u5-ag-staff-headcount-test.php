<?php
/**
 * u5 (19 Nov 2026) — AG-1 per-head tracking + AG-3 staff = manpower only.
 *
 *   php -c .claude/php-dev.ini tests/u5-ag-staff-headcount-test.php
 *
 * Proves:
 *   - staff_commission_off (in-memory only here) stops NEW commission for office / staff
 *     logins (support, manager, superadmin-like roles) but never for role 'agent' or a
 *     'counter' desk WITHOUT a staff number; a counter login holding a SHG-S staff number is
 *     staff while staff_commission_counter_staff_off = 1 (review fix); switched off, staff
 *     earn exactly as before;
 *   - accrue() writes no commission row for staff while cash_due is still written;
 *   - AgentWallet::perHeadRate (moved from ticket-statement.php) says company / own / staff;
 *   - AgentWallet::headcount counts tickets, passengers (outbound seat_count), collected
 *     money and commission the same way as Ticket Statement; cancelled apart.
 *
 * Throwaway rows only: admins 'zztest-u5-*' (phones 90000xxxxx), bookings 'ZZU5…' —
 * deleted at the end. Settings are changed in memory only (shared test DB). CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentwallet.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? ' — ' . $extra : '') . "\n"; }
}
function money_is(float $a, float $b): bool { return abs($a - $b) < 0.005; }
/** settings in memory only: nothing global is written (the test DB is shared) */
function u5_set(array $ovr): void {
    Settings::get('company_name');
    $rp = new ReflectionProperty('Settings', 'cache');
    $c = $rp->getValue();
    foreach ($ovr as $k => $v) {
        $c[$k] = ['value' => $v, 'type' => in_array($v, ['0', '1'], true) ? 'bool' : 'string', 'public' => false];
    }
    $rp->setValue(null, $c);
}

$tag = (string) random_int(1000, 9999);
$ids = [];
$bookings = [];

function u5_admin(string $role, string $tag, int $n): int {
    return (int) Database::insert('admins', [
        'username' => 'zztest-u5-' . $role . '-' . $tag . '-' . $n,'password_hash' => 'x', 'full_name' => 'ZZTEST u5 ' . $role,
        'phone' => '90000' . $tag . $n, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0,
    ]);
}
function u5_booking(int $seller, string $status, int $seats, float $total, string $when, int $sid, string $method = 'cash'): int {
    global $bookings;
    $pnr = 'ZZU5' . strtoupper(bin2hex(random_bytes(4)));
    $id = (int) Database::insert('bookings', [
        'pnr' => $pnr, 'contact_phone' => '9000000000', 'status' => $status, 'total_amount' => $total,
        'sold_by_admin_id' => $seller, 'created_at' => $when,
    ]);
    Database::insert('booking_legs', ['booking_id' => $id, 'schedule_id' => $sid, 'leg_type' => 'outbound',
        'travel_date' => '2099-11-19', 'seat_count' => $seats, 'fare_per_seat' => $seats > 0 ? $total / $seats : 0, 'leg_total' => $total]);
    try {
        Database::insert('payments', ['booking_id' => $id, 'payment_ref' => 'ZZU5-' . $id, 'method' => $method, 'amount' => $total, 'status' => 'verified']);
    } catch (Throwable $e) {
        // payments shape differs: cash_due part is then skipped by the test below
    }
    $bookings[] = $id;
    return $id;
}

echo "\n=== u5 AG-1 / AG-3: per-head + staff manpower ===\n\n";
try {
    $sid = (int) Database::scalar('SELECT id FROM schedules ORDER BY id LIMIT 1', [], 0);
    if ($sid <= 0) { echo "  no schedule row — cannot build legs\n"; exit(1); }

    $agent   = $ids[] = u5_admin('agent', $tag, 1);
    $staff   = $ids[] = u5_admin('support', $tag, 2);
    $counter = $ids[] = u5_admin('counter', $tag, 3);
    $manager = $ids[] = u5_admin('manager', $tag, 4);
    u5_set(['agent_commission_mode' => 'flat_per_seat']);
    $flatA = AgentWallet::flatRateFor($agent);
    $flatS = AgentWallet::flatRateFor($staff);
    $flatC = AgentWallet::flatRateFor($counter);

    /* ---- AG-3 switch ON (the code default) ---- */
    u5_set(['staff_commission_off' => '1']);
    check('staff (support) earns 0 new commission', money_is(AgentWallet::commissionForBooking(2000, $staff, 2), 0.0));
    check('manager earns 0 new commission', money_is(AgentWallet::commissionForBooking(2000, $manager, 2), 0.0));
    check('agent still earns ₹/passenger × 2', money_is(AgentWallet::commissionForBooking(2000, $agent, 2), $flatA * 2),
        (string) AgentWallet::commissionForBooking(2000, $agent, 2));
    check('counter desk still earns (unchanged)', money_is(AgentWallet::commissionForBooking(2000, $counter, 2), $flatC * 2));
    check('staff ledger note says passenger count only', str_contains(AgentWallet::commissionNoteFor($staff, 2, 2000), 'passenger count only'));
    check('staffCommissionOff: support yes, agent no, counter (no staff number) no',
        AgentWallet::staffCommissionOff($staff) && !AgentWallet::staffCommissionOff($agent) && !AgentWallet::staffCommissionOff($counter));

    /* review fix: a counter login that holds a SHG-S staff number is staff (the desk lists say
       "staff (no commission)" for every SHG-S entry) — unless staff_commission_counter_staff_off = 0 */
    $cstaff = $ids[] = u5_admin('counter', $tag, 5);
    $sno = 900000 + random_int(1, 99999);
    Database::insert('ap_members', ['admin_id' => $cstaff, 'card_token' => substr(bin2hex(random_bytes(12)), 0, 24), 'staff_no' => $sno]);
    check('staffNoOf reads the SHG-S number', AgentWallet::staffNoOf($cstaff) === $sno && AgentWallet::staffNoOf($counter) === 0);
    check('counter login WITH a SHG-S number earns 0 (switch default ON)',
        AgentWallet::staffCommissionOff($cstaff) && money_is(AgentWallet::commissionForBooking(2000, $cstaff, 2), 0.0));
    check('perHeadRate(counter staff) = staff', AgentWallet::perHeadRate($cstaff)['source'] === 'staff');
    $deskStaff = array_values(array_filter(AgentWallet::sellableAgents(), static fn(array $x): bool => $x['type'] === 'staff' && $x['code'] === AgentWallet::staffCodeLabel($cstaff)));
    check('the same login is a "staff" entry in the desk list (label and ledger now agree)', count($deskStaff) === 1);
    u5_set(['staff_commission_counter_staff_off' => '0']);
    check('staff_commission_counter_staff_off=0: counter staff earn again', !AgentWallet::staffCommissionOff($cstaff)
        && money_is(AgentWallet::commissionForBooking(2000, $cstaff, 2), AgentWallet::flatRateFor($cstaff) * 2));
    check('staff_commission_counter_staff_off=0: office staff (support) still 0', AgentWallet::staffCommissionOff($staff));
    u5_set(['staff_commission_counter_staff_off' => '1']);
    $r = AgentWallet::perHeadRate($staff);
    check('perHeadRate(staff) = staff source, ₹0', $r['source'] === 'staff' && money_is($r['amount'], 0.0));
    $r = AgentWallet::perHeadRate($agent);
    check('perHeadRate(agent) = company ₹/passenger', $r['source'] === 'company' && money_is($r['amount'], $flatA) && str_contains($r['label'], '/ passenger'), json_encode($r));
    AgentWallet::setCommissionOverride($agent, 'flat', 275.0, 0);
    $r = AgentWallet::perHeadRate($agent);
    check('perHeadRate(agent with own rate) = own ₹275', $r['source'] === 'own' && money_is($r['amount'], 275.0));
    check('commissionForBooking follows the own rate (275 × 3)', money_is(AgentWallet::commissionForBooking(3000, $agent, 3), 825.0));
    AgentWallet::setCommissionOverride($agent, null, null, 0);

    /* accrue: staff → no commission row, cash_due kept; agent → commission row */
    $now = date('Y-m-d H:i:s');
    $bs = u5_booking($staff, 'confirmed', 2, 3000.0, $now, $sid);
    AgentWallet::accrue(Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $bs]));
    check('accrue: staff sale writes NO commission row',
        !Database::exists("SELECT 1 FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission'", ['b' => $bs]));
    if (Database::exists('SELECT 1 FROM payments WHERE booking_id = :b', ['b' => $bs])) {
        check('accrue: staff cash sale still writes cash_due',
            Database::exists("SELECT 1 FROM agent_ledger WHERE booking_id = :b AND entry_type = 'cash_due'", ['b' => $bs]));
    }
    $ba = u5_booking($agent, 'confirmed', 3, 4500.0, $now, $sid);
    AgentWallet::accrue(Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $ba]));
    check('accrue: agent sale writes the commission row',
        Database::exists("SELECT 1 FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission' AND amount > 0", ['b' => $ba]));

    /* ---- AG-3 switch OFF: as before ---- */
    u5_set(['staff_commission_off' => '0']);
    check('switch off: staff earns the flat rate again (today\'s behaviour)', money_is(AgentWallet::commissionForBooking(2000, $staff, 2), $flatS * 2));
    check('switch off: perHeadRate(staff) is a rate again', AgentWallet::perHeadRate($staff)['source'] !== 'staff');
    u5_set(['staff_commission_off' => '1']);

    /* ---- AG-1 headcount ---- */
    $today = date('Y-m-d');
    u5_booking($agent, 'pending', 1, 1500.0, $now, $sid);                  // desk pending counts (as Ticket Statement)
    u5_booking($agent, 'cancelled', 2, 3000.0, $now, $sid);                // apart, never in totals
    u5_booking($agent, 'confirmed', 4, 6000.0, date('Y-m-d H:i:s', strtotime('-40 days')), $sid);   // older
    $h = AgentWallet::headcount($agent, $today, $today);
    check('headcount today: 2 tickets (confirmed + desk pending)', $h['tickets'] === 2, json_encode($h));
    check('headcount today: 4 passengers (3 + 1)', $h['heads'] === 4, json_encode($h));
    check('headcount today: ₹6,000 ticket value (sales; collected kept as alias)', money_is($h['sales'], 6000.0) && money_is($h['collected'], $h['sales']));
    check('headcount today: 2 cancelled passengers apart', $h['cancelled'] === 2);
    // (no booking_seats rows in this test, so accrue() paid for 1 seat: compare with the ledger itself)
    $led = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM agent_ledger WHERE booking_id = :b AND entry_type IN ('commission','commission_void')", ['b' => $ba], 0);
    check('headcount today: commission = the ledger rows on those tickets', $led > 0 && money_is($h['commission'], $led), json_encode($h) . ' ledger ' . $led);
    $all = AgentWallet::headcount($agent, '2000-01-01', $today);
    check('headcount all time: 8 passengers, 3 tickets', $all['heads'] === 8 && $all['tickets'] === 3, json_encode($all));
    $set = AgentWallet::headcountSet($agent);
    check('headcountSet has today / month / all', isset($set['today'], $set['month'], $set['all']) && $set['today']['heads'] === 4);
    $hs = AgentWallet::headcount($staff, $today, $today);
    check('staff still counted as passengers brought (2 जना, ₹0 commission)', $hs['heads'] === 2 && money_is($hs['commission'], 0.0), json_encode($hs));
    check('bad dates give zeros, not an error', AgentWallet::headcount($agent, 'x', $today)['tickets'] === 0);
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach ($bookings as $b) {
        Database::delete('agent_ledger', 'booking_id = :b', ['b' => $b]);
        try { Database::delete('payments', 'booking_id = :b', ['b' => $b]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => $b]);
    }
    foreach ($ids as $a) {
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $a]);
        try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $a]); } catch (Throwable $e) {}
        try { Database::delete('ap_members', 'admin_id = :a', ['a' => $a]); } catch (Throwable $e) {}
        Database::delete('admins', 'id = :a', ['a' => $a]);
    }
    // the override map lives in settings: make sure nothing of ours is left there
    try { if (isset($agent)) { AgentWallet::setCommissionOverride($agent, null, null, 0); } } catch (Throwable $e) {}
}
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
