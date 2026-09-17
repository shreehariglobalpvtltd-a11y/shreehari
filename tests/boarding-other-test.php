<?php
/**
 * "Other" boarding / drop point (owner requirement 8, 17 Sep 2026).
 *
 * BookingService::create() and ::counterSale() accept the passenger's OWN
 * pickup text only behind the BOARDING_OTHER sentinel ('__other__'):
 *
 *     boarding = '__other__'  +  boardingOther = 'Ram Mandir Gate 2'
 *     drop     = '__other__'  +  dropOther     = '...'      (create() only)
 *
 * The text is trimmed, cleaned to 120 characters, refused under 3, and saved
 * as plain text in booking_legs.boarding_stop (drop_stop for drop) — the very
 * column the ticket (Ticket::loadBooking / BookingService::detail legs[]),
 * manifest, chalani and CSV export already print. A configured stop, and a
 * label posted WITHOUT the sentinel, take exactly the path they always took.
 *
 *   php -c .claude/php-dev.ini tests/boarding-other-test.php
 * Throwaway agent + far-future schedule; cleans up after itself. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/booking.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; } }
/** Assert $fn throws; with $needle, the message must also contain it (case-insensitive). */
function expectThrow(string $l, callable $fn, string $needle = ''): void {
    try { $fn(); check($l . ' (expected rejection)', false); }
    catch (Throwable $e) {
        $ok = $needle === '' || stripos($e->getMessage(), $needle) !== false;
        check($l . ' → "' . $e->getMessage() . '"', $ok);
    }
}

const TD      = '2099-10-12';
const AGENT_U = 'testother-agent';
const PHONE0  = '91000009';   // + 2 digits per booking = a 10-digit throwaway mobile

$OTHER = BookingService::BOARDING_OTHER;

/* ---- Pin the customer window so 2099 is bookable; put every setting back
   EXACTLY as found (raw value + type + group + is_public; a key that did not
   exist before is deleted again) — the same discipline staff-any-date uses. */
$PINNED = ['booking_horizon_days', 'daily_service_on'];
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
Settings::set('booking_horizon_days', 40000, 'int', 'booking', true);
Settings::set('daily_service_on', true, 'bool', 'booking', true);
Settings::flush();

/** The outbound leg row of a PNR — the columns the ticket and reports read. */
function legOf(string $pnr): ?array {
    return Database::fetch(
        'SELECT l.boarding_stop, l.drop_stop, l.boarding_time
           FROM bookings b JOIN booking_legs l ON l.booking_id = b.id
          WHERE b.pnr = :p ORDER BY l.id LIMIT 1',
        ['p' => $pnr]
    );
}

