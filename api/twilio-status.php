<?php
/**
 * =====================================================================
 *  api/twilio-status.php — Twilio delivery status callback.
 *
 *  Twilio POSTs here every time a message we sent changes state. It is
 *  what turns message_logs from a record of ATTEMPTS into a record of
 *  DELIVERIES, and it exists because the send call cannot tell us:
 *  creating a message answers 2xx the moment Twilio accepts it, while
 *  WhatsApp decides seconds later. On this account 19 of the last 50
 *  messages came back failed 63112 ("Meta disabled the WhatsApp Business
 *  Account connected to this Sender") AFTER a clean 201 — every one of
 *  them recorded as a successful ticket.
 *
 *  Rows are matched on provider_ref, the Twilio message SID stored by
 *  Notify::whatsapp(). cron/whatsapp-retry.php then re-sends whatever
 *  ends up 'failed', so a ticket lost to a broken sender reaches the
 *  passenger by itself once the sender works again.
 *
 *  Security: same HMAC-SHA1 signature check as the inbound webhook. The
 *  URL must match byte for byte what was sent as StatusCallback — the
 *  www/non-www 301 breaks it, so Notify::statusCallbackUrl() and the
 *  twilio_status_callback_url override must agree.
 *
 *  Always answers 204 so Twilio never retry-storms, even on a request we
 *  reject or cannot match.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

/** Answer Twilio and stop. Never a body — Twilio ignores it either way. */
function ts_done(string $note = '', array $ctx = []): never
{
    if ($note !== '') {
        Logger::info('Twilio status callback: ' . $note, $ctx, 'whatsapp');
    }
    http_response_code(204);
    exit;
}

/** Same scheme as api/whatsapp-webhook.php: HMAC-SHA1 over URL + sorted params. */
function ts_valid_signature(string $authToken): bool
{
    $sig = (string) ($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '');
    if ($sig === '') {
        return false;
    }

    $override = trim(Settings::getString('twilio_status_callback_url', ''));
    if ($override !== '') {
        $url = $override;
    } else {
        $scheme = Security::isHttps() ? 'https' : 'http';
        $url    = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '') . (string) ($_SERVER['REQUEST_URI'] ?? '');
    }

    $params = $_POST;
    ksort($params);

    $data = $url;
    foreach ($params as $key => $value) {
        $data .= $key . (is_array($value) ? implode('', $value) : (string) $value);
    }

    return hash_equals(base64_encode(hash_hmac('sha1', $data, $authToken, true)), $sig);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    ts_done();
}

// Fail closed, exactly like the inbound webhook: with no auth token there is
// no way to tell Twilio from anyone else, and these rows drive re-sends.
$authToken = Settings::getString('twilio_auth_token', '');
if ($authToken === '' || !ts_valid_signature($authToken)) {
    Logger::warning('Twilio status callback rejected (bad signature)', [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ], 'whatsapp');
    http_response_code(403);
    exit;
}

$sid    = trim((string) ($_POST['MessageSid'] ?? ''));
$state  = strtolower(trim((string) ($_POST['MessageStatus'] ?? '')));
$errNum = trim((string) ($_POST['ErrorCode'] ?? ''));

if ($sid === '' || $state === '') {
    ts_done();
}

// queued/sending/sent/accepted are all "still in flight" — only the terminal
// states are worth rewriting a row for. 'read' and 'delivered' are the only
// two that prove a human could see the ticket.
$final = match ($state) {
    'delivered', 'read'      => 'sent',
    'failed', 'undelivered'  => 'failed',
    default                  => '',
};
if ($final === '') {
    ts_done();
}

$row = Database::fetch(
    'SELECT id, booking_id, status FROM message_logs WHERE provider_ref = :ref ORDER BY id DESC LIMIT 1',
    ['ref' => $sid]
);
if ($row === null) {
    // Not ours, or sent before provider_ref was stored. Nothing to correct.
    ts_done('no message_logs row for SID', ['sid' => $sid, 'state' => $state]);
}

$error = null;
if ($final === 'failed') {
    $error = 'WhatsApp ' . $state . ($errNum !== '' ? ' (code ' . $errNum . ')' : '');
    // The two that actually explain a dead ticket on this account.
    if ($errNum === '63112') {
        $error .= ' - Meta disabled the WhatsApp Business Account';
    } elseif ($errNum === '63016') {
        $error .= ' - no approved template for a business-initiated message';
    } elseif ($errNum === '63024' || $errNum === '63003') {
        $error .= ' - this number is not on WhatsApp; call the passenger';
    }
}

Database::update(
    'message_logs',
    ['status' => $final, 'error' => $error !== null ? mb_substr($error, 0, 255) : null],
    'id = :id',
    ['id' => (int) $row['id']]
);

if ($final === 'failed') {
    Logger::warning('WhatsApp delivery FAILED (async)', [
        'sid'     => $sid,
        'booking' => $row['booking_id'],
        'code'    => $errNum,
    ], 'whatsapp');
}

ts_done();
