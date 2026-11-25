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
 *      never moves it, nor display_phone (the box is shown disabled; a number
 *      posted anyway is refused, the rest saves); a super-admin does; the
 *      AgentPro office form never moves a live member's login email either (the
 *      form goes back to it, one audit per try, api save says why)
 *   D3 agreements.php: creds / sendcreds 403 for a manager (no forms) - they change
 *      an EXISTING login; CREATING one stays with the office: "Create login"
 *      (mklogin) and go-live / approve with mklogin make the login + first password
 *      for a manager too; a password typed on approve for a login that already
 *      exists is skipped (audited); super-admin works; lock off = old rule
 *   D4 staff_credentials_lock_on / chalani_export_perm_on: a manager cannot flip
 *      them on settings.php Save, Easy Settings nor the S Hari AI 'setting' op;
 *      a super-admin can (AiOps::SUPER_ONLY_SETTINGS, the one list)
 *   D4b settings.php Save: role_permissions_override, admin_2fa_ids and every
 *      other row of the 'security' sgroup are refused for a manager (rows
 *      unchanged, a note names them, shown read-only); a super-admin writes them
 *   D5 staff-applications.php: pwset / pwclear / the sign-in mobile on 'edit' are
 *      a super-admin's (403 / refused + audited, the rest of the edit saves);
 *      approving with a password (onboarding) stays with the office
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
    $dir    = (string) ($cfg['dir'] ?? 'admin') === 'api' ? 'api' : 'admin';   // api = a JSON endpoint (api/agentpro.php)
    $post   = (array) ($cfg['post'] ?? []);
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.71';
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    $_SERVER['SCRIPT_NAME']    = '/' . $dir . '/' . $script;
    $_SERVER['REQUEST_URI']    = '/' . $dir . '/' . $script;
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
    require dirname(__DIR__) . '/' . $dir . '/' . $script;
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
    try { Database::delete('agent_applications', "ref LIKE 'MPLG%'", []); } catch (Throwable $e) {}   // D3 rows
    try { Database::delete('staff_applications', "id_number LIKE 'MPLG-STF-%'", []); } catch (Throwable $e) {}   // D5 rows
    // D3: the logins a manager now makes from an application (username = the test mobile)
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE '{$PFX}%'
                                 OR (role = 'agent' AND full_name LIKE 'Mp Agr Person%' AND username IN ('9000000202', 'agent_9000000202', '9000000203', 'agent_9000000203'))") as $r) {
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
foreach (['includes/staffavatar.php', 'admin/_guard.php', 'admin/login.php', 'admin/staff.php', 'admin/sessions.php', 'admin/login-requests.php', 'admin/agent.php', 'admin/agents.php', 'admin/agent-360.php', 'includes/agentpro.php', 'admin/agreements.php', 'admin/easy-settings.php', 'payment/icici_booking.php',
          'admin/settings.php', 'includes/aiops.php', 'api/agentpro.php', 'admin/staff-applications.php'] as $f) {
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
Database::insert('admin_profiles', ['admin_id' => $aPh, 'display_phone' => '9000000199']);   // a ticket number of its own
$nameOf = static fn(int $id): string => (string) Database::scalar('SELECT full_name FROM admins WHERE id = :i', ['i' => $id], '');
$edit = static fn(string $phone, string $name = 'Mp Phone Agent'): array => ['action' => 'edit', 'id' => $aPh, 'full_name' => $name, 'phone' => $phone, 'agent_kind' => 'person'];
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agents.php', 'post' => $edit('9000000102', 'Mp Phone Agent B')]);
check('manager: agents.php edit with a new mobile posted -> admins.phone unchanged', $loginPh($aPh) === '9000000101', $loginPh($aPh));
check('...and display_phone unchanged too (the box is not a ticket number)', $dispPh($aPh) === '9000000199', $dispPh($aPh));
check('...the other fields saved, the mobile refused with the note', $nameOf($aPh) === 'Mp Phone Agent B' && str_contains($o, 'Agent updated.')
    && str_contains($o, 'मोबाइल नम्बर सुपर-एडमिनले मात्र बदल्न मिल्छ · Only a super-admin can change the login mobile') && !str_contains($o, 'saved as the number shown on tickets'));
check('...the refusal is audited', $denied($aPh, 'agents.edit.phone'));
$noPhone = $edit('', 'Mp Phone Agent C');
unset($noPhone['phone']);   // the disabled box posts nothing
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agents.php', 'post' => $noPhone]);
check('manager: agents.php edit with no mobile posted (disabled box) saves the rest', $nameOf($aPh) === 'Mp Phone Agent C' && $loginPh($aPh) === '9000000101' && $dispPh($aPh) === '9000000199'
    && str_contains($o, 'Agent updated.') && !str_contains($o, 'Enter a valid mobile number'), $nameOf($aPh));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agents.php']);
check('manager: the register form shows admins.phone read-only (disabled, not posted) + the note', str_contains($o, '<input value="9000000101" disabled')
    && str_contains($o, 'मोबाइल नम्बर सुपर-एडमिनले मात्र बदल्न मिल्छ · Only a super-admin can change the login mobile') && !str_contains($o, 'Mobile (on tickets)'));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agent-360.php', 'get' => ['agent' => $aPh], 'post' => ['action' => 'edit_basic', 'agent_id' => $aPh, 'tab' => 'report', 'full_name' => 'Mp Phone Agent', 'phone' => '9000000104']]);
check('manager: agent-360 edit_basic with a new mobile posted -> admins.phone + display_phone unchanged, name saved', $loginPh($aPh) === '9000000101' && $dispPh($aPh) === '9000000199' && $nameOf($aPh) === 'Mp Phone Agent', $loginPh($aPh) . ' / ' . $dispPh($aPh));
check('...audited', $denied($aPh, 'agent360.edit_basic.phone'));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agent-360.php', 'get' => ['agent' => $aPh, 'tab' => 'report']]);
check('manager: agent-360 Edit details shows the mobile disabled + the note', str_contains($o, 'value="9000000101" disabled') && str_contains($o, 'Only a super-admin can change the login mobile') && !str_contains($o, 'Mobile (on tickets)'));
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

    // the login email of a live member (saveDraft office mode -> syncApproved)
    $mailOf = static fn(int $id): string => (string) Database::scalar('SELECT email FROM admins WHERE id = :i', ['i' => $id], '');
    $mail0  = 'mp1lg-' . bin2hex(random_bytes(3)) . '@example.test';
    $mail1  = 'mp1lg-' . bin2hex(random_bytes(3)) . '@example.test';
    $mail2  = 'mp1lg-' . bin2hex(random_bytes(3)) . '@example.test';
    Database::update('admins', ['email' => $mail0], 'id = :i', ['i' => $aPh]);
    $formMail = static fn(): string => (string) Database::scalar('SELECT email FROM ap_applications WHERE draft_key = :k', ['k' => $key], '');
    $nDen0    = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'staff.credentials_denied' AND entity_id = :e AND new_value LIKE '%agentpro.sync_email%'", ['e' => (string) $aPh], 0);
    $nDenied  = static fn(): int => (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'staff.credentials_denied' AND entity_id = :e AND new_value LIKE '%agentpro.sync_email%'", ['e' => (string) $aPh], 0) - $nDen0;   // this run's lines
    $asAdmin($aMgr);
    AgentPro::takeNotice();
    $err = '';
    try { AgentPro::saveDraft($key, ['email' => $mail1, 'city' => 'Mp Nepalgunj'], true); } catch (Throwable $e) { $err = $e->getMessage(); }
    check('manager: AgentPro office form, live member, new email -> admins.email unchanged, the rest synced', $err === '' && $mailOf($aPh) === $mail0
        && (string) Database::scalar('SELECT counter_name FROM admin_profiles WHERE admin_id = :i', ['i' => $aPh], '') === 'Mp Nepalgunj', $err . ' / ' . $mailOf($aPh));
    check('...the form goes back to the live login email (form = admins.email) and the refusal is audited once', $formMail() === $mail0 && $nDenied() === 1, $formMail() . ' / ' . $nDenied());
    check('...the save says why (AgentPro::takeNotice, read once)', AgentPro::takeNotice() === 'लगइन इमेल सुपर-एडमिनले मात्र बदल्न मिल्छ · Only a super-admin can change the login email' && AgentPro::takeNotice() === '');
    try { AgentPro::saveDraft($key, ['email' => $mail0, 'city' => 'Mp Nepalgunj B'], true); } catch (Throwable $e) { $err = $e->getMessage(); }
    check('...a later save of the same (now reverted) form is no new attempt: no audit, no notice, the rest saves', $nDenied() === 1 && AgentPro::takeNotice() === ''
        && (string) Database::scalar('SELECT counter_name FROM admin_profiles WHERE admin_id = :i', ['i' => $aPh], '') === 'Mp Nepalgunj B', (string) $nDenied());
    // the office form's own endpoint (api/agentpro.php?a=save, office mode): ok + the visible message, the rest saved
    $o = child(['what' => 'page', 'dir' => 'api', 'script' => 'agentpro.php', 'admin' => $aMgr, 'get' => ['a' => 'save'],
        'post' => ['key' => $key, 'office' => '1', 'email' => $mail1, 'city' => 'Mp Nepalgunj C']]);
    $js = ($p0 = strpos($o, '{"ok"')) !== false ? json_decode(trim(substr($o, $p0, (int) strpos($o, '<!--STATUS') - $p0)), true) : null;
    check('api save (manager, new login email): ok, message "Only a super-admin can change the login email", the rest saved', is_array($js) && ($js['ok'] ?? false) === true
        && (string) ($js['message'] ?? '') === 'लगइन इमेल सुपर-एडमिनले मात्र बदल्न मिल्छ · Only a super-admin can change the login email'
        && (string) ($js['data']['draft']['email'] ?? '') === $mail0 && $mailOf($aPh) === $mail0 && $formMail() === $mail0
        && (string) Database::scalar('SELECT counter_name FROM admin_profiles WHERE admin_id = :i', ['i' => $aPh], '') === 'Mp Nepalgunj C', substr($o, 0, 300));
    check('...one more audit line for that one more try', $nDenied() === 2, (string) $nDenied());
    $o = child(['what' => 'page', 'dir' => 'api', 'script' => 'agentpro.php', 'admin' => $aMgr, 'get' => ['a' => 'save'],
        'post' => ['key' => $key, 'office' => '1', 'city' => 'Mp Nepalgunj D']]);
    $js = ($p0 = strpos($o, '{"ok"')) !== false ? json_decode(trim(substr($o, $p0, (int) strpos($o, '<!--STATUS') - $p0)), true) : null;
    check('api save with no email change: ok, no message', is_array($js) && ($js['ok'] ?? false) === true && !isset($js['message']) && $nDenied() === 2, substr($o, 0, 300));
    $asAdmin($aSa);
    $err = '';
    try { AgentPro::saveDraft($key, ['email' => $mail2], true); } catch (Throwable $e) { $err = $e->getMessage(); }
    check('super-admin: AgentPro office form moves the live member\'s login email', $err === '' && $mailOf($aPh) === $mail2 && $formMail() === $mail2 && AgentPro::takeNotice() === '', $err . ' / ' . $mailOf($aPh));
    $_SESSION = $saved;
} else {
    check('ap_applications (af1) present for the AgentPro office-form check', false, 'run database/upgrade-2026-10-30-agentpro-af1.sql');
}

/* review fix: admin/agreements.php - a linked login's username / email / password (creds, sendcreds)
   are the super-admin's too; making a NEW login (mklogin, go-live / approve) stays with the office. */
echo "\n-- D3. Agreements page: sign-in details of a linked login\n";
require_once INCLUDE_PATH . '/agentapplications.php';
if (!AgentApplications::available()) {
    check('agent_applications present for the agreements checks', false, 'run the agreements migration');
} else {
    $appRow = static function (string $st, ?int $adminId, string $phone): int {
        return (int) Database::insert('agent_applications', ['ref' => 'MPLG' . strtoupper(bin2hex(random_bytes(4))), 'type' => 'ind', 'name' => 'Mp Agr Person',
            'phone' => $phone, 'status' => $st, 'kyc_status' => 'verified', 'admin_id' => $adminId]);
    };
    $userOf = static fn(int $id): string => (string) Database::scalar('SELECT username FROM admins WHERE id = :i', ['i' => $id], '');
    $aAgr = $mk('agr', 'agent', 'Mp Agr Person');
    Database::update('admins', ['phone' => '9000000201'], 'id = :i', ['i' => $aAgr]);
    $app1 = $appRow('approved', $aAgr, '9000000201');
    $hA   = $hashOf($aAgr);
    $agrGet = ['tab' => 'apps', 'id' => (string) $app1];
    $creds  = static fn(string $u, string $pw): array => ['action' => 'creds', 'id' => $app1, 'username' => $u, 'login_email' => '', 'new_password' => $pw];
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agreements.php', 'get' => $agrGet, 'post' => $creds($PFX . 'agr2', 'MgrSet#Pass2026')]);
    check('manager: agreements creds -> 403, username + password unchanged', status($o) === 403 && $userOf($aAgr) === $PFX . 'agr' && $hashOf($aAgr) === $hA, 'status ' . status($o) . ' ' . $userOf($aAgr));
    check('...says why and is audited', str_contains($o, 'Only a super-admin can change the username, email or password') && $denied($aAgr, 'agreements.creds'));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agreements.php', 'get' => $agrGet, 'post' => ['action' => 'sendcreds', 'id' => $app1]]);
    check('manager: agreements sendcreds -> 403, no new password, audited', status($o) === 403 && $hashOf($aAgr) === $hA && $denied($aAgr, 'agreements.sendcreds'), 'status ' . status($o));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agreements.php', 'get' => $agrGet]);
    check('manager: the Login account panel has no creds / sendcreds forms and says who changes them', str_contains($o, 'id="login"')
        && !str_contains($o, 'name="action" value="creds"') && !str_contains($o, 'name="action" value="sendcreds"') && str_contains($o, 'Only a super-admin changes the username, email or password'));
    $o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'agreements.php', 'get' => $agrGet]);
    check('super-admin: the creds + sendcreds forms are there', str_contains($o, 'name="action" value="creds"') && str_contains($o, 'name="action" value="sendcreds"'));
    $o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'agreements.php', 'get' => $agrGet, 'post' => $creds($PFX . 'agr2', '')]);
    check('super-admin: agreements creds changes the username', $userOf($aAgr) === $PFX . 'agr2', $userOf($aAgr) . ' ' . substr(strip_tags($o), 0, 160));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agreements.php', 'get' => $agrGet, 'post' => $creds($PFX . 'agr3', ''), 'mem' => ['staff_credentials_lock_on' => ['0', 'bool']]]);
    check('staff_credentials_lock_on = 0: a manager changes it again (old rule)', $userOf($aAgr) === $PFX . 'agr3', $userOf($aAgr) . ' status ' . status($o));

    /* the release rule: CREATING a brand-new login is onboarding - the office's, as before: "Create login"
       (mklogin) and go-live / approve with mklogin make the login + its first password for a manager too */
    $loginOf = static fn(string $p10): ?array => Database::fetch("SELECT id, username, password_hash FROM admins WHERE role = 'agent' AND phone LIKE :p ORDER BY id DESC LIMIT 1", ['p' => '%' . $p10]);
    $appOf   = static fn(int $id): array => Database::fetch('SELECT status, admin_id, ref FROM agent_applications WHERE id = :i', ['i' => $id]) ?? [];
    $app2 = $appRow('reviewing', null, '9000000202');
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agreements.php', 'get' => ['tab' => 'apps', 'id' => (string) $app2]]);
    check('manager: a new application shows the password-on-approve box + the go-live "create the login" tick, no super-admin note',
        str_contains($o, 'id="agrPw"') && str_contains($o, 'name="mklogin" value="1"') && !str_contains($o, 'Login is created by a super-admin'));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agreements.php', 'get' => ['tab' => 'apps', 'id' => (string) $app2],
        'post' => ['action' => 'golive', 'id' => $app2, 'mklogin' => '1', 'new_password' => 'MgrSet#Pass2026']]);
    $row2 = $appOf($app2);
    $lg2  = $loginOf('9000000202');
    check('manager: go-live with mklogin -> approved AND the login made with the typed first password (onboarding)', ($row2['status'] ?? '') === 'approved'
        && !empty($row2['admin_id']) && $lg2 !== null && (int) $lg2['id'] === (int) $row2['admin_id'] && password_verify('MgrSet#Pass2026', (string) $lg2['password_hash']),
        json_encode($row2) . ' status ' . status($o) . ' ' . substr(strip_tags($o), 0, 160));
    check('...nothing refused on the way', !Database::exists("SELECT 1 FROM audit_logs WHERE action = 'staff.credentials_denied' AND (entity_id = :e OR entity_id = :a)",
        ['e' => (string) ($row2['ref'] ?? '-'), 'a' => (string) (int) ($row2['admin_id'] ?? 0)]));
    // the standalone "Create login" (action=mklogin) on an approved application with no account
    $app3 = $appRow('approved', null, '9000000203');
    $o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'agreements.php', 'get' => ['tab' => 'apps', 'id' => (string) $app3]]);
    check('super-admin: the "Create login" button is there', str_contains($o, 'name="action" value="mklogin"') && str_contains($o, 'Create login (sell tickets)'));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agreements.php', 'get' => ['tab' => 'apps', 'id' => (string) $app3]]);
    check('manager: the "Create login" button is back too (no "Login is created by a super-admin" note)', str_contains($o, 'name="action" value="mklogin"')
        && str_contains($o, 'Create login (sell tickets)') && !str_contains($o, 'Login is created by a super-admin'));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agreements.php', 'get' => ['tab' => 'apps', 'id' => (string) $app3], 'post' => ['action' => 'mklogin', 'id' => $app3]]);
    $row3 = $appOf($app3);
    $lg3  = $loginOf('9000000203');
    check('manager: agreements mklogin POST makes the login (no 403)', status($o) !== 403 && !empty($row3['admin_id']) && $lg3 !== null && (int) $lg3['id'] === (int) $row3['admin_id'],
        'status ' . status($o) . ' ' . json_encode($row3));
    // ...but a password typed on approve for a login that ALREADY exists changes that login: the super-admin's
    $hA2 = $hashOf($aAgr);
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'agreements.php', 'get' => $agrGet, 'post' => ['action' => 'golive', 'id' => $app1, 'mklogin' => '1', 'new_password' => 'MgrSet#Pass2027']]);
    check('manager: go-live with a password on an application that already has a login -> password unchanged, audited (agreements.approve_password)',
        $hashOf($aAgr) === $hA2 && $denied($aAgr, 'agreements.approve_password'), 'status ' . status($o));
    $o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'agreements.php', 'get' => $agrGet, 'post' => ['action' => 'golive', 'id' => $app1, 'mklogin' => '1', 'new_password' => 'SaSet#Pass2027']]);
    check('super-admin: the same go-live sets that password', password_verify('SaSet#Pass2027', $hashOf($aAgr)), 'status ' . status($o));
}

