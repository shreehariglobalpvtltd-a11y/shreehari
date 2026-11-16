<?php
/**
 * 16 Nov 2026 — Master v2.0 unit F1 (core fixes).
 *
 *   F1-1  SECURITY: the website / staff AI door (POST /api/ai-chat.php ->
 *         AiTools::whoIsWeb) never looked at must_change_pw, and the endpoint
 *         never calls Auth::requireAdmin() (it serves guests too). A superadmin
 *         still on a temporary password got the office tools and the office
 *         summaries of the local brain (WaLocal::answerWeb). Now the admins row
 *         is read on every message, as whoIs() does on WhatsApp: a temporary
 *         password, a suspension, a lock or a role taken away - also one issued
 *         while the person is signed in - reaches nothing of the office.
 *   F1-2  includes/bootstrap.php: under the CLI an uncaught exception printed the
 *         error page and exited with status 0, so tests/run-all.php (which
 *         judges a suite by its exit code) counted a crashed suite as ok. Now
 *         the CLI exit status is 1; what prints is unchanged.
 *   F1-4  HelpDesk::open() stored the first line of EVERY ticket as the
 *         customer's words, so an office / system-opened ticket (the desk form,
 *         the WhatsApp inbox, the cancel refund follow-up) showed the office's
 *         note as the customer's on the inbox and on help.php. Now a trusted
 *         (staff / system) ticket's first line is an internal office note under
 *         the staff member's name (else "सिस्टम · System").
 *   (F1-3, the payout refusal wording, is in tests/agent-advance-test.php.)
 *
 *   Review round (16 Nov 2026):
 *   F1-1b SECURITY: F1-1 closed /api/ai-chat.php only. Every other endpoint in
 *         /api/ (api/book.php counter mode, api/quick-ticket.php, ...) never
 *         calls Auth::requireAdmin() either, so a session on a temporary
 *         password could still SELL. includes/bootstrap.php now refuses every
 *         /api/ endpoint (but csrf / lang / wa-verify) to such a session -
 *         also a "must change" switched on mid-session, also a percent-encoded
 *         path - and lifts the moment the password is changed. Read-only
 *         probes: the sale is never reached (an empty payload either way).
 *   F1-4b HelpDesk::open() keyed the office voice on 'trusted' alone, so the
 *         ticket WaPayProof writes FOR the office ("the passenger typed a
 *         payment reference ... Please check the bank") was stored as the
 *         passenger's own words. open() now takes system_note (the internal
 *         System line, and none of trusted's powers); WaPayProof must pass it.
 *
 * Seeds two temp superadmins (u11_f1_*), four help-desk tickets on 91000119xx
 * numbers and one bare cancelled booking, and removes every row it made.
 *
 *   SHG_TEST_BASE=http://localhost:9011 php -c <ini> tests/u11-F1-test.php
 *   (no SHG_TEST_BASE: the suite serves THIS checkout on a free port itself)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/helpdesk.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/quickunlock.php';

if (!function_exists('curl_init')) { echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n"; exit(1); }

/* Same helper as tests/manifest-test.php: SHG_TEST_BASE wins; without it the
   suite serves this checkout with this run's php + ini and stops it on exit. */
function shg_test_base(): string
{
    $env = trim((string) getenv('SHG_TEST_BASE'));
    if ($env !== '') {
        return rtrim($env, '/');
    }
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        return 'http://localhost:8899';
    }
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    $ini  = (string) php_ini_loaded_file();
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $cmd  = array_merge([PHP_BINARY], $ini !== '' ? ['-c', $ini] : [], ['-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)]);
    $proc = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return 'http://localhost:8899';
    }
    register_shutdown_function(static function () use ($proc): void {
        @proc_terminate($proc);
        @proc_close($proc);
    });
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($c !== false) { fclose($c); break; }
        usleep(100000);
    }
    return 'http://127.0.0.1:' . $port;
}
define('BASE', shg_test_base());

