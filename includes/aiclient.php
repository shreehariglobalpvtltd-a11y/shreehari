<?php
/**
 * =====================================================================
 *  includes/aiclient.php — local model first, cloud second
 *  (26 Sep 2026, Prompt 2 section 2).
 *
 *  AiClient::chat() asks Ollama on this server when settings.ai_local_on is
 *  set (endpoint ai_local_endpoint, model ai_local_model, ai_local_timeout
 *  seconds), and falls back to the cloud brain the WhatsApp assistant
 *  already uses (AiChat::complete: Gemini or Claude, keys from settings).
 *  With ai_local_on = 0 (the default) nothing new is called.
 *
 *  Every call is written to ai_provider_usage (provider, model, tokens,
 *  latency, status). The prompt itself is never stored or logged, and the
 *  text sent out is passed through AiPrivacy::redact first (PNRs, phone
 *  numbers, UTRs, emails, keys).
 *
 *  AiClient::classify() sorts a customer message into PAY_PROOF | CANCEL |
 *  STATUS | TICKET_REQUEST | OTHER. Plain keyword rules answer first; the
 *  model is asked only when the rules are unsure. The label is a HINT for
 *  routing and for the office's badge. It never confirms, cancels or
 *  changes anything by itself.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

require_once INCLUDE_PATH . '/ai/Privacy.php';

final class AiClient
{
    public const LABELS = ['PAY_PROOF', 'CANCEL', 'STATUS', 'TICKET_REQUEST', 'OTHER'];

    /** Last call's provider ('local' | 'gemini' | 'anthropic' | 'none'), for callers and tests. */
    public static string $lastProvider = 'none';

    public static function localEnabled(): bool
    {
        return Settings::getBool('ai_local_on', false) && function_exists('curl_init');
    }

    /**
     * One completion. Returns the text, or null when no model answered.
     * Never throws.
     */
    public static function chat(string $prompt, int $maxTokens = 500, string $system = '', ?int $bookingId = null): ?string
    {
        $prompt = AiPrivacy::redact($prompt);
        self::$lastProvider = 'none';

        if (self::localEnabled()) {
            $t0   = microtime(true);
            $res  = self::askOllama($prompt, $system, $maxTokens);
            $ms   = (int) round((microtime(true) - $t0) * 1000);
            self::logUsage('local', Settings::getString('ai_local_model', 'llama3.1'), 'chat',
                $res['text'] !== null ? 'ok' : 'error', $res['in'], $res['out'], $ms);
            if ($res['text'] !== null) {
                self::$lastProvider = 'local';
                return $res['text'];
            }
        }

        try {
            require_once INCLUDE_PATH . '/aichat.php';
            $t0  = microtime(true);
            $res = AiChat::complete($system, $prompt);
            $ms  = (int) round((microtime(true) - $t0) * 1000);
            if ($res['provider'] !== 'none') {
                self::logUsage($res['provider'], '', 'chat', $res['text'] !== null ? 'ok' : 'error', 0, 0, $ms);
                if ($res['text'] !== null) {
                    self::$lastProvider = $res['provider'];
                    if (self::localEnabled()) {
                        require_once INCLUDE_PATH . '/notifier.php';
                        Notifier::notifyAdmin('ai_cloud_fallback', null,
                            '🤖 Local AI did not answer, the cloud model was used',
                            ['Model' => Settings::getString('ai_local_model', ''), 'Check' => 'Is Ollama running on the server?']);
                    }
                    return $res['text'];
                }
            }
        } catch (Throwable $e) {
            Logger::exception($e);
        }
        return null;
    }

    /**
     * Sort one customer message. Rules first, model only when unsure.
     */
    public static function classify(string $message): string
    {
        $rule = self::classifyByRules($message);
        if ($rule !== null) {
            return $rule;
        }
        $answer = self::chat(
            "Message from a bus-ticket customer:\n\"\"\"\n" . mb_substr($message, 0, 500) . "\n\"\"\"\n"
            . 'Reply with exactly one word from: ' . implode(', ', self::LABELS) . '.',
            5,
            'You label customer messages for a bus company. Output one label and nothing else.'
        );
        $label = strtoupper(trim((string) preg_replace('/[^A-Z_]/i', '', (string) $answer)));
        return in_array($label, self::LABELS, true) ? $label : 'OTHER';
    }

    /** Keyword rules in English, romanised Nepali/Hindi and Devanagari. Null = unsure. */
    public static function classifyByRules(string $message): ?string
    {
        $m = mb_strtolower(trim($message));
        if ($m === '') {
            return 'OTHER';
        }
        $has = static function (array $words) use ($m): bool {
            foreach ($words as $w) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '/u', $m)) {
                    return true;
                }
            }
            return false;
        };
        if ($has(['cancel', 'radd', 'रद्द', 'रद', 'refund', 'फिर्ता'])) {
            return 'CANCEL';
        }
        if ($has(['utr', 'paid', 'payment done', 'transaction', 'txn', 'tireko', 'tiryo', 'pathaye', 'bhuktani', 'भुक्तानी', 'भुगतान', 'screenshot', 'paisa pathayo'])) {
            return 'PAY_PROOF';
        }
        if (preg_match('/^\s*(ticket|tiket|टिकट)\s*[:#-]?\s*[a-z0-9]{6}\s*$/iu', $m)
            || $has(['send ticket', 'ticket pathau', 'ticket pathaunu', 'टिकट पठाउनु', 'ticket bhej', 'e-ticket'])) {
            return 'TICKET_REQUEST';
        }
        if ($has(['status', 'confirm bhayo', 'confirmed', 'kaha pugyo', 'कहाँ', 'pakka', 'पक्का'])) {
            return 'STATUS';
        }
        return null;
    }

    /** @return array{text: ?string, in: int, out: int} */
    private static function askOllama(string $prompt, string $system, int $maxTokens): array
    {
        $endpoint = rtrim(Settings::getString('ai_local_endpoint', 'http://127.0.0.1:11434'), '/');
        // Local means local: refuse anything that is not this machine.
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)) {
            Logger::warning('ai_local_endpoint is not a local address, local AI skipped', ['host' => $host]);
            return ['text' => null, 'in' => 0, 'out' => 0];
        }
        $payload = [
            'model'   => Settings::getString('ai_local_model', 'llama3.1'),
            'prompt'  => $prompt,
            'stream'  => false,
            'options' => ['num_predict' => max(1, min(2000, $maxTokens))],
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }
        $timeout = max(1, min(60, Settings::getInt('ai_local_timeout', 8)));

        $ch = curl_init($endpoint . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_PROXY          => '',          // never route a local call through a proxy
        ]);
        $body = (string) curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http !== 200) {
            return ['text' => null, 'in' => 0, 'out' => 0];
        }
        $j    = json_decode($body, true);
        $text = is_array($j) ? trim((string) ($j['response'] ?? '')) : '';
        return [
            'text' => $text !== '' ? $text : null,
            'in'   => (int) ($j['prompt_eval_count'] ?? 0),
            'out'  => (int) ($j['eval_count'] ?? 0),
        ];
    }

    /** One row in ai_provider_usage. No prompt, no reply, no customer data. */
    private static function logUsage(string $provider, string $model, string $op, string $status, int $in, int $out, int $ms): void
    {
        try {
            Database::insert('ai_provider_usage', [
                'provider'      => mb_substr($provider, 0, 30),
                'model'         => mb_substr($model, 0, 120),
                'operation'     => mb_substr($op, 0, 30),
                'status'        => mb_substr($status, 0, 20),
                'input_tokens'  => max(0, $in),
                'output_tokens' => max(0, $out),
                'elapsed_ms'    => max(0, $ms),
            ]);
        } catch (Throwable $e) {
            // The usage table comes with upgrade-2026-09-ai-manager.sql; without it, skip.
        }
    }
}
