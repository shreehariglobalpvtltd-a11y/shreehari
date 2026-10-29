<?php
/**
 * =====================================================================
 *  Fleet rotation — the "bus recycles every 3 days" rule
 *
 *  A coach that leaves Surat today reaches the border tomorrow and is
 *  only back for the next outbound run on the third day. So a DAILY
 *  service is not one bus repeated; it is a small fleet taking turns.
 *  The operator wanted to stop hand-assigning that every morning.
 *
 *  The rotation is a plain ordered list of buses plus a cycle length.
 *  Day N of the cycle gets fleet[N % count], so a 3-bus list recycles on
 *  the third day on its own, for ever, with nothing to maintain.
 *
 *  Deliberately fleet-size agnostic: put 2 buses in the list and it
 *  alternates, put 5 in and it runs a 5-day loop. The operator changes
 *  the list — or a single bus NUMBER — in Admin without a deploy.
 *
 *  ROUND-TRIP MODE (29 Oct 2026, the owner's "S Hari Global 3 Bus Round
 *  Trip Routine" chart). The daily mode gives the SAME bus to both
 *  directions on a date, so with one route pair the board showed one coach
 *  leaving Surat at 13:00 and Rupaidiha at 18:00 the same day. The chart:
 *
 *      Bus 01  Gujarat depart day 1, reach Rupaidiha day 3, leave the Nepal
 *              side day 4, back in Gujarat day 6, depart again day 7
 *      Bus 02  the same, 2 days later (depart 3, return 6)
 *      Bus 03  the same, 4 days later (depart 5, return 8 = day 2)
 *
 *  i.e. a 6-day turnaround, a departure every 2 days ("2-day staggered
 *  starts"), each bus leaving the Nepal side 3 days after it left Gujarat.
 *  In general: cycle = buses x stagger; bus k leaves outbound on cycle day
 *  k x stagger and on the return backAfter days later. Each bus may name its
 *  own outbound / return route (2 Surat buses + 1 Rajkot bus); a route runs
 *  only on the days of a bus that names it. A direction with no bus that day
 *  gets its schedule row created BLOCKED (hidden from search, refused at
 *  checkout - the same flag as a per-date OFF), so nothing is ever sold on a
 *  bus that is not there.
 *
 *  Stored in one `bus_rotation` settings row (zero SQL, same pattern as
 *  agent_types / agent_codes), and applied when a schedule row is first
 *  materialised, so `schedules.bus_id` carries the answer and every
 *  downstream screen (trips board, ticket PDF) picks it up unchanged.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Fleet
{
    /** Hard ceiling so a typo cannot create an absurd cycle. */
    public const MAX_CYCLE_DAYS = 30;

    /**
     * The stored rotation, normalised.
     *
     * @return array{enabled: bool, cycleDays: int, startDate: string, buses: array<int, int>, mode: string,
     *                stagger: int, backAfter: int, routes: array<int, array{out: int, back: int}>}
     */
    public static function rotation(): array
    {
        $raw = Settings::getArray('bus_rotation', []);

        $buses = [];
        foreach ((array) ($raw['buses'] ?? []) as $b) {
            $id = (int) $b;
            if ($id > 0 && !in_array($id, $buses, true)) {
                $buses[] = $id;   // duplicates would waste a slot in the cycle
            }
        }

        $cycle = (int) ($raw['cycleDays'] ?? 3);
        $cycle = max(1, min(self::MAX_CYCLE_DAYS, $cycle));

        $start = (string) ($raw['startDate'] ?? '');
        if ($start === '' || !Security::isValidDate($start)) {
            $start = '2026-01-01';   // any fixed epoch; only the offset matters
        }

        return [
            'enabled'   => !empty($raw['enabled']) && $buses !== [],
            'cycleDays' => $cycle,
            'startDate' => $start,
            'buses'     => $buses,
            'mode'      => (string) ($raw['mode'] ?? '') === 'round_trip' ? 'round_trip' : 'daily',
            // Round trip: days between departures, and how many days after
            // leaving Gujarat the same bus leaves the Nepal side (chart: 2, 3).
            'stagger'   => max(1, min(10, (int) ($raw['stagger'] ?? 2))),
            'backAfter' => max(1, min(self::MAX_CYCLE_DAYS, (int) ($raw['backAfter'] ?? 3))),
            // busId => [out route id, back route id]; 0 = any route that way,
            // -1 = no route yet (that bus's departures are not sold).
            'routes'    => self::normaliseSlotRoutes((array) ($raw['routes'] ?? []), $buses),
        ];
    }

    /** @return array<int, array{out: int, back: int}> */
    private static function normaliseSlotRoutes(array $raw, array $buses): array
    {
        $out = [];
        foreach ($buses as $b) {
            $r = (array) ($raw[$b] ?? $raw[(string) $b] ?? []);
            $out[$b] = ['out' => max(-1, (int) ($r['out'] ?? 0)), 'back' => max(-1, (int) ($r['back'] ?? 0))];
        }
        return $out;
    }

    /** Persist a rotation. Audited. */
    /**
     * @param array{stagger?: int, backAfter?: int, routes?: array<int, array{out?: int, back?: int}>} $roundTrip
     */
    public static function saveRotation(bool $enabled, array $busIds, int $cycleDays, string $startDate, int $by = 0,
        string $mode = 'daily', array $roundTrip = []): void
    {
        $mode = $mode === 'round_trip' ? 'round_trip' : 'daily';
        $buses = [];
        foreach ($busIds as $b) {
            $id = (int) $b;
            if ($id > 0 && !in_array($id, $buses, true)) {
                $buses[] = $id;
            }
        }

        if ($enabled && $buses === []) {
            throw new RuntimeException('Choose at least one bus before turning the rotation on.');
        }

        $cycleDays = max(1, min(self::MAX_CYCLE_DAYS, $cycleDays));
        if ($startDate === '' || !Security::isValidDate($startDate)) {
            $startDate = todayISO();
        }

        $old = self::rotation();

        Settings::set('bus_rotation', [
            'enabled'   => $enabled,
            'cycleDays' => $cycleDays,
            'startDate' => $startDate,
            'buses'     => $buses,
            'mode'      => $mode,
            'stagger'   => max(1, min(10, (int) ($roundTrip['stagger'] ?? 2))),
            'backAfter' => max(1, min(self::MAX_CYCLE_DAYS, (int) ($roundTrip['backAfter'] ?? 3))),
            'routes'    => self::normaliseSlotRoutes((array) ($roundTrip['routes'] ?? []), $buses),
        ], 'json', 'fleet', false);

        Logger::audit(
            'fleet.rotation_saved',
            'settings',
            'bus_rotation',
            ['buses' => $old['buses'], 'enabled' => $old['enabled'], 'cycleDays' => $old['cycleDays'], 'mode' => $old['mode'], 'startDate' => $old['startDate']],
            ['buses' => $buses, 'enabled' => $enabled, 'cycleDays' => $cycleDays, 'mode' => $mode, 'startDate' => $startDate],
            'by admin#' . $by
        );
    }

    /**
     * Whole days from the rotation epoch to a travel date. Uses UTC
     * midnights so daylight-saving or a server timezone nudge can never
     * shift the whole roster by one bus.
     */
    public static function dayIndex(string $travelDate, ?string $startDate = null): int
    {
        $rot   = self::rotation();
        $start = $startDate ?? $rot['startDate'];

        $a = strtotime($start . ' 00:00:00 UTC');
        $b = strtotime($travelDate . ' 00:00:00 UTC');
        if ($a === false || $b === false) {
            return 0;
        }

        return (int) floor(($b - $a) / 86400);
    }

    /**
     * Which bus serves a travel date, or null when no rotation applies
     * (then the route's own bus_id stands, exactly as before).
     */
    public static function busForDate(string $travelDate): ?int
    {
        $rot = self::rotation();
        if (!$rot['enabled'] || $rot['buses'] === []) {
            return null;
        }

        $n = count($rot['buses']);
        // Modulo of a negative day index (a date before the epoch) is
        // negative in PHP, which would index nothing — wrap it forward.
        $i = self::dayIndex($travelDate) % $n;
        if ($i < 0) {
            $i += $n;
        }

        return $rot['buses'][$i];
    }

    /**
     * Route-aware answer (29 Oct 2026): which bus runs THIS route on a date,
     * and whether the route runs at all that day.
     *
     *   daily mode       runs = true, bus = busForDate() (null = route's own bus)
     *   round_trip mode  the owner's chart: cycle = buses x stagger days; bus
     *                    k leaves outbound (to Nepal) on cycle day k x stagger
     *                    and on the return backAfter days later. The route runs
     *                    only when the bus due that day names it (or "any").
     *   no rotation      runs = true, bus = null
     *
     * @param array<string,mixed>|int $route routes row (needs id + to_city) or id
     * @return array{runs: bool, bus: ?int, leg: string, slot: int}
     */
    public static function planFor(array|int $route, string $travelDate): array
    {
        $rot = self::rotation();
        if (!$rot['enabled'] || $rot['buses'] === []) {
            return ['runs' => true, 'bus' => null, 'leg' => '', 'slot' => 0];
        }
        if ($rot['mode'] !== 'round_trip') {
            return ['runs' => true, 'bus' => self::busForDate($travelDate), 'leg' => '', 'slot' => 0];
        }
        if (is_int($route)) {
            $route = Database::fetch('SELECT id, from_city, to_city FROM routes WHERE id = :id', ['id' => $route]) ?? [];
        }
        require_once __DIR__ . '/fare.php';
        $outbound = Fare::routeDir($route) === 'toNepal';
        $routeId  = (int) ($route['id'] ?? 0);
        $leg      = $outbound ? 'out' : 'back';

        $n     = count($rot['buses']);
        $cycle = $n * $rot['stagger'];
        $i     = self::dayIndex($travelDate) % $cycle;
        if ($i < 0) {
            $i += $cycle;
        }
        foreach ($rot['buses'] as $k => $busId) {
            $day = $outbound ? $k * $rot['stagger'] : ($k * $rot['stagger'] + $rot['backAfter']) % $cycle;
            if ($day !== $i) {
                continue;
            }
            $want = (int) ($rot['routes'][$busId][$leg] ?? 0);
            if ($want === 0 || ($want > 0 && $want === $routeId)) {
                return ['runs' => true, 'bus' => $busId, 'leg' => $leg, 'slot' => $k + 1];
            }
        }
        return ['runs' => false, 'bus' => null, 'leg' => $leg, 'slot' => 0];
    }

    /**
     * Round-trip preview for the admin: per date, the bus (and its slot
     * number) that leaves Gujarat and the one that leaves the Nepal side
     * (null = nobody that way that day).
     *
     * @return array<int, array{date: string, out: ?int, outSlot: int, back: ?int, backSlot: int}>
     */
    public static function roundTripPreview(int $days = 12, ?string $fromDate = null): array
    {
        $rot = self::rotation();
        if (!$rot['enabled'] || $rot['mode'] !== 'round_trip' || $rot['buses'] === []) {
            return [];
        }
        $cycle = count($rot['buses']) * $rot['stagger'];
        $out   = [];
        $from  = $fromDate ?? todayISO();
        for ($d = 0; $d < max(1, $days); $d++) {
            $date = addDaysISO($from, $d);
            $i    = self::dayIndex($date) % $cycle;
            if ($i < 0) {
                $i += $cycle;
            }
            $row = ['date' => $date, 'out' => null, 'outSlot' => 0, 'back' => null, 'backSlot' => 0];
            foreach ($rot['buses'] as $k => $busId) {
                if ($k * $rot['stagger'] === $i) {
                    $row['out']     = $busId;
                    $row['outSlot'] = $k + 1;
                }
                if (($k * $rot['stagger'] + $rot['backAfter']) % $cycle === $i) {
                    $row['back']     = $busId;
                    $row['backSlot'] = $k + 1;
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The next `$days` days of the roster, for the admin preview: who
     * runs when, so the office can see the recycle before trusting it.
     *
     * @return array<int, array{date: string, busId: int, busName: string, busNumber: string}>
     */
    public static function upcoming(int $days = 7, ?string $fromDate = null): array
    {
        $rot = self::rotation();
        if (!$rot['enabled'] || $rot['buses'] === []) {
            return [];
        }

        $names = [];
        foreach (Database::fetchAll(
            'SELECT id, bus_name, bus_number FROM buses WHERE id IN ('
            . implode(',', array_map('intval', $rot['buses'])) . ')'
        ) as $b) {
            $names[(int) $b['id']] = $b;
        }

        $out  = [];
        $from = $fromDate ?? todayISO();

        for ($d = 0; $d < max(1, $days); $d++) {
            $date  = addDaysISO($from, $d);
            $busId = self::busForDate($date);
            if ($busId === null) {
                continue;
            }
            $out[] = [
                'date'      => $date,
                'busId'     => $busId,
                'busName'   => (string) ($names[$busId]['bus_name'] ?? ('Bus #' . $busId)),
                'busNumber' => (string) ($names[$busId]['bus_number'] ?? ''),
            ];
        }

        return $out;
    }

    /** Every bus the operator has, for the admin picker. */
    public static function allBuses(): array
    {
        return Database::fetchAll(
            'SELECT id, bus_name, bus_number, coach_type, is_active
               FROM buses ORDER BY is_active DESC, bus_name ASC'
        );
    }

    /**
     * Full detail for the admin fleet manager, with the reference counts that
     * tell a never-used coach (safe to hard-delete) apart from one that has
     * trips/routes behind it (keep the record — deactivate instead).
     */
    public static function manageBuses(): array
    {
        return Database::fetchAll(
            "SELECT b.id, b.bus_name, b.bus_number, b.coach_type, b.total_seats,
                    b.registration, b.is_active,
                    (SELECT COUNT(*) FROM schedules s WHERE s.bus_id = b.id) AS sched_count,
                    (SELECT COUNT(*) FROM routes r   WHERE r.bus_id = b.id) AS route_count
               FROM buses b
              ORDER BY b.is_active DESC, b.bus_name ASC"
        );
    }

    /**
     * Add a coach to the fleet. Returns the new bus id.
     *
     * @throws RuntimeException on a missing field or a duplicate plate
     */
    public static function createBus(string $busName, string $busNumber, string $coachType, int $totalSeats, string $registration = '', int $by = 0): int
    {
        $busName      = Security::clean($busName, 120);
        $busNumber    = Security::clean($busNumber, 30);
        $registration = Security::clean($registration, 60);
        $coachType    = in_array($coachType, ['seater', 'sleeper'], true) ? $coachType : 'sleeper';

        // Standardise: sleeper buses are always 72-seat 2-floor.
        if ($coachType === 'sleeper') {
            $totalSeats = 72;
        } else {
            $totalSeats = max(1, min(120, $totalSeats));
        }

        // Derive seat_model and floors from coach type + seat count.
        $seatModel = $coachType === 'sleeper'
            ? ($totalSeats >= 70 ? '72-sleeper' : '60-sleeper')
            : ($totalSeats >= 48 ? '50-seater' : '45-seater');
        $floors    = $coachType === 'sleeper' ? 2 : 1;

        if ($busName === '' || $busNumber === '') {
            throw new RuntimeException('A bus name and a bus number are both required.');
        }
        if (Database::fetch('SELECT id FROM buses WHERE bus_number = :n LIMIT 1', ['n' => $busNumber]) !== null) {
            throw new RuntimeException('Bus number ' . $busNumber . ' already exists.');
        }

        $id = Database::insert('buses', [
            'bus_number'   => $busNumber,
            'bus_name'     => $busName,
            'coach_type'   => $coachType,
            'total_seats'  => $totalSeats,
            'seat_model'   => $seatModel,
            'floors'       => $floors,
            'registration' => $registration !== '' ? $registration : null,
            'is_active'    => 1,
        ]);

        Logger::audit('fleet.bus_created', 'bus', (string) $id, [],
            ['bus_name' => $busName, 'bus_number' => $busNumber, 'coach_type' => $coachType, 'total_seats' => $totalSeats],
            'by admin#' . $by);

        return $id;
    }

    /**
     * Activate / deactivate a coach. An inactive bus stays in the records
     * (its past trips, manifests and tickets still reference it) but can be
     * hidden from new assignment.
     *
     * @throws RuntimeException if the bus is in the enabled rotation
     */
    public static function setActive(int $busId, bool $active, int $by = 0): void
    {
        $bus = Database::fetch('SELECT bus_name, bus_number, is_active FROM buses WHERE id = :i', ['i' => $busId]);
        if ($bus === null) {
            throw new RuntimeException('That bus no longer exists.');
        }
        if (!$active) {
            // Deactivating a bus that is still a rotation slot would silently
            // keep it serving trips — make the operator free the slot first.
            $rot = self::rotation();
            if ($rot['enabled'] && in_array($busId, $rot['buses'], true)) {
                throw new RuntimeException('This bus is in the active rotation. Clear its slot first, then deactivate it.');
            }
        }

        Database::update('buses', ['is_active' => $active ? 1 : 0], 'id = :i', ['i' => $busId]);

        Logger::audit($active ? 'fleet.bus_activated' : 'fleet.bus_deactivated', 'bus', (string) $busId,
            ['is_active' => (int) $bus['is_active']], ['is_active' => $active ? 1 : 0], 'by admin#' . $by);
    }

    /**
     * Hard-delete a coach — permitted ONLY when nothing references it. A bus
     * with schedules, routes or a rotation slot behind it is kept (its
     * bookings/manifests point at it) and must be deactivated instead.
     *
     * @throws RuntimeException when the bus is in use
     */
    public static function deleteBus(int $busId, int $by = 0): void
    {
        $bus = Database::fetch('SELECT bus_name, bus_number FROM buses WHERE id = :i', ['i' => $busId]);
        if ($bus === null) {
            throw new RuntimeException('That bus no longer exists.');
        }

        $sched = (int) Database::scalar('SELECT COUNT(*) FROM schedules WHERE bus_id = :i', ['i' => $busId]);
        $route = (int) Database::scalar('SELECT COUNT(*) FROM routes   WHERE bus_id = :i', ['i' => $busId]);
        $rot   = self::rotation();
        if ($sched > 0 || $route > 0 || in_array($busId, $rot['buses'], true)) {
            throw new RuntimeException('This bus has trips or routes attached — deactivate it instead of deleting.');
        }

        Database::delete('buses', 'id = :i', ['i' => $busId]);

        Logger::audit('fleet.bus_deleted', 'bus', (string) $busId, $bus, [], 'by admin#' . $by);
    }

    /**
     * Rename / renumber one bus. This is what makes "admin le bus number
     * daily update garna sakos" work: the ROTATION keeps pointing at the
     * same slot while the plate on that slot changes.
     *
     * @throws RuntimeException on a duplicate plate
     */
    public static function updateBus(int $busId, string $busName, string $busNumber, int $by = 0, ?string $coachType = null, ?int $totalSeats = null, ?string $registration = null): void
    {
        $busName   = Security::clean($busName, 120);
        $busNumber = Security::clean($busNumber, 30);

        if ($busId <= 0 || $busName === '' || $busNumber === '') {
            throw new RuntimeException('Both a bus name and a bus number are required.');
        }

        $clash = Database::fetch(
            'SELECT id FROM buses WHERE bus_number = :n AND id <> :i LIMIT 1',
            ['n' => $busNumber, 'i' => $busId]
        );
        if ($clash !== null) {
            throw new RuntimeException('Bus number ' . $busNumber . ' already belongs to another bus.');
        }

        $before = Database::fetch(
            'SELECT bus_name, bus_number, coach_type, total_seats, registration FROM buses WHERE id = :i',
            ['i' => $busId]
        );
        if ($before === null) {
            throw new RuntimeException('That bus no longer exists.');
        }

        // Name + number always update (the quick-rename path). The extra fleet
        // fields update only when the fuller edit form supplies them, so the
        // 4-argument rename callers keep working unchanged.
        $set = ['bus_name' => $busName, 'bus_number' => $busNumber];
        if ($coachType !== null) {
            $set['coach_type'] = in_array($coachType, ['seater', 'sleeper'], true) ? $coachType : (string) $before['coach_type'];
        }
        if ($totalSeats !== null) {
            $set['total_seats'] = max(1, min(120, $totalSeats));
        }
        if ($registration !== null) {
            $registration = Security::clean($registration, 60);
            $set['registration'] = $registration !== '' ? $registration : null;
        }

        Database::update('buses', $set, 'id = :i', ['i' => $busId]);

        Logger::audit('fleet.bus_updated', 'bus', (string) $busId, $before, $set, 'by admin#' . $by);
    }
}
