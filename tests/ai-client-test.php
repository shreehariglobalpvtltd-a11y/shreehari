<?php
/**
 * =====================================================================
 *  ai-client-test.php — local model first, cloud second (26 Sep 2026).
 *
 *      php -c .claude/php-dev.ini tests/ai-client-test.php
 *
 *  Guards includes/aiclient.php:
 *    - the keyword rules sort plain messages without any model call
 *    - with the local model off and no cloud key, nothing is called
 *    - with the local model on (a mock Ollama started on 127.0.0.1 by this
 *      test), it answers, the usage row says 'local' and holds no text,
 *      and the PNR / phone number never reach the model
 *    - an endpoint that is not this machine is never called
 *
 *  Restores every setting it touches.
 * =====================================================================
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}
require_once INCLUDE_PATH . '/aiclient.php';

$pass = 0; $fail = 0;
function ac_check(string $label, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$keys  = ['ai_local_on' => 'bool', 'ai_local_endpoint' => 'string', 'ai_local_model' => 'string',
          'ai_local_timeout' => 'int', 'wa_ai_enabled' => 'bool'];
$saved = [];
foreach ($keys as $k => $t) { $saved[$k] = Settings::getString($k, ''); }

$dir    = sys_get_temp_dir() . '/shg-ai-client-test-' . getmypid();
$seen   = $dir . '/seen.json';
$router = $dir . '/router.php';
$proc   = null;
$maxId  = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM ai_provider_usage', [], 0);

try {
    // --- rules ---------------------------------------------------------------
    $cases = [
        'I want to cancel my ticket'          => 'CANCEL',
        'ticket radd garnu paryo'             => 'CANCEL',
        'टिकट रद्द गर्नुस्'                     => 'CANCEL',
        'paid, UTR 123456789012'              => 'PAY_PROOF',
        'maile paisa tireko'                  => 'PAY_PROOF',
        'TICKET K7M2QX'                       => 'TICKET_REQUEST',
        'please send ticket'                  => 'TICKET_REQUEST',
        'booking confirm bhayo?'              => 'STATUS',
        ''                                    => 'OTHER',
    ];
    $ok = true; $bad = '';
    foreach ($cases as $msg => $want) {
        $got = AiClient::classifyByRules((string) $msg);
        if ($got !== $want) { $ok = false; $bad .= "[$msg => " . var_export($got, true) . "] "; }
    }
    ac_check('keyword rules sort plain messages', $ok, $bad);
    ac_check('the rules say "unsure" instead of guessing', AiClient::classifyByRules('namaste, kasto cha') === null);
    ac_check('"cancel" inside another word is not a match', AiClient::classifyByRules('xcancel') === null);

    // --- nothing configured: nothing called ------------------------------------
    Settings::set('ai_local_on', '0', 'bool', 'ai');
    Settings::set('wa_ai_enabled', '0', 'bool', 'whatsapp');
    ac_check('local off and no cloud: chat() returns null', AiClient::chat('hello') === null && AiClient::$lastProvider === 'none');
    ac_check('...and classify() falls back to OTHER', AiClient::classify('namaste, kasto cha') === 'OTHER');
    ac_check('...and nothing was logged',
        (int) Database::scalar('SELECT COUNT(*) FROM ai_provider_usage WHERE id > :m', ['m' => $maxId], 0) === 0);

    // --- mock Ollama on 127.0.0.1 ------------------------------------------------
    @mkdir($dir, 0700, true);
    file_put_contents($router, '<?php
        $in = (string) file_get_contents("php://input");
        file_put_contents(' . var_export($seen, true) . ', $in);
        header("Content-Type: application/json");
        if ($_SERVER["REQUEST_URI"] !== "/api/generate") { http_response_code(404); echo "{}"; return true; }
        $j = json_decode($in, true) ?: [];
        $answer = str_contains((string) ($j["prompt"] ?? ""), "Reply with exactly one word") ? "CANCEL" : "local says hi";
        echo json_encode(["response" => $answer, "prompt_eval_count" => 12, "eval_count" => 3]);
        return true;');
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
    fclose($sock);
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.1);
        if ($c) { fclose($c); break; }
        usleep(100000);
    }

    Settings::set('ai_local_on', '1', 'bool', 'ai');
    Settings::set('ai_local_endpoint', 'http://127.0.0.1:' . $port, 'string', 'ai');
    Settings::set('ai_local_model', 'mock-model', 'string', 'ai');
    Settings::set('ai_local_timeout', '5', 'int', 'ai');

    $text = AiClient::chat('Customer SHG-260926-ABCD from +977 9811000055 asks about the bus');
    ac_check('the local model answers', $text === 'local says hi' && AiClient::$lastProvider === 'local', var_export($text, true));
    $sent = (string) @file_get_contents($seen);
    ac_check('the PNR and phone number never reach the model',
        $sent !== '' && !str_contains($sent, 'SHG-260926-ABCD') && !str_contains($sent, '9811000055'), mb_substr($sent, 0, 120));
    $row = Database::fetch('SELECT * FROM ai_provider_usage WHERE id > :m ORDER BY id DESC LIMIT 1', ['m' => $maxId]) ?? [];
    ac_check('the usage row says local, ok, with token counts',
        ($row['provider'] ?? '') === 'local' && ($row['status'] ?? '') === 'ok' && (int) ($row['input_tokens'] ?? 0) === 12);
    ac_check('...and holds no message text', !str_contains(implode('|', array_map('strval', $row)), 'bus'));

    ac_check('an unsure message is labelled by the local model', AiClient::classify('namaste, kasto cha') === 'CANCEL');

    // --- not this machine: never called ---------------------------------------------
    @unlink($seen);
    Settings::set('ai_local_endpoint', 'http://example.com:' . $port, 'string', 'ai');
    $text = AiClient::chat('hello');
    ac_check('an endpoint that is not this machine is skipped', $text === null && !is_file($seen));
} catch (Throwable $e) {
    ac_check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    @unlink($seen); @unlink($router); @rmdir($dir);
    Database::run('DELETE FROM ai_provider_usage WHERE id > :m', ['m' => $maxId]);
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'notify_%'");
    foreach ($keys as $k => $t) { Settings::set($k, $saved[$k], $t, $k === 'wa_ai_enabled' ? 'whatsapp' : 'ai'); }
}

echo "\n  ai-client: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
