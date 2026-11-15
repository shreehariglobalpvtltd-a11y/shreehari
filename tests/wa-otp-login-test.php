<?php
/**
 * =====================================================================
 *  wa-otp-login-test.php — 15 Nov 2026 (lockdisc).
 *
 *  1. The unlock screen's "📲 WhatsApp कोड": CodeLogin::waLoginSend /
 *     waLoginCheck on the LOCAL shari_test database with throwaway accounts
 *     (deleted before and after): an agent with a PIN opens by a WhatsApp
 *     code; the reply is the same for unknown / office / switched-off / 2FA
 *     codes and no code is sent to them; wrong, reused and unsent codes fail.
 *  2. The desk's ticket note + discount share: BookingService::ticketNoteClean,
 *     Ticket::ticketNote, Ticket::discountPctTxt.
 *
 *     php -c .claude/php-dev.ini tests/wa-otp-login-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/codelogin.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/ticket.php';

set_exception_handler(static function (Throwable $e): void {
    echo "\n  UNCAUGHT " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\nFAILED: 1 (uncaught)\n";
    exit(1);
});
ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
register_shutdown_function(static function (): void { while (ob_get_level() > 0) { ob_end_flush(); } });

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }
function sid(): int { return (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0); }
function fresh(): void {
    $_SESSION = [];
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'code_login%' OR bucket LIKE 'quick_unlock%' OR bucket LIKE 'otp\\_%'
        OR bucket = 'otp_verify' OR bucket LIKE 'admin_2fa%'");
}

echo "\n=== WhatsApp code on the unlock screen + ticket note ===\n\n";
if (!CodeLogin::ready()) {
    echo "  SKIP  run database/upgrade-2026-11-15-code-login.sql first\n";
    exit(1);
}
$keep = [];
foreach (['quick_unlock_on', 'login_code_first_on', 'login_pin_required', 'whatsapp_driver'] as $k) {
    $keep[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
register_shutdown_function(static function () use ($keep): void {
    foreach ($keep as $k => $row) {
        $row === null ? Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k])
                      : Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => (string) $row['svalue'], 'k' => $k]);
    }
});
Settings::set('quick_unlock_on', true, 'bool', 'security', false);
Settings::set('login_code_first_on', true, 'bool', 'security', false);
Settings::set('login_pin_required', true, 'bool', 'security', false);
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);
CodeLogin::$evenTiming = false;
$CODES = [];
CodeLogin::$deliverHook = static function (string $intl, string $code) use (&$CODES): bool { $CODES[$intl] = $code; return true; };

$PFX = 'wl1_';
$PH = ['7000002201', '7000002202', '7000002203', '7000002204'];
$cleanup = static function () use ($PFX): void {
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE '{$PFX}%'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        try { Auth::setAdmin2fa($id, false); } catch (Throwable $e) {}
        foreach (['ap_members', 'admin_profiles', 'admin_sessions'] as $t) {
            try { Database::delete($t, 'admin_id = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    Database::run("DELETE FROM otp_codes WHERE identifier LIKE 'wl:%'");
};
$cleanup();
register_shutdown_function($cleanup);

$mk = static function (string $u, string $role, string $phone, ?int $agentCode = null) use ($PFX): int {
    $id = (int) Database::insert('admins', [
        'username' => $PFX . $u, 'password_hash' => password_hash('Pw@' . $u . '9z', PASSWORD_BCRYPT),
        'full_name' => 'WA Login ' . $u, 'role' => $role, 'phone' => $phone, 'is_active' => 1, 'must_change_pw' => 0,
    ]);
    if ($agentCode !== null) {
        AgentWallet::setAgentCode($id, $agentCode);
    }
    return $id;
};
$code  = static fn(int $n): string => 'SHG-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
$aOk   = $mk('agent', 'agent', $PH[0], 980);
$aMgr  = $mk('mgr', 'manager', $PH[1]);
$aOff  = $mk('off', 'agent', $PH[2], 982);
$a2fa  = $mk('twofa', 'counter', $PH[3], 983);
foreach ([$aOk, $aMgr, $aOff, $a2fa] as $i => $id) {
    QuickUnlock::set($id, 'pin', ['4826', '602718', '5937', '7391'][$i]);
}
Database::update('admins', ['is_active' => 0], 'id = :i', ['i' => $aOff]);
Auth::setAdmin2fa($a2fa, true);
$sent = static function () use (&$CODES): int { return count($CODES); };

/* ------------------------------------------------------------------ */
section('Agent with a PIN opens by a WhatsApp code');
fresh(); $CODES = [];
$r = CodeLogin::enter($code(980));
check('code -> unlock screen', ($r['step'] ?? '') === 'c_unlock', json_encode($r));
check('waLoginIntl names the registered WhatsApp', str_ends_with(CodeLogin::waLoginIntl($aOk), $PH[0]), CodeLogin::waLoginIntl($aOk));
$r = CodeLogin::waLoginSend();
check('send -> code screen + generic notice', ($r['step'] ?? '') === 'c_walogin' && str_contains((string) ($r['notice'] ?? ''), 'If this account'), json_encode($r));
$intl = (string) array_key_first($CODES);
$otp  = (string) ($CODES[$intl] ?? '');
check('one code went to the agent\'s own number', $sent() === 1 && str_ends_with($intl, $PH[0]) && strlen($otp) === OTP_LENGTH, $intl);
$wrong = $otp === '000000' ? '111111' : '000000';
$r = CodeLogin::waLoginCheck($wrong);
check('wrong code refused, stays on the code screen', ($r['step'] ?? '') === 'c_walogin' && sid() === 0, json_encode($r));
$r = CodeLogin::waLoginCheck($otp);
check('right code opens the session', ($r['go'] ?? '') === 'home' && sid() === $aOk, json_encode($r) . ' sid=' . sid());
check('door state cleared after sign-in', CodeLogin::state() === null);
check('audit row admin.login.whatsapp_otp', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.login.whatsapp_otp' AND entity_id = :i", ['i' => (string) $aOk]) >= 1);

section('A used code does not open again');
fresh();
CodeLogin::enter($code(980));
$_SESSION[CodeLogin::KEY]['wl'] = time();   // as if a code had been asked for
$r = CodeLogin::waLoginCheck($otp);
check('reused code refused', empty($r['go']) && sid() === 0, json_encode($r));

section('No send, no check');
fresh();
CodeLogin::enter($code(980));
$r = CodeLogin::waLoginCheck('123456');
check('check before any send -> back to the code box', ($r['step'] ?? '') === 'c_code' && sid() === 0, json_encode($r));
$_SESSION = [];
$r = CodeLogin::waLoginSend();
check('send with no code typed -> code box', ($r['step'] ?? '') === 'c_code');

section('Same reply, nothing sent: unknown / office / switched off / 2FA');
foreach ([['unknown code', 'SHG-0979'], ['manager (office)', 'wl1_mgr'], ['switched-off agent', $code(982)], ['counter with 2FA', $code(983)]] as [$lbl, $typed]) {
    fresh(); $CODES = [];
    CodeLogin::enter($typed);
    $r = CodeLogin::waLoginSend();
    check($lbl . ': same notice', ($r['step'] ?? '') === 'c_walogin' && str_contains((string) ($r['notice'] ?? ''), 'If this account'), json_encode($r));
    check($lbl . ': no code sent', $sent() === 0);
    $r = CodeLogin::waLoginCheck('482611');
    check($lbl . ': any code refused', empty($r['go']) && sid() === 0);
}
check('waLoginIntl empty for office / off / 2FA / nobody',
    CodeLogin::waLoginIntl($aMgr) === '' && CodeLogin::waLoginIntl($aOff) === '' && CodeLogin::waLoginIntl($a2fa) === '' && CodeLogin::waLoginIntl(0) === '');

section('Wrong-code budget');
fresh(); $CODES = [];
CodeLogin::enter($code(980));
CodeLogin::waLoginSend();
$last = [];
for ($i = 0; $i < 7; $i++) { $last = CodeLogin::waLoginCheck('99999' . $i); }
check('7th wrong try says too many', str_contains((string) ($last['error'] ?? ''), 'Too many'), json_encode($last));
$good = (string) ($CODES[(string) array_key_first($CODES)] ?? '');
$r = CodeLogin::waLoginCheck($good);
check('even the right code waits once the budget is spent', empty($r['go']) && sid() === 0);

/* ------------------------------------------------------------------ */
section('Ticket note + discount share');
check('note: one line, trimmed', BookingService::ticketNoteClean("  Dashain\n chhut \t 10%  ") === 'Dashain chhut 10%', BookingService::ticketNoteClean("  Dashain\n chhut \t 10%  "));
check('note: at most 80 characters', mb_strlen(BookingService::ticketNoteClean(str_repeat('छुट ', 60))) === 80);
check('note: blank stays blank', BookingService::ticketNoteClean("  \n ") === '');
check('Ticket::ticketNote reads the column', Ticket::ticketNote(['ticket_note' => " family\n offer "]) === 'family offer');
check('Ticket::ticketNote without the column', Ticket::ticketNote([]) === '');
check('10% of 2200', Ticket::discountPctTxt(['base_total' => 2200, 'coupon_discount' => 220]) === ' (10%)', Ticket::discountPctTxt(['base_total' => 2200, 'coupon_discount' => 220]));
check('₹50 of 2200 = 2.3%', Ticket::discountPctTxt(['base_total' => 2200, 'coupon_discount' => 50]) === ' (2.3%)');
check('no discount, no text', Ticket::discountPctTxt(['base_total' => 2200, 'coupon_discount' => 0]) === '' && Ticket::discountPctTxt([]) === '');

$col = CounterDesk::hasColumn('bookings', 'ticket_note');
check('bookings.ticket_note exists (upgrade-2026-11-15-ticket-note.sql)', $col);

/* ------------------------------------------------------------------ */
section('Name on the PIN screen');
fresh();
CodeLogin::enter($code(980));
check('known code shows the person\'s name', CodeLogin::shownName() === 'WA Login agent', CodeLogin::shownName());
fresh();
CodeLogin::enter('SHG-0979');
check('unknown code shows no name', CodeLogin::shownName() === '');

section('Office readies a login: pick the person, DOB, mobile');
$aNew = $mk('fresh', 'agent', '', 984);
$r = CodeLogin::officeSetDetails($aNew, '1992-02-29', '9812345611', '977', $aMgr, false);
check('office sets DOB + Nepal mobile', $r['ok'] === true && ($r['name'] ?? '') === 'WA Login fresh', json_encode($r));
check('mobile saved as 10 digits', (string) Database::scalar('SELECT phone FROM admins WHERE id = :i', ['i' => $aNew]) === '9812345611');
check('DOB saved on the member record', (string) Database::scalar('SELECT dob FROM ap_members WHERE admin_id = :i', ['i' => $aNew]) === '1992-02-29');
check('country saved as 977', (string) Database::scalar('SELECT country_code FROM admin_profiles WHERE admin_id = :i', ['i' => $aNew]) === '977');
check('the number now opens by WhatsApp as +977', CodeLogin::waLoginIntl($aNew) === '9779812345611', CodeLogin::waLoginIntl($aNew));
fresh();
$r = CodeLogin::enter($code(984));
check('no PIN yet -> first-time setup screen', ($r['step'] ?? '') === 'c_setup', json_encode($r));
$r = CodeLogin::identity('1992-02-29', '9812345611');
check('the DOB + mobile the office set now match', !empty($_SESSION[CodeLogin::KEY]['idok']), json_encode($r));
check('bad date refused', CodeLogin::officeSetDetails($aNew, '2099-01-01', '9812345611', '977', $aMgr, false)['ok'] === false);
check('bad mobile refused', CodeLogin::officeSetDetails($aNew, '1992-02-29', '12345', '91', $aMgr, false)['ok'] === false);
check('India number must start 6-9', CodeLogin::officeSetDetails($aNew, '1992-02-29', '5812345611', '91', $aMgr, false)['ok'] === false);
check('own account refused', CodeLogin::officeSetDetails($aNew, '1992-02-29', '9812345611', '977', $aNew, true)['ok'] === false);
check('office account only by a super-admin', CodeLogin::officeSetDetails($aMgr, '1980-01-01', '9812345612', '977', $aOk, false)['ok'] === false);
check('...and a super-admin may', CodeLogin::officeSetDetails($aMgr, '1980-01-01', '7000002202', '91', $aOk, true)['ok'] === true);
/* ------------------------------------------------------------------ */
Settings::set('login_ask_dob', false, 'bool', 'security', false);   // the default door
$hiYes = static function (string $intl, string $text): void {
    WaVerify::inbound($intl, $text);
    WaVerify::inbound($intl, 'YES');
};

section('First time: agent code + mobile only, then WhatsApp hi + YES');
Database::run('DELETE FROM ap_members WHERE admin_id = :i', ['i' => $aNew]);   // no DOB on file at all
fresh();
CodeLogin::enter($code(984));
$r = CodeLogin::identity('', '9812345611');
$w = $_SESSION[CodeLogin::KEY]['wa'] ?? null;
check('mobile alone (no DOB) -> WhatsApp step with the hi link ready', ($r['step'] ?? '') === 'c_prove' && is_array($w) && empty($w['decoy']), json_encode($r));
check('the hi link is a real token for the registered number',
    (string) Database::scalar('SELECT intl FROM wa_verify WHERE token = :t', ['t' => (string) ($w['token'] ?? '')], '') === '9779812345611');
$hiYes('9779812345611', (string) ($w['text'] ?? ''));
$r = CodeLogin::hiDone();
check('hi + YES from that WhatsApp -> choose the PIN', ($r['step'] ?? '') === 'c_create', json_encode($r));
fresh();
CodeLogin::enter($code(984));
$r = CodeLogin::identity('', '9812345699');
check('wrong mobile -> told at once, no WhatsApp step', ($r['step'] ?? '') === 'c_setup' && str_contains((string) ($r['error'] ?? ''), 'not the one registered') && empty($_SESSION[CodeLogin::KEY]['wa']), json_encode($r));
fresh();
$aNoPh = $mk('nophone', 'agent', '', 985);
CodeLogin::enter($code(985));
$r = CodeLogin::identity('', '9812345698');
check('no mobile on file -> told to ask the office (owner screenshot case)', ($r['step'] ?? '') === 'c_setup' && str_contains((string) ($r['error'] ?? ''), 'No mobile is registered'), json_encode($r));
fresh();
CodeLogin::enter($code(984));
$r = CodeLogin::identity('', '');
check('no mobile -> asked again', ($r['step'] ?? '') === 'c_setup');

section('Forgot: code + mobile + security question -> new PIN straight away');
QuickUnlock::secSet($aOk, QuickUnlock::SEC_QUESTIONS['mother'], 'Sita Devi');
fresh();
CodeLogin::enter($code(980));
CodeLogin::forgot();
check('the forgot screen shows the account\'s question', CodeLogin::forgotQuestion() === QuickUnlock::SEC_QUESTIONS['mother']);
$r = CodeLogin::identity('', $PH[0], 'Gita');
check('wrong answer: "did not match", no PIN step', ($r['step'] ?? '') === 'c_forgot' && !CodeLogin::proved(), json_encode($r));
$r = CodeLogin::identity('', '9800000000', 'sita devi');
check('right answer + wrong mobile: refused', ($r['step'] ?? '') === 'c_forgot' && !CodeLogin::proved());
$r = CodeLogin::identity('', $PH[0], '  sita  DEVI ');
check('mobile + answer (any case / spaces) -> new PIN step', ($r['step'] ?? '') === 'c_create' && CodeLogin::proved(), json_encode($r));
$r = CodeLogin::createFirst('pin', '913572');
$r = CodeLogin::createConfirm('913572');
check('new PIN saved and signed in', ($r['go'] ?? '') === 'home' && sid() === $aOk, json_encode($r));
fresh();
CodeLogin::enter($code(980));
check('old PIN stops', empty(CodeLogin::unlock('pin', '4826')['go']));
fresh();
CodeLogin::enter($code(980));
check('new PIN opens', (CodeLogin::unlock('pin', '913572')['go'] ?? '') === 'home');
fresh();
CodeLogin::enter($code(980));
CodeLogin::forgot();
$last = [];
for ($i = 0; $i < 6; $i++) { $last = CodeLogin::identity('', $PH[0], 'wrong' . $i); }
check('answer tries are limited (5 a day per account)', str_contains((string) ($last['error'] ?? ''), 'Too many'), json_encode($last));
Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'code_login%'");

section('Unlock screen: WhatsApp hi + YES opens an agent');
fresh();
CodeLogin::enter($code(980));
CodeLogin::waLoginSend();
$wl = $_SESSION[CodeLogin::KEY]['wlhi'] ?? null;
check('hi link ready on the WhatsApp screen (real token)', is_array($wl) && empty($wl['decoy']));
$r = CodeLogin::waLoginHiDone();
check('before YES: not opened', empty($r['go']) && sid() === 0);
$hiYes('91' . $PH[0], (string) ($wl['text'] ?? ''));
$r = CodeLogin::waLoginHiDone();
check('hi + YES -> signed in', ($r['go'] ?? '') === 'home' && sid() === $aOk, json_encode($r) . ' sid=' . sid());
fresh();
CodeLogin::enter('wl1_mgr');
CodeLogin::waLoginSend();
check('office account: the hi link is a decoy', !empty($_SESSION[CodeLogin::KEY]['wlhi']['decoy']));
check('...and never opens', empty(CodeLogin::waLoginHiDone()['go']) && sid() === 0);
Database::run("DELETE FROM wa_verify WHERE phone IN ('" . implode("','", $PH) . "', '9812345611')");
Database::run("UPDATE admins SET sec_q = NULL, sec_a_hash = NULL WHERE id = :i", ['i' => $aOk]);
Database::run("DELETE FROM settings WHERE skey = 'login_ask_dob'");
try { Database::run('DELETE FROM admin_profiles WHERE admin_id IN (' . (int) $aNew . ',' . (int) $aMgr . ')'); } catch (Throwable $e) {}

echo "\n" . ($FAIL === 0 ? "ALL PASS: $PASS" : "FAILED: $FAIL (passed $PASS)") . "\n";
exit($FAIL === 0 ? 0 : 1);
