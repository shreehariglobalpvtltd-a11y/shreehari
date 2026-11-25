<?php
/**
 * =====================================================================
 *  op5-hold-test.php — every unpaid HOLD frees itself (op5, 25 Nov 2026).
 *
 *   A the one window: seat_hold_minutes default 20, clamped 15-30
 *   B switch on  -> a new unpaid web booking holds <= the window
 *   C switch off -> the old booking_expiry_minutes (120) comes back
 *   D a hold older than the limit is expired, its seat is free again,
 *     one booking.hold_expired audit row
 *   E a pending booking with a UTR is untouched (paid claim)
 *   F a pending booking with a screenshot is untouched (paid claim)
 *   G a COD / pay-at-boarding (confirmed) booking is untouched
 *   H a fresh hold (still inside its window) stays
 *   I idempotent: a second sweep changes nothing
 *   J checkout seat lock: an expired lock does not block, the sweep
 *     drops it, a fresh lock stays
 *   K SmartBook QR hold is never longer than the window
 *   L the "hold expired" message is off by default
 *
 *    php -c .claude/php-dev.ini tests/op5-hold-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/holdrelease.php';

const HR_PHONE = '9000017725';
const HR_TOKEN = 'op5holdtest-token-aaaaaaaaaaaaaaaa';

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$keep = [];
function setKeep(string $k, ?string $v, string $t = 'string'): void {
    global $keep;
    if (!array_key_exists($k, $keep)) { $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]); }
    if ($v === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
    else { Settings::set($k, $v, $t, 'booking', false); }
    Settings::flush();
}
$schedIds = [];
function cleanup(): void {
    global $keep, $schedIds;
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => HR_PHONE]) as $r) {
        foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
        foreach (['payment_screenshots', 'payments', 'booking_collections', 'agent_ledger', 'pay_events', 'payment_reviews', 'notifications'] as $t) {
            try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        }
        try { Database::run('DELETE FROM audit_logs WHERE entity_id = :p', ['p' => $r['pnr']]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    try { Database::delete('seat_locks', 'lock_token = :t', ['t' => HR_TOKEN]); } catch (Throwable $e) {}
    foreach (array_unique($schedIds) as $sid) {
        try { Database::run("DELETE FROM audit_logs WHERE action = 'seat.hold_released' AND entity_id = :s", ['s' => (string) $sid]); } catch (Throwable $e) {}
    }
    $ns = (int) ($GLOBALS['newSched'] ?? 0);
    if ($ns > 0 && !Database::exists('SELECT 1 FROM booking_legs WHERE schedule_id = :s', ['s' => $ns])) {
        try { Database::delete('schedules', 'id = :s', ['s' => $ns]); } catch (Throwable $e) {}
    }
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    $keep = [];
    Settings::flush();
}
register_shutdown_function('cleanup');

echo "\n=== op5: every unpaid hold frees itself ===\n\n";
cleanup();
foreach (['whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'email_enabled' => '0'] as $k => $v) { setKeep($k, $v, 'bool'); }
setKeep('booking_expiry_minutes', '120', 'int');
setKeep('seat_hold_autorelease_on', null);
setKeep('hold_expiry_notice_on', null);
setKeep('smartbook_online_hold_min', '15', 'int');

/* ---------- A the window ---------- */
setKeep('seat_hold_minutes', null);
check('A1 default window is 20 min', HoldRelease::minutes() === 20, (string) HoldRelease::minutes());
check('A2 auto-release switch defaults ON', HoldRelease::on());
setKeep('seat_hold_minutes', '50', 'int');
check('A3 50 is clamped to 30', HoldRelease::minutes() === 30);
setKeep('seat_hold_minutes', '5', 'int');
check('A4 5 is clamped to 15', HoldRelease::minutes() === 15);
setKeep('seat_hold_minutes', '20', 'int');
check('A5 checkout lock uses the window', HoldRelease::lockMinutes() === 20);
check('A6 unpaid booking uses min(120, window) = 20', HoldRelease::pendingMinutes() === 20);

