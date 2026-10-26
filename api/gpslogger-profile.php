<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/gpsloggerprofile.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
try {
    Security::requireRateLimit('gps_profile', Security::clientIp(), 30, 60);
    $tracker = GpsLoggerProfile::authorizedTracker($_GET);
    if ($tracker === null) { http_response_code(404); exit('Setup link expired or unavailable. Open Admin > GPS trackers for a new link.'); }
    header('Content-Type: application/octet-stream');
    $filename = preg_replace('/[^A-Za-z0-9-]/', '-', (string) $tracker['bus_number']);
    header('Content-Disposition: attachment; filename="SHG-' . $filename . '.properties"');
    echo GpsLoggerProfile::body((string) $tracker['device_uid']);
} catch (Throwable $e) { Response::serverError($e); }
