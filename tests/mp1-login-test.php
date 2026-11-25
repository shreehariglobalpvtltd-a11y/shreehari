<?php
/**
 * =====================================================================
 *  mp1-login-test.php — release mp1-lg (25 Nov 2026): login modes, the top
 *  profile bar and the credential permissions (owner plan sections 2, 4, 8).
 *
 *   A  StaffAvatar: initials, name colour, mode from the REAL role, photo only
 *      from a safe stored path that exists, initials when there is none
 *   B  the staff header (admin_header) with a photo (super-admin) and without
 *      (agent): avatar in the desktop strip, the phone bar slot, the drawer, the
 *      coloured mode badge; staff_avatar_on = 0 = the old bar
 *   C  the login door: 4 mode cards (Staff / Agent / Counter / Admin) outside the
 *      form, reduced-motion respected, switch off = no cards; a card NEVER changes
 *      the role - signing in with "admin" chosen still gives the agent's role
 *   D  credential actions: a manager / operations login gets 403 on staff.php
 *      resetpw, login-requests.php open and sessions.php clear_qk; a super-admin
 *      (or a person granted staff.credentials) succeeds; the switch
 *      staff_credentials_lock_on = 0 gives login-requests back to staff.manage
 *   D2 the sign-in mobile (admins.phone): a manager editing an agent on
 *      agents.php / agent-360.php / the AgentPro office form of a live member
 *      never moves it (the ticket number still saves); a super-admin does
 *   E  remember days: code default 90, capped by login_remember_max_days (90)
 *
 *  shari_test only. Every account, profile, file and row this suite makes is
 *  deleted at the end; settings are changed in each child's memory only.
 *
 *    php -c .claude/php-dev.ini tests/mp1-login-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/** Settings for this process only (no database write — other suites share the box). */
function memSetting(string $key, string $value, string $type): void {
    $r = new ReflectionClass(Settings::class);
    $c = $r->getProperty('cache'); $c->setAccessible(true);
    $d = $r->getProperty('decoded'); $d->setAccessible(true);
    Settings::get('company_name');
    $cache = (array) $c->getValue();
    $cache[$key] = ['value' => $value, 'type' => $type, 'public' => false];
    $c->setValue(null, $cache);
    $dec = (array) $d->getValue();
    unset($dec[$key]);
    $d->setValue(null, $dec);
}

/* ---- child: one page / one header in its own PHP process ----------------
   argv: --child <base64 json {what: header|page|login, admin, script, get, post, mem}> */
if (($argv[1] ?? '') === '--child') {
    $cfg    = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $what   = (string) ($cfg['what'] ?? 'page');
    $script = (string) ($cfg['script'] ?? 'home.php');
    $post   = (array) ($cfg['post'] ?? []);
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.71';
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    $_SERVER['SCRIPT_NAME']    = '/admin/' . $script;
    $_SERVER['REQUEST_URI']    = '/admin/' . $script;
    $_GET  = (array) ($cfg['get'] ?? []);
    $_POST = [];
    if (!defined('SHG_APP')) { define('SHG_APP', true); }
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    if (DB_NAME !== 'shari_test') { exit(2); }
    foreach ((array) ($cfg['mem'] ?? []) as $k => $v) {
        memSetting((string) $k, (string) $v[0], (string) $v[1]);
    }
    register_shutdown_function(static function (): void {
        echo "\n<!--STATUS:" . (int) (http_response_code() ?: 200) . "-->\n";
    });
    if ($what === 'login') {
        $_SESSION = [];
        $_SESSION[CSRF_TOKEN_NAME] = 'mp1lg-fixed-csrf-token';
        $prev = null;
        $prev = set_error_handler(static function (int $sev, string $msg, string $f = '', int $l = 0) use (&$prev): bool {
            if (str_contains($msg, 'SHG_APP') && str_contains($msg, 'already defined')) { return true; }
            return is_callable($prev) ? (bool) $prev($sev, $msg, $f, $l) : false;
        });
        require dirname(__DIR__) . '/admin/login.php';
        exit(0);
    }
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(3); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if ($what === 'header') {
        require_once dirname(__DIR__) . '/admin/_guard.php';
        admin_header('MP1 header probe', 'home.php');
        echo '<!--END-->';
        exit(0);
    }
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME] = Security::csrfToken();
    }
    $prev = null;
    $prev = set_error_handler(static function (int $sev, string $msg, string $f = '', int $l = 0) use (&$prev): bool {
        if (str_contains($msg, 'SHG_APP') && str_contains($msg, 'already defined')) { return true; }
        return is_callable($prev) ? (bool) $prev($sev, $msg, $f, $l) : false;
    });
    require dirname(__DIR__) . '/admin/' . $script;
    exit(0);
}

