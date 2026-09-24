<?php
/**
 * ai-learn-test.php — the learning loop (24 Sep 2026).
 *
 *   A. What counts as a correction, in three scripts; a language guess.
 *   B. feedback(): 👍 is only a log line; 👎 and a correction open an
 *      example candidate (the correction carries the right answer).
 *   C. The office curates: save, approve (never without a right answer),
 *      retire; examplesBlock() is '' while off and lists only approved.
 *   D. The prompt carries the block; stats count the month.
 *   E. api/ai-feedback.php over HTTP: CSRF, shape, the row.        [HTTP]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ailearn.php';
require_once INCLUDE_PATH . '/aiprompt.php';

define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function req(string $method, string $path, ?array $json = null): array {
    global $CSRF;
    $ch = curl_init(BASE . $path);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIEJAR => '/tmp/ail-jar', CURLOPT_COOKIEFILE => '/tmp/ail-jar'];
    if ($json !== null) { $o[CURLOPT_POSTFIELDS] = json_encode($json); $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'X-CSRF-Token: ' . $CSRF]; }
    curl_setopt_array($ch, $o);
    $b = (string) curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $c, 'body' => $b];
}

$was = Settings::getBool('ai_examples_on', false);
$restore = static function () use ($was): void { Settings::set('ai_examples_on', $was ? '1' : '0', 'bool', 'ai'); Settings::flush(); };
Database::run("DELETE FROM ai_examples WHERE user_text LIKE 'AILT %'");
Database::run("DELETE FROM ai_feedback_log WHERE user_text LIKE 'AILT %'");

echo "-- A. corrections and languages --\n";
foreach (['galat', 'That is wrong', 'hoina, 1500 ho', 'यो गलत हो', 'नहीं ऐसा नहीं है', 'sahi nahi'] as $s) {
    check("\"$s\" reads as a correction", AiLearn::looksLikeCorrection($s));
}
foreach (['kitna bhada hai', 'Rupaidiha ko ticket', 'thanks'] as $s) {
    check("\"$s\" does not", !AiLearn::looksLikeCorrection($s));
}
check('a long paragraph is not a correction', !AiLearn::looksLikeCorrection(str_repeat('wrong ', 40)));
check('Devanagari Hindi → hi', AiLearn::languageOf('टिकट कितने का है?') === 'hi');
check('Devanagari Nepali → ne', AiLearn::languageOf('टिकट कति हो?') === 'ne');
check('romanised Nepali → ne', AiLearn::languageOf('bhada kati ho hajur') === 'ne');
check('romanised Hindi → hi', AiLearn::languageOf('kya aap surat se chalte hain') === 'hi');
check('plain English → en', AiLearn::languageOf('what time does the bus leave') === 'en');

echo "\n-- B. feedback opens candidates --\n";
$upId = AiLearn::feedback('9870007711', 'web', 'up', 'AILT how long is the trip', 'About 40 hours.');
check('👍 is logged', $upId > 0);
check('…and opens no candidate', (int) Database::scalar("SELECT COUNT(*) FROM ai_examples WHERE source_ref = :r", ['r' => (string) $upId], 0) === 0);
$downId = AiLearn::feedback('9870007711', 'web', 'down', 'AILT bhada kati ho', 'The fare is ₹100.');
$cand = Database::fetch('SELECT * FROM ai_examples WHERE source_ref = :r', ['r' => (string) $downId]);
check('👎 opens a candidate with the bad reply', $cand !== null && $cand['status'] === 'candidate' && $cand['bad_reply'] === 'The fare is ₹100.' && $cand['good_reply'] === '');
check('…in the language the person wrote', ($cand['language'] ?? '') === 'ne', (string) ($cand['language'] ?? ''));
$corrId = AiLearn::feedback('9870007711', 'whatsapp', 'correction', 'AILT ticket kitne ka', 'It is free.', 'Sharing sleeper is ₹1,500 per person.');
$c2 = Database::fetch('SELECT * FROM ai_examples WHERE source_ref = :r', ['r' => (string) $corrId]);
check('a correction carries the right answer', $c2 !== null && $c2['good_reply'] === 'Sharing sleeper is ₹1,500 per person.');
$log = Database::fetch('SELECT owner_key, channel FROM ai_feedback_log WHERE id = :id', ['id' => $corrId]);
check('the log keys the person by hash, not number', strlen((string) $log['owner_key']) === 64 && !str_contains((string) $log['owner_key'], '9870007711') && $log['channel'] === 'whatsapp');
check('an IP without a phone still gets a stable key', AiLearn::ownerKey('10.0.0.1') === AiLearn::ownerKey('10.0.0.1') && strlen(AiLearn::ownerKey('10.0.0.1')) === 64);

echo "\n-- C. the office curates --\n";
$threw = false;
try { AiLearn::setStatus((int) $cand['id'], 'approved', 1); } catch (RuntimeException $e) { $threw = str_contains($e->getMessage(), 'right answer'); }
check('approving without a right answer is refused', $threw);
$id = AiLearn::save(['intent' => 'fare', 'language' => 'ne', 'user_text' => 'AILT bhada kati ho', 'bad_reply' => 'The fare is ₹100.', 'good_reply' => 'Sharing sleeper ₹1,500 per person hajur.', 'rule_text' => 'Quote the sharing sleeper fare from settings'], 1, (int) $cand['id']);
check('save() updates the candidate in place', $id === (int) $cand['id']);
AiLearn::setStatus($id, 'approved', 1);
$row = Database::fetch('SELECT status, approved_by FROM ai_examples WHERE id = :id', ['id' => $id]);
check('approved with the approver on record', $row['status'] === 'approved' && (int) $row['approved_by'] === 1);
$threw = false;
try { AiLearn::setStatus($id, 'published', 1); } catch (RuntimeException $e) { $threw = true; }
check('an unknown status is refused', $threw);
$threw = false;
try { AiLearn::setStatus(99999999, 'retired', 1); } catch (RuntimeException $e) { $threw = true; }
check('an unknown example is refused', $threw);
Settings::set('ai_examples_on', '0', 'bool', 'ai'); Settings::flush();
check('off: the block is empty even with an approved example', AiLearn::examplesBlock('ne') === '');
Settings::set('ai_examples_on', '1', 'bool', 'ai'); Settings::flush();
$block = AiLearn::examplesBlock('ne');
check('on: the block lists the approved example with its rule', str_contains($block, 'HOW WE ANSWER THESE') && str_contains($block, 'Quote the sharing sleeper fare') && str_contains($block, '1,500 per person hajur'));
check('…and not the still-open candidate', !str_contains($block, 'It is free.'));
$hits = (int) Database::scalar('SELECT hits FROM ai_examples WHERE id = :id', ['id' => $id], 0);
check('each use counts a hit', $hits >= 1, "$hits");
AiLearn::setStatus($id, 'retired', 1);
check('retired examples leave the block', !str_contains(AiLearn::examplesBlock('ne'), '1,500 per person hajur'));
AiLearn::setStatus($id, 'approved', 1);

echo "\n-- D. the prompt and the numbers --\n";
$prompt = ai_system_prompt('9870007711', 'AILT bhada kati ho');
check('the system prompt carries the approved block', str_contains($prompt, 'HOW WE ANSWER THESE'));
Settings::set('ai_examples_on', '0', 'bool', 'ai'); Settings::flush();
check('…and not while off', !str_contains(ai_system_prompt('9870007711', 'AILT bhada kati ho'), 'HOW WE ANSWER THESE'));
Settings::set('ai_examples_on', '1', 'bool', 'ai'); Settings::flush();
$st = AiLearn::stats(30);
check('stats count up / down / corrections / approved', $st['up'] >= 1 && $st['down'] >= 1 && $st['corrections'] >= 1 && $st['approved'] >= 1 && $st['candidates'] >= 1, json_encode($st));

echo "\n-- E. the endpoint --\n";
$home = req('GET', '/index.php');
if ($home['code'] !== 200) {
    echo "  SKIP  no server at " . BASE . "\n";
} else {
    $cfg  = json_decode(req('GET', '/api/config.php')['body'], true) ?: [];
    $CSRF = (string) ($cfg['data']['csrfToken'] ?? '');
    Database::delete('rate_limits', "bucket LIKE 'ai_feedback%'");
    $r = req('POST', '/api/ai-feedback.php', ['verdict' => 'down', 'userText' => 'AILT kab chalti hai', 'replyText' => 'At noon.']);
    $j = json_decode($r['body'], true) ?: [];
    check('a 👎 is accepted', $r['code'] === 200 && !empty($j['ok']) && (int) ($j['data']['id'] ?? 0) > 0, substr($r['body'], 0, 120));
    check('…and says the office will look', str_contains((string) ($j['message'] ?? ''), 'office'));
    $row = Database::fetch("SELECT status FROM ai_examples WHERE user_text = 'AILT kab chalti hai' ORDER BY id DESC LIMIT 1");
    check('…opening a candidate', ($row['status'] ?? '') === 'candidate');
    $r = req('POST', '/api/ai-feedback.php', ['verdict' => 'sideways', 'userText' => 'AILT x', 'replyText' => 'y']);
    check('an invented verdict is refused', $r['code'] === 422 || $r['code'] === 400, (string) $r['code']);
    $r = req('POST', '/api/ai-feedback.php', ['verdict' => 'up', 'userText' => 'AILT x', 'replyText' => '']);
    check('feedback on nothing is refused', $r['code'] === 422 || $r['code'] === 400, (string) $r['code']);
    $ch = curl_init(BASE . '/api/ai-feedback.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['verdict' => 'up', 'replyText' => 'z']), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 20]);
    curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    check('without a CSRF token: refused', $code === 419 || $code === 403, (string) $code);
}

Database::run("DELETE FROM ai_examples WHERE user_text LIKE 'AILT %'");
Database::run("DELETE FROM ai_feedback_log WHERE user_text LIKE 'AILT %'");
$restore();

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
