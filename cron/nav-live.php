<?php
/**
 * cron/nav-live.php - SHARI NAV live (Phase 2, 22 Oct 2026). Every minute:
 *
 *   * the S Hari coaches on the road are pushed on channel "bus" every
 *     5 s - only while some phone listens (the hub writes its listener
 *     counts to /run/shg-live/stats.json)
 *   * at minute 17 of every hour: shared trips and expired road reports
 *     older than 24 h are deleted (NavLive::purge)
 *
 * crontab (root):  * * * * * /usr/bin/php …/cron/nav-live.php >> …/logs/nav-live.log 2>&1
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/navlive.php';

$lock = fopen(sys_get_temp_dir() . '/shg-navlive-cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    cron_done(['skipped' => 'previous run still going']);
    return;
}

$out = ['pushed' => 0];
if ((int) date('i') === 17) {
    $out['purged'] = NavLive::purge();
}
$t0 = time();
for ($i = 0; $i < 12; $i++) {
    $st = json_decode((string) @file_get_contents('/run/shg-live/stats.json'), true);
    $fresh = is_array($st) && time() - (int) ($st['t'] ?? 0) < 30;
    if ($fresh && (int) ($st['subs']['bus'] ?? 0) > 0) {
        @unlink(sys_get_temp_dir() . '/shg-navlive-bus-v3.json');      /* a fresh snapshot, not the 12 s cache */
        NavLive::publish('bus', NavLive::buses());
        $out['pushed']++;
    }
    $next = $t0 + ($i + 1) * 5;
    if ($i < 11 && $next > time()) {
        sleep($next - time());
    }
}
flock($lock, LOCK_UN);
cron_done($out);
