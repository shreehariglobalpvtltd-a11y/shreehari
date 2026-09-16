<?php
/**
 * Reschedule (BookingService::rebookLeg) — move a booking's outbound leg to a
 * different date on the SAME route, keeping PNR / payment / commission and
 * re-minting the signed ticket QR. CLI only.
 *   php -c .claude/php-dev.ini tests/reschedule-test.php
 * Throwaway rows (SHG-TEST-) on far-future dates; cleans up after itself.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/tripstatus.php';
require_once INCLUDE_PATH . '/booking.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; } }
function expectThrow(string $l, callable $fn): void { try { $fn(); check($l . ' (expected rejection)', false); } catch (Throwable $e) { check($l . ' → "' . $e->getMessage() . '"', true); } }

const TDA = '2099-11-20';   // source date
const TDB = '2099-11-21';   // target date (same route)

function seatCount(int $sid): int { return (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE schedule_id = :s AND released_at IS NULL', ['s' => $sid], 0); }
function glock(int $sid, string $u): string { return (string) Database::scalar('SELECT gender_lock FROM schedule_unit_locks WHERE schedule_id = :s AND unit_key = :u', ['s' => $sid, 'u' => $u], 'none'); }

function cleanupAll(): void {
    foreach (pluck(Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE 'SHG-TEST-%'"), 'id') as $id) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $id]);   // FK cascade drops legs/seats/passengers/payments/tickets
    }
    foreach ([TDA, TDB] as $d) {
        foreach (pluck(Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $d]), 'id') as $sid) {
            Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $sid]);
        }
        Database::delete('booking_legs', 'travel_date = :d', ['d' => $d]);
        Database::delete('schedules', 'travel_date = :d', ['d' => $d]);
    }
}

/** Build a confirmed booking on $sid/$date with $seat, issue its ticket. */
function mkBooking(int $sid, string $coach, string $seat, string $gender, string $date): int {
    return Database::transaction(function () use ($sid, $coach, $seat, $gender, $date): int {
        Seats::assertAvailable($sid, [$seat], 't' . bin2hex(random_bytes(3)));
        Seats::assertGenderAllowed($sid, $coach, 'sharing', [$seat], [$gender]);
        $bid = Database::insert('bookings', [
            'pnr' => 'SHG-TEST-' . strtoupper(bin2hex(random_bytes(4))),
            'trip_type' => 'oneway', 'booking_mode' => 'sharing',
            'contact_phone' => '919000000000', 'fare_per_seat' => 1800, 'base_total' => 1800,
            'total_amount' => 1800, 'currency' => 'INR', 'status' => 'confirmed',
            'confirmed_at' => date('Y-m-d H:i:s'), 'source' => 'counter',
        ]);
        $lid = Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'leg_type' => 'outbound', 'travel_date' => $date, 'fare_per_seat' => 1800, 'seat_count' => 1, 'leg_total' => 1800]);
        Seats::claim($sid, [$seat], $bid, $lid);
        Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $lid, 'passenger_ref' => 'PAX' . strtoupper(bin2hex(random_bytes(5))), 'seat_no' => $seat, 'full_name' => 'T ' . $seat, 'gender' => $gender, 'is_primary' => 1]);
        Database::insert('payments', ['booking_id' => $bid, 'payment_ref' => 'PAY' . strtoupper(bin2hex(random_bytes(5))), 'method' => 'cash', 'mode' => 'offline', 'amount' => 1800, 'currency' => 'INR', 'status' => 'verified', 'verified_at' => date('Y-m-d H:i:s')]);
        Ticket::issue($bid);
        return $bid;
    });
}

echo "\n=== Reschedule (rebookLeg) ===\n\n";

// Pin the pre-L5/L6 staff layout for the run (fixture uses cabin L-2 back).
Settings::set('staff_seats', ['sleeper' => ['L1'], 'seater' => ['1A']], 'json', 'seats', false);

