<?php
/**
 * /api/agreement-scan.php — "read my filled paper form and fill the boxes"
 * for /agreements/apply.html (24 Oct 2026, owner: "khaali form print garera
 * haat le bharera scan garepachhi OCR garera form automatic feel hune ra
 * attach hune").
 *
 * POST  Content-Type: application/json, X-CSRF-Token (from GET /api/agreement-apply.php)
 *       {"pages":["data:image/jpeg;base64,...", ...],"kind":"ind"|"org"|"staff",
 *        "form":"cv"|"apply"|"sa"}   which page's input ids to answer in (FormScan::FORMS, default cv)
 *   200 {"ok":true,"status":"read"|"partial"|"notform"|"unavailable",
 *        "fields":{"p_name":"Ram Bahadur Thapa","p_phone":"98...","p_dob":"1990-05-14",...},
 *        "pages":2}
 *
 * Nothing read here is trusted: the page only PRE-FILLS empty boxes and the
 * applicant corrects them; the ID name/number check and the office review
 * still run on the final values. No value is logged. The scanned pages are
 * kept in this session (45 min) so the submit can attach them to the
 * application for the office, like the ID photo. Its own budget — separate
 * from the ID reader — so whole-form scans never starve ID verification:
 * 10 scans an hour per IP, 120 a day for the whole site.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/docverify.php';
require_once INCLUDE_PATH . '/formscan.php';

const AGR_SCAN_BODY_MAX = 2_600_000;   // up to 4 shrunk A4 page scans (the page grays + shrinks each first)
const AGR_SCAN_KEEP     = 3;           // pages kept in the session to attach

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        Response::error('This endpoint takes POST.', 405);
    }
    Security::requireCsrf();
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > AGR_SCAN_BODY_MAX) {
        Response::error('That scan is too large — send fewer or smaller pages.', 413);
    }
    Response::$maxInputBytes = AGR_SCAN_BODY_MAX;

    $ip = Security::clientIp();
    if (!Security::rateLimit('agr_scan_ip', $ip, 10, 3600)
        || !Security::rateLimit('agr_scan_all', 'global', 120, 86400)) {
        Response::error('धेरै पटक स्क्यान भयो — केही बेरपछि फेरि प्रयास गर्नुहोस्।', 429);
    }

    $kindIn = strtolower(trim((string) Response::field('kind', 'ind')));
    $kind   = in_array($kindIn, ['org', 'partner'], true) ? 'org' : ($kindIn === 'staff' ? 'staff' : 'ind');
    // 30 Oct 2026: the live one-page form (f_* ids) and the A4 staff sheet (sa_* ids) ask in their own ids
    $formIn = strtolower(trim((string) Response::field('form', 'cv')));
    $form   = in_array($formIn, FormScan::FORMS, true) ? $formIn : 'cv';

    $raw = Response::field('pages', []);
    if (!is_array($raw)) {
        $raw = [];
    }
    $pages = [];
    foreach (array_slice($raw, 0, FormScan::MAX_PAGES) as $uri) {
        $clean = AgentApplications::cleanPhoto($uri)['photo'];
        if (is_string($clean)) {
            $pages[] = $clean;
        }
    }
    if ($pages === []) {
        Response::invalid(['pages' => 'required'], 'भरिएको फारमको फोटो वा स्क्यान चाहिन्छ।');
    }

    if (!FormScan::on()) {
        Response::json(['ok' => true, 'status' => 'unavailable', 'fields' => [], 'pages' => count($pages)]);
    }

    $key = 'scan:' . hash('sha256', $kind . '|' . $form . '|' . implode('|', array_map(static fn($p) => hash('sha256', $p), $pages)));
    $r   = DocVerify::recall($key);
    if ($r === null) {
        $r = FormScan::read($pages, $kind, $form);
        if ($r['status'] !== 'unavailable') {
            DocVerify::remember($key, $r);
        }
    }

    // Keep the scan in the session so the submit (api/agreement-apply.php) can
    // attach the pages + what was read, to this same kind, within 45 minutes.
    if ($r['status'] === 'read' || $r['status'] === 'partial') {
        $_SESSION['agr_scan'] = [
            't'      => time(),
            'kind'   => $kind,
            'pages'  => array_slice($pages, 0, AGR_SCAN_KEEP),
            'ocr'    => $r['fields'],
            'status' => $r['status'],
        ];
    }

    Logger::info('Agreement form scan', ['status' => $r['status'], 'kind' => $kind, 'form' => $form, 'filled' => array_keys($r['fields']), 'pages' => count($pages), 'ip' => $ip]);
    Response::json(['ok' => true, 'status' => $r['status'], 'fields' => $r['fields'], 'pages' => count($pages)]);
} catch (Throwable $e) {
    Response::serverError($e);
}
