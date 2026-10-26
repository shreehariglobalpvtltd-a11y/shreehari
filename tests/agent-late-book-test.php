<?php
/**
 * Agent 24h late-booking on a DEPARTED bus (Phase 2 — customer-app path).
 * A valid SHG-NNN agent code lets BookingService::create() place a ticket on a
 * bus that has already left, for 24h after its scheduled departure; an
 * anonymous customer (or an invalid code) is still refused. Also pins the
 * Boarding::agentGraceOpen / routeSellableForAgent window. CLI only.
 *   php -c .claude/php-dev.ini tests/agent-late-book-test.php
 * Timestamp-derived dates (robust across midnight). Throwaway rows.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; } }
function throws(string $l, callable $fn): void { try { $fn(); check($l . ' (expected rejection)', false); } catch (Throwable $e) { check($l . ' → "' . $e->getMessage() . '"', true); } }

// Maximally-separated offsets so the three fixtures ALWAYS land on distinct
// calendar days (schedules are UNIQUE per route+date — colliding days would let
// one fixture overwrite another). Departed 2h ago (inside 24h), 40h ago
// (outside), +30h (not departed yet).
$T1  = time() - 2 * 3600;     $D1 = date('Y-m-d', $T1);  $H1 = date('H:i:s', $T1);
$T30 = time() - 40 * 3600;    $D30 = date('Y-m-d', $T30); $H30 = date('H:i:s', $T30);
$TF  = time() + 30 * 3600;    $DF = date('Y-m-d', $TF);  $HF = date('H:i:s', $TF);
$ALL = array_values(array_unique([$D1, $D30, $DF]));
const PHONE = '9100000931';

function cleanupAll(): void {
    global $ALL;
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone = '" . PHONE . "'") as $r) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    foreach ($ALL as $d) {
        foreach (pluck(Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $d]), 'id') as $sid) {
            Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $sid]);
        }
        Database::delete('booking_legs', 'travel_date = :d', ['d' => $d]);
        Database::delete('schedules', 'travel_date = :d', ['d' => $d]);
    }
}

function mkSchedule(int $rid, string $date, string $depOverride, string $status): int {
    $sid = (int) Seats::schedule($rid, $date)['id'];
    Database::update('schedules', ['dep_time_override' => $depOverride, 'status' => $status], 'id = :i', ['i' => $sid]);
    return $sid;
}

function bookReq(int $rid, string $date, string $seat, ?string $refCode): array {
    return [
        'routeId'       => $rid,
        'travelDate'    => $date,
        'seats'         => [$seat],
        'passengers'    => [['name' => 'Late Pax', 'age' => 30, 'gender' => 'Male']],
        'contact'       => ['phone' => PHONE],
        'bookingMode'   => 'sharing',
        'paymentMethod' => 'upi',
        'isCod'         => false,
        'boarding'      => '',
        'referralCode'  => $refCode ?? '',
    ];
}

echo "\n=== Agent 24h late-booking on a departed bus ===\n\n";

Settings::set('staff_seats', ['sleeper' => ['L1'], 'seater' => ['1A']], 'json', 'seats', false);

try {
    $route = Database::fetch("SELECT id FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no sleeper route\n"; exit(1); }
    $rid = (int) $route['id'];

    cleanupAll();

    // A valid, active agent + its SHG code.
    $agentId = (int) Database::scalar("SELECT id FROM admins WHERE role='agent' AND is_active=1 ORDER BY id LIMIT 1", [], 0);
    if ($agentId <= 0) { echo "no active agent to test with\n"; exit(1); }
    if (AgentWallet::agentCodeFor($agentId) === null) {
        $free = AgentWallet::nextFreeAgentCode();
        if ($free !== null) { AgentWallet::setAgentCode($agentId, $free, 0); }
    }
    $code = AgentWallet::agentCodeLabel($agentId);          // e.g. SHG-0001
    check('agent has a resolvable code (' . $code . ')', $code !== '');

    // ---- Boarding window helpers -----------------------------------------
    $sid1  = mkSchedule($rid, $D1,  $H1,  'departed');       // 1h ago
    $sid30 = mkSchedule($rid, $D30, $H30, 'departed');       // 30h ago
    $sidF  = mkSchedule($rid, $DF,  $HF,  'scheduled');      // not departed

    check('agentGraceOpen TRUE for a bus that left 2h ago',  Boarding::agentGraceOpen($rid, $D1) === true);
    check('agentGraceOpen FALSE past the 24h window (40h)',  Boarding::agentGraceOpen($rid, $D30) === false);
    check('agentGraceOpen FALSE before departure (+30h)',     Boarding::agentGraceOpen($rid, $DF) === false);
    check('routeSellableForAgent TRUE inside grace',         Boarding::routeSellableForAgent($rid, $D1) === true);
    /* The property this guards: the agent grace only ever WIDENS what may be
       sold, and the anonymous rule does not consult it at all.

       It used to assert routeSellable($D1) === ($D1 === todayISO()), which
       assumed the whole of today is sellable to anyone. It is not: for
       today, routeSellable() answers 'is any pickup still before its
       cut-off', so after the last pickup closes it is FALSE while the date
       is still today — and the suite went red every evening on code it was
       not testing (11 Sep 2026). Rewritten to the real property, which holds
       at every hour of the day. */
    $anonD1  = Boarding::routeSellable($rid, $D1);
    $graceD1 = Boarding::agentGraceOpen($rid, $D1);
    check('the grace never NARROWS what an anonymous customer could already book',
        !$anonD1 || Boarding::routeSellableForAgent($rid, $D1),
        'anon=' . var_export($anonD1, true) . ' grace=' . var_export($graceD1, true));
    check('an agent may book the departed bus even when the anonymous rule is shut',
        !$graceD1 || Boarding::routeSellableForAgent($rid, $D1) === true);
    check('a PAST date stays shut for everyone but the grace',
        Boarding::routeSellable($rid, $D30) === false);

    // ---- create(): the actual booking gate -------------------------------
    // Anonymous customer on the departed bus → refused.
    throws('anonymous CANNOT book the departed bus', fn() => BookingService::create(bookReq($rid, $D1, 'L9', null)));

    // Invalid agent code → treated as anonymous → refused.
    throws('an INVALID agent code cannot book the departed bus', fn() => BookingService::create(bookReq($rid, $D1, 'L9', 'SHG-9999')));

    // Valid agent code inside 24h → SUCCEEDS, attributed to the agent.
    $res = BookingService::create(bookReq($rid, $D1, 'L9', $code));
    check('valid agent code BOOKS the departed bus (within 24h)', !empty($res['pnr']));
    $soldBy = (int) Database::scalar('SELECT sold_by_admin_id FROM bookings WHERE pnr = :p', ['p' => $res['pnr'] ?? ''], 0);
    check('  booking attributed to the selling agent', $soldBy === $agentId);

    // Valid agent code but the window has passed (30h) → refused.
    throws('agent CANNOT book past the 24h window', fn() => BookingService::create(bookReq($rid, $D30, 'L9', $code)));

    // Cancelled trip inside the window → still refused (grace never resurrects it).
    Database::update('schedules', ['status' => 'cancelled'], 'id = :i', ['i' => $sid1]);
    throws('agent CANNOT book a cancelled trip even inside the window', fn() => BookingService::create(bookReq($rid, $D1, 'L5', $code)));

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanupAll();
    Settings::set('staff_seats', ['sleeper' => ['L5', 'L6'], 'seater' => ['1A']], 'json', 'seats', false);
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
