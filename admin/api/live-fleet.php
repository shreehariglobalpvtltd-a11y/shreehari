<?php
/**
 * admin/api/live-fleet.php - every coach the driver app is running (20 Oct
 * 2026). Polled every 10 s by admin/map.php: bus, route, driver, passengers,
 * position, speed and how old the fix is. Read-only.
 *
 * Permission: schedules.view - the same staff who already see admin/map.php.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot('schedules.view');
require_once INCLUDE_PATH . '/livetrips.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    echo json_encode(['ok' => true, 'data' => ['trips' => LiveTrips::fleet(), 'at' => date('c')]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Logger::error('live-fleet failed', ['e' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not read the buses right now.']);
}
