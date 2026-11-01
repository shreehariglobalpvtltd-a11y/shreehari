<?php
/**
 * =====================================================================
 *  BusRoutine — read-only view of the round-trip bus rotation for the
 *  public routine page (owner poster, 31 Oct 2026).
 *
 *  The rotation itself lives in Fleet (Admin → Trips → Bus rotation, mode
 *  round_trip): buses take turns, a departure every `stagger` days, the
 *  same bus leaves the Nepal side `backAfter` days after leaving Gujarat.
 *  This class only RE-STATES that plan in the poster's words — BUS 01/02/03,
 *  Day N, India → Nepal / Nepal → India — using exactly Fleet's arithmetic,
 *  so the page can never show a bus the booking app does not sell.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class BusRoutine
{
    public const DIR_TO_NEPAL = 'IN-NP';
    public const DIR_TO_INDIA = 'NP-IN';

    /** Poster names for the three lines, by rotation slot. */
    private const NAMES = [
        1 => 'Surat – Vadodara – Ahmedabad – Mehsana – Palanpur – Jaipur – Agra – Lucknow – Rupaidiha',
        2 => 'Surat – Vadodara – Ahmedabad – Himatnagar – Jaipur – Agra – Lucknow – Rupaidiha',
        3 => 'Rajkot – Ahmedabad – Godhra – Dahod – Indore – Agra – Lucknow – Rupaidiha',
    ];

    public static function enabled(): bool
    {
        $r = Fleet::rotation();
        return $r['enabled'] && $r['mode'] === 'round_trip' && $r['buses'] !== [];
    }

    public static function anchor(): string
    {
        return Fleet::rotation()['startDate'];
    }

    public static function cycle(): int
    {
        $r = Fleet::rotation();
        return max(1, count($r['buses']) * $r['stagger']);
    }

    public static function dayIndex(string $date): int
    {
        return Fleet::dayIndex($date);
    }

    public static function dayNumber(string $date): int
    {
        return self::dayIndex($date) + 1;
    }

    public static function dirLabel(string $dir): string
    {
        return $dir === self::DIR_TO_NEPAL ? 'India → Nepal' : 'Nepal → India';
    }

    /**
     * Every departure from $from for $days days (one per coach that leaves a
     * terminal that day), straight from Fleet::roundTripPreview().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function departures(string $from, int $days): array
    {
        if (!self::enabled()) {
            return [];
        }
        $rot  = Fleet::rotation();
        $out  = [];
        foreach (Fleet::roundTripPreview(max(1, min(120, $days)), $from) as $p) {
            foreach ([['out', self::DIR_TO_NEPAL], ['back', self::DIR_TO_INDIA]] as [$leg, $dir]) {
                $busId = $p[$leg];
                if ($busId === null) {
                    continue;
                }
                $routeId = (int) ($rot['routes'][$busId][$leg] ?? 0);
                if ($routeId < 0) {
                    continue;   // that slot has no route yet — not sold
                }
                $r = self::route($routeId, $leg);
                // A trip the office switched off (or cancelled) is not shown.
                if (Database::exists(
                    "SELECT 1 FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 1
                        AND (is_blocked = 1 OR status = 'cancelled')",
                    ['r' => (int) ($r['id'] ?? 0), 'd' => $p['date']]
                )) {
                    continue;
                }
                $b = self::bus((int) $busId);
                $slot = (int) $p[$leg === 'out' ? 'outSlot' : 'backSlot'];
                $out[] = [
                    'date'      => $p['date'],
                    'dayNo'     => self::dayNumber($p['date']),
                    'line'      => 'BUS ' . str_pad((string) $slot, 2, '0', STR_PAD_LEFT),
                    'direction' => $dir,
                    'dirLabel'  => self::dirLabel($dir),
                    'routeId'   => (int) ($r['id'] ?? 0),
                    'from'      => (string) ($r['from_city'] ?? ''),
                    'to'        => (string) ($r['to_city'] ?? ''),
                    'depTime'   => isset($r['dep_time']) ? substr((string) $r['dep_time'], 0, 5) : '',
                    'reachDate' => addDaysISO($p['date'], max(1, $rot['backAfter'] - 1)),
                    'busNumber' => Fleet::publicNumber((string) ($b['bus_number'] ?? '')),
                    'busName'   => (string) ($b['bus_name'] ?? ''),
                    'fare'      => $dir === self::DIR_TO_NEPAL
                        ? (float) Settings::get('fare_to_nepal', 2000)
                        : (float) Settings::get('fare_to_india', 1800),
                ];
            }
        }
        return $out;
    }

    /**
     * The rotation's lines: code, poster name, coach, route ids and the
     * poster's "day" columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lines(): array
    {
        if (!self::enabled()) {
            return [];
        }
        $rot   = Fleet::rotation();
        $cycle = self::cycle();
        $out   = [];
        foreach ($rot['buses'] as $k => $busId) {
            $slot   = $k + 1;
            $outPos = $k * $rot['stagger'];
            $retPos = ($outPos + $rot['backAfter']) % $cycle;
            $up     = self::route((int) ($rot['routes'][$busId]['out'] ?? 0), 'out');
            $dn     = self::route((int) ($rot['routes'][$busId]['back'] ?? 0), 'back');
            $b      = self::bus((int) $busId);
            $out[] = [
                'code'       => 'BUS ' . str_pad((string) $slot, 2, '0', STR_PAD_LEFT),
                'name'       => self::NAMES[$slot] ?? trim(($up['from_city'] ?? '') . ' – ' . ($up['to_city'] ?? '')),
                'outRouteId' => (int) ($up['id'] ?? 0),
                'retRouteId' => (int) ($dn['id'] ?? 0),
                'busId'      => (int) $busId,
                'busNumber'  => Fleet::publicNumber((string) ($b['bus_number'] ?? '')),
                'outDepTime' => isset($up['dep_time']) ? substr((string) $up['dep_time'], 0, 5) : '',
                'retDepTime' => isset($dn['dep_time']) ? substr((string) $dn['dep_time'], 0, 5) : '',
                'outDays'    => self::monthDays($outPos + 1, $cycle),
                'retDays'    => self::monthDays($retPos + 1, $cycle),
            ];
        }
        return $out;
    }

    /**
     * Where every coach is on a date — the poster's six columns.
     *
     * @return array<int, array{line: string, stage: int, label: string}>
     */
    public static function positions(string $date): array
    {
        if (!self::enabled()) {
            return [];
        }
        $rot   = Fleet::rotation();
        $cycle = self::cycle();
        $back  = $rot['backAfter'];
        $out   = [];
        foreach ($rot['buses'] as $k => $busId) {
            $s = (self::dayIndex($date) - $k * $rot['stagger']) % $cycle;
            if ($s < 0) { $s += $cycle; }
            $label = match (true) {
                $s === 0             => 'Gujarat depart',
                $s < $back - 1       => 'Outbound road',
                $s === $back - 1     => 'Reach Rupaidiha',
                $s === $back         => 'Nepal return (Rupaidiha depart)',
                $s === $cycle - 1    => 'Reach Gujarat',
                default              => 'Return road',
            };
            $out[] = ['line' => 'BUS ' . str_pad((string) ($k + 1), 2, '0', STR_PAD_LEFT), 'stage' => $s, 'label' => $label];
        }
        return $out;
    }

    /* ----------------------------------------------------------------- */

    /** A route by id; 0 = "any route that way" → the first active one. */
    private static function route(int $id, string $leg): ?array
    {
        static $cache = [];
        $key = $id . $leg;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if ($id > 0) {
            return $cache[$key] = Database::fetch('SELECT id, from_city, to_city, dep_time FROM routes WHERE id = :i', ['i' => $id]);
        }
        require_once INCLUDE_PATH . '/fare.php';
        foreach (Database::fetchAll('SELECT id, from_city, to_city, dep_time FROM routes WHERE is_active = 1 ORDER BY sort_order, id') as $r) {
            if ((Fare::routeDir($r) === 'toNepal') === ($leg === 'out')) {
                return $cache[$key] = $r;
            }
        }
        return $cache[$key] = null;
    }

    private static function bus(int $id): ?array
    {
        static $cache = [];
        return $cache[$id] ??= Database::fetch('SELECT bus_number, bus_name FROM buses WHERE id = :i', ['i' => $id]);
    }

    /** @return array<int, int> */
    private static function monthDays(int $first, int $cycle): array
    {
        $out = [];
        for ($d = $first; $d <= 31; $d += $cycle) {
            $out[] = $d;
        }
        return $out;
    }
}
