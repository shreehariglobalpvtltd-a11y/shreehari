<?php
/**
 * cron/eta-alerts.php — tell waiting passengers when the coach is close.
 * (includes/etaalerts.php has the rules and the reasoning.)
 *
 * Recommended: every 3 minutes. A 30-minute warning read every 3 minutes
 * lands between 27 and 30 minutes out, and an idle run is one indexed read.
 *   crontab:   every 3 minutes (minute field = star-slash-3; not written literally here
 *              because that pair of characters would close this comment)
 *              /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/eta-alerts.php
 *   or by URL: https://www.shreehariglobal.in/cron/eta-alerts.php?token=CRON_TOKEN
 *
 * Switch: settings.eta_alert_on (default off). With no driver publishing a
 * position it does nothing at all.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/etaalerts.php';

if (!EtaAlerts::enabled()) {
    cron_done(['skipped' => 'eta_alert_on = 0']);
    return;
}

cron_done(EtaAlerts::run());
