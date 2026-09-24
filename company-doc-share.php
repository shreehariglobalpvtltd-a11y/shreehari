<?php
/**
 * company-doc-share.php?t=<token> — the ONE public reader of a company
 * document, and only through a single-use, short-lived share token
 * (24 Sep 2026).
 *
 * When the WhatsApp assistant sends a document (AiTools company_doc_send)
 * Meta has to fetch the file from an https URL. That URL is this script
 * with a 48-hex token minted by CompanyDocs::mintShareLink() for ONE
 * document, ONE number, a few minutes and a handful of fetches (the
 * provider may retry). The token is stored hashed; the file on disk is
 * encrypted and is decrypted here, in memory, for this response only.
 * An expired, used-up, unknown or withdrawn token answers 404 with no
 * hint, and every fetch — served or refused — is a row in
 * company_document_access.
 *
 * There is deliberately no listing, no id parameter and no path
 * parameter: knowing a document exists is not enough to fetch it.
 */
declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/companydocs.php';

$token = (string) ($_GET['t'] ?? '');
$ctx   = ['channel' => 'link', 'role' => '', 'phone' => '', 'adminId' => 0];

if (!Security::rateLimit('doc_share_fetch', Security::clientIp(), 30, 300)) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Too many requests.');
}

$hit = CompanyDocs::consumeShareLink($token);
if ($hit === null) {
    CompanyDocs::logAccess(null, 'deny', $ctx, false, 'share token invalid, expired or used up');
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store');
    exit('This link is no longer valid.');
}

$doc  = $hit['doc'];
$link = $hit['link'];
$ctx['phone'] = (string) ($link['phone'] ?? '');
$ctx['role']  = (string) ($link['actor_role'] ?? '');

try {
    $bytes = CompanyDocs::plaintext($doc);
} catch (Throwable $e) {
    CompanyDocs::logAccess($doc, 'deny', $ctx, false, 'file unreadable: ' . $e->getMessage());
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('The file is no longer available.');
}

CompanyDocs::logAccess($doc, 'link_fetch', $ctx, true, 'use ' . ((int) $link['uses'] + 1) . ' of ' . (int) $link['max_uses']);

$mime = (string) ($doc['mime_type'] ?? '');
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain', 'text/csv', 'text/markdown',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
    $mime = 'application/octet-stream';
}
$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($doc['file_name'] ?? 'document')) ?: 'document';

if (!headers_sent()) {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
}
echo $bytes;
exit;
