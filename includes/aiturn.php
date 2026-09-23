<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit; }

/** Bounded tool orchestration. A provider failure must not hide a completed sale. */
final class AiTurn
{
    /**
     * Tools that change something (a ticket, money, a message). When the model
     * fails mid-turn after one of these was attempted, the turn must still
     * report what happened. After READ-only tools there is nothing to protect,
     * so the turn returns null and the caller answers with its own proven,
     * human reply instead of a raw list of tool notes (23 Sep 2026: a Gemini
     * quota error turned "naam galat cha" into "Results so far: ✓ This number
     * has no booking with us yet.").
     */
    private const WRITE_TOOLS = ['issue_ticket', 'staff_sell', 'cancel_ticket', 'rename_passenger',
        'fix_ticket', 'office_confirm', 'resend_ticket', 'marketing_send'];

    public static function run(callable $ask, callable $execute, string $system, array $history,
        array $tools, int $limit, float $deadline): ?array
    {
        $limit = max(1, min(10, $limit));
        $used = 0; $media = null; $outcomes = []; $seen = []; $wrote = false;
        // The final round has no tools; the limit counts actual actions, not model rounds.
        for ($round = 0; $round <= $limit; $round++) {
            if (microtime(true) >= $deadline) { break; }
            $available = $used < $limit && $round < $limit ? $tools : [];
            try { $reply = $ask($system, $history, $available); }
            catch (Throwable $e) { break; }
            if ($reply === null) { break; }
            $calls = (array) ($reply['calls'] ?? []);
            if ($calls === []) {
                $text = trim((string) ($reply['text'] ?? ''));
                return $text !== '' ? ['text' => $text, 'media' => $media] : self::fallback($outcomes, $media, $wrote);
            }
            $history[] = ['role' => 'assistant', 'content' => $reply['blocks'] ?? []];
            $results = [];
            foreach ($calls as $call) {
                $name = (string) ($call['name'] ?? '');
                $args = (array) ($call['input'] ?? []);
                if (in_array($name, self::WRITE_TOOLS, true)) { $wrote = true; }
                $fingerprint = hash('sha256', $name . json_encode(self::canonical($args), JSON_UNESCAPED_UNICODE));
                if (isset($seen[$fingerprint])) {
                    // Provider fallback or repeated function calls cannot repeat a write.
                    $out = $seen[$fingerprint];
                } elseif ($available === [] || $used >= $limit || microtime(true) >= $deadline) {
                    $out = ['ok' => false, 'say' => 'यो काम अझै गरिएको छैन। बाँकी कामका लागि फेरि सन्देश पठाउनुहोस्।', 'data' => [], 'media' => null];
                    $outcomes[] = $out;
                } else {
                    $used++;
                    try { $out = $execute($name, $args); }
                    catch (Throwable $e) {
                        $out = ['ok' => false, 'say' => 'यो कामको नतिजा पुष्टि भएन। दोहोर्‍याउनु अघि कार्यालयबाट जाँच गर्नुहोस्।', 'data' => [], 'media' => null];
                    }
                    $seen[$fingerprint] = $out;
                    $outcomes[] = $out;
                    if (!empty($out['media'])) { $media = (string) $out['media']; }
                }
                $results[] = [
                    'type' => 'tool_result', 'tool_use_id' => (string) ($call['id'] ?? ''),
                    'toolName' => $name, 'is_error' => empty($out['ok']),
                    'content' => json_encode(['ok' => $out['ok'], 'note' => $out['say'], 'data' => $out['data']], JSON_UNESCAPED_UNICODE),
                ];
            }
            $history[] = ['role' => 'user', 'content' => $results];
        }
        return self::fallback($outcomes, $media, $wrote);
    }

    private static function canonical(array $args): array
    {
        if (!array_is_list($args)) { ksort($args); }
        foreach ($args as &$value) { if (is_array($value)) { $value = self::canonical($value); } }
        return $args;
    }

    private static function fallback(array $outcomes, ?string $media, bool $wrote): ?array
    {
        // Nothing was attempted, or only reads: let the caller's own reply stand.
        if ($outcomes === [] || !$wrote) { return null; }
        $lines = ['अहिलेसम्मको नतिजा / Results so far:'];
        foreach ($outcomes as $out) {
            $data = (array) ($out['data'] ?? []);
            $facts = [];
            // Keep useful identifiers without exposing arbitrary internal tool data.
            foreach (['pnr', 'status', 'date', 'travel_date', 'total', 'fare', 'new_date', 'new_phone', 'campaign_id'] as $key) {
                if (isset($data[$key]) && is_scalar($data[$key])) { $facts[] = $key . ': ' . $data[$key]; }
            }
            $lines[] = (!empty($out['ok']) ? '✓ ' : '⚠ ') . (string) ($out['say'] ?? '')
                . ($facts !== [] ? ' (' . implode(', ', $facts) . ')' : '');
        }
        $lines[] = 'पूरा भएको काम फेरि नगर्नुहोस्। बाँकी कुरा बुझ्न अर्को सन्देश पठाउनुहोस्।';
        return ['text' => implode("\n", array_unique($lines)), 'media' => $media];
    }
}
