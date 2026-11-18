<?php
/**
 * =====================================================================
 *  payauto-test.php — auto-confirm ONLY from a signed provider success (19 Nov 2026).
 *
 *  Drives the REAL ICICI bridge (payment/icici_booking.php: icici_apply_result) with
 *  signed callbacks built from a throw-away local test config (payment/icici_config.php is
 *  written here only when it does not exist, with a test key, and removed again). Each
 *  scenario runs in a child process because the config defines constants per environment.
 *
 *   A production, signed success, exact INR amount  → confirmed once, one booking.confirm
 *   B the same callback again (webhook after return)  → no second confirm, no second ticket
 *   C wrong amount                                    → stays pending, office alert
 *   D bad signature                                   → rejected, stays pending
 *   E wrong currency (USD 840)                        → proof recorded, stays pending
 *   F UAT (test money)                                → proof recorded, stays pending
 *   G the same bank txn sent for a second booking     → second booking stays pending
 *   H screenshot / UTR path (WaPayProof)              → never confirms (no PayAuto call)
 *
 *    php -c .claude/php-dev.ini tests/payauto-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT = dirname(__DIR__);
$CFG  = $ROOT . '/payment/icici_config.php';
const PA_PHONE = '9000017701';

/* ---------- child: one callback ---------- */
if (($argv[1] ?? '') === '--child') {
    putenv('SHG_ICICI_TEST_ENV=' . $argv[2]);
    $_SERVER['SCRIPT_FILENAME'] = __FILE__;
    require $ROOT . '/payment/icici_booking.php';
    if (DB_NAME !== 'shari_test') { exit(2); }
    $in = json_decode((string) base64_decode($argv[3]), true);
    $data = $in['data'];
    if (empty($in['badHash'])) {
        $data['secureHash'] = icici_generate_secure_hash($data);
    } else {
        $data['secureHash'] = str_repeat('a', 64);
    }
    $r = icici_apply_result($data, $in['source'] ?? 'WEBHOOK');
    echo json_encode(['state' => $r['state'], 'message' => $r['message']]);
    exit(0);
}

require_once $ROOT . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/booking.php';

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$wroteCfg = false;
if (!is_file($CFG)) {
    $wroteCfg = true;
    file_put_contents($CFG, <<<'PHP'
<?php
/* LOCAL TEST CONFIG written by tests/payauto-test.php — deleted again by the test. Never deployed. */
define('ICICI_ENVIRONMENT', getenv('SHG_ICICI_TEST_ENV') === 'production' ? 'production' : 'uat');
define('ICICI_MERCHANT_ID', 'TESTMERCHANT1');
define('ICICI_AGGREGATOR_ID', 'TESTAGG1');
define('ICICI_SECRET_KEY', 'local-test-key-not-a-secret');
define('ICICI_APP_ID', 'test');
define('ICICI_URL_INITIATE_SALE', 'http://127.0.0.1:1/');
define('ICICI_URL_COMMAND', 'http://127.0.0.1:1/');
define('SITE_BASE_URL', 'http://localhost');
define('PAYMENT_RETURN_URL', SITE_BASE_URL . '/payment/payment_return.php');
define('PAYMENT_WEBHOOK_URL', SITE_BASE_URL . '/payment/payment_webhook.php');
function icici_generate_secure_hash(array $d): string {
    unset($d['secureHash']); $k = array_keys($d); sort($k, SORT_STRING); $t = '';
    foreach ($k as $x) { $v = $d[$x]; $t .= is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v); }
    return hash_hmac('sha256', $t, ICICI_SECRET_KEY);
}
function icici_verify_secure_hash(array $r): bool {
    $h = $r['secureHash'] ?? ''; if ($h === '') return false; unset($r['secureHash']);
    return hash_equals(icici_generate_secure_hash($r), strtolower($h));
}
function icici_log(string $c, array $d): void { }
PHP);
}

