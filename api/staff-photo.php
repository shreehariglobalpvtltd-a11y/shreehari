<?php
/**
 * GET /api/staff-photo.php?k=<web_key> — the face on an "Our team" card
 * (22 Oct 2026).
 *
 * Answers only for an APPROVED application the office left on the website,
 * while 'photo_path' is one of the public fields (StaffApps::publicPhoto),
 * and only with the 240 px square thumbnail — never the full photo, an ID,
 * a certificate or the signature. The key is a random 20-hex value, never
 * the row id, so the team cannot be walked through.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/staffapplications.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        Response::error('This endpoint only answers GET.', 405);
    }
    Security::requireRateLimit('staff_photo', Security::clientIp(), 300, 60);
    $img = StaffApps::publicPhoto((string) ($_GET['k'] ?? ''));
    if ($img === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'Not found';
        exit;
    }
    header_remove('Set-Cookie');
    header('Content-Type: ' . $img[0]);
    header('Content-Length: ' . strlen($img[1]));
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $img[1];
    exit;
} catch (Throwable $e) {
    Response::serverError($e);
}
