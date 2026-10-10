<?php
/**
 * cron/wa-agent-route.php — the 5-minute agent timer (10 Oct 2026,
 * includes/waagentroute.php). Every minute:
 *   * * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/wa-agent-route.php >> .../logs/wa-agent-route.log 2>&1
 * Tickets the passenger did not get go to the route agent; an agent who
 * does not answer in time is marked busy and the next one is asked; when
 * nobody is left the admin is told. Free WhatsApp only.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waagentroute.php';
if (!WaAgentRoute::on()) {
    cron_done(['skipped' => 'delivery_log missing or wa_route_on off']);
    exit;
}
cron_done(WaAgentRoute::tick());