$ROOT = dirname(__DIR__);
ob_start();   // before any output, so the session (the sign-in check below) can start
require_once $ROOT . '/includes/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
register_shutdown_function(static function (): void { while (ob_get_level() > 0) { ob_end_flush(); } });
require_once INCLUDE_PATH . '/codelogin.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/staffavatar.php';
require_once INCLUDE_PATH . '/adminsessions.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; } else { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function run(string $args): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . $args;
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out;
}
function child(array $cfg): string {
    return run(escapeshellarg(__FILE__) . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg))));
}
function status(string $out): int {
    return preg_match('/<!--STATUS:(\d+)-->/', $out, $m) === 1 ? (int) $m[1] : 0;
}

echo "\n=== mp1-lg: login modes + top profile bar + credential permissions ===\n\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n\n  0 passed, 0 failed\n"; exit(0); }

/* ---- throwaway accounts ---------------------------------------------- */
$PFX = 'mp1lg_';
$PHOTO_FILE = 'agents/zzmp1lg-photo-test.jpg';
$cleanup = static function () use ($PFX, $PHOTO_FILE): void {
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE '{$PFX}%'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        try { Database::delete('kv_store', "kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $id]); } catch (Throwable $e) {}
        foreach (['ap_applications', 'ap_members', 'admin_profiles', 'admin_sessions', 'login_recovery_requests', 'admin_devices', 'admin_remember'] as $t) {
            try { Database::delete($t, 'admin_id = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        try { Database::delete('admins', 'id = :i', ['i' => $id]); } catch (Throwable $e) { echo "  !! could not delete admin $id: " . $e->getMessage() . "\n"; }
    }
    if (defined('UPLOAD_PATH') && is_file(UPLOAD_PATH . '/' . $PHOTO_FILE)) { @unlink(UPLOAD_PATH . '/' . $PHOTO_FILE); }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'code_login%' OR bucket LIKE 'quick_unlock%'");
};
$cleanup();
register_shutdown_function($cleanup);

$mk = static function (string $u, string $role, string $name, array $perms = []) use ($PFX): int {
    return (int) Database::insert('admins', [
        'username' => $PFX . $u, 'password_hash' => password_hash('Mp1@' . $u . '#9z', PASSWORD_BCRYPT),
        'full_name' => $name, 'role' => $role, 'phone' => null, 'is_active' => 1, 'must_change_pw' => 0,
        'permissions' => $perms !== [] ? json_encode($perms) : null,
    ]);
};
$aSa    = $mk('sa', 'superadmin', 'Mp One Owner');
$aAgent = $mk('agent', 'agent', 'Ram Bahadur Thapa');
$aCtr   = $mk('ctr', 'counter', 'Nepalgunj Desk Test');
$aMgr   = $mk('mgr', 'manager', 'Mp Manager');
$aOps   = $mk('ops', 'operations', 'Mp Operations');
$aOpsG  = $mk('opsg', 'operations', 'Mp Ops Granted', ['staff.credentials']);
$aSup   = $mk('sup', 'support', 'Mp Support');

/* ===================================================================== */
echo "-- A. StaffAvatar\n";
foreach (['includes/staffavatar.php', 'admin/_guard.php', 'admin/login.php', 'admin/staff.php', 'admin/sessions.php', 'admin/login-requests.php', 'admin/agent.php', 'admin/agents.php', 'admin/agent-360.php', 'includes/agentpro.php'] as $f) {
    $o = run('-l ' . escapeshellarg($ROOT . '/' . $f));
    check("$f lints", str_contains($o, 'No syntax errors'), trim(substr($o, 0, 160)));
}
check('initials: two words', StaffAvatar::initials('Ram Bahadur Thapa') === 'RB');
check('initials: one word, lower case', StaffAvatar::initials('  sita ') === 'S');
check('initials: empty name -> star', StaffAvatar::initials('') === '★');
check('initials: Devanagari name keeps its letters', StaffAvatar::initials('राम थापा') === 'रथ');
check('colour: same name, same colour', StaffAvatar::color('Ram Bahadur Thapa') === StaffAvatar::color('ram bahadur thapa '));
check('colour: a #RRGGBB from the palette', preg_match('/^#[0-9A-F]{6}$/', StaffAvatar::color('Anyone')) === 1);
$modes = [];
foreach (['superadmin', 'manager', 'accountant', 'operations', 'counter', 'agent', 'support', 'scanner', 'official', 'viewer'] as $r) { $modes[$r] = StaffAvatar::modeFor($r); }
check('mode from role: office roles = admin', $modes['superadmin'] === 'admin' && $modes['manager'] === 'admin' && $modes['accountant'] === 'admin' && $modes['operations'] === 'admin');
check('mode from role: counter / agent as named', $modes['counter'] === 'counter' && $modes['agent'] === 'agent');
check('mode from role: the rest = staff', $modes['support'] === 'staff' && $modes['scanner'] === 'staff' && $modes['official'] === 'staff' && $modes['viewer'] === 'staff');
$h0 = StaffAvatar::html(['id' => $aAgent, 'full_name' => 'Ram Bahadur Thapa'], 32, 'sa-av sa-32');
check('no photo -> coloured initials circle', str_contains($h0, 'sa-ini') && str_contains($h0, '>RB</span>') && str_contains($h0, 'background:#') && !str_contains($h0, '<img'));
check('badge: agent = एजेन्ट · Agent', str_contains(StaffAvatar::badge(['role' => 'agent']), 'एजेन्ट · Agent'));
check('badge: escaped role in the title', !str_contains(StaffAvatar::badge(['role' => '"><x>']), '"><x>'));
check('html: a hostile name is escaped', !str_contains(StaffAvatar::html(['id' => 0, 'full_name' => '<script>x'], 28), '<script>'));

// a photo on the super-admin's record (the ID-card photo the office uploaded)
@mkdir(UPLOAD_PATH . '/agents', 0775, true);
$jpg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
$tmp = UPLOAD_PATH . '/' . $PHOTO_FILE . '.tmp';
file_put_contents($tmp, (string) $jpg);
rename($tmp, UPLOAD_PATH . '/' . $PHOTO_FILE);
Database::insert('admin_profiles', ['admin_id' => $aSa, 'photo_path' => $PHOTO_FILE]);
Database::insert('admin_profiles', ['admin_id' => $aMgr, 'photo_path' => 'agents/../config/x.jpg']);       // unsafe path
Database::insert('admin_profiles', ['admin_id' => $aOps, 'photo_path' => 'agents/zzmp1lg-missing-file.jpg']); // file not there
StaffAvatar::flush();
$h1 = StaffAvatar::html(['id' => $aSa, 'full_name' => 'Mp One Owner'], 28, 'sa-av sa-28');
check('photo on file -> <img> from /uploads/agents/', str_contains($h1, '<img') && str_contains($h1, 'src="/uploads/' . $PHOTO_FILE . '?v='));
check('unsafe stored path -> initials, never that path', !str_contains(StaffAvatar::html(['id' => $aMgr, 'full_name' => 'Mp Manager']), '<img'));
check('stored file missing -> initials (no broken image)', !str_contains(StaffAvatar::html(['id' => $aOps, 'full_name' => 'Mp Operations']), '<img'));
check('no id -> initials', !str_contains(StaffAvatar::html(['full_name' => 'X']), '<img'));
$src = (string) file_get_contents(INCLUDE_PATH . '/staffavatar.php');
check('the lookup is wrapped in try/catch (a missing table gives initials)', substr_count($src, 'catch (Throwable') >= 3);

/* ===================================================================== */
echo "\n-- B. Staff header (admin_header)\n";
$hSa = child(['what' => 'header', 'admin' => $aSa]);
check('super-admin header draws', str_contains($hSa, '<!--END-->'), substr(strip_tags($hSa), 0, 200));
check('desktop strip: photo + name + Admin badge', str_contains($hSa, 'class="who"') && str_contains($hSa, 'sa-av sa-28 sa-img') && str_contains($hSa, 'एडमिन · Admin'));
check('super-admin avatar opens their own record (photo upload)', str_contains($hSa, 'href="/admin/team-member.php?id=' . $aSa . '"'));
check('phone bar: one avatar slot (tb-av + tb-me) next to the lock', str_contains($hSa, '<header class="tb tb-av">') && substr_count($hSa, 'class="tb-me"') === 1 && str_contains($hSa, 'class="tb-lock"'));
check('phone bar CSS: 4 columns, 36px avatar slot, still 44px rows', str_contains($hSa, 'grid-template-columns:44px minmax(0,1fr) 36px 44px') && str_contains($hSa, '"menu search me lock"'));
check('drawer: 44px avatar + badge', str_contains($hSa, 'side-me-h') && str_contains($hSa, 'sa-av sa-44'));
$hAg = child(['what' => 'header', 'admin' => $aAgent]);
check('agent header draws', str_contains($hAg, '<!--END-->'), substr(strip_tags($hAg), 0, 200));
check('agent with no photo: initials RB, Agent badge, link to /me/', str_contains($hAg, '>RB</span>') && str_contains($hAg, 'एजेन्ट · Agent') && str_contains($hAg, 'href="/me/"') && !str_contains($hAg, 'sa-img'));
$hCt = child(['what' => 'header', 'admin' => $aCtr]);
check('counter header: Counter badge', str_contains($hCt, 'काउन्टर · Counter'));
$hSu = child(['what' => 'header', 'admin' => $aSup]);
check('support header: Staff badge', str_contains($hSu, 'स्टाफ · Staff'));
$hOff = child(['what' => 'header', 'admin' => $aSa, 'mem' => ['staff_avatar_on' => ['0', 'bool']]]);
check('staff_avatar_on = 0: the old bar (no avatar, no badge, plain <header class="tb">)', str_contains($hOff, '<!--END-->') && !str_contains($hOff, 'sa-av') && !str_contains($hOff, 'sa-mode') && str_contains($hOff, '<header class="tb">') && str_contains($hOff, '<em>Superadmin</em>'));
$hNoMin = child(['what' => 'header', 'admin' => $aSa, 'mem' => ['staff_phone_min_on' => ['0', 'bool']]]);
check('staff_phone_min_on = 0: avatar in the strip, no phone slot', str_contains($hNoMin, 'sa-av sa-28') && !str_contains($hNoMin, 'class="tb-me"') && !str_contains($hNoMin, 'tb-av'));

/* ===================================================================== */
echo "\n-- C. Login door mode cards\n";
$lg = child(['what' => 'login', 'script' => 'login.php', 'get' => ['step' => 'c_code']]);
check('the code door draws', str_contains($lg, 'name="code"') && str_contains($lg, '</html>'), substr(strip_tags($lg), 0, 200));
preg_match_all('/<button type="button" class="lm-card lm-(\w+)" data-lm-mode="(\w+)"/', $lg, $cm);
check('4 mode cards: staff, agent, counter, admin', ($cm[2] ?? []) === ['staff', 'agent', 'counter', 'admin'], implode(',', $cm[2] ?? []));
check('the Nepali words: स्टाफ / एजेन्ट / काउन्टर मोड / एडमिन मोड', str_contains($lg, '<b>स्टाफ</b>') && str_contains($lg, '<b>एजेन्ट</b>') && str_contains($lg, '<b>काउन्टर मोड</b>') && str_contains($lg, '<b>एडमिन मोड</b>'));
$cardsAt = strpos($lg, 'data-lm>');
$formAt  = strpos($lg, 'name="mode" value="c_code"');
$gridHtml = $cardsAt !== false ? substr($lg, $cardsAt, (int) strpos($lg, '</div>', $cardsAt) - $cardsAt) : '';
check('the cards sit outside the sign-in form and carry no name / value', $cardsAt !== false && $formAt !== false && $cardsAt < $formAt
    && !preg_match('/<(input|select|textarea)\b/', $gridHtml) && !preg_match('/\sname=/', $gridHtml));
check('choosing a card only changes label / placeholder / hint (+ localStorage)', str_contains($lg, "localStorage.setItem('shg_login_mode'") && str_contains($lg, "setAttribute('placeholder'") && !preg_match('/shg_login_mode[^;]*(fetch|submit|\.value=)/', $lg));
check('light animation respects prefers-reduced-motion', str_contains($lg, '@media (prefers-reduced-motion:no-preference){') && str_contains($lg, 'lmIn'));
check('the app download link is still there for a browser', str_contains($lg, 'S-Hari-Manage.apk'));
$lgApp = child(['what' => 'login', 'script' => 'login.php', 'get' => ['step' => 'c_code', 'app' => '1']]);
check('inside the app (?app=1) no download link', str_contains($lgApp, 'name="code"') && !str_contains($lgApp, 'S-Hari-Manage.apk'));
$lgOff = child(['what' => 'login', 'script' => 'login.php', 'get' => ['step' => 'c_code'], 'mem' => ['login_mode_cards_on' => ['0', 'bool']]]);
check('login_mode_cards_on = 0: no cards, the code door as before', str_contains($lgOff, 'name="code"') && !str_contains($lgOff, 'lm-card') && !str_contains($lgOff, 'shg_login_mode'));
$srvSrc = (string) file_get_contents(INCLUDE_PATH . '/codelogin.php') . (string) file_get_contents(INCLUDE_PATH . '/onedoor.php') . (string) file_get_contents(INCLUDE_PATH . '/auth.php');
check('no server code reads a posted mode card', !str_contains($srvSrc, 'login_mode') && !str_contains($srvSrc, 'shg_login_mode'));

// sign in with "Admin mode" chosen: the role is still the account's own (agent)
$free = 0;
for ($n = 960; $n <= 989; $n++) {
    if (AgentWallet::adminForAgentCode($n) === null) { $free = $n; break; }
}
if ($free === 0 || !CodeLogin::ready() || !QuickUnlock::ready()) {
    check('a free test agent code + the code-login tables', false, 'run database/upgrade-2026-11-15-code-login.sql / free a code 960-989');
} else {
    AgentWallet::setAgentCode($aAgent, $free);
    QuickUnlock::set($aAgent, 'pin', '5831');
    memSetting('quick_unlock_on', '1', 'bool');
    memSetting('login_code_first_on', '1', 'bool');
    memSetting('login_pin_required', '1', 'bool');
    CodeLogin::$evenTiming = false;
    $_SESSION = [];
    $_POST = ['mode' => 'c_code', 'code' => 'SHG-' . str_pad((string) $free, 4, '0', STR_PAD_LEFT), 'login_mode' => 'admin', 'role' => 'superadmin'];
    $r = CodeLogin::enter((string) $_POST['code']);
    check('the code (with "admin" mode posted) goes to the PIN step', ($r['step'] ?? '') === 'c_unlock', json_encode($r));
    $_POST = ['mode' => 'c_unlock', 'qk_kind' => 'pin', 'qk_val' => '5831', 'login_mode' => 'admin', 'role' => 'superadmin'];
    $r = CodeLogin::unlock('pin', '5831');
    check('signed in as THAT account', ($r['go'] ?? '') === 'home' && (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) === $aAgent, json_encode($r));
    check('mode card never changes the role: still agent', ($_SESSION[ADMIN_SESSION_KEY]['role'] ?? '') === 'agent' && !Auth::isSuperadmin() && !Auth::can('staff.manage'));
    check('the account row is unchanged (role agent)', (string) Database::scalar('SELECT role FROM admins WHERE id = :i', ['i' => $aAgent], '') === 'agent');
    $_SESSION = []; $_POST = [];
}

/* ===================================================================== */
echo "\n-- D. Credential actions are the super-admin's\n";
check('staff.credentials is in the permission catalog and marked sensitive', isset(Auth::permissionList()['staff.credentials']) && in_array('staff.credentials', Auth::SENSITIVE_PERMISSIONS, true));
$noRole = [];
foreach (Auth::MATRIX_ROLES as $r) { if (in_array('staff.credentials', Auth::defaultRolePermissions($r), true)) { $noRole[] = $r; } }
check('no role holds staff.credentials by default', $noRole === [], implode(',', $noRole));

$hashOf = static fn(int $id): string => (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $id], '');
$h0 = $hashOf($aCtr);
$o = child(['what' => 'page', 'admin' => $aOps, 'script' => 'staff.php', 'post' => ['action' => 'resetpw', 'id' => $aCtr, 'password' => 'NewPass#2026x']]);
check('operations: staff.php resetpw -> 403, password unchanged', status($o) === 403 && $hashOf($aCtr) === $h0, 'status ' . status($o));
check('...and says why (super-admin only)', str_contains($o, 'Only a super-admin changes sign-in details'));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'staff.php', 'post' => ['action' => 'setphone', 'id' => $aCtr, 'phone' => '9000000000']]);
check('manager: staff.php setphone -> 403, phone unchanged', status($o) === 403 && Database::scalar('SELECT phone FROM admins WHERE id = :i', ['i' => $aCtr]) === null, 'status ' . status($o));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'staff.php', 'post' => ['action' => 'reset_2fa', 'id' => $aCtr]]);
check('manager: staff.php reset_2fa -> 403', status($o) === 403, 'status ' . status($o));
$o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'staff.php', 'post' => ['action' => 'resetpw', 'id' => $aCtr, 'password' => 'NewPass#2026x']]);
check('super-admin: staff.php resetpw works', status($o) === 200 && $hashOf($aCtr) !== $h0 && str_contains($o, 'Password reset for'), 'status ' . status($o));
$h1 = $hashOf($aCtr);
$o = child(['what' => 'page', 'admin' => $aOpsG, 'script' => 'staff.php', 'post' => ['action' => 'resetpw', 'id' => $aCtr, 'password' => 'OtherPass#2026y']]);
check('operations granted staff.credentials: resetpw works', status($o) === 200 && $hashOf($aCtr) !== $h1, 'status ' . status($o));
$o = child(['what' => 'page', 'admin' => $aOpsG, 'script' => 'staff.php', 'post' => ['action' => 'toggle', 'id' => $aCtr]]);
check('...but the grant opens nothing else on staff.php (toggle -> 403)', status($o) === 403 && (int) Database::scalar('SELECT is_active FROM admins WHERE id = :i', ['i' => $aCtr], 0) === 1);
$saHash = $hashOf($aSa);
$o = child(['what' => 'page', 'admin' => $aOpsG, 'script' => 'staff.php', 'post' => ['action' => 'resetpw', 'id' => $aSa, 'password' => 'Takeover#2026z']]);
check('...and never a super-admin\'s sign-in (resetpw on a super-admin -> 403)', status($o) === 403 && $hashOf($aSa) === $saHash, 'status ' . status($o));
$o = child(['what' => 'page', 'admin' => $aOpsG, 'script' => 'staff.php', 'post' => ['action' => 'reset_2fa', 'id' => $aSa]]);
check('...nor a super-admin\'s 2FA (reset_2fa -> 403)', status($o) === 403, 'status ' . status($o));

