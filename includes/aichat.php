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
        return Settings::getString('anthropic_api_key', '') !== ''
            && Settings::getBool('wa_ai_enabled', true)
            && function_exists('curl_init');
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
            . "give the office number instead of a long explanation.";
    }

    /** One Anthropic Messages API call. Returns the text, or null. */
    private static function ask(string $system, array $messages): ?string
    {
        $key   = Settings::getString('anthropic_api_key', '');
        $model = Settings::getString('ai_model', 'claude-opus-5');

        $payload = json_encode([
            'model'      => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => $system,
            'messages'   => $messages,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $http !== 200) {
            Logger::error('Anthropic call failed (HTTP ' . $http . ')', [
                'err'  => $err,
                'body' => mb_substr((string) $body, 0, 300),
            ], 'whatsapp');
            return null;
        }

        $json = json_decode((string) $body, true);
        $out  = '';
        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $out .= (string) ($block['text'] ?? '');
            }
        }

        return trim($out) !== '' ? trim($out) : null;
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
