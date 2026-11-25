<?php
/**
 * =====================================================================
 *  mp1-pay-test.php — Payment Management release "pay" (25 Nov 2026).
 *
 *   A hardening: test_sandbox.php is CLI-only; ICICI signed success with no amount /
 *     merchant id (or a non-INR currency) is NOT auto-confirmed (icici_strict_fields_on); a
 *     missing currency alone is fine; a full one still is
 *   B one Nepal QR: ManualQrAdapter shows the uploaded NPJ QR + nepal_esewa_id for an NPR sale
 *   C/D the credits ledger + PayCredits rules
 *   E BankMail: parser fixtures (credit with 5- and 4-digit PNR, debit ignored, html/base64,
 *     Devanagari digits, forged lower Authentication-Results, "dkim=pass" faked in an spf
 *     comment / smtp.mailfrom, dmarc=fail, From not aligned, a genuine dkim+dmarc pass, the
 *     credited account read and masked - never from the remarks; AMBIGUITY-REFUSING amount /
 *     txn id / PNR: every figure and every txn id in the WHOLE alert, exactly one distinct value
 *     or none - a payer-typed second figure or second txn id (any label, next line, next table
 *     row, "10.00 NPR", "Amount: 10.00", a mid-line "bal") -> amount 0 / txn '' -> never auto;
 *     only a line that BEGINS with a balance label is left out; credit + debit words -> kept
 *     for a person; every probe fixture of the previous checker end to end through poll(): the
 *     booking stays pending, each credit unmatched with its reason), the IMAP wire client on a scripted stream, poll():
 *     happy path confirms + chalani tick, replay no-op, the same txn on ANOTHER e-mail kept
 *     for a person (never dropped), missing DKIM / wrong sender / amount mismatch / money into
 *     another account / no account digits -> unmatched + bell, debit skipped, an odd message
 *     (unknown charset, absurd amount, 8-bit subject, one that throws) never stops the reader
 *   E (25 Nov 2026, owner-safety) Gmail auto-PAID OFF by default: with pay_bankmail_autoconfirm_on
 *     not stored a perfect alert ends 'matched' as a SUGGESTION (booking still pending, no proof, bell
 *     "बैंक इमेल: रू X आयो · PNR Y …"), link() = Confirm then confirms it (chalani "PAID - Nepali online
 *     (Gmail)", "confirmed by <name>"); with the switch ON (set here, restored) the old auto path still
 *     confirms the genuine fixtures. Fail-closed Authentication-Results (quote / backslash / nested or
 *     unbalanced brackets / non-ASCII / control -> dkim_ok false 'auth header not simple'; P4 + P5 are
 *     real checks now); lines split only on \n (U+2028 / U+2029 / U+0085 / VT / FF = a space), NBSP and
 *     the other Unicode spaces = a space in text/plain too
 *   G bank webhook: bad signature 401, sandbox -> 'test' never confirms, live confirms,
 *     replay no-op, same UTR with another amount -> unmatched, stale timestamp 401, wrong merchant 403
 *   H admin/payment-center.php renders its three tabs for a super-admin, refuses others; the Nepal tab
 *     shows the note + the auto switch read-only (no toggle) and a suggestion with PNR / passenger / due
 *     vs credited / ✓ chips / "✅ Confirm"; the Confirm POST (CSRF + "I saw it in the bank app") confirms
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
    if (isset($cfg['post']) && is_array($cfg['post'])) {   // one form post (CSRF token from this session unless 'nocsrf')
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $cfg['post'] + (empty($cfg['nocsrf']) ? [CSRF_TOKEN_NAME => Security::csrfToken()] : [CSRF_TOKEN_NAME => 'forged-token']);
    }
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
/** The setting not stored at all = its code default (restored at the end like setKeep). */
function unsetKeep(string $k): void {
    global $keep;
    if (!array_key_exists($k, $keep)) { $keep[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]); }
    Database::delete('settings', 'skey = :k', ['k' => $k]);
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
    try { Database::run("DELETE FROM notifications WHERE id > :f AND (title LIKE 'Bank credit needs a person%' OR title LIKE 'Auto-confirmed%' OR title LIKE 'Bank mail could not be read%' OR title LIKE 'बैंक इमेल:%')", ['f' => $bellFloor]); } catch (Throwable $e) {}
    try { Database::run('DELETE FROM rate_limits WHERE identifier = :i', ['i' => MP_IP]); } catch (Throwable $e) {}
    foreach ($madeAdmins as $aid) {
        try { Database::delete('kv_store', "kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $aid]); } catch (Throwable $e) {}
        try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        try { Database::delete('admins', 'id = :a', ['a' => $aid]); } catch (Throwable $e) {}
    }
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        elseif (Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $k])) { Database::run('UPDATE settings SET svalue = :v, stype = :t WHERE skey = :k', ['v' => $row['svalue'], 't' => $row['stype'], 'k' => $k]); }
        else { try { Database::insert('settings', ['skey' => $k] + $row + ['sgroup' => 'payments']); } catch (Throwable $e) {} }   // unsetKeep() removed it
    }
    /* mp4: viewing admin/payment-center.php copies the old single QR (this run's temp file) into
       the QR library as the NP default (which un-defaults the others) - the library goes back
       to exactly what it held before this run (pictures this run added are removed), and
       pay_qr_legacy_imported is restored via $keep */
    global $qrKeep;
    if (is_array($qrKeep)) {
        try {
            $keptFiles = array_column($qrKeep, 'file');
            foreach (Database::fetchAll('SELECT file FROM pay_qr_codes') as $qr) {
                $qf = (string) $qr['file'];
                if (!in_array($qf, $keptFiles, true) && preg_match('#^uploads/payqr/[a-z0-9-]+\.png$#', $qf) === 1 && is_file(ROOT_PATH . '/' . $qf)) { @unlink(ROOT_PATH . '/' . $qf); }
            }
            Database::run('DELETE FROM pay_qr_codes');
            foreach ($qrKeep as $qr) { Database::insert('pay_qr_codes', $qr); }
        } catch (Throwable $e) {}
    }
    $keep = [];
    Settings::flush();
    foreach ($tmpFiles as $f) { @unlink($f); }
    if ($wroteCfg && is_file($CFG)) { @unlink($CFG); }
}
register_shutdown_function('cleanup');
$keep['pay_qr_legacy_imported'] = Database::fetch('SELECT svalue, stype FROM settings WHERE skey = :k', ['k' => 'pay_qr_legacy_imported']);   // mp4 QR library flag
$qrKeep = Database::fetch("SHOW TABLES LIKE 'pay_qr_codes'") !== null ? Database::fetchAll('SELECT * FROM pay_qr_codes') : null;   // mp4: the QR library, restored by cleanup()

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
/* (the default subject says "Credit Alert": a debit fixture now carries a debit subject - a debit body
   under a "Credit Alert" subject is ambiguous and kept for a person, see "credit AND debit words" below) */
$pd = BankMail::parse($mail(['plain' => "Your A/C 0123456789012 has been debited by NPR 2,400.00. Remarks: SHG-2026-00842 Txn ID: 7712345699", 'subject' => 'Debit Alert']), $opts);
check('E debit alert -> is_debit, not a credit', $pd['is_debit'] && !$pd['is_credit'] && !$pd['mixed'] && $pd['why'] === 'debit alert');
$pm = BankMail::parse($mail(['plain' => "Your A/C 0123456789012 has been debited by NPR 2,400.00. Remarks: SHG-2026-00842 Txn ID: 7712345698"]), $opts);
check('E credit AND debit words ("Credit Alert" subject, "debited" body) -> mixed: kept as a credit for a person (hold), never a silent drop',
    $pm['is_credit'] && !$pm['is_debit'] && $pm['mixed'] && $pm['why'] === 'credit and debit words' && str_contains($pm['hold'], 'credit and debit'), json_encode([$pm['is_credit'], $pm['is_debit'], $pm['mixed'], $pm['hold']]));
$pm = BankMail::parse($mail(['plain' => "NPR 2,400.00 has been credited to your A/C ****9012.\nAvailable Balance after withdrawal: NPR 9,000.00\nTxn ID: 7712345697"]), $opts);
check('E a debit word on a balance line only does not make a credit ambiguous', $pm['is_credit'] && !$pm['mixed'] && $pm['hold'] === '' && $pm['amount'] === 2400.0, json_encode([$pm['mixed'], $pm['hold'], $pm['amount']]));
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
/* the topmost Authentication-Results is read unit by unit: comments + quoted strings cut out, only a unit that BEGINS
   "dkim=pass" counts (its own header.d / header.i), a dmarc unit must pass, From: must sit with the signer */
$spfTrick = 'mx.google.com; dkim=pass header.i=@evil.example header.s=s1 header.b=abc; spf=pass (google.com: domain of dkim=pass+header.d=gibl.test@evil.example designates 1.2.3.4 as permitted sender) smtp.mailfrom="dkim=pass+header.d=gibl.test@evil.example"';
$pk = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345605'), 'auth' => $spfTrick]), $opts);
check('E (a) "dkim=pass+header.d=gibl.test" in an spf comment + quoted smtp.mailfrom -> no DKIM (a double quote: the header is not read at all, fail-closed)',
    $pk['dkim_ok'] === false && $pk['dkim_domains'] === [] && $pk['dkim_why'] === 'auth header not simple', json_encode([$pk['dkim_domains'], $pk['dkim_why']]));
$pk = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345605'), 'auth' => str_replace('"', '', $spfTrick)]), $opts);
check('E (a) the same trick without the quotes is read unit by unit -> no DKIM (the one dkim unit says evil.example)',
    $pk['dkim_ok'] === false && $pk['dkim_domains'] === ['evil.example'] && $pk['dkim_why'] === '', json_encode([$pk['dkim_domains'], $pk['dkim_why']]));
