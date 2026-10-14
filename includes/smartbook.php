<?php
/**
 * =====================================================================
 *  SmartBook — ONE booking engine, many faces (15 Oct 2026, SmartBook v5 §4).
 *
 *  Every booking screen calls this: the home BookingCard (customer), the
 *  QuickBot line, Sahayak, Agent mode, Counter mode and Admin. It sits on
 *  top of the engine that has been taking money since August and adds
 *  nothing that could sell a berth twice:
 *
 *      which bus / pickup / date   QuickTicket::plan()  (cut-offs, OFF days,
 *                                   extra buses, booking window — unchanged)
 *      which berths                AutoBerth::pick()    (H1-H5 + S1-S7)
 *      what it costs               BookingService::priceFor() — the very
 *                                   function create() charges with
 *      the sale                    BookingService::create() — row locks,
 *                                   UNIQUE(schedule, seat), gender lock,
 *                                   women-only check, ticket, WhatsApp
 *
 *  What is new here:
 *    - points(direction, mode)  the boarding / drop list with visible_to
 *    - defaults(...)            smart defaults (history, location, most used)
 *    - quote(...)               the card's live summary; never writes
 *    - book(..., pay)           Cash | Online IS the booking; idempotent
 *                               (smartbook_requests.idem_key UNIQUE)
 *
 *  A customer request never carries an agent / counter / staff code (D13):
 *  the mode comes from the SESSION, never from the payload.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/autoberth.php';
require_once __DIR__ . '/quickticket.php';
require_once __DIR__ . '/boarding.php';
require_once __DIR__ . '/sitefacts.php';

/** A quote that moved since the card showed it — the card re-renders. */
final class SmartBookChanged extends RuntimeException
{
    /** @param array<string, mixed> $quote */
    public function __construct(string $message, public readonly array $quote)
    {
        parent::__construct($message);
    }
}

final class SmartBook
{
    public const MODES = ['customer', 'agent', 'counter', 'admin'];

    /** The booking mode a SESSION may use — never what the client claims. */
    public static function sessionMode(string $asked = ''): string
    {
        $admin = Auth::admin();
        if ($admin === null || !Auth::isSellingStaff()) {
            return 'customer';
        }
        $role = (string) ($admin['role'] ?? '');
        $own  = $role === 'agent' ? 'agent' : ($role === 'counter' ? 'counter' : 'admin');
        // An admin may open Agent or Counter mode (D2); nobody may go "up".
        if ($own === 'admin' && in_array($asked, ['agent', 'counter', 'admin'], true)) {
            return $asked;
        }
        return $own;
    }

    /* =================================================================
     *  Lists
     * ================================================================= */

    /**
     * The two runs, in the order the switch shows them.
     *
     * @return list<array{direction: string, routeId: int, from: string, to: string, depTime: string}>
     */
    public static function directions(): array
    {
        $out = [];
        foreach (QuickTicket::routes() as $r) {
            $out[] = [
                'direction' => (string) $r['direction'],
                'routeId'   => (int) $r['id'],
                'from'      => (string) $r['from_city'],
                'to'        => (string) $r['to_city'],
                'depTime'   => substr((string) ($r['dep_time'] ?? ''), 0, 5),
            ];
        }
        usort($out, static fn(array $a, array $b): int => ($a['direction'] === 'toNepal' ? 0 : 1) <=> ($b['direction'] === 'toNepal' ? 0 : 1));
        return $out;
    }

    private static ?bool $hasVisibleTo = null;

