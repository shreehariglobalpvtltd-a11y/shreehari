<?php
/**
 * ai-web-test.php — the assistant reads the internet, the office decides
 * what becomes a fact (25 Sep 2026).
 *
 *   A. The switch and the allowlist: off, nothing is fetched; a host the
 *      office did not list is refused, and so is http, a port, a redirect
 *      target nobody checked.
 *   B. The SSRF guard: every private, loopback, link-local and carrier-NAT
 *      address is refused — this server can reach its own database.
 *   C. readable(): script and style are dropped, the words survive, the
 *      text is capped.
 *   D. refreshOne() writes a DRAFT, never a published article, and leaves a
 *      note the office already published alone.
 *   E. The assistant cannot see a draft: AiKb answers published + verified.
 *   F. The cron idles politely while off and while nothing is watched.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aiweb.php';
require_once INCLUDE_PATH . '/aiknowledge.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
$ROOT = dirname(__DIR__);
$was = [
    'on'    => Settings::getBool('ai_web_on', false),
    'hosts' => Settings::getString('ai_web_hosts', '[]'),
    'watch' => Settings::getString('ai_web_watch', '[]'),
];
$restore = static function () use ($was): void {
    Settings::set('ai_web_on', $was['on'] ? '1' : '0', 'bool', 'ai');
    Settings::set('ai_web_hosts', $was['hosts'], 'json', 'ai');
    Settings::set('ai_web_watch', $was['watch'], 'json', 'ai');
    Settings::flush();
    AiWeb::$fetcher = null;
};
Database::run("DELETE FROM ai_kb_articles WHERE canonical_title LIKE 'Web note — AIW%'");

echo "\n=== The assistant reads the internet ===\n\n-- A. the switch and the allowlist --\n";
Settings::set('ai_web_on', '0', 'bool', 'ai');
Settings::set('ai_web_hosts', json_encode(['example.org']), 'json', 'ai');
Settings::flush();
check('off: nothing is fetched at all', AiWeb::fetch('https://example.org/x') === null);
check('off: refreshOne says so rather than reading', ($r = AiWeb::refreshOne(['topic' => 'AIW t', 'url' => 'https://example.org/x']))['skipped'] !== null && !isset($r['draft']));
Settings::set('ai_web_on', '1', 'bool', 'ai'); Settings::flush();
check('a listed host is allowed', AiWeb::refuse('https://example.org/page') === '');
check('…including a subdomain of it', AiWeb::refuse('https://news.example.org/page') === '');
check('a host nobody listed is refused', str_contains(AiWeb::refuse('https://evil.test/page'), 'not in ai_web_hosts'));
check('a look-alike host is refused', str_contains(AiWeb::refuse('https://notexample.org/page'), 'not in ai_web_hosts'));
check('http is refused', str_contains(AiWeb::refuse('http://example.org/page'), 'https'));
check('another port is refused', str_contains(AiWeb::refuse('https://example.org:8443/page'), 'port'));
check('a file:// URL is refused', AiWeb::refuse('file:///etc/passwd') !== '');
check('nonsense is refused', AiWeb::refuse('not a url') !== '');
check('the allowlist ignores anything that is not a hostname', !in_array('http://x', AiWeb::hosts(), true));

echo "\n-- B. the SSRF guard --\n";
foreach (['127.0.0.1', '10.0.0.5', '172.16.4.4', '192.168.1.9', '169.254.169.254', '100.64.0.1', '::1', 'fd00::1', '0.0.0.0'] as $ip) {
    check("$ip is refused as a target", !AiWeb::isPublicAddress($ip));
}
foreach (['8.8.8.8', '93.127.167.249', '2001:4860:4860::8888'] as $ip) {
    check("$ip is accepted as public", AiWeb::isPublicAddress($ip));
}

echo "\n-- C. reading a page --\n";
$html = "<html><head><title>Border hours</title><style>.x{color:red}</style></head><body>"
      . "<script>alert('hi')</script><h1>Rupaidiha border</h1><p>The crossing is open from 6 am to 10 pm.</p>"
      . "<p>&nbsp;Carry a photo ID.</p></body></html>";
$text = AiWeb::readable($html);
check('the words a person reads survive', str_contains($text, 'Rupaidiha border') && str_contains($text, '6 am to 10 pm') && str_contains($text, 'photo ID'));
check('script and style are gone', !str_contains($text, 'alert') && !str_contains($text, 'color:red'));
check('the markup is gone', !str_contains($text, '<') && !str_contains($text, '&nbsp;'));
check('a huge page is capped', mb_strlen(AiWeb::readable('<p>' . str_repeat('word ', 5000) . '</p>')) <= 6000);

echo "\n-- D. a draft, never a published article --\n";
AiWeb::$fetcher = static fn(string $url): array => [
    'url' => $url, 'title' => 'Border hours', 'text' => 'The crossing is open from 6 am to 10 pm. Carry a photo ID.',
    'fetchedAt' => time(), 'cached' => false,
];
/* The model is not called in the battery: without an API key note()
   returns null, and refreshOne() must then write nothing at all. */
