<?php
/**
 * =====================================================================
 *  whatsapp/webhook.php — Meta WhatsApp Cloud API webhook.
 *
 *  Meta Developer Console -> App -> WhatsApp -> Configuration:
 *      Callback URL : https://www.shreehariglobal.in/whatsapp/webhook.php
 *      Verify token : the value of whatsapp_webhook_verify_token (or env WA_WEBHOOK_TOKEN)
 *      Fields       : messages
 *
 *  GET  — Meta's one-time verification handshake (hub.challenge echo).
 *  POST — two kinds of event, both under field "messages":
 *    - statuses : sent / delivered / read / failed for messages WE sent.
 *                 Matched on message_logs.provider_ref (the wamid saved by
 *                 Notify::whatsapp()), exactly like api/twilio-status.php
 *                 does for Twilio — so the retry cron and the delivery
 *                 health check keep working on Meta.
 *    - messages : a customer wrote to us. Opens the 24 h window
 *                 (WaTemplates::noteInbound) and answers with the same PNR
 *                 bot the Twilio webhook uses (includes/wabot.php).
 *
 *  Security:
 *    - every POST must carry X-Hub-Signature-256 = HMAC-SHA256(raw body,
 *      App Secret). Fails CLOSED when no App Secret is configured.
 *    - only events for OUR phone number ID are processed: the WABA also
 *      holds the Twilio-connected +1 978 number, whose traffic Meta may
 *      copy here too.
 *    - Meta re-delivers events; inbound message ids are de-duplicated.
 *
 *  Always answers 200 "EVENT_RECEIVED" to an authentic POST (even when a
 *  step inside fails) so Meta never retry-storms; the answer is flushed
 *  before the bot reply is sent.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api.php';

/* ---------------------------------------------------------------
 *  GET — webhook verification. PHP turns "hub.mode" into "hub_mode".
 * ------------------------------------------------------------- */
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

/* ---------------------------------------------------------------
 *  POST — authenticate first.
 * ------------------------------------------------------------- */
$raw = (string) file_get_contents('php://input');
$sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');

if (META_APP_SECRET === '' || $sig === ''
    || !hash_equals('sha256=' . hash_hmac('sha256', $raw, META_APP_SECRET), $sig)) {
    Logger::warning('Meta WhatsApp webhook rejected', [
        'ip'     => Security::clientIp(),
        'reason' => META_APP_SECRET === '' ? 'no app secret configured' : 'signature mismatch',
    ], 'whatsapp');
    logWhatsAppEvent('webhook', '', [], ['status' => 'REJECTED', 'error' => META_APP_SECRET === '' ? 'no app secret' : 'bad signature']);
    http_response_code(403);
    exit;
}

$payload = json_decode($raw, true);

// Answer Meta now; everything below runs after the response is flushed.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'EVENT_RECEIVED';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
    exit;
}

/** Human-readable reason for the Meta error codes this business actually meets. */
function wh_hint(int $code): string
{
    return match ($code) {
        131047        => ' - more than 24 h since the customer last wrote; free text needs an approved template',
        131026        => ' - message undeliverable (number not on WhatsApp or old app version); call the passenger',
        132000, 132001, 132005, 132007, 132012 => ' - template missing / not approved / wrong variables',
        131049        => ' - Meta held it to protect the user experience (marketing limit)',
        131031        => ' - WhatsApp Business Account locked; see WhatsApp Manager',
        131042        => ' - payment method problem on the WhatsApp Business Account',
        130472        => ' - user is part of a Meta experiment; not delivered',
        131021        => ' - sender and recipient are the same number',
        default       => '',
    };
}

/** Apply one delivery status to the message_logs row that carries its wamid. */
function wh_status(array $st): void
{
    $wamid = (string) ($st['id'] ?? '');
    $state = strtolower((string) ($st['status'] ?? ''));
    $to    = (string) ($st['recipient_id'] ?? '');
    if ($wamid === '' || $state === '') {
        return;
    }

    $err     = $st['errors'][0] ?? [];
    $code    = (int) ($err['code'] ?? 0);
    $errText = trim((string) ($err['title'] ?? '') . ' ' . (string) ($err['error_data']['details'] ?? ''));
    logWhatsAppEvent('status', $to, [], ['status' => strtoupper($state), 'message_id' => $wamid,
        'error' => $code ? '(code ' . $code . ') ' . $errText : '']);

    // Same mapping as api/twilio-status.php: only terminal states rewrite a row.
    $final = match ($state) {
        'delivered', 'read' => 'sent',
        'failed'            => 'failed',
        default             => '',
    };
    if ($final === '') {
        return;
    }

    $row = Database::fetch(
        'SELECT id, booking_id FROM message_logs WHERE provider_ref = :ref ORDER BY id DESC LIMIT 1',
        ['ref' => $wamid]
    );
    if ($row === null) {
        return;
    }

    // "(code NNNNN)" is the exact text cron/whatsapp-retry.php matches on.
    $error = $final === 'failed'
        ? 'WhatsApp failed' . ($code ? ' (code ' . $code . ')' : '') . wh_hint($code) . ($errText !== '' ? ' · ' . $errText : '')
        : null;

    Database::update(
        'message_logs',
        ['status' => $final, 'error' => $error !== null ? mb_substr($error, 0, 255) : null],
        'id = :id',
        ['id' => (int) $row['id']]
    );

    if ($final === 'failed') {
        Logger::warning('WhatsApp delivery FAILED (Meta, async)', [
            'wamid' => $wamid, 'booking' => $row['booking_id'], 'code' => $code,
        ], 'whatsapp');
    }
}

