<?php
/**
 * =====================================================================
 *  ScheduleMaker — the "one row per route per travel date" auto-filler.
 *
 *  The whole booking stack keys off `schedules` rows (SELECTs, seat
 *  locks, availability, TripNotify reminders). If the office runs a
 *  daily coach and nobody hand-inserts a schedule for tomorrow, the
 *  seat map for tomorrow silently returns "no bus" — no error, just a
 *  quiet lost sale. This class refills the sliding window every night.
 *
 *  Non-destructive by design:
 *    - UNIQUE(route_id, travel_date) on `schedules` (uq_schedule_route_date)
 *      means the INSERT IGNORE cannot double-create; already-there rows
 *      are counted as "skipped_existing" and untouched.
 *    - Only routes with `is_active = 1 AND dep_time IS NOT NULL` are
 *      considered "daily service" — the operator toggles a route on
 *      or off in Admin -> Routes, which flips exactly this flag.
 *    - Routes with a NULL `bus_id` are skipped (no bus assigned yet;
 *      creating a row would break Seats::availability downstream).
 *    - When Fleet rotation is enabled (bus recycles every N days),
 *      Fleet::busForDate() overrides `routes.bus_id` so the correct
 *      coach in the rotation goes on the row — same behaviour as when
 *      an operator opens Admin -> Trips for a new date.
 *
 *  Used by:
 *    - cron/daily-schedule.php (nightly, recommended 30 0 * * *)
 *    - admin/routes.php "Refill schedule rows for next 30 days" button
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ScheduleMaker
{
    /** Hard ceiling so a typo cannot spray 3650 rows in one call. */
    public const MAX_DAYS = 90;

    /**
     * Insert schedule rows for every daily-service route across the
     * next $days days, starting at CURDATE() + $graceDays.
     *
     * @return array{
     *   created: int,
     *   skipped_existing: int,
     *   routes: array<int, string>,
     *   from: string,
     *   to: string
     * }
     */
    public static function ensureNextDays(PDO $db, int $days = 30, int $graceDays = 0): array
    {
        $days      = max(1, min(self::MAX_DAYS, $days));
        $graceDays = max(0, min(self::MAX_DAYS, $graceDays));

        // "Today" in the app's own timezone — MySQL on live runs SYSTEM
        // tz (IST on the host) but a stray VPS in UTC would otherwise
        // create the wrong first day. bootstrap.php has already set
        // date_default_timezone_set(APP_TIMEZONE).
        $startTs = strtotime('+' . $graceDays . ' day');
        $fromISO = date('Y-m-d', $startTs);
        $toISO   = date('Y-m-d', $startTs + ($days - 1) * 86400);

        // Daily-service routes = active + has a departure time set. The
        // schema doesn't carry an `is_daily_service` flag (memory
        // project-daily-service-button confirmed) — the toggle is
        // routes.is_active, exactly what Admin -> Routes flips today.
        $routes = self::dailyRoutes($db);

        $created = 0;
        $skipped = 0;
        $codes   = [];

        // Prepared once, rebound per row — PDO under this codebase does
        // NOT emulate prepares (see memory reference-local-test-traps),
        // so a fresh statement per iteration would waste a round trip.
        // slot 1 = the daily bus; the nightly seed never creates extra buses
        // (those are added by hand on the Bus Calendar) and never touches an
        // existing row, so a per-date OFF / retime / other vehicle survives.
        $stmt = $db->prepare(
            'INSERT IGNORE INTO `schedules`
                (route_id, travel_date, slot, bus_id, status, total_seats)
             VALUES
                (:route_id, :travel_date, 1, :bus_id, "scheduled", :total_seats)'
        );

        foreach ($routes as $r) {
            $routeId       = (int) $r['id'];
            $defaultBusId  = self::intOrNull($r['bus_id']);
            $defaultSeats  = self::defaultSeats($r);

            if ($defaultBusId === null) {
                // No bus on the route AND no rotation fallback below —
                // skip silently. The office will see it because the
                // route stays without schedules; failing loud would
                // spam the cron log every 5 minutes.
                if (!self::rotationEnabled()) {
                    continue;
                }
            }

            $anyRow = false;

            for ($i = 0; $i < $days; $i++) {
                $travelDate = date('Y-m-d', $startTs + $i * 86400);

                // Fleet rotation, when configured, is authoritative:
                // it is exactly the mechanism that assigns "today's bus
                // is #4, tomorrow's is #5" so a 3-bus fleet recycles.
                $busId = self::busForDate($travelDate, $defaultBusId);
                if ($busId === null) {
                    continue;
                }

                $stmt->execute([
                    ':route_id'    => $routeId,
                    ':travel_date' => $travelDate,
                    ':bus_id'      => $busId,
                    ':total_seats' => $defaultSeats,
                ]);

                if ($stmt->rowCount() > 0) {
                    $created++;
                    $anyRow = true;
                } else {
                    $skipped++;
                }
            }

            if ($anyRow) {
                $codes[] = (string) $r['route_code'];
            }
        }

        return [
            'created'          => $created,
            'skipped_existing' => $skipped,
            'routes'           => $codes,
            'from'             => $fromISO,
            'to'               => $toISO,
        ];
    }

    /**
     * Create ONE schedule row for a specific route + date, with optional
     * per-trip overrides. The write path for Admin -> Schedule Manager's
     * "Add trip" and "Duplicate to date" actions.
     *
     * Unlike ensureNextDays(), this is NOT idempotent-silent — a duplicate
     * (route_id, travel_date) is a caller mistake and must be surfaced,
     * not swallowed. Uses INSERT (which fails on UNIQUE violation) after
     * an explicit pre-check that returns a user-safe RuntimeException.
     *
     * $opts may set (all optional):
     *   dep_time_override : HH:MM string  (null / '' clears / omits override)
     *   bus_id            : int > 0       (0 / null / omitted → route default
     *                                       or Fleet rotation for the date)
     *   driver_id         : int > 0       (0 / null / omitted → unassigned)
     *   total_seats       : int > 0       (falls back to defaultSeats())
     *   fare_override     : float > 0     (per-seat price for THIS departure
     *                                       only; 0 / null / omitted → the
     *                                       normal route / direction fare)
     *   coach_type        : 'seater'|'sleeper'  (the coach THIS departure runs;
     *                                       omitted / '' → the route's own type,
     *                                       which is every row the seeder makes)
     *
     * @return array{id:int, created:bool, route_code:string, dep_time:?string}
     */
    /**
     * The slot number the next extra bus on this route + date should take
     * (2 when only the daily bus exists).
     */
    public static function nextFreeSlot(int $routeId, string $travelDate): int
    {
        $max = (int) Database::scalar(
            'SELECT COALESCE(MAX(slot), 1) FROM schedules WHERE route_id = :r AND travel_date = :d',
            ['r' => $routeId, 'd' => $travelDate],
            1
        );
        return max(2, $max + 1);
    }

    public static function insertOne(int $routeId, string $travelDate, array $opts = []): array
    {
        if ($routeId <= 0) {
            throw new RuntimeException('Please choose a route.');
        }

        // travel_date must be a valid Y-m-d and today or later.
        $ts = strtotime($travelDate);
        if ($ts === false || date('Y-m-d', $ts) !== $travelDate) {
            throw new RuntimeException('Travel date is not a valid YYYY-MM-DD.');
        }
        if ($travelDate < date('Y-m-d')) {
            throw new RuntimeException('Travel date cannot be in the past.');
        }

        // Route must exist AND be active (a schedule row for a disabled
        // route would show up in seat map / search as bookable, breaking
        // the "toggle route off" intent).
        $route = Database::fetch(
            "SELECT r.id, r.route_code, r.bus_id, r.coach_type, r.dep_time,
                    r.is_active,
                    b.total_seats AS bus_total_seats
               FROM routes r
               LEFT JOIN buses b ON b.id = r.bus_id
              WHERE r.id = :id",
            ['id' => $routeId]
        );
        if ($route === null) {
            throw new RuntimeException('That route no longer exists.');
        }
        if ((int) $route['is_active'] !== 1) {
            throw new RuntimeException('That route is disabled — enable it in Admin → Routes first.');
        }

        // Which departure of the day: 1 = the daily bus, 2+ = an extra bus
        // (Bus Calendar, 5 Sep 2026). One row per (route, date, slot) is
        // enforced at the schema level (uq_schedule_route_date_slot). A
        // friendly pre-check gives the operator a real message instead of a
        // raw SQL error.
        $slot = max(1, min(20, (int) ($opts['slot'] ?? 1)));
        $existing = Database::fetch(
            'SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = :s LIMIT 1',
            ['r' => $routeId, 'd' => $travelDate, 's' => $slot]
        );
        if ($existing !== null) {
            throw new RuntimeException(($slot > 1 ? 'Bus ' . $slot . ' for this route on ' : 'A trip for this route on ') . $travelDate . ' already exists (id #' . (int) $existing['id'] . ').'
                . ($slot === 1 ? ' Use "Extra bus" to run a second one that day.' : ''));
        }

        // Resolve bus_id: explicit opt wins; else route default with
        // Fleet rotation override, same behaviour as ensureNextDays.
        $busOpt = self::intOrNull($opts['bus_id'] ?? null);
        if ($busOpt !== null && $busOpt > 0) {
            $busId = $busOpt;
        } else {
            $defaultBusId = self::intOrNull($route['bus_id']);
            $busId        = self::busForDate($travelDate, $defaultBusId);
        }
        if ($busId === null) {
            throw new RuntimeException('No bus assigned — pick a bus or set a default on the route.');
        }

        // Resolve driver: explicit opt wins; else NULL (unassigned).
        $driverOpt = self::intOrNull($opts['driver_id'] ?? null);
        $driverId  = ($driverOpt !== null && $driverOpt > 0) ? $driverOpt : null;

        // dep_time_override: HH:MM if given, else NULL (route timetable wins).
        $depOverride = null;
        $depRaw      = trim((string) ($opts['dep_time_override'] ?? ''));
        if ($depRaw !== '') {
            if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $depRaw)) {
                throw new RuntimeException('Departure time must be HH:MM (24-hour).');
            }
            // Normalise to HH:MM:SS for TIME storage.
            $depOverride = str_pad($depRaw, 5, '0', STR_PAD_LEFT) . ':00';
        }

        // total_seats: explicit opt wins; else the bus's own count via
        // defaultSeats() (schema default 40 is the last fallback).
        $seatsOpt = self::intOrNull($opts['total_seats'] ?? null);
        $seats    = ($seatsOpt !== null && $seatsOpt > 0) ? $seatsOpt : self::defaultSeats($route);

        // Per-departure price (4 Sep 2026): an extra bus may run at its own
        // fare — a festival special, a different coach, a partner service.
        // Null means "the normal route / direction fare", which is every row
        // the nightly seeder creates, so the daily bus is unaffected.
        $fareOverride = self::fareInput($opts['fare_override'] ?? null);

        /* Per-departure SEAT LAYOUT (4 Sep 2026): the office can run a
           different kind of coach as an extra bus — a 40-seat seater beside
           the 72-berth sleeper. Null = the route's own coach, so the seat map
           is byte-for-byte what it was for every existing row. When a layout
           IS chosen, the seat count follows it rather than the route default. */
        $coachOverride = self::coachInput($opts['coach_type'] ?? null, (string) $route['coach_type']);
        if ($coachOverride !== null && ($seatsOpt === null || $seatsOpt <= 0)) {
            $seats = count(Seats::seatIds($coachOverride, 'sharing'));
        }

        $data = [
            'route_id'    => $routeId,
            'travel_date' => $travelDate,
            'slot'        => $slot,
            'bus_id'      => $busId,
            'driver_id'   => $driverId,
            'total_seats' => $seats,
            'status'      => 'scheduled',
        ];
        if ($depOverride !== null) {
            $data['dep_time_override'] = $depOverride;
        }
        if ($fareOverride !== null) {
            $data['fare_override'] = $fareOverride;
        }
        if ($coachOverride !== null) {
            $data['coach_type_override'] = $coachOverride;
        }

        try {
            $newId = Database::insert('schedules', $data);
        } catch (Throwable $e) {
            // Duplicate-key race (someone inserted between our pre-check
            // and here) — refuse cleanly rather than swallow.
            if (str_contains($e->getMessage(), '1062') || stripos($e->getMessage(), 'Duplicate') !== false) {
                throw new RuntimeException('A trip for this route on ' . $travelDate . ' already exists.');
            }
            throw $e;
        }

        return [
            'id'         => (int) $newId,
            'created'    => true,
            'route_code' => (string) $route['route_code'],
            'dep_time'   => $depOverride !== null
                ? substr($depOverride, 0, 5)
                : (isset($route['dep_time']) ? substr((string) $route['dep_time'], 0, 5) : null),
        ];
    }

    /* -----------------------------------------------------------------
     *  Internals — kept tiny so ensureNextDays reads top-to-bottom.
     * ----------------------------------------------------------------- */

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function dailyRoutes(PDO $db): array
    {
        $stmt = $db->query(
            "SELECT r.id, r.route_code, r.bus_id, r.coach_type,
                    b.total_seats AS bus_total_seats
               FROM routes r
               LEFT JOIN buses b ON b.id = r.bus_id
              WHERE r.is_active = 1
                AND r.dep_time IS NOT NULL
              ORDER BY r.sort_order, r.id"
        );
        if ($stmt === false) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $rows;
    }

    /**
     * schedules.total_seats defaults to 40 in the schema, but a sleeper
     * carries 72 in this fleet — use the bus's own count when we have
     * it so the seat map for a new date is right from row zero.
     */
    private static function defaultSeats(array $route): int
    {
        $busSeats = self::intOrNull($route['bus_total_seats'] ?? null);
        if ($busSeats !== null && $busSeats > 0) {
            return $busSeats;
        }
        // Fallback matches schema default so a legacy route without a
        // bus still gets a sensible value.
        return 40;
    }

    private static function busForDate(string $travelDate, ?int $default): ?int
    {
        if (class_exists('Fleet', false) || class_exists('Fleet')) {
            $rotated = Fleet::busForDate($travelDate);
            if ($rotated !== null) {
                return $rotated;
            }
        }
        return $default;
    }

    private static function rotationEnabled(): bool
    {
        if (!class_exists('Fleet')) {
            return false;
        }
        $rot = Fleet::rotation();
        return !empty($rot['enabled']) && !empty($rot['buses']);
    }

    /**
     * Validate a per-departure price. Blank / zero means "no override";
     * anything else must be a sane positive amount, because this figure is
     * what a passenger is actually charged.
     *
     * @throws RuntimeException with a user-safe message
     */
    public static function fareInput(mixed $raw): ?float
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        if (!is_numeric($raw)) {
            throw new RuntimeException('The price must be a number (or left blank for the normal fare).');
        }
        $v = round((float) $raw, 2);
        if ($v <= 0.0) {
            return null;                       // 0 / negative = clear the override
        }
        if ($v > 100000.0) {
            throw new RuntimeException('That price looks wrong — the maximum is ₹1,00,000 per seat.');
        }
        return $v;
    }

    /**
     * Validate a per-departure coach type. Blank means "run the route's own
     * coach"; so does naming the route's own type, so an override is only ever
     * stored when it genuinely differs and NULL keeps its single meaning.
     *
     * @throws RuntimeException with a user-safe message
     */
    public static function coachInput(mixed $raw, string $routeCoach = ''): ?string
    {
        $v = strtolower(trim((string) ($raw ?? '')));
        if ($v === '') {
            return null;
        }
        if ($v !== 'seater' && $v !== 'sleeper') {
            throw new RuntimeException('Coach type must be either seater or sleeper.');
        }
        return $v === strtolower($routeCoach) ? null : $v;
    }

    private static function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === false) {
            return null;
        }
        return (int) $v;
    }
}
