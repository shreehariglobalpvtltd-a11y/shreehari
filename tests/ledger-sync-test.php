<?php
/**
 * tests/ledger-sync-test.php — agent ledger follows the booking (3 Oct 2026).
 *
 * Runs the REAL paths (counterSale, cancelSeat, cancel, reject, accrue,
 * reassignSeller, syncCashDue, purgeBookingRows) and LedgerCheck on a
 * throwaway agent + far-future schedule, then cleans up:
 *  1. seat-cancel then full cancel nets the commission to exactly zero
 *  2. the per-seat void uses the commission actually credited
 *  3. reject -> re-approve after a seat-cancel pays only the seats left
 *  4. payment method cash <-> UPI moves "cash held" with it
 *  5. reassigning an unpaid cash-on-delivery ticket pays nothing yet
 *  6. summary()['earnedMonth'] is net of this month's reversals
 *  7. LedgerCheck finds + fixes: orphan rows, missing commission, cash mismatch
 *
 *   php -c .claude/php-dev.ini tests/ledger-sync-test.php
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
require_once INCLUDE_PATH . '/ledgercheck.php';

if (!str_contains(DB_NAME, 'test')) { fwrite(STDERR, "Refusing: DB_NAME is not a test database\n"); exit(2); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok   $l\n"; } else { $FAIL++; echo "  FAIL $l\n"; }
}
function money_is(float $a, float $b): bool { return abs($a - $b) < 0.005; }
function net(int $bookingId): float {
    return (float) Database::scalar("SELECT COALESCE(SUM(amount), 0) FROM agent_ledger WHERE booking_id = :b AND entry_type IN ('commission','commission_void')", ['b' => $bookingId], 0);
}
function cashDue(int $bookingId): ?float {
    $v = Database::scalar("SELECT amount FROM agent_ledger WHERE booking_id = :b AND entry_type = 'cash_due'", ['b' => $bookingId], null);
    return $v === null ? null : (float) $v;
}
function fresh(int $id): array { return Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $id]) ?? []; }

const TD = '2099-10-03';
$agents = []; $sid = 0;
$saved = [];
foreach (['agent_commission_mode', 'agent_commission_percent', 'agent_wallet_enabled', 'commission_agent_only'] as $k) {
    $saved[$k] = Database::fetch('SELECT svalue, stype, sgroup FROM settings WHERE skey = :k', ['k' => $k]);
}

function cleanup(array $agents, int $sid): void {
    foreach ($agents as $a) {
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $a]);
        foreach (pluck(Database::fetchAll('SELECT id FROM bookings WHERE sold_by_admin_id = :a', ['a' => $a]), 'id') as $id) {
            Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $id]);
            Database::delete('bookings', 'id = :i', ['i' => (int) $id]);
        }
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $a]);
        Database::delete('admins', 'id = :a', ['a' => $a]);
    }
    if ($sid > 0) {
        Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]);
        Database::delete('booking_legs', 'schedule_id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
        Database::delete('schedules', 'id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
    }
}

echo "\n=== Agent ledger follows the booking ===\n\n";
try {
    foreach (['ledgersync-a', 'ledgersync-b'] as $u) {
        $old = Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => $u]);
        if ($old !== null) { cleanup([(int) $old['id']], 0); }
        $agents[] = Database::insert('admins', ['username' => $u, 'password_hash' => password_hash('x-not-a-login', PASSWORD_BCRYPT),
            'full_name' => 'Ledger Sync ' . strtoupper(substr($u, -1)), 'role' => 'agent', 'is_active' => 1]);
    }
    [$A, $B] = $agents;

    $route = Database::fetch("SELECT * FROM routes WHERE coach_type = 'seater' AND is_active = 1 ORDER BY id LIMIT 1")
          ?? Database::fetch('SELECT * FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1');
    $sch = Seats::schedule((int) $route['id'], TD);
    $sid = (int) $sch['id'];
    $free = array_values(Seats::availability((int) $route['id'], TD)['available'] ?? []);
    if (count($free) < 10) { echo "not enough free seats\n"; exit(1); }

    Settings::set('agent_commission_mode', 'percent', 'string', 'agent');
    Settings::set('agent_commission_percent', '10', 'float', 'agent');
    Settings::set('agent_wallet_enabled', '1', 'bool', 'agent');
    Settings::set('commission_agent_only', '0', 'bool', 'agents');
    Settings::flush();

    $sell = static function (array $seats, float $amount, string $method, int $agent, string $phone) use ($route, $sid): array {
        return BookingService::counterSale($route, $sid, TD, $seats, [
            'name' => 'Ledger Test', 'phone' => $phone, 'amount' => $amount, 'paymentMethod' => $method,
        ], $agent, 'agent');
    };

    /* 1 + 2: seat-cancel, then cancel the rest */
    $b1 = $sell([$free[0], $free[1], $free[2]], 3000.0, 'cash', $A, '9800000101');
    check('3-seat cash sale credits 10% = Rs 300', money_is(net((int) $b1['id']), 300.0));
    BookingService::cancelSeat((int) $b1['id'], $free[0], 1, 'test seat cancel');
    check('one seat cancelled: commission left = Rs 200', money_is(net((int) $b1['id']), 200.0));
    BookingService::cancelSeat((int) $b1['id'], $free[1], 1, 'test seat cancel 2');
    check('second seat cancelled: Rs 100 left (share of what is still credited)', money_is(net((int) $b1['id']), 100.0));
    BookingService::cancel((string) $b1['pnr'], 'test full cancel', false);
    check('full cancel after seat-cancels nets commission to exactly 0', money_is(net((int) $b1['id']), 0.0));
    check('  LedgerCheck: nothing left to reverse on it',
        array_filter(LedgerCheck::find('not_reversed'), static fn($r) => $r['booking_id'] === (int) $b1['id']) === []);

    /* 3: reject -> re-approve after a seat-cancel */
    $b2 = $sell([$free[3], $free[4], $free[5]], 3000.0, 'upi', $A, '9800000102');
    BookingService::cancelSeat((int) $b2['id'], $free[3], 1, 'test');
    check('2-of-3 seats left: Rs 200', money_is(net((int) $b2['id']), 200.0));
    AgentWallet::voidFor(fresh((int) $b2['id']), 'test reject');
    check('rejected: Rs 0', money_is(net((int) $b2['id']), 0.0));
    Database::update('bookings', ['status' => 'confirmed'], 'id = :i', ['i' => (int) $b2['id']]);
    AgentWallet::accrue(fresh((int) $b2['id']));
    check('re-approved: pays the 2 seats left (Rs 200), not the original 3', money_is(net((int) $b2['id']), 200.0));

    /* 4: method correction moves cash held */
    $b3 = $sell([$free[6]], 1000.0, 'upi', $A, '9800000103');
    check('UPI sale holds no cash', cashDue((int) $b3['id']) === null);
    Database::update('payments', ['method' => 'cash'], 'booking_id = :b', ['b' => (int) $b3['id']]);
    check('corrected to cash -> cash held Rs 1000', AgentWallet::syncCashDue(fresh((int) $b3['id']), 't') === 'added' && money_is((float) cashDue((int) $b3['id']), 1000.0));
    Database::update('payments', ['method' => 'upi'], 'booking_id = :b', ['b' => (int) $b3['id']]);
    check('corrected back to UPI -> cash held removed', AgentWallet::syncCashDue(fresh((int) $b3['id']), 't') === 'removed' && cashDue((int) $b3['id']) === null);

    /* 5: reassign an unpaid COD ticket */
    $b4 = $sell([$free[7]], 1000.0, 'upi', $A, '9800000104');
    Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $b4['id']]);
    Database::update('bookings', ['is_cod' => 1], 'id = :i', ['i' => (int) $b4['id']]);
    Database::update('payments', ['status' => 'cod_pending', 'method' => 'cod'], 'booking_id = :b', ['b' => (int) $b4['id']]);
    $r = AgentWallet::reassignSeller(fresh((int) $b4['id']), $B, 1);
    check('reassigning an unpaid COD ticket credits nothing yet', money_is($r['commission'], 0.0) && money_is(net((int) $b4['id']), 0.0));
    Database::update('payments', ['status' => 'verified'], 'booking_id = :b', ['b' => (int) $b4['id']]);
    $r = AgentWallet::reassignSeller(fresh((int) $b4['id']), $A, 1);
    check('once the cash is collected, reassigning credits it (Rs 100)', money_is(net((int) $b4['id']), 100.0));

    /* 6: earnedMonth is net */
    $s = AgentWallet::summary($A);
    $expect = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM agent_ledger WHERE agent_admin_id = :a AND entry_type IN ('commission','commission_void')", ['a' => $A], 0);
    check('earned this month is net of reversals (' . $s['earnedMonth'] . ' = ' . $expect . ')', money_is($s['earnedMonth'], $expect));

    /* 7: LedgerCheck find + fix */
    $b5 = $sell([$free[8]], 1000.0, 'cash', $A, '9800000105');
    Database::delete('agent_ledger', "booking_id = :b AND entry_type = 'commission'", ['b' => (int) $b5['id']]);
    $nc = array_filter(LedgerCheck::find('not_credited'), static fn($r) => $r['booking_id'] === (int) $b5['id']);
    check('missing commission is found', count($nc) === 1);
    check('  and Fix credits it', LedgerCheck::fix('not_credited', (int) $b5['id']) === '' && money_is(net((int) $b5['id']), 100.0));

    Database::delete('agent_ledger', "booking_id = :b AND entry_type = 'cash_due'", ['b' => (int) $b5['id']]);
    $cm = array_filter(LedgerCheck::find('cash_mismatch'), static fn($r) => $r['booking_id'] === (int) $b5['id']);
    check('cash sale with no cash held is found', count($cm) === 1);
    check('  and Fix records the cash', LedgerCheck::fix('cash_mismatch', (int) $b5['id']) === '' && money_is((float) cashDue((int) $b5['id']), 1000.0));

    $ghost = 900000000 + random_int(1, 99999);
    Database::insert('agent_ledger', ['agent_admin_id' => $A, 'booking_id' => $ghost, 'account' => 'commission', 'entry_type' => 'commission', 'amount' => 50, 'ref' => 'GHOST']);
    Database::insert('agent_ledger', ['agent_admin_id' => $A, 'booking_id' => $ghost, 'account' => 'cash', 'entry_type' => 'cash_due', 'amount' => 500, 'ref' => 'GHOST']);
    $or = array_filter(LedgerCheck::find('orphan'), static fn($r) => $r['booking_id'] === $ghost);
    check('rows of a deleted booking are found', count($or) === 1);
    check('  and Fix removes them', LedgerCheck::fix('orphan', $ghost) === ''
        && (int) Database::scalar('SELECT COUNT(*) FROM agent_ledger WHERE booking_id = :b', ['b' => $ghost], 0) === 0);
    check('a fix on a booking already in order changes nothing', LedgerCheck::fix('not_credited', (int) $b5['id']) !== '');

    $all = LedgerCheck::run();
    $mine = 0;
    $left = [];
    foreach ($all as $kind => $rows) { foreach ($rows as $r) { if (in_array($r['booking_id'], [(int) $b1['id'], (int) $b2['id'], (int) $b3['id'], (int) $b4['id'], (int) $b5['id']], true)) { $left[] = $kind . ' ' . $r['pnr'] . ' ' . $r['stored'] . ' -> ' . $r['expected']; } } }
    // b4 had its rows deleted by hand above and is COD now collected: its cash
    // held is genuinely missing, and the check must say so.
    check('the one booking whose rows were removed by hand is flagged: ' . implode(' | ', $left), count($left) === 1 && str_starts_with($left[0], 'cash_mismatch ' . $b4['pnr']));
} catch (Throwable $e) {
    $FAIL++;
    echo '  FAIL exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    cleanup($agents, $sid);
    foreach ($saved as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::update('settings', ['svalue' => $row['svalue']], 'skey = :k', ['k' => $k]); }
    }
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
