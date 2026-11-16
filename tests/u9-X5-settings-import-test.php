<?php
/**
 * =====================================================================
 *  u9-X5-settings-import-test.php — X5 (16 Nov 2026, Master v2.0)
 *
 *  1. admin/settings.php SETTINGS IMPORT (P4-6e), run for real: each page
 *     call is its own PHP process (this file re-invokes itself with --run)
 *     with a hand-built staff session that is carried from call to call in
 *     a temp file, so the preview's one-shot token reaches the Apply press.
 *       - super-admin only (a settings manager sees no form and is refused);
 *       - preview: old -> new of every changed key, NOTHING written; refused:
 *         secret, *_key link key, a "keys / connection" row, app state,
 *         seat_mode_map, a key this app does not have, a bad value, a key
 *         listed twice; routes / route_stops / coupons "not imported";
 *       - apply: only the ticked rows, each audited settings.import old/new,
 *         one settings.import_summary with every old value; a row changed
 *         since the preview is skipped; a reload applies nothing twice;
 *         expired / cancelled / bad CSRF / too big / not a settings file;
 *       - the page's own export, imported back, changes nothing.
 *  2. 60 -> 30 minutes: WaFaq / WaLocal / the AI system prompt say 30.
 *  3. Easy Settings: every key of the X SWITCH CONTRACT is in
 *     AiOps::EASY_KEYS (+ EASY_GROUPS), bilingual, with its type / bounds,
 *     and reads OFF / 0 / 30 / classic with no row (nothing is written).
 *
 *  Throwaway rows on the LOCAL shari_test only: settings 'u9x5_<pid>_*'
 *  and their audit rows, deleted at the end. Real settings are never
 *  written (the export round-trip only previews).
 *
 *     php -c .claude/php-dev.ini tests/u9-X5-settings-import-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---------------------------------------------------------------- runner */
if (($argv[1] ?? '') === '--run') {
    // php this.php --run <page> <post> <get> <role> <perms> <files> <sessfile> <badcsrf>   (JSON / path, base64)
    $arg  = static fn(int $i): array => json_decode((string) base64_decode((string) ($argv[$i] ?? ''), true), true) ?: [];
    $page = (string) $argv[2];
    $post = $arg(3);
    $get  = $arg(4);
    $role = (string) ($argv[5] ?? 'superadmin');
    $perm = $arg(6);
    $fls  = $arg(7);
    $sess = (string) base64_decode((string) ($argv[8] ?? ''), true);
    $bad  = ($argv[9] ?? '') === '1';
    $root = dirname(__DIR__);
    require_once $root . '/includes/bootstrap.php';
    if (DB_NAME !== 'shari_test') { fwrite(STDERR, "refusing: not shari_test\n"); exit(2); }
    $_SESSION = [];
    if ($sess !== '' && is_file($sess)) {
        $s = @unserialize((string) file_get_contents($sess), ['allowed_classes' => false]);
        if (is_array($s)) { $_SESSION = $s; }
    }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => 1, 'username' => 'superadmin', 'full_name' => 'U9 X5 test', 'role' => $role,
        'permissions' => $perm, 'last_seen' => time(), 'qk_ok' => true,
    ];
    $tok = Security::csrfToken();
    $_SERVER['REQUEST_METHOD'] = $post !== [] ? 'POST' : 'GET';
    $_SERVER['SCRIPT_NAME']    = '/' . $page;
    $_SERVER['REQUEST_URI']    = '/' . $page . ($get !== [] ? '?' . http_build_query($get) : '');
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_GET   = $get;
    $_POST  = $post !== [] ? $post + [CSRF_TOKEN_NAME => $bad ? 'not-the-token' : $tok] : [];
    $_FILES = [];
    foreach ($fls as $field => $path) {
        $_FILES[(string) $field] = ['name' => basename((string) $path), 'type' => 'application/json', 'tmp_name' => (string) $path,
            'error' => is_file((string) $path) ? UPLOAD_ERR_OK : UPLOAD_ERR_NO_FILE, 'size' => is_file((string) $path) ? (int) filesize((string) $path) : 0];
    }
    register_shutdown_function(static function () use ($sess): void {
        if ($sess !== '') { @file_put_contents($sess, serialize($_SESSION)); }
        $err = error_get_last();
        echo "\n@@META@@" . json_encode(['http' => http_response_code() ?: 200,
            'err' => $err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) ? $err['message'] : null]);
    });
    try {
        require $root . '/' . $page;
    } catch (Throwable $e) {
        http_response_code(500);
        echo "\nUNCAUGHT " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    exit(0);
}

