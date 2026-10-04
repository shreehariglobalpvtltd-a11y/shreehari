<?php
/**
 * cron/campaigns.php — the campaign worker (5 Oct 2026).
 *
 * Every minute: starts scheduled campaigns whose time has come, sends the
 * next rate_per_min messages of each running automatic campaign (WhatsApp
 * template / SMS) outside quiet hours and under the daily cap, and reads
 * delivered / read back from AiSensy. includes/campaigns.php has the rules.
 *
 *   crontab (www-data):
 *     * * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/campaigns.php
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/campaigns.php';

if (!Campaigns::ready()) {
    cron_done(['skipped' => 'campaign tables not installed']);
    return;
}
$r = Campaigns::work();
cron_done($r, !isset($r['error']));
