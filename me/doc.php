<?php
/**
 * me/doc.php — the signed-in member's OWN documents (31 Oct 2026, owner:
 * "documents haru pani herne, upload garne option chhaina").
 *
 *   GET  ?list=1          JSON: every document of theirs (AgentPro::memberDocs) + the labels to add one
 *   GET  ?k=<key>         the file itself, inline (no-store, nosniff) — kyc1 / kyc2 / front / back /
 *                         selfie / thumb / x<N>, always resolved from the session, never from the URL
 *   POST file + label     adds a document to their form (AgentPro::memberAddDoc); X-CSRF-Token
 *
 * Staff were refused by admin/agent-kyc-file.php (agents only), so a counter
 * or manager could not open their own ID photos from /me/.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';

Security::sendHeaders();
$admin = Auth::admin();
if ($admin === null || !AgentPro::available()) {
    Response::unauthorized('Please sign in.');
}
$id = (int) $admin['id'];

try {
    if (Security::isPost()) {
        if (!Security::verifyCsrf()) {
            Response::error('Session expired — reload the page.', 419);
        }
        Security::requireRateLimit('ap_member_doc', (string) $id, 20, 3600);
        if (empty($_FILES['file'])) {
            Response::error('Choose a file.', 422);
        }
        AgentPro::memberAddDoc($id, $_FILES['file'], (string) ($_POST['label'] ?? ''));
        Response::success(['docs' => AgentPro::memberDocs($id)], 'Document saved — the office can see it now.');
    }

    if (isset($_GET['list'])) {
        Response::json(['ok' => true, 'docs' => AgentPro::memberDocs($id), 'labels' => AgentPro::MEMBER_DOC_LABELS]);
    }

    $key = (string) ($_GET['k'] ?? '');
    $abs = preg_match('/^(kyc[12]|front|back|selfie|thumb|x\d{1,2})$/', $key) === 1 ? AgentPro::memberDocPath($id, $key) : null;
    if ($abs === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('No such document.');
    }
    $mime = '';
    try {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($abs);
    } catch (Throwable $e) {
        $mime = '';
    }
    $ok = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $ext = $ok[$mime] ?? 'bin';
    if ($ext === 'bin') {
        $mime = 'application/octet-stream';
    }
    Logger::audit('agentpro.member_doc_viewed', 'admin', (string) $id, null, ['key' => $key], 'Member opened their own document');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($abs));
    header('Content-Disposition: ' . ($ext === 'bin' ? 'attachment' : 'inline') . '; filename="my-' . $key . '.' . $ext . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    readfile($abs);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
} catch (Throwable $e) {
    Response::serverError($e);
}
