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

/* 19 Nov 2026 (u10, WA-6 + WA-5): two more jobs ride this every-15-minutes line - no
   crontab change:
     · the COUNTER-WISE day report (OfficeReport::sendCounterDay) once a day at
       counter_day_report_time (default 21:30 IST) while counter_day_report_on
       (default 1); slot remembered in counter_day_report_last BEFORE sending;
     · the DRIVER passenger list (DriverList::run) on every run while
       driver_list_on (default 1).
       php cron/office-report.php --day=2026-11-19 --dry   print that day's counter report, send nothing
       php cron/office-report.php --day=2026-11-19         send it now (whatever the clock)
       php cron/office-report.php --driver-dry             print which driver lists would go now */
require_once INCLUDE_PATH . '/driverlist.php';

$args  = $argv ?? [];
$dry   = in_array('--dry', $args, true);
$force = in_array('--force', $args, true);
$now   = date('Y-m-d H:i');
$dayArg = '';
foreach ($args as $a) {
    if (preg_match('/^--day=(\d{4}-\d{2}-\d{2})$/', (string) $a, $m) === 1) { $dayArg = $m[1]; }
}

if ($dayArg !== '') {
    $res = OfficeReport::sendCounterDay($dayArg . ' ' . date('H:i'), $dry, $dayArg);
    if ($dry) {
        echo ($res['text'] ?? '') . "\n\n";
        unset($res['text']);
    }
    cron_done($res);
    exit(0);
}
if (in_array('--driver-dry', $args, true)) {
    $res = DriverList::run(true);
    foreach ((array) ($res['would'] ?? []) as $w) { echo "--- sid " . $w['sid'] . ' ' . $w['kind'] . "\n" . $w['text'] . "\n\n"; }
    unset($res['would']);
    cron_done($res);
    exit(0);
}

if ($dry || $force) {
    $res = OfficeReport::send($now, $dry);
    if ($dry) {
        echo ($res['text'] ?? '') . "\n\n";
        unset($res['text']);
    }
    cron_done($res);
    exit(0);
}

$out = [];
if (!Settings::getBool('office_report_on', false)) {
    $out['office'] = ['skipped' => 'office_report_on is off'];
} else {
    $slot = OfficeReport::dueSlot(
        Settings::getString('office_report_times', '07:00,13:00,20:00'),
        $now,
        Settings::getString('office_report_last', '')
    );
    if ($slot === null) {
        $out['office'] = ['skipped' => 'no slot due at ' . $now];
    } else {
        Settings::set('office_report_last', $slot, 'string', 'reports');
        $out['office'] = OfficeReport::send($slot);
    }
}

if (Settings::getBool('counter_day_report_on', true)) {
    try {
        $cSlot = OfficeReport::dueSlot(
            Settings::getString('counter_day_report_time', '21:30'),
            $now,
            Settings::getString('counter_day_report_last', '')
        );
        if ($cSlot !== null) {
            Settings::set('counter_day_report_last', $cSlot, 'string', 'reports');
            $out['counter_day'] = OfficeReport::sendCounterDay($cSlot);
        }
    } catch (Throwable $e) {
        $out['counter_day'] = ['error' => $e->getMessage()];
    }
}

if (DriverList::on()) {
    try {
        $out['driver_list'] = DriverList::run();
    } catch (Throwable $e) {
        $out['driver_list'] = ['error' => $e->getMessage()];
    }
}
cron_done($out);
