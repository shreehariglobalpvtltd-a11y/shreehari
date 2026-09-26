<?php
/**
 * =====================================================================
 *  quick-ticket-test.php — ⚡ Quick Ticket (6 Sep 2026): name + mobile
 *  in, confirmed ticket out.
 *
 *    • plan(): picks the outbound / return route, the asked date, a
 *      pickup ("Name @ HH:MM"), ONE seat that is neither women-only nor
 *      staff-reserved, and the direction fare — repeatably (no writes)
 *    • the desk's pickup wins when the run calls there (argument or the
 *      quick_ticket_default_boarding setting) and steers the auto
 *      direction; the auto date is today-or-later with the pickup ahead
 *    • gender: a man never lands on a women-only berth, a pair shares a
 *      cabin, a woman's cabin locks female_only and the next man is
 *      seated elsewhere
 *    • sell(): a CONFIRMED counter booking through BookingService::create
 *      (payment verified · cash · "Quick Ticket" note, seller attributed,
 *      leg on the planned pickup), ticket row + 1080x1620 PNG rendered on
 *      the spot, keyed image link, WhatsApp attempted with a click-to-chat
 *      fallback carrying the ticket, all inside 30 s; the second passenger
 *      fills the half-empty cabin; a +977 number stays 10 digits but is
 *      flagged Nepali so WhatsApp dials +977; a party is numbered after
 *      the buyer and priced by Fare::quote
 *    • refusals: blank name / blank or short mobile, a cancelled date
 *    • static mirrors: API gate, admin nav, app banner, i18n parity,
 *      counter bar, run-all + role-gates registration
 *
 *  Creates real rows on throwaway dates (today + 40/41/42) and cleans up.
 *      php -c .claude/php-dev.ini tests/quick-ticket-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickticket.php';   // pulls fare / seats / boarding / ticket / booking / notify / agentwallet

const QT_PHONE = '9100004';   // + 3 digits = a 10-digit test mobile (prefix unique to this suite)

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(string $l, callable $fn, string $needle = ''): void {
    try { $fn(); check($l . ' (expected a refusal)', false, 'no exception'); }
    catch (Throwable $e) {
        $ok = $needle === '' || stripos($e->getMessage(), $needle) !== false;
        check($l, $ok, '"' . mb_substr($e->getMessage(), 0, 90) . '"');
    }
}

echo "\n=== Quick Ticket — name + mobile → auto bus / seat / fare → confirmed ticket ===\n\n";

/* ---- pin the switches this suite depends on; restore exactly as found -- */
$PINNED = ['daily_service_on', 'quick_ticket_default_boarding', 'quick_ticket_default_pay', 'whatsapp_notify_customer', 'allow_cod', 'quick_ticket_customer_on', 'quick_ticket_customer_boarding',
           // QuickBot hardening (7 Sep 2026): the open-ticket cap, the office number the cancel alert
           // would page, and the WhatsApp driver + Twilio rows the sender-breaker block pins (no network here)
           'quick_ticket_max_open', 'whatsapp_notify_admin', 'admin_whatsapp', 'whatsapp_driver', 'twilio_account_sid', 'twilio_auth_token', 'twilio_whatsapp_from'];
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
    // A failure inside the breaker block must not leave a 15-minute pause row
    // behind for the next suite (or the developer's own machine) to inherit.
    try { Notify::twilioResume(); } catch (Throwable $e) {}
};
Settings::set('daily_service_on', true, 'bool', 'booking', true);
Settings::set('quick_ticket_default_boarding', '', 'string', 'booking', false);
Settings::set('quick_ticket_default_pay', 'cash', 'string', 'booking', false);
Settings::set('whatsapp_notify_customer', true, 'bool', 'notify', false);
Settings::set('allow_cod', true, 'bool', 'payment', true);
Settings::set('quick_ticket_customer_on', true, 'bool', 'booking', true);
Settings::set('quick_ticket_customer_boarding', '', 'string', 'booking', false);   // pinned blank; the customer section sets it
Settings::set('quick_ticket_max_open', 3, 'int', 'booking', false);
Settings::set('whatsapp_notify_admin', true, 'bool', 'notify', false);
Settings::set('admin_whatsapp', '9100009999', 'string', 'notify', false);          // the office number a cancel alert pages
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);      // journal only — this suite never calls Twilio
Settings::flush();
Notify::twilioResume();                                                             // a stale pause from an earlier run must not colour the journal

/* ---- the routes under test ------------------------------------------- */
$routes = QuickTicket::routes();
$out = null; $ret = null;
foreach ($routes as $r) {
    if ($out === null && $r['direction'] === 'toNepal' && (string) $r['coach_type'] === 'sleeper') { $out = $r; }
    if ($ret === null && $r['direction'] === 'toIndia') { $ret = $r; }
}
if ($out === null) { echo "  SKIP  no active sleeper route towards Nepal\n"; exit(0); }
$rid   = (int) $out['id'];
$stops = Boarding::stopsFor($rid);
$dir   = Fare::dirFares();

$today = todayISO();
$D  = addDaysISO($today, 40);   // explicit staff any-date fixture: every pickup still ahead
$D2 = addDaysISO($today, 41);   // cancelled
$D3 = addDaysISO($today, 42);   // party of two
$DC = addDaysISO($today, 23);   // customer path: inside the public 30-day horizon
$DC2 = addDaysISO($today, 24);  // open-cap block: a second and third unpaid one-tap ticket
$DC3 = addDaysISO($today, 25);
$dates = [$D, $D2, $D3, $DC, $DC2, $DC3];

/* ---- throwaway counter agent (role agent → Auth::isSellingStaff) ------- */
$agentId = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => 'qt-agent'], 0);
if ($agentId === 0) {
    $agentId = (int) Database::insert('admins', [
        'username' => 'qt-agent', 'password_hash' => password_hash('Qt@12345', PASSWORD_BCRYPT),
        'full_name' => 'Quick Ticket Agent', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0,
    ]);
} else {
    Database::update('admins', ['role' => 'agent', 'is_active' => 1], 'id = :i', ['i' => $agentId]);
}
$staffRow = ['id' => $agentId, 'username' => 'qt-agent', 'role' => 'agent', 'full_name' => 'Quick Ticket Agent'];
$_SESSION[ADMIN_SESSION_KEY] = ['id' => $agentId, 'username' => 'qt-agent', 'role' => 'agent', 'permissions' => [], 'last_seen' => time(), 'logged_in_at' => time()];

$cleanup = static function () use ($dates, $agentId): void {
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . QT_PHONE . "%'") as $r) {
        $bid = (int) $r['id'];
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        Database::delete('bookings', 'id = :i', ['i' => $bid]);
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
        @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf');
    }
    foreach ($dates as $d) {
        foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $d]) as $s) {
            $sid = (int) $s['id'];
            Database::delete('seat_locks', 'schedule_id = :s', ['s' => $sid]);
            try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
            try { Database::delete('booking_seats', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
        }
        Database::delete('booking_legs', 'travel_date = :d', ['d' => $d]);
        Database::delete('schedules', 'travel_date = :d', ['d' => $d]);
    }
    try { Database::pdo()->exec('DELETE FROM rate_limits'); } catch (Throwable $e) {}
};
$cleanup();

