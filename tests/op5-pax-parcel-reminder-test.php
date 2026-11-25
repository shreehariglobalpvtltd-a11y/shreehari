<?php
/**
 * =====================================================================
 *  op5-pax-parcel-reminder-test.php — item 5 (25 Nov 2026, op5):
 *
 *   (a) optional passenger ID on the staff desk card (pax_id_field_on):
 *       PaxId clean / chalani tag, a staff sale stores each passenger's own
 *       ID, a blank row stays NULL, a customer card cannot send one, the
 *       switch off ignores it, the chalani surfaces are wired (office copy only)
 *   (b) parcels (parcel_book_on): add / validation / loaded -> delivered /
 *       cancel rules / Paid + To-pay totals per currency / audit rows, and
 *       admin/parcels.php: a counter opens + posts (CSRF), an agent gets 403,
 *       a post without the token changes nothing
 *   (c) the 2-hour reminder: the passenger's own pickup map link (the stop's
 *       pin, else the office place in that town) + that place's phone, the
 *       switch reminder_map_link_on, and one claim per booking (no double send)
 *
 *  Every row it makes is deleted; settings it touches are put back.
 *
 *    php -c .claude/php-dev.ini tests/op5-pax-parcel-reminder-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: render admin/parcels.php as one admin -------------------- */
if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $post = (array) ($cfg['post'] ?? []);
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.67';
    $_SERVER['REQUEST_METHOD'] = $post !== [] ? 'POST' : 'GET';
    $_SERVER['SCRIPT_NAME']    = '/admin/parcels.php';
    $_SERVER['REQUEST_URI']    = '/admin/parcels.php';
    $_GET = (array) ($cfg['get'] ?? []);
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if ($post !== []) {
        if (!empty($cfg['csrf'])) { $post[CSRF_TOKEN_NAME] = Security::csrfToken(); }
        $_POST = $post;
    }
    register_shutdown_function(static function (): void { echo "\n<!--HTTP:" . (int) (http_response_code() ?: 200) . "-->\n"; });
    require dirname(__DIR__) . '/admin/parcels.php';
    exit(0);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/paxid.php';
require_once INCLUDE_PATH . '/parcels.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/tripnotify.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/smartbook.php';
require_once INCLUDE_PATH . '/companylocations.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; } else { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function finish(): never {
    global $PASS, $FAIL;
    echo "\n  $PASS passed, $FAIL failed\n";
    exit($FAIL === 0 ? 0 : 1);
}
function child(array $cfg): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out;
}

echo "\n=== op5 · passenger ID, parcels, 2-hour reminder map ===\n\n";

/* ================================================================== */
echo "-- (a) PaxId: pure\n";
$c = PaxId::clean('Passport', ' p 12-34567 ');
check('clean: Passport, spaces gone, upper case', $c === ['type' => 'Passport', 'num' => 'P12-34567'], json_encode($c));
check('clean: fewer than 4 letters/digits = not given', PaxId::clean('Passport', '12') === null && PaxId::clean('', '') === null);
check('clean: an unknown type with a number = Other (never guessed)', (PaxId::clean('Bank card', 'AB1234')['type'] ?? '') === 'Other');
check('short: CTZ **5678 (masked by default)', PaxId::short('Nepali Citizenship', '12-34-5678', false) === 'CTZ **5678');
check('short: full number when asked', PaxId::short('Nepali Citizenship', '12-34-5678', true) === 'CTZ 12-34-5678');
check('short: old spelling "Nepali Citizenship Card" is CTZ too', str_starts_with(PaxId::short('Nepali Citizenship Card', 'ABCD9999', false), 'CTZ '));
check('short: no number = no tag', PaxId::short('Passport', '', false) === '' && PaxId::short(null, null, false) === '');
check('choices: the five desk types, Citizenship first', array_column(PaxId::choices(), 'v') === ['Nepali Citizenship', 'Passport', 'Aadhaar Card', 'Voter ID', 'Other']);

