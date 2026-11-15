<?php
/**
 * =====================================================================
 *  u3a-wa-inbox-render-test.php — admin/wa-inbox.php as staff see it
 *  (16 Nov 2026, SHG Network Master Upgrade v2.0, unit U3a).
 *
 *  Renders the real page in a child PHP with a hand-built staff session:
 *  the hub (channel tabs, today's reply times, search, state chips, names,
 *  the customer panel, Book / Escalate / Resolved, quick replies, Suggest,
 *  👍 / 👎), the JSON branches (20 s refresh, suggest, AJAX reply / verdict /
 *  resolve) and the guards (CSRF, the 24 h window, who may see Sent).
 *  Nothing is sent: the test number has no open WhatsApp window and the
 *  Meta transport is captured. Throwaway rows only, deleted at the end.
 *
 *      php -c .claude/php-dev.ini tests/u3a-wa-inbox-render-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $page = in_array($cfg['page'] ?? '', ['wa-inbox.php', 'ai-knowledge.php'], true) ? (string) $cfg['page'] : 'wa-inbox.php';
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.72';
    $_SERVER['SCRIPT_NAME']    = '/admin/' . $page;
    $_SERVER['REQUEST_URI']    = '/admin/' . $page;
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
    if (isset($cfg['role'])) {
        $_SESSION[ADMIN_SESSION_KEY]['role'] = (string) $cfg['role'];
        $_SESSION[ADMIN_SESSION_KEY]['permissions'] = [];
    }
    Settings::get('company_name');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    foreach (['helpdesk_ack' => '0', 'helpdesk_office_alert' => '0', 'helpdesk_autoassign' => '0', 'staff_setup_wizard' => '0', 'wa_faq_on' => '0'] as $k => $v) {
        $c[$k] = ['value' => $v, 'type' => 'bool', 'public' => false];
    }
    $rp->setValue(null, $c);
    WaRoute::$transport = static function (string $to, string $text, ?string $img = null): array {
        fwrite(STDERR, "\nWA-SEND $to\n");
        return ['success' => true, 'message_id' => 'wamid.U3A', 'error' => ''];
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
$jsonOf = static fn(string $out): ?array => is_array($j = json_decode(trim($out), true)) ? $j : null;

$super   = (int) Database::scalar("SELECT MIN(id) FROM admins WHERE is_active = 1 AND role = 'superadmin'", [], 0);
$tag     = (string) random_int(10000, 99999);
$A       = '9191' . $tag . '001';        // a passenger with a name, a bot reply and a ticket
$B       = '9191' . $tag . '002';        // an unnamed number
$cleanup = static function () use ($A, $B, $tag): void {
    $all = [$A, $B, substr($A, -10), substr($B, -10)];
    $q = static function (string $sql, array $p = []): void { try { Database::run($sql, $p); } catch (Throwable $e) {} };
    $q('DELETE FROM wa_inbound WHERE wamid LIKE ?', ['wamid.u3ar.' . $tag . '.%']);
    $q("DELETE FROM audit_logs WHERE action = 'ai.feedback' AND entity_id IN (SELECT id FROM message_logs WHERE provider = 'u3a-render')");
    $q('DELETE FROM message_logs WHERE to_number IN (?, ?, ?, ?)', $all);
    $ids = [];
    try { $ids = array_map(static fn(array $r): int => (int) $r['id'], Database::fetchAll('SELECT id FROM support_tickets WHERE RIGHT(phone, 10) IN (?, ?)', [substr($A, -10), substr($B, -10)])); } catch (Throwable $e) {}
    foreach ($ids as $id) {
        $ref = (string) Database::scalar('SELECT ticket_ref FROM support_tickets WHERE id = ?', [$id], '');
        $q('DELETE FROM audit_logs WHERE entity_id = ?', [$ref]);
        $q('DELETE FROM support_messages WHERE ticket_id = ?', [$id]);
        $q('DELETE FROM support_tickets WHERE id = ?', [$id]);
    }
    $q('DELETE FROM audit_logs WHERE entity_id IN (?, ?, ?, ?)', $all);
    $q("DELETE FROM kv_store WHERE kscope IN ('wa_bot_pause', 'wa_greet', 'wa_agent') AND kkey IN (?, ?, ?, ?)", $all);
    $q("DELETE FROM kv_store WHERE kscope = 'wa_inbox_seen_t' AND kkey LIKE ?", ['%:9191' . $tag . '%']);
    $q('DELETE FROM wa_contacts WHERE phone IN (?, ?)', [$A, $B]);
    $q('DELETE FROM ai_feedback_log WHERE owner_key IN (?, ?)', [hash('sha256', $A), hash('sha256', $B)]);
};
register_shutdown_function($cleanup);

echo "\n=== admin/wa-inbox.php — the hub, rendered (U3a) ===\n\n";
if ($super <= 0) {
    check('a superadmin exists in the test database', false);
    exit(1);
}
try {
    $in = static function (string $from, string $text, string $ago = '0') use ($tag): void {
        Database::run("INSERT INTO wa_inbound (wamid, from_digits, msg_type, payload, status, created_at)
                       VALUES (:w, :f, 'text', :p, 'done', NOW() - INTERVAL $ago MINUTE)",
            ['w' => 'wamid.u3ar.' . $tag . '.' . bin2hex(random_bytes(4)), 'f' => $from, 'p' => json_encode(['text' => ['body' => $text]], JSON_UNESCAPED_UNICODE)]);
    };
    $in($A, 'bholi Surat bata bus kati baje? <script>alert(1)</script>', '5');
    $bot = (int) Database::insert('message_logs', ['channel' => 'whatsapp', 'purpose' => 'bot_reply', 'provider' => 'u3a-render',
        'to_number' => $A, 'body' => 'The bus from Surat leaves daily at 1:00 PM.', 'status' => 'sent']);
    $in($B, 'hello', '3');
    $in($B, 'seat cha?', '2');
    Database::run('INSERT INTO wa_contacts (name, phone, note, created_by, created_at, updated_at) VALUES (?, ?, ?, NULL, NOW(), NOW())', ['U3a Render Gita', $A, 'u3a']);

    /* ---- the page ---- */
    $h = $child(['admin' => $super, 'get' => ['tab' => 'inbox', 'p' => $A]]);
    check('the page renders with no PHP error', str_contains($h, 'wi-grid') && !str_contains($h, 'Fatal error') && !str_contains($h, 'Warning:') && !str_contains($h, 'Notice:'), substr(strip_tags($h), 0, 300));
    check('WhatsApp | Messenger | Sent on top (WhatsApp active)', str_contains($h, 'class="wi-chan"') && str_contains($h, 'href="wa-inbox.php" class="on"') && str_contains($h, 'tab=sent'));
    check('today\'s reply times against 30 s / 5 min', str_contains($h, 'aria-label="Reply time today"') && str_contains($h, 'target ≤ 30 s') && str_contains($h, 'target ≤ 5 min'));
    check('search box and the state chips', str_contains($h, 'name="q"') && str_contains($h, 'Agent needed') && str_contains($h, 'Agent handling'));
    check('the list and the header show the NAME', substr_count($h, 'U3a Render Gita') >= 2);
    check('the customer\'s words are escaped', !str_contains($h, '<script>alert(1)</script>') && str_contains($h, '&lt;script&gt;alert(1)&lt;/script&gt;'));
    check('Book for them is prefilled with the number and name', str_contains($h, 'smartbook.php?cphone=' . substr($A, -10) . '&amp;cname=U3a+Render+Gita'));
    check('Escalate is there; Resolved only when not AI', str_contains($h, 'value="escalate"') && !str_contains($h, 'value="resolve"'));
    check('quick replies + Suggest reply', str_contains($h, 'id="wiSuggest"') && str_contains($h, 'data-body='));
    if (WaInbox::feedbackReady() > 0) {
        check('👍 / 👎 beside the bot reply', str_contains($h, 'name="msg" value="' . $bot . '"') && str_contains($h, 'value="verdict"'));
    }
    check('the customer panel with bookings', str_contains($h, 'ग्राहक · Customer') && str_contains($h, 'Bookings'));
    check('the reply form keeps its CSRF field', str_contains($h, 'id="wiReply"') && str_contains($h, 'name="' . CSRF_TOKEN_NAME . '"'));
    check('20 s refresh only while visible', str_contains($h, 'setInterval(poll, 20000)') && str_contains($h, "visibilityState !== 'visible'"));
    $tB = null;
    foreach (WaInbox::threads($super) as $t) { if ($t['digits'] === $B) { $tB = $t; } }
    $tA = null;
    foreach (WaInbox::threads($super) as $t) { if ($t['digits'] === $A) { $tA = $t; } }
    check('opening chat A marked only A as seen', ($tA['unread'] ?? -1) === 0 && ($tB['unread'] ?? 0) >= 2, json_encode([$tA['unread'] ?? null, $tB['unread'] ?? null]));

    /* ---- search + filter ---- */
    $s = $child(['admin' => $super, 'get' => ['tab' => 'inbox', 'q' => 'Render Gita']]);
    check('?q=name keeps only that chat', str_contains($s, '+' . $A) && !str_contains($s, '+' . $B));
    $s = $child(['admin' => $super, 'get' => ['tab' => 'inbox', 'q' => substr($B, -6)]]);
    check('?q=digits finds the unnamed chat', str_contains($s, '+' . $B) && !str_contains($s, 'U3a Render Gita'));

    /* ---- JSON: the 20 s refresh and Suggest ---- */
    $j = $jsonOf($child(['admin' => $super, 'get' => ['ajax' => 'thread', 'p' => $A]]));
    check('ajax=thread returns the chat as JSON', ($j['ok'] ?? false) === true && count($j['items'] ?? []) >= 2 && is_string($j['sig'] ?? null) && ($j['status'] ?? '') === 'ai');
    $botItem = array_values(array_filter($j['items'] ?? [], static fn(array $m): bool => (int) $m['id'] === $bot));
    check('…a bot reply is marked votable', WaInbox::feedbackReady() === 0 || ($botItem[0]['vote'] ?? false) === true);
    $calls0 = (int) Database::scalar('SELECT COUNT(*) FROM ai_agent_calls WHERE phone = ?', [substr($A, -10)], 0);
    $j = $jsonOf($child(['admin' => $super, 'get' => ['ajax' => 'suggest', 'p' => $A]]));
    check('ajax=suggest drafts an answer to their last message', ($j['ok'] ?? false) === true && preg_match('/\d:\d\d (AM|PM)/', (string) ($j['text'] ?? '')) === 1, json_encode($j, JSON_UNESCAPED_UNICODE));
    check('…and writes nothing (no ai_agent_calls row)', (int) Database::scalar('SELECT COUNT(*) FROM ai_agent_calls WHERE phone = ?', [substr($A, -10)], 0) === $calls0);

    /* ---- AJAX posts ---- */
    $before = (int) Database::scalar('SELECT COUNT(*) FROM message_logs WHERE to_number IN (?, ?)', [$A, substr($A, -10)], 0);
    $out = $child(['admin' => $super, 'post' => ['ajax' => '1', 'action' => 'reply', 'phone' => $A, 'text' => 'नमस्ते from the office ' . $tag]]);
    $j = $jsonOf($out);
    check('AJAX reply uses the same 24 h guard: closed window → refused, as JSON', ($j['ok'] ?? true) === false && str_contains((string) ($j['message'] ?? ''), '24-hour'), $out);
    check('…nothing was sent or logged', !str_contains($out, 'WA-SEND') && (int) Database::scalar('SELECT COUNT(*) FROM message_logs WHERE to_number IN (?, ?)', [$A, substr($A, -10)], 0) === $before);
    $j = $jsonOf($child(['admin' => $super, 'csrf' => 'bad', 'post' => ['ajax' => '1', 'action' => 'pause', 'phone' => $A, 'hours' => '2']]));
    check('a bad CSRF token: refused, nothing changed', ($j['ok'] ?? true) === false && !WaInbox::botPaused($A));
    if (WaInbox::feedbackReady() > 0) {
        $j = $jsonOf($child(['admin' => $super, 'post' => ['ajax' => '1', 'action' => 'verdict', 'phone' => $A, 'msg' => (string) $bot, 'v' => 'down', 'note' => 'wrong time']]));
        check('AJAX 👎 is stored', ($j['ok'] ?? false) === true && Database::exists("SELECT 1 FROM ai_feedback_log WHERE owner_key = :k AND verdict = 'down'", ['k' => hash('sha256', $A)]));
        /* P7-9b: the 👎 list on AI Knowledge, with "write the article" */
        $fid = (int) Database::scalar("SELECT id FROM ai_feedback_log WHERE owner_key = :k AND verdict = 'down' ORDER BY id DESC LIMIT 1", ['k' => hash('sha256', $A)], 0);
        $kb = $child(['admin' => $super, 'page' => 'ai-knowledge.php', 'get' => ['from_fb' => (string) $fid]]);
        $okKb = str_contains($kb, 'The knowledge tables are not installed') || (str_contains($kb, 'Bot replies marked wrong') && str_contains($kb, 'from_fb=' . $fid)
            && str_contains($kb, 'The bus from Surat leaves daily at 1:00 PM.') && str_contains($kb, 'value="bholi Surat bata bus kati baje? &lt;script&gt;'));
        check('AI Knowledge lists the 👎 reply and starts an article from the question', $okKb && !str_contains($kb, 'Fatal error') && !str_contains($kb, 'Warning:'), substr(strip_tags($kb), 0, 300));
    }
    $j = $jsonOf($child(['admin' => $super, 'post' => ['ajax' => '1', 'action' => 'escalate', 'phone' => $A]]));
    check('Escalate (POST) opens a Help Desk ticket, customer not messaged', ($j['ok'] ?? false) === true && WaInbox::statusFor($A) === 'needed', json_encode($j, JSON_UNESCAPED_UNICODE));
    $h2 = $child(['admin' => $super, 'get' => ['tab' => 'inbox', 'p' => $A, 'st' => 'needed']]);
    check('?st=needed lists the escalated chat; Resolved appears', str_contains($h2, 'U3a Render Gita') && !str_contains($h2, '+' . $B) && str_contains($h2, 'value="resolve"'));
    $j = $jsonOf($child(['admin' => $super, 'post' => ['ajax' => '1', 'action' => 'resolve', 'phone' => $A]]));
    check('Resolved (POST) closes it again', ($j['ok'] ?? false) === true && WaInbox::statusFor($A) === 'ai');

    /* ---- who sees what ---- */
    $sent = $child(['admin' => $super, 'get' => ['tab' => 'sent', 'q' => substr($A, -8)]]);
    check('Sent tab lists WhatsApp sends (office)', str_contains($sent, 'Sent on WhatsApp — last 7 days') && str_contains($sent, 'The bus from Surat leaves daily')
        && str_contains($sent, 'href="wa-inbox.php?tab=sent" class="on"'));
    $ctr = $child(['admin' => $super, 'role' => 'counter', 'get' => ['tab' => 'sent', 'p' => $A]]);
    check('a counter (no dashboard.view): no Sent tab, the inbox instead', !str_contains($ctr, 'Sent on WhatsApp — last 7 days')
        && !str_contains($ctr, 'href="wa-inbox.php?tab=sent"') && str_contains($ctr, 'wi-grid'));
    $vw = $child(['admin' => $super, 'role' => 'viewer', 'get' => ['tab' => 'inbox', 'p' => $A]]);
    check('a viewer (no customers.view) cannot open the inbox', !str_contains($vw, 'wi-grid') && !str_contains($vw, 'U3a Render Gita'));
} catch (Throwable $e) {
    check('no exception: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    $cleanup();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
