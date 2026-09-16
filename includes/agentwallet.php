<?php
/**
 * =====================================================================
 *  AgentWallet — the counter agent's profile, money ledger and the
 *  register of physical (paper) tickets they sold away from the system.
 *
 *  WHY A LEDGER AND NOT A PERCENTAGE
 *  ---------------------------------
 *  Commission used to be recomputed on every page load as
 *  "this month's confirmed sales x agent_commission_percent". A number
 *  like that can be shown but never paid: it has no history, it silently
 *  rewrites itself the day someone edits the percentage, and a booking
 *  cancelled in April quietly changes what March said it owed. Here every
 *  rupee is a row, a reversal is another row, and a balance is a SUM().
 *
 *  TWO BALANCES, one table, distinguished by `account`:
 *
 *    commission  what the COMPANY OWES THE AGENT
 *                + commission earned on a confirmed sale
 *                − payout, when the office pays them
 *
 *    cash        what the AGENT OWES THE COMPANY
 *                + cash taken across the counter
 *                − handover, when they deposit it at the office
 *
 *  Amounts are stored SIGNED, so a balance can never disagree with the
 *  rows that make it up.
 *
 *  ONE DELIBERATE ASYMMETRY: cancelling a booking reverses the
 *  commission (it was plainly not earned) but NEVER the cash. Whether the
 *  agent refunded the passenger from their drawer or still holds the
 *  money is something only the office knows — so cash is settled by an
 *  explicit handover or adjustment, exactly like a real counter.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AgentWallet
{
    /** Payment methods that mean the agent physically holds the money. */
    private const CASH_METHODS = ['cash', 'cod'];

    /** Profile defaults, merged over whatever admin_profiles holds. */
    private const PROFILE_DEFAULTS = [
        'display_phone'      => '',
        'whatsapp'           => '',
        'display_email'      => '',
        'counter_name'       => '',
        // Agent CRM (5 Sep 2026): 'org' = organization / travel agency
        // (serial band 1-20), 'person' = individual agent (serial 21+).
        'agent_kind'         => 'org',
        'contact_person'     => '',
        'address'            => '',
        'id_type'            => '',
        'id_number'          => '',
        'photo_path'         => '',
        'commission_percent' => null,
        'cash_limit'         => 0.0,
        // 0 = no cap, matching cash_limit. A COUNT of bookings per day —
        // cash_limit is an AMOUNT of money, and neither substitutes for
        // the other.
        'daily_booking_limit' => 0,
        'suspended_reason'   => '',
        'suspended_at'       => null,
        'payout_method'      => '',
        'payout_account'     => '',
        'joined_on'          => null,
        'notes'              => '',
    ];

    /** ID documents an agent may be registered against. */
    public const ID_TYPES = ['Aadhaar', 'PAN', 'Citizenship', 'Passport', 'Voter ID', 'Driving Licence'];


    /* =================================================================
     *  Configuration
     * ================================================================= */

    public static function enabled(): bool
    {
        return Settings::getBool('agent_wallet_enabled', true);
    }

    public static function offlineTicketsEnabled(): bool
    {
        return Settings::getBool('agent_offline_tickets', true);
    }

    /**
     * The commission rate for one agent: their own override when set,
     * otherwise the company-wide rate.
     */
    public static function commissionPercentFor(int $adminId): float
    {
        $own = Database::scalar(
            'SELECT commission_percent FROM admin_profiles WHERE admin_id = :a',
            ['a' => $adminId]
        );

        if ($own !== null && $own !== '') {
            return (float) $own;
        }

        return Settings::getFloat('agent_commission_percent', 5.0);
    }

    /** What this sale earns its seller. */
    public static function commissionOn(float $amount, int $adminId): float
    {
        return round($amount * self::commissionPercentFor($adminId) / 100, 2);
    }

    /* =================================================================
     *  Flat per-seat commission (owner decision 26 Aug 2026)
     *
     *  A direct agent earns a flat ₹200 PER PASSENGER; an agent who works
     *  as a team / organisation — 4-5 travellers booked under one name —
     *  earns ₹400 per passenger. Both amounts stay editable in
     *  Admin -> Settings, because the owner expects to move them up or
     *  down as the business settles.
     *
     *  ZERO SQL by design: which agents are "joint" lives in an
     *  `agent_types` settings JSON map, exactly like salaryMap() and
     *  depositMap() above, so this ships without a migration on the live
     *  server. The percent engine is left completely intact — set
     *  `agent_commission_mode` to 'percent' and everything reverts.
     * ================================================================= */

    /** @return array<string, string> admin_id => 'joint' */
    private static function agentTypeMap(): array
    {
        $raw = Settings::getArray('agent_types', []);
        $out = [];
        foreach ($raw as $k => $v) {
            if (strtolower(trim((string) $v)) === 'joint') {
                $out[(string) (int) $k] = 'joint';
            }
        }
        return $out;
    }

    /** 'joint' for a team/organisation agent, otherwise 'direct'. */
    public static function agentTypeFor(int $adminId): string
    {
        return self::agentTypeMap()[(string) $adminId] ?? 'direct';
    }

    /** Mark an agent as a team/organisation ('joint') or a plain 'direct' agent. */
    public static function setAgentType(int $adminId, string $type, int $by = 0): void
    {
        if ($adminId <= 0) {
            throw new RuntimeException('Choose an agent first.');
        }
        $type = strtolower(trim($type)) === 'joint' ? 'joint' : 'direct';

        $map = self::agentTypeMap();
        $old = $map[(string) $adminId] ?? 'direct';
        if ($type === 'joint') {
            $map[(string) $adminId] = 'joint';
        } else {
            unset($map[(string) $adminId]);   // 'direct' is the absence of a flag
        }

        // Same call shape as setSalary()/saveDeposit(): hand Settings the array
        // itself (it owns the encoding and the cache invalidation) and keep the
        // row private. Passing a pre-encoded string here left the in-process
        // cache stale, so a freshly-set tier read back as the old one.
        Settings::set('agent_types', $map, 'json', 'agent', false);

        if ($old !== $type) {
            Logger::audit(
                'agent.type_changed',
                'admin',
                (string) $adminId,
                ['agent_type' => $old],
                ['agent_type' => $type],
                'Commission tier changed by admin #' . $by
            );
        }
    }

    /* =================================================================
     *  Agent code — a short unique number, 1..1000
     *
     *  The office wanted every agent to carry a number a passenger and the
     *  desk can both quote ("agent 27"), printed on every ticket that agent
     *  sells. It is deliberately NOT the admins.id: ids are database
     *  plumbing, they skip after a deletion and they run past 1000.
     *
     *  Stored in an `agent_codes` settings map, same zero-SQL shape as the
     *  tier map above. Uniqueness is enforced here on write, because a
     *  settings blob has no UNIQUE index to lean on.
     * ================================================================= */

    public const AGENT_CODE_MIN = 1;
    public const AGENT_CODE_MAX = 1000;

    /* -----------------------------------------------------------------
     *  Agent kind + serial bands (owner ask, 5 Sep 2026)
     *
     *  Organization agents (travel agencies, counters) carry serial 1-20,
     *  person agents (individuals) carry 21 and up. The kind lives on
     *  admin_profiles.agent_kind; the serial stays in the agent_codes map
     *  above, so switching an agent's kind never touches their number,
     *  their wallet ledger or their commission tier.
     * ----------------------------------------------------------------- */
    public const KIND_ORG    = 'org';
    public const KIND_PERSON = 'person';
    public const ORG_CODE_MIN    = 1;
    public const ORG_CODE_MAX    = 20;
    public const PERSON_CODE_MIN = 21;

    /** 'org' | 'person' — anything unknown reads as 'org'. */
    public static function normaliseKind(string $kind): string
    {
        return strtolower(trim($kind)) === self::KIND_PERSON ? self::KIND_PERSON : self::KIND_ORG;
    }

    /** Human label for a kind. */
    public static function kindLabel(string $kind): string
    {
        return self::normaliseKind($kind) === self::KIND_PERSON ? 'Person' : 'Organization';
    }

    /** Digits-only phone (WhatsApp) — empty when nothing usable was typed. */
    public static function cleanPhone(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        return (strlen($d) >= 8 && strlen($d) <= 15) ? $d : '';
    }

    /** This agent's kind ('org' | 'person'); 'org' when no profile exists yet. */
    public static function agentKindFor(int $adminId): string
    {
        try {
            $v = Database::scalar('SELECT agent_kind FROM admin_profiles WHERE admin_id = :a', ['a' => $adminId]);
        } catch (Throwable $e) {
            $v = null;   // migration not run yet — every agent reads as org
        }
        return self::normaliseKind((string) ($v ?? self::KIND_ORG));
    }

    /**
     * Switch an agent between Organization and Person. The serial they hold
     * is kept on purpose (the owner's "serial nabigrine"): a number printed
     * on tickets must never change behind the office's back. New numbers
     * follow the band; an existing one is only moved by hand (setAgentCode).
     */
    public static function setAgentKind(int $adminId, string $kind, int $by = 0): void
    {
        if ($adminId <= 0) {
            throw new RuntimeException('Choose an agent first.');
        }
        $kind = self::normaliseKind($kind);
        $old  = self::agentKindFor($adminId);
        if ($old === $kind && Database::exists('SELECT 1 FROM admin_profiles WHERE admin_id = :a', ['a' => $adminId])) {
            return;
        }
        $exists = Database::exists('SELECT 1 FROM admin_profiles WHERE admin_id = :a', ['a' => $adminId]);
        if ($exists) {
            Database::update('admin_profiles', ['agent_kind' => $kind], 'admin_id = :a', ['a' => $adminId]);
        } else {
            Database::insert('admin_profiles', ['admin_id' => $adminId, 'agent_kind' => $kind]);
        }
        if ($old !== $kind) {
            Logger::audit('agent.kind_changed', 'admin', (string) $adminId,
                ['agent_kind' => $old], ['agent_kind' => $kind],
                'Agent kind changed by admin #' . $by . ' (serial, wallet and tier kept)');
        }
    }

    /** The band a kind's new serials are issued from: [min, max]. */
    public static function codeBandFor(string $kind): array
    {
        return self::normaliseKind($kind) === self::KIND_PERSON
            ? [self::PERSON_CODE_MIN, self::AGENT_CODE_MAX]
            : [self::ORG_CODE_MIN, self::ORG_CODE_MAX];
    }

    /** "1-20" / "21-1000" — for form hints. */
    public static function codeBandLabel(string $kind): string
    {
        [$lo, $hi] = self::codeBandFor($kind);
        return $lo . '-' . $hi;
    }

    /** True when a serial sits inside the band its kind should use. */
    public static function codeInBand(?int $code, string $kind): bool
    {
        if ($code === null) { return true; }
        [$lo, $hi] = self::codeBandFor($kind);
        return $code >= $lo && $code <= $hi;
    }

    /** @return array<string, int> admin_id => code */
    private static function agentCodeMap(): array
    {
        $raw = Settings::getArray('agent_codes', []);
        $out = [];
        foreach ($raw as $k => $v) {
            $n = (int) $v;
            if ($n >= self::AGENT_CODE_MIN && $n <= self::AGENT_CODE_MAX) {
                $out[(string) (int) $k] = $n;
            }
        }
        return $out;
    }

    /** This agent's number, or null when the office has not issued one yet. */
    public static function agentCodeFor(int $adminId): ?int
    {
        return self::agentCodeMap()[(string) $adminId] ?? null;
    }

    /** Which admin holds a given code, or null when it is free. */
    public static function adminForAgentCode(int $code): ?int
    {
        $id = array_search($code, self::agentCodeMap(), true);
        return $id === false ? null : (int) $id;
    }

    /**
     * Public booking pipeline resolver (master-prompt §3).
     *
     * Takes the customer's raw typed string ("SHG-027", "SHG027", "27",
     * "shg 27"…) and returns the counter-agent's admin_id — but ONLY when
     * every guard passes: the pattern parses, the number is in range, the
     * code is currently assigned, and the admin behind it is an ACTIVE
     * (not locked, not soft-deleted) agent. Anything else returns null so
     * the booking silently falls through to a normal direct sale.
     *
     * This is the one place the server trusts a customer-supplied code —
     * the agent's admin_id itself is never accepted from the browser.
     */
    public static function resolveAgentCodeFromString(string $raw): ?int
    {
        // Uppercase FIRST so a lowercase "shg-42" survives the filter — a
        // case-blind /[^A-Z0-9]/i would still strip lowercase before we
        // could hoist them, so the order matters.
        $s = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $raw)) ?? '';
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^SHG(\d{1,4})$/', $s, $m)) {
            return null;
        }
        $code = (int) $m[1];
        if ($code < self::AGENT_CODE_MIN || $code > self::AGENT_CODE_MAX) {
            return null;
        }
        $adminId = self::adminForAgentCode($code);
        if ($adminId === null) {
            return null;
        }
        // Refuse a disabled / locked agent so a suspended code cannot silently
        // keep earning commissions on the public checkout.
        $row = Database::fetch(
            "SELECT id FROM admins
              WHERE id = :id AND role = 'agent' AND is_active = 1
                    AND (locked_until IS NULL OR locked_until < NOW())
              LIMIT 1",
            ['id' => $adminId]
        );
        return $row === null ? null : $adminId;
    }

    /**
     * The lowest unissued number, or null once all 1000 are taken.
     *
     * With a kind the search follows its band: 'org' walks 1-20 first (and
     * only spills past 20 when every org number is held, so a 21st agency
     * still gets an account rather than an error), 'person' starts at 21.
     * Without a kind it is the old flat scan, kept for existing callers.
     */
    public static function nextFreeAgentCode(?string $kind = null): ?int
    {
        $taken = array_flip(self::agentCodeMap());
        $scan  = static function (int $lo, int $hi) use ($taken): ?int {
            for ($n = $lo; $n <= $hi; $n++) {
                if (!isset($taken[$n])) {
                    return $n;
                }
            }
            return null;
        };
        if ($kind === null) {
            return $scan(self::AGENT_CODE_MIN, self::AGENT_CODE_MAX);
        }
        [$lo, $hi] = self::codeBandFor($kind);
        $n = $scan($lo, $hi);
        if ($n === null && self::normaliseKind($kind) === self::KIND_ORG) {
            $n = $scan(self::PERSON_CODE_MIN, self::AGENT_CODE_MAX);   // org band full — spill over
        }
        return $n;
    }

    /**
     * Issue (or clear, with null/0) one agent's number.
     *
     * @throws RuntimeException when out of range, or already held by someone else
     */
    public static function setAgentCode(int $adminId, ?int $code, int $by = 0): void
    {
        if ($adminId <= 0) {
            throw new RuntimeException('Choose an agent first.');
        }

        $map = self::agentCodeMap();
        $old = $map[(string) $adminId] ?? null;

        if ($code === null || $code === 0) {
            unset($map[(string) $adminId]);
        } else {
            if ($code < self::AGENT_CODE_MIN || $code > self::AGENT_CODE_MAX) {
                throw new RuntimeException(
                    'The agent number must be between ' . self::AGENT_CODE_MIN . ' and ' . self::AGENT_CODE_MAX . '.'
                );
            }
            // Two agents sharing a number would make every ticket ambiguous
            // and mis-credit the commission conversation at the counter.
            $holder = self::adminForAgentCode($code);
            if ($holder !== null && $holder !== $adminId) {
                throw new RuntimeException('Agent number ' . $code . ' is already used by another agent.');
            }
            $map[(string) $adminId] = $code;
        }

        Settings::set('agent_codes', $map, 'json', 'agent', false);

        if ($old !== $code) {
            Logger::audit('agent.code_set', 'admin', (string) $adminId,
                ['agent_code' => $old], ['agent_code' => $code], 'by admin#' . $by);
        }
    }

    /** "SHG-0027" — the form printed on tickets. Empty when none is issued. */
    public static function agentCodeLabel(int $adminId): string
    {
        $code = self::agentCodeFor($adminId);
        return $code === null ? '' : 'SHG-' . str_pad((string) $code, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Renumber every agent to a clean 1..N sequence (owner ask, 2 Sep 2026:
     * "0001 bata suru, S Hari Global ko 1 ma"). Order: agents that already
     * hold a code, by that code ascending (so the lowest existing number —
     * S Hari Global — lands on 1), then any un-numbered agents by id. The
     * whole agent_codes map is rebuilt, dropping stale entries left by
     * deleted agents (which is what made numbers look "reserved up to 27").
     *
     * @return array{count:int, changes:array<int,array{id:int,name:string,old:?int,new:int}>}
     */
    public static function resequenceAgentCodes(int $by = 0): array
    {
        // Band-aware since 5 Sep 2026: organization agents are packed into
        // 1, 2, 3... and person agents into 21, 22, 23... (each group ordered
        // by its current number, then id). Should there ever be more than 20
        // organizations, the extra ones simply continue after the persons.
        $agents = Database::fetchAll(
            "SELECT a.id, a.full_name, a.username, COALESCE(p.agent_kind, 'org') AS agent_kind
               FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id
              WHERE a.role = 'agent'"
        );
        $cur    = self::agentCodeMap();   // id => current code

        usort($agents, static function (array $a, array $b) use ($cur): int {
            $ca = $cur[(string) (int) $a['id']] ?? PHP_INT_MAX;
            $cb = $cur[(string) (int) $b['id']] ?? PHP_INT_MAX;
            if ($ca !== $cb) { return $ca <=> $cb; }
            return (int) $a['id'] <=> (int) $b['id'];
        });

        $orgs    = array_values(array_filter($agents, static fn(array $a) => self::normaliseKind((string) $a['agent_kind']) === self::KIND_ORG));
        $persons = array_values(array_filter($agents, static fn(array $a) => self::normaliseKind((string) $a['agent_kind']) === self::KIND_PERSON));

        $map = [];
        $changes = [];
        $assign = static function (array $a, int $n) use (&$map, &$changes, $cur): void {
            $id  = (int) $a['id'];
            $old = $cur[(string) $id] ?? null;
            $map[(string) $id] = $n;
            if ($old !== $n) {
                $changes[] = ['id' => $id, 'name' => (string) ($a['full_name'] ?: $a['username']), 'old' => $old, 'new' => $n];
            }
        };

        $n = self::ORG_CODE_MIN;
        $overflow = [];
        foreach ($orgs as $a) {
            if ($n > self::ORG_CODE_MAX) { $overflow[] = $a; continue; }
            $assign($a, $n++);
        }
        $n = self::PERSON_CODE_MIN;
        foreach (array_merge($persons, $overflow) as $a) {
            if ($n > self::AGENT_CODE_MAX) { break; }   // never exceed the ceiling
            $assign($a, $n++);
        }

        Settings::set('agent_codes', $map, 'json', 'agent', false);
        Logger::audit('agent.resequence', 'settings', 'agent_codes',
            null, ['count' => count($map), 'org' => count($orgs), 'person' => count($persons)],
            'Agents renumbered (org 1-20, person 21+) by admin #' . $by);

        return ['count' => count($map), 'changes' => $changes];
    }

    /** The flat per-passenger commission this agent earns. */
    public static function flatRateFor(int $adminId): float
    {
        return self::agentTypeFor($adminId) === 'joint'
            ? Settings::getFloat('agent_flat_joint', 400.0)
            : Settings::getFloat('agent_flat_direct', 200.0);
    }

    /**
     * Edit the two company tier amounts (direct / team-organisation, ₹ per
     * passenger) — the ₹200/₹400 the owner wants to move without a deploy.
     * Same store the flat engine already reads (agent_flat_direct /
     * agent_flat_joint), same call shape as the other settings writers.
     */
    public static function setFlatRates(int $direct, int $joint, int $by = 0): void
    {
        foreach (['direct' => $direct, 'team/organisation' => $joint] as $label => $v) {
            if ($v < 0 || $v > 10000) {
                throw new RuntimeException('The ' . $label . ' tier rate must be between 0 and 10000.');
            }
        }

        $oldD = Settings::getFloat('agent_flat_direct', 200.0);
        $oldJ = Settings::getFloat('agent_flat_joint', 400.0);

        Settings::set('agent_flat_direct', (string) $direct, 'float', 'agent');
        Settings::set('agent_flat_joint',  (string) $joint,  'float', 'agent');

        if ($oldD !== (float) $direct || $oldJ !== (float) $joint) {
            Logger::audit('agent.tier_rates', 'settings', 'agent_flat',
                ['direct' => $oldD, 'joint' => $oldJ],
                ['direct' => $direct, 'joint' => $joint],
                'Tier rates changed by admin #' . $by);
        }
    }

    /* =================================================================
     *  PER-AGENT commission override (owner ask, 27 Aug 2026:
     *  "commission 200/400 aru editable percentage / direct amount")
     *
     *  One agent can be paid differently from the company scheme: a flat
     *  ₹ amount per passenger of their own, or forced onto the percent
     *  engine, regardless of the global agent_commission_mode. Stored in
     *  an `agent_commission_overrides` settings JSON map (admin_id ->
     *  {mode, flat}) — the same zero-SQL shape as agent_types /
     *  agent_salaries, so nothing needs a migration on the live server.
     * ================================================================= */

    /** @return array<string, array{mode: string, flat: float|null}> */
    private static function commissionOverrideMap(): array
    {
        $raw = Settings::getArray('agent_commission_overrides', []);
        $out = [];
        foreach ($raw as $k => $v) {
            if (!is_array($v)) { continue; }
            $mode = strtolower(trim((string) ($v['mode'] ?? '')));
            if (!in_array($mode, ['flat', 'percent'], true)) { continue; }
            $flat = isset($v['flat']) && is_numeric($v['flat']) ? round((float) $v['flat'], 2) : null;
            if ($flat !== null && ($flat < 0 || $flat > 10000)) { $flat = null; }
            $out[(string) (int) $k] = ['mode' => $mode, 'flat' => $flat];
        }
        return $out;
    }

    /**
     * This agent's override, or nulls when they follow the company scheme.
     *
     * @return array{mode: string|null, flat: float|null}
     */
    public static function commissionOverrideFor(int $adminId): array
    {
        return self::commissionOverrideMap()[(string) $adminId]
            ?? ['mode' => null, 'flat' => null];
    }

    /**
     * Set (mode 'flat'|'percent') or clear (mode null) one agent's override.
     *
     * @throws RuntimeException on a bad mode, a flat amount out of 0..10000,
     *                          or a 'flat' override with no amount
     */
    public static function setCommissionOverride(int $adminId, ?string $mode, ?float $flat, int $by = 0): void
    {
        if ($adminId <= 0) {
            throw new RuntimeException('Choose an agent first.');
        }

        $mode = $mode === null ? null : strtolower(trim($mode));
        if ($mode !== null && !in_array($mode, ['flat', 'percent'], true)) {
            throw new RuntimeException('Unknown commission override mode.');
        }
        if ($flat !== null) {
            $flat = round($flat, 2);
            if ($flat < 0 || $flat > 10000) {
                throw new RuntimeException('The flat override must be between ₹0 and ₹10,000 per passenger.');
            }
        }
        if ($mode === 'flat' && $flat === null) {
            throw new RuntimeException('Enter the flat ₹ amount per passenger for the override.');
        }

        $map = self::commissionOverrideMap();
        $old = $map[(string) $adminId] ?? null;

        if ($mode === null) {
            unset($map[(string) $adminId]);   // back to the company scheme
        } else {
            $map[(string) $adminId] = [
                'mode' => $mode,
                'flat' => $mode === 'flat' ? $flat : null,
            ];
        }

        Settings::set('agent_commission_overrides', $map, 'json', 'agent', false);

        if ($old !== ($map[(string) $adminId] ?? null)) {
            Logger::audit('agent.commission_override', 'admin', (string) $adminId,
                ['override' => $old], ['override' => $map[(string) $adminId] ?? null],
                'Commission override changed by admin #' . $by);
        }
    }

    /** True while the company pays a flat per-seat commission (the default). */
    public static function flatMode(): bool
    {
        return Settings::getString('agent_commission_mode', 'flat_per_seat') !== 'percent';
    }

    /**
     * Passengers actually sold on a booking — the unit the flat commission is
     * paid on. Counts live seats only, so a released/cancelled berth never
     * earns commission.
     */
    public static function seatCountFor(int $bookingId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b AND released_at IS NULL',
            ['b' => $bookingId],
            0
        );
    }

    /**
     * What one confirmed booking earns its seller, under whichever scheme is
     * active. Flat mode pays per passenger; percent mode is the original
     * "x% of the sale" behaviour.
     */
    public static function commissionForBooking(float $total, int $adminId, int $seats): float
    {
        // A per-agent override beats the company scheme entirely: their own
        // flat ₹ per passenger, or forced onto the percent engine (which in
        // turn honours their commission_percent when one is set).
        $ov = self::commissionOverrideFor($adminId);
        if ($ov['mode'] === 'flat') {
            return round(($ov['flat'] ?? self::flatRateFor($adminId)) * max(1, $seats), 2);
        }
        if ($ov['mode'] === 'percent') {
            return self::commissionOn($total, $adminId);
        }

        if (!self::flatMode()) {
            return self::commissionOn($total, $adminId);
        }

        // A booking always carries at least one passenger; guard against a
        // zero count so a seat-row hiccup cannot silently pay nothing.
        return round(self::flatRateFor($adminId) * max(1, $seats), 2);
    }

    /** Human note for the ledger row, so the agent can see how it was worked out. */
    public static function commissionNoteFor(int $adminId, int $seats, float $total): string
    {
        // Says what was ACTUALLY paid — an override note must not read like
        // the company tier, or the office can never explain a payout.
        $ov = self::commissionOverrideFor($adminId);
        if ($ov['mode'] === 'flat') {
            return max(1, $seats) . ' x ' . inr($ov['flat'] ?? self::flatRateFor($adminId)) . ' (agent override)';
        }
        if ($ov['mode'] === 'percent') {
            return self::commissionPercentFor($adminId) . '% of ' . inr($total) . ' (agent override)';
        }

        if (!self::flatMode()) {
            return self::commissionPercentFor($adminId) . '% of ' . inr($total);
        }
        $seats = max(1, $seats);
        return $seats . ' x ' . inr(self::flatRateFor($adminId))
             . ' (' . (self::agentTypeFor($adminId) === 'joint' ? 'team/organisation' : 'direct') . ' agent)';
    }


    /* =================================================================
     *  Profile
     * ================================================================= */

    /**
     * One agent's profile, defaults filled in. Never returns null so the
     * admin screens can read fields without guarding every one.
     *
     * @return array<string, mixed>
     */
    public static function profile(int $adminId): array
    {
        try {
            $row = Database::fetch('SELECT * FROM admin_profiles WHERE admin_id = :a', ['a' => $adminId]);
        } catch (Throwable $e) {
            $row = null;   // migration not run yet — degrade to defaults
        }

        $profile = self::PROFILE_DEFAULTS;
        if ($row !== null) {
            foreach (self::PROFILE_DEFAULTS as $k => $_) {
                if (array_key_exists($k, $row) && $row[$k] !== null) {
                    $profile[$k] = $row[$k];
                }
            }
        }
        if ($profile['cash_limit'] === 0.0 || $profile['cash_limit'] === '0.00') {
            $profile['cash_limit'] = Settings::getFloat('agent_cash_limit_default', 0.0);
        }

        return $profile;
    }

    /**
     * Create or update a profile. Only the keys given are written, so a
     * form that shows half the fields cannot blank the other half.
     *
     * @param array<string, mixed> $data
     */
    public static function saveProfile(int $adminId, array $data): void
    {
        $clean = [];

        if (array_key_exists('display_phone', $data))  { $clean['display_phone']  = Security::clean((string) $data['display_phone'], 20); }
        if (array_key_exists('whatsapp', $data))       { $clean['whatsapp']       = self::cleanPhone((string) $data['whatsapp']); }
        if (array_key_exists('display_email', $data))  { $clean['display_email']  = Security::email((string) $data['display_email']); }
        if (array_key_exists('counter_name', $data))   { $clean['counter_name']   = Security::clean((string) $data['counter_name'], 120); }
        if (array_key_exists('agent_kind', $data))     { $clean['agent_kind']     = self::normaliseKind((string) $data['agent_kind']); }
        if (array_key_exists('contact_person', $data)) { $clean['contact_person'] = Security::clean((string) $data['contact_person'], 120); }
        if (array_key_exists('address', $data))        { $clean['address']        = Security::clean((string) $data['address'], 255); }
        if (array_key_exists('payout_method', $data))  { $clean['payout_method']  = Security::clean((string) $data['payout_method'], 40); }
        if (array_key_exists('payout_account', $data)) { $clean['payout_account'] = Security::clean((string) $data['payout_account'], 120); }
        if (array_key_exists('notes', $data))          { $clean['notes']          = Security::clean((string) $data['notes'], 500); }

        if (array_key_exists('commission_percent', $data)) {
            $pct = trim((string) $data['commission_percent']);
            // Blank means "use the company rate", which is a real choice —
            // it must be storable, so it maps to NULL rather than to 0.
            if ($pct === '') {
                $clean['commission_percent'] = null;
            } else {
                $pct = (float) $pct;
                if ($pct < 0 || $pct > 100) {
                    throw new RuntimeException('Commission % must be between 0 and 100.');
                }
                $clean['commission_percent'] = $pct;
            }
        }

        if (array_key_exists('cash_limit', $data)) {
            $clean['cash_limit'] = max(0.0, round((float) $data['cash_limit'], 2));
        }

        if (array_key_exists('id_type', $data)) {
            $t = Security::clean((string) $data['id_type'], 40);
            $clean['id_type'] = in_array($t, self::ID_TYPES, true) ? $t : '';
        }
        if (array_key_exists('id_number', $data)) {
            $clean['id_number'] = Security::clean((string) $data['id_number'], 60);
        }
        if (array_key_exists('photo_path', $data)) {
            // Stored, never echoed as a path the browser controls — see
            // savePhoto(), which is the only thing that should set this.
            $clean['photo_path'] = Security::clean((string) $data['photo_path'], 255);
        }

        if (array_key_exists('daily_booking_limit', $data)) {
            $clean['daily_booking_limit'] = max(0, (int) $data['daily_booking_limit']);
        }

        if (array_key_exists('suspended_reason', $data)) {
            $clean['suspended_reason'] = Security::clean((string) $data['suspended_reason'], 255);
        }
        if (array_key_exists('suspended_at', $data)) {
            $v = $data['suspended_at'];
            $clean['suspended_at'] = ($v === null || $v === '') ? null : (string) $v;
        }

        if (array_key_exists('joined_on', $data)) {
            $d = trim((string) $data['joined_on']);
            $clean['joined_on'] = ($d !== '' && Security::isValidDate($d)) ? $d : null;
        }

        if ($clean === []) {
            return;
        }

        $exists = Database::exists('SELECT 1 FROM admin_profiles WHERE admin_id = :a', ['a' => $adminId]);
        if ($exists) {
            Database::update('admin_profiles', $clean, 'admin_id = :a', ['a' => $adminId]);
        } else {
            Database::insert('admin_profiles', $clean + ['admin_id' => $adminId]);
        }

        Logger::audit('agent.profile', 'admin', (string) $adminId, null, $clean, 'Agent profile updated');
    }


    /**
     * Store an agent's photo and return the value to record on the profile.
     *
     * Reuses the payment-proof validator, so the real MIME type is read out
     * of the file rather than trusted from the browser, and the same
     * unguessable filename, so a photo cannot be found by walking uploads/.
     * That tree is served but never executed (uploads/.htaccess), which is
     * what makes handing the path straight to an <img> safe here.
     *
     * @param array<string, mixed> $file entry from $_FILES
     */
    public static function savePhoto(int $adminId, array $file): string
    {
        $check = Security::validateUpload($file);
        if (!$check['ok']) {
            throw new RuntimeException($check['error'] ?? 'Upload failed.');
        }

        // The shared validator also accepts PDF, which is right for payment
        // proof and wrong for a face — narrow it here.
        $ext = strtolower((string) ($check['ext'] ?? ''));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new RuntimeException('The photo must be a JPG, PNG or WEBP image.');
        }

        $dir = UPLOAD_PATH . '/agents';
        ensureDir($dir);

        $filename = Security::safeFilename($ext);
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $filename)) {
            throw new RuntimeException('Could not save the photo.');
        }

        // One current photo per agent — replace rather than accumulate, or
        // every re-upload leaves an orphan nobody ever cleans up.
        $old = (string) (self::profile($adminId)['photo_path'] ?? '');
        if ($old !== '' && str_starts_with($old, 'agents/')) {
            @unlink(UPLOAD_PATH . '/' . $old);
        }

        return 'agents/' . $filename;
    }


    /* =================================================================
     *  Selling controls — which routes, and how many a day
     * ================================================================= */

    /**
     * Route ids this agent is restricted to.
     *
     * AN EMPTY ARRAY MEANS "EVERY ROUTE", not "no routes". Restriction is
     * opt-in: an agent is unrestricted until somebody names the routes they
     * are allowed to sell. Default-closed would have silently stopped every
     * existing agent from selling the day the migration ran.
     *
     * @return array<int, int>
     */
    public static function routePermissions(int $adminId): array
    {
        try {
            return array_map('intval', pluck(Database::fetchAll(
                'SELECT route_id FROM agent_route_permissions WHERE admin_id = :a ORDER BY route_id',
                ['a' => $adminId]
            ), 'route_id'));
        } catch (Throwable $e) {
            return [];   // migration not run yet — unrestricted, as before
        }
    }

    /**
     * Replace an agent's route permissions. An empty list clears the
     * restriction entirely (back to "may sell every route").
     *
     * @param array<int, int|string> $routeIds
     */
    public static function setRoutePermissions(int $adminId, array $routeIds, int $by = 0): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $routeIds), static fn(int $i): bool => $i > 0)));

        Database::transaction(static function () use ($adminId, $ids, $by): void {
            Database::delete('agent_route_permissions', 'admin_id = :a', ['a' => $adminId]);
            foreach ($ids as $rid) {
                Database::insert('agent_route_permissions', [
                    'admin_id'   => $adminId,
                    'route_id'   => $rid,
                    'created_by' => $by ?: null,
                ]);
            }
        });

        Logger::audit('agent.routes', 'admin', (string) $adminId, null,
            ['routes' => $ids], $ids === [] ? 'Route restriction cleared' : 'Route permissions set');
    }

    public static function maySellRoute(int $adminId, int $routeId): bool
    {
        $allowed = self::routePermissions($adminId);

        return $allowed === [] || in_array($routeId, $allowed, true);
    }

    /** How many bookings this agent has created today. */
    public static function bookingsToday(int $adminId): int
    {
        $today = date('Y-m-d');

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM bookings
              WHERE sold_by_admin_id = :a AND created_at >= :d0 AND created_at < :d1',
            ['a' => $adminId, 'd0' => $today, 'd1' => date('Y-m-d', strtotime($today . ' +1 day'))],
            0
        );
    }

    /**
     * The daily cap for this agent — their own, else the company default.
     * 0 means no cap.
     */
    public static function dailyLimitFor(int $adminId): int
    {
        $own = (int) (self::profile($adminId)['daily_booking_limit'] ?? 0);

        return $own > 0 ? $own : Settings::getInt('agent_daily_booking_limit', 0);
    }

    /**
     * Gate a counter sale before it is written. Throws with a message meant
     * to be shown to the agent at the counter, so it says what to do next.
     *
     * Only counter agents are constrained: a manager or superadmin selling
     * from the same screen is not subject to an agent's route list or cap.
     */
    public static function assertMaySell(int $adminId, int $routeId): void
    {
        if ($adminId <= 0 || !Auth::isCounterAgent()) {
            return;
        }

        if (!self::maySellRoute($adminId, $routeId)) {
            throw new RuntimeException('You are not assigned to this route. Ask the office to add it to your account.');
        }

        $limit = self::dailyLimitFor($adminId);
        if ($limit > 0 && self::bookingsToday($adminId) >= $limit) {
            throw new RuntimeException(
                'You have reached your daily limit of ' . $limit . ' bookings. Ask the office to raise it.'
            );
        }
    }


    /* =================================================================
     *  Automatic accrual
     * ================================================================= */

    /**
     * A booking became confirmed — credit its seller.
     *
     * Idempotent by construction: the UNIQUE(booking_id, entry_type) index
     * means a second call inserts nothing, so re-approving a reversed
     * booking cannot pay commission twice.
     *
     * Never throws — a wallet problem must not be able to block a booking
     * confirmation.
     */
    public static function accrue(array $booking): void
    {
        try {
            if (!self::enabled()) {
                return;
            }

            $agentId   = (int) ($booking['sold_by_admin_id'] ?? 0);
            $bookingId = (int) ($booking['id'] ?? 0);
            if ($agentId <= 0 || $bookingId <= 0) {
                return;   // an online sale has no counter agent
            }

            /* Reversal-aware (§4 reversible payment review).
               A previous reject/cancel wrote a negative 'commission_void' row
               that nets this booking's commission to zero. The ORIGINAL
               'commission' row is still there, and UNIQUE(booking_id,
               entry_type) makes the insert below a silent no-op — so without
               this the agent would stay permanently unpaid after a
               reject -> re-approve cycle. Dropping the reversal restores the
               net to exactly the original commission, and still cannot pay
               twice (the commission row itself is never duplicated). */
            $unvoided = Database::delete(
                'agent_ledger',
                "booking_id = :b AND entry_type = 'commission_void'",
                ['b' => $bookingId]
            );
            if ($unvoided > 0) {
                Logger::audit(
                    'agent.commission_restored',
                    'booking',
                    (string) ($booking['pnr'] ?? ''),
                    ['commission' => 'voided'],
                    ['commission' => 'restored'],
                    'Re-approved after reversal — commission reinstated for agent #' . $agentId
                );
            }

            $total = (float) ($booking['total_amount'] ?? 0);
            // Flat commission is paid PER PASSENGER, so the seat count is part
            // of the sum — read from the live seat rows rather than trusting a
            // caller-supplied figure.
            $seats = self::seatCountFor($bookingId);
            $comm  = self::commissionForBooking($total, $agentId, $seats);

            if ($comm > 0) {
                self::insertEntry([
                    'agent_admin_id' => $agentId,
                    'booking_id'     => $bookingId,
                    'account'        => 'commission',
                    'entry_type'     => 'commission',
                    'amount'         => $comm,
                    'note'           => 'Commission on ' . ($booking['pnr'] ?? '')
                                        . ' (' . self::commissionNoteFor($agentId, $seats, $total) . ')',
                    'ref'            => (string) ($booking['pnr'] ?? ''),
                ]);
            }

            // Cash across the counter is money the agent now holds for us.
            if ($total > 0 && self::isCashSale($bookingId, $booking)) {
                self::insertEntry([
                    'agent_admin_id' => $agentId,
                    'booking_id'     => $bookingId,
                    'account'        => 'cash',
                    'entry_type'     => 'cash_due',
                    'amount'         => $total,
                    'note'           => 'Cash collected for ' . ($booking['pnr'] ?? ''),
                    'ref'            => (string) ($booking['pnr'] ?? ''),
                ]);
            }
        } catch (Throwable $e) {
            Logger::error('AgentWallet::accrue failed', ['booking' => $booking['pnr'] ?? '', 'err' => $e->getMessage()], 'agent');
        }
    }

    /**
     * A booking was cancelled or rejected — take the commission back.
     *
     * Cash is deliberately left alone: see the class docblock.
     */
    public static function voidFor(array $booking, string $reason = ''): void
    {
        try {
            $bookingId = (int) ($booking['id'] ?? 0);
            if ($bookingId <= 0) {
                return;
            }

            $earned = Database::fetch(
                "SELECT agent_admin_id, amount FROM agent_ledger
                  WHERE booking_id = :b AND entry_type = 'commission'",
                ['b' => $bookingId]
            );
            if ($earned === null || (float) $earned['amount'] <= 0) {
                return;
            }

            self::insertEntry([
                'agent_admin_id' => (int) $earned['agent_admin_id'],
                'booking_id'     => $bookingId,
                'account'        => 'commission',
                'entry_type'     => 'commission_void',
                'amount'         => -1 * (float) $earned['amount'],
                'note'           => 'Reversed — ' . ($reason !== '' ? $reason : 'booking cancelled')
                                    . ' (' . ($booking['pnr'] ?? '') . ')',
                'ref'            => (string) ($booking['pnr'] ?? ''),
            ]);
        } catch (Throwable $e) {
            Logger::error('AgentWallet::voidFor failed', ['booking' => $booking['pnr'] ?? '', 'err' => $e->getMessage()], 'agent');
        }
    }

    /** Did the agent physically take money for this booking? */
    private static function isCashSale(int $bookingId, array $booking): bool
    {
        if ((int) ($booking['is_cod'] ?? 0) === 1) {
            return true;
        }

        $method = (string) Database::scalar(
            'SELECT method FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
            ['b' => $bookingId],
            ''
        );

        return in_array($method, self::CASH_METHODS, true);
    }

    /**
     * Move a booking's COMMISSION from one seller to another — the office
     * correcting who really sold a ticket, even after it exists (requirement 3:
     * "ticket banisakepachhi pani agent assign/change garna milne").
     *
     * COMMISSION ONLY — cash is never moved. Cash-in-hand is physical custody:
     * whoever actually took the money still owes it until they hand it over
     * (recorded as a booking_id-NULL handover). Rewriting a per-booking cash_due
     * after a handover would corrupt both agents' cash balances, so this leaves
     * every 'cash' row exactly where it is.
     *
     * agent_ledger is keyed UNIQUE(booking_id, entry_type), so there can only be
     * ONE 'commission' row per booking — the row every future cancel/re-approve
     * reads. Rather than delete-and-re-mint (which can pay an unearned credit on
     * a cancelled booking, and relies on accrue()'s error-swallowing), this
     * re-owns the existing commission-account rows with a direct UPDATE and only
     * re-rates / mints a live credit when the booking is actually
     * commission-eligible (confirmed/completed). Every write throws on failure,
     * so a hiccup rolls the whole transaction back rather than committing a
     * half-move.
     *
     * Passing 0/null as $newAgentId detaches the sale to the office: the old
     * seller's commission is reversed (netted to zero), cash left untouched.
     *
     * @param array<string,mixed> $booking a bookings row (needs id, pnr,
     *                                      sold_by_admin_id, status, total_amount)
     * @return array{old:int, new:int, commission:float}
     */
    public static function reassignSeller(array $booking, int $newAgentId, int $by = 0): array
    {
        $bookingId = (int) ($booking['id'] ?? 0);
        if ($bookingId <= 0) {
            throw new RuntimeException('Unknown booking.');
        }
        $oldAgentId = (int) ($booking['sold_by_admin_id'] ?? 0);

        if ($newAgentId > 0) {
            $ok = Database::exists(
                "SELECT 1 FROM admins WHERE id = :i AND role = 'agent'",
                ['i' => $newAgentId]
            );
            if (!$ok) {
                throw new RuntimeException('Choose an active agent to assign this booking to.');
            }
        }
        if ($newAgentId === $oldAgentId) {
            return ['old' => $oldAgentId, 'new' => $newAgentId, 'commission' => 0.0];
        }

        // Commission is only earned once a booking is actually paid/travelled.
        $status     = strtolower((string) ($booking['status'] ?? ''));
        $eligible   = in_array($status, ['confirmed', 'completed'], true);
        $total      = (float) ($booking['total_amount'] ?? 0);
        $pnr        = (string) ($booking['pnr'] ?? '');
        $commission = 0.0;

        Database::transaction(function () use ($booking, $bookingId, $newAgentId, $eligible, $total, $pnr, &$commission): void {
            // 1. Reattribute the booking itself — valid whatever its state.
            Database::update(
                'bookings',
                ['sold_by_admin_id' => $newAgentId > 0 ? $newAgentId : null],
                'id = :i',
                ['i' => $bookingId]
            );

            if (!self::enabled()) {
                return;
            }

            // 2a. Detach to office: reverse the old seller's commission so they
            //     net zero, and stop. Cash is deliberately left with them.
            if ($newAgentId <= 0) {
                self::voidFor($booking, 'Sale reassigned to office (no agent)');
                return;
            }

            // 2b. Re-own every COMMISSION-account row for this booking (the live
            //     credit and any historical reversal) so nothing is orphaned and
            //     no 'cash' row is ever touched.
            Database::update(
                'agent_ledger',
                ['agent_admin_id' => $newAgentId],
                "booking_id = :b AND account = 'commission'",
                ['b' => $bookingId]
            );

            // 3. Only a LIVE booking mints / re-rates commission. A pending or
            //    cancelled one just carries its (already-netted) rows across, so
            //    a later confirm credits the right person and a cancel stays 0.
            if (!$eligible) {
                return;
            }

            $seats      = self::seatCountFor($bookingId);
            $commission = self::commissionForBooking($total, $newAgentId, $seats);
            $note       = 'Commission on ' . $pnr . ' ('
                        . self::commissionNoteFor($newAgentId, $seats, $total) . ') · reassigned';

            $commRow = Database::fetch(
                "SELECT id FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission' LIMIT 1",
                ['b' => $bookingId]
            );
            if ($commRow !== null) {
                // Re-rate the moved row to the NEW agent's tier (a direct agent
                // must not inherit a joint agent's ₹400).
                Database::update('agent_ledger',
                    ['amount' => $commission, 'note' => $note],
                    'id = :i', ['i' => (int) $commRow['id']]);
                // A live booking should carry no reversal; drop a stale one so the
                // net equals the re-rated commission.
                Database::delete('agent_ledger',
                    "booking_id = :b AND entry_type = 'commission_void'", ['b' => $bookingId]);
            } elseif ($commission > 0) {
                // No prior commission (e.g. an online sale first attributed to an
                // agent now) — mint one for the new seller.
                self::insertEntry([
                    'agent_admin_id' => $newAgentId,
                    'booking_id'     => $bookingId,
                    'account'        => 'commission',
                    'entry_type'     => 'commission',
                    'amount'         => $commission,
                    'note'           => $note,
                    'ref'            => $pnr,
                ]);
            }
        });

        Logger::audit(
            'booking.reassign_agent',
            'booking',
            $pnr !== '' ? $pnr : (string) $bookingId,
            ['sold_by_admin_id' => $oldAgentId],
            ['sold_by_admin_id' => $newAgentId, 'commission' => $commission, 'status' => $status],
            'Selling agent changed by admin #' . $by
        );

        return ['old' => $oldAgentId, 'new' => $newAgentId, 'commission' => $commission];
    }


    /* =================================================================
     *  Manual entries — payout, handover, correction
     * ================================================================= */

    /**
     * Record a money movement by hand. Returns the new row id.
     *
     * The sign is derived from the entry type, not taken from the caller,
     * so a mistyped minus in a form can never turn a payout into a credit.
     */
    /* =================================================================
     *  SALARY
     *
     *  A counter agent can be on commission, on a fixed monthly salary, or
     *  on both. Salary deliberately reuses the existing ledger instead of
     *  adding an `entry_type`: changing that ENUM means an ALTER TABLE on a
     *  live database days before launch, and the owner runs migrations by
     *  hand. An 'adjustment' credit tagged `SALARY YYYY-MM` in `ref` gives
     *  the same audit trail, the same balance arithmetic and the same
     *  payout flow, with nothing to migrate.
     *
     *  The per-agent amount lives in the `agent_salaries` setting (a JSON
     *  map of admin_id -> monthly rupees) for the same reason: no new
     *  column on admin_profiles.
     * ================================================================= */

    private const SALARY_REF_PREFIX = 'SALARY ';

    /** @return array<string, float> admin_id => monthly amount */
    private static function salaryMap(): array
    {
        $raw = Settings::getArray('agent_salaries', []);
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_numeric($v) && (float) $v > 0) {
                $out[(string) (int) $k] = round((float) $v, 2);
            }
        }
        return $out;
    }

    /** This agent's agreed monthly salary, or 0.0 when they are commission-only. */
    public static function salaryFor(int $adminId): float
    {
        return self::salaryMap()[(string) $adminId] ?? 0.0;
    }

    /** Set (or clear, with 0) one agent's monthly salary. */
    public static function setSalary(int $adminId, float $amount, int $by = 0): void
    {
        if ($adminId <= 0) {
            throw new RuntimeException('Choose an agent first.');
        }
        $amount = round(max(0.0, $amount), 2);
        if ($amount > 10000000) {
            throw new RuntimeException('That salary looks wrong — check the amount.');
        }

        $map = self::salaryMap();
        $old = $map[(string) $adminId] ?? 0.0;
        if ($amount > 0) {
            $map[(string) $adminId] = $amount;
        } else {
            unset($map[(string) $adminId]);
        }

        Settings::set('agent_salaries', $map, 'json', 'agent', false);
        Logger::audit('agent.salary_set', 'admin', (string) $adminId,
            ['salary' => $old], ['salary' => $amount], 'by admin#' . $by);
    }

    /** Has this agent's salary for `$month` (YYYY-MM) already been posted? */
    public static function salaryPosted(int $adminId, string $month): bool
    {
        $row = Database::fetch(
            "SELECT id FROM agent_ledger
              WHERE agent_admin_id = :a AND ref = :r AND entry_type = 'adjustment'
              LIMIT 1",
            ['a' => $adminId, 'r' => self::SALARY_REF_PREFIX . $month]
        );
        return $row !== null;
    }

    /**
     * Credit one month's salary to an agent's commission account, so it is
     * paid out through the same "payout" flow as commission.
     *
     * Idempotent by an explicit check, NOT by the unique index: that index
     * is on (booking_id, entry_type) and booking_id is NULL here — MySQL
     * treats NULLs as distinct, so it would happily insert the same salary
     * twice and quietly double what the company owes.
     *
     * @param string $month YYYY-MM
     */
    public static function postSalary(int $adminId, string $month, int $by = 0): float
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new RuntimeException('Pick a month in YYYY-MM form.');
        }
        if ($month > date('Y-m')) {
            throw new RuntimeException('That month has not started yet.');
        }
        $amount = self::salaryFor($adminId);
        if ($amount <= 0) {
            throw new RuntimeException('No monthly salary is set for this agent.');
        }
        if (self::salaryPosted($adminId, $month)) {
            throw new RuntimeException('Salary for ' . $month . ' has already been posted for this agent.');
        }

        self::record($adminId, 'adjustment', $amount, [
            'account'   => 'commission',
            'direction' => 'credit',
            'note'      => 'Monthly salary ' . $month,
            'ref'       => self::SALARY_REF_PREFIX . $month,
            'by'        => $by,
        ]);

        return $amount;
    }

    /**
     * Salary credited to this agent so far, all months.
     * Read back off the ledger rather than recomputed, so it always agrees
     * with what was actually posted.
     */
    public static function salaryPaidTotal(int $adminId): float
    {
        $row = Database::fetch(
            "SELECT COALESCE(SUM(amount), 0) AS total FROM agent_ledger
              WHERE agent_admin_id = :a AND entry_type = 'adjustment'
                AND ref LIKE :r",
            ['a' => $adminId, 'r' => self::SALARY_REF_PREFIX . '%']
        );
        return round((float) ($row['total'] ?? 0), 2);
    }

    /* =================================================================
     *  AGENT SECURITY DEPOSIT
     *
     *  Before a counter agent starts selling, the company holds a security
     *  deposit from them (owner: "agent ko nsuruma company deposit hunu
     *  paryo"). This is the company's money-held-as-trust, refundable when
     *  the agent leaves — genuinely a THIRD kind of money, neither the
     *  agent's commission nor the cash they collected for us.
     *
     *  Kept ENTIRELY in a setting (`agent_deposits`, a JSON map of
     *  admin_id -> {required, paid}), NOT in agent_ledger. The ledger's
     *  `account` ENUM is only 'commission'|'cash'; putting a deposit on
     *  either would corrupt the wallet balances an agent is paid out on, and
     *  adding a third ENUM value means an ALTER TABLE on a live DB the owner
     *  migrates by hand. A setting needs no migration and cannot pollute the
     *  payout maths.
     * ================================================================= */

    /** @return array<string, array{required: float, paid: float}> */
    private static function depositMap(): array
    {
        $raw = Settings::getArray('agent_deposits', []);
        $out = [];
        foreach ($raw as $k => $v) {
            if (!is_array($v)) { continue; }
            $out[(string) (int) $k] = [
                'required' => round(max(0.0, (float) ($v['required'] ?? 0)), 2),
                'paid'     => round(max(0.0, (float) ($v['paid'] ?? 0)), 2),
            ];
        }
        return $out;
    }

    /** required + paid + shortfall for one agent. */
    public static function depositInfo(int $adminId): array
    {
        $row = self::depositMap()[(string) $adminId] ?? ['required' => 0.0, 'paid' => 0.0];
        $row['short'] = round(max(0.0, $row['required'] - $row['paid']), 2);
        $row['met']   = $row['required'] <= 0 || $row['paid'] + 0.001 >= $row['required'];
        return $row;
    }

    private static function saveDeposit(int $adminId, array $row, int $by, string $what): void
    {
        $map = self::depositMap();
        $map[(string) $adminId] = [
            'required' => round(max(0.0, (float) ($row['required'] ?? 0)), 2),
            'paid'     => round(max(0.0, (float) ($row['paid'] ?? 0)), 2),
        ];
        // Drop a fully-zero row so the setting does not grow forever.
        if ($map[(string) $adminId]['required'] <= 0 && $map[(string) $adminId]['paid'] <= 0) {
            unset($map[(string) $adminId]);
        }
        Settings::set('agent_deposits', $map, 'json', 'agent', false);
        Logger::audit('agent.' . $what, 'admin', (string) $adminId, null, $map[(string) $adminId] ?? [], 'by admin#' . $by);
    }

    /** Set the agreed deposit the company expects from this agent. */
    public static function setDepositRequired(int $adminId, float $amount, int $by = 0): void
    {
        if ($adminId <= 0) { throw new RuntimeException('Choose an agent first.'); }
        $amount = round(max(0.0, $amount), 2);
        if ($amount > 10000000) { throw new RuntimeException('That deposit looks wrong — check the amount.'); }
        $info = self::depositInfo($adminId);
        self::saveDeposit($adminId, ['required' => $amount, 'paid' => $info['paid']], $by, 'deposit_required');
    }

    /** Record that the agent handed the company more deposit money. */
    public static function recordDeposit(int $adminId, float $amount, int $by = 0): float
    {
        if ($adminId <= 0) { throw new RuntimeException('Choose an agent first.'); }
        $amount = round($amount, 2);
        if ($amount == 0.0) { throw new RuntimeException('Enter an amount (use a minus figure to refund).'); }
        $info = self::depositInfo($adminId);
        $newPaid = round($info['paid'] + $amount, 2);
        if ($newPaid < 0) { throw new RuntimeException('That is more than the ' . inr($info['paid']) . ' deposit on file.'); }
        self::saveDeposit($adminId, ['required' => $info['required'], 'paid' => $newPaid], $by, $amount > 0 ? 'deposit_received' : 'deposit_refunded');
        return $newPaid;
    }

    public static function record(int $agentId, string $entryType, float $amount, array $meta = []): int
    {
        $amount = round(abs($amount), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Enter an amount greater than zero.');
        }
        if ($agentId <= 0) {
            throw new RuntimeException('Choose an agent first.');
        }

        [$account, $sign] = match ($entryType) {
            'payout'        => ['commission', -1],   // we paid the agent
            'cash_handover' => ['cash',       -1],   // the agent paid us
            'commission'    => ['commission', +1],   // manual credit
            'cash_due'      => ['cash',       +1],   // manual debit
            'adjustment'    => [
                ($meta['account'] ?? 'commission') === 'cash' ? 'cash' : 'commission',
                (($meta['direction'] ?? 'credit') === 'debit' ? -1 : +1),
            ],
            default => throw new RuntimeException('Unknown wallet entry type.'),
        };

        if ($entryType === 'payout') {
            $due = self::balances($agentId)['commission'];
            if ($amount > $due + 0.001) {
                throw new RuntimeException('That is more than the ' . inr($due) . ' currently owed to this agent.');
            }
        }
        if ($entryType === 'cash_handover') {
            $held = self::balances($agentId)['cash'];
            if ($amount > $held + 0.001) {
                throw new RuntimeException('That is more than the ' . inr($held) . ' this agent is currently holding.');
            }
        }

        $id = self::insertEntry([
            'agent_admin_id' => $agentId,
            'booking_id'     => null,
            'account'        => $account,
            'entry_type'     => $entryType,
            'amount'         => $sign * $amount,
            'note'           => Security::clean((string) ($meta['note'] ?? ''), 255),
            'ref'            => Security::clean((string) ($meta['ref'] ?? ''), 80),
            'created_by'     => (int) ($meta['by'] ?? 0) ?: null,
        ]);

        Logger::audit('agent.' . $entryType, 'admin', (string) $agentId, null,
            ['amount' => $sign * $amount, 'account' => $account], (string) ($meta['note'] ?? ''));

        return $id;
    }

    /**
     * Record an ADVANCE paid to an agent against FUTURE commission (Point 7 —
     * "treat the agent like an employee"). Modelled as a DEBIT on the
     * commission account tagged ref 'ADVANCE', so the existing balances() SUM
     * nets it automatically: every commission the agent later earns lifts the
     * balance back toward zero, and the advance is "settled" the moment the
     * commission balance is no longer negative. Deliberately UNCAPPED — an
     * advance is precisely money handed over BEFORE it has been earned, so
     * unlike a payout it is allowed to exceed the commission owed to date.
     *
     * A POSITIVE $amount records an advance GIVEN (debit); a NEGATIVE $amount
     * records a repayment / correction the agent returns in cash (credit).
     *
     * @param array{note?:string,ref?:string,by?:int} $meta
     */
    public static function recordAdvance(int $agentId, float $amount, array $meta = []): int
    {
        $amount = round($amount, 2);
        if ($amount == 0.0) {
            throw new RuntimeException('Enter an advance amount (use a minus figure to record a repayment).');
        }
        if ($agentId <= 0) {
            throw new RuntimeException('Choose an agent first.');
        }
        $isAgent = (int) Database::scalar(
            "SELECT 1 FROM admins WHERE id = :i AND role = 'agent'", ['i' => $agentId], 0
        ) === 1;
        if (!$isAgent) {
            throw new RuntimeException('Advances can only be recorded for counter agents.');
        }

        // amount > 0 → advance GIVEN → commission DEBIT (−). amount < 0 → repayment → CREDIT (+).
        $signed  = -$amount;
        $extra   = Security::clean((string) ($meta['ref'] ?? ''), 60);
        $refTag  = 'ADVANCE' . ($extra !== '' ? ' ' . $extra : '');

        $id = self::insertEntry([
            'agent_admin_id' => $agentId,
            'booking_id'     => null,
            'account'        => 'commission',
            'entry_type'     => 'adjustment',
            'amount'         => $signed,
            'note'           => Security::clean((string) ($meta['note'] ?? ''), 255),
            'ref'            => $refTag,
            'created_by'     => (int) ($meta['by'] ?? 0) ?: null,
        ]);

        Logger::audit('agent.advance', 'admin', (string) $agentId, null,
            ['amount' => $signed, 'account' => 'commission',
             'kind'   => $amount > 0 ? 'advance_given' : 'advance_repaid'],
            (string) ($meta['note'] ?? ''));

        return $id;
    }

    /**
     * Advance figures for an agent's employee-style card (Point 7).
     *   given       — lifetime advance handed to the agent
     *   repaid       — advance the agent returned in cash (rare)
     *   outstanding  — advance not yet recovered from earnings = the amount by
     *                  which the commission balance is currently negative
     *                  (only an advance debit can push it below zero, since a
     *                  payout is capped at the balance owed)
     *   netCommission— the live commission balance (already advance-adjusted)
     *
     * @return array{given: float, repaid: float, outstanding: float, netCommission: float}
     */
    public static function advanceSummary(int $adminId): array
    {
        $out = ['given' => 0.0, 'repaid' => 0.0, 'outstanding' => 0.0, 'netCommission' => 0.0];
        try {
            $r = Database::fetch(
                "SELECT
                    COALESCE(SUM(CASE WHEN amount < 0 THEN -amount END), 0) AS given,
                    COALESCE(SUM(CASE WHEN amount > 0 THEN  amount END), 0) AS repaid
                   FROM agent_ledger
                  WHERE agent_admin_id = :a AND entry_type = 'adjustment'
                    AND account = 'commission' AND ref LIKE 'ADVANCE%'",
                ['a' => $adminId]
            );
            $out['given']  = round((float) ($r['given'] ?? 0), 2);
            $out['repaid'] = round((float) ($r['repaid'] ?? 0), 2);
        } catch (Throwable $e) {
            Logger::warning('AgentWallet advanceSummary unavailable: ' . $e->getMessage(), [], 'agent');
        }
        $net = self::balances($adminId)['commission'];
        $out['netCommission'] = $net;
        $out['outstanding']   = $net < 0 ? round(-$net, 2) : 0.0;
        return $out;
    }

    /**
     * The one place a ledger row is written. INSERT IGNORE so the
     * UNIQUE(booking_id, entry_type) index silently absorbs a repeat
     * rather than throwing into the middle of a booking transaction.
     *
     * @param array<string, mixed> $row
     */
    private static function insertEntry(array $row): int
    {
        $row += ['created_by' => null, 'note' => '', 'ref' => ''];
        $row['note'] = mb_substr((string) $row['note'], 0, 255);
        $row['ref']  = mb_substr((string) $row['ref'], 0, 80);

        return Database::insertIgnore('agent_ledger', $row);
    }


    /* =================================================================
     *  Reading the wallet
     * ================================================================= */

    /**
     * Both balances for one agent.
     *
     * @return array{commission: float, cash: float}
     */
    public static function balances(int $adminId): array
    {
        $out = ['commission' => 0.0, 'cash' => 0.0];

        try {
            foreach (Database::fetchAll(
                'SELECT account, COALESCE(SUM(amount), 0) AS bal
                   FROM agent_ledger WHERE agent_admin_id = :a GROUP BY account',
                ['a' => $adminId]
            ) as $r) {
                $out[(string) $r['account']] = round((float) $r['bal'], 2);
            }
        } catch (Throwable $e) {
            Logger::warning('AgentWallet balances unavailable: ' . $e->getMessage(), [], 'agent');
        }

        return $out;
    }

    /**
     * Lifetime and this-month figures behind the two balances, so the
     * panel can show "earned 12,400 · paid 9,000 · due 3,400" rather than
     * a single number nobody can check.
     *
     * @return array<string, float>
     */
    public static function summary(int $adminId): array
    {
        $zero = ['earned' => 0.0, 'paidOut' => 0.0, 'collected' => 0.0,
                 'handedOver' => 0.0, 'earnedMonth' => 0.0, 'reversed' => 0.0];

        try {
            $r = Database::fetch(
                "SELECT
                    COALESCE(SUM(CASE WHEN entry_type = 'commission'      THEN amount END), 0) AS earned,
                    COALESCE(SUM(CASE WHEN entry_type = 'commission_void' THEN -amount END), 0) AS reversed,
                    COALESCE(SUM(CASE WHEN entry_type = 'payout'          THEN -amount END), 0) AS paidOut,
                    COALESCE(SUM(CASE WHEN entry_type = 'cash_due'        THEN amount END), 0) AS collected,
                    COALESCE(SUM(CASE WHEN entry_type = 'cash_handover'   THEN -amount END), 0) AS handedOver,
                    COALESCE(SUM(CASE WHEN entry_type = 'commission'
                                       AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                      THEN amount END), 0) AS earnedMonth
                   FROM agent_ledger WHERE agent_admin_id = :a",
                ['a' => $adminId]
            );
        } catch (Throwable $e) {
            return $zero;
        }

        if ($r === null) {
            return $zero;
        }

        $out = [];
        foreach ($zero as $k => $_) {
            $out[$k] = round((float) ($r[$k] ?? 0), 2);
        }

        return $out;
    }

    /**
     * Recent ledger rows for one agent, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function entries(int $adminId, int $limit = 40, string $account = ''): array
    {
        $limit  = max(1, min(500, $limit));
        $where  = 'l.agent_admin_id = :a';
        $params = ['a' => $adminId];

        if (in_array($account, ['commission', 'cash'], true)) {
            $where .= ' AND l.account = :acc';
            $params['acc'] = $account;
        }

        try {
            return Database::fetchAll(
                "SELECT l.*, b.pnr, a.full_name AS by_name
                   FROM agent_ledger l
                   LEFT JOIN bookings b ON b.id = l.booking_id
                   LEFT JOIN admins   a ON a.id = l.created_by
                  WHERE $where
                  ORDER BY l.id DESC
                  LIMIT " . $limit,
                $params
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Ledger rows for an agent within an inclusive [$from, $to] day window,
     * OLDEST first — the shape a printable / CSV statement wants (Point 6:
     * "a clear statement per agent, like a mini bank statement"). Dates are
     * Y-m-d; $to covers the whole day. Uses the ix_ledger_agent index. Every
     * named placeholder is bound once (this PDO does not emulate prepares).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function entriesBetween(int $adminId, string $from, string $to, string $account = ''): array
    {
        $where  = 'l.agent_admin_id = :a AND l.created_at >= :f AND l.created_at < :t';
        $params = [
            'a' => $adminId,
            'f' => $from . ' 00:00:00',
            't' => date('Y-m-d', (int) strtotime($to . ' +1 day')) . ' 00:00:00',
        ];
        if (in_array($account, ['commission', 'cash'], true)) {
            $where .= ' AND l.account = :acc';
            $params['acc'] = $account;
        }
        try {
            return Database::fetchAll(
                "SELECT l.*, b.pnr, a.full_name AS by_name
                   FROM agent_ledger l
                   LEFT JOIN bookings b ON b.id = l.booking_id
                   LEFT JOIN admins   a ON a.id = l.created_by
                  WHERE $where
                  ORDER BY l.id ASC
                  LIMIT 5000",
                $params
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Every agent with their two balances — the office's settlement view.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function roster(): array
    {
        $agents = Database::fetchAll(
            "SELECT id, username, full_name, role, is_active, last_login_at
               FROM admins WHERE role = 'agent' ORDER BY is_active DESC, full_name, username"
        );

        foreach ($agents as &$a) {
            $id            = (int) $a['id'];
            $a['balances'] = self::balances($id);
            $a['summary']  = self::summary($id);
            $a['profile']  = self::profile($id);
            $a['pct']      = self::commissionPercentFor($id);
        }
        unset($a);

        return $agents;
    }


    /* =================================================================
     *  Physical (paper) ticket register
     * ================================================================= */

    /**
     * Record a ticket the agent sold on paper, away from the system.
     *
     * Two shapes, both valid:
     *
     *   with seats  — a real confirmed booking is created through
     *                 BookingService::counterSale(), so seat inventory,
     *                 the manifest and the QR ticket all stay truthful.
     *                 Commission accrues from that booking, not from here.
     *
     *   no seats    — a register-only sale. Nothing touches seat
     *                 inventory; commission (and cash, when it was a cash
     *                 sale) is written straight to the ledger against the
     *                 paper ticket number.
     *
     * @param array<string, mixed> $data
     * @return array{id: int, pnr: string, commission: float, mode: string}
     */
    public static function recordOfflineTicket(array $data, int $createdBy): array
    {
        if (!self::offlineTicketsEnabled()) {
            throw new RuntimeException('Offline ticket entry is switched off in Settings.');
        }

        $agentId = (int) ($data['agentId'] ?? 0);
        if ($agentId <= 0) {
            throw new RuntimeException('Choose which agent sold this ticket.');
        }

        $paperNo = strtoupper(Security::clean((string) ($data['paperNo'] ?? ''), 60));
        if ($paperNo === '') {
            throw new RuntimeException('Enter the number printed on the paper ticket.');
        }

        $name = Security::clean((string) ($data['name'] ?? ''), 120);
        if ($name === '') {
            throw new RuntimeException('Enter the passenger name.');
        }

        $travelDate = Security::clean((string) ($data['travelDate'] ?? ''), 10);
        if (!Security::isValidDate($travelDate)) {
            throw new RuntimeException('Enter a valid journey date.');
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Enter the fare that was collected.');
        }

        $routeId     = (int) ($data['routeId'] ?? 0);
        $phone       = normalisePhone((string) ($data['phone'] ?? ''));
        $paymentMode = in_array($data['paymentMode'] ?? '', ['cash', 'upi', 'other'], true)
            ? (string) $data['paymentMode'] : 'cash';
        $note = Security::clean((string) ($data['note'] ?? ''), 255);

        $seats = array_values(array_filter(array_map(
            static fn($s): string => strtoupper(trim((string) $s)),
            (array) ($data['seats'] ?? [])
        ), static fn(string $s): bool => $s !== ''));

        // Passenger count is the unit the flat commission is paid on, so it
        // has to be settled before commission is worked out — not derived a
        // second, possibly different way when the row is inserted.
        $paxCount = max(1, (int) ($data['paxCount'] ?? max(1, count($seats))));

        // Refuse the duplicate up front so the agent gets a clear message
        // instead of a raw constraint violation from deep inside a booking.
        if (Database::exists(
            'SELECT 1 FROM offline_tickets WHERE agent_admin_id = :a AND paper_ticket_no = :p',
            ['a' => $agentId, 'p' => $paperNo]
        )) {
            throw new RuntimeException('Paper ticket ' . $paperNo . ' has already been entered for this agent.');
        }

        $bookingId = null;
        $pnr       = '';
        $mode      = 'register';

        if ($seats !== []) {
            if ($routeId <= 0) {
                throw new RuntimeException('Choose the route before naming seats.');
            }
            $route = Database::fetch('SELECT * FROM routes WHERE id = :i LIMIT 1', ['i' => $routeId]);
            if ($route === null) {
                throw new RuntimeException('That route no longer exists.');
            }

            $schedule = Seats::schedule($routeId, $travelDate);
            $booking  = BookingService::counterSale($route, (int) $schedule['id'], $travelDate, $seats, [
                'name'          => $name,
                'phone'         => $phone,
                'gender'        => $data['gender'] ?? null,
                'amount'        => $amount,
                'paymentMethod' => $paymentMode === 'cash' ? 'cash' : ($paymentMode === 'upi' ? 'upi' : 'cash'),
                'note'          => 'Paper ticket ' . $paperNo . ($note !== '' ? ' · ' . $note : ''),
            ], $agentId, 'agent');

            $bookingId = (int) $booking['id'];
            $pnr       = (string) $booking['pnr'];
            $mode      = 'booking';
        }

        // Commission must come from the SAME flat ₹200/₹400-per-passenger
        // engine as every other sale (accrue → commissionForBooking). The
        // old percent engine (commissionOn, default 5%) paid a register-only
        // paper ticket roughly half of what a seated sale earned, and made
        // the stored figure below disagree with the ledger the agent is
        // actually paid from. commissionForBooking still honours a per-agent
        // override or the company 'percent' mode, so the escape hatch stays.
        //
        // For a SEATED sale the wallet was already credited by
        // counterSale → accrue(), which pays on the LIVE seat rows — so use
        // that same count for the stored figure, or the register table and the
        // ledger disagree when the entered passenger count differs from the
        // seats actually named. A register-only sale has no seats and uses the
        // entered passenger count.
        $commSeats  = $bookingId !== null ? self::seatCountFor($bookingId) : $paxCount;
        $commission = self::commissionForBooking($amount, $agentId, max(1, $commSeats));

        $id = Database::insert('offline_tickets', [
            'agent_admin_id'  => $agentId,
            'paper_ticket_no' => $paperNo,
            'booking_id'      => $bookingId,
            'route_id'        => $routeId ?: null,
            'travel_date'     => $travelDate,
            'passenger_name'  => $name,
            'passenger_phone' => $phone !== '' ? $phone : null,
            'seat_text'       => $seats !== [] ? implode(', ', $seats) : Security::clean((string) ($data['seatText'] ?? ''), 120),
            'pax_count'       => $paxCount,
            'amount'          => $amount,
            'commission'      => $commission,
            'payment_mode'    => $paymentMode,
            'note'            => $note !== '' ? $note : null,
            'created_by'      => $createdBy ?: null,
        ]);

        // A real booking already credited the agent through accrue(); only
        // the register-only shape needs its own ledger rows, or the agent
        // would be paid twice for the same ticket.
        if ($bookingId === null && self::enabled()) {
            if ($commission > 0) {
                self::insertEntry([
                    'agent_admin_id' => $agentId,
                    'booking_id'     => null,
                    'account'        => 'commission',
                    'entry_type'     => 'commission',
                    'amount'         => $commission,
                    'note'           => 'Paper ticket ' . $paperNo . ' · ' . $name
                                        . ' (' . self::commissionNoteFor($agentId, $paxCount, $amount) . ')',
                    'ref'            => $paperNo,
                    'created_by'     => $createdBy ?: null,
                ]);
            }
            if ($paymentMode === 'cash') {
                self::insertEntry([
                    'agent_admin_id' => $agentId,
                    'booking_id'     => null,
                    'account'        => 'cash',
                    'entry_type'     => 'cash_due',
                    'amount'         => $amount,
                    'note'           => 'Cash for paper ticket ' . $paperNo,
                    'ref'            => $paperNo,
                    'created_by'     => $createdBy ?: null,
                ]);
            }
        }

        Logger::audit('agent.offline_ticket', 'admin', (string) $agentId, null,
            ['paper' => $paperNo, 'amount' => $amount, 'pnr' => $pnr], $mode);

        return ['id' => $id, 'pnr' => $pnr, 'commission' => $commission, 'mode' => $mode];
    }

    /**
     * Paper tickets recorded by (or for) one agent. Pass 0 for every agent.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function offlineTickets(int $agentId = 0, int $limit = 50): array
    {
        $limit  = max(1, min(500, $limit));
        $where  = '1 = 1';
        $params = [];

        if ($agentId > 0) {
            $where           = 'o.agent_admin_id = :a';
            $params['a']     = $agentId;
        }

        try {
            return Database::fetchAll(
                "SELECT o.*, r.from_city, r.to_city, a.full_name AS agent_name, b.pnr
                   FROM offline_tickets o
                   LEFT JOIN routes   r ON r.id = o.route_id
                   LEFT JOIN admins   a ON a.id = o.agent_admin_id
                   LEFT JOIN bookings b ON b.id = o.booking_id
                  WHERE $where
                  ORDER BY o.id DESC
                  LIMIT " . $limit,
                $params
            );
        } catch (Throwable $e) {
            return [];
        }
    }
}
