<?php
/**
 * cron/wa-habits.php — the daily answers people said "ho" to (9 Oct 2026,
 * includes/wahabit.php). Every 15 minutes:
 *   *\/15 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/wa-habits.php >> .../logs/wa-habits.log 2>&1
 * Sends only FREE (Meta number, open window); a closed window is skipped.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/wahabit.php';
if (!WaHabit::ready()) {
    cron_done(['skipped' => 'wa_habits table missing or wa_habits_on off']);
    exit;
}
cron_done(WaHabit::run());
