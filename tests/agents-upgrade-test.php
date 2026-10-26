<?php
/**
 * tests/agents-upgrade-test.php — 15 Oct 2026 Agent Management upgrade
 * (owner's Feature 5):
 *
 *   5A  Quick add through ONE creation path (AgentWallet::createAgent, used by
 *       admin/agents.php and admin/staff.php); agents are never deleted
 *       (register Delete gone, staff.php delete_agent refuses an agent,
 *       Reject keeps the account switched off, the pending list holds real
 *       applications only, Approve keeps an existing SHG code).
 *   5B  Bulk settlement (batch Mark paid, cash-only drafts with the cash
 *       prefilled), the two-person rule for adjustments
 *       (agent_adjust_two_step, OFF by default), the cash-limit highlight.
 *   5C  The ranking's Cash held column and Low flag, the never-sold
 *       inactivity alert.
 *   +   analytics -> ranking keeps the window, Agent 360 shows cash received,
 *       SmartBook reads the agent's real cash limit.
 *
 *   SHG_DB=lt_f5 php -c .claude/php-dev.ini tests/agents-upgrade-test.php
 *
 * Behaviour runs against the database (it refuses anything that is not a
 * test database: the name must contain "test" or start with "lt_"). The
 * pages run end-to-end in a child PHP with a faked staff session — the
 * tests/render-admin.php technique, with array POST bodies. Every row it
 * writes is removed at the end and every setting it switches is restored.
 * Exit 1 on any failure.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/agentsettlement.php';
require_once INCLUDE_PATH . '/agentreport.php';
require_once INCLUDE_PATH . '/adminalerts.php';

$DBN = (string) DB_NAME;
if (!str_contains($DBN, 'test') && !str_starts_with($DBN, 'lt_')) {
    fwrite(STDERR, "refusing to run against '$DBN' (not a test database)\n");
    exit(2);
}

$PASS = 0; $FAIL = 0;
function check(string $label, bool $ok, string $why = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $label" . ($why !== '' ? "  [$why]" : '') . "\n"; }
}
function throws(callable $fn): string
{
    try { $fn(); } catch (Throwable $e) { return $e->getMessage() !== '' ? $e->getMessage() : 'threw'; }
    return '';
}

$ROOT  = dirname(__DIR__);
$src   = static fn(string $rel): string => (string) @file_get_contents($ROOT . '/' . $rel);
$TAG   = 'zz_ag5_' . bin2hex(random_bytes(3));
$START = date('Y-m-d H:i:s', time() - 2);
$TODAY = todayISO();
$MADE  = [];            // admins.id this test created
$SAVED = [];            // settings rows switched for the run: skey => row|null

/* ------------------------------------------------------------------ helpers */
function mk_admin(string $role, string $label, int $active = 1): int
{
    global $TAG, $MADE;
    $id = Database::insert('admins', [
        'username' => $TAG . '_' . $label, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => 'ZZ ' . $label . ' ' . $TAG, 'role' => $role, 'is_active' => $active, 'must_change_pw' => 0,
    ]);
    $MADE[] = $id;
    return $id;
}
function uname(int $id): string { return (string) Database::scalar('SELECT username FROM admins WHERE id = :i', ['i' => $id], ''); }
function fresh_phone(): string
{
    for ($i = 0; $i < 50; $i++) {
        $p = '79' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        if (!Database::exists('SELECT 1 FROM admins WHERE phone = :p OR username = :u LIMIT 1', ['p' => $p, 'u' => $p])) { return $p; }
    }
    throw new RuntimeException('no free test phone');
}
/** Switch a setting in the DATABASE (child pages read it too); restored at the end. */
function set_db(string $key, string $value, string $type, string $group = 'agent'): void
{
    global $SAVED;
    if (!array_key_exists($key, $SAVED)) {
        $SAVED[$key] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $key]);
    }
    Database::run(
        'INSERT INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES (:k, :v, :t, :g, :l, 0)
         ON DUPLICATE KEY UPDATE svalue = :v2',
        ['k' => $key, 'v' => $value, 't' => $type, 'g' => $group, 'l' => 'test', 'v2' => $value]
    );
    Settings::flush();
}
function bal(int $id): array { return AgentWallet::balances($id); }

/* The child-PHP page runner: one admin page, as one staff member, with GET and
   (array) POST bodies; prints the HTML, the a360 session flash and any fatal. */
$HARNESS = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'shg-ag5-harness-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($HARNESS, <<<'PHPH'
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
$root = (string) getenv('SHG_ROOT');
$page = (string) ($argv[1] ?? '');
$get  = json_decode((string) getenv('SHG_GET_JSON'), true) ?: [];
$post = json_decode((string) getenv('SHG_POST_JSON'), true) ?: [];
$_GET = $get;
$_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
$_SERVER['SCRIPT_NAME']    = '/admin/' . $page;
$_SERVER['QUERY_STRING']   = http_build_query($get);
$_SERVER['REQUEST_URI']    = '/admin/' . $page . ($get !== [] ? '?' . http_build_query($get) : '');
$_SERVER['HTTP_HOST']      = 'localhost';
define('SHG_APP', true);
require_once $root . '/includes/bootstrap.php';
$admin = Database::fetch('SELECT * FROM admins WHERE is_active = 1 AND username = :u', ['u' => (string) getenv('SHG_RENDER_AS')]);
if ($admin === null) { echo 'HARNESS: no such active admin'; exit(3); }
$_SESSION[ADMIN_SESSION_KEY] = [
    'id' => (int) $admin['id'], 'username' => (string) $admin['username'], 'full_name' => (string) ($admin['full_name'] ?? ''),
    'role' => (string) $admin['role'], 'permissions' => jsonColumn($admin['permissions'] ?? null), 'last_seen' => time(),
];
if ($post !== []) {
    $_POST = $post;
    $_POST[CSRF_TOKEN_NAME] = getenv('SHG_BAD_CSRF') ? 'not-the-token' : Security::csrfToken();
}
register_shutdown_function(static function (): void {
    echo "\nHARNESS-FLASH:" . json_encode($_SESSION['shg_a360_flash'] ?? null, JSON_UNESCAPED_UNICODE);
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\nHARNESS-FATAL: " . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line'];
    }
});
require $root . '/admin/' . $page;
PHPH);