if (CodeLogin::ready()) {
    $openRows = static fn(int $id): int => (int) Database::scalar("SELECT COUNT(*) FROM login_recovery_requests WHERE admin_id = :a AND status = 'opened'", ['a' => $id], 0);
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'login-requests.php', 'post' => ['act' => 'open', 'aid' => $aCtr]]);
    check('manager: login-requests open (PIN reset) -> 403, nothing opened', status($o) === 403 && $openRows($aCtr) === 0, 'status ' . status($o));
    check('...the page tells a non-super-admin up front', str_contains($o, 'super-admin only; you can view and close requests'));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'login-requests.php', 'post' => ['act' => 'details', 'aid' => $aCtr, 'mobile' => '9000000001', 'dob' => '1990-01-01', 'cc' => '91']]);
    check('manager: login-requests details (login mobile) -> 403', status($o) === 403, 'status ' . status($o));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'login-requests.php', 'post' => ['act' => 'open', 'aid' => $aCtr], 'mem' => ['staff_credentials_lock_on' => ['0', 'bool']]]);
    check('staff_credentials_lock_on = 0: a manager may open it again (old rule)', status($o) === 200 && $openRows($aCtr) === 1, 'status ' . status($o));
    Database::run("DELETE FROM login_recovery_requests WHERE admin_id = :a", ['a' => $aCtr]);
    $o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'login-requests.php', 'post' => ['act' => 'open', 'aid' => $aCtr]]);
    check('super-admin: login-requests open works', status($o) === 200 && $openRows($aCtr) === 1, 'status ' . status($o));
    Database::run("DELETE FROM login_recovery_requests WHERE admin_id = :a", ['a' => $aCtr]);

    QuickUnlock::set($aCtr, 'pin', '730194');
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'sessions.php', 'get' => ['tab' => 'person', 'id' => $aCtr], 'post' => ['act' => 'clear_qk', 'person' => $aCtr]]);
    check('manager: sessions.php clear PIN -> 403, PIN kept', status($o) === 403 && QuickUnlock::status($aCtr)['pin'] === true, 'status ' . status($o));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'sessions.php', 'get' => ['tab' => 'person', 'id' => $aCtr]]);
    check('manager: no Clear PIN button, force sign-out still there', !str_contains($o, 'value="clear_qk"') && str_contains($o, 'value="end_all"'));
    $o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'sessions.php', 'get' => ['tab' => 'person', 'id' => $aCtr]]);
    check('super-admin: Clear PIN button + photo link on the person tab', str_contains($o, 'value="clear_qk"') && str_contains($o, 'team-member.php?id=' . $aCtr));
    $o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'sessions.php', 'get' => ['tab' => 'person', 'id' => $aCtr], 'post' => ['act' => 'clear_qk', 'person' => $aCtr, 'signout' => '1']]);
    $st = QuickUnlock::status($aCtr);
    check('super-admin: PIN and pattern cleared', status($o) === 200 && !$st['pin'] && !$st['pattern'], 'status ' . status($o));
    check('...the door is opened so the person sets a new one (60 min)', $openRows($aCtr) === 1 && CodeLogin::openUntil($aCtr) !== '');
    check('...audited', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'staff.pin_cleared' AND entity_id = :u", ['u' => $PFX . 'ctr']));
    $o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'sessions.php', 'get' => ['tab' => 'person', 'id' => $aSa], 'post' => ['act' => 'clear_qk', 'person' => $aSa]]);
    check('super-admin cannot clear their OWN PIN here', str_contains($o, 'Your own PIN: use the PIN / Pattern page'));
} else {
    check('code-login tables present for the login-requests / Clear PIN checks', false, 'run database/upgrade-2026-11-15-code-login.sql');
}