/** A customer wrote to us: note the 24 h window and answer with the PNR bot. */
function wh_inbound(array $msg): void
{
    $from  = preg_replace('/\D/', '', (string) ($msg['from'] ?? '')) ?? '';
    $wamid = (string) ($msg['id'] ?? '');
    $type  = (string) ($msg['type'] ?? '');
    if ($from === '' || $wamid === '') {
        return;
    }

    // Meta re-delivers when it is unsure we got an event: answer each message once.
    if (!Security::rateLimit('wa_meta_seen', $wamid, 1, 172800)) {
        return;
    }

    $text = match ($type) {
        'text'        => (string) ($msg['text']['body'] ?? ''),
        'button'      => (string) ($msg['button']['text'] ?? ''),
        'interactive' => (string) ($msg['interactive']['button_reply']['title']
                                   ?? ($msg['interactive']['list_reply']['title'] ?? '')),
        default       => (string) ($msg[$type]['caption'] ?? ''),   // image/document captions
    };
    logWhatsAppEvent('inbound', $from, [], ['status' => strtoupper($type !== '' ? $type : 'unknown'), 'message_id' => $wamid]);

    try {
        require_once INCLUDE_PATH . '/watemplates.php';
        WaTemplates::noteInbound($from);
    } catch (Throwable $ignored) {
    }

    // Abuse guard, keyed on the sender — same budget as the Twilio bot.
    if (!Security::rateLimit('wa_bot', normalisePhone($from), 20, 60)) {
        return;
    }
    if (!Settings::getBool('whatsapp_bot_enabled', true)) {
        return;
    }

    require_once INCLUDE_PATH . '/wabot.php';
    $reply = WaBot::reply('+' . $from, $text);

    $media = $reply['media'];
    if ($media !== null && $media !== '' && mb_strlen($reply['text']) <= 1024) {
        $r = sendWhatsAppImage($from, $media, $reply['text']);
        if (!$r['success']) {
            // The image could not go (e.g. URL not reachable by Meta) — the
            // text still carries every fact the passenger asked for.
            $r = sendWhatsAppText($from, $reply['text']);
        }
    } else {
        $r = sendWhatsAppText($from, $reply['text']);
        if ($media !== null && $media !== '' && $r['success']) {
            sendWhatsAppImage($from, $media);
        }
    }

    Notify::logOutbound($from, $reply['text'], $r['success'] ? 'sent' : 'failed', [
        'provider' => 'cloud_api',
        'sid'      => $r['message_id'],
        'purpose'  => 'bot_reply',
        'error'    => $r['success'] ? null : $r['error'],
    ]);
}

try {
    foreach ((array) ($payload['entry'] ?? []) as $entry) {
        foreach ((array) ($entry['changes'] ?? []) as $change) {
            if (($change['field'] ?? '') !== 'messages') {
                continue;
            }
            $value   = (array) ($change['value'] ?? []);
            $phoneId = (string) ($value['metadata']['phone_number_id'] ?? '');
            if (META_PHONE_NUMBER_ID === '' || $phoneId !== META_PHONE_NUMBER_ID) {
                continue;   // another number on the same WABA (the Twilio sender)
            }
            foreach ((array) ($value['statuses'] ?? []) as $st) {
                try {
                    wh_status((array) $st);
                } catch (Throwable $e) {
                    Logger::exception($e, 'whatsapp');
                }
            }
            foreach ((array) ($value['messages'] ?? []) as $msg) {
                try {
                    wh_inbound((array) $msg);
                } catch (Throwable $e) {
                    Logger::exception($e, 'whatsapp');
                }
            }
        }
    }
} catch (Throwable $e) {
    Logger::exception($e, 'whatsapp');
}
