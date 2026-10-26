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
 * deployed before database/upgrade-2026-10-04-counter-desks.sql is applied.
 *
 * 4 Oct 2026 - ported onto the release line with four more desk facts:
 * the COMPANY (entity IN|NP) whose ticket the desk issues, the language its
 * screen opens in, a map link and opening hours. SIDE (which run the desk
 * sells) and CURRENCY (which money it takes) are separate questions here:
 * Rupaidiha sells the return run but stands in India and takes rupees.
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

    /** The ways a counter can take money, as includes/booking.php names them. */
    public const METHODS = ['cash', 'upi', 'esewa', 'bank'];

    /**
     * The WEBSITE-only desk facts (9 Oct 2026, database/upgrade-2026-10-09-
     * site-facts.sql): whether the office is listed on the site, what kind it
     * is, its status and its names in Nepali / Hindi. None of them prints on a
     * ticket, so changing only these never re-renders issued tickets.
     */
    public const PUBLIC_FIELDS = ['show_public', 'office_kind', 'public_status', 'name_ne', 'name_hi',
                                  'address_ne', 'lat', 'lng', 'whatsapp', 'contact_person'];

    /** What a listed office is: head office, branch, partner (Nepal) or border point. */
    public const OFFICE_KINDS = ['head', 'branch', 'partner', 'border'];

    /** The owner's office list (9 Oct 2026) - used only until the migration adds show_public. */
    private const DEFAULT_PUBLIC = ['MSA' => 'head', 'AMD' => 'branch', 'SRT' => 'branch', 'RJT' => 'branch', 'NPJ' => 'partner'];

    /** @var array<string,array<string,mixed>>|null code => desk */
    private static ?array $cache = null;

    /** @var list<array<string,mixed>>|null publicOffices(), per request */
    private static ?array $publicCache = null;

    /** Drop the in-memory list — used after a write and by long cron scripts. */
    public static function flush(): void
    {
        self::$cache = null;
        self::$publicCache = null;
    }

    /**
     * Is the desk frozen on the sale yet? (bookings.counter_code). Pages ask
     * this before offering a desk filter, so a database whose migration has
     * not run shows no filter rather than a 500.
     */
    public static function stampColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Database::fetch("SHOW COLUMNS FROM bookings LIKE 'counter_code'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
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
     * Are the frozen-money columns there? bookings.fx_total is what the
     * customer was quoted at a Nepal window, payments.local_amount what the
     * drawer took. Pages read them only when present, so a checkout whose
     * SQL has not run shows the rupee alone rather than a 500.
     *
     * @return array{bookings:bool,payments:bool}
     */
    public static function frozenColumns(): array
    {
        static $has = null;
        if ($has === null) {
            $has = ['bookings' => false, 'payments' => false];
            try {
                $has['bookings'] = Database::fetch("SHOW COLUMNS FROM bookings LIKE 'fx_total'") !== null;
                $has['payments'] = Database::fetch("SHOW COLUMNS FROM payments LIKE 'local_amount'") !== null;
            } catch (Throwable $e) {
                // an unreadable schema reads as "not there" — the rupee still prints
            }
        }
        return $has;
    }

    /**
     * Does this table carry this column? Cached per request. Every optional
     * stamp (entity, fx_*, local_*) asks before it writes, so a database
     * whose migration ran only partly keeps selling (4 Oct 2026).
     */
    public static function hasColumn(string $table, string $column): bool
    {
        static $seen = [];
        $k = $table . '.' . $column;
        if (!isset($seen[$k])) {
            try {
                $seen[$k] = preg_match('/^[a-z_]+$/', $table . $column) === 1
                    && Database::fetch('SHOW COLUMNS FROM `' . $table . '` LIKE ' . Database::pdo()->quote($column)) !== null;
            } catch (Throwable $e) {
                $seen[$k] = false;
            }
        }
        return $seen[$k];
    }

    /**
     * The money frozen on a row, as text — or '' when that sale was in rupees.
     *
     * A Nepal desk writes what it quoted on the booking (fx_currency/fx_total)
     * and what it took on the payment (local_currency/local_amount). This
     * prints that figure and NEVER converts: a rupee row has nothing to show,
     * and an NPR row shows the NPR written at the second of the sale, whatever
     * the peg is today. That is the whole point of freezing it.
     */
    public static function frozen(?array $row, string $curKey = 'fx_currency', string $amtKey = 'fx_total'): string
    {
        if ($row === null) {
            return '';
        }
        $cur = strtoupper(trim((string) ($row[$curKey] ?? '')));
        $amt = (float) ($row[$amtKey] ?? 0);
        if ($cur === '' || $cur === 'INR' || $amt <= 0) {
            return '';
        }
        return self::format($amt, $cur);
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

    /**
     * The run this desk sells, when it only really sells one.
     *
     * Owner, 26 Sep 2026, looking at a demo ticket that read Surat →
     * Rupaidiha: "khai ta ticket mai Surat to Rupaidiha lekhieko chha, yesto
     * sano mistake nahunu paryo." He is right. A window at Nepalgunj serves
     * passengers standing in Nepal: they board at Rupaidiha and travel INTO
     * India. A Gujarat window serves the opposite. Leaving that to the clerk
     * every single sale is how the wrong direction eventually gets sold.
     *
     * '' = this desk sells both and the screen stays on Auto. The clerk can
     * always override the chip — a Nepalgunj customer buying a relative's
     * return leg is a real sale, not a mistake.
     *
     * @return string 'toNepal' | 'toIndia' | ''
     */
    public static function direction(string $code): string
    {
        $d = (string) (self::get($code)['default_direction'] ?? '');

        return in_array($d, ['toNepal', 'toIndia'], true) ? $d : '';
    }

    /**
     * Which side of the border this desk SELLS from: 'nepal' when it opens on
     * the return run (Rupaidiha -> Gujarat), else 'india'. Says nothing about
     * money - ask currency() for that.
     */
    public static function side(string $code): string
    {
        return self::direction($code) === 'toIndia' ? 'nepal' : 'india';
    }

    /**
     * The COMPANY whose ticket this desk issues: 'IN' (S Hari Global Pvt Ltd)
     * or 'NP' (the Nepal partner company). An unknown or blank desk - an
     * online sale - is the India company, as every sale was before 4 Oct 2026.
     */
    public static function entity(string $code): string
    {
        return strtoupper((string) (self::get($code)['entity'] ?? 'IN')) === 'NP' ? 'NP' : 'IN';
    }

    /** The language this desk's screen opens in, or '' for the site default. */
    public static function lang(string $code): string
    {
        $l = strtolower(trim((string) (self::get($code)['lang'] ?? '')));

        return in_array($l, ['en', 'hi', 'ne', 'gu'], true) ? $l : '';
    }

    /** The town this desk's passengers board at — prefills the counter search. */
    public static function defaultFrom(string $code): string
    {
        return trim((string) (self::get($code)['default_from'] ?? ''));
    }

    /** The money this desk collects: INR or NPR. Unknown desk = INR. */
    public static function currency(string $code): string
    {
        $c = strtoupper((string) (self::get($code)['currency'] ?? 'INR'));
        return isset(self::DENOMINATIONS[$c]) ? $c : 'INR';
    }

    /**
     * The payment methods this desk may take, or null for "all of them".
     *
     * The owner's Nepalgunj window has no Nepali payment gateway and no
     * Nepali bank account: it collects cash. Until now nothing stopped a
     * clerk there tapping UPI, which would have put money in the books that
     * never existed in any account.
     *
     * @return list<string>|null
     */
    public static function allowedMethods(string $code): ?array
    {
        $raw = trim((string) (self::get($code)['allowed_methods'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $out = [];
        foreach (explode(',', $raw) as $m) {
            $m = strtolower(trim($m));
            if ($m !== '' && in_array($m, self::METHODS, true)) {
                $out[] = $m;
            }
        }

        return $out === [] ? null : array_values(array_unique($out));
    }

    /** May this desk take this money? A desk with no rule may take anything. */
    public static function allowsMethod(string $code, string $method): bool
    {
        $allowed = self::allowedMethods($code);

        return $allowed === null || in_array(strtolower($method), $allowed, true);
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
     * The WHERE fragment that pins a bookings query to one desk.
     *
     * Reads the code frozen on the sale and falls back to the seller's desk
     * today for rows written before the stamp existed — the same expression
     * the counter book and the register filter use, kept in one place so the
     * three can never drift apart.
     */
    public static function scopeClause(string $alias = 'b', string $param = 'deskScope'): string
    {
        return "COALESCE(NULLIF({$alias}.counter_code,''),"
             . " (SELECT apd.counter_code FROM admin_profiles apd WHERE apd.admin_id = {$alias}.sold_by_admin_id)) = :{$param}";
    }

    /**
     * May the signed-in staff member open / act on this booking under desk
     * isolation? (7 Oct 2026.) True whenever isolation is off or the user is
     * not a counter clerk; otherwise only a booking cut at (or sold by) their
     * own desk. The list filters alone left the booking page and the payment
     * buttons open to any PNR typed by hand.
     */
    public static function bookingVisible(?int $bookingId = null, ?string $pnr = null): bool
    {
        $scope = Auth::deskScopeCode();
        if ($scope === null || !self::stampColumn()) {
            return true;
        }
        if (($bookingId ?? 0) <= 0 && trim((string) $pnr) === '') {
            return true;   // nothing named yet
        }
        $key = ($bookingId ?? 0) > 0 ? 'b.id = :k' : 'b.pnr = :k';
        return Database::exists(
            'SELECT 1 FROM bookings b WHERE ' . $key . ' AND ' . self::scopeClause('b') . ' LIMIT 1',
            ['k' => ($bookingId ?? 0) > 0 ? $bookingId : (string) $pnr, 'deskScope' => $scope]
        );
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
            'allowed_methods' => self::cleanMethods($in['allowed_methods'] ?? null),
            'default_direction' => in_array($in['default_direction'] ?? '', ['toNepal', 'toIndia'], true)
                ? (string) $in['default_direction'] : null,
            'default_from'      => trim(Security::clean((string) ($in['default_from'] ?? ''), 80)) ?: null,
            'entity'     => strtoupper(trim((string) ($in['entity'] ?? ''))) === 'NP' ? 'NP'
                : (strtoupper(trim((string) ($in['entity'] ?? ''))) === 'IN' ? 'IN' : $country),
            'lang'       => in_array(strtolower(trim((string) ($in['lang'] ?? ''))), ['en', 'hi', 'ne', 'gu'], true)
                ? strtolower(trim((string) $in['lang'])) : null,
            'phone'      => trim(Security::clean((string) ($in['phone'] ?? ''), 40)) ?: null,
            'address'    => trim(Security::clean((string) ($in['address'] ?? ''), 190)) ?: null,
            'map_url'    => self::cleanUrl((string) ($in['map_url'] ?? '')),
            'hours'      => trim(Security::clean((string) ($in['hours'] ?? ''), 120)) ?: null,
            'is_active'  => empty($in['is_active']) ? 0 : 1,
            'sort_order' => (int) ($in['sort_order'] ?? 0),
            'note'       => trim(Security::clean((string) ($in['note'] ?? ''), 255)) ?: null,
        ];

        if (!self::hasTable()) {
            throw new RuntimeException('Counter desks need database/upgrade-2026-10-04-counter-desks.sql applied first.');
        }

        /* The website-only facts (9 Oct 2026). Written only when the caller
           sent the key AND the column exists - a caller that never heard of
           them (My desk, an old form) must not unlist an office by omission. */
        $pub = [
            'show_public'    => static fn($v): int => empty($v) ? 0 : 1,
            'office_kind'    => static fn($v): ?string => in_array((string) $v, self::OFFICE_KINDS, true) ? (string) $v : null,
            'public_status'  => static fn($v): string => (string) $v === 'coming_soon' ? 'coming_soon' : 'open',
            'name_ne'        => static fn($v): ?string => trim(Security::clean((string) $v, 120)) ?: null,
            'name_hi'        => static fn($v): ?string => trim(Security::clean((string) $v, 120)) ?: null,
            'address_ne'     => static fn($v): ?string => trim(Security::clean((string) $v, 255)) ?: null,
            'lat'            => static fn($v): ?float => self::cleanCoord($v, 90.0),
            'lng'            => static fn($v): ?float => self::cleanCoord($v, 180.0),
            'whatsapp'       => static fn($v): ?string => trim(Security::clean((string) $v, 40)) ?: null,
            'contact_person' => static fn($v): ?string => trim(Security::clean((string) $v, 80)) ?: null,
        ];
        foreach ($pub as $col => $clean) {
            if (array_key_exists($col, $in) && self::hasColumn('counter_locations', $col)) {
                $row[$col] = $clean($in[$col]);
            }
        }

        $before = self::get($code);
        $exists = Database::fetch('SELECT id FROM counter_locations WHERE code = :c LIMIT 1', ['c' => $code]);
        if ($exists === null) {
            Database::insert('counter_locations', $row);
        } else {
            unset($row['code']);
            Database::update('counter_locations', $row, 'code = :c', ['c' => $code]);
        }

        /* The profile carries a copy of the name (it predates the table);
           a renamed desk must not leave the old town on staff rows. */
        try {
            Database::run('UPDATE admin_profiles SET counter_name = :n WHERE counter_code = :c', ['n' => $name, 'c' => $code]);
        } catch (Throwable $e) {
            // never fail a desk save on the mirror copy
        }

        self::flush();
        self::syncSettingsMirror();
        /* The desk's phone / address print on its tickets, so a change there
           re-renders them. A change to the website-only facts alone (listed,
           kind, Nepali name, map pin …) does not touch any ticket. */
        if (self::identityChanged($before, self::get($code))) {
            Settings::touchTicketIdentity();
        }

        return $code;
    }

    /**
     * Did anything that prints on a ticket change between two desk rows?
     * A new desk (no "before") always counts.
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function identityChanged(?array $before, ?array $after): bool
    {
        if ($before === null || $after === null) {
            return true;
        }
        foreach ($after as $k => $v) {
            if (in_array($k, self::PUBLIC_FIELDS, true)) {
                continue;
            }
            if ((string) ($before[$k] ?? '') !== (string) ($v ?? '')) {
                return true;
            }
        }
        return false;
    }

    /**
     * THE PUBLIC OFFICE LIST (9 Oct 2026, owner: "office haru kaha kaha xa
     * sabai lai listed banau — Mehsana head, NPJ, Rajkot, AMD, Surat … expand
     * hudai xa"). The desks the office ticked "show on website", head office
     * first, then by the desk order. One list for the home page, the facts
     * object, JSON-LD and the /offices page.
     *
     * The phone is the desk's own; else the town's number from the company
     * line (company_counters, the line the documents already print); a Nepal
     * partner office falls back to the Nepal office line (Company details).
     * NOTHING IS INVENTED: an office with no known number has phone ''.
     * Never carries money facts (rate, methods, currency) or notes.
     *
     * @return list<array{code:string,name:string,nameNe:string,nameHi:string,label:string,city:string,
     *                    kind:string,status:string,country:string,phone:string,tel:string,wa:string,
     *                    address:string,addressNe:string,hours:string,mapUrl:string,lat:?float,lng:?float,contact:string}>
     */
    public static function publicOffices(): array
    {
        if (self::$publicCache !== null) {
            return self::$publicCache;
        }
        $hasFlag = self::hasTable() && self::hasColumn('counter_locations', 'show_public');
        $co      = Settings::company();
        $byTown  = self::countersByTown((string) ($co['counters'] ?? ''));

        $out = [];
        foreach (self::all(true) as $code => $d) {
            $listed = $hasFlag && ($d['show_public'] ?? null) !== null
                ? (int) $d['show_public'] === 1
                : isset(self::DEFAULT_PUBLIC[$code]);
            if (!$listed) {
                continue;
            }
            $kind = (string) ($d['office_kind'] ?? '');
            if (!in_array($kind, self::OFFICE_KINDS, true)) {
                $kind = self::DEFAULT_PUBLIC[$code] ?? ((string) $d['country'] === 'NP' ? 'partner' : 'branch');
            }
            $country = (string) $d['country'] === 'NP' ? 'NP' : 'IN';
            $city    = self::townOf((string) $d['name']);

            $phone   = trim((string) ($d['phone'] ?? ''));
            $contact = trim((string) ($d['contact_person'] ?? ''));
            if ($phone === '') {
                $phone = $byTown[self::townKey($city)] ?? '';
            }
            if ($phone === '' && $kind === 'head') {
                $phone = (string) $co['phone'];
            }
            if ($phone === '' && $country === 'NP') {
                [$phone, $who] = self::nepalOfficeLine();
                if ($contact === '') {
                    $contact = $who;
                }
            }

            $tel = self::dialDigits($phone, $country);
            $wa  = self::dialDigits(trim((string) ($d['whatsapp'] ?? '')), $country);
            if ($wa === '' && $kind === 'head') {
                $wa = (string) $co['whatsapp'];
            }

            $address   = trim((string) ($d['address'] ?? ''));
            $addressNe = trim((string) ($d['address_ne'] ?? ''));
            if ($kind === 'head') {
                $address   = $address !== '' ? $address : (string) $co['address'];
                $addressNe = $addressNe !== '' ? $addressNe : (string) $co['addressNe'];
            } elseif ($country === 'NP' && $address === '') {
                $address = Settings::nepalOfficePlace();
            }
            $lat = isset($d['lat']) && $d['lat'] !== null && $d['lat'] !== '' ? (float) $d['lat'] : null;
            $lng = isset($d['lng']) && $d['lng'] !== null && $d['lng'] !== '' ? (float) $d['lng'] : null;
            $map = trim((string) ($d['map_url'] ?? ''));
            if ($map === '') {
                $map = $lat !== null && $lng !== null
                    ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(sprintf('%.6f,%.6f', $lat, $lng))
                    : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim($city . ' ' . ($address !== '' ? $address : '')) . ' ' . ($country === 'NP' ? 'Nepal' : 'India'));
            }
            $status = (string) ($d['public_status'] ?? '') === 'coming_soon' ? 'coming_soon' : 'open';

            $out[] = [
                'code'      => $code,
                'name'      => $city,
                'nameNe'    => trim((string) ($d['name_ne'] ?? '')),
                'nameHi'    => trim((string) ($d['name_hi'] ?? '')),
                'label'     => (string) $d['name'],
                'city'      => $city,
                'kind'      => $kind,
                'status'    => $status,
                'country'   => $country,
                'phone'     => $status === 'open' ? $phone : '',
                'tel'       => $status === 'open' ? $tel : '',
                'wa'        => $status === 'open' ? $wa : '',
                'address'   => $address,
                'addressNe' => $addressNe,
                'hours'     => trim((string) ($d['hours'] ?? '')),
                'mapUrl'    => $map,
                'lat'       => $lat,
                'lng'       => $lng,
                'contact'   => $contact,
                'sort'      => (int) $d['sort_order'],
            ];
        }
        usort($out, static fn(array $a, array $b): int =>
            [$a['kind'] === 'head' ? 0 : 1, $a['status'] === 'open' ? 0 : 1, $a['sort'], $a['code']]
            <=> [$b['kind'] === 'head' ? 0 : 1, $b['status'] === 'open' ? 0 : 1, $b['sort'], $b['code']]);
        foreach ($out as &$o) {
            unset($o['sort']);
        }
        unset($o);

        return self::$publicCache = $out;
    }

    /** "Mehsana — Head Office" / "Rajkot - Office +91 …" / "S Hari Parking, Nana Chiloda" → the town. */
    public static function townOf(string $name): string
    {
        $t = trim((string) ((preg_split('/\s+[\x{2014}\x{2013}-]\s+/u', trim($name), 2) ?: [$name])[0]));
        if (str_contains($t, ',')) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $t)), 'strlen'));
            $t     = $parts !== [] ? (string) end($parts) : $t;
        }
        $t = trim((string) preg_replace('/\s*\+?\d[\d\s-]{6,}$/', '', $t));   // a number typed into the name
        return $t !== '' ? $t : trim($name);
    }

    /** Lower-case letters only: "Baroda" / "BARODA " → "baroda". */
    private static function townKey(string $town): string
    {
        return (string) preg_replace('/[^a-z]/', '', strtolower($town));
    }

    /**
     * The company line "Mehsana +91 91048 01507 · Ahmedabad +91 …" as
     * townKey => phone (the same parse Settings::helpContacts() makes).
     *
     * @return array<string,string>
     */
    private static function countersByTown(string $line): array
    {
        $out = [];
        foreach (preg_split('/\s*[\x{00B7}|;\n]\s*/u', $line) ?: [] as $part) {
            if (preg_match('/^(.*?)\s*(\+?\d[\d \-]{7,})$/u', trim($part), $m) === 1) {
                $k = self::townKey(self::townOf($m[1]));
                if ($k !== '' && !isset($out[$k])) {
                    $out[$k] = trim($m[2]);
                }
            }
        }
        return $out;
    }

    /**
     * The Nepal office's line when the NPJ desk has no phone of its own:
     * the number typed for the Nepalgunj office on Company details, else the
     * Nepal company phone, else the Nepal team's published office line.
     *
     * @return array{0:string,1:string} [phone, contact person]
     */
    private static function nepalOfficeLine(): array
    {
        $typed = trim(Settings::getString('np_office_phone', ''));
        if ($typed !== '') {
            return [$typed, trim(Settings::getString('np_office_name', ''))];
        }
        $np = trim(Settings::getString('nepal_phone', ''));
        if ($np !== '') {
            return [$np, ''];
        }
        foreach (Settings::nepalTeam() as $p) {
            if (($p['key'] ?? '') === 'office') {
                return [(string) $p['phone'], (string) $p['name']];
            }
        }
        return ['', ''];
    }

    /** A number as tel: / wa.me want it — country code, digits only — or ''. */
    public static function dialDigits(string $phone, string $country = 'IN'): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 10) {
            $d = ($country === 'NP' ? '977' : '91') . $d;
        }
        return preg_match('/^[1-9]\d{9,14}$/', $d) === 1 ? $d : '';
    }

    /** A latitude / longitude within ±$max, or null. */
    private static function cleanCoord(mixed $v, float $max): ?float
    {
        if ($v === null || trim((string) $v) === '' || !is_numeric(trim((string) $v))) {
            return null;
        }
        $f = (float) trim((string) $v);
        return abs($f) <= $max && $f !== 0.0 ? round($f, 7) : null;
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
        /* stype is an ENUM('string','int','float','bool','json') — 'text'
           is not a member and MySQL truncates it (the 24 Sep migration
           wrote 'text' and the row landed with no type at all). */
        if ($lines === []) {
            return;   // never blank the mirror — an empty list would hide every desk
        }
        Settings::set('counter_locations', implode("\n", $lines), 'string', 'company');
    }

    /* ----------------------------------------------------------------- */

    /** "cash,upi" from a posted list; null (= all) when nothing is ticked. */
    private static function cleanMethods(mixed $in): ?string
    {
        $list = is_array($in) ? $in : (is_string($in) ? explode(',', $in) : []);
        $out  = [];
        foreach ($list as $m) {
            $m = strtolower(trim((string) $m));
            if (in_array($m, self::METHODS, true)) {
                $out[$m] = true;
            }
        }
        if ($out === [] || count($out) === count(self::METHODS)) {
            return null;   // all of them is the same as no rule
        }

        return implode(',', array_keys($out));
    }

    /** An http(s) link or nothing - a desk card never links to a script URL. */
    private static function cleanUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return mb_substr($url, 0, 255);
    }

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
                'allowed_methods' => (string) ($r['allowed_methods'] ?? ''),
                'default_direction' => (string) ($r['default_direction'] ?? ''),
                'default_from'      => (string) ($r['default_from'] ?? ''),
                'entity'     => strtoupper((string) ($r['entity'] ?? 'IN')) === 'NP' ? 'NP' : 'IN',
                'lang'       => (string) ($r['lang'] ?? ''),
                'map_url'    => (string) ($r['map_url'] ?? ''),
                'hours'      => (string) ($r['hours'] ?? ''),
                // website-only facts (9 Oct 2026); show_public null = column not there yet
                'show_public'    => array_key_exists('show_public', $r) ? (int) $r['show_public'] : null,
                'office_kind'    => (string) ($r['office_kind'] ?? ''),
                'public_status'  => (string) ($r['public_status'] ?? ''),
                'name_ne'        => (string) ($r['name_ne'] ?? ''),
                'name_hi'        => (string) ($r['name_hi'] ?? ''),
                'address_ne'     => (string) ($r['address_ne'] ?? ''),
                'lat'            => isset($r['lat']) && $r['lat'] !== null ? (float) $r['lat'] : null,
                'lng'            => isset($r['lng']) && $r['lng'] !== null ? (float) $r['lng'] : null,
                'whatsapp'       => (string) ($r['whatsapp'] ?? ''),
                'contact_person' => (string) ($r['contact_person'] ?? ''),
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
        foreach (Settings::counterLocationsRaw() as $code => $name) {
            $np = (bool) preg_match('/nepal|nepalgunj|kohalpur|dhamboji|banke/i', $code . ' ' . $name)
                  && !preg_match('/rupaid/i', $code . ' ' . $name);
            // The pre-table SIDE rule (settings counter_nepal_desks) - Rupaidiha
            // sells the return run though it stands in India.
            $back = in_array($code, Settings::nepalDeskCodes(), true);
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
                'allowed_methods' => '',
                'default_direction' => ($np || $back) ? 'toIndia' : '',
                'default_from'      => '',
                'entity'     => $np ? 'NP' : 'IN',
                'lang'       => '',
                'map_url'    => '',
                'hours'      => '',
                'show_public'    => null,
                'office_kind'    => '',
                'public_status'  => '',
                'name_ne'        => '',
                'name_hi'        => '',
                'address_ne'     => '',
                'lat'            => null,
                'lng'            => null,
                'whatsapp'       => '',
                'contact_person' => '',
            ];
        }

        return $out;
    }
}
