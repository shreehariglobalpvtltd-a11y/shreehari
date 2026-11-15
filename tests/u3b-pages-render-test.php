<?php
/**
 * =====================================================================
 *  u3b-pages-render-test.php — Master v2 (16 Nov 2026), unit U3b: the two
 *  staff pages, each rendered in its own PHP process as a signed-in staff
 *  member (the tests/ticket-requests-test.php pattern).
 *
 *    admin/home.php
 *      - the office (dashboard.view) sees the AI Watch panel (P3-3a) with
 *        the idle-agents card when there are idle agents; every card
 *        links into /admin/; an agent sees no panel
 *      - Messages & AI carries today's reply time (P6-10b) once
 *        WaInbox::responseStats() exists
 *    admin/messenger-inbox.php (P6-6a)
 *      - customers.view opens it; a viewer (no customers.view) is refused
 *      - the thread list and the conversation render, words escaped
 *      - POST: a bad CSRF token sends nothing; a good one inside the
 *        24-hour window sends one RESPONSE and stores it as inbox_reply
 *
 *  No Graph API call (transport seam in the child), no setting written
 *  (Settings cache in the child only). Every row it makes is deleted.
 *
 *    php -c .claude/php-dev.ini tests/u3b-pages-render-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child: one page in its own process ---- */
if (($argv[1] ?? '') === '--child') {
    $cfg  = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $page = (string) $cfg['page'];
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.71';
    $_SERVER['SCRIPT_NAME']    = '/admin/' . $page;
    $_SERVER['REQUEST_URI']    = '/admin/' . $page;
    $_GET  = (array) ($cfg['get'] ?? []);
    $post  = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    require_once INCLUDE_PATH . '/fbmessenger.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) $cfg['admin']]);
    if ($a === null) { fwrite(STDERR, "no such admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if (isset($cfg['role'])) {                     // a hand-built session (no checked_at): this role, no extra grant
        $_SESSION[ADMIN_SESSION_KEY]['role'] = (string) $cfg['role'];
        $_SESSION[ADMIN_SESSION_KEY]['permissions'] = [];
    }
    Settings::get('company_name');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    foreach (['fb_msgr_on' => ['1', 'bool'], 'fb_page_token' => ['EAAU3BTEST' . str_repeat('y', 30), 'string'],
              'fb_app_secret' => ['u3b-render', 'string'], 'staff_setup_wizard' => ['0', 'bool']] as $k => [$v, $t]) {
        $c[$k] = ['value' => $v, 'type' => $t, 'public' => false];
    }
    $rp->setValue(null, $c);
    FbMessenger::$transport = static function (string $m, string $path, array $body): array {
        if (isset($body['message'])) {
            fwrite(STDERR, "\nGRAPH-SEND " . json_encode($body) . "\n");
        }
        return ['ok' => true, 'http' => 200, 'body' => '{"recipient_id":"1","message_id":"m"}'];
    };
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME]  = ($cfg['csrf'] ?? 'valid') === 'valid' ? Security::csrfToken() : 'not-the-token';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    }
    require dirname(__DIR__) . '/admin/' . $page;
    exit(0);
}

define('SHG_APP', true);
chdir(dirname(__DIR__));
require 'includes/bootstrap.php';
require_once INCLUDE_PATH . '/fbmessenger.php';
require_once INCLUDE_PATH . '/wainbox.php';
if (!str_contains((string) DB_NAME, 'test')) { exit("Refusing to run: DB_NAME is not a test database.\n"); }

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what, string $extra = '') use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what" . ($extra !== '' ? "\n        " . $extra : '') . "\n"; }
};
$child = static function (array $cfg): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
};
$roleId = static fn(string $role): int => (int) Database::scalar('SELECT MIN(id) FROM admins WHERE is_active = 1 AND role = :r', ['r' => $role], 0);
$super  = $roleId('superadmin');
$agent  = $roleId('agent');

$P = '9901' . random_int(100000000, 999999999);
$tag = 'u3bpg' . random_int(1000, 9999);
/* opening the inbox marks it read for that admin (kv_store fb_inbox_seen): put it back afterwards */
$seenBefore = Database::fetch("SELECT kvalue FROM kv_store WHERE kscope = 'fb_inbox_seen' AND kkey = :k", ['k' => (string) $super]);

