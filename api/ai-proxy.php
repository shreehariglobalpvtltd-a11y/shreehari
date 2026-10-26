<?php
/**
 * =====================================================================
 *  POST /api/ai-proxy.php — SHG Sahayak's AI fallback (v4.0 PATCH A1)
 *
 *  5 Oct 2026: answers through the system's ONE AI (includes/aibrain.php)
 *  — Gemini / GPT / Claude, whichever key the owner put in. It used to
 *  know only Claude, so with only a Gemini key on live it never answered.
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
require_once INCLUDE_PATH . '/aibrain.php';    // the one AI (5 Oct 2026)

try {
    Security::requirePost();
    Security::requireCsrf();

    // Per-IP: chat replies are cheap but not free — 8 AI calls / 5 min and
    // 60 / day, so one address cannot drain the Anthropic budget (4 Sep 2026).
    Security::requireRateLimit('ai_chat', Security::clientIp(), 8, 300);
    Security::requireRateLimit('ai_chat_day', Security::clientIp(), 60, 86400);

    if (!AiBrain::configured()) {
        // No AI key at all (or no cURL) — the bot degrades to rules + WhatsApp handoff.
        Response::error('AI assistant is not configured.', 503);
    }

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

    /* ---- One AI: the provider chosen in Settings, the others as backup ---- */
    $ans = AiBrain::text($system, $messages, 900, 'web_chat');
    if ($ans === null) {
        Logger::warning('AI proxy: no provider answered', ['order' => implode(',', AiBrain::order())]);
        Response::error('The assistant is busy — please try again.', 502);
    }
    $text = $ans['text'];

    // 6 Oct 2026: an unanswered website question becomes tonight's learning (AiLearn).
    try {
        require_once INCLUDE_PATH . '/ailearn.php';
        AiLearn::observe((string) $messages[count($messages) - 1]['content']);
    } catch (Throwable $e) {
        // learning never costs the answer
    }

    Response::success(['text' => $text]);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
