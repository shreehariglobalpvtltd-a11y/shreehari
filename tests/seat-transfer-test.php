<?php
/**
 * Agent seat transfer — reseat a passenger without a duplicate booking,
 * gender-safe. CLI only.
 *   php -c .claude/php-dev.ini tests/seat-transfer-test.php
 * Throwaway rows (SHG-TEST-) on a far-future date; cleans up after itself.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/notify.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; } }
function expectThrow(string $l, callable $fn): void { try { $fn(); check($l . ' (expected rejection)', false); } catch (RuntimeException $e) { check($l . ' → "' . $e->getMessage() . '"', true); } }

const TD = '2099-10-15';
$sid = 0;

function cleanup(int $sid): void {
    if ($sid <= 0) return;
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE pnr LIKE 'SHG-TEST-%'") as $bk) {
        Database::delete('message_logs', 'booking_id = :b', ['b' => (int) $bk['id']]);
        Database::delete('audit_logs', "entity_type = 'booking' AND entity_id = :p", ['p' => (string) $bk['pnr']]);
        if (defined('TICKET_PATH')) { @unlink(TICKET_PATH . '/ticket_' . $bk['pnr'] . '.png'); @unlink(TICKET_PATH . '/ticket_' . $bk['pnr'] . '.pdf'); }
        Database::delete('bookings', 'id = :i', ['i' => (int) $bk['id']]);
    }
    Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]);
    Database::delete('booking_legs', 'schedule_id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
    Database::delete('schedules', 'id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
}
function book(int $sid, string $coach, string $seat, string $gender): void {
    Database::transaction(function () use ($sid, $coach, $seat, $gender) {
        Seats::assertAvailable($sid, [$seat], 't' . bin2hex(random_bytes(3)));
        Seats::assertGenderAllowed($sid, $coach, 'sharing', [$seat], [$gender]);
        $bid = Database::insert('bookings', ['pnr' => 'SHG-TEST-' . strtoupper(bin2hex(random_bytes(4))), 'contact_phone' => '919000000077', 'status' => 'confirmed', 'source' => 'agent', 'booking_mode' => 'sharing', 'total_amount' => 1800, 'fare_per_seat' => 1800, 'base_total' => 1800]);
        $lid = Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'leg_type' => 'outbound', 'travel_date' => TD, 'seat_count' => 1, 'fare_per_seat' => 1800, 'leg_total' => 1800]);
        Seats::claim($sid, [$seat], $bid, $lid);
        Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $lid, 'passenger_ref' => 'PAX' . strtoupper(bin2hex(random_bytes(5))), 'seat_no' => $seat, 'full_name' => 'T ' . $seat, 'gender' => $gender]);
        Database::insert('payments', ['booking_id' => $bid, 'payment_ref' => 'PAY' . strtoupper(bin2hex(random_bytes(5))), 'method' => 'cash', 'mode' => 'offline', 'amount' => 1800, 'currency' => 'INR', 'status' => 'verified']);
    });
}
function bidOfSeat(int $sid, string $seat): int { return (int) Database::scalar('SELECT booking_id FROM booking_seats WHERE schedule_id = :s AND seat_no = :n AND released_at IS NULL', ['s' => $sid, 'n' => $seat], 0); }
function revOf(int $bid): int { return (int) Database::scalar('SELECT reissue_count FROM tickets WHERE booking_id = :b', ['b' => $bid], -1); }
function glock(int $sid, string $u): string { return (string) Database::scalar('SELECT gender_lock FROM schedule_unit_locks WHERE schedule_id = :s AND unit_key = :u', ['s' => $sid, 'u' => $u], 'none'); }
function seatCount(int $sid): int { return (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE schedule_id = :s AND released_at IS NULL', ['s' => $sid], 0); }

echo "\n=== Agent seat transfer ===\n\n";

/* Fixture predates the L5+L6 reservation (cabin L-3) — pin the old L1
   layout for the run and restore the production one after. */
Settings::set('staff_seats', ['sleeper' => ['L1'], 'seater' => ['1A']], 'json', 'seats', false);

