<?php
/**
 * POST /api/wa-verify.php — "send hi on WhatsApp" number check for forms.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/waverify.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        Response::error('This endpoint takes POST.', 405);
    }
    Security::requireCsrf();
    Response::$maxInputBytes = 4000;

    $ip = Security::clientIp();
    $action = (string) Response::field('action', '');

    if ($action === 'start') {
        $purpose = (string) Response::field('purpose', 'staff');
        if (!in_array($purpose, WaVerify::FORM_PURPOSES, true)) {
            Response::error('Unknown purpose.', 400);
        }
        $cc = (string) Response::field('cc', '91');
        $country = $cc === '977' ? 'NP' : 'IN';
        $phone = normalisePhone((string) Response::field('phone', ''));
        if (strlen($phone) !== 10 || !Security::isValidPhone($phone) || isPlaceholderPhone($phone)) {
            Response::invalid(['phone' => 'number'], '१० अङ्कको मोबाइल नम्बर लेख्नुहोस् · Enter a 10-digit mobile number.');
        }
        Security::requireRateLimit('wa_verify_ip', $ip, 12, 600);
        Security::requireRateLimit('wa_verify_phone', $phone, 6, 600);
        $s = WaVerify::start($purpose, $phone, $country, $ip);
        try {
            require_once INCLUDE_PATH . '/qr.php';
            $s['qr'] = QrCode::dataUri('https://wa.me/' . $s['to'] . '?text=' . rawurlencode($s['text']), 5, 2);
        } catch (Throwable $e) {
            $s['qr'] = '';
        }
        Response::json(['ok' => true] + $s);
    }

    if ($action === 'check') {
        Security::requireRateLimit('wa_verify_check', $ip, 180, 600);
        $token = (string) Response::field('token', '');
        $r = WaVerify::check($token);
        Response::json(['ok' => true, 'status' => $r['status']]);
    }

    Response::error('Unknown action.', 400);
} catch (Throwable $e) {
    Logger::error('wa-verify error: ' . $e->getMessage());
    Response::error($e->getMessage(), 500);
}
