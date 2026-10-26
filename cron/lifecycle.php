<?php
/**
 * cron/lifecycle.php — time-based trip lifecycle promotion.
 *
 * Any 'scheduled' trip whose departure time is at least a few minutes in
 * the past is promoted to 'departed' — as if a staff member had pressed
 * "Bus started" on Admin -> Trips. TripNotify::markTrip() does the work,
 * so the same messaging + audit trail fires, and its exactly-once claims
 * mean a cron racing a real operator click cannot double-message anyone.
 *
 * Recommended: every 5 minutes.
 *   cPanel cron:  /usr/local/bin/php /home/USER/public_html/cron/lifecycle.php
 *   crontab -e :  every 5 min -> "* /5 * * * * /usr/local/bin/php /home/USER/public_html/cron/lifecycle.php" (remove the space after the first *)
 *   or by URL  :  https://yourdomain.com/cron/lifecycle.php?token=YOUR_CRON_TOKEN
 *
 * Requires database/upgrade-2026-08-trip-lifecycle.sql to have been run
 * (same migration cron/reminders.php depends on).
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/tripnotify.php';

$promoted = 0;
try {
    $promoted = TripNotify::promoteDepartedByTime(5);
} catch (Throwable $e) {
    // A missing trip_status / trip_events table (migration not yet run
    // on live) must not leave a red cron in the panel every 5 minutes.
    Logger::error('lifecycle cron failed', ['err' => $e->getMessage()], 'cron');
    echo '[lifecycle] promoted 0 schedules to departed (error: ' . $e->getMessage() . ")\n";
    cron_done(['promoted' => 0, 'error' => $e->getMessage(),
               'hint'     => 'Run database/upgrade-2026-08-trip-lifecycle.sql once.']);
    exit(0);
}

/* Second, optional hop: departed -> arrived, N hours after the scheduled
   departure. OFF unless the owner sets auto_arrive_hours, because 'arrived'
   WhatsApps every passenger on the trip. Kept in the same cron as the
   departed promotion so one place moves a trip through its lifecycle
   without a human. */
$arrived   = 0;
$arriveHrs = 0;
try {
    $arriveHrs = Settings::getInt('auto_arrive_hours', 0);
    if ($arriveHrs > 0) {
        $arrived = TripNotify::promoteArrivedByTime($arriveHrs);
    }
} catch (Throwable $e) {
    Logger::error('lifecycle arrive step failed', ['err' => $e->getMessage()], 'cron');
}

echo '[lifecycle] promoted ' . $promoted . ' schedules to departed'
   . ($arriveHrs > 0 ? ', ' . $arrived . ' to arrived (after ' . $arriveHrs . 'h)' : ', auto-arrive off')
   . "
";
cron_done(['promoted' => $promoted, 'arrived' => $arrived, 'auto_arrive_hours' => $arriveHrs]);
exit(0);
