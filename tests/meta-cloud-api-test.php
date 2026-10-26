<?php
/**
 * =====================================================================
 *  meta-cloud-api-test.php — whatsapp/ (Meta WhatsApp Cloud API) module.
 *
 *  Pins, against the TEST database:
 *    - waCleanPhone(): India (91) / Nepal (977) normalisation;
 *    - waGraphPost() never throws and returns the success/error array, and
 *      a Meta 4xx is NOT retried (one attempt in the day log);
 *    - whatsapp/webhook.php (served by `php -S` with env credentials):
 *        GET verify handshake, wrong token 403, unsigned POST 403,
 *        delivery status -> message_logs by wamid, other phone number IDs
 *        ignored, inbound message answered once (duplicates dropped).
 *
 *    php tests/meta-cloud-api-test.php
 *
 *  Talks to graph.facebook.com only with a deliberately invalid token.
 *  Cleans every row it writes.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

// Credentials for THIS process, before whatsapp/config.php reads settings.
const T_SECRET  = 'metatest-app-secret';
const T_VERIFY  = 'metatest-verify';
const T_PHONEID = '555000111222333';
const T_SENDER  = '919999000111';
define('META_ACCESS_TOKEN', 'INVALID_TEST_TOKEN');
define('META_PHONE_NUMBER_ID', T_PHONEID);
define('META_APP_SECRET', T_SECRET);
define('WHATSAPP_WEBHOOK_TOKEN', T_VERIFY);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/whatsapp/api.php';

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
    Database::run("DELETE FROM message_logs WHERE provider_ref LIKE 'wamid.METATEST%' OR to_number = :t", ['t' => T_SENDER]);
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :t", ['t' => T_SENDER]);
    Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = 'wa_meta.billing_ok_at'");
    Database::run("DELETE FROM rate_limits WHERE (bucket = 'wa_meta_seen' AND identifier LIKE 'wamid.METATEST%') OR (bucket = 'wa_bot' AND identifier = :n)",
        ['n' => normalisePhone(T_SENDER)]);
};
$cleanup();

echo "\n— waCleanPhone\n";
check('bare Indian 10-digit + IN hint', waCleanPhone('9104801507', 'IN') === '919104801507');
check('formatted +91 number',          waCleanPhone('+91 91048-01507') === '919104801507');
check('bare Nepali 10-digit + NP hint', waCleanPhone('9801234567', 'NP') === '9779801234567');
check('Nepali with trunk 0',            waCleanPhone('09801234567', 'NP') === '9779801234567');
check('00977 prefix',                   waCleanPhone('00977 9801234567') === '9779801234567');
check('whatsapp:+ prefix',              waCleanPhone('whatsapp:+919104801507') === '919104801507');
check('garbage -> empty',               waCleanPhone('123') === '');

echo "\n— waGraphPost / helpers never throw\n";
$r = sendWhatsAppText('123', 'x');
check('invalid phone -> error array', $r['success'] === false && $r['error'] === 'invalid phone number');
$r = sendWhatsAppTemplate('9104801507', '', 'en');
check('empty template name refused', $r['success'] === false && str_contains($r['error'], 'template name'));
$r = sendWhatsAppImage('9104801507', 'http://insecure.example/x.png');
check('non-https image refused', $r['success'] === false && str_contains($r['error'], 'https'));

$logFile = WHATSAPP_LOG_DIR . '/' . date('Y-m-d') . '.log';
$before  = is_file($logFile) ? count(file($logFile)) : 0;
$r = sendWhatsAppText(T_SENDER, 'meta test');
$after  = is_file($logFile) ? file($logFile) : [];
check('invalid token -> success=false, no exception', $r['success'] === false && $r['http'] >= 400, $r['error']);
check('4xx is not retried (exactly one log line)', count($after) - $before === 1, (string) (count($after) - $before));
check('log line format', (bool) preg_match('/^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\] TEXT \| ' . T_SENDER . ' \| FAIL \| - \| attempt 1/', (string) end($after)));

echo "\n— whatsapp/webhook.php over HTTP\n";
$port = 8897;
$env  = 'META_APP_SECRET=' . T_SECRET . ' WA_WEBHOOK_TOKEN=' . T_VERIFY . ' META_PHONE_NUMBER_ID=' . T_PHONEID
      . ' META_ACCESS_TOKEN=INVALID_TEST_TOKEN';
$pid  = (int) shell_exec('cd ' . escapeshellarg(ROOT_PATH) . ' && ' . $env . ' nohup php -S 127.0.0.1:' . $port
      . ' -t ' . escapeshellarg(ROOT_PATH) . ' > /dev/null 2>&1 & echo $!');
usleep(800000);

$http = static function (string $method, string $query = '', string $body = '', array $headers = []) use ($port): array {
    $ch = curl_init('http://127.0.0.1:' . $port . '/whatsapp/webhook.php' . ($query !== '' ? '?' . $query : ''));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $out];
};
$signed = static function (array $payload) use ($http): array {
    $raw = (string) json_encode($payload);
    return $http('POST', '', $raw, ['X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $raw, T_SECRET)]);
};
$event = static function (string $phoneId, array $value): array {
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [[
        'field' => 'messages',
        'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '919173401507', 'phone_number_id' => $phoneId]] + $value,
    ]]]]];
};

try {
    [$c, $o] = $http('GET', 'hub.mode=subscribe&hub.verify_token=' . T_VERIFY . '&hub.challenge=1234567890');
    check('GET verify echoes challenge', $c === 200 && $o === '1234567890', "$c $o");
    [$c] = $http('GET', 'hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1');
    check('GET wrong verify token -> 403', $c === 403, (string) $c);
    [$c] = $http('POST', '', '{"object":"whatsapp_business_account"}');
    check('unsigned POST -> 403', $c === 403, (string) $c);
    [$c] = $http('POST', '', '{"object":"whatsapp_business_account"}', ['X-Hub-Signature-256: sha256=deadbeef']);
    check('bad signature -> 403', $c === 403, (string) $c);

    // Seed two "sent" ticket rows carrying Meta wamids.
    foreach (['wamid.METATEST1', 'wamid.METATEST2', 'wamid.METATEST3'] as $w) {
        Database::insert('message_logs', ['channel' => 'whatsapp', 'provider' => 'cloud_api', 'to_number' => T_SENDER,
            'body' => 'meta test', 'status' => 'sent', 'provider_ref' => $w]);
    }
    $status = static fn (string $w) => Database::fetch('SELECT status, error FROM message_logs WHERE provider_ref = :w', ['w' => $w]);

    [$c, $o] = $signed($event(T_PHONEID, ['statuses' => [[
        'id' => 'wamid.METATEST1', 'status' => 'failed', 'recipient_id' => T_SENDER, 'timestamp' => (string) time(),
        'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
    ]]]));
    check('signed status POST -> 200 EVENT_RECEIVED', $c === 200 && $o === 'EVENT_RECEIVED', "$c $o");
    $row = $status('wamid.METATEST1');
    check('failed status flips row to failed', ($row['status'] ?? '') === 'failed');
    check('error carries "(code 131026)" for the retry cron', str_contains((string) ($row['error'] ?? ''), '(code 131026)'), (string) ($row['error'] ?? ''));

    $signed($event(T_PHONEID, ['statuses' => [['id' => 'wamid.METATEST2', 'status' => 'read', 'recipient_id' => T_SENDER,
        'pricing' => ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'utility']]]]));
    check('read status keeps row sent, error cleared', ($status('wamid.METATEST2')['status'] ?? '') === 'sent');
    check('billable delivery records proof that WABA billing works',
        (int) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope='global' AND kkey='wa_meta.billing_ok_at'", [], 0) > time() - 60);

    $signed($event('999999999', ['statuses' => [['id' => 'wamid.METATEST3', 'status' => 'failed', 'recipient_id' => T_SENDER,
        'errors' => [['code' => 131026]]]]]));
    check('status for ANOTHER phone number id is ignored', ($status('wamid.METATEST3')['status'] ?? '') === 'sent');

    $inbound = $event(T_PHONEID, [
        'contacts' => [['profile' => ['name' => 'Test'], 'wa_id' => T_SENDER]],
        'messages' => [['from' => T_SENDER, 'id' => 'wamid.METATEST_IN1', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'hi']]],
    ]);
    [$c] = $signed($inbound);
    check('inbound message -> 200', $c === 200);
    usleep(300000);
    $bot = static fn () => (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE to_number = :t AND purpose = 'bot_reply'", ['t' => T_SENDER]);
    check('bot reply attempted and logged once', $bot() === 1, (string) $bot());
    $kv = Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :t", ['t' => T_SENDER]);
    check('24 h window noted for the sender', is_string($kv) && (int) $kv > time() - 60);
    $signed($inbound);
    usleep(300000);
    check('re-delivered message is not answered twice', $bot() === 1, (string) $bot());
} finally {
    if ($pid > 0) {
        posix_kill($pid, SIGTERM);
    }
    $cleanup();
}

echo "\nmeta-cloud-api: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
