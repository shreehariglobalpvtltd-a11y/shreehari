<?php
/**
 * =====================================================================
 *  mp2-bankgmail-test.php — bank Gmail boxes from the admin panel (25 Nov 2026, mp2).
 *
 *  Owner: "bank ko lagi gmail account add garxu admin panel bataoi login garn milos browser
 *  jasto ... tesma update haru sab aos; indian ko laini credential naaya samma same system hos".
 *
 *   S  SecretBox: sealed != plaintext, opens back, another context / a cut value opens to ''
 *   L  the old single-box settings become account #1 ONCE (sealed), then are read only through it
 *   O  Google sign-in: start URL (client_id, redirect_uri, gmail.readonly, offline, consent, state,
 *      PKCE S256), callback: bad / expired / reused state refused, good state -> box stored with
 *      sealed tokens (DB value != plaintext, opens back), no refresh_token / no gmail.readonly /
 *      access_denied / wrong account on reconnect refused, a non-super-admin gets 403
 *   G  the Gmail API reader (all Google HTTP mocked through GoogleOAuth::$http, no network):
 *      list + get raw -> credit with mail_account_id, one bank_mail_log row per mail, oldest first,
 *      seen mails never fetched again, token refresh, invalid_grant -> "reconnect", the cron skips it
 *   I  Indian alerts: ICICI / HDFC / SBI wordings -> amount + UPI Ref / RRN / UTR, "reference number is",
 *      INR PNR match -> suggestion on the India tab, Confirm -> "PAID - Indian online (Gmail)", INBANK-
 *   A  amount-only (both currencies): one booking owing exactly the amount -> suggestion match_kind
 *      'amount' (never auto, even with the auto switch forced ON here), two -> unmatched + PNR list
 *   R  per-box rules: DKIM / sender / our account digits are the BOX's, the global bankmail_* settings
 *      never apply to a box; a box without DKIM domain + digits logs "setup incomplete", stores nothing
 *   P  admin/payment-center.php: both tabs show the accounts card for a super-admin, the Google setup
 *      steps + redirect URI, refuses a manager; Check now / pause / edit / remove need CSRF;
 *      admin/gmail-oauth.php start for the super-admin, 403 for a manager
 *
 *    php -c .claude/php-dev.ini -d extension=sodium tests/mp2-bankgmail-test.php
 *  (re-runs itself with ext-sodium when php-dev.ini does not load it). Creates its own bookings
 *  (phone 9000017726), boxes (…@mp2test.example) and admins and removes them, the credits, logs,
 *  bells, audit rows and the settings it touched again.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT = dirname(__DIR__);
const MP2_PHONE = '9000017726';
const MP2_DOM   = 'mp2test.example';

/* ---------- ext-sodium: secrets are never stored without it, so the suite needs it ---------- */
if (!extension_loaded('sodium') && ($argv[1] ?? '') !== '--page') {
    $dll = rtrim((string) ini_get('extension_dir'), '\\/') . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php_sodium.dll' : 'sodium.so');
    if (is_file($dll) && getenv('MP2_REEXEC') !== '1') {
        putenv('MP2_REEXEC=1');
        $ini = php_ini_loaded_file();
        passthru(escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' -d extension=sodium ' . escapeshellarg(__FILE__), $code);
        exit((int) $code);
    }
    echo "  FAIL  ext-sodium is not available — bank Gmail secrets can never be stored here\n";
    exit(1);
}

/* ---------- child: one admin page as a signed-in admin (Google HTTP mocked when asked) ---------- */
if (($argv[1] ?? '') === '--page') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $script = (string) ($cfg['script'] ?? 'payment-center.php');
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.68';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/admin/' . $script;
    $_SERVER['REQUEST_URI'] = '/admin/' . $script;
    $_GET = (array) ($cfg['get'] ?? []);
    define('SHG_APP', true);
    require_once $ROOT . '/includes/bootstrap.php';
    require_once INCLUDE_PATH . '/bankmail.php';
    $mock = (array) ($cfg['mock'] ?? []);
    if ($mock !== []) {
        GoogleOAuth::$http = static function (string $m, string $url, array $form, array $h) use ($mock): array {
            foreach ($mock as [$needle, $code, $json]) {
                if (str_contains($url, (string) $needle)) {
                    return ['http' => (int) $code, 'json' => (array) $json];
                }
            }
            return ['http' => 599, 'json' => ['error' => 'unmocked ' . $url]];
        };
    }
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time()];
    if (isset($cfg['post']) && is_array($cfg['post'])) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $cfg['post'] + (empty($cfg['nocsrf']) ? [CSRF_TOKEN_NAME => Security::csrfToken()] : [CSRF_TOKEN_NAME => 'forged-token']);
    }
    register_shutdown_function(static function (): void { echo "\n<!--HTTP:" . http_response_code() . "-->\n"; });
    require $ROOT . '/admin/' . basename($script);
    exit(0);
}

