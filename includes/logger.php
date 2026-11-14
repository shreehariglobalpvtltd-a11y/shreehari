<?php
/**
 * =====================================================================
 *  Logger
 *
 *  Writes to the app_logs table when the database is reachable and
 *  always mirrors to a dated file in /logs so nothing is lost when the
 *  database itself is the thing that broke.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Logger
{
    /** Guard against a logging failure triggering more logging. */
    private static bool $inWrite = false;

    /** @param array<string, mixed> $context */
    public static function debug(string $message, array $context = [], string $channel = 'app'): void
    {
        if (APP_DEBUG) {
            self::write('debug', $message, $context, $channel);
        }
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write('info', $message, $context, $channel);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write('warning', $message, $context, $channel);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write('error', $message, $context, $channel);
    }

    /** @param array<string, mixed> $context */
    public static function critical(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write('critical', $message, $context, $channel);
    }

    /**
     * Log a caught exception with its origin.
     */
    public static function exception(Throwable $e, string $channel = 'app'): void
    {
        self::write('error', $e->getMessage(), [
            'class' => get_class($e),
            'trace' => APP_DEBUG ? $e->getTraceAsString() : null,
        ], $channel, $e->getFile(), $e->getLine());
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(
        string $level,
        string $message,
        array $context = [],
        string $channel = 'app',
        ?string $file = null,
        ?int $line = null
    ): void {
        if (self::$inWrite) {
            return;
        }
        self::$inWrite = true;

        // 15 Nov 2026: no password, token, OTP or PIN reaches a log in clear.
        try {
            $message = self::scrubText($message);
            $context = $context === [] ? [] : self::redact($context);
        } catch (Throwable) {
            $context = $context === [] ? [] : ['redaction' => 'failed'];   // never the raw values
        }

        $encodedContext = $context === []
            ? null
            : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        // File log first — this must work even with no database.
        self::toFile($level, $channel, $message, $encodedContext);

        // Then the database, best effort.
        try {
            if (class_exists('Database', false)) {
                Database::insert('app_logs', [
                    'level'      => $level,
                    'channel'    => substr($channel, 0, 40),
                    'message'    => substr($message, 0, 60000),
                    'context'    => $encodedContext,
                    'file'       => $file !== null ? substr($file, 0, 255) : null,
                    'line'       => $line,
                    'ip_address' => class_exists('Security', false) ? Security::clientIp() : null,
                ]);
            }
        } catch (Throwable) {
            // Already captured on disk; never let logging break a request.
        }

        self::$inWrite = false;
    }

    /* =================================================================
     *  Redaction (15 Nov 2026)
     *
     *  A context value whose KEY looks secret (password, token, OTP, PIN,
     *  API key, cookie, Authorization, any "code") is written as its first
     *  two characters + '***', at any depth. The shape stays the same —
     *  same keys, same nesting — so every log reader keeps working.
     *  Integers under an error-number key ('code' => 63016, 'http_code')
     *  stay readable: those are provider / HTTP status numbers, and every
     *  OTP, PIN and verify code in this codebase is a string.
     *  Free text (the message and every string value) also loses a
     *  6-character verify code written after "code / OTP / PIN /
     *  password", a "token=…" style query value and a Bearer token.
     * ================================================================= */

    /** Whole words (snake_case / camelCase / kebab split) that make a key secret. */
    private const SECRET_WORDS = [
        'pass', 'password', 'passwd', 'passcode', 'pwd', 'secret', 'token', 'otp', 'pin',
        'authorization', 'auth', 'cookie', 'code', 'apikey', 'signature', 'sig', 'credential', 'credentials',
    ];
    /** "key" is secret only after one of these words (api_key, secretKey, private-key…). */
    private const KEY_PREFIX = ['api', 'secret', 'private', 'access', 'app', 'auth', 'signing', 'client', 'master'];
    /** "code" right after one of these words is a plain label, not a secret. */
    private const PLAIN_CODE = ['country', 'dial', 'postal', 'zip', 'pnr', 'route', 'station', 'agent', 'staff', 'seat',
        'lang', 'language', 'iso', 'currency', 'error', 'status', 'http', 'response', 'reason', 'promo', 'coupon', 'bus', 'desk', 'counter'];

    /** True when a context key names a secret — word by word, so "passengers", "pincode", "keyword", "mapping" stay readable. */
    public static function isSecretKey(string $key): bool
    {
        $spaced = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key);
        $words  = preg_split('/[^a-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $i => $w) {
            $one  = (strlen($w) > 3 && str_ends_with($w, 's')) ? substr($w, 0, -1) : $w;   // tokens, cookies, codes
            $prev = $words[$i - 1] ?? '';
            if ($one === 'key') {
                if (in_array($prev, self::KEY_PREFIX, true)) {
                    return true;
                }
                continue;
            }
            if ($one === 'code' && in_array($prev, self::PLAIN_CODE, true)) {
                continue;
            }
            if (in_array($one, self::SECRET_WORDS, true) || in_array($w, self::SECRET_WORDS, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function redact(array $context, int $depth = 0): array
    {
        foreach ($context as $k => $v) {
            $secret = is_string($k) && self::isSecretKey($k);
            if (is_array($v)) {
                $context[$k] = $depth >= 8 ? '[deep]' : ($secret ? self::maskAll($v, $depth + 1) : self::redact($v, $depth + 1));
            } elseif ($secret && !self::isErrorNumber((string) $k, $v)) {
                $context[$k] = self::mask($v);
            } elseif (is_string($v)) {
                $context[$k] = self::scrubText($v);
            }
        }
        return $context;
    }

    /** First two characters + '***'. true / false / null stay as they are (nothing secret in them). */
    public static function mask(mixed $v): mixed
    {
        if ($v === null || is_bool($v) || $v === '') {
            return $v;
        }
        if (!is_scalar($v)) {
            return '***';
        }
        return mb_substr((string) $v, 0, 2) . '***';
    }

    /**
     * Mask every masked-scalar leaf of a secret-named list ('tokens' => [...]).
     *
     * @param array<mixed> $list
     * @return array<mixed>
     */
    private static function maskAll(array $list, int $depth): array
    {
        foreach ($list as $k => $v) {
            $list[$k] = is_array($v) ? ($depth >= 8 ? '[deep]' : self::maskAll($v, $depth + 1)) : self::mask($v);
        }
        return $list;
    }

    private static function isErrorNumber(string $key, mixed $v): bool
    {
        return is_int($v)
            && preg_match('/code$/i', $key) === 1
            && preg_match('/otp|pin|pass|pwd|secret|token|verif|key|auth/i', $key) !== 1;
    }

    /** Verify codes, token=… values and Bearer tokens out of free text. */
    public static function scrubText(string $text): string
    {
        if ($text === '' || preg_match('/code|otp|pin|pass|pwd|token|key|secret|sig|bearer/i', $text) !== 1) {
            return $text;   // the common case: nothing to look at
        }
        $keep = static fn(array $m): string => $m[1] . mb_substr($m[2], 0, 2) . '***';
        $text = (string) (preg_replace_callback(
            '/(?<![A-Za-z_])(?<!error )(?<!status )(?<!http )(?<!response )((?:otp|pin|passcode|password|code)(?:\s+(?:is|was))?\s*[:=#-]?\s*)((?=[A-Za-z]*\d)[A-Za-z0-9]{6})(?![A-Za-z0-9])/i',
            $keep,
            $text
        ) ?? $text);
        $text = (string) (preg_replace_callback(
            '/(?<![A-Za-z0-9])((?:access_token|api_?key|key|token|secret|password|pwd|otp|pin|sig|signature)=)([^&\s"\'<>]{3,})/i',
            $keep,
            $text
        ) ?? $text);
        return (string) (preg_replace_callback('/\b(Bearer\s+)([A-Za-z0-9._~+\/=-]{6,})/', $keep, $text) ?? $text);
    }

    private static function toFile(string $level, string $channel, string $message, ?string $context): void
    {
        try {
            if (!is_dir(LOG_PATH)) {
                @mkdir(LOG_PATH, 0755, true);
            }

            $line = sprintf(
                "[%s] %s.%s: %s%s%s",
                date('Y-m-d H:i:s'),
                $channel,
                strtoupper($level),
                str_replace(["\r", "\n"], ' ', $message),
                $context !== null ? ' ' . $context : '',
                PHP_EOL
            );

            $path = LOG_PATH . '/' . date('Y-m-d') . '.log';
            @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
            self::adopt($path);
        } catch (Throwable) {
            // Nothing further we can safely do.
        }
    }

    /**
     * The root crontab runs PHP as root. A log file root creates first
     * (just after midnight for a dated log) is root:root 0644, and the
     * website (www-data) cannot append to it for the rest of the day.
     * So when we are root, a root-owned file is handed to whoever owns its
     * folder. Cheap no-op for www-data. (5 Oct 2026: whatsapp/logs/
     * 2026-10-04.log and 2026-10-05.log were root:root.)
     */
    public static function adopt(string $file): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }
        clearstatcache(true, $file);
        $dir = dirname($file);
        $uid = @fileowner($dir);
        if ($uid === false || $uid === 0 || @fileowner($file) !== 0) {
            return;
        }
        @chown($file, $uid);
        $gid = @filegroup($dir);
        if ($gid !== false) {
            @chgrp($file, $gid);
        }
    }

    /**
     * Record an admin or customer action in the audit trail.
     *
     * @param array<string, mixed>|null $oldValue
     * @param array<string, mixed>|null $newValue
     */
    public static function audit(
        string $action,
        string $entityType = '',
        string $entityId = '',
        ?array $oldValue = null,
        ?array $newValue = null,
        string $detail = '',
        string $reason = ''
    ): void {
        try {
            $actorType = 'system';
            $actorId   = null;
            $actorName = null;
            $actorRole = null;

            if (!empty($_SESSION[ADMIN_SESSION_KEY]['id'])) {
                $actorType = 'admin';
                $actorId   = (int) $_SESSION[ADMIN_SESSION_KEY]['id'];
                $actorName = (string) ($_SESSION[ADMIN_SESSION_KEY]['username'] ?? '');
                $actorRole = (string) ($_SESSION[ADMIN_SESSION_KEY]['role'] ?? '') ?: null;
            } elseif (!empty($_SESSION[USER_SESSION_KEY]['id'])) {
                $actorType = 'user';
                $actorId   = (int) $_SESSION[USER_SESSION_KEY]['id'];
                $actorName = (string) ($_SESSION[USER_SESSION_KEY]['phone'] ?? '');
                $actorRole = 'customer';
            } elseif (PHP_SAPI === 'cli') {
                $actorType = 'cron';
            }

            $row = [
                'actor_type'  => $actorType,
                'actor_id'    => $actorId,
                'actor_name'  => $actorName,
                'action'      => substr($action, 0, 80),
                'entity_type' => $entityType !== '' ? substr($entityType, 0, 60) : null,
                'entity_id'   => $entityId !== '' ? substr($entityId, 0, 60) : null,
                'old_value'   => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
                'new_value'   => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
                'detail'      => $detail !== '' ? substr($detail, 0, 500) : null,
                'ip_address'  => Security::clientIp(),
            ];
            /* SHG AI BRAIN 1.6 (10 Sep 2026): the trail now also keeps WHO-AS-WHAT
               (actor_role) and WHY (reason — what the desk typed when it edited a
               ticket). Both columns arrive with upgrade-2026-09-challan-png.sql;
               until that migration has run on a database, the write falls back to
               the old column set so no audit row is ever lost to a schema gap. */
            try {
                Database::insert('audit_logs', $row + [
                    'actor_role' => $actorRole !== null ? substr($actorRole, 0, 30) : null,
                    'reason'     => $reason !== '' ? substr($reason, 0, 255) : null,
                ]);
            } catch (Throwable $e) {
                Database::insert('audit_logs', $row);
            }
        } catch (Throwable $e) {
            self::error('Audit write failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete log rows and files older than N days. Called by cron.
     */
    public static function rotate(int $keepDays = 60): int
    {
        $cutoff  = date('Y-m-d H:i:s', strtotime('-' . $keepDays . ' days'));
        $deleted = Database::delete('app_logs', 'created_at < :cutoff', ['cutoff' => $cutoff]);

        $files = glob(LOG_PATH . '/*.log') ?: [];
        $limit = time() - ($keepDays * 86400);
        foreach ($files as $file) {
            if (@filemtime($file) < $limit) {
                @unlink($file);
            }
        }

        return $deleted;
    }
}
