<?php
/**
 * =====================================================================
 *  op5-busswap-test.php — one-click bus swap + tell every passenger once (25 Nov 2026).
 *
 *  Drives includes/busswap.php (BusSwap::preview / swap) on throw-away rows:
 *  own buses (OP5SW-*), one far-future sleeper departure, four bookings
 *  (three confirmed with tickets, one pending) and a live_trips row.
 *
 *   A smaller bus      → preview lists who has no seat; swap refused, nothing written
 *   B seater bus       → refused (seat namespaces differ)
 *   C bus on another trip that day → refused
 *   D cancelled / arrived trip     → refused; DEPARTED (breakdown) allowed
 *   E swap A → B       → bus_id + total_seats, live_trips follows, tickets re-minted
 *                        (REV +1, QR names the new bus, old PNG replaced), boarded
 *                        mark kept, every confirmed passenger told once, crew told
 *   F press again      → nothing resent, no new REV (idempotent)
 *   G one send fails   → a re-press resends only that one
 *   H bus_swap_notify_on = 0 → tickets still redrawn, no message
 *   I Notify wording   → "तपाईंको बस नम्बर परिवर्तन भयो: <old> -> <new>, सिट उही, समय …, PNR …"
 *
 *    php -c .claude/php-dev.ini tests/op5-busswap-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---------- child: render one admin page as a superadmin (GET only) ---------- */
if (($argv[1] ?? '') === '--run') {
    $page = (string) $argv[2];
    $get  = json_decode((string) base64_decode((string) ($argv[3] ?? ''), true), true) ?: [];
    $root = dirname(__DIR__);
    require_once $root . '/includes/bootstrap.php';
    if (DB_NAME !== 'shari_test') { fwrite(STDERR, "refusing\n"); exit(2); }
    $_SESSION = [];
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => 1, 'username' => 'superadmin', 'full_name' => 'op5 test', 'role' => 'superadmin',
        'permissions' => [], 'last_seen' => time(), 'qk_ok' => true];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/' . $page;
    $_SERVER['REQUEST_URI'] = '/' . $page . '?' . http_build_query($get);
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_GET = $get; $_REQUEST = $get;
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        echo "\n@@META@@" . json_encode(['err' => $err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) ? $err['message'] : null]);
    });
    try {
        require $root . '/' . $page;
    } catch (Throwable $e) {
        echo "\nUNCAUGHT " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    exit(0);
}
function op5_page(string $p, array $get): string {
    $cmd = '"' . PHP_BINARY . '" -c "' . php_ini_loaded_file() . '" "' . __FILE__ . '" --run ' . $p . ' ' . base64_encode(json_encode($get) ?: '{}');
    return (string) shell_exec($cmd . ' 2>&1');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/livetrips.php';
require_once INCLUDE_PATH . '/busswap.php';

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function refused(string $l, callable $fn): void {
    try { $fn(); check($l . ' (expected refusal)', false); }
    catch (Throwable $e) { check($l . ' → ' . mb_substr($e->getMessage(), 0, 90), true); }
}

const OP5_DATE = '2098-03-17';
const OP5_TAG  = 'OP5SW';
$tag = strtoupper(bin2hex(random_bytes(2)));

$made = ['buses' => [], 'bookings' => [], 'pnrs' => [], 'sched' => [], 'driver' => 0, 'live' => 0];

function op5_bus(string $no, string $coach, int $seats): int {
    global $made;
    $id = Database::insert('buses', ['bus_number' => $no, 'bus_name' => 'Op5 ' . $no, 'coach_type' => $coach,
        'total_seats' => $seats, 'is_active' => 1, 'seat_model' => $coach === 'sleeper' ? '72-sleeper' : '40-seater', 'floors' => $coach === 'sleeper' ? 2 : 1]);
    $made['buses'][] = $id;
    return $id;
}

function op5_booking(int $sid, string $seat, string $status, string $date): int {
    global $made;
    return Database::transaction(function () use ($sid, $seat, $status, $date, &$made): int {
        $pnr = 'SHGOP5' . strtoupper(bin2hex(random_bytes(4)));
        $bid = Database::insert('bookings', [
            'pnr' => $pnr, 'trip_type' => 'oneway', 'booking_mode' => 'sharing',
            'contact_phone' => '919000017' . random_int(100, 999), 'fare_per_seat' => 1800, 'base_total' => 1800,
            'total_amount' => 1800, 'currency' => 'INR', 'status' => $status,
            'confirmed_at' => $status === 'confirmed' ? date('Y-m-d H:i:s') : null, 'source' => 'counter',
        ]);
        $lid = Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'leg_type' => 'outbound',
            'travel_date' => $date, 'fare_per_seat' => 1800, 'seat_count' => 1, 'leg_total' => 1800]);
        Seats::claim($sid, [$seat], $bid, $lid);
        Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $lid,
            'passenger_ref' => 'PAX' . strtoupper(bin2hex(random_bytes(5))), 'seat_no' => $seat,
            'full_name' => 'Op5 ' . $seat, 'gender' => 'Male', 'is_primary' => 1]);
        if ($status === 'confirmed') {
            Database::insert('payments', ['booking_id' => $bid, 'payment_ref' => 'PAY' . strtoupper(bin2hex(random_bytes(5))),
                'method' => 'cash', 'mode' => 'offline', 'amount' => 1800, 'currency' => 'INR', 'status' => 'verified',
                'verified_at' => date('Y-m-d H:i:s')]);
            Ticket::issue($bid);
        }
        $made['bookings'][] = $bid;
        $made['pnrs'][] = $pnr;
        return $bid;
    });
}