try {
    $ok(FbMessenger::logReady(true), 'fb_inbound is installed on the test database (database/upgrade-2026-11-16-fb-inbound.sql)');

    echo "\n1. Home — the office's AI Watch and reply time\n";
    $h = $child(['page' => 'home.php', 'admin' => $super]);
    $ok(str_contains($h, 'class="aw"') && str_contains($h, 'what needs you now'), 'the office home shows the AI Watch panel', substr(strip_tags($h), 0, 300));
    $ok(!str_contains($h, 'Fatal error') && !str_contains($h, 'Application error') && !str_contains($h, 'Warning:'), 'no PHP error on the page');
    preg_match_all('/class="btn aw-go" href="([^"]+)"/', $h, $m);
    $ok($m[1] !== [] && array_filter($m[1], static fn(string $u): bool => !str_starts_with($u, '/admin/')) === [], 'every suggestion only links to a staff page (' . count($m[1]) . ' cards)');
    require_once INCLUDE_PATH . '/aiwatch.php';
    if (AiWatch::idleAgents(max(1, Settings::getInt('alert_agent_inactive_days', 7))) !== []) {
        $ok(str_contains($h, 'with no sale for') && str_contains($h, '/admin/agents.php'), 'idle agents appear as a card to the agents list');
    }
    if (method_exists('WaInbox', 'responseStats')) {
        $ok(str_contains($h, 'aria-label="Reply time today"') && str_contains($h, 'target &lt;30 s') && str_contains($h, 'target &lt;5 min'), 'Messages & AI shows today\'s reply time against both targets');
    } else {
        $ok(!str_contains($h, 'Reply time today'), 'no reply-time block before WaInbox::responseStats exists');
    }
    $ha = $child(['page' => 'home.php', 'admin' => $agent]);
    $ok($agent > 0 && !str_contains($ha, 'class="aw"') && !str_contains($ha, 'Application error'), 'an agent\'s home has no office panel');
    $bad = [];
    foreach (['manager', 'accountant', 'operations', 'support', 'official', 'scanner', 'counter', 'viewer', 'agent'] as $role) {
        $out = $child(['page' => 'home.php', 'admin' => $super, 'role' => $role]);
        if (preg_match('/Application error|Warning:|Notice:|Deprecated:|Fatal error|Uncaught/i', $out) === 1 || !str_contains($out, 'mg-me')) {
            $bad[] = $role . ': ' . mb_substr(trim(strip_tags($out)), 0, 160);
        }
        $office = in_array('dashboard.view', Auth::rolePermissions($role), true);
        if ($office !== str_contains($out, 'class="aw"')) {
            $bad[] = $role . ': AI Watch ' . ($office ? 'missing' : 'shown without dashboard.view');
        }
    }
    $ok($bad === [], 'home renders for every role with no PHP warning; AI Watch only with dashboard.view', implode(' | ', $bad));

    echo "\n2. Messenger inbox\n";
    Database::run("INSERT INTO fb_inbound (mid, psid, direction, kind, body, status, created_at)
                   VALUES (:m, :p, 'in', 'message', :b, 'received', NOW() - INTERVAL 5 MINUTE)",
        ['m' => $tag . '-1', 'p' => $P, 'b' => 'bholi seat cha? <script>alert(1)</script>']);
    Database::run("INSERT INTO fb_inbound (psid, direction, kind, body, purpose, status, created_at)
                   VALUES (:p, 'out', 'text', 'Cha, 4 seat khali.', 'bot_reply', 'sent', NOW() - INTERVAL 4 MINUTE)", ['p' => $P]);
    $g = $child(['page' => 'messenger-inbox.php', 'admin' => $super, 'get' => ['p' => $P]]);
    $ok(str_contains($g, 'Messenger · …' . substr($P, -6)) && str_contains($g, 'Cha, 4 seat khali.'), 'the thread and the conversation render');
    $ok(!str_contains($g, '<script>alert(1)</script>') && str_contains($g, '&lt;script&gt;'), 'the person\'s words are escaped');
    $ok(str_contains($g, 'name="action" value="reply"') && str_contains($g, 'name="' . CSRF_TOKEN_NAME . '"'), 'inside 24 h the reply box is there, with the CSRF field');
    if (method_exists('WaInbox', 'channelTabs')) {
        $ok(str_contains($g, 'wa-inbox.php') && str_contains($g, 'aria-current="page"'), 'the WhatsApp | Messenger | Sent tabs are on top');
    }
    // a viewer session (dashboard.view + reports.view, no customers.view): other suites make and drop staff rows here
    $v = $child(['page' => 'messenger-inbox.php', 'admin' => $super, 'role' => 'viewer', 'get' => ['p' => $P]]);
    $ok(!str_contains($v, 'Cha, 4 seat khali.') && !str_contains($v, 'name="action" value="reply"'), 'a viewer (no customers.view) cannot read it');
    $vw = $child(['page' => 'wa-inbox.php', 'admin' => $super, 'role' => 'viewer']);
    $ok(!str_contains($vw, 'wi-tabs'), 'the same session cannot open the WhatsApp inbox either (same permission)');

    $bad = $child(['page' => 'messenger-inbox.php', 'admin' => $super, 'get' => ['p' => $P], 'csrf' => 'bad',
                   'post' => ['action' => 'reply', 'psid' => $P, 'text' => 'from the office ' . $tag]]);
    $ok(!str_contains($bad, 'GRAPH-SEND') && (int) Database::scalar("SELECT COUNT(*) FROM fb_inbound WHERE psid = :p AND purpose = 'inbox_reply'", ['p' => $P], 0) === 0,
        'a bad CSRF token sends nothing and stores nothing');
    $good = $child(['page' => 'messenger-inbox.php', 'admin' => $super, 'get' => ['p' => $P],
                    'post' => ['action' => 'reply', 'psid' => $P, 'text' => 'from the office ' . $tag]]);
    $row = Database::fetch("SELECT * FROM fb_inbound WHERE psid = :p AND purpose = 'inbox_reply'", ['p' => $P]);
    $ok(substr_count($good, 'GRAPH-SEND') === 1 && str_contains($good, '"messaging_type":"RESPONSE"'), 'a good reply sends one RESPONSE');
    $ok($row !== null && (int) $row['admin_id'] === $super && $row['body'] === 'from the office ' . $tag, 'stored as inbox_reply with who sent it');
    $ok(str_contains($good, 'Sent on Messenger'), 'the office sees it went');

    Database::run('UPDATE fb_inbound SET created_at = NOW() - INTERVAL 30 HOUR WHERE psid = :p', ['p' => $P]);
    $closed = $child(['page' => 'messenger-inbox.php', 'admin' => $super, 'get' => ['p' => $P]]);
    $ok(!str_contains($closed, 'name="action" value="reply"') && str_contains($closed, '24-hour window is closed'), 'after 24 h the reply box gives way to the reason');
} catch (Throwable $e) {
    $fail++;
    echo '  FAIL  exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    Database::run('DELETE FROM fb_inbound WHERE psid = :p', ['p' => $P]);
    Database::run("DELETE FROM audit_logs WHERE action = 'fb.inbox_reply' AND entity_id = :e", ['e' => substr(hash('sha256', $P), 0, 10)]);
    Database::run("DELETE FROM rate_limits WHERE identifier = :p", ['p' => $P]);
    if ($seenBefore === null) {
        Database::run("DELETE FROM kv_store WHERE kscope = 'fb_inbox_seen' AND kkey = :k", ['k' => (string) $super]);
    } else {
        Database::run("UPDATE kv_store SET kvalue = :v WHERE kscope = 'fb_inbox_seen' AND kkey = :k", ['v' => $seenBefore['kvalue'], 'k' => (string) $super]);
    }
}
$ok((int) Database::scalar('SELECT COUNT(*) FROM fb_inbound WHERE psid = :p', ['p' => $P], 0) === 0, 'every test row was removed');

echo "\n" . ($fail === 0 ? "ALL $pass PASSED" : "$fail FAILED, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
