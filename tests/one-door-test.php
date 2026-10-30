<?php
/**
 * =====================================================================
 *  one-door-test.php — 30 Oct 2026 (owner: "login sabai ko lagi eutai,
 *  minimalistic; number → WhatsApp hi / OTP, natra ID + password; forgot
 *  WhatsApp bata; euta dashboard"). Against the TEST database; everything
 *  it makes is deleted again.
 *
 *    - what the one box means (mobile / ID) — no lookup
 *    - mobile + password: the password picks the right one of 2 IDs; the
 *      same password on 2 → "which ID?"; a pick outside the list is refused
 *    - ID (agent code / username / email) + password; office 2FA kept
 *    - WhatsApp code: every number the same reply; 2-3 IDs → chooser; never
 *      an office account
 *    - "hi VERIFY … LOGIN": strict sender, LOGIN word, single use
 *    - forgot via WhatsApp: the code proves the number, ONE chosen ID gets
 *      the new password; a manager can reset; 2FA kept; wrong-code lockout
 *    - every sign-in lands on admin/home.php; the switch off = the old map
 *    - admin/home.php tiles per role, rendered for every role (no warnings)
 *    - over HTTP (when a dev server answers): the page, the landings,
 *      /me/ + /team/ redirects, next= / back=, tile pages open (not 403)
 *
 *    php tests/one-door-test.php [http://idc.localhost:8951]
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: render admin/home.php for one account with a hand-built session ---- */
if (($argv[1] ?? '') === '--render') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME']    = '/admin/home.php';
    $_SERVER['REQUEST_URI']    = '/admin/home.php';
    $_SERVER['HTTP_HOST']      = 'localhost';
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    ob_start();
    @session_start();
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($argv[2] ?? 0)]);
    if ($a === null) { echo "NO ACCOUNT\n"; exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
        'must_change_pw' => false, 'via_otp' => false,
    ];
    register_shutdown_function(static function (): void { $o = (string) ob_get_clean(); echo $o; });
    require dirname(__DIR__) . '/admin/home.php';
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/onedoor.php';
require_once INCLUDE_PATH . '/homeboard.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/notify.php';

set_exception_handler(static function (Throwable $e): void {
    echo "\n  \033[31mUNCAUGHT\033[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\nFAILED: 1 (uncaught)\n";
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
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%' OR bucket LIKE 'team_login%' OR bucket LIKE 'agent_otp%'
        OR bucket LIKE 'otp\\_%' OR bucket LIKE 'wa_verify%' OR bucket LIKE 'team_reset%' OR bucket LIKE 'one_door%' OR bucket LIKE 'admin_2fa%'");
}
function pwOf(int $id): string { return (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $id], ''); }

$PH  = '7000000811';   // agent A + counter C, each with its own password
$PHS = '7000000812';   // agent B + counter D, the SAME password
$PHM = '7000000813';   // a manager
$PHX = '7000000814';   // a superadmin with 2FA
$PHN = '7000000819';   // nobody
$PHONES = [$PH, $PHS, $PHM, $PHX, $PHN];

