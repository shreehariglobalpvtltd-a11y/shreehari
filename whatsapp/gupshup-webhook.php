<?php
/**
 * =====================================================================
 *  whatsapp/gupshup-webhook.php — Gupshup Enterprise webhook receiver.
 *
 *  Gupshup Console -> App -> Webhook configuration:
 *      Callback URL : https://www.shreehariglobal.in/whatsapp/gupshup-webhook.php
 *      Signature    : X-Hub-Signature-256 (HMAC-SHA256 of the raw body with
 *                     the value of GUPSHUP_WEBHOOK_SECRET / gupshup_webhook_secret)
 *      Events       : message + message-event  (inbound + status)
 *
 *  Body shape (Gupshup v2 JSON):
 *    { "app":"<app>", "type":"message-event", "payload": {
 *        "id":"<gsId>", "type":"sent|delivered|read|failed|enqueued",
 *        "destination":"<E.164 digits>", "gsId":"...",
 *        "payload":{"code":<int>, "reason":"<text>"}   // on failed
 *      } }
 *    { "app":"<app>", "type":"message", "payload": {
 *        "id":"<gsId>", "source":"<E.164>", "type":"text|image|button|...",
 *        "payload":{"text":"..."}
 *      } }
 *
 *  Same mapping as whatsapp/webhook.php (Meta) and api/twilio-status.php
 *  (Twilio): match `message_logs.provider_ref` on the message id, flip
 *  status to `sent` on delivered/read, `failed` on failed. That is what
 *  keeps cron/whatsapp-retry.php and the delivery health check working
 *  across every driver.
 *
 *  Security:
 *    - When gupshup_webhook_secret is set the request must carry a valid
 *      HMAC-SHA256 signature (Gupshup mirrors Meta's X-Hub-Signature-256).
 *      When it is NOT set the endpoint fails CLOSED — the retry cron
 *      trusts these rows to re-send tickets, so an unauth signal is
 *      never accepted.
 *    - Duplicate deliveries are de-duplicated on the message id.
 *
 *  Always answers 200 to an authentic POST so Gupshup does not retry-storm.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/gupshup.php';

$raw = (string) file_get_contents('php://input');

/* Signature check — same scheme as whatsapp/webhook.php.
   HMAC-SHA256(raw body, GUPSHUP_WEBHOOK_SECRET), lowercase hex,
   compared with hash_equals. Fails closed when the secret is missing. */
$sig  = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256']
                  ?? ($_SERVER['HTTP_X_GUPSHUP_SIGNATURE']
                  ?? ($_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '')));
$expected = GUPSHUP_WEBHOOK_SECRET !== ''
    ? 'sha256=' . hash_hmac('sha256', $raw, GUPSHUP_WEBHOOK_SECRET)
    : '';

if (GUPSHUP_WEBHOOK_SECRET === '' || $sig === '' || !hash_equals($expected, $sig)) {
    Logger::warning('Gupshup webhook rejected', [
        'ip'     => Security::clientIp(),
        'reason' => GUPSHUP_WEBHOOK_SECRET === '' ? 'no webhook secret configured' : 'signature mismatch',
    ], 'whatsapp');
    logWhatsAppEvent('gs:webhook', '', [], ['status' => 'REJECTED',
        'error' => GUPSHUP_WEBHOOK_SECRET === '' ? 'no webhook secret' : 'bad signature']);
    http_response_code(403);
    exit;
}

$payload = json_decode($raw, true);

// Answer Gupshup now; everything below runs after the response is flushed.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'EVENT_RECEIVED';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if (!is_array($payload)) {
    exit;
}

$evType  = strtolower((string) ($payload['type'] ?? ''));
$body    = (array) ($payload['payload'] ?? []);
$appName = (string) ($payload['app'] ?? '');

// Only accept events for OUR Gupshup app when configured (an operator with
// several apps under one account can otherwise cross-post here).
if (GUPSHUP_APP_NAME !== '' && $appName !== '' && $appName !== GUPSHUP_APP_NAME) {
    exit;
}

/** Friendly reason for the Gupshup error codes seen most often. */
function gs_hint(int $code): string
{
    return match ($code) {
        1002    => ' - template not approved / not linked to this app',
        1004    => ' - template variable mismatch (wrong number of {{n}} params)',
        1005    => ' - template is paused',
        1013    => ' - number is not on WhatsApp; call the passenger',
        1014    => ' - free-form message outside the 24 h customer-service window',
        62      => ' - session is closed; needs a template message',
        66      => ' - template not found',
        default => '',
    };
}

