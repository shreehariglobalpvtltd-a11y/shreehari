<?php
/**
 * Feature C — admin seat management: integration test.
 * Proves a blocked seat is unbookable everywhere, a sold seat can't be
 * blocked, and the admin seat map carries status + channel attribution.
 *
 *   php -c .claude/php-dev.ini tests/seat-block-test.php
 *
 * Writes only throwaway rows (PNRs prefixed SHG-TEST-) on a far-future date
 * and cleans up after itself. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; } }
function expectThrow(string $l, callable $fn): void { try { $fn(); check($l . ' (expected rejection)', false); } catch (RuntimeException $e) { check($l . ' → "' . $e->getMessage() . '"', true); } }

const TD = '2099-11-30';
$sid = 0;

function cleanup(int $sid): void {
    if ($sid <= 0) return;
    foreach (pluck(Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE 'SHG-TEST-%'"), 'id') as $id) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $id]);
    }
    Database::delete('seat_blocks', 'schedule_id = :s', ['s' => $sid]);
    Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]);
    Database::delete('booking_legs', 'schedule_id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
    Database::delete('schedules', 'id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
}

echo "\n=== Feature C — admin seat management ===\n\n";

try {
    $route = Database::fetch("SELECT id, coach_type, route_code FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no active route\n"; exit(1); }
    $coach = (string) $route['coach_type'];
    $bt    = $coach === 'sleeper' ? 'sharing' : 'seater';

    $sch = Seats::schedule((int) $route['id'], TD); $sid = (int) $sch['id']; cleanup($sid);
    $sch = Seats::schedule((int) $route['id'], TD); $sid = (int) $sch['id'];

    $seat = $coach === 'sleeper' ? 'U7' : '5C';

    Seats::blockSeat($sid, $seat, 'broken berth', 1);
    check('blocked seat appears in blockedSeats()', in_array($seat, Seats::blockedSeats($sid), true));
    $av = Seats::availability((int) $route['id'], TD, $bt);
    check('availability() lists it blocked', in_array($seat, $av['blocked'], true));
    check('availability() excludes it from available', !in_array($seat, $av['available'], true));
    expectThrow('assertAvailable() rejects a blocked seat', fn() => Seats::assertAvailable($sid, [$seat], 'tok'));
    expectThrow('re-blocking the same seat is rejected', fn() => Seats::blockSeat($sid, $seat, 'x', 1));

    Seats::unblockSeat($sid, $seat, 1);
    check('after unblock the seat is available again', in_array($seat, Seats::availability((int) $route['id'], TD, $bt)['available'], true));

    // Sell a seat, then prove it cannot be blocked.
    $seat2 = $coach === 'sleeper' ? 'U9' : '6C';
    Database::transaction(function () use ($sid, $seat2, $coach) {
        Seats::assertAvailable($sid, [$seat2], 'tok2');
        Seats::assertGenderAllowed($sid, $coach, $coach === 'sleeper' ? 'sharing' : null, [$seat2], ['Male']);
        $bid = Database::insert('bookings', ['pnr' => 'SHG-TEST-' . strtoupper(bin2hex(random_bytes(4))), 'contact_phone' => '910000000000', 'status' => 'confirmed', 'source' => 'agent']);
        $lid = Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'leg_type' => 'outbound', 'travel_date' => TD, 'seat_count' => 1]);
        Seats::claim($sid, [$seat2], $bid, $lid);
        Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $lid, 'passenger_ref' => 'PAX' . strtoupper(bin2hex(random_bytes(5))), 'seat_no' => $seat2, 'full_name' => 'Sold Seat', 'gender' => 'Male']);
    });
    expectThrow('a sold seat cannot be blocked', fn() => Seats::blockSeat($sid, $seat2, 'x', 1));

    $m = Seats::adminSeatMap($sid, $coach);
    check('adminSeatMap() marks the sold seat booked', ($m[$seat2]['status'] ?? '') === 'booked');
    check('adminSeatMap() carries channel attribution', !empty($m[$seat2]['channel']));
    check('adminSeatMap() carries passenger gender', ($m[$seat2]['gender'] ?? '') === 'Male');
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage(), false);
} finally {
    cleanup($sid);
}

echo "\n----------------------------------------\n  $PASS passed, $FAIL failed\n----------------------------------------\n\n";
exit($FAIL === 0 ? 0 : 1);
