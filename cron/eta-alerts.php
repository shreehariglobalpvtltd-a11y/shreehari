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
 *
 * 15 Nov 2026 (P8): after that run, the 15 / 5 minute WEB PUSH steps
 * (EtaAlerts::runSteps) - their own switch, settings.eta_push_steps_on
 * (default off), steps settings.eta_push_steps ('15,5'). With both
 * switches off the run's output is exactly what it was before.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/etaalerts.php';

$out = EtaAlerts::enabled() ? EtaAlerts::run() : ['skipped' => 'eta_alert_on = 0'];

if (EtaAlerts::stepsEnabled()) {
    try {
        $out['steps'] = EtaAlerts::runSteps();
    } catch (Throwable $e) {
        // the 30-minute run above already finished; a step failure is logged, never fatal
        $out['steps'] = ['error' => mb_substr($e->getMessage(), 0, 200)];
    }
}

cron_done($out);
