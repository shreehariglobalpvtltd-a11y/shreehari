<?php
/**
 * Bus Calendar — extra buses per date (schedules.slot), 5 Sep 2026.
 *
 * Locks in:
 *   • the daily bus is slot 1 and Seats::schedule() never returns anything else;
 *   • ScheduleMaker::insertOne(['slot' => N]) + nextFreeSlot() add a 2nd
 *     departure on the SAME route + date with its own seats;
 *   • scheduleFor() refuses a schedule id that belongs to another route/date;
 *   • BookingService::create() with scheduleId books the EXTRA bus, and the
 *     daily bus's seat map does not show that seat (separate occupancy);
 *   • per-date OFF (is_blocked) refuses a checkout on the daily bus while the
 *     extra bus still sells; the extra bus with its own time closes at ITS
 *     departure; slot 1 cannot be inserted twice.
 *
 *   php -c .claude/php-dev.ini tests/bus-calendar-slot-test.php
 *
 * Writes only throwaway rows (one extra schedule + test bookings) and
 * cleans up after itself. CLI only.
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
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/schedulemaker.php';
require_once INCLUDE_PATH . '/fleet.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $note = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($note !== '' ? " — $note" : '') . "\n"; }
}
function throws(string $l, callable $fn, string $needle = ''): void {
    try { $fn(); check($l, false, 'no exception'); }
    catch (Throwable $e) { check($l, $needle === '' || str_contains($e->getMessage(), $needle), $e->getMessage()); }
}

const PHONE = '9700000777';
$D = date('Y-m-d', strtotime('+10 days'));

function bookReq(int $rid, string $date, string $seat, int $sid = 0): array {
    return [
        'routeId' => $rid, 'travelDate' => $date, 'scheduleId' => $sid, 'seats' => [$seat],
        'passengers' => [['name' => 'Slot Pax', 'age' => 30, 'gender' => 'Male']],
        'contact' => ['phone' => PHONE], 'bookingMode' => 'sharing', 'paymentMethod' => 'upi',
        'isCod' => false, 'boarding' => '', 'referralCode' => '',
    ];
}
function cleanup(int $rid, string $date): void {
    foreach (Database::fetchAll('SELECT id FROM bookings WHERE contact_phone = :p', ['p' => PHONE]) as $b) {
        try { Seats::releaseBooking((int) $b['id']); } catch (Throwable $e) {}
        Database::run('DELETE FROM bookings WHERE id = :i', ['i' => (int) $b['id']]);
    }
    Database::run('DELETE FROM seat_locks WHERE schedule_id IN (SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d)', ['r' => $rid, 'd' => $date]);
    Database::run('DELETE FROM schedules WHERE route_id = :r AND travel_date = :d AND slot > 1', ['r' => $rid, 'd' => $date]);
    Database::run('UPDATE schedules SET is_blocked = 0 WHERE route_id = :r AND travel_date = :d', ['r' => $rid, 'd' => $date]);
}

echo "\n=== Bus Calendar: extra buses per date (slot) ===\n\n";
$route = Database::fetch("SELECT id, route_code, dep_time FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
if ($route === null) { echo "no sleeper route\n"; exit(1); }
$rid = (int) $route['id'];
$other = Database::fetch("SELECT id FROM routes WHERE id <> :r AND is_active = 1 ORDER BY id LIMIT 1", ['r' => $rid]);
$serviceWas = Settings::getBool('daily_service_on', true);
Settings::set('daily_service_on', '1', 'bool', 'booking', true);
cleanup($rid, $D);

try {
    // -- 1. daily bus = slot 1
    $daily = Seats::schedule($rid, $D);
    check('daily bus row has slot 1', (int) ($daily['slot'] ?? 0) === 1);
    check('nextFreeSlot is 2 when only the daily bus exists', ScheduleMaker::nextFreeSlot($rid, $D) === 2);

    // -- 2. add an extra bus
    $res = ScheduleMaker::insertOne($rid, $D, ['slot' => ScheduleMaker::nextFreeSlot($rid, $D), 'dep_time_override' => '21:00']);
    $xid = (int) $res['id'];
    $x   = Database::fetch('SELECT * FROM schedules WHERE id = :i', ['i' => $xid]);
    check('extra bus inserted with slot 2', $x !== null && (int) $x['slot'] === 2);
    check('extra bus keeps its own departure (21:00)', substr((string) ($x['dep_time_override'] ?? ''), 0, 5) === '21:00');
    check('nextFreeSlot now 3', ScheduleMaker::nextFreeSlot($rid, $D) === 3);
    check('Seats::schedule() still returns the DAILY bus (slot 1 pin)', (int) Seats::schedule($rid, $D)['id'] === (int) $daily['id']);
    throws('slot 1 cannot be inserted twice (points at Extra bus)', fn() => ScheduleMaker::insertOne($rid, $D, []), 'Extra bus');

    // -- 3. scheduleFor validation
    check('scheduleFor(rid, D, xid) resolves the extra bus', (int) Seats::scheduleFor($rid, $D, $xid)['id'] === $xid);
    check('scheduleFor(rid, D, null) resolves the daily bus', (int) Seats::scheduleFor($rid, $D, null)['id'] === (int) $daily['id']);
    if ($other !== null) {
        throws('scheduleFor refuses an id from another route', fn() => Seats::scheduleFor((int) $other['id'], $D, $xid), 'does not match');
    }
    throws('scheduleFor refuses a wrong date', fn() => Seats::scheduleFor($rid, date('Y-m-d', strtotime('+11 days')), $xid), 'does not match');

    // -- 4. book on the extra bus; occupancy stays separate
    $b = BookingService::create(bookReq($rid, $D, 'L10', $xid));
    $bid = (int) $b['id'];
    $legSid = (int) Database::scalar('SELECT schedule_id FROM booking_legs WHERE booking_id = :b LIMIT 1', ['b' => $bid], 0);
    check('booking leg points at the EXTRA bus', $legSid === $xid);
    $avDaily = Seats::availability($rid, $D, 'sharing');
    $avExtra = Seats::availability($rid, $D, 'sharing', $xid);
    check('daily bus map does NOT show L10 booked', !in_array('L10', $avDaily['booked'], true));
    check('extra bus map shows L10 booked', in_array('L10', $avExtra['booked'], true));
    check('availabilityForSchedule(xid) agrees', in_array('L10', Seats::availabilityForSchedule($xid, 'sharing')['booked'], true));
    check('detail() reports the extra bus time (21:00)', substr((string) (BookingService::detail((string) $b['pnr'])['legs'][0]['dep_time'] ?? ''), 0, 5) === '21:00');

    // -- 5. wrong scheduleId is refused
    throws('create() refuses a scheduleId of another date', fn() => BookingService::create(bookReq($rid, date('Y-m-d', strtotime('+11 days')), 'L11', $xid)), 'no longer available');

    // -- 6. per-date OFF blocks the daily bus, extra bus still sells
    Database::run('UPDATE schedules SET is_blocked = 1 WHERE id = :i', ['i' => (int) $daily['id']]);
    throws('OFF (is_blocked) daily bus refuses checkout', fn() => BookingService::create(bookReq($rid, $D, 'L12', 0)), 'not running');
    $b2 = BookingService::create(bookReq($rid, $D, 'L12', $xid));
    check('extra bus still sells while the daily bus is OFF', (int) Database::scalar('SELECT schedule_id FROM booking_legs WHERE booking_id = :b', ['b' => (int) $b2['id']], 0) === $xid);
    Database::run('UPDATE schedules SET is_blocked = 0 WHERE id = :i', ['i' => (int) $daily['id']]);

    // -- 7. an extra bus with its own time that has departed refuses checkout
    Database::run("UPDATE schedules SET dep_time_override = '00:01:00' WHERE id = :i", ['i' => $xid]);
    Database::run('UPDATE schedules SET travel_date = :d WHERE id = :i', ['d' => date('Y-m-d'), 'i' => $xid]);   // today 00:01 = gone
    throws('extra bus past its own departure refuses checkout', fn() => BookingService::create(bookReq($rid, date('Y-m-d'), 'L13', $xid)), 'departed');
    Database::run('UPDATE schedules SET travel_date = :d, dep_time_override = :t WHERE id = :i', ['d' => $D, 't' => '21:00:00', 'i' => $xid]);
} catch (Throwable $e) {
    check('no exception: ' . $e->getMessage(), false);
} finally {
    cleanup($rid, $D);
    Settings::set('daily_service_on', $serviceWas ? '1' : '0', 'bool', 'booking', true);
}

echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
