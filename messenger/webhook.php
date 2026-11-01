<?php
/**
 * =====================================================================
 *  messenger/webhook.php — Meta Facebook Messenger webhook.
 *
 *  Meta Developer Console -> App -> Messenger -> Settings:
 *      Callback URL : https://www.shreehariglobal.in/messenger/webhook.php
 *      Verify token : the value of fb_verify_token (admin Settings)
 *      Subscribe    : messages, messaging_postbacks
 *
 *  GET  — Meta's one-time verification handshake (hub.challenge echo).
 *  POST — object "page": entry[].messaging[]. A customer wrote to the Page;
 *         the text goes to the SAME bot the WhatsApp webhook uses
 *         (WaBot::reply) and the answer is sent back with the Messenger
 *         Send API (includes/messenger.php).
 *
 *  Security — every POST must carry X-Hub-Signature-256 = HMAC-SHA256(raw
 *  body, App Secret); fails CLOSED when no App Secret is configured. A
 *  redelivered message id is answered once. The whole channel is behind the
 *  messenger_bot_enabled switch (default OFF), so nothing runs until the
 *  owner turns it on and pastes the Page token + App Secret in Settings.
 *
 *  Note — a Messenger sender is a page-scoped id (PSID), not a phone, so the
 *  bot answers questions and guides the booking; the PSID can never become a
 *  ticket's phone (QuickTicket::sellCustomer rejects a non-phone), so a
 *  Messenger chat cannot mis-book to a wrong number.
 * =====================================================================
 */
declare(strict_types=1);

defined('SHG_APP') || define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/messenger.php';

/* ---------------------------------------------------------------
 *  GET — webhook verification.
 * ------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $challenge = Messenger::verifyChallenge(
        (string) ($_GET['hub_mode'] ?? ''),
        (string) ($_GET['hub_verify_token'] ?? ''),
        (string) ($_GET['hub_challenge'] ?? '')
    );
    if ($challenge !== null) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

/* ---------------------------------------------------------------
 *  POST — authenticate first (fails closed without an App Secret).
 * ------------------------------------------------------------- */
$raw = (string) file_get_contents('php://input');
$sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');

if (!Messenger::verifySignature($raw, $sig)) {
    Logger::warning('Messenger webhook rejected', [
        'ip'     => Security::clientIp(),
        'reason' => Messenger::appSecret() === '' ? 'no app secret configured' : 'signature mismatch',
    ], 'messenger');
    http_response_code(403);
    exit;
}

$payload = json_decode($raw, true);

// Answer Meta now; the bot reply runs after the response is flushed.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'EVENT_RECEIVED';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if (!is_array($payload) || ($payload['object'] ?? '') !== 'page') {
    exit;
}
// Switched off (or no Page token) — accept the event, answer nothing.
if (!Messenger::enabled()) {
    exit;
}

/** Answer one Messenger message with the shared PNR / booking bot. */
function msgr_handle(array $m): void
{
    $psid = (string) ($m['sender']['id'] ?? '');
    $mid  = (string) ($m['message']['mid'] ?? ($m['postback']['mid'] ?? ''));
    $text = trim(Messenger::textOf($m));
    if ($psid === '' || $text === '') {
        return;   // a receipt, an echo, or an attachment with no caption
    }

    // Answer a redelivered event only once.
    if ($mid !== '' && !Security::rateLimit('msgr_seen', $mid, 1, 86400)) {
        return;
    }
    // Abuse guard, keyed on the sender — same budget as the WhatsApp bot.
    if (!Security::rateLimit('msgr_bot', $psid, 20, 60)) {
        return;
    }

    try {
        require_once INCLUDE_PATH . '/wabot.php';
        // The PSID is the conversation key. It is not a phone, so the booking
        // bot will ask for a mobile before it can issue a ticket, and can
        // never book to the PSID (QuickTicket::sellCustomer rejects it).
        $reply = WaBot::reply($psid, $text, 'text', []);
        $r = Messenger::send($psid, (string) ($reply['text'] ?? ''), $reply['media'] ?? null);

        Notify::logOutbound($psid, (string) ($reply['text'] ?? ''), $r['success'] ? 'sent' : 'failed', [
            'provider' => 'messenger',
            'sid'      => $r['message_id'] ?? '',
            'purpose'  => 'bot_reply',
            'error'    => $r['success'] ? null : ($r['error'] ?? ''),
        ]);
    } catch (Throwable $e) {
        Logger::exception($e, 'messenger');
    }
}

try {
    foreach ((array) ($payload['entry'] ?? []) as $entry) {
        foreach ((array) ($entry['messaging'] ?? []) as $m) {
            try {
                msgr_handle((array) $m);
            } catch (Throwable $e) {
                Logger::exception($e, 'messenger');
            }
        }
    }
} catch (Throwable $e) {
    Logger::exception($e, 'messenger');
}