const BOSS     = 'u11_f1_boss';
const TMP      = 'u11_f1_tmp';
const PW       = 'U11F1Core77x';
const PW_NEW   = 'U11F1Fresh88y';  // what the temporary-password holder changes it to (F1-1b)
const PH_CUST  = '9100011901';     // opened the ticket themself (help.php)
const PH_DESK  = '9100011902';     // the desk typed it in for them
const PH_SYS   = '9100011903';     // the system opened it (cancel refund follow-up)
const PH_PAY   = '9100011904';     // the system wrote it without staff powers (WaPayProof, system_note)
const NOTE_DESK = 'U11F1 desk note: caller says the AC is broken, office to call back';
const NOTE_CUST = 'U11F1 my own words: the bus was late at Surat';
const SYS_NAME  = 'सिस्टम · System';
const NOTE_PAY  = 'WhatsApp: the passenger typed a payment reference for U11F1 and confirmed it — UTR 412345678904. Please check the bank and confirm in Verify Payments. Text: utr 412345678904';
const TEMP_MSG  = 'Change your temporary password first';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/* ---- HTTP (one cookie jar per person) --------------------------------- */
function http(string $jar, string $url, array $opt = []): array {
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'headers' => substr($raw, 0, $hlen)];
}
function csrfFrom(string $h): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? $m[1] : '';
}
function clearLimits(): void {
    try {
        Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN
            ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try', 'ai_chat_web', 'ai_chat_web_day', 'helpdesk_look')");
    } catch (Throwable $e) {}
}
function signIn(string $jar, string $user): array {
    @unlink($jar);
    clearLimits();
    $login = http($jar, '/admin/login.php?classic=1&other=1');
    return http($jar, '/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($login['body']), 'classic' => '1', 'username' => $user, 'password' => PW]]);
}
/** POST /api/ai-chat.php as the browser in $jar does. */
function aiChat(string $jar, string $csrf, string $message): array {
    $r = http($jar, '/api/ai-chat.php', ['form' => [CSRF_TOKEN_NAME => $csrf, 'message' => $message, 'lang' => 'en']]);
    $j = json_decode($r['body'], true);
    return ['code' => $r['code'], 'json' => is_array($j) ? $j : null, 'raw' => $r['body']];
}
/** POST /api/quick-ticket.php action=plan (read-only: plans a sale, holds and writes nothing). */
function qtPlan(string $jar, string $csrf): array {
    return http($jar, '/api/quick-ticket.php', ['form' => [CSRF_TOKEN_NAME => $csrf, 'action' => 'plan']]);
}
/** The /api/ gate's answer to a session on a temporary password (includes/bootstrap.php 6b). */
function isTempRefusal(array $r): bool {
    $j = json_decode((string) ($r['body'] ?? ''), true);
    return $r['code'] === 403 && is_array($j) && ($j['ok'] ?? null) === false && str_contains((string) ($r['body'] ?? ''), TEMP_MSG);
}
/** The office's own answer: the role it was given and the local-brain intent, or none. */
function officeAnswer(array $r): bool {
    $d = (array) ($r['json']['data'] ?? []);
    return !empty($r['json']['ok']) && ((string) ($d['role'] ?? '') === 'admin' || (string) ($d['local'] ?? '') === 'summary');
}

/* ---- switches this suite drives; restored exactly as found ------------- */
$PINNED = ['ai_web_agent_on', 'wa_local_brain_on', 'wa_report_chart_on', 'helpdesk_on', 'helpdesk_ack',
           'helpdesk_office_alert', 'helpdesk_autoassign', 'helpdesk_cancel_escalate_inr'];
$prior = [];
foreach ($PINNED as $k) {
    $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$restoreSettings = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        try {
            if ($row === null) {
                Database::delete('settings', 'skey = :k', ['k' => $k]);
            } else {
                Database::update('settings', ['svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'],
                    'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public']], 'skey = :k', ['k' => $k]);
            }
        } catch (Throwable $e) {}
    }
    Settings::flush();
};

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shg_u11f1_' . getmypid();
$jars   = [];
$pnr    = '';
$cleanup = static function () use (&$pnr, &$jars, $tmpDir): void {
    foreach (Database::fetchAll("SELECT id, ticket_ref FROM support_tickets WHERE phone LIKE '%91000119%'") as $t) {
        try { Database::delete('audit_logs', "entity_type = 'support_ticket' AND entity_id = :r", ['r' => (string) $t['ticket_ref']]); } catch (Throwable $e) {}
        try { Database::delete('support_tickets', 'id = :i', ['i' => (int) $t['id']]); } catch (Throwable $e) {}   // support_messages cascade
    }
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '91000119%'") as $b) {
        try { Database::delete('audit_logs', "action = 'support.cancel_followup' AND entity_id = :p", ['p' => (string) $b['pnr']]); } catch (Throwable $e) {}
        try { Database::delete('bookings', 'id = :i', ['i' => (int) $b['id']]); } catch (Throwable $e) {}
    }
    foreach ([BOSS, TMP] as $u) {
        $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
        if ($id > 0) {
            try { Database::delete('admin_sessions', 'admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            try { Database::delete('audit_logs', "entity_type = 'admin' AND entity_id = :a", ['a' => (string) $id]); } catch (Throwable $e) {}
            try { Database::delete('admins', 'id = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
    }
    try { Database::delete('admin_login_events', 'username IN (:a, :b)', ['a' => BOSS, 'b' => TMP]); } catch (Throwable $e) {}
    clearLimits();
    foreach ($jars as $j) { @unlink($j); }
    foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) { @unlink($f); }
    @rmdir($tmpDir);
};

echo "\n=== u11-F1: core fixes (AI door, CLI exit status, help-desk sender) ===\n";
$cleanup();

try {
    $mkAdmin = static function (string $user, string $name, int $mustChange): int {
        $id = (int) Database::insert('admins', [
            'username' => $user, 'password_hash' => password_hash(PW, PASSWORD_DEFAULT), 'full_name' => $name,
            'role' => 'superadmin', 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => $mustChange,
        ]);
        QuickUnlock::set($id, 'pin', '615273');   // the code-first door sends a PIN-less account to pin-setup.php
        return $id;
    };
    $bossId = $mkAdmin(BOSS, 'U11 F1 Boss', 0);
    $tmpId  = $mkAdmin(TMP, 'U11 F1 Temp', 1);

    Settings::set('ai_web_agent_on', '0', 'bool', 'ai', false);            // no AI call: only the local brain answers
    Settings::set('wa_local_brain_on', '1', 'bool', 'whatsapp', false);
    Settings::set('wa_report_chart_on', '0', 'bool', 'ai', false);
    Settings::set('helpdesk_on', '1', 'bool', 'helpdesk', false);
    Settings::set('helpdesk_ack', '0', 'bool', 'helpdesk', false);        // no message to any test number
    Settings::set('helpdesk_office_alert', '0', 'bool', 'helpdesk', false);
    Settings::set('helpdesk_autoassign', '0', 'bool', 'helpdesk', false);
    Settings::set('helpdesk_cancel_escalate_inr', '1000', 'int', 'helpdesk', false);
    Settings::flush();

    /* =====================================================================
     *  F1-4  the first line of an office / system ticket is the office's
     * ===================================================================== */
    echo "\n-- F1-4: who wrote the first line of a ticket --\n";
    $first = static fn(int $tid): ?array => Database::fetch(
        'SELECT sender_type, sender_name, message, is_internal FROM support_messages WHERE ticket_id = :t ORDER BY id LIMIT 1', ['t' => $tid]);

    $cust = HelpDesk::open(['name' => 'U11 F1 Customer', 'phone' => PH_CUST, 'category' => 'bus_late',
        'message' => NOTE_CUST, 'source' => 'web', 'lang' => 'en', 'ack' => false]);
    $desk = HelpDesk::open(['name' => 'U11 F1 Caller', 'phone' => PH_DESK, 'category' => 'other', 'message' => NOTE_DESK,
        'source' => 'desk', 'trusted' => true, 'created_by' => $bossId, 'ack' => false, 'lang' => 'ne']);
    check('tickets opened (customer + desk)', !empty($cust['ok']) && !empty($desk['ok']) && empty($cust['duplicate']) && empty($desk['duplicate']));

    $fc = $first((int) ($cust['id'] ?? 0));
    check('a ticket the customer opened keeps their words as theirs (customer, their name)',
        $fc !== null && $fc['sender_type'] === 'customer' && $fc['sender_name'] === 'U11 F1 Customer' && $fc['message'] === NOTE_CUST,
        $fc === null ? 'no message' : $fc['sender_type'] . ' / ' . $fc['sender_name']);

    $fd = $first((int) ($desk['id'] ?? 0));
    check('a desk-opened ticket: the first line is the office\'s, under the staff member\'s name',
        $fd !== null && $fd['sender_type'] === 'admin' && $fd['sender_name'] === 'U11 F1 Boss' && $fd['message'] === NOTE_DESK,
        $fd === null ? 'no message' : $fd['sender_type'] . ' / ' . $fd['sender_name']);
    check('…kept as an internal note (nothing of it was sent to the customer)', $fd !== null && (int) $fd['is_internal'] === 1);
    check('…while the ticket itself still carries the CUSTOMER\'s name and number',
        (string) Database::scalar('SELECT name FROM support_tickets WHERE id = :i', ['i' => (int) $desk['id']], '') === 'U11 F1 Caller');

    // The system path: HelpDesk::cancelFollowUp on a cancelled ticket the office owes a refund on.
    do {
        $pnr = 'SHG-2099-' . random_int(910000, 919999);
    } while (Database::exists('SELECT 1 FROM bookings WHERE pnr = :p', ['p' => $pnr]));
    $bid = (int) Database::insert('bookings', ['pnr' => $pnr, 'contact_phone' => PH_SYS, 'contact_country_code' => '91',
        'status' => 'cancelled', 'total_amount' => 6000.00, 'refund_status' => 'pending', 'refund_amount' => 4500.00]);
    $booking = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $bid]) ?? [];
    $sysRef  = HelpDesk::cancelFollowUp($booking, null, '');
    $sysId   = $sysRef !== null ? (int) Database::scalar('SELECT id FROM support_tickets WHERE ticket_ref = :r', ['r' => $sysRef], 0) : 0;
    check('the cancel follow-up opened its refund ticket', $sysId > 0, (string) $sysRef);
    $fs = $first($sysId);
    check('the system follow-up\'s first line is the system\'s, not the customer\'s ("' . SYS_NAME . '")',
        $fs !== null && $fs['sender_type'] === 'admin' && $fs['sender_name'] === SYS_NAME && str_contains((string) $fs['message'], 'refund follow-up'),
        $fs === null ? 'no message' : $fs['sender_type'] . ' / ' . $fs['sender_name']);
    check('…as an internal note', $fs !== null && (int) $fs['is_internal'] === 1);

    // The customer's view (help.php -> HelpDesk::publicView) and the assistant's read-back (AiHandoff).
    $words = static fn(?array $v): array => array_map(static fn(array $m): string => $m['from'] . ':' . $m['text'], (array) ($v['messages'] ?? []));
    $vCust = HelpDesk::publicView((string) $cust['ref'], PH_CUST);
    $vDesk = HelpDesk::publicView((string) $desk['ref'], PH_DESK);
    $vSys  = HelpDesk::publicView((string) $sysRef, PH_SYS);
    check('help.php: the customer still sees their own words as "you"', in_array('you:' . NOTE_CUST, $words($vCust), true), implode(' | ', $words($vCust)));
    check('help.php: the desk\'s note is NOT shown to the customer as their words (nor as a reply)',
        $vDesk !== null && $words($vDesk) === [], implode(' | ', $words($vDesk)));
    check('help.php: the system follow-up note is NOT shown to the customer at all',
        $vSys !== null && $words($vSys) === [], implode(' | ', $words($vSys)));
    $replies = (int) Database::scalar(
        "SELECT COUNT(*) FROM support_messages WHERE ticket_id IN (:a, :b) AND sender_type = 'admin' AND is_internal = 0",
        ['a' => (int) $desk['id'], 'b' => $sysId], -1);
    check('the assistant\'s status read-back (AiHandoff: a SENT staff reply) finds nothing to read out', $replies === 0, "$replies row(s)");

    /* F1-4b: a note the SYSTEM writes for the office WITHOUT staff powers - the WaPayProof call
       exactly as includes/wapayproof.php makes it (source chat, no name, no ack), plus system_note.
       It names the PNR of ANOTHER number's booking and asks for priority 'low': neither may stick,
       because system_note is not 'trusted'. */
    echo "\n-- F1-4b: a system-written note without staff powers (WaPayProof) --\n";
    $pay = HelpDesk::open(['name' => '', 'phone' => PH_PAY, 'category' => 'payment', 'pnr' => $pnr, 'source' => 'chat',
        'lang' => 'en', 'ack' => false, 'priority' => 'low', 'system_note' => true, 'message' => NOTE_PAY]);
    $payId = (int) ($pay['id'] ?? 0);
    check('the WaPayProof-style ticket opened', !empty($pay['ok']) && $payId > 0 && empty($pay['duplicate']));
    $fp = $first($payId);
    check('its first line is the SYSTEM\'s internal note, not the passenger\'s words',
        $fp !== null && $fp['sender_type'] === 'admin' && $fp['sender_name'] === SYS_NAME && (int) $fp['is_internal'] === 1
            && str_contains((string) $fp['message'], 'Please check the bank'),
        $fp === null ? 'no message' : $fp['sender_type'] . ' / ' . $fp['sender_name'] . ' / internal ' . $fp['is_internal']);
    $vPay = HelpDesk::publicView((string) ($pay['ref'] ?? ''), PH_PAY);
    check('help.php: the passenger is NOT shown the office instruction as "you:"',
        $vPay !== null && $words($vPay) === [], implode(' | ', $words($vPay)));
    $tp = Database::fetch('SELECT booking_id, priority, name FROM support_tickets WHERE id = :i', ['i' => $payId]) ?? [];
    check('system_note grants none of trusted\'s powers: another number\'s PNR is NOT linked',
        array_key_exists('booking_id', $tp) && $tp['booking_id'] === null,
        'booking_id ' . (array_key_exists('booking_id', $tp) ? var_export($tp['booking_id'], true) : 'no row'));
    check('…and the priority stays the category\'s own (no override)', (string) ($tp['priority'] ?? '') === 'high', (string) ($tp['priority'] ?? ''));
    check('…while the ticket itself is still the passenger\'s (masked number as the name)',
        str_starts_with((string) ($tp['name'] ?? ''), 'Customer '), (string) ($tp['name'] ?? ''));
    $payAudit = (string) Database::scalar("SELECT detail FROM audit_logs WHERE action = 'support.open' AND entity_id = :r ORDER BY id DESC LIMIT 1",
        ['r' => (string) ($pay['ref'] ?? '')], '');
    check('the audit says the system opened it (not "the customer")', $payAudit === 'opened by the system', $payAudit);
    /* The caller. HelpDesk can only store what it is told: includes/wapayproof.php confirmTurn()
       must pass system_note on its HelpDesk::open() call (one key). */
    $wppCalls = preg_match_all('/HelpDesk::open\(\[[^;]*/s', (string) file_get_contents(INCLUDE_PATH . '/wapayproof.php'), $wppM) > 0 ? $wppM[0] : [];
    $wppPay   = array_values(array_filter($wppCalls, static fn(string $c): bool => str_contains($c, "'category' => 'payment'")));
    check('includes/wapayproof.php opens its payment-reference ticket with system_note => true',
        $wppPay !== [] && array_filter($wppPay, static fn(string $c): bool => !str_contains($c, "'system_note' => true")) === [],
        $wppPay === [] ? 'the HelpDesk::open call was not found' : 'one-key patch in WaPayProof::confirmTurn: add \'system_note\' => true to the HelpDesk::open([...]) array');

    /* =====================================================================
     *  F1-1  the AI door over HTTP, as the browser asks it
     * ===================================================================== */
    echo "\n-- F1-1: POST /api/ai-chat.php from a signed-in office browser --\n";
    $jarBoss = $jars[] = sys_get_temp_dir() . '/shg_u11f1_boss_' . getmypid() . '.txt';
    $jarTmp  = $jars[] = sys_get_temp_dir() . '/shg_u11f1_tmp_' . getmypid() . '.txt';
    $jarCust = $jars[] = sys_get_temp_dir() . '/shg_u11f1_cust_' . getmypid() . '.txt';
    $Q = 'aaja kati ticket bikyo';

    $r = signIn($jarBoss, BOSS);
    check('the clean superadmin signs in', in_array($r['code'], [200, 302, 303], true), 'HTTP ' . $r['code']);
    $csrfBoss = csrfFrom(http($jarBoss, '/admin/change-password.php')['body']);
    check('…and has a CSRF token for the API', $csrfBoss !== '');
    $a = aiChat($jarBoss, $csrfBoss, $Q);
    check('control: the office gets its day summary (role admin, local summary)',
        (string) ($a['json']['data']['role'] ?? '') === 'admin' && (string) ($a['json']['data']['local'] ?? '') === 'summary',
        'HTTP ' . $a['code'] . ' ' . mb_substr($a['raw'], 0, 120));

    // F1-4 on the office page, while this session is clean.
    $inbox = static function (int $tid, string $ref) use ($jarBoss): string {
        $h = http($jarBoss, '/admin/support-inbox.php?q=' . rawurlencode($ref) . '&open=' . $tid)['body'];
        $at = strpos($h, 'id="t' . $tid . '"');
        return $at === false ? '' : substr($h, $at, 4000);
    };
    $tDesk = $inbox((int) $desk['id'], (string) $desk['ref']);
    check('inbox: the desk ticket\'s first line shows under the STAFF name as an internal note',
        str_contains($tDesk, '<b class="adm">U11 F1 Boss</b>') && str_contains($tDesk, 'internal note') && !str_contains($tDesk, 'class="cus"'),
        $tDesk === '' ? 'thread not rendered' : '');
    $tSys = $inbox($sysId, (string) $sysRef);
    check('inbox: the follow-up\'s first line shows as the SYSTEM\'s internal note, never as the customer',
        str_contains($tSys, '<b class="adm">' . SYS_NAME . '</b>') && str_contains($tSys, 'internal note') && !str_contains($tSys, 'class="cus"'),
        $tSys === '' ? 'thread not rendered' : '');
    $tCust = $inbox((int) $cust['id'], (string) $cust['ref']);
    check('inbox: a customer-opened ticket still shows the customer', str_contains($tCust, '<b class="cus">U11 F1 Customer</b>'),
        $tCust === '' ? 'thread not rendered' : '');
    $tPay = $inbox($payId, (string) ($pay['ref'] ?? ''));
    check('inbox (F1-4b): the WaPayProof-style note shows as the SYSTEM\'s internal note, never under the passenger\'s name',
        str_contains($tPay, '<b class="adm">' . SYS_NAME . '</b>') && str_contains($tPay, 'internal note') && !str_contains($tPay, 'class="cus"'),
        $tPay === '' ? 'thread not rendered' : '');

    /* F1-1b control: a clean office session is NOT touched by the /api/ gate. Read-only probe:
       Quick Ticket's staff 'plan' (no seat is held, nothing is written). */
    $qt = qtPlan($jarBoss, $csrfBoss);
    check('F1-1b control: a clean superadmin still reaches Quick Ticket\'s staff door (no temporary-password refusal)',
        !str_contains($qt['body'], TEMP_MSG) && $qt['code'] !== 401 && $qt['code'] !== 403, 'HTTP ' . $qt['code'] . ' ' . mb_substr($qt['body'], 0, 140));

    // help.php's own API, as the customer asks it.
    clearLimits();
    $helpPage = http($jarCust, '/help.php')['body'];   // help.php carries <meta name="csrf">
    $csrfCust = preg_match('/<meta name="csrf" content="([^"]+)"/', $helpPage, $hm) === 1 ? html_entity_decode($hm[1]) : csrfFrom($helpPage);
    $st = static function (string $ref, string $phone) use ($jarCust, $csrfCust): array {
        $j = json_decode(http($jarCust, '/api/helpdesk.php', ['form' => [CSRF_TOKEN_NAME => $csrfCust, 'action' => 'status', 'ref' => $ref, 'phone' => $phone]])['body'], true);
        return is_array($j) ? $j : [];
    };
    $hs = $st((string) $sysRef, PH_SYS);
    $hd = $st((string) $desk['ref'], PH_DESK);
    check('help.php API: the follow-up opens for its own number with NO message from anyone',
        !empty($hs['ok']) && ($hs['data']['messages'] ?? null) === [], json_encode($hs['data']['messages'] ?? $hs, JSON_UNESCAPED_UNICODE));
    check('help.php API: the desk ticket shows no office note to the customer',
        !empty($hd['ok']) && ($hd['data']['messages'] ?? null) === []);

    // The same session, after the office issues a temporary password (inside Auth's 30 s window).
    Database::update('admins', ['must_change_pw' => 1], 'id = :i', ['i' => $bossId]);
    $a = aiChat($jarBoss, $csrfBoss, $Q);
    check('a temporary password issued mid-session: no office role, no office summary',
        !officeAnswer($a), 'HTTP ' . $a['code'] . ' ' . mb_substr($a['raw'], 0, 160));
    $qt = qtPlan($jarBoss, $csrfBoss);
    check('F1-1b: …and the very next Quick Ticket call is refused (403, change the password first) - not 30 s later',
        isTempRefusal($qt), 'HTTP ' . $qt['code'] . ' ' . mb_substr($qt['body'], 0, 140));
    Database::update('admins', ['must_change_pw' => 0, 'is_active' => 0], 'id = :i', ['i' => $bossId]);
    $a = aiChat($jarBoss, $csrfBoss, $Q);
    check('a suspension mid-session: no office role, no office summary', !officeAnswer($a), 'HTTP ' . $a['code']);
    Database::update('admins', ['is_active' => 1], 'id = :i', ['i' => $bossId]);

    // THE bug: signed in ON the temporary password (the session itself carries must_change_pw).
    $r = signIn($jarTmp, TMP);
    check('the superadmin on a temporary password signs in (sent to change it)', in_array($r['code'], [200, 302, 303], true), 'HTTP ' . $r['code']);
    $csrfTmp = csrfFrom(http($jarTmp, '/admin/change-password.php')['body']);
    check('…and holds a session + CSRF token', $csrfTmp !== '');
    $a = aiChat($jarTmp, $csrfTmp, $Q);
    check('POST /api/ai-chat.php on a temporary password: NOT the office (no office role, no office summary)',
        !officeAnswer($a), 'HTTP ' . $a['code'] . ' ' . mb_substr($a['raw'], 0, 160));
    check('…whatever answers it answers as a customer', $a['json'] === null || empty($a['json']['ok'])
        || (string) ($a['json']['data']['role'] ?? '') === 'customer', mb_substr($a['raw'], 0, 120));

    /* F1-1b: the SELLING doors. Every probe is read-only or empty, so even with the gate gone
       nothing is sold: Quick Ticket 'plan' only plans, Quick Ticket 'sell' and the counter-mode
       booking carry no passenger and fail validation when they get past the gate. */
    echo "\n-- F1-1b: a temporary password reaches no /api/ endpoint (selling included) --\n";
    check('/api/ai-chat.php: refused by the /api/ gate itself (403, change the password first)',
        $a['code'] === 403 && str_contains($a['raw'], TEMP_MSG), 'HTTP ' . $a['code'] . ' ' . mb_substr($a['raw'], 0, 140));
    $qt = qtPlan($jarTmp, $csrfTmp);
    check('/api/quick-ticket.php staff door (plan): refused, 403', isTempRefusal($qt), 'HTTP ' . $qt['code'] . ' ' . mb_substr($qt['body'], 0, 140));
    $qs = http($jarTmp, '/api/quick-ticket.php', ['form' => [CSRF_TOKEN_NAME => $csrfTmp, 'action' => 'sell']]);
    check('/api/quick-ticket.php action=sell: refused, 403', isTempRefusal($qs), 'HTTP ' . $qs['code'] . ' ' . mb_substr($qs['body'], 0, 140));
    $bk = http($jarTmp, '/api/book.php', ['form' => [CSRF_TOKEN_NAME => $csrfTmp, 'counterPayment' => 'cash']]);
    check('/api/book.php counter mode (counterPayment=cash): refused, 403', isTempRefusal($bk), 'HTTP ' . $bk['code'] . ' ' . mb_substr($bk['body'], 0, 140));
    $enc = http($jarTmp, '/%61pi/quick-ticket.php', ['form' => [CSRF_TOKEN_NAME => $csrfTmp, 'action' => 'plan']]);
    check('a percent-encoded path (/%61pi/quick-ticket.php) is refused too (the gate reads the real script, not the URI)',
        isTempRefusal($enc), 'HTTP ' . $enc['code'] . ' ' . mb_substr($enc['body'], 0, 140));
    $cs = http($jarTmp, '/api/csrf.php');
    check('…the CSRF refresh (no staff power) still answers', $cs['code'] === 200 && !str_contains($cs['body'], TEMP_MSG), 'HTTP ' . $cs['code']);
    $gq = qtPlan($jarCust, $csrfCust);
    check('a guest is untouched by the gate (Quick Ticket\'s own 401 "sign in as staff")',
        $gq['code'] === 401 && !str_contains($gq['body'], TEMP_MSG), 'HTTP ' . $gq['code']);

    // The way out: the holder changes the password; the very next call is let through.
    $cp = http($jarTmp, '/admin/change-password.php', ['form' => [CSRF_TOKEN_NAME => $csrfTmp,
        'current_password' => PW, 'new_password' => PW_NEW, 'confirm_password' => PW_NEW]]);
    $mcp = (int) Database::scalar('SELECT must_change_pw FROM admins WHERE id = :i', ['i' => $tmpId], -1);
    check('the temporary password is changed at change-password.php', $mcp === 0, 'HTTP ' . $cp['code'] . ', must_change_pw ' . $mcp);
    $csrfTmp2 = csrfFrom(http($jarTmp, '/admin/change-password.php')['body']);
    $qt = qtPlan($jarTmp, $csrfTmp2 !== '' ? $csrfTmp2 : $csrfTmp);
    check('…and from then on Quick Ticket answers that session again',
        !str_contains($qt['body'], TEMP_MSG) && $qt['code'] !== 401 && $qt['code'] !== 403, 'HTTP ' . $qt['code'] . ' ' . mb_substr($qt['body'], 0, 140));

    /* =====================================================================
     *  F1-2  a crashing CLI script ends with status 1
     * ===================================================================== */
    echo "\n-- F1-2: an uncaught exception under the CLI fails the process --\n";
    @mkdir($tmpDir, 0700, true);
    $child = $tmpDir . DIRECTORY_SEPARATOR . 'u11f1-child.php';
    file_put_contents($child, "<?php\ndeclare(strict_types=1);\nrequire_once " . var_export(dirname(__DIR__) . '/includes/bootstrap.php', true) . ";\n"
        . "\$mode = \$argv[1] ?? '';\n"
        . "if (\$mode === 'api') { \$_SERVER['REQUEST_URI'] = '/api/u11-f1.php'; }\n"
        . "register_shutdown_function(static function (): void { echo \"\\n[u11f1-cleanup-ran]\\n\"; });\n"
        . "if (\$mode === 'ok') { echo \"u11f1-fine\\n\"; exit; }\n"
        . "throw new RuntimeException('u11f1-boom');\n");
    $ini = (string) php_ini_loaded_file();
    $run = static function (string $mode) use ($child, $ini): array {
        $cmd = escapeshellarg(PHP_BINARY) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg($child) . ' ' . $mode;
        $out = []; $code = -1;
        exec($cmd . ' 2>&1', $out, $code);
        return ['code' => $code, 'out' => implode("\n", $out)];
    };
    $crash = $run('page');
    check('a script that throws ends with exit status 1 (was 0, so run-all counted it ok)', $crash['code'] === 1, 'exit ' . $crash['code']);
    check('…and still prints the error page as before',
        str_contains($crash['out'], 'u11f1-boom') || str_contains($crash['out'], 'Something went wrong'), mb_substr($crash['out'], 0, 100));
    check('…after the script\'s own clean-up ran (registered earlier, still runs first)', str_contains($crash['out'], '[u11f1-cleanup-ran]'));
    $api = $run('api');
    $apiJson = json_decode(trim((string) strtok($api['out'], "\n")), true);
    check('an /api/ request that throws under the CLI: JSON error body as before, exit status 1',
        $api['code'] === 1 && is_array($apiJson) && ($apiJson['ok'] ?? null) === false, 'exit ' . $api['code']);
    $fine = $run('ok');
    check('a script that ends normally still exits 0', $fine['code'] === 0 && str_contains($fine['out'], 'u11f1-fine'), 'exit ' . $fine['code']);
    $runAll = (string) file_get_contents(__DIR__ . '/run-all.php');
    check('tests/run-all.php judges each suite by its exit code (so a crash now counts as FAIL)',
        str_contains($runAll, "exec(\$cmd . ' 2>&1', \$output, \$code)") && str_contains($runAll, "\$r['code'] === 0"));
} catch (Throwable $e) {
    check('suite ran to the end', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $restoreSettings();
    $cleanup();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