$pk = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345606'), 'auth' => 'mx.google.com; spf=pass smtp.mailfrom=dkim=pass+header.d=gibl.test@evil.example']), $opts);
check('E (a) the same trick unquoted inside the spf unit -> no DKIM', $pk['dkim_ok'] === false && $pk['dkim_domains'] === [], json_encode($pk['dkim_domains']));
$pk = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345607'),
    'auth' => 'mx.google.com; dkim=pass header.i=@gibl.test header.s=s1 header.b=AbC; spf=pass smtp.mailfrom=alerts@gibl.test; dmarc=fail (p=NONE sp=NONE dis=NONE) header.from=gibl.test']), $opts);
check('E (b) dkim=pass for the bank but dmarc=fail in the same header -> no DKIM', $pk['dkim_ok'] === false && $pk['dkim_domains'] === ['gibl.test'], json_encode($pk['dkim_domains']));
$pk = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345608'),
    'auth' => 'mx.google.com; dkim=pass header.i=@gibl.test header.s=s1; dmarc=pass (p=NONE) header.from=evil.example']), $opts);
check('E (b) dmarc=pass but for another header.from -> no DKIM', $pk['dkim_ok'] === false);
$pk = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345609'), 'from' => 'Global IME Bank <alerts@evil.example>']), $opts);
check('E (c) bank-signed (header.i=@gibl.test) but From: alerts@evil.example -> no DKIM (From not aligned)', $pk['dkim_ok'] === false);
$pk = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345610'), 'from' => 'Global IME Bank <alerts@other.gibl.test>',
    'auth' => 'mx.google.com; dkim=pass header.d=mail.gibl.test header.s=s1']), $opts);
check('E (c) From: other.gibl.test signed by mail.gibl.test (siblings, neither under the other) -> no DKIM', $pk['dkim_ok'] === false);
$pk = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345611'), 'auth' => 'mx.google.com; dkim=pass header.d=gibl.test header.i=@evil.example header.s=s1']), $opts);
check('E a dkim unit whose header.i sits outside its header.d is not trusted -> no DKIM', $pk['dkim_ok'] === false && $pk['dkim_domains'] === []);
/* (d) Gmail's own shape: one-level comments only (the old fixture's "(good (2048-bit rsa) signature)" is NESTED: not simple) */
$genuine = 'mx.google.com; dkim=pass header.d=gibl.test header.s=s1 header.b=AbC+/9=; spf=pass (google.com: domain of alerts@gibl.test designates 1.2.3.4 as permitted sender) smtp.mailfrom=alerts@gibl.test; dmarc=pass (p=REJECT sp=REJECT dis=NONE) header.from=gibl.test';
$pk = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345612'), 'auth' => $genuine]), $opts);
check('E (d) genuine: dkim=pass header.d=gibl.test + dmarc=pass header.from=gibl.test, From alerts@gibl.test -> DKIM ok',
    $pk['dkim_ok'] === true && $pk['dkim_domains'] === ['gibl.test'] && $pk['dkim_why'] === '', json_encode($pk['dkim_domains']));
/* FAIL-CLOSED (round 3): the topmost header with a quote, a backslash, nested / unbalanced brackets, non-ASCII or a control
   character is not read at all -> dkim_ok false, 'auth header not simple' - even when everything else in it says pass */
$notSimple = [
    'nested comment'       => str_replace('dkim=pass header.d', 'dkim=pass (good (2048-bit rsa) signature) header.d', $genuine),
    'double quote'         => str_replace('smtp.mailfrom=alerts@gibl.test', 'smtp.mailfrom="alerts"@gibl.test', $genuine),
    'backslash'            => str_replace('(p=REJECT', '(p\\=REJECT', $genuine),
    'unclosed ('           => str_replace('dis=NONE)', 'dis=NONE', $genuine),
    'stray )'              => str_replace('(google.com:', 'google.com:)', $genuine),
    ') before ('           => str_replace('(p=REJECT sp=REJECT dis=NONE)', ')p=REJECT sp=REJECT dis=NONE(', $genuine),
    'non-ASCII (é)'        => str_replace('permitted sender', 'permitted sendér', $genuine),
    'non-ASCII NBSP'       => str_replace('dkim=pass header.d', "dkim=pass\u{00A0}header.d", $genuine),
    'control (tab)'        => str_replace('dkim=pass header.d', "dkim=pass\theader.d", $genuine),
    'control (\x01)'       => str_replace('header.s=s1', "header.s=s1\x01", $genuine),
];
$nsBad = [];
foreach ($notSimple as $nk => $au) {
    $pq = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345614'), 'auth' => $au]), $opts);
    if ($pq['dkim_ok'] !== false || $pq['dkim_why'] !== 'auth header not simple' || $pq['dkim_domains'] !== []) { $nsBad[$nk] = [$pq['dkim_ok'], $pq['dkim_why']]; }
}
check('E fail-closed: nested comment, double quote, backslash, unclosed "(", stray ")", ")" before "(", non-ASCII (é, NBSP), tab, \x01 in the topmost Authentication-Results -> dkim_ok false, "auth header not simple"',
    $nsBad === [] && BankMail::authSimple($genuine) && !BankMail::authSimple(''), json_encode($nsBad));
check('E fail-closed: authResults() of a not-simple header reads nothing (serv_ok false, no dkim, no dmarc, the note)',
    BankMail::authResults($notSimple['double quote'], 'mx.google.com') === ['serv_ok' => false, 'dkim' => [], 'dmarc' => [], 'note' => 'auth header not simple']
    && BankMail::authResults($genuine, 'mx.google.com')['note'] === '' && BankMail::authResults($genuine, 'mx.google.com')['serv_ok'] === true);
$pk = BankMail::parse($mail(['plain' => $credit('SHG-2026-00842', '2,400.00', '7712345613'), 'from' => 'Global IME Bank <alerts@alerts.gibl.test>',
    'auth' => "mx.google.com;\r\n       dkim=pass header.i=@gibl.test header.s=s1 header.b=AbC;\r\n       dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=alerts.gibl.test"]), $opts);
check('E (d) folded header, From: alerts.gibl.test signed by gibl.test (subdomain of the signer), dmarc pass -> DKIM ok', $pk['dkim_ok'] === true);
/* the owner's rules (group 1): the override is really applied ([...] + $opts: $opts holds '' for both keys), on
   the bank's text - the bank's Txn ID sits on its own line, not after "Remarks:" on the payer's line */
$pr = BankMail::parse($mail(['plain' => "Credited NPR 10.00\nRemarks: x\nJournal: 1234567"]), ['amount_regex' => '/Credited NPR ([0-9.,]+)/', 'txn_regex' => '/Journal: (\d+)/'] + $opts);
check('E owner regex overrides (group 1) read amount and txn', abs($pr['amount'] - 10.0) < 0.001 && $pr['txn_id'] === '1234567', json_encode([$pr['amount'], $pr['txn_id']]));
/* AMBIGUITY-REFUSING (25 Nov 2026, round 2): the bank's exact layout is unknown, so the parser no longer
   tries to tell the bank's figure from the payer's. (e)/(f)/(g) used to expect the bank's value to win;
   now a payer-typed SECOND figure or SECOND txn id makes the amount 0 / the txn id '' (a person checks,
   with the reason in 'hold') - so such an alert can never auto-confirm, whichever value is the bank's. */
$pe = BankMail::parse($mail(['plain' => "NPR 10.00 has been credited to your A/C ****9012 on 25-11-2026.\nRemarks: SHG-2026-00842 credited by NPR 2400\nTxn ID: 88112233"]), $opts);
check('E (e) "credited by NPR 2400" typed in the remarks + the bank\'s NPR 10 -> two figures: amount 0 (held), txn 88112233, PNR, account XXXX9012',
    $pe['amount'] === 0.0 && $pe['amounts'] === [10.0, 2400.0] && str_starts_with($pe['hold'], 'Different amounts') && $pe['txn_id'] === '88112233'
    && $pe['pnr'] === 'SHG-2026-00842' && $pe['account'] === 'XXXX9012', json_encode([$pe['amount'], $pe['amounts'], $pe['txn_id'], $pe['pnr'], $pe['account'], $pe['hold']], JSON_UNESCAPED_UNICODE));
$pv = BankMail::parse($mail(['html' => '<html><body><table><tr><td>खातामा</td><td>रु १०.००</td></tr><tr><td>जम्मा भयो · credited</td></tr><tr><td>Narration</td><td>SHG-2026-00843 credited by NPR 2400</td></tr><tr><td>Reference No: 99001122335</td></tr></table></body></html>']), $opts);
check('E (f) HTML + Devanagari: रु १० + a Narration cell "credited by NPR 2400" -> two figures: amount 0, reference id, PNR',
    $pv['is_credit'] && $pv['amount'] === 0.0 && $pv['hold'] !== '' && $pv['txn_id'] === '99001122335' && $pv['pnr'] === 'SHG-2026-00843', json_encode([$pv['amount'], $pv['txn_id'], $pv['pnr']]));
$pg = BankMail::parse($mail(['plain' => "Your A/C 0123456789012 has been credited by NPR 500.00.\nRemarks: SHG-2026-00842 ref 55554444\nTxn ID: 99887766"]), $opts);
check('E (g) "ref 55554444" typed in the remarks + the bank\'s Txn ID 99887766 -> two txn ids: txn \'\' (held), amount 500, PNR',
    $pg['txn_id'] === '' && $pg['txns'] === ['55554444', '99887766'] && str_starts_with($pg['hold'], 'Different transaction') && $pg['pnr'] === 'SHG-2026-00842' && abs($pg['amount'] - 500.0) < 0.001,
    json_encode([$pg['amount'], $pg['txn_id'], $pg['txns'], $pg['pnr']]));
