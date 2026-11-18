<?php
/**
 * =====================================================================
 *  u2-wa-form-page-test.php — admin/wa-form.php after WA-3 (19 Nov 2026),
 *  each request rendered in its own PHP process as a signed-in staff member
 *  (the tests/u3b-pages-render-test.php pattern).
 *
 *    - the owner (superadmin) sees "♻️ Re-create form"; a manager (settings
 *      permission, not the owner) does not, and a POSTed re-create from a
 *      manager is refused;
 *    - a re-create with a bad CSRF token changes nothing;
 *    - the owner's re-create drops a form from another WhatsApp account and
 *      makes a fresh one here (Graph stubbed in the child: no Meta call);
 *    - "Save" with ON ticked keeps the form OFF unless Meta lists it as
 *      PUBLISHED in this account.
 *
 *  Settings the children write (wa_flow_*) are put back afterwards.
 *
 *    php -c .claude/php-dev.ini tests/u2-wa-form-page-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child: one request in its own process ---- */
if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.72';
    $_SERVER['SCRIPT_NAME']    = '/admin/wa-form.php';
    $_SERVER['REQUEST_URI']    = '/admin/wa-form.php';
    $_GET  = (array) ($cfg['get'] ?? []);
    $post  = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    define('SHG_APP', true);
    define('META_ACCESS_TOKEN', 'U2-PAGE-TOKEN');
    define('META_WABA_ID', '777000222');
    define('META_PHONE_NUMBER_ID', '');
    define('META_API_BASE', 'https://graph.invalid/v21.0');
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    require_once INCLUDE_PATH . '/waflow.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) $cfg['admin']]);
    if ($a === null) { fwrite(STDERR, "no such admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) ($cfg['role'] ?? $a['role']), 'permissions' => [], 'last_seen' => time(),
    ];
    $listed = (array) ($cfg['listed'] ?? []);   // what this account's form list holds
    WaFlow::$graphStub = static function (string $m, string $path, array $f) use ($listed): array {
        fwrite(STDERR, "\nGRAPH " . $m . ' ' . strtok($path, '?') . "\n");
        if ($m === 'GET' && str_starts_with($path, '/777000222/flows')) {
            return ['http' => 200, 'json' => ['data' => $listed]];
        }
        if ($m === 'POST' && $path === '/777000222/flows') {
            return ['http' => 200, 'json' => ['id' => '606000606', 'success' => true]];
        }
        if ($m === 'GET' && str_starts_with($path, '/606000606?')) {
            return ['http' => 200, 'json' => ['id' => '606000606', 'status' => 'PUBLISHED']];
        }
        if ($m === 'GET' && preg_match('#^/(\d+)\?#', $path, $mm) === 1) {
            return ['http' => 200, 'json' => ['id' => $mm[1], 'status' => 'DRAFT']];
        }
        return ['http' => 400, 'json' => ['error' => ['message' => 'stub: refused']]];
    };
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME]  = ($cfg['csrf'] ?? 'valid') === 'valid' ? Security::csrfToken() : 'not-the-token';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    }
    require dirname(__DIR__) . '/admin/wa-form.php';
    exit(0);
}

define('SHG_APP', true);
chdir(dirname(__DIR__));
require 'includes/bootstrap.php';
if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; } else { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
/** @return array{out: string, err: string} */
function render(array $cfg): array {
    $php  = PHP_BINARY;
    $ini  = php_ini_loaded_file();
    $cmd  = escapeshellarg($php) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p    = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out  = stream_get_contents($pipes[1]);
    $err  = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return ['out' => (string) $out, 'err' => (string) $err];
}
function setting(string $k): string {
    return (string) Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k], '');
}

$keys  = ['wa_flow_on', 'wa_flow_book_id', 'wa_flow_draft', 'wa_flow_waba', 'wa_flow_template_status', 'wa_flow_dropped'];
$saved = [];
foreach ($keys as $k) {
    $saved[$k] = Database::fetch('SELECT svalue, stype FROM settings WHERE skey = :k', ['k' => $k]);
}
$super   = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);
$put = static function (string $k, string $v, string $t): void {
    Settings::set($k, $v, $t, 'whatsapp');
};

