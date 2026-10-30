<?php
/**
 * cron/agentpro-practice.php — the VPS model practises on the WhatsApp
 * desk's examples while nobody needs it (31 Oct 2026, idc-learn).
 *
 *     15 * * * * php /path/to/public_html/cron/agentpro-practice.php >/dev/null 2>&1
 *
 * Owner: "VPS ko model le herera practice garos, training lidai fast hudai
 * jaos." Honest scope: the model re-reads examples the desk was CONFIRMED on
 * (ApLearn::practice) and the result is measured — accuracy %, seconds per
 * message — in ap_ai_practice; the ones it gets wrong are shown first in its
 * later prompts. Its weights are not re-trained on this server.
 *
 *   - runs only inside ap_practice_hours (default "1-5" = 01:00-05:59 IST;
 *     "23-4" and "1-5,14" work too), switch ap_practice_on (default on),
 *     at most ap_practice_seconds (default 900) per run
 *   - quietly skips when the model server is not configured / not answering,
 *     or ap_ai_vps is switched off
 *   - one run at a time (LOG_PATH/agentpro-practice.lock); every model call
 *     gives way to WhatsApp (a document-class call) and the run stops there
 *   - CLI only
 *
 *   --now           ignore the hours and the switch (admin "Practice now")
 *   --seconds=60    this run's budget (10-900)
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-ai.php';
require_once INCLUDE_PATH . '/agentpro-brain.php';
require_once INCLUDE_PATH . '/agentpro-learn.php';

if (PHP_SAPI !== 'cli') {
    cron_done(['skipped' => 'cli only']);
    exit;
}

$args = array_slice($argv ?? [], 1);
$now = in_array('--now', $args, true);
$seconds = max(10, min(3600, Settings::getInt('ap_practice_seconds', 900)));
foreach ($args as $a) {
    if (preg_match('/^--seconds=(\d{1,4})$/', $a, $m) === 1) {
        $seconds = max(10, min(900, (int) $m[1]));
    }
}

if (!AgentPro::available() || !ApLearn::ready()) {
    cron_done(['skipped' => 'learning tables missing (database/upgrade-2026-10-31-idc-learn.sql)']);
    exit;
}
if (!$now && !Settings::getBool('ap_practice_on', true)) {
    cron_done(['skipped' => 'ap_practice_on is off']);
    exit;
}
$hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('G');
$hours = Settings::getString('ap_practice_hours', '1-5');
if (!$now && !ApLearn::inHours($hours, $hour)) {
    cron_done(['skipped' => 'outside ap_practice_hours (' . $hours . ' IST, now ' . $hour . ')']);
    exit;
}
if (!Settings::getBool('ap_ai_vps', true) || ApAi::base() === '' || !ApAi::available(true)) {
    cron_done(['skipped' => 'model server not configured or not answering']);
    exit;
}

$lock = @fopen(LOG_PATH . '/agentpro-practice.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    cron_done(['skipped' => 'another practice run is going']);
    exit;
}
@set_time_limit($seconds + 180);
try {
    $r = ApLearn::practice($seconds);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
if ($r['ok']) {
    Logger::info('AI practice run', ['model' => $r['model'], 'n' => $r['n'], 'correct' => $r['correct'], 'avg_ms' => $r['avg_ms']], 'agent');
}
cron_done($r['ok']
    ? ['model' => $r['model'], 'n' => $r['n'], 'correct' => $r['correct'], 'pct' => $r['pct'], 'avg_ms' => $r['avg_ms'], 'p50_ms' => $r['p50_ms'], 'notes' => $r['notes']]
    : ['skipped' => $r['skipped']]);
