<?php
/**
 * =====================================================================
 *  TicketBrain — the PASSIVE half of QuickBot (7 Sep 2026).
 *
 *  Owner ask: "Agent bolne bhanda pahile bot le sochos. Raat: VPS
 *  thinks. Bihana: agent opens the desk and the cards are already
 *  waiting. Passenger calls -> card already there -> confirm -> done."
 *
 *  TicketBot (6 Sep) is REACTIVE: the desk types, the bot suggests.
 *  TicketBrain is PROACTIVE: nobody types, and the work is already done.
 *
 *  The three things it does while the office is asleep
 *  ---------------------------------------------------
 *    1. think()    Reads a year of VERIFIED sales, learns each traveller's
 *                  own rhythm (how often they travel, how far ahead they
 *                  book, which weekday, which pickup) and writes a ranked
 *                  list of WHO IS LIKELY TO CALL, day by day, for the next
 *                  week. Each row is a card the desk confirms in one tap.
 *
 *    2. alerts()   The standing intelligence: a bus filling faster than
 *                  usual, a week that was busy this time LAST year, a
 *                  number that keeps cancelling, tomorrow's departures,
 *                  today's revenue against target.
 *
 *    3. capture()  A request that arrives on its own (WhatsApp today) is
 *                  parsed into the same option set the desk would type and
 *                  parked in the drafts queue. One confirmation sells it.
 *
 *  What it never does
 *  ------------------
 *    - never sells, never holds a seat, never prices, never messages a
 *      passenger on its own. Every row here is ADVISORY. Drop all three
 *      brain tables and the ticket desk sells exactly as it does today.
 *    - never predicts from cancelled / rejected / expired bookings: those
 *      are noise, not a pattern (same rule TicketBot::profile() follows).
 *    - never invents a festival date. Demand spikes are measured from the
 *      company's OWN sales a year ago (a "demand echo"); named festivals
 *      come from the brain_festivals setting the office fills in, so the
 *      calendar is never wrong in code.
 *
 *  Why no external AI call
 *  -----------------------
 *  The nightly pass must finish at 02:00 on a VPS with no network luck and
 *  no per-call bill, and a hallucinated seat number is worse than no
 *  suggestion. So the maths here is the company's own statistics — the
 *  same choice TicketBot made. The card it produces is then handed to
 *  TicketBot::suggest(), which is handed to QuickTicket::plan(), which is
 *  the only thing that ever reads live availability. One engine.
 *
 *  Storage: MariaDB (brain_predictions / brain_drafts / brain_alerts) —
 *  see database/upgrade-2026-09-quickbot-brain.sql.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/ticketbot.php';

final class TicketBrain
{
    /** Bump when the stored signal shape changes. */
    public const VERSION = 1;

    /** Score bands (out of 1000) used for the card colour. */
    private const BAND_HOT  = 700;
    private const BAND_WARM = 450;

    /** A traveller needs at least this many verified trips before a rhythm means anything. */
    private const MIN_TRIPS = 2;

    /** Hard ceiling on the nightly scan, so one runaway year cannot stall the box. */
    private const SCAN_LIMIT = 200000;

    /* =================================================================
     *  Settings — every one optional, every one office-editable.
     * ================================================================= */

    public static function enabled(): bool
    {
        return Settings::getBool('brain_on', true);
    }

    private static function historyDays(): int
    {
        return max(60, min(1095, Settings::getInt('brain_history_days', 365)));
    }

    private static function horizonDays(): int
    {
        return max(1, min(30, Settings::getInt('brain_horizon_days', 7)));
    }

    private static function queueSize(): int
    {
        return max(1, min(100, Settings::getInt('brain_queue_size', 12)));
    }

    /** Below this (of 1000) a guess is not worth the desk's attention. */
    private static function minScore(): int
    {
        return max(0, min(1000, Settings::getInt('brain_min_score', 250)));
    }

    /** People rarely call on the exact predicted day — keep the card alive this long. */
    private static function carryDays(): int
    {
        return max(0, min(7, Settings::getInt('brain_carry_days', 2)));
    }

    private static function fillAlertPct(): int
    {
        return max(10, min(100, Settings::getInt('brain_fill_alert_pct', 80)));
    }

    private static function cancelFlagCount(): int
    {
        return max(2, min(50, Settings::getInt('brain_cancel_flag', 3)));
    }

    private static function revenueTarget(): float
    {
        return max(0.0, Settings::getFloat('brain_revenue_target', 0.0));
    }

    /**
     * Named festival windows the office maintains, as JSON:
     *   [{"name":"Dashain","from":"2026-10-11","to":"2026-10-22"}, ...]
     *
     * Deliberately EMPTY by default. A wrong hard-coded festival date would
     * make the brain confidently wrong every year; the demand echo below
     * finds the same spike from real sales without needing a calendar.
     *
     * @return list<array{name: string, from: string, to: string}>
     */
    public static function festivals(): array
    {
        $raw = Settings::get('brain_festivals', []);
        if (is_string($raw)) {
            $raw = Settings::decodeJsonLayers($raw, 'brain_festivals');
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $f) {
            if (!is_array($f)) {
                continue;
            }
            $name = trim((string) ($f['name'] ?? ''));
            $from = trim((string) ($f['from'] ?? ''));
            $to   = trim((string) ($f['to'] ?? $from));
            if ($name === '' || !self::isDate($from) || !self::isDate($to)) {
                continue;
            }
            $out[] = ['name' => $name, 'from' => $from, 'to' => $to >= $from ? $to : $from];
        }

        return $out;
    }

    /**
     * Are the brain tables installed? Everything degrades to "no brain yet"
     * rather than a red cron panel when the migration has not been run.
     */
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                Database::query('SELECT 1 FROM brain_predictions LIMIT 1');
                Database::query('SELECT 1 FROM brain_drafts LIMIT 1');
                Database::query('SELECT 1 FROM brain_alerts LIMIT 1');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }

        return $ok;
    }

    /* =================================================================
     *  MODULE 1 — the nightly pass.
     * ================================================================= */

    /**
     * Read the year, rank who calls next, write the week's queue.
     *
     * Safe to re-run: a card the desk has already confirmed or dismissed is
     * never reopened, and each (day, phone) exists at most once.
     *
     * @return array<string, mixed> a cron-friendly one-line result
     */
    public static function think(string $today = '', bool $persist = true): array
    {
        $t0    = microtime(true);
        $today = self::isDate($today) ? $today : date('Y-m-d');

        if (!self::enabled()) {
            return ['skipped' => 'brain_on off'];
        }
        if ($persist && !self::ready()) {
            return ['skipped' => 'brain tables missing', 'hint' => 'run database/upgrade-2026-09-quickbot-brain.sql'];
        }

        $horizon = self::horizonDays();
        $since   = date('Y-m-d', strtotime($today . ' -' . self::historyDays() . ' days'));

        $travellers = self::travellers($since, $today);
        $echo       = self::demandEchoMap($today, $horizon, $since);

        // One best-day guess per traveller, then rank per day.
        $byDay = [];
        foreach ($travellers as $t) {
            $card = self::predictFor($t, $today, $horizon, $since);
            if ($card === null || $card['score'] < self::minScore()) {
                continue;
            }
            $byDay[$card['predictDate']][] = $card;
        }

        $cap     = self::queueSize();
        $written = 0;
        $kept    = [];
        foreach ($byDay as $day => $cards) {
            usort($cards, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $byDay[$day] = array_slice($cards, 0, $cap);
            $kept[$day]  = count($byDay[$day]);
        }
        ksort($kept);

        /* The passenger's own name, looked up ONCE for the handful of numbers
           that actually made the cut. Without it the 06:00 brief would read a
           phone number aloud instead of "Ram Bahadur Thapa". */
        $shortlist = [];
        foreach ($byDay as $cards) {
            foreach ($cards as $c) {
                $shortlist[] = (string) $c['phone'];
            }
        }
        $names = self::namesFor(array_values(array_unique($shortlist)));

        if ($persist) {
            foreach ($byDay as $day => $cards) {
                foreach ($cards as $c) {
                    $c['passenger'] = $names[(string) $c['phone']] ?? '';
                    $written += self::storePrediction((string) $day, $c);
                }
            }
        }

        $reconciled = $persist ? self::reconcile($today) : 0;
        $expired    = $persist ? self::expire($today) : 0;

        return [
            'travellers' => count($travellers),
            'days'       => $kept,
            'written'    => $written,
            'confirmed'  => $reconciled,
            'expired'    => $expired,
            'echoDays'   => count(array_filter($echo, static fn(array $e): bool => $e['score'] >= 0.5)),
            'ms'         => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * Every phone with a usable rhythm inside the window.
     *
     * One pass over verified outbound legs — cheap even on a year of sales,
     * and far cheaper than calling TicketBot::profile() per number (that is
     * saved for the handful of cards the desk actually sees).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function travellers(string $since, string $today): array
    {
        $limit = self::SCAN_LIMIT;
        $rows  = Database::fetchAll(
            "SELECT b.contact_phone AS phone, b.created_at, b.total_amount,
                    l.travel_date, l.seat_count, l.boarding_stop,
                    r.to_city
               FROM bookings b
               JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
          LEFT JOIN schedules s ON s.id = l.schedule_id
          LEFT JOIN routes r ON r.id = s.route_id
              WHERE b.status IN ('confirmed','completed')
                AND l.travel_date >= :since
                AND l.travel_date <= :today
                AND b.contact_phone <> ''
           ORDER BY b.contact_phone, l.travel_date
              LIMIT {$limit}",
            ['since' => $since, 'today' => $today]
        );

        $byPhone = [];
        foreach ($rows as $r) {
            $phone = normalisePhone((string) $r['phone']);
            if (!self::isRealPassengerPhone($phone)) {
                continue;
            }
            $byPhone[$phone][] = $r;
        }

        $out = [];
        foreach ($byPhone as $phone => $trips) {
            // A digits-only key comes back from PHP as an int — cast it back.
            $phone = (string) $phone;
            $t = self::rhythm($phone, $trips, $today);
            if ($t !== null) {
                $out[$phone] = $t;
            }
        }

        return $out;
    }

    /**
     * Turn one number's trips into a rhythm: how often, how far ahead, which
     * weekday, which pickup, how big a party.
     *
     * @param list<array<string, mixed>> $trips ordered by travel_date
     * @return array<string, mixed>|null
     */
    private static function rhythm(string $phone, array $trips, string $today): ?array
    {
        $dates = [];
        $leads = []; $weekday = []; $party = []; $stops = []; $stopLabel = []; $dirs = [];
        $spend = 0.0;

        foreach ($trips as $r) {
            $d = (string) $r['travel_date'];
            if (!self::isDate($d)) {
                continue;
            }
            $dates[$d] = true;                       // one trip per DAY: a 2-seat sale is one journey

            $ts = strtotime($d);
            $weekday[(int) date('w', $ts)] = ($weekday[(int) date('w', $ts)] ?? 0) + 1;

            $c = strtotime((string) $r['created_at']);
            if ($c !== false) {
                $leads[] = max(0, (int) floor(($ts - strtotime(date('Y-m-d', $c))) / 86400));
            }

            $n = max(1, (int) $r['seat_count']);
            $party[$n] = ($party[$n] ?? 0) + 1;

            $key = Boarding::townKey((string) $r['boarding_stop']);
            if ($key !== '') {
                $stops[$key] = ($stops[$key] ?? 0) + 1;
                $stopLabel[$key] = (string) $r['boarding_stop'];       // most recent label wins
            }
            if (!empty($r['to_city'])) {
                $dir = QuickTicket::directionOf(['to_city' => (string) $r['to_city']]);
                $dirs[$dir] = ($dirs[$dir] ?? 0) + 1;
            }
            $spend += (float) $r['total_amount'];
        }

        $dates = array_keys($dates);
        sort($dates);
        $n = count($dates);
        if ($n < self::MIN_TRIPS) {
            return null;
        }

        // Gaps between consecutive journeys — the rhythm itself.
        $gaps = [];
        for ($i = 1; $i < $n; $i++) {
            $g = (int) round((strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400);
            if ($g > 0) {
                $gaps[] = $g;
            }
        }
        if ($gaps === []) {
            return null;
        }

        $gapMedian = self::median($gaps);
        if ($gapMedian <= 0) {
            return null;
        }
        $gapMad = self::mad($gaps, $gapMedian);

        $lastTrip     = $dates[$n - 1];
        $daysSince    = (int) round((strtotime($today) - strtotime($lastTrip)) / 86400);
        $leadMedian   = $leads !== [] ? (int) round(self::median($leads)) : 1;

        arsort($weekday);
        arsort($party);
        arsort($stops);
        arsort($dirs);
        $topStopKey = (string) (array_key_first($stops) ?? '');

        return [
            'phone'      => $phone,
            'trips'      => $n,
            'dates'      => $dates,
            'lastTrip'   => $lastTrip,
            'daysSince'  => $daysSince,
            'gapMedian'  => $gapMedian,
            'gapMad'     => $gapMad,
            'leadMedian' => max(0, min(60, $leadMedian)),
            'weekday'    => $weekday,
            'topWeekday' => (int) (array_key_first($weekday) ?? 0),
            'party'      => max(1, (int) (array_key_first($party) ?? 1)),
            'boarding'   => $topStopKey !== '' ? (string) $stopLabel[$topStopKey] : '',
            'direction'  => (string) (array_key_first($dirs) ?? ''),
            'avgSpend'   => round($spend / max(1, count($trips)), 2),
        ];
    }

    /**
     * The one card this traveller earns for the coming week — which day they
     * are most likely to ring, what trip they will ask for, and how sure we
     * are (0..1000) with the reasons in plain words.
     *
     * @param array<string, mixed> $t
     * @return array<string, mixed>|null
     */
    private static function predictFor(array $t, string $today, int $horizon, string $since): ?array
    {
        $gap  = (float) $t['gapMedian'];
        $lead = (int) $t['leadMedian'];

        // When their own rhythm says the next journey falls.
        $expected = date('Y-m-d', strtotime($t['lastTrip'] . ' +' . (int) round($gap) . ' days'));
        $minTrip  = date('Y-m-d', strtotime($today . ' +1 day'));
        if ($expected < $minTrip) {
            // Overdue: they are already due, so aim at their favourite weekday
            // from tomorrow on — but the overdue penalty below still applies.
            $expected = self::nextWeekdayOnOrAfter((int) $t['topWeekday'], $minTrip);
        }

        // They ring roughly `lead` days before travelling.
        $call = date('Y-m-d', strtotime($expected . ' -' . $lead . ' days'));
        if ($call < $today) {
            $call = $today;
        }
        $lastDay = date('Y-m-d', strtotime($today . ' +' . $horizon . ' days'));
        if ($call > $lastDay) {
            return null;                                  // beyond the week we are planning
        }

        /* ---- signals, each 0..1 -------------------------------------- */

        // How metronomic they are: a 30-day rider who is never more than 3
        // days off scores high; a scattershot one scores low.
        $regularity = 1.0 - min(1.0, $t['gapMad'] / max(1.0, $gap));

        // How much evidence there is at all.
        $volume = min(1.0, ((int) $t['trips'] - 1) / 5.0);

        // Still on schedule = 1.0; decays once they are well past due.
        $overdue = $gap > 0 ? ((float) $t['daysSince']) / $gap : 0.0;
        $recency = $overdue <= 1.0 ? 1.0 : exp(-($overdue - 1.0));

        // Do they favour this weekday?
        $wd         = (int) date('w', (int) strtotime($expected));
        $weekdayFit = ((int) ($t['weekday'][$wd] ?? 0)) / max(1, (int) $t['trips']);

        // Was this window busy a year ago / is it a named festival?
        // Looked up by the TRAVEL date they will ask for, not the day they ring.
        $e         = self::demandEcho($expected, $since, $today);
        $echoScore = (float) $e['score'];

        $score = 0.30 * $regularity
               + 0.22 * $volume
               + 0.24 * $recency
               + 0.14 * $weekdayFit
               + 0.10 * $echoScore;
        $score = (int) round(max(0.0, min(1.0, $score)) * 1000);

        /* ---- why, in words the desk can argue with -------------------- */
        $reason = [];
        $reason[] = sprintf(
            'Travels about every %d days — last trip %s (%d days ago).',
            (int) round($gap),
            formatDate((string) $t['lastTrip'], 'j M'),
            (int) $t['daysSince']
        );
        if ($lead > 0) {
            $reason[] = sprintf('Usually books %d day%s ahead.', $lead, $lead === 1 ? '' : 's');
        } else {
            $reason[] = 'Usually books on the day of travel.';
        }
        if ($weekdayFit >= 0.5) {
            $reason[] = sprintf(
                '%d of %d trips on a %s.',
                (int) ($t['weekday'][$wd] ?? 0),
                (int) $t['trips'],
                date('l', (int) strtotime($expected))
            );
        }
        if ($regularity >= 0.7) {
            $reason[] = 'Very regular rhythm (rarely more than ' . max(1, (int) round($t['gapMad'])) . ' days off).';
        }
        if ($echoScore >= 0.5 && $e['label'] !== '') {
            $reason[] = $e['label'];
        }
        if ($overdue > 1.2) {
            $reason[] = 'Overdue by ' . (int) round(($overdue - 1.0) * $gap) . ' days — may have travelled with someone else.';
        }

        return [
            'predictDate' => $call,
            'phone'       => (string) $t['phone'],
            'score'       => $score,
            'band'        => $score >= self::BAND_HOT ? 'hot' : ($score >= self::BAND_WARM ? 'warm' : 'cool'),
            'travelDate'  => $expected,
            'direction'   => (string) $t['direction'],
            'boarding'    => (string) $t['boarding'],
            'seats'       => (int) $t['party'],
            'reason'      => $reason,
            'signals'     => [
                'regularity' => round($regularity, 3),
                'volume'     => round($volume, 3),
                'recency'    => round($recency, 3),
                'weekdayFit' => round($weekdayFit, 3),
                'echo'       => round($echoScore, 3),
                'gapMedian'  => round($gap, 1),
                'gapMad'     => round((float) $t['gapMad'], 1),
                'leadMedian' => $lead,
                'trips'      => (int) $t['trips'],
                'lastTrip'   => (string) $t['lastTrip'],
                'version'    => self::VERSION,
            ],
        ];
    }

    /**
     * Demand echo: for each day in the horizon, how busy the SAME window was
     * a year ago compared with a normal week, plus any named festival the
     * office has entered.
     *
     * 364 days back, not 365, so the weekday lines up — a Friday is compared
     * with a Friday, which matters for a bus that fills on weekends.
     *
     * @return array<string, array{score: float, label: string}>
     */
    private static function demandEchoMap(string $today, int $horizon, string $since): array
    {
        $out = [];
        for ($i = 0; $i <= $horizon; $i++) {
            $date       = date('Y-m-d', strtotime($today . ' +' . $i . ' days'));
            $out[$date] = self::demandEcho($date, $since, $today);
        }

        return $out;
    }

    /**
     * The echo for ONE date, memoised for the run.
     *
     * Looked up per date rather than over a fixed window because the two
     * callers ask about different days: alerts() walks the next `horizon`
     * days, while predictFor() asks about a passenger's expected TRAVEL date,
     * which for anyone who books a fortnight ahead sits well outside that
     * window. Building one map over the call-date range meant every such
     * passenger silently scored echo 0 — a named Dashain window could never
     * fire for exactly the people who book early for Dashain.
     *
     * @return array{score: float, label: string}
     */
    private static function demandEcho(string $date, string $since, string $today): array
    {
        static $cache = [];
        $key = $date . '|' . $since;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $perWeek   = self::echoBaseline($since, $today);
        $festivals = self::festivals();
        $score     = 0.0;
        $label     = '';

        if ($perWeek >= 3.0) {                       // below this, "last year" is not evidence
            // 364, not 365: a Friday must be compared with a Friday.
            $mid  = date('Y-m-d', strtotime($date . ' -364 days'));
            $from = date('Y-m-d', strtotime($mid . ' -3 days'));
            $to   = date('Y-m-d', strtotime($mid . ' +3 days'));
            $n    = (int) Database::scalar(
                "SELECT COUNT(*) FROM bookings b
                   JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
                  WHERE b.status IN ('confirmed','completed') AND l.travel_date BETWEEN :f AND :t",
                ['f' => $from, 't' => $to],
                0
            );
            $ratio = $n / max(1.0, $perWeek);
            if ($ratio > 1.0) {
                $score = min(1.0, ($ratio - 1.0));    // 2x a normal week = 1.0
                if ($score >= 0.5) {
                    $label = sprintf('This week last year ran %.1fx a normal week — expect a rush.', $ratio);
                }
            }
        }

        foreach ($festivals as $f) {
            if ($date >= $f['from'] && $date <= $f['to']) {
                $score = max($score, 0.8);
                $label = $f['name'] . ' window — block seats early.';
                break;
            }
        }

        return $cache[$key] = ['score' => $score, 'label' => $label];
    }

    /** Bookings in an average week over the learning window, memoised. */
    private static function echoBaseline(string $since, string $today): float
    {
        static $cache = [];
        $key = $since . '|' . $today;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $days = max(1, (int) round((strtotime($today) - strtotime($since)) / 86400));
        $tot  = (int) Database::scalar(
            "SELECT COUNT(*) FROM bookings b
               JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
              WHERE b.status IN ('confirmed','completed') AND l.travel_date >= :s AND l.travel_date <= :t",
            ['s' => $since, 't' => $today],
            0
        );

        return $cache[$key] = ($tot / $days) * 7.0;
    }

    /**
     * Write one card. An already-confirmed or dismissed card is left exactly
     * as the desk left it — the nightly re-run refreshes open cards only.
     *
     * Every value is bound twice under DIFFERENT names: with PDO's real
     * prepares a named placeholder may appear only once per statement.
     *
     * @param array<string, mixed> $c
     */
    private static function storePrediction(string $day, array $c): int
    {
        $reason  = json_encode($c['reason'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $signals = json_encode($c['signals'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        try {
            return Database::run(
                "INSERT INTO brain_predictions
                    (predict_date, phone, passenger, score, band, travel_date, direction, boarding, seats, reason, signals, status)
                 VALUES
                    (:day, :phone, :name, :score, :band, :tdate, :dir, :board, :seats, :reason, :signals, 'open')
                 ON DUPLICATE KEY UPDATE
                    passenger   = IF(status = 'open', :name2,    passenger),
                    score       = IF(status = 'open', :score2,   score),
                    band        = IF(status = 'open', :band2,    band),
                    travel_date = IF(status = 'open', :tdate2,   travel_date),
                    direction   = IF(status = 'open', :dir2,     direction),
                    boarding    = IF(status = 'open', :board2,   boarding),
                    seats       = IF(status = 'open', :seats2,   seats),
                    reason      = IF(status = 'open', :reason2,  reason),
                    signals     = IF(status = 'open', :signals2, signals)",
                [
                    'day'      => $day,
                    'phone'    => (string) $c['phone'],
                    'name'     => (string) ($c['passenger'] ?? ''),
                    'name2'    => (string) ($c['passenger'] ?? ''),
                    'score'    => (int) $c['score'],
                    'score2'   => (int) $c['score'],
                    'band'     => (string) $c['band'],
                    'band2'    => (string) $c['band'],
                    'tdate'    => (string) $c['travelDate'],
                    'tdate2'   => (string) $c['travelDate'],
                    'dir'      => (string) $c['direction'],
                    'dir2'     => (string) $c['direction'],
                    'board'    => (string) $c['boarding'],
                    'board2'   => (string) $c['boarding'],
                    'seats'    => (int) $c['seats'],
                    'seats2'   => (int) $c['seats'],
                    'reason'   => $reason,
                    'reason2'  => $reason,
                    'signals'  => $signals,
                    'signals2' => $signals,
                ]
            );

            /* One OPEN card per number, ever. A traveller whose call date is
               clamped to today gets a fresh row each night while the previous
               nights' rows are still inside the carry window, so without this
               the desk would meet the same person two or three times. The row
               written just now is the current one; older open rows for this
               number are superseded (confirmed / dismissed rows are left
               exactly as the desk left them). */
            Database::run(
                "UPDATE brain_predictions
                    SET status = 'expired', resolved_at = NOW()
                  WHERE phone = :p AND status = 'open' AND predict_date <> :day",
                ['p' => (string) $c['phone'], 'day' => $day]
            );

            return 1;
        } catch (Throwable $e) {
            Logger::warning('TicketBrain prediction write failed', ['phone' => $c['phone'], 'e' => $e->getMessage()], 'brain');

            return 0;
        }
    }

    /**
     * Did the guess land? Any open card whose number actually bought a
     * verified ticket since the card was written is marked confirmed and
     * linked to the booking. This is the honest accuracy signal — nobody
     * has to click "yes it worked".
     */
    private static function reconcile(string $today): int
    {
        $from = date('Y-m-d', strtotime($today . ' -' . (self::carryDays() + 7) . ' days'));
        $open = Database::fetchAll(
            "SELECT id, phone, created_at FROM brain_predictions
              WHERE status = 'open' AND predict_date BETWEEN :f AND :t",
            ['f' => $from, 't' => $today]
        );

        $n = 0;
        foreach ($open as $row) {
            $booking = Database::fetch(
                "SELECT id FROM bookings
                  WHERE contact_phone = :p AND status IN ('confirmed','completed') AND created_at >= :c
               ORDER BY id LIMIT 1",
                ['p' => (string) $row['phone'], 'c' => (string) $row['created_at']]
            );
            if ($booking === null) {
                continue;
            }
            $n += Database::run(
                "UPDATE brain_predictions
                    SET status = 'confirmed', booking_id = :b, resolved_at = NOW()
                  WHERE id = :id AND status = 'open'",
                ['b' => (int) $booking['id'], 'id' => (int) $row['id']]
            ) > 0 ? 1 : 0;
        }

        return $n;
    }

    /** Cards whose day has passed unused. */
    private static function expire(string $today): int
    {
        $cut = date('Y-m-d', strtotime($today . ' -' . self::carryDays() . ' days'));

        return Database::run(
            "UPDATE brain_predictions SET status = 'expired', resolved_at = NOW()
              WHERE status = 'open' AND predict_date < :cut",
            ['cut' => $cut]
        );
    }

    /* =================================================================
     *  The desk's morning queue.
     * ================================================================= */

    /**
     * Today's ready cards, richest first.
     *
     * Each row is run back through TicketBot::suggest() so the desk sees the
     * SAME card it would get by typing the number by hand — live bus, live
     * seat, live fare, every rule checked at confirm time. The stored row
     * only says WHO and WHY; availability is never cached overnight.
     *
     * $scopeAdminId is an OBLIGATION, not a hint (Auth::bookingScopeAdminId):
     * a counter agent may only ever see the passengers they sold themselves,
     * so a scoped desk gets cards for its own customers and nobody else's.
     *
     * @param array<string, mixed>|null $staff the signed-in admins row
     * @return list<array<string, mixed>>
     */
    public static function queue(string $date = '', int $limit = 0, ?array $staff = null, bool $enrich = true, ?int $scopeAdminId = null): array
    {
        if (!self::enabled() || !self::ready()) {
            return [];
        }
        $date  = self::isDate($date) ? $date : date('Y-m-d');
        $from  = date('Y-m-d', strtotime($date . ' -' . self::carryDays() . ' days'));
        $limit = $limit > 0 ? min(50, $limit) : self::queueSize();
        $params = ['f' => $from, 't' => $date];

        $scopeSql = '';
        if ($scopeAdminId !== null) {
            $scopeSql = ' AND phone IN (SELECT DISTINCT contact_phone FROM bookings WHERE sold_by_admin_id = :me)';
            $params['me'] = $scopeAdminId;
        }

        /* One card per NUMBER. A traveller whose call date was clamped to today
           can hold an open row on each day of the carry window; without this the
           desk would see the same person two or three times and burn the queue.
           The newest, highest-scoring row wins. */
        $rows = Database::fetchAll(
            "SELECT * FROM brain_predictions
              WHERE status = 'open' AND predict_date BETWEEN :f AND :t{$scopeSql}
           ORDER BY score DESC, predict_date DESC, id
              LIMIT 200",
            $params
        );

        $seen = [];
        $rows = array_values(array_filter($rows, static function (array $r) use (&$seen): bool {
            $p = (string) $r['phone'];
            if (isset($seen[$p])) {
                return false;
            }
            $seen[$p] = true;

            return true;
        }));
        $rows = array_slice($rows, 0, $limit);

        $out = [];
        foreach ($rows as $r) {
            $card = [
                'id'         => (int) $r['id'],
                'phone'      => (string) $r['phone'],
                'passenger'  => (string) $r['passenger'],
                'score'      => (int) $r['score'],
                'confidence' => round(((int) $r['score']) / 1000, 2),
                'band'       => (string) $r['band'],
                'predictDate'=> (string) $r['predict_date'],
                'travelDate' => (string) ($r['travel_date'] ?? ''),
                'direction'  => (string) $r['direction'],
                'boarding'   => (string) $r['boarding'],
                'seats'      => (int) $r['seats'],
                'reason'     => self::jsonList((string) ($r['reason'] ?? '')),
                'signals'    => self::jsonMap((string) ($r['signals'] ?? '')),
                'stale'      => (string) $r['predict_date'] < $date,
            ];

            if ($enrich) {
                // The live half: same engine the desk types into.
                $card['suggestion'] = TicketBot::suggest([
                    'phone'     => $card['phone'],
                    'name'      => $card['passenger'],
                    'seats'     => $card['seats'],
                    'direction' => $card['direction'],
                    'boarding'  => $card['boarding'],
                    'date'      => $card['travelDate'],
                ], $staff);
                // The desk's card shows the name the bot knows, not the stored one.
                $known = (string) ($card['suggestion']['prefill']['name'] ?? '');
                if ($known !== '') {
                    $card['passenger'] = $known;
                }
            }

            $out[] = $card;
        }

        return $out;
    }

    /**
     * How well the brain has been guessing (last 30 days of resolved cards).
     *
     * @return array<string, mixed>
     */
    public static function accuracy(int $days = 30): array
    {
        if (!self::ready()) {
            return ['sample' => 0, 'hitRate' => 0.0, 'days' => $days];
        }
        $from = date('Y-m-d', strtotime('-' . max(1, $days) . ' days'));
        $row  = Database::fetch(
            "SELECT COUNT(*) AS n,
                    SUM(status = 'confirmed') AS hit,
                    SUM(status = 'dismissed') AS no,
                    SUM(status = 'expired')   AS gone
               FROM brain_predictions
              WHERE predict_date >= :f AND status <> 'open'",
            ['f' => $from]
        ) ?? [];

        $n   = (int) ($row['n'] ?? 0);
        $hit = (int) ($row['hit'] ?? 0);

        return [
            'sample'    => $n,
            'hits'      => $hit,
            'dismissed' => (int) ($row['no'] ?? 0),
            'expired'   => (int) ($row['gone'] ?? 0),
            'hitRate'   => $n > 0 ? round($hit / $n, 2) : 0.0,
            'days'      => $days,
        ];
    }

    /** The desk acted on a card. */
    public static function resolvePrediction(int $id, string $status, ?int $bookingId = null, ?int $adminId = null): bool
    {
        if (!in_array($status, ['confirmed', 'dismissed'], true) || !self::ready()) {
            return false;
        }

        return Database::run(
            "UPDATE brain_predictions
                SET status = :s, booking_id = :b, resolved_by = :a, resolved_at = NOW()
              WHERE id = :id AND status = 'open'",
            ['s' => $status, 'b' => $bookingId, 'a' => $adminId, 'id' => $id]
        ) > 0;
    }

    /* =================================================================
     *  MODULE 2 — requests that arrive on their own.
     * ================================================================= */

    /**
     * A message came in. If it reads like a booking request, park a draft
     * for the desk and tell the caller what we understood.
     *
     * Returns null when the text is not a request at all (a PNR lookup, a
     * greeting, a thank-you) so the caller can fall back to its own reply.
     *
     * @return array<string, mixed>|null
     */
    public static function capture(string $phoneRaw, string $text, string $channel = 'whatsapp'): ?array
    {
        $text  = trim($text);
        $phone = normalisePhone($phoneRaw);
        if ($text === '' || $phone === '' || !self::enabled() || !self::ready()) {
            return null;
        }

        $parsed = TicketBot::parse($text);
        $found  = is_array($parsed['found'] ?? null) ? $parsed['found'] : [];

        // Is this a booking request? Either the parser pulled out something
        // bookable, or the words say so. A bare "hello" is neither.
        $hard = array_intersect($found, ['seats', 'date', 'direction', 'boarding']);
        if ($hard === [] && !self::looksLikeRequest($text)) {
            return null;
        }

        /* Their own history fills the blanks the message left — but only when
           it is actually a HABIT, at the same bar TicketBot::suggest() uses.
           This door used to apply a number's past seats / direction / boarding
           with no share and no sample threshold at all, so ONE previous trip
           was enough to auto-fill a draft: a passenger who happened to board at
           Mehsana once got Mehsana pre-filled forever, and the desk saw a
           confident-looking card built on a single data point. suggest()
           requires share >= 0.5 && n >= 2 for a party size, >= 0.6 && n >= 2
           for a direction and >= 0.5 for a pickup; the WhatsApp door is the
           same bot and now asks the same question. */
        $profile = TicketBot::profile($phone);
        $habit   = static function (?array $p, float $minShare, int $minN): bool {
            return $p !== null
                && (float) ($p['share'] ?? 0) >= $minShare
                && (int) ($p['n'] ?? 0) >= $minN;
        };

        $seats = (int) ($parsed['seats'] ?? 0);
        if ($seats < 1) {
            $seats = $habit($profile['party'] ?? null, 0.5, 2)
                ? (int) $profile['party']['size']
                : 1;
        }
        $direction = (string) ($parsed['direction'] ?? '');
        if ($direction === '' && $habit($profile['direction'] ?? null, 0.6, 2)) {
            $direction = (string) $profile['direction']['value'];
        }
        $boarding = (string) ($parsed['boarding'] ?? '');
        if ($boarding === '' && $habit($profile['boarding'] ?? null, 0.5, 2)) {
            $boarding = (string) ($profile['boarding']['label'] ?? '');
        }
        $date = (string) ($parsed['date'] ?? '');
        $name = (string) ($parsed['name'] ?? '');
        if ($name === '') {
            $name = (string) ($profile['name'] ?? '');
        }

        /* What the desk must look at before confirming. */
        $flags = [];
        if ($date === '') {
            $flags[] = 'No date in the message — confirm the travel date.';
        }
        if ($direction === '') {
            $flags[] = 'Direction not clear — confirm India or Nepal.';
        }
        if ($name === '') {
            $flags[] = 'No passenger name yet.';
        }
        if ($date !== '' && self::hasBookingOn($phone, $date)) {
            $flags[] = 'DUPLICATE: this number already holds a ticket for ' . formatDate($date, 'j M') . '.';
        }
        if (self::openDraftCount($phone) > 0) {
            $flags[] = 'This number already has an unconfirmed request waiting.';
        }

        // 0..100: how much of a bookable request actually arrived.
        $confidence = 0;
        foreach (['seats' => 20, 'date' => 30, 'direction' => 25, 'boarding' => 15, 'name' => 10] as $k => $w) {
            $has = $k === 'name' ? $name !== '' : (($k === 'seats') ? ($parsed['seats'] ?? 0) > 0 : ($parsed[$k] ?? '') !== '');
            if ($has) {
                $confidence += $w;
            }
        }
        if ($profile !== null) {
            $confidence = min(100, $confidence + 15);      // a known number is a safer bet
        }

        $payload = [
            'parsed'  => $parsed,
            'profile' => $profile === null ? null : [
                'trips'    => (int) ($profile['trips'] ?? 0),
                'lastTrip' => (string) ($profile['lastTrip'] ?? ''),
                'name'     => (string) ($profile['name'] ?? ''),
                'gender'   => $profile['gender'] ?? null,
                'country'  => (string) ($profile['country'] ?? ''),
            ],
        ];

        try {
            $id = Database::insert('brain_drafts', [
                'channel'     => $channel,
                'phone'       => $phone,
                'passenger'   => mb_substr($name, 0, 120),
                'raw_text'    => mb_substr($text, 0, 2000),
                'parsed'      => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'travel_date' => $date !== '' ? $date : null,
                'direction'   => $direction,
                'boarding'    => mb_substr($boarding, 0, 160),
                'seats'       => max(1, min(20, $seats)),
                'flags'       => mb_substr(implode("\n", $flags), 0, 255),
                'confidence'  => $confidence,
            ]);
        } catch (Throwable $e) {
            Logger::warning('TicketBrain draft write failed', ['phone' => $phone, 'e' => $e->getMessage()], 'brain');

            return null;
        }

        Logger::info('TicketBrain captured a request', [
            'id' => $id, 'channel' => $channel, 'confidence' => $confidence, 'flags' => count($flags),
        ], 'brain');

        return [
            'id'         => $id,
            'phone'      => $phone,
            'name'       => $name,
            'seats'      => $seats,
            'date'       => $date,
            'direction'  => $direction,
            'boarding'   => $boarding,
            'flags'      => $flags,
            'confidence' => $confidence,
            'known'      => $profile !== null,
        ];
    }

    /**
     * Words that mean "I want a ticket" in the three languages the desk
     * actually gets messages in. Kept deliberately narrow: a false positive
     * costs the desk a glance at a draft, so it must not fire on "thanks".
     */
    private static function looksLikeRequest(string $text): bool
    {
        $t = mb_strtolower($text, 'UTF-8');
        $words = [
            // English
            'seat', 'seats', 'ticket', 'tickets', 'book', 'booking', 'reserve', 'want', 'need',
            // Hindi / Nepali in Roman
            'chahiye', 'chaiye', 'chahie', 'chaiyo', 'chahincha', 'chha ki', 'banau', 'banao',
            'milcha', 'milega', 'khali', 'jana', 'janu', 'jane', 'nikalcha', 'nikalxa',
            // Devanagari
            'सिट', 'सीट', 'टिकट', 'चाहिए', 'चाहियो', 'चाहिन्छ', 'बुक', 'जानु', 'जाना', 'खाली',
        ];
        foreach ($words as $w) {
            if (mb_strpos($t, $w) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drafts still waiting for a decision. Scoped the same way as queue():
     * a counter agent sees only requests from numbers they have sold to.
     */
    public static function drafts(int $limit = 20, string $status = 'new', ?int $scopeAdminId = null): array
    {
        if (!self::ready()) {
            return [];
        }
        $limit  = max(1, min(100, $limit));
        $params = ['s' => $status];

        $scopeSql = '';
        if ($scopeAdminId !== null) {
            $scopeSql = ' AND phone IN (SELECT DISTINCT contact_phone FROM bookings WHERE sold_by_admin_id = :me)';
            $params['me'] = $scopeAdminId;
        }

        $rows = Database::fetchAll(
            "SELECT * FROM brain_drafts WHERE status = :s{$scopeSql} ORDER BY id DESC LIMIT {$limit}",
            $params
        );

        return array_map(static function (array $r): array {
            $flags = trim((string) $r['flags']);

            return [
                'id'         => (int) $r['id'],
                'channel'    => (string) $r['channel'],
                'phone'      => (string) $r['phone'],
                'passenger'  => (string) $r['passenger'],
                'text'       => (string) ($r['raw_text'] ?? ''),
                'travelDate' => (string) ($r['travel_date'] ?? ''),
                'direction'  => (string) $r['direction'],
                'boarding'   => (string) $r['boarding'],
                'seats'      => (int) $r['seats'],
                'flags'      => $flags === '' ? [] : explode("\n", $flags),
                'confidence' => (int) $r['confidence'],
                'createdAt'  => (string) $r['created_at'],
                'parsed'     => self::jsonMap((string) ($r['parsed'] ?? '')),
            ];
        }, $rows);
    }

    public static function resolveDraft(int $id, string $status, ?int $bookingId = null, ?int $adminId = null): bool
    {
        if (!in_array($status, ['confirmed', 'dismissed'], true) || !self::ready()) {
            return false;
        }

        return Database::run(
            "UPDATE brain_drafts
                SET status = :s, booking_id = :b, resolved_by = :a, resolved_at = NOW()
              WHERE id = :id AND status = 'new'",
            ['s' => $status, 'b' => $bookingId, 'a' => $adminId, 'id' => $id]
        ) > 0;
    }

    /** Count of requests waiting — the desk's badge (scoped like queue()). */
    public static function pending(?int $scopeAdminId = null): array
    {
        if (!self::enabled() || !self::ready()) {
            return ['drafts' => 0, 'cards' => 0];
        }
        $today  = date('Y-m-d');
        $from   = date('Y-m-d', strtotime($today . ' -' . self::carryDays() . ' days'));
        $params = ['f' => $from, 't' => $today];

        $scopeSql = '';
        $draftParams = [];
        if ($scopeAdminId !== null) {
            $scopeSql = ' AND phone IN (SELECT DISTINCT contact_phone FROM bookings WHERE sold_by_admin_id = :me)';
            $params['me']      = $scopeAdminId;
            $draftParams['me'] = $scopeAdminId;
        }

        return [
            'drafts' => (int) Database::scalar(
                "SELECT COUNT(*) FROM brain_drafts WHERE status = 'new'" . $scopeSql,
                $draftParams,
                0
            ),
            // Distinct numbers, so the badge matches what queue() actually shows.
            'cards'  => (int) Database::scalar(
                "SELECT COUNT(DISTINCT phone) FROM brain_predictions
                  WHERE status = 'open' AND predict_date BETWEEN :f AND :t" . $scopeSql,
                $params,
                0
            ),
        ];
    }

    /* =================================================================
     *  MODULE 5 — standing intelligence.
     * ================================================================= */

    /**
     * Look at the week ahead and say what the desk should do about it.
     * Advisory only: no fare is changed, no seat is held, nothing is sent.
     *
     * @return list<array<string, mixed>>
     */
    public static function alerts(string $date = '', bool $persist = true): array
    {
        if (!self::enabled()) {
            return [];
        }
        $date    = self::isDate($date) ? $date : date('Y-m-d');
        $horizon = self::horizonDays();
        $out     = [];

        /* --- a bus filling faster than the desk has noticed -------------- */
        $to = date('Y-m-d', strtotime($date . ' +' . $horizon . ' days'));
        $runs = Database::fetchAll(
            "SELECT s.id, s.travel_date, s.route_id, r.from_city, r.to_city, r.id AS rid
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
              WHERE s.travel_date BETWEEN :f AND :t
                AND s.is_blocked = 0
                AND s.status = 'scheduled'
           ORDER BY s.travel_date, s.id
              LIMIT 60",
            ['f' => $date, 't' => $to]
        );
        $threshold = self::fillAlertPct();
        foreach ($runs as $s) {
            $fill = self::fillOf((int) $s['id']);
            if ($fill === null || $fill['capacity'] < 1) {
                continue;
            }
            if ($fill['pct'] < $threshold) {
                continue;
            }
            $daysOut = (int) round((strtotime((string) $s['travel_date']) - strtotime($date)) / 86400);
            $out[] = [
                'kind'     => 'fill',
                'ref'      => 'sch:' . (int) $s['id'],
                'severity' => $fill['pct'] >= 95 ? 'high' : 'warn',
                'title'    => sprintf(
                    '%s %s is %d%% full (%d seat%s left)',
                    formatDate((string) $s['travel_date'], 'j M'),
                    (string) $s['from_city'] . ' to ' . (string) $s['to_city'],
                    $fill['pct'],
                    $fill['left'],
                    $fill['left'] === 1 ? '' : 's'
                ),
                'body'     => $daysOut > 1
                    ? sprintf('Still %d days out. Peak-day pricing is worth a look, and the extra bus is worth deciding today.', $daysOut)
                    : 'Departs within a day — confirm the waitlist before releasing any held seat.',
                'meta'     => $fill + ['scheduleId' => (int) $s['id'], 'daysOut' => $daysOut],
            ];
        }

        /* --- a week that was busy this time last year ------------------- */
        $since = date('Y-m-d', strtotime($date . ' -' . self::historyDays() . ' days'));
        foreach (self::demandEchoMap($date, $horizon, $since) as $d => $e) {
            if ($e['score'] < 0.5 || $e['label'] === '') {
                continue;
            }
            $out[] = [
                'kind'     => 'demand_echo',
                'ref'      => $d,
                'severity' => $e['score'] >= 0.8 ? 'high' : 'warn',
                'title'    => 'Demand spike expected ' . formatDate($d, 'D, j M'),
                'body'     => $e['label'],
                'meta'     => ['date' => $d, 'score' => round($e['score'], 2)],
            ];
        }

        /* --- a number that keeps cancelling ---------------------------- */
        $minCancels = self::cancelFlagCount();
        $repeat = Database::fetchAll(
            "SELECT contact_phone AS phone, COUNT(*) AS n, MAX(cancelled_at) AS last_at
               FROM bookings
              WHERE status = 'cancelled' AND cancelled_at >= :since
              GROUP BY contact_phone
             HAVING n >= :min
           ORDER BY n DESC
              LIMIT 10",
            ['since' => $since, 'min' => $minCancels]
        );
        foreach ($repeat as $r) {
            // Counter walk-ins all share one placeholder number, so "0000000000
            // has cancelled 5 times" is five different people and tells the desk
            // nothing. Same rule the prediction scan uses.
            if (!self::isRealPassengerPhone(normalisePhone((string) $r['phone']))) {
                continue;
            }
            $out[] = [
                'kind'     => 'cancel_risk',
                'ref'      => (string) $r['phone'],
                'severity' => 'info',
                'title'    => sprintf('%s has cancelled %d times', (string) $r['phone'], (int) $r['n']),
                'body'     => 'Worth a word before holding seats for this number again.',
                'meta'     => ['phone' => (string) $r['phone'], 'cancels' => (int) $r['n'], 'lastAt' => (string) $r['last_at']],
            ];
        }

        /* --- tomorrow's departures (the 10 PM checklist) ---------------- */
        $tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));
        // A run may retime itself for one date (dep_time_override); the route's
        // own dep_time is the standing timetable behind it.
        $depart = Database::fetchAll(
            "SELECT s.id, COALESCE(s.dep_time_override, r.dep_time) AS dep_time, r.from_city, r.to_city
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
              WHERE s.travel_date = :d AND s.is_blocked = 0 AND s.status = 'scheduled'
           ORDER BY dep_time
              LIMIT 20",
            ['d' => $tomorrow]
        );
        foreach ($depart as $s) {
            $fill = self::fillOf((int) $s['id']);
            if ($fill === null || $fill['sold'] < 1) {
                continue;
            }
            $out[] = [
                'kind'     => 'departure',
                'ref'      => 'sch:' . (int) $s['id'],
                'severity' => 'info',
                'title'    => sprintf(
                    'Tomorrow %s — %s to %s, %d passenger%s',
                    substr((string) $s['dep_time'], 0, 5),
                    (string) $s['from_city'],
                    (string) $s['to_city'],
                    $fill['sold'],
                    $fill['sold'] === 1 ? '' : 's'
                ),
                'body'     => 'Check driver, bus and the boarding list before the night shift ends.',
                'meta'     => $fill + ['scheduleId' => (int) $s['id'], 'date' => $tomorrow],
            ];
        }

        /* --- today against the target ----------------------------------
           NOT part of $out, and deliberately never persisted: the nightly job
           runs at 02:00, when "today" is two hours old and always ~0%. A row
           written then would sit on the desk saying "₹0 of ₹50,000 (0%)" all
           day, including at 6pm after a full day's sales. It is computed live
           by revenueAlert() whenever the desk actually asks. */

        if ($persist && self::ready()) {
            foreach ($out as $a) {
                try {
                    Database::insertIgnore('brain_alerts', [
                        'alert_date' => $date,
                        'kind'       => (string) $a['kind'],
                        'ref'        => mb_substr((string) $a['ref'], 0, 64),
                        'severity'   => (string) $a['severity'],
                        'title'      => mb_substr((string) $a['title'], 0, 160),
                        'body'       => (string) $a['body'],
                        'meta'       => json_encode($a['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                } catch (Throwable $e) {
                    Logger::warning('TicketBrain alert write failed', ['kind' => $a['kind'], 'e' => $e->getMessage()], 'brain');
                }
            }
        }

        return $out;
    }

    /**
     * Today's revenue against the target, computed at the moment it is asked
     * for (never stored — see alerts()). Null when no target is set.
     *
     * @return array<string, mixed>|null
     */
    public static function revenueAlert(string $date = ''): ?array
    {
        $target = self::revenueTarget();
        if ($target <= 0) {
            return null;
        }
        $date   = self::isDate($date) ? $date : date('Y-m-d');
        $earned = (float) Database::scalar(
            "SELECT COALESCE(SUM(total_amount), 0) FROM bookings
              WHERE status IN ('confirmed','completed') AND DATE(created_at) = :d",
            ['d' => $date],
            0.0
        );
        $pct = (int) round(($earned / $target) * 100);

        return [
            'id'       => 0,                       // live, so there is no row to dismiss
            'kind'     => 'revenue',
            'ref'      => $date,
            'severity' => $pct >= 100 ? 'info' : ($pct >= 60 ? 'warn' : 'high'),
            'title'    => sprintf('Today: %s of %s (%d%%)', inr($earned), inr($target), $pct),
            'body'     => $pct >= 100 ? 'Target met.' : 'Short of target — the ready queue is the fastest catch-up.',
            'meta'     => ['earned' => $earned, 'target' => $target, 'pct' => $pct],
            'live'     => true,
        ];
    }

    /**
     * Today's open alerts, for the desk panel.
     *
     * $kinds limits what the caller is allowed to see: revenue against target
     * and other customers' cancellation history are office numbers, not
     * counter-clerk numbers, so the API passes only the kinds the signed-in
     * role holds a permission for.
     *
     * @param list<string>|null $kinds null = every kind
     */
    public static function openAlerts(string $date = '', int $limit = 12, ?array $kinds = null): array
    {
        if (!self::enabled() || !self::ready()) {
            return [];
        }
        $date  = self::isDate($date) ? $date : date('Y-m-d');
        $limit = max(1, min(50, $limit));

        $rows = Database::fetchAll(
            "SELECT id, kind, ref, severity, title, body, meta, alert_date
               FROM brain_alerts
              WHERE status = 'open' AND alert_date = :d
           ORDER BY FIELD(severity, 'high', 'warn', 'info'), id
              LIMIT {$limit}",
            ['d' => $date]
        );

        if ($kinds !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $r): bool => in_array((string) $r['kind'], $kinds, true)
            ));
        }

        // The live one, only when the caller may see it.
        if ($kinds === null || in_array('revenue', $kinds, true)) {
            $rev = self::revenueAlert($date);
            if ($rev !== null) {
                array_unshift($rows, $rev);
            }
        }

        return $rows;
    }

    public static function dismissAlert(int $id): bool
    {
        if (!self::ready()) {
            return false;
        }

        return Database::run(
            "UPDATE brain_alerts SET status = 'dismissed' WHERE id = :id AND status = 'open'",
            ['id' => $id]
        ) > 0;
    }

    /**
     * How full one run is. Capacity comes from the coach layout, never from
     * schedules.total_seats (that column is bookkeeping, not a cap).
     *
     * @return array{capacity: int, sold: int, left: int, pct: int}|null
     */
    private static function fillOf(int $scheduleId): ?array
    {
        try {
            $schedule = Seats::scheduleById($scheduleId);
            if ($schedule === []) {
                return null;
            }
            $coach = Seats::effectiveCoach($schedule);
            $bt    = $coach === 'sleeper' ? 'sharing' : 'seater';
            $av    = Seats::availabilityForSchedule($scheduleId, $bt);

            /* Staff berths are permanently reserved and never appear in
               `available`, so counting them against the seat list made an
               EMPTY bus read as "2 passengers, 3% full" — and the departure
               checklist then fired on every empty run. Sellable capacity is
               the seat list minus those berths. */
            $all      = array_map('strval', Seats::seatIds($coach, $bt));
            $staff    = array_map('strval', Seats::staffSeats($coach));
            $capacity = count(array_diff($all, $staff));
            $left     = count(array_map('strval', $av['available'] ?? []));
            if ($capacity < 1) {
                return null;
            }
            $sold = max(0, $capacity - $left);

            return [
                'capacity' => $capacity,
                'sold'     => $sold,
                'left'     => $left,
                'pct'      => (int) round(($sold / $capacity) * 100),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /* =================================================================
     *  Small helpers
     * ================================================================= */

    /**
     * The name each of these numbers last travelled under (lead passenger of
     * their most recent verified booking). One query for the whole shortlist.
     *
     * @param list<string> $phones
     * @return array<string, string>
     */
    private static function namesFor(array $phones): array
    {
        if ($phones === []) {
            return [];
        }
        $params = [];
        $marks  = [];
        foreach (array_slice($phones, 0, 200) as $i => $p) {
            $k = 'p' . $i;
            $marks[]     = ':' . $k;
            $params[$k]  = $p;
        }
        try {
            $rows = Database::fetchAll(
                'SELECT b.contact_phone AS phone, p.full_name
                   FROM bookings b
                   JOIN booking_passengers p ON p.booking_id = b.id AND p.is_primary = 1
                  WHERE b.contact_phone IN (' . implode(',', $marks) . ")
                    AND b.status IN ('confirmed','completed')
               ORDER BY b.id DESC",
                $params
            );
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $phone = (string) $r['phone'];
            $name  = trim((string) $r['full_name']);
            // Most recent booking wins; "(2)" suffixes are party fillers.
            if ($name !== '' && !isset($out[$phone]) && preg_match('/\(\d+\)$/', $name) !== 1) {
                $out[$phone] = mb_substr($name, 0, 120);
            }
        }

        return $out;
    }

    /**
     * Is this a number a real person will answer?
     *
     * A counter walk-in is sold against a PLACEHOLDER number (the desk may
     * leave the phone blank and the sale stores 0000000000). Every walk-in
     * therefore shares one "traveller" whose trips look daily and perfectly
     * regular — which is exactly the shape this engine scores highest, so the
     * placeholder would take the top HOT card every morning and prefill a sale
     * with an unreachable number. Repeated-digit and sequential dummies
     * (1111111111, 1234567890) go the same way.
     */
    private static function isRealPassengerPhone(string $phone): bool
    {
        if ($phone === '' || !Security::isValidPhone($phone)) {
            return false;
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '' || strlen($digits) < 10) {
            return false;
        }
        // All one digit (0000000000, 9999999999).
        if (preg_match('/^(\d)\1+$/', $digits) === 1) {
            return false;
        }
        // Straight run up or down (1234567890, 0987654321).
        $up = true; $down = true;
        for ($i = 1, $n = strlen($digits); $i < $n; $i++) {
            $step = (int) $digits[$i] - (int) $digits[$i - 1];
            if ($step !== 1) { $up = false; }
            if ($step !== -1) { $down = false; }
        }

        return !$up && !$down;
    }

    private static function hasBookingOn(string $phone, string $date): bool
    {
        try {
            return Database::exists(
                "SELECT 1 FROM bookings b
                   JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
                  WHERE b.contact_phone = :p AND l.travel_date = :d
                    AND b.status IN ('pending','confirmed','completed')
                  LIMIT 1",
                ['p' => $phone, 'd' => $date]
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function openDraftCount(string $phone): int
    {
        try {
            return (int) Database::scalar(
                "SELECT COUNT(*) FROM brain_drafts WHERE phone = :p AND status = 'new'",
                ['p' => $phone],
                0
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @param list<int> $v */
    private static function median(array $v): float
    {
        if ($v === []) {
            return 0.0;
        }
        sort($v);
        $n = count($v);
        $m = (int) floor($n / 2);

        return $n % 2 === 1 ? (float) $v[$m] : ($v[$m - 1] + $v[$m]) / 2.0;
    }

    /**
     * Median absolute deviation — how far off their own rhythm they usually
     * run. Robust to the one holiday trip that would wreck a plain variance.
     *
     * @param list<int> $v
     */
    private static function mad(array $v, float $median): float
    {
        if ($v === []) {
            return 0.0;
        }
        $dev = array_map(static fn(int $x): int => (int) round(abs($x - $median)), $v);

        return self::median($dev);
    }

    private static function nextWeekdayOnOrAfter(int $weekday, string $from): string
    {
        $ts   = strtotime($from);
        $diff = ($weekday - (int) date('w', $ts) + 7) % 7;

        return date('Y-m-d', strtotime($from . ' +' . $diff . ' days'));
    }

    private static function isDate(string $d): bool
    {
        return $d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;
    }

    /** @return list<string> */
    private static function jsonList(string $raw): array
    {
        $v = $raw === '' ? null : json_decode($raw, true);

        return is_array($v) ? array_values(array_map('strval', $v)) : [];
    }

    /** @return array<string, mixed> */
    private static function jsonMap(string $raw): array
    {
        $v = $raw === '' ? null : json_decode($raw, true);

        return is_array($v) ? $v : [];
    }
}
