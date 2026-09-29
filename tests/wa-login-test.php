<?php
/**
 * =====================================================================
 *  wa-login-test.php — staff sign in over WhatsApp (24 Sep 2026).
 *
 *  includes/walogin.php is a door into the staff tools from a number the
 *  staff record does not carry. This suite locks in what keeps that door
 *  safe:
 *
 *    • OFF       with wa_login_on off nothing signs in — and a "login …"
 *                line is still swallowed (the password goes nowhere)
 *    • PASSWORD  the same admins.password_hash, the same generic failure,
 *                failed_logins / locked_until untouched (own rate buckets),
 *                must_change_pw refused
 *    • SECOND    an office role always needs the one-time code; an agent
 *      FACTOR    needs it when wa_login_otp is on; the sender's own
 *                registered number never does
 *    • SESSION   whoIs() becomes the account for that number, and only
 *                while the row is live: expiry, revoke, logout, a
 *                deactivated account, all end it
 *    • RATE      five tries per number, then a wait
 *
 *  Creates throwaway staff rows and cleans up after itself.
 *      php tests/wa-login-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/walogin.php';
require_once INCLUDE_PATH . '/agentwallet.php';

const WL_REG   = '9100007001';   // the agent's registered mobile
const WL_NEW   = '9100007002';   // a handset nobody has on file
const WL_NEW2  = '9100007003';   // a second such handset
const WL_BOSS  = '9100007004';   // the manager's registered mobile
const WL_NEW3  = '9100007005';   // the manager's travelling SIM
const WL_LIKE  = '910000700';
const WL_PW    = 'Wa@Login123';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== WhatsApp sign-in — the door, the code, the session ===\n\n";

$PINNED = ['wa_login_on', 'wa_login_otp', 'wa_login_ttl_hours', 'whatsapp_driver', 'whatsapp_notify_customer'];
$prior = [];
foreach ($PINNED as $k) {
    $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$restoreSettings = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        if ($row === null) {
            try { Database::delete('settings', 'skey = :k', ['k' => $k]); } catch (Throwable $e) {}
        } else {
            try {
                Database::update('settings', [
                    'svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'],
                    'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public'],
                ], 'skey = :k', ['k' => $k]);
            } catch (Throwable $e) {}
        }
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};

Settings::set('wa_login_on', false, 'bool', 'ai', false);
Settings::set('wa_login_otp', false, 'bool', 'ai', false);
Settings::set('wa_login_ttl_hours', 12, 'int', 'ai', false);
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);   // journal only, no network
Settings::set('whatsapp_notify_customer', true, 'bool', 'notify', false);
Settings::flush();
// Registered NOW, not in the finally: a failure before the try (no route, a
// fixture insert) must not leave the pinned switches on for the next suite.
register_shutdown_function($restoreSettings);

$mkStaff = static function (string $username, string $name, string $role, string $phone, string $email = ''): int {
    $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $username], 0);
    $row = [
        'full_name' => $name, 'role' => $role, 'phone' => $phone, 'is_active' => 1, 'must_change_pw' => 0,
        'failed_logins' => 0, 'locked_until' => null, 'email' => $email !== '' ? $email : null,
        'password_hash' => password_hash(WL_PW, PASSWORD_BCRYPT),
    ];
    if ($id === 0) {
        return (int) Database::insert('admins', $row + ['username' => $username]);
    }
    Database::update('admins', $row, 'id = :i', ['i' => $id]);
    return $id;
};
$agentId = $mkStaff('wa-login-agent', 'WA Login Agent', 'agent', WL_REG, 'wa-login-agent@example.test');
$bossId  = $mkStaff('wa-login-boss',  'WA Login Boss',  'manager', WL_BOSS);
$agentCode = AgentWallet::agentCodeFor($agentId);
if ($agentCode === null) {
    $agentCode = AgentWallet::nextFreeAgentCode('person');
    if ($agentCode !== null) { AgentWallet::setAgentCode($agentId, $agentCode, 0); }
}
$codeLabel = AgentWallet::agentCodeLabel($agentId);

$cleanup = static function () use ($agentId, $bossId): void {
    try { Database::delete('wa_logins', "phone LIKE '%" . WL_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('wa_logins', 'admin_id IN (:a, :b)', ['a' => $agentId, 'b' => $bossId]); } catch (Throwable $e) {}
    try { Database::delete('kv_store', "kscope IN ('wa_login_pending','wa_agent','wa_stage','wa_turn','wa_bulk') AND kkey LIKE '" . WL_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('otp_codes', "identifier LIKE 'walogin:" . WL_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('rate_limits', "identifier LIKE '%" . WL_LIKE . "%' OR identifier LIKE 'walogin:" . WL_LIKE . "%' OR bucket = 'wa_login_acct'", []); } catch (Throwable $e) {}
    try { Database::delete('admin_login_events', 'admin_id IN (:a, :b)', ['a' => $agentId, 'b' => $bossId]); } catch (Throwable $e) {}
    try { Database::delete('admin_login_events', "username IN ('wa-login-agent','wa-login-boss','nobody-here','[unparsed id]')", []); } catch (Throwable $e) {}
    try {
        Database::delete('audit_logs',
            "(action IN ('staff.login_whatsapp','staff.login_whatsapp_otp','staff.logout_whatsapp') AND entity_id IN (:a, :b))
             OR (action LIKE 'admin.login.%' AND entity_id IN ('wa-login-agent','wa-login-boss','nobody-here','[unparsed id]'))
             OR (action = 'agent.code_set' AND entity_id = :c)",
            ['a' => (string) $agentId, 'b' => (string) $bossId, 'c' => (string) $agentId]);
    } catch (Throwable $e) {}
    try { Database::delete('message_logs', "to_number LIKE '%" . WL_LIKE . "%'", []); } catch (Throwable $e) {}
};
$cleanup();

$roleOf = static fn(string $phone): string => (string) AiTools::whoIs($phone)['role'];

try {
    /* ================================================================
     *  1. Reading the message
     * ================================================================ */
    echo "== the command is read, not guessed ==\n";
    $c = WaLogin::command('login SHG-0027 my secret pw');
    check('"login <code> <password>" is a login', ($c['cmd'] ?? '') === 'login' && ($c['id'] ?? '') === 'SHG-0027' && ($c['password'] ?? '') === 'my secret pw');
    check('"logout" is a logout', (WaLogin::command('Logout')['cmd'] ?? '') === 'logout');
    check('"otp 123456" is a code', (WaLogin::command('otp 123456')['code'] ?? '') === '123456');
    check('"ma ko hu" asks who am I', (WaLogin::command('ma ko hu?')['cmd'] ?? '') === 'whoami');
    check('a bare "login" asks for the format', (WaLogin::command('login')['cmd'] ?? '') === 'help');
    check('a CAPITALISED "Login SHG-0027 pw" (a phone keyboard) is still a login', (WaLogin::command('Login SHG-0027 pw1234')['cmd'] ?? '') === 'login'
        && (WaLogin::command('LOGIN wa-login-agent pw1234')['cmd'] ?? '') === 'login' && (WaLogin::command('Sign in SHG-0027 pw1234')['cmd'] ?? '') === 'login');
    check('"login kasari garne?" is a question, not credentials', (WaLogin::command('login kasari garne?')['cmd'] ?? '') === 'help'
        && (WaLogin::command('login garna mildaina site ma')['cmd'] ?? '') === 'help' && (WaLogin::command('log in to the app kaise kare')['cmd'] ?? '') === 'help');
    check('  while a username + one-token password is', (WaLogin::command('login wa-login-agent Wa@Login123')['cmd'] ?? '') === 'login');
    check('an ordinary sentence is not a command', WaLogin::command('bholi 2 seat chahiyo') === null);
    check('  nor is a sentence that merely contains the word', WaLogin::command('mero login kina mildaina') === null);

    /* ================================================================
     *  2. The door is shut by default
     * ================================================================ */
    echo "\n== with the switch OFF ==\n";
    $off = WaLogin::handle(WL_NEW, 'login ' . $codeLabel . ' ' . WL_PW);
    check('a login line is swallowed and answered "switched off"', $off !== null && str_contains((string) $off['text'], 'बन्द'), (string) ($off['text'] ?? 'null'));
    check('  and nobody is signed in', $roleOf(WL_NEW) === 'customer'
        && (int) Database::scalar("SELECT COUNT(*) FROM wa_logins WHERE phone = :p", ['p' => WL_NEW], 0) === 0);
    check('  an ordinary message is left to the bot', WaLogin::handle(WL_NEW, 'namaste') === null);
    check('  "logout" is left alone too', WaLogin::handle(WL_NEW, 'logout') === null);

    /* ================================================================
     *  3. Password
     * ================================================================ */
    echo "\n== the password (switch ON, code OFF) ==\n";
    Settings::set('wa_login_on', true, 'bool', 'ai', false);
    Settings::flush();

    $help = WaLogin::handle(WL_NEW, 'login');
    check('"login" alone explains the format', $help !== null && str_contains((string) $help['text'], 'login <'));
    $q = WaLogin::handle(WL_NEW, 'login kasari garne?');
    check('a "how do I log in" question gets the format and spends no guess', $q !== null && str_contains((string) $q['text'], 'login <')
        && !Database::exists("SELECT 1 FROM rate_limits WHERE bucket = 'wa_login' AND identifier = :p", ['p' => WL_NEW]));

    $bad = WaLogin::handle(WL_NEW, 'login ' . $codeLabel . ' wrong-password');
    check('a wrong password is refused with the generic line', $bad !== null && str_contains((string) $bad['text'], 'मिलेन'), (string) ($bad['text'] ?? ''));
    check('  the number stays a customer', $roleOf(WL_NEW) === 'customer');
    check('  the WEB lockout counters are NOT touched (the agent code is public)',
        (int) Database::scalar('SELECT failed_logins FROM admins WHERE id = :i', ['i' => $agentId], 0) === 0
        && Database::scalar('SELECT locked_until FROM admins WHERE id = :i', ['i' => $agentId], null) === null);
    check('  and it is in the sign-in log', Database::exists(
        "SELECT 1 FROM admin_login_events WHERE admin_id = :a AND outcome = 'bad_password'", ['a' => $agentId]));

    $unknown = WaLogin::handle(WL_NEW, 'login nobody-here ' . WL_PW);
    check('an unknown id gets the SAME generic line', $unknown !== null && (string) $unknown['text'] === (string) $bad['text']);
    Database::update('admins', ['locked_until' => date('Y-m-d H:i:s', time() + 600)], 'id = :i', ['i' => $agentId]);
    $lockedTry = WaLogin::handle(WL_NEW, 'login ' . $codeLabel . ' ' . WL_PW);
    check('a web-locked account gets the SAME generic line too (no code enumeration)', $lockedTry !== null && (string) $lockedTry['text'] === (string) $bad['text'] && $roleOf(WL_NEW) === 'customer');
    Database::update('admins', ['locked_until' => null], 'id = :i', ['i' => $agentId]);
    $swapped = WaLogin::handle(WL_NEW, 'login MyS3cret!Pw ' . $codeLabel);
    check('id and password in the wrong order: the password is NOT written to the sign-in log',
        $swapped !== null && !Database::exists("SELECT 1 FROM admin_login_events WHERE username LIKE '%MyS3cret%'")
        && !Database::exists("SELECT 1 FROM audit_logs WHERE entity_id LIKE '%MyS3cret%' OR detail LIKE '%MyS3cret%'"));
    try { Database::delete('rate_limits', "bucket IN ('wa_login','wa_login_acct')", []); } catch (Throwable $e) {}

    $ok = WaLogin::handle(WL_NEW, 'login ' . $codeLabel . ' ' . WL_PW);
    check('the right password signs the agent in by agent code', $ok !== null && str_starts_with((string) $ok['text'], '✅'), (string) ($ok['text'] ?? ''));
    check('  the reply asks them to delete the password message', $ok !== null && str_contains((string) $ok['text'], 'मेटाउनुहोस्'));
    $who = AiTools::whoIs(WL_NEW);
    check('  the strange number is now this agent', $who['role'] === 'staff' && $who['adminId'] === $agentId, $who['role']);
    check('  scoped to their own book', (int) $who['scopeAdminId'] === $agentId);
    check('  and carries the sign-in with its expiry', is_array($who['login']) && (string) $who['login']['expires_at'] > date('Y-m-d H:i:s'));
    check('  the sign-in is recorded as password only (no code was sent)',
        (string) Database::scalar('SELECT method FROM wa_logins WHERE phone = :p AND revoked_at IS NULL ORDER BY id DESC LIMIT 1', ['p' => WL_NEW], '') === 'password');
    check('  a success row is in the sign-in log', Database::exists(
        "SELECT 1 FROM admin_login_events WHERE admin_id = :a AND outcome = 'success'", ['a' => $agentId]));
    check('  and the audit trail names the number', Database::exists(
        "SELECT 1 FROM audit_logs WHERE action = 'staff.login_whatsapp' AND entity_id = :a AND new_value LIKE :p",
        ['a' => (string) $agentId, 'p' => '%' . WL_NEW . '%']));

    $me = WaLogin::handle(WL_NEW, 'ma ko hu');
    check('"ma ko hu" answers with the role', $me !== null && str_contains((string) $me['text'], 'एजेन्ट'), (string) ($me['text'] ?? ''));

    $again = WaLogin::handle(WL_NEW, 'login wa-login-agent ' . WL_PW);
    check('signing in again by USERNAME replaces the session', $again !== null && str_starts_with((string) $again['text'], '✅'));
    check('  one live row per number',
        (int) Database::scalar('SELECT COUNT(*) FROM wa_logins WHERE phone = :p AND revoked_at IS NULL', ['p' => WL_NEW], 0) === 1);

    $byMail = WaLogin::handle(WL_NEW2, 'login wa-login-agent@example.test ' . WL_PW);
    check('signing in by EMAIL works from another handset', $byMail !== null && str_starts_with((string) $byMail['text'], '✅'));
    check('  and that handset is staff too', $roleOf(WL_NEW2) === 'staff');

    $longLine = WaLogin::handle(WL_NEW3, "login " . $codeLabel . " " . WL_PW . "\n" . str_repeat("1. Ram Thapa 9876543210\n", 12));
    check('a login line with a long list pasted under it is still swallowed (the password never travels on)',
        $longLine !== null && str_starts_with((string) $longLine['text'], '✅') && $roleOf(WL_NEW3) === 'staff', (string) ($longLine['text'] ?? 'null'));
    WaLogin::handle(WL_NEW3, 'logout');

    /* The session is keyed on the INTERNATIONAL number: +977 98… and
       +91 98… normalise to the same ten digits and must never share it. */
    $np = WaLogin::handle('+977' . WL_NEW3, 'login ' . $codeLabel . ' ' . WL_PW);
    check('a sign-in from +977 opens', $np !== null && str_starts_with((string) $np['text'], '✅'));
    check('  and is staff for +977', $roleOf('+977' . WL_NEW3) === 'staff');
    check('  but the +91 number with the same ten digits is a customer', $roleOf('+91' . WL_NEW3) === 'customer');
    check('  and so is the bare ten-digit form', $roleOf(WL_NEW3) === 'customer');
    check('  the row carries the country code', Database::exists('SELECT 1 FROM wa_logins WHERE phone = :p AND revoked_at IS NULL', ['p' => '977' . WL_NEW3]));
    WaLogin::handle('+977' . WL_NEW3, 'logout');
    check('  logout from +977 ends exactly that session', $roleOf('+977' . WL_NEW3) === 'customer');

    /* ---- the session ends ---------------------------------------- */
    echo "\n== the session ends ==\n";
    Database::update('admins', ['is_active' => 0], 'id = :i', ['i' => $agentId]);
    check('a deactivated account is a customer at once', $roleOf(WL_NEW) === 'customer');
    check('  and its row was revoked',
        (int) Database::scalar('SELECT COUNT(*) FROM wa_logins WHERE phone = :p AND revoked_at IS NULL', ['p' => WL_NEW], 0) === 0);
    Database::update('admins', ['is_active' => 1], 'id = :i', ['i' => $agentId]);

    Database::update('wa_logins', ['expires_at' => date('Y-m-d H:i:s', time() - 60)], 'phone = :p', ['p' => WL_NEW2]);
    check('an expired session is a customer', $roleOf(WL_NEW2) === 'customer');

    $ok2 = WaLogin::handle(WL_NEW, 'login ' . $codeLabel . ' ' . WL_PW);
    $out = WaLogin::handle(WL_NEW, 'logout');
    check('"logout" ends it', $out !== null && str_contains((string) $out['text'], 'साइन आउट') && $roleOf(WL_NEW) === 'customer');
    check('  and is in the sign-in log', Database::exists(
        "SELECT 1 FROM admin_login_events WHERE admin_id = :a AND outcome = 'logout'", ['a' => $agentId]));

    $ok3 = WaLogin::handle(WL_NEW, 'login ' . $codeLabel . ' ' . WL_PW);
    $rowId = (int) Database::scalar('SELECT id FROM wa_logins WHERE phone = :p AND revoked_at IS NULL ORDER BY id DESC LIMIT 1', ['p' => WL_NEW], 0);
    WaLogin::revoke($rowId, $bossId);
    check('the office can revoke a sign-in', $rowId > 0 && $roleOf(WL_NEW) === 'customer');
    check('  and the row remembers who did it (revoke)',
        (int) Database::scalar('SELECT revoked_by FROM wa_logins WHERE id = :i', ['i' => $rowId], 0) === $bossId);

    Database::update('admins', ['must_change_pw' => 1], 'id = :i', ['i' => $agentId]);
    $mc = WaLogin::handle(WL_NEW, 'login ' . $codeLabel . ' ' . WL_PW);
    check('a temporary password cannot sign in here', $mc !== null && str_contains((string) $mc['text'], 'पासवर्ड पहिले') && $roleOf(WL_NEW) === 'customer');
    Database::update('admins', ['must_change_pw' => 0], 'id = :i', ['i' => $agentId]);

    /* ================================================================
     *  4. The second factor
     * ================================================================ */
    echo "\n== the one-time code ==\n";
    $bossTry = WaLogin::handle(WL_NEW3, 'login wa-login-boss ' . WL_PW);
    check('a manager is asked for a code even with wa_login_otp OFF', $bossTry !== null && str_contains((string) $bossTry['text'], 'otp'), (string) ($bossTry['text'] ?? ''));
    check('  and is NOT signed in yet', $roleOf(WL_NEW3) === 'customer');
    check('  the code went to the REGISTERED number, not the sender',
        Database::exists("SELECT 1 FROM otp_codes WHERE identifier = :i AND is_used = 0", ['i' => 'walogin:' . WL_BOSS]));
    check('  the passenger-facing journal shows a send to that number',
        (static function (): bool {
            require_once INCLUDE_PATH . '/notify.php';
            foreach (Notify::whatsappJournal() as $j) { if (str_contains((string) ($j['to'] ?? ''), WL_BOSS)) { return true; } }
            return false;
        })());

    $wrong = WaLogin::handle(WL_NEW3, 'otp 000000');
    check('a wrong code is refused', $wrong !== null && str_contains((string) $wrong['text'], 'मिलेन') && $roleOf(WL_NEW3) === 'customer');

    // The real code is hashed; plant a known one on top (verifyOtp reads the newest).
    Database::insert('otp_codes', [
        'identifier' => 'walogin:' . WL_BOSS, 'channel' => 'whatsapp', 'purpose' => 'login',
        'code_hash' => password_hash('246810', PASSWORD_BCRYPT),
        'expires_at' => date('Y-m-d H:i:s', time() + 300), 'ip_address' => '127.0.0.1',
    ]);
    $right = WaLogin::handle(WL_NEW3, 'otp 246810');
    check('the right code signs the manager in', $right !== null && str_starts_with((string) $right['text'], '✅'), (string) ($right['text'] ?? ''));
    $bw = AiTools::whoIs(WL_NEW3);
    check('  as the OFFICE, unscoped', $bw['role'] === 'admin' && $bw['adminId'] === $bossId && $bw['scopeAdminId'] === null, $bw['role']);
    check('  the method is recorded as password+otp',
        (string) Database::scalar('SELECT method FROM wa_logins WHERE phone = :p AND revoked_at IS NULL ORDER BY id DESC LIMIT 1', ['p' => WL_NEW3], '') === 'password+otp');
    check('  a second "otp" finds nothing pending', str_contains((string) (WaLogin::handle(WL_NEW3, 'otp 246810')['text'] ?? ''), 'पर्खिरहेको छैन'));
    WaLogin::handle(WL_NEW3, 'logout');

    Settings::set('wa_login_otp', true, 'bool', 'ai', false);
    Settings::flush();
    $agentOtp = WaLogin::handle(WL_NEW2, 'login ' . $codeLabel . ' ' . WL_PW);
    check('with wa_login_otp ON an agent is asked for the code too', $agentOtp !== null && str_contains((string) $agentOtp['text'], 'otp') && $roleOf(WL_NEW2) === 'customer');
    $own = WaLogin::handle(WL_REG, 'login ' . $codeLabel . ' ' . WL_PW);
    check('  but from their own registered number no code is needed', $own !== null && str_starts_with((string) $own['text'], '✅'));
    WaLogin::handle(WL_REG, 'logout');

    Database::update('admins', ['phone' => null], 'id = :i', ['i' => $agentId]);
    $noPhone = WaLogin::handle(WL_NEW2, 'login wa-login-agent ' . WL_PW);
    check('an account with no mobile on file cannot sign in while a code is required',
        $noPhone !== null && str_contains((string) $noPhone['text'], 'मोबाइल नम्बर छैन') && $roleOf(WL_NEW2) === 'customer');
    Database::update('admins', ['phone' => WL_REG], 'id = :i', ['i' => $agentId]);
    Settings::set('wa_login_otp', false, 'bool', 'ai', false);
    Settings::flush();

    /* ================================================================
     *  5. Rate limit — per number AND per account, never the web lock
     * ================================================================ */
    echo "\n== a guesser is slowed down ==\n";
    try { Database::delete('rate_limits', "bucket IN ('wa_login','wa_login_acct')", []); } catch (Throwable $e) {}
    Database::update('admins', ['failed_logins' => 0, 'locked_until' => null], 'id = :i', ['i' => $agentId]);
    $last = null;
    for ($i = 0; $i < MAX_LOGIN_ATTEMPTS; $i++) {
        $last = WaLogin::handle(WL_NEW2, 'login ' . $codeLabel . ' nope' . $i);
    }
    $sixth = WaLogin::handle(WL_NEW2, 'login ' . $codeLabel . ' ' . WL_PW);
    check('after ' . MAX_LOGIN_ATTEMPTS . ' wrong tries even the right password must wait',
        $sixth !== null && !str_starts_with((string) $sixth['text'], '✅'), (string) ($sixth['text'] ?? ''));
    check('  and the number is still a customer', $roleOf(WL_NEW2) === 'customer');
    check('  the WEB portal is NOT locked by WhatsApp guesses',
        Database::scalar('SELECT locked_until FROM admins WHERE id = :i', ['i' => $agentId], null) === null
        && (int) Database::scalar('SELECT failed_logins FROM admins WHERE id = :i', ['i' => $agentId], 0) === 0);
    try { Database::delete('rate_limits', "bucket = 'wa_login'", []); } catch (Throwable $e) {}
    $otherNumber = WaLogin::handle(WL_NEW, 'login ' . $codeLabel . ' ' . WL_PW);
    check('  the ACCOUNT budget holds from a second number too', $otherNumber !== null && !str_starts_with((string) $otherNumber['text'], '✅') && $roleOf(WL_NEW) === 'customer');
    try { Database::delete('rate_limits', "bucket IN ('wa_login','wa_login_acct')", []); } catch (Throwable $e) {}

    /* ================================================================
     *  6. The wiring
     * ================================================================ */
    echo "\n== the wiring ==\n";
    $root = dirname(__DIR__);
    $wabot = (string) file_get_contents($root . '/includes/wabot.php');
    $tools = (string) file_get_contents($root . '/includes/aitools.php');
    check('wabot.php answers a login line BEFORE the assistant sees it',
        strpos($wabot, 'WaLogin::handle') !== false && strpos($wabot, 'WaLogin::handle') < strpos($wabot, 'AiAgent::handle'));
    check('whoIs() consults the WhatsApp session', str_contains($tools, 'WaLogin::session'));
    $sql = (string) file_get_contents($root . '/database/upgrade-2026-09-24-wa-manager.sql');
    check('the migration ships the door shut', str_contains($sql, "('wa_login_on',        '0'"));
    check('  and the second factor on', str_contains($sql, "('wa_login_otp',       '1'"));
    check('this suite is registered in the battery', str_contains((string) file_get_contents($root . '/tests/run-all.php'), 'wa-login-test.php'));

} catch (Throwable $e) {
    check('suite completed without an unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    try { Database::delete('admins', 'id IN (:a, :b)', ['a' => $agentId, 'b' => $bossId]); } catch (Throwable $e) {}
    try { AgentWallet::setAgentCode($agentId, null, 0); } catch (Throwable $e) {}
    $restoreSettings();
}

echo "\n----------------------------------------\n";
echo "  \033[32m$PASS passed\033[0m, " . ($FAIL > 0 ? "\033[31m$FAIL failed\033[0m" : "0 failed") . "\n\n";
exit($FAIL > 0 ? 1 : 0);
