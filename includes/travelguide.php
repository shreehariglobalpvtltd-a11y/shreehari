<?php
/**
 * includes/travelguide.php — passenger extras on one place (25 Nov 2026, wx1-c2).
 *
 *   onward()       the "Onward travel from Nepalgunj" rows of /travel-info, from
 *                  Settings onward_buses_json = [{to,to_ne,times,operator,phone,note}].
 *                  EMPTY by default: no bus time, operator or phone is ever made up
 *                  here - the office types them on admin/owner-setup.php#onward.
 *                  A row with only a town name is not shown (nothing to tell yet).
 *   clean()        the owner form's rows -> what is stored (same rules as onward()).
 *   luggage()      the free luggage rule, luggage_free_kg (20) + cabin_bag_kg (7) -
 *                  the numbers of the published terms (terms-data.js section G).
 *   facilities()   bus_facilities_json, the bus facilities the owner stated.
 *   nepalOfficePhone()  the Nepalgunj office line (nepal_phone, else the Nepal team's
 *                  office row), for "ask the Nepalgunj office" when no row is filled.
 *   nepalBoarding() / boardingLine()  the boarding stops + times of the active
 *                  Nepal -> India routes, for the /travel-info "coming from Nepalgunj" line.
 *
 * Switches (all default ON, Admin -> Settings): travel_onward_on (the travel-info card),
 * travel_npj_only_on (empty card says "our bus goes only to Nepalgunj", 25 Nov 2026).
 */

declare(strict_types=1);

final class TravelGuide
{
    /** The prefilled onward row on the owner form - name only; times / operator / phone stay
     *  empty until the office fills them. 25 Nov 2026 (owner: "our bus is NPJ only;
     *  NPJ-Surkhet may start"): Surkhet alone - no other town is prefilled as if served. */
    public const TOWNS = [
        ['Surkhet', 'सुर्खेत'],
    ];

    public const FACILITIES = [
        'AC स्लिपर · AC sleeper',
        'मोबाइल चार्जिङ · Mobile charging',
        'पिउने पानी · Drinking water',
        'CCTV सुरक्षा · CCTV security',
    ];

    private const MAX = ['to' => 40, 'to_ne' => 40, 'times' => 120, 'operator' => 80, 'phone' => 40, 'note' => 160];

    public static function onwardOn(): bool
    {
        return Settings::getBool('travel_onward_on', true);
    }

    /** No onward row filled: say "our bus ends at Nepalgunj" (ON) or only "ask the office" (OFF). */
    public static function npjOnlyOn(): bool
    {
        return Settings::getBool('travel_npj_only_on', true);
    }

