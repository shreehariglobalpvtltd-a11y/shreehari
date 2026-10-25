<?php
/**
 * =====================================================================
 *  whatsapp/webhook.php — Meta WhatsApp Cloud API webhook.
 *
 *  Handles:
 *    - Inbound status receipts (sent / delivered / read / failed)
 *    - Automatically updates CardDelivery status when card messages are delivered
 *    - Number verification inbound "hi VERIFY ..." (WaVerify::inbound)
 * =====================================================================
 */

declare(strict_types=1);

defined('SHG_APP') || define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api.php';
require_once INCLUDE_PATH . '/waverify.php';
require_once INCLUDE_PATH . '/carddelivery.php';

/* GET — webhook verification handshake */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $mode      = (string) ($_GET['hub_mode'] ?? '');
    $token     = (string) ($_GET['hub_verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? '');

    if ($mode === 'subscribe' && WHATSAPP_WEBHOOK_TOKEN !== ''
        && hash_equals(WHATSAPP_WEBHOOK_TOKEN, $token)
        && preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $challenge) === 1) {
        logWhatsAppEvent('verify', '', [], ['status' => 'OK']);
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }
    if ($mode !== '') {
        logWhatsAppEvent('verify', '', [], ['status' => 'FAIL', 'error' => 'verify token mismatch']);
    }
    http_response_code(403);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = (string) file_get_contents('php://input');
$sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');

if (META_APP_SECRET !== '' && ($sig === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, META_APP_SECRET), $sig))) {
    Logger::warning('Meta WhatsApp webhook rejected: bad signature', ['ip' => Security::clientIp()], 'whatsapp');
    http_response_code(403);
    exit;
}

$payload = json_decode($raw, true);

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'EVENT_RECEIVED';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
    exit;
}

foreach (($payload['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $val = $change['value'] ?? [];

        // 1. Delivery Status updates
        if (!empty($val['statuses']) && is_array($val['statuses'])) {
            foreach ($val['statuses'] as $st) {
                $wamid = (string) ($st['id'] ?? '');
                $state = strtolower((string) ($st['status'] ?? ''));
                $to    = (string) ($st['recipient_id'] ?? '');

                logWhatsAppEvent('status', $to, [], ['status' => strtoupper($state), 'message_id' => $wamid]);

                if ($state === 'delivered' || $state === 'read') {
                    CardDelivery::markDelivered($wamid);
                }
            }
        }

        // 2. Inbound messages
        if (!empty($val['messages']) && is_array($val['messages'])) {
            foreach ($val['messages'] as $msg) {
                $from = (string) ($msg['from'] ?? '');
                $text = (string) ($msg['text']['body'] ?? '');

                if ($text !== '' && WaVerify::isVerifyText($text)) {
                    $reply = WaVerify::inbound($from, $text);
                    if ($reply !== null) {
                        sendWhatsAppText($from, $reply);
                    }
                }
            }
        }
    }
}
