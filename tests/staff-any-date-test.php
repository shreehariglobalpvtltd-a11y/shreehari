<?php
/**
 * =====================================================================
 *  STAFF MAY BOOK ANY DATE — customers may not (6 Sep 2026)
 *
 *  The counter was refusing a ticket for a bus that left three days ago
 *  with "Choose a valid travel date": api/book.php floored a seller at
 *  yesterday, and behind it BookingService::create() applied the customer
 *  window (today → 30-day horizon), the "bus already departed" gate and
 *  the pickup cut-off. Six stacked guards; removing one just moved the
 *  error to the next.
 *
 *  Now a selling staff session ($seller context + Auth::isSellingStaff())
 *  may record a ticket for ANY travel date. This suite proves both halves:
 *
 *    - staff: 3 days ago → booked; 45 days ahead (past the horizon) →
 *      booked; a cancelled trip → still refused; before the inaugural
 *      date → still refused
 *    - a customer (no session, no seller): the same past and far-future
 *      dates are refused exactly as before
 *
 *  Service-level, like counter-mode-test.php: create() is the one booking
 *  path every role shares, so what it accepts is what the counter gets.
 *  Creates real rows on throwaway dates and cleans up after itself.
 *
 *      php -c .claude/php-dev.ini tests/staff-any-date-test.php
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
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/boarding.php';

const SAD_PHONE = '98770001';   // + 2-digit suffix = 10 digits

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(string $l, callable $fn, string $needle = ''): void {
    try { $fn(); check($l . ' (expected refusal)', false, 'no exception'); }
    catch (Throwable $e) {
        $ok = $needle === '' || stripos($e->getMessage(), $needle) !== false;
        check($l, $ok, '"' . mb_substr($e->getMessage(), 0, 90) . '"');
    }
}

echo "\n=== Staff may book any date; customers may not ===\n\n";

/* Pin the window so the far-future case is genuinely past the horizon —
   and put every setting back EXACTLY as found when done. The shared test
   database carries a very wide horizon so other suites can book fixture
   dates in 2099; the first run of this suite left it at 30 and took three
   of them down with "open up to ...". Restored raw (value + type + group +
   is_public), and a key that did not exist before is deleted again. */
$PINNED = ['booking_open_from', 'booking_horizon_days', 'daily_service_on'];
$prior  = [];
foreach ($PINNED as $k) {
    $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$restoreSettings = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        if ($row === null) {
            try { Database::delete('settings', 'skey = :k', ['k' => $k]); } catch (Throwable $e) {}
        } else {
            try {
                Database::update('settings', [
                    'svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'],
                    'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public'],
                ], 'skey = :k', ['k' => $k]);
            } catch (Throwable $e) {}
        }
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};
Settings::set('booking_open_from', '2026-09-02', 'string', 'booking', true);
Settings::set('booking_horizon_days', 30, 'int', 'booking', true);
Settings::set('daily_service_on', true, 'bool', 'booking', true);
Settings::flush();

$rid = (int) Database::scalar('SELECT id FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1', [], 0);
if ($rid <= 0) { check('an active route exists', false); exit(1); }
$route = Database::fetch('SELECT * FROM routes WHERE id = :i', ['i' => $rid]);

$D_PAST   = date('Y-m-d', strtotime('-3 days'));
$D_FAR    = date('Y-m-d', strtotime('+45 days'));
$D_CANCEL = date('Y-m-d', strtotime('+52 days'));
$D_PRE    = '2026-09-01';
$dates    = [$D_PAST, $D_FAR, $D_CANCEL, $D_PRE];

/* Throwaway counter agent — role agent carries schedules.edit, which is
   what makes Auth::isSellingStaff() true, the same as a real counter login. */
$agentId = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => 'sad-agent'], 0);
if ($agentId === 0) {
    $agentId = (int) Database::insert('admins', [
        'username' => 'sad-agent', 'password_hash' => password_hash('Sad@12345', PASSWORD_BCRYPT),
        'full_name' => 'Any Date Agent', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0,
    ]);
} else {
    Database::update('admins', ['role' => 'agent', 'is_active' => 1], 'id = :i', ['i' => $agentId]);
}

$cleanup = function () use ($dates, $agentId): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . SAD_PHONE . "%' OR (contact_phone = '0000000000' AND sold_by_admin_id = " . (int) $agentId . ")") as $r) {
        try { Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    foreach ($dates as $d) {
        Database::delete('booking_legs', 'travel_date = :d', ['d' => $d]);
        foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $d]) as $s) {
            Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
            try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $s['id']]); } catch (Throwable $e) {}
        }
        Database::delete('schedules', 'travel_date = :d', ['d' => $d]);
    }
    try { Database::pdo()->exec('DELETE FROM rate_limits'); } catch (Throwable $e) {}
};
$cleanup();

