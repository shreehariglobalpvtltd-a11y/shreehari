<?php
/**
 * =====================================================================
 *  u13-S1-cabin-test.php — 17 Nov 2026 (S1-cabin, owner "as your wish"):
 *  the partner berth of a women-reserved berth in a SHARING cabin
 *  (sleeper L4 beside women's L3, cabin L-2) is sold to a woman only,
 *  except inside one booking that also puts a woman on the women's berth.
 *
 *   - seat map: L4 rides the `female` list + womenPair['L4'] = ['L3']
 *   - customer (BookingService::create) Male on L4 alone  -> refused
 *   - desk / Quick Ticket (counterSale) Male on L4 alone  -> refused
 *   - Female on L4 alone                                  -> allowed
 *   - couple L3(F) + L4(M) in one booking                 -> allowed
 *   - L4(M) + L3(M)                                       -> refused BY THE PARTNER RULE
 *   - once L3 is sold, L4 is no longer guarded
 *   - seat transfer of a man off L3 onto L4               -> refused
 *   - AutoBerth never gives L4 to a man; checkManual lets the couple pass
 *   - private mode / seater untouched
 *
 *  18 Nov 2026 refix:
 *   - re-approval / resubmitted proof re-takes a booking's OWN L4 even with
 *     L3 free (existing bookings are not touched by the rule)
 *   - a transfer or per-seat cancel that FREES L3 while a man stays on L4
 *     is refused (couple's woman moved / cancelled, office's L3 man moved);
 *     doing it in the right order (the man first) goes through
 *   - cleanup removes every row the bookings leave (message / delivery logs,
 *     customer-master rows, audit, ticket files)
 *
 *  Run: php -c .claude/php-dev.ini tests/u13-S1-cabin-test.php
 *  Throw-away bookings on a far-future date no other suite uses; everything
 *  is removed.
 * =====================================================================
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
require_once INCLUDE_PATH . '/autoberth.php';

const S1_PHONE = '910000913';   // throw-away booking marker (customer + desk): 910000913 + one digit

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function attempt(callable $fn): ?string { try { $fn(); return null; } catch (Throwable $e) { return $e->getMessage(); } }

echo "\n=== S1-cabin: the partner of a women's berth is a women's berth ===\n\n";

$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
if ($route === null) { echo "  \033[33mSKIP\033[0m  no active sleeper route\n\n0 passed, 0 failed\n"; exit(0); }
$routeId = (int) $route['id'];

/* A date of our own: far out, inside the booking window, with no schedule row
   on this route yet - other suites (run in parallel on the same DB) pick near
   dates and delete their own schedule rows, which used to pull ours away
   mid-run ("That departure no longer exists"). */
$w = bookingWindow();
$D = null;
$horizon = (int) round((strtotime($w['to']) - strtotime($w['from'])) / 86400);
for ($off = min(613, $horizon - 1); $off >= 8 && $D === null; $off--) {
    $cand = addDaysISO($w['from'], $off);
    if (!Database::exists('SELECT 1 FROM schedules WHERE route_id = :r AND travel_date = :d', ['r' => $routeId, 'd' => $cand])) {
        $D = $cand;
    }
}
$D ??= addDaysISO($w['from'], 23);

/* Every table with a booking_id column (children before parents is not
   knowable from here, so two passes let a FK-ordered delete finish). */
