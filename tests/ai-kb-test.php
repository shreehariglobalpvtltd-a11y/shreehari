<?php
/**
 * =====================================================================
 *  ai-kb-test.php — the assistant's knowledge base (22 Sep 2026).
 *
 *  includes/aiknowledge.php (class AiKb) reads curated company knowledge
 *  from ai_kb_articles and hands the WhatsApp assistant a verified answer
 *  to a policy / FAQ / how-to question. This suite pins the only parts
 *  that could hurt: the AUDIENCE (a customer never reads a staff-only
 *  article), the SWITCH (the tool vanishes when ai_kb_on is off), that a
 *  MISS never invents an answer, and that a stored miss is REDACTED.
 *
 *  Self-contained: it creates its own throwaway articles and cleans up.
 *      php tests/ai-kb-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aiknowledge.php';
require_once INCLUDE_PATH . '/aitools.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Assistant knowledge base — audience, switch, honest miss ===\n\n";

/* The tables may not be migrated on a bare test database; make the suite
   stand on its own exactly as the migration does. Wrapped: an
   already-migrated database makes these a harmless no-op. */
try {
Database::run("CREATE TABLE IF NOT EXISTS ai_kb_articles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category VARCHAR(80) NOT NULL, slug VARCHAR(160) NOT NULL UNIQUE,
 canonical_title VARCHAR(200) NOT NULL, canonical_answer TEXT NOT NULL, nepali_content TEXT NULL, hindi_content TEXT NULL,
 english_content TEXT NULL, roman_nepali_examples TEXT NULL, roman_hindi_examples TEXT NULL, keywords TEXT NULL, synonyms TEXT NULL,
 applicable_roles JSON NOT NULL, source_type VARCHAR(40) NOT NULL, source_reference VARCHAR(255) NOT NULL,
 verification_status VARCHAR(30) NOT NULL DEFAULT 'unverified', publication_status VARCHAR(30) NOT NULL DEFAULT 'draft',
 effective_from DATETIME NULL, review_after DATETIME NULL, version INT NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY ix_ai_kb_status(publication_status,verification_status,category), KEY ix_ai_kb_review(review_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
Database::run("CREATE TABLE IF NOT EXISTS ai_kb_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, article_id BIGINT UNSIGNED NOT NULL, version INT NOT NULL,
 snapshot JSON NOT NULL, created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_ai_kb_version(article_id,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
Database::run("CREATE TABLE IF NOT EXISTS ai_unanswered_questions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, question_hash CHAR(64) NOT NULL UNIQUE, normalized_question TEXT NOT NULL,
 language VARCHAR(10) NOT NULL, frequency INT NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'new',
 article_id BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY ix_ai_unanswered(status,frequency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { /* tables already exist, or DDL is restricted — the inserts below will report the real state */ }

/* ---- restore whatever ai_kb_on was, exactly ------------------------- */
$prior = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => 'ai_kb_on']);
$cleanup = static function () use ($prior): void {
    try { Database::run("DELETE FROM ai_kb_versions WHERE article_id IN (SELECT id FROM ai_kb_articles WHERE slug LIKE 'zztest-%')"); } catch (Throwable $e) {}
    try { Database::delete('ai_kb_articles', "slug LIKE 'zztest-%'", []); } catch (Throwable $e) {}
    try { Database::delete('ai_unanswered_questions', "normalized_question LIKE '%zzqmarker%'", []); } catch (Throwable $e) {}
    if ($prior === null) {
        try { Database::delete('settings', 'skey = :k', ['k' => 'ai_kb_on']); } catch (Throwable $e) {}
    } else {
        try {
            Database::update('settings', ['svalue' => (string) $prior['svalue'], 'stype' => (string) $prior['stype'],
                'sgroup' => (string) $prior['sgroup'], 'is_public' => (int) $prior['is_public']], 'skey = :k', ['k' => 'ai_kb_on']);
        } catch (Throwable $e) {}
    }
    Settings::flush();
};
register_shutdown_function($cleanup);
$cleanup();   // start from a known-clean slate

/* ---- two throwaway articles: one public, one staff-only ------------- */
Database::insert('ai_kb_articles', [
    'category' => 'baggage', 'slug' => 'zztest-luggage', 'canonical_title' => 'Luggage allowance',
    'canonical_answer' => 'Each passenger may carry one suitcase and a small bag.',
    'english_content' => 'Each passenger may carry one suitcase and a small bag.',
    'nepali_content' => 'हरेक यात्रुले एउटा सुटकेस र सानो झोला बोक्न सक्नुहुन्छ।',
    'hindi_content' => 'हर यात्री एक सूटकेस और एक छोटा बैग ले जा सकता है।',
    'keywords' => 'luggage bag baggage saman jhola suitcase', 'synonyms' => 'saman, jhola, bag',
    'roman_nepali_examples' => 'kati saman lana milcha, jhola kati bokna milcha',
    'applicable_roles' => json_encode(['public']), 'source_type' => 'test', 'source_reference' => 'ai-kb-test',
    'verification_status' => 'verified', 'publication_status' => 'published',
]);
Database::insert('ai_kb_articles', [
    'category' => 'agent', 'slug' => 'zztest-agentonly', 'canonical_title' => 'Agent settlement',
    'canonical_answer' => 'Agent settlement runs on the weekly ledger.',
    'english_content' => 'Agent settlement runs on the weekly ledger.',
    'keywords' => 'settlement ledger payout hisab weekly', 'synonyms' => 'hisab, settlement',
    'applicable_roles' => json_encode(['agent', 'counter']), 'source_type' => 'test', 'source_reference' => 'ai-kb-test',
    'verification_status' => 'verified', 'publication_status' => 'published',
]);

/* ---- search + audience --------------------------------------------- */
$hit = AiKb::search('luggage', 'customer', 3);
check('a public article is found by keyword', $hit !== [] && ($hit[0]['canonical_title'] ?? '') === 'Luggage allowance');
check('found by a roman-Nepali word too', AiKb::search('saman', 'customer') !== []);
check('found across two words', AiKb::search('luggage bag', 'customer') !== []);

check('a customer does NOT see a staff-only article', AiKb::search('settlement', 'customer') === []);
check('a staff number DOES see the staff-only article', AiKb::search('settlement', 'staff') !== []);
check('the office sees the staff-only article', AiKb::search('settlement', 'admin') !== []);

/* ---- language selection -------------------------------------------- */
$lug = AiKb::search('luggage', 'customer')[0] ?? [];
check('answer() returns Nepali content for ne', str_contains(AiKb::answer($lug, 'ne'), 'सुटकेस'));
check('answer() returns English content for en', str_contains(AiKb::answer($lug, 'en'), 'suitcase'));
check('answer() falls back to canonical when a language is blank',
    AiKb::answer(['canonical_answer' => 'only canonical'], 'hi') === 'only canonical');

/* ---- an honest miss, recorded and redacted ------------------------- */
check('an unknown question returns nothing', AiKb::search('zzqmarker floating city', 'customer') === []);
AiKb::logUnknown('zzqmarker floating city', 'en');
$row = Database::fetch("SELECT frequency FROM ai_unanswered_questions WHERE normalized_question LIKE '%zzqmarker floating%'");
check('the miss is recorded once', $row !== null && (int) $row['frequency'] === 1);
AiKb::logUnknown('zzqmarker floating city', 'en');
$row = Database::fetch("SELECT frequency FROM ai_unanswered_questions WHERE normalized_question LIKE '%zzqmarker floating%'");
check('the same miss increments, never duplicates', $row !== null && (int) $row['frequency'] === 2);

AiKb::logUnknown('zzqmarker my number is 9876543210 why charged', 'en');
$red = Database::fetch("SELECT normalized_question FROM ai_unanswered_questions WHERE normalized_question LIKE '%zzqmarker my number%'");
check('a stored miss is redacted (no raw phone)',
    $red !== null && !str_contains((string) $red['normalized_question'], '9876543210')
        && str_contains((string) $red['normalized_question'], '[number]'));

/* ---- the switch and the tool through AiTools ------------------------ */
$cust = ['role' => 'customer', 'phone' => '9100007001', 'adminId' => 0, 'scopeAdminId' => null,
         'name' => '', 'channel' => 'whatsapp', 'turn' => 1, 'messageText' => 'luggage kati lana milcha'];

Settings::set('ai_kb_on', true, 'bool', 'ai');
Settings::flush();
$names = array_column(AiTools::catalogue($cust), 'name');
check('knowledge_lookup is offered when ai_kb_on is 1', in_array('knowledge_lookup', $names, true));

$on = AiTools::run('knowledge_lookup', ['query' => 'luggage'], $cust);
check('run() returns the article when on', ($on['ok'] ?? false) === true && ($on['data']['found'] ?? null) === true
    && ($on['data']['articles'][0]['title'] ?? '') === 'Luggage allowance');

$miss = AiTools::run('knowledge_lookup', ['query' => 'zzqmarker unknowable thing'], $cust);
check('run() reports an honest miss without inventing', ($miss['ok'] ?? false) === true
    && ($miss['data']['found'] ?? null) === false && !str_contains(strtolower((string) $miss['say']), 'yes'));

Settings::set('ai_kb_on', false, 'bool', 'ai');
Settings::flush();
$namesOff = array_column(AiTools::catalogue($cust), 'name');
check('knowledge_lookup disappears when ai_kb_on is 0', !in_array('knowledge_lookup', $namesOff, true));
$off = AiTools::run('knowledge_lookup', ['query' => 'luggage'], $cust);
check('run() refuses the tool by name when off', ($off['ok'] ?? true) === false);

/* ---- the office writes an answer (AiKb::save / setState) ------------ */
$newId = AiKb::save([
    'canonical_title' => 'Zztest Save Article', 'canonical_answer' => 'A saved answer about zztest widgets.',
    'english_content' => 'A saved answer about zztest widgets.', 'keywords' => 'zztest widget saved',
    'applicable_roles' => ['public'], 'source_reference' => 'ai-kb-test', 'publication_status' => 'published',
], 4242);
check('save() creates a published article', $newId > 0);
check('a saved published article is searchable', AiKb::search('zztest widget', 'customer') !== []);

AiKb::save([
    'canonical_title' => 'Zztest Save Article', 'canonical_answer' => 'An edited answer about zztest widgets.',
    'english_content' => 'An edited answer about zztest widgets.', 'keywords' => 'zztest widget saved',
    'applicable_roles' => ['public'], 'source_reference' => 'ai-kb-test', 'publication_status' => 'published',
], 4242, $newId);
$ver = Database::fetch('SELECT version FROM ai_kb_articles WHERE id = :id', ['id' => $newId]);
check('an edit bumps the version', $ver !== null && (int) $ver['version'] === 2);
check('the previous version is snapshotted', (int) Database::scalar('SELECT COUNT(*) FROM ai_kb_versions WHERE article_id = :a', ['a' => $newId], 0) >= 1);

AiKb::setState($newId, 'archived', 4242);
check('an archived article disappears from search', AiKb::search('zztest widget', 'customer') === []);

$threw = static function (callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } };
check('save() rejects an empty title', $threw(static fn() => AiKb::save(['canonical_title' => '', 'canonical_answer' => 'x', 'applicable_roles' => ['public'], 'source_reference' => 's'], 1)));
check('save() rejects no audience', $threw(static fn() => AiKb::save(['canonical_title' => 'Zztest T', 'canonical_answer' => 'x', 'applicable_roles' => [], 'source_reference' => 's'], 1)));
check('save() rejects a missing source', $threw(static fn() => AiKb::save(['canonical_title' => 'Zztest T', 'canonical_answer' => 'x', 'applicable_roles' => ['public'], 'source_reference' => ''], 1)));

/* ---- registered in the battery ------------------------------------- */
$runAll = @file_get_contents(dirname(__DIR__) . '/tests/run-all.php') ?: '';
check('this suite is registered in the battery', str_contains($runAll, 'ai-kb-test.php'));

echo "\n" . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n\n";
exit($FAIL === 0 ? 0 : 1);
