<?php
/**
 * =====================================================================
 *  includes/agentpro-ai.php — the AI on this VPS (Oct 2026).
 *
 *  Owner: "hamro VPS jun model, tei model use hos — no extra API, afnai
 *  AI free ma. Aile ko model ramro chaina bhane update gardeu."
 *
 *  So this talks to the model server that runs ON the VPS:
 *    - Ollama (default, http://127.0.0.1:11434) through its native
 *      /api/chat — tools, a JSON-schema `format`, images, think:false
 *    - or any OpenAI-compatible server (llama.cpp, vLLM, LM Studio) via
 *      /v1/chat/completions
 *  No key, no quota, nothing leaves the machine.
 *
 *  It also knows what it is running on: RAM and CPU, the installed
 *  models and what each can do (vision / tools), which model suits this
 *  VPS, and a short Nepali / Hindi / English benchmark the owner can run
 *  before switching models.
 *
 *  NEVER THROWS from chat(): a failure is ['ok' => false, 'error' => …].
 *
 *  30 Oct 2026 (af1, K7 — the AI ladder):
 *    - health(): a cached probe (60 s up / 30 s down, LOG_PATH file) of the
 *      model server; enabled() = switch on AND the server answers, so a
 *      dead server never gives "⏳" then "could not understand" — the desk
 *      falls back to its menu, the readers to Gemini / Tesseract
 *    - docsReady(): may ID photos / paper forms use the model (ap_ai_docs)
 *    - WhatsApp first: a chat turn waits at most ap_ai_wa_wait (20 s) for
 *      the model and, while waiting, flags it; a document read (docread,
 *      formread, benchmark) never jumps that queue and hangs up its HTTP
 *      call the moment the flag appears (lastPreempted()); its own timeout
 *      is ap_ai_doc_timeout (120 s)
 *    - recommend() counts what MySQL, PHP and Valhalla already hold;
 *      installScript() prints the root commands (official install + a
 *      systemd drop-in with MemoryMax / CPUQuota / keep-alive / one model)
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ApAi
{
    /**
     * What to prefer, best first, when the office left the model on "auto".
     * Matched as name prefixes against what is installed.
     */
    private const PREFERRED_CHAT = [
        'qwen3.5:9b', 'qwen3.5:4b', 'gemma4:e4b', 'qwen3.5:27b', 'qwen3:8b', 'qwen3.5:2b', 'gemma4:e2b',
        'qwen3:4b', 'gemma3:12b', 'gemma3:4b', 'llama3.2', 'qwen2.5', 'mistral',
    ];
    private const PREFERRED_VISION = ['qwen3.5:9b', 'qwen3.5:4b', 'gemma4:e4b', 'qwen3-vl', 'gemma4:e2b', 'qwen2.5vl', 'gemma3', 'llava', 'minicpm-v'];

    /** The recommendations, by RAM. Sizes are the Ollama download (Q4). */
    public const CATALOGUE = [
        'qwen3.5:2b'  => ['gb' => 2.7, 'minRam' => 4,  'vision' => true, 'tools' => true, 'note' => 'Small VPS. Quick, but its Nepali is basic.'],
        'qwen3.5:4b'  => ['gb' => 3.4, 'minRam' => 8,  'vision' => true, 'tools' => true, 'note' => 'Best for an 8 GB VPS: tools and reading ID photos in one model.'],
        'qwen3.5:9b'  => ['gb' => 6.6, 'minRam' => 14, 'vision' => true, 'tools' => true, 'note' => 'Clearly better Nepali and Hindi; about twice as slow as 4b on CPU.'],
        'gemma4:e4b'  => ['gb' => 9.6, 'minRam' => 16, 'vision' => true, 'tools' => true, 'note' => 'Google\'s small model; good second opinion for documents.'],
        'gemma4:e2b'  => ['gb' => 7.2, 'minRam' => 10, 'vision' => true, 'tools' => true, 'note' => 'Lighter Gemma 4; reads documents and audio.'],
    ];

    /* =================================================================
     *  Settings
     * ================================================================= */

    /**
     * The WhatsApp AI is ON *and* the model server answers right now.
     *
     * 30 Oct 2026 (af1): this used to check only the switch, so with the
     * switch on and no server every unknown message got "⏳" and then
     * "could not understand". Now a down server reads as "AI off" and the
     * desk falls back to its menu (members) / the existing bot (passengers).
     * The health probe is cached (60 s up, 30 s down) so this stays cheap.
     */
    public static function enabled(): bool
    {
        return self::configured() && self::available();
    }

    /** The switch is on and a server address is set (no network call). */
    public static function configured(): bool
    {
        return Settings::getBool('ap_ai_on', false) && self::base() !== '' && function_exists('curl_init');
    }

    /**
     * May the VPS model read ID photos and paper forms? Its own switch
     * (ap_ai_docs, default on) so the office can use the model for documents
     * while WhatsApp stays on the existing bot — and only when the server
     * answers and a model that can SEE is installed. Otherwise the readers go
     * on down the ladder (Gemini, then Tesseract).
     */
    public static function docsReady(): bool
    {
        if (!(Settings::getBool('ap_ai_docs', true) || Settings::getBool('ap_ai_on', false))
            || self::base() === '' || !function_exists('curl_init') || !self::available()) {
            return false;
        }
        return self::pickModel('vision') !== '';
    }

    public static function base(): string
    {
        return rtrim(trim(Settings::getString('ap_ai_base', 'http://127.0.0.1:11434')), '/');
    }

    /** Ollama unless the address says OpenAI-compatible (/v1) — or the probe says so. */
    public static function isOllama(): bool
    {
        if (str_ends_with(self::base(), '/v1')) {
            return false;
        }
        return self::health()['kind'] === 'ollama';
    }

    /* =================================================================
     *  Health — is the model server up? (cached, so a WhatsApp turn or a
     *  form upload never waits on a dead server)
     * ================================================================= */

    public const HEALTH_UP_SEC   = 60;
    public const HEALTH_DOWN_SEC = 30;

    /** @var array<string, array<string, mixed>> per-process memo, by base URL */
    private static array $health = [];

    /** Is the model server answering (cached probe)? */
    public static function available(bool $fresh = false): bool
    {
        return (bool) self::health($fresh)['ok'];
    }

    /**
     * The cached health probe: the server answered /api/version (Ollama) or
     * /v1/models (OpenAI-compatible) and has at least one model.
     * Cached in LOG_PATH/agentpro-ai-health.json, keyed by the base URL.
     *
     * @return array{ok: bool, kind: string, version: string, models: int, at: int, error: string}
     */
    public static function health(bool $fresh = false): array
    {
        $base = self::base();
        $now = time();
        $none = ['ok' => false, 'kind' => '', 'version' => '', 'models' => 0, 'at' => $now, 'error' => 'No model server is configured.'];
        if ($base === '' || !function_exists('curl_init')) {
            return $none;
        }
        $fresh = $fresh || self::$healthFresh;
        $fresh2 = static fn(array $h): bool => isset($h['at']) && $now - (int) $h['at'] < (!empty($h['ok']) ? self::HEALTH_UP_SEC : self::HEALTH_DOWN_SEC);
        if (!$fresh && isset(self::$health[$base]) && $fresh2(self::$health[$base])) {
            return self::$health[$base];
        }
        $file = self::healthFile();
        if (!$fresh && $file !== '') {
            $all = json_decode((string) @file_get_contents($file), true);
            $h = is_array($all) ? ($all[md5($base)] ?? null) : null;
            if (is_array($h) && $fresh2($h)) {
                return self::$health[$base] = $h + $none;
            }
        }
        $h = self::probe();
        self::$health[$base] = $h;
        if ($file !== '') {
            $all = json_decode((string) @file_get_contents($file), true);
            $all = is_array($all) ? $all : [];
            $all[md5($base)] = $h;
            // write + rename: two PHP processes never see half a file
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($all)) !== false) {
                @rename($tmp, $file);
            }
        }
        return $h;
    }

    /** Forget the cached probe (the admin page, the tests, a settings save). */
    public static function resetHealth(): void
    {
        self::$health = [];
        self::$caps = [];
        $f = self::healthFile();
        if ($f !== '') {
            @unlink($f);
        }
    }

    /** Tests: every health() call probes again. */
    public static bool $healthFresh = false;

    private static function healthFile(): string
    {
        return defined('LOG_PATH') && is_dir(LOG_PATH) ? LOG_PATH . '/agentpro-ai-health.json' : '';
    }

    /** @return array{ok: bool, kind: string, version: string, models: int, at: int, error: string} */
    private static function probe(): array
    {
        $out = ['ok' => false, 'kind' => '', 'version' => '', 'models' => 0, 'at' => time(), 'error' => ''];
        if (!str_ends_with(self::base(), '/v1')) {
            $v = self::request('GET', '/api/version', null, 3);
            if ($v !== null) {
                $tags = self::request('GET', '/api/tags', null, 4);
                $n = count((array) ($tags['models'] ?? []));
                return ['ok' => $n > 0, 'kind' => 'ollama', 'version' => (string) ($v['version'] ?? ''), 'models' => $n, 'at' => time(),
                        'error' => $n > 0 ? '' : 'The model server answers but no model is installed (ollama pull …).'];
            }
            $out['error'] = self::$lastError;
        }
        $m = self::request('GET', '/models', null, 3);
        if ($m !== null) {
            $n = count((array) ($m['data'] ?? []));
            return ['ok' => $n > 0, 'kind' => 'openai', 'version' => 'openai-compatible', 'models' => $n, 'at' => time(),
                    'error' => $n > 0 ? '' : 'The server answers but lists no model.'];
        }
        $out['error'] = $out['error'] !== '' ? $out['error'] : self::$lastError;
        return $out;
    }

    private static function timeout(): int
    {
        return max(10, min(600, Settings::getInt('ap_ai_timeout', 150)));
    }

    /**
     * Seconds one document read (ID photo, one page of a paper form) may take
     * on the model. Shorter than a chat answer can be, so a read stuck on a
     * slow CPU gives way to Gemini / Tesseract instead of hanging the form.
     */
    public static function docTimeout(): int
    {
        return max(20, min(600, Settings::getInt('ap_ai_doc_timeout', 120)));
    }

    /** At most this long a WhatsApp turn waits for the model while something else is using it. */
    public static function waWait(): int
    {
        return max(3, min(60, Settings::getInt('ap_ai_wa_wait', 20)));
    }

    /** Purposes that give way to WhatsApp: they yield the model the moment a chat turn waits for it. */
    public const DOC_PURPOSES = ['docread', 'formread', 'benchmark'];

    private static bool $preempted = false;
    private static bool $busy = false;

    /** Did the last chat() stop because a WhatsApp turn needed the model (a document read gave way)? */
    public static function lastPreempted(): bool
    {
        return self::$preempted;
    }

    /** Did the last chat() fail because the model was busy for longer than it could wait? */
    public static function lastBusy(): bool
    {
        return self::$busy;
    }

    /** Why the last call failed ('' when it did not). Never contains a key. */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /* =================================================================
     *  What is installed
     * ================================================================= */

    /** Server version, or '' when nothing answers. */
    public static function version(bool $fresh = false): string
    {
        $h = self::health($fresh);
        return $h['kind'] !== '' ? ($h['version'] !== '' ? $h['version'] : $h['kind']) : '';
    }

    /**
     * Installed models with what they can do.
     *
     * @return list<array{name: string, gb: float, family: string, params: string, quant: string, vision: bool, tools: bool, thinking: bool}>
     */
    public static function models(bool $fresh = false): array
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        if (self::health()['kind'] === '') {
            return [];                                    // nothing answers: no list, and nothing cached
        }
        $out = [];
        if (self::isOllama()) {
            $r = self::get('/api/tags', 6);
            foreach ((array) ($r['models'] ?? []) as $m) {
                $name = (string) ($m['name'] ?? $m['model'] ?? '');
                if ($name === '') {
                    continue;
                }
                $caps = self::capabilities($name);
                $out[] = [
                    'name'     => $name,
                    'gb'       => round(((int) ($m['size'] ?? 0)) / 1e9, 1),
                    'family'   => (string) ($m['details']['family'] ?? ''),
                    'params'   => (string) ($m['details']['parameter_size'] ?? ''),
                    'quant'    => (string) ($m['details']['quantization_level'] ?? ''),
                    'vision'   => in_array('vision', $caps, true),
                    'tools'    => in_array('tools', $caps, true),
                    'thinking' => in_array('thinking', $caps, true),
                ];
            }
        } else {
            $r = self::get('/models', 6);
            foreach ((array) ($r['data'] ?? []) as $m) {
                $name = (string) ($m['id'] ?? '');
                if ($name !== '') {
                    $lower = strtolower($name);
                    $out[] = ['name' => $name, 'gb' => 0.0, 'family' => '', 'params' => '', 'quant' => '',
                              'vision' => (bool) preg_match('/vl|vision|gemma[34]|qwen3\.5|llava|minicpm-v/', $lower),
                              'tools' => true, 'thinking' => false];
                }
            }
        }
        return $cache = $out;
    }

    /** @var array<string, list<string>> */
    private static array $caps = [];

    /** Ollama /api/show capabilities: completion, vision, tools, thinking. */
    public static function capabilities(string $model): array
    {
        if (!isset(self::$caps[$model])) {
            $r = self::post('/api/show', ['model' => $model], 8);
            $caps = array_map('strval', (array) ($r['capabilities'] ?? []));
            if ($caps === [] && $r !== null) {
                // Older Ollama: no capability list. Guess from the name.
                $l = strtolower($model);
                $caps = ['completion'];
                if (preg_match('/vl|vision|gemma[34]|qwen3\.5|llava|minicpm-v/', $l)) { $caps[] = 'vision'; }
                if (preg_match('/qwen|llama3|mistral|gemma4|granite|command/', $l)) { $caps[] = 'tools'; }
            }
            self::$caps[$model] = $caps;
        }
        return self::$caps[$model];
    }

    /** Models loaded in memory right now (Ollama /api/ps). @return list<array<string, mixed>> */
    public static function running(): array
    {
        if (!self::isOllama()) {
            return [];
        }
        $r = self::get('/api/ps', 4);
        return array_values((array) ($r['models'] ?? []));
    }

    /**
     * The model to use for chat or for reading documents: the setting when
     * it is installed, else the best installed one by PREFERRED_*.
     */
    public static function pickModel(string $purpose = 'chat'): string
    {
        $want = trim(Settings::getString($purpose === 'vision' ? 'ap_ai_vision_model' : 'ap_ai_model', ''));
        $models = self::models();
        $names = array_column($models, 'name');
        if ($want !== '' && (in_array($want, $names, true) || in_array($want . ':latest', $names, true) || !self::isOllama())) {
            return $want;
        }
        if ($purpose === 'vision') {
            $chat = self::pickModel('chat');
            foreach ($models as $m) {
                if ($m['name'] === $chat && $m['vision']) {
                    return $chat;                         // one model in memory is faster than two
                }
            }
        }
        foreach ($purpose === 'vision' ? self::PREFERRED_VISION : self::PREFERRED_CHAT as $prefix) {
            foreach ($models as $m) {
                if (str_starts_with($m['name'], $prefix) && ($purpose !== 'vision' || $m['vision'])) {
                    return $m['name'];
                }
            }
        }
        foreach ($models as $m) {
            if ($purpose !== 'vision' || $m['vision']) {
                return $m['name'];
            }
        }
        return '';
    }

    /* =================================================================
     *  Talking to the model
     * ================================================================= */

    /**
     * One chat call.
     *
     * @param list<array{role: string, content: mixed, images?: list<string>, tool_calls?: array}> $messages
     *        content is text; images are base64 (no data: prefix) on a user message
     * @param array{model?: string, tools?: list<array>, format?: array, temperature?: float,
     *              max_tokens?: int, timeout?: int, purpose?: string, ctx?: int} $o
     *        tools are OpenAI-style: [{type: function, function: {name, description, parameters}}]
     * @return array{ok: bool, text: string, calls: list<array{id: string, name: string, input: array}>,
     *               model: string, ms: int, tokensIn: int, tokensOut: int, error: string}
     */
    public static function chat(array $messages, array $o = []): array
    {
        $t0 = microtime(true);
        self::$preempted = false;
        self::$busy = false;
        $model = (string) ($o['model'] ?? '') ?: self::pickModel(!empty($o['vision']) ? 'vision' : 'chat');
        $fail = static fn(string $why) => ['ok' => false, 'text' => '', 'calls' => [], 'model' => $model,
            'ms' => (int) ((microtime(true) - $t0) * 1000), 'tokensIn' => 0, 'tokensOut' => 0, 'error' => $why];
        if ($model === '') {
            return $fail('No model is installed on the model server.');
        }
        $purpose = (string) ($o['purpose'] ?? 'chat');
        // 30 Oct 2026 (af1): documents give way to WhatsApp. A document read waits for the model only
        // while no chat turn is waiting, and its HTTP call is cut the moment one starts waiting (Ollama
        // stops generating when the client hangs up); the caller retries later or goes down its ladder.
        // A chat turn waits at most ap_ai_wa_wait (20 s) for the model.
        $doc = in_array($purpose, self::DOC_PURPOSES, true);
        $timeout = (int) ($o['timeout'] ?? ($doc ? self::docTimeout() : self::timeout()));
        $wait = $doc ? (int) ($o['lock_wait'] ?? min($timeout, 60)) : min($timeout, self::waWait());
        // One model call at a time: on a CPU two parallel calls are each slower than one after the other.
        $lock = self::lock($wait, !$doc);
        if ($lock === false) {
            self::$busy = true;
            if ($doc && self::someoneWaiting()) {
                self::$preempted = true;
                return $fail('The model is answering WhatsApp first; the document is read again in a moment.');
            }
            return $fail('The model is busy with other messages; try again in a minute.');
        }
        self::$yieldNow = $doc;
        try {
            $out = self::isOllama() ? self::chatOllama($model, $messages, $o, $timeout) : self::chatOpenAi($model, $messages, $o, $timeout);
        } catch (Throwable $e) {
            $out = null;
            $err = $e->getMessage();
        } finally {
            self::$yieldNow = false;
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        $ms = (int) ((microtime(true) - $t0) * 1000);
        if ($out === null) {
            $r = $fail(($err ?? '') !== '' ? $err : (self::$lastError ?: 'The model server did not answer.'));
            self::log($purpose, $model, $ms, false, 0, 0, $r['error']);
            return $r;
        }
        self::log($purpose, $model, $ms, true, $out['tokensIn'], $out['tokensOut'], '');
        return ['ok' => true, 'model' => $model, 'ms' => $ms, 'error' => ''] + $out;
    }

    /**
     * Ask for JSON that matches a schema; null when the model could not.
     *
     * @param array<string, mixed> $schema JSON schema of an object
     * @param list<string> $images base64 images for the question
     * @return array<string, mixed>|null
     */
    public static function json(string $system, string $user, array $schema, array $images = [], array $o = []): ?array
    {
        $msgs = [['role' => 'system', 'content' => $system . "\nAnswer with JSON only."], ['role' => 'user', 'content' => $user]];
        if ($images !== []) {
            $msgs[1]['images'] = $images;
        }
        $r = self::chat($msgs, ['format' => $schema, 'temperature' => 0.0, 'vision' => $images !== []] + $o);
        if (!$r['ok']) {
            return null;
        }
        $text = trim($r['text']);
        // Some servers wrap JSON in a ```json fence even when asked not to.
        if (preg_match('/\{.*\}/s', $text, $m) === 1) {
            $text = $m[0];
        }
        $d = json_decode($text, true);
        return is_array($d) ? $d : null;
    }

    private static string $lastError = '';

    /** True while a document read holds the model: request() then cuts its call when a chat turn starts waiting. */
    private static bool $yieldNow = false;

    /**
     * @param bool $urgent a chat turn: while it waits it keeps the "someone is waiting" marker fresh, so a
     *                     document read in another process hangs up and frees the model
     * @return resource|false|null a held lock, false when it was not free in time, null when locking is impossible
     */
    private static function lock(int $wait, bool $urgent = true)
    {
        $f = @fopen(LOG_PATH . '/agentpro-ai.lock', 'c');
        if ($f === false) {
            return null;
        }
        $end = microtime(true) + max(1, $wait);
        while (true) {
            // a document read never jumps in front of a waiting chat turn
            if (($urgent || !self::someoneWaiting()) && flock($f, LOCK_EX | LOCK_NB)) {
                return $f;
            }
            if ($urgent) {
                @touch(self::wantFile());
            } elseif (self::someoneWaiting()) {
                break;                                    // a chat turn is queued: give up now, retry later
            }
            if (microtime(true) > $end) {
                break;
            }
            usleep(150000);
        }
        fclose($f);
        return false;
    }

    private static function wantFile(): string
    {
        return LOG_PATH . '/agentpro-ai.want';
    }

    /** Is a chat turn waiting for the model right now (marker touched in the last 3 s)? */
    public static function someoneWaiting(): bool
    {
        $f = self::wantFile();
        clearstatcache(true, $f);
        $t = @filemtime($f);
        return $t !== false && $t >= time() - 3;
    }

    /** How long Ollama keeps the model in RAM after a call (ap_ai_keep_alive, e.g. 30m; 0 = unload at once). */
    public static function keepAlive(): string
    {
        $k = trim(Settings::getString('ap_ai_keep_alive', '30m'));
        return preg_match('/^(?:-1|0|\d{1,4}[smh])$/', $k) === 1 ? $k : '30m';
    }

    /** @return array{text: string, calls: list<array>, tokensIn: int, tokensOut: int}|null */
    private static function chatOllama(string $model, array $messages, array $o, int $timeout): ?array
    {
        $msgs = [];
        foreach ($messages as $m) {
            $row = ['role' => (string) $m['role'], 'content' => (string) ($m['content'] ?? '')];
            if (!empty($m['images'])) {
                $row['images'] = array_values($m['images']);
            }
            if (!empty($m['tool_calls'])) {
                $row['tool_calls'] = array_map(static fn(array $c): array => [
                    'function' => ['name' => (string) $c['name'], 'arguments' => (object) ($c['input'] ?? [])],
                ], $m['tool_calls']);
            }
            if (($m['role'] ?? '') === 'tool' && !empty($m['name'])) {
                $row['tool_name'] = (string) $m['name'];
            }
            $msgs[] = $row;
        }
        $payload = [
            'model'      => $model,
            'messages'   => $msgs,
            'stream'     => false,
            'keep_alive' => self::keepAlive(),
            'options'    => array_filter([
                'temperature' => (float) ($o['temperature'] ?? 0.2),
                'num_predict' => (int) ($o['max_tokens'] ?? 600),
                'num_ctx'     => (int) ($o['ctx'] ?? 8192),
            ], static fn($v): bool => $v !== null),
        ];
        if (!empty($o['tools'])) {
            $payload['tools'] = $o['tools'];
        }
        if (!empty($o['format'])) {
            $payload['format'] = $o['format'];
        }
        // Thinking costs a minute on CPU; switch it off where the model has it.
        if (in_array('thinking', self::capabilities($model), true)) {
            $payload['think'] = false;
        }
        $r = self::post('/api/chat', $payload, $timeout);
        if ($r === null) {
            return null;
        }
        $msg = (array) ($r['message'] ?? []);
        $calls = [];
        foreach ((array) ($msg['tool_calls'] ?? []) as $i => $c) {
            $fn = (array) ($c['function'] ?? []);
            $args = $fn['arguments'] ?? [];
            if (is_string($args)) {
                $args = json_decode($args, true) ?: [];
            }
            $calls[] = ['id' => 'call_' . $i . '_' . substr(md5((string) microtime(true)), 0, 6), 'name' => (string) ($fn['name'] ?? ''),
                        'input' => is_array($args) ? $args : []];
        }
        $text = (string) ($msg['content'] ?? '');
        // A model that ignores think:false still must not show its reasoning to a passenger.
        $text = trim((string) preg_replace('#<think>.*?</think>#s', '', $text));
        return ['text' => $text, 'calls' => $calls, 'tokensIn' => (int) ($r['prompt_eval_count'] ?? 0), 'tokensOut' => (int) ($r['eval_count'] ?? 0)];
    }

    /** @return array{text: string, calls: list<array>, tokensIn: int, tokensOut: int}|null */
    private static function chatOpenAi(string $model, array $messages, array $o, int $timeout): ?array
    {
        $msgs = [];
        foreach ($messages as $m) {
            if (!empty($m['images'])) {
                $parts = [['type' => 'text', 'text' => (string) ($m['content'] ?? '')]];
                foreach ($m['images'] as $b64) {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $b64]];
                }
                $msgs[] = ['role' => (string) $m['role'], 'content' => $parts];
                continue;
            }
            $row = ['role' => (string) $m['role'], 'content' => (string) ($m['content'] ?? '')];
            if (!empty($m['tool_calls'])) {
                $row['tool_calls'] = array_map(static fn(array $c): array => [
                    'id' => (string) $c['id'], 'type' => 'function',
                    'function' => ['name' => (string) $c['name'], 'arguments' => json_encode((object) ($c['input'] ?? []))],
                ], $m['tool_calls']);
            }
            if (($m['role'] ?? '') === 'tool') {
                $row['tool_call_id'] = (string) ($m['tool_call_id'] ?? '');
            }
            $msgs[] = $row;
        }
        $payload = ['model' => $model, 'messages' => $msgs, 'temperature' => (float) ($o['temperature'] ?? 0.2),
                    'max_tokens' => (int) ($o['max_tokens'] ?? 600)];
        if (!empty($o['tools'])) {
            $payload['tools'] = $o['tools'];
            $payload['tool_choice'] = 'auto';
        }
        if (!empty($o['format'])) {
            $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'answer', 'schema' => $o['format']]];
        }
        $r = self::post('/chat/completions', $payload, $timeout);
        if ($r === null) {
            return null;
        }
        $msg = (array) ($r['choices'][0]['message'] ?? []);
        $calls = [];
        foreach ((array) ($msg['tool_calls'] ?? []) as $c) {
            $args = json_decode((string) ($c['function']['arguments'] ?? '{}'), true);
            $calls[] = ['id' => (string) ($c['id'] ?? ''), 'name' => (string) ($c['function']['name'] ?? ''), 'input' => is_array($args) ? $args : []];
        }
        $text = trim((string) preg_replace('#<think>.*?</think>#s', '', (string) ($msg['content'] ?? '')));
        return ['text' => $text, 'calls' => $calls, 'tokensIn' => (int) ($r['usage']['prompt_tokens'] ?? 0),
                'tokensOut' => (int) ($r['usage']['completion_tokens'] ?? 0)];
    }

    /** Anthropic-shaped tool specs (AiTools::catalogue) → OpenAI / Ollama function specs. */
    public static function toolsFromAnthropic(array $tools): array
    {
        return array_map(static fn(array $t): array => [
            'type' => 'function',
            'function' => ['name' => (string) $t['name'], 'description' => (string) ($t['description'] ?? ''),
                           'parameters' => $t['input_schema'] ?? ['type' => 'object', 'properties' => (object) []]],
        ], $tools);
    }

    /* =================================================================
     *  Pulling a model (the "update the model" button)
     * ================================================================= */

    /**
     * Start downloading a model in the background. Ollama keeps going even
     * when this request ends, so the page just polls models() afterwards.
     */
    public static function pull(string $model): array
    {
        if (!self::isOllama()) {
            return ['ok' => false, 'error' => 'Pulling models works with Ollama. On another server, download the model there.'];
        }
        if (preg_match('#^[a-z0-9][a-z0-9._/-]{0,80}(:[a-z0-9._-]{1,40})?$#i', $model) !== 1) {
            return ['ok' => false, 'error' => 'That does not look like a model name (e.g. qwen3.5:4b).'];
        }
        // stream:true with a 3 s timeout: Ollama starts the download and carries on after we hang up.
        $ch = curl_init(self::base() . '/api/pull');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'stream' => true]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3, CURLOPT_NOPROXY => '*',
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            return ['ok' => false, 'error' => 'The model server refused: ' . mb_substr($body, 0, 200)];
        }
        Logger::audit('agentpro.ai_pull', 'ai', $model, null, null, 'Model download started');
        return ['ok' => true, 'started' => true];
    }

    /* =================================================================
     *  What this VPS can run
     * ================================================================= */

    /** @return array{ramGb: float, availGb: float, cpus: int, diskGb: float, gpu: bool, os: string} */
    public static function hardware(): array
    {
        $ram = 0.0;
        $avail = 0.0;
        $info = @file_get_contents('/proc/meminfo');
        if (is_string($info)) {
            if (preg_match('/MemTotal:\s+(\d+)/', $info, $m)) { $ram = round((int) $m[1] / 1048576, 1); }
            if (preg_match('/MemAvailable:\s+(\d+)/', $info, $m)) { $avail = round((int) $m[1] / 1048576, 1); }
        }
        $cpus = 0;
        $cpu = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpu)) {
            $cpus = substr_count($cpu, "\nprocessor") + (str_starts_with($cpu, 'processor') ? 1 : 0);
        }
        $disk = @disk_free_space(ROOT_PATH);
        $others = self::otherServices();
        return [
            'ramGb'      => $ram,
            'availGb'    => $avail,
            'cpus'       => max(1, $cpus),
            'diskGb'     => $disk !== false ? round($disk / 1e9, 1) : 0.0,
            'gpu'        => is_dir('/proc/driver/nvidia') || is_file('/dev/nvidia0'),
            'os'         => PHP_OS_FAMILY,
            // 30 Oct 2026 (af1): what the other services on this box hold right now (null = not measurable here)
            'othersGb'   => $others['total'],
            'valhallaGb' => $others['valhalla'],
            'services'   => $others['list'],
        ];
    }

    /**
     * Resident memory of the other big services on this box (MySQL / MariaDB,
     * PHP-FPM, nginx / Apache, the Valhalla route engine in Docker, Redis),
     * read from /proc. The model server itself is left out. null totals when
     * /proc cannot be read (Windows, open_basedir).
     *
     * @return array{total: ?float, valhalla: ?float, list: array<string, float>}
     */
    public static function otherServices(): array
    {
        $none = ['total' => null, 'valhalla' => null, 'list' => []];
        if (!@is_dir('/proc/self')) {
            return $none;
        }
        $pids = @scandir('/proc');
        if (!is_array($pids)) {
            return $none;
        }
        $groups = ['mysql' => '/^(mysqld|mariadbd)$/', 'php' => '/^php(-fpm|\d|\.|$)|^php-fpm/', 'web' => '/^(nginx|apache2|httpd|caddy)$/',
                   'valhalla' => '/valhalla/', 'docker' => '/^(dockerd|containerd)/', 'redis' => '/^redis/'];
        $sum = array_fill_keys(array_keys($groups), 0.0);
        $seen = 0;
        foreach ($pids as $pid) {
            if (!ctype_digit($pid)) {
                continue;
            }
            $comm = trim((string) @file_get_contents('/proc/' . $pid . '/comm'));
            if ($comm === '' || str_starts_with($comm, 'ollama')) {
                continue;
            }
            foreach ($groups as $g => $re) {
                if (preg_match($re, $comm) === 1) {
                    $st = (string) @file_get_contents('/proc/' . $pid . '/status');
                    if (preg_match('/VmRSS:\s+(\d+)/', $st, $m) === 1) {
                        $sum[$g] += (int) $m[1] / 1048576;
                        $seen++;
                    }
                    break;
                }
            }
        }
        if ($seen === 0) {
            return $none;
        }
        $list = array_map(static fn(float $v): float => round($v, 2), array_filter($sum, static fn(float $v): bool => $v > 0.01));
        return ['total' => round(array_sum($sum), 1), 'valhalla' => round($sum['valhalla'], 2), 'list' => $list];
    }

    /** RAM a model needs when loaded with the context we use (weights + KV cache + runtime), GB. */
    public const MODEL_NEED_GB = ['qwen3.5:2b' => 2.6, 'qwen3.5:4b' => 4.0, 'qwen3.5:9b' => 7.5, 'gemma4:e4b' => 10.5];

    /**
     * What to run on this machine.
     *
     * 30 Oct 2026 (af1): the model gets what the OTHER services leave, not
     * "RAM minus 3 GB". Measured from /proc when possible; otherwise the
     * site, MySQL and PHP are counted as 2 GB and the Valhalla route engine
     * (Docker) as 1 GB (it uses 0.3-2 GB depending on the tiles it has
     * loaded). Half a GB stays free for the page cache. On this 8 GB / 2 vCPU
     * VPS that leaves ~4-4.5 GB: qwen3.5:4b (one model for WhatsApp and for
     * reading documents), capped by a systemd MemoryMax so it can never push
     * MySQL out.
     *
     * @return array{chat: string, vision: string, why: string, alternatives: list<string>, budgetGb: float,
     *               othersGb: float, measured: bool, memoryMaxMb: int, cpuQuota: int, ctx: int, warn: string}
     */
    public static function recommend(?array $hw = null): array
    {
        $hw = $hw ?? self::hardware();
        $ram = (float) ($hw['ramGb'] ?? 0);
        $unknown = $ram <= 0;
        if ($unknown) {
            $ram = 8.0;                                   // /proc not readable here: plan for the company VPS (8 GB, 2 vCPU)
            $hw['cpus'] = max(2, (int) ($hw['cpus'] ?? 2));
        }
        $cpus = max(1, (int) ($hw['cpus'] ?? 2));
        $measured = isset($hw['othersGb']) && is_numeric($hw['othersGb']) && (float) $hw['othersGb'] > 0;
        $valhalla = isset($hw['valhallaGb']) && is_numeric($hw['valhallaGb']) && (float) $hw['valhallaGb'] > 0 ? (float) $hw['valhallaGb'] : 1.0;
        // measured services + 0.5 GB head-room for PHP spikes; else the estimate
        $others = $measured ? max(1.5, (float) $hw['othersGb'] + 0.5) : 2.0 + $valhalla;
        $budget = round(max(0.0, $ram - $others - 0.5), 1);
        $common = ['budgetGb' => $budget, 'othersGb' => round($others, 1), 'measured' => $measured, 'ramKnown' => !$unknown,
                   'cpuQuota' => max(100, (int) (floor($cpus * 0.75 * 2) / 2 * 100))];
        $cap = static fn(string $m): int => (int) (ceil(min(max(self::MODEL_NEED_GB[$m] + 0.5, $budget), max(self::MODEL_NEED_GB[$m] + 0.5, $ram * 0.6)) * 2) / 2 * 1024);
        if (!empty($hw['gpu']) || $budget >= 18.5) {
            return ['chat' => 'qwen3.5:9b', 'vision' => 'gemma4:e4b', 'alternatives' => ['qwen3.5:27b'], 'ctx' => 8192, 'warn' => '',
                    'memoryMaxMb' => (int) (ceil($budget * 2) / 2 * 1024),
                    'why' => 'Plenty of memory: the 9B model answers WhatsApp while Gemma 4 reads ID photos and paper forms, both stay loaded.'] + $common;
        }
        if ($budget >= self::MODEL_NEED_GB['qwen3.5:9b']) {
            return ['chat' => 'qwen3.5:9b', 'vision' => 'qwen3.5:9b', 'alternatives' => ['qwen3.5:4b', 'gemma4:e4b'], 'ctx' => 8192, 'warn' => '',
                    'memoryMaxMb' => $cap('qwen3.5:9b'),
                    'why' => sprintf('About %.1f GB is free for the model after the other services (%.1f GB): the 9B model fits and speaks clearly better Nepali and Hindi. It also reads ID photos and forms.', $budget, $others)] + $common;
        }
        if ($budget >= self::MODEL_NEED_GB['qwen3.5:4b'] - 0.3 || ($ram >= 7.5 && $budget >= 3.2)) {
            $tight = $budget < self::MODEL_NEED_GB['qwen3.5:4b'];
            return ['chat' => 'qwen3.5:4b', 'vision' => 'qwen3.5:4b', 'alternatives' => ['qwen3.5:2b'], 'ctx' => $tight ? 4096 : 8192,
                    'memoryMaxMb' => $cap('qwen3.5:4b'),
                    'warn' => $tight ? 'Memory is tight: keep one model (OLLAMA_MAX_LOADED_MODELS=1), context 4096, and the MemoryMax cap below.' : '',
                    'why' => sprintf('About %.1f GB is free for the model after the website, MySQL and the route engine (%.1f GB): '
                        . 'qwen3.5:4b (3.4 GB download) fits — one model answers WhatsApp and reads ID photos and paper forms. '
                        . 'On %d CPU cores expect ~5-20 s for a WhatsApp answer and 1-3 min for a form page; Gemini / Tesseract take over when it is slower.', $budget, $others, $cpus)] + $common;
        }
        return ['chat' => 'qwen3.5:2b', 'vision' => 'qwen3.5:2b', 'alternatives' => [], 'ctx' => 4096,
                'memoryMaxMb' => $cap('qwen3.5:2b'),
                'warn' => $budget < self::MODEL_NEED_GB['qwen3.5:2b'] ? 'Not enough free memory even for the 2B model — keep using Gemini / Tesseract, or add RAM.' : '',
                'why' => 'Little memory: the 2B model fits, but expect simpler Nepali and weaker handwriting reading. More RAM is the best upgrade for the AI.'] + $common;
    }

    /**
     * The exact root commands to install Ollama on this VPS with limits — the
     * admin page cannot install anything itself (PHP runs as www-data).
     */
    public static function installScript(?array $rec = null): string
    {
        $rec = $rec ?? self::recommend();
        $models = array_values(array_unique([$rec['chat'], $rec['vision']]));
        $port = (string) (parse_url(self::base(), PHP_URL_PORT) ?: 11434);
        $lines = [
            '# 1. Install Ollama (official script), as root:',
            'curl -fsSL https://ollama.com/install.sh | sh',
            '',
            '# 2. Limits so the model never starves the website, MySQL or Valhalla:',
            'mkdir -p /etc/systemd/system/ollama.service.d',
            "cat > /etc/systemd/system/ollama.service.d/shg-limits.conf <<'EOF'",
            '[Service]',
            'Environment="OLLAMA_HOST=127.0.0.1:' . $port . '"',
            'Environment="OLLAMA_NUM_PARALLEL=1"',
            'Environment="OLLAMA_MAX_LOADED_MODELS=1"',
            'Environment="OLLAMA_KEEP_ALIVE=' . self::keepAlive() . '"',
            'Environment="OLLAMA_CONTEXT_LENGTH=' . (int) $rec['ctx'] . '"',
            'MemoryMax=' . (int) $rec['memoryMaxMb'] . 'M',
            'MemorySwapMax=0',
            'CPUQuota=' . (int) $rec['cpuQuota'] . '%',
            'Nice=10',
            'EOF',
            'systemctl daemon-reload',
            'systemctl enable --now ollama',
            'systemctl restart ollama',
            '',
            '# 3. The model' . (count($models) > 1 ? 's' : '') . ' for this VPS:',
        ];
        foreach ($models as $m) {
            $lines[] = 'ollama pull ' . $m;
        }
        $lines[] = '';
        $lines[] = '# 4. Check (should print a version and the model):';
        $lines[] = 'curl -s http://127.0.0.1:' . $port . '/api/version && ollama list';
        return implode("\n", $lines);
    }

    /* =================================================================
     *  The benchmark — run on THIS VPS before switching models
     * ================================================================= */

    /**
     * Five short tasks in the three languages the desk receives, each with
     * a checkable answer. Returns per-task pass/fail and seconds.
     *
     * @return array{model: string, score: int, of: int, seconds: float, tasks: list<array<string, mixed>>}
     */
    public static function benchmark(string $model = ''): array
    {
        $model = $model !== '' ? $model : self::pickModel('chat');
        $schema = ['type' => 'object', 'properties' => [
            'seats' => ['type' => 'integer'], 'date' => ['type' => 'string'], 'pickup' => ['type' => 'string'],
            'name' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'intent' => ['type' => 'string'],
        ], 'required' => ['intent']];
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $sys = "You read WhatsApp messages to a bus company (India-Nepal). Today is $today. "
             . "Return intent (book | status | balance | other), seats, date as YYYY-MM-DD, pickup town, passenger name and 10-digit phone when given.";
        $tasks = [
            ['lang' => 'ne', 'q' => 'भोलि २ सिट चाहियो, मेहसाना बाट। नाम राम थापा 9812345678', 'want' => ['intent' => 'book', 'seats' => 2, 'date' => $tomorrow, 'phone' => '9812345678', 'pickup' => 'mehsana']],
            ['lang' => 'ne-rom', 'q' => 'bholi 3 jana surat bata jane, sita gurung 9801122334', 'want' => ['intent' => 'book', 'seats' => 3, 'date' => $tomorrow, 'phone' => '9801122334', 'pickup' => 'surat']],
            ['lang' => 'hi', 'q' => 'कल के लिए 1 सीट चाहिए अहमदाबाद से, नाम सुनील 9876543210', 'want' => ['intent' => 'book', 'seats' => 1, 'date' => $tomorrow, 'phone' => '9876543210', 'pickup' => 'ahmedabad']],
            ['lang' => 'en', 'q' => 'What is my wallet balance?', 'want' => ['intent' => 'balance']],
            ['lang' => 'ne', 'q' => 'मेरो टिकट SHG-2026-00123 कहाँ पुग्यो?', 'want' => ['intent' => 'status']],
        ];
        $out = [];
        $score = 0;
        $t0 = microtime(true);
        foreach ($tasks as $t) {
            $r = self::chat([['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $t['q']]],
                ['model' => $model, 'format' => $schema, 'temperature' => 0.0, 'purpose' => 'benchmark', 'max_tokens' => 200]);
            $got = $r['ok'] ? (json_decode(preg_match('/\{.*\}/s', $r['text'], $mm) ? $mm[0] : $r['text'], true) ?: []) : [];
            $ok = $r['ok'];
            $miss = [];
            foreach ($t['want'] as $k => $v) {
                $g = $got[$k] ?? null;
                $hit = match ($k) {
                    'pickup' => is_string($g) && str_contains(strtolower($g), (string) $v),
                    'phone'  => is_string($g) && str_contains(preg_replace('/\D/', '', $g) ?? '', (string) $v),
                    'seats'  => (int) $g === (int) $v,
                    default  => is_string($g) && strtolower(trim($g)) === (string) $v,
                };
                if (!$hit) {
                    $ok = false;
                    $miss[] = $k;
                }
            }
            if ($ok) {
                $score++;
            }
            $out[] = ['lang' => $t['lang'], 'q' => $t['q'], 'ok' => $ok, 'missed' => $miss, 'ms' => $r['ms'], 'error' => $r['error']];
        }
        return ['model' => $model, 'score' => $score, 'of' => count($tasks), 'seconds' => round(microtime(true) - $t0, 1), 'tasks' => $out];
    }

    /* =================================================================
     *  Health for the admin page
     * ================================================================= */

    /** @return array{calls: int, failed: int, avgMs: int, p90Ms: int, last: ?array} last 24 h */
    public static function stats(): array
    {
        try {
            $rows = Database::fetchAll('SELECT ms, ok FROM ap_ai_calls WHERE created_at > (NOW() - INTERVAL 1 DAY) ORDER BY ms');
            $last = Database::fetch('SELECT * FROM ap_ai_calls ORDER BY id DESC LIMIT 1');
        } catch (Throwable $e) {
            return ['calls' => 0, 'failed' => 0, 'avgMs' => 0, 'p90Ms' => 0, 'last' => null];
        }
        $ms = array_map(static fn(array $r): int => (int) $r['ms'], array_filter($rows, static fn(array $r): bool => (int) $r['ok'] === 1));
        sort($ms);
        return [
            'calls'  => count($rows),
            'failed' => count($rows) - count($ms),
            'avgMs'  => $ms === [] ? 0 : (int) (array_sum($ms) / count($ms)),
            'p90Ms'  => $ms === [] ? 0 : $ms[(int) floor((count($ms) - 1) * 0.9)],
            'last'   => $last,
        ];
    }

    private static function log(string $purpose, string $model, int $ms, bool $ok, int $tin, int $tout, string $note): void
    {
        try {
            Database::insert('ap_ai_calls', ['purpose' => mb_substr($purpose, 0, 30), 'model' => mb_substr($model, 0, 80), 'ms' => $ms,
                'ok' => $ok ? 1 : 0, 'tokens_in' => $tin, 'tokens_out' => $tout, 'note' => $note !== '' ? mb_substr($note, 0, 255) : null]);
        } catch (Throwable $e) {
            // the call log is a convenience
        }
    }

    /* =================================================================
     *  HTTP
     * ================================================================= */

    /** OpenAI-compatible paths sit under /v1; Ollama's under /api. */
    private static function url(string $path): string
    {
        $b = self::base();
        if (!str_starts_with($path, '/api/') && !str_ends_with($b, '/v1')) {
            $b .= '/v1';
        }
        return $b . $path;
    }

    /** @return array<string, mixed>|null */
    private static function get(string $path, int $timeout): ?array
    {
        return self::request('GET', $path, null, $timeout);
    }

    /** @return array<string, mixed>|null */
    private static function post(string $path, array $payload, int $timeout): ?array
    {
        return self::request('POST', $path, $payload, $timeout);
    }

    /** @return array<string, mixed>|null */
    private static function request(string $method, string $path, ?array $payload, int $timeout): ?array
    {
        self::$lastError = '';
        if (!function_exists('curl_init') || self::base() === '') {
            self::$lastError = 'No model server is configured.';
            return null;
        }
        $headers = ['Accept: application/json'];
        $key = trim(Settings::getString('ap_ai_api_key', ''));
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        $ch = curl_init(self::url($path));
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_NOPROXY        => '*',             // the model lives on this machine / network
        ];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        $cut = false;
        if (self::$yieldNow && $method === 'POST') {
            // A document read: hang up as soon as a WhatsApp turn waits for the model.
            $next = 0.0;
            $opts[CURLOPT_NOPROGRESS] = false;
            $opts[CURLOPT_XFERINFOFUNCTION] = static function () use (&$next, &$cut): int {
                $now = microtime(true);
                if ($now < $next) {
                    return 0;
                }
                $next = $now + 0.5;
                if (self::someoneWaiting()) {
                    $cut = true;
                    return 1;                             // abort the transfer
                }
                return 0;
            };
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($cut) {
            self::$preempted = true;
            self::$lastError = 'Stopped so the model could answer WhatsApp first; the document is read again in a moment.';
            return null;
        }
        if ($body === false || $code === 0) {
            self::$lastError = 'Model server not reachable at ' . self::base() . ($err !== '' ? ' (' . $err . ')' : '');
            return null;
        }
        $data = json_decode((string) $body, true);
        if ($code >= 400) {
            self::$lastError = 'Model server error ' . $code . ': ' . mb_substr((string) ($data['error'] ?? $body), 0, 200);
            return null;
        }
        return is_array($data) ? $data : null;
    }
}