// the lock's own switch on Easy Settings: a manager (settings.manage) may not undo the lock placed on managers
$lockRow = Database::fetch("SELECT * FROM settings WHERE skey = 'staff_credentials_lock_on'");
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'easy-settings.php', 'post' => ['act' => 'nset', 'key' => 'staff_credentials_lock_on']]);
$lockNow = Database::fetch("SELECT * FROM settings WHERE skey = 'staff_credentials_lock_on'");
check('manager: Easy Settings refuses staff_credentials_lock_on (super-admin only), nothing written', $lockNow == $lockRow && str_contains($o, 'Only a super-admin can change this switch'), substr(strip_tags($o), 0, 120));
if ($lockNow != $lockRow) {   // never leave a changed lock behind on the shared box
    Database::delete('settings', "skey = 'staff_credentials_lock_on'", []);
    if (is_array($lockRow)) { Database::insert('settings', $lockRow); }
}
$o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'easy-settings.php']);
$sixOk = true;
foreach (['staff_credentials_lock_on', 'chalani_export_perm_on', 'login_mode_cards_on', 'staff_avatar_on', 'chalani_money_strip_on', 'icici_strict_fields_on'] as $sk) {
    if (!str_contains($o, 'data-esn="' . $sk . '"')) { $sixOk = false; check("Easy Settings lists $sk", false); }
}
check('Easy Settings lists the six mp1 switches (owner can roll each back)', $sixOk);

