<?php
/**
 * =====================================================================
 *  code-login-office-test.php — 15 Nov 2026 (owner: "number system jhau
 *  bhayo, hatau — sabai control admin le, reset dina milne, forget lai
 *  security question").
 *
 *  The code door with the mobile + WhatsApp proof OFF (the default):
 *    - first time: code -> the office opens it (1 h, one use) -> own PIN
 *    - forgot: code -> security question -> new PIN; no question -> office
 *    - no WhatsApp code / hi is ever started from the code door
 *  On the LOCAL shari_test database with throwaway accounts (deleted before
 *  and after).
 *
 *     php -c .claude/php-dev.ini tests/code-login-office-test.php
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
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'code_login%' OR bucket LIKE 'quick_unlock%' OR bucket LIKE 'wa_verify%' OR bucket LIKE 'otp\\_%'");
}

echo "\n=== Code door without the mobile: office opens it, security question for forgot ===\n\n";
if (!CodeLogin::ready() || !QuickUnlock::secReady()) {
    echo "  SKIP  run database/upgrade-2026-11-15-code-login.sql first\n";
    exit(1);
}
$keep = [];
foreach (['quick_unlock_on', 'login_code_first_on', 'login_pin_required', 'code_login_wa_on', 'whatsapp_driver'] as $k) {
    $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
}
register_shutdown_function(static function () use ($keep): void {
    foreach ($keep as $k => $row) {
        $row === null ? Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k])
                      : Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => (string) $row['svalue'], 'k' => $k]);
    }
    Settings::flush();
});
Settings::set('quick_unlock_on', true, 'bool', 'security', false);
Settings::set('login_code_first_on', true, 'bool', 'security', false);
Settings::set('login_pin_required', true, 'bool', 'security', false);
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);
Database::run("DELETE FROM settings WHERE skey = 'code_login_wa_on'");   // the default door
Settings::flush();
CodeLogin::$evenTiming = false;
$SENT = 0;
CodeLogin::$deliverHook = static function () use (&$SENT): bool { $SENT++; return true; };

$PFX = 'clo_';
$cleanup = static function () use ($PFX): void {
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE '{$PFX}%'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['admin_profiles', 'admin_sessions', 'login_recovery_requests'] as $t) {
            try { Database::delete($t, 'admin_id = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
};
$cleanup();
register_shutdown_function($cleanup);

$mk = static function (string $u, string $role, string $phone, ?int $agentCode = null) use ($PFX): int {
    $id = (int) Database::insert('admins', [
        'username' => $PFX . $u, 'password_hash' => password_hash('Pw@' . $u . '9z', PASSWORD_BCRYPT),
        'full_name' => 'Office Door ' . $u, 'role' => $role, 'phone' => $phone, 'is_active' => 1, 'must_change_pw' => 0,
    ]);
    if ($agentCode !== null) {
        AgentWallet::setAgentCode($id, $agentCode);
    }
    return $id;
};
$code = static fn(int $n): string => 'SHG-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
$aNew  = $mk('new', 'agent', '', 980);              // first time, NO mobile on file
$aSec  = $mk('sec', 'agent', '7000003302', 981);    // has PIN + security question
$aNoQ  = $mk('noq', 'counter', '', 982);            // has PIN, no question
$aOff  = $mk('off', 'agent', '', 983);              // switched off
$aMgr  = $mk('mgr', 'manager', '7000003305');
QuickUnlock::set($aSec, 'pin', '482611');
QuickUnlock::secSet($aSec, QuickUnlock::SEC_QUESTIONS[array_key_first(QuickUnlock::SEC_QUESTIONS)] ?? 'mother', 'Sita Devi');
QuickUnlock::set($aNoQ, 'pin', '730194');
Database::update('admins', ['is_active' => 0], 'id = :i', ['i' => $aOff]);

/* ------------------------------------------------------------------ */
section('The mobile + WhatsApp proof is off by default');
check('waOn() is false with no setting', CodeLogin::waOn() === false);

