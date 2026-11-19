<?php
/**
 * =====================================================================
 *  i2-desk-ui-test.php — 19 Nov 2026 (integrator i2, desk / customer UI
 *  glue for u3 / u4 / u5 / u9 / u10). PHP side of:
 *
 *   A  admin/booking-view.php shows bookings.cash_note ("पैसा कहिले?") -
 *      display only
 *   B  admin/bookings.php?counter=<NPR desk> prints "रू … (₹…)" (Revenue
 *      card + the row), the NPR frozen on the sale
 *   C  admin/counters.php reads CounterBook::range(): an agent with no desk
 *      has their own line, Commission + Net columns
 *   D  api/track.php (the booking JSON the app ticket reads) carries
 *      fxCurrency / fxTotal / fxRate
 *   E  api/search.php carries salesClosed / clockLabel (r7 closed by a
 *      temporary sales_close_clock, restored at once) and seatsLow
 *   F  the JS glue is in place (static checks; node --check is separate)
 *
 *  Own throwaway rows on the LOCAL shari_test only (phones 90000103xx,
 *  names 'ZZTEST i2 …', admins i2_sd_*), money on the fixture day
 *  2001-11-22, seats on a free date 101-130 days out; all removed at the end.
 *
 *     SHG_TEST_BASE=http://localhost:8963 php -c .claude/php-dev.ini tests/i2-desk-ui-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---------------------------------------------------------------- runner */
if (($argv[1] ?? '') === '--run') {
    $page = (string) $argv[2];
    $get  = json_decode((string) base64_decode((string) ($argv[3] ?? ''), true), true) ?: [];
    $root = dirname(__DIR__);
    require_once $root . '/includes/bootstrap.php';
    if (DB_NAME !== 'shari_test') { fwrite(STDERR, "refusing: not shari_test\n"); exit(2); }
    $bossId = (int) Database::scalar("SELECT id FROM admins WHERE username = 'i2_sd_boss'", [], 1);
    $_SESSION = [];
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => $bossId, 'username' => 'i2_sd_boss', 'full_name' => 'ZZTEST i2 Boss', 'role' => 'superadmin',
        'permissions' => [], 'last_seen' => time(), 'qk_ok' => true,
    ];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME']    = '/' . $page;
    $_SERVER['REQUEST_URI']    = '/' . $page . ($get !== [] ? '?' . http_build_query($get) : '');
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_GET  = $get;
    $_POST = [];
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        echo "\n@@META@@" . json_encode(['http' => http_response_code() ?: 200,
            'err' => $err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) ? $err['message'] : null]);
    });
    try {
        require $root . '/' . $page;
    } catch (Throwable $e) {
        http_response_code(500);
        echo "\nUNCAUGHT " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    exit(0);
}

/* ----------------------------------------------------------------- suite */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/booking.php';
if (DB_NAME !== 'shari_test') { echo "refusing: not the local shari_test database\n"; exit(2); }
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8963'), '/'));

const I2_FD     = '2001-11-22';
const I2_PHONES = ['N1' => '9000010301', 'G1' => '9000010302'];
const I2_BOSS   = 'i2_sd_boss';
const I2_AGENT  = 'i2_sd_agent';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
/** @return array{http:int, body:string, uncaught:string, warn:string} */
function page(string $page, array $get = []): array {
    $ini = getenv('SHG_TEST_INI') ?: (php_ini_loaded_file() ?: '');
    $cmd = escapeshellarg(PHP_BINARY) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --run ' . escapeshellarg($page) . ' ' . base64_encode((string) json_encode($get)) . ' 2>&1';
    $out  = (string) shell_exec($cmd);
    $meta = ['http' => 0, 'err' => null];
    if (($p = strrpos($out, '@@META@@')) !== false) {
        $meta = json_decode(substr($out, $p + 8), true) ?: $meta;
        $out  = substr($out, 0, $p);
    }
    $unc  = preg_match('/UNCAUGHT [^\n]+/', $out, $m) === 1 ? $m[0] : (string) ($meta['err'] ?? '');
    $warn = preg_match('/(?:<b>)?(?:Warning|Notice|Deprecated|Fatal error)(?:<\/b>)?:\s[^\n]{0,200}/', $out, $w) === 1 ? $w[0] : '';
    return ['http' => (int) $meta['http'], 'body' => $out, 'uncaught' => $unc, 'warn' => $warn];
}
/** @return array{code:int, json:?array, body:string} */
function api(string $path, array $json): array {
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($json),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'i2-desk-ui-test']);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode($body, true), 'body' => $body];
}

