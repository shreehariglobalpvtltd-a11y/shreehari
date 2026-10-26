<?php
/**
 * upgrade-2026-09-daily-service.php — the master ON / OFF switch for the
 * one daily bus (owner ask, 4 Sep 2026: "app ma euta matra, tyo pani off
 * garna milos").
 *
 *   daily_service_on   bool   1 = the daily service sells (default)
 *                             0 = paused: search returns no bus, the app shows
 *                                 "service paused", new bookings are refused
 *   daily_service_note string optional message shown while paused
 *
 * Both are public (the app reads them). Idempotent: existing rows are kept.
 *
 *   php -c .claude/php-dev.ini database/upgrade-2026-09-daily-service.php   (local)
 *   php database/upgrade-2026-09-daily-service.php                            (VPS)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (Settings::get('daily_service_on', null) === null) {
    Settings::set('daily_service_on', '1', 'bool', 'booking', true);
    echo "seeded daily_service_on = 1\n";
} else {
    echo "daily_service_on already set = " . (Settings::getBool('daily_service_on', true) ? '1' : '0') . "\n";
}
if (Settings::get('daily_service_note', null) === null) {
    Settings::set('daily_service_note', '', 'string', 'booking', true);
    echo "seeded daily_service_note = ''\n";
} else {
    echo "daily_service_note already set\n";
}
Database::query("UPDATE settings SET label = 'Daily service ON (1) / OFF (0)' WHERE skey = 'daily_service_on' AND (label IS NULL OR label = '')");
Database::query("UPDATE settings SET label = 'Message shown while the daily service is OFF' WHERE skey = 'daily_service_note' AND (label IS NULL OR label = '')");
exit(0);
