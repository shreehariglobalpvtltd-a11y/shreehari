<?php
/**
 * =====================================================================
 *  /api/gps.php - a GPS tracker of a BUS sends its position (22 Oct 2026).
 *  (includes/gpstrackers.php, admin/gps-trackers.php)
 *
 *    Traccar Client app (phone)      Server URL = https://www.shreehariglobal.in/api/gps.php
 *                                    Device identifier = the SHG code of the bus
 *    Traccar server (GPS box, IMEI)  forward.url = .../api/gps.php?id={uniqueId}&lat={latitude}
 *                                    &lon={longitude}&timestamp={fixTime}&speed={speed}
 *                                    &bearing={course}&key=<forward key>
 *
 *  GET or POST, query string, form or JSON body (OsmAnd protocol, Traccar
 *  Client 9, Traccar forward json). No session, no CSRF: the device id is
 *  the credential (plus the key for an IMEI). Plain-text answers, because
 *  Traccar Client only looks at the status:
 *
 *    200 OK   stored (or nothing to store: parked bus, invalid fix)
 *    400      not a position report
 *    403      unknown / switched-off device, or a wrong key
 *    429      too many requests
 *    503      switched off (gps_trackers_on) or not set up yet
 *
 *  Nothing is logged per fix (one every 10 s per bus), and the device id
 *  and key are never echoed back.
 * ===================================================================== */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/gpstrackers.php';

/* A tracker keeps no cookie, so bootstrap opened a brand-new session for
   this request: throw it away rather than leave a session file behind for
   every fix. (A browser that does carry a session keeps it.) */
if (session_status() === PHP_SESSION_ACTIVE) {
    if (!isset($_COOKIE[session_name()])) {
        session_destroy();
    } else {
        session_write_close();
    }
}
header_remove('Set-Cookie');

function gps_reply(int $status, string $text): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo $text;
    exit;
}

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'POST') {
        gps_reply(405, 'Method not allowed');
    }
    if (!GpsTrackers::enabled()) {
        gps_reply(503, 'Switched off');
    }
    if (!GpsTrackers::tableReady()) {
        gps_reply(503, 'Not set up');
    }
    if (!Security::rateLimit('gps_ip', Security::clientIp(), 600, 60)) {
        gps_reply(429, 'Too many requests');
    }

    $q = $_GET;
    if ($method === 'POST') {
        $q = $_POST + $q;
        if (str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'json')) {
            $raw = file_get_contents('php://input', false, null, 0, GpsTrackers::MAX_BODY + 1);
            if (is_string($raw) && strlen($raw) > GpsTrackers::MAX_BODY) {
                gps_reply(413, 'Too large');
            }
            $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($json)) {
                gps_reply(400, 'Bad request');
            }
            $q = $json + $q;
        }
    }

    $uid = GpsTrackers::normaliseUid(GpsTrackers::uidOf($q));
    if ($uid === '') {
        gps_reply(400, 'Bad request');
    }
    // per device: a phone sends every 10 s, plus its backlog after a dead zone
    if (!Security::rateLimit('gps_uid', substr(hash('sha256', $uid), 0, 32), 120, 60)) {
        gps_reply(429, 'Too many requests');
    }

    $res = GpsTrackers::ingestOsmAnd($q);
    gps_reply($res['code'], match ($res['code']) {
        200     => 'OK',
        403     => 'Forbidden',
        default => 'Bad request',
    });
} catch (Throwable $e) {
    Logger::exception($e);
    gps_reply(500, 'Error');
}