$bookingTables = array_map(static fn($r): string => (string) $r['t'], Database::fetchAll(
    "SELECT c.table_name AS t FROM information_schema.columns c
       JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
      WHERE c.table_schema = DATABASE() AND c.column_name = 'booking_id' AND c.table_name <> 'bookings'"
));
$wipeBookingRows = function (int $id, string $pnr) use ($bookingTables): void {
    for ($pass = 0; $pass < 2; $pass++) {
        foreach ($bookingTables as $t) {
            try { Database::query("DELETE FROM `{$t}` WHERE booking_id = :b", ['b' => $id]); } catch (Throwable $e) {}
        }
    }
    try { Database::query("DELETE FROM audit_logs WHERE entity_type = 'booking' AND entity_id IN (:p, :i)", ['p' => $pnr, 'i' => (string) $id]); } catch (Throwable $e) {}
    if ($pnr !== '') {
        try { Database::query('DELETE FROM automation_log WHERE data LIKE :d OR dedupe_key LIKE :k1 OR dedupe_key LIKE :k2',
            ['d' => '%"' . $pnr . '"%', 'k1' => '%:' . $pnr, 'k2' => '%:' . $pnr . ':%']); } catch (Throwable $e) {}
        try { Database::query('DELETE FROM notifications WHERE booking_id IS NULL AND (link LIKE :l1 OR link LIKE :l2)',
            ['l1' => '%pnr=' . $pnr, 'l2' => '%pnr=' . $pnr . '&%']); } catch (Throwable $e) {}
        foreach (glob(dirname(__DIR__) . '/tickets/ticket_' . $pnr . '.*') ?: [] as $f) { @unlink($f); }
    }
};