$pp = BankMail::parse($mail(['plain' => "NPR 500.00 has been credited to your A/C ****9012.\nRemarks: SHG-2026-00842 SHG-2026-00843\nTxn ID: 99887765"]), $opts);
check('E two different PNRs anywhere -> no PNR, and ref_text names both (the ledger never sees just one)', $pp['pnr'] === '' && count($pp['pnrs']) === 2
    && count(PayCredits::pnrsIn($pp['remarks'])) === 2, json_encode([$pp['pnr'], $pp['pnrs'], $pp['remarks']]));
$pp = BankMail::parse($mail(['plain' => "NPR 500.00 has been credited to your A/C ****9012.\nRemarks: SHG-2026-0842 (SHG-2026-00842)\nTxn ID: 99887764"]), $opts);
check('E the same PNR written 4- and 5-digit is ONE PNR', $pp['pnr'] === 'SHG-2026-0842' && count($pp['pnrs']) === 1, json_encode($pp['pnrs']));
$pg = BankMail::parse($mail(['plain' => "Your A/C 0123456789012 has been credited by NPR 500.00.\nRemarks: SHG-2026-00842 ref 55554444 credited by NPR 2400\nTxn ID: 99887766"]),
    ['amount_regex' => '/credited by NPR ([0-9.,]+)\s*$/m', 'txn_regex' => '/ref (\d+)/'] + $opts);
check('E the owner regexes never read the remarks either (only there do they match -> amount 0, no txn id)', $pg['amount'] === 0.0 && $pg['txn_id'] === '', json_encode([$pg['amount'], $pg['txn_id']]));
$pt = BankMail::parse($mail(['plain' => "NPR 10.00 has been credited to your A/C ****9012.\nSender note: credited by NPR 2,400.00\nTxn ID: 88112238\nAvailable Balance: NPR 55,000.00"]), $opts);
check('E two different NPR figures outside the balance line (an unlabelled payer note) -> amount 0, a person checks', $pt['amount'] === 0.0, (string) $pt['amount']);
/* only a line that BEGINS with a balance label is left out (was: a "bal" up to 40 characters before the figure on
   the same line - a payer could type "bal" right before the bank's figure). A mid-line balance now makes it 0. */
check('E a balance figure is not a second figure when its LINE BEGINS with a balance label (Avl Bal / Available Balance / Avail. Bal / मौज्दात / ब्यालेन्स)',
    BankMail::amountIn("credited by NPR 2,400.00\nAvl Bal NPR 9,000.00") === 2400.0 && BankMail::amountIn("credited by NPR 2,400.00\n   Available Balance: NPR 9,000.00") === 2400.0
    && BankMail::amountIn("credited by NPR 2,400.00\nAvail. Bal: NPR 9,000.00") === 2400.0 && BankMail::amountIn("रु 2,400.00 जम्मा भयो\nमौज्दात रु ९,०००.००") === 2400.0
    && BankMail::amountIn("credited by NPR 2,400.00\nब्यालेन्स: NPR 9,000.00") === 2400.0 && BankMail::amountIn("credited by NPR 2,400.00\nBalance\nNPR 9,000.00") === 0.0
    && BankMail::amountIn('NPR 2,400.00 has been credited (NPR 2,400.00)') === 2400.0 && BankMail::amountIn("credited by NPR 2,400.00\nBalaju branch, NPR 2,400.00") === 2400.0);
check('E a mid-line "bal" never excludes anything: "…, Avl Bal: NPR 9,000" on the credit line -> 0; a line BEGINNING "Avl Bal" holds no credit either -> 0',
    BankMail::amountIn('credited by NPR 2,400.00, Avl Bal: NPR 9,000.00') === 0.0 && BankMail::amountIn('Avl Bal: NPR 9,000.00. NPR 2,400.00 has been credited') === 0.0
    && BankMail::amountIn('SHG-2026-00842 credited by NPR 2400 bal NPR 10.00') === 0.0);
/* round 3: lines split ONLY on \n (\r\n and \r become \n); U+2028 / U+2029 / U+0085 / VT / FF are a space, so a separator the
   payer types can never start a fake "Bal" line that blanks the bank's own figure */
$seps = ['U+2028' => "\u{2028}", 'U+2029' => "\u{2029}", 'U+0085' => "\u{0085}", 'VT' => "\x0B", 'FF' => "\x0C"];
$sepBad = [];
foreach ($seps as $sk => $sc) {
    if (BankMail::amountIn("credited by NPR 2,400.00{$sc}Avl Bal NPR 9,000.00") !== 0.0) { $sepBad[] = $sk . ' split'; }
    if (str_contains(BankMail::scanText("a{$sc}b"), "\n") || BankMail::scanText("a{$sc}b") !== 'a b') { $sepBad[] = $sk . ' scan'; }
    /* the attack: bank line "Remarks: <payer> Amount: NPR 10.00" - the payer types "<due> <sep>Bal" so the bank's NPR 10 would sit on a "Bal" line */
    $pa2 = BankMail::parse($mail(['plain' => "Your A/C ****9012 has been credited. Remarks: SHG-2026-00842 credited by NPR 2400 {$sc}Bal Amount: NPR 10.00\nTxn ID: 88112240", 'subject' => 'Credit Alert']), $opts);
    if ($pa2['amount'] !== 0.0 || $pa2['amounts'] !== [2400.0, 10.0] || $pa2['hold'] === '') { $sepBad[] = $sk . ' parse ' . json_encode([$pa2['amount'], $pa2['amounts']]); }
}
check('E a payer-typed U+2028 / U+2029 / U+0085 / VT / FF never starts a "Bal" line: the bank\'s NPR 10 still counts -> two figures, held',
    $sepBad === [], json_encode($sepBad, JSON_UNESCAPED_UNICODE));
check('E \r\n is a line end for the balance rule ("…2,400.00\r\nAvl Bal …" -> 2400); a LONE \r is only a space (payer-typed CR: two figures -> 0, held)',
    BankMail::amountIn("credited by NPR 2,400.00\r\nAvl Bal NPR 9,000.00") === 2400.0 && BankMail::amountIn("credited by NPR 2,400.00\rAvl Bal NPR 9,000.00") === 0.0
    && BankMail::scanText("x\r\ny\rz") === "x\ny z");
/* HTML: an entity line break (&#10; / &#13; / &#8232;) inside a cell is a space - only real tags (<br>, </p>, </tr> ...) make lines */
check('E HTML entity &#10; inside the remarks cannot start a fake "Bal" line (bank NPR 10 still counts -> two figures -> 0)',
    str_contains(BankMail::htmlToText('<p>Remarks: SHG-2026-00842 NPR 2400&#10;Bal Amount: NPR 10.00</p><p>x</p>'), "NPR 2400 Bal Amount")
    && BankMail::amountIn(BankMail::htmlToText('<p>Remarks: SHG-2026-00842 NPR 2400&#10;Bal Amount: NPR 10.00</p>')) === 0.0
    && BankMail::htmlToText('<p>a</p><p>b<br>c</p>') === "a\nb\nc");
/* NBSP and the other Unicode spaces are a plain space in text/plain (not only HTML): the bank's "NPR\u{00A0}10" must be seen */
$pn1 = BankMail::parse($mail(['plain' => "NPR\u{00A0}2,400 has been credited to your A/C\u{00A0}****9012.\nRemarks: SHG-2026-00842\nTxn ID: 88112241", 'subject' => 'Credit Alert']), $opts);
$pn2 = BankMail::parse($mail(['plain' => "NPR\u{00A0}10 has been credited to your A/C ****9012.\nRemarks: SHG-2026-00842 credited by NPR 2400\nTxn ID: 88112242", 'subject' => 'Credit Alert']), $opts);
$pn3 = BankMail::parse($mail(['plain' => "NPR\u{202F}10 credited\u{2007}to A/C ****9012. Remarks: SHG-2026-00842 credited by NPR\u{3000}2400\nTxn ID: 88112243", 'subject' => 'Credit Alert']), $opts);
check('E text/plain NBSP: "NPR 2,400" reads 2400 (account too); the bank\'s "NPR 10" beside a payer\'s NPR 2400 -> two figures, held; U+202F / U+2007 / U+3000 the same',
    $pn1['amount'] === 2400.0 && $pn1['account'] === 'XXXX9012' && !str_contains($pn1['text'], "\u{00A0}")
    && $pn2['amount'] === 0.0 && $pn2['amounts'] === [10.0, 2400.0] && $pn2['hold'] !== ''
    && $pn3['amount'] === 0.0 && $pn3['amounts'] === [10.0, 2400.0],
    json_encode([[$pn1['amount'], $pn1['account']], $pn2['amounts'], $pn3['amounts']]));
$ph2 = BankMail::parse($mail(['html' => "<table><tr><td>A/C ****9012 credited</td></tr><tr><td>Remarks: SHG-2026-00842 credited by NPR 2400\nAvl Bal</td><td>NPR&nbsp;10.00</td></tr><tr><td>Txn ID: 88112244</td></tr></table>", 'subject' => 'Credit Alert']), $opts);
check('E HTML: a newline in the HTML source (a payer\'s, inside a cell) is only a space, never a line start - "…NPR 2400\nAvl Bal | NPR&nbsp;10.00" keeps the bank\'s 10 -> two figures, held',
    $ph2['amount'] === 0.0 && $ph2['amounts'] === [2400.0, 10.0] && $ph2['hold'] !== '', json_encode($ph2['amounts']));
