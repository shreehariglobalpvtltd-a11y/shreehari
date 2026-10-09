<?php
/**
 * cron/staff-msg.php — Office chat's clock (9 Oct 2026).
 *
 * Every 5 minutes (includes/staffmsg.php StaffMsg::tick() has the rules):
 *   · a conversation quiet for staffmsg_autoclose_days closes itself (a new
 *     message reopens it; nothing is deleted);
 *   · ONLY when the office switched staffmsg_wa_nudge_on on: an office message
 *     the agent has not read for staffmsg_wa_nudge_min minutes gets ONE
 *     WhatsApp template nudge (approved AiSensy template, no ticket details,
 *     once per message via EventBus::claim). Off by default — the panel badge
 *     and phone push are the delivery path, never WhatsApp.
 * It never touches a booking, a refund or a cancellation.
 *
 *   crontab (www-data, so the day's log stays writable):
 *     3-59/5 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/staff-msg.php
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/staffmsg.php';

if (!StaffMsg::tablesReady()) {
    cron_done(['skipped' => 'office chat tables not installed']);
    return;
}
try {
    cron_done(StaffMsg::tick());
} catch (Throwable $e) {
    Logger::error('cron staff-msg failed', ['err' => $e->getMessage()]);
    cron_done(['error' => mb_substr($e->getMessage(), 0, 200)], false);
}
