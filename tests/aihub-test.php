<?php
/**
 * =====================================================================
 *  aihub-test.php — AI Command Center + the VPS model as the last rung
 *  (19 Nov 2026).
 *
 *   A. AiAgent ladder: 'local' is the last rung in auto mode, gone when
 *      ai_local_backup_on = 0, not added to an explicit office list.
 *   B. askLocal() through the REAL ApAi against tools/mock-ollama.php
 *      (started here): words only, no tool calls; a dead server -> null.
 *   C. AiHub::status / flow / feed / marketing return their shapes on
 *      shari_test; feed never shows a full phone number.
 *   D. admin/ai-hub.php renders (page + ?json=1) with no PHP error.
 *
 *    php -c .claude/php-dev.ini tests/aihub-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aiagent.php';
require_once INCLUDE_PATH . '/agentpro-ai.php';
require_once INCLUDE_PATH . '/aihub.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function cacheSet(array $kv): void {
    Settings::get('company_name');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    foreach ($kv as $k => [$v, $t]) { $c[$k] = ['value' => $v, 'type' => $t, 'public' => false]; }
    $rp->setValue(null, $c);
}

echo "\n=== AI hub: one pipeline + command center ===\n\n";

// ---- A. ladder
cacheSet(['ai_model_ladder' => ['auto', 'string'], 'ai_provider' => ['gemini', 'string'], 'gemini_api_key' => ['g', 'string'],
          'anthropic_api_key' => ['a', 'string'], 'ai_staff_provider' => ['anthropic', 'string'], 'ai_local_backup_on' => ['1', 'bool'],
          'cerebras_api_key' => ['', 'string'], 'grok_api_key' => ['', 'string'], 'openai_api_key' => ['', 'string'], 'openrouter_api_key' => ['', 'string']]);
$c = AiAgent::buildLadder(['role' => 'customer']);
$s = AiAgent::buildLadder(['role' => 'staff']);
check('customer ladder: cloud first, local last', ($c[0] ?? '') === 'gemini' && end($c) === 'local', implode(',', $c));
check('staff ladder: Claude first, local last', ($s[0] ?? '') === 'anthropic' && end($s) === 'local', implode(',', $s));
cacheSet(['ai_local_backup_on' => ['0', 'bool']]);
check('ai_local_backup_on = 0 → no local rung', !in_array('local', AiAgent::buildLadder(['role' => 'customer']), true));
cacheSet(['ai_local_backup_on' => ['1', 'bool'], 'ai_model_ladder' => ['gemini,anthropic', 'string']]);
check('an explicit office list is kept as written', AiAgent::buildLadder(['role' => 'customer']) === ['gemini', 'anthropic']);
cacheSet(['ai_model_ladder' => ['auto', 'string']]);

// ---- B. askLocal through the real ApAi + mock Ollama
$port = 11555;
$alive = static fn(): bool => @file_get_contents('http://127.0.0.1:' . $port . '/api/version') !== false;
$proc = null;
if (!$alive()) {
    $args = php_ini_loaded_file() ? [PHP_BINARY, '-c', (string) php_ini_loaded_file()] : [PHP_BINARY];
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = proc_open(array_merge($args, ['-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/tools/mock-ollama.php']),
        [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
    for ($i = 0; $i < 40 && !$alive(); $i++) { usleep(100000); }
}
$up = $alive();
check('mock model server started', $up);
cacheSet(['ap_ai_base' => ['http://127.0.0.1:' . $port, 'string'], 'ap_ai_on' => ['0', 'bool'], 'ap_ai_model' => ['qwen3.5:4b', 'string']]);
ApAi::resetHealth();
check('backup is usable while ap_ai_on stays OFF (the VPS model does not replace the cloud)', AiAgent::localBackupOk() && !ApAi::configured());
$m = new ReflectionMethod(AiAgent::class, 'askLocal');
$out = $m->invoke(null, 'You are the S Hari bus assistant.', [
    ['role' => 'user', 'content' => 'bholi Surat bata kati baje bus cha?'],
    ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'search', 'input' => []]]],
    ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '13:00 Surat → Rupaidiha']]],
]);
check('askLocal answers in words', is_array($out) && trim((string) ($out['text'] ?? '')) !== '', json_encode($out, JSON_UNESCAPED_UNICODE));
check('askLocal never returns a tool call', is_array($out) && ($out['calls'] ?? null) === []);
cacheSet(['ap_ai_base' => ['http://127.0.0.1:1', 'string']]);
ApAi::resetHealth();
check('dead model server → no local rung', !AiAgent::localBackupOk());
if (is_resource($proc)) { proc_terminate($proc); }
ApAi::resetHealth();

// ---- C. AiHub shapes
$st = AiHub::status();
check('status has all six engines', array_keys($st) === AiHub::ENGINES, implode(',', array_keys($st)));
$f = AiHub::flow(24);
check('flow has channels / brains / outcomes', isset($f['channels']['whatsapp'], $f['channels']['desk'], $f['outcomes']['tickets'], $f['outcomes']['failed']));
check('flow hours are clamped', AiHub::flow(999999)['hours'] === 720 && AiHub::flow(0)['hours'] === 1);
$feed = AiHub::feed(30);
$leak = false;
foreach ($feed as $ev) { if (preg_match('/\d{7,}/', $ev['text'])) { $leak = true; } }
check('feed is newest first and never shows a full number', !$leak && ($feed === [] || $feed[0]['at'] >= end($feed)['at']));
check('code hints in Nepali · English', str_contains(AiHub::codeHint('131070'), 'AiSensy') && str_contains(AiHub::codeHint('131070'), ' · '));
check('marketing tile returns a list', is_array(AiHub::marketing(5)));

// ---- D. the page renders
$ini = php_ini_loaded_file() ?: '';
foreach (['' => 'page', 'json=1' => 'json'] as $q => $what) {
    $cmd = escapeshellarg(PHP_BINARY) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__DIR__ . '/render-admin.php') . ' ai-hub.php' . ($q !== '' ? ' ' . $q : '') . ' 2>&1';
    $html = (string) shell_exec($cmd);
    check("ai-hub.php $what renders without a PHP error", strlen($html) > 500 && !preg_match('/(Fatal error|Uncaught|<b>Warning<\/b>|PHP Warning)/', $html), substr($html, 0, 200));
    if ($what === 'json') {
        $j = json_decode(trim(substr($html, (int) strpos($html, '{'))), true);
        check('?json=1 is valid JSON with status + flow + feed', is_array($j) && isset($j['status'], $j['flow'], $j['feed']));
    } else {
        check('page carries the flow SVG and the Test buttons wiring', str_contains($html, 'id="hubSvg"') && str_contains($html, "act', 'ping'"));
    }
}

echo "\n" . ($fail === 0 ? "  $pass passed, 0 failed\n" : "  $pass passed, $fail failed\n");
exit($fail === 0 ? 0 : 1);
