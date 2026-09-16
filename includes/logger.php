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

            @file_put_contents(
                LOG_PATH . '/' . date('Y-m-d') . '.log',
                $line,
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable) {
            // Nothing further we can safely do.
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
