<?php
/**
 * /api/agreement-doccheck.php — "is this document really mine?" for the
 * agent form /agreements/apply.html (18 Oct 2026).
 *
 * POST  Content-Type: application/json, X-CSRF-Token (from GET /api/agreement-apply.php)
 *       {"doc":"data:image/jpeg;base64,...","name":"Ram Bahadur Thapa","idno":"52-01-75-12345","idtype":"1"}
 *   200 {"ok":true,"status":"pass"|"fail"|"unavailable","name_ok":bool|null,"number_ok":bool|null,
 *        "is_doc":bool|null,"seen_name":"...","seen_number":"...","message":"<Nepali line>"}
 *
 * The readers never see what was typed (DocVerify). A pass is kept in the
 * session, so the send that follows does not read the card a second time.
 * Budgets: 15 checks an hour per IP, 300 a day for the whole site (the free
 * Gemini quota), the body at most 900 KB.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/docverify.php';

const AGR_DOC_BODY_MAX = 900_000;

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        Response::error('This endpoint takes POST.', 405);
    }
    Security::requireCsrf();
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > AGR_DOC_BODY_MAX) {
        Response::error('That photo is too large.', 413);
    }
    Response::$maxInputBytes = AGR_DOC_BODY_MAX;

    $ip = Security::clientIp();
    if (!Security::rateLimit('agr_doccheck_ip', $ip, 15, 3600)
        || !Security::rateLimit('agr_doccheck_all', 'global', 300, 86400)) {
        Response::error('धेरै पटक जाँच भयो — केही बेरपछि फेरि प्रयास गर्नुहोस् वा कार्यालयमा फोन गर्नुहोस्: ' . Settings::officePhone(), 429);
    }

    $doc  = AgentApplications::cleanPhoto(Response::field('doc', null))['photo'];
    $name = trim((string) Response::field('name', ''));
    $idno = trim((string) Response::field('idno', ''));
    $type = (string) Response::field('idtype', '');
    if ($doc === null || mb_strlen($name) < 2 || strlen(DocVerify::normNumber($idno)) < 4) {
        Response::invalid(array_filter([
            'p_name' => mb_strlen($name) < 2 ? 'required' : '',
            'p_idno' => strlen(DocVerify::normNumber($idno)) < 4 ? 'required' : '',
            'docIn'  => $doc === null ? 'required' : '',
        ]), 'नाम, डकुमेन्ट नम्बर र डकुमेन्टको फोटो चाहिन्छ।');
    }

    if (DocVerify::mode() === 'off') {
        Response::json(['ok' => true, 'status' => 'pass', 'name_ok' => null, 'number_ok' => null, 'is_doc' => null,
                        'seen_name' => '', 'seen_number' => '', 'message' => 'डकुमेन्ट जाँच बन्द छ — कार्यालयले हेर्नेछ।']);
    }

    $key = DocVerify::key($doc, $name, $idno);
    $r   = DocVerify::recall($key) ?? DocVerify::check($doc, $name, $idno, $type);
    if ($r['status'] !== 'unavailable') {
        DocVerify::remember($key, $r);
    }
    Logger::info('Agreement doc check', ['status' => $r['status'], 'engine' => $r['engine'], 'problems' => $r['problems'], 'ip' => $ip]);

    Response::json([
        'ok'          => true,
        'status'      => $r['status'],
        'name_ok'     => $r['name_ok'],
        'number_ok'   => $r['number_ok'],
        'is_doc'      => $r['is_doc'],
        'seen_name'   => $r['seen_name'],
        'seen_number' => $r['seen_number'],
        'message'     => DocVerify::message($r),
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