try {
    if ($super === 0) {
        throw new RuntimeException('no active superadmin in the test database');
    }
    // The old account's draft, as it sits on live.
    $put('wa_flow_book_id', '1440536441555084', 'string');
    $put('wa_flow_draft', '1', 'bool');
    $put('wa_flow_on', '0', 'bool');

    echo "\n— who sees the button\n";
    $r = render(['admin' => $super, 'listed' => [['id' => '5', 'status' => 'DRAFT']]]);
    check('the owner sees ♻️ Re-create form', str_contains($r['out'], 'name="action" value="recreate"'), mb_substr($r['err'], 0, 200));
    check('… and is told the form is not in this WhatsApp account', str_contains($r['out'], 'not in the current WhatsApp account'));
    check('the page renders the new form preview (name + mobile required, country picker)',
        str_contains($r['out'], 'Mobile · मोबाइल <b>*</b>') && str_contains($r['out'], '+977 Nepal'));
    $m = render(['admin' => $super, 'role' => 'manager', 'listed' => []]);
    check('a manager does not see it', !str_contains($m['out'], 'value="recreate"') && str_contains($m['out'], 'WhatsApp booking form'), mb_substr($m['err'], 0, 200));

    echo "\n— the re-create POST\n";
    $r = render(['admin' => $super, 'role' => 'manager', 'post' => ['action' => 'recreate'], 'listed' => []]);
    check('a manager\'s re-create is refused, nothing made', str_contains($r['out'], 'Only the owner') && !str_contains($r['err'], 'POST /777000222/flows')
        && setting('wa_flow_book_id') === '1440536441555084');
    $r = render(['admin' => $super, 'post' => ['action' => 'recreate'], 'csrf' => 'bad', 'listed' => []]);
    check('a bad CSRF token changes nothing', str_contains($r['out'], 'Session expired') && setting('wa_flow_book_id') === '1440536441555084');
    $r = render(['admin' => $super, 'post' => ['action' => 'recreate'], 'listed' => []]);
    check('the owner\'s re-create makes a fresh form in this account', str_contains($r['err'], 'POST /777000222/flows')
        && setting('wa_flow_book_id') === '606000606', mb_substr(strip_tags($r['out']), 0, 0) . setting('wa_flow_book_id'));
    check('… switched ON only because Meta says PUBLISHED, for this account', setting('wa_flow_on') === '1' && setting('wa_flow_waba') === '777000222');
    check('… the old account\'s id kept for the record', str_starts_with(setting('wa_flow_dropped'), '1440536441555084'));
    check('… and the old draft was never "published"', !str_contains($r['err'], 'POST /1440536441555084/publish'));

    echo "\n— Save with ON ticked\n";
    $put('wa_flow_book_id', '123123123', 'string');
    $put('wa_flow_on', '0', 'bool');
    $put('wa_flow_waba', '', 'string');
    $r = render(['admin' => $super, 'post' => ['action' => 'save', 'wa_flow_book_id' => '123123123', 'wa_flow_on' => '1'], 'listed' => [['id' => '123123123', 'status' => 'DRAFT']]]);
    check('a DRAFT form stays OFF, and the page says why', setting('wa_flow_on') === '0' && str_contains($r['out'], 'stays OFF'));
    $r = render(['admin' => $super, 'post' => ['action' => 'save', 'wa_flow_book_id' => '123123123', 'wa_flow_on' => '1'], 'listed' => [['id' => '123123123', 'status' => 'PUBLISHED']]]);
    check('a form PUBLISHED in this account goes ON', setting('wa_flow_on') === '1' && setting('wa_flow_waba') === '777000222');
} catch (Throwable $e) {
    $FAIL++;
    echo "  UNCAUGHT " . $e->getMessage() . "\n";
} finally {
    foreach ($saved as $k => $row) {
        if ($row === null) {
            Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        } else {
            Database::run('UPDATE settings SET svalue = :v, stype = :t WHERE skey = :k', ['v' => $row['svalue'], 't' => $row['stype'], 'k' => $k]);
        }
    }
    Database::run("DELETE FROM audit_logs WHERE action IN ('wa.flow_recreate','wa.flow_dropped','wa.flow_settings') AND created_at >= NOW() - INTERVAL 10 MINUTE AND (new_value LIKE '%777000222%' OR new_value LIKE '%606000606%' OR new_value LIKE '%123123123%' OR new_value LIKE '%1440536441555084%')");
    Settings::flush();
}

echo "\nu2-wa-form-page: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
