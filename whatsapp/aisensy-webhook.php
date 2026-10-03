<?php
/**
 * =====================================================================
 *  whatsapp/aisensy-webhook.php — AiSensy inbound -> our WhatsApp AI bot.
 *
 *  3 Oct 2026. A customer writes to the AiSensy number (+91 91570 01507);
 *  AiSensy POSTs the event here, the SAME bot the Meta webhook uses
 *  (WaBot::reply — PNR lookup, QuickBot booking, staff login, Hindi /
 *  Nepali / Gujarati parsing) builds the answer, and it goes back through
 *  AiSensyAPI::sendText / sendImage. whatsapp/webhook.php (Meta) is not
 *  touched; the two numbers run side by side.
 *
 *  AiSensy dashboard -> Manage -> Webhooks (project API cannot register it):
 *      URL    : https://www.shreehariglobal.in/whatsapp/aisensy-webhook.php?token=<aisensy_webhook_token>
 *      Topics : the inbound "message received / sender user" topic
 *               (status topics are accepted and only logged)
 *
 *  Security:
 *    - ?token= must equal AISENSY_WEBHOOK_TOKEN (.env / settings
 *      aisensy_webhook_token). Fails CLOSED when no token is configured.
 *    - when aisensy_webhook_secret is set, X-AiSensy-Signature must be
 *      HMAC-SHA256(raw body, secret) as hex or base64.
 *    - inbound ids are de-duplicated; same per-sender rate limit as Meta.
 *    - only a message whose sender is the USER is answered — never our own
 *      outgoing echo, so the bot can never talk to itself.
 *    - an event shape we do not recognise is logged and NOT answered.
 *
 *  Always answers 200 to an authentic POST, flushed before the bot runs.
 *  Raw events: logs/aisensy-webhook.log (nginx denies *.log).
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/aisensy.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token  = AiSensyAPI::config('AISENSY_WEBHOOK_TOKEN', 'aisensy_webhook_token');
$given  = (string) ($_GET['token'] ?? '');

if ($token === '' || !hash_equals($token, $given)) {
    http_response_code(403);
    exit;
}
if ($method === 'GET' || $method === 'HEAD') {
    // URL check from the dashboard.
    header('Content-Type: text/plain; charset=utf-8');
    echo 'OK';
    exit;
}
if ($method !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = (string) file_get_contents('php://input');

$secret = AiSensyAPI::config('AISENSY_WEBHOOK_SECRET', 'aisensy_webhook_secret');
if ($secret !== '') {
    $sig = trim((string) ($_SERVER['HTTP_X_AISENSY_SIGNATURE'] ?? ''));
    $bin = hash_hmac('sha256', $raw, $secret, true);
    $ok  = $sig !== '' && (hash_equals(bin2hex($bin), strtolower(preg_replace('/^sha256=/i', '', $sig) ?? ''))
                        || hash_equals(base64_encode($bin), $sig));
    if (!$ok) {
        ais_wh_log('REJECTED bad signature', $raw);
        http_response_code(403);
        exit;
    }
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

/** One raw-event line in logs/aisensy-webhook.log (truncated). */
function ais_wh_log(string $what, string $raw): void
{
    try {
        $dir = defined('LOG_PATH') ? LOG_PATH : dirname(__DIR__) . '/logs';
        @file_put_contents($dir . '/aisensy-webhook.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $what . ' | ' . str_replace(["\r", "\n"], ' ', mb_substr($raw, 0, 3000)) . "\n",
            FILE_APPEND | LOCK_EX);
    } catch (Throwable $ignored) {
    }
}

/** First non-empty string found at any of the dotted paths. */
function ais_pick(array $a, array $paths): string
{
    foreach ($paths as $path) {
        $v = $a;
        foreach (explode('.', $path) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                $v = null;
                break;
            }
            $v = $v[$k];
        }
        if (is_string($v) || is_int($v) || is_float($v)) {
            $s = trim((string) $v);
            if ($s !== '') {
                return $s;
            }
        }
    }
    return '';
}

/**
 * Normalise the event to {from, text, id, type, sender, topic}, or null.
 * Accepts AiSensy's project webhook shape (topic + data.message) and a
 * Meta-style forward (entry[].changes[].value.messages[]).
 */