$keep = [];
foreach (['whatsapp_driver' => ['click_to_chat', 'string'], 'login_one_door_on' => ['1', 'bool'], 'login_one_door_hi_on' => ['1', 'bool'],
          'admin_2fa_ids' => [null, 'json']] as $k => [$v, $type]) {
    $keep[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
    if ($v !== null) {
        Settings::set($k, $v, $type, 'security', false);
    }
}
Settings::flush();
/* the settings come back even when the run dies half-way (a 2FA id left behind would stay in admin_2fa_ids) */
$restored = false;
$restore = static function () use (&$keep, &$restored): void {
    if ($restored) {
        return;
    }
    $restored = true;
    foreach ($keep as $k => $row) {
        if ($row === null) {
            Database::delete('settings', 'skey = :k', ['k' => $k]);
        } else {
            Database::update('settings', ['svalue' => $row['svalue'], 'stype' => $row['stype']], 'skey = :k', ['k' => $k]);
        }
    }
    Settings::flush();
};
register_shutdown_function($restore);
OneDoor::$evenTiming = false;
$CODES = [];
TeamPortal::$deliverHook = static function (string $intl, string $code) use (&$CODES): bool { $CODES[$intl] = $code; return true; };

$cleanup = static function () use ($PHONES): void {
    $in = "'" . implode("','", $PHONES) . "'";
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'od1\\_%'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'admin_sessions' => 'admin_id', 'agent_ledger' => 'agent_admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    try { Database::run("DELETE FROM wa_verify WHERE phone IN ($in)"); } catch (Throwable $e) {}
    Database::run("DELETE FROM otp_codes WHERE identifier LIKE 'agent:7000000%' OR identifier LIKE 'team:917000000%' OR identifier LIKE 'admin:%' AND created_at > NOW() - INTERVAL 1 DAY AND ip_address IN ('127.0.0.1','::1','')");
    Database::run("DELETE FROM rate_limits WHERE identifier IN ($in) OR bucket LIKE 'admin_login%' OR bucket LIKE 'team_login%' OR bucket LIKE 'agent_otp%'
        OR bucket LIKE 'otp\\_%' OR bucket LIKE 'wa_verify%' OR bucket LIKE 'team_reset%' OR bucket LIKE 'one_door%' OR bucket LIKE 'admin_2fa%'");
};
$cleanup();

$mk = static function (string $user, string $role, ?string $phone, string $pw, string $name = '') : int {
    return (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
        'full_name' => $name !== '' ? $name : 'OD1 ' . ucfirst($role), 'phone' => $phone !== null ? '+91 ' . $phone : null,
        'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
};
$A = $mk('od1_agent', 'agent', $PH, 'AgentOne#2026', 'OD1 Ramesh Agent');
$C = $mk('od1_counter', 'counter', $PH, 'CounterOne#2026', 'OD1 Ramesh Counter');
$B = $mk('od1_agent2', 'agent', $PHS, 'SamePass#2026', 'OD1 Sita Agent');
$D = $mk('od1_counter2', 'counter', $PHS, 'SamePass#2026', 'OD1 Sita Counter');
$M = $mk('od1_manager', 'manager', $PHM, 'Manager#2026', 'OD1 Hari Manager');
$X = $mk('od1_super', 'superadmin', $PHX, 'Super#2026', 'OD1 Boss');
$ROLES = ['agent' => $A, 'counter' => $C, 'manager' => $M, 'superadmin' => $X];
foreach (['accountant', 'support', 'scanner', 'official', 'viewer', 'operations'] as $r) {
    $ROLES[$r] = $mk('od1_' . $r, $r, null, 'Role#' . $r . '2026');
}
Database::update('admins', ['email' => 'od1.manager@example.test'], 'id = :i', ['i' => $M]);
$codeA = (int) AgentWallet::nextFreeAgentCode();
AgentWallet::setAgentCode($A, $codeA);
$labelA = AgentWallet::agentCodeLabel($A);
Auth::setAdmin2fa($X, true);

/* =================================================================== */
section('the one box: mobile or ID (no lookup)');
$c = OneDoor::classify('98 7654 3210', '91');
check('a spaced mobile is a mobile', $c['kind'] === 'mobile' && $c['phone'] === '9876543210' && $c['cc'] === '91');
$c = OneDoor::classify('+977 9812345678', '91');
check('+977 picks Nepal by itself', $c['kind'] === 'mobile' && $c['phone'] === '9812345678' && $c['cc'] === '977');
foreach (['SHG-0029', '29', 'SHG-S-0003', 'od1_agent', '9876543210-2', 'a@b.test'] as $id) {
    check("'$id' is an ID", OneDoor::classify($id, '91')['kind'] === 'id');
}
check('an empty box asks again', OneDoor::who('', '91')['step'] === 'start');
check('a placeholder number is not a mobile', OneDoor::classify('0000000000', '91')['kind'] === 'id');

section('ID → username (agent code, number, email)');
check('agent code label → that agent', Auth::resolveLoginId($labelA) === 'od1_agent', $labelA);
check('the bare number → that agent', Auth::resolveLoginId((string) $codeA) === 'od1_agent');
check('an email → its account', Auth::resolveLoginId('OD1.Manager@example.test') === 'od1_manager');
check('anything else passes through', Auth::resolveLoginId('somebody') === 'somebody');

/* =================================================================== */
section('mobile + password: the password picks the account');
fresh();
check('the mobile step comes next', OneDoor::who($PH, '91')['step'] === 'mobile');
$r = OneDoor::password('CounterOne#2026');
check('the counter password opens the COUNTER', ($r['go'] ?? '') === 'home' && sid() === $C, json_encode($r, JSON_UNESCAPED_UNICODE));
check('…a member session, not via OTP', ($_SESSION[ADMIN_SESSION_KEY]['via_otp'] ?? true) === false && ($_SESSION[ADMIN_SESSION_KEY]['role'] ?? '') === 'counter');
check('…the door state is gone', OneDoor::state() === null);
fresh(); OneDoor::who($PH, '91');
$r = OneDoor::password('AgentOne#2026');
check('the agent password opens the AGENT on the same mobile', ($r['go'] ?? '') === 'home' && sid() === $A);
fresh(); OneDoor::who($PH, '91');
$r = OneDoor::password('Wrong#2026x');
check('a wrong password: no session, same step, an error', sid() === 0 && ($r['step'] ?? '') === 'mobile' && ($r['error'] ?? '') !== '');

section('the same password on 2 IDs → which ID?');
fresh(); OneDoor::who($PHS, '91');
$r = OneDoor::password('SamePass#2026');
$ids = array_map(static fn(array $p): int => (int) $p['id'], OneDoor::pickList());
sort($ids);
check('asks which ID', ($r['step'] ?? '') === 'pick' && sid() === 0);
check('…only the two IDs of this number', $ids === [min($B, $D), max($B, $D)], json_encode($ids));
$r = OneDoor::pick($A);
check('a pick outside the list is refused', empty($r['go']) && sid() === 0);
$r = OneDoor::pick($D);
check('picking the counter opens it', ($r['go'] ?? '') === 'home' && sid() === $D);

section('ID + password (office 2FA kept)');
fresh(); OneDoor::who($labelA, '91');
$r = OneDoor::password('AgentOne#2026');
check('agent code + password opens the agent', ($r['go'] ?? '') === 'home' && sid() === $A);
fresh(); OneDoor::who('od1_manager', '91');
check('username + password opens the manager', (OneDoor::password('Manager#2026')['go'] ?? '') === 'home' && sid() === $M);
fresh(); OneDoor::who('od1.manager@example.test', '91');
check('email + password opens the manager', (OneDoor::password('Manager#2026')['go'] ?? '') === 'home' && sid() === $M);
fresh(); OneDoor::who('od1_manager', '91');
$r = OneDoor::password('nope-nope');
check('a wrong password: one generic message', sid() === 0 && str_contains((string) ($r['error'] ?? ''), 'ID or password did not match'), (string) ($r['error'] ?? ''));
fresh(); OneDoor::who($PHX, '91');
$r = OneDoor::password('Super#2026');
check('mobile + password of a 2FA superadmin → the code step, no session', ($r['step'] ?? '') === 'admin_otp' && sid() === 0
    && (int) ($_SESSION['admin_2fa_pending']['id'] ?? 0) === $X);
Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'otp\\_%'");
$iss = Auth::issueOtp('admin:' . $X, 'login', 'whatsapp');
$v = Auth::adminLogin2faVerify((string) $iss['code']);
check('…the code opens it (password-authenticated)', $v['ok'] && sid() === $X && ($_SESSION[ADMIN_SESSION_KEY]['via_otp'] ?? true) === false);

/* =================================================================== */
section('WhatsApp code: same reply for every number; 2 IDs → chooser');
fresh(); OneDoor::who($PH, '91');
$r1 = OneDoor::codeSend();
fresh(); OneDoor::who($PHN, '91');
$r2 = OneDoor::codeSend();
check('registered and unknown numbers get the same answer', $r1 === $r2 && ($r1['step'] ?? '') === 'code', json_encode($r1, JSON_UNESCAPED_UNICODE));
$v = OneDoor::codeVerify('123456');
check('…an unknown number: the plain "code did not match"', ($v['step'] ?? '') === 'code' && str_contains((string) ($v['error'] ?? ''), 'did not match'));
fresh(); OneDoor::who($PH, '91');
$iss = Auth::issueOtp('agent:' . $PH, 'login', 'whatsapp');
$w = OneDoor::codeVerify('000000');
check('a wrong code: the same plain message', ($w['step'] ?? '') === 'code' && str_contains((string) ($w['error'] ?? ''), 'did not match') && sid() === 0);
$v = OneDoor::codeVerify((string) $iss['code']);
$ids = array_map(static fn(array $p): int => (int) $p['id'], OneDoor::pickList());
sort($ids);
check('the right code on a 2-ID mobile → which ID?', ($v['step'] ?? '') === 'pick' && sid() === 0 && $ids === [min($A, $C), max($A, $C)], json_encode($ids));
check('…the chooser carries the agent code', in_array($labelA, array_column(OneDoor::pickList(), 'code'), true));
$r = OneDoor::pick($C);
check('…picking the counter opens it, as WhatsApp-proven', ($r['go'] ?? '') === 'home' && sid() === $C && ($_SESSION[ADMIN_SESSION_KEY]['via_otp'] ?? false) === true);
fresh(); OneDoor::who($PH, '91');
Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'otp\\_%'");
$iss = Auth::issueOtp('agent:' . $PH, 'login', 'whatsapp');
OneDoor::codeVerify((string) $iss['code']);
$r = OneDoor::pick($M);
check('a pick outside the proven list ends the pick (one try)', empty($r['go']) && sid() === 0 && OneDoor::pickList() === []);
fresh(); OneDoor::who($PHM, '91');
Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'otp\\_%'");
$iss = Auth::issueOtp('agent:' . $PHM, 'login', 'whatsapp');
$v = OneDoor::codeVerify((string) $iss['code']);
check('a WhatsApp code never opens an office account', sid() === 0 && empty($v['go']));

/* =================================================================== */
section('"hi VERIFY … LOGIN": strict sender, the word, single use');
fresh(); OneDoor::who($PH, '91');
$r = OneDoor::waStart();
$st = OneDoor::state();
$tok = (string) ($st['wa']['token'] ?? '');
check('the hi step starts with a token tied to +91 ' . $PH, ($r['step'] ?? '') === 'wa' && $tok !== '' && ($st['wa']['intl'] ?? '') === '91' . $PH, json_encode($r, JSON_UNESCAPED_UNICODE));
check('…the message says LOGIN', str_contains((string) $st['wa']['text'], 'LOGIN'));
check('finishing before the message: not yet', (OneDoor::waFinish()['step'] ?? '') === 'wa' && sid() === 0);
$reply = (string) WaVerify::inbound('919999999999', 'hi VERIFY ' . $tok . ' — LOGIN');
check('from another number: refused', str_contains($reply, 'LOGIN code') && WaVerify::check($tok)['status'] === 'mismatch');
Database::run("UPDATE wa_verify SET mismatch_from = NULL WHERE token = :t", ['t' => $tok]);
$reply = (string) WaVerify::inbound('91' . $PH, 'hi VERIFY ' . $tok);
check('without the LOGIN word: not verified', WaVerify::check($tok)['status'] !== 'verified', mb_substr($reply, 0, 60));
$reply = (string) WaVerify::inbound('91' . $PH, (string) $st['wa']['text']);
check('the full message from the number on file: verified', WaVerify::check($tok)['status'] === 'verified', mb_substr($reply, 0, 40));
$r = OneDoor::waFinish();
check('…2 IDs on the number → which ID?', ($r['step'] ?? '') === 'pick' && count(OneDoor::pickList()) === 2);
check('…the same token cannot be used twice', WaVerify::consumeLogin($tok, '91' . $PH) === false);
$r = OneDoor::pick($A);
check('…picking the agent opens it', ($r['go'] ?? '') === 'home' && sid() === $A);
fresh(); OneDoor::who($PHM, '91');
OneDoor::waStart();
$st = OneDoor::state();
WaVerify::inbound('91' . $PHM, (string) $st['wa']['text']);
$r = OneDoor::waFinish();
check('hi on an office-only number: "use your password", no session', sid() === 0 && str_contains((string) ($r['error'] ?? ''), 'use your password'));
Settings::set('login_one_door_hi_on', '0', 'bool', 'security', false); Settings::flush();
fresh(); OneDoor::who($PH, '91');
check('the hi switch off: no hi sign-in', (OneDoor::waStart()['step'] ?? '') === 'mobile' && !OneDoor::hiOn());
Settings::set('login_one_door_hi_on', '1', 'bool', 'security', false); Settings::flush();

/* =================================================================== */
section('forgot: the WhatsApp code resets ONLY the chosen ID');
fresh();
$hashA = pwOf($A);
$CODES = [];
$r = OneDoor::forgotSend($PH, '91');
check('the code goes to the number on file', ($r['step'] ?? '') === 'forgot_code' && isset($CODES['91' . $PH]), json_encode(array_keys($CODES)));
$r = OneDoor::forgotProve((string) ($CODES['91' . $PH] ?? ''));
$who = array_column(TeamPortal::resetChoices(), 'who');
sort($who);
check('2 IDs on the number → whose password?', ($r['step'] ?? '') === 'forgot_pick' && $who === ['admin:' . min($A, $C), 'admin:' . max($A, $C)], json_encode($who));
check('…a stranger ID is not offered', (OneDoor::forgotPick('admin:' . $M)['step'] ?? '') === 'forgot_pick');
check('…the counter is picked', (OneDoor::forgotPick('admin:' . $C)['step'] ?? '') === 'forgot_pw');
check('…too short is refused', (OneDoor::forgotSet('short', 'short')['step'] ?? '') === 'forgot_pw');
$r = OneDoor::forgotSet('NewCounter#2026', 'NewCounter#2026');
check('the new password is saved and the COUNTER signed in', ($r['go'] ?? '') === 'home' && sid() === $C);
check('…the counter has the new password', password_verify('NewCounter#2026', pwOf($C)));
check('…the agent on the same number is untouched', pwOf($A) === $hashA && password_verify('AgentOne#2026', pwOf($A)));
check('…the proof is used up', TeamPortal::resetChoices() === [] && (OneDoor::forgotSet('Again#20260', 'Again#20260')['step'] ?? '') === 'forgot');
check('…audited for that ID only', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'team.password_reset' AND entity_id = :i AND created_at > NOW() - INTERVAL 5 MINUTE", ['i' => (string) $C], 0) >= 1
    && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'team.password_reset' AND entity_id = :i AND created_at > NOW() - INTERVAL 5 MINUTE", ['i' => (string) $A], 0) === 0);

section('forgot: a manager can reset (one ID → straight to the new password)');
fresh(); $CODES = [];
OneDoor::forgotSend($PHM, '91');
$r = OneDoor::forgotProve((string) ($CODES['91' . $PHM] ?? ''));
check('one ID on the number → no chooser', ($r['step'] ?? '') === 'forgot_pw' && (OneDoor::forgotWho()['who'] ?? '') === 'admin:' . $M);
$r = OneDoor::forgotSet('NewManager#2026', 'NewManager#2026');
check('the manager is signed in with the new password', ($r['go'] ?? '') === 'home' && sid() === $M && password_verify('NewManager#2026', pwOf($M)));

section('forgot: a 2FA superadmin keeps the code step');
fresh(); $CODES = [];
OneDoor::forgotSend($PHX, '91');
OneDoor::forgotProve((string) ($CODES['91' . $PHX] ?? ''));
$r = OneDoor::forgotSet('NewSuper#2026', 'NewSuper#2026');
check('new password saved, then the 2FA code step (no session yet)', ($r['step'] ?? '') === 'admin_otp' && sid() === 0 && password_verify('NewSuper#2026', pwOf($X)));

section('forgot: no hint for an unknown number; wrong codes lock');
fresh(); $CODES = [];
$r1 = OneDoor::forgotSend($PHN, '91');
check('an unknown number: the same next step, nothing sent', ($r1['step'] ?? '') === 'forgot_code' && $CODES === []);
fresh(); $CODES = [];
OneDoor::forgotSend($PH, '91');
$good = (string) ($CODES['91' . $PH] ?? '');
$hashC = pwOf($C);
for ($i = 0; $i < TeamPortal::RESET_FAILS; $i++) {
    OneDoor::forgotProve($good === '000000' ? '111111' : '000000');
}
$r = OneDoor::forgotProve($good);
check('after ' . TeamPortal::RESET_FAILS . ' wrong codes even the right one is refused (24 h)', ($r['step'] ?? '') === 'forgot_code' && str_contains((string) ($r['error'] ?? ''), '24'), (string) ($r['error'] ?? ''));
check('…nothing was changed', pwOf($C) === $hashC && TeamPortal::resetChoices() === []);
Database::run("DELETE FROM rate_limits WHERE bucket = 'team_reset_fail' AND identifier = :i", ['i' => $PH]);

section('forgot by "hi VERIFY … RESET"');
fresh();
OneDoor::forgotHiFirst($PH, '91');
$wa = $_SESSION['team_reset']['wa'] ?? [];
check('hi step ready, tied to +91 ' . $PH, ($wa['intl'] ?? '') === '91' . $PH && str_contains((string) ($wa['text'] ?? ''), 'RESET'));
WaVerify::inbound('91' . $PH, (string) $wa['text']);
$r = OneDoor::forgotProve('');
check('the message proves the number → whose password?', ($r['step'] ?? '') === 'forgot_pick');
OneDoor::forgotPick('admin:' . $A);
$r = OneDoor::forgotSet('NewAgent#2026', 'NewAgent#2026');
check('…the agent only', sid() === $A && password_verify('NewAgent#2026', pwOf($A)) && pwOf($C) === $hashC);

/* =================================================================== */
section('landing: everybody → admin/home.php; the switch off → the old map');
check('switch on (code default)', Auth::oneDoorOn());
foreach ($ROLES as $role => $id) {
    check("$role lands on /admin/home.php", Auth::homeUrl(['role' => $role]) === '/admin/home.php');
}
Settings::set('login_one_door_on', '0', 'bool', 'security', false); Settings::flush();
check('switch off: agent → agent.php', Auth::homeUrl(['role' => 'agent']) === '/admin/agent.php');
check('switch off: counter → bookings.php', Auth::homeUrl(['role' => 'counter']) === '/admin/bookings.php');
check('switch off: scanner → scan.php, official → payments.php', Auth::homeUrl(['role' => 'scanner']) === '/admin/scan.php' && Auth::homeUrl(['role' => 'official']) === '/admin/payments.php');
check('switch off: manager → index.php', Auth::homeUrl(['role' => 'manager']) === '/admin/index.php');
check('switch off: no Home item in the menu', (static function (): bool { $_SESSION[ADMIN_SESSION_KEY] = ['id' => 1, 'username' => 'x', 'full_name' => 'x', 'role' => 'manager', 'permissions' => [], 'last_seen' => time()];
    require_once dirname(__DIR__) . '/admin/_guard.php'; $h = array_column(admin_nav(), 'href'); return !in_array('home.php', $h, true); })());
Settings::set('login_one_door_on', '1', 'bool', 'security', false); Settings::flush();
check('switch on: Home leads the menu', array_column(admin_nav(), 'href')[0] === 'home.php');
fresh();
check('the old /team/ rules stand: a manager is not a /team/ account', !TeamPortal::login($PHM, 'NewManager#2026', '91')['ok'] && sid() === 0);
$e = null; try { Auth::teamOpenSession(Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $M]), false); } catch (RuntimeException $ex) { $e = $ex; }
check('…teamOpenSession without anyRole still refuses office roles', $e !== null && sid() === 0);

/* =================================================================== */
section('home tiles per role (each tile = the gate of its page)');
$tilesFor = static function (int $id): array {
    $_SESSION = [];
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $id]);
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $id, 'username' => $a['username'], 'full_name' => $a['full_name'], 'role' => $a['role'],
        'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time()];
    $m = AgentPro::available() ? AgentPro::member($id, false) : null;
    $t = HomeBoard::tiles(Auth::admin(), $m, HomeBoard::isOffice() ? AgentPro::counts() : []);
    return ['hero' => array_column($t['hero'], 'key'), 'all' => array_merge(array_column($t['hero'], 'key'), array_column($t['more'], 'key')),
            'strip' => HomeBoard::strip(Auth::admin(), HomeBoard::isOffice() ? AgentPro::counts() : [])];
};
$t = $tilesFor($A);
check('agent: sell, counter mode, my tickets, my chalani, seat, wallet, ID up front', array_diff(['sell', 'counter', 'mytix', 'mychalani', 'seat', 'wallet', 'id'], $t['hero']) === [], implode(',', $t['hero']));
check('agent: no company tiles (approvals, reports, admin, team, register)', array_intersect(['approvals', 'reports', 'admin', 'team', 'register', 'pay', 'alltools'], $t['all']) === []);
check('agent: own money + own sales only', count($t['strip']) === 2 && str_contains($t['strip'][1]['text'], 'Sold by me today'));
$t = $tilesFor($C);
check('counter: SmartBook, counter mode, tickets I cut, chalani per bus, seat, desk up front', array_diff(['sell', 'counter', 'cut', 'chalani', 'seat', 'desk'], $t['hero']) === [], implode(',', $t['hero']));
check('counter: no wallet / approvals / reports', array_intersect(['wallet', 'approvals', 'reports', 'admin'], $t['all']) === []);
$t = $tilesFor($M);
check('manager: sell + approvals + team + reports + admin panel up front', array_diff(['sell', 'approvals', 'team', 'reports', 'admin'], $t['hero']) === [], implode(',', $t['hero']));
$t = $tilesFor($ROLES['accountant']);
check('accountant: no Sell / counter mode (not selling staff)', !in_array('sell', $t['all'], true) && !in_array('counter', $t['all'], true) && in_array('approvals', $t['hero'], true));
$t = $tilesFor($ROLES['support']);
check('support: no Sell, has the support inbox', !in_array('sell', $t['all'], true) && in_array('support', $t['hero'], true));
$t = $tilesFor($ROLES['scanner']);
check('scanner: scan (and nothing that needs bookings.view)', in_array('scan', $t['hero'], true) && array_intersect(['sell', 'register', 'chalani', 'pay'], $t['all']) === []);
$t = $tilesFor($ROLES['official']);
check('official: payments + support', in_array('pay', $t['all'], true) && in_array('support', $t['all'], true) && !in_array('sell', $t['all'], true));
$t = $tilesFor($ROLES['viewer']);
sort($t['all']);
check('viewer: reports + admin panel only', $t['all'] === ['admin', 'reports'], implode(',', $t['all']));
check('switch ID shows only with another ID on the own mobile', HomeBoard::otherIds($A) === 1 && HomeBoard::otherIds($M) === 0 && HomeBoard::otherIds($ROLES['viewer']) === 0);

