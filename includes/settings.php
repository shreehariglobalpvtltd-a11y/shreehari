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

/* The company's Mehsana desk, on its Indian number (owner, 8 Sep 2026).
   Defined once, here, so every "call us" line, wa.me link and ticket footer
   in the system falls back to the SAME number when the settings row is
   missing — rather than to an empty string, which printed "call " with
   nothing after it and produced a dead wa.me/ link on a real ticket. */
const OFFICE_PHONE_DISPLAY   = '+91 91048 01507';
const OFFICE_WHATSAPP_DIGITS = '919104801507';

final class Settings
{
    /** @var array<string, array{value: string|null, type: string, public: bool}>|null */
    private static ?array $cache = null;

    /** Decoded json values, memoised per request (5 Sep 2026): agent_codes
     *  & co. were re-decoded on every agentCodeLabel() call in render loops. */
    /** @var array<string, mixed> */
    private static array $decoded = [];

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

        if ($entry['type'] === 'json') {
            if (!array_key_exists($key, self::$decoded)) {
                self::$decoded[$key] = json_decode($raw, true);
            }
            return self::$decoded[$key] ?? $default;
        }

        return match ($entry['type']) {
            'int'   => (int) $raw,
            'float' => (float) $raw,
            'bool'  => $raw === '1' || strtolower($raw) === 'true',
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
        if ($type === 'json' && is_string($value)) {
            // 4 Sep 2026: the admin form posts a JSON *string* for stype=json
            // rows. json_encode()-ing that string wrapped it in quotes and
            // escaped it, so every Save added one more layer and the row
            // became unreadable (cabin_pricing on live carried 1,792
            // backslashes; getArray() then silently returned the code
            // default). Decode first — peeling any layers an earlier save
            // already added — and refuse invalid JSON instead of storing it.
            $value = self::decodeJsonLayers($value, $key);
        }

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
        unset(self::$decoded[$key]);
    }

