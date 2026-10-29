<?php
/**
 * cron/wa-onboard.php — a new agent's 7 daily WhatsApp lessons (29 Oct 2026,
 * includes/waonboard.php). Hourly in the daytime:
 *   0 9-20 * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/wa-onboard.php >> /var/log/shg-wa-onboard.log 2>&1
 * Sends only FREE (Meta number, open window); a closed window waits for the next run.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waonboard.php';
if (!WaOnboard::on()) {
    cron_done(['skipped' => 'wa_onboard_on off']);
    exit;
}
cron_done(WaOnboard::run());
