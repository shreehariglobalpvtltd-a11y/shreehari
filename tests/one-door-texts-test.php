<?php
/**
 * =====================================================================
 *  one-door-texts-test.php — 31 Oct 2026. The sign-in TEXTS follow the
 *  one staff door, and the Control Center (admin/hub.php) shows each
 *  viewer only the figures they may see. Against the TEST database;
 *  everything it makes is deleted again. No WhatsApp leaves the machine:
 *  whatsapp_driver = click_to_chat, the text is read back from the
 *  journal's wa.me link.
 *
 *    A. Notify::agentCredentials / agentWelcome — admin/login.php,
 *       "mobile or ID", WhatsApp hi / code / password; no /team/, no
 *       ?portal=agent, no "email + username"; the password line keeps
 *       its shape and is masked in message_logs; switch off = old text
 *    B. WaLogin replies — the one door link instead of ?portal=agent
 *    C. admin/hub.php rendered for agent / counter / manager with a
 *       hand-built session: an agent sees only their own sales, the
 *       counter and the manager the company, a desk-isolated counter
 *       its desk; no office queue reaches an agent
 *
 *    php -c .claude/php-dev.ini tests/one-door-texts-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: render admin/hub.php for one account with a hand-built session ---- */
if (($argv[1] ?? '') === '--render-hub') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME']    = '/admin/hub.php';
    $_SERVER['REQUEST_URI']    = '/admin/hub.php';
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
    require dirname(__DIR__) . '/admin/hub.php';
    exit;
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/walogin.php';
require_once INCLUDE_PATH . '/counterdesk.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function section(string $t): void { echo "\n-- $t --\n"; }

const ODT_PHONE = '7000000901';
const ODT_PNR   = 'ODTX';

