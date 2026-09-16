<?php
/**
 * =====================================================================
 *  TripStatus — one place that says what a trip's live state is.
 *
 *  The database stores only four states on `schedules.status`
 *  (scheduled | departed | arrived | cancelled) plus the three
 *  operator milestones on `trip_status` (departed | border | arrived)
 *  and an optional `delay_minutes` / `delay_note` pair. The admin
 *  Control Center needs nine — Upcoming, Departing Soon, Boarding,
 *  Departed, On Route, Arrived, Completed, Delayed, Cancelled — and
 *  every one of those has to fall out of NOW() versus the departure
 *  and arrival wall-clock so an operator never has to press a button
 *  to move a trip from "in 2 hours" into "boarding". Everything here
 *  is derivation, not storage; the same row rendered again a minute
 *  later will re-classify itself.
 *
 *  The compute() call takes ONE schedule row plus its marks (from
 *  TripNotify::statusMap()) and returns a small array with the state,
 *  a human label, a colour token the dashboard CSS knows, and the
 *  minute delta to departure — so the same shape drives both the
 *  first server render and the 15-second JSON poll.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class TripStatus
{
    /* The full ladder — every one of these gets its own colour so a
       glance at the dashboard shows what needs attention now. */
    public const STATE_UPCOMING   = 'upcoming';
    public const STATE_DEPARTING  = 'departing';   // "Departing Soon"
    public const STATE_BOARDING   = 'boarding';
    public const STATE_DEPARTED   = 'departed';
    public const STATE_ON_ROUTE   = 'on_route';
    public const STATE_ARRIVED    = 'arrived';
    public const STATE_COMPLETED  = 'completed';
    public const STATE_DELAYED    = 'delayed';
    public const STATE_CANCELLED  = 'cancelled';
    /* Sold-out is derived from seat inventory rather than the wall-clock —
       a trip whose sharing seats are all taken cannot accept a new booking
       even while its state would otherwise read "Upcoming" / "Departing" /
       "Boarding" / "Delayed". Admins can still EDIT sold-out trips (a
       counter refund reopens a berth), but the customer-facing bookable
       check refuses them. Handled as a post-check inside compute(). */
    public const STATE_SOLD_OUT   = 'sold_out';

    /**
     * Thresholds (minutes before scheduled departure) — tuned so an
     * operator standing at the counter sees the state change roughly
     * every hour without noise. If you make BOARDING wider than about
     * 30 minutes the "in X h" text starts fighting the badge.
     */
    private const BOARDING_MIN   = 30;   // 0..30 min → Boarding
    private const DEPARTING_MIN  = 180;  // 30..180 min → Departing Soon
    /** Past scheduled departure by this many minutes with no `departed`
     *  mark counts as Delayed (before that, it just reads "Departed"). */
    private const DELAY_GRACE    = 15;

    /**
     * Compute the state for one schedule row.
     *
     * @param array<string, mixed> $schedule Must carry:
     *   - travel_date (Y-m-d)
     *   - dep_time    (HH:MM:SS)
     *   - arr_time    (HH:MM:SS, optional — needed to auto-arrive)
     *   - status      (scheduled|departed|arrived|cancelled — optional)
     *   - delay_minutes (int, optional)
     *   - day_offset  (int, optional — routes.day_offset for arr_time)
     *   - marks       (from TripNotify::statusMap()[scheduleId]) — optional
     * @param int|null $now UNIX ts, defaults to time() — inject in tests.
     * @return array{state:string,label:string,color:string,minutesTo:int,detail:string}
     */
    public static function compute(array $schedule, ?int $now = null): array
    {
        $now    = $now ?? time();
        $stored = strtolower((string) ($schedule['status'] ?? 'scheduled'));

        // Cancelled dominates every other signal — even if a departed
        // milestone was recorded before someone cancelled the trip.
        if ($stored === 'cancelled') {
            return self::state(self::STATE_CANCELLED, 0, 'Trip cancelled');
        }

        $marks = self::indexMarks($schedule['marks'] ?? []);

        // A driver PWA "arrived" mark is authoritative — the bus is at
        // the terminal. Older than an hour: the trip is fully done.
        if (isset($marks['arrived'])) {
            $ago = max(0, $now - $marks['arrived']);
            if ($ago >= 3600) {
                return self::state(self::STATE_COMPLETED, 0, 'Completed ' . self::agoLabel($ago));
            }
            return self::state(self::STATE_ARRIVED, 0, 'Arrived ' . self::agoLabel($ago));
        }

        $depTs = self::depTs($schedule);
        $arrTs = self::arrTs($schedule);
        $delay = max(0, (int) ($schedule['delay_minutes'] ?? 0));
        // Apply the operator's declared delay to the effective departure
        // time so the "Boarding" / "Departing Soon" chip does the right
        // thing when a trip is running late without anyone re-timing it.
        $effDep = $depTs !== null ? $depTs + $delay * 60 : null;

        // Departed milestone: the bus has left. Auto-promote to Arrived
        // 30 min after the scheduled arr_time — the manifest closes on
        // its own even if the driver PWA never fires the "arrived" mark.
        if (isset($marks['departed'])) {
            $sinceDep = max(0, $now - $marks['departed']);
            if ($arrTs !== null && $now >= $arrTs + 30 * 60) {
                return self::state(self::STATE_ARRIVED, 0, 'Should have arrived');
            }
            // Border milestone is a mid-route waypoint; keep the trip
            // in On Route but reflect the location in the detail.
            if (isset($marks['border'])) {
                $ago = max(0, $now - $marks['border']);
                return self::state(self::STATE_ON_ROUTE, 0, 'At border · ' . self::agoLabel($ago));
            }
            return self::state(self::STATE_ON_ROUTE, 0, 'Running · ' . self::agoLabel($sinceDep));
        }

        // Past departure with no milestone: the trip should have left
        // but nobody has pressed the button. A short grace period reads
        // as "Departed" (probably en route, driver simply hasn't tapped);
        // beyond that it graduates to Delayed so someone chases it.
        if ($effDep !== null && $now >= $effDep) {
            $overdue = ($now - $effDep) / 60;
            if ($delay > 0 || $overdue > self::DELAY_GRACE) {
                $note = (string) ($schedule['delay_note'] ?? '');
                return self::maybeSoldOut($schedule, self::state(self::STATE_DELAYED, 0,
                    $delay > 0 ? "Delay {$delay}m" . ($note !== '' ? ' · ' . $note : '')
                              : 'Overdue ' . self::minsLabel((int) $overdue)));
            }
            return self::state(self::STATE_DEPARTED, 0, 'Departure time reached');
        }

        // Everything from here on is future — three tiers by minute delta.
        $mins = $effDep !== null ? (int) ceil(($effDep - $now) / 60) : PHP_INT_MAX;

        if ($mins <= self::BOARDING_MIN) {
            return self::maybeSoldOut($schedule, self::state(self::STATE_BOARDING, $mins, 'Boarding · ' . self::minsLabel($mins)));
        }
        if ($mins <= self::DEPARTING_MIN) {
            return self::maybeSoldOut($schedule, self::state(self::STATE_DEPARTING, $mins, 'Departing in ' . self::minsLabel($mins)));
        }
        return self::maybeSoldOut($schedule, self::state(self::STATE_UPCOMING, $mins, self::inLabel($mins)));
    }

    /**
     * Static state → colour / label map. Kept next to compute() so a
     * new state means editing one file, not four templates.
     *
     * @return array{state:string,label:string,color:string}
     */
    public static function meta(string $state): array
    {
        static $map = [
            self::STATE_UPCOMING  => ['label' => 'Upcoming',        'color' => '#6b7688'],
            self::STATE_DEPARTING => ['label' => 'Departing Soon',  'color' => '#f39c12'],
            self::STATE_BOARDING  => ['label' => 'Boarding',        'color' => '#e67e22'],
            self::STATE_DEPARTED  => ['label' => 'Departed',        'color' => '#2E5FA8'],
            self::STATE_ON_ROUTE  => ['label' => 'On Route',        'color' => '#00897b'],
            self::STATE_ARRIVED   => ['label' => 'Arrived',         'color' => '#0a8b4b'],
            self::STATE_COMPLETED => ['label' => 'Completed',       'color' => '#065a30'],
            self::STATE_DELAYED   => ['label' => 'Delayed',         'color' => '#c0392b'],
            self::STATE_SOLD_OUT  => ['label' => 'Sold Out',        'color' => '#d68910'],
            self::STATE_CANCELLED => ['label' => 'Cancelled',       'color' => '#95a5a6'],
        ];
        $row = $map[$state] ?? ['label' => ucfirst($state), 'color' => '#6b7688'];
        return ['state' => $state] + $row;
    }

    /**
     * Convenience: run compute() against a whole list of schedule rows,
     * fetching the milestones for all of them in one query rather than
     * one per row. Rows are returned unchanged, plus a `_status` key.
     *
     * @param array<int, array<string, mixed>> $rows must each carry `id`
     * @return array<int, array<string, mixed>>
     */
    public static function annotate(array $rows, ?int $now = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $r): int => (int) ($r['id'] ?? $r['schedule_id'] ?? 0),
            $rows
        ))));
        if ($ids === []) {
            return $rows;
        }

        // TripNotify::statusMap() lives in the tripnotify feature already —
        // reuse it so the "border" / "arrived" milestone semantics stay in
        // one place. Falls through to an empty map when the class hasn't
        // been loaded (e.g. very early boot) so annotation still works.
        $marks = [];
        if (class_exists('TripNotify', false) || (function_exists('spl_autoload_call') && class_exists('TripNotify'))) {
            $marks = TripNotify::statusMap($ids);
        }

        foreach ($rows as &$row) {
            $sid = (int) ($row['id'] ?? $row['schedule_id'] ?? 0);
            $row['marks']   = $marks[$sid] ?? [];
            $row['_status'] = self::compute($row, $now);
        }
        unset($row);

        return $rows;
    }

    /* -----------------------------------------------------------------
     *  Edit-window gates — Phase 6.
     * ----------------------------------------------------------------- */

    /** States where bookings/seats are still open for change. Sold-out
     *  is editable — a counter refund reopens a seat and the trip goes
     *  back to Boarding/Departing on the next compute() call. */
    private const EDITABLE_STATES = [
        self::STATE_UPCOMING, self::STATE_DEPARTING, self::STATE_BOARDING,
        self::STATE_DELAYED,  self::STATE_SOLD_OUT,
    ];
    /** States where NEW bookings are still accepted (narrower — no
     *  new bookings once we're boarding, but you can still edit an
     *  existing one at the counter). Sold-out is deliberately excluded:
     *  the seat inventory says there's nothing left to sell.
     *
     *  This is the ONLINE-customer gate: a passenger on the website must
     *  not be able to buy a seat on a bus that is already boarding. The
     *  counter/office gate below is deliberately wider. */
    private const BOOKABLE_STATES = [
        self::STATE_UPCOMING, self::STATE_DEPARTING, self::STATE_DELAYED,
    ];
    /** States where the COUNTER (office / agent) may still sell a seat.
     *  Deliberately wide (owner ask, 2 Sep 2026): the desk must be able to
     *  add a passenger who was missed even after the coach has left and while
     *  it is still running through the night — so it stays open through
     *  BOARDING, DEPARTED and ON_ROUTE, right up until the trip ARRIVES the
     *  next day. Only SOLD_OUT (no inventory) and ARRIVED/COMPLETED/CANCELLED
     *  (the trip is over) close it; a superadmin still bypasses even those.
     *  The website's own gate (BOOKABLE_STATES) stays narrow — a customer can
     *  never buy onto a bus that has already left. */
    private const COUNTER_BOOKABLE_STATES = [
        self::STATE_UPCOMING, self::STATE_DEPARTING, self::STATE_DELAYED,
        self::STATE_BOARDING, self::STATE_DEPARTED, self::STATE_ON_ROUTE,
    ];

    /**
     * Can existing bookings / seats on this trip be modified?
     * Superadmins bypass via their own flag — this only answers the
     * schedule's own state.
     */
    public static function isEditable(array $schedule, ?int $now = null): bool
    {
        $s = self::compute($schedule, $now);
        return in_array($s['state'], self::EDITABLE_STATES, true);
    }

    /**
     * Can NEW bookings still be placed on this trip? (Online-customer gate.)
     */
    public static function isBookable(array $schedule, ?int $now = null): bool
    {
        $s = self::compute($schedule, $now);
        return in_array($s['state'], self::BOOKABLE_STATES, true);
    }

    /**
     * Can the COUNTER (office / agent) still sell a walk-in on this trip?
     * Wider than isBookable() — stays open through the Boarding window.
     */
    public static function isCounterBookable(array $schedule, ?int $now = null): bool
    {
        $s = self::compute($schedule, $now);
        return in_array($s['state'], self::COUNTER_BOOKABLE_STATES, true);
    }

    /**
     * Quick helper: fetch one schedule row with enough columns for
     * compute() and return [editable:bool, state:string, label:string].
     * Call from any admin handler that needs a gate before mutating.
     */
    public static function editableFor(int $scheduleId, ?int $now = null): array
    {
        // dep_time is the SCHEDULED effective departure — a per-schedule
        // dep_time_override (Phase 5+ delay workflow) wins over the route's
        // default, so a rescheduled trip's editability window follows the
        // new time rather than the printed timetable. delay_minutes is a
        // separate DELTA layered on top by compute() and stays untouched.
        $row = Database::fetch(
            "SELECT s.id, s.travel_date, s.status, s.delay_minutes, s.delay_note, s.total_seats,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                    r.arr_time, r.day_offset
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
              WHERE s.id = :id",
            ['id' => $scheduleId]
        );
        if ($row === null) {
            return ['editable' => false, 'state' => 'unknown', 'label' => 'Trip not found'];
        }
        $status = self::compute($row, $now);
        return [
            'editable'        => in_array($status['state'], self::EDITABLE_STATES, true),
            'bookable'        => in_array($status['state'], self::BOOKABLE_STATES, true),
            'counterBookable' => in_array($status['state'], self::COUNTER_BOOKABLE_STATES, true),
            'state'           => $status['state'],
            'label'           => $status['label'],
        ];
    }

    /**
     * Counter/agent LATE-BOOKING grace (owner ask, Sep 2026): a bus that has
     * left Surat can still take an agent-recorded ticket for this many hours
     * after its SCHEDULED departure — so a late/missed passenger who actually
     * travelled can be entered even the next day. Anchored to the scheduled
     * departure (not actual departed_at), matching how the owner states it
     * ("bus 1 baje chutchha, chuteko + 24 ghanta").
     */
    public const COUNTER_LATE_HOURS = 24;

    /**
     * Scheduled (effective) departure UNIX ts for a schedule — a per-schedule
     * dep_time_override wins over the route default, exactly as compute()/
     * editableFor() resolve it. Null if the row/time can't be resolved.
     */
    public static function scheduledDepartureTs(int $scheduleId): ?int
    {
        $row = Database::fetch(
            "SELECT s.travel_date, COALESCE(s.dep_time_override, r.dep_time) AS dep_time
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.id = :id",
            ['id' => $scheduleId]
        );
        if ($row === null) { return null; }
        $date = (string) ($row['travel_date'] ?? '');
        $time = (string) ($row['dep_time'] ?? '');
        if ($date === '' || $time === '') { return null; }
        $ts = strtotime($date . ' ' . $time);
        return $ts === false ? null : $ts;
    }

    /**
     * Can the COUNTER (office / agent) still sell on this trip, INCLUDING the
     * post-departure grace window? True when the live state is already
     * counter-bookable, OR the trip has run its course (e.g. ARRIVED /
     * COMPLETED after the overnight leg) but we are still within $graceHours
     * of its scheduled departure. SOLD_OUT (no seats) and CANCELLED (trip
     * void) always stay closed — the grace never resurrects those.
     *
     * @return array{ok:bool,state:string,label:string,within_grace:bool}
     */
    public static function counterBookableWithin(int $scheduleId, int $graceHours = self::COUNTER_LATE_HOURS, ?int $now = null): array
    {
        $g     = self::editableFor($scheduleId, $now);
        $state = (string) ($g['state'] ?? 'unknown');
        $label = (string) ($g['label'] ?? '');
        $nowTs = $now ?? time();
        $depTs = self::scheduledDepartureTs($scheduleId);

        $shut = static fn(): array =>
            ['ok' => false, 'state' => $state, 'label' => $label, 'within_grace' => false];

        // No inventory (sold out) or a void/unknown trip: never open, and the
        // grace never resurrects these.
        if (in_array($state, [self::STATE_SOLD_OUT, self::STATE_CANCELLED, 'unknown'], true)) {
            return $shut();
        }

        // Before / at departure (upcoming, departing soon, boarding): the
        // counter is always open — this is the ordinary walk-in window.
        if (in_array($state, [self::STATE_UPCOMING, self::STATE_DEPARTING, self::STATE_BOARDING], true)) {
            return ['ok' => true, 'state' => $state, 'label' => $label, 'within_grace' => false];
        }

        // Departed / on-route / delayed / arrived / completed: open ONLY while
        // still within $graceHours of the SCHEDULED departure. This both grants
        // the owner's post-departure window AND closes the old behaviour where
        // a long-past trip read "Delayed" and stayed counter-bookable forever.
        if ($depTs !== null) {
            if ($nowTs >= $depTs && $nowTs <= $depTs + $graceHours * 3600) {
                return ['ok' => true, 'state' => $state, 'label' => $label, 'within_grace' => true];
            }
            return $shut();
        }

        // Departure time unknown — don't newly break anything; defer to the
        // base counter-bookable state.
        return ['ok' => (bool) ($g['counterBookable'] ?? false), 'state' => $state, 'label' => $label, 'within_grace' => false];
    }

    /* -----------------------------------------------------------------
     *  Internals — kept out of every render path.
     * ----------------------------------------------------------------- */

    /**
     * @return array{state:string,label:string,color:string,minutesTo:int,detail:string}
     */
    private static function state(string $state, int $minutesTo = 0, string $detail = ''): array
    {
        $m = self::meta($state);
        return $m + ['minutesTo' => $minutesTo, 'detail' => $detail];
    }

    /**
     * Upgrade an "otherwise still open" state to SOLD_OUT when the
     * schedule row carries a seat count and every sharing seat has
     * been sold. Only fires for the four states where a customer could
     * otherwise still book — everything else is passed through unchanged
     * so a trip that has departed is never re-labelled "Sold Out".
     *
     * The seat total lives on schedules.total_seats and the sold count
     * is either explicitly enriched by the caller (`seats_booked`) or
     * available as the aggregate `sold` alias the dashboard/JSON query
     * already computes. Callers that don't supply either just fall
     * through and get the un-adjusted state.
     *
     * @param array<string, mixed> $schedule
     * @param array{state:string,label:string,color:string,minutesTo:int,detail:string} $result
     * @return array{state:string,label:string,color:string,minutesTo:int,detail:string}
     */
    private static function maybeSoldOut(array $schedule, array $result): array
    {
        static $open = [
            self::STATE_UPCOMING, self::STATE_DEPARTING,
            self::STATE_BOARDING, self::STATE_DELAYED,
        ];
        if (!in_array($result['state'], $open, true)) {
            return $result;
        }
        $total = (int) ($schedule['total_seats'] ?? 0);
        if ($total <= 0) {
            return $result;
        }
        // Accept either the explicit seats_booked key OR the `sold`
        // alias the trips-data / live-status queries emit. Absence
        // means "we don't know yet" — leave the state alone rather
        // than guessing zero and never triggering sold-out.
        if (array_key_exists('seats_booked', $schedule)) {
            $booked = (int) $schedule['seats_booked'];
        } elseif (array_key_exists('sold', $schedule)) {
            $booked = (int) $schedule['sold'];
        } else {
            return $result;
        }
        if ($booked < $total) {
            return $result;
        }
        $meta = self::meta(self::STATE_SOLD_OUT);
        return $meta + [
            'minutesTo' => $result['minutesTo'],
            'detail'    => 'Sold out' . ($result['detail'] !== '' ? ' · ' . $result['detail'] : ''),
        ];
    }

    /**
     * Turn a TripNotify::statusMap() row (event => 'Y-m-d H:i:s') into
     * (event => unix ts) — one place, one strtotime() rather than N.
     *
     * @param array<string, string> $marks
     * @return array<string, int>
     */
    private static function indexMarks(array $marks): array
    {
        $out = [];
        foreach ($marks as $event => $when) {
            $ts = strtotime((string) $when);
            if ($ts !== false) {
                $out[(string) $event] = $ts;
            }
        }
        return $out;
    }

    private static function depTs(array $schedule): ?int
    {
        $date = (string) ($schedule['travel_date'] ?? '');
        // Per-schedule dep_time_override (Phase 5+ delay workflow) wins over
        // the route's printed dep_time when the caller supplies it as its
        // own column; SELECTs that already resolved it via COALESCE just
        // send it as dep_time and fall through the null-coalesce below.
        // delay_minutes is layered on the resulting timestamp by compute(),
        // not here — so this stays a pure scheduled-time reader.
        $override = (string) ($schedule['dep_time_override'] ?? '');
        $time = $override !== '' ? $override : (string) ($schedule['dep_time'] ?? '');
        if ($date === '' || $time === '') { return null; }
        $ts = strtotime($date . ' ' . $time);
        return $ts === false ? null : $ts;
    }

    /**
     * arr_time can be next-day — routes.day_offset carries a +1 when
     * the bus arrives the morning after departure. Fall through to
     * null when we don't have enough to compute; compute() will simply
     * skip the auto-arrival promotion in that case.
     */
    private static function arrTs(array $schedule): ?int
    {
        $date = (string) ($schedule['travel_date'] ?? '');
        $time = (string) ($schedule['arr_time'] ?? '');
        if ($date === '' || $time === '') { return null; }
        $offset = max(0, (int) ($schedule['day_offset'] ?? 1)) - 1; // routes.day_offset defaults to 1 for same-day
        $ts = strtotime($date . ' ' . $time . ' +' . $offset . ' day');
        return $ts === false ? null : $ts;
    }

    private static function minsLabel(int $mins): string
    {
        $mins = max(0, $mins);
        if ($mins < 60)  { return $mins . 'm'; }
        $h = intdiv($mins, 60); $m = $mins % 60;
        return $m === 0 ? $h . 'h' : $h . 'h ' . $m . 'm';
    }

    private static function inLabel(int $mins): string
    {
        if ($mins >= 24 * 60) {
            $days = intdiv($mins, 24 * 60);
            return 'in ' . $days . 'd';
        }
        return 'in ' . self::minsLabel($mins);
    }

    private static function agoLabel(int $secs): string
    {
        $mins = intdiv(max(0, $secs), 60);
        if ($mins < 1)   { return 'just now'; }
        return self::minsLabel($mins) . ' ago';
    }
}
