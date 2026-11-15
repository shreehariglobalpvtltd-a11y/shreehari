<?php
/**
 * =====================================================================
 *  u5-endpoints-test.php — v2.0 unit U5 staff JSON endpoints (16 Nov 2026).
 *
 *  admin/api/ai-settings.php (C3, the AI bubble):
 *    - bad CSRF refused; a login without settings / routes / schedules
 *      rights refused; GET refused
 *    - {cmd} → proposal id + old → new, nothing written; {confirm} writes,
 *      audits settings.ai_proposed / settings.ai_command / settings.ai;
 *      a confirm from another admin, or twice, is refused; {cancel} writes
 *      nothing and audits settings.ai_declined; {setting_key,new_value};
 *      a protected key refused; a routes-only login may not touch a setting;
 *      a question ("which pickup?") comes back with no proposal; 30/min limit
 *  admin/api/inline-save.php (C2, click-to-edit):
 *    - bad CSRF / no right / unknown field refused
 *    - route_stop fare "2,200" → 2200 (audit route_stop.fares old/new),
 *      "abc" / "0" refused, blank = default; stop_time HH:MM; landmark;
 *      same value = no change; the X-CSRF-Token header works
 *
 *  shari_test only. Every row it makes (a settings knob "zu5_test_knob",
 *  two throwaway logins, one OFF stop) is deleted at the end; the shared
 *  rate-limit buckets are cleared for its own logins only.
 *
 *    php -c .claude/php-dev.ini tests/u5-endpoints-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child: run one endpoint as a given staff member -------------------
   argv: --child <base64 json {page, admin, post, csrf, session, session_out}> */
if (($argv[1] ?? '') === '--child') {
    $cfg  = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $page = (string) ($cfg['page'] ?? '');
    if (!in_array($page, ['ai-settings.php', 'inline-save.php'], true)) { fwrite(STDERR, "bad page\n"); exit(2); }
    $post = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = ($cfg['method'] ?? 'POST');
    $_SERVER['SCRIPT_NAME']    = '/admin/api/' . $page;
    $_SERVER['REQUEST_URI']    = '/admin/api/' . $page;
    $_SERVER['HTTP_HOST']      = 'localhost';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if (is_array($cfg['session'] ?? null)) {
        $_SESSION['ai_props'] = $cfg['session'];
    }
    if (($cfg['session_out'] ?? '') !== '') {
        $outFile = (string) $cfg['session_out'];
        register_shutdown_function(static function () use ($outFile): void {
            file_put_contents($outFile, json_encode($_SESSION['ai_props'] ?? [], JSON_UNESCAPED_UNICODE));
        });
    }
    $_POST = $post;
    $mode = (string) ($cfg['csrf'] ?? 'valid');
    if ($mode === 'valid') {
        $_POST[CSRF_TOKEN_NAME] = Security::csrfToken();
    } elseif ($mode === 'header') {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = Security::csrfToken();
    } else {
        $_POST[CSRF_TOKEN_NAME] = 'not-the-token';
    }
    require dirname(__DIR__) . '/admin/api/' . $page;
    exit(0);
}

$ROOT = dirname(__DIR__);
define('SHG_APP', true);
require_once $ROOT . '/includes/bootstrap.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
/** @return array<string,mixed> the endpoint's JSON (or ['_raw' => output]) */
function call(array $cfg): array {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ['_raw' => 'proc_open failed']; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['_raw' => $out . $err];
}

echo "\n=== U5 endpoints: ai-settings + inline-save ===\n\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
    echo "  SKIP  DB_NAME is not shari_test — refusing to write\n";
    exit(0);
}
$super = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 AND must_change_pw = 0 ORDER BY id LIMIT 1", [], 0);
$super2 = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 AND must_change_pw = 0 AND id <> :i ORDER BY id LIMIT 1", ['i' => $super], 0);
$route = Database::fetch("SELECT id FROM routes WHERE is_active = 1 AND to_city = 'Rupaidiha' ORDER BY sort_order, id LIMIT 1");
if ($super <= 0 || $route === null) {
    echo "  SKIP  no active super-admin or route\n";
    exit(0);
}

