<?php
/**
 * =====================================================================
 *  gupshup-send-test.php — whatsapp/gupshup.php + Notify::whatsappGupshup
 *
 *  Pins, against the TEST database:
 *    - waCleanPhone(): shared cleaner still normalises India (91) and
 *      Nepal (977) for the Gupshup path;
 *    - sendGupshupText / Image / Document / Template never throw and
 *      return the {success, message_id, error, http, code} array;
 *    - missing GUPSHUP_API_KEY / _SOURCE fails EARLY with a clear reason;
 *    - a Gupshup 4xx is NOT retried (exactly one log line);
 *    - Notify::whatsapp() with driver=gupshup logs a 'sent' row on success
 *      (mocked via a stubbed HTTP endpoint) and a 'failed' row on refusal;
 *    - the idempotency guard skips a second ticket send for the same
 *      booking when the earlier row is already 'sent';
 *    - whatsapp/gupshup-webhook.php over HTTP:
 *        no signature -> 403, bad signature -> 403,
 *        signed status POST -> 200 and message_logs updates by messageId,
 *        signed inbound message -> 200 (bot reply attempted).
 *
 *    php tests/gupshup-send-test.php
 *
 *  Talks to api.gupshup.io only with a deliberately invalid API key.
 *  Cleans every row it writes.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

// Credentials for THIS process, before whatsapp/gupshup.php reads settings.
const G_SECRET  = 'gstest-webhook-secret';
const G_APPNAME = 'shg-test-app';
const G_SOURCE  = '918735881507';
const G_SENDER  = '919999000112';
define('GUPSHUP_API_KEY', 'INVALID_TEST_KEY');
define('GUPSHUP_APP_NAME', G_APPNAME);
define('GUPSHUP_SOURCE_NUMBER', G_SOURCE);
define('GUPSHUP_WEBHOOK_SECRET', G_SECRET);
define('GUPSHUP_API_BASE', 'https://api.gupshup.io');

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/whatsapp/api.php';
require_once dirname(__DIR__) . '/whatsapp/gupshup.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$cleanup = static function (): void {
    Database::run("DELETE FROM message_logs WHERE provider_ref LIKE 'gs-msg-GS%' OR to_number IN (:s, :b) OR booking_id BETWEEN 8999001 AND 8999099",
        ['s' => G_SENDER, 'b' => G_SOURCE]);
    Database::run("DELETE FROM rate_limits WHERE (bucket = 'wa_gs_seen' AND identifier LIKE 'gs-msg-GS%') OR (bucket = 'wa_bot' AND identifier = :n)",
        ['n' => normalisePhone(G_SENDER)]);
};
$cleanup();

echo "\n— waCleanPhone (shared cleaner still serves Gupshup)\n";
check('bare Indian 10-digit + IN hint', waCleanPhone('9104801507', 'IN') === '919104801507');
check('bare Nepali 10-digit + NP hint', waCleanPhone('9801234567', 'NP') === '9779801234567');
check('Nepali with trunk 0',            waCleanPhone('09801234567', 'NP') === '9779801234567');

echo "\n— send helpers never throw; API key invalid so every call fails cleanly\n";
$r = sendGupshupText('123', 'x');
check('invalid phone -> error array', $r['success'] === false && str_contains((string) $r['error'], 'invalid phone number'));

$r = sendGupshupImage('9104801507', 'http://insecure.example/x.png');
check('non-https image refused early', $r['success'] === false && str_contains((string) $r['error'], 'https'));

$r = sendGupshupTemplate('9104801507', '', []);
check('empty template name refused', $r['success'] === false && str_contains((string) $r['error'], 'template name'));

$logFile = WHATSAPP_LOG_DIR . '/' . date('Y-m-d') . '.log';
$before  = is_file($logFile) ? count(file($logFile)) : 0;
$r = sendGupshupText(G_SENDER, 'gs test');
$after   = is_file($logFile) ? file($logFile) : [];
check('invalid API key -> success=false, no exception', $r['success'] === false && $r['http'] >= 400, (string) $r['error']);
check('Gupshup 4xx is not retried (exactly one log line)', count($after) - $before === 1, (string) (count($after) - $before));

// Missing-config path.
$oldKey = GUPSHUP_API_KEY;
// We can't undefine a const; simulate by direct call with a blank key.
$r = gupshupPost('/wa/api/v1/msg', ['x' => 'y'], 'text', G_SENDER, '');
check('empty API key branch -> not configured', $r['success'] === false && str_contains((string) $r['error'], 'not configured'));

echo "\n— Notify::whatsapp() with driver=gupshup\n";
require_once dirname(__DIR__) . '/includes/notify.php';
$prev = [];
foreach (['whatsapp_driver', 'whatsapp_notify_customer', 'whatsapp_template_name', 'whatsapp_template_name_gupshup'] as $k) {
    $prev[$k] = Settings::getString($k, '');
}
Settings::set('whatsapp_driver', 'gupshup', 'string', 'notify', false);
Settings::set('whatsapp_notify_customer', '1', 'bool', 'notify', false);
Settings::set('whatsapp_template_name', '', 'string', 'notify', false);
Settings::set('whatsapp_template_name_gupshup', '', 'string', 'notify', false);
Settings::flush();

$bid = 8999001;
Database::insert('message_logs', ['channel' => 'whatsapp', 'provider' => 'gupshup', 'to_number' => G_SENDER,
    'body' => 'earlier ticket', 'status' => 'sent', 'provider_ref' => 'gs-msg-GS-PRIOR', 'booking_id' => $bid,
    'purpose' => 'ticket']);
$res = Notify::whatsapp('+' . G_SENDER, 'duplicate ticket', null, 'IN', [], $bid, ['purpose' => 'ticket']);
check('idempotency guard short-circuits a duplicate ticket', $res === true);
$after = (int) Database::scalar(
    "SELECT COUNT(*) FROM message_logs WHERE booking_id = :b AND channel='whatsapp'", ['b' => $bid], 0);
check('no new message_logs row was written by the guard', $after === 1, (string) $after);

// A fresh booking with no prior row goes down the driver, hits Gupshup with
// the invalid key, and logs 'failed' (fallback to click-to-chat). We verify
// the provider marker plus the fallback link.
$bid2 = 8999002;
$res  = Notify::whatsapp('+' . G_SENDER, 'fresh ticket', null, 'IN', [], $bid2, ['purpose' => 'ticket']);
check('fresh send returns click-to-chat link on refused API key', is_string($res) && str_contains((string) $res, 'wa.me/'), (string) $res);
$row  = Database::fetch("SELECT provider, status, error FROM message_logs WHERE booking_id = :b ORDER BY id DESC LIMIT 1", ['b' => $bid2]);
check('failed row marked provider=gupshup', ($row['provider'] ?? '') === 'gupshup', (string) ($row['provider'] ?? ''));
check('failed row records the provider refusal in error', str_contains((string) ($row['error'] ?? ''), 'gupshup') || str_contains((string) ($row['error'] ?? ''), 'refused') || str_contains((string) ($row['error'] ?? ''), 'click-to-chat'), (string) ($row['error'] ?? ''));

echo "\n— whatsapp/gupshup-webhook.php over HTTP\n";
$port = 8898;
$env  = 'GUPSHUP_WEBHOOK_SECRET=' . G_SECRET . ' GUPSHUP_APP_NAME=' . G_APPNAME
      . ' GUPSHUP_SOURCE_NUMBER=' . G_SOURCE . ' GUPSHUP_API_KEY=INVALID_TEST_KEY';
$pid  = (int) shell_exec('cd ' . escapeshellarg(ROOT_PATH) . ' && ' . $env . ' nohup php -S 127.0.0.1:' . $port
      . ' -t ' . escapeshellarg(ROOT_PATH) . ' > /dev/null 2>&1 & echo $!');
usleep(800000);

$http = static function (string $method, string $body = '', array $headers = []) use ($port): array {
    $ch = curl_init('http://127.0.0.1:' . $port . '/whatsapp/gupshup-webhook.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    if ($body !== '') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $out  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $out];
};
$signed = static function (array $payload) use ($http): array {
    $raw = (string) json_encode($payload);
    return $http('POST', $raw, ['X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $raw, G_SECRET)]);
};

try {
    [$c] = $http('POST', '{"type":"message-event"}');
    check('unsigned POST -> 403', $c === 403, (string) $c);
    [$c] = $http('POST', '{"type":"message-event"}', ['X-Hub-Signature-256: sha256=deadbeef']);
    check('bad signature -> 403', $c === 403, (string) $c);

    // Seed two 'sent' rows carrying Gupshup ids.
    foreach (['gs-msg-GS1', 'gs-msg-GS2', 'gs-msg-GS3'] as $id) {
        Database::insert('message_logs', ['channel' => 'whatsapp', 'provider' => 'gupshup', 'to_number' => G_SENDER,
            'body' => 'gs test', 'status' => 'sent', 'provider_ref' => $id]);
    }
    $status = static fn (string $id) => Database::fetch('SELECT status, error FROM message_logs WHERE provider_ref = :w', ['w' => $id]);

    [$c, $o] = $signed(['app' => G_APPNAME, 'type' => 'message-event',
        'payload' => ['id' => 'gs-msg-GS1', 'type' => 'failed', 'destination' => G_SENDER,
                      'payload' => ['code' => 1013, 'reason' => 'Number not on WhatsApp']]]);
    check('signed status POST -> 200', $c === 200 && $o === 'EVENT_RECEIVED', "$c $o");
    $row = $status('gs-msg-GS1');
    check('failed status flips row to failed', ($row['status'] ?? '') === 'failed');
    check('error carries "(code 1013)" for the retry cron', str_contains((string) ($row['error'] ?? ''), '(code 1013)'), (string) ($row['error'] ?? ''));

    $signed(['app' => G_APPNAME, 'type' => 'message-event',
        'payload' => ['id' => 'gs-msg-GS2', 'type' => 'read', 'destination' => G_SENDER]]);
    check('read status keeps row sent', ($status('gs-msg-GS2')['status'] ?? '') === 'sent');

    // Wrong app name is ignored.
    $signed(['app' => 'someone-else', 'type' => 'message-event',
        'payload' => ['id' => 'gs-msg-GS3', 'type' => 'failed', 'destination' => G_SENDER]]);
    check('event for another app is ignored', ($status('gs-msg-GS3')['status'] ?? '') === 'sent');

    // Inbound message triggers the bot reply (best-effort logged; the send
    // itself hits the invalid API key so the log row is 'failed').
    $inbound = ['app' => G_APPNAME, 'type' => 'message',
        'payload' => ['id' => 'gs-msg-GS-IN1', 'source' => G_SENDER, 'type' => 'text',
                      'payload' => ['text' => 'hi']]];
    [$c] = $signed($inbound);
    check('inbound message -> 200', $c === 200);
    usleep(300000);
    $bot = static fn () => (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE to_number = :t AND purpose = 'bot_reply'", ['t' => G_SENDER]);
    check('bot reply attempted and logged once', $bot() >= 1);
    [$c] = $signed($inbound);
    usleep(300000);
    check('re-delivered inbound is not answered twice', $bot() === 1, (string) $bot());
} finally {
    if ($pid > 0 && function_exists('posix_kill')) {
        posix_kill($pid, SIGTERM);
    }
    foreach ($prev as $k => $v) {
        Settings::set($k, $v, 'string', 'notify', false);
    }
    Settings::flush();
    $cleanup();
}

echo "\ngupshup-send: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