echo "\n-- (a) wiring (static)\n";
$js  = (string) file_get_contents($ROOT . '/assets/js/27-smartbook.js');
$api = (string) file_get_contents($ROOT . '/api/smartbook.php');
$sb  = (string) file_get_contents($ROOT . '/includes/smartbook.php');
$mf  = (string) file_get_contents($ROOT . '/admin/manifest.php');
$png = (string) file_get_contents($ROOT . '/includes/chalanipng.php');
$pdf = (string) file_get_contents($ROOT . '/includes/chalanipdf.php');
check('desk card: the ID box is staff-only and switch-gated', str_contains($js, 'S.paxIdOn = !!STAFF && !!d.paxIdOn') && str_contains($js, 'data-pidn'));
check('desk card: book() sends idType / idNum per passenger', str_contains($js, 'p.idNum = nm.slice(0, 30)'));
check('boot answer: paxIdOn only for a staff mode', str_contains($api, "'paxIdOn'   => \$mode !== 'customer' && PaxId::enabled()"));
check('server: only a staff sale reads the ID', str_contains($sb, "\$mode !== 'customer' && PaxId::enabled()"));
check('chalani: the tag is for the office copy only (no agent copy)', str_contains($mf, "'paxId' => \$scopeId === null && PaxId::enabled()"));
check('chalani PNG + PDF draw the tag', str_contains($png, "\$fmt['paxId']") && str_contains($pdf, "\$fmt['paxId']"));
check('chalani PNG cache key covers the tag', str_contains($png, "\$seed['paxid']"));

/* ================================================================== */
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
    echo "  SKIP  database part: DB_NAME is not shari_test\n";
    finish();
}

const OP5_PHONE = '98705';
const OP5_IDEM  = 'op5pax';
$tag = substr(bin2hex(random_bytes(4)), 0, 6);
$PINNED = ['whatsapp_notify_customer', 'whatsapp_notify_admin', 'whatsapp_driver', 'aisensy_mode', 'sms_enabled', 'daily_service_on',
           'pax_id_field_on', 'parcel_book_on', 'reminder_map_link_on', 'trip_reminders_enabled', 'reminder_2h_enabled', 'ticket_maps_qr'];
$prior = [];
foreach ($PINNED as $k) { $prior[$k] = Database::fetch('SELECT skey, svalue, stype, sgroup, label, is_public FROM settings WHERE skey = :k', ['k' => $k]); }
$admins = []; $parcelIds = []; $locIds = []; $schedTouch = []; $bookingIds = [];
$date = addDaysISO(todayISO(), 44);
$schedBefore = array_map('intval', array_column(Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $date]), 'id'));
$auditStart = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);