/* review fix: the two permission switches (a lock on the managers themselves) are the super-admin's
   from every door - settings.php Save, Easy Settings nset, the S Hari AI 'setting' op (cmd / yes).
   A row is needed for those doors to see the key at all: each gets a temporary row 'true' (read as
   ON = the code default, so nothing behaves differently meanwhile); a super-admin's write turns it
   into '1' (still ON) - visible, harmless. The rows as they were are put back at the end. */
echo "\n-- D4. The permission switches are the super-admin's\n";
require_once INCLUDE_PATH . '/aiops.php';
$SU_KEYS = ['staff_credentials_lock_on', 'chalani_export_perm_on'];
check('one list: AiOps::SUPER_ONLY_SETTINGS = the two lock switches + the role matrix + the 2FA list', AiOps::SUPER_ONLY_SETTINGS === array_merge($SU_KEYS, ['role_permissions_override', 'admin_2fa_ids'])
    && AiOps::superOnlySetting('staff_credentials_lock_on') && AiOps::superOnlySetting('chalani_export_perm_on') && AiOps::superOnlySetting('role_permissions_override')
    && AiOps::superOnlySetting('admin_2fa_ids') && !AiOps::superOnlySetting('staff_avatar_on'));
$esSrc = (string) file_get_contents($ROOT . '/admin/easy-settings.php') . (string) file_get_contents($ROOT . '/admin/settings.php');
check('settings.php + easy-settings.php read that list (AiOps::superOnlySetting)', substr_count($esSrc, 'AiOps::superOnlySetting(') >= 3);
$suKeep = [];
foreach ($SU_KEYS as $sk) { $suKeep[$sk] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $sk]); }
$suRestore = static function () use ($suKeep): void {
    foreach ($suKeep as $sk => $row) {
        try {
            Database::delete('settings', 'skey = :k', ['k' => $sk]);
            if (is_array($row)) { Database::insert('settings', $row); }
        } catch (Throwable $e) { echo "  !! could not restore $sk: " . $e->getMessage() . "\n"; }
    }
    Settings::flush();
};
register_shutdown_function($suRestore);
$suSet = static function (string $v) use ($SU_KEYS): void {
    foreach ($SU_KEYS as $sk) {
        Database::run('INSERT INTO settings (skey, svalue, stype, sgroup, is_public) VALUES (:k, :v, \'bool\', \'security\', 0)
                       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), stype = VALUES(stype)', ['k' => $sk, 'v' => $v]);
    }
    Settings::flush();
};
$suVal = static fn(string $sk): string => (string) Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => $sk], '');
$suAll = static fn(string $v): bool => $suVal('staff_credentials_lock_on') === $v && $suVal('chalani_export_perm_on') === $v;
$suSet('true');
check('the temporary rows read as ON (the code default)', Settings::getBool('staff_credentials_lock_on', false) && Settings::getBool('chalani_export_perm_on', false));

