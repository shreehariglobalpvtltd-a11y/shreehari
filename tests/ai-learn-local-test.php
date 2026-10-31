<?php
/**
 * Offline tests for AiLearn's local-model drafting (1 Nov 2026): no DB, no
 * real model, no messages. The REAL ApAi talks to tools/mock-ollama.php
 * (through tests/ai-learn-router.php) on a free port.
 *
 *    php tests/ai-learn-local-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('SHG_APP', true);
define('ROOT_PATH', dirname(__DIR__));
define('INCLUDE_PATH', ROOT_PATH . '/includes');
define('LOG_PATH', sys_get_temp_dir() . '/shg-learn-test-' . getmypid());
@mkdir(LOG_PATH);

/* Stand-ins for the app classes these files read. */
final class Settings
{
    public static array $v = [];
    public static function getBool(string $k, bool $d = false): bool { return array_key_exists($k, self::$v) ? (bool) self::$v[$k] : $d; }
    public static function getString(string $k, string $d = ''): string { return (string) (self::$v[$k] ?? $d); }
    public static function getInt(string $k, int $d = 0): int { return (int) (self::$v[$k] ?? $d); }
}
final class Logger
{
    public static array $lines = [];
    public static function __callStatic(string $n, array $a): void { self::$lines[] = $n . ': ' . (is_string($a[0] ?? null) ? $a[0] : ''); }
}
final class Database
{
    public static function __callStatic(string $n, array $a): mixed { throw new RuntimeException('no DB in this test'); }
}

require_once INCLUDE_PATH . '/ailearn.php';

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks; $checks++;
    if (!$condition) { throw new RuntimeException('FAIL ' . $label); }
    echo "PASS $label\n";
}
$ask = new ReflectionMethod(AiLearn::class, 'ask');
$askIt = static fn(string $user, ?float $until) => $ask->invoke(null, 'FACTS: one bag allowed.', $user, $until);

/* ---- the fake model server ---- */
$s = stream_socket_server('tcp://127.0.0.1:0');
$name = (string) stream_socket_get_name($s, false);
$port = (int) substr($name, strrpos($name, ':') + 1);
fclose($s);
$log = LOG_PATH . '/requests.log';
touch($log);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/ai-learn-router.php'],
    [['pipe', 'r'], ['file', LOG_PATH . '/srv.out', 'w'], ['file', LOG_PATH . '/srv.err', 'w']],
    $pipes, null, array_merge(getenv(), ['SHG_MOCK_LOG' => $log]));
register_shutdown_function(static function () use ($proc) {
    proc_terminate($proc);
    foreach (glob(LOG_PATH . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir(LOG_PATH);
});
for ($i = 0; $i < 50; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $e, $es, 0.1);
    if ($c) { fclose($c); break; }
    usleep(100000);
}
$sent = static fn(): array => array_values(array_map(static fn($l) => json_decode($l, true),
    array_filter(explode("\n", (string) file_get_contents($log)))));

/* ---- 1. local model on and answering ---- */
Settings::$v = ['ap_ai_on' => true, 'ap_ai_base' => 'http://127.0.0.1:' . $port, 'ap_ai_doc_timeout' => 60];
check(AiLearn::localReady(), 'local model ready: switch on (default) + server answering');

[$j, $who, $local] = $askIt('Customer question: kati saman lana milcha?', microtime(true) + 120);
check($local === true && $who === 'local VPS model', 'the VPS model drafts first');
check(($j['answerable'] ?? null) === true && ($j['title'] ?? '') === 'Luggage on the bus', 'the article JSON comes back decoded');
check(str_contains((string) ($j['answer_ne'] ?? ''), 'झोला'), 'the Nepali answer comes back');
$req = $sent()[0] ?? [];
check(($req['format']['required'] ?? []) === ['answerable', 'title', 'answer_en', 'answer_ne', 'keywords'], 'the model is held to the article schema');
check(str_contains((string) ($req['messages'][0]['content'] ?? ''), 'FACTS: one bag allowed.'), 'the company facts are the system prompt');
check(($req['stream'] ?? null) === false, 'one non-streamed answer');

[$j] = $askIt('Customer question: unanswerable thing', microtime(true) + 120);
check(($j['answerable'] ?? null) === false, 'an honest "not answerable" is passed through (the caller marks needs_owner)');

/* ---- 2. when the local model must NOT be used ---- */
file_put_contents($log, '');
[$j, $who, $local] = $askIt('Customer question: x', null);
check($j === null && $local === false && $sent() === [], 'no budget (the web button) → local not asked; no cloud key → nothing');

[$j, , $local] = $askIt('Customer question: x', microtime(true) + 5);
check($j === null && $local === false && $sent() === [], 'under 15 s of budget left → local not asked');

Settings::$v['ai_learn_local'] = false;
check(!AiLearn::localReady(), 'ai_learn_local off → cloud only, the old way');
[$j, , $local] = $askIt('Customer question: x', microtime(true) + 120);
check($j === null && $local === false && $sent() === [], 'switched off → the VPS model is never called');
unset(Settings::$v['ai_learn_local']);

Settings::$v['ap_ai_on'] = false;
check(!AiLearn::localReady(), 'ap_ai_on off → not ready');
Settings::$v['ap_ai_on'] = true;

/* ---- 3. a dead server fails soft ---- */
Settings::$v['ap_ai_base'] = 'http://127.0.0.1:1';
check(!AiLearn::localReady(), 'server not answering → not ready (no "busy" wait)');
[$j, , $local] = $askIt('Customer question: x', microtime(true) + 120);
check($j === null && $local === false, 'dead server and no cloud key → no draft, no exception');

/* ---- 4. the wiring in draft() / learn() ---- */
$src = (string) file_get_contents(INCLUDE_PATH . '/ailearn.php');
check(str_contains($src, '$publish = !$local && Settings::getBool(\'ai_learn_autopublish\''), 'a local draft is never auto-published');
check(str_contains($src, "\$until = PHP_SAPI === 'cli'"), 'the local budget exists only for the CLI cron');
check(in_array('kb_learn', ApAi::DOC_PURPOSES, true), 'kb_learn is a document-class purpose: it gives way to WhatsApp');

echo "OK $checks checks\n";
