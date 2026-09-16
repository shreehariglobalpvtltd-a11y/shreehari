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
        'ahmedabad'    => ['AMD', 'Ahmedabad'],
        'emli'         => ['EMB', null],
        'bhupal'       => ['EMB', null],
        'mehsana'      => ['MSN', 'Mehsana'],
        'surat'        => ['STV', 'Surat'],
        'baroda'       => ['BRC', 'Baroda'],
        'barauda'      => ['BRC', 'Baroda'],
        'vadodara'     => ['BRC', 'Vadodara'],
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
    ];

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
        static $cache = [];
        if (isset($cache[$routeId])) {
            return $cache[$routeId];
        }

        $rows = Database::fetchAll(
            "SELECT stop_name, stop_time, sort_order
               FROM route_stops
              WHERE route_id = :r AND stop_type = 'boarding'
              ORDER BY sort_order ASC",
            ['r' => $routeId]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name'  => (string) $r['stop_name'],
                'time'  => $r['stop_time'] !== null ? (string) $r['stop_time'] : null,
                'order' => (int) $r['sort_order'],
            ];
        }

        return $cache[$routeId] = $out;
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
