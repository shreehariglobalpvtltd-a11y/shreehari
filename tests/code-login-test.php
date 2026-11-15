<?php
/**
 * =====================================================================
 *  code-login-test.php — the code-first staff door (15 Nov 2026).
 *
 *  includes/codelogin.php end to end on the LOCAL shari_test database with
 *  throwaway accounts (deleted before and after): incorrect code, mobile
 *  typed as a code, existing 4-digit PIN, pattern, wrong credentials,
 *  progressive cooldown, switched-off account, first-time setup by WhatsApp
 *  code and by "hi" + YES, DOB + mobile alone never enough, missing details
 *  -> office activation, forgot PIN -> new pattern (old PIN stops), office
 *  2FA still asked after the PIN, the compulsory-PIN gate, remembered
 *  device, change account, and that DOB / mobile on file never change.
 *
 *     php -c .claude/php-dev.ini tests/code-login-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/codelogin.php';
require_once INCLUDE_PATH . '/agentwallet.php';

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
        OR bucket = 'otp_verify' OR bucket LIKE 'wa_verify%' OR bucket LIKE 'admin_2fa%'");
}

echo "\n=== Code-first login (code -> PIN / pattern -> own dashboard) ===\n\n";
if (!CodeLogin::ready() || !QuickUnlock::strikesReady()) {
    echo "  SKIP  run database/upgrade-2026-11-15-code-login.sql first\n";
    exit(1);
}
Settings::set('quick_unlock_on', true, 'bool', 'security', false);
Settings::set('login_code_first_on', true, 'bool', 'security', false);
Settings::set('login_pin_required', true, 'bool', 'security', false);
CodeLogin::$evenTiming = false;
// the local box has no whatsapp/config.php: the 2FA send goes through the no-network driver here
$oldDrv = Database::scalar("SELECT svalue FROM settings WHERE skey = 'whatsapp_driver'", [], null);
register_shutdown_function(static function () use ($oldDrv): void {
    $oldDrv === null ? Database::run("DELETE FROM settings WHERE skey = 'whatsapp_driver'")
                     : Database::run("UPDATE settings SET svalue = :v WHERE skey = 'whatsapp_driver'", ['v' => (string) $oldDrv]);
});
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);
$CODES = [];
CodeLogin::$deliverHook = static function (string $intl, string $code) use (&$CODES): bool { $CODES[$intl] = $code; return true; };

$PFX = 'cl1_';
$PH = ['7000001101', '7000001102', '7000001103', '7000001104', '7000001105', '7000001106', '7000001107'];
$cleanup = static function () use ($PFX, $PH): void {
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE '{$PFX}%'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        try { Auth::setAdmin2fa($id, false); } catch (Throwable $e) {}
        foreach (['ap_members', 'admin_profiles', 'admin_sessions', 'login_recovery_requests'] as $t) {
            try { Database::delete($t, 'admin_id = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    $in = "'" . implode("','", $PH) . "'";
    try { Database::run("DELETE FROM wa_verify WHERE phone IN ($in)"); } catch (Throwable $e) {}
    Database::run("DELETE FROM otp_codes WHERE identifier LIKE 'pin:%' OR identifier LIKE 'admin:%' AND created_at > NOW() - INTERVAL 1 DAY");
    Database::run("DELETE FROM login_recovery_requests WHERE code_typed LIKE 'CL1-%' OR code_typed LIKE 'SHG-99%'");
};
$cleanup();

$mk = static function (string $u, string $role, string $phone, ?string $dob, ?int $agentCode = null) use ($PFX): int {
    $id = (int) Database::insert('admins', [
        'username' => $PFX . $u, 'password_hash' => password_hash('Pw@' . $u . '9z', PASSWORD_BCRYPT),
        'full_name' => 'Code Login ' . $u, 'role' => $role, 'phone' => $phone, 'is_active' => 1, 'must_change_pw' => 0,
    ]);
    if ($agentCode !== null) {
        AgentWallet::setAgentCode($id, $agentCode);
    }
    if ($dob !== null) {
        Database::insert('ap_members', ['admin_id' => $id, 'card_token' => substr(bin2hex(random_bytes(12)), 0, 24), 'dob' => $dob]);
    }
    return $id;
};
// free agent codes 990-996 for the test (live codes are 1-9)
$code = static fn(int $n): string => 'SHG-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
$aPin   = $mk('pin', 'agent', $PH[0], '1990-04-12', 990);      // existing 4-digit PIN
$aPat   = $mk('pat', 'counter', $PH[1], '1988-01-30', 991);    // existing pattern
$aNew   = $mk('new', 'agent', $PH[2], '1995-07-07', 992);      // first-time, details on file
$aHi    = $mk('hi', 'agent', $PH[3], '1993-03-03', 993);       // first-time by "hi"
$aBare  = $mk('bare', 'agent', $PH[4], null, 994);             // no DOB on file
$aOff   = $mk('off', 'agent', $PH[5], '1980-05-05', 995);      // switched off
$a2fa   = $mk('mgr', 'counter', $PH[6], '1985-09-09', 996);    // office 2FA
QuickUnlock::set($aPin, 'pin', '4826');
QuickUnlock::set($aPat, 'pattern', '0-4-8-5');
QuickUnlock::set($aOff, 'pin', '5937');
QuickUnlock::set($a2fa, 'pin', '602718');
Database::update('admins', ['is_active' => 0], 'id = :i', ['i' => $aOff]);
Auth::setAdmin2fa($a2fa, true);
$before = Database::fetchAll("SELECT a.id, a.phone, a.role, m.dob FROM admins a LEFT JOIN ap_members m ON m.admin_id = a.id WHERE a.username LIKE '{$PFX}%' ORDER BY a.id");

/* ------------------------------------------------------------------ */
section('Incorrect code / mobile typed as a code');
fresh();
$r = CodeLogin::enter('SHG-0989');
check('an unknown code goes to the same PIN screen', $r['step'] === 'c_unlock');
$r = CodeLogin::unlock('pin', '482600');
check('any PIN on an unknown code: generic "did not match", no session', $r['step'] === 'c_unlock' && str_contains((string) $r['error'], 'did not match') && sid() === 0);
fresh();
$r = CodeLogin::enter('9800000941');
check('a mobile number is refused with a hint to use the code', $r['step'] === 'c_code' && str_contains((string) $r['error'], 'ID card'));
$r = CodeLogin::enter('');
check('an empty code is refused', $r['step'] === 'c_code' && isset($r['error']));

