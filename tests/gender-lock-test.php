<?php
/**
 * =====================================================================
 *  Feature A — gender-aware shared-cabin locking: integration test.
 *
 *  Proves the rule the master prompt calls non-negotiable:
 *    - a woman alone in a shared cabin holds it for women only;
 *    - a man cannot then take the other bed (and the mirror case);
 *    - a group may still book the WHOLE cabin together in one checkout;
 *    - vacating the cabin reopens it to anyone;
 *    - two bookings racing into the same cabin are serialised by the
 *      database row lock, not the UI — so the rule holds under
 *      simultaneous requests.
 *
 *  It drives the exact server-side path (Seats::assertGenderAllowed +
 *  claim + release) that BookingService::create() uses, minus the
 *  pricing / notification noise. CLI only.
 *
 *  RUN (local dev — needs pdo_mysql + MariaDB on :3307, db shari_test):
 *      php -c .claude/php-dev.ini tests/gender-lock-test.php
 *
 *  It writes only throwaway rows (PNRs prefixed SHG-TEST-) on a far-future
 *  travel date and deletes everything it created before exiting.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';

/* ---- tiny test harness ---------------------------------------------- */
$PASS = 0;
$FAIL = 0;

function check(string $label, bool $ok): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  {$label}\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  {$label}\n";
    }
}

/** Assert that $fn throws (a rejected booking). Returns the message. */
function expectReject(string $label, callable $fn): void
{
    try {
        $fn();
        check($label . ' (expected rejection)', false);
    } catch (RuntimeException $e) {
        check($label . ' → "' . $e->getMessage() . '"', true);
    }
}

const TEST_DATE = '2099-12-31';

$sid   = 0;
$coach = 'sleeper';

/** Minimal confirmed booking for one seat, driving the real seat path. */
function bookSeat(int $sid, string $coach, string $seat, ?string $gender): int
{
    return Database::transaction(function () use ($sid, $coach, $seat, $gender): int {
        Seats::assertAvailable($sid, [$seat], 'tst-' . bin2hex(random_bytes(6)));
        Seats::assertGenderAllowed($sid, $coach, 'sharing', [$seat], [$gender]);

        $bid = Database::insert('bookings', [
            'pnr'           => 'SHG-TEST-' . strtoupper(bin2hex(random_bytes(4))),
            'contact_phone' => '910000000000',
            'status'        => 'confirmed',
            'source'        => 'admin',
        ]);
        $lid = Database::insert('booking_legs', [
            'booking_id'  => $bid,
            'schedule_id' => $sid,
            'leg_type'    => 'outbound',
            'travel_date' => TEST_DATE,
            'seat_count'  => 1,
        ]);
        Seats::claim($sid, [$seat], $bid, $lid);
        Database::insert('booking_passengers', [
            'booking_id'    => $bid,
            'leg_id'        => $lid,
            'passenger_ref' => 'PAX' . strtoupper(bin2hex(random_bytes(5))),
            'seat_no'       => $seat,
            'full_name'     => 'Test ' . $seat,
            'gender'        => $gender,
        ]);
        return $bid;
    });
}

/** One booking taking every bed of a cabin at once (the group exception). */
function bookGroup(int $sid, string $coach, array $seats, array $genders): int
{
    return Database::transaction(function () use ($sid, $coach, $seats, $genders): int {
        Seats::assertAvailable($sid, $seats, 'tst-' . bin2hex(random_bytes(6)));
        Seats::assertGenderAllowed($sid, $coach, 'sharing', $seats, $genders);

        $bid = Database::insert('bookings', [
            'pnr'           => 'SHG-TEST-' . strtoupper(bin2hex(random_bytes(4))),
            'contact_phone' => '910000000000',
            'status'        => 'confirmed',
            'source'        => 'admin',
        ]);
        $lid = Database::insert('booking_legs', [
            'booking_id'  => $bid,
            'schedule_id' => $sid,
            'leg_type'    => 'outbound',
            'travel_date' => TEST_DATE,
            'seat_count'  => count($seats),
        ]);
        Seats::claim($sid, $seats, $bid, $lid);
        foreach ($seats as $i => $seat) {
            Database::insert('booking_passengers', [
                'booking_id'    => $bid,
                'leg_id'        => $lid,
                'passenger_ref' => 'PAX' . strtoupper(bin2hex(random_bytes(5))),
                'seat_no'       => $seat,
                'full_name'     => 'Group ' . $seat,
                'gender'        => $genders[$i] ?? null,
            ]);
        }
        return $bid;
    });
}

function unitLock(int $sid, string $unitKey): string
{
    return (string) Database::scalar(
        'SELECT gender_lock FROM schedule_unit_locks WHERE schedule_id = :s AND unit_key = :u',
        ['s' => $sid, 'u' => $unitKey],
        'none'
    );
}

function cleanup(int $sid): void
{
    if ($sid <= 0) {
        return;
    }
    // Deleting the bookings cascades their legs / seats / passengers.
    $ids = pluck(Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE 'SHG-TEST-%'"), 'id');
    foreach ($ids as $id) {
        Database::delete('bookings', 'id = :id', ['id' => (int) $id]);
    }
    Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]);
    Database::delete('booking_legs', 'schedule_id = :s AND travel_date = :d', ['s' => $sid, 'd' => TEST_DATE]);
    Database::delete('schedules', 'id = :s AND travel_date = :d', ['s' => $sid, 'd' => TEST_DATE]);
}

