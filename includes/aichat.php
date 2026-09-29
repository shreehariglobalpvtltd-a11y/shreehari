<?php
/**
 * =====================================================================
 *  AiChat — the WhatsApp assistant's Nepali brain (20 Sep 2026)
 *
 *  wabot.php answers a PNR from the database and parses a booking
 *  request with TicketBrain. Everything else used to fall through to
 *  one canned paragraph, so a passenger asking "Mehsana bata kati baje
 *  cha?" or "bacchalai kati lagcha?" got a menu instead of an answer.
 *
 *  This class hands those messages to Claude with the SAME live facts
 *  the website assistant uses (ai_system_prompt(): routes, boarding
 *  points, fares, refund slabs, office numbers), plus WhatsApp rules
 *  and an instruction to write natural Nepali.
 *
 *  Deliberately conservative:
 *   - off unless an Anthropic key is configured (no key = old behaviour),
 *   - a per-sender rate limit, so one number cannot drain the budget,
 *   - short memory per sender (kv_store, 2 h) so a conversation holds,
 *   - any failure returns null and the caller keeps its canned reply.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiChat
{
    /** Messages kept per sender (user + assistant entries, newest last). */
    private const HISTORY_MAX = 10;

    /** A conversation older than this starts fresh. */
    private const HISTORY_TTL = 7200;          // 2 hours

    private const MAX_TOKENS  = 600;
    private const TIMEOUT_SEC = 20;

    /** No key, no cURL, or switched off in Settings — caller keeps its own reply. */
    public static function configured(): bool
    {
        return self::apiKey() !== ''
            && Settings::getBool('wa_ai_enabled', true)
            && function_exists('curl_init');
    }

    /**
     * Which brain answers. Gemini first when its key is present: Google's
     * free tier costs nothing, which is why the owner asked for it. Claude
     * stays fully supported — set anthropic_api_key (and leave the Gemini
     * one empty, or set ai_provider) and nothing else changes.
     *
     * @return array{provider: string, key: string}
     */
    private static function brain(): array
    {
        $forced = strtolower(trim(Settings::getString('ai_provider', '')));
        $gem    = trim(Settings::getString('gemini_api_key', ''));
        $ant    = trim(Settings::getString('anthropic_api_key', ''));

        if ($forced === 'gemini' && $gem !== '') {
            return ['provider' => 'gemini', 'key' => $gem];
        }
        if ($forced === 'anthropic' && $ant !== '') {
            return ['provider' => 'anthropic', 'key' => $ant];
        }
        if ($gem !== '') {
            return ['provider' => 'gemini', 'key' => $gem];
        }
        return ['provider' => 'anthropic', 'key' => $ant];
    }

    private static function apiKey(): string
    {
        return self::brain()['key'];
    }

    /**
     * Answer one inbound WhatsApp message. Returns null when the assistant
     * is off, rate-limited or failed — never throws at the caller.
     */
    public static function whatsappReply(string $senderDigits, string $text): ?string
    {
        $text = trim($text);
        if ($text === '' || !self::configured()) {
            return null;
        }

        /* 12 answers per 15 min per number. A human asking questions never
           reaches it; a loop or a prank does. */
        $who = $senderDigits !== '' ? $senderDigits : 'unknown';
        if (!Security::rateLimit('wa_ai', $who, 12, 900)) {
            Logger::warning('WhatsApp AI rate limit hit', ['to' => $who], 'whatsapp');
            return null;
        }

        try {
            $history   = self::loadHistory($who);
            $history[] = ['role' => 'user', 'content' => mb_substr($text, 0, 1500)];

            $reply = self::ask(self::systemPrompt(), $history);
            if ($reply === null || trim($reply) === '') {
                return null;
            }

            $history[] = ['role' => 'assistant', 'content' => $reply];
            self::saveHistory($who, $history);

            return $reply;
        } catch (Throwable $e) {
            Logger::error('WhatsApp AI failed: ' . $e->getMessage(), ['to' => $who], 'whatsapp');
            return null;
        }
    }

    /** Forget one sender's conversation (used when they send a fresh PNR). */
    public static function forget(string $senderDigits): void
    {
        if ($senderDigits === '') {
            return;
        }
        try {
            Database::delete('kv_store', 'kscope = :s AND kkey = :k', ['s' => 'wa_ai', 'k' => $senderDigits]);
        } catch (Throwable $e) {
            // A stale conversation is harmless; never let cleanup break a reply.
        }
    }

    /* ----------------------------------------------------------------- */

    /**
     * The website assistant's live system prompt (routes, fares, refund
     * slabs, contact numbers — read from the same tables the booking
     * engine uses) plus the rules that make it a WhatsApp voice.
     */
    private static function systemPrompt(): string
    {
        require_once INCLUDE_PATH . '/aiprompt.php';

        $phone = Settings::officePhone();

        return ai_system_prompt() . "\n\n"
            . "=== WHATSAPP CHANNEL RULES ===\n"
            . "You are replying inside WhatsApp, as the S Hari Global ticket assistant.\n"
            . "1. WRITE IN NEPALI (Devanagari), natural and fluent — the way a polite Nepali "
            . "shopkeeper speaks, not translated English. If the passenger writes in English or "
            . "Hindi, reply in that language instead.\n"
            . "2. Keep it short: 2–6 lines. No markdown, no headings, no bullet symbols like * or #. "
            . "Plain sentences and line breaks only. A couple of emoji are fine.\n"
            . "3. Never invent a fare, a time, a seat or a booking. Use only the facts above. "
            . "If you do not know, say so and give the office number " . ($phone !== '' ? $phone : '') . ".\n"
            . "4. You cannot book, cancel, confirm payment or hold a seat. For those, tell the "
            . "passenger what to send (date, how many seats, boarding point) and say the desk "
            . "confirms it — or ask them to send their PNR (SHG-...) for ticket status.\n"
            . "5. Never ask for card numbers, CVV, OTP, passwords or any document number.\n"
            . "6. If the passenger sounds upset or the matter is urgent, apologise briefly and "
            . "give the office number instead of a long explanation.
"
            . "7. The briefing above is written for the website, so it mentions in-app routes "
            . "like \"#/book\". Those mean nothing in WhatsApp — never print one. To send "
            . "someone to the site, write the full address " . appUrl('') . " , or better, "
            . "tell them to reply here with the date, how many seats and the boarding point.";
    }

    /** One call to whichever brain is configured. Returns the text, or null. */
    private static function ask(string $system, array $messages): ?string
    {
        $brain = self::brain();
        return $brain['provider'] === 'gemini'
            ? self::askGemini($brain['key'], $system, $messages)
            : self::askClaude($brain['key'], $system, $messages);
    }

    /**
     * Google Gemini (free tier). The model id is NOT hardcoded: model names
     * come and go, and a wrong one is a silent 404 on every reply. The first
     * call asks Google which models this key may use, keeps the choice in
     * settings, and re-discovers if that model ever stops answering.
     */
    private static function askGemini(string $key, string $system, array $messages): ?string
    {
        $model = trim(Settings::getString('gemini_model', ''));
        if ($model === '') {
            $model = self::geminiPickModel($key);
            if ($model === '') {
                return null;
            }
        }

        $contents = [];
        foreach ($messages as $m) {
            $contents[] = [
                'role'  => ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) ($m['content'] ?? '')]],
            ];
        }

        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => $contents,
            'generationConfig'   => ['maxOutputTokens' => self::MAX_TOKENS, 'temperature' => 0.4],
        ];

        [$http, $body] = self::httpJson(
            'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
            $payload,
            ['Content-Type: application/json', 'x-goog-api-key: ' . $key]
        );

        /* ListModels happily lists models a given key may NOT call — a new
           key gets "this model is no longer available to new users. Please
           update your code to use models/X". Google names the replacement in
           that message, so follow it; otherwise pick the next candidate and
           never the one that just failed. */
        if ($http === 404 || $http === 400) {
            $next = self::geminiSuggestedModel($body);
            if ($next === '' || $next === $model) {
                $next = self::geminiPickModel($key, [$model]);
            }
            if ($next !== '' && $next !== $model) {
                [$http, $body] = self::httpJson(
                    'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($next) . ':generateContent',
                    $payload,
                    ['Content-Type: application/json', 'x-goog-api-key: ' . $key]
                );
                if ($http === 200) {
                    Settings::set('gemini_model', $next);
                    Logger::info('Gemini model switched', ['from' => $model, 'to' => $next], 'whatsapp');
                }
                $model = $next;
            }
        }

        if ($http !== 200) {
            Logger::error('Gemini call failed (HTTP ' . $http . ')', [
                'model' => $model,
                'body'  => mb_substr($body, 0, 300),
            ], 'whatsapp');
            return null;
        }

        $json = json_decode($body, true);
        $out  = '';
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
            $out .= (string) ($part['text'] ?? '');
        }
        return trim($out) !== '' ? trim($out) : null;
    }

    /** "Please update your code to use models/gemini-3.5-flash-lite". */
    private static function geminiSuggestedModel(string $errorBody): string
    {
        if (preg_match('#use\s+models/([A-Za-z0-9._\-]+)#', $errorBody, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    /**
     * Ask Google which models this key can use, and keep the choice.
     *
     * @param array<int, string> $exclude models already known to fail
     */
    private static function geminiPickModel(string $key, array $exclude = []): string
    {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT_SEC,
            CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $key]]);
        $body = (string) curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http !== 200) {
            Logger::error('Gemini model list failed (HTTP ' . $http . ')', ['body' => mb_substr($body, 0, 200)], 'whatsapp');
            return '';
        }

        $names = [];
        foreach (json_decode($body, true)['models'] ?? [] as $m) {
            if (!in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true)) {
                continue;
            }
            $name = (string) preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
            if ($name !== '' && !in_array($name, $exclude, true)) {
                $names[] = $name;
            }
        }
        if ($names === []) {
            return '';
        }

        /* Which tier to prefer. "flash" is the cheap fast one a ticket
           assistant wants; an owner paying for Pro can set gemini_tier=pro
           and get the stronger model when the key is allowed one. A preview
           or experimental name is always last — those disappear without
           notice, and this bot answers passengers. */
        $tier = strtolower(trim(Settings::getString('gemini_tier', 'flash'))) === 'pro' ? 'pro' : 'flash';
        usort($names, static function (string $a, string $b) use ($tier): int {
            $score = static function (string $n) use ($tier): int {
                $s = 0;
                if (str_contains($n, $tier))   { $s -= 4; }
                if ($tier === 'flash' && str_contains($n, 'lite')) { $s -= 1; }
                if (str_contains($n, 'preview') || str_contains($n, 'exp')) { $s += 5; }
                if (str_contains($n, 'vision') || str_contains($n, 'embedding') || str_contains($n, 'tts')) { $s += 10; }
                return $s;
            };
            return [$score($a), $a] <=> [$score($b), $b];
        });

        Settings::set('gemini_model', $names[0]);
        Logger::info('Gemini model selected', ['model' => $names[0]], 'whatsapp');
        return $names[0];
    }

    /** One Anthropic Messages API call. Returns the text, or null. */
    private static function askClaude(string $key, string $system, array $messages): ?string
    {
        $model = Settings::getString('ai_model', 'claude-opus-5');

        [$http, $body] = self::httpJson('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => $system,
            'messages'   => $messages,
        ], [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ]);

        if ($http !== 200) {
            Logger::error('Anthropic call failed (HTTP ' . $http . ')', [
                'body' => mb_substr($body, 0, 300),
            ], 'whatsapp');
            return null;
        }

        $json = json_decode($body, true);
        $out  = '';
        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $out .= (string) ($block['text'] ?? '');
            }
        }

        return trim($out) !== '' ? trim($out) : null;
    }

    /**
     * POST JSON, return [httpStatus, body]. Shared so both brains time out,
     * log and fail the same way.
     *
     * @return array{0: int, 1: string}
     */
    private static function httpJson(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Logger::error('AI request failed', ['url' => parse_url($url, PHP_URL_HOST), 'err' => $err], 'whatsapp');
            return [0, ''];
        }
        return [$http, (string) $body];
    }

    /** @return array<int, array{role: string, content: string}> */
    private static function loadHistory(string $who): array
    {
        $row = Database::fetch(
            'SELECT kvalue, UNIX_TIMESTAMP(updated_at) AS ts FROM kv_store WHERE kscope = :s AND kkey = :k',
            ['s' => 'wa_ai', 'k' => $who]
        );
        if ($row === null || (time() - (int) $row['ts']) > self::HISTORY_TTL) {
            return [];
        }

        $saved = json_decode((string) $row['kvalue'], true);
        if (!is_array($saved)) {
            return [];
        }

        $clean = [];
        foreach ($saved as $m) {
            $role    = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            // The API rejects two messages in a row from the same role.
            if ($clean !== [] && $clean[count($clean) - 1]['role'] === $role) {
                $clean[count($clean) - 1]['content'] .= "\n" . $content;
                continue;
            }
            $clean[] = ['role' => $role, 'content' => $content];
        }

        // A conversation must start with the passenger.
        while ($clean !== [] && $clean[0]['role'] !== 'user') {
            array_shift($clean);
        }

        return array_slice($clean, -self::HISTORY_MAX);
    }

    private static function saveHistory(string $who, array $history): void
    {
        $json = json_encode(array_slice($history, -self::HISTORY_MAX), JSON_UNESCAPED_UNICODE);

        $done = Database::update(
            'kv_store',
            ['kvalue' => $json, 'updated_by' => 'wabot'],
            'kscope = :s AND kkey = :k',
            ['s' => 'wa_ai', 'k' => $who]
        );
        if ($done === 0) {
            Database::insertIgnore('kv_store', [
                'kscope'     => 'wa_ai',
                'kkey'       => $who,
                'kvalue'     => $json,
                'updated_by' => 'wabot',
            ]);
        }
    }
}
