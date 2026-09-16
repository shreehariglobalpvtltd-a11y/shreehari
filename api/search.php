<?php
/**
 * POST /api/search.php  — find buses for a route + date.
 * Body: { from, to, date }
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/tripstatus.php';

try {
    $from = Security::clean(Response::field('from', ''), 80);
    $to   = Security::clean(Response::field('to', ''), 80);
    $date = Security::clean(Response::field('date', ''), 10);

    if ($from === '' || $to === '') {
        Response::invalid(['from' => 'Choose your route.']);
    }
    if (!Security::isValidDate($date)) {
        Response::invalid(['date' => 'Choose a valid travel date.']);
    }

    // Agent context (owner ask, 3 Sep 2026): a VALID SHG-NNN agent code lets the
    // search surface a bus that has already departed — up to 24h after its
    // scheduled departure — so the agent can still book it from the customer
    // app. Anonymous callers (no / invalid code) keep every refusal below
    // byte-for-byte; the per-route grace test (routeSellableForAgent) bounds
    // how far back an agent can actually reach.
    require_once INCLUDE_PATH . '/agentwallet.php';
    $agentCodeRaw = strtoupper(Security::clean((string) (Response::field('agentCode', '') ?: Response::field('referralCode', '')), 20));
    $agentMode    = ($agentCodeRaw !== '' && AgentWallet::resolveAgentCodeFromString($agentCodeRaw) !== null)
        // Counter mode (3 Sep 2026): signed-in staff who may sell see the same
        // departed-within-24h buses the agent panel does, without typing a code.
        || (Auth::admin() !== null && (Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin()));

    if ($date < todayISO() && !$agentMode) {
        Response::invalid(['date' => 'Travel date cannot be in the past.']);
    }

    // Inauguration is 2 Sep 2026 — nothing is sold for earlier travel, and
    // sales stay inside the rolling horizon (both settings-driven).
    $window = bookingWindow();
    if ($date < $window['from'] && !$agentMode) {
        Response::invalid(['date' => 'Booking opens for travel from ' . formatDate($window['from']) . ' — the inaugural departure. Choose a date from there on.']);
    }
    // Selling staff (6 Sep 2026) are not held to the 30-day horizon either —
    // a group booking two months out is recorded at the counter today.
    if ($date > $window['to'] && !Auth::isSellingStaff()) {
        Response::invalid(['date' => 'Booking is currently open up to ' . formatDate($window['to']) . '. Later dates open soon.']);
    }

    Security::requireRateLimit('search', Security::clientIp(), 60, 60);

    /* Master switch (4 Sep 2026): Admin → Buses → "Daily service" OFF empties
       the board for everyone and the app shows the office's note instead of
       "no buses found". Bookings are refused server-side as well. */
    if (!Settings::getBool('daily_service_on', true)) {
        Response::success([
            'date'          => $date,
            'requestedDate' => $date,
            'nextAvailable' => false,
            'from'          => $from,
            'to'            => $to,
            'count'         => 0,
            'results'       => [],
            'serviceOff'    => true,
            'note'          => Settings::getString('daily_service_note', ''),
        ]);
    }

    /* Rupaidiha (the India-side border drop) and Nepalgunj are the SAME
       Nepal-side endpoint of this corridor. The buses are stored as
       Ahmedabad<->Nepalgunj, but the ticketed drop is Rupaidiha and the search
       boxes now say "Rupaidiha", so treat the two names as equivalent when
       matching — a search for either still finds the routes and no booking is
       lost to the label change. */
    $expand = static function (string $city): array {
        $pair = ['nepalgunj', 'rupaidiha'];
        return in_array(strtolower($city), $pair, true) ? $pair : [strtolower($city)];
    };
    $fromList = $expand($from);
    $toList   = $expand($to);
    $params   = [];
    $fromPh   = [];
    $toPh     = [];
    foreach ($fromList as $i => $v) { $k = 'f' . $i; $fromPh[] = ':' . $k; $params[$k] = $v; }
    foreach ($toList as $i => $v)   { $k = 't' . $i; $toPh[]   = ':' . $k; $params[$k] = $v; }

    /* A BOARDING POINT IS A STOP ON THE RUN, NOT A SEPARATE ROUTE.
       Matching only `routes.from_city` meant the daily Gujarat -> Rupaidiha
       coach was findable as "Ahmedabad" and nowhere else, so picking Surat,
       Baroda, S Hari Parking or Nana Chiloda returned NO buses
       even though that same bus stops at every one of them. A town now
       matches a route when it is the endpoint city OR a stop the coach
       actually calls at — boarding side for where you get on, drop side for
       where you get off. Town names are compared through Boarding::townKey(),
       the same normaliser the pickup cut-off uses, so the decorated labels
       ("Mehsana @ 23:00 [...]") still match. */
    // A DEACTIVATED / under-maintenance bus stops selling immediately (Point 3:
    // "changes apply instantly to the customer booking page"). The guard is
    // NULL-safe on purpose: a route with no bus assigned yet (b.id IS NULL) must
    // keep selling — only an EXPLICITLY deactivated bus (is_active = 0) hides
    // its route. A naive `AND b.is_active = 1` would evaluate NULL for every
    // unassigned route and wrongly kill all of them.
    $candidates = Database::fetchAll(
        'SELECT r.*, b.bus_name AS bus, b.bus_number, b.amenities AS bus_amenities
           FROM routes r
           LEFT JOIN buses b ON b.id = r.bus_id
          WHERE r.is_active = 1
            AND (b.id IS NULL OR b.is_active = 1)
          ORDER BY r.sort_order, r.dep_time'
    );

    /** Does this town name appear among a route's stops of the given type? */
    $servesTown = static function (int $routeId, string $type, string $town): bool {
        static $cache = [];
        $key = $routeId . '|' . $type;
        if (!isset($cache[$key])) {
            $cache[$key] = array_map(
                static fn(array $r): string => Boarding::townKey((string) $r['stop_name']),
                Database::fetchAll(
                    'SELECT stop_name FROM route_stops WHERE route_id = :r AND stop_type = :t',
                    ['r' => $routeId, 't' => $type]
                )
            );
        }
        return in_array(Boarding::townKey($town), $cache[$key], true);
    };

    /** Either endpoint city, or a stop the coach calls at. */
    $matches = static function (array $route, string $side, array $wanted) use ($servesTown): bool {
        $cityCol = $side === 'boarding' ? 'from_city' : 'to_city';
        foreach ($wanted as $town) {
            if (strcasecmp((string) $route[$cityCol], $town) === 0) {
                return true;
            }
            if ($servesTown((int) $route['id'], $side, $town)) {
                return true;
            }
        }
        return false;
    };

    $routes = [];
    foreach ($candidates as $route) {
        if ($matches($route, 'boarding', $fromList) && $matches($route, 'drop', $toList)) {
            $routes[] = $route;
        }
    }
    unset($params, $fromPh, $toPh);

    /* Display the India-side border endpoint as "Rupaidiha" wherever the
       corridor is named, even though the bus row still stores the onward city
       Nepalgunj. Booking still uses routeId, so this is display-only. */
    $label = static fn(string $c): string => strcasecmp($c, 'Nepalgunj') === 0 ? 'Rupaidiha' : $c;

    /* Build the board for a given travel date. Extracted into a closure so
       Task 1 can call it again for the NEXT available date when the searched
       pickup's departure has already left. $date is the parameter; everything
       else (matched routes, agent mode, the label + rupaidiha helpers) is
       captured unchanged. */
    $buildResults = static function (string $date) use ($routes, $agentMode, $label, $from, $to): array {
        $results = [];

        /* One result card per SCHEDULE ROW (Bus Calendar, 5 Sep 2026): the
           daily bus (slot 1, resolved by route + date exactly as before) and
           any extra buses the office added for the date (slot 2+). $buildOne
           renders one row; the loop after it drives it. */
        $buildOne = static function (array $route, array $schedule) use (&$results, $date, $agentMode, $label, $from, $to): void {
        $routeId  = (int) $route['id'];
        $slot     = max(1, (int) ($schedule['slot'] ?? 1));
        $ownTime  = $slot > 1 && !empty($schedule['dep_time_override']);   // an extra bus with its own departure

        // Admin "block this trip" gate — a schedule row flagged is_blocked
        // never surfaces to customers even though the underlying route is
        // still active. Admin screens (trip-dashboard, trips.php,
        // live-status) still see the row so the block is visible in-house.
        if ((int) ($schedule['is_blocked'] ?? 0) === 1) {
            return;
        }

        $availability = Seats::availability($routeId, $date, 'sharing', (int) $schedule['id']);

        $stops = Database::fetchAll(
            'SELECT stop_type, stop_name, landmark, stop_time, latitude, longitude, is_border, is_meal_halt
               FROM route_stops WHERE route_id = :r ORDER BY stop_type, sort_order',
            ['r' => $routeId]
        );

        $boarding = array_values(array_filter($stops, static fn(array $s): bool => $s['stop_type'] === 'boarding'));
        $drop     = array_values(array_filter($stops, static fn(array $s): bool => $s['stop_type'] === 'drop'));

        /* Today's run keeps selling only the pickups it has not reached yet.
           Offering a departed pickup would take money for a bus that is
           hours down the road. Booking re-checks this independently — the
           browser is never the authority. */
        // Anonymous: drop the pickups the run has already reached. Agent
        // late-booking: keep every pickup (the passenger already boarded
        // somewhere) so the agent can pick the correct one for the ticket.
        if (!$agentMode && !$ownTime) {
            $boarding = array_values(array_filter(
                $boarding,
                static fn(array $s): bool => Boarding::isOpen($routeId, $date, (string) $s['stop_name'])
            ));
        }

        /* Serialize each stop as the canonical single-string label the whole
           downstream pipeline understands: parseBP() in the browser, the
           checkout POST payload, boarding_stop in booking_legs, the ticket
           PDF renderer, WhatsApp/email/ICS templates. The format is:
               "Name · Landmark @ HH:MM [lat,lng]"
           with Landmark, @time and [coords] each optional. Before this fix
           search.php returned raw DB rows (associative objects) which the
           `<option>` fill treated as strings — String({stop_name:...}) →
           "[object Object]" — so the dropdown never held a real value, the
           select posted "" to /book.php, and the ticket PDF fell back to
           routes.from_city ("Surat") and routes.dep_time ("13:00") for a
           passenger who actually boarded Emli Bhupal at 19:00. (SHG-2026-
           00056 · 29 Aug 2026 · owner-reported.) */
        // An extra bus with its own departure shifts every printed pickup
        // time by the same delta (a 21:00 second bus reaches each stop 8h
        // after the 13:00 daily bus does).
        $deltaSec = 0;
        if ($ownTime && !empty($route['dep_time'])) {
            $a = strtotime('2000-01-01 ' . (string) $route['dep_time']);
            $b = strtotime('2000-01-01 ' . (string) $schedule['dep_time_override']);
            if ($a !== false && $b !== false) { $deltaSec = $b - $a; }
        }
        $encodeStop = static function (array $s) use ($deltaSec): string {
            $out = trim((string) ($s['stop_name'] ?? ''));
            $lm  = trim((string) ($s['landmark'] ?? ''));
            if ($lm !== '') {
                $out .= ' · ' . $lm;
            }
            $t = (string) ($s['stop_time'] ?? '');
            if ($t !== '') {
                if ($deltaSec !== 0) {
                    $ts = strtotime('2000-01-01 ' . $t);
                    if ($ts !== false) { $t = date('H:i:s', $ts + $deltaSec); }
                }
                $out .= ' @ ' . substr($t, 0, 5);
            }
            if ($s['latitude'] !== null && $s['longitude'] !== null
                && $s['latitude'] !== '' && $s['longitude'] !== '') {
                $out .= ' [' . (float) $s['latitude'] . ',' . (float) $s['longitude'] . ']';
            }
            return $out;
        };
        $boarding = array_map($encodeStop, $boarding);
        $drop     = array_map($encodeStop, $drop);

        /* Pick the boarding / drop the customer's SEARCHED town points at —
           the client will auto-select this option so a distracted "Continue"
           tap can't print a wrong pickup on the ticket. Match uses
           Boarding::townKey() (same normaliser the cut-off logic uses) so a
           search for "Ahmedabad" lands on "Emli Bhupal · @ 19:00" and a
           search for "Nana Chiloda" lands on "S Hari Parking, Nana Chiloda".
           -1 means "no explicit match" — the client keeps the browser
           default (first option), same as before. */
        $indexMatching = static function (array $labels, string $wanted): int {
            $target = Boarding::townKey($wanted);
            if ($target === '') {
                return -1;
            }
            foreach ($labels as $i => $label) {
                if (Boarding::townKey((string) $label) === $target) {
                    return $i;
                }
            }
            return -1;
        };
        $boardingIdx = $indexMatching($boarding, $from);
        $dropIdx     = $indexMatching($drop,     $to);

        /* Whether the RUN is over is a separate question from whether this
           list is empty. Most routes here carry no route_stops rows at all,
           so an "empty means departed" test hid 7 of 13 buses on every date.
           routeSellable() falls back to the route's own dep_time when a route
           has no stop rows, and only reports false once the run is genuinely
           past. */
        if ($ownTime) {
            // An extra bus sells until ITS OWN departure (the route's stop
            // cut-offs describe the daily bus).
            $extraDep = strtotime($date . ' ' . (string) $schedule['dep_time_override']);
            $sellable = Auth::isSellingStaff() || ($extraDep !== false && $extraDep > time());
        } else {
            // Staff any-date (6 Sep 2026): the run being over does not hide it
            // from the counter — a late or back-dated ticket is exactly what
            // they are here to record. Cancelled / OFF departures are still
            // filtered out above, and create() refuses them again.
            $sellable = Auth::isSellingStaff()
                || ($agentMode
                    ? Boarding::routeSellableForAgent($routeId, $date)
                    : Boarding::routeSellable($routeId, $date));
        }
        if (!$sellable) {
            return;
        }

        // An extra bus may run a different coach than the route default.
        $busRow = null;
        if ($slot > 1 && (int) ($schedule['bus_id'] ?? 0) > 0) {
            $busRow = Database::fetch('SELECT bus_name, bus_number FROM buses WHERE id = :b', ['b' => (int) $schedule['bus_id']]);
        }

        /* Unified TripStatus so the customer results carry the same
           idea of "state" the admin does — but only the safe subset:
           we never leak internal timestamps or post-departure labels
           to the public API. When the derived state falls outside the
           allowed set, expose null and let the client draw a plain
           availability chip. */
        $totalSeats = (int) $availability['total'];
        $availCount = (int) $availability['availableCount'];
        $seatsBooked = max(0, $totalSeats - $availCount);

        // Effective scheduled dep_time: a per-schedule dep_time_override
        // (Phase 5+ delay-workflow reschedule) wins over the printed
        // route timetable. delay_minutes is layered separately by
        // TripStatus::compute() — it stays a distinct delta and is not
        // folded into dep_time here.
        $effDepTime = $schedule['dep_time_override'] ?? ($route['dep_time'] ?? null);

        $stInfo = TripStatus::compute([
            'id'            => (int) $schedule['id'],
            'travel_date'   => $date,
            'dep_time'      => $effDepTime,
            'arr_time'      => $route['arr_time'] ?? null,
            'day_offset'    => (int) ($route['day_offset'] ?? 1),
            'status'        => $schedule['status'] ?? 'scheduled',
            'delay_minutes' => (int) ($schedule['delay_minutes'] ?? 0),
            'delay_note'    => $schedule['delay_note'] ?? null,
            'total_seats'   => $totalSeats,
            'seats_booked'  => $seatsBooked,
        ]);
        $safeStates = [
            TripStatus::STATE_UPCOMING, TripStatus::STATE_DEPARTING,
            TripStatus::STATE_BOARDING, TripStatus::STATE_SOLD_OUT,
            TripStatus::STATE_DELAYED,  TripStatus::STATE_CANCELLED,
        ];
        $publicState = in_array($stInfo['state'], $safeStates, true) ? $stInfo['state'] : null;

        // Belt-and-braces: a sold-out trip must never advertise open
        // seats even if a race between availability() and compute()
        // widened the gap by a berth.
        $seatsLeft = $publicState === TripStatus::STATE_SOLD_OUT ? 0 : $availCount;

        $results[] = [
            'routeId'      => $routeId,
            'routeCode'    => $route['route_code'],
            'scheduleId'   => (int) $schedule['id'],
            // 1 = the daily bus; 2+ = an extra bus the office added for the
            // date (its own card, its own seats). Clients that don't know the
            // field keep showing the daily bus only.
            'slot'         => $slot,
            'extraBus'     => $slot > 1,
            'from'         => $label((string) $route['from_city']),
            'to'           => $label((string) $route['to_city']),
            'busName'      => $busRow['bus_name'] ?? ($route['bus'] ?? $route['bus_name'] ?? ''),
            'busNumber'    => $busRow['bus_number'] ?? ($route['bus_number'] ?? ''),
            // This departure's own coach (4 Sep 2026) — an extra bus may run a
            // different layout; identical to the route's type for the daily bus.
            'coachType'    => Seats::effectiveCoach($schedule, $route),
            // Customer-facing depTime uses the effective scheduled time
            // (override wins over the timetable). delay_minutes stays a
            // separate field consumed by the state / delay pill.
            'depTime'      => substr((string) $effDepTime, 0, 5),
            // duration_text '' = arrival deliberately blanked (see the
            // one-daily-bus migration) — show no clock rather than a wrong one.
            'arrTime'      => (string) ($route['duration_text'] ?? '') === '' ? '' : substr((string) $route['arr_time'], 0, 5),
            'duration'     => $route['duration_text'],
            'dayOffset'    => (string) ($route['duration_text'] ?? '') === '' ? 0 : (int) $route['day_offset'],
            'fare'         => (float) $route['base_fare'],
            // Per-departure price (4 Sep 2026): when the office gave THIS bus
            // its own fare on the Bus Calendar, that is the per-seat price the
            // server will charge, and the board must show the same number.
            // 0 = the normal route / direction fare, which is every daily bus.
            'fareOverride' => (float) (BookingService::scheduleFareOverride($schedule) ?? 0),
            'amenities'    => jsonColumn($route['amenities']),
            'crewName'     => $route['crew_name'],
            'crewPhone'    => $route['crew_phone'],
            'seatsLeft'    => $seatsLeft,
            'totalSeats'   => $totalSeats,
            'boarding'     => $boarding,
            'drop'         => $drop,
            'boardingIdx'  => $boardingIdx,
            'dropIdx'      => $dropIdx,
            'status'       => $schedule['status'],
            'delayMinutes' => (int) $schedule['delay_minutes'],
            'state'        => $publicState,
            // The travel date these results are for — usually the searched date,
            // but the NEXT available date when Task 1 rolls a spent departure
            // forward. The client adopts it as Flow.date when the customer picks
            // this bus, so seats + checkout + ticket all use the right date.
            'date'         => $date,
        ];
        };   // end $buildOne

        foreach ($routes as $route) {
            $routeId = (int) $route['id'];
            $buildOne($route, Seats::schedule($routeId, $date));
            // Extra buses the office added for this date (Bus Calendar, 5 Sep 2026).
            $extras = Database::fetchAll(
                "SELECT * FROM schedules WHERE route_id = :r AND travel_date = :d AND slot > 1 AND status = 'scheduled' ORDER BY slot",
                ['r' => $routeId, 'd' => $date]
            );
            foreach ($extras as $extra) {
                $buildOne($route, $extra);
            }
        }
        return $results;
    };

    // Board for the date the customer actually searched.
    $results = $buildResults($date);

    /* SURAT 24×7 (Task 1). If an anonymous customer searched a pickup whose
       departure has ALREADY left for the requested date, don't hand them an
       empty board — roll forward to the next date this daily service runs and
       flag it as the "next available departure" (next day, same 1 PM slot). No
       booking gate is weakened: the effective travel date is a future one whose
       cut-off passes naturally. The agent "book a departed bus with a code" path
       is untouched — it keeps every pickup and never rolls forward. */
    $nextAvailable = false;
    $effectiveDate = $date;
    if (!$agentMode) {
        $fromOpenToday = false;
        foreach ($routes as $route) {
            if (Boarding::isOpen((int) $route['id'], $date, $from)) { $fromOpenToday = true; break; }
        }
        if (!$fromOpenToday) {
            $probe = $date;
            for ($i = 0; $i < 35; $i++) {
                $probe = addDaysISO($probe, 1);
                if ($probe > $window['to']) { break; }
                $anyOpen = false;
                foreach ($routes as $route) {
                    if (Boarding::routeSellable((int) $route['id'], $probe)
                        && Boarding::isOpen((int) $route['id'], $probe, $from)) { $anyOpen = true; break; }
                }
                if ($anyOpen) {
                    $rolled = $buildResults($probe);
                    if ($rolled !== []) {
                        $results       = $rolled;
                        $nextAvailable = true;
                        $effectiveDate = $probe;
                    }
                    break;
                }
            }
        }
    }

    Response::success([
        'date'              => $effectiveDate,
        'requestedDate'     => $date,
        'nextAvailable'     => $nextAvailable,
        'nextAvailableStop' => $nextAvailable ? $from : '',
        'from'              => $from,
        'to'                => $to,
        'count'             => count($results),
        'results'           => $results,
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
