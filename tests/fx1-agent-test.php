<?php
/**
 * =====================================================================
 *  fx1-agent-test.php — agent mode and sub-agents (26 Nov 2026, fx1).
 *
 *  Throw-away rows only (username 'fx1ag-…-<tag>', PNR 'FX1…<tag>'), cleaned
 *  up by their own ids; the settings this suite touches are restored. Pages
 *  are driven for real: the suite re-invokes itself as a child process
 *  (--hit), which signs one seeded person in by hand ($_SESSION, the way
 *  tests/op5-credit-test.php does), fills $_GET / $_POST, includes the page
 *  and hands back status, body and the agent-panel flash. No server, no
 *  browser, no network.
 *
 *   A  read isolation   agent A opening agent B (and a sub-agent its main agent, and back)
 *                       by id on every agent page: home, panel, ledger CSV, sales,
 *                       passengers, statement (+PDF), receipt, settlements
 *   B  POST isolation   A posting B's id at the agent panel: profile, cash handover,
 *                       payout, counter-staff pause, counter-staff cash
 *   C  sub-agent rules  who may add / pause / switch on / take cash; new login starts
 *                       paused (switch); a held main agent stops the sub-agent (switch)
 *   D  home figures     AgentReport::home() = statement = wallet = credit, to the rupee,
 *                       and the page prints exactly those; NPR on the ticket lines
 *   E  lists            "KYC बाँकी" marker, verified first (switch), NULL profile = not verified
 *   F  wiring           switches, migration, super-admin gate, no invented company details
 *
 *    php -c .claude/php-dev.ini tests/fx1-agent-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT = dirname(__DIR__);

/* =====================================================================
 *  Child mode: render ONE page as ONE signed-in person.
 *  argv: --hit <base64 json {as:int, page:string, get:array, post:array|null}>
 * ===================================================================== */
if (($argv[1] ?? '') === '--hit') {
    $spec = json_decode((string) base64_decode((string) ($argv[2] ?? ''), true), true);
    if (!is_array($spec)) { fwrite(STDERR, "bad spec\n"); exit(2); }
    ob_start();
    require_once $ROOT . '/includes/bootstrap.php';
    if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { ob_end_clean(); echo "@@FX1@@" . base64_encode(json_encode(['skip' => true])) . "@@"; exit(0); }

    $page = (string) $spec['page'];
    if (preg_match('#^admin/[a-z0-9\-]+\.php$#', $page) !== 1 || !is_file($ROOT . '/' . $page)) { fwrite(STDERR, "bad page\n"); exit(2); }
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) $spec['as']]);
    if ($a === null) { fwrite(STDERR, "no such admin\n"); exit(2); }
    // No 'checked_at': Auth::admin() would re-validate the row against a password stamp.
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
        'must_change_pw' => false, 'via_otp' => false,
    ];
    // 'nokyc': act as a database without admin_profiles.kyc_status (the cached probe says "no").
    if (!empty($spec['nokyc'])) {
        require_once $ROOT . '/includes/agentwallet.php';
        $rp = new ReflectionProperty('AgentWallet', 'kycAvailable');
        $rp->setAccessible(true);
        $rp->setValue(null, false);
    }
    $_GET  = is_array($spec['get'] ?? null) ? $spec['get'] : [];
    $_POST = is_array($spec['post'] ?? null) ? $spec['post'] : [];
    $isPost = is_array($spec['post'] ?? null);
    if ($isPost) { $_POST[CSRF_TOKEN_NAME] = Security::csrfToken(); }
    $_REQUEST = $_GET + $_POST;
    $_SERVER['REQUEST_METHOD'] = $isPost ? 'POST' : 'GET';
    $_SERVER['REQUEST_URI']    = '/' . $page . ($_GET !== [] ? '?' . http_build_query($_GET) : '');
    $_SERVER['SCRIPT_NAME']    = '/' . $page;
    $_SERVER['PHP_SELF']       = '/' . $page;
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['SERVER_NAME']    = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'fx1-agent-test';

    register_shutdown_function(static function (): void {
        $body = '';
        while (ob_get_level() > 0) { $body = (string) ob_get_clean() . $body; }
        $code = http_response_code();
        $loc  = '';
        foreach (headers_list() as $h) {
            if (stripos($h, 'Location:') === 0) { $loc = trim(substr($h, 9)); }
        }
        $flash = $_SESSION['shg_agent_flash'] ?? ($_SESSION['shg_agents_flash'] ?? null);
        echo '@@FX1@@' . base64_encode((string) json_encode([
            'code'  => $code === false ? 200 : (int) $code,
            'loc'   => $loc,
            'flash' => is_array($flash) ? [(string) ($flash[0] ?? ''), (string) ($flash[1] ?? '')] : null,
            'body'  => base64_encode($body),
        ])) . '@@';
    });

    chdir($ROOT . '/' . dirname($page));
    require $ROOT . '/' . $page;
    exit(0);
}

/* =====================================================================
 *  Parent
 * ===================================================================== */
require_once $ROOT . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/agentreport.php';
require_once INCLUDE_PATH . '/agentstatement.php';
require_once INCLUDE_PATH . '/agentcredit.php';

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function thrown(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}

/** Run one page in a child process as $asId. @return array{code:int, loc:string, flash:?array, body:string} */
function hit(int $asId, string $page, array $get = [], ?array $post = null, bool $noKyc = false): array {
    global $ROOT;
    $ini  = $ROOT . DIRECTORY_SEPARATOR . '.claude' . DIRECTORY_SEPARATOR . 'php-dev.ini';
    if (!is_file($ini)) { $ini = (string) php_ini_loaded_file(); }     // a worktree without its own ini: the child uses the parent's
    $spec = base64_encode((string) json_encode(['as' => $asId, 'page' => $page, 'get' => $get, 'post' => $post, 'nokyc' => $noKyc]));
    $cmd  = escapeshellarg(PHP_BINARY) . (is_file($ini) ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' --hit ' . $spec;
    $des  = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $des, $pipes, $ROOT);
    if (!is_resource($proc)) { return ['code' => 0, 'loc' => '', 'flash' => null, 'body' => '(could not start the child)']; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    if (preg_match('/@@FX1@@([A-Za-z0-9+\/=]+)@@/', $out, $m) !== 1) {
        return ['code' => 0, 'loc' => '', 'flash' => null, 'body' => '(no result) ' . substr($out . ' ' . $err, 0, 600)];
    }
    $r = json_decode((string) base64_decode($m[1]), true) ?: [];
    return ['code' => (int) ($r['code'] ?? 0), 'loc' => (string) ($r['loc'] ?? ''), 'flash' => $r['flash'] ?? null,
            'body' => (string) base64_decode((string) ($r['body'] ?? ''))];
}
/** Does the body show any of these strings? Returns the first one found, '' when clean. */
function leaks(string $body, array $needles): string {
    foreach ($needles as $n) {
        if ($n !== '' && stripos($body, (string) $n) !== false) { return (string) $n; }
    }
    return '';
}
/** The home page's own content (the side menu links to SmartBook and Quick Ticket for everyone). */
function ahMain(string $body): string {
    $at = strpos($body, '<div class="ah">');
    if ($at === false) return '';
    // Stop at </main>: the shared chrome after it (hub bottom bar) has its own Book link.
    $end = strpos($body, '</main>', $at);
    return substr($body, $at, $end === false ? null : $end - $at);
}
/** A PDF's text: the raw bytes plus every Flate stream inflated (so a name inside a compressed page is found). */
function pdfText(string $pdf): string {
    $out = $pdf;
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m) > 0) {
        foreach ($m[1] as $s) {
            $u = @gzuncompress($s);
            if ($u === false) { $u = @gzinflate($s); }
            if (is_string($u)) { $out .= "\n" . $u; }
        }
    }
    return $out;
}

