<?php
/**
 * =====================================================================
 *  u8-W5-reminder24-test.php — P8-4b (16 Nov 2026): the 24-hour trip
 *  reminder (TripNotify / Notify, switch reminder_24h_enabled, default 0).
 *
 *  Pure part:
 *    - reminderEvent(): 1321-1440 min = reminder_24h; the 12 h / 2 h bands
 *      and the dead bands are exactly what they were
 *    - Notify::TRIP_EVENTS / TripNotify::LABELS know the event
 *    - the body: Nepali first, pickup date + time, stop, seat, the signed
 *      ticket link, photo-ID line; a one-line SMS
 *
 *  Database part (shari_test; every row it makes is deleted):
 *    - switch off  -> a booking 23 h 20 min out gets NOTHING (the scan stays 12 h)
 *    - switch on, ENUM without the value (as on live before the migration)
 *      -> nothing claimed, no '' row, the cron output says which file to run
 *    - switch on, migration applied -> exactly one reminder_24h claim; a
 *      second cron run claims nothing more
 *    - with the 24 h reminder on, a trip 11 h out still gets its 12 h one
 *
 *    php -c .claude/php-dev.ini tests/u8-W5-reminder24-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/tripnotify.php';

$PASS = 0; $FAIL = 0;
function check(string $label, bool $ok, string $why = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $label" . ($why !== '' ? ' — ' . $why : '') . "\n"; }
}

echo "\n=== W5 · 24-hour trip reminder (P8-4b) ===\n\n";

/* ---------------- pure ---------------- */
check('1440 min before pickup is the 24 h reminder', TripNotify::reminderEvent(1440) === 'reminder_24h');
check('1321 min is still the 24 h band', TripNotify::reminderEvent(1321) === 'reminder_24h');
check('1320 min (22 h) is no reminder', TripNotify::reminderEvent(1320) === null);
check('1441 min is no reminder', TripNotify::reminderEvent(1441) === null);
check('721 min is no reminder (unchanged)', TripNotify::reminderEvent(721) === null);
check('700 min is the 12 h reminder (unchanged)', TripNotify::reminderEvent(700) === 'reminder_12h');
check('600 min is the dead band (unchanged)', TripNotify::reminderEvent(600) === null);
check('120 min is the 2 h reminder (unchanged)', TripNotify::reminderEvent(120) === 'reminder_2h');
check('0 min is no reminder (unchanged)', TripNotify::reminderEvent(0) === null);
check('Notify::TRIP_EVENTS knows reminder_24h', in_array('reminder_24h', Notify::TRIP_EVENTS, true));
check('TripNotify::LABELS names it', (TripNotify::LABELS['reminder_24h'] ?? '') === '24-hour reminder');

$body = new ReflectionMethod(Notify::class, 'tripEventBody');
$body->setAccessible(true);
$fx = ['route' => 'Surat → Rupaidiha', 'date' => 'Tue, 17 Nov 2026', 'depTime' => '3:30 PM', 'seats' => 'LA1, LA2',
       'boarding' => 'Nana Chiloda · 15:30', 'pickupToday' => '0'];
$b = $body->invoke(null, 'reminder_24h', 'S Hari Global', 'SHG-TEST-W5BODY', $fx);
$wa = (string) ($b['whatsapp'] ?? '');
check('WhatsApp body opens with the company and "भोलि यात्रा" (Nepali first)',
    str_starts_with($wa, "🚌 S Hari Global\nभोलि यात्रा · Trip tomorrow — SHG-TEST-W5BODY"), $wa);
check('  Nepali label before English on the pickup line', str_contains($wa, 'चढ्ने समय · Pickup: Tue, 17 Nov 2026 · 3:30 PM'));
check('  the stop, the seat and the route are there',
    str_contains($wa, 'चढ्ने ठाउँ · Boarding: Nana Chiloda · 15:30') && str_contains($wa, 'सिट · Seat: LA1, LA2') && str_contains($wa, 'रुट · Route: Surat → Rupaidiha'));