section('admin/home.php renders for every role (CLI, no warnings)');
$php = PHP_BINARY;
$ini = (string) php_ini_loaded_file();
foreach ($ROLES as $role => $id) {
    $cmd = escapeshellarg($php) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' --render ' . $id . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $bad = preg_match('/Application error|Warning:|Notice:|Deprecated:|Fatal error|Uncaught/i', $out) === 1;
    check("home.php as $role", !$bad && str_contains($out, 'hb-me') && str_contains($out, '🏠 गृह · Home'),
        $bad ? mb_substr(strip_tags($out), 0, 300) : strlen($out) . ' bytes');
}

/* =================================================================== */
$BASE = rtrim((string) ($argv[1] ?? (getenv('SHG_TEST_BASE') ?: 'http://idc.localhost:8951')), '/');
$pc = curl_init($BASE . '/admin/login.php');
curl_setopt_array($pc, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6]);
$probe = curl_exec($pc);
$probeCode = (int) curl_getinfo($pc, CURLINFO_HTTP_CODE);
curl_close($pc);
if ($probe === false || $probeCode !== 200) {
    echo "  (no dev server at $BASE — HTTP part skipped)\n";
} else {
    section("over HTTP ($BASE)");
    $http = static function (string $method, string $url, array $form, string $jar) use ($BASE): array {
        $ch = curl_init($BASE . $url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $hs);
        return ['code' => $code, 'body' => substr($raw, $hs), 'loc' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : ''];
    };
    $csrf = static fn(string $h): string => preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $h, $m) ? $m[1] : '';
    $jar = static function (string $n): string { $p = sys_get_temp_dir() . '/od1_' . $n . '_' . getmypid() . '.cookies'; @unlink($p); return $p; };
    $signIn = static function (string $user, string $pw, string $j, array $extra = []) use ($http, $csrf): array {
        fresh();
        $lp = $http('GET', '/admin/login.php', [], $j);
        return $http('POST', '/admin/login.php', ['shg_csrf' => $csrf($lp['body']), 'username' => $user, 'password' => $pw] + $extra, $j);
    };

    $g = $jar('guest');
    $lp = $http('GET', '/admin/login.php', [], $g);
    check('the door: one box, a CSRF token, no role tiles', $lp['code'] === 200 && str_contains($lp['body'], 'data-door="one"') && str_contains($lp['body'], 'name="who"')
        && $csrf($lp['body']) !== '' && !str_contains($lp['body'], 'class="choose"'));
    check('…a passenger link to the website, Forgot password', str_contains($lp['body'], 'Passenger?') && str_contains($lp['body'], 'step=forgot'));
    check('?portal=agent&auth=otp shows the same one door', str_contains($http('GET', '/admin/login.php?portal=agent&auth=otp', [], $g)['body'], 'data-door="one"'));
    fresh();
    $r = $http('POST', '/admin/login.php', ['shg_csrf' => $csrf($lp['body']), 'mode' => 'who', 'who' => $PH, 'cc' => '91'], $g);
    check('a mobile → hi, code and password on one screen', $r['code'] === 200 && str_contains($r['body'], 'wa_start') && str_contains($r['body'], 'code_send')
        && str_contains($r['body'], 'name="password"') && str_contains($r['body'], '+91 70•••••811'));
    $r = $http('POST', '/admin/login.php', ['shg_csrf' => $csrf($lp['body']), 'mode' => 'pw', 'password' => 'NewCounter#2026'], $g);   // reset above
    check('…the counter password → 302 /admin/home.php', $r['code'] === 302 && $r['loc'] === '/admin/home.php', $r['code'] . ' ' . $r['loc']);
    $hm = $http('GET', '/admin/home.php', [], $g);
    check('home opens for the counter with its tiles', $hm['code'] === 200 && str_contains($hm['body'], 'Tickets I cut') && str_contains($hm['body'], 'Switch ID'));
    preg_match_all('~class="hb-tile" href="(/admin/[a-z\-]+\.php[^"]*)"~', $hm['body'], $mm);
    $bad = [];
    foreach (array_unique($mm[1]) as $u) { $x = $http('GET', html_entity_decode($u), [], $g); if ($x['code'] !== 200) { $bad[] = $u . '=' . $x['code']; } }
    check('every counter tile page opens (200, not 403)', $bad === [] && count($mm[1]) >= 5, implode(' ', $bad) ?: count($mm[1]) . ' pages');
    $r = $http('POST', '/admin/home.php', ['shg_csrf' => $csrf($hm['body']) ?: (preg_match('/<meta name="csrf" content="([^"]+)"/', $hm['body'], $q) ? $q[1] : ''), 'action' => 'switch'], $g);
    check('Switch ID → signed out, the door on the same mobile', $r['code'] === 302 && str_contains($r['loc'], 'login.php?step=mobile'), $r['code'] . ' ' . $r['loc']);
    $sw = $http('GET', '/admin/login.php?step=mobile', [], $g);
    check('…the mobile screen is ready (other ID: its own password / WhatsApp)', str_contains($sw['body'], '+91 70•••••811') && str_contains($sw['body'], 'wa_start'));
    check('…and the old ID is signed out', $http('GET', '/admin/home.php', [], $g)['code'] === 302);

    foreach (['agent' => [$A, 'od1_agent', 'NewAgent#2026'], 'manager' => [$M, 'od1_manager', 'NewManager#2026'],
              'accountant' => [$ROLES['accountant'], 'od1_accountant', 'Role#accountant2026'], 'scanner' => [$ROLES['scanner'], 'od1_scanner', 'Role#scanner2026'],
              'support' => [$ROLES['support'], 'od1_support', 'Role#support2026']] as $role => [$id, $u, $pw]) {
        $j = $jar($role);
        $li = $signIn($u, $pw, $j);
        check("$role: plain username + password POST → /admin/home.php", $li['code'] === 302 && $li['loc'] === '/admin/home.php', $li['code'] . ' ' . $li['loc']);
        $hm = $http('GET', '/admin/home.php', [], $j);
        preg_match_all('~class="hb-tile" href="(/admin/[a-z\-]+\.php[^"]*)"~', $hm['body'], $mm);
        $bad = [];
        foreach (array_unique($mm[1]) as $url) { $x = $http('GET', html_entity_decode($url), [], $j); if ($x['code'] !== 200) { $bad[] = $url . '=' . $x['code']; } }
        check("$role: home 200 and every tile page opens", $hm['code'] === 200 && $bad === [], implode(' ', $bad) ?: count(array_unique($mm[1])) . ' pages');
        $bare = $http('GET', '/admin/', [], $j);
        check("$role: /admin/ → the one home", $bare['code'] === 302 && $bare['loc'] === '/admin/home.php', $bare['code'] . ' ' . $bare['loc']);
        $idx = $http('GET', '/admin/index.php', [], $j);
        if (in_array($role, ['manager', 'accountant', 'support'], true)) {
            check("$role: /admin/index.php still opens the office dashboard", $idx['code'] === 200, (string) $idx['code']);
        } else {
            check("$role: /admin/index.php → the one home", $idx['code'] === 302 && $idx['loc'] === '/admin/home.php', $idx['code'] . ' ' . $idx['loc']);
        }
    }
    $j = $jar('deep');
    $li = $signIn('od1_manager', 'NewManager#2026', $j, ['next' => 'bookings.php']);
    check('a next= deep link still wins', $li['code'] === 302 && $li['loc'] === '/admin/bookings.php', $li['loc']);
    $j = $jar('back');
    $li = $signIn('od1_agent', 'NewAgent#2026', $j, ['back' => 'me']);
    check('back=me returns to /me/', $li['code'] === 302 && $li['loc'] === '/me/', $li['loc']);
    $j = $jar('evil');
    $li = $signIn('od1_agent', 'NewAgent#2026', $j, ['back' => 'https://evil.test/']);
    check('an unknown back= is ignored', $li['loc'] === '/admin/home.php', $li['loc']);
    $g2 = $jar('guest2');
    $me = $http('GET', '/me/', [], $g2);
    check('/me/ signed out → the one door (back=me)', $me['code'] === 302 && str_contains($me['loc'], 'admin/login.php?back=me'), $me['code'] . ' ' . $me['loc']);
    $tm = $http('GET', '/team/', [], $g2);
    check('/team/ signed out → the one door (back=team)', in_array($tm['code'], [302, 303], true) && str_contains($tm['loc'], 'login.php?back=team'), $tm['code'] . ' ' . $tm['loc']);
    $tf = $http('GET', '/team/?v=forgot', [], $g2);
    check('/team/?v=forgot → the one door\'s forgot', str_contains($tf['loc'], 'step=forgot'), $tf['loc']);

    // the switch off: today's door and landings exactly
    Settings::set('login_one_door_on', '0', 'bool', 'security', false); Settings::flush();
    $g3 = $jar('off');
    $off = $http('GET', '/admin/login.php', [], $g3);
    check('switch off: the old door (role chooser, username form)', str_contains($off['body'], 'class="choose"') && str_contains($off['body'], 'name="username"') && !str_contains($off['body'], 'data-door="one"'));
    $j = $jar('offagent');
    $li = $signIn('od1_agent', 'NewAgent#2026', $j);
    check('switch off: an agent lands on agent.php', $li['code'] === 302 && str_contains($li['loc'], 'agent.php'), $li['loc']);
    $idx = $http('GET', '/admin/index.php', [], $j);
    check('switch off: index.php sends the agent to agent.php', $idx['code'] === 302 && str_contains($idx['loc'], 'agent.php'), $idx['loc']);
    $me = $http('GET', '/me/', [], $g3);
    check('switch off: /me/ shows its own sign-in again', $me['code'] === 200);
    Settings::set('login_one_door_on', '1', 'bool', 'security', false); Settings::flush();
}

/* =================================================================== */
$cleanup();
$restore();
echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