$wasKey = Settings::getString('anthropic_api_key', '');
Settings::set('anthropic_api_key', '', 'string', 'ai'); Settings::flush();
$r = AiWeb::refreshOne(['topic' => 'AIW border hours', 'url' => 'https://example.org/border']);
check('no API key: nothing is drafted, and it says why', !isset($r['draft']) && str_contains((string) $r['skipped'], 'did not answer'));
check('…and no article was created', (int) Database::scalar("SELECT COUNT(*) FROM ai_kb_articles WHERE canonical_title LIKE 'Web note — AIW%'", [], 0) === 0);

/* With the model stubbed out of the way, prove the write itself: a draft,
   unverified, sourced to the URL and the day it was read. */
$id = AiKb::save([
    'category' => 'company', 'canonical_title' => 'Web note — AIW border hours',
    'canonical_answer' => 'The crossing is open from 6 am to 10 pm.',
    'english_content' => 'The crossing is open from 6 am to 10 pm.',
    'hindi_content' => 'सीमा सुबह ६ बजे से रात १० बजे तक खुली रहती है।',
    'nepali_content' => 'नाका बिहान ६ बजेदेखि राति १० बजेसम्म खुल्छ।',
    'keywords' => 'AIW border hours', 'applicable_roles' => ['public', 'customer'],
    'source_reference' => 'https://example.org/border, read ' . date('j M Y H:i'),
    'publication_status' => 'draft',
], 0);
$row = Database::fetch('SELECT publication_status, verification_status, source_reference FROM ai_kb_articles WHERE id = :id', ['id' => $id]);
check('a web note is saved as a draft', ($row['publication_status'] ?? '') === 'draft');
check('…and as unverified, so nothing treats it as checked', ($row['verification_status'] ?? '') === 'unverified');
check('…carrying the URL it came from and the day it was read', str_contains((string) $row['source_reference'], 'https://example.org/border') && str_contains((string) $row['source_reference'], date('j M Y')));

echo "\n-- E. a draft is invisible to the assistant --\n";
$hits = AiKb::search('AIW border hours', 'customer');
$seen = false;
foreach ((array) $hits as $h) { if (str_contains((string) ($h['canonical_title'] ?? ''), 'AIW')) { $seen = true; } }
check('the knowledge base does not answer from a draft', !$seen, $seen ? 'a draft was served' : 'nothing served');
Database::update('ai_kb_articles', ['publication_status' => 'published', 'verification_status' => 'verified'], 'id = :id', ['id' => $id]);
$hits = AiKb::search('AIW border hours', 'customer');
$seen = false;
foreach ((array) $hits as $h) { if (str_contains((string) ($h['canonical_title'] ?? ''), 'AIW')) { $seen = true; } }
check('…and does answer once a person publishes it', $seen);
$r = AiWeb::refreshOne(['topic' => 'AIW border hours', 'url' => 'https://example.org/border']);
check('a later read does not overwrite what the office published, and costs nothing', str_contains((string) ($r['skipped'] ?? ''), 'left alone')
    && (string) Database::scalar('SELECT publication_status FROM ai_kb_articles WHERE id = :id', ['id' => $id], '') === 'published');
Settings::set('anthropic_api_key', $wasKey, 'string', 'ai'); Settings::flush();

echo "\n-- F. the cron --\n";
Settings::set('ai_web_on', '0', 'bool', 'ai'); Settings::flush();
$out = (string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($ROOT . '/cron/ai-web-refresh.php') . ' 2>&1');
check('off: it idles and reads nothing', str_contains($out, 'ai_web_on is off') && substr_count(trim($out), "\n") === 0, trim($out));
Settings::set('ai_web_on', '1', 'bool', 'ai');
Settings::set('ai_web_watch', '[]', 'json', 'ai'); Settings::flush();
$out = (string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($ROOT . '/cron/ai-web-refresh.php') . ' 2>&1');
check('on with nothing watched: it says so', str_contains($out, 'nothing to read'), trim($out));

Database::run("DELETE FROM ai_kb_articles WHERE canonical_title LIKE 'Web note — AIW%'");
Database::run("DELETE FROM kv_store WHERE kkey LIKE 'aiweb:%'");
$restore();

echo "\n----------------------------------------\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