function i2_cleanup(): void
{
    foreach (I2_PHONES as $p) {
        foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => $p]) as $r) {
            foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
            if (defined('INVOICE_PATH')) { @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf'); }
            foreach (['booking_collections', 'agent_ledger', 'pay_events'] as $t) {
                try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            }
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
    }
    foreach ([I2_BOSS, I2_AGENT] as $u) {
        $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
        if ($id > 0) {
            try { Database::run('DELETE FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            try { Database::run('DELETE FROM admin_profiles WHERE admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            Database::delete('admins', 'id = :i', ['i' => $id]);
        }
    }
}

echo "\n=== i2 · desk / customer UI glue ===\n\n";
$keep = [];   // settings to put back: key => row|null
$sched = ['id' => 0, 'made' => false];
$root  = dirname(__DIR__);
try {
    i2_cleanup();
    foreach (['whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'email_enabled' => '0'] as $k => $v) {
        $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
        Settings::set($k, $v, 'bool', 'notify', false);
    }
    Settings::flush();

    /* ---- seed: N1 (NPJ desk, NPR frozen 1.6, a cash note) + G1 (agent, no desk, ₹150 commission) */
    $TD = '';
    for ($i = 130; $i >= 101; $i--) {
        $d = date('Y-m-d', strtotime(todayISO() . " +$i days"));
        if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE travel_date = :d', ['d' => $d], 0) === 0) { $TD = $d; break; }
    }
    if ($TD === '') { throw new RuntimeException('no free travel date 101-130 days out'); }
    $route   = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
    $routeId = (int) $route['id'];
    $sched['made'] = Database::fetch('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 1', ['r' => $routeId, 'd' => $TD]) === null;
    $sc = Seats::schedule($routeId, $TD);
    $sched['id'] = (int) $sc['id'];
    Database::update('schedules', ['is_blocked' => 0, 'status' => 'scheduled'], 'id = :i', ['i' => $sched['id']]);

    $mk = static fn(string $u, string $role, string $name): int => (int) Database::insert('admins', ['username' => $u,
        'password_hash' => password_hash('I2SeedPw!77', PASSWORD_DEFAULT), 'full_name' => $name, 'role' => $role,
        'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
    $boss  = $mk(I2_BOSS, 'superadmin', 'ZZTEST i2 Boss');
    $agent = $mk(I2_AGENT, 'agent', 'ZZTEST i2 Agent');
    $at = I2_FD . ' 10:00:00';
    $sell = static function (string $k, string $seat) use ($routeId, $TD, $boss): array {
        $res = BookingService::create(['routeId' => $routeId, 'travelDate' => $TD, 'seats' => [$seat],
            'passengers' => [['name' => 'ZZTEST i2 ' . $k, 'age' => 31, 'gender' => 'Male']],
            'contact' => ['phone' => I2_PHONES[$k]], 'bookingMode' => 'sharing', 'paymentMethod' => 'cash', 'isCod' => false, 'boarding' => ''],
            ['adminId' => $boss, 'source' => 'admin', 'paymentMethod' => 'cash']);
        $row = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $res['pnr']]);
        Database::run('DELETE FROM agent_ledger WHERE booking_id = :b', ['b' => (int) $row['id']]);
        return $row;
    };
    $n1 = $sell('N1', 'L20');
    $nTot = (float) $n1['total_amount'];
    $nNpr = round($nTot * 1.6);
    $hasNote = CounterDesk::hasColumn('bookings', 'cash_note');
    Database::update('bookings', ['counter_code' => 'NPJ', 'status' => 'confirmed', 'sold_by_admin_id' => $boss, 'confirmed_at' => $at,
        'fx_currency' => 'NPR', 'fx_rate' => 1.6, 'fx_total' => $nNpr]
        + ($hasNote ? ['cash_note' => json_encode(['when' => 'partial', 'amount' => 1500, 'date' => '2026-11-25', 'note' => 'ZZTEST i2 bhai dinchha'])] : []),
        'id = :i', ['i' => (int) $n1['id']]);
    Database::update('payments', ['method' => 'cash', 'status' => 'verified', 'verified_by' => $boss, 'verified_at' => $at,
        'local_currency' => 'NPR', 'local_amount' => $nNpr, 'fx_rate' => 1.6], 'booking_id = :b', ['b' => (int) $n1['id']]);
    $g1 = $sell('G1', 'L22');
    Database::update('bookings', ['counter_code' => null, 'status' => 'confirmed', 'sold_by_admin_id' => $agent, 'confirmed_at' => $at],
        'id = :i', ['i' => (int) $g1['id']]);
    Database::update('payments', ['method' => 'cash', 'status' => 'verified', 'verified_by' => $agent, 'verified_at' => $at],
        'booking_id = :b', ['b' => (int) $g1['id']]);
    Database::insert('agent_ledger', ['agent_admin_id' => $agent, 'booking_id' => (int) $g1['id'], 'account' => 'commission',
        'entry_type' => 'commission', 'amount' => 150, 'note' => 'i2 test commission', 'created_at' => $at]);
    check('seed: NPJ is an NPR desk on this database', CounterDesk::currency('NPJ') === 'NPR', CounterDesk::currency('NPJ'));

    /* ---- A: booking-view cash note */
    $bv = page('admin/booking-view.php', ['pnr' => (string) $n1['pnr']]);
    check('A booking-view renders (200, no uncaught, no PHP warning)', $bv['http'] === 200 && $bv['uncaught'] === '' && $bv['warn'] === '',
        $bv['http'] . ' ' . $bv['uncaught'] . ' ' . $bv['warn']);
    if ($hasNote) {
        check('A cash note panel: पैसा कहिले? + Part paid', str_contains($bv['body'], 'पैसा कहिले?') && str_contains($bv['body'], 'Part paid'));
        check('A cash note: amount ₹1,500, date, note', str_contains($bv['body'], '1,500') && str_contains($bv['body'], '2026-11-25')
            && str_contains($bv['body'], 'ZZTEST i2 bhai dinchha'));
        check('A says it is a note only', str_contains($bv['body'], 'a note only'));
    } else {
        check('A (bookings.cash_note column missing - migration not applied: panel skipped)', !str_contains($bv['body'], 'Cash note'));
    }
    $bv2 = page('admin/booking-view.php', ['pnr' => (string) $g1['pnr']]);
    check('A no cash note on a booking without one', $bv2['http'] === 200 && !str_contains($bv2['body'], '💵 पैसा कहिले? · Cash note'));

    /* ---- B: bookings register at the NPR desk */
    $bk = page('admin/bookings.php', ['counter' => 'NPJ', 'q' => (string) $n1['pnr']]);
    check('B bookings?counter=NPJ renders (200, no warning)', $bk['http'] === 200 && $bk['uncaught'] === '' && $bk['warn'] === '',
        $bk['http'] . ' ' . $bk['uncaught'] . ' ' . $bk['warn']);
    $nprTxt = CounterDesk::format((float) $nNpr, 'NPR');
    check('B the row prints the frozen NPR + ₹', str_contains($bk['body'], $nprTxt) && str_contains($bk['body'], (string) $n1['pnr']), $nprTxt);
    check('B the Revenue card is in NPR too', preg_match('~<div class="k">Revenue</div>\s*<div class="v">[^<]*' . preg_quote(htmlspecialchars($nprTxt, ENT_QUOTES), '~') . '~u', $bk['body']) === 1);
    /* fix pass: a half-cancelled frozen sale (total_amount halves, fx_total stays) - the
       Revenue card follows total_amount x fx_rate, like the row (ChalaniPng::rateFor) */
    $half = round($nTot / 2, 2);
    Database::update('bookings', ['total_amount' => $half], 'id = :i', ['i' => (int) $n1['id']]);
    $bkH = page('admin/bookings.php', ['counter' => 'NPJ', 'q' => (string) $n1['pnr']]);
    $halfTxt = CounterDesk::format($half * 1.6, 'NPR');
    check('B half-cancelled: the Revenue card = the row (total x fx_rate, not fx_total)', $bkH['http'] === 200
        && preg_match('~<div class="k">Revenue</div>\s*<div class="v">[^<]*' . preg_quote(htmlspecialchars($halfTxt, ENT_QUOTES), '~') . '~u', $bkH['body']) === 1
        && !preg_match('~<div class="k">Revenue</div>\s*<div class="v">[^<]*' . preg_quote(htmlspecialchars($nprTxt, ENT_QUOTES), '~') . '~u', $bkH['body']), $halfTxt);
    Database::update('bookings', ['total_amount' => $nTot], 'id = :i', ['i' => (int) $n1['id']]);
    $bk2 = page('admin/bookings.php', ['q' => (string) $g1['pnr']]);
    check('B no desk filter: plain ₹ (no रू)', $bk2['http'] === 200 && $bk2['warn'] === '' && !preg_match('~<div class="k">Revenue</div>\s*<div class="v">[^<]*रू~u', $bk2['body']));

    /* ---- C: counters page reads CounterBook */
    $ct = page('admin/counters.php', ['from' => I2_FD, 'to' => I2_FD]);
    check('C counters renders (200, no warning)', $ct['http'] === 200 && $ct['uncaught'] === '' && $ct['warn'] === '',
        $ct['http'] . ' ' . $ct['uncaught'] . ' ' . $ct['warn']);
    check('C Commission + Net columns', str_contains($ct['body'], 'Commission</th>') && str_contains($ct['body'], 'Net</th>'));
    check('C the agent with no desk has an own line', str_contains($ct['body'], 'ZZTEST i2 Agent') && str_contains($ct['body'], 'no desk'));
    check('C its tickets link filters by the agent', str_contains($ct['body'], 'bookings.php?agent=' . $agent));
    check('C the seller link says it lists every sale by the seller', str_contains($ct['body'], 'सबै बिक्री · All sales by seller'));
    check('C ₹150 commission shown', str_contains($ct['body'], '₹150'));
    require_once INCLUDE_PATH . '/counterbook.php';
    $cbk = CounterBook::range(I2_FD, I2_FD);
    check('C the page total net = CounterBook total net', str_contains($ct['body'], Security::e(inr((float) $cbk['totals']['net']))), inr((float) $cbk['totals']['net']));

    /* ---- D: the booking JSON (api/track.php) */
    $tr = api('/api/track.php', ['pnr' => (string) $n1['pnr'], 'phone' => I2_PHONES['N1']]);
    $td = $tr['json']['data'] ?? [];
    check('D track.php ok for the NPR sale', ($tr['json']['ok'] ?? false) === true && empty($td['partial']), 'HTTP ' . $tr['code'] . ' ' . substr($tr['body'], 0, 160));
    check('D fxCurrency NPR, fxTotal frozen, fxRate 1.6', ($td['fxCurrency'] ?? '') === 'NPR' && abs((float) ($td['fxTotal'] ?? 0) - $nNpr) < 0.01
        && abs((float) ($td['fxRate'] ?? 0) - 1.6) < 0.0001, json_encode([$td['fxCurrency'] ?? null, $td['fxTotal'] ?? null, $td['fxRate'] ?? null]));
    $tr2 = api('/api/track.php', ['pnr' => (string) $g1['pnr'], 'phone' => I2_PHONES['G1']]);
    $td2 = $tr2['json']['data'] ?? [];
    check('D an INR sale: fxCurrency empty, fxTotal 0', array_key_exists('fxCurrency', $td2) && ($td2['fxCurrency'] ?? 'x') === '' && (float) ($td2['fxTotal'] ?? 1) === 0.0);

    /* ---- E: api/search.php salesClosed on today's r7 */
    $r7 = Database::fetch("SELECT * FROM routes WHERE route_code = 'r7' AND is_active = 1");
    if ($r7 === null) {
        check('E (no active r7 on this database - skipped)', true);
    } else {
        $keep['sales_close_clock'] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => 'sales_close_clock']);
        $find = static function (array $res) use ($r7): ?array {
            foreach ((array) ($res['json']['data']['results'] ?? []) as $x) {
                if ((int) ($x['routeId'] ?? 0) === (int) $r7['id'] && (int) ($x['slot'] ?? 1) === 1) { return $x; }
            }
            return null;
        };
        Settings::set('sales_close_clock', '{"r7":{"all":"00:00","tz":"Asia/Kathmandu"}}', 'json', 'booking', false);
        $s1 = api('/api/search.php', ['from' => (string) $r7['from_city'], 'to' => (string) $r7['to_city'], 'date' => todayISO()]);
        $hit = $find($s1);
        /* 19 Nov 2026: after today's bus has left, the search answers with TOMORROW's date
           (data.date) - that bus is rightly open, so there is nothing to check tonight. */
        if ($hit !== null && (string) ($s1['json']['data']['date'] ?? todayISO()) !== todayISO()) {
            $hit = null;
        }
        if ($hit === null) {
            check('E (today\'s r7 not in the search - departed / no bus today: skipped)', true, substr($s1['body'], 0, 160));
        } else {
            check('E closed r7: salesClosed true + clockLabel', ($hit['salesClosed'] ?? null) === true && trim((string) ($hit['clockLabel'] ?? '')) !== '',
                json_encode([$hit['salesClosed'] ?? null, $hit['clockLabel'] ?? null]));
            check('E seatsLow / seatsLowLabel keys sent', array_key_exists('seatsLow', $hit) && array_key_exists('seatsLowLabel', $hit));
        }
        Settings::set('sales_close_clock', '{}', 'json', 'booking', false);
        $s2 = api('/api/search.php', ['from' => (string) $r7['from_city'], 'to' => (string) $r7['to_city'], 'date' => todayISO()]);
        $hit2 = $find($s2);
        check('E clock off ({}): salesClosed false', $hit2 === null || (($hit2['salesClosed'] ?? null) === false && ($hit2['clockLabel'] ?? 'x') === ''),
            json_encode([$hit2['salesClosed'] ?? null]));
        $kc = $keep['sales_close_clock'];
        if ($kc === null) { Database::delete('settings', 'skey = :k', ['k' => 'sales_close_clock']); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $kc['svalue'], 'k' => 'sales_close_clock']); }
        unset($keep['sales_close_clock']);
    }

    /* ---- F: the JS glue (static; node --check runs separately) */
    $sb = (string) file_get_contents($root . '/assets/js/27-smartbook.js');
    check('F 27-smartbook: altPhone + altCountry sent', str_contains($sb, 'body.altPhone') && str_contains($sb, 'body.altCountry'));
    check('F 27-smartbook: cashNote on a cash sale only', str_contains($sb, "pay === 'cash' ? cashNote()") && str_contains($sb, 'body.cashNote'));
    check('F 27-smartbook: clockNote warning', str_contains($sb, 'q.clockNote') && str_contains($sb, 'sbClock'));
    check('F 27-smartbook / 14-counter: staff = जना मात्र · passenger count', str_contains($sb, 'जना मात्र · passenger count')
        && str_contains((string) file_get_contents($root . '/assets/js/14-counter.js'), 'जना मात्र · passenger count'));
    check('F 27-smartbook: 2nd number checked per country (NP 96/97/98, IN 6-9)', str_contains($sb, 'a Nepal number starts with 98/97/96')
        && str_contains($sb, 'an India number starts with 6/7/8/9'));
    check('F 27-smartbook: resetDeskMore clears the picked alt country', (bool) preg_match('~function resetDeskMore\(\)[\s\S]{0,400}S\.altPicked = false; altOther\(\)~', $sb));
    /* run the real alt-number functions in node with a tiny DOM stub */
    $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
    $node = $node !== '' ? strtok($node, "\r\n") : '';
    $grab = static function (string $name) use ($sb): string {
        $p = strpos($sb, '  function ' . $name . '(');
        if ($p === false) { return ''; }
        $l = strpos($sb, "\n", $p);
        $line = rtrim(substr($sb, $p, $l === false ? null : $l - $p));
        if (str_ends_with($line, '}')) { return $line; }   // a one-line function
        $e = strpos($sb, "\n  }", $p);
        return $e === false ? '' : substr($sb, $p, $e - $p + 4);
    };
    $fns = $grab('digits') . "\n" . $grab('altDigits') . "\n" . $grab('altErr') . "\n" . $grab('altGuessCc') . "\n" . $grab('altOther');
    if ($node === '' || substr_count($fns, 'function ') < 5) {
        check('F (node not found or functions not extracted - alt-number run skipped)', true);
    } else {
        $js = "var els={sbAltCc:{value:'NP'},sbAltPhone:{value:''}};function \$(i){return els[i]||null;}var S={cc:'IN',phone:'9812345678',altPicked:false};\n"
            . $fns . "\nvar out={};"
            . "altOther();out.def=els.sbAltCc.value;"                                         // IN main -> NP default
            . "els.sbAltPhone.value='8123456789';altGuessCc();out.guessIn=els.sbAltCc.value;out.errIn=altErr();"   // 8... -> IN, valid
            . "S.altPicked=true;els.sbAltCc.value='NP';out.errNp8=altErr();"                 // clerk forces NP: 8... rejected
            . "els.sbAltPhone.value='5123456789';els.sbAltCc.value='IN';out.err5=altErr();"   // 5... rejected (server would drop it)
            . "S.altPicked=false;S.cc='NP';els.sbAltPhone.value='';altOther();out.reset=els.sbAltCc.value;"   // next customer, NP main -> IN
            . "els.sbAltPhone.value='+977 9812345670';els.sbAltCc.value='IN';altGuessCc();out.guess977=els.sbAltCc.value;out.err977=altErr();"
            . "els.sbAltPhone.value='9812345678';S.cc='IN';S.phone='9812345678';els.sbAltCc.value='IN';out.errSame=altErr();"
            . "console.log(JSON.stringify(out));";
        $tmp = tempnam(sys_get_temp_dir(), 'i2alt') . '.js';
        file_put_contents($tmp, $js);
        $o = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' 2>&1'), true) ?: [];
        @unlink($tmp);
        check('F alt: IN main -> 2nd number defaults to NP', ($o['def'] ?? '') === 'NP', json_encode($o));
        check('F alt: typed 8123456789 (not picked) -> IN, accepted', ($o['guessIn'] ?? '') === 'IN' && ($o['errIn'] ?? 'x') === '');
        check('F alt: 8123456789 forced to +977 -> Nepal error', str_contains((string) ($o['errNp8'] ?? ''), '98/97/96'));
        check('F alt: 5123456789 -> India error', str_contains((string) ($o['err5'] ?? ''), '6/7/8/9'));
        check('F alt: after reset, NP main -> default IN again', ($o['reset'] ?? '') === 'IN');
        check('F alt: +977 prefix picks NP and is accepted', ($o['guess977'] ?? '') === 'NP' && ($o['err977'] ?? 'x') === '');
        check('F alt: same as the first number -> error', str_contains((string) ($o['errSame'] ?? ''), 'not the first number'));
    }
    $rs = (string) file_get_contents($root . '/assets/js/06-results.js');
    check('F 06-results: closed card + seats-low label', str_contains($rs, 'srv.salesClosed') && str_contains($rs, 'srv.seatsLowLabel'));
    $co = (string) file_get_contents($root . '/assets/js/07-checkout.js');
    check('F 07-checkout: fx fields + raw UPI rule', str_contains($co, 'fxCurrency: d.fxCurrency') && str_contains($co, 'ticket_pay_qr_upi !== false') && str_contains($co, 'nprSale'));
} catch (Throwable $e) {
    $FAIL++;
    echo '  ERROR  ' . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}

/* ---- cleanup */
i2_cleanup();
foreach ($keep as $k => $row) {
    if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
    else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
}
if ($sched['made'] && $sched['id'] > 0 && (int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE schedule_id = :s', ['s' => $sched['id']], 0) === 0) {
    Database::delete('schedules', 'id = :i', ['i' => $sched['id']]);
}
Settings::flush();
$left = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone IN ('9000010301','9000010302')", [], 0)
      + (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE username LIKE 'i2\\_sd\\_%'", [], 0);
check('cleanup: no i2 rows left', $left === 0, (string) $left);

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