    /**
     * Turn a JSON string (possibly wrapped in one or more stray layers of
     * encoding by the pre-4-Sep-2026 writer) into the value it represents.
     * Throws on text that is not JSON at all, so a typo in the admin form
     * keeps the previous value instead of replacing it with garbage.
     *
     * @throws InvalidArgumentException
     */
    public static function decodeJsonLayers(string $raw, string $key = ''): mixed
    {
        $trim = trim($raw);
        if ($trim === '') {
            return [];
        }
        $value = json_decode($trim, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                ($key !== '' ? $key . ': ' : '') . 'not valid JSON (' . json_last_error_msg() . ')'
            );
        }
        // A doubly-encoded row decodes to a *string* that is itself JSON;
        // keep peeling while that is the case (bounded — a real string
        // value such as "abc" stops the loop at once).
        $guard = 0;
        while (is_string($value) && $guard++ < 12) {
            $inner = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                break;
            }
            $value = $inner;
        }
        return $value;
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

        // Snapshot BEFORE the write so the audit row records old→new, not
        // just "these keys were touched". Previously only array_keys($pairs)
        // was logged — a stealth change to upi_id, twilio_auth_token or
        // agent_flat_direct was invisible after the fact.
        $before = [];
        foreach ($pairs as $key => $_v) {
            if (isset(self::$cache[$key])) {
                $before[$key] = self::$cache[$key]['value'];
            }
        }

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

        // Mask obvious secrets before they land in audit_logs — the log itself
        // is protected but there is no reason to keep a plaintext copy of a
        // Twilio auth token or SMTP password inside it.
        $mask = static function (string $key, $value): string {
            $sensitive = preg_match('/(token|password|secret|auth|key|api)/i', $key) === 1;
            $str       = is_scalar($value) ? (string) $value : json_encode($value);
            if ($str === '' || $str === false || $str === null) { return ''; }
            if (!$sensitive) {
                return mb_strlen($str) > 200 ? mb_substr($str, 0, 200) . '…' : $str;
            }
            $len = mb_strlen($str);
            return $len <= 4 ? str_repeat('*', $len) : mb_substr($str, 0, 2) . str_repeat('*', max(0, $len - 4)) . mb_substr($str, -2);
        };

        $diff = [];
        foreach ($pairs as $key => $value) {
            if (!isset(self::$cache[$key])) { continue; }
            $oldStr = $mask($key, $before[$key] ?? null);
            $newStr = $mask($key, $value);
            if ($oldStr !== $newStr) {
                $diff[$key] = ['old' => $oldStr, 'new' => $newStr];
            }
        }

        Logger::audit('settings.update', 'settings', '', $diff === [] ? null : ['keys' => array_keys($diff)], $diff === [] ? array_keys($pairs) : $diff);
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

        /* Round trip gate (17 Sep 2026): the app offers the "Round trip" pill
           only when this public bool is on (row added by
           database/upgrade-2026-09-round-trip.sql, shipped off). Guaranteed
           present in the feed — a site that has not run the upgrade behaves
           exactly like one that has, instead of the browser guessing. */
        if (!array_key_exists('round_trip_on', $out)) {
            $out['round_trip_on'] = false;
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

    /* =================================================================
     *  THE OFFICE NUMBER — one definition (owner, 8 Sep 2026)
     *
     *  The company's Mehsana desk on its Indian number, 9104801507. It was
     *  already the live value of company_phone and admin_whatsapp, but it was
     *  READ with four different fallbacks: '', '+91 91048 01507',
     *  '919104801507', and admin_whatsapp-then-company_phone-then-''. Most
     *  sites used the empty one, so if that row were ever cleared or mistyped
     *  a passenger would be told to "call " with nothing after it, and the
     *  wa.me links would point at wa.me/ — a dead link on a ticket.
     *
     *  These two are now the only way to ask. The number is the built-in
     *  fallback, so the office number cannot go missing even with no row at
     *  all; a row still overrides it, which is how the desk moves it later.
     * ================================================================= */

    /** The office number as PEOPLE read it: "+91 91048 01507". */
    public static function officePhone(): string
    {
        $v = trim(self::getString('company_phone', ''));
        return $v !== '' ? $v : OFFICE_PHONE_DISPLAY;
    }

    /**
     * The office number as wa.me / tel: want it — digits, country code, no
     * punctuation: "919104801507". Prefers an explicit company_whatsapp (the
     * desk may answer WhatsApp on a different handset), then company_phone.
     */
    public static function officeWhatsApp(): string
    {
        foreach ([self::getString('company_whatsapp', ''), self::getString('company_phone', '')] as $raw) {
            $d = preg_replace('/\D/', '', $raw) ?? '';
            if ($d === '') {
                continue;
            }
            // A bare 10-digit Indian number needs its country code for wa.me.
            if (strlen($d) === 10) {
                $d = '91' . $d;
            }
            if (strlen($d) >= 11) {
                return $d;
            }
        }
        return OFFICE_WHATSAPP_DIGITS;
    }

    /* =================================================================
     *  THE LETTERHEAD — one definition (owner, 10 Sep 2026:
     *  "logo name details haru ramro sita manage hos")
     *
     *  Every printed document used to carry its own copy of the company's
     *  identity: the chalani PDF had the Mehsana address, the operator's
     *  name and the office email typed into its header function, the
     *  challan PNG read company_name only, and the CIN default was spelled
     *  out in three files. Change the address and you had to remember all
     *  of them.
     *
     *  One call now answers "whose document is this?", with the values that
     *  were already hard-coded as the built-in fallbacks — so nothing moves
     *  until someone edits a row, and after the migration every one of these
     *  is editable in Admin -> Settings (group "company").
     *
     *  Keys: name, legal, address, addressNe, phone, whatsapp, email, cin,
     *  web, operator, operatorNe, counters.
     *
     * @return array<string,string>
     * ================================================================= */
    public static function company(): array
    {
        $s = static fn(string $k, string $d): string => trim(self::getString($k, '')) !== ''
            ? trim(self::getString($k, '')) : $d;

        return [
            'name'      => $s('company_name', APP_NAME),
            'legal'     => $s('company_legal', 'S Hari Global Private Limited'),
            'legalNe'   => $s('company_legal_ne', 'एस हरि ग्लोबल प्राइभेट लिमिटेड'),
            'address'   => $s('company_address', 'Near Shilpa Garage, Silver Complex, Mehsana - 384002, Gujarat, India'),
            'addressNe' => $s('company_address_ne', 'प्रधान कार्यालय : शिल्पा ग्यारेज नजिक, सिल्भर कम्प्लेक्स, मेहसाणा – ३८४००२, गुजरात, भारत'),
            'phone'     => self::officePhone(),
            'whatsapp'  => self::officeWhatsApp(),
            'email'     => $s('company_email', 'shreehariglobalpvtltd@gmail.com'),
            'cin'       => $s('company_cin', 'U52291GJ2026PTC174029'),
            'web'       => $s('company_web', 'shreehariglobal.in'),
            // company_ceo is the row this site has always carried; company_operator
            // is the newer, document-specific name. Prefer the specific one.
            'operator'  => $s('company_operator', $s('company_ceo', 'Sher Bahadur Bishwakarma')),
            'operatorNe'=> $s('company_operator_ne', 'शेर बहादुर विश्वकर्मा'),
            'counters'  => $s('company_counters',
                'Mehsana +91 91048 01507 · Ahmedabad +91 91570 01507 · Baroda +91 97264 01507 · Surat +91 73059 01507'),
        ];
    }

    /**
     * Drop the in-memory cache — used by long-running cron scripts.
     */
    public static function flush(): void
    {
        self::$cache   = null;
        self::$decoded = [];
    }
}