    private static function hasVisibleTo(): bool
    {
        if (self::$hasVisibleTo === null) {
            try {
                self::$hasVisibleTo = (bool) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'route_stops' AND column_name = 'visible_to'"
                );
            } catch (Throwable $e) {
                self::$hasVisibleTo = false;
            }
        }
        return self::$hasVisibleTo;
    }

    /**
     * The points the passenger chooses for a direction: pickups going to
     * Nepal, drops coming back. Only stops switched ON and visible to the
     * mode (D10: Rajkot is visible to every mode like any other stop).
     *
     * @return array{type: string, routeId: int, fixed: string, list: list<array<string, mixed>>}
     */
    public static function points(string $direction, string $mode = 'customer'): array
    {
        $route = null;
        foreach (self::directions() as $d) {
            if ($d['direction'] === $direction) {
                $route = $d;
                break;
            }
        }
        if ($route === null) {
            return ['type' => 'boarding', 'routeId' => 0, 'fixed' => '', 'list' => []];
        }
        $type    = $direction === 'toIndia' ? 'drop' : 'boarding';
        $visible = self::hasVisibleTo() ? ', visible_to' : '';
        $rows = Database::fetchAll(
            "SELECT stop_name, landmark, stop_time, fare, latitude, longitude{$visible}
               FROM route_stops
              WHERE route_id = :r AND stop_type = :t AND is_active = 1
           ORDER BY sort_order, id",
            ['r' => $route['routeId'], 't' => $type]
        );
        $want = $mode === 'admin' ? '' : $mode;
        $list = [];
        foreach ($rows as $r) {
            if ($want !== '' && isset($r['visible_to']) && !in_array($want, explode(',', (string) $r['visible_to']), true)) {
                continue;
            }
            $time = $r['stop_time'] !== null ? substr((string) $r['stop_time'], 0, 5) : '';
            $disp = Boarding::stopDisplay((string) $r['stop_name'] . ($time !== '' ? ' @ ' . $time : ''));
            $list[] = [
                'name'     => (string) $r['stop_name'],
                'short'    => (string) $disp['name'],
                'code'     => (string) $disp['code'],
                'landmark' => (string) ($r['landmark'] ?? ''),
                'time'     => $time,
                'fare'     => $r['fare'] !== null && (float) $r['fare'] > 0 ? (float) $r['fare'] : null,
                'lat'      => $r['latitude'] !== null ? (float) $r['latitude'] : null,
                'lng'      => $r['longitude'] !== null ? (float) $r['longitude'] : null,
            ];
        }
        // The other end of the run is fixed (Rupaidiha going back, the border going out).
        $fixed = $type === 'drop' ? (string) $route['from'] : (string) $route['to'];
        return ['type' => $type, 'routeId' => (int) $route['routeId'], 'fixed' => $fixed, 'list' => $list];
    }

    /* =================================================================
     *  Smart defaults (§8) — Default Rules (§11) are applied on top by
     *  DefaultRules::apply() once the office has saved any.
     * ================================================================= */

    /**
     * @param array<string, mixed> $in phone, lat, lng, direction (optional)
     * @return array<string, mixed> direction, point, pax, type, berth, pay + why{field: {en,hi,ne}} + history bool
     */
    public static function defaults(array $in, string $mode = 'customer'): array
    {
        $phone   = normalisePhone((string) ($in['phone'] ?? ''));
        $history = $phone !== '' && self::mayReadHistory($phone, $mode) ? self::history($phone) : null;
        $why     = [];

        // Direction: opposite of the last one-way trip when it was > 7 days ago, else the same.
        $dir = in_array($in['direction'] ?? '', ['toNepal', 'toIndia'], true) ? (string) $in['direction'] : '';
        if ($dir === '' && $history !== null && $history['direction'] !== '') {
            $dir = $history['direction'];
            if ($history['daysAgo'] !== null && $history['daysAgo'] > 7) {
                $dir = $dir === 'toNepal' ? 'toIndia' : 'toNepal';
                $why['direction'] = self::why('Return trip — your last trip was ' . $history['daysAgo'] . ' days ago',
                    'वापसी — पिछली यात्रा ' . $history['daysAgo'] . ' दिन पहले', 'फर्कने यात्रा — पछिल्लो यात्रा ' . $history['daysAgo'] . ' दिन अघि');
            } else {
                $why['direction'] = self::why('Same as your last trip', 'पिछली यात्रा जैसा', 'पहिलेको यात्रा जस्तै');
            }
        }
        if ($dir === '') {
            $dir = self::mostUsedDirection();
        }

        $points = self::points($dir, $mode);
        $names  = array_map(static fn(array $p): string => $p['name'], $points['list']);
        $point  = '';
        // (1) this mobile's most-used point
        if ($history !== null) {
            $want = $points['type'] === 'drop' ? $history['drop'] : $history['boarding'];
            $hit  = self::matchPoint($want, $names);
            if ($hit !== '') {
                $point = $hit;
                $why['point'] = self::why('You boarded at ' . self::short($hit) . ' before', 'पहले ' . self::short($hit) . ' से चढ़े थे', 'पहिले ' . self::short($hit) . ' बाट चढ्नुभएको');
                if ($points['type'] === 'drop') {
                    $why['point'] = self::why('You got off at ' . self::short($hit) . ' before', 'पहले ' . self::short($hit) . ' उतरे थे', 'पहिले ' . self::short($hit) . ' मा ओर्लनुभएको');
                }
            }
        }
        // (2) the nearest point to the browser's location (asked once, never blocks)
        if ($point === '' && is_numeric($in['lat'] ?? null) && is_numeric($in['lng'] ?? null)) {
            $near = self::nearest((float) $in['lat'], (float) $in['lng'], $points['list']);
            if ($near !== null) {
                $point = $near['name'];
                $why['point'] = self::why('Nearest to you (~' . $near['km'] . ' km)', 'आपके सबसे पास (~' . $near['km'] . ' km)', 'तपाईंको नजिक (~' . $near['km'] . ' km)');
            }
        }
        // (3) the most-used point for this direction (last 90 days)
        if ($point === '') {
            $mu = self::mostUsedPoint($dir, $points['type'], $names);
            if ($mu !== '') {
                $point = $mu;
                $why['point'] = self::why('Most passengers board here', 'ज़्यादातर यात्री यहीं से', 'धेरै यात्रु यहीँबाट चढ्छन्');
                if ($points['type'] === 'drop') {
                    $why['point'] = self::why('Most passengers get off here', 'ज़्यादातर यात्री यहीं उतरते', 'धेरै यात्रु यहीँ ओर्लन्छन्');
                }
            }
        }
        if ($point === '' && $names !== []) {
            $point = $names[0];
        }

        $pax = [['gender' => '', 'band' => 'adult']];
        $name = '';
        if ($history !== null && $history['pax'] !== []) {
            $pax  = $history['pax'];
            $name = $history['name'];
            $why['pax'] = self::why('Same party as last time', 'पिछली बार जैसे यात्री', 'पहिलेकै यात्रुहरू');
        }

        $out = [
            'direction' => $dir,
            'point'     => $point,
            'pointType' => $points['type'],
            'name'      => $name,
            'pax'       => $pax,
            'type'      => 'sharing',
            'cabin'     => 'single',
            'berth'     => 'any',
            'pay'       => Settings::getBool('allow_cod', true) && $mode === 'customer' ? 'cash' : 'online',
            'history'   => $history !== null,
            'why'       => $why,
        ];
        require_once __DIR__ . '/defaultrules.php';
        $ages = [];
        foreach ((array) ($in['ages'] ?? []) as $a) {
            if (is_numeric($a) && (int) $a > 0) {
                $ages[] = (int) $a;
            }
        }
        return DefaultRules::apply($out, ['phone' => $phone, 'history' => $history, 'mode' => $mode, 'points' => $names, 'ages' => $ages]);
    }

    /**
     * History may fill a card only for the number's proven owner (OTP
     * session) or for selling staff — a typed number never reveals a
     * stranger's name, party or habits.
     */
    private static function mayReadHistory(string $phone, string $mode): bool
    {
        if ($mode !== 'customer') {
            return Auth::isSellingStaff();
        }
        $me = Auth::user();
        if ($me === null || normalisePhone((string) ($me['phone'] ?? '')) !== $phone) {
            return false;
        }
        require_once __DIR__ . '/customerprofile.php';
        return CustomerProfile::verifiedUserId() === (int) ($me['id'] ?? 0);
    }

    /** @return array{name: string, direction: string, daysAgo: ?int, boarding: string, drop: string, pax: list<array{gender: string, band: string}>}|null */
    private static function history(string $phone): ?array
    {
        $b = Database::fetch(
            "SELECT b.id, l.travel_date, l.boarding_stop, l.drop_stop, r.to_city
               FROM bookings b
               JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
               JOIN schedules s ON s.id = l.schedule_id
               JOIN routes r ON r.id = s.route_id
              WHERE b.contact_phone = :p AND b.status IN ('confirmed','completed')
           ORDER BY l.travel_date DESC, b.id DESC LIMIT 1",
            ['p' => $phone]
        );
        if ($b === null) {
            return null;
        }
        $dir  = stripos((string) $b['to_city'], 'rupaidiha') !== false || stripos((string) $b['to_city'], 'nepal') !== false ? 'toNepal' : 'toIndia';
        $rows = Database::fetchAll(
            'SELECT full_name, age, gender, special_need, is_primary FROM booking_passengers WHERE booking_id = :b ORDER BY is_primary DESC, id',
            ['b' => (int) $b['id']]
        );
        $pax  = [];
        $name = '';
        foreach ($rows as $r) {
            if ($name === '' && (int) $r['is_primary'] === 1) {
                $name = (string) preg_replace('/\s*\(\d+\)$/', '', (string) $r['full_name']);
            }
            $age  = $r['age'] !== null ? (int) $r['age'] : null;
            $band = 'adult';
            $sp   = strtolower((string) ($r['special_need'] ?? ''));
            if (in_array($sp, ['patient', 'senior', 'child'], true)) {
                $band = $sp;
            } elseif ($age !== null && $age > 0 && $age < 12) {
                $band = 'child';
            } elseif ($age !== null && $age >= 60) {
                $band = 'senior';
            }
            $pax[] = ['gender' => (string) (AutoBerth::gender($r['gender']) ?? ''), 'band' => $band];
        }
        // The usual pickup across all of this number's trips, not just the last one.
        $most = Database::fetch(
            "SELECT l.boarding_stop, COUNT(*) n FROM bookings b JOIN booking_legs l ON l.booking_id = b.id
              WHERE b.contact_phone = :p AND b.status IN ('confirmed','completed') GROUP BY l.boarding_stop ORDER BY n DESC LIMIT 1",
            ['p' => $phone]
        );
        $days = (int) floor((strtotime(todayISO()) - strtotime((string) $b['travel_date'])) / 86400);
        return [
            'name'      => $name,
            'direction' => $dir,
            'daysAgo'   => $days >= 0 ? $days : null,
            'boarding'  => (string) ($most['boarding_stop'] ?? $b['boarding_stop'] ?? ''),
            'drop'      => (string) ($b['drop_stop'] ?? ''),
            'pax'       => array_slice($pax, 0, 6),
        ];
    }

    private static function mostUsedDirection(): string
    {
        $row = Database::fetch(
            "SELECT r.to_city, COUNT(*) n FROM bookings b
               JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
               JOIN schedules s ON s.id = l.schedule_id JOIN routes r ON r.id = s.route_id
              WHERE b.status IN ('confirmed','completed') AND b.created_at > NOW() - INTERVAL 90 DAY
           GROUP BY r.to_city ORDER BY n DESC LIMIT 1"
        );
        if ($row !== null && stripos((string) $row['to_city'], 'rupaidiha') === false) {
            return 'toIndia';
        }
        return 'toNepal';
    }

    /** @param list<string> $names */
    private static function mostUsedPoint(string $direction, string $type, array $names): string
    {
        if ($names === []) {
            return '';
        }
        $col  = $type === 'drop' ? 'l.drop_stop' : 'l.boarding_stop';
        $rows = Database::fetchAll(
            "SELECT {$col} AS stop, COUNT(*) n FROM bookings b
               JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
               JOIN schedules s ON s.id = l.schedule_id JOIN routes r ON r.id = s.route_id
              WHERE b.status IN ('confirmed','completed') AND b.created_at > NOW() - INTERVAL 90 DAY
                AND " . ($direction === 'toIndia' ? "r.to_city NOT LIKE '%Rupaidiha%'" : "r.to_city LIKE '%Rupaidiha%'") . "
           GROUP BY {$col} ORDER BY n DESC LIMIT 20"
        );
        $score = [];
        foreach ($rows as $r) {
            $hit = self::matchPoint((string) $r['stop'], $names);
            if ($hit !== '') {
                $score[$hit] = ($score[$hit] ?? 0) + (int) $r['n'];
            }
        }
        if ($score === []) {
            return '';
        }
        arsort($score);
        return (string) array_key_first($score);
    }

    /**
     * The configured point a free-text label names ("Baroda @ 17:00",
     * "amd s hari global parking", "Chiloda (AMD) · …") — same ticket code
     * or same town key. '' when none.
     *
     * @param list<string> $names
     */
    public static function matchPoint(string $label, array $names): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        $code = (string) Boarding::stopDisplay($label)['code'];
        $key  = Boarding::townKey($label);
        foreach ($names as $n) {
            if ($key !== '' && Boarding::townKey($n) === $key) {
                return $n;
            }
        }
        foreach ($names as $n) {
            if ($code !== '' && $code !== 'SHG' && (string) Boarding::stopDisplay($n)['code'] === $code) {
                return $n;
            }
        }
        foreach ($names as $n) {
            $k = Boarding::townKey($n);
            if ($key !== '' && $k !== '' && (str_contains($k, $key) || str_contains($key, $k))) {
                return $n;
            }
        }
        return '';
    }

    /** @param list<array<string, mixed>> $list */
    private static function nearest(float $lat, float $lng, array $list): ?array
    {
        $best = null;
        foreach ($list as $p) {
            if ($p['lat'] === null || $p['lng'] === null) {
                continue;
            }
            $km = self::haversine($lat, $lng, (float) $p['lat'], (float) $p['lng']);
            if ($best === null || $km < $best['km']) {
                $best = ['name' => $p['name'], 'km' => (int) round($km)];
            }
        }
        $max = Settings::getFloat('nearme_max_km', 150.0);
        return $best !== null && $best['km'] <= $max ? $best : null;
    }

    private static function haversine(float $a1, float $o1, float $a2, float $o2): float
    {
        $r = 6371.0;
        $d1 = deg2rad($a2 - $a1);
        $d2 = deg2rad($o2 - $o1);
        $h  = sin($d1 / 2) ** 2 + cos(deg2rad($a1)) * cos(deg2rad($a2)) * sin($d2 / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($h)));
    }

    /* =================================================================
     *  Quote — what the card shows. Read-only.
     * ================================================================= */

    /**
     * @param array<string, mixed> $in direction, date, point, pax[{gender,band,name}], type, cabin,
     *                                 berth, seats (manual), phone, prefer
     * @return array<string, mixed>
     * @throws RuntimeException passenger-safe
     */
    public static function quote(array $in, string $mode = 'customer'): array
    {
        $t0  = microtime(true);
        $req = self::normalise($in, $mode);
        $n   = count($req['pax']);

        $points = self::points($req['direction'], $mode);
        $point  = self::matchPoint($req['point'], array_map(static fn(array $p): string => $p['name'], $points['list']));
        if ($req['point'] !== '' && $point === '') {
            throw new RuntimeException('That point is not on this bus — choose from the list. / यो ठाउँ यो बसमा छैन — सूचीबाट छान्नुहोस्।');
        }

        $genders = array_values(array_unique(array_filter(array_map(static fn(array $p): ?string => AutoBerth::gender($p['gender']), $req['pax']))));
        $plan = QuickTicket::plan([
            'direction' => $req['direction'],
            'date'      => $req['date'],
            'boarding'  => $points['type'] === 'boarding' ? $point : '',
            'seats'     => $req['type'] === 'private' ? 1 : $n,
            'gender'    => count($genders) === 1 ? $genders[0] : null,
            'customer'  => $mode === 'customer',
            'phone'     => $req['phone'],
        ]);
        $route    = Database::fetch('SELECT * FROM routes WHERE id = :id LIMIT 1', ['id' => (int) $plan['routeId']]);
        $schedule = Seats::scheduleById((int) $plan['scheduleId']);
        if ($route === null) {
            throw new RuntimeException('That bus is not available. / यो बस उपलब्ध छैन।');
        }
        $coach = Seats::effectiveCoach($schedule, $route);
        $drop  = $points['type'] === 'drop' ? $point : '';

        /* ---- berths --------------------------------------------------- */
        $mode2   = $coach === 'sleeper' ? $req['type'] : null;
        $seats   = [];
        $reasons = [];
        $auto    = $req['seats'] === [];
        $err     = '';
        if ($mode2 === 'private') {
            $av     = Seats::availabilityForSchedule((int) $schedule['id'], 'private');
            $cabins = $req['cabin'] === 'double' ? (int) ceil($n / 2) : $n;
            if ($auto) {
                $seats = self::pickCabins($av, $cabins, $req['berth']);
                if (count($seats) < $cabins) {
                    $err = 'Not enough private cabins free on this bus. / यो बसमा पर्याप्त प्राइभेट क्याबिन खाली छैनन्।';
                    $seats = [];
                }
            } else {
                $free = array_flip(array_map('strval', $av['available'] ?? []));
                foreach ($req['seats'] as $s) {
                    if (!isset($free[$s])) {
                        $err = 'Cabin ' . Seats::displayLabel($s, $coach, 'private') . ' is not free any more. / यो क्याबिन खाली छैन।';
                    }
                }
                if (count($req['seats']) !== $cabins) {
                    $err = 'Choose ' . $cabins . ' cabin(s). / ' . $cabins . ' वटा क्याबिन छान्नुहोस्।';
                }
                $seats = $err === '' ? $req['seats'] : [];
            }
            foreach ($seats as $s) {
                $reasons[] = ['en' => 'Private cabin — only your booking', 'hi' => 'प्राइवेट केबिन — सिर्फ आपकी बुकिंग', 'ne' => 'प्राइभेट क्याबिन — तपाईंको मात्र'];
            }
        } else {
            $bt = $coach === 'sleeper' ? 'sharing' : 'seater';
            $av = Seats::availabilityForSchedule((int) $schedule['id'], $bt);
            if ($auto) {
                $pick = AutoBerth::pick($av, $req['pax'], ['pref' => $req['berth'], 'prefer' => $req['prefer'], 'coach' => $coach]);
                if ($pick['ok']) {
                    $seats   = $pick['seats'];
                    $reasons = $pick['reasons'];
                } else {
                    $err = 'No berths on this bus keep every safety rule for this group. / यो समूहका लागि सबै नियम मिल्ने बर्थ यो बसमा छैनन्।';
                }
            } else {
                $err = AutoBerth::checkManual($av, $req['seats'], $req['pax'], $coach);
                $seats = $err === '' ? $req['seats'] : [];
                foreach ($seats as $s) {
                    $reasons[] = ['en' => 'You chose this berth', 'hi' => 'आपकी चुनी बर्थ', 'ne' => 'तपाईंले छानेको बर्थ'];
                }
            }
        }

        /* ---- price (the sale's own function) ---------------------------- */
        $price = null;
        if ($seats !== []) {
            $price = BookingService::priceFor($route, $schedule, $seats, $mode2, $req['cabin'], (string) $plan['boarding'], $req['phone'], $drop);
        }
        $npr = round(((float) ($price['total'] ?? 0)) * (float) SiteFacts::all()['npr']);

        $out = [
            'ok'           => $seats !== [] && $err === '',
            'error'        => $err,
            'mode'         => $mode,
            'direction'    => (string) $plan['direction'],
            'routeId'      => (int) $plan['routeId'],
            'scheduleId'   => (int) $plan['scheduleId'],
            'slot'         => (int) $plan['slot'],
            'from'         => (string) $plan['from'],
            'to'           => (string) $plan['to'],
            'date'         => (string) $plan['date'],
            'dateLabel'    => (string) $plan['dateLabel'],
            'isToday'      => (bool) $plan['isToday'],
            'nextDate'     => addDaysISO((string) $plan['date'], 1),
            'window'       => $plan['window'] ?? null,
            'depTime'      => (string) $plan['depTime'],
            'boarding'     => (string) $plan['boarding'],
            'boardingName' => (string) $plan['boardingName'],
            'boardingTime' => (string) $plan['boardingTime'],
            'reportTime'   => self::reportTime((string) $plan['boardingTime']),
            'drop'         => $drop,
            'pointType'    => $points['type'],
            'point'        => $point !== '' ? $point : (string) $plan['boardingName'],
            'coach'        => $coach,
            'type'         => $mode2 ?? 'seater',
            'cabin'        => $req['cabin'],
            'auto'         => $auto,
            'pax'          => $req['pax'],
            'seats'        => $seats,
            'seatLabels'   => array_map(static fn(string $s): string => Seats::displayLabel($s, $coach, $mode2 ?? 'sharing'), $seats),
            'floors'       => array_map(static fn(string $s): string => str_starts_with($s, 'U') ? 'upper' : 'lower', $seats),
            'reasons'      => $reasons,
            'seatsLeft'    => (int) ($plan['seatsLeft'] ?? 0),
            'price'        => $price !== null ? [
                'perSeat'  => (float) $price['perSeat'],
                'base'     => (float) $price['base'],
                'discount' => round((float) $price['groupDiscount'] + (float) $price['couponDiscount'] + (float) $price['tierDiscount'], 2),
                'offer'    => (string) ($price['couponCode'] ?? ''),
                'fee'      => (float) $price['fee'],
                'tax'      => (float) $price['tax'],
                'total'    => (float) $price['total'],
                'label'    => (string) ($price['cabinLabel'] ?? ''),
                'npr'      => $npr,
            ] : null,
            'suggest'      => null,
            'alternatives' => [],
            'ms'           => 0,
        ];

        // Two people of mixed gender in Sharing: SUGGEST a private double, never force it.
        if ($mode2 === 'sharing' && $n === 2 && count($genders) === 2) {
            $pv = Seats::availabilityForSchedule((int) $schedule['id'], 'private');
            $cab = self::pickCabins($pv, 1, $req['berth']);
            if ($cab !== []) {
                $pp = BookingService::priceFor($route, $schedule, $cab, 'private', 'double', (string) $plan['boarding'], $req['phone'], $drop);
                $out['suggest'] = ['type' => 'private', 'cabin' => 'double', 'total' => (float) $pp['total'],
                    'en' => 'Travelling as a couple? A private double cabin is ' . inr((float) $pp['total']) . '.',
                    'hi' => 'दो लोग साथ? प्राइवेट डबल केबिन ' . inr((float) $pp['total']) . '।',
                    'ne' => 'दुई जना सँगै? प्राइभेट डबल क्याबिन ' . inr((float) $pp['total']) . '।'];
            }
        }
        // Nothing fits: the two best alternatives instead of an error (§9).
        if (!$out['ok'] && empty($in['nested'])) {
            $out['alternatives'] = self::alternatives($req, $mode, $schedule, $route, (string) $plan['boarding'], $drop, (string) $plan['date']);
        }
        $out['ms'] = (int) round((microtime(true) - $t0) * 1000);
        return $out;
    }

    /** Pickup minus 60 minutes — the reporting time on the card and the ticket. */
    public static function reportTime(string $hhmm): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m) !== 1) {
            return '';
        }
        $min = ((int) $m[1] * 60 + (int) $m[2] - Settings::getInt('report_before_min', 60) + 1440) % 1440;
        return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
    }

    /**
     * Private cabins: the requested floor first, then the timetable's row
     * order, consecutive cabins of one row preferred.
     *
     * @return list<string>
     */
    private static function pickCabins(array $av, int $count, string $berth): array
    {
        $free = array_values(array_map('strval', $av['available'] ?? []));
        if (count($free) < $count) {
            return [];
        }
        $rear = Settings::getString('seat_ai_row_order', 'rear') !== 'front';
        usort($free, static function (string $a, string $b) use ($berth, $rear): int {
            $pa = AutoBerth::pos($a);
            $pb = AutoBerth::pos($b);
            $da = ($berth === 'upper') === ($pa['deck'] === 'U') ? 0 : 1;
            $db = ($berth === 'upper') === ($pb['deck'] === 'U') ? 0 : 1;
            $na = (int) preg_replace('/\D/', '', $a);
            $nb = (int) preg_replace('/\D/', '', $b);
            return [$da, $rear ? -$na : $na] <=> [$db, $rear ? -$nb : $nb];
        });
        return array_slice($free, 0, $count);
    }

    /** @return list<array<string, mixed>> */
    private static function alternatives(array $req, string $mode, array $schedule, array $route, string $boarding, string $drop, string $date): array
    {
        $alt = [];
        $n   = count($req['pax']);
        if ($req['type'] !== 'private' && Seats::effectiveCoach($schedule, $route) === 'sleeper') {
            $pv  = Seats::availabilityForSchedule((int) $schedule['id'], 'private');
            $cab = self::pickCabins($pv, (int) ceil($n / 2), $req['berth']);
            if ($cab !== []) {
                $pp = BookingService::priceFor($route, $schedule, $cab, 'private', 'double', $boarding, $req['phone'], $drop);
                $alt[] = ['kind' => 'private', 'type' => 'private', 'cabin' => 'double', 'date' => $date, 'total' => (float) $pp['total'],
                          'en' => 'Private cabin on the same bus — ' . inr((float) $pp['total']),
                          'hi' => 'इसी बस में प्राइवेट केबिन — ' . inr((float) $pp['total']),
                          'ne' => 'यही बसमा प्राइभेट क्याबिन — ' . inr((float) $pp['total'])];
            }
        }
        try {
            $next = self::quote(['date' => addDaysISO($date, 1), 'nested' => true] + self::denormalise($req), $mode);
            if ($next['ok']) {
                $alt[] = ['kind' => 'nextDay', 'type' => $req['type'], 'cabin' => $req['cabin'], 'date' => $next['date'], 'total' => (float) ($next['price']['total'] ?? 0),
                          'en' => 'Next day — ' . $next['dateLabel'], 'hi' => 'अगले दिन — ' . $next['dateLabel'], 'ne' => 'भोलिपल्ट — ' . $next['dateLabel']];
            }
        } catch (Throwable $e) {
            // no next-day alternative
        }
        return array_slice($alt, 0, 2);
    }

    /* =================================================================
     *  Book — Cash | Online IS the booking (D4). Idempotent.
     * ================================================================= */

    /**
     * @param array<string, mixed>      $in     quote input + name, phone, country, expect{total,date}
     * @param 'cash'|'online'           $pay
     * @param array<string, mixed>|null $seller BookingService seller context (staff modes)
     * @return array<string, mixed>
     */
    public static function book(array $in, string $pay, string $mode, ?array $seller, string $idemKey): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $idemKey) !== 1) {
            throw new RuntimeException('Please tap again. / फेरि ट्याप गर्नुहोस्।');
        }
        $pay = $pay === 'online' ? 'online' : 'cash';

        // Claim the key. A second tap / retry / duplicate callback gets the first answer back.
        $claimed = Database::insertIgnore('smartbook_requests', [
            'idem_key'   => $idemKey,
            'mode'       => $mode,
            'ip_address' => Security::clientIp(),
        ]);
        if (!$claimed) {
            $row = Database::fetch('SELECT state, response FROM smartbook_requests WHERE idem_key = :k LIMIT 1', ['k' => $idemKey]);
            if ($row !== null && $row['state'] === 'done' && $row['response'] !== null) {
                $prev = json_decode((string) $row['response'], true);
                if (is_array($prev)) {
                    $prev['replayed'] = true;
                    return $prev;
                }
            }
            if ($row !== null && $row['state'] === 'working') {
                throw new RuntimeException('Your booking is being made — please wait a moment. / बुकिङ हुँदैछ — एकछिन पर्खनुहोस्।');
            }
            Database::run("UPDATE smartbook_requests SET state = 'working' WHERE idem_key = :k", ['k' => $idemKey]);
        }

        try {
            $out = self::bookNow($in, $pay, $mode, $seller);
            Database::run(
                "UPDATE smartbook_requests SET state = 'done', booking_id = :b, pnr = :p, response = :r WHERE idem_key = :k",
                ['b' => (int) $out['bookingId'], 'p' => (string) $out['pnr'], 'r' => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'k' => $idemKey]
            );
            return $out;
        } catch (Throwable $e) {
            Database::run("UPDATE smartbook_requests SET state = 'failed' WHERE idem_key = :k", ['k' => $idemKey]);
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private static function bookNow(array $in, string $pay, string $mode, ?array $seller): array
    {
        $t0   = microtime(true);
        $req  = self::normalise($in, $mode);
        $name = Security::clean((string) ($in['name'] ?? ''), 120);
        if (mb_strlen($name) < 2) {
            throw new RuntimeException('Enter the lead passenger\'s name. / मुख्य यात्रुको नाम लेख्नुहोस्।');
        }
        $phone = $req['phone'];
        if ($phone === '' || !Security::isValidPhone($phone, $mode !== 'customer')) {
            throw new RuntimeException('Enter a valid mobile number — the ticket goes to its WhatsApp. / सही मोबाइल नम्बर लेख्नुहोस्।');
        }
        if ($mode === 'customer') {
            foreach ($req['pax'] as $p) {
                if (AutoBerth::gender($p['gender']) === null) {
                    throw new RuntimeException('Choose Female or Male for every passenger. / हरेक यात्रुको महिला वा पुरुष छान्नुहोस्।');
                }
            }
        }
        $country = strtoupper((string) ($in['country'] ?? ''));
        $country = in_array($country, ['NP', '977'], true) ? 'NP' : 'IN';

        $q = self::quote($in, $mode);
        if (!$q['ok']) {
            throw new SmartBookChanged($q['error'] !== '' ? $q['error'] : 'Please check the booking again. / फेरि जाँच गर्नुहोस्।', $q);
        }
        $expect = is_array($in['expect'] ?? null) ? $in['expect'] : [];
        if ((isset($expect['total']) && abs((float) $expect['total'] - (float) $q['price']['total']) > 0.5)
            || (isset($expect['date']) && (string) $expect['date'] !== '' && (string) $expect['date'] !== $q['date'])) {
            throw new SmartBookChanged('The price or date changed since you looked — please check and tap again. / भाडा वा मिति बदलियो — फेरि हेरेर ट्याप गर्नुहोस्।', $q);
        }

        $cod = $pay === 'cash';
        if ($mode === 'customer') {
            if ($cod && !Settings::getBool('allow_cod', true)) {
                throw new RuntimeException('Cash is not available right now — tap Online. / अहिले Cash छैन — Online थिच्नुहोस्।');
            }
            if ($cod) {
                self::assertCodCap($phone, (int) $q['scheduleId'], count($req['pax']));
            }
        }

        /* ---- the request create() takes ------------------------------ */
        $passengers = [];
        $paxFor     = $q['type'] === 'private'
            ? self::cabinLeads($req['pax'], count($q['seats']), $q['cabin'])
            : $req['pax'];
        foreach ($q['seats'] as $i => $seat) {
            $p    = $paxFor[$i] ?? ['gender' => '', 'band' => 'adult', 'name' => ''];
            $nm   = Security::clean((string) ($p['name'] ?? ''), 120);
            $band = AutoBerth::band($p['band'] ?? 'adult');
            $passengers[] = [
                'seat'    => $seat,
                'name'    => $nm !== '' ? $nm : ($i === 0 ? $name : $name . ' (' . ($i + 1) . ')'),
                'age'     => null,
                'gender'  => AutoBerth::gender($p['gender'] ?? null),
                'special' => $band !== 'adult' ? $band : null,
            ];
        }
        $request = [
            'routeId'         => (int) $q['routeId'],
            'travelDate'      => (string) $q['date'],
            'scheduleId'      => (int) $q['slot'] > 1 ? (int) $q['scheduleId'] : 0,
            'seats'           => $q['seats'],
            'passengers'      => $passengers,
            'contact'         => ['phone' => $phone, 'email' => '', 'idType' => $country === 'NP' ? 'Nepali Citizenship' : '', 'idNum' => '', 'country' => $country],
            'bookingMode'     => $q['type'] === 'seater' ? null : $q['type'],
            'cabinType'       => $q['type'] === 'private' ? $q['cabin'] : ($q['type'] === 'sharing' ? 'single' : null),
            'sharingTier'     => null,
            'boarding'        => (string) $q['boarding'],
            'drop'            => (string) $q['drop'],
            'originTown'      => (string) $q['boardingName'],
            'couponCode'      => '',
            'pointsRequested' => 0,
            // D13: never a typed code. An agent's Online sale carries the agent's
            // OWN code from the login (api/smartbook.php); a customer's never does.
            'referralCode'    => $mode === 'agent' && $seller === null ? Security::clean((string) ($in['referralCode'] ?? ''), 20) : '',
            'paymentMethod'   => $cod ? 'cod' : 'upi',
            'isCod'           => $cod && $seller === null,
        ];
        if ($seller !== null) {
            $seller['paymentMethod'] = $cod ? 'cash' : (string) ($seller['paymentMethod'] ?? 'upi');
            $request['paymentMethod'] = $seller['paymentMethod'];
        }

        $booking = BookingService::create($request, $seller);
        if (empty($booking['id'])) {
            throw new RuntimeException('The ticket could not be made — please try again. / टिकट बन्न सकेन, फेरि प्रयास गर्नुहोस्।');
        }
        $pnr = (string) $booking['pnr'];
        if ($mode === 'customer') {
            Auth::rememberOwnPnr($pnr);
        }
        // Online: the berths are held for the QR payment window, then released by cron/expire.php.
        if (($booking['status'] ?? '') === 'pending') {
            $hold = max(5, Settings::getInt('smartbook_online_hold_min', 15));
            Database::run(
                "UPDATE bookings SET expires_at = DATE_ADD(NOW(), INTERVAL {$hold} MINUTE) WHERE id = :id AND status = 'pending'",
                ['id' => (int) $booking['id']]
            );
            $booking['expires_at'] = date('Y-m-d H:i:s', time() + $hold * 60);
        }

        return self::result($booking, $q, $name, $phone, $pay, $t0);
    }

    /** Pay-at-boarding passengers one mobile may hold on one departure (§7, default 6). */
    private static function assertCodCap(string $phone, int $scheduleId, int $adding): void
    {
        $cap = Settings::getInt('cod_max_pax_per_mobile', 6);
        if ($cap <= 0) {
            return;
        }
        $have = (int) Database::scalar(
            "SELECT COUNT(*) FROM bookings b
               JOIN booking_seats bs ON bs.booking_id = b.id AND bs.released_at IS NULL
              WHERE b.contact_phone = :p AND bs.schedule_id = :s AND b.is_cod = 1 AND b.status = 'confirmed'
                AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status = 'verified')",
            ['p' => $phone, 's' => $scheduleId]
        );
        if ($have + $adding > $cap) {
            throw new RuntimeException('One mobile can hold ' . $cap . ' pay-at-boarding seats on a bus — tap Online for more. / एउटा मोबाइलमा एउटा बसका ' . $cap . ' Cash सिट मात्र — थपका लागि Online थिच्नुहोस्।');
        }
    }

    /** One name per private cabin: the first passenger of each cabin. */
    private static function cabinLeads(array $pax, int $cabins, string $cabin): array
    {
        $per = $cabin === 'double' ? 2 : 1;
        $out = [];
        for ($c = 0; $c < $cabins; $c++) {
            $out[] = $pax[$c * $per] ?? ['gender' => '', 'band' => 'adult'];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function result(array $booking, array $q, string $name, string $phone, string $pay, float $t0): array
    {
        $bid       = (int) $booking['id'];
        $pnr       = (string) $booking['pnr'];
        $confirmed = (string) ($booking['status'] ?? '') === 'confirmed';
        $total     = (float) ($booking['total_amount'] ?? 0);
        $detail    = null;
        try {
            $detail = BookingService::detail($pnr);
        } catch (Throwable $e) {
            Logger::error('SmartBook detail failed', ['pnr' => $pnr, 'e' => $e->getMessage()]);
        }
        return [
            'ok'          => true,
            'pnr'         => $pnr,
            'bookingId'   => $bid,
            'status'      => (string) ($booking['status'] ?? ''),
            'isCod'       => !empty($booking['is_cod']),
            'pay'         => $pay,
            'name'        => $name,
            'phone'       => $phone,
            'total'       => $total,
            'totalLabel'  => inr($total),
            'npr'         => round($total * (float) SiteFacts::all()['npr']),
            'seats'       => $q['seats'],
            'seatLabels'  => $q['seatLabels'],
            'date'        => $q['date'],
            'dateLabel'   => $q['dateLabel'],
            'boarding'    => $q['boarding'],
            'boardingTime'=> $q['boardingTime'],
            'reportTime'  => $q['reportTime'],
            'route'       => $q['from'] . ' → ' . $q['to'],
            'ticket'      => $confirmed ? [
                'view'  => Ticket::imageUrl($pnr) . '&view=1',
                'png'   => Ticket::imageUrl($pnr),
                'pdf'   => Ticket::downloadUrl($pnr),
                'print' => Ticket::downloadUrl($pnr) . '&print=1',
                'home'  => '/#/?ticket=' . rawurlencode($pnr),
            ] : null,
            'payPage'     => !$confirmed ? '/smartpay.php?pnr=' . rawurlencode($pnr) : null,
            'expiresAt'   => !$confirmed ? (string) ($booking['expires_at'] ?? '') : null,
            'customer'    => $detail !== null ? shg_customer_payload($detail) : null,
            'elapsedMs'   => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /* =================================================================
     *  Input
     * ================================================================= */

    /** @return array<string, mixed> */
    private static function normalise(array $in, string $mode): array
    {
        $dir = in_array($in['direction'] ?? '', ['toNepal', 'toIndia'], true) ? (string) $in['direction'] : 'toNepal';
        $max = BookingService::maxSeatsFor($mode !== 'customer');
        $pax = [];
        foreach (array_slice((array) ($in['pax'] ?? []), 0, $max) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $pax[] = [
                'gender' => (string) (AutoBerth::gender($p['gender'] ?? '') ?? ''),
                'band'   => AutoBerth::band($p['band'] ?? 'adult'),
                'name'   => Security::clean((string) ($p['name'] ?? ''), 120),
            ];
        }
        if ($pax === []) {
            $pax[] = ['gender' => '', 'band' => 'adult', 'name' => ''];
        }
        $seats = [];
        foreach (array_slice((array) ($in['seats'] ?? []), 0, $max) as $s) {
            $s = strtoupper(Security::clean((string) $s, 6));
            if ($s !== '' && Security::isValidSeat($s)) {
                $seats[] = $s;
            }
        }
        $prefer = [];
        foreach (array_slice((array) ($in['prefer'] ?? []), 0, 6) as $s) {
            $s = strtoupper(Security::clean((string) $s, 6));
            if ($s !== '' && Security::isValidSeat($s)) {
                $prefer[] = $s;
            }
        }
        return [
            'direction' => $dir,
            'date'      => Security::isValidDate((string) ($in['date'] ?? '')) ? (string) $in['date'] : '',
            'point'     => Security::clean((string) ($in['point'] ?? ''), 191),
            'pax'       => $pax,
            'type'      => ($in['type'] ?? '') === 'private' ? 'private' : 'sharing',
            'cabin'     => ($in['cabin'] ?? '') === 'double' ? 'double' : 'single',
            'berth'     => in_array($in['berth'] ?? '', ['lower', 'upper'], true) ? (string) $in['berth'] : 'any',
            'seats'     => array_values(array_unique($seats)),
            'prefer'    => $prefer,
            'phone'     => normalisePhone((string) ($in['phone'] ?? '')),
        ];
    }

    /** normalise() back into quote() input (for the next-day alternative). */
    private static function denormalise(array $req): array
    {
        return [
            'direction' => $req['direction'], 'point' => $req['point'], 'pax' => $req['pax'], 'type' => $req['type'],
            'cabin' => $req['cabin'], 'berth' => $req['berth'], 'phone' => $req['phone'], 'prefer' => $req['prefer'],
        ];
    }

    /** @return array{en: string, hi: string, ne: string} */
    private static function why(string $en, string $hi, string $ne): array
    {
        return ['en' => $en, 'hi' => $hi, 'ne' => $ne];
    }

    private static function short(string $name): string
    {
        return (string) Boarding::stopDisplay($name)['name'];
    }
}