check('E every money form counts: "10.00 NPR", "Amount: 10.00", "Amount (NPR) 10", "रकम: 10", "credited with 10.00", a bare 10.00 cell, "Rs.10", "₹10" (each beside NPR 2,400 -> 0)',
    BankMail::figures("credited 10.00 NPR\nAmount: 11.00\nAmount (NPR) 12\nरकम: 13\ncredited with 14\nDetails 15.00 x\nRs.16 ₹17 NRs 18 INR 19 रू 20")
        === [16.0, 17.0, 18.0, 19.0, 20.0, 10.0, 11.0, 12.0, 13.0, 14.0, 15.0]
    && BankMail::amountIn("NPR 2,400.00 credited\nAmount: 10") === 0.0 && BankMail::amountIn("NPR 2,400.00 credited\nAmount\n10") === 0.0
    && BankMail::amountIn("NPR 2,400.00 credited\n25-11 Details 10.00") === 0.0, json_encode(BankMail::figures("credited 10.00 NPR\nAmount: 11.00\nAmount (NPR) 12\nरकम: 13\ncredited with 14\nDetails 15.00 x\nRs.16 ₹17 NRs 18 INR 19 रू 20")));
check('E ids, accounts, phones and dates are not figures: Txn 7712345678 NPR, A/C 0123456789012 NPR, ****9012 NPR, SHG-2026-00842 NPR, 25.11.2026, deposited by 9812345678',
    BankMail::figures("Txn 7712345678 NPR 2,400.00 credited A/C ****9012 NPR\nA/C 0123456789012 NPR, SHG-2026-00842 NPR, on 25.11.2026, deposited by 9812345678") === [2400.0]);
check('E owner amount regex must AGREE with the one figure (another value / no match / a broken pattern -> 0, with the reason)',
    BankMail::amountIn('NPR 10.00 credited. Journal NPR 10.00', '/Journal NPR ([0-9.,]+)/') === 10.0
    && BankMail::amountIn('NPR 10.00 credited.', '/Journal NPR ([0-9.,]+)/') === 0.0 && BankMail::amountIn('NPR 10.00 credited.', '/(unclosed/') === 0.0
    && str_starts_with(BankMail::parse($mail(['plain' => "NPR 10.00 credited to A/C ****9012. Txn ID: 88112239"]), ['amount_regex' => '/Journal NPR ([0-9.,]+)/'] + $opts)['hold'], 'bankmail_amount_regex does not agree'));
check('E txn ids: every label (Txn ID / Ref. No. / Reference No / UTR / ट्रान्जेक्सन नं) + the owner\'s txn regex are all candidates; one distinct -> it, two -> \'\'',
    BankMail::txnIn("Txn ID: 88112233\nRef. No.: 88112233") === '88112233' && BankMail::txnIn("Txn ID: 88112233\nRef. No.: 99112233") === ''
    && BankMail::txnIn('ट्रान्जेक्सन नं: 7712345678') === '7712345678' && BankMail::txnIn("UTR: 123456789012\nJournal 5544332211", '/Journal (\d+)/') === ''
    && BankMail::txnIn('Journal 5544332211', '/Journal (\d+)/') === '5544332211' && BankMail::txnIn('Ref: SHG-2026-00842') === '');

/* the previous checker's probe fixtures (scratchpad probe.php / probe2.php), unchanged: none may auto-confirm.
   $canAuto = what tryAutoConfirm needs from the parse (a credit, not held, an amount, a txn id, one PNR, DKIM). */
$canAuto = static fn(array $p): bool => $p['is_credit'] && !$p['mixed'] && $p['hold'] === '' && $p['amount'] > 0 && $p['txn_id'] !== '' && $p['pnr'] !== '' && $p['dkim_ok'];
$probeAuth = 'mx.google.com; dkim=pass header.i=@gibl.test header.s=s1 header.b=AbC; spf=pass smtp.mailfrom=alerts@gibl.test';
$probes = [   // label => [mail fixture, extra opts, expected amount, expected txn]
    'P1 Remarks: label alone, payer text on the next line, "Amount: 10.00"' => [['plain' => "Your A/C ****9012 has been credited.\nAmount: 10.00\nRemarks:\nSHG-2026-00842 credited by NPR 2400\nTxn ID: 88112233"], [], 0.0, '88112233'],
    'P1b HTML "Remarks<br>value", "Amount (NPR)" cell 10.00' => [['html' => "<table><tr><td>A/C</td><td>****9012</td></tr><tr><td>Amount (NPR)</td><td>10.00</td></tr><tr><td>Status</td><td>credited</td></tr><tr><td>Remarks<br>SHG-2026-00842 credited by NPR 2400</td></tr><tr><td>Txn ID</td><td>88112233</td></tr></table>"], [], 0.0, '88112233'],
    'P1c HTML Remarks row / value row (+ ref), NPR 10.00' => [['html' => "<table><tr><td>NPR 10.00 has been credited to A/C ****9012</td></tr><tr><td>Remarks</td></tr><tr><td>SHG-2026-00842 ref 55554444 credited by NPR 2400</td></tr><tr><td>Txn ID: 88112233</td></tr></table>"], [], 0.0, ''],
    'P2 Devanagari कैफियत label + "ref 55554444"' => [['plain' => "तपाईंको खाता ****9012 मा रु 10.00 जम्मा भयो (credited).\nकैफियत: SHG-2026-00842 ref 55554444\nTxn ID: 99887766"], [], 10.0, ''],
    'P2b कैफियत + "रकम: 10.00" without currency' => [['plain' => "तपाईंको खाता ****9012 मा जम्मा भयो (credited).\nरकम: 10.00\nकैफियत: SHG-2026-00842 credited by NPR 2400\nTxn ID: 99887766"], [], 0.0, '99887766'],
    'P3 "Note:" label + Amount: 10.00' => [['plain' => "Your A/C ****9012 has been credited.\nAmount: 10.00\nNote: SHG-2026-00842 credited by NPR 2400\nTxn ID: 88112233"], [], 0.0, '88112233'],
    'P6 columnar row, payer "bal" before the bank\'s NPR 10' => [['html' => "<table><tr><th>Date</th><th>Details</th><th>Credit</th></tr><tr><td>25-11-2026</td><td>SHG-2026-00842 credited by NPR 2400 bal</td><td>NPR 10.00</td></tr><tr><td>A/C ****9012 credited</td></tr></table>"], [], 0.0, ''],
    'P7 bank "10.00 NPR" (currency after) + an unlabelled note' => [['plain' => "Your A/C ****9012 has been credited with 10.00 NPR.\nSender note: credited by NPR 2400\nTxn ID: 88112233"], [], 0.0, '88112233'],
    'P12 owner amount regex + Remarks value on the next line' => [['plain' => "Your A/C ****9012 has been credited.\nAmount: 10.00\nRemarks:\nCredited NPR 2400\nTxn ID: 88112233"], ['amount_regex' => '/Credited NPR ([0-9.,]+)/i'], 0.0, '88112233'],
    'Q1 plain "Remarks:" then value line (+ ref) then Txn ID' => [['plain' => "Your A/C 0123456789012 has been credited by NPR 500.00.\nRemarks:\nSHG-2026-00842 ref 55554444\nTxn ID: 99887766"], [], 500.0, ''],
    'Q2 HTML "Remarks:" row / value row (+ ref) then Txn ID' => [['html' => "<table><tr><td>NPR 500.00 has been credited to A/C ****9012</td></tr><tr><td>Remarks:</td></tr><tr><td>SHG-2026-00842 ref 55554444</td></tr><tr><td>Txn ID: 99887766</td></tr></table>"], [], 500.0, ''],
    'Q3 HTML "Remarks:<br>value", Amount: 500.00' => [['html' => "<p>Your A/C ****9012 has been credited.</p><p>Amount: 500.00</p><p>Remarks:<br>SHG-2026-00842 credited by NPR 2400</p><p>Txn ID: 99887766</p>"], [], 0.0, '99887766'],
    /* Q4 was the checker's "legit" layout: the balance VALUE sits on its own row under an "Available Balance" row, so its
       line does not begin with a balance label -> it counts -> two figures -> a person checks (fails safe, never auto) */
    'Q4 balance label row / value row (fails safe: a person checks)' => [['html' => "<table><tr><td>NPR 2,400.00 has been credited to A/C ****9012</td></tr><tr><td>Remarks: SHG-2026-00842</td></tr><tr><td>Txn ID: 99887766</td></tr><tr><td>Available Balance</td></tr><tr><td>NPR 55,000.00</td></tr></table>"], [], 0.0, '99887766'],
    'P10 dmarc=bestguesspass (no DKIM pass)' => [['plain' => "NPR 2,400.00 has been credited to your A/C ****9012.\nRemarks: SHG-2026-00842\nTxn ID: 88112233", 'auth' => 'mx.google.com; dkim=pass header.i=@gibl.test header.s=s1 header.b=AbC; spf=pass smtp.mailfrom=alerts@gibl.test; dmarc=bestguesspass header.from=gibl.test'], [], 2400.0, '88112233'],
];
foreach ($probes as $label => [$fx, $xo, $wantAmt, $wantTxn]) {
    $pq = BankMail::parse($mail($fx + ['auth' => $probeAuth, 'subject' => 'Credit Alert']), $xo + $opts);
    check('E probe ' . $label . ' -> amount ' . $wantAmt . ', txn ' . ($wantTxn !== '' ? $wantTxn : "''") . ', never auto-confirms',
        !$canAuto($pq) && abs($pq['amount'] - $wantAmt) < 0.001 && $pq['txn_id'] === $wantTxn && ($pq['hold'] !== '' || !$pq['dkim_ok']),
        json_encode(['amt' => $pq['amount'], 'amounts' => $pq['amounts'], 'txn' => $pq['txn_id'], 'txns' => $pq['txns'], 'pnr' => $pq['pnr'], 'dkim' => $pq['dkim_ok'], 'hold' => $pq['hold']], JSON_UNESCAPED_UNICODE));
}
/* the genuine single-figure probes read cleanly (and so may auto-confirm; end to end below) */
$pq = BankMail::parse($mail(['plain' => "NPR 2,400.00 credited to A/C ****9012 Remarks: SHG-2026-00842 Txn ID: 88112233", 'auth' => $probeAuth, 'subject' => 'Credit Alert']), $opts);
check('E probe P8 one-line genuine alert -> 2400, txn 88112233 (no longer cut away after "Remarks:"), PNR, not held', $canAuto($pq) && $pq['amount'] === 2400.0 && $pq['txn_id'] === '88112233' && $pq['account'] === 'XXXX9012', json_encode([$pq['amount'], $pq['txn_id'], $pq['hold']]));
$pq = BankMail::parse($mail(['plain' => "NPR 2,400.00 has been credited to your A/C ****9012.\nRemarks: SHG-2026-00842\nTxn ID: 88112233", 'subject' => 'Credit Alert',
    'auth' => 'mx.google.com; dkim=pass header.i=@gibl.test header.s=s1 header.b=AbCdEf12; arc=pass (i=1); spf=pass (google.com: domain of alerts@gibl.test designates 1.2.3.4 as permitted sender) smtp.mailfrom=alerts@gibl.test; dmarc=pass (p=REJECT sp=REJECT dis=NONE) header.from=gibl.test']), $opts);
