<?php
/**
 * api/whereis.php?pnr=…&k=…  — "Where is my bus?" refresh for track.php.
 *
 * The same door as track.php (WhereIs::authorised): the tracker's own key
 * (Ticket::trackToken, 8 Oct 2026), the download key older links carry, the
 * owning customer or staff. Answers the WhereIs::status() shape: trip state,
 * live position (only when fresh, accurate and on this route), minutes and km
 * to the passenger's own boarding stop, or an honest last-known place while
 * the coach is out of signal. Never a guess.
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
    /* 26 Nov 2026 (fx1): how long the asking screen may keep calling this answer "live" when
       its own connection brings no newer one (track_stale_sec, 0 = off, else 30..600 s). */
    $stale = Settings::getInt('track_stale_sec', 60);
    $data  = WhereIs::status($booking) + ['staleSec' => $stale > 0 ? max(30, min(600, $stale)) : 0];
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    Logger::error('whereis failed', ['e' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not read the bus position right now.']);
}
