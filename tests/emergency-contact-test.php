<?php
/**
 * =====================================================================
 *  emergency-contact-test.php — P7 (15 Nov 2026): the passenger's optional
 *  emergency contact (includes/emergencycontact.php,
 *  database/upgrade-2026-11-15-emergency-contact.sql).
 *
 *  Pure part:
 *    - phone rules = the main mobile's: Nepal 96/97/98…, India 6–9…, a
 *      typed +977 / +91 wins over the country box, stored with its code
 *    - read(): empty -> nothing; the passenger's own mobile, a bad number
 *      or a name without a number -> ignored with a note, never an error
 *    - relations: key or word in, "बुबा · Father" out
 *    - a request without a contact keeps the idempotency fingerprint it
 *      had before P7
 *
 *  Database part (shari_test ONLY; every row it makes is removed):
 *    - SmartBook::book (customer, Online) stores name / phone / relation;
 *      no contact -> NULLs; own mobile / bad number / name only -> NULLs
 *      and the answer says why; the same key with another contact is
 *      refused, the same request replays
 *    - TripEvents::manifest carries {name, relation, phone} per passenger
 *      (first name only), null without one, and before the migration
 *    - admin/booking-view.php shows it escaped with a tap-to-call link, and
 *      Quick Edit saves / refuses / removes it with an audit row
 *
 *    php -c .claude/php-dev.ini tests/emergency-contact-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: render admin/booking-view.php as a given staff member --- */
if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_GET = (array) ($cfg['get'] ?? []);
    $post = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    $_SERVER['SCRIPT_NAME']    = '/admin/booking-view.php';
    $_SERVER['REQUEST_URI']    = '/admin/booking-view.php';
    $_SERVER['HTTP_HOST']      = 'localhost';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    if (DB_NAME !== 'shari_test') { fwrite(STDERR, "not the test database\n"); exit(2); }
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME]  = Security::csrfToken();
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    }
    require dirname(__DIR__) . '/admin/booking-view.php';
    exit(0);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/smartbook.php';
require_once INCLUDE_PATH . '/tripevents.php';
require_once INCLUDE_PATH . '/emergencycontact.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    $ok ? $PASS++ : $FAIL++;
    echo '  ' . ($ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "  $l" . ($extra !== '' ? " — $extra" : '') . "\n";
}
/** Run admin/booking-view.php in a child PHP with a faked staff session; returns its output. */
function child(array $cfg): string
{
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
}

echo "\n=== P7 emergency contact ===\n\n-- phone rules\n";
check('Nepal 98… with the +977 box -> +977 stored', EmergencyContact::phoneFor('9812300001', 'NP') === '+9779812300001');
check('India 6–9… with the +91 box -> +91 stored', EmergencyContact::phoneFor('7012300001', 'IN') === '+917012300001');
check('a typed +91 wins over the +977 box', EmergencyContact::phoneFor('+91 98765 00001', 'NP') === '+919876500001');
check('a typed 977 prefix wins over the +91 box', EmergencyContact::phoneFor('977-9812300001', 'IN') === '+9779812300001');
check('no box: Nepal', EmergencyContact::phoneFor('9712300001') === '+9779712300001');
check('India number in the Nepal box is refused (Nepal is 96/97/98)', EmergencyContact::phoneFor('7012300001', 'NP') === '');
check('short / long / letters / all one digit are refused',
    EmergencyContact::phoneFor('98123', 'NP') === '' && EmergencyContact::phoneFor('981230000123', 'NP') === ''
    && EmergencyContact::phoneFor('call me', 'NP') === '' && EmergencyContact::phoneFor('9999999999', 'IN') === '');
check('a trunk 0 is dropped', EmergencyContact::phoneFor('09812300001', 'NP') === '+9779812300001');

