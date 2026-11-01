<?php
/**
 * me/doc-share.php?a=<adminId>&k=<doc key>&e=<expiry>&s=<sig> — one of a
 * member's OWN documents, for the WhatsApp desk's "kagaj 2" (1 Nov 2026).
 *
 * The link is an HMAC under APP_KEY of member + key + expiry, made only by
 * ApWa::myDocs for the member who asked from their own number, and it dies
 * after 15 minutes (long enough for WhatsApp to fetch the file). The file is
 * found through AgentPro::memberDocPath — only ever that member's own —
 * served with no caching, every fetch audited.
 */
declare(strict_types=1);
defined('SHG_APP') || define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';

$a = (int) ($_GET['a'] ?? 0);
$k = (string) ($_GET['k'] ?? '');
$e = (int) ($_GET['e'] ?? 0);
$s = (string) ($_GET['s'] ?? '');

$ok = $a > 0 && $e >= time() && $e <= time() + 3600
    && preg_match('/^(kyc[12]|front|back|selfie|thumb|x\d{1,2})$/', $k) === 1
    && hash_equals(substr(hash_hmac('sha256', 'apdoc|' . $a . '|' . $k . '|' . $e, APP_KEY), 0, 32), $s);
$abs = $ok ? AgentPro::memberDocPath($a, $k) : null;
if ($abs === null || !is_file($abs)) {
    http_response_code(404);
    exit('Not found');
}

$mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($abs);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    http_response_code(415);
    exit('Unsupported');
}
Logger::audit('agentpro.doc_share_fetch', 'admin', (string) $a, null, ['key' => $k], 'Own document fetched through a signed WhatsApp link');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($abs));
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
readfile($abs);
