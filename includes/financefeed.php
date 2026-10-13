<?php
/**
 * =====================================================================
 *  includes/financefeed.php — the read-only website feed that SHG Finance
 *  Master (admin/finance.php) pulls to build its books (Oct 2026).
 *
 *  The finance app keeps its double-entry books in the CEO's browser. To
 *  post the website's money it needs the register: bookings with their
 *  latest payment, the agent ledger, paper tickets, deposits, loans, closed
 *  counter shifts and the Accounting day-book. This class answers those
 *  questions as plain arrays so the endpoint (admin/api/finance-feed.php)
 *  only parses parameters, and tests/finance-feed-test.php can call it
 *  without HTTP.
 *
 *  Rules this file keeps (SPEC §2):
 *    - READ ONLY. Every statement is a SELECT (or SHOW COLUMNS), every value
 *      is a bound parameter; the only interpolations are a clamped integer
 *      LIMIT and column expressions chosen from a fixed list.
 *    - NO PERSONAL DATA. Customer contact details, identity documents,
 *      passenger rows, payer details, network fingerprints, audit actor
 *      names and old/new values, paper-ticket passengers, staff contact
 *      details and the settings table outside a whitelist never leave.
 *      agent_ledger.note is only sent for booking-linked rows (a paper-ticket
 *      note embeds the passenger's name).
 *    - Money is a decimal STRING ("1234.50"), signed where the column is.
 *    - Timestamps are 'YYYY-MM-DD HH:MM:SS' exactly as stored. DATETIME
 *      columns are written by PHP in Asia/Kolkata; TIMESTAMP columns follow
 *      the DB session zone, which every envelope reports (dbTimeZone,
 *      dbUtcOffset) instead of converting.
 *    - Columns that arrived with later migrations are probed once per
 *      request (SHOW COLUMNS) so an older install degrades, never fatals.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class FinanceFeed
{
    public const VERSION = 1;
    public const TZ      = 'Asia/Kolkata';

    /** part => [default limit, max limit]; 0/0 = not paged. */
    public const PARTS = [
        'meta'        => [0, 0],
        'agents'      => [0, 0],
        'schedules'   => [2000, 2000],
        'bookings'    => [500, 1000],
        'booking_ids' => [20000, 20000],
        'ledger'      => [5000, 5000],
        'paper'       => [5000, 5000],
        'deposits'    => [5000, 5000],
        'loans'       => [5000, 5000],
        'shifts'      => [1000, 2000],
        'daybook'     => [0, 0],
    ];

    /** Earliest watermark a first sync starts from. */
    public const EPOCH = '2000-01-01 00:00:00';

    /** Longest day-book window (days between from and to). */
    public const DAYBOOK_MAX_DAYS = 400;

    /** Destinations that make a route an "up" run (towards Rupaidiha / Nepal). */
    public const UP_PATTERN = '/rupaidiha|nepal|nepalgunj|kathmandu|pokhara/i';

    /** The moneyState vocabulary (SPEC §2, research moneyRules). */
    public const MONEY_STATES = [
        'collected', 'cod_due', 'proof_awaiting_verification', 'awaiting_payment',
        'cancelled_after_collection', 'cancelled_uncollected', 'rejected', 'expired', 'other',
    ];

    /** agent_ledger kinds the feed reports. */
    public const LEDGER_KINDS = [
        'commission', 'commission_void', 'payout', 'cash_due', 'cash_handover', 'salary', 'loan',
        'advance', 'paper_ticket_commission', 'paper_ticket_cash', 'adjustment',
    ];

    /** @var array<string, array<string, true>> table => lower-case column set ([] = no table) */
    private static array $columns = [];

    /** @var array{tz:string, sys:string, offset:string}|null */
    private static ?array $dbZone = null;

    /** @var array<string, mixed>|null decoded agent_* settings maps for this request */
    private static ?array $maps = null;

    private function __construct() {}

    /* =================================================================
     *  Entry point
     * ================================================================= */

    /**
     * Validate the query string for one part. Unknown parameters are ignored
     * (cache busters); a malformed one is refused rather than guessed.
     *
     * @param array<string, mixed> $q usually $_GET
     * @return array<string, mixed> normalised parameters, always with 'part'
     * @throws InvalidArgumentException with a message fit for a 422 reply
     */
    public static function params(array $q): array
    {
        $part = $q['part'] ?? '';
        if (!is_string($part) || $part === '') {
            throw new InvalidArgumentException('part is required (one of: ' . implode(', ', array_keys(self::PARTS)) . ').');
        }
        if (!array_key_exists($part, self::PARTS)) {
            throw new InvalidArgumentException('Unknown part.');
        }

        $out = ['part' => $part];
        [$defLimit, $maxLimit] = self::PARTS[$part];
        if ($maxLimit > 0) {
            $out['afterId'] = self::intParam($q, 'afterId', 0);
            $limit          = self::intParam($q, 'limit', $defLimit);
            $out['limit']   = max(1, min($maxLimit, $limit));
        }

        $today = self::now()->format('Y-m-d');

        switch ($part) {
            case 'bookings':
            case 'shifts':
                $out['since'] = self::sinceParam($q, 'since', self::EPOCH);
                break;

            case 'schedules':
                $out['from'] = self::dateParam($q, 'from', self::shiftDays($today, -400));
                $out['to']   = self::dateParam($q, 'to', self::shiftDays($today, 60));
                if ($out['from'] > $out['to']) {
                    throw new InvalidArgumentException('from must be on or before to.');
                }
                break;

            case 'daybook':
                $out['from'] = self::dateParam($q, 'from', $today);
                $out['to']   = self::dateParam($q, 'to', $today);
                if ($out['from'] > $out['to']) {
                    throw new InvalidArgumentException('from must be on or before to.');
                }
                $span = (int) (new DateTimeImmutable($out['from']))->diff(new DateTimeImmutable($out['to']))->days;
                if ($span > self::DAYBOOK_MAX_DAYS) {
                    throw new InvalidArgumentException('The day-book window is at most ' . self::DAYBOOK_MAX_DAYS . ' days.');
                }
                break;
        }

        return $out;
    }

    /**
     * Answer one part inside the common envelope.
     *
     * @param array<string, mixed> $p     output of params()
     * @param array<string, mixed> $admin the signed-in staff member (Auth::admin())
     * @return array<string, mixed>
     */
    public static function run(array $p, array $admin = []): array
    {
        $part = (string) ($p['part'] ?? '');
        $res  = match ($part) {
            'meta'        => self::meta($admin),
            'agents'      => ['rows' => self::agents(), 'next' => null],
            'schedules'   => self::schedules((string) $p['from'], (string) $p['to'], (int) $p['afterId'], (int) $p['limit']),
            'bookings'    => self::bookings((string) $p['since'], (int) $p['afterId'], (int) $p['limit']),
            'booking_ids' => self::bookingIds((int) $p['afterId'], (int) $p['limit']),
            'ledger'      => self::ledger((int) $p['afterId'], (int) $p['limit']),
            'paper'       => self::paper((int) $p['afterId'], (int) $p['limit']),
            'deposits'    => self::deposits((int) $p['afterId'], (int) $p['limit']),
            'loans'       => self::loans((int) $p['afterId'], (int) $p['limit']),
            'shifts'      => self::shifts((string) $p['since'], (int) $p['afterId'], (int) $p['limit']),
            'daybook'     => self::daybook((string) $p['from'], (string) $p['to']),
            default       => throw new InvalidArgumentException('Unknown part.'),
        };
        return self::envelope($part, $res);
    }

    /**
     * {"ok":true,"part","feedVersion","generatedAt","tz","dbTimeZone",…extras,"rows","next","count"}
     *
     * @param array<string, mixed> $res rows + next + any part-specific extras
     * @return array<string, mixed>
     */
    public static function envelope(string $part, array $res): array
    {
        $zone = self::dbZone();
        $rows = is_array($res['rows'] ?? null) ? array_values($res['rows']) : [];
        $next = $res['next'] ?? null;
        unset($res['rows'], $res['next']);

        return [
            'ok'               => true,
            'part'             => $part,
            'feedVersion'      => self::VERSION,
            'generatedAt'      => self::now()->format('Y-m-d H:i:s'),
            'tz'               => self::TZ,
            'dbTimeZone'       => $zone['tz'],
            'dbSystemTimeZone' => $zone['sys'],
            'dbUtcOffset'      => $zone['offset'],
        ] + $res + [
            'rows'  => $rows,
            'next'  => $next,
            'count' => count($rows),
        ];
    }

    /* =================================================================
     *  meta — who we are, the pegs, the commission scheme, table sizes
     * ================================================================= */

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public static function meta(array $admin = []): array
    {
        $c = Settings::company();
        $s = static fn(string $key): string => trim(Settings::getString($key, ''));

        $company = [
            'name'        => (string) ($c['name'] ?? ''),
            'legal'       => (string) ($c['legal'] ?? ''),
            'legalNe'     => (string) ($c['legalNe'] ?? ''),
            'address'     => (string) ($c['address'] ?? ''),
            'addressNe'   => (string) ($c['addressNe'] ?? ''),
            'phone'       => (string) ($c['phone'] ?? ''),
            'email'       => (string) ($c['email'] ?? ''),
            'web'         => (string) ($c['web'] ?? ''),
            'cin'         => (string) ($c['cin'] ?? ''),
            'gstin'       => $s('company_gstin'),
            'ceo'         => $s('company_ceo'),
            'tagline'     => $s('company_tagline'),
            'mantra'      => $s('company_mantra'),
            'operator'    => (string) ($c['operator'] ?? ''),
            'operatorNe'  => (string) ($c['operatorNe'] ?? ''),
            'counters'    => (string) ($c['counters'] ?? ''),
            'whatsapp'    => (string) ($c['whatsapp'] ?? ''),
            'nepalPhone'  => $s('nepal_phone'),
            'nepalOffice' => $s('nepal_office'),
            'nepalCompany'=> $s('nepal_company'),
            'nepalReg'    => $s('nepal_reg'),
            'upiId'       => $s('upi_id'),
            'upiName'     => $s('upi_name'),
            'esewaId'     => $s('esewa_id'),
            'esewaName'   => $s('esewa_name'),
        ];
        /* A settings row applied through a latin1 client reads back as
           "à¤à¤¸ à¤¹à¤°à¤¿…" instead of "एस हरि…" — the letterhead would print
           that. Undo exactly that one accident; anything else is untouched. */
        $company = array_map([self::class, 'fixMojibake'], $company);

        $counts = [];
        foreach ([
            'bookings'  => 'bookings',
            'ledger'    => 'agent_ledger',
            'paper'     => 'offline_tickets',
            'deposits'  => 'agent_deposit_txns',
            'loans'     => 'agent_loans',
            'schedules' => 'schedules',
        ] as $key => $table) {
            $counts[$key] = self::hasTable($table)
                ? (int) Database::scalar('SELECT COUNT(*) FROM `' . $table . '`', [], 0)
                : 0;
        }

        return [
            'company'       => $company,
            'logo'          => ['url' => self::logoUrl()],
            'peg'           => [
                'constant' => defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6,
                'setting'  => Settings::getFloat('npr_per_inr', 1.6),
            ],
            'user'          => [
                'id'   => (int) ($admin['id'] ?? 0),
                'name' => (string) ($admin['full_name'] ?? ($admin['username'] ?? '')),
                'role' => (string) ($admin['role'] ?? ''),
            ],
            'counts'        => $counts,
            'serverDate'    => self::now()->format('Y-m-d'),
            'walletEnabled' => Settings::getBool('agent_wallet_enabled', true),
            'commission'    => [
                'mode'       => Settings::getString('agent_commission_mode', 'flat_per_seat') === 'percent' ? 'percent' : 'flat_per_seat',
                'flatDirect' => self::money(Settings::getFloat('agent_flat_direct', 200.0)),
                'flatJoint'  => self::money(Settings::getFloat('agent_flat_joint', 400.0)),
                'percent'    => self::money(Settings::getFloat('agent_commission_percent', 5.0)),
            ],
            'rows'          => [],
            'next'          => null,
        ];
    }

    /**
     * Root-relative logo: the admin-set company_logo when it is a usable
     * local PNG/JPEG/WebP, else the site logo. Never an absolute URL (the
     * panel is served on more than one host).
     */
    public static function logoUrl(): string
    {
        $cand = trim(Settings::getString('company_logo', ''));
        if ($cand !== ''
            && preg_match('#^/?[A-Za-z0-9_\-][A-Za-z0-9_\-./]*\.(png|jpe?g|webp)$#i', $cand) === 1
            && !str_contains($cand, '..')
            && !str_contains($cand, '//')) {
            $rel  = ltrim($cand, '/');
            $path = ROOT_PATH . '/' . $rel;
            if (is_file($path) && filesize($path) > 0) {
                return '/' . $rel;
            }
        }
        return '/assets/img/logo.png';
    }

    /* =================================================================
     *  agents — every seller (full snapshot)
     * ================================================================= */

    /** @return array<int, array<string, mixed>> */
    public static function agents(): array
    {
        $hasProfiles = self::hasTable('admin_profiles');
        $kindCol     = $hasProfiles && self::hasColumn('admin_profiles', 'agent_kind') ? 'ap.agent_kind' : 'NULL';
        $pctCol      = $hasProfiles && self::hasColumn('admin_profiles', 'commission_percent') ? 'ap.commission_percent' : 'NULL';
        $join        = $hasProfiles ? 'LEFT JOIN admin_profiles ap ON ap.admin_id = a.id' : '';

        $or = [];
        foreach (self::sellerSources() as $sql) {
            $or[] = 'a.id IN (' . $sql . ')';
        }

        $rows = Database::fetchAll(
            "SELECT a.id, a.username, a.full_name, a.role, a.is_active,
                    {$kindCol} AS agent_kind, {$pctCol} AS commission_percent
               FROM admins a
               {$join}
              WHERE a.role IN ('agent', 'counter')"
                . ($or !== [] ? ' OR ' . implode(' OR ', $or) : '') . '
              ORDER BY a.id'
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = self::agentRow((int) $r['id'], [
                'name'     => (string) $r['full_name'],
                'username' => (string) $r['username'],
                'role'     => (string) $r['role'],
                'active'   => (int) $r['is_active'] === 1,
                'kind'     => $r['agent_kind'],
                'percent'  => $r['commission_percent'],
            ]);
        }

        /* A ledger / paper / deposit / loan row can outlive its staff account
           (no foreign key there). The books still need a party for it. */
        if ($or !== []) {
            $union = implode(' UNION ', self::sellerSources());
            foreach (Database::fetchAll(
                "SELECT x.id FROM ({$union}) x
                   LEFT JOIN admins a ON a.id = x.id
                  WHERE a.id IS NULL AND x.id IS NOT NULL
                  ORDER BY x.id"
            ) as $r) {
                $id    = (int) $r['id'];
                $out[] = self::agentRow($id, [
                    'name'     => 'Staff #' . $id . ' (deleted)',
                    'username' => '',
                    'role'     => 'deleted',
                    'active'   => false,
                    'kind'     => null,
                    'percent'  => null,
                ]);
            }
        }

        return $out;
    }

    /**
     * Sub-selects naming every admins.id that ever sold or carries money.
     *
     * @return array<int, string>
     */
    private static function sellerSources(): array
    {
        $src = [];
        if (self::hasColumn('bookings', 'sold_by_admin_id')) {
            $src[] = 'SELECT DISTINCT sold_by_admin_id AS id FROM bookings WHERE sold_by_admin_id IS NOT NULL';
        }
        foreach (['agent_ledger', 'offline_tickets', 'agent_deposit_txns', 'agent_loans'] as $t) {
            if (self::hasColumn($t, 'agent_admin_id')) {
                $src[] = 'SELECT DISTINCT agent_admin_id AS id FROM ' . $t;
            }
        }
        return $src;
    }

    /**
     * @param array{name:string, username:string, role:string, active:bool, kind:mixed, percent:mixed} $a
     * @return array<string, mixed>
     */
    private static function agentRow(int $id, array $a): array
    {
        $m        = self::maps();
        $key      = (string) $id;
        $override = $m['overrides'][$key] ?? null;
        $deposit  = $m['deposits'][$key] ?? ['required' => 0.0, 'paid' => 0.0];
        $salary   = $m['salaries'][$key] ?? null;

        return [
            'sellerId'        => $id,
            'code'            => self::codeLabel($id),
            'name'            => $a['name'],
            'username'        => $a['username'],
            'role'            => $a['role'],
            'active'          => $a['active'],
            'kind'            => strtolower(trim((string) ($a['kind'] ?? ''))) === 'person' ? 'person' : 'org',
            'tier'            => ($m['types'][$key] ?? '') === 'joint' ? 'joint' : 'direct',
            'overrideMode'    => $override['mode'] ?? null,
            'overrideFlat'    => isset($override['flat']) ? self::money($override['flat']) : null,
            'overridePercent' => ($a['percent'] !== null && $a['percent'] !== '') ? self::money($a['percent']) : null,
            'salary'          => $salary !== null ? self::money($salary) : null,
            'depositRequired' => self::money($deposit['required']),
            'depositPaid'     => self::money($deposit['paid']),
        ];
    }

    /** "SHG-0007", or null when the office has not issued a number. */
    public static function codeLabel(int $adminId): ?string
    {
        $n = self::maps()['codes'][(string) $adminId] ?? null;
        return $n === null ? null : 'SHG-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * The agent_* settings maps, decoded tolerantly (an old install may hold
     * a double-encoded JSON row, or '[]' for an empty map) and normalised
     * with the same rules AgentWallet applies.
     *
     * @return array{codes: array<string,int>, types: array<string,string>, overrides: array<string, array{mode:string, flat:?float}>, salaries: array<string,float>, deposits: array<string, array{required:float, paid:float}>}
     */
    private static function maps(): array
    {
        if (self::$maps !== null) {
            return self::$maps;
        }

        $codes = [];
        foreach (self::decodeMap(Settings::get('agent_codes', [])) as $k => $v) {
            $n = is_numeric($v) ? (int) $v : 0;
            if ($n >= 1 && $n <= 1000 && is_numeric($k)) {
                $codes[(string) (int) $k] = $n;
            }
        }

        $types = [];
        foreach (self::decodeMap(Settings::get('agent_types', [])) as $k => $v) {
            if (is_numeric($k) && is_scalar($v) && strtolower(trim((string) $v)) === 'joint') {
                $types[(string) (int) $k] = 'joint';
            }
        }

        $overrides = [];
        foreach (self::decodeMap(Settings::get('agent_commission_overrides', [])) as $k => $v) {
            if (!is_numeric($k) || !is_array($v)) { continue; }
            $mode = strtolower(trim((string) ($v['mode'] ?? '')));
            if (!in_array($mode, ['flat', 'percent'], true)) { continue; }
            $flat = isset($v['flat']) && is_numeric($v['flat']) ? round((float) $v['flat'], 2) : null;
            if ($flat !== null && ($flat < 0 || $flat > 10000)) { $flat = null; }
            $overrides[(string) (int) $k] = ['mode' => $mode, 'flat' => $flat];
        }

        $salaries = [];
        foreach (self::decodeMap(Settings::get('agent_salaries', [])) as $k => $v) {
            if (is_numeric($k) && is_numeric($v) && (float) $v > 0) {
                $salaries[(string) (int) $k] = round((float) $v, 2);
            }
        }

        $deposits = [];
        foreach (self::decodeMap(Settings::get('agent_deposits', [])) as $k => $v) {
            if (!is_numeric($k) || !is_array($v)) { continue; }
            $deposits[(string) (int) $k] = [
                'required' => round(max(0.0, (float) (is_numeric($v['required'] ?? null) ? $v['required'] : 0)), 2),
                'paid'     => round(max(0.0, (float) (is_numeric($v['paid'] ?? null) ? $v['paid'] : 0)), 2),
            ];
        }

        return self::$maps = compact('codes', 'types', 'overrides', 'salaries', 'deposits');
    }

    /**
     * A settings JSON map as an array: decoded arrays pass, a string that is
     * itself (possibly several layers of) JSON is peeled, anything else is [].
     *
     * @return array<mixed>
     */
    public static function decodeMap(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        try {
            $v = Settings::decodeJsonLayers($raw);
        } catch (Throwable $e) {
            return [];
        }
        return is_array($v) ? $v : [];
    }

    /* =================================================================
     *  schedules — departures the books may tag a sale to
     * ================================================================= */

    /** @return array{rows: array<int, array<string, mixed>>, next: ?array<string, int>} */
    public static function schedules(string $from, string $to, int $afterId, int $limit): array
    {
        $limit  = self::clampLimit('schedules', $limit);
        $today  = self::now()->format('Y-m-d');
        $slot   = self::hasColumn('schedules', 'slot') ? 's.slot' : '1';
        $dep    = self::hasColumn('schedules', 'dep_time_override') ? 'COALESCE(s.dep_time_override, r.dep_time)' : 'r.dep_time';
        $sBus   = self::hasColumn('schedules', 'bus_id') ? 's.bus_id' : 'NULL';
        $rBus   = self::hasColumn('routes', 'bus_id') ? 'r.bus_id' : 'NULL';
        $bus    = "COALESCE({$sBus}, {$rBus})";

        $rows = Database::fetchAll(
            "SELECT s.id, s.travel_date, {$slot} AS slot, {$dep} AS dep_time, s.status, s.total_seats,
                    r.id AS route_id, r.route_code, r.from_city, r.to_city,
                    {$bus} AS bus_id, bu.bus_number, bu.bus_name,
                    (SELECT COALESCE(SUM(l.seat_count), 0) FROM booking_legs l JOIN bookings b ON b.id = l.booking_id
                      WHERE l.schedule_id = s.id AND b.status IN ('confirmed', 'completed')) AS live_seats,
                    (SELECT COUNT(DISTINCT l.booking_id) FROM booking_legs l JOIN bookings b ON b.id = l.booking_id
                      WHERE l.schedule_id = s.id AND b.status IN ('confirmed', 'completed')) AS live_bookings
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses bu ON bu.id = {$bus}
              WHERE s.id > :a
                AND s.travel_date >= :f AND s.travel_date <= :t
                AND ((s.travel_date >= :n1 AND s.travel_date <= :n2)
                     OR EXISTS (SELECT 1 FROM booking_legs l2 WHERE l2.schedule_id = s.id))
              ORDER BY s.id
              LIMIT " . $limit,
            [
                'a'  => $afterId, 'f' => $from, 't' => $to,
                'n1' => self::shiftDays($today, -7), 'n2' => self::shiftDays($today, 7),
            ]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'scheduleId'   => (int) $r['id'],
                'travelDate'   => (string) $r['travel_date'],
                'slot'         => (int) $r['slot'],
                'depTime'      => self::str($r['dep_time']),
                'status'       => (string) $r['status'],
                'routeId'      => (int) $r['route_id'],
                'routeCode'    => (string) $r['route_code'],
                'fromCity'     => (string) $r['from_city'],
                'toCity'       => (string) $r['to_city'],
                'direction'    => self::direction((string) $r['to_city']),
                'busId'        => self::intOrNull($r['bus_id']),
                'busNumber'    => self::str($r['bus_number']),
                'busName'      => self::str($r['bus_name']),
                'seats'        => (int) $r['total_seats'],
                'liveSeats'    => (int) $r['live_seats'],
                'liveBookings' => (int) $r['live_bookings'],
            ];
        }

        return ['rows' => $out, 'next' => self::nextAfterId($out, 'scheduleId', $limit)];
    }

    /** 'up' = towards Rupaidiha / Nepal, else 'down'. */
    public static function direction(string $toCity): string
    {
        return preg_match(self::UP_PATTERN, $toCity) === 1 ? 'up' : 'down';
    }

    /* =================================================================
     *  bookings — keyset by (wm, id)
     * ================================================================= */

    /**
     * wm = GREATEST(b.updated_at, COALESCE(latest payment updated_at, b.updated_at)).
     * Page: (wm > since) OR (wm = since AND id > afterId), ordered by wm, id.
     * Two steps: the keys for one page first (light), then the detail for
     * exactly those ids — so the per-booking sub-selects never run over the
     * whole register.
     *
     * @return array{rows: array<int, array<string, mixed>>, next: ?array{since:string, afterId:int}}
     */
    public static function bookings(string $since, int $afterId, int $limit): array
    {
        $limit = self::clampLimit('bookings', $limit);

        $keys = Database::fetchAll(
            "SELECT k.id, k.wm
               FROM (SELECT b.id,
                            GREATEST(b.updated_at, COALESCE(lp.updated_at, b.updated_at)) AS wm
                       FROM bookings b
                       LEFT JOIN payments lp
                              ON lp.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
                      WHERE b.updated_at >= :s0 OR lp.updated_at >= :s1) k
              WHERE k.wm > :s2 OR (k.wm = :s3 AND k.id > :a)
              ORDER BY k.wm, k.id
              LIMIT " . $limit,
            ['s0' => $since, 's1' => $since, 's2' => $since, 's3' => $since, 'a' => $afterId]
        );
        if ($keys === []) {
            return ['rows' => [], 'next' => null];
        }

        $detail = [];
        foreach (self::bookingDetail(array_map(static fn(array $k): int => (int) $k['id'], $keys)) as $row) {
            $detail[$row['bookingId']] = $row;
        }

        $out = [];
        foreach ($keys as $k) {
            $id = (int) $k['id'];
            if (!isset($detail[$id])) {
                continue;   // hard-deleted between the two reads; booking_ids will show it
            }
            $row       = $detail[$id];
            $row['wm'] = (string) $k['wm'];   // the key the page was cut on
            $out[]     = $row;
        }

        $next = null;
        if (count($keys) >= $limit) {
            $last = $keys[count($keys) - 1];
            $next = ['since' => (string) $last['wm'], 'afterId' => (int) $last['id']];
        }

        return ['rows' => $out, 'next' => $next];
    }

    /**
     * One feed row per booking id (order not guaranteed).
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    public static function bookingDetail(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $ph = [];
        $pp = [];
        foreach ($ids as $i => $id) {
            $ph[]         = ':b' . $i;
            $pp['b' . $i] = $id;
        }

        $discount = [];
        foreach (['group_discount', 'tier_discount', 'coupon_discount', 'points_value'] as $col) {
            if (self::hasColumn('bookings', $col)) {
                $discount[] = 'b.' . $col;
            }
        }
        $discountSql = $discount === [] ? '0' : implode(' + ', $discount);
        $seller      = self::hasColumn('bookings', 'sold_by_admin_id') ? 'b.sold_by_admin_id' : 'NULL';
        $proof       = self::hasTable('payment_screenshots')
            ? " OR EXISTS (SELECT 1 FROM payment_screenshots ps2 WHERE ps2.booking_id = b.id)"
            : '';
        $approved    = self::hasTable('audit_logs')
            ? "(SELECT MAX(al.created_at) FROM audit_logs al
                 WHERE al.entity_type = 'booking' AND al.entity_id = b.pnr AND al.action = 'refund.approve')"
            : 'NULL';
        $ticket      = self::hasTable('tickets')
            ? '(SELECT t.ticket_number FROM tickets t WHERE t.booking_id = b.id ORDER BY t.id LIMIT 1)'
            : 'NULL';

        $rows = Database::fetchAll(
            "SELECT b.id, b.pnr, {$ticket} AS ticket_number, b.status, b.is_cod, b.source, b.currency,
                    b.total_amount, ({$discountSql}) AS discount, b.booking_fee, b.tax_amount,
                    b.refund_amount, b.refund_status, b.refund_ref,
                    {$approved} AS refund_approved_at,
                    {$seller} AS seller_id, b.referral_code, b.created_at, b.confirmed_at, b.cancelled_at,
                    lp.id AS payment_id, lp.payment_ref, lp.method AS payment_method, lp.mode AS payment_mode,
                    lp.amount AS payment_amount, lp.status AS payment_status, lp.utr_number,
                    lp.verified_by, lp.verified_at,
                    (SELECT COUNT(*) FROM payments p3 WHERE p3.booking_id = b.id) AS payment_rows,
                    ol.schedule_id, ol.travel_date, ol.seat_count AS leg_seats,
                    (SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id) AS pax_count,
                    r.route_code,
                    CASE
                      WHEN b.status IN ('confirmed', 'completed') AND lp.status = 'verified'    THEN 'collected'
                      WHEN b.status IN ('confirmed', 'completed') AND lp.status = 'cod_pending' THEN 'cod_due'
                      WHEN b.status = 'pending' AND (TRIM(COALESCE(lp.utr_number, '')) <> ''{$proof}) THEN 'proof_awaiting_verification'
                      WHEN b.status = 'pending'                                THEN 'awaiting_payment'
                      WHEN b.status = 'cancelled' AND lp.status = 'verified'   THEN 'cancelled_after_collection'
                      WHEN b.status = 'cancelled'                              THEN 'cancelled_uncollected'
                      WHEN b.status = 'rejected'                               THEN 'rejected'
                      WHEN b.status = 'expired'                                THEN 'expired'
                      ELSE 'other'
                    END AS money_state,
                    GREATEST(b.updated_at, COALESCE(lp.updated_at, b.updated_at)) AS wm
               FROM bookings b
               LEFT JOIN payments lp
                      ON lp.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
               LEFT JOIN booking_legs ol
                      ON ol.id = (SELECT MIN(l2.id) FROM booking_legs l2
                                   WHERE l2.booking_id = b.id AND l2.leg_type = 'outbound')
               LEFT JOIN schedules s ON s.id = ol.schedule_id
               LEFT JOIN routes r    ON r.id = s.route_id
              WHERE b.id IN (" . implode(',', $ph) . ')',
            $pp
        );

        $out = [];
        foreach ($rows as $r) {
            $legSeats = $r['leg_seats'] === null ? 0 : (int) $r['leg_seats'];
            $out[] = [
                'bookingId'        => (int) $r['id'],
                'pnr'              => (string) $r['pnr'],
                'ticketNo'         => self::str($r['ticket_number']),
                'status'           => (string) $r['status'],
                'isCod'            => (int) $r['is_cod'] === 1,
                'source'           => (string) $r['source'],
                'currency'         => (string) $r['currency'],
                'totalAmount'      => self::money0($r['total_amount']),
                'discount'         => self::money0($r['discount']),
                'bookingFee'       => self::money0($r['booking_fee']),
                'taxAmount'        => self::money0($r['tax_amount']),
                'refundAmount'     => self::money0($r['refund_amount']),
                'refundStatus'     => (string) $r['refund_status'],
                'refundRef'        => self::scrubRef($r['refund_ref']),
                'refundApprovedAt' => self::str($r['refund_approved_at']),
                'sellerId'         => self::intOrNull($r['seller_id']),
                'referralCode'     => self::str($r['referral_code']),
                'createdAt'        => self::str($r['created_at']),
                'confirmedAt'      => self::str($r['confirmed_at']),
                'cancelledAt'      => self::str($r['cancelled_at']),
                'paymentId'        => self::intOrNull($r['payment_id']),
                'paymentRef'       => self::str($r['payment_ref']),
                'paymentMethod'    => self::str($r['payment_method']),
                'paymentMode'      => self::str($r['payment_mode']),
                'paymentAmount'    => self::money($r['payment_amount']),
                'paymentStatus'    => self::str($r['payment_status']),
                'utr'              => self::scrubRef($r['utr_number']),
                'verifiedBy'       => self::intOrNull($r['verified_by']),
                'verifiedAt'       => self::str($r['verified_at']),
                'paymentRows'      => (int) $r['payment_rows'],
                'scheduleId'       => self::intOrNull($r['schedule_id']),
                'travelDate'       => self::str($r['travel_date']),
                'seats'            => $legSeats > 0 ? $legSeats : (int) $r['pax_count'],
                'routeCode'        => self::str($r['route_code']),
                'moneyState'       => (string) $r['money_state'],
                'wm'               => (string) $r['wm'],
            ];
        }
        return $out;
    }

    /* =================================================================
     *  booking_ids — the whole id set, for deletion detection
     * ================================================================= */

    /** @return array{rows: array<int, array{0:int, 1:string}>, next: ?array{afterId:int}} */
    public static function bookingIds(int $afterId, int $limit): array
    {
        $limit = self::clampLimit('booking_ids', $limit);
        $rows  = Database::fetchAll(
            'SELECT id, pnr FROM bookings WHERE id > :a ORDER BY id LIMIT ' . $limit,
            ['a' => $afterId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [(int) $r['id'], (string) $r['pnr']];
        }
        $next = null;
        if (count($out) >= $limit) {
            $next = ['afterId' => $out[count($out) - 1][0]];
        }
        return ['rows' => $out, 'next' => $next];
    }

    /* =================================================================
     *  ledger — agent_ledger, every row, by id
     * ================================================================= */

    /** @return array{rows: array<int, array<string, mixed>>, next: ?array{afterId:int}} */
    public static function ledger(int $afterId, int $limit): array
    {
        $limit = self::clampLimit('ledger', $limit);
        if (!self::hasTable('agent_ledger')) {
            return ['rows' => [], 'next' => null];
        }

        $rows = Database::fetchAll(
            'SELECT l.id, l.agent_admin_id, l.account, l.entry_type, l.amount, l.booking_id,
                    b.id AS b_id, b.pnr AS b_pnr, b.status AS b_status,
                    l.ref, l.note, l.created_by, l.created_at
               FROM agent_ledger l
               LEFT JOIN bookings b ON b.id = l.booking_id
              WHERE l.id > :a
              ORDER BY l.id
              LIMIT ' . $limit,
            ['a' => $afterId]
        );

        $out = [];
        foreach ($rows as $r) {
            $bookingId = self::intOrNull($r['booking_id']);
            $ref       = self::scrubRef($r['ref']);
            $note      = self::str($r['note']);
            $kind      = self::ledgerKind((string) $r['entry_type'], $ref, $bookingId);
            $loanId    = ($ref !== null && preg_match('/^ADVANCE L(\d+)/i', $ref, $m) === 1) ? (int) $m[1] : null;
            $amount    = self::money0($r['amount']);
            $pnr       = $r['b_pnr'] !== null ? (string) $r['b_pnr'] : ($bookingId !== null ? $ref : null);

            $out[] = [
                'ledgerId'       => (int) $r['id'],
                'sellerId'       => (int) $r['agent_admin_id'],
                'code'           => self::codeLabel((int) $r['agent_admin_id']),
                'account'        => (string) $r['account'],
                'entryType'      => (string) $r['entry_type'],
                'kind'           => $kind,
                'amount'         => $amount,
                'bookingId'      => $bookingId,
                'pnr'            => $pnr,
                'bookingMissing' => $bookingId !== null && $r['b_id'] === null,
                'bookingStatus'  => self::str($r['b_status']),
                'ref'            => $ref,
                'loanId'         => $loanId,
                // A booking-less row (paper ticket, advance, salary…) may carry a
                // passenger's name in its note — only the booking-linked note leaves.
                'note'           => $bookingId !== null ? self::scrubText($note) : null,
                'writeOff'       => in_array($kind, ['loan', 'advance'], true)
                                    && !str_starts_with($amount, '-')
                                    && $note !== null && preg_match('/\bwritten[\s-]*off\b/i', $note) === 1,
                'createdBy'      => self::intOrNull($r['created_by']),
                'createdAt'      => self::str($r['created_at']),
            ];
        }

        return ['rows' => $out, 'next' => self::nextAfterId($out, 'ledgerId', $limit)];
    }

    /**
     * The research CASE (schemaMap agent_ledger_entries), in PHP. LIKE on a
     * *_ci column is case-insensitive, so this is too.
     */
    public static function ledgerKind(string $entryType, ?string $ref, ?int $bookingId): string
    {
        $r = strtoupper((string) $ref);
        if ($entryType === 'adjustment' && str_starts_with($r, 'SALARY ')) {
            return 'salary';
        }
        if ($entryType === 'adjustment' && str_starts_with($r, 'ADVANCE L')) {
            return 'loan';
        }
        if ($entryType === 'adjustment' && str_starts_with($r, 'ADVANCE')) {
            return 'advance';
        }
        if ($entryType === 'commission' && $bookingId === null) {
            return 'paper_ticket_commission';
        }
        if ($entryType === 'cash_due' && $bookingId === null) {
            return 'paper_ticket_cash';
        }
        return in_array($entryType, self::LEDGER_KINDS, true) ? $entryType : 'adjustment';
    }

    /* =================================================================
     *  paper — offline_tickets (never the passenger)
     * ================================================================= */

    /** @return array{rows: array<int, array<string, mixed>>, next: ?array{afterId:int}} */
    public static function paper(int $afterId, int $limit): array
    {
        $limit = self::clampLimit('paper', $limit);
        if (!self::hasTable('offline_tickets')) {
            return ['rows' => [], 'next' => null];
        }

        $rows = Database::fetchAll(
            'SELECT o.id, o.agent_admin_id, o.paper_ticket_no, o.booking_id, b.pnr, r.route_code,
                    o.travel_date, o.pax_count, o.amount, o.commission, o.payment_mode, o.status, o.created_at
               FROM offline_tickets o
               LEFT JOIN bookings b ON b.id = o.booking_id
               LEFT JOIN routes r   ON r.id = o.route_id
              WHERE o.id > :a
              ORDER BY o.id
              LIMIT ' . $limit,
            ['a' => $afterId]
        );

        $out = [];
        foreach ($rows as $r) {
            $bookingId = self::intOrNull($r['booking_id']);
            $out[] = [
                'paperId'       => (int) $r['id'],
                'sellerId'      => (int) $r['agent_admin_id'],
                'paperTicketNo' => (string) $r['paper_ticket_no'],
                'bookingId'     => $bookingId,
                'pnr'           => self::str($r['pnr']),
                'shape'         => $bookingId === null ? 'register_only' : 'seated_booking',
                'routeCode'     => self::str($r['route_code']),
                'travelDate'    => self::str($r['travel_date']),
                'pax'           => (int) $r['pax_count'],
                'amount'        => self::money0($r['amount']),
                'commission'    => self::money0($r['commission']),
                'paymentMode'   => (string) $r['payment_mode'],
                'status'        => (string) $r['status'],
                'createdAt'     => self::str($r['created_at']),
            ];
        }

        return ['rows' => $out, 'next' => self::nextAfterId($out, 'paperId', $limit)];
    }

    /* =================================================================
     *  deposits / loans
     * ================================================================= */

    /** @return array{rows: array<int, array<string, mixed>>, next: ?array{afterId:int}} */
    public static function deposits(int $afterId, int $limit): array
    {
        $limit = self::clampLimit('deposits', $limit);
        if (!self::hasTable('agent_deposit_txns')) {
            return ['rows' => [], 'next' => null];
        }

        $out = [];
        foreach (Database::fetchAll(
            'SELECT id, agent_admin_id, amount, ref, created_at
               FROM agent_deposit_txns
              WHERE id > :a
              ORDER BY id
              LIMIT ' . $limit,
            ['a' => $afterId]
        ) as $r) {
            $out[] = [
                'depositId' => (int) $r['id'],
                'sellerId'  => (int) $r['agent_admin_id'],
                'amount'    => self::money0($r['amount']),
                'ref'       => self::scrubRef($r['ref']),
                'createdAt' => self::str($r['created_at']),
            ];
        }

        return ['rows' => $out, 'next' => self::nextAfterId($out, 'depositId', $limit)];
    }

    /** @return array{rows: array<int, array<string, mixed>>, next: ?array{afterId:int}} */
    public static function loans(int $afterId, int $limit): array
    {
        $limit = self::clampLimit('loans', $limit);
        if (!self::hasTable('agent_loans')) {
            return ['rows' => [], 'next' => null];
        }

        $out = [];
        foreach (Database::fetchAll(
            'SELECT id, agent_admin_id, kind, principal, recovered, status, issued_on, settled_at, created_at
               FROM agent_loans
              WHERE id > :a
              ORDER BY id
              LIMIT ' . $limit,
            ['a' => $afterId]
        ) as $r) {
            $out[] = [
                'loanId'    => (int) $r['id'],
                'sellerId'  => (int) $r['agent_admin_id'],
                'kind'      => (string) $r['kind'],
                'principal' => self::money0($r['principal']),
                'recovered' => self::money0($r['recovered']),
                'status'    => (string) $r['status'],
                'issuedOn'  => self::str($r['issued_on']),
                'settledAt' => self::str($r['settled_at']),
                'createdAt' => self::str($r['created_at']),
            ];
        }

        return ['rows' => $out, 'next' => self::nextAfterId($out, 'loanId', $limit)];
    }

    /* =================================================================
     *  shifts — closed counter shifts, keyset by (updated_at, id)
     * ================================================================= */

    /** @return array{rows: array<int, array<string, mixed>>, next: ?array{since:string, afterId:int}} */
    public static function shifts(string $since, int $afterId, int $limit): array
    {
        $limit = self::clampLimit('shifts', $limit);
        if (!self::hasTable('counter_shifts')) {
            return ['rows' => [], 'next' => null];
        }

        $rows = Database::fetchAll(
            'SELECT s.id, s.admin_id, a.full_name, s.opened_at, s.closed_at, s.opening_cash, s.cash_sales,
                    s.upi_sales, s.tickets, s.seats, s.cash_paid_out, s.expected_cash, s.counted_cash,
                    s.variance, s.updated_at
               FROM counter_shifts s
               LEFT JOIN admins a ON a.id = s.admin_id
              WHERE s.closed_at IS NOT NULL AND (s.is_open IS NULL OR s.is_open = 0)
                AND (s.updated_at > :s1 OR (s.updated_at = :s2 AND s.id > :a))
              ORDER BY s.updated_at, s.id
              LIMIT ' . $limit,
            ['s1' => $since, 's2' => $since, 'a' => $afterId]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'shiftId'     => (int) $r['id'],
                'staffId'     => (int) $r['admin_id'],
                'staffName'   => $r['full_name'] !== null ? (string) $r['full_name'] : 'Staff #' . (int) $r['admin_id'],
                'openedAt'    => self::str($r['opened_at']),
                'closedAt'    => self::str($r['closed_at']),
                'openingCash' => self::money0($r['opening_cash']),
                'cashSales'   => self::money0($r['cash_sales']),
                'upiSales'    => self::money0($r['upi_sales']),
                'tickets'     => (int) $r['tickets'],
                'seats'       => (int) $r['seats'],
                'cashPaidOut' => self::money0($r['cash_paid_out']),
                'expectedCash'=> self::money0($r['expected_cash']),
                'countedCash' => self::money0($r['counted_cash']),
                'variance'    => self::money0($r['variance']),
                'wm'          => (string) $r['updated_at'],
            ];
        }

        $next = null;
        if (count($out) >= $limit) {
            $last = $out[count($out) - 1];
            $next = ['since' => $last['wm'], 'afterId' => $last['shiftId']];
        }
        return ['rows' => $out, 'next' => $next];
    }

    /* =================================================================
     *  daybook — the Accounting page's collections, for reconciliation
     * ================================================================= */

    /**
     * Same basis as admin/accounting.php: bookings confirmed/completed with
     * confirmed_at in the window, b.total_amount (NPR converted at the
     * NPR_PER_INR constant), bucketed by the LATEST payment — cod_pending is
     * still to collect, a settled cod is cash.
     *
     * @return array<string, mixed> rows + totals
     */
    public static function daybook(string $from, string $to): array
    {
        $toExcl = self::shiftDays($to, 1);
        $peg    = defined('NPR_PER_INR') && (float) NPR_PER_INR > 0 ? (float) NPR_PER_INR : 1.6;

        $rows = Database::fetchAll(
            "SELECT DATE(b.confirmed_at) AS day,
                    CASE WHEN lp.status = 'cod_pending' THEN 'cod_pending'
                         WHEN lp.method = 'cod' THEN 'cash'
                         WHEN lp.method IS NULL OR lp.method = '' THEN 'unknown'
                         ELSE lp.method END AS bucket,
                    COUNT(*) AS bookings,
                    SUM(CASE WHEN b.currency = 'NPR' THEN b.total_amount / :peg ELSE b.total_amount END) AS amount
               FROM bookings b
               LEFT JOIN payments lp
                      ON lp.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
              WHERE b.status IN ('confirmed', 'completed')
                AND b.confirmed_at >= :f AND b.confirmed_at < :t1
              GROUP BY day, bucket
              ORDER BY day, bucket",
            ['peg' => $peg, 'f' => $from . ' 00:00:00', 't1' => $toExcl . ' 00:00:00']
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'day'      => (string) $r['day'],
                'bucket'   => (string) $r['bucket'],
                'bookings' => (int) $r['bookings'],
                'amount'   => self::money0($r['amount']),
            ];
        }

        $commission = '0.00';
        if (self::hasTable('agent_ledger')) {
            $commission = self::money0(Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger
                  WHERE account = 'commission' AND entry_type IN ('commission', 'commission_void')
                    AND created_at >= :f AND created_at < :t1",
                ['f' => $from . ' 00:00:00', 't1' => $toExcl . ' 00:00:00'],
                0
            ));
        }

        $rf = Database::fetch(
            "SELECT COALESCE(SUM(CASE WHEN refund_status = 'processed' THEN refund_amount END), 0) AS processed,
                    COALESCE(SUM(CASE WHEN refund_status = 'pending'   THEN refund_amount END), 0) AS pending,
                    COALESCE(SUM(refund_amount), 0) AS all_status
               FROM bookings
              WHERE refund_amount > 0 AND cancelled_at >= :f AND cancelled_at < :t1",
            ['f' => $from . ' 00:00:00', 't1' => $toExcl . ' 00:00:00']
        ) ?? [];

        return [
            'from'   => $from,
            'to'     => $to,
            'totals' => [
                'commissionAccrued' => $commission,
                'refundsProcessed'  => self::money0($rf['processed'] ?? 0),
                'refundsPending'    => self::money0($rf['pending'] ?? 0),
                // admin/accounting.php "Refunds paid out": every status, by cancelled_at.
                'refundsAll'        => self::money0($rf['all_status'] ?? 0),
            ],
            'rows'   => $out,
            'next'   => null,
        ];
    }

    /* =================================================================
     *  Schema probes
     * ================================================================= */

    /** @return array<string, true> lower-case column names; [] when the table is missing */
    public static function columns(string $table): array
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $table) !== 1) {
            return [];
        }
        if (!array_key_exists($table, self::$columns)) {
            $set = [];
            try {
                foreach (Database::fetchAll('SHOW COLUMNS FROM `' . $table . '`') as $r) {
                    $set[strtolower((string) ($r['Field'] ?? ''))] = true;
                }
            } catch (Throwable $e) {
                $set = [];   // table absent on this install
            }
            self::$columns[$table] = $set;
        }
        return self::$columns[$table];
    }

    public static function hasTable(string $table): bool
    {
        return self::columns($table) !== [];
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return isset(self::columns($table)[strtolower($column)]);
    }

    /** Forget the per-request caches (tests, long-running CLI). */
    public static function reset(): void
    {
        self::$columns = [];
        self::$dbZone  = null;
        self::$maps    = null;
    }

    /** @return array{tz:string, sys:string, offset:string} */
    public static function dbZone(): array
    {
        if (self::$dbZone === null) {
            $r = Database::fetch(
                'SELECT @@session.time_zone AS tz, @@system_time_zone AS sys,
                        TIME_FORMAT(TIMEDIFF(NOW(), UTC_TIMESTAMP()), \'%H:%i\') AS off'
            ) ?? [];
            $off = (string) ($r['off'] ?? '00:00');
            if ($off !== '' && $off[0] !== '-') {
                $off = '+' . $off;
            }
            self::$dbZone = [
                'tz'     => (string) ($r['tz'] ?? ''),
                'sys'    => (string) ($r['sys'] ?? ''),
                'offset' => $off,
            ];
        }
        return self::$dbZone;
    }

    /* =================================================================
     *  Small helpers
     * ================================================================= */

    /** Decimal string with two places ("1234.50", "-200.00"), or null. */
    public static function money(mixed $v): ?string
    {
        if ($v === null || $v === '' || is_bool($v)) {
            return null;
        }
        if (is_string($v) && preg_match('/^\s*([+-]?)(\d+)(?:\.(\d{1,2})0*)?\s*$/', $v, $m) === 1) {
            $int = ltrim($m[2], '0');
            $int = $int === '' ? '0' : $int;
            $dec = str_pad($m[3] ?? '', 2, '0');
            $neg = $m[1] === '-' && ($int !== '0' || $dec !== '00');
            return ($neg ? '-' : '') . $int . '.' . $dec;
        }
        if (!is_numeric($v)) {
            return null;
        }
        $f = round((float) $v, 2);
        if (abs($f) < 0.005) {
            return '0.00';
        }
        return number_format($f, 2, '.', '');
    }

    /** Windows-1252 bytes 0x80–0x9F as Unicode (what MySQL's "latin1" means). */
    private const CP1252 = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86,
        0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
        0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95,
        0x2013 => 0x96, 0x2014 => 0x97, 0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B,
        0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    /**
     * Repair UTF-8 text that was stored twice-encoded through a latin1
     * (cp1252) connection. Only when every character maps back to one byte
     * AND those bytes are valid UTF-8 with at least one multi-byte character
     * is the repaired text returned; a correct string never qualifies
     * (real Devanagari, ₹ or plain ASCII cannot all map to single bytes and
     * still differ), so it comes back unchanged.
     */
    public static function fixMojibake(string $s): string
    {
        if ($s === '' || preg_match('/[\x{00C2}-\x{00F4}][\x{0080}-\x{00BF}\x{0152}-\x{2122}]/u', $s) !== 1) {
            return $s;
        }
        $bytes = '';
        foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp === false) {
                return $s;
            }
            if ($cp <= 0xFF) {
                $bytes .= chr($cp);
            } elseif (isset(self::CP1252[$cp])) {
                $bytes .= chr(self::CP1252[$cp]);
            } else {
                return $s;
            }
        }
        if ($bytes === $s || !mb_check_encoding($bytes, 'UTF-8') || preg_match('/[^\x00-\x7F]/', $bytes) !== 1) {
            return $s;
        }
        return $bytes;
    }

    /**
     * A typed reference (UTR, refund ref, receipt no) as sent. A UPI address
     * typed into it (98xxxxxxxx@ybl is a customer's mobile) keeps only its
     * last four characters before the @; a plain reference is unchanged.
     */
    public static function scrubRef(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string) $v;
        if (!str_contains($s, '@')) {
            return $s;
        }
        return preg_replace_callback(
            '/([A-Za-z0-9._%+\-]+)@([A-Za-z0-9.\-]+)/',
            static fn(array $m): string => str_repeat('*', max(0, strlen($m[1]) - 4)) . substr($m[1], -4) . '@' . $m[2],
            $s
        ) ?? '';
    }

    /**
     * Free text that may leave (a booking-linked ledger note): an e-mail
     * address or a mobile number typed into a cancel reason is blanked.
     */
    public static function scrubText(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return $s;
        }
        $s = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $s) ?? '';
        return preg_replace('/(?<![\d])(?:\+?(?:91|977)[\s\-]?)?[6-9]\d{9}(?!\d)/', '[phone]', $s) ?? '';
    }

    /** money(), with '0.00' for a missing value. */
    public static function money0(mixed $v): string
    {
        return self::money($v) ?? '0.00';
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }

    private static function clampLimit(string $part, int $limit): int
    {
        $max = self::PARTS[$part][1] ?? 1000;
        return max(1, min($max, $limit));
    }

    /**
     * next = {afterId: last id} when the page came back full, else null.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{afterId:int}|null
     */
    private static function nextAfterId(array $rows, string $idKey, int $limit): ?array
    {
        if (count($rows) < $limit || $rows === []) {
            return null;
        }
        return ['afterId' => (int) $rows[count($rows) - 1][$idKey]];
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TZ));
    }

    private static function shiftDays(string $ymd, int $days): string
    {
        return (new DateTimeImmutable($ymd, new DateTimeZone(self::TZ)))
            ->modify(($days < 0 ? '-' : '+') . abs($days) . ' days')
            ->format('Y-m-d');
    }

    /** @param array<string, mixed> $q */
    private static function intParam(array $q, string $key, int $default): int
    {
        if (!array_key_exists($key, $q) || $q[$key] === '' || $q[$key] === null) {
            return $default;
        }
        $v = $q[$key];
        if (is_int($v) && $v >= 0) {
            return $v;
        }
        if (!is_string($v) || preg_match('/^\d{1,18}$/', $v) !== 1) {
            throw new InvalidArgumentException($key . ' must be a whole number (0 or more).');
        }
        return (int) $v;
    }

    /** @param array<string, mixed> $q */
    private static function dateParam(array $q, string $key, string $default): string
    {
        if (!array_key_exists($key, $q) || $q[$key] === '' || $q[$key] === null) {
            return $default;
        }
        $v = $q[$key];
        if (!is_string($v) || !Security::isValidDate($v)) {
            throw new InvalidArgumentException($key . ' must be a date YYYY-MM-DD.');
        }
        return $v;
    }

    /**
     * 'YYYY-MM-DD HH:MM:SS' (a 'T' separator or a bare date is accepted and
     * normalised). Round-tripped through DateTime so 2026-02-30 or 25:00 fail.
     *
     * @param array<string, mixed> $q
     */
    private static function sinceParam(array $q, string $key, string $default): string
    {
        if (!array_key_exists($key, $q) || $q[$key] === '' || $q[$key] === null) {
            return $default;
        }
        $v = $q[$key];
        if (!is_string($v)) {
            throw new InvalidArgumentException($key . ' must be YYYY-MM-DD HH:MM:SS.');
        }
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
            $v .= ' 00:00:00';
        }
        $v = preg_replace('/^(\d{4}-\d{2}-\d{2})T/', '$1 ', $v) ?? $v;
        // A TIMESTAMP(n) column on some install echoes back fractional seconds.
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(\.\d{1,6})?$/', $v, $m) !== 1) {
            throw new InvalidArgumentException($key . ' must be YYYY-MM-DD HH:MM:SS.');
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1], new DateTimeZone(self::TZ));
        if ($dt === false || $dt->format('Y-m-d H:i:s') !== $m[1]) {
            throw new InvalidArgumentException($key . ' must be YYYY-MM-DD HH:MM:SS.');
        }
        return $v;
    }
}