/* ---- settings: kept and put back, even when the run dies half-way ---- */
$keep = [];
foreach (['whatsapp_driver' => ['click_to_chat', 'string'], 'login_one_door_on' => ['1', 'bool'], 'agent_notify_enabled' => ['1', 'bool'],
          'counter_desk_isolation' => ['0', 'bool'], 'wa_login_on' => ['0', 'bool']] as $k => [$v, $type]) {
    $keep[$k] = Database::fetch('SELECT svalue, stype FROM settings WHERE skey = :k', ['k' => $k]);
    Settings::set($k, $v, $type, 'security', false);
}
Settings::flush();
$restored = false;
$cleanup = static function (): void {
    try { Database::run("DELETE FROM bookings WHERE pnr LIKE 'ODTX%'"); } catch (Throwable $e) {}
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'odt\\_%'") as $r) {
        foreach (['admin_profiles' => 'admin_id', 'admin_sessions' => 'admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => (int) $r['id']]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => (int) $r['id']]);
    }
    try { Database::run("DELETE FROM message_logs WHERE to_number LIKE :p", ['p' => '%' . ODT_PHONE . '%']); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM message_logs WHERE to_number LIKE :p", ['p' => '%7000000903%']); } catch (Throwable $e) {}
};
$restore = static function () use (&$keep, &$restored, $cleanup): void {
    if ($restored) { return; }
    $restored = true;
    $cleanup();
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
$cleanup();

/** The text of the newest journal entry (the wa.me ?text= link). */
$lastText = static function (): string {
    $j = Notify::whatsappJournal();
    $last = $j !== [] ? $j[count($j) - 1] : null;
    $link = (string) ($last['link'] ?? '');
    $q = (string) parse_url($link, PHP_URL_QUERY);
    parse_str($q, $p);
    return (string) ($p['text'] ?? '');
};
$door = appUrl('admin/login.php');

$mk = static function (string $user, string $role, string $phone): int {
    return (int) Database::insert('admins', [
        'username' => $user, 'full_name' => 'ODT ' . ucfirst($role), 'role' => $role, 'phone' => $phone,
        'password_hash' => password_hash('x' . bin2hex(random_bytes(6)), PASSWORD_DEFAULT), 'is_active' => 1,
    ]);
};
$AG  = $mk('odt_agent', 'agent', ODT_PHONE);
$CT  = $mk('odt_counter', 'counter', '7000000902');
$MG  = $mk('odt_mgr', 'manager', '7000000903');
$AG2 = $mk('odt_agent2', 'agent', '7000000904');

/* =================================================================== */
section('A. credential + welcome texts (one door on)');
$pw = 'Tmp#Door4821';
$r = Notify::agentCredentials(0, 'ODT Staff', ODT_PHONE, ODT_PHONE, $pw, 'staff');
$t = $lastText();
check('credentials went to the click-to-chat link (nothing sent)', is_string($r) && str_contains($r, 'wa.me/'));
check('credentials: the one door link', str_contains($t, $door) && !str_contains($t, '?portal=agent'), $door);
check('credentials: no /team/ sign-in link', !str_contains($t, appUrl('team/')));
check('credentials: "mobile or ID" + the mobile', str_contains($t, 'Mobile or ID: ' . ODT_PHONE) && str_contains($t, 'मोबाइल वा ID'));
check('credentials: WhatsApp hi / code / password explained', str_contains($t, '6-digit WhatsApp code') && str_contains($t, 'password'));
check('credentials: 2-3 IDs on one mobile explained', str_contains($t, '2-3 IDs') && str_contains($t, '२-३ ID'));
check('credentials: the temporary password line is kept', str_contains($t, '🔑 Password: ' . $pw));
check('credentials: no "Username" label', !str_contains($t, 'Username'));
$row = Database::fetch("SELECT body FROM message_logs WHERE to_number LIKE :p ORDER BY id DESC LIMIT 1", ['p' => '%' . ODT_PHONE . '%']);
check('credentials: the password is masked in message_logs', $row !== null && !str_contains((string) $row['body'], $pw));
/* 31 Oct 2026 (review): a staff mobile with no member account (agent / counter / scanner) opens with the password only. */
Notify::agentCredentials(0, 'ODT Staff', '7000000903', '7000000903', $pw, 'staff');
$t = $lastText();
check('staff with no member login: password only, no WhatsApp hi / code', str_contains($t, 'then your password') && !str_contains($t, '6-digit WhatsApp code') && !str_contains($t, '"hi"'));
check('staff with no member login: the password line is kept', str_contains($t, '🔑 Password: ' . $pw));

Notify::agentWelcome($AG, 'ODT Agent', ODT_PHONE);
$t = $lastText();
check('welcome: the one door link', str_contains($t, $door) && !str_contains($t, '?portal=agent'));
check('welcome: no "email, username and password"', !str_contains($t, 'इमेल, युजरनेम'));
check('welcome: mobile or ID, Nepali + English', str_contains($t, 'मोबाइल नम्बर वा ID') && str_contains($t, 'mobile number or ID'));
require_once INCLUDE_PATH . '/wasend.php';
check('welcome: not mistaken for a secret', !WaSend::isSecret('message', $t));

section('A2. switch off = the old texts');
Settings::set('login_one_door_on', '0', 'bool', 'security', false); Settings::flush();
Notify::agentCredentials(0, 'ODT Staff', ODT_PHONE, ODT_PHONE, $pw, 'staff');
$t = $lastText();
check('off: credentials keep /team/ + Username', str_contains($t, appUrl('team/')) && str_contains($t, 'Username: ' . ODT_PHONE));
Notify::agentWelcome($AG, 'ODT Agent', ODT_PHONE);
check('off: welcome keeps the agent portal', str_contains($lastText(), '?portal=agent'));
check('off: WaLogin keeps the agent portal', str_contains((string) (WaLogin::handle('+91' . ODT_PHONE, 'login SHG-1 x')['text'] ?? ''), '?portal=agent'));
Settings::set('login_one_door_on', '1', 'bool', 'security', false); Settings::flush();

/* =================================================================== */
section('B. WaLogin replies');
$out = WaLogin::handle('+91' . ODT_PHONE, 'login SHG-1 secretpw');
$txt = (string) ($out['text'] ?? '');
check('switched-off reply points to the one door', str_contains($txt, $door) && !str_contains($txt, '?portal=agent'), mb_substr($txt, 0, 90));
$help = (new ReflectionMethod(WaLogin::class, 'help'))->invoke(null);
check('help names the website door', str_contains((string) $help['text'], $door));
$src = (string) file_get_contents(INCLUDE_PATH . '/walogin.php');
check('no ?portal=agent left outside the switch-off branch', substr_count($src, "portal=agent") === 1);

/* =================================================================== */
section('B2. staff approval + sign-in lines');
require_once INCLUDE_PATH . '/staffapplications.php';
$appr = StaffApps::waText('approved', ['id' => 1, 'full_name' => 'Ram', 'role_applied' => 'driver']);
check('staff approved: one door link', str_contains($appr, appUrl('admin/login.php?back=team')) && !str_contains($appr, appUrl('team/') . ' '));
check('staff approved: first password via Forgot + WhatsApp code', str_contains($appr, 'Forgot password?') && str_contains($appr, 'WhatsApp'));
Settings::set('login_one_door_on', '0', 'bool', 'security', false); Settings::flush();
$apprOff = StaffApps::waText('approved', ['id' => 1, 'full_name' => 'Ram', 'role_applied' => 'driver']);
check('switch off: staff approved keeps /team/', str_contains($apprOff, appUrl('team/')) && !str_contains($apprOff, 'back=team'));
Settings::set('login_one_door_on', '1', 'bool', 'security', false); Settings::flush();
$sl = implode("\n", Notify::oneDoorSignInLines(true));
check('sign-in lines are public + one door', str_contains($sl, appUrl('admin/login.php')) && !str_contains($sl, 'portal=agent'));

section('C. admin/hub.php figures per viewer');
$ins = static function (string $pnr, string $status, int $soldBy, string $desk = ''): void {
    $row = ['pnr' => $pnr, 'contact_phone' => '7000000999', 'status' => $status, 'sold_by_admin_id' => $soldBy, 'created_at' => date('Y-m-d H:i:s')];
    if ($desk !== '' && CounterDesk::stampColumn()) { $row['counter_code'] = $desk; }
    Database::insert('bookings', $row);
};
$ins(ODT_PNR . '1', 'confirmed', $AG);
$ins(ODT_PNR . '2', 'confirmed', $AG);
$ins(ODT_PNR . '3', 'pending', $AG);
$ins(ODT_PNR . '4', 'confirmed', $AG2, 'ODTD');
$ins(ODT_PNR . '5', 'pending', $AG2);
$company = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','completed') AND DATE(created_at) = CURDATE()");
$companyPend = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE status = 'pending'");

$render = static function (int $id): string {
    $ini = (string) php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' --render-hub ' . $id . ' 2>&1';
    return (string) shell_exec($cmd);
};
$kpis = static function (string $html): array {
    preg_match_all('~<a class="cc-kpi[^"]*" href="[^"]*"><small>([^<]*)</small><b>(\d+)</b></a>~u', $html, $m, PREG_SET_ORDER);
    $o = [];
    foreach ($m as $x) { $o[html_entity_decode($x[1], ENT_QUOTES)] = (int) $x[2]; }
    return $o;
};
$clean = static fn(string $h): bool => preg_match('/Application error|Warning:|Notice:|Deprecated:|Fatal error|Uncaught/i', $h) !== 1;

$h = $render($AG);
$k = $kpis($h);
check('agent: hub renders clean', $clean($h) && str_contains($h, 'cc-kpis'), $clean($h) ? strlen($h) . ' bytes' : mb_substr(strip_tags($h), 0, 300));
check('agent: "My tickets today" = own sales only (2)', ($k['My tickets today · मेरो आजको टिकट'] ?? -1) === 2, json_encode($k, JSON_UNESCAPED_UNICODE));
check('agent: the company count is not shown', !isset($k['Tickets today']) && $company > 2, 'company ' . $company);
check('agent: no office queue / company figure', array_diff(array_keys($k), ['My tickets today · मेरो आजको टिकट', 'Live buses']) === [], implode(', ', array_keys($k)));
check('agent: no "needs you" office list', !str_contains($h, 'cc-attn'));

$h = $render($CT);
$k = $kpis($h);
check('counter: hub renders clean', $clean($h) && str_contains($h, 'cc-kpis'));
check('counter: company tickets today', ($k['Tickets today'] ?? -1) === $company, json_encode($k, JSON_UNESCAPED_UNICODE));
check('counter: company payments to verify', ($k['Payments to verify'] ?? -1) === $companyPend);

$h = $render($MG);
$k = $kpis($h);
check('manager: hub renders clean', $clean($h) && str_contains($h, 'cc-kpis'));
check('manager: company tickets today', ($k['Tickets today'] ?? -1) === $company, json_encode($k, JSON_UNESCAPED_UNICODE));

if (CounterDesk::stampColumn()) {
    Database::insert('admin_profiles', ['admin_id' => $CT, 'counter_code' => 'ODTD']);
    Settings::set('counter_desk_isolation', '1', 'bool', 'security', false); Settings::flush();
    $h = $render($CT);
    $k = $kpis($h);
    check('desk-isolated counter: only the desk (1)', ($k['Desk tickets today · काउन्टरको आजको टिकट'] ?? -1) === 1, json_encode($k, JSON_UNESCAPED_UNICODE));
    $h = $render($MG);
    check('desk isolation never narrows the manager', ($kpis($h)['Tickets today'] ?? -1) === $company);
    Settings::set('counter_desk_isolation', '0', 'bool', 'security', false); Settings::flush();
} else {
    echo "  (bookings.counter_code missing — desk part skipped)\n";
}

$restore();
echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
