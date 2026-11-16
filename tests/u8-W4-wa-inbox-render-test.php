<?php
/**
 * =====================================================================
 *  u8-W4-wa-inbox-render-test.php — P6-3b on the real inbox page
 *  (16 Nov 2026, SHG Network Master Upgrade v2.0, unit W4).
 *
 *  Renders admin/wa-inbox.php in a child PHP with a hand-built superadmin
 *  session (the same harness as u3a-wa-inbox-render-test.php) and pins:
 *    - wa_handoff_msg_on OFF (the default): no "Tell the customer" tick, and
 *      a forged tell=1 on "Pause bot" sends nothing
 *    - ON: the tick is drawn; Pause + tick inside the 24 h window sends ONE
 *      fixed line (captured, never the network), logged inbox_handoff and
 *      audited; outside the window nothing goes and the office is told why;
 *      Resume (hours 0) with the tick never sends
 *    - CSRF still guards the pause
 *  Throwaway rows only, deleted at the end.
 *
 *      php -c .claude/php-dev.ini tests/u8-W4-wa-inbox-render-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.73';
    $_SERVER['SCRIPT_NAME']    = '/admin/wa-inbox.php';
    $_SERVER['REQUEST_URI']    = '/admin/wa-inbox.php';
    $_GET  = (array) ($cfg['get'] ?? []);
    $post  = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    require_once INCLUDE_PATH . '/waroute.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) $cfg['admin']]);
    if ($a === null) { fwrite(STDERR, "no such admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    Settings::get('company_name');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    foreach (['helpdesk_ack' => '0', 'helpdesk_office_alert' => '0', 'helpdesk_autoassign' => '0', 'staff_setup_wizard' => '0', 'wa_faq_on' => '0',
              'whatsapp_driver' => 'click_to_chat', 'wa_policy' => 'meta_first'] + (array) ($cfg['ovr'] ?? []) as $k => $v) {
        $c[$k] = ['value' => (string) $v, 'type' => in_array((string) $v, ['0', '1'], true) ? 'bool' : 'string', 'public' => false];
    }
    $rp->setValue(null, $c);
    WaRoute::$transport = static function (string $to, string $text, ?string $img = null): array {
        fwrite(STDERR, "\nWA-SEND $to " . base64_encode($text) . "\n");
        return ['success' => true, 'message_id' => 'wamid.U8W4', 'error' => ''];
    };
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME]  = ($cfg['csrf'] ?? 'valid') === 'valid' ? Security::csrfToken() : 'not-the-token';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    }
    require dirname(__DIR__) . '/admin/wa-inbox.php';
    exit(0);
}

define('SHG_APP', true);
chdir(dirname(__DIR__));
require 'includes/bootstrap.php';
require_once INCLUDE_PATH . '/wainbox.php';
if (!str_contains((string) DB_NAME, 'test')) { exit("Refusing to run: DB_NAME is not a test database.\n"); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? ' — ' . str_replace("\n", ' ⏎ ', mb_substr($extra, 0, 400)) : '') . "\n"; }
}
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
$jsonOf = static function (string $out): ?array {
    $body = trim((string) preg_replace('/\nWA-SEND [^\n]*\n?/', "\n", $out));
    return is_array($j = json_decode($body, true)) ? $j : null;
};
$sends = static function (string $out): array {
    preg_match_all('/WA-SEND (\d+) ([A-Za-z0-9+\/=]+)/', $out, $m, PREG_SET_ORDER);
    return array_map(static fn(array $x): array => ['to' => $x[1], 'text' => (string) base64_decode($x[2])], $m);
};

$super   = (int) Database::scalar("SELECT MIN(id) FROM admins WHERE is_active = 1 AND role = 'superadmin'", [], 0);
$tag     = (string) random_int(10000, 99999);
$N       = [];
for ($i = 1; $i <= 4; $i++) { $N[$i] = '9188' . $tag . str_pad((string) $i, 3, '0', STR_PAD_LEFT); }
$all     = array_merge(array_values($N), array_map(static fn(string $d): string => substr($d, -10), array_values($N)));
$counted = 0;
$cleanup = static function () use ($all, $tag, &$counted): void {
    $in = implode(',', array_fill(0, count($all), '?'));
    $q = static function (string $sql, array $p = []): void { try { Database::run($sql, $p); } catch (Throwable $e) {} };
    $q('DELETE FROM wa_inbound WHERE wamid LIKE ?', ['wamid.u8w4r.' . $tag . '.%']);
    $q("DELETE FROM message_logs WHERE to_number IN ($in)", $all);
    $q("DELETE FROM audit_logs WHERE entity_id IN ($in)", $all);
    $q("DELETE FROM kv_store WHERE kscope IN ('wa_bot_pause', 'wa_inbound', 'wa_greet', 'wa_agent') AND kkey IN ($in)", $all);
    $q("DELETE FROM kv_store WHERE kscope = 'wa_inbox_seen_t' AND kkey LIKE ?", ['%:9188' . $tag . '%']);
    $q("DELETE FROM rate_limits WHERE bucket = 'wa_inbox_handoff' AND identifier IN ($in)", $all);
    if ($counted > 0) {   // the day's send counters our captured lines bumped
        foreach (['free', 'all'] as $what) {
            $q("UPDATE kv_store SET kvalue = GREATEST(CAST(kvalue AS SIGNED) - ?, 0) WHERE kscope = 'wa_route' AND kkey = ?", [$counted, date('Y-m-d') . ':' . $what]);
        }
        $counted = 0;
    }
};
register_shutdown_function($cleanup);

echo "\n=== admin/wa-inbox.php — the hand-off tick (W4, P6-3b) ===\n\n";
if ($super <= 0) {
    check('a superadmin exists in the test database', false);
    exit(1);
}
try {
    $in = static function (string $from, string $text) use ($tag): void {
        Database::run("INSERT INTO wa_inbound (wamid, from_digits, msg_type, payload, status, created_at)
                       VALUES (:w, :f, 'text', :p, 'done', NOW() - INTERVAL 2 MINUTE)",
            ['w' => 'wamid.u8w4r.' . $tag . '.' . bin2hex(random_bytes(4)), 'f' => $from, 'p' => json_encode(['text' => ['body' => $text]], JSON_UNESCAPED_UNICODE)]);
    };
    $window = static function (string $intl): void {
        Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'u8w4')
                       ON DUPLICATE KEY UPDATE kvalue = :v2", ['k' => $intl, 'v' => (string) time(), 'v2' => (string) time()]);
    };
    foreach ($N as $d) { $in($d, 'ticket ko barema sodhnu cha'); }
    $window($N[1]); $window($N[2]); $window($N[4]);           // N3 has no open window

    /* ---- OFF (the default) ---- */
    $h = $child(['admin' => $super, 'get' => ['tab' => 'inbox', 'p' => $N[1]], 'ovr' => ['wa_handoff_msg_on' => '0']]);
    check('switch OFF: the page renders with no PHP error', str_contains($h, 'wi-grid') && !str_contains($h, 'Fatal error') && !str_contains($h, 'Warning:'), substr(strip_tags($h), 0, 300));
    check('switch OFF: no "Tell the customer" tick', !str_contains($h, 'name="tell"') && str_contains($h, 'Pause bot 2 h'));
    $out = $child(['admin' => $super, 'post' => ['ajax' => '1', 'action' => 'pause', 'phone' => $N[1], 'hours' => '2', 'tell' => '1'], 'ovr' => ['wa_handoff_msg_on' => '0']]);
    $j = $jsonOf($out);
    check('switch OFF: a forged tell=1 pauses the bot and sends nothing', ($j['ok'] ?? null) === true && $sends($out) === [] && WaInbox::botPaused($N[1]), $out);

    /* ---- ON ---- */
    $h = $child(['admin' => $super, 'get' => ['tab' => 'inbox', 'p' => $N[2]], 'ovr' => ['wa_handoff_msg_on' => '1']]);
    check('switch ON: the page renders with no PHP error', str_contains($h, 'wi-grid') && !str_contains($h, 'Fatal error') && !str_contains($h, 'Warning:'), substr(strip_tags($h), 0, 300));
    check('switch ON: the tick is drawn beside Pause, bilingual', str_contains($h, '<input type="checkbox" name="tell" value="1">') && str_contains($h, 'ग्राहकलाई भन्नुहोस् · Tell the customer'));
    check('…its tooltip is the fixed line, escaped', str_contains($h, 'title="Namaste, S Hari office bata ek jana le aba tapai lai sahayog garnuhunchha.'));
    $out = $child(['admin' => $super, 'csrf' => 'bad', 'post' => ['ajax' => '1', 'action' => 'pause', 'phone' => $N[2], 'hours' => '2', 'tell' => '1'], 'ovr' => ['wa_handoff_msg_on' => '1']]);
    check('a pause without the CSRF token is refused and sends nothing', ($jsonOf($out)['ok'] ?? null) === false && $sends($out) === [] && !WaInbox::botPaused($N[2]), $out);
    $out = $child(['admin' => $super, 'post' => ['ajax' => '1', 'action' => 'pause', 'phone' => $N[2], 'hours' => '2', 'tell' => '1'], 'ovr' => ['wa_handoff_msg_on' => '1']]);
    $j = $jsonOf($out);
    $s = $sends($out);
    $counted += count($s);
    check('Pause + tick in the window: ONE line goes to that number', ($j['ok'] ?? null) === true && count($s) === 1 && ($s[0]['to'] ?? '') === $N[2], $out);
    check('…the fixed line', ($s[0]['text'] ?? '') === WaInbox::HANDOFF_TEXT, (string) ($s[0]['text'] ?? ''));
    check('…the office is told', str_contains((string) ($j['message'] ?? ''), 'The customer was told'), (string) ($j['message'] ?? ''));
    check('…the bot is paused', WaInbox::botPaused($N[2]));
    check('…logged as inbox_handoff and audited',
        Database::exists("SELECT 1 FROM message_logs WHERE to_number IN (?, ?) AND purpose = 'inbox_handoff' AND status = 'sent'", [$N[2], substr($N[2], -10)])
        && Database::exists("SELECT 1 FROM audit_logs WHERE action = 'wa.inbox_handoff' AND entity_id = ?", [$N[2]]));

    $out = $child(['admin' => $super, 'post' => ['ajax' => '1', 'action' => 'pause', 'phone' => $N[3], 'hours' => '24', 'tell' => '1'], 'ovr' => ['wa_handoff_msg_on' => '1']]);
    $j = $jsonOf($out);
    check('window closed: paused, nothing sent, the office is told why',
        ($j['ok'] ?? null) === true && $sends($out) === [] && str_contains((string) ($j['message'] ?? ''), 'window is closed') && WaInbox::botPaused($N[3]), $out);

    $out = $child(['admin' => $super, 'post' => ['ajax' => '1', 'action' => 'pause', 'phone' => $N[4], 'hours' => '0', 'tell' => '1'], 'ovr' => ['wa_handoff_msg_on' => '1']]);
    check('Resume (hours 0) with the tick never sends', ($jsonOf($out)['ok'] ?? null) === true && $sends($out) === [], $out);
    $out = $child(['admin' => $super, 'post' => ['ajax' => '1', 'action' => 'pause', 'phone' => $N[4], 'hours' => '2'], 'ovr' => ['wa_handoff_msg_on' => '1']]);
    check('Pause without the tick never sends', ($jsonOf($out)['ok'] ?? null) === true && $sends($out) === [], $out);
} catch (Throwable $e) {
    check('no exception: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    $cleanup();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
