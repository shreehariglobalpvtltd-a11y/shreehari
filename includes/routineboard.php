<?php
/**
 * =====================================================================
 *  includes/routineboard.php — "कुन दिन कुन बस" (1 Nov 2026).
 *
 *  The Bus Routine board that lived on the site before the idc10 release
 *  (a hand-deployed /routine.php + api/routine.php that were never in git)
 *  came back on this lineage. It reads exactly what the site SELLS: the
 *  unblocked slot-1 schedules of active routes — the same rows
 *  api/timetable.php and the booking engine use — so the board can never
 *  show a bus that cannot be booked, or miss one that can.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class RoutineBoard
{
    public const MAX_DAYS = 30;

    private function __construct() {}

    /**
     * The next $days days of departures, oldest first.
     *
     * @return list<array{date: string, dep: string, from: string, to: string, bus: string, toNepal: bool, book: string}>
     */
    public static function departures(int $days = 14): array
    {
        $days  = max(1, min(self::MAX_DAYS, $days));
        $today = todayISO();
        $rows  = Database::fetchAll(
            'SELECT s.travel_date, COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                    r.from_city, r.to_city, b.bus_number
               FROM schedules s
               JOIN routes r     ON r.id = s.route_id
               LEFT JOIN buses b ON b.id = s.bus_id
              WHERE s.travel_date BETWEEN :a AND :b
                AND s.slot = 1 AND COALESCE(s.is_blocked, 0) = 0 AND r.is_active = 1
              ORDER BY s.travel_date ASC, dep_time ASC, r.id ASC',
            ['a' => $today, 'b' => addDaysISO($today, $days - 1)]
        );

        $out = [];
        foreach ($rows as $r) {
            $toNepal = self::toNepal((string) $r['to_city']);
            $bus = trim((string) ($r['bus_number'] ?? ''));
            $d = [
                'date'    => (string) $r['travel_date'],
                'dep'     => substr((string) $r['dep_time'], 0, 5),
                'from'    => (string) $r['from_city'],
                'to'      => (string) $r['to_city'],
                'bus'     => preg_match('/^X{2,}/i', $bus) === 1 ? '' : $bus,   // "XXXX-02" = plate not yet given
                'toNepal' => $toNepal,
            ];
            $d['book'] = self::bookUrl($d);
            $out[] = $d;
        }
        return $out;
    }

    /** The Nepal side of the border is the far end of every "going" run. */
    public static function toNepal(string $toCity): bool
    {
        return preg_match('/rupaidiha|rupaidhiya|nepalgunj|jamunaha|nepal/i', $toCity) === 1;
    }

    /**
     * The home page's own deep link (05-router.js applyCampaignLink): direction, the Gujarat town of
     * the run (its pickup going to Nepal, its drop coming back — the one town box), and the date.
     */
    public static function bookUrl(array $d): string
    {
        return '/?' . http_build_query([
            'dir'  => !empty($d['toNepal']) ? 'go' : 'back',
            'town' => mb_strtolower((string) (!empty($d['toNepal']) ? $d['from'] : $d['to'])),
            'date' => (string) $d['date'],
            'src'  => 'routine',
        ]) . '#/';
    }
}
