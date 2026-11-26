<?php
/**
 * fx1-counter-test.php — staff + counter desk, incl. the Nepal counter (fx1, 26 Nov 2026).
 *
 *  A. CounterDesk::dayListDesk - whose day admin/desk-day.php may show.
 *  B. An NPR desk with its OWN pinned rate: the card's रू (quote price.fx) is the रू
 *     the sale freezes on the ticket and on the payment (cash figure), with a desk
 *     discount on the sale; an INR desk / no desk / a customer get no such key.
 *  C. One-way desk on the card: deskOneWay(), defaults() ignores a remembered other
 *     run, the quote carries the sale's own sentence, the sale still refuses; the
 *     switch desk_one_way_on = 0 = as before; an agent's defaults are untouched.
 *  D. Double submit: the same key twice = ONE booking, the second answer replayed.
 *  E. The pages, rendered as each login (child PHP process, no web server):
 *     desk-day for the clerk / agent / no desk / switch off / office; the Nepal
 *     counter home for an India-desk clerk and for the office.
 *  F. Source wiring a browser test would otherwise need (staff-only branches, the
 *     in-flight key guards, every switch name, no parcel UI).
 *
 *  Own rows only: staff 'fx1ctr-*', desks FXQ / FXI (inactive), passengers 98779xxxxx,
 *  idem keys 'fx1ctr…'. Everything is deleted again by marker; nothing else is touched.
 *
 *   php -c .claude/php-dev.ini tests/fx1-counter-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
date_default_timezone_set('Asia/Kolkata');

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/counterdesk.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/smartbook.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
echo "\n=== fx1 counter desk ===\n\n";

$ROOT  = dirname(__DIR__);
$src   = static fn(string $f): string => (string) file_get_contents($ROOT . '/' . $f);
const FX_PHONE = '98779';          // every passenger of this suite
const FX_USER  = 'fx1ctr-';        // every staff login of this suite
const FX_NPR   = 'FXQ';            // an NPR desk with its own pinned rate
const FX_INR   = 'FXI';            // an India desk that opens on the return run
const FX_RATE  = 1.65;             // NOT the company peg (1.6): proves the desk's rate is used
$DATE = addDaysISO(todayISO(), 47);

/* ---- settings this suite pins or flips: whole rows put back at the end ---- */
$KEYS = ['desk_one_way_on', 'desk_day_clerk_on', 'counter_desk_isolation', 'default_rules',
    'whatsapp_notify_customer', 'whatsapp_notify_admin', 'whatsapp_driver', 'aisensy_mode', 'sms_enabled', 'daily_service_on'];
$savedRows = [];
foreach ($KEYS as $k) { $savedRows[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]); }
$restoreSettings = static function () use ($KEYS, $savedRows): void {
    foreach ($KEYS as $k) {
        try {
            Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
            if ($savedRows[$k] !== null) { Database::insert('settings', $savedRows[$k]); }
        } catch (Throwable $e) { echo "  (settings restore $k: " . $e->getMessage() . ")\n"; }
    }
    Settings::flush();
};
$setting = static function (string $k, $v, string $type = 'bool', string $group = 'booking'): void {
    Settings::set($k, $v, $type, $group, false);
    Settings::flush();
};
$unset = static function (string $k): void {
    Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
    Settings::flush();
};

$schedBefore = array_map('intval', array_column(Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $DATE]), 'id'));
$hadDesk = [FX_NPR => CounterDesk::get(FX_NPR) !== null, FX_INR => CounterDesk::get(FX_INR) !== null];