$tmp     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'u5-sess-' . bin2hex(random_bytes(4)) . '.json';
$startId = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$made    = ['admins' => [], 'stop' => 0, 'knob' => false];
$cleanup = static function () use (&$made, $startId, $tmp, $super, $super2): void {
    if ($made['stop'] > 0) {
        Database::delete('route_stops', 'id = :i', ['i' => $made['stop']]);
    }
    if ($made['knob']) {
        Database::delete('settings', "skey = 'zu5_test_knob'");
        Settings::flush();
    }
    foreach ($made['admins'] as $id) {
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    Database::delete('audit_logs', "id > :s AND (entity_id = 'zu5_test_knob' OR old_value LIKE '%Zu5 Inline%' OR new_value LIKE '%Zu5 Inline%'
                                     OR new_value LIKE '%zu5_test_knob%' OR detail LIKE '%Zu5 Inline%')", ['s' => $startId]);
    foreach (array_merge([$super, $super2], $made['admins']) as $id) {
        foreach (['ai_settings', 'inline_save'] as $b) {
            Database::delete('rate_limits', 'bucket = :b AND identifier = :i', ['b' => $b, 'i' => 'admin:' . $id]);
        }
    }
    if (is_file($tmp)) { @unlink($tmp); }
    $made = ['admins' => [], 'stop' => 0, 'knob' => false];
};
register_shutdown_function($cleanup);

try {
    /* fixtures */
    Database::insert('settings', ['skey' => 'zu5_test_knob', 'svalue' => '3', 'stype' => 'int', 'sgroup' => 'general', 'label' => 'U5 test knob', 'is_public' => 0]);
    $made['knob'] = true;
    $mk = static function (string $u, array $perms) use (&$made): int {
        $id = Database::insert('admins', ['username' => $u . substr(bin2hex(random_bytes(3)), 0, 5), 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
            'full_name' => 'U5 Test', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0, 'permissions' => json_encode($perms)]);
        $made['admins'][] = $id;
        return $id;
    };
    // both pass the pages' own boot gate, so the endpoints' OWN checks are what refuse them
    $agent  = $mk('zu5-agent-', ['dashboard.view', 'routes.view']);
    $router = $mk('zu5-route-', ['dashboard.view', 'routes.view', 'routes.edit']);
    $isNull = static fn(array $r, string $k): bool => array_key_exists($k, $r) && $r[$k] === null;
    $made['stop'] = Database::insert('route_stops', ['route_id' => (int) $route['id'], 'stop_type' => 'boarding', 'stop_name' => 'Zu5 Inline Stop',
        'fare' => 2000, 'is_active' => 0, 'sort_order' => 995]);
    $sid = $made['stop'];
    $knob = static fn(): string => (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'zu5_test_knob'", [], '');

    echo "-- ai-settings.php\n";
    $r = call(['page' => 'ai-settings.php', 'admin' => $super, 'post' => ['cmd' => 'zu5_test_knob 7'], 'csrf' => 'bad']);
    check('bad CSRF refused', ($r['ok'] ?? null) === false && str_contains((string) ($r['message'] ?? ''), 'Session'), json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = call(['page' => 'ai-settings.php', 'admin' => $agent, 'post' => ['cmd' => 'zu5_test_knob 7']]);
    check('a login without settings / routes / schedules rights refused', ($r['ok'] ?? null) === false && str_contains((string) ($r['message'] ?? ''), '⛔') && $knob() === '3', json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = call(['page' => 'ai-settings.php', 'admin' => $super, 'method' => 'GET', 'post' => []]);
    check('GET refused', ($r['ok'] ?? null) === false && str_contains((string) ($r['message'] ?? ''), 'POST'), json_encode($r, JSON_UNESCAPED_UNICODE));

    $r = call(['page' => 'ai-settings.php', 'admin' => $super, 'post' => ['cmd' => 'zu5_test_knob 7'], 'session_out' => $tmp]);
    $sess = json_decode((string) @file_get_contents($tmp), true) ?: [];
    $pid  = (string) ($r['proposal_id'] ?? '');
    check('{cmd} → proposal id + old → new', ($r['ok'] ?? false) === true && preg_match('/^[a-f0-9]{16}$/', $pid) === 1
        && ($r['changes'][0]['old'] ?? '') === '3' && ($r['changes'][0]['new'] ?? '') === '7', json_encode($r, JSON_UNESCAPED_UNICODE));
    check('nothing written before Yes', $knob() === '3');
    check('the proposal is kept in the session, bound to the admin', isset($sess[$pid]) && (int) ($sess[$pid]['admin'] ?? 0) === $super);
    check('settings.ai_proposed audited with the actions',
        Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.ai_proposed' AND new_value LIKE '%zu5_test_knob%'", ['s' => $startId]));

    if ($super2 > 0) {
        $r = call(['page' => 'ai-settings.php', 'admin' => $super2, 'post' => ['confirm' => $pid], 'session' => $sess]);
        check('a confirm from another admin is refused', ($r['ok'] ?? null) === false && $knob() === '3', json_encode($r, JSON_UNESCAPED_UNICODE));
    }
    $r = call(['page' => 'ai-settings.php', 'admin' => $super, 'post' => ['confirm' => $pid], 'session' => $sess, 'csrf' => 'header']);
    check('{confirm} writes it (X-CSRF-Token header)', ($r['ok'] ?? false) === true && ($r['success'] ?? false) === true && $knob() === '7', json_encode($r, JSON_UNESCAPED_UNICODE));
    check('settings.ai_command + settings.ai audited',
        Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.ai_command' AND new_value LIKE '%zu5_test_knob%'", ['s' => $startId])
        && Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.ai' AND entity_id = 'zu5_test_knob' AND old_value LIKE '%3%'", ['s' => $startId]));
    $r = call(['page' => 'ai-settings.php', 'admin' => $super, 'post' => ['confirm' => str_repeat('a', 16)], 'session' => $sess]);
    check('an unknown / used proposal is refused', ($r['ok'] ?? null) === false, json_encode($r, JSON_UNESCAPED_UNICODE));

    $r = call(['page' => 'ai-settings.php', 'admin' => $super, 'post' => ['cmd' => 'zu5_test_knob 9'], 'session_out' => $tmp]);
    $sess = json_decode((string) @file_get_contents($tmp), true) ?: [];
    $r2 = call(['page' => 'ai-settings.php', 'admin' => $super, 'post' => ['cancel' => (string) ($r['proposal_id'] ?? '')], 'session' => $sess]);
    check('{cancel} writes nothing', ($r2['ok'] ?? false) === true && $knob() === '7', json_encode($r2, JSON_UNESCAPED_UNICODE));
    check('settings.ai_declined audited',
        Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.ai_declined' AND new_value LIKE '%zu5_test_knob%'", ['s' => $startId]));

    $r = call(['page' => 'ai-settings.php', 'admin' => $super, 'post' => ['setting_key' => 'zu5_test_knob', 'new_value' => '11'], 'session_out' => $tmp]);
    $sess = json_decode((string) @file_get_contents($tmp), true) ?: [];
    $r2 = call(['page' => 'ai-settings.php', 'admin' => $super, 'post' => ['confirm' => (string) ($r['proposal_id'] ?? '')], 'session' => $sess]);
    check('{setting_key,new_value} → confirm → saved', ($r['changes'][0]['new'] ?? '') === '11' && ($r2['success'] ?? false) === true && $knob() === '11', json_encode([$r, $r2], JSON_UNESCAPED_UNICODE));
    $r = call(['page' => 'ai-settings.php', 'admin' => $super, 'post' => ['setting_key' => 'anthropic_api_key', 'new_value' => 'x']]);
    check('a protected key is refused', ($r['ok'] ?? null) === false && $isNull($r, 'proposal_id'), json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = call(['page' => 'ai-settings.php', 'admin' => $router, 'post' => ['cmd' => 'zu5_test_knob 4']]);
    check('a routes-only login may not change a setting', $isNull($r, 'proposal_id') && str_contains((string) ($r['message'] ?? ''), '⛔') && $knob() === '11', json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = call(['page' => 'ai-settings.php', 'admin' => $super, 'post' => ['cmd' => 'Vapi ko bhada 2500']]);
    check('"which pickup?" comes back as a question, no proposal', ($r['ok'] ?? false) === true && $isNull($r, 'proposal_id')
        && str_contains((string) ($r['message'] ?? ''), 'pickup'), json_encode($r, JSON_UNESCAPED_UNICODE));
    Database::query("INSERT INTO rate_limits (bucket, identifier, hits, window_start) VALUES ('ai_settings', :i, 999, NOW())
                     ON DUPLICATE KEY UPDATE hits = 999, window_start = NOW(), blocked_until = NULL", ['i' => 'admin:' . $super]);
    $r = call(['page' => 'ai-settings.php', 'admin' => $super, 'post' => ['cmd' => 'zu5_test_knob 5']]);
    check('rate limited (30 / min)', ($r['ok'] ?? null) === false && str_contains((string) ($r['message'] ?? ''), 'Too many') && $knob() === '11', json_encode($r, JSON_UNESCAPED_UNICODE));
    Database::delete('rate_limits', 'bucket = :b AND identifier = :i', ['b' => 'ai_settings', 'i' => 'admin:' . $super]);

    echo "\n-- inline-save.php\n";
    $fare = static fn() => Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => $sid]);
    $ie = static fn(array $p, int $who, string $csrf = 'valid'): array => call(['page' => 'inline-save.php', 'admin' => $who, 'post' => $p, 'csrf' => $csrf]);
    $r = $ie(['entity' => 'route_stop', 'field' => 'fare', 'id' => $sid, 'value' => '2100'], $super, 'bad');
    check('bad CSRF refused', ($r['ok'] ?? null) === false && (float) $fare() === 2000.0, json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ie(['entity' => 'route_stop', 'field' => 'fare', 'id' => $sid, 'value' => '2100'], $agent);
    check('a login without routes.edit refused', ($r['ok'] ?? null) === false && (float) $fare() === 2000.0, json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ie(['entity' => 'route_stop', 'field' => 'stop_name', 'id' => $sid, 'value' => 'x'], $super);
    check('a field outside the whitelist refused', ($r['ok'] ?? null) === false, json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ie(['entity' => 'admins', 'field' => 'fare', 'id' => $sid, 'value' => '1'], $super);
    check('an entity outside the whitelist refused', ($r['ok'] ?? null) === false, json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ie(['entity' => 'route_stop', 'field' => 'fare', 'id' => $sid, 'value' => '2,200'], $router);
    check('fare "2,200" → 2200 (routes.edit login)', ($r['ok'] ?? false) === true && (float) ($r['new'] ?? 0) === 2200.0 && (float) ($r['old'] ?? 0) === 2000.0
        && (float) $fare() === 2200.0, json_encode($r, JSON_UNESCAPED_UNICODE));
    $log = Database::fetch("SELECT old_value, new_value FROM audit_logs WHERE id > :s AND action = 'route_stop.fares' AND old_value LIKE '%Zu5 Inline%' ORDER BY id DESC LIMIT 1", ['s' => $startId]);
    check('audited route_stop.fares old → new', $log !== null && str_contains((string) $log['old_value'], '2000') && str_contains((string) $log['new_value'], '2200'));
    $r = $ie(['entity' => 'route_stop', 'field' => 'fare', 'id' => $sid, 'value' => 'abc'], $super);
    check('fare "abc" refused', ($r['ok'] ?? null) === false && (float) $fare() === 2200.0, json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ie(['entity' => 'route_stop', 'field' => 'fare', 'id' => $sid, 'value' => '0'], $super);
    check('fare 0 refused (₹1–₹100000)', ($r['ok'] ?? null) === false && (float) $fare() === 2200.0, json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ie(['entity' => 'route_stop', 'field' => 'fare', 'id' => $sid, 'value' => '2200'], $super);
    check('same value = no change', ($r['ok'] ?? false) === true && str_contains((string) ($r['message'] ?? ''), 'No change'), json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ie(['entity' => 'route_stop', 'field' => 'fare', 'id' => $sid, 'value' => ''], $super, 'header');
    check('blank fare = the default (NULL), header token', ($r['ok'] ?? false) === true && $isNull($r, 'new') && $fare() === null, json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ie(['entity' => 'route_stop', 'field' => 'stop_time', 'id' => $sid, 'value' => '25:00'], $super);
    check('time 25:00 refused', ($r['ok'] ?? null) === false, json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ie(['entity' => 'route_stop', 'field' => 'stop_time', 'id' => $sid, 'value' => '22:30'], $super);
    check('time 22:30 saved', ($r['ok'] ?? false) === true && ($r['new'] ?? '') === '22:30'
        && (string) Database::scalar('SELECT stop_time FROM route_stops WHERE id = :i', ['i' => $sid]) === '22:30:00', json_encode($r, JSON_UNESCAPED_UNICODE));
    check('audited route_stop.edit with the time',
        Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'route_stop.edit' AND new_value LIKE '%22:30%' AND new_value LIKE '%Zu5 Inline%'", ['s' => $startId]));
    $r = $ie(['entity' => 'route_stop', 'field' => 'landmark', 'id' => $sid, 'value' => '  Near gate 2  '], $super);
    check('landmark saved (trimmed)', ($r['ok'] ?? false) === true
        && (string) Database::scalar('SELECT landmark FROM route_stops WHERE id = :i', ['i' => $sid]) === 'Near gate 2', json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ie(['entity' => 'route_stop', 'field' => 'fare', 'id' => 99999999, 'value' => '2000'], $super);
    check('unknown stop refused', ($r['ok'] ?? null) === false, json_encode($r, JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    $cleanup();
}
check('cleanup left nothing behind', !Database::exists("SELECT 1 FROM settings WHERE skey = 'zu5_test_knob'")
    && !Database::exists("SELECT 1 FROM route_stops WHERE stop_name = 'Zu5 Inline Stop'"));

echo "\n  u5-endpoints-test: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