/* ------------------------------------------------------------------ */
section('Existing PIN (4 digits, set before) and pattern');
fresh();
$r = CodeLogin::enter($code(990));
check('code of a PIN account -> unlock', $r['step'] === 'c_unlock' && CodeLogin::shownCode() === $code(990));
check('lowercase / no dash finds the same account', (function () use ($aPin) { $_SESSION = []; CodeLogin::enter('shg990'); return (int) CodeLogin::state()['aid'] === $aPin; })());
$r = CodeLogin::unlock('pin', '1111');
check('wrong PIN: generic error, no session', isset($r['error']) && sid() === 0);
$r = CodeLogin::unlock('pin', '4826');
check('right existing PIN opens THAT account', ($r['go'] ?? '') === 'home' && sid() === $aPin);
check('session role comes from the account (agent)', ($_SESSION[ADMIN_SESSION_KEY]['role'] ?? '') === 'agent');
check('dashboard = the one home, filtered by role', Auth::homeUrl() === '/admin/home.php');
check('door state is gone after sign-in', CodeLogin::state() === null);
fresh();
CodeLogin::enter($code(991));
$r = CodeLogin::unlock('pin', '4826');
check('PIN tab on a pattern-only account does not open it', sid() === 0);
$r = CodeLogin::unlock('pattern', '0-4-8-5');
check('right pattern opens the counter account', ($r['go'] ?? '') === 'home' && sid() === $aPat && ($_SESSION[ADMIN_SESSION_KEY]['role'] ?? '') === 'counter');

