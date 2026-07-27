<?php
/**
 * =====================================================================
 *  Settings — typed access to the `settings` table.
 *
 *  The whole table is small, so it is loaded once per request and cached
 *  in memory. Admin edits go through set() which keeps the cache honest.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Settings
{
    /** @var array<string, array{value: string|null, type: string, public: bool}>|null */
    private static ?array $cache = null;

    /**
     * Load every setting once per request.
     */
    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        try {
            $rows = Database::fetchAll('SELECT skey, svalue, stype, is_public FROM settings');
            foreach ($rows as $row) {
                self::$cache[(string) $row['skey']] = [
                    'value'  => $row['svalue'] !== null ? (string) $row['svalue'] : null,
                    'type'   => (string) $row['stype'],
                    'public' => (bool) $row['is_public'],
                ];
            }
        } catch (Throwable $e) {
            // A missing settings table should not take the whole site down —
            // callers fall back to their supplied defaults.
            Logger::error('Settings load failed: ' . $e->getMessage());
        }
    }

    /**
     * Raw string value, or $default when the key is absent.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        if (!isset(self::$cache[$key])) {
            return $default;
        }

        $entry = self::$cache[$key];
        $raw   = $entry['value'];

        if ($raw === null || $raw === '') {
            // An explicitly empty string is a legitimate value for text
            // settings such as GSTIN; only fall back for null.
            return $raw === null ? $default : $raw;
        }

        return match ($entry['type']) {
            'int'   => (int) $raw,
            'float' => (float) $raw,
            'bool'  => $raw === '1' || strtolower($raw) === 'true',
            'json'  => json_decode($raw, true) ?? $default,
            default => $raw,
        };
    }

    public static function getString(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (float) $value : $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    /**
     * Decoded JSON setting.
     *
     * @param array<mixed> $default
     * @return array<mixed>
     */
    public static function getArray(string $key, array $default = []): array
    {
        $value = self::get($key, $default);
        return is_array($value) ? $value : $default;
    }

    /**
     * Write a setting. Creates the row if it does not exist yet.
     */
    public static function set(string $key, mixed $value, string $type = 'string', string $group = 'general', bool $isPublic = false): void
    {
        $stored = match ($type) {
            'json'  => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'bool'  => ($value === true || $value === 1 || $value === '1') ? '1' : '0',
            default => is_scalar($value) ? (string) $value : (string) json_encode($value),
        };

        Database::query(
            'INSERT INTO settings (skey, svalue, stype, sgroup, is_public)
                  VALUES (:k, :v, :t, :g, :p)
             ON DUPLICATE KEY UPDATE
                  svalue = VALUES(svalue),
                  stype  = VALUES(stype)',
            [
                'k' => $key,
                'v' => $stored,
                't' => $type,
                'g' => $group,
                'p' => $isPublic ? 1 : 0,
            ]
        );

        self::load();
        self::$cache[$key] = [
            'value'  => $stored,
            'type'   => $type,
            'public' => $isPublic,
        ];
    }

    /**
     * Bulk write, used by the admin settings form.
     *
     * @param array<string, mixed> $pairs key => value (types are preserved
     *                                    from the existing row)
     */
    public static function setMany(array $pairs): void
    {
        self::load();

        Database::transaction(static function () use ($pairs): void {
            foreach ($pairs as $key => $value) {
                $type  = self::$cache[$key]['type'] ?? 'string';
                $known = isset(self::$cache[$key]);

                if (!$known) {
                    // Unknown keys are ignored rather than silently created,
                    // so a stray form field cannot pollute configuration.
                    continue;
                }

                self::set($key, $value, $type);
            }
        });

        Logger::audit('settings.update', 'settings', '', null, array_keys($pairs));
    }

    /**
     * Everything marked is_public — safe to hand to the browser.
     *
     * @return array<string, mixed>
     */
    public static function publicSettings(): array
    {
        self::load();

        $out = [];
        foreach (self::$cache as $key => $entry) {
            if ($entry['public']) {
                $out[$key] = self::get($key);
            }
        }

        return $out;
    }

    /**
     * All settings in one group, for rendering the admin form.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function group(string $group): array
    {
        return Database::fetchAll(
            'SELECT skey, svalue, stype, sgroup, label, is_public
               FROM settings
              WHERE sgroup = :g
              ORDER BY id',
            ['g' => $group]
        );
    }

    /**
     * Drop the in-memory cache — used by long-running cron scripts.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
