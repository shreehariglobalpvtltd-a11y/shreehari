<?php
/**
 * =====================================================================
 *  includes/messenger.php — Facebook Messenger (Meta) send + credentials.
 *
 *  WhatsApp Cloud (whatsapp/webhook.php) and Facebook Messenger share one
 *  Meta app and one AI bot (WaBot::reply). This class is the thin
 *  Messenger-only layer that messenger/webhook.php leans on:
 *
 *    - reads the Page token / App Secret / verify token from Settings
 *      (is_public = 0; env fallback), never hard-coded;
 *    - proves an inbound POST's X-Hub-Signature-256 before it is trusted;
 *    - sends a reply back through the Graph Send API.
 *
 *  Everything degrades to "disabled, do nothing" when a credential is
 *  missing, so an unconfigured Page never errors and never sends. The whole
 *  channel is behind the messenger_bot_enabled switch (default OFF).
 * =====================================================================
 */
declare(strict_types=1);

defined('SHG_APP') || exit('No direct access.');

final class Messenger
{
    private const GRAPH = 'https://graph.facebook.com/v21.0';

    /** A credential from Settings (is_public=0), with an env fallback. */
    private static function cfg(string $key, string $env): string
    {
        $v = trim(Settings::getString($key, ''));
        if ($v === '' && $env !== '') {
            $v = trim((string) (getenv($env) ?: ''));
        }
        return $v;
    }

    public static function pageToken(): string   { return self::cfg('fb_page_token',   'FB_PAGE_TOKEN'); }
    public static function appSecret(): string    { return self::cfg('fb_app_secret',   'FB_APP_SECRET'); }
    public static function verifyToken(): string  { return self::cfg('fb_verify_token', 'FB_VERIFY_TOKEN'); }

    /** Live only when switched on AND a Page token is present. */
    public static function enabled(): bool
    {
        return Settings::getBool('messenger_bot_enabled', false) && self::pageToken() !== '';
    }

    /**
     * X-Hub-Signature-256 over the raw body, keyed by the App Secret.
     * Fails CLOSED when no secret is configured — an unsigned POST is never
     * trusted (same rule whatsapp/webhook.php applies to WhatsApp).
     */
    public static function verifySignature(string $raw, string $sig): bool
    {
        $secret = self::appSecret();
        if ($secret === '' || $sig === '') {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig);
    }

    /**
     * The GET verify-token handshake. Returns the challenge to echo when the
     * token matches, or null to answer 403.
     */
    public static function verifyChallenge(string $mode, string $token, string $challenge): ?string
    {
        $want = self::verifyToken();
        if ($mode === 'subscribe' && $want !== '' && hash_equals($want, $token)
            && preg_match('/^[A-Za-z0-9_\-]{1,512}$/', $challenge) === 1) {
            return $challenge;
        }
        return null;
    }

    /**
     * Pull the text out of one messaging event: a typed message, a tapped
     * quick-reply or a postback button. An echo (our own sent message), a
     * read receipt or a delivery receipt carries no text and returns ''.
     */
    public static function textOf(array $m): string
    {
        if (!empty($m['message']['is_echo'])) {
            return '';
        }
        if (isset($m['message']['quick_reply']['payload'])) {
            return (string) $m['message']['quick_reply']['payload'];
        }
        if (isset($m['message']['text'])) {
            return (string) $m['message']['text'];
        }
        if (isset($m['postback']['payload'])) {
            return (string) $m['postback']['payload'];
        }
        if (isset($m['postback']['title'])) {
            return (string) $m['postback']['title'];
        }
        return '';
    }

    /**
     * Send a text (and an optional image) to a PSID through the Graph Send
     * API. Returns ['success'=>bool, 'message_id'=>?string, 'error'=>?string].
     * Never throws; a missing token or network error is a clean failure.
     */
    public static function send(string $psid, string $text, ?string $imageUrl = null): array
    {
        $token = self::pageToken();
        if ($token === '' || $psid === '') {
            return ['success' => false, 'message_id' => null, 'error' => 'messenger not configured'];
        }

        // Picture first (a caption is not supported on an image attachment).
        if ($imageUrl !== null && $imageUrl !== '') {
            self::post($token, [
                'recipient'      => ['id' => $psid],
                'messaging_type' => 'RESPONSE',
                'message'        => ['attachment' => ['type' => 'image', 'payload' => ['url' => $imageUrl, 'is_reusable' => false]]],
            ]);
        }

        $text = trim($text);
        if ($text === '') {
            return ['success' => true, 'message_id' => null, 'error' => null];
        }
        return self::post($token, [
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => ['text' => mb_substr($text, 0, 2000)],
        ]);
    }

    /** One POST to /me/messages. Token travels as a Bearer header, not the URL. */
    private static function post(string $token, array $body): array
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
        $ch = curl_init(self::GRAPH . '/me/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['success' => false, 'message_id' => null, 'error' => 'curl: ' . $cerr];
        }
        $data = json_decode((string) $resp, true);
        if ($http >= 200 && $http < 300 && isset($data['message_id'])) {
            return ['success' => true, 'message_id' => (string) $data['message_id'], 'error' => null];
        }
        $err = $data['error'] ?? [];
        $code = isset($err['code']) ? (' (code ' . $err['code'] . ')') : '';
        return ['success' => false, 'message_id' => null, 'error' => (string) (($err['message'] ?? 'send failed') . $code)];
    }
}
