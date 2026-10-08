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

    /**
     * Forget the per-request caches. Admin edits call this so the page that
     * saved a change renders the new state; tests call it between steps.
     */
    public static function flush(): void
    {
        self::$stopCache = [];
        self::$locCache  = null;
        self::$hasControls = null;
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
     * @return int unix time, or 0 when the date or every time is unusable
     */
    public static function pickupTs(string $travelDate, string $boardingStop, ?string $boardingTime, string $depTime): int
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
        $dep  = $hhmm($depTime);
        $pick = $hhmm($boardingTime);
        if ($pick === '') {
            $pick = $hhmm((string) (self::stopDisplay($boardingStop)['time'] ?? ''));
        }
        if ($pick === '') {
            $pick = $dep;
        }
        if ($pick === '') {
            return 0;
        }
        $ts = strtotime($travelDate . ' ' . $pick);
        if ($ts === false) {
            return 0;
        }
        if ($dep !== '') {
            $toMin = static fn(string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
            if ($toMin($pick) < $toMin($dep) - 60) {
                $ts = (int) strtotime('+1 day', $ts);   // past midnight on the same run
            }
        }
        return (int) $ts;
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
}
