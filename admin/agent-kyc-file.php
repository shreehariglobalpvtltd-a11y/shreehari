<?php
/**
 * admin/agent-kyc-file.php?agent=<id>&slot=1|2 — streams one agent's KYC document.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot();

$agentId = (int) ($_GET['agent'] ?? 0);
$slot    = in_array((string) ($_GET['slot'] ?? '1'), ['2', '3'], true) ? (string) $_GET['slot'] : '1';

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
$rel     = $slot === '3' ? AgentWallet::agreementPath($agentId) : (string) ($profile[$slot === '2' ? 'kyc_doc2_path' : 'kyc_doc_path'] ?? '');

if ($rel === '' || !str_starts_with($rel, 'agents-kyc/') || str_contains($rel, '..') || str_contains($rel, "\0")) {
    $deny(404, 'No document on file.');
}
$dir = realpath(UPLOAD_PATH . '/agents-kyc');
$abs = realpath(UPLOAD_PATH . '/' . $rel);
if ($dir === false || $abs === false || !is_file($abs) || !str_starts_with($abs, $dir . DIRECTORY_SEPARATOR)) {
    $deny(404, 'No document on file.');
}

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
header('Content-Disposition: ' . ($ext === 'bin' ? 'attachment' : 'inline') . '; filename="agent-' . $agentId . '-kyc-' . $slot . '.' . $ext . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($abs);
