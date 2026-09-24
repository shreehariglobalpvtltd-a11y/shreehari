<?php
/**
 * cron/ai-refresh.php — the assistant's nightly housekeeping (24 Sep 2026).
 *
 *   02:20 daily:   /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/ai-refresh.php
 *
 *  1. Company facts → the knowledge base. The routes, pickups, fares and
 *     the refund ladder the assistant states come from ai_system_prompt(),
 *     read live. This job writes the same facts into ONE published
 *     ai_kb_articles row ("Company facts — auto") with today's date as its
 *     source, so a knowledge-base answer and a prompt answer can never
 *     disagree, and the office can see in one place what the assistant
 *     believes.
 *  2. Memory hygiene: expired episodes go; a person keeps at most 120.
 *  3. Learning hygiene: candidates older than 90 days with no answer
 *     written are retired, so the approve list stays short.
 *
 *  Behind ai_refresh_on (OFF). Never calls a model, never spends money.
 */

declare(strict_types=1);

require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/health.php';

$t0 = microtime(true);
if (!Settings::getBool('ai_refresh_on', false)) {
    cron_done(['idle' => 'ai_refresh_on is off']);
    exit(0);
}

$result = ['facts' => 'skipped', 'episodes_pruned' => 0, 'candidates_retired' => 0];

try {
    /* 1. company facts */
    require_once INCLUDE_PATH . '/aiprompt.php';
    require_once INCLUDE_PATH . '/aiknowledge.php';
    $facts = ai_system_prompt();
    $start = strpos($facts, 'FACTS (');
    $end   = strpos($facts, 'STYLE:');
    $body  = $start !== false && $end !== false && $end > $start ? trim(substr($facts, $start, $end - $start)) : trim($facts);
    $title = 'Company facts — auto (routes, pickups, fares, refunds)';
    $existing = Database::fetch('SELECT id FROM ai_kb_articles WHERE canonical_title = :t LIMIT 1', ['t' => $title]);
    $id = AiKb::save([
        'category'           => 'company',
        'canonical_title'    => $title,
        'canonical_answer'   => $body,
        'english_content'    => $body,
        'keywords'           => 'route, timing, pickup, fare, price, refund, cancel, border, luggage, office',
        'applicable_roles'   => ['public', 'customer', 'agent', 'counter', 'support', 'manager'],
        'source_reference'   => 'cron/ai-refresh.php from settings + routes, ' . date('j M Y H:i'),
        'publication_status' => 'published',
    ], 0, $existing !== null ? (int) $existing['id'] : null);
    $result['facts'] = ($existing !== null ? 'updated' : 'created') . ' article #' . $id;
} catch (Throwable $e) {
    $result['facts'] = 'failed: ' . $e->getMessage();
}

try {
    /* 2. memory hygiene */
    $result['episodes_pruned'] = Database::delete('ai_memory_episodes', 'expires_at IS NOT NULL AND expires_at < CURDATE()');
    $heavy = Database::fetchAll('SELECT owner_key, COUNT(*) n FROM ai_memory_episodes GROUP BY owner_key HAVING n > 120');
    foreach ($heavy as $h) {
        $ids = array_column(Database::fetchAll(
            'SELECT id FROM ai_memory_episodes WHERE owner_key = :k ORDER BY importance ASC, happened_at ASC LIMIT ' . ((int) $h['n'] - 120),
            ['k' => $h['owner_key']]
        ), 'id');
        if ($ids !== []) {
            $result['episodes_pruned'] += Database::delete('ai_memory_episodes', 'id IN (' . implode(',', array_map('intval', $ids)) . ')');
        }
    }
    /* 3. learning hygiene */
    $result['candidates_retired'] = Database::run(
        "UPDATE ai_examples SET status = 'retired' WHERE status = 'candidate' AND good_reply = '' AND created_at < :old",
        ['old' => date('Y-m-d H:i:s', time() - 90 * 86400)]
    );
} catch (Throwable $e) {
    $result['hygiene'] = 'failed: ' . $e->getMessage();
}

try {
    Health::beat('ai-refresh', (int) round((microtime(true) - $t0) * 1000), $result, !str_starts_with((string) $result['facts'], 'failed'));
} catch (Throwable $e) { /* the heartbeat is optional */ }

cron_done($result, !str_starts_with((string) $result['facts'], 'failed'));