// settings.php - the generic Save
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'settings.php']);
preg_match('/name="_boolkeys" value="([^"]*)"/', $o, $bm);
$bkList = explode(',', html_entity_decode((string) ($bm[1] ?? '')));
check('manager: settings.php shows the two switches read-only (disabled + note) and leaves them out of _boolkeys',
    preg_match('/name="s\[staff_credentials_lock_on\]" value="1"\s+(checked\s+)?disabled\s+data-saved/', $o) === 1 && preg_match('/name="s\[chalani_export_perm_on\]" value="1"\s+(checked\s+)?disabled\s+data-saved/', $o) === 1
    && str_contains($o, 'Only a super-admin can change this switch.') && !in_array('staff_credentials_lock_on', $bkList, true) && !in_array('chalani_export_perm_on', $bkList, true) && count($bkList) > 2,
    count($bkList) . ' boolkeys');
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'settings.php', 'post' => ['_boolkeys' => implode(',', $SU_KEYS)]]);
check('manager: settings.php Save with both unticked (_boolkeys) -> both still ON, the rest "saved", a note says why', $suAll('true')
    && str_contains($o, 'Settings saved.') && str_contains($o, 'Only a super-admin can change staff_credentials_lock_on, chalani_export_perm_on'), $suVal('staff_credentials_lock_on') . '/' . $suVal('chalani_export_perm_on'));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'settings.php', 'post' => ['s' => ['staff_credentials_lock_on' => '0', 'chalani_export_perm_on' => 'off']]]);