function page(string $file, string $as, array $get = [], array $post = [], bool $badCsrf = false): string
{
    global $HARNESS, $ROOT;
    $env = getenv();
    $env['SHG_DB']        = (string) DB_NAME;
    $env['SHG_ROOT']      = $ROOT;
    $env['SHG_RENDER_AS'] = $as;
    $env['SHG_GET_JSON']  = json_encode($get);
    $env['SHG_POST_JSON'] = json_encode($post);
    if ($badCsrf) { $env['SHG_BAD_CSRF'] = '1'; } else { unset($env['SHG_BAD_CSRF']); }
    $errFile = $HARNESS . '.err';
    $cmd = [PHP_BINARY];
    if (($ini = php_ini_loaded_file()) !== false) { array_push($cmd, '-c', $ini); }
    array_push($cmd, $HARNESS, $file);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, $ROOT, $env);
    if (!is_resource($p)) { return 'HARNESS: could not start'; }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($p);
    $err = (string) @file_get_contents($errFile);
    @unlink($errFile);
    Settings::flush();   // the child may have written settings (the agent_codes map)
    return $out . ($err !== '' ? "\nSTDERR:" . $err : '');
}
/** The a360 flash the child left in its session (agent-360.php redirects after a POST). */
function harness_flash(string $out): string
{
    return preg_match('/HARNESS-FLASH:(.*)$/m', $out, $m) === 1 ? (string) (json_decode($m[1], true)[1] ?? '') : '';
}
function fatal_of(string $out): string
{
    return preg_match('/HARNESS-FATAL: (.*)$/m', $out, $m) === 1 ? $m[1] : '';
}

$SUP = Database::fetch("SELECT id, username FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1");
if ($SUP === null) { fwrite(STDERR, "no active superadmin in $DBN\n"); exit(2); }
$SUPID = (int) $SUP['id'];
$SUPU  = (string) $SUP['username'];