/* ---------- fixtures ---------- */
$TD = '';
for ($i = 140; $i >= 100; $i--) {
    $d = date('Y-m-d', strtotime(todayISO() . " +$i days"));
    if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE travel_date = :d', ['d' => $d], 0) === 0) { $TD = $d; break; }
}
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
if ($TD === '' || $route === null) { check('fixtures: free date + sleeper route', false); echo "\n  $pass passed, $fail failed\n"; exit(1); }
$hadSched = Database::exists('SELECT 1 FROM schedules WHERE route_id = :r AND travel_date = :d', ['r' => (int) $route['id'], 'd' => $TD]);
$sched = Seats::schedule((int) $route['id'], $TD);
if (!$hadSched) { $GLOBALS['newSched'] = (int) ($sched['id'] ?? 0); }
$sid   = (int) ($sched['id'] ?? 0);
$schedIds[] = $sid;
$seat = 8;
$mk = static function (bool $cod = false) use ($route, $TD, &$seat): array {
    $seat += 2;
    $res = BookingService::create(['routeId' => (int) $route['id'], 'travelDate' => $TD, 'seats' => ['L' . $seat],
        'passengers' => [['name' => 'ZZTEST op5 hold', 'age' => 30, 'gender' => 'Male']],
        'contact' => ['phone' => HR_PHONE], 'bookingMode' => 'sharing', 'paymentMethod' => $cod ? 'cod' : 'upi', 'isCod' => $cod, 'boarding' => '']);
    $b = Database::fetch('SELECT *, TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS secs FROM bookings WHERE pnr = :p', ['p' => $res['pnr']]);
    $b['seat'] = 'L' . $seat;
    return $b;
};
$age = static function (array $b, int $minutesAgo): void {
    Database::run('UPDATE bookings SET expires_at = NOW() - INTERVAL ' . $minutesAgo . ' MINUTE WHERE id = :i', ['i' => (int) $b['id']]);
};
$seatsOf = static fn(array $b): int => (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b', ['b' => (int) $b['id']], 0);
$statusOf = static fn(array $b): string => (string) Database::scalar('SELECT status FROM bookings WHERE id = :b', ['b' => (int) $b['id']], '');

/* ---------- B switch on ---------- */
$bOn = $mk();
check('B1 unpaid web booking is pending', (string) $bOn['status'] === 'pending', (string) $bOn['status']);
check('B2 its hold is <= 20 min', (int) $bOn['secs'] > 0 && (int) $bOn['secs'] <= 20 * 60 + 5, (string) $bOn['secs']);

/* ---------- C switch off ---------- */
setKeep('seat_hold_autorelease_on', '0', 'bool');
$bOff = $mk();
check('C1 switch off: hold is the old 120 min', (int) $bOff['secs'] > 110 * 60 && (int) $bOff['secs'] <= 120 * 60 + 5, (string) $bOff['secs']);
check('C2 switch off: lock reads seat_hold_minutes raw', HoldRelease::lockMinutes() === 20);
setKeep('seat_hold_minutes', '45', 'int');
check('C3 switch off: lock 45 is not clamped (old behaviour)', HoldRelease::lockMinutes() === 45);
setKeep('seat_hold_minutes', '20', 'int');
setKeep('seat_hold_autorelease_on', '1', 'bool');

/* ---------- D-H ---------- */
$stale = $bOn;                 // will be aged past its window
$fresh = $mk();                // stays inside its window
$utr   = $mk();
$shot  = $mk();
$cod   = $mk(true);
$age($stale, 2);
$age($bOff, 2);                // also stale, also unpaid

// E: a UTR on its payment row (or a new row), then the clock forced past anyway
$payId = (int) Database::scalar('SELECT id FROM payments WHERE booking_id = :b ORDER BY id LIMIT 1', ['b' => (int) $utr['id']], 0);
if ($payId > 0) {
    Database::update('payments', ['utr_number' => 'OP5UTR' . random_int(100000, 999999)], 'id = :i', ['i' => $payId]);
} else {
    Database::insert('payments', ['booking_id' => (int) $utr['id'], 'payment_ref' => 'PAY-OP5-' . random_int(1000, 9999), 'utr_number' => 'OP5UTR1', 'amount' => 1]);
}
$age($utr, 5);
// F: a screenshot row, clock forced past
$payS = (int) Database::scalar('SELECT id FROM payments WHERE booking_id = :b ORDER BY id LIMIT 1', ['b' => (int) $shot['id']], 0);
if ($payS === 0) {
    $payS = Database::insert('payments', ['booking_id' => (int) $shot['id'], 'payment_ref' => 'PAY-OP5S-' . random_int(1000, 9999), 'amount' => 1, 'mode' => 'screenshot']);
}
Database::insert('payment_screenshots', ['payment_id' => $payS, 'booking_id' => (int) $shot['id'], 'file_path' => 'op5-test/none.jpg', 'mime_type' => 'image/jpeg']);
$age($shot, 5);
// G: COD is confirmed; force a past clock anyway to prove status protects it
check('G0 COD booking is confirmed on submit', (string) $cod['status'] === 'confirmed', (string) $cod['status']);
$age($cod, 5);

$ids = array_map(static fn(array $b): int => (int) $b['id'], [$stale, $fresh, $utr, $shot, $cod, $bOff]);
$r1 = HoldRelease::expireStale(500, $ids);

check('D1 sweep expired exactly the 2 stale unpaid holds', $r1['expired'] === 2, json_encode($r1));
check('D2 stale hold is now expired', $statusOf($stale) === 'expired');
check('D3 its seat rows are gone', $seatsOf($stale) === 0);
$tok = HR_TOKEN;
$lk  = Seats::lock($sid, [$stale['seat']], $tok);
check('D4 its seat can be held again (free)', in_array(strtoupper($stale['seat']), array_map('strtoupper', $lk['locked']), true), json_encode($lk['failed']));
Seats::release($sid, [$stale['seat']], $tok);
check('D5 one booking.hold_expired audit row', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'booking.hold_expired' AND entity_id = :p", ['p' => $stale['pnr']], 0) === 1);
check('D6 the switch-off booking (aged) is swept too', $statusOf($bOff) === 'expired');

check('E1 UTR booking untouched (pending)', $statusOf($utr) === 'pending');
check('E2 UTR booking keeps its seat', $seatsOf($utr) === 1);
check('F1 screenshot booking untouched (pending)', $statusOf($shot) === 'pending');
check('F2 screenshot booking keeps its seat', $seatsOf($shot) === 1);
check('G1 COD booking untouched (confirmed)', $statusOf($cod) === 'confirmed');
check('G2 COD booking keeps its seat', $seatsOf($cod) === 1);
check('H1 fresh hold stays pending', $statusOf($fresh) === 'pending');
check('H2 fresh hold keeps its seat', $seatsOf($fresh) === 1);

/* ---------- I idempotent ---------- */
$r2 = HoldRelease::expireStale(500, $ids);
check('I1 second sweep expires nothing', $r2['expired'] === 0, json_encode($r2));
check('I2 still one audit row after the rerun', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'booking.hold_expired' AND entity_id = :p", ['p' => $stale['pnr']], 0) === 1);

/* ---------- held-now view ---------- */
$held = HoldRelease::heldNow(500);
$pp = array_column($held['pending'], 'pnr');
$pr = array_column($held['proof'], 'pnr');
check('V1 fresh hold listed as waiting for payment', in_array($fresh['pnr'], $pp, true));
check('V2 UTR + screenshot listed as proof waiting', in_array($utr['pnr'], $pr, true) && in_array($shot['pnr'], $pr, true));
check('V3 expired one is not listed', !in_array($stale['pnr'], $pp, true) && !in_array($stale['pnr'], $pr, true));
check('V4 minutesLeft rounds up / null when over', HoldRelease::minutesLeft(61) === 2 && HoldRelease::minutesLeft(-5) === null && HoldRelease::minutesLeft(null) === null);

/* ---------- J checkout seat lock ---------- */
Database::insert('seat_locks', ['schedule_id' => $sid, 'seat_no' => 'L3', 'lock_token' => HR_TOKEN, 'expires_at' => date('Y-m-d H:i:s', time() - 120)]);
Database::run('UPDATE seat_locks SET expires_at = NOW() - INTERVAL 2 MINUTE WHERE schedule_id = :s AND lock_token = :t', ['s' => $sid, 't' => HR_TOKEN]);
Database::insert('seat_locks', ['schedule_id' => $sid, 'seat_no' => 'L5', 'lock_token' => HR_TOKEN, 'expires_at' => date('Y-m-d H:i:s', time() + 900)]);
Database::run("UPDATE seat_locks SET expires_at = NOW() + INTERVAL 15 MINUTE WHERE schedule_id = :s AND seat_no = 'L5' AND lock_token = :t", ['s' => $sid, 't' => HR_TOKEN]);
$locked = Seats::lockedSeats($sid);
check('J1 expired lock does not block the seat', !in_array('L3', $locked, true));
check('J2 fresh lock still blocks', in_array('L5', $locked, true));
HoldRelease::sweepLocks();
check('J3 sweep removed the expired lock', !Database::exists("SELECT 1 FROM seat_locks WHERE schedule_id = :s AND seat_no = 'L3'", ['s' => $sid]));
check('J4 fresh lock kept', Database::exists("SELECT 1 FROM seat_locks WHERE schedule_id = :s AND seat_no = 'L5'", ['s' => $sid]));
check('J5 lock release audited per bus', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'seat.hold_released' AND entity_id = :s", ['s' => (string) $sid], 0) >= 1);
$lk2 = Seats::lock($sid, ['L7'], HR_TOKEN);
$sec = (int) Database::scalar("SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) FROM seat_locks WHERE schedule_id = :s AND seat_no = 'L7'", ['s' => $sid], 0);
check('J6 a new checkout lock lasts the 20-min window', $sec > 18 * 60 && $sec <= 20 * 60 + 5, (string) $sec);
Database::delete('seat_locks', 'lock_token = :t', ['t' => HR_TOKEN]);

/* ---------- K SmartBook ---------- */
setKeep('smartbook_online_hold_min', '60', 'int');
check('K1 SmartBook 60 is capped to the 20 window', HoldRelease::smartbookMinutes() === 20);
setKeep('smartbook_online_hold_min', '15', 'int');
check('K2 SmartBook 15 stays 15', HoldRelease::smartbookMinutes() === 15);

/* ---------- L notice off by default ---------- */
check('L1 hold-expired message off by default', HoldRelease::notice((int) $stale['id']) === false);
check('L2 sweep sent no notices', $r1['noticed'] === 0);

echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
