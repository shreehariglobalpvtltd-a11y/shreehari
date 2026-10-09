<?php
/**
 * =====================================================================
 *  Boarding-point cut-off
 *
 *  A long-distance coach is still selling seats for hours after it has
 *  left its first town. Someone boarding at Mehsana at 23:00 must be
 *  able to buy a seat at 20:00, long after the bus rolled out of Surat
 *  at 13:00 — but nobody should be sold the SURAT pickup at 20:00,
 *  because that bus is 7 hours down the road.
 *
 *  Before this file the only rule anywhere was "the travel date is not
 *  in the past" (api/search.php), so today's departed pickups stayed on
 *  sale until midnight. This decides, per boarding point, whether that
 *  specific pickup is still catchable.
 *
 *  Times come from `route_stops.stop_time` — the server's own list —
 *  never from the boarding string the browser submits, which a client
 *  could edit. The submitted string is only ever MATCHED against that
 *  list, by town name.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Boarding
{
    /**
     * How the pickups the trade runs read on a ticket: short code + short
     * name, matched by substring against the lowercased stop label. A null
     * name means "use the label's own words" (Emli Bhupal / Limbli - Bhupal).
     * Mirrored in assets/js/02-config.js STOP_CODES — keep the two identical.
     */
    private const STOP_CODES = [
        'nana chiloda' => ['AMD', 'Nana Chiloda'],
        'hari parking' => ['AMD', 'Nana Chiloda'],
        'chiloda'      => ['AMD', 'Nana Chiloda'],
        'ahmedabad'    => ['AMD', 'Ahmedabad'],
        'emli'         => ['EMB', null],
        'bhupal'       => ['EMB', null],
        'mehsana'      => ['MSN', 'Mehsana'],
        'surat'        => ['STV', 'Surat'],
        'baroda'       => ['BRC', 'Baroda'],
        'barauda'      => ['BRC', 'Baroda'],
        'vadodara'     => ['BRC', 'Vadodara'],
        'kamrej'       => ['KMJ', 'Kamrej'],
        'ankleshwar'   => ['AKV', 'Ankleshwar'],
        'ankeshwar'    => ['AKV', 'Ankleshwar'],
        'bharuch'      => ['BRH', 'Bharuch'],
        'anand'        => ['ANA', 'Anand'],
        'nadiad'       => ['NAD', 'Nadiad'],
        'rupaidiha'    => ['RPD', 'Rupaidiha'],
        'jamunaha'     => ['RPD', 'Jamunaha'],
        'nepalgunj'    => ['NPJ', 'Nepalgunj'],
        'nepalganj'    => ['NPJ', 'Nepalgunj'],
        'kohalpur'     => ['KHL', 'Kohalpur'],
        'lucknow'      => ['LKO', 'Lucknow'],
        'bahraich'     => ['BRK', 'Bahraich'],
        'gorakhpur'    => ['GKP', 'Gorakhpur'],
        'kathmandu'    => ['KTM', 'Kathmandu'],
        'delhi'        => ['DEL', 'Delhi'],
        'jaipur'       => ['JAI', 'Jaipur'],
        'udaipur'      => ['UDR', 'Udaipur'],
        'rajkot'       => ['RJT', 'Rajkot'],
    ];

    /**
     * Nepal-side towns, in townKey() form. Decides which side of the border
     * a route's endpoint is on, so Boarding::locations() can split the pickup
     * list into the Gujarat side and the Nepal side without reading Fare (which
     * is not loaded on every page). The `main_points` setting's 'nepal' list,
     * when the office ever sets one, is honoured on top of this.
     */
    private const NEPAL_KEYS = [
        'rupaidiha', 'jamunaha', 'nepalgunj', 'nepalganj', 'kohalpur',
        'kathmandu', 'lumbini', 'butwal', 'bhairahawa', 'dhangadhi',
    ];

    /** @var array<string, array<int, array<string, mixed>>> per-request stop cache */
    private static array $stopCache = [];

    /** @var array<string, mixed>|null per-request Boarding::locations() cache */
    private static ?array $locCache = null;

    /** @var bool|null do route_stops.fare / is_active exist yet? */
    private static ?bool $hasControls = null;

    /** @var bool|null do route_stops.km_from_origin + route_halts exist yet? (9 Oct 2026) */
    private static ?bool $hasDetails = null;

    /**
     * Forget the per-request caches. Admin edits call this so the page that
     * saved a change renders the new state; tests call it between steps.
     */
    public static function flush(): void
    {
        self::$stopCache = [];
        self::$locCache  = null;
        self::$hasControls = null;
        self::$hasDetails  = null;
    }

    /**
     * True once database/upgrade-2026-10-01-stop-fares.sql has run (the
     * route_stops.fare and route_stops.is_active columns exist). Every query
     * below reads through this, so code deployed a minute before the
     * migration keeps selling exactly as before instead of failing on an
     * unknown column.
     */
    public static function hasStopControls(): bool
    {
        if (self::$hasControls === null) {
            try {
                self::$hasControls = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'route_stops'
                        AND COLUMN_NAME IN ('fare', 'is_active')",
                    [],
                    0
                ) === 2;
            } catch (Throwable $e) {
                self::$hasControls = false;
            }
        }

        return self::$hasControls;
    }

    /** SQL select-list fragment for fare + is_active that works before the migration too. */
    private static function controlCols(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';

        return self::hasStopControls()
            ? $p . 'fare AS fare, ' . $p . 'is_active AS is_active'
            : 'NULL AS fare, 1 AS is_active';
    }

    /** " AND is_active = 1" once the column exists, '' before the migration. */
    public static function activeSql(string $alias = ''): string
    {
        return self::hasStopControls() ? ' AND ' . ($alias !== '' ? $alias . '.' : '') . 'is_active = 1' : '';
    }

    /** Is this city on the Nepal side of the border? */
    public static function isNepalTown(string $city): bool
    {
        $k = self::townKey($city);
        if ($k === '') {
            return false;
        }
        if (in_array($k, self::NEPAL_KEYS, true)) {
            return true;
        }
        $mp = Settings::getArray('main_points', []);
        foreach ((array) ($mp['nepal'] ?? []) as $n) {
            if (is_string($n) && self::townKey($n) === $k) {
                return true;
            }
        }

        return false;
    }

    /**
     * 'toNepal' for a Gujarat -> Rupaidiha route, 'toIndia' for the return.
     *
     * @param array<string, mixed> $route routes row (from_city, to_city)
     */
    public static function routeDirection(array $route): string
    {
        return self::isNepalTown((string) ($route['to_city'] ?? ''))
            && !self::isNepalTown((string) ($route['from_city'] ?? ''))
            ? 'toNepal' : 'toIndia';
    }

    /**
     * Which stop list carries the per-pickup fare on a route: the BOARDING
     * stops going to Nepal (the passenger's Gujarat pickup), the DROP stops
     * coming back (their Gujarat drop).
     */
    public static function pricedType(array $route): string
    {
        return self::routeDirection($route) === 'toNepal' ? 'boarding' : 'drop';
    }

    /**
     * Minutes before a pickup that it stops being sellable. A passenger
     * needs time to reach the stop, and the desk needs the manifest to
     * settle. Configurable in Admin → Settings.
     */
    public static function cutoffMinutes(): int
    {
        return max(0, Settings::getInt('boarding_cutoff_minutes', 30));
    }

    /**
     * The town a stop label names, lowercased, for comparison.
     *
     * Server rows read "Ahmedabad — Paldi Bus Stand" while the browser
     * submits "Ahmedabad — SHG Office · Paldi Bus Stand @ 08:00
     * [23.023,72.571]". Both start with the town, so the leading segment
     * before the first dash/·/@ is the reliable comparison key.
     */
    public static function townKey(string $label): string
    {
        $s = (string) preg_replace('/\[[^\]]*\]/u', ' ', $label);   // drop [lat,lng]
        $s = (string) preg_replace('/@.*$/u', ' ', $s);             // drop "@ 08:00"
        // Split on em/en dash, hyphen or the middle dot used as a separator.
        $parts = preg_split('/[—–\-·|,(]/u', $s, 2);
        $town  = is_array($parts) ? (string) $parts[0] : $s;
        $town  = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $town);

        return mb_strtolower(trim($town));
    }

    /**
     * How a stop reads on a ticket: a short code, a short name and the
     * pickup time carried in the label —
     *   "S Hari Parking, Nana Chiloda @ 21:00 [23.171,72.623]"
     *   → AMD · Nana Chiloda · 21:00
     *
     * The ticket used to print the ROUTE's origin (Surat, 13:00) in the big
     * letters even when the passenger boards at Nana Chiloda at nine at
     * night (owner's report, 6 Sep 2026). The PNG, the PDF, the verify page
     * and the in-app card (02-config.js stopDisplay) now all read this one
     * helper. Unknown labels fall back to their own leading words.
     *
     * @return array{code: string, name: string, time: ?string}   time = HH:MM
     */
    public static function stopDisplay(string $label, string $fallbackName = ''): array
    {
        $s    = trim((string) preg_replace('/\s*\[[^\]]*\]\s*$/u', '', trim($label)));   // drop [lat,lng]
        $time = null;
        if (preg_match('/@\s*([0-2]?\d:\d{2})\s*$/u', $s, $m)) {
            $time = str_pad($m[1], 5, '0', STR_PAD_LEFT);
            $s    = trim(mb_substr($s, 0, mb_strlen($s) - mb_strlen($m[0])));
        }
        if ($s === '') {
            $s = trim($fallbackName);
        }
        $lead = trim((string) ((preg_split('/\s*·\s*/u', $s) ?: [$s])[0]));        // before the landmark
        $key  = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lead)));
        if ($key !== '') {
            foreach (self::STOP_CODES as $needle => [$code, $name]) {
                if (str_contains($key, $needle)) {
                    return ['code' => $code, 'name' => $name ?? self::stopTown($lead), 'time' => $time];
                }
            }
        }
        $town    = self::stopTown($lead);
        $letters = (string) preg_replace('/[^A-Za-z]/', '', $town);

        return ['code' => strtoupper(substr($letters !== '' ? $letters : 'SHG', 0, 3)), 'name' => $town, 'time' => $time];
    }

    /**
     * WHEN this passenger is picked up, as a unix time (8 Oct 2026) — the one
     * server-side answer for the trip reminders, the ticket's live pill and
     * anything else that counts down to "your bus".
     *
     * The route's departure (Surat 13:00) is not the passenger's: a Mehsana
     * traveller on the same coach is picked up at 23:00, ten hours later, and
     * was being told "your bus leaves in 2 hours" at eleven in the morning and
     * "Departed" at five past one. The time is, in order: the leg's own
     * boarding_time, the "@ HH:MM" the stop label carries, the route's
     * departure. A pickup earlier than the departure by more than an hour is
     * past midnight — the next calendar day (r6 leaves Mehsana 21:00 and
     * reaches Vadodara 00:00).
     *
     * A RETIMED trip (8 Oct 2026, review): the office's "Edit dep time" moves
     * the daily bus (dep_time_override) but the sold labels keep the
     * timetable's "@ 13:00", and Quick Ticket labels an extra bus with the
     * timetable time too. The label's clock is then read against the clock
     * it was written for ($routeDep, the route timetable) and the stop's
     * offset is laid onto the effective departure — 13:00 → 15:00 moves the
     * Surat pickup to 15:00 the same day, never to 13:00 the next day.
     * Extra buses sold through search carry labels already shifted to their
     * own departure (api/search.php), so for slot > 1 the timetable is used
     * only when the label still shows the stop's timetable time.
     *
     * @return int unix time, or 0 when the date or every time is unusable
     */
    public static function pickupTs(string $travelDate, string $boardingStop, ?string $boardingTime, string $depTime,
                                     ?string $routeDep = null, int $slot = 1, int $routeId = 0): int
    {
        $travelDate = trim($travelDate);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $travelDate) !== 1) {
            return 0;
        }
        $hhmm = static function (?string $t): string {
            $t = trim((string) $t);
            return preg_match('/^([0-2]?\d):([0-5]\d)/', $t, $m) === 1 && (int) $m[1] < 24
                ? str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2] : '';
        };
        $dep       = $hhmm($depTime);
        $pick      = $hhmm($boardingTime);
        $fromLabel = false;
        if ($pick === '') {
            $pick      = $hhmm((string) (self::stopDisplay($boardingStop)['time'] ?? ''));
            $fromLabel = $pick !== '';
        }
        if ($pick === '') {
            $pick = $dep;
        }
        if ($pick === '') {
            return 0;
        }
        if ($dep === '') {
            $ts = strtotime($travelDate . ' ' . $pick);
            return $ts === false ? 0 : (int) $ts;
        }

        // The clock the label was written against (see the docblock).
        $base = $dep;
        $tt   = $hhmm($routeDep);
        if ($fromLabel && $tt !== '' && $tt !== $dep) {
            if ($slot <= 1) {
                $base = $tt;
            } elseif ($routeId > 0) {
                try {
                    if ($hhmm(self::timeForStop($routeId, $boardingStop)) === $pick) {
                        $base = $tt;
                    }
                } catch (Throwable $e) {
                    // no stop table: keep the effective departure
                }
            }
        }

        // The stop's offset from departure: -60 min .. +23 h (a pickup more
        // than an hour "before" departure is past midnight on the same run).
        $toMin = static fn(string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
        $off   = (($toMin($pick) - $toMin($base)) % 1440 + 1440) % 1440;
        if ($off > 1380) {
            $off -= 1440;
        }
        $ts = strtotime($travelDate . ' ' . $dep);
        if ($ts === false) {
            return 0;
        }
        return (int) $ts + $off * 60;
    }

    /** "Mehsana — Silver Complex" → "Mehsana"; "S Hari Parking, Nana Chiloda" → "Nana Chiloda". */
    private static function stopTown(string $lead): string
    {
        $t = trim((string) ((preg_split('/\s+[—–-]\s+/u', $lead, 2) ?: [$lead])[0]));
        if (str_contains($t, ',')) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $t)), 'strlen'));
            $t     = $parts !== [] ? (string) end($parts) : $t;
        }

        return $t !== '' ? $t : trim($lead);
    }

    /**
     * Boarding stops for a route, from the server's own table.
     *
     * @return array<int, array{name: string, time: string|null, order: int}>
     */
    public static function stopsFor(int $routeId): array
    {
        $ck = 'b|' . $routeId;
        if (isset(self::$stopCache[$ck])) {
            return self::$stopCache[$ck];
        }

        /* Only stops switched ON in Admin (route_stops.is_active, 1 Oct 2026):
           this list is what search, the counter, QuickTicket, the bots and the
           cut-off sell from. A stop switched OFF keeps its row (old bookings
           carry its text) — allStopsFor() still sees it for display. */
        $rows = Database::fetchAll(
            "SELECT stop_name, stop_time, sort_order, " . self::controlCols() . "
               FROM route_stops
              WHERE route_id = :r AND stop_type = 'boarding'" . self::activeSql() . "
              ORDER BY sort_order ASC, id ASC",
            ['r' => $routeId]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name'  => (string) $r['stop_name'],
                'time'  => $r['stop_time'] !== null ? (string) $r['stop_time'] : null,
                'order' => (int) $r['sort_order'],
                'fare'  => $r['fare'] !== null && (float) $r['fare'] > 0 ? round((float) $r['fare'], 2) : null,
            ];
        }

        return self::$stopCache[$ck] = $out;
    }

    /**
     * Every stop row of a route and type, ON and OFF, in Admin order — for
     * DISPLAY of existing bookings (seat-map pickup colours, legends), never
     * for selling.
     *
     * @return array<int, array{id: int, name: string, landmark: string, time: ?string, order: int, fare: ?float, active: bool}>
     */
    public static function allStopsFor(int $routeId, string $type = 'boarding'): array
    {
        $type = $type === 'drop' ? 'drop' : 'boarding';
        $ck   = 'all|' . $type . '|' . $routeId;
        if (isset(self::$stopCache[$ck])) {
            return self::$stopCache[$ck];
        }
        $rows = Database::fetchAll(
            "SELECT id, stop_name, landmark, stop_time, sort_order, " . self::controlCols() . "
               FROM route_stops
              WHERE route_id = :r AND stop_type = :t
              ORDER BY sort_order ASC, id ASC",
            ['r' => $routeId, 't' => $type]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'       => (int) $r['id'],
                'name'     => (string) $r['stop_name'],
                'landmark' => (string) ($r['landmark'] ?? ''),
                'time'     => $r['stop_time'] !== null ? (string) $r['stop_time'] : null,
                'order'    => (int) $r['sort_order'],
                'fare'     => $r['fare'] !== null && (float) $r['fare'] > 0 ? round((float) $r['fare'], 2) : null,
                'active'   => (int) $r['is_active'] === 1,
            ];
        }

        return self::$stopCache[$ck] = $out;
    }

    /**
     * The stop row a label names on a route, matched the way the cut-off
     * matches it: an exact stop_name first, else the same townKey() — an ON
     * row before an OFF one. Null when the route has no such stop. The row
     * carries its fare and is_active so callers can price and gate with it.
     *
     * @return array{id: int, name: string, landmark: string, time: ?string, order: int, fare: ?float, active: bool}|null
     */
    public static function stopRow(int $routeId, string $label, string $type = 'boarding'): ?array
    {
        $label = trim($label);
        if ($routeId <= 0 || $label === '') {
            return null;
        }
        $rows = self::allStopsFor($routeId, $type);
        foreach ($rows as $row) {
            if (strcasecmp($row['name'], $label) === 0) {
                return $row;
            }
        }
        $want = self::townKey($label);
        if ($want === '') {
            return null;
        }
        $off = null;
        foreach ($rows as $row) {
            if (self::townKey($row['name']) !== $want) {
                continue;
            }
            if ($row['active']) {
                return $row;
            }
            $off ??= $row;
        }

        return $off;
    }

    /**
     * Boarding::locations() for the page boot payload (SHG_BOOT.locations /
     * api/config.php). Never throws: a database hiccup ships empty lists and
     * the app falls back to its packaged CONFIG.mainPoints.
     *
     * @return array<string, mixed>
     */
    public static function bootLocations(): array
    {
        try {
            return self::locations();
        } catch (Throwable $e) {
            return ['india' => [], 'nepal' => [], 'go' => [], 'back' => []];
        }
    }

    /**
     * Does this town name an ON stop of the given type on this route?
     * (api/search.php and api/occupancy.php used to carry byte-identical
     * copies of this closure that also matched stops switched OFF.)
     */
    public static function servesTown(int $routeId, string $type, string $town): bool
    {
        $want = self::townKey($town);
        if ($want === '') {
            return false;
        }
        foreach (self::allStopsFor($routeId, $type) as $s) {
            if ($s['active'] && self::townKey($s['name']) === $want) {
                return true;
            }
        }

        return false;
    }

    /**
     * May this route sell this boarding / drop label? (create()'s gate.)
     *
     *   'ok'       an ON stop of the route, or a route that has no stop rows
     *              of this type at all (old routes keep selling as before), or
     *              the route's own endpoint city when no stop row names it;
     *   'off'      the stop exists but the office switched it OFF;
     *   'unknown'  the route has stops and none of them is this town.
     */
    public static function labelStatus(array $route, string $label, string $type = 'boarding'): string
    {
        $type    = $type === 'drop' ? 'drop' : 'boarding';
        $routeId = (int) ($route['id'] ?? 0);
        if (trim($label) === '' || self::allStopsFor($routeId, $type) === []) {
            return 'ok';
        }
        $row = self::stopRow($routeId, $label, $type);
        if ($row !== null) {
            return $row['active'] ? 'ok' : 'off';
        }
        $endpoint = (string) ($route[$type === 'boarding' ? 'from_city' : 'to_city'] ?? '');
        if ($endpoint !== '' && self::townKey($endpoint) === self::townKey($label)) {
            return 'ok';
        }

        return 'unknown';
    }

    /**
     * THE location list (owner R3g, 1 Oct 2026): every place the company
     * sells, built from the ON stops of the ACTIVE routes in Admin order.
     * The homepage picker, the fare board, Fare::mainPoints(), the AI and
     * WhatsApp answers all read this — no second hard-coded copy.
     *
     *   go     Gujarat pickups of the Gujarat -> Nepal routes, with time + fare
     *   back   Gujarat drops of the return routes (the route's own to_city
     *          first, so a "Rupaidiha -> Surat" search still finds the bus
     *          when the drop is a single "Gujarat" row)
     *   india  go ∪ back names (Gujarat side), deduped by townKey
     *   nepal  the Nepal-side points (Rupaidiha)
     *
     * @return array{india: string[], nepal: string[], go: array<int, array{name: string, time: ?string, fare: ?float}>, back: array<int, array{name: string, time: ?string, fare: ?float}>}
     */
    public static function locations(): array
    {
        if (self::$locCache !== null) {
            return self::$locCache;
        }
        $go = $back = $nepal = [];
        $seenGo = $seenBack = $seenNp = [];
        $add = static function (array &$list, array &$seen, string $name, ?string $time = null, ?float $fare = null): void {
            $name = trim($name);
            $k    = self::townKey($name);
            if ($name === '' || $k === '' || isset($seen[$k])) {
                return;
            }
            $seen[$k] = true;
            $list[]   = ['name' => $name, 'time' => $time !== null ? substr($time, 0, 5) : null, 'fare' => $fare];
        };

        try {
            $routes = Database::fetchAll('SELECT id, from_city, to_city FROM routes WHERE is_active = 1 ORDER BY sort_order, id');
        } catch (Throwable $e) {
            $routes = [];
        }
        foreach ($routes as $r) {
            $rid = (int) $r['id'];
            $dir = self::routeDirection($r);
            if ($dir === 'toNepal') {
                $stops = array_values(array_filter(self::allStopsFor($rid, 'boarding'), static fn(array $s): bool => $s['active']));
                if ($stops === [] && self::allStopsFor($rid, 'boarding') === []) {
                    $add($go, $seenGo, (string) $r['from_city']);
                }
                foreach ($stops as $s) {
                    $add($go, $seenGo, $s['name'], $s['time'], $s['fare']);
                }
                $add($nepal, $seenNp, (string) $r['to_city']);
            } else {
                $add($back, $seenBack, (string) $r['to_city']);
                foreach (self::allStopsFor($rid, 'drop') as $s) {
                    if ($s['active']) {
                        $add($back, $seenBack, $s['name'], $s['time'], $s['fare']);
                    }
                }
                $add($nepal, $seenNp, (string) $r['from_city']);
            }
        }

        $india = [];
        $seenIn = [];
        foreach (array_merge($go, $back) as $s) {
            $k = self::townKey($s['name']);
            if (!isset($seenIn[$k])) {
                $seenIn[$k] = true;
                $india[]    = $s['name'];
            }
        }

        return self::$locCache = [
            'india' => $india,
            'nepal' => array_map(static fn(array $s): string => $s['name'], $nepal),
            'go'    => $go,
            'back'  => $back,
        ];
    }

    /**
     * The pickup time the server believes for a boarding label, as
     * 'HH:MM:SS'. Falls back to the route's own departure time when the
     * label matches no stop row, and null when even that is unknown.
     */
    public static function timeForStop(int $routeId, string $boardingStop): ?string
    {
        $want = self::townKey($boardingStop);

        if ($want !== '') {
            foreach (self::stopsFor($routeId) as $stop) {
                if ($stop['time'] !== null && self::townKey($stop['name']) === $want) {
                    return $stop['time'];
                }
            }
        }

        $dep = Database::scalar('SELECT dep_time FROM routes WHERE id = :r LIMIT 1', ['r' => $routeId]);

        return $dep !== null && $dep !== '' ? (string) $dep : null;
    }

    /**
     * Is this pickup still catchable for this travel date?
     *
     * A future date is always open; a past date never is. For today the
     * pickup closes `cutoffMinutes()` before its own time — so the later
     * stops on the same run stay on sale after the earlier ones shut.
     *
     * Fails OPEN when no time can be determined at all: refusing a sale
     * we cannot justify would be worse than the status quo, and every
     * seeded stop currently carries a time.
     *
     * @return array{open: bool, time: ?string, reason: string}
     */
    public static function status(int $routeId, string $travelDate, string $boardingStop): array
    {
        $today = todayISO();

        if ($travelDate > $today) {
            return ['open' => true, 'time' => null, 'reason' => ''];
        }
        if ($travelDate < $today) {
            return ['open' => false, 'time' => null, 'reason' => 'That travel date has already passed.'];
        }

        $time = self::timeForStop($routeId, $boardingStop);
        if ($time === null) {
            return ['open' => true, 'time' => null, 'reason' => ''];
        }

        $stopAt   = strtotime($travelDate . ' ' . $time);
        $closesAt = $stopAt - (self::cutoffMinutes() * 60);

        if ($stopAt === false || time() < $closesAt) {
            return ['open' => true, 'time' => substr($time, 0, 5), 'reason' => ''];
        }

        return [
            'open'   => false,
            'time'   => substr($time, 0, 5),
            'reason' => 'The bus has already left this pickup point today ('
                        . substr($time, 0, 5) . '). Please choose a later pickup, or tomorrow’s bus.',
        ];
    }

    /** Convenience wrapper — just the yes/no. */
    public static function isOpen(int $routeId, string $travelDate, string $boardingStop): bool
    {
        return self::status($routeId, $travelDate, $boardingStop)['open'];
    }

    /**
     * Boarding labels still sellable for a route on a date. An empty
     * result for TODAY means the whole run is past its last pickup.
     *
     * @return array<int, array{name: string, time: ?string}>
     */
    public static function openStops(int $routeId, string $travelDate): array
    {
        $out = [];
        foreach (self::stopsFor($routeId) as $stop) {
            if (self::status($routeId, $travelDate, $stop['name'])['open']) {
                $out[] = [
                    'name' => $stop['name'],
                    'time' => $stop['time'] !== null ? substr($stop['time'], 0, 5) : null,
                ];
            }
        }
        return $out;
    }

    /**
     * Can this route still be sold at all on this date? True when at
     * least one pickup is still ahead. A route with no stop rows falls
     * back to its departure time so it is never silently unsellable.
     */
    public static function routeSellable(int $routeId, string $travelDate): bool
    {
        $today = todayISO();
        if ($travelDate > $today) {
            return true;
        }
        if ($travelDate < $today) {
            return false;
        }

        $stops = self::stopsFor($routeId);
        if ($stops === []) {
            return self::status($routeId, $travelDate, '')['open'];
        }

        return self::openStops($routeId, $travelDate) !== [];
    }

    /**
     * Agent late-booking grace (owner ask, 3 Sep 2026): is NOW within $hours of
     * this route's SCHEDULED departure on $travelDate? True ONLY once that
     * departure has passed and up to $hours after it — the window in which an
     * agent (with a VALID code) may still record a ticket for a bus that has
     * already left Surat. Anchored to the scheduled departure ("1 baje chuteko
     * + 24 ghanta"), using a per-schedule dep_time_override when one exists,
     * else the route's own dep_time. Fails CLOSED when no departure time is
     * known, so it can never widen a route that has no timetable.
     */
    public static function agentGraceOpen(int $routeId, string $travelDate, int $hours = 24): bool
    {
        $depTime = Database::scalar(
            "SELECT COALESCE(s.dep_time_override, r.dep_time)
               FROM routes r
          LEFT JOIN schedules s ON s.route_id = r.id AND s.travel_date = :d AND s.slot = 1
              WHERE r.id = :r LIMIT 1",
            ['d' => $travelDate, 'r' => $routeId],
            null
        );
        if ($depTime === null || $depTime === '') { return false; }
        $depTs = strtotime($travelDate . ' ' . (string) $depTime);
        if ($depTs === false) { return false; }
        $now = time();
        return $now >= $depTs && $now <= $depTs + $hours * 3600;
    }

    /**
     * routeSellable() OR still inside the agent 24h grace — the sellability
     * test for an AGENT-context search/booking. Kept as a separate method so
     * the anonymous routeSellable() (and every caller of it) stays byte-for-
     * byte unchanged; only paths that have resolved a valid agent code call it.
     */
    public static function routeSellableForAgent(int $routeId, string $travelDate, int $hours = 24): bool
    {
        return self::routeSellable($routeId, $travelDate)
            || self::agentGraceOpen($routeId, $travelDate, $hours);
    }

    /* =================================================================
     *  ROUTE FACTS (9 Oct 2026, owner ask #11/#12) — road km per stop,
     *  en-route halts, and the ONE ordered "journey line" every screen
     *  draws: the home route rail (22-routeline.js via api/timetable.php),
     *  the live tracker (WhereIs::status -> track.php) and Admin -> Routes.
     *  Guarded: before database/upgrade-2026-10-09-route-details.sql runs,
     *  every helper answers "no km, no halts" instead of failing.
     * ================================================================= */

    /** The kinds route_halts.kind accepts (keep in step with the ENUM). */
    public const HALT_KINDS = ['meal', 'breakfast', 'lunch', 'dinner', 'tea', 'fuel', 'rest', 'border'];

    /** True once route_stops.km_from_origin and the route_halts table exist. */
    public static function hasRouteDetails(): bool
    {
        if (self::$hasDetails === null) {
            try {
                $col = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'route_stops' AND COLUMN_NAME = 'km_from_origin'",
                    [], 0
                );
                $tbl = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'route_halts'",
                    [], 0
                );
                self::$hasDetails = $col === 1 && $tbl === 1;
            } catch (Throwable $e) {
                self::$hasDetails = false;
            }
        }

        return self::$hasDetails;
    }

    /** SQL select-list fragment for the stop km that works before the migration too. */
    public static function kmSql(string $alias = ''): string
    {
        return self::hasRouteDetails()
            ? ($alias !== '' ? $alias . '.' : '') . 'km_from_origin AS km_from_origin'
            : 'NULL AS km_from_origin';
    }

    /** "HH:MM[:SS]" -> minutes after midnight, or null. */
    public static function hmToMin(?string $t): ?int
    {
        if ($t === null || preg_match('/^\s*([0-2]?\d):([0-5]\d)/', $t, $m) !== 1 || (int) $m[1] > 23) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** Minutes after midnight -> "HH:MM" (wraps past midnight). */
    public static function minToHm(int $min): string
    {
        $min = (($min % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
    }

    /**
     * A halt's offset from the departure, from the clock the office types
     * and the journey day it falls on (0 = departure day, 1 = next day ...).
     * Null when that moment would sit before the departure.
     */
    public static function haltOffset(string $clock, int $day, string $depTime): ?int
    {
        $c = self::hmToMin($clock);
        $d = self::hmToMin($depTime);
        if ($c === null || $d === null) {
            return null;
        }
        $off = max(0, min(3, $day)) * 1440 + $c - $d;

        return $off >= 0 ? $off : null;
    }

    /**
     * Journey minutes from the timetable: departure -> arrival plus
     * day_offset calendar days. Null when the arrival is missing or the
     * result is outside one hour .. four days.
     */
    public static function durationMin(?string $depTime, ?string $arrTime, int $dayOffset): ?int
    {
        $d = self::hmToMin($depTime);
        $a = self::hmToMin($arrTime);
        if ($d === null || $a === null || $dayOffset < 0) {
            return null;
        }
        $min = $dayOffset * 1440 + $a - $d;

        return $min >= 60 && $min <= 4 * 1440 ? $min : null;
    }

    /**
     * Halts of many routes in one query, grouped by route id, in the office's
     * order. Empty before the migration (never throws).
     *
     * @param int[] $routeIds
     * @return array<int, list<array<string, mixed>>>
     */
    public static function haltsForRoutes(array $routeIds, bool $activeOnly = true): array
    {
        $routeIds = array_values(array_unique(array_filter(array_map('intval', $routeIds), static fn(int $i): bool => $i > 0)));
        if ($routeIds === [] || !self::hasRouteDetails()) {
            return [];
        }
        $ph = [];
        $pp = [];
        foreach ($routeIds as $i => $id) {
            $ph[]          = ':h' . $i;
            $pp['h' . $i] = $id;
        }
        try {
            $rows = Database::fetchAll(
                'SELECT id, route_id, sort_order, name, kind, clock_time, offset_min, halt_min, km_from_origin,
                        landmark, note, is_approx, is_active
                   FROM route_halts
                  WHERE route_id IN (' . implode(',', $ph) . ')' . ($activeOnly ? ' AND is_active = 1' : '') . '
                  ORDER BY route_id, sort_order, id',
                $pp
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['route_id']][] = self::haltRow($r);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function haltsFor(int $routeId, bool $activeOnly = true): array
    {
        return self::haltsForRoutes([$routeId], $activeOnly)[$routeId] ?? [];
    }

    /** One route_halts row in the shape journeyLine() reads. */
    private static function haltRow(array $r): array
    {
        return [
            'id'        => (int) $r['id'],
            'routeId'   => (int) $r['route_id'],
            'sort'      => (int) $r['sort_order'],
            'name'      => (string) $r['name'],
            'kind'      => in_array((string) $r['kind'], self::HALT_KINDS, true) ? (string) $r['kind'] : 'rest',
            'clock'     => $r['clock_time'] !== null ? substr((string) $r['clock_time'], 0, 5) : null,
            'offsetMin' => $r['offset_min'] !== null ? (int) $r['offset_min'] : null,
            'haltMin'   => max(0, (int) $r['halt_min']),
            'km'        => $r['km_from_origin'] !== null ? round((float) $r['km_from_origin'], 1) : null,
            'landmark'  => (string) ($r['landmark'] ?? ''),
            'note'      => (string) ($r['note'] ?? ''),
            'approx'    => (int) $r['is_approx'] === 1,
            'active'    => (int) $r['is_active'] === 1,
        ];
    }

    /**
     * Every stop row of a route in the shape journeyLine() reads, in the
     * office's order (boarding, then drops). ON stops only by default.
     *
     * @return list<array<string, mixed>>
     */
    public static function lineStops(int $routeId, bool $activeOnly = true): array
    {
        $rows = Database::fetchAll(
            'SELECT id, stop_type, stop_name, landmark, stop_time, latitude, longitude, is_border, ' . self::kmSql() . '
               FROM route_stops
              WHERE route_id = :r' . ($activeOnly ? self::activeSql() : '') . "
              ORDER BY FIELD(stop_type, 'boarding', 'drop'), sort_order, id",
            ['r' => $routeId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'       => (int) $r['id'],
                'type'     => (string) $r['stop_type'] === 'drop' ? 'drop' : 'boarding',
                'name'     => (string) $r['stop_name'],
                'landmark' => (string) ($r['landmark'] ?? ''),
                'time'     => $r['stop_time'] !== null ? (string) $r['stop_time'] : null,
                'km'       => $r['km_from_origin'] !== null ? (float) $r['km_from_origin'] : null,
                'isBorder' => (int) $r['is_border'] === 1,
                'lat'      => $r['latitude'] !== null && (float) $r['latitude'] !== 0.0 ? (float) $r['latitude'] : null,
                'lng'      => $r['longitude'] !== null && (float) $r['longitude'] !== 0.0 ? (float) $r['longitude'] : null,
            ];
        }

        return $out;
    }

    /**
     * THE ordered run of one route: its stops and its halts merged into
     * travel order, each with the timetable clock, the journey day and the
     * road km from the origin.
     *
     * Order: road km where known; an entry without km takes its place from
     * its time (interpolated on the entries that carry both), else sits next
     * to its neighbour in the office's own list. Times are offsets from the
     * departure (a pickup more than an hour "before" departure is past
     * midnight, as in pickupTs()), so a 00:30 stop never sorts first. Two
     * rows of one town (an old and a new label) collapse into one entry that
     * keeps every row id.
     *
     * @param list<array<string, mixed>> $stops  lineStops() shape, office order
     * @param list<array<string, mixed>> $halts  haltRow() shape
     * @return list<array<string, mixed>>
     */
    public static function journeyLine(array $stops, array $halts, string $depTime): array
    {
        $dep   = self::hmToMin($depTime);
        $items = [];
        $board = [];
        $drop  = [];
        foreach ($stops as $s) {
            if ((string) ($s['type'] ?? 'boarding') === 'drop') {
                $drop[] = $s;
            } else {
                $board[] = $s;
            }
        }
        $num = static fn(mixed $v): ?float => $v !== null && is_numeric($v) ? (float) $v : null;

        $maxStopOff = null;
        foreach ([['boarding', $board], ['drop', $drop]] as [$type, $list]) {
            foreach ($list as $s) {
                $t   = self::hmToMin(isset($s['time']) ? (string) $s['time'] : null);
                $off = null;
                if ($t !== null && $dep !== null) {
                    $off = (($t - $dep) % 1440 + 1440) % 1440;
                    if ($type === 'boarding') {
                        if ($off > 1380) {
                            $off -= 1440;
                        }
                    } else {
                        // a drop comes after the pickups: 00:39 on a 13:00 coach is a later day
                        while ($maxStopOff !== null && $off < $maxStopOff && $off < 3 * 1440) {
                            $off += 1440;
                        }
                    }
                    $maxStopOff = $maxStopOff === null ? $off : max($maxStopOff, $off);
                }
                $disp    = self::stopDisplay((string) $s['name']);
                $km      = $num($s['km'] ?? null);
                $items[] = [
                    'kind' => 'stop', 'type' => $type, 'role' => $type === 'drop' ? 'drop' : 'board',
                    'name' => (string) $disp['name'], 'label' => (string) $s['name'], 'code' => (string) $disp['code'],
                    'landmark' => trim((string) ($s['landmark'] ?? '')),
                    'clock' => $t !== null ? self::minToHm($t) : '', 'off' => $off,
                    'km' => $km !== null ? round($km, 1) : null, 'border' => !empty($s['isBorder']),
                    'halt' => '', 'haltMin' => null, 'approx' => false, 'note' => '',
                    'lat' => $num($s['lat'] ?? null), 'lng' => $num($s['lng'] ?? null),
                    'open' => array_key_exists('open', $s) && $s['open'] !== null ? (bool) $s['open'] : null,
                    'ids' => isset($s['id']) ? [(int) $s['id']] : [],
                ];
            }
        }
        foreach ($halts as $h) {
            $off = isset($h['offsetMin']) && $h['offsetMin'] !== null ? (int) $h['offsetMin'] : null;
            if ($off === null && !empty($h['clock']) && $dep !== null) {
                $c   = self::hmToMin((string) $h['clock']);
                $off = $c !== null ? (($c - $dep) % 1440 + 1440) % 1440 : null;
            }
            $km      = $num($h['km'] ?? null);
            $kind    = in_array((string) ($h['kind'] ?? ''), self::HALT_KINDS, true) ? (string) $h['kind'] : 'rest';
            $items[] = [
                'kind' => 'halt', 'type' => 'halt', 'role' => 'halt',
                'name' => (string) ($h['name'] ?? ''), 'label' => (string) ($h['name'] ?? ''), 'code' => '',
                'landmark' => trim((string) ($h['landmark'] ?? '')),
                'clock' => $off !== null && $dep !== null ? self::minToHm($dep + $off) : (string) ($h['clock'] ?? ''),
                'off' => $off, 'km' => $km !== null ? round($km, 1) : null, 'border' => $kind === 'border',
                'halt' => $kind, 'haltMin' => (int) ($h['haltMin'] ?? 0), 'approx' => !empty($h['approx']),
                'note' => (string) ($h['note'] ?? ''), 'lat' => null, 'lng' => null, 'open' => null,
                'ids' => isset($h['id']) ? [(int) $h['id']] : [],
            ];
        }
        if ($items === []) {
            return [];
        }

        // Sort keys: road km (time-interpolated when missing), else minutes.
        $useKm   = count(array_filter($items, static fn(array $i): bool => $i['km'] !== null)) >= 2;
        $anchors = [];
        if ($useKm) {
            foreach ($items as $i) {
                if ($i['km'] !== null && $i['off'] !== null) {
                    $anchors[] = [(float) $i['off'], (float) $i['km']];
                }
            }
            usort($anchors, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        }
        $interp = static function (float $off) use ($anchors): ?float {
            $n = count($anchors);
            if ($n < 2) {
                return null;
            }
            for ($k = 0; $k < $n - 1; $k++) {
                if ($off <= $anchors[$k + 1][0] || $k === $n - 2) {
                    [$o1, $k1] = $anchors[$k];
                    [$o2, $k2] = $anchors[$k + 1];
                    $span = $o2 - $o1;

                    return $span > 0 ? max(0.0, $k1 + ($off - $o1) * ($k2 - $k1) / $span) : $k1;
                }
            }

            return null;
        };
        foreach ($items as $n => $it) {
            $key = null;
            if ($useKm) {
                $key = $it['km'] ?? ($it['off'] !== null ? $interp((float) $it['off']) : null);
            } elseif ($it['off'] !== null) {
                $key = (float) $it['off'];
            }
            $items[$n]['key'] = $key;
        }
        // A stop with no key sits beside its neighbour in the office's list.
        foreach (['boarding', 'drop'] as $type) {
            $idx = array_keys(array_filter($items, static fn(array $i): bool => $i['type'] === $type));
            $cnt = count($idx);
            foreach ($idx as $p => $n) {
                if ($items[$n]['key'] !== null) {
                    continue;
                }
                $k = null;
                for ($q = $p - 1; $q >= 0 && $k === null; $q--) {
                    if ($items[$idx[$q]]['key'] !== null) {
                        $k = $items[$idx[$q]]['key'] + 0.001 * ($p - $q);
                    }
                }
                for ($q = $p + 1; $q < $cnt && $k === null; $q++) {
                    if ($items[$idx[$q]]['key'] !== null) {
                        $k = $items[$idx[$q]]['key'] - 0.001 * ($q - $p);
                    }
                }
                $items[$n]['key'] = $k ?? ($type === 'boarding' ? -1.0 + 0.001 * $p : 1.0e7 + $p);
            }
        }
        $stopKeys = [];
        foreach ($items as $it) {
            if ($it['kind'] === 'stop') {
                $stopKeys[] = (float) $it['key'];
            }
        }
        $lastKey = $stopKeys !== [] ? max($stopKeys) : 0.0;
        foreach ($items as $n => $it) {
            if ($it['kind'] === 'halt' && $it['key'] === null) {
                $items[$n]['key'] = $lastKey - 0.0005;      // unknown place: just before the end
            }
        }
        $rank  = ['boarding' => 0, 'halt' => 1, 'drop' => 2];
        $order = array_keys($items);
        usort($order, static fn(int $a, int $b): int =>
            [(float) $items[$a]['key'], $rank[$items[$a]['type']], $a] <=> [(float) $items[$b]['key'], $rank[$items[$b]['type']], $b]);

        // One entry per town and list (old + new labels of one place): the
        // ticket code when the town is a known one (Barauda / Vadodara = BRC),
        // else the display name.
        $known = array_flip(array_map(static fn(array $c): string => $c[0], self::STOP_CODES));
        $out   = [];
        $seen  = [];
        foreach ($order as $n) {
            $it = $items[$n];
            if ($it['kind'] === 'stop') {
                $dk = $it['type'] . '|' . (isset($known[$it['code']]) ? $it['code'] : mb_strtolower($it['name']));
                if (isset($seen[$dk])) {
                    $j = $seen[$dk];
                    foreach (['km', 'off', 'lat', 'lng'] as $f) {
                        if ($out[$j][$f] === null && $it[$f] !== null) {
                            $out[$j][$f] = $it[$f];
                        }
                    }
                    foreach (['clock', 'landmark'] as $f) {
                        if ($out[$j][$f] === '' && $it[$f] !== '') {
                            $out[$j][$f] = $it[$f];
                        }
                    }
                    $out[$j]['border'] = $out[$j]['border'] || $it['border'];
                    if ($it['open'] === true) {
                        $out[$j]['open'] = true;
                    }
                    $out[$j]['ids'] = array_merge($out[$j]['ids'], $it['ids']);
                    continue;
                }
                $seen[$dk] = count($out);
            }
            $out[] = $it;
        }

        $line = [];
        foreach ($out as $it) {
            $abs    = $dep !== null && $it['off'] !== null ? $dep + (int) $it['off'] : null;
            $line[] = [
                'kind'     => $it['kind'],
                'role'     => $it['role'],
                'name'     => $it['name'],
                'label'    => $it['label'],
                'code'     => $it['code'],
                'landmark' => $it['landmark'],
                'time'     => $it['clock'],
                'dayPlus'  => $abs !== null ? (int) floor($abs / 1440) : null,
                'offMin'   => $it['off'],
                'km'       => $it['km'],
                'border'   => $it['border'],
                'halt'     => $it['halt'],
                'haltMin'  => $it['haltMin'],
                'approx'   => $it['approx'],
                'note'     => $it['note'],
                'lat'      => $it['lat'],
                'lng'      => $it['lng'],
                'open'     => $it['open'],
                'ids'      => $it['ids'],
            ];
        }

        return $line;
    }

    /**
     * Totals for a journeyLine(): the road distance (the furthest stop's km,
     * else routes.distance_km), stop and halt counts, the spans the website
     * prints ("Surat -> Rupaidiha ~ 1,688 km", "Ahmedabad -> Rupaidiha ~
     * 1,372 km") and the journey minutes from the timetable.
     *
     * The journey time is read from dep_time -> arr_time + day_offset and is
     * dropped when it is implausible for the distance (a stale clock from an
     * old onward leg). 'durationConfirmed' is true only when the office has
     * set duration_text (Admin -> Routes "show arrival"): until then a screen
     * may say "approx. N hours" but must not print an arrival clock.
     *
     * @param list<array<string, mixed>> $line
     * @param array<string, mixed>       $route  dep_time (timetable), arr_time, day_offset, duration_text, distance_km
     * @return array<string, mixed>
     */
    public static function journeySummary(array $line, array $route): array
    {
        $stops = array_values(array_filter($line, static fn(array $i): bool => $i['kind'] === 'stop'));
        $first = null;
        $last  = null;
        foreach ($stops as $s) {
            if ($s['km'] === null) {
                continue;
            }
            if ($first === null || $s['km'] < $first['km']) {
                $first = $s;
            }
            if ($last === null || $s['km'] > $last['km']) {
                $last = $s;
            }
        }
        $routeKm  = (int) ($route['distance_km'] ?? 0);
        $fromStop = $first !== null && $last !== null && $last['km'] > $first['km'];
        $distance = $fromStop ? (int) round($last['km'] - $first['km']) : ($routeKm > 0 ? $routeKm : null);

        $spans = [];
        if ($fromStop) {
            $spans[] = ['from' => $first['name'], 'to' => $last['name'], 'km' => (int) round($last['km'] - $first['km'])];
            foreach ($stops as $s) {
                if ($s['code'] === 'AMD' && $s['km'] !== null && $s['km'] > $first['km'] && $s['km'] < $last['km']) {
                    $spans[] = self::isNepalTown((string) $last['label']) || self::isNepalTown((string) $last['name'])
                        ? ['from' => 'Ahmedabad', 'to' => $last['name'], 'km' => (int) round($last['km'] - $s['km'])]
                        : ['from' => $first['name'], 'to' => 'Ahmedabad', 'km' => (int) round($s['km'] - $first['km'])];
                    break;
                }
            }
        }

        $dur = self::durationMin((string) ($route['dep_time'] ?? ''), (string) ($route['arr_time'] ?? ''), (int) ($route['day_offset'] ?? 0));
        if ($dur !== null && $distance !== null) {
            $kmh = $distance / ($dur / 60);
            if ($kmh < 20 || $kmh > 80) {
                $dur = null;
            }
        }
        $board = count(array_filter($stops, static fn(array $s): bool => $s['role'] === 'board'));

        return [
            'distanceKm'        => $distance,
            'distanceSource'    => $fromStop ? 'stops' : ($distance !== null ? 'route' : null),
            'stopCount'         => count($stops),
            'boardCount'        => $board,
            'dropCount'         => count($stops) - $board,
            'haltCount'         => count($line) - count($stops),
            'spans'             => $spans,
            'durationMin'       => $dur,
            'durationConfirmed' => $dur !== null && trim((string) ($route['duration_text'] ?? '')) !== '',
        ];
    }

    /**
     * Road km from the origin for every stop of a route that has a map pin,
     * measured on OUR routing engine (Settings nav_engine_url - Valhalla, bus
     * costing, at most 5 locations per call; consecutive calls share their
     * boundary stop). Computes only - Admin -> Routes stores the answer, on
     * press. A stop without coordinates is skipped and reported, never
     * guessed; the first stop must have a pin (every km counts from it).
     *
     * @param callable|null $engine  fn(list<array{lat:float,lon:float}>): ?list<float> (leg km) - test seam
     * @return array{ok: bool, error: string, rows: array<int, float>, names: array<string, float>, skipped: list<string>, totalKm: ?float, calls: int}
     */
    public static function measureRoadKm(int $routeId, ?callable $engine = null): array
    {
        $res   = ['ok' => false, 'error' => '', 'rows' => [], 'names' => [], 'skipped' => [], 'totalKm' => null, 'calls' => 0];
        $route = Database::fetch('SELECT id, dep_time FROM routes WHERE id = :r', ['r' => $routeId]);
        if ($route === null) {
            $res['error'] = 'Route not found.';
            return $res;
        }
        $line = array_values(array_filter(
            self::journeyLine(self::lineStops($routeId), [], (string) $route['dep_time']),
            static fn(array $i): bool => $i['kind'] === 'stop'
        ));
        if (count($line) < 2) {
            $res['error'] = 'This route needs at least two stops to measure.';
            return $res;
        }
        if ($line[0]['lat'] === null || $line[0]['lng'] === null) {
            $res['error'] = 'The first stop (' . $line[0]['name'] . ') has no map position - add its latitude/longitude first: every km is counted from it.';
            return $res;
        }
        $pts = [];   // [lat, lng, [line indexes]] with consecutive duplicates folded
        foreach ($line as $n => $it) {
            if ($it['lat'] === null || $it['lng'] === null) {
                $res['skipped'][] = $it['name'];
                continue;
            }
            $lp = count($pts) - 1;
            if ($lp >= 0 && abs($pts[$lp][0] - $it['lat']) < 0.00001 && abs($pts[$lp][1] - $it['lng']) < 0.00001) {
                $pts[$lp][2][] = $n;
                continue;
            }
            $pts[] = [(float) $it['lat'], (float) $it['lng'], [$n]];
        }
        if (count($pts) < 2) {
            $res['error'] = 'Only one stop of this route has a map position - add coordinates to at least one more stop.';
            return $res;
        }
        $engine ??= self::valhallaLegEngine();
        $cum = [0.0];
        $i   = 0;
        $np  = count($pts);
        while ($i < $np - 1) {
            $chunk = array_slice($pts, $i, 5);
            $locs  = array_map(static fn(array $p): array => ['lat' => round($p[0], 6), 'lon' => round($p[1], 6)], $chunk);
            $legs  = null;
            try {
                $legs = $engine($locs);
            } catch (Throwable $e) {
                $legs = null;
            }
            $res['calls']++;
            if (!is_array($legs) || count($legs) !== count($locs) - 1) {
                $a = $line[$chunk[0][2][0]]['name'];
                $b = $line[$chunk[count($chunk) - 1][2][0]]['name'];
                $res['error'] = 'The route engine did not answer for ' . $a . ' -> ' . $b . ' (Settings: nav_engine_url). Nothing was changed.';
                return $res;
            }
            foreach (array_values($legs) as $j => $km) {
                $cum[$i + $j + 1] = $cum[$i + $j] + max(0.0, (float) $km);
            }
            $i += count($locs) - 1;
        }
        foreach ($pts as $p => $pt) {
            $km = round($cum[$p], 1);
            foreach ($pt[2] as $n) {
                foreach ($line[$n]['ids'] as $id) {
                    $res['rows'][(int) $id] = $km;
                }
                $res['names'][$line[$n]['name']] = $km;
            }
        }
        $res['totalKm'] = round((float) end($cum), 1);
        $res['ok']      = true;

        return $res;
    }

    /** The default engine for measureRoadKm(): our own Valhalla, bus costing, leg lengths only. */
    private static function valhallaLegEngine(): callable
    {
        $base = rtrim(Settings::getString('nav_engine_url', 'http://127.0.0.1:8002'), '/');

        return static function (array $locs) use ($base): ?array {
            if (!function_exists('curl_init') || preg_match('#^https?://#', $base) !== 1) {
                return null;
            }
            $body = ['locations' => $locs, 'costing' => 'bus', 'directions_type' => 'none',
                     'directions_options' => ['units' => 'kilometers']];
            $ch = curl_init($base . '/route?json=' . rawurlencode((string) json_encode($body)));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'SHG-route-km/1.0']);
            $raw  = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($http !== 200 || !is_string($raw)) {
                return null;
            }
            $j    = json_decode($raw, true);
            $legs = is_array($j) ? ($j['trip']['legs'] ?? null) : null;
            if (!is_array($legs)) {
                return null;
            }

            return array_map(static fn(mixed $l): float => is_array($l) ? (float) ($l['summary']['length'] ?? 0) : 0.0, $legs);
        };
    }
}
