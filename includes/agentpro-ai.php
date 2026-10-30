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

    public static function enabled(): bool
    {
        return Settings::getBool('ap_ai_on', false) && self::base() !== '' && function_exists('curl_init');
    }

    public static function base(): string
    {
        return rtrim(trim(Settings::getString('ap_ai_base', 'http://127.0.0.1:11434')), '/');
    }

    /** Ollama unless the address says OpenAI-compatible (/v1) — or the probe says so. */
    public static function isOllama(): bool
    {
        $b = self::base();
        if (str_ends_with($b, '/v1')) {
            return false;
        }
        static $probe = null;
        if ($probe === null) {
            $probe = self::get('/api/version', 4) !== null;
        }
        return $probe;
    }

    private static function timeout(): int
    {
        return max(10, min(600, Settings::getInt('ap_ai_timeout', 150)));
    }

    /* =================================================================
     *  What is installed
     * ================================================================= */

    /** Server version, or '' when nothing answers. */
    public static function version(): string
    {
        if (self::isOllama()) {
            $r = self::get('/api/version', 4);
            return (string) ($r['version'] ?? '');
        }
        return self::get('/models', 4) !== null ? 'openai-compatible' : '';
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
        $model = (string) ($o['model'] ?? '') ?: self::pickModel(!empty($o['vision']) ? 'vision' : 'chat');
        $fail = static fn(string $why) => ['ok' => false, 'text' => '', 'calls' => [], 'model' => $model,
            'ms' => (int) ((microtime(true) - $t0) * 1000), 'tokensIn' => 0, 'tokensOut' => 0, 'error' => $why];
        if ($model === '') {
            return $fail('No model is installed on the model server.');
        }
        $purpose = (string) ($o['purpose'] ?? 'chat');
        $timeout = (int) ($o['timeout'] ?? self::timeout());
        // One model call at a time: on a CPU two parallel calls are each slower than one after the other.
        $lock = self::lock($timeout);
        if ($lock === false) {
            return $fail('The model is busy with other messages; try again in a minute.');
        }
        try {
            $out = self::isOllama() ? self::chatOllama($model, $messages, $o, $timeout) : self::chatOpenAi($model, $messages, $o, $timeout);
        } catch (Throwable $e) {
            $out = null;
            $err = $e->getMessage();
        } finally {
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

    /** @return resource|false|null a held lock, false when it was not free in time, null when locking is impossible */
    private static function lock(int $wait)
    {
        $f = @fopen(LOG_PATH . '/agentpro-ai.lock', 'c');
        if ($f === false) {
            return null;
        }
        $end = microtime(true) + max(1, $wait);
        while (!flock($f, LOCK_EX | LOCK_NB)) {
            if (microtime(true) > $end) {
                fclose($f);
                return false;
            }
            usleep(150000);
        }
        return $f;
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
            'keep_alive' => '30m',
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
        return [
            'ramGb'   => $ram,
            'availGb' => $avail,
            'cpus'    => max(1, $cpus),
            'diskGb'  => $disk !== false ? round($disk / 1e9, 1) : 0.0,
            'gpu'     => is_dir('/proc/driver/nvidia') || is_file('/dev/nvidia0'),
            'os'      => PHP_OS_FAMILY,
        ];
    }

    /**
     * What to run on this machine. The site, MySQL and PHP keep ~3 GB; the
     * model gets the rest.
     *
     * @return array{chat: string, vision: string, why: string, alternatives: list<string>}
     */
    public static function recommend(?array $hw = null): array
    {
        $hw = $hw ?? self::hardware();
        $ram = (float) $hw['ramGb'];
        if ($hw['gpu'] || $ram >= 30) {
            return ['chat' => 'qwen3.5:9b', 'vision' => 'gemma4:e4b', 'alternatives' => ['qwen3.5:27b'],
                    'why' => 'Plenty of memory: the 9B model answers WhatsApp while Gemma 4 reads ID photos, both stay loaded.'];
        }
        if ($ram >= 14) {
            return ['chat' => 'qwen3.5:9b', 'vision' => 'qwen3.5:9b', 'alternatives' => ['qwen3.5:4b', 'gemma4:e4b'],
                    'why' => 'With ~16 GB the 9B model fits beside the website and speaks clearly better Nepali and Hindi. It also reads ID photos.'];
        }
        if ($ram >= 7) {
            return ['chat' => 'qwen3.5:4b', 'vision' => 'qwen3.5:4b', 'alternatives' => ['qwen3.5:2b'],
                    'why' => 'An 8 GB VPS runs the 4B model comfortably next to MySQL; one model does WhatsApp and reads ID photos.'];
        }
        return ['chat' => 'qwen3.5:2b', 'vision' => 'qwen3.5:2b', 'alternatives' => [],
                'why' => 'Little memory: the 2B model fits, but expect simpler Nepali. More RAM (8 GB) is the best upgrade for the AI.'];
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
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
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
