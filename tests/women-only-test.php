<?php
/**
 * =====================================================================
 *  women-only-test.php — 4 Sep 2026: the operator's female_seats list is
 *  ENFORCED at sale time, not just painted.
 *
 *   - a customer booking a Male passenger on a women-only seat in SHARING
 *     mode is refused;
 *   - Female / blank gender on the same seat goes through;
 *   - a Male on a non-listed seat goes through;
 *   - PRIVATE cabins are whole cabins — the list does not apply;
 *   - a COUNTER sale (staff override) is allowed on the listed seat.
 *
 *  Run: php -c .claude/php-dev.ini tests/women-only-test.php
 *  Creates throw-away bookings on a far-future date and removes them.
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
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

const WO_PHONE = '910000772';   // throwaway-booking marker for cleanup

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function attempt(callable $fn): ?string { try { $fn(); return null; } catch (Throwable $e) { return $e->getMessage(); } }

echo "\n=== Women-only seats enforced at sale time ===\n\n";

$w = bookingWindow(); $D = addDaysISO($w['from'], 26);
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
if ($route === null) { echo "  \033[33mSKIP\033[0m  no active sleeper route\n"; exit(0); }
$routeId = (int) $route['id'];
$fem = Seats::femaleSeats('sleeper');
check('female_seats list is non-empty for sleeper', $fem !== [], implode(',', $fem));
$femSeat = $fem[0] ?? 'L1';

$cleanup = function () use ($D) {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . WO_PHONE . "%'") as $r) {
        foreach (['booking_passengers', 'booking_legs', 'booking_seats', 'payments', 'tickets', 'agent_ledger'] as $t) {
            try { Database::query("DELETE FROM {$t} WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        }
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
        Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
};
$cleanup();

$req = function (array $seats, string $gender, string $mode, string $suffix) use ($routeId, $D): array {
    return [
        'routeId' => $routeId, 'travelDate' => $D, 'seats' => $seats,
        'passengers' => array_map(fn($s) => ['seat' => $s, 'name' => 'WO Test', 'age' => 30, 'gender' => $gender], $seats),
        'contact' => ['phone' => WO_PHONE . $suffix], 'bookingMode' => $mode,
        'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '',
    ];
};

try {
    $err = attempt(fn() => BookingService::create($req([$femSeat], 'Male', 'sharing', '1')));
    check("customer: Male on women-only $femSeat (sharing) is REFUSED", $err !== null && stripos($err, 'women') !== false, (string) $err);

    $err = attempt(fn() => BookingService::create($req([$femSeat], 'Female', 'sharing', '2')));
    check("customer: Female on $femSeat (sharing) goes through", $err === null, (string) $err);
    $cleanup();

    $err = attempt(fn() => BookingService::create($req([$femSeat], '', 'sharing', '3')));
    check("customer: blank gender on $femSeat is not blocked", $err === null, (string) $err);
    $cleanup();

    $femPhys = Seats::toPhysical($fem, 'sharing');
    $other = null;
    foreach (Seats::seatIds('sleeper', 'sharing') as $cand) {
        if (!in_array($cand, $femPhys, true) && !in_array($cand, Seats::staffSeatsAll(), true) && str_starts_with($cand, 'U')) { $other = $cand; break; }
    }
    $err = attempt(fn() => BookingService::create($req([$other], 'Male', 'sharing', '4')));
    check("customer: Male on a normal seat ($other) goes through", $err === null, (string) $err);
    $cleanup();

    // Private: cabin L4 = beds L7/L8 (never the reserved pair). The female
    // list does not apply to whole-cabin bookings.
    $err = attempt(fn() => BookingService::create($req(['L4'], 'Male', 'private', '5')));
    check('private cabin: Male in cabin L4 goes through (list not applied)', $err === null, (string) $err);
    $cleanup();

    // Counter override: a superadmin selling at the counter may seat a man there.
    $admin = Database::fetch("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1");
    if ($admin === null) {
        echo "  \033[33mSKIP\033[0m  no active superadmin for the counter-override case\n";
    } else {
        $seller = ['adminId' => (int) $admin['id'], 'source' => 'counter', 'paymentMethod' => 'cash', 'discountType' => '', 'discountValue' => 0, 'note' => 'women-only test'];
        $err = attempt(fn() => BookingService::create($req([$femSeat], 'Male', 'sharing', '6'), $seller));
        check("counter (staff override): Male on $femSeat is ALLOWED", $err === null, (string) $err);
        $cleanup();
    }
} catch (Throwable $e) {
    check('suite ran without a fatal', false, $e->getMessage());
} finally {
    $cleanup();
}

echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