/* ----------------------------------------------------------------- suite */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aiops.php';
if (DB_NAME !== 'shari_test') { echo "refusing: not the local shari_test database\n"; exit(2); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 700) : '') . "\n"; }
}
$SESS = (string) tempnam(sys_get_temp_dir(), 'u9x5s');
/** @return array{http: int, body: string, flash: list<array{0: string, 1: string}>, uncaught: string} */
function page(string $page, array $post = [], array $get = [], string $role = 'superadmin', array $perms = [], array $files = [], bool $badCsrf = false): array {
    global $SESS;
    $ini = getenv('SHG_TEST_INI') ?: (php_ini_loaded_file() ?: '');
    $cmd = escapeshellarg(PHP_BINARY) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --run ' . escapeshellarg($page) . ' ' . base64_encode((string) json_encode($post)) . ' ' . base64_encode((string) json_encode($get))
         . ' ' . escapeshellarg($role) . ' ' . base64_encode((string) json_encode($perms)) . ' ' . base64_encode((string) json_encode($files))
         . ' ' . base64_encode($SESS) . ' ' . ($badCsrf ? '1' : '0');
    $out  = (string) shell_exec($cmd . ' 2>&1');
    $meta = ['http' => 0, 'err' => null];
    $p = strrpos($out, '@@META@@');
    if ($p !== false) {
        $meta = json_decode(substr($out, $p + 8), true) ?: $meta;
        $out  = substr($out, 0, $p);
    }
    preg_match_all('#<div class="flash (ok|bad)"[^>]*>(.*?)</div>#s', $out, $m, PREG_SET_ORDER);
    $flash = array_map(static fn(array $x): array => [$x[1], html_entity_decode(strip_tags($x[2]), ENT_QUOTES)], $m);
    $unc = preg_match('/UNCAUGHT [^\n]+/', $out, $u) ? $u[0] : '';
    return ['http' => (int) ($meta['http'] ?? 0), 'body' => $out, 'flash' => $flash, 'uncaught' => $unc . (string) ($meta['err'] ?? '')];
}
$flashOf = static fn(array $r): string => implode(' | ', array_map(static fn(array $f): string => $f[0] . ': ' . $f[1], $r['flash']));
$sessGet = static function () use ($SESS): array {
    $s = @unserialize((string) @file_get_contents($SESS), ['allowed_classes' => false]);
    return is_array($s) ? $s : [];
};
$val = static fn(string $k): ?string => ($r = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k])) === null ? null : ($r['svalue'] === null ? null : (string) $r['svalue']);

$P   = 'u9x5_' . getmypid() . '_';
$auditFrom = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$tmpFiles  = [];
$jsonFile  = static function (string $content) use (&$tmpFiles): string {
    $f = (string) tempnam(sys_get_temp_dir(), 'u9x5j');
    file_put_contents($f, $content);
    $tmpFiles[] = $f;
    return $f;
};
$cleanup = static function () use ($P, $auditFrom, &$tmpFiles, $SESS): void {
    Database::delete('settings', 'skey LIKE :p', ['p' => $P . '%']);
    Database::delete('audit_logs', "id > :s AND (entity_id LIKE :p OR old_value LIKE :q1 OR new_value LIKE :q2
                                     OR (action = 'settings.import_preview' AND new_value LIKE '%\"file\":\"u9x%'))",   /* tempnam: u9x… on Windows */
        ['s' => $auditFrom, 'p' => $P . '%', 'q1' => '%' . $P . '%', 'q2' => '%' . $P . '%']);
    foreach ($tmpFiles as $f) { @unlink($f); }
    @unlink($SESS);
    Settings::flush();
};
register_shutdown_function($cleanup);

