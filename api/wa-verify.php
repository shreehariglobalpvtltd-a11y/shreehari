<?php
/**
 * POST /api/wa-verify.php — "send hi on WhatsApp" number check for the
 * staff form, the agent form and the staff agreement's sign box
 * (22 Oct 2026, includes/waverify.php).
 *
 *   X-CSRF-Token (the session token the page already has from
 *   /api/staff-apply.php or /api/agreement-apply.php), application/json:
 *
 *   {"action":"start","purpose":"staff"|"agent"|"sign","phone":"98…","cc":"91"|"977"}
 *     200 {"ok":true,"token":"K7Q2M9","url":"https://wa.me/…?text=hi%20VERIFY%20K7Q2M9","text":"hi VERIFY K7Q2M9"}
 *   {"action":"check","token":"K7Q2M9"}
 *     200 {"ok":true,"status":"verified"|"waiting"|"mismatch"|"expired"|"unknown"}
 *
 * Budgets: 8 starts per 10 minutes per IP, 4 per number per 10 minutes;
 * checks are cheap (the page asks every few seconds while waiting).
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
    if (!WaVerify::ready()) {
        Response::error('WhatsApp check is not set up yet.', 503);
    }
    $ip = Security::clientIp();
    $action = (string) Response::field('action', '');

    if ($action === 'start') {
        $cc = (string) Response::field('cc', '91');
        $country = $cc === '977' ? 'NP' : 'IN';
        $phone = normalisePhone((string) Response::field('phone', ''));
        if (strlen($phone) !== 10 || !Security::isValidPhone($phone) || isPlaceholderPhone($phone)) {
            Response::invalid(['phone' => 'number'], '१० अङ्कको मोबाइल नम्बर लेख्नुहोस् · Enter a 10-digit mobile number.');
        }
        Security::requireRateLimit('wa_verify_ip', $ip, 8, 600);
        Security::requireRateLimit('wa_verify_phone', $phone, 4, 600);
        $s = WaVerify::start((string) Response::field('purpose', 'staff'), $phone, $country, $ip);
        Response::json(['ok' => true] + $s);
    }

    if ($action === 'check') {
        Security::requireRateLimit('wa_verify_check', $ip, 120, 600);
        $r = WaVerify::check((string) Response::field('token', ''));
        Response::json(['ok' => true, 'status' => $r['status']]);
    }

    Response::error('Unknown action.', 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