define('SHG_APP', true);
require_once $ROOT . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/bankmail.php';
require_once INCLUDE_PATH . '/gmailapi.php';
require_once INCLUDE_PATH . '/aiops.php';

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$keep = [];
function setKeep(string $k, string $v, string $t = 'string'): void {
    global $keep;
    if (!array_key_exists($k, $keep)) { $keep[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]); }
    Settings::set($k, $v, $t, 'payments', false);
    Settings::flush();
}
function unsetKeep(string $k): void {
    global $keep;
    if (!array_key_exists($k, $keep)) { $keep[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]); }
    Database::delete('settings', 'skey = :k', ['k' => $k]);
    Settings::flush();
}
$acctIds = [];
$madeAdmins = [];
$bellFloor  = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM notifications', [], 0);
$auditFloor = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
function cleanup(): void {
    global $keep, $acctIds, $madeAdmins, $bellFloor, $auditFloor;
    GoogleOAuth::$http = null;
    foreach (Database::fetchAll('SELECT id FROM bank_mail_accounts WHERE email LIKE :d', ['d' => '%@' . MP2_DOM]) as $r) { $acctIds[] = (int) $r['id']; }
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => MP2_PHONE]) as $r) {
        foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
        foreach (['booking_collections', 'agent_ledger', 'pay_events', 'payment_reviews', 'notifications', 'payments'] as $t) {
            try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        }
        try { Database::run('DELETE FROM bank_credits WHERE booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM audit_logs WHERE entity_id = :p', ['p' => $r['pnr']]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM booking_passengers WHERE booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    foreach (array_unique($acctIds) as $aid) {
        foreach (Database::fetchAll('SELECT id FROM bank_credits WHERE mail_account_id = :a', ['a' => $aid]) as $c) {
            try { Database::run("DELETE FROM audit_logs WHERE entity_type = 'bank_credit' AND entity_id = :i", ['i' => (string) $c['id']]); } catch (Throwable $e) {}
        }
        try { Database::run('DELETE FROM bank_credits WHERE mail_account_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM bank_mail_log WHERE account_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM bank_mail_accounts WHERE id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        try { Database::run("DELETE FROM audit_logs WHERE entity_type = 'bank_mail_account' AND entity_id = :i", ['i' => (string) $aid]); } catch (Throwable $e) {}
    }
    try { Database::run("DELETE FROM bank_credits WHERE txn_id LIKE 'MP2T%' OR note LIKE 'Txn MP2T%'"); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM notifications WHERE id > :f AND (title LIKE 'Bank credit needs a person%' OR title LIKE 'बैंक इमेल:%' OR title LIKE 'Bank mail could not be read%')", ['f' => $bellFloor]); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM audit_logs WHERE id > :f AND (action LIKE 'bank_mail.%' OR action = 'settings.google_oauth')", ['f' => $auditFloor]); } catch (Throwable $e) {}
    foreach ($madeAdmins as $aid) {
        try { Database::delete('kv_store', "kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $aid]); } catch (Throwable $e) {}
        try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        try { Database::delete('admins', 'id = :a', ['a' => $aid]); } catch (Throwable $e) {}
    }
    foreach ($keep as $k => $row) {
        Database::delete('settings', 'skey = :k', ['k' => $k]);
        if ($row !== null) { try { Database::insert('settings', $row); } catch (Throwable $e) {} }
    }
    $keep = [];
    Settings::flush();
}
register_shutdown_function('cleanup');

echo "\n=== mp2 bank Gmail: boxes from the admin panel ===\n\n";
foreach (['whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'email_enabled' => '0'] as $k => $v) { setKeep($k, $v, 'bool'); }
setKeep('npr_per_inr', '1.6', 'float');
unsetKeep('pay_bankmail_autoconfirm_on');   // the code default: OFF (suggestions only)
setKeep('bankmail_on', '0', 'bool');         // the OLD single-box switch: boxes do not need it

if (!BankMailAccounts::ready() || !BankMailAccounts::logReady() || !BankMailAccounts::creditCols() || !PayCredits::mailCols()) {
    check('migration database/upgrade-2026-11-25-mp2-bank-gmail.sql applied (bank_mail_accounts, bank_mail_log, bank_credits.mail_account_id / match_kind)', false);
    exit(1);
}
$tag = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
$T   = static fn(string $n): string => 'MP2T' . $tag . $n;
$em  = static fn(string $n): string => strtolower('mp2' . $n . $tag) . '@' . MP2_DOM;

/* ---------------- S. SecretBox ---------------- */
echo "-- S SecretBox\n";
$sealed = SecretBox::seal('abcd efgh ijkl mnop', 'bankmail');
check('S sealed value is not the plaintext and opens back', $sealed !== 'abcd efgh ijkl mnop' && !str_contains($sealed, 'abcd') && str_starts_with($sealed, 'sb1:') && SecretBox::open($sealed, 'bankmail') === 'abcd efgh ijkl mnop');
check('S another context, a cut value, a plaintext value all open to ""', SecretBox::open($sealed, 'other') === '' && SecretBox::open(substr($sealed, 0, 30), 'bankmail') === '' && SecretBox::open('abcd', 'bankmail') === '' && SecretBox::open(null) === '');
check('S two seals of the same secret differ (random nonce)', SecretBox::seal('x', 'bankmail') !== SecretBox::seal('x', 'bankmail'));
check('S google_oauth_* is super-admin only (AiOps prefix), the client secret too', AiOps::superOnlySetting('google_oauth_client_secret') && AiOps::superOnlySetting('google_oauth_client_id') && AiOps::superOnlySetting('google_oauth_redirect'));

/* ---------------- L. the old single box -> account #1, once ---------------- */
echo "\n-- L old settings -> account #1\n";
if ((int) Database::scalar('SELECT COUNT(*) FROM bank_mail_accounts', [], 0) === 0) {
    setKeep('bankmail_user', $em('legacy'), 'string');
    setKeep('bankmail_app_password', 'abcd efgh ijkl mnop', 'string');
    setKeep('bankmail_from', 'alerts@gibl.test', 'string');
    setKeep('bankmail_dkim_domain', 'gibl.test', 'string');
    setKeep('bankmail_account_hint', '789012', 'string');
    setKeep('bankmail_last_uid', '777', 'int');
    setKeep('bankmail_on', '1', 'bool');
    unsetKeep(BankMailAccounts::LEGACY_KEY);
    check('L old settings filled, no box yet -> the cron has something to run (legacyPending)', BankMailAccounts::legacyPending() && BankMail::anythingToRun());
    $lid = BankMailAccounts::migrateLegacy();
    $acctIds[] = $lid;
    $la = BankMailAccounts::get($lid) ?? [];
    check('L account #1 (NP, app password) from bankmail_user, the app password SEALED (no spaces), rules + on/off + IMAP position copied',
        $lid > 0 && ($la['email'] ?? '') === $em('legacy') && ($la['country'] ?? '') === 'NP' && ($la['currency'] ?? '') === 'NPR' && ($la['method'] ?? '') === 'app_password'
        && !str_contains((string) ($la['secret_enc'] ?? ''), 'abcd') && BankMailAccounts::secret($la) === 'abcdefghijklmnop'
        && ($la['senders'] ?? '') === 'alerts@gibl.test' && ($la['dkim_domain'] ?? '') === 'gibl.test' && ($la['account_hint'] ?? '') === '789012'
        && (int) ($la['is_active'] ?? 0) === 1 && (int) ($la['last_uid'] ?? 0) === 777, json_encode($la));
    check('L once only: marker saved, a second call does nothing, still one box', Settings::getString(BankMailAccounts::LEGACY_KEY, '') === (string) $lid
        && BankMailAccounts::migrateLegacy() === 0 && (int) Database::scalar('SELECT COUNT(*) FROM bank_mail_accounts WHERE email = :e', ['e' => $em('legacy')], 0) === 1
        && !BankMailAccounts::legacyPending());
    check('L audited bank_mail.legacy_migrated', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'bank_mail.legacy_migrated' AND entity_id = :i", ['i' => (string) $lid], 0) === 1);
    BankMailAccounts::remove($lid);
    setKeep('bankmail_on', '0', 'bool');
} else {
    check('L (skipped: shari_test already has bank Gmail boxes — the once-only move needs an empty table)', true);
}

/* ---------------- O. Google sign-in ---------------- */
echo "\n-- O Google sign-in (OAuth, mocked)\n";
setKeep('google_oauth_client_id', '123456-mp2test.apps.googleusercontent.com', 'string');
setKeep('google_oauth_client_secret', 'GOCSPX-mp2-local-test-secret', 'string');
unsetKeep('google_oauth_redirect');
$calls = [];
$gResp = [];   // [needle => [http, json]] - first match wins
GoogleOAuth::$http = static function (string $m, string $url, array $form, array $h) use (&$calls, &$gResp): array {
    $calls[] = ['m' => $m, 'url' => $url, 'form' => $form, 'auth' => implode("\n", $h)];
    foreach ($gResp as $needle => [$code, $json]) {
        if (str_contains($url, (string) $needle)) {
            return ['http' => $code, 'json' => $json];
        }
    }
    return ['http' => 599, 'json' => ['error' => 'unmocked']];
};
$mkAdmin = static function (string $role) use (&$madeAdmins, $tag): int {
    $id = (int) Database::insert('admins', ['username' => 'mp2-' . $role . '-' . strtolower($tag), 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => 'MP2 ' . $role, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
    $madeAdmins[] = $id;
    return $id;
};
$SUP = $mkAdmin('superadmin');
$MGR = $mkAdmin('manager');

$redir = GoogleOAuth::redirectUri();
check('O default redirect URI = the admin door + /admin/gmail-oauth.php', str_ends_with($redir, '/admin/gmail-oauth.php') && str_starts_with($redir, 'http'), $redir);
$store = [];
$url = GoogleOAuth::start('NP', null, $store, $SUP);
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
$pend = $store[GoogleOAuth::SESSION_KEY] ?? [];
$b64 = static fn(string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
check('O start URL: accounts.google.com, client_id, redirect_uri, scope gmail.readonly, access_type offline, prompt consent, response_type code, state, PKCE S256',
    str_starts_with($url, 'https://accounts.google.com/o/oauth2/v2/auth?') && ($q['client_id'] ?? '') === '123456-mp2test.apps.googleusercontent.com'
    && ($q['redirect_uri'] ?? '') === $redir && ($q['scope'] ?? '') === 'https://www.googleapis.com/auth/gmail.readonly' && ($q['access_type'] ?? '') === 'offline'
    && str_contains((string) ($q['prompt'] ?? ''), 'consent') && ($q['response_type'] ?? '') === 'code' && ($q['include_granted_scopes'] ?? '') === 'true'
    && preg_match('/^[0-9a-f]{32}$/', (string) ($q['state'] ?? '')) === 1 && ($q['state'] ?? '') === ($pend['state'] ?? '-')
    && ($q['code_challenge_method'] ?? '') === 'S256' && ($q['code_challenge'] ?? '') === $b64(hash('sha256', (string) ($pend['verifier'] ?? ''), true))
    && strlen((string) ($pend['verifier'] ?? '')) >= 43 && (int) ($pend['exp'] ?? 0) > time() + 500 && ($pend['country'] ?? '') === 'NP', $url);
check('O the client secret never goes into the browser URL', !str_contains($url, 'GOCSPX'));

$okToken = ['access_token' => 'ya29.MP2ACCESS' . $tag, 'refresh_token' => '1//MP2REFRESH' . $tag, 'expires_in' => 3599,
    'scope' => 'https://www.googleapis.com/auth/gmail.readonly', 'token_type' => 'Bearer'];
$gResp = ['oauth2.googleapis.com/token' => [200, $okToken], 'gmail/v1/users/me/profile' => [200, ['emailAddress' => $em('np')]]];

$calls = [];
$r = GoogleOAuth::finish(['state' => 'not-the-state', 'code' => 'c1'], $store, $SUP);
check('O callback with a wrong state -> refused, nothing called, the pending state consumed', !$r['ok'] && $calls === [] && !isset($store[GoogleOAuth::SESSION_KEY]) && str_contains($r['msg'], 'session'), $r['msg']);
GoogleOAuth::start('NP', null, $store, $SUP);
$st = (string) $store[GoogleOAuth::SESSION_KEY]['state'];
$store[GoogleOAuth::SESSION_KEY]['exp'] = time() - 1;
$r = GoogleOAuth::finish(['state' => $st, 'code' => 'c1'], $store, $SUP);
check('O callback after 10 minutes (expired state) -> refused, nothing called', !$r['ok'] && $calls === [] && str_contains($r['msg'], '१० मिनेट'), $r['msg']);
GoogleOAuth::start('NP', null, $store, $SUP);
$st = (string) $store[GoogleOAuth::SESSION_KEY]['state'];
$r = GoogleOAuth::finish(['state' => $st, 'code' => 'c1'], $store, $MGR);
check('O callback in another admin\'s session -> refused', !$r['ok'] && $calls === []);
GoogleOAuth::start('NP', null, $store, $SUP);
$st = (string) $store[GoogleOAuth::SESSION_KEY]['state'];
$ver = (string) $store[GoogleOAuth::SESSION_KEY]['verifier'];
$r = GoogleOAuth::finish(['state' => $st, 'error' => 'access_denied'], $store, $SUP);
check('O Google said access_denied -> Nepali message, nothing stored', !$r['ok'] && str_contains($r['msg'], 'Allow') && $calls === [] && Database::fetch('SELECT id FROM bank_mail_accounts WHERE email = :e', ['e' => $em('np')]) === null, $r['msg']);

GoogleOAuth::start('NP', null, $store, $SUP);
$st  = (string) $store[GoogleOAuth::SESSION_KEY]['state'];
$ver = (string) $store[GoogleOAuth::SESSION_KEY]['verifier'];
$calls = [];
$r = GoogleOAuth::finish(['state' => $st, 'code' => 'good-code-1'], $store, $SUP);
$npA = Database::fetch('SELECT * FROM bank_mail_accounts WHERE email = :e', ['e' => $em('np')]) ?? [];
$NPID = (int) ($npA['id'] ?? 0);
$acctIds[] = $NPID;
$tokCall = $calls[0] ?? [];
check('O good state -> the code swapped at oauth2.googleapis.com/token (POST, authorization_code, code_verifier, redirect_uri, client secret), profile read with the access token',
    $r['ok'] && ($tokCall['m'] ?? '') === 'POST' && str_starts_with((string) ($tokCall['url'] ?? ''), 'https://oauth2.googleapis.com/token')
    && ($tokCall['form']['grant_type'] ?? '') === 'authorization_code' && ($tokCall['form']['code'] ?? '') === 'good-code-1' && ($tokCall['form']['code_verifier'] ?? '') === $ver
    && ($tokCall['form']['redirect_uri'] ?? '') === $redir && ($tokCall['form']['client_secret'] ?? '') === 'GOCSPX-mp2-local-test-secret'
    && str_contains((string) ($calls[1]['url'] ?? ''), 'gmail.googleapis.com/gmail/v1/users/me/profile') && str_contains((string) ($calls[1]['auth'] ?? ''), 'Bearer ya29.MP2ACCESS' . $tag), json_encode([$r, $calls], JSON_UNESCAPED_UNICODE));
check('O the box is stored: NP / NPR, method google, active, ✓ last_ok, address from the Gmail profile',
    $NPID > 0 && $npA['country'] === 'NP' && $npA['currency'] === 'NPR' && $npA['method'] === 'google' && (int) $npA['is_active'] === 1 && (int) $npA['last_ok'] === 1 && (int) $r['account_id'] === $NPID, json_encode($npA));
check('O tokens SEALED at rest: the DB values are not the plaintext and open back; expiry ~1 h',
    !str_contains((string) $npA['secret_enc'], 'MP2REFRESH') && !str_contains((string) $npA['access_enc'], 'MP2ACCESS')
    && BankMailAccounts::secret($npA) === '1//MP2REFRESH' . $tag && SecretBox::open((string) $npA['access_enc'], 'bankmail') === 'ya29.MP2ACCESS' . $tag
    && strtotime((string) $npA['access_expires_at']) > time() + 3000);
check('O no token in the message shown to the person; audited bank_mail.google_connect',
    !str_contains($r['msg'], 'MP2REFRESH') && !str_contains($r['msg'], 'MP2ACCESS') && str_contains($r['msg'], $em('np'))
    && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'bank_mail.google_connect' AND entity_id = :i", ['i' => (string) $NPID], 0) === 1);
$calls = [];
$r2 = GoogleOAuth::finish(['state' => $st, 'code' => 'good-code-1'], $store, $SUP);
check('O the same state again (reused) -> refused, Google not called', !$r2['ok'] && $calls === [] && str_contains($r2['msg'], 'session'), $r2['msg']);

$gResp = ['oauth2.googleapis.com/token' => [200, array_diff_key($okToken, ['refresh_token' => 1])], 'gmail/v1/users/me/profile' => [200, ['emailAddress' => $em('np2')]]];
GoogleOAuth::start('NP', null, $store, $SUP);
$r = GoogleOAuth::finish(['state' => (string) $store[GoogleOAuth::SESSION_KEY]['state'], 'code' => 'c2'], $store, $SUP);
check('O no refresh_token from Google -> refused with "remove the app at myaccount.google.com/permissions"', !$r['ok'] && str_contains($r['msg'], 'myaccount.google.com/permissions')
    && Database::fetch('SELECT id FROM bank_mail_accounts WHERE email = :e', ['e' => $em('np2')]) === null, $r['msg']);
$gResp = ['oauth2.googleapis.com/token' => [200, ['scope' => 'openid email'] + $okToken], 'gmail/v1/users/me/profile' => [200, ['emailAddress' => $em('np2')]]];
GoogleOAuth::start('NP', null, $store, $SUP);
$r = GoogleOAuth::finish(['state' => (string) $store[GoogleOAuth::SESSION_KEY]['state'], 'code' => 'c3'], $store, $SUP);
check('O gmail.readonly not granted -> refused', !$r['ok'] && str_contains($r['msg'], 'gmail.readonly') && Database::fetch('SELECT id FROM bank_mail_accounts WHERE email = :e', ['e' => $em('np2')]) === null, $r['msg']);
$gResp = ['oauth2.googleapis.com/token' => [200, $okToken], 'gmail/v1/users/me/profile' => [200, ['emailAddress' => $em('other')]]];
$url2 = GoogleOAuth::start('NP', $npA, $store, $SUP);
parse_str((string) parse_url($url2, PHP_URL_QUERY), $q2);
$r = GoogleOAuth::finish(['state' => (string) $store[GoogleOAuth::SESSION_KEY]['state'], 'code' => 'c4'], $store, $SUP);
check('O reconnect: login_hint = the box address; choosing another Google account -> refused, the box untouched',
    ($q2['login_hint'] ?? '') === $em('np') && !$r['ok'] && str_contains($r['msg'], $em('other'))
    && BankMailAccounts::secret(BankMailAccounts::get($NPID) ?? []) === '1//MP2REFRESH' . $tag, $r['msg']);
$gResp = ['oauth2.googleapis.com/token' => [400, ['error' => 'invalid_grant', 'error_description' => 'Bad Request']]];
GoogleOAuth::start('NP', null, $store, $SUP);
$r = GoogleOAuth::finish(['state' => (string) $store[GoogleOAuth::SESSION_KEY]['state'], 'code' => 'c5'], $store, $SUP);
check('O Google refuses the code -> refused with Google\'s words, no token shown', !$r['ok'] && str_contains($r['msg'], 'invalid_grant'), $r['msg']);
check('O only https Google hosts are ever called', (static function (): bool {
    try { GoogleOAuth::request('GET', 'https://evil.example/token'); return false; } catch (Throwable $e) { /* refused */ }
    try { GoogleOAuth::request('GET', 'http://oauth2.googleapis.com/token'); return false; } catch (Throwable $e) { /* refused */ }
    return true;
})());

/* ---------------- fixtures ---------------- */
$mail = static function (array $o): string {
    $dom  = $o['dom'] ?? 'gibl.test';
    $auth = $o['auth'] ?? "mx.google.com; dkim=pass header.i=@$dom header.s=s1 header.b=AbC; spf=pass smtp.mailfrom=alerts@$dom";
    $h = "Delivered-To: bankbox@gmail.com\r\n" . ($auth !== '' ? "Authentication-Results: $auth\r\n" : '')
        . 'From: ' . ($o['from'] ?? "Bank Alerts <alerts@$dom>") . "\r\n"
        . 'Message-ID: <' . ($o['mid'] ?? bin2hex(random_bytes(6))) . "@$dom>\r\n"
        . 'Date: ' . ($o['date'] ?? date('D, d M Y H:i:s O')) . "\r\n"   // default now: amount-only looks at bookings made BEFORE the money came
        . 'Subject: ' . ($o['subject'] ?? 'Credit Alert') . "\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
    return $h . quoted_printable_encode((string) $o['plain']) . "\r\n";
};
$b64raw = static fn(string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
$npCredit = static fn(string $pnr, string $amt, string $txn): string =>
    "Dear Customer,\nYour A/C 0123456789012 has been credited by NPR $amt on 25-11-2026.\nRemarks: $pnr Ram Bahadur\nTxn ID: $txn\nAvailable Balance: NPR 55,000.00\nThank you, Global IME Bank";

foreach (Database::fetchAll('SELECT id FROM bookings WHERE contact_phone = :p', ['p' => MP2_PHONE]) as $r0) { Database::delete('bookings', 'id = :i', ['i' => (int) $r0['id']]); }
$TD = '';
for ($i = 131; $i >= 91; $i--) {
    $d = date('Y-m-d', strtotime(todayISO() . " +$i days"));
    if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE travel_date = :d', ['d' => $d], 0) === 0) { $TD = $d; break; }
}
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
Seats::schedule((int) $route['id'], $TD);
$seatList = array_merge(array_map(static fn(int $n): string => 'L' . $n, range(12, 36, 2)), array_map(static fn(int $n): string => 'U' . $n, range(2, 36, 2)));
$mk = static function (?float $total = null) use ($route, $TD, &$seatList): array {
    $res = BookingService::create(['routeId' => (int) $route['id'], 'travelDate' => $TD, 'seats' => [(string) array_shift($seatList)],
        'passengers' => [['name' => 'ZZTEST mp2bank', 'age' => 30, 'gender' => 'Male']],
        'contact' => ['phone' => MP2_PHONE], 'bookingMode' => 'sharing', 'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '']);
    if ($total !== null) {
        Database::update('bookings', ['total_amount' => $total], 'pnr = :p', ['p' => $res['pnr']]);
    }
    return Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $res['pnr']]);
};
$status = static fn(array $b): string => (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $b['id']], '');
$npr    = static fn(array $b): string => number_format(round((float) $b['total_amount'] * 1.6), 2);
$credit = static fn(string $txn): ?array => Database::fetch('SELECT * FROM bank_credits WHERE txn_id = :t', ['t' => $txn]);
$logOf  = static fn(int $aid): array => Database::fetchAll('SELECT * FROM bank_mail_log WHERE account_id = :a ORDER BY id', ['a' => $aid]);
/* a unique amount nobody else owes (amount-only tests) */
$uniq = static function (): float {
    for ($k = 0; $k < 50; $k++) {
        $v = (float) random_int(3001, 9899) + random_int(1, 98) / 100;
        if (!Database::exists('SELECT 1 FROM bookings WHERE ABS(total_amount - :v) < 0.005 OR ABS(total_amount * 1.6 - :w) < 1', ['v' => $v, 'w' => round($v * 1.6)])) { return $v; }
    }
    return 7777.77;
};

/* ---------------- G. the Gmail API reader ---------------- */
echo "\n-- G Gmail API reader (mocked)\n";
BankMailAccounts::updateRules($NPID, ['senders' => 'alerts@gibl.test', 'dkim_domain' => 'gibl.test', 'account_hint' => '789012', 'label' => 'Global IME test']);
$npA = BankMailAccounts::get($NPID);
check('G a box with a DKIM domain + our digits is READY', BankMailAccounts::isReady($npA) && GmailApi::query($npA) === 'newer_than:3d from:(alerts@gibl.test)', GmailApi::query($npA));
$happy = $mk();
$msgs = [
    'm1' . $tag => ['raw' => $mail(['plain' => 'Your A/C 0123456789012 has been debited by NPR 500.00. Txn ID: ' . $T('D1'), 'subject' => 'Debit Alert']), 'ms' => 1764044000000],
    'm2' . $tag => ['raw' => $mail(['plain' => $npCredit((string) $happy['pnr'], $npr($happy), $T('G1')), 'mid' => 'mp2g1' . $tag]), 'ms' => 1764044100000],
    'm3' . $tag => ['raw' => $mail(['plain' => 'NPR 10.00 credited to A/C ****9012 Txn ID: ' . $T('X1'), 'from' => 'Win Money <alerts@spoof.test>', 'dom' => 'spoof.test']), 'ms' => 1764044200000],
    'm4' . $tag => ['raw' => '', 'ms' => 1764044300000],
];
$gResp = ['messages?' => [200, ['messages' => array_map(static fn(string $id): array => ['id' => $id, 'threadId' => $id], array_reverse(array_keys($msgs))), 'resultSizeEstimate' => 4]]];
foreach ($msgs as $id => $m) {
    $gResp['messages/' . $id . '?format=raw'] = [200, ['id' => $id, 'raw' => $b64raw($m['raw']), 'internalDate' => (string) $m['ms']]];
}
$calls = [];
$res = BankMail::pollAccount($npA, 50);
$gc = $credit($T('G1'));
$lg = $logOf($NPID);
$getOrder = array_values(array_filter(array_map(static fn(array $c): string => (string) preg_replace('/^.*messages\/([A-Za-z0-9]+)\?.*$/', '$1', $c['url']), $calls), static fn(string $s): bool => !str_contains($s, '/')));
check('G list with the box query (newer_than:3d + from:), then each message as format=raw, OLDEST first, no token refresh while the access token is fresh',
    str_contains(rawurldecode((string) ($calls[0]['url'] ?? '')), 'q=newer_than:3d from:(alerts@gibl.test)') && $getOrder === array_keys($msgs)
    && array_filter($calls, static fn(array $c): bool => str_contains($c['url'], 'oauth2.googleapis.com')) === [], json_encode(array_column($calls, 'url')));
check('G poll counts: 4 read, 1 debit, 1 credit suggested (auto OFF), 1 unreadable stepped over',
    $res['ok'] && $res['seen'] === 4 && $res['debits'] === 1 && $res['credits'] === 1 && $res['suggested'] === 1 && $res['failed'] === 1 && $res['confirmed'] === 0, json_encode($res));
check('G the credit carries mail_account_id + NPR + match_kind pnr, a SUGGESTION for the booking, booking still pending',
    $gc !== null && (int) $gc['mail_account_id'] === $NPID && $gc['currency'] === 'NPR' && $gc['match_kind'] === 'pnr' && PayCredits::isSuggestion($gc)
    && (int) $gc['booking_id'] === (int) $happy['id'] && $status($happy) === 'pending', json_encode($gc, JSON_UNESCAPED_UNICODE));
check('G one bank_mail_log row per mail, oldest first: debit-ignored, suggestion (credit #), not-bank-sender, unreadable',
    array_column($lg, 'outcome') === ['debit-ignored', 'suggestion', 'not-bank-sender', 'unreadable']
    && (int) $lg[1]['credit_id'] === (int) ($gc['id'] ?? -1) && $lg[0]['msg_ref'] === 'g:m1' . $tag && $lg[1]['subject'] === 'Credit Alert', json_encode($lg, JSON_UNESCAPED_UNICODE));
check('G the spoofed sender stored no credit', $credit($T('X1')) === null);
$npA = BankMailAccounts::get($NPID);
check('G the box remembers the newest internalDate + ✓ last_ok + the summary', (int) $npA['last_seen_ms'] === 1764044300000 && (int) $npA['last_ok'] === 1 && str_contains((string) $npA['last_result'], '4 read'), json_encode([$npA['last_seen_ms'], $npA['last_result']]));
$calls = [];
$res2 = BankMail::pollAccount($npA, 50);
check('G the next run asks after:<last seen - 2 min> and fetches none of the 4 again (seen)', $res2['ok'] && $res2['seen'] === 0
    && str_contains(rawurldecode((string) ($calls[0]['url'] ?? '')), 'after:' . (intdiv(1764044300000, 1000) - 120)) && count($calls) === 1 && count($logOf($NPID)) === 4, json_encode(array_column($calls, 'url')));
/* the access token is refreshed when < 60 s are left */
Database::update('bank_mail_accounts', ['access_expires_at' => date('Y-m-d H:i:s', time() + 30)], 'id = :i', ['i' => $NPID]);
$gResp = ['oauth2.googleapis.com/token' => [200, ['access_token' => 'ya29.MP2NEW' . $tag, 'expires_in' => 3599, 'scope' => GoogleOAuth::SCOPE]], 'messages?' => [200, ['resultSizeEstimate' => 0]]];
$calls = [];
$res3 = BankMail::pollAccount(BankMailAccounts::get($NPID), 50);
$npA = BankMailAccounts::get($NPID);
check('G access token with < 60 s left -> refreshed (grant_type refresh_token with the sealed refresh token), the new one sealed and used',
    $res3['ok'] && ($calls[0]['form']['grant_type'] ?? '') === 'refresh_token' && ($calls[0]['form']['refresh_token'] ?? '') === '1//MP2REFRESH' . $tag
    && SecretBox::open((string) $npA['access_enc'], 'bankmail') === 'ya29.MP2NEW' . $tag && !str_contains((string) $npA['access_enc'], 'MP2NEW')
    && str_contains((string) ($calls[1]['auth'] ?? ''), 'Bearer ya29.MP2NEW' . $tag), json_encode($calls));
/* Confirm (link) on the Gmail API suggestion: the same gate as mp1 */
$cr = PayCredits::link((int) $gc['id'], (string) $happy['pnr'], $SUP);
check('G Confirm on the box\'s suggestion confirms the booking through the queue gate (GIME- reference)', $cr['ok'] && $status($happy) === 'confirmed'
    && Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND utr_number = :u AND status = 'verified'", ['b' => (int) $happy['id'], 'u' => 'GIME-' . $T('G1')]), json_encode($cr));

/* ---------------- I. Indian alerts ---------------- */
echo "\n-- I Indian bank alerts (INR)\n";
$inOpts = ['dkim_domain' => 'icici.test', 'authserv' => 'mx.google.com', 'amount_regex' => '', 'txn_regex' => '', 'account_regex' => '', 'currency' => 'INR'];
$pI = BankMail::parse($mail(['dom' => 'icici.test', 'plain' => "Dear Customer,\nRs 2,200.00 credited to A/c XX1234 on 25-Nov-26 by UPI Ref No 123456789012.\nAvl Bal Rs 15,000.00\n- ICICI Bank"]), $inOpts);
check('I ICICI "Rs 2,200.00 credited to A/c XX1234 … UPI Ref No 123456789012": credit, INR 2200, txn, A/C …1234, DKIM ok',
    $pI['is_credit'] && $pI['amount'] === 2200.0 && $pI['currency'] === 'INR' && $pI['txn_id'] === '123456789012' && PayCredits::accountMatches($pI['account'], '1234') && $pI['dkim_ok'] && $pI['hold'] === '', json_encode($pI, JSON_UNESCAPED_UNICODE));
$pH = BankMail::parse($mail(['dom' => 'icici.test', 'plain' => "Dear Customer, INR 2200.00 has been credited to your account XXXXXXXX1234 on 25-11-26 by VPA payer.ram@okaxis (RAM KUMAR).\nUPI ref no 432112345678.\nRemarks: SHG-2026-00911"]), $inOpts);
check('I HDFC "INR 2200.00 … VPA … UPI ref no": INR 2200, txn 432112345678, the PNR in the remarks',
    $pH['is_credit'] && $pH['amount'] === 2200.0 && $pH['txn_id'] === '432112345678' && $pH['pnr'] === 'SHG-2026-00911' && PayCredits::accountMatches($pH['account'], '1234'), json_encode($pH, JSON_UNESCAPED_UNICODE));
$pS = BankMail::parse($mail(['dom' => 'icici.test', 'plain' => "Dear SBI User, your A/c X1234-credited by Rs.2,200.00 on 25Nov26 transfer from RAM KUMAR Ref No 512345678901 -SBI"]), $inOpts);
check('I SBI "A/c X1234-credited by Rs.2,200.00 … Ref No 512345678901": INR 2200, txn, A/C …1234', $pS['is_credit'] && $pS['amount'] === 2200.0 && $pS['txn_id'] === '512345678901' && PayCredits::accountMatches($pS['account'], '1234'), json_encode($pS, JSON_UNESCAPED_UNICODE));
$pR = BankMail::parse($mail(['dom' => 'icici.test', 'plain' => "₹2,200 has been credited to your account **1234.\nYour UPI transaction reference number is 432112345679."]), $inOpts);
check('I "₹2,200 … UPI transaction reference number is 432112345679" -> INR 2200 + that txn', $pR['amount'] === 2200.0 && $pR['txn_id'] === '432112345679', json_encode([$pR['amount'], $pR['txns']]));
$pU = BankMail::parse($mail(['dom' => 'icici.test', 'plain' => "INR 2,200.00 credited to A/c XX1234 by IMPS.\nIMPS Ref No 612345678901\nRRN 612345678901\nUTR: 612345678901"]), $inOpts);
check('I IMPS Ref No / RRN / UTR with the SAME id -> one txn (the single-candidate rule holds)', $pU['txn_id'] === '612345678901' && $pU['hold'] === '', json_encode($pU['txns']));
$pX = BankMail::parse($mail(['dom' => 'icici.test', 'plain' => "INR 2,200.00 credited to A/c XX1234.\nNEFT Ref N123456789012\nUTR 712345678901"]), $inOpts);
check('I two DIFFERENT ids (NEFT Ref + UTR) -> no txn, kept for a person', $pX['txn_id'] === '' && str_contains($pX['hold'], 'Different transaction'), json_encode([$pX['txns'], $pX['hold']]));
$p2 = BankMail::parse($mail(['dom' => 'icici.test', 'plain' => "Rs 2,200.00 credited to A/c XX1234. UPI Ref No 812345678901. Info: Rs 99 cashback"]), $inOpts);
check('I a second rupee figure -> amount 0 + hold "Different amounts (INR …)"', $p2['amount'] === 0.0 && str_contains($p2['hold'], 'INR 2,200.00'), $p2['hold']);

/* an India box (app password, IMAP scripted - no network) */
$inA = ['id' => BankMailAccounts::save(['country' => 'IN', 'email' => $em('in'), 'method' => 'app_password', 'secret_enc' => SecretBox::seal('abcdefghijklmnop', 'bankmail'),
    'senders' => 'alerts@icici.test', 'dkim_domain' => 'icici.test', 'account_hint' => '1234', 'label' => 'ICICI test'], $SUP)];
$INID = (int) $inA['id'];
$acctIds[] = $INID;
Database::update('bank_mail_accounts', ['last_uid' => 6000], 'id = :i', ['i' => $INID]);
$inA = BankMailAccounts::get($INID);
check('I India box stored: IN / INR, app password sealed', $inA['country'] === 'IN' && $inA['currency'] === 'INR' && !str_contains((string) $inA['secret_enc'], 'abcdefgh') && BankMailAccounts::secret($inA) === 'abcdefghijklmnop');
$imapFor = static function (array $msgs): BankMailImap {
    $s = "* 3 EXISTS\r\n* OK [UIDVALIDITY 5151] ok\r\nS0001 OK [READ-ONLY] done\r\n* SEARCH " . implode(' ', array_keys($msgs)) . "\r\nS0002 OK done\r\n";
    $n = 3;
    foreach ($msgs as $uid => $raw) {
        $s .= "* 1 FETCH (UID $uid BODY[] {" . strlen($raw) . "}\r\n" . $raw . ")\r\nS" . str_pad((string) $n++, 4, '0', STR_PAD_LEFT) . " OK done\r\n";
    }
    $in = fopen('php://temp', 'w+'); fwrite($in, $s); rewind($in);
    return new BankMailImap($in, fopen('php://memory', 'w+'));
};
$inB = $mk();
$inAmt = number_format((float) $inB['total_amount'], 2);
$inRaw = $mail(['dom' => 'icici.test', 'mid' => 'mp2in1' . $tag, 'plain' => "Dear Customer,\nRs $inAmt credited to A/c XX1234 on 25-Nov-26 by UPI Ref No " . $T('I1') . "1.\nRemarks: " . $inB['pnr'] . "\nAvl Bal Rs 15,000.00"]);
$resI = BankMail::pollAccount($inA, 50, $imapFor([6001 => $inRaw]));
$ic = $credit($T('I1') . '1');
check('I India box, INR alert with the PNR + exact INR due -> a SUGGESTION (INR, mail_account_id, pnr), booking pending',
    $resI['ok'] && $resI['suggested'] === 1 && $ic !== null && $ic['currency'] === 'INR' && (int) $ic['mail_account_id'] === $INID && $ic['match_kind'] === 'pnr'
    && PayCredits::isSuggestion($ic) && (int) $ic['booking_id'] === (int) $inB['id'] && $status($inB) === 'pending', json_encode([$resI, $ic], JSON_UNESCAPED_UNICODE));
check('I the bell says ₹ and opens the India tab', (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE booking_id = :b AND title LIKE :t AND link = '/admin/payment-center.php?tab=in'",
    ['b' => (int) $inB['id'], 't' => 'बैंक इमेल: ₹ ' . $inAmt . ' आयो%'], 0) === 1);
check('I the box moved its IMAP position + uidvalidity; one log row "suggestion"', (int) (BankMailAccounts::get($INID)['last_uid'] ?? 0) === 6001 && (string) (BankMailAccounts::get($INID)['uidvalidity'] ?? '') === '5151'
    && array_column($logOf($INID), 'outcome') === ['suggestion']);
$sv = PayCredits::suggestionView($ic);
check('I suggestionView uses the BOX rules (A/C …1234, sender, DKIM) and offers Confirm', $sv !== null && $sv['can_confirm'] && in_array(['A/C …1234', true], $sv['checks'], true) && $sv['kind'] === 'pnr', json_encode($sv, JSON_UNESCAPED_UNICODE));
$crI = PayCredits::link((int) $ic['id'], (string) $inB['pnr'], $SUP);
$tkI = Database::fetch('SELECT status, mode, note FROM booking_collections WHERE booking_id = :b', ['b' => (int) $inB['id']]);
check('I Confirm -> booking confirmed, payment INBANK-…, chalani "PAID - Indian online (Gmail)" confirmed by the person',
    $crI['ok'] && $status($inB) === 'confirmed' && Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND utr_number = :u AND status = 'verified'", ['b' => (int) $inB['id'], 'u' => 'INBANK-' . $T('I1') . '1'])
    && ($tkI['mode'] ?? '') === 'PAID - Indian online (Gmail)' && str_contains((string) ($tkI['note'] ?? ''), 'confirmed by MP2 superadmin'), json_encode([$crI, $tkI], JSON_UNESCAPED_UNICODE));

/* ---------------- A. amount-only ---------------- */
echo "\n-- A amount-only (no PNR in the alert)\n";
$u1 = $uniq();
$aB = $mk($u1);
$aRaw = $mail(['dom' => 'icici.test', 'mid' => 'mp2am1' . $tag, 'plain' => "Dear Customer,\nRs " . number_format($u1, 2) . " credited to A/c XX1234 on 25-Nov-26 by UPI Ref No " . $T('A1') . "2.\nAvl Bal Rs 15,000.00"]);
$resA = BankMail::pollAccount(BankMailAccounts::get($INID), 50, $imapFor([6002 => $aRaw]));
$ac = $credit($T('A1') . '2');
$lA = $logOf($INID);
check('A INR, no PNR, exactly one booking owes exactly the amount -> suggestion match_kind "amount", note "PNR छैन — रकम मात्र मिल्यो"',
    $resA['suggested'] === 1 && $ac !== null && $ac['match_kind'] === 'amount' && PayCredits::isSuggestion($ac) && (int) $ac['booking_id'] === (int) $aB['id']
    && str_contains((string) $ac['note'], PayCredits::AMOUNT_NOTE) && $status($aB) === 'pending' && (string) ($lA[count($lA) - 1]['outcome'] ?? '') === 'suggestion-amount', json_encode([$resA, $ac], JSON_UNESCAPED_UNICODE));
$svA = PayCredits::suggestionView($ac);
check('A the page view marks it amount-only (kind amount, the booking phone for the person to check)', $svA !== null && $svA['kind'] === 'amount' && $svA['phone'] === MP2_PHONE);
/* the auto switch forced ON (and the old master switch too): an amount-only match still never confirms */
setKeep('pay_bankmail_autoconfirm_on', '1', 'bool');
setKeep('bankmail_on', '1', 'bool');
$u2 = $uniq();
$aB2 = $mk($u2);
$aRaw2 = $mail(['mid' => 'mp2am2' . $tag, 'plain' => "Dear Customer,\nYour A/C 0123456789012 has been credited by NPR " . number_format(round($u2 * 1.6), 2) . " on 25-11-2026.\nTxn ID: " . $T('A2') . "\nAvailable Balance: NPR 55,000.00"]);
$gResp = ['messages?' => [200, ['messages' => [['id' => 'am2' . $tag]]]], 'messages/am2' . $tag => [200, ['id' => 'am2' . $tag, 'raw' => $b64raw($aRaw2), 'internalDate' => '1764045000000']]];
$resA2 = BankMail::pollAccount(BankMailAccounts::get($NPID), 50);
$ac2 = $credit($T('A2'));
check('A NPR (Nepal box), no PNR, one booking owes round(due x 1.6): suggestion "amount" — with the auto switch ON it STILL does not confirm',
    $resA2['ok'] && $resA2['confirmed'] === 0 && $resA2['suggested'] === 1 && $ac2 !== null && $ac2['match_kind'] === 'amount' && PayCredits::isSuggestion($ac2)
    && (int) $ac2['booking_id'] === (int) $aB2['id'] && $status($aB2) === 'pending'
    && !Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND (utr_number = :u OR status = 'verified')", ['b' => (int) $aB2['id'], 'u' => 'GIME-' . $T('A2')]), json_encode([$resA2, $ac2], JSON_UNESCAPED_UNICODE));
/* while the switch is ON, a PNR match from a box does auto-confirm (the old rule, unchanged), amount-only never */
$pnrB = $mk();
$gResp = ['messages?' => [200, ['messages' => [['id' => 'ap1' . $tag]]]], 'messages/ap1' . $tag => [200, ['id' => 'ap1' . $tag,
    'raw' => $b64raw($mail(['mid' => 'mp2ap1' . $tag, 'plain' => $npCredit((string) $pnrB['pnr'], $npr($pnrB), $T('P1'))])), 'internalDate' => '1764045100000']]];
$resP = BankMail::pollAccount(BankMailAccounts::get($NPID), 50);
check('A (contrast) switch ON + a PNR match from the box -> auto-confirmed (chalani "PAID - Nepali online (Gmail Auto)")', $resP['confirmed'] === 1 && $status($pnrB) === 'confirmed'
    && (string) Database::scalar('SELECT mode FROM booking_collections WHERE booking_id = :b', ['b' => (int) $pnrB['id']], '') === 'PAID - Nepali online (Gmail Auto)', json_encode($resP));
unsetKeep('pay_bankmail_autoconfirm_on');
setKeep('bankmail_on', '0', 'bool');
/* two bookings owe the same amount -> unmatched with the PNR list */
$u3 = $uniq();
$tw1 = $mk($u3);
$tw2 = $mk($u3);
$aRaw3 = $mail(['dom' => 'icici.test', 'mid' => 'mp2am3' . $tag, 'plain' => "Dear Customer,\nRs " . number_format($u3, 2) . " credited to A/c XX1234 by UPI Ref No " . $T('A3') . "3.\n"]);
$resA3 = BankMail::pollAccount(BankMailAccounts::get($INID), 50, $imapFor([6003 => $aRaw3]));
$ac3 = $credit($T('A3') . '3');
check('A two bookings owe exactly the amount -> unmatched "रकम मिल्ने धेरै टिकट · several bookings match the amount" with both PNRs, nothing suggested',
    $resA3['unmatched'] === 1 && $ac3 !== null && $ac3['state'] === 'unmatched' && $ac3['booking_id'] === null
    && str_contains((string) $ac3['note'], 'रकम मिल्ने धेरै टिकट · several bookings match the amount') && str_contains((string) $ac3['note'], (string) $tw1['pnr']) && str_contains((string) $ac3['note'], (string) $tw2['pnr'])
    && $status($tw1) === 'pending' && $status($tw2) === 'pending', json_encode($ac3, JSON_UNESCAPED_UNICODE));
$aRaw4 = $mail(['dom' => 'icici.test', 'mid' => 'mp2am4' . $tag, 'plain' => "Dear Customer,\nRs 1.37 credited to A/c XX1234 by UPI Ref No " . $T('A4') . "4.\n"]);
BankMail::pollAccount(BankMailAccounts::get($INID), 50, $imapFor([6004 => $aRaw4]));
$ac4 = $credit($T('A4') . '4');
check('A no PNR and nobody owes that amount -> unmatched "no booking … owes exactly"', $ac4 !== null && $ac4['state'] === 'unmatched' && str_contains((string) $ac4['note'], 'owes exactly INR 1.37'), (string) ($ac4['note'] ?? ''));
/* money that came BEFORE the booking existed is never offered for it on the amount alone */
$u5 = $uniq();
$oldB = $mk($u5);
$aRaw5 = $mail(['dom' => 'icici.test', 'mid' => 'mp2am5' . $tag, 'date' => date('D, d M Y H:i:s O', time() - 2 * 86400),
    'plain' => "Dear Customer,\nRs " . number_format($u5, 2) . " credited to A/c XX1234 by UPI Ref No " . $T('A5') . "5.\n"]);
BankMail::pollAccount(BankMailAccounts::get($INID), 50, $imapFor([6005 => $aRaw5]));
$ac5 = $credit($T('A5') . '5');
check('A an alert from 2 days ago, a pending booking made now owing the same INR -> unmatched (the booking did not exist yet), no suggestion',
    $ac5 !== null && $ac5['state'] === 'unmatched' && $ac5['booking_id'] === null && str_contains((string) $ac5['note'], 'owes exactly') && $status($oldB) === 'pending', json_encode($ac5, JSON_UNESCAPED_UNICODE));
$aRaw6 = $mail(['dom' => 'icici.test', 'mid' => 'mp2am6' . $tag, 'date' => date('D, d M Y H:i:s O', time() - (PayCredits::AMOUNT_WINDOW_H + 2) * 3600),
    'plain' => "Dear Customer,\nRs " . number_format($u5, 2) . " credited to A/c XX1234 by UPI Ref No " . $T('A6') . "6.\n"]);
BankMail::pollAccount(BankMailAccounts::get($INID), 50, $imapFor([6006 => $aRaw6]));
$ac6 = $credit($T('A6') . '6');
check('A an alert older than ' . PayCredits::AMOUNT_WINDOW_H . ' h with no PNR (a new / resumed box reading back) -> unmatched "old alert", never amount-matched',
    $ac6 !== null && $ac6['state'] === 'unmatched' && $ac6['booking_id'] === null && str_contains((string) $ac6['note'], 'old alert'), json_encode($ac6, JSON_UNESCAPED_UNICODE));
/* the only booking owing the amount already has an open suggestion: a second, remark-less payment is NOT pinned on anyone */
$aRaw7 = $mail(['dom' => 'icici.test', 'mid' => 'mp2am7' . $tag, 'plain' => "Dear Customer,\nRs " . number_format($u1, 2) . " credited to A/c XX1234 by UPI Ref No " . $T('A7') . "7.\n"]);
BankMail::pollAccount(BankMailAccounts::get($INID), 50, $imapFor([6007 => $aRaw7]));
$ac7 = $credit($T('A7') . '7');
check('A the one booking owing the amount already has a suggestion -> the second credit is unmatched "already has a suggestion", not suggested again',
    $ac7 !== null && $ac7['state'] === 'unmatched' && $ac7['booking_id'] === null && str_contains((string) $ac7['note'], (string) $aB['pnr'] . ' already has a suggestion')
    && PayCredits::isSuggestion($credit($T('A1') . '2') ?? []), json_encode($ac7, JSON_UNESCAPED_UNICODE));
$tw3 = $mk($u1);
$aRaw8 = $mail(['dom' => 'icici.test', 'mid' => 'mp2am8' . $tag, 'plain' => "Dear Customer,\nRs " . number_format($u1, 2) . " credited to A/c XX1234 by UPI Ref No " . $T('A8') . "8.\n"]);
BankMail::pollAccount(BankMailAccounts::get($INID), 50, $imapFor([6008 => $aRaw8]));
$ac8 = $credit($T('A8') . '8');
check('A booking A (already suggested) + a new booking B owe the same amount -> "several bookings", both PNRs, B NOT suggested',
    $ac8 !== null && $ac8['state'] === 'unmatched' && $ac8['booking_id'] === null && str_contains((string) $ac8['note'], 'several bookings match the amount')
    && str_contains((string) $ac8['note'], (string) $aB['pnr']) && str_contains((string) $ac8['note'], (string) $tw3['pnr']), json_encode($ac8, JSON_UNESCAPED_UNICODE));

/* ---------------- R. per-box rules ---------------- */
echo "\n-- R per-box rules (never the global bankmail_* settings)\n";
/* the global settings say something else entirely: they must not matter for a box */
setKeep('bankmail_from', 'alerts@spoof.test', 'string');
setKeep('bankmail_dkim_domain', 'spoof.test', 'string');
setKeep('bankmail_account_hint', '9999', 'string');
$rB = $mk();
$rAmt = number_format((float) $rB['total_amount'], 2);
$rr = [
    6101 => $mail(['dom' => 'icici.test', 'mid' => 'mp2r1' . $tag, 'auth' => 'mx.google.com; dkim=pass header.i=@other.test header.s=s1', 'plain' => "Rs $rAmt credited to A/c XX1234 UPI Ref No " . $T('R1') . "1 Remarks: " . $rB['pnr']]),
    6102 => $mail(['dom' => 'spoof.test', 'mid' => 'mp2r2' . $tag, 'plain' => "Rs $rAmt credited to A/c XX1234 UPI Ref No " . $T('R2') . "2 Remarks: " . $rB['pnr']]),
    6103 => $mail(['dom' => 'icici.test', 'mid' => 'mp2r3' . $tag, 'plain' => "Rs $rAmt credited to A/c XX9999 UPI Ref No " . $T('R3') . "3 Remarks: " . $rB['pnr']]),
];
$resR = BankMail::pollAccount(BankMailAccounts::get($INID), 50, $imapFor($rr));
$r1 = $credit($T('R1') . '1');
$r3 = $credit($T('R3') . '3');
check('R the box\'s DKIM domain decides: signed by other.test -> stored without DKIM, unmatched "No DKIM pass"', $r1 !== null && (int) $r1['dkim_ok'] === 0 && $r1['state'] === 'unmatched' && str_contains((string) $r1['note'], 'No DKIM'), json_encode($r1));
check('R the box\'s sender list decides: alerts@spoof.test (the GLOBAL allowed sender) -> "not-bank-sender", no credit', $credit($T('R2') . '2') === null
    && in_array('not-bank-sender', array_column($logOf($INID), 'outcome'), true));
check('R the box\'s account digits decide: A/C XX9999 (the GLOBAL hint) -> unmatched "not to our account"', $r3 !== null && $r3['state'] === 'unmatched' && str_contains((string) $r3['note'], 'not to our account'), json_encode($r3));
check('R the booking stayed pending', $status($rB) === 'pending' && $resR['ok']);
/* a box without DKIM domain / digits: NOT read at all (no alert used up before its rules exist) */
$incId = BankMailAccounts::save(['country' => 'IN', 'email' => $em('inc'), 'method' => 'app_password', 'secret_enc' => SecretBox::seal('abcdefghijklmnop', 'bankmail')], $SUP);
$acctIds[] = $incId;
$incA = BankMailAccounts::get($incId);
$resInc = BankMail::pollAccount($incA, 50, $imapFor([7001 => $mail(['dom' => 'icici.test', 'mid' => 'mp2inc' . $tag, 'plain' => "Rs $rAmt credited to A/c XX1234 UPI Ref No " . $T('N1') . "1 Remarks: " . $rB['pnr']])]));
check('R a box without DKIM domain + digits is NOT ready: nothing is read (no credit, no log row, no position), the box says "setup incomplete, not reading yet"',
    !BankMailAccounts::isReady($incA) && $resInc['ok'] && $resInc['seen'] === 0 && $resInc['credits'] === 0 && $credit($T('N1') . '1') === null
    && $logOf($incId) === [] && (BankMailAccounts::get($incId)['last_uid'] ?? null) === null
    && str_contains((string) (BankMailAccounts::get($incId)['last_result'] ?? ''), 'setup incomplete, not reading yet'), json_encode($resInc));
/* a race with Edit (the box loses its rules mid-run): logged "setup-incomplete" WITHOUT a msg_ref, so it is read again later */
$resRace = ['credits' => 0, 'other' => 0, 'incomplete' => 0];
BankMail::handle($mail(['dom' => 'icici.test', 'mid' => 'mp2inc' . $tag, 'plain' => "Rs $rAmt credited to A/c XX1234 UPI Ref No " . $T('N1') . "1 Remarks: " . $rB['pnr']]), $resRace, $incA, 'uid:7001');
$lInc = $logOf($incId);
check('R handle() on a box that is not ready: "setup-incomplete" logged with no msg_ref (not "seen"), no credit', $resRace['credits'] === 0 && $resRace['incomplete'] === 1
    && array_column($lInc, 'outcome') === ['setup-incomplete'] && $lInc[0]['msg_ref'] === null && BankMailAccounts::seen($incId, ['uid:7001']) === [] && $credit($T('N1') . '1') === null);
Database::update('bank_mail_accounts', ['last_uid' => 7001, 'uidvalidity' => '5151'], 'id = :i', ['i' => $incId]);   // as if an older build had moved on
check('R the IMAP query of a box: FROM its senders, else its DKIM domain', BankMail::fromCriteria(BankMailAccounts::senders($inA)) === 'FROM "alerts@icici.test"' && GmailApi::query(['senders' => '', 'dkim_domain' => 'gibl.test']) === 'newer_than:3d from:(gibl.test)');
check('R senderRule: blank senders = any address under the DKIM domain; nothing set = no rule (null)',
    BankMailAccounts::senderRule(['senders' => '', 'dkim_domain' => 'gibl.test'], 'x@alerts.gibl.test') === true && BankMailAccounts::senderRule(['senders' => '', 'dkim_domain' => 'gibl.test'], 'x@evil.test') === false
    && BankMailAccounts::senderRule(['senders' => '', 'dkim_domain' => ''], 'x@evil.test') === null);
/* a paused box: its credits are not suggested by the cron, and poll() skips it */
BankMailAccounts::setActive($incId, false);
check('R a paused box is not in active() (the cron leaves it alone)', !in_array($incId, array_map('intval', array_column(BankMailAccounts::active(), 'id')), true));

/* ---------------- G2. invalid_grant -> reconnect ---------------- */
echo "\n-- G invalid_grant -> reconnect\n";
Database::update('bank_mail_accounts', ['access_expires_at' => date('Y-m-d H:i:s', time() - 5)], 'id = :i', ['i' => $NPID]);
$gResp = ['oauth2.googleapis.com/token' => [400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']]];
$calls = [];
$resX = BankMail::pollAccount(BankMailAccounts::get($NPID), 50);
$npA = BankMailAccounts::get($NPID);
check('G invalid_grant -> the run stops, the box says "Google access removed - reconnect" (last_ok 0), no Gmail call',
    !$resX['ok'] && (int) $npA['last_ok'] === 0 && (string) $npA['last_result'] === GoogleOAuth::RECONNECT && count($calls) === 1 && $npA['access_enc'] === null, json_encode([$resX['msg'], $npA['last_result'], count($calls)]));
/* every app-password box paused for this one run: poll() would otherwise really dial imap.gmail.com */
$wasOn = [];
foreach (BankMailAccounts::active() as $x) {
    if ((string) $x['method'] !== 'google') { $wasOn[] = (int) $x['id']; BankMailAccounts::setActive((int) $x['id'], false); }
}
$calls = [];
$all = BankMail::poll(50);
foreach ($wasOn as $x) { BankMailAccounts::setActive($x, true); }
check('G the cron (poll) skips a box waiting for reconnect - no Google call for it', !in_array(true, array_map(static fn(array $c): bool => str_contains($c['url'], 'oauth2') , $calls), true)
    && str_contains((string) $all['msg'], GoogleOAuth::RECONNECT), json_encode([$all['msg'], array_column($calls, 'url')]));

/* ---------------- P. the admin pages ---------------- */
echo "\n-- P admin/payment-center.php + admin/gmail-oauth.php\n";
$ini = php_ini_loaded_file();
$probe = (string) shell_exec(escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' -r "echo extension_loaded(\'sodium\') ? 1 : 0;"');
$sodiumFlag = trim($probe) === '1' ? '' : ' -d extension=sodium';
$page = static function (string $script, int $admin, array $get = [], ?array $post = null, bool $nocsrf = false, array $mock = []) use ($ini, $sodiumFlag): string {
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . $sodiumFlag . ' ' . escapeshellarg(__FILE__) . ' --page '
        . escapeshellarg(base64_encode((string) json_encode(['script' => $script, 'admin' => $admin, 'get' => $get, 'post' => $post, 'nocsrf' => $nocsrf, 'mock' => $mock]))) . ' 2>&1';
    return (string) shell_exec($cmd);
};
$warn = static fn(string $h): bool => preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)[: ]/', $h) === 1;
/* the Nepal box back to a good state for the page */
Database::update('bank_mail_accounts', ['access_enc' => SecretBox::seal('ya29.MP2PAGE' . $tag, 'bankmail'), 'access_expires_at' => date('Y-m-d H:i:s', time() + 3000), 'last_ok' => 1, 'last_result' => 'ok'], 'id = :i', ['i' => $NPID]);
$h = $page('payment-center.php', $SUP, ['tab' => 'np']);
$box = (string) substr($h, (int) strpos($h, 'id="pc-acc-' . $NPID . '"'), 14000);
check('P Nepal tab: the "📧 बैंक Gmail खाताहरू" card with the Google box, Google login chip, ✓ connected, Check now / Pause / Reconnect / Remove / Edit / Latest mails, no PHP warning',
    !$warn($h) && str_contains($h, 'बैंक Gmail खाताहरू · Bank Gmail accounts') && str_contains($box, $em('np')) && str_contains($box, 'Google login') && str_contains($box, '✓ जोडिएको')
    && str_contains($box, 'अहिले जाँच्नुहोस् · Check now') && str_contains($box, 'पज · Pause') && str_contains($box, 'फेरि जोड्नुहोस्') && str_contains($box, 'gmail-oauth.php?start=1&amp;country=NP&amp;account=' . $NPID)
    && str_contains($box, 'हटाउनुहोस्') && str_contains($box, 'onsubmit="return confirm(') && str_contains($box, 'name="dkim_domain"') && str_contains($box, '📬 पछिल्लो mail · Latest mails'),
    substr((string) preg_replace('/\s+/', ' ', strip_tags($h)), 0, 400));
check('P the box\'s latest mails = its log (escaped subjects, outcome chips)', str_contains($box, '💡 सुझाव') && str_contains($box, 'डेबिट · ignored') && str_contains($box, 'बैंकको होइन · not bank sender') && str_contains($box, '✖ पढ्न सकिएन'));
check('P "➕ Gmail जोड्नुहोस्": the big "🔐 Google बाट Login गर्नुहोस् (browser जस्तै)" (client saved) + the app-password form', str_contains($h, '🔐 Google बाट Login गर्नुहोस् (browser जस्तै)')
    && str_contains($h, 'href="gmail-oauth.php?start=1&amp;country=NP"') && str_contains($h, 'value="acct_add_pw"') && str_contains($h, 'App passwords'));
check('P Nepal credits show the box address + the PNR / amount-only chips; no secret ever printed', str_contains($h, '📧 ' . $em('np')) && str_contains($h, 'रकम मात्र · amount only')
    && !str_contains($h, 'GOCSPX-mp2-local-test-secret') && !str_contains($h, 'MP2REFRESH') && !str_contains($h, 'MP2PAGE') && !str_contains($h, 'abcdefghijklmnop'));
unsetKeep('google_oauth_client_id');
$h = $page('payment-center.php', $SUP, ['tab' => 'in']);
$inBox = (string) substr($h, (int) strpos($h, 'id="pc-acc-' . $incId . '"'), 3000);
check('P India tab: the note "बैंक API credential नआउन्जेल …", the India boxes, the bank API card, INR credits, no PHP warning',
    !$warn($h) && str_contains($h, 'बैंक API credential नआउन्जेल Gmail bank alert बाट पैसा आएको हेर्न सकिन्छ (सुझाव + Confirm)') && str_contains($h, 'id="pc-mail-IN"')
    && str_contains($h, $em('in')) && str_contains($h, 'App password') && str_contains($h, 'payment/bank-webhook.php') && str_contains($h, 'Credits (INR · bank API + Gmail)')
    && str_contains($h, 'रकम मिल्ने धेरै टिकट'), substr((string) preg_replace('/\s+/', ' ', strip_tags($h)), 0, 400));
check('P the incomplete box shows "अधुरो · setup incomplete" and ⏸ paused', str_contains($inBox, 'अधुरो · setup incomplete') && str_contains($inBox, '⏸ पज · paused') && str_contains($inBox, '▶ चालु · Resume'));
check('P no Google client yet -> the one-time setup box: numbered Nepali steps, Gmail API, gmail.readonly, Test users, "Publish app", the exact redirect URI with Copy, the "not verified" hint',
    str_contains($h, 'console.cloud.google.com') && str_contains($h, '"Gmail API"') && str_contains($h, '…/auth/gmail.readonly') && str_contains($h, 'Test users')
    && str_contains($h, '"Publish app"') && str_contains($h, 'हरेक ७ दिनमा logout') && str_contains($h, 'value="' . Security::e($redir) . '"') && str_contains($h, 'pcCopy(this)')
    && str_contains($h, 'Google hasn\'t verified this app') && str_contains($h, 'name="google_oauth_client_id"') && !str_contains($h, '🔐 Google बाट Login गर्नुहोस् (browser जस्तै)</a>'));
$h = $page('payment-center.php', $MGR, ['tab' => 'np']);
check('P a manager is refused (super-admin only)', !str_contains($h, 'बैंक Gmail खाताहरू') && (str_contains($h, 'super-admin only') || str_contains($h, '<!--HTTP:403-->')));

/* POSTs: CSRF first */
$tg = ['act' => 'acct_toggle', 'tab' => 'np', 'id' => (string) $NPID];
$h = $page('payment-center.php', $SUP, ['tab' => 'np'], $tg, true);
check('P Pause with a forged CSRF token -> "Session expired", the box still on', str_contains($h, 'Session expired') && (int) BankMailAccounts::get($NPID)['is_active'] === 1);
$h = $page('payment-center.php', $SUP, ['tab' => 'np'], $tg);
check('P Pause (CSRF) -> paused + audited bank_mail.pause', str_contains($h, '⏸ पज · paused') && (int) BankMailAccounts::get($NPID)['is_active'] === 0
    && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'bank_mail.pause' AND entity_id = :i", ['i' => (string) $NPID], 0) === 1);
$h = $page('payment-center.php', $SUP, ['tab' => 'np'], ['act' => 'acct_check', 'tab' => 'np', 'id' => (string) $NPID], false, [['gmail/v1/users/me/messages?', 200, ['resultSizeEstimate' => 0]]]);
check('P Check now on a paused box -> "resume it first", nothing read', str_contains($h, 'resume it first') && !str_contains($h, '0 read'));
$page('payment-center.php', $SUP, ['tab' => 'np'], $tg);
check('P Resume -> on again', (int) BankMailAccounts::get($NPID)['is_active'] === 1);
$h = $page('payment-center.php', $MGR, ['tab' => 'np'], $tg);
check('P a manager\'s Pause post is refused', (int) BankMailAccounts::get($NPID)['is_active'] === 1);
$ck = ['act' => 'acct_check', 'tab' => 'np', 'id' => (string) $NPID];
$mock = [['gmail/v1/users/me/messages?', 200, ['resultSizeEstimate' => 0]]];
$h = $page('payment-center.php', $SUP, ['tab' => 'np'], $ck, true, $mock);
check('P Check now with a forged CSRF token -> refused, nothing read', str_contains($h, 'Session expired') && !str_contains($h, '0 read'));
$before = (string) BankMailAccounts::get($NPID)['last_poll_at'];
sleep(1);
$h = $page('payment-center.php', $SUP, ['tab' => 'np'], $ck, false, $mock);
check('P Check now (CSRF, Google mocked) -> "✔ <box>: 0 read …", the box\'s last check moves, audited bank_mail.check',
    !$warn($h) && str_contains($h, '✔ ' . $em('np') . ': 0 read') && (string) BankMailAccounts::get($NPID)['last_poll_at'] !== $before
    && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'bank_mail.check' AND entity_id = :i", ['i' => (string) $NPID], 0) >= 1, substr((string) preg_replace('/\s+/', ' ', strip_tags($h)), 0, 300));
$ed = ['act' => 'acct_edit', 'tab' => 'in', 'id' => (string) $incId, 'label' => 'HDFC test', 'senders' => 'alerts@icici.test', 'dkim_domain' => 'icici.test', 'account_hint' => '12-34'];
$page('payment-center.php', $SUP, ['tab' => 'in'], $ed, true);
check('P Edit with a forged CSRF token -> nothing saved', !BankMailAccounts::isReady(BankMailAccounts::get($incId) ?? []));
$h = $page('payment-center.php', $SUP, ['tab' => 'in'], ['account_hint' => '12'] + $ed);
check('P Edit with 2 digits -> refused with the reason', str_contains($h, 'अन्तिम ४ देखि ६ अंक') && !BankMailAccounts::isReady(BankMailAccounts::get($incId) ?? []));
$h = $page('payment-center.php', $SUP, ['tab' => 'in'], $ed);
$incA = BankMailAccounts::get($incId) ?? [];
check('P Edit (CSRF) -> rules saved ("12-34" -> 1234), now ✓ ready, audited bank_mail.edit', str_contains($h, '✓ तयार · ready') && BankMailAccounts::isReady($incA) && $incA['account_hint'] === '1234' && $incA['label'] === 'HDFC test'
    && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'bank_mail.edit' AND entity_id = :i", ['i' => (string) $incId], 0) === 1);
check('P the box just became ready -> its position is cleared + its "setup-incomplete" rows dropped (recent mail read again), audited bank_mail.reread',
    $incA['last_uid'] === null && $incA['uidvalidity'] === null && $incA['last_seen_ms'] === null && !in_array('setup-incomplete', array_column($logOf($incId), 'outcome'), true)
    && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'bank_mail.reread' AND entity_id = :i", ['i' => (string) $incId], 0) === 1, json_encode([$incA['last_uid'], $logOf($incId)]));
/* a box with a real position and NO skipped mail keeps its position when its rules are saved again
   (clearing it would skip alerts older than the 2-3 day re-read window) */
$posBefore = BankMailAccounts::get($incId) ?? [];
Database::update('bank_mail_accounts', ['last_uid' => 6500, 'uidvalidity' => 1], 'id = :i', ['i' => $incId]);
BankMailAccounts::rereadIncomplete($incId);
check('P rules saved again with nothing skipped -> the position (last_uid 6500) is kept', (int) ((BankMailAccounts::get($incId) ?? [])['last_uid'] ?? 0) === 6500);
Database::update('bank_mail_accounts', ['last_uid' => $posBefore['last_uid'] ?? null, 'uidvalidity' => $posBefore['uidvalidity'] ?? null], 'id = :i', ['i' => $incId]);
$resRe = BankMail::pollAccount($incA, 50, $imapFor([7001 => $mail(['dom' => 'icici.test', 'mid' => 'mp2inc' . $tag, 'plain' => "Rs $rAmt credited to A/c XX1234 UPI Ref No " . $T('N1') . "1 Remarks: " . $rB['pnr']])]));
check('P the next check reads the mail that came while the box was incomplete -> the credit is stored now (from that box)', $resRe['ok'] && $resRe['credits'] === 1
    && (int) (($credit($T('N1') . '1') ?? [])['mail_account_id'] ?? 0) === $incId, json_encode($resRe, JSON_UNESCAPED_UNICODE));
$add = ['act' => 'acct_add_pw', 'tab' => 'in', 'country' => 'IN', 'email' => $em('add'), 'app_password' => 'qrst uvwx yzab cdef', 'dkim_domain' => 'icici.test', 'account_hint' => '1234', 'senders' => ''];
$page('payment-center.php', $SUP, ['tab' => 'in'], $add, true);
check('P Add with an app password and a forged CSRF token -> nothing added', Database::fetch('SELECT id FROM bank_mail_accounts WHERE email = :e', ['e' => $em('add')]) === null);
$h = $page('payment-center.php', $SUP, ['tab' => 'in'], $add);
$addA = Database::fetch('SELECT * FROM bank_mail_accounts WHERE email = :e', ['e' => $em('add')]) ?? [];
if (($addA['id'] ?? 0) > 0) { $acctIds[] = (int) $addA['id']; }
check('P Add (CSRF) -> an India app-password box, the password sealed (spaces dropped), audited, never printed back', ($addA['country'] ?? '') === 'IN' && ($addA['method'] ?? '') === 'app_password'
    && BankMailAccounts::secret($addA) === 'qrstuvwxyzabcdef' && !str_contains((string) $addA['secret_enc'], 'qrst') && !str_contains($h, 'qrst') && str_contains($h, $em('add') . ' जोडियो'));
$rm = ['act' => 'acct_remove', 'tab' => 'in', 'id' => (string) ($addA['id'] ?? 0)];
$page('payment-center.php', $SUP, ['tab' => 'in'], $rm, true);
check('P Remove with a forged CSRF token -> the box stays', BankMailAccounts::get((int) ($addA['id'] ?? 0)) !== null);
$h = $page('payment-center.php', $SUP, ['tab' => 'in'], $rm);
check('P Remove (CSRF) -> row + sealed password gone, audited bank_mail.remove', BankMailAccounts::get((int) ($addA['id'] ?? 0)) === null && str_contains($h, 'हटाइयो')
    && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'bank_mail.remove' AND entity_id = :i", ['i' => (string) ($addA['id'] ?? 0)], 0) === 1);
/* one Gmail on BOTH tabs: removing one must not revoke Google's grant for the other */
$shNp = BankMailAccounts::save(['country' => 'NP', 'email' => $em('shared'), 'method' => 'google', 'secret_enc' => SecretBox::seal('1//MP2SHARED' . $tag, 'bankmail')], $SUP);
$shIn = BankMailAccounts::save(['country' => 'IN', 'email' => $em('shared'), 'method' => 'google', 'secret_enc' => SecretBox::seal('1//MP2SHARED' . $tag, 'bankmail')], $SUP);
$acctIds[] = $shNp; $acctIds[] = $shIn;
$revMock = [['oauth2.googleapis.com/revoke', 200, []]];
$auditNew = static fn(int $aid): string => (string) Database::scalar("SELECT new_value FROM audit_logs WHERE action = 'bank_mail.remove' AND entity_id = :i ORDER BY id DESC LIMIT 1", ['i' => (string) $aid], '');
$page('payment-center.php', $SUP, ['tab' => 'in'], ['act' => 'acct_remove', 'tab' => 'in', 'id' => (string) $shIn], false, $revMock);
check('P the same Gmail is on the other tab too -> removing one does NOT revoke at Google (the other box keeps working)',
    BankMailAccounts::get($shIn) === null && BankMailAccounts::get($shNp) !== null && str_contains($auditNew($shIn), '"revoked":false'), $auditNew($shIn));
$page('payment-center.php', $SUP, ['tab' => 'np'], ['act' => 'acct_remove', 'tab' => 'np', 'id' => (string) $shNp], false, $revMock);
check('P the last box of that Gmail removed -> revoked at Google', BankMailAccounts::get($shNp) === null && str_contains($auditNew($shNp), '"revoked":true'), $auditNew($shNp));
/* the migrated mp1 box: removing it also blanks the plaintext app password left in settings */
$lgId = BankMailAccounts::save(['country' => 'NP', 'email' => $em('legacy2'), 'method' => 'app_password', 'secret_enc' => SecretBox::seal('abcdefghijklmnop', 'bankmail')], $SUP);
$acctIds[] = $lgId;
setKeep(BankMailAccounts::LEGACY_KEY, (string) $lgId, 'string');
setKeep('bankmail_app_password', 'abcd efgh ijkl mnop', 'string');
$page('payment-center.php', $SUP, ['tab' => 'np'], ['act' => 'acct_remove', 'tab' => 'np', 'id' => (string) $lgId]);
Settings::flush();
check('P removing the box migrated from the old settings also blanks settings.bankmail_app_password (no plaintext copy left)',
    BankMailAccounts::get($lgId) === null && Settings::getString('bankmail_app_password', 'x') === '' && str_contains($auditNew($lgId), '"legacy_password_cleared":true'), $auditNew($lgId));
$gs = ['act' => 'google_save', 'tab' => 'np', 'google_oauth_client_id' => '999-mp2save.apps.googleusercontent.com', 'google_oauth_client_secret' => '', 'google_oauth_redirect' => ''];
$h = $page('payment-center.php', $SUP, ['tab' => 'np'], $gs);
Settings::flush();   // the page saved in its own process
check('P Google client Save (CSRF): the id saved, a blank secret keeps the saved one, the secret never printed, the Login button back',
    Settings::getString('google_oauth_client_id', '') === '999-mp2save.apps.googleusercontent.com' && Settings::getString('google_oauth_client_secret', '') === 'GOCSPX-mp2-local-test-secret'
    && !str_contains($h, 'GOCSPX-mp2-local-test-secret') && str_contains($h, '🔐 Google बाट Login गर्नुहोस् (browser जस्तै)</a>'));
check('P with the Google client saved, the one-time steps fold away under a closed "🔧 Google setup (एकपटकको)" (Login button stays on top)',
    str_contains($h, '<details class="pc-adv"><summary>🔧 Google setup (एकपटकको)') && strpos($h, '🔐 Google बाट Login') < strpos($h, '🔧 Google setup (एकपटकको)'));
$h = $page('payment-center.php', $SUP, ['tab' => 'np'], ['google_oauth_client_id' => 'not-a-client-id'] + $gs);
Settings::flush();
check('P Google client Save with a bad Client ID -> refused', str_contains($h, 'apps.googleusercontent.com मा सकिनुपर्छ') && Settings::getString('google_oauth_client_id', '') === '999-mp2save.apps.googleusercontent.com');

$h = $page('gmail-oauth.php', $SUP, ['start' => '1', 'country' => 'IN']);
preg_match('/href="([^"]+)"/', $h, $hm);
$gUrl = html_entity_decode($hm[1] ?? '', ENT_QUOTES);
parse_str((string) parse_url($gUrl, PHP_URL_QUERY), $gq);
check('P gmail-oauth.php?start=1 (super-admin) -> on to accounts.google.com with the saved client id, gmail.readonly, a state',
    str_starts_with($gUrl, 'https://accounts.google.com/o/oauth2/v2/auth?') && ($gq['client_id'] ?? '') === '999-mp2save.apps.googleusercontent.com' && ($gq['scope'] ?? '') === GoogleOAuth::SCOPE
    && preg_match('/^[0-9a-f]{32}$/', (string) ($gq['state'] ?? '')) === 1 && !str_contains($h, 'GOCSPX'), substr($h, 0, 300));
$nBefore = (int) Database::scalar('SELECT COUNT(*) FROM bank_mail_accounts', [], 0);
$h = $page('gmail-oauth.php', $MGR, ['start' => '1', 'country' => 'NP']);
check('P gmail-oauth.php start as a manager -> 403, no Google link', str_contains($h, '<!--HTTP:403-->') && !str_contains($h, 'accounts.google.com'));
$h = $page('gmail-oauth.php', $MGR, ['state' => str_repeat('a', 32), 'code' => 'x'], null, false, [['oauth2.googleapis.com/token', 200, $okToken], ['profile', 200, ['emailAddress' => $em('mgr')]]]);
check('P the callback as a manager -> 403, nothing stored', str_contains($h, '<!--HTTP:403-->') && (int) Database::scalar('SELECT COUNT(*) FROM bank_mail_accounts', [], 0) === $nBefore
    && Database::fetch('SELECT id FROM bank_mail_accounts WHERE email = :e', ['e' => $em('mgr')]) === null);
$h = $page('gmail-oauth.php', $SUP, ['state' => str_repeat('b', 32), 'code' => 'x'], null, false, [['oauth2.googleapis.com/token', 200, $okToken], ['profile', 200, ['emailAddress' => $em('forged')]]]);
check('P a callback with no sign-in started in this session (forged state) -> refused, nothing stored', str_contains($h, 'session') && Database::fetch('SELECT id FROM bank_mail_accounts WHERE email = :e', ['e' => $em('forged')]) === null);

/* the cron */
$cron = (string) file_get_contents($ROOT . '/cron/bank-mail.php');
check('P cron/bank-mail.php runs every active box (anythingToRun + poll) and keeps its heartbeat', str_contains($cron, 'BankMail::anythingToRun()') && str_contains($cron, 'BankMail::poll(50)') && substr_count($cron, 'cron_done(') === 2);

echo "\n" . ($fail === 0 ? "  $pass passed, 0 failed\n" : "  $pass passed, $fail failed\n");
exit($fail === 0 ? 0 : 1);
