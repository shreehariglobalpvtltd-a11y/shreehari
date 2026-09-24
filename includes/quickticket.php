<?php
/**
 * =====================================================================
 *  QuickTicket — name + mobile in, confirmed ticket out (6 Sep 2026).
 *
 *  Owner ask: "only name and phone number, then in 30 seconds the ticket
 *  is automatic — analyse everything, make the PNG, send it on WhatsApp".
 *
 *  This class is the ANALYSE step. It decides, from the server's own data
 *  and clock, everything a checkout normally asks the customer:
 *
 *    • which bus      — the next catchable departure (today while a pickup
 *                        is still ahead, else the next running date), in
 *                        the desk's direction, or the soonest of the two
 *    • which pickup   — the desk's own town when the run calls there
 *                        (the value the desk sends, else the
 *                        quick_ticket_default_boarding setting), else the
 *                        first pickup still ahead
 *    • which seat     — the best free berth: lower deck first, women-only
 *                        berths kept for women, a shared cabin never mixed
 *                        against the gender rule, a party kept together
 *    • what it costs  — the same direction fare / per-bus override and
 *                        Fare::quote() the checkout charges
 *
 *  The SELL step is deliberately thin: it hands that plan to
 *  BookingService::create() with a $seller context — the ONE booking path
 *  every role shares — so double-booking protection, the gender lock,
 *  ticket issue, wallet accrual, the audit row and the WhatsApp / e-mail
 *  fan-out (EventBus 'booking.approved' → Notify::bookingConfirmed) are
 *  byte-for-byte what a counter sale gets. Nothing here writes a booking
 *  row itself. After the sale the PNG is rendered right away so the desk
 *  sees the ticket, and the WhatsApp outcome is read back from Notify's
 *  per-request journal so the screen can say "sent", or hand the clerk a
 *  one-tap click-to-chat link while the WhatsApp sender's KYC is pending.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/fare.php';
require_once __DIR__ . '/seats.php';
require_once __DIR__ . '/boarding.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/ticket.php';
require_once __DIR__ . '/agentwallet.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/notify.php';

/**
 * The fresh plan no longer matches what the passenger's card showed. Carries
 * the fresh plan so the app can re-render it and ask for one more tap.
 */
final class QuickTicketPlanChanged extends RuntimeException
{
    /** @var array<string, mixed> */
    public array $plan;

    /** @param array<string, mixed> $plan */
    public function __construct(string $message, array $plan)
    {
        parent::__construct($message);
        $this->plan = $plan;
    }
}

final class QuickTicket
{
    /** Stamped into the payment note so the register can tell these sales apart. */
    public const NOTE = 'Quick Ticket';

    /** Stamped into the cancel reason by customerUndo(). */
    public const UNDO_NOTE = 'Undo (Quick Ticket)';

    /** How many days ahead the automatic date looks for a running departure. */
    private const LOOKAHEAD_DAYS = 14;

    /** Nepal-side town keys — a route ending at one of these is the outbound (toNepal) run. */
    private const NEPAL_KEYS = ['rupaidiha', 'nepalgunj', 'nepalganj', 'kohalpur', 'jamunaha', 'kathmandu', 'nepal'];

    /* =================================================================
     *  Routes
     * ================================================================= */

    /**
     * Active routes on an active (or not yet assigned) bus, each tagged with
     * its direction. Same NULL-safe bus guard as api/search.php: only an
     * explicitly deactivated bus hides its route.
     *
     * @return list<array<string, mixed>>
     */
    public static function routes(): array
    {
        $rows = Database::fetchAll(
            'SELECT r.*, b.bus_name AS bus, b.bus_number
               FROM routes r
          LEFT JOIN buses b ON b.id = r.bus_id
              WHERE r.is_active = 1
                AND (b.id IS NULL OR b.is_active = 1)
           ORDER BY r.sort_order, r.dep_time, r.id'
        );
        foreach ($rows as &$r) {
            $r['direction'] = self::directionOf($r);
        }
        unset($r);

        return $rows;
    }

    /** 'toNepal' (Gujarat → Rupaidiha) or 'toIndia' (the return run). */
    public static function directionOf(array $route): string
    {
        $to = (string) ($route['to_city'] ?? '');
        if (Fare::isNepalPoint($to)) {
            return 'toNepal';
        }
        $key = Boarding::townKey($to);
        foreach (self::NEPAL_KEYS as $k) {
            if ($key !== '' && str_contains($key, $k)) {
                return 'toNepal';
            }
        }

        return 'toIndia';
    }

    public static function directionLabel(string $direction): string
    {
        return $direction === 'toNepal'
            ? 'Going to Nepal · Gujarat → Rupaidiha'
            : 'Returning · Rupaidiha → Gujarat';
    }

    /* =================================================================
     *  Analyse
     * ================================================================= */