$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agent.php', 'get' => ['id' => $aAgent], 'post' => ['action' => 'credentials', 'target_id' => $aAgent, 'agent_id' => $aAgent, 'login_username' => $PFX . 'agent2', 'login_email' => '', 'login_password' => 'Hack#Pass2026']]);
check('manager: agent.php credentials -> username unchanged', (string) Database::scalar('SELECT username FROM admins WHERE id = :i', ['i' => $aAgent], '') === $PFX . 'agent');

/* review fix: admins.phone is the sign-in mobile (the /team/ WhatsApp reset + the WhatsApp door send
   codes there) - a manager must not move it through the agents register, Agent 360 or the AgentPro
   office form of a live member; the ticket number (display_phone) still saves. */
echo "\n-- D2. The sign-in mobile on the agent pages\n";
$loginPh = static fn(int $id): string => (string) Database::scalar('SELECT phone FROM admins WHERE id = :i', ['i' => $id], '');
$dispPh  = static fn(int $id): string => (string) Database::scalar('SELECT display_phone FROM admin_profiles WHERE admin_id = :i', ['i' => $id], '');
$denied  = static fn(int $id, string $act): bool => Database::exists(
    "SELECT 1 FROM audit_logs WHERE action = 'staff.credentials_denied' AND entity_id = :e AND new_value LIKE :a", ['e' => (string) $id, 'a' => '%' . $act . '%']);
