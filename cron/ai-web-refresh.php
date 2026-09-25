<?php
/**
 * cron/ai-web-refresh.php — read the pages the office watches, and leave
 * a draft for a person to approve.
 *
 *     06:40 daily:  /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/ai-web-refresh.php
 *
 *  Off unless ai_web_on is on and ai_web_watch lists something. For each
 *  watch it fetches the page (https, allow-listed host, no redirects, no
 *  private addresses — includes/aiweb.php), asks Claude for a short note
 *  in English, Hindi and Nepali, and saves it as a knowledge-base DRAFT.
 *
 *  Nothing it writes can be said to a passenger until somebody opens
 *  Admin -> Knowledge and publishes it: AiKb answers from rows that are
 *  published AND verified only. That is the point of this job — it does
 *  the reading, the office keeps the deciding.
 */

declare(strict_types=1);

require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/aiweb.php';

if (!AiWeb::enabled()) {
    cron_done(['idle' => 'ai_web_on is off']);
    exit(0);
}

$watches = AiWeb::watches();
if ($watches === []) {
    cron_done(['idle' => 'ai_web_watch is empty — nothing to read']);
    exit(0);
}

$result = ['read' => 0, 'drafted' => 0, 'skipped' => []];
foreach (array_slice($watches, 0, 12) as $watch) {
    try {
        $r = AiWeb::refreshOne($watch);
        $result['read']++;
        if (isset($r['draft'])) {
            $result['drafted']++;
        } else {
            $result['skipped'][] = $watch['topic'] . ': ' . (string) ($r['skipped'] ?? 'no reason given');
        }
    } catch (Throwable $e) {
        $result['skipped'][] = $watch['topic'] . ': ' . mb_substr($e->getMessage(), 0, 120);
    }
}

cron_done($result);