/** Apply one delivery status to the message_logs row that carries its id. */
function gs_status(array $st, array $eventOuter): void
{
    $gsId  = (string) ($st['id'] ?? ($eventOuter['id'] ?? ($st['gsId'] ?? '')));
    $state = strtolower((string) ($st['type'] ?? ''));
    $to    = (string) ($st['destination'] ?? '');
    if ($gsId === '' || $state === '') {
        return;
    }

    $err     = (array) ($st['payload'] ?? []);
    $code    = (int) ($err['code'] ?? 0);
    $errText = trim((string) ($err['reason'] ?? ($err['message'] ?? '')));
    logWhatsAppEvent('gs:status', $to, [], ['status' => strtoupper($state), 'message_id' => $gsId,
        'error' => $code ? '(code ' . $code . ') ' . $errText : '']);

    // Same mapping as api/twilio-status.php and whatsapp/webhook.php.
    $final = match ($state) {
        'delivered', 'read' => 'sent',
        'failed'            => 'failed',
        default             => '',
    };
    if ($final === '') {
        return;
    }

    $row = Database::fetch(
        'SELECT id, booking_id, purpose, to_number FROM message_logs WHERE provider_ref = :ref ORDER BY id DESC LIMIT 1',
        ['ref' => $gsId]
    );
    if ($row === null) {
        return;
    }

    // "(code NNNNN)" matches the same regex cron/whatsapp-retry.php reads
    // on Twilio / Meta errors — one shape for every driver.
    $error = $final === 'failed'
        ? 'WhatsApp failed' . ($code ? ' (code ' . $code . ')' : '') . gs_hint($code) . ($errText !== '' ? ' · ' . $errText : '')
        : null;

    Database::update(
        'message_logs',
        ['status' => $final, 'error' => $error !== null ? mb_substr($error, 0, 255) : null],
        'id = :id',
        ['id' => (int) $row['id']]
    );

    if ($final === 'failed') {
        Logger::warning('WhatsApp delivery FAILED (Gupshup, async)', [
            'gsId' => $gsId, 'booking' => $row['booking_id'], 'code' => $code,
        ], 'whatsapp');
        /* 26 Sep 2026: the office gets the ticket to forward by hand. */
        Notify::deliveryFallback((int) ($row['booking_id'] ?? 0), (string) ($row['to_number'] ?? ''),
            (string) ($row['purpose'] ?? ''), 'Gupshup: ' . (string) $error);
    }
}

/** A customer wrote to us: note the 24 h window and answer with the PNR bot. */
function gs_inbound(array $msg): void
{
    $from  = preg_replace('/\D/', '', (string) ($msg['source'] ?? '')) ?? '';
    $gsId  = (string) ($msg['id'] ?? '');
    $type  = (string) ($msg['type'] ?? '');
    if ($from === '' || $gsId === '') {
        return;
    }

    // De-duplicate — same reason the Meta webhook does it.
    if (!Security::rateLimit('wa_gs_seen', $gsId, 1, 172800)) {
        return;
    }

    $inner = (array) ($msg['payload'] ?? []);
    $text  = match ($type) {
        'text'   => (string) ($inner['text'] ?? ''),
        'button' => (string) ($inner['text'] ?? ($inner['title'] ?? '')),
        'list_reply', 'quick_reply' => (string) ($inner['title'] ?? ($inner['text'] ?? '')),
        default  => (string) ($inner['caption'] ?? ''),  // image/document
    };
    logWhatsAppEvent('gs:inbound', $from, [], ['status' => strtoupper($type !== '' ? $type : 'unknown'), 'message_id' => $gsId]);

    try {
        require_once INCLUDE_PATH . '/watemplates.php';
        WaTemplates::noteInbound($from);
    } catch (Throwable $ignored) {
    }

    // Same abuse guard as the Meta / Twilio bots.
    if (!Security::rateLimit('wa_bot', normalisePhone($from), 20, 60)) {
        return;
    }
    if (!Settings::getBool('whatsapp_bot_enabled', true)) {
        return;
    }

    require_once INCLUDE_PATH . '/wabot.php';
    $reply = WaBot::reply('+' . $from, $text);

    // Send back through the same driver — Gupshup — so the reply lives in
    // the same session and its delivery status comes back to this webhook.
    $media = $reply['media'];
    if ($media !== null && $media !== '' && mb_strlen($reply['text']) <= 1024) {
        $r = sendGupshupImage($from, $media, $reply['text']);
        if (!$r['success']) {
            $r = sendGupshupText($from, $reply['text']);
        }
    } else {
        $r = sendGupshupText($from, $reply['text']);
        if ($media !== null && $media !== '' && $r['success']) {
            sendGupshupImage($from, $media);
        }
    }

    Notify::logOutbound($from, $reply['text'], $r['success'] ? 'sent' : 'failed', [
        'provider' => 'gupshup',
        'sid'      => $r['message_id'],
        'purpose'  => 'bot_reply',
        'error'    => $r['success'] ? null : $r['error'],
    ]);
}

try {
    if ($evType === 'message-event' || $evType === 'user-event') {
        // Some Gupshup accounts send the outer object as the event itself,
        // others wrap the fields under 'payload'. Handle both.
        if (isset($body['type'])) {
            gs_status($body, $payload);
        } elseif (isset($payload['payload']['type'])) {
            gs_status((array) $payload['payload'], $payload);
        }
    } elseif ($evType === 'message') {
        gs_inbound($body);
    }
} catch (Throwable $e) {
    Logger::exception($e, 'whatsapp');
}
