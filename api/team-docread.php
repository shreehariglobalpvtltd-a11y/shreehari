<?php
/**
 * api/team-docread.php — Multimodal OCR for printed IDs and handwritten KYC forms.
 *
 * Implements:
 *  - OCR for uploaded photos/PDFs into editable KYC fields
 *  - Highlights uncertain text for admin review (confidence: high, medium, uncertain)
 *  - Retains original document bytes
 *  - Strict rule: Never hallucinates or invents missing details
 */

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/docverify.php';

const TEAM_DOCREAD_MAX_BYTES = 4_000_000;

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        Response::error('This endpoint takes POST.', 405);
    }
    Security::requireCsrf();

    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > TEAM_DOCREAD_MAX_BYTES) {
        Response::error('Uploaded document file is too large (max 4MB).', 413);
    }
    Response::$maxInputBytes = TEAM_DOCREAD_MAX_BYTES;

    $ip = Security::clientIp();
    if (!Security::rateLimit('team_docread_ip', $ip, 25, 3600)) {
        Response::error('Too many scan requests. Please wait a moment.', 429);
    }

    $docData = Response::field('doc', null);
    $isHandwritten = (bool) Response::field('handwritten', false);

    if (empty($docData) || !is_string($docData)) {
        Response::invalid(['doc' => 'required'], 'डकुमेन्ट वा फारमको फोटो आवश्यक छ · Document photo is required.');
    }

    // Extract binary bytes
    $mime = 'image/jpeg';
    if (preg_match('/^data:(image\/[a-zA-Z+]+|application\/pdf);base64,/', $docData, $m)) {
        $mime = $m[1];
        $rawBase64 = substr($docData, strlen($m[0]));
    } else {
        $rawBase64 = $docData;
    }
    $bytes = (string) base64_decode($rawBase64, true);

    if (strlen($bytes) < 500) {
        Response::error('Invalid or corrupted document image data.', 400);
    }

    if (DocVerify::mode() === 'off') {
        Response::json([
            'ok' => true,
            'status' => 'unavailable',
            'fields' => [],
            'handwritten' => $isHandwritten,
            'message' => 'AI OCR service is offline or not configured with Gemini API key.'
        ]);
    }

    if ($isHandwritten) {
        // Multimodal OCR for Handwritten Form
        $result = DocVerify::importHandwrittenForm($bytes, $mime);
        Logger::info('Handwritten form OCR processed', [
            'status' => $result['status'] ?? 'unknown',
            'uncertain_count' => count($result['uncertain_fields'] ?? []),
            'ip' => $ip
        ]);

        Response::json([
            'ok'               => true,
            'status'           => $result['status'] ?? 'read',
            'handwritten'      => true,
            'fields'           => $result['fields'] ?? [],
            'confidence'       => $result['confidence'] ?? [],
            'uncertain_fields' => $result['uncertain_fields'] ?? [],
            'note'             => 'Handwritten form fields extracted. Please review uncertain (amber) fields before approving.'
        ]);
    } else {
        // Standard Printed ID Document OCR
        $key = 'read:' . hash('sha256', $bytes);
        $r = DocVerify::recall($key);
        if ($r === null) {
            $g = DocVerify::readFields($bytes);
            $r = $g === null ? ['status' => 'unavailable', 'fields' => []] : DocVerify::fieldsFromRead($g);
            if (($r['status'] ?? '') !== 'unavailable') {
                DocVerify::remember($key, $r);
            }
        }

        Response::json([
            'ok'               => true,
            'status'           => $r['status'] ?? 'read',
            'handwritten'      => false,
            'fields'           => $r['fields'] ?? [],
            'confidence'       => [],
            'uncertain_fields' => [],
        ]);
    }
} catch (Throwable $e) {
    Logger::error('team-docread exception: ' . $e->getMessage());
    Response::error($e->getMessage(), 500);
}
