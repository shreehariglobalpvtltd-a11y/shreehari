<?php
/**
 * Agent login + bulk cancel + manual bus (4 Sep 2026).
 *
 * Locks in the six rules this batch introduced:
 *
 *   1. An agent signs in with EMAIL + USERNAME + PASSWORD. All three must
 *      match one ACTIVE account whose role is 'agent'; a wrong email fails
 *      exactly like a wrong password, and an office account cannot be signed
 *      in through the agent door.
 *   2. The office can view / edit / reset an agent's username, email and
 *      password (Auth::setStaffCredentials): uniqueness enforced, weak or
 *      malformed input refused, every change audited — never the password.
 *   3. An agent may cancel ONLY tickets they sold (bookings.cancel_own);
 *      a role holding bookings.cancel may cancel anyone's.
 *   4. Bulk cancel honours that per booking: other agents' tickets are
 *      SKIPPED and reported, never cancelled, and one booking.bulk_cancel
 *      audit row records who / how many / which PNRs / why.
 *   5. The daily bus is created automatically for the rolling window, and a
 *      re-run is a no-op (the nightly cron may run twice safely).
 *   6. A manually added bus may carry its own seat count and its own price,
 *      and a booking on it is charged that price.
 *
 *   php -c .claude/php-dev.ini tests/agent-login-bulk-cancel-test.php
 *
 * Writes only throwaway rows (two test agents, their bookings, one extra
 * schedule) and cleans up after itself. CLI only.
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
require_once INCLUDE_PATH . '/agentwallet.php';

/* A real sign-in regenerates the session id, which needs a live session AND
   unsent headers. bootstrap.php only starts a session for web requests, and
   on CLI the first echo counts as "headers sent" — so open a session and hold
   every line in an output buffer, which is flushed when the script ends. */
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
ob_start();

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

const A_USER  = 'testblk-agent-a';
const B_USER  = 'testblk-agent-b';
const A_MAIL  = 'testblk-a@example.com';
const B_MAIL  = 'testblk-b@example.com';
const A_PW    = 'blktest-pw-aaa1';
const PHONE   = '9700000778';

$D = date('Y-m-d', strtotime('+12 days'));

/** Sign the given admins row into the session, the way a real login does. */
function beAdmin(?array $row): void {
    if ($row === null) { unset($_SESSION[ADMIN_SESSION_KEY]); return; }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $row['id'], 'username' => (string) $row['username'],
        'full_name' => (string) ($row['full_name'] ?? ''), 'role' => (string) $row['role'],
        'permissions' => jsonColumn($row['permissions'] ?? null),
        'must_change_pw' => false, 'via_otp' => false,
        'logged_in_at' => time(), 'last_seen' => time(),
    ];
}