/* ------------------------------------------------------------------ */
section('Attempt limit + progressive cooldown');
fresh();
CodeLogin::enter($code(990));
for ($i = 0; $i < QuickUnlock::MAX_FAILS; $i++) {
    $r = CodeLogin::unlock('pin', '9' . $i . '7351');
}
$row = Database::fetch('SELECT unlock_locked_until, unlock_strikes FROM admins WHERE id = :i', ['i' => $aPin]);
$mins = (int) round((strtotime((string) $row['unlock_locked_until']) - time()) / 60);
check('5 wrong PINs lock for 15 min (strike 1)', (int) $row['unlock_strikes'] === 1 && $mins >= 14 && $mins <= 15, $mins . ' min');
check('the screen says how long', str_contains((string) ($r['error'] ?? ''), 'minutes'));
$r = CodeLogin::unlock('pin', '4826');
check('even the right PIN waits while locked', sid() === 0);
Database::update('admins', ['unlock_locked_until' => null], 'id = :i', ['i' => $aPin]);
fresh();
CodeLogin::enter($code(990));
for ($i = 0; $i < QuickUnlock::MAX_FAILS; $i++) {
    CodeLogin::unlock('pin', '8' . $i . '6142');
}
$row = Database::fetch('SELECT unlock_locked_until, unlock_strikes FROM admins WHERE id = :i', ['i' => $aPin]);
$mins = (int) round((strtotime((string) $row['unlock_locked_until']) - time()) / 60);
check('the next lock doubles to 30 min (strike 2)', (int) $row['unlock_strikes'] === 2 && $mins >= 29 && $mins <= 30, $mins . ' min');
check('cooldown is capped at 24 h', QuickUnlock::lockMinutes(20) === 1440 && QuickUnlock::lockMinutes(0) === 15);
Database::update('admins', ['unlock_locked_until' => null], 'id = :i', ['i' => $aPin]);
fresh();
CodeLogin::enter($code(990));
CodeLogin::unlock('pin', '4826');
check('a right PIN resets the strikes', (int) Database::scalar('SELECT unlock_strikes FROM admins WHERE id = :i', ['i' => $aPin], 9) === 0);

/* ------------------------------------------------------------------ */
section('Switched-off (blocked) account');
fresh();
$r = CodeLogin::enter($code(995));
check('a switched-off account gets the unlock screen (no setup)', $r['step'] === 'c_unlock');
$r = CodeLogin::unlock('pin', '0000');
check('wrong PIN: the generic answer', str_contains((string) $r['error'], 'did not match') && sid() === 0);
$r = CodeLogin::unlock('pin', '5937');
check('its own PIN: "switched off", still no session', str_contains((string) $r['error'], 'switched off') && sid() === 0);

/* ------------------------------------------------------------------ */
section('First-time setup: DOB + mobile, then the WhatsApp code');
fresh();
$r = CodeLogin::enter($code(992));
check('no PIN / pattern yet -> setup screen', $r['step'] === 'c_setup');
$r = CodeLogin::createFirst('pin', '482613');
check('cannot choose a PIN before any proof', $r['step'] === 'c_code' && !QuickUnlock::status($aNew)['pin']);
$bad = CodeLogin::identity('1995-07-08', $PH[2]);
check('wrong DOB: same reply as a match (nothing confirmed)', $bad['step'] === 'c_prove' && isset($bad['notice']));
$CODES = [];
CodeLogin::codeSend();
check('wrong DOB: no code is sent at all', $CODES === []);
CodeLogin::hiStart();
check('wrong DOB: the "hi" link is a decoy (no wa_verify row)', !empty($_SESSION['code_login']['wa']['decoy'])
    && !Database::exists('SELECT 1 FROM wa_verify WHERE token = :t', ['t' => (string) $_SESSION['code_login']['wa']['token']]));
