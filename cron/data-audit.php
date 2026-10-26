<?php
/**
 * cron/data-audit.php — re-read the register every night and name what does
 * not add up (includes/dataaudit.php has the checks and the reasoning).
 *
 * Recommended: nightly, after backup.php.
 *   crontab:   40 3 * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/data-audit.php
 *   or by URL: https://www.shreehariglobal.in/cron/data-audit.php?token=CRON_TOKEN
 *
 * By hand, without writing a single incident (prints what it WOULD raise):
 *   php cron/data-audit.php --dry
 *
 * OBSERVE ONLY. SELECTs, then health_incidents. It never repairs a booking,
 * never releases a seat, never sends a message.
 *
 * Switch: settings.data_audit_on (default on). Window: data_audit_days_back
 * (default 30) for the money / wallet checks; seat checks look at upcoming
 * travel only, because a wrong seat on a bus that has already arrived can no
 * longer hurt anyone and would raise the same card for ever.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/dataaudit.php';

$dry = PHP_SAPI === 'cli' && in_array('--dry', $argv ?? [], true);

if (!$dry && !Settings::getBool('data_audit_on', true)) {
    cron_done(['skipped' => 'data_audit_on = 0']);
    return;
}

if ($dry) {
    $findings = DataAudit::run();
    foreach ($findings as $f) {
        echo strtoupper($f['severity']) . '  ' . $f['key'] . '  ' . $f['title'] . "\n";
        if ($f['ids'] !== []) {
            echo '      booking ids: ' . implode(', ', $f['ids']) . "\n";
        }
    }
    echo count($findings) === 0 ? "clean - every check passed\n" : count($findings) . " finding(s) (dry run, nothing written)\n";
    return;
}

$result = DataAudit::runAndReport();

/* ok = the job did its job. Findings are not a job failure — they are the
   job working — so the heartbeat stays green and the cards carry the news. */
cron_done($result);
