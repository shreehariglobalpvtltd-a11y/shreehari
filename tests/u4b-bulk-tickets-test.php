<?php
/**
 * =====================================================================
 *  u4b-bulk-tickets-test.php — P8-7c (16 Nov 2026): bulk tickets from the
 *  panel (admin/bulk-tickets.php) on the WhatsApp bulk engine (WaBulk).
 *
 *  Pure part:
 *    - WaBulk::csvLines(): an Excel CSV (BOM, header row in any order,
 *      semicolons, an old Windows-1252 save) -> one passenger per line
 *
 *  Database part (shari_test only; every row it makes is deleted):
 *    - quote from the panel stages the list under 'panel:<admin id>' with
 *      a one-shot token; nothing is sold or held
 *    - a wrong token sells nothing and leaves the quote alone
 *    - the right token sells every booking once; the same token again
 *      sells nothing (consumed)
 *    - the WhatsApp path keeps its own switch (wa_bulk_on) - checked in
 *      this process only, the shared settings row is never written
 *    - the page: renders for a seller, previews a list (token in the
 *      Confirm form), refuses a bad CSRF token, a reader role may not
 *      sell, a page Confirm with a wrong token sells nothing
 *
 *  Customer messages are pinned OFF while it runs (put back after).
 *
 *    php -c .claude/php-dev.ini tests/u4b-bulk-tickets-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: the admin page in its own PHP process ---------------- */
if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_SERVER['HTTP_HOST']   = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
    $_SERVER['SCRIPT_NAME'] = '/admin/bulk-tickets.php';
    $_SERVER['REQUEST_URI'] = '/admin/bulk-tickets.php';
    $_GET  = (array) ($cfg['get'] ?? []);
    $post  = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    /* customer messages off for THIS process too (the parent pins the rows; this is belt and braces) */
    Settings::get('whatsapp_notify_customer');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    foreach (['whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'sms_enabled' => '0'] as $k => $v) {
        $c[$k] = ['value' => $v, 'type' => 'bool', 'public' => false];
    }
    $rp->setValue(null, $c);
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME]  = ($cfg['csrf'] ?? 'valid') === 'valid' ? Security::csrfToken() : 'not-the-token';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    }
    require dirname(__DIR__) . '/admin/bulk-tickets.php';
    exit(0);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/wabulk.php';
require_once INCLUDE_PATH . '/quickticket.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
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
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
}

echo "\n=== U4b bulk tickets (P8-7c) ===\n\n";

/* ---- 1. Pure part ------------------------------------------------------ */
echo "-- CSV reader\n";
$csv = "\xEF\xBB\xBFName,Mobile,Gender,Age\r\nRam Thapa,9876543210,M,35\r\n\r\nSita Thapa,9876543211,F,32\r\n";
check('header row + BOM: one line per passenger, in Name Mobile Gender Age order',
    WaBulk::csvLines($csv) === ['Ram Thapa 9876543210 M 35', 'Sita Thapa 9876543211 F 32'], json_encode(WaBulk::csvLines($csv), JSON_UNESCAPED_UNICODE));
$csv2 = "Mobile;Age;Name\n9876543210;35;राम थापा\n";
check('semicolons and columns in another order (named by the header)', WaBulk::csvLines($csv2) === ['राम थापा 9876543210 35'], json_encode(WaBulk::csvLines($csv2), JSON_UNESCAPED_UNICODE));
check('no header row: Name, Mobile, Gender, Age', WaBulk::csvLines("Hari Gurung,977 9812345678,M\n") === ['Hari Gurung 977 9812345678 M']);
check('a first passenger named like a header word ("Naamdev", "Yatri") is a passenger, not a header',
    WaBulk::csvLines("Naamdev Sharma,9876543210,M,30\nRam Thapa,9876543211,M,20\n") === ['Naamdev Sharma 9876543210 M 30', 'Ram Thapa 9876543211 M 20']
    && WaBulk::csvLines("Yatri Prasad;98765 43212;M;40\n") === ['Yatri Prasad 98765 43212 M 40'],
    (string) json_encode(WaBulk::csvLines("Naamdev Sharma,9876543210,M,30\nRam Thapa,9876543211,M,20\n"), JSON_UNESCAPED_UNICODE));
