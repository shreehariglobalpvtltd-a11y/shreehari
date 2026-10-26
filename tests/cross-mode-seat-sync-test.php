<?php
/**
 * cross-mode-seat-sync-test.php — proves Private ⇄ Sharing seat sync
 * (double-booking safety, Task 4, owner ask 3 Sep 2026).
 *
 * A sleeper coach is ONE physical bus sold two ways. A berth sold in one mode
 * must block the physical bed(s) it occupies in the other, or the bus is
 * oversold. The canonical namespace is SHARING (72 beds), and:
 *     private Lj  ->  physical { L(2j-1), L(2j) }
 *     inverse: physical Ln -> private L(ceil(n/2))
 *
 * Part 1 is pure (mapping helpers). Part 2 creates ONE real private booking on a
 * far-future date, proves the beds it takes are blocked in the sharing view and
 * that the booking gate refuses the overlap, then cleans up after itself.
 *
 *   php -c .claude/php-dev.ini tests/cross-mode-seat-sync-test.php
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

const XM_PHONE = '910000771';   // throwaway-booking marker for cleanup

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } }

echo "\n=== Cross-mode seat sync (Private L3 ⇄ Sharing L5/L6) ===\n\n";

// ---------- Part 1: pure physical-namespace mapping ----------
check("physicalSeats(L3, private) === [L5,L6]", Seats::physicalSeats('L3', 'private') === ['L5', 'L6']);
check("physicalSeats(L1, private) === [L1,L2]", Seats::physicalSeats('L1', 'private') === ['L1', 'L2']);
check("physicalSeats(U9, private) === [U17,U18]", Seats::physicalSeats('U9', 'private') === ['U17', 'U18']);
check("physicalSeats(L5, sharing) === [L5] (identity)", Seats::physicalSeats('L5', 'sharing') === ['L5']);
check("toPhysical([L1,L2], private) === [L1,L2,L3,L4] (double cabin = 4 beds)",
    Seats::toPhysical(['L1', 'L2'], 'private') === ['L1', 'L2', 'L3', 'L4']);
check("physicalToMode(L5, private) === L3", Seats::physicalToMode('L5', 'private') === 'L3');
check("physicalToMode(L6, private) === L3", Seats::physicalToMode('L6', 'private') === 'L3');
check("physicalToMode(L5, sharing) === L5 (identity)", Seats::physicalToMode('L5', 'sharing') === 'L5');
check("physicalSetToMode([L5,L6], private) === [L3]", Seats::physicalSetToMode(['L5', 'L6'], 'private') === ['L3']);

// ---------- Part 2: a real private booking blocks the sharing beds ----------
$w = bookingWindow(); $D = addDaysISO($w['from'], 24);

$cleanup = function () use ($D) {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . XM_PHONE . "%'") as $r) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => $D]);
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
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
        // Book PRIVATE single cabin L6 (physically beds L11 + L12). L6 is used
        // rather than L3 because L3 is the reserved Emergency berth (Task 2).
        BookingService::create([
            'routeId'     => $routeId,
            'travelDate'  => $D,
            'seats'       => ['L6'],
            'passengers'  => [['name' => 'XMode Private', 'age' => 30, 'gender' => 'Male']],
            'contact'     => ['phone' => XM_PHONE . '0'],
            'bookingMode' => 'private',
            'cabinType'   => 'single',
            'sharingTier' => 'single',
            'paymentMethod' => 'upi',
            'isCod'       => false,
            'boarding'    => '',
        ]);

        $schedule = Seats::schedule($routeId, $D);
        $sid      = (int) $schedule['id'];

        $phys = Seats::bookedPhysical($sid);
        check("bookedPhysical() contains L11 & L12 after private-L6 sale",
            in_array('L11', $phys, true) && in_array('L12', $phys, true), implode(',', $phys));

        $shar = Seats::availability($routeId, $D, 'sharing');
        check("sharing view: L11 shows BOOKED", in_array('L11', $shar['booked'], true));
        check("sharing view: L12 shows BOOKED", in_array('L12', $shar['booked'], true));
        check("sharing view: L11 NOT available", !in_array('L11', $shar['available'], true));
        check("sharing view: L13 (a different bed) still available", in_array('L13', $shar['available'], true));

        $priv = Seats::availability($routeId, $D, 'private');
        check("private view: L6 shows BOOKED (its own sale)", in_array('L6', $priv['booked'], true));

        /* ---- the STAFF map must show the same coach as the customer app ----
           adminSeatMap() draws the SHARING namespace but booking_seats stores
           each sale in its own mode's labels. Before the booking_mode join it
           keyed straight off seat_no, so this private "L6" cabin marked sharing
           berth L6 (a bed nobody bought — it belongs to private cabin L3) and
           left beds L11/L12 open even though a passenger is lying on them. */
        $adminMap = Seats::adminSeatMap($sid, 'sleeper');
        check("admin map: bed L11 BOOKED (the private cabin's real bed)",
            ($adminMap['L11']['status'] ?? '') === 'booked', $adminMap['L11']['status'] ?? 'missing');
        check("admin map: bed L12 BOOKED (the private cabin's other bed)",
            ($adminMap['L12']['status'] ?? '') === 'booked', $adminMap['L12']['status'] ?? 'missing');
        // Sharing berth L6 must not be drawn as SOLD: the private "L6" cabin is
        // beds L11+L12, and bed 6 belongs to private cabin L3, which nobody
        // bought. (It reports 'staff' rather than 'open' because L6 is one of
        // the reserved crew berths on a sleeper — the point is only that the
        // label collision no longer invents a booking here.)
        check("admin map: berth L6 NOT booked (label collision, not a real sale)",
            ($adminMap['L6']['status'] ?? '') !== 'booked', $adminMap['L6']['status'] ?? 'missing');
        check("admin map agrees with the customer sharing view on every berth",
            array_values(array_filter(array_keys($adminMap), fn($s) => ($adminMap[$s]['status'] ?? '') === 'booked'))
            === array_values(array_intersect(array_keys($adminMap), $shar['booked'])));
        check("admin map: the passenger rides on the physical bed, not the label",
            ($adminMap['L11']['passenger'] ?? '') === 'XMode Private', $adminMap['L11']['passenger'] ?? 'null');

        $tok = 'xmode-tok';
        check("gate REFUSES sharing L11 (physical clash with private L6)",
            throws(fn() => Seats::assertAvailable($sid, ['L11'], $tok, false, 'sharing')));
        check("gate REFUSES sharing L12 (physical clash with private L6)",
            throws(fn() => Seats::assertAvailable($sid, ['L12'], $tok, false, 'sharing')));
        check("gate ALLOWS sharing L13 (no physical clash)",
            !throws(fn() => Seats::assertAvailable($sid, ['L13'], $tok, false, 'sharing')));
        check("gate REFUSES private L6 again (already sold)",
            throws(fn() => Seats::assertAvailable($sid, ['L6'], $tok, false, 'private')));

        // ---- Task 7: ONE sharing berth makes the whole private cabin unavailable ----
        // Sharing books L7 only (physical bed 7). Private cabin L4 spans physical
        // beds L7 + L8; even though L8 is still a sellable SHARING berth, the
        // private cabin L4 must read as unavailable (it needs the full cabin).
        BookingService::create([
            'routeId'     => $routeId,
            'travelDate'  => $D,
            'seats'       => ['L7'],
            'passengers'  => [['name' => 'XMode Sharing', 'age' => 28, 'gender' => 'Female']],
            'contact'     => ['phone' => XM_PHONE . '1'],
            'bookingMode' => 'sharing',
            'paymentMethod' => 'upi',
            'isCod'       => false,
            'boarding'    => '',
        ]);
        $priv2 = Seats::availability($routeId, $D, 'private');
        $shar2 = Seats::availability($routeId, $D, 'sharing');
        check("Task7: private cabin L4 UNAVAILABLE after 1 sharing berth (L7) taken",
            !in_array('L4', $priv2['available'], true) && in_array('L4', $priv2['booked'], true));
        check("Task7: sharing berth L8 (the cabin's other bed) STILL available",
            in_array('L8', $shar2['available'], true));
        check("Task7: gate REFUSES private L4 (cabin not fully free)",
            throws(fn() => Seats::assertAvailable($sid, ['L4'], $tok, false, 'private')));
        check("Task7: private cabin L5 (both beds free) still available",
            in_array('L5', $priv2['available'], true));
    } catch (Throwable $e) {
        check('private booking + assertions ran without a fatal', false, $e->getMessage());
    } finally {
        $cleanup();
    }
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL {$PASS} PASSED\033[0m" : "\033[31m{$FAIL} FAILED\033[0m ({$PASS} passed)") . "\n\n";
exit($FAIL === 0 ? 0 : 1);
