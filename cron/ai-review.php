<?php
/**
 * cron/ai-review.php — Claude reviews the day that just closed (19 Nov 2026,
 * unit u8 / AI-1). includes/aireview.php does the work.
 *
 *     20 0 * * * /usr/bin/php /path/to/public_html/cron/ai-review.php >> /path/to/logs/ai-review.log 2>&1
 *
 *   - runs in the hour ai_review_hour (default 0 = 00:00-00:59 IST) for
 *     YESTERDAY; one Opus call, capped by ai_review_usd (1.50) a night,
 *     ai_review_month_usd (45) a month and the daily AI ceiling
 *   - switch: ai_review_on (default 1). No Anthropic key → a 'skipped: no
 *     key' row. A day already reviewed 'ok' is not reviewed again.
 *   - one run at a time (LOG_PATH/ai-review.lock); CLI only
 *
 *   --day=YYYY-MM-DD  review this day instead of yesterday
 *   --now             ignore the hour
 *   --force           review again even when the day has an 'ok' row
 *   --dry             print the facts and the prompt; no API call, nothing saved
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/aiknowledge.php';
require_once INCLUDE_PATH . '/ailearn.php';
require_once INCLUDE_PATH . '/aiteach.php';
require_once INCLUDE_PATH . '/aireview.php';

if (PHP_SAPI !== 'cli') {
    cron_done(['skipped' => 'cli only']);
    exit;
}
Settings::flush();

$args  = array_slice($argv ?? [], 1);
$now   = in_array('--now', $args, true);
$dry   = in_array('--dry', $args, true);
$force = in_array('--force', $args, true);
$day   = AiReview::closedDay();
foreach ($args as $a) {
    if (preg_match('/^--day=(\d{4}-\d{2}-\d{2})$/', $a, $m) === 1) {
        $day = $m[1];
    }
}

// The switch is read BEFORE anything is claimed or written.
if (!AiReview::enabled()) {
    cron_done(['skipped' => 'ai_review_on is off']);
    exit;
}
$hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('G');
if (!$now && !$dry && $hour !== AiReview::hour()) {
    cron_done(['skipped' => 'outside ai_review_hour (' . AiReview::hour() . ' IST, now ' . $hour . ')']);
    exit;
}

$lock = @fopen(LOG_PATH . '/ai-review.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    cron_done(['skipped' => 'another review is running']);
    exit;
}
@set_time_limit(900);
try {
    $r = AiReview::run($day, $dry, $force);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($dry) {
    echo "=== FACTS (" . $day . ") ===\n", json_encode($r['facts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n\n";
    echo "=== SYSTEM (cached) ===\n", (string) ($r['prompt']['system'] ?? ''), "\n\n";
    echo "=== TASK ===\n", (string) ($r['prompt']['task'] ?? ''), "\n\n";
    echo "model ", (string) ($r['prompt']['model'] ?? ''), " · effort high · max_tokens ", (int) ($r['prompt']['max_tokens'] ?? 0),
        " · worst case $", number_format((float) ($r['prompt']['estimate_usd'] ?? 0), 2), "\n";
    unset($r['facts'], $r['prompt']);
}
Logger::info('AI review run', $r, 'ai');
cron_done($r, in_array($r['status'], ['ok', 'skipped', 'dry'], true));
