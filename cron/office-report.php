<?php
/**
 * =====================================================================
 *  cron/office-report.php — the office's WhatsApp report at set times.
 *
 *      every 15 minutes: php cron/office-report.php   (crontab, live)
 *      php cron/office-report.php --dry                 print it, send nothing
 *      php cron/office-report.php --force               send now, whatever the clock
 *
 *  Sends only while office_report_on = 1, once per slot in
 *  office_report_times (IST, e.g. "07:00,13:00,20:00"), and only within
 *  OfficeReport::GRACE_MIN of the slot — a cron that was down at 07:00
 *  does not post "morning" at noon. The slot is recorded in
 *  office_report_last BEFORE sending: a half-failed send is never
 *  repeated to the numbers that did get it (at most once beats twice).
 *  See includes/officereport.php for what the message carries.
 * =====================================================================
 */
declare(strict_types=1);

require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/officereport.php';

$args  = $argv ?? [];
$dry   = in_array('--dry', $args, true);
$force = in_array('--force', $args, true);
$now   = date('Y-m-d H:i');

if ($dry || $force) {
    $res = OfficeReport::send($now, $dry);
    if ($dry) {
        echo ($res['text'] ?? '') . "\n\n";
        unset($res['text']);
    }
    cron_done($res);
    exit(0);
}

if (!Settings::getBool('office_report_on', false)) {
    cron_done(['skipped' => 'office_report_on is off']);
    exit(0);
}

$slot = OfficeReport::dueSlot(
    Settings::getString('office_report_times', '07:00,13:00,20:00'),
    $now,
    Settings::getString('office_report_last', '')
);
if ($slot === null) {
    cron_done(['skipped' => 'no slot due at ' . $now]);
    exit(0);
}

Settings::set('office_report_last', $slot, 'string', 'reports');
cron_done(OfficeReport::send($slot));