    /** @return list<array{to:string,to_ne:string,times:string,operator:string,phone:string,note:string}> */
    public static function onward(): array
    {
        $raw = Settings::get('onward_buses_json', []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        return self::clean(is_array($raw) ? $raw : []);
    }

    /**
     * Rows as posted (or stored) -> clean rows. A row needs a town and at least one
     * real detail (times, operator, phone or note); everything else is dropped.
     *
     * @return list<array{to:string,to_ne:string,times:string,operator:string,phone:string,note:string}>
     */
    public static function clean(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || count($out) >= 20) {
                continue;
            }
            $row = [];
            foreach (self::MAX as $k => $max) {
                $v = preg_replace('/\s+/u', ' ', Security::clean(is_scalar($r[$k] ?? null) ? (string) $r[$k] : '', $max)) ?? '';
                $row[$k] = trim($v);
            }
            // a phone is digits, +, spaces, / and - only (several numbers split by /)
            $row['phone'] = trim(preg_replace('/[^0-9+\/\s-]/', '', $row['phone']) ?? '');
            if ($row['to'] === '' || ($row['times'] === '' && $row['operator'] === '' && $row['phone'] === '' && $row['note'] === '')) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** "tel:" digits of the first number in a phone field ('' when none). */
    public static function dial(string $phone): string
    {
        $first = trim((string) (preg_split('#\s*/\s*#', $phone)[0] ?? ''));
        $d = preg_replace('/\D/', '', $first) ?? '';
        if (str_starts_with($first, '+')) {
            return strlen($d) >= 8 ? '+' . $d : '';
        }
        if (strlen($d) === 10 && preg_match('/^9[678]/', $d) === 1) {
            return '+977' . $d;       // a Nepali mobile
        }
        return strlen($d) >= 7 ? $d : '';
    }

    /** @return array{free:int,cabin:int} */
    public static function luggage(): array
    {
        $free  = Settings::getInt('luggage_free_kg', 20);
        $cabin = Settings::getInt('cabin_bag_kg', 7);
        return [
            'free'  => $free >= 1 && $free <= 100 ? $free : 20,
            'cabin' => $cabin >= 0 && $cabin <= 50 ? $cabin : 7,
        ];
    }

    /** @return list<string> */
    public static function facilities(): array
    {
        $raw = Settings::get('bus_facilities_json', null);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return self::FACILITIES;
        }
        $out = [];
        foreach ($raw as $f) {
            $f = is_scalar($f) ? trim(Security::clean((string) $f, 60)) : '';
            if ($f !== '' && count($out) < 12) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * Where a Nepal -> India passenger boards (wx1-c2 review): the boarding stops
     * switched ON of every active Nepal -> India route (Boarding::stopsFor), one
     * entry per stop name with its time(s) as HH:MM, earliest first, at most 4.
     * Nothing is typed here: [] when no route / stop is set or the tables cannot
     * be read - the page then says it in general words, never with a made-up time.
     *
     * @return list<array{name:string,times:list<string>}>
     */
    public static function nepalBoarding(): array
    {
        try {
            $routes = Database::fetchAll('SELECT * FROM routes WHERE is_active = 1 ORDER BY id ASC');
        } catch (Throwable) {
            return [];
        }
        $by = [];
        foreach ($routes as $r) {
            if (Boarding::routeDirection($r) !== 'toIndia') {
                continue;
            }
            try {
                $stops = Boarding::stopsFor((int) $r['id']);
            } catch (Throwable) {
                continue;
            }
            foreach ($stops as $s) {
                $name = trim((string) ($s['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $k = mb_strtolower($name);
                $by[$k] ??= ['name' => $name, 'times' => []];
                $t = (string) ($s['time'] ?? '');
                if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d/', $t) === 1 && !in_array(substr($t, 0, 5), $by[$k]['times'], true)) {
                    $by[$k]['times'][] = substr($t, 0, 5);
                }
            }
        }
        $out = array_values($by);
        foreach ($out as $i => &$o) {
            sort($o['times']);
            $o['_i'] = $i;
        }
        unset($o);
        // earliest pickup first (Nepalgunj office before the border); a stop with no time last
        usort($out, static fn(array $a, array $b): int => [$a['times'][0] ?? '99:99', $a['_i']] <=> [$b['times'][0] ?? '99:99', $b['_i']]);
        return array_map(static fn(array $o): array => ['name' => $o['name'], 'times' => $o['times']], array_slice($out, 0, 4));
    }

    /**
     * The /travel-info line from nepalBoarding(): $w[0] when there is no stop,
     * $w[1] with {A} for one stop, $w[2] with {A} + the rest as {B}. Names are
     * escaped; times in Devanagari digits when $dev (the Nepali block).
     *
     * @param list<array{name:string,times:list<string>}> $stops
     * @param array<int,string> $w
     */
    public static function boardingLine(array $stops, array $w, bool $dev = false): string
    {
        $fmt = static function (array $s) use ($dev): string {
            $t = implode(' / ', $s['times']);
            if ($dev) {
                $t = strtr($t, ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९']);
            }
            return '<b>' . htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') . '</b>' . ($t !== '' ? ' (' . $t . ')' : '');
        };
        if ($stops === []) {
            return (string) ($w[0] ?? '');
        }
        $a = $fmt($stops[0]);
        if (count($stops) === 1) {
            return str_replace('{A}', $a, (string) ($w[1] ?? ''));
        }
        return str_replace(['{A}', '{B}'], [$a, implode(', ', array_map($fmt, array_slice($stops, 1)))], (string) ($w[2] ?? ''));
    }

    /** The Nepalgunj office line as the site already shows it ('' when none is saved). */
    public static function nepalOfficePhone(): string
    {
        $np = trim((string) (preg_split('#\s*/\s*#', Settings::getString('nepal_phone', ''))[0] ?? ''));
        if ($np !== '') {
            return $np;
        }
        foreach (Settings::nepalTeam() as $p) {
            if (($p['key'] ?? '') === 'office' && (string) ($p['phone'] ?? '') !== '') {
                return (string) $p['phone'];
            }
        }
        return '';
    }
}
