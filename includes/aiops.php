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
 *
 *  16 Nov 2026 (v2.0):
 *     fare_delta   {stop|'*', delta, dir}     every fare it names up / down by ₹delta
 *     add_stop     {route_id, stop_type, stop_name, stop_time, fare}  Boarding::addStop
 *     seat_block   {seat, date, on}           one berth out of / back in service per departure
 *     trip_fare / trip_sale also take time (+ alt for "10 baje" am/pm) and dir.
 *  16 Nov 2026 (v2.0, unit W3):
 *     trip_delay   {schedule_id, date, minutes} "bholi jane bus 2 ghanta late": the trips board's
 *                  Delay (delay_minutes + TripNotify::notifyDelay) for ONE bus, office only
 *                  (schedules.manage), only while ai_trip_delay_on is ON; never from the AI path.
 *     ai_settings_engine 'cloud' asks the cloud brain before the VPS model; ai_change_wa_on
 *     sends the office one WhatsApp line after a "Yes"; every v2.0 switch is in EASY_KEYS.
 *  16 Nov 2026 (Master v2, unit Y2 — P3-2f + P8-10e):
 *     coupon       {code, discount_type, discount_value, max_discount, min_amount, valid_from,
 *                   valid_until, usage_limit, per_user_limit, route_id}  "coupon DIWALI10 10% max 200
 *                  till 30 Nov": a NEW offer row, held to admin/offers.php's rules (OfferRules), always
 *                  written is_active = 0 AND auto_apply = 0 — no fare changes until the office switches
 *                  it ON on Offers. Refused: a percent without a cap, over 50 %, a past date, a code
 *                  already used. coupons.edit (or super-admin) only; never from the AI path.
 *     coupon_off   {coupon_id, code}  "offer band DIWALI10": an ACTIVE offer OFF (it only takes a
 *                  discount away). The AI never switches an offer ON.
 *  A sentence the words half-understand ("which pickup?", "what time?")
 *  returns [] with lastNote() set, and the AI is not asked to guess.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiOps
{
    public const TYPES = ['route', 'stop_fare', 'dir_fare', 'trip_fare', 'trip_sale', 'bus_number', 'bus_name', 'extra_seats', 'commission', 'setting',
                          'fare_delta', 'add_stop', 'seat_block', 'trip_delay', 'coupon', 'coupon_off'];

    private const ON  = ['on', 'chalu', 'chalau', 'chalaideu', 'chalaidinu', 'khol', 'kholdeu', 'kholdinu', 'enable', 'start', 'activate', 'open', 'suru', 'shuru',
                         'चालु', 'खोल', 'चलाउ', 'सुरु', 'अन', 'शुरू', 'चालू', 'खोलो', 'chalu gara', 'चलाऊ',
                         'ચાલુ', 'શરૂ', 'ખોલો', 'ચાલુ કરો'];   // 16 Nov 2026: Gujarati
    private const OFF = ['off', 'band', 'banda', 'bandh', 'bnd', 'disable', 'stop', 'roka', 'rok', 'rokdeu', 'hatau', 'close', 'deactivate',
                         'बन्द', 'बंद', 'रोक', 'हटाउ', 'अफ', 'रोको', 'hatao', 'nachalau', 'na chalau',
                         'બંધ', 'બંધ કરો', 'રોકો'];

    /** "all / every" — the only words that may widen a fare change to every pickup (16 Nov 2026, P3-2a). */
    private const ALL_WORDS = ['sabai', 'sab', 'sabko', 'sabhi', 'all', 'every', 'sabbai', 'सबै', 'सब', 'सबको', 'सभी', 'બધા', 'બધી', 'બધું', 'બધાં'];
    /** going to Nepal / coming back (P3-2b) */
    private const DIR_NEPAL = ['jane', 'jaane', 'going', 'nepal', 'नेपाल', 'जाने', 'જતી', 'જવાની', 'નેપાળ'];
    private const DIR_INDIA = ['aaune', 'aune', 'return', 'wapas', 'wapsi', 'farkane', 'india tira', 'gujarat tira', 'फर्कने', 'आउने', 'वापसी', 'वापस', 'આવતી', 'પાછી', 'રિટર્ન'];

    /** Devanagari / Hindi spellings of the places the routes use. */
    private const CITY_ALIAS = [
        'सुरत' => 'surat', 'रुपैडिहा' => 'rupaidiha', 'रुपैडीहा' => 'rupaidiha', 'रूपैडिहा' => 'rupaidiha', 'रुपईडीहा' => 'rupaidiha', 'रुपैदिहा' => 'rupaidiha',
        'rupaidiya' => 'rupaidiha', 'rupedia' => 'rupaidiha', 'rupadiha' => 'rupaidiha', 'rupaidia' => 'rupaidiha', 'rupdia' => 'rupaidiha',
        'राजकोट' => 'rajkot', 'अहमदाबाद' => 'ahmedabad', 'अहमदावाद' => 'ahmedabad', 'नेपालगञ्ज' => 'nepalgunj', 'नेपालगंज' => 'nepalgunj',
        'मेहसाना' => 'mehsana', 'वडोदरा' => 'vadodara', 'बडोदा' => 'vadodara', 'baroda' => 'vadodara', 'भरुच' => 'bharuch', 'आनन्द' => 'anand',
        'नडियाद' => 'nadiad', 'कामरेज' => 'kamrej', 'अंकलेश्वर' => 'ankleshwar', 'गोधरा' => 'godhra', 'हिम्मतनगर' => 'himatnagar', 'पालनपुर' => 'palanpur',
        'amdavad' => 'ahmedabad', 'ahmdabad' => 'ahmedabad',
        // 16 Nov 2026 (BUG1 + P3-7b): Mehsana as the office spells it, and the Gujarat pickups in Gujarati script
        'महेसाणा' => 'mehsana', 'मेहसाणा' => 'mehsana', 'महेसाना' => 'mehsana', 'mahesana' => 'mehsana', 'mehesana' => 'mehsana', 'mahesaana' => 'mehsana',
        'गांधीनगर' => 'gandhinagar', 'गान्धीनगर' => 'gandhinagar', 'दाहोद' => 'dahod',
        'મહેસાણા' => 'mehsana', 'સુરત' => 'surat', 'વડોદરા' => 'vadodara', 'અમદાવાદ' => 'ahmedabad', 'ભરૂચ' => 'bharuch', 'ભરુચ' => 'bharuch',
        'આણંદ' => 'anand', 'નડિયાદ' => 'nadiad', 'કામરેજ' => 'kamrej', 'અંકલેશ્વર' => 'ankleshwar', 'ગોધરા' => 'godhra', 'હિંમતનગર' => 'himatnagar',
        'હિમ્મતનગર' => 'himatnagar', 'પાલનપુર' => 'palanpur', 'રાજકોટ' => 'rajkot', 'ગાંધીનગર' => 'gandhinagar', 'દાહોદ' => 'dahod',
        'રૂપૈડીહા' => 'rupaidiha', 'રુપૈડીહા' => 'rupaidiha', 'નેપાળગંજ' => 'nepalgunj',
    ];

    /** What the words understood but could not settle ("which pickup?", "what time?"). */
    private static string $note = '';

    /** The question / reason left by the last parseWords() that returned []. '' = none. */
    public static function lastNote(): string
    {
        return self::$note;
    }

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
        if (self::$note !== '') {
            // the words know what is meant but need one more detail — the AI must not guess it
            return ['actions' => [], 'via' => 'words', 'note' => self::$note];
        }
        if ($useAi) {
            $acts = self::parseAi($text);
            if ($acts !== []) {
                return ['actions' => $acts, 'via' => 'AI', 'note' => ''];
            }
        }
        return ['actions' => [], 'via' => '', 'note' => ''];
    }

    /** Devanagari / Gujarati digits → 0-9 (norm() and the switch words read the same). */
    private const DIGITS = ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9',
                            '૦' => '0', '૧' => '1', '૨' => '2', '૩' => '3', '૪' => '4', '૫' => '5', '૬' => '6', '૭' => '7', '૮' => '8', '૯' => '9'];

    public static function norm(string $text): string
    {
        $t = strtr($text, self::DIGITS + ['₹' => ' ']);
        /* 16 Nov 2026 (BUG1): "2,200" / "1,00,000" / "2,200/-" / "Rs.2200" is ONE amount — the comma
           used to split "Mehsana price 2,200" into a ₹200 fare. A list ("seat 1,2", "12,13": the last
           group is not 3 digits) still splits. */
        $t = (string) preg_replace_callback('/(?<![\d,])\d{1,3}(?:,\d{2,3})*,\d{3}(?![\d,])/u', static fn(array $m): string => str_replace(',', '', $m[0]), $t);
        $t = (string) preg_replace('/(\d)\s*\/-/u', '$1 ', $t);
        $t = (string) preg_replace('/(?<![a-z])rs\.?\s*(?=\d)/iu', ' ', $t);
        $t = str_replace(',', ' ', $t);
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
        // 16 Nov 2026 (P3-7b): Gujarati aaje / kale / param divase too
        if (preg_match('/(?<![a-z])(aaja|aaj|aaje|today|आज|આજે)(?![a-z])/u', $t) === 1) {
            return $today;
        }
        if (preg_match('/(?<![a-z])(bholi|bholi|kal|kale|tomorrow|भोलि|भोली|कल|કાલે)(?![a-z])/u', $t) === 1) {
            return date('Y-m-d', strtotime($today . ' +1 day'));
        }
        if (preg_match('/(?<![a-z])(parsi|parso|parson|पर्सि|पर्सी|परसों|param divase|પરમ દિવસે|પરમદિવસે)(?![a-z])/u', $t) === 1) {
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

    /** The sentence without what is not an amount: an ISO date, a clock time, a vehicle plate. */
    private static function amountText(string $t): string
    {
        $t = (string) preg_replace('/20\d\d-\d\d-\d\d/', ' ', $t);
        $t = (string) preg_replace('/(?<![\d:])([01]?\d|2[0-3]):[0-5]\d(?![\d:])/', ' ', $t);                     // "22:30"
        $t = (string) preg_replace('/(?<![\d:])\d{1,2}\s*(?:baje|baj|bje|o\'?clock|बजे|vagye|vage|વાગ્યે|વાગે)/u', ' ', $t);   // "10 baje"
        /* 16 Nov 2026 (P3-2a): a plate needs its series letters and a 4-digit tail (GJ05AB1234,
           GJ-02-T-4127). The old shape took "do 2" + "200" for a plate, so "badha do 2200 kar do"
           lost its fare. */
        return (string) preg_replace('/\b(?!(?:do|ko|ka|ki|ke|ma|se|ne|le|to|ho|na|ta)\b)[a-z]{2}[ -]?\d{1,2}[ -]?[a-z]{1,3}[ -]?\d{4}\b/', ' ', $t);
    }

    /** @return list<int> numbers in the sentence (not part of a date, a time or a plate) */
    private static function numbers(string $t): array
    {
        preg_match_all('/(?<![\d.])(\d{1,6})(?![\d.])/', self::amountText($t), $m);
        return array_map('intval', $m[1]);
    }

    /**
     * A clock time in the sentence (16 Nov 2026, P3-2b): "22:30", "1:30 pm", "raat 10 baje",
     * "subah 10 baje". A bare "10 baje" could be morning or night: time = 10:00, alt = 22:00.
     *
     * @return array{time:string, alt:?string}|null
     */
    public static function findTime(string $t): ?array
    {
        $h = null; $mi = 0; $bare = false;
        if (preg_match('/(?<![\d:.])([01]?\d|2[0-3])[:.]([0-5]\d)(?![\d:])\s*(a\.?m\.?|p\.?m\.?|baje|बजे|vagye|વાગ્યે)/u', $t, $m) === 1) {
            $h = (int) $m[1];                 // "10.30 baje", "1:30 pm"
            $mi = (int) $m[2];
            $bare = !str_contains($m[3], 'm');
        } elseif (preg_match('/(?<![\d:.])([01]?\d|2[0-3]):([0-5]\d)(?![\d:])/', $t, $m) === 1) {
            $h = (int) $m[1];                 // "22:30" — a 24-hour clock
            $mi = (int) $m[2];
        } elseif (preg_match('/(?<![\d:.])(\d{1,2})\s*(?:baje|baj|bje|o\'?clock|बजे|vagye|vage|વાગ્યે|વાગે)/u', $t, $m) === 1
            || preg_match('/(?<![\d:.])(\d{1,2})\s*(?=a\.?m\.?(?![a-z])|p\.?m\.?(?![a-z]))/u', $t, $m) === 1) {
            $h = (int) $m[1];
            $bare = true;
        }
        if ($h === null || $h > 23) {
            return null;
        }
        $am = self::hasWord($t, ['am', 'a.m.', 'subah', 'subha', 'savere', 'savare', 'bihana', 'bihan', 'morning', 'सुबह', 'सवेरे', 'बिहान', 'સવારે']) >= 0;
        $pm = self::hasWord($t, ['pm', 'p.m.', 'dopahar', 'dupahar', 'diuso', 'afternoon', 'shaam', 'sham', 'saanjh', 'sanjh', 'beluka', 'evening',
                                 'raat', 'rat', 'rati', 'night', 'दोपहर', 'दिउँसो', 'शाम', 'साँझ', 'बेलुका', 'रात', 'राति', 'બપોરે', 'સાંજે', 'રાત્રે', 'રાતે']) >= 0;
        $alt = null;
        if ($pm && !$am) {
            $h = $h < 12 ? $h + 12 : $h;
        } elseif ($am && !$pm) {
            $h = $h === 12 ? 0 : $h;
        } elseif ($bare && $h >= 1 && $h <= 12) {
            $alt = sprintf('%02d:%02d', ($h + 12) % 24, $mi);
        }
        return ['time' => sprintf('%02d:%02d', $h, $mi), 'alt' => $alt];
    }

    /** 'toNepal' (jane) / 'toIndia' (aaune) when the sentence says one direction only. */
    public static function findDir(string $t): ?string
    {
        $np = self::hasWord($t, self::DIR_NEPAL) >= 0;
        $in = self::hasWord($t, self::DIR_INDIA) >= 0;
        return $np === $in ? null : ($np ? 'toNepal' : 'toIndia');
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
            /* 16 Nov 2026 (P3-2a): the place part ends at ",", "(" or a spaced dash —
               "Mehsana Head Office - Silver Complex" → "mehsana head office"; "Limbli / Bhupal"
               answers to either name; a known town said alone ("Mehsana") names the stop that
               starts with it. */
            $key  = mb_strtolower(trim((string) preg_replace('/(?:[,(]| [-—–] ).*$/u', '', $name)));
            $alts = array_merge([$key], array_map('trim', preg_split('/\s*\/\s*/u', $key) ?: []));
            $hit  = false;
            foreach (array_unique($alts) as $k) {
                if ($k !== '' && mb_strlen($k) >= 4 && mb_strpos($t, $k) !== false) {
                    $hit = true;
                    break;
                }
            }
            $first = (string) (preg_split('/\s+/u', $key)[0] ?? '');
            if (!$hit && $first !== $key && mb_strlen($first) >= 5 && isset(self::knownTowns()[$first])
                && preg_match('/(?<![\p{L}])' . preg_quote($first, '/') . '(?![\p{L}])/u', $t) === 1) {
                $hit = true;
            }
            if ($hit) {
                $out[] = $name;
            }
        }
        // "Surat" already covers "Surat Kamrej Road" (stopRows matches by the start of the name)
        return array_values(array_filter($out, static function (string $n) use ($out): bool {
            foreach ($out as $o) {
                if ($o !== $n && mb_stripos($n, $o) === 0) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** @return array<string,true> lower-case town names: the route cities + every alias target */
    private static function knownTowns(): array
    {
        static $towns = null;
        if ($towns === null) {
            $towns = array_fill_keys(array_values(self::CITY_ALIAS), true);
            foreach (self::routes() as $r) {
                foreach ([(string) $r['from_city'], (string) $r['to_city']] as $c) {
                    $towns[mb_strtolower(trim($c))] = true;
                }
            }
        }
        return $towns;
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
        self::$note = '';
        $t    = self::norm($raw);
        $nums = self::numbers($t);
        $date = self::findDate($t);
        $oo   = self::onOff($t);
        $acts = [];

        $isFare  = self::hasWord($t, ['bhada', 'bhaada', 'bhara', 'fare', 'price', 'rate', 'kiraya', 'kiraaya', 'daam', 'dam', 'ticket price', 'भाडा', 'भाड़ा', 'किराया', 'दाम', 'मूल्य',
                                      'fares', 'prices', 'rates', 'bhadu', 'kimat', 'kimmat', 'ભાડું', 'ભાડુ', 'ભાડા', 'કિંમત', 'ભાવ']) >= 0
            // X5 (16 Nov 2026): "infant fare 500" is the lap-infant knob (infant_seat_fare), never a pickup's seat fare
            && self::hasWord($t, ['infant', 'baby', 'lap baby', 'kakhe', 'kaakhe', 'शिशु', 'काखे', 'ખોળામાં']) < 0;
        $isComm  = self::hasWord($t, ['commission', 'comission', 'kamisan', 'kamishan', 'commision', 'कमिसन', 'कमीशन', 'कमिशन']) >= 0;
        $isExtra = self::hasWord($t, ['extra seat', 'extra seats', 'extra', 'ex seat', 'थप सिट', 'एक्स्ट्रा', 'atirikt', 'अतिरिक्त']) >= 0
            // X5 (16 Nov 2026): "extra luggage fee 200" is the luggage knob, never 40 extra seats per bus
            && self::hasWord($t, ['luggage', 'baggage', 'saman', 'samaan', 'लगेज', 'सामान', 'સામાન']) < 0;
        $isFine  = self::hasWord($t, ['fine', 'jariwana', 'jarimana', 'penalty', 'cancel charge', 'जरिवाना', 'जुर्माना', 'दण्ड', 'जरिमाना']) >= 0;
        $isNum   = self::hasWord($t, ['number', 'no.', 'plate', 'gadi no', 'नम्बर', 'नंबर', 'नं']) >= 0;
        $isRoute = self::hasWord($t, ['route', 'routes', 'rut', 'root', 'रुट', 'रूट', 'line', 'लाइन']) >= 0;
        $isOnly  = self::hasWord($t, ['only', 'matra', 'matrai', 'sirf', 'keval', 'मात्र', 'सिर्फ', 'केवल']) >= 0;
        $isName  = self::hasWord($t, ['naam', 'name', 'नाम']) >= 0;
        $isAll   = self::hasWord($t, self::ALL_WORDS) >= 0;
        $dirSaid = self::findDir($t);
        // a setting said by its own key ("agent_commission_percent 6") belongs to rule 8, not to the commission rule (P3-2c)
        $keyName = preg_match('/(?<![a-z_])([a-z][a-z0-9]+(?:_[a-z0-9]+)+)(?![a-z_])/', $t, $km) === 1 ? $km[1] : '';
        $keyRow  = $keyName !== '' ? self::settingRow($keyName) : null;

        /* 0. an offer / coupon (16 Nov 2026, Y2: P3-2f + P8-10e): "coupon DIWALI10 10% max 200 till 30 Nov",
              "offer band DIWALI10". First, so "₹50 off coupon 20 Nov tak" is never a date's sale OFF — but
              (Y2 review) never over a commission / fine sentence or a setting named by its key (rule 8), and a
              fare / extra-seat sentence is an offer only when it says a discount ("10%", "₹100 off"):
              "Diwali offer Surat ko bhada ₹2000" is Surat's fare, as before. */
        $coupon = ($isComm || $isFine || $keyRow !== null) ? null : self::couponAction($raw, $t, $isFare || $isExtra);
        if ($coupon !== null) {
            return $coupon;
        }

        /* 1. commission for one agent: "Ram ko commission 250", "SHG-0027 commission 300" */
        if ($isComm && $nums !== [] && $keyRow === null) {
            // 16 Nov 2026 (P3-2c): the company pays a flat ₹ per seat — "10%" used to become ₹10/seat
            if (preg_match('/\d\s*(?:%|percent|per cent|pratishat|प्रतिशत|ટકા)/u', $t) === 1) {
                self::$note = '💡 कमिसन % मा होइन, प्रति सिट ₹ मा हुन्छ · Commission is ₹ per seat, not %. Say e.g. "Ramesh ko commission 200".';
                return [];
            }
            $agent = self::findAgent($raw, $t);
            $val   = (float) max($nums);
            if ($agent !== null && $val >= 0 && $val <= 10000) {
                return [['type' => 'commission', 'agent_id' => (int) $agent['id'], 'value' => $val]];
            }
        }

        /* 1a. a bus running late: "bholi jane bus 2 ghanta late" (16 Nov 2026, P8-10d) — office only,
              behind ai_trip_delay_on; the passengers are told only after "Yes" */
        $delay = self::delayAction($t, $date, $dirSaid);
        if ($delay !== null) {
            return $delay;
        }

        /* 1b. a new pickup / drop point: "Naya pickup add karo: Gandhinagar 22:30" (16 Nov 2026, P3-2e)
              — not "sabai pickup ko bhada 100 thap" (that is a fare change BY ₹100) */
        /*    — nor "Mehsana boarding point ko new price 2,200" / "Surat pickup ko naya bhada 2300": a fare
                said for a pickup that already exists, with no time, is a NEW FARE, not a new stop */
        $fareFirst = $isFare && $nums !== [] && (self::fareAmount($t, $nums)[0] === 'delta'
            || (self::findTime($t) === null && self::findStops($t) !== []));
        $add = $fareFirst ? null : self::addStopAction($raw, $t);
        if ($add !== null) {
            return $add;
        }

        /* 1c. one berth out of / back in service: "Seat U15 block karo kal" (16 Nov 2026, P3-2g) */
        $seat = self::seatAction($t, $date, $dirSaid);
        if ($seat !== null) {
            return $seat;
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
            [$kind, $amount] = self::fareAmount($t, $nums);
            if ($kind === 'ask') {
                self::$note = '🤔 यति बनाउने कि यति बढाउने? · Set the fare TO this amount, or change it BY this amount? '
                    . 'Say e.g. "Surat ko bhada 2300 kar do" or "Surat ko bhada 100 badha do".';
                return [];
            }
            if ($kind === 'delta') {
                return self::deltaActions($t, $amount, $date, $dirSaid, $isAll, $isRoute);
            }
            $fare = $amount;
            if ($fare >= 100 && $fare <= 20000) {
                if ($date !== null) {
                    return self::withDeparture(['type' => 'trip_fare', 'date' => $date, 'fare' => $fare] + self::tripFilter($t, $dirSaid));
                }
                $back   = self::hasWord($t, self::DIR_INDIA) >= 0;
                $cities = self::findCities($t);
                if (!$back && $cities !== [] && Fare::isNepalPoint(mb_convert_case($cities[0], MB_CASE_TITLE))) {
                    $back = true;   // "Rupaidiha Surat bhada" = coming back
                }
                if ($back) {
                    return [['type' => 'dir_fare', 'dir' => 'toIndia', 'fare' => $fare]];
                }
                $stops = self::findStops($t);
                if ($stops !== [] && !$isAll) {
                    // review 16 Nov 2026: "Surat 2300 aur Kamrej 2100" gave BOTH ₹2,300 — two pickups and two fares are asked
                    if (count($stops) > 1 && count(array_unique(array_filter($nums, static fn(int $n): bool => $n >= 100))) > 1) {
                        self::$note = '🤔 एक पटकमा एउटा भाडा · One fare per sentence — say e.g. "Surat ko bhada 2300", then "Kamrej ko bhada 2100".';
                        return [];
                    }
                    foreach ($stops as $s) {
                        $acts[] = ['type' => 'stop_fare', 'stop' => $s, 'fare' => $fare];
                    }
                    return $acts;
                }
                if ($isAll) {
                    return [['type' => 'stop_fare', 'stop' => '*', 'fare' => $fare], ['type' => 'dir_fare', 'dir' => 'toNepal', 'fare' => $fare]];
                }
                if (self::hasWord($t, ['default', 'base fare', 'डिफल्ट', 'ડિફોલ્ટ']) >= 0) {
                    return [['type' => 'dir_fare', 'dir' => 'toNepal', 'fare' => $fare]];
                }
                /* 16 Nov 2026 (P3-2a): a city that is not a pickup ("Rajkot ko bhada 2500" on live), or
                   "jane"/"nepal" alone, used to re-price EVERY pickup and the default. Only an "all"
                   word does that now; anything else is asked. */
                self::$note = self::whichPickupNote();
                return [];
            }
        }
        if ($isFare && $date !== null && self::hasWord($t, ['normal', 'pahile', 'pahilekai', 'clear', 'hatau', 'remove', 'पहिले', 'सामान्य']) >= 0) {
            return self::withDeparture(['type' => 'trip_fare', 'date' => $date, 'fare' => null] + self::tripFilter($t, $dirSaid));
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
            // 16 Nov 2026 (P3-2b): "10 baje" / "22:30" / "subah" and jane / aaune narrow it to those buses
            $a = ['type' => 'trip_sale', 'date' => $date, 'on' => $oo] + self::tripFilter($t, $dirSaid);
            if ($cities !== []) {
                $a['route_ids'] = array_map(static fn(array $r): int => (int) $r['id'], self::routesFor($cities, true));
                if ($a['route_ids'] === []) {
                    // the town has no route ON — it used to fall through to EVERY bus of the day
                    self::$note = '🛣 ' . implode(', ', array_map('ucfirst', $cities)) . ' को कुनै रुट चालु छैन · No route is ON for '
                        . implode(', ', array_map('ucfirst', $cities)) . '. Say the date only (every bus), or "jane" / "aaune".';
                    return [];
                }
            }
            return self::withDeparture($a);
        }

        /* 8. a setting named by its own key: "seat_hold_minutes 20" */
        if ($keyRow !== null) {
            $v = self::valueFor($keyRow, $t, $nums, $oo);
            if ($v !== null) {
                return [['type' => 'setting', 'key' => $keyName, 'value' => $v]];
            }
        }
        return [];
    }

    /** "Which pickup?" — asked instead of widening a fare change. */
    private static function whichPickupNote(): string
    {
        return '📍 कुन pickup? · Which pickup? Say its name, e.g. "Surat ko bhada 2300", or "sabai pickup ko bhada 2300" for every one.';
    }

    /**
     * Which amount a fare sentence means (16 Nov 2026, P3-2d). A number tied to a relative
     * word ("100 badha", "+100", "by 100", "100 kam / ghata") is a change BY it; a number tied
     * to "kar do / rakha / banao" (or "2000 se 2200") is the new fare, so "badha do 2200 kar do"
     * still sets ₹2,200. Both kinds, or a relative word with an untied number = 'ask'.
     *
     * @param list<int> $nums
     * @return array{0:string,1:float} ['abs', fare] | ['delta', ±amount] | ['ask', 0]
     */
    private static function fareAmount(string $t, array $nums): array
    {
        $x    = self::amountText($t);
        $cur  = '(?:\s*(?:rs\.?|rupaiya|rupiya|rupaye|rupee|rupees|रुपैयाँ|रुपैया|रुपये|रुपया|रूपये|રૂપિયા))?';
        $up   = '(?:badh[a-z]*|vadh[a-z]*|increase[a-z]*|plus(?![a-z])|jyada|zyada|thap[a-z]*|बढ|थप|વધ)';
        $down = '(?:ghat[a-z]*|kam(?![a-z])|decrease[a-z]*|reduce[a-z]*|less(?![a-z])|minus(?![a-z])|घट|कम|ઘટ)';
        $abs  = [];
        // "2000 se 2200 kar do" / "2000 to 2200 badhau" = from → to: the second number is the new fare
        if (preg_match_all('/(?<![\d.])\d{3,5}\s*(?:se|bata|dekhi|from|to|से|बाट|देखि|થી)\s*(\d{3,5})(?![\d.])/u', $x, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $m) {
                $abs[] = (int) $m[1];
                $x = str_replace($m[0], ' ', $x);
            }
        }
        $rel = [];
        if (preg_match_all('/(?<![\d.])(\d{1,5})' . $cur . '\s*(?:le|se|ले|से|nu|થી)?\s*(' . $up . '|' . $down . ')/u', $x, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $m) {
                $rel[] = (preg_match('/^' . $down . '/u', $m[2]) === 1 ? -1 : 1) * (int) $m[1];
            }
        }
        if (preg_match_all('/(?:^|\s)([+\-−])\s?(\d{1,5})(?![\d:])/u', $x, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $m) {
                $rel[] = ($m[1] === '+' ? 1 : -1) * (int) $m[2];
            }
        }
        $upSaid   = preg_match('/(?<![a-z])' . $up . '/u', $x) === 1;
        $downSaid = preg_match('/(?<![a-z])' . $down . '/u', $x) === 1;
        if (preg_match('/(?<![a-z])by\s+(\d{1,5})(?![\d:])/', $x, $m) === 1) {
            if ($upSaid === $downSaid) {
                return ['ask', 0.0];
            }
            $rel[] = ($downSaid ? -1 : 1) * (int) $m[1];
        }
        if (preg_match_all('/(?<![\d.])(\d{1,5})' . $cur . '\s*(?:ma|मा|par|पर|pe|પર)?\s*(?:kar[a-z]*|kr(?![a-z])|bana[a-z]*|rakh[a-z]*|gar[a-z]*|set(?![a-z])|fix(?![a-z])|कर|बना|राख|गर|કર|રાખ)/u', $x, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $m) {
                $abs[] = (int) $m[1];
            }
        }
        $rel = array_values(array_unique($rel));
        $abs = array_values(array_unique($abs));
        if (($rel !== [] && $abs !== []) || count($rel) > 1 || count($abs) > 1) {
            return ['ask', 0.0];
        }
        if ($rel !== []) {
            return ['delta', (float) $rel[0]];
        }
        if ($abs !== []) {
            return ['abs', (float) $abs[0]];
        }
        // review 16 Nov 2026: "add 100 to Surat fare" was a ₹100 fare — "add / jod" with an untied number is asked too
        if ($upSaid || $downSaid || preg_match('/(?<![a-z])(?:add|jod[a-z]*)(?![a-z])/', $x) === 1) {
            return ['ask', 0.0];
        }
        return ['abs', (float) max($nums)];
    }

    /** @return list<array<string,mixed>> fare_delta actions, or [] with a note */
    private static function deltaActions(string $t, float $delta, ?string $date, ?string $dirSaid, bool $isAll, bool $isRoute): array
    {
        if ($delta === 0.0 || abs($delta) > 5000) {
            self::$note = '🤔 एकै पटक ₹1–₹5,000 सम्म मात्र · Change a fare by ₹1 to ₹5,000 at a time.';
            return [];
        }
        if ($date !== null) {
            self::$note = '📅 एक दिनको भाडा: नयाँ भाडा भन्नुहोस् · For one date, say the new fare, e.g. "9 Nov ko bhada 2500".';
            return [];
        }
        $stops = $isAll ? [] : self::findStops($t);
        if ($stops !== []) {
            return array_map(static fn(string $s): array => ['type' => 'fare_delta', 'stop' => $s, 'delta' => $delta, 'dir' => $dirSaid ?? 'toNepal'], $stops);
        }
        if ($isAll) {
            // "sab route ka fare 100 badha do" = both ways; "sabai pickup …" = going
            return [['type' => 'fare_delta', 'stop' => '*', 'delta' => $delta, 'dir' => $dirSaid ?? ($isRoute ? 'both' : 'toNepal')]];
        }
        self::$note = self::whichPickupNote();
        return [];
    }

    /** @return array<string,string> the time (+ alt) and direction said for a date's buses */
    private static function tripFilter(string $t, ?string $dir): array
    {
        $f  = [];
        $tm = self::findTime($t);
        if ($tm !== null) {
            $f['time'] = $tm['time'];
            if ($tm['alt'] !== null) {
                $f['alt'] = $tm['alt'];
            }
        }
        if ($dir !== null) {
            $f['dir'] = $dir;
        }
        return $f;
    }

    /**
     * [$a] — or [] and a note naming that day's buses when the time said matches none
     * ("Kal 10 baje bus band" while the buses leave at 13:00 and 18:00).
     *
     * @return list<array<string,mixed>>
     */
    private static function withDeparture(array $a): array
    {
        if (!isset($a['time']) || self::dateSchedules((string) $a['date'], (array) ($a['route_ids'] ?? []), $a) !== []) {
            return [$a];
        }
        $day  = self::dateSchedules((string) $a['date'], (array) ($a['route_ids'] ?? []), array_intersect_key($a, ['dir' => 1]));
        $list = implode(', ', array_map(static fn(array $s): string => substr((string) $s['dep'], 0, 5) . ' ' . $s['from_city'] . ' → ' . $s['to_city'], $day));
        $at   = $a['time'] . (isset($a['alt']) ? ' / ' . $a['alt'] : '');
        self::$note = '🕙 ' . formatDate((string) $a['date'], 'j M') . ' ' . $at . ' मा कुनै बस छैन · No bus leaves at ' . $at . '. '
            . ($list !== '' ? 'त्यो दिन · That day: ' . $list . '.' : 'त्यो दिन बस छैन · No bus that day.');
        return [];
    }

    /**
     * "Naya pickup add karo: Gandhinagar 22:30", "Gandhinagar boarding jodnu 22:30 ₹2200"
     * (16 Nov 2026, P3-2e). The time is required (asked when missing); the fare is optional
     * (none = the direction default). Default route = the first route ON for the direction.
     *
     * @return list<array<string,mixed>>|null null = not this kind of sentence
     */
    private static function addStopAction(string $raw, string $t): ?array
    {
        $isAdd  = self::hasWord($t, ['add', 'jod', 'jodo', 'jodnu', 'jodna', 'jodiye', 'jodideu', 'thap', 'thapnu', 'thapdeu', 'naya', 'nayaa', 'new',
                                     'जोड', 'जोडो', 'जोड़ो', 'नयाँ', 'नया', 'नई', 'ઉમેરો', 'નવું', 'નવો', 'નવી']) >= 0;
        $isStop = self::hasWord($t, ['pickup', 'pick up', 'boarding', 'bus stop', 'stop point', 'location', 'point', 'sthan', 'jagah', 'jagaa', 'thau',
                                     'पिकअप', 'बोर्डिङ', 'बोर्डिंग', 'ठाउँ', 'स्थान', 'जगह', 'लोकेसन', 'लोकेशन', 'પિકઅપ', 'સ્ટોપ', 'લોકેશન', 'જગ્યા']) >= 0;
        if (!$isAdd || !$isStop) {
            return null;
        }
        if (preg_match('/(?<!\d)[:：]\s*(.+)$/u', $raw, $m) === 1) {
            $name = $m[1];                                  // "…: Gandhinagar, Gujarat"
        } elseif (preg_match('/["“‘](.{2,80}?)["”’]/u', $raw, $m) === 1) {
            $name = $m[1];                                  // "… 'Gandhinagar' …"
        } else {                                            // the words that are not command words
            $name = (string) preg_replace('/(?<![\p{L}\p{M}])(?:add|jod\p{L}*|thap\p{L}*|naya|nayaa|new|karo|kar|karna|kardo|karde|kariye|do|de|gara|garnu|garideu|please|plz'
                . '|pickup|pick|up|boarding|bus|stop|point|location|sthan|jagah|jagaa|thau|drop|ko|ka|ki|ke|ma|me|mein|par|pe|the|a|an|at|on|for|in|route|wala|wali'
                . '|jane|aaune|ek|one|पिकअप|बोर्डिङ|बोर्डिंग|ठाउँ|स्थान|जगह|लोकेसन|लोकेशन|जोड\p{L}*|जोड़ो|नयाँ|नया|नई|को|का|की|मा|में|गर्नु|गर|करो'
                . '|કરો|ઉમેરો|નવું|નવો|નવી|પિકઅપ|સ્ટોપ|લોકેશન|જગ્યા)(?![\p{L}\p{M}])/iu', ' ', $raw);
        }
        // the name never carries the time or the fare said with it
        $name = (string) preg_replace('/(?<![\d:])\d{1,2}[:.]\d{2}(?![\d:])\s*(?:a\.?m\.?|p\.?m\.?)?|(?<!\d)\d{1,2}\s*(?:baje|बजे|vagye|વાગ્યે|a\.?m\.?|p\.?m\.?)(?![a-z])/iu', ' ', $name);
        $name = (string) preg_replace('/(?:₹|rs\.?|inr)?\s*(?<![\p{L}])\d[\d,]*(?:\.\d+)?\s*(?:\/-)?/iu', ' ', $name);
        $name = (string) preg_replace('/(?<![\p{L}\p{M}])(?:bhada|fare|price|rate|time|samay|subah|savere|bihana|raat|rati|shaam|sham|beluka|dopahar|diuso'
            . '|morning|evening|night|baje|भाडा|किराया|बजे|रात|बिहान|सुबह|सांझ|साँझ|ભાડું|સવારે|સાંજે|રાત્રે)(?![\p{L}\p{M}])/iu', ' ', $name);
        // a /u trim: trim()'s byte list would also eat the last byte of "ी" / "ી" (वापी, નવસારી)
        $name = (string) preg_replace('/^[\s,.;:\-–—\/|]+|[\s,.;:\-–—\/|]+$/u', '', Security::clean((string) preg_replace('/\s+/u', ' ', $name), 191));
        // review 16 Nov 2026: "Gandhinagar, Gujarat" names the stop "Gandhinagar" — the state is not part of a ticket label
        $name = trim((string) preg_replace('/\s*,\s*(?:gujarat|gujrat|गुजरात|ગુજરાત|india|bharat|भारत|ભારત)\s*$/iu', '', $name));
        $alias = self::CITY_ALIAS[mb_strtolower($name)] ?? null;
        if ($alias !== null) {
            $name = mb_convert_case($alias, MB_CASE_TITLE);   // "ગાંધીનગર" → "Gandhinagar"
        }
        if (mb_strlen($name) < 2 || preg_match('/\p{L}/u', $name) !== 1) {
            self::$note = '📍 कुन ठाउँ? · Which place? Say e.g. "Naya pickup: Gandhinagar 22:30".';
            return [];
        }
        $tm = self::findTime($t);
        if ($tm === null) {
            self::$note = '⏰ ' . $name . ' मा बस कति बजे आउँछ? · What time is the bus at ' . $name . '? Say e.g. "Naya pickup: ' . $name . ' 22:30".';
            return [];
        }
        if ($tm['alt'] !== null) {
            self::$note = '⏰ ' . $name . ': ' . $tm['time'] . ' कि ' . $tm['alt'] . '? · Morning or night? Say the time as ' . $tm['time'] . ' or ' . $tm['alt'] . '.';
            return [];
        }
        $dir  = self::findDir($t) ?? 'toNepal';
        $type = ($dir === 'toIndia' || self::hasWord($t, ['drop', 'utarne', 'utarna', 'उतार्ने', 'उतरने', 'ड्रप', 'ड्रॉप']) >= 0) ? 'drop' : 'boarding';
        $route = null;
        foreach (self::routes() as $r) {   // a route code said ("r2", "b02-up") wins
            $code = mb_strtolower((string) $r['route_code']);
            if ((int) $r['is_active'] === 1 && $code !== '' && preg_match('/(?<![a-z0-9])' . preg_quote($code, '/') . '(?![a-z0-9])/', $t) === 1) {
                $route = $r;
                break;
            }
        }
        foreach ($route === null ? self::routes() : [] as $r) {
            if ((int) $r['is_active'] === 1 && Fare::routeDir($r) === $dir) {
                $route = $r;
                break;
            }
        }
        if ($route === null) {
            self::$note = '🛣 ' . ($dir === 'toNepal' ? 'जाने' : 'आउने') . ' कुनै रुट चालु छैन · No route is ON that way — add the stop on Routes.';
            return [];
        }
        $fare = null;
        $amts = array_values(array_filter(self::numbers($t), static fn(int $n): bool => $n >= 100));
        if ($amts !== []) {
            $fare = (float) max($amts);
            if ($fare > 20000) {
                self::$note = '💰 भाडा ₹100–₹20,000 · The fare must be ₹100 to ₹20,000.';
                return [];
            }
        }
        if (!class_exists('Boarding')) {
            require_once __DIR__ . '/boarding.php';
        }
        $key = Boarding::townKey($name);
        foreach (Database::fetchAll('SELECT id, stop_name, is_active FROM route_stops WHERE route_id = :r AND stop_type = :t',
                     ['r' => (int) $route['id'], 't' => $type]) as $s) {
            if ($key !== '' && Boarding::townKey((string) $s['stop_name']) === $key) {
                self::$note = '📍 ' . $s['stop_name'] . ' पहिल्यै छ · is already on ' . $route['route_code'] . ' (#' . $s['id'] . ((int) $s['is_active'] === 1 ? '' : ', OFF')
                    . ') — change its time / fare or switch it ON on Routes.';
                return [];
            }
        }
        return [['type' => 'add_stop', 'route_id' => (int) $route['id'], 'stop_type' => $type, 'stop_name' => $name, 'stop_time' => $tm['time'], 'fare' => $fare]];
    }

    /**
     * "Seat U15 block karo kal", "U15 unblock bholi jane" (16 Nov 2026, P3-2g): one row per
     * departure that day, through Seats::blockSeat / unblockSeat (which refuse a sold berth).
     *
     * @return list<array<string,mixed>>|null null = not this kind of sentence
     */
    private static function seatAction(string $t, ?string $date, ?string $dirSaid): ?array
    {
        if (preg_match('/(?<![a-z0-9])([lu])-?(\d{1,2})(?![\d:])/u', $t, $m) !== 1) {
            return null;
        }
        $un      = preg_match('/un\s?-?block|अनब्लक|अनब्लॉक/u', $t) === 1;
        $blockAt = self::hasWord($t, ['block', 'blok', 'band', 'banda', 'bandh', 'roka', 'rok', 'hold', 'ब्लक', 'ब्लॉक', 'बन्द', 'बंद', 'રોકો', 'બંધ']);
        $openAt  = self::hasWord($t, ['khol', 'kholdeu', 'kholo', 'open', 'free', 'mukta', 'release', 'chalu', 'खोल', 'खोलो', 'चालु', 'मुक्त', 'ખોલો', 'ચાલુ']);
        if (!$un && $blockAt < 0 && $openAt < 0) {
            return null;
        }
        $seat = strtoupper($m[1]) . (int) $m[2];
        // review 16 Nov 2026: "L1 L2 block bholi" used to block L1 only, without a word about L2
        preg_match_all('/(?<![a-z0-9])([lu])-?(\d{1,2})(?![\d:])/u', $t, $all, PREG_SET_ORDER);
        if (count(array_unique(array_map(static fn(array $x): string => strtoupper($x[1]) . (int) $x[2], $all))) > 1) {
            self::$note = '💺 एक पटकमा एउटा सिट · One seat per sentence — say e.g. "Seat ' . $seat . ' block karo kal", then the next seat.';
            return [];
        }
        if ($date === null) {
            self::$note = '📅 कुन दिनको बस? · Which date? Say e.g. "Seat ' . $seat . ' block karo kal" or "' . $seat . ' block 20 Nov".';
            return [];
        }
        $a = ['type' => 'seat_block', 'seat' => $seat, 'date' => $date, 'on' => $un || $openAt > $blockAt] + self::tripFilter($t, $dirSaid);
        if (self::withDeparture($a) === []) {
            return [];
        }
        $rows = self::dateSchedules($date, [], $a);
        if ($rows === []) {
            self::$note = '📅 ' . formatDate($date, 'j M') . ' मा बस छैन · No bus that day.';
            return [];
        }
        foreach ($rows as $s) {
            if (in_array($seat, Seats::seatIdsForSchedule((int) $s['id']), true)) {
                return [$a];
            }
        }
        self::$note = '💺 ' . $seat . ' नाम्को सिट यो बसमा छैन · There is no seat ' . $seat . ' on that bus.';
        return [];
    }

    /**
     * "bholi jane bus 2 ghanta late", "aaja aaune bus 90 min dhilo", "9 Nov 13:00 bus 1.5 ghanta
     * late" (16 Nov 2026, P8-10d). Exactly ONE departure (date + jane / aaune / a time / a town
     * narrow it); "Yes" then runs the trips board's own Delay (schedules.delay_minutes +
     * TripNotify::notifyDelay, which messages that bus's confirmed passengers once). Only while
     * the office keeps ai_trip_delay_on ON — otherwise the note says the switch is off.
     *
     * @return list<array<string,mixed>>|null null = not this kind of sentence
     */
    private static function delayAction(string $t, ?string $date, ?string $dirSaid): ?array
    {
        /* a whole word in every script (review 16 Nov 2026): hasWord() finds a Devanagari / Gujarati word
           anywhere, so "बस 02 को प्लेट GJ05AB1234" (प्लेट = plate) read as "late" and its note kept the AI
           from reading the plate; "મોડાસા" (Modasa) is not "late" either */
        if (preg_match('/(?<![\p{L}\p{M}])(?:late|delay|delayed|dhilo|dhilai|dhila|deri|ढिलो|ढिला|ढिलाइ|लेट|देरी|મોડી|મોડું|મોડા|લેટ)(?![\p{L}\p{M}])/u', $t) !== 1) {
            return null;
        }
        $mins  = self::delayMinutes($t);
        $isBus = self::hasWord($t, ['bus', 'gadi', 'gaadi', 'trip', 'बस', 'गाडी', 'गाड़ी', 'બસ']) >= 0;
        if ($mins === null && (!$isBus || self::onOff($t) !== null
                || self::hasWord($t, ['fine', 'jariwana', 'jarimana', 'penalty', 'जरिवाना', 'जुर्माना', 'bhada', 'fare', 'भाडा']) >= 0)) {
            return null;   // "late cancel fine 500", "bus late fine 500", "ai trip delay on" … are not a running bus
        }
        if (!Settings::getBool('ai_trip_delay_on', false)) {
            self::$note = '⏸ AI बाट "बस ढिलो" सन्देश बन्द छ · The AI bus-delay message is switched OFF (ai_trip_delay_on). '
                . 'Use Trips → Delay, or switch it ON in Easy Settings.';
            return [];
        }
        if ($mins === null) {
            self::$note = '⏰ कति ढिलो? · How late? Say e.g. "bholi jane bus 2 ghanta late" or "aaja aaune bus 90 min late".';
            return [];
        }
        if ($mins < 5 || $mins > 600) {
            self::$note = '⏰ ५ मिनेट देखि १० घण्टा सम्म · A delay of 5 minutes to 10 hours.';
            return [];
        }
        if ($date === null) {
            self::$note = '📅 कुन दिनको बस? · Which date? Say e.g. "aaja jane bus 2 ghanta late".';
            return [];
        }
        $f = self::tripFilter($t, $dirSaid);
        $cities = self::findCities($t);
        $ids = $cities !== [] ? array_map(static fn(array $r): int => (int) $r['id'], self::routesFor($cities, true)) : [];
        if ($cities !== [] && $ids === []) {
            self::$note = '🛣 ' . implode(', ', array_map('ucfirst', $cities)) . ' को कुनै रुट चालु छैन · No route is ON for that town.';
            return [];
        }
        $rows = self::dateSchedules($date, $ids, $f);
        $list = static fn(array $rs): string => implode(', ', array_map(static fn(array $s): string => substr((string) $s['dep'], 0, 5) . ' ' . $s['from_city'] . ' → ' . $s['to_city'], $rs));
        if ($rows === []) {
            $day = self::dateSchedules($date);
            self::$note = '🚌 ' . formatDate($date, 'j M') . ' मा त्यस्तो बस छैन · No such bus that day.'
                . ($day !== [] ? ' त्यो दिन · That day: ' . $list($day) . '.' : '');
            return [];
        }
        if (count($rows) > 1) {
            self::$note = '🚌 ' . formatDate($date, 'j M') . ' मा ' . count($rows) . ' बस · ' . count($rows) . ' buses that day: ' . $list($rows)
                . '. कुन? · Which one? Say jane / aaune or its time, e.g. "' . formatDate($date, 'j M') . ' ' . substr((string) $rows[0]['dep'], 0, 5) . ' bus 2 ghanta late".';
            return [];
        }
        if (in_array((string) $rows[0]['status'], ['departed', 'arrived'], true)) {
            self::$note = '🚌 ' . $list($rows) . ' छुटिसक्यो · has already left — passengers are on board, nothing to send.';
            return [];
        }
        return [['type' => 'trip_delay', 'schedule_id' => (int) $rows[0]['id'], 'date' => $date, 'minutes' => $mins]];
    }

    /** "2 ghanta", "1.5 hours", "90 min", "1 ghanta 30 minat", "dedh ghanta", "aadha ghanta" → minutes; null = none said. */
    public static function delayMinutes(string $t): ?int
    {
        $m = 0;
        $said = false;
        if (preg_match('/(?<![\d.:])(\d{1,2}(?:\.\d{1,2})?)\s*(?:ghanta|ghante|ghanto|ghnta|hours?|hrs?|h(?![a-z])|घण्टा|घंटा|घन्टा|કલાક)/u', $t, $x) === 1) {
            $m += (int) round((float) $x[1] * 60);
            $said = true;
        } elseif (preg_match('/(?<![a-z])(?:dedh|derh|डेढ|દોઢ)\s*(?:ghanta|ghante|hours?|घण्टा|घंटा|કલાક)/u', $t) === 1) {
            $m += 90;
            $said = true;
        } elseif (preg_match('/(?<![a-z])(?:aadha|adha|आधा|અડધો)\s*(?:ghanta|ghante|hour|घण्टा|घंटा|કલાક)/u', $t) === 1) {
            $m += 30;
            $said = true;
        }
        if (preg_match('/(?<![\d.:])(\d{1,3})\s*(?:minutes?|mins?|minat|minet|मिनेट|मिनट|મિનિટ)/u', $t, $x) === 1) {
            $m += (int) $x[1];
            $said = true;
        }
        return $said ? $m : null;
    }

    /** @return array{bookings:int, people:int, status:string, delay:int} who a delay on this departure would reach */
    private static function delayReach(int $scheduleId): array
    {
        $s = Database::fetch('SELECT status, delay_minutes FROM schedules WHERE id = :s', ['s' => $scheduleId]) ?? [];
        // the same passengers TripNotify::notifyDelay messages: confirmed, with a phone
        $r = Database::fetch(
            "SELECT COUNT(DISTINCT b.id) AS n,
                    (SELECT COUNT(*) FROM booking_passengers bp JOIN booking_legs bl2 ON bl2.id = bp.leg_id JOIN bookings b2 ON b2.id = bl2.booking_id
                      WHERE bl2.schedule_id = :s2 AND b2.status = 'confirmed' AND b2.contact_phone <> '') AS p
               FROM booking_legs bl JOIN bookings b ON b.id = bl.booking_id
              WHERE bl.schedule_id = :s1 AND b.status = 'confirmed' AND b.contact_phone <> ''",
            ['s1' => $scheduleId, 's2' => $scheduleId]
        ) ?? [];
        return ['bookings' => (int) ($r['n'] ?? 0), 'people' => (int) ($r['p'] ?? 0), 'status' => (string) ($s['status'] ?? ''), 'delay' => (int) ($s['delay_minutes'] ?? 0)];
    }

    /** "2 h 30 min" */
    private static function hm(int $mins): string
    {
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        return ($h > 0 ? $h . ' h' : '') . ($h > 0 && $m > 0 ? ' ' : '') . ($m > 0 || $h === 0 ? $m . ' min' : '');
    }

    /* =================================================================
     *  Offers / coupons by sentence (16 Nov 2026, Master v2 unit Y2 —
     *  P3-2f + P8-10e). The office's own words only: parseAi() drops
     *  these types. A NEW offer is held to admin/offers.php's rules
     *  (OfferRules) and always written OFF, never auto-applied; an ACTIVE
     *  offer may be switched OFF; none is ever switched ON from here.
     * ================================================================= */

    /** an offer is meant — never a bare "discount": "group / return discount" are switches */
    private const COUPON_WORDS = ['coupon', 'coupons', 'coupan', 'cupon', 'kupan', 'kupon', 'promo', 'promo code', 'promocode', 'offer', 'offers', 'ofar', 'offar',
                                  'discount code', 'chhut code', 'chut code', 'कुपन', 'कूपन', 'अफर', 'ऑफर', 'ओफर', 'प्रोमो', 'छुट कोड', 'ઓફર', 'કૂપન', 'કુપન'];
    /** "naya / new / banau": a new offer is meant even before its amount is said */
    private const COUPON_NEW = ['naya', 'nayaa', 'new', 'banau', 'banao', 'banaau', 'banaideu', 'banaunu', 'banaye', 'create', 'make',
                                'नयाँ', 'नया', 'नई', 'नयां', 'बनाउ', 'बनाऊ', 'बनाओ', 'बनाउनु', 'નવું', 'નવો', 'નવી', 'બનાવો'];
    /** the words around a code that are never the code itself */
    private const COUPON_STOP = ['band', 'banda', 'bandh', 'bnd', 'off', 'on', 'chalu', 'chalau', 'khol', 'naya', 'nayaa', 'new', 'banau', 'banao', 'banaau', 'banaideu',
                                 'banaunu', 'bana', 'create', 'make', 'add', 'max', 'maximum', 'upto', 'till', 'until', 'from', 'tak', 'samma', 'sama', 'dekhi', 'bata',
                                 'the', 'for', 'and', 'aur', 'percent', 'pct', 'flat', 'code', 'kod', 'coupon', 'coupons', 'coupan', 'cupon', 'kupan', 'kupon', 'offer',
                                 'offers', 'ofar', 'offar', 'promo', 'promocode', 'karo', 'kardo', 'gara', 'garnu', 'garideu', 'please', 'plz', 'disable', 'stop', 'hatau',
                                 'hatao', 'deactivate', 'roka', 'rokdeu', 'close', 'this', 'name', 'naam', 'with', 'valid', 'chhut', 'chut', 'discount', 'jana', 'jaana',
                                 'log', 'people', 'uses', 'times', 'choti', 'patak', 'baar', 'per', 'user', 'mobile', 'min', 'minimum', 'limit', 'route', 'only', 'matra',
                                 'sirf', 'all', 'sabai', 'today', 'aaja', 'bholi', 'tomorrow', 'link', 'message', 'inbox', 'whatsapp', 'enable', 'start', 'activate',
                                 'open', 'lagau', 'rupees', 'rupaiya', 'rupaye', 'inr', 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'sept', 'oct', 'nov',
                                 'dec', 'january', 'february', 'march', 'april', 'june', 'july', 'august', 'september', 'october', 'november', 'december', 'ticket',
                                 'tickets', 'seat', 'seats', 'customer', 'customers', 'sabko', 'sabhi', 'every', 'kul', 'total', 'yatri', 'passenger', 'passengers'];
    /** the little words of an offer sentence that never name WHICH offer, nor are its code (Y2 review) */
    private const COUPON_FILLER = ['karo', 'kar', 'kardo', 'kardijiye', 'kijiye', 'karde', 'karna', 'karnu', 'karne', 'do', 'de', 'dey', 'deu', 'dinu', 'dinus',
                                   'dinuhos', 'dinos', 'dijiye', 'gara', 'garnu', 'garideu', 'garidinu', 'gardinu', 'garnuhos', 'garau', 'please', 'plz', 'pls', 'the',
                                   'this', 'that', 'yo', 'yah', 'ye', 'is', 'it', 'ko', 'ka', 'ki', 'ke', 'lai', 'ma', 'me', 'mein', 'pe', 'par', 'now', 'abhi', 'aba',
                                   'ahile', 'ab', 'turant', 'hai', 'cha', 'chha', 'ho', 'wala', 'wali', 'vala', 'vali', 'chahiyo', 'chahiye', 'chaiyo', 'chaiye',
                                   'lagi', 'for', 'code', 'kod', 'naam', 'name',
                                   'गर', 'गर्नु', 'गरिदेउ', 'गरिदिनु', 'गर्नुहोस्', 'गर्नुस्', 'करो', 'कर', 'करें', 'दो', 'दें', 'दीजिए', 'दिनु', 'दिनुस्', 'दिनुहोस्',
                                   'को', 'का', 'की', 'के', 'लाई', 'मा', 'में', 'यो', 'यह', 'अब', 'अहिले', 'अभी', 'है', 'छ', 'हो', 'वाला', 'वाली', 'कोड', 'नाम',
                                   'કરો', 'કર', 'હવે', 'ને', 'નો', 'ની', 'નું', 'કોડ'];
    /** a discount said in so many words: "10%", "₹100 off", "50 chhut", "flat 50" (a fare sentence needs one to be an offer) */
    private const COUPON_DISCOUNT_RE = '/\d\s*(?:%|percent|per ?cent|pct|pratishat|pratisat|प्रतिशत|फीसदी|ટકા)'
                                     . '|\d\s*(?:₹|rs\.?|rupaiya|rupiya|rupees?|rupaye|रु\.?)?\s*(?:off|chhut|chut|chhoot|discount|छुट|छूट|ऑफ|ओफ)(?![a-z])'
                                     . '|(?<![a-z])(?:flat|sidha|सिधा)\s*(?:₹|rs\.?)?\s*\d/u';
    /** "route" as the office says it (the same words as parseWords' $isRoute) */
    private const COUPON_ROUTE_WORDS = ['route', 'routes', 'rut', 'root', 'रुट', 'रूट', 'line', 'लाइन'];
    /** the AI's own ceiling for an offer; a bigger one is made by a person on admin/offers.php */
    public const COUPON_MAX_PCT  = 50;
    public const COUPON_MAX_FLAT = 5000;
    private const COUPON_MONTHS  = 'january|february|march|april|june|july|august|september|october|november|december|sept|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec'
                                 . '|जनवरी|फरवरी|मार्च|अप्रैल|मई|जून|जुलाई|अगस्त|सितम्बर|अक्टूबर|नवम्बर|नोभेम्बर|दिसम्बर|डिसेम्बर';

    /**
     * "coupon DIWALI10 10% max 200 till 30 Nov", "offer 5% max ₹100 20 Nov to 30 Nov",
     * "naya coupon SHG50 ₹50 off 50 jana", "offer band DIWALI10".
     *
     * @param bool $needDiscount a fare / extra-seat word is in the sentence: an offer only when a discount is said
     * @return list<array<string,mixed>>|null null = not an offer sentence; [] = one with a note
     */
    private static function couponAction(string $raw, string $t, bool $needDiscount = false): ?array
    {
        // a setting's own key is no offer word: "btn_offer_ribbon_on band", "mk_auto_offer" stay rule 8 / the AI (Y2 review)
        $tw = (string) preg_replace('/(?<![a-z0-9])[a-z0-9]+(?:_[a-z0-9]+)+(?![a-z0-9])/u', ' ', $t);
        if (self::hasWord($tw, self::COUPON_WORDS) < 0) {
            return null;
        }
        $r = strtr($raw, self::DIGITS);
        // lower-case, ₹ and % kept: "1,000" is one amount, "200/-" is ₹200
        $c = (string) preg_replace_callback('/(?<![\d,])\d{1,3}(?:,\d{2,3})*,\d{3}(?![\d,])/u', static fn(array $m): string => str_replace(',', '', $m[0]), $r);
        $c = (string) preg_replace('/(\d)\s*\/-/u', '$1 rs ', $c);
        $c = ' ' . mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', str_replace(',', ' ', $c)))) . ' ';
        if ($needDiscount && preg_match(self::COUPON_DISCOUNT_RE, $c) !== 1) {
            return null;   // "Diwali offer Surat ko bhada ₹2000" = Surat's fare, never a ₹2,000-off offer (Y2 review)
        }

        $routeCodes = [];
        foreach (self::routes() as $rt) {
            if (trim((string) $rt['route_code']) !== '') {
                // an OFF route too ("route r1"): admin/offers.php takes one, and it must never fall back to every route (Y2 review)
                $routeCodes[mb_strtolower(trim((string) $rt['route_code']))] = $rt;
            }
        }
        [$explicit, $any] = self::couponTokens($r, array_keys($routeCodes));
        $blank = static fn(string $s, string $word): string => (string) preg_replace_callback('/(?<![a-z0-9_\-])' . preg_quote(mb_strtolower($word), '/') . '(?![a-z0-9_\-])/u',
            static fn(array $m): string => str_repeat(' ', strlen($m[0])), $s);

        // the offer the sentence names, if one exists already
        $found = null;
        foreach (array_unique(array_merge($explicit, array_map('strtoupper', $any))) as $tok) {
            $row = Database::fetch('SELECT id, code, title, discount_type, discount_value, max_discount, is_active FROM coupons WHERE code = :c', ['c' => $tok]);
            if ($row !== null) {
                $found = $row;
                break;
            }
        }
        // the code for a NEW offer: said after "coupon / code …", else one typed like a code (DIWALI10, SHG50, WELCOME)
        $code = $explicit[0] ?? null;
        if ($code === null) {
            foreach ($any as $x) {
                if (self::couponCodeLike($x, 4)) {
                    $code = strtoupper($x);
                    break;
                }
            }
        }
        if ($explicit === [] && $found === null && self::parseSwitches($raw) !== []) {
            return null;   // "offer link on", "offer message band", "group discount offer 8 percent" are one-tap switches
        }
        foreach ([$code, $found['code'] ?? null] as $w) {
            // a town said as the code ("coupon code SURAT") stays in the sentence, so its route is still asked
            if ($w !== null && (preg_match('/\d/', (string) $w) === 1 || !self::couponPlace(mb_strtolower((string) $w)))) {
                $c = $blank($c, (string) $w);
            }
        }
        // a route named by its code ("route r2")
        $route = null;
        foreach ($routeCodes as $rc => $rt) {
            if (preg_match('/(?<![a-z0-9_\-])' . preg_quote($rc, '/') . '(?![a-z0-9_\-])/u', $c) === 1) {
                $route = $rt;
                $c = $blank($c, $rc);
                break;
            }
        }
        $dates = self::couponDates($c);
        $n     = self::couponNumbers($c);
        $oo    = self::onOff(self::couponUnword($tw));   // "अफर" holds the OFF word "अफ" — the offer word itself is no verb
        $isNew = self::hasWord($tw, self::COUPON_NEW) >= 0;

        /* no amount: switch an offer OFF ("offer band DIWALI10"), never ON */
        if ($n['pct'] === [] && $n['flat'] === []) {
            $never = '🔒 AI ले अफर चालु गर्दैन · The AI never switches an offer ON — switch it ON on the Offers page (admin/offers.php).';
            if ($found !== null && !$isNew) {
                if ($oo === false) {
                    if ((int) $found['is_active'] !== 1) {
                        self::$note = '🏷 ' . $found['code'] . ' पहिल्यै बन्द छ · is already OFF.';
                        return [];
                    }
                    return [['type' => 'coupon_off', 'coupon_id' => (int) $found['id'], 'code' => (string) $found['code']]];
                }
                self::$note = $oo === true ? $never
                    : '🏷 ' . $found['code'] . ' अहिले ' . ((int) $found['is_active'] === 1 ? 'ON' : 'OFF') . ' छ · is ' . ((int) $found['is_active'] === 1 ? 'ON' : 'OFF')
                      . ' now. बन्द गर्न · To stop it: "offer band ' . $found['code'] . '".';
                return [];
            }
            if ($isNew) {
                self::$note = $found !== null
                    ? '🏷 ' . $found['code'] . ' कोडको अफर पहिल्यै छ · An offer with code ' . $found['code'] . ' already exists — choose another code.'
                    : '💸 कति छुट? · How much off? Say e.g. "coupon DIWALI10 10% max 200 till 30 Nov" or "naya coupon SHG50 ₹50 off 50 jana".';
                return [];
            }
            if ($oo === null) {
                return null;
            }
            /* Y2 review: only a sentence that names an offer gets an offer's answer. Words left over that
               name something else ("offer ribbon band", "promo message band", "Diwali offer: bholi ko bus
               band") go on to the other rules and the AI, as before. */
            $rest = self::couponRest($tw, [(string) $code, (string) ($found['code'] ?? '')]);
            // the one word that may start an offer's code: "dashain offer band karo", "offer band DASHAIN" = DASHAIN10 (shown, and only after "Yes")
            $word = $rest === [] ? (string) $code : ($code === null && count($rest) === 1 ? $rest[0] : '');
            if (preg_match('/^[a-z][a-z0-9]{2,19}$/i', $word) === 1) {
                $hits = Database::fetchAll('SELECT id, code, is_active FROM coupons WHERE code LIKE :p ORDER BY is_active DESC, id DESC LIMIT 8', ['p' => strtoupper($word) . '%']);
                if ($hits !== []) {
                    $on = array_values(array_filter($hits, static fn(array $h): bool => (int) $h['is_active'] === 1));
                    if ($oo === true) {
                        self::$note = $never;
                        return [];
                    }
                    if (count($on) === 1) {
                        return [['type' => 'coupon_off', 'coupon_id' => (int) $on[0]['id'], 'code' => (string) $on[0]['code']]];
                    }
                    self::$note = $on === []
                        ? '🏷 ' . implode(', ', array_column($hits, 'code')) . ' पहिल्यै बन्द छ · is already OFF.'
                        : '🏷 कुन अफर? · Which offer? Say its code: ' . implode(', ', array_column($on, 'code')) . '.';
                    return [];
                }
            }
            if ($rest !== []) {
                return null;
            }
            if ($oo === true) {
                self::$note = $never;
            } elseif ($code !== null) {
                self::$note = '🏷 ' . $code . ' कोडको अफर भेटिएन · No offer with code ' . $code . '.';
            } else {
                $on = array_column(Database::fetchAll('SELECT code FROM coupons WHERE is_active = 1 ORDER BY id DESC LIMIT 8'), 'code');
                self::$note = '🏷 कुन अफर? · Which offer? Say its code, e.g. "offer band ' . ($on[0] ?? 'DIWALI10') . '".'
                    . ($on !== [] ? ' अहिले ON · ON now: ' . implode(', ', $on) . '.' : ' कुनै अफर ON छैन · No offer is ON.');
            }
            return [];
        }

        /* an amount: a NEW offer */
        if (count($n['pct']) + count($n['flat']) > 1) {
            self::$note = '💸 एउटा अफरमा एउटै छुट · One discount per offer — say "10% max 200" or "₹50 off".';
            return [];
        }
        if ($n['left'] !== []) {
            self::$note = '🤔 "' . $n['left'][0] . '" के हो? · What is ' . $n['left'][0] . '? Say e.g. "max 200", "50 jana", "till 30 Nov", "min 1000".';
            return [];
        }
        foreach (['cap' => 'max', 'min' => 'min', 'uses' => 'jana', 'per' => 'per mobile'] as $k => $w) {
            if (count($n[$k]) > 1) {
                self::$note = '🤔 दुई वटा "' . $w . '" · Two "' . $w . '" amounts — say one.';
                return [];
            }
        }
        if ($dates['err'] !== '') {
            self::$note = $dates['err'];
            return [];
        }
        /* the route: by its code, else by its town(s). A place or a "route" word that names no single route
           is asked — never silently widened to every route (Y2 review: "offer Rajkot route ₹100 off"). */
        if ($route === null) {
            $nc     = self::norm($c);
            $cities = self::findCities($nc);
            $list   = static fn(array $rs): string => implode(', ', array_map(static fn(array $x): string => (string) $x['route_code'] . ' ' . $x['from_city'] . ' → ' . $x['to_city'], $rs));
            if ($cities !== []) {
                $rs = self::routesFor($cities, true);
                if (count($rs) !== 1) {
                    self::$note = $rs === []
                        ? '🛣 ' . implode(', ', array_map('ucfirst', $cities)) . ' को कुनै रुट चालु छैन · No route is ON for that town.'
                        : '🛣 कुन रुट? · Which route? Say its code, e.g. "route ' . $rs[0]['route_code'] . '" (' . $list($rs) . ').';
                    return [];
                }
                $route = $rs[0];
            } elseif (($stops = self::findStops($nc)) !== []) {
                self::$note = '📍 अफर pickup अनुसार होइन, रुट अनुसार हुन्छ · An offer is per route, not per pickup (' . implode(', ', $stops)
                    . '). Say the route code, e.g. "route r2", or leave the place out for every route.';
                return [];
            } elseif (($towns = array_values(array_unique(array_filter(preg_split('/[^\p{L}]+/u', $nc, -1, PREG_SPLIT_NO_EMPTY) ?: [],
                    static fn(string $w): bool => isset(self::knownTowns()[$w]))))) !== []) {
                // a town with no route and no pickup ("offer Baroda ₹100 off") — asked, never every route
                self::$note = '🛣 ' . implode(', ', array_map('ucfirst', $towns)) . ' को रुट भेटिएन · No route found for ' . implode(', ', array_map('ucfirst', $towns))
                    . '. Say the route code, e.g. "route r2", or leave the place out for every route.';
                return [];
            } elseif (self::hasWord($nc, self::COUPON_ROUTE_WORDS) >= 0 && self::hasWord($nc, self::ALL_WORDS) < 0) {
                $on = array_values(array_filter(self::routes(), static fn(array $x): bool => (int) $x['is_active'] === 1));
                self::$note = '🛣 कुन रुट? · Which route? Say its code, e.g. "route ' . ($on[0]['route_code'] ?? 'r2') . '"'
                    . ($on !== [] ? ' (' . $list($on) . ')' : '') . ', or "sabai route" for every route.';
                return [];
            }
        }
        $pct  = $n['pct'] !== [];
        $val  = $pct ? $n['pct'][0] : $n['flat'][0];
        $auto = $code === null;
        $a = [
            'type'           => 'coupon',
            'code'           => $code ?? self::couponAutoCode($pct ? 'percent' : 'flat', $val, $dates['from']),
            'discount_type'  => $pct ? 'percent' : 'flat',
            'discount_value' => $val,
            'max_discount'   => $n['cap'][0] ?? null,
            'min_amount'     => $n['min'][0] ?? 0.0,
            'valid_from'     => $dates['from'],
            'valid_until'    => $dates['until'],
            'usage_limit'    => isset($n['uses'][0]) ? (int) $n['uses'][0] : null,
            'per_user_limit' => isset($n['per'][0]) ? (int) $n['per'][0] : 1,
            'route_id'       => $route !== null ? (int) $route['id'] : null,
            'auto_code'      => $auto,
        ];
        [$err, $clean] = self::couponCheck($a);
        if ($err !== null) {
            self::$note = $err;
            return [];
        }
        return [$clean];
    }

    /**
     * Word-like tokens that could be a code: said as one (explicit, upper-cased), and every other one
     * (case kept). Y2 review: right after "code / naam" any word is the code; right after "coupon /
     * offer / promo" only one typed like a code (DIWALI10, SHG50, WELCOME) — "offer dinus / wala /
     * chahiyo" are ordinary words, and a town or a pickup ("offer Rajkot route", "offer Surat ko
     * bhada") is never a code unless it carries a digit (SURAT50) or "code" names it.
     *
     * @param list<string> $routeCodes lower-case route codes, ON or OFF (never a coupon code)
     * @return array{0:list<string>, 1:list<string>}
     */
    private static function couponTokens(string $r, array $routeCodes): array
    {
        $ok = static function (string $tok, bool $named) use ($routeCodes): bool {
            $l = strtolower($tok);
            if (preg_match('/[a-z]/', $l) !== 1 || in_array($l, self::COUPON_STOP, true) || in_array($l, self::COUPON_FILLER, true) || in_array($l, $routeCodes, true)) {
                return false;
            }
            if (!$named && preg_match('/\d/', $l) !== 1 && self::couponPlace($l)) {
                return false;
            }
            $mon = 'jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec';
            return preg_match('/^\d{1,2}(?:st|nd|rd|th)?(?:' . $mon . ')[a-z]*$|^(?:' . $mon . ')[a-z]*\d{1,4}$/', $l) !== 1       // 30nov, nov30
                && preg_match('/^(?:rs|inr|max|min|upto)\d+$|^\d+(?:\.\d+)?(?:rs|inr|rupees?|rupaiya|jana|log|people|times?|x|pct|percent|off|baje|min|mins|hrs?|h|am|pm|st|nd|rd|th|k)$/', $l) !== 1
                && preg_match('/^[lu]\d{1,2}$/', $l) !== 1;                                                                        // a berth (U15)
        };
        $explicit = [];
        // [1] "code" after a coupon word, [2] "code / naam" alone, [3] the word said as the code
        if (preg_match_all('/(?<![\p{L}\p{N}])(?:(?:coupon|coupan|cupon|kupan|kupon|promo|offer|ofar|offar|कुपन|कूपन|अफर|ऑफर|ओफर|ઓફર|કૂપન|કુપન)(?:\s+(code|kod|naam|name|कोड|नाम|કોડ))?'
                . '|(code|kod|naam|name|कोड|नाम|કોડ))(?:\s*[:#=]\s*|\s+)["\'“‘]?([A-Za-z0-9][A-Za-z0-9_\-]{2,39})(?![A-Za-z0-9_\-])/iu', $r, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $m) {
                $tok   = (string) ($m[3] ?? '');
                $named = ($m[1] ?? '') !== '' || ($m[2] ?? '') !== '';
                if ($tok !== '' && $ok($tok, $named) && ($named || self::couponCodeLike($tok, 3))) {
                    $explicit[] = strtoupper($tok);
                }
            }
        }
        $any = [];
        if (preg_match_all('/(?<![A-Za-z0-9_\-₹])([A-Za-z0-9][A-Za-z0-9_\-]{2,39})(?![A-Za-z0-9_\-])/u', $r, $mm) > 0) {
            foreach ($mm[1] as $tok) {
                if ($ok($tok, false)) {
                    $any[] = $tok;
                }
            }
        }
        return [array_values(array_unique($explicit)), array_values(array_unique($any))];
    }

    /** Typed like a code: letters with a digit (DIWALI10, shg50), or capitals ($capsMin+ long: WELCOME). */
    private static function couponCodeLike(string $x, int $capsMin): bool
    {
        return (preg_match('/[A-Za-z]/', $x) === 1 && preg_match('/\d/', $x) === 1)
            || (strlen($x) >= $capsMin && preg_match('/[A-Z]/', $x) === 1 && $x === strtoupper($x));
    }

    /** A town or a pickup's own word (lower-case): never taken for a code (Y2 review). */
    private static function couponPlace(string $l): bool
    {
        static $words = null;
        if ($words === null) {
            $words = self::knownTowns();
            foreach (array_keys(self::CITY_ALIAS) as $k) {
                if (preg_match('/^[a-z]+$/', (string) $k) === 1) {
                    $words[(string) $k] = true;   // baroda, amdavad, rupaidiya …
                }
            }
            foreach (Database::fetchAll("SELECT DISTINCT stop_name FROM route_stops WHERE stop_name <> ''") as $s) {
                $key = mb_strtolower(trim((string) preg_replace('/(?:[,(]| [-—–] ).*$/u', '', (string) $s['stop_name'])));
                foreach (preg_split('/[^\p{L}]+/u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                    if (mb_strlen($w) >= 4) {
                        $words[$w] = true;   // kamrej, limbli, bhupal, barauda …
                    }
                }
            }
        }
        return isset($words[$l]);
    }

    /** $tw without the offer words themselves ("अफर" holds the OFF word "अफ"). */
    private static function couponUnword(string $s): string
    {
        $words = array_map(static fn(string $w): string => mb_strtolower($w), self::COUPON_WORDS);
        usort($words, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));   // "promo code" before "promo"
        foreach ($words as $w) {
            $s = preg_match('/^[a-z ]+$/', $w) === 1
                ? (string) preg_replace('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/u', ' ', $s)
                : str_replace($w, ' ', $s);
        }
        return $s;
    }

    /**
     * The words of an offer sentence that are not the offer word, an on / off / "new" verb, a
     * little filler word or one of $codes: what else the sentence talks about. [] = it names
     * an offer and nothing else ("offer band", "अफर बन्द गर", "offer band XYZ99").
     *
     * @param list<string> $codes
     * @return list<string> lower-case
     */
    private static function couponRest(string $tw, array $codes): array
    {
        $s = self::couponUnword(' ' . $tw . ' ');
        $multi = [];
        foreach (array_merge(self::ON, self::OFF) as $w) {
            if (str_contains($w, ' ')) {
                $multi[] = mb_strtolower($w);
            }
        }
        foreach ($multi as $w) {   // "chalu gara", "na chalau", "બંધ કરો"
            $s = preg_match('/^[a-z ]+$/', $w) === 1 ? (string) preg_replace('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/u', ' ', $s) : str_replace($w, ' ', $s);
        }
        $drop = [];
        foreach (array_merge(self::ON, self::OFF, self::COUPON_NEW, self::COUPON_FILLER, self::ALL_WORDS, $codes) as $w) {
            if ($w !== '') {
                $drop[mb_strtolower($w)] = true;
            }
        }
        $out = [];
        foreach (preg_split('/[^\p{L}\p{M}\p{N}_\-]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $w = trim($w, '-_');
            if ($w !== '' && !isset($drop[$w])) {
                $out[] = $w;
            }
        }
        return $out;
    }

    /**
     * The offer's dates said in $c, blanked out of it (same byte length): "till 30 Nov", "20 Nov to
     * 30 Nov", "30-11", "2026-11-30", "aaja dekhi 30 nov samma". Two dates = from → until; one date
     * = until, unless "dekhi / from" marks it the start.
     *
     * @return array{from:?string, until:?string, err:string}
     */
    private static function couponDates(string &$c): array
    {
        $today = todayISO();
        $found = [];   // [start byte, end byte, iso|null]
        $mk = static function (int $d, int $mo, ?int $y) use ($today): ?string {
            $yy = $y === null ? (int) substr($today, 0, 4) : ($y < 100 ? 2000 + $y : $y);
            if (!checkdate($mo, $d, $yy)) {
                return null;
            }
            $iso = sprintf('%04d-%02d-%02d', $yy, $mo, $d);
            if ($y === null && $iso < addDaysISO($today, -60)) {   // "5 Jan" said in November = next year
                $iso = sprintf('%04d-%02d-%02d', $yy + 1, $mo, $d);
            }
            return $iso;
        };
        $mon = static function (string $w): int {
            $dev = ['जनवरी' => 1, 'फरवरी' => 2, 'मार्च' => 3, 'अप्रैल' => 4, 'मई' => 5, 'जून' => 6, 'जुलाई' => 7, 'अगस्त' => 8, 'सितम्बर' => 9, 'अक्टूबर' => 10,
                    'नवम्बर' => 11, 'नोभेम्बर' => 11, 'दिसम्बर' => 12, 'डिसेम्बर' => 12];
            return $dev[$w] ?? (int) (array_search(substr($w, 0, 3), ['', 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true) ?: 0);
        };
        $grab = static function (string $re, callable $iso) use (&$c, &$found): void {
            if (preg_match_all($re, $c, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                foreach ($mm as $m) {
                    $g = array_map(static fn(array $x): string => (string) $x[0], $m);
                    $found[] = [(int) $m[0][1], (int) $m[0][1] + strlen($m[0][0]), $iso($g)];
                    $c = substr_replace($c, str_repeat(' ', strlen($m[0][0])), (int) $m[0][1], strlen($m[0][0]));
                }
            }
        };
        $M   = self::COUPON_MONTHS;
        $pct = '(?!\s?(?:%|percent|pct|pratishat|प्रतिशत|फीसदी))';
        $grab('/(?<![\d\-\/])(20\d\d)-(\d{1,2})-(\d{1,2})(?![\d\-\/])/', static fn(array $g): ?string => $mk((int) $g[3], (int) $g[2], (int) $g[1]));
        $grab('/(?<![\d\-\/.])(\d{1,2})[\-\/](\d{1,2})(?:[\-\/](20\d\d|\d\d))?(?![\d\-\/])' . $pct . '/', static function (array $g) use ($mk): ?string {
            [$d, $mo] = [(int) $g[1], (int) $g[2]];
            if ($mo > 12 && $d <= 12) {
                [$d, $mo] = [$mo, $d];   // "11-30" = 30 Nov
            }
            return $mk($d, $mo, ($g[3] ?? '') !== '' ? (int) $g[3] : null);
        });
        $grab('/(?<![\d.])(\d{1,2}) ?(?:st|nd|rd|th|tarik|गते)? ?(?:of )?(' . $M . ')(?![a-z])(?: ?(20\d\d))?/u',
            static fn(array $g): ?string => $mk((int) $g[1], $mon($g[2]), ($g[3] ?? '') !== '' ? (int) $g[3] : null));
        $grab('/(?<![a-z])(' . $M . ')\.? ?(\d{1,2})(?![\d.])' . $pct . '(?! ?(?:rs|₹|rupaiya|rupees?|jana|log|people|uses|times|off)(?![a-z]))(?: ?(20\d\d))?/u',
            static fn(array $g): ?string => $mk((int) $g[2], $mon($g[1]), ($g[3] ?? '') !== '' ? (int) $g[3] : null));
        $grab('/(?<![a-z])(?:aaja|aaj|today|आज|આજે)(?![a-z])/u', static fn(array $g): ?string => $today);
        $grab('/(?<![a-z])(?:bholi|tomorrow|kal|भोलि|भोली|कल|કાલે)(?![a-z])/u', static fn(array $g): ?string => addDaysISO($today, 1));
        $grab('/(?<![a-z])(?:parsi|parso|parson|पर्सि|पर्सी|परसों)(?![a-z])/u', static fn(array $g): ?string => addDaysISO($today, 2));

        $out = ['from' => null, 'until' => null, 'err' => ''];
        if ($found === []) {
            return $out;
        }
        usort($found, static fn(array $x, array $y): int => $x[0] <=> $y[0]);
        if (count($found) > 2) {
            $out['err'] = '📅 एउटा सुरु र एउटा अन्त्य मिति मात्र · Say one start and one end date, e.g. "20 Nov to 30 Nov".';
            return $out;
        }
        foreach ($found as $f) {
            if ($f[2] === null) {
                $out['err'] = '📅 मिति मिलेन · That is not a real date — say e.g. "30 Nov", "30-11" or "2026-11-30".';
                return $out;
            }
        }
        $role = static function (array $f) use ($c): ?string {
            $after  = substr($c, $f[1], 40);
            $before = substr($c, max(0, $f[0] - 30), min(30, $f[0]));
            if (preg_match('/^\s*(?:dekhi|bata|se|from|onwards?|देखि|बाट|से|થી)(?![a-z])/u', $after) === 1
                || preg_match('/(?:from|starting|starts?|suru|shuru|सुरु|शुरू)\s*$/u', $before) === 1) {
                return 'from';
            }
            if (preg_match('/^\s*(?:tak|tk|samma|sama|sammo|sudhi|सम्म|तक|સુધી)(?![a-z])/u', $after) === 1
                || preg_match('/(?:till|until|upto|up to|expiry|expires|ends?)\s*$/u', $before) === 1) {
                return 'until';
            }
            return null;
        };
        if (count($found) === 2) {
            [$a, $b] = $found;
            if ($role($a) === 'until' && $role($b) === 'from') {
                [$a, $b] = [$b, $a];
            }
            $out['from']  = $a[2];
            $out['until'] = $b[2];
        } elseif ($role($found[0]) === 'from') {
            $out['from'] = $found[0][2];
        } else {
            $out['until'] = $found[0][2];
        }
        return $out;
    }

    /**
     * The amounts of an offer sentence (dates and the code already blanked out): each kind is
     * taken by its own word and blanked, so what is left over is a number nobody named.
     *
     * @return array{pct:list<float>, flat:list<float>, cap:list<float>, min:list<float>, uses:list<int>, per:list<int>, left:list<string>}
     */
    private static function couponNumbers(string $c): array
    {
        $take = static function (string $re) use (&$c): array {
            $vals = [];
            if (preg_match_all($re, $c, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                foreach ($mm as $m) {
                    $vals[] = (float) $m[1][0];
                    $c = substr_replace($c, str_repeat(' ', strlen($m[0][0])), (int) $m[0][1], strlen($m[0][0]));
                }
            }
            return $vals;
        };
        $N    = '(\d{1,7}(?:\.\d{1,2})?)(?![\d.])';
        $RS   = '(?:₹|(?<![a-z])rs\.?|(?<![a-z])inr|रु\.?)';
        $RUP  = '(?:₹|rs\.?|rupaiya|rupiya|rupees?|rupaye|rupya|रुपैयाँ|रुपैया|रुपये|रुपया|रु|inr)(?![a-z])';
        $PCT  = '(?:%|percent|per ?cent|pct|pratishat|pratisat|प्रतिशत|फीसदी|ટકા)';
        $WHO  = '(?:user|mobile|number|customer|person|jana|phone|grahak|yatri|यात्रु|जना|मोबाइल|नम्बर|ग्राहक)';
        $TIME = '(?:choti|chot|patak|baar|bar|times?|पटक|बार|वटा)';
        $out = [];
        $out['min'] = $take('/(?<![a-z])(?:minimum|min|kamtima|kam ?se ?kam|न्यूनतम|कम्तीमा|कम से कम)(?![a-z]) ?(?:booking|ticket|amount|order|ko|ka|ki|को|का)? ?[:=]? ?' . $RS . '? ?' . $N . '(?! ?' . $PCT . ')(?: ?' . $RUP . ')?/u');
        $out['per'] = array_merge(
            $take('/(?<![a-z])(?:per|prati|har|हर|प्रति) ?' . $WHO . ' ?(?:ko|lai|ke|ka|को|लाई)? ?[:=]? ?(\d{1,3})(?![\d.])(?: ?' . $TIME . '(?![a-z]))?/u'),
            $take('/(?<![\d.a-z])(\d{1,3}) ?' . $TIME . ' ?(?:per|prati|प्रति|har|हर|ek|एक) ?' . $WHO . '(?![a-z])/u'),
            $take('/(?<![a-z])(?:ek|एक) ?' . $WHO . ' ?(?:ko|lai|le|ke|ka|को|लाई|ले)? ?(\d{1,3}) ?' . $TIME . '(?![a-z])/u')
        );
        $out['uses'] = array_merge(
            $take('/(?<![a-z])(?:max ?uses?|uses?|usage|use ?limit|usage ?limit|kul|कुल)(?![a-z]) ?[:=]? ?(\d{1,7})(?![\d.])(?! ?' . $PCT . ')(?! ?' . $RUP . ')/u'),
            $take('/(?<![\d.a-z])(\d{1,7}) ?(?:jana|jaana|janalai|janako|log|logon|logo|people|persons?|customers?|users?|passengers?|yatri|uses|times|choti|patak|baar|bar|seats?|tickets?'
                . '|जना|जनालाई|जनाको|लोग|लोगों|यात्रु|पटक|बार|टिकट|જણ|લોકો|વખત)(?![a-z])/u')
        );
        $out['cap'] = array_merge(
            $take('/(?<![a-z])(?:maximum|max|upto|up ?to|cap|badhima|badhi ?ma|jyada ?se ?jyada|zyada ?se ?zyada|adhiktam|अधिकतम|बढीमा|बढी ?मा|ज्यादा ?से ?ज्यादा|વધુમાં ?વધુ)(?![a-z])'
                . ' ?[:=]? ?(?:discount|chhut|छुट)? ?' . $RS . '? ?' . $N . '(?! ?' . $PCT . ')(?: ?' . $RUP . ')?/u'),
            $take('/(?:₹ ?|(?<![a-z])rs\.? ?|(?<![a-z])inr ?)?(?<![\d.a-z])' . $N . ' ?(?:' . $RUP . ')? ?(?:samma|sama|sammo|tak|tk|sudhi|सम्म|तक|સુધી)(?![a-z])/u')
        );
        $out['pct']  = $take('/(?<![\d.a-z])(\d{1,3}(?:\.\d{1,2})?) ?' . $PCT . '/u');
        $out['flat'] = array_merge(
            $take('/' . $RS . ' ?' . $N . '/u'),
            $take('/(?<![\d.a-z])' . $N . ' ?(?:rs\.?|rupaiya|rupiya|rupees?|rupaye|rupya|रुपैयाँ|रुपैया|रुपये|रुपया|रु|inr)(?![a-z])/u'),
            $take('/(?<![\d.a-z])(\d{1,7})(?![\d.]) ?(?:off|chhut|chut|chhoot|discount|छुट|ऑफ|ओफ)(?![a-z])/u'),
            $take('/(?<![a-z])(?:flat|sidha|सिधा) ?(\d{1,7})(?![\d.])/u')
        );
        preg_match_all('/(?<![\d.a-z_\-])(\d+(?:\.\d+)?)(?![\d.a-z_\-])/u', $c, $mm);
        $out['left'] = $mm[1];
        $out['uses'] = array_map('intval', $out['uses']);
        $out['per']  = array_map('intval', $out['per']);
        return $out;
    }

    /** "OFFER10-20NOV" / "FLAT50-16NOV" (+ "-2" … while taken) for an offer said without a code. */
    private static function couponAutoCode(string $type, float $v, ?string $from): string
    {
        $num  = str_replace('.', '_', self::couponNum($v));
        $base = ($type === 'percent' ? 'OFFER' : 'FLAT') . $num . '-' . strtoupper(date('jM', (int) strtotime($from ?? todayISO())));
        $code = $base;
        for ($i = 2; $i < 100 && Database::exists('SELECT 1 FROM coupons WHERE code = :c', ['c' => $code]); $i++) {
            $code = $base . '-' . $i;
        }
        return $code;
    }

    /** 10 → "10", 7.5 → "7.5" */
    private static function couponNum(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** "10% छुट · off, बढीमा · max ₹200" / "₹50 छुट · off" — an action or a coupons row. */
    private static function couponValue(array $c): string
    {
        $cap = ($c['max_discount'] ?? null) !== null && (float) $c['max_discount'] > 0 ? (float) $c['max_discount'] : null;
        return ((string) ($c['discount_type'] ?? '') === 'percent' ? self::couponNum((float) $c['discount_value']) . '%' : inr((float) $c['discount_value']))
            . ' छुट · off' . ($cap !== null ? ', बढीमा · max ' . inr($cap) : '');
    }

    /** The preview line of a new offer: value, dates, limits, route — and that it is made OFF. */
    private static function couponSummary(array $a): string
    {
        $p   = [self::couponValue($a)];
        $p[] = ($a['valid_from'] !== null ? formatDate((string) $a['valid_from'], 'j M Y') : 'अहिलेदेखि · from now') . ' → '
             . ($a['valid_until'] !== null ? formatDate((string) $a['valid_until'], 'j M Y') : 'अन्त्य छैन · no end');
        $p[] = $a['usage_limit'] !== null ? (int) $a['usage_limit'] . ' पटक · uses' : 'जति पनि पटक · no use limit';
        $p[] = (int) $a['per_user_limit'] > 0 ? (int) $a['per_user_limit'] . '/मोबाइल · per mobile' : 'मोबाइल सीमा छैन · no per-mobile limit';
        if ((float) $a['min_amount'] > 0) {
            $p[] = 'कम्तीमा · min ' . inr((float) $a['min_amount']);
        }
        $rt  = $a['route_id'] !== null ? self::routeById((int) $a['route_id']) : null;
        $p[] = $rt !== null ? 'रुट · route ' . $rt['route_code'] . ' ' . $rt['from_city'] . ' → ' . $rt['to_city'] . ((int) $rt['is_active'] === 1 ? '' : ' (रुट बन्द · route is OFF)')
             : 'सबै रुट · all routes';
        $p[] = 'बन्द अवस्थामा बन्छ · created OFF';
        return implode(' · ', $p);
    }

    /** An action → the input OfferRules::validate() takes (always OFF, never auto-applied). */
    private static function couponIn(array $a): array
    {
        $cap = ($a['max_discount'] ?? null) !== null && $a['max_discount'] !== '' && (float) $a['max_discount'] > 0 ? (string) round((float) $a['max_discount'], 2) : '';
        $uses = ($a['usage_limit'] ?? null) !== null && $a['usage_limit'] !== '' ? (string) (int) $a['usage_limit'] : '';
        $code = strtoupper(trim((string) ($a['code'] ?? '')));
        return [
            'code'           => $code,
            'title'          => $code,
            'discount_type'  => (string) ($a['discount_type'] ?? '') === 'percent' ? 'percent' : 'flat',
            'discount_value' => round((float) ($a['discount_value'] ?? 0), 2),
            'max_discount'   => $cap,
            'min_amount'     => max(0.0, round((float) ($a['min_amount'] ?? 0), 2)),
            'valid_from'     => (string) ($a['valid_from'] ?? ''),
            'valid_until'    => (string) ($a['valid_until'] ?? ''),
            'usage_limit'    => $uses,
            'per_user_limit' => (string) (int) ($a['per_user_limit'] ?? 1),
            'route_id'       => (int) ($a['route_id'] ?? 0),
            'auto_apply'     => 0,
            'is_active'      => 0,
        ];
    }

    /**
     * A new offer, checked: the AI's own limits (a percent needs a cap and stays ≤ 50 %, ₹ ≤ 5,000,
     * no past date, no code already used), then admin/offers.php's rules (OfferRules).
     *
     * @return array{0:?string, 1:?array<string,mixed>} [why not, the clean action]
     */
    private static function couponCheck(array $a): array
    {
        require_once __DIR__ . '/offerrules.php';
        $in    = self::couponIn($a);
        $code  = $in['code'];
        $pct   = $in['discount_type'] === 'percent';
        $v     = (float) $in['discount_value'];
        $cap   = $in['max_discount'] !== '' ? (float) $in['max_discount'] : null;
        $today = todayISO();
        if ($code !== '' && Database::exists('SELECT 1 FROM coupons WHERE code = :c', ['c' => $code])) {
            return ['🏷 ' . $code . ' कोडको अफर पहिल्यै छ · An offer with code ' . $code . ' already exists — choose another code, or change it on the Offers page.', null];
        }
        if ($pct && $cap === null) {
            return ['💸 प्रतिशत छुटमा "max ₹" चाहिन्छ · A percent offer needs a cap — say e.g. "10% max 200".', null];
        }
        if ($pct && $v > self::COUPON_MAX_PCT) {
            return ['⛔ ' . self::couponNum($v) . '% धेरै ठूलो — Offers पेजमा बनाउनुहोस् · too big, use the Offers page (AI: ' . self::COUPON_MAX_PCT . '% सम्म · up to ' . self::COUPON_MAX_PCT . '%).', null];
        }
        if ((!$pct && $v > self::COUPON_MAX_FLAT) || ($cap !== null && $cap > self::COUPON_MAX_FLAT)) {
            return ['⛔ ' . inr(max($pct ? 0.0 : $v, (float) $cap)) . ' धेरै ठूलो — Offers पेजमा बनाउनुहोस् · too big, use the Offers page (AI: '
                . inr(self::COUPON_MAX_FLAT) . ' सम्म · up to ' . inr(self::COUPON_MAX_FLAT) . ').', null];
        }
        foreach ([$in['valid_from'], $in['valid_until']] as $d) {
            if ($d !== '' && Security::isValidDate($d) && $d < $today) {
                return ['📅 ' . formatDate($d, 'j M Y') . ' बितिसक्यो · That date is already past — an offer runs from today on.', null];
            }
        }
        try {
            $row = OfferRules::validate($in);
        } catch (InvalidArgumentException $e) {
            return ['⛔ ' . $e->getMessage(), null];
        }
        return [null, [
            'type'           => 'coupon',
            'code'           => (string) $row['code'],
            'discount_type'  => (string) $row['discount_type'],
            'discount_value' => (float) $row['discount_value'],
            'max_discount'   => $row['max_discount'] !== null ? (float) $row['max_discount'] : null,
            'min_amount'     => (float) $row['min_amount'],
            'valid_from'     => $row['valid_from'],
            'valid_until'    => $row['valid_until'],
            'usage_limit'    => ($row['usage_limit'] ?? null) !== null ? (int) $row['usage_limit'] : null,
            'per_user_limit' => (int) ($row['per_user_limit'] ?? 1),
            'route_id'       => ($row['route_id'] ?? null) !== null ? (int) $row['route_id'] : null,
            'auto_code'      => !empty($a['auto_code']),
        ]];
    }

    /** @return array{0:?string, 1:?array<string,mixed>} an ACTIVE offer only (the AI only ever takes a discount away) */
    private static function couponOffCheck(array $a): array
    {
        $id   = (int) ($a['coupon_id'] ?? 0);
        $code = strtoupper(trim((string) ($a['code'] ?? '')));
        $row  = $id > 0 ? Database::fetch('SELECT id, code, is_active FROM coupons WHERE id = :i', ['i' => $id])
              : ($code !== '' ? Database::fetch('SELECT id, code, is_active FROM coupons WHERE code = :c', ['c' => $code]) : null);
        if ($row === null) {
            return ['🏷 अफर भेटिएन · Offer not found.', null];
        }
        if ((int) $row['is_active'] !== 1) {
            return ['🏷 ' . $row['code'] . ' पहिल्यै बन्द छ · is already OFF.', null];
        }
        return [null, ['type' => 'coupon_off', 'coupon_id' => (int) $row['id'], 'code' => (string) $row['code']]];
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
            // 16 Nov 2026 (W3): an Easy Settings switch with no row yet is written to its own group
            self::$extra[(string) $k] = [(string) $d[0], (string) $d[1], (array) ($d[2] ?? []), self::EASY_GROUPS[(string) $k] ?? 'general', isset($d[3]) ? (string) $d[3] : null];
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
            'public'  => ($row !== null && (int) $row['is_public'] === 1) || in_array($key, self::EASY_PUBLIC, true),
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
             . "- fare_delta {stop:'<pickup stop name>' or '*', delta:+/-rupees, dir:'toNepal'|'toIndia'|'both'}  raise / lower fares BY an amount\n"
             . "- seat_block {seat:'U15', date:'YYYY-MM-DD', on:false to block | true to unblock}\n"
             . "- add_stop {route_id:id, stop_type:'boarding'|'drop', stop_name:'...', stop_time:'HH:MM', fare:number or null}  only when a time is said\n"
             . "Routes:\n- " . implode("\n- ", $routes) . "\n"
             . "Buses:\n- " . implode("\n- ", $buses) . "\n"
             . ($sets !== [] ? "Settings that may be meant:\n- " . implode("\n- ", $sets) . "\n" : '')
             . 'Reply JSON {"actions":[{"type":"...", ...}]}. Use an empty list when the instruction is unclear. Never invent ids or keys.';
        $schema = ['type' => 'object', 'properties' => ['actions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'type' => ['type' => 'string'], 'route_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'on' => ['type' => 'boolean'],
            'stop' => ['type' => 'string'], 'fare' => ['type' => ['number', 'null']], 'dir' => ['type' => 'string'], 'date' => ['type' => 'string'],
            'bus_id' => ['type' => 'integer'], 'value' => ['type' => ['string', 'number']], 'n' => ['type' => 'integer'], 'agent' => ['type' => 'string'], 'key' => ['type' => 'string'],
            'delta' => ['type' => 'number'], 'seat' => ['type' => 'string'], 'route_id' => ['type' => 'integer'], 'stop_type' => ['type' => 'string'],
            'stop_name' => ['type' => 'string'], 'stop_time' => ['type' => 'string'], 'time' => ['type' => 'string'],
        ], 'required' => ['type']]]], 'required' => ['actions']];
        $local = static function () use ($sys, $text, $schema): ?array {
            try {
                require_once __DIR__ . '/agentpro-ai.php';
                if (class_exists('ApAi') && ApAi::enabled()) {
                    $j = ApAi::json($sys, $text, $schema, [], ['purpose' => 'settings', 'max_tokens' => 400, 'timeout' => 30, 'lock_wait' => 5]);
                    return is_array($j) ? $j : null;
                }
            } catch (Throwable $e) {
                // the cloud brain may still answer
            }
            return null;
        };
        $cloud = static function () use ($sys, $text): ?array {
            try {
                require_once __DIR__ . '/aibrain.php';
                if (class_exists('AiBrain') && AiBrain::configured()) {
                    $r = AiBrain::text($sys . "\nJSON only.", [['role' => 'user', 'content' => $text]], 500, 'settings');
                    $rawOut = trim((string) ($r['text'] ?? ''));
                    if (preg_match('/\{.*\}/s', $rawOut, $m) === 1) {
                        $j = json_decode($m[0], true);
                        return is_array($j) ? $j : null;
                    }
                }
            } catch (Throwable $e) {
                // the VPS model may still answer
            }
            return null;
        };
        /* 16 Nov 2026 (P3-7a / P3-7d): ai_settings_engine 'cloud' asks the cloud brain first (~1-2 s on
           live), then the VPS model; 'local' (the default) keeps the order as it was — VPS model first
           (up to 30 s on the 2-CPU VPS), the cloud brain only when it gives nothing. */
        $j = self::settingsEngine() === 'cloud' ? ($cloud() ?? $local()) : ($local() ?? $cloud());
        $out = [];
        foreach ((array) ($j['actions'] ?? []) as $a) {
            // a passenger broadcast (trip_delay) and an offer (coupon / coupon_off, Y2) are only ever proposed from the office's own words
            $a = is_array($a) && !in_array((string) ($a['type'] ?? ''), ['trip_delay', 'coupon', 'coupon_off'], true) ? self::clean($a, $text) : null;
            if ($a !== null) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /** 'local' (default: the VPS model first) or 'cloud' (the cloud brain first) for the settings AI path. */
    public static function settingsEngine(): string
    {
        try {
            return Settings::getString('ai_settings_engine', 'local') === 'cloud' ? 'cloud' : 'local';
        } catch (Throwable $e) {
            return 'local';
        }
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
                return (!Security::isValidDate($d) || ($f !== null && $f < 0)) ? null : self::cleanTrip($a, ['type' => 'trip_fare', 'date' => $d, 'fare' => $f], $routeIds);
            case 'trip_sale':
                $d = (string) ($a['date'] ?? '');
                return !Security::isValidDate($d) ? null : self::cleanTrip($a, ['type' => 'trip_sale', 'date' => $d, 'on' => (bool) ($a['on'] ?? false)], $routeIds);
            case 'fare_delta':
                $dl   = round((float) ($a['delta'] ?? 0), 2);
                $stop = trim((string) ($a['stop'] ?? ''));
                $dir  = (string) ($a['dir'] ?? 'toNepal');
                return ($dl === 0.0 || abs($dl) > 5000 || $stop === '' || !in_array($dir, ['toNepal', 'toIndia', 'both'], true)) ? null
                    : ['type' => 'fare_delta', 'stop' => mb_substr($stop, 0, 120), 'delta' => $dl, 'dir' => $dir];
            case 'add_stop':
                $rid  = (int) ($a['route_id'] ?? 0);
                $st   = (string) ($a['stop_type'] ?? 'boarding');
                $name = trim(Security::clean((string) ($a['stop_name'] ?? ''), 191));
                $tm   = substr((string) ($a['stop_time'] ?? ''), 0, 5);
                $f    = $fare($a['fare'] ?? null);
                $on   = array_map(static fn(array $r): int => (int) $r['id'], array_filter(self::routes(), static fn(array $r): bool => (int) $r['is_active'] === 1));
                if (!in_array($rid, $on, true) || !in_array($st, ['boarding', 'drop'], true) || mb_strlen($name) < 2 || preg_match('/\p{L}/u', $name) !== 1
                    || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $tm) !== 1 || ($f !== null && $f < 0)) {
                    return null;
                }
                return ['type' => 'add_stop', 'route_id' => $rid, 'stop_type' => $st, 'stop_name' => $name, 'stop_time' => $tm, 'fare' => $f];
            case 'seat_block':
                $seat = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($a['seat'] ?? '')));
                $d    = (string) ($a['date'] ?? '');
                return (!Security::isValidDate($d) || preg_match('/^[LU]\d{1,2}$/', $seat) !== 1) ? null
                    : self::cleanTrip($a, ['type' => 'seat_block', 'seat' => $seat, 'date' => $d, 'on' => (bool) ($a['on'] ?? false)], $routeIds);
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
                // 16 Nov 2026 (W3): an Easy Settings number knob stays inside its own range ("device limit 500" is refused)
                if ($v !== '' && in_array(self::EASY_KEYS[$key][1] ?? '', ['int', 'float'], true)) {
                    $v = self::easyNumber($key, $v) ?? '';
                }
                return ($v === '' && $info['type'] !== 'string') ? null : ['type' => 'setting', 'key' => $key, 'value' => $v];
            case 'trip_delay':
                // 16 Nov 2026 (P8-10d): one known, not-yet-left departure on that date; 5 min – 10 h; only while the switch is ON
                $sid  = (int) ($a['schedule_id'] ?? 0);
                $d    = (string) ($a['date'] ?? '');
                $mins = (int) ($a['minutes'] ?? 0);
                if (!Settings::getBool('ai_trip_delay_on', false) || $sid <= 0 || !Security::isValidDate($d) || $mins < 5 || $mins > 600
                    || !Database::exists("SELECT 1 FROM schedules WHERE id = :s AND travel_date = :d AND status NOT IN ('cancelled', 'departed', 'arrived')", ['s' => $sid, 'd' => $d])) {
                    return null;
                }
                return ['type' => 'trip_delay', 'schedule_id' => $sid, 'date' => $d, 'minutes' => $mins];
            case 'coupon':
                // 16 Nov 2026 (Y2): admin/offers.php's rules (OfferRules) + the AI's own limits, checked again on "Yes"
                return self::couponCheck($a)[1];
            case 'coupon_off':
                return self::couponOffCheck($a)[1];
        }
        return null;
    }

    /**
     * Keep a date action's own filters — route_ids, time (+ alt), dir — through clean(). They
     * decide which buses it touches; until 16 Nov 2026 apply() dropped route_ids, so a change the
     * preview showed for one route was written to every bus of that day.
     *
     * @param list<int> $routeIds
     */
    private static function cleanTrip(array $a, array $out, array $routeIds): ?array
    {
        // an empty list (the AI fills every schema field) = no route filter, the same as the preview reads it
        if (array_key_exists('route_ids', $a) && (array) $a['route_ids'] !== []) {
            $ids = array_values(array_intersect(array_map('intval', (array) $a['route_ids']), $routeIds));
            if ($ids === []) {
                return null;   // a filter that matches nothing must never widen to every bus
            }
            $out['route_ids'] = $ids;
        }
        foreach (['time', 'alt'] as $k) {
            if (($a[$k] ?? null) !== null && $a[$k] !== '') {
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $a[$k]) !== 1) {
                    return null;
                }
                $out[$k] = (string) $a[$k];
            }
        }
        if (($a['dir'] ?? null) !== null && $a['dir'] !== '') {
            if (!in_array($a['dir'], ['toNepal', 'toIndia'], true)) {
                return null;
            }
            $out['dir'] = (string) $a['dir'];
        }
        return $out;
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
            'route', 'stop_fare', 'dir_fare',
            'fare_delta', 'add_stop'         => Auth::can('routes.edit'),
            'trip_fare', 'trip_sale'         => Auth::can('schedules.manage'),
            'seat_block'                     => Auth::can('schedules.manage') && !Auth::isCounterAgent(),
            'trip_delay'                     => Auth::can('schedules.manage') && !Auth::isCounterAgent(),   // office only (P8-10d)
            'bus_number', 'bus_name'          => Auth::can('routes.edit') || Auth::can('schedules.manage'),
            'commission'                      => Auth::can('staff.manage') && Auth::canManageSettings(),
            'coupon', 'coupon_off'            => Auth::can('coupons.edit'),   // Y2: the Offers page's own gate (super-admin passed above)
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
                        $s['fare'] !== null ? inr((float) $s['fare']) : 'default ' . inr((float) (Fare::dirFares()['toNepal'] ?? 0)), inr((float) $a['fare'])];
                }
                break;
            case 'fare_delta':
                $sign = ((float) $a['delta'] > 0 ? '+' : '−') . inr(abs((float) $a['delta']));
                foreach (self::deltaPlan($a) as $p) {
                    $bad = $p['new'] < 100 || $p['new'] > 20000;
                    $rows[] = [$p['label'] . ' (' . $sign . ')', ($p['own'] ? '' : 'default ') . inr($p['old']),
                        inr($p['new']) . ($bad ? ' ⚠ ₹100–₹20,000 बाहिर · out of range' : '')];
                }
                break;
            case 'add_stop':
                $rt  = self::routeById((int) $a['route_id']);
                $def = (float) (Fare::dirFares()[Fare::routeDir($rt ?? [])] ?? 0);
                $rows[] = ['➕ ' . ($a['stop_type'] === 'drop' ? 'नयाँ drop · New drop point' : 'नयाँ pickup · New pickup') . ' · '
                        . ($rt !== null ? $rt['route_code'] . ' ' . $rt['from_city'] . ' → ' . $rt['to_city'] : '#' . $a['route_id']),
                    '—', $a['stop_name'] . ' · ' . $a['stop_time'] . ' · ' . ($a['fare'] !== null ? inr((float) $a['fare']) : 'default ' . inr($def))];
                break;
            case 'seat_block':
                foreach (self::seatPlan($a) as $p) {
                    $rows[] = ['💺 ' . $a['seat'] . ' · ' . formatDate((string) $a['date'], 'j M') . ' · ' . $p['bus'], $p['old'], $p['new']];
                }
                break;
            case 'trip_delay':
                // the bus and who would be told — nothing is sent before "Yes" (P8-10d)
                foreach (self::dateSchedules((string) $a['date']) as $s) {
                    if ((int) $s['id'] !== (int) $a['schedule_id']) {
                        continue;
                    }
                    $r = self::delayReach((int) $s['id']);
                    $rows[] = ['⏰ ' . formatDate((string) $a['date'], 'j M') . ' · ' . $s['from_city'] . ' → ' . $s['to_city'] . ' ' . substr((string) $s['dep'], 0, 5)
                            . ' · ' . $r['bookings'] . ' booking (' . $r['people'] . ' यात्रु · passengers) लाई WhatsApp/SMS',
                        $r['delay'] > 0 ? self::hm($r['delay']) . ' late' : 'समयमै · on time',
                        self::hm((int) $a['minutes']) . ' late 📣'];
                }
                break;
            case 'dir_fare':
                $cur = Fare::dirFares()[$a['dir']] ?? 0;
                $rows[] = ['💰 Default fare ' . ($a['dir'] === 'toNepal' ? 'जाने · to Nepal' : 'आउने · to India'), inr((float) $cur), inr((float) $a['fare'])];
                break;
            case 'coupon':
                // Y2: code, % / ₹, cap, dates, limits — and that it is made OFF (nothing changes before the office switches it ON)
                $rows[] = ['🏷 नयाँ अफर · New offer ' . $a['code'] . (!empty($a['auto_code']) ? ' (कोड आफैं बनाइयो · code made for you)' : ''),
                    '—', self::couponSummary($a)];
                break;
            case 'coupon_off':
                $c = Database::fetch('SELECT code, title, discount_type, discount_value, max_discount, is_active FROM coupons WHERE id = :i', ['i' => (int) $a['coupon_id']]);
                if ($c !== null) {
                    $rows[] = ['🏷 अफर · Offer ' . $c['code'] . ' · ' . self::couponValue($c),
                        (int) $c['is_active'] === 1 ? 'ON 🟢' : 'OFF ⛔', 'OFF ⛔'];
                }
                break;
            case 'trip_fare':
                foreach (self::dateSchedules((string) $a['date'], (array) ($a['route_ids'] ?? []), $a) as $s) {
                    $rows[] = ['📅 ' . formatDate((string) $a['date'], 'j M') . ' · ' . $s['from_city'] . ' → ' . $s['to_city'] . ' ' . substr((string) $s['dep'], 0, 5),
                        $s['fare_override'] !== null ? inr((float) $s['fare_override']) : 'normal', $a['fare'] === null ? 'normal' : inr((float) $a['fare'])];
                }
                break;
            case 'trip_sale':
                foreach (self::dateSchedules((string) $a['date'], (array) ($a['route_ids'] ?? []), $a) as $s) {
                    if ($a['on'] && !Fleet::planFor((int) $s['route_id'], (string) $a['date'])['runs']) {
                        continue;   // the rotation has no bus for it that day
                    }
                    $rows[] = ['📅 ' . formatDate((string) $a['date'], 'j M') . ' · ' . $s['from_city'] . ' → ' . $s['to_city'] . ' ' . substr((string) $s['dep'], 0, 5),
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
                    $easy = isset(self::EASY_KEYS[(string) $a['key']]) && in_array(self::EASY_KEYS[(string) $a['key']][1], ['int', 'float'], true);
                    $show = static fn(string $v): string => $info['type'] === 'bool' ? ($v === '1' ? 'ON' : 'OFF')
                        : ($info['type'] === 'enum' ? (string) ($info['options'][$v] ?? $v) : ($easy ? self::easyShow((string) $a['key'], $v) : $v));
                    $rows[] = ['⚙️ ' . $info['label'] . ' (' . $a['key'] . ')', $show($info['current']), $show((string) $a['value'])];
                }
                break;
        }
        return $rows;
    }

    /**
     * The stops of active routes that price a seat in that direction, matching the stop ('*' = the
     * priced ones): going to Nepal the pickup, coming back the drop (Fare::stopFare).
     *
     * @return list<array<string,mixed>>
     */
    private static function stopRows(string $stop, string $dir = 'toNepal'): array
    {
        $rows = [];
        foreach (Database::fetchAll(
            "SELECT rs.id, rs.stop_name, rs.fare, rs.route_id, r.from_city, r.to_city
               FROM route_stops rs JOIN routes r ON r.id = rs.route_id
              WHERE r.is_active = 1 AND rs.stop_type = :t AND rs.stop_name <> ''
              ORDER BY rs.route_id, rs.sort_order",
            ['t' => $dir === 'toIndia' ? 'drop' : 'boarding']
        ) as $s) {
            if (Fare::routeDir(['to_city' => $s['to_city']]) !== $dir) {
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

    /**
     * A day's departures on active routes. $f may narrow them (16 Nov 2026, P3-2b): dir
     * (toNepal / toIndia) and time (+ alt), each ± 60 minutes of the bus's own departure.
     *
     * @param array<string,mixed> $f
     * @return list<array<string,mixed>>
     */
    private static function dateSchedules(string $date, array $routeIds = [], array $f = []): array
    {
        $rows = Database::fetchAll(
            "SELECT s.id, s.route_id, s.is_blocked, s.fare_override, s.status, r.from_city, r.to_city,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.travel_date = :d AND r.is_active = 1 AND s.status <> 'cancelled' ORDER BY dep, s.id",
            ['d' => $date]
        );
        $routeIds = array_map('intval', $routeIds);
        $mins = static fn(string $hm): int => (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2);
        return array_values(array_filter($rows, static function (array $s) use ($routeIds, $f, $mins): bool {
            if ($routeIds !== [] && !in_array((int) $s['route_id'], $routeIds, true)) {
                return false;
            }
            if (!empty($f['dir']) && Fare::routeDir(['to_city' => $s['to_city']]) !== $f['dir']) {
                return false;
            }
            if (!empty($f['time'])) {
                $dep = $mins((string) $s['dep']);
                return abs($dep - $mins((string) $f['time'])) <= 60 || (!empty($f['alt']) && abs($dep - $mins((string) $f['alt'])) <= 60);
            }
            return true;
        }));
    }

    /** @return array<string,mixed>|null */
    private static function routeById(int $id): ?array
    {
        foreach (self::routes() as $r) {
            if ((int) $r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Every fare a fare_delta touches: the stops (own fare, or the default they follow when
     * named), and for '*' the direction default too (it carries the stops without their own).
     *
     * @return list<array{kind:string, id:int|string, key:string, label:string, old:float, own:bool, new:float}>
     */
    private static function deltaPlan(array $a): array
    {
        $def  = Fare::dirFares();
        $plan = [];
        foreach ((string) $a['dir'] === 'both' ? ['toNepal', 'toIndia'] : [(string) $a['dir']] as $d) {
            foreach (self::stopRows((string) $a['stop'], $d) as $s) {
                $own = $s['fare'] !== null;
                $old = $own ? (float) $s['fare'] : (float) ($def[$d] ?? 0);
                $plan[] = ['kind' => 'stop', 'id' => (int) $s['id'], 'key' => $s['stop_name'] . ' #' . $s['id'],
                    'label' => '💰 ' . $s['stop_name'] . ' (' . $s['from_city'] . ' → ' . $s['to_city'] . ')',
                    'old' => $old, 'own' => $own, 'new' => round($old + (float) $a['delta'], 2)];
            }
            if ((string) $a['stop'] === '*') {
                $old = (float) ($def[$d] ?? 0);
                $plan[] = ['kind' => 'dir', 'id' => $d, 'key' => $d, 'label' => '💰 Default fare ' . ($d === 'toNepal' ? 'जाने · to Nepal' : 'आउने · to India'),
                    'old' => $old, 'own' => true, 'new' => round($old + (float) $a['delta'], 2)];
            }
        }
        return $plan;
    }

    /**
     * One row per departure that has the seat: open / blocked / sold now, and after.
     *
     * @return list<array{id:int, bus:string, old:string, new:string, sold:bool}>
     */
    private static function seatPlan(array $a): array
    {
        $out  = [];
        $seat = (string) $a['seat'];
        foreach (self::dateSchedules((string) $a['date'], (array) ($a['route_ids'] ?? []), $a) as $s) {
            $sid = (int) $s['id'];
            if (!in_array($seat, Seats::seatIdsForSchedule($sid), true)) {
                continue;
            }
            $sold    = array_intersect(Seats::physicalSeats($seat, 'sharing', Seats::coachForSchedule($sid)), Seats::bookedPhysical($sid)) !== [];
            $blocked = in_array($seat, Seats::blockedSeats($sid), true);
            $old     = $sold ? 'बिक्यो · Sold' : ($blocked ? 'Blocked ⛔' : 'Open 🟢');
            $new     = $a['on'] ? ($sold ? $old : 'Open 🟢') : ($sold ? 'बिक्यो — रोकिँदैन · Sold, skipped' : 'Blocked ⛔');
            $out[]   = ['id' => $sid, 'bus' => $s['from_city'] . ' → ' . $s['to_city'] . ' ' . substr((string) $s['dep'], 0, 5), 'old' => $old, 'new' => $new, 'sold' => $sold];
        }
        return $out;
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
                // Y2: an offer refused at "Yes" (the code was taken meanwhile, the date passed, it is already OFF) says why
                $ty = (string) ($a0['type'] ?? '');
                if ($ty === 'coupon' || $ty === 'coupon_off') {
                    $out[] = '⚠️ ' . $ty . ': ' . (string) ($ty === 'coupon' ? self::couponCheck($a0)[0] : self::couponOffCheck($a0)[0]);
                }
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
                // 16 Nov 2026 (P3-1c): every stop's old fare goes into the log, so a wrong change can be put back
                $before = $after = [];
                foreach ($rows as $s) {
                    $before[$s['stop_name'] . ' #' . $s['id']] = $s['fare'] !== null ? round((float) $s['fare'], 2) : null;
                    $after[$s['stop_name'] . ' #' . $s['id']]  = $a['fare'];
                }
                Database::transaction(static function () use ($rows, $a): void {
                    foreach ($rows as $s) {
                        Database::update('route_stops', ['fare' => $a['fare']], 'id = :i', ['i' => (int) $s['id']]);
                    }
                });
                if (class_exists('Boarding')) {
                    Boarding::flush();
                }
                Logger::audit('routes.stop_fare', 'route_stops', (string) $a['stop'], $before, $after, 'fare ' . inr((float) $a['fare']) . ' on ' . count($rows) . ' stop(s) via S Hari AI by admin #' . $by);
                return '💰 ' . count($rows) . ' pickup' . ($a['stop'] === '*' ? 's' : ' (' . $a['stop'] . ')') . ' → ' . inr((float) $a['fare']);
            case 'dir_fare':
                self::setDirFare((string) $a['dir'], (float) $a['fare'], $by);
                return '💰 Default ' . ($a['dir'] === 'toNepal' ? 'to Nepal' : 'to India') . ' → ' . inr((float) $a['fare']);
            case 'fare_delta':
                $plan = self::deltaPlan($a);
                foreach ($plan as $p) {
                    if ($p['new'] < 100 || $p['new'] > 20000) {
                        throw new RuntimeException(trim(str_replace('💰', '', $p['label'])) . ' → ' . inr($p['new']) . ': ₹100–₹20,000 बाहिर · out of range — nothing changed');
                    }
                }
                $before = $after = [];
                foreach ($plan as $p) {
                    if ($p['kind'] === 'stop') {
                        $before[$p['key']] = $p['own'] ? $p['old'] : null;
                        $after[$p['key']]  = $p['new'];
                    }
                }
                Database::transaction(static function () use ($plan): void {
                    foreach ($plan as $p) {
                        if ($p['kind'] === 'stop') {
                            Database::update('route_stops', ['fare' => $p['new']], 'id = :i', ['i' => (int) $p['id']]);
                        }
                    }
                });
                foreach ($plan as $p) {
                    if ($p['kind'] === 'dir') {
                        self::setDirFare((string) $p['id'], (float) $p['new'], $by);
                    }
                }
                if (class_exists('Boarding')) {
                    Boarding::flush();
                }
                $sign = ((float) $a['delta'] > 0 ? '+' : '−') . inr(abs((float) $a['delta']));
                if ($before !== []) {
                    Logger::audit('routes.stop_fare', 'route_stops', (string) $a['stop'], $before, $after, 'fare ' . $sign . ' via S Hari AI by admin #' . $by);
                }
                return '💰 ' . count($plan) . ' fare' . (count($plan) === 1 ? '' : 's') . ' ' . $sign . ($a['stop'] === '*' ? '' : ' (' . $a['stop'] . ')');
            case 'add_stop':
                if (!class_exists('Boarding')) {
                    require_once __DIR__ . '/boarding.php';
                }
                if (!method_exists('Boarding', 'addStop')) {
                    throw new RuntimeException('Boarding::addStop यो server मा छैन · not on this server yet — add the stop on Routes.');
                }
                $rt = self::routeById((int) $a['route_id']);
                $id = Boarding::addStop([
                    'route_id' => (int) $a['route_id'], 'stop_type' => (string) $a['stop_type'], 'stop_name' => (string) $a['stop_name'],
                    'stop_time' => (string) $a['stop_time'], 'fare' => $a['fare'], 'is_active' => 1,
                ], $by);
                return '➕ ' . $a['stop_name'] . ' · ' . $a['stop_time'] . ' → ' . ($rt['route_code'] ?? '#' . $a['route_id']) . ' (#' . $id . ')';
            case 'seat_block':
                $n = 0;
                $skip = [];
                foreach (self::seatPlan($a) as $p) {
                    if ($p['old'] === $p['new']) {
                        continue;
                    }
                    if ($p['sold']) {
                        $skip[] = $p['bus'] . ' sold';
                        continue;
                    }
                    try {
                        if ($a['on']) {
                            Seats::unblockSeat($p['id'], (string) $a['seat'], $by);
                        } else {
                            Seats::blockSeat($p['id'], (string) $a['seat'], 'S Hari AI by admin #' . $by, $by);
                        }
                        $n++;
                    } catch (RuntimeException $e) {
                        $skip[] = $e->getMessage();
                    }
                }
                return '💺 ' . $a['seat'] . ' · ' . formatDate((string) $a['date'], 'j M') . ': ' . $n . ' bus ' . ($a['on'] ? 'open 🟢' : 'blocked ⛔')
                    . ($skip !== [] ? ' · ' . implode('; ', $skip) : '');
            case 'trip_delay':
                /* 16 Nov 2026 (P8-10d): exactly what the trips board's "Delay" does (admin/trips.php
                   set_delay) — the delay on the bus, audited trip.set_delay, then the EXISTING
                   TripNotify::notifyDelay messages its confirmed passengers once. */
                if (!Settings::getBool('ai_trip_delay_on', false)) {
                    throw new RuntimeException('AI bus-delay message is switched OFF (ai_trip_delay_on) — nothing sent');
                }
                if (!class_exists('TripNotify')) {
                    require_once __DIR__ . '/tripnotify.php';
                }
                if (!method_exists('TripNotify', 'notifyDelay')) {
                    throw new RuntimeException('TripNotify::notifyDelay यो server मा छैन · not on this server — use Trips → Delay');
                }
                $sid    = (int) $a['schedule_id'];
                $mins   = (int) $a['minutes'];
                $before = Database::fetch('SELECT delay_minutes, delay_note FROM schedules WHERE id = :id', ['id' => $sid]);
                Database::update('schedules', ['delay_minutes' => $mins], 'id = :id', ['id' => $sid]);
                try {
                    $res = TripNotify::notifyDelay($sid, $mins, '', $by);
                } catch (Throwable $e) {
                    // as on the trips board: the delay stays saved even when WhatsApp / SMS fails
                    Logger::error('trip.set_delay.notify', ['err' => $e->getMessage(), 'schedule' => $sid], 'admin');
                    $res = ['notified' => 0, 'failed' => 1, 'message' => 'Passengers could not be messaged — see the error log.'];
                }
                Logger::audit('trip.set_delay', 'schedule', (string) $sid, $before, ['delay_minutes' => $mins, 'notified' => (int) ($res['notified'] ?? 0),
                    'failed' => (int) ($res['failed'] ?? 0)], 'via S Hari AI by admin #' . $by . ' · ' . (string) ($res['message'] ?? ''));
                return '⏰ ' . formatDate((string) $a['date'], 'j M') . ' bus ' . self::hm($mins) . ' late · ' . (string) ($res['message'] ?? '');
            case 'coupon':
                /* 16 Nov 2026 (Y2): the same row admin/offers.php would write — but ALWAYS is_active = 0 and
                   auto_apply = 0, so no fare changes until a person switches it ON on the Offers page */
                require_once __DIR__ . '/offerrules.php';
                $row = OfferRules::validate(self::couponIn($a));
                $row['is_active']  = 0;
                $row['auto_apply'] = 0;
                try {
                    $id = (int) Database::insert('coupons', $row);
                } catch (Throwable $e) {
                    if (stripos($e->getMessage(), 'duplicate') !== false) {
                        throw new RuntimeException($row['code'] . ' कोडको अफर पहिल्यै छ · An offer with that code already exists — nothing made');
                    }
                    throw $e;
                }
                Logger::audit('offer.create', 'coupon', (string) $row['code'], null, $row,
                    'Offer created OFF via S Hari AI by admin #' . $by . ' — switched ON only on admin/offers.php');
                return '🏷 ' . $row['code'] . ' · ' . self::couponValue($row) . ' — बन्द अवस्थामा बन्यो · created OFF (#' . $id . '). '
                    . 'Offers पेजमा ON गर्नुहोस् · Switch it ON on Offers.';
            case 'coupon_off':
                $c = Database::fetch('SELECT id, code, is_active FROM coupons WHERE id = :i', ['i' => (int) $a['coupon_id']]);
                if ($c === null) {
                    throw new RuntimeException('अफर भेटिएन · offer not found');
                }
                // only ever ON → OFF (it takes a discount away); the AI never switches an offer ON
                if (Database::update('coupons', ['is_active' => 0], 'id = :i AND is_active = 1', ['i' => (int) $c['id']]) > 0) {
                    Logger::audit('offer.toggle', 'coupon', (string) $c['code'], ['is_active' => 1], ['is_active' => 0], 'disabled via S Hari AI by admin #' . $by);
                    return '🏷 ' . $c['code'] . ' अफर बन्द · offer OFF ⛔';
                }
                return '🏷 ' . $c['code'] . ' पहिल्यै बन्द · already OFF';
            case 'trip_fare':
                $rows = self::dateSchedules((string) $a['date'], (array) ($a['route_ids'] ?? []), $a);
                foreach ($rows as $s) {
                    Database::update('schedules', ['fare_override' => $a['fare']], 'id = :i', ['i' => (int) $s['id']]);
                    Logger::audit('schedule.fare_override', 'schedule', (string) $s['id'], ['fare_override' => $s['fare_override']], ['fare_override' => $a['fare']], 'via S Hari AI by admin #' . $by);
                }
                return '📅 ' . formatDate((string) $a['date'], 'j M') . ': ' . count($rows) . ' bus → ' . ($a['fare'] === null ? 'normal fare' : inr((float) $a['fare']));
            case 'trip_sale':
                $n = 0;
                foreach (self::dateSchedules((string) $a['date'], (array) ($a['route_ids'] ?? []), $a) as $s) {
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

    /** A direction's default fare (fare_to_nepal / fare_to_india, kept in step with main_fares), audited. */
    private static function setDirFare(string $dir, float $fare, int $by): void
    {
        $key = $dir === 'toNepal' ? 'fare_to_nepal' : 'fare_to_india';
        $old = Settings::getFloat($key, 0.0);
        Settings::set($key, (string) $fare, 'float', 'pricing');
        $mf = Settings::getArray('main_fares', []);
        if ($mf !== [] && isset($mf[$dir])) {
            $mf[$dir] = $fare;
            Settings::set('main_fares', $mf, 'json', 'pricing');
        }
        Logger::audit('settings.fare', 'settings', $key, ['value' => $old], ['value' => $fare], 'via S Hari AI by admin #' . $by);
    }

    /* =================================================================
     *  The one command path — admin/easy-settings.php and the in-page
     *  bubble (admin/api/ai-settings.php) both go through here
     *  (16 Nov 2026, P3-4a): words first, then the one-tap switches,
     *  the AI only when both found nothing and nothing is being asked;
     *  shown old → new; written only on "Yes"; every step audited.
     * ================================================================= */

    /** The one-tap switches: key => [label, type bool|enum, words that name it, enum options] */
    public const EASY_KEYS = [
        'quick_unlock_on'          => ['🔐 PIN / Pattern login', 'bool', ['pin', 'pattern', 'पिन', 'प्याटर्न', 'quick unlock', 'lock']],
        'login_one_door_hi_on'     => ['💬 WhatsApp "hi" login', 'bool', ['hi login', 'whatsapp login', 'wa login', 'hi pathaune', 'login hi']],
        'agent_chalani_on'         => ['🧾 Agent ले आफ्नो chalani हेर्ने', 'bool', ['agent chalani', 'chalani agent', 'agent copy', 'एजेन्ट चलानी']],
        'ap_join_open'             => ['📝 नयाँ agent form (public) खुला', 'bool', ['join form', 'agent form', 'apply form', 'naya agent', 'registration', 'join']],
        'whatsapp_notify_customer' => ['📲 Customer लाई WhatsApp ticket', 'bool', ['customer whatsapp', 'ticket whatsapp', 'whatsapp ticket', 'customer lai whatsapp']],
        'whatsapp_notify_admin'    => ['🔔 Office लाई WhatsApp alert', 'bool', ['admin whatsapp', 'office alert', 'admin alert', 'office lai whatsapp']],
        'wa_booking_on'            => ['🤖 WhatsApp बाट booking (bot)', 'bool', ['whatsapp bot', 'wa bot', 'whatsapp booking', 'bot booking', 'bot', 'बोट']],
        'ai_web_agent_on'          => ['🧠 Website AI assistant', 'bool', ['website ai', 'web ai', 'ai assistant', 'sahayak', 'chat ai', 'website chat']],
        'ai_kb_on'                 => ['📚 AI knowledge base', 'bool', ['knowledge', 'kb', 'gyan', 'ज्ञान']],
        'fb_msgr_on'               => ['📘 Facebook Messenger AI', 'bool', ['facebook', 'messenger', 'fb']],
        'allow_cod'                => ['💵 Cash on boarding (COD)', 'bool', ['cod', 'cash on', 'nagad', 'नगद', 'cash']],
        'trip_reminders_enabled'   => ['⏰ Trip reminder message', 'bool', ['reminder', 'yaad', 'सम्झना', 'remind']],
        'eta_alert_on'             => ['🛰 Bus aaune samay (ETA) alert', 'bool', ['eta', 'arrival alert', 'bus aaune', 'gps alert']],
        'wa_marketing_on'          => ['📣 WhatsApp marketing', 'bool', ['marketing', 'promotion', 'offer message', 'campaign']],
        'seat_pick_manual_first'   => ['💺 सिट आफैं छान्ने (auto भन्दा पहिले)', 'bool', ['seat select', 'seat chhan', 'auto seat', 'seat pick', 'सिट छान', 'manual seat']],
        'smartbook_desk_seat_auto' => ['💺 Desk (agent / counter / admin) मा सिट auto, चाहिए map बाट', 'bool', ['desk seat', 'counter seat auto', 'agent seat auto', 'quick book seat', 'सिट auto']],
        'staff_door_only'          => ['🔐 Agent/staff only on .network', 'bool', ['network', '.network', 'staff door', 'agent door', 'network matra']],
        'chalani_public_on'        => ['🔗 Public chalani link (login बिना)', 'bool', ['public chalani', 'chalani link', 'public link', 'chalani public']],
        'cancel_fine_on'           => ['💸 Cancel गर्दा fine', 'bool', ['cancel fine', 'fine', 'jariwana', 'जरिवाना']],
        'agent_cancel_mode'        => ['🗑 Agent cancel: direct / request', 'enum', ['agent cancel', 'cancel mode', 'agent le cancel'], ['direct' => 'Direct (agent cancels)', 'request' => 'Request (office cancels)']],

        /* 16 Nov 2026 (Master v2, unit W3): every new switch of the v2.0 build — all OFF until the
           owner says so — and the older knobs the office asked to reach by sentence. 'int' / 'float'
           carry [min, max, code default, what 0 means, unit, words that must sit next to the number]. */
        'ai_change_wa_on'          => ['🤖 AI ले बदल्दा office लाई WhatsApp · WhatsApp the office after an AI change', 'bool',
                                       ['ai change whatsapp', 'ai change wa', 'ai change message', 'change receipt', 'ai receipt', 'ai le badleko', 'ai badlav']],
        'ai_settings_engine'       => ['🧠 S Hari AI इन्जिन · Settings AI engine', 'enum', ['ai engine', 'settings engine', 'setting engine', 'settings ai', 'cloud ai', 'local ai'],
                                       ['local' => 'Local — VPS model पहिले · VPS model first', 'cloud' => 'Cloud — छिटो, थोरै खर्च · cloud first, faster, small cost']],
        'ai_trip_delay_on'         => ['⏰ AI बाट बस ढिलो सन्देश यात्रुलाई · AI bus-delay message to passengers', 'bool',
                                       ['ai trip delay', 'trip delay', 'ai delay', 'delay message', 'late message', 'dhilo message', 'ढिलो सन्देश']],
        'reminder_24h_enabled'     => ['⏰ २४ घण्टा अगाडि reminder · 24-hour trip reminder', 'bool',
                                       ['24 ghanta reminder', '24 ghante reminder', '24 ghanta ko reminder', '24 ghante ka reminder', '24 hour reminder', '24 hours reminder',
                                        '24h reminder', '24 hr reminder', '24 घण्टा', '24 घंटा', 'ek din agadi reminder', 'ek din pahle reminder', '24 કલાક']],
        'trip_alert_agents_on'     => ['🧑‍💼 Trip alert agent लाई पनि · Trip alerts to the agents', 'bool',
                                       ['trip alert agent', 'agent trip alert', 'agent lai trip alert', 'agent ko trip alert', 'agent alert', 'एजेन्ट alert']],
        // never a bare "handoff": wa_ops_handoff_on / wa_ops_handoff_notify are other switches
        'wa_handoff_msg_on'        => ['🙋 Staff ले लिँदा customer लाई सूचना · WhatsApp hand-off message', 'bool',
                                       ['handoff message', 'hand off message', 'hand-off message', 'handover message', 'takeover message', 'staff le liyo message']],
        'wa_typing_on'             => ['⌨️ WhatsApp मा typing… देखाउने · WhatsApp typing indicator', 'bool', ['typing', 'टाइपिङ', 'ટાઇપિંગ']],
        'wa_ai_cancel_mode'        => ['🗑 WhatsApp AI बाट cancel · WhatsApp AI cancel: request / self', 'enum', ['whatsapp cancel', 'wa cancel', 'ai cancel', 'whatsapp ma cancel', 'chat cancel'],
                                       ['request' => 'Request — office लाई अनुरोध मात्र · a request to the office', 'self' => 'Self — AI ले आफैं cancel · the AI cancels the ticket']],
        'offline_callback_on'      => ['📴 Offline हुँदा call-back माग्ने · Offline call-back request', 'bool',
                                       ['offline callback', 'offline call back', 'offline call-back', 'callback request', 'call-back request', 'offline booking request']],
        'social_publish_on'        => ['📣 Social post आफैं publish · Social posts auto-publish', 'bool',
                                       ['social publish', 'social post', 'facebook post', 'fb post', 'auto post', 'social media']],
        'wa_global_daily_cap'      => ['🚦 WhatsApp दिनको सीमा · Daily WhatsApp cap', 'int',
                                       // never a bare "daily cap / limit": social_daily_cap and wa_paid_daily_limit are other knobs
                                       ['whatsapp daily cap', 'wa daily cap', 'whatsapp cap', 'wa cap', 'global cap', 'global daily cap', 'whatsapp daily limit',
                                        'wa daily limit', 'whatsapp दैनिक सीमा'],
                                       ['min' => 0, 'max' => 100000, 'def' => '0', 'zero' => 'सीमा छैन · no cap', 'unit' => '/day']],
        'device_limit_agent'       => ['📱 Agent: कति device · Device limit (agent)', 'int',
                                       ['device limit agent', 'agent device limit', 'agent ko device limit', 'agent ko phone limit', 'agent ko mobile limit'],
                                       ['min' => 0, 'max' => 10, 'def' => '0', 'zero' => 'सीमा छैन · no limit', 'unit' => 'device']],
        'device_limit_counter'     => ['📱 Counter: कति device · Device limit (counter)', 'int',
                                       ['device limit counter', 'counter device limit', 'counter ko device limit', 'counter ko phone limit', 'counter ko mobile limit'],
                                       ['min' => 0, 'max' => 10, 'def' => '0', 'zero' => 'सीमा छैन · no limit', 'unit' => 'device']],
        'group_discount_on'        => ['👥 समूह छुट · Group discount', 'bool', ['group discount', 'group chhut', 'group chut', 'समूह छुट', 'ગ્રુપ ડિસ્કાઉન્ટ']],
        'group_discount_min_seats' => ['👥 समूह छुट: कम्तीमा सिट · Group discount from', 'int', ['group discount', 'group chhut', 'group chut', 'समूह छुट'],
                                       ['min' => 2, 'max' => 50, 'def' => '5', 'zero' => '', 'unit' => 'seats',
                                        'need' => ['seat', 'seats', 'sit', 'jana', 'jaana', 'people', 'passenger', 'passengers', 'सिट', 'जना', 'यात्रु', 'સીટ']]],
        'group_discount_percent'   => ['👥 समूह छुट % · Group discount %', 'float', ['group discount', 'group chhut', 'group chut', 'समूह छुट'],
                                       ['min' => 0, 'max' => 50, 'def' => '5', 'zero' => '', 'unit' => '%',
                                        'need' => ['%', 'percent', 'pratishat', 'प्रतिशत', 'ટકા']]],
        // review 16 Nov 2026: > 0 = cron/lifecycle.php marks departed buses Arrived and WhatsApps every passenger
        'auto_arrive_hours'        => ['🏁 छुटेको यति घण्टापछि आफैं "पुग्यो" ⚠ यात्रुलाई सन्देश · Auto-arrive after ⚠ messages every passenger', 'int',
                                       ['auto arrive', 'auto arrived', 'auto arrival', 'arrive hours', 'auto pugyo', 'आफैं पुग्यो'],
                                       ['min' => 0, 'max' => 120, 'def' => '0', 'zero' => 'बन्द · off', 'unit' => 'h']],
        'agent_settlement_due_days' => ['💰 Agent नगद बुझाउने दिन · Agent settlement due in', 'int',
                                       ['settlement due', 'settle due', 'settlement days', 'hisab din', 'cash due'],
                                       ['min' => 0, 'max' => 365, 'def' => '0', 'zero' => 'कहिल्यै होइन · never flag', 'unit' => 'days']],
        'agent_settle_reminder_days' => ['💰 Agent लाई हिसाब reminder · Agent settlement reminder after', 'int',
                                       ['settle reminder', 'settlement reminder', 'hisab reminder', 'payment reminder agent', 'agent payment reminder'],
                                       ['min' => 0, 'max' => 365, 'def' => '0', 'zero' => 'बन्द · off', 'unit' => 'days']],
        // the TEMPLATE switch only — "good morning band" alone is wa_good_morning_on, not this one
        'wa_good_morning_tpl_on'   => ['🌅 Good morning सन्देशमा paid template · Good-morning paid template', 'bool',
                                       ['good morning template', 'good morning tpl', 'gm template', 'good morning paid', 'शुभ प्रभात template']],
        'ap_ai_on'                 => ['🖥 VPS को AI (WhatsApp) · VPS AI for WhatsApp', 'bool', ['vps ai', 'ollama', 'vps model', 'local model']],

        /* 16 Nov 2026 (Master v2, unit X5): the last owner-decision switches (X1 money, X2 festival,
           X3 login look, X4 Facebook comments) — every one OFF / 0 / classic until the owner says so.
           festival_dates is edited on admin/themes.php, not here. The two return-discount knobs share
           their words: the number goes to the one whose unit word sits next to it (% or din). */
        'return_discount_pct'      => ['🔁 फर्कने टिकटमा छुट % · Return-journey discount %', 'float',
                                       ['return discount', 'return chhut', 'return chut', 'return ko chhut', 'round trip discount', 'two way discount',
                                        'aune jane chhut', 'jane aune chhut', 'फर्कने छुट', 'रिटर्न छुट', 'आउने जाने छुट', 'રિટર્ન ડિસ્કાઉન્ટ'],
                                       ['min' => 0, 'max' => 20, 'def' => '0', 'zero' => 'बन्द · off', 'unit' => '%',
                                        'need' => ['%', 'percent', 'pratishat', 'प्रतिशत', 'ટકા']]],
        'return_discount_days'     => ['🔁 फर्कने छुट: कति दिनभित्र · Return discount within', 'int',
                                       ['return discount', 'return chhut', 'return chut', 'return ko chhut', 'round trip discount', 'two way discount',
                                        'aune jane chhut', 'jane aune chhut', 'फर्कने छुट', 'रिटर्न छुट', 'आउने जाने छुट', 'રિટર્ન ડિસ્કાઉન્ટ'],
                                       ['min' => 1, 'max' => 90, 'def' => '30', 'zero' => '', 'unit' => 'days',
                                        'need' => ['din', 'dinma', 'dinbhitra', 'day', 'days', 'दिन', 'दिनभित्र', 'દિવસ']]],
        'luggage_fee_per_piece'    => ['🧳 थप सामान शुल्क, प्रति थान · Extra luggage fee per piece', 'int',
                                       ['luggage fee', 'luggage charge', 'luggage ko paisa', 'luggage per piece', 'baggage fee', 'baggage charge',
                                        'saman fee', 'saman charge', 'saman ko paisa', 'saman ko fee', 'लगेज शुल्क', 'सामान शुल्क', 'लगेज फी', 'सामान फी', 'સામાન ફી'],
                                       ['min' => 0, 'max' => 2000, 'def' => '0', 'zero' => 'बन्द · off', 'unit' => '₹/piece']],
        'infant_seat_on'           => ['👶 काखे बच्चा (सिट बिना) थप्ने · Infant on the lap (desk)', 'bool',
                                       ['infant', 'infant seat', 'baby seat', 'lap baby', 'lap infant', 'kakhe bachcha', 'kaakhe bachcha', 'godi bachcha',
                                        'काखे बच्चा', 'शिशु', 'गोदी बच्चा', 'ખોળામાં બાળક']],
        'infant_seat_fare'         => ['👶 काखे बच्चाको भाडा · Infant (lap) fare', 'int',
                                       ['infant fare', 'infant seat fare', 'infant price', 'infant charge', 'infant fee', 'baby fare', 'baby seat fare',
                                        'lap baby fare', 'kakhe bachcha ko bhada', 'शिशु भाडा', 'शिशु सिट भाडा', 'काखे बच्चाको भाडा', 'બાળક ભાડું'],
                                       ['min' => 0, 'max' => 3000, 'def' => '0', 'zero' => 'निःशुल्क · free', 'unit' => '₹']],
        'festival_theme_on'        => ['🎉 चाडपर्वको रंग र ब्यानर · Festival colours & banner', 'bool',
                                       ['festival', 'festival theme', 'festival colour', 'festival color', 'festival banner', 'festival rang',
                                        'tyohar theme', 'chad theme', 'chaad theme', 'चाडपर्व', 'चाड थिम', 'त्योहार थीम', 'તહેવાર']],
        'login_theme'              => ['🎨 लगइन पेजको रूप · Login page look', 'enum',
                                       ['login theme', 'login look', 'login design', 'login colour', 'login color', 'login rang', 'gold login', 'golden login',
                                        'classic login', 'लगइन थिम', 'लगइन रूप', 'लगइन रंग'],
                                       ['classic' => 'Classic — अहिलेको जस्तै · as today', 'gold' => 'Gold — सुनौलो · golden']],
        // a bare "facebook / fb" is Messenger (fb_msgr_on); "comment" names these two (EASY_OVER keeps the narrower one)
        'fb_comments_on'           => ['💬 Facebook कमेन्ट पढ्ने र जवाफ · Facebook Page comments', 'bool',
                                       ['fb comment', 'fb comments', 'facebook comment', 'facebook comments', 'comment', 'comments', 'कमेन्ट', 'टिप्पणी', 'કોમેન્ટ']],
        'fb_comment_ai_on'         => ['🤖 Facebook कमेन्टमा AI जवाफ · AI replies to FB comments', 'bool',
                                       ['fb comment ai', 'facebook comment ai', 'comment ai', 'comments ai', 'ai comment', 'ai comments', 'comment reply ai',
                                        'comment ma ai', 'comment ko ai', 'कमेन्ट ai', 'कमेन्टमा ai']],

        /* 16 Nov 2026 (Master v2, unit Y2): the switches the Y-units' migrations create (Y5 agents and
           help-desk, the WhatsApp inbox) — all OFF / 0 until the owner flips them here. Never a bare
           "followup", "cod" or "offer": each is named by its own two-word phrase. */
        'agent_followups_on'       => ['🧑‍💼 एजेन्टलाई आफ्नो यात्रुको सेवा फलो-अप देखाउने · Agents see service follow-ups on their own passengers', 'bool',
                                       ['agent followup', 'agent followups', 'agent follow up', 'agent follow ups', 'agent follow-up', 'agent follow-ups',
                                        'agent ko followup', 'agent lai followup', 'agent ko follow up', 'agent lai follow up', 'agent gunaso', 'agent ko gunaso',
                                        'agent lai gunaso', 'mera followup', 'mero followup', 'एजेन्ट गुनासो', 'एजेन्टलाई गुनासो', 'एजेन्ट फलो-अप', 'एजेन्टको फलो-अप', 'मेरा फलो-अप']],
        'helpdesk_cancel_escalate_inr' => ['🎫 रिफन्ड बाँकी भएको यति ₹ भन्दा माथिको रद्द टिकटमा फलो-अप खोल्ने · Open a follow-up when a cancelled ticket at or above ₹ has a refund owed', 'int',
                                       ['cancel escalate', 'cancel escalation', 'cancel followup', 'cancel follow up', 'cancel follow-up', 'cancelled followup',
                                        'helpdesk cancel', 'cancel ticket followup', 'refund followup', 'refund follow up', 'radda followup', 'रद्द फलो-अप', 'रद्द फलोअप',
                                        'रद्द टिकट फलो-अप'],
                                       ['min' => 0, 'max' => 100000, 'def' => '0', 'zero' => 'बन्द · off', 'unit' => '₹']],
        'wa_agent_settle_cod_on'   => ['💵 एजेन्टले आफ्नो बेचेको टिकटको नगद WhatsApp बाट मिलाउन पाउने · Agents may record cash for their own pay-at-boarding tickets on WhatsApp', 'bool',
                                       ['agent cod settle', 'agent settle cod', 'agent cod whatsapp', 'whatsapp cod settle', 'wa cod settle', 'cod settle', 'settle cod',
                                        'agent cash whatsapp', 'agent nagad whatsapp', 'whatsapp ma nagad', 'whatsapp nagad', 'agent ko nagad whatsapp',
                                        'एजेन्ट नगद whatsapp', 'एजेन्ट नगद व्हाट्सएप']],
        'wa_inbox_promo_link_on'   => ['🔗 WhatsApp इनबक्समा अफर लिङ्क · Offer link in the WhatsApp inbox', 'bool',
                                       ['promo link', 'offer link', 'inbox offer', 'inbox promo', 'inbox offer link', 'whatsapp inbox offer', 'wa inbox offer', 'inbox link',
                                        'अफर लिङ्क', 'अफर लिंक', 'इनबक्स अफर']],
    ];
    /** where a switch's row goes when the Easy Settings page writes it first (an existing row keeps its own group) */
    private const EASY_GROUPS = [
        'ai_change_wa_on' => 'ai', 'ai_settings_engine' => 'ai', 'ai_trip_delay_on' => 'ai', 'reminder_24h_enabled' => 'notify',
        'trip_alert_agents_on' => 'notify', 'wa_handoff_msg_on' => 'whatsapp', 'wa_typing_on' => 'whatsapp', 'wa_ai_cancel_mode' => 'whatsapp',
        'offline_callback_on' => 'app', 'social_publish_on' => 'marketing', 'wa_global_daily_cap' => 'whatsapp', 'device_limit_agent' => 'security',
        'device_limit_counter' => 'security', 'group_discount_on' => 'booking', 'group_discount_min_seats' => 'booking', 'group_discount_percent' => 'booking',
        'auto_arrive_hours' => 'booking', 'agent_settlement_due_days' => 'agent', 'agent_settle_reminder_days' => 'agent',
        'wa_good_morning_tpl_on' => 'whatsapp', 'ap_ai_on' => 'agentpro', 'agent_cancel_mode' => 'booking',
        // X5: the same groups the X1 / X2 / X4 migrations use (login_theme: X3, the look of a page)
        'return_discount_pct' => 'booking', 'return_discount_days' => 'booking', 'luggage_fee_per_piece' => 'booking',
        'infant_seat_on' => 'booking', 'infant_seat_fare' => 'booking', 'festival_theme_on' => 'app', 'login_theme' => 'app',
        'fb_comments_on' => 'facebook', 'fb_comment_ai_on' => 'facebook',
        // Y2: the groups upgrade-2026-11-16d-Y5-agents.sql uses (wa_inbox_promo_link_on: the WhatsApp inbox)
        'agent_followups_on' => 'agent', 'helpdesk_cancel_escalate_inr' => 'helpdesk', 'wa_agent_settle_cod_on' => 'ai', 'wa_inbox_promo_link_on' => 'whatsapp',
    ];
    /** a switch named in a sentence that also carries the wider one's word: "24 ghanta ko reminder band" is
        the 24-hour reminder, never also the trip-reminder master switch */
    private const EASY_OVER = [
        'reminder_24h_enabled'       => ['trip_reminders_enabled'],
        'agent_settle_reminder_days' => ['trip_reminders_enabled'],
        'social_publish_on'          => ['fb_msgr_on'],
        'fb_comments_on'             => ['fb_msgr_on'],
        'fb_comment_ai_on'           => ['fb_msgr_on', 'fb_comments_on'],
        'wa_agent_settle_cod_on'     => ['allow_cod'],   // Y2: "agent cod settle on" is not Cash on boarding
        'wa_inbox_promo_link_on'     => ['wa_marketing_on'],
    ];
    /** read by the public booking page (SHG_BOOT.settings) — public when the page writes the row first */
    private const EASY_PUBLIC = ['seat_pick_manual_first', 'group_discount_on', 'group_discount_min_seats', 'group_discount_percent', 'offline_callback_on',
                                 'return_discount_pct'];   // X5: public as in the X1 migration
    private const EASY_ON  = ['on', 'chalu', 'chalau', 'chalaideu', 'chalaidinu', 'chalaunu', 'khol', 'kholdeu', 'kholdinu', 'enable', 'start', 'activate', 'yes', 'haan', 'gar on',
                              'चालु', 'खोल', 'चलाउ', 'सुरु', 'अन', 'शुरू', 'चालू', 'करो चालू', 'खोलो', 'shuru', 'suru', 'lagau', 'lagaideu', 'lagaa',
                              'ચાલુ', 'શરૂ', 'ખોલો'];
    private const EASY_OFF = ['off', 'band', 'banda', 'bandh', 'bnd', 'disable', 'stop', 'roka', 'rok', 'rokdeu', 'hatau', 'hatai', 'na chalau', 'nachalau', 'deactivate',
                              'बन्द', 'बंद', 'रोक', 'हटाउ', 'अफ', 'रोको', 'hatao', 'બંધ', 'રોકો'];
    /** the default each switch has in the code that reads it, so an unsaved switch shows what really happens */
    private const EASY_DEFAULT_ON = ['quick_unlock_on', 'login_one_door_hi_on', 'agent_chalani_on', 'ap_join_open', 'whatsapp_notify_customer',
                                     'whatsapp_notify_admin', 'wa_booking_on', 'allow_cod', 'trip_reminders_enabled', 'seat_pick_manual_first',
                                     'smartbook_desk_seat_auto', 'staff_door_only', 'chalani_public_on', 'cancel_fine_on'];

    public static function easyCurrent(string $k): string
    {
        $t = self::EASY_KEYS[$k][1] ?? 'bool';
        if ($t === 'bool') {
            return Settings::getBool($k, in_array($k, self::EASY_DEFAULT_ON, true)) ? '1' : '0';
        }
        if ($t === 'int' || $t === 'float') {   // 16 Nov 2026 (W3): number knobs, with the default the code reads them with
            $def = (string) (self::EASY_KEYS[$k][3]['def'] ?? '0');
            return $t === 'int' ? (string) Settings::getInt($k, (int) $def) : (string) Settings::getFloat($k, (float) $def);
        }
        return Settings::getString($k, $t === 'enum' ? (string) array_key_first(self::EASY_KEYS[$k][3]) : '');
    }

    public static function easyShow(string $k, string $v): string
    {
        $t = self::EASY_KEYS[$k][1] ?? 'bool';
        if ($t === 'bool') {
            return $v === '1' ? 'ON ✅' : 'OFF ⛔';
        }
        if ($t === 'int' || $t === 'float') {
            $spec = (array) (self::EASY_KEYS[$k][3] ?? []);
            if ((float) $v === 0.0 && (string) ($spec['zero'] ?? '') !== '') {
                return '0 · ' . $spec['zero'];
            }
            $u = (string) ($spec['unit'] ?? '');
            return $v . ($u === '' ? '' : ($u === '%' || $u[0] === '/' ? $u : ' ' . $u));
        }
        return (string) (self::EASY_KEYS[$k][3][$v] ?? $v);
    }

    /** A number knob's value, or null when it is not a number or out of its range. */
    public static function easyNumber(string $k, string $v): ?string
    {
        $t = self::EASY_KEYS[$k][1] ?? '';
        $v = trim($v);
        if (($t !== 'int' && $t !== 'float') || !is_numeric($v)) {
            return null;
        }
        $spec = (array) self::EASY_KEYS[$k][3];
        $n = $t === 'int' ? (float) (int) round((float) $v) : round((float) $v, 2);
        if ($n < (float) $spec['min'] || $n > (float) $spec['max']) {
            return null;
        }
        return $t === 'int' ? (string) (int) $n : (string) $n;
    }

    /** The switches a sentence names, with on / off (the verb said LAST wins). @return array<string,string> */
    public static function parseSwitches(string $text): array
    {
        $t     = ' ' . mb_strtolower(preg_replace('/\s+/u', ' ', strtr($text, self::DIGITS)) ?? $text) . ' ';
        $onAt  = self::hasWord($t, self::EASY_ON);
        $offAt = self::hasWord($t, self::EASY_OFF);
        $out   = [];
        $spans = [];   // key => [byte offset, length] of the longest word it was named by
        foreach (self::EASY_KEYS as $k => $def) {
            $hit = null;
            foreach (array_merge([$k], $def[2]) as $w) {   // the key itself ("device_limit_agent 2") names it too
                $w = mb_strtolower($w);
                /* a roman word must START a word (16 Nov 2026 review): "Seat U15 block … band" is not the
                   "lock" of PIN login, "meta" not "eta", "chatbot" not the WhatsApp "bot" */
                /* review 2: a short one (cod, pin, kb, fb, eta, bot) must also END the word — "code login band"
                   proposed Cash on boarding OFF, "pincode" the PIN login */
                $at = -1;
                if (preg_match('/^[a-z]/', $w) === 1) {
                    if (preg_match('/(?<![a-z])' . preg_quote($w, '/') . (strlen($w) <= 3 ? '(?![a-z])' : '') . '/u', $t, $m, PREG_OFFSET_CAPTURE) === 1) {
                        $at = (int) $m[0][1];
                    }
                } elseif (($p = strpos($t, $w)) !== false) {
                    $at = (int) $p;
                }
                if ($at >= 0 && ($hit === null || strlen($w) > $hit[1])) {
                    $hit = [$at, strlen($w), $w];
                }
            }
            if ($hit === null) {
                continue;
            }
            if ($def[1] === 'bool') {
                if ($onAt < 0 && $offAt < 0) {
                    continue;
                }
                $out[$k] = $offAt > $onAt ? '0' : '1';   // "on chha, band gara" → off
            } elseif ($def[1] === 'enum') {
                foreach ($def[3] as $opt => $lbl) {
                    if (mb_strpos($t, (string) $opt) !== false) {
                        $out[$k] = (string) $opt;
                    }
                }
                // only a switch that HAS a 'request' choice may take it from "anurodh / office" (W3: two more enums now)
                if (!isset($out[$k]) && isset($def[3]['request']) && preg_match('/(request|anurodh|अनुरोध|office)/u', $t) === 1) {
                    $out[$k] = 'request';
                }
            } else {
                /* a number knob (16 Nov 2026, W3): "device limit agent 2", "whatsapp daily cap 500",
                   "group discount 10 percent" / "group discount 6 seat" (the number next to its own
                   word), "auto arrive band" = 0 */
                $before = substr($t, 0, $hit[0]);
                $after  = substr($t, $hit[0] + $hit[1]);
                $numRe  = '/(?<![\d.:\-])(\d{1,6}(?:\.\d{1,2})?)(?![\d.:\-])/';
                $num    = null;
                if (isset($def[3]['need'])) {
                    $rest = $before . ' ' . $after;
                    $need = implode('|', array_map(static fn(string $x): string => preg_quote(mb_strtolower($x), '/'), $def[3]['need']));
                    /* X5 review: the number said AFTER the knob's own name first, else the nearest one before it -
                       "group discount 10 percent return discount 5 percent" gave the return discount 10 */
                    foreach ([$after, $before] as $pi => $part) {
                        if (preg_match_all('/(?<![\d.])(\d{1,6}(?:\.\d{1,2})?)\s*(?:' . $need . ')(?![a-z])/u', $part, $m) > 0
                            || preg_match_all('/(?<![a-z])(?:' . $need . ')\s*(\d{1,6}(?:\.\d{1,2})?)(?![\d.])/u', $part, $m) > 0) {
                            $num = $pi === 0 ? (string) $m[1][0] : (string) end($m[1]);
                            break;
                        }
                    }
                    if ($num === null && (string) ($def[3]['zero'] ?? '') !== '' && $offAt >= 0 && $offAt > $onAt
                        && preg_match('/\d/', $rest) !== 1) {
                        $num = '0';   // X5: "return discount band" = 0 (only a knob whose 0 means off, and no number said at all)
                    }
                } elseif (preg_match($numRe, $after, $m) === 1) {          // the number said after the knob's name …
                    $num = $m[1];
                } elseif (preg_match_all($numRe, $before, $m) > 0) {       // … or the last one before it
                    $num = (string) end($m[1]);
                } elseif ($offAt >= 0 && $offAt > $onAt) {
                    $num = '0';
                }
                $v = $num !== null ? self::easyNumber((string) $k, $num) : null;
                if ($v === null) {
                    continue;
                }
                $out[$k] = $v;
            }
            $spans[$k] = $hit;
        }
        /* "24 ghanta reminder on" names the 24-hour reminder, not also the trip-reminder master
           switch ("reminder"); "facebook post" is not Messenger ("facebook"): a key named only by
           a word INSIDE another key's longer word is dropped */
        foreach ($spans as $k => [$at, $len]) {
            foreach ($spans as $k2 => [$at2, $len2]) {
                if ($k2 !== $k && $len2 > $len && $at >= $at2 && $at + $len <= $at2 + $len2) {
                    unset($out[$k]);
                    break;
                }
            }
        }
        foreach (self::EASY_OVER as $k => $wider) {
            if (isset($out[$k])) {
                foreach ($wider as $w) {
                    unset($out[$w]);
                }
            }
        }
        return $out;
    }

    /** One switch, saved the way the switches page saves it, audited settings.easy. */
    public static function saveSwitch(string $k, string $v, int $adminId): void
    {
        $old  = self::easyCurrent($k);
        $t    = self::EASY_KEYS[$k][1];
        $type = $t === 'bool' ? 'bool' : ($t === 'int' || $t === 'float' ? $t : 'string');
        // seat_pick_manual_first is read by the public booking page too (SHG_BOOT.settings) — public on first save
        Settings::set($k, $type === 'bool' ? ($v === '1') : $v, $type, self::EASY_GROUPS[$k] ?? 'general', in_array($k, self::EASY_PUBLIC, true));
        Logger::audit('settings.easy', 'setting', $k, ['value' => $old], ['value' => $v], 'Easy Settings by admin #' . $adminId);
    }

    /** The switches are known to the AI path too (label, type, options, value now). */
    public static function registerEasyKeys(): void
    {
        $reg = [];
        foreach (self::EASY_KEYS as $k => $def) {
            $reg[$k] = [$def[0], $def[1], $def[1] === 'enum' ? (array) $def[3] : [], self::easyCurrent($k)];
        }
        self::registerKeys($reg);
    }

    /**
     * Read a command: what would change, nothing written.
     *
     * @return array{cmd:string, switches:array<string,string>, ops:list<array<string,mixed>>, via:string, denied:list<string>, note:string}
     */
    public static function propose(string $cmd, bool $canEdit, bool $useAi = true): array
    {
        self::registerEasyKeys();
        $ops  = self::parseWords($cmd);
        $note = self::$note;
        $sw   = $canEdit ? self::parseSwitches($cmd) : [];
        $via  = 'words';
        if ($ops === [] && $sw === [] && $note === '' && $useAi) {
            $ops = self::parseAi($cmd);
            $via = 'AI';
        }
        // drop no-ops and what this login may not do
        $sw = array_filter($sw, static fn($v, $k): bool => self::easyCurrent((string) $k) !== (string) $v, ARRAY_FILTER_USE_BOTH);
        $denied = [];
        $ops = array_values(array_filter($ops, static function (array $a) use (&$denied, $sw): bool {
            if ((string) $a['type'] === 'setting' && isset($sw[(string) ($a['key'] ?? '')])) {
                return false;   // "ap_ai_on band" is one switch, not a switch AND the same setting twice (W3)
            }
            if (!self::allowed($a)) {
                $denied[] = (string) $a['type'];
                return false;
            }
            foreach (self::preview($a) as $r) {
                if ((string) $r[1] !== (string) $r[2]) {
                    return true;
                }
            }
            return false;
        }));
        return ['cmd' => $cmd, 'switches' => $sw, 'ops' => $ops, 'via' => $via, 'denied' => array_values(array_unique($denied)), 'note' => $note];
    }

    /** @return list<array{what:string, old:string, new:string}> every row that would change */
    public static function changes(array $prop): array
    {
        $out = [];
        foreach ((array) ($prop['switches'] ?? []) as $k => $v) {
            if (isset(self::EASY_KEYS[$k])) {
                $out[] = ['what' => self::EASY_KEYS[$k][0], 'old' => self::easyShow((string) $k, self::easyCurrent((string) $k)), 'new' => self::easyShow((string) $k, (string) $v)];
            }
        }
        foreach ((array) ($prop['ops'] ?? []) as $a) {
            foreach (self::preview($a) as [$what, $old, $new]) {
                if ((string) $old !== (string) $new) {
                    $out[] = ['what' => (string) $what, 'old' => (string) $old, 'new' => (string) $new];
                }
            }
        }
        return $out;
    }

    /** What the office sees when nothing can be proposed. */
    public static function emptyMessage(array $prop): string
    {
        if ((array) ($prop['denied'] ?? []) !== []) {
            return '⛔ यो बदल्ने अनुमति तपाईंसँग छैन · Not allowed for your login (' . implode(', ', (array) $prop['denied']) . ').';
        }
        if ((string) ($prop['note'] ?? '') !== '') {
            return (string) $prop['note'];
        }
        return '🤔 बुझिएन वा पहिल्यै त्यस्तै छ · Not understood, or already like that. अर्को शब्दमा भन्नुहोस्, वा तलको switch / खोज प्रयोग गर्नुहोस्।';
    }

    /** P3-1c: the parsed actions of every proposal shown (settings.ai_proposed). */
    public static function auditProposed(array $prop): void
    {
        Logger::audit('settings.ai_proposed', 'setting', 'easy-settings', null,
            ['cmd' => (string) ($prop['cmd'] ?? ''), 'via' => (string) ($prop['via'] ?? ''), 'actions' => array_values((array) ($prop['ops'] ?? [])), 'switches' => (array) ($prop['switches'] ?? [])],
            mb_substr(implode(' | ', array_map(static fn(array $c): string => $c['what'] . ': ' . $c['old'] . ' → ' . $c['new'], self::changes($prop))), 0, 480));
    }

    /** P3-1c: a proposal the office said No to (settings.ai_declined). */
    public static function auditDeclined(array $prop): void
    {
        Logger::audit('settings.ai_declined', 'setting', 'easy-settings', null,
            ['cmd' => (string) ($prop['cmd'] ?? ''), 'via' => (string) ($prop['via'] ?? ''), 'actions' => array_values((array) ($prop['ops'] ?? [])), 'switches' => (array) ($prop['switches'] ?? [])],
            'Declined — nothing changed');
    }

    /**
     * "Yes": write it — the switches (settings managers only) and the actions (each re-cleaned and
     * permission-checked again by apply()) — and audit settings.ai_command with the actions.
     *
     * @return list<string> one line per change
     */
    public static function confirm(array $prop, array $admin, bool $canEdit): array
    {
        /* review 16 Nov 2026: the "Yes" request must know the switches too — a 'setting' action on a
           switch with no settings row yet (live: agent_chalani_on, seat_pick_manual_first …) was
           dropped by clean() in admin/api/ai-settings.php, so "Haan" saved nothing */
        self::registerEasyKeys();
        $done = [];
        if ($canEdit) {
            foreach ((array) ($prop['switches'] ?? []) as $k => $v) {
                if (isset(self::EASY_KEYS[$k])) {
                    self::saveSwitch((string) $k, (string) $v, (int) ($admin['id'] ?? 0));
                    $done[] = self::EASY_KEYS[$k][0] . ' → ' . self::easyShow((string) $k, (string) $v);
                }
            }
        }
        foreach (self::apply((array) ($prop['ops'] ?? []), $admin) as $line) {
            $done[] = $line;
        }
        Logger::audit('settings.ai_command', 'setting', 'easy-settings', null,
            ['cmd' => (string) ($prop['cmd'] ?? ''), 'via' => (string) ($prop['via'] ?? ''), 'actions' => array_values((array) ($prop['ops'] ?? []))],
            implode(' | ', $done));
        // 16 Nov 2026 (P3-4b): admin/easy-settings.php act=yes and admin/api/ai-settings.php confirm both land here
        self::notifyChange($admin, $done);
        return $done;
    }

    /* =================================================================
     *  P3-4b (16 Nov 2026): one WhatsApp line to the office after an AI
     *  change — "🤖 <who> changed: <lines>" — only while ai_change_wa_on
     *  is ON (default OFF) and the office alerts are on (the same number
     *  and switch every office alert uses). Audited; never throws.
     * ================================================================= */

    /** @var null|Closure(string, string): (bool|string) tests catch the send here instead of WhatsApp */
    public static ?Closure $waSend = null;

    /** The one line, or '' when nothing was changed (refused / failed lines are left out). @param list<string> $done */
    public static function changeMessage(array $admin, array $done): string
    {
        $lines = array_values(array_filter(array_map('strval', $done),
            static fn(string $l): bool => trim($l) !== '' && !str_starts_with($l, '⛔') && !str_starts_with($l, '⚠️')));
        if ($lines === []) {
            return '';
        }
        $who = trim((string) ($admin['full_name'] ?? ''));
        if ($who === '') {
            $who = trim((string) ($admin['username'] ?? '')) !== '' ? trim((string) $admin['username']) : 'admin #' . (int) ($admin['id'] ?? 0);
        }
        return mb_substr('🤖 ' . $who . ' changed: ' . implode(' · ', $lines), 0, 1000);
    }

    /** @param list<string> $done @return bool true when a message was handed to WhatsApp */
    public static function notifyChange(array $admin, array $done): bool
    {
        try {
            if (!Settings::getBool('ai_change_wa_on', false)) {
                return false;
            }
            $msg = self::changeMessage($admin, $done);
            if ($msg !== '' && !class_exists('Notify')) {
                require_once __DIR__ . '/notify.php';   // admin pages have it already; a CLI caller may not
            }
            if ($msg === '' || !class_exists('Notify') || !method_exists('Notify', 'adminWhatsappReady') || !Notify::adminWhatsappReady()) {
                return false;
            }
            $phone = Settings::getString('admin_whatsapp', Settings::officePhone());
            $send  = self::$waSend;
            $r = $send !== null ? $send($phone, $msg)
                : Notify::whatsapp($phone, $msg, null, null, [], null, ['purpose' => 'ai_change', 'admin_id' => (int) ($admin['id'] ?? 0)]);
            Logger::audit('settings.ai_change_wa', 'setting', 'ai_change_wa_on', null,
                ['to' => '…' . substr((string) preg_replace('/\D+/', '', $phone), -4), 'sent' => $r === true, 'link' => is_string($r)], $msg);
            return $r !== false;
        } catch (Throwable $e) {
            Logger::warning('AI change WhatsApp not sent: ' . $e->getMessage(), [], 'whatsapp');
            return false;
        }
    }
}