check('manager: settings.php Save with s[...] = 0 -> both still ON, note', $suAll('true') && str_contains($o, 'Only a super-admin can change staff_credentials_lock_on, chalani_export_perm_on'));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'settings.php', 'post' => ['s' => ['staff_credentials_lock_on' => '1'], '_boolkeys' => 'staff_credentials_lock_on']]);
check('manager: posting the value it already has -> no note (nothing would change)', $suAll('true') && str_contains($o, 'Settings saved.') && !str_contains($o, 'Only a super-admin can change staff_credentials_lock_on'));
$o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'settings.php']);
check('super-admin: settings.php switches editable, in _boolkeys', preg_match('/name="s\[staff_credentials_lock_on\]" value="1"\s+(checked\s+)?data-saved/', $o) === 1
    && preg_match('/name="_boolkeys" value="[^"]*staff_credentials_lock_on/', $o) === 1);
$o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'settings.php', 'post' => ['s' => ['staff_credentials_lock_on' => '1', 'chalani_export_perm_on' => '1'], '_boolkeys' => implode(',', $SU_KEYS)]]);
check('super-admin: settings.php Save writes them (stored "1", no note)', $suAll('1') && str_contains($o, 'Settings saved.') && !str_contains($o, 'Only a super-admin can change'), $suVal('staff_credentials_lock_on'));
$suSet('true');

// the S Hari AI 'setting' op (easy-settings cmd / yes, the in-page bubble) - AiOps in this process
$suSaved = $_SESSION ?? [];
$asSess = static function (int $id): array {
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $id]);
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time()];
    return $_SESSION[ADMIN_SESSION_KEY];
};
$mgrS = $asSess($aMgr);
check('manager can manage settings (so only the lock rule stops it)', Auth::canManageSettings() && !Auth::isSuperadmin());
check('manager: AiOps::settingEditable / settingRow refuse both lock switches', !AiOps::settingEditable('staff_credentials_lock_on') && !AiOps::settingEditable('chalani_export_perm_on')
    && AiOps::settingRow('staff_credentials_lock_on') === null && AiOps::settingRow('chalani_export_perm_on') === null && AiOps::settingEditable('staff_avatar_on'));
check('manager: AiOps::allowed refuses a setting op on them, allows another setting', !AiOps::allowed(['type' => 'setting', 'key' => 'staff_credentials_lock_on'])
    && !AiOps::allowed(['type' => 'setting', 'key' => 'chalani_export_perm_on']) && AiOps::allowed(['type' => 'setting', 'key' => 'staff_avatar_on']));
$pr = AiOps::propose('staff_credentials_lock_on off', true, false);
$prBad = array_filter((array) $pr['ops'], static fn(array $a): bool => AiOps::superOnlySetting((string) ($a['key'] ?? '')));
check('manager: the cmd words "staff_credentials_lock_on off" propose nothing for it', $prBad === [] && !isset($pr['switches']['staff_credentials_lock_on']), json_encode($pr['ops']));
$done = AiOps::apply([['type' => 'setting', 'key' => 'staff_credentials_lock_on', 'value' => '0'], ['type' => 'setting', 'key' => 'chalani_export_perm_on', 'value' => '0']], $mgrS);
check('manager: an AI-shaped "yes" (AiOps::apply setting = 0) writes nothing', $suAll('true') && !str_contains(implode(' ', $done), '⚙️'), implode(' | ', $done));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'easy-settings.php']);
check('manager: Easy Settings shows both switches disabled and leaves them out of the setting finder', preg_match('/data-esn="staff_credentials_lock_on">(?:(?!data-esn=).)*?class="esn-t" disabled>/s', $o) === 1
    && preg_match('/data-esn="chalani_export_perm_on">(?:(?!data-esn=).)*?class="esn-t" disabled>/s', $o) === 1 && !str_contains($o, '"k":"staff_credentials_lock_on"') && !str_contains($o, '"k":"chalani_export_perm_on"'));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'easy-settings.php', 'post' => ['act' => 'nset', 'key' => 'chalani_export_perm_on']]);