check('E probe P9 genuine Gmail (dkim + arc + spf + dmarc pass) -> readable, not held', $canAuto($pq) && $pq['amount'] === 2400.0 && $pq['txn_id'] === '88112233', json_encode([$pq['amount'], $pq['txn_id'], $pq['hold']]));
$pq = BankMail::parse($mail(['plain' => "Rs. 10.00 credited to A/C ****9012.\nAmount NPR 10\nTxn ID: 88112233", 'auth' => $probeAuth, 'subject' => 'Credit Alert']), $opts);
check('E probe P11 the same figure twice (Rs. 10.00 / NPR 10) is ONE figure -> 10 (no PNR: not auto)', $pq['amount'] === 10.0 && $pq['amounts'] === [10.0] && $pq['hold'] === '' && $pq['pnr'] === '' && !$canAuto($pq));
$pq = BankMail::parse($mail(['plain' => "NPR 2,400.00 has been credited to A/C ****9012\nRemarks\nTxn ID: 99887766\nSHG-2026-00842", 'auth' => $probeAuth, 'subject' => 'Credit Alert']), $opts);
check('E probe Q5 empty "Remarks" then the bank\'s Txn line -> 2400, txn 99887766 (no longer eaten), PNR', $canAuto($pq) && $pq['amount'] === 2400.0 && $pq['txn_id'] === '99887766' && $pq['pnr'] === 'SHG-2026-00842');
/* P4 / P5 attack the Authentication-Results reader (a ')' inside a quoted local part in the spf comment closes the
   comment early; an unclosed '(' swallows dmarc=fail). Round 3: the reader fails closed on any quote / backslash /
   nested or unbalanced bracket, so both are real checks now (they were NOTE lines that printed "WOULD AUTO-CONFIRM"). */
