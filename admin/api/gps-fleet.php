<?php
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot(Auth::can('buses.view') ? 'buses.view' : 'schedules.view');
require_once INCLUDE_PATH . '/gpsfleet.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
try {
    echo json_encode(['ok' => true, 'data' => GpsFleet::snapshot()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Logger::error('GPS fleet read failed', ['e' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'GPS status is temporarily unavailable.']);
}
