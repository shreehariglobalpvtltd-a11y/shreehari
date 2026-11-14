<?php
/**
 * =====================================================================
 *  AiMeter — what the AI costs, call by call (13 Nov 2026, Super AI).
 *
 *  Until now the office could see WHAT the assistant did (ai_agent_calls)
 *  but not what it spent: no token count, no rupee figure, no ceiling.
 *  Every model call now writes one row to ai_usage — provider, model,
 *  tokens in / out, tokens served from the prompt cache, milliseconds,
 *  and the cost where the price is known.
 *
 *  Honest about prices: only models in PRICES get a cost. Anything else
 *  (Gemini free tier, a model added later) is stored with cost NULL and
 *  shown as "not priced" — never a guessed number.
 *
 *  The daily ceiling (Settings: ai_daily_budget_usd, 0 = no ceiling)
 *  counts priced calls only. Once today's spend reaches it, Claude is
 *  skipped and the next brain in the ladder (or the no-AI local reply)
 *  answers — a passenger is never left without a reply.
 *
 *  Never throws: a missing table or a bad row must not cost an answer.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiMeter
{
    /**
     * USD per 1,000,000 tokens: [input, output, cache read].
     * Cache writes are billed at 1.25 x input. Longest matching prefix wins.
     */
    public const PRICES = [
        'claude-fable-5-2'  => [8.00, 40.00, 0.20],
        'claude-opus-5-5'   => [4.00, 20.00, 0.20],
        'claude-opus-5'     => [5.00, 25.00, 0.50],
        'claude-opus-4'     => [5.00, 25.00, 0.50],
        'claude-sonnet-5-5' => [2.00, 10.00, 0.10],
        'claude-sonnet-5'   => [2.00, 10.00, 0.20],
        'claude-sonnet-4-6' => [3.00, 15.00, 0.30],
        'claude-haiku-5-5'  => [0.10, 0.50, 0.01],
        'claude-haiku-4-5'  => [1.00, 5.00, 0.10],
    ];

    /** Who is asking, set by the caller before a model call. */
    private static array $ctx = [];
    private static ?float $spentToday = null;

    /** @param array<string,mixed> $ctx channel, role, tag */
    public static function context(array $ctx): void
    {
        self::$ctx = [
            'channel' => mb_substr((string) ($ctx['channel'] ?? ''), 0, 20),
            'role'    => mb_substr((string) ($ctx['role'] ?? ''), 0, 12),
            'tag'     => mb_substr((string) ($ctx['tag'] ?? ''), 0, 20),
        ];
    }

    /** @return array{0:float,1:float,2:float}|null */
    public static function price(string $model): ?array
    {
        $m = strtolower(trim($model));
        $best = null;
        $len = 0;
        foreach (self::PRICES as $prefix => $p) {
            if (str_starts_with($m, $prefix) && strlen($prefix) > $len) {
                $best = $p;
                $len = strlen($prefix);
            }
        }
        return $best;
    }

    /**
     * Cost in USD of one call, or null when the model has no known price.
     *
     * @param array{in:int,out:int,cache_read:int,cache_write:int} $u
     */
    public static function cost(string $model, array $u): ?float
    {
        $p = self::price($model);
        if ($p === null) {
            return null;
        }
        return round((
            $u['in'] * $p[0]
            + $u['cache_write'] * $p[0] * 1.25
            + $u['cache_read'] * $p[2]
            + $u['out'] * $p[1]
        ) / 1_000_000
            // 14 Nov 2026: web research is billed per search, on top of the tokens.
            + max(0, (int) ($u['web_searches'] ?? 0)) * self::WEB_SEARCH_USD, 6);
    }

    /** Anthropic web search: USD 10 per 1,000 searches. */
    public const WEB_SEARCH_USD = 0.01;

    /** Anthropic `usage`: input_tokens excludes the cached part. */
    public static function fromAnthropic(mixed $usage): array
    {
        $u = is_array($usage) ? $usage : [];
        return [
            'in'          => max(0, (int) ($u['input_tokens'] ?? 0)),
            'out'         => max(0, (int) ($u['output_tokens'] ?? 0)),
            'cache_read'  => max(0, (int) ($u['cache_read_input_tokens'] ?? 0)),
            'cache_write' => max(0, (int) ($u['cache_creation_input_tokens'] ?? 0)),
        ];
    }

    /** Gemini `usageMetadata`: promptTokenCount includes the cached part. */
    public static function fromGemini(mixed $meta): array
    {
        $u = is_array($meta) ? $meta : [];
        $cached = max(0, (int) ($u['cachedContentTokenCount'] ?? 0));
        return [
            'in'          => max(0, (int) ($u['promptTokenCount'] ?? 0) - $cached),
            'out'         => max(0, (int) ($u['candidatesTokenCount'] ?? 0) + (int) ($u['thoughtsTokenCount'] ?? 0)),
            'cache_read'  => $cached,
            'cache_write' => 0,
        ];
    }

    /** OpenAI-style `usage`: prompt_tokens includes the cached part. */
    public static function fromOpenAI(mixed $usage): array
    {
        $u = is_array($usage) ? $usage : [];
        $cached = max(0, (int) ($u['prompt_tokens_details']['cached_tokens'] ?? 0));
        return [
            'in'          => max(0, (int) ($u['prompt_tokens'] ?? 0) - $cached),
            'out'         => max(0, (int) ($u['completion_tokens'] ?? 0)),
            'cache_read'  => $cached,
            'cache_write' => 0,
        ];
    }

    /**
     * One model call. $u from one of the from*() helpers ([] on a failure).
     */
    public static function record(string $provider, string $model, array $u, int $ms, bool $ok): void
    {
        try {
            $u += ['in' => 0, 'out' => 0, 'cache_read' => 0, 'cache_write' => 0];
            // A declined or failed call that still reported tokens was still billed.
            $cost = self::cost($model, $u);
            Database::insert('ai_usage', [
                'created_at'  => date('Y-m-d H:i:s'),
                'provider'    => mb_substr($provider, 0, 20),
                'model'       => mb_substr($model, 0, 60),
                'channel'     => (string) (self::$ctx['channel'] ?? ''),
                'role'        => (string) (self::$ctx['role'] ?? ''),
                'tag'         => (string) (self::$ctx['tag'] ?? ''),
                'in_tokens'   => (int) $u['in'],
                'out_tokens'  => (int) $u['out'],
                'cache_read'  => (int) $u['cache_read'],
                'cache_write' => (int) $u['cache_write'],
                'cost_usd'    => $cost,
                'ms'          => max(0, $ms),
                'ok'          => $ok ? 1 : 0,
            ]);
            if ($cost !== null && self::$spentToday !== null) {
                self::$spentToday += $cost;
            }
        } catch (Throwable $e) {
            // table not installed yet, or the database is busy: the answer matters more
        }
    }

    /** Priced spend today, in USD. */
    public static function spentToday(): float
    {
        if (self::$spentToday !== null) {
            return self::$spentToday;
        }
        try {
            self::$spentToday = (float) Database::scalar(
                'SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage WHERE created_at >= :d',
                ['d' => date('Y-m-d') . ' 00:00:00'], 0
            );
        } catch (Throwable $e) {
            self::$spentToday = 0.0;
        }
        return self::$spentToday;
    }

    /** The owner's ceiling for one day, in USD. 0 = no ceiling. */
    public static function budget(): float
    {
        return max(0.0, Settings::getFloat('ai_daily_budget_usd', 0.0));
    }

    /** True once today's priced spend has reached the ceiling. */
    public static function overBudget(): bool
    {
        $b = self::budget();
        return $b > 0 && self::spentToday() >= $b;
    }

    /** For tests: forget the cached figure and the caller. */
    public static function reset(): void
    {
        self::$spentToday = null;
        self::$ctx = [];
    }
}
