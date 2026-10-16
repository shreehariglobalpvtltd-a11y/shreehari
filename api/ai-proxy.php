<?php
/**
 * =====================================================================
 *  POST /api/ai-proxy.php — SHG Sahayak's Claude fallback (v4.0 PATCH A1)
 *
 *  The browser cannot call api.anthropic.com directly (CORS, and the key
 *  must never reach a client), so the chatbot posts its conversation here
 *  and this endpoint relays it. Three server-side guarantees:
 *
 *    · the API key lives in the settings table (is_public = 0) and is
 *      never echoed anywhere;
 *    · the SYSTEM prompt is fixed here — a client cannot rewrite the
 *      assistant's rules by sending its own system field;
 *    · when no key is configured the reply is a quiet {ok:false} 503,
 *      and the chatbot silently stays rule-based — never an error toast.
 *
 *  Raw curl on purpose: this codebase is composer-less shared hosting and
 *  already speaks to Twilio the same way (includes/notify.php). Wire shape
 *  per the Anthropic Messages API: POST /v1/messages with x-api-key +
 *  anthropic-version headers; response content is an array of typed
 *  blocks — only 'text' blocks are read, anything else is skipped.
 *
 *  Body: { messages: [{role:'user'|'assistant', content:string}, ...] }
 *  Reply: { ok:true, data:{ text: "..." } }
 * =====================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

require_once INCLUDE_PATH . '/aiprompt.php';   // ai_system_prompt(), shared with the WhatsApp bot

try {
    Security::requirePost();
    Security::requireCsrf();

    // Per-IP: chat replies are cheap but not free — 8 AI calls / 5 min and
    // 60 / day, so one address cannot drain the Anthropic budget (4 Sep 2026).
    Security::requireRateLimit('ai_chat', Security::clientIp(), 8, 300);
    Security::requireRateLimit('ai_chat_day', Security::clientIp(), 60, 86400);

    $apiKey = Settings::getString('anthropic_api_key', '');
    if ($apiKey === '') {
        // Not configured — the bot degrades to rules + WhatsApp handoff.
        Response::error('AI assistant is not configured.', 503);
    }

    // Same 503 when the host has no cURL. Without this the undefined
    // function is a Throwable, which Response::serverError turns into a
    // 500 — a real error in the log for a feature that is meant to be
    // silently optional. (Caught in testing: the dev php.ini omits cURL.)
    if (!function_exists('curl_init')) {
        Logger::warning('AI proxy unavailable: cURL extension is not loaded');
        Response::error('AI assistant is unavailable on this server.', 503);
    }

    // The operator named this model in the master prompt (cost: this is a
    // bus company's FAQ bot, not a coding agent). Changeable in Admin →
    // Settings without a deploy.
    // claude-sonnet-5: half the price of the sonnet-4-6 default this shipped with.
    $model = Settings::getString('ai_model', 'claude-sonnet-5');

    /* ---- Validate the conversation from the browser ---------------- */
    $raw = Response::field('messages', []);
    if (!is_array($raw) || $raw === []) {
        Response::invalid(['messages' => 'Nothing to answer.']);
    }

    $messages = [];
    foreach (array_slice($raw, -20) as $m) {          // last 10 turns max
        $role    = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = trim((string) ($m['content'] ?? ''));
        if ($content === '') {
            continue;
        }
        // Alternation: the API rejects consecutive same-role messages.
        if ($messages !== [] && $messages[count($messages) - 1]['role'] === $role) {
            $messages[count($messages) - 1]['content'] .= "\n" . mb_substr($content, 0, 2000);
            continue;
        }
        $messages[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
    }
    if ($messages === [] || $messages[0]['role'] !== 'user') {
        array_unshift($messages, ['role' => 'user', 'content' => 'Namaste']);
    }
    if ($messages[count($messages) - 1]['role'] !== 'user') {
        Response::invalid(['messages' => 'The last message must be from the user.']);
    }

    /* ---- System prompt: server-owned, never client-supplied, built from
       live settings and tables on every call (ai_system_prompt above). */
    $system = ai_system_prompt();

    /* ---- Relay to the Anthropic Messages API ----------------------- */
    $req = [
        'model'      => $model,
        'max_tokens' => 1024,   // thinking + text share this cap on Sonnet 5
        'system'     => $system,
        'messages'   => $messages,
    ];
    if (!str_contains(strtolower($model), 'haiku') && !str_contains(strtolower($model), 'sonnet-4-5')) {
        $req['thinking']      = ['type' => 'adaptive'];
        $req['output_config'] = ['effort' => 'low'];
    }
    $payload = json_encode($req, JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        Logger::warning('AI proxy upstream failure', ['http' => $code, 'err' => $err]);
        Response::error('The assistant is busy — please try again.', 502);
    }

    $data = json_decode((string) $body, true);

    // A refusal or empty content is a graceful "can't help with that".
    $text = '';
    foreach ((array) ($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= (string) $block['text'];
        }
    }
    if ($text === '') {
        Response::error('The assistant could not answer that.', 502);
    }

    Response::success(['text' => $text]);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
