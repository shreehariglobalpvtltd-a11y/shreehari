<?php
/**
 * =====================================================================
 *  Fare engine
 *
 *  A line-for-line port of the pricing logic from the original
 *  single-file build (calcCabinFare, calcFare, refund slabs, loyalty
 *  tiers, referral commission). Prices computed here are authoritative:
 *  the browser may display a total, but only this file decides what a
 *  passenger is actually charged.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Fare
{
    /**
     * Cabin pricing table, loaded from settings with the original
     * CONFIG.cabinPricing values as the fallback.
     *
     * @return array<string, mixed>
     */
    public static function pricing(): array
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $cached = Settings::getArray('cabin_pricing', [
            'onlineDiscountPct' => 0,   // 5% online discount REMOVED 26 Aug 2026 — one flat fare
            // Sharing per-person base fare (offline) by direction. This is
            // only the FALLBACK: the live `cabin_pricing` settings row wins,
            // and these values mirror it. Owner-confirmed 25 Aug 2026:
            // Gujarat->Rupaidiha (toNepal) 2000, Rupaidiha->Gujarat (toIndia)
            // 1800. Editable from Admin -> Settings (cabin_pricing.sharingByDir),
            // or from the app's Main-point fares box, which writes the
            // 'main_fares' setting read by dirFares() below.
            'sharingByDir' => ['toNepal' => 2000, 'toIndia' => 1800],
            'sharing' => [
                'single_2pax' => ['capacity' => 2, 'offline' => 4400, 'online' => 4180, 'perPerson' => 2090,
                                  'label' => 'Single Sleeper (Sharing)', 'cabinType' => 'single', 'emoji' => '🛏️'],
                'double_3pax' => ['capacity' => 3, 'offline' => 7500, 'online' => 7125, 'perPerson' => 2375,
                                  'label' => 'Double Sleeper (Sharing) · 3P', 'cabinType' => 'double', 'emoji' => '🛏️🛏️'],
                'double_4pax' => ['capacity' => 4, 'offline' => 8800, 'online' => 8360, 'perPerson' => 2090,
                                  'label' => 'Double Sleeper (Sharing) · 4P', 'cabinType' => 'double', 'emoji' => '🛏️🛏️'],
            ],
            'private' => [
                'single_1pax' => ['capacity' => 1, 'offline' => 3800, 'online' => 3800,
                                  'label' => 'Single Sleeper (Private)', 'cabinType' => 'single', 'emoji' => '🔒🛏️'],
                'double_2pax' => ['capacity' => 2, 'offline' => 7600, 'online' => 7600,
                                  'label' => 'Double Sleeper (Private)', 'cabinType' => 'double', 'emoji' => '🔒🛏️🛏️'],
            ],
        ]);

        return $cached;
    }

    /**
     * The towns on each side of the border. The browser keeps the same list
     * in CONFIG.mainPoints; this copy is the one that decides money, because
     * a client can send any city name it likes.
     *
     * @return array{india: string[], nepal: string[]}
     */
    public static function mainPoints(): array
    {
        return Settings::getArray('main_points', [
            'india' => ['Surat', 'Kamrej', 'Ankleshwar', 'Bharuch', 'Vadodara', 'Anand', 'Nadiad', 'Emli Bhupal', 'S Hari Parking, Nana Chiloda'],
            // Rupaidiha is the ONLY Nepal-side point the company may sell today: the service is licensed to the India-side border and no further. Nepalgunj, Kohalpur and Lumbini Pradesh are a FUTURE extension — listing them here put them on the public fare board and in the search boxes as if they were bookable. Add them back the day the permit exists.
            'nepal' => ['Rupaidiha'],
        ]);
    }

    /**
     * True when the destination is on the Nepal side, i.e. this is the
     * outbound leg. Replaces the old `$toCity === 'Ahmedabad'` test, which
     * quietly mispriced every Gujarat town except Ahmedabad the moment more
     * than one became bookable.
     */
    public static function isNepalPoint(?string $city): bool
    {
        $nepal = self::mainPoints()['nepal'] ?? [];
        return in_array((string) $city, $nepal, true);
    }

    /**
     * Live directional fares. The operator's 'main_fares' setting (written
     * by Admin -> Settings -> Main-point fares) wins over the packaged
     * cabin_pricing defaults, so a price change needs no deploy.
     *
     * @return array{toNepal: float, toIndia: float}
     */
    public static function dirFares(): array
    {
        // Owner-confirmed launch rates (25 Aug 2026): Gujarat->Rupaidiha
        // (toNepal, "jane") 2000, Rupaidiha->Gujarat (toIndia, "aune") 1800.
        // 3 Sep 2026: the owner now edits these from Admin -> Settings ->
        // "Fares & booking rules" (settings rows fare_to_nepal / fare_to_india,
        // seeded by database/upgrade-2026-09-fares.php). The code figures stay
        // as the fallback when a row is missing or zero. cabin_pricing.sharingByDir
        // is still deliberately ignored (the live DB held the reversed pair).
        // The legacy "Main-point fares" override (main_fares) still wins below;
        // settings.php keeps it in step whenever the fares panel is saved.
        $ftn  = Settings::getFloat('fare_to_nepal', 0.0);
        $fti  = Settings::getFloat('fare_to_india', 0.0);
        $base = ['toNepal' => $ftn > 0 ? $ftn : 2000.0, 'toIndia' => $fti > 0 ? $fti : 1800.0];
        $over = Settings::getArray('main_fares', []);

        $pick = static function ($o, $b): float {
            $v = is_numeric($o) ? (float) $o : (float) $b;
            return $v > 0 ? round($v) : (float) $b;
        };

        return [
            'toNepal' => $pick($over['toNepal'] ?? null, $base['toNepal'] ?? 2000),
            'toIndia' => $pick($over['toIndia'] ?? null, $base['toIndia'] ?? 1800),
        ];
    }

    /* =================================================================
     *  POINT-TO-POINT FARE RULES  (26 Sep 2026)
     *
     *  Until today the sharing fare had exactly two values — one per
     *  DIRECTION (toNepal 2000 / toIndia 1800). The owner's real board is
     *  finer than that: a pickup below Ahmedabad pays more than Ahmedabad
     *  itself, and the return leg is not symmetrical either. Encoding that
     *  as more `fare_to_*` rows would have meant one settings key per town
     *  per direction, and a second place for the app, the counter, the
     *  chatbot and WhatsApp each to get wrong.
     *
     *  So the board itself is the setting: an ORDERED list of rules, first
     *  match wins, each `{from, to, amount}`. `from`/`to` accept a town
     *  name, the zone tokens `@india` / `@nepal`, or `*` for anything. An
     *  unmatched pair still falls through to dirFares(), so a site with no
     *  rules row prices exactly as it did before this change.
     *
     *  Owner-confirmed 26 Sep 2026:
     *      Ahmedabad (S Hari Parking, Nana Chiloda) -> Rupaidiha  2000
     *      any other Gujarat point       -> Rupaidiha             2200
     *      Rupaidiha -> Ahmedabad                                 1800
     *      Rupaidiha -> any other Gujarat point                   2200
     * ================================================================= */

    /** The packaged board — also the fallback when the settings row is absent. */
    public const FARE_RULES_DEFAULT = [
        ['from' => 'S Hari Parking, Nana Chiloda', 'to' => 'Rupaidiha', 'amount' => 2000, 'note' => 'Ahmedabad to the border'],
        ['from' => '@india', 'to' => 'Rupaidiha', 'amount' => 2200, 'note' => 'any Gujarat pickup before Ahmedabad'],
        ['from' => 'Rupaidiha', 'to' => 'S Hari Parking, Nana Chiloda', 'amount' => 1800, 'note' => 'border to Ahmedabad'],
        ['from' => 'Rupaidiha', 'to' => '@india', 'amount' => 2200, 'note' => 'border to any Gujarat drop below Ahmedabad'],
    ];

    /**
     * Spellings the office (and the WhatsApp assistant) may type for a point
     * whose official name is longer. "Ahmedabad" is the one that matters:
     * the bookable stop is called "S Hari Parking, Nana Chiloda", and both
     * the owner and every passenger call it Ahmedabad.
     *
     * @return array<string, string> lower-case alias => official point name
     */
    public static function pointAliases(): array
    {
        $packaged = [
            'ahmedabad'      => 'S Hari Parking, Nana Chiloda',
            'amdavad'        => 'S Hari Parking, Nana Chiloda',
            'nana chiloda'   => 'S Hari Parking, Nana Chiloda',
            'chiloda'        => 'S Hari Parking, Nana Chiloda',
            's hari parking' => 'S Hari Parking, Nana Chiloda',
            'rupaidia'       => 'Rupaidiha',
            'border'         => 'Rupaidiha',
        ];
        $extra = Settings::getArray('fare_point_aliases', []);
        $out   = $packaged;
        foreach ($extra as $k => $v) {
            $k = strtolower(trim((string) $k));
            if ($k !== '' && trim((string) $v) !== '') {
                $out[$k] = trim((string) $v);
            }
        }
        return $out;
    }

    /**
     * The comparison key for a place. A stop reaches us in several shapes —
     * the settings list says "S Hari Parking, Nana Chiloda", the browser
     * submits "S Hari Parking, Nana Chiloda @ 21:00 [23.171,72.623]", and
     * Boarding::townKey() has already reduced that to "shariparking". All
     * three must price the same journey, so the pickup time, the coordinates
     * and every separator are stripped before anything is compared. Same
     * reasoning as Boarding::townKey(); kept here so Fare has no dependency
     * on the boarding module.
     */
    public static function pkey(?string $s): string
    {
        $t = (string) preg_replace('/\[[^\]]*\]/u', ' ', (string) $s);   // drop [lat,lng]
        $t = (string) preg_replace('/@.*$/u', ' ', $t);                    // drop "@ 21:00"
        $t = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $t);
        return mb_strtolower(trim($t));
    }

    /**
     * The OFFICIAL point name for whatever a human (or the checkout) typed.
     * Falls back to the trimmed input, so an unknown town is compared as
     * itself rather than silently becoming a different town.
     */
    public static function canonicalPoint(?string $name): string
    {
        $raw = trim((string) $name);
        if ($raw === '') {
            return '';
        }
        $key = self::pkey($raw);
        if ($key === '') {
            return $raw;
        }

        foreach (self::pointAliases() as $alias => $official) {
            if (self::pkey((string) $alias) === $key) {
                return (string) $official;
            }
        }

        $points = self::mainPoints();
        $all    = array_merge((array) ($points['india'] ?? []), (array) ($points['nepal'] ?? []));

        foreach ($all as $p) {                       // the whole name
            if (self::pkey((string) $p) === $key) {
                return (string) $p;
            }
        }
        /* A submitted label carries only the LEADING segment of the official
           name ("shariparking" for "shariparkingnanachiloda"), so a prefix
           either way is the same place. Checked after every exact match so a
           short name can never swallow a longer one. */
        foreach ($all as $p) {
            $pk = self::pkey((string) $p);
            if ($pk !== '' && (str_starts_with($pk, $key) || str_starts_with($key, $pk)
                || str_contains($pk, $key) || str_contains($key, $pk))) {
                return (string) $p;
            }
        }
        return $raw;
    }

    /**
     * True when the point is on the INDIA SIDE of the border.
     *
     * Deliberately "not Nepal" rather than "on the india list". The service is
     * licensed Gujarat to the Rupaidiha border and no further, so every place
     * that is not the Nepal side is the India side — including a pickup the
     * office has added to route_stops but not yet to main_points.
     *
     * The strict reading cost money: an unlisted Gujarat stop matched no rule
     * at all, fell through to the directional fallback, and was charged
     * ₹2,000 — two hundred rupees LESS than the Surat pickup beside it,
     * because a rule that names @india had quietly stopped applying to it.
     * A new stop should inherit the board, not slip out from under it.
     */
    public static function isIndiaPoint(?string $city): bool
    {
        $canon = self::canonicalPoint($city);
        if (self::pkey($canon) === '') {
            return false;
        }
        return !self::isNepalPoint($canon);
    }

    /**
     * The two points that decide the fare for ONE booking.
     *
     * The route says Surat -> Rupaidiha, but the passenger may be getting on
     * at Nana Chiloda, and on the return leg it is the DROP that is the
     * Gujarat town. Pricing off routes.from_city alone charged every Gujarat
     * pickup the same, which is exactly the board the owner replaced on
     * 26 Sep 2026. The browser has reasoned this way since 19 Sep
     * (07-checkout.js `ownTown`); this is the server's copy, and the server
     * is what charges.
     *
     * @param  array<string,mixed>|null $route
     * @return array{from: string, to: string}
     */
    public static function journeyPoints(?array $route, string $boardingLabel = '', string $dropLabel = ''): array
    {
        $rFrom = trim((string) ($route['from_city'] ?? ''));
        $rTo   = trim((string) ($route['to_city'] ?? ''));

        if (self::isNepalPoint(self::canonicalPoint($rTo))) {
            // Outbound: the passenger's own boarding stop is the Gujarat end.
            return [
                'from' => self::canonicalPoint($boardingLabel !== '' ? $boardingLabel : $rFrom),
                'to'   => self::canonicalPoint($rTo),
            ];
        }

        // Inbound from the border: the DROP is the Gujarat end.
        return [
            'from' => self::canonicalPoint($rFrom),
            'to'   => self::canonicalPoint($dropLabel !== '' ? $dropLabel : $rTo),
        ];
    }

    /**
     * Does one side of a rule match a city?
     *   '*'       anything (including an unknown town)
     *   '@nepal'  a Nepal-side point
     *   '@india'  an India-side point
     *   a name    that point, by any of its spellings
     */
    public static function pointMatches(string $pattern, ?string $city): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '' || $pattern === '*') {
            return true;
        }
        $low = strtolower($pattern);
        if ($low === '@nepal') {
            return self::isNepalPoint(self::canonicalPoint($city));
        }
        if ($low === '@india') {
            return self::isIndiaPoint($city);
        }
        $want = self::canonicalPoint($pattern);
        $have = self::canonicalPoint($city);
        return $have !== '' && $want === $have;
    }

    /**
     * The live board, normalised and ordered. A malformed row is dropped
     * rather than thrown: a typo in one line must not stop the bus selling
     * tickets on every other line.
     *
     * @return array<int, array{from: string, to: string, amount: float, note: string}>
     */
    public static function fareRules(): array
    {
        $raw = Settings::getArray('fare_rules', []);
        if ($raw === []) {
            $raw = self::FARE_RULES_DEFAULT;
        }
        return self::normaliseRules($raw);
    }

    /**
     * @param  array<mixed> $raw
     * @return array<int, array{from: string, to: string, amount: float, note: string}>
     */
    public static function normaliseRules(array $raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $from = trim((string) ($row['from'] ?? ''));
            $to   = trim((string) ($row['to'] ?? ''));
            $amt  = (float) ($row['amount'] ?? 0);
            if ($from === '' || $to === '' || $amt <= 0 || $amt > 1000000) {
                continue;
            }
            $out[] = [
                'from'   => $from,
                'to'     => $to,
                'amount' => round($amt, 2),
                'note'   => Security::clean((string) ($row['note'] ?? ''), 120),
            ];
        }
        return $out;
    }

    /**
     * The first rule that covers this journey, or null when none does.
     *
     * @return array{amount: float, from: string, to: string, note: string, index: int}|null
     */
    public static function ruleFare(?string $fromCity, ?string $toCity): ?array
    {
        if (trim((string) $toCity) === '') {
            return null;
        }
        foreach (self::fareRules() as $i => $rule) {
            if (self::pointMatches($rule['from'], $fromCity) && self::pointMatches($rule['to'], $toCity)) {
                return $rule + ['index' => $i];
            }
        }
        return null;
    }

    /**
     * The per-person sharing fare for one journey — ONE definition, used by
     * the website, the counter, the agent panel, the chatbot and WhatsApp.
     * Rules first, the two directional rows second, the packaged pair last.
     */
    public static function pointFare(?string $fromCity, ?string $toCity): float
    {
        $rule = self::ruleFare($fromCity, $toCity);
        if ($rule !== null) {
            return (float) $rule['amount'];
        }
        $dir = self::dirFares();
        return self::isNepalPoint(self::canonicalPoint($toCity)) ? $dir['toNepal'] : $dir['toIndia'];
    }

    /**
     * The whole published board, for the fare table on the home page, the
     * admin pricing screen and the assistant's "kati paisa" answer.
     *
     * @return array<int, array{from: string, to: string, amount: float, source: string}>
     */
    public static function fareBoard(): array
    {
        $points = self::mainPoints();
        $india  = (array) ($points['india'] ?? []);
        $nepal  = (array) ($points['nepal'] ?? []);
        $out    = [];

        foreach ($nepal as $np) {
            foreach ($india as $ip) {
                $r     = self::ruleFare((string) $ip, (string) $np);
                $out[] = ['from' => (string) $ip, 'to' => (string) $np,
                          'amount' => self::pointFare((string) $ip, (string) $np),
                          'source' => $r !== null ? 'rule' : 'direction'];
            }
            foreach ($india as $ip) {
                $r     = self::ruleFare((string) $np, (string) $ip);
                $out[] = ['from' => (string) $np, 'to' => (string) $ip,
                          'amount' => self::pointFare((string) $np, (string) $ip),
                          'source' => $r !== null ? 'rule' : 'direction'];
            }
        }
        return $out;
    }

    /**
     * The board as a FLAT LOOKUP the browser can use without carrying a copy
     * of the rule engine: "<fromKey>|<toKey>" => amount, for every bookable
     * pair, plus a "*|<toKey>" line per destination for a pickup the page
     * does not recognise.
     *
     * The page has always kept its own fare table (CONFIG.cabinPricing) so it
     * can quote instantly and offline. Now that the fare depends on WHICH
     * pickup, that copy would have gone wrong for eight of the nine Gujarat
     * towns — so the server hands over the answers rather than the rules.
     * There is still exactly one place that decides a price; this is a
     * read-only projection of it.
     *
     * @return array<string, float>
     */
    public static function fareBoardMap(): array
    {
        $out = [];
        foreach (self::fareBoard() as $row) {
            $out[self::pkey($row['from']) . '|' . self::pkey($row['to'])] = (float) $row['amount'];
        }
        /* The fallback line for each destination: what somebody boarding at a
           point the page cannot name would pay. */
        $points = self::mainPoints();
        foreach (array_merge((array) ($points['india'] ?? []), (array) ($points['nepal'] ?? [])) as $to) {
            $out['*|' . self::pkey((string) $to)] = self::pointFare(null, (string) $to);
        }
        return $out;
    }

    /* =================================================================
     *  ADVANCE-BOOKING OFFER  (Dashain / Tihar, 26 Sep 2026)
     *
     *  "Book at least N hours before departure and get P% off." Every part
     *  of it is a settings row so the office can move the hours, the
     *  percentage, the window and the wording without a deploy, and can
     *  switch it off outright. It is applied inside quote(), which means
     *  the website, the counter, the agent panel, Quick Ticket, the chatbot
     *  and WhatsApp all grant exactly the same discount — including on a
     *  VIP private cabin, which is a mode of the same quote.
     * ================================================================= */

    /**
     * The offer as configured. `live` folds in the ON switch, a positive
     * percentage and today's date against the window.
     *
     * @return array{on: bool, live: bool, percent: float, hours: int,
     *               from: string, to: string, max: float, modes: string,
     *               text: string, title: string}
     */
    public static function advanceOffer(): array
    {
        $on    = Settings::getBool('advance_offer_on', false);
        $pct   = round(Settings::getFloat('advance_offer_percent', 10.0), 2);
        $hrs   = Settings::getInt('advance_offer_hours', 24);
        $from  = trim(Settings::getString('advance_offer_from', ''));
        $to    = trim(Settings::getString('advance_offer_to', ''));
        $max   = max(0.0, Settings::getFloat('advance_offer_max_inr', 0.0));
        $modes = strtolower(trim(Settings::getString('advance_offer_modes', 'all')));
        if (!in_array($modes, ['all', 'sharing', 'private'], true)) {
            $modes = 'all';
        }
        $title = trim(Settings::getString('advance_offer_title', ''));
        if ($title === '') {
            $title = 'Advance booking offer';
        }
        $text = trim(Settings::getString('advance_offer_text', ''));
        if ($text === '') {
            $text = sprintf(
                'Book %d hours before departure and save %s%%.',
                max(0, $hrs),
                rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.')
            );
        }

        $today = todayISO();
        $live  = $on && $pct > 0 && $hrs >= 0
            && ($from === '' || $from <= $today)
            && ($to === ''   || $to   >= $today);

        return [
            'on' => $on, 'live' => $live, 'percent' => $pct, 'hours' => max(0, $hrs),
            'from' => $from, 'to' => $to, 'max' => $max, 'modes' => $modes,
            'text' => $text, 'title' => $title,
        ];
    }

    /**
     * The advance discount due on one subtotal.
     *
     * `$departureTime` matters: without it the boundary is measured to
     * MIDNIGHT at the start of the travel date, which would refuse the
     * discount to somebody booking 33 hours before an evening bus. Every
     * caller that knows the departure passes it.
     *
     * @return array{ok: bool, amount: float, percent: float, hoursLeft: float,
     *               hoursNeeded: int, title: string, text: string, why: string}
     */
    public static function advanceDiscount(
        float $subtotal,
        string $travelDate,
        string $departureTime = '00:00:00',
        ?string $bookingMode = null
    ): array {
        $offer = self::advanceOffer();
        $no    = static function (string $why) use ($offer): array {
            return [
                'ok' => false, 'amount' => 0.0, 'percent' => (float) $offer['percent'],
                'hoursLeft' => 0.0, 'hoursNeeded' => (int) $offer['hours'],
                'title' => (string) $offer['title'], 'text' => (string) $offer['text'], 'why' => $why,
            ];
        };

        if (!$offer['live']) {
            return $no($offer['on'] ? 'The offer is not running today.' : 'No advance offer is running.');
        }
        if ($offer['modes'] !== 'all' && $bookingMode !== null && $bookingMode !== $offer['modes']) {
            return $no('This offer applies to ' . $offer['modes'] . ' bookings only.');
        }
        if ($travelDate === '' || !Security::isValidDate($travelDate)) {
            return $no('No travel date to measure against.');
        }
        if ($subtotal <= 0) {
            return $no('Nothing to discount.');
        }

        $time      = trim($departureTime) !== '' ? trim($departureTime) : '00:00:00';
        $hoursLeft = hoursUntil($travelDate, $time);

        if ($hoursLeft < (float) $offer['hours']) {
            return $no(sprintf(
                'Booked %s hours before departure — the offer needs %d.',
                number_format(max(0, $hoursLeft), 0),
                (int) $offer['hours']
            ));
        }

        $amount = round($subtotal * (float) $offer['percent'] / 100);
        if ($offer['max'] > 0) {
            $amount = min($amount, (float) $offer['max']);
        }
        $amount = min($amount, $subtotal);

        return [
            'ok' => $amount > 0, 'amount' => $amount, 'percent' => (float) $offer['percent'],
            'hoursLeft' => round($hoursLeft, 1), 'hoursNeeded' => (int) $offer['hours'],
            'title' => (string) $offer['title'], 'text' => (string) $offer['text'],
            'why' => $amount > 0 ? 'Booked in advance.' : 'The discount worked out to nothing.',
        ];
    }

    /**
     * The departure clock for a schedule row, as the rest of the app reads
     * it: the per-date override, else the route's timetable, else midnight.
     *
     * @param array<string,mixed>|null $schedule
     * @param array<string,mixed>|null $route
     */
    public static function departureTime(?array $schedule, ?array $route = null): string
    {
        $t = trim((string) ($schedule['dep_time_override'] ?? ''));
        if ($t === '') {
            $t = trim((string) ($route['dep_time'] ?? ''));
        }
        return $t !== '' ? $t : '00:00:00';
    }
    /**
     * Cabin fare — the direct port of calcCabinFare().
     *
     * @param string $cabinType   'single' | 'double'
     * @param string $bookingType 'sharing' | 'private'
     * @param int    $passengers  head count
     * @param bool   $isOnline    true for prepaid web bookings (discounted)
     *
     * @return array{base: float, total: float, saved: float, perPerson: float,
     *               label: string, emoji: string, cabins: int}
     */
    /**
     * $stopCount: number of Gujarat-side stops actually touched on this
     * booking (boarding + drop combined, from 1 to 4). Only relevant for
     * sharing service — a passenger who only touches 1–2 of the 4 stops
     * gets a "short run" discount off the base fare (business rule set
     * in Admin → Settings → sharing_short_run_*).
     */
    public static function cabinFare(string $cabinType, string $bookingType, int $passengers, bool $isOnline = true, ?string $toCity = null, int $stopCount = 4, ?string $fromCity = null): array
    {
        $passengers = max(1, $passengers);
        $pricing    = self::pricing();

        $empty = [
            'base' => 0.0, 'total' => 0.0, 'saved' => 0.0, 'perPerson' => 0.0,
            'label' => '', 'emoji' => '', 'cabins' => 0,
        ];

        if ($bookingType === 'private') {
            $key = $cabinType === 'single' ? 'single_1pax' : 'double_2pax';
            $p   = $pricing['private'][$key] ?? null;

            if ($p === null) {
                return $empty;
            }

            $capacity = max(1, (int) $p['capacity']);
            $cabins   = (int) ceil($passengers / $capacity);

            // Flat pricing (26 Aug 2026): the 5% online discount is removed —
            // a private cabin is charged the single published rate online and
            // offline alike, so no "you saved" line ever appears.
            $total       = $cabins * (float) $p['offline'];
            $offlineCost = $cabins * (float) $p['offline'];

            return [
                'base'      => $offlineCost,
                'total'     => $total,
                'saved'     => $offlineCost - $total,
                'perPerson' => round($total / $passengers),
                'label'     => (string) $p['label'] . ($cabins > 1 ? ' x' . $cabins : ''),
                'emoji'     => (string) ($p['emoji'] ?? ''),
                'cabins'    => $cabins,
            ];
        }

        if ($bookingType === 'sharing') {
            if ($cabinType === 'single') {
                $key = 'single_2pax';
            } elseif ($passengers === 3) {
                $key = 'double_3pax';
            } else {
                $key = 'double_4pax';
            }

            $p = $pricing['sharing'][$key] ?? null;

            if ($p === null) {
                return $empty;
            }

            // Per-person is a flat, direction-based fare — 2000 towards
            // Nepal (Gujarat->Rupaidiha) / 1800 towards India (return) — the
            // same across every sharing tier. Owner decision 26 Aug 2026: the
            // 5% online discount is REMOVED, so the fare is identical online and
            // offline. A "short run" that only uses 1-2 of the 4 Gujarat stops
            // (e.g. Surat -> border only) still drops 200 off the base -- set
            // in Admin -> Settings.
            // 26 Sep 2026: the per-person fare now comes from the point-to-point
            // board (pointFare), which still falls back to the two directional
            // rows when no rule covers the pair — so a site with no fare_rules
            // row prices exactly as it did before.
            $base     = self::pointFare($fromCity, $toCity);

            $minFullStops = Settings::getInt('sharing_short_run_min_stops', 3);
            $shortCut     = Settings::getFloat('sharing_short_run_discount_inr', 200.0);
            if ($stopCount > 0 && $stopCount < $minFullStops && $shortCut > 0) {
                $base = max(0.0, $base - $shortCut);
            }

            // Flat fare: no online discount. Hardcoded to 0 (not read from the
            // cabin_pricing setting) for the same reason dirFares() hardcodes
            // the rate — the live production row may still carry the old 5, and
            // the flat price must ship in code. $isOnline no longer changes the
            // per-person charge.
            $perPerson   = $base;
            $total       = $perPerson * $passengers;
            $offlineCost = $base * $passengers;

            return [
                'base'      => $offlineCost,
                'total'     => $total,
                'saved'     => $offlineCost - $total,
                'perPerson' => $perPerson,
                'label'     => (string) $p['label'],
                'emoji'     => (string) ($p['emoji'] ?? ''),
                'cabins'    => 1,
            ];
        }

        return $empty;
    }

    /**
     * Seater / simple per-seat pricing, plus the group discount.
     *
     * @return array{base: float, groupDiscount: float, fee: float, total: float, perSeat: float}
     */
    public static function seatFare(float $farePerSeat, int $seatCount): array
    {
        $seatCount = max(1, $seatCount);
        $base      = $farePerSeat * $seatCount;

        $minSeats  = Settings::getInt('group_discount_min_seats', 5);
        $percent   = Settings::getFloat('group_discount_percent', 5.0);
        $groupCut  = ($minSeats > 0 && $seatCount >= $minSeats)
            ? round($base * $percent / 100)
            : 0.0;

        $fee = Settings::getFloat('booking_fee', 0.0);

        return [
            'base'          => $base,
            'groupDiscount' => $groupCut,
            'fee'           => $fee,
            'total'         => max(0.0, $base - $groupCut + $fee),
            'perSeat'       => $farePerSeat,
        ];
    }

    /**
     * Loyalty tier for a lifetime points balance.
     *
     * @return array{name: string, min: int, discountPct: float, icon: string}
     */
    public static function tierForPoints(int $lifetimePoints): array
    {
        $tiers = Settings::getArray('loyalty_tiers', [
            ['name' => 'Silver',   'min' => 0,    'discountPct' => 0, 'icon' => '🥈'],
            ['name' => 'Gold',     'min' => 300,  'discountPct' => 2, 'icon' => '🥇'],
            ['name' => 'Platinum', 'min' => 900,  'discountPct' => 3, 'icon' => '⭐'],
            ['name' => 'Diamond',  'min' => 2000, 'discountPct' => 5, 'icon' => '💎'],
        ]);

        $current = ['name' => 'Silver', 'min' => 0, 'discountPct' => 0.0, 'icon' => '🥈'];

        foreach ($tiers as $tier) {
            if ($lifetimePoints >= (int) ($tier['min'] ?? 0)) {
                $current = [
                    'name'        => (string) ($tier['name'] ?? 'Silver'),
                    'min'         => (int) ($tier['min'] ?? 0),
                    'discountPct' => (float) ($tier['discountPct'] ?? 0),
                    'icon'        => (string) ($tier['icon'] ?? ''),
                ];
            }
        }

        return $current;
    }

    /**
     * Tier discount in rupees for a given subtotal.
     *
     * @return array{amount: float, tierName: string, pct: float}
     */
    public static function tierDiscount(float $subtotal, int $lifetimePoints): array
    {
        $tier = self::tierForPoints($lifetimePoints);
        $pct  = $tier['discountPct'];

        return [
            'amount'   => $pct > 0 ? round($subtotal * $pct / 100) : 0.0,
            'tierName' => $tier['name'],
            'pct'      => $pct,
        ];
    }

    /**
     * Convert loyalty points into a rupee discount.
     * Points are only redeemable in whole blocks (default 100 pts = ₹10).
     *
     * @return array{points: int, value: float}
     */
    public static function pointsRedemption(int $pointsRequested, int $pointsAvailable, float $capAmount): array
    {
        $blockPoints = max(1, Settings::getInt('loyalty_redeem_points', 100));
        $blockValue  = Settings::getFloat('loyalty_redeem_rupees', 10.0);

        $usable = min(max(0, $pointsRequested), max(0, $pointsAvailable));
        $blocks = intdiv($usable, $blockPoints);

        if ($blocks < 1) {
            return ['points' => 0, 'value' => 0.0];
        }

        $value = $blocks * $blockValue;

        // Never let points push the payable total below zero.
        if ($value > $capAmount) {
            $blocks = (int) floor($capAmount / $blockValue);
            $value  = $blocks * $blockValue;
        }

        return [
            'points' => $blocks * $blockPoints,
            'value'  => max(0.0, $value),
        ];
    }

    /**
     * Points earned by a confirmed booking.
     */
    public static function pointsEarned(float $confirmedAmount): int
    {
        $per100 = Settings::getInt('loyalty_points_per_100', 1);
        return (int) floor($confirmedAmount / 100) * $per100;
    }

    /**
     * Validate a coupon and work out its discount.
     *
     * @return array{ok: bool, error?: string, amount?: float, coupon?: array<string, mixed>}
     */
    /**
     * The running offer, applied without anyone typing a code (20 Sep 2026).
     *
     * A Dashain/Tihar offer has to reach every passenger, not only the ones
     * who know a code. Rows flagged auto_apply are considered here and put
     * through the SAME couponDiscount() validation as a typed code, so
     * dates, per-route limits, minimum amount, usage caps and the max
     * discount ceiling all behave identically. When several qualify the
     * passenger gets the biggest one.
     *
     * @return array{ok: bool, amount?: float, code?: string, title?: string}
     */
    public static function autoOffer(float $subtotal, string $phone, ?int $routeId = null): array
    {
        $today = todayISO();
        $rows  = Database::fetchAll(
            "SELECT code, title FROM coupons
              WHERE is_active = 1 AND auto_apply = 1
                AND (valid_from  IS NULL OR valid_from  <= :d1)
                AND (valid_until IS NULL OR valid_until >= :d2)
                AND (route_id IS NULL OR route_id = :r)
              ORDER BY id DESC
              LIMIT 20",
            ['d1' => $today, 'd2' => $today, 'r' => $routeId]
        );

        $best = ['ok' => false];
        foreach ($rows as $row) {
            $try = self::couponDiscount((string) $row['code'], $subtotal, $phone, $routeId);
            if (!empty($try['ok']) && (float) $try['amount'] > (float) ($best['amount'] ?? 0)) {
                $best = [
                    'ok'     => true,
                    'amount' => (float) $try['amount'],
                    'code'   => (string) $row['code'],
                    'title'  => trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : (string) $row['code'],
                ];
            }
        }
        return $best;
    }

    /**
     * The offers running TODAY that every passenger gets automatically
     * (auto_apply = 1) — what the WhatsApp assistant may tell people about
     * (23 Sep 2026). Typed-code coupons are deliberately left out: a code the
     * office hands to one group must not be broadcast by a bot.
     *
     * Read-only. The discount itself is still decided by autoOffer() /
     * couponDiscount() at quote and sale time, never by this list.
     *
     * @return list<array{code:string, title:string, type:string, value:float, max:float, min:float, routeId:?int, until:string, left:?int}>
     */
    public static function runningOffers(?int $routeId = null): array
    {
        try {
            $today = todayISO();
            $rows  = Database::fetchAll(
                "SELECT code, title, discount_type, discount_value, max_discount, min_amount, route_id, valid_until,
                        usage_limit, used_count
                   FROM coupons
                  WHERE is_active = 1 AND auto_apply = 1
                    AND (valid_from  IS NULL OR valid_from  <= :d1)
                    AND (valid_until IS NULL OR valid_until >= :d2)
                    AND (usage_limit IS NULL OR used_count < usage_limit)
                  ORDER BY id DESC LIMIT 10",
                ['d1' => $today, 'd2' => $today]
            );
        } catch (Throwable $e) {
            return [];      // no coupons table / column yet: simply no offer
        }
        $out = [];
        foreach ($rows as $r) {
            if ($routeId !== null && $r['route_id'] !== null && (int) $r['route_id'] !== $routeId) {
                continue;
            }
            $out[] = [
                'code'    => (string) $r['code'],
                'title'   => trim((string) ($r['title'] ?? '')) !== '' ? (string) $r['title'] : (string) $r['code'],
                'type'    => (string) $r['discount_type'] === 'percent' ? 'percent' : 'flat',
                'value'   => (float) $r['discount_value'],
                'max'     => (float) ($r['max_discount'] ?? 0),
                'min'     => (float) ($r['min_amount'] ?? 0),
                'routeId' => $r['route_id'] !== null ? (int) $r['route_id'] : null,
                'until'   => (string) ($r['valid_until'] ?? ''),
                'left'    => $r['usage_limit'] !== null ? max(0, (int) $r['usage_limit'] - (int) $r['used_count']) : null,
            ];
        }
        return $out;
    }

    /** One plain line for an offer: "Dashain offer — ₹200 off (bookings of ₹1,500+), until 30 Oct". */
    public static function offerLine(array $o): string
    {
        $amt = $o['type'] === 'percent'
            ? rtrim(rtrim(number_format($o['value'], 2), '0'), '.') . '% off' . ($o['max'] > 0 ? ' (up to ' . inr($o['max']) . ')' : '')
            : inr($o['value']) . ' off';
        return $o['title'] . ' — ' . $amt
            . ($o['min'] > 0 ? ', on bookings of ' . inr($o['min']) . '+' : '')
            . ($o['until'] !== '' ? ', until ' . date('j M', (int) strtotime($o['until'])) : '');
    }

    public static function couponDiscount(string $code, float $subtotal, string $phone, ?int $routeId = null): array
    {
        $code = strtoupper(Security::clean($code, 40));

        if ($code === '') {
            return ['ok' => false, 'error' => 'Enter a coupon code.'];
        }

        $coupon = Database::fetch(
            'SELECT * FROM coupons WHERE code = :c AND is_active = 1 LIMIT 1',
            ['c' => $code]
        );

        if ($coupon === null) {
            return ['ok' => false, 'error' => 'That coupon code is not valid.'];
        }

        $today = todayISO();

        if (!empty($coupon['valid_from']) && $coupon['valid_from'] > $today) {
            return ['ok' => false, 'error' => 'This coupon is not active yet.'];
        }
        if (!empty($coupon['valid_until']) && $coupon['valid_until'] < $today) {
            return ['ok' => false, 'error' => 'This coupon has expired.'];
        }
        if ($coupon['route_id'] !== null && $routeId !== null && (int) $coupon['route_id'] !== $routeId) {
            return ['ok' => false, 'error' => 'This coupon does not apply to the selected route.'];
        }
        if ($subtotal < (float) $coupon['min_amount']) {
            return ['ok' => false, 'error' => 'This coupon needs a minimum booking of ' . inr((float) $coupon['min_amount']) . '.'];
        }
        if ($coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            return ['ok' => false, 'error' => 'This coupon has been fully redeemed.'];
        }

        $perUser = (int) $coupon['per_user_limit'];
        if ($perUser > 0 && $phone !== '') {
            $used = (int) Database::scalar(
                'SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = :cid AND user_phone = :p',
                ['cid' => $coupon['id'], 'p' => $phone],
                0
            );
            if ($used >= $perUser) {
                return ['ok' => false, 'error' => 'You have already used this coupon.'];
            }
        }

        $amount = $coupon['discount_type'] === 'percent'
            ? round($subtotal * (float) $coupon['discount_value'] / 100)
            : (float) $coupon['discount_value'];

        if ($coupon['max_discount'] !== null) {
            $amount = min($amount, (float) $coupon['max_discount']);
        }

        $amount = min($amount, $subtotal);

        return ['ok' => true, 'amount' => $amount, 'coupon' => $coupon];
    }

    /**
     * Refund due on cancellation, using the published slabs.
     *
     * @return array{amount: float, percent: float, hoursLeft: float, reason: string}
     */
    public static function refundFor(float $amountPaid, string $travelDate, string $departureTime = '00:00:00'): array
    {
        $hoursLeft = hoursUntil($travelDate, $departureTime);

        // 5 Sep 2026: matched to the published Terms (section C) — the T&C
        // page has promised five tiers since launch, but the enforced slabs
        // still had the older three. Terms are the contract; code follows.
        $slabs = Settings::getArray('refund_slabs', [
            ['minHrs' => 96, 'pct' => 90],
            ['minHrs' => 48, 'pct' => 75],
            ['minHrs' => 24, 'pct' => 50],
            ['minHrs' => 6,  'pct' => 25],
            ['minHrs' => 0,  'pct' => 0],
        ]);

        // Highest threshold first so the most generous matching slab wins.
        usort($slabs, static fn(array $a, array $b): int => (int) $b['minHrs'] <=> (int) $a['minHrs']);

        $percent = 0.0;
        $reason  = 'Cancelled after the free-cancellation window.';

        foreach ($slabs as $slab) {
            if ($hoursLeft >= (float) $slab['minHrs']) {
                $percent = (float) $slab['pct'];
                $reason  = sprintf(
                    'Cancelled %s hours before departure — %s%% refundable.',
                    number_format(max(0, $hoursLeft), 0),
                    number_format($percent, 0)
                );
                break;
            }
        }

        return [
            'amount'    => round($amountPaid * $percent / 100),
            'percent'   => $percent,
            'hoursLeft' => round($hoursLeft, 1),
            'reason'    => $reason,
        ];
    }

    /**
     * Referral commission for a confirmed booking — port of
     * computeReferralCommission(), including the self-referral guard
     * and the confirm-within-N-days window.
     *
     * @param array<string, mixed> $booking
     * @return array{amount: float, agent: array<string, mixed>|null, mode: string, value: float, withinWindow: bool}
     */
    public static function referralCommission(array $booking, int $seatCount): array
    {
        $none = ['amount' => 0.0, 'agent' => null, 'mode' => 'flat', 'value' => 0.0, 'withinWindow' => false];

        $code = strtoupper((string) ($booking['referral_code'] ?? ''));
        if ($code === '') {
            return $none;
        }

        $agent = Database::fetch(
            "SELECT * FROM users WHERE ref_code = :c AND role = 'agent' AND is_blocked = 0 LIMIT 1",
            ['c' => $code]
        );

        if ($agent === null) {
            return $none;
        }

        // An agent cannot earn commission on their own booking.
        if (normalisePhone((string) ($booking['contact_phone'] ?? '')) === (string) $agent['phone']) {
            return $none;
        }

        $mode    = Settings::getString('referral_mode', 'flat');
        $flat    = Settings::getFloat('referral_flat', 100.0);
        $percent = Settings::getFloat('referral_percent', 5.0);
        $window  = Settings::getInt('referral_window_days', 7);

        $amount = $mode === 'percent'
            ? round((float) ($booking['total_amount'] ?? 0) * $percent / 100)
            : $flat;

        $createdAt    = strtotime((string) ($booking['created_at'] ?? 'now'));
        $withinWindow = (time() - $createdAt) <= ($window * 86400);

        return [
            'amount'       => $withinWindow ? max(0.0, $amount) : 0.0,
            'agent'        => $agent,
            'mode'         => $mode,
            'value'        => $mode === 'percent' ? $percent : $flat,
            'withinWindow' => $withinWindow,
        ];
    }

    /**
     * Tax on a subtotal. Zero by default — set tax_percent in Admin →
     * Settings once GST registration is active.
     */
    public static function tax(float $subtotal): float
    {
        $percent = Settings::getFloat('tax_percent', 0.0);
        return $percent > 0 ? round($subtotal * $percent / 100, 2) : 0.0;
    }

    /**
     * Assemble a complete, authoritative quote for a booking request.
     *
     * Every discount is applied in a fixed order so the arithmetic is
     * reproducible: base -> group -> coupon -> tier -> points -> tax -> fee.
     *
     * @return array{
     *   base: float, groupDiscount: float, couponDiscount: float,
     *   tierDiscount: float, tierName: string, pointsUsed: int,
     *   pointsValue: float, tax: float, fee: float, total: float,
     *   breakdown: array<int, array{label: string, amount: float}>
     * }
     */
    public static function quote(
        float $baseAmount,
        int $seatCount,
        int $lifetimePoints = 0,
        int $pointsRequested = 0,
        int $pointsAvailable = 0,
        string $couponCode = '',
        string $phone = '',
        ?int $routeId = null,
        array $ctx = []
    ): array {
        /* 26 Sep 2026 — $ctx carries what the ADVANCE offer needs to be
           decided: travelDate, departureTime and bookingMode. It is a
           trailing optional argument so every existing caller keeps working
           and simply gets no advance discount until it passes the context. */
        $ctxDate = trim((string) ($ctx['travelDate'] ?? ''));
        $ctxTime = trim((string) ($ctx['departureTime'] ?? ''));
        $ctxMode = isset($ctx['bookingMode']) && in_array($ctx['bookingMode'], ['sharing', 'private'], true)
            ? (string) $ctx['bookingMode'] : null;

        $breakdown = [['label' => 'Base fare', 'amount' => $baseAmount]];

        // 1. Group discount
        $minSeats     = Settings::getInt('group_discount_min_seats', 5);
        $groupPct     = Settings::getFloat('group_discount_percent', 5.0);
        $groupCut     = ($minSeats > 0 && $seatCount >= $minSeats) ? round($baseAmount * $groupPct / 100) : 0.0;
        $running      = $baseAmount - $groupCut;

        if ($groupCut > 0) {
            $breakdown[] = ['label' => 'Group discount (' . $seatCount . ' seats)', 'amount' => -$groupCut];
        }

        /* 1b. ADVANCE-BOOKING OFFER (26 Sep 2026) — "book N hours before
           departure, save P%". Applied here, before the coupon, so a coupon
           percentage is taken off the already-reduced amount and the order of
           operations stays reproducible. Identical for a sharing berth and a
           VIP private cabin: this is the same quote for both. */
        $advanceCut  = 0.0;
        $advancePct  = 0.0;
        $advanceInfo = ['ok' => false, 'amount' => 0.0, 'percent' => 0.0, 'hoursLeft' => 0.0,
                        'hoursNeeded' => 0, 'title' => '', 'text' => '', 'why' => 'No advance context supplied.'];
        if ($ctxDate !== '') {
            $advanceInfo = self::advanceDiscount($running, $ctxDate, $ctxTime, $ctxMode);
            if (!empty($advanceInfo['ok'])) {
                $advanceCut  = (float) $advanceInfo['amount'];
                $advancePct  = (float) $advanceInfo['percent'];
                $running    -= $advanceCut;
                $breakdown[] = [
                    'label'  => (string) $advanceInfo['title'] . ' ('
                                . rtrim(rtrim(number_format($advancePct, 2, '.', ''), '0'), '.') . '%)',
                    'amount' => -$advanceCut,
                ];
            }
        }

        // 2. Coupon — typed by the passenger, or the running offer
        $couponCut = 0.0;
        if ($couponCode !== '') {
            $couponResult = self::couponDiscount($couponCode, $running, $phone, $routeId);
            if ($couponResult['ok']) {
                $couponCut   = (float) $couponResult['amount'];
                $running    -= $couponCut;
                $breakdown[] = ['label' => 'Coupon ' . strtoupper($couponCode), 'amount' => -$couponCut];
            }
        } else {
            $auto = self::autoOffer($running, $phone, $routeId);
            if (!empty($auto['ok'])) {
                $couponCut   = (float) $auto['amount'];
                $running    -= $couponCut;
                $couponCode  = (string) $auto['code'];   // stored on the booking
                $breakdown[] = ['label' => (string) $auto['title'], 'amount' => -$couponCut];
            }
        }

        // 3. Loyalty tier
        $tier    = self::tierDiscount($running, $lifetimePoints);
        $tierCut = $tier['amount'];
        $running -= $tierCut;

        if ($tierCut > 0) {
            $breakdown[] = ['label' => $tier['tierName'] . ' member discount', 'amount' => -$tierCut];
        }

        // 4. Points
        $points     = self::pointsRedemption($pointsRequested, $pointsAvailable, $running);
        $pointsCut  = $points['value'];
        $running   -= $pointsCut;

        if ($pointsCut > 0) {
            $breakdown[] = ['label' => $points['points'] . ' loyalty points', 'amount' => -$pointsCut];
        }

        // 5. Tax and booking fee
        $tax = self::tax($running);
        if ($tax > 0) {
            $breakdown[] = ['label' => 'Taxes', 'amount' => $tax];
        }

        $fee = Settings::getFloat('booking_fee', 0.0);
        if ($fee > 0) {
            $breakdown[] = ['label' => 'Booking fee', 'amount' => $fee];
        }

        $total = max(0.0, round($running + $tax + $fee));

        /* The four lines every screen must show before payment (req 23):
           Original fare, Discount %, Discount amount, Final fare. Computed
           HERE so the checkout, the counter, the agent panel, Quick Ticket,
           the chatbot and WhatsApp cannot each arrive at a different set. */
        $discountTotal = max(0.0, round($groupCut + $advanceCut + $couponCut + $tierCut + $pointsCut));
        $discountPct   = $baseAmount > 0 ? round($discountTotal * 100 / $baseAmount, 2) : 0.0;

        return [
            'base'           => $baseAmount,
            'originalFare'   => $baseAmount,
            'discountAmount' => $discountTotal,
            'discountPercent' => $discountPct,
            'finalFare'      => $total,
            'advanceDiscount' => $advanceCut,
            'advancePercent'  => $advancePct,
            'advanceTitle'    => $advanceCut > 0 ? (string) $advanceInfo['title'] : '',
            'advanceWhy'      => (string) ($advanceInfo['why'] ?? ''),
            'advanceHoursLeft' => (float) ($advanceInfo['hoursLeft'] ?? 0),
            'advanceHoursNeeded' => (int) ($advanceInfo['hoursNeeded'] ?? 0),
            'groupDiscount'  => $groupCut,
            'couponDiscount' => $couponCut,
            'couponCode'     => $couponCut > 0 ? strtoupper($couponCode) : '',
            'tierDiscount'   => $tierCut,
            'tierName'       => $tierCut > 0 ? $tier['tierName'] : '',
            'pointsUsed'     => $points['points'],
            'pointsValue'    => $pointsCut,
            'tax'            => $tax,
            'fee'            => $fee,
            'total'          => $total,
            'breakdown'      => $breakdown,
        ];
    }
}