$aPh = $mk('agph', 'agent', 'Mp Phone Agent');
Database::update('admins', ['phone' => '9000000101'], 'id = :i', ['i' => $aPh]);
$edit = static fn(string $phone): array => ['action' => 'edit', 'id' => $aPh, 'full_name' => 'Mp Phone Agent', 'phone' => $phone, 'agent_kind' => 'person'];
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agents.php', 'post' => $edit('9000000102')]);
check('manager: agents.php edit with a new mobile -> login mobile kept', $loginPh($aPh) === '9000000101', $loginPh($aPh));
check('...the new number is the ticket number (display_phone)', $dispPh($aPh) === '9000000102', $dispPh($aPh));
check('...the page says so and the rest saved', str_contains($o, 'Agent updated.') && str_contains($o, 'Login mobile not changed'));
check('...the refusal is audited', $denied($aPh, 'agents.edit.phone'));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agents.php']);
check('manager: the register form shows the ticket number box + who changes the login one', str_contains($o, 'Mobile (on tickets)') && str_contains($o, 'value="9000000102"') && str_contains($o, 'Login mobile: super-admin only'));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agent-360.php', 'get' => ['agent' => $aPh], 'post' => ['action' => 'edit_basic', 'agent_id' => $aPh, 'tab' => 'report', 'full_name' => 'Mp Phone Agent', 'phone' => '9000000104']]);
check('manager: agent-360 edit_basic with a new mobile -> login mobile kept', $loginPh($aPh) === '9000000101' && $dispPh($aPh) === '9000000104', $loginPh($aPh) . ' / ' . $dispPh($aPh));
check('...audited', $denied($aPh, 'agent360.edit_basic.phone'));
$o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'agents.php', 'post' => $edit('9000000103')]);
check('super-admin: agents.php edit moves the login mobile', $loginPh($aPh) === '9000000103' && $dispPh($aPh) === '9000000103', $loginPh($aPh));
$o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'agent-360.php', 'get' => ['agent' => $aPh], 'post' => ['action' => 'edit_basic', 'agent_id' => $aPh, 'tab' => 'report', 'full_name' => 'Mp Phone Agent', 'phone' => '9000000105']]);
check('super-admin: agent-360 edit_basic moves the login mobile', $loginPh($aPh) === '9000000105', $loginPh($aPh));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agents.php', 'post' => $edit('9000000106'), 'mem' => ['staff_credentials_lock_on' => ['0', 'bool']]]);
check('staff_credentials_lock_on = 0: a manager moves it again (old rule)', $loginPh($aPh) === '9000000106', $loginPh($aPh));

