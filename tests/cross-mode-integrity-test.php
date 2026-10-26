<?php
/**
 * cross-mode-integrity-test.php — the admin paths that MUTATE seats must reason
 * in physical beds, not in stored labels (8 Sep 2026).
 *
 * The sale gate (Seats::assertAvailable) has resolved occupancy in physical
 * space since 3 Sep, but three admin-side paths still compared raw
 * booking_seats.seat_no, which cannot see a cross-mode clash because private
 * cabin "L4" and sharing "L7" are simply different strings:
 *
 *   transferSeat()      validated the destination in the SHARING namespace even
 *                       for a private booking -> a real cross-mode oversell,
 *                       reachable from the seat map and from booking-view.
 *   blockSeat()         guarded against bookedSeats() (labels) -> an admin could
 *                       take a bed out of service that a passenger is lying on.
 *   assertBusSwapSafe() counted ROWS -> a private booking of N cabins counted N
 *                       while occupying 2N berths, so the capacity guard
 *                       under-counted by up to 2x.
 *
 *   php -c .claude/php-dev.ini tests/cross-mode-integrity-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

const XMI_PHONE = '910000773';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } }
/* Run a MUTATING call exactly once and report both whether it threw and why.
   throws()+whyNot() would invoke it twice, which for transferSeat means the
   second call reports "not currently booked" about the move the first one
   already made — a misleading message, and a second mutation besides. */
function attempt(callable $fn): array {
    try { $fn(); return ['threw' => false, 'msg' => '(did not throw)']; }
    catch (Throwable $e) { return ['threw' => true, 'msg' => $e->getMessage()]; }
}

echo "\n=== Cross-mode integrity of the admin mutation paths ===\n\n";

$w = bookingWindow(); $D = addDaysISO($w['from'], 28);
$cleanup = function () use ($D) {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . XMI_PHONE . "%'") as $r) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => $D]);
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
        Database::delete('seat_locks',  'schedule_id = :s', ['s' => (int) $s['id']]);
        Database::delete('seat_blocks', 'schedule_id = :s', ['s' => (int) $s['id']]);
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
};

$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
if ($route === null) {
    echo "  \033[33mSKIP\033[0m  no active sleeper route on this DB\n";
} else {
    $routeId = (int) $route['id'];
    $cleanup();
    try {
        // A PRIVATE cabin L6 -> physical beds L11 + L12.
        BookingService::create([
            'routeId' => $routeId, 'travelDate' => $D, 'seats' => ['L6'],
            'passengers' => [['name' => 'XMI Private', 'age' => 30, 'gender' => 'Male']],
            'contact' => ['phone' => XMI_PHONE . '0'],
            'bookingMode' => 'private', 'cabinType' => 'single', 'sharingTier' => 'single',
            'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '',
        ]);
        // A SHARING berth on a different bed, to move around.
        $shareBooking = BookingService::create([
            'routeId' => $routeId, 'travelDate' => $D, 'seats' => ['L20'],
            'passengers' => [['name' => 'XMI Sharing', 'age' => 28, 'gender' => 'Male']],
            'contact' => ['phone' => XMI_PHONE . '1'],
            'bookingMode' => 'sharing',
            'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '',
        ]);
        $sid = (int) Seats::schedule($routeId, $D)['id'];

        echo "-- blockSeat must see the beds under a private cabin --\n";
        $a = attempt(fn() => Seats::blockSeat($sid, 'L11', 'test', 1));
        check("blocking bed L11 (under private L6) is REFUSED", $a['threw'], $a['msg']);
        check("blocking bed L12 (the cabin's other bed) is REFUSED",
            throws(fn() => Seats::blockSeat($sid, 'L12', 'test', 1)));
        check("blocking a genuinely free bed L15 still WORKS",
            !throws(fn() => Seats::blockSeat($sid, 'L15', 'test', 1)));
        check("the label L6 itself — a bed nobody bought — is still blockable",
            !throws(fn() => Seats::blockSeat($sid, 'L6', 'test', 1)));

        echo "\n-- transferSeat must validate the destination in the booking's OWN mode --\n";
        // Move the SHARING passenger onto bed L11, which the private cabin holds.
        $a = attempt(fn() => Seats::transferSeat($sid, 'L20', 'L11', 'sleeper', 1));
        check("moving a sharing berth onto bed L11 (private-held) is REFUSED", $a['threw'], $a['msg']);
        // And the reverse: move the PRIVATE cabin onto a cabin whose beds are taken.
        // Private cabin L10 spans beds L19 + L20; L20 is sold in sharing mode.
        $a = attempt(fn() => Seats::transferSeat($sid, 'L6', 'L10', 'sleeper', 1));
        check("moving the private cabin onto L10 (its bed L20 is sold sharing) is REFUSED", $a['threw'], $a['msg']);
        $a = attempt(fn() => Seats::transferSeat($sid, 'L6', 'L14', 'sleeper', 1));
        check("moving the private cabin to a genuinely free cabin L14 WORKS", !$a['threw'], $a['msg']);
        // After that move the cabin is L14 -> beds L27+L28.
        $phys = Seats::bookedPhysical($sid);
        check("after the move the private cabin holds beds L27,L28",
            in_array('L27', $phys, true) && in_array('L28', $phys, true), implode(',', $phys));
        check("...and its old beds L11,L12 are free again",
            !in_array('L11', $phys, true) && !in_array('L12', $phys, true));

        echo "\n-- the bus-swap guard counts BEDS, not rows --\n";
        // 2 rows live (1 private cabin + 1 sharing berth) but 3 physical beds.
        $liveRows = (int) Database::scalar(
            'SELECT COUNT(*) FROM booking_seats WHERE schedule_id = :s AND released_at IS NULL',
            ['s' => $sid], 0
        );
        check("rows say 2 but physical beds say 3 (the whole point)",
            $liveRows === 2 && count(Seats::bookedPhysical($sid)) === 3,
            "rows={$liveRows} beds=" . count(Seats::bookedPhysical($sid)));
        $small = Database::fetch(
            "SELECT id FROM buses WHERE coach_type='sleeper' AND is_active=1 AND total_seats <= 2 LIMIT 1"
        );
        if ($small === null) {
            echo "  \033[33mSKIP\033[0m  no 2-seat sleeper on this DB to prove the capacity refusal\n";
        } else {
            check("a bus too small for the BEDS is refused",
                throws(fn() => Seats::assertBusSwapSafe($sid, (int) $small['id'])));
        }
    } catch (Throwable $e) {
        check('integrity assertions ran without a fatal', false, $e->getMessage());
    } finally {
        $cleanup();
    }
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL {$PASS} PASSED\033[0m" : "\033[31m{$FAIL} FAILED\033[0m ({$PASS} passed)") . "\n\n";
exit($FAIL === 0 ? 0 : 1);
