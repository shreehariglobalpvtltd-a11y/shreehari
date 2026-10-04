<?php
/**
 * cron/wa-ticket-queue.php — the WhatsApp "hi" ticket queue, every 5 minutes
 * (4 Oct 2026, see includes/waqueue.php).
 *
 *   crontab:  *\/5 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/wa-ticket-queue.php
 *
 * 1. Asks AiSensy what really happened to each ticket it accepted in the last
 *    3 days (accepted is not delivered): DELIVERED / READ are marked; a
 *    "no 24 h window" failure goes back to waiting for that number's "hi";
 * 2. queues (and sends, if their window is open) the copy for the counter /
 *    agent who sold a ticket the customer did not get;
 * 3. expires waits older than 7 days or for a bus that has already gone.
 *
 * Off switch: Settings wa_hi_queue_on. Sends nothing a person has not been
 * waiting for - every send is a ticket already queued for that number.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waqueue.php';

if (!WaQueue::ready()) {
    cron_done(['skipped' => 'queue table missing or wa_hi_queue_on off']);
    exit;
}
cron_done(WaQueue::checkStatuses(30));
