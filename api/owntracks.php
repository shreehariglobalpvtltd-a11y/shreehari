<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/owntracks.php';
if (session_status() === PHP_SESSION_ACTIVE) {
    if (!isset($_COOKIE[session_name()])) { session_destroy(); } else { session_write_close(); }
}
header_remove('Set-Cookie');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function owntracks_reply(int $code): never { http_response_code($code); echo '[]'; exit; }
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); owntracks_reply(405); }
    if (!GpsTrackers::enabled() || !GpsTrackers::tableReady()) { owntracks_reply(503); }
    if (!Security::rateLimit('owntracks_ip', Security::clientIp(), 300, 60)) { owntracks_reply(429); }
    $tracker = OwnTracksGateway::authenticatedTracker($_SERVER);
    if ($tracker === null) { header('WWW-Authenticate: Basic realm="S Hari bus GPS"'); owntracks_reply(401); }
    if (!Security::rateLimit('owntracks_tracker', (string) $tracker['id'], 120, 60)) { owntracks_reply(429); }
    $raw = file_get_contents('php://input', false, null, 0, GpsTrackers::MAX_BODY + 1);
    if (!is_string($raw) || strlen($raw) > GpsTrackers::MAX_BODY) { owntracks_reply(413); }
    if ($raw === '') { owntracks_reply(200); }
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !is_string($payload['_type'] ?? null)) { owntracks_reply(400); }
    if (in_array($payload['_type'], ['status', 'transition', 'lwt', 'waypoint', 'waypoints', 'steps', 'card', 'request'], true)) { owntracks_reply(200); }
    if ($payload['_type'] !== 'location') { owntracks_reply(400); }
    $q = OwnTracksGateway::location($payload, (string) $tracker['device_uid']);
    $result = GpsTrackers::ingestOsmAnd($q);
    owntracks_reply($result['code']);
} catch (InvalidArgumentException $e) { owntracks_reply(400); }
catch (Throwable $e) { Logger::exception($e); owntracks_reply(500); }