check('decoy "I have sent it" proves nothing', CodeLogin::hiDone()['step'] === 'c_prove' && !CodeLogin::proved());
$good = CodeLogin::identity('1995-07-07', '+91 70000 01103');
check('right DOB + mobile: the very same reply text', $good['step'] === 'c_prove' && $good['notice'] === $bad['notice']);
$r = CodeLogin::createFirst('pin', '482613');
check('DOB + mobile alone still cannot set a PIN', $r['step'] === 'c_code' && !QuickUnlock::status($aNew)['pin']);
fresh();
CodeLogin::enter($code(992));
CodeLogin::identity('1995-07-07', $PH[2]);
$CODES = [];
CodeLogin::codeSend();
$sent = (string) ($CODES['91' . $PH[2]] ?? '');
check('the code goes to the REGISTERED WhatsApp number', $sent !== '' && count($CODES) === 1, implode(',', array_keys($CODES)));
check('a wrong code is refused', CodeLogin::codeCheck('000000')['step'] === 'c_prove' && !CodeLogin::proved());
$r = CodeLogin::codeCheck($sent);
check('the right code -> choose PIN / pattern', $r['step'] === 'c_create' && CodeLogin::proved());
check('PIN 123456 (too easy) refused', isset(CodeLogin::createFirst('pin', '123456')['error']));
check('a 4-digit PIN is refused for a NEW PIN', isset(CodeLogin::createFirst('pin', '4826')['error']));
$r = CodeLogin::createFirst('pin', '482613');
check('6-digit PIN -> confirm step', $r['step'] === 'c_confirm');
check('the first entry waits as a hash, not the digits', !str_contains(json_encode($_SESSION), '482613'));
$r = CodeLogin::createConfirm('482614');
check('a different confirm -> back to choose, nothing saved', $r['step'] === 'c_create' && !QuickUnlock::status($aNew)['pin']);
CodeLogin::createFirst('pin', '482613');
$r = CodeLogin::createConfirm('482613');
check('matching confirm saves and opens the dashboard', ($r['go'] ?? '') === 'home' && sid() === $aNew);
$h = (string) Database::scalar('SELECT pin_hash FROM admins WHERE id = :i', ['i' => $aNew], '');
check('PIN stored only as bcrypt', $h !== '482613' && str_starts_with($h, '$2') && password_verify('482613', $h));
check('audit: setup_done written, without the PIN', (function () use ($aNew): bool {
    $r = Database::fetch("SELECT * FROM audit_logs WHERE action = 'admin.login.setup_done' AND entity_id = :i ORDER BY id DESC LIMIT 1", ['i' => (string) $aNew]);
    return $r !== null && !str_contains(json_encode($r), '482613');
})());
fresh();
CodeLogin::enter($code(992));
check('next time: code -> PIN screen (no DOB / mobile / OTP again)', (CodeLogin::state()['purpose'] ?? '') !== 'setup');
check('... and the new PIN opens it', (CodeLogin::unlock('pin', '482613')['go'] ?? '') === 'home' && sid() === $aNew);

/* ------------------------------------------------------------------ */
section('First-time setup by "hi" + YES from the registered WhatsApp');
fresh();
CodeLogin::enter($code(993));
CodeLogin::identity('1993-03-03', $PH[3]);
CodeLogin::hiStart();
$tok = (string) ($_SESSION['code_login']['wa']['token'] ?? '');
check('a real hi token for the registered number', $tok !== '' && empty($_SESSION['code_login']['wa']['decoy'])
    && (string) Database::scalar('SELECT intl FROM wa_verify WHERE token = :t', ['t' => $tok], '') === '91' . $PH[3]);
WaVerify::inbound('919999999999', 'hi VERIFY ' . $tok . ' — PASSWORD RESET');
WaVerify::inbound('919999999999', 'YES');
check('hi + YES from ANOTHER number proves nothing', CodeLogin::hiDone()['step'] === 'c_prove' && !CodeLogin::proved());
WaVerify::inbound('91' . $PH[3], 'hi VERIFY ' . $tok . ' — PASSWORD RESET');
check('the hi alone is not enough (waits for YES)', CodeLogin::hiDone()['step'] === 'c_prove' && !CodeLogin::proved());
WaVerify::inbound('91' . $PH[3], 'YES');
$r = CodeLogin::hiDone();
check('hi + YES from the registered number -> choose PIN', $r['step'] === 'c_create' && CodeLogin::proved());
CodeLogin::createFirst('pattern', '2-4-6-8');
$r = CodeLogin::createConfirm('2-4-6-8');
check('pattern chosen + confirmed opens the dashboard', ($r['go'] ?? '') === 'home' && sid() === $aHi && QuickUnlock::status($aHi)['pattern']);

