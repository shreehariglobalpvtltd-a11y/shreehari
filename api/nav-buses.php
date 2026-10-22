<?php
/**
 * GET /api/nav-buses.php - S Hari coaches on the road now, for the SHARI NAV
 * map (Phase 2, 22 Oct 2026): position, from -> to, next boarding stop and
 * minutes to it. Only coaches with a fresh, accurate fix (the track page's
 * rules, EtaAlerts::fixForTrip). One snapshot serves everyone for 12 s, and
 * the same snapshot is pushed on channel "bus" by cron/nav-live.php.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/navlive.php';

header('Cache-Control: no-store');

try {
    Security::requireRateLimit('nav_buses', Security::clientIp(), 60, 60);
    Response::success(NavLive::buses());
} catch (Throwable $e) {
    Response::serverError($e);
}
