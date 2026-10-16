<?php
/**
 * ci-fixtures.php — make a FRESH test database (schema.sql + seed.sql +
 * every upgrade) look enough like the live register for the battery.
 *
 * On the VPS the battery runs against shari_test, a copy of the live
 * database, which carries things seed.sql never had: an owner account with
 * id 1, the real pickup towns (Kamrej, Ankleshwar, Bharuch, Anand, Nadiad)
 * on the daily route, and a booking horizon long enough for the suites that
 * book in 2099. A database built from the repository alone lacks all of
 * that, so six suites failed for data reasons on every machine but the VPS.
 * This script adds exactly those rows. Idempotent; refuses anything that is
 * not unmistakably a test database (same rule as run-all.php).
 *
 *     php tests/ci-fixtures.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (stripos((string) DB_NAME, 'test') === false || strtolower((string) APP_ENV) === 'production') {
    fwrite(STDERR, "REFUSING: '" . DB_NAME . "' / APP_ENV=" . APP_ENV . " does not look like a test database.\n");
    exit(2);
}

$log = static function (string $m): void { echo "  $m\n"; };

/* --owner: only step 1. ci-setup.sh runs it right after schema + seed, BEFORE
   the upgrades, because upgrade-2026-08-agent-role.sql inserts the demo
   agent1 and on a fresh table that would make an AGENT the id-1 account
   (on live id 1 is the owner; chalani-png-test relies on that). */
$ownerOnly = in_array('--owner', $argv, true);