/* ------------------------------------------------------------------ */
section('Missing DOB -> office activation (admin-assisted)');
fresh();
CodeLogin::enter($code(994));
$r = CodeLogin::identity('1990-01-01', $PH[4]);
check('missing DOB: same reply, but not usable', $r['step'] === 'c_prove' && empty($_SESSION['code_login']['idok']));
$CODES = [];
CodeLogin::codeSend();
check('missing DOB: no WhatsApp code is sent', $CODES === []);
$r = CodeLogin::request('call me on the counter phone');
$req = Database::fetch("SELECT * FROM login_recovery_requests WHERE admin_id = :a ORDER BY id DESC LIMIT 1", ['a' => $aBare]);
check('request reaches the office with the reason', $req !== null && $req['status'] === 'open' && $req['reason'] === 'no_details' && (int) $req['details_match'] === 0);
check('a second request does not pile up', (function () use ($aBare): bool { CodeLogin::request(''); return (int) Database::scalar("SELECT COUNT(*) FROM login_recovery_requests WHERE admin_id = :a AND status = 'open'", ['a' => $aBare], 0) === 1; })());
check('activation before the office approves: refused', CodeLogin::activation('ABCD-EFGH')['step'] === 'c_prove' && !CodeLogin::proved());
// the office approves (what admin/login-requests.php writes)
Database::update('login_recovery_requests', ['status' => 'approved', 'act_hash' => Security::hashPassword('K7QM2XPA'),
    'act_expires' => date('Y-m-d H:i:s', time() + 86400), 'decided_by' => 1, 'decided_at' => date('Y-m-d H:i:s')], 'id = :i', ['i' => (int) $req['id']]);
check('a wrong activation code is refused', !CodeLogin::proved() && isset(CodeLogin::activation('K7QM2XPB')['error']));
$r = CodeLogin::activation('k7qm-2xpa');
check('the office code (any case, dash) -> choose PIN', $r['step'] === 'c_create' && CodeLogin::proved());
check('the activation code is used up', Database::scalar('SELECT status FROM login_recovery_requests WHERE id = :i', ['i' => (int) $req['id']], '') === 'used');
fresh();
CodeLogin::enter($code(990));   // someone else's code in the same browser
check("another account's activation code does not work", isset(CodeLogin::activation('K7QM2XPA')['error']) && !CodeLogin::proved());

/* ------------------------------------------------------------------ */
section('Forgot PIN -> verified -> new pattern; the old PIN stops');
fresh();
CodeLogin::enter($code(990));
$r = CodeLogin::forgot();
check('"Forgot PIN / Pattern?" -> DOB + mobile', $r['step'] === 'c_forgot');
CodeLogin::identity('1990-04-12', $PH[0]);
$CODES = [];
CodeLogin::codeSend();
CodeLogin::codeCheck((string) ($CODES['91' . $PH[0]] ?? 'x'));
check('proved through the registered WhatsApp', CodeLogin::proved());
CodeLogin::createFirst('pattern', '6-7-8-5-2');
$r = CodeLogin::createConfirm('6-7-8-5-2');
check('new pattern saved and signed in', ($r['go'] ?? '') === 'home' && sid() === $aPin);
check('the forgotten PIN was removed', QuickUnlock::status($aPin) === ['pin' => false, 'pattern' => true]);
fresh();
CodeLogin::enter($code(990));
check('old PIN no longer opens it', sid() === 0 && isset(CodeLogin::unlock('pin', '4826')['error']));
check('new pattern does', (CodeLogin::unlock('pattern', '6-7-8-5-2')['go'] ?? '') === 'home' && sid() === $aPin);