$cleanup = static function () use (&$admins, &$parcelIds, &$locIds, &$schedTouch, &$bookingIds, $date, $schedBefore): void {
    foreach ($parcelIds as $pid) { try { Database::delete('parcels', 'id = :i', ['i' => $pid]); } catch (Throwable) {} }
    try { Database::run("DELETE FROM parcels WHERE sender_name LIKE 'OP5 %'"); } catch (Throwable) {}
    foreach ($locIds as $lid) { try { Database::delete('company_locations', 'id = :i', ['i' => $lid]); } catch (Throwable) {} }
    $ids = array_map('intval', array_column(Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . OP5_PHONE . "%'"), 'id'));
    foreach (array_unique(array_merge($ids, $bookingIds)) as $bid) {
        foreach (['trip_events', 'message_logs', 'agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications', 'coupon_redemptions'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable) {}
        }
        try { Database::delete('schedule_unit_locks', 'locked_by_booking = :b', ['b' => $bid]); } catch (Throwable) {}
        try { Database::delete('bookings', 'id = :i', ['i' => $bid]); } catch (Throwable) {}
    }
    try { Database::run("DELETE FROM smartbook_requests WHERE idem_key LIKE '" . OP5_IDEM . "%'"); } catch (Throwable) {}
    foreach (['wa_outbox' => 'to_number', 'message_logs' => 'to_number'] as $t => $col) {
        try { Database::run("DELETE FROM {$t} WHERE {$col} LIKE :p", ['p' => '%' . OP5_PHONE . '%']); } catch (Throwable) {}
    }
    try { Database::run("DELETE FROM user_profiles WHERE phone LIKE '" . OP5_PHONE . "%' AND user_id IS NULL"); } catch (Throwable) {}
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $date]) as $s) {
        $sid = (int) $s['id'];
        if (in_array($sid, $schedBefore, true)) { continue; }
        foreach (['seat_locks', 'schedule_unit_locks', 'booking_seats', 'booking_legs'] as $t) {
            try { Database::delete($t, 'schedule_id = :s', ['s' => $sid]); } catch (Throwable) {}
        }
        try { Database::delete('schedules', 'id = :s', ['s' => $sid]); } catch (Throwable) {}
    }
    foreach ($schedTouch as $sid => $st) { try { Database::update('schedules', ['status' => $st], 'id = :i', ['i' => $sid]); } catch (Throwable) {} }
    $schedTouch = [];
    foreach ($admins as $aid) {
        try { Database::delete('kv_store', "kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $aid]); } catch (Throwable) {}
        try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aid]); } catch (Throwable) {}
        try { Database::delete('admins', 'id = :a', ['a' => $aid]); } catch (Throwable) {}
    }
    $admins = [];
};
$restore = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        try { Database::delete('settings', 'skey = :k', ['k' => $k]); } catch (Throwable) {}
        if ($prior[$k] !== null) { try { Database::insert('settings', $prior[$k]); } catch (Throwable) {} }
    }
    try { Settings::flush(); } catch (Throwable) {}
};
$set = static function (string $k, bool|string $v, string $type = 'bool'): void {
    Settings::set($k, $v, $type, 'notify', false);
    Settings::flush();
};
$mkAdmin = static function (string $role) use ($tag, &$admins): int {
    $id = (int) Database::insert('admins', ['username' => 'op5-' . $role . '-' . $tag, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => 'OP5 ' . ucfirst($role), 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
    $admins[] = $id;
    return $id;
};

/* nothing here may reach a real phone */
$set('whatsapp_notify_customer', false);
$set('whatsapp_notify_admin', false);
$set('whatsapp_driver', 'click_to_chat', 'string');
$set('aisensy_mode', 'off', 'string');
$set('sms_enabled', false);
$set('daily_service_on', true);

try {
    /* ============================================================== */
    echo "\n-- (a) a desk sale stores the optional ID\n";
    $SUP = $mkAdmin('superadmin');
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $SUP, 'username' => 'op5-superadmin-' . $tag, 'full_name' => 'OP5 Superadmin', 'role' => 'superadmin', 'permissions' => [], 'last_seen' => time()];
    $n = 0;
    $book = static function (string $tail, string $mode, array $pax) use ($date, &$n): array {
        $n++;
        $in = ['direction' => 'toNepal', 'date' => $date, 'name' => 'Op Five ' . $tail, 'phone' => OP5_PHONE . $tail, 'country' => 'IN',
               'pax' => $pax, 'type' => 'sharing'];
        return SmartBook::book($in, 'online', $mode, null, OP5_IDEM . str_pad((string) $n, 3, '0', STR_PAD_LEFT) . bin2hex(random_bytes(6)));
    };
    $paxRows = static fn(int $bid): array => Database::fetchAll('SELECT id_type, id_number FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => $bid]);

    $set('pax_id_field_on', true);
    $r1 = $book('00001', 'admin', [['gender' => 'Male', 'band' => 'adult', 'idType' => 'Passport', 'idNum' => 'p 1234567'],
                                   ['gender' => 'Male', 'band' => 'adult']]);
    $rows1 = isset($r1['bookingId']) ? $paxRows((int) $r1['bookingId']) : [];
    check('staff sale made', ($r1['ok'] ?? false) === true && count($rows1) === 2, json_encode($r1['error'] ?? $rows1));
    check('  passenger 1: Passport P1234567', ($rows1[0]['id_type'] ?? null) === 'Passport' && ($rows1[0]['id_number'] ?? null) === 'P1234567', json_encode($rows1));
    check('  passenger 2 left blank: stays NULL (optional)', array_key_exists(1, $rows1) && $rows1[1]['id_number'] === null && $rows1[1]['id_type'] === null, json_encode($rows1));
    check('  the chalani tag for it', PaxId::short($rows1[0]['id_type'] ?? '', $rows1[0]['id_number'] ?? '', false) === 'PP **4567');

    $r2 = $book('00002', 'admin', [['gender' => 'Female', 'band' => 'adult', 'idType' => 'Voter ID', 'idNum' => '12']]);
    $rows2 = isset($r2['bookingId']) ? $paxRows((int) $r2['bookingId']) : [];
    check('a too-short number is not stored, the sale still goes', ($r2['ok'] ?? false) === true && array_key_exists('id_number', $rows2[0] ?? []) && $rows2[0]['id_number'] === null, json_encode($rows2));

    unset($_SESSION[ADMIN_SESSION_KEY]);
    $r3 = $book('00003', 'customer', [['gender' => 'Male', 'band' => 'adult', 'idType' => 'Passport', 'idNum' => 'Z9999999']]);
    $rows3 = isset($r3['bookingId']) ? $paxRows((int) $r3['bookingId']) : [];
    check('a customer card cannot send a per-passenger ID', ($r3['ok'] ?? false) === true && array_key_exists('id_number', $rows3[0] ?? []) && $rows3[0]['id_number'] === null, json_encode($r3['error'] ?? $rows3));

    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $SUP, 'username' => 'op5-superadmin-' . $tag, 'full_name' => 'OP5 Superadmin', 'role' => 'superadmin', 'permissions' => [], 'last_seen' => time()];
    $set('pax_id_field_on', false);
    $r4 = $book('00004', 'admin', [['gender' => 'Male', 'band' => 'adult', 'idType' => 'Passport', 'idNum' => 'Q7654321']]);
    $rows4 = isset($r4['bookingId']) ? $paxRows((int) $r4['bookingId']) : [];
    check('pax_id_field_on = 0: the desk ID is ignored', ($r4['ok'] ?? false) === true && array_key_exists('id_number', $rows4[0] ?? []) && $rows4[0]['id_number'] === null, json_encode($rows4));
    $set('pax_id_field_on', true);
    unset($_SESSION[ADMIN_SESSION_KEY]);

    /* ============================================================== */
    echo "\n-- (b) parcels\n";
    Parcels::resetCache();
    check('the parcels migration has run on shari_test', Parcels::hasTable(), 'run database/upgrade-2026-11-25-op5-parcels.sql');
    if (!Parcels::hasTable()) { throw new RuntimeException('no parcels table'); }
    $set('parcel_book_on', true);
    $base = ['travel_date' => $date, 'from_place' => 'Surat', 'to_place' => 'Nepalgunj', 'sender_name' => 'OP5 Sender ' . $tag,
             'sender_phone' => '+91 98250 12345', 'receiver_name' => 'OP5 Receiver', 'receiver_phone' => '9812345678',
             'items' => 'Bag of clothes', 'pieces' => '2', 'weight_kg' => '12.5', 'amount' => '500', 'currency' => 'INR', 'pay' => 'paid'];
    $p1 = Parcels::add($base, $SUP); $parcelIds[] = $p1;
    $row = Parcels::get($p1) ?? [];
    check('add: stored, booked, phones cleaned to 10 digits', ($row['status'] ?? '') === 'booked' && ($row['sender_phone'] ?? '') === '9825012345'
        && ($row['receiver_phone'] ?? '') === '9812345678' && (float) ($row['weight_kg'] ?? 0) === 12.5 && (int) ($row['pieces'] ?? 0) === 2, json_encode($row));
    check('  slip number P-<date>-<id>', Parcels::number($row) === 'P-' . str_replace('-', '', $date) . '-' . str_pad((string) $p1, 4, '0', STR_PAD_LEFT));
    check('  tap-to-call: the number as stored (98 is both India and Nepal, never guessed)', Parcels::telHref('9825012345') === 'tel:9825012345' && Parcels::telHref('12') === '');
    $p2 = Parcels::add(['pay' => 'to_pay', 'amount' => '300', 'pieces' => '1', 'weight_kg' => ''] + $base, $SUP); $parcelIds[] = $p2;
    $p3 = Parcels::add(['pay' => 'to_pay', 'amount' => '1000', 'currency' => 'NPR'] + $base, $SUP); $parcelIds[] = $p3;
    $p4 = Parcels::add(['pay' => 'paid', 'amount' => '999'] + $base, $SUP); $parcelIds[] = $p4;

    $bad = static function (array $over) use ($base, $SUP): bool {
        try { Parcels::add($over + $base, $SUP); return false; } catch (RuntimeException) { return true; }
    };
    check('add refuses a bad mobile', $bad(['receiver_phone' => '12345']));
    check('add refuses a blank sender name', $bad(['sender_name' => '']));
    check('add refuses no Paid / To pay choice', $bad(['pay' => '']));
    check('add refuses 0 pieces and a negative amount', $bad(['pieces' => '0']) && $bad(['amount' => '-5']));
    check('add refuses a bus that does not run that date', $bad(['schedule_id' => '999999999']));

    Parcels::setStatus($p1, 'loaded', $SUP);
    $l = Parcels::get($p1) ?? [];
    check('booked -> loaded stamps loaded_at', ($l['status'] ?? '') === 'loaded' && $l['loaded_at'] !== null);
    Parcels::setStatus($p1, 'delivered', $SUP);
    $d = Parcels::get($p1) ?? [];
    check('loaded -> delivered stamps delivered_at; pay untouched', ($d['status'] ?? '') === 'delivered' && $d['delivered_at'] !== null && ($d['pay'] ?? '') === 'paid');
    $refused = false;
    try { Parcels::setStatus($p1, 'cancelled', $SUP); } catch (RuntimeException) { $refused = true; }
    check('a delivered parcel cannot be cancelled', $refused && (Parcels::get($p1)['status'] ?? '') === 'delivered');
    Parcels::setStatus($p4, 'cancelled', $SUP);
    $refused = false;
    try { Parcels::setStatus($p4, 'loaded', $SUP); } catch (RuntimeException) { $refused = true; }
    check('a cancelled parcel stays cancelled', $refused);
    Parcels::setStatus($p2, 'delivered', $SUP);
    check('booked -> delivered (handed over at the desk) also stamps loaded_at', (Parcels::get($p2)['loaded_at'] ?? null) !== null);

    $list = array_values(array_filter(Parcels::listFor($date), static fn(array $r): bool => in_array((int) $r['id'], [$p1, $p2, $p3, $p4], true)));
    $t = Parcels::totals($list);
    check('totals: cancelled left out (3 parcels, 5 pieces)', $t['count'] === 3 && $t['pieces'] === 5, json_encode($t));
    check('totals: Paid ₹500; To pay ₹300 + रू1000 kept apart', $t['paid'] === ['INR' => 500.0] && $t['to_pay'] === ['INR' => 300.0, 'NPR' => 1000.0], json_encode($t));
    check('totals: by status', $t['byStatus']['delivered'] === 2 && $t['byStatus']['booked'] === 1 && $t['byStatus']['cancelled'] === 1);
    check('money line: "₹ 300 · रू 1,000"', Parcels::moneyLine($t['to_pay']) === '₹ 300 · रू 1,000', Parcels::moneyLine($t['to_pay']));
    $toPay = array_column(array_filter(Parcels::listFor($date, 0, 'to_pay'), static fn(array $r): bool => in_array((int) $r['id'], [$p1, $p2, $p3, $p4], true)), 'id');
    check('filter To pay lists only the to-pay parcels', array_map('intval', $toPay) === [$p3, $p2] || array_map('intval', $toPay) === [$p2, $p3], json_encode($toPay));
    $aud = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action IN ('parcel.add', 'parcel.status') AND entity_id IN ('$p1', '$p2', '$p3', '$p4')", ['s' => $auditStart], 0);
    check('every add and every status change is audited (4 + 4)', $aud === 8, (string) $aud);

    $set('parcel_book_on', false);
    check('parcel_book_on = 0: add refused', $bad([]));
    $set('parcel_book_on', true);

    echo "\n-- (b) admin/parcels.php\n";
    $CTR = $mkAdmin('counter');
    $AGT = $mkAdmin('agent');
    $html = child(['admin' => $CTR, 'get' => ['date' => $date]]);
    check('a counter opens the page (200) and sees its parcels', str_contains($html, 'class="pc-grid"') && str_contains($html, 'OP5 Sender ' . $tag) && str_contains($html, '<!--HTTP:200-->'), substr(strip_tags($html), 0, 200));
    check('  tap-to-call links and the slip link are there', str_contains($html, 'href="tel:9825012345"') && str_contains($html, 'slip=' . $p1));
    check('  every POST form carries the CSRF field', substr_count($html, 'method="post"') > 0 && substr_count($html, 'method="post"') === substr_count($html, 'name="' . CSRF_TOKEN_NAME . '"'));
    $html = child(['admin' => $AGT, 'get' => ['date' => $date]]);
    check('an agent gets 403 (bookings.edit)', str_contains($html, '<!--HTTP:403-->') && !str_contains($html, 'class="pc-grid"'));
    $slip = child(['admin' => $CTR, 'get' => ['slip' => (string) $p3]]);
    check('the slip prints the number, To pay and NPR', str_contains($slip, Parcels::number(Parcels::get($p3) ?? [])) && str_contains($slip, 'रू 1,000') && str_contains($slip, 'To pay'));
    $post = ['act' => 'add', 'travel_date' => $date, 'from_place' => 'Mehsana', 'to_place' => 'Kohalpur', 'sender_name' => 'OP5 Post ' . $tag,
             'sender_phone' => '9825099999', 'receiver_name' => 'OP5 R', 'receiver_phone' => '9800011111', 'items' => 'Box', 'pieces' => '1',
             'amount' => '150', 'currency' => 'INR', 'pay' => 'to_pay'];
    child(['admin' => $CTR, 'post' => $post, 'csrf' => false, 'get' => ['date' => $date]]);
    check('a POST without the CSRF token adds nothing', !Database::exists('SELECT 1 FROM parcels WHERE sender_name = :n', ['n' => 'OP5 Post ' . $tag]));
    $html = child(['admin' => $CTR, 'post' => $post, 'csrf' => true, 'get' => ['date' => $date]]);
    $pid = (int) Database::scalar('SELECT id FROM parcels WHERE sender_name = :n', ['n' => 'OP5 Post ' . $tag], 0);
    if ($pid > 0) { $parcelIds[] = $pid; }
    check('a counter POST with the token books it', $pid > 0 && (int) Database::scalar('SELECT booked_by FROM parcels WHERE id = :i', ['i' => $pid], 0) === $CTR, substr(strip_tags($html), 0, 200));
    child(['admin' => $CTR, 'post' => ['act' => 'status', 'id' => (string) $pid, 'to' => 'loaded'], 'csrf' => true, 'get' => ['date' => $date]]);
    check('a counter marks it loaded from the page', (Parcels::get($pid)['status'] ?? '') === 'loaded');
    child(['admin' => $AGT, 'post' => ['act' => 'status', 'id' => (string) $pid, 'to' => 'delivered'], 'csrf' => true, 'get' => ['date' => $date]]);
    check('an agent POST changes nothing', (Parcels::get($pid)['status'] ?? '') === 'loaded');
    $g = (string) file_get_contents($ROOT . '/admin/_guard.php');
    check('menu: one Parcels line, bookings.edit, Ticketing', preg_match("~'href' => 'parcels\.php',[^\n]*'perm' => 'bookings\.edit', 'section' => \\\$S3~", $g) === 1 && substr_count($g, "'parcels.php'") === 1);

    /* ============================================================== */
    echo "\n-- (c) 2-hour reminder: pickup map + phone\n";
    $town = 'Opfive' . $tag;
    $locIds[] = (int) Database::insert('company_locations', ['name' => 'OP5 Place', 'kind' => 'pickup', 'town' => $town,
        'map_url' => 'https://www.google.com/maps?q=27.512345,83.412345', 'phone' => '9800000123 / 9800000124', 'show_public' => 0, 'sort_order' => 999, 'created_at' => date('Y-m-d H:i:s')]);
    $set('reminder_map_link_on', true);
    $set('ticket_maps_qr', true);
    $pinned = TripNotify::pickupPlace(['boarding_stop' => 'Op5 Chowk [26.123456,80.654321]', 'route_id' => 0]);
    check('a stop with its own pin -> that pin (short maps link)', $pinned['map'] === 'https://maps.google.com/?q=26.123456,80.654321', json_encode($pinned));
    $byTown = TripNotify::pickupPlace(['boarding_stop' => $town . ' Bazaar · Gate 2', 'route_id' => 0]);
    check('no pin -> the office place of that town', $byTown['map'] === 'https://maps.google.com/?q=27.512345,83.412345', json_encode($byTown));
    check('  and that place\'s phone (first number)', $byTown['phone'] === '9800000123', json_encode($byTown));
    $none = TripNotify::pickupPlace(['boarding_stop' => 'Nowhere' . $tag . ' Road', 'route_id' => 0]);
    check('no pin and no place -> no link (never guessed)', $none['map'] === '' && $none['phone'] === '', json_encode($none));
    $x = TripNotify::reminderExtras(['boarding_stop' => $town . ' Bazaar', 'route_id' => 0], 'reminder_2h');
    check('reminderExtras: map + phone + ticket for reminder_2h', ($x['pickupMap'] ?? '') !== '' && ($x['pickupPhone'] ?? '') === '9800000123' && ($x['withTicket'] ?? '') === '1');
    check('reminderExtras: nothing for the 12 h reminder', TripNotify::reminderExtras(['boarding_stop' => $town], 'reminder_12h') === []);
    $set('reminder_map_link_on', false);
    check('reminder_map_link_on = 0: nothing added', TripNotify::reminderExtras(['boarding_stop' => $town], 'reminder_2h') === []);
    $set('reminder_map_link_on', true);

    $body = new ReflectionMethod(Notify::class, 'tripEventBody');
    $body->setAccessible(true);
    $fx = ['route' => 'Surat → Nepalgunj', 'date' => 'Wed, 25 Nov 2026', 'depTime' => '3:30 PM', 'seats' => 'A1', 'boarding' => 'Surat · 15:30',
           'pickupMap' => 'https://maps.google.com/?q=21.17,72.83', 'pickupPhone' => '7359301507', 'withTicket' => '1'];
    $b = $body->invoke(null, 'reminder_2h', 'S Hari Global', 'SHG-OP5-' . $tag, $fx);
    $wa = (string) $b['whatsapp'];
    check('2 h WhatsApp: the pickup map line', str_contains($wa, "📍 चढ्ने ठाउँ · Pickup map: https://maps.google.com/?q=21.17,72.83\n"), $wa);
    check('2 h WhatsApp: the place phone line', str_contains($wa, '📞 त्यहाँको फोन · Place phone: 7359301507'));
    check('2 h WhatsApp: the ticket picture (with its pickup QR)', (bool) preg_match('~🎫 टिकट \(QR सहित\) · Ticket: \S*download-ticket\.php\?pnr=SHG-OP5-~u', $wa), $wa);
    check('2 h SMS: one line with the map link', !str_contains((string) $b['sms'], "\n") && str_contains((string) $b['sms'], 'Map: https://maps.google.com/?q=21.17,72.83'), (string) $b['sms']);
    unset($fx['pickupMap'], $fx['pickupPhone'], $fx['withTicket']);
    $b0 = $body->invoke(null, 'reminder_2h', 'S Hari Global', 'SHG-OP5-' . $tag, $fx);
    check('without a map the 2 h text is exactly as before', !str_contains((string) $b0['whatsapp'], '📍') && !str_contains((string) $b0['whatsapp'], '🎫') && !str_contains((string) $b0['sms'], 'Map:'));

    echo "\n-- (c) the cron sends it once\n";
    check('switches: trip_reminders_enabled + reminder_2h_enabled are on (default true)',
        Settings::getBool('trip_reminders_enabled', true) && Settings::getBool('reminder_2h_enabled', true));
    $set('whatsapp_notify_customer', true);   // click_to_chat: logged, never sent
    $route = Database::fetch('SELECT id, dep_time FROM routes WHERE is_active = 1 AND dep_time IS NOT NULL ORDER BY id LIMIT 1');
    $placed = null;
    if ($route !== null) {
        $target = time() + 100 * 60 + 30;
        $pick   = date('H:i:00', $target);
        foreach ([date('Y-m-d', $target), date('Y-m-d', $target - 86400)] as $dd) {
            $sch = Seats::schedule((int) $route['id'], $dd);
            $s   = Database::fetch("SELECT s.id, s.slot, s.status, COALESCE(s.dep_time_override, r.dep_time) AS dep, r.dep_time AS rdep
                                      FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :i", ['i' => (int) $sch['id']]);
            $label = $town . ' Bazaar';
            $pt = Boarding::pickupTs($dd, $label, $pick, (string) $s['dep'], (string) $s['rdep'], (int) $s['slot'], (int) $route['id']);
            if (abs($pt - $target) > 60) { continue; }
            if (!isset($schedTouch[(int) $s['id']])) { $schedTouch[(int) $s['id']] = (string) $s['status']; }
            Database::update('schedules', ['status' => 'scheduled'], 'id = :i', ['i' => (int) $s['id']]);
            $bid = (int) Database::insert('bookings', ['pnr' => 'SHG-TEST-OP5' . strtoupper($tag), 'contact_phone' => OP5_PHONE . '00099', 'contact_email' => '',
                'status' => 'confirmed', 'total_amount' => 1800.00, 'confirmed_at' => date('Y-m-d H:i:s')]);
            $bookingIds[] = $bid;
            $leg = (int) Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => (int) $s['id'], 'leg_type' => 'outbound', 'travel_date' => $dd,
                'boarding_stop' => $label, 'boarding_time' => $pick, 'seat_count' => 1, 'fare_per_seat' => 1800.00, 'leg_total' => 1800.00]);
            Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $leg, 'seat_no' => 'OP51', 'full_name' => 'Op5 Reminder', 'passenger_ref' => generatePassengerRef()]);
            $placed = $bid;
            break;
        }
    }
    check('a booking 100 min before its pickup was placed', $placed !== null);
    if ($placed !== null) {
        $logStart = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM message_logs', [], 0);
        // a free-form trip update is logged against the number (booking_id rides only with a template)
        $logCount = static fn(): int => (int) Database::scalar('SELECT COUNT(*) FROM message_logs WHERE to_number LIKE :p AND id > :s',
            ['p' => '%' . OP5_PHONE . '00099', 's' => $logStart], 0);
        TripNotify::runReminders();
        $ev = array_column(Database::fetchAll('SELECT `event` FROM trip_events WHERE booking_id = :b', ['b' => $placed]), 'event');
        check('the cron claims reminder_2h once', $ev === ['reminder_2h'], json_encode($ev));
        $msg = (string) Database::scalar("SELECT body FROM message_logs WHERE channel = 'whatsapp' AND to_number LIKE :p AND id > :s ORDER BY id DESC LIMIT 1",
            ['p' => '%' . OP5_PHONE . '00099', 's' => $logStart], '');
        check('the WhatsApp text carries that town\'s pickup map + phone', str_contains($msg, 'Pickup map: https://maps.google.com/?q=27.512345,83.412345') && str_contains($msg, '9800000123'), mb_substr($msg, 0, 300));
        $logs = $logCount();
        TripNotify::runReminders();
        $ev2 = array_column(Database::fetchAll('SELECT `event` FROM trip_events WHERE booking_id = :b', ['b' => $placed]), 'event');
        check('a second cron run sends nothing more (no double send)', $ev2 === ['reminder_2h'] && $logs > 0 && $logCount() === $logs, $logs . ' / ' . $logCount());
    }
} catch (Throwable $e) {
    check('test ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    $cleanup();
    $restore();
}

$left = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . OP5_PHONE . "%'", [], 0)
      + (int) Database::scalar("SELECT COUNT(*) FROM parcels WHERE sender_name LIKE 'OP5 %'", [], 0)
      + (int) Database::scalar('SELECT COUNT(*) FROM company_locations WHERE town = :t', ['t' => 'Opfive' . $tag], 0);
check('cleanup left nothing behind', $left === 0, (string) $left);
finish();
