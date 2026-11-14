<?php
/**
 * =====================================================================
 *  ai-web-research-test.php — 14 Nov 2026 (Super AI: web research for the
 *  office and staff, on the Claude brain only).
 *
 *    php -c .claude/php-dev.ini tests/ai-web-research-test.php
 *
 *  Pins:
 *   - WHO gets the tool: never a customer, nobody while the switch is
 *     off, staff and office when it is on — with the exact tool shape,
 *     the right version per model and the max-uses cap;
 *   - the request: the search tool sits in front of our tools, the cache
 *     mark stays on our last tool, the final round still declares it;
 *   - a history that holds server_tool_use / web_search_tool_result
 *     blocks (and a cited sentence, and a failed search) goes back to the
 *     API unchanged — byte for byte, empty objects included;
 *   - pause_turn is resumed by re-sending, at most twice, never past the
 *     deadline; a search error does not break the turn;
 *   - the briefing lines, the search fee in the cost ledger, plain-text
 *     memory, and that Gemini / OpenAI-style brains are untouched.
 *
 *  NO model is called and NO message is sent: the network is replaced by
 *  a fake (AiAgent::$httpFake). It changes five settings and one kv row
 *  and puts every one back. CLI only.
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
require_once INCLUDE_PATH . '/aiprompt.php';
require_once INCLUDE_PATH . '/aiagent.php';

if (!str_contains((string) DB_NAME, 'test')) { exit("Refusing to run: DB_NAME is not a test database.\n"); }

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
};
$call = static function (string $method, ...$args) {
    return (new ReflectionMethod('AiAgent', $method))->invoke(null, ...$args);
};
$prop = static function (string $name, mixed $value): void {
    (new ReflectionProperty('AiAgent', $name))->setValue(null, $value);
};
$j = static fn(mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

/* Settings this test touches: remembered here, restored at the end. */
const WR_KEYS = ['ai_web_research_on', 'ai_web_research_max_uses', 'ai_claude_cache_on', 'ai_agent_model', 'ai_model'];
$saved = [];
foreach (WR_KEYS as $k) {
    $saved[$k] = Database::fetch('SELECT svalue, stype FROM settings WHERE skey = :k', ['k' => $k]);
}
$restore = static function () use ($saved): void {
    foreach ($saved as $k => $row) {
        if ($row === null) {
            Database::delete('settings', 'skey = :k', ['k' => $k]);
        } else {
            Database::run('UPDATE settings SET svalue = :v, stype = :t WHERE skey = :k', ['v' => $row['svalue'], 't' => $row['stype'], 'k' => $k]);
        }
    }
    Settings::flush();
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_agent' AND kkey = 'wrtest-000'");
    AiAgent::$httpFake = null;
};

$customer = ['role' => 'customer', 'channel' => 'whatsapp', 'phone' => '9800000001'];
$staff    = ['role' => 'staff',    'channel' => 'whatsapp', 'phone' => '9800000002', 'adminId' => 0, 'name' => 'Test Seller'];
$office   = ['role' => 'admin',    'channel' => 'web',      'phone' => '9800000003', 'adminId' => 0, 'name' => 'Test Office'];
$ours     = [
    ['name' => 'office_day', 'description' => 'x', 'input_schema' => ['type' => 'object']],
    ['name' => 'find_ticket', 'description' => 'y', 'input_schema' => ['type' => 'object']],
];
$opus = 'claude-opus-5-5';

