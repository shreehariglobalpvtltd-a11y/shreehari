<?php
/**
 * /api/agreement-docread.php — "read my ID card and fill the form" for
 * /agreements/apply.html.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/docverify.php';

const AGR_DOCREAD_BODY_MAX = 900_000;

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        Response::error('This endpoint takes POST.', 405);
    }
    Security::requireCsrf();
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > AGR_DOCREAD_BODY_MAX) {
        Response::error('That photo is too large.', 413);
    }
    Response::$maxInputBytes = AGR_DOCREAD_BODY_MAX;

    $ip = Security::clientIp();
    if (!Security::rateLimit('agr_docread_ip', $ip, 15, 3600)
        || !Security::rateLimit('agr_doccheck_all', 'global', 300, 86400)) {
        Response::error('धेरै पटक जाँच भयो — केही बेरपछि फेरि प्रयास गर्नुहोस्।', 429);
    }

    $doc = AgentApplications::cleanPhoto(Response::field('doc', null))['photo'];
    if ($doc === null) {
        Response::invalid(['docIn' => 'required'], 'डकुमेन्टको फोटो चाहिन्छ।');
    }
    if (DocVerify::mode() === 'off') {
        Response::json(['ok' => true, 'status' => 'unavailable', 'fields' => []]);
    }

    $key = 'read:' . hash('sha256', $doc);
    $r   = DocVerify::recall($key);
    if ($r === null) {
        $g = DocVerify::readFields($doc);
        $r = $g === null ? ['status' => 'unavailable', 'fields' => []] : DocVerify::fieldsFromRead($g);
        if ($r['status'] !== 'unavailable') {
            DocVerify::remember($key, $r);
        }
    }
    Logger::info('Agreement doc read', ['status' => $r['status'], 'filled' => array_keys(array_filter($r['fields'])), 'ip' => $ip]);
    Response::json(['ok' => true, 'status' => $r['status'], 'fields' => $r['fields']]);
} catch (Throwable $e) {
    Response::serverError($e);
}