    /**
     * The departure, pickup, seats and fare a name + mobile ticket would be
     * sold on right now. Read-only apart from the schedule row that
     * Seats::schedule() materialises (exactly what a search does).
     *
     * @param array<string, mixed> $opts
     *   direction  'toNepal' | 'toIndia' | '' (auto)
     *   date       'Y-m-d' | '' (auto: today while catchable, else the next running day)
     *   boarding   a town / stop label the desk prefers, '' = setting, then first pickup ahead
     *   seats      how many berths (1 … counter_max_seats_per_booking)
     *   gender     'Male' | 'Female' | 'Other' | null
     *   exclude    seat ids to skip (a retry after a lost race)
     * @return array<string, mixed>
     * @throws RuntimeException with a desk-safe message when nothing can be sold
     */
    public static function plan(array $opts = []): array
    {
        if (!Settings::getBool('daily_service_on', true)) {
            $note = trim(Settings::getString('daily_service_note', ''));
            throw new RuntimeException(
                'Booking is paused — the daily service is switched off. / बुकिङ अहिले बन्द छ — दैनिक सेवा रोकिएको छ।'
                . ($note !== '' ? ' ' . $note : '')
            );
        }

        /* Customer mode (6 Sep 2026, "Quick Ticket for everyone"): the same
           analyse step a passenger runs from the app. It keeps the public
           caps the checkout has — max_seats_per_booking, today-onwards,
           the rolling horizon, never a departed run — so the plan can only
           ever propose what BookingService::create() would accept from a
           customer. Staff keep the counter caps and any-date rules. */
        $customer  = !empty($opts['customer']);
        $direction = in_array($opts['direction'] ?? '', ['toNepal', 'toIndia'], true) ? (string) $opts['direction'] : '';
        $wantDate  = Security::isValidDate((string) ($opts['date'] ?? '')) ? (string) $opts['date'] : '';
        $wantStop  = trim((string) ($opts['boarding'] ?? ''));
        if ($wantStop === '' && $customer) {
            /* One-click Quick Ticket (owner ask, 6 Sep 2026): a passenger's
               card comes up pre-filled with the company's own yard — S Hari
               Parking, Nana Chiloda, where 36 of 70 verified customer tickets
               board. A SEPARATE setting from the desks' default, so a Surat
               or Mehsana counter keeps its "first pickup ahead" behaviour. */
            $wantStop = trim(Settings::getString('quick_ticket_customer_boarding', 'S Hari Parking'));
        }
        if ($wantStop === '') {
            $wantStop = trim(Settings::getString('quick_ticket_default_boarding', ''));
        }
        $maxSeats = BookingService::maxSeatsFor(!$customer);   // one definition
        $count    = max(1, min((int) ($opts['seats'] ?? 1), $maxSeats));
        if ($customer && $wantDate !== '') {
            $window = bookingWindow();
            if ($wantDate < todayISO()) {
                throw new RuntimeException('Choose today or a later date. / आज वा पछिको मिति छान्नुहोस्।');
            }
            if ($wantDate > $window['to']) {
                throw new RuntimeException('Booking is open up to ' . formatDate($window['to']) . ' for now. / अहिले ' . formatDate($window['to']) . ' सम्म मात्र बुकिङ खुला छ।');
            }
        }
        $gender   = in_array($opts['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? (string) $opts['gender'] : null;
        $exclude  = array_values(array_unique(array_map(
            static fn($s): string => strtoupper(trim((string) $s)),
            (array) ($opts['exclude'] ?? [])
        )));
        // Berths to try FIRST when free — "same as last time" (SHG AI BRAIN Phase 2,
        // 10 Sep 2026). Every seat rule still applies; an unavailable preference is skipped.
        $prefer   = array_values(array_unique(array_map(
            static fn($s): string => strtoupper(trim((string) $s)),
            (array) ($opts['prefer'] ?? [])
        )));

        $routes = self::routes();
        if ($routes === []) {
            throw new RuntimeException('No active bus to sell on. / बिक्रीका लागि कुनै बस सक्रिय छैन।');
        }

        // Every candidate route's next departure, seats included.
        $options = [];
        $why     = [];
        foreach ($routes as $route) {
            if ($direction !== '' && $route['direction'] !== $direction) {
                continue;
            }
            try {
                $opt = self::nextDeparture($route, $wantDate, $wantStop, $count, $gender, $exclude, $customer, $prefer);
            } catch (RuntimeException $e) {
                $why[] = $e->getMessage();
                continue;
            }
            if ($opt !== null) {
                $options[] = $opt;
            }
        }
        if ($options === []) {
            throw new RuntimeException($why !== []
                ? $why[0]
                : 'No departure can be sold right now. / अहिले कुनै बस बिक्रीमा छैन।');
        }

        /* Which one: the desk's own pickup wins (a Rupaidiha desk sells the
           return bus, a Mehsana desk the outbound one), then the soonest
           boarding time. With an explicit direction there is one route. */
        usort($options, static function (array $a, array $b): int {
            if ($a['matchedDesk'] !== $b['matchedDesk']) {
                return $a['matchedDesk'] ? -1 : 1;
            }
            return ($a['boardingTs'] ?? PHP_INT_MAX) <=> ($b['boardingTs'] ?? PHP_INT_MAX);
        });
        $pick = $options[0];

        $pick['alternatives'] = [];
        foreach (array_slice($options, 1) as $alt) {
            $pick['alternatives'][] = [
                'direction'    => $alt['direction'],
                'label'        => self::directionLabel($alt['direction']),
                'route'        => $alt['from'] . ' → ' . $alt['to'],
                'date'         => $alt['date'],
                'dateLabel'    => $alt['dateLabel'],
                'depTime'      => $alt['depTime'],
                'boardingName' => $alt['boardingName'],
                'boardingTime' => $alt['boardingTime'],
                'seatsLeft'    => $alt['seatsLeft'],
            ];
        }
        // Directions the desk can switch to — both, when both runs are active.
        $pick['directions'] = array_values(array_unique(array_map(
            static fn(array $r): string => (string) $r['direction'],
            $routes
        )));
        // The dates a passenger may pick (the app's date control): today up to
        // the rolling horizon. Staff are not bound by it (any-date rule).
        $window          = bookingWindow();
        $pick['today']   = todayISO();
        $pick['window']  = ['from' => max((string) $window['from'], todayISO()), 'to' => (string) $window['to']];
        $pick['customer'] = $customer;

        return $pick;
    }

    /**
     * ONE route's next sellable departure, with the pickup and seats chosen.
     * Null when nothing inside the lookahead runs; throws only for an
     * explicit date that cannot be sold (so the desk gets the reason).
     */
    private static function nextDeparture(array $route, string $wantDate, string $wantStop, int $count, ?string $gender, array $exclude, bool $customer = false, array $prefer = []): ?array
    {
        $routeId  = (int) $route['id'];
        $today    = todayISO();
        $now      = time();
        $stops    = Boarding::stopsFor($routeId);
        $openFrom = Settings::getString('booking_open_from', '2026-09-02');

        if ($wantDate !== '') {
            $dates = [$wantDate];
        } else {
            $dates = [];
            for ($i = 0; $i <= self::LOOKAHEAD_DAYS; $i++) {
                $dates[] = addDaysISO($today, $i);
            }
        }

        foreach ($dates as $date) {
            // Before the inaugural departure is not a booking, it is fiction — create() refuses it too.
            if (Security::isValidDate($openFrom) && $date < $openFrom) {
                if ($wantDate !== '') {
                    throw new RuntimeException('No service ran before ' . formatDate($openFrom) . ' — the inaugural departure.');
                }
                continue;
            }
            try {
                $schedule = Seats::schedule($routeId, $date);
            } catch (Throwable $e) {
                continue;
            }
            // Per-date OFF / cancelled: never offered (the checkout refuses them anyway).
            if ((int) ($schedule['is_blocked'] ?? 0) === 1 || (string) ($schedule['status'] ?? '') === 'cancelled') {
                if ($wantDate !== '') {
                    throw new RuntimeException('This bus is not running on ' . formatDate($date) . ' (cancelled / OFF). / ' . formatDate($date) . ' मा यो बस चल्दैन।');
                }
                continue;
            }
            $depTime = !empty($schedule['dep_time_override'])
                ? (string) $schedule['dep_time_override']
                : (string) ($route['dep_time'] ?? '');

            /* Pickups still ahead of the clock. Today that is a real filter; a
               future date keeps every stop. An EXPLICIT date keeps every stop
               as well — staff record a passenger who already boarded, and
               create() lifts the cut-off for selling staff. */
            $list = [];
            foreach ($stops as $s) {
                $t  = ($s['time'] !== null && $s['time'] !== '') ? (string) $s['time'] : $depTime;
                $ts = $t !== '' ? strtotime($date . ' ' . $t) : false;
                $list[] = ['name' => (string) $s['name'], 'time' => $t, 'ts' => $ts === false ? null : $ts];
            }
            if ($list === [] && $depTime !== '') {
                // A route with no stop rows: its own origin + departure time.
                $ts     = strtotime($date . ' ' . $depTime);
                $list[] = ['name' => (string) ($route['from_city'] ?? ''), 'time' => $depTime, 'ts' => $ts === false ? null : $ts];
            }
            /* Plan / sale parity (one-click, 6 Sep 2026): a passenger's card
               must never offer a pickup BookingService::create() then refuses
               — create() closes each stop boarding_cutoff_minutes before its
               time (Boarding::status), so the customer plan closes it at the
               same minute. Staff keep the plain "still ahead" rule (their
               any-date path lifts the cut-off anyway). */
            $closeBy  = $customer ? Boarding::cutoffMinutes() * 60 : 0;
            $ahead    = array_values(array_filter($list, static fn(array $s): bool => $s['ts'] === null || ($s['ts'] - $closeBy) > $now));
            $departed = false;
            if ($ahead === []) {
                if ($wantDate === '') {
                    continue;          // the whole run is past — look at the next day
                }
                if ($customer) {
                    // A passenger cannot book a bus that has already left (create() refuses it too).
                    throw new RuntimeException('The bus for ' . formatDate($date) . ' has already left — choose a later date. / ' . formatDate($date) . ' को बस गइसक्यो — पछिको मिति छान्नुहोस्।');
                }
                $ahead    = $list;     // explicit date: the desk knows what it is recording
                $departed = true;
            }
            if ($ahead === []) {
                continue;
            }

            // The pickup: the desk's own town when the run calls there, else the first still ahead.
            $chosen = null;
            if ($wantStop !== '') {
                foreach ($ahead as $s) {
                    if (self::stopMatches($s['name'], $wantStop)) {
                        $chosen = $s;
                        break;
                    }
                }
            }
            $matchedDesk = $chosen !== null;
            if ($chosen === null) {
                $chosen = $ahead[0];
            }

            // Seats: the daily bus first, then any extra bus the office added for the date.
            $seatPick       = self::pickSeats($route, $schedule, $count, $gender, $exclude, $prefer);
            $chosenSchedule = $schedule;
            if ($seatPick['seats'] === []) {
                $extras = Database::fetchAll(
                    "SELECT * FROM schedules WHERE route_id = :r AND travel_date = :d AND slot > 1 AND status = 'scheduled' ORDER BY slot",
                    ['r' => $routeId, 'd' => $date]
                );
                foreach ($extras as $extra) {
                    if ((int) ($extra['is_blocked'] ?? 0) === 1) {
                        continue;
                    }
                    $try = self::pickSeats($route, $extra, $count, $gender, $exclude, $prefer);
                    if ($try['seats'] !== []) {
                        $seatPick       = $try;
                        $chosenSchedule = $extra;
                        break;
                    }
                }
            }
            if ($seatPick['seats'] === []) {
                $full = 'Bus ' . $route['from_city'] . ' → ' . $route['to_city'] . ' on ' . formatDate($date)
                      . ' has no free seat for ' . $count . ' passenger(s). / ' . formatDate($date) . ' को बस भरिएको छ।';
                if ($wantDate !== '') {
                    throw new RuntimeException($full);
                }
                continue;              // full — the next running date
            }

            $slot = max(1, (int) ($chosenSchedule['slot'] ?? 1));
            if ($slot > 1 && !empty($chosenSchedule['dep_time_override'])) {
                $depTime = (string) $chosenSchedule['dep_time_override'];
            }
            // The canonical "Name @ HH:MM" label create() checks and prints.
            $boardLabel = trim($chosen['name']) . ($chosen['time'] !== '' ? ' @ ' . substr($chosen['time'], 0, 5) : '');
            $display    = Boarding::stopDisplay($boardLabel, (string) ($route['from_city'] ?? ''));
            $fare       = self::fareFor($route, $chosenSchedule, $seatPick['bookingMode'], count($seatPick['seats']),
                                        normalisePhone((string) ($opts['phone'] ?? '')));

            $aheadNames = array_map(static fn(array $s): string => $s['name'], $ahead);

            return [
                'routeId'        => $routeId,
                'routeCode'      => (string) ($route['route_code'] ?? ''),
                'scheduleId'     => (int) $chosenSchedule['id'],
                'slot'           => $slot,
                'extraBus'       => $slot > 1,
                'direction'      => (string) $route['direction'],
                'directionLabel' => self::directionLabel((string) $route['direction']),
                'from'           => (string) $route['from_city'],
                'to'             => (string) $route['to_city'],
                'busName'        => (string) ($route['bus'] ?? ''),
                'busNumber'      => (string) ($route['bus_number'] ?? ''),
                'coach'          => $seatPick['coach'],
                'bookingMode'    => $seatPick['bookingMode'],
                'date'           => $date,
                'dateLabel'      => formatDate($date),
                'isToday'        => $date === $today,
                'departed'       => $departed,
                'depTime'        => substr($depTime, 0, 5),
                'boarding'       => $boardLabel,
                'boardingName'   => (string) $display['name'],
                'boardingCode'   => (string) $display['code'],
                'boardingTime'   => $chosen['time'] !== '' ? substr($chosen['time'], 0, 5) : '',
                'boardingTs'     => $chosen['ts'],
                'departsInMin'   => $chosen['ts'] !== null ? (int) floor(($chosen['ts'] - $now) / 60) : null,
                'matchedDesk'    => $matchedDesk,
                'seats'          => $seatPick['seats'],
                'seatCount'      => count($seatPick['seats']),
                'seatsLeft'      => $seatPick['seatsLeft'],
                'fare'           => $fare,
                'stops'          => array_map(static function (array $s) use ($aheadNames): array {
                    $d = Boarding::stopDisplay($s['name'] . ($s['time'] !== '' ? ' @ ' . substr($s['time'], 0, 5) : ''));
                    return [
                        'name'  => $s['name'],
                        'short' => (string) $d['name'],
                        'code'  => (string) $d['code'],
                        'time'  => $s['time'] !== '' ? substr($s['time'], 0, 5) : '',
                        'ahead' => in_array($s['name'], $aheadNames, true),
                    ];
                }, $list),
            ];
        }

        return null;
    }

    /**
     * Does a stop row name the town the desk asked for? Town-key equality,
     * either key containing the other ("Mehsana" vs "Mehsana Head Office"),
     * or the same short ticket code (MSN / AMD / STV …).
     */
    private static function stopMatches(string $stopName, string $wanted): bool
    {
        $a = Boarding::townKey($stopName);
        $b = Boarding::townKey($wanted);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }
        $da = Boarding::stopDisplay($stopName);
        $db = Boarding::stopDisplay($wanted);

        return $da['code'] !== 'SHG' && $da['code'] === $db['code'];
    }

    /**
     * The best free seats on one schedule row, or none.
     *
     * @return array{seats: list<string>, seatsLeft: int, coach: string, bookingMode: ?string}
     */
    private static function pickSeats(array $route, array $schedule, int $count, ?string $gender, array $exclude, array $prefer = []): array
    {
        $coach = Seats::effectiveCoach($schedule, $route);
        $mode  = $coach === 'sleeper' ? 'sharing' : null;    // a sleeper sells as sharing; a seater has no mode
        $bt    = $coach === 'sleeper' ? 'sharing' : 'seater';
        $av    = Seats::availabilityForSchedule((int) $schedule['id'], $bt);
        $none  = ['seats' => [], 'seatsLeft' => 0, 'coach' => $coach, 'bookingMode' => $mode];

        $free = array_values(array_diff(array_map('strval', $av['available'] ?? []), $exclude));
        $left = count($free);
        if ($free === []) {
            return $none;
        }
        $none['seatsLeft'] = $left;

        // Women-only berths stay for women. A customer is refused on them; the
        // desk COULD override by hand, but an automatic pick must never.
        $female = array_map('strval', $av['female'] ?? []);
        if ($gender !== 'Female' && $female !== []) {
            $free = array_values(array_diff($free, $female));
        }
        // A shared cabin already holding one gender takes only that gender.
        $units = is_array($av['units'] ?? null) ? $av['units'] : [];
        $free  = array_values(array_filter($free, static function (string $seat) use ($units, $gender): bool {
            $u = $units[Seats::unitKey($seat)] ?? null;
            if ($u === null) {
                return true;
            }
            $lock = (string) ($u['lock'] ?? 'none');
            return $lock === 'none'
                || ($lock === 'female_only' && $gender === 'Female')
                || ($lock === 'male_only' && $gender === 'Male');
        }));
        if ($free === []) {
            return $none;
        }

        /* The passenger's usual berths first ("same as last time") — only those
           still free AND allowed by the gender / cabin rules above, which have
           already removed everything else from $free. Never more than asked. */
        $chosenPref = $prefer !== [] ? array_slice(array_values(array_intersect($prefer, $free)), 0, $count) : [];
        // Preference: lower deck before upper, then the seat number.
        usort($free, static fn(string $a, string $b): int => self::seatRank($a) <=> self::seatRank($b));

        // Free seats grouped by cabin, in that same order.
        $byUnit = [];
        foreach ($free as $seat) {
            $byUnit[Seats::unitKey($seat)][] = $seat;
        }
        $unitSize = static function (string $unit) use ($coach): int {
            $n = count(Seats::unitSeats($unit, $coach));
            return $n > 0 ? $n : 1;
        };

        // Cabins that already hold somebody (booked, or held at a checkout).
        // The gender filter above has already kept only the compatible ones.
        $taken   = array_flip(array_map('strval', array_merge($av['booked'] ?? [], $av['locked'] ?? [])));
        $partial = static function (string $unit) use ($taken, $coach): bool {
            foreach (Seats::unitSeats($unit, $coach) as $s) {
                if (isset($taken[$s])) {
                    return true;
                }
            }
            return false;
        };

        $chosen = [];
        $take   = static function (array $seats) use (&$chosen, $count): void {
            foreach ($seats as $s) {
                if (count($chosen) < $count && !in_array($s, $chosen, true)) {
                    $chosen[] = $s;
                }
            }
        };

        if ($chosenPref !== []) {
            $take($chosenPref);
            // A party keeps together: fill the preferred berths' OWN cabins (free mates
            // only) before the generic walk would seat the rest with a stranger.
            foreach ($chosenPref as $ps) {
                if (count($chosen) >= $count) {
                    break;
                }
                $take(array_values(array_intersect(Seats::unitSeats(Seats::unitKey($ps, $coach, 'sharing'), $coach), $free)));
            }
        }
        if ($count === 1) {
            // One passenger: complete a cabin that already has a traveller
            // (keeps whole cabins free for pairs), then the first EMPTY cabin,
            // then whatever berth is nearest the front.
            foreach ($byUnit as $unit => $seats) {
                if ($partial((string) $unit)) {
                    $take([$seats[0]]);
                    break;
                }
            }
            if ($chosen === []) {
                foreach ($byUnit as $unit => $seats) {
                    if (count($seats) === $unitSize((string) $unit)) {
                        $take([$seats[0]]);
                        break;
                    }
                }
            }
            if ($chosen === []) {
                $take([$free[0]]);
            }
        } else {
            // A party: whole empty cabins first so they travel together, then
            // whatever single berths remain, nearest the front.
            foreach ($byUnit as $unit => $seats) {
                if (count($chosen) >= $count) {
                    break;
                }
                $size = $unitSize((string) $unit);
                if (count($seats) === $size && $size <= $count - count($chosen)) {
                    $take($seats);
                }
            }
            foreach ($byUnit as $seats) {
                if (count($chosen) >= $count) {
                    break;
                }
                $take($seats);
            }
        }

        if (count($chosen) < $count) {
            return $none;
        }

        return ['seats' => $chosen, 'seatsLeft' => $left, 'coach' => $coach, 'bookingMode' => $mode];
    }

    /** Sort key: L1 … L36, then U1 … U36; seater 1A … 10D; anything else last. */
    private static function seatRank(string $seat): int
    {
        $seat = strtoupper($seat);
        if (preg_match('/^([LU])(\d+)$/', $seat, $m) === 1) {
            return ($m[1] === 'L' ? 0 : 1000) + (int) $m[2];
        }
        if (preg_match('/^(\d+)([A-D])$/', $seat, $m) === 1) {
            return 2000 + (int) $m[1] * 10 + (ord($m[2]) - 64);
        }

        return 9000;
    }

    /**
     * What the checkout will charge — the same inputs BookingService's
     * pricing uses: direction fare (sleeper sharing) or the route's seat
     * fare, a per-bus override, then Fare::quote() (group discount, tax, fee).
     *
     * 23 Sep 2026: the passenger's phone is passed through, exactly as
     * BookingService::priceBooking() passes it, so an office offer with a
     * per-passenger limit ("first booking ₹100 off") is priced the SAME in the
     * quote and in the sale. Without it the quote showed the discount to a
     * repeat passenger, the sale refused it, and the totals no longer matched.
     * The offer that applied is returned so the bot can SAY it.
     *
     * @return array{perSeat: float, base: float, total: float, groupDiscount: float, tax: float, fee: float, label: string,
     *               couponDiscount: float, offerCode: string, offerTitle: string}
     */
    private static function fareFor(array $route, array $schedule, ?string $mode, int $count, string $phone = ''): array
    {
        $perSeat = (float) ($route['base_fare'] ?? 0);
        $label   = 'Seat';
        if ($mode === 'sharing') {
            $cf = Fare::cabinFare('single', 'sharing', $count, true, (string) ($route['to_city'] ?? ''));
            if (($cf['perPerson'] ?? 0) > 0) {
                $perSeat = (float) $cf['perPerson'];
            }
            $label = (string) ($cf['label'] ?? 'Sharing sleeper');
        }
        $override = BookingService::scheduleFareOverride($schedule);
        if ($override !== null) {
            $perSeat = $override;
        }
        $base  = round($perSeat * $count, 2);
        $quote = Fare::quote($base, $count, 0, 0, 0, '', $phone, (int) $route['id']);

        // The office offer that applied, by its own title (breakdown label).
        $offerCut   = (float) ($quote['couponDiscount'] ?? 0);
        $offerTitle = '';
        if ($offerCut > 0) {
            foreach ((array) ($quote['breakdown'] ?? []) as $line) {
                if (abs((float) ($line['amount'] ?? 0) + $offerCut) < 0.01 && !str_starts_with((string) ($line['label'] ?? ''), 'Group')) {
                    $offerTitle = (string) $line['label'];
                    break;
                }
            }
        }

        return [
            'perSeat'        => $perSeat,
            'base'           => (float) $quote['base'],
            'total'          => (float) $quote['total'],
            'groupDiscount'  => (float) $quote['groupDiscount'],
            'tax'            => (float) $quote['tax'],
            'fee'            => (float) $quote['fee'],
            'label'          => $label,
            'couponDiscount' => $offerCut,
            'offerCode'      => (string) ($quote['couponCode'] ?? ''),
            'offerTitle'     => $offerTitle,
        ];
    }

    /* =================================================================
     *  Sell
     * ================================================================= */

    /**
     * Name + mobile (+ the desk's options) → a confirmed booking with its
     * ticket issued, the PNG rendered and the WhatsApp attempted.
     *
     * @param array<string, mixed> $input name, phone, country (IN|NP), gender, seats,
     *        direction, date, boarding, pay (cash|upi|esewa|bank), discountType,
     *        discountValue, note, passengers[{name,age,gender}] (optional, per seat)
     * @param array<string, mixed> $staff the signed-in admins row (Auth::admin())
     * @return array<string, mixed>
     * @throws RuntimeException on any refusal (desk-safe message)
     */
    public static function sell(array $input, array $staff): array
    {
        $t0 = microtime(true);

        $name = Security::clean((string) ($input['name'] ?? ''), 120);
        if (mb_strlen($name) < 2) {
            throw new RuntimeException("Enter the passenger's name. / यात्रीको नाम लेख्नुहोस्।");
        }
        $rawPhone  = trim((string) ($input['phone'] ?? ''));
        $digitsAll = preg_replace('/\D/', '', $rawPhone) ?? '';
        $phone     = normalisePhone($rawPhone);

        /* NAME-ONLY IS A REAL SALE AT THE DESK (owner ask, point 9).
           A walk-in with no phone still gets a ticket: the office prints it and
           hands it over, and BookingService::create() stores the walk-in
           placeholder for a seller. This screen is the primary staff sale
           screen, and it was the one place that refused — $staff was already
           being passed in and simply never consulted here, so agent, counter
           AND superadmin were all told to "enter a valid mobile number" for a
           passenger standing at the counter with cash.
           A number that IS given still has to be a real one, at the desk's
           wider 8-15 range. Customers keep the strict rule: their ticket has
           nowhere else to go. */
        $deskSale = $staff !== [];
        if ($phone === '') {
            if (!$deskSale) {
                throw new RuntimeException('Enter a valid mobile number — the WhatsApp ticket goes there. / सही मोबाइल नम्बर लेख्नुहोस् — टिकट त्यही WhatsApp मा जान्छ।');
            }
        } elseif (!Security::isValidPhone($phone, $deskSale)) {
            throw new RuntimeException('Enter a valid mobile number — the WhatsApp ticket goes there. / सही मोबाइल नम्बर लेख्नुहोस् — टिकट त्यही WhatsApp मा जान्छ।');
        }
        /* Nepali number? A +977 prefix, or the desk's own choice. India and
           Nepal both use 10-digit mobiles, so this hint is what puts +977 in
           front when the ticket is sent (Notify::countryHint reads id_type). */
        $country = strtoupper((string) ($input['country'] ?? ''));
        if ($country !== 'NP' && $country !== 'IN') {
            $country = (str_starts_with($digitsAll, '977') && strlen($digitsAll) === 13) ? 'NP' : 'IN';
        }
        $idType = $country === 'NP' ? 'Nepali Citizenship' : '';
        $gender = in_array($input['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? (string) $input['gender'] : null;

        $defaultPay = Settings::getString('quick_ticket_default_pay', 'cash');
        $pay = in_array($input['pay'] ?? '', ['cash', 'upi', 'esewa', 'bank'], true)
            ? (string) $input['pay']
            : (in_array($defaultPay, ['cash', 'upi', 'esewa', 'bank'], true) ? $defaultPay : 'cash');
        $note  = Security::clean((string) ($input['note'] ?? ''), 160);
        $given = is_array($input['passengers'] ?? null) ? array_values($input['passengers']) : [];

        $opts = [
            'direction' => (string) ($input['direction'] ?? ''),
            'date'      => (string) ($input['date'] ?? ''),
            'boarding'  => (string) ($input['boarding'] ?? ''),
            'seats'     => (int) ($input['seats'] ?? 1),
            'gender'    => $gender,
            'prefer'    => (array) ($input['prefer'] ?? []),
            'phone'     => $phone,      // priced like the sale: per-passenger offer limits
        ];
        $seller = [
            'adminId'       => (int) ($staff['id'] ?? 0),
            /* Read the SELLER ROW first, and only then the session (20 Sep 2026).
               The row is already in hand and is the truth about who is selling;
               the session was the only source until the WhatsApp assistant began
               calling this with a staff record and no session, which would have
               filed an agent's sale as a counter sale. Same answer as before for
               every screen that does have a session. */
            'source'        => (((string) ($staff['role'] ?? '')) === 'agent' || Auth::isCounterAgent()) ? 'agent' : 'counter',
            'paymentMethod' => $pay,
            'discountType'  => in_array($input['discountType'] ?? '', ['flat', 'percent'], true) ? (string) $input['discountType'] : '',
            'discountValue' => (float) ($input['discountValue'] ?? 0),
            'note'          => self::NOTE . ($note !== '' ? ' · ' . $note : ''),
        ];

        $plan    = [];
        $booking = [];
        $exclude = [];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $plan    = self::plan($opts + ['exclude' => $exclude]);
            $request = self::requestFor($plan, $name, $phone, $gender, $idType, $given, '', $country);
            try {
                $booking = BookingService::create($request, $seller);
                break;
            } catch (RuntimeException $e) {
                // Lost the berth between plan and claim (another desk, an
                // online checkout): pick again once, skipping those seats.
                // Anything else is a real refusal and goes to the desk as is.
                if ($attempt === 1 && self::isSeatClash($e->getMessage())) {
                    $exclude = $plan['seats'];
                    continue;
                }
                throw $e;
            }
        }
        if ($booking === [] || empty($booking['id'])) {
            throw new RuntimeException('The ticket could not be issued — please try again. / टिकट बन्न सकेन, फेरि प्रयास गर्नुहोस्।');
        }

        return self::result($booking, $plan, $name, $phone, $t0);
    }

    /**
     * The CUSTOMER's own Quick Ticket (6 Sep 2026, "for everyone"): name +
     * mobile from the app → the same plan → BookingService::create() with
     * NO seller — the identical path the app's checkout uses. With
     * pay-at-counter allowed (allow_cod, the way most passengers already
     * book) the ticket is CONFIRMED on the spot, issued, rendered as PNG and
     * PDF, and the WhatsApp fan-out fires; the fare is collected by the
     * crew before departure exactly like every other pay-at-counter ticket.
     * With allow_cod off the booking is created PENDING and the response
     * carries the UPI / eSewa payment targets so the app can walk the
     * passenger through payment on its normal ticket screen.
     *
     * Every customer rule still applies inside create(): the public seat
     * cap, today-onwards + horizon, the pickup cut-off, the duplicate-seat
     * guard, the women-only berths, the gender lock — nothing here lifts them.
     *
     * @param array<string, mixed>      $input name, phone, country (IN|NP), gender, seats, direction, date, boarding, passengers[]
     * @param array<string, mixed>|null $user  the signed-in customer session (Auth::user()) or null for a guest
     * @return array<string, mixed>
     * @throws RuntimeException with a passenger-safe bilingual message
     */
    public static function sellCustomer(array $input, ?array $user = null): array
    {
        $t0 = microtime(true);

        if (!Settings::getBool('quick_ticket_customer_on', true)) {
            throw new RuntimeException('Quick Ticket is available at our desks only right now — please book through the seat map. / Quick Ticket अहिले desk मा मात्र छ — कृपया सिट नक्साबाट बुक गर्नुहोस्।');
        }
        $name = Security::clean((string) ($input['name'] ?? ''), 120);
        if (mb_strlen($name) < 2) {
            throw new RuntimeException('Enter your name. / आफ्नो नाम लेख्नुहोस्।');
        }
        $rawPhone  = trim((string) ($input['phone'] ?? ''));
        $digitsAll = preg_replace('/\D/', '', $rawPhone) ?? '';
        $phone     = normalisePhone($rawPhone);
        /* A signed-in customer books against their own number (the api/book.php
           rule): the form cannot aim a ticket at somebody else's WhatsApp. */
        if ($user !== null && normalisePhone((string) ($user['phone'] ?? '')) !== '') {
            $phone = normalisePhone((string) $user['phone']);
        }
        if ($phone === '' || !Security::isValidPhone($phone)) {
            throw new RuntimeException('Enter a valid 10-digit mobile number — the ticket goes to that WhatsApp. / सही १० अंकको मोबाइल नम्बर लेख्नुहोस् — टिकट त्यही WhatsApp मा जान्छ।');
        }
        $country = strtoupper((string) ($input['country'] ?? ''));
        if ($country !== 'NP' && $country !== 'IN') {
            $country = (str_starts_with($digitsAll, '977') && strlen($digitsAll) === 13) ? 'NP' : 'IN';
        }
        $idType = $country === 'NP' ? 'Nepali Citizenship' : '';
        $gender = in_array($input['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? (string) $input['gender'] : null;
        $given  = is_array($input['passengers'] ?? null) ? array_values($input['passengers']) : [];
        $cod    = Settings::getBool('allow_cod', true);

        $opts = [
            'direction' => (string) ($input['direction'] ?? ''),
            'date'      => (string) ($input['date'] ?? ''),
            'boarding'  => (string) ($input['boarding'] ?? ''),
            'seats'     => (int) ($input['seats'] ?? 1),
            'gender'    => $gender,
            'prefer'    => (array) ($input['prefer'] ?? []),
            'customer'  => true,
            'phone'     => $phone,      // priced like the sale: per-passenger offer limits
        ];

        /* Optional agent code (QuickBot, 7 Sep 2026): "SHG-027" typed in the
           field or in the smart line. The booking engine resolves it exactly
           as the seat-map checkout does; an unknown or inactive code is
           simply a direct sale — it never blocks the passenger. */
        $agentCode = strtoupper(Security::clean((string) ($input['agentCode'] ?? ''), 20));

        $plan    = [];
        $booking = [];
        $exclude = [];
        $expect  = is_array($input['expect'] ?? null) ? $input['expect'] : null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $plan = self::plan($opts + ['exclude' => $exclude]);
            if ($attempt === 1) {
                self::assertCustomerNotRepeating($phone, (int) $plan['routeId'], (string) $plan['date']);
                if ($cod) {
                    self::assertCustomerOpenCap($phone);   // unpaid one-tap tickets per number
                }
                /* PINNED sale (one-click, 6 Sep 2026): the card showed a day, a
                   direction, a pickup, a party and a price. If the fresh plan
                   differs on any of them — the day rolled over, the pickup
                   closed, the fare changed — the tap must NOT quietly buy
                   something else. The app re-renders the fresh plan and asks
                   for one more tap. A retry after a seat clash keeps the pin
                   (same day / pickup / price, another berth). */
                if ($expect !== null) {
                    self::assertPlanAsShown($plan, $expect);
                }
            }
            $request = self::requestFor($plan, $name, $phone, $gender, $idType, $given, $agentCode, $country);
            // Pay-at-counter when the office allows it (confirmed now); else the normal UPI-pending path.
            $request['isCod']         = $cod;
            $request['paymentMethod'] = $cod ? 'cod' : 'upi';
            try {
                $booking = BookingService::create($request, null);
                break;
            } catch (RuntimeException $e) {
                if ($attempt === 1 && self::isSeatClash($e->getMessage())) {
                    $exclude = $plan['seats'];
                    continue;
                }
                throw $e;
            }
        }
        if ($booking === [] || empty($booking['id'])) {
            throw new RuntimeException('The ticket could not be issued — please try again. / टिकट बन्न सकेन, फेरि प्रयास गर्नुहोस्।');
        }

        $out = self::result($booking, $plan, $name, $phone, $t0, true);
        // Agent-code notice, exactly what api/book.php tells the checkout: a
        // display label only — never the agent's name, branch or admin id.
        $out['agent'] = null;
        if ($agentCode !== '') {
            $soldBy = (int) ($booking['sold_by_admin_id'] ?? 0);
            $out['agent'] = $soldBy > 0
                ? ['applied' => true,  'label' => AgentWallet::agentCodeLabel($soldBy) ?: 'Agent code applied']
                : ['applied' => false, 'label' => ''];
        }

        return $out;
    }

    /**
     * Does the fresh plan still say what the card said? Compared on the
     * facts the passenger actually read: date, direction, pickup CODE
     * (label wording may differ), party size and the total. The berth
     * number is deliberately not compared — another passenger may take L7
     * between render and tap and L8 is the same ticket.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $expect date, direction, boardingCode, seats, total
     * @throws QuickTicketPlanChanged
     */
    private static function assertPlanAsShown(array $plan, array $expect): void
    {
        $diff = [];
        if (isset($expect['date']) && (string) $expect['date'] !== (string) $plan['date']) {
            $diff[] = 'date';
        }
        if (isset($expect['direction']) && (string) $expect['direction'] !== '' && (string) $expect['direction'] !== (string) $plan['direction']) {
            $diff[] = 'direction';
        }
        if (isset($expect['boardingCode']) && (string) $expect['boardingCode'] !== '' && strtoupper((string) $expect['boardingCode']) !== strtoupper((string) $plan['boardingCode'])) {
            $diff[] = 'boarding';
        }
        if (isset($expect['seats']) && (int) $expect['seats'] > 0 && (int) $expect['seats'] !== (int) $plan['seatCount']) {
            $diff[] = 'seats';
        }
        if (isset($expect['total']) && abs((float) $expect['total'] - (float) $plan['fare']['total']) > 0.5) {
            $diff[] = 'total';
        }
        if ($diff !== []) {
            throw new QuickTicketPlanChanged(
                'The bus details changed since the card was shown (' . implode(', ', $diff) . ') — please check and tap again. / बसको विवरण बदलियो — फेरि हेरेर ट्याप गर्नुहोस्।',
                $plan
            );
        }
    }

    /**
     * Free undo (one-click safety, 6 Sep 2026): a passenger who tapped by
     * mistake may cancel their own pay-on-boarding Quick Ticket within
     * quick_ticket_undo_min minutes (default 10) at no charge — nothing has
     * been paid, so nothing is refunded and the seats simply go back. Only
     * the session that owns the number may do it; a pending or already
     * paid booking, or one older than the window, goes through the normal
     * cancel flow with its refund slabs.
     *
     * @param array<string, mixed> $user Auth::user()
     * @return array<string, mixed>
     * @throws RuntimeException with a passenger-safe message
     */
    public static function customerUndo(string $pnr, ?array $user): array
    {
        $pnr = strtoupper(trim($pnr));
        if ($user === null || normalisePhone((string) ($user['phone'] ?? '')) === '') {
            throw new RuntimeException('Sign in with the mobile number on the ticket to undo it. / टिकटको मोबाइल नम्बरले sign in गर्नुहोस्।');
        }
        $booking = BookingService::findByPnr($pnr);
        if ($booking === null || normalisePhone((string) ($booking['contact_phone'] ?? '')) !== normalisePhone((string) $user['phone'])) {
            throw new RuntimeException('That ticket is not on this mobile number. / यो टिकट यो नम्बरको होइन।');
        }
        if ((string) $booking['status'] !== 'confirmed' || (int) ($booking['is_cod'] ?? 0) !== 1) {
            throw new RuntimeException('Only a pay-on-boarding ticket can be undone here — use Cancel on the ticket instead. / यो टिकट यहाँबाट undo हुँदैन — Cancel प्रयोग गर्नुहोस्।');
        }
        $window  = max(1, Settings::getInt('quick_ticket_undo_min', 10));
        $created = strtotime((string) ($booking['created_at'] ?? ''));
        if ($created === false || (time() - $created) > $window * 60) {
            throw new RuntimeException('The free undo window (' . $window . ' min) has passed — use Cancel on the ticket. / ' . $window . ' मिनेटको undo समय सकियो — Cancel प्रयोग गर्नुहोस्।');
        }
        $paid = Database::exists(
            "SELECT 1 FROM payments WHERE booking_id = :b AND status = 'verified' AND method <> 'cod'",
            ['b' => (int) $booking['id']]
        );
        if ($paid) {
            throw new RuntimeException('A paid ticket cannot be undone here — use Cancel on the ticket. / भुक्तानी भइसकेको टिकट Cancel बाट मात्र रद्द हुन्छ।');
        }
        // 'undo' (7 Sep 2026): the office bell reads "Ticket undone" and the
        // fan-out sends the passenger one short undo line instead of the
        // "CANCELLED · refund ₹0" pair — see BookingService::cancel().
        $result = BookingService::cancel($pnr, self::UNDO_NOTE, true, 'undo');

        return [
            'ok'           => true,
            'pnr'          => $pnr,
            'status'       => 'cancelled',
            'refundAmount' => (float) ($result['refund']['amount'] ?? 0),
            'windowMin'    => $window,
        ];
    }

    /**
     * How many pay-on-boarding tickets one number may hold unpaid at once
     * through the passenger door (7 Sep 2026). A QuickBot ticket confirms
     * on the spot with no money down, so nothing else stops one phone from
     * parking seats on every date of the month — a family booking out,
     * back and a cousin's trip is three, and that is the default cap
     * (`quick_ticket_max_open`, 0 = no cap). The desk sells on the office
     * phone all day and is not counted; a ticket the counter has settled
     * (payment 'verified') or that has departed no longer counts either.
     * The seat map stays open to the passenger regardless.
     */
    private static function assertCustomerOpenCap(string $phone): void
    {
        $cap = Settings::getInt('quick_ticket_max_open', 3);
        if ($cap <= 0) {
            return;
        }
        $open = (int) Database::scalar(
            "SELECT COUNT(*) FROM bookings b
               JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
              WHERE b.contact_phone = :p AND b.status = 'confirmed' AND b.is_cod = 1
                AND l.travel_date >= CURDATE()
                AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status = 'verified')",
            ['p' => $phone]
        );
        if ($open >= $cap) {
            throw new RuntimeException('This number already holds ' . $open . ' pay-on-boarding ticket' . ($open === 1 ? '' : 's') . ' not paid yet — pay one at the counter or cancel one first; the seat map is open for more. / यो नम्बरमा भुक्तानी बाँकी ' . $open . ' टिकट छन् — एउटा काउन्टरमा तिर्नुहोस् वा रद्द गर्नुहोस्; थप बुकिङ सिट नक्साबाट गर्न सकिन्छ।');
        }
    }

    /**
     * A passenger who already holds a live ticket on this bus and date
     * (made in the last hour) almost certainly double-tapped, or is
     * re-trying after a slow network. The desk sells many tickets to one
     * office phone all day, so this guard is customer-only.
     */
    private static function assertCustomerNotRepeating(string $phone, int $routeId, string $travelDate): void
    {
        $row = Database::fetch(
            "SELECT b.pnr FROM bookings b
               JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
               JOIN schedules s ON s.id = l.schedule_id
              WHERE b.contact_phone = :p AND s.route_id = :r AND l.travel_date = :d
                AND b.status IN ('pending','confirmed')
                AND b.created_at > (NOW() - INTERVAL 60 MINUTE)
           ORDER BY b.id DESC LIMIT 1",
            ['p' => $phone, 'r' => $routeId, 'd' => $travelDate]
        );
        if ($row !== null) {
            throw new RuntimeException('You already have a ticket on this bus for ' . formatDate($travelDate) . ' — PNR ' . $row['pnr'] . '. Open My Bookings to see it. / यो बसमा ' . formatDate($travelDate) . ' को टिकट (PNR ' . $row['pnr'] . ') पहिले नै छ — My Bookings हेर्नुहोस्।');
        }
    }

    private static function isSeatClash(string $message): bool
    {
        return preg_match('/just been taken|booked by someone else|currently booking these seats/i', $message) === 1;
    }

    /** The exact request shape api/book.php hands BookingService::create(). */
    private static function requestFor(array $plan, string $name, string $phone, ?string $gender, string $idType, array $given, string $referralCode = '', string $country = ''): array
    {
        $passengers = [];
        foreach ($plan['seats'] as $i => $seat) {
            $g       = is_array($given[$i] ?? null) ? $given[$i] : [];
            $paxName = Security::clean((string) ($g['name'] ?? ''), 120);
            $paxGen  = in_array($g['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? (string) $g['gender'] : null;
            $passengers[] = [
                'seat'    => $seat,
                // A party under one name is numbered after the buyer, like the seat map does.
                'name'    => $paxName !== '' ? $paxName : ($i === 0 ? $name : $name . ' (' . ($i + 1) . ')'),
                'age'     => isset($g['age']) && $g['age'] !== '' ? (int) $g['age'] : null,
                'gender'  => $paxGen ?? ($i === 0 ? $gender : null),
                'special' => null,
            ];
        }

        return [
            'routeId'         => (int) $plan['routeId'],
            'travelDate'      => (string) $plan['date'],
            'scheduleId'      => (int) $plan['slot'] > 1 ? (int) $plan['scheduleId'] : 0,
            'seats'           => $plan['seats'],
            'passengers'      => $passengers,
            /* 24 Sep 2026: the country rides on the contact too, so
               bookings.contact_country_code is stamped (Notify's first
               choice) rather than inferred from the ID type alone. */
            'contact'         => ['phone' => $phone, 'email' => '', 'idType' => $idType, 'idNum' => '', 'country' => $country],
            'bookingMode'     => $plan['bookingMode'],
            'cabinType'       => $plan['bookingMode'] !== null ? 'single' : null,
            'sharingTier'     => null,
            'boarding'        => (string) $plan['boarding'],
            'drop'            => '',
            'originTown'      => (string) $plan['boardingName'],
            'couponCode'      => '',
            'pointsRequested' => 0,
            // A passenger's optional agent code (SHG-NNN): create() resolves it
            // to sold_by_admin_id; an unknown code never blocks the sale.
            'referralCode'    => $referralCode,
            'paymentMethod'   => 'cash',
            'isCod'           => false,
        ];
    }

    /** What the desk screen shows after the sale. */
    private static function result(array $booking, array $plan, string $name, string $phone, float $t0, bool $customer = false): array
    {
        $bid       = (int) $booking['id'];
        $pnr       = (string) $booking['pnr'];
        $confirmed = (string) ($booking['status'] ?? '') === 'confirmed';

        // The ticket image, rendered now: the desk sees it on screen and the
        // WhatsApp fetch (Twilio pulls the URL itself) never waits on a cold render.
        // A PENDING customer booking (pay-at-counter switched off) has no ticket
        // yet — download-ticket.php refuses it — so nothing is rendered.
        $png = '';
        if ($confirmed) {
            try {
                $png = Ticket::pngPath($bid);
            } catch (Throwable $e) {
                Logger::error('QuickTicket PNG render failed', ['pnr' => $pnr, 'e' => $e->getMessage()]);
            }
        }

        $ticket = Database::fetch('SELECT ticket_number FROM tickets WHERE booking_id = :b LIMIT 1', ['b' => $bid]);
        $seats  = array_map('strval', pluck(Database::fetchAll(
            'SELECT seat_no FROM booking_seats WHERE booking_id = :b AND released_at IS NULL ORDER BY seat_no',
            ['b' => $bid]
        ), 'seat_no'));
        $total = (float) ($booking['total_amount'] ?? 0);

        $out = [
            'ok'           => true,
            'pnr'          => $pnr,
            'ticketNumber' => (string) ($ticket['ticket_number'] ?? ''),
            'bookingId'    => $bid,
            'status'       => (string) ($booking['status'] ?? ''),
            'isCod'        => !empty($booking['is_cod']),
            'paymentMethod'=> (string) ($booking['payment_method'] ?? ''),
            'name'         => $name,
            'phone'        => $phone,
            'seats'        => $seats !== [] ? $seats : $plan['seats'],
            'total'        => $total,
            'totalLabel'   => inr($total),
            'date'         => (string) $plan['date'],
            'dateLabel'    => (string) $plan['dateLabel'],
            'depTime'      => (string) $plan['depTime'],
            'route'        => $plan['from'] . ' → ' . $plan['to'],
            'direction'    => (string) $plan['direction'],
            'boarding'     => (string) $plan['boarding'],
            'boardingName' => (string) $plan['boardingName'],
            'boardingCode' => (string) $plan['boardingCode'],
            'boardingTime' => (string) $plan['boardingTime'],
            // Keyed links — the PNG (WhatsApp / phone) and the PDF (print).
            // Null while a booking is still pending: there is no ticket yet.
            'imageUrl'     => $confirmed ? Ticket::imageUrl($pnr) : null,
            'viewUrl'      => $confirmed ? Ticket::imageUrl($pnr) . '&view=1' : null,
            'pdfUrl'       => $confirmed ? Ticket::downloadUrl($pnr) : null,
            'printUrl'     => $confirmed ? Ticket::downloadUrl($pnr) . '&print=1' : null,
            'adminUrl'     => '/admin/booking-view.php?pnr=' . rawurlencode($pnr),
            'pngReady'     => $png !== '' && is_file($png),
            'whatsapp'     => self::whatsappStatus($booking, $phone),
            'elapsedMs'    => (int) round((microtime(true) - $t0) * 1000),
        ];

        if ($customer) {
            /* The app adopts the booking straight into its local store
               (bookingFromServer) and opens its normal ticket screen — the
               same payload /api/track.php and /api/my-bookings.php send. */
            $detail = null;
            try {
                $detail = BookingService::detail($pnr);
            } catch (Throwable $e) {
                Logger::error('QuickTicket detail failed', ['pnr' => $pnr, 'e' => $e->getMessage()]);
            }
            $out['customer'] = $detail !== null ? shg_customer_payload($detail) : null;
            // Payment targets for the pending (pay-at-counter OFF) path — what api/book.php returns.
            $out['payment'] = [
                'upiId'     => Settings::getString('upi_id', ''),
                'upiName'   => Settings::getString('upi_name', ''),
                'esewaId'   => Settings::getString('esewa_id', ''),
                'esewaName' => Settings::getString('esewa_name', ''),
                'upiLink'   => upiLink(Settings::getString('upi_id', ''), Settings::getString('upi_name', ''), $total, $pnr),
            ];
            $out['verifyTime'] = Settings::getString('verify_time_text', '1–3 minutes');
            $out['adminUrl']   = null;
            /* The passenger is told only whether their ticket went and to
               which number. Which provider the office uses, whether it is
               configured, the click-to-chat link the DESK sends by hand and —
               since 7 Sep 2026 — the provider's own refusal text are the
               office's business, not the buyer's. (The app reads `sent` and
               `to`; api/quick-ticket.php's staff door keeps the full block.) */
            $out['whatsapp'] = [
                'sent' => (bool) ($out['whatsapp']['sent'] ?? false),
                'to'   => (string) ($out['whatsapp']['to'] ?? ''),
            ];
            // Free undo window for a mistaken one-click tap (customerUndo).
            $out['undoMin']    = $confirmed && !empty($booking['is_cod']) ? max(1, Settings::getInt('quick_ticket_undo_min', 10)) : 0;
        }

        return $out;
    }

    /* =================================================================
     *  WhatsApp outcome
     * ================================================================= */

    /**
     * Did the automatic WhatsApp go? Read back from Notify's per-request
     * journal (bookingConfirmed ran inside create()'s post-commit fan-out).
     * Always carries a click-to-chat link so the desk can send by hand when
     * the sender is not live yet (Twilio WhatsApp KYC pending) or a send failed.
     *
     * @return array{attempted: bool, sent: bool, driver: string, configured: bool, to: string, link: string, reason: string, paused: bool, pausedUntil: string, pausedWhy: string}
     */
    public static function whatsappStatus(array $booking, string $phone): array
    {
        $driver     = Settings::getString('whatsapp_driver', 'click_to_chat');
        $configured = match ($driver) {
            'twilio'    => Settings::getString('twilio_account_sid', '') !== ''
                           && Settings::getString('twilio_auth_token', '') !== ''
                           && Settings::getString('twilio_whatsapp_from', '') !== '',
            'cloud_api' => Settings::getString('whatsapp_api_token', '') !== ''
                           && Settings::getString('whatsapp_phone_id', '') !== '',
            default     => false,
        };
        $to   = Notify::whatsappNumberFor($booking);
        $tail = substr(preg_replace('/\D/', '', $phone) ?? '', -8);
        $hit  = null;
        foreach (array_reverse(Notify::whatsappJournal()) as $j) {
            if ($tail !== '' && str_ends_with((string) ($j['to'] ?? ''), $tail)) {
                $hit = $j;
                break;
            }
        }
        $link = ($hit !== null && !empty($hit['link'])) ? (string) $hit['link'] : self::shareLink($booking, $to);
        /* Sender breaker (7 Sep 2026): Twilio is refusing the whole account
           (Trust Hub KYC pending, bad credentials), so automatic sends are
           paused and this ticket went out as a click-to-chat link. The desk
           reads the real reason and the time it resumes by itself, instead of
           "provider refused" on every sale. Only meaningful under the Twilio
           driver: a pause row left over from an earlier Twilio spell must not
           make a Cloud API failure read as "Twilio is refusing the account". */
        $pause = $driver === 'twilio' ? Notify::twilioPause() : null;

        return [
            'attempted'   => $hit !== null,
            'sent'        => $hit !== null && !empty($hit['ok']),
            'driver'      => $driver,
            'configured'  => $configured,
            'to'          => $to !== '' ? '+' . $to : '',
            'link'        => $link,
            'reason'      => (string) ($hit['reason'] ?? ''),
            'paused'      => $pause !== null,
            'pausedUntil' => $pause !== null ? date('H:i', $pause['until']) : '',
            'pausedWhy'   => $pause !== null ? (string) $pause['message'] : '',
        ];
    }

    /** One-tap click-to-chat carrying the ticket image — the manual fallback. */
    public static function shareLink(array $booking, string $toDigits = ''): string
    {
        $pnr = (string) ($booking['pnr'] ?? '');
        if ($toDigits === '') {
            $toDigits = Notify::whatsappNumberFor($booking);
        }
        if ($toDigits === '' || $pnr === '') {
            return '';
        }
        $company = Settings::getString('company_name', APP_NAME);
        $text = "🚌 " . $company . "\n"
              . "टिकट confirm भयो ✅ / Ticket CONFIRMED\n"
              . "PNR: " . $pnr . "\n"
              . "जम्मा / Total: " . inr((float) ($booking['total_amount'] ?? 0)) . "\n"
              . "E-Ticket (image): " . Ticket::imageUrl($pnr) . "\n"
              . "धन्यवाद · " . $company;

        return whatsappLink($toDigits, $text);
    }
}
