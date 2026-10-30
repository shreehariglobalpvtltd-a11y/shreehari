<?php
/**
 * GET /api/ap-photo.php?k=<20 hex> — the face on a home page card of an
 * AgentPro member (31 Oct 2026, owner: "verified admin le dinchha, live ma
 * home page ma photo haru dekhiyos").
 *
 * Answers only while that member is on the website (ApWeb: active login and
 * card, office approved / KYC verified or "always show", not hidden or
 * archived), and only with a re-encoded JPEG of at most 400 px - never the
 * ID documents, the selfie original or the signature. The key is an HMAC of
 * the card token and the photo, never the admin id or the file name, so the
 * team cannot be walked through; a new photo is a new key.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentpro-web.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        Response::error('This endpoint only answers GET.', 405);
    }
    Security::requireRateLimit('ap_photo', Security::clientIp(), 300, 60);
    $found = ApWeb::photoFor((string) ($_GET['k'] ?? ''));
    $bytes = null;
    if ($found !== null) {
        header_remove('Set-Cookie');
        $inm = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($inm !== '' && in_array($found['etag'], array_map('trim', explode(',', $inm)), true)) {
            http_response_code(304);
            header('ETag: ' . $found['etag']);
            header('Cache-Control: public, max-age=86400');
            exit;
        }
        $bytes = ApWeb::jpeg($found['abs']);
    }
    if ($bytes === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'Not found';
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: public, max-age=86400');
    header('ETag: ' . $found['etag']);
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
} catch (Throwable $e) {
    Response::serverError($e);
}
