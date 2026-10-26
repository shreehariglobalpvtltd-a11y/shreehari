<?php
/**
 * upgrade-2026-09-quickbot-brain.php — QuickBot Passive Brain settings
 * (owner ask, 7 Sep 2026).
 *
 * Seeds the rows the brain reads, so the office can tune it from
 * Admin → Settings (Settings::setMany() only updates rows that exist):
 *
 *   brain_on             bool  1 = the night shift thinks and the desk shows
 *                              the Ready Queue; 0 = QuickBot stays reactive
 *   brain_history_days   int   how far back the nightly scan reads (365)
 *   brain_horizon_days   int   how many days ahead it predicts (7)
 *   brain_queue_size     int   how many cards a day may hold (12)
 *   brain_min_score      int   0-1000; below this a guess is not shown (250)
 *   brain_carry_days     int   how long an unused card stays on the desk (2)
 *   brain_fill_alert_pct int   raise a "filling fast" alert at this % (80)
 *   brain_cancel_flag    int   flag a number after this many cancellations (3)
 *   brain_revenue_target float today's revenue target; 0 = no tracker
 *   brain_digest_on      bool  1 = send the 06:00 morning brief
 *   brain_festivals      json  named festival windows, e.g.
 *                              [{"name":"Dashain","from":"2026-10-11","to":"2026-10-22"}]
 *
 * brain_festivals ships EMPTY on purpose. A festival date hard-coded in
 * code is wrong the following year; the brain already finds the same rush
 * from the company's own sales a year earlier ("demand echo"), and the
 * office can name a window here whenever it wants the alert to say so.
 *
 * Idempotent: existing rows are kept exactly as they are.
 *
 *   php -c .claude/php-dev.ini database/upgrade-2026-09-quickbot-brain.php   (local)
 *   php database/upgrade-2026-09-quickbot-brain.php                            (VPS)
 *
 * Run database/upgrade-2026-09-quickbot-brain.sql first — that is the one
 * that creates the tables.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$rows = [
    // key, default, type, group, public, label
    ['brain_on',             '1',    'bool',   'booking', false, 'QuickBot Passive Brain ON (1) / OFF (0) — nightly predictions + Ready Queue'],
    ['brain_history_days',   '365',  'int',    'booking', false, 'Passive Brain: read this many days of verified sales (365)'],
    ['brain_horizon_days',   '7',    'int',    'booking', false, 'Passive Brain: predict this many days ahead (7)'],
    ['brain_queue_size',     '12',   'int',    'booking', false, 'Passive Brain: maximum ready cards per day (12)'],
    ['brain_min_score',      '250',  'int',    'booking', false, 'Passive Brain: minimum confidence 0-1000 to show a card (250)'],
    ['brain_carry_days',     '2',    'int',    'booking', false, 'Passive Brain: keep an unused card on the desk for this many days (2)'],
    ['brain_fill_alert_pct', '80',   'int',    'booking', false, 'Passive Brain: alert when a bus passes this % full (80)'],
    ['brain_cancel_flag',    '3',    'int',    'booking', false, 'Passive Brain: flag a number after this many cancellations (3)'],
    ['brain_revenue_target', '0',    'float',  'booking', false, 'Passive Brain: daily revenue target for the tracker (0 = off)'],
    ['brain_digest_on',      '1',    'bool',   'booking', false, 'Passive Brain: send the 06:00 morning brief ON (1) / OFF (0)'],
    ['brain_festivals',      '[]',   'json',   'booking', false, 'Passive Brain: named festival windows [{"name","from","to"}] (blank = learn from last year)'],
];

foreach ($rows as [$key, $default, $type, $group, $public, $label]) {
    if (Settings::get($key, null) === null
        && !Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $key])) {
        Settings::set($key, $default, $type, $group, $public);
        echo "seeded {$key} = " . var_export($default, true) . "\n";
    } else {
        echo "{$key} already set\n";
    }
    Database::query(
        'UPDATE settings SET label = :l WHERE skey = :k AND (label IS NULL OR label = \'\')',
        ['l' => $label, 'k' => $key]
    );
}
Settings::flush();
exit(0);