require_once INCLUDE_PATH . '/agentpro.php';
if (AgentPro::available() && AgentPro::appHas('office_filled_by')) {
    $key = bin2hex(random_bytes(16));
    Database::insert('ap_applications', ['ref' => 'ML-' . strtoupper(substr($key, 0, 8)), 'draft_key' => $key, 'status' => 'approved', 'admin_id' => $aPh, 'phone' => '9000000106']);
    $saved = $_SESSION ?? [];
    $asAdmin = static function (int $id): void {
        $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $id]);
        $_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
            'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time()];
    };
    $asAdmin($aMgr);
    $err = '';
    try { AgentPro::officeVerify($key, $aMgr, 'came to the office', '9000000107', 'IN'); } catch (Throwable $e) { $err = $e->getMessage(); }
    check('manager: AgentPro office form, live member, new mobile -> refused', str_contains($err, 'Only a super-admin changes a live member') && $loginPh($aPh) === '9000000106', $err);
    check('...the form keeps its number and the refusal is audited', (string) Database::scalar('SELECT phone FROM ap_applications WHERE draft_key = :k', ['k' => $key], '') === '9000000106' && $denied($aPh, 'agentpro.office_verify'));
    $asAdmin($aSa);
    $err = '';
    try { AgentPro::officeVerify($key, $aSa, 'came to the office', '9000000107', 'IN'); } catch (Throwable $e) { $err = $e->getMessage(); }
    check('super-admin: AgentPro office form moves the live member\'s login mobile', $err === '' && $loginPh($aPh) === '9000000107', $err . ' / ' . $loginPh($aPh));
    $_SESSION = $saved;
} else {
    check('ap_applications (af1) present for the AgentPro office-form check', false, 'run database/upgrade-2026-10-30-agentpro-af1.sql');
}

