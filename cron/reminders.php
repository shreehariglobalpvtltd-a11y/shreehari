<?php
/**
 * cron/reminders.php — automatic departure reminders.
 *
 * Sends the 12-hours-before and 2-hours-before messages to every
 * confirmed passenger, over WhatsApp and SMS. Nobody in the office
 * touches anything: this is V6's "12 Hours Before Departure → Send
 * Reminder Automatically" requirement.
 *
 * Recommended: every 15 minutes (every 5 is also fine — the exactly-once
 * claim in trip_events means a passenger can never be messaged twice).
 *
 *   cPanel cron:  /usr/local/bin/php /home/USER/public_html/cron/reminders.php
 *   or by URL:    https://yourdomain.com/cron/reminders.php?token=YOUR_CRON_TOKEN
 *
 * Requires database/upgrade-2026-08-trip-lifecycle.sql to have been run.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/tripnotify.php';

try {
    $result = TripNotify::runReminders();
} catch (Throwable $e) {
    // A missing trip_events table (migration not yet run on live) must not
    // leave a red cron in the panel every quarter hour — say so plainly.
    Logger::error('reminders cron failed', ['err' => $e->getMessage()], 'cron');
    cron_done(['error' => $e->getMessage(),
               'hint'  => 'Run database/upgrade-2026-08-trip-lifecycle.sql once.']);
    exit(0);
}

cron_done($result);
