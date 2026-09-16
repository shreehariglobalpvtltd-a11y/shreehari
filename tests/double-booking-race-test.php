<?php
/**
 * DOUBLE-BOOKING RACE — master prompt §5 / §40
 *
 * The one test the whole system rests on: one seat may have exactly one
 * active booking, no matter how many people claim it at the same instant.
 *
 * This does NOT simulate concurrency with a loop. It launches real, separate
 * OS processes, each with its own PHP interpreter and its own database
 * connection, and holds them at a wall-clock barrier so they all dive at the
 * same seat within the same few milliseconds. That is the only way to
 * exercise InnoDB's UNIQUE(schedule_id, seat_no) the way two customers on
 * two phones actually would.
 *
 * Scenarios:
 *   A  6 customers, one seat, schedule already exists
 *   B  6 customers, one seat, schedule does NOT exist yet
 *      (also proves UNIQUE(route_id, travel_date) stops duplicate schedules)
 *   C  customer vs. counter AGENT racing for the same berth
 *   D  6 visitors racing for the same temporary hold (seat_locks)
 *
 *   php -c .claude/php-dev.ini tests/double-booking-race-test.php
 *
 * Throwaway rows on far-future dates only; cleans up after itself. CLI only.
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

const PHONE_PREFIX = '91000088';   // + 2 digits of worker index

/* =====================================================================
 *  WORKER MODE — one racing customer. Prints exactly one JSON line.
 * ===================================================================== */
if (($argv[1] ?? '') === '--worker') {
    $mode    = (string) ($argv[2] ?? 'create');
    $routeId = (int)    ($argv[3] ?? 0);
    $date    = (string) ($argv[4] ?? '');
    $seat    = (string) ($argv[5] ?? '');
    $startAt = (float)  ($argv[6] ?? 0);
    $ix      = (int)    ($argv[7] ?? 0);

    // Barrier: spin (not sleep) over the last stretch so every worker leaves
    // the gate inside the same millisecond or two.
    while (microtime(true) < $startAt) { /* spin */ }

    try {
        if ($mode === 'lock') {
            $schedule = Seats::schedule($routeId, $date);
            // Each worker needs its OWN lock token; they are separate visitors.
            $token  = 'racetok' . str_pad((string) $ix, 4, '0', STR_PAD_LEFT);
            $result = Seats::lock((int) $schedule['id'], [$seat], $token);
            echo json_encode(['ok' => $result['ok'], 'who' => $ix,
                              'error' => $result['ok'] ? '' : 'lost the hold']) . "\n";
            exit(0);
        }

        if ($mode === 'agent') {
            $route    = Database::fetch('SELECT * FROM routes WHERE id = :i', ['i' => $routeId]);
            $schedule = Seats::schedule($routeId, $date);
            $adminId  = (int) Database::scalar(
                "SELECT id FROM admins WHERE is_active=1 ORDER BY (role='superadmin') DESC, id ASC LIMIT 1", [], 0);

            $b = BookingService::counterSale($route, (int) $schedule['id'], $date, [$seat], [
                'name'   => 'Race Agent ' . $ix,
                'phone'  => PHONE_PREFIX . str_pad((string) $ix, 2, '0', STR_PAD_LEFT),
                'gender' => 'Male',
                'paymentMethod' => 'cash',
            ], $adminId, 'counter');

            echo json_encode(['ok' => true, 'who' => $ix, 'pnr' => $b['pnr']]) . "\n";
            exit(0);
        }

        $b = BookingService::create([
            'routeId'       => $routeId,
            'travelDate'    => $date,
            'seats'         => [$seat],
            'passengers'    => [['name' => 'Race Customer ' . $ix, 'age' => 30, 'gender' => 'Male']],
            'contact'       => ['phone' => PHONE_PREFIX . str_pad((string) $ix, 2, '0', STR_PAD_LEFT)],
            'bookingMode'   => 'sharing',
            'paymentMethod' => 'upi',
            'isCod'         => false,
            'boarding'      => '',
        ]);

        echo json_encode(['ok' => true, 'who' => $ix, 'pnr' => $b['pnr']]) . "\n";
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'who' => $ix, 'error' => $e->getMessage()]) . "\n";
    }
    exit(0);
}