$root = dirname(__DIR__);
$src  = static fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);

try {
    /* ================================================================
     *  1. plan() — the analyse step, on an explicit date
     * ================================================================ */
    echo "== plan(): route · date · pickup · seat · fare ==\n";
    $p = QuickTicket::plan(['date' => $D, 'direction' => 'toNepal']);
    check('picks the outbound route', $p['direction'] === 'toNepal' && (int) $p['routeId'] === $rid, $p['from'] . ' → ' . $p['to']);
    check('  on the asked date', $p['date'] === $D, $p['date']);
    check('  exactly one seat', count($p['seats']) === 1, implode(',', $p['seats']));
    check('  pickup label carries its time ("Name @ HH:MM")', preg_match('/@ \d\d:\d\d$/', (string) $p['boarding']) === 1, (string) $p['boarding']);
    $firstStop = (string) ($stops[0]['name'] ?? $out['from_city']);
    check('  first pickup by default', Boarding::townKey((string) $p['boarding']) === Boarding::townKey($firstStop), (string) $p['boardingName']);
    /* 26 Sep 2026: priced from the point board for THIS plan's own pickup,
       not from one number per direction — a Surat pickup and an Ahmedabad
       one no longer cost the same. */
    $wantPP = Fare::pointFare((string) $p['boarding'], (string) $p['to']);
    check('  fare per seat = the board fare for this pickup',
        abs((float) $p['fare']['perSeat'] - $wantPP) < 0.01,
        (string) $p['fare']['perSeat'] . ' vs board ' . $wantPP);
    $q = Fare::quote((float) $p['fare']['perSeat'], 1, 0, 0, 0, '', '', $rid);
    check('  total = Fare::quote of that base', abs((float) $p['fare']['total'] - (float) $q['total']) < 0.01, (string) $p['fare']['total']);
    check('  no women-only berth for an unstated gender', !in_array($p['seats'][0], Seats::femaleSeats('sleeper'), true), $p['seats'][0]);
    check('  no staff / emergency berth', array_intersect($p['seats'], Seats::staffSeats('sleeper')) === []);
    check('  lower deck first', str_starts_with((string) $p['seats'][0], 'L'), $p['seats'][0]);
    check('  stops list present, every one "ahead" on a future date',
        is_array($p['stops']) && $p['stops'] !== [] && count(array_filter($p['stops'], static fn(array $s): bool => !$s['ahead'])) === 0,
        count($p['stops']) . ' stops');
    $p2 = QuickTicket::plan(['date' => $D, 'direction' => 'toNepal']);
    check('  planning is repeatable (reads only)', $p2['seats'] === $p['seats'] && $p2['boarding'] === $p['boarding']);
    check('  both directions offered to the desk', in_array('toNepal', $p['directions'], true), implode(',', $p['directions']));

    /* ================================================================
     *  2. direction + the desk's own pickup
     * ================================================================ */
    echo "\n== direction and the desk's pickup ==\n";
    if ($ret !== null) {
        $r = QuickTicket::plan(['date' => $D, 'direction' => 'toIndia']);
        check('return direction picks the return route', $r['direction'] === 'toIndia' && (int) $r['routeId'] === (int) $ret['id'], $r['from'] . ' → ' . $r['to']);
        /* Coming back it is the DROP that is the Gujarat end, so the board
           is asked for the route's own pair. */
        $wantRet = Fare::pointFare((string) $r['from'], (string) $r['to']);
        check('  priced at the board fare for the return pair',
            abs((float) $r['fare']['perSeat'] - $wantRet) < 0.01,
            (string) $r['fare']['perSeat'] . ' vs board ' . $wantRet);
    } else {
        echo "  SKIP  no active return route\n";
    }
    $lastStop = $stops !== [] ? $stops[count($stops) - 1] : null;
    if ($lastStop !== null && count($stops) > 1) {
        $town = (string) Boarding::stopDisplay((string) $lastStop['name'])['name'];   // e.g. "Mehsana"
        $m = QuickTicket::plan(['date' => $D, 'direction' => 'toNepal', 'boarding' => $town]);
        check('the desk pickup wins when the run calls there', $m['matchedDesk'] === true && Boarding::townKey((string) $m['boarding']) === Boarding::townKey((string) $lastStop['name']), (string) $m['boarding']);
        check('  full stop label works as the argument too', QuickTicket::plan(['date' => $D, 'direction' => 'toNepal', 'boarding' => (string) $lastStop['name']])['matchedDesk'] === true);
        Settings::set('quick_ticket_default_boarding', $town, 'string', 'booking', false);
        Settings::flush();
        $m2 = QuickTicket::plan(['date' => $D, 'direction' => 'toNepal']);
        check('  …and via the quick_ticket_default_boarding setting', $m2['matchedDesk'] === true, (string) $m2['boardingName']);
        Settings::set('quick_ticket_default_boarding', '', 'string', 'booking', false);
        Settings::flush();
        if ($ret !== null) {
            $a = QuickTicket::plan(['date' => $D, 'boarding' => $town]);
            check('auto direction follows the desk town', $a['direction'] === 'toNepal' && $a['matchedDesk'] === true, $a['from'] . ' → ' . $a['to']);
        }
        $x = QuickTicket::plan(['date' => $D, 'direction' => 'toNepal', 'boarding' => 'Timbuktu']);
        check('an unknown desk town falls back to the first pickup', $x['matchedDesk'] === false && Boarding::townKey((string) $x['boarding']) === Boarding::townKey($firstStop));
    } else {
        echo "  SKIP  route has fewer than two pickups\n";
    }

    /* ================================================================
     *  3. the automatic date
     * ================================================================ */
    echo "\n== auto date ==\n";
    $auto = QuickTicket::plan(['direction' => 'toNepal']);
    check('auto date is today or later', $auto['date'] >= $today, $auto['date'] . ($auto['isToday'] ? ' (today)' : ''));
    check('  its pickup is still ahead of the clock', $auto['departsInMin'] === null || $auto['departsInMin'] > 0, (string) $auto['departsInMin'] . ' min');
    check('  never a departed run for an auto date', $auto['departed'] === false);

    /* ================================================================
     *  4. gender-aware seating
     * ================================================================ */
    echo "\n== gender-aware seats ==\n";
    $f = QuickTicket::plan(['date' => $D, 'direction' => 'toNepal', 'gender' => 'Female']);
    check('a woman gets a seat', count($f['seats']) === 1, $f['seats'][0]);
    $mm = QuickTicket::plan(['date' => $D, 'direction' => 'toNepal', 'gender' => 'Male']);
    check('a man never gets a women-only berth', !in_array($mm['seats'][0], Seats::femaleSeats('sleeper'), true), $mm['seats'][0]);
    $pair = QuickTicket::plan(['date' => $D, 'direction' => 'toNepal', 'seats' => 2]);
    check('a pair shares one cabin', count($pair['seats']) === 2 && Seats::unitKey($pair['seats'][0]) === Seats::unitKey($pair['seats'][1]), implode(',', $pair['seats']));
    $wantPair = 2 * Fare::pointFare((string) $pair['boarding'], (string) $pair['to']);
    check('  priced for two', abs((float) $pair['fare']['base'] - $wantPair) < 0.01,
        (string) $pair['fare']['base'] . ' vs board ' . $wantPair);

    /* ================================================================
     *  5. refusals
     * ================================================================ */
    echo "\n== refusals ==\n";
    throws('a blank name is refused', fn() => QuickTicket::sell(['name' => '', 'phone' => QT_PHONE . '001', 'date' => $D], $staffRow), 'name');
    /* A blank mobile is a WALK-IN at the desk, not an error (owner ask,
       point 9: "allow the ticket to be created with Passenger Name only if
       the phone number is not available"). It sells, and create() stores the
       walk-in placeholder so the manifest stays honest. This suite used to
       assert the opposite — that the desk refuses it — which was the very
       behaviour the owner asked to change.
       The berth it takes is released immediately: leaving it would shift
       every seat-picking assertion below by one seat. */
    $walkIn = QuickTicket::sell(['name' => 'Quick Walk In', 'phone' => '', 'date' => $D], $staffRow);
    check('a blank mobile SELLS at the desk (walk-in, name only)', ($walkIn['pnr'] ?? '') !== '', (string) ($walkIn['pnr'] ?? ''));
    $walkPhone = (string) Database::scalar('SELECT contact_phone FROM bookings WHERE pnr = :p', ['p' => (string) $walkIn['pnr']], '');
    check('  …stored as the walk-in placeholder, not a real number', preg_match('/^0+$/', $walkPhone) === 1, $walkPhone);
    check('  …and the admin UI treats that as "no phone"', Notify::usablePhone($walkPhone) === '', $walkPhone);
    BookingService::cancel((string) $walkIn['pnr'], 'walk-in test cleanup', true);

    throws('a short mobile is still refused', fn() => QuickTicket::sell(['name' => 'Quick Pax', 'phone' => '12345', 'date' => $D], $staffRow), 'mobile');
    throws('a CUSTOMER with no mobile is still refused', fn() => QuickTicket::sell(['name' => 'Quick Pax', 'phone' => '', 'date' => $D], []), 'mobile');
    Seats::schedule($rid, $D2);
    Database::update('schedules', ['status' => 'cancelled'], 'route_id = :r AND travel_date = :d', ['r' => $rid, 'd' => $D2]);
    throws('a cancelled date cannot be planned', fn() => QuickTicket::plan(['date' => $D2, 'direction' => 'toNepal']), 'not running');
    check('no booking was written by the refusals', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . QT_PHONE . "%'") === 0);

    /* ================================================================
     *  6. sell() — the real thing
     * ================================================================ */
    echo "\n== sell(): confirmed ticket, PNG, WhatsApp ==\n";
    $res = QuickTicket::sell(['name' => 'Quick Test Pax', 'phone' => QT_PHONE . '001', 'date' => $D, 'direction' => 'toNepal', 'pay' => 'cash'], $staffRow);
    check('returns a PNR', preg_match('/^SHG-/i', (string) $res['pnr']) === 1, (string) $res['pnr']);
    $b = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $res['pnr']]) ?? [];
    check('  booking is CONFIRMED', ($b['status'] ?? '') === 'confirmed', (string) ($b['status'] ?? 'missing'));
    check('  sold by the agent, source agent', (int) ($b['sold_by_admin_id'] ?? 0) === $agentId && ($b['source'] ?? '') === 'agent');
    check('  contact phone stored normalised', ($b['contact_phone'] ?? '') === QT_PHONE . '001');
    $pay = Database::fetch('SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) ($b['id'] ?? 0)]) ?? [];
    check('  payment verified · cash · "Quick Ticket" note',
        ($pay['status'] ?? '') === 'verified' && ($pay['method'] ?? '') === 'cash' && str_starts_with((string) ($pay['admin_note'] ?? ''), 'Quick Ticket'),
        (string) ($pay['admin_note'] ?? ''));
    $leg = Database::fetch('SELECT * FROM booking_legs WHERE booking_id = :b LIMIT 1', ['b' => (int) ($b['id'] ?? 0)]) ?? [];
    check('  leg on the asked date with the planned pickup', ($leg['travel_date'] ?? '') === $D && ($leg['boarding_stop'] ?? '') === $res['boarding'], (string) ($leg['boarding_stop'] ?? ''));
    $pax = Database::fetch('SELECT * FROM booking_passengers WHERE booking_id = :b AND is_primary = 1', ['b' => (int) ($b['id'] ?? 0)]) ?? [];
    check('  passenger named on the planned seat', ($pax['full_name'] ?? '') === 'Quick Test Pax' && ($pax['seat_no'] ?? '') === ($res['seats'][0] ?? '-'), (string) ($pax['seat_no'] ?? ''));
    check('  a ticket row exists', (int) Database::scalar('SELECT COUNT(*) FROM tickets WHERE booking_id = :b', ['b' => (int) ($b['id'] ?? 0)]) === 1);
    check('  ticket number returned', $res['ticketNumber'] !== '', (string) $res['ticketNumber']);
    $png = TICKET_PATH . '/ticket_' . $res['pnr'] . '.png';
    check('  PNG rendered on the spot', $res['pngReady'] === true && is_file($png), $png);
    $info = @getimagesize($png);
    // The height grows with the passenger list (10 Sep 2026); 1620 is the floor.
    check('  1080 wide, at least 1620 tall', $info !== false && $info[0] === 1080 && $info[1] >= 1620, $info ? $info[0] . 'x' . $info[1] : 'unreadable');
    check('  keyed image link', str_contains((string) $res['imageUrl'], 'img=1&k='), (string) $res['imageUrl']);
    check('  inline view + print links', str_contains((string) $res['viewUrl'], '&view=1') && str_contains((string) $res['printUrl'], 'print=1'));
    check('  ticket in under 30 seconds', (int) $res['elapsedMs'] < 30000, $res['elapsedMs'] . ' ms');
    $wa = $res['whatsapp'];
    check('  WhatsApp was attempted', ($wa['attempted'] ?? false) === true, json_encode($wa));
    check('  …with a click-to-chat link to the passenger carrying the ticket',
        str_contains((string) $wa['link'], 'wa.me/91' . QT_PHONE . '001') && str_contains((string) $wa['link'], 'download-ticket'),
        (string) $wa['link']);
    check('  sent=false while no WhatsApp sender is configured', $wa['configured'] ? true : ($wa['sent'] === false), 'driver ' . $wa['driver'] . ', configured ' . json_encode($wa['configured']));
    check('  resolves the number as +91…', ($wa['to'] ?? '') === '+91' . QT_PHONE . '001', (string) ($wa['to'] ?? ''));

    /* ---- the second passenger fills the half-empty cabin ---------------- */
    $res2 = QuickTicket::sell(['name' => 'Quick Test Pax Two', 'phone' => QT_PHONE . '002', 'date' => $D, 'direction' => 'toNepal'], $staffRow);
    check('the second passenger gets a different seat', ($res2['seats'][0] ?? '') !== ($res['seats'][0] ?? ''), $res['seats'][0] . ' then ' . $res2['seats'][0]);
    check('  …in the same cabin (half-empty cabins fill first)', Seats::unitKey((string) $res2['seats'][0]) === Seats::unitKey((string) $res['seats'][0]));

    /* ---- a woman locks her cabin; the next man goes elsewhere ---------- */
    $resF = QuickTicket::sell(['name' => 'Quick Test Sita', 'phone' => QT_PHONE . '003', 'date' => $D, 'direction' => 'toNepal', 'gender' => 'Female'], $staffRow);
    $legF = Database::fetch('SELECT schedule_id FROM booking_legs WHERE booking_id = :b LIMIT 1', ['b' => (int) $resF['bookingId']]) ?? [];
    $unitF = Seats::unitKey((string) $resF['seats'][0]);
    $lock = (string) Database::scalar('SELECT gender_lock FROM schedule_unit_locks WHERE schedule_id = :s AND unit_key = :u', ['s' => (int) ($legF['schedule_id'] ?? 0), 'u' => $unitF], '');
    check("a woman's cabin is locked female_only", $lock === 'female_only', $resF['seats'][0] . ' / ' . $unitF . ' → ' . $lock);
    $resM = QuickTicket::sell(['name' => 'Quick Test Hari', 'phone' => QT_PHONE . '004', 'date' => $D, 'direction' => 'toNepal', 'gender' => 'Male'], $staffRow);
    check('the next man is seated in another cabin', Seats::unitKey((string) $resM['seats'][0]) !== $unitF, $resM['seats'][0]);

    /* ---- a Nepali number ------------------------------------------------ */
    $resN = QuickTicket::sell(['name' => 'Quick Test Nepali', 'phone' => '+977 ' . QT_PHONE . '005', 'date' => $D, 'direction' => 'toNepal'], $staffRow);
    $bN = Database::fetch('SELECT contact_phone, id_type FROM bookings WHERE pnr = :p', ['p' => $resN['pnr']]) ?? [];
    check('a +977 number is stored as its 10 digits', ($bN['contact_phone'] ?? '') === QT_PHONE . '005', (string) ($bN['contact_phone'] ?? ''));
    check('  flagged Nepali so WhatsApp dials +977', stripos((string) ($bN['id_type'] ?? ''), 'nepal') !== false && ($resN['whatsapp']['to'] ?? '') === '+977' . QT_PHONE . '005', (string) ($resN['whatsapp']['to'] ?? ''));

    /* ---- a party under one name ----------------------------------------- */
    $resP = QuickTicket::sell(['name' => 'Quick Test Family', 'phone' => QT_PHONE . '006', 'date' => $D3, 'direction' => 'toNepal', 'seats' => 2], $staffRow);
    check('a party of two gets two seats', count($resP['seats']) === 2, implode(',', $resP['seats']));
    $names = array_map('strval', pluck(Database::fetchAll('SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY is_primary DESC, id', ['b' => (int) $resP['bookingId']]), 'full_name'));
    check('  second passenger numbered after the buyer', $names === ['Quick Test Family', 'Quick Test Family (2)'], implode(' | ', $names));
    /* The invariant worth guarding is not a figure: it is that the bot
       CHARGED what it QUOTED. Ask the planner for the same journey and
       compare the sale against its own quote. */
    $planP = QuickTicket::plan(['date' => $D3, 'direction' => 'toNepal', 'seats' => 2]);
    check('  charged exactly what the plan quoted for two',
        abs((float) $resP['total'] - (float) $planP['fare']['total']) < 0.01,
        (string) $resP['total'] . ' vs quoted ' . $planP['fare']['total']);

    /* ================================================================
     *  6b. the PASSENGER's own Quick Ticket (6 Sep 2026, "for everyone")
     * ================================================================ */
    echo "\n== sellCustomer(): the passenger's own Quick Ticket ==\n";
    $savedAdmin = $_SESSION[ADMIN_SESSION_KEY] ?? null;
    unset($_SESSION[ADMIN_SESSION_KEY]);                       // a passenger has no staff session
    $cap = max(1, Settings::getInt('max_seats_per_booking', MAX_SEATS_BOOKING));
    $cp  = QuickTicket::plan(['customer' => true, 'date' => $DC, 'direction' => 'toNepal']);
    check('a customer plan on a date inside the horizon', $cp['date'] === $DC && count($cp['seats']) === 1 && $cp['departed'] === false, $cp['boarding']);
    check('  the plan carries the date window for the app\'s date control', ($cp['window']['from'] ?? '') >= $today && ($cp['window']['to'] ?? '') === bookingWindow()['to'] && ($cp['customer'] ?? false) === true, json_encode($cp['window'] ?? null));
    /* One-click default (6 Sep 2026): the passenger's card comes up on the
       company yard (S Hari Parking, Nana Chiloda) — its own setting, so the
       desks keep "first pickup ahead". A town word is enough to match. */
    $yard = null;
    foreach ($stops as $s) { if (Boarding::stopDisplay((string) $s['name'])['code'] === 'AMD') { $yard = $s; break; } }
    if ($yard !== null) {
        Settings::set('quick_ticket_customer_boarding', 'S Hari Parking', 'string', 'booking', false); Settings::flush();
        $cy = QuickTicket::plan(['customer' => true, 'date' => $DC, 'direction' => 'toNepal']);
        check('the passenger default pickup is S Hari Parking (AMD) when the run calls there', $cy['matchedDesk'] === true && $cy['boardingCode'] === 'AMD', (string) $cy['boarding']);
        Settings::set('quick_ticket_default_boarding', '', 'string', 'booking', false); Settings::flush();
        $cs = QuickTicket::plan(['date' => $DC, 'direction' => 'toNepal']);
        check('  …while the desk default stays the first pickup ahead', $cs['matchedDesk'] === false && Boarding::townKey((string) $cs['boarding']) === Boarding::townKey($firstStop), (string) $cs['boarding']);
        $ce = QuickTicket::plan(['customer' => true, 'date' => $DC, 'direction' => 'toNepal', 'boarding' => $firstStop]);
        check('  an explicit pickup still wins over the default', Boarding::townKey((string) $ce['boarding']) === Boarding::townKey($firstStop));
        Settings::set('quick_ticket_customer_boarding', '', 'string', 'booking', false); Settings::flush();
    } else {
        echo "  SKIP  route has no AMD (S Hari Parking) pickup in this database\n";
    }
    check('  the public seat cap holds (' . $cap . ')', count(QuickTicket::plan(['customer' => true, 'date' => $DC, 'direction' => 'toNepal', 'seats' => 9])['seats']) === $cap);
    throws('  a past date is refused for a passenger', fn() => QuickTicket::plan(['customer' => true, 'date' => addDaysISO($today, -1), 'direction' => 'toNepal']), 'later date');
    $beyond = addDaysISO(bookingWindow()['to'], 1);
    throws('  a date beyond the horizon (' . $beyond . ') is refused for a passenger', fn() => QuickTicket::plan(['customer' => true, 'date' => $beyond, 'direction' => 'toNepal']), 'open up to');
    throws('  a cancelled date is refused for a passenger', fn() => QuickTicket::plan(['customer' => true, 'date' => $D2, 'direction' => 'toNepal']), 'not running');
    $resC = QuickTicket::sellCustomer(['name' => 'Quick Cust', 'phone' => QT_PHONE . '007', 'date' => $DC, 'direction' => 'toNepal'], null);
    $bC   = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $resC['pnr']]) ?? [];
    check('the passenger\'s booking is CONFIRMED on the spot (pay on boarding)', ($bC['status'] ?? '') === 'confirmed' && (int) ($bC['is_cod'] ?? 0) === 1, (string) ($bC['status'] ?? 'missing'));
    check('  source web, no seller attributed', ($bC['source'] ?? '') === 'web' && empty($bC['sold_by_admin_id']), (string) ($bC['source'] ?? ''));
    $payC = Database::fetch('SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) ($bC['id'] ?? 0)]) ?? [];
    check('  cash collected by the crew: payment cod / cod_pending', ($payC['method'] ?? '') === 'cod' && ($payC['status'] ?? '') === 'cod_pending', ($payC['method'] ?? '') . ' / ' . ($payC['status'] ?? ''));
    check('  ticket row + PNG rendered on the spot', (int) Database::scalar('SELECT COUNT(*) FROM tickets WHERE booking_id = :b', ['b' => (int) ($bC['id'] ?? 0)]) === 1 && $resC['pngReady'] === true && is_file(TICKET_PATH . '/ticket_' . $resC['pnr'] . '.png'));
    check('  keyed PNG + PDF links for instant download', str_contains((string) $resC['imageUrl'], 'img=1&k=') && str_contains((string) $resC['pdfUrl'], 'download-ticket.php?pnr=') && str_contains((string) $resC['pdfUrl'], '&k='), (string) $resC['pdfUrl']);
    check('  the app payload (track.php shape) carries PNR, leg and ticket link', ($resC['customer']['pnr'] ?? '') === $resC['pnr'] && !empty($resC['customer']['legs']) && !empty($resC['customer']['ticketUrl']) && ($resC['customer']['status'] ?? '') === 'confirmed');
    check('  priced exactly as the checkout would (Fare::quote)', abs((float) $resC['total'] - (float) $q['total']) < 0.01, (string) $resC['total']);
    check('  the passenger is told which number the ticket went to', ($resC['whatsapp']['to'] ?? '') !== '' && array_key_exists('sent', $resC['whatsapp']), (string) ($resC['whatsapp']['to'] ?? '—'));
    check('  no admin link leaks to a passenger', $resC['adminUrl'] === null);
    check('  under 10 seconds', (int) $resC['elapsedMs'] < 10000, $resC['elapsedMs'] . ' ms');
    throws('a second Quick Ticket on the same bus within the hour is refused', fn() => QuickTicket::sellCustomer(['name' => 'Quick Cust', 'phone' => QT_PHONE . '007', 'date' => $DC, 'direction' => 'toNepal'], null), 'already have a ticket');

    /* ---- one click, pinned to the card (6 Sep 2026) --------------------- */
    echo "\n== one click: pinned sale · cut-off parity · free undo ==\n";
    $pin = QuickTicket::plan(['customer' => true, 'date' => $DC, 'direction' => 'toNepal']);
    $okExpect = ['date' => $pin['date'], 'direction' => $pin['direction'], 'boardingCode' => $pin['boardingCode'], 'seats' => $pin['seatCount'], 'total' => $pin['fare']['total']];
    try {
        QuickTicket::sellCustomer(['name' => 'Quick Pinned', 'phone' => QT_PHONE . '013', 'date' => $DC, 'direction' => 'toNepal', 'expect' => array_merge($okExpect, ['total' => $pin['fare']['total'] + 500])], null);
        check('a sale whose card showed another fare is refused (plan_changed)', false, 'no exception');
    } catch (QuickTicketPlanChanged $e) {
        check('a sale whose card showed another fare is refused (plan_changed)', str_contains($e->getMessage(), 'total') && is_array($e->plan) && $e->plan['date'] === $DC, mb_substr($e->getMessage(), 0, 70));
    }
    try {
        QuickTicket::sellCustomer(['name' => 'Quick Pinned', 'phone' => QT_PHONE . '013', 'date' => $DC, 'direction' => 'toNepal', 'expect' => array_merge($okExpect, ['boardingCode' => 'ZZZ'])], null);
        check('  …or another pickup', false, 'no exception');
    } catch (QuickTicketPlanChanged $e) {
        check('  …or another pickup', str_contains($e->getMessage(), 'boarding'));
    }
    check('  nothing was sold by the refused pins', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone = :p", ['p' => QT_PHONE . '013']) === 0);
    $resPin = QuickTicket::sellCustomer(['name' => 'Quick Pinned', 'phone' => QT_PHONE . '013', 'date' => $DC, 'direction' => 'toNepal', 'expect' => $okExpect], null);
    check('the same facts as the card → sold, on the pinned day and pickup', $resPin['status'] === 'confirmed' && $resPin['date'] === $DC && $resPin['boardingCode'] === $pin['boardingCode'] && (int) $resPin['undoMin'] >= 1, 'undo ' . $resPin['undoMin'] . ' min');
    $autoC = QuickTicket::plan(['customer' => true, 'direction' => 'toNepal']);
    check('cut-off parity: a passenger is never offered a pickup inside the boarding cut-off',
        !$autoC['isToday'] || $autoC['departsInMin'] === null || $autoC['departsInMin'] >= Boarding::cutoffMinutes(),
        ($autoC['isToday'] ? 'today · ' : 'later · ') . $autoC['departsInMin'] . ' min ahead, cut-off ' . Boarding::cutoffMinutes());
    // undo: owner only, inside the window, pay-on-boarding only
    $owner = ['id' => 1, 'phone' => QT_PHONE . '013', 'full_name' => 'Quick Pinned', 'role' => 'customer', 'last_seen' => time()];
    throws('undo is refused to a session that is not the ticket\'s number', fn() => QuickTicket::customerUndo($resPin['pnr'], ['id' => 2, 'phone' => QT_PHONE . '099', 'last_seen' => time()]), 'not on this mobile');
    throws('  …and to a guest', fn() => QuickTicket::customerUndo($resPin['pnr'], null), 'Sign in');
    $jBefore = count(Notify::whatsappJournal());
    $u = QuickTicket::customerUndo($resPin['pnr'], $owner);
    // Snapshot the undo's own messages HERE: the journal is request-scoped and
    // the next sale below appends its confirmation to the same passenger.
    $jUndo = array_slice(Notify::whatsappJournal(), $jBefore);
    $bU = Database::fetch('SELECT status FROM bookings WHERE pnr = :p', ['p' => $resPin['pnr']]) ?? [];
    check('the owner undoes a mistaken tap free within the window', $u['status'] === 'cancelled' && ($bU['status'] ?? '') === 'cancelled' && (float) $u['refundAmount'] === 0.0, 'refund ' . $u['refundAmount']);
    check('  the berth is released', (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b AND released_at IS NULL', ['b' => (int) $resPin['bookingId']]) === 0);
    throws('  a second undo of the same ticket is refused', fn() => QuickTicket::customerUndo($resPin['pnr'], $owner), 'undo');
    $resOld = QuickTicket::sellCustomer(['name' => 'Quick Pinned', 'phone' => QT_PHONE . '013', 'date' => $DC, 'direction' => 'toNepal'], null);
    check('after an undo the same passenger may book the bus again', $resOld['status'] === 'confirmed');
    Database::update('bookings', ['created_at' => date('Y-m-d H:i:s', time() - 3 * 3600)], 'pnr = :p', ['p' => $resOld['pnr']]);
    throws('an old ticket is past the free undo window (use Cancel)', fn() => QuickTicket::customerUndo($resOld['pnr'], $owner), 'window');

    /* ---- quiet undo (7 Sep 2026): one short line to the passenger, no ₹0 refund page to the office ---- */
    $toPax = array_filter($jUndo, static fn(array $j): bool => str_ends_with((string) $j['to'], QT_PHONE . '013'));
    $toOff = array_filter($jUndo, static fn(array $j): bool => str_ends_with((string) $j['to'], '9100009999'));
    check('quiet undo: the passenger gets one short undo line and the office is not paged for a ₹0 refund', count($toPax) === 1 && count($toOff) === 0, count($jUndo) . ' message(s) queued');
    $bell = Database::fetch("SELECT title FROM notifications WHERE booking_id = :b AND audience = 'admin' ORDER BY id DESC LIMIT 1", ['b' => (int) $resPin['bookingId']]) ?? [];
    check('  the office bell says "undone", not "cancelled"', str_contains((string) ($bell['title'] ?? ''), 'undone'), (string) ($bell['title'] ?? '—'));
    $bR = Database::fetch('SELECT cancel_reason FROM bookings WHERE pnr = :p', ['p' => $resPin['pnr']]) ?? [];
    check('  the register keeps the undo note', ($bR['cancel_reason'] ?? '') === QuickTicket::UNDO_NOTE, (string) ($bR['cancel_reason'] ?? '—'));
    /* The quiet path is chosen by the REFUND, not by the label. If the desk
       already took the cash (settleCod verifies the COD payment) an undo owes
       real money, so the passenger and the office must get the ordinary cancel
       copy — a silent "nothing to pay" would strand the refund. */
    $resCash = QuickTicket::sellCustomer(['name' => 'Quick Pinned', 'phone' => QT_PHONE . '013', 'date' => $DC2, 'direction' => 'toNepal'], null);
    Database::update('payments', ['status' => 'verified'], 'booking_id = :b', ['b' => (int) $resCash['bookingId']]);
    $jCashBefore = count(Notify::whatsappJournal());
    $uCash = QuickTicket::customerUndo($resCash['pnr'], $owner);
    $jCash = array_slice(Notify::whatsappJournal(), $jCashBefore);
    check('an undo after the counter took the cash refunds real money', (float) $uCash['refundAmount'] > 0, inr((float) $uCash['refundAmount']));
    check('  …so the passenger AND the office get the ordinary cancel copy, not "nothing to pay"',
        count(array_filter($jCash, static fn(array $j): bool => str_ends_with((string) $j['to'], QT_PHONE . '013'))) === 1
        && count(array_filter($jCash, static fn(array $j): bool => str_ends_with((string) $j['to'], '9100009999'))) === 1, count($jCash) . ' queued');
    $bellCash = Database::fetch("SELECT title FROM notifications WHERE booking_id = :b AND audience = 'admin' ORDER BY id DESC LIMIT 1", ['b' => (int) $resCash['bookingId']]) ?? [];
    check('  …and the bell says cancelled, so the refund is actioned', str_contains((string) ($bellCash['title'] ?? ''), 'cancelled'), (string) ($bellCash['title'] ?? '—'));

    $jBefore2 = count(Notify::whatsappJournal());
    BookingService::cancel($resOld['pnr'], 'Changed plans', true);
    $jNew2 = array_slice(Notify::whatsappJournal(), $jBefore2);
    check('a normal cancel still tells the passenger AND pages the office',
        count(array_filter($jNew2, static fn(array $j): bool => str_ends_with((string) $j['to'], QT_PHONE . '013'))) === 1
        && count(array_filter($jNew2, static fn(array $j): bool => str_ends_with((string) $j['to'], '9100009999'))) === 1, count($jNew2) . ' queued');

    /* ---- open pay-on-boarding cap per number (7 Sep 2026) ---- */
    echo "\n== QuickBot: unpaid one-tap tickets per number are capped ==\n";
    Settings::set('quick_ticket_max_open', 1, 'int', 'booking', false); Settings::flush();
    $resCap1 = QuickTicket::sellCustomer(['name' => 'Quick Capped', 'phone' => QT_PHONE . '017', 'date' => $DC, 'direction' => 'toNepal'], null);
    check('the first pay-on-boarding ticket sells', $resCap1['status'] === 'confirmed');
    throws('  a second unpaid one on another day is refused at the cap (1)', fn() => QuickTicket::sellCustomer(['name' => 'Quick Capped', 'phone' => QT_PHONE . '017', 'date' => $DC2, 'direction' => 'toNepal'], null), 'not paid yet');
    Database::update('payments', ['status' => 'verified'], 'booking_id = :b', ['b' => (int) $resCap1['bookingId']]);   // the counter collected the fare
    $resCap2 = QuickTicket::sellCustomer(['name' => 'Quick Capped', 'phone' => QT_PHONE . '017', 'date' => $DC2, 'direction' => 'toNepal'], null);
    check('  once the counter has collected the fare the number may book again', $resCap2['status'] === 'confirmed');
    throws('  …and is capped again on the next unpaid one', fn() => QuickTicket::sellCustomer(['name' => 'Quick Capped', 'phone' => QT_PHONE . '017', 'date' => $DC3, 'direction' => 'toNepal'], null), 'not paid yet');
    Settings::set('quick_ticket_max_open', 0, 'int', 'booking', false); Settings::flush();
    $resCap3 = QuickTicket::sellCustomer(['name' => 'Quick Capped', 'phone' => QT_PHONE . '017', 'date' => $DC3, 'direction' => 'toNepal'], null);
    check('  0 switches the cap off', $resCap3['status'] === 'confirmed');
    /* Pin the cap at 1 while this number ALREADY holds unpaid one-tap tickets:
       if the desk were counted or capped the sale below would be refused, so
       the check fails the moment the staff exemption is removed. */
    Settings::set('quick_ticket_max_open', 1, 'int', 'booking', false); Settings::flush();
    $openNow = (int) Database::scalar("SELECT COUNT(*) FROM bookings b JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound' WHERE b.contact_phone = :p AND b.status = 'confirmed' AND b.is_cod = 1 AND l.travel_date >= CURDATE() AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status = 'verified')", ['p' => QT_PHONE . '017']);
    check('  the number is over the cap before the desk tries', $openNow >= 1, $openNow . ' unpaid');
    $resDesk = QuickTicket::sell(['name' => 'Desk Many', 'phone' => QT_PHONE . '017', 'date' => $DC, 'direction' => 'toIndia', 'pay' => 'cash'], $staffRow);
    check('  the desk is never counted or capped — it sells on a number already over the cap', $resDesk['status'] === 'confirmed');
    throws('  …while the same number is still refused at the passenger door', fn() => QuickTicket::sellCustomer(['name' => 'Quick Capped', 'phone' => QT_PHONE . '017', 'date' => $DC2, 'direction' => 'toIndia'], null), 'not paid yet');
    Settings::set('quick_ticket_max_open', 3, 'int', 'booking', false); Settings::flush();

    /* ---- Twilio sender breaker (7 Sep 2026): an account-level refusal pauses the sender, never the ticket ---- */
    echo "\n== WhatsApp sender breaker ==\n";
    check('20003 / 20005 / 20008 and a bare 401 condemn the account', Notify::twilioAccountBlocked(401, 20003) && Notify::twilioAccountBlocked(401, 20005) && Notify::twilioAccountBlocked(403, 20008) && Notify::twilioAccountBlocked(401, 0));
    check('  a per-message error (bad number 21211, sandbox 63015, template 21654) does not', !Notify::twilioAccountBlocked(400, 21211) && !Notify::twilioAccountBlocked(400, 63015) && !Notify::twilioAccountBlocked(400, 21654));
    check('  no pause to begin with', Notify::twilioPause() === null);
    Settings::set('whatsapp_driver', 'twilio', 'string', 'notify', false);
    Settings::set('twilio_account_sid', 'ACtest', 'string', 'notify', false);
    Settings::set('twilio_auth_token', 'tok', 'string', 'notify', false);
    Settings::set('twilio_whatsapp_from', '+14155238886', 'string', 'notify', false);
    Settings::flush();
    Notify::twilioPauseSet(401, 20003, 'Primary compliance profile is not approved.');
    $pz = Notify::twilioPause();
    check('  a KYC refusal pauses the sender for ~15 min', $pz !== null && $pz['code'] === 20003 && $pz['until'] > time() + 600, $pz ? 'until ' . date('H:i', $pz['until']) : 'null');
    /* Read kv_store itself, not the in-process memo: the pause has to survive
       into the NEXT request (and the cron) or the breaker does nothing on live. */
    $kvRow = Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = 'notify.twilio_wa.pause' LIMIT 1");
    $kvVal = is_string($kvRow) ? json_decode($kvRow, true) : null;
    check('  the pause is persisted in kv_store, so the next request sees it too', is_array($kvVal) && (int) ($kvVal['code'] ?? 0) === 20003 && (int) ($kvVal['until'] ?? 0) > time(), is_string($kvRow) ? 'row present' : 'no row');
    $t = microtime(true);
    $r = Notify::whatsapp(QT_PHONE . '018', 'breaker probe');
    $ms = (int) round((microtime(true) - $t) * 1000);
    $jl = Notify::whatsappJournal();
    $last = $jl[count($jl) - 1] ?? [];
    check('  while paused a send skips Twilio and hands back the click-to-chat link at once', is_string($r) && str_contains($r, 'wa.me') && ($last['reason'] ?? '') === 'paused' && $ms < 150, $ms . ' ms · ' . ($last['reason'] ?? '?'));
    Notify::twilioPauseSet(401, 20003, 'Primary compliance profile is not approved.');
    $stDesk = QuickTicket::whatsappStatus(['pnr' => $resNone['pnr'] ?? 'X', 'contact_phone' => QT_PHONE . '013', 'total_amount' => 0], QT_PHONE . '013');
    check('  the desk is told the sender is paused, why and until when', ($stDesk['paused'] ?? false) === true && str_contains((string) $stDesk['pausedWhy'], 'compliance') && preg_match('/^\d{2}:\d{2}$/', (string) $stDesk['pausedUntil']) === 1, (string) $stDesk['pausedUntil']);
    Settings::set('whatsapp_driver', 'cloud_api', 'string', 'notify', false); Settings::flush();
    $stCloud = QuickTicket::whatsappStatus(['pnr' => 'X', 'contact_phone' => QT_PHONE . '013', 'total_amount' => 0], QT_PHONE . '013');
    check('  a leftover Twilio pause never speaks for another driver', ($stCloud['paused'] ?? true) === false && ($stCloud['pausedWhy'] ?? 'x') === '');
    Notify::twilioResume();
    check('  a good test send lifts the pause', Notify::twilioPause() === null);
    check('  …and clears the kv_store row with it', Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = 'notify.twilio_wa.pause' LIMIT 1") === null);
    Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false); Settings::flush();

    /* ---- QuickBot (7 Sep 2026): optional agent code on a passenger's sale ---- */
    echo "\n== QuickBot: agent code is optional, never blocking ==\n";
    $resNoCode = QuickTicket::sellCustomer(['name' => 'Quick Direct', 'phone' => QT_PHONE . '014', 'date' => $DC, 'direction' => 'toNepal', 'agentCode' => 'SHG-9999'], null);
    $bNo = Database::fetch('SELECT sold_by_admin_id, referral_code, status FROM bookings WHERE pnr = :p', ['p' => $resNoCode['pnr']]) ?? [];
    check('an unknown agent code still books — as a direct sale', ($bNo['status'] ?? '') === 'confirmed' && empty($bNo['sold_by_admin_id']) && ($resNoCode['agent']['applied'] ?? true) === false, json_encode($resNoCode['agent']));
    $resNone = QuickTicket::sellCustomer(['name' => 'Quick Plain', 'phone' => QT_PHONE . '015', 'date' => $DC, 'direction' => 'toNepal'], null);
    check('  no code at all → no agent notice', $resNone['status'] === 'confirmed' && $resNone['agent'] === null);
    $codedAgent = null;
    foreach (Database::fetchAll("SELECT id FROM admins WHERE role = 'agent' AND is_active = 1 AND (locked_until IS NULL OR locked_until < NOW()) ORDER BY id") as $ag) {
        if (AgentWallet::agentCodeFor((int) $ag['id']) !== null) { $codedAgent = (int) $ag['id']; break; }
    }
    if ($codedAgent !== null) {
        $label  = AgentWallet::agentCodeLabel($codedAgent);
        $resAg  = QuickTicket::sellCustomer(['name' => 'Quick Referred', 'phone' => QT_PHONE . '016', 'date' => $DC, 'direction' => 'toNepal', 'agentCode' => strtolower($label)], null);
        $bAg    = Database::fetch('SELECT sold_by_admin_id FROM bookings WHERE pnr = :p', ['p' => $resAg['pnr']]) ?? [];
        check('a valid agent code (any case) attributes the passenger\'s sale to that agent', (int) ($bAg['sold_by_admin_id'] ?? 0) === $codedAgent && ($resAg['agent']['applied'] ?? false) === true && ($resAg['agent']['label'] ?? '') === $label, $label);
    } else {
        echo "  SKIP  no agent with an assigned code in this database\n";
    }
    throws('a blank name is refused', fn() => QuickTicket::sellCustomer(['name' => '', 'phone' => QT_PHONE . '008', 'date' => $DC], null), 'name');
    throws('a short mobile is refused', fn() => QuickTicket::sellCustomer(['name' => 'Quick Cust', 'phone' => '12345', 'date' => $DC], null), 'mobile');
    $_SESSION[USER_SESSION_KEY] = ['id' => 1, 'phone' => QT_PHONE . '009', 'full_name' => 'Session Pax', 'role' => 'customer', 'last_seen' => time()];
    check('a passenger is never shown the office\'s provider, its refusal text or the desk\'s send link',
        array_keys($resNone['whatsapp']) === ['sent', 'to'], implode(',', array_keys($resNone['whatsapp'])));
    $deskWa = QuickTicket::sell(['name' => 'Desk Wa', 'phone' => QT_PHONE . '019', 'date' => $D, 'direction' => 'toNepal', 'pay' => 'cash'], $staffRow)['whatsapp'];
    check('  …while the desk still gets the whole delivery picture', isset($deskWa['driver'], $deskWa['configured'], $deskWa['link'], $deskWa['paused']));
    $resS = QuickTicket::sellCustomer(['name' => 'Session Pax', 'phone' => QT_PHONE . '010', 'date' => $DC, 'direction' => 'toNepal'], Auth::user());
    check('a signed-in passenger books against their OWN number, whatever the form says', (string) Database::scalar('SELECT contact_phone FROM bookings WHERE pnr = :p', ['p' => $resS['pnr']]) === QT_PHONE . '009');
    unset($_SESSION[USER_SESSION_KEY]);
    Settings::set('allow_cod', false, 'bool', 'payment', true); Settings::flush();
    $resP = QuickTicket::sellCustomer(['name' => 'Quick Pending', 'phone' => QT_PHONE . '011', 'date' => $DC, 'direction' => 'toNepal'], null);
    check('with pay-on-boarding OFF the booking is PENDING with payment targets, no ticket yet', $resP['status'] === 'pending' && $resP['imageUrl'] === null && isset($resP['payment']['upiLink']) && (int) Database::scalar('SELECT COUNT(*) FROM tickets WHERE booking_id = :b', ['b' => (int) $resP['bookingId']]) === 0, (string) $resP['status']);
    Settings::set('allow_cod', true, 'bool', 'payment', true); Settings::flush();
    Settings::set('quick_ticket_customer_on', false, 'bool', 'booking', true); Settings::flush();
    throws('self-service can be switched off in Settings', fn() => QuickTicket::sellCustomer(['name' => 'Quick Off', 'phone' => QT_PHONE . '012', 'date' => $DC], null), 'desks only');
    Settings::set('quick_ticket_customer_on', true, 'bool', 'booking', true); Settings::flush();
    if ($savedAdmin !== null) { $_SESSION[ADMIN_SESSION_KEY] = $savedAdmin; }

    /* ================================================================
     *  7. static mirrors — the surfaces around the engine
     * ================================================================ */
    echo "\n== static mirrors ==\n";
    check('api admits passengers through customer_plan / customer_sell', str_contains($src('api/quick-ticket.php'), "'customer_sell'") && str_contains($src('api/quick-ticket.php'), 'sellCustomer'));
    check('the app banner plans + confirms in place', str_contains($src('app.template.html'), 'id="qtPlanCard"') && str_contains($src('assets/js/05-router.js'), "action: 'customer_sell'") && str_contains($src('assets/js/05-router.js'), "'#/ticket/'"));
    check('the desk offers PNG + PDF downloads', str_contains($src('admin/quick-ticket.php'), 'Download PNG') && str_contains($src('admin/quick-ticket.php'), 'Download PDF'));
    check('api/quick-ticket.php admits selling staff only', str_contains($src('api/quick-ticket.php'), 'isSellingStaff') && str_contains($src('api/quick-ticket.php'), 'requireCsrf'));
    check('admin nav carries the ⚡ Quick Ticket entry (hot)', str_contains($src('admin/_guard.php'), "'href' => 'quick-ticket.php'") && str_contains($src('admin/_guard.php'), "'hot' => true"));
    check('admin dashboard links the desk', str_contains($src('admin/index.php'), '/admin/quick-ticket.php'));
    check('the desk page gates on bookings.view and reads QuickTicket', str_contains($src('admin/quick-ticket.php'), "admin_boot('bookings.view')") && str_contains($src('admin/quick-ticket.php'), '/api/quick-ticket.php'));
    check('the app home highlights the Quick Ticket Service', str_contains($src('app.template.html'), 'id="quickTicket"') && str_contains($src('app.template.html'), 'data-scroll="quick-ticket"'));
    $i18n = $src('assets/js/04-i18n.js');
    foreach (['qtBadge', 'qtTitle', 'qtSub', 'qtNamePh', 'qtPhonePh', 'qtCta', 'qtS1', 'qtS2', 'qtS3', 'qtErr', 'qtSent', 'qtWaTail'] as $k) {
        check("i18n key $k in en / hi / ne", substr_count($i18n, ' ' . $k . ': ') === 3, substr_count($i18n, ' ' . $k . ': ') . ' found');
    }
    check('the banner is wired at boot', str_contains($src('assets/js/05-router.js'), 'function initQuickTicket') && str_contains($src('assets/js/13-admin-routes.js'), 'initQuickTicket()'));
    check('counter mode links the desk', str_contains($src('assets/js/14-counter.js'), '/admin/quick-ticket.php'));
    check('run-all lists this suite', str_contains($src('tests/run-all.php'), 'quick-ticket-test.php'));
    check('role-gates walks the desk page + API', str_contains($src('tests/role-gates-test.php'), '/admin/quick-ticket.php') && str_contains($src('tests/role-gates-test.php'), '/api/quick-ticket.php'));
    check('Notify keeps the WhatsApp journal the desk reads', str_contains($src('includes/notify.php'), 'function whatsappJournal') && str_contains($src('includes/notify.php'), 'function whatsappNumberFor'));
    check('the undo travels as via=undo: cancel() → events → bookingUndone', str_contains($src('includes/booking.php'), "string \$via = ''") && str_contains($src('includes/events.php'), "=== 'undo'") && str_contains($src('includes/notify.php'), 'function bookingUndone'));
    check('the migration seeds the open-ticket cap and the undo window', str_contains($src('database/upgrade-2026-09-quick-ticket-bot.php'), "'quick_ticket_max_open'") && str_contains($src('database/upgrade-2026-09-quick-ticket-bot.php'), "'quick_ticket_undo_min'"));
    check('the sender breaker guards the Twilio call and the test tool lifts it', str_contains($src('includes/notify.php'), 'twilioPause() !== null') && str_contains($src('includes/notify.php'), 'self::twilioResume();'));
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    $restoreSettings();
    unset($_SESSION[ADMIN_SESSION_KEY]);
}

echo "\n----------------------------------------\n";
echo "$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
