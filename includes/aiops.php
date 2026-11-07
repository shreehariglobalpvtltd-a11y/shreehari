<?php
/**
 * =====================================================================
 *  includes/aiops.php — S Hari AI: say it, the app changes it (6 Nov 2026)
 *
 *  Owner: "S Hari AI lai sapai setting ko har ek kuna tha hos, jun bhasa ma
 *  j bhane ni bujos ra edit gardeos — bus ko bhada change garne, route
 *  change garne, bus number rakhne — AI le ni garna milos, manually ni."
 *
 *  ONE way in for a typed / spoken instruction (Nepali, Hindi, English,
 *  roman or Devanagari):
 *
 *     parse()    words first (no AI needed for the everyday sentences),
 *                then the VPS model (ApAi) or the cloud brain (AiBrain),
 *                which may ONLY answer with the action types below;
 *     preview()  old → new for every row the change would touch;
 *     apply()    only after the office says "Yes", each action through the
 *                same writer the manual screen uses (Fleet::updateBus,
 *                AgentWallet::setCommissionOverride, Settings::set …),
 *                each audited.
 *
 *  Action types:
 *     route        {route_ids, on}            routes.is_active
 *     stop_fare    {stop, fare}               route_stops.fare (Nepal-bound pickups; '*' = all)
 *     dir_fare     {dir toNepal|toIndia, fare} the direction default fare
 *     trip_fare    {date, fare|null}          schedules.fare_override for that date
 *     trip_sale    {date, on}                 schedules.is_blocked for that date
 *     bus_number   {bus_id, value}            buses.bus_number (tickets redraw)
 *     bus_name     {bus_id, value}            buses.bus_name
 *     extra_seats  {n}                        extra_seats_per_bus
 *     commission   {agent_id, value}          ₹ per passenger for one agent
 *     setting      {key, value}               any plain (non-secret) setting
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiOps
{
    public const TYPES = ['route', 'stop_fare', 'dir_fare', 'trip_fare', 'trip_sale', 'bus_number', 'bus_name', 'extra_seats', 'commission', 'setting'];

    private const ON  = ['on', 'chalu', 'chalau', 'chalaideu', 'chalaidinu', 'khol', 'kholdeu', 'kholdinu', 'enable', 'start', 'activate', 'open', 'suru', 'shuru',
                         'चालु', 'खोल', 'चलाउ', 'सुरु', 'अन', 'शुरू', 'चालू', 'खोलो', 'chalu gara', 'चलाऊ'];
    private const OFF = ['off', 'band', 'banda', 'bandh', 'bnd', 'disable', 'stop', 'roka', 'rok', 'rokdeu', 'hatau', 'close', 'deactivate',
                         'बन्द', 'बंद', 'रोक', 'हटाउ', 'अफ', 'रोको', 'hatao', 'nachalau', 'na chalau'];

    /** Devanagari / Hindi spellings of the places the routes use. */
    private const CITY_ALIAS = [
        'सुरत' => 'surat', 'रुपैडिहा' => 'rupaidiha', 'रुपैडीहा' => 'rupaidiha', 'रूपैडिहा' => 'rupaidiha', 'रुपईडीहा' => 'rupaidiha', 'रुपैदिहा' => 'rupaidiha',
        'rupaidiya' => 'rupaidiha', 'rupedia' => 'rupaidiha', 'rupadiha' => 'rupaidiha', 'rupaidia' => 'rupaidiha', 'rupdia' => 'rupaidiha',
        'राजकोट' => 'rajkot', 'अहमदाबाद' => 'ahmedabad', 'अहमदावाद' => 'ahmedabad', 'नेपालगञ्ज' => 'nepalgunj', 'नेपालगंज' => 'nepalgunj',
        'मेहसाना' => 'mehsana', 'वडोदरा' => 'vadodara', 'बडोदा' => 'vadodara', 'baroda' => 'vadodara', 'भरुच' => 'bharuch', 'आनन्द' => 'anand',
        'नडियाद' => 'nadiad', 'कामरेज' => 'kamrej', 'अंकलेश्वर' => 'ankleshwar', 'गोधरा' => 'godhra', 'हिम्मतनगर' => 'himatnagar', 'पालनपुर' => 'palanpur',
        'amdavad' => 'ahmedabad', 'ahmdabad' => 'ahmedabad',
    ];

    /* =================================================================
     *  Parsing
     * ================================================================= */

    /**
     * @return array{actions: list<array<string,mixed>>, via: string, note: string}
     */
    public static function parse(string $text, bool $useAi = true): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['actions' => [], 'via' => '', 'note' => ''];
        }
        $acts = self::parseWords($text);
        if ($acts !== []) {
            return ['actions' => $acts, 'via' => 'words', 'note' => ''];
        }
        if ($useAi) {
            $acts = self::parseAi($text);
            if ($acts !== []) {
                return ['actions' => $acts, 'via' => 'AI', 'note' => ''];
            }
        }
        return ['actions' => [], 'via' => '', 'note' => ''];
    }

    public static function norm(string $text): string
    {
        $t = strtr($text, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9', '₹' => ' ', ',' => ' ']);
        $t = mb_strtolower((string) preg_replace('/\s+/u', ' ', $t));
        foreach (self::CITY_ALIAS as $from => $to) {
            $t = str_replace(mb_strtolower($from), $to, $t);
        }
        return ' ' . trim($t) . ' ';
    }

    /** @param list<string> $words */
    private static function hasWord(string $t, array $words): int
    {
        $best = -1;
        foreach ($words as $w) {
            $w = mb_strtolower(trim($w));
            if ($w === '') {
                continue;
            }
            if (preg_match('/^[a-z .]+$/', $w) === 1) {
                if (preg_match_all('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/', $t, $mm, PREG_OFFSET_CAPTURE) > 0) {
                    $best = max($best, (int) end($mm[0])[1]);
                }
            } elseif (($p = strrpos($t, $w)) !== false) {
                $best = max($best, (int) $p);
            }
        }
        return $best;
    }

    /** null = no on/off word; true / false = the verb said LAST. */
    private static function onOff(string $t): ?bool
    {
        $on  = self::hasWord($t, self::ON);
        $off = self::hasWord($t, self::OFF);
        if ($on < 0 && $off < 0) {
            return null;
        }
        return $on > $off;
    }

    /** A travel date in the sentence: aaja / bholi / parsi / "9 nov" / 2026-11-09 / 9/11. */
    public static function findDate(string $t): ?string
    {
        $today = todayISO();
        if (preg_match('/(?<![a-z])(aaja|aaj|today|आज)(?![a-z])/u', $t) === 1) {
            return $today;
        }
        if (preg_match('/(?<![a-z])(bholi|bholi|kal|tomorrow|भोलि|भोली|कल)(?![a-z])/u', $t) === 1) {
            return date('Y-m-d', strtotime($today . ' +1 day'));
        }
        if (preg_match('/(?<![a-z])(parsi|parso|parson|पर्सि|पर्सी|परसों)(?![a-z])/u', $t) === 1) {
            return date('Y-m-d', strtotime($today . ' +2 days'));
        }
        if (preg_match('/(20\d\d)-(\d\d)-(\d\d)/', $t, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $m[0];
        }
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
                   'जनवरी' => 1, 'फरवरी' => 2, 'मार्च' => 3, 'अप्रैल' => 4, 'मई' => 5, 'जून' => 6, 'जुलाई' => 7, 'अगस्त' => 8, 'सितम्बर' => 9, 'अक्टूबर' => 10, 'नवम्बर' => 11, 'नोभेम्बर' => 11, 'दिसम्बर' => 12, 'डिसेम्बर' => 12];
        $mk = static function (int $d, int $mo) use ($today): ?string {
            $y = (int) substr($today, 0, 4);
            if (!checkdate($mo, $d, $y)) {
                return null;
            }
            $iso = sprintf('%04d-%02d-%02d', $y, $mo, $d);
            if ($iso < date('Y-m-d', strtotime($today . ' -60 days'))) {   // "5 jan" said in December = next year
                $iso = sprintf('%04d-%02d-%02d', $y + 1, $mo, $d);
            }
            return $iso;
        };
        foreach ($months as $name => $mo) {
            $n = preg_quote((string) $name, '/');
            if (preg_match('/(?<!\d)(\d{1,2})\s*(?:st|nd|rd|th|tarik|गते)?\s*' . $n . '[a-z]*/u', $t, $m) === 1) {
                return $mk((int) $m[1], $mo);
            }
            if (preg_match('/' . $n . '[a-z]*\s*(\d{1,2})(?!\d)/u', $t, $m) === 1) {
                return $mk((int) $m[1], $mo);
            }
        }
        if (preg_match('/(?<![\d.])(\d{1,2})\/(\d{1,2})(?![\d\/])/', $t, $m) === 1) {
            return $mk((int) $m[1], (int) $m[2]);
        }
        if (preg_match('/(?<!\d)(\d{1,2})\s*(?:tarik|tarikh|गते|तारिख)/u', $t, $m) === 1) {
            $d   = (int) $m[1];
            $iso = $mk($d, (int) substr($today, 5, 2));
            if ($iso !== null && $iso < $today) {
                $iso = $mk($d, (int) date('n', strtotime(substr($today, 0, 8) . '01 +1 month')));
            }
            return $iso;
        }
        return null;
    }

    /** @return list<int> numbers in the sentence (not part of a date or plate) */
    private static function numbers(string $t): array
    {
        $t = (string) preg_replace('/20\d\d-\d\d-\d\d/', ' ', $t);
        $t = (string) preg_replace('/\b[a-z]{2}[ -]?\d{1,2}[ -]?[a-z]{0,3}[ -]?\d{3,4}\b/', ' ', $t);   // a plate
        preg_match_all('/(?<![\d.])(\d{1,6})(?![\d.])/', $t, $m);
        return array_map('intval', $m[1]);
    }

    /** @return list<array<string,mixed>> */
    public static function routes(): array
    {
        static $r = null;
        return $r ??= Database::fetchAll('SELECT id, route_code, from_city, to_city, is_active, bus_id FROM routes ORDER BY is_active DESC, sort_order, id');
    }

    /** @return list<array<string,mixed>> */
    public static function buses(): array
    {
        static $b = null;
        return $b ??= Database::fetchAll('SELECT id, bus_number, bus_name, is_active FROM buses ORDER BY id');
    }

    /** Which bus "Bus 02" / "bus 2" / a plate / a name means. */
    private static function findBus(string $t): ?array
    {
        $buses = self::buses();
        if (preg_match('/(?:bus|बस|gadi|gaadi|गाडी)\s*(?:no\.?|number|नम्बर|नंबर)?\s*0?(\d{1,2})(?!\d)/u', $t, $m) === 1) {
            $n = (int) $m[1];
            foreach ($buses as $b) {
                if (preg_match('/^bus\s*0?' . $n . '$/i', trim((string) $b['bus_name'])) === 1) {
                    return $b;
                }
            }
            $rot = Fleet::rotation()['buses'] ?? [];
            if (isset($rot[$n - 1])) {
                foreach ($buses as $b) {
                    if ((int) $b['id'] === (int) $rot[$n - 1]) {
                        return $b;
                    }
                }
            }
        }
        foreach ($buses as $b) {
            $num = mb_strtolower((string) $b['bus_number']);
            $nam = mb_strtolower(trim((string) $b['bus_name']));
            if (($num !== '' && strlen($num) >= 6 && str_contains(str_replace([' ', '-'], '', $t), str_replace([' ', '-'], '', $num)))
                || ($nam !== '' && mb_strlen($nam) >= 5 && !preg_match('/^bus\s*\d+$/', $nam) && str_contains($t, $nam))) {
                return $b;
            }
        }
        return null;
    }

    /** Cities named in the sentence, in the order they are said. @return list<string> lower-case */
    private static function findCities(string $t): array
    {
        $cities = [];
        foreach (self::routes() as $r) {
            foreach ([(string) $r['from_city'], (string) $r['to_city']] as $c) {
                $c = mb_strtolower(trim($c));
                if ($c !== '') {
                    $cities[$c] = true;
                }
            }
        }
        $hits = [];
        foreach (array_keys($cities) as $c) {
            $p = mb_strpos($t, $c);
            if ($p !== false) {
                $hits[$c] = $p;
            }
        }
        asort($hits);
        return array_keys($hits);
    }

    /** Pickup / drop stop names said in the sentence. @return list<string> */
    private static function findStops(string $t): array
    {
        $out = [];
        foreach (Database::fetchAll(
            "SELECT DISTINCT rs.stop_name FROM route_stops rs JOIN routes r ON r.id = rs.route_id
              WHERE r.is_active = 1 AND rs.stop_type = 'boarding' AND rs.stop_name <> ''"
        ) as $s) {
            $name = (string) $s['stop_name'];
            $key  = mb_strtolower(trim((string) preg_replace('/[,(].*$/u', '', $name)));
            if ($key !== '' && mb_strlen($key) >= 4 && mb_strpos($t, $key) !== false) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function routesFor(array $cities, bool $activeOnly = false): array
    {
        $out = [];
        foreach (self::routes() as $r) {
            if ($activeOnly && (int) $r['is_active'] !== 1) {
                continue;
            }
            $f = mb_strtolower((string) $r['from_city']);
            $to = mb_strtolower((string) $r['to_city']);
            if (count($cities) >= 2) {
                if ($f === $cities[0] && $to === $cities[1]) {
                    $out[] = $r;
                }
            } elseif (count($cities) === 1 && ($f === $cities[0] || $to === $cities[0])) {
                $out[] = $r;
            }
        }
        return $out;
    }

    private static function findAgent(string $raw, string $t): ?array
    {
        require_once __DIR__ . '/agentwallet.php';
        if (preg_match('/shg[\s\-]*(\d{1,4})/i', $raw, $m) === 1) {
            $id = AgentWallet::resolveAgentCodeFromString('SHG' . $m[1]);
            if ($id !== null) {
                return Database::fetch('SELECT id, full_name, username, phone FROM admins WHERE id = :i', ['i' => $id]);
            }
        }
        if (preg_match('/(?<!\d)(\d{10})(?!\d)/', $raw, $m) === 1) {
            $row = Database::fetch("SELECT id, full_name, username, phone FROM admins WHERE role = 'agent' AND is_active = 1 AND REPLACE(phone, ' ', '') LIKE :p LIMIT 1", ['p' => '%' . $m[1]]);
            if ($row !== null) {
                return $row;
            }
        }
        $best = null;
        foreach (Database::fetchAll("SELECT id, full_name, username, phone FROM admins WHERE role = 'agent' AND is_active = 1") as $a) {
            $first = mb_strtolower(trim((string) (explode(' ', trim((string) $a['full_name']))[0] ?? '')));
            $full  = mb_strtolower(trim((string) $a['full_name']));
            if ($full !== '' && mb_strlen($full) >= 4 && mb_strpos($t, $full) !== false) {
                return $a;
            }
            if ($first !== '' && mb_strlen($first) >= 3 && preg_match('/(?<![a-z\p{L}])' . preg_quote($first, '/') . '(?![a-z\p{L}])/u', $t) === 1) {
                $best = $best === null ? $a : false;   // two agents share the first name → ambiguous
            }
        }
        return is_array($best) ? $best : null;
    }

    /**
     * The everyday sentences, understood without any AI.
     *
     * @return list<array<string,mixed>>
     */
    public static function parseWords(string $raw): array
    {
        $t    = self::norm($raw);
        $nums = self::numbers($t);
        $date = self::findDate($t);
        $oo   = self::onOff($t);
        $acts = [];

        $isFare  = self::hasWord($t, ['bhada', 'bhaada', 'bhara', 'fare', 'price', 'rate', 'kiraya', 'kiraaya', 'daam', 'dam', 'ticket price', 'भाडा', 'भाड़ा', 'किराया', 'दाम', 'मूल्य']) >= 0;
        $isComm  = self::hasWord($t, ['commission', 'comission', 'kamisan', 'kamishan', 'commision', 'कमिसन', 'कमीशन', 'कमिशन']) >= 0;
        $isExtra = self::hasWord($t, ['extra seat', 'extra seats', 'extra', 'ex seat', 'थप सिट', 'एक्स्ट्रा', 'atirikt', 'अतिरिक्त']) >= 0;
        $isFine  = self::hasWord($t, ['fine', 'jariwana', 'jarimana', 'penalty', 'cancel charge', 'जरिवाना', 'जुर्माना', 'दण्ड', 'जरिमाना']) >= 0;
        $isNum   = self::hasWord($t, ['number', 'no.', 'plate', 'gadi no', 'नम्बर', 'नंबर', 'नं']) >= 0;
        $isRoute = self::hasWord($t, ['route', 'routes', 'rut', 'root', 'रुट', 'रूट', 'line', 'लाइन']) >= 0;
        $isOnly  = self::hasWord($t, ['only', 'matra', 'matrai', 'sirf', 'keval', 'मात्र', 'सिर्फ', 'केवल']) >= 0;
        $isName  = self::hasWord($t, ['naam', 'name', 'नाम']) >= 0;

        /* 1. commission for one agent: "Ram ko commission 250", "SHG-0027 commission 300" */
        if ($isComm && $nums !== []) {
            $agent = self::findAgent($raw, $t);
            $val   = (float) max($nums);
            if ($agent !== null && $val >= 0 && $val <= 10000) {
                return [['type' => 'commission', 'agent_id' => (int) $agent['id'], 'value' => $val]];
            }
        }

        /* 2. extra seats per bus: "10 extra seat", "extra seat band" */
        if ($isExtra && !$isFare) {
            if ($nums !== []) {
                return [['type' => 'extra_seats', 'n' => max(0, min(40, $nums[0]))]];
            }
            if ($oo === false) {
                return [['type' => 'extra_seats', 'n' => 0]];
            }
        }

        /* 3. cancel fine: "cancel fine 500", "fine band" */
        if ($isFine) {
            if ($nums !== []) {
                $acts[] = ['type' => 'setting', 'key' => 'cancel_fine_default', 'value' => (string) max(0, min(100000, $nums[0]))];
                if (Settings::getBool('cancel_fine_on', true) === false) {
                    $acts[] = ['type' => 'setting', 'key' => 'cancel_fine_on', 'value' => '1'];
                }
                return $acts;
            }
            if ($oo !== null) {
                return [['type' => 'setting', 'key' => 'cancel_fine_on', 'value' => $oo ? '1' : '0']];
            }
        }

        /* 4. bus number / name: "Bus 02 ko number GJ05AB1234 rakha" */
        $bus = self::findBus($t);
        if ($bus !== null && $isNum && preg_match('/\b([A-Za-z]{2}[ -]?\d{1,2}[ -]?[A-Za-z]{0,3}[ -]?\d{3,4})\b/', $raw, $pm) === 1) {
            $plate = strtoupper((string) preg_replace('/[ -]+/', '', $pm[1]));
            if ($plate !== strtoupper(str_replace([' ', '-'], '', (string) $bus['bus_number']))) {
                return [['type' => 'bus_number', 'bus_id' => (int) $bus['id'], 'value' => $plate]];
            }
        }
        if ($bus !== null && $isName && preg_match('/["“\'](.{3,60})["”\']/u', $raw, $nm) === 1) {
            return [['type' => 'bus_name', 'bus_id' => (int) $bus['id'], 'value' => trim($nm[1])]];
        }

        /* 5. fares */
        if ($isFare && $nums !== []) {
            $fare = (float) max($nums);
            if ($fare >= 100 && $fare <= 20000) {
                if ($date !== null) {
                    return [['type' => 'trip_fare', 'date' => $date, 'fare' => $fare]];
                }
                $all    = self::hasWord($t, ['sabai', 'sab', 'sabko', 'all', 'every', 'sabbai', 'सबै', 'सब', 'सबको']) >= 0;
                $back   = self::hasWord($t, ['aaune', 'aune', 'return', 'wapas', 'farkane', 'india tira', 'फर्कने', 'आउने', 'वापसी']) >= 0;
                $cities = self::findCities($t);
                if (!$back && $cities !== [] && Fare::isNepalPoint(mb_convert_case($cities[0], MB_CASE_TITLE))) {
                    $back = true;   // "Rupaidiha Surat bhada" = coming back
                }
                if ($back) {
                    return [['type' => 'dir_fare', 'dir' => 'toIndia', 'fare' => $fare]];
                }
                $stops = self::findStops($t);
                if ($stops !== [] && !$all) {
                    foreach ($stops as $s) {
                        $acts[] = ['type' => 'stop_fare', 'stop' => $s, 'fare' => $fare];
                    }
                    return $acts;
                }
                if ($all || $cities !== [] || self::hasWord($t, ['jane', 'nepal', 'नेपाल', 'जाने']) >= 0) {
                    return [['type' => 'stop_fare', 'stop' => '*', 'fare' => $fare], ['type' => 'dir_fare', 'dir' => 'toNepal', 'fare' => $fare]];
                }
            }
        }
        if ($isFare && $date !== null && self::hasWord($t, ['normal', 'pahile', 'pahilekai', 'clear', 'hatau', 'remove', 'पहिले', 'सामान्य']) >= 0) {
            return [['type' => 'trip_fare', 'date' => $date, 'fare' => null]];
        }

        /* 6. routes on / off: "Rajkot route band", "Rupaidiha Surat route on", "Surat Rupaidiha matra" */
        $cities = self::findCities($t);
        if (($isRoute || $isOnly) && $cities !== []) {
            if ($isOnly) {
                $keep = self::routesFor($cities);
                if ($keep !== []) {
                    $keepIds = array_map(static fn(array $r): int => (int) $r['id'], $keep);
                    $offIds  = [];
                    foreach (self::routes() as $r) {
                        if ((int) $r['is_active'] === 1 && !in_array((int) $r['id'], $keepIds, true)) {
                            $offIds[] = (int) $r['id'];
                        }
                    }
                    $acts[] = ['type' => 'route', 'route_ids' => $keepIds, 'on' => true];
                    if ($offIds !== []) {
                        $acts[] = ['type' => 'route', 'route_ids' => $offIds, 'on' => false];
                    }
                    return $acts;
                }
            } elseif ($oo !== null) {
                $rs = self::routesFor($cities);
                if ($rs !== []) {
                    return [['type' => 'route', 'route_ids' => array_map(static fn(array $r): int => (int) $r['id'], $rs), 'on' => $oo]];
                }
            }
        }

        /* 7. a date's sale on / off: "9 Nov ko bus band", "bholi ko bus chalau" */
        if ($date !== null && $oo !== null && !$isRoute) {
            $a = ['type' => 'trip_sale', 'date' => $date, 'on' => $oo];
            if ($cities !== []) {
                $a['route_ids'] = array_map(static fn(array $r): int => (int) $r['id'], self::routesFor($cities, true));
            }
            return [$a];
        }

        /* 8. a setting named by its own key: "seat_hold_minutes 20" */
        if (preg_match('/(?<![a-z_])([a-z][a-z0-9]+(?:_[a-z0-9]+)+)(?![a-z_])/', $t, $km) === 1) {
            $row = self::settingRow($km[1]);
            if ($row !== null) {
                $v = self::valueFor($row, $t, $nums, $oo);
                if ($v !== null) {
                    return [['type' => 'setting', 'key' => $km[1], 'value' => $v]];
                }
            }
        }
        return [];
    }

    /* =================================================================
     *  Settings — every plain (non-secret) one is reachable
     * ================================================================= */

    /** @return array<string,mixed>|null */
    public static function settingRow(string $key): ?array
    {
        if (!self::settingEditable($key)) {
            return null;
        }
        return Database::fetch("SELECT skey, svalue, stype, sgroup, is_public, label FROM settings WHERE skey = :k AND stype IN ('bool','int','float','string') LIMIT 1", ['k' => $key]);
    }

    /** Switches the app reads with a code default even before a row exists (easy-settings registers its own too). */
    private const BUILTIN = [
        'cancel_fine_on'      => ['💸 Cancel गर्दा fine लगाउने', 'bool', [], 'booking'],
        'cancel_fine_default' => ['💸 Cancel fine (₹, default)', 'float', [], 'booking'],
        'chalani_public_on'   => ['🔗 Public chalani link (login बिना)', 'bool', [], 'chalani'],
        'staff_door_only'     => ['🔐 Agent / staff only on .network', 'bool', [], 'general'],
    ];
    /** @var array<string, array{0:string,1:string,2:array,3:string,4:?string}> */
    private static array $extra = [];

    /**
     * Extra keys the caller knows (label, type bool|enum|int|float|string,
     * enum options, current value shown).
     *
     * @param array<string, array{0:string,1:string,2?:array,3?:string}> $keys
     */
    public static function registerKeys(array $keys): void
    {
        foreach ($keys as $k => $d) {
            self::$extra[(string) $k] = [(string) $d[0], (string) $d[1], (array) ($d[2] ?? []), 'general', isset($d[3]) ? (string) $d[3] : null];
        }
    }

    /**
     * Everything needed to show and save one plain setting.
     *
     * @return array{key:string,label:string,type:string,options:array,current:string,group:string,public:bool}|null
     */
    public static function keyInfo(string $key): ?array
    {
        $row = self::settingRow($key);
        $ex  = self::$extra[$key] ?? null;
        if ($ex === null && isset(self::BUILTIN[$key])) {
            $b  = self::BUILTIN[$key];
            $ex = [$b[0], $b[1], $b[2], $b[3], null];
        }
        if ($row === null && $ex === null) {
            return null;
        }
        $type = $ex !== null ? $ex[1] : (string) $row['stype'];
        if ($ex !== null && $ex[4] !== null) {
            $cur = $ex[4];
        } elseif ($row !== null) {
            $cur = (string) $row['svalue'];
        } elseif (in_array($key, ['cancel_fine_on', 'chalani_public_on', 'staff_door_only'], true)) {
            $cur = Settings::getBool($key, true) ? '1' : '0';
        } else {
            $cur = (string) Settings::get($key, '');
        }
        return [
            'key'     => $key,
            'label'   => $ex !== null ? $ex[0] : (((string) ($row['label'] ?? '')) !== '' ? (string) $row['label'] : $key),
            'type'    => $type,
            'options' => $ex !== null ? $ex[2] : [],
            'current' => $cur,
            'group'   => $row !== null ? (string) $row['sgroup'] : (string) ($ex[3] ?? 'general'),
            'public'  => ($row !== null && (int) $row['is_public'] === 1) || $key === 'seat_pick_manual_first',
        ];
    }

    public static function settingEditable(string $key): bool
    {
        if ($key === '' || preg_match('/^[a-z0-9_]{2,80}$/', $key) !== 1) {
            return false;
        }
        if (Settings::isSecretKey($key) || preg_match('/(key|secret|token|pass|pin_|otp|webhook|app_id|phone_id|account|salt|hash|cookie|session|smtp|api)/', $key) === 1) {
            return false;
        }
        return !in_array($key, ['maintenance_mode', 'login_one_door_on', 'company_cin', 'company_legal'], true);
    }

    /** @param list<int> $nums */
    private static function valueFor(array $row, string $t, array $nums, ?bool $oo): ?string
    {
        return match ((string) $row['stype']) {
            'bool'  => $oo === null ? null : ($oo ? '1' : '0'),
            'int'   => $nums !== [] ? (string) $nums[count($nums) - 1] : null,
            'float' => $nums !== [] ? (string) $nums[count($nums) - 1] : null,
            default => null,   // free text is left to the AI path / the settings page
        };
    }

    /**
     * The settings most likely meant — key / label words shared with the
     * sentence — so a small local model gets a short, relevant list.
     *
     * @return list<array<string,mixed>>
     */
    public static function settingCatalog(string $t, int $max = 24): array
    {
        $words = array_filter(preg_split('/[^a-z0-9\p{L}]+/u', mb_strtolower($t)) ?: [], static fn(string $w): bool => mb_strlen($w) >= 3);
        $scored = [];
        foreach (Database::fetchAll("SELECT skey, svalue, stype, sgroup, label FROM settings WHERE stype IN ('bool','int','float','string')") as $r) {
            if (!self::settingEditable((string) $r['skey'])) {
                continue;
            }
            $hay = mb_strtolower(str_replace('_', ' ', (string) $r['skey']) . ' ' . (string) $r['label'] . ' ' . (string) $r['sgroup']);
            $s = 0;
            foreach ($words as $w) {
                if (mb_strpos($hay, $w) !== false) {
                    $s++;
                }
            }
            if ($s > 0) {
                $scored[] = [$s, $r];
            }
        }
        usort($scored, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        return array_map(static fn(array $x): array => $x[1], array_slice($scored, 0, $max));
    }

    /* =================================================================
     *  The AI path — only for what the words missed
     * ================================================================= */

    /** @return list<array<string,mixed>> */
    public static function parseAi(string $text): array
    {
        $t = self::norm($text);
        $routes = [];
        foreach (self::routes() as $r) {
            $routes[] = $r['id'] . ': ' . $r['from_city'] . ' -> ' . $r['to_city'] . ((int) $r['is_active'] === 1 ? ' (ON)' : ' (OFF)');
        }
        $buses = [];
        $rot = Fleet::rotation()['buses'] ?? [];
        foreach (self::buses() as $b) {
            $slot = array_search((int) $b['id'], array_map('intval', $rot), true);
            $buses[] = $b['id'] . ': ' . $b['bus_name'] . ' number ' . $b['bus_number'] . ($slot !== false ? ' (= Bus 0' . ($slot + 1) . ')' : '');
        }
        $sets = [];
        foreach (array_merge(array_keys(self::BUILTIN), array_keys(self::$extra)) as $k) {
            $i = self::keyInfo((string) $k);
            if ($i !== null) {
                $sets[] = $k . ' (' . $i['type'] . ($i['options'] !== [] ? ' one of ' . implode('|', array_keys($i['options'])) : '') . ', now ' . $i['current'] . ', ' . $i['label'] . ')';
            }
        }
        foreach (self::settingCatalog($t) as $r) {
            $sets[] = $r['skey'] . ' (' . $r['stype'] . ', now ' . mb_substr((string) $r['svalue'], 0, 30) . ($r['label'] ? ', ' . $r['label'] : '') . ')';
        }
        $sys = "You turn a bus company manager's instruction (any language: Nepali, Hindi, English, romanised or Devanagari) into a list of changes.\n"
             . "Today is " . todayISO() . ".\n"
             . "Action types (use ONLY these):\n"
             . "- route {route_ids:[ids], on:true|false}  switch routes on/off\n"
             . "- stop_fare {stop:'<pickup stop name>' or '*', fare:number}  per-seat fare of a pickup going to Nepal\n"
             . "- dir_fare {dir:'toNepal'|'toIndia', fare:number}  default fare of a direction\n"
             . "- trip_fare {date:'YYYY-MM-DD', fare:number or null}  one date's own fare (null = normal)\n"
             . "- trip_sale {date:'YYYY-MM-DD', on:true|false}  open / stop sale for a date\n"
             . "- bus_number {bus_id:id, value:'PLATE'}\n- bus_name {bus_id:id, value:'name'}\n"
             . "- extra_seats {n:0-40}  extra seats on every bus\n"
             . "- commission {agent:'name, SHG code or mobile', value:rupees per passenger}\n"
             . "- setting {key:'<key from the list>', value:'...'}\n"
             . "Routes:\n- " . implode("\n- ", $routes) . "\n"
             . "Buses:\n- " . implode("\n- ", $buses) . "\n"
             . ($sets !== [] ? "Settings that may be meant:\n- " . implode("\n- ", $sets) . "\n" : '')
             . 'Reply JSON {"actions":[{"type":"...", ...}]}. Use an empty list when the instruction is unclear. Never invent ids or keys.';
        $schema = ['type' => 'object', 'properties' => ['actions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'type' => ['type' => 'string'], 'route_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'on' => ['type' => 'boolean'],
            'stop' => ['type' => 'string'], 'fare' => ['type' => ['number', 'null']], 'dir' => ['type' => 'string'], 'date' => ['type' => 'string'],
            'bus_id' => ['type' => 'integer'], 'value' => ['type' => ['string', 'number']], 'n' => ['type' => 'integer'], 'agent' => ['type' => 'string'], 'key' => ['type' => 'string'],
        ], 'required' => ['type']]]], 'required' => ['actions']];
        $j = null;
        try {
            require_once __DIR__ . '/agentpro-ai.php';
            if (class_exists('ApAi') && ApAi::enabled()) {
                $j = ApAi::json($sys, $text, $schema, [], ['purpose' => 'settings', 'max_tokens' => 400, 'timeout' => 30, 'lock_wait' => 5]);
            }
        } catch (Throwable $e) {
            $j = null;
        }
        if (!is_array($j)) {
            try {
                require_once __DIR__ . '/aibrain.php';
                if (class_exists('AiBrain') && AiBrain::configured()) {
                    $r = AiBrain::text($sys . "\nJSON only.", [['role' => 'user', 'content' => $text]], 500, 'settings');
                    $rawOut = trim((string) ($r['text'] ?? ''));
                    if (preg_match('/\{.*\}/s', $rawOut, $m) === 1) {
                        $j = json_decode($m[0], true);
                    }
                }
            } catch (Throwable $e) {
                $j = null;
            }
        }
        $out = [];
        foreach ((array) ($j['actions'] ?? []) as $a) {
            $a = is_array($a) ? self::clean($a, $text) : null;
            if ($a !== null) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /**
     * Hold whatever came back to the rules: known ids only, sane numbers.
     *
     * @param array<string,mixed> $a
     * @return array<string,mixed>|null
     */
    public static function clean(array $a, string $text = ''): ?array
    {
        $type = (string) ($a['type'] ?? '');
        $routeIds = array_map(static fn(array $r): int => (int) $r['id'], self::routes());
        $busIds   = array_map(static fn(array $b): int => (int) $b['id'], self::buses());
        $fare     = static function ($v): ?float {
            if ($v === null || $v === '') {
                return null;
            }
            $f = (float) $v;
            return ($f >= 100 && $f <= 20000) ? round($f, 2) : -1.0;
        };
        switch ($type) {
            case 'route':
                $ids = array_values(array_intersect(array_map('intval', (array) ($a['route_ids'] ?? [])), $routeIds));
                return $ids === [] ? null : ['type' => 'route', 'route_ids' => $ids, 'on' => (bool) ($a['on'] ?? false)];
            case 'stop_fare':
                $f = $fare($a['fare'] ?? null);
                $stop = trim((string) ($a['stop'] ?? ''));
                return ($f === null || $f < 0 || $stop === '') ? null : ['type' => 'stop_fare', 'stop' => mb_substr($stop, 0, 120), 'fare' => $f];
            case 'dir_fare':
                $f = $fare($a['fare'] ?? null);
                $dir = (string) ($a['dir'] ?? '');
                return ($f === null || $f < 0 || !in_array($dir, ['toNepal', 'toIndia'], true)) ? null : ['type' => 'dir_fare', 'dir' => $dir, 'fare' => $f];
            case 'trip_fare':
                $d = (string) ($a['date'] ?? '');
                $f = $fare($a['fare'] ?? null);
                return (!Security::isValidDate($d) || ($f !== null && $f < 0)) ? null : ['type' => 'trip_fare', 'date' => $d, 'fare' => $f];
            case 'trip_sale':
                $d = (string) ($a['date'] ?? '');
                return !Security::isValidDate($d) ? null : ['type' => 'trip_sale', 'date' => $d, 'on' => (bool) ($a['on'] ?? false)];
            case 'bus_number':
                $bid = (int) ($a['bus_id'] ?? 0);
                $v = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($a['value'] ?? '')));
                return (!in_array($bid, $busIds, true) || strlen($v) < 4 || strlen($v) > 20) ? null : ['type' => 'bus_number', 'bus_id' => $bid, 'value' => $v];
            case 'bus_name':
                $bid = (int) ($a['bus_id'] ?? 0);
                $v = trim(Security::clean((string) ($a['value'] ?? ''), 120));
                return (!in_array($bid, $busIds, true) || mb_strlen($v) < 2) ? null : ['type' => 'bus_name', 'bus_id' => $bid, 'value' => $v];
            case 'extra_seats':
                $n = (int) ($a['n'] ?? $a['value'] ?? -1);
                return ($n < 0 || $n > 40) ? null : ['type' => 'extra_seats', 'n' => $n];
            case 'commission':
                $id = (int) ($a['agent_id'] ?? 0);
                if ($id <= 0) {
                    $ag = self::findAgent((string) ($a['agent'] ?? ''), self::norm((string) ($a['agent'] ?? '')));
                    $id = (int) ($ag['id'] ?? 0);
                }
                $v = (float) ($a['value'] ?? -1);
                if ($id <= 0 || $v < 0 || $v > 10000 || !Database::exists("SELECT 1 FROM admins WHERE id = :i AND role = 'agent'", ['i' => $id])) {
                    return null;
                }
                return ['type' => 'commission', 'agent_id' => $id, 'value' => round($v, 2)];
            case 'setting':
                $key  = (string) ($a['key'] ?? '');
                $info = self::keyInfo($key);
                if ($info === null) {
                    return null;
                }
                $v = (string) ($a['value'] ?? '');
                $v = match ($info['type']) {
                    'bool'  => in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true) ? '1' : '0',
                    'int'   => is_numeric($v) ? (string) (int) $v : '',
                    'float' => is_numeric($v) ? (string) (float) $v : '',
                    'enum'  => isset($info['options'][$v]) ? $v : '',
                    default => mb_substr(trim($v), 0, 500),
                };
                return ($v === '' && $info['type'] !== 'string') ? null : ['type' => 'setting', 'key' => $key, 'value' => $v];
        }
        return null;
    }

    /* =================================================================
     *  Permission, preview, apply
     * ================================================================= */

    public static function allowed(array $a): bool
    {
        if (Auth::isSuperadmin()) {
            return true;
        }
        return match ((string) $a['type']) {
            'route', 'stop_fare', 'dir_fare' => Auth::can('routes.edit'),
            'trip_fare', 'trip_sale'         => Auth::can('schedules.manage'),
            'bus_number', 'bus_name'          => Auth::can('routes.edit') || Auth::can('schedules.manage'),
            'commission'                      => Auth::can('staff.manage') && Auth::canManageSettings(),
            default                           => Auth::canManageSettings(),
        };
    }

    /**
     * What would change, row by row.
     *
     * @return list<array{0:string,1:string,2:string}> [what, old, new]
     */
    public static function preview(array $a): array
    {
        $rows = [];
        switch ((string) $a['type']) {
            case 'route':
                foreach (self::routes() as $r) {
                    if (in_array((int) $r['id'], $a['route_ids'], true)) {
                        $rows[] = ['🛣 ' . $r['from_city'] . ' → ' . $r['to_city'] . ' (' . $r['route_code'] . ')',
                            (int) $r['is_active'] === 1 ? 'ON' : 'OFF', $a['on'] ? 'ON 🟢' : 'OFF ⛔'];
                    }
                }
                break;
            case 'stop_fare':
                foreach (self::stopRows((string) $a['stop']) as $s) {
                    $rows[] = ['💰 ' . $s['stop_name'] . ' (' . $s['from_city'] . ' → ' . $s['to_city'] . ')',
                        $s['fare'] !== null ? inr((float) $s['fare']) : 'default', inr((float) $a['fare'])];
                }
                break;
            case 'dir_fare':
                $cur = Fare::dirFares()[$a['dir']] ?? 0;
                $rows[] = ['💰 Default fare ' . ($a['dir'] === 'toNepal' ? 'जाने · to Nepal' : 'आउने · to India'), inr((float) $cur), inr((float) $a['fare'])];
                break;
            case 'trip_fare':
                foreach (self::dateSchedules((string) $a['date'], (array) ($a['route_ids'] ?? [])) as $s) {
                    $rows[] = ['📅 ' . formatDate((string) $a['date'], 'j M') . ' · ' . $s['from_city'] . ' → ' . $s['to_city'],
                        $s['fare_override'] !== null ? inr((float) $s['fare_override']) : 'normal', $a['fare'] === null ? 'normal' : inr((float) $a['fare'])];
                }
                break;
            case 'trip_sale':
                foreach (self::dateSchedules((string) $a['date'], (array) ($a['route_ids'] ?? [])) as $s) {
                    if ($a['on'] && !Fleet::planFor((int) $s['route_id'], (string) $a['date'])['runs']) {
                        continue;   // the rotation has no bus for it that day
                    }
                    $rows[] = ['📅 ' . formatDate((string) $a['date'], 'j M') . ' · ' . $s['from_city'] . ' → ' . $s['to_city'],
                        (int) $s['is_blocked'] === 1 ? 'Sale OFF' : 'Sale ON', $a['on'] ? 'Sale ON 🟢' : 'Sale OFF ⛔'];
                }
                break;
            case 'bus_number':
            case 'bus_name':
                foreach (self::buses() as $b) {
                    if ((int) $b['id'] === (int) $a['bus_id']) {
                        $rows[] = ['🚌 ' . $b['bus_name'] . ($a['type'] === 'bus_number' ? ' — number' : ' — name'),
                            (string) ($a['type'] === 'bus_number' ? $b['bus_number'] : $b['bus_name']), (string) $a['value']];
                    }
                }
                break;
            case 'extra_seats':
                $rows[] = ['💺 Extra seats per bus', (string) Seats::extraSeatCount(), (string) $a['n']];
                break;
            case 'commission':
                require_once __DIR__ . '/agentwallet.php';
                $ag  = Database::fetch('SELECT full_name, username FROM admins WHERE id = :i', ['i' => (int) $a['agent_id']]) ?? [];
                $ov  = AgentWallet::commissionOverrideFor((int) $a['agent_id']);
                $old = $ov['flat'] !== null ? inr((float) $ov['flat']) . '/seat' : inr(AgentWallet::flatRateFor((int) $a['agent_id'])) . '/seat (company)';
                $rows[] = ['🧑‍💼 Commission · ' . (($ag['full_name'] ?? '') ?: ($ag['username'] ?? '#' . $a['agent_id'])) . ' ' . AgentWallet::agentCodeLabel((int) $a['agent_id']),
                    $old, inr((float) $a['value']) . '/seat'];
                break;
            case 'setting':
                $info = self::keyInfo((string) $a['key']);
                if ($info !== null) {
                    $show = static fn(string $v): string => $info['type'] === 'bool' ? ($v === '1' ? 'ON' : 'OFF')
                        : ($info['type'] === 'enum' ? (string) ($info['options'][$v] ?? $v) : $v);
                    $rows[] = ['⚙️ ' . $info['label'] . ' (' . $a['key'] . ')', $show($info['current']), $show((string) $a['value'])];
                }
                break;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> Nepal-bound pickups of active routes matching the stop ('*' = all) */
    private static function stopRows(string $stop): array
    {
        $rows = [];
        foreach (Database::fetchAll(
            "SELECT rs.id, rs.stop_name, rs.fare, rs.route_id, r.from_city, r.to_city
               FROM route_stops rs JOIN routes r ON r.id = rs.route_id
              WHERE r.is_active = 1 AND rs.stop_type = 'boarding' AND rs.stop_name <> ''
              ORDER BY rs.route_id, rs.sort_order"
        ) as $s) {
            if (Fare::routeDir(['to_city' => $s['to_city']]) !== 'toNepal') {
                continue;
            }
            if ($stop === '*') {
                if ($s['fare'] === null) {
                    continue;   // stops without their own fare follow the direction default
                }
            } elseif (mb_stripos((string) $s['stop_name'], $stop) !== 0 && mb_strtolower((string) $s['stop_name']) !== mb_strtolower($stop)) {
                continue;
            }
            $rows[] = $s;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function dateSchedules(string $date, array $routeIds = []): array
    {
        $rows = Database::fetchAll(
            "SELECT s.id, s.route_id, s.is_blocked, s.fare_override, s.status, r.from_city, r.to_city
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.travel_date = :d AND r.is_active = 1 AND s.status <> 'cancelled' ORDER BY s.id",
            ['d' => $date]
        );
        $routeIds = array_map('intval', $routeIds);
        return $routeIds === [] ? $rows : array_values(array_filter($rows, static fn(array $s): bool => in_array((int) $s['route_id'], $routeIds, true)));
    }

    /**
     * Do it. Every action through the same writer the manual screen uses.
     *
     * @param list<array<string,mixed>> $actions
     * @param array<string,mixed>       $admin
     * @return list<string> one line per action
     */
    public static function apply(array $actions, array $admin): array
    {
        $by  = (int) ($admin['id'] ?? 0);
        $out = [];
        foreach ($actions as $a0) {
            $a = self::clean($a0);
            if ($a === null) {
                continue;
            }
            if (!self::allowed($a)) {
                $out[] = '⛔ ' . $a['type'] . ': तपाईंलाई अनुमति छैन · not allowed for your login';
                continue;
            }
            try {
                $out[] = self::applyOne($a, $by);
            } catch (Throwable $e) {
                $out[] = '⚠️ ' . $a['type'] . ': ' . $e->getMessage();
            }
        }
        return $out;
    }

    private static function applyOne(array $a, int $by): string
    {
        switch ((string) $a['type']) {
            case 'route':
                $n = 0;
                foreach (self::routes() as $r) {
                    if (!in_array((int) $r['id'], $a['route_ids'], true) || (int) $r['is_active'] === ($a['on'] ? 1 : 0)) {
                        continue;
                    }
                    Database::update('routes', ['is_active' => $a['on'] ? 1 : 0], 'id = :i', ['i' => (int) $r['id']]);
                    Logger::audit('routes.toggled', 'routes', (string) $r['id'], ['is_active' => (int) $r['is_active']], ['is_active' => $a['on'] ? 1 : 0],
                        $r['route_code'] . ' ' . $r['from_city'] . ' → ' . $r['to_city'] . ' via S Hari AI by admin #' . $by);
                    $n++;
                }
                return '🛣 ' . $n . ' route ' . ($a['on'] ? 'ON 🟢' : 'OFF ⛔');
            case 'stop_fare':
                $rows = self::stopRows((string) $a['stop']);
                foreach ($rows as $s) {
                    Database::update('route_stops', ['fare' => $a['fare']], 'id = :i', ['i' => (int) $s['id']]);
                }
                if (class_exists('Boarding')) {
                    Boarding::flush();
                }
                Logger::audit('routes.stop_fare', 'route_stops', (string) $a['stop'], null, ['fare' => $a['fare'], 'stops' => count($rows)], 'via S Hari AI by admin #' . $by);
                return '💰 ' . count($rows) . ' pickup' . ($a['stop'] === '*' ? 's' : ' (' . $a['stop'] . ')') . ' → ' . inr((float) $a['fare']);
            case 'dir_fare':
                $key = $a['dir'] === 'toNepal' ? 'fare_to_nepal' : 'fare_to_india';
                $old = Settings::getFloat($key, 0.0);
                Settings::set($key, (string) $a['fare'], 'float', 'pricing');
                $mf = Settings::getArray('main_fares', []);
                if ($mf !== [] && isset($mf[$a['dir']])) {
                    $mf[$a['dir']] = $a['fare'];
                    Settings::set('main_fares', $mf, 'json', 'pricing');
                }
                Logger::audit('settings.fare', 'settings', $key, ['value' => $old], ['value' => $a['fare']], 'via S Hari AI by admin #' . $by);
                return '💰 Default ' . ($a['dir'] === 'toNepal' ? 'to Nepal' : 'to India') . ' → ' . inr((float) $a['fare']);
            case 'trip_fare':
                $rows = self::dateSchedules((string) $a['date'], (array) ($a['route_ids'] ?? []));
                foreach ($rows as $s) {
                    Database::update('schedules', ['fare_override' => $a['fare']], 'id = :i', ['i' => (int) $s['id']]);
                    Logger::audit('schedule.fare_override', 'schedule', (string) $s['id'], ['fare_override' => $s['fare_override']], ['fare_override' => $a['fare']], 'via S Hari AI by admin #' . $by);
                }
                return '📅 ' . formatDate((string) $a['date'], 'j M') . ': ' . count($rows) . ' bus → ' . ($a['fare'] === null ? 'normal fare' : inr((float) $a['fare']));
            case 'trip_sale':
                $n = 0;
                foreach (self::dateSchedules((string) $a['date'], (array) ($a['route_ids'] ?? [])) as $s) {
                    if ($a['on'] && !Fleet::planFor((int) $s['route_id'], (string) $a['date'])['runs']) {
                        continue;
                    }
                    Database::update('schedules', ['is_blocked' => $a['on'] ? 0 : 1], 'id = :i', ['i' => (int) $s['id']]);
                    Logger::audit('schedule.sale_toggle', 'schedule', (string) $s['id'], ['is_blocked' => (int) $s['is_blocked']], ['is_blocked' => $a['on'] ? 0 : 1], 'via S Hari AI by admin #' . $by);
                    $n++;
                }
                return '📅 ' . formatDate((string) $a['date'], 'j M') . ': ' . $n . ' bus sale ' . ($a['on'] ? 'ON 🟢' : 'OFF ⛔');
            case 'bus_number':
            case 'bus_name':
                $b = Database::fetch('SELECT bus_name, bus_number FROM buses WHERE id = :i', ['i' => (int) $a['bus_id']]);
                if ($b === null) {
                    throw new RuntimeException('bus not found');
                }
                Fleet::updateBus((int) $a['bus_id'],
                    $a['type'] === 'bus_name' ? (string) $a['value'] : (string) $b['bus_name'],
                    $a['type'] === 'bus_number' ? (string) $a['value'] : (string) $b['bus_number'], $by);
                return '🚌 ' . ($a['type'] === 'bus_name' ? $b['bus_name'] . ' → ' . $a['value'] : $b['bus_name'] . ' number → ' . $a['value'] . ' (tickets redrawn)');
            case 'extra_seats':
                $old = Seats::extraSeatCount();
                Settings::set('extra_seats_per_bus', (string) $a['n'], 'int', 'booking');
                Logger::audit('settings.extra_seats', 'settings', 'extra_seats_per_bus', ['n' => $old], ['n' => $a['n']], 'via S Hari AI by admin #' . $by);
                return '💺 Extra seats per bus → ' . $a['n'];
            case 'commission':
                require_once __DIR__ . '/agentwallet.php';
                AgentWallet::setCommissionOverride((int) $a['agent_id'], 'flat', (float) $a['value'], $by);
                return '🧑‍💼 Commission → ' . inr((float) $a['value']) . '/seat · ' . AgentWallet::agentCodeLabel((int) $a['agent_id']);
            case 'setting':
                $info = self::keyInfo((string) $a['key']);
                if ($info === null) {
                    throw new RuntimeException('unknown setting');
                }
                $type = $info['type'] === 'enum' ? 'string' : $info['type'];
                Settings::set((string) $a['key'], $type === 'bool' ? ((string) $a['value'] === '1') : (string) $a['value'], $type, $info['group'], $info['public']);
                Logger::audit('settings.ai', 'setting', (string) $a['key'], ['value' => $info['current']], ['value' => $a['value']], 'via S Hari AI by admin #' . $by);
                return '⚙️ ' . $info['label'] . ' → ' . ($type === 'bool' ? ((string) $a['value'] === '1' ? 'ON' : 'OFF')
                    : ($info['type'] === 'enum' ? (string) ($info['options'][(string) $a['value']] ?? $a['value']) : $a['value']));
        }
        throw new RuntimeException('unknown action');
    }
}
