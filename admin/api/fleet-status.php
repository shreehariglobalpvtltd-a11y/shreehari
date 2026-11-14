<?php
/**
 * admin/api/fleet-status.php - the count bar above admin/map.php (15 Nov 2026):
 * running / GPS ok / GPS silent / stopped / not started, with the ids behind
 * each count so a tap filters the map. Polled every 30 s by the map.
 *
 * Read-only. Same gate as the map (schedules.view); no session = 401 JSON.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/_guard.php';
admin_boot('schedules.view');
require_once INCLUDE_PATH . '/gpsfleet.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

try {
    echo json_encode(['ok' => true, 'data' => GpsFleet::statusBar()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Logger::error('fleet-status failed', ['e' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Fleet status is temporarily unavailable.']);
}