function ais_parse(array $p): ?array
{
    $topic = strtolower(ais_pick($p, ['topic', 'event', 'type']));

    // Meta-style forward
    $m = $p['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
    if (is_array($m)) {
        $type = (string) ($m['type'] ?? 'text');
        return [
            'from'   => ais_pick($m, ['from']),
            'text'   => ais_pick($m, ['text.body', 'button.text', 'interactive.button_reply.title', 'interactive.list_reply.title', $type . '.caption']),
            'id'     => ais_pick($m, ['id']),
            'type'   => $type,
            'sender' => 'user',
            'topic'  => $topic !== '' ? $topic : 'meta.messages',
        ];
    }

    $msg = $p['data']['message'] ?? ($p['data'] ?? ($p['message'] ?? $p));
    if (!is_array($msg)) {
        return null;
    }
    $sender = strtolower(ais_pick($msg, ['sender', 'sender_type', 'senderType', 'direction', 'from_type']));
    return [
        'from'   => ais_pick($msg, ['phone_number', 'phoneNumber', 'from', 'waId', 'wa_id', 'mobile', 'contact.phone_number', 'contact.phone', 'userNumber']),
        'text'   => ais_pick($msg, ['message_content.text', 'message_content.body', 'message_content.caption', 'text.body', 'text', 'body', 'message', 'content.text']),
        'id'     => ais_pick($msg, ['id', 'message_id', 'messageId', 'wamid', '_id']),
        'type'   => strtolower(ais_pick($msg, ['message_type', 'messageType', 'type']) ?: 'text'),
        'sender' => $sender,
        'topic'  => $topic,
    ];
}

try {
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        ais_wh_log('IGNORED not json', $raw);
        exit;
    }
    $ev = ais_parse($payload);

    // Status / delivery events and anything that is not a customer message: log only.
    $isUser = $ev !== null
        && (str_contains((string) $ev['topic'], 'sender.user')
            || in_array($ev['sender'], ['user', 'customer', 'contact', 'inbound', 'incoming'], true));
    if ($ev === null || !$isUser || $ev['from'] === '' || str_contains((string) $ev['topic'], 'status')) {
        ais_wh_log('LOGGED ' . ($ev['topic'] ?? '?') . ' sender=' . ($ev['sender'] ?? '?'), $raw);
        exit;
    }
    ais_wh_log('INBOUND ' . $ev['topic'] . ' from=' . $ev['from'] . ' type=' . $ev['type'], $raw);

    $from = AiSensyAPI::normalizePhone($ev['from']);
    if ($from === '') {
        exit;
    }
    // AiSensy may re-deliver: answer each message once.
    $dedupe = $ev['id'] !== '' ? $ev['id'] : sha1($from . '|' . $ev['text'] . '|' . date('YmdHi'));
    if (!Security::rateLimit('ais_seen', $dedupe, 1, 172800)) {
        exit;
    }
    if (!Security::rateLimit('wa_bot', normalisePhone($from), 20, 60)) {
        exit;
    }
    if (!Settings::getBool('whatsapp_bot_enabled', true) || !Settings::getBool('aisensy_bot_enabled', true)) {
        exit;
    }

    require_once INCLUDE_PATH . '/notify.php';
    require_once INCLUDE_PATH . '/wabot.php';
    $kind  = in_array($ev['type'], ['text', 'button', 'interactive'], true) ? 'text' : $ev['type'];
    $reply = WaBot::reply('+' . $from, $ev['text'], $kind, []);

    $text  = (string) ($reply['text'] ?? '');
    $media = (string) ($reply['media'] ?? '');
    if ($media !== '' && mb_strlen($text) <= 1024) {
        $r = AiSensyAPI::sendImage($from, $media, $text);
        if (!$r['success']) {
            $r = AiSensyAPI::sendText($from, $text);
        }
    } else {
        $r = AiSensyAPI::sendText($from, $text);
        if ($media !== '' && $r['success']) {
            AiSensyAPI::sendImage($from, $media);
        }
    }

    Notify::logOutbound($from, $text, $r['success'] ? 'sent' : 'failed', [
        'provider' => 'aisensy',
        'sid'      => $r['message_id'],
        'purpose'  => 'bot_reply',
        'error'    => $r['success'] ? null : mb_substr((string) $r['error'], 0, 250),
    ]);
} catch (Throwable $e) {
    Logger::exception($e, 'whatsapp');
}
