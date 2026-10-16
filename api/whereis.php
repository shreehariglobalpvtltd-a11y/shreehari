<?php
/**
 * api/whereis.php?pnr=…&k=…  — "Where is my bus?" refresh for track.php.
 *
 * The same keyed door as the ticket download. Answers the WhereIs::status()
 * shape: trip state, live position (only when fresh, accurate and on this
 * route), minutes and km to the passenger's own boarding stop. Never a guess.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/whereis.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    Security::requireRateLimit('whereis', Security::clientIp(), 120, 60);
    $pnr   = strtoupper(Security::clean((string) ($_GET['pnr'] ?? ''), 40));
    $token = Security::clean((string) ($_GET['k'] ?? ''), 64);
    $booking = $pnr !== '' ? BookingService::findByPnr($pnr) : null;
    if ($booking === null || !WhereIs::authorised($booking, $token)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found.']);
        exit;
    }
    echo json_encode(['ok' => true, 'data' => WhereIs::status($booking)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    Logger::error('whereis failed', ['e' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not read the bus position right now.']);
}