function op5_cleanup(): void {
    global $made;
    foreach ($made['pnrs'] as $pnr) {
        Database::delete('audit_logs', "entity_type = 'booking' AND entity_id = :p", ['p' => $pnr]);
        Database::delete('message_logs', 'booking_id IN (SELECT id FROM bookings WHERE pnr = :p)', ['p' => $pnr]);
        if (defined('TICKET_PATH')) {
            @unlink(TICKET_PATH . '/ticket_' . $pnr . '.png');
            @unlink(TICKET_PATH . '/ticket_' . $pnr . '.pdf');
        }
    }
    foreach ($made['bookings'] as $bid) {
        Database::delete('bookings', 'id = :i', ['i' => $bid]);   // FK cascade: legs / seats / passengers / payments / tickets
    }
    foreach ($made['sched'] as $sid) {
        Database::delete('automation_log', 'dedupe_key LIKE :k', ['k' => 'trip:bus_changed:%:' . $sid . ':%']);
        Database::delete('automation_log', 'dedupe_key LIKE :k', ['k' => 'trip:bus_changed:crew:' . $sid . ':%']);
        Database::delete('audit_logs', "entity_type = 'schedule' AND entity_id = :s", ['s' => (string) $sid]);
        Database::delete('live_trips', 'schedule_id = :s', ['s' => $sid]);
        Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]);
        Database::delete('booking_legs', 'schedule_id = :s', ['s' => $sid]);
        Database::delete('schedules', 'id = :s', ['s' => $sid]);
    }
    if ($made['driver'] > 0) {
        Database::delete('drivers', 'id = :i', ['i' => $made['driver']]);
    }
    foreach ($made['buses'] as $id) {
        Database::delete('buses', 'id = :i', ['i' => $id]);
    }
    Database::delete('settings', 'skey = :k', ['k' => 'bus_swap_notify_on']);
    Settings::flush();
}

echo "\n=== op5 bus swap ===\n\n";

/* message + crew seams: count what would go, never touch a real sender */
$sent = [];
$failPnr = '';
BusSwap::$sender = static function (int $bid, string $pnr, array $ctx) use (&$sent, &$failPnr): array {
    if ($pnr === $failPnr) { return ['ok' => false, 'detail' => 'test: provider refused']; }
    $sent[] = ['bid' => $bid, 'pnr' => $pnr, 'ctx' => $ctx];
    return ['ok' => true, 'detail' => 'test sent'];
};
$crewMsgs = [];
BusSwap::$crewTransport = static function (string $phone, string $text, ?string $hint) use (&$crewMsgs): bool {
    $crewMsgs[] = ['phone' => $phone, 'text' => $text];
    return true;
};

