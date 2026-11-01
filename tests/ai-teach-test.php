<?php
/**
 * Offline tests for includes/aiteach.php (1 Nov 2026): no DB, no Claude,
 * no money. The API, the facts and the store are replaced through
 * AiTeach's test seams.
 *
 *    php tests/ai-teach-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('SHG_APP', true);
define('INCLUDE_PATH', dirname(__DIR__) . '/includes');

final class Settings
{
    public static array $v = [];
    public static function getBool(string $k, bool $d = false): bool { return array_key_exists($k, self::$v) ? (bool) self::$v[$k] : $d; }
    public static function getString(string $k, string $d = ''): string { return (string) (self::$v[$k] ?? $d); }
    public static function getInt(string $k, int $d = 0): int { return (int) (self::$v[$k] ?? $d); }
    public static function officePhone(): string { return '+91 98000 11111'; }
}
final class Logger
{
    public static array $lines = [];
    public static function __callStatic(string $n, array $a): void { self::$lines[] = $n . ': ' . (is_string($a[0] ?? null) ? $a[0] : ''); }
}

require_once INCLUDE_PATH . '/ailearn.php';
require_once INCLUDE_PATH . '/aiteach.php';

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks; $checks++;
    if (!$condition) { throw new RuntimeException('FAIL ' . $label); }
    echo "PASS $label\n";
}

/* ---- seams ---- */
$calls = [];
$replies = [];                                     // queue of [http, body-array]
AiTeach::$http = static function (array $body) use (&$calls, &$replies): array {
    $calls[] = $body;
    [$http, $j] = array_shift($replies) ?? [500, ['error' => ['message' => 'no more replies queued']]];
    return ['ok' => $http === 200, 'http' => $http, 'body' => json_encode($j, JSON_UNESCAPED_UNICODE)];
};
AiTeach::$facts = static fn(): array => ['facts' => 'FACT: tickets are booked on the website or WhatsApp.', 'titles' => ['How do I book a ticket?'], 'questions' => ['saman kati lana milcha']];
$saved = [];
AiTeach::$store = static function (array $a, bool $publish) use (&$saved): int { $saved[] = [$a['title'], $publish]; return count($saved); };
AiTeach::$kv = [];

