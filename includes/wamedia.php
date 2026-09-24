<?php
/**
 * =====================================================================
 *  WaMedia — inbound WhatsApp attachments, handled as untrusted bytes
 *  (24 Sep 2026).
 *
 *  A passenger sends a payment screenshot, a photo of a ticket, a PDF, or
 *  a voice note. Until now the webhook read only the caption and the
 *  bot answered with a fixed line. This class does three careful things,
 *  each behind its own switch:
 *
 *   describe()   — metadata only (kind, mime, size, Meta media id, the
 *                  sha256 Meta reports). Costs nothing, always safe.
 *   download()   — fetch the bytes from the Graph API with a size cap and
 *                  a MIME allow-list, so a "document" that is really an
 *                  executable is refused before it is stored anywhere.
 *   stash()      — keep the bytes ENCRYPTED (same AES-256-GCM seal as the
 *                  company documents) under uploads/wa-inbound/ as evidence
 *                  for a handoff; the desk opens it from the Support Inbox.
 *   transcribe() — a voice note to words through Gemini (wa_ops_voice_on).
 *                  The words are never acted on directly: WaBot reads them
 *                  back and asks the person to confirm, because a wrong
 *                  name on a ticket is exactly the mistake a transcriber
 *                  makes.
 *
 *  Nothing here is ever fed to the model as an instruction; an attachment
 *  is data about the conversation, no more.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaMedia
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** What we are prepared to hold on disk or hand to a transcriber. */
    private const ALLOWED = [
        'image/jpeg', 'image/png', 'image/webp',
        'application/pdf',
        'audio/ogg', 'audio/ogg; codecs=opus', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/amr',
    ];

    private const SUBDIR = 'wa-inbound';

    private const GEMINI_LADDER = ['gemini-3.5-flash', 'gemini-2.5-flash'];

    private function __construct() {}

    /** Attachments reach the assistant (metadata + evidence) only when the office says so. */
    public static function enabled(): bool
    {
        return Settings::getBool('wa_ops_media_on', false);
    }

    /** Voice notes are transcribed only when switched on AND a Gemini key exists. */
    public static function voiceEnabled(): bool
    {
        return Settings::getBool('wa_ops_voice_on', false)
            && trim(Settings::getString('gemini_api_key', '')) !== ''
            && function_exists('curl_init');
    }

    /**
     * The safe description of an inbound media message.
     *
     * @param array<string,mixed> $msg the Meta message object
     * @return array{kind: string, id: string, mime: string, sha256: string, filename: string, caption: string}
     */
    public static function describe(string $type, array $msg): array
    {
        $m    = is_array($msg[$type] ?? null) ? $msg[$type] : [];
        $id   = (string) ($m['id'] ?? '');
        $mime = strtolower(trim((string) ($m['mime_type'] ?? '')));
        $sha  = (string) ($m['sha256'] ?? '');
        // Strict shapes, or nothing: a media id or MIME that does not look like
        // one is dropped whole rather than "cleaned" into something plausible.
        return [
            'kind'     => in_array($type, ['image', 'document', 'audio', 'voice', 'video', 'sticker'], true) ? $type : 'file',
            'id'       => preg_match('/^[A-Za-z0-9_.=\-]{1,160}$/', $id) === 1 ? $id : '',
            'mime'     => preg_match('~^[a-z0-9.+\-]+/[a-z0-9.+\-]+(?:;\s*codecs=[a-z0-9.+\-]+)?$~', $mime) === 1 ? mb_substr($mime, 0, 80) : '',
            'sha256'   => preg_match('~^[A-Za-z0-9+/=]{20,64}$~', $sha) === 1 ? $sha : '',
            'filename' => mb_substr(trim(preg_replace('/\.{2,}/', '.', preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', (string) ($m['filename'] ?? '')) ?? '') ?? '', '. '), 0, 120),
            'caption'  => (string) ($m['caption'] ?? ''),
        ];
    }

    /**
     * Fetch the bytes of one media id from the Graph API. Two calls: the id
     * resolves to a short-lived URL, the URL to the bytes (Bearer on both).
     * Refuses anything over the cap or outside the allow-list.
     *
     * @return array{bytes: string, mime: string, size: int}|null
     */
    public static function download(string $mediaId): ?array
    {
        if (preg_match('/^[A-Za-z0-9_.=\-]{1,160}$/', $mediaId) !== 1 || !function_exists('curl_init')) {
            return null;
        }
        require_once ROOT_PATH . '/whatsapp/config.php';
        if (META_ACCESS_TOKEN === '') {
            return null;
        }
        $meta = self::httpGet(META_API_BASE . '/' . rawurlencode($mediaId), META_ACCESS_TOKEN, 8192);
        if ($meta === null) {
            return null;
        }
        $info = json_decode($meta['body'], true);
        $url  = (string) ($info['url'] ?? '');
        $mime = strtolower(trim((string) ($info['mime_type'] ?? '')));
        $size = (int) ($info['file_size'] ?? 0);
        if ($url === '' || !preg_match('~^https://~i', $url) || $size > self::MAX_BYTES || !self::mimeAllowed($mime)) {
            Logger::warning('WhatsApp media refused', ['mime' => $mime, 'size' => $size], 'whatsapp');
            return null;
        }
        $file = self::httpGet($url, META_ACCESS_TOKEN, self::MAX_BYTES);
        if ($file === null || $file['body'] === '') {
            return null;
        }
        // Trust the bytes, not the header.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $real  = strtolower((string) $finfo->buffer($file['body']));
        if (!self::mimeAllowed($real) && !($real === 'application/octet-stream' && str_starts_with($mime, 'audio/'))) {
            Logger::warning('WhatsApp media content does not match its type', ['claimed' => $mime, 'real' => $real], 'whatsapp');
            return null;
        }
        return ['bytes' => $file['body'], 'mime' => $real !== 'application/octet-stream' ? $real : $mime, 'size' => strlen($file['body'])];
    }

    /**
     * Keep downloaded bytes encrypted as handoff evidence. Returns the path
     * relative to UPLOAD_PATH, or null when nothing could be kept.
     */
    public static function stash(array $media, string $phone): ?string
    {
        $bytes = (string) ($media['bytes'] ?? '');
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        try {
            require_once INCLUDE_PATH . '/companydocs.php';
            $ym  = date('Y') . '/' . date('m');
            $dir = UPLOAD_PATH . '/' . self::SUBDIR;
            if (ensureDir($dir) && !is_file($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            }
            if (!ensureDir($dir . '/' . $ym)) {
                return null;
            }
            $name = Security::safeFilename('bin');
            $rel  = self::SUBDIR . '/' . $ym . '/' . $name;
            if (file_put_contents($dir . '/' . $ym . '/' . $name, CompanyDocs::seal($bytes), LOCK_EX) === false) {
                return null;
            }
            @chmod($dir . '/' . $ym . '/' . $name, 0640);
            Logger::info('WhatsApp attachment kept as evidence', ['from' => maskPhone($phone), 'mime' => (string) ($media['mime'] ?? ''), 'size' => strlen($bytes)], 'whatsapp');
            return $rel;
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
            return null;
        }
    }

    /** Decrypt a stashed attachment for the Support Inbox. */
    public static function openStash(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (preg_match('#^wa-inbound/\d{4}/\d{2}/[A-Za-z0-9_-]+\.bin$#', $rel) !== 1) {
            throw new RuntimeException('Invalid evidence path.');
        }
        $abs = UPLOAD_PATH . '/' . $rel;
        if (!is_file($abs)) {
            throw new RuntimeException('Evidence file missing.');
        }
        require_once INCLUDE_PATH . '/companydocs.php';
        return CompanyDocs::open((string) file_get_contents($abs));
    }

    /**
     * A voice note to words. Gemini takes the audio inline; the prompt asks
     * for a verbatim transcript in the language spoken, with names and
     * numbers exactly as heard and NOTHING added. Returns null when unsure
     * (no key, provider down, empty result) — WaBot then asks the person to
     * type, as it always did.
     */
    public static function transcribe(array $media): ?string
    {
        if (!self::voiceEnabled()) {
            return null;
        }
        $bytes = (string) ($media['bytes'] ?? '');
        $mime  = (string) ($media['mime'] ?? 'audio/ogg');
        if ($bytes === '' || strlen($bytes) > 6 * 1024 * 1024 || !str_starts_with($mime, 'audio/')) {
            return null;
        }
        $mime = explode(';', $mime)[0];
        $key  = trim(Settings::getString('gemini_api_key', ''));
        $payload = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                    ['text' => 'Transcribe this WhatsApp voice note word for word, in the language actually spoken (Nepali, Hindi, Gujarati or English), '
                             . 'using the natural script for that language. Keep every name, number, date and place EXACTLY as heard; never correct, '
                             . 'translate, summarise or add anything. If a part is unclear write [अस्पष्ट]. Output only the transcript.'],
                ],
            ]],
            'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 400],
        ];
        $ladder = self::GEMINI_LADDER;
        $pinned = trim(Settings::getString('gemini_model', ''));
        if ($pinned !== '') {
            $ladder = array_values(array_unique(array_merge([$pinned], $ladder)));
        }
        foreach ($ladder as $model) {
            $res = self::httpPostJson(
                'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
                $payload, ['content-type: application/json', 'x-goog-api-key: ' . $key]
            );
            if ($res === null) {
                continue;
            }
            $text = '';
            foreach ((array) ($res['candidates'][0]['content']['parts'] ?? []) as $part) {
                if (isset($part['text'])) {
                    $text .= (string) $part['text'];
                }
            }
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            if ($text !== '' && mb_strlen($text) <= 1500) {
                return $text;
            }
        }
        return null;
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ----------------------------------------------------------------- */

    private static function mimeAllowed(string $mime): bool
    {
        $mime = strtolower(trim($mime));
        if (in_array($mime, self::ALLOWED, true)) {
            return true;
        }
        return str_starts_with($mime, 'audio/');       // WhatsApp voice notes vary by handset
    }

    /** @return array{body: string, http: int}|null */
    private static function httpGet(string $url, string $token, int $cap): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE    => $cap,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false || $http !== 200 || strlen((string) $body) > $cap) {
            Logger::warning('WhatsApp media fetch failed', ['http' => $http, 'err' => $err, 'host' => parse_url($url, PHP_URL_HOST)], 'whatsapp');
            return null;
        }
        return ['body' => (string) $body, 'http' => $http];
    }

    private static function httpPostJson(string $url, array $payload, array $headers): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $http !== 200) {
            Logger::warning('Voice transcription HTTP ' . $http, ['body' => mb_substr((string) $body, 0, 200)], 'whatsapp');
            return null;
        }
        $json = json_decode((string) $body, true);
        return is_array($json) ? $json : null;
    }
}