/* ---- settings this suite touches: saved, restored at the end ---- */
$keys = [
    'agent_home_on' => 'bool', 'agent_subagent_office_approve_on' => 'bool', 'agent_credit_parent_hold_on' => 'bool',
    'agent_list_verified_first_on' => 'bool', 'agent_credit_block_on' => 'bool', 'agent_credit_limit_default' => 'float',
];
$watch = ['pvt_ltd_config', 'company_branches', 'company_announcement'];     // must come out exactly as they went in
$saved = [];
foreach (array_merge(array_keys($keys), $watch) as $sk) {
    $saved[$sk] = Database::fetch('SELECT svalue, stype, sgroup FROM settings WHERE skey = :k', ['k' => $sk]);
}
$set = static function (string $k, string $v) use ($keys): void { Settings::set($k, $v, $keys[$k], 'agent'); };

$tag   = substr(bin2hex(random_bytes(4)), 0, 8);
$TAG   = strtoupper($tag);
$made  = [];          // admins ids
$books = [];          // bookings ids
$cleanup = static function () use (&$made, &$books, $saved, $tag): void {
    // counter staff the pages created under our agents carry no fx1ag- username: find them by their parent
    try {
        if ($made !== []) {
            foreach (Database::fetchAll('SELECT admin_id FROM admin_profiles WHERE parent_agent_id IN (' . implode(',', array_map('intval', $made)) . ')') as $r) {
                if (!in_array((int) $r['admin_id'], $made, true)) { $made[] = (int) $r['admin_id']; }
            }
        }
    } catch (Throwable $e) {
        echo "  (cleanup) " . $e->getMessage() . "\n";
    }
    foreach ($books as $bid) {
        try {
            Database::run('DELETE FROM agent_ledger WHERE booking_id = :b', ['b' => $bid]);
            Database::run('DELETE FROM bookings WHERE id = :b', ['b' => $bid]);
        } catch (Throwable $e) {
            echo "  (cleanup) " . $e->getMessage() . "\n";
        }
    }
    $books = [];
    foreach ($made as $id) {
        try {
            Database::run('DELETE FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $id]);
            // agent_codes is ONE shared settings row and Settings caches it per process: re-read it
            // before every removal, or the map this process loaded at the start (without the codes
            // another session issued meanwhile) would be written back over theirs. No code = no write.
            try {
                Settings::flush();
                if (AgentWallet::agentCodeFor($id) !== null) { AgentWallet::setAgentCode($id, null, 0); }
            } catch (Throwable $e) {}
            Database::run('DELETE FROM admin_profiles WHERE admin_id = :a', ['a' => $id]);
            $un = (string) Database::scalar('SELECT username FROM admins WHERE id = :a', ['a' => $id], '');
            // audit rows ABOUT this throw-away person (by id, and staff.create by username) and rows it wrote itself
            Database::run("DELETE FROM audit_logs WHERE entity_type = 'admin' AND entity_id = :a AND (action LIKE 'agent.%' OR action LIKE 'subagent.%')", ['a' => (string) $id]);
            if ($un !== '') { Database::run("DELETE FROM audit_logs WHERE entity_type = 'admin' AND entity_id = :u AND action = 'staff.create'", ['u' => $un]); }
            Database::run("DELETE FROM audit_logs WHERE actor_type = 'admin' AND actor_id = :a", ['a' => $id]);
            try { Database::run("DELETE FROM kv_store WHERE kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $id]); } catch (Throwable $e) {}
            Database::run('DELETE FROM admins WHERE id = :a', ['a' => $id]);
        } catch (Throwable $e) {
            echo "  (cleanup) " . $e->getMessage() . "\n";
        }
    }
    $made = [];
    foreach ($saved as $sk => $row) {
        try {
            if ($row === null) {
                Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $sk]);
            } else {
                $now = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $sk]);
                if ($now === null || (string) $now['svalue'] !== (string) $row['svalue']) {
                    Settings::set($sk, (string) $row['svalue'], (string) $row['stype'], (string) $row['sgroup']);
                }
            }
        } catch (Throwable $e) {
            echo "  (cleanup) " . $e->getMessage() . "\n";
        }
    }
};
register_shutdown_function($cleanup);