echo "\n-- read(): never an error\n";
$own = '9877600001';
$r = EmergencyContact::read([], $own);
check('nothing typed -> nothing, no note', $r === ['contact' => null, 'note' => '']);
$r = EmergencyContact::read(null, $own);
check('not even an array -> nothing', $r === ['contact' => null, 'note' => '']);
$r = EmergencyContact::read(['name' => '  Hari   Thapa ', 'phone' => '9812300001', 'country' => 'NP', 'relation' => 'father'], $own);
check('a full contact: tidy name, +977 phone, relation key', $r['note'] === '' && $r['contact'] === ['name' => 'Hari Thapa', 'phone' => '+9779812300001', 'relation' => 'father'], json_encode($r, JSON_UNESCAPED_UNICODE));
$r = EmergencyContact::read(['phone' => '9812300001'], $own);
check('phone only: name and relation stay NULL', $r['contact'] === ['name' => null, 'phone' => '+9779812300001', 'relation' => null]);
$r = EmergencyContact::read(['name' => 'Self', 'phone' => '+977 ' . $own, 'country' => 'NP'], $own);
check('the passenger\'s own mobile (even with +977) -> ignored, note "own"', $r === ['contact' => null, 'note' => 'own']);
$r = EmergencyContact::read(['name' => 'X', 'phone' => '12345'], $own);
check('a bad number -> ignored, note "invalid"', $r === ['contact' => null, 'note' => 'invalid']);
$r = EmergencyContact::read(['name' => 'Only A Name', 'relation' => 'mother'], $own);
check('a name without a number -> ignored, note "nophone"', $r === ['contact' => null, 'note' => 'nophone']);
$r = EmergencyContact::read(['phone' => '9812300001', 'relation' => 'uncle-ish'], $own);
check('an unknown relation is dropped, the contact kept', $r['contact'] !== null && $r['contact']['relation'] === null);
$r = EmergencyContact::read(['name' => str_repeat('अ', 120), 'phone' => '9812300001'], $own);
check('a long name is cut to 80', $r['contact'] !== null && mb_strlen((string) $r['contact']['name']) === 80);
$r = EmergencyContact::read(['name' => ['x'], 'phone' => ['9812300001']], $own);
check('arrays in place of text -> nothing, no warning', $r === ['contact' => null, 'note' => '']);

echo "\n-- relations\n";
check('key / Nepali / English all read as the key', EmergencyContact::relationKey('wife') === 'wife'
    && EmergencyContact::relationKey('श्रीमती') === 'wife' && EmergencyContact::relationKey('Wife') === 'wife'
    && EmergencyContact::relationKey('') === null && EmergencyContact::relationKey('cousin') === null);
check('the eight relations of the card, Nepali first', array_keys(EmergencyContact::RELATIONS) === ['father', 'mother', 'husband', 'wife', 'brother', 'sister', 'friend', 'other']
    && EmergencyContact::relationLabel('father') === 'बुबा · Father' && EmergencyContact::relationLabel('sister') === 'दिदीबहिनी · Sister'
    && EmergencyContact::relationLabel(null) === '');
$fr = EmergencyContact::fromRow(['emergency_name' => 'Hari', 'emergency_phone' => '+9779812300001', 'emergency_relation' => 'father']);
check('fromRow: display "+977 98…", tel "+977…"', $fr !== null && $fr['phone'] === '+977 9812300001' && $fr['tel'] === '+9779812300001'
    && $fr['relationLabel'] === 'बुबा · Father', json_encode($fr, JSON_UNESCAPED_UNICODE));
check('fromRow: no phone -> null', EmergencyContact::fromRow(['emergency_name' => 'Hari']) === null && EmergencyContact::fromRow([]) === null);

echo "\n-- the idempotency fingerprint\n";
$fp = new ReflectionMethod('SmartBook', 'fingerprint');
$fp->setAccessible(true);
$fin = ['phone' => $own, 'name' => 'Em Test', 'pax' => [['gender' => 'Male', 'band' => 'adult']], 'direction' => 'toNepal', 'date' => '2026-12-28'];
$old = hash('sha256', implode('|', ['customer', 'online', normalisePhone($own), 'em test', 'male:adult', '', 'toNepal', '2026-12-28', '', '', '', '']));
check('a request with no contact hashes exactly as before P7', $fp->invoke(null, $fin, 'online', 'customer') === $old);
check('…and an ignored (own-number) contact changes nothing', $fp->invoke(null, $fin + ['emergency' => ['phone' => $own]], 'online', 'customer') === $old);
check('another contact = another request', $fp->invoke(null, $fin + ['emergency' => ['phone' => '9812300001']], 'online', 'customer') !== $old
    && $fp->invoke(null, $fin + ['emergency' => ['phone' => '9812300001']], 'online', 'customer') !== $fp->invoke(null, $fin + ['emergency' => ['phone' => '9812300002']], 'online', 'customer'));

