<?php
/**
 * =====================================================================
 *  AiGovernor — the only hand the learning jobs are allowed to write with.
 *
 *  Everything in this system that "learns" (the night brain's weights, the
 *  parser's aliases, the notifier's channel preferences, the bot's copy)
 *  wants to change a number somewhere. The danger is not that it changes
 *  the wrong number by malice; it is that six months from now somebody adds
 *  a tuning job, points it at Settings::set(), and a fare drifts.
 *
 *  So no learning job may call Settings::set() or write kv_store directly.
 *  They call AiGovernor::set(), and this class answers three questions
 *  before anything moves:
 *
 *     1. Is this key on the allow-list?      (not on it  -> refused)
 *     2. Is the value inside its bounds?     (outside    -> clamped or refused)
 *     3. Are we in shadow mode?              (yes        -> recorded, not applied)
 *
 *  "AI never touches booking, seat, fare or refund code" therefore stops
 *  being a promise in a document and becomes a property of the code: there
 *  is no path from a learning job to a money key, because the allow-list
 *  does not contain one and DENY_PATTERNS refuses anything shaped like one
 *  even if a future edit adds it to the list by mistake.
 *
 *  EVERY WRITE IS REVERSIBLE
 *  Each applied change pushes the previous value onto a bounded history in
 *  kv_store, so rollback() restores the last known-good value in one call,
 *  and every attempt — applied, shadowed or refused — lands in audit_logs.
 *
 *  DROP-DEAD SAFE
 *  Delete every brain table and turn this off and the desk sells exactly as
 *  it does today: get() falls back to the value Settings already holds,
 *  which is the frozen constant the code shipped with.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiGovernor
{
    /**
     * The complete set of keys a learning job may move, with the bounds
     * outside which a value is not "learned" but broken.
     *
     * Bounds are deliberately tight around today's shipped defaults. A job
     * that wants to move a number by an order of magnitude is a job with a
     * bug, and the whole point of this file is that such a bug costs an
     * audit row instead of a week of wrong tickets.
     *
     * @var array<string, array{type:string, min?:float, max?:float, max_len?:int, note:string}>
     */
    public const TUNABLES = [
        /* ---- night brain: how far it looks and how sure it must be ---- */
        'brain_min_score'      => ['type' => 'int',   'min' => 50,  'max' => 900,  'note' => 'confidence floor for a prediction to surface'],
        'brain_horizon_days'   => ['type' => 'int',   'min' => 1,   'max' => 30,   'note' => 'how many days ahead the brain predicts'],
        'brain_history_days'   => ['type' => 'int',   'min' => 30,  'max' => 1095, 'note' => 'learning window'],
        'brain_queue_size'     => ['type' => 'int',   'min' => 1,   'max' => 100,  'note' => 'ready-queue length'],
        'brain_carry_days'     => ['type' => 'int',   'min' => 0,   'max' => 14,   'note' => 'how long a draft stays warm'],
        'brain_cancel_flag'    => ['type' => 'int',   'min' => 1,   'max' => 20,   'note' => 'cancellations before a number is flagged'],
        'brain_fill_alert_pct' => ['type' => 'int',   'min' => 30,  'max' => 100,  'note' => 'seat-fill percentage that raises an alert'],

        /* ---- bot: cache and learning window only ---- */
        'ai_bot_cache_min'     => ['type' => 'int',   'min' => 1,   'max' => 1440, 'note' => 'pattern cache lifetime'],
        'ai_bot_window_days'   => ['type' => 'int',   'min' => 7,   'max' => 730,  'note' => 'how far back the bot learns habits'],

        /* ---- learned models and routing hints (kv_store, JSON) ---- */
        'brain.model'          => ['type' => 'json',  'max_len' => 20000, 'note' => 'fitted prediction weights, versioned'],
        'notify.route'         => ['type' => 'json',  'max_len' => 20000, 'note' => 'best delivery channel per country/number class'],
        'bot.aliases'          => ['type' => 'json',  'max_len' => 40000, 'note' => 'auto-adopted town/date aliases for the parser'],
        'bot.feedback'         => ['type' => 'json',  'max_len' => 60000, 'note' => 'per-context parse accuracy buckets'],
        'bot.copy'             => ['type' => 'json',  'max_len' => 20000, 'note' => 'message copy variants under test'],
    ];

    /**
     * A second, independent lock. Even if somebody adds a money key to
     * TUNABLES above, a key matching one of these is refused.
     *
     * Two locks rather than one because the allow-list is the thing a future
     * edit is most likely to get wrong, and the cost of getting it wrong is
     * a wrong fare on a real ticket.
     */
    private const DENY_PATTERNS = [
        '/fare/i', '/refund/i', '/price/i', '/pricing/i', '/discount/i',
        '/commission/i', '/salary/i', '/payout/i', '/wallet/i',
        '/seat/i', '/cabin/i', '/gender/i', '/cutoff/i', '/cut_off/i',
        '/capacity/i', '/max_seats/i', '/slab/i', '/tax/i', '/currency/i',
    ];

    /** How many previous values we keep per key for one-tap rollback. */
    private const HISTORY = 5;

    /* =================================================================
     *  Mode
     * ================================================================= */

    /**
     * In shadow mode a learning job's writes are recorded and compared but
     * never applied. This is the default for a NEW tunable's first nights:
     * you get the counterfactual ("the fit wanted 310, we ran 250") without
     * betting a live desk on an unproven model.
     */
    public static function shadow(): bool
    {
        return Settings::getBool('ai_governor_shadow', true);
    }

    /** Master switch. Off = the Governor refuses every write; nothing learns. */
    public static function enabled(): bool
    {
        return Settings::getBool('ai_governor_on', true);
    }

    /* =================================================================
     *  The gate
     * ================================================================= */

    /** Is this key one a learning job may move at all? */
    public static function isTunable(string $key): bool
    {
        if (!isset(self::TUNABLES[$key])) {
            return false;
        }
        foreach (self::DENY_PATTERNS as $re) {
            if (preg_match($re, $key) === 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * Propose a value for a tunable.
     *
     * @param string $job    which learning job is asking ('brainfit', 'parser', …)
     * @param string $reason one line, for the audit trail and the owner screen
     * @return array{ok:bool, applied:bool, value:mixed, error:string, clamped:bool}
     */
    public static function set(string $key, mixed $value, string $job, string $reason = ''): array
    {
        $fail = static fn(string $why): array
            => ['ok' => false, 'applied' => false, 'value' => null, 'error' => $why, 'clamped' => false];

        if (!self::enabled()) {
            return $fail('governor is switched off');
        }
        if (!self::isTunable($key)) {
            // Journal the refusal: a job repeatedly asking for a key it may
            // not have is exactly the thing the owner should be able to see.
            self::journal($key, null, $value, $job, $reason, 'refused');
            return $fail('key is not tunable');
        }

        $spec = self::TUNABLES[$key];
        [$clean, $clamped, $err] = self::coerce($spec, $value);
        if ($err !== '') {
            self::journal($key, null, $value, $job, $reason, 'refused');
            return $fail($err);
        }

        $current = self::get($key);

        if (self::shadow()) {
            self::journal($key, $current, $clean, $job, $reason, 'shadow');
            return ['ok' => true, 'applied' => false, 'value' => $clean, 'error' => '', 'clamped' => $clamped];
        }

        try {
            self::pushHistory($key, $current);
            self::write($key, $spec, $clean);
        } catch (Throwable $e) {
            return $fail('write failed: ' . $e->getMessage());
        }

        self::journal($key, $current, $clean, $job, $reason, 'applied');

        return ['ok' => true, 'applied' => true, 'value' => $clean, 'error' => '', 'clamped' => $clamped];
    }

    /**
     * Read a tunable's live value, falling back to what the code shipped with.
     *
     * A learning job that has never run, a dropped kv_store row and a
     * switched-off Governor all land on the same answer: the frozen default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!isset(self::TUNABLES[$key])) {
            return $default;
        }
        $spec = self::TUNABLES[$key];

        try {
            if ($spec['type'] === 'json') {
                $raw = Database::scalar(
                    "SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = :k LIMIT 1",
                    ['k' => self::kvKey($key)],
                    null
                );
                if (!is_string($raw) || $raw === '') {
                    return $default;
                }
                $decoded = json_decode($raw, true);
                return is_array($decoded) ? $decoded : $default;
            }
            return match ($spec['type']) {
                'int'   => Settings::getInt($key, is_int($default) ? $default : 0),
                'float' => Settings::getFloat($key, is_float($default) ? $default : 0.0),
                'bool'  => Settings::getBool($key, (bool) $default),
                default => Settings::getString($key, is_string($default) ? $default : ''),
            };
        } catch (Throwable $e) {
            return $default;
        }
    }

    /**
     * Undo the last applied change to one key.
     *
     * @return array{ok:bool, value:mixed, error:string}
     */
    public static function rollback(string $key, string $by = 'admin'): array
    {
        if (!self::isTunable($key)) {
            return ['ok' => false, 'value' => null, 'error' => 'key is not tunable'];
        }
        $hist = self::history($key);
        if ($hist === []) {
            return ['ok' => false, 'value' => null, 'error' => 'no previous value recorded'];
        }

        $previous = array_pop($hist);
        $spec     = self::TUNABLES[$key];
        $current  = self::get($key);

        try {
            self::write($key, $spec, $previous['value']);
            self::kvSet('governor.history.' . $key, $hist);
        } catch (Throwable $e) {
            return ['ok' => false, 'value' => null, 'error' => $e->getMessage()];
        }

        self::journal($key, $current, $previous['value'], $by, 'manual rollback', 'rollback');

        return ['ok' => true, 'value' => $previous['value'], 'error' => ''];
    }

    /**
     * The bounded stack of previous values for one key, oldest first.
     *
     * @return list<array{value:mixed, at:string}>
     */
    public static function history(string $key): array
    {
        $raw = self::kvGet('governor.history.' . $key);
        return is_array($raw) ? array_values($raw) : [];
    }

    /**
     * Everything the owner's health screen needs: each tunable, its live
     * value, its bounds and whether it has ever been moved.
     *
     * @return list<array<string,mixed>>
     */
    public static function report(): array
    {
        $out = [];
        foreach (self::TUNABLES as $key => $spec) {
            $out[] = [
                'key'       => $key,
                'type'      => $spec['type'],
                'note'      => $spec['note'],
                'min'       => $spec['min'] ?? null,
                'max'       => $spec['max'] ?? null,
                'value'     => $spec['type'] === 'json' ? '(json)' : self::get($key),
                'versions'  => count(self::history($key)),
                'tunable'   => true,
            ];
        }
        return $out;
    }

    /* =================================================================
     *  Internals
     * ================================================================= */

    /**
     * Force a proposed value into the shape and range its spec allows.
     *
     * @return array{0:mixed, 1:bool, 2:string} [value, wasClamped, error]
     */
    private static function coerce(array $spec, mixed $value): array
    {
        switch ($spec['type']) {
            case 'int':
            case 'float':
                if (!is_numeric($value)) {
                    return [null, false, 'value is not a number'];
                }
                $n   = $spec['type'] === 'int' ? (int) round((float) $value) : (float) $value;
                $min = $spec['min'] ?? null;
                $max = $spec['max'] ?? null;
                $clamped = false;
                if ($min !== null && $n < $min) {
                    $n = $spec['type'] === 'int' ? (int) $min : (float) $min;
                    $clamped = true;
                }
                if ($max !== null && $n > $max) {
                    $n = $spec['type'] === 'int' ? (int) $max : (float) $max;
                    $clamped = true;
                }
                return [$n, $clamped, ''];

            case 'bool':
                return [(bool) $value, false, ''];

            case 'json':
                if (!is_array($value)) {
                    return [null, false, 'value is not an array'];
                }
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded === false) {
                    return [null, false, 'value is not JSON-encodable'];
                }
                if (strlen($encoded) > (int) ($spec['max_len'] ?? 20000)) {
                    return [null, false, 'value is too large'];
                }
                return [$value, false, ''];

            default:
                $s = is_scalar($value) ? (string) $value : '';
                if ($s === '') {
                    return [null, false, 'value is not a string'];
                }
                $len = (int) ($spec['max_len'] ?? 500);
                return [mb_substr($s, 0, $len), mb_strlen($s) > $len, ''];
        }
    }

    /** The one place a tunable is actually stored. */
    private static function write(string $key, array $spec, mixed $value): void
    {
        if ($spec['type'] === 'json') {
            self::kvSet(self::kvKey($key), $value, false);
            return;
        }
        // settings.stype is an ENUM('string','int','float','bool','json') —
        // a value outside it is refused by MariaDB in strict mode, so the
        // spec type maps straight through rather than to a friendlier word.
        $type = in_array($spec['type'], ['int', 'float', 'bool', 'json'], true) ? $spec['type'] : 'string';
        Settings::set($key, $value, $type, 'ai', false);
    }

    /** Push the outgoing value onto the bounded history stack. */
    private static function pushHistory(string $key, mixed $current): void
    {
        $hist   = self::history($key);
        $hist[] = ['value' => $current, 'at' => date('c')];
        if (count($hist) > self::HISTORY) {
            $hist = array_slice($hist, -self::HISTORY);
        }
        self::kvSet('governor.history.' . $key, $hist);
    }

    /** kv_store key for a JSON tunable ('brain.model' -> 'brain.model.current'). */
    private static function kvKey(string $key): string
    {
        return $key . '.current';
    }

    /** @return array<mixed>|null */
    private static function kvGet(string $key): ?array
    {
        try {
            $raw = Database::scalar(
                "SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = :k LIMIT 1",
                ['k' => $key],
                null
            );
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function kvSet(string $key, mixed $value, bool $quiet = true): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            Database::query(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('global', :k, :v, 'governor')
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = VALUES(updated_by)",
                ['k' => $key, 'v' => $json === false ? '{}' : $json]
            );
        } catch (Throwable $e) {
            if (!$quiet) {
                throw $e;
            }
        }
    }

    /**
     * Every attempt — applied, shadowed, refused or rolled back — is an
     * audit row. A learning system you cannot audit is a learning system you
     * cannot switch off with confidence.
     */
    private static function journal(
        string $key,
        mixed $old,
        mixed $new,
        string $job,
        string $reason,
        string $outcome
    ): void {
        try {
            Database::insert('audit_logs', [
                'actor_type'  => 'system',
                'actor_name'  => 'ai:' . substr($job, 0, 100),
                'action'      => 'ai.tune.' . $outcome,
                'entity_type' => 'tunable',
                'entity_id'   => substr($key, 0, 60),
                'old_value'   => substr((string) json_encode($old, JSON_UNESCAPED_UNICODE), 0, 2000),
                'new_value'   => substr((string) json_encode($new, JSON_UNESCAPED_UNICODE), 0, 2000),
                'detail'      => substr($reason, 0, 500),
            ]);
        } catch (Throwable $e) {
            try {
                Logger::warning('governor journal failed', ['key' => $key, 'error' => $e->getMessage()], 'ai');
            } catch (Throwable $ignored) {
            }
        }
    }
}
