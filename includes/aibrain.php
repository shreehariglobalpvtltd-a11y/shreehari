<?php
/**
 * =====================================================================
 *  AiBrain — ONE AI for the whole system (5 Oct 2026)
 *
 *  Owner: "my AI is one — the chatbot and every other help run on the
 *  same one. I will put the Gemini / Claude / GPT key; use whichever is
 *  there."
 *
 *  Before this, each corner chose its own brain: the WhatsApp agent
 *  (AiAgent) had a ladder, the plain WhatsApp reply (AiChat) knew only
 *  Gemini and Claude, and the website fallback (api/ai-proxy.php) knew
 *  only Claude — with only a Gemini key on live, that one never answered.
 *
 *  Now there is one rule, read from Settings:
 *    ai_provider = auto     → Gemini, then GPT, then Claude (whichever key
 *                             is present; free Gemini first)
 *    ai_provider = gemini | openai | anthropic
 *                           → that one first, the others only if it fails
 *  and one call, text(), used by AiChat and the website relay. AiAgent
 *  (the tool-using WhatsApp agent) reads the same order via order().
 *
 *  Keys: gemini_api_key, openai_api_key, anthropic_api_key (Settings →
 *  ai, or the AI Brain page). Models: gemini_model (auto-picked),
 *  openai_model (default gpt-5-mini), ai_model (Claude).
 *
 *  Every answer is counted per provider in kv_store (scope "ai_brain"):
 *  ok / fail / last time / average ms — the AI Brain page shows it.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiBrain
{
    public const PROVIDERS = [
        'gemini'    => ['label' => 'Google Gemini',    'key' => 'gemini_api_key',    'model' => 'gemini_model', 'default' => 'auto (free tier)'],
        'openai'    => ['label' => 'OpenAI GPT',       'key' => 'openai_api_key',    'model' => 'openai_model', 'default' => 'gpt-5-mini'],
        'anthropic' => ['label' => 'Anthropic Claude', 'key' => 'anthropic_api_key', 'model' => 'ai_model',     'default' => 'claude-opus-5-5'],
    ];

    private const TIMEOUT = 25;

    public static function key(string $provider): string
    {
        $cfg = self::PROVIDERS[$provider] ?? null;
        return $cfg === null ? '' : trim(Settings::getString($cfg['key'], ''));
    }

    public static function model(string $provider): string
    {
        $cfg = self::PROVIDERS[$provider] ?? null;
        if ($cfg === null) {
            return '';
        }
        $m = trim(Settings::getString($cfg['model'], ''));
        if ($provider === 'openai' && $m === '') {
            return 'gpt-5-mini';
        }
        return $m;
    }

    /** The chosen setting: auto | gemini | openai | anthropic. */
    public static function choice(): string
    {
        $c = strtolower(trim(Settings::getString('ai_provider', 'auto')));
        return isset(self::PROVIDERS[$c]) ? $c : 'auto';
    }

    /**
     * Providers in the order they are tried — only those with a key.
     *
     * @return list<string>
     */
    public static function order(): array
    {
        $base = ['gemini', 'openai', 'anthropic'];
        $c = self::choice();
        if ($c !== 'auto') {
            $base = array_values(array_unique(array_merge([$c], $base)));
        }
        return array_values(array_filter($base, static fn(string $p): bool => self::key($p) !== ''));
    }

    public static function configured(): bool
    {
        return self::order() !== [] && function_exists('curl_init');
    }

    /**
     * One answer from the first provider that gives one.
     *
     * @param array<int, array{role:string, content:string}> $messages user / assistant turns, user last
     * @return array{text:string, provider:string, model:string, ms:int}|null
     */
    public static function text(string $system, array $messages, int $maxTokens = 700, string $tag = 'chat'): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $messages = self::clean($messages);
        if ($messages === []) {
            return null;
        }
        foreach (self::order() as $p) {
            $t0 = microtime(true);
            $out = null;
            try {
                $out = match ($p) {
                    'gemini'    => self::gemini($system, $messages, $maxTokens),
                    'openai'    => self::openai($system, $messages, $maxTokens),
                    'anthropic' => self::claude($system, $messages, $maxTokens),
                    default     => null,
                };
            } catch (Throwable $e) {
                Logger::warning('AI brain ' . $p . ' threw: ' . $e->getMessage(), ['tag' => $tag], 'whatsapp');
                $out = null;
            }
            $ms = (int) round((microtime(true) - $t0) * 1000);
            self::count($p, $out !== null && trim((string) $out) !== '', $ms, $tag);
            if ($out !== null && trim($out) !== '') {
                return ['text' => trim($out), 'provider' => $p, 'model' => self::model($p) ?: (string) Settings::getString('gemini_model', ''), 'ms' => $ms];
            }
        }
        return null;
    }

    /** A one-line probe for the AI Brain page: does this key answer? */
    public static function test(string $provider): array
    {
        if (!isset(self::PROVIDERS[$provider]) || self::key($provider) === '') {
            return ['ok' => false, 'detail' => 'no key'];
        }
        $t0 = microtime(true);
        $msgs = [['role' => 'user', 'content' => 'Reply with exactly: OK']];
        $out = match ($provider) {
            'gemini'    => self::gemini('You are a health check. Answer in one word.', $msgs, 50),
            'openai'    => self::openai('You are a health check. Answer in one word.', $msgs, 50),
            'anthropic' => self::claude('You are a health check. Answer in one word.', $msgs, 50),
            default     => null,
        };
        $ms = (int) round((microtime(true) - $t0) * 1000);
        self::count($provider, $out !== null, $ms, 'test');
        return ['ok' => $out !== null, 'detail' => $out !== null ? mb_substr(trim($out), 0, 60) . ' · ' . $ms . ' ms' : 'no answer (see logs)'];
    }

    /** @return array<string, array{ok:int, fail:int, last:string, last_ok:string, ms:int, tag:string}> */
    public static function stats(): array
    {
        $out = [];
        foreach (array_keys(self::PROVIDERS) as $p) {
            $j = json_decode((string) Database::scalar(
                "SELECT kvalue FROM kv_store WHERE kscope = 'ai_brain' AND kkey = :k", ['k' => $p], ''), true);
            $out[$p] = is_array($j) ? $j + ['ok' => 0, 'fail' => 0, 'last' => '', 'last_ok' => '', 'ms' => 0, 'tag' => '']
                                    : ['ok' => 0, 'fail' => 0, 'last' => '', 'last_ok' => '', 'ms' => 0, 'tag' => ''];
        }
        return $out;
    }

    /* ----------------------------------------------------------------- */

    /** user/assistant alternation, user first and last, non-empty. */
    private static function clean(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $c = trim((string) ($m['content'] ?? ''));
            if ($c === '') {
                continue;
            }
            if ($out !== [] && $out[count($out) - 1]['role'] === $role) {
                $out[count($out) - 1]['content'] .= "\n" . $c;
                continue;
            }
            $out[] = ['role' => $role, 'content' => $c];
        }
        while ($out !== [] && $out[0]['role'] !== 'user') {
            array_shift($out);
        }
        while ($out !== [] && $out[count($out) - 1]['role'] !== 'user') {
            array_pop($out);
        }
        return $out;
    }

    /** Gemini goes through AiChat, which already picks and re-picks the model. */
    private static function gemini(string $system, array $messages, int $max): ?string
    {
        if (!class_exists('AiChat')) {
            require_once INCLUDE_PATH . '/aichat.php';
        }
        return AiChat::geminiText(self::key('gemini'), $system, $messages, $max);
    }

    private static function claude(string $system, array $messages, int $max): ?string
    {
        $model = self::model('anthropic') ?: self::CLAUDE_DEFAULT;
        [$payload, $headers] = self::claudeShape($model, [
            'model'      => $model,
            'max_tokens' => max(64, $max),
            'system'     => $system,
            'messages'   => $messages,
        ], [
            'x-api-key: ' . self::key('anthropic'),
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ]);
        [$http, $body] = self::post('https://api.anthropic.com/v1/messages', $payload, $headers);
        if ($http === 400 && isset($payload['fallbacks']) && stripos($body, 'fallback') !== false) {
            // An account without the fallback beta must still get its answer.
            unset($payload['fallbacks']);
            $headers = array_values(array_filter($headers, static fn(string $h): bool => stripos($h, 'server-side-fallback') === false));
            [$http, $body] = self::post('https://api.anthropic.com/v1/messages', $payload, $headers);
        }
        if ($http !== 200) {
            Logger::warning('AI brain: Claude HTTP ' . $http, ['body' => self::redact($body)], 'whatsapp');
            return null;
        }
        $json = json_decode($body, true);
        if (self::claudeRefusal(is_array($json) ? $json : []) !== null) {
            return null;                          // the next provider answers instead
        }
        $out = '';
        foreach ((array) ($json['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') {
                $out .= (string) ($b['text'] ?? '');
            }
        }
        return trim($out) !== '' ? $out : null;
    }

    /* -----------------------------------------------------------------
     *  Claude request shape (29 Oct 2026, owner's "Opus 5.5 Max" brief)
     *
     *  The newer Claude models think before they write, always: Opus 5.5
     *  refuses thinking:disabled and any temperature, and its thinking is
     *  paid out of max_tokens. A 700-token WhatsApp budget left no room for
     *  the reply. One shape for AiBrain and AiAgent alike:
     *    - adaptive thinking + output_config.effort (Settings
     *      ai_claude_effort, default low — a chat answer, not a proof);
     *    - max_tokens no lower than 2048 on those models;
     *    - no temperature / top_p / top_k (a 400 on these models);
     *    - server-side refusal fallback (ai_claude_fallback, on by
     *      default): a false-positive safety decline is retried by
     *      Anthropic on its recommended model instead of going silent.
     *  Older models get the request exactly as before.
     * ----------------------------------------------------------------- */

    public const CLAUDE_DEFAULT = 'claude-opus-5-5';

    /** Effort levels the Messages API takes in output_config.effort. */
    public const CLAUDE_EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** Does this model run adaptive thinking and take output_config.effort? */
    public static function claudeAdaptive(string $model): bool
    {
        $m = strtolower($model);
        if (str_contains($m, 'haiku')) {
            return str_contains($m, 'haiku-5');           // Haiku 4.5 still takes budget_tokens
        }
        return str_contains($m, 'sonnet-5') || str_contains($m, 'opus-5') || str_contains($m, 'opus-4-')
            || str_contains($m, 'sonnet-4-6') || str_contains($m, 'fable') || str_contains($m, 'mythos');
    }

    /** The models the server-side "default" refusal fallback is documented for. */
    private static function claudeFallbackModel(string $model): bool
    {
        return (bool) preg_match('/^claude-(opus-5(-5)?|sonnet-5-5|fable-5-1)$/', strtolower(trim($model)));
    }

    public static function claudeEffort(): string
    {
        $e = strtolower(trim(Settings::getString('ai_claude_effort', 'low')));
        return in_array($e, self::CLAUDE_EFFORTS, true) ? $e : 'low';
    }

    /**
     * Payload and headers for one Claude request on this model.
     *
     * @param array<string,mixed> $payload
     * @param list<string>        $headers
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    public static function claudeShape(string $model, array $payload, array $headers): array
    {
        unset($payload['temperature'], $payload['top_p'], $payload['top_k']);
        if (!self::claudeAdaptive($model)) {
            return [$payload, $headers];
        }
        $payload['thinking']      = ['type' => 'adaptive'];
        $payload['output_config'] = ['effort' => self::claudeEffort()];
        $payload['max_tokens']    = max(2048, (int) ($payload['max_tokens'] ?? 0));
        if (self::claudeFallbackModel($model) && Settings::getBool('ai_claude_fallback', true)) {
            $payload['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }
        return [$payload, $headers];
    }

    /**
     * A safety decline arrives as HTTP 200 with stop_reason "refusal" and no
     * answer in it. Returns the category (or "unknown") and logs it once.
     *
     * @param array<string,mixed> $json
     */
    public static function claudeRefusal(array $json): ?string
    {
        if ((string) ($json['stop_reason'] ?? '') !== 'refusal') {
            return null;
        }
        $cat = (string) (($json['stop_details']['category'] ?? null) ?: 'unknown');
        Logger::warning('AI brain: Claude declined (refusal)', ['category' => $cat, 'model' => (string) ($json['model'] ?? '')], 'whatsapp');
        return $cat;
    }

    private static function openai(string $system, array $messages, int $max): ?string
    {
        $model = self::model('openai');
        $payload = [
            'model'    => $model,
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
        ];
        /* The gpt-5 / o-series models think before they write, and the
           thinking is paid out of the same budget: give room, keep effort
           low, and send no temperature (they refuse one). */
        if (preg_match('/^(gpt-5|o\d)/i', $model)) {
            $payload['max_completion_tokens'] = max(600, $max * 3);
            $payload['reasoning_effort'] = 'low';
        } else {
            $payload['max_completion_tokens'] = max(64, $max);
            $payload['temperature'] = 0.4;
        }
        [$http, $body] = self::post('https://api.openai.com/v1/chat/completions', $payload, [
            'Authorization: Bearer ' . self::key('openai'),
            'Content-Type: application/json',
        ]);
        if ($http !== 200) {
            Logger::warning('AI brain: OpenAI HTTP ' . $http, ['body' => self::redact($body)], 'whatsapp');
            return null;
        }
        $t = (string) (json_decode($body, true)['choices'][0]['message']['content'] ?? '');
        return trim($t) !== '' ? $t : null;
    }

    /** @return array{0:int, 1:string} */
    private static function post(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$http, is_string($body) ? $body : ''];
    }

    /** An error body can echo part of the key ("Incorrect API key provided: sk-…"). */
    private static function redact(string $body): string
    {
        $body = preg_replace('/\b(sk|sk-ant|sk-proj|AIza)[A-Za-z0-9_\-*]{6,}/', '[key]', $body) ?? '';
        return mb_substr($body, 0, 240);
    }

    /** ok / fail / speed per provider — never breaks an answer. */
    private static function count(string $p, bool $ok, int $ms, string $tag): void
    {
        try {
            $j = json_decode((string) Database::scalar(
                "SELECT kvalue FROM kv_store WHERE kscope = 'ai_brain' AND kkey = :k", ['k' => $p], ''), true);
            $j = is_array($j) ? $j : ['ok' => 0, 'fail' => 0, 'ms' => 0];
            $j[$ok ? 'ok' : 'fail'] = (int) ($j[$ok ? 'ok' : 'fail'] ?? 0) + 1;
            $j['last'] = date('Y-m-d H:i:s');
            if ($ok) {
                $j['last_ok'] = $j['last'];
                $n = (int) $j['ok'];
                $j['ms'] = (int) round((((int) ($j['ms'] ?? 0)) * ($n - 1) + $ms) / max(1, $n));
            }
            $j['tag'] = $tag;
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('ai_brain', :k, :v, 'ai')
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = 'ai'",
                ['k' => $p, 'v' => json_encode($j)]
            );
        } catch (Throwable $e) {
        }
    }
}
