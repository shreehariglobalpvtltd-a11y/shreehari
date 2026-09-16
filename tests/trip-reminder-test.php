<?php
/**
 * Trip lifecycle messaging — integration test.
 *
 * Proves the exactly-once guarantee that the whole feature rests on:
 * a reminder is claimed once and never re-sent, the 12h and 2h reminders
 * are separate claims, a late booking skips straight to the 2h one, and
 * an operator can press "Bus started" twice without spamming the coach.
 *
 *   php -c .claude/php-dev.ini tests/trip-reminder-test.php
 *
 * Writes only throwaway rows (PNR prefixed SHG-TEST-) and restores every
 * setting and route time it touches. CLI only.
 *
 * Note on delivery: with no WhatsApp/SMS provider configured locally,
 * every send legitimately reports ok=false. That is the point being
 * tested here — the CLAIM must still happen exactly once either way.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/tripnotify.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}

$PNR      = 'SHG-TEST-TRIPNOTIFY';
$routeId  = 0; $origDep = ''; $origStatus = ''; $sid = 0; $bookingId = 0;

/** Point the route's departure at exactly now + N minutes. */
function departureIn(int $minutes): array {
    $ts = time() + $minutes * 60;
    return ['date' => date('Y-m-d', $ts), 'time' => date('H:i:s', $ts)];
}

function cleanup(string $pnr): void {
    foreach (pluck(Database::fetchAll("SELECT id FROM bookings WHERE pnr = :p", ['p' => $pnr]), 'id') as $id) {
        Database::delete('trip_events', 'booking_id = :b', ['b' => (int) $id]);
        Database::delete('bookings', 'id = :i', ['i' => (int) $id]);   // legs/passengers cascade
    }
}

/** Create the throwaway confirmed booking on $sid for $date. */
function makeBooking(string $pnr, int $sid, string $date, string $seat): int {
    $bid = Database::insert('bookings', [
        'pnr' => $pnr, 'contact_phone' => '9800000099', 'contact_email' => '',
        'status' => 'confirmed', 'total_amount' => 1800.00, 'confirmed_at' => date('Y-m-d H:i:s'),
    ]);
    $legId = Database::insert('booking_legs', [
        'booking_id' => $bid, 'schedule_id' => $sid, 'leg_type' => 'outbound',
        'travel_date' => $date, 'seat_count' => 1, 'fare_per_seat' => 1800.00, 'leg_total' => 1800.00,
    ]);
    Database::insert('booking_passengers', [
        'booking_id' => $bid, 'leg_id' => $legId, 'seat_no' => $seat, 'full_name' => 'Test Passenger',
        'passenger_ref' => generatePassengerRef(),
    ]);
    return $bid;
}

function eventsFor(int $bid): array {
    return pluck(Database::fetchAll(
        "SELECT `event` FROM trip_events WHERE booking_id = :b ORDER BY id", ['b' => $bid]
    ), 'event');
}

echo "\n=== Trip lifecycle messaging ===\n\n";