/* ================================================================= */
echo "\n-- database (shari_test only)\n";
if (DB_NAME !== 'shari_test') {
    echo "Refusing the database part: DB_NAME is not shari_test.\n";
    echo "\nPASSED: $PASS   FAILED: $FAIL\n";
    exit($FAIL > 0 ? 1 : 0);
}
EmergencyContact::resetCache();
check('the migration has run on shari_test (three columns)', EmergencyContact::available());
if (!EmergencyContact::available()) {
    echo "\nPASSED: $PASS   FAILED: $FAIL\n";
    exit(1);
}

const EC_PHONE = '98776';          // every mobile this test books with starts so
const EC_IDEM  = 'ectest';         // every idempotency key too
$date = addDaysISO(todayISO(), 43);
$schedBefore = array_map('intval', array_column(Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $date]), 'id'));

/* nothing in this suite may reach a real phone: notifications off - put back afterwards */
$PINNED = ['whatsapp_notify_customer', 'whatsapp_notify_admin', 'whatsapp_driver', 'aisensy_mode', 'sms_enabled', 'daily_service_on'];
$prior = [];
foreach ($PINNED as $k) { $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]); }
Settings::set('whatsapp_notify_customer', false, 'bool', 'notify', false);
Settings::set('whatsapp_notify_admin', false, 'bool', 'notify', false);
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);
Settings::set('aisensy_mode', 'off', 'string', 'whatsapp', false);
Settings::set('sms_enabled', false, 'bool', 'notify', false);
Settings::set('daily_service_on', true, 'bool', 'booking', true);
Settings::flush();

$cleanup = static function () use ($date, $schedBefore): void {
    $sids = [];
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . EC_PHONE . "%'") as $r) {
        $bid = (int) $r['id'];
        foreach (Database::fetchAll('SELECT DISTINCT schedule_id FROM booking_legs WHERE booking_id = :b', ['b' => $bid]) as $l) { $sids[(int) $l['schedule_id']] = true; }
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications', 'coupon_redemptions'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::delete('schedule_unit_locks', 'locked_by_booking = :b', ['b' => $bid]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => $bid]);
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
    }
    try { Database::run("DELETE FROM smartbook_requests WHERE idem_key LIKE '" . EC_IDEM . "%'"); } catch (Throwable $e) {}
    // only the departures this test brought into being (one that was already there stays)
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $date]) as $s) {
        $sid = (int) $s['id'];
        if (in_array($sid, $schedBefore, true)) { continue; }
        foreach (['seat_locks', 'schedule_unit_locks', 'booking_seats'] as $t) {
            try { Database::delete($t, 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
        }
        try { Database::delete('booking_legs', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
        try { Database::delete('schedules', 'id = :s', ['s' => $sid]); } catch (Throwable $e) {}
    }
    foreach (['wa_outbox' => 'to_number', 'message_logs' => 'to_number'] as $t => $c) {
        try { Database::run("DELETE FROM {$t} WHERE {$c} LIKE :p", ['p' => '%' . EC_PHONE . '%']); } catch (Throwable $e) {}
    }
    // the customer-master row each guest sale opens for its mobile
    try { Database::run("DELETE FROM user_profiles WHERE phone LIKE '" . EC_PHONE . "%' AND user_id IS NULL"); } catch (Throwable $e) {}
};
$restore = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        if ($row === null) {
            try { Database::delete('settings', 'skey = :k', ['k' => $k]); } catch (Throwable $e) {}
        } else {
            try { Database::update('settings', ['svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'], 'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public']], 'skey = :k', ['k' => $k]); } catch (Throwable $e) {}
        }
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};

