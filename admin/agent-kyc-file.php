<?php
/**
 * admin/agent-kyc-file.php?agent=<id>&slot=1|2 — streams one agent's KYC
 * document (17 Sep 2026).
 *
 * WHY A SCRIPT AND NOT A LINK: everything under /uploads/ is served by
 * nginx to anyone who knows the path (deploy/nginx-shreehariglobal.in.conf,
 * location ^~ /uploads/). That is fine for a face photo and wrong for an
 * Aadhaar / citizenship scan. So the KYC files live under
 * uploads/agents-kyc/ but are NEVER linked as /uploads/…; the only way to
 * read one is through this page, which checks who is asking, refuses any
 * path that is not inside agents-kyc/, reads the real MIME type from the
 * bytes (never from the stored name) and streams it with no caching.
 *
 * Gate: staff.manage (verifies KYC) OR commissions.view (office finance)
 * OR the agent's own profile (Auth::bookingScopeAdminId() === agent).
 * Every view is written to the audit trail — an ID card is a thing whose
 * readers the office should be able to list.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot();   // signed-in staff; the document-level gate is below

$agentId = (int) ($_GET['agent'] ?? 0);
$slot    = ((string) ($_GET['slot'] ?? '1')) === '2' ? '2' : '1';

$deny = static function (int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($msg);
};

$own = $agentId > 0 && Auth::bookingScopeAdminId() === $agentId;
if (!(Auth::can('staff.manage') || Auth::can('commissions.view') || $own)) {
    $deny(403, 'You may not view this document.');
}
if ($agentId <= 0 || !AgentWallet::kycAvailable()) {
    $deny(404, 'No document on file.');
}

$profile = AgentWallet::profile($agentId);
$rel     = (string) ($profile[$slot === '2' ? 'kyc_doc2_path' : 'kyc_doc_path'] ?? '');

// The stored path must be exactly what saveKycDoc() writes: 'agents-kyc/<file>'.
// Anything else — a traversal, an absolute path, a photo path — is a 404,
// not a 403, so the response never confirms what exists.
if ($rel === '' || !str_starts_with($rel, 'agents-kyc/') || str_contains($rel, '..') || str_contains($rel, "\0")) {
    $deny(404, 'No document on file.');
}
$dir = realpath(UPLOAD_PATH . '/agents-kyc');
$abs = realpath(UPLOAD_PATH . '/' . $rel);
if ($dir === false || $abs === false || !is_file($abs) || !str_starts_with($abs, $dir . DIRECTORY_SEPARATOR)) {
    $deny(404, 'No document on file.');
}

// Real type from the bytes; only the four kinds the uploader accepts are
// shown inline — anything else downloads as an opaque attachment.
$mime = '';
try {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($abs);
} catch (Throwable $e) {
    $mime = '';
}
$inline = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
$ext    = $inline[$mime] ?? 'bin';
if ($ext === 'bin') {
    $mime = 'application/octet-stream';
}

Logger::audit('agent.kyc_viewed', 'admin', (string) $agentId, null,
    ['slot' => $slot, 'mime' => $mime, 'size' => (int) filesize($abs)], 'KYC document viewed');

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($abs));
header('Content-Disposition: ' . ($ext === 'bin' ? 'attachment' : 'inline')
     . '; filename="agent-' . $agentId . '-kyc-' . $slot . '.' . $ext . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($abs);
exit;
