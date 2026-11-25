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
 *
 * Switches (all default ON, Admin -> Settings): travel_onward_on (the travel-info card).
 */

declare(strict_types=1);

final class TravelGuide
{
    /** The towns the owner named - names only; times / operator / phone stay empty until the office fills them. */
    public const TOWNS = [
        ['Surkhet', 'सुर्खेत'],
        ['Dang', 'दाङ'],
        ['Butwal', 'बुटवल'],
        ['Pokhara', 'पोखरा'],
        ['Kathmandu', 'काठमाडौं'],
        ['Dhangadhi', 'धनगढी'],
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