check('manager: Easy Settings nset chalani_export_perm_on refused (super-admin only)', $suAll('true') && str_contains($o, 'Only a super-admin can change this switch'));
$saS = $asSess($aSa);
check('super-admin: settingEditable + allowed for both', AiOps::settingEditable('staff_credentials_lock_on') && AiOps::settingEditable('chalani_export_perm_on')
    && AiOps::allowed(['type' => 'setting', 'key' => 'staff_credentials_lock_on']));
$done = AiOps::apply([['type' => 'setting', 'key' => 'staff_credentials_lock_on', 'value' => '1'], ['type' => 'setting', 'key' => 'chalani_export_perm_on', 'value' => '1']], $saS);
check('super-admin: AiOps::apply setting writes them (stored "1")', $suAll('1') && substr_count(implode(' ', $done), '⚙️') === 2, implode(' | ', $done));
$_SESSION = $suSaved;
$suRestore();
check('the lock rows are back as they were', array_map(static fn(string $sk): ?array => Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $sk]), array_combine($SU_KEYS, $SU_KEYS)) == $suKeep);

/* review fix: a settings.manage manager's plain Save could rewrite the 'security' rows - the role matrix
   (role_permissions_override), who has 2FA (admin_2fa_ids), PIN lock, device limits - and with them
   undo the credential lock. Refused for anyone but a super-admin (rows unchanged, a note names them,
   shown read-only); a super-admin's Save writes them. Every row is put back exactly (svalue +
   updated_at) - unless another suite changed it meanwhile, which is then said and left alone. The
   super-admin writes only no-op values (a role that does not exist, an admin id that does not exist). */
echo "\n-- D4b. Security rows on settings.php Save\n";
$SEC = ['role_permissions_override' => ['json', '[]'], 'admin_2fa_ids' => ['json', '[]'], 'pin_lock_idle_min' => ['int', '0']];
$secKeep = []; $secMade = []; $secMine = [];
foreach ($SEC as $sk => [$st, $sv]) {
    $row = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $sk]);
    if ($row === null) {   // a fresh box: a temporary row (the code default), removed at the end
        Database::insert('settings', ['skey' => $sk, 'svalue' => $sv, 'stype' => $st, 'sgroup' => 'security', 'is_public' => 0]);
        $row = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $sk]);
        $secMade[$sk] = true;
    }
    $secKeep[$sk] = $row;
}
$secVal  = static fn(string $sk): string => (string) Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => $sk], '');
$secSame = static function () use ($secKeep, $secVal): bool {
    foreach ($secKeep as $sk => $row) {
        if ($secVal($sk) !== (string) $row['svalue']) { return false; }
    }
    return true;
};
$secRestore = static function () use (&$secKeep, &$secMade, &$secMine): void {
    foreach ($secKeep as $sk => $row) {
        try {
            if (!empty($secMade[$sk])) { Database::delete('settings', 'skey = :k', ['k' => $sk]); continue; }
            $now = (string) Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => $sk], '');
            if ($now !== (string) $row['svalue'] && in_array($now, $secMine[$sk] ?? [], true)) {
                Database::run('UPDATE settings SET svalue = :v, updated_at = :u WHERE skey = :k', ['v' => $row['svalue'], 'u' => $row['updated_at'], 'k' => $sk]);
            } elseif ($now !== (string) $row['svalue']) {
                echo "  !! $sk was changed by someone else meanwhile - left as it is\n";
            }
        } catch (Throwable $e) { echo "  !! could not restore $sk: " . $e->getMessage() . "\n"; }
    }
    $secMade = []; $secMine = [];
    Settings::flush();
};
register_shutdown_function($secRestore);
$secBool = (string) Database::scalar("SELECT skey FROM settings WHERE sgroup = 'security' AND stype = 'bool' ORDER BY skey LIMIT 1", [], '');
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'settings.php']);
preg_match('/name="_boolkeys" value="([^"]*)"/', $o, $bm);
$bkMgr = explode(',', html_entity_decode((string) ($bm[1] ?? '')));
check('manager: settings.php shows the security rows read-only (disabled = not posted + note); a security switch is not in _boolkeys',
    preg_match('/<textarea name="s\[role_permissions_override\]"[^>]*\sdisabled>/', $o) === 1 && preg_match('/<textarea name="s\[admin_2fa_ids\]"[^>]*\sdisabled>/', $o) === 1
    && preg_match('/name="s\[pin_lock_idle_min\]"[^>]*\sdisabled>/', $o) === 1 && str_contains($o, 'Only a super-admin can change this setting.')
    && ($secBool === '' || !in_array($secBool, $bkMgr, true)) && count($bkMgr) > 2, $secBool . ' / ' . count($bkMgr) . ' boolkeys');
$o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'settings.php']);
preg_match('/name="_boolkeys" value="([^"]*)"/', $o, $bm);
$bkSa = explode(',', html_entity_decode((string) ($bm[1] ?? '')));
check('super-admin: the security rows are editable, the security switch is in _boolkeys', str_contains($o, 'name="s[role_permissions_override]"')
    && preg_match('/<textarea name="s\[role_permissions_override\]"[^>]*\sdisabled>/', $o) === 0 && preg_match('/name="s\[pin_lock_idle_min\]"[^>]*\sdisabled>/', $o) === 0
    && ($secBool === '' || in_array($secBool, $bkSa, true)) && !str_contains($o, 'Only a super-admin can change this setting.'));
$orig2fa = (string) $secKeep['admin_2fa_ids']['svalue'];
$secTry  = ['role_permissions_override' => '{"manager":{"staff.credentials":true,"settings.manage":true}}',
            'admin_2fa_ids'             => trim($orig2fa) === '[]' || trim($orig2fa) === '' ? '[999999992]' : '[]',   // everybody's 2FA off
            'pin_lock_idle_min'         => (string) ((int) $secKeep['pin_lock_idle_min']['svalue'] + 7)];