try {
    $route = Database::fetch("SELECT id FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { throw new RuntimeException('no sleeper route in shari_test'); }
    $rid = (int) $route['id'];

    $busA     = op5_bus(OP5_TAG . '-A-' . $tag, 'sleeper', 72);
    $busB     = op5_bus(OP5_TAG . '-B-' . $tag, 'sleeper', 72);
    $busSmall = op5_bus(OP5_TAG . '-S-' . $tag, 'sleeper', 2);
    $busSeat  = op5_bus(OP5_TAG . '-T-' . $tag, 'seater', 40);
    $busClash = op5_bus(OP5_TAG . '-C-' . $tag, 'sleeper', 72);
    $labelA = OP5_TAG . '-A-' . $tag; $labelB = OP5_TAG . '-B-' . $tag;

    // leftovers from an aborted earlier run on this date
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => OP5_DATE]) as $old) {
        $made['sched'][] = (int) $old['id'];
    }
    $sid = (int) Seats::schedule($rid, OP5_DATE)['id'];
    if (!in_array($sid, $made['sched'], true)) { $made['sched'][] = $sid; }
    Database::update('schedules', ['bus_id' => $busA, 'total_seats' => 72, 'status' => 'scheduled'], 'id = :id', ['id' => $sid]);

    $did = Database::insert('drivers', ['full_name' => 'Op5 Driver', 'phone' => '9779800' . random_int(100000, 999999), 'is_active' => 1]);
    $made['driver'] = $did;
    Database::update('schedules', ['driver_id' => $did], 'id = :id', ['id' => $sid]);

    $b1 = op5_booking($sid, 'L10', 'confirmed', OP5_DATE);
    $b2 = op5_booking($sid, 'L11', 'confirmed', OP5_DATE);
    $b3 = op5_booking($sid, 'L12', 'confirmed', OP5_DATE);
    $b4 = op5_booking($sid, 'L20', 'pending', OP5_DATE);
    $pnr1 = (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :i', ['i' => $b1]);
    $pnr2 = (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :i', ['i' => $b2]);
    $confirmed = [$b1, $b2, $b3];
    $rev = static fn(int $b): int => (int) Database::scalar('SELECT reissue_count FROM tickets WHERE booking_id = :b', ['b' => $b], -1);
    $qr  = static fn(int $b): string => (string) Database::scalar('SELECT qr_payload FROM tickets WHERE booking_id = :b', ['b' => $b], '');
    check('setup: 3 tickets issued on bus A', count(array_filter($confirmed, static fn($b) => str_contains($qr($b), $labelA))) === 3);

    /* ---- A smaller bus ------------------------------------------------ */
    $pv = BusSwap::preview($sid, $busSmall);
    check('A preview: smaller bus is not ok', $pv['ok'] === false);
    check('A preview: 4 beds sold vs 2 seats → overflow 2', $pv['beds'] === 4 && $pv['newSeats'] === 2 && $pv['overflow'] === 2,
        'beds ' . $pv['beds'] . ' seats ' . $pv['newSeats'] . ' overflow ' . $pv['overflow']);
    check('A preview: lists the 2 latest bookings as having no seat',
        count($pv['noSeat']) === 2 && (int) $pv['noSeat'][0]['bookingId'] === $b4 && (int) $pv['noSeat'][1]['bookingId'] === $b3);
    refused('A swap onto the smaller bus', fn() => BusSwap::swap($sid, $busSmall, 1, 'test'));
    check('A nothing written: still bus A', (int) Database::scalar('SELECT bus_id FROM schedules WHERE id = :s', ['s' => $sid]) === $busA);
    check('A no message, no new REV', $sent === [] && $rev($b1) === 0);

    /* ---- B seater / C clash -------------------------------------------- */
    refused('B swap onto a seater coach', fn() => BusSwap::swap($sid, $busSeat, 1, 'test'));
    $sid2 = Database::insert('schedules', ['route_id' => $rid, 'travel_date' => OP5_DATE, 'slot' => 7, 'bus_id' => $busClash,
        'total_seats' => 72, 'status' => 'scheduled']);
    $made['sched'][] = $sid2;
    refused('C swap onto a bus already on another trip that day', fn() => BusSwap::swap($sid, $busClash, 1, 'test'));
    refused('C swap onto the bus the trip already runs', fn() => BusSwap::swap($sid, $busA, 1, 'test'));

    /* ---- D trip status --------------------------------------------------- */
    Database::update('schedules', ['status' => 'cancelled'], 'id = :id', ['id' => $sid]);
    refused('D cancelled trip', fn() => BusSwap::swap($sid, $busB, 1, 'test'));
    Database::update('schedules', ['status' => 'arrived'], 'id = :id', ['id' => $sid]);
    refused('D arrived trip', fn() => BusSwap::swap($sid, $busB, 1, 'test'));
    Database::update('schedules', ['status' => 'departed'], 'id = :id', ['id' => $sid]);   // breakdown on the road
    check('D departed trip: preview ok', BusSwap::preview($sid, $busB)['ok'] === true);

    /* ---- the Date View card renders (preview only, nothing written) ---- */
    $h = op5_page('admin/trip-dashboard.php', ['date' => OP5_DATE, 'swap' => $sid, 'to' => $busSmall]);
    check('page: swap card renders with the no-seat list', str_contains($h, 'id="bus-swap"') && str_contains($h, 'No seat on the smaller bus')
        && !str_contains($h, 'UNCAUGHT') && !str_contains($h, '"err":"'), mb_substr((string) strstr($h, 'UNCAUGHT') ?: (string) strstr($h, '@@META@@'), 0, 200));
    check('page: smaller bus offers no confirm button', !str_contains($h, 'value="swap_bus"'));
    $h = op5_page('admin/trip-dashboard.php', ['date' => OP5_DATE, 'swap' => $sid, 'to' => $busB]);
    check('page: a fitting bus shows the confirm form (CSRF + reason + notify)', str_contains($h, 'value="swap_bus"')
        && str_contains($h, 'name="reason_preset"') && str_contains($h, 'name="notify"') && str_contains($h, 'name="' . CSRF_TOKEN_NAME . '"'));
    check('page: preview wrote nothing', (int) Database::scalar('SELECT bus_id FROM schedules WHERE id = :s', ['s' => $sid]) === $busA);

    // mid-route: one passenger already boarded, the GPS is on bus A
    Database::query('UPDATE booking_passengers SET boarded_at = :t WHERE booking_id = :b', ['t' => '2098-03-17 20:05:00', 'b' => $b2]);
    Database::insert('live_trips', ['trip_ref' => LiveTrips::tripRef($sid), 'schedule_id' => $sid, 'route_id' => $rid, 'bus_id' => $busA,
        'departure_at' => OP5_DATE . ' 20:00:00', 'started_at' => OP5_DATE . ' 20:00:00', 'status' => 'running']);
    LiveTrips::resetCache();

    $pngBefore = defined('TICKET_PATH') ? TICKET_PATH . '/ticket_' . $pnr1 . '.png' : '';
    try { Ticket::pngPath($b1, true); } catch (Throwable $e) { /* drawn lazily if GD is missing */ }
    $pngHashBefore = ($pngBefore !== '' && is_file($pngBefore)) ? md5_file($pngBefore) : '';

    /* ---- E swap A → B ---------------------------------------------------- */
    $r = BusSwap::swap($sid, $busB, 1, 'गाडी बिग्रियो', ['via' => 'test']);
    $s = Database::fetch('SELECT bus_id, total_seats, status FROM schedules WHERE id = :s', ['s' => $sid]);
    check('E schedules.bus_id → B', (int) $s['bus_id'] === $busB);
    check('E total_seats synced (72)', (int) $s['total_seats'] === 72);
    check('E trip still departed', (string) $s['status'] === 'departed');
    check('E 4 passengers moved', (int) $r['moved'] === 4, 'moved ' . $r['moved']);
    check('E 3 tickets redrawn', (int) $r['tickets'] === 3, 'tickets ' . $r['tickets']);
    check('E 3 messages sent, 0 failed', (int) $r['sent'] === 3 && (int) $r['failed'] === 0, $r['message']);
    check('E exactly one message per confirmed booking', count($sent) === 3
        && count(array_unique(array_column($sent, 'bid'))) === 3 && !in_array($b4, array_column($sent, 'bid'), true));
    check('E message names old and new bus', ($sent[0]['ctx']['busOld'] ?? '') === $labelA && ($sent[0]['ctx']['busNew'] ?? '') === $labelB);
    check('E message carries seat + time', ($sent[0]['ctx']['seats'] ?? '') !== '' && ($sent[0]['ctx']['time'] ?? '') !== '');
    check('E REV bumped on every confirmed ticket', $rev($b1) === 1 && $rev($b2) === 1 && $rev($b3) === 1);
    check('E QR names the new bus', str_contains($qr($b1), $labelB) && !str_contains($qr($b1), $labelA));
    if ($pngHashBefore !== '') {
        check('E old ticket PNG replaced', !is_file($pngBefore) || md5_file($pngBefore) !== $pngHashBefore);
    } else {
        echo "  (skip PNG-replace check — no PNG drawn on this machine)\n";
    }
    check('E live_trips follows bus B', (int) Database::scalar('SELECT bus_id FROM live_trips WHERE trip_ref = :r', ['r' => LiveTrips::tripRef($sid)]) === $busB
        && (int) $r['liveRepointed'] === 1);
    check('E boarded mark kept', (string) Database::scalar('SELECT boarded_at FROM booking_passengers WHERE booking_id = :b', ['b' => $b2]) === '2098-03-17 20:05:00');
    check('E booking statuses untouched', (string) Database::scalar('SELECT status FROM bookings WHERE id = :b', ['b' => $b4]) === 'pending'
        && (string) Database::scalar('SELECT status FROM bookings WHERE id = :b', ['b' => $b1]) === 'confirmed');
    check('E seats untouched', (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE schedule_id = :s AND released_at IS NULL', ['s' => $sid]) === 4);
    $aud = Database::fetch("SELECT new_value, reason FROM audit_logs WHERE action = 'trip.swap_bus' AND entity_type = 'schedule' AND entity_id = :s ORDER BY id DESC LIMIT 1", ['s' => (string) $sid]);
    check('E audited trip.swap_bus with the reason', $aud !== null && (string) $aud['reason'] === 'गाडी बिग्रियो' && str_contains((string) $aud['new_value'], '"v2":1'));
    check('E crew told once, with both numbers', count($crewMsgs) === 1 && str_contains($crewMsgs[0]['text'], $labelA . ' -> ' . $labelB), (string) ($r['crew'] ?? ''));

    /* ---- F press again (same bus) ---------------------------------------- */
    $r2 = BusSwap::swap($sid, $busB, 1, 'गाडी बिग्रियो');
    check('F re-press: resume, nothing resent', !empty($r2['resume']) && (int) $r2['sent'] === 0 && (int) $r2['already'] === 3 && count($sent) === 3, $r2['message']);
    check('F re-press: no new REV', $rev($b1) === 1);
    check('F re-press: crew not told twice', count($crewMsgs) === 1);

    /* ---- G one send fails, the re-press resends only that one ------------ */
    $sent = []; $failPnr = $pnr2;
    $r3 = BusSwap::swap($sid, $busA, 1, 'नम्बर बदलियो');
    check('G swap B → A: 2 sent, 1 failed', (int) $r3['sent'] === 2 && (int) $r3['failed'] === 1, $r3['message']);
    check('G failed PNR listed', isset($r3['failedList'][0]) && str_starts_with($r3['failedList'][0], $pnr2));
    $sent = []; $failPnr = '';
    $r4 = BusSwap::swap($sid, $busA, 1, 'नम्बर बदलियो');
    check('G re-press: only the failed one resent', (int) $r4['sent'] === 1 && count($sent) === 1 && (int) $sent[0]['bid'] === $b2 && (int) $r4['already'] === 2, $r4['message']);
    check('G second swap notified again (key has the new bus + seq)', count($crewMsgs) === 2);

    /* ---- H switch off ----------------------------------------------------- */
    Settings::set('bus_swap_notify_on', false, 'bool', 'fleet');
    $sent = [];
    $revBefore = $rev($b1);
    $r5 = BusSwap::swap($sid, $busB, 1, 'test');
    check('H notify off: no message', (int) $r5['sent'] === 0 && $sent === [] && count($crewMsgs) === 2);
    check('H notify off: tickets still redrawn', (int) $r5['tickets'] === 3 && $rev($b1) === $revBefore + 1);
    Database::delete('settings', 'skey = :k', ['k' => 'bus_swap_notify_on']);
    Settings::flush();
    check('H switch defaults back to on', BusSwap::notifyOn() === true);

    /* ---- I the passenger wording (real Notify, local click-to-chat sender) */
    $wasDriver = Settings::getString('whatsapp_driver', 'click_to_chat');
    if ($wasDriver === 'click_to_chat') {
        $detail = BookingService::detail($pnr1);
        $nr = Notify::ticketChanged($detail ?? [], 'trip_change', ['busOld' => $labelA, 'busNew' => $labelB, 'seats' => 'LA10', 'time' => '8:00 PM · 17 Mar']);
        $body = (string) Database::scalar("SELECT body FROM message_logs WHERE booking_id = :b AND channel = 'whatsapp' ORDER BY id DESC LIMIT 1", ['b' => $b1], '');
        if ($body === '') { $body = (string) ($nr['link'] ?? ''); $body = rawurldecode($body); }
        check('I wording: old -> new, same seat, time, PNR',
            str_contains($body, 'तपाईंको बस नम्बर परिवर्तन भयो: ' . $labelA . ' -> ' . $labelB) && str_contains($body, 'सिट उही')
            && str_contains($body, 'समय 8:00 PM') && str_contains($body, 'PNR ' . $pnr1), mb_substr($body, 0, 160));
    } else {
        echo "  (skip wording check — a real WhatsApp sender is configured here)\n";
    }
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    BusSwap::$sender = null;
    BusSwap::$crewTransport = null;
    op5_cleanup();
}

$left = (int) Database::scalar("SELECT COUNT(*) FROM buses WHERE bus_number LIKE :t", ['t' => OP5_TAG . '-%-' . $tag], 0)
      + (int) Database::scalar('SELECT COUNT(*) FROM schedules WHERE travel_date = :d', ['d' => OP5_DATE], 0);
check('cleanup: own buses and schedules removed', $left === 0);

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
