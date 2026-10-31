<?php
/**
 * GET /api/routine.php?days=14 — the Bus Routine board as JSON (1 Nov 2026).
 *
 * Restores the endpoint the earlier home page called (it was never in git).
 * Same rows as /routine.php: includes/routineboard.php reads what is sold.
 */
declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/routineboard.php';

try {
    Security::requireRateLimit('routine_api', Security::clientIp(), 60, 60);
    $days = (int) ($_GET['days'] ?? 14);
    Response::success([
        'days'       => max(1, min(RoutineBoard::MAX_DAYS, $days)),
        'serviceOn'  => Settings::getBool('daily_service_on', true),
        'departures' => RoutineBoard::departures($days),
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
