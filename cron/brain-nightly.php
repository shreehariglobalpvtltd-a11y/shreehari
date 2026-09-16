<?php
/**
 * cron/brain-nightly.php — QuickBot's night shift (recommended: 02:00).
 *
 * While the office sleeps: read the year of verified sales, work out who is
 * likely to ring in the coming week, and leave a ranked, pre-filled card for
 * each of them. Then look at the week ahead and raise the standing alerts —
 * a bus filling too fast, a demand echo from last year, a number that keeps
 * cancelling, tomorrow's departures.
 *
 * Nothing here sells, holds a seat, prices anything or messages a passenger.
 * Every row it writes is advisory: cron/brain-digest.php reads them out in
 * the morning and admin/quick-ticket.php shows them on the desk.
 *
 * Exactly-once per day via an automation_log claim, same as the other jobs,
 * so a double cron entry (or a manual re-run alongside the scheduled one)
 * cannot double-write. Pass ?force=1 (or --force on the CLI) to re-think a
 * day you have already claimed — useful right after changing a setting.
 *
 *   crontab:  0 2 * * *  curl -s "https://shreehariglobal.in/cron/brain-nightly.php?token=CRON_TOKEN"
 *   CLI:      php cron/brain-nightly.php [--force]
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/ticketbrain.php';

// Long-running safety: read toggles fresh, not from a stale request cache.
Settings::flush();

$today = date('Y-m-d');
$force = PHP_SAPI === 'cli'
    ? in_array('--force', $argv ?? [], true)
    : (string) ($_GET['force'] ?? '') === '1';

if (!TicketBrain::enabled()) {
    cron_done(['skipped' => 'brain_on is off']);
    exit(0);
}

// The migration this job depends on. Missing table → friendly hint and a
// GREEN exit: a red cron panel every night helps nobody (same contract as
// cron/daily-summary.php with automation_log).
if (!TicketBrain::ready()) {
    cron_done([
        'skipped' => 'brain tables missing',
        'hint'    => 'Run database/upgrade-2026-09-quickbot-brain.sql, then this cron works.',
    ]);
    exit(0);
}

if (!$force && !EventBus::claim('brain-nightly:' . $today, 'cron.brain_nightly')) {
    cron_done(['skipped' => 'already ran for ' . $today]);
    exit(0);
}

$out = ['date' => $today];

try {
    $out['think'] = TicketBrain::think($today);
} catch (Throwable $e) {
    Logger::exception($e);
    $out['think'] = ['error' => $e->getMessage()];
}

try {
    $alerts = TicketBrain::alerts($today);
    $out['alerts'] = count($alerts);
    $byKind = [];
    foreach ($alerts as $a) {
        $byKind[(string) $a['kind']] = ($byKind[(string) $a['kind']] ?? 0) + 1;
    }
    $out['alertKinds'] = $byKind;
} catch (Throwable $e) {
    Logger::exception($e);
    $out['alerts'] = ['error' => $e->getMessage()];
}

// How the guessing has been going lately — the one number worth watching.
try {
    $out['accuracy'] = TicketBrain::accuracy(30);
} catch (Throwable $e) {
    $out['accuracy'] = ['error' => $e->getMessage()];
}

cron_done($out);
