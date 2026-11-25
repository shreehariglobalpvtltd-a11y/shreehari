<?php
/**
 * payment/bank-webhook.php — the India bank / UPI API payment notice (25 Nov 2026, mp1 "pay").
 *
 * Give the bank: https://www.shreehariglobal.in/payment/bank-webhook.php
 * (Admin → 💳 Payment Management → 🇮🇳 shows the exact address).
 *
 * Every check lives in BankApi::handleWebhook() (includes/bankapi.php): POST only, HMAC-SHA256
 * of the raw body with the webhook secret (constant-time), optional 5-minute timestamp window,
 * merchant id, success status, then a bank_credits row (sandbox = 'test', never confirms) and
 * PayCredits::tryAutoConfirm(). Always a quick JSON answer; 401 on a bad signature.
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    define('SHG_APP', true);
}
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/bankapi.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $r = BankApi::handleWebhook(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string) file_get_contents('php://input'),
        BankApi::requestHeaders(),
        Security::clientIp()
    );
} catch (Throwable $e) {
    Logger::warning('Bank webhook error', ['err' => $e->getMessage()], 'payment');
    $r = ['code' => 500, 'body' => ['ok' => false, 'error' => 'server error']];
}
if ($r['code'] === 405) {
    header('Allow: POST');
}
http_response_code($r['code']);
echo json_encode($r['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