$auditStart = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$cleanup();
try {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    $mode = SmartBook::sessionMode();
    check('a guest session books as a customer', $mode === 'customer');
    $n = 0;
    $book = static function (string $tail, ?array $em, ?string $key = null) use ($date, $mode, &$n): array {
        $n++;
        $in = ['direction' => 'toNepal', 'date' => $date, 'name' => 'Em Test ' . $tail, 'phone' => EC_PHONE . $tail, 'country' => 'NP',
            'pax' => [['gender' => 'Male', 'band' => 'adult']], 'type' => 'sharing'];
        if ($em !== null) { $in['emergency'] = $em; }
        return SmartBook::book($in, 'online', $mode, null, $key ?? (EC_IDEM . str_pad((string) $n, 4, '0', STR_PAD_LEFT) . bin2hex(random_bytes(6))));
    };
    $row = static fn(int $id): array => Database::fetch('SELECT pnr, status, contact_phone, emergency_name, emergency_phone, emergency_relation FROM bookings WHERE id = :i', ['i' => $id]) ?? [];

    $keyA = EC_IDEM . 'A' . bin2hex(random_bytes(8));
    $emA  = ['name' => 'Hari <b>Thapa</b>', 'phone' => '9812300001', 'country' => 'NP', 'relation' => 'father'];
    $a    = $book('00001', $emA, $keyA);
    $ra   = $row((int) $a['bookingId']);
    check('Online sale made (pending, berths held)', ($a['ok'] ?? false) === true && ($ra['status'] ?? '') === 'pending', json_encode($ra));
    check('the three fields are stored: name as typed, +977 phone, relation key',
        ($ra['emergency_name'] ?? null) === 'Hari <b>Thapa</b>' && ($ra['emergency_phone'] ?? null) === '+9779812300001' && ($ra['emergency_relation'] ?? null) === 'father',
        json_encode($ra, JSON_UNESCAPED_UNICODE));
    check('the answer says it was saved', ($a['emergency'] ?? null) === ['saved' => true, 'note' => '']);

    $b  = $book('00002', null);
    $rb = $row((int) $b['bookingId']);
    check('no contact sent: all three stay NULL', array_key_exists('emergency_phone', $rb) && $rb['emergency_name'] === null && $rb['emergency_phone'] === null && $rb['emergency_relation'] === null);
    check('…and the answer has nothing to say', ($b['emergency'] ?? null) === ['saved' => false, 'note' => '']);

    $c  = $book('00003', ['name' => 'Me Again', 'phone' => '+977 ' . EC_PHONE . '00003', 'country' => 'NP', 'relation' => 'friend']);
    $rc = $row((int) $c['bookingId']);
    check('the passenger\'s own mobile: the sale stands, nothing stored', ($c['ok'] ?? false) === true && $rc['emergency_phone'] === null && $rc['emergency_name'] === null && $rc['emergency_relation'] === null);
    check('…the answer says "own"', ($c['emergency']['note'] ?? '') === 'own' && ($c['emergency']['saved'] ?? true) === false);

    $d  = $book('00004', ['name' => 'Bad Number', 'phone' => '55512', 'country' => 'IN', 'relation' => 'mother']);
    $rd = $row((int) $d['bookingId']);
    check('a bad number: the sale stands, nothing stored, note "invalid"', ($d['ok'] ?? false) === true && $rd['emergency_phone'] === null && $rd['emergency_name'] === null
        && ($d['emergency']['note'] ?? '') === 'invalid');

    $e  = $book('00005', ['name' => 'Name Only', 'relation' => 'sister']);
    $re = $row((int) $e['bookingId']);
    check('a name with no number: nothing stored, note "nophone"', $re['emergency_name'] === null && ($e['emergency']['note'] ?? '') === 'nophone');

    $f  = $book('00006', ['name' => 'Ramesh', 'phone' => '+91 70123 00006', 'country' => 'NP', 'relation' => 'brother']);
    $rf = $row((int) $f['bookingId']);
    check('an Indian number typed with +91 in the Nepal box: stored as +91', $rf['emergency_phone'] === '+917012300006' && $rf['emergency_relation'] === 'brother');

    // the same key: the same request replays; another contact is another request
    $again = $book('00001', $emA, $keyA);
    check('the same key + the same contact replays the first booking', ($again['replayed'] ?? false) === true && (int) $again['bookingId'] === (int) $a['bookingId']);
    $threw = '';
    try { $book('00001', ['phone' => '9812300099', 'country' => 'NP'], $keyA); } catch (SmartBookKeyReused $x) { $threw = 'reused'; } catch (Throwable $x) { $threw = get_class($x) . ': ' . $x->getMessage(); }
    check('the same key with another contact is refused (no second sale, no overwrite)', $threw === 'reused'
        && $row((int) $a['bookingId'])['emergency_phone'] === '+9779812300001', $threw);
    check('one booking per sale (6 made)', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . EC_PHONE . "%'", [], 0) === 6);

    /* ---- the driver's manifest ---- */
    $leg = Database::fetch("SELECT l.schedule_id, s.route_id FROM booking_legs l JOIN schedules s ON s.id = l.schedule_id WHERE l.booking_id = :b LIMIT 1", ['b' => (int) $a['bookingId']]);
    $seatOf = static fn(int $bid): string => (string) Database::scalar('SELECT seat_no FROM booking_passengers WHERE booking_id = :b LIMIT 1', ['b' => $bid], '');
    $find = static function (array $mf, string $seat): ?array {
        foreach ($mf['groups'] as $g) { foreach ($g['pax'] as $p) { if ($p['seat'] === $seat) { return $p; } } }
        return null;
    };
    if ($leg === null) {
        check('the sale has a departure', false);
    } else {
        $mf = TripEvents::manifest((int) $leg['schedule_id'], (int) $leg['route_id']);
        $pa = $find($mf, $seatOf((int) $a['bookingId']));
        $pb = $find($mf, $seatOf((int) $b['bookingId']));
        $pf = $find($mf, $seatOf((int) $f['bookingId']));
        check('manifest: the contact rides with the passenger — first name, relation, tap-to-call',
            $pa !== null && ($pa['emergency'] ?? null) === ['name' => 'Hari', 'relation' => 'बुबा · Father', 'phone' => '+9779812300001'],
            json_encode($pa['emergency'] ?? null, JSON_UNESCAPED_UNICODE));
        check('manifest: +91 keeps its code', ($pf['emergency']['phone'] ?? '') === '+917012300006' && ($pf['emergency']['relation'] ?? '') === 'दाजुभाइ · Brother');
        check('manifest: no contact -> emergency null', $pb !== null && array_key_exists('emergency', $pb) && $pb['emergency'] === null);
        $js = (string) json_encode($mf, JSON_UNESCAPED_UNICODE);
        check('manifest: the contact\'s surname is not on the driver\'s list', !str_contains($js, 'Thapa'), $js);
        // before the migration: the old column list, emergency null, no SQL error
        $flag = new ReflectionProperty('EmergencyContact', 'available');
        $flag->setAccessible(true);
        $flag->setValue(null, false);
        $mf0 = TripEvents::manifest((int) $leg['schedule_id'], (int) $leg['route_id']);
        $pa0 = $find($mf0, $seatOf((int) $a['bookingId']));
        check('before the migration: the manifest still works, emergency null', $pa0 !== null && $pa0['emergency'] === null && $mf0['totals'] === $mf['totals']);
        check('before the migration: save() writes nothing and says so', EmergencyContact::save((int) $b['bookingId'], ['name' => 'X', 'phone' => '+9779812300001', 'relation' => null]) === false
            && $row((int) $b['bookingId'])['emergency_phone'] === null);
        EmergencyContact::resetCache();
    }

    /* ---- the office: admin/booking-view.php ---- */
    $super = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);
    if ($super === 0) {
        echo "  \033[33mSKIP\033[0m  booking-view checks: no superadmin in shari_test\n";
    } else {
        $pnrA = (string) $a['pnr'];
        $view = child(['admin' => $super, 'get' => ['pnr' => $pnrA]]);
        check('booking page renders', str_contains($view, 'Emergency · आपतकालीन') && !str_contains($view, 'Application error'), substr(strip_tags($view), 0, 200));
        check('the name is escaped (no raw <b> from the passenger)', str_contains($view, 'Hari &lt;b&gt;Thapa&lt;/b&gt;') && !str_contains($view, 'Hari <b>Thapa</b>'));
        check('relation + tap-to-call link', str_contains($view, 'बुबा · Father') && str_contains($view, 'href="tel:+9779812300001"') && str_contains($view, '+977 9812300001'));
        check('Quick Edit carries the boxes, prefilled', str_contains($view, 'name="emergency_name"') && str_contains($view, 'value="9812300001"')
            && str_contains($view, 'name="em_form" value="1"') && preg_match('/<option value="father" selected>/', $view) === 1);
        $viewB = child(['admin' => $super, 'get' => ['pnr' => (string) $b['pnr']]]);
        check('a booking without one says "not given"', str_contains($viewB, 'not given · दिइएको छैन'));

        $post = static fn(array $p): array => ['action' => 'edit_passenger', 'booking_id' => (string) (int) $a['bookingId'], 'em_form' => '1', 'reason' => 'emergency test'] + $p;
        $o = child(['admin' => $super, 'get' => ['pnr' => $pnrA], 'post' => $post(['emergency_name' => 'Sita Thapa', 'emergency_phone' => '9812300009', 'emergency_cc' => 'NP', 'emergency_relation' => 'mother'])]);
        $ra = $row((int) $a['bookingId']);
        check('Quick Edit saves a new contact', $ra['emergency_name'] === 'Sita Thapa' && $ra['emergency_phone'] === '+9779812300009' && $ra['emergency_relation'] === 'mother', json_encode($ra, JSON_UNESCAPED_UNICODE));
        check('…says it is internal (no ticket re-issue, nobody messaged)', str_contains($o, 'Emergency contact saved'));
        $aud = Database::fetch("SELECT old_value, new_value FROM audit_logs WHERE id > :s AND action = 'booking.edit_passenger' AND entity_id = :p ORDER BY id DESC LIMIT 1", ['s' => $auditStart, 'p' => $pnrA]);
        check('…one audit row, old -> new', $aud !== null && str_contains((string) $aud['old_value'], '9812300001') && str_contains((string) $aud['new_value'], '9812300009'));
        check('…the ticket was not re-issued for it', (int) Database::scalar('SELECT COUNT(*) FROM tickets WHERE booking_id = :b AND reissue_count > 0', ['b' => (int) $a['bookingId']], 0) === 0);

        $o = child(['admin' => $super, 'get' => ['pnr' => $pnrA], 'post' => $post(['emergency_name' => 'Sita Thapa', 'emergency_phone' => EC_PHONE . '00001', 'emergency_cc' => 'NP', 'emergency_relation' => 'mother'])]);
        check('the passenger\'s own number is refused with a reason, nothing changed', str_contains($o, 'passenger&#039;s own number') || str_contains($o, "passenger's own number"));
        check('…the stored contact is untouched', $row((int) $a['bookingId'])['emergency_phone'] === '+9779812300009');
        $o = child(['admin' => $super, 'get' => ['pnr' => $pnrA], 'post' => $post(['emergency_phone' => '12345', 'emergency_cc' => 'NP'])]);
        check('a bad number is refused with a reason', str_contains($o, 'Emergency mobile: 10 digits') && $row((int) $a['bookingId'])['emergency_phone'] === '+9779812300009');
        $o = child(['admin' => $super, 'get' => ['pnr' => $pnrA], 'post' => $post(['emergency_name' => 'Sita Thapa', 'emergency_phone' => '9812300009', 'emergency_cc' => 'NP', 'emergency_relation' => 'mother'])]);
        check('saving the same values changes nothing', str_contains($o, 'Nothing changed'));
        $o = child(['admin' => $super, 'get' => ['pnr' => $pnrA], 'post' => $post(['emergency_remove' => 'remove'])]);
        $ra = $row((int) $a['bookingId']);
        check('"Remove" clears all three', $ra['emergency_name'] === null && $ra['emergency_phone'] === null && $ra['emergency_relation'] === null);
        $o = child(['admin' => $super, 'get' => ['pnr' => (string) $b['pnr']], 'post' => ['action' => 'edit_passenger', 'booking_id' => (string) (int) $b['bookingId'], 'em_form' => '1',
            'emergency_name' => '', 'emergency_phone' => '', 'emergency_cc' => 'NP', 'emergency_relation' => '']]);
        check('empty boxes on a booking without one: nothing changed, still NULL', str_contains($o, 'Nothing changed') && $row((int) $b['bookingId'])['emergency_phone'] === null);

        /* review fix: an untouched (prefilled) block neither refuses nor rewrites an unrelated save */
        Database::update('bookings', ['emergency_name' => 'Self Again', 'emergency_phone' => '+977' . EC_PHONE . '00001', 'emergency_relation' => 'uncle'],
            'id = :id', ['id' => (int) $a['bookingId']]);   // as if another path had since made it the passenger's own number
        $o  = child(['admin' => $super, 'get' => ['pnr' => $pnrA], 'post' => $post(['emergency_name' => 'Self Again', 'emergency_phone' => EC_PHONE . '00001',
            'emergency_cc' => 'NP', 'emergency_relation' => 'uncle', 'remark' => 'emergency untouched test'])]);
        $ra = $row((int) $a['bookingId']);
        check('an untouched block does not block a remark (stored number = own, stored word not one of the eight)',
            str_contains($o, 'Remark added') && $ra['emergency_phone'] === '+977' . EC_PHONE . '00001' && $ra['emergency_relation'] === 'uncle',
            json_encode($ra, JSON_UNESCAPED_UNICODE));
        $o  = child(['admin' => $super, 'get' => ['pnr' => $pnrA], 'post' => $post(['emergency_name' => 'Self Again', 'emergency_phone' => EC_PHONE . '00001',
            'emergency_cc' => 'IN', 'emergency_relation' => 'uncle'])]);
        check('…but a changed box is checked again (own number refused)', str_contains($o, 'passenger&#039;s own number') || str_contains($o, "passenger's own number"));
        Database::update('bookings', ['emergency_name' => 'Old Name', 'emergency_phone' => '+9779812300009', 'emergency_relation' => 'uncle'],
            'id = :id', ['id' => (int) $a['bookingId']]);
        $o  = child(['admin' => $super, 'get' => ['pnr' => $pnrA], 'post' => $post(['emergency_name' => 'New Name', 'emergency_phone' => '9812300009',
            'emergency_cc' => 'NP', 'emergency_relation' => 'uncle'])]);
        $ra = $row((int) $a['bookingId']);
        check('a name edit keeps a stored relation word that is not one of the eight', $ra['emergency_name'] === 'New Name'
            && $ra['emergency_phone'] === '+9779812300009' && $ra['emergency_relation'] === 'uncle', json_encode($ra, JSON_UNESCAPED_UNICODE));
    }
} catch (Throwable $ex) {
    check('database part finished', false, get_class($ex) . ': ' . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine());
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    // the audit rows of this test's own bookings only (by their PNRs)
    foreach (Database::fetchAll("SELECT pnr FROM bookings WHERE contact_phone LIKE '" . EC_PHONE . "%'") as $p) {
        try { Database::run('DELETE FROM audit_logs WHERE id > :s AND entity_id = :p', ['s' => $auditStart, 'p' => (string) $p['pnr']]); } catch (Throwable $e) {}
    }
    $cleanup();
    $restore();
}
check('cleaned up: no test booking / customer row / request left', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . EC_PHONE . "%'", [], 0) === 0
    && (int) Database::scalar("SELECT COUNT(*) FROM user_profiles WHERE phone LIKE '" . EC_PHONE . "%'", [], 0) === 0
    && (int) Database::scalar("SELECT COUNT(*) FROM smartbook_requests WHERE idem_key LIKE '" . EC_IDEM . "%'", [], 0) === 0);

echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
