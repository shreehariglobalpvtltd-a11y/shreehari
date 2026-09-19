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
 * Also (18 Sep 2026): counterSale() given a plain 'boarding' / 'originTown'
 * hint that names a configured TOWN stores that town's configured stop, not
 * the first open one. Its transaction closure had not captured $data, so the
 * hint was silently '' (`??` hides an undefined variable) and every counter
 * ticket was pinned to the first open stop (cases 5c-5e).
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
const FIXTURE_STOP = 'Zz Fixture Town - Test Pickup';   // appended only when the route's pickups are all in one town (5c)

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
    try { Database::delete('route_stops', 'stop_name = :n', ['n' => FIXTURE_STOP]); } catch (Throwable $e) {}
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

    /* (5c) needs two configured pickups in DIFFERENT towns, or "matched the
       caller's town" cannot be told from "first open stop". A route whose
       pickups are all in one town gets a throwaway LAST pickup - added here,
       before Seats::schedule() / Boarding::stopsFor() prime Boarding's
       per-route cache (raw SQL on purpose); cleanup() removes it by name. */
    $rawStops = Database::fetchAll(
        "SELECT stop_name, sort_order FROM route_stops WHERE route_id = :r AND stop_type = 'boarding' ORDER BY sort_order",
        ['r' => $routeId]
    );
    $towns = array_unique(array_map(static fn(array $r): string => Boarding::townKey((string) $r['stop_name']), $rawStops));
    if (count($towns) < 2) {
        $maxOrder = 0;
        foreach ($rawStops as $r) { $maxOrder = max($maxOrder, (int) $r['sort_order']); }
        Database::insert('route_stops', [
            'route_id' => $routeId, 'stop_type' => 'boarding', 'stop_name' => FIXTURE_STOP,
            'stop_time' => '23:45:00', 'sort_order' => $maxOrder + 1,
        ]);
    }

    $sid  = (int) Seats::schedule($routeId, TD)['id'];
    $free = Seats::availability($routeId, TD)['available'] ?? [];
    // Female passengers throughout: the test database's first active route is a sleeper whose free list
// includes women-reserved berths, and every cabin here is all-female so the gender lock never bites.
if (count($free) < 9) { echo "need 9 free seats on route #$routeId for " . TD . "\n"; exit(1); }

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

    // 5c) A plain 'boarding' naming a configured TOWN (what the paper register
    //     hands over) must land on THAT configured stop, not the first open one.
    //     The transaction closure had not captured $data, so the hint was
    //     silently '' (`??` hides an undefined variable) and every counter
    //     ticket took the first open stop (fixed 18 Sep 2026).
    $open  = Boarding::openStops($routeId, TD);
    $canon = static function (array $s): string {   // the label defaultBoardingStop() builds
        $t = (string) ($s['time'] ?? '');
        return trim((string) $s['name']) . ($t !== '' ? ' @ ' . substr($t, 0, 5) : '');
    };
    $firstLabel = $open !== [] ? $canon($open[0]) : '';
    $target = null;   // the LAST open pickup in a different town from the first
    foreach (array_reverse($open) as $s) {
        if (Boarding::townKey((string) $s['name']) !== Boarding::townKey((string) $open[0]['name'])) { $target = $s; break; }
    }
    check('(5c) the route offers a configured pickup in a second town to test against', $target !== null);
    if ($target !== null) {
        // The caller's TOWN only: the leading segment of the stop label, e.g. "Mehsana" of "Mehsana — Silver Complex".
        $parts = preg_split('/[—–\-·|,(@]/u', (string) $target['name'], 2);
        $town  = trim((string) (is_array($parts) ? $parts[0] : $target['name']));
        $want  = $canon($target);
        $b5c = BookingService::counterSale($route, $sid, TD, [$free[6]], [
            'name' => 'Counter Town', 'phone' => PHONE0 . '10', 'paymentMethod' => 'cash',
            'boarding' => $town,
        ], $agentId, 'agent');
        $l5c = legOf((string) $b5c['pnr']);
        check('(5c) counterSale boarding="' . $town . '" stores that town\'s configured stop "' . $want . '"',
            $l5c !== null && (string) $l5c['boarding_stop'] === $want);
        check('(5c) ...and not the first open stop "' . $firstLabel . '"',
            $l5c !== null && (string) $l5c['boarding_stop'] !== $firstLabel);

        // 5d) 'originTown' (the create() hint name) is the fallback hint -> the same stop.
        $b5d = BookingService::counterSale($route, $sid, TD, [$free[7]], [
            'name' => 'Counter Origin', 'phone' => PHONE0 . '11', 'paymentMethod' => 'cash',
            'originTown' => $town,
        ], $agentId, 'agent');
        $l5d = legOf((string) $b5d['pnr']);
        check('(5d) originTown="' . $town . '" alone resolves to the same configured stop',
            $l5d !== null && (string) $l5d['boarding_stop'] === $want);

        // 5e) A town matching no configured pickup still falls back to the first open stop.
        $b5e = BookingService::counterSale($route, $sid, TD, [$free[8]], [
            'name' => 'Counter Nowhere', 'phone' => PHONE0 . '12', 'paymentMethod' => 'cash',
            'boarding' => 'Nowhere Junction',
        ], $agentId, 'agent');
        $l5e = legOf((string) $b5e['pnr']);
        check('(5e) a town matching no configured pickup falls back to the first open stop "' . $firstLabel . '"',
            $l5e !== null && (string) $l5e['boarding_stop'] === $firstLabel);
    }

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