$keep = [];
function setKeep(string $k, string $v, string $t = 'string'): void {
    global $keep;
    if (!array_key_exists($k, $keep)) { $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]); }
    Settings::set($k, $v, $t, 'payments', false);
    Settings::flush();
}
function cleanup(): void {
    global $keep, $wroteCfg, $CFG;
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => PA_PHONE]) as $r) {
        foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
        foreach (['booking_collections', 'agent_ledger', 'pay_events', 'payment_reviews', 'notifications'] as $t) {
            try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        }
        try { Database::run("DELETE FROM audit_logs WHERE entity_id = :p", ['p' => $r['pnr']]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    $keep = [];
    Settings::flush();
    if ($wroteCfg && is_file($CFG)) { @unlink($CFG); }
}
register_shutdown_function('cleanup');

echo "\n=== PayAuto: auto-confirm only from a signed provider success ===\n\n";
foreach (Database::fetchAll('SELECT id FROM bookings WHERE contact_phone = :p', ['p' => PA_PHONE]) as $r) { Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]); }
foreach (['whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'email_enabled' => '0'] as $k => $v) { setKeep($k, $v, 'bool'); }
setKeep('pay_provider_autoconfirm_on', '1', 'bool');

$TD = '';
for ($i = 100; $i >= 60; $i--) {
    $d = date('Y-m-d', strtotime(todayISO() . " +$i days"));
    if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE travel_date = :d', ['d' => $d], 0) === 0) { $TD = $d; break; }
}
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
Seats::schedule((int) $route['id'], $TD);
$seat = 20;
$mk = static function () use ($route, $TD, &$seat): array {
    $seat += 2;
    $res = BookingService::create(['routeId' => (int) $route['id'], 'travelDate' => $TD, 'seats' => ['L' . $seat],
        'passengers' => [['name' => 'ZZTEST payauto', 'age' => 30, 'gender' => 'Male']],
        'contact' => ['phone' => PA_PHONE], 'bookingMode' => 'sharing', 'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '']);
    return Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $res['pnr']]);
};
$ini = php_ini_loaded_file();
$cb = static function (string $env, array $data, array $extra = []) use ($ini): array {
    $arg = base64_encode((string) json_encode(['data' => $data] + $extra));
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' --child ' . $env . ' ' . $arg . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $j = json_decode(trim(substr($out, (int) strrpos($out, '{'))), true);
    return is_array($j) ? $j : ['state' => 'error', 'message' => mb_substr($out, 0, 300)];
};
$payload = static fn(array $b, string $bankTxn, ?string $amount = null, string $cur = '356'): array => [
    'merchantId' => 'TESTMERCHANT1', 'merchantTxnNo' => 'SHG' . $b['id'] . 'T' . date('YmdHis') . '123',
    'txnID' => $bankTxn, 'responseCode' => '000', 'amount' => $amount ?? number_format((float) $b['total_amount'], 2, '.', ''),
    'currencyCode' => $cur, 'respDescription' => 'Transaction successful'];
$status = static fn(array $b): string => (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $b['id']], '');
$count = static fn(string $action, array $b): int => (int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE action = :a AND entity_id = :p', ['a' => $action, 'p' => $b['pnr']], 0);

// A + B
$b = $mk();
$p = $payload($b, 'BANK' . random_int(100000000, 999999999));
$r = $cb('production', $p, ['source' => 'RETURN']);
check('A signed production success → confirmed', $r['state'] === 'success' && $status($b) === 'confirmed', json_encode($r) . ' ' . $status($b));
check('A audit payment.auto_confirmed = 1', $count('payment.auto_confirmed', $b) === 1);
check('A exactly one booking.confirm (one ticket)', $count('booking.confirm', $b) === 1);
$r2 = $cb('production', $p, ['source' => 'WEBHOOK']);
check('B duplicate callback → answered, nothing new', $r2['state'] === 'success');
check('B still one booking.confirm and one auto-confirm', $count('booking.confirm', $b) === 1 && $count('payment.auto_confirmed', $b) === 1);

// C wrong amount
$c = $mk();
$r = $cb('production', $payload($c, 'BANK' . random_int(100000000, 999999999), number_format((float) $c['total_amount'] - 50, 2, '.', '')));
check('C wrong amount → stays pending', $r['state'] === 'failed' && $status($c) === 'pending', json_encode($r));
check('C office alert raised', (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE booking_id = :b AND title LIKE 'ICICI amount mismatch%'", ['b' => (int) $c['id']], 0) === 1);

// D bad signature
$d = $mk();
$r = $cb('production', $payload($d, 'BANK' . random_int(100000000, 999999999)), ['badHash' => true]);
check('D bad signature → rejected, stays pending', $r['state'] === 'invalid' && $status($d) === 'pending', json_encode($r));

// E wrong currency
$e = $mk();
$r = $cb('production', $payload($e, 'BANK' . random_int(100000000, 999999999), null, '840'));
check('E USD currency → proof kept, stays pending', $status($e) === 'pending' && $count('payment.auto_confirmed', $e) === 0, json_encode($r));

// F UAT
$f = $mk();
$r = $cb('uat', $payload($f, 'BANK' . random_int(100000000, 999999999)));
check('F UAT (test money) → stays pending', $r['state'] === 'success' && $status($f) === 'pending' && $count('payment.auto_confirmed', $f) === 0, json_encode($r));

// G same bank txn for a second booking
$g = $mk();
$r = $cb('production', $payload($g, $p['txnID']));
check('G same bank txn on a second booking → stays pending', $status($g) === 'pending' && $count('payment.auto_confirmed', $g) === 0, json_encode($r));

// H switch off
setKeep('pay_provider_autoconfirm_on', '0', 'bool');
$h = $mk();
$r = $cb('production', $payload($h, 'BANK' . random_int(100000000, 999999999)));
check('H switch off → stays pending for a person', $status($h) === 'pending', json_encode($r));
setKeep('pay_provider_autoconfirm_on', '1', 'bool');

// I screenshot / UTR path never auto-confirms
$src = (string) file_get_contents(INCLUDE_PATH . '/wapayproof.php');
check('I WhatsApp screenshot / UTR code never calls PayAuto or approve', !str_contains($src, 'PayAuto') && !str_contains($src, 'approveReviewed') && !preg_match('/BookingService::confirm\(/', $src));
$i = $mk();
BookingService::submitPaymentProof((string) $i['pnr'], ['utr' => '6' . date('z') . '1234567', 'method' => 'upi', 'claimedAmount' => (float) $i['total_amount']]);
check('I a typed UTR leaves the booking pending', $status($i) === 'pending');

echo "\n" . ($fail === 0 ? "  $pass passed, 0 failed\n" : "  $pass passed, $fail failed\n");
exit($fail === 0 ? 0 : 1);
