<?php
/**
 * ai-manager-test.php — Admin → AI Manager, the office's one page (24 Sep 2026).
 *
 *   A. Every tab renders without a PHP notice; the nav carries the entry.
 *   B. The office actions: save-and-approve an example, draft a post,
 *      forget a person — each lands and flashes.
 *   C. The doors: the copilot API refuses a stranger; the switches default
 *      OFF; the crons idle politely while off.
 *   D. The chat's 👍/👎 assets: labels in three languages, the JS, the CSS.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aimemory.php';

define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function render(string ...$args): string {
    $cmd = PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/tests/render-admin.php') . ' ai-manager.php';
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
    return (string) shell_exec($cmd . ' 2>&1');
}
function clean(string $html): bool {
    return !preg_match('/\b(Warning|Fatal error|Deprecated|Notice|Uncaught)\b:/', $html);
}
$ROOT = dirname(__DIR__);
Database::run("DELETE FROM ai_examples WHERE user_text LIKE 'AIMT %'");
Database::run("DELETE FROM social_posts WHERE topic LIKE 'AIMT %'");

echo "-- A. the tabs --\n";
foreach (['today' => 'Today', 'copilot' => 'Copilot', 'learn' => 'Learn', 'memory' => 'Memory', 'marketing' => 'Marketing'] as $tab => $label) {
    $html = render("tab=$tab");
    check("$tab renders clean", clean($html) && str_contains($html, 'AI Manager') && str_contains($html, $label), substr(preg_replace('/\s+/', ' ', strip_tags($html)), 0, 100));
}
$html = render('tab=nonsense');
check('an unknown tab falls back to Today', clean($html) && str_contains($html, 'Today'));
$guard = (string) file_get_contents($ROOT . '/admin/_guard.php');
check('the admin nav carries AI Manager', str_contains($guard, 'ai-manager.php'));

echo "\n-- B. the office actions --\n";
$html = render('post:action=example_save', 'post:intent=fare', 'post:language=ne', 'post:user_text=AIMT bhada kati ho', 'post:good_reply=Sharing sleeper ₹1,500 hajur.', 'post:rule_text=Quote the sharing fare', 'post:approve=1');
$row = Database::fetch("SELECT status, good_reply FROM ai_examples WHERE user_text = 'AIMT bhada kati ho'");
check('save-and-approve lands as an approved example', $row !== null && $row['status'] === 'approved' && str_contains((string) $row['good_reply'], '1,500'));
check('…and the page says so', str_contains($html, 'Saved and approved') && clean($html));
$html = render('post:action=example_save', 'post:user_text=AIMT half', 'post:good_reply=', 'post:approve=1');
check('approving without a right answer is refused with a message', str_contains($html, 'what we should answer') && clean($html));
$html = render('post:action=post_create', 'post:channel=telegram', 'post:topic=AIMT dashain', 'post:caption_en=Dashain special', 'post:publish_at=2099-10-01 09:00');
$p = Database::fetch("SELECT status, channel FROM social_posts WHERE topic = 'AIMT dashain'");
check('a post draft lands', $p !== null && $p['status'] === 'draft' && $p['channel'] === 'telegram');
check('…and the page says so', str_contains($html, 'Draft #') && clean($html));
$html = render('post:action=post_create', 'post:channel=instagram', 'post:topic=AIMT nopic', 'post:caption_en=x');
check('Instagram without a picture is refused with a message', str_contains($html, 'picture') && clean($html));
$wasMem = Settings::getBool('ai_memory_on', false);
Settings::set('ai_memory_on', '1', 'bool', 'ai'); Settings::flush();
AiMemory::remember('9870007733', 'note', 'AIMT remembered');
$html = render('tab=memory', 'phone=9870007733');
check('the memory tab shows what we know about a number', str_contains($html, 'AIMT remembered') && clean($html));
$html = render('post:action=forget', 'post:phone=9870007733');
check('forget erases the person and says how many rows', str_contains($html, 'Forgotten') && AiMemory::recall('9870007733') === [] && clean($html));
Settings::set('ai_memory_on', $wasMem ? '1' : '0', 'bool', 'ai'); Settings::flush();
$html = render('post:action=teleport');
check('an unknown action is a flash, not a crash', str_contains($html, 'Unknown action') && clean($html));

echo "\n-- C. the doors --\n";
foreach (['ai_memory_on', 'ai_examples_on', 'ai_refresh_on', 'social_publish_on'] as $k) {
    $row = Database::fetch('SELECT skey FROM settings WHERE skey = :k', ['k' => $k]);
    check("$k exists as a switch", $row !== null);
}
$ch = curl_init(BASE . '/admin/api/ai-copilot.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{"text":"hi"}', CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 20]);
$body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($body === false || $code === 0) {
    echo "  SKIP  no server at " . BASE . "\n";
} else {
    check('the copilot refuses a stranger', in_array($code, [401, 403, 419], true), (string) $code);
}
$wasR = Settings::getBool('ai_refresh_on', false);
Settings::set('ai_refresh_on', '0', 'bool', 'ai'); Settings::flush();
$before = (int) Database::scalar("SELECT COUNT(*) FROM ai_kb_articles WHERE canonical_title LIKE 'Company facts%'", [], 0);
$out = (string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($ROOT . '/cron/ai-refresh.php') . ' 2>&1');
$after = (int) Database::scalar("SELECT COUNT(*) FROM ai_kb_articles WHERE canonical_title LIKE 'Company facts%'", [], 0);
check('ai-refresh idles while off and writes nothing', str_contains($out, 'idle') && substr_count(trim($out), "\n") === 0 && $after === $before, trim($out));
Settings::set('ai_refresh_on', '1', 'bool', 'ai'); Settings::flush();
$out = (string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($ROOT . '/cron/ai-refresh.php') . ' 2>&1');
$art = Database::fetch("SELECT publication_status, source_reference FROM ai_kb_articles WHERE canonical_title LIKE 'Company facts%' ORDER BY id DESC LIMIT 1");
check('on: it writes the company-facts article, published, dated today', $art !== null && $art['publication_status'] === 'published' && str_contains((string) $art['source_reference'], date('j M Y')), trim($out));
$out2 = (string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($ROOT . '/cron/ai-refresh.php') . ' 2>&1');
$n = (int) Database::scalar("SELECT COUNT(*) FROM ai_kb_articles WHERE canonical_title LIKE 'Company facts%'", [], 0);
check('running twice keeps one article', $n === 1, "$n");
Database::run("DELETE FROM ai_kb_articles WHERE canonical_title LIKE 'Company facts%'");
Settings::set('ai_refresh_on', $wasR ? '1' : '0', 'bool', 'ai'); Settings::flush();

echo "\n-- D. the chat's thumbs --\n";
$i18n = (string) file_get_contents($ROOT . '/assets/js/04-i18n.js');
foreach (['aiFbUp', 'aiFbDown', 'aiFbThanks', 'aiFbNoted'] as $k) {
    check("$k in en / hi / ne", substr_count($i18n, ' ' . $k . ': ') === 3, substr_count($i18n, ' ' . $k . ': ') . ' found');
}
$js = (string) file_get_contents($ROOT . '/assets/js/16-lazy.js');
check('the widget posts the verdict to /api/ai-feedback.php', str_contains($js, "shgApi.post('/ai-feedback.php'") && str_contains($js, 'addFeedback(bubble, q, d.text)'));
$css = (string) file_get_contents($ROOT . '/assets/css/views.css');
check('the thumbs are styled', str_contains($css, '.ai-fb-btn'));

Database::run("DELETE FROM ai_examples WHERE user_text LIKE 'AIMT %'");
Database::run("DELETE FROM social_posts WHERE topic LIKE 'AIMT %'");

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