try {
    /* ------------------------------------------------------------ fixtures */
    $fx = [
        'num'      => ['5', 'int', 'booking'],
        'flag'     => ['0', 'bool', 'booking'],
        'txt'      => ['hello', 'string', 'site'],
        'cfg'      => ['{"a":1,"b":[1,2]}', 'json', 'pricing'],
        'same'     => ['keep', 'string', 'site'],
        'fl'       => ['2.5', 'float', 'booking'],
        'moved'    => ['a', 'string', 'site'],
        'unticked' => ['u', 'string', 'site'],
        'num2'     => ['3', 'int', 'booking'],
        'api_token'=> ['S3CRET-OLD', 'string', 'whatsapp'],
        'link_key' => ['abc', 'string', 'site'],
        'state'    => ['{}', 'json', 'ai'],
        'conn'     => ['host.example', 'string', 'integrations'],
    ];
    foreach ($fx as $n => [$v, $t, $g]) {
        Database::insert('settings', ['skey' => $P . $n, 'svalue' => $v, 'stype' => $t, 'sgroup' => $g, 'label' => 'U9 X5 ' . $n, 'is_public' => 0]);
    }
    $seatMap = $val('seat_mode_map');
    $fileRows = [
        ['skey' => $P . 'num', 'svalue' => '9', 'stype' => 'int'],
        ['skey' => $P . 'flag', 'svalue' => '1', 'stype' => 'bool'],
        ['skey' => $P . 'txt', 'svalue' => 'नमस्ते <b>bold</b>', 'stype' => 'string'],
        ['skey' => $P . 'cfg', 'svalue' => '{ "a": 2, "b": [1, 2] }', 'stype' => 'json'],
        ['skey' => $P . 'same', 'svalue' => 'keep', 'stype' => 'string'],
        ['skey' => $P . 'fl', 'svalue' => '2.50', 'stype' => 'float'],          // same number, other spelling → no change
        ['skey' => $P . 'moved', 'svalue' => 'b', 'stype' => 'string'],
        ['skey' => $P . 'unticked', 'svalue' => 'u2', 'stype' => 'string'],
        ['skey' => $P . 'num2', 'svalue' => 'abc', 'stype' => 'int'],
        ['skey' => $P . 'api_token', 'svalue' => 'S3CRET-NEW-VALUE', 'stype' => 'string'],
        ['skey' => $P . 'link_key', 'svalue' => 'zzz', 'stype' => 'string'],
        ['skey' => $P . 'state', 'svalue' => '{"x":1}', 'stype' => 'json'],
        ['skey' => $P . 'conn', 'svalue' => 'evil.example', 'stype' => 'string'],
        ['skey' => $P . 'nope', 'svalue' => '1', 'stype' => 'string'],
        ['skey' => $P . 'txt', 'svalue' => 'second copy', 'stype' => 'string'],   // listed twice
    ];
    if ($seatMap !== null) {
        $fileRows[] = ['skey' => 'seat_mode_map', 'svalue' => '{"sleeper":{"canonical":"sharing"}}', 'stype' => 'json'];
    }
    $doc = ['exported_at' => '2026-11-16T10:00:00+05:30', 'app' => 'S Hari Global', 'by' => 'u9x5', 'note' => 'test',
            'settings' => $fileRows, 'routes' => [['id' => 1, 'route_code' => 'zz']], 'route_stops' => [['id' => 1], ['id' => 2]], 'coupons' => []];
    $good = $jsonFile((string) json_encode($doc, JSON_UNESCAPED_UNICODE));

    /* ------------------------------------------------------------ who may */
    echo "\n=== admin/settings.php — import: who may ===\n\n";
    $r = page('admin/settings.php');
    check('super-admin sees the Import form beside Export (multipart, import_act=preview, file field)',
        str_contains($r['body'], 'name="import_json"') && str_contains($r['body'], 'value="preview"') && str_contains($r['body'], 'enctype="multipart/form-data"')
        && str_contains($r['body'], 'Import settings (JSON)'), $r['uncaught']);
    $mgr = ['dashboard.view', 'settings.manage'];
    $r = page('admin/settings.php', [], [], 'manager', $mgr);
    check('a settings manager (not super-admin) sees Export but no Import form', str_contains($r['body'], 'export=json') && !str_contains($r['body'], 'name="import_json"'), $r['uncaught']);
    $r = page('admin/settings.php', ['import_act' => 'preview'], [], 'manager', $mgr, ['import_json' => $good]);
    check('…and a manager\'s import POST is refused, nothing previewed or kept',
        str_contains($flashOf($r), 'super-admin') && !str_contains($r['body'], 'id="importPreview"') && !isset($sessGet()['settings_import']), $flashOf($r));
    $r = page('admin/settings.php', ['import_act' => 'preview'], [], 'counter', ['dashboard.view'], ['import_json' => $good]);
    check('a counter role\'s import POST is refused too', str_contains($flashOf($r), 'super-admin') && !isset($sessGet()['settings_import']), $flashOf($r));
    $r = page('admin/settings.php', ['import_act' => 'preview'], [], 'superadmin', [], ['import_json' => $good], true);
    check('a bad CSRF token: "Session expired", nothing previewed', str_contains($flashOf($r), 'Session expired') && !isset($sessGet()['settings_import']), $flashOf($r));
    // review: warnings throw in this app (bootstrap error handler) - an array where a word is expected must not be a 500
    $r = page('admin/settings.php', ['import_act' => ['apply'], 'import_token' => ['x']]);
    check('import_act / import_token sent as arrays: no 500, nothing done', $r['http'] === 200 && $r['uncaught'] === '' && !isset($sessGet()['settings_import']), $r['uncaught'] . ' ' . $flashOf($r));
    $r = page('admin/settings.php', ['import_act' => 'apply', 'import_token' => ['x'], 'imp' => [$P . 'num']]);
    check('…an array token on Apply is refused like a wrong one (no 500)', $r['http'] === 200 && $r['uncaught'] === '' && str_contains($flashOf($r), 'already applied'), $r['uncaught'] . ' ' . $flashOf($r));
    check('…and the generic Save branch did not run on an import POST (no settings.update row)',
        !Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.update' AND actor_id = 1 AND created_at >= NOW() - INTERVAL 2 MINUTE AND new_value = '[]'", ['s' => $auditFrom]));

    /* ------------------------------------------------------------ preview */
    echo "\n=== preview ===\n\n";
    $r = page('admin/settings.php', ['import_act' => 'preview'], [], 'superadmin', [], ['import_json' => $good]);
    $b = $r['body'];
    check('the preview renders (HTTP 200, no fatal)', $r['http'] === 200 && $r['uncaught'] === '' && str_contains($b, 'id="importPreview"'), $r['uncaught'] . ' ' . $flashOf($r));
    $boxes = preg_match_all('#name="imp\[\]" value="([^"]+)"#', $b, $mm) ? $mm[1] : [];
    sort($boxes);
    $wantBoxes = [$P . 'cfg', $P . 'flag', $P . 'moved', $P . 'num', $P . 'txt', $P . 'unticked'];
    check('exactly the changed, importable keys get a tick box (num, flag, txt, cfg, moved, unticked)', $boxes === $wantBoxes, implode(', ', $boxes));
    check('…each ticked by default, old → new shown (5 → 9, OFF → ON)', preg_match_all('#name="imp\[\]" value="[^"]+" checked>#', $b) === count($boxes)
        && preg_match('#<s>5</s> → <b>9</b>#', $b) === 1 && preg_match('#<s>OFF</s> → <b>ON</b>#', $b) === 1);
    check('the new text is escaped in the preview (no raw <b>bold</b> from the file)', str_contains($b, 'नमस्ते &lt;b&gt;bold&lt;/b&gt;') && !str_contains($b, 'नमस्ते <b>bold</b>'));
    check('same value (and "2.50" vs "2.5") is not listed as a change', !in_array($P . 'same', $boxes, true) && !in_array($P . 'fl', $boxes, true));
    $not = preg_match('#<details class="imp-not">(.*?)</details>#s', $b, $nm) ? $nm[1] : '';
    foreach (['api_token' => 'secret', 'link_key' => 'API key', 'conn' => 'API key', 'state' => 'written by the app', 'nope' => 'not a setting of this app',
              'num2' => 'not a valid int', 'txt' => 'listed twice'] as $n => $why) {
        check('not imported: ' . $n . ' (' . $why . ')', preg_match('#<code>' . preg_quote($P . $n, '#') . '</code> — [^<]*' . preg_quote($why, '#') . '#u', $not) === 1);
    }
    if ($seatMap !== null) {
        check('not imported: seat_mode_map (its own sold-seat check)', preg_match('#<code>seat_mode_map</code> — [^<]*seat layout rule#u', $not) === 1);
    }
    check('routes / route_stops / coupons are listed as "not imported"', preg_match('#<code>routes</code> — 1 row #', $not) === 1
        && preg_match('#<code>route_stops</code> — 2 rows#', $not) === 1 && preg_match('#<code>coupons</code> — 0 rows#', $not) === 1);
    check('the secret from the file is never printed', !str_contains($b, 'S3CRET-NEW-VALUE'));
    $vals = [];
    foreach ($fx as $n => $_) { $vals[$n] = $val($P . $n); }
    check('the preview wrote NOTHING', $vals['num'] === '5' && $vals['flag'] === '0' && $vals['txt'] === 'hello' && $vals['cfg'] === '{"a":1,"b":[1,2]}'
        && $vals['api_token'] === 'S3CRET-OLD' && $val($P . 'nope') === null, json_encode($vals));
    $s = $sessGet();
    $tok = (string) ($s['settings_import']['token'] ?? '');
    check('the plan waits in the session with a one-shot token', strlen($tok) === 32 && preg_match('#name="import_token" value="' . preg_quote($tok, '#') . '"#', $b) === 1);
    check('the preview is audited (settings.import_preview, nothing written)',
        Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.import_preview' AND new_value LIKE :q", ['s' => $auditFrom, 'q' => '%' . $P . 'num%']));
    $r = page('admin/settings.php');
    check('a plain reload (GET) applies nothing and shows no preview', !str_contains($r['body'], 'id="importPreview"') && $val($P . 'num') === '5');

    /* ------------------------------------------------------------ apply */
    echo "\n=== apply ===\n\n";
    Database::update('settings', ['svalue' => 'changed-meanwhile'], 'skey = :k', ['k' => $P . 'moved']);   // another Save between preview and apply
    $tick = [$P . 'num', $P . 'flag', $P . 'txt', $P . 'cfg', $P . 'moved', $P . 'api_token', $P . 'nope'];   // + two that were never offered
    $r = page('admin/settings.php', ['import_act' => 'apply', 'import_token' => $tok, 'imp' => $tick]);
    $fl = $flashOf($r);
    check('apply: flash names the imported keys and the skipped one', str_starts_with($fl, 'ok:') && str_contains($fl, '4 settings imported') && str_contains($fl, $P . 'num')
        && str_contains($fl, $P . 'moved (changed since the preview)'), $fl . $r['uncaught']);
    check('the ticked rows are written (9, ON, text, canonical JSON)', $val($P . 'num') === '9' && $val($P . 'flag') === '1' && $val($P . 'txt') === 'नमस्ते <b>bold</b>'
        && $val($P . 'cfg') === '{"a":2,"b":[1,2]}', json_encode([$val($P . 'num'), $val($P . 'flag'), $val($P . 'txt'), $val($P . 'cfg')], JSON_UNESCAPED_UNICODE));
    check('a row changed since the preview is skipped, not overwritten', $val($P . 'moved') === 'changed-meanwhile');
    check('an unticked row is untouched', $val($P . 'unticked') === 'u');
    check('a refused row ticked by hand is still not written (secret, unknown key)', $val($P . 'api_token') === 'S3CRET-OLD' && $val($P . 'nope') === null);
    $aud = Database::fetchAll("SELECT entity_id, old_value, new_value, detail FROM audit_logs WHERE id > :s AND action = 'settings.import' AND entity_id LIKE :p ORDER BY id",
        ['s' => $auditFrom, 'p' => $P . '%']);
    $byKey = [];
    foreach ($aud as $a) { $byKey[(string) $a['entity_id']] = $a; }
    check('one settings.import audit row per written key (4)', count($aud) === 4 && isset($byKey[$P . 'num'], $byKey[$P . 'flag'], $byKey[$P . 'txt'], $byKey[$P . 'cfg']), (string) count($aud));
    check('…with the OLD and new value (num 5 → 9) and the file named', ($byKey[$P . 'num']['old_value'] ?? '') === '{"value":"5"}' && ($byKey[$P . 'num']['new_value'] ?? '') === '{"value":"9"}'
        && str_contains((string) ($byKey[$P . 'num']['detail'] ?? ''), 'Settings JSON import from'));
    $sum = Database::fetch("SELECT old_value, new_value, detail FROM audit_logs WHERE id > :s AND action = 'settings.import_summary' AND old_value LIKE :q ORDER BY id DESC LIMIT 1",
        ['s' => $auditFrom, 'q' => '%' . $P . 'num%']);
    $so = json_decode((string) ($sum['old_value'] ?? ''), true);
    check('one settings.import_summary row keeps every old value (undo by hand)', is_array($so) && ($so['values'][$P . 'num'] ?? null) === '5' && ($so['values'][$P . 'flag'] ?? null) === '0'
        && ($so['values'][$P . 'cfg'] ?? null) === '{"a":1,"b":[1,2]}' && str_contains((string) ($sum['detail'] ?? ''), '4 applied, 1 skipped'), (string) ($sum['old_value'] ?? ''));
    check('the plan left the session', !isset($sessGet()['settings_import']));

    Database::update('settings', ['svalue' => '7'], 'skey = :k', ['k' => $P . 'num']);
    $r = page('admin/settings.php', ['import_act' => 'apply', 'import_token' => $tok, 'imp' => $tick]);
    check('a reload of the Apply POST applies nothing twice', str_contains($flashOf($r), 'already applied') && $val($P . 'num') === '7', $flashOf($r));

    /* ------------------------------------------------------------ expiry / cancel / bad files */
    echo "\n=== expiry, cancel, bad files ===\n\n";
    page('admin/settings.php', ['import_act' => 'preview'], [], 'superadmin', [], ['import_json' => $good]);
    $s = $sessGet();
    $tok2 = (string) ($s['settings_import']['token'] ?? '');
    $s['settings_import']['at'] = time() - 1000;
    file_put_contents($SESS, serialize($s));
    $r = page('admin/settings.php', ['import_act' => 'apply', 'import_token' => $tok2, 'imp' => [$P . 'num']]);
    check('a preview older than 15 minutes is refused', str_contains($flashOf($r), 'older than 15 minutes') && $val($P . 'num') === '7', $flashOf($r));
    page('admin/settings.php', ['import_act' => 'preview'], [], 'superadmin', [], ['import_json' => $good]);
    $tok3 = (string) ($sessGet()['settings_import']['token'] ?? '');
    $r = page('admin/settings.php', ['import_act' => 'apply', 'import_token' => 'x' . substr($tok3, 1), 'imp' => [$P . 'num']]);
    check('a wrong token is refused (and spends the plan)', str_contains($flashOf($r), 'already applied') && $val($P . 'num') === '7');
    page('admin/settings.php', ['import_act' => 'preview'], [], 'superadmin', [], ['import_json' => $good]);
    $tok4 = (string) ($sessGet()['settings_import']['token'] ?? '');
    $r = page('admin/settings.php', ['import_act' => 'cancel']);
    check('Cancel clears the plan', str_contains($flashOf($r), 'cancelled') && !isset($sessGet()['settings_import']));
    $r = page('admin/settings.php', ['import_act' => 'apply', 'import_token' => $tok4, 'imp' => [$P . 'num']]);
    check('…so its token applies nothing', str_contains($flashOf($r), 'already applied') && $val($P . 'num') === '7');
    page('admin/settings.php', ['import_act' => 'preview'], [], 'superadmin', [], ['import_json' => $good]);
    $tok5 = (string) ($sessGet()['settings_import']['token'] ?? '');
    $r = page('admin/settings.php', ['import_act' => 'apply', 'import_token' => $tok5]);
    check('Apply with nothing ticked writes nothing', str_contains($flashOf($r), 'no row was ticked') && $val($P . 'num') === '7', $flashOf($r));

    $big = $jsonFile('{"settings":[],"pad":"' . str_repeat('x', 2 * 1024 * 1024 + 10) . '"}');
    $r = page('admin/settings.php', ['import_act' => 'preview'], [], 'superadmin', [], ['import_json' => $big]);
    check('a file over 2 MB is refused', str_contains($flashOf($r), '2 MB') && !isset($sessGet()['settings_import']), $flashOf($r));
    foreach (['[1,2,3]' => 'a JSON list', '{"foo":1}' => 'no "settings"', 'not json at all' => 'not JSON', '{"settings":"x"}' => '"settings" not a list'] as $bad => $what) {
        $r = page('admin/settings.php', ['import_act' => 'preview'], [], 'superadmin', [], ['import_json' => $jsonFile($bad)]);
        check('not a settings file (' . $what . ') is refused', str_contains($flashOf($r), 'not a settings file') && !str_contains($r['body'], 'id="importPreview"'), $flashOf($r));
    }
    $r = page('admin/settings.php', ['import_act' => 'preview'], [], 'superadmin', [], ['import_json' => sys_get_temp_dir() . '/u9x5-missing-' . getmypid() . '.json']);
    check('no file chosen → asked for one', str_contains($flashOf($r), 'Choose the exported'), $flashOf($r));
    $map = $jsonFile((string) json_encode(['settings' => [$P . 'unticked' => 'u3', $P . 'flag' => false]]));
    $r = page('admin/settings.php', ['import_act' => 'preview'], [], 'superadmin', [], ['import_json' => $map]);
    $boxes = preg_match_all('#name="imp\[\]" value="([^"]+)"#', $r['body'], $mm) ? $mm[1] : [];
    sort($boxes);
    check('a plain {"key": value} map is read too (u3, ON → OFF)', $boxes === [$P . 'flag', $P . 'unticked'], implode(', ', $boxes) . $flashOf($r));
    page('admin/settings.php', ['import_act' => 'cancel']);

    /* ------------------------------------------------------------ export → import = nothing */
    echo "\n=== export, imported back ===\n\n";
    $expFrom = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
    $r = page('admin/settings.php', [], ['export' => 'json']);
    // review: the export's own settings.export audit row is ours too - removed (only that call's id range)
    Database::query("DELETE FROM audit_logs WHERE id > :a AND id <= :b AND action = 'settings.export' AND actor_id = 1 AND detail = 'Settings JSON exported'",
        ['a' => $expFrom, 'b' => (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0)]);
    $exp = trim($r['body']);
    check('the export downloads JSON', is_array(json_decode($exp, true)));
    $r = page('admin/settings.php', ['import_act' => 'preview'], [], 'superadmin', [], ['import_json' => $jsonFile($exp)]);
    $boxes = preg_match_all('#name="imp\[\]" value="([^"]+)"#', $r['body'], $mm) ? $mm[1] : [];
    check('this app\'s own export, imported back, changes nothing', $r['uncaught'] === '' && $boxes === [] && str_contains($r['body'], 'Nothing to change'),
        implode(', ', array_slice($boxes, 0, 12)) . $r['uncaught']);
    page('admin/settings.php', ['import_act' => 'cancel']);

    /* ------------------------------------------------------------ 60 minutes everywhere (lead, 16 Nov 2026:
       the website, terms, report_before_min and WhatsApp all say 60; the ticket PNG/PDF now says 60 too) */
    echo "\n=== 60 minutes early, the same everywhere ===\n\n";
    $root = dirname(__DIR__);
    foreach (['includes/wafaq.php', 'includes/walocal.php', 'includes/aiprompt.php', 'includes/ticket.php', 'includes/socialposts.php'] as $f) {
        $src = (string) file_get_contents($root . '/' . $f);
        check($f . ' never says 30 minutes early', !str_contains($src, '३० मिनेट अगाडि') && !str_contains($src, '30 मिनट पहले') && !str_contains($src, '30 minutes early'));
    }
    require_once INCLUDE_PATH . '/wafaq.php';
    require_once INCLUDE_PATH . '/walocal.php';
    try {
        $m = new ReflectionMethod('WaFaq', 'timing');
        $t = (string) $m->invoke(null, 'surat bata bus kati baje', 'surat bata bus kati baje', 'en');
        check('WaFaq timing answer: "60 minutes early"', $t === '' || str_contains($t, '60 minutes early'), mb_substr($t, 0, 200));
        $t = (string) $m->invoke(null, 'surat bata bus kati baje', 'surat bata bus kati baje', 'ne');
        check('…in Nepali: "६० मिनेट अगाडि"', $t === '' || str_contains($t, '६० मिनेट अगाडि'), mb_substr($t, 0, 200));
    } catch (Throwable $e) {
        check('WaFaq timing callable', false, $e->getMessage());
    }
    try {
        $m = new ReflectionMethod('WaLocal', 'timetable');
        $tt = (array) $m->invoke(null, 'hi');
        check('WaLocal timetable (Hindi): "60 मिनट पहले"', (string) ($tt['text'] ?? '') === '' || str_contains((string) $tt['text'], '60 मिनट पहले'), mb_substr((string) ($tt['text'] ?? ''), 0, 200));
    } catch (Throwable $e) {
        check('WaLocal timetable callable', false, $e->getMessage());
    }
    require_once INCLUDE_PATH . '/aiprompt.php';
    if (function_exists('ai_system_prompt')) {
        $sp = ai_system_prompt();
        check('AI system prompt: "reach the pickup 60 minutes early"', str_contains($sp, 'reach the pickup 60 minutes early') && !str_contains($sp, '30 minutes early'));
    }

    /* ------------------------------------------------------------ Easy Settings */
    echo "\n=== Easy Settings: the X switch contract ===\n\n";
    $want = [
        'return_discount_pct'   => ['float', 0, 20],
        'return_discount_days'  => ['int', 1, 90],
        'luggage_fee_per_piece' => ['int', 0, 2000],
        'infant_seat_on'        => ['bool'],
        'infant_seat_fare'      => ['int', 0, 3000],
        'festival_theme_on'     => ['bool'],
        'login_theme'           => ['enum'],
        'fb_comments_on'        => ['bool'],
        'fb_comment_ai_on'      => ['bool'],
    ];
    $E = AiOps::EASY_KEYS;
    $groups = (new ReflectionClassConstant(AiOps::class, 'EASY_GROUPS'))->getValue();
    foreach ($want as $k => $spec) {
        $d = $E[$k] ?? null;
        $ok = is_array($d) && $d[1] === $spec[0] && str_contains((string) $d[0], ' · ') && preg_match('/[\x{0900}-\x{097F}]/u', (string) $d[0]) === 1
            && isset($groups[$k]) && (array) $d[2] !== [];
        if ($ok && isset($spec[1])) {
            $ok = (float) $d[3]['min'] === (float) $spec[1] && (float) $d[3]['max'] === (float) $spec[2];
        }
        check('Easy Settings has ' . $k . ' (' . $spec[0] . (isset($spec[1]) ? ' ' . $spec[1] . '-' . $spec[2] : '') . ', bilingual label, group, words)', $ok, json_encode($d, JSON_UNESCAPED_UNICODE));
    }
    check('login_theme is classic | gold, classic first', array_keys((array) ($E['login_theme'][3] ?? [])) === ['classic', 'gold']);
    check('festival_dates is NOT an Easy Settings key (edited on admin/themes.php)', !isset($E['festival_dates']));
    check('the groups match the X1 / X2 / X4 migrations', ($groups['return_discount_pct'] ?? '') === 'booking' && ($groups['luggage_fee_per_piece'] ?? '') === 'booking'
        && ($groups['infant_seat_fare'] ?? '') === 'booking' && ($groups['festival_theme_on'] ?? '') === 'app' && ($groups['fb_comments_on'] ?? '') === 'facebook');
    // the value each reads with no row at all (a cache that does not hold them) — nothing is written
    Settings::flush();
    Settings::get('company_name');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    foreach (array_keys($want) as $k) { unset($c[$k]); }
    $rp->setValue(null, $c);
    $def = [];
    foreach (array_keys($want) as $k) { $def[$k] = AiOps::easyCurrent($k); }
    Settings::flush();
    check('with no row: OFF / 0 / 30 days / classic', $def === ['return_discount_pct' => '0', 'return_discount_days' => '30', 'luggage_fee_per_piece' => '0', 'infant_seat_on' => '0',
        'infant_seat_fare' => '0', 'festival_theme_on' => '0', 'login_theme' => 'classic', 'fb_comments_on' => '0', 'fb_comment_ai_on' => '0'], json_encode($def));
    check('shown words: "0 · बन्द · off" for the return discount, "0 · निःशुल्क · free" for the infant fare',
        AiOps::easyShow('return_discount_pct', '0') === '0 · बन्द · off' && AiOps::easyShow('infant_seat_fare', '0') === '0 · निःशुल्क · free'
        && AiOps::easyShow('return_discount_pct', '5') === '5%' && AiOps::easyShow('login_theme', 'gold') !== 'gold');
    AiOps::registerEasyKeys();
    check('the AI / key path (clean) keeps the knobs inside their bounds (25 % and ₹2,500 luggage refused, 5 % kept)',
        AiOps::clean(['type' => 'setting', 'key' => 'return_discount_pct', 'value' => '25']) === null
        && AiOps::clean(['type' => 'setting', 'key' => 'luggage_fee_per_piece', 'value' => '2500']) === null
        && (AiOps::clean(['type' => 'setting', 'key' => 'return_discount_pct', 'value' => '5'])['value'] ?? '') === '5'
        && (AiOps::clean(['type' => 'setting', 'key' => 'login_theme', 'value' => 'gold'])['value'] ?? '') === 'gold'
        && AiOps::clean(['type' => 'setting', 'key' => 'login_theme', 'value' => 'purple']) === null);
} catch (Throwable $e) {
    check('suite ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
}

echo "\n---------- $PASS passed / $FAIL failed ----------\n";
exit($FAIL > 0 ? 1 : 0);