check('  the signed ticket link is there', (bool) preg_match('~टिकट · Ticket: \S*download-ticket\.php\?pnr=SHG-TEST-W5BODY&img=1&k=\S+~u', $wa), $wa);
check('  the photo-ID line (Nepali, then English)', str_contains($wa, 'फोटो भएको परिचयपत्र') && str_contains($wa, 'Carry a valid photo ID'));
$sms = (string) ($b['sms'] ?? '');
check('SMS is one line with PNR, pickup, stop and seat', $sms !== '' && !str_contains($sms, "\n") && str_contains($sms, 'SHG-TEST-W5BODY')
    && str_contains($sms, 'Pickup Tue, 17 Nov 2026 · 3:30 PM') && str_contains($sms, 'At Nana Chiloda') && str_contains($sms, 'Seat LA1, LA2'), $sms);
$today = $body->invoke(null, 'reminder_24h', 'S Hari Global', 'X1', ['pickupToday' => '1'] + $fx);
check('a pickup later today says "आज यात्रा"', str_contains((string) $today['whatsapp'], 'आज यात्रा · Trip today'));
$line = new ReflectionMethod(Notify::class, 'tripEventLine');
$line->setAccessible(true);
[$head] = $line->invoke(null, 'reminder_24h', $fx);
check('the one-line template head exists', str_contains((string) $head, '24 hours'));

/* ---------------- database ---------------- */
$tag        = 'W5R' . substr((string) time(), -6);
$PNR        = 'SHG-TEST-' . $tag;
$PNR12      = 'SHG-TEST-' . $tag . 'B';
$bookingIds = [];
$schedTouch = [];          // schedule id => original status
$setOrig    = Database::fetch("SELECT skey, svalue, stype, sgroup, label, is_public FROM settings WHERE skey = 'reminder_24h_enabled'");
$enumRef    = new ReflectionProperty(TripNotify::class, 'enumValues');
$enumRef->setAccessible(true);

