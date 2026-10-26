<?php
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot('buses.view');
require_once INCLUDE_PATH . '/gpssimulator.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private');
if (!GpsSimulator::available()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'GPS simulation is disabled in production.']); exit; }
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
try {
    $state = GpsSimulator::state();
    if ((int) $state['routeId'] === 0) { echo json_encode(['ok' => true, 'data' => ['simulation' => true, 'buses' => [], 'running' => false]]); exit; }
    $route = GpsSimulator::route((int) $state['routeId']); $frame = GpsSimulator::frame($state, $route, microtime(true));
    if (($_GET['route'] ?? '') === '1') { $frame['line'] = array_map(static fn($p) => [$p[1], $p[0]], $route['points']); }
    echo json_encode(['ok' => true, 'data' => $frame], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) { http_response_code(400); echo json_encode(['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Simulation unavailable.']); }