/* ------------------------------------------------------------------ */
section('First time without the office: no mobile asked, no WhatsApp');
fresh();
$r = CodeLogin::enter($code(980));
check('a new account goes to first-time setup', $r['step'] === 'c_setup', json_encode($r));
$r = CodeLogin::identity('', '9812345678');
check('typing a mobile does not open anything: ask the office', $r['step'] === 'c_setup' && str_contains((string) ($r['error'] ?? ''), 'office'), json_encode($r));
$r = CodeLogin::hiStart();
check('the WhatsApp hi cannot start', isset($r['error']) && !is_array($_SESSION[CodeLogin::KEY]['wa'] ?? null));
$r = CodeLogin::codeSend();
check('no WhatsApp code is sent', isset($r['error']) && $SENT === 0);
$r = CodeLogin::openCheck();
check('"office opened it" before the office did: still waiting', $r['step'] === 'c_setup' && str_contains((string) ($r['error'] ?? ''), 'not opened'));
check('no PIN can be chosen yet', !CodeLogin::proved() && CodeLogin::createFirst('pin', '582014')['step'] === 'c_code');
$r = CodeLogin::request('call me on the counter phone');
check('"ask the office" makes a request', str_contains((string) ($r['notice'] ?? ''), 'office')
    && Database::exists("SELECT 1 FROM login_recovery_requests WHERE admin_id = :a AND status = 'open'", ['a' => $aNew]));

/* ------------------------------------------------------------------ */
section('The office opens it (1 hour, one use)');
$rid = (int) Database::scalar("SELECT id FROM login_recovery_requests WHERE admin_id = :a AND status = 'open'", ['a' => $aNew], 0);
check('office opens the request', CodeLogin::officeOpen($aNew, $aMgr, $rid) === true);
check('the request row is now "opened" (no new row)', (string) Database::scalar('SELECT status FROM login_recovery_requests WHERE id = :i', ['i' => $rid], '') === 'opened'
    && (int) Database::scalar('SELECT COUNT(*) FROM login_recovery_requests WHERE admin_id = :a', ['a' => $aNew], 0) === 1);
