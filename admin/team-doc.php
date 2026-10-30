<?php
/**
 * admin/team-doc.php?app=<id>&f=front|back|selfie|thumb|extra[&i=n][&deposit=<id>]
 * Streams one private file of a join application (or a payment proof) to
 * the office. Same rules as admin/agent-kyc-file.php: never a public URL,
 * the stored path must be inside uploads/agents-kyc/, real MIME from the
 * bytes, no caching, every view audited.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentpro.php';
admin_boot();

$deny = static function (int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($msg);
};
if (!(Auth::can('staff.manage') || Auth::can('commissions.view'))) {
    $deny(403, 'You may not view this document.');
}
$rel = '';
$what = '';
if (isset($_GET['deposit'])) {
    $row = Database::fetch('SELECT id, admin_id, proof_path FROM ap_deposit_requests WHERE id = :i', ['i' => (int) $_GET['deposit']]);
    $rel = (string) ($row['proof_path'] ?? '');
    $what = 'deposit#' . (int) ($row['id'] ?? 0);
} else {
    $app = AgentPro::application((int) ($_GET['app'] ?? 0));
    if ($app === null) {
        $deny(404, 'No document on file.');
    }
    $f = (string) ($_GET['f'] ?? '');
    if ($f === 'extra') {
        $rel = (string) (jsonColumn($app['extras'] ?? null)[(int) ($_GET['i'] ?? -1)]['path'] ?? '');
    } else {
        $col = ['front' => 'doc_front', 'back' => 'doc_back', 'selfie' => 'selfie', 'thumb' => 'thumb'][$f] ?? '';
        $rel = $col !== '' ? (string) ($app[$col] ?? '') : '';
    }
    $what = $app['ref'] . ':' . $f;
}
$abs = AgentPro::privateAbs($rel);
if ($abs === null) {
    $deny(404, 'No document on file.');
}
$mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($abs);
$ok = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
$ext = $ok[$mime] ?? 'bin';
if ($ext === 'bin') {
    $mime = 'application/octet-stream';
}
Logger::audit('agentpro.doc_viewed', 'application', $what, null, ['mime' => $mime], 'Private document viewed');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($abs));
header('Content-Disposition: ' . ($ext === 'bin' ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9-]/', '-', $what) . '.' . $ext . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($abs);