$n  = 0;
$mk = static function (string $role, string $name) use ($tag, &$made, &$n): int {
    $n++;
    $id = (int) Database::insert('admins', ['username' => 'fx1ag-' . $role . $n . '-' . $tag,
        'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => $name . ' ' . $tag, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
    $made[] = $id;
    return $id;
};
$book = static function (int $seller, string $pnr, float $total, string $status = 'confirmed', array $extra = []) use (&$books): int {
    $id = (int) Database::insert('bookings', $extra + [
        'pnr' => $pnr, 'status' => $status, 'sold_by_admin_id' => $seller, 'contact_phone' => '9999900000',
        'contact_email' => 'fx1ag@example.test', 'total_amount' => $total, 'currency' => 'INR', 'source' => 'agent',
        'confirmed_at' => date('Y-m-d H:i:s'),
    ]);
    $books[] = $id;
    return $id;
};
/** One ledger row of OUR agent, written directly (the wallet's own writers need a full seat sale). */
$led = static function (int $agent, string $account, string $type, float $amount, ?int $booking = null, ?string $at = null, string $ref = ''): int {
    $row = ['agent_admin_id' => $agent, 'booking_id' => $booking, 'account' => $account, 'entry_type' => $type,
            'amount' => $amount, 'note' => 'fx1 agent test', 'ref' => $ref !== '' ? $ref : null];
    if ($at !== null) { $row['created_at'] = $at; }
    return (int) Database::insert('agent_ledger', $row);
};
$signIn = static function (int $id): void {
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $id]);
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
        'must_change_pw' => false, 'via_otp' => false,
    ];
};
$signOut = static function (): void { unset($_SESSION[ADMIN_SESSION_KEY]); };
$freePhone = static function (): string {
    for ($i = 0; $i < 50; $i++) {
        $p = '70' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        if (!Database::exists("SELECT 1 FROM admins WHERE RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), 10) = :p", ['p' => $p])) { return $p; }
    }
    return '7000000000';
};
$active = static fn(int $id): int => (int) Database::scalar('SELECT is_active FROM admins WHERE id = :i', ['i' => $id], -1);
$rowsOf = static fn(int $id): int => (int) Database::scalar('SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $id], 0);
$cashOf = static fn(int $id): float => (float) AgentWallet::balances($id)['cash'];

try {
    foreach (array_keys($keys) as $sk) {
        $set($sk, $sk === 'agent_credit_limit_default' ? '0' : '1');
    }

    /* ---------------- seed ---------------- */
    $A = $mk('agent', 'Fxagalpha');            // main agent, the "attacker" in section A
    $B = $mk('agent', 'Fxagbravo');            // another agent, the "victim" (no profile row at all)
    $S = $mk('agent', 'Fxagsierra');           // counter staff of A
    $V = $mk('agent', 'Fxagzulu');             // KYC verified
    $X = $mk('accountant', 'Fxagxray');        // office: commissions.view + pay, no staff.manage
    $M = $mk('manager', 'Fxagmike');           // office: staff.manage + commissions.pay, not super-admin
    $C = $mk('counter', 'Fxagcharlie');        // counter clerk: no commissions.view
    $Z = $mk('superadmin', 'Fxagzed');
    Database::run('INSERT INTO admin_profiles (admin_id, agent_kind) VALUES (:a, :k)', ['a' => $A, 'k' => 'person']);
    Database::run('INSERT INTO admin_profiles (admin_id, agent_kind, parent_agent_id) VALUES (:a, :k, :p)', ['a' => $S, 'k' => 'person', 'p' => $A]);
    Database::run("INSERT INTO admin_profiles (admin_id, agent_kind, kyc_status) VALUES (:a, 'person', 'verified')", ['a' => $V]);
    $name = static fn(int $id): string => (string) Database::scalar('SELECT full_name FROM admins WHERE id = :i', ['i' => $id], '');
    $user = static fn(int $id): string => (string) Database::scalar('SELECT username FROM admins WHERE id = :i', ['i' => $id], '');

    $today = todayISO();
    $first = date('Y-m-01', (int) strtotime($today . ' 12:00:00'));
    $now   = date('Y-m-d H:i:s');

    // A: two tickets today (one sold in NPR at a Nepal window), one cancelled; commission on each; one void
    $pA1 = 'FX1A1' . $TAG; $pA2 = 'FX1A2' . $TAG; $pA3 = 'FX1A3' . $TAG;
    $bA1 = $book($A, $pA1, 2200);
    $fx  = CounterDesk::hasColumn('bookings', 'fx_total') ? ['fx_currency' => 'NPR', 'fx_total' => 3520] + (CounterDesk::hasColumn('bookings', 'fx_rate') ? ['fx_rate' => 1.6] : []) : [];
    $bA2 = $book($A, $pA2, 2200, 'confirmed', $fx);
    $bA3 = $book($A, $pA3, 1500, 'cancelled');
    $led($A, 'cash', 'cash_due', 2200, $bA1);       $led($A, 'commission', 'commission', 200, $bA1);
    $led($A, 'cash', 'cash_due', 2200, $bA2);       $led($A, 'commission', 'commission', 200, $bA2);
    $led($A, 'cash', 'cash_due', 1500, $bA3);       $led($A, 'commission', 'commission', 150, $bA3);
    $led($A, 'commission', 'commission_void', -150, $bA3);
    // a commission row from LAST month (must not be in this month's figure) ...
    $led($A, 'commission', 'commission', 300, null, date('Y-m-d 10:00:00', (int) strtotime($first . ' -3 days')));
    // ... and one earlier THIS month (in the month, not in today) when today is not the 1st
    $earlier = $today !== $first;
    if ($earlier) { $led($A, 'commission', 'commission', 120, null, $first . ' 09:00:00'); }

    // B: one ticket, cash due
    $pB1 = 'FX1B1' . $TAG;
    $bB1 = $book($B, $pB1, 5000);
    $ledB = $led($B, 'cash', 'cash_due', 5000, $bB1);
    $led($B, 'commission', 'commission', 400, $bB1);

    // S (counter staff of A): one ticket, cash 1000
    $pS1 = 'FX1S1' . $TAG;
    $bS1 = $book($S, $pS1, 1000);
    $led($S, 'cash', 'cash_due', 1000, $bS1);
    $led($S, 'commission', 'commission', 100, $bS1);

    $needB = [$name($B), $user($B), $pB1];
    $needA = [$name($A), $user($A), $pA1, $pA2, $pA3];
    $needS = [$name($S), $user($S), $pS1];

    /* ---------------- D home figures ---------------- */
    echo "\n-- D home figures = statement = wallet = credit\n";
    $h  = AgentReport::home($A);
    $st = AgentStatement::data($A, $first, $today);
    $np = AgentWallet::netPosition($A);
    $monthComm = $earlier ? 520.0 : 400.0;
    $netWant   = 5900.0 - (400.0 + 300.0 + ($earlier ? 120.0 : 0.0));
    check('D statement for the month balances', $st['balanced'] === true && $h['balanced'] === true);
    check('D month commission = the statement (earned less reversed)',
        abs($h['month']['commission'] - (-1 * ($st['sum']['earned']['amount'] + $st['sum']['reversed']['amount']))) < 0.005
        && abs($h['month']['commission'] - $monthComm) < 0.005, (string) $h['month']['commission']);
    check("D today's commission = today's ledger rows only (last month and the 1st left out)", abs($h['today']['commission'] - 400.0) < 0.005, (string) $h['today']['commission']);
    check('D net = statement closing = AgentWallet::netPosition',
        abs((float) $h['net']['net'] - (float) $st['closing']) < 0.005 && abs((float) $h['net']['net'] - (float) $np['net']) < 0.005
        && abs((float) $h['net']['net'] - $netWant) < 0.005, $h['net']['net'] . ' / ' . $st['closing']);
    check('D credit outstanding = AgentCredit::outstanding', abs((float) $h['credit']['outstanding'] - AgentCredit::outstanding($A)) < 0.005 && abs((float) $h['credit']['outstanding'] - $netWant) < 0.005);
    $hc = AgentWallet::headcount($A, $today, $today);
    check('D today: 2 tickets, 2 people, ticket value 4,400 (the cancelled one left out) = AgentWallet::headcount',
        $h['today']['tickets'] === 2 && $h['today']['heads'] === 2 && abs($h['today']['sales'] - 4400.0) < 0.005
        && $h['today']['tickets'] === (int) $hc['tickets'] && abs($h['today']['sales'] - (float) $hc['sales']) < 0.005);
    check('D owes sentence is the statement\'s own', $h['owesEn'] === AgentStatement::owes((float) $np['net'], 'en') && str_contains($h['owesNe'], inr($netWant)));
    check('D last five: newest first, only this agent', count($h['recent']) === 3 && (string) $h['recent'][0]['pnr'] === $pA3
        && leaks(json_encode($h['recent']), [$pB1, $pS1]) === '');
    check('D KYC: no verified ID = not ok; verified agent = ok; an agent with NO profile row = not ok',
        $h['kycOk'] === false && AgentReport::home($V)['kycOk'] === true && AgentReport::home($B)['kycOk'] === false);

    $pg = hit($A, 'admin/agent-home.php');
    check('D page renders for the agent', $pg['code'] === 200 && str_contains($pg['body'], 'id="ahWallet"'), 'code ' . $pg['code'] . ' ' . substr($pg['body'], 0, 300));
    check('D page prints the net, cash and commission of the helpers',
        str_contains($pg['body'], 'data-ah="net">' . inr($netWant) . '<')
        && str_contains($pg['body'], 'data-ah="cash">' . inr(5900) . '<')
        && str_contains($pg['body'], 'data-ah="commission">' . inr(5900 - $netWant) . '<'));
    check('D page prints today and month figures',
        str_contains($pg['body'], 'data-ah="today-sales">' . inr(4400) . '<') && str_contains($pg['body'], 'data-ah="today-commission">' . inr(400) . '<')
        && str_contains($pg['body'], 'data-ah="month-commission">' . inr($monthComm) . '<'));
    check('D "due to office" wording when the agent owes', str_contains($pg['body'], 'अफिसलाई तिर्न बाँकी'));
    check('D one Book-ticket button to SmartBook (the one ticket door)', str_contains($pg['body'], 'id="ahBook" href="/admin/smartbook.php"') && !str_contains(ahMain($pg['body']), 'quick-ticket.php')
        && substr_count(ahMain($pg['body']), 'smartbook.php') === 1);
    check('D KYC pending chip links to the member\'s own page', str_contains($pg['body'], 'id="ahKyc" href="/me/"') && str_contains($pg['body'], 'KYC बाँकी'));
    check('D shortcuts: tickets, passengers, statement, ID card, full panel',
        str_contains($pg['body'], 'href="/admin/bookings.php"') && str_contains($pg['body'], 'href="/admin/agent-passengers.php"')
        && str_contains($pg['body'], 'href="/admin/agent-statement.php"') && str_contains($pg['body'], 'href="/me/"') && str_contains($pg['body'], 'href="/admin/agent.php"'));
    check('D last five tickets on the page, no customer phone printed', str_contains($pg['body'], $pA1) && str_contains($pg['body'], $pA3) && !str_contains($pg['body'], '9999900000'));
    if ($fx !== []) {
        check('D an NPR sale reads NPR on the home, the panel and the sales book',
            str_contains($pg['body'], 'NPR 3,520 (INR 2,200)')
            && str_contains(hit($A, 'admin/agent.php')['body'], 'NPR 3,520 (INR 2,200)')
            && str_contains(hit($A, 'admin/agent-sales.php', ['range' => 'today', 'basis' => 'sale'])['body'], 'NPR 3,520 (INR 2,200)'));
    } else {
        echo "  SKIP  bookings.fx_total missing on this database\n";
    }
    check('D no parcel wording and no form on the home', stripos($pg['body'], 'parcel') === false && stripos($pg['body'], 'पार्सल') === false
        && !str_contains($pg['body'], '<form method="post"'));

    // last-five seats: a released berth (cancel / seat shift) is not shown, the one still held is
    if (CounterDesk::hasColumn('booking_seats', 'released_at')) {
        require_once INCLUDE_PATH . '/seats.php';
        $sDate   = date('Y-m-d', strtotime('+333 days'));
        $sNew    = Database::fetch('SELECT id FROM schedules WHERE route_id = 2 AND travel_date = :d', ['d' => $sDate]) === null;
        $sId     = 0;
        $legRow  = 0;
        try {
            $sId    = (int) Seats::schedule(2, $sDate)['id'];
            $sIds   = Seats::seatIds('sleeper', 'sharing');
            [$sKeep, $sGone] = [$sIds[count($sIds) - 1], $sIds[count($sIds) - 3]];
            $legRow = (int) Database::insert('booking_legs', ['booking_id' => $bA1, 'schedule_id' => $sId, 'leg_type' => 'outbound',
                'travel_date' => $sDate, 'fare_per_seat' => 1100, 'seat_count' => 1, 'leg_total' => 1100]);
            Database::insert('booking_seats', ['schedule_id' => $sId, 'seat_no' => $sKeep, 'booking_id' => $bA1, 'leg_id' => $legRow]);
            Database::insert('booking_seats', ['schedule_id' => $sId, 'seat_no' => $sGone, 'booking_id' => $bA1, 'leg_id' => $legRow,
                'released_at' => date('Y-m-d H:i:s')]);
            $seatsOf = '';
            foreach (AgentReport::home($A)['recent'] as $rr) {
                if ((string) $rr['pnr'] === $pA1) { $seatsOf = (string) ($rr['seats'] ?? ''); }
            }
            check('D last-five seats leave out a released berth', $seatsOf === (string) $sKeep, 'seats "' . $seatsOf . '"');
        } catch (Throwable $ex) {
            check('D last-five seats leave out a released berth', false, $ex->getMessage());
        } finally {
            try {
                Database::run('DELETE FROM booking_seats WHERE booking_id = :b', ['b' => $bA1]);
                if ($legRow > 0) { Database::run('DELETE FROM booking_legs WHERE id = :l', ['l' => $legRow]); }
                if ($sNew && $sId > 0) { Database::delete('schedules', 'id = :i', ['i' => $sId]); }
            } catch (Throwable $ex) {
                echo "  (seat cleanup) " . $ex->getMessage() . "\n";
            }
        }
    } else {
        echo "  SKIP  booking_seats.released_at missing on this database\n";
    }

    // credit limit bar + hold: the button goes, the office buttons come
    AgentCredit::setLimit($A, 10000.0, $Z);
    $pg = hit($A, 'admin/agent-home.php');
    check('D limit bar: outstanding / limit', str_contains($pg['body'], 'id="ahLimit"') && str_contains($pg['body'], inr($netWant) . ' / ' . inr(10000)));
    check('D near the limit keeps the Book button', str_contains(ahMain($pg['body']), 'href="/admin/smartbook.php"'));
    AgentCredit::setHold($A, true, 'fx1 test dues', $Z);
    $pg = hit($A, 'admin/agent-home.php');
    check('D on hold: no Book link, hold line, call / WhatsApp the office',
        ahMain($pg['body']) !== '' && !str_contains(ahMain($pg['body']), 'smartbook.php') && str_contains($pg['body'], 'id="ahHold"') && str_contains($pg['body'], 'fx1 test dues')
        && str_contains($pg['body'], 'href="tel:') && str_contains($pg['body'], 'https://wa.me/'));
    check('D the hold banner is printed once (admin_header), not twice', substr_count($pg['body'], 'data-credit="blocked"') === 1);
    AgentCredit::setHold($A, false, '', $Z);
    AgentCredit::setLimit($A, null, $Z);

    /* ---------------- A read isolation ---------------- */
    echo "\n-- A read isolation\n";
    $pg = hit($A, 'admin/agent-home.php', ['agent' => $B]);
    check('A home: ?agent=B as agent A shows A', $pg['code'] === 200 && str_contains($pg['body'], $name($A)) && leaks($pg['body'], $needB) === '', leaks($pg['body'], $needB));
    foreach ([
        ['admin/agent.php', ['agent' => $B]],
        ['admin/agent.php', ['agent' => $B, 'export' => 'ledger', 'from' => '2020-01-01']],
        ['admin/agent-sales.php', ['agent' => $B, 'range' => 'all', 'basis' => 'sale']],
        ['admin/agent-passengers.php', ['agent' => $B]],
        ['admin/agent-passengers.php', ['agent' => $B, 'view' => 'customers']],
        ['admin/agent-statement.php', ['agent' => $B, 'from' => '2020-01-01', 'to' => $today]],
        ['admin/settlements.php', ['agent' => $B]],
    ] as [$p, $g]) {
        $pg = hit($A, $p, $g);
        check('A ' . $p . '?' . http_build_query($g) . ' as agent A never shows B', in_array($pg['code'], [200, 302, 403], true) && leaks($pg['body'], $needB) === '',
            'code ' . $pg['code'] . ' leaked ' . leaks($pg['body'], $needB) . ' ' . ($pg['code'] === 0 ? $pg['body'] : ''));
    }
    // searching for B's PNR in A's book: the box repeats what was typed, the list stays empty
    $pg = hit($A, 'admin/agent-sales.php', ['agent' => $B, 'range' => 'all', 'basis' => 'sale', 'q' => $pB1]);
    check('A searching B\'s PNR in the sales book as agent A finds nothing', $pg['code'] === 200 && str_contains($pg['body'], 'No tickets in this range')
        && leaks($pg['body'], [$name($B), $user($B), 'booking-view.php?pnr=' . $pB1]) === '');
    $own = hit($A, 'admin/agent-statement.php', ['format' => 'pdf', 'from' => $first, 'to' => $today]);
    $oth = hit($A, 'admin/agent-statement.php', ['agent' => $B, 'format' => 'pdf', 'from' => '2020-01-01', 'to' => $today]);
    if (str_starts_with($own['body'], '%PDF') && leaks(pdfText($own['body']), [$pA1]) !== '') {
        check('A statement PDF with ?agent=B as agent A is A\'s own statement', str_starts_with($oth['body'], '%PDF') && leaks(pdfText($oth['body']), $needB) === '' && leaks(pdfText($oth['body']), [$pA1]) !== '');
    } else {
        echo "  SKIP  statement PDF text could not be read back (" . strlen($own['body']) . " bytes) - the screen check above covers the scope\n";
    }
    $pg = hit($A, 'admin/agent-receipt.php', ['id' => $ledB]);
    check('A receipt of B\'s ledger row is refused', $pg['code'] === 403 && leaks($pg['body'], $needB) === '', 'code ' . $pg['code']);
    foreach (['admin/agent-360.php', 'admin/agents.php', 'admin/agent-aging.php', 'admin/agent-ranking.php', 'admin/people.php', 'admin/id-card.php'] as $p) {
        $pg = hit($A, $p, ['agent' => $B, 'id' => $B]);
        check('A ' . $p . ' is closed to an agent', $pg['code'] === 403 && leaks($pg['body'], $needB) === '', 'code ' . $pg['code']);
    }
    // sub-agent against its main agent, and the main agent against the sub-agent's own books
    foreach (['admin/agent-home.php', 'admin/agent.php', 'admin/agent-sales.php', 'admin/agent-passengers.php', 'admin/agent-statement.php'] as $p) {
        $pg = hit($S, $p, ['agent' => $A, 'range' => 'all', 'basis' => 'sale', 'from' => '2020-01-01', 'to' => $today]);
        check('A ' . $p . ' as the sub-agent never shows the main agent', $pg['code'] === 200 && leaks($pg['body'], $needA) === '', 'code ' . $pg['code'] . ' leaked ' . leaks($pg['body'], $needA));
    }
    foreach (['admin/agent-home.php', 'admin/agent-sales.php', 'admin/agent-passengers.php', 'admin/agent-statement.php'] as $p) {
        $pg = hit($A, $p, ['agent' => $S, 'range' => 'all', 'basis' => 'sale', 'from' => '2020-01-01', 'to' => $today]);
        check('A ' . $p . ' as the main agent stays the main agent\'s own book', $pg['code'] === 200 && leaks($pg['body'], $needS) === '', 'code ' . $pg['code'] . ' leaked ' . leaks($pg['body'], $needS));
    }
    $pg = hit($S, 'admin/agent.php');
    check('A a sub-agent gets no counter-staff panel (no staff under staff)', $pg['code'] === 200 && !str_contains($pg['body'], 'value="add_subagent"') && !str_contains($pg['body'], 'My Counter Staff'));
    $pg = hit($A, 'admin/agent.php');
    check('A the main agent sees their counter staff; the staff ticket PNR is text, not a dead link',
        str_contains($pg['body'], 'value="add_subagent"') && str_contains($pg['body'], $name($S)) && str_contains($pg['body'], $pS1)
        && !str_contains($pg['body'], 'booking-view.php?pnr=' . $pS1));
    check('A the panel no longer carries a typed office number', !str_contains($pg['body'], 'wa.me/919104801507?text=Namaste+HQ'));

    // office viewers
    $pg = hit($X, 'admin/agent-home.php', ['agent' => $B]);
    check('A home for the office: the picked agent, no Book button, the office-view tag',
        $pg['code'] === 200 && str_contains($pg['body'], $name($B)) && !str_contains($pg['body'], 'id="ahBook"') && str_contains($pg['body'], 'office view')
        && str_contains($pg['body'], 'agent-statement.php?agent=' . $B));
    $pg = hit($X, 'admin/agent-home.php');
    check('A home for the office with nobody picked: the "agent page" notice and the picker', $pg['code'] === 200 && str_contains($pg['body'], 'यो एजेन्टको पेज हो') && str_contains($pg['body'], 'name="agent"') && !str_contains($pg['body'], 'id="ahWallet"'));
    $pg = hit($X, 'admin/agent-home.php', ['agent' => $X]);
    check('A home: ?agent= of a non-agent row is not opened', str_contains($pg['body'], 'यो एजेन्टको पेज हो') && !str_contains($pg['body'], 'id="ahWallet"'));
    $pg = hit($C, 'admin/agent-home.php', ['agent' => $B]);
    check('A home is closed to a counter clerk (no commissions.view)', $pg['code'] === 403 && leaks($pg['body'], $needB) === '', 'code ' . $pg['code']);
    $set('agent_home_on', '0');
    $pg = hit($A, 'admin/agent-home.php');
    check('A switch agent_home_on = 0 forwards to the agent panel', $pg['code'] === 302 && !str_contains($pg['body'], 'id="ahWallet"') && ($pg['loc'] === '' || str_contains($pg['loc'], 'admin/agent.php')), 'code ' . $pg['code'] . ' loc ' . $pg['loc']);
    $set('agent_home_on', '1');

    /* ---------------- B POST isolation ---------------- */
    echo "\n-- B POST isolation (agent A posting B's id)\n";
    $rowsB = $rowsOf($B); $rowsA = $rowsOf($A);
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'profile', 'agent_id' => (string) $B, 'counter_name' => 'FX1 hijack', 'display_phone' => '7000000001']);
    check('B profile of another agent is refused', ($pg['flash'][0] ?? '') === 'bad' && !Database::exists("SELECT 1 FROM admin_profiles WHERE admin_id = :b AND counter_name = 'FX1 hijack'", ['b' => $B]), json_encode($pg['flash']));
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'cash_handover', 'agent_id' => (string) $B, 'amount' => '100']);
    check('B cash handover on another agent is refused', ($pg['flash'][0] ?? '') === 'bad' && $rowsOf($B) === $rowsB, json_encode($pg['flash']));
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'payout', 'amount' => '100', 'ref' => 'FX1REF' . $TAG]);
    check('B paying out one\'s own commission is refused', ($pg['flash'][0] ?? '') === 'bad' && $rowsOf($A) === $rowsA, json_encode($pg['flash']));
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'toggle_subagent', 'sub_agent_id' => (string) $B]);
    check('B pausing an agent who is not your counter staff is refused', ($pg['flash'][0] ?? '') === 'bad' && $active($B) === 1, json_encode($pg['flash']));
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'subagent_handover', 'sub_agent_id' => (string) $B, 'amount' => '100']);
    check('B taking cash from an agent who is not your counter staff writes nothing', ($pg['flash'][0] ?? '') === 'bad' && $rowsOf($B) === $rowsB && $rowsOf($A) === $rowsA, json_encode($pg['flash']));

    /* ---------------- C sub-agent rules ---------------- */
    echo "\n-- C sub-agent rules\n";
    $pw = 'Fx1-' . bin2hex(random_bytes(5));
    $ph1 = $freePhone();
    $pg = hit($S, 'admin/agent.php', [], ['action' => 'add_subagent', 'sub_name' => 'Fxagchild One ' . $tag, 'sub_phone' => $ph1, 'sub_pin' => $pw]);
    check('C a sub-agent cannot add counter staff', ($pg['flash'][0] ?? '') === 'bad' && !Database::exists('SELECT 1 FROM admins WHERE phone = :p', ['p' => $ph1]), json_encode($pg['flash']));
    $pg = hit($X, 'admin/agent.php', ['agent' => $A], ['action' => 'add_subagent', 'sub_name' => 'Fxagchild One ' . $tag, 'sub_phone' => $ph1, 'sub_pin' => $pw]);
    check('C an office user without staff.manage cannot add counter staff', ($pg['flash'][0] ?? '') === 'bad' && !Database::exists('SELECT 1 FROM admins WHERE phone = :p', ['p' => $ph1]), json_encode($pg['flash']));

    AgentCredit::setHold($A, true, 'fx1 test dues', $Z);
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'add_subagent', 'sub_name' => 'Fxagchild One ' . $tag, 'sub_phone' => $ph1, 'sub_pin' => $pw]);
    check('C a main agent on hold cannot add counter staff', ($pg['flash'][0] ?? '') === 'bad' && !Database::exists('SELECT 1 FROM admins WHERE phone = :p', ['p' => $ph1]), json_encode($pg['flash']));
    AgentCredit::setHold($A, false, '', $Z);

    $pg = hit($A, 'admin/agent.php', [], ['action' => 'add_subagent', 'sub_name' => 'Fxagchild One ' . $tag, 'sub_phone' => $ph1, 'sub_pin' => 'short7!']);
    check('C a password under 8 characters is refused', ($pg['flash'][0] ?? '') === 'bad' && !Database::exists('SELECT 1 FROM admins WHERE phone = :p', ['p' => $ph1]));

    $pg = hit($A, 'admin/agent.php', [], ['action' => 'add_subagent', 'sub_name' => 'Fxagchild One ' . $tag, 'sub_phone' => $ph1, 'sub_pin' => $pw]);
    $c1 = Database::fetch('SELECT a.*, p.parent_agent_id FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id WHERE a.phone = :p', ['p' => $ph1]);
    check('C the main agent adds counter staff: an agent login under them', ($pg['flash'][0] ?? '') === 'ok' && $c1 !== null && (string) $c1['role'] === 'agent' && (int) $c1['parent_agent_id'] === $A, json_encode($pg['flash']));
    $c1id = (int) ($c1['id'] ?? 0);
    if ($c1id > 0 && !in_array($c1id, $made, true)) { $made[] = $c1id; }
    check('C it starts PAUSED until the office switches it on (agent_subagent_office_approve_on = 1)', $c1 !== null && (int) $c1['is_active'] === 0 && str_contains((string) ($pg['flash'][1] ?? ''), 'अफिसले खोलेपछि'));
    check('C the password works and is never repeated in the message', $c1 !== null && password_verify($pw, (string) $c1['password_hash']) && !str_contains((string) ($pg['flash'][1] ?? ''), $pw));
    check('C the mobile is kept as typed (no forced +91)', $c1 !== null && (string) $c1['phone'] === $ph1);
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'add_subagent', 'sub_name' => 'Fxagchild Dup ' . $tag, 'sub_phone' => $ph1, 'sub_pin' => $pw]);
    check('C the same mobile cannot be given a second login', ($pg['flash'][0] ?? '') === 'bad' && (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE phone = :p', ['p' => $ph1], 0) === 1, json_encode($pg['flash']));
    $pg = hit($c1id, 'admin/agent.php', [], ['action' => 'add_subagent', 'sub_name' => 'Fxagchild Sub ' . $tag, 'sub_phone' => $freePhone(), 'sub_pin' => $pw]);
    check('C the new counter staff cannot add staff of their own', ($pg['flash'][0] ?? '') === 'bad');
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'toggle_subagent', 'sub_agent_id' => (string) $c1id]);
    check('C the main agent cannot switch a paused login on', ($pg['flash'][0] ?? '') === 'bad' && $active($c1id) === 0, json_encode($pg['flash']));
    $pg = hit($A, 'admin/agent.php');
    check('C the main agent sees "waiting for the office", no Resume button for it', str_contains($pg['body'], 'Waiting for office') && str_contains($pg['body'], 'the office switches it on'));
    $pg = hit($X, 'admin/agent.php', ['agent' => $A], ['action' => 'toggle_subagent', 'sub_agent_id' => (string) $c1id]);
    check('C an office user without staff.manage cannot switch it on', ($pg['flash'][0] ?? '') === 'bad' && $active($c1id) === 0);
    $pg = hit($X, 'admin/agent.php', ['agent' => $A], ['action' => 'toggle_subagent', 'sub_agent_id' => (string) $Z]);
    check('C an office user cannot switch a super-admin off through this form any more', ($pg['flash'][0] ?? '') === 'bad' && $active($Z) === 1, json_encode($pg['flash']));
    $pg = hit($M, 'admin/agent.php', ['agent' => $A], ['action' => 'toggle_subagent', 'sub_agent_id' => (string) $Z]);
    check('C not even with staff.manage: the target must be that agent\'s counter staff', ($pg['flash'][0] ?? '') === 'bad' && $active($Z) === 1);
    $pg = hit($M, 'admin/agent.php', ['agent' => $A], ['action' => 'toggle_subagent', 'sub_agent_id' => (string) $c1id]);
    check('C the office (staff.manage) switches the new login on', ($pg['flash'][0] ?? '') === 'ok' && $active($c1id) === 1, json_encode($pg['flash']));
    $oneWay = 'only the office can switch this login on again';
    check('C the main agent is warned BEFORE the tap that Pause is one-way', stripos(hit($A, 'admin/agent.php')['body'], $oneWay) !== false);
    check('C the office (staff.manage) gets no such warning: it can resume', stripos(hit($M, 'admin/agent.php', ['agent' => $A])['body'], $oneWay) === false);
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'toggle_subagent', 'sub_agent_id' => (string) $c1id]);
    check('C the main agent may always pause their own staff', ($pg['flash'][0] ?? '') === 'ok' && $active($c1id) === 0);
    check('C ... and the result says only the office can switch it on again', stripos((string) ($pg['flash'][1] ?? ''), $oneWay) !== false, json_encode($pg['flash']));
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'toggle_subagent', 'sub_agent_id' => (string) $c1id]);
    check('C ... but not switch them back on while the switch is on', ($pg['flash'][0] ?? '') === 'bad' && $active($c1id) === 0);

    $set('agent_subagent_office_approve_on', '0');
    $ph2 = $freePhone();
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'add_subagent', 'sub_name' => 'Fxagchild Two ' . $tag, 'sub_phone' => $ph2, 'sub_pin' => $pw]);
    $c2id = (int) Database::scalar('SELECT id FROM admins WHERE phone = :p', ['p' => $ph2], 0);
    if ($c2id > 0 && !in_array($c2id, $made, true)) { $made[] = $c2id; }
    check('C switch off: the new login is active at once, as before', ($pg['flash'][0] ?? '') === 'ok' && $c2id > 0 && $active($c2id) === 1, json_encode($pg['flash']));
    check('C switch off: no one-way warning on the page', stripos(hit($A, 'admin/agent.php')['body'], $oneWay) === false);
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'toggle_subagent', 'sub_agent_id' => (string) $c2id]);
    check('C switch off: no one-way warning in the result either', stripos((string) ($pg['flash'][1] ?? ''), $oneWay) === false);
    $off = $active($c2id);
    hit($A, 'admin/agent.php', [], ['action' => 'toggle_subagent', 'sub_agent_id' => (string) $c2id]);
    check('C switch off: the main agent pauses and resumes, as before', $off === 0 && $active($c2id) === 1);
    $pg = hit($S, 'admin/agent.php', [], ['action' => 'add_subagent', 'sub_name' => 'Fxagchild No ' . $tag, 'sub_phone' => $freePhone(), 'sub_pin' => $pw]);
    check('C switch off: a sub-agent still cannot add staff (that is a fix, not a switch)', ($pg['flash'][0] ?? '') === 'bad');
    $set('agent_subagent_office_approve_on', '1');

    // counter-staff cash handed to the main agent
    $cashA = $cashOf($A); $cashS = $cashOf($S); $rowsA = $rowsOf($A); $rowsS = $rowsOf($S);
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'subagent_handover', 'sub_agent_id' => (string) $S, 'amount' => '5000', 'note' => 'fx1 too much']);
    check('C more cash than the staff member holds is refused and NOTHING is written on either ledger',
        ($pg['flash'][0] ?? '') === 'bad' && $rowsOf($A) === $rowsA && $rowsOf($S) === $rowsS, json_encode($pg['flash']));
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'subagent_handover', 'sub_agent_id' => (string) $S, 'amount' => '400', 'note' => 'fx1 handover']);
    check('C handover 400: the staff member holds 400 less, the main agent 400 more',
        ($pg['flash'][0] ?? '') === 'ok' && abs($cashOf($S) - ($cashS - 400)) < 0.005 && abs($cashOf($A) - ($cashA + 400)) < 0.005, json_encode($pg['flash']));
    $pg = hit($X, 'admin/agent.php', ['agent' => $B], ['action' => 'subagent_handover', 'sub_agent_id' => (string) $S, 'amount' => '100']);
    check('C the office cannot move a staff member\'s cash to an agent who is not their main agent', ($pg['flash'][0] ?? '') === 'bad' && abs($cashOf($S) - ($cashS - 400)) < 0.005 && $rowsOf($B) === $rowsB);
    $pg = hit($X, 'admin/agent.php', ['agent' => $A], ['action' => 'subagent_handover', 'sub_agent_id' => (string) $S, 'amount' => '100', 'note' => 'fx1 office']);
    check('C the office with commissions.pay records a real handover', ($pg['flash'][0] ?? '') === 'ok' && abs($cashOf($S) - ($cashS - 500)) < 0.005 && abs($cashOf($A) - ($cashA + 500)) < 0.005, json_encode($pg['flash']));
    check('C a whole-rupee handover reads "₹100", no decimals', str_contains((string) ($pg['flash'][1] ?? ''), '₹100 ') && !str_contains((string) ($pg['flash'][1] ?? ''), '100.00'), json_encode($pg['flash'], JSON_UNESCAPED_UNICODE));
    $pg = hit($A, 'admin/agent.php', [], ['action' => 'subagent_handover', 'sub_agent_id' => (string) $S, 'amount' => '10.55', 'note' => 'fx1 paisa']);
    check('C handover 10.55: both ledgers move by 10.55 and the message says ₹10.55, not ₹11',
        ($pg['flash'][0] ?? '') === 'ok' && abs($cashOf($S) - ($cashS - 510.55)) < 0.005 && abs($cashOf($A) - ($cashA + 510.55)) < 0.005
        && str_contains((string) ($pg['flash'][1] ?? ''), '₹10.55 ') && !str_contains((string) ($pg['flash'][1] ?? ''), '₹11'), json_encode($pg['flash'], JSON_UNESCAPED_UNICODE));

    // the main agent's hold reaches the sub-agent
    $route = Database::fetch('SELECT id FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1');
    $rid   = (int) ($route['id'] ?? 0);
    check('C a sub-agent sells while the main agent is free', AgentCredit::refusal($S) === null && AgentCredit::parentOf($S) === $A && AgentCredit::parentOf($A) === 0);
    AgentCredit::setHold($A, true, 'fx1 secret reason 98765', $Z);
    $why = (string) AgentCredit::refusal($S);
    check('C main agent on hold: the sub-agent is refused, in Nepali', str_contains($why, 'मुख्य एजेन्ट') && AgentCredit::parentRefusal($S) !== null, $why);
    check('C the sub-agent is told nothing of the main agent\'s money or the reason', !str_contains($why, '₹') && !str_contains($why, '98765'));
    check('C an agent with no main agent is untouched', AgentCredit::refusal($B) === null && AgentCredit::parentRefusal($B) === null);
    if ($rid > 0) {
        $signIn($S);
        $door = thrown(static fn() => AgentWallet::assertMaySell($S, $rid));
        $signOut();
        check('C the sale door (AgentWallet::assertMaySell) refuses the sub-agent', str_contains($door, 'मुख्य एजेन्ट'), $door);
    }
    check('C the sub-agent\'s pages carry the banner', str_contains(AgentCredit::bannerHtml($S), 'data-credit="parent"') && str_contains(hit($S, 'admin/agent-home.php')['body'], 'data-credit="parent"'));
    $pg = hit($S, 'admin/agent-home.php');
    check('C the sub-agent\'s home drops the Book link', ahMain($pg['body']) !== '' && !str_contains(ahMain($pg['body']), 'smartbook.php') && leaks($pg['body'], ['98765']) === '');
    $set('agent_credit_parent_hold_on', '0');
    check('C switch agent_credit_parent_hold_on = 0: each ledger alone, as before', AgentCredit::refusal($S) === null && AgentCredit::refusal($A) !== null);
    $set('agent_credit_parent_hold_on', '1');
    AgentCredit::setHold($A, false, '', $Z);
    AgentCredit::setLimit($A, 100.0, $Z);
    check('C main agent AT THE LIMIT: the sub-agent is refused too', AgentCredit::refusal($A) !== null && str_contains((string) AgentCredit::refusal($S), 'मुख्य एजेन्ट'));
    AgentCredit::setLimit($A, null, $Z);
    check('C limit lifted: both sell again', AgentCredit::refusal($A) === null && AgentCredit::refusal($S) === null);

    // the office switches the MAIN agent's login off (agents.php toggle / bulk pause): the staff logins stop with it
    Database::run("UPDATE admins SET is_active = 0 WHERE id = :i AND username LIKE 'fx1ag-%'", ['i' => $A]);
    $why = (string) AgentCredit::refusal($S);
    check('C main agent SWITCHED OFF by the office: the sub-agent is refused, in Nepali', $active($A) === 0 && str_contains($why, 'मुख्य एजेन्ट') && str_contains($why, 'बन्द') && AgentCredit::parentRefusal($S) !== null, $why);
    check('C ... with no figures, and not worded as a dues hold', !str_contains($why, '₹') && !str_contains($why, 'settle'));
    check('C ... another agent and the main agent\'s own ledger rule are untouched', AgentCredit::refusal($B) === null && AgentCredit::refusal($A) === null);
    if ($rid > 0) {
        $signIn($S);
        $door = thrown(static fn() => AgentWallet::assertMaySell($S, $rid));
        $signOut();
        check('C ... the sale door refuses the sub-agent of a switched-off main agent', str_contains($door, 'मुख्य एजेन्ट') && str_contains($door, 'बन्द'), $door);
    }
    check('C ... the sub-agent\'s banner says so', str_contains(AgentCredit::bannerHtml($S), 'data-credit="parent"') && str_contains(AgentCredit::bannerHtml($S), 'बन्द'));
    $set('agent_credit_parent_hold_on', '0');
    check('C ... switch agent_credit_parent_hold_on = 0: the sub-agent sells as before', AgentCredit::refusal($S) === null);
    $set('agent_credit_parent_hold_on', '1');
    Database::run("UPDATE admins SET is_active = 1 WHERE id = :i AND username LIKE 'fx1ag-%'", ['i' => $A]);
    check('C main agent switched on again: the sub-agent sells again', $active($A) === 1 && AgentCredit::refusal($S) === null && !str_contains(AgentCredit::bannerHtml($S), 'data-credit="parent"'));

    /* ---------------- E lists ---------------- */
    echo "\n-- E lists: KYC marker, verified first\n";
    // Rows are found by an anchor that only the list row carries (the name alone also shows in
    // header cards, alerts and pickers): the row checkbox / the row's own link, with the id.
    $rowOf = static function (string $body, string $needle): string {
        $at = strpos($body, $needle);
        if ($at === false) { return ''; }
        $from = strrpos(substr($body, 0, $at), '<tr');
        $to   = strpos($body, '</tr>', $at);
        return $from === false || $to === false ? '' : substr($body, $from, $to - $from);
    };
    $reg  = static fn(int $id): string => 'class="agent-cb" value="' . $id . '"';                 // admin/agents.php
    $a360 = static fn(int $id): string => 'href="/admin/agent-360.php?agent=' . $id . '"><b>';     // people.php, agent-aging.php
    $cmp  = static fn(int $id): string => 'name="cmp[]" value="' . $id . '"';                      // agent-ranking.php

    $pg = hit($Z, 'admin/agents.php');
    $pV = strpos($pg['body'], $reg($V)); $pA = strpos($pg['body'], $reg($A)); $pB = strpos($pg['body'], $reg($B));
    check('E agents register renders for the office', $pg['code'] === 200 && $pV !== false && $pA !== false && $pB !== false, 'code ' . $pg['code']);
    check('E the verified agent is listed above the unverified ones (created later, so serial order alone would put it last)', $pV !== false && $pA !== false && $pB !== false && $pV < $pA && $pV < $pB);
    check('E unverified agent carries "KYC बाँकी"', str_contains($rowOf($pg['body'], $reg($A)), 'KYC बाँकी') && str_contains($rowOf($pg['body'], $reg($A)), 'data-kyc="0"') && str_contains($rowOf($pg['body'], $reg($A)), $name($A)));
    check('E an agent with no profile row reads as not verified', str_contains($rowOf($pg['body'], $reg($B)), 'KYC बाँकी'));
    check('E the verified agent carries no marker', $rowOf($pg['body'], $reg($V)) !== '' && !str_contains($rowOf($pg['body'], $reg($V)), 'data-kyc-pill') && str_contains($rowOf($pg['body'], $reg($V)), 'data-kyc="1"'));
    // a name with an apostrophe must not break out of the inline onclick (dead ID-card button / script in the office session)
    $Q = $mk('agent', "Fxag O'Quote");
    $pq = hit($Z, 'admin/agents.php');
    $rq = $rowOf($pq['body'], $reg($Q));
    check('E a name with an apostrophe is passed to the ID-card button as an encoded JS string',
        $rq !== '' && str_contains($rq, 'openIdModal(&quot;') && str_contains($rq, 'Fxag O\\u0027Quote') && !str_contains($rq, "'Fxag O&#039;Quote"), substr($rq, 0, 200));
    $pg = hit($Z, 'admin/agents.php', ['tier' => 'sub_agents']);
    check('E counter staff waiting for the office show as Pending on the sub-agent tab', $c1id > 0 && str_contains($rowOf($pg['body'], $reg($c1id)), 'data-status="pending"'));
    $pp = hit($Z, 'admin/people.php', ['tab' => 'agents']);
    $qV = strpos($pp['body'], $a360($V)); $qA = strpos($pp['body'], $a360($A));
    check('E Agents & Staff: verified first (the name alone would put Fxagalpha before Fxagzulu)', $pp['code'] === 200 && $qV !== false && $qA !== false && $qV < $qA, 'code ' . $pp['code']);
    check('E Agents & Staff: the marker instead of the bare word "None"', str_contains($rowOf($pp['body'], $a360($A)), 'KYC बाँकी') && str_contains($rowOf($pp['body'], $a360($V)), 'Verified'));
    $kp = AgentReport::kycPending([$A, $B, $V, $X, 0]);
    check('E one rule for every list: unverified and no-profile agents pending, verified and office staff not', isset($kp[$A], $kp[$B]) && !isset($kp[$V]) && !isset($kp[$X]) && $kp[$B] === 'none');
    $pg = hit($Z, 'admin/agent-ranking.php');
    check('E ranking board marks the unverified agent, not the verified one', $pg['code'] === 200 && str_contains($rowOf($pg['body'], $cmp($A)), 'KYC बाँकी')
        && $rowOf($pg['body'], $cmp($V)) !== '' && !str_contains($rowOf($pg['body'], $cmp($V)), 'data-kyc-pill'), 'code ' . $pg['code']);
    $pg = hit($Z, 'admin/agent-aging.php');
    check('E cash-aging list marks an unverified agent who holds cash', $pg['code'] === 200 && str_contains($rowOf($pg['body'], $a360($A)), 'KYC बाँकी'), 'code ' . $pg['code']);
    $set('agent_list_verified_first_on', '0');
    $pg = hit($Z, 'admin/agents.php');
    $pV = strpos($pg['body'], $reg($V)); $pA = strpos($pg['body'], $reg($A));
    $pp = hit($Z, 'admin/people.php', ['tab' => 'agents']);
    $qV = strpos($pp['body'], $a360($V)); $qA = strpos($pp['body'], $a360($A));
    check('E switch agent_list_verified_first_on = 0: the old order, the marker stays',
        $pV !== false && $pA !== false && $pA < $pV && $qV !== false && $qA !== false && $qA < $qV && str_contains($rowOf($pg['body'], $reg($A)), 'KYC बाँकी'));
    $set('agent_list_verified_first_on', '1');
    // A database without the KYC column: the same rule as AgentReport::kycPending() - nobody marked, nothing re-sorted.
    $pg = hit($Z, 'admin/agents.php', [], null, true);
    $pV = strpos($pg['body'], $reg($V)); $pA = strpos($pg['body'], $reg($A));
    $pp = hit($Z, 'admin/people.php', ['tab' => 'agents'], null, true);
    $qV = strpos($pp['body'], $a360($V)); $qA = strpos($pp['body'], $a360($A));
    check('E no KYC column: the register and Agents & Staff mark nobody and keep the old order',
        $pg['code'] === 200 && $pp['code'] === 200 && $pV !== false && $pA !== false && $pA < $pV && $qV !== false && $qA !== false && $qA < $qV
        && !str_contains($rowOf($pg['body'], $reg($A)), 'data-kyc-pill') && !str_contains($rowOf($pp['body'], $a360($A)), 'data-kyc-pill')
        && !str_contains($rowOf($pg['body'], $reg($A)), "'0')\""), 'codes ' . $pg['code'] . '/' . $pp['code']);

    /* ---------------- F wiring ---------------- */
    echo "\n-- F wiring\n";
    $before = [];
    foreach ($watch as $wk) { $before[$wk] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $wk]); }
    $pg = hit($M, 'admin/agents.php', ['tier' => 'pvt_ltd'], ['action' => 'save_pvt_ltd', 'legal_name' => 'FX1 Fake Ltd ' . $tag, 'upi_id' => 'fx1fake@upi', 'bank_acc' => '000', 'announcement' => 'fx1 ' . $tag]);
    $same = true;
    foreach ($watch as $wk) { $same = $same && $before[$wk] == Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $wk]); }
    check('F company / bank details: a staff manager\'s save is refused, nothing stored', $same && !str_contains((string) json_encode($pg['flash']), 'saved'), json_encode($pg['flash']));
    $pg = hit($M, 'admin/agents.php', ['tier' => 'pvt_ltd']);
    check('F company tab for a manager: read-only, no save form', $pg['code'] === 200 && !str_contains($pg['body'], 'value="save_pvt_ltd"') && str_contains($pg['body'], 'सुपर-एडमिनले मात्र'));
    $pg = hit($Z, 'admin/agents.php', ['tier' => 'pvt_ltd']);
    check('F company tab for the super-admin: the form, with no invented company or bank numbers',
        str_contains($pg['body'], 'value="save_pvt_ltd"') && leaks($pg['body'], ['U63040GJ2024PTC152341', '24AAECS4912J1Z8', 'shreehariglobal@sbi', '41238947291', 'SBIN0001234', '98250 12345', '98012 34567']) === '');
    $pg = hit($M, 'admin/agents.php', [], ['action' => 'link_parent_agent', 'subagent_id' => (string) $Z, 'parent_id' => (string) $A]);
    check('F a non-agent row cannot be linked under an agent', !Database::exists('SELECT 1 FROM admin_profiles WHERE admin_id = :z AND parent_agent_id IS NOT NULL', ['z' => $Z]));
    $pg = hit($M, 'admin/agents.php', [], ['action' => 'link_parent_agent', 'subagent_id' => (string) $B, 'parent_id' => (string) $S]);
    check('F an agent cannot be put under someone\'s counter staff (one level only)', !Database::exists('SELECT 1 FROM admin_profiles WHERE admin_id = :b AND parent_agent_id IS NOT NULL', ['b' => $B]));
    $pg = hit($M, 'admin/agents.php', [], ['action' => 'link_parent_agent', 'subagent_id' => (string) $A, 'parent_id' => (string) $A]);
    check('F an agent cannot be linked to themself', AgentCredit::parentOf($A) === 0);

    $src = static fn(string $rel): string => (string) file_get_contents($ROOT . '/' . $rel);
    $home = $src('admin/agent-home.php');
    check('F agent-home.php is read-only: no POST handling, no ledger writer', !str_contains($home, '$_POST') && !str_contains($home, 'REQUEST_METHOD') && !str_contains($home, 'AgentWallet::record') && !str_contains($home, 'Database::run') && !str_contains($home, 'Database::insert'));
    check('F agent-home.php: same gate as the agent panel, scope applied after ?agent=',
        str_contains($home, "admin_boot('bookings.view')") && str_contains($home, "Auth::requireAdmin('commissions.view')")
        && strpos($home, '$scopeId = Auth::bookingScopeAdminId();') > strpos($home, "isset(\$_GET['agent'])") && str_contains($home, "Settings::getBool('agent_home_on', true)"));
    $panel = $src('admin/agent.php');
    check('F agent panel: counter-staff actions ask who is posting, and the ledger pair is one transaction',
        str_contains($panel, '$maySubManage') && str_contains($panel, '$maySubSettle') && str_contains($panel, "Logger::audit('subagent.toggle'")
        && str_contains($panel, "Logger::audit('subagent.handover'") && !str_contains($panel, '!$isSub && !$isSupervisor') && !str_contains($panel, '919104801507'));
    $sql = $src('database/upgrade-2026-11-26-fx1-agent.sql');
    check('F migration: four switches, INSERT IGNORE only, nothing destructive',
        str_contains($sql, 'INSERT IGNORE INTO settings') && str_contains($sql, "'agent_home_on'") && str_contains($sql, "'agent_subagent_office_approve_on'")
        && str_contains($sql, "'agent_credit_parent_hold_on'") && str_contains($sql, "'agent_list_verified_first_on'")
        && preg_match('/\b(DROP|DELETE|TRUNCATE|UPDATE|ALTER)\b/i', (string) preg_replace('/^--.*$/m', '', $sql)) !== 1);
    check('F the old sign-up door goes straight to the one form', str_contains($src('agent-signup.php'), "header('Location: /join.php?from=apply', true, 302);"));
    check('F credit file documents the new switch', str_contains($src('includes/agentcredit.php'), 'agent_credit_parent_hold_on'));
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  exception: " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}

$cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
