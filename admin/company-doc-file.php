<?php
/**
 * admin/company-doc-file.php?id=<company_documents.id>[&dl=1] — stream ONE
 * company document to a signed-in staff member (24 Sep 2026).
 *
 * The file on disk is encrypted (CompanyDocs::seal); this is one of the two
 * readers that decrypt it, the other being the single-use share link the
 * assistant mints for WhatsApp. It requires an office session and a role
 * the paper's sensitivity allows (CompanyDocs::adminMaySee): the owner sees
 * everything, a manager everything but restricted, accounts / support only
 * public and internal. A paper the role may not see answers 404, not 403 —
 * confirming it exists is itself a disclosure. Every open is logged.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/companydocs.php';
$admin = admin_boot('dashboard.view');

$id  = (int) ($_GET['id'] ?? 0);
$doc = $id > 0 ? CompanyDocs::get($id) : null;
$ctx = ['channel' => 'admin', 'role' => (string) ($admin['role'] ?? ''), 'adminId' => (int) ($admin['id'] ?? 0), 'phone' => ''];

if ($doc !== null && !CompanyDocs::adminMaySee($admin, $doc)) {
    Logger::warning('Staff member tried to open a company document above their clearance', [
        'admin' => $admin['username'] ?? '', 'doc' => $id,
    ], 'security');
    CompanyDocs::logAccess($doc, 'deny', $ctx, false, 'above clearance for role ' . (string) ($admin['role'] ?? ''));
    $doc = null;
}

if ($doc === null || (string) ($doc['file_path'] ?? '') === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('No such document.');
}

try {
    $bytes = CompanyDocs::plaintext($doc);
} catch (Throwable $e) {
    CompanyDocs::logAccess($doc, 'deny', $ctx, false, 'file unreadable');
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('The file is no longer available.');
}

CompanyDocs::logAccess($doc, 'download', $ctx, true, 'opened in the staff panel');

$mime = (string) ($doc['mime_type'] ?? '');
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain', 'text/csv', 'text/markdown',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
    $mime = 'application/octet-stream';
}
$name        = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($doc['file_name'] ?? 'document')) ?: 'document';
$disposition = ((string) ($_GET['dl'] ?? '') === '1' || $mime === 'application/octet-stream') ? 'attachment' : 'inline';

if (!headers_sent()) {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $name) . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
}
echo $bytes;
exit;
