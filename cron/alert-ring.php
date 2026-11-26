<?php
/**
 * cron/alert-ring.php — the call-style ring's heartbeat (26 Nov 2026, fx1).
 * Recommended: every minute.
 *
 *   · reads new rows of the event journal into alert_events (AlertBus::sweep)
 *     - so a ticket that arrives while NO panel is open still becomes a ring
 *   · sends the web push that is due to the phones that tapped "Ring on this
 *     phone": the first ring, then again every alert_ring_repeat_min minutes
 *     up to alert_ring_repeat_max times, until someone taps Seen
 *   · agents at their credit warning line: one alert per agent per day
 *   · drops this feature's own rows older than 30 days
 *
 * Switches are checked BEFORE any claim (alert_bus_on, alert_push_on,
 * alert_wa_on) - a claim taken while a channel is off would eat that alert.
 * Without this cron the ring still works while some panel is open (the
 * panel's poll does the same work); the cron covers "everybody is away".
 *
 *   crontab (every minute):
 *     * * * * * php /path/to/cron/alert-ring.php
 *   or by URL:
 *     curl -s "https://shreehariglobal.network/cron/alert-ring.php?token=CRON_TOKEN"
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/alertbus.php';

Settings::flush();

if (!AlertBus::on()) {
    cron_done(['skipped' => AlertBus::ready() ? 'alert_bus_on is off' : 'tables missing - run database/upgrade-2026-11-26-fx1-alertbus.sql']);
    exit(0);
}

$out = [];
try {
    $out['sweep']   = AlertBus::sweep();
    $out['deliver'] = AlertBus::deliver(40);
    $out['credit']  = AlertBus::creditWatch();
    /* housekeeping once an hour is plenty */
    if ((int) date('i') === 7) {
        $out['pruned'] = AlertBus::prune(30);
    }
    cron_done($out);
} catch (Throwable $e) {
    Logger::exception($e);
    cron_done($out + ['error' => $e->getMessage()], false);
}
