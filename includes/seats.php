<?php
/**
 * =====================================================================
 *  Seat engine
 *
 *  Generates the seat map, resolves schedules, and owns the temporary
 *  seat-hold mechanism used while a visitor is at checkout.
 *
 *  Availability is decided here and nowhere else. The browser is told
 *  which seats are free, but it is never trusted about it.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Seats
{
    /**
     * Seat identifiers for a coach — the port of seatIdsFor().
     *
     *   sleeper + sharing  -> L1..L36, U1..U36   (72 berths)
     *   sleeper + private  -> L1..L18, U1..U18   (36 berths, wider cabins)
     *   seater             -> 1A..10D            (40 seats)
     *
     * @return array<int, string>
     */
    public static function seatIds(string $coachType, string $bookingType = 'sharing'): array
    {
        $ids = [];

        if ($coachType === 'sleeper') {
            // Counts now DERIVE from seat_mode_map (8 Sep 2026) rather than the
            // old `$perDeck = $bookingType === 'private' ? 18 : 36` literal, so
            // a mode's seat count and its bed mapping cannot drift apart: 36
            // canonical beds ÷ 2 beds per cabin IS the 18 private cabins.
            // Private went 15 → 18/deck (owner, 30 Aug 2026) because the coach
            // has SIX rows per deck; showing five made a row vanish on toggle.
            // Old private bookings (L1..L15/U1..U15) stay valid — a subset.
            // ⚠️ The canonical perDeck MUST still equal SHARING_PER_DECK in
            // assets/js/06-results.js in the SAME deploy: the client renders the
            // berths and this validates them, and the bundle does not yet read
            // this rule set. A mismatch hides sold berths or offers berths the
            // bus does not have.
            $spec    = self::modeMap($coachType);
            $perDeck = intdiv((int) $spec['perDeck'], self::bedsPerLabel($coachType, $bookingType));

            foreach (($spec['decks'] ?? ['L', 'U']) as $deck) {
                for ($row = 1; $row <= $perDeck; $row++) {
                    $ids[] = $deck . $row;
                }
            }

            return $ids;
        }

        for ($row = 1; $row <= 10; $row++) {
            foreach (['A', 'B', 'C', 'D'] as $column) {
                $ids[] = $row . $column;
            }
        }

        return $ids;
    }

    /**
     * Physical seat geometry for a coach — the single source of truth every
     * seat renderer (customer JS, admin seatmap, trip dashboard, reschedule)
     * consumes so the visual layout is identical everywhere. The seat labels
     * returned in `seatIds` are exactly what `Seats::seatIds()` would return
     * for the same arguments — this method never invents new berth IDs.
     *
     *   sleeper + sharing  ->  6 rows/deck × (4 left | aisle | 2 right) = 72
     *   sleeper + private  ->  6 rows/deck × (2 left | aisle | 1 right) = 36
     *   seater             -> 10 rows × (2 left | aisle | 2 right)     = 40
     *
     * A "row" is one physical row across the coach. `cabinKey` is the
     * shared-cabin key used by the gender-lock engine (unitKey(seat)) — two
     * neighbouring berths share a cabin, so a 6-berth sharing row contains
     * three cabins. Renderers group by cabinKey when they need to draw a box
     * (customer cabin cards); ignore it when they just need the grid (admin).
     *
     * @return array{
     *   seatIds: array<int, string>,
     *   perDeck: int,
     *   perRow:  int,
     *   decks: array<int, array{
     *     key: string,
     *     label: string,
     *     rows: array<int, array{
     *       rowKey: string,
     *       label:  string,
     *       left:   array<int, string>,
     *       aisle:  bool,
     *       right:  array<int, string>,
     *     }>,
     *   }>,
     * }
     */
    public static function layoutFor(string $coachType, string $bookingType = 'sharing'): array
    {
        $seatIds = self::seatIds($coachType, $bookingType);

        if ($coachType === 'sleeper') {
            // Sharing: 4+2 across, 6 rows/deck. Private: 2+1 across, 6 rows/deck
            // (raised from 5 on 30 Aug 2026 — both modes are the SAME physical
            // six-row coach). Every berth returned here also appears in
            // seatIds() for the same arguments — tests/seat-layout-test.php.
            // Same rule set as seatIds(), so "every berth in layoutFor also
            // appears in seatIds" (tests/seat-layout-test.php) holds for a
            // reconfigured coach too, not just for these two hard-coded shapes.
            $spec       = self::modeMap($coachType);
            $modeRule   = $spec['modes'][$bookingType] ?? $spec['modes'][$spec['canonical'] ?? 'sharing'] ?? [];
            $perDeck    = intdiv((int) $spec['perDeck'], self::bedsPerLabel($coachType, $bookingType));
            $across     = $modeRule['across'] ?? [4, 2];
            $leftCols   = max(1, (int) ($across[0] ?? 4));
            $rightCols  = max(0, (int) ($across[1] ?? 2));

            $perRow      = $leftCols + $rightCols;
            $rowsPerDeck = intdiv($perDeck, $perRow);

            $decks = [];
            foreach ([['L', self::floorName('L')], ['U', self::floorName('U')]] as $pair) {
                [$prefix, $label] = $pair;
                $rows = [];
                for ($r = 1; $r <= $rowsPerDeck; $r++) {
                    $base  = ($r - 1) * $perRow;
                    $left  = [];
                    $right = [];
                    for ($i = 0; $i < $leftCols; $i++) {
                        $left[] = $prefix . ($base + $i + 1);
                    }
                    for ($i = 0; $i < $rightCols; $i++) {
                        $right[] = $prefix . ($base + $leftCols + $i + 1);
                    }
                    $rows[] = [
                        'rowKey' => $prefix . '-row-' . $r,
                        'label'  => 'Row ' . self::rowLetter($r - 1),   // same letter the berths carry (A1..A6 / A7..A12)
                        'left'   => $left,
                        'aisle'  => true,
                        'right'  => $right,
                    ];
                }
                $decks[] = [
                    'key'   => $prefix,
                    'label' => $label,
                    'rows'  => $rows,
                ];
            }

            return [
                'seatIds' => $seatIds,
                'perDeck' => $perDeck,
                'perRow'  => $perRow,
                'decks'   => $decks,
            ];
        }

        // Seater — single deck: 10 rows × (A B | aisle | C D).
        $rows = [];
        for ($r = 1; $r <= 10; $r++) {
            $rows[] = [
                'rowKey' => 'M-row-' . $r,
                'label'  => 'Row ' . $r,
                'left'   => [$r . 'A', $r . 'B'],
                'aisle'  => true,
                'right'  => [$r . 'C', $r . 'D'],
            ];
        }

        return [
            'seatIds' => $seatIds,
            'perDeck' => 40,
            'perRow'  => 4,
            'decks'   => [[
                'key'   => 'M',
                'label' => 'Main Cabin',
                'rows'  => $rows,
            ]],
        ];
    }

    /**
     * Seats marked "female preferred" for a coach type.
     *
     * @return array<int, string>
     */
    public static function femaleSeats(string $coachType): array
    {
        $map = Settings::getArray('female_seats', [
            'seater'  => ['1A', '1B', '2A', '2B'],
            'sleeper' => ['L1', 'L2', 'L3'],
        ]);

        $seats = $map[$coachType] ?? [];

        return is_array($seats) ? array_values(array_map('strval', $seats)) : [];
    }

    /**
     * Seats permanently reserved for staff / emergency use (Master-prompt:
     * "Seat 1 reserved permanently — Staff/Emergency"). These are never sold
     * to a customer or an agent; only a super-admin may override and place a
     * booking on them. Configurable via the `staff_seats` setting, keyed by
     * coach type, so the reserved berth can be changed without code edits.
     *
     * @return array<int, string>
     */
    public static function staffSeats(string $coachType): array
    {
        // Owner decision 27 Aug 2026: the reserved pair is cabin L-3 (L5+L6);
        // L1 sells normally again (it stays female-preferred).
        $map = Settings::getArray('staff_seats', [
            'sleeper' => ['L5', 'L6'],
            'seater'  => ['1A'],
        ]);

        $seats = $map[$coachType] ?? [];

        return is_array($seats) ? array_values(array_map('strval', $seats)) : [];
    }

    /**
     * The union of every configured staff-reserved seat across all coach
     * types. Seat ids never collide between layouts (L1 is sleeper-only,
     * 1A is seater-only), so this is a safe membership set for the booking
     * chokepoints (lock / assertAvailable) that hold only a schedule id.
     *
     * @return array<int, string>
     */
    public static function staffSeatsAll(): array
    {
        $map = Settings::getArray('staff_seats', [
            'sleeper' => ['L5', 'L6'],
            'seater'  => ['1A'],
        ]);

        $out = [];
        foreach ($map as $list) {
            if (is_array($list)) {
                foreach ($list as $seat) {
                    $out[] = strtoupper((string) $seat);
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Emergency berths that are held back from normal online selection —
     * MODE-AWARE and ADDITIVE to staffSeats(). Owner decision 3 Sep 2026:
     *
     *   Private Sleeper -> L3   (the row-1 single berth)
     *   Sharing Sleeper -> L5,L6 already covered by staffSeats() — NOT repeated
     *                      here, so a sharing "L3" (a different physical berth
     *                      that merely shares the label) keeps selling normally.
     *
     * Config-driven via the `emergency_seats` setting, keyed by coach type and
     * then by booking mode, so the berth can be changed without code edits. A
     * per-mode map is read by the caller's mode; an UNKNOWN mode (null) returns
     * [] on purpose, so a mode-agnostic chokepoint never blocks the wrong
     * same-labelled berth. A flat list (no mode keys) applies to every mode.
     *
     * These are shown but not selectable to a customer/agent; only the same
     * super-admin path that overrides staff seats ($allowStaffSeats) can place
     * a booking on them.
     * // TODO: confirm exact override rule with the business owner before
     * // widening the hard block beyond super-admin.
     *
     * @return array<int, string>
     */
    public static function emergencySeats(string $coachType, ?string $bookingType = null): array
    {
        $map = Settings::getArray('emergency_seats', [
            'sleeper' => ['private' => ['L3']],
        ]);

        $forCoach = $map[$coachType] ?? [];
        if (!is_array($forCoach) || $forCoach === []) {
            return [];
        }

        // Associative (per-mode) vs flat list.
        $isPerMode = array_keys($forCoach) !== range(0, count($forCoach) - 1);
        if ($isPerMode) {
            if ($bookingType === null) {
                return [];
            }
            $seats = $forCoach[$bookingType] ?? [];
            return is_array($seats) ? array_values(array_map('strval', $seats)) : [];
        }

        return array_values(array_map('strval', $forCoach));
    }

    /**
     * Every seat id that physically exists on the coach running a schedule.
     *
     * The browser is never trusted about which seats exist: a stale or edited
     * client could ask for a berth the bus does not have (e.g. "L25" on a
     * 40-berth sleeper) and — without this check — it would be held, sold and
     * counted, overselling the coach. Both hold and sale are validated
     * against this list.
     *
     * The 'sharing' layout is used because it is the superset for every coach
     * (sleeper sharing 36/deck vs private 15/deck; seater is identical in
     * both), so every legitimate berth passes regardless of booking mode.
     *
     * @return array<int, string>
     */
    public static function seatIdsForSchedule(int $scheduleId): array
    {
        static $cache = [];

        if (isset($cache[$scheduleId])) {
            return $cache[$scheduleId];
        }

        // COALESCE (4 Sep 2026): an extra bus the office added may run a
        // different coach than the route normally does. NULL — every row the
        // seeder creates — returns the route's own type, exactly as before.
        $row = Database::fetch(
            'SELECT COALESCE(s.coach_type_override, r.coach_type) AS coach_type
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
              WHERE s.id = :id
              LIMIT 1',
            ['id' => $scheduleId]
        );

        $coach = (string) ($row['coach_type'] ?? 'sleeper');

        return $cache[$scheduleId] = self::seatIds($coach, 'sharing');
    }

    /**
     * Coach type ('sleeper'|'seater') for a schedule — used by the booking
     * chokepoints to resolve the mode-aware emergency berth. Cached per request.
     */
    public static function coachForSchedule(int $scheduleId): string
    {
        static $cache = [];
        if (isset($cache[$scheduleId])) {
            return $cache[$scheduleId];
        }
        $row = Database::fetch(
            'SELECT COALESCE(s.coach_type_override, r.coach_type) AS coach_type
               FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :id LIMIT 1',
            ['id' => $scheduleId]
        );
        return $cache[$scheduleId] = (string) ($row['coach_type'] ?? 'sleeper');
    }

    /**
     * The coach a given departure runs — its own override when the office set
     * one on that bus, otherwise the route's coach type. The single place the
     * fallback is spelled out, so a caller holding both rows never has to
     * remember the precedence.
     *
     * @param array<string,mixed>      $schedule a schedules row
     * @param array<string,mixed>|null $route    the routes row, when already loaded
     */
    public static function effectiveCoach(array $schedule, ?array $route = null): string
    {
        $override = (string) ($schedule['coach_type_override'] ?? '');
        if ($override === 'seater' || $override === 'sleeper') {
            return $override;
        }
        if ($route !== null && ($route['coach_type'] ?? '') !== '') {
            return (string) $route['coach_type'];
        }
        return self::coachForSchedule((int) ($schedule['id'] ?? 0));
    }

    /* =====================================================================
     *  PHYSICAL SEAT NAMESPACE — double-booking safety across booking modes
     *  (Task 4, owner ask 3 Sep 2026).
     *
     *  A sleeper coach is ONE physical bus sold two ways: SHARING (72 berths
     *  L1..L36 / U1..U36, 4+2 per row) and PRIVATE (36 wider cabins
     *  L1..L18 / U1..U18, 2+1 per row). The two label sets name DIFFERENT
     *  physical positions over the SAME beds, so a berth sold in one mode must
     *  block the physical bed(s) it occupies in the other, or the coach is
     *  oversold. The SHARING namespace is canonical (the superset), and:
     *
     *     private  Lj  ->  physical { L(2j-1), L(2j) }   (a private cabin = 2 beds)
     *     sharing / seater label -> itself (already physical)
     *     inverse: physical Ln  ->  private L(ceil(n/2))
     *
     *  Storage stays mode-labelled (so passengers, tickets and the gender lock
     *  are unchanged); only availability() and the booking chokepoints reason
     *  in physical space, which is where double-booking is actually decided.
     * ===================================================================== */

    /* ---------------------------------------------------------------------
     *  THE MAPPING IS A RULE SET, NOT A LITERAL  (owner ask, point 7, 8 Sep 2026)
     *
     *  The 1-cabin-to-2-beds ratio used to be a bare `2` in three methods here
     *  and two more in the browser bundle. That expressed exactly one coach.
     *  It now comes from `seat_mode_map` (Settings, stype=json), so a coach with
     *  a different ratio — or one irregular row — is a configuration change.
     *
     *  Shape, per coach type:
     *      "sleeper": {
     *        "canonical": "sharing",          // the namespace beds are named in
     *        "decks":     ["L","U"],
     *        "perDeck":   36,                 // CANONICAL beds per deck
     *        "modes": {
     *          "sharing": {"bedsPerLabel":1, "across":[4,2]},
     *          "private": {"bedsPerLabel":2, "across":[2,1],
     *                      "explicit": {"L18":["L35","L36","L37"]}}
     *        }
     *      }
     *
     *  `bedsPerLabel` drives the formula (label j -> beds b(j-1)+1 .. bj) and
     *  also the mode's own seat count (perDeck / bedsPerLabel), so the layout
     *  can never disagree with the mapping. `explicit` is the second tier a
     *  pure ratio cannot express — an irregular row — and WINS OVER THE FORMULA
     *  IN BOTH DIRECTIONS: physicalSeats() reads it directly, and the inverse
     *  index is built from it before any division is attempted.
     *
     *  TWO DELIBERATE SAFETY PROPERTIES:
     *
     *  1. The built-in default below IS the live geometry. A missing, empty or
     *     unparseable row therefore falls back to exactly what this bus does
     *     today — the silent-default failure mode cannot hand you a DIFFERENT
     *     coach. A row that is present but structurally wrong throws instead of
     *     being quietly ignored, because that one is a typo, not an absence.
     *
     *  2. Changing a ratio RE-INTERPRETS HISTORY. bookedPhysical() re-expands
     *     every stored label on every read, so moving sleeper from 2 to 3 beds
     *     would silently relocate an already-sold private L6 from beds L11/L12
     *     to L16..L18 — passengers on printed tickets would move, and the beds
     *     they actually occupy would go back on sale. mapChangeImpact() below
     *     exists to make that visible BEFORE a rule is saved; never edit a
     *     coach's ratio that already has sales without running it.
     * ------------------------------------------------------------------- */

    /** The live geometry, as a rule set. Also the fallback — see property 1. */
    private const DEFAULT_MODE_MAP = [
        'sleeper' => [
            'canonical' => 'sharing',
            'decks'     => ['L', 'U'],
            'perDeck'   => 36,
            'modes'     => [
                'sharing' => ['bedsPerLabel' => 1, 'across' => [4, 2]],
                'private' => ['bedsPerLabel' => 2, 'across' => [2, 1]],
            ],
        ],
    ];

    /** @var array<string, array<string, mixed>> memo — physicalSeats is per-seat hot */
    private static array $modeMapMemo = [];

    /**
     * Validated rule set for one coach type. Throws on a present-but-broken
     * configuration rather than falling back, so a typo cannot quietly reshape
     * a bus that is taking money.
     *
     * @return array<string, mixed>
     */
    public static function modeMap(string $coachType = 'sleeper'): array
    {
        $coachType = $coachType !== '' ? $coachType : 'sleeper';
        if (isset(self::$modeMapMemo[$coachType])) {
            return self::$modeMapMemo[$coachType];
        }

        $configured = Settings::getArray('seat_mode_map', []);
        $map        = self::DEFAULT_MODE_MAP;
        if ($configured !== []) {
            self::assertValidModeMap($configured);
            $map = $configured + $map;   // configured coaches win; unlisted keep the default
        }

        $entry = $map[$coachType] ?? self::DEFAULT_MODE_MAP['sleeper'];
        return self::$modeMapMemo[$coachType] = $entry;
    }

    /** Drop the memo — for tests and for a settings save inside one request. */
    public static function forgetModeMap(): void
    {
        self::$modeMapMemo = [];
    }

    /**
     * Structural validation. Every failure is a throw: this describes the
     * physical bus, and a half-understood rule set oversells it.
     *
     * @param array<string, mixed> $map
     */
    public static function assertValidModeMap(array $map): void
    {
        foreach ($map as $coach => $spec) {
            $where = "seat_mode_map[{$coach}]";
            if (!is_array($spec)) {
                throw new RuntimeException("{$where} must be an object.");
            }
            $perDeck = (int) ($spec['perDeck'] ?? 0);
            if ($perDeck < 1) {
                throw new RuntimeException("{$where}.perDeck must be a positive number of beds per deck.");
            }
            $decks = $spec['decks'] ?? [];
            if (!is_array($decks) || $decks === []) {
                throw new RuntimeException("{$where}.decks must list at least one deck prefix.");
            }
            foreach ($decks as $d) {
                if (!is_string($d) || !preg_match('/^[A-Z]$/', $d)) {
                    throw new RuntimeException("{$where}.decks entries must each be a single capital letter.");
                }
            }
            $modes = $spec['modes'] ?? [];
            if (!is_array($modes) || $modes === []) {
                throw new RuntimeException("{$where}.modes must describe at least one booking mode.");
            }
            $canonical = (string) ($spec['canonical'] ?? '');
            if ($canonical === '' || !isset($modes[$canonical])) {
                throw new RuntimeException("{$where}.canonical must name one of its own modes.");
            }
            if ((int) ($modes[$canonical]['bedsPerLabel'] ?? 0) !== 1) {
                throw new RuntimeException("{$where}: the canonical mode '{$canonical}' must have bedsPerLabel 1 — it IS the bed namespace.");
            }

            foreach ($modes as $mode => $rule) {
                $mw = "{$where}.modes[{$mode}]";
                if (!is_array($rule)) {
                    throw new RuntimeException("{$mw} must be an object.");
                }
                $bpl = (int) ($rule['bedsPerLabel'] ?? 0);
                if ($bpl < 1) {
                    throw new RuntimeException("{$mw}.bedsPerLabel must be 1 or more.");
                }
                if ($perDeck % $bpl !== 0) {
                    throw new RuntimeException("{$mw}.bedsPerLabel ({$bpl}) must divide perDeck ({$perDeck}) exactly, or the last cabin runs off the end of the deck.");
                }
                $seen = [];
                foreach ((array) ($rule['explicit'] ?? []) as $label => $beds) {
                    if (!is_array($beds) || $beds === []) {
                        throw new RuntimeException("{$mw}.explicit[{$label}] must list the beds it occupies.");
                    }
                    foreach ($beds as $bed) {
                        $bed = strtoupper((string) $bed);
                        if (isset($seen[$bed])) {
                            throw new RuntimeException("{$mw}.explicit: bed {$bed} is claimed by both {$seen[$bed]} and {$label} — one bed, one cabin.");
                        }
                        $seen[$bed] = (string) $label;
                    }
                }
            }
        }
    }

    /**
     * Which EXISTING sales a proposed rule set would relocate.
     *
     * bookedPhysical() re-expands every stored label on every read, so a ratio
     * change does not just apply to future sales — it silently rewrites where
     * every past one is. Take sleeper private from 2 beds to 3 and a sold "L6"
     * stops meaning beds L11/L12 and starts meaning L16..L18: the passenger
     * moves off the berth printed on their ticket, and the berth they are
     * actually lying on goes back on sale.
     *
     * This reports that damage instead of discovering it at a boarding gate.
     * Only future-dated, live bookings are considered — a trip that has already
     * run cannot be re-seated.
     *
     * @param  array<string, mixed> $proposed a full seat_mode_map value
     * @return array<int, array<string, mixed>> one row per booking that moves
     */
    public static function mapChangeImpact(array $proposed): array
    {
        self::assertValidModeMap($proposed);

        $rows = Database::fetchAll(
            "SELECT b.pnr, b.booking_mode, bs.seat_no, l.travel_date, r.coach_type
               FROM booking_seats bs
               JOIN bookings b  ON b.id = bs.booking_id
               JOIN booking_legs l ON l.id = bs.leg_id
               JOIN schedules s ON s.id = bs.schedule_id
               JOIN routes r    ON r.id = s.route_id
              WHERE bs.released_at IS NULL
                AND b.status IN ('pending','confirmed')
                AND l.travel_date >= CURDATE()"
        );

        $moved = [];
        foreach ($rows as $r) {
            $coach = (string) ($r['coach_type'] ?? 'sleeper');
            $mode  = (string) ($r['booking_mode'] ?? 'sharing');
            $seat  = (string) $r['seat_no'];

            $before = self::physicalSeats($seat, $mode, $coach);

            // Resolve the same seat under the PROPOSED rules, without disturbing
            // the live memo the rest of this request is using.
            $keep               = self::$modeMapMemo;
            self::$modeMapMemo  = [];
            $spec               = ($proposed + self::DEFAULT_MODE_MAP)[$coach] ?? self::DEFAULT_MODE_MAP['sleeper'];
            self::$modeMapMemo[$coach] = $spec;
            $after              = self::physicalSeats($seat, $mode, $coach);
            self::$modeMapMemo  = $keep;

            if ($before !== $after) {
                $moved[] = [
                    'pnr'    => (string) $r['pnr'],
                    'date'   => (string) $r['travel_date'],
                    'seat'   => $seat,
                    'mode'   => $mode,
                    'coach'  => $coach,
                    'before' => $before,
                    'after'  => $after,
                ];
            }
        }
        return $moved;
    }

    /** bedsPerLabel for a coach+mode, 1 when the mode is unknown (identity). */
    private static function bedsPerLabel(string $coachType, string $bookingType): int
    {
        $modes = self::modeMap($coachType)['modes'] ?? [];
        return max(1, (int) ($modes[$bookingType]['bedsPerLabel'] ?? 1));
    }

    /** Physical (canonical-namespace) bed(s) a mode label occupies. */
    public static function physicalSeats(string $seatLabel, string $bookingType, string $coachType = 'sleeper'): array
    {
        $s     = strtoupper(trim($seatLabel));
        $modes = self::modeMap($coachType)['modes'] ?? [];
        $rule  = $modes[$bookingType] ?? null;
        if ($rule === null) {
            return [$s];                       // unknown mode: already physical
        }

        // An irregular cabin is spelled out, and beats the ratio.
        $explicit = $rule['explicit'] ?? [];
        if (isset($explicit[$s])) {
            return array_values(array_map(static fn($b): string => strtoupper((string) $b), (array) $explicit[$s]));
        }

        $beds = max(1, (int) ($rule['bedsPerLabel'] ?? 1));
        if ($beds === 1) {
            return [$s];                       // canonical namespace: identity
        }
        if (preg_match('/^([A-Z])(\d+)$/', $s, $m)) {
            $deck = $m[1];
            $j    = (int) $m[2];
            $out  = [];
            for ($k = $beds * ($j - 1) + 1; $k <= $beds * $j; $k++) {
                $out[] = $deck . $k;
            }
            return $out;
        }
        return [$s];
    }

    /** Expand a list of mode labels to the physical beds they occupy (unique). */
    public static function toPhysical(array $seats, string $bookingType, string $coachType = 'sleeper'): array
    {
        $out = [];
        foreach ($seats as $s) {
            foreach (self::physicalSeats((string) $s, $bookingType, $coachType) as $p) {
                $out[$p] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Collapse a physical bed to the label of the TARGET booking mode.
     *
     * The explicit table is inverted FIRST. If the ratio were tried first, a
     * bed inside an irregular cabin would divide down to the wrong neighbour's
     * label, and that cabin would then read as free while somebody is in it.
     */
    public static function physicalToMode(string $physicalSeat, string $bookingType, string $coachType = 'sleeper'): ?string
    {
        $s = strtoupper(trim($physicalSeat));
        if ($s === '') {
            return null;
        }
        $modes = self::modeMap($coachType)['modes'] ?? [];
        $rule  = $modes[$bookingType] ?? null;
        if ($rule === null) {
            return $s;
        }

        foreach ((array) ($rule['explicit'] ?? []) as $label => $beds) {
            foreach ((array) $beds as $bed) {
                if (strtoupper((string) $bed) === $s) {
                    return (string) $label;
                }
            }
        }

        $per = max(1, (int) ($rule['bedsPerLabel'] ?? 1));
        if ($per > 1 && preg_match('/^([A-Z])(\d+)$/', $s, $m)) {
            return $m[1] . (int) ceil(((int) $m[2]) / $per);
        }
        return $s;
    }

    /** Translate a set of physical beds into the target mode's label set (unique). */
    public static function physicalSetToMode(array $physical, string $bookingType, string $coachType = 'sleeper'): array
    {
        $out = [];
        foreach ($physical as $p) {
            $label = self::physicalToMode((string) $p, $bookingType, $coachType);
            if ($label !== null) {
                $out[$label] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Every PHYSICAL bed occupied on a schedule, across ALL booking modes.
     * booking_seats stores each booking's own mode labels; this joins the
     * booking's mode and expands private cabins to their physical beds, so a
     * private sale and a sharing sale that share a bed both appear here. This
     * is the one true occupancy set the seat map and the booking gate use.
     *
     * @return array<int, string>
     */
    public static function bookedPhysical(int $scheduleId, bool $locking = false): array
    {
        // $locking (4 Sep 2026): inside the booking transaction the first
        // plain SELECT (Seats::schedule) fixes the REPEATABLE READ snapshot
        // BEFORE the schedule row is locked, so a cross-mode sale committed in
        // between stayed invisible on MariaDB 10.11. A locking read always
        // returns the latest committed rows; assertAvailable() asks for it.
        $rows = Database::fetchAll(
            'SELECT bs.seat_no, b.booking_mode
               FROM booking_seats bs
               JOIN bookings b ON b.id = bs.booking_id
              WHERE bs.schedule_id = :s AND bs.released_at IS NULL'
            . ($locking ? ' LOCK IN SHARE MODE' : ''),
            ['s' => $scheduleId]
        );
        // The rule set is PER COACH, so the coach has to be named or every bus
        // would be expanded with the sleeper's ratio. coachForSchedule() is
        // statically memoised, so this costs one query per schedule per request.
        $coach = self::coachForSchedule($scheduleId);
        $out   = [];
        foreach ($rows as $r) {
            $mode = (string) ($r['booking_mode'] ?? 'sharing');
            foreach (self::physicalSeats((string) $r['seat_no'], $mode, $coach) as $p) {
                $out[$p] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Find, or lazily create, the schedule row for a route on a date.
     *
     * Schedules are materialised on first demand rather than pre-generated
     * for every future date, which keeps the table small on shared hosting.
     *
     * @return array<string, mixed>
     */
    public static function schedule(int $routeId, string $travelDate): array
    {
        // slot = 1 is THE daily bus (5 Sep 2026, Bus Calendar). Extra buses the
        // office adds for a date carry slot 2+ and are addressed by their own
        // id (scheduleById) — this lookup never returns one of them, so every
        // caller that thinks in "route + date" keeps its old, single answer.
        $existing = Database::fetch(
            'SELECT * FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 1 LIMIT 1',
            ['r' => $routeId, 'd' => $travelDate]
        );

        if ($existing !== null) {
            return $existing;
        }

        $route = Database::fetch('SELECT * FROM routes WHERE id = :id LIMIT 1', ['id' => $routeId]);

        if ($route === null) {
            throw new RuntimeException('Unknown route.');
        }

        $totalSeats = count(self::seatIds((string) $route['coach_type'], 'sharing'));

        // Which coach runs this date. With a rotation configured the fleet
        // takes turns (a bus that leaves today is only back on the third
        // day), so the answer depends on the DATE, not on the route. With
        // no rotation, the route's own bus stands exactly as before.
        $busId = Fleet::busForDate($travelDate) ?? $route['bus_id'];

        // insertIgnore absorbs the race where two visitors open the same
        // date at once — the UNIQUE(route_id, travel_date) key decides.
        Database::insertIgnore('schedules', [
            'route_id'    => $routeId,
            'travel_date' => $travelDate,
            'slot'        => 1,
            'bus_id'      => $busId,
            'total_seats' => $totalSeats,
            'status'      => 'scheduled',
        ]);

        $schedule = Database::fetch(
            'SELECT * FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 1 LIMIT 1',
            ['r' => $routeId, 'd' => $travelDate]
        );

        if ($schedule === null) {
            throw new RuntimeException('Schedule could not be created.');
        }

        return $schedule;
    }

    /**
     * One schedule row by id — the daily bus or an extra bus alike. Never
     * creates anything.
     *
     * @return array<string, mixed>
     */
    public static function scheduleById(int $scheduleId): array
    {
        $row = $scheduleId > 0
            ? Database::fetch('SELECT * FROM schedules WHERE id = :id LIMIT 1', ['id' => $scheduleId])
            : null;
        if ($row === null) {
            throw new RuntimeException('That departure no longer exists.');
        }
        return $row;
    }

    /**
     * Resolve the departure a request means: an explicit scheduleId (must
     * belong to this route + date) or the daily bus for route + date.
     *
     * @return array<string, mixed>
     */
    public static function scheduleFor(int $routeId, string $travelDate, ?int $scheduleId = null): array
    {
        if ($scheduleId !== null && $scheduleId > 0) {
            $row = self::scheduleById($scheduleId);
            if ((int) $row['route_id'] !== $routeId || (string) $row['travel_date'] !== $travelDate) {
                throw new RuntimeException('That departure does not match the chosen bus and date.');
            }
            return $row;
        }
        return self::schedule($routeId, $travelDate);
    }

    /**
     * Seats already sold on a schedule.
     *
     * @return array<int, string>
     */
    public static function bookedSeats(int $scheduleId): array
    {
        $rows = Database::fetchAll(
            'SELECT seat_no FROM booking_seats
              WHERE schedule_id = :s AND released_at IS NULL',
            ['s' => $scheduleId]
        );

        return array_map('strval', pluck($rows, 'seat_no'));
    }

    /**
     * Seats currently held by somebody at checkout — as PHYSICAL (sharing)
     * beds since 4 Sep 2026, so a private cabin hold and the sharing beds
     * under it block each other; translate with physicalSetToMode() before
     * showing them in another mode.
     *
     * @param string $excludeToken this visitor's own token — their holds
     *                             should still look selectable to them
     * @return array<int, string>
     */
    public static function lockedSeats(int $scheduleId, string $excludeToken = ''): array
    {
        $sql = 'SELECT seat_no FROM seat_locks
                 WHERE schedule_id = :s AND expires_at > NOW()';
        $params = ['s' => $scheduleId];

        if ($excludeToken !== '') {
            $sql .= ' AND lock_token <> :t';
            $params['t'] = $excludeToken;
        }

        return array_map('strval', pluck(Database::fetchAll($sql, $params), 'seat_no'));
    }

    /**
     * Full availability picture for the seat map UI.
     *
     * @return array{
     *   scheduleId: int, total: int, all: array<int, string>,
     *   booked: array<int, string>, locked: array<int, string>,
     *   available: array<int, string>, female: array<int, string>,
     *   availableCount: int
     * }
     */
    public static function availability(int $routeId, string $travelDate, string $bookingType = 'sharing', ?int $scheduleId = null): array
    {
        $route = Database::fetch('SELECT * FROM routes WHERE id = :id LIMIT 1', ['id' => $routeId]);

        if ($route === null) {
            throw new RuntimeException('Unknown route.');
        }

        // No expireLocks() sweep on this READ path any more (4 Sep 2026): a
        // search issued one DELETE per route. lock() and cron/expire.php still
        // sweep, and every reader below filters expires_at > NOW() anyway.
        // $scheduleId (5 Sep 2026) addresses an extra bus on the same date;
        // null keeps the daily bus exactly as before.
        $schedule  = self::scheduleFor($routeId, $travelDate, $scheduleId);
        return self::availabilityCore($route, $schedule, $bookingType);
    }

    /**
     * Availability for one schedule row (daily or extra bus) — the row says
     * which route and date it is.
     *
     * @return array<string, mixed>
     */
    public static function availabilityForSchedule(int $scheduleId, string $bookingType = 'sharing'): array
    {
        $schedule = self::scheduleById($scheduleId);
        $route    = Database::fetch('SELECT * FROM routes WHERE id = :id LIMIT 1', ['id' => (int) $schedule['route_id']]);
        if ($route === null) {
            throw new RuntimeException('Unknown route.');
        }
        return self::availabilityCore($route, $schedule, $bookingType);
    }

    /** @return array<string, mixed> */
    private static function availabilityCore(array $route, array $schedule, string $bookingType): array
    {
        $token     = Auth::lockToken();
        // The coach THIS departure runs (4 Sep 2026): an extra bus may carry
        // its own layout. Falls back to the route's type for every other row.
        $coachType = self::effectiveCoach($schedule, $route);

        $all     = self::seatIds($coachType, $bookingType);
        // Occupancy is decided in the canonical PHYSICAL (sharing) namespace and
        // then translated into the mode being shown, so a berth sold in the
        // OTHER mode still reads as booked here — the fix for cross-mode
        // double-booking (Task 4). Sharing/seater translation is identity; for
        // private a bed maps to its cabin (physical L5/L6 -> private L3).
        $booked  = self::physicalSetToMode(self::bookedPhysical((int) $schedule['id']), $bookingType, $coachType);
        // Holds are stored as physical beds (4 Sep 2026) and translated into
        // the shown mode like everything else, so a private picker sees cabin
        // L4 held while someone holds sharing L7, and vice-versa.
        $locked  = self::physicalSetToMode(self::lockedSeats((int) $schedule['id'], $token), $bookingType, $coachType);
        // Blocks and staff seats are stored canonically (sharing) — translate
        // them to the shown mode too so private sees its cabin dimmed.
        $blocked = self::physicalSetToMode(self::blockedSeats((int) $schedule['id']), $bookingType, $coachType);
        $staff   = self::physicalSetToMode(self::staffSeats($coachType), $bookingType, $coachType);
        // Mode-aware emergency berth (Private Sleeper -> L3). Additive to the
        // permanent staff pair; empty for sharing/seater so those maps are
        // unchanged. Already in the shown mode's namespace. See emergencySeats().
        $emergency = self::emergencySeats($coachType, $bookingType);

        // Staff/emergency berths are treated as unavailable to the public map,
        // exactly like an out-of-service block, so a customer can neither see
        // them free nor select them. A super-admin sells them from the admin
        // seat map, which reads adminSeatMap() rather than this method.
        $unavailable = array_unique(array_merge($booked, $locked, $blocked, $staff, $emergency));
        $available   = array_values(array_diff($all, $unavailable));

        return [
            'scheduleId'     => (int) $schedule['id'],
            'total'          => count($all),
            'all'            => $all,
            'booked'         => array_values($booked),
            'locked'         => array_values($locked),
            'blocked'        => array_values($blocked),
            'staff'          => $staff,
            'emergency'      => $emergency,
            // Booked here ONLY because the OTHER mode sold the bed(s) - the map
            // paints these 'taken as sharing / taken as a private cabin' rather
            // than plain sold (SHG AI BRAIN 1.3 / 1.4, 10 Sep 2026). Subset of booked.
            'crossMode'      => self::crossModeSeats((int) $schedule['id'], $bookingType, $coachType),
            'available'      => $available,
            'female'         => self::femaleSeats($coachType),
            'units'          => self::unitStates((int) $schedule['id'], $coachType, $booked),
            'availableCount' => count($available),
            // Geometry — every renderer should walk `layout.decks` rather than
            // hard-coding row/col counts locally, so a bus with a different
            // layout only has to change layoutFor() to reach every screen.
            'layout'         => self::layoutFor($coachType, $bookingType),
        ];
    }

    /**
     * Labels in the SHOWN mode that are occupied by a sale made in the OTHER
     * mode: a sharing bed whose cabin went private, or a private cabin one of
     * whose beds sold as sharing. Physical occupancy is unchanged — this is a
     * second channel of the same fact, so the customer sees WHY a half-empty
     * cabin is closed instead of a grey "booked" with no explanation.
     * Sleeper only (the seater has one mode). Always a subset of `booked`.
     *
     * @return array<int, string>
     */
    public static function crossModeSeats(int $scheduleId, string $bookingType, string $coachType = 'sleeper'): array
    {
        if ($coachType !== 'sleeper') {
            return [];
        }
        $rows = Database::fetchAll(
            'SELECT bs.seat_no, b.booking_mode
               FROM booking_seats bs
               JOIN bookings b ON b.id = bs.booking_id
              WHERE bs.schedule_id = :s AND bs.released_at IS NULL',
            ['s' => $scheduleId]
        );
        $out = [];
        foreach ($rows as $r) {
            $mode = (string) ($r['booking_mode'] ?? 'sharing') === 'private' ? 'private' : 'sharing';
            if ($mode === $bookingType) {
                continue;   // sold in the mode being shown: plain booked
            }
            foreach (self::physicalSeats((string) $r['seat_no'], $mode, $coachType) as $bed) {
                $label = self::physicalToMode($bed, $bookingType, $coachType);
                if ($label !== null) {
                    $out[$label] = true;
                }
            }
        }
        return array_keys($out);
    }

    /**
     * Hold seats for this visitor.
     *
     * Returns which seats were secured and which were taken in the
     * meantime, so the UI can show an honest message rather than
     * failing the whole selection.
     *
     * @param array<int, string> $seats
     * @return array{ok: bool, locked: array<int, string>, failed: array<int, string>, expiresAt: string}
     */
    public static function lock(int $scheduleId, array $seats, ?string $token = null, bool $allowStaffSeats = false, ?string $bookingType = null): array
    {
        $token ??= Auth::lockToken();

        self::expireLocks();

        $holdMinutes = Settings::getInt('seat_hold_minutes', SEAT_HOLD_MINUTES);
        $expiresAt   = date('Y-m-d H:i:s', time() + ($holdMinutes * 60));

        /* One visitor may hold one party's worth of seats. There was no cap
           at all — a browser could hold all 72 beds of a coach for the hold
           window (only a 120/min IP limit stood in the way), which is a
           denial-of-sale as much as a griefing vector. Staff (allowStaffSeats)
           get the counter party cap, customers the public one; seats already
           held by this token count towards it. */
        $seats   = array_values(array_unique(array_map(static fn($s): string => strtoupper(trim((string) $s)), $seats)));
        $partyCap = $allowStaffSeats
            ? max(1, Settings::getInt('counter_max_seats_per_booking', 20))
            : max(1, Settings::getInt('max_seats_per_booking', MAX_SEATS_BOOKING));
        $alreadyHeld = array_map('strval', array_column(Database::fetchAll(
            'SELECT seat_no FROM seat_locks WHERE schedule_id = :s AND lock_token = :t AND expires_at > NOW()',
            ['s' => $scheduleId, 't' => $token]
        ), 'seat_no'));
        $wouldHold = count(array_unique(array_merge($alreadyHeld, $seats)));
        if ($wouldHold > $partyCap) {
            throw new RuntimeException(
                'You can hold at most ' . $partyCap . ' seats at a time. / एक पटकमा बढीमा ' . $partyCap . ' सिट मात्र रोक्न मिल्छ।'
            );
        }

        $lockedNow = [];
        $failed    = [];

        // All occupancy is compared in the canonical PHYSICAL (sharing) namespace
        // (Task 4), so a hold is refused when the bed it occupies is already sold,
        // blocked or reserved in EITHER booking mode. Reserving is resolved in
        // physical space too, which fixes the earlier mode-agnostic check that
        // could wrongly block a legitimate private L5/L6 (physical L9..L12) just
        // because sharing L5/L6 are the reserved pair.
        $mode             = $bookingType ?? 'sharing';
        $occupiedPhysical = self::bookedPhysical($scheduleId);
        $blockedPhysical  = self::blockedSeats($scheduleId);
        $reservedPhysical = [];
        if (!$allowStaffSeats) {
            $reservedPhysical = array_values(array_unique(array_merge(
                self::staffSeatsAll(),
                self::toPhysical(self::emergencySeats(self::coachForSchedule($scheduleId), $bookingType), $mode, self::coachForSchedule($scheduleId))
            )));
        }
        // Labels must exist in the requested MODE and every bed they stand for
        // must exist on the coach (a private L19 would otherwise expand to
        // beds L37/L38 that no bus has).
        $coach    = self::coachForSchedule($scheduleId);
        $labelsOk = array_flip(self::seatIds($coach, $mode));
        $exists   = array_flip(self::seatIdsForSchedule($scheduleId));

        foreach ($seats as $seat) {
            $seat = strtoupper(Security::clean($seat, 10));

            if ($seat === '' || !Security::isValidSeat($seat)) {
                continue;
            }

            $beds = self::physicalSeats($seat, $mode, self::coachForSchedule($scheduleId));
            if (!isset($labelsOk[$seat]) || array_diff($beds, array_keys($exists)) !== []) {
                $failed[] = $seat;
                continue;
            }

            if (array_intersect($beds, $occupiedPhysical)
                || array_intersect($beds, $blockedPhysical)
                || array_intersect($beds, $reservedPhysical)) {
                $failed[] = $seat;
                continue;
            }

            /* HOLDS ARE STORED AS PHYSICAL BEDS (4 Sep 2026). A private cabin
               is two rows; they are acquired IN ORDER and the whole label is
               abandoned on the first bed somebody else owns, releasing the
               bed(s) already won. Ordered acquisition makes bed one the
               arbiter, so two visitors racing for one cabin cannot both roll
               back — and the UNIQUE key on (schedule_id, seat_no) now
               arbitrates across modes exactly as it does for booking_seats. */
            $won  = [];
            $lost = false;
            foreach ($beds as $bed) {
                // Refresh our own hold, or take a free / expired one.
                Database::query(
                    'INSERT INTO seat_locks (schedule_id, seat_no, lock_token, expires_at)
                          VALUES (:s, :seat, :t, :exp)
                     ON DUPLICATE KEY UPDATE
                          lock_token = IF(expires_at < NOW() OR lock_token = VALUES(lock_token),
                                          VALUES(lock_token), lock_token),
                          expires_at = IF(lock_token = VALUES(lock_token),
                                          VALUES(expires_at), expires_at)',
                    ['s' => $scheduleId, 'seat' => $bed, 't' => $token, 'exp' => $expiresAt]
                );

                // Confirm we actually own it now.
                $owner = Database::scalar(
                    'SELECT lock_token FROM seat_locks
                      WHERE schedule_id = :s AND seat_no = :seat AND expires_at > NOW()
                      LIMIT 1',
                    ['s' => $scheduleId, 'seat' => $bed]
                );
                if ($owner === $token) {
                    $won[] = $bed;
                } else {
                    $lost = true;
                    break;
                }
            }

            if ($lost) {
                if ($won !== []) {
                    self::deleteOwnBeds($scheduleId, $token, $won);
                }
                $failed[] = $seat;
            } else {
                $lockedNow[] = $seat;
            }
        }

        return [
            'ok'        => $failed === [],
            'locked'    => $lockedNow,
            'failed'    => $failed,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Delete this visitor's own rows for the given PHYSICAL beds — the
     * partial-cabin rollback in lock(). Never touches another token's rows.
     *
     * @param array<int, string> $beds
     */
    private static function deleteOwnBeds(int $scheduleId, string $token, array $beds): int
    {
        if ($beds === []) {
            return 0;
        }
        $placeholders = [];
        $params       = ['s' => $scheduleId, 't' => $token];
        foreach (array_values(array_unique($beds)) as $index => $bed) {
            $key            = 'bed' . $index;      // distinct names: no emulated prepares here
            $placeholders[] = ':' . $key;
            $params[$key]   = $bed;
        }
        return Database::delete(
            'seat_locks',
            'schedule_id = :s AND lock_token = :t AND seat_no IN (' . implode(', ', $placeholders) . ')',
            $params
        );
    }

    /**
     * Release specific holds owned by this visitor.
     *
     * @param array<int, string> $seats       mode labels as the client knows them
     * @param string|null        $bookingType 'sharing' | 'private' — the mode the
     *                                        labels were held in. Null means the
     *                                        labels ARE the beds (identity): never
     *                                        a union, or a sharing visitor releasing
     *                                        L4 would also lose L7/L8.
     */
    public static function release(int $scheduleId, array $seats, ?string $token = null, ?string $bookingType = null): int
    {
        $token ??= Auth::lockToken();
        $mode    = $bookingType ?? 'sharing';

        $beds = [];
        foreach ($seats as $seat) {
            $seat = strtoupper(Security::clean($seat, 10));
            if ($seat !== '' && Security::isValidSeat($seat)) {
                foreach (self::physicalSeats($seat, $mode, self::coachForSchedule($scheduleId)) as $bed) {
                    $beds[$bed] = true;
                }
            }
        }

        return self::deleteOwnBeds($scheduleId, $token, array_keys($beds));
    }

    /**
     * Drop every hold belonging to this visitor — called after checkout
     * completes or is abandoned.
     */
    public static function releaseAll(?string $token = null): int
    {
        $token ??= Auth::lockToken();

        return Database::delete('seat_locks', 'lock_token = :t', ['t' => $token]);
    }

    /**
     * Remove expired holds. Cheap, and safe to call on every request.
     */
    public static function expireLocks(): int
    {
        return Database::delete('seat_locks', 'expires_at < NOW()');
    }

    /**
     * Final pre-write check inside the booking transaction.
     *
     * Throws when any requested seat is unavailable, which rolls the
     * whole booking back rather than half-selling it.
     *
     * @param array<int, string> $seats
     * @throws RuntimeException
     */
    public static function assertAvailable(int $scheduleId, array $seats, string $token, bool $allowStaffSeats = false, ?string $bookingType = null): void
    {
        if ($seats === []) {
            throw new RuntimeException('No seats were selected.');
        }

        // Serialize concurrent sales on THIS schedule (Task 4). The cross-mode
        // physical check below reads committed occupancy; two overlapping
        // bookings in different modes have DIFFERENT labels, so the UNIQUE index
        // on booking_seats alone would not catch them — both could pass the read
        // and both claim(). Locking the schedule row first (inside the booking
        // transaction) makes the read+claim atomic per schedule; consistent lock
        // order (schedule row first) means it cannot deadlock with itself, and
        // Database::transaction() retries the rare lock-wait. Outside a
        // transaction this acquires and releases immediately — harmless.
        Database::fetchForUpdate('SELECT id FROM schedules WHERE id = :s', ['s' => $scheduleId]);

        // Everything below is decided in the canonical PHYSICAL (sharing)
        // namespace so a berth is judged by the bed(s) it occupies, not its
        // label — the core of the cross-mode double-booking fix (Task 4).
        $mode        = $bookingType ?? 'sharing';
        $coach       = self::coachForSchedule($scheduleId);
        $reqPhysical = self::toPhysical($seats, $mode, $coach);

        // A seat that does not exist on this coach can never be sold — this is
        // the last line of defence against overselling the bus if a client
        // offers berths the layout does not contain. Checked in the requested
        // MODE (a private L19 is not a cabin this bus has) and again for every
        // physical bed the label expands to. ($coach was resolved above, where
        // the request's labels are first expanded into beds.)
        $unknown = array_values(array_unique(array_merge(
            array_diff($seats, self::seatIds($coach, $mode)),
            self::physicalSetToMode(array_diff($reqPhysical, self::seatIdsForSchedule($scheduleId)), $mode, $coach)
        )));
        if ($unknown !== []) {
            throw new RuntimeException(
                'These seats do not exist on this bus: ' . self::displayLabels($unknown, $coach, $mode, ', ') . '. Please choose again.'
            );
        }

        // Staff/emergency berths can never be sold to a customer or agent. Only
        // a super-admin path sets $allowStaffSeats=true. Resolved in physical
        // space, so private L3 (physical L5/L6) is refused while a legitimate
        // private L5 (physical L9/L10) still sells.
        if (!$allowStaffSeats) {
            $reservedPhysical = array_values(array_unique(array_merge(
                self::staffSeatsAll(),
                self::toPhysical(self::emergencySeats(self::coachForSchedule($scheduleId), $bookingType), $mode, self::coachForSchedule($scheduleId))
            )));
            $clashStaff = array_values(array_intersect($reqPhysical, $reservedPhysical));
            if ($clashStaff !== []) {
                $shown = self::physicalSetToMode($clashStaff, $mode, $coach);
                throw new RuntimeException(
                    'Seat ' . self::displayLabels($shown, $coach, $mode, ', ') . ' is reserved for staff / emergency use and cannot be booked.'
                );
            }
        }

        // Cross-mode double-booking guard (Task 4): reject if ANY requested bed
        // is already occupied by a live booking in EITHER mode. bookedPhysical()
        // joins each booking's own mode and expands private cabins to their beds,
        // so a private sale and a sharing sale that share a bed can never both go
        // through. Authoritative sale gate — runs inside the booking transaction.
        // Locking read (4 Sep 2026): see bookedPhysical() — the transaction's
        // snapshot predates the schedule-row lock, so a plain read could miss
        // a cross-mode sale that committed a moment ago.
        $clashPhys   = array_values(array_intersect($reqPhysical, self::bookedPhysical($scheduleId, true)));
        if ($clashPhys !== []) {
            $shown = self::physicalSetToMode($clashPhys, $mode, $coach);
            throw new RuntimeException(
                'These seats have just been taken: ' . self::displayLabels($shown, $coach, $mode, ', ') . '. Please choose again.'
            );
        }

        // Out-of-service berths (Feature C) — blocks are stored canonically, so
        // compare in physical space too.
        $blockedPhys = array_values(array_intersect($reqPhysical, self::blockedSeats($scheduleId)));
        if ($blockedPhys !== []) {
            $shown = self::physicalSetToMode($blockedPhys, $mode, $coach);
            throw new RuntimeException(
                'These seats are out of service: ' . self::displayLabels($shown, $coach, $mode, ', ') . '. Please choose another seat.'
            );
        }

        // Anything held by somebody else is equally off limits. Holds are
        // physical beds (4 Sep 2026), so a private cabin request is refused
        // while a sharing visitor holds one of its beds, and vice-versa.
        $heldByOthers = self::lockedSeats($scheduleId, $token);
        $conflict     = array_values(array_intersect($reqPhysical, $heldByOthers));

        if ($conflict !== []) {
            $shown = self::physicalSetToMode($conflict, $mode, $coach);
            throw new RuntimeException(
                'Someone is currently booking these seats: ' . self::displayLabels($shown, $coach, $mode, ', ') . '. Please choose again.'
            );
        }
    }

    /**
     * Permanently claim seats as part of a confirmed booking.
     *
     * Relies on UNIQUE(schedule_id, seat_no) to reject a concurrent
     * double sale at the database level.
     *
     * @param array<int, string> $seats
     * @throws RuntimeException when a seat was claimed by someone else first
     */
    public static function claim(int $scheduleId, array $seats, int $bookingId, int $legId): void
    {
        foreach ($seats as $seat) {
            $inserted = Database::insertIgnore('booking_seats', [
                'schedule_id' => $scheduleId,
                'seat_no'     => $seat,
                'booking_id'  => $bookingId,
                'leg_id'      => $legId,
            ]);

            if ($inserted === 0) {
                // The row already existed — check whether it is ours or a
                // genuine collision with another booking.
                $ownerBooking = Database::scalar(
                    'SELECT booking_id FROM booking_seats
                      WHERE schedule_id = :s AND seat_no = :seat LIMIT 1',
                    ['s' => $scheduleId, 'seat' => $seat]
                );

                if ((int) $ownerBooking !== $bookingId) {
                    throw new RuntimeException(
                        'Seat ' . $seat . ' was booked by someone else moments ago. Please pick another seat.'
                    );
                }
            }
        }

        Database::query(
            'UPDATE schedules
                SET seats_booked = (
                    SELECT COUNT(*) FROM booking_seats
                     WHERE schedule_id = :s1 AND released_at IS NULL
                )
              WHERE id = :s2',
            ['s1' => $scheduleId, 's2' => $scheduleId]
        );
    }

    /**
     * Return seats to the pool when a booking is cancelled or rejected.
     */
    public static function releaseBooking(int $bookingId): int
    {
        // Capture the seats before deletion so we can recompute both the
        // schedule counts and the shared-cabin gender locks they touched.
        $seatRows = Database::fetchAll(
            'SELECT schedule_id, seat_no FROM booking_seats WHERE booking_id = :b',
            ['b' => $bookingId]
        );
        $scheduleIds = array_values(array_unique(array_map(
            static fn($r) => (int) $r['schedule_id'],
            $seatRows
        )));

        // Delete rather than soft-release so the seat becomes immediately
        // sellable again and the UNIQUE key stops blocking it.
        $removed = Database::delete('booking_seats', 'booking_id = :b', ['b' => $bookingId]);

        foreach ($scheduleIds as $scheduleId) {
            Database::query(
                'UPDATE schedules
                    SET seats_booked = (
                        SELECT COUNT(*) FROM booking_seats
                         WHERE schedule_id = :s1 AND released_at IS NULL
                    )
                  WHERE id = :s2',
                ['s1' => $scheduleId, 's2' => $scheduleId]
            );
        }

        // An emptied shared cabin reopens to either gender (Feature A, rule 4).
        self::recomputeUnitsForSeats($seatRows);

        return $removed;
    }

    /**
     * Release the seats of ONE leg only. Mirrors releaseBooking() but is
     * scoped by leg_id, so moving a single leg of a round trip (reschedule)
     * can never drop the OTHER leg's seats. Same hard-delete semantics — the
     * UNIQUE(schedule_id,seat_no) key must be freed, not soft-flagged. Must
     * run inside the caller's Database::transaction.
     */
    public static function releaseLeg(int $bookingId, int $legId): int
    {
        $seatRows = Database::fetchAll(
            'SELECT schedule_id, seat_no FROM booking_seats WHERE booking_id = :b AND leg_id = :l',
            ['b' => $bookingId, 'l' => $legId]
        );
        $scheduleIds = array_values(array_unique(array_map(
            static fn($r) => (int) $r['schedule_id'],
            $seatRows
        )));

        $removed = Database::delete(
            'booking_seats',
            'booking_id = :b AND leg_id = :l',
            ['b' => $bookingId, 'l' => $legId]
        );

        foreach ($scheduleIds as $scheduleId) {
            Database::query(
                'UPDATE schedules
                    SET seats_booked = (
                        SELECT COUNT(*) FROM booking_seats
                         WHERE schedule_id = :s1 AND released_at IS NULL
                    )
                  WHERE id = :s2',
                ['s1' => $scheduleId, 's2' => $scheduleId]
            );
        }

        self::recomputeUnitsForSeats($seatRows);

        return $removed;
    }

    /* =================================================================
     *  Gender-aware shared-cabin locking (Part 2 · Feature A)
     *
     *  A "unit" is one physical shared cabin. By default two adjacent
     *  berths make a cabin (L1+L2 -> "L-1") and a seater's two-seat side
     *  pair makes one (1A+1B -> "1-AB"). Rule: a cabin may not seat an
     *  unrelated male and female together. The first woman to take a bed
     *  holds the cabin for women until it empties; the mirror protects a
     *  lone-male cabin. A group may still book the WHOLE cabin together in
     *  one checkout. Private sleeper cabins are exempt (sold whole).
     *
     *  The authoritative per-trip, per-cabin state lives in one
     *  schedule_unit_locks row, always transitioned under its own
     *  FOR UPDATE lock, so online and agent bookings racing into the same
     *  cabin are serialised by the database — never by the UI.
     * ================================================================= */

    /** Only a fully private sleeper cabin is exempt from the rule (rule 6). */
    public static function isSharedBooking(string $coachType, ?string $bookingMode): bool
    {
        return !($coachType === 'sleeper' && $bookingMode === 'private');
    }

    /**
     * Physical cabin ("unit") key a seat belongs to — see the block note
     * for the default two-berth / side-pair grouping.
     */
    public static function unitKey(string $seatNo, string $coachType = 'sleeper', string $bookingType = 'sharing'): string
    {
        $seatNo = strtoupper(trim($seatNo));

        /* A label from a CABIN-SHAPED mode is already the cabin — dividing it
           again names a different one. booking_seats stores each sale in its
           own mode's labels, so a private booking written as "L3" was being
           read as bed 3 and attributed to sharing cabin L-2 (beds L3+L4),
           while that passenger is actually in cabin L-3 (beds L5+L6). The
           gender lock then sat on a cabin nobody was in and refused legitimate
           sharing sales there, while the real cabin carried none. */
        if (self::bedsPerLabel($coachType, $bookingType) > 1
            && preg_match('/^([A-Z])(\d+)$/', $seatNo, $mm) === 1) {
            return $mm[1] . '-' . (int) $mm[2];
        }

        if (preg_match('/^([A-Z])(\d+)$/', $seatNo, $m) === 1) {
            // A "unit" IS the physical cabin, which is precisely what a bed
            // collapses to in the cabin-shaped mode — so ask the rule set
            // instead of dividing by a literal 2. Irregular cabins declared in
            // `explicit` therefore group correctly here too, which the old
            // ceil(n/2) could not do. Output format is unchanged ("L-3"):
            // these strings are STORED in schedule_unit_locks.unit_key, so a
            // reformat would orphan every live gender lock.
            $modes  = self::modeMap($coachType)['modes'] ?? [];
            $cabin  = isset($modes['private']) ? self::physicalToMode($seatNo, 'private', $coachType) : null;
            if ($cabin !== null && preg_match('/^([A-Z])(\d+)$/', $cabin, $c) === 1) {
                return $c[1] . '-' . (int) $c[2];
            }
            return $m[1] . '-' . (int) $m[2];
        }
        if (preg_match('/^(\d+)([A-D])$/', $seatNo, $m) === 1) {
            return $m[1] . '-' . (in_array($m[2], ['A', 'B'], true) ? 'AB' : 'CD');
        }

        return 'S-' . $seatNo; // singleton — never paired
    }

    /**
     * Every seat id sharing a cabin with $unitKey in a coach.
     *
     * @return array<int, string>
     */
    public static function unitSeats(string $unitKey, string $coachType): array
    {
        $out = [];
        foreach (self::seatIds($coachType, 'sharing') as $seat) {
            if (self::unitKey($seat) === $unitKey) {
                $out[] = $seat;
            }
        }
        return $out;
    }

    /** Normalise a gender to Male|Female|Other, or null (nulls never lock). */
    private static function normaliseGender(mixed $gender): ?string
    {
        $g = ucfirst(strtolower(trim((string) $gender)));
        return in_array($g, ['Male', 'Female', 'Other'], true) ? $g : null;
    }

    /**
     * Genders seated on the given seats of a schedule.
     *
     * @param array<int, string> $seats
     * @param bool $lock  read FOR UPDATE (used inside a recompute so it sees
     *                    the latest committed rows, not a stale tx snapshot)
     * @return array<int, string> Male|Female|Other values
     */
    private static function seatGenders(int $scheduleId, array $seats, bool $lock = false): array
    {
        if ($seats === []) {
            return [];
        }

        $placeholders = [];
        $params       = ['s' => $scheduleId];
        foreach ($seats as $i => $seat) {
            $k              = 'g' . $i;
            $placeholders[] = ':' . $k;
            $params[$k]     = $seat;
        }

        $sql = 'SELECT bp.gender
                  FROM booking_seats bs
                  JOIN booking_passengers bp
                    ON bp.booking_id = bs.booking_id AND bp.seat_no = bs.seat_no
                 WHERE bs.schedule_id = :s
                   AND bs.released_at IS NULL
                   AND bs.seat_no IN (' . implode(', ', $placeholders) . ')';

        $rows = $lock
            ? Database::fetchForUpdate($sql, $params)
            : Database::fetchAll($sql, $params);

        $out = [];
        foreach ($rows as $r) {
            $g = self::normaliseGender($r['gender']);
            if ($g !== null) {
                $out[] = $g;
            }
        }
        return $out;
    }

    /**
     * Enforce the shared-cabin gender rule. MUST run inside the booking
     * transaction, after assertAvailable() and before claim(). It locks and
     * updates each affected cabin's authoritative state row, so a rejected
     * booking rolls the state change back with the rest of the transaction.
     *
     * @param array<int, string>      $seats    seats being booked, in order
     * @param array<int, string|null> $genders  gender per seat, same order
     * @return array<int, string> unit keys touched
     * @throws RuntimeException on a rule violation (user-safe message)
     */
    public static function assertGenderAllowed(
        int $scheduleId,
        string $coachType,
        ?string $bookingMode,
        array $seats,
        array $genders
    ): array {
        if (!self::isSharedBooking($coachType, $bookingMode)) {
            return [];
        }

        $incoming = [];
        foreach (array_values($seats) as $i => $seat) {
            $seat = strtoupper((string) $seat);
            if ($seat === '') {
                continue;
            }
            $incoming[self::unitKey($seat)][$seat] = self::normaliseGender($genders[$i] ?? null);
        }

        $touched = [];

        foreach ($incoming as $unitKey => $incomingSeats) {
            // Create-if-absent, then lock the cabin's state row FOR UPDATE so
            // a simultaneous booking into the same cabin waits for us and then
            // reads our committed decision (not a stale snapshot).
            Database::insertIgnore('schedule_unit_locks', [
                'schedule_id' => $scheduleId,
                'unit_key'    => $unitKey,
            ]);
            $row = Database::fetchForUpdate(
                'SELECT gender_lock FROM schedule_unit_locks WHERE schedule_id = :s AND unit_key = :u',
                ['s' => $scheduleId, 'u' => $unitKey]
            );
            $current = (string) ($row[0]['gender_lock'] ?? 'none');

            $incValues = array_values($incomingSeats);
            $incFemale = in_array('Female', $incValues, true);
            $incMale   = in_array('Male', $incValues, true);

            // A cabin already booked whole by a mixed group is full; any new
            // entrant here is a mistake (their seats should not be available).
            if ($current === 'mixed_allowed') {
                throw new RuntimeException(self::genderRejectMessage('mixed_allowed'));
            }

            $femalePresent = $current === 'female_only' || $incFemale;
            $malePresent   = $current === 'male_only'   || $incMale;

            if ($femalePresent && $malePresent) {
                // Mixing is allowed only when a group takes every bed of an
                // otherwise-empty cabin in this one checkout (rule 3).
                $unitSize        = count(self::unitSeats($unitKey, $coachType));
                $wholeCabinGroup = $current === 'none'
                    && $incFemale && $incMale
                    && count($incomingSeats) === $unitSize;

                if (!$wholeCabinGroup) {
                    throw new RuntimeException(self::genderRejectMessage($current));
                }
                $newLock = 'mixed_allowed';
            } elseif ($femalePresent) {
                $newLock = 'female_only';
            } elseif ($malePresent) {
                $newLock = 'male_only';
            } else {
                $newLock = 'none';
            }

            Database::update(
                'schedule_unit_locks',
                ['gender_lock' => $newLock],
                'schedule_id = :s AND unit_key = :u',
                ['s' => $scheduleId, 'u' => $unitKey]
            );
            $touched[] = $unitKey;
        }

        return $touched;
    }

    /** User-safe rejection message for a cabin already holding a gender. */
    private static function genderRejectMessage(string $current): string
    {
        if ($current === 'female_only') {
            return 'This shared cabin already has a female passenger and is held for women only until it is fully vacated. Please choose another cabin or a different seat.';
        }
        if ($current === 'male_only') {
            return 'This shared cabin already has a male passenger, so it cannot also seat a female passenger. Please choose another cabin or a different seat.';
        }
        return 'A shared cabin cannot mix male and female passengers unless the whole cabin is booked together in one checkout. Please choose another cabin, or book the whole cabin as a group.';
    }

    /**
     * Recompute a cabin's gender lock from who occupies it now, under the
     * cabin's FOR UPDATE lock. Call after seats are released (an emptied
     * cabin reopens to anyone) or re-claimed (an approved booking re-takes
     * its cabin). The create path keeps the row correct itself via
     * assertGenderAllowed; this covers every other occupancy change.
     */
    public static function recomputeUnitLock(int $scheduleId, string $unitKey, string $coachType): void
    {
        Database::insertIgnore('schedule_unit_locks', [
            'schedule_id' => $scheduleId,
            'unit_key'    => $unitKey,
        ]);
        Database::fetchForUpdate(
            'SELECT id FROM schedule_unit_locks WHERE schedule_id = :s AND unit_key = :u',
            ['s' => $scheduleId, 'u' => $unitKey]
        );

        $genders   = self::seatGenders($scheduleId, self::unitSeats($unitKey, $coachType), true);
        $hasMale   = in_array('Male', $genders, true);
        $hasFemale = in_array('Female', $genders, true);

        if ($genders === []) {
            $lock = 'none';
        } elseif ($hasMale && $hasFemale) {
            $lock = 'mixed_allowed';
        } elseif ($hasFemale) {
            $lock = 'female_only';
        } elseif ($hasMale) {
            $lock = 'male_only';
        } else {
            $lock = 'none';
        }

        Database::update(
            'schedule_unit_locks',
            ['gender_lock' => $lock],
            'schedule_id = :s AND unit_key = :u',
            ['s' => $scheduleId, 'u' => $unitKey]
        );
    }

    /**
     * Recompute cabin locks for a set of released (schedule_id, seat_no)
     * rows, so an emptied cabin reopens.
     *
     * @param array<int, array<string, mixed>> $seatRows
     */
    private static function recomputeUnitsForSeats(array $seatRows): void
    {
        $seen = [];
        foreach ($seatRows as $r) {
            $sid  = (int) $r['schedule_id'];
            $unit = self::unitKey((string) $r['seat_no']);
            $key  = $sid . '|' . $unit;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $coach = (string) Database::scalar(
                'SELECT r.coach_type FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :s',
                ['s' => $sid],
                'sleeper'
            );
            self::recomputeUnitLock($sid, $unit, $coach);
        }
    }

    /**
     * Locked-cabin summary for the seat map. Only cabins carrying a lock are
     * returned, read from the authoritative state rows.
     *
     * @param array<int, string> $booked seats already sold on the schedule
     * @return array<string, array{seats: array<int,string>, lock: string, freeBeds: int}>
     */
    public static function unitStates(int $scheduleId, string $coachType, array $booked): array
    {
        $rows = Database::fetchAll(
            "SELECT unit_key, gender_lock FROM schedule_unit_locks
              WHERE schedule_id = :s AND gender_lock <> 'none'",
            ['s' => $scheduleId]
        );
        if ($rows === []) {
            return [];
        }

        $bookedSet = array_flip(array_map('strval', $booked));
        $out       = [];

        foreach ($rows as $r) {
            $unitKey   = (string) $r['unit_key'];
            $unitSeats = self::unitSeats($unitKey, $coachType);
            if ($unitSeats === []) {
                continue;
            }

            $free = 0;
            foreach ($unitSeats as $s) {
                if (!isset($bookedSet[$s])) {
                    $free++;
                }
            }

            $out[$unitKey] = [
                'seats'    => $unitSeats,
                'lock'     => (string) $r['gender_lock'],
                'freeBeds' => $free,
            ];
        }

        return $out;
    }

    /* =================================================================
     *  Admin seat management (Part 2 · Feature C)
     *
     *  Blocking, force-releasing holds and the per-seat attribution map the
     *  admin seat-map editor renders. All mutations audit, and blocked
     *  seats are treated as unavailable by availability/assertAvailable/lock
     *  so an override can never leave the inventory inconsistent.
     * ================================================================= */

    /**
     * Seats an admin has taken out of service on a schedule.
     *
     * @return array<int, string>
     */
    public static function blockedSeats(int $scheduleId): array
    {
        return array_map('strval', pluck(
            Database::fetchAll('SELECT seat_no FROM seat_blocks WHERE schedule_id = :s', ['s' => $scheduleId]),
            'seat_no'
        ));
    }

    /**
     * Take a seat out of service. Refuses a seat that is currently sold —
     * cancel or move that booking first. Transactional + audited.
     *
     * @throws RuntimeException
     */
    public static function blockSeat(int $scheduleId, string $seat, string $reason, int $adminId): void
    {
        $seat = strtoupper(Security::clean($seat, 10));
        if ($seat === '' || !Security::isValidSeat($seat)) {
            throw new RuntimeException('Invalid seat.');
        }

        Database::transaction(function () use ($scheduleId, $seat, $reason, $adminId): void {
            // Compared in PHYSICAL space, not against the raw stored labels.
            // bookedSeats() returns booking_seats.seat_no as written, so with
            // private cabin L3 sold (beds L5 + L6) a block on sharing bed L5
            // passed this guard and was written over a berth a paying
            // passenger physically occupies — and the DELETE below then
            // dropped the hold on it. Not an oversell, but it silently takes a
            // sold berth out of service, which the docblock promises it cannot.
            $bookedPhysical = self::bookedPhysical($scheduleId);
            if (array_intersect(self::physicalSeats($seat, 'sharing', self::coachForSchedule($scheduleId)), $bookedPhysical) !== []) {
                throw new RuntimeException('Seat ' . self::displayLabel($seat, self::coachForSchedule($scheduleId), 'sharing') . ' is sold — cancel or move its booking before blocking it.');
            }

            $inserted = Database::insertIgnore('seat_blocks', [
                'schedule_id' => $scheduleId,
                'seat_no'     => $seat,
                'reason'      => Security::clean($reason, 191) ?: null,
                'blocked_by'  => $adminId,
            ]);
            if ($inserted === 0) {
                throw new RuntimeException('Seat ' . $seat . ' is already out of service.');
            }

            // Drop any stray hold so the block takes effect immediately.
            Database::delete('seat_locks', 'schedule_id = :s AND seat_no = :seat', ['s' => $scheduleId, 'seat' => $seat]);

            Logger::audit('seat.block', 'schedule', (string) $scheduleId, null, ['seat' => $seat], 'reason: ' . $reason);
        });
    }

    /** Return a blocked seat to service. Audited. */
    public static function unblockSeat(int $scheduleId, string $seat, int $adminId): void
    {
        $seat    = strtoupper(Security::clean($seat, 10));
        $removed = Database::delete('seat_blocks', 'schedule_id = :s AND seat_no = :seat', ['s' => $scheduleId, 'seat' => $seat]);
        if ($removed > 0) {
            Logger::audit('seat.unblock', 'schedule', (string) $scheduleId, ['seat' => $seat], null, 'back in service');
        }
    }

    /** Force-release a stuck hold on a seat (admin override). Audited. */
    public static function adminReleaseHold(int $scheduleId, string $seat, int $adminId): int
    {
        $seat = strtoupper(Security::clean($seat, 10));
        // Holds are physical beds (4 Sep 2026): a private cabin is two rows
        // under one token, so releasing one bed frees the sibling bed of the
        // same holder too — otherwise half a cabin stays stuck for the hold
        // window. Beds held by a different visitor are never touched.
        $holder = Database::scalar(
            'SELECT lock_token FROM seat_locks WHERE schedule_id = :s AND seat_no = :seat LIMIT 1',
            ['s' => $scheduleId, 'seat' => $seat]
        );
        if (!is_string($holder) || $holder === '') {
            return 0;
        }
        $rhCoach = self::coachForSchedule($scheduleId);
        $cabin   = self::physicalToMode($seat, 'private', $rhCoach);
        $beds    = $cabin !== null ? self::physicalSeats($cabin, 'private', $rhCoach) : [$seat];
        if (!in_array($seat, $beds, true)) {
            $beds[] = $seat;
        }
        $removed = self::deleteOwnBeds($scheduleId, $holder, $beds);
        if ($removed > 0) {
            Logger::audit('seat.release_hold', 'schedule', (string) $scheduleId, ['seat' => $seat, 'beds' => $beds], null, 'admin force-release');
        }
        return $removed;
    }

    /**
     * Per-seat detail for the admin seat-map editor: status, who holds or
     * booked it (customer / named agent / counter), passenger gender and the
     * cabin gender lock. Also surfaces the "who booked it" attribution that
     * Part 2 Feature B asks admin to see.
     *
     * @return array<string, array<string, mixed>> seat_no => detail
     */
    public static function adminSeatMap(int $scheduleId, string $coachType): array
    {
        $all     = self::seatIds($coachType, 'sharing');
        $blocked = array_flip(self::blockedSeats($scheduleId));
        $staff   = array_flip(self::staffSeats($coachType));

        // booking_legs carries the pickup: joined through bs.leg_id (the seat's
        // OWN leg) rather than leg_type='outbound', so a return-leg seat reports
        // the stop the passenger actually boards at on THIS schedule.
        $rows = Database::fetchAll(
            "SELECT bs.seat_no, b.booking_mode, b.pnr, b.status, b.source, b.sold_by_admin_id,
                    bp.full_name, bp.gender,
                    bl.boarding_stop, bl.drop_stop,
                    CASE WHEN u.role = 'agent' THEN u.full_name ELSE NULL END AS agent_name
               FROM booking_seats bs
               JOIN bookings b ON b.id = bs.booking_id
               LEFT JOIN booking_passengers bp
                      ON bp.booking_id = bs.booking_id AND bp.seat_no = bs.seat_no
               LEFT JOIN booking_legs bl ON bl.id = bs.leg_id
               LEFT JOIN users u ON u.id = b.user_id
              WHERE bs.schedule_id = :s AND bs.released_at IS NULL",
            ['s' => $scheduleId]
        );
        // $all is the SHARING (canonical physical) namespace, but booking_seats
        // stores each sale in its OWN mode's labels — so a private cabin sold as
        // "L3" must be drawn on the beds it actually occupies (L5 + L6), not on
        // sharing berth L3, which is a different bed and belongs to nobody.
        // Without this expansion the staff map marked the wrong berth booked and
        // showed two physically-occupied beds as open, i.e. the desk and the
        // customer app disagreed about the same coach. bookedPhysical() has
        // always joined booking_mode this way; adminSeatMap() did not.
        $bookedBy = [];
        foreach ($rows as $r) {
            $mode = (string) ($r['booking_mode'] ?? 'sharing');
            foreach (self::physicalSeats((string) $r['seat_no'], $mode, $coachType) as $bed) {
                $bookedBy[$bed] = $r;
            }
        }

        $holds = [];
        foreach (Database::fetchAll(
            'SELECT seat_no, expires_at FROM seat_locks WHERE schedule_id = :s AND expires_at > NOW()',
            ['s' => $scheduleId]
        ) as $h) {
            $holds[(string) $h['seat_no']] = (string) $h['expires_at'];
        }

        $unitLock = [];
        foreach (Database::fetchAll(
            'SELECT unit_key, gender_lock FROM schedule_unit_locks WHERE schedule_id = :s',
            ['s' => $scheduleId]
        ) as $u) {
            $unitLock[(string) $u['unit_key']] = (string) $u['gender_lock'];
        }

        // A counter agent may work this trip but must not read the passenger
        // book of sales that are not theirs. The seat map is the one screen
        // that shows every berth on a bus, so without this an agent could walk
        // each route and date and harvest the PNR, name and seller of every
        // passenger in the company — the PNR then unlocking full detail
        // elsewhere. Seat STATUS and GENDER stay visible for every berth:
        // both are needed to sell legally (a shared cabin's gender lock is
        // computed from who is already in it), and neither identifies anyone.
        $scopeId = Auth::bookingScopeAdminId();

        // SHG-### agent code map — loaded once so the loop below never
        // touches Settings again. Only leaks out on 'mine' rows, so the
        // same privacy split as the rest of this method still holds.
        $agentCodes = Settings::getArray('agent_codes', []);

        /* Pickup/drop SHORT CODES (MSN, STV, AMD…) resolved HERE, in PHP.
           The admin pages do not load the customer bundle, so they cannot call
           the assets/js/02-config.js mirror of stopDisplay(); shipping the code
           already-resolved is what keeps STOP_CODES to the two copies
           includes/boarding.php:36 already warns about, instead of a third.
           Memoised because a coach has ~5 distinct stops but up to 72 seats. */
        $stopMemo = [];
        $stopOf   = static function (?string $label) use (&$stopMemo): array {
            $label = trim((string) $label);
            if ($label === '') {
                return ['code' => '', 'name' => '', 'time' => null];
            }
            if (!isset($stopMemo[$label])) {
                $stopMemo[$label] = Boarding::stopDisplay($label);
            }
            return $stopMemo[$label];
        };

        /* Colour index for the pickup. Taken from the stop's POSITION ALONG THE
           ROUTE, not a hash of its code: two stops on one bus can then never
           collide onto the same colour, and the map reads geographically (first
           pickup = first hue) instead of arbitrarily. -1 means "no stop
           recorded" and renders uncoloured, which is a real state — the desk
           sells with boarding='' and it is backfilled later. */
        $stopIndex = [];
        $routeId   = (int) (self::scheduleById($scheduleId)['route_id'] ?? 0);
        if ($routeId > 0) {
            foreach (Boarding::stopsFor($routeId) as $i => $st) {
                $key = Boarding::townKey((string) ($st['name'] ?? ''));
                if ($key !== '' && !isset($stopIndex[$key])) {
                    $stopIndex[$key] = $i;
                }
            }
        }
        $stopHue = static function (?string $label) use ($stopIndex): int {
            $key = Boarding::townKey(trim((string) $label));
            return $key === '' ? -1 : ($stopIndex[$key] ?? -1);
        };

        $out = [];
        foreach ($all as $seat) {
            $row    = $bookedBy[$seat] ?? [];
            $status = 'open';
            if ($row !== [])                { $status = 'booked'; }
            elseif (isset($blocked[$seat])) { $status = 'blocked'; }
            elseif (isset($staff[$seat]))   { $status = 'staff'; }
            elseif (isset($holds[$seat]))   { $status = 'held'; }

            $mine = $scopeId === null
                || ($row !== [] && (int) ($row['sold_by_admin_id'] ?? 0) === $scopeId);

            $soldById  = ($row !== [] && isset($row['sold_by_admin_id']))
                ? (int) $row['sold_by_admin_id']
                : 0;
            $agentCode = '';
            if ($mine && $soldById > 0 && isset($agentCodes[$soldById])) {
                $agentCode = 'SHG-' . str_pad((string) $agentCodes[$soldById], 4, '0', STR_PAD_LEFT);
            }

            $out[$seat] = [
                'seat'       => $seat,
                'unit'       => self::unitKey($seat),
                'status'     => $status,
                'isStaff'    => isset($staff[$seat]),
                'genderLock' => $unitLock[self::unitKey($seat)] ?? 'none',
                'mine'       => $mine,
                'pnr'        => $mine ? ($row['pnr'] ?? null) : null,
                'passenger'  => $mine ? ($row['full_name'] ?? null) : null,
                'gender'     => $row['gender'] ?? null,
                'channel'    => !$mine
                                    ? null
                                    : ((!empty($row['agent_name']))
                                        ? ('Agent: ' . $row['agent_name'])
                                        : (isset($row['source']) ? ucfirst((string) $row['source']) : null)),
                'holdUntil'  => $holds[$seat] ?? null,
                /* Pickup / drop short code. Deliberately NOT $mine-gated, on the
                   same reasoning as status and gender above: a 3-letter stop
                   code identifies nobody, and a counter agent working the trip
                   has to see which berths board at the stop being called. The
                   passenger book (pnr / passenger / channel / seller) stays
                   gated. Empty string when the leg recorded no stop — the desk
                   sells with boarding='' and BookingService::defaultBoardingStop
                   fills it in later, so blank is a real, common state and must
                   render as "no stop recorded" rather than an invented code. */
                'stop'       => $stopOf($row['boarding_stop'] ?? null)['code'],
                'stopName'   => $stopOf($row['boarding_stop'] ?? null)['name'],
                'stopTime'   => $stopOf($row['boarding_stop'] ?? null)['time'],
                'stopHue'    => $stopHue($row['boarding_stop'] ?? null),
                'drop'       => $stopOf($row['drop_stop'] ?? null)['code'],
                'dropName'   => $stopOf($row['drop_stop'] ?? null)['name'],
                // Agent attribution — precomputed SHG-### code and the raw
                // seller id (kept 'mine'-gated so cross-agent enumeration
                // is not possible from either the page or the JSON poller).
                'soldById'   => $mine ? ($soldById > 0 ? $soldById : null) : null,
                'agentCode'  => $mine ? $agentCode : '',
            ];
        }
        return $out;
    }

    /**
     * PHYSICAL berths occupied, for many schedules at once.
     *
     * "How full is this bus" is asked on a dozen admin screens, and every one
     * of them counted booking_seats ROWS. A private cabin is one row and two
     * berths, so those screens under-reported a coach's real load — the Bus
     * Calendar, the dashboard, the trips list and the live status could all
     * show a bus as having room it does not have.
     *
     * SQL cannot answer this on its own any more: the cabin-to-bed ratio is
     * configurable (seat_mode_map), so a `CASE WHEN booking_mode='private'
     * THEN 2` in a query would re-introduce the hard-coded 2 that was just
     * removed, in twelve more places. One query, expanded in PHP through the
     * rule set, keeps the answer correct and still costs a single round trip.
     *
     * @param  array<int, int> $scheduleIds
     * @return array<int, int> schedule id => berths occupied (missing ids = 0)
     */
    public static function occupiedBedsFor(array $scheduleIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $scheduleIds))));
        if ($ids === []) {
            return [];
        }
        $ph = [];
        $pa = [];
        foreach ($ids as $i => $id) {
            $ph[]        = ':s' . $i;
            $pa['s' . $i] = $id;
        }
        $rows = Database::fetchAll(
            'SELECT bs.schedule_id, bs.seat_no, b.booking_mode
               FROM booking_seats bs
               JOIN bookings b ON b.id = bs.booking_id
              WHERE bs.schedule_id IN (' . implode(',', $ph) . ')
                AND bs.released_at IS NULL',
            $pa
        );

        $beds = [];
        foreach ($rows as $r) {
            $sid = (int) $r['schedule_id'];
            foreach (self::physicalSeats(
                (string) $r['seat_no'],
                (string) ($r['booking_mode'] ?? 'sharing'),
                self::coachForSchedule($sid)
            ) as $bed) {
                $beds[$sid][$bed] = true;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = isset($beds[$id]) ? count($beds[$id]) : 0;
        }
        return $out;
    }

    /** Physical berths occupied on ONE schedule. */
    public static function occupiedBeds(int $scheduleId): int
    {
        return self::occupiedBedsFor([$scheduleId])[$scheduleId] ?? 0;
    }

    /**
     * Release EVERY unpaid hold on one schedule, as a trip cancellation does.
     *
     * admin/api/schedule-action.php was doing this with a raw
     * Database::delete('seat_locks', ...) — the only write to booking_seats /
     * seat_locks / seat_blocks anywhere outside this class. The behaviour it
     * wanted was right; there was simply no method for it (release() takes
     * named seats, releaseAll() takes a token, adminReleaseHold() takes one
     * seat), so the seat store had a back door and the action left no audit
     * row. Booked seats are deliberately untouched: the owner decides those
     * per-PNR in the Refund Desk.
     *
     * @return int holds released
     */
    public static function releaseAllHolds(int $scheduleId, int $adminId, string $why = 'trip cancelled'): int
    {
        $n = Database::delete('seat_locks', 'schedule_id = :s', ['s' => $scheduleId]);
        if ($n > 0) {
            Logger::audit('seat.release_all_holds', 'schedule', (string) $scheduleId, null, ['holds' => $n], $why);
        }
        return $n;
    }

    /**
     * Guard a bus-swap on a schedule before it is written. Refuses swaps that
     * would leave passengers holding seat IDs the new coach does not have
     * (e.g. sleeper L1..L72 on a target seater 1A..10D), or that would
     * over-fill the new bus. Read-only — the caller performs the UPDATE
     * inside its own transaction after this returns ok.
     *
     * @return array{ok:true, currentCoach:string, newCoach:string, bookedCount:int, newTotalSeats:int}
     * @throws RuntimeException (user-safe)
     */
    public static function assertBusSwapSafe(int $scheduleId, int $newBusId): array
    {
        if ($scheduleId <= 0) {
            throw new RuntimeException('Missing schedule id.');
        }
        if ($newBusId <= 0) {
            throw new RuntimeException('Missing bus id.');
        }

        // 1. Schedule + route coach type in a single JOIN.
        $sched = Database::fetch(
            'SELECT s.id, s.bus_id, r.coach_type AS route_coach
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
              WHERE s.id = :id
              LIMIT 1',
            ['id' => $scheduleId]
        );
        if ($sched === null) {
            throw new RuntimeException('Cannot swap: that trip no longer exists.');
        }
        // effectiveCoach()/coachForSchedule() are the central resolvers and
        // honour schedules.coach_type_override, which an extra bus has carried
        // since 4 Sep. Reading r.coach_type straight out of the JOIN compared
        // the target against the WRONG coach on such a trip — refusing a
        // correct swap (booked 1A/1B read as orphans because allowedSeats came
        // out L#/U#) or admitting a mismatched one.
        $currentCoach = self::coachForSchedule($scheduleId);
        if ($currentCoach === '') {
            $currentCoach = (string) ($sched['route_coach'] ?? '');
        }

        // 2. Target bus.
        $bus = Database::fetch(
            'SELECT id, is_active, coach_type, total_seats
               FROM buses WHERE id = :id LIMIT 1',
            ['id' => $newBusId]
        );

        // 3. Bus must exist and be active.
        if ($bus === null) {
            throw new RuntimeException('Cannot swap: that bus no longer exists.');
        }
        if ((int) ($bus['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Cannot swap: the target bus is inactive.');
        }

        $newCoach     = (string) ($bus['coach_type'] ?? '');
        $newTotalSeat = (int) ($bus['total_seats'] ?? 0);

        // 4. Coach type must match the route (sleeper L#/U# vs seater 1A..10D
        //    are not interchangeable seat namespaces).
        if ($newCoach === '' || $currentCoach === '' || $newCoach !== $currentCoach) {
            throw new RuntimeException(
                'Cannot swap: the target bus is a ' . ($newCoach !== '' ? $newCoach : 'unknown')
                . ' coach but this trip runs on a ' . ($currentCoach !== '' ? $currentCoach : 'unknown')
                . ' route. Seat IDs are incompatible.'
            );
        }

        // 5. Currently-booked seat_nos on this schedule (live claims only).
        $bookedRows = Database::fetchAll(
            'SELECT bs.seat_no, b.booking_mode
               FROM booking_seats bs
               JOIN bookings b ON b.id = bs.booking_id
              WHERE bs.schedule_id = :s AND bs.released_at IS NULL',
            ['s' => $scheduleId]
        );
        // Expanded to PHYSICAL beds, with each booking's own mode: a private
        // booking of N cabins is N rows but 2N berths, so counting rows
        // under-counted the load by up to 2x and could approve a swap onto a
        // sleeper that physically cannot hold the passengers. The orphan check
        // below wants beds too — a private cabin's label existing on the
        // target says nothing about the beds underneath it.
        $bookedSeats = [];
        foreach ($bookedRows as $r) {
            $seat = (string) ($r['seat_no'] ?? '');
            if ($seat === '') { continue; }
            foreach (self::physicalSeats($seat, (string) ($r['booking_mode'] ?? 'sharing'), $currentCoach) as $bed) {
                $bookedSeats[$bed] = true;
            }
        }
        $bookedSeats = array_keys($bookedSeats);
        $bookedCount = count($bookedSeats);

        // 6. Seats the target bus can actually offer (sharing layout — the
        //    superset that covers both sharing and private berths).
        $allowedSeats = self::seatIds($newCoach, 'sharing');
        $allowedFlip  = array_flip($allowedSeats);

        // 7. Every held seat must exist on the target coach.
        $orphans = [];
        foreach ($bookedSeats as $seat) {
            if (!isset($allowedFlip[$seat])) { $orphans[] = $seat; }
        }
        if (!empty($orphans)) {
            $preview = array_slice($orphans, 0, 6);
            $more    = count($orphans) - count($preview);
            $list    = implode(',', $preview) . ($more > 0 ? (' +' . $more . ' more') : '');
            throw new RuntimeException(
                'Cannot swap: ' . count($orphans) . ' passenger'
                . (count($orphans) === 1 ? '' : 's')
                . ' hold seat' . (count($orphans) === 1 ? '' : 's')
                . ' ' . $list . ' which do not exist on the target ' . $newCoach . ' coach.'
            );
        }

        // 8. Physical capacity check.
        if ($newTotalSeat > 0 && $bookedCount > $newTotalSeat) {
            throw new RuntimeException(
                'Cannot swap: ' . $bookedCount . ' passengers are booked but the target bus only has '
                . $newTotalSeat . ' seats.'
            );
        }

        return [
            'ok'            => true,
            'currentCoach'  => $currentCoach,
            'newCoach'      => $newCoach,
            'bookedCount'   => $bookedCount,
            'newTotalSeats' => $newTotalSeat,
        ];
    }

    /**
     * Transfer a booked seat to a free seat on the SAME schedule — the agent
     * "reseat" action. Moves the passenger without creating a second booking:
     * the target must be free (not sold / blocked / held by others), the
     * shared-cabin gender rule is enforced for the target cabin using the
     * passenger's own gender and their booking's sharing/private mode, and
     * both the vacated and the new cabin locks are recomputed. The
     * UNIQUE(schedule_id, seat_no) key still guarantees no double-book even
     * under a race. Audited.
     *
     * @return array{pnr:string, from:string, to:string, passenger:string}
     * @throws RuntimeException (user-safe)
     */
    public static function transferSeat(int $scheduleId, string $fromSeat, string $toSeat, string $coachType, int $adminId): array
    {
        $fromSeat = strtoupper(Security::clean($fromSeat, 10));
        $toSeat   = strtoupper(Security::clean($toSeat, 10));

        if ($fromSeat === '' || $toSeat === '' || !Security::isValidSeat($fromSeat) || !Security::isValidSeat($toSeat)) {
            throw new RuntimeException('Invalid seat.');
        }
        if ($fromSeat === $toSeat) {
            throw new RuntimeException('Choose a different destination seat.');
        }

        return Database::transaction(function () use ($scheduleId, $fromSeat, $toSeat, $coachType, $adminId): array {
            $row = Database::fetch(
                'SELECT bs.booking_id, b.pnr, b.booking_mode, b.sold_by_admin_id,
                        bp.id AS pax_id, bp.gender, bp.full_name
                   FROM booking_seats bs
                   JOIN bookings b ON b.id = bs.booking_id
                   LEFT JOIN booking_passengers bp
                          ON bp.booking_id = bs.booking_id AND bp.seat_no = bs.seat_no
                  WHERE bs.schedule_id = :s AND bs.seat_no = :seat AND bs.released_at IS NULL
                  LIMIT 1',
                ['s' => $scheduleId, 'seat' => $fromSeat]
            );
            if ($row === null) {
                throw new RuntimeException('Seat ' . $fromSeat . ' is not currently booked.');
            }

            // A counter agent may reseat their own passengers and no one
            // else's. schedules.edit — which every agent holds, because they
            // must be able to sell — is authority over the TRIP, not over
            // another agent's passenger. This also closes a read hole: the
            // caller reports the moved passenger's name and PNR back to the
            // operator, so without this an agent could recover the identity
            // behind any berth simply by nudging it to a free seat.
            $scopeId = Auth::bookingScopeAdminId();
            if ($scopeId !== null && (int) ($row['sold_by_admin_id'] ?? 0) !== $scopeId) {
                throw new RuntimeException('You can only move a passenger from a booking you sold.');
            }

            // Target must be free (not sold / blocked / held by someone else).
            // The booking's OWN mode has to be passed: without it the target
            // label was judged in the sharing namespace even for a private
            // booking, so moving a private booking to cabin "L4" only checked
            // physical bed L4 while the row written means beds L7+L8 — which
            // may already be sold in sharing mode. The UNIQUE key cannot catch
            // that (the labels differ), so it was a real cross-mode oversell
            // reachable from both the seat map and booking-view. The mode is
            // already in hand and is passed to assertGenderAllowed on the very
            // next line; it was simply missing here.
            self::assertAvailable(
                $scheduleId,
                [$toSeat],
                'transfer-' . $adminId,
                false,
                (string) ($row['booking_mode'] ?? 'sharing')
            );

            // Same gender rule as booking, for the destination cabin.
            self::assertGenderAllowed($scheduleId, $coachType, $row['booking_mode'] ?? null, [$toSeat], [$row['gender'] ?? null]);

            // Move the seat claim; the UNIQUE key rejects a concurrent grab.
            Database::update(
                'booking_seats',
                ['seat_no' => $toSeat],
                'schedule_id = :s AND seat_no = :from AND booking_id = :b',
                ['s' => $scheduleId, 'from' => $fromSeat, 'b' => (int) $row['booking_id']]
            );

            if (!empty($row['pax_id'])) {
                Database::update('booking_passengers', ['seat_no' => $toSeat], 'id = :id', ['id' => (int) $row['pax_id']]);
            }

            // Vacated cabin may reopen; new cabin takes the passenger's lock.
            // Both labels belong to THIS booking, so they are read in its own
            // mode: a private "L4" is cabin L-4, not bed 4 halved into L-2.
            $tsMode = (string) ($row['booking_mode'] ?? 'sharing');
            self::recomputeUnitLock($scheduleId, self::unitKey($fromSeat, $coachType, $tsMode), $coachType);
            self::recomputeUnitLock($scheduleId, self::unitKey($toSeat, $coachType, $tsMode), $coachType);

            Logger::audit('seat.transfer', 'booking', (string) $row['pnr'], ['seat' => $fromSeat], ['seat' => $toSeat], 'reseat ' . ($row['full_name'] ?? ''));

            /* The passenger is holding a picture of the OLD berth. Every other
               path that moves a seat re-mints the ticket (a leg move, a
               per-seat cancel, a booking-view edit); a transfer from the seat
               map did not, so the chalani said L9 while the WhatsApp ticket
               still said L5 and the QR still carried the old seat list
               (audit, 11 Sep 2026). Done HERE, not in the caller, so no future
               caller can forget it. Never fatal to the move itself: the seat
               has changed hands either way, and a stale render is a smaller
               problem than a rolled-back transfer.

               ticket.php is not a dependency of the seat engine, so it is
               required on demand — every real entry point already has it. */
            try {
                if (!class_exists('Ticket')) { require_once __DIR__ . '/ticket.php'; }
                Ticket::reissue((int) $row['booking_id']);
            } catch (Throwable $e) {
                Logger::error('Seat-transfer ticket reissue failed', ['e' => $e->getMessage(), 'pnr' => (string) $row['pnr']]);
            }

            return [
                'pnr'       => (string) $row['pnr'],
                'from'      => $fromSeat,
                'to'        => $toSeat,
                'passenger' => (string) ($row['full_name'] ?? ''),
            ];
        });
    }

    /* =================================================================
     *  Per-seat cancel  (Admin Panel Upgrade · Section C)
     *
     *  Releases ONE seat from a multi-seat booking while keeping the rest
     *  intact.  Mirrors releaseBooking() but scoped to a single seat_no.
     *  Must run inside the caller's Database::transaction.
     * ================================================================= */

    /**
     * Release a single seat from a booking, recompute counts and cabin locks.
     *
     * @return array{schedule_id: int, seat_no: string}
     * @throws RuntimeException when the seat is not found on the booking
     */
    public static function releaseSingleSeat(int $bookingId, string $seatNo): array
    {
        $seatNo = strtoupper(trim($seatNo));

        $row = Database::fetch(
            'SELECT schedule_id, seat_no FROM booking_seats
              WHERE booking_id = :b AND seat_no = :s AND released_at IS NULL
              LIMIT 1',
            ['b' => $bookingId, 's' => $seatNo]
        );
        if ($row === null) {
            throw new RuntimeException('Seat ' . $seatNo . ' is not currently held by this booking.');
        }

        $scheduleId = (int) $row['schedule_id'];

        // Hard-delete so the UNIQUE key frees the seat immediately.
        Database::delete(
            'booking_seats',
            'booking_id = :b AND seat_no = :s',
            ['b' => $bookingId, 's' => $seatNo]
        );

        // Recompute schedule counts.
        Database::query(
            'UPDATE schedules
                SET seats_booked = (
                    SELECT COUNT(*) FROM booking_seats
                     WHERE schedule_id = :s1 AND released_at IS NULL
                )
              WHERE id = :s2',
            ['s1' => $scheduleId, 's2' => $scheduleId]
        );

        // Recompute the vacated cabin's gender lock.
        $coach = (string) Database::scalar(
            'SELECT r.coach_type FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :s',
            ['s' => $scheduleId],
            'sleeper'
        );
        self::recomputeUnitLock($scheduleId, self::unitKey($seatNo), $coach);

        return ['schedule_id' => $scheduleId, 'seat_no' => $seatNo];
    }

    /* =================================================================
     *  PASSENGER-FACING SEAT LABEL  (owner ask, 11 Sep 2026)
     *
     *  The seat map is drawn as a row-letter grid. Since 23 Sep 2026 (owner
     *  ask) the two floors share ONE grid: Lower Floor (1F) A1..F6, Upper
     *  Floor (2F) A7..F12 — row A..F down the coach, columns 1..6 across the
     *  lower floor and 7..12 across the upper, 4+2 either side of the aisle —
     *  so all 72 labels are unique without a deck prefix. (11–23 Sep it was
     *  LA1..LF6 / UA1..UF6.) That same label must appear on the customer app,
     *  the agent/counter desk, the admin seat map, the printed ticket (PNG +
     *  PDF), the chalani and every WhatsApp/e-mail that names a berth.
     *
     *  This is a DISPLAY transform ONLY. Storage stays canonical: booking_seats
     *  .seat_no / seat_locks / seat_blocks are L1..L36 / U1..U36 / 1A..10D, and
     *  the entire cross-mode physical-bed engine (physicalSeats/unitKey/…) reads
     *  that <letter><number> shape. Rewriting the stored ids would break every
     *  live booking and orphan every gender lock, so it is never done — the
     *  pretty label is produced at the render edge and the canonical id keeps
     *  flowing through holds, sales, APIs and the database unchanged.
     * ================================================================= */

    /**
     * The passenger-facing label for a canonical seat id.
     *
     *   sleeper bed L{n}/U{n} -> row-letter + column on the one two-floor grid:
     *                        L1..L36 -> A1..F6, U1..U36 -> A7..F12. `perRow`
     *                        comes from the canonical (bed) mode's `across` in
     *                        seat_mode_map — the rule layoutFor() draws — so the
     *                        label can never disagree with the grid.
     *   private cabin      -> named by the physical beds it covers, exactly as
     *                        the sharing map labels them: private L6 = beds
     *                        L11+L12 = "B5-6". A cabin label can therefore never
     *                        name a different bed than the one the passenger
     *                        finds on the coach.
     *   pure numeric "{n}" -> "A{n}"  (defensive: no live coach stores this, but
     *                        the owner asked for it explicitly).
     *   seater "{n}{A-D}"  -> unchanged (already a row-number + column-letter).
     *
     * @param string $seat       canonical seat id (as stored)
     * @param string $coachType  'sleeper' | 'seater'
     * @param string $bookingType 'sharing' | 'private' — the mode the label was
     *                            sold/shown in, so a private cabin expands to
     *                            its own beds.
     */
    public static function displayLabel(string $seat, string $coachType = 'sleeper', string $bookingType = 'sharing'): string
    {
        $s = strtoupper(trim($seat));
        if ($s === '') {
            return '';
        }

        // Sleeper deck berth / cabin: L{n} / U{n} -> the bed(s) on the grid.
        if (preg_match('/^([LU])(\d+)$/', $s, $m) === 1) {
            if ((int) $m[2] < 1) {
                return $s;
            }
            $coach = $coachType !== '' ? $coachType : 'sleeper';
            $lbls  = [];
            foreach (self::physicalSeats($s, $bookingType, $coach) as $bed) {
                $lbls[] = self::bedLabel((string) $bed, $coach);
            }
            return self::joinBedLabels($lbls);
        }

        // Purely numeric id -> 'A' prefix (defensive; live data never hits this).
        if (preg_match('/^\d+$/', $s) === 1) {
            return 'A' . $s;
        }

        // Seater #A..#D and anything else: already human-labelled.
        return $s;
    }

    /** One physical bed -> its grid label (L1 -> A1, L36 -> F6, U1 -> A7, U36 -> F12). */
    private static function bedLabel(string $bed, string $coachType): string
    {
        if (preg_match('/^([A-Z])(\d+)$/', $bed, $m) !== 1 || (int) $m[2] < 1) {
            return $bed;
        }
        $spec   = self::modeMap($coachType);
        $canon  = (string) ($spec['canonical'] ?? 'sharing');
        $across = $spec['modes'][$canon]['across'] ?? [4, 2];
        $perRow = max(1, (int) ($across[0] ?? 0) + (int) ($across[1] ?? 0));
        // Floor index: lower 0, upper 1 — the upper floor's columns continue
        // after the lower floor's, which is what makes every label unique.
        $floor  = (int) array_search($m[1], array_values((array) ($spec['decks'] ?? ['L', 'U'])), true);
        $n      = (int) $m[2];
        return self::rowLetter(intdiv($n - 1, $perRow)) . ((($n - 1) % $perRow) + 1 + $floor * $perRow);
    }

    /** ["B5","B6"] -> "B5-6"; beds that are not one run in one row -> "A1+B1". */
    private static function joinBedLabels(array $lbls): string
    {
        if (count($lbls) < 2) {
            return (string) ($lbls[0] ?? '');
        }
        $row = null;
        $prev = null;
        foreach ($lbls as $l) {
            if (preg_match('/^([A-Z]+)(\d+)$/', (string) $l, $m) !== 1
                || ($row !== null && $m[1] !== $row)
                || ($prev !== null && (int) $m[2] !== $prev + 1)) {
                return implode('+', $lbls);
            }
            $row  = $m[1];
            $prev = (int) $m[2];
        }
        return $lbls[0] . '-' . $prev;
    }

    /**
     * displayLabel() for a whole list, joined for a chip line. Keeps the join
     * in one place so every ticket / manifest / message spells it the same way.
     *
     * @param array<int, string> $seats canonical ids
     */
    public static function displayLabels(array $seats, string $coachType = 'sleeper', string $bookingType = 'sharing', string $glue = ', '): string
    {
        $out = [];
        foreach ($seats as $s) {
            $lbl = self::displayLabel((string) $s, $coachType, $bookingType);
            if ($lbl !== '') {
                $out[] = $lbl;
            }
        }
        return implode($glue, $out);
    }

    /** 0-based index -> spreadsheet-style row letter (A..Z, AA..). Total, though
     *  a real coach never has more than a handful of rows per deck. */
    private static function rowLetter(int $idx): string
    {
        $idx = max(0, $idx);
        $out = '';
        do {
            $out = chr(65 + ($idx % 26)) . $out;
            $idx = intdiv($idx, 26) - 1;
        } while ($idx >= 0);
        return $out;
    }

    /**
     * Human label for a seat: "Lower Floor (1F) · A4" / "Seat 3C". Uses the
     * passenger-facing grid id (displayLabel) so the long form and the tile agree.
     */
    public static function label(string $seatNo): string
    {
        if (preg_match('/^([LU])(\d+)$/i', $seatNo, $match) === 1) {
            return self::floorName(strtoupper($match[1])) . ' · ' . self::displayLabel(strtoupper($seatNo));
        }

        return 'Seat ' . self::displayLabel(strtoupper($seatNo));
    }

    /** A floor's first–last bed label: L -> "A1–F6", U -> "A7–F12" ('' for a seater). */
    public static function floorRange(string $deck, string $coachType = 'sleeper'): string
    {
        $deck = strtoupper($deck);
        if ($deck !== 'L' && $deck !== 'U') {
            return '';
        }
        $coach   = $coachType !== '' ? $coachType : 'sleeper';
        $perDeck = max(1, (int) (self::modeMap($coach)['perDeck'] ?? 36));
        return self::bedLabel($deck . '1', $coach) . '–' . self::bedLabel($deck . $perDeck, $coach);
    }

    /** Floor heading every seat surface prints: L -> "Lower Floor (1F)", U -> "Upper Floor (2F)". */
    public static function floorName(string $deck): string
    {
        return match (strtoupper($deck)) {
            'L'     => 'Lower Floor (1F)',
            'U'     => 'Upper Floor (2F)',
            default => 'Main Cabin',
        };
    }
}
