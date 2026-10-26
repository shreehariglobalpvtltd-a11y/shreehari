<?php
/**
 * ICICI Payment Gateway - Webhook / Payment Advice (joined to bookings, 6 Oct 2026)
 * shreehariglobal.in
 *
 * Server-to-server: works even if the customer closes the browser.
 * URL: https://www.shreehariglobal.in/payment/payment_webhook.php
 * Shares icici_apply_result() with the return page, so whichever arrives
 * first records the payment and the other is a no-op.
 */

declare(strict_types=1);

require_once __DIR__ . '/icici_booking.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$rawBody     = (string) file_get_contents('php://input');
$webhookData = json_decode($rawBody, true);
if (!is_array($webhookData) || $webhookData === []) {
    $webhookData = $_POST; // form-encoded
}
if ($webhookData === []) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

icici_log('WEBHOOK_RECEIVED', $webhookData);

$r = icici_apply_result($webhookData, 'WEBHOOK');
if ($r['state'] === 'invalid') {
    http_response_code(403);
    echo json_encode(['status' => 'rejected']);
    exit;
}

// ICICI expects 200, otherwise it retries.
http_response_code(200);
echo json_encode(['status' => 'received', 'merchantTxnNo' => (string) ($webhookData['merchantTxnNo'] ?? '')]);
