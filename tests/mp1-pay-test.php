<?php
/**
 * =====================================================================
 *  mp1-pay-test.php — Payment Management release "pay" (25 Nov 2026).
 *
 *   A hardening: test_sandbox.php is CLI-only; ICICI signed success with no amount /
 *     merchant id / currency is NOT auto-confirmed (icici_strict_fields_on), a full one still is
 *   B one Nepal QR: ManualQrAdapter shows the uploaded NPJ QR + nepal_esewa_id for an NPR sale
 *   C/D the credits ledger + PayCredits rules
 *   E BankMail: parser fixtures (credit with 5- and 4-digit PNR, debit ignored, html/base64,
 *     Devanagari digits, forged lower Authentication-Results, the credited account read and
 *     masked - never from the remarks), the IMAP wire client on a scripted stream, poll():
 *     happy path confirms + chalani tick, replay no-op, the same txn on ANOTHER e-mail kept
 *     for a person (never dropped), missing DKIM / wrong sender / amount mismatch / money into
 *     another account / no account digits -> unmatched + bell, debit skipped, an odd message
 *     (unknown charset, absurd amount, 8-bit subject, one that throws) never stops the reader
 *   G bank webhook: bad signature 401, sandbox -> 'test' never confirms, live confirms,
 *     replay no-op, same UTR with another amount -> unmatched, stale timestamp 401, wrong merchant 403
 *   H admin/payment-center.php renders its three tabs for a super-admin, refuses others
 *
 *    php -c .claude/php-dev.ini tests/mp1-pay-test.php
 *  Creates its own bookings (phone 9000017725) and removes them, the credits, the bells,
 *  the settings it touched and the temp files again.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT = dirname(__DIR__);
$CFG  = $ROOT . '/payment/icici_config.php';
const MP_PHONE = '9000017725';
const MP_IP    = '198.51.100.77';

/* ---------- child 1: one ICICI callback in its own process (constants per environment) ---------- */
if (($argv[1] ?? '') === '--icici') {
    putenv('SHG_ICICI_TEST_ENV=' . $argv[2]);
    $_SERVER['SCRIPT_FILENAME'] = __FILE__;
    require $ROOT . '/payment/icici_booking.php';
    if (DB_NAME !== 'shari_test') { exit(2); }
    $data = json_decode((string) base64_decode($argv[3]), true);
    $data['secureHash'] = icici_generate_secure_hash($data);
    $r = icici_apply_result($data, 'WEBHOOK');
    echo json_encode(['state' => $r['state'], 'message' => $r['message']]);
    exit(0);
}
/* ---------- child 2: one admin page as a signed-in admin ---------- */
if (($argv[1] ?? '') === '--page') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.67';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/admin/payment-center.php';
    $_SERVER['REQUEST_URI'] = '/admin/payment-center.php';
    $_GET = (array) ($cfg['get'] ?? []);
    define('SHG_APP', true);
    require_once $ROOT . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time()];
    if (!empty($cfg['nav'])) {
        require_once $ROOT . '/admin/_guard.php';
        echo json_encode(array_column(admin_nav(), 'href'));
        exit(0);
    }
    register_shutdown_function(static function (): void { echo "\n<!--HTTP:" . http_response_code() . "-->\n"; });
    require $ROOT . '/admin/payment-center.php';
    exit(0);
}

