<?php
/**
 * =====================================================================
 *  WaVoice — WhatsApp voice notes become text (23 Sep 2026).
 *
 *  Owner: "sunne — voice message pani bujhne banau". Many passengers
 *  speak rather than type. Until now a voice note got "I cannot listen,
 *  please type". Now:
 *
 *      Meta media id  ->  download the audio (Graph API, our own token)
 *                     ->  Gemini transcribes it, in the language and script
 *                         it was spoken in
 *                     ->  the text goes through the SAME path a typed
 *                         message takes (booking engine, VPS answers,
 *                         assistant) — nothing new can sell or change
 *                     ->  the reply opens with 🎤 "…what we heard…" so the
 *                         passenger can see it was understood right.
 *
 *  Any failure (no key, quota, a long or empty recording) returns null and
 *  the old "please type" reply stands. Nothing is stored: the audio lives
 *  in memory for one request. Switch: wa_voice_on.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaVoice
{
    /** WhatsApp voice notes are small; anything bigger is not a question. */
    private const MAX_BYTES = 4 * 1024 * 1024;

    /** Reliable rungs first: a transcript is an errand, not a conversation. */
    private const MODELS = ['gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-3.7-flash', 'gemini-3.8-flash'];

    public static function enabled(): bool
    {
        return Settings::getBool('wa_voice_on', false) && trim(Settings::getString('gemini_api_key', '')) !== '';
    }

    /** The words in one voice note, or null when it could not be heard. */
    public static function transcribe(string $mediaId, string $from = ''): ?string
    {
        if ($mediaId === '' || !self::enabled() || !function_exists('curl_init')) {
            return null;
        }
        $t0 = microtime(true);
        $audio = self::download($mediaId);
        if ($audio === null) {
            self::log($from, false, 'download failed', $t0);
            return null;
        }
        $text = self::gemini($audio['bytes'], $audio['mime']);
        self::log($from, $text !== null, $text !== null ? 'transcribed ' . mb_strlen($text) . ' chars' : 'no speech / model failed', $t0);
        return $text;
    }

    /** The line that opens the reply, so the passenger sees what we heard. */
    public static function heardLine(string $transcript): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $transcript) ?? $transcript);
        if (mb_strlen($t) > 140) {
            $t = mb_substr($t, 0, 137) . '…';
        }
        return '🎤 "' . $t . '"';
    }

    /** Gemini request body — public so the test can pin it without a network. */
    public static function payload(string $base64, string $mime): array
    {
        return [
            'contents' => [[
                'role'  => 'user',
                'parts' => [
                    ['inline_data' => ['mime_type' => $mime, 'data' => $base64]],
                    ['text' => 'Transcribe this WhatsApp voice note from a bus passenger in India or Nepal, word for word. '
                        . 'Write it in the language and script it was spoken in: Nepali or Hindi in Devanagari, English in '
                        . 'Latin letters; keep place names (Surat, Rupaidiha, Baroda) as spoken. Output ONLY the words — no '
                        . 'quotes, no labels, no translation, no comments. If there is no clear speech, output nothing.'],
                ],
            ]],
            'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 600, 'thinkingConfig' => ['thinkingBudget' => 128]],
        ];
    }

    /** Strip what a model sometimes wraps around a transcript. */
    public static function clean(string $raw): string
    {
        $t = trim($raw);
        $t = preg_replace('/^(transcript(ion)?|text|output)\s*[:\-]\s*/iu', '', $t) ?? $t;
        $t = trim($t, " \t\n\r\"'“”‘’`");
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return mb_substr($t, 0, 500);
    }

    /* ------------------------------------------------------------------ */

    /** @return array{bytes: string, mime: string}|null */
    private static function download(string $mediaId): ?array
    {
        require_once dirname(__DIR__) . '/whatsapp/config.php';
        if (META_ACCESS_TOKEN === '') {
            return null;
        }
        $meta = self::get(META_API_BASE . '/' . rawurlencode($mediaId));
        $info = $meta !== null ? json_decode($meta, true) : null;
        $url  = is_array($info) ? (string) ($info['url'] ?? '') : '';
        $size = is_array($info) ? (int) ($info['file_size'] ?? 0) : 0;
        $mime = is_array($info) ? (string) ($info['mime_type'] ?? 'audio/ogg') : 'audio/ogg';
        if ($url === '' || !preg_match('~^https://~i', $url) || $size > self::MAX_BYTES) {
            return null;
        }
        $bytes = self::get($url);
        if ($bytes === null || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        // "audio/ogg; codecs=opus" -> "audio/ogg" (what Gemini accepts)
        $mime = strtolower(trim(explode(';', $mime)[0]));
        return ['bytes' => $bytes, 'mime' => str_starts_with($mime, 'audio/') ? $mime : 'audio/ogg'];
    }

    /** One authenticated GET to Meta (the media URL also needs the token). */
    private static function get(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . META_ACCESS_TOKEN, 'User-Agent: SHG-WhatsApp/1.0'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? (string) $body : null;
    }

    private static function gemini(string $bytes, string $mime): ?string
    {
        $key = trim(Settings::getString('gemini_api_key', ''));
        $body = json_encode(self::payload(base64_encode($bytes), $mime));
        if ($key === '' || $body === false) {
            return null;
        }
        foreach (self::MODELS as $model) {
            $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-goog-api-key: ' . $key],
            ]);
            $res  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($res === false || $code !== 200) {
                if ($code === 429) {
                    return null;        // quota: every rung shares it on this key
                }
                continue;               // busy / unknown model: next rung
            }
            $j = json_decode((string) $res, true);
            $text = '';
            foreach ((array) ($j['candidates'][0]['content']['parts'] ?? []) as $p) {
                if (isset($p['text']) && empty($p['thought'])) {
                    $text .= (string) $p['text'];
                }
            }
            $text = self::clean($text);
            return $text !== '' ? $text : null;
        }
        return null;
    }

    private static function log(string $from, bool $ok, string $detail, float $t0): void
    {
        try {
            Database::insert('ai_agent_calls', [
                'created_at' => date('Y-m-d H:i:s'), 'phone' => normalisePhone($from), 'role' => 'customer',
                'channel' => 'whatsapp', 'tool' => 'voice:transcribe', 'args' => '{}', 'ok' => $ok ? 1 : 0,
                'detail' => mb_substr($detail, 0, 255), 'booking_id' => null, 'ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
        } catch (Throwable $e) {
        }
    }
}
