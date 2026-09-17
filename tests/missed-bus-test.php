<?php
/**
 * Missed-bus 24h grace rebooking (BookingService::rebookMissedLeg) — Point 11.
 * Moves a booking whose source trip has ALREADY DEPARTED onto a later same-route
 * service within the grace window, keeping PNR / payment / commission and
 * re-minting the ticket QR, and logging a booking.missed_rebook audit row with
 * the acting agent code + original/missed departures. CLI only.
 *   php -c .claude/php-dev.ini tests/missed-bus-test.php
 * Runs with NO admin session → Auth::isSuperadmin()=false (window IS enforced),
 * bookingScopeAdminId()=null (may touch any booking). Throwaway SHG-TEST- rows.
 *
 * Dates are derived from real timestamps (not "today"/"tomorrow" strings) so the
 * departed/within-window fixtures stay correct even when the suite runs across
 * midnight — the source's scheduled departure must be a genuine past instant.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/tripstatus.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; } }
function expectThrow(string $l, callable $fn): void { try { $fn(); check($l . ' (expected rejection)', false); } catch (Throwable $e) { check($l . ' → "' . $e->getMessage() . '"', true); } }

// --- Timestamp-derived fixture dates (robust across midnight) --------------
$SRC_TS  = strtotime('-3 hours');    // missed departure: ~3h ago (departed, inside 24h)
$SRC_DATE = date('Y-m-d', $SRC_TS);  $SRC_TIME = date('H:i:s', $SRC_TS);
$DST_DATE = date('Y-m-d', (int) strtotime('+2 days'));   // next service, clearly upcoming
$FUT_TS  = strtotime('+5 hours');    // a trip that has NOT departed yet
$FUT_DATE = date('Y-m-d', $FUT_TS);  $FUT_TIME = date('H:i:s', $FUT_TS);
$OLD_TS  = strtotime('-30 hours');   // beyond the 24h grace window
$OLD_DATE = date('Y-m-d', $OLD_TS);  $OLD_TIME = date('H:i:s', $OLD_TS);
$DEAD_DATE = date('Y-m-d', (int) strtotime('-2 days'));  // a cancelled (dead) target trip

$ALL_DATES = array_values(array_unique([$SRC_DATE, $DST_DATE, $FUT_DATE, $OLD_DATE, $DEAD_DATE]));

function seatCount(int $sid): int { return (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE schedule_id = :s AND released_at IS NULL', ['s' => $sid], 0); }

function cleanupAll(): void {
    global $ALL_DATES;
    foreach (pluck(Database::fetchAll("SELECT id, pnr FROM bookings WHERE pnr LIKE 'SHG-TEST-%'"), 'id') as $id) {
        $pnr = (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :i', ['i' => (int) $id], '');
        Database::delete('audit_logs', "entity_type='booking' AND entity_id = :p", ['p' => $pnr]);
        Database::delete('bookings', 'id = :i', ['i' => (int) $id]);   // FK cascade drops legs/seats/passengers/payments/tickets
    }
    foreach ($ALL_DATES as $d) {
        foreach (pluck(Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $d]), 'id') as $sid) {
            Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $sid]);
        }
        Database::delete('booking_legs', 'travel_date = :d', ['d' => $d]);
        Database::delete('schedules', 'travel_date = :d', ['d' => $d]);
    }
}

/** Build a confirmed booking on $sid/$date with $seat, optional selling agent. */
function mkBooking(int $sid, string $coach, string $seat, string $gender, string $date, ?int $soldBy = null): int {
    return Database::transaction(function () use ($sid, $coach, $seat, $gender, $date, $soldBy): int {
        Seats::assertAvailable($sid, [$seat], 't' . bin2hex(random_bytes(3)));
        Seats::assertGenderAllowed($sid, $coach, 'sharing', [$seat], [$gender]);
        $bid = Database::insert('bookings', [
            'pnr' => 'SHG-TEST-' . strtoupper(bin2hex(random_bytes(4))),
            'trip_type' => 'oneway', 'booking_mode' => 'sharing',
            'contact_phone' => '919000000000', 'fare_per_seat' => 1800, 'base_total' => 1800,
            'total_amount' => 1800, 'currency' => 'INR', 'status' => 'confirmed',
            'confirmed_at' => date('Y-m-d H:i:s'), 'source' => 'counter',
            'sold_by_admin_id' => $soldBy,
        ]);
        $lid = Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'leg_type' => 'outbound', 'travel_date' => $date, 'fare_per_seat' => 1800, 'seat_count' => 1, 'leg_total' => 1800]);
        Seats::claim($sid, [$seat], $bid, $lid);
        Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $lid, 'passenger_ref' => 'PAX' . strtoupper(bin2hex(random_bytes(5))), 'seat_no' => $seat, 'full_name' => 'T ' . $seat, 'gender' => $gender, 'is_primary' => 1]);
        Database::insert('payments', ['booking_id' => $bid, 'payment_ref' => 'PAY' . strtoupper(bin2hex(random_bytes(5))), 'method' => 'cash', 'mode' => 'offline', 'amount' => 1800, 'currency' => 'INR', 'status' => 'verified', 'verified_at' => date('Y-m-d H:i:s')]);
        Ticket::issue($bid);
        return $bid;
    });
}