/* =====================================================================
 *  ORCHESTRATOR
 * ===================================================================== */
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/** Launch $n workers that all fire at the same wall-clock instant. */
function race(int $n, string $mode, int $routeId, string $date, string $seat): array
{
    $ini     = php_ini_loaded_file();
    $startAt = microtime(true) + 1.2;      // enough for every child to boot
    $procs   = [];
    $pipes   = [];

    for ($i = 1; $i <= $n; $i++) {
        $cmd = escapeshellarg(PHP_BINARY)
             . ($ini ? ' -c ' . escapeshellarg($ini) : '')
             . ' ' . escapeshellarg(__FILE__)
             . ' --worker ' . escapeshellarg($mode)
             . ' ' . $routeId . ' ' . escapeshellarg($date) . ' ' . escapeshellarg($seat)
             . ' ' . sprintf('%.6f', $startAt) . ' ' . $i;

        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
        if (is_resource($p)) { $procs[$i] = $p; $pipes[$i] = $pipe; }
    }

    $out = [];
    foreach ($procs as $i => $p) {
        $stdout = stream_get_contents($pipes[$i][1]);
        $stderr = stream_get_contents($pipes[$i][2]);
        fclose($pipes[$i][1]); fclose($pipes[$i][2]);
        proc_close($p);

        $line = trim((string) strrchr("\n" . trim($stdout), "\n"));
        $j    = json_decode($line, true);
        if (!is_array($j)) {
            $j = ['ok' => false, 'who' => $i, 'error' => 'no JSON: ' . trim($stdout . ' ' . $stderr)];
        }
        $out[] = $j;
    }
    return $out;
}

function wipe(string ...$dates): void {
    foreach (Database::fetchAll(
        "SELECT id FROM bookings WHERE contact_phone LIKE '" . PHONE_PREFIX . "%'") as $r) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    foreach ($dates as $d) {
        Database::delete('booking_legs', 'travel_date = :d', ['d' => $d]);
        foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $d]) as $s) {
            Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
        }
        Database::delete('schedules', 'travel_date = :d', ['d' => $d]);
    }
}

function seatRowCount(int $routeId, string $date, string $seat): int
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM booking_seats bs
           JOIN schedules s ON s.id = bs.schedule_id
          WHERE s.route_id = :r AND s.travel_date = :d AND bs.seat_no = :seat',
        ['r' => $routeId, 'd' => $date, 'seat' => $seat],
        0
    );
}

// Dates within the live booking window so BookingService::create() accepts them.
// Use from+20..23 so they land well inside the 30-day horizon.
$w = bookingWindow(); $base = addDaysISO($w["from"], 20);
$D1 = $base; $D2 = addDaysISO($base, 1); $D3 = addDaysISO($base, 2); $D4 = addDaysISO($base, 3);

echo "\n=== DOUBLE-BOOKING RACE — one seat, many simultaneous buyers ===\n\n";
wipe($D1, $D2, $D3, $D4);

$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
if ($route === null) { echo "  no active sleeper route — cannot run\n"; exit(1); }
$routeId = (int) $route['id'];
echo "  route: {$route['route_code']} {$route['from_city']} -> {$route['to_city']}\n";
echo "  racing 6 real OS processes per scenario\n\n";