define('SHG_APP', true);
require_once $ROOT . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/bankmail.php';
require_once INCLUDE_PATH . '/bankapi.php';
require_once INCLUDE_PATH . '/payadapter.php';

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$keep = [];
function setKeep(string $k, string $v, string $t = 'string'): void {
    global $keep;
    if (!array_key_exists($k, $keep)) { $keep[$k] = Database::fetch('SELECT svalue, stype FROM settings WHERE skey = :k', ['k' => $k]); }
    Settings::set($k, $v, $t, 'payments', false);
    Settings::flush();
}
$credIds = [];
$tmpFiles = [];
$wroteCfg = false;
$madeAdmins = [];
$bellFloor = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM notifications', [], 0);
function cleanup(): void {
    global $keep, $credIds, $tmpFiles, $wroteCfg, $CFG, $madeAdmins, $bellFloor;
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => MP_PHONE]) as $r) {
        foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
        foreach (['booking_collections', 'agent_ledger', 'pay_events', 'payment_reviews', 'notifications'] as $t) {
            try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        }
        try { Database::run('DELETE FROM bank_credits WHERE booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM audit_logs WHERE entity_id = :p', ['p' => $r['pnr']]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    foreach ($credIds as $id) {
        try { Database::run('DELETE FROM bank_credits WHERE id = :i', ['i' => $id]); } catch (Throwable $e) {}
        try { Database::run("DELETE FROM audit_logs WHERE entity_type = 'bank_credit' AND entity_id = :i", ['i' => (string) $id]); } catch (Throwable $e) {}
    }
    try { Database::run("DELETE FROM bank_credits WHERE txn_id LIKE 'MP1T%' OR note LIKE 'Txn MP1T%'"); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM notifications WHERE id > :f AND (title LIKE 'Bank credit needs a person%' OR title LIKE 'Auto-confirmed%' OR title LIKE 'Bank mail could not be read%')", ['f' => $bellFloor]); } catch (Throwable $e) {}
    try { Database::run('DELETE FROM rate_limits WHERE identifier = :i', ['i' => MP_IP]); } catch (Throwable $e) {}
    foreach ($madeAdmins as $aid) {
        try { Database::delete('kv_store', "kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $aid]); } catch (Throwable $e) {}
        try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        try { Database::delete('admins', 'id = :a', ['a' => $aid]); } catch (Throwable $e) {}
    }
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v, stype = :t WHERE skey = :k', ['v' => $row['svalue'], 't' => $row['stype'], 'k' => $k]); }
    }
    $keep = [];
    Settings::flush();
    foreach ($tmpFiles as $f) { @unlink($f); }
    if ($wroteCfg && is_file($CFG)) { @unlink($CFG); }
}
register_shutdown_function('cleanup');

echo "\n=== mp1 pay: Payment Management ===\n\n";
foreach (['whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'email_enabled' => '0'] as $k => $v) { setKeep($k, $v, 'bool'); }
setKeep('pay_provider_autoconfirm_on', '1', 'bool');
setKeep('icici_strict_fields_on', '1', 'bool');
setKeep('npr_per_inr', '1.6', 'float');

/* ---------------- A. hardening (static) ---------------- */
echo "-- A hardening\n";
$sb = (string) file_get_contents($ROOT . '/payment/test_sandbox.php');
check('A test_sandbox.php: CLI-only guard (404 on the web) before the config is read',
    ($g = strpos($sb, "if (PHP_SAPI !== 'cli')")) !== false && $g < (int) strpos($sb, "require_once __DIR__ . '/icici_config.php'") && str_contains($sb, 'http_response_code(404)'));
$ib = (string) file_get_contents($ROOT . '/payment/icici_booking.php');
check('A icici_booking.php: strict-fields switch read inline, default on', str_contains($ib, "Settings::getBool('icici_strict_fields_on', true)"));
$pa = (string) file_get_contents(INCLUDE_PATH . '/payauto.php');
check("A PayAuto ICICI path untouched (still 'icici' only)", str_contains($pa, "if (\$provider !== 'icici' || \$txnId === '' || \$proofRef === '')"));
$gd = (string) file_get_contents($ROOT . '/admin/_guard.php');
check('A one menu line "💳 Payment Management" beside Verify Payments, super-admin only',
    substr_count($gd, "'href' => 'payment-center.php'") === 1 && str_contains($gd, "...(Auth::isSuperadmin() ? [['href' => 'payment-center.php', 'icon' => 'card', 'label' => '💳 Payment Management'"));

/* ---------------- E. parser fixtures (pure) ---------------- */
echo "\n-- E BankMail::parse\n";
$opts = ['dkim_domain' => 'gibl.test', 'authserv' => 'mx.google.com', 'amount_regex' => '', 'txn_regex' => ''];
$mail = static function (array $o): string {
    $auth = $o['auth'] ?? 'mx.google.com; dkim=pass header.i=@gibl.test header.s=s1 header.b=AbC; spf=pass smtp.mailfrom=alerts@gibl.test';
    $plain = $o['plain'] ?? '';
    $html  = $o['html'] ?? '';
    $h = "Delivered-To: shg.alerts@gmail.com\r\n";
    if ($auth !== '') { $h .= "Authentication-Results: $auth\r\n"; }
    $h .= ($o['extra'] ?? '')
        . 'From: ' . ($o['from'] ?? 'Global IME Bank <alerts@gibl.test>') . "\r\n"
        . 'Message-ID: <' . ($o['mid'] ?? bin2hex(random_bytes(6))) . "@gibl.test>\r\n"
        . "Date: Tue, 25 Nov 2026 10:15:00 +0545\r\n"
        . 'Subject: ' . ($o['subject'] ?? '=?UTF-8?B?' . base64_encode('Credit Alert · खाता') . '?=') . "\r\n"
        . "MIME-Version: 1.0\r\n";
    if ($html === '') {
        return $h . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode($plain) . "\r\n";
    }
    return $h . "Content-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n--b1\r\n"
        . ($plain !== '' ? "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode($plain) . "\r\n--b1\r\n" : '')
        . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--b1--\r\n";
};
$credit = static fn(string $pnr, string $amt, string $txn): string =>
    "Dear Customer,\nYour A/C 0123456789012 has been credited by NPR $amt on 25-11-2026.\nRemarks: $pnr Ram Bahadur\nTxn ID: $txn\nAvailable Balance: NPR 55,000.00\nThank you, Global IME Bank";

$p = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345678'), 'mid' => 'fx1']), $opts);
check('E credit alert, 5-digit PNR: credit, NPR 2400, txn, PNR, DKIM ok, From, Message-ID',
    $p['is_credit'] && !$p['is_debit'] && abs($p['amount'] - 2400.0) < 0.001 && $p['txn_id'] === '7712345678' && $p['pnr'] === 'SHG-2026-00842'
    && $p['dkim_ok'] === true && $p['from'] === 'alerts@gibl.test' && $p['message_id'] === 'fx1@gibl.test' && $p['date'] !== '', json_encode($p, JSON_UNESCAPED_UNICODE));
check('E the balance figure is never read as the credit', abs($p['amount'] - 55000.0) > 1);
$p4 = BankMail::parse($mail(['plain' => $credit('shg 2026 0842', '1,250', 'FT26330AB12')]), $opts);
check('E 4-digit PNR written with spaces -> SHG-2026-0842, candidates include the 5-digit number',
    $p4['pnr'] === 'SHG-2026-0842' && in_array('SHG-2026-00842', PayCredits::pnrCandidates($p4['pnr']), true) && abs($p4['amount'] - 1250.0) < 0.001 && $p4['txn_id'] === 'FT26330AB12', json_encode($p4));
$pd = BankMail::parse($mail(['plain' => "Your A/C 0123456789012 has been debited by NPR 2,400.00. Remarks: SHG-2026-00842 Txn ID: 7712345699"]), $opts);
check('E debit alert -> is_debit, not a credit', $pd['is_debit'] && !$pd['is_credit'] && $pd['why'] === 'debit alert');
$ph = BankMail::parse($mail(['html' => '<html><head><style>p{}</style></head><body><table><tr><td>खातामा</td><td>रु २,४००.००</td></tr><tr><td>जम्मा भयो · credited</td></tr><tr><td>Narration: SHG-2026-00843</td></tr><tr><td>Reference No: 99001122334</td></tr></table></body></html>']), $opts);
check('E HTML-only (base64) + Devanagari digits: रु २,४०० -> 2400, narration PNR, reference id',
    $ph['is_credit'] && abs($ph['amount'] - 2400.0) < 0.001 && $ph['pnr'] === 'SHG-2026-00843' && $ph['txn_id'] === '99001122334', json_encode($ph, JSON_UNESCAPED_UNICODE));
$pf = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345601'),
    'auth' => 'mx.google.com; dkim=fail header.i=@gibl.test; spf=softfail',
    'extra' => "Authentication-Results: mx.google.com; dkim=pass header.d=gibl.test\r\n"]), $opts);
check('E a forged lower Authentication-Results never counts (topmost says dkim=fail)', $pf['dkim_ok'] === false);
$pn = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345602'), 'auth' => '']), $opts);
check('E no Authentication-Results -> no DKIM', $pn['dkim_ok'] === false);
$po = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345603'), 'auth' => 'evil.example; dkim=pass header.d=gibl.test']), $opts);
check('E Authentication-Results from another server (authserv-id) -> no DKIM', $po['dkim_ok'] === false);
$pw = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345604'), 'auth' => 'mx.google.com; dkim=pass header.d=gibl-test.evil.example']), $opts);
check('E DKIM from a look-alike domain -> no DKIM', $pw['dkim_ok'] === false);
$pr = BankMail::parse($mail(['plain' => "Credited NPR 10.00 Remarks: x Txn ID: 1234567"]), $opts + ['amount_regex' => '/Credited NPR ([0-9.,]+)/', 'txn_regex' => '/Txn ID: (\d+)/']);
check('E owner regex overrides (group 1) read amount and txn', abs($pr['amount'] - 10.0) < 0.001 && $pr['txn_id'] === '1234567');
check('E legacy PNR SHG-R-MXYZ12-AB3D is found, two PNRs both listed',
    PayCredits::pnrsIn('paid for SHG-R-MXYZ12-AB3D') === ['SHG-R-MXYZ12-AB3D'] && count(PayCredits::pnrsIn('SHG-2026-00842 and SHG-2026-00843')) === 2);
/* the credited account: a genuine signed alert for money paid into SOMEONE ELSE's account must not pay our ticket */
check('E credited account read and masked to its last 6: A/C 0123456789012 -> XXXXXXX789012', $p['account'] === 'XXXXXXX789012', $p['account']);
$pa = BankMail::parse($mail(['plain' => "NPR 500.00 has been credited to your A/C ****9012 on 25-11-2026. Remarks: SHG-2026-00842 Txn ID: 88112233"]), $opts);
check('E masked account ****9012 -> XXXX9012', $pa['account'] === 'XXXX9012', $pa['account']);
$pa = BankMail::parse($mail(['plain' => "Your A/C 0000005555 has been credited by NPR 500.00.\nRemarks: SHG-2026-00842 A/C XXXX9012\nTxn ID: 88112234"]), $opts);
check('E an account the payer typed in the remarks never counts (the real one is read)', $pa['account'] === 'XXXX005555', $pa['account']);
$pa = BankMail::parse($mail(['plain' => "NPR 500.00 has been credited. Remarks: SHG-2026-00842 A/C XXXX9012 Txn ID: 88112235"]), $opts);
check('E only the remarks name an account -> no account read', $pa['account'] === '', $pa['account']);
$pa = BankMail::parse($mail(['plain' => "NPR 500.00 from A/C XXXX5555 has been credited to A/C XXXX9012. Txn ID: 88112236"]), $opts);
check('E two different accounts in one alert -> "?"', $pa['account'] === '?', $pa['account']);
$pa = BankMail::parse($mail(['plain' => "Your account ending with 9012 has been credited by NPR 500.00. Txn ID: 88112237"]), $opts);
check('E "account ending with 9012" -> XXXX9012', $pa['account'] === 'XXXX9012', $pa['account']);
check('E accountMatches: 4-6 visible digits against the hint, "?" / none / 3 digits never match',
    PayCredits::accountMatches('XXXXXXX789012', '9012') && PayCredits::accountMatches('XXXX9012', '789012') && PayCredits::accountMatches('XXXXXXX789012', '789012')
    && !PayCredits::accountMatches('XXXX5555', '9012') && !PayCredits::accountMatches('XXXXXX189012', '289012') && !PayCredits::accountMatches('?', '9012')
    && !PayCredits::accountMatches('', '9012') && !PayCredits::accountMatches('XXXXX012', '9012') && !PayCredits::accountMatches('XXXX9012', ''));
/* odd messages are made harmless before they reach the database */
$pz = null; $thrown = '';
try {
    $pz = BankMail::parse("Authentication-Results: mx.google.com; dkim=pass header.i=@gibl.test\r\nFrom: Global IME Bank <alerts@gibl.test>\r\nMessage-ID: <pz1@gibl.test>\r\n"
        . "Subject: Credit\r\nContent-Type: text/plain; charset=x-unknown-cs\r\n\r\nYour A/C 0123456789012 has been credited by NPR 100.00\nTxn ID: 99887766\n", $opts);
} catch (Throwable $ex) { $thrown = get_class($ex) . ': ' . $ex->getMessage(); }
check('E an unknown charset (x-unknown-cs) never throws; the text is still read', $thrown === '' && $pz !== null && $pz['is_credit'] && abs($pz['amount'] - 100.0) < 0.001, $thrown);
check('E an absurd amount (NPR 99999999999999) reads as 0 - never as the next figure', BankMail::amountIn('credited by NPR 99999999999999. Fee NPR 10') === 0.0
    && BankMail::amountIn('credited by NPR 2,400.00') === 2400.0 && PayCredits::cleanAmount(1.0E+17) === 0.0 && PayCredits::cleanAmount('12.345') === 12.35);
$p8 = BankMail::parse($mail(['plain' => "Your A/C 0123456789012 has been credited by NPR 100.00\nTxn ID: 99887767", 'subject' => "Credit \xE9\xE8 alert"]), $opts);
check('E a raw 8-bit subject comes out as valid UTF-8', mb_check_encoding($p8['subject'], 'UTF-8') && mb_check_encoding($p8['remarks'], 'UTF-8') && str_contains($p8['remarks'], 'Credit'));

/* ---------------- E. the IMAP wire client on a scripted stream ---------------- */
echo "\n-- E IMAP client (scripted server, no network)\n";
$lit = "Subject: x\r\n\r\nhello {not a literal}\r\n";
$srv = "* 3 EXISTS\r\n* OK [UIDVALIDITY 777] UIDs valid\r\nS0001 OK [READ-ONLY] EXAMINE completed\r\n"
     . "* SEARCH 5 9 12\r\nS0002 OK SEARCH completed\r\n"
     . "* 2 FETCH (UID 9 BODY[] {" . strlen($lit) . "}\r\n" . $lit . ")\r\nS0003 OK FETCH completed\r\n";
$in = fopen('php://temp', 'w+'); fwrite($in, $srv); rewind($in);
$outS = fopen('php://memory', 'w+');
$im = new BankMailImap($in, $outS);
$bx = $im->examine('INBOX');
$se = $im->uidSearch('UID 6:* FROM "alerts@gibl.test"');
$fe = $im->uidFetch(9);
rewind($outS); $sent = (string) stream_get_contents($outS);
check('E EXAMINE (read-only) reads EXISTS + UIDVALIDITY', $bx === ['exists' => 3, 'uidvalidity' => '777'] && str_contains($sent, 'S0001 EXAMINE "INBOX"'));
check('E UID SEARCH reads the UIDs; FETCH uses BODY.PEEK[] and returns the literal byte-exact',
    $se === [5, 9, 12] && $fe === $lit && str_contains($sent, 'S0003 UID FETCH 9 (UID BODY.PEEK[])') && !preg_match('/\bSTORE\b|\\\\Seen|EXPUNGE/i', $sent));
check('E FROM criteria for several senders is one OR chain', BankMail::fromCriteria(['a@x.np', '@y.np', 'c@z.np']) === 'OR FROM "a@x.np" OR FROM "y.np" FROM "c@z.np"');

/* ---------------- DB part ---------------- */
if (!PayCredits::ready()) {
    check('ledger table bank_credits exists (apply database/upgrade-2026-11-25-mp1-pay-credits.sql)', false);
    echo "\n  $pass passed, $fail failed\n";
    exit(1);
}
foreach (Database::fetchAll('SELECT id FROM bookings WHERE contact_phone = :p', ['p' => MP_PHONE]) as $r) { Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]); }
$TD = '';
for ($i = 130; $i >= 90; $i--) {
    $d = date('Y-m-d', strtotime(todayISO() . " +$i days"));
    if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE travel_date = :d', ['d' => $d], 0) === 0) { $TD = $d; break; }
}
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
Seats::schedule((int) $route['id'], $TD);
$seatList = array_merge(array_map(static fn(int $n): string => 'L' . $n, range(12, 36, 2)), array_map(static fn(int $n): string => 'U' . $n, range(2, 36, 2)));
$mk = static function () use ($route, $TD, &$seatList): array {
    $res = BookingService::create(['routeId' => (int) $route['id'], 'travelDate' => $TD, 'seats' => [(string) array_shift($seatList)],
        'passengers' => [['name' => 'ZZTEST mp1pay', 'age' => 30, 'gender' => 'Male']],
        'contact' => ['phone' => MP_PHONE], 'bookingMode' => 'sharing', 'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '']);
    return Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $res['pnr']]);
};
$status = static fn(array $b): string => (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $b['id']], '');
$count  = static fn(string $action, array $b): int => (int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE action = :a AND entity_id = :p', ['a' => $action, 'p' => $b['pnr']], 0);
$tick   = static fn(array $b): ?array => Database::fetch('SELECT status, mode, note FROM booking_collections WHERE booking_id = :b', ['b' => (int) $b['id']]);
$npr    = static fn(array $b): string => number_format(round((float) $b['total_amount'] * 1.6), 2);
$tag    = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
$T      = static fn(string $n): string => 'MP1T' . $tag . $n;   // a unique txn id per run

/* ---------------- A. ICICI strict fields (real bridge, child processes) ---------------- */
echo "\n-- A ICICI strict fields\n";
if (!is_file($CFG)) {
    $wroteCfg = true;
    file_put_contents($CFG, <<<'PHP'
<?php
/* LOCAL TEST CONFIG written by tests/mp1-pay-test.php — deleted again by the test. Never deployed. */
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
$ini = php_ini_loaded_file();
$icici = static function (array $data) use ($ini): array {
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' --icici production ' . base64_encode((string) json_encode($data)) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $j = json_decode(trim(substr($out, (int) strrpos($out, '{'))), true);
    return is_array($j) ? $j : ['state' => 'error', 'message' => mb_substr($out, 0, 300)];
};
$ip = static fn(array $b, string $bank): array => ['merchantId' => 'TESTMERCHANT1', 'merchantTxnNo' => 'SHG' . $b['id'] . 'T' . date('YmdHis') . '123',
    'txnID' => $bank, 'responseCode' => '000', 'amount' => number_format((float) $b['total_amount'], 2, '.', ''), 'currencyCode' => '356', 'respDescription' => 'ok'];
foreach (['amount', 'merchantId', 'currencyCode'] as $drop) {
    $b = $mk();
    $d = $ip($b, 'BANK' . random_int(100000000, 999999999));
    unset($d[$drop]);
    $r = $icici($d);
    check("A signed production success WITHOUT $drop -> proof kept, stays pending for a person",
        $r['state'] === 'success' && $status($b) === 'pending' && $count('payment.auto_confirmed', $b) === 0
        && Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND utr_number LIKE 'ICICI-%'", ['b' => (int) $b['id']]), json_encode($r) . ' ' . $status($b));
}
$b = $mk();
$r = $icici($ip($b, 'BANK' . random_int(100000000, 999999999)));
check('A full signed production success still auto-confirms (ICICI happy path unchanged)', $r['state'] === 'success' && $status($b) === 'confirmed' && $count('payment.auto_confirmed', $b) === 1, json_encode($r));

/* ---------------- B. one Nepal QR ---------------- */
echo "\n-- B one Nepal QR\n";
$qrDir = UPLOAD_PATH . '/payqr';
if (!is_dir($qrDir)) { @mkdir($qrDir, 0755, true); }
$qrName = 'esewa-npj-mp1test' . strtolower($tag) . '.png';
$im2 = imagecreatetruecolor(8, 8); imagepng($im2, $qrDir . '/' . $qrName); imagedestroy($im2);
$tmpFiles[] = $qrDir . '/' . $qrName;
setKeep('nepal_esewa_qr', 'uploads/payqr/' . $qrName, 'string');
setKeep('nepal_esewa_id', '9800000001', 'string');
setKeep('nepal_esewa_name', 'NPJ Branch Test', 'string');
setKeep('esewa_id', '9811111111', 'string');
$fake = ['pnr' => 'SHG-2026-00001', 'total_amount' => 1000.0, 'fx_currency' => 'NPR', 'fx_total' => 1650.0, 'counter_code' => '', 'entity' => ''];
$st = (new ManualQrAdapter())->start($fake, 500.0);
check('B an NPR sale: the Nepal eSewa id + name, NPR from the frozen total (825), the uploaded NPJ QR inline',
    ($st['esewa']['id'] ?? '') === '9800000001' && ($st['esewa']['name'] ?? '') === 'NPJ Branch Test' && (float) ($st['esewa']['npr'] ?? 0) === 825.0
    && str_starts_with((string) ($st['esewa']['qr'] ?? ''), 'data:image/png;base64,'), json_encode(['id' => $st['esewa']['id'] ?? null, 'npr' => $st['esewa']['npr'] ?? null, 'qr' => substr((string) ($st['esewa']['qr'] ?? ''), 0, 30)]));
$st2 = (new ManualQrAdapter())->start(['pnr' => 'SHG-2026-00002', 'total_amount' => 1000.0, 'counter_code' => '', 'entity' => 'IN'], 1000.0);
check('B an India sale keeps the India eSewa id (same QR picture as the ticket)', ($st2['esewa']['id'] ?? '') === '9811111111' && str_starts_with((string) ($st2['esewa']['qr'] ?? ''), 'data:image/png'));
check('B pay.php reads the same QR (ManualQrAdapter::esewaQrSrc)', str_contains((string) file_get_contents($ROOT . '/pay.php'), 'ManualQrAdapter::esewaQrSrc()'));

/* ---------------- E. Gmail poll end to end ---------------- */
echo "\n-- E Gmail credits -> PayCredits\n";
setKeep('bankmail_on', '1', 'bool');
setKeep('pay_bankmail_autoconfirm_on', '1', 'bool');
setKeep('bankmail_from', 'alerts@gibl.test, @alerts.gibl.test', 'string');
setKeep('bankmail_dkim_domain', 'gibl.test', 'string');
setKeep('bankmail_authserv', 'mx.google.com', 'string');
setKeep('bankmail_amount_regex', '', 'string');
setKeep('bankmail_txn_regex', '', 'string');
setKeep('bankmail_account_regex', '', 'string');
setKeep('bankmail_account_hint', '789012', 'string');
setKeep('bankmail_last_uid', '5000', 'int');
setKeep('bankmail_uidvalidity', '4242', 'string');
setKeep('bankmail_last_poll', '{}', 'json');

$happy = $mk();
$debitRaw = $mail(['plain' => "Your A/C 0123456789012 has been debited by NPR 999.00. Txn ID: " . $T('D1')]);
$happyRaw = $mail(['plain' => $credit((string) $happy['pnr'], $npr($happy), $T('H1')), 'mid' => 'mp1happy' . $tag]);
$script = static function (array $msgs): string {   // [uid => raw]
    $s = "* 9 EXISTS\r\n* OK [UIDVALIDITY 4242] ok\r\nS0001 OK [READ-ONLY] done\r\n* SEARCH 4999 " . implode(' ', array_keys($msgs)) . "\r\nS0002 OK done\r\n";
    $n = 3;
    foreach ($msgs as $uid => $raw) {
        $s .= "* 1 FETCH (UID $uid BODY[] {" . strlen($raw) . "}\r\n" . $raw . ")\r\nS" . str_pad((string) $n++, 4, '0', STR_PAD_LEFT) . " OK done\r\n";
    }
    return $s;
};
$imapFor = static function (array $msgs) use ($script): BankMailImap {
    $in = fopen('php://temp', 'w+'); fwrite($in, $script($msgs)); rewind($in);
    return new BankMailImap($in, fopen('php://memory', 'w+'));
};
$res = BankMail::poll(50, $imapFor([5001 => $debitRaw, 5002 => $happyRaw]));
$hc = Database::fetch('SELECT * FROM bank_credits WHERE source = \'gmail\' AND txn_id = :t', ['t' => $T('H1')]);
if ($hc !== null) { $credIds[] = (int) $hc['id']; }
check('E poll: 2 read (UID 4999 <= last is skipped), 1 debit ignored, 1 credit confirmed, last UID 5002',
    $res['ok'] && $res['seen'] === 2 && $res['debits'] === 1 && $res['credits'] === 1 && $res['confirmed'] === 1 && Settings::getInt('bankmail_last_uid', 0) === 5002, json_encode($res));
check('E debit alert stored nothing', !Database::exists('SELECT 1 FROM bank_credits WHERE txn_id = :t', ['t' => $T('D1')]));
check('E happy path: booking confirmed through the gate (audit auto_confirmed, one booking.confirm)',
    $status($happy) === 'confirmed' && $count('payment.auto_confirmed', $happy) === 1 && $count('booking.confirm', $happy) === 1, $status($happy) . ' ' . json_encode($hc));
check('E credit row matched + linked to the booking', $hc !== null && $hc['state'] === 'matched' && (int) $hc['booking_id'] === (int) $happy['id'] && (int) $hc['dkim_ok'] === 1);
$tk = $tick($happy);
check('E chalani tick "PAID - Nepali online (Gmail Auto)", UTR in the note', $tk !== null && $tk['status'] === 'paid' && $tk['mode'] === 'PAID - Nepali online (Gmail Auto)' && str_contains((string) $tk['note'], $T('H1')), json_encode($tk));
$pay = Database::fetch('SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $happy['id']]);
check('E payment verified, GIME- reference, NPR amount kept (local_amount)', ($pay['status'] ?? '') === 'verified' && ($pay['utr_number'] ?? '') === 'GIME-' . $T('H1')
    && (!array_key_exists('local_amount', $pay) || abs((float) $pay['local_amount'] - (float) str_replace(',', '', $npr($happy))) < 0.01), json_encode($pay));
check('E an admin bell for the auto-confirmed credit', (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE booking_id = :b AND title LIKE 'Auto-confirmed (Gmail%'", ['b' => (int) $happy['id']], 0) === 1);

$res2 = BankMail::poll(50, $imapFor([5003 => $happyRaw]));
check('E replayed message (same Message-ID) -> duplicate, nothing new', $res2['duplicates'] === 1 && $res2['confirmed'] === 0 && $count('booking.confirm', $happy) === 1, json_encode($res2));
$dupId = PayCredits::record(['source' => 'gmail', 'currency' => 'NPR', 'amount' => 1, 'txn_id' => $T('H1'), 'message_id' => 'other' . $tag, 'dkim_ok' => true,
    'sender' => 'alerts@gibl.test', 'ref_text' => 'SHG-2026-00999 return trip', 'credit_account' => 'XXXXXXX789012']);
$dupOut = PayCredits::lastOutcome();
$credIds[] = $dupId;
$dupRow = PayCredits::get($dupId) ?? [];
check('E the same txn id on ANOTHER e-mail is kept, not dropped: own row, no txn id, unmatched, note names the first credit, bell; nothing confirms',
    $hc !== null && $dupId !== (int) $hc['id'] && $dupOut === 'conflict' && array_key_exists('txn_id', $dupRow) && $dupRow['txn_id'] === null &&($dupRow['state'] ?? '') === 'unmatched'
    && str_contains((string) ($dupRow['note'] ?? ''), 'already seen on credit #' . (int) $hc['id'])
    && (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE title LIKE 'Bank credit needs a person%' AND body LIKE :b", ['b' => '%(credit #' . $dupId . ',%'], 0) === 1
    && PayCredits::tryAutoConfirm($dupId)['ok'] === false && $count('booking.confirm', $happy) === 1, json_encode($dupRow));
$threw = '';
try { PayCredits::link($dupId, 'SHG-2026-00999', (int) (PayAuto::systemAdmin()['id'] ?? 0)); } catch (Throwable $ex) { $threw = $ex->getMessage(); }
check('E such a credit cannot be linked from here (no own txn id): the person records it on the booking page', str_contains($threw, 'no own transaction id'), $threw);
$otherRaw = $mail(['plain' => $credit('SHG-2026-00998', '500.00', $T('H1')), 'mid' => 'mp1other' . $tag]);
$res3 = BankMail::poll(50, $imapFor([5004 => $otherRaw]));
$or = Database::fetch('SELECT * FROM bank_credits WHERE message_id = :m', ['m' => 'mp1other' . $tag . '@gibl.test']);
if ($or !== null) { $credIds[] = (int) $or['id']; }
check('E poll: another e-mail with a txn id already seen -> counted as needing a person, not as a duplicate', $res3['unmatched'] === 1 && $res3['duplicates'] === 0
    && $or !== null && $or['state'] === 'unmatched' && $or['txn_id'] === null, json_encode($res3));

/* each failing rule -> unmatched + bell, booking untouched */
$fromParse = static function (string $raw) use (&$credIds): int {
    $p = BankMail::parse($raw);
    $id = PayCredits::record(['source' => 'gmail', 'currency' => 'NPR', 'amount' => $p['amount'], 'txn_id' => $p['txn_id'], 'ref_text' => $p['remarks'],
        'pnr' => $p['pnr'], 'sender' => $p['from'], 'dkim_ok' => $p['dkim_ok'], 'message_id' => $p['message_id'], 'received_at' => $p['date'], 'raw_excerpt' => $p['text'],
        'credit_account' => $p['account']]);
    $credIds[] = $id;
    return $id;
};
$bell = static fn(int $credId): int => (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE title LIKE 'Bank credit needs a person%' AND body LIKE :t",
    ['t' => '%' . substr((string) Database::scalar('SELECT txn_id FROM bank_credits WHERE id = :i', ['i' => $credId], ''), -6) . '%'], 0);

$noDk = $mk();
$idNoDk = $fromParse($mail(['plain' => $credit((string) $noDk['pnr'], $npr($noDk), $T('K1')), 'auth' => 'mx.google.com; dkim=none; spf=pass']));
$r = PayCredits::tryAutoConfirm($idNoDk);
check('E missing DKIM -> not auto: unmatched + bell, booking pending', !$r['ok'] && PayCredits::get($idNoDk)['state'] === 'unmatched' && $status($noDk) === 'pending' && $bell($idNoDk) === 1, json_encode($r));

$wrongFrom = $mk();
$idWf = $fromParse($mail(['plain' => $credit((string) $wrongFrom['pnr'], $npr($wrongFrom), $T('W1')), 'from' => 'Bank <alerts@gibl.test.evil.example>',
    'auth' => 'mx.google.com; dkim=pass header.d=gibl.test']));
$r = PayCredits::tryAutoConfirm($idWf);
check('E wrong sender (DKIM ok) -> not auto: unmatched, booking pending', !$r['ok'] && str_contains($r['why'], 'not on the allowed list') && $status($wrongFrom) === 'pending', json_encode($r));

$na = $mk();
$idNa = $fromParse($mail(['plain' => str_replace('0123456789012', '0000005555', $credit((string) $na['pnr'], $npr($na), $T('A1')))]));
$r = PayCredits::tryAutoConfirm($idNa);
check('E a genuine signed alert for money paid into ANOTHER account (DKIM + sender + PNR + amount all fine) -> unmatched "not to our account" + bell, booking pending',
    !$r['ok'] && str_contains($r['why'], 'not to our account') && $status($na) === 'pending' && $bell($idNa) === 1, json_encode($r));
$nn = $mk();
$idNn = $fromParse($mail(['plain' => str_replace('Your A/C 0123456789012 has been credited', 'Your money has been credited', $credit((string) $nn['pnr'], $npr($nn), $T('A3')))]));
$r = PayCredits::tryAutoConfirm($idNn);
check('E an alert that names no credited account -> unmatched, booking pending', !$r['ok'] && str_contains($r['why'], 'No credited account') && $status($nn) === 'pending', json_encode($r));
setKeep('bankmail_account_hint', '', 'string');
$nh = $mk();
$idNh = $fromParse($mail(['plain' => $credit((string) $nh['pnr'], $npr($nh), $T('A2'))]));
$r = PayCredits::tryAutoConfirm($idNh);
check('E our account digits not saved (bankmail_account_hint) -> nothing auto-confirms', !$r['ok'] && str_contains($r['why'], 'bankmail_account_hint') && $status($nh) === 'pending', json_encode($r));
setKeep('bankmail_account_hint', '789012', 'string');

$short = $mk();
$idSh = $fromParse($mail(['plain' => $credit((string) $short['pnr'], number_format(round((float) $short['total_amount'] * 1.6) - 100, 2), $T('S1'))]));
$r = PayCredits::tryAutoConfirm($idSh);
check('E amount mismatch -> unmatched with the reason + bell, booking pending', !$r['ok'] && str_starts_with($r['why'], 'Amount NPR') && $status($short) === 'pending' && $bell($idSh) === 1, json_encode($r));
$threw = '';
try { PayCredits::link($idSh, (string) $short['pnr'], (int) (PayAuto::systemAdmin()['id'] ?? 0), true, 'test'); } catch (Throwable $ex) { $threw = $ex->getMessage(); }
check('E a person cannot link a credit whose amount does not match', str_contains($threw, 'Amount does not match') && $status($short) === 'pending', $threw);

$cod = $mk();
Database::update('bookings', ['is_cod' => 1], 'id = :i', ['i' => (int) $cod['id']]);
$idCod = $fromParse($mail(['plain' => $credit((string) $cod['pnr'], $npr($cod), $T('C1'))]));
$r = PayCredits::tryAutoConfirm($idCod);
check('E pay-at-boarding booking -> never auto', !$r['ok'] && str_contains($r['why'], 'pay-at-boarding') && $status($cod) === 'pending', json_encode($r));

$other = $mk();
BookingService::submitPaymentProof((string) $other['pnr'], ['utr' => $T('X1'), 'method' => 'bank']);   // someone typed this txn on another booking
$target = $mk();
$idX = $fromParse($mail(['plain' => $credit((string) $target['pnr'], $npr($target), $T('X1'))]));
$r = PayCredits::tryAutoConfirm($idX);
check('E txn already typed on another booking -> unmatched', !$r['ok'] && str_contains($r['why'], 'another booking') && $status($target) === 'pending', json_encode($r));

$idNo = $fromParse($mail(['plain' => $credit('SHG-2026-99999', '2,400.00', $T('N1'))]));
$r = PayCredits::tryAutoConfirm($idNo);
check('E unknown PNR -> unmatched "not found"', !$r['ok'] && str_contains($r['why'], 'not found'), json_encode($r));

$f4 = $mk();
$four = 'SHG-2099-0' . random_int(1000, 9999);
while (Database::exists('SELECT 1 FROM bookings WHERE pnr = :p', ['p' => $four])) { $four = 'SHG-2099-0' . random_int(1000, 9999); }
Database::update('bookings', ['pnr' => $four], 'id = :i', ['i' => (int) $f4['id']]);
$f4['pnr'] = $four;
$idF4 = $fromParse($mail(['plain' => $credit('SHG-2099-' . substr($four, -4), $npr($f4), $T('F1'))]));
$r = PayCredits::tryAutoConfirm($idF4);
check('E 4-digit PNR in the remarks confirms the 5-digit booking', $r['ok'] && $status($f4) === 'confirmed', json_encode($r));

setKeep('pay_bankmail_autoconfirm_on', '0', 'bool');
$off = $mk();
$idOff = $fromParse($mail(['plain' => $credit((string) $off['pnr'], $npr($off), $T('O1'))]));
$r = PayCredits::tryAutoConfirm($idOff);
check('E switch pay_bankmail_autoconfirm_on off -> waits for a person', !$r['ok'] && $status($off) === 'pending', json_encode($r));
setKeep('pay_bankmail_autoconfirm_on', '1', 'bool');

/* the person's doors */
$sys = PayAuto::systemAdmin();
$lr = PayCredits::link($idNoDk, (string) $noDk['pnr'], (int) ($sys['id'] ?? 0), true, 'checked the bank app');
$tk = $tick($noDk);
check('E Link to PNR (a person, via the queue gate) confirms + chalani "Linked" + credit linked',
    $lr['ok'] && $status($noDk) === 'confirmed' && PayCredits::get($idNoDk)['state'] === 'linked' && ($tk['mode'] ?? '') === 'PAID - Nepali online (Linked)'
    && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'bank_credit.link' AND entity_id = :i", ['i' => (string) $idNoDk], 0) === 1, json_encode($lr) . json_encode($tk));
$threw = '';
try { PayCredits::link($idNoDk, (string) $noDk['pnr'], (int) ($sys['id'] ?? 0)); } catch (Throwable $ex) { $threw = $ex->getMessage(); }
check('E a linked credit cannot be linked again', $threw !== '' && $count('booking.confirm', $noDk) === 1);
PayCredits::ignore($idWf, (int) ($sys['id'] ?? 0), 'not ours');
check('E Ignore sets it aside (audited), booking untouched', PayCredits::get($idWf)['state'] === 'ignored' && $status($wrongFrom) === 'pending'
    && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'bank_credit.ignore' AND entity_id = :i", ['i' => (string) $idWf], 0) === 1);
$threw = '';
try { PayCredits::ignore((int) $hc['id'], (int) ($sys['id'] ?? 0), 'x'); } catch (Throwable $ex) { $threw = $ex->getMessage(); }
check('E a credit that paid a ticket cannot be ignored', $threw !== '');
check('E listRecent shows open credits only with state=open', array_filter(PayCredits::listRecent(['source' => 'gmail', 'state' => 'open', 'limit' => 200]),
    static fn(array $c): bool => !in_array($c['state'], ['new', 'unmatched'], true)) === []);

/* one odd message never stops the reader */
$okB = $mk();
$poisonCs = "Authentication-Results: mx.google.com; dkim=pass header.i=@gibl.test\r\nFrom: Global IME Bank <alerts@gibl.test>\r\nMessage-ID: <mp1cs" . $tag . "@gibl.test>\r\n"
    . "Date: Tue, 25 Nov 99999 10:15:00 +0545\r\nSubject: Credit\r\nContent-Type: text/plain; charset=x-unknown-cs\r\n\r\nYour A/C 0123456789012 has been credited by NPR 100.00\nTxn ID: " . $T('P1') . "\n";
$poisonBig = $mail(['plain' => $credit('SHG-2026-99998', '99999999999999', $T('P2'))]);
$poison8   = $mail(['plain' => "Your A/C 0123456789012 has been credited by NPR 100.00\nTxn ID: " . $T('P3'), 'subject' => "Credit \xE9\xE8 alert"]);
$goodB     = $mail(['plain' => $credit((string) $okB['pnr'], $npr($okB), $T('P4'))]);
$res4 = BankMail::poll(50, $imapFor([5101 => $poisonCs, 5102 => $poisonBig, 5103 => $poison8, 5104 => $goodB]));
$rows4 = [];
foreach (['P1', 'P2', 'P3', 'P4'] as $k) {
    $rows4[$k] = Database::fetch('SELECT * FROM bank_credits WHERE txn_id = :t', ['t' => $T($k)]);
    if ($rows4[$k] !== null) { $credIds[] = (int) $rows4[$k]['id']; }
}
check('E unknown charset + year-99999 date, NPR 99999999999999, 8-bit subject: all stored, none stops the poll, the credit after them confirms',
    $res4['ok'] && $res4['seen'] === 4 && $res4['failed'] === 0 && Settings::getInt('bankmail_last_uid', 0) === 5104 && $status($okB) === 'confirmed'
    && !in_array(null, $rows4, true), json_encode($res4));
check('E the absurd amount is stored as 0 (unmatched "No amount"); the 8-bit subject is stored as valid UTF-8; the odd date became now',
    $rows4['P2'] !== null && (float) $rows4['P2']['amount'] === 0.0 && $rows4['P2']['state'] === 'unmatched'
    && $rows4['P3'] !== null && mb_check_encoding((string) $rows4['P3']['ref_text'], 'UTF-8') && str_contains((string) $rows4['P3']['ref_text'], 'Credit')
    && $rows4['P1'] !== null && abs(strtotime((string) $rows4['P1']['received_at']) - time()) < 600, json_encode([$rows4['P2']['amount'] ?? null, $rows4['P3']['ref_text'] ?? null, $rows4['P1']['received_at'] ?? null]));
$okC = $mk();
$goodC = $mail(['plain' => $credit((string) $okC['pnr'], $npr($okC), $T('Q2'))]);
$boom = static function (string $raw, array &$r): void {
    if (str_contains($raw, 'BOOM')) { throw new ValueError('mb_convert_encoding(): Argument #3 ($from_encoding) contains invalid encoding'); }
    BankMail::handle($raw, $r);
};
$res5 = BankMail::poll(50, $imapFor([5201 => $mail(['plain' => 'BOOM credited by NPR 1.00 Txn ID: ' . $T('Q1')]), 5202 => $goodC]), $boom);
check('E a message that throws is stepped over: bell names its UID, last UID moves past it, the next credit still confirms',
    $res5['ok'] && $res5['failed'] === 1 && Settings::getInt('bankmail_last_uid', 0) === 5202 && $status($okC) === 'confirmed'
    && (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE id > :f AND title = 'Bank mail could not be read · UID 5201'", ['f' => $bellFloor], 0) === 1
    && str_contains((string) $res5['msg'], '1 could not be read'), json_encode($res5));
check('E the next run does not read the stepped-over message again', BankMail::poll(50, $imapFor([5201 => 'BOOM', 5202 => $goodC]), $boom)['seen'] === 0);

setKeep('bankmail_on', '0', 'bool');
$offRes = BankMail::poll(50, $imapFor([5010 => $happyRaw]));
check('E bankmail_on off -> poll does nothing', $offRes['msg'] === 'off' && $offRes['seen'] === 0);

/* ---------------- G. bank webhook ---------------- */
echo "\n-- G bank webhook\n";
setKeep('bankapi_on', '1', 'bool');
setKeep('bankapi_mode', 'sandbox', 'string');
setKeep('bankapi_merchant_id', 'MID-MP1', 'string');
setKeep('bankapi_webhook_secret', 'whsec_mp1_local_test', 'string');
setKeep('bankapi_sig_header', 'X-Signature', 'string');
setKeep('bankapi_sig_format', 'hex', 'string');
setKeep('bankapi_ts_header', 'X-Timestamp', 'string');
setKeep('bankapi_field_map', '', 'string');
setKeep('bankapi_last_webhook', '{}', 'json');
$hook = static function (array $body, ?string $sig = null, array $extraH = []): array {
    $raw = (string) json_encode($body);
    $h = ['x-signature' => $sig ?? hash_hmac('sha256', $raw, 'whsec_mp1_local_test')] + $extraH;
    return BankApi::handleWebhook('POST', $raw, $h, MP_IP);
};
$w = $mk();
$body = ['merchant_id' => 'MID-MP1', 'utr' => $T('U1'), 'amount' => number_format((float) $w['total_amount'], 2, '.', ''), 'reference' => (string) $w['pnr'], 'status' => 'SUCCESS', 'currency' => 'INR'];
$r = $hook($body, str_repeat('0', 64));
check('G bad signature -> 401, nothing stored', $r['code'] === 401 && !Database::exists('SELECT 1 FROM bank_credits WHERE txn_id = :t', ['t' => $T('U1')]), json_encode($r));
check('G GET -> 405', BankApi::handleWebhook('GET', '', [], MP_IP)['code'] === 405);
$r = $hook(['merchant_id' => 'MID-MP1', 'utr' => $T('T1'), 'amount' => $body['amount'], 'reference' => (string) $w['pnr'], 'status' => 'SUCCESS']);
$tc = Database::fetch('SELECT * FROM bank_credits WHERE txn_id = :t', ['t' => $T('T1')]);
if ($tc !== null) { $credIds[] = (int) $tc['id']; }
check('G sandbox, good signature -> 200 state test, never confirms', $r['code'] === 200 && ($r['body']['state'] ?? '') === 'test' && ($tc['state'] ?? '') === 'test' && $status($w) === 'pending', json_encode($r));
$threw = '';
try { PayCredits::link((int) $tc['id'], (string) $w['pnr'], (int) ($sys['id'] ?? 0)); } catch (Throwable $ex) { $threw = $ex->getMessage(); }
check('G a sandbox test credit cannot be linked to a ticket', str_contains($threw, 'sandbox') && $status($w) === 'pending');
setKeep('bankapi_mode', 'live', 'string');
$r = $hook($body + [], null, ['x-timestamp' => (string) (time() - 3600)]);
check('G stale timestamp (1 h old) -> 401', $r['code'] === 401 && ($r['body']['error'] ?? '') === 'stale timestamp');
$r = $hook(['merchant_id' => 'OTHER'] + $body);
check('G wrong merchant id -> 403', $r['code'] === 403);
$r = $hook(['status' => 'FAILED'] + $body);
check('G a failed status is answered and dropped', $r['code'] === 200 && ($r['body']['state'] ?? '') === 'ignored' && $status($w) === 'pending');
$r = $hook($body, null, ['x-timestamp' => (string) time()]);
$uc = Database::fetch('SELECT * FROM bank_credits WHERE txn_id = :t', ['t' => $T('U1')]);
if ($uc !== null) { $credIds[] = (int) $uc['id']; }
$tk = $tick($w);
check('G live, good signature, exact INR -> confirmed', $r['code'] === 200 && ($r['body']['state'] ?? '') === 'confirmed' && $status($w) === 'confirmed' && ($uc['state'] ?? '') === 'matched', json_encode($r));
check('G chalani tick "PAID - Indian UPI (API Auto)" + UPIAPI- proof', ($tk['mode'] ?? '') === 'PAID - Indian UPI (API Auto)'
    && Database::exists('SELECT 1 FROM payments WHERE booking_id = :b AND utr_number = :u AND status = \'verified\'', ['b' => (int) $w['id'], 'u' => 'UPIAPI-' . $T('U1')]));
$r = $hook($body);
check('G replay of the same notice -> 200 duplicate, still one confirm', $r['code'] === 200 && ($r['body']['state'] ?? '') === 'duplicate' && $count('booking.confirm', $w) === 1, json_encode($r));
$r = $hook(['amount' => number_format((float) $w['total_amount'] + 5, 2, '.', '')] + $body);
$cr = PayCredits::get((int) ($r['body']['id'] ?? 0));
if ($cr !== null) { $credIds[] = (int) $cr['id']; }
check('G the same UTR with another amount -> kept for a person (unmatched, no txn id), never a silent duplicate; still one confirm',
    $r['code'] === 200 && ($r['body']['state'] ?? '') === 'unmatched' && $cr !== null && $cr['txn_id'] === null && $count('booking.confirm', $w) === 1, json_encode($r));
$w2 = $mk();
$r = $hook(['utr' => $T('U2'), 'amount' => number_format((float) $w2['total_amount'] + 1, 2, '.', ''), 'reference' => (string) $w2['pnr']] + $body);
check('G live, amount off by ₹1 -> unmatched, booking pending', ($r['body']['state'] ?? '') === 'unmatched' && $status($w2) === 'pending', json_encode($r));
if (($x = (int) Database::scalar('SELECT id FROM bank_credits WHERE txn_id = :t', ['t' => $T('U2')], 0)) > 0) { $credIds[] = $x; }
check('G last webhook recorded for the admin page', (BankApi::lastWebhook()['code'] ?? 0) === 200);
check('G dynamic UPI link carries tr=PNR', str_contains(BankApi::upiUri('a@icici', 'S Hari', 100.0, 'SHG-2026-00842'), 'tr=SHG-2026-00842') && str_contains(BankApi::upiUri('a@icici', 'S Hari', 100.0, 'X'), 'am=100.00&cu=INR'));
setKeep('bankapi_on', '0', 'bool');
check('G bankapi_on off -> 404, nothing read', $hook($body)['code'] === 404);

/* ---------------- H. the admin page ---------------- */
echo "\n-- H admin/payment-center.php\n";
$mkAdmin = static function (string $role) use (&$madeAdmins, $tag): int {
    $id = (int) Database::insert('admins', ['username' => 'mp1-' . $role . '-' . strtolower($tag), 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => 'MP1 ' . $role, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
    $madeAdmins[] = $id;
    return $id;
};
$page = static function (int $admin, array $get = [], bool $nav = false) use ($ini): string {
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' --page ' . escapeshellarg(base64_encode((string) json_encode(['admin' => $admin, 'get' => $get, 'nav' => $nav]))) . ' 2>&1';
    return (string) shell_exec($cmd);
};
$SUP = $mkAdmin('superadmin');
$MGR = $mkAdmin('manager');
$warn = static fn(string $h): bool => preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)[: ]/', $h) === 1;
$h = $page($SUP, ['tab' => 'np']);
check('H Nepal tab renders (QR card, Gmail box, Test button, credits), no PHP warning',
    !$warn($h) && str_contains($h, 'नेपाली QR') && str_contains($h, 'name="bankmail_app_password"') && str_contains($h, 'value="np_test"') && str_contains($h, 'Credits (Gmail)')
    && str_contains($h, 'name="bankmail_account_hint"')
    && !str_contains($h, 'whsec_mp1_local_test'), substr((string) preg_replace('/\s+/', ' ', strip_tags($h)), 0, 300));
$h = $page($SUP, ['tab' => 'in']);
check('H India tab renders with the webhook address and never prints a secret', !$warn($h) && str_contains($h, 'payment/bank-webhook.php') && str_contains($h, 'Field map') && !str_contains($h, 'whsec_mp1_local_test'));
$h = $page($SUP, ['tab' => 'cash', 'day' => $TD]);
check('H Cash tab renders (per counter + per staff, links to the books)', !$warn($h) && str_contains($h, 'Per counter / desk') && str_contains($h, 'Per staff') && str_contains($h, 'cash-book.php'));
$h = $page($MGR, ['tab' => 'np']);
check('H a manager is refused (super-admin only)', !str_contains($h, 'name="bankmail_app_password"') && (str_contains($h, 'super-admin only') || str_contains($h, '<!--HTTP:403-->')), substr(strip_tags($h), 0, 200));
$navS = json_decode(trim(substr($p1 = $page($SUP, [], true), (int) strrpos($p1, '['))), true) ?: [];
$navM = json_decode(trim(substr($p2 = $page($MGR, [], true), (int) strrpos($p2, '['))), true) ?: [];
check('H the menu line shows for the super-admin only', in_array('payment-center.php', $navS, true) && !in_array('payment-center.php', $navM, true), json_encode([count($navS), count($navM)]));

echo "\n" . ($fail === 0 ? "  $pass passed, 0 failed\n" : "  $pass passed, $fail failed\n");
exit($fail === 0 ? 0 : 1);