$msg = static fn(array $data, array $usage = ['input_tokens' => 1000, 'output_tokens' => 2000], string $stop = 'end_turn'): array
    => [200, ['stop_reason' => $stop, 'usage' => $usage, 'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE)]]]];
$art = static fn(string $title, string $en = 'You can carry one bag in the boot and a small bag with you on board.'): array => [
    'title' => $title, 'audience' => 'customer', 'category' => 'luggage', 'answer_en' => $en,
    'answer_ne' => 'डिक्कीमा एउटा झोला र भित्र सानो झोला।', 'answer_hi' => 'डिक्की में एक बैग और साथ में छोटा बैग।',
    'roman_ne' => 'saman kati lana milcha', 'roman_hi' => 'kitna saman le ja sakte', 'keywords' => 'luggage bag',
];

/* ---- 1. off ---- */
Settings::$v = [];
check(!AiTeach::on(), 'no Anthropic key → off');
check(AiTeach::run()['note'] === 'off or no Anthropic key', 'run refuses while off');

/* ---- 2. pricing ---- */
Settings::$v = ['anthropic_api_key' => 'test-key'];
check(AiTeach::model() === 'claude-opus-5-5', 'default teacher is Opus 5.5');
check(abs(AiTeach::cost(['input_tokens' => 1_000_000], 'claude-opus-5-5') - 4.0) < 1e-9, 'input $4 / MTok');
check(abs(AiTeach::cost(['output_tokens' => 1_000_000], 'claude-opus-5-5') - 20.0) < 1e-9, 'output $20 / MTok');
check(abs(AiTeach::cost(['cache_read_input_tokens' => 1_000_000, 'cache_creation_input_tokens' => 1_000_000], 'claude-opus-5-5') - 5.4) < 1e-9, 'cache read 0.1x, cache write 1.25x');

/* ---- 3. one good round ---- */
$replies = [
    $msg(['articles' => [
        $art('Luggage allowance'),
        $art('How do I book a ticket?'),                                         // already written → skipped
        $art('Night bus fare', 'The night bus costs 1200 rupees per seat.'),      // a number → rejected
        $art('Lost item on the bus', 'Tell the office on WhatsApp the same day; the crew checks the bus and the office calls you back.'),
    ]]),
    $msg(['results' => [['index' => 0, 'supported' => true, 'problem' => ''], ['index' => 1, 'supported' => false, 'problem' => 'not in facts']]]),
    $msg(['articles' => []]),                                                    // round 2: nothing more
];
$r = AiTeach::run(2.0);
check($r['ok'] && $r['written'] === 2, 'two new articles written (duplicate and number one dropped)');
check($r['rejected'] === 1, 'an article with a fare is rejected');
check($saved === [['Luggage allowance', true], ['Lost item on the bus', false]], 'fact-checked → published, doubtful → draft');
check($r['published'] === 1 && $r['drafted'] === 1, 'counts match');
check($r['note'] === 'nothing new to write', 'stops when Claude has nothing new');
$expect = 3 * AiTeach::cost(['input_tokens' => 1000, 'output_tokens' => 2000], 'claude-opus-5-5');
check(abs($r['cost'] - round($expect, 4)) < 1e-6 && abs(AiTeach::spent() - $expect) < 1e-6, 'spend = priced usage of all three calls, saved');
check(count(AiTeach::history()) === 1, 'the run is logged');

/* ---- 4. the request itself ---- */
$b = $calls[0];
check($b['model'] === 'claude-opus-5-5' && $b['max_tokens'] === 12000, 'model + max_tokens');
check(($b['system'][0]['cache_control']['type'] ?? '') === 'ephemeral' && str_contains($b['system'][0]['text'], 'FACT: tickets'), 'facts sent first, cached');
check(($b['output_config']['format']['type'] ?? '') === 'json_schema' && ($b['output_config']['effort'] ?? '') === 'medium', 'structured output + explicit effort');
check(($b['output_config']['format']['schema']['additionalProperties'] ?? true) === false, 'schema is closed (additionalProperties false)');
check(($b['fallbacks'] ?? '') === 'default', 'server-side fallback on');
check(!isset($b['thinking']) && !isset($b['temperature']), 'no disabled thinking, no temperature (both rejected on Opus 5.5)');
check(str_contains($b['messages'][0]['content'], 'How do I book a ticket?') && str_contains($b['messages'][0]['content'], 'saman kati lana milcha'), 'existing titles and open questions are in the task');

/* ---- 5. budget ---- */
$calls = []; $saved = [];
AiTeach::$kv = ['spent' => '24.995'];
Settings::$v['ai_teach_budget_usd'] = '25';
$r = AiTeach::run(2.0);
check($calls === [] && str_contains($r['note'], 'budget used up'), 'total budget reached → no call at all');
AiTeach::$kv = [];
$replies = [$msg(['articles' => [$art('A')]], ['output_tokens' => 60_000]), $msg(['results' => []])];   // $1.20 first call
$r = AiTeach::run(1.0);
check(count($calls) <= 2 && $r['rounds'] === 1, 'a run stops once its own budget is spent');

/* ---- 6. failures ---- */
$calls = []; AiTeach::$kv = [];
$replies = [[429, ['error' => ['message' => 'rate limited']]]];
$r = AiTeach::run(1.0);
check($r['ok'] && $r['written'] === 0 && str_contains($r['note'], 'HTTP 429'), 'an HTTP error ends the run softly');
$saved = [];
$replies = [$msg([], ['input_tokens' => 10, 'output_tokens' => 5], 'refusal')];
$r = AiTeach::run(1.0);
check(str_contains($r['note'], 'declined') && $saved === [], 'a refusal saves nothing');
$replies = [[200, ['stop_reason' => 'end_turn', 'usage' => [], 'content' => [['type' => 'text', 'text' => 'not json']]]]];
check(str_contains(AiTeach::run(1.0)['note'], 'unreadable'), 'unreadable answer is reported');

/* ---- 7. dry run ---- */
$saved = []; AiTeach::$kv = [];
$replies = [$msg(['articles' => [$art('Dry one')]]), $msg(['results' => [['index' => 0, 'supported' => true, 'problem' => '']]]), $msg(['articles' => []])];
$r = AiTeach::run(1.0, true);
check($r['published'] === 1 && $saved === [] && AiTeach::spent() === 0.0, '--dry counts but saves and spends nothing');

/* ---- 8. desk examples for the VPS model ---- */
$learned = []; AiTeach::$kv = []; $saved = [];
AiTeach::$desk = static fn(): array => ['DESK GUIDE', ['type' => 'object', 'properties' => ['intent' => ['type' => 'string'], 'pnr' => ['type' => 'string']], 'required' => ['intent']]];
AiTeach::$learn = static function (string $t, array $i) use (&$learned): bool { $learned[] = [$t, $i['intent']]; return true; };
$replies = [$msg(['articles' => []]), $msg(['examples' => [
    ['text' => 'bal kati xa', 'intent' => ['intent' => 'balance']],
    ['text' => 'k cha bus ma', 'intent' => ['intent' => 'question']],
    ['text' => 'SHG-2026-10001 cancel gardinu', 'intent' => ['intent' => 'cancel', 'pnr' => 'SHG-2026-10001']],
]])];
$r = AiTeach::run(2.0);
check($r["examples"] === 2 && $learned === [['bal kati xa', 'balance'], ['SHG-2026-10001 cancel gardinu', 'cancel']], 'desk examples stored, question/other skipped');
check(($calls[count($calls) - 1]['output_config']['format']['schema']['properties']['examples']['items']['properties']['intent']['additionalProperties'] ?? true) === false, 'the intent schema is closed for structured output');

echo "OK $checks checks\n";
