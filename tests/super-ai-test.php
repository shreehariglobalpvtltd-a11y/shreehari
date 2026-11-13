<?php
/**
 * =====================================================================
 *  super-ai-test.php — 13 Nov 2026 (Super AI: cost ledger, prompt cache,
 *  daily ceiling, finance_day tool, tool permissions).
 *
 *  Uses the local test database. No model is called and no message is
 *  sent. It writes rows tagged 'satest' to ai_usage and removes them, and
 *  puts back the two settings it changes.
 *
 *    php tests/super-ai-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
chdir(dirname(__DIR__));
require 'includes/bootstrap.php';
require_once INCLUDE_PATH . '/aibrain.php';
require_once INCLUDE_PATH . '/aimeter.php';
require_once INCLUDE_PATH . '/aitools.php';

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
};
$near = static fn(?float $a, float $b): bool => $a !== null && abs($a - $b) < 0.0000005;

if (!str_contains((string) DB_NAME, 'test')) { exit("Refusing to run: DB_NAME is not a test database.\n"); }
$sql = (string) file_get_contents('database/upgrade-2026-11-13-super-ai.sql');
Database::run(trim(preg_replace('/^--.*$/m', '', $sql) ?? '', " \r\n;"));

echo "\n1. Prices and cost\n";
$u = ['in' => 1000, 'out' => 500, 'cache_read' => 20000, 'cache_write' => 2000];
$ok($near(AiMeter::cost('claude-opus-5-5', $u), (1000 * 4 + 2000 * 4 * 1.25 + 20000 * 0.20 + 500 * 20) / 1e6), 'Opus 5.5: input, cache write x1.25, cache read, output');
$ok($near(AiMeter::cost('claude-sonnet-5-5', $u), (1000 * 2 + 2000 * 2 * 1.25 + 20000 * 0.10 + 500 * 10) / 1e6), 'Sonnet 5.5 is priced on its own row, not as Sonnet 5');
$ok(AiMeter::price('claude-opus-5-5')[0] === 4.0 && AiMeter::price('claude-opus-5')[0] === 5.0, 'longest prefix wins (opus-5-5 vs opus-5)');
$ok(AiMeter::cost('gemini-3.8-flash', $u) === null && AiMeter::cost('', $u) === null, 'a model with no known price has no cost (never a guess)');

echo "\n2. Token counts from each provider\n";
$a = AiMeter::fromAnthropic(['input_tokens' => 12, 'output_tokens' => 30, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 40]);
$ok($a === ['in' => 12, 'out' => 30, 'cache_read' => 900, 'cache_write' => 40], 'Anthropic usage');
$g = AiMeter::fromGemini(['promptTokenCount' => 1000, 'candidatesTokenCount' => 50, 'thoughtsTokenCount' => 25, 'cachedContentTokenCount' => 600]);
$ok($g === ['in' => 400, 'out' => 75, 'cache_read' => 600, 'cache_write' => 0], 'Gemini: cached part taken out of the prompt, thinking counted as output');
$o = AiMeter::fromOpenAI(['prompt_tokens' => 800, 'completion_tokens' => 60, 'prompt_tokens_details' => ['cached_tokens' => 500]]);
$ok($o === ['in' => 300, 'out' => 60, 'cache_read' => 500, 'cache_write' => 0], 'OpenAI-style usage');
$ok(AiMeter::fromAnthropic(null) === ['in' => 0, 'out' => 0, 'cache_read' => 0, 'cache_write' => 0], 'a missing usage block is zeros, not an error');

echo "\n3. The ledger and the daily ceiling\n";
$oldBudget = Settings::getString('ai_daily_budget_usd', '');
$oldCache  = Settings::getString('ai_claude_cache_on', '1');
Database::run("DELETE FROM ai_usage WHERE tag = 'satest'");
AiMeter::reset();
$base = AiMeter::spentToday();
AiMeter::context(['channel' => 'whatsapp', 'role' => 'customer', 'tag' => 'satest']);
AiMeter::record('anthropic', 'claude-opus-5-5', $u, 1234, true);
AiMeter::record('gemini', 'gemini-3.8-flash', $g, 800, true);
AiMeter::record('anthropic', 'claude-opus-5-5', [], 50, false);
$rows = Database::fetchAll("SELECT * FROM ai_usage WHERE tag = 'satest' ORDER BY id");
$ok(count($rows) === 3, 'three calls, three rows');
$ok($near((float) $rows[0]['cost_usd'], 0.028) && (int) $rows[0]['cache_read'] === 20000 && $rows[0]['channel'] === 'whatsapp' && $rows[0]['role'] === 'customer', 'Claude row: cost $0.028, tokens, channel and role');
$ok($rows[1]['cost_usd'] === null && (int) $rows[1]['in_tokens'] === 400, 'Gemini row: tokens kept, cost NULL');
$ok((int) $rows[2]['ok'] === 0 && (float) $rows[2]['cost_usd'] === 0.0, 'a failed call is recorded as failed with no cost');
$ok($near(AiMeter::spentToday() - $base, 0.028), 'spentToday rose by the priced call only');
AiMeter::reset();
$ok($near(AiMeter::spentToday() - $base, 0.028), 'and reads the same figure back from the table');

Settings::set('ai_daily_budget_usd', '0', 'string', 'ai');
$ok(AiMeter::overBudget() === false, 'ceiling 0 = no ceiling');
Settings::set('ai_daily_budget_usd', (string) ($base + 0.02), 'string', 'ai');
$ok(AiMeter::overBudget() === true, 'spend at or above the ceiling = over budget');
Settings::set('ai_daily_budget_usd', (string) ($base + 5), 'string', 'ai');
$ok(AiMeter::overBudget() === false, 'spend under the ceiling = not over');

echo "\n4. Prompt cache marks on the Claude request\n";
Settings::set('ai_claude_cache_on', '1', 'bool', 'ai');
$tools = [['name' => 'a', 'description' => 'x', 'input_schema' => ['type' => 'object']], ['name' => 'b', 'description' => 'y', 'input_schema' => ['type' => 'object']]];
[$p] = AiBrain::claudeShape('claude-opus-5-5', ['model' => 'claude-opus-5-5', 'max_tokens' => 700, 'system' => 'BRIEF', 'messages' => [['role' => 'user', 'content' => 'hi']], 'tools' => $tools, 'temperature' => 0.4], []);
$ok(is_array($p['system']) && $p['system'][0]['text'] === 'BRIEF' && ($p['system'][0]['cache_control']['type'] ?? '') === 'ephemeral', 'system text becomes one cacheable block, words unchanged');
$ok(!isset($p['tools'][0]['cache_control']) && ($p['tools'][1]['cache_control']['type'] ?? '') === 'ephemeral', 'only the LAST tool carries the mark');
$ok($p['tools'][1]['name'] === 'b' && $p['tools'][0] === $tools[0], 'tool definitions are otherwise untouched');
$ok(!isset($p['temperature']) && ($p['thinking']['type'] ?? '') === 'adaptive' && $p['max_tokens'] >= 2048, 'the Opus 5.5 shape is still applied (adaptive thinking, no temperature)');
$blocks = [['type' => 'text', 'text' => 'FACTS', 'cache_control' => ['type' => 'ephemeral']], ['type' => 'text', 'text' => 'tail']];
[$p2] = AiBrain::claudeShape('claude-opus-5-5', ['model' => 'claude-opus-5-5', 'max_tokens' => 700, 'system' => $blocks, 'messages' => []], []);
$ok($p2['system'] === $blocks, 'a system already sent as blocks (AiTeach) is left exactly as it was');
[$p3] = AiBrain::claudeShape('claude-haiku-4-5', ['model' => 'claude-haiku-4-5', 'max_tokens' => 300, 'system' => 'S', 'messages' => []], []);
$ok(is_array($p3['system']) && !isset($p3['thinking']), 'older models get the cache mark but not the new thinking shape');
Settings::set('ai_claude_cache_on', '0', 'bool', 'ai');
[$p4] = AiBrain::claudeShape('claude-opus-5-5', ['model' => 'claude-opus-5-5', 'max_tokens' => 700, 'system' => 'BRIEF', 'messages' => [], 'tools' => $tools], []);
$ok($p4['system'] === 'BRIEF' && !isset($p4['tools'][1]['cache_control']), 'switch off = the request exactly as before');
$src = (string) file_get_contents('includes/aiagent.php');
$ok(str_contains($src, "AiMeter::overBudget()") && str_contains($src, "AiMeter::record(\$brain"), 'the agent checks the ceiling and meters every brain call');

echo "\n5. Tool permissions and finance_day\n";
$names = static fn(string $role): array => array_column(AiTools::catalogue(['role' => $role, 'channel' => 'whatsapp', 'phone' => '9800000000']), 'name');
$cust = $names('customer'); $staff = $names('staff'); $adm = $names('admin');
$ok(in_array('finance_day', $adm, true), 'the office has finance_day');
$ok(!in_array('finance_day', $cust, true) && !in_array('finance_day', $staff, true), 'a customer and an agent do not');
$ok(array_intersect(['office_day', 'office_agents', 'office_customer', 'finance_day', 'office_settle_cod'], $cust) === [], 'no office tool is on the customer list');
$r = AiTools::run('finance_day', [], ['role' => 'customer', 'channel' => 'whatsapp', 'phone' => '9800000000']);
$ok($r['ok'] === false && $r['data'] === [], 'a customer asking for finance_day is refused with no data');
$bookingsBefore = (int) Database::scalar('SELECT COUNT(*) FROM bookings', [], 0);
$ledgerBefore   = (string) Database::scalar('SELECT CONCAT(COUNT(*), ":", COALESCE(SUM(amount), 0)) FROM agent_ledger', [], '');
$r = AiTools::run('finance_day', ['date' => date('Y-m-d')], ['role' => 'admin', 'channel' => 'web', 'phone' => '9800000000', 'admin_id' => 1, 'verified' => true]);
if ($r['ok'] === false) { echo "        (note: " . $r['say'] . ")\n"; }
$d = $r['data'];
$ok($r['ok'] === true || str_contains((string) $r['say'], 'verif'), 'the office gets an answer (or the step-up request on this channel)');
if ($r['ok'] === true) {
    $ok(isset($d['date'], $d['ticketsSold'], $d['verifiedMoneyInr'], $d['paymentsWaiting']) && array_key_exists('ledgerFindings', $d), 'it carries sales, verified money, waiting payments and the ledger check');
    require_once INCLUDE_PATH . '/officereport.php';
    $ok((float) $d['verifiedMoneyInr'] === (float) OfficeReport::collected(date('Y-m-d'))['inr'], 'verified money is the office report\'s own figure');
    $ok($d['ledgerFindings'] === null || is_array($d['ledgerFindings']), 'ledger findings are a list, or null when the check could not run');
}
$ok($bookingsBefore === (int) Database::scalar('SELECT COUNT(*) FROM bookings', [], 0)
    && $ledgerBefore === (string) Database::scalar('SELECT CONCAT(COUNT(*), ":", COALESCE(SUM(amount), 0)) FROM agent_ledger', [], ''), 'finance_day changed no booking and no ledger row');

/* put everything back */
Database::run("DELETE FROM ai_usage WHERE tag = 'satest'");
Database::run("DELETE FROM ai_agent_calls WHERE tool = 'finance_day' AND phone = '9800000000'");
Settings::set('ai_daily_budget_usd', $oldBudget === '' ? '0' : $oldBudget, 'string', 'ai');
Settings::set('ai_claude_cache_on', $oldCache === '0' ? '0' : '1', 'bool', 'ai');

echo "\n" . ($fail === 0 ? "ALL $pass PASSED" : "$fail FAILED, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
