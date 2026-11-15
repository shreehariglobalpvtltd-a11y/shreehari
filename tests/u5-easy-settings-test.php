<?php
/**
 * =====================================================================
 *  u5-easy-settings-test.php — admin/easy-settings.php on the shared AiOps
 *  command path (16 Nov 2026, v2.0 unit U5).
 *
 *    - the page renders for a super-admin (switches, examples, AI box)
 *    - act=cmd shows "old → new" + Yes / No (No is now a POST form),
 *      writes nothing, audits settings.ai_proposed
 *    - act=no writes nothing and audits settings.ai_declined
 *    - act=yes writes and audits settings.ai_command with the actions
 *    - a question ("which pickup?") is shown as the flash, no proposal
 *    - a one-tap switch still saves through AiOps::saveSwitch (settings.easy)
 *
 *  shari_test only; its knob "zu5_es_knob" and its audit rows are deleted;
 *  the one switch it flips (wa_marketing_on) is put back.
 *
 *    php -c .claude/php-dev.ini tests/u5-easy-settings-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

if (($argv[1] ?? '') === '--child') {
    $cfg  = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $post = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    $_SERVER['SCRIPT_NAME']    = '/admin/easy-settings.php';
    $_SERVER['REQUEST_URI']    = '/admin/easy-settings.php';
    $_SERVER['HTTP_HOST']      = 'localhost';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if (is_array($cfg['es_prop'] ?? null)) {
        $_SESSION['es_prop'] = $cfg['es_prop'] + ['t' => time()];
    }
    if (($cfg['session_out'] ?? '') !== '') {
        $f = (string) $cfg['session_out'];
        register_shutdown_function(static function () use ($f): void { file_put_contents($f, json_encode($_SESSION['es_prop'] ?? null, JSON_UNESCAPED_UNICODE)); });
    }
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME] = Security::csrfToken();
    }
    require dirname(__DIR__) . '/admin/easy-settings.php';
    exit(0);
}

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; } else { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function page(array $cfg): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out;
}

echo "\n=== U5 easy-settings page ===\n\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
$super = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 AND must_change_pw = 0 ORDER BY id LIMIT 1", [], 0);
if ($super <= 0) { echo "  SKIP  no super-admin\n"; exit(0); }

$tmp     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'u5-es-' . bin2hex(random_bytes(4)) . '.json';
$startId = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$wasMkt  = Database::fetch("SELECT svalue FROM settings WHERE skey = 'wa_marketing_on'");
$cleanup = static function () use ($startId, $tmp, $wasMkt): void {
    Database::delete('settings', "skey = 'zu5_es_knob'");
    if ($wasMkt === null) {
        Database::delete('settings', "skey = 'wa_marketing_on'");
    } else {
        Database::update('settings', ['svalue' => $wasMkt['svalue']], "skey = 'wa_marketing_on'");
    }
    Database::delete('audit_logs', "id > :s AND (entity_id IN ('zu5_es_knob', 'wa_marketing_on') OR new_value LIKE '%zu5_es_knob%')", ['s' => $startId]);
    if (is_file($tmp)) { @unlink($tmp); }
};
register_shutdown_function($cleanup);
$knob = static fn(): string => (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'zu5_es_knob'", [], '');

try {
    Database::insert('settings', ['skey' => 'zu5_es_knob', 'svalue' => '4', 'stype' => 'int', 'sgroup' => 'general', 'label' => 'U5 ES knob', 'is_public' => 0]);

    $h = page(['admin' => $super]);
    check('page renders (AI box, switches, key box)', str_contains($h, 'id="esCmd"') && str_contains($h, 'One-tap switches') && str_contains($h, 'Paste any API key'));
    check('no PHP warning / error in the page', !preg_match('/(<b>(Warning|Notice|Fatal error|Deprecated)<\/b>|PHP (Warning|Notice|Fatal|Deprecated)|(Warning|Notice|Deprecated): .* on line \d|Application error)/', $h));
    check('new examples are offered', str_contains($h, 'Seat U15 block karo kal') && str_contains($h, '100 badha do'));

    $h = page(['admin' => $super, 'post' => ['act' => 'cmd', 'cmd' => 'zu5_es_knob 8'], 'session_out' => $tmp]);
    $sp = json_decode((string) @file_get_contents($tmp), true);
    check('act=cmd shows old → new with Yes and a No POST form', str_contains($h, 'Change this?') && str_contains($h, '<s>4</s>') && str_contains($h, '<b>8</b>')
        && str_contains($h, 'name="act" value="no"'));
    check('act=cmd writes nothing, keeps the proposal', $knob() === '4' && is_array($sp) && count((array) ($sp['ops'] ?? [])) === 1);
    check('settings.ai_proposed audited', Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.ai_proposed' AND new_value LIKE '%zu5_es_knob%'", ['s' => $startId]));

    $h = page(['admin' => $super, 'post' => ['act' => 'no'], 'es_prop' => $sp]);
    check('act=no: nothing changed, settings.ai_declined audited', str_contains($h, 'Nothing changed') && $knob() === '4'
        && Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.ai_declined' AND new_value LIKE '%zu5_es_knob%'", ['s' => $startId]));

    $h = page(['admin' => $super, 'post' => ['act' => 'yes'], 'es_prop' => $sp]);
    check('act=yes writes it', $knob() === '8' && str_contains($h, '✅'));
    check('settings.ai_command carries the actions', Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.ai_command' AND new_value LIKE '%\"actions\"%zu5_es_knob%'", ['s' => $startId]));

    $h = page(['admin' => $super, 'post' => ['act' => 'cmd', 'cmd' => 'Vapi ko bhada 2500']]);
    check('a question is the flash, no proposal', str_contains($h, 'Which pickup?') && !str_contains($h, 'Change this?'));

    $before = (string) (Database::scalar("SELECT svalue FROM settings WHERE skey = 'wa_marketing_on'", [], '') ?? '');
    page(['admin' => $super, 'post' => ['act' => 'toggle', 'key' => 'wa_marketing_on']]);
    check('a one-tap switch still saves (settings.easy)', Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.easy' AND entity_id = 'wa_marketing_on'", ['s' => $startId])
        && (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'wa_marketing_on'", [], '') !== $before);
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    $cleanup();
}
echo "\n  u5-easy-settings-test: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
