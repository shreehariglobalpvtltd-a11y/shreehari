<?php
/**
 * api/agreement-points.php — the office's changes to one agreement page.
 *
 *     GET /api/agreement-points.php?doc=agent|partner&lang=ne|hi|en|gu
 *
 *  → {"ok":true,"v":"…","points":{"2.1":{"text":"…{{org_rate}}…","hidden":false}},
 *     "added":[{"num":"1.6","section":"1","text":"…"}]}
 *
 * Public and read-only: the same words anyone reads on /agreements/. Only
 * points the office changed are listed — an empty answer means the page's
 * own wording stands. Used by assets/js/agreement-live.js (2 Oct 2026).
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agreementtext.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Response::error('GET only.', 405);
}

$doc  = (string) ($_GET['doc'] ?? '');
$lang = (string) ($_GET['lang'] ?? '');
if (!isset(AgreementText::DOCS[$doc]) || !isset(AgreementText::LANGS[$lang])) {
    Response::error('Unknown agreement or language.', 422);
}

try {
    $payload = AgreementText::publicPayload($doc, $lang);
} catch (Throwable $e) {
    Logger::warning('agreement points read failed', ['e' => $e->getMessage()]);
    // The page keeps its own wording; never break it for a read hiccup.
    $payload = ['points' => (object) [], 'added' => [], 'v' => ''];
}

// A minute: an office correction reaches readers quickly, and a busy page
// does not hit the database on every open.
Response::$publicCacheSeconds = 60;
Response::json(['ok' => true] + $payload);