/** Force a schedule's effective departure to a given wall-clock (HH:MM:SS) via override. */
function setDep(int $sid, string $hms): void { Database::update('schedules', ['dep_time_override' => $hms], 'id = :i', ['i' => $sid]); }

echo "\n=== Missed-bus 24h grace (rebookMissedLeg) ===\n\n";

Settings::set('staff_seats', ['sleeper' => ['L1'], 'seater' => ['1A']], 'json', 'seats', false);

try {
    $route = Database::fetch("SELECT id, coach_type FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no sleeper route\n"; exit(1); }
    $rid = (int) $route['id']; $coach = (string) $route['coach_type'];

    cleanupAll();
    // Source = a trip that departed ~3h ago (inside the 24h window).
    $sidSrc = (int) Seats::schedule($rid, $SRC_DATE)['id'];
    setDep($sidSrc, $SRC_TIME);
    // Destination = a clearly-upcoming trip (counter-bookable).
    $sidDst = (int) Seats::schedule($rid, $DST_DATE)['id'];

    // Give the selling agent a code so the audit captures SHG-NNNN.
    $agentId = (int) Database::scalar("SELECT id FROM admins WHERE role='agent' AND is_active=1 ORDER BY id LIMIT 1", [], 0);
    $agentLabel = '';
    if ($agentId > 0) {
        if (AgentWallet::agentCodeFor($agentId) === null) {
            $free = AgentWallet::nextFreeAgentCode();
            if ($free !== null) { AgentWallet::setAgentCode($agentId, $free, 0); }
        }
        $agentLabel = AgentWallet::agentCodeLabel($agentId);
    }

    // ---- Happy path: passenger missed the 3h-ago bus → move to the next ----
    $bid = mkBooking($sidSrc, $coach, 'L3', 'Female', $SRC_DATE, $agentId > 0 ? $agentId : null);
    $tkBefore = Database::fetch('SELECT qr_payload, ticket_number FROM tickets WHERE booking_id = :b', ['b' => $bid]);
    check('setup: seat held on source', seatCount($sidSrc) === 1);

    $res = BookingService::rebookMissedLeg($bid, $sidDst, ['L7'], $DST_DATE, 1);
    check('rebookMissedLeg returns changed=true', ($res['changed'] ?? false) === true);
    check('old seat freed on source (count 0)', seatCount($sidSrc) === 0);
    check('new seat claimed on dest (count 1)', seatCount($sidDst) === 1);

    $leg = Database::fetch("SELECT schedule_id, travel_date FROM booking_legs WHERE booking_id = :b AND leg_type='outbound'", ['b' => $bid]);
    check('leg moved to dest schedule', (int) $leg['schedule_id'] === $sidDst);
    check('leg travel_date → dest date', (string) $leg['travel_date'] === $DST_DATE);

    $paxSeat = (string) Database::scalar('SELECT seat_no FROM booking_passengers WHERE booking_id = :b LIMIT 1', ['b' => $bid]);
    check('passenger seat_no → L7', $paxSeat === 'L7');

    $bk = Database::fetch('SELECT status, total_amount FROM bookings WHERE id = :b', ['b' => $bid]);
    check('booking still confirmed', (string) $bk['status'] === 'confirmed');
    check('total_amount unchanged (1800)', (int) $bk['total_amount'] === 1800);
    check('exactly one payment (no re-collect)', (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE booking_id = :b', ['b' => $bid], 0) === 1);

    $tkAfter = Database::fetch('SELECT qr_payload, ticket_number FROM tickets WHERE booking_id = :b', ['b' => $bid]);
    check('ticket_number preserved', (string) $tkAfter['ticket_number'] === (string) $tkBefore['ticket_number']);
    check('QR re-minted (encodes new date)', strpos((string) $tkAfter['qr_payload'], $DST_DATE) !== false);

    // ---- Audit: booking.missed_rebook with reason + agent code + original dep ----
    $pnr = (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :b', ['b' => $bid], '');
    $audit = Database::fetch("SELECT action, new_value, detail FROM audit_logs WHERE entity_type='booking' AND entity_id=:p AND action='booking.missed_rebook' ORDER BY id DESC LIMIT 1", ['p' => $pnr]);
    check('audit row booking.missed_rebook written', $audit !== null);
    if ($audit !== null) {
        $nv = json_decode((string) $audit['new_value'], true) ?: [];
        check('audit reason = missed_bus', ($nv['reason'] ?? '') === 'missed_bus');
        check('audit records original_dep', !empty($nv['original_dep']));
        if ($agentLabel !== '') {
            check('audit captures selling agent code ' . $agentLabel, ($nv['agent_code'] ?? '') === $agentLabel);
            check('audit detail names the agent', strpos((string) $audit['detail'], $agentLabel) !== false);
        } else {
            echo "  (skip agent-code assertion — no agent account seeded)\n";
        }
    }

    // ---- Adversarial ----------------------------------------------------
    // (a) Target trip is CANCELLED → not counter-bookable.
    $sidDead = (int) Seats::schedule($rid, $DEAD_DATE)['id'];
    Database::update('schedules', ['status' => 'cancelled'], 'id = :i', ['i' => $sidDead]);
    $bid2 = mkBooking($sidSrc, $coach, 'L9', 'Male', $SRC_DATE, null);
    expectThrow('cannot rebook onto a cancelled target trip',
        fn() => BookingService::rebookMissedLeg($bid2, $sidDead, ['L3'], $DEAD_DATE, 1));

    // (b) Source has NOT departed yet → must use Reschedule, not missed grace.
    $sidFuture = (int) Seats::schedule($rid, $FUT_DATE)['id'];
    setDep($sidFuture, $FUT_TIME);
    $bid3 = mkBooking($sidFuture, $coach, 'L11', 'Male', $FUT_DATE, null);
    expectThrow('cannot use missed-grace before the bus has departed',
        fn() => BookingService::rebookMissedLeg($bid3, $sidSrc, ['L13'], $SRC_DATE, 1));

    // (c) Window expired: source departed > 24h ago.
    $sidOld = (int) Seats::schedule($rid, $OLD_DATE)['id'];
    setDep($sidOld, $OLD_TIME);
    $bid4 = mkBooking($sidOld, $coach, 'L15', 'Male', $OLD_DATE, null);
    expectThrow('cannot rebook after the 24h grace window has passed',
        fn() => BookingService::rebookMissedLeg($bid4, $sidDst, ['L17'], $DST_DATE, 1));

    // (d) Wrong seat count.
    expectThrow('cannot change the seat count on a missed rebooking',
        fn() => BookingService::rebookMissedLeg($bid, $sidSrc, ['L1', 'L2'], $SRC_DATE, 1));

    // (e) Leg selector (17 Sep 2026): only outbound/return exist; a bogus
    // value is refused up front and the booking stays exactly where it is.
    expectThrow('a bogus leg type is rejected on a missed rebooking',
        fn() => BookingService::rebookMissedLeg($bid, $sidSrc, ['L3'], $SRC_DATE, 1, 'sideways'));
    check('bogus leg type moved nothing — bid still on dest', seatCount($sidDst) === 1);

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanupAll();
    Settings::set('staff_seats', ['sleeper' => ['L5', 'L6'], 'seater' => ['1A']], 'json', 'seats', false);
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
