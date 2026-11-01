<?php
/**
 * cron/ai-teach.php — Claude writes the next knowledge articles for the
 * free local bot (1 Nov 2026). includes/aiteach.php does the work.
 *
 *     40 * * * * php /path/to/public_html/cron/ai-teach.php >/dev/null 2>&1
 *
 *   - runs only inside ai_teach_hours (default "2-5" IST), once per day
 *     (kv ai_teach:day), at most ai_teach_run_usd (2) per run and
 *     ai_teach_budget_usd (25) in all — the spend is priced from the
 *     API's own usage figures
 *   - quietly skips with no Anthropic key, the switch ai_teach_on off, or
 *     the budget used up
 *   - one run at a time (LOG_PATH/ai-teach.lock); CLI only
 *
 *   --now        ignore the hours and today's run (first training, by hand)
 *   --usd=1.5    this run's budget
 *   --dry        call Claude and count, save nothing
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/aiknowledge.php';
require_once INCLUDE_PATH . '/ailearn.php';
require_once INCLUDE_PATH . '/aiteach.php';
require_once INCLUDE_PATH . '/agentpro-learn.php';

if (PHP_SAPI !== 'cli') {
    cron_done(['skipped' => 'cli only']);
    exit;
}
Settings::flush();

$args = array_slice($argv ?? [], 1);
$now = in_array('--now', $args, true);
$dry = in_array('--dry', $args, true);
$usd = null;
foreach ($args as $a) {
    if (preg_match('/^--usd=(\d+(?:\.\d+)?)$/', $a, $m) === 1) {
        $usd = max(0.05, min(20.0, (float) $m[1]));
    }
}

if (!AiTeach::on()) {
    cron_done(['skipped' => 'ai_teach_on off or no Anthropic key']);
    exit;
}
$hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('G');
$hours = Settings::getString('ai_teach_hours', '2-5');
if (!$now && !ApLearn::inHours($hours, $hour)) {
    cron_done(['skipped' => 'outside ai_teach_hours (' . $hours . ' IST, now ' . $hour . ')']);
    exit;
}
$today = date('Y-m-d');
$day = (string) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'ai_teach' AND kkey = 'day'", [], '');
if (!$now && $day === $today) {
    cron_done(['skipped' => 'already taught today']);
    exit;
}

$lock = @fopen(LOG_PATH . '/ai-teach.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    cron_done(['skipped' => 'another teaching run is going']);
    exit;
}
@set_time_limit(1800);
try {
    if (!$dry) {
        Database::run(
            "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('ai_teach', 'day', :d, 'ai')
             ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = 'ai'",
            ['d' => $today]
        );
    }
    $r = AiTeach::run($usd, $dry);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
Logger::info('AI teach run', $r, 'ai');
cron_done($r, $r['ok']);