/* 1. The owner's account — id 1 whenever the table is empty, as on live. */
$super = Database::fetch("SELECT id FROM admins WHERE username = 'superadmin'");
if ($super === null) {
    $id = Database::insert('admins', [
        'username'       => 'superadmin',
        'password_hash'  => password_hash(getenv('SHG_ADMIN_PW') ?: 'Admin@12345', PASSWORD_BCRYPT),
        'full_name'      => 'S Hari Global',
        'email'          => 'owner@test.local',
        'phone'          => '+919000000001',
        'role'           => 'superadmin',
        'permissions'    => '[]',
        'is_active'      => 1,
        'must_change_pw' => 0,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
    $log("superadmin created (id $id)");
} else {
    Database::update('admins', ['role' => 'superadmin', 'is_active' => 1, 'must_change_pw' => 0], 'id = :id', ['id' => (int) $super['id']]);
    $log('superadmin present (id ' . (int) $super['id'] . ')');
}

if ($ownerOnly) { echo "owner ready\n"; exit(0); }

/* 2. Settings the suites lean on. Settings::setMany() only touches rows
      that exist, so missing rows are inserted the plain way. */
$want = [
    // paid-pending-hold, manifest and launch-fixes book in 2099.
    ['booking_horizon_days', '30000', 'int', 'booking', 'Booking horizon (days)', 1],
];
foreach ($want as [$k, $v, $t, $g, $label, $pub]) {
    $row = Database::fetch('SELECT skey FROM settings WHERE skey = :k', ['k' => $k]);
    if ($row === null) {
        Database::insert('settings', ['skey' => $k, 'svalue' => $v, 'stype' => $t, 'sgroup' => $g, 'label' => $label, 'is_public' => $pub]);
        $log("setting $k inserted = $v");
    } else {
        Database::update('settings', ['svalue' => $v], 'skey = :k', ['k' => $k]);
        $log("setting $k = $v");
    }
}

/* 3. The owner holds no agent code (chalani-png-test: a sale by admin 1 with
      role counter/admin reads COUNTER/OFFICE, never AGENT). */
$codes = Settings::getArray('agent_codes', []);
if (isset($codes['1'])) {
    unset($codes['1']);
    Settings::set('agent_codes', $codes, 'json', 'agents', false);
    $log('agent code removed from admin 1');
}

/* 4. The daily route's real pickups. bot-parse-test reads Hindi "नडियाद" as
      the Nadiad pickup — which only exists when Nadiad is a boarding stop of
      an ACTIVE route. Mirrors the towns the live register carries. */
$stops = [
    ['Kamrej',     '13:30:00', 21.2729662, 72.9555969],
    ['Ankleshwar', '15:00:00', null, null],
    ['Bharuch',    '16:00:00', null, null],
    ['Anand',      '18:30:00', null, null],
    ['Nadiad',     '20:00:00', null, null],
];
$routes = Database::fetchAll("SELECT id, route_code FROM routes WHERE is_active = 1 ORDER BY id");
foreach ($routes as $r) {
    $rid = (int) $r['id'];
    $hasBoarding = (int) Database::scalar("SELECT COUNT(*) FROM route_stops WHERE route_id = :r AND stop_type = 'boarding'", ['r' => $rid], 0);
    $hasDrop     = (int) Database::scalar("SELECT COUNT(*) FROM route_stops WHERE route_id = :r AND stop_type = 'drop'", ['r' => $rid], 0);
    // Outbound (India → Nepal) routes have many boardings; return routes many drops.
    $type = $hasBoarding >= $hasDrop ? 'boarding' : 'drop';
    $max  = (int) Database::scalar("SELECT COALESCE(MAX(sort_order),0) FROM route_stops WHERE route_id = :r AND stop_type = :t", ['r' => $rid, 't' => $type], 0);
    foreach ($stops as [$name, $time, $lat, $lng]) {
        $exists = Database::fetch("SELECT id FROM route_stops WHERE route_id = :r AND stop_name = :n", ['r' => $rid, 'n' => $name]);
        if ($exists !== null) { continue; }
        $max++;
        Database::insert('route_stops', [
            'route_id'     => $rid,
            'stop_type'    => $type,
            'stop_name'    => $name,
            'landmark'     => '',
            'stop_time'    => $type === 'boarding' ? $time : null,
            'latitude'     => $lat,
            'longitude'    => $lng,
            'is_border'    => 0,
            'is_meal_halt' => 0,
            'sort_order'   => $max,
        ]);
        $log("route {$r['route_code']}: $type stop $name added");
    }
}

/* 5. The Rajkot office pickup, beside Ahmedabad.
      database/upgrade-2026-10-15-rajkot-reschedule.sql reorders the stops by
      NAME, and the names it reorders ('Emli Bhupal', and a Nadiad on r2) are
      the live register's, not seed.sql's - so on a repo-built database the
      reorder is a no-op and r7 has no Rajkot row at all. The owner's rule is
      what matters, not the ids: Rajkot is an OFFICE pickup with no timetable
      clock and no map pin, and it sits where its passengers join the coach -
      right BEFORE the Ahmedabad stop going to Nepal, right AFTER it coming
      back. Placed here by position, whatever the neighbouring towns are
      called in this install. */
foreach ([['r2', 'boarding', 'before'], ['r7', 'drop', 'after']] as [$code, $type, $side]) {
    $route = Database::fetch("SELECT id FROM routes WHERE route_code = :c", ['c' => $code]);
    if ($route === null) { continue; }
    $rid = (int) $route['id'];
    /* The Ahmedabad stop: 'Nana Chiloda' on a repo database, 'Ahmedabad' on
       an install that spells it out. */
    $hub = Database::fetch(
        "SELECT id, sort_order FROM route_stops
          WHERE route_id = :r AND stop_type = :t
            AND (stop_name LIKE '%Nana Chiloda%' OR stop_name LIKE '%Ahmedabad%')
          ORDER BY sort_order, id LIMIT 1",
        ['r' => $rid, 't' => $type]
    );
    if ($hub === null) { continue; }
    $want = (int) $hub['sort_order'] + ($side === 'before' ? 0 : 1);
    $rajkot = Database::fetch(
        "SELECT id, sort_order FROM route_stops WHERE route_id = :r AND stop_type = :t AND stop_name = 'Rajkot'",
        ['r' => $rid, 't' => $type]
    );
    /* Idempotent: once Rajkot sits next to the hub, leave the numbers alone.
       "Next to" is read against the hub's CURRENT slot, because the insert
       below pushes the hub down one — so a second run must not shift it
       again. */
    $done = $side === 'before' ? (int) $hub['sort_order'] - 1 : (int) $hub['sort_order'] + 1;
    if ($rajkot !== null && (int) $rajkot['sort_order'] === $done) { continue; }
    Database::run(
        "UPDATE route_stops SET sort_order = sort_order + 1
          WHERE route_id = :r AND stop_type = :t AND sort_order >= :s AND stop_name <> 'Rajkot'",
        ['r' => $rid, 't' => $type, 's' => $want]
    );
    $row = [
        'sort_order' => $want,
        'is_active'  => 1,
        'landmark'   => 'Rajkot office pickup (+91 94268 27507)',
    ];
    if ($rajkot === null) {
        Database::insert('route_stops', $row + [
            'route_id'     => $rid,
            'stop_type'    => $type,
            'stop_name'    => 'Rajkot',
            'stop_time'    => null,     // an office pickup publishes no clock
            'latitude'     => null,     // and no map pin is invented
            'longitude'    => null,
            'is_border'    => 0,
            'is_meal_halt' => 0,
        ]);
        $log("route $code: $type stop Rajkot added at $want (beside Ahmedabad)");
    } else {
        Database::update('route_stops', $row, 'id = :i', ['i' => (int) $rajkot['id']]);
        $log("route $code: $type stop Rajkot moved to $want (beside Ahmedabad)");
    }
}
/* 6. Park every dated auto-apply promotion the upgrades seed.
      database/upgrade-2026-10-06-dashain-offer.sql seeds DASHAIN10 (10%,
      auto_apply, 6 Oct - 12 Nov 2026), so from 6 Oct 2026 every suite that
      asserts a GROSS fare silently got 10% off: launch-fixes read 1800 for the
      2000 fare, agent-login-bulk-cancel 1980 for 2200, counter-mode-http 880
      for the clerk's own 200. The battery would have gone green again by
      itself on 13 Nov. The offer is real and stays in the table (the Offers
      screen still lists it); it only stops auto-applying while the battery
      runs, so a fare assertion means the same thing in October as in December.
      A suite that wants a running offer installs its own, the way
      auto-offer-test.php does. */
$parked = Database::run("UPDATE coupons SET auto_apply = 0 WHERE auto_apply = 1");
if ($parked > 0) { $log("parked $parked auto-apply offer(s) - fare assertions stay calendar-independent"); }

/* 7. One confirmed counter sale on the daily route, today, with a verified
      payment and an issued ticket.
      On the VPS shari_test is a copy of the live register, so there is always
      a confirmed booking with a ticket to read. A repo-built database has
      none, and six suites quietly depended on an EARLIER suite in the roster
      having made one: ticket-art ("a confirmed booking with a ticket exists"),
      mk-autopilot ("needs at least one confirmed booking to copy"),
      wa-agent-routing (clones a confirmed route-2 booking), wa-local (the
      office passenger list for today), counter-location (which skipped its
      render) and the dashboard's lead-passenger row. Whichever of them ran
      first failed, and which one that was depended on the roster. One honest
      sale here makes every one of them independent.
      Built the way missed-bus-test builds its fixtures: the real seat lock,
      the real ticket issue - nothing hand-written that live would not write. */
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/ticket.php';
$fxPnrPrefix = 'SHG-CIFX-';
$already = Database::fetch(
    "SELECT b.id FROM bookings b JOIN tickets t ON t.booking_id = b.id
      WHERE b.pnr LIKE :p AND b.status = 'confirmed' AND t.is_void = 0 LIMIT 1",
    ['p' => $fxPnrPrefix . '%']
);
if ($already !== null) {
    $log('fixture booking already present (id ' . (int) $already['id'] . ')');
} else {
    $route = Database::fetch("SELECT id, coach_type FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) {
        $log('no active route - skipping the fixture booking');
    } else {
        $rid   = (int) $route['id'];
        $coach = (string) $route['coach_type'];
        $date  = date('Y-m-d');
        $sid   = (int) Seats::schedule($rid, $date)['id'];
        /* Which berth matters, because one sale on today's bus is visible to
           every suite that reads today. The FRONT berths (L1, L3, L5, L6 ...)
           are hard-coded by dozens of suites. The LAST berth is what the
           planner suites read as "the last free berth", and a single-gender
           booking locks its shared CABIN, so taking U36 put U35 out of reach
           of a customer plan and bot-ladder asked for a berth it could not be
           given. So: the middle of the upper deck, a cabin no suite names,
           and only if it is genuinely free - otherwise the first berth that
           is, which is better than no fixture at all. */
        $seats = [];
        try { $seats = array_values(array_map('strval', Seats::seatIdsForSchedule($sid))); } catch (Throwable $e) { $seats = []; }
        if ($seats === []) { try { $seats = array_values(array_map('strval', Seats::seatIds($coach, 'sharing'))); } catch (Throwable $e) { $seats = []; } }
        /* Never a staff / emergency berth (only a super-admin may sell one)
           and never a balcony EX seat, which is staff-only too. */
        $reserved = [];
        try { $reserved = array_map('strval', Seats::staffSeats($coach)); } catch (Throwable $e) { $reserved = []; }
        $quiet = ['U27', 'U28', 'U25', 'U26', 'U31', 'U32', 'U33', 'U29'];
        $order = array_values(array_unique(array_merge(array_intersect($quiet, $seats), $seats)));
        $pick = '';
        foreach ($order as $no) {
            if ($no === '' || in_array($no, $reserved, true) || str_starts_with($no, 'EX')) { continue; }
            try {
                Seats::assertAvailable($sid, [$no], 'cifx' . bin2hex(random_bytes(3)));
                Seats::assertGenderAllowed($sid, $coach, 'sharing', [$no], ['Male']);
            } catch (Throwable $e) { continue; }
            $pick = $no;
            break;
        }
        if ($pick === '') {
            $log('no free seat on ' . $date . ' - skipping the fixture booking');
        } else {
            try {
                $bid = Database::transaction(static function () use ($sid, $coach, $pick, $date, $fxPnrPrefix): int {
                    Seats::assertAvailable($sid, [$pick], 'cifx' . bin2hex(random_bytes(3)));
                    Seats::assertGenderAllowed($sid, $coach, 'sharing', [$pick], ['Male']);
                    $bid = Database::insert('bookings', [
                        'pnr'              => $fxPnrPrefix . strtoupper(bin2hex(random_bytes(3))),
                        'trip_type'        => 'oneway',
                        'booking_mode'     => 'sharing',
                        'contact_phone'    => '919000000111',
                        'fare_per_seat'    => 1800,
                        'base_total'       => 1800,
                        'total_amount'     => 1800,
                        'currency'         => 'INR',
                        'status'           => 'confirmed',
                        'confirmed_at'     => date('Y-m-d H:i:s'),
                        'source'           => 'counter',
                        'sold_by_admin_id' => 1,
                    ]);
                    $lid = Database::insert('booking_legs', [
                        'booking_id'    => $bid, 'schedule_id' => $sid, 'leg_type' => 'outbound',
                        'travel_date'   => $date, 'fare_per_seat' => 1800, 'seat_count' => 1, 'leg_total' => 1800,
                    ]);
                    Seats::claim($sid, [$pick], $bid, $lid);
                    Database::insert('booking_passengers', [
                        'booking_id' => $bid, 'leg_id' => $lid,
                        'passenger_ref' => 'CIFX' . strtoupper(bin2hex(random_bytes(4))),
                        'seat_no' => $pick, 'full_name' => 'Fixture Passenger', 'gender' => 'Male', 'is_primary' => 1,
                    ]);
                    Database::insert('payments', [
                        'booking_id' => $bid, 'payment_ref' => 'CIFXP' . strtoupper(bin2hex(random_bytes(4))),
                        'method' => 'cash', 'mode' => 'offline', 'amount' => 1800, 'currency' => 'INR',
                        'status' => 'verified', 'verified_at' => date('Y-m-d H:i:s'),
                    ]);
                    Ticket::issue($bid);
                    return $bid;
                });
                $log("confirmed fixture sale on route $rid, $date, seat $pick (booking $bid) with a ticket");
            } catch (Throwable $e) {
                $log('fixture booking skipped: ' . $e->getMessage());
            }
        }
    }
}
Settings::flush();
echo "fixtures ready\n";