$cleanup = function () use ($D, $routeId, $wipeBookingRows): void {
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . S1_PHONE . "_'") as $r) {
        $GLOBALS['S1_SEEN'][(int) $r['id']] = true;
        $wipeBookingRows((int) $r['id'], (string) $r['pnr']);
        try { Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]); } catch (Throwable $e) {}
    }
    /* Rows an earlier run left for bookings that are gone (the logs name our
       marker number, so they are ours): their open "watching" delivery rows. */
    foreach (Database::fetchAll("SELECT DISTINCT booking_id FROM message_logs WHERE to_number LIKE '%" . S1_PHONE . "_' AND booking_id IS NOT NULL") as $r) {
        $bid = (int) $r['booking_id'];
        if ($bid > 0 && !Database::exists('SELECT 1 FROM bookings WHERE id = :b', ['b' => $bid])) {
            try { Database::delete('delivery_log', 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
    }
    try { Database::query("DELETE FROM message_logs WHERE to_number LIKE '%" . S1_PHONE . "_'"); } catch (Throwable $e) {}
    try { Database::query("DELETE FROM user_profiles WHERE phone LIKE '%" . S1_PHONE . "_'
                            AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.contact_phone = user_profiles.phone)"); } catch (Throwable $e) {}
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d AND route_id = :r', ['d' => $D, 'r' => $routeId]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
        Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
        Database::delete('seat_blocks', 'schedule_id = :s', ['s' => (int) $s['id']]);
    }
    try { Database::delete('schedules', 'travel_date = :d AND route_id = :r AND NOT EXISTS (SELECT 1 FROM booking_legs bl WHERE bl.schedule_id = schedules.id)', ['d' => $D, 'r' => $routeId]); } catch (Throwable $e) {}
};

/* Another suite's live booking on our date would make the cabin state ours
   to guess - refuse to run instead of failing for the wrong reason. */
$pre = (int) Database::scalar(
    'SELECT COUNT(*) FROM booking_seats bs JOIN schedules s ON s.id = bs.schedule_id
      WHERE s.route_id = :r AND s.travel_date = :d AND bs.released_at IS NULL',
    ['r' => $routeId, 'd' => $D], 0
);
$cleanup();

$req = function (array $seats, array $genders, string $mode, string $suffix) use ($routeId, $D): array {
    $pax = [];
    foreach ($seats as $i => $s) {
        $pax[] = ['seat' => $s, 'name' => 'S1 Test ' . ($i + 1), 'age' => 30, 'gender' => $genders[$i] ?? ''];
    }
    return [
        'routeId' => $routeId, 'travelDate' => $D, 'seats' => $seats, 'passengers' => $pax,
        'contact' => ['phone' => S1_PHONE . $suffix], 'bookingMode' => $mode,
        'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '',
    ];
};
$admin = Database::fetch("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1");
$desk = function (array $seats, array $genders, string $suffix, int $sid) use ($route, $D, $admin): array {
    $pax = [];
    foreach ($seats as $i => $s) {
        $pax[] = ['name' => 'S1 Desk ' . ($i + 1), 'gender' => $genders[$i] ?? ''];
    }
    return BookingService::counterSale($route, $sid, $D, $seats, [
        'name' => 'S1 Desk', 'phone' => S1_PHONE . $suffix, 'gender' => $genders[0] ?? '',
        'passengers' => $pax, 'amount' => 2000.00, 'paymentMethod' => 'cash',
    ], (int) ($admin['id'] ?? 0), 'counter');
};
$bookingOf = static fn(string $suffix): ?array => Database::fetch(
    'SELECT * FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => S1_PHONE . $suffix]);
$seatsOf = static fn(int $bid): array => array_map(static fn($r): string => (string) $r['seat_no'], Database::fetchAll(
    'SELECT seat_no FROM booking_seats WHERE booking_id = :b AND released_at IS NULL ORDER BY seat_no', ['b' => $bid]));
$lockOf = static fn(int $sid, string $unit): string => (string) Database::scalar(
    'SELECT gender_lock FROM schedule_unit_locks WHERE schedule_id = :s AND unit_key = :u', ['s' => $sid, 'u' => $unit], 'none');
$isPartnerMsg = static fn(?string $e): bool => $e !== null && stripos($e, 'shares a cabin with women') !== false;
$isHoleMsg    = static fn(?string $e): bool => $e !== null && stripos($e, 'could no longer be sold to a woman') !== false;

try {
    check('test date ' . $D . ' has no other live booking (clean fixture)', $pre === 0, (string) $pre);

    /* ---- the rule data ------------------------------------------- */
    $map = Seats::womenPartnerMap('sleeper');
    $fem = Seats::femaleSeats('sleeper');
    check('women-reserved sleeper berths are L1-L3', array_values(array_intersect(['L1', 'L2', 'L3'], $fem)) === ['L1', 'L2', 'L3'], implode(',', $fem));
    check('partner map: L4 guards L3', ($map['L4'] ?? null) === ['L3'], json_encode($map));
    check('all-women cabin L-1 has no partner (L1/L2 not in the map)', !isset($map['L1']) && !isset($map['L2']));
    check('seater: 1A-2B pairs are all women -> no partner', Seats::womenPartnerMap('seater') === [], json_encode(Seats::womenPartnerMap('seater')));

    $sid = (int) Seats::schedule($routeId, $D)['id'];
    $av  = Seats::availabilityForSchedule($sid, 'sharing');
    check('seat map: L4 is in the `female` list the pickers paint', in_array('L4', $av['female'], true), implode(',', $av['female']));
    check('seat map: womenPair L4 => [L3]', ($av['womenPair']['L4'] ?? null) === ['L3']);
    check('seat map: L4 is still AVAILABLE (to a woman)', in_array('L4', $av['available'], true));
    $avP = Seats::availabilityForSchedule($sid, 'private');
    check('private map: no partner guard (cabin sold whole)', ($avP['womenPair'] ?? []) === [] && !in_array('L4', $avP['female'], true));

    /* ---- AutoBerth -------------------------------------------------- */
    $only = static fn(array $av, array $seats): array => ['available' => $seats] + $av;
    $p = AutoBerth::pick($only($av, ['L4']), [['gender' => 'Male', 'band' => 'adult']], ['coach' => 'sleeper']);
    check('auto-pick: a man is never given L4 (only L4 free -> no pick)', !$p['ok'] || !in_array('L4', $p['seats'], true), json_encode($p['seats']));
    $p = AutoBerth::pick($only($av, ['L4', 'U10']), [['gender' => 'Male', 'band' => 'adult']], ['coach' => 'sleeper']);
    check('auto-pick: a man gets U10, not L4', $p['ok'] && $p['seats'] === ['U10'], json_encode($p['seats']));
    $p = AutoBerth::pick($av, [['gender' => 'Male', 'band' => 'adult'], ['gender' => 'Male', 'band' => 'adult'], ['gender' => 'Male', 'band' => 'adult']], ['coach' => 'sleeper']);
    check('auto-pick on the full map: 3 men, none on L1-L4', $p['ok'] && array_intersect($p['seats'], ['L1', 'L2', 'L3', 'L4']) === [], json_encode($p['seats']));
    $p = AutoBerth::pick($only($av, ['L4']), [['gender' => 'Female', 'band' => 'adult']], ['coach' => 'sleeper']);
    check('auto-pick: a woman may get L4', $p['ok'] && $p['seats'] === ['L4'], json_encode($p['seats']));
    $e = AutoBerth::checkManual($av, ['L4'], [['gender' => 'Male']], 'sleeper');
    check('hand pick: a man alone on L4 is refused', $e !== '', $e);
    $e = AutoBerth::checkManual($av, ['L3', 'L4'], [['gender' => 'Female'], ['gender' => 'Male']], 'sleeper');
    check('hand pick: couple L3(F)+L4(M) passes', $e === '', $e);
    $e = AutoBerth::checkManual($av, ['L4', 'L3'], [['gender' => 'Male'], ['gender' => 'Male']], 'sleeper');
    check('hand pick: L4(M)+L3(M) is refused', $e !== '', $e);

    /* ---- customer sale path ------------------------------------------- */
    $err = attempt(fn() => BookingService::create($req(['L4'], ['Male'], 'sharing', '1')));
    check('customer: Male on L4 alone is REFUSED by the partner rule', $isPartnerMsg($err), (string) $err);
    $err = attempt(fn() => BookingService::create($req(['L4'], ['Male'], '', '1')));
    check('customer (no mode given): Male on L4 alone is REFUSED by the partner rule', $isPartnerMsg($err), (string) $err);
    /* The old customer-only "Seat L3 is reserved for women" block would refuse
       this too - so assert it is the PARTNER rule that answers (it runs first). */
    $err = attempt(fn() => BookingService::create($req(['L4', 'L3'], ['Male', 'Male'], 'sharing', '2')));
    check('customer: L4(M)+L3(M) is REFUSED by the partner rule (not only the old L3 block)', $isPartnerMsg($err), (string) $err);

    $err = attempt(fn() => BookingService::create($req(['L4'], ['Female'], 'sharing', '3')));
    check('customer: Female on L4 alone goes through', $err === null, (string) $err);
    $cleanup();

    $err = attempt(fn() => BookingService::create($req(['L3', 'L4'], ['Female', 'Male'], 'sharing', '4')));
    check('customer: couple L3(F)+L4(M) in one booking goes through', $err === null, (string) $err);
    $sid = (int) Seats::schedule($routeId, $D)['id'];   // the cleanup above may have removed the old row
    $lk = $lockOf($sid, 'L-2');
    check('couple booking takes the cabin whole (mixed_allowed)', $lk === 'mixed_allowed', $lk);
    $cleanup();

    $err = attempt(fn() => BookingService::create($req(['L4', 'L3'], ['Male', 'Female'], 'sharing', '5')));
    check('customer: couple in the other order L4(M)+L3(F) also goes through', $err === null, (string) $err);
    $cleanup();

    /* ---- desk / Quick Ticket sale path -------------------------------- */
    if ($admin === null) {
        echo "  \033[33mSKIP\033[0m  no active superadmin for the desk / transfer / re-approval cases\n";
    } else {
        $aid = (int) $admin['id'];
        $sid = (int) Seats::schedule($routeId, $D)['id'];
        $err = attempt(fn() => $desk(['L4'], ['Male'], '6', $sid));
        check('desk (counterSale / Quick Ticket): Male on L4 alone is REFUSED by the partner rule', $isPartnerMsg($err), (string) $err);
        $err = attempt(fn() => $desk(['L4', 'L3'], ['Male', 'Male'], '6', $sid));
        check('desk: L4(M)+L3(M) is REFUSED by the partner rule', $isPartnerMsg($err), (string) $err);
        $err = attempt(fn() => $desk(['L3', 'L4'], ['Female', 'Male'], '7', $sid));
        check('desk: couple L3(F)+L4(M) goes through', $err === null, (string) $err);
        $cleanup();

        /* Office sold L3 to a man (its staff override of the women's berth):
           L3 is no longer free, so L4 is no longer guarded. */
        $sid = (int) Seats::schedule($routeId, $D)['id'];
        $err = attempt(fn() => $desk(['L3'], ['Male'], '8', $sid));
        check('desk: Male on women\'s L3 (staff override, unchanged rule) goes through', $err === null, (string) $err);
        $avNow = Seats::availabilityForSchedule($sid, 'sharing');
        check('seat map: L4 no longer marked once L3 is sold', !in_array('L4', $avNow['female'], true) && !isset($avNow['womenPair']['L4']));

        /* Seat transfer: moving that man from L3 onto L4 frees L3 again -> refused. */
        $err = attempt(fn() => Seats::transferSeat($sid, 'L3', 'L4', 'sleeper', $aid, 'S1 test'));
        check('transfer: man moved off L3 onto L4 is REFUSED', $err !== null && stripos($err, 'woman') !== false, (string) $err);

        $err = attempt(fn() => BookingService::create($req(['L4'], ['Male'], 'sharing', '9')));
        check('customer: Male on L4 is allowed once L3 is sold (cabin already male)', $err === null, (string) $err);

        /* 18 Nov refix: the office's L3 man moved AWAY while the L4 man stays
           frees L3 beside a man -> refused; moving the L4 man first works. */
        $err = attempt(fn() => Seats::transferSeat($sid, 'L3', 'U11', 'sleeper', $aid, 'S1 test'));
        check('transfer: office\'s L3 man moved away while a man stays on L4 is REFUSED', $isHoleMsg($err), (string) $err);
        $l3Back = $seatsOf((int) ($bookingOf('8')['id'] ?? 0));
        check('  ...and nothing moved (L3 still his)', $l3Back === ['L3'], json_encode($l3Back));
        $err = attempt(fn() => Seats::transferSeat($sid, 'L4', 'U12', 'sleeper', $aid, 'S1 test'));
        check('transfer: the L4 man moved first goes through', $err === null, (string) $err);
        $err = attempt(fn() => Seats::transferSeat($sid, 'L3', 'U11', 'sleeper', $aid, 'S1 test'));
        check('transfer: then the L3 man moves away too (cabin empties, no hole)', $err === null, (string) $err);
        check('  cabin L-2 reopens (lock none)', $lockOf($sid, 'L-2') === 'none', $lockOf($sid, 'L-2'));
        $cleanup();

        /* A woman on L3 first: L4 follows the cabin's female_only lock as before. */
        $sid = (int) Seats::schedule($routeId, $D)['id'];
        $err = attempt(fn() => BookingService::create($req(['L3'], ['Female'], 'sharing', '1')));
        check('customer: Female on L3 goes through', $err === null, (string) $err);
        $err = attempt(fn() => BookingService::create($req(['L4'], ['Male'], 'sharing', '2')));
        check('customer: Male on L4 next to a woman is still REFUSED (old cabin lock)', $err !== null, (string) $err);
        /* A woman alone moved off L3 (nobody on L4) opens no hole. */
        $err = attempt(fn() => Seats::transferSeat($sid, 'L3', 'L1', 'sleeper', $aid, 'S1 test'));
        check('transfer: a woman alone moved L3 -> L1 (L4 empty) goes through', $err === null, (string) $err);
        $err = attempt(fn() => BookingService::create($req(['L4'], ['Female'], 'sharing', '3')));
        check('customer: Female on L4 goes through', $err === null, (string) $err);
        $cleanup();

        /* ---- 18 Nov refix: couple's woman moved / cancelled off L3 ---------- */
        $sid = (int) Seats::schedule($routeId, $D)['id'];
        $err = attempt(fn() => BookingService::create($req(['L3', 'L4'], ['Female', 'Male'], 'sharing', '4')));
        check('couple L3(F)+L4(M) booked for the move / cancel cases', $err === null, (string) $err);
        $cb = (int) ($bookingOf('4')['id'] ?? 0);
        $err = attempt(fn() => Seats::transferSeat($sid, 'L3', 'L1', 'sleeper', $aid, 'S1 test'));
        check('transfer: the couple\'s woman moved off L3 (man stays on L4) is REFUSED', $isHoleMsg($err), (string) $err);
        check('  ...nothing moved, cabin still mixed_allowed', $seatsOf($cb) === ['L3', 'L4'] && $lockOf($sid, 'L-2') === 'mixed_allowed',
            json_encode($seatsOf($cb)) . ' ' . $lockOf($sid, 'L-2'));
        check('  message is bilingual (नेपाली · English)', $err !== null && str_contains($err, 'महिला'), (string) $err);

        $err = attempt(fn() => BookingService::cancelSeat($cb, 'L3', $aid, 'S1 test'));
        check('per-seat cancel: the couple\'s woman on L3 (man stays on L4) is REFUSED', $isHoleMsg($err), (string) $err);
        check('  ...both seats still on the booking', $seatsOf($cb) === ['L3', 'L4'], json_encode($seatsOf($cb)));

        $err = attempt(fn() => BookingService::cancelSeat($cb, 'L4', $aid, 'S1 test'));
        check('per-seat cancel: the man on L4 first goes through', $err === null, (string) $err);
        check('  ...the woman keeps L3, cabin female_only', $seatsOf($cb) === ['L3'] && $lockOf($sid, 'L-2') === 'female_only',
            json_encode($seatsOf($cb)) . ' ' . $lockOf($sid, 'L-2'));
        $cleanup();

        $sid = (int) Seats::schedule($routeId, $D)['id'];
        $err = attempt(fn() => BookingService::create($req(['L3', 'L4'], ['Female', 'Male'], 'sharing', '5')));
        $cb  = (int) ($bookingOf('5')['id'] ?? 0);
        $err = $err ?? attempt(fn() => Seats::transferSeat($sid, 'L4', 'U10', 'sleeper', $aid, 'S1 test'));
        check('transfer: the couple\'s man moved L4 -> U10 first goes through', $err === null, (string) $err);
        $err = attempt(fn() => Seats::transferSeat($sid, 'L3', 'L1', 'sleeper', $aid, 'S1 test'));
        check('transfer: then the woman L3 -> L1 goes through', $err === null, (string) $err);
        check('  ...booking now on L1 + U10', $seatsOf($cb) === ['L1', 'U10'], json_encode($seatsOf($cb)));
        $cleanup();

        /* ---- 18 Nov refix: re-approval / resubmitted proof re-takes OWN L4 --- */
        $sid = (int) Seats::schedule($routeId, $D)['id'];
        $err = attempt(fn() => $desk(['L3'], ['Male'], '6', $sid));          // office override: L3 taken
        $err = $err ?? attempt(fn() => BookingService::create($req(['L4'], ['Male'], 'sharing', '7')));
        check('man books L4 alone while L3 is taken (legal sale)', $err === null, (string) $err);
        $l3b = (int) ($bookingOf('6')['id'] ?? 0);
        $l4b = (int) ($bookingOf('7')['id'] ?? 0);
        // The L3 booking goes away (cancelled), so L3 is free again.
        Database::transaction(function () use ($l3b): void { Seats::releaseBooking($l3b); });
        Database::update('bookings', ['status' => 'cancelled'], 'id = :i', ['i' => $l3b]);
        $err = attempt(fn() => BookingService::reject($l4b, $aid, 'S1 test: bad proof'));
        check('his booking is rejected (seats released)', $err === null && $seatsOf($l4b) === [], (string) $err . ' ' . json_encode($seatsOf($l4b)));

        $pnr = (string) ($bookingOf('7')['pnr'] ?? '');
        $err = attempt(fn() => BookingService::submitPaymentProof($pnr, ['utr' => 'S1UTR' . random_int(100000, 999999), 'payerName' => 'S1 Test']));
        $st  = (string) ($bookingOf('7')['status'] ?? '');
        check('resubmitted proof re-takes his OWN L4 with L3 free (existing booking untouched)', $err === null && $st === 'pending' && $seatsOf($l4b) === ['L4'],
            (string) $err . ' status=' . $st . ' ' . json_encode($seatsOf($l4b)));

        $err = attempt(fn() => BookingService::reject($l4b, $aid, 'S1 test: bad proof again'));
        check('rejected again (seats released)', $err === null && $seatsOf($l4b) === [], (string) $err);
        $err = attempt(fn() => BookingService::confirm($l4b, $aid, 'S1 test: re-approve'));
        $st  = (string) ($bookingOf('7')['status'] ?? '');
        check('office re-approval re-takes his OWN L4 with L3 free', $err === null && $st === 'confirmed' && $seatsOf($l4b) === ['L4'],
            (string) $err . ' status=' . $st . ' ' . json_encode($seatsOf($l4b)));
        check('  cabin L-2 lock follows him (male_only)', $lockOf($sid, 'L-2') === 'male_only', $lockOf($sid, 'L-2'));
        $err = attempt(fn() => BookingService::create($req(['L3'], ['Female'], 'sharing', '8')));
        check('  (the known cost: a woman cannot take L3 beside him - same as before 17 Nov)', $err !== null, (string) $err);
        $cleanup();
    }

    /* ---- private mode keeps today's rules ------------------------------ */
    $err = attempt(fn() => BookingService::create($req(['L2'], ['Male'], 'private', '5')));
    check('private cabin L2 (beds L3+L4) to a man: unchanged, goes through', $err === null, (string) $err);
    $cleanup();

    /* ---- the opt-out for re-taking a booking's own seats --------------- */
    $sid = (int) Seats::schedule($routeId, $D)['id'];
    $err = attempt(fn() => Database::transaction(function () use ($sid): void {
        Seats::assertGenderAllowed($sid, 'sleeper', 'sharing', ['L4'], ['Male'], [], false);
        throw new RuntimeException('rollback-ok');
    }));
    check('assertGenderAllowed(..., womenPartnerRule: false) skips the partner rule', $err === 'rollback-ok', (string) $err);
    $err = attempt(fn() => Database::transaction(function () use ($sid): void {
        Seats::assertGenderAllowed($sid, 'sleeper', 'sharing', ['L4'], ['Other'], []);
        throw new RuntimeException('rollback-ok');
    }));
    check('only a Male is refused (Other / blank gender not blocked, as on L1-L3)', $err === 'rollback-ok', (string) $err);
    check('womenPartnerHoleAfter: seater has no partner, never a hole', Seats::womenPartnerHoleAfter($sid, 'seater', ['1A']) === null);
} catch (Throwable $e) {
    check('suite ran without a fatal', false, $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    $left = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . S1_PHONE . "_'", [], 0);
    check('cleanup: no test bookings left', $left === 0, (string) $left);
    $logs = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE to_number LIKE '%" . S1_PHONE . "_'", [], 0);
    $prof = (int) Database::scalar("SELECT COUNT(*) FROM user_profiles WHERE phone LIKE '%" . S1_PHONE . "_'", [], 0);
    $ids   = array_keys($GLOBALS['S1_SEEN'] ?? []);
    $watch = $ids === [] ? 0 : (int) Database::scalar('SELECT COUNT(*) FROM delivery_log WHERE booking_id IN (' . implode(',', array_map('intval', $ids)) . ')', [], 0);
    check('cleanup: no message_logs / user_profiles / delivery_log rows left for the test bookings', $logs === 0 && $prof === 0 && $watch === 0,
        "logs={$logs} profiles={$prof} delivery={$watch} (" . count($ids) . ' bookings made)');
    $sch = (int) Database::scalar('SELECT COUNT(*) FROM schedules WHERE route_id = :r AND travel_date = :d', ['r' => $routeId, 'd' => $D], 0);
    check('cleanup: the test schedule row is gone', $sch === 0, (string) $sch);
}

echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