try {
    /* ---- A: schedule already exists --------------------------------- */
    echo "-- A. six customers, one berth (L30), schedule pre-created --\n";
    Seats::schedule($routeId, $D1);
    $res = race(6, 'create', $routeId, $D1, 'L30');
    $won = array_values(array_filter($res, fn($r) => !empty($r['ok'])));
    $lost = array_values(array_filter($res, fn($r) => empty($r['ok'])));

    check('exactly ONE customer succeeded', count($won) === 1,
          count($won) . ' won, ' . count($lost) . ' refused');
    check('the database holds exactly ONE booking for L30', seatRowCount($routeId, $D1, 'L30') === 1,
          'booking_seats rows = ' . seatRowCount($routeId, $D1, 'L30'));
    if ($lost) {
        echo "     loser message: \"" . $lost[0]['error'] . "\"\n";
        check('losers get a human message, not a database error',
              stripos($lost[0]['error'], 'SQLSTATE') === false
              && stripos($lost[0]['error'], 'Integrity constraint') === false);
    }

    /* ---- B: schedule does NOT exist yet ------------------------------ */
    echo "\n-- B. same race, but the schedule row does not exist yet --\n";
    $res = race(6, 'create', $routeId, $D2, 'L31');
    $won = array_values(array_filter($res, fn($r) => !empty($r['ok'])));
    check('exactly ONE customer succeeded', count($won) === 1,
          count($won) . ' won, ' . (6 - count($won)) . ' refused');
    check('the database holds exactly ONE booking for L31', seatRowCount($routeId, $D2, 'L31') === 1);
    $scheds = (int) Database::scalar(
        'SELECT COUNT(*) FROM schedules WHERE route_id = :r AND travel_date = :d',
        ['r' => $routeId, 'd' => $D2], 0);
    check('exactly ONE schedule was created for that date', $scheds === 1, "schedules = $scheds");

    /* ---- C: customer vs counter agent -------------------------------- */
    echo "\n-- C. three customers vs three counter AGENTS, same berth (L32) --\n";
    Seats::schedule($routeId, $D3);
    $ini = php_ini_loaded_file();
    $startAt = microtime(true) + 1.2;
    $procs = []; $pipes = [];
    for ($i = 1; $i <= 6; $i++) {
        $mode = ($i % 2 === 0) ? 'agent' : 'create';
        $cmd  = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '')
              . ' ' . escapeshellarg(__FILE__) . ' --worker ' . $mode . ' ' . $routeId
              . ' ' . escapeshellarg($D3) . ' L32 ' . sprintf('%.6f', $startAt) . ' ' . (20 + $i);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
        if (is_resource($p)) { $procs[$i] = $p; $pipes[$i] = $pipe; }
    }
    $mixed = [];
    foreach ($procs as $i => $p) {
        $o = stream_get_contents($pipes[$i][1]);
        fclose($pipes[$i][1]); fclose($pipes[$i][2]); proc_close($p);
        $j = json_decode(trim((string) strrchr("\n" . trim($o), "\n")), true);
        $mixed[] = is_array($j) ? $j : ['ok' => false, 'error' => trim($o)];
    }
    $wonMixed = array_values(array_filter($mixed, fn($r) => !empty($r['ok'])));
    check('exactly ONE of customer-or-agent got the berth', count($wonMixed) === 1,
          count($wonMixed) . ' won');
    check('the database holds exactly ONE booking for L32', seatRowCount($routeId, $D3, 'L32') === 1);

    /* ---- D: temporary hold race ------------------------------------- */
    echo "\n-- D. six visitors racing for the same temporary hold (L33) --\n";
    Seats::schedule($routeId, $D4);
    $res = race(6, 'lock', $routeId, $D4, 'L33');
    $wonLock = array_values(array_filter($res, fn($r) => !empty($r['ok'])));
    check('exactly ONE visitor holds the seat', count($wonLock) === 1, count($wonLock) . ' holders');

    $sid = (int) Database::scalar(
        'SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d', ['r' => $routeId, 'd' => $D4], 0);
    $lockRows = (int) Database::scalar(
        'SELECT COUNT(*) FROM seat_locks WHERE schedule_id = :s AND seat_no = :seat',
        ['s' => $sid, 'seat' => 'L33'], 0);
    check('seat_locks holds exactly ONE row for that berth', $lockRows === 1, "rows = $lockRows");

} catch (Throwable $e) {
    $FAIL++;
    echo "  \033[31mERROR\033[0m  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}

wipe($D1, $D2, $D3, $D4);
echo "\n----------------------------------------\n";
echo "PASSED: $PASS   FAILED: $FAIL\n\n";
exit($FAIL === 0 ? 0 : 1);
