<?php
/**
 * =====================================================================
 *  api/payment-webhook.php — signed payment gateway / bank webhook.
 *  26 Sep 2026 (Prompt 2 section 5). OFF until settings.payment_webhook_on.
 *
 *  POST, JSON body, header X-Signature (or X-Hub-Signature-256):
 *      hex HMAC-SHA256 of the raw body, keyed by the env var
 *      SHG_PAYMENT_WEBHOOK_SECRET (or settings.payment_webhook_secret),
 *      optionally prefixed "sha256=".
 *  Body fields (common spellings accepted, see PayWebhook::normalise):
 *      event_id, utr, amount, status, reference (the PNR, when the
 *      gateway carries our order reference).
 *
 *  Answers:
 *      404  switched off (the URL does not exist as far as callers know)
 *      403  bad or missing signature (nothing said about why)
 *      200  {"ok":true,"outcome":...} for everything accepted, including
 *           duplicates, so a gateway never retry-storms us.
 *
 *  Only PayWebhook decides what the money means. See includes/paywebhook.php
 *  for the rule: nothing here confirms a booking unless the office switched
 *  auto-confirm on, and the default is a "possible match" for a person.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/paywebhook.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$answer = static function (int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body);
    exit;
};

try {
    if (!PayWebhook::enabled()) {
        $answer(404, ['ok' => false]);
    }
    if (!Security::isPost()) {
        $answer(405, ['ok' => false]);
    }

    Security::requireRateLimit('pay_webhook', Security::clientIp(), 120, 60);

    $raw = (string) file_get_contents('php://input', false, null, 0, 65536);
    $sig = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');

    if (!PayWebhook::verifySignature($raw, $sig)) {
        Logger::warning('Payment webhook rejected', ['ip' => Security::clientIp()], 'payment');
        $answer(403, ['ok' => false]);
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $answer(200, ['ok' => true, 'outcome' => 'invalid']);
    }

    $provider = Settings::getString('payment_webhook_provider', 'generic');
    $res      = PayWebhook::process($provider !== '' ? $provider : 'generic', $payload, $raw);
    $answer(200, ['ok' => true, 'outcome' => $res['outcome']]);
} catch (Throwable $e) {
    // Acknowledge quietly: the event row (if written) keeps it for the office.
    Logger::exception($e);
    $answer(200, ['ok' => true, 'outcome' => 'error']);
}