try {
    $route = Database::fetch("SELECT id, coach_type FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no sleeper route\n"; exit(1); }
    $coach = (string) $route['coach_type'];
    $sch = Seats::schedule((int) $route['id'], TD); $sid = (int) $sch['id']; cleanup($sid);
    $sch = Seats::schedule((int) $route['id'], TD); $sid = (int) $sch['id'];

    // L1 is the permanently reserved staff / emergency berth
    // (Seats::staffSeats), so this fixture works from cabin L-2 back.
    book($sid, $coach, 'L3', 'Female');   // cabin L-2 → female_only
    book($sid, $coach, 'L5', 'Male');     // cabin L-3 → male_only
    $before = seatCount($sid);
    check('cabin L-2 is female_only to start', glock($sid, 'L-2') === 'female_only');

    /* The ticket (1 Oct 2026, R3f/R3e): issued before the move, it must come
       out of transferSeat() re-minted EXACTLY once (booking-view used to add a
       second reissue: REV +2), redrawn, and the passenger told through the
       ticket_update purpose the first-ticket guard no longer swallows. */
    $prevDriver = Settings::getString('whatsapp_driver', 'click_to_chat');
    $prevEdit   = Settings::getString('notify_ticket_edit', '1');
    Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);
    Settings::set('notify_ticket_edit', '1', 'bool', 'notify', false);
    Settings::flush();
    $tBid = bidOfSeat($sid, 'L3');
    Ticket::issue($tBid);
    $urlBefore = Ticket::imageUrl((string) Database::scalar('SELECT pnr FROM bookings WHERE id = :i', ['i' => $tBid], ''));
    check('setup: ticket issued at REV 0', revOf($tBid) === 0);

    $r = Seats::transferSeat($sid, 'L3', 'L7', $coach, 1, 'test reseat');   // move the woman to an empty cabin
    check('transfer L3→L7 returns the PNR', !empty($r['pnr']));
    check('the ticket is re-minted exactly once (REV 1)', revOf($tBid) === 1);
    check('the PNG is redrawn at once, not left for the next open', (string) Database::scalar('SELECT png_path FROM tickets WHERE booking_id = :b', ['b' => $tBid], '') !== ''
        && is_file(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png'));
    check('the new QR carries the new seat', str_contains((string) Database::scalar('SELECT qr_payload FROM tickets WHERE booking_id = :b', ['b' => $tBid], ''), 'L7'));
    check('the ticket link changed with the revision', Ticket::imageUrl((string) $r['pnr']) !== $urlBefore);
    check('a ticket_update WhatsApp row was written for the passenger (no idempotent swallow)',
        Database::exists("SELECT 1 FROM message_logs WHERE booking_id = :b AND channel = 'whatsapp' AND purpose = 'ticket_update'", ['b' => $tBid]));
    $tsAudit = Database::fetch("SELECT new_value, reason FROM audit_logs WHERE action = 'ticket.regenerated' AND entity_id = :p ORDER BY id DESC LIMIT 1", ['p' => (string) $r['pnr']]);
    check('one ticket.regenerated audit row names the kind and carries the reason', $tsAudit !== null
        && str_contains((string) $tsAudit['new_value'], '"kind":"seat"') && (string) $tsAudit['reason'] === 'test reseat');
    check('seat.transfer audit carries the reason too', (string) Database::scalar("SELECT reason FROM audit_logs WHERE action = 'seat.transfer' AND entity_id = :p ORDER BY id DESC LIMIT 1", ['p' => (string) $r['pnr']], '') === 'test reseat');
    Settings::set('whatsapp_driver', $prevDriver, 'string', 'notify', false);
    Settings::set('notify_ticket_edit', $prevEdit, 'bool', 'notify', false);
    Settings::flush();
    check('no duplicate booking (seat count unchanged)', seatCount($sid) === $before);
    check('vacated cabin L-2 reopens (none)', glock($sid, 'L-2') === 'none');
    check('new cabin L-4 becomes female_only', glock($sid, 'L-4') === 'female_only');
    check('seat L3 is free again', in_array('L3', Seats::availability((int) $route['id'], TD, 'sharing')['available'], true));

    expectThrow('cannot move a female into a male-only cabin', fn() => Seats::transferSeat($sid, 'L7', 'L6', $coach, 1));
    expectThrow('cannot transfer onto an occupied seat', fn() => Seats::transferSeat($sid, 'L5', 'L7', $coach, 1));
    expectThrow('cannot transfer a seat that is not booked', fn() => Seats::transferSeat($sid, 'U9', 'U10', $coach, 1));
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage(), false);
} finally {
    cleanup($sid);
    Settings::set('staff_seats', ['sleeper' => ['L5', 'L6'], 'seater' => ['1A']], 'json', 'seats', false);
}
echo "\n----------------------------------------\n  $PASS passed, $FAIL failed\n----------------------------------------\n\n";
exit($FAIL === 0 ? 0 : 1);
