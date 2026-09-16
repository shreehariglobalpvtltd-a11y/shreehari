<?php
/**
 * qr/?data=...  — render a QR PNG on the fly (payment / share links).
 *
 * Kept deliberately small and cache-friendly. Payload length is capped
 * so this can never be used to render an oversized image.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/qr.php';

try {
    $data = (string) ($_GET['data'] ?? '');

    if ($data === '' || strlen($data) > 512) {
        http_response_code(400);
        exit('Invalid QR data.');
    }

    Security::requireRateLimit('qr_render', Security::clientIp(), 120, 60);

    $scale = max(2, min(12, (int) ($_GET['scale'] ?? 8)));

    // Cache the rendered PNG by content hash so repeat loads are free.
    $cacheKey  = substr(hash('sha256', $data . '|' . $scale), 0, 24);
    $cacheFile = QR_PATH . '/cache_' . $cacheKey . '.png';

    if (!is_file($cacheFile)) {
        QrCode::png($data, $cacheFile, $scale, 3, QrCode::ECC_M);
    }

    header('Cache-Control: public, max-age=86400');
    Response::inline($cacheFile, 'image/png');
} catch (Throwable $e) {
    Logger::exception($e);
    http_response_code(500);
    exit('QR could not be generated.');
}