function cleanup(int $agentId, int $sid): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . PHONE0 . "%'") as $r) {
        try { Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    if ($agentId > 0) {
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $agentId]);
        foreach (pluck(Database::fetchAll('SELECT id FROM bookings WHERE sold_by_admin_id = :a', ['a' => $agentId]), 'id') as $id) {
            Database::delete('bookings', 'id = :i', ['i' => (int) $id]);
        }
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $agentId]);
        Database::delete('admins', 'id = :a', ['a' => $agentId]);
    }
    if ($sid > 0) {
        try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
        try { Database::delete('seat_locks', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
        Database::delete('booking_legs', 'schedule_id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
        Database::delete('schedules', 'id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
    }
}

echo "\n=== \"Other\" boarding / drop point — create() + counterSale() ===\n\n";

$agentId = 0; $sid = 0;
try {
    $old = Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => AGENT_U]);
    cleanup($old !== null ? (int) $old['id'] : 0, 0);
    $agentId = Database::insert('admins', [
        'username' => AGENT_U, 'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'full_name' => 'Other Stop Test Agent', 'role' => 'agent', 'is_active' => 1,
    ]);

    $route = Database::fetch("SELECT * FROM routes WHERE coach_type='seater' AND is_active=1 ORDER BY id LIMIT 1")
          ?? Database::fetch("SELECT * FROM routes WHERE is_active=1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no active route\n"; exit(1); }
    $routeId = (int) $route['id'];
    $sid  = (int) Seats::schedule($routeId, TD)['id'];
    $free = Seats::availability($routeId, TD)['available'] ?? [];
    // Female passengers throughout: the test database's first active route is a sleeper whose free list
// includes women-reserved berths, and every cabin here is all-female so the gender lock never bites.
if (count($free) < 6) { echo "need 6 free seats on route #$routeId for " . TD . "\n"; exit(1); }

    $stops      = Boarding::stopsFor($routeId);
    $configured = $stops !== [] ? (string) $stops[0]['name'] : (string) $route['from_city'];
    $originTown = (string) $route['from_city'];

    /* A customer checkout as api/book.php hands it to create(): 'boarding',
       'drop', 'originTown', 'boardingOther', 'dropOther' overridden per case. */
    $req = static function (string $seat, string $suffix, array $extra = []) use ($routeId): array {
        return array_merge([
            'routeId'       => $routeId,
            'travelDate'    => TD,
            'seats'         => [$seat],
            'passengers'    => [['seat' => $seat, 'name' => 'Other Test ' . $suffix, 'age' => 30, 'gender' => 'Female']],
            'contact'       => ['phone' => PHONE0 . $suffix, 'email' => '', 'idType' => '', 'idNum' => ''],
            'bookingMode'   => 'sharing',
            'paymentMethod' => 'cod',
            'isCod'         => true,
            'boarding'      => '',
            'drop'          => '',
            'originTown'    => '',
        ], $extra);
    };

    // 1) A configured stop still lands verbatim — and boardingOther means
    //    nothing without the sentinel (the contract is explicit, never implied).
    $b1 = BookingService::create($req($free[0], '01', ['boarding' => $configured, 'boardingOther' => 'IGNORED WITHOUT SENTINEL']));
    $l1 = legOf((string) $b1['pnr']);
    check('(1) configured stop stored verbatim in booking_legs.boarding_stop', $l1 !== null && (string) $l1['boarding_stop'] === $configured);
    check('(1) boardingOther is ignored when the sentinel is absent', $l1 !== null && strpos((string) $l1['boarding_stop'], 'IGNORED') === false);
    check('(1) no boarding_time is stamped for a configured stop', $l1 !== null && $l1['boarding_time'] === null);

    // 2) Sentinel + text → exactly that text.
    $b2 = BookingService::create($req($free[1], '02', ['boarding' => $OTHER, 'boardingOther' => 'Ram Mandir Gate 2', 'originTown' => $originTown]));
    $l2 = legOf((string) $b2['pnr']);
    check('(2) manual text "Ram Mandir Gate 2" stored exactly in boarding_stop', $l2 !== null && (string) $l2['boarding_stop'] === 'Ram Mandir Gate 2');
    $townTime = null;
    foreach ($stops as $s) {
        if ($s['time'] !== null && Boarding::townKey((string) $s['name']) === Boarding::townKey($originTown)) { $townTime = (string) $s['time']; break; }
    }
    check('(2) boarding_time = the searched town\'s configured stop time (null when it matches none)',
        $l2 !== null && (string) ($l2['boarding_time'] ?? '') === (string) ($townTime ?? ''));
    check('(2) booking confirmed / pending like any other', in_array((string) $b2['status'], ['confirmed', 'pending'], true));

    // 3) Too short → refused, and nothing written.
    expectThrow('(3) a 2-character manual text is refused',
        fn() => BookingService::create($req($free[2], '03', ['boarding' => $OTHER, 'boardingOther' => 'Ab'])), 'at least 3');
    expectThrow('(3b) the sentinel without any text is refused',
        fn() => BookingService::create($req($free[2], '04', ['boarding' => $OTHER])), 'at least 3');
    expectThrow('(3c) whitespace-only text is refused',
        fn() => BookingService::create($req($free[2], '04', ['boarding' => $OTHER, 'boardingOther' => "  \t  "])), 'at least 3');
    $left = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone IN (:a, :b)', ['a' => PHONE0 . '03', 'b' => PHONE0 . '04'], 0);
    check('(3) a refused request leaves no booking behind', $left === 0);

    // 4) 300+ characters → cut to 120 (Security::clean), never refused for length.
    $long = trim(str_repeat('Ram Mandir Gate 2 ', 20));   // 359 chars, single spaces
    $b4 = BookingService::create($req($free[2], '05', ['boarding' => $OTHER, 'boardingOther' => $long]));
    $l4 = legOf((string) $b4['pnr']);
    check('(4) an over-long manual text is cut to 120 characters',
        $l4 !== null && mb_strlen((string) $l4['boarding_stop'], 'UTF-8') <= 120
        && (string) $l4['boarding_stop'] === rtrim(mb_substr($long, 0, 120, 'UTF-8')));

    // 4b) Drop gets the same treatment (drop_stop is the same kind of free-text column).
    $b4b = BookingService::create($req($free[3], '06', ['boarding' => $configured, 'drop' => $OTHER, 'dropOther' => '  Kohalpur   Chowk  ']));
    $l4b = legOf((string) $b4b['pnr']);
    check('(4b) manual drop text stored (whitespace collapsed) in drop_stop', $l4b !== null && (string) $l4b['drop_stop'] === 'Kohalpur Chowk');
    check('(4b) boarding untouched by a manual drop', $l4b !== null && (string) $l4b['boarding_stop'] === $configured);
    expectThrow('(4c) a manual drop under 3 characters is refused',
        fn() => BookingService::create($req($free[4], '07', ['drop' => $OTHER, 'dropOther' => 'K'])), 'drop point');
    // A typed "@ 21:00" must never pose as a configured pickup time on the ticket.
    expectThrow('(4d) text that is only a time suffix is refused once the suffix is stripped',
        fn() => BookingService::create($req($free[4], '07', ['boarding' => $OTHER, 'boardingOther' => '@ 21:00'])), 'at least 3');

    // 5) counterSale() (paper register) with the same contract stores the text.
    $b5 = BookingService::counterSale($route, $sid, TD, [$free[4]], [
        'name' => 'Counter Other', 'phone' => PHONE0 . '08', 'amount' => 1500.00, 'paymentMethod' => 'cash',
        'boarding' => $OTHER, 'boardingOther' => 'Gorakhpur Bus Stand Gate 3',
    ], $agentId, 'agent');
    $l5 = legOf((string) $b5['pnr']);
    check('(5) counterSale stores the manual text in boarding_stop', $l5 !== null && (string) $l5['boarding_stop'] === 'Gorakhpur Bus Stand Gate 3');
    check('(5) counterSale booking confirmed as before', (string) $b5['status'] === 'confirmed');
    expectThrow('(5b) counterSale refuses a 2-character manual text before touching a seat',
        fn() => BookingService::counterSale($route, $sid, TD, [$free[5]], [
            'name' => 'Counter Short', 'phone' => PHONE0 . '09', 'paymentMethod' => 'cash',
            'boarding' => $OTHER, 'boardingOther' => 'Go',
        ], $agentId, 'agent'), 'at least 3');
    check('(5b) the refused counter sale left its seat free',
        in_array($free[5], Seats::availability($routeId, TD)['available'] ?? [], true));

    // 6) The manual text reaches the data the ticket reads.
    $d = BookingService::detail((string) $b2['pnr']);
    check('(6) detail(pnr) → legs[0].boarding_stop carries the manual text',
        $d !== null && (string) ($d['legs'][0]['boarding_stop'] ?? '') === 'Ram Mandir Gate 2');
    $disp = Boarding::stopDisplay('Ram Mandir Gate 2', (string) $route['from_city']);
    check('(6) Boarding::stopDisplay() (ticket PNG / PDF header) prints the manual text, not the route origin',
        $disp['name'] === 'Ram Mandir Gate 2' && $disp['code'] === 'RAM' && $disp['time'] === null);
    $d5 = BookingService::detail((string) $b5['pnr']);
    check('(6) detail(pnr) of the counter sale carries its manual text too',
        $d5 !== null && (string) ($d5['legs'][0]['boarding_stop'] ?? '') === 'Gorakhpur Bus Stand Gate 3');

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanup($agentId, $sid);
    $restoreSettings();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