check('an old Windows-1252 save becomes UTF-8', WaBulk::csvLines("Jos\xE9 Kumar,9876543212\n") === ['José Kumar 9876543212']);
check('quoted cells with a comma inside are one cell', WaBulk::csvLines("\"Thapa, Ram\",9876543210\n") === ['Thapa, Ram 9876543210']);
$parsed = WaBulk::parse(implode("\n", WaBulk::csvLines($csv)));
check('what csvLines() makes, parse() reads: 2 bookings, 2 passengers, no error', count($parsed['bookings']) === 2 && $parsed['pax'] === 2 && $parsed['errors'] === [], json_encode($parsed['errors'], JSON_UNESCAPED_UNICODE));

/* ---- 2. Database part -------------------------------------------------- */
echo "\n-- Database part\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
    echo "  \033[33mSKIP\033[0m  database part: DB_NAME is not shari_test — refusing to write\n";
    finish();
}
$super = Database::fetch("SELECT * FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1");
$route = null;
foreach (QuickTicket::routes() as $r) {
    if ((string) $r['direction'] === 'toNepal') { $route = $r; break; }
}
if ($super === null || $route === null) {
    echo "  \033[33mSKIP\033[0m  database part: needs an active super-admin and a route towards Nepal\n";
    finish();
}
$superId = (int) $super['id'];
$_SESSION[ADMIN_SESSION_KEY] = ['id' => $superId, 'username' => (string) $super['username'], 'full_name' => (string) ($super['full_name'] ?? ''),
    'role' => 'superadmin', 'permissions' => [], 'last_seen' => time()];

$date   = addDaysISO(todayISO(), 97);
$rnd    = (string) random_int(1000000, 9999999);
$phoneA = '986' . $rnd;                         // one passenger
$phoneB = '985' . $rnd;                         // a family of two on one number
$phones = [$phoneA, $phoneB];
$schedBefore = array_map('intval', array_column(Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $date]), 'id'));
$startAudit  = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$madeAdmins  = [];

/* nothing may reach a real phone: customer / admin messages off, put back after */
$PINNED = ['whatsapp_notify_customer' => [false, 'bool', 'notify'], 'whatsapp_notify_admin' => [false, 'bool', 'notify'],
           'sms_enabled' => [false, 'bool', 'notify'], 'daily_service_on' => [true, 'bool', 'booking']];
$prior = [];
foreach (array_keys($PINNED) as $k) { $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]); }
foreach ($PINNED as $k => [$v, $t, $g]) { Settings::set($k, $v, $t, $g, false); }
Settings::flush();

$bookingsOf = static fn(): array => Database::fetchAll(
    'SELECT id, pnr, status, contact_phone, total_amount, source FROM bookings WHERE contact_phone IN (:a, :b) ORDER BY id', ['a' => $phoneA, 'b' => $phoneB]);