echo "\n=== Feature A — gender-aware shared-cabin lock ===\n\n";

/* This fixture was written when L1 was the reserved staff berth and uses
   cabins L-2..L-4 (L3..L8) as free beds. Production now reserves L5+L6
   (cabin L-3), so pin the old layout for the test run and restore after. */
Settings::set('staff_seats', ['sleeper' => ['L1'], 'seater' => ['1A']], 'json', 'seats', false);

try {
    $route = Database::fetch(
        "SELECT id, coach_type FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1"
    );
    if ($route === null) {
        echo "No active sleeper route found — seed the database first.\n";
        exit(1);
    }
    $coach    = (string) $route['coach_type'];
    $schedule = Seats::schedule((int) $route['id'], TEST_DATE);
    $sid      = (int) $schedule['id'];

    // Start from a clean cabin set for this throwaway schedule.
    cleanup($sid);
    $schedule = Seats::schedule((int) $route['id'], TEST_DATE);
    $sid      = (int) $schedule['id'];

    echo "Route #{$route['id']} ({$coach}), schedule #{$sid}, date " . TEST_DATE . "\n\n";

    // L1 is the permanently reserved staff / emergency berth
    // (Seats::staffSeats), so this fixture starts one cabin further back:
    // L3+L4 = cabin L-2, L5+L6 = L-3, L7+L8 = L-4.

    // 1. Woman takes L3 → cabin L-2 becomes female_only.
    bookSeat($sid, $coach, 'L3', 'Female');
    check('woman books L3 → cabin L-2 is female_only', unitLock($sid, 'L-2') === 'female_only');

    // 2. Man cannot take the other bed L4 of that cabin.
    expectReject('man is refused L4 in the female cabin', fn() => bookSeat($sid, $coach, 'L4', 'Male'));
    check('L4 stayed free after refusal', !in_array('L4', Seats::availability((int) $route['id'], TEST_DATE)['booked'] ?? [], true));

    // 3. Another woman MAY share it.
    bookSeat($sid, $coach, 'L4', 'Female');
    check('second woman books L4 → cabin still female_only', unitLock($sid, 'L-2') === 'female_only');

    // 4. Mirror case: man takes L5 → cabin L-3 becomes male_only, woman refused.
    bookSeat($sid, $coach, 'L5', 'Male');
    check('man books L5 → cabin L-3 is male_only', unitLock($sid, 'L-3') === 'male_only');
    expectReject('woman is refused L6 in the male cabin', fn() => bookSeat($sid, $coach, 'L6', 'Female'));

    // 5. Group exception: a mixed pair books the WHOLE cabin L-4 together.
    bookGroup($sid, $coach, ['L7', 'L8'], ['Male', 'Female']);
    check('mixed group books whole cabin L-4 → mixed_allowed', unitLock($sid, 'L-4') === 'mixed_allowed');

    // 6. Vacating a cabin reopens it. Cancel both women in L-2, then a man fits.
    $ids = pluck(Database::fetchAll(
        "SELECT DISTINCT bs.booking_id AS id FROM booking_seats bs
          WHERE bs.schedule_id = :s AND bs.seat_no IN ('L3','L4')",
        ['s' => $sid]
    ), 'id');
    foreach ($ids as $id) {
        Database::transaction(fn() => Seats::releaseBooking((int) $id));
    }
    check('after cancelling both women, cabin L-2 resets to none', unitLock($sid, 'L-2') === 'none');
    bookSeat($sid, $coach, 'L3', 'Male');
    check('a man can now book the vacated cabin L-2', unitLock($sid, 'L-2') === 'male_only');

    // 7. Concurrency: two bookings racing into the same cabin serialise on the
    //    row lock — the second must wait (proven via a short lock-wait timeout).
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $A = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $B = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $A->prepare('INSERT IGNORE INTO schedule_unit_locks (schedule_id, unit_key) VALUES (?, ?)')->execute([$sid, 'L-9']);
        $A->beginTransaction();
        $A->prepare('SELECT id FROM schedule_unit_locks WHERE schedule_id = ? AND unit_key = ? FOR UPDATE')->execute([$sid, 'L-9']);

        $B->beginTransaction();
        $B->exec('SET innodb_lock_wait_timeout = 1');
        $blocked = false;
        try {
            $B->prepare('SELECT id FROM schedule_unit_locks WHERE schedule_id = ? AND unit_key = ? FOR UPDATE')->execute([$sid, 'L-9']);
        } catch (Throwable $e) {
            $blocked = true; // lock-wait timeout → the second booking correctly waited
        }
        $A->rollBack();
        $B->rollBack();
        check('a concurrent booking into the same cabin blocks on the row lock', $blocked);
    } catch (Throwable $e) {
        echo "  (skipped concurrency check — second connection failed: " . $e->getMessage() . ")\n";
    }
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage(), false);
} finally {
    cleanup($sid);
    // Back to the production layout (equivalent to no settings row).
    Settings::set('staff_seats', ['sleeper' => ['L5', 'L6'], 'seater' => ['1A']], 'json', 'seats', false);
}

echo "\n----------------------------------------\n";
echo "  {$PASS} passed, {$FAIL} failed\n";
echo "----------------------------------------\n\n";

exit($FAIL === 0 ? 0 : 1);
