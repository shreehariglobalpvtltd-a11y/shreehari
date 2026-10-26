<?php
/**
 * cron/helpdesk.php — the Help Desk's clock (5 Oct 2026).
 *
 * Every 5 minutes: a ticket nobody answered before its due time is
 * escalated ONCE (priority +1, new due time, office WhatsApp alert), and a
 * resolved ticket nobody re-opened closes itself after helpdesk_autoclose_days.
 * includes/helpdesk.php has the rules.
 *
 *   crontab (www-data, so the day's log stays writable):
 *     2-59/5 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/helpdesk.php
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/helpdesk.php';

if (!HelpDesk::ready()) {
    cron_done(['skipped' => 'help desk columns not installed']);
    return;
}
cron_done(HelpDesk::tick());