$seatIds = Seats::seatIds((string) $route['coach_type'], 'sharing');
$req = function (string $date, array $seats, string $sfx) use ($rid): array {
    $pax = [];
    foreach ($seats as $i => $s) { $pax[] = ['seat' => $s, 'name' => 'Any Date Pax ' . ($i + 1), 'age' => 30 + $i, 'gender' => 'Male']; }
    return [
        'routeId' => $rid, 'travelDate' => $date, 'seats' => $seats, 'passengers' => $pax,
        'contact' => ['phone' => SAD_PHONE . $sfx], 'bookingMode' => 'sharing',
        'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '', 'referralCode' => '',
    ];
};
$seller = fn(): array => ['adminId' => $agentId, 'source' => 'agent', 'paymentMethod' => 'cash'];
$asStaff = function () use ($agentId): void {
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $agentId, 'username' => 'sad-agent', 'role' => 'agent', 'permissions' => [], 'last_seen' => time(), 'logged_in_at' => time()];
};
$asGuest = function (): void { unset($_SESSION[ADMIN_SESSION_KEY]); };

try {
    /* ---- the predicate itself ------------------------------------------ */
    $asGuest();
    check('no session → not selling staff', Auth::isSellingStaff() === false);
    $asStaff();
    check('agent session → selling staff', Auth::isSellingStaff() === true);

    /* ---- customer: unchanged refusals ---------------------------------- */
    $asGuest();
    throws('customer cannot book 3 days ago', fn() => BookingService::create($req($D_PAST, [$seatIds[0]], '01')));
    throws('customer cannot book past the 30-day horizon', fn() => BookingService::create($req($D_FAR, [$seatIds[0]], '02')), 'open up to');

    /* ---- staff: any date ------------------------------------------------ */
    $asStaff();
    $b1 = BookingService::create($req($D_PAST, [$seatIds[0], $seatIds[1]], '03'), $seller());
    check('staff books a bus that left 3 days ago', !empty($b1['pnr']), (string) ($b1['pnr'] ?? ''));
    check('  it is confirmed and issued (counter path)', ($b1['status'] ?? '') === 'confirmed');
    check('  attributed to the seller', (int) ($b1['sold_by_admin_id'] ?? 0) === $agentId);
    $leg = Database::fetch('SELECT travel_date, boarding_stop FROM booking_legs WHERE booking_id = :b LIMIT 1', ['b' => (int) $b1['id']]);
    check('  the leg carries the past travel date', ($leg['travel_date'] ?? '') === $D_PAST);
    check('  a boarding point was still filled in', trim((string) ($leg['boarding_stop'] ?? '')) !== '', (string) ($leg['boarding_stop'] ?? ''));

    $b2 = BookingService::create($req($D_FAR, [$seatIds[0]], '04'), $seller());
    check('staff books 45 days out, past the customer horizon', !empty($b2['pnr']), (string) ($b2['pnr'] ?? ''));

    /* ---- what staff still cannot do ------------------------------------ */
    Seats::schedule($rid, $D_CANCEL);
    Database::update('schedules', ['status' => 'cancelled'], 'route_id = :r AND travel_date = :d', ['r' => $rid, 'd' => $D_CANCEL]);
    throws('a CANCELLED trip still refuses staff', fn() => BookingService::create($req($D_CANCEL, [$seatIds[0]], '05'), $seller()), 'cancelled');

    throws('a date before the inaugural departure still refuses staff', fn() => BookingService::create($req($D_PRE, [$seatIds[0]], '06'), $seller()), 'inaugural');

    /* ---- a seller array WITHOUT a selling session gets no any-date ------ */
    $asGuest();
    throws('a bare seller context with no staff session is held to the customer window',
        fn() => BookingService::create($req($D_FAR, [$seatIds[2]], '07'), $seller()), 'open up to');

    /* ---- the HTTP-layer floor is gone too (static) ---------------------- */
    $src = (string) file_get_contents(dirname(__DIR__) . '/api/book.php');
    check('api/book.php no longer floors a seller at yesterday', !str_contains($src, "addDaysISO(todayISO(), -1)"));
    check('api/book.php still floors a customer at today', str_contains($src, "\$seller === null && \$request['travelDate'] < todayISO()"));
    $cj = (string) file_get_contents(dirname(__DIR__) . '/assets/js/14-counter.js');
    check('counter date input no longer carries a min', str_contains($cj, "di.removeAttribute('min')"));

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage(), false);
} finally {
    $asGuest();
    $cleanup();
    $restoreSettings();
}

echo "\n----------------------------------------\n";
echo "PASSED: {$PASS}   FAILED: {$FAIL}\n";
echo "----------------------------------------\n\n";
exit($FAIL === 0 ? 0 : 1);
