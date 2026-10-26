<?php
/**
 * =====================================================================
 *  api/whatsapp-webhook.php — inbound WhatsApp bot (Twilio).
 *
 *  Point your Twilio WhatsApp Sandbox / Sender "WHEN A MESSAGE COMES IN"
 *  webhook (HTTP POST) at:
 *
 *      https://yourdomain.com/api/whatsapp-webhook.php
 *
 *  A customer messages your WhatsApp number with their PNR and instantly
 *  gets back the booking status — plus the ticket PDF when the booking is
 *  confirmed and they message from the number used at booking.
 *
 *  Security:
 *   - Every request is checked against the Twilio X-Twilio-Signature
 *     header (HMAC-SHA1 over the URL + sorted params, keyed by the auth
 *     token). A forged request is rejected with 403.
 *   - Full passenger detail + the PDF are only released to the sender when
 *     their WhatsApp number matches the booking's contact number — a
 *     guessed PNR from a stranger's phone leaks nothing but the status.
 *   - The endpoint always answers Twilio with valid TwiML and HTTP 200 on
 *     success (and an empty <Response> on error) so it never retry-storms.
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

/**
 * Build one <Message> block, XML-escaping the body and optional media URL.
 */
function wa_message(string $body, ?string $mediaUrl = null): string
{
    $out = '<Message><Body>' . htmlspecialchars($body, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</Body>';
    if ($mediaUrl !== null && $mediaUrl !== '') {
        $out .= '<Media>' . htmlspecialchars($mediaUrl, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</Media>';
    }
    return $out . '</Message>';
}

/**
 * Emit a TwiML document (already-escaped message blocks) and stop.
 */
function wa_reply(string ...$messages): never
{
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/xml; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Response>' . implode('', $messages) . '</Response>';
    exit;
}

/**
 * Validate the Twilio request signature. Twilio signs the exact webhook URL
 * with every POST parameter appended (sorted by key, no separators),
 * HMAC-SHA1 keyed by the account auth token, base64-encoded.
 */
function wa_validate_signature(string $authToken): bool
{
    $sig = (string) ($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '');
    if ($sig === '') {
        return false;
    }

    $override = Settings::getString('twilio_webhook_url', '');
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

    $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));
    return hash_equals($expected, $sig);
}

try {
    $company = Settings::getString('company_name', APP_NAME);
    $phone   = Settings::officePhone();

    // A GET (e.g. someone opening the URL, or a Twilio console probe).
    if (!Security::isPost()) {
        wa_reply(wa_message(
            "🙏 Namaste! This is the " . $company . " ticket assistant.\n"
            . "Send your booking PNR (looks like SHG-XXXX-XXXX-XXXX) to check your ticket."
        ));
    }

    // Reject forged callers. This FAILS CLOSED: with no auth token configured
    // a request cannot be proven to come from Twilio, so it is rejected rather
    // than trusted — otherwise anyone could POST a victim's number + a guessed
    // PNR and read back the booking detail. Configure twilio_auth_token before
    // pointing Twilio's webhook here (the setup guide does this first).
    $authToken = Settings::getString('twilio_auth_token', '');
    if ($authToken === '' || !wa_validate_signature($authToken)) {
        Logger::warning('Twilio webhook rejected', [
            'ip'     => Security::clientIp(),
            'reason' => $authToken === '' ? 'no auth token configured' : 'signature mismatch',
        ], 'whatsapp');
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Invalid signature.');
    }

    $from = (string) ($_POST['From'] ?? '');       // e.g. "whatsapp:+9198XXXXXXXX"
    $body = trim((string) ($_POST['Body'] ?? ''));
    $senderDigits = normalisePhone($from);
    /* 17 Sep 2026: remember that this number wrote to us — WhatsApp delivers
       free-text business messages only inside the 24 h after that, and the
       office's one-click sends (admin/api/wa-send.php) check this window
       before choosing "send via API" over "open on your phone". */
    try {
        require_once INCLUDE_PATH . '/watemplates.php';
        WaTemplates::noteInbound(preg_replace('/\D/', '', $from) ?? '');
    } catch (Throwable $ignored) {
    }

    // Abuse guard, keyed on the sender (falls back to IP).
    Security::requireRateLimit('wa_bot', $senderDigits !== '' ? $senderDigits : Security::clientIp(), 20, 60);

    // 18 Sep 2026: the reply logic moved to includes/wabot.php unchanged,
    // shared with the Meta Cloud API webhook (whatsapp/webhook.php).
    require_once INCLUDE_PATH . '/wabot.php';
    $reply = WaBot::reply($from, $body);
    wa_reply(wa_message($reply['text'], $reply['media']));

} catch (Throwable $e) {
    // Never leak internals to Twilio — acknowledge quietly so it doesn't retry.
    Logger::exception($e);
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/xml; charset=utf-8');
    }
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
    exit;
}