/** A throwaway confirmed booking whose OWN pickup is $mins minutes from now (no route is edited). */
$makeBooking = static function (string $pnr, int $mins, string $seat) use (&$bookingIds, &$schedTouch): array {
    $route = Database::fetch("SELECT id, dep_time FROM routes WHERE is_active = 1 AND dep_time IS NOT NULL ORDER BY id LIMIT 1");
    if ($route === null) {
        throw new RuntimeException('no active route');
    }
    $target = time() + $mins * 60 + 30;
    $pick   = date('H:i:00', $target);
    foreach ([date('Y-m-d', $target), date('Y-m-d', $target - 86400)] as $d) {
        $sch = Seats::schedule((int) $route['id'], $d);
        $s   = Database::fetch("SELECT s.id, s.slot, s.status, COALESCE(s.dep_time_override, r.dep_time) AS dep, r.dep_time AS rdep
                                  FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :i", ['i' => (int) $sch['id']]);
        $pt  = Boarding::pickupTs($d, '', $pick, (string) $s['dep'], (string) $s['rdep'], (int) $s['slot'], (int) $route['id']);
        if (abs($pt - $target) <= 60) {
            if (!isset($schedTouch[(int) $s['id']])) {
                $schedTouch[(int) $s['id']] = (string) $s['status'];
            }
            Database::update('schedules', ['status' => 'scheduled'], 'id = :i', ['i' => (int) $s['id']]);
            $bid = (int) Database::insert('bookings', [
                'pnr' => $pnr, 'contact_phone' => '9800000099', 'contact_email' => '',
                'status' => 'confirmed', 'total_amount' => 1800.00, 'confirmed_at' => date('Y-m-d H:i:s'),
            ]);
            $bookingIds[] = $bid;
            $leg = (int) Database::insert('booking_legs', [
                'booking_id' => $bid, 'schedule_id' => (int) $s['id'], 'leg_type' => 'outbound', 'travel_date' => $d,
                'boarding_time' => $pick, 'seat_count' => 1, 'fare_per_seat' => 1800.00, 'leg_total' => 1800.00,
            ]);
            Database::insert('booking_passengers', [
                'booking_id' => $bid, 'leg_id' => $leg, 'seat_no' => $seat, 'full_name' => 'W5 Test Passenger',
                'passenger_ref' => generatePassengerRef(),
            ]);
            return ['booking' => $bid, 'schedule' => (int) $s['id']];
        }
    }
    throw new RuntimeException('could not place a pickup ' . $mins . ' min out');
};
$events = static fn(int $bid): array => array_map(static fn(array $r): string => (string) $r['event'],
    Database::fetchAll('SELECT `event` FROM trip_events WHERE booking_id = :b ORDER BY id', ['b' => $bid]));
$setSwitch = static function (string $v): void {
    Settings::set('reminder_24h_enabled', $v, 'bool', 'notify');
    Settings::flush();
};

try {
    // Defaults first: with no row stored (live before the migration) the event is off.
    Database::delete('settings', 'skey = :k', ['k' => 'reminder_24h_enabled']);
    Settings::flush();
    check('no settings row -> tripEventEnabled(reminder_24h) is OFF', Notify::tripEventEnabled('reminder_24h') === false);
    $setSwitch('0');
    check('stored 0 -> the event is off', Notify::tripEventEnabled('reminder_24h') === false);

    $A = $makeBooking($PNR, 1400, 'W51');

    /* switch off: the scan does not reach 23 h */
    $r = TripNotify::runReminders();
    check('switch OFF: a pickup 23 h 20 min out gets nothing', $events($A['booking']) === [], json_encode($r));

    /* switch on, the ENUM as live has it before the migration */
    $setSwitch('1');
    check('stored 1 -> the event is on', Notify::tripEventEnabled('reminder_24h') === true);
    $enumRef->setValue(null, ['reminder_12h', 'reminder_2h', 'departed', 'border', 'arrived']);
    $r = TripNotify::runReminders();
    check('ENUM without the value: nothing claimed for the booking', $events($A['booking']) === [], json_encode($r));
    check('  and no blank ENUM row anywhere for it', !Database::exists("SELECT 1 FROM trip_events WHERE booking_id = :b", ['b' => $A['booking']]));
    check('  the cron output names the migration', str_contains((string) ($r['note'] ?? ''), 'upgrade-2026-11-16b-W5-notify.sql'), json_encode($r));
    check('  eventStorable() says no', TripNotify::eventStorable('reminder_24h') === false);

    /* switch on, the real column */
    TripNotify::resetEventCache();
    $migrated = TripNotify::eventStorable('reminder_24h');
    check('the migration is applied on this database (trip_events.event has reminder_24h)', $migrated,
        'run database/upgrade-2026-11-16b-W5-notify.sql');
    if ($migrated) {
        $r1 = TripNotify::runReminders();
        check('switch ON: exactly one reminder_24h claim', $events($A['booking']) === ['reminder_24h'], json_encode($r1));
        check('  counted under byEvent.reminder_24h', (int) ($r1['byEvent']['reminder_24h'] ?? 0) >= 1, json_encode($r1));
        $row = Database::fetch("SELECT ok, detail FROM trip_events WHERE booking_id = :b AND `event` = 'reminder_24h'", ['b' => $A['booking']]);
        check('  the claim row carries the outcome', $row !== null && (string) ($row['detail'] ?? '') !== '');
        $r2 = TripNotify::runReminders();
        check('a second cron run claims nothing more', $events($A['booking']) === ['reminder_24h'] && (int) ($r2['byEvent']['reminder_24h'] ?? 0) === 0, json_encode($r2));

        $B = $makeBooking($PNR12, 660, 'W52');
        TripNotify::runReminders();
        check('with the 24 h reminder on, a trip 11 h out still gets its 12 h reminder', $events($B['booking']) === ['reminder_12h']);
    }

    /* the guard also holds inside deliver() for a manual milestone path */
    check('base events stay storable', TripNotify::eventStorable('departed') && TripNotify::eventStorable('reminder_12h'));
    check('an unknown event is never storable', TripNotify::eventStorable('no_such_event') === false);
} catch (Throwable $e) {
    check('test ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($bookingIds as $bid) {
        Database::delete('trip_events', 'booking_id = :b', ['b' => $bid]);
        try { Database::delete('message_logs', 'booking_id = :b', ['b' => $bid]); } catch (Throwable $ignored) {}
        Database::delete('bookings', 'id = :i', ['i' => $bid]);   // legs / passengers cascade
    }
    foreach ($schedTouch as $sid => $st) {
        Database::update('schedules', ['status' => $st !== '' ? $st : 'scheduled'], 'id = :i', ['i' => $sid]);
    }
    // The whole row back as it was (label included), or no row when there was none.
    Database::delete('settings', 'skey = :k', ['k' => 'reminder_24h_enabled']);
    if ($setOrig !== null) {
        Database::insert('settings', $setOrig);
    }
    Settings::flush();
    TripNotify::resetEventCache();
}

$left = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE pnr LIKE :p", ['p' => 'SHG-TEST-' . $tag . '%'], 0);
check('cleanup left no booking behind', $left === 0, (string) $left);

echo "\n  {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