$sidA = 0; $sidB = 0;
try {
    $route = Database::fetch("SELECT id, coach_type FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no sleeper route\n"; exit(1); }
    $rid = (int) $route['id']; $coach = (string) $route['coach_type'];

    cleanupAll();
    $sidA = (int) Seats::schedule($rid, TDA)['id'];
    $sidB = (int) Seats::schedule($rid, TDB)['id'];

    // ---- Happy path: move L3(Female) on date A → L7 on date B ----
    $bid = mkBooking($sidA, $coach, 'L3', 'Female', TDA);
    $tkBefore = Database::fetch('SELECT ticket_number, qr_payload, qr_hash, pdf_path FROM tickets WHERE booking_id = :b', ['b' => $bid]);
    check('setup: seat on A before', seatCount($sidA) === 1);
    check('setup: cabin L-2 on A is female_only', glock($sidA, 'L-2') === 'female_only');

    $res = BookingService::rebookLeg($bid, $sidB, ['L7'], TDB, 1);
    check('rebookLeg returns changed=true', ($res['changed'] ?? false) === true);

    // seat inventory moved
    check('old seat freed on A (count 0)', seatCount($sidA) === 0);
    check('new seat claimed on B (count 1)', seatCount($sidB) === 1);
    check('seat L3 sellable again on A', in_array('L3', Seats::availability($rid, TDA, 'sharing')['available'], true));
    check('seat L7 NOT available on B (taken)', !in_array('L7', Seats::availability($rid, TDB, 'sharing')['available'], true));

    // leg row moved, same leg id
    $leg = Database::fetch("SELECT schedule_id, travel_date, seat_count FROM booking_legs WHERE booking_id = :b AND leg_type='outbound'", ['b' => $bid]);
    check('leg schedule_id → B', (int) $leg['schedule_id'] === $sidB);
    check('leg travel_date → date B', (string) $leg['travel_date'] === TDB);

    // passenger seat re-pointed
    $paxSeat = (string) Database::scalar('SELECT seat_no FROM booking_passengers WHERE booking_id = :b LIMIT 1', ['b' => $bid]);
    check('passenger seat_no → L7', $paxSeat === 'L7');

    // gender locks: old cabin reopened, new cabin locked
    check('old cabin L-2 on A reopened (none)', glock($sidA, 'L-2') === 'none');
    check('new cabin L-4 on B is female_only', glock($sidB, 'L-4') === 'female_only');

    // money continuity untouched
    $bk = Database::fetch('SELECT status, total_amount FROM bookings WHERE id = :b', ['b' => $bid]);
    check('booking still confirmed', (string) $bk['status'] === 'confirmed');
    check('total_amount unchanged (1800)', (int) $bk['total_amount'] === 1800);
    check('exactly one payment row (no re-collect)', (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE booking_id = :b', ['b' => $bid], 0) === 1);

    // ticket re-minted: same number, new QR (date+seat), pdf regenerated
    $tkAfter = Database::fetch('SELECT ticket_number, qr_payload, qr_hash FROM tickets WHERE booking_id = :b', ['b' => $bid]);
    check('ticket_number preserved', (string) $tkAfter['ticket_number'] === (string) $tkBefore['ticket_number']);
    check('QR payload changed', (string) $tkAfter['qr_payload'] !== (string) $tkBefore['qr_payload']);
    check('QR hash changed', (string) $tkAfter['qr_hash'] !== (string) $tkBefore['qr_hash']);
    check('QR encodes new date B', strpos((string) $tkAfter['qr_payload'], TDB) !== false);
    check('QR encodes new seat L7', strpos((string) $tkAfter['qr_payload'], 'L7') !== false);
    check('QR no longer encodes old date A', strpos((string) $tkAfter['qr_payload'], TDA) === false);

    // ---- Adversarial ----
    expectThrow('cannot reschedule to the SAME schedule', fn() => BookingService::rebookLeg($bid, $sidB, ['L9'], TDB, 1));
    // wrong seat count
    expectThrow('cannot change the seat count', fn() => BookingService::rebookLeg($bid, $sidA, ['L11', 'L12'], TDA, 1));
    // different route (if a second sleeper route exists)
    $route2 = Database::fetch("SELECT id FROM routes WHERE coach_type='sleeper' AND is_active=1 AND id <> :id ORDER BY id LIMIT 1", ['id' => $rid]);
    if ($route2 !== null) {
        $sidC = (int) Seats::schedule((int) $route2['id'], TDA)['id'];
        expectThrow('cannot reschedule to a DIFFERENT route (fare would change)', fn() => BookingService::rebookLeg($bid, $sidC, ['L3'], TDA, 1));
    } else {
        echo "  (skip different-route test — only one sleeper route)\n";
    }

    // Atomicity: occupy L9 on date A, then try to move bid (now on B/L7) onto
    // the taken A/L9 — claim must throw AND the whole txn roll back, so bid
    // keeps its L7 on B (releaseLeg is not left half-applied).
    $bid2 = mkBooking($sidA, $coach, 'L9', 'Male', TDA);
    expectThrow('cannot land on an already-taken seat', fn() => BookingService::rebookLeg($bid, $sidA, ['L9'], TDA, 1));
    check('failed move rolled back — bid still holds L7 on B',
        seatCount($sidB) === 1 && !in_array('L7', Seats::availability($rid, TDB, 'sharing')['available'], true));
    check('the blocker booking still holds L9 on A', seatCount($sidA) === 1);

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanupAll();
    Settings::set('staff_seats', ['sleeper' => ['L5', 'L6'], 'seater' => ['1A']], 'json', 'seats', false);
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