foreach ($secTry as $sk => $v) { $secMine[$sk][] = $v; }   // put back even if a bug wrote them
$nDen = static fn(): int => (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.super_only_denied' AND new_value LIKE '%role_permissions_override%' AND new_value LIKE '%admin_2fa_ids%'", [], 0);
$nDen0 = $nDen();
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'settings.php', 'post' => ['s' => $secTry]]);
check('manager: settings.php Save with s[role_permissions_override] + s[admin_2fa_ids] (+ a security int) -> the rows unchanged', $secSame(),
    $secVal('role_permissions_override') . ' / ' . $secVal('admin_2fa_ids') . ' / ' . $secVal('pin_lock_idle_min'));
check('...the rest "saved", the note names the three keys, audited once', str_contains($o, 'Settings saved.')
    && str_contains($o, 'Only a super-admin can change role_permissions_override, admin_2fa_ids, pin_lock_idle_min — left as it was.') && $nDen() === $nDen0 + 1,
    substr(strip_tags((string) preg_replace('/.*?(Settings saved[^<]*).*/s', '$1', $o)), 0, 240));
$o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'settings.php', 'post' => ['s' => array_map(static fn(array $r): string => (string) $r['svalue'], $secKeep)]]);
check('manager: posting the values the rows already have -> saved, no note, rows unchanged', str_contains($o, 'Settings saved.') && !str_contains($o, '— left as it was.') && $secSame());
$saTry = ['role_permissions_override' => '{"mp1lg_norole":{"dashboard.view":false}}',
          'admin_2fa_ids'             => (string) json_encode(array_values(array_merge(array_map('intval', (array) (json_decode($orig2fa, true) ?: [])), [999999991])))];
foreach ($saTry as $sk => $v) { $secMine[$sk][] = $v; }
$o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'settings.php', 'post' => ['s' => $saTry]]);
check('super-admin: settings.php Save writes role_permissions_override + admin_2fa_ids, no note', $secVal('role_permissions_override') === $saTry['role_permissions_override']
    && $secVal('admin_2fa_ids') === $saTry['admin_2fa_ids'] && str_contains($o, 'Settings saved.') && !str_contains($o, '— left as it was.'),
    $secVal('role_permissions_override') . ' / ' . $secVal('admin_2fa_ids'));
$secWasMade = $secMade;
$secRestore();
$secNow = []; $secWant = [];
foreach ($secKeep as $sk => $row) {
    $secNow[$sk]  = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $sk]);
    $secWant[$sk] = !empty($secWasMade[$sk]) ? null : $row;   // a temporary row is gone again
}
check('the security rows are back as they were (svalue + updated_at)', $secNow == $secWant, 'see the !! line above');

/* review fix: admin/staff-applications.php - an EXISTING person's portal password (pwset / pwclear) and the
   sign-in mobile (edit: phone / phone_country; /team/ = mobile + password) are the super-admin's; the
   rest of an edit still saves; approving with a first password (onboarding) stays with the office. */