try {
    if (!Database::exists("SELECT 1 FROM information_schema.tables
                            WHERE table_schema = DATABASE() AND table_name = 'trip_events'")) {
        echo "  trip_events missing — run database/upgrade-2026-08-trip-lifecycle.sql first\n";
        exit(1);
    }

    $route = Database::fetch("SELECT id, dep_time, route_code FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no active route\n"; exit(1); }
    $routeId = (int) $route['id'];
    $origDep = (string) $route['dep_time'];

    cleanup($PNR);

    /* ---- 1. A trip ~11 hours out earns the 12-hour reminder --------- */
    /* Must be > 10h out: a 2-10h booking is in the dead band and gets
       NOTHING until it enters the 2h window (section 1b), so it is never
       told "your journey is tomorrow" hours before it actually is. */
    $d = departureIn(660);
    Database::update('routes', ['dep_time' => $d['time']], 'id = :i', ['i' => $routeId]);
    $sch = Seats::schedule($routeId, $d['date']);
    $sid = (int) $sch['id'];
    $origStatus = (string) $sch['status'];
    Database::update('schedules', ['status' => 'scheduled'], 'id = :i', ['i' => $sid]);

    $bookingId = makeBooking($PNR, $sid, $d['date'], 'T1');

    $r1 = TripNotify::runReminders();
    check('12h reminder claimed for a trip ~11h away', eventsFor($bookingId) === ['reminder_12h']);
    check('  and it was counted as attempted (sent+failed = 1)', ($r1['sent'] + $r1['failed']) >= 1);

    /* ---- 1b. A trip in the 2-10h dead band gets NOTHING yet --------- */
    $PNR_DEAD = 'SHG-TEST-TRIPDEAD';
    cleanup($PNR_DEAD);
    $dd  = departureIn(360);   // ~6h out
    Database::update('routes', ['dep_time' => $dd['time']], 'id = :i', ['i' => $routeId]);
    $dsch = Seats::schedule($routeId, $dd['date']);
    $dsid = (int) $dsch['id'];
    Database::update('schedules', ['status' => 'scheduled'], 'id = :i', ['i' => $dsid]);
    $deadId = makeBooking($PNR_DEAD, $dsid, $dd['date'], 'D1');
    TripNotify::runReminders();
    check('a 6h-out booking gets NO "tomorrow" reminder (dead band)', eventsFor($deadId) === []);
    cleanup($PNR_DEAD);
    // restore the route dep_time to the section-1 trip for the re-send check
    Database::update('routes', ['dep_time' => $d['time']], 'id = :i', ['i' => $routeId]);

    /* ---- 2. Running the cron again must not re-send ----------------- */
    $r2 = TripNotify::runReminders();
    check('second cron run sends nothing new', ($r2['sent'] + $r2['failed']) === 0);
    check('  the booking still has exactly one claim', eventsFor($bookingId) === ['reminder_12h']);

    /* ---- 3. Inside 2 hours, the 2h reminder is a separate claim ----- */
    $d2 = departureIn(75);
    Database::update('routes', ['dep_time' => $d2['time']], 'id = :i', ['i' => $routeId]);
    Database::update('booking_legs', ['travel_date' => $d2['date']], 'booking_id = :b', ['b' => $bookingId]);

    TripNotify::runReminders();
    check('2h reminder claimed separately', eventsFor($bookingId) === ['reminder_12h', 'reminder_2h']);

    TripNotify::runReminders();
    check('  and it too is never re-sent', eventsFor($bookingId) === ['reminder_12h', 'reminder_2h']);

    /* ---- 4. A booking made 1h before departure skips the 12h one ---- */
    cleanup($PNR);
    $bookingId = makeBooking($PNR, $sid, $d2['date'], 'T2');
    TripNotify::runReminders();
    check('late booking gets ONLY the 2h reminder', eventsFor($bookingId) === ['reminder_2h']);

    /* ---- 5. "Bus started" notifies once, however often it is pressed  */
    $m1 = TripNotify::markTrip($sid, 'departed', 0);
    check('marking departed reaches the passenger', in_array('departed', eventsFor($bookingId), true));
    check('  schedules.status moved to departed',
        (string) Database::scalar("SELECT status FROM schedules WHERE id = :i", ['i' => $sid]) === 'departed');

    $m2 = TripNotify::markTrip($sid, 'departed', 0);
    check('pressing it again notifies nobody twice', $m2['notified'] === 0 && $m2['skipped'] >= 1);
    check('  and leaves exactly one departed claim',
        count(array_filter(eventsFor($bookingId), static fn($e) => $e === 'departed')) === 1);

    /* ---- 6. Border then arrived are their own milestones ------------ */
    TripNotify::markTrip($sid, 'border', 0);
    TripNotify::markTrip($sid, 'arrived', 0);
    $ev = eventsFor($bookingId);
    check('border + arrived recorded as distinct events',
        in_array('border', $ev, true) && in_array('arrived', $ev, true));
    check('  arrived moves the trip to arrived',
        (string) Database::scalar("SELECT status FROM schedules WHERE id = :i", ['i' => $sid]) === 'arrived');
    check('  statusMap reports all three milestones',
        count(TripNotify::statusMap([$sid])[$sid] ?? []) === 3);

    /* ---- 7. The master switch really stops everything --------------- */
    cleanup($PNR);
    $bookingId = makeBooking($PNR, $sid, $d2['date'], 'T3');
    Database::update('schedules', ['status' => 'scheduled'], 'id = :i', ['i' => $sid]);
    Settings::set('trip_reminders_enabled', '0', 'bool', 'notify');
    Settings::flush();
    $off = TripNotify::runReminders();
    check('master switch off → nothing is claimed', eventsFor($bookingId) === [] && $off['sent'] === 0);

    Settings::set('trip_reminders_enabled', '1', 'bool', 'notify');
    Settings::flush();
    TripNotify::runReminders();
    check('switching it back on resumes sending', eventsFor($bookingId) === ['reminder_2h']);

} catch (Throwable $e) {
    check('unexpected exception: ' . $e->getMessage(), false);
} finally {
    cleanup($PNR);
    if ($routeId > 0 && $origDep !== '') {
        Database::update('routes', ['dep_time' => $origDep], 'id = :i', ['i' => $routeId]);
    }
    if ($sid > 0) {
        Database::delete('trip_status', 'schedule_id = :s', ['s' => $sid]);
        Database::update('schedules', ['status' => $origStatus !== '' ? $origStatus : 'scheduled'], 'id = :i', ['i' => $sid]);
    }
    Settings::set('trip_reminders_enabled', '1', 'bool', 'notify');
    Settings::flush();
}

echo "\n  {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