$a4 = 'mx.google.com; dkim=pass header.i=@evil.example header.s=s1 header.b=abc; spf=pass (google.com: domain of "x);dkim=pass header.i=@gibl.test;(y"@evil.example designates 1.2.3.4 as permitted sender) smtp.mailfrom="x);dkim=pass header.i=@gibl.test;(y"@evil.example';
$a5 = 'mx.google.com; dkim=pass header.i=@evil.example header.s=s1 header.b=abc; spf=pass (google.com: domain of "x);dkim=pass header.i=@gibl.test;(("@evil.example designates 1.2.3.4 as permitted sender) smtp.mailfrom="x);dkim=pass header.i=@gibl.test;(("@evil.example; dmarc=fail (p=REJECT sp=REJECT dis=NONE) header.from=gibl.test';
$a5b = 'mx.google.com; dkim=pass header.i=@gibl.test header.s=s1; spf=pass (google.com: domain of x@evil.example designates 1.2.3.4 as permitted sender) smtp.mailfrom=x"@evil.example; dmarc=fail (p=REJECT) header.from=gibl.test';
foreach (['P4 ")" breakout in the spf comment, no dmarc' => $a4, 'P5 unclosed "((" hides dmarc=fail' => $a5, 'P5b unclosed quote hides dmarc=fail' => $a5b] as $label => $au) {
    $pq = BankMail::parse($mail(['plain' => "NPR 2,400.00 has been credited to your A/C ****9012. Remarks: SHG-2026-00842\nTxn ID: 88112233", 'auth' => $au, 'subject' => 'Credit Alert']), $opts);
    check('E DKIM probe ' . $label . ' -> dkim_ok false ("auth header not simple"), no signing domain read, never auto-confirms',
        $pq['dkim_ok'] === false && $pq['dkim_why'] === 'auth header not simple' && $pq['dkim_domains'] === [] && !$canAuto($pq)
        && BankMail::authResults($au, 'mx.google.com')['serv_ok'] === false,
        json_encode(['dkim' => $pq['dkim_ok'], 'why' => $pq['dkim_why'], 'domains' => $pq['dkim_domains']]));
}
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
foreach (['amount', 'merchantId'] as $drop) {
    $b = $mk();
    $d = $ip($b, 'BANK' . random_int(100000000, 999999999));
    unset($d[$drop]);
    $r = $icici($d);
    check("A signed production success WITHOUT $drop -> proof kept, stays pending for a person",
        $r['state'] === 'success' && $status($b) === 'pending' && $count('payment.auto_confirmed', $b) === 0
        && Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND utr_number LIKE 'ICICI-%'", ['b' => (int) $b['id']]), json_encode($r) . ' ' . $status($b));
}
/* 25 Nov 2026 (mp1 review): nothing shows ICICI returns a currency field, so a missing one no
   longer blocks auto-confirm (amount + merchant id still must be there); a currency that IS
   sent must be INR / 356. */
$b = $mk();
$d = $ip($b, 'BANK' . random_int(100000000, 999999999));
unset($d['currencyCode']);
$r = $icici($d);
check('A signed production success WITHOUT currencyCode (amount + merchant id there) -> still auto-confirms', $r['state'] === 'success' && $status($b) === 'confirmed' && $count('payment.auto_confirmed', $b) === 1, json_encode($r) . ' ' . $status($b));
$b = $mk();
$d = $ip($b, 'BANK' . random_int(100000000, 999999999));
$d['currencyCode'] = '840';
$r = $icici($d);
check('A signed production success with currencyCode 840 (USD) -> proof kept, stays pending for a person', $r['state'] === 'success' && $status($b) === 'pending' && $count('payment.auto_confirmed', $b) === 0, json_encode($r) . ' ' . $status($b));
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
/* mp4: the picture, the id beside it and its wallet word now come from ONE resolver (PayQr::resolve)
   that the ticket (Ticket::esewaQrFile / paymentQr) and ManualQrAdapter read too - pay.php reads it
   through ManualQrAdapter::nepalQr($booking, …) and draws that file (ManualQrAdapter::qrSrc). */
$paySrc = (string) file_get_contents($ROOT . '/pay.php');
check('B pay.php reads the same QR as the ticket (ManualQrAdapter::nepalQr -> PayQr::resolve, its picture via qrSrc)',
    str_contains($paySrc, 'ManualQrAdapter::nepalQr($booking') && str_contains($paySrc, "ManualQrAdapter::qrSrc(\$npQ['file'])")
    && ManualQrAdapter::esewaQrSrc() === ManualQrAdapter::qrSrc(Ticket::esewaQrFile()) && ManualQrAdapter::esewaQrSrc() !== '');

/* ---------------- E. Gmail poll end to end ---------------- */
echo "\n-- E Gmail credits -> PayCredits\n";
setKeep('bankmail_on', '1', 'bool');
unsetKeep('pay_bankmail_autoconfirm_on');   // not stored = the code default (OFF); switched ON further down, restored at the end
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
$debitRaw = $mail(['plain' => "Your A/C 0123456789012 has been debited by NPR 999.00. Txn ID: " . $T('D1'), 'subject' => 'Debit Alert']);
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

/* ---- owner-safety (25 Nov 2026): Gmail auto-PAID is OFF by default - a perfect alert is only a SUGGESTION ---- */
check('E pay_bankmail_autoconfirm_on not stored -> Gmail auto-PAID is OFF (the code default)',
    Settings::get('pay_bankmail_autoconfirm_on', null) === null && PayCredits::gmailAutoOn() === false);
$sug = $mk();
$sugRaw = $mail(['plain' => $credit((string) $sug['pnr'], $npr($sug), $T('SG')), 'mid' => 'mp1sug' . $tag]);
setKeep('bankmail_last_uid', '4998', 'int');
$resS = BankMail::poll(50, $imapFor([4999 => $sugRaw]));
setKeep('bankmail_last_uid', '5000', 'int');
$sc = Database::fetch('SELECT * FROM bank_credits WHERE source = \'gmail\' AND txn_id = :t', ['t' => $T('SG')]);
if ($sc !== null) { $credIds[] = (int) $sc['id']; }
check('E default OFF: a perfect alert (DKIM, sender, our account, one PNR, exact amount) -> poll counts it "suggested", not confirmed, not unmatched',
    $resS['ok'] && $resS['credits'] === 1 && $resS['suggested'] === 1 && $resS['confirmed'] === 0 && $resS['unmatched'] === 0 && str_contains((string) $resS['msg'], '1 suggested'), json_encode($resS));
check('E default OFF: the credit ends \'matched\' as a SUGGESTION: booking_id = that booking, acted_at empty, note "सुझाव · Suggested: <PNR> NPR <amount>"',
    $sc !== null && $sc['state'] === 'matched' && (int) $sc['booking_id'] === (int) $sug['id'] && $sc['acted_at'] === null
    && $sc['note'] === PayCredits::SUGGEST_NOTE . $sug['pnr'] . ' NPR ' . $npr($sug) && PayCredits::isSuggestion($sc), json_encode($sc, JSON_UNESCAPED_UNICODE));
check('E default OFF: the booking stays pending - no proof recorded, no chalani tick, no booking.confirm / payment.auto_confirmed',
    $status($sug) === 'pending' && !Database::exists('SELECT 1 FROM payments WHERE booking_id = :b AND utr_number = :u', ['b' => (int) $sug['id'], 'u' => 'GIME-' . $T('SG')])
    && $tick($sug) === null && $count('booking.confirm', $sug) === 0 && $count('payment.auto_confirmed', $sug) === 0, $status($sug));
$sugBell = 'बैंक इमेल: रू ' . $npr($sug) . ' आयो · PNR ' . $sug['pnr'] . ' — Payment Management मा Confirm थिच्नुहोस्';
check('E default OFF: the admin bell "बैंक इमेल: रू X आयो · PNR Y — Payment Management मा Confirm थिच्नुहोस्" (on the booking, opens the Nepal tab); no "needs a person" bell',
    (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE booking_id = :b AND title = :t AND link = '/admin/payment-center.php?tab=np'", ['b' => (int) $sug['id'], 't' => $sugBell], 0) === 1
    && (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE booking_id = :b AND title LIKE 'Bank credit needs a person%'", ['b' => (int) $sug['id']], 0) === 0,
    json_encode(Database::fetchAll('SELECT title, link FROM notifications WHERE booking_id = :b', ['b' => (int) $sug['id']]), JSON_UNESCAPED_UNICODE));
$again = $sc !== null ? PayCredits::tryAutoConfirm((int) $sc['id']) : ['ok' => true, 'why' => ''];
$rp = PayCredits::record(['source' => 'gmail', 'currency' => 'NPR', 'amount' => 1, 'txn_id' => 'X', 'message_id' => 'mp1sug' . $tag . '@gibl.test']);
check('E a suggestion is left alone: tryAutoConfirm again = no-op, the same e-mail again = replay; open / suggested lists + counts show it',
    !$again['ok'] && $again['why'] === 'already matched' && PayCredits::lastOutcome() === 'replay' && $rp === (int) ($sc['id'] ?? -1)
    && in_array((int) ($sc['id'] ?? -1), array_map('intval', array_column(PayCredits::listRecent(['source' => 'gmail', 'state' => 'open', 'limit' => 200]), 'id')), true)
    && in_array((int) ($sc['id'] ?? -1), array_map('intval', array_column(PayCredits::listRecent(['source' => 'gmail', 'state' => 'suggested', 'limit' => 200]), 'id')), true)
    && (PayCredits::counts('gmail')['suggested'] ?? 0) >= 1 && $status($sug) === 'pending', json_encode($again));
/* Confirm = link(): the person checked the bank app; approveReviewed via 'queue' (PaymentRisk + the person in the loop) */
$sys0 = PayAuto::systemAdmin() ?? ['id' => 0];
$sysRow = Database::fetch('SELECT full_name, username FROM admins WHERE id = :i', ['i' => (int) $sys0['id']]) ?? [];
$sysName = trim((string) (($sysRow['full_name'] ?? '') ?: ($sysRow['username'] ?? '')));
$cr = $sc !== null ? PayCredits::link((int) $sc['id'], (string) $sug['pnr'], (int) $sys0['id']) : ['ok' => false, 'msg' => 'no credit'];
$scRow = $sc !== null ? (PayCredits::get((int) $sc['id']) ?? []) : [];
$tk = $tick($sug);
check('E Confirm (link()) on the suggestion: booking confirmed through the queue gate, credit linked + acted_by, payment verified GIME-…, no auto audit',
    $cr['ok'] && $status($sug) === 'confirmed' && ($scRow['state'] ?? '') === 'linked' && (int) ($scRow['acted_by'] ?? 0) === (int) $sys0['id'] && !empty($scRow['acted_at'])
    && str_starts_with((string) ($scRow['note'] ?? ''), 'Confirmed ' . $sug['pnr'] . ' (Gmail suggestion) by ')
    && Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND utr_number = :u AND status = 'verified'", ['b' => (int) $sug['id'], 'u' => 'GIME-' . $T('SG')])
    && $count('booking.confirm', $sug) === 1 && $count('payment.auto_confirmed', $sug) === 0, json_encode([$cr, $scRow['state'] ?? null, $scRow['note'] ?? null], JSON_UNESCAPED_UNICODE));
check('E chalani tick "PAID - Nepali online (Gmail)" (OpsHub keeps 40 chars), its note "UTR … · confirmed by <name>"; audited bank_credit.link',
    ($tk['mode'] ?? '') === 'PAID - Nepali online (Gmail)' && mb_strlen(PayCredits::LABEL_CONFIRMED['gmail']) <= 40
    && str_contains((string) ($tk['note'] ?? ''), $T('SG')) && $sysName !== '' && str_contains((string) ($tk['note'] ?? ''), 'confirmed by ' . $sysName)
    && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'bank_credit.link' AND entity_id = :i", ['i' => (string) ($sc['id'] ?? '')], 0) === 1, json_encode($tk));
$threw = ''; $threw2 = '';
try { PayCredits::link((int) ($sc['id'] ?? 0), (string) $sug['pnr'], (int) $sys0['id']); } catch (Throwable $ex) { $threw = $ex->getMessage(); }
try { PayCredits::ignore((int) ($sc['id'] ?? 0), (int) $sys0['id'], 'x'); } catch (Throwable $ex) { $threw2 = $ex->getMessage(); }
check('E a confirmed suggestion can be neither confirmed again nor ignored', str_contains($threw, 'already paid') && str_contains($threw2, 'already paid') && $count('booking.confirm', $sug) === 1, $threw . ' | ' . $threw2);

/* ---- the switch ON (set here, restored at the end): the old auto path, unchanged, for the genuine fixtures ---- */
setKeep('pay_bankmail_autoconfirm_on', '1', 'bool');
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

/* fail-closed end to end, switch ON: an otherwise perfect alert whose topmost Authentication-Results is not simple
   (Gmail's real header + a nested comment) is held with the reason, never confirmed */
$ns = $mk();
$resNs = ['seen' => 0, 'credits' => 0, 'new' => 0, 'duplicates' => 0, 'debits' => 0, 'other' => 0, 'confirmed' => 0, 'suggested' => 0, 'unmatched' => 0, 'failed' => 0];
BankMail::handle($mail(['plain' => $credit((string) $ns['pnr'], $npr($ns), $T('NS')), 'mid' => 'mp1ns' . $tag, 'auth' => $notSimple['nested comment']]), $resNs);
$nsRow = Database::fetch('SELECT * FROM bank_credits WHERE txn_id = :t', ['t' => $T('NS')]);
if ($nsRow !== null) { $credIds[] = (int) $nsRow['id']; }
check('E fail-closed end to end (switch ON): a perfect alert with a nested comment in Authentication-Results -> unmatched "No DKIM pass: auth header not simple …", booking pending',
    PayCredits::gmailAutoOn() && $resNs['unmatched'] === 1 && $resNs['confirmed'] === 0 && $nsRow !== null && $nsRow['state'] === 'unmatched' && (int) $nsRow['dkim_ok'] === 0
    && str_starts_with((string) $nsRow['note'], 'No DKIM pass: auth header not simple') && $status($ns) === 'pending', json_encode([$resNs, $nsRow['note'] ?? null]));

$wrongFrom = $mk();
/* the bank's own signed marketing address (From aligned with DKIM), just not an allowed sender. (Was
   alerts@gibl.test.evil.example "signed" by gibl.test: a From outside the signer is no DKIM pass any more) */
$idWf = $fromParse($mail(['plain' => $credit((string) $wrongFrom['pnr'], $npr($wrongFrom), $T('W1')), 'from' => 'Bank <offers@gibl.test>',
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
check('E switch pay_bankmail_autoconfirm_on stored "0" -> a suggestion (matched, booking pending) that waits for a person',
    !$r['ok'] && $r['state'] === 'matched' && $r['why'] === 'suggested' && $r['bookingId'] === (int) $off['id'] && PayCredits::isSuggestion(PayCredits::get($idOff)) && $status($off) === 'pending', json_encode($r));
PayCredits::ignore($idOff, (int) (PayAuto::systemAdmin()['id'] ?? 0), 'not in the bank app');
check('E Ignore sets a suggestion aside (the bank app does not show it): ignored, booking pending',
    (PayCredits::get($idOff)['state'] ?? '') === 'ignored' && $status($off) === 'pending' && $count('booking.confirm', $off) === 0);
setKeep('pay_bankmail_autoconfirm_on', '1', 'bool');

/* every probe fixture end to end through poll(), against ONE real pending booking: the PAYER types the booking's own
   PNR and its exact NPR due ("credited by NPR <due>") - the bank's figure is NPR 10.00 - or the bank's figure IS the due
   and the payer types a second "ref …" id. Whatever the layout, nothing confirms; every alert is stored (never
   dropped) and unmatched with its reason + a bell. */
$vic = $mk();
$VP = (string) $vic['pnr'];
$VD = $npr($vic);
$uidV = 5004;   // UIDs 5005-5021: after the polls above, before the 5101+ ones below
$probeMails = [
    'P1'  => ['plain' => "Your A/C ****9012 has been credited.\nAmount: 10.00\nRemarks:\n$VP credited by NPR $VD\nTxn ID: " . $T('V1')],
    'P1b' => ['html' => "<table><tr><td>A/C</td><td>****9012</td></tr><tr><td>Amount (NPR)</td><td>10.00</td></tr><tr><td>Status</td><td>credited</td></tr><tr><td>Remarks<br>$VP credited by NPR $VD</td></tr><tr><td>Txn ID</td><td>" . $T('V2') . "</td></tr></table>"],
    'P1c' => ['html' => "<table><tr><td>NPR 10.00 has been credited to A/C ****9012</td></tr><tr><td>Remarks</td></tr><tr><td>$VP ref " . $T('V3R') . " credited by NPR $VD</td></tr><tr><td>Txn ID: " . $T('V3') . "</td></tr></table>"],
    'P2'  => ['plain' => "तपाईंको खाता ****9012 मा रु $VD जम्मा भयो (credited).\nकैफियत: $VP ref " . $T('V4R') . "\nTxn ID: " . $T('V4')],
    'P2b' => ['plain' => "तपाईंको खाता ****9012 मा जम्मा भयो (credited).\nरकम: 10.00\nकैफियत: $VP credited by NPR $VD\nTxn ID: " . $T('V5')],
    'P3'  => ['plain' => "Your A/C ****9012 has been credited.\nAmount: 10.00\nNote: $VP credited by NPR $VD\nTxn ID: " . $T('V6')],
    'P6'  => ['html' => "<table><tr><th>Date</th><th>Details</th><th>Credit</th></tr><tr><td>25-11-2026</td><td>$VP credited by NPR $VD bal</td><td>NPR 10.00</td></tr><tr><td>A/C ****9012 credited</td></tr><tr><td>Txn ID: " . $T('V7') . "</td></tr></table>"],
    'P7'  => ['plain' => "Your A/C ****9012 has been credited with 10.00 NPR.\nSender note: $VP credited by NPR $VD\nTxn ID: " . $T('V8')],
    'Q1'  => ['plain' => "Your A/C 0123456789012 has been credited by NPR $VD.\nRemarks:\n$VP ref " . $T('V9R') . "\nTxn ID: " . $T('V9')],
    'Q2'  => ['html' => "<table><tr><td>NPR $VD has been credited to A/C ****9012</td></tr><tr><td>Remarks:</td></tr><tr><td>$VP ref " . $T('VAR') . "</td></tr><tr><td>Txn ID: " . $T('VA') . "</td></tr></table>"],
    'Q3'  => ['html' => "<p>Your A/C ****9012 has been credited.</p><p>Amount: 10.00</p><p>Remarks:<br>$VP credited by NPR $VD</p><p>Txn ID: " . $T('VB') . "</p>"],
    'Q4'  => ['html' => "<table><tr><td>NPR $VD has been credited to A/C ****9012</td></tr><tr><td>Remarks: $VP</td></tr><tr><td>Txn ID: " . $T('VC') . "</td></tr><tr><td>Available Balance</td></tr><tr><td>NPR 55,000.00</td></tr></table>"],
    'MIX' => ['plain' => "Your A/C 0123456789012 has been credited by NPR $VD (the amount debited on 24-11-2026, returned).\nRemarks: $VP\nTxn ID: " . $T('VM')],
];
$vMsgs = [];
foreach ($probeMails as $k => $fx) {
    $vMsgs[++$uidV] = $mail($fx + ['mid' => 'mp1v' . strtolower($k) . $tag, 'subject' => 'Credit Alert']);
}
$resV = BankMail::poll(50, $imapFor($vMsgs));
$vRows = [];
foreach (array_keys($probeMails) as $k) {
    $vRows[$k] = Database::fetch('SELECT * FROM bank_credits WHERE message_id = :m', ['m' => 'mp1v' . strtolower($k) . $tag . '@gibl.test']);
    if ($vRows[$k] !== null) { $credIds[] = (int) $vRows[$k]['id']; }
}
$vBad = array_keys(array_filter($vRows, static fn(?array $r): bool => $r === null || $r['state'] !== 'unmatched' || (string) $r['note'] === '' || (string) $r['note'] === 'checking…'));
check('E every probe layout end to end (payer types the PNR + the exact NPR due, or a second ref id): booking stays pending, all ' . count($probeMails) . ' stored unmatched (none dropped, none confirmed)',
    $resV['ok'] && $resV['seen'] === count($probeMails) && $resV['credits'] === count($probeMails) && $resV['confirmed'] === 0 && $resV['unmatched'] === count($probeMails)
    && $resV['debits'] === 0 && $vBad === [] && $status($vic) === 'pending' && $count('booking.confirm', $vic) === 0,
    json_encode(['res' => $resV, 'bad' => $vBad, 'status' => $status($vic)]));
check('E each held probe carries its reason: two amounts (P1), two txn ids (Q1), credit + debit words (MIX), balance value row (Q4) - and a bell',
    str_starts_with((string) ($vRows['P1']['note'] ?? ''), 'Different amounts') && str_starts_with((string) ($vRows['Q1']['note'] ?? ''), 'Different transaction')
    && str_contains((string) ($vRows['MIX']['note'] ?? ''), 'credit and debit') && str_starts_with((string) ($vRows['Q4']['note'] ?? ''), 'Different amounts')
    && $vRows['Q1'] !== null && $vRows['Q1']['txn_id'] === null && abs((float) ($vRows['MIX']['amount'] ?? 0) - (float) str_replace(',', '', $VD)) < 0.01
    && (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE id > :f AND title LIKE 'Bank credit needs a person%' AND body LIKE '%check the bank statement%'", ['f' => $bellFloor], 0) >= count($probeMails),
    json_encode(array_map(static fn(?array $r): string => (string) ($r['note'] ?? 'MISSING'), $vRows), JSON_UNESCAPED_UNICODE));
setKeep('bankmail_amount_regex', '/Credited NPR ([0-9.,]+)/i', 'string');
$p12 = $mail(['plain' => "Your A/C ****9012 has been credited.\nAmount: 10.00\nRemarks:\nCredited NPR $VD\nTxn ID: " . $T('VD'), 'mid' => 'mp1vp12' . $tag, 'subject' => 'Credit Alert']);
$resP12 = BankMail::poll(50, $imapFor([++$uidV => $p12]));
setKeep('bankmail_amount_regex', '', 'string');
$r12 = Database::fetch('SELECT * FROM bank_credits WHERE message_id = :m', ['m' => 'mp1vp12' . $tag . '@gibl.test']);
if ($r12 !== null) { $credIds[] = (int) $r12['id']; }
check('E probe P12 end to end: the owner\'s amount regex matches the payer\'s "Credited NPR <due>" - still two figures -> unmatched, booking pending',
    $resP12['confirmed'] === 0 && $resP12['unmatched'] === 1 && $r12 !== null && $r12['state'] === 'unmatched' && (float) $r12['amount'] === 0.0 && $status($vic) === 'pending', json_encode([$resP12, $r12['note'] ?? null]));

/* the genuine single-figure alerts still auto-confirm: plain with an "Avl Bal: NPR 50,000" line, Devanagari HTML
   (रु + Devanagari digits, मौज्दात row), the one-line alert (its Txn ID after "Remarks:" on the same line) */
$dev = static fn(string $s): string => strtr($s, ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४', '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९']);
$g1 = $mk(); $g2 = $mk(); $g3 = $mk();
$gMsgs = [
    ++$uidV => $mail(['plain' => "Dear Customer,\nYour A/C 0123456789012 has been credited by NPR " . $npr($g1) . " on 25-11-2026.\nRemarks: " . $g1['pnr'] . "\nTxn ID: " . $T('G1') . "\nAvl Bal: NPR 50,000.00", 'mid' => 'mp1g1' . $tag]),
    ++$uidV => $mail(['html' => '<html><body><table><tr><td>खाता नं.</td><td>0123456789012</td></tr><tr><td>रकम</td><td>रु ' . $dev($npr($g2)) . '</td></tr><tr><td>जम्मा भयो · credited</td></tr>'
        . '<tr><td>कैफियत</td><td>' . $g2['pnr'] . '</td></tr><tr><td>Reference No: ' . $T('G2') . '</td></tr><tr><td>मौज्दात</td><td>रु ५०,०००.००</td></tr></table></body></html>', 'mid' => 'mp1g2' . $tag]),
    ++$uidV => $mail(['plain' => 'NPR ' . $npr($g3) . ' credited to A/C 0123456789012 Remarks: ' . $g3['pnr'] . ' Txn ID: ' . $T('G3'), 'mid' => 'mp1g3' . $tag]),
];
$resG = BankMail::poll(50, $imapFor($gMsgs));
foreach (['G1', 'G2', 'G3'] as $k) {
    if (($x = (int) Database::scalar('SELECT id FROM bank_credits WHERE txn_id = :t', ['t' => $T($k)], 0)) > 0) { $credIds[] = $x; }
}
check('E genuine single-figure alerts still auto-confirm: plain + "Avl Bal: NPR 50,000" line, Devanagari HTML (रकम / रु digits / मौज्दात row), one-line alert',
    $resG['ok'] && $resG['confirmed'] === 3 && $resG['unmatched'] === 0 && $status($g1) === 'confirmed' && $status($g2) === 'confirmed' && $status($g3) === 'confirmed',
    json_encode(['res' => $resG, 'st' => [$status($g1), $status($g2), $status($g3)],
        'notes' => Database::fetchAll("SELECT txn_id, state, note FROM bank_credits WHERE message_id IN ('mp1g1$tag@gibl.test','mp1g2$tag@gibl.test','mp1g3$tag@gibl.test')")], JSON_UNESCAPED_UNICODE));
$threw = '';
try { PayCredits::link((int) ($vRows['Q1']['id'] ?? 0), $VP, (int) (PayAuto::systemAdmin()['id'] ?? 0)); } catch (Throwable $ex) { $threw = $ex->getMessage(); }
$lm = PayCredits::hold((int) ($vRows['P1']['id'] ?? 0), 'again');
check('E a held credit with no txn id cannot be linked from the queue; hold() leaves a non-new credit alone',
    str_contains($threw, 'no own transaction id') && !$lm['ok'] && $lm['why'] === 'already unmatched' && $status($vic) === 'pending', $threw . ' ' . json_encode($lm));

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
check('E listRecent state=open shows only open credits (new, unmatched, suggestions)', array_filter(PayCredits::listRecent(['source' => 'gmail', 'state' => 'open', 'limit' => 200]),
    static fn(array $c): bool => !in_array($c['state'], ['new', 'unmatched'], true) && !PayCredits::isSuggestion($c)) === []);

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
$page = static function (int $admin, array $get = [], bool $nav = false, ?array $post = null, bool $nocsrf = false) use ($ini): string {
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' --page '
        . escapeshellarg(base64_encode((string) json_encode(['admin' => $admin, 'get' => $get, 'nav' => $nav, 'post' => $post, 'nocsrf' => $nocsrf]))) . ' 2>&1';
    return (string) shell_exec($cmd);
};
$SUP = $mkAdmin('superadmin');
$MGR = $mkAdmin('manager');
$warn = static fn(string $h): bool => preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)[: ]/', $h) === 1;
/* a fresh Gmail suggestion for the page, with the switch back at its code default (not stored = OFF) */
unsetKeep('pay_bankmail_autoconfirm_on');
setKeep('bankmail_on', '1', 'bool');
$pc = $mk();
$resPc = BankMail::poll(50, $imapFor([5301 => $mail(['plain' => $credit((string) $pc['pnr'], $npr($pc), $T('PC')), 'mid' => 'mp1pc' . $tag])]));
$pcRow = Database::fetch('SELECT * FROM bank_credits WHERE source = \'gmail\' AND txn_id = :t', ['t' => $T('PC')]);
if ($pcRow !== null) { $credIds[] = (int) $pcRow['id']; }
$pcId = (int) ($pcRow['id'] ?? 0);
check('H a fresh suggestion for the page (switch not stored = OFF): matched, booking pending', $resPc['suggested'] === 1 && PayCredits::isSuggestion($pcRow) && $status($pc) === 'pending', json_encode($resPc));
$h = $page($SUP, ['tab' => 'np']);
/* 25 Nov 2026 (mp2 "bank Gmail"): the old single Gmail form (bankmail_user / bankmail_app_password / np_test) is
   REPLACED by the "📧 बैंक Gmail खाताहरू" card (Google login / app password per box, tests/mp2-bankgmail-test.php) */
check('H Nepal tab renders (QR card, eSewa id, bank Gmail accounts card, credits), no PHP warning',
    !$warn($h) && str_contains($h, 'नेपाली QR') && str_contains($h, 'name="nepal_esewa_id"') && str_contains($h, 'बैंक Gmail खाताहरू · Bank Gmail accounts')
    && str_contains($h, 'value="acct_add_pw"') && str_contains($h, 'Credits (Gmail)') && !str_contains($h, 'name="bankmail_app_password"')
    && !str_contains($h, 'whsec_mp1_local_test'), substr((string) preg_replace('/\s+/', ' ', strip_tags($h)), 0, 300));
check('H Nepal tab: the Nepali note + the auto switch shown read-only (○ बन्द · OFF), no toggle for it on this page',
    str_contains($h, 'Gmail बाट आएको पैसा अहिले सुझावको रूपमा आउँछ; बैंक app मा हेरेर Confirm थिच्नुहोस्। Global IME को साँचो alert email को नमुना मिलाएपछि Auto-PAID खोल्न सकिन्छ।')
    && str_contains($h, 'id="pc-auto-state"') && str_contains($h, '○ बन्द · OFF') && !str_contains($h, 'name="pay_bankmail_autoconfirm_on"'));
$pcAt = strpos($h, 'name="id" value="' . $pcId . '"');
$pcBlock = $pcAt === false ? '' : (string) substr($h, max(0, $pcAt - 2500), 5000);
check('H the suggestion row: PNR, passenger, due vs credited (NPR), ✓ DKIM / ✓ A/C …9012 / ✓ Sender / ✓ amount chips, the big "✅ Confirm" (CSRF) with the bank-app tick',
    $pcId > 0 && str_contains($h, '💡 सुझाव · Suggested') && str_contains($pcBlock, (string) $pc['pnr']) && str_contains($pcBlock, 'ZZTEST mp1pay')
    && substr_count($pcBlock, 'NPR ' . $npr($pc)) >= 2 && str_contains($pcBlock, '✓ DKIM') && str_contains($pcBlock, '✓ A/C …9012') && str_contains($pcBlock, '✓ Sender')
    && str_contains($pcBlock, '✓ रकम मिल्यो') && str_contains($pcBlock, '✅ Confirm · पुष्टि गर्नुहोस्') && str_contains($pcBlock, 'name="act" value="confirm"')
    && str_contains($pcBlock, 'name="bank_checked" value="1" required') && str_contains($pcBlock, 'name="' . CSRF_TOKEN_NAME . '"'),
    substr((string) preg_replace('/\s+/', ' ', strip_tags($pcBlock)), 0, 600));
$cPost = ['act' => 'confirm', 'tab' => 'np', 'id' => (string) $pcId, 'bank_checked' => '1'];
$h = $page($MGR, ['tab' => 'np'], false, $cPost);
check('H a manager\'s Confirm post is refused (super-admin only), booking pending', $status($pc) === 'pending' && !str_contains($h, 'confirmed — ticket sent'));
$h = $page($SUP, ['tab' => 'np'], false, $cPost, true);
check('H Confirm with a forged CSRF token -> "Session expired", booking pending', str_contains($h, 'Session expired') && $status($pc) === 'pending');
$h = $page($SUP, ['tab' => 'np'], false, ['bank_checked' => ''] + $cPost);
check('H Confirm without the "I saw it in the bank app" tick -> refused with the reason, booking pending',
    str_contains($h, 'पहिले बैंक app मा पैसा आएको हेर्नुहोस्') && $status($pc) === 'pending' && PayCredits::isSuggestion(PayCredits::get($pcId)));
$h = $page($SUP, ['tab' => 'np'], false, $cPost);
$pcTk = $tick($pc);
$pcAfter = PayCredits::get($pcId) ?? [];
check('H Confirm (super-admin, CSRF, bank-app tick) -> "✅ … confirmed — ticket sent", booking confirmed, credit linked by that person, chalani "PAID - Nepali online (Gmail)" confirmed by MP1 superadmin',
    !$warn($h) && str_contains($h, '✅ ' . $pc['pnr'] . ' confirmed — ticket sent.') && $status($pc) === 'confirmed' && ($pcAfter['state'] ?? '') === 'linked'
    && (int) ($pcAfter['acted_by'] ?? 0) === $SUP && ($pcTk['mode'] ?? '') === 'PAID - Nepali online (Gmail)' && str_contains((string) ($pcTk['note'] ?? ''), 'confirmed by MP1 superadmin'),
    json_encode([$status($pc), $pcAfter['state'] ?? null, $pcAfter['note'] ?? null, $pcTk], JSON_UNESCAPED_UNICODE) . ' ' . substr((string) preg_replace('/\s+/', ' ', strip_tags($h)), 0, 300));
$h = $page($SUP, ['tab' => 'np'], false, $cPost);
check('H Confirm again -> "no longer a suggestion", still one booking.confirm', str_contains($h, 'no longer a suggestion') && $count('booking.confirm', $pc) === 1);
$h = $page($SUP, ['tab' => 'in']);
check('H India tab renders with the webhook address and never prints a secret', !$warn($h) && str_contains($h, 'payment/bank-webhook.php') && str_contains($h, 'Field map') && !str_contains($h, 'whsec_mp1_local_test'));
$h = $page($SUP, ['tab' => 'cash', 'day' => $TD]);
check('H Cash tab renders (per counter + per staff, links to the books)', !$warn($h) && str_contains($h, 'Per counter / desk') && str_contains($h, 'Per staff') && str_contains($h, 'cash-book.php'));
$h = $page($MGR, ['tab' => 'np']);
check('H a manager is refused (super-admin only)', !str_contains($h, 'बैंक Gmail खाताहरू') && !str_contains($h, 'value="acct_add_pw"') && (str_contains($h, 'super-admin only') || str_contains($h, '<!--HTTP:403-->')), substr(strip_tags($h), 0, 200));
$navS = json_decode(trim(substr($p1 = $page($SUP, [], true), (int) strrpos($p1, '['))), true) ?: [];
$navM = json_decode(trim(substr($p2 = $page($MGR, [], true), (int) strrpos($p2, '['))), true) ?: [];
check('H the menu line shows for the super-admin only', in_array('payment-center.php', $navS, true) && !in_array('payment-center.php', $navM, true), json_encode([count($navS), count($navM)]));

echo "\n" . ($fail === 0 ? "  $pass passed, 0 failed\n" : "  $pass passed, $fail failed\n");
exit($fail === 0 ? 0 : 1);