/* ------------------------------------------------------------------ */
section('Office 2FA still asked after the PIN');
fresh();
CodeLogin::enter($code(996));
$r = CodeLogin::unlock('pin', '602718');
check('2FA account: PIN -> WhatsApp code step, NOT signed in yet', ($r['step'] ?? '') === 'admin_otp' && sid() === 0
    && (int) ($_SESSION['admin_2fa_pending']['id'] ?? 0) === $a2fa);

/* ------------------------------------------------------------------ */
section('Compulsory PIN gate, remembered device, change account');
Auth::$pinGateInCli = true;
$_SESSION = [ADMIN_SESSION_KEY => ['id' => $aBare]];
check('signed in with no PIN / pattern -> must set one first', Auth::pinSetupNeeded($aBare));
$_SESSION = [ADMIN_SESSION_KEY => ['id' => $aNew]];
check('an account with a PIN goes straight on', !Auth::pinSetupNeeded($aNew) && !empty($_SESSION[ADMIN_SESSION_KEY]['qk_ok']));
Settings::set('login_pin_required', false, 'bool', 'security', false);
$_SESSION = [ADMIN_SESSION_KEY => ['id' => $aBare]];
check('switch login_pin_required off -> no gate', !Auth::pinSetupNeeded($aBare));
Settings::set('login_pin_required', true, 'bool', 'security', false);
Auth::$pinGateInCli = false;

$sig = static function (string $p): string {
    $key = defined('APP_KEY') ? (string) APP_KEY : (defined('DB_PASS') ? 'shg-qk|' . DB_PASS : 'shg-qk');
    return substr(hash_hmac('sha256', 'qk|' . $p, $key), 0, 32);
};
fresh();
$p = $aNew . '.' . (time() + 3600);
$_COOKIE[QuickUnlock::DEVICE_COOKIE] = $p . '.' . $sig($p);
$r = CodeLogin::enterRemembered();
check('remembered phone: opens on its own code, PIN screen', $r['step'] === 'c_unlock' && (int) CodeLogin::state()['aid'] === $aNew && CodeLogin::shownCode() === $code(992));
check('the cookie holds an id + signature only (no PIN)', !str_contains((string) $_COOKIE[QuickUnlock::DEVICE_COOKIE], '482613'));
fresh();
$_COOKIE[QuickUnlock::DEVICE_COOKIE] = $aNew . '.' . (time() + 3600) . '.' . str_repeat('a', 32);
check('a forged device cookie is ignored', CodeLogin::enterRemembered()['step'] === 'c_code' && CodeLogin::state() === null);
unset($_COOKIE[QuickUnlock::DEVICE_COOKIE]);
fresh();
CodeLogin::enter($code(990));
CodeLogin::clear();
check('"Change account" leaves no account behind', CodeLogin::state() === null && isset(CodeLogin::unlock('pin', '4826')['error']));

/* ------------------------------------------------------------------ */
section('Records untouched');
$after = Database::fetchAll("SELECT a.id, a.phone, a.role, m.dob FROM admins a LEFT JOIN ap_members m ON m.admin_id = a.id WHERE a.username LIKE '{$PFX}%' ORDER BY a.id");
check('DOB, mobile and role of every account unchanged', $before === $after);
check('no PIN / pattern / code in the app log', (function (): bool {
    try {
        $rows = Database::fetchAll("SELECT message, context FROM app_logs WHERE created_at > NOW() - INTERVAL 10 MINUTE");
    } catch (Throwable $e) {
        return true;
    }
    $all = json_encode($rows);
    return !str_contains($all, '482613') && !str_contains($all, '602718') && !str_contains($all, 'K7QM2XPA');
})());

$cleanup();
echo "\n" . ($FAIL === 0 ? "\033[32mALL PASS\033[0m" : "\033[31mFAILED: $FAIL\033[0m") . " ($PASS passed)\n";
exit($FAIL === 0 ? 0 : 1);
