<?php
/**
 * cron/stop-timing.php — learn real stop times from the GPS trails of the
 * trips that ended (19 Nov 2026, unit u8 / AI-4). includes/stoptiming.php
 * does the work; it writes ONLY trip_stop_times and route_stop_learned.
 *
 *     10 1 * * * /usr/bin/php /path/to/public_html/cron/stop-timing.php >/dev/null 2>&1
 *
 *   --trip=ID   read one live trip now (and learn its route)
 *   --route=ID  re-learn one route from what is stored
 *   --days=N    look back N days for ended trips (default 3)
 *
 * Switch: stop_learn_on (default 1). CLI only; one run at a time.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/stoptiming.php';

if (PHP_SAPI !== 'cli') {
    cron_done(['skipped' => 'cli only']);
    exit;
}
Settings::flush();
if (!StopTiming::enabled()) {
    cron_done(['skipped' => 'stop_learn_on is off']);
    exit;
}

$trip = 0; $route = 0; $days = 3;
foreach (array_slice($argv ?? [], 1) as $a) {
    if (preg_match('/^--trip=(\d+)$/', $a, $m) === 1) { $trip = (int) $m[1]; }
    if (preg_match('/^--route=(\d+)$/', $a, $m) === 1) { $route = (int) $m[1]; }
    if (preg_match('/^--days=(\d+)$/', $a, $m) === 1) { $days = max(1, min(30, (int) $m[1])); }
}

$lock = @fopen(LOG_PATH . '/stop-timing.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    cron_done(['skipped' => 'another run is going']);
    exit;
}
try {
    if ($trip > 0) {
        $r = StopTiming::recordTrip($trip);
        $rid = (int) Database::scalar('SELECT route_id FROM live_trips WHERE id = :t', ['t' => $trip], 0);
        $r['learned'] = $rid > 0 ? StopTiming::learn($rid) : 0;
    } elseif ($route > 0) {
        $r = ['route' => $route, 'learned' => StopTiming::learn($route)];
    } else {
        $r = StopTiming::nightly($days);
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
cron_done($r);