$clean = static function () use ($DATE, $schedBefore, $hadDesk): void {
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . FX_PHONE . "%'") as $r) {
        $bid = (int) $r['id'];
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications', 'coupon_redemptions', 'delivery_log'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::run("DELETE FROM audit_logs WHERE entity_type = 'booking' AND entity_id = :p", ['p' => (string) $r['pnr']]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM automation_log WHERE data LIKE :p', ['p' => '%' . $r['pnr'] . '%']); } catch (Throwable $e) {}
        try { Database::delete('schedule_unit_locks', 'locked_by_booking = :b', ['b' => $bid]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => $bid]);
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
    }
    try { Database::run("DELETE FROM smartbook_requests WHERE idem_key LIKE 'fx1ctr%'"); } catch (Throwable $e) {}
    /* a departure this suite made appear on its own far date: removed only when it was not there
       before AND nobody's ticket sits on it (another session may be using the same date) */
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $DATE]) as $s) {
        $sid = (int) $s['id'];
        if (in_array($sid, $schedBefore, true)) { continue; }
        try {
            if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE schedule_id = :s', ['s' => $sid], 0) > 0) { continue; }
            foreach (['seat_locks', 'schedule_unit_locks'] as $t) {
                try { Database::delete($t, 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
            }
            Database::delete('schedules', 'id = :s', ['s' => $sid]);
        } catch (Throwable $e) {}
    }
    foreach (['wa_outbox', 'message_logs'] as $t) {
        try { Database::run("DELETE FROM {$t} WHERE to_number LIKE '%" . FX_PHONE . "%'"); } catch (Throwable $e) {}
    }
    try { Database::run("DELETE FROM user_profiles WHERE phone LIKE '" . FX_PHONE . "%' AND user_id IS NULL"); } catch (Throwable $e) {}
    /* the suite's own staff - and the log lines that carry their marker name */
    try { Database::run("DELETE FROM audit_logs WHERE actor_name LIKE '" . FX_USER . "%'"); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM app_logs WHERE context LIKE '%\"" . FX_USER . "%'"); } catch (Throwable $e) {}
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE '" . FX_USER . "%'") as $a) {
        $aid = (int) $a['id'];
        foreach (['admin_profiles' => 'admin_id', 'admin_sessions' => 'admin_id', 'agent_ledger' => 'admin_id', 'counter_shifts' => 'admin_id', 'activity_log' => 'admin_id', 'audit_log' => 'admin_id'] as $t => $col) {
            try { Database::delete($t, "$col = :a", ['a' => $aid]); } catch (Throwable $e) {}
        }
        try { Database::delete('admins', 'id = :a', ['a' => $aid]); } catch (Throwable $e) {}
    }
    /* the suite's own desks */
    $gone = false;
    foreach ([FX_NPR, FX_INR] as $code) {
        if (!$hadDesk[$code]) {
            try { Database::run('DELETE FROM counter_locations WHERE code = :c', ['c' => $code]); $gone = true; } catch (Throwable $e) {}
        }
    }
    CounterDesk::flush();
    if ($gone) { try { CounterDesk::syncSettingsMirror(); } catch (Throwable $e) {} }
    CounterDesk::flush();
    Settings::flush();
};

/** One throwaway login. */
$mkAdmin = static function (string $tail, string $role, string $desk = ''): array {
    $id = (int) Database::insert('admins', [
        'username'       => FX_USER . $tail,
        'password_hash'  => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
        'full_name'      => 'Fx1 Test ' . ucfirst($tail),
        'role'           => $role,
        'is_active'      => 1,
        'must_change_pw' => 0,
    ]);
    if ($desk !== '') {
        Database::insert('admin_profiles', ['admin_id' => $id, 'counter_code' => $desk]);
    }
    return Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $id]) ?? [];
};
$login = static function (?array $a): void {
    if ($a === null) { unset($_SESSION[ADMIN_SESSION_KEY]); return; }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
        'must_change_pw' => false, 'via_otp' => false,
    ];
};

/* A page of the panel, rendered as one login in a CHILD process (the page may exit). */
$harness = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'fx1ctr-page-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($harness, <<<'PHP'
<?php
$root = $argv[1]; $page = $argv[2]; $aid = (int) $argv[3]; parse_str($argv[4] ?? '', $_GET);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/admin/' . $page; $_SERVER['SCRIPT_NAME'] = '/admin/' . $page;
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
register_shutdown_function(static function (): void { echo "\n##CODE=" . var_export(http_response_code(), true) . "\n"; });
require $root . '/includes/bootstrap.php';
$a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $aid]);
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $a['id'], 'username' => $a['username'], 'full_name' => $a['full_name'], 'role' => $a['role'],
    'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(), 'must_change_pw' => false, 'via_otp' => false];
chdir($root . '/admin');
require $root . '/admin/' . $page;
PHP);
$page = static function (string $file, int $adminId, string $query = '') use ($ROOT, $harness): array {
    $ini  = (string) php_ini_loaded_file();
    $cmd  = array_merge([PHP_BINARY], $ini !== '' ? ['-c', $ini] : [], [$harness, $ROOT, $file, (string) $adminId, $query]);
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $ROOT);
    if (!is_resource($proc)) { return ['code' => 0, 'main' => '', 'all' => '']; }
    $out = (string) stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($proc);
    $code = preg_match('/##CODE=(\d+|false)/', $out, $m) === 1 ? (int) $m[1] : 0;
    $main = ($p = strpos($out, '<main')) !== false ? substr($out, $p) : $out;   // the page itself, without the menu
    return ['code' => $code === 0 ? 200 : $code, 'main' => $main, 'all' => $out . $err];
};