function cleanup(array $ids, int $rid, string $date): void {
    foreach (Database::fetchAll('SELECT id FROM bookings WHERE contact_phone = :p', ['p' => PHONE]) as $b) {
        try { Seats::releaseBooking((int) $b['id']); } catch (Throwable $e) {}
        Database::run('DELETE FROM bookings WHERE id = :i', ['i' => (int) $b['id']]);
    }
    foreach ($ids as $id) {
        if ($id <= 0) { continue; }
        Database::run('DELETE FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $id]);
        Database::run('DELETE FROM admin_profiles WHERE admin_id = :a', ['a' => $id]);
        Database::run('DELETE FROM admins WHERE id = :a', ['a' => $id]);
    }
    Database::run('DELETE FROM seat_locks WHERE schedule_id IN (SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d)', ['r' => $rid, 'd' => $date]);
    Database::run('DELETE FROM schedules WHERE route_id = :r AND travel_date = :d AND slot > 1', ['r' => $rid, 'd' => $date]);
    Database::run('DELETE FROM audit_logs WHERE action IN (\'booking.bulk_cancel\', \'agent.credentials\') AND detail LIKE \'%#0%\'', []);
}

echo "\n=== Agent login · bulk cancel · manual bus (4 Sep 2026) ===\n\n";

/* from_city joined the SELECT on 26 Sep 2026: the fare now depends on which
   pickup, so working out "the normal fare" needs the route's own origin. */
$route = Database::fetch("SELECT id, route_code, from_city, to_city, coach_type FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
if ($route === null) { echo "no active sleeper route\n"; exit(1); }
$rid = (int) $route['id'];

$super = Database::fetch("SELECT * FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1");
if ($super === null) { echo "no superadmin\n"; exit(1); }

$serviceWas = Settings::getBool('daily_service_on', true);
Settings::set('daily_service_on', '1', 'bool', 'booking', true);

$aId = 0; $bId = 0;
foreach ([A_USER, B_USER] as $u) { Database::run('DELETE FROM admins WHERE username = :u', ['u' => $u]); }
Database::run('DELETE FROM admins WHERE email IN (:a, :b)', ['a' => A_MAIL, 'b' => B_MAIL]);

try {
    /* ---- two agents ------------------------------------------------ */
    foreach ([[A_USER, A_MAIL, 'aId'], [B_USER, B_MAIL, 'bId']] as [$u, $m, $var]) {
        $$var = Database::insert('admins', [
            'username' => $u, 'email' => $m,
            'password_hash' => Security::hashPassword(A_PW),
            'full_name' => 'Bulk Test ' . strtoupper(substr($u, -1)),
            'phone' => null, 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0,
        ]);
    }
    $agentA = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $aId]);
    $agentB = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $bId]);

    /* ================================================================
     *  1. Agent password login — email + username + password
     * ================================================================ */
    echo "-- agent login --\n";
    beAdmin(null);
    check('correct email + username + password signs the agent in',
        !empty(Auth::agentPasswordLogin(A_MAIL, A_USER, A_PW)['ok']));
    check('session now holds that agent', (int) (Auth::admin()['id'] ?? 0) === $aId);

    beAdmin(null);
    Database::run('DELETE FROM rate_limits', []);   // per-IP throttle is shared across these attempts
    $r = Auth::agentPasswordLogin(B_MAIL, A_USER, A_PW);
    check('a valid email belonging to ANOTHER agent is refused', empty($r['ok']));
    check('the failure message names no field', str_contains((string) ($r['error'] ?? ''), 'did not match'));

    Database::run('DELETE FROM rate_limits', []);
    check('wrong password is refused', empty(Auth::agentPasswordLogin(A_MAIL, A_USER, 'not-the-password')['ok']));
    Database::run('DELETE FROM rate_limits', []);
    check('blank email is refused', empty(Auth::agentPasswordLogin('', A_USER, A_PW)['ok']));
    Database::run('DELETE FROM rate_limits', []);
    check('an office (superadmin) account cannot use the agent door',
        empty(Auth::agentPasswordLogin((string) ($super['email'] ?? 'x@y.z'), (string) $super['username'], A_PW)['ok']));

    Database::run('DELETE FROM rate_limits', []);
    Database::update('admins', ['is_active' => 0, 'failed_logins' => 0, 'locked_until' => null], 'id = :i', ['i' => $aId]);
    $r = Auth::agentPasswordLogin(A_MAIL, A_USER, A_PW);
    check('a deactivated agent cannot sign in', empty($r['ok']));
    check('and is told the account is not active', str_contains((string) ($r['error'] ?? ''), 'not active'));
    Database::update('admins', ['is_active' => 1, 'failed_logins' => 0, 'locked_until' => null], 'id = :i', ['i' => $aId]);

    Database::run('DELETE FROM rate_limits', []);
    for ($i = 0; $i < MAX_LOGIN_ATTEMPTS; $i++) { Auth::agentPasswordLogin(A_MAIL, A_USER, 'wrong' . $i); }
    check('repeated wrong passwords lock the account',
        (int) Database::scalar('SELECT failed_logins FROM admins WHERE id = :i', ['i' => $aId], 0) >= MAX_LOGIN_ATTEMPTS);
    Database::update('admins', ['failed_logins' => 0, 'locked_until' => null], 'id = :i', ['i' => $aId]);
    Database::run('DELETE FROM rate_limits', []);

    /* ================================================================
     *  2. Office manages the agent's credentials
     * ================================================================ */
    echo "\n-- admin manages agent credentials --\n";
    beAdmin($super);

    $before = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'agent.credentials'", [], 0);
    $res = Auth::setStaffCredentials($aId, ['username' => 'testblk-agent-a2', 'email' => 'testblk-a2@example.com'], (int) $super['id']);
    check('username + email changed', in_array('username', $res['changed'], true) && in_array('email', $res['changed'], true));
    check('an agent.credentials audit row was written',
        (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'agent.credentials'", [], 0) === $before + 1);
    $auditRow = Database::fetch("SELECT new_value FROM audit_logs WHERE action = 'agent.credentials' ORDER BY id DESC LIMIT 1");
    check('the audit row never stores the password',
        $auditRow !== null && !str_contains(strtolower((string) $auditRow['new_value']), 'pw-aaa1'));

    Database::run('DELETE FROM rate_limits', []);
    check('the agent signs in with the NEW email + username',
        !empty(Auth::agentPasswordLogin('testblk-a2@example.com', 'testblk-agent-a2', A_PW)['ok']));
    beAdmin($super);
    Database::run('DELETE FROM rate_limits', []);
    check('the OLD email no longer works', empty(Auth::agentPasswordLogin(A_MAIL, 'testblk-agent-a2', A_PW)['ok']));
    beAdmin($super);
    Database::run('DELETE FROM rate_limits', []);

    throws('a username already taken is refused',
        fn() => Auth::setStaffCredentials($aId, ['username' => B_USER], (int) $super['id']), 'already taken');
    throws('an email already used is refused',
        fn() => Auth::setStaffCredentials($aId, ['email' => B_MAIL], (int) $super['id']), 'already used');
    throws('a malformed email is refused',
        fn() => Auth::setStaffCredentials($aId, ['email' => 'not-an-email'], (int) $super['id']), 'valid email');
    throws('a password under 8 characters is refused',
        fn() => Auth::setStaffCredentials($aId, ['password' => 'short'], (int) $super['id']), 'at least 8');
    throws('an unknown account is refused',
        fn() => Auth::setStaffCredentials(99999999, ['username' => 'nobody'], (int) $super['id']), 'not found');

    Auth::setStaffCredentials($aId, ['password' => 'brand-new-pw-99'], (int) $super['id']);
    Database::run('DELETE FROM rate_limits', []);
    check('the reset password works', !empty(Auth::agentPasswordLogin('testblk-a2@example.com', 'testblk-agent-a2', 'brand-new-pw-99')['ok']));
    beAdmin($super);
    Database::run('DELETE FROM rate_limits', []);
    check('the old password no longer works', empty(Auth::agentPasswordLogin('testblk-a2@example.com', 'testblk-agent-a2', A_PW)['ok']));
    beAdmin($super);
    Database::run('DELETE FROM rate_limits', []);

    $noop = Auth::setStaffCredentials($aId, ['username' => 'testblk-agent-a2'], (int) $super['id']);
    check('re-saving the same details changes nothing', $noop['changed'] === []);

    /* ================================================================
     *  3. Who may cancel what
     * ================================================================ */
    echo "\n-- cancel permissions --\n";
    beAdmin($agentA);
    check('an agent holds bookings.cancel_own', Auth::can('bookings.cancel_own'));
    check('an agent does NOT hold bookings.cancel', !Auth::can('bookings.cancel'));
    check('an agent may cancel their own sale', Auth::mayCancelBooking(['sold_by_admin_id' => $aId]));
    check('an agent may NOT cancel another agent\'s sale', !Auth::mayCancelBooking(['sold_by_admin_id' => $bId]));
    check('an agent may NOT cancel an unsold (online) booking', !Auth::mayCancelBooking(['sold_by_admin_id' => null]));
    check('mayCancelAny() is true for an agent', Auth::mayCancelAny());

    beAdmin($super);
    check('a superadmin may cancel any agent\'s sale', Auth::mayCancelBooking(['sold_by_admin_id' => $bId]));
    check('a superadmin may cancel an online booking', Auth::mayCancelBooking(['sold_by_admin_id' => null]));

    /* ================================================================
     *  4. Bulk cancel
     * ================================================================ */
    echo "\n-- bulk cancel --\n";
    $mk = static function (int $sellerId, string $seat) use ($rid, $D): int {
        $b = BookingService::create([
            'routeId' => $rid, 'travelDate' => $D, 'scheduleId' => 0, 'seats' => [$seat],
            'passengers' => [['name' => 'Bulk Pax', 'age' => 30, 'gender' => 'Male']],
            'contact' => ['phone' => PHONE], 'bookingMode' => 'sharing', 'paymentMethod' => 'upi',
            'isCod' => false, 'boarding' => '', 'referralCode' => '',
        ]);
        $id = (int) $b['id'];
        Database::update('bookings', ['sold_by_admin_id' => $sellerId, 'source' => 'agent'], 'id = :i', ['i' => $id]);
        return $id;
    };

    beAdmin($super);
    $a1 = $mk($aId, 'L10');
    $a2 = $mk($aId, 'L11');
    $b1 = $mk($bId, 'L12');

    beAdmin($agentA);
    $auditBefore = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'booking.bulk_cancel'", [], 0);
    $out = BookingService::bulkCancel([$a1, $a2, $b1], 'Bus not running', $aId);

    check('two of the agent\'s own tickets were cancelled', $out['cancelled'] === 2, json_encode($out['results']));
    check('the other agent\'s ticket was skipped', $out['skipped'] === 1);
    check('nothing failed outright', $out['failed'] === 0);
    check('the skipped one is named with a reason',
        (bool) array_filter($out['results'], static fn(array $r): bool => !$r['ok'] && str_contains((string) ($r['error'] ?? ''), 'Not your ticket')));
    check('own ticket 1 is now cancelled', (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => $a1], '') === 'cancelled');
    check('own ticket 2 is now cancelled', (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => $a2], '') === 'cancelled');
    check('the OTHER agent\'s ticket was left untouched',
        in_array((string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => $b1], ''), ['pending', 'confirmed'], true));
    check('the cancel reason was written onto the booking',
        (string) Database::scalar('SELECT cancel_reason FROM bookings WHERE id = :i', ['i' => $a1], '') === 'Bus not running');
    check('the seats were released',
        (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = :i AND released_at IS NULL', ['i' => $a1], 0) === 0);

    check('one booking.bulk_cancel audit row was written',
        (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'booking.bulk_cancel'", [], 0) === $auditBefore + 1);
    $bulkAudit = Database::fetch("SELECT actor_id, new_value, detail FROM audit_logs WHERE action = 'booking.bulk_cancel' ORDER BY id DESC LIMIT 1");
    $bulkNew   = $bulkAudit !== null ? jsonColumn($bulkAudit['new_value']) : [];
    check('the audit row records the actor', $bulkAudit !== null && (int) $bulkAudit['actor_id'] === $aId);
    check('the audit row records how many were cancelled', (int) ($bulkNew['cancelled'] ?? -1) === 2);
    check('the audit row lists the cancelled PNRs', count((array) ($bulkNew['pnrs'] ?? [])) === 2);
    check('the audit row lists the refused ticket', count((array) ($bulkNew['refused'] ?? [])) === 1);
    check('the audit row records the reason', (string) ($bulkNew['reason'] ?? '') === 'Bus not running');
    check('one booking.cancel row per cancelled PNR as well',
        (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'booking.cancel' AND entity_id IN (SELECT pnr FROM bookings WHERE id IN (:a, :b))",
            ['a' => $a1, 'b' => $a2], 0) === 2);

    $again = BookingService::bulkCancel([$a1], 'Second attempt', $aId);
    check('an already-cancelled ticket is skipped, not re-cancelled', $again['cancelled'] === 0 && $again['skipped'] === 1);

    throws('an empty selection is refused', fn() => BookingService::bulkCancel([], 'x', $aId), 'at least one');
    throws('more than the cap is refused',
        fn() => BookingService::bulkCancel(range(1, BookingService::BULK_CANCEL_MAX + 1), 'x', $aId), 'at most');

    $ghost = BookingService::bulkCancel([99999998], 'x', $aId);
    check('an unknown id is reported, not fatal', $ghost['failed'] === 1 && $ghost['cancelled'] === 0);

    beAdmin($super);
    $sup = BookingService::bulkCancel([$b1], 'Office cancel', (int) $super['id']);
    check('the office CAN bulk-cancel another agent\'s ticket', $sup['cancelled'] === 1);

    /* ================================================================
     *  5. The automatic daily bus
     * ================================================================ */
    echo "\n-- automatic daily bus --\n";
    $seed1 = ScheduleMaker::ensureNextDays(Database::pdo(), 30, 0);
    $seed2 = ScheduleMaker::ensureNextDays(Database::pdo(), 30, 0);
    check('the seeder covers the active daily routes', $seed1['routes'] !== [] || $seed1['skipped_existing'] > 0);
    check('a second run creates nothing (safe to re-run)', $seed2['created'] === 0, json_encode($seed2));
    check('every day of the window now has the daily bus',
        (int) Database::scalar(
            'SELECT COUNT(DISTINCT travel_date) FROM schedules WHERE route_id = :r AND slot = 1 AND travel_date BETWEEN :a AND :b',
            ['r' => $rid, 'a' => todayISO(), 'b' => addDaysISO(todayISO(), 29)], 0
        ) === 30);
    check('the daily bus is always slot 1',
        (int) Seats::schedule($rid, addDaysISO(todayISO(), 5))['slot'] === 1);

    /* ================================================================
     *  6. A manually added bus with its own seats and its own price
     * ================================================================ */
    echo "\n-- manual bus: own seats, own price --\n";
    $slot = ScheduleMaker::nextFreeSlot($rid, $D);
    $x = ScheduleMaker::insertOne($rid, $D, [
        'slot' => $slot, 'dep_time_override' => '22:30', 'total_seats' => 30, 'fare_override' => 2600,
    ]);
    $xid  = (int) $x['id'];
    $xRow = Database::fetch('SELECT * FROM schedules WHERE id = :i', ['i' => $xid]);
    check('the extra bus took its own seat count', (int) $xRow['total_seats'] === 30);
    check('the extra bus took its own price', (float) $xRow['fare_override'] === 2600.0);
    check('scheduleFareOverride reads it back', BookingService::scheduleFareOverride($xRow) === 2600.0);
    check('a normal daily row has no override',
        BookingService::scheduleFareOverride(Seats::schedule($rid, $D)) === null);

    throws('a nonsense price is refused', fn() => ScheduleMaker::fareInput('abc'), 'must be a number');
    throws('an absurd price is refused', fn() => ScheduleMaker::fareInput(999999), 'maximum');
    check('a blank price means "no override"', ScheduleMaker::fareInput('') === null);
    check('a zero price clears the override', ScheduleMaker::fareInput(0) === null);

    $bx  = BookingService::create([
        'routeId' => $rid, 'travelDate' => $D, 'scheduleId' => $xid, 'seats' => ['L20'],
        'passengers' => [['name' => 'Priced Pax', 'age' => 28, 'gender' => 'Male']],
        'contact' => ['phone' => PHONE], 'bookingMode' => 'sharing', 'paymentMethod' => 'upi',
        'isCod' => false, 'boarding' => '', 'referralCode' => '',
    ]);
    check('a booking on that bus is charged its own price',
        (float) $bx['total_amount'] === 2600.0, 'charged ' . $bx['total_amount']);

    $bd = BookingService::create([
        'routeId' => $rid, 'travelDate' => $D, 'scheduleId' => 0, 'seats' => ['L21'],
        'passengers' => [['name' => 'Normal Pax', 'age' => 28, 'gender' => 'Male']],
        'contact' => ['phone' => PHONE], 'bookingMode' => 'sharing', 'paymentMethod' => 'upi',
        'isCod' => false, 'boarding' => '', 'referralCode' => '',
    ]);
    $normal = Fare::cabinFare('single', 'sharing', 1, true, (string) $route['to_city'], 4,
                              (string) $route['from_city'])['perPerson'];
    check('the daily bus still charges the normal fare',
        (float) $bd['total_amount'] === (float) $normal, 'charged ' . $bd['total_amount'] . ' vs ' . $normal);
    /* ================================================================
     *  7. A manually added bus with its OWN SEAT LAYOUT
     * ================================================================ */
    echo "
-- manual bus: own seat layout --
";
    $routeCoach = (string) $route['coach_type'];
    $otherCoach = $routeCoach === 'sleeper' ? 'seater' : 'sleeper';

    check('a normal daily row reports the route coach',
        Seats::effectiveCoach(Seats::schedule($rid, $D)) === $routeCoach);

    $slot2 = ScheduleMaker::nextFreeSlot($rid, $D);
    $y = ScheduleMaker::insertOne($rid, $D, ['slot' => $slot2, 'dep_time_override' => '23:15', 'coach_type' => $otherCoach]);
    $yid  = (int) $y['id'];
    $yRow = Database::fetch('SELECT * FROM schedules WHERE id = :i', ['i' => $yid]);

    check('the extra bus stored its own coach', (string) $yRow['coach_type_override'] === $otherCoach);
    check('effectiveCoach reports that coach', Seats::effectiveCoach($yRow) === $otherCoach);
    check('coachForSchedule agrees (the engine reads this one)', Seats::coachForSchedule($yid) === $otherCoach);
    check('its seat count follows the layout',
        (int) $yRow['total_seats'] === count(Seats::seatIds($otherCoach, 'sharing')),
        'stored ' . $yRow['total_seats']);
    check('its seat map is the other layout, not the route one',
        Seats::availabilityForSchedule($yid, 'sharing')['total'] === count(Seats::seatIds($otherCoach, 'sharing')));
    check('the DAILY bus map is untouched',
        Seats::availability($rid, $D, 'sharing')['total'] === count(Seats::seatIds($routeCoach, 'sharing')));

    check('naming the route own coach stores no override',
        ScheduleMaker::coachInput($routeCoach, $routeCoach) === null);
    check('a blank coach means "same as the route"', ScheduleMaker::coachInput('', $routeCoach) === null);
    throws('a nonsense coach is refused', fn() => ScheduleMaker::coachInput('luxury', $routeCoach), 'seater or sleeper');

    // A seat that exists on the other layout but not on the route's one, or
    // vice versa, must be judged against THIS departure's coach.
    $otherIds = Seats::seatIds($otherCoach, 'sharing');
    $routeIds = Seats::seatIds($routeCoach, 'sharing');
    $onlyOther = array_values(array_diff($otherIds, $routeIds));
    $onlyRoute = array_values(array_diff($routeIds, $otherIds));
    if ($onlyRoute !== []) {
        throws('a seat that does not exist on this coach is refused',
            fn() => Seats::assertAvailable($yid, [$onlyRoute[0]], 'test-token', false, 'sharing'));
    } else {
        check('(no route-only seat to test against)', true);
    }

    // Pick a seat that is genuinely SELLABLE on this coach — the first id of a
    // layout is often a staff / emergency berth, which no customer may book.
    $avYy      = Seats::availabilityForSchedule($yid, $otherCoach === 'sleeper' ? 'sharing' : 'seater');
    $sellable  = $avYy['available'];
    $preferred = array_values(array_intersect($sellable, $onlyOther));
    $bookSeat  = $preferred !== [] ? $preferred[0] : ($sellable[0] ?? $otherIds[0]);
    $by = BookingService::create([
        'routeId' => $rid, 'travelDate' => $D, 'scheduleId' => $yid, 'seats' => [$bookSeat],
        // Female: the first free seat of a layout is often a women-only one,
        // and that rule is itself proof the departure's own coach is in force.
        'passengers' => [['name' => 'Layout Pax', 'age' => 31, 'gender' => 'Female']],
        'contact' => ['phone' => PHONE], 'bookingMode' => $otherCoach === 'sleeper' ? 'sharing' : null,
        'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '', 'referralCode' => '',
    ]);
    check('a seat on that layout books on the extra bus',
        (int) Database::scalar('SELECT schedule_id FROM booking_legs WHERE booking_id = :b LIMIT 1', ['b' => (int) $by['id']], 0) === $yid);
    check('the daily bus does NOT show that seat taken',
        !in_array($bookSeat, Seats::availability($rid, $D, 'sharing')['booked'], true));
} catch (Throwable $e) {
    check('no exception: ' . $e->getMessage(), false, $e->getFile() . ':' . $e->getLine());
} finally {
    beAdmin(null);
    cleanup([$aId, $bId], $rid, $D);
    Settings::set('daily_service_on', $serviceWas ? '1' : '0', 'bool', 'booking', true);
    Database::run('DELETE FROM rate_limits', []);
}

echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
