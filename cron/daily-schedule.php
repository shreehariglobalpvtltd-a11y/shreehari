<?php
/**
 * cron/daily-schedule.php — nightly "make sure the next 30 days of
 * schedules exist" job.
 *
 * Every daily-service route (is_active = 1 AND dep_time IS NOT NULL)
 * gets one `schedules` row per travel_date across a rolling 30-day
 * window. UNIQUE(route_id, travel_date) means a re-run is a no-op —
 * a re-run at 00:31 after the 00:30 tick prints "created 0 (skipped
 * 30 existing)" and touches nothing.
 *
 * Without this job the seat map for tomorrow silently returns "no bus"
 * on any date that nobody has hand-inserted a schedule for, which is
 * the quietest way to lose sales.
 *
 * Recommended cadence:
 *   cPanel cron:  30 0 * * * /usr/local/bin/php /home/USER/public_html/cron/daily-schedule.php
 *   crontab -e :  30 0 * * * /usr/local/bin/php /home/USER/public_html/cron/daily-schedule.php
 *   or by URL  :  https://yourdomain.com/cron/daily-schedule.php?token=YOUR_CRON_TOKEN
 *
 * The window is +0..+29 days; the +0 keeps today intact when the cron
 * runs shortly after midnight on a fresh install (rolling out to a new
 * VPS where nothing has been seeded yet).
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/fleet.php';
require_once INCLUDE_PATH . '/schedulemaker.php';

try {
    $result = ScheduleMaker::ensureNextDays(Database::pdo(), 30, 0);
} catch (Throwable $e) {
    // A missing routes/schedules table (fresh install where the schema
    // has not been imported yet) must not leave a red cron every night.
    Logger::error('daily-schedule cron failed', ['err' => $e->getMessage()], 'cron');
    echo '[daily-schedule] created 0 (skipped 0 existing) across 0 routes (error: '
        . $e->getMessage() . ")\n";
    cron_done(['created' => 0, 'skipped_existing' => 0, 'routes' => [], 'error' => $e->getMessage()]);
    exit(0);
}

/* Heartbeat for the admin (4 Sep 2026): the Bus Calendar shows how far ahead
   the automatic daily bus is created and when this job last ran, so "one bus
   every day, no manual action" is something the office can SEE rather than
   assume. Never fatal — a settings write must not fail the seed. */
try {
    Settings::set('daily_schedule_last_run', date('c'), 'string', 'booking', false);
} catch (Throwable $e) {
    Logger::warning('daily-schedule heartbeat not written', ['err' => $e->getMessage()], 'cron');
}

$routeList = $result['routes'];
echo '[daily-schedule] created ' . $result['created']
    . ' (skipped ' . $result['skipped_existing'] . ' existing) across '
    . count($routeList) . ' routes'
    . ($routeList === [] ? '' : ' [' . implode(', ', $routeList) . ']')
    . ' — window ' . $result['from'] . ' .. ' . $result['to']
    . "\n";

cron_done($result);
exit(0);
