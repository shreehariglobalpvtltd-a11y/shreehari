<?php
/**
 * /api/agreement-docread.php — "read my ID card and fill the form" for
 * /agreements/apply.html (23 Oct 2026, owner: "document upload garepachi
 * naam, janma miti afai aos, bala manche le correction garne").
 *
 * POST  Content-Type: application/json, X-CSRF-Token (from GET /api/agreement-apply.php)
 *       {"doc":"data:image/jpeg;base64,..."}
 *   200 {"ok":true,"status":"read"|"unavailable"|"notdoc",
 *        "fields":{"name":"Ram Bahadur Thapa","idno":"52-01-75-12345","idtype":"1","dob":"1990-05-14",
 *                  "gender":"male","address":"Nepalgunj-5, Banke","father":"…","doc_type":"citizenship"}}
 *
 * Nothing read here is trusted: the page only PRE-FILLS empty boxes and
 * the applicant corrects them; the usual name / number check
 * (agreement-doccheck.php) still runs on the final values. No value is
 * logged. Same budgets as the check: 15 reads an hour per IP, 300 a day.
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
