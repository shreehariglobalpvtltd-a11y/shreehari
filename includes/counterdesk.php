<?php
/**
 * includes/counterdesk.php — a ticket window as a place, not a label.
 *
 * Until 26 Sep 2026 a "counter location" was one line of text in a settings
 * box (CODE|Name) that got printed on a ticket. That was enough while every
 * desk was in Gujarat and took rupees. It stopped being enough the day the
 * owner asked for Nepalgunj:
 *
 *   "sabai ko lagi alag alag add garna milos jati pani — sabko hisab kitab
 *    admin le herna milos, chalani ma ni chuttinu paryo"
 *   "NPR ma bech, dubai record"
 *
 * A desk now carries its country, the money it collects, its own rate, its
 * phone and whether it is still open — and the code is FROZEN onto every
 * booking it sells (bookings.counter_code), so moving a clerk to another town
 * never rewrites last month's sheet.
 *
 * MONEY. INR stays the company's currency: fares, discounts, refunds,
 * commissions and every existing ledger are rupees and nothing here changes
 * that. A Nepal desk quotes and collects NPR, and BOTH numbers are kept —
 * bookings.fx_* (what the customer was told) and payments.local_* (what the
 * drawer took), each with the rate used at that moment. Converting back is
 * never necessary and never guessed.
 *
 * SAFE BEFORE THE MIGRATION. Every read falls back to the old settings row
 * when the counter_locations TABLE is not there yet, so this file may be
 * deployed before database/upgrade-2026-09-counter-desks.sql is applied.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class CounterDesk
{
    /** Notes and coins a drawer is counted in, largest first, per currency. */
    public const DENOMINATIONS = [
        'INR' => [500, 200, 100, 50, 20, 10, 5, 2, 1],
        'NPR' => [1000, 500, 100, 50, 20, 10, 5, 2, 1],
    ];

    /** Currency symbols as a clerk writes them. */
    public const SYMBOL = ['INR' => '₹', 'NPR' => 'रू'];

    /** @var array<string,array<string,mixed>>|null code => desk */
    private static ?array $cache = null;

    /** Drop the in-memory list — used after a write and by long cron scripts. */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /** Does the real table exist? False on a checkout whose SQL is not applied. */
    public static function hasTable(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Database::fetch("SHOW TABLES LIKE 'counter_locations'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /**
     * Every desk, code => row. Rows always carry code, name, country,
     * currency, fx_rate, phone, address, is_active, sort_order.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(bool $activeOnly = false): array
    {
        if (self::$cache === null) {
            self::$cache = self::hasTable() ? self::fromTable() : self::fromSettings();
        }
        if (!$activeOnly) {
            return self::$cache;
        }

        return array_filter(self::$cache, static fn(array $d): bool => (int) $d['is_active'] === 1);
    }

    /** One desk, or null when the code is unknown (or blank). */
    public static function get(string $code): ?array
    {
        $code = self::normaliseCode($code);
        return $code === '' ? null : (self::all()[$code] ?? null);
    }

    /** code => name, the shape the old Settings::counterLocations() returned. */
    public static function options(bool $activeOnly = true): array
    {
        return array_map(static fn(array $d): string => (string) $d['name'], self::all($activeOnly));
    }

    /** "Nepalgunj — Bus Park (NPJ)" — the printable label. */
    public static function label(string $code, string $name = ''): string
    {
        $code = self::normaliseCode($code);
        $name = trim($name);
        if ($name === '' && $code !== '') {
            $name = (string) (self::get($code)['name'] ?? '');
        }
        if ($name === '') {
            return $code;
        }

        return $code === '' ? $name : $name . ' (' . $code . ')';
    }

    /** 🇳🇵 / 🇮🇳 — the flag the owner asked to see in front of a place. */
    public static function flag(string $countryOrCode): string
    {
        $v = strtoupper(trim($countryOrCode));
        if ($v !== 'IN' && $v !== 'NP') {
            $v = (string) (self::get($v)['country'] ?? 'IN');
        }

        return $v === 'NP' ? '🇳🇵' : '🇮🇳';
    }

    /** The money this desk collects: INR or NPR. Unknown desk = INR. */
    public static function currency(string $code): string
    {
        $c = strtoupper((string) (self::get($code)['currency'] ?? 'INR'));
        return isset(self::DENOMINATIONS[$c]) ? $c : 'INR';
    }

    /** Dialing code of the desk's country — 977 for Nepal, 91 for India. */
    public static function dialCode(string $code): string
    {
        return ((string) (self::get($code)['country'] ?? 'IN')) === 'NP' ? '977' : '91';
    }

    /**
     * Local units per 1 INR for this desk, right now.
     *
     * The desk may pin its own rate (counter_locations.fx_rate) — a border
     * desk that buys NPR at a different rate than the head office quotes.
     * Otherwise the company rate (settings npr_per_inr) is used. An INR desk
     * is always 1.0, never a peg.
     */
    public static function rate(string $code): float
    {
        if (self::currency($code) === 'INR') {
            return 1.0;
        }
        $own = self::get($code)['fx_rate'] ?? null;
        if ($own !== null && (float) $own > 0) {
            return round((float) $own, 4);
        }

        $peg = Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6);

        return $peg > 0 ? round($peg, 4) : 1.6;
    }

    /**
     * An INR amount as this desk's money.
     *
     * Rounded to a whole unit: a clerk hands over notes, not paisa, and a
     * ticket that says "NPR 3,518.40" invites an argument at the window.
     *
     * @return array{currency:string,amount:float,rate:float,inr:float}
     */
    public static function convert(float $inr, string $code): array
    {
        $cur  = self::currency($code);
        $rate = self::rate($code);

        return [
            'currency' => $cur,
            'rate'     => $rate,
            'inr'      => round($inr, 2),
            'amount'   => $cur === 'INR' ? round($inr, 2) : (float) round($inr * $rate),
        ];
    }

    /** "रू 3,520" / "₹2,199" — an amount written the way that desk writes it. */
    public static function format(float $amount, string $currency): string
    {
        $currency = strtoupper($currency);
        $sym      = self::SYMBOL[$currency] ?? ($currency . ' ');
        $n        = function_exists('indianNumber')
            ? indianNumber($amount)
            : number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);

        return $currency === 'NPR' ? $sym . ' ' . $n : $sym . $n;
    }

    /** The notes this desk's drawer is counted in. */
    public static function denominations(string $code): array
    {
        return self::DENOMINATIONS[self::currency($code)] ?? self::DENOMINATIONS['INR'];
    }

    /**
     * The desk a staff member is signed in at: ['code'=>..,'name'=>..] with
     * blanks when they have not been given one. Reads admin_profiles, which
     * is where staff.php and agents.php already write it.
     */
    public static function forAdmin(int $adminId): array
    {
        if ($adminId <= 0) {
            return ['code' => '', 'name' => ''];
        }
        try {
            $row = Database::fetch(
                'SELECT counter_code, counter_name FROM admin_profiles WHERE admin_id = :a LIMIT 1',
                ['a' => $adminId]
            );
        } catch (Throwable $e) {
            $row = null;
        }
        $code = self::normaliseCode((string) ($row['counter_code'] ?? ''));
        $name = trim((string) ($row['counter_name'] ?? ''));
        if ($name === '' && $code !== '') {
            $name = (string) (self::get($code)['name'] ?? '');
        }

        return ['code' => $code, 'name' => $name];
    }

    /**
     * The code to stamp on a sale by this seller. Blank when the seller has
     * no desk — an online sale, or a desk nobody has assigned yet. Never
     * invents one: a wrong town on a ticket is worse than no town.
     */
    public static function codeForSale(?int $adminId): ?string
    {
        if ($adminId === null || $adminId <= 0) {
            return null;
        }
        $code = self::forAdmin($adminId)['code'];

        return $code === '' ? null : $code;
    }

    /**
     * Create or update a desk. Returns the stored code.
     *
     * The settings mirror (counter_locations, "CODE|Name" per line) is
     * rewritten on every write so the datalists in agents.php / staff.php and
     * anything else still reading the old row stay true.
     */
    public static function save(array $in): string
    {
        $code = self::normaliseCode((string) ($in['code'] ?? ''));
        if ($code === '') {
            throw new InvalidArgumentException('A desk needs a short code — NPJ, SRT, RJT.');
        }
        $name = trim(Security::clean((string) ($in['name'] ?? ''), 120));
        if ($name === '') {
            $name = $code;
        }
        $country  = strtoupper(trim((string) ($in['country'] ?? 'IN'))) === 'NP' ? 'NP' : 'IN';
        $currency = strtoupper(trim((string) ($in['currency'] ?? '')));
        if (!isset(self::DENOMINATIONS[$currency])) {
            $currency = $country === 'NP' ? 'NPR' : 'INR';
        }
        $rate = $in['fx_rate'] ?? null;
        $rate = ($rate === null || $rate === '' || (float) $rate <= 0) ? null : round((float) $rate, 4);
        if ($currency === 'INR') {
            $rate = null;   // a rupee desk has no rate to pin
        }

        $row = [
            'code'       => $code,
            'name'       => $name,
            'country'    => $country,
            'currency'   => $currency,
            'fx_rate'    => $rate,
            'phone'      => trim(Security::clean((string) ($in['phone'] ?? ''), 40)) ?: null,
            'address'    => trim(Security::clean((string) ($in['address'] ?? ''), 190)) ?: null,
            'is_active'  => empty($in['is_active']) ? 0 : 1,
            'sort_order' => (int) ($in['sort_order'] ?? 0),
            'note'       => trim(Security::clean((string) ($in['note'] ?? ''), 255)) ?: null,
        ];

        if (!self::hasTable()) {
            throw new RuntimeException('Counter desks need database/upgrade-2026-09-counter-desks.sql applied first.');
        }

        $exists = Database::fetch('SELECT id FROM counter_locations WHERE code = :c LIMIT 1', ['c' => $code]);
        if ($exists === null) {
            Database::insert('counter_locations', $row);
        } else {
            unset($row['code']);
            Database::update('counter_locations', $row, 'code = :c', ['c' => $code]);
        }

        self::flush();
        self::syncSettingsMirror();

        return $code;
    }

    /**
     * Close a desk. Never deletes: tickets, shifts and last year's ledger all
     * point at the code, and a deleted place would read as "nowhere".
     */
    public static function deactivate(string $code): void
    {
        $code = self::normaliseCode($code);
        if ($code === '' || !self::hasTable()) {
            return;
        }
        Database::update('counter_locations', ['is_active' => 0], 'code = :c', ['c' => $code]);
        self::flush();
        self::syncSettingsMirror();
    }

    /** Rewrite the old settings row from the table, so both always agree. */
    public static function syncSettingsMirror(): void
    {
        $lines = [];
        foreach (self::all() as $d) {
            if ((int) $d['is_active'] !== 1) {
                continue;
            }
            $lines[] = $d['code'] . '|' . $d['name'];
        }
        if ($lines === []) {
            return;   // never blank the mirror — an empty list would hide every desk
        }
        Settings::set('counter_locations', implode("\n", $lines), 'text', 'company');
    }

    /* ----------------------------------------------------------------- */

    private static function normaliseCode(string $code): string
    {
        $code = mb_strtoupper(trim($code));
        $code = preg_replace('/[^A-Z0-9_-]/u', '', $code) ?? '';

        return mb_substr($code, 0, 16);
    }

    /** @return array<string,array<string,mixed>> */
    private static function fromTable(): array
    {
        try {
            $rows = Database::fetchAll('SELECT * FROM counter_locations ORDER BY sort_order, code');
        } catch (Throwable $e) {
            return self::fromSettings();
        }
        if ($rows === []) {
            return self::fromSettings();   // table made, nothing seeded yet
        }
        $out = [];
        foreach ($rows as $r) {
            $code = self::normaliseCode((string) $r['code']);
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'code'       => $code,
                'name'       => (string) $r['name'],
                'country'    => strtoupper((string) $r['country']) === 'NP' ? 'NP' : 'IN',
                'currency'   => strtoupper((string) $r['currency']),
                'fx_rate'    => $r['fx_rate'] === null ? null : (float) $r['fx_rate'],
                'phone'      => (string) ($r['phone'] ?? ''),
                'address'    => (string) ($r['address'] ?? ''),
                'is_active'  => (int) $r['is_active'],
                'sort_order' => (int) $r['sort_order'],
                'note'       => (string) ($r['note'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The pre-table world: the settings row, with the country guessed from
     * the town's name. Only ever a fallback — as soon as the migration runs,
     * the table answers and nothing is guessed.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function fromSettings(): array
    {
        $out = [];
        $i   = 0;
        foreach (Settings::counterLocations() as $code => $name) {
            $np = (bool) preg_match('/nepal|nepalgunj|kohalpur|dhamboji|banke/i', $code . ' ' . $name)
                  && !preg_match('/rupaid/i', $code . ' ' . $name);
            $out[$code] = [
                'code'       => $code,
                'name'       => $name,
                'country'    => $np ? 'NP' : 'IN',
                'currency'   => $np ? 'NPR' : 'INR',
                'fx_rate'    => null,
                'phone'      => '',
                'address'    => '',
                'is_active'  => 1,
                'sort_order' => ($i += 10),
                'note'       => '',
            ];
        }

        return $out;
    }
}