echo "\n-- D5. Staff applications: an existing person's sign-in\n";
require_once INCLUDE_PATH . '/staffapplications.php';
require_once INCLUDE_PATH . '/teamportal.php';
if (!StaffApps::available() || !TeamPortal::ready()) {
    check('staff_applications + the team-portal columns present for the staff checks', false, 'run database/upgrade-2026-10-22-staff-applications.sql');
} else {
    $stfRow = static function (string $st, string $phone, string $n, ?string $pw): int {
        return (int) Database::insert('staff_applications', ['full_name' => 'Mp Stf Person', 'phone' => $phone, 'phone_country' => 'IN', 'phone_verified' => 1,
            'role_applied' => 'driver', 'id_type' => 'citizenship', 'id_number' => 'MPLG-STF-' . $n, 'status' => $st,
            'password_hash' => $pw !== null ? Security::hashPassword($pw) : null, 'show_on_website' => 0]);
    };
    $stf       = static fn(int $id): array => Database::fetch('SELECT * FROM staff_applications WHERE id = :i', ['i' => $id]) ?? [];
    $stfDenied = static fn(int $id, string $act): int => (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'staff.credentials_denied' AND entity_id = :e AND new_value LIKE :a",
        ['e' => StaffApps::refFor($id), 'a' => '%' . $act . '%'], 0);
    $s1   = $stfRow('approved', '9000000301', '1', 'Stf#Pass2026');
    $sGet = ['id' => (string) $s1];
    $sh0  = (string) ($stf($s1)['password_hash'] ?? '');
    $sEdit = static fn(array $more): array => ['action' => 'edit', 'id' => $s1] + $more;
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'staff-applications.php', 'get' => $sGet]);
    check('manager: the staff record draws; no set / clear password forms, the note instead', str_contains($o, 'id="login"') && !str_contains($o, 'value="pwset"')
        && !str_contains($o, 'value="pwclear"') && str_contains($o, 'Only a super-admin can set or clear the portal password'), substr(strip_tags($o), 0, 200));
    check('manager: Edit details shows the sign-in mobile + its country disabled (not posted) + the note', preg_match('/name="phone" maxlength="\d+" value="9000000301" inputmode="numeric" disabled>/', $o) === 1
        && str_contains($o, '<select class="inp" name="phone_country" disabled>') && str_contains($o, 'Only a super-admin can change the login mobile'));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'staff-applications.php', 'get' => $sGet, 'post' => ['action' => 'pwset', 'id' => $s1, 'new_password' => 'MgrStf#Pass2026']]);
    check('manager: pwset -> 403, password unchanged, says why, audited', status($o) === 403 && (string) $stf($s1)['password_hash'] === $sh0
        && str_contains($o, 'Only a super-admin can set or clear the portal password. Nothing was changed.') && $stfDenied($s1, 'staff_apps.pwset') === 1, 'status ' . status($o));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'staff-applications.php', 'get' => $sGet, 'post' => ['action' => 'pwclear', 'id' => $s1]]);
    check('manager: pwclear -> 403, password kept, audited', status($o) === 403 && (string) $stf($s1)['password_hash'] === $sh0 && $stfDenied($s1, 'staff_apps.pwclear') === 1, 'status ' . status($o));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'staff-applications.php', 'get' => $sGet, 'post' => $sEdit(['full_name' => 'Mp Stf Person B', 'phone' => '9000000302', 'phone_country' => 'NP'])]);
    $r1 = $stf($s1);
    check('manager: edit with a new mobile + country -> both unchanged (still proven), the name saved, audited', (string) $r1['phone'] === '9000000301' && (string) $r1['phone_country'] === 'IN'
        && (int) $r1['phone_verified'] === 1 && (string) $r1['full_name'] === 'Mp Stf Person B' && $stfDenied($s1, 'staff_apps.edit.phone') === 1,
        json_encode([$r1['phone'] ?? null, $r1['phone_country'] ?? null, $r1['full_name'] ?? null]) . ' status ' . status($o));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'staff-applications.php', 'get' => $sGet, 'post' => $sEdit(['full_name' => 'Mp Stf Person C'])]);
    check('manager: edit with no mobile posted (the disabled box) saves the rest, no new refusal', (string) $stf($s1)['full_name'] === 'Mp Stf Person C' && $stfDenied($s1, 'staff_apps.edit.phone') === 1);
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'staff-applications.php', 'get' => $sGet + ['done' => 'editnoph']]);
    check('...the page after a refused mobile says so (done=editnoph)', str_contains($o, 'Details saved. मोबाइल नम्बर सुपर-एडमिनले मात्र बदल्न मिल्छ · Only a super-admin can change the login mobile — left as it was.'));
    $s2 = $stfRow('pending', '9000000303', '2', null);
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'staff-applications.php', 'get' => ['id' => (string) $s2]]);
    check('manager: onboarding stays - a pending application still offers Approve with a first password', str_contains($o, 'name="do" value="approve"') && str_contains($o, 'name="new_password"'));
    $o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'staff-applications.php', 'get' => $sGet]);
    check('super-admin: set / clear password forms there, the mobile box editable', str_contains($o, 'value="pwset"') && str_contains($o, 'value="pwclear"')
        && preg_match('/name="phone" maxlength="\d+" value="9000000301" inputmode="numeric">/', $o) === 1 && !str_contains($o, 'Only a super-admin can set or clear the portal password'));
    $o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'staff-applications.php', 'get' => $sGet, 'post' => ['action' => 'pwset', 'id' => $s1, 'new_password' => 'SaStf#Pass2026']]);
    check('super-admin: pwset sets the portal password', password_verify('SaStf#Pass2026', (string) $stf($s1)['password_hash']), 'status ' . status($o));
    $o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'staff-applications.php', 'get' => $sGet, 'post' => $sEdit(['full_name' => 'Mp Stf Person C', 'phone' => '9000000304'])]);
    check('super-admin: edit moves the sign-in mobile (marked not proven)', (string) $stf($s1)['phone'] === '9000000304' && (int) $stf($s1)['phone_verified'] === 0, (string) $stf($s1)['phone']);
    $o = child(['what' => 'page', 'admin' => $aSa, 'script' => 'staff-applications.php', 'get' => $sGet, 'post' => ['action' => 'pwclear', 'id' => $s1]]);
    check('super-admin: pwclear clears it', ($stf($s1)['password_hash'] ?? null) === null, 'status ' . status($o));
    $o = child(['what' => 'page', 'admin' => $aMgr, 'script' => 'staff-applications.php', 'get' => $sGet, 'post' => ['action' => 'pwset', 'id' => $s1, 'new_password' => 'MgrStf#Pass2026'],
        'mem' => ['staff_credentials_lock_on' => ['0', 'bool']]]);
    check('staff_credentials_lock_on = 0: a manager sets it again (old rule)', password_verify('MgrStf#Pass2026', (string) $stf($s1)['password_hash']), 'status ' . status($o));
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

/* ---- payment ids / bank keys are a super-admin's (owner plan 8: Payment Management = Admin only) ---- */
require_once INCLUDE_PATH . '/aiops.php';
$superKeys = ['bankapi_webhook_secret', 'bankapi_on', 'bankapi_mode', 'bankmail_app_password', 'bankmail_on', 'pay_bankmail_autoconfirm_on',
              'upi_id', 'esewa_id', 'nepal_esewa_id', 'nepal_esewa_qr', 'india_upi_vpa', 'icici_strict_fields_on', 'role_permissions_override', 'admin_2fa_ids'];
$notSuper = array_values(array_filter($superKeys, static fn(string $k): bool => !AiOps::superOnlySetting($k)));
check('payment ids, bank keys and the Gmail auto switch are super-admin-only settings', $notSuper === [], json_encode($notSuper));
check('  ordinary keys stay a settings manager\'s (company_phone, seller_day_report_on)',
    !AiOps::superOnlySetting('company_phone') && !AiOps::superOnlySetting('seller_day_report_on'));
$srcCo = (string) file_get_contents(dirname(__DIR__) . '/admin/company.php');
$srcOs = (string) file_get_contents(dirname(__DIR__) . '/admin/owner-setup.php');
check('  company.php and owner-setup.php refuse them for a non-super-admin (and owner-setup the Nepal QR upload)',
    str_contains($srcCo, 'AiOps::superOnlySetting($key)') && str_contains($srcOs, 'AiOps::superOnlySetting($k)')
    && str_contains($srcOs, "=== 'esewa_qr' && !Auth::isSuperadmin()"));

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
