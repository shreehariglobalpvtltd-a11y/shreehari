<?php
/**
 * cron/ai-teach.php — Claude writes the next knowledge articles for the
 * free local bot (1 Nov 2026). includes/aiteach.php does the work.
 *
 *     40 * * * * php /path/to/public_html/cron/ai-teach.php >/dev/null 2>&1
 *
 *   - runs only inside ai_teach_hours (default "1-6" IST), at most
 *     ai_teach_runs_per_day (4) a day (kv ai_teach:day = "date:n"), at most
 *     ai_teach_run_usd (3) per run and ai_teach_budget_usd (95) in all —
 *     owner: "teach until the Claude credit is gone" — the spend is priced from the
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
$hours = Settings::getString('ai_teach_hours', '1-6');
if (!$now && !ApLearn::inHours($hours, $hour)) {
    cron_done(['skipped' => 'outside ai_teach_hours (' . $hours . ' IST, now ' . $hour . ')']);
    exit;
}
$today = date('Y-m-d');
[$dayDate, $dayRuns] = array_pad(explode(':', (string) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'ai_teach' AND kkey = 'day'", [], '')), 2, '0');
$runsToday = $dayDate === $today ? (int) $dayRuns : 0;
if (!$now && $runsToday >= max(1, Settings::getInt('ai_teach_runs_per_day', 4))) {
    cron_done(['skipped' => 'taught ' . $runsToday . ' times today already']);
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
            ['d' => $today . ':' . ($runsToday + 1)]
        );
    }
    $r = AiTeach::run($usd, $dry);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
Logger::info('AI teach run', $r, 'ai');
cron_done($r, $r['ok']);