$cleanup = static function () use (&$madeAdmins, $phones, $date, $schedBefore, $startAudit, $superId, $PINNED, $prior): void {
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone IN (:a, :b)', ['a' => $phones[0], 'b' => $phones[1]]) as $r) {
        $bid = (int) $r['id'];
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications', 'coupon_redemptions', 'ticket_links'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        Database::delete('bookings', 'id = :i', ['i' => $bid]);
        foreach (['.png', '.pdf', '.png.lock', '.pdf.lock'] as $ext) {
            @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . $ext);
        }
        if (defined('INVOICE_PATH')) { @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf'); }
    }
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $date]) as $s) {
        $sid = (int) $s['id'];
        if (in_array($sid, $schedBefore, true)) { continue; }
        foreach (['seat_locks', 'schedule_unit_locks', 'booking_seats'] as $t) {
            try { Database::delete($t, 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
        }
        try { Database::delete('schedules', 'id = :s', ['s' => $sid]); } catch (Throwable $e) { echo "  !! schedule {$sid} not removed: " . $e->getMessage() . "\n"; }
    }
    foreach ($phones as $p) {
        foreach (['wa_outbox' => 'to_number', 'message_logs' => 'to_number'] as $t => $c) {
            try { Database::run("DELETE FROM {$t} WHERE {$c} LIKE :p", ['p' => '%' . $p . '%']); } catch (Throwable $e) {}
        }
        foreach (['customers' => 'phone', 'users' => 'phone'] as $t => $c) {
            try { Database::run("DELETE FROM {$t} WHERE {$c} = :p", ['p' => $p]); } catch (Throwable $e) {}
        }
    }
    foreach (array_merge([$superId], $madeAdmins) as $aid) {
        try { Database::delete('kv_store', "kscope = 'wa_bulk' AND kkey = :k", ['k' => 'panel:' . $aid]); } catch (Throwable $e) {}
        try { Database::delete('kv_store', "kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $aid]); } catch (Throwable $e) {}
        try { Database::delete('rate_limits', "bucket = 'bulk_tickets' AND identifier = :i", ['i' => (string) $aid]); } catch (Throwable $e) {}
    }
    try { Database::delete('audit_logs', "id > :s AND action LIKE 'bulk_tickets.%'", ['s' => $startAudit]); } catch (Throwable $e) {}
    foreach ($madeAdmins as $aid) {
        try { Database::delete('admins', 'id = :a', ['a' => $aid]); } catch (Throwable $e) {}
    }
    foreach ($PINNED as $k => $_) {
        $row = $prior[$k];
        if ($row === null) {
            try { Database::delete('settings', 'skey = :k', ['k' => $k]); } catch (Throwable $e) {}
        } else {
            try { Database::update('settings', ['svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'], 'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public']], 'skey = :k', ['k' => $k]); } catch (Throwable $e) {}
        }
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};

$list = "Ramesh Kharel {$phoneA} M 30\nSunita Kharel {$phoneB} F 28\nAsha Kharel {$phoneB} F 6\nDate: {$date}\nTo: nepal\nPay: cash";
$ctx  = ['phone' => 'panel:' . $superId, 'role' => 'staff', 'channel' => 'panel', 'turn' => 0, 'admin' => $super];

try {
    echo "\n-- Quote, then one-shot sale\n";
    $q = WaBulk::quote($ctx, WaBulk::parse($list));
    $qd = (array) ($q['data'] ?? []);
    check('the panel quote reads 2 bookings / 3 seats back', !empty($q['ok']) && count((array) ($qd['bookings'] ?? [])) === 2 && (int) ($qd['seats'] ?? 0) === 3,
        mb_substr((string) ($q['text'] ?? ''), 0, 300));
    check('it carries a one-shot token and the bus heading (panel only)', strlen((string) ($qd['token'] ?? '')) === 24 && is_array($qd['bus'] ?? null));
    check('the family shares one booking with both names', ($qd['bookings'][1]['passengers'] ?? []) === ['Sunita Kharel', 'Asha Kharel'] && ($qd['bookings'][1]['genders'] ?? []) === ['Female', 'Female']);
    check('nothing is sold by a quote', $bookingsOf() === []);
    check('the quote is staged under panel:<admin id>', WaBulk::staged('panel:' . $superId) !== null);
    $qw = WaBulk::quote(['phone' => '9800000001', 'role' => 'staff', 'channel' => 'whatsapp'], WaBulk::parse($list));
    check('a WhatsApp quote never carries the token (it would reach the model)', !isset($qw['data']['token']) && !isset($qw['data']['bus']));
    WaBulk::clear('9800000001');

    $bad = WaBulk::issue($ctx, $super, 'deadbeefdeadbeefdeadbeef');
    check('a wrong token sells nothing', empty($bad['ok']) && $bookingsOf() === [], (string) ($bad['text'] ?? ''));
    check('... and leaves the quote in place', WaBulk::staged('panel:' . $superId) !== null);

    $good = WaBulk::issue($ctx, $super, (string) $qd['token']);
    $gd   = (array) ($good['data'] ?? []);
    $rows = $bookingsOf();
    check('the right token sells both bookings', !empty($good['ok']) && count((array) ($gd['sold'] ?? [])) === 2 && count($rows) === 2,
        json_encode(['failed' => $gd['failed'] ?? null, 'rows' => $rows], JSON_UNESCAPED_UNICODE));
    check('each sale is a confirmed desk sale on the right number',
        count(array_filter($rows, static fn(array $r): bool => $r['status'] === 'confirmed' && in_array($r['source'], ['counter', 'admin', 'agent'], true))) === 2
        && array_values(array_unique(array_column($rows, 'contact_phone'))) == [$phoneA, $phoneB] || array_values(array_unique(array_column($rows, 'contact_phone'))) == [$phoneB, $phoneA]);
    $famId = 0;
    foreach ($rows as $r) { if ($r['contact_phone'] === $phoneB) { $famId = (int) $r['id']; } }
    check('the family booking holds 2 passengers under their own names',
        array_column(Database::fetchAll('SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => $famId]), 'full_name') === ['Sunita Kharel', 'Asha Kharel']);
    check('the quote is consumed', WaBulk::staged('panel:' . $superId) === null);
    $again = WaBulk::issue($ctx, $super, (string) $qd['token']);
    check('the same token again (a reload) sells nothing', empty($again['ok']) && count($bookingsOf()) === 2, (string) ($again['text'] ?? ''));

    echo "\n-- The WhatsApp switch still guards the WhatsApp path\n";
    Settings::get('wa_bulk_on');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $cache = (array) $rp->getValue();
    $saved = $cache['wa_bulk_on'] ?? null;
    $cache['wa_bulk_on'] = ['value' => '0', 'type' => 'bool', 'public' => false];
    $rp->setValue(null, $cache);
    $wa = WaBulk::issue(['phone' => '9800000002', 'role' => 'staff', 'channel' => 'whatsapp'], $super);
    check('wa_bulk_on OFF: a WhatsApp "ho" sells nothing', empty($wa['ok']) && str_contains((string) $wa['text'], 'बन्द'), (string) $wa['text']);
    $cache = (array) $rp->getValue();
    if ($saved === null) { unset($cache['wa_bulk_on']); } else { $cache['wa_bulk_on'] = $saved; }
    $rp->setValue(null, $cache);

    echo "\n-- The page\n";
    $pg = child(['admin' => $superId]);
    check('the page renders for a seller (paste box, CSV, sample link)', str_contains($pg, 'name="list"') && str_contains($pg, 'type="file" name="csv"') && str_contains($pg, '?sample=1'));
    check('no PHP warning on the page', !preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $pg));
    $pq = child(['admin' => $superId, 'post' => ['action' => 'quote', 'list' => "Ramesh Kharel {$phoneA} M 30", 'date' => $date, 'dir' => 'toNepal', 'pay' => 'cash']]);
    check('Preview reads the list back with a Confirm form carrying the token',
        str_contains($pq, 'Check before you confirm') && str_contains($pq, 'Ramesh Kharel') && preg_match('/name="token" value="[0-9a-f]{24}"/', $pq) === 1, mb_substr(strip_tags($pq), -400));
    check('no PHP warning on the preview', !preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $pq));
    check('the preview is audited', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action = 'bulk_tickets.quote'", ['s' => $startAudit], 0) >= 1);
    $pbad = child(['admin' => $superId, 'csrf' => 'bad', 'post' => ['action' => 'quote', 'list' => "Ramesh Kharel {$phoneA} M 30"]]);
    check('a bad CSRF token is refused', str_contains($pbad, 'Page was open too long') && !str_contains($pbad, 'Check before you confirm'));
    $before = count($bookingsOf());
    child(['admin' => $superId, 'post' => ['action' => 'issue', 'token' => 'deadbeefdeadbeefdeadbeef']]);
    check('a page Confirm with a wrong token sells nothing', count($bookingsOf()) === $before
        && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action = 'bulk_tickets.issue'", ['s' => $startAudit], 0) >= 1);
    check('... and the page preview\'s quote is still staged', WaBulk::staged('panel:' . $superId) !== null);
    child(['admin' => $superId, 'post' => ['action' => 'cancel']]);
    check('Cancel drops the staged quote', WaBulk::staged('panel:' . $superId) === null);

    $sup = Database::insert('admins', ['username' => 'u4b-sup-' . $rnd, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => 'U4b Support Test', 'role' => 'support', 'is_active' => 1, 'must_change_pw' => 0]);
    $madeAdmins[] = $sup;
    $ps = child(['admin' => $sup]);
    if (str_contains($ps, 'name="list"')) {
        check('a reader role is told it may not sell', str_contains($ps, 'can read this page but not sell'));
        $pss = child(['admin' => $sup, 'post' => ['action' => 'quote', 'list' => "Ramesh Kharel {$phoneA} M 30"]]);
        check('... and its Preview is refused', str_contains($pss, 'can view tickets but not sell') && !str_contains($pss, 'Check before you confirm'));
    } else {
        check('a role without bookings.view cannot open the page', !str_contains($ps, 'name="list"'));
    }
} catch (Throwable $e) {
    check('test ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
}
check('cleanup left no booking behind', $bookingsOf() === []);
finish();
