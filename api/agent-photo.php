<?php
/**
 * GET /api/agent-photo.php?k=<web_key> — an agent's photo on the website's
 * "Our agents" cards (19 Oct 2026).
 *
 * Answers only for an APPROVED application whose card is live and whose
 * photo the office allowed (AgentApplications::publicPhoto). The key is a
 * random 20-hex value, never the row id, so photos cannot be walked
 * through. The document photo and the signature are never served here.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentapplications.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        Response::error('This endpoint only answers GET.', 405);
    }
    Security::requireRateLimit('agent_photo', Security::clientIp(), 300, 60);
    // ?v=logo (22 Oct 2026): a live partner company's logo instead of the photo
    $img = AgentApplications::publicPhoto((string) ($_GET['k'] ?? ''), (string) ($_GET['v'] ?? '') === 'logo' ? 'logo' : 'photo');
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