try {
    // No WhatsApp leaves a test run, in this process or a child page.
    set_db('agent_notify_enabled', '0', 'bool');
    set_db('agent_adjust_two_step', '0', 'bool');
    set_db('agent_cash_limit_default', '0', 'float');
    set_db('alert_cash_holding_amount', '50000', 'int', 'alerts');
    set_db('agent_target_monthly', '0', 'float');
    set_db('agent_low_perf_pct', '30', 'int');
    Database::run('DELETE FROM rate_limits');

    $M1  = mk_admin('manager', 'm1');      // maker
    $M2  = mk_admin('manager', 'm2');      // second maker
    $M3  = mk_admin('manager', 'm3');      // checker
    $ACC = mk_admin('accountant', 'acc');  // commissions.pay, no customers.view / staff.manage
    $OPS = mk_admin('operations', 'ops');  // staff.manage + commissions.view, no commissions.pay

    /* ============================================================ 0. migration */
    echo "\n=== 0. Migration (additive) ===\n";
    $mig = $src('database/upgrade-2026-10-15-agents.sql');
    check('migration file exists', $mig !== '');
    check('adds one nullable column, guarded', str_contains($mig, 'ADD COLUMN IF NOT EXISTS `adjusted_by` VARCHAR(255) NULL'));
    check('settings through INSERT IGNORE only', str_contains($mig, 'INSERT IGNORE INTO `settings`') && !preg_match('/\b(DROP|DELETE|TRUNCATE|UPDATE)\b/', preg_replace('/^--.*$/m', '', $mig)));
    check('agent_adjust_two_step ships OFF', str_contains($mig, "('agent_adjust_two_step', '0',  'bool'"));
    check('agent_low_perf_pct ships at 30', str_contains($mig, "('agent_low_perf_pct',    '30', 'int'"));
    $stmts = array_filter(array_map('trim', explode(';', (string) preg_replace('/^\s*--.*$/m', '', $mig))));
    check('every statement survives the ;-splitting migration runner (2 statements)', count($stmts) === 2);
    check('agent_settlements.adjusted_by exists (migration applied)', (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_settlements' AND COLUMN_NAME = 'adjusted_by'", [], 0) === 1);
    check('the rule reads OFF', AgentSettlement::twoStepOn() === false);

    /* ============================================================ 1. createAgent */
    echo "\n=== 1. AgentWallet::createAgent — the one creation path ===\n";
    $p1  = fresh_phone();
    $qa1 = AgentWallet::createAgent('ZZ Quick Agent ' . $TAG, $p1, 'person', 'Nepalgunj', '', $SUPID);
    $MADE[] = $qa1;
    $row = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $qa1]) ?? [];
    check('username = the mobile (the agent-signup rule)', ($row['username'] ?? '') === $p1);
    check('role agent, active, never forced to change the password', ($row['role'] ?? '') === 'agent' && (int) ($row['is_active'] ?? 0) === 1 && (int) ($row['must_change_pw'] ?? 1) === 0);
    check('a random password (nothing guessable signs in)', (string) ($row['password_hash'] ?? '') !== '' && !password_verify('', (string) $row['password_hash']) && !password_verify($p1, (string) $row['password_hash']));
    check('no email is fine (optional)', ($row['email'] ?? null) === null);
    $pr = AgentWallet::profile($qa1);
    check('profile: kind, city, display phone', $pr['agent_kind'] === 'person' && $pr['counter_name'] === 'Nepalgunj' && (string) $pr['display_phone'] === $p1);
    $c1 = AgentWallet::agentCodeFor($qa1);
    check('the next free SHG serial in the Person band is issued', $c1 !== null && AgentWallet::codeInBand($c1, 'person'), (string) $c1);
    check('one staff.create audit row', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'staff.create' AND entity_id = :u AND created_at >= :t", ['u' => $p1, 't' => $START], 0) === 1);
    check('the welcome event fired (agent.registered journalled)', Database::exists("SELECT 1 FROM automation_log WHERE event = 'agent.registered' AND data LIKE :d LIMIT 1", ['d' => '%"admin_id":' . $qa1 . '%']));

    check('the same mobile again is refused', str_contains(throws(static fn() => AgentWallet::createAgent('ZZ Dup', $p1, 'org', '', '', $SUPID)), 'mobile'));
    check('a one-letter name is refused', str_contains(throws(static fn() => AgentWallet::createAgent('Z', fresh_phone(), 'org', '', '', $SUPID)), 'name'));
    check('a short mobile is refused', str_contains(throws(static fn() => AgentWallet::createAgent('ZZ Short', '12345', 'org', '', '', $SUPID)), 'mobile'));
    check('a bad email is refused', str_contains(throws(static fn() => AgentWallet::createAgent('ZZ Mail', fresh_phone(), 'org', '', 'not-an-email', $SUPID)), 'email'));
    $mail = $TAG . '@example.com';
    $qa2  = AgentWallet::createAgent('ZZ Mail Agent', fresh_phone(), 'org', '', $mail, $SUPID);
    $MADE[] = $qa2;
    check('a valid email is stored', strtolower((string) Database::scalar('SELECT email FROM admins WHERE id = :i', ['i' => $qa2], '')) === strtolower($mail));
    check('an email used by another account is refused', str_contains(throws(static fn() => AgentWallet::createAgent('ZZ Mail 2', fresh_phone(), 'org', '', strtoupper($mail), $SUPID)), 'email'));
    $c2 = AgentWallet::agentCodeFor($qa2);
    check('an Organization gets an org-band serial (or spills past 20 when full)', $c2 !== null && ($c2 <= AgentWallet::ORG_CODE_MAX || AgentWallet::nextFreeAgentCode('org') >= AgentWallet::PERSON_CODE_MIN));

    $p3 = fresh_phone();
    $squat = Database::insert('admins', ['username' => $p3, 'password_hash' => 'x', 'full_name' => 'ZZ squat ' . $TAG, 'role' => 'counter', 'is_active' => 0]);
    $MADE[] = $squat;
    $qa3 = AgentWallet::createAgent('ZZ Fallback', $p3, 'person', '', null, $SUPID);
    $MADE[] = $qa3;
    check("username taken -> 'agent_<mobile>' (the signup fallback)", uname($qa3) === 'agent_' . $p3);

    $u4  = $TAG . '_typed';
    $qa4 = AgentWallet::createAgent('ZZ Staff Path', '+91 ' . substr(fresh_phone(), 0, 5) . ' ' . random_int(10000, 99999), 'org', '', $TAG . '.staff@example.com', $SUPID,
        ['username' => $u4, 'password' => 'Secret-pass-1', 'contact_person' => 'Hari Contact', 'whatsapp' => '+977 9812345678']);
    $MADE[] = $qa4;
    $r4 = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $qa4]) ?? [];
    check('staff.php path: the typed username and password are kept', ($r4['username'] ?? '') === $u4 && password_verify('Secret-pass-1', (string) ($r4['password_hash'] ?? '')));
    $pr4 = AgentWallet::profile($qa4);
    check('staff.php path: contact person + WhatsApp on the profile', $pr4['contact_person'] === 'Hari Contact' && $pr4['whatsapp'] === '9779812345678');
    check('a typed password under 8 characters is refused', str_contains(throws(static fn() => AgentWallet::createAgent('ZZ Pw', fresh_phone(), 'org', '', null, $SUPID, ['username' => $TAG . '_pw', 'password' => '1234'])), '8'));
    check('a username with a space is refused', str_contains(throws(static fn() => AgentWallet::createAgent('ZZ User', fresh_phone(), 'org', '', null, $SUPID, ['username' => 'a b c'])), 'Username'));

    // The unified release deliberately redirects managed roles to the reviewed digital applications.
    // Creation-service coverage above remains; new approval/CSRF/account-linking coverage runs in unified-team.
    $legacy=$src('admin/agents.php');
    check('managed register redirects to unified team', str_contains($legacy, "Response::redirect('admin/team.php')"));
    $out=page('team.php',$SUPU);
    check('superadmin sees the actual unified application queue', fatal_of($out)==='' && str_contains($out,'Digital applications'), fatal_of($out));
    check('unified page keeps withdrawals and earnings visible', str_contains($out,'Withdrawals') && str_contains($out,'Earnings'));
    $out=page('team.php',uname($M1),[],['action'=>'post_announcement','title'=>'Synthetic CSRF test','body'=>'Must never publish','target_role'=>'all','priority'=>'normal'],true);
    check('active team page refuses bad CSRF without writing', str_contains($out,'Page expired') && !Database::exists("SELECT 1 FROM team_notices WHERE title='Synthetic CSRF test'"));
    check('unified queue cannot Quick Add around KYC approval', !str_contains($src('admin/team.php'), "action==='quick_add'"));

    /* ============================================================ 3. staff.php */
    echo "\n=== 3. admin/staff.php — never delete, real applications only ===\n";
    $APP = mk_admin('agent', 'applicant', 0);
    AgentWallet::saveProfile($APP, ['counter_name' => 'Butwal']);
    $DEACT = mk_admin('agent', 'deactivated', 0);
    AgentWallet::record($DEACT, 'commission', 10, ['note' => 'history', 'by' => 0]);
    $freeCode = AgentWallet::nextFreeAgentCode('person');
    AgentWallet::setAgentCode($DEACT, $freeCode, 0);
    $pendingPanel = static function (string $html): string {
        $a = strpos($html, 'Pending Agent Applications');
        return $a === false ? '' : substr($html, $a, (int) strpos($html, '</table>', $a) - $a);
    };
    $out = page('staff.php', $SUPU);
    $pp  = $pendingPanel($out);
    check('staff.php renders (no fatal)', fatal_of($out) === '', fatal_of($out));
    check('a real application is listed as pending', str_contains($pp, 'ZZ applicant ' . $TAG));
    check('a deactivated agent with a code and wallet history is NOT an application', !str_contains($pp, 'ZZ deactivated ' . $TAG));

    $out = page('staff.php', $SUPU, [], ['action' => 'reject_agent', 'id' => (string) $DEACT]);
    check('Reject on a deactivated agent is refused, code kept', str_contains($out, 'not an application') && AgentWallet::agentCodeFor($DEACT) === $freeCode);
    $out = page('staff.php', $SUPU, [], ['action' => 'reject_agent', 'id' => (string) $APP]);
    $ap  = Database::fetch('SELECT a.is_active, p.suspended_reason, p.suspended_at FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id WHERE a.id = :i', ['i' => $APP]);
    check('Reject keeps the account (never deleted), switched off', $ap !== null && (int) $ap['is_active'] === 0);
    check("…marked 'Application rejected' with the time", $ap !== null && $ap['suspended_reason'] === 'Application rejected' && (string) $ap['suspended_at'] !== '');
    check('…and it leaves the pending list', !str_contains($pendingPanel(page('staff.php', $SUPU)), 'ZZ applicant ' . $TAG));

    $out = page('staff.php', $SUPU, [], ['action' => 'delete_agent', 'id' => (string) $APP]);
    check('delete_agent refuses an agent ("Deactivate instead")', str_contains($out, 'Agents are never deleted') && Database::exists('SELECT 1 FROM admins WHERE id = :i', ['i' => $APP]));
    $clerk = mk_admin('counter', 'clerk', 0);
    page('staff.php', $SUPU, [], ['action' => 'delete_agent', 'id' => (string) $clerk]);
    $clerkGone = !Database::exists('SELECT 1 FROM admins WHERE id = :i', ['i' => $clerk]);
    check('a non-agent staff account with no history can still be deleted (unchanged)', $clerkGone);

    $KEEP = mk_admin('agent', 'keepcode', 0);
    $keepCode = AgentWallet::nextFreeAgentCode('person');
    AgentWallet::setAgentCode($KEEP, $keepCode, 0);
    page('staff.php', $SUPU, [], ['action' => 'approve_agent', 'id' => (string) $KEEP]);
    check('Approve keeps an existing SHG code (no silent re-number)', (int) Database::scalar('SELECT is_active FROM admins WHERE id = :i', ['i' => $KEEP], 0) === 1 && AgentWallet::agentCodeFor($KEEP) === $keepCode);
    $NEWAPP = mk_admin('agent', 'newapp', 0);
    page('staff.php', $SUPU, [], ['action' => 'approve_agent', 'id' => (string) $NEWAPP]);
    check('Approve still issues a code to an applicant with none', AgentWallet::agentCodeFor($NEWAPP) !== null);

    $su  = $TAG . '_sp';
    $out = page('staff.php', $SUPU, [], ['action' => 'create', 'username' => $su, 'full_name' => 'ZZ Staff Created ' . $TAG, 'role' => 'agent', 'password' => 'Another-pass-9',
                                         'phone' => fresh_phone(), 'email' => $su . '@example.com', 'agent_kind' => 'person', 'contact_person' => '', 'whatsapp' => '', 'counter_code' => '']);
    $sc  = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $su], 0);
    if ($sc > 0) { $MADE[] = $sc; }
    check('staff.php agent create goes through createAgent (agent row + code + audit with kind)', $sc > 0 && AgentWallet::agentCodeFor($sc) !== null
        && Database::exists("SELECT 1 FROM audit_logs WHERE action = 'staff.create' AND entity_id = :u AND new_value LIKE '%\"kind\"%'", ['u' => $su]), fatal_of($out));
    check('…and the flash still names the issued code', str_contains($out, 'agent code SHG-'));
    $stf = $src('admin/staff.php');
    check('staff.php: no DELETE left in reject_agent', !str_contains($stf, "DELETE FROM admins WHERE id = :id AND is_active = 0"));
    check('staff.php: both Delete buttons hidden for agents', str_contains($stf, "(string) \$s['role'] !== 'superadmin' && !\$isAgent") && str_contains($stf, "(string) \$s['role'] !== 'superadmin' && (string) \$s['role'] !== 'agent'"));
    check('agents.php: no DELETE FROM admins anywhere', !str_contains($src('admin/agents.php'), 'DELETE FROM admins'));

    /* ============================================================ 4. settlements */
    echo "\n=== 4. Bulk settlement — batch Mark paid, cash-only drafts ===\n";
    $S1 = mk_admin('agent', 's1');      // commission only
    AgentWallet::record($S1, 'commission', 1000, ['note' => 'test credit', 'by' => 0]);
    $S2 = mk_admin('agent', 's2');      // cash only
    AgentWallet::record($S2, 'cash_due', 3000, ['note' => 'test cash', 'by' => 0]);
    $S3 = mk_admin('agent', 's3');      // advance outstanding (commission below zero) + cash
    AgentWallet::record($S3, 'adjustment', 500, ['account' => 'commission', 'direction' => 'debit', 'note' => 'test advance', 'ref' => 'ADJ test', 'by' => 0]);
    AgentWallet::record($S3, 'cash_due', 2000, ['note' => 'test cash', 'by' => 0]);

    $out = page('settlements.php', uname($M1), ['tab' => 'pending']);
    check('pending tab renders', fatal_of($out) === '' && str_contains($out, 'Waiting to be settled'), fatal_of($out));
    check('an agent owed nothing but holding cash can be ticked for a draft', str_contains($out, 'name="agents[]" value="' . $S2 . '"') && str_contains($out, 'name="agents[]" value="' . $S3 . '"'));
    check('over-the-limit cash reads late on the waiting list', str_contains($out, 'over ₹1,000'));

    $out = page('settlements.php', uname($M1), ['tab' => 'pending'], ['action' => 'batch_draft', 'agents' => [(string) $S1, (string) $S2, (string) $S3]]);
    check('batch draft: 3 drafts', str_contains($out, '3 drafts created'), fatal_of($out) ?: substr(strip_tags($out), 0, 200));
    $d = [];
    foreach ([$S1, $S2, $S3] as $sx) {
        $d[$sx] = Database::fetch("SELECT * FROM agent_settlements WHERE agent_admin_id = :a AND status = 'draft'", ['a' => $sx]);
    }
    check('commission draft unchanged: nothing prefilled for cash', $d[$S1] !== null && (float) $d[$S1]['cash_received'] === 0.0 && (float) $d[$S1]['net_amount'] === 1000.0);
    check('cash-only draft prefills the cash held (₹3,000), pays nothing', $d[$S2] !== null && (float) $d[$S2]['cash_received'] === 3000.0 && (float) $d[$S2]['net_amount'] === 0.0);
    check('a draft is still only paper — the ledger has not moved', bal($S1)['commission'] === 1000.0 && bal($S2)['cash'] === 3000.0);

    $ids = array_map(static fn(?array $r): string => (string) (int) ($r['id'] ?? 0), array_values($d));
    page('settlements.php', uname($M2), ['tab' => 'register'], ['action' => 'batch_approve', 'ids' => $ids]);
    check('approved (by a second person)', (int) Database::scalar("SELECT COUNT(*) FROM agent_settlements WHERE id IN (" . implode(',', array_map('intval', $ids)) . ") AND status = 'approved'", [], 0) === 3);

    $out = page('settlements.php', uname($M1), ['tab' => 'register']);
    check('register: approved rows get a tick box and the Mark-paid form', str_contains($out, 'class="st-pay" name="ids[]" form="stPayForm" value="' . $ids[0] . '"') && str_contains($out, 'id="stPayForm"') && str_contains($out, 'value="batch_pay"'));
    check('…which asks before writing the ledger (confirm)', preg_match('/id="stPayForm"[^>]*\R?\s*onsubmit="[^"]*confirm\(/', $out) === 1);
    check('…and never offers "No payment" as the batch mode', preg_match('/id="stPayForm".*?<\/form>/s', $out, $pf) === 1 && !str_contains($pf[0], 'value="adjust"'));

    $out = page('settlements.php', uname($OPS), ['tab' => 'register'], ['action' => 'batch_pay', 'mode' => 'upi', 'ids' => $ids]);
    check('operations (no commissions.pay) cannot batch-pay', str_contains($out, 'commission-payout permission') && bal($S1)['commission'] === 1000.0);
    $out = page('settlements.php', uname($M1), ['tab' => 'register'], ['action' => 'batch_pay', 'mode' => 'adjust', 'ids' => $ids]);
    check('"No payment" is refused as the batch mode', str_contains($out, 'Choose how the agents were paid') && bal($S1)['commission'] === 1000.0);

    $out = page('settlements.php', uname($M1), ['tab' => 'register'], ['action' => 'batch_pay', 'mode' => 'upi', 'ref' => 'ZZB', 'ids' => array_merge($ids, ['999999999'])]);
    check('batch pay: 3 marked paid, the unknown id named', str_contains($out, '3 settlements marked paid') && str_contains($out, '#999999999'), fatal_of($out) ?: substr(strip_tags($out), 0, 300));
    check('S1: commission paid out (UPI, ref ZZB-<no.>)', bal($S1)['commission'] === 0.0
        && Database::exists("SELECT 1 FROM agent_settlements WHERE id = :i AND status = 'paid' AND payment_mode = 'upi' AND payment_ref = :r", ['i' => (int) $ids[0], 'r' => 'ZZB-' . $ids[0]]));
    // review fix: cash really changed hands, so the mode picked (UPI) is kept, not "No payment"
    check('S2: the cash is handed over, recorded with the chosen mode', bal($S2)['cash'] === 0.0
        && Database::exists("SELECT 1 FROM agent_settlements WHERE id = :i AND status = 'paid' AND payment_mode = 'upi'", ['i' => (int) $ids[1]]));
    check('S3: an advance outstanding no longer blocks a cash-only settlement', bal($S3)['cash'] === 0.0 && bal($S3)['commission'] === -500.0);
    $out = page('settlements.php', uname($M1), ['tab' => 'register'], ['action' => 'batch_pay', 'mode' => 'upi', 'ids' => $ids]);
    check('the same batch again pays nothing (no double payout)', str_contains($out, '0 settlements marked paid') && bal($S1)['commission'] === 0.0 && bal($S2)['cash'] === 0.0);
    check('markPaid keeps its row lock and ledger re-check', str_contains($src('includes/agentsettlement.php'), 'FOR UPDATE') && str_contains($src('includes/agentsettlement.php'), 'The ledger changed after approval'));

    /* ============================================================ 5. two-person rule */
    echo "\n=== 5. Two-person rule (agent_adjust_two_step) ===\n";
    // OFF first: today's behaviour.
    $T0 = mk_admin('agent', 't0');
    AgentWallet::record($T0, 'commission', 400, ['note' => 'test credit', 'by' => 0]);
    $off = AgentSettlement::createDraft($T0, $TODAY, $TODAY, [], $M1);
    check('rule OFF: the drafter may approve (unchanged)', throws(static fn() => AgentSettlement::approve($off, $M1)) === '');
    AgentSettlement::cancel($off, $M1, 'test');
    page('agent-360.php', uname($M1), ['agent' => (string) $T0], ['action' => 'comm_adjust', 'agent_id' => (string) $T0, 'tab' => 'report', 'direction' => 'debit', 'amount' => '100', 'note' => 'desk shortfall']);
    check('rule OFF: Agent 360 add / deduct writes the ledger at once (unchanged)', bal($T0)['commission'] === 300.0);

    set_db('agent_adjust_two_step', '1', 'bool');
    check('the rule reads ON', AgentSettlement::twoStepOn());
    $T1 = mk_admin('agent', 't1');
    AgentWallet::record($T1, 'commission', 1000, ['note' => 'test credit', 'by' => 0]);
    $st1 = AgentSettlement::stageAdjustment($T1, 'debit', 200, 'short cash at the desk', $M1);
    $dr  = AgentSettlement::get($st1['id']) ?? [];
    check('a deduction is staged on a NEW draft, the ledger untouched', $st1['created'] && ($dr['status'] ?? '') === 'draft' && bal($T1)['commission'] === 1000.0);
    check('…as one deductions line with its reason', AgentSettlement::deductionsOf($dr) === [['label' => 'short cash at the desk', 'amount' => 200.0]]);
    $st2 = AgentSettlement::stageAdjustment($T1, 'credit', 150, 'monthly target bonus', $M2);
    $dr  = AgentSettlement::get($st1['id']) ?? [];
    check('an addition lands on the SAME draft as bonus, with its reason', !$st2['created'] && $st2['id'] === $st1['id'] && (float) $dr['bonus'] === 150.0 && str_contains((string) $dr['bonus_note'], 'monthly target bonus'));
    check('…and the second person is recorded as a maker', in_array($M2, AgentSettlement::makersOf($dr), true) && in_array($M1, AgentSettlement::makersOf($dr), true));
    check('the drafter cannot approve', str_contains(throws(static fn() => AgentSettlement::approve($st1['id'], $M1)), 'Two-person rule'));
    check('the person who added money cannot approve either', str_contains(throws(static fn() => AgentSettlement::approve($st1['id'], $M2)), 'Two-person rule'));
    check('a third person approves', throws(static fn() => AgentSettlement::approve($st1['id'], $M3)) === '' && (AgentSettlement::get($st1['id'])['status'] ?? '') === 'approved');
    AgentSettlement::markPaid($st1['id'], 'upi', '', $M3);
    check('Mark paid writes it: 1000 − 200 + 150 paid out = 0 owed', bal($T1)['commission'] === 0.0);
    check('…the deduction and the bonus are ADJ ledger lines', (int) Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'adjustment' AND ref LIKE 'ADJ ST-%'", ['a' => $T1], 0) === 2);
    check('a deduction above the commission due is refused (and leaves no draft)',
        str_contains(throws(static fn() => AgentSettlement::stageAdjustment($T1, 'debit', 50, 'another short one', $M1)), 'more than the commission due')
        && !Database::exists("SELECT 1 FROM agent_settlements WHERE agent_admin_id = :a AND status = 'draft'", ['a' => $T1]));
    AgentWallet::record($T1, 'commission', 300, ['note' => 'test credit 2', 'by' => 0]);
    $ap1 = AgentSettlement::createDraft($T1, $TODAY, $TODAY, [], $M1);
    AgentSettlement::approve($ap1, $M2);
    check('an approved settlement waiting to be paid blocks staging', str_contains(throws(static fn() => AgentSettlement::stageAdjustment($T1, 'credit', 10, 'small fix here', $M3)), 'waiting to be paid'));
    AgentSettlement::cancel($ap1, $M3, 'test');
    $sa = AgentSettlement::createDraft($T1, $TODAY, $TODAY, [], $SUPID);
    check('a super-admin is exempt (owner)', throws(static fn() => AgentSettlement::approve($sa, $SUPID)) === '');
    AgentSettlement::cancel($sa, $SUPID, 'test');
    check('a reason under 5 characters is refused', str_contains(throws(static fn() => AgentSettlement::stageAdjustment($T1, 'credit', 10, 'abc', $M1)), '5 characters'));
    check('a zero amount is refused', str_contains(throws(static fn() => AgentSettlement::stageAdjustment($T1, 'credit', 0, 'zero amount', $M1)), 'above zero'));
    $ed = AgentSettlement::createDraft($T1, $TODAY, $TODAY, [], $M1);
    AgentSettlement::updateDraft($ed, ['bonus' => 25, 'bonus_note' => 'typed on the draft'], $M2);
    check('changing a draft\'s money on the Settlements page also makes you a maker', str_contains(throws(static fn() => AgentSettlement::approve($ed, $M2)), 'Two-person rule'));
    AgentSettlement::updateDraft($ed, [], $M3);
    check('a plain re-read of the ledger does not', throws(static fn() => AgentSettlement::approve($ed, $M3)) === '');
    AgentSettlement::cancel($ed, $M3, 'test');

    // The pages, rule ON.
    $T2 = mk_admin('agent', 't2');
    AgentWallet::record($T2, 'commission', 500, ['note' => 'test credit', 'by' => 0]);
    AgentWallet::record($T2, 'cash_due', 700, ['note' => 'test cash', 'by' => 0]);
    $out = page('agent-360.php', uname($M1), ['agent' => (string) $T2], ['action' => 'comm_adjust', 'agent_id' => (string) $T2, 'tab' => 'report', 'direction' => 'debit', 'amount' => '100', 'note' => 'desk shortfall']);
    check('Agent 360 (manager): the deduction is staged, not written', bal($T2)['commission'] === 500.0 && str_contains(harness_flash($out), 'staged'), harness_flash($out) ?: fatal_of($out));
    check('…onto a draft with that deduction', ($dd = Database::fetch("SELECT * FROM agent_settlements WHERE agent_admin_id = :a AND status = 'draft'", ['a' => $T2])) !== null
        && (float) $dd['deductions_total'] === 100.0 && (int) $dd['created_by'] === $M1);
    $out = page('agent.php', uname($M1), ['agent' => (string) $T2], ['action' => 'adjustment', 'agent_id' => (string) $T2, 'account' => 'cash', 'direction' => 'debit', 'amount' => '50', 'note' => 'cash miscount fix']);
    check('agent.php (manager): a cash-account correction needs a super-admin', str_contains($out, 'Two-person rule is on: a cash-account correction needs a super-admin.') && bal($T2)['cash'] === 700.0, fatal_of($out));
    check('…and the correction form says so while the rule is on', str_contains($out, '<b>Two-person rule is on:</b> a commission correction goes onto'));
    $out = page('agent.php', uname($M1), ['agent' => (string) $T2], ['action' => 'adjustment', 'agent_id' => (string) $T2, 'account' => 'commission', 'direction' => 'credit', 'amount' => '40', 'note' => 'late bonus for sept']);
    check('agent.php (manager): a commission correction is staged on the same draft', bal($T2)['commission'] === 500.0
        && (float) Database::scalar("SELECT bonus FROM agent_settlements WHERE agent_admin_id = :a AND status = 'draft'", ['a' => $T2], 0) === 40.0, fatal_of($out));
    $out = page('agent.php', $SUPU, ['agent' => (string) $T2], ['action' => 'adjustment', 'agent_id' => (string) $T2, 'account' => 'cash', 'direction' => 'debit', 'amount' => '50', 'note' => 'cash miscount fix']);
    check('a super-admin still corrects directly (cash ₹700 → ₹650)', bal($T2)['cash'] === 650.0, fatal_of($out));
    $out = page('settlements.php', uname($M1), [], ['action' => 'approve', 'id' => (string) (int) $dd['id']]);
    check('Settlements page: the maker\'s Approve is refused with the reason', str_contains($out, 'Two-person rule') && (AgentSettlement::get((int) $dd['id'])['status'] ?? '') === 'draft');
    set_db('agent_adjust_two_step', '0', 'bool');

    /* ============================================================ 6. cash limit */
    echo "\n=== 6. The cash limit every list reads ===\n";
    check('own profile limit wins', AgentWallet::effectiveCashLimit(5000.0) === 5000.0);
    set_db('agent_cash_limit_default', '8000', 'float');
    check('else the company default (agent_cash_limit_default)', AgentWallet::effectiveCashLimit(0.0) === 8000.0);
    set_db('agent_cash_limit_default', '0', 'float');
    set_db('alert_cash_holding_amount', '12000', 'int', 'alerts');
    check('else the cash alert\'s amount (Admin → Notifications)', AgentWallet::effectiveCashLimit(0.0) === 12000.0);
    $lm = AgentWallet::cashLimitMap([$X, $S1, 0, -4]);
    check('cashLimitMap: one read for many, junk ids dropped', $lm === [$X => 1000.0, $S1 => 12000.0]);
    set_db('alert_cash_holding_amount', '0', 'int', 'alerts');
    check('0 everywhere = no limit', AgentWallet::effectiveCashLimit(0.0) === 0.0);
    set_db('alert_cash_holding_amount', '50000', 'int', 'alerts');
    $sb = $src('admin/smartbook.php');
    check('SmartBook reads the agent\'s own limit, not the dead agent_cash_limit_inr', str_contains($sb, "\$limit  = (float) AgentWallet::profile(\$me)['cash_limit'];") && !str_contains($sb, "Settings::getFloat('agent_cash_limit_inr'"));
    check('the register reads the ledger balances (not the zero defaults)', str_contains($src('admin/agents.php'), "\$bal          = (\$balMap[\$id] ?? []) + ['commission' => 0.0, 'cash' => 0.0];"));

    /* ============================================================ 7. ranking */
    echo "\n=== 7. admin/agent-ranking.php — Cash held, Low flag ===\n";
    $L1 = mk_admin('agent', 'lowold');
    Database::run('UPDATE admins SET created_at = :c WHERE id = :i', ['c' => date('Y-m-d H:i:s', strtotime('-60 days')), 'i' => $L1]);
    $L2 = mk_admin('agent', 'lownew');
    $out = page('agent-ranking.php', $SUPU, ['range' => 'month', 'low' => '1']);
    check('ranking renders with a Cash held column', fatal_of($out) === '' && str_contains($out, '<th>Cash held</th>'), fatal_of($out));
    check('an active agent who never sold (older than the inactivity days) is Low', str_contains($out, 'ZZ lowold ' . $TAG) && str_contains($out, 'Low: has never sold'));
    check('a brand-new agent gets the same grace as the bell', !str_contains($out, 'ZZ lownew ' . $TAG));
    check('"Low performers only" hides everyone else', !str_contains($out, 'ZZ s1 ' . $TAG));
    /* 15 Oct 2026: with no sale at all in the window the board replaces its rows
       with "No sales in this window — try a wider range", and nothing in this
       suite sells. "Low performers only" still lists its rows, and Cash held is
       the balance NOW whatever the window, so the red marker is read there. */
    $out = page('agent-ranking.php', $SUPU, ['range' => 'month', 'low' => '1']);
    check('cash over the limit is red on the board', (bool) preg_match('/ZZ overcash ' . preg_quote($TAG, '/') . '.*?over ₹1,000/s', $out), $out === '' ? 'empty page' : 'overcash row ' . (str_contains($out, 'ZZ overcash ' . $TAG) ? 'present' : 'MISSING'));
    set_db('agent_target_monthly', '100000', 'float');
    $out = page('agent-ranking.php', $SUPU, ['range' => 'month', 'low' => '1']);
    check('with a monthly target set, below the target share reads Low', str_contains($out, 'below 30% of the target'));
    set_db('agent_target_monthly', '0', 'float');

    /* ============================================================ 8. inactivity alert */
    echo "\n=== 8. Inactivity bell catches never-sold agents ===\n";
    $only = ['advance_limit' => ['on' => false], 'settlement_due' => ['on' => false], 'cash_holding' => ['on' => false],
             'low_bookings' => ['on' => false], 'cancel_spike' => ['on' => false], 'agent_inactive' => ['on' => true, 'days' => 7]];
    $idleN = static function () use ($only): int {
        foreach (AdminAlerts::evaluate($only, false) as $a) {
            if ($a['type'] === 'agent_inactive') { return (int) $a['title']; }
        }
        return 0;
    };
    $n0 = $idleN();
    $L3 = mk_admin('agent', 'idleold');
    Database::run('UPDATE admins SET created_at = :c WHERE id = :i', ['c' => date('Y-m-d H:i:s', strtotime('-30 days')), 'i' => $L3]);
    check('an active agent who never sold, 30 days old, is now counted', $idleN() === $n0 + 1, $n0 . ' -> ' . $idleN());
    mk_admin('agent', 'idlenew');
    check('one created today is not', $idleN() === $n0 + 1);
    check('the alert query binds each placeholder once (:cut1 / :cut2)', str_contains($src('includes/adminalerts.php'), "['cut1' => \$cut, 'cut2' => \$cut]"));

    /* ============================================================ 9. small fixes */
    echo "\n=== 9. Small fixes ===\n";
    check('analytics → Agent ranking keeps the window (range=custom)', str_contains($src('admin/analytics.php'), '/admin/agent-ranking.php?range=custom&amp;from='));
    $rep = AgentReport::build($S2, ['from' => $TODAY, 'to' => addDaysISO($TODAY, 1), 'basis' => 'sale']);
    check('AgentReport counts the cash received in the window (₹3,000 from S2)', (float) ($rep['totals']['cash_in'] ?? 0) === 3000.0);
    $out = page('agent-360.php', $SUPU, ['agent' => (string) $S2, 'tab' => 'report', 'range' => 'today', 'basis' => 'sale']);
    check('Agent 360 Report shows "Cash received" for the period', fatal_of($out) === '' && str_contains($out, 'Cash received') && str_contains($out, '₹3,000'), fatal_of($out));
} catch (Throwable $e) {
    check('no unexpected exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    /* ------------------------------------------------------------ cleanup */
    @unlink($HARNESS);
    $ids = array_values(array_unique(array_filter(array_map('intval', $MADE))));
    if ($ids !== []) {
        $in    = implode(',', $ids);
        $names = array_map(static fn(array $r): string => (string) $r['username'], Database::fetchAll("SELECT username FROM admins WHERE id IN ($in)"));
        $setl  = array_map('intval', array_column(Database::fetchAll("SELECT id FROM agent_settlements WHERE agent_admin_id IN ($in)"), 'id'));
        foreach ($ids as $aid) {
            try { AgentWallet::setAgentCode($aid, null, 0); } catch (Throwable $e) {}
        }
        try { Database::run("DELETE FROM agent_payout_requests WHERE agent_admin_id IN ($in)"); } catch (Throwable $e) {}
        Database::run("DELETE FROM agent_settlements WHERE agent_admin_id IN ($in)");
        Database::run("DELETE FROM agent_ledger WHERE agent_admin_id IN ($in)");
        Database::run("DELETE FROM admin_profiles WHERE admin_id IN ($in)");
        $keys = array_merge(array_map('strval', $ids), $names);
        foreach (array_chunk($keys, 50) as $chunk) {
            $ph = []; $pv = ['t' => $START];
            foreach ($chunk as $i => $v) { $ph[] = ':k' . $i; $pv['k' . $i] = $v; }
            Database::run('DELETE FROM audit_logs WHERE created_at >= :t AND entity_id IN (' . implode(',', $ph) . ')', $pv);
        }
        if ($setl !== []) {
            Database::run("DELETE FROM audit_logs WHERE entity_type = 'settlement' AND entity_id IN ('" . implode("','", $setl) . "')");
        }
        // Rows about accounts already gone (the deleted clerk) carry the tag in their username.
        Database::run('DELETE FROM audit_logs WHERE created_at >= :t AND entity_id LIKE :g', ['t' => $START, 'g' => $TAG . '%']);
        Database::run("DELETE FROM automation_log WHERE event = 'agent.registered' AND created_at >= :t", ['t' => $START]);
        Database::run("DELETE FROM admins WHERE id IN ($in)");
    }
    foreach ($SAVED as $key => $rowS) {
        if ($rowS === null) {
            Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $key]);
        } else {
            Database::run('UPDATE settings SET svalue = :v, stype = :t, sgroup = :g WHERE skey = :k',
                ['v' => $rowS['svalue'], 't' => $rowS['stype'], 'g' => $rowS['sgroup'], 'k' => $key]);
        }
    }
    Settings::flush();
    $left = $ids === [] ? 0 : (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE id IN (' . implode(',', $ids) . ')', [], 0);
    check('cleanup: every row this test wrote is gone, settings restored', $left === 0);
}

echo "\n" . ($FAIL === 0 ? "\033[32m" : "\033[31m") . "$PASS passed, $FAIL failed\033[0m\n";
exit($FAIL === 0 ? 0 : 1);