/* ===================================================================== */
echo "\n-- E. Remember days\n";
$agentRow = ['id' => $aAgent, 'role' => 'agent'];
memSetting('login_remember_roles', '["agent","counter","scanner","support","operations","staff"]', 'json');
$r = new ReflectionClass(Settings::class);
$c = $r->getProperty('cache'); $c->setAccessible(true);
$cache = (array) $c->getValue(); unset($cache['login_remember_days'], $cache['login_remember_max_days']); $c->setValue(null, $cache);
$w = AdminSessions::rememberWindow($agentRow);
check('login_remember_days unset: 90 days', $w === 90 * 86400, (string) intdiv($w, 86400) . ' days');
memSetting('login_remember_days', '365', 'int');
check('365 asked, cap 90 by default: 90 days', AdminSessions::rememberWindow($agentRow) === 90 * 86400);
memSetting('login_remember_days', '30', 'int');
check('30 asked: 30 days', AdminSessions::rememberWindow($agentRow) === 30 * 86400);
memSetting('login_remember_days', '365', 'int');
memSetting('login_remember_max_days', '730', 'int');
check('cap raised to 730: 365 days', AdminSessions::rememberWindow($agentRow) === 365 * 86400);
memSetting('login_remember_max_days', '5000', 'int');
memSetting('login_remember_days', '5000', 'int');
check('never more than 730 days', AdminSessions::rememberWindow($agentRow) === 730 * 86400);

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
