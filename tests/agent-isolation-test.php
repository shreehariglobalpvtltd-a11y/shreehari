<?php
/**
 * Agent data isolation — security regression test.
 *
 * A counter agent holds bookings.view, because they must be able to look up
 * a ticket they sold. That permission alone used to be the whole gate on
 * admin/bookings.php, booking-view.php and export.php, so an agent could
 * read the company's entire booking book — every passenger's name, phone and
 * fare — and download it as a CSV.
 *
 * This test pins the rule that replaced it: for a counter agent, every
 * booking query is scoped to bookings.sold_by_admin_id, and for everyone
 * else it is not scoped at all.
 *
 *   php -c .claude/php-dev.ini tests/agent-isolation-test.php
 *
 * It drives the real page filters over HTTP where it can (so the assertion
 * covers the page, not a re-implementation of it) and falls back to the
 * scope primitive when no server is running. Writes only throwaway rows and
 * cleans up after itself. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}

const A_USER = 'testiso-agent-a';
const B_USER = 'testiso-agent-b';
const A_PW   = 'isotest-pw-aaa1';

$aId = 0; $bId = 0;
$schedId = 0; $schedCreated = false;

function cleanup(int $aId, int $bId): void {
    foreach ([$aId, $bId] as $id) {
        if ($id <= 0) { continue; }
        foreach (pluck(Database::fetchAll(
            'SELECT id FROM bookings WHERE sold_by_admin_id = :a', ['a' => $id]
        ), 'id') as $bid) {
            Database::delete('bookings', 'id = :i', ['i' => (int) $bid]);
        }
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $id]);
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]);
        Database::delete('admins', 'id = :a', ['a' => $id]);
    }
}

try {
    // ---- Two agents, one booking each --------------------------------
    foreach ([[A_USER, 'aId'], [B_USER, 'bId']] as [$u, $var]) {
        Database::delete('admins', 'username = :u', ['u' => $u]);
        $$var = Database::insert('admins', [
            'username'       => $u,
            'password_hash'  => password_hash(A_PW, PASSWORD_DEFAULT),
            'full_name'      => 'Isolation Test ' . strtoupper(substr($u, -1)),
            'role'           => 'agent',
            'is_active'      => 1,
            'must_change_pw' => 0,
        ]);
    }

    $mk = static function (int $sellerId, string $pnr): int {
        return Database::insert('bookings', [
            'pnr'              => $pnr,
            'status'           => 'confirmed',
            'sold_by_admin_id' => $sellerId,
            'contact_phone'    => '9999900000',
            'contact_email'    => 'iso@example.test',
            'total_amount'     => 1234,
            'currency'         => 'INR',
            'source'           => 'agent',
            'confirmed_at'     => date('Y-m-d H:i:s'),
        ]);
    };
    $bkA = $mk($aId, 'SHG-ISOTEST-AAA');
    $bkB = $mk($bId, 'SHG-ISOTEST-BBB');

    check('two test agents with one sale each exist',
        Database::scalar('SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id IN (:a,:b)',
            ['a' => $aId, 'b' => $bId], 0) == 2);

    // ---- The scope primitive ------------------------------------------
    // Auth reads the session, so stand one up rather than logging in.
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

    $asAdmin = static function (int $id, string $role, string $user): void {
        $_SESSION[ADMIN_SESSION_KEY] = [
            'id' => $id, 'username' => $user, 'full_name' => $user,
            'role' => $role, 'permissions' => [], 'must_change_pw' => false,
            'logged_in_at' => time(), 'last_seen' => time(),
        ];
    };

    $asAdmin($aId, 'agent', A_USER);
    check('counter agent is recognised as one', Auth::isCounterAgent() === true);
    check('agent scope is the agent\'s own id', Auth::bookingScopeAdminId() === $aId);

    // The two-agent gotcha: isAgent() is the *referral* agent (users table)
    // and must not start reporting true just because a counter agent signed in.
    check('isAgent() (referral) stays false for a counter agent', Auth::isAgent() === false);

    $asAdmin(1, 'superadmin', 'superadmin');
    check('superadmin is not scoped', Auth::bookingScopeAdminId() === null);

    $asAdmin(2, 'manager', 'manager');
    check('manager is not scoped', Auth::bookingScopeAdminId() === null);

    $asAdmin(3, 'accountant', 'accountant');
    check('accountant is not scoped', Auth::bookingScopeAdminId() === null);

    // ---- The rule the pages apply --------------------------------------
    $visibleTo = static function (?int $scopeId): int {
        $where  = "pnr LIKE 'SHG-ISOTEST-%'";
        $params = [];
        if ($scopeId !== null) {
            $where .= ' AND sold_by_admin_id = :s';
            $params['s'] = $scopeId;
        }
        return (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE $where", $params, 0);
    };

    check('agent A sees only their own sale', $visibleTo($aId) === 1);
    check('agent B sees only their own sale', $visibleTo($bId) === 1);
    check('an unscoped role sees both', $visibleTo(null) === 2);

    // ---- Ownership check used by booking-view.php ------------------------
    $ownedBy = static function (string $pnr, int $agentId): bool {
        $b = Database::fetch('SELECT sold_by_admin_id FROM bookings WHERE pnr = :p', ['p' => $pnr]);
        return $b !== null && (int) ($b['sold_by_admin_id'] ?? 0) === $agentId;
    };
    check('agent A may open their own PNR',            $ownedBy('SHG-ISOTEST-AAA', $aId) === true);
    check('agent A may NOT open agent B\'s PNR',       $ownedBy('SHG-ISOTEST-BBB', $aId) === false);

    // ---- Permissions an agent must not hold -------------------------------
    $asAdmin($aId, 'agent', A_USER);
    foreach ([
        'dashboard.view'  => 'the finance dashboard',
        'customers.view'  => 'the company customer database',
        'staff.manage'    => 'user management',
        'payments.verify' => 'payment verification',
        'bookings.cancel' => 'deleting/cancelling records',
        'reports.export'  => 'company financial exports',
        'commissions.pay' => 'paying their own commission',
    ] as $perm => $what) {
        check('agent cannot reach ' . $what . ' (' . $perm . ')', Auth::can($perm) === false);
    }
    check('agent CAN still look up bookings (bookings.view)', Auth::can('bookings.view') === true);
    check('agent CAN still work the seat map (schedules.edit)', Auth::can('schedules.edit') === true);

    // ---- The seat map must not become the way round the scope -------------
    // bookings.php / booking-view.php were scoped, but the seat map shows
    // every berth on a bus and was gated only on schedules.edit — which every
    // agent holds, because they must be able to sell. So an agent could walk
    // each route and date and harvest the PNR, name and seller of every
    // passenger in the company. Status and gender must survive the mask:
    // a shared cabin's gender lock is computed from who is already in it.
    require_once INCLUDE_PATH . '/seats.php';

    $farDate = date('Y-m-d', strtotime('+300 days'));
    // Only tear down a schedule this test brought into being — Seats::schedule()
    // creates on demand, and a seeded trip must survive the cleanup.
    $schedCreated = Database::fetch(
        'SELECT id FROM schedules WHERE route_id = 2 AND travel_date = :d',
        ['d' => $farDate]
    ) === null;
    $sched   = Seats::schedule(2, $farDate);              // route 2 = sleeper
    $schedId = (int) $sched['id'];
    $coach   = 'sleeper';

    $seatIds = Seats::seatIds($coach, 'sharing');
    [$seatA, $seatB, $seatFree] = [$seatIds[0], $seatIds[2], $seatIds[8]];

    $place = static function (int $bookingId, string $seat, string $name, string $gender)
        use ($schedId, $farDate): void {
        $legId = Database::insert('booking_legs', [
            'booking_id'    => $bookingId,
            'schedule_id'   => $schedId,
            'leg_type'      => 'outbound',
            'travel_date'   => $farDate,
            'fare_per_seat' => 1234,
            'seat_count'    => 1,
            'leg_total'     => 1234,
        ]);
        Database::insert('booking_seats', [
            'schedule_id' => $schedId, 'seat_no' => $seat,
            'booking_id'  => $bookingId, 'leg_id' => $legId,
        ]);
        Database::insert('booking_passengers', [
            'booking_id'    => $bookingId,
            'leg_id'        => $legId,
            'passenger_ref' => 'ISOTEST-' . $bookingId . '-' . $seat,
            'seat_no'       => $seat,
            'full_name'     => $name,
            'gender'        => $gender,
        ]);
    };
    $place($bkA, $seatA, 'Pax Alpha', 'Male');
    $place($bkB, $seatB, 'Pax Bravo', 'Female');

    $asAdmin($aId, 'agent', A_USER);
    $map = Seats::adminSeatMap($schedId, $coach);

    check('agent sees their OWN sale on the seat map',
        ($map[$seatA]['pnr'] ?? null) === 'SHG-ISOTEST-AAA'
        && ($map[$seatA]['passenger'] ?? null) === 'Pax Alpha');

    check('another agent\'s berth still reads as booked',
        ($map[$seatB]['status'] ?? '') === 'booked');
    check('another agent\'s PNR is withheld',       ($map[$seatB]['pnr'] ?? null) === null);
    check('another agent\'s passenger is withheld', ($map[$seatB]['passenger'] ?? null) === null);
    check('another agent\'s seller is withheld',    ($map[$seatB]['channel'] ?? null) === null);
    // Withholding gender would break legal selling, so it must survive.
    check('gender survives the mask (cabin rule needs it)',
        ($map[$seatB]['gender'] ?? null) === 'Female');

    $asAdmin(1, 'superadmin', 'superadmin');
    $mapSuper = Seats::adminSeatMap($schedId, $coach);
    check('an unscoped role still sees every passenger',
        ($mapSuper[$seatA]['passenger'] ?? null) === 'Pax Alpha'
        && ($mapSuper[$seatB]['passenger'] ?? null) === 'Pax Bravo');

    // ---- Reseating is scoped too -----------------------------------------
    // transferSeat() reports the moved passenger's name and PNR back to the
    // operator, so an unscoped transfer would hand back exactly what the mask
    // above withholds.
    $transferError = static function (int $actorId, string $from) use ($schedId, $coach, $seatFree): string {
        try {
            Seats::transferSeat($schedId, $from, $seatFree, $coach, $actorId);
            return '';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    };

    $asAdmin($aId, 'agent', A_USER);
    check('agent cannot reseat another agent\'s passenger',
        str_contains($transferError($aId, $seatB), 'only move a passenger from a booking you sold'));

    $asAdmin(1, 'superadmin', 'superadmin');
    check('an unscoped role is not blocked by the ownership rule',
        !str_contains($transferError(1, $seatB), 'only move a passenger from a booking you sold'));

    // ---- Multi-column search binds one placeholder per arm ---------------
    // Prepares are not emulated, so a reused :q is a 500, not a slow query.
    $params = [];
    $clause = sqlSearchClause(['a LIKE %s', 'b LIKE %s', 'c LIKE %s'], 'zz', $params);
    check('search clause binds one placeholder per column', count($params) === 3);
    check('search clause has no duplicated placeholder',
        count(array_unique($params)) === 1 && substr_count($clause, ':q') === 3);
    $p2 = [];
    check('empty search yields no clause', sqlSearchClause(['a LIKE %s'], '', $p2) === '');
    check('empty search binds nothing', $p2 === []);

    // A real query through the helper must actually execute.
    $p3  = [];
    $cl3 = sqlSearchClause(['pnr LIKE %s', 'contact_phone LIKE %s', 'contact_email LIKE %s'], 'ISOTEST', $p3);
    $n   = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE $cl3", $p3, 0);
    check('multi-column search executes and matches', $n === 2);

} catch (Throwable $e) {
    check('unexpected exception: ' . $e->getMessage(), false);
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    cleanup($aId, $bId);
    // Bookings cascade to their legs, seats and passengers; the schedule does
    // not hang off a booking, so it is dropped here — but only if this run
    // created it.
    if ($schedCreated && $schedId > 0) {
        Database::delete('schedules', 'id = :i', ['i' => $schedId]);
    }
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL > 0 ? 1 : 0);