$clean();   // a run that died half-way leaves nothing behind for this one
try {
    /* ---------------- fixtures ---------------- */
    CounterDesk::save(['code' => FX_NPR, 'name' => 'Fx1 Test Nepal desk', 'country' => 'NP', 'currency' => 'NPR', 'fx_rate' => FX_RATE,
        'default_direction' => 'toIndia', 'entity' => 'NP', 'is_active' => 0, 'allowed_methods' => 'cash']);
    CounterDesk::save(['code' => FX_INR, 'name' => 'Fx1 Test India desk', 'country' => 'IN', 'currency' => 'INR',
        'default_direction' => 'toIndia', 'entity' => 'IN', 'is_active' => 0, 'allowed_methods' => 'cash']);
    CounterDesk::flush();
    $clerk  = $mkAdmin('clerk', 'counter', FX_NPR);
    $indian = $mkAdmin('ind', 'counter', FX_INR);
    $free   = $mkAdmin('free', 'counter');
    $agent  = $mkAdmin('agent', 'agent', FX_NPR);
    $office = Database::fetch("SELECT * FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1");
    check('fixtures: an NPR desk with its own rate, an India desk, four logins',
        CounterDesk::currency(FX_NPR) === 'NPR' && abs(CounterDesk::rate(FX_NPR) - FX_RATE) < 0.00001 && CounterDesk::currency(FX_INR) === 'INR'
        && $clerk !== [] && $indian !== [] && $free !== [] && $agent !== []);

    $setting('whatsapp_notify_customer', false, 'bool', 'notify');
    $setting('whatsapp_notify_admin', false, 'bool', 'notify');
    $setting('whatsapp_driver', 'click_to_chat', 'string', 'notify');
    $setting('aisensy_mode', 'off', 'string', 'whatsapp');
    $setting('sms_enabled', false, 'bool', 'notify');
    Settings::set('daily_service_on', true, 'bool', 'booking', true);
    $unset('desk_one_way_on');          // default = on
    $unset('desk_day_clerk_on');        // default = on
    $unset('default_rules');
    $setting('counter_desk_isolation', false, 'bool', 'counter');

    /* ---------------- A. whose day ---------------- */
    echo "-- A. desk-day: whose day --\n";
    check('office: the desk asked for', CounterDesk::dayListDesk($office, 'fxq', true) === FX_NPR);
    $first = CounterDesk::get('NPJ') !== null ? 'NPJ' : (string) (array_key_first(CounterDesk::all()) ?? '');
    check('office: nothing / an unknown desk asked = NPJ (else the first desk), as before',
        CounterDesk::dayListDesk($office, '', true) === $first && CounterDesk::dayListDesk($office, 'ZZZ9', true) === $first);
    check('clerk: own desk, whatever is asked', CounterDesk::dayListDesk($clerk, 'MSA', false) === FX_NPR
        && CounterDesk::dayListDesk($clerk, '', false) === FX_NPR && CounterDesk::dayListDesk($clerk, 'NPJ', false) === FX_NPR);
    check('clerk with no desk: no page', CounterDesk::dayListDesk($free, FX_NPR, false) === null);
    check('agent (own sales only): no page, even on a desk', CounterDesk::dayListDesk($agent, FX_NPR, false) === null);
    check('nobody: no page', CounterDesk::dayListDesk(null, FX_NPR, false) === null && CounterDesk::dayListDesk([], FX_NPR, false) === null);

    /* ---------------- B. NPR at the desk's own rate ---------------- */
    echo "-- B. the cash figure at an NPR desk --\n";
    $in = ['direction' => 'toIndia', 'date' => $DATE, 'name' => 'Fx Test One', 'phone' => FX_PHONE . '00001', 'country' => 'NP',
        'pax' => [['gender' => 'Male', 'band' => 'adult']], 'type' => 'sharing', 'discountType' => 'flat', 'discountValue' => 50];

    $login(null);
    $qc = SmartBook::quote(['direction' => 'toIndia', 'date' => $DATE, 'pax' => [['gender' => 'Male', 'band' => 'adult']], 'type' => 'sharing'], 'customer');
    check('customer quote: no fx / deskOnly / deskBlock keys (as before)', $qc['ok'] && is_array($qc['price'])
        && !array_key_exists('fx', $qc['price']) && !array_key_exists('deskOnly', $qc) && !array_key_exists('deskBlock', $qc));
    $dc = SmartBook::defaults(['direction' => 'toNepal'], 'customer');
    check('customer defaults: no deskOnly key, the asked run kept', !array_key_exists('deskOnly', $dc) && $dc['direction'] === 'toNepal');

    $login($free);
    $qf = SmartBook::quote($in, SmartBook::sessionMode());
    check('counter with no desk: no fx (sells in ₹)', $qf['ok'] && !isset($qf['price']['fx']) && SmartBook::deskOneWay() === '');

    $login($indian);
    $qi = SmartBook::quote($in, SmartBook::sessionMode());
    check('India desk: no fx, sells both runs', $qi['ok'] && !isset($qi['price']['fx']) && SmartBook::deskOneWay() === '' && !isset($qi['deskOnly']));

    $login($clerk);
    $mode = SmartBook::sessionMode();
    check('the clerk sells in Counter mode', $mode === 'counter');
    $q = SmartBook::quote($in, $mode);
    $want = CounterDesk::convert((float) $q['price']['total'], FX_NPR);
    check('NPR desk quote: price.fx = CounterDesk::convert(total, desk)', $q['ok'] && isset($q['price']['fx'])
        && $q['price']['fx']['currency'] === 'NPR' && abs($q['price']['fx']['rate'] - FX_RATE) < 0.00001
        && (float) $q['price']['fx']['amount'] === (float) $want['amount'] && $q['price']['fx']['desk'] === FX_NPR,
        json_encode($q['price']['fx'] ?? null));
    check('... at the DESK rate, not the company peg (price.npr is still the peg, untouched)',
        (float) $q['price']['fx']['amount'] === (float) round((float) $q['price']['total'] * FX_RATE)
        && (float) $q['price']['npr'] === (float) round((float) $q['price']['total'] * (float) SiteFacts::all()['npr'])
        && (float) $q['price']['fx']['amount'] !== (float) $q['price']['npr']);
    check('the desk discount is inside it (₹50 off)', abs((float) $q['price']['desk'] - 50.0) < 0.01, (string) ($q['price']['desk'] ?? ''));

    $seller = ['adminId' => (int) $clerk['id'], 'source' => 'counter', 'paymentMethod' => 'cash', 'note' => '', 'ticketNote' => '',
        'luggagePieces' => 0, 'infantCount' => 0, 'discountType' => 'flat', 'discountValue' => 50];
    $key = 'fx1ctr' . bin2hex(random_bytes(8));
    $sale = SmartBook::book($in + ['expect' => ['total' => $q['price']['total'], 'date' => $q['date']]], 'cash', $mode, $seller, $key);
    $row  = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => (string) ($sale['pnr'] ?? '')]) ?? [];
    check('the sale is made and confirmed at the desk', ($sale['status'] ?? '') === 'confirmed' && (string) ($row['counter_code'] ?? '') === FX_NPR,
        json_encode([$sale['status'] ?? null, $row['counter_code'] ?? null]));
    check('price: the ticket total = the quote total (₹)', abs((float) ($row['total_amount'] ?? 0) - (float) $q['price']['total']) < 0.01,
        ($row['total_amount'] ?? '') . ' vs ' . $q['price']['total']);
    check('cash figure: the रू on the card = the रू frozen on the ticket', ($row['fx_currency'] ?? '') === 'NPR'
        && (float) ($row['fx_total'] ?? 0) === (float) $q['price']['fx']['amount'] && abs((float) ($row['fx_rate'] ?? 0) - FX_RATE) < 0.00001,
        json_encode([$row['fx_total'] ?? null, $q['price']['fx']['amount']]));
    check('after-sale strip: fx comes from the booking row', isset($sale['fx']) && (float) $sale['fx']['amount'] === (float) $row['fx_total']
        && $sale['fx']['currency'] === 'NPR' && abs((float) $sale['fx']['rate'] - FX_RATE) < 0.00001);
    $pay = Database::fetch("SELECT * FROM payments WHERE booking_id = :b AND status = 'verified' ORDER BY id DESC LIMIT 1", ['b' => (int) ($row['id'] ?? 0)]) ?? [];
    check('the payment is the clerk\'s cash, in ₹ = the ticket', $pay !== [] && (int) ($pay['verified_by'] ?? 0) === (int) $clerk['id']
        && in_array((string) ($pay['method'] ?? ''), ['cash', 'cod'], true) && abs((float) $pay['amount'] - (float) $row['total_amount']) < 0.01);
    if (array_key_exists('local_amount', $pay)) {
        check('drawer: payments.local_amount (what the shift counts) = the ticket\'s रू', (float) $pay['local_amount'] === (float) $row['fx_total']
            && strtoupper((string) ($pay['local_currency'] ?? '')) === 'NPR', json_encode([$pay['local_amount'], $row['fx_total']]));
        require_once INCLUDE_PATH . '/countershift.php';
        $tot = CounterShift::totals((int) $clerk['id'], date('Y-m-d 00:00:00'), null, 'NPR', FX_RATE);
        check('shift totals for this clerk add up to exactly that sale (रू and ₹)', (float) $tot['cash'] === (float) $row['fx_total']
            && abs((float) $tot['cashInr'] - (float) $row['total_amount']) < 0.01 && (int) $tot['tickets'] === 1, json_encode($tot));
        $other = CounterShift::totals((int) $indian['id'], date('Y-m-d 00:00:00'), null, 'INR', 1.0);
        check('another desk\'s clerk sees none of that cash in their shift', (float) $other['cash'] === 0.0 && (int) $other['tickets'] === 0);
    }
    $nSeats = (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b', ['b' => (int) ($row['id'] ?? 0)], 0);
    check('one berth for one adult, the berth the answer names', $nSeats === 1 && count((array) ($sale['seats'] ?? [])) === 1);

    /* ---------------- D. double submit ---------------- */
    echo "-- D. double submit --\n";
    $again = SmartBook::book($in + ['expect' => ['total' => $q['price']['total'], 'date' => $q['date']]], 'cash', $mode, $seller, $key);
    check('the same key again = the same ticket, replayed', !empty($again['replayed']) && ($again['pnr'] ?? '') === ($sale['pnr'] ?? 'x'));
    check('... and ONE booking for that passenger', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . FX_PHONE . "00001'", [], 0) === 1);
    $reuse = '';
    try {
        SmartBook::book(['name' => 'Fx Test Two', 'phone' => FX_PHONE . '00002'] + $in + ['expect' => ['date' => $q['date']]], 'cash', $mode, $seller, $key);
    } catch (Throwable $e) { $reuse = get_class($e); }
    check('the same key for ANOTHER passenger is refused (never the first one\'s ticket)', $reuse === 'SmartBookKeyReused', $reuse);
    check('... and nothing was booked for them', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . FX_PHONE . "00002'", [], 0) === 0);

    /* ---------------- C. one-way desk ---------------- */
    echo "-- C. one-way desk on the card --\n";
    check('deskOneWay: the Nepal desk sells Rupaidiha → Gujarat only', SmartBook::deskOneWay() === 'toIndia');
    $d = SmartBook::defaults(['direction' => 'toNepal'], 'counter');
    check('defaults: a remembered Gujarat → Nepal loses to the desk', $d['direction'] === 'toIndia' && ($d['deskOnly'] ?? '') === 'toIndia'
        && $d['pointType'] === 'drop', json_encode([$d['direction'], $d['deskOnly'] ?? null, $d['pointType']]));
    $dropNames = array_map(static fn(array $p): string => $p['name'], SmartBook::points('toIndia', 'counter')['list']);
    check('... and the point offered belongs to that run', $d['point'] === '' || in_array($d['point'], $dropNames, true), (string) $d['point']);
    $setting('default_rules', ['fixed' => ['direction' => 'toNepal']], 'json', 'booking');
    $dr = SmartBook::defaults([], 'counter');
    check('an office Default Rule naming the other run cannot win either', $dr['direction'] === 'toIndia' && ($dr['deskOnly'] ?? '') === 'toIndia');
    $unset('default_rules');

    $inBack = ['direction' => 'toNepal', 'date' => $DATE, 'name' => 'Fx Test Three', 'phone' => FX_PHONE . '00003', 'country' => 'IN',
        'pax' => [['gender' => 'Male', 'band' => 'adult']], 'type' => 'sharing'];
    $qb = SmartBook::quote($inBack, 'counter');
    check('quote of the other run: priced as before, plus deskBlock', $qb['ok'] && (float) $qb['price']['total'] > 0
        && ($qb['deskOnly'] ?? '') === 'toIndia' && str_contains((string) ($qb['deskBlock'] ?? ''), '(' . FX_NPR . ')')
        && str_contains((string) ($qb['deskBlock'] ?? ''), 'Rupaidiha → Gujarat tickets only'), (string) ($qb['deskBlock'] ?? ''));
    check('quote of the desk\'s own run: deskOnly, no deskBlock', ($q['deskOnly'] ?? '') === 'toIndia' && !isset($q['deskBlock']));
    $thrown = '';
    try {
        SmartBook::book($inBack + ['expect' => ['date' => $qb['date']]], 'cash', 'counter', ['discountType' => '', 'discountValue' => 0] + $seller, 'fx1ctr' . bin2hex(random_bytes(8)));
    } catch (Throwable $e) { $thrown = $e->getMessage(); }
    check('the real sale throws the SAME sentence the card showed', $thrown !== '' && $thrown === (string) ($qb['deskBlock'] ?? 'x'), $thrown);
    check('... and no ticket was made', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . FX_PHONE . "00003'", [], 0) === 0);

    $login($agent);
    $da = SmartBook::defaults(['direction' => 'toNepal'], SmartBook::sessionMode());
    check('an AGENT on that desk: defaults untouched (Online has no desk seller)', SmartBook::sessionMode() === 'agent'
        && $da['direction'] === 'toNepal' && !array_key_exists('deskOnly', $da));
    $qa = SmartBook::quote($inBack, 'agent');
    check('... the quote still warns about Cash (deskBlock)', $qa['ok'] && isset($qa['deskBlock']));

    $login($clerk);
    $setting('desk_one_way_on', false);
    $d0 = SmartBook::defaults(['direction' => 'toNepal'], 'counter');
    $q0 = SmartBook::quote($inBack, 'counter');
    check('desk_one_way_on = 0: as before - the asked run, no deskOnly / deskBlock', SmartBook::deskOneWay() === ''
        && $d0['direction'] === 'toNepal' && !array_key_exists('deskOnly', $d0) && !isset($q0['deskOnly']) && !isset($q0['deskBlock']));
    check('... the NPR figure does not depend on that switch', isset($q0['price']['fx']) && $q0['price']['fx']['currency'] === 'NPR');
    $unset('desk_one_way_on');

    /* ---------------- E. the pages ---------------- */
    echo "-- E. the pages, as each login --\n";
    $today = todayISO();
    $p = $page('desk-day.php', (int) $clerk['id'], 'desk=NPJ&day=' . $today);
    check('desk-day: the clerk gets their OWN desk (asked NPJ)', $p['code'] === 200 && str_contains($p['main'], FX_NPR) && str_contains($p['main'], (string) $sale['pnr']),
        'code ' . $p['code']);
    check('... the ticket in the desk\'s money (रू frozen on the sale)', str_contains($p['main'], 'रू ' . number_format((float) $row['fx_total'], 0)));
    check('... the desk picker is locked and no office-only link is drawn', str_contains($p['main'], '<select name="desk" disabled') && !str_contains($p['main'], 'day-memo.php'));
    check('... no PHP warning on the page', !preg_match('/(Warning|Notice|Deprecated|Fatal error)\b.* in .* on line/i', $p['all']));
    $p = $page('desk-day.php', (int) $agent['id'], 'desk=' . FX_NPR);
    check('desk-day: an agent is refused (403) and sees no ticket', $p['code'] === 403 && !str_contains($p['main'], (string) $sale['pnr']), 'code ' . $p['code']);
    $p = $page('desk-day.php', (int) $free['id'], 'desk=' . FX_NPR);
    check('desk-day: a clerk with no desk is refused (403)', $p['code'] === 403 && !str_contains($p['main'], (string) $sale['pnr']), 'code ' . $p['code']);
    $setting('desk_day_clerk_on', false);
    $p = $page('desk-day.php', (int) $clerk['id'], '');
    check('desk_day_clerk_on = 0: office only again (clerk 403)', $p['code'] === 403, 'code ' . $p['code']);
    $unset('desk_day_clerk_on');
    if ($office !== null) {
        $p = $page('desk-day.php', (int) $office['id'], 'desk=' . FX_NPR . '&day=' . $today);
        check('desk-day: the office picks any desk and keeps the Day memo link', $p['code'] === 200 && str_contains($p['main'], (string) $sale['pnr'])
            && str_contains($p['main'], 'day-memo.php') && !str_contains($p['main'], '<select name="desk" disabled'), 'code ' . $p['code']);
        $p = $page('nepal-counter.php', (int) $office['id'], '');
        check('Nepal counter home (office): Cash book tile, no dead Quick-ticket tile, list + memo links',
            $p['code'] === 200 && str_contains($p['main'], '/admin/cash-book.php') && !str_contains($p['main'], '/admin/quick-ticket.php')
            && str_contains($p['main'], '/admin/desk-day.php?desk=') && str_contains($p['main'], '/admin/day-memo.php?desk='), 'code ' . $p['code']);
        check('... no PHP warning on the page', !preg_match('/(Warning|Notice|Deprecated|Fatal error)\b.* in .* on line/i', $p['all']));
    }
    $p = $page('nepal-counter.php', (int) $indian['id'], '');
    check('Nepal counter home: an INDIA-desk clerk gets "not on a Nepal desk", not a ₹ desk drawn in रू',
        $p['code'] === 200 && str_contains($p['main'], 'This login is not on a Nepal desk') && !str_contains($p['main'], 'class="nc-rate"'), 'code ' . $p['code']);
    /* review fixes: an office role built in Permissions (reports.view without bookings.view)
       still opens the day list; a login with neither is refused by the page itself */
    $rep = $mkAdmin('rep', 'official');
    Database::run('UPDATE admins SET permissions = :p WHERE id = :i', ['p' => json_encode(['reports.view']), 'i' => (int) $rep['id']]);
    $p = $page('desk-day.php', (int) $rep['id'], 'desk=' . FX_NPR . '&day=' . $today);
    check('desk-day: reports.view WITHOUT bookings.view is the office, as before (200, any desk)',
        $p['code'] === 200 && str_contains($p['main'], (string) $sale['pnr']) && !str_contains($p['main'], '<select name="desk" disabled'), 'code ' . $p['code']);
    $bare = $mkAdmin('bare', 'official');
    $p = $page('desk-day.php', (int) $bare['id'], 'desk=' . FX_NPR . '&day=' . $today);
    check('desk-day: a login with neither reports.view nor bookings.view is refused (403), no ticket',
        $p['code'] === 403 && !str_contains($p['main'], (string) $sale['pnr']), 'code ' . $p['code']);
    /* the Nepal home as people on a LIVE NPR desk (the suite's own desk is inactive, so the
       real NPJ is used when this database has it; only marker logins are added to it) */
    $npj = CounterDesk::all(true)['NPJ'] ?? null;
    if ($npj !== null && CounterDesk::currency('NPJ') === 'NPR') {
        $nClerk = $mkAdmin('npjc', 'counter', 'NPJ');
        $nAgent = $mkAdmin('npja', 'agent', 'NPJ');
        $nSup   = $mkAdmin('npjs', 'support', 'NPJ');
        $p = $page('nepal-counter.php', (int) $nClerk['id'], '');
        check('Nepal home, the desk\'s clerk: the day panel, and the Cash book tile opens THIS desk\'s book (?desk=)',
            $p['code'] === 200 && str_contains($p['main'], 'id="ncDayH"') && str_contains($p['main'], 'href="/admin/cash-book.php?desk=NPJ"'), 'code ' . $p['code']);
        check('... no PHP warning on the page', !preg_match('/(Warning|Notice|Deprecated|Fatal error)\b.* in .* on line/i', $p['all']));
        $p = $page('nepal-counter.php', (int) $nAgent['id'], '');
        check('Nepal home, an AGENT on the desk: no "Today at the desk" money panel (own sales only), no list link',
            $p['code'] === 200 && !str_contains($p['main'], 'id="ncDayH"') && !str_contains($p['main'], '/admin/desk-day.php')
            && str_contains($p['main'], 'href="/admin/cash-book.php"'), 'code ' . $p['code']);
        $p = $page('nepal-counter.php', (int) $nSup['id'], '');
        check('Nepal home, a login with no payments.view: no money panel either',
            ($p['code'] === 200 && !str_contains($p['main'], 'id="ncDayH"')) || $p['code'] === 403, 'code ' . $p['code']);
        $p = $page('cash-book.php', (int) $nClerk['id'], 'desk=NPJ');
        check('cash book ?desk=NPJ opens for that clerk (200, no warning)', $p['code'] === 200
            && !preg_match('/(Warning|Notice|Deprecated|Fatal error)\b.* in .* on line/i', $p['all']), 'code ' . $p['code']);
    } else {
        echo "  (no active NPR desk NPJ in this database - the three Nepal-home role renders are skipped)\n";
    }
    $p = $page('cash-book.php', (int) $clerk['id'], '');
    check('cash book opens for the clerk (200, no warning)', $p['code'] === 200 && !preg_match('/(Warning|Notice|Deprecated|Fatal error)\b.* in .* on line/i', $p['all']), 'code ' . $p['code']);
    $p = $page('smartbook.php', (int) $clerk['id'], '');
    check('SmartBook desk page for the Nepal clerk: +977 first, Nepali, the switches on the page',
        $p['code'] === 200 && str_contains($p['main'], '"deskCc":"NP"') && str_contains($p['main'], '"fast":true') && str_contains($p['main'], '"nprFirst":true')
        && str_contains($p['main'], '"twoBtn":true') && str_contains($p['main'], '"printTouch":false') && str_contains($p['main'], 'var SBX_LANG_DEF = "ne"'), 'code ' . $p['code']);
    check('... no PHP warning, no parcel UI', !preg_match('/(Warning|Notice|Deprecated|Fatal error)\b.* in .* on line/i', $p['all']) && stripos($p['main'], 'parcel') === false);
    $p = $page('smartbook.php', (int) $indian['id'], '');
    check('SmartBook desk page for an India clerk: +91 stays first', $p['code'] === 200 && str_contains($p['main'], '"deskCc":""'), 'code ' . $p['code']);

    /* ---------------- F. wiring ---------------- */
    echo "-- F. wiring --\n";
    $js = $src('assets/js/27-smartbook.js');
    check('key guard: a re-quote never drops the key of a sale in flight', str_contains($js, 'if (!S.busy) S.idem = null;   // anything changed'));
    check('key guard: late autofill (who / name guess) stays out while a sale is in flight or waiting for its retry',
        substr_count($js, 'if (S.busy || S.idem) return;') === 2);
    check('book(): one sale at a time (busy) + one key per intent, kept on a lost answer',
        str_contains($js, 'if (S.busy) return;') && str_contains($js, 'S.busy = true; S.idem = S.idem || newKey();') && str_contains($js, 'if (er && er.status) S.idem = null;'));
    check('fast entry is STAFF + switch only', str_contains($js, 'var FAST = !!(STAFF && STAFF.fast);')
        && str_contains($js, "if (FAST && e.key === 'Enter' && !e.isComposing && e.target.id === 'sbPhone')")
        && str_contains($js, "if (FAST && e.key === 'Enter' && !e.isComposing && e.target.id === 'sbName' && coarse())"));
    check('a keyboard keeps Enter = one more passenger and Ctrl+Enter = book + print',
        str_contains($js, "if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); book('cash', true); return; }")
        && str_contains($js, "if (e.key === 'Enter' && (e.target.id === 'sbName' || e.target.closest('#sbPax'))) {"));
    check('F | M two buttons: STAFF + switch, only while nothing is chosen, never pre-set',
        str_contains($js, "if (STAFF && STAFF.twoBtn && g === 'u') {") && str_contains($js, 'data-g="Female"') && str_contains($js, 'data-g="Male"')
        && str_contains($js, "(gWant === 'Female' || gWant === 'Male')"));
    check('the customer chip is untouched (first tap as before)', str_contains($js, "p.gender = p.gender === 'Female' ? 'Male' : (p.gender === 'Male' ? 'Female' : 'Female'); p.ai = false; }"));
    check('NPR first: STAFF + switch, the quoted total is the server\'s own figure', str_contains($js, 'var f = STAFF && STAFF.nprFirst && q && q.price ? q.price.fx : null;')
        && str_contains($js, 'if (Math.abs(inr - Number(q.price.total)) < 0.005 && Number(f.amount) > 0) return nprRs(f.amount);'));
    check('the customer money lines are still in the file as they were', str_contains($js, "esc(q.price ? curAmt(Number(q.price.total) + dxS.amt, q) : '—')")
        && str_contains($js, "var tot = q && q.ok && q.price ? (inNpr() ? npr(q.price.npr) : money(q.price.total)) : '—';"));
    check('one-way desk: STAFF only, an agent\'s Online goes on', str_contains($js, "if (STAFF && S.q.deskBlock && (pay === 'cash' || MODE !== 'agent')) {")
        && str_contains($js, "var oneWay = !!(STAFF && S.deskOnly && MODE !== 'agent');"));
    check('a desk never keeps a passenger in the shared browser store (sb:v1 = the desk\'s habits only)',
        str_contains($js, "JSON.stringify({ dir: S.dir, point: S.point, cc: S.cc, pickup: S.pickup || '', type: S.type, cabin: S.cabin, berth: S.berth })")
        && !str_contains($js, "if (FAST) { saved.phone = ''; saved.name = ''; saved.pax = null; }"));
    check('... the UNFINISHED entry survives a reload in its own tab only (sessionStorage), never an AI-guessed gender',
        str_contains($js, "sessionStorage.setItem('sb:draft'") && str_contains($js, "if (FAST) { var dr = draft.get();")
        && str_contains($js, "return { gender: p.ai ? '' : p.gender, band: p.band };") && !str_contains($js, "localStorage.setItem('sb:draft'"));
    check('... and is dropped the moment a sale is made and when the card is cleared',
        str_contains($js, 'if (FAST) { S.soldKey = draft.key(); draft.clear(); }')
        && str_contains($js, "if (!some || (S.soldKey && S.soldKey === draft.key())) { draft.clear(); return; }"));
    check('NPR first on the CARD is Counter / Admin only (an agent\'s Online sale freezes no NPR); the Cash sheet keeps it',
        str_contains($js, "function cardFx(q) { return MODE !== 'agent' ? deskFx(q) : null; }")
        && str_contains($js, 'if (q && q.ok && q.price && cardFx(q)) { tot = deskAmt(q.price.total, q); altM = deskAlt(q.price.total, q); }')
        && str_contains($js, "var okNpr = q.price ? deskAmt(Number(q.price.total) + dxS.amt, q) : '';"));
    check('80 mm print: off by itself only on a phone, only when the page says so', str_contains($js, "STAFF.printTouch === false && window.matchMedia && window.matchMedia('(pointer:coarse) and (max-width:600px)').matches"));
    check('after a sale the desk opens the booking; the customer keeps Track', str_contains($js, "'<a href=\"/admin/booking-view.php?pnr=' + encodeURIComponent(r.pnr)")
        && str_contains($js, "'<a href=\"#/trip/' + encodeURIComponent(r.pnr) + '\">' + esc(tr('track')) + '</a>'"));
    $adm = $src('admin/smartbook.php');
    foreach (['desk_npr_first_on', 'desk_fast_entry_on', 'desk_gender_two_btn_on', 'desk_print80_touch_on', 'staff_card_lang'] as $k) {
        check("switch on the desk page: $k", str_contains($adm, "'" . $k . "'"));
    }
    check('desk page: 44px controls + 16px inputs under a finger', str_contains($adm, '@media (pointer:coarse){')
        && str_contains($adm, '.sbx-chips button{min-height:44px') && str_contains($adm, 'min-height:44px;font-size:16px}'));
    check('desk page: language buttons (ne / hi / en) set shg:lang and <html lang>', str_contains($adm, 'data-lang="ne"') && str_contains($adm, 'data-lang="hi"')
        && str_contains($adm, 'data-lang="en"') && str_contains($adm, "localStorage.setItem('shg:lang', LANG)") && str_contains($adm, 'document.documentElement.lang = LANG;'));
    $dd = $src('admin/desk-day.php');
    check('desk-day boots signed-in only and decides itself (office = reports.view; clerk = bookings + payments + own desk)',
        str_contains($dd, "admin_boot('')") && str_contains($dd, "'desk_day_clerk_on'") && str_contains($dd, "Auth::can('reports.view')")
        && str_contains($dd, 'CounterDesk::dayListDesk(') && str_contains($dd, "Auth::can('bookings.view') && Auth::can('payments.view')"));
    $nc = $src('admin/nepal-counter.php');
    check('Nepal home: the day panel and its query are for the office and the desk\'s money-handling clerks only',
        str_contains($nc, "\$mayDay  = \$isOffice || (Auth::can('payments.view') && Auth::bookingScopeAdminId() === null);")
        && str_contains($nc, "if (\$desk !== '' && \$mayDay) {") && str_contains($nc, '<?php if ($mayDay): ?>'));
    /* the migration: every settings label fits the column (VARCHAR(191)) - a longer one is cut mid-sentence */
    $mig = (string) preg_replace('/^--.*$/m', '', $src('database/upgrade-2026-11-26-fx1-counter.sql'));
    preg_match_all("/'((?:[^']|'')*)'/u", $mig, $mm);
    $long = array_filter($mm[1], static fn(string $s): bool => mb_strlen(str_replace("''", "'", $s), 'UTF-8') > 190);
    check('migration: no settings label longer than the column (190 characters)', $mm[1] !== [] && $long === [], (string) count($long));
    check('migration: additive only (INSERT IGNORE + label UPDATE by key; no DROP / DELETE / ALTER, no value rewritten)',
        str_contains($mig, 'INSERT IGNORE INTO settings') && preg_match('/\b(DROP|DELETE|ALTER|TRUNCATE)\b/i', $mig) !== 1
        && preg_match('/SET\s+(svalue|stype|sgroup|is_public)\b/i', $mig) !== 1);
    $cb = $src('admin/cash-book.php');
    check('cash book: the collect honours the agent scope; the confirm speaks the row\'s money',
        str_contains($cb, '$scopeBy !== null && (int) ($row[\'sold_by_admin_id\'] ?? 0) !== $scopeBy') && str_contains($cb, '$dm((float) $r[\'total_amount\'], $r)'));
    $parcel = [];
    foreach (['admin/smartbook.php', 'admin/quick-ticket.php', 'admin/nepal-counter.php', 'admin/desk-day.php', 'admin/my-desk.php', 'admin/shift.php',
        'admin/cash-book.php', 'admin/quick-unlock.php', 'assets/js/27-smartbook.js', 'assets/js/14-counter.js', 'assets/js/quick-unlock.js', 'assets/js/agent-picker.js'] as $f) {
        if (is_file($ROOT . '/' . $f) && stripos($src($f), 'parcel') !== false) { $parcel[] = $f; }
    }
    check('no parcel button / field / column in any desk file', $parcel === [], implode(', ', $parcel));
} catch (Throwable $e) {
    check('the suite ran to the end (' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')', false);
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    $clean();
    $restoreSettings();
    @unlink($harness);
}

check('cleanup: no passenger of this suite left', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . FX_PHONE . "%'", [], 0) === 0);
check('cleanup: no login of this suite left', (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE username LIKE '" . FX_USER . "%'", [], 0) === 0);
check('cleanup: no desk of this suite left', ($hadDesk[FX_NPR] || CounterDesk::get(FX_NPR) === null) && ($hadDesk[FX_INR] || CounterDesk::get(FX_INR) === null));
check('cleanup: no idem key of this suite left', (int) Database::scalar("SELECT COUNT(*) FROM smartbook_requests WHERE idem_key LIKE 'fx1ctr%'", [], 0) === 0);

echo "\n   fx1-counter: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