$until = CodeLogin::openUntil($aNew);
$mins = $until !== '' ? (int) round((strtotime($until) - time()) / 60) : 0;
check('open for about an hour', $mins >= 59 && $mins <= 60, $mins . ' min');
check('audited', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'admin.login.office_opened' AND entity_id = :i", ['i' => (string) $aNew]));
$r = CodeLogin::openCheck();
check('"office opened it — continue" goes to the PIN', $r['step'] === 'c_create' && CodeLogin::proved(), json_encode($r));
check('the opening is used up', (string) Database::scalar('SELECT status FROM login_recovery_requests WHERE id = :i', ['i' => $rid], '') === 'used' && CodeLogin::openUntil($aNew) === '');
CodeLogin::createFirst('pin', '582014');
$r = CodeLogin::createConfirm('582014');
check('own PIN saved and signed in', ($r['go'] ?? '') === 'home' && sid() === $aNew, json_encode($r) . ' sid=' . sid());
check('the PIN works next time', (function () use ($code) { fresh(); CodeLogin::enter($code(980)); return (CodeLogin::unlock('pin', '582014')['go'] ?? '') === 'home'; })());
fresh();
CodeLogin::enter($code(983));
check('used once: a second "continue" does not open again', (function () use ($code, $aNew) {
    fresh(); $_SESSION[CodeLogin::KEY] = ['aid' => $aNew, 'code' => $code(980), 't' => time(), 'purpose' => 'reset'];
    return CodeLogin::openCheck()['step'] === 'c_forgot';
})());

/* ------------------------------------------------------------------ */
section('Opened from the account list: typing the code goes straight to the PIN');
Database::run('UPDATE admins SET pin_hash = NULL, pattern_hash = NULL WHERE id = :i', ['i' => $aNew]);
CodeLogin::officeOpen($aNew, $aMgr);
fresh();
$r = CodeLogin::enter($code(980));
check('code -> c_create at once (no extra tap)', $r['step'] === 'c_create' && CodeLogin::proved(), json_encode($r));

section('An expired or switched-off opening does nothing');
CodeLogin::officeOpen($aNew, $aMgr);
Database::run("UPDATE login_recovery_requests SET act_expires = NOW() - INTERVAL 1 MINUTE WHERE admin_id = :a AND status = 'opened'", ['a' => $aNew]);
fresh();
check('expired: back to first-time setup', CodeLogin::enter($code(980))['step'] === 'c_setup');
CodeLogin::officeOpen($aOff, $aMgr);
fresh();
$r = CodeLogin::enter($code(983));
check('switched-off account: no PIN screen even when opened', $r['step'] !== 'c_create' && !CodeLogin::proved(), json_encode($r));

/* ------------------------------------------------------------------ */
section('Forgot PIN: the security question alone');
fresh();
CodeLogin::enter($code(981));
$r = CodeLogin::forgot();
check('forgot -> the question screen', $r['step'] === 'c_forgot' && CodeLogin::forgotQuestion() !== '');
$r = CodeLogin::identity('', '', 'wrong answer');
check('a wrong answer: "did not match", no PIN', str_contains((string) ($r['error'] ?? ''), 'did not match') && !CodeLogin::proved());
$r = CodeLogin::identity('', '', '');
check('an empty answer is asked for', str_contains((string) ($r['error'] ?? ''), 'answer'));
$r = CodeLogin::identity('', '', '  sita devi ');
check('the right answer (any case / spaces) -> new PIN', $r['step'] === 'c_create' && CodeLogin::proved(), json_encode($r));
check('no mobile was needed and nothing went to WhatsApp', $SENT === 0);
CodeLogin::createFirst('pin', '903417');
$r = CodeLogin::createConfirm('903417');
check('new PIN saved and signed in', ($r['go'] ?? '') === 'home' && sid() === $aSec);
check('the old PIN stops working', (function () use ($code) { fresh(); CodeLogin::enter($code(981)); return empty(CodeLogin::unlock('pin', '482611')['go']); })());
check('the new PIN works', (function () use ($code) { fresh(); CodeLogin::enter($code(981)); return (CodeLogin::unlock('pin', '903417')['go'] ?? '') === 'home'; })());

section('Security question: 5 tries a day per account');
fresh();
CodeLogin::enter($code(981));
CodeLogin::forgot();
for ($i = 0; $i < 5; $i++) {
    $_SESSION[CodeLogin::KEY]['t'] = time();
    CodeLogin::identity('', '', 'nope ' . $i);
    Database::run("DELETE FROM rate_limits WHERE bucket = 'code_login_id_ip'");
}
$r = CodeLogin::identity('', '', 'Sita Devi');
check('after 5 misses even the right answer waits', !CodeLogin::proved() && str_contains((string) ($r['error'] ?? ''), 'Too many'), json_encode($r));

section('Forgot without a question: the office');
fresh();
CodeLogin::enter($code(982));
$r = CodeLogin::forgot();
check('no question: forgot screen, nothing to answer', $r['step'] === 'c_forgot' && CodeLogin::forgotQuestion() === '');
$r = CodeLogin::identity('', '', 'anything');
check('an answer cannot open it', !CodeLogin::proved() && str_contains((string) ($r['error'] ?? ''), 'office'));
CodeLogin::officeOpen($aNoQ, $aMgr);
fresh();
CodeLogin::enter($code(982));
$r = CodeLogin::forgot();
check('office opened a reset: forgot -> new PIN at once', $r['step'] === 'c_create' && CodeLogin::proved(), json_encode($r));
CodeLogin::createFirst('pattern', '0-1-2-5-8');
$r = CodeLogin::createConfirm('0-1-2-5-8');
check('reset by pattern; signed in', ($r['go'] ?? '') === 'home' && sid() === $aNoQ);
check('the forgotten PIN was removed', QuickUnlock::status($aNoQ)['pin'] === false && QuickUnlock::status($aNoQ)['pattern'] === true);

/* ------------------------------------------------------------------ */
section('The unlock screen WhatsApp code is off too');
fresh();
CodeLogin::enter($code(981));
$r = CodeLogin::waLoginSend();
check('waLoginSend refuses', $r['step'] === 'c_unlock' && isset($r['error']) && empty($_SESSION[CodeLogin::KEY]['wl']));

section('Switched back on (code_login_wa_on = 1): the mobile step returns');
Settings::set('code_login_wa_on', true, 'bool', 'security', false);
fresh();
CodeLogin::enter($code(981));
CodeLogin::forgot();
$r = CodeLogin::identity('', '', 'Sita Devi');
check('with the switch on, the mobile is asked again', $r['step'] === 'c_forgot' && str_contains((string) ($r['error'] ?? ''), 'mobile'), json_encode($r));
Database::run("DELETE FROM settings WHERE skey = 'code_login_wa_on'");
Settings::flush();

echo "\n" . ($FAIL === 0 ? "ALL PASS: $PASS" : "FAILED: $FAIL (passed $PASS)") . "\n";
exit($FAIL === 0 ? 0 : 1);
