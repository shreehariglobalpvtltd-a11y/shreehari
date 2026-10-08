<?php
/**
 * =====================================================================
 *  extra-seats-test.php — Extra seats EX1..EX20 (owner ask, 8 Oct 2026)
 *
 *    • a customer (no staff session) can neither hold nor buy an EX seat,
 *      and never sees the `extra` block in availability
 *    • an agent / counter sells EX seats through the normal booking path:
 *      confirmed, cash payment, ticket, commission, passenger on the seat
 *    • a party may mix berths and EX seats in one ticket
 *    • EX seats are not berths: the fill (occupiedBeds, seats_booked) and
 *      the public berth lists ignore them; no cabin gender lock is written
 *    • the same EX seat can never be sold twice; EX21 does not exist;
 *      private mode refuses EX; count 0 switches the feature off
 *    • transfer EX → berth and berth → EX works
 *
 *  Creates real bookings on a far-future date and cleans up after itself.
 *    php -c .claude/php-dev.ini tests/extra-seats-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

const XS_PHONE = '910000881';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(string $l, callable $fn, string $needle = ''): void {
    try { $fn(); check($l . ' (expected a refusal)', false); }
    catch (Throwable $e) { check($l, $needle === '' || stripos($e->getMessage(), $needle) !== false, $e->getMessage()); }
}

echo "\n=== Extra seats EX1..EX20 ===\n\n";

$w = bookingWindow(); $D = addDaysISO($w['from'], 26);
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
if ($route === null) { echo "  SKIP  no active sleeper route\n"; exit(0); }
$rid = (int) $route['id'];

$prevCount = Settings::getInt('extra_seats_per_bus', 20);
Settings::set('extra_seats_per_bus', '20', 'int', 'booking');
Settings::set('counter_max_seats_per_booking', 20, 'int', 'booking', false);
Database::run('UPDATE coupons SET auto_apply = 7 WHERE auto_apply = 1');   // park a running offer

$agentId = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => 'xs-agent'], 0);
if ($agentId === 0) {
    $agentId = (int) Database::insert('admins', [
        'username' => 'xs-agent', 'password_hash' => password_hash('Xs@12345', PASSWORD_BCRYPT),
        'full_name' => 'Extra Seat Agent', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0,
    ]);
}
$asAgent = static function () use ($agentId): void {
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $agentId, 'username' => 'xs-agent', 'role' => 'agent', 'permissions' => [], 'last_seen' => time(), 'logged_in_at' => time()];
};
$asCustomer = static function (): void { unset($_SESSION[ADMIN_SESSION_KEY]); };

$cleanup = function () use ($D, $agentId): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . XS_PHONE . "%'") as $r) {
        try { Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => $D]);
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
        try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $s['id']]); } catch (Throwable $e) {}
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
};
$cleanup();

$req = function (array $seats, string $suffix, array $over = [], string $gender = 'Male') use ($rid, $D): array {
    $pax = [];
    foreach ($seats as $i => $s) { $pax[] = ['seat' => $s, 'name' => 'Extra Pax ' . ($i + 1), 'age' => 30, 'gender' => $gender]; }
    return array_merge([
        'routeId' => $rid, 'travelDate' => $D, 'seats' => $seats, 'passengers' => $pax,
        'contact' => ['phone' => XS_PHONE . $suffix], 'bookingMode' => 'sharing',
        'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '', 'referralCode' => '',
    ], $over);
};
$seller = static fn(): array => ['adminId' => $agentId, 'source' => 'agent', 'paymentMethod' => 'cash'];

try {
    $sched = Seats::schedule($rid, $D);
    $sid   = (int) $sched['id'];

    echo "-- 1. Helpers --\n";
    check('EX1 / EX20 are extra seats', Seats::isExtraSeat('EX1') && Seats::isExtraSeat('ex20'));
    check('L1, 1A, EX0, EXA are not', !Seats::isExtraSeat('L1') && !Seats::isExtraSeat('1A') && !Seats::isExtraSeat('EX0') && !Seats::isExtraSeat('EXA'));
    check('20 ids EX1..EX20', Seats::extraSeatIds() === array_map(static fn($i) => 'EX' . $i, range(1, 20)));
    check('physical namespace is identity in private mode too', Seats::physicalSeats('EX3', 'private', 'sleeper') === ['EX3']);

    echo "-- 2. A customer cannot touch them --\n";
    $asCustomer();
    $av = Seats::availabilityForSchedule($sid, 'sharing');
    check('no extra block for a customer', $av['extra'] === null);
    check('EX not in the public seat list', !in_array('EX1', $av['all'], true));
    $lk = Seats::lock($sid, ['EX1'], 'xs-cust-token', false, 'sharing');
    check('customer hold on EX1 refused', !$lk['ok'] && in_array('EX1', $lk['failed'], true));
    throws('customer booking on EX1 refused', fn() => BookingService::create($req(['EX1'], '01')), 'counter');

    echo "-- 3. An agent sells them --\n";
    $asAgent();
    $av = Seats::availabilityForSchedule($sid, 'sharing');
    check('agent sees 20 extra seats, all free', is_array($av['extra']) && count($av['extra']['available']) === 20);
    $lk = Seats::lock($sid, ['EX2'], 'xs-agent-token', false, 'sharing');
    check('agent may hold EX2', $lk['ok'], json_encode($lk['failed']));
    Seats::release($sid, ['EX2'], 'xs-agent-token', 'sharing');

    $occBefore = Seats::occupiedBeds($sid);
    $b1 = BookingService::create($req(['EX1', 'EX2'], '02'), $seller());
    check('booking on EX1+EX2 created', !empty($b1['pnr']), (string) ($b1['pnr'] ?? ''));
    check('confirmed at once', ($b1['status'] ?? '') === 'confirmed');
    check('attributed to the agent', (int) ($b1['sold_by_admin_id'] ?? 0) === $agentId);
    check('priced like two normal seats', (float) $b1['total_amount'] > 0, 'total=' . $b1['total_amount']);
    $paxSeats = array_column(Database::fetchAll('SELECT seat_no FROM booking_passengers WHERE booking_id = :b ORDER BY seat_no', ['b' => (int) $b1['id']]), 'seat_no');
    check('passengers sit on EX1 / EX2', $paxSeats === ['EX1', 'EX2'], json_encode($paxSeats));
    $led = Database::fetch("SELECT amount FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission'", ['b' => (int) $b1['id']]);
    check('commission accrued for the extra seats', $led !== null && (float) $led['amount'] > 0, 'amount=' . ($led['amount'] ?? 'none'));
    check('berth fill unchanged by extra seats', Seats::occupiedBeds($sid) === $occBefore, Seats::occupiedBeds($sid) . ' vs ' . $occBefore);
    check('seats_booked counts berths only', (int) Database::scalar('SELECT seats_booked FROM schedules WHERE id = :s', ['s' => $sid], -1) === $occBefore);
    check('extraOccupiedFor says 2', (Seats::extraOccupiedFor([$sid])[$sid] ?? 0) === 2);
    check('no gender lock row for an extra seat', !Database::exists("SELECT 1 FROM schedule_unit_locks WHERE schedule_id = :s AND unit_key LIKE 'S-EX%'", ['s' => $sid]));

    $av = Seats::availabilityForSchedule($sid, 'sharing');
    check('EX1/EX2 now booked in the extra block', $av['extra']['booked'] === ['EX1', 'EX2']);
    check('public booked list stays berths only', !in_array('EX1', $av['booked'], true));
    check('label reads Extra seat', Seats::label('EX1') === 'Extra seat · EX1');

    echo "-- 4. Refusals --\n";
    throws('EX1 cannot be sold twice', fn() => BookingService::create($req(['EX1'], '03'), $seller()), 'taken');
    throws('EX21 does not exist', fn() => BookingService::create($req(['EX21'], '04'), $seller()), 'does not exist');
    throws('private mode refuses extra seats', fn() => BookingService::create($req(['EX5'], '05', ['bookingMode' => 'private', 'cabinType' => 'single']), $seller()), 'private');

    echo "-- 5. Mixed party + women on extra seats --\n";
    $b2 = BookingService::create($req(['L20', 'EX3'], '06', [], 'Female'), $seller());
    check('berth + extra seat in one ticket', !empty($b2['pnr']) && (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b AND released_at IS NULL', ['b' => (int) $b2['id']], 0) === 2);
    $b3 = BookingService::create($req(['EX4'], '07', [], 'Male'), $seller());
    check('a man next to a woman on extra seats is not blocked (no cabin)', !empty($b3['pnr']));

    echo "-- 6. Transfer --\n";
    $t1 = Seats::transferSeat($sid, 'EX4', 'L30', 'sleeper', $agentId, 'berth freed');
    check('EX4 → L30 moved', ($t1['to'] ?? '') === 'L30');
    $t2 = Seats::transferSeat($sid, 'L30', 'EX6', 'sleeper', $agentId, 'back to extra');
    check('L30 → EX6 moved', ($t2['to'] ?? '') === 'EX6');

    echo "-- 7. Count 0 switches it off --\n";
    Settings::set('extra_seats_per_bus', '0', 'int', 'booking');
    check('no extra seats when count is 0', Seats::extraSeatIds() === []);
    throws('EX7 refused when off', fn() => BookingService::create($req(['EX7'], '08'), $seller()), 'does not exist');
    $av = Seats::availabilityForSchedule($sid, 'sharing');
    check('extra block hidden when off', $av['extra'] === null);
} finally {
    $asCustomer();
    Settings::set('extra_seats_per_bus', (string) $prevCount, 'int', 'booking');
    $cleanup();
    try { Database::run('UPDATE coupons SET auto_apply = 1 WHERE auto_apply = 7'); } catch (Throwable $e) {}
    try { Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $agentId]); } catch (Throwable $e) {}
    try { Database::delete('admins', 'id = :i', ['i' => $agentId]); } catch (Throwable $e) {}
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