try {
    Settings::set('ai_agent_model', $opus, 'string', 'ai');
    Settings::set('ai_claude_cache_on', '1', 'bool', 'ai');
    Settings::set('ai_web_research_max_uses', '3', 'int', 'ai');

    echo "\n1. Who is handed the web search tool\n";
    Settings::set('ai_web_research_on', '0', 'bool', 'ai');
    $ok(AiAgent::webResearchTool($staff, $opus) === null && AiAgent::webResearchTool($office, $opus) === null, 'switch off (the default): nobody, not even the office');
    $ok(AiAgent::webResearchOn($office) === false, 'webResearchOn is false while the switch is off');
    Settings::set('ai_web_research_on', '1', 'bool', 'ai');
    $ok(AiAgent::webResearchTool($customer, $opus) === null, 'switch on: a customer still gets nothing');
    $ok(AiAgent::webResearchTool([], $opus) === null && AiAgent::webResearchTool(['role' => 'Admin'], $opus) === null
        && AiAgent::webResearchTool(['role' => 'owner'], $opus) === null, 'a missing or unknown role counts as a customer');
    $want = ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 3];
    $ok(AiAgent::webResearchTool($staff, $opus) === $want, 'staff: {"type":"web_search_20260209","name":"web_search","max_uses":3}');
    $ok(AiAgent::webResearchTool($office, $opus) === $want, 'office: the same block');
    foreach (['claude-fable-5-2', 'claude-opus-5', 'claude-opus-4-8', 'claude-opus-4-6', 'claude-sonnet-5-5', 'claude-sonnet-4-6'] as $m) {
        if ((AiAgent::webResearchTool($office, $m)['type'] ?? '') !== 'web_search_20260209') { $ok(false, "$m should take the 2026 tool"); }
    }
    $old = ['claude-haiku-4-5', 'claude-haiku-5-5', 'claude-opus-4-5-20251101', 'claude-sonnet-4-5-20250929', 'claude-opus-4-20250514'];
    $oldOk = true;
    foreach ($old as $m) { $oldOk = $oldOk && (AiAgent::webResearchTool($office, $m)['type'] ?? '') === 'web_search_20250305'; }
    $ok($oldOk, 'older models and the Haiku family take web_search_20250305');
    Settings::set('ai_web_research_max_uses', '5', 'int', 'ai');
    $ok(AiAgent::webResearchTool($office, $opus)['max_uses'] === 5, 'max_uses follows the setting');
    Settings::set('ai_web_research_max_uses', '99', 'int', 'ai');
    $hi = AiAgent::webResearchTool($office, $opus)['max_uses'];
    Settings::set('ai_web_research_max_uses', '0', 'int', 'ai');
    $lo = AiAgent::webResearchTool($office, $opus)['max_uses'];
    $ok($hi === 10 && $lo === 1, 'and is held between 1 and 10 whatever is saved');
    Settings::set('ai_web_research_max_uses', '3', 'int', 'ai');

    echo "\n2. The Claude request\n";
    $hist = [['role' => 'user', 'content' => 'Butwal road khulyo?']];
    $pc = $call('anthropicPayload', $opus, 'SYS', $hist, $ours, $customer, false);
    $ok($pc['tools'] === $ours && !str_contains($j($pc), 'web_search'), 'customer: the request carries our tools only, no web_search anywhere');
    $ps = $call('anthropicPayload', $opus, 'SYS', $hist, $ours, $staff, false);
    $ok($ps['tools'][0] === $want && array_slice($ps['tools'], 1) === $ours, 'staff: web_search first, then our tools untouched and in order');
    $pa = $call('anthropicPayload', $opus, 'SYS', $hist, $ours, $office, false);
    $ok($pa['tools'][0] === $want && count($pa['tools']) === 3, 'office: the same');
    [$shaped] = AiBrain::claudeShape($opus, $pa, []);
    $ok(!isset($shaped['tools'][0]['cache_control']) && ($shaped['tools'][2]['cache_control']['type'] ?? '') === 'ephemeral'
        && $shaped['tools'][0] === $want, 'after claudeShape the cache mark is on OUR last tool, the search tool is as declared');
    $ok(!isset($shaped['tools'][0]['input_schema']) && !isset($shaped['tools'][0]['description']), 'the search tool has no schema of ours (it is Anthropic\'s)');
    $pl = $call('anthropicPayload', $opus, 'SYS', $hist, $ours, $office, true);
    $ok(($pl['tool_choice']['type'] ?? '') === 'none' && $pl['tools'][0] === $want, 'the last round forbids calls but still declares the tool (a history with a search needs it)');
    $pe = $call('anthropicPayload', $opus, 'SYS', $hist, [], $office, false);
    $ok($pe['tools'] === [$want], 'with no tools of ours the search tool still goes');
    $ok(!isset($pc['container']) && !isset($pa['container']), 'no container is sent before an answer named one');
    Settings::set('ai_web_research_on', '0', 'bool', 'ai');
    $po = $call('anthropicPayload', $opus, 'SYS', $hist, $ours, $office, false);
    $ok($po['tools'] === $ours && !str_contains($j($po), 'web_search'), 'switch off: the office request is exactly as before');
    Settings::set('ai_web_research_on', '1', 'bool', 'ai');

    echo "\n3. A history with server blocks goes back unchanged\n";
    $search  = ['type' => 'server_tool_use', 'id' => 'srvtoolu_01A', 'name' => 'web_search', 'input' => ['query' => 'Butwal Narayanghat road status']];
    $result  = ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_01A', 'content' => [
        ['type' => 'web_search_result', 'url' => 'https://example.org/road', 'title' => 'सडक अवस्था', 'encrypted_content' => 'EqgfCioIARgB/abc+==', 'page_age' => null],
        ['type' => 'web_search_result', 'url' => 'https://example.org/b', 'title' => 'B', 'encrypted_content' => 'Zm9v', 'page_age' => 'November 13, 2026'],
    ]];
    $failed  = ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_01B', 'content' => ['type' => 'web_search_tool_result_error', 'error_code' => 'max_uses_exceeded']];
    $search2 = ['type' => 'server_tool_use', 'id' => 'srvtoolu_01B', 'name' => 'web_search', 'input' => ['query' => 'again']];
    $cited   = ['type' => 'text', 'text' => 'सडक खुला छ।', 'citations' => [
        ['type' => 'web_search_result_location', 'url' => 'https://example.org/road', 'title' => 'सडक अवस्था', 'encrypted_index' => 'Eo8BCioIAhgB', 'cited_text' => 'The road reopened…'],
    ]];
    $plain   = ['type' => 'text', 'text' => 'Let me check our day too.'];
    $toolUse = ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'office_day', 'input' => ['date' => '2026-11-14']];
    $turn    = [$plain, $search, $result, $search2, $failed, $cited, $toolUse];
    $history = [
        ['role' => 'user', 'content' => 'Butwal road khulyo? aaja kati ticket?'],
        ['role' => 'assistant', 'content' => $turn],
        ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_01', 'toolName' => 'office_day', 'is_error' => false, 'content' => '{"ok":true}']]],
    ];
    $msgs = $call('anthropicMessages', $history);
    $ok($j($msgs[1]['content']) === $j($turn), 'text, server_tool_use, web_search_tool_result, a failed search, a cited sentence and our tool_use: identical JSON');
    $ok($msgs[1]['content'][1] === $search && $msgs[1]['content'][2] === $result, 'the search and its result are the very same blocks (encrypted_content untouched)');
    $ok(is_array($msgs[1]['content'][4]['content']) && ($msgs[1]['content'][4]['content']['error_code'] ?? '') === 'max_uses_exceeded', 'a failed search keeps its error OBJECT (not turned into a list or text)');
    $ok(!str_contains($j($msgs), '"text":""'), 'no server block was rewritten into an empty text block (the old default)');
    $ok($msgs[2]['content'][0] === ['type' => 'tool_result', 'tool_use_id' => 'toolu_01', 'content' => '{"ok":true}'], 'our tool_result is still rebuilt with only the keys the API knows');
    $empty = $call('anthropicMessages', [['role' => 'user', 'content' => 'q'], ['role' => 'assistant', 'content' => [['type' => 'server_tool_use', 'id' => 's1', 'name' => 'web_search', 'input' => []]]]]);
    $ok(str_contains($j($empty), '"input":{}'), 'a server call with no input goes back as {} and not []');
    $mid = $call('anthropicMessages', [['role' => 'user', 'content' => 'q'], ['role' => 'assistant', 'content' => [$search, $toolUse]],
        ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_01', 'content' => 'x']]]]);
    $ok($j($mid[1]['content']) === $j([$search, $toolUse]), 'a search still waiting beside our tool call (no result yet) is carried as it is');

    echo "\n4. Reading a Claude answer\n";
    $prop('webSources', []);
    $body = '{"model":"claude-opus-5-5","stop_reason":"end_turn","content":['
        . '{"type":"thinking","thinking":"","signature":"sig=="},'
        . '{"type":"server_tool_use","id":"srvtoolu_9","name":"code_execution","input":{}},'
        . '{"type":"code_execution_tool_result","tool_use_id":"srvtoolu_9","content":{"type":"code_execution_result","stdout":"","stderr":"","return_code":0,"content":[],"meta":{}}},'
        . '{"type":"server_tool_use","id":"srvtoolu_8","name":"web_search","input":{"query":"छठ २०२६ मिति"},"caller":{"type":"code_execution_20260120","tool_id":"srvtoolu_9"}},'
        . '{"type":"web_search_tool_result","tool_use_id":"srvtoolu_8","content":{"type":"web_search_tool_result_error","error_code":"unavailable"}},'
        . '{"type":"text","text":"वेबबाट: खोज अहिले चलेन।"},'
        . '{"type":"text","text":" छठ १५ नोभेम्बर।","citations":[{"type":"web_search_result_location","url":"https://example.org/chhath","title":"Chhath 2026","encrypted_index":"abc","cited_text":"…"}]}'
        . '],"usage":{"input_tokens":10,"output_tokens":20,"server_tool_use":{"web_search_requests":2}}}';
    $r = $call('anthropicReply', json_decode($body, true), $body);
    $rawContent = json_decode($body)->content;
    $ok($r['calls'] === [] && $r['text'] === 'वेबबाट: खोज अहिले चलेन। छठ १५ नोभेम्बर।', 'no call to our tools; the words are the text blocks joined');
    $ok(count($r['blocks']) === 7, 'every block is kept for a replay (thinking, 4 server blocks, 2 text)');
    $same = true;
    foreach ([1, 2, 3, 4, 6] as $i) { $same = $same && $j($r['blocks'][$i]) === $j($rawContent[$i]); }
    $ok($same, 'server blocks and the cited sentence re-encode to exactly what the API sent (empty {} kept, [] kept)');
    $ok(str_contains($j($r['blocks'][1]), '"input":{}') && str_contains($j($r['blocks'][2]), '"meta":{}') && str_contains($j($r['blocks'][2]), '"content":[]'), 'an empty object stays an object and an empty list stays a list');
    $ok($j(array_map(static fn($b) => (new ReflectionMethod('AiAgent', 'anthropicBlock'))->invoke(null, $b), $r['blocks'])) === $j($rawContent),
        'and the whole answer, thinking block included, survives anthropicBlock() on the way back');
    $ok($call('sourcesNote') !== '' && str_contains($call('sourcesNote'), 'https://example.org/chhath') && str_contains($call('sourcesNote'), 'Chhath 2026'), 'the page the answer cited is named under the reply');
    $noRaw = $call('anthropicReply', json_decode($body, true), '');
    $ok(count($noRaw['blocks']) === 7 && str_contains($j((new ReflectionMethod('AiAgent', 'anthropicBlock'))->invoke(null, $noRaw['blocks'][1])), '"input":{}'), 'without the raw body the blocks are still kept, and the empty input is repaired');
    $legacy = $call('anthropicReply', ['content' => [['type' => 'text', 'text' => 'नमस्ते'], ['type' => 'tool_use', 'id' => 't1', 'name' => 'office_day', 'input' => []], ['type' => 'mystery_block', 'x' => 1]]], '');
    $ok($legacy['text'] === 'नमस्ते' && $legacy['calls'] === [['id' => 't1', 'name' => 'office_day', 'input' => []]] && count($legacy['blocks']) === 2, 'an ordinary answer reads as before; an unknown block type is still dropped');
    $prop('webSources', []);

    echo "\n5. pause_turn, a failed search, the deadline (fake network)\n";
    $sent = [];
    $queue = [];
    AiAgent::$httpFake = static function (string $url, array $payload, array $headers) use (&$sent, &$queue): array {
        $sent[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers];
        return $queue === [] ? [500, '{}'] : array_shift($queue);
    };
    $paused = '{"model":"claude-opus-5-5","stop_reason":"pause_turn","container":{"id":"container_01X","expires_at":"2026-11-14T10:00:00Z"},"content":['
        . '{"type":"text","text":"हेर्दैछु। "},'
        . '{"type":"server_tool_use","id":"srvtoolu_p1","name":"web_search","input":{"query":"Butwal road"}}'
        . '],"usage":{"input_tokens":100,"output_tokens":10,"cache_read_input_tokens":50,"server_tool_use":{"web_search_requests":0}}}';
    $done = '{"model":"claude-opus-5-5","stop_reason":"end_turn","content":['
        . '{"type":"web_search_tool_result","tool_use_id":"srvtoolu_p1","content":[{"type":"web_search_result","url":"https://example.org/road","title":"Road","encrypted_content":"RU5D","page_age":null}]},'
        . '{"type":"text","text":"वेबबाट: सडक खुला छ।","citations":[{"type":"web_search_result_location","url":"https://example.org/road","title":"Road","encrypted_index":"i1","cited_text":"open"}]}'
        . '],"usage":{"input_tokens":200,"output_tokens":30,"server_tool_use":{"web_search_requests":1}}}';
    $ask = static fn(array $ctx, array $history) => (new ReflectionMethod('AiAgent', 'askAnthropic'))->invoke(null, 'test-key', 'SYS', $history, $ours, false, $ctx);

    $prop('claudeContainer', null);
    $queue = [[200, $paused], [200, $done]];
    $out = $ask($office, $hist);
    $ok(count($sent) === 2 && $out !== null, 'a paused turn is sent again once and then answers');
    $ok($out['text'] === 'हेर्दैछु। वेबबाट: सडक खुला छ।' && $out['calls'] === [], 'the reply is the words of both parts');
    $m2 = $sent[1]['payload']['messages'];
    $ok(count($m2) === 2 && $m2[0] === $sent[0]['payload']['messages'][0] && $m2[1]['role'] === 'assistant', 'the resume is the same conversation plus ONE assistant message — no "continue" from the user');
    $ok($j($m2[1]['content']) === $j(json_decode($paused)->content), 'that message is the paused answer exactly as it came');
    $ok($j($sent[1]['payload']['tools']) === $j($sent[0]['payload']['tools']) && $sent[0]['payload']['tools'][0] === $want, 'both requests declare the same tools, web_search included');
    $ok(!isset($sent[0]['payload']['container']) && ($sent[1]['payload']['container'] ?? '') === 'container_01X', 'the container the answer named is handed back on the resume');
    $usage = (new ReflectionProperty('AiAgent', 'lastUsage'))->getValue();
    $ok($usage[1] === ['in' => 300, 'out' => 40, 'cache_read' => 50, 'cache_write' => 0, 'web_searches' => 1], 'tokens of both requests are added up, with the one search that ran');
    $ok(count($out['blocks']) === 4 && $j($out['blocks'][1]) === $j(json_decode($paused)->content[1]), 'the blocks of both parts are kept in order for the next round');
    $ok(str_contains($call('sourcesNote'), 'https://example.org/road'), 'and the cited page is remembered for the reply');
    $ok(str_contains(implode("\n", $sent[0]['headers']), 'x-api-key: test-key') && $sent[0]['url'] === 'https://api.anthropic.com/v1/messages', 'the request goes to the Messages API with the key header');

    $sent = []; $prop('claudeContainer', null); $prop('webSources', []);
    $queue = [[200, $paused], [200, $paused], [200, $paused], [200, $done]];
    $out = $ask($office, $hist);
    $ok($out === null && count($sent) === 3, 'still paused after 2 resumes: 3 requests in all, then the turn is handed on (null) — never a loop');
    $ok(count($sent[2]['payload']['messages']) === 2 && count($sent[2]['payload']['messages'][1]['content']) === 4, 'the second resume carries both paused parts, in order');

    $sent = []; $prop('claudeContainer', null);
    $queue = [[200, $paused], [500, 'upstream error']];
    $ok($ask($office, $hist) === null && count($sent) === 2, 'a resume that fails returns null (the next brain answers), no exception');

    $sent = []; $prop('claudeContainer', null); $prop('webSources', []);
    $err = '{"model":"claude-opus-5-5","stop_reason":"end_turn","content":['
        . '{"type":"server_tool_use","id":"srvtoolu_e","name":"web_search","input":{"query":"x"}},'
        . '{"type":"web_search_tool_result","tool_use_id":"srvtoolu_e","content":{"type":"web_search_tool_result_error","error_code":"too_many_requests"}},'
        . '{"type":"text","text":"वेब खोज अहिले चलेन, पछि फेरि सोध्नुहोस्।"}],"usage":{"input_tokens":5,"output_tokens":5}}';
    $queue = [[200, $err]];
    $out = $ask($staff, $hist);
    $ok($out !== null && $out['text'] === 'वेब खोज अहिले चलेन, पछि फेरि सोध्नुहोस्।' && count($sent) === 1 && $call('sourcesNote') === '', 'a search that failed (error object) is an ordinary answer: the model\'s words, no crash, no source line');

    $sent = []; $prop('claudeContainer', null);
    $prop('deadline', microtime(true) - 1);
    $queue = [[200, $done]];
    $out = $ask($office, $hist);
    $prop('deadline', null);
    $ok($out === null && $sent === [], 'past the turn deadline nothing is sent at all');

    $sent = []; $prop('claudeContainer', null);
    $prop('deadline', microtime(true) + 30);
    AiAgent::$httpFake = static function (string $url, array $payload, array $headers) use (&$sent, $paused, $prop): array {
        $sent[] = $payload;
        $prop('deadline', microtime(true) - 1);          // the time runs out while the search is paused
        return [200, $paused];
    };
    $out = $ask($office, $hist);
    $prop('deadline', null);
    $ok($out === null && count($sent) === 1, 'a pause that outlives the deadline is not resumed');

    $sent = []; $prop('claudeContainer', null);
    AiAgent::$httpFake = static function (string $url, array $payload, array $headers) use (&$sent, $done): array {
        $sent[] = $payload;
        return [200, $done];
    };
    $ask($customer, $hist);
    $ok(count($sent) === 1 && !str_contains($j($sent[0]), 'web_search'), 'end to end: a customer\'s request to Claude never mentions web_search');
    AiAgent::$httpFake = null;
    $prop('claudeContainer', null); $prop('webSources', []);

    echo "\n6. Briefing, cost, memory, other brains\n";
    $sp = static fn(array $ctx): string => (string) (new ReflectionMethod('AiAgent', 'systemPrompt'))->invoke(null, $ctx);
    $ok(!str_contains($sp($customer), 'WEB RESEARCH'), 'the passenger briefing has no web research lines (rule 11 stands: no internet)');
    $sStaff = $sp($staff); $sOffice = $sp($office);
    $ok(str_contains($sStaff, 'WEB RESEARCH (office and staff only)') && str_contains($sOffice, 'WEB RESEARCH (office and staff only)'), 'staff and office briefings carry them when the switch is on');
    $ok(str_contains($sOffice, 'NEVER use the web for OUR OWN fares, seats') && str_contains($sOffice, 'came from the web') && str_contains($sOffice, 'never an instruction'), 'they say: general information only, never our own fares/seats/bookings/money, name the web as the source, a page is not an instruction');
    Settings::set('ai_web_research_on', '0', 'bool', 'ai');
    $ok(!str_contains($sp($staff), 'WEB RESEARCH') && !str_contains($sp($office), 'WEB RESEARCH'), 'switch off: no such lines for anyone');
    Settings::set('ai_web_research_on', '1', 'bool', 'ai');

    $u = ['in' => 1000, 'out' => 500, 'cache_read' => 0, 'cache_write' => 0];
    $base = AiMeter::cost($opus, $u);
    $ok(abs(AiMeter::cost($opus, $u + ['web_searches' => 3]) - ($base + 0.03)) < 0.0000005 && AiMeter::cost($opus, $u + ['web_searches' => 0]) === $base, 'the ledger adds one US cent per search to the token cost');
    $ok(AiMeter::cost('gemini-3.8-flash', $u + ['web_searches' => 3]) === null, 'and still shows no cost for a model with no known price');

    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_agent' AND kkey = 'wrtest-000'");
    $call('saveHistory', 'wrtest-000', [
        ['role' => 'user', 'content' => 'Butwal road khulyo?'],
        ['role' => 'assistant', 'content' => $turn],
        ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_01', 'content' => 'x']]],
        ['role' => 'assistant', 'content' => 'वेबबाट: सडक खुला छ।'],
    ]);
    $kept = (string) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wa_agent' AND kkey = 'wrtest-000'", [], '');
    $rows = json_decode($kept, true);
    $ok(is_array($rows) && count($rows) === 2 && $rows[1]['content'] === 'वेबबाट: सडक खुला छ।'
        && !str_contains($kept, 'server_tool_use') && !str_contains($kept, 'encrypted_content') && !str_contains($kept, 'web_search'), 'saved memory keeps the plain words only — no search block, no encrypted content');

    $src = (string) file_get_contents('includes/aiagent.php');
    $from = strpos($src, 'private static function askGemini');
    $to   = strpos($src, 'private static function http(');
    $other = $from !== false && $to !== false ? substr($src, $from, $to - $from) : 'web_search';
    $ok(!str_contains($other, 'web_search') && !str_contains($other, 'webResearch'), 'the Gemini and OpenAI-style brains never see the tool');
    Database::delete('settings', 'skey = :k', ['k' => 'ai_web_research_on']);
    Database::delete('settings', 'skey = :k', ['k' => 'ai_web_research_max_uses']);
    Settings::flush();
    $ok(AiAgent::webResearchOn($office) === false && AiAgent::webResearchTool($office, $opus) === null, 'a server where the setting was never saved has web research OFF');
    Settings::set('ai_web_research_on', '1', 'bool', 'ai');
    $ok((AiAgent::webResearchTool($office, $opus)['max_uses'] ?? 0) === 3, 'and 3 searches a message when only the switch was saved');
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  exception: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    $restore();
}

echo "\n" . ($fail === 0 ? "ALL $pass PASSED" : "$fail FAILED, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
