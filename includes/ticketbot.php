<?php
/**
 * =====================================================================
 *  TicketBot — the AI ticket assistant behind Quick Ticket (6 Sep 2026).
 *
 *  Owner ask: "an AI Ticket Bot that learns from previous booking
 *  patterns and gradually improves its suggestions … automatically
 *  handles passenger details, route, date, boarding, seat, fare and
 *  ticket generation … while keeping all final booking decisions
 *  validated by the existing system rules."
 *
 *  What it is
 *  ----------
 *  A SUGGESTION engine. It never writes a booking, never prices a seat
 *  and never lifts a rule. Its whole output is the option set handed to
 *  QuickTicket::plan() — which berth, which pickup, how many seats, which
 *  direction — plus the passenger details the desk would otherwise type.
 *  plan() picks the bus / seat / fare from live availability and
 *  BookingService::create() enforces every rule at the moment of sale, so
 *  a wrong guess costs one tap on a chip, never a wrong ticket.
 *
 *  What it learns from
 *  -------------------
 *  VERIFIED bookings only (status confirmed / completed) — a cancelled,
 *  rejected or expired booking is not a pattern, it is noise.
 *
 *    • profile(phone)  — passenger memory: the name last used on this
 *                        number, their gender when it was recorded, the
 *                        pickup they usually board at, their usual
 *                        direction and party size, the deck they sit on,
 *                        their travel weekday, companions on past trips.
 *    • patterns()      — the company's own rhythm over the last N days:
 *                        which pickup sells at which hour of the day,
 *                        what each desk (seller) usually sells, the
 *                        direction mix by hour, party sizes, weekdays.
 *                        Cached in kv_store for a few minutes so a busy
 *                        desk never re-aggregates on every keystroke, and
 *                        refreshed as new verified sales land — that is
 *                        the "improves over time".
 *    • feedback()      — the outcome loop: when the desk keeps a
 *                        suggested field unchanged it counts as a hit,
 *                        when it changes it a miss. A field whose
 *                        suggestions are mostly changed stops being
 *                        auto-applied (it is still shown, flagged
 *                        "check") until its hit-rate recovers.
 *
 *  Every suggestion carries WHY (a short reason) and HOW SURE (a 0–1
 *  confidence = the share of verified trips supporting it), so the desk
 *  can read the bot, not just trust it.
 *
 *  What it deliberately never does
 *  -------------------------------
 *    • never guesses a gender (women-only berths depend on it): gender
 *      comes from the desk, the text, or this number's own past tickets;
 *    • never moves the travel DATE from history — a passenger who always
 *      travels on Fridays still gets the next catchable bus unless the
 *      desk says otherwise (history is shown as a hint only);
 *    • never touches fares, discounts, seat rules or cut-offs.
 *
 *  No external AI call is made here. The parsing is rule-based (fast,
 *  offline, free, and it cannot hallucinate a seat number); the learning
 *  is statistics over the company's own verified sales.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/quickticket.php';

final class TicketBot
{
    /** Bump when the cached pattern shape changes. */
    public const VERSION = 1;

    private const KV_PATTERNS = 'ticketbot.patterns.v1';
    private const KV_FEEDBACK = 'ticketbot.feedback.v1';

    /** Below this hit-rate (after MIN_SAMPLES outcomes) a field is shown but not auto-applied. */
    private const MIN_HIT_RATE = 0.4;
    private const MIN_SAMPLES  = 10;

    /** Fields the outcome loop tracks. */
    private const TRACKED = ['name', 'gender', 'boarding', 'direction', 'seats'];

    /**
     * Unicode-safe "end of word" (reviewed 10 Sep 2026): PCRE's \b is ASCII-only,
     * and Nepali / Gujarati write postpositions ATTACHED to the noun
     * (सुरतबाट = from Surat, भोलिको = tomorrow's, સુરતથી = from Surat), so a token
     * may be followed by one of those before the real word end.
     */
    private const NB = '(?:को|का|की|बाट|लाई|मा|ले|देखि|सम्म|तिर|तर्फ|થી|માં|ને|નું|ની|નો|ના)?(?![\p{L}\p{M}])';

    /* =================================================================
     *  Settings (all optional; sensible defaults so no migration is needed)
     * ================================================================= */

    public static function enabled(): bool
    {
        return Settings::getBool('ai_bot_on', true);
    }

    private static function windowDays(): int
    {
        return max(7, min(365, Settings::getInt('ai_bot_window_days', 90)));
    }

    private static function cacheMinutes(): int
    {
        return max(1, min(720, Settings::getInt('ai_bot_cache_min', 30)));
    }

    /* =================================================================
     *  Passenger memory
     * ================================================================= */

    /**
     * What this mobile number's own verified tickets say about the
     * passenger. Null when the number has never travelled.
     *
     * @return array<string, mixed>|null
     */
    /**
     * A live ticket this number already holds for the trip being planned.
     *
     * Answers "is the desk about to issue the same ticket twice?" — the
     * duplicate half of point 10. Deliberately NOT a gate: it reports, and the
     * operator decides. Scoped like every other passenger read, so a counter
     * agent is never told about another agent's sale.
     *
     * @param  array<string, mixed>|null $plan a QuickTicket::plan() result
     * @return array<string, mixed>|null
     */
    public static function existingTicketFor(string $phoneRaw, ?array $plan): ?array
    {
        $phone = normalisePhone($phoneRaw);
        if ($phone === '' || $plan === null) {
            return null;
        }
        // The walk-in placeholder is shared by every phoneless desk sale, so
        // matching on it would flag unrelated passengers as duplicates.
        if (preg_match('/^0+$/', preg_replace('/\D/', '', $phone) ?? '') === 1) {
            return null;
        }
        $date    = (string) ($plan['travelDate'] ?? $plan['date'] ?? '');
        $routeId = (int) ($plan['routeId'] ?? 0);
        if ($date === '' || $routeId <= 0) {
            return null;
        }

        $scopeId  = Auth::bookingScopeAdminId();
        $params   = ['p' => $phone, 'd' => $date, 'r' => $routeId];
        $scopeSql = '';
        if ($scopeId !== null) {
            $scopeSql        = ' AND b.sold_by_admin_id = :scope';
            $params['scope'] = $scopeId;
        }

        $row = Database::fetch(
            "SELECT b.pnr, b.status, l.travel_date, l.seat_count,
                    GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no) AS seats
               FROM bookings b
               JOIN booking_legs l ON l.booking_id = b.id
               JOIN schedules s ON s.id = l.schedule_id
          LEFT JOIN booking_seats bs ON bs.booking_id = b.id AND bs.released_at IS NULL
              WHERE b.contact_phone = :p
                AND l.travel_date = :d
                AND s.route_id = :r
                AND b.status IN ('pending','confirmed')" . $scopeSql .
          " GROUP BY b.id
              ORDER BY b.created_at DESC
              LIMIT 1",
            $params
        );
        if ($row === null) {
            return null;
        }
        return [
            'pnr'    => (string) $row['pnr'],
            'status' => (string) $row['status'],
            'date'   => (string) $row['travel_date'],
            'seats'  => array_values(array_filter(explode(',', (string) ($row['seats'] ?? '')))),
            'note'   => 'This number already has a ' . (string) $row['status']
                        . ' ticket on this route for ' . formatDate((string) $row['travel_date'])
                        . ' (' . (string) $row['pnr'] . ').',
        ];
    }

    public static function profile(string $phoneRaw, int $limit = 40): ?array
    {
        $phone = normalisePhone($phoneRaw);
        if ($phone === '' || !Security::isValidPhone($phone)) {
            return null;
        }
        $limit = max(1, min(200, $limit));

        /* SCOPED like every other passenger read (8 Sep 2026).
           This query filtered on contact_phone ALONE, and suggest() hands the
           whole profile back to the browser. A counter agent — for whom
           Auth::bookingScopeAdminId() returns their own id everywhere else —
           could therefore POST {action:'bot', phone:'<any number>'} and read
           that passenger's name, recorded gender, usual pickup, usual
           direction and party size out of another agent's book, one number at
           a time. Seat status and gender stay unscoped on the seat map because
           they identify nobody; a named travel history is the opposite. */
        $scopeId = Auth::bookingScopeAdminId();
        $params  = ['p' => $phone];
        $scopeSql = '';
        if ($scopeId !== null) {
            $scopeSql        = ' AND b.sold_by_admin_id = :scope';
            $params['scope'] = $scopeId;
        }

        $rows = Database::fetchAll(
            "SELECT b.id, b.pnr, b.status, b.total_amount, b.id_type, b.created_at, b.sold_by_admin_id, b.source,
                    l.travel_date, l.boarding_stop, l.seat_count, l.schedule_id,
                    r.to_city, r.from_city, r.id AS route_id
               FROM bookings b
          LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
          LEFT JOIN schedules s ON s.id = l.schedule_id
          LEFT JOIN routes r ON r.id = s.route_id
              WHERE b.contact_phone = :p AND b.status IN ('confirmed','completed')"
            . $scopeSql .
          " ORDER BY b.created_at DESC
              LIMIT {$limit}",
            $params
        );
        if ($rows === []) {
            return null;
        }

        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        [$in, $params] = self::inList($ids, 'b');
        $pax = Database::fetchAll(
            "SELECT booking_id, full_name, gender, is_primary, seat_no
               FROM booking_passengers WHERE booking_id IN ({$in}) ORDER BY booking_id DESC, is_primary DESC, id",
            $params
        );
        [$in2, $params2] = self::inList($ids, 'c');
        $seatRows = self::physicalSeatRows(Database::fetchAll(
            "SELECT bs.booking_id, bs.seat_no, b.booking_mode
               FROM booking_seats bs JOIN bookings b ON b.id = bs.booking_id
              WHERE bs.booking_id IN ({$in2}) AND bs.released_at IS NULL",
            $params2
        ));

        $trips = count($rows);
        $names = []; $genders = []; $stops = []; $stopLabel = []; $dirs = []; $party = [];
        $weekday = []; $leads = []; $totals = 0.0; $nepali = false; $lastTrip = ''; $lastBooked = '';
        foreach ($rows as $i => $r) {
            $key = Boarding::townKey((string) $r['boarding_stop']);
            if ($key !== '') {
                $stops[$key] = ($stops[$key] ?? 0) + 1;
                if (!isset($stopLabel[$key])) {
                    $stopLabel[$key] = (string) $r['boarding_stop'];     // most recent label wins
                }
            }
            if (!empty($r['to_city'])) {
                $d = QuickTicket::directionOf(['to_city' => (string) $r['to_city']]);
                $dirs[$d] = ($dirs[$d] ?? 0) + 1;
            }
            $n = max(1, (int) $r['seat_count']);
            $party[$n] = ($party[$n] ?? 0) + 1;
            $ts = strtotime((string) $r['travel_date']);
            if ($ts !== false) {
                $w = (int) date('w', $ts);
                $weekday[$w] = ($weekday[$w] ?? 0) + 1;
                $c = strtotime((string) $r['created_at']);
                if ($c !== false) {
                    $leads[] = max(0, (int) floor(($ts - strtotime(date('Y-m-d', $c))) / 86400));
                }
            }
            $totals += (float) $r['total_amount'];
            if (stripos((string) ($r['id_type'] ?? ''), 'nepal') !== false) {
                $nepali = true;
            }
            if ($i === 0) {
                $lastTrip   = (string) $r['travel_date'];
                $lastBooked = (string) $r['created_at'];
            }
        }

        $primaryName = ''; $nameCount = 0; $companions = [];
        $byBooking = [];
        foreach ($pax as $p) {
            $byBooking[(int) $p['booking_id']][] = $p;
        }
        $nameTally = [];
        foreach ($rows as $r) {
            $list = $byBooking[(int) $r['id']] ?? [];
            foreach ($list as $p) {
                $nm = trim((string) $p['full_name']);
                if ($nm === '') {
                    continue;
                }
                if ((int) $p['is_primary'] === 1) {
                    $nameTally[$nm] = ($nameTally[$nm] ?? 0) + 1;
                    if ($primaryName === '') {
                        $primaryName = $nm;
                    }
                } elseif (preg_match('/\(\d+\)$/', $nm) !== 1 && !in_array($nm, $companions, true) && count($companions) < 5) {
                    $companions[] = $nm;
                }
                if (!empty($p['gender'])) {
                    $g = (string) $p['gender'];
                    if ((int) $p['is_primary'] === 1) {
                        $genders[$g] = ($genders[$g] ?? 0) + 1;
                    }
                }
            }
        }
        $nameCount = $primaryName !== '' ? (int) ($nameTally[$primaryName] ?? 0) : 0;

        $deck = ['lower' => 0, 'upper' => 0]; $seatTally = [];
        foreach ($seatRows as $s) {
            $seat = strtoupper((string) $s['seat_no']);
            $seatTally[$seat] = ($seatTally[$seat] ?? 0) + 1;
            if (str_starts_with($seat, 'L')) {
                $deck['lower']++;
            } elseif (str_starts_with($seat, 'U')) {
                $deck['upper']++;
            }
        }
        arsort($seatTally);
        $deckTotal = $deck['lower'] + $deck['upper'];

        $topStop = self::top($stops);
        $topDir  = self::top($dirs);
        $topPty  = self::top($party);
        $topWd   = self::top($weekday);
        $topGen  = self::top($genders);
        $genderN = array_sum($genders);
        sort($leads);
        $median = $leads !== [] ? $leads[(int) floor((count($leads) - 1) / 2)] : null;

        $stopOut = null;
        if ($topStop !== null) {
            $label = $stopLabel[$topStop['key']];
            $disp  = Boarding::stopDisplay($label);
            $stopOut = [
                'label' => $label,
                'value' => self::stopValue($label),
                'name'  => (string) $disp['name'],
                'code'  => (string) $disp['code'],
                'time'  => (string) ($disp['time'] ?? ''),
                'n'     => $topStop['n'],
                'share' => round($topStop['n'] / $trips, 2),
            ];
        }

        return [
            'phone'      => $phone,
            'known'      => true,
            'trips'      => $trips,
            'lastTrip'   => $lastTrip,
            'lastBooked' => $lastBooked,
            'lastPnr'    => (string) $rows[0]['pnr'],
            'name'       => $primaryName,
            'nameN'      => $nameCount,
            'gender'     => $topGen !== null && $genderN > 0 ? (string) $topGen['key'] : null,
            'genderN'    => $genderN,
            'genderShare'=> $topGen !== null && $genderN > 0 ? round($topGen['n'] / $genderN, 2) : 0.0,
            'country'    => $nepali ? 'NP' : 'IN',
            'boarding'   => $stopOut,
            'direction'  => $topDir !== null ? ['value' => (string) $topDir['key'], 'n' => $topDir['n'], 'share' => round($topDir['n'] / $trips, 2)] : null,
            'party'      => $topPty !== null ? ['size' => (int) $topPty['key'], 'n' => $topPty['n'], 'share' => round($topPty['n'] / $trips, 2)] : null,
            'deck'       => $deckTotal > 0 ? ['value' => $deck['lower'] >= $deck['upper'] ? 'lower' : 'upper', 'share' => round(max($deck['lower'], $deck['upper']) / $deckTotal, 2)] : null,
            'seats'      => array_slice(array_keys($seatTally), 0, 3),
            'weekday'    => $topWd !== null ? ['value' => (int) $topWd['key'], 'label' => self::weekdayName((int) $topWd['key']), 'n' => $topWd['n'], 'share' => round($topWd['n'] / $trips, 2)] : null,
            'leadDays'   => $median,
            'companions' => $companions,
            'avgTotal'   => round($totals / $trips, 2),
        ];
    }

    /* =================================================================
     *  Company patterns (cached)
     * ================================================================= */

    /**
     * Aggregate rhythm of verified sales inside the learning window.
     * Cached in kv_store; recomputed when the cache is older than
     * ai_bot_cache_min minutes (or when $fresh is asked).
     *
     * @return array<string, mixed>
     */
    public static function patterns(bool $fresh = false): array
    {
        if (!$fresh) {
            $cached = self::kvGet(self::KV_PATTERNS);
            if (is_array($cached) && (int) ($cached['version'] ?? 0) === self::VERSION
                && (time() - (int) ($cached['computedAt'] ?? 0)) < self::cacheMinutes() * 60) {
                return $cached;
            }
        }
        $days  = self::windowDays();
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $rows  = Database::fetchAll(
            "SELECT b.id, b.created_at, b.sold_by_admin_id, b.source, b.contact_phone,
                    l.boarding_stop, l.seat_count, l.travel_date, r.to_city
               FROM bookings b
          LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
          LEFT JOIN schedules s ON s.id = l.schedule_id
          LEFT JOIN routes r ON r.id = s.route_id
              WHERE b.status IN ('confirmed','completed') AND b.created_at >= :since
           ORDER BY b.id DESC
              LIMIT 5000",
            ['since' => $since]
        );

        $stops = []; $label = []; $byHour = []; $bySeller = []; $dirByHour = []; $party = []; $weekday = []; $phones = [];
        foreach ($rows as $r) {
            $key = Boarding::townKey((string) $r['boarding_stop']);
            $c   = strtotime((string) $r['created_at']);
            $h   = $c !== false ? (int) date('G', $c) : -1;
            if ($key !== '') {
                $stops[$key] = ($stops[$key] ?? 0) + 1;
                if (!isset($label[$key])) {
                    $label[$key] = (string) $r['boarding_stop'];
                }
                if ($h >= 0) {
                    $byHour[$h][$key] = ($byHour[$h][$key] ?? 0) + 1;
                }
                $sid = (int) ($r['sold_by_admin_id'] ?? 0);
                if ($sid > 0) {
                    $bySeller[$sid][$key] = ($bySeller[$sid][$key] ?? 0) + 1;
                }
            }
            if (!empty($r['to_city']) && $h >= 0) {
                $d = QuickTicket::directionOf(['to_city' => (string) $r['to_city']]);
                $dirByHour[$h][$d] = ($dirByHour[$h][$d] ?? 0) + 1;
            }
            $n = max(1, (int) $r['seat_count']);
            $party[$n] = ($party[$n] ?? 0) + 1;
            $ts = strtotime((string) $r['travel_date']);
            if ($ts !== false) {
                $w = (int) date('w', $ts);
                $weekday[$w] = ($weekday[$w] ?? 0) + 1;
            }
            $phones[(string) $r['contact_phone']] = ($phones[(string) $r['contact_phone']] ?? 0) + 1;
        }
        $sample = count($rows);
        $repeat = count(array_filter($phones, static fn(int $n): bool => $n >= 2));

        arsort($stops);
        $stopList = [];
        foreach (array_slice($stops, 0, 8, true) as $key => $n) {
            $disp = Boarding::stopDisplay($label[$key]);
            $stopList[] = [
                'key'   => $key,
                'label' => $label[$key],
                'value' => self::stopValue($label[$key]),
                'name'  => (string) $disp['name'],
                'code'  => (string) $disp['code'],
                'n'     => $n,
                'share' => $sample > 0 ? round($n / $sample, 2) : 0.0,
            ];
        }
        $hourOut = [];
        foreach ($byHour as $h => $tally) {
            $t = self::top($tally);
            if ($t !== null && $t['n'] >= 3) {
                $tot = array_sum($tally);
                $hourOut[(string) $h] = ['key' => $t['key'], 'value' => self::stopValue($label[$t['key']]), 'name' => (string) Boarding::stopDisplay($label[$t['key']])['name'], 'n' => $t['n'], 'share' => round($t['n'] / $tot, 2)];
            }
        }
        $sellerOut = [];
        foreach ($bySeller as $sid => $tally) {
            $t = self::top($tally);
            if ($t !== null && $t['n'] >= 2) {
                $tot = array_sum($tally);
                $sellerOut[(string) $sid] = ['key' => $t['key'], 'value' => self::stopValue($label[$t['key']]), 'name' => (string) Boarding::stopDisplay($label[$t['key']])['name'], 'n' => $t['n'], 'share' => round($t['n'] / $tot, 2)];
            }
        }
        $dirOut = [];
        foreach ($dirByHour as $h => $tally) {
            $t = self::top($tally);
            if ($t !== null) {
                $tot = array_sum($tally);
                $dirOut[(string) $h] = ['value' => (string) $t['key'], 'n' => $t['n'], 'share' => round($t['n'] / $tot, 2)];
            }
        }
        ksort($party);
        $wdOut = [];
        foreach ($weekday as $w => $n) {
            $wdOut[(string) $w] = $n;
        }

        $out = [
            'version'    => self::VERSION,
            'computedAt' => time(),
            'windowDays' => $days,
            'sample'     => $sample,
            'repeat'     => $repeat,
            'stops'      => $stopList,
            'stopByHour' => $hourOut,
            'stopBySeller' => $sellerOut,
            'dirByHour'  => $dirOut,
            'party'      => $party,
            'weekday'    => $wdOut,
        ];
        self::kvSet(self::KV_PATTERNS, $out);

        return $out;
    }

    /* =================================================================
     *  Outcome loop
     * ================================================================= */

    /**
     * Record how a suggestion fared: for every tracked field the bot
     * proposed, was the sale made with that value (hit) or another (miss)?
     *
     * @param array<string, mixed> $suggested the `prefill` block suggest() returned
     * @param array<string, mixed> $final     what was actually sold
     */
    public static function feedback(array $suggested, array $final): void
    {
        $fb = self::kvGet(self::KV_FEEDBACK);
        if (!is_array($fb)) {
            $fb = [];
        }
        $touched = false;
        foreach (self::TRACKED as $f) {
            if (!array_key_exists($f, $suggested) || $suggested[$f] === null || $suggested[$f] === '') {
                continue;
            }
            $want = self::normField($f, $suggested[$f]);
            $got  = self::normField($f, $final[$f] ?? null);
            if ($got === '') {
                continue;
            }
            $fb[$f] = $fb[$f] ?? ['hit' => 0, 'miss' => 0];
            if ($want === $got) {
                $fb[$f]['hit']++;
            } else {
                $fb[$f]['miss']++;
            }
            $touched = true;
        }
        if ($touched) {
            $fb['updatedAt'] = time();
            self::kvSet(self::KV_FEEDBACK, $fb);
        }
    }

    /**
     * Hit-rate per tracked field, and whether that field is currently
     * trusted enough to be auto-applied.
     *
     * @return array<string, array{hit: int, miss: int, rate: ?float, trusted: bool}>
     */
    public static function accuracy(): array
    {
        $fb  = self::kvGet(self::KV_FEEDBACK);
        $out = [];
        foreach (self::TRACKED as $f) {
            $hit  = (int) ($fb[$f]['hit'] ?? 0);
            $miss = (int) ($fb[$f]['miss'] ?? 0);
            $n    = $hit + $miss;
            $rate = $n > 0 ? round($hit / $n, 2) : null;
            $out[$f] = [
                'hit'     => $hit,
                'miss'    => $miss,
                'rate'    => $rate,
                'trusted' => $n < self::MIN_SAMPLES || ($rate !== null && $rate >= self::MIN_HIT_RATE),
            ];
        }

        return $out;
    }

    /* =================================================================
     *  Free-text intent
     * ================================================================= */

    /**
     * One typed line → the fields it carries. Rule-based, bilingual
     * (English + romanised Hindi / Nepali), and conservative: anything it
     * does not recognise stays in `residual` and becomes the name.
     *
     *   "Ram Bahadur 9876543210 2 seats mehsana kal female"
     *     → name Ram Bahadur · phone 9876543210 · seats 2 · boarding Mehsana
     *       · date tomorrow · gender Female
     *
     * @return array<string, mixed>
     */
    public static function parse(string $text): array
    {
        $raw = trim((string) preg_replace('/\s+/u', ' ', $text));
        $out = ['name' => '', 'phone' => '', 'country' => '', 'seats' => 0, 'date' => '', 'direction' => '',
                'boarding' => '', 'gender' => '', 'pay' => '', 'agentCode' => '', 'found' => [], 'residual' => '', 'lang' => 'en'];
        if ($raw === '') {
            return $out;
        }
        /* SHG AI BRAIN Phase 2 (10 Sep 2026): the writer's language, read off
           the script and a few tell-tale words — it only ever chooses the
           language of a clarifying question, never a booking fact. Devanagari
           (०-९) and Gujarati (૦-૯) digits become ASCII first, so a mobile or a
           seat count typed on a Hindi / Gujarati keyboard reads like any other. */
        $out['lang'] = self::detectLang($raw);
        // Precomposed nukta letters (U+0958-U+095F, what some keyboards emit) become base + U+093C,
        // the form every alias and filler word below is written in; otherwise "बड़ौदा" never matched.
        $work = ' ' . strtr(self::asciiDigits($raw), [
            "\u{0958}" => "\u{0915}\u{093C}", "\u{0959}" => "\u{0916}\u{093C}", "\u{095A}" => "\u{0917}\u{093C}", "\u{095B}" => "\u{091C}\u{093C}",
            "\u{095C}" => "\u{0921}\u{093C}", "\u{095D}" => "\u{0922}\u{093C}", "\u{095E}" => "\u{092B}\u{093C}", "\u{095F}" => "\u{092F}\u{093C}",
        ]) . ' ';

        /* agent code — "SHG-027", "shg 27", "agent 27": taken out FIRST so its
           digits can never be mistaken for part of the mobile number. The
           booking engine resolves it (AgentWallet::resolveAgentCodeFromString);
           an unknown code never blocks a sale. */
        // (?!\s*-\s*\d) keeps a PNR out of this: bookings are numbered
        // SHG-2026-00123, so without it "SHG-2026-00123 ko ticket" matched
        // "SHG-2026" and fabricated agent code SHG-2026 — and pasting a PNR
        // into the smart line ("same as SHG-2026-00123, 2 more seats") is a
        // natural thing for a desk to do.
        if (preg_match('/(?<![\p{L}\d])(?:shg|agent(?:\s*code)?)\s*[-:#]?\s*0*(\d{1,4})(?!\d)(?!\s*-\s*\d)/iu', $work, $ac)) {
            $out['agentCode'] = 'SHG-' . str_pad($ac[1], 4, '0', STR_PAD_LEFT);
            $out['found'][]   = 'agentCode';
            $pos  = strpos($work, $ac[0]);
            $work = $pos === false ? $work : substr_replace($work, ' ', $pos, strlen($ac[0]));
        }

        /* phone: a run of digits (spaces / dashes allowed inside — "98765 43210",
           "+977 9812345678") that strips to 10–13 digits. A run that is too long
           as a whole ("9876543210 2" = number + seat count) is retried piece by
           piece and as pairs of neighbouring pieces, so the seat count survives. */
        $best = '';
        if (preg_match_all('/\+?[\d][\d\s\-()]*\d/u', $work, $m)) {
            $valid = static function (string $cand): bool {
                $d = preg_replace('/\D/', '', $cand) ?? '';
                $n = strlen($d);
                return $n === 10 || ($n === 12 && str_starts_with($d, '91')) || ($n === 13 && str_starts_with($d, '977')) || $n === 11 && str_starts_with($d, '0');
            };
            /* A run whose LAST space-separated piece is only 1-2 digits has a
               seat count or a day glued onto it — "987654321 2 seats" is one
               run that strips to a perfectly valid 10 digits. Accepting it
               whole is how a 9-digit typo became 9876543212: somebody else's
               live mobile, and the ticket and WhatsApp went there. Such a run
               falls through to the piece logic below, which refuses to join a
               short tail. */
            $gluedTail = static function (string $run): bool {
                $pieces = preg_split('/\s+/', trim($run)) ?: [];
                if (count($pieces) < 2) {
                    return false;
                }
                $last = preg_replace('/\D/', '', (string) end($pieces)) ?? '';
                return $last !== '' && strlen($last) <= 2;
            };
            foreach ($m[0] as $run) {
                $run = trim($run);
                if ($valid($run) && !$gluedTail($run)) {
                    $best = $run;
                    break;
                }
                $pieces = preg_split('/\s+/', $run) ?: [];
                $np = count($pieces);
                for ($i = 0; $i < $np && $best === ''; $i++) {
                    /* Joining two neighbouring pieces is for a number typed
                       with a space ("98765 43210", "+977 9812345678"), NOT for
                       gluing a seat count or a day onto a short number. Without
                       the >= 3 digit floor on the SECOND piece, "Ram 987654321
                       2 seats" — a 9-digit typo followed by the party size —
                       was silently repaired into 9876543212: a valid 10-digit
                       mobile belonging to somebody else, which is where the
                       ticket PNG and the WhatsApp then went. A wrong number is
                       far worse than no number, so a short piece is refused and
                       the desk is asked for the mobile instead. */
                    $nextLen = $i + 1 < $np ? strlen(preg_replace('/\D/', '', $pieces[$i + 1]) ?? '') : 0;
                    if ($i + 1 < $np && $nextLen >= 3 && $valid($pieces[$i] . ' ' . $pieces[$i + 1])) {
                        $best = $pieces[$i] . ' ' . $pieces[$i + 1];
                    } elseif ($valid($pieces[$i])) {
                        $best = $pieces[$i];
                    }
                }
                if ($best !== '') {
                    break;
                }
            }
        }
        if ($best !== '') {
            $d = preg_replace('/\D/', '', $best) ?? '';
            $out['phone']   = normalisePhone($best);
            $out['country'] = (strlen($d) === 13 && str_starts_with($d, '977')) ? 'NP' : ((strlen($d) === 12 && str_starts_with($d, '91')) ? 'IN' : '');
            $out['found'][] = 'phone';
            $pos  = strpos($work, $best);
            $work = $pos === false ? $work : substr_replace($work, ' ', $pos, strlen($best));
        }
        $lower = mb_strtolower($work);

        /* date — before the seat pass, so "12/9" is a date and never "12 seats" */
        $today = todayISO();
        $date  = '';
        $dateTokens = [
            'day after tomorrow' => 2, 'parsi' => 2, 'parso' => 2, 'porsi' => 2,
            // Devanagari (Hindi / Nepali) and Gujarati — two-day words first so "परसों" never reads as "कल"
            'परसों' => 2, 'परसो' => 2, 'पर्सी' => 2, 'पर्सि' => 2, 'પરમ દિવસે' => 2, 'પરમદિવસે' => 2, 'પરમ દિવસ' => 2,
            'tomorrow' => 1, 'tmrw' => 1, 'tmr' => 1, 'kal' => 1, 'bholi' => 1, 'bhole' => 1,
            'भोलि' => 1, 'भोली' => 1, 'कल' => 1, 'કાલે' => 1, 'કાલ' => 1,
            'आज' => 0, 'आजै' => 0, 'अभी' => 0, 'अहिले' => 0, 'આજે' => 0, 'આજ' => 0, 'અત્યારે' => 0, 'હમણાં' => 0,
            'today' => 0, 'aaj' => 0, 'aja' => 0, 'ajai' => 0, 'aj' => 0, 'tonight' => 0, 'now' => 0, 'abhi' => 0, 'ahile' => 0,
        ];
        /* \b is ASCII-only in PCRE — it is defined off \w, and the /u flag
           alone does not switch that to Unicode — so a Devanagari date word
           never matched a \b-anchored token: it failed to set the date AND
           survived into the passenger's name. Letter lookarounds are the
           Unicode-safe equivalent and behave identically for the ASCII words. */
        foreach ($dateTokens as $tok => $plus) {
            $re = '/(?<![\p{L}\p{M}])' . preg_quote($tok, '/') . '' . self::NB . '/u';
            if (preg_match($re, $lower)) {
                $date  = addDaysISO($today, $plus);
                $lower = (string) preg_replace($re, ' ', $lower);
                break;
            }
        }
        if ($date === '' && preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $lower, $m) && Security::isValidDate($m[0])) {
            $date  = $m[0];
            $lower = str_replace($m[0], ' ', $lower);
        }
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        // Whole month words only — "jane" (going) must never read as "jan".
        $monthRe = '(january|jan|february|feb|march|mar|april|apr|may|june|jun|july|jul|august|aug|september|sept|sep|october|oct|november|nov|december|dec)';
        // Both orders must tolerate the ordinal suffix. "20th nov" always did;
        // "nov 20th" did NOT — the trailing \b fell between "0" and "t", so the
        // whole month rule failed and the day-only rule below then read "20th"
        // against the CURRENT month. That silently booked the wrong month.
        if ($date === '' && (preg_match('/\b(\d{1,2})\s*(?:st|nd|rd|th)?\s*' . $monthRe . '\b/u', $lower, $m)
                             || preg_match('/\b' . $monthRe . '\s*(\d{1,2})(?:\s*(?:st|nd|rd|th))?\b/u', $lower, $m))) {
            $dNum = ctype_digit($m[1]) ? (int) $m[1] : (int) $m[2];
            $mon  = $months[substr(ctype_digit($m[1]) ? $m[2] : $m[1], 0, 3)];
            $date = self::nextDate($dNum, $mon, $today);
            $lower = str_replace($m[0], ' ', $lower);
        }
        if ($date === '' && preg_match('/\b(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{2,4}))?\b/u', $lower, $m)) {
            $dNum = (int) $m[1]; $mon = (int) $m[2];
            $yr = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
            if ($yr > 0 && $yr < 100) { $yr += 2000; }
            if ($mon >= 1 && $mon <= 12 && $dNum >= 1 && $dNum <= 31) {
                $date = $yr > 0 ? sprintf('%04d-%02d-%02d', $yr, $mon, $dNum) : self::nextDate($dNum, $mon, $today);
                if (!Security::isValidDate($date)) { $date = ''; }
                $lower = str_replace($m[0], ' ', $lower);
            }
        }
        /* a bare day of the month — "15th", "on the 3rd", "15 tarikh", "20 gate".
           Only ever read when the day carries an ordinal suffix or a date word,
           so it cannot swallow a seat count: nobody asks for "15th seats". A
           day that has already passed this month means next month.

           Two things this must NOT do, both of which produce a confidently
           WRONG date (worse than no date, because it silences the "confirm the
           date" flag downstream):
             · claim a day when a month word is still in the text — that means
               the month rules above failed to pair them, and guessing the
               current month would move the trip by months;
             · read a POSITION as a date — "2nd bus", "3rd seat", "seat no 12th"
               are a bus, a seat and a seat, not the 2nd/3rd/12th of a month. */
        $hasMonthWord = preg_match('/\b' . $monthRe . '\b/u', $lower) === 1;
        if ($date === '' && !$hasMonthWord
            && (preg_match('/\b(\d{1,2})\s*(?:st|nd|rd|th)\b/u', $lower, $m, PREG_OFFSET_CAPTURE)
                || preg_match('/\b(\d{1,2})\s*(?:tarikh|tarik|tareekh|tareek|gate|gatey|gte|तारीख|तारिख|तारीक|गते|તારીખ)' . self::NB . '/u', $lower, $m, PREG_OFFSET_CAPTURE))) {
            $whole  = (string) $m[0][0];
            $at     = (int) $m[0][1];
            $dNum   = (int) $m[1][0];
            $before = mb_strtolower(substr($lower, max(0, $at - 18), min(18, $at)));
            $after  = substr($lower, $at + strlen($whole), 12);
            $noun   = '(?:bus|buses|seat|seats|sit|sits|berth|berths|row|rows|deck|floor|class|time|trip|coach)';
            $positional = preg_match('/' . $noun . '\s*(?:no|number|num|#)?\s*$/u', $before) === 1
                       || preg_match('/\b(?:no|number|num|seat|bus|berth)\s*$/u', $before) === 1
                       || preg_match('/^\s*' . $noun . '\b/u', $after) === 1;
            if ($dNum >= 1 && $dNum <= 31 && !$positional) {
                $date  = self::nextDayOfMonth($dNum, $today);
                $lower = $date === '' ? $lower : str_replace($whole, ' ', $lower);
            }
        }
        /* weekday names — English, romanised Hindi / Nepali, and (SHG AI BRAIN
           Phase 2, 10 Sep 2026) the Devanagari and Gujarati spellings. Matched
           with letter / mark lookarounds, never \b (ASCII-only in PCRE). */
        $days = [
            'sunday' => 0, 'sun' => 0, 'ravivar' => 0, 'raviwar' => 0, 'aitabar' => 0, 'aitawar' => 0, 'itwar' => 0,
            'रविवार' => 0, 'आइतबार' => 0, 'आइतवार' => 0, 'इतवार' => 0, 'રવિવાર' => 0,
            'monday' => 1, 'mon' => 1, 'somvar' => 1, 'sombar' => 1, 'somwar' => 1,
            'सोमवार' => 1, 'सोमबार' => 1, 'સોમવાર' => 1,
            'tuesday' => 2, 'tue' => 2, 'tues' => 2, 'mangalvar' => 2, 'mangalbar' => 2, 'mangal' => 2,
            'मंगलवार' => 2, 'मङ्गलबार' => 2, 'मंगलबार' => 2, 'મંગળવાર' => 2,
            'wednesday' => 3, 'wed' => 3, 'budhvar' => 3, 'budhbar' => 3, 'budh' => 3,
            'बुधवार' => 3, 'बुधबार' => 3, 'બુધવાર' => 3,
            'thursday' => 4, 'thu' => 4, 'thurs' => 4, 'guruvar' => 4, 'bihibar' => 4, 'brihaspatibar' => 4,
            'गुरुवार' => 4, 'बृहस्पतिवार' => 4, 'बिहीबार' => 4, 'बिहिबार' => 4, 'ગુરુવાર' => 4,
            'friday' => 5, 'fri' => 5, 'shukravar' => 5, 'shukrabar' => 5, 'sukrabar' => 5,
            'शुक्रवार' => 5, 'शुक्रबार' => 5, 'શુક્રવાર' => 5,
            'saturday' => 6, 'sat' => 6, 'shanivar' => 6, 'shanibar' => 6, 'sanibar' => 6,
            'शनिवार' => 6, 'शनिबार' => 6, 'શનિવાર' => 6,
        ];
        if ($date === '') {
            foreach ($days as $tok => $w) {
                $re = '/(?<![\p{L}\p{M}])' . preg_quote($tok, '/') . '' . self::NB . '/u';
                if (preg_match($re, $lower)) {
                    $cur  = (int) date('w', strtotime($today));
                    $plus = ($w - $cur + 7) % 7;
                    $date = addDaysISO($today, $plus);
                    $lower = (string) preg_replace($re, ' ', $lower);
                    break;
                }
            }
        }
        if ($date !== '') {
            $out['date'] = $date;
            $out['found'][] = 'date';
        }

        /* Strip EVERY remaining date word, not only the one that won. Just the
           first match was removed, so a second survived into the residual and
           became part of the name: "Ram 9876543210 kal parso 2 seats" printed
           "Ram Kal" on the ticket, and "today tomorrow" gave "Ram Today".
           These words are never a passenger's name, whether or not they set
           the date. */
        foreach (array_keys($dateTokens) as $tok) {
            $lower = (string) preg_replace('/(?<![\p{L}\p{M}])' . preg_quote($tok, '/') . '' . self::NB . '/u', ' ', $lower);
        }
        foreach (array_keys($days) as $tok) {
            $lower = (string) preg_replace('/(?<![\p{L}\p{M}])' . preg_quote($tok, '/') . '' . self::NB . '/u', ' ', $lower);
        }

        /* seats — a number next to a seat / person word, "x3", "for 3", or a
           number word ("dui jana"). "jane"/"jana" alone mean "going", so only
           the counted form "2 jana" is a party size. */
        $numWords = ['one' => 1, 'ek' => 1, 'euta' => 1, 'two' => 2, 'do' => 2, 'dui' => 2, 'three' => 3, 'teen' => 3, 'tin' => 3,
                     'four' => 4, 'char' => 4, 'chaar' => 4, 'five' => 5, 'panch' => 5, 'paanch' => 5, 'pach' => 5, 'six' => 6, 'chha' => 6, 'che' => 6,
                     // Devanagari (Hindi / Nepali) and Gujarati number words — only ever a COUNT
                     // when a seat word follows ("दुई जना", "બે ટિકિટ"), so "छ" (is) alone never counts.
                     'एक' => 1, 'दो' => 2, 'दुई' => 2, 'तीन' => 3, 'चार' => 4, 'पाँच' => 5, 'पांच' => 5, 'छह' => 6, 'छवटा' => 6,
                     'એક' => 1, 'બે' => 2, 'ત્રણ' => 3, 'ચાર' => 4, 'પાંચ' => 5, 'છ' => 6];
        $seatWord = '(?:seats?|sits?|sit|berths?|jana|log|pax|people|persons?|passengers?|tickets?|tkts?|tkt'
                  . '|सिट|सिटहरू|सीट|सीटें|सीटे|टिकट|टिकिट|यात्री|यात्रियों|लोग|लोगों|जन|जना|वटा|व्यक्ति|सवारी'
                  . '|સીટ|સીટો|ટિકિટ|ટીકીટ|જણ|જણા|મુસાફર|મુસાફરો|વ્યક્તિ|લોકો|પેસેન્જર)';
        $numAlt   = implode('|', array_map(static fn(string $w): string => preg_quote($w, '/'), array_keys($numWords)));
        $nb       = self::NB;   // Unicode-safe "end of word" — \b is ASCII-only and fails after Devanagari
        if (preg_match('/\b(\d{1,2})\s*' . $seatWord . $nb . '/u', $lower, $m)
            /* Noun-then-number is only a COUNT when the noun is plural
               ("seats 3") or carries a separator ("seats: 3"). A SINGULAR noun
               followed by a number is a POSITION — "seat 12", "berth 15",
               "tkt 12" — and reading those as a party of 12 or 15 tried to
               sell a dozen berths to one walk-in. The date block already
               refuses positions this way; the seat pass did not. */
            || preg_match('/\b(?:seats|sits|berths|pax|persons|passengers|tickets|tkts)\s*[:=]?\s*(\d{1,2})\b/u', $lower, $m)
            || preg_match('/\b(?:seats?|sits?|sit|berths?|pax|persons?|passengers?|tickets?|tkts?|tkt)\s*[:=]\s*(\d{1,2})\b/u', $lower, $m)
            || preg_match('/\bx\s*(\d{1,2})\b/u', $lower, $m)
            || preg_match('/\bfor\s+(\d{1,2})\b/u', $lower, $m)) {
            $out['seats'] = max(1, min(20, (int) $m[1]));
            $out['found'][] = 'seats';
            $lower = str_replace($m[0], ' ', $lower);
        } elseif (preg_match('/(?<![\p{L}\p{M}])(' . $numAlt . ')\s+' . $seatWord . $nb . '/u', $lower, $m)
                  // Hindi imperatives "कर दो / दे दो / भेज दो टिकट": that "दो" is "give", not two (reviewed 10 Sep 2026)
                  && !($m[1] === 'दो' && preg_match('/(?:कर|दे|भेज|बना|करा|दिला|लगा)\s+दो\s/u', $lower) === 1)) {
            $out['seats'] = $numWords[$m[1]];
            $out['found'][] = 'seats';
            $lower = str_replace($m[0], ' ', $lower);
        }

        /* payment received how */
        $pays = ['cash' => 'cash', 'nagad' => 'cash', 'nakad' => 'cash', 'upi' => 'upi', 'gpay' => 'upi', 'phonepe' => 'upi', 'paytm' => 'upi',
                 'esewa' => 'esewa', 'khalti' => 'esewa', 'bank' => 'bank', 'neft' => 'bank', 'imps' => 'bank', 'transfer' => 'bank',
                 // Devanagari / Gujarati (longer phrases before their head word)
                 'बैंक ट्रांसफर' => 'bank', 'गूगल पे' => 'upi', 'नगद' => 'cash', 'नकद' => 'cash', 'रोकड़' => 'cash', 'रोकड' => 'cash', 'कैश' => 'cash', 'क्यास' => 'cash', 'રોકડ' => 'cash', 'કેશ' => 'cash',
                 'यूपीआई' => 'upi', 'युपिआई' => 'upi', 'યુપીઆઈ' => 'upi', 'फोनपे' => 'upi', 'इसेवा' => 'esewa', 'ई-सेवा' => 'esewa', 'खल्ती' => 'esewa', 'बैंक' => 'bank', 'બેંક' => 'bank'];
        foreach ($pays as $tok => $pay) {
            $re = '/(?<![\p{L}\p{M}])' . preg_quote($tok, '/') . '' . self::NB . '/u';
            if (preg_match($re, $lower)) {
                $out['pay'] = $pay; $out['found'][] = 'pay';
                $lower = (string) preg_replace($re, ' ', $lower);
                break;
            }
        }

        /* gender — explicit words only, never inferred from a name */
        // "Devi", "Kumari", "Shri" are parts of real names — never treated as gender words.
        /* VOCATIVES ARE NOT GENDER. "bhai", "dai", "dada", "didi", "bahini",
           "aunty", "uncle" and "sir" are how somebody addresses the desk, not
           a statement about the passenger — "2 seat chahiye bhai" means "mate,
           I need 2 seats", and it was recording the passenger as Male. The
           same words are already listed as meaningless filler twenty lines
           below, so the function contradicted itself and the gender pass ran
           first. A wrong gender is not cosmetic: it decides women-only berths
           and the shared-cabin lock. Titles that genuinely do declare a gender
           (mr / mrs / ms) stay. */
        $female = ['female', 'f', 'woman', 'lady', 'ladies', 'mahila', 'aurat', 'mrs', 'ms', 'girl',
                   'महिला', 'औरत', 'स्त्री', 'लेडी', 'श्रीमती', 'મહિલા', 'સ્ત્રી', 'શ્રીમતી'];
        $male   = ['male', 'm', 'man', 'gent', 'gents', 'purush', 'aadmi', 'admi', 'mr', 'boy',
                   'पुरुष', 'आदमी', 'मर्द', 'श्रीमान', 'પુરુષ', 'શ્રીમાન'];
        foreach ([['Female', $female], ['Male', $male]] as [$g, $list]) {
            foreach ($list as $tok) {
                if (preg_match('/(?<![\p{L}\p{M}])' . preg_quote($tok, '/') . '' . self::NB . '/u', $lower)) {
                    $out['gender'] = $g; $out['found'][] = 'gender';
                    $lower = (string) preg_replace('/(?<![\p{L}\p{M}])' . preg_quote($tok, '/') . '' . self::NB . '/u', ' ', $lower, 1);
                    break 2;
                }
            }
        }

        /* direction words (destination phrasing) */
        // Destination phrasing only. A bare town word is a PICKUP (matched
        // below), so "surat" alone never flips the direction; "to surat" does.
        /* Direction (reviewed 10 Sep 2026) — an explicit "A से/बाट/देखि/થી/from B"
           first (B is the destination), then three tiers: destination PHRASES
           ("to nepal", "नेपाल जाने", "भारत वापस") beat bare RETURN words (back,
           wapas, वापस, फर्कने, પાછા), which beat a bare COUNTRY word — so "nepal se
           back" is a return and "back to nepal" is not. Nepal-side TOWNS
           (rupaidiha, nepalganj, kathmandu, in any script) are PICKUPS for the
           boarding pass below, never a direction by themselves. */
        $phrasesNepal = ['to nepal', 'nepal jane', 'nepal jana', 'nepal tak', 'nepal samma', 'nepal side', 'to rupaidiha', 'rupaidiha jane', 'rupaidiha jana', 'to nepalgunj', 'nepalgunj jane', 'nepalganj jane', 'to kathmandu', 'kathmandu jane',
                         'नेपाल जाना है', 'नेपाल जाना', 'नेपाल जाने', 'नेपाल जानु', 'नेपाल तर्फ', 'नेपाल तिर', 'नेपालगंज जाने', 'रुपैडिया जाने', 'काठमाडौं जाने',
                         'નેપાળ જવું', 'નેપાળ જવાનું', 'નેપાળ જવા', 'નેપાળગંજ જવું'];
        $phrasesIndia = ['to india', 'india jane', 'india jana', 'to gujarat', 'gujarat jane', 'to surat', 'surat jane', 'surat jana', 'to ahmedabad', 'ahmedabad jane', 'to mehsana', 'mehsana jane',
                         'भारत जाना', 'भारत जाने', 'भारत वापस', 'भारत फर्कने', 'गुजरात जाना', 'गुजरात जाने', 'सूरत जाना', 'सूरत जाने', 'सुरत जाने', 'सुरत जाना', 'अहमदाबाद जाना', 'अहमदाबाद जाने', 'मेहसाणा जाना',
                         'ભારત જવું', 'ભારત પાછા', 'ગુજરાત જવું', 'સુરત જવું', 'સુરત જવાનું'];
        $returnWords  = ['return', 'returning', 'wapas', 'wapsi', 'farkine', 'farkeko', 'farkanu', 'back',
                         'वापस', 'वापसी', 'फर्कने', 'फर्किने', 'फर्केर', 'फिर्ता', 'પાછા', 'પાછું', 'પરત'];
        $bareNepal    = ['nepal', 'nepalganj', 'kohalpur', 'kathmandu', 'नेपाल', 'નેપાળ', 'નેપાલ'];
        $bareIndia    = ['india', 'gujarat', 'भारत', 'इंडिया', 'इण्डिया', 'गुजरात', 'ભારત', 'ઈન્ડિયા', 'ગુજરાત'];
        $nepalTowns   = ['rupaidiha', 'rupaideha', 'nepalgunj', 'रुपैडिया', 'रूपैडिया', 'रुपैडिहा', 'नेपालगंज', 'नेपालगन्ज', 'काठमाडौं', 'काठमांडू', 'कोहलपुर', 'રૂપૈડિયા', 'નેપાળગંજ', 'કાઠમંડુ'];
        $indiaTowns   = ['surat', 'ahmedabad', 'amdavad', 'mehsana', 'baroda', 'vadodara', 'kamrej', 'ankleshwar', 'bharuch', 'nadiad', 'कामरेज', 'अंकलेश्वर', 'भरूच', 'भरुच', 'नडियाद', 'કામરેજ', 'અંકલેશ્વર', 'ભરૂચ', 'નડિયાદ', 'सूरत', 'सुरत', 'अहमदाबाद', 'अमदावाद', 'मेहसाणा', 'मेहसाना', 'बड़ौदा', 'बडौदा', 'वडोदरा', 'સુરત', 'અમદાવાદ', 'મહેસાણા', 'વડોદરા', 'બરોડા'];
        $sideOf = static function (string $w) use ($bareNepal, $bareIndia, $nepalTowns, $indiaTowns): string {
            if (in_array($w, $bareNepal, true) || in_array($w, $nepalTowns, true)) { return 'toNepal'; }
            if (in_array($w, $bareIndia, true) || in_array($w, $indiaTowns, true)) { return 'toIndia'; }
            return '';
        };
        $placeAlt = implode('|', array_map(static fn(string $w): string => preg_quote($w, '/'), array_merge($bareNepal, $bareIndia, $nepalTowns, $indiaTowns)));
        if (preg_match('/(?<![\p{L}\p{M}])(' . $placeAlt . ')\s+(?:se|bata|dekhi|from|से|बाट|देखि|થી)\s+(' . $placeAlt . ')' . self::NB . '/u', $lower, $ab)) {
            $side = $sideOf($ab[2]);
            if ($side !== '' && $side !== $sideOf($ab[1])) {
                $out['direction'] = $side; $out['found'][] = 'direction';
                // the origin TOWN stays for the boarding pass; a bare origin COUNTRY is never a name
                $keepOrigin = !in_array($ab[1], array_merge($bareNepal, $bareIndia), true);
                $lower = str_replace($ab[0], $keepOrigin ? ' ' . $ab[1] . ' ' : ' ', $lower);
            }
        }
        if ($out['direction'] === '') {
            $dirPhrases = [];
            foreach ($phrasesIndia as $tok) { $dirPhrases[] = [0, 'toIndia', $tok]; }
            foreach ($phrasesNepal as $tok) { $dirPhrases[] = [0, 'toNepal', $tok]; }
            foreach ($returnWords as $tok)  { $dirPhrases[] = [1, 'toIndia', $tok]; }
            foreach ($bareIndia as $tok)    { $dirPhrases[] = [2, 'toIndia', $tok]; }
            foreach ($bareNepal as $tok)    { $dirPhrases[] = [2, 'toNepal', $tok]; }
            usort($dirPhrases, static fn(array $a, array $b): int => [$a[0], mb_strlen($b[2])] <=> [$b[0], mb_strlen($a[2])]);
            foreach ($dirPhrases as [, $dir, $tok]) {
                $re = '/(?<![\p{L}\p{M}])' . preg_quote($tok, '/') . self::NB . '/u';
                if (preg_match($re, $lower)) {
                    $out['direction'] = $dir; $out['found'][] = 'direction';
                    $lower = (string) preg_replace($re, ' ', $lower, 1);
                    break;
                }
            }
        }
        // A bare country word is never a passenger's name — drop any that survived ("nepal se back").
        foreach (array_merge($bareNepal, $bareIndia) as $tok) {
            $lower = (string) preg_replace('/(?<![\p{L}\p{M}])' . preg_quote($tok, '/') . self::NB . '/u', ' ', $lower);
        }

        /* boarding — any pickup of an active route, by town words or aliases */
        foreach (self::stopCatalogue() as $stop) {
            foreach ($stop['aliases'] as $alias) {
                if ($alias !== '' && preg_match('/(?<![\p{L}\p{M}])' . preg_quote($alias, '/') . '' . self::NB . '/u', $lower)) {
                    $out['boarding'] = $stop['value']; $out['found'][] = 'boarding';
                    $lower = (string) preg_replace('/(?<![\p{L}\p{M}])' . preg_quote($alias, '/') . '' . self::NB . '/u', ' ', $lower, 1);
                    break 2;
                }
            }
        }

        /* name = what is left, minus filler */
        $filler = ['ticket', 'tickets', 'tkt', 'seat', 'seats', 'sit', 'book', 'booking', 'banau', 'banao', 'banaidinu', 'garnu', 'gara', 'garne', 'please', 'pls', 'plz',
                   'ko', 'ka', 'ki', 'lai', 'le', 'for', 'and', 'the', 'a', 'an', 'quick', 'bot', 'name', 'naam', 'mobile', 'phone', 'no', 'number', 'ph', 'mob',
                   // left behind by the seat / agent-code passes above — never a name
                   'berth', 'berths', 'sits', 'tkts', 'tickets', 'persons', 'passengers', 'shg', 'pnr', 'agent', 'code',
                   'सिट', 'सिटहरू', 'टिकट', 'चाहियो', 'नाम',
                   // SHG AI BRAIN Phase 2 (10 Sep 2026): Hindi / Nepali / Gujarati request words — never a passenger's name
                   'सीट', 'सीटें', 'टिकिट', 'चाहिए', 'चाहिये', 'चाहिन्छ', 'चाहिन्थ्यो', 'बुक', 'बुकिंग', 'बुकिङ', 'करना', 'करो', 'कर', 'करें', 'कीजिए', 'गर्नु', 'गर्नुहोस्', 'गरिदिनु', 'गरिदिनुस्', 'दिनुस्', 'दिनुहोस्', 'दे', 'दें',
                   'मुझे', 'हमें', 'मलाई', 'हामीलाई', 'हामी', 'हम', 'मैं', 'म', 'मेरा', 'मेरो', 'हमारा', 'हाम्रो', 'के', 'लिए', 'का', 'की', 'को', 'से', 'तक', 'में', 'पर', 'और', 'या', 'है', 'हैं', 'हो', 'छ', 'छन्', 'बाट', 'मा', 'लागि', 'देखि', 'सम्म', 'तिर', 'तर्फ',
                   'बस', 'गाड़ी', 'गाडी', 'यात्रा', 'जाना', 'जाने', 'जानु', 'जान', 'चढ्ने', 'चढ़ना', 'चढ़ने', 'पिकअप', 'बोर्डिंग', 'बोर्डिङ',
                   'भाई', 'दाई', 'दिदी', 'दीदी', 'बहिनी', 'जी', 'सर', 'मैडम', 'मेडम', 'नमस्ते', 'नमस्कार', 'हेलो', 'हैलो', 'ओके', 'ठीक', 'ठिक', 'कृपया', 'प्लीज', 'प्लिज', 'कितना', 'कति', 'कितने', 'क्या',
                   'ટિકિટ', 'ટીકીટ', 'સીટ', 'સીટો', 'બુક', 'બુકિંગ', 'કરો', 'કરવી', 'કરવું', 'કરી', 'આપો', 'જોઈએ', 'જોઇએ', 'છે', 'છું', 'મને', 'અમને', 'મારે', 'અમારે', 'મારું', 'અમારું', 'માટે', 'થી', 'સુધી', 'માં', 'ને', 'નું', 'ની', 'નો', 'અને', 'કે', 'બસ', 'ગાડી', 'મુસાફરી', 'જવું', 'જવાનું', 'જવા', 'ચઢવું', 'ચડવું', 'પિકઅપ',
                   'ભાઈ', 'બેન', 'બહેન', 'સાહેબ', 'મેડમ', 'નમસ્તે', 'હેલો', 'ઓકે', 'બરાબર', 'કૃપા', 'પ્લીઝ', 'કેટલા', 'કેટલી', 'શું',
                   // reviewed 10 Sep 2026: verbs and return words that survive the passes above are never a name
                   'दो', 'भेज', 'भेजो', 'भेजना', 'भेजिए', 'दिनुस', 'दिनु', 'आना', 'आउने', 'आउनु', 'जाऊँगा', 'जाऊंगा', 'जान्छु', 'जान्छौं', 'वापस', 'वापसी', 'फर्कने', 'फर्किने', 'फर्केर', 'फिर्ता',
                   'wapas', 'wapsi', 'farkine', 'farkeko', 'farkanu', 'return', 'returning', 'back', 'પાછા', 'પાછું', 'પરત', 'આવવું', 'આવું',
                   'from', 'at', 'in', 'on', 'bus', 'is', 'ho', 'hai', 'ma', 'me', 'se', 'dekhi', 'bata', 'boarding', 'pickup', 'pick', 'up', 'chadne', 'chadhne', 'passenger', 'pax', 'jana', 'jan', 'log', 'x',
                   // "want / available / is there" and the usual openings. A
                   // message like "2 seat chahiye bhai" used to name the
                   // passenger "Chahiye" on the desk card.
                   'chahiye', 'chaiye', 'chahie', 'chaiyo', 'chahincha', 'chahinchha', 'milcha', 'milxa', 'milega', 'milega?', 'khali',
                   'cha', 'chha', 'xa', 'hola', 'hola?', 'kati', 'kina', 'namaste', 'namaskar', 'hello', 'helo', 'hi', 'hey',
                   'bhai', 'dai', 'didi', 'sir', 'madam', 'maam', 'ji', 'ok', 'okay', 'thik', 'theek'];
        /* \p{M} keeps a combining mark WITH its letter. Devanagari vowel signs
           (matras) are category Mn and match neither \p{L} nor \p{N}, so every
           matra was treated as a word break: "राम बहादुर" came out as
           "र म बह द र", and that is what would have been printed on the
           ticket and sent on WhatsApp. */
        $tokens = preg_split('/[^\p{L}\p{N}\p{M}\'.]+/u', $lower) ?: [];
        $keep   = [];
        foreach ($tokens as $tk) {
            $tk = trim($tk, ".'");
            // "2nd", "3rd", "12th" — an ordinal left behind by a position
            // ("2nd bus") is never a passenger's name.
            if ($tk === '' || in_array($tk, $filler, true) || ctype_digit($tk)
                || preg_match('/^\d{1,2}(?:st|nd|rd|th)$/u', $tk) === 1) {
                continue;
            }
            $keep[] = $tk;
        }
        $keep = array_slice($keep, 0, 5);
        $out['residual'] = implode(' ', $keep);
        if ($keep !== []) {
            $out['name'] = implode(' ', array_map(static fn(string $w): string => mb_convert_case($w, MB_CASE_TITLE, 'UTF-8'), $keep));
            $out['found'][] = 'name';
        }

        return $out;
    }

    /**
     * booking_seats rows expanded to PHYSICAL (sharing) beds: a private cabin is
     * stored under its OWN label (L2 = beds L3 + L4, Seats::physicalSeats), and
     * QuickTicket sells a sleeper as sharing — so the "usual berths" a returning
     * passenger is offered must be beds, never a cabin label (reviewed 10 Sep 2026).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function physicalSeatRows(array $rows): array
    {
        if (!class_exists('Seats')) {
            require_once INCLUDE_PATH . '/seats.php';
        }
        $out = [];
        foreach ($rows as $r) {
            $seat = strtoupper((string) $r['seat_no']);
            if ((string) ($r['booking_mode'] ?? 'sharing') === 'private' && preg_match('/^[LU]\d+$/', $seat) === 1) {
                foreach (Seats::physicalSeats($seat, 'private', 'sleeper') as $bed) {
                    $out[] = ['booking_id' => $r['booking_id'], 'seat_no' => $bed];
                }
            } else {
                $out[] = ['booking_id' => $r['booking_id'], 'seat_no' => $seat];
            }
        }
        return $out;
    }

    /* =================================================================
     *  Scripts & languages (SHG AI BRAIN Phase 2, 10 Sep 2026)
     * ================================================================= */

    /** Devanagari (०-९) and Gujarati (૦-૯) digits as ASCII; everything else untouched. */
    public static function asciiDigits(string $text): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'] as $i => $d) { $map[$d] = (string) $i; }
            foreach (['૦', '૧', '૨', '૩', '૪', '૫', '૬', '૭', '૮', '૯'] as $i => $d) { $map[$d] = (string) $i; }
        }
        return strtr($text, $map);
    }

    /**
     * Which language the writer used — from the SCRIPT first (Gujarati,
     * Devanagari), then from tell-tale romanised words, scored. 'ne' wins a
     * Devanagari tie because most passengers are Nepali. Only ever used to
     * choose the language of a clarifying question and a card label — never
     * to change a booking fact.
     *
     * @return string 'en' | 'hi' | 'ne' | 'gu'
     */
    public static function detectLang(string $text): string
    {
        if (preg_match('/\p{Gujarati}/u', $text) === 1) {
            return 'gu';
        }
        $score = static function (array $words, string $s): int {
            $n = 0;
            foreach ($words as $w) {
                if (preg_match('/(?<![\p{L}\p{M}])' . preg_quote($w, '/') . '' . self::NB . '/u', $s) === 1) {
                    $n++;
                }
            }
            return $n;
        };
        if (preg_match('/\p{Devanagari}/u', $text) === 1) {
            $hi = $score(['चाहिए', 'चाहिये', 'है', 'हैं', 'हूँ', 'हूं', 'मुझे', 'हमें', 'कितने', 'कितना', 'कौन', 'जाना', 'वापस', 'कल', 'परसों', 'लिए', 'में', 'करना', 'करो', 'कीजिए', 'लोग', 'सीट'], $text);
            $ne = $score(['चाहियो', 'चाहिन्छ', 'छ', 'छन्', 'हो', 'जना', 'भोलि', 'पर्सि', 'मलाई', 'हामीलाई', 'कति', 'जाने', 'फर्कने', 'गर्नु', 'गर्नुहोस्', 'दिनुस्', 'बाट', 'लागि', 'सम्म', 'तिर', 'हरू', 'सिट'], $text);
            return ($hi > $ne) ? 'hi' : 'ne';
        }
        $l  = mb_strtolower($text);
        $gu = $score(['joie', 'joiye', 'joiee', 'chhe', 'jovu', 'javu', 'javanu', 'karvu', 'aavu', 'kaale', 'aaje', 'amne', 'ketla', 'ketli', 'thi', 'mate'], $l);
        /* 23 Sep 2026: + everyday Nepali-only words. "payment gare tara ticket
           aayena" and "Dashain ma ghar jana ticket milcha?" matched none and were
           answered in English. Words shared with Hindi (jana, jane, ho, ghar,
           kaha) are left out on purpose. */
        $ne = $score(['chahiyo', 'chaiyo', 'chahincha', 'chahinchha', 'chha', 'xa', 'hola', 'bholi', 'parsi', 'malai', 'hamilai', 'kati', 'garnu', 'garidinu', 'dinu', 'dinus', 'farkine', 'ahile', 'aaja', 'bata', 'lagi', 'samma', 'euta', 'dui', 'tin', 'huncha', 'hunchha',
                      'mero', 'hamro', 'cha', 'milcha', 'milchha', 'garna', 'garne', 'gare', 'tara', 'aayena', 'aaena', 'bhayo', 'hajur', 'kasari', 'aaune', 'parcha', 'parchha', 'sakchu', 'sakincha', 'pathaideu', 'dekhau'], $l);
        $hi = $score(['chahiye', 'chaiye', 'chahie', 'hai', 'hain', 'hoon', 'mujhe', 'humein', 'hume', 'kitne', 'kitna', 'jaana', 'wapas', 'wapsi', 'karo', 'karna', 'kijiye', 'banao', 'lena', 'liye', 'abhi', 'parso', 'log', 'hoga', 'milega'], $l);
        if ($gu > 0 && $gu > $hi && $gu > $ne) { return 'gu'; }   // Gujarati only when it wins outright — most passengers write Hindi / Nepali
        if ($ne > 0 && $ne >= $hi) { return 'ne'; }
        if ($hi > 0) { return 'hi'; }
        return 'en';
    }

    /**
     * The ONE clarifying question for a field, in the writer's language, with
     * tappable answers. Option values are exactly what the API accepts for
     * that field, so a tap is an explicit input on the re-plan.
     *
     * @return array{field:string, lang:string, question:string, options:array<int, array{value:string, label:string}>}|null
     */
    public static function question(string $field, string $lang, ?array $plan = null): ?array
    {
        $lang  = in_array($lang, ['en', 'hi', 'ne', 'gu'], true) ? $lang : 'en';
        $texts = [
            'date'      => ['en' => 'Which date do you want to travel?', 'hi' => 'किस तारीख को यात्रा करनी है?', 'ne' => 'कुन मिति जान चाहनुहुन्छ?', 'gu' => 'કઈ તારીખે મુસાફરી કરવી છે?'],
            'seats'     => ['en' => 'How many passengers?', 'hi' => 'कितने यात्री हैं?', 'ne' => 'कति जना यात्री छन्?', 'gu' => 'કેટલા મુસાફરો છે?'],
            'boarding'  => ['en' => 'Where will you board the bus?', 'hi' => 'बस कहाँ से चढ़ेंगे?', 'ne' => 'बस कहाँबाट चढ्नुहुन्छ?', 'gu' => 'બસમાં ક્યાંથી ચઢશો?'],
            'direction' => ['en' => 'Going to Nepal, or returning to India?', 'hi' => 'नेपाल जाना है या भारत वापस?', 'ne' => 'नेपाल जाने कि भारत फर्कने?', 'gu' => 'નેપાળ જવું છે કે ભારત પાછા?'],
        ];
        if (!isset($texts[$field])) {
            return null;
        }
        $today = todayISO();
        $lbl = [
            'today'    => ['en' => 'Today', 'hi' => 'आज', 'ne' => 'आज', 'gu' => 'આજે'],
            'tomorrow' => ['en' => 'Tomorrow', 'hi' => 'कल', 'ne' => 'भोलि', 'gu' => 'કાલે'],
            'dayafter' => ['en' => 'Day after', 'hi' => 'परसों', 'ne' => 'पर्सि', 'gu' => 'પરમ દિવસે'],
            'other'    => ['en' => 'Another date', 'hi' => 'दूसरी तारीख', 'ne' => 'अर्को मिति', 'gu' => 'બીજી તારીખ'],
            'toNepal'  => ['en' => 'To Nepal', 'hi' => 'नेपाल', 'ne' => 'नेपाल जाने', 'gu' => 'નેપાળ'],
            'toIndia'  => ['en' => 'Back to India', 'hi' => 'भारत वापस', 'ne' => 'भारत फर्कने', 'gu' => 'ભારત પાછા'],
        ];
        $options = match ($field) {
            'date'      => [
                ['value' => $today, 'label' => $lbl['today'][$lang]],
                ['value' => addDaysISO($today, 1), 'label' => $lbl['tomorrow'][$lang]],
                ['value' => addDaysISO($today, 2), 'label' => $lbl['dayafter'][$lang]],
                ['value' => 'other', 'label' => $lbl['other'][$lang]],
            ],
            'seats'     => array_map(static fn(int $n): array => ['value' => (string) $n, 'label' => (string) $n], [1, 2, 3, 4]),
            'direction' => [['value' => 'toNepal', 'label' => $lbl['toNepal'][$lang]], ['value' => 'toIndia', 'label' => $lbl['toIndia'][$lang]]],
            default     => [],
        };
        if ($field === 'boarding') {
            foreach ((array) ($plan['stops'] ?? []) as $s) {
                if (!empty($s['ahead'])) {
                    $options[] = ['value' => (string) ($s['name'] ?? ''), 'label' => trim((string) ($s['code'] ?? '') . ' · ' . (string) ($s['short'] ?? ($s['name'] ?? '')))];
                }
            }
            if ($options === []) {
                foreach (self::stopCatalogue() as $st) {
                    $options[] = ['value' => $st['value'], 'label' => $st['code'] . ' · ' . $st['name']];
                }
            }
        }
        return ['field' => $field, 'lang' => $lang, 'question' => $texts[$field][$lang], 'options' => $options];
    }

    /* =================================================================
     *  Suggest — explicit input > passenger memory > desk patterns > auto
     * ================================================================= */

    /**
     * The bot's proposal for a sale. Runs QuickTicket::plan() on the merged
     * option set so the caller gets the live bus / seat / fare too.
     *
     * @param array<string, mixed> $input text?, name?, phone?, country?, seats?, date?, direction?, boarding?, gender?, pay?
     * @param array<string, mixed>|null $staff the signed-in admins row (null = customer)
     * @return array<string, mixed>
     */
    public static function suggest(array $input, ?array $staff = null): array
    {
        $t0     = microtime(true);
        $parsed = trim((string) ($input['text'] ?? '')) !== '' ? self::parse((string) $input['text']) : null;
        $pick   = static function (string $k) use ($input, $parsed): array {
            $v = $input[$k] ?? null;
            if ($v !== null && $v !== '' && $v !== 0) {
                return [$v, 'input'];
            }
            if ($parsed !== null && ($parsed[$k] ?? '') !== '' && ($parsed[$k] ?? 0) !== 0) {
                return [$parsed[$k], 'text'];
            }
            return [null, ''];
        };

        $isStaff  = $staff !== null;
        $staffId  = (int) ($staff['id'] ?? 0);
        $learn    = self::enabled();          // ai_bot_on OFF = no memory, no patterns; parsing + defaults still work
        [$phone]  = $pick('phone');
        $phone    = normalisePhone((string) ($phone ?? ''));
        /* Passenger memory is looked up by the SESSION's number for a
           customer (`sessionPhone`, set by the API from Auth::user()) — never
           a typed one, so a phone's habits are only ever shown to its owner,
           and the sale binds to that number anyway. The desk may look up any
           number it types. */
        $sessionPhone = normalisePhone((string) ($input['sessionPhone'] ?? ''));
        if (!$isStaff && $sessionPhone !== '') {
            $phone = $sessionPhone;
        }
        $memoryPhone = $isStaff ? $phone : $sessionPhone;
        $profile  = ($learn && $memoryPhone !== '') ? self::profile($memoryPhone) : null;
        $patterns = $learn ? self::patterns() : ['sample' => 0, 'repeat' => 0, 'windowDays' => 0, 'computedAt' => 0, 'stops' => [], 'stopByHour' => [], 'stopBySeller' => [], 'dirByHour' => []];
        $acc      = self::accuracy();
        $trust    = static fn(string $f): bool => $acc[$f]['trusted'] ?? true;
        $hour     = (string) ((int) date('G'));

        $fields  = [];
        $reasons = [];
        $set = static function (string $f, mixed $value, string $source, string $reason, float $conf, bool $check = false) use (&$fields, &$reasons): void {
            $fields[$f] = ['value' => $value, 'source' => $source, 'reason' => $reason, 'confidence' => round($conf, 2), 'check' => $check];
            if ($reason !== '' && $source !== 'input') {
                $reasons[] = $reason;
            }
        };

        /* name */
        [$v, $src] = $pick('name');
        if ($v !== null) {
            $set('name', Security::clean((string) $v, 120), $src, $src === 'text' ? 'Name read from your line.' : '', 1.0);
        } elseif ($profile !== null && $profile['name'] !== '') {
            $set('name', $profile['name'], 'history', 'Name from this number\'s last ticket (' . $profile['trips'] . ' verified trip' . ($profile['trips'] > 1 ? 's' : '') . ').', min(1.0, 0.6 + 0.1 * $profile['nameN']), !$trust('name'));
        } else {
            $set('name', '', 'none', '', 0.0);
        }

        /* gender — never inferred from a name */
        [$v, $src] = $pick('gender');
        if ($v !== null && in_array($v, ['Male', 'Female', 'Other'], true)) {
            $set('gender', $v, $src, $src === 'text' ? 'Gender word found in your line.' : '', 1.0);
        } elseif ($isStaff && $profile !== null && $profile['gender'] !== null && $profile['genderShare'] >= 0.6 && $trust('gender')) {
            // Desk only: a family phone must never seat a passenger by another traveller's gender (women-only berths).
            $set('gender', $profile['gender'], 'history', 'Gender as recorded on ' . $profile['genderN'] . ' earlier ticket' . ($profile['genderN'] > 1 ? 's' : '') . '.', (float) $profile['genderShare']);
        } else {
            $set('gender', null, 'none', '', 0.0);
        }

        /* country */
        [$v, $src] = $pick('country');
        if ($v !== null && in_array(strtoupper((string) $v), ['IN', 'NP'], true)) {
            $set('country', strtoupper((string) $v), $src, '', 1.0);
        } elseif ($profile !== null) {
            $set('country', $profile['country'], 'history', $profile['country'] === 'NP' ? 'Nepali number on earlier tickets (+977 WhatsApp).' : '', 0.9);
        } else {
            $set('country', '', 'none', '', 0.0);
        }

        /* seats — a party size only from this passenger's own history */
        [$v, $src] = $pick('seats');
        $maxSeats = BookingService::maxSeatsFor($isStaff);   // one definition
        if ($v !== null && (int) $v > 0) {
            $set('seats', max(1, min($maxSeats, (int) $v)), $src, $src === 'text' ? 'Seat count read from your line.' : '', 1.0);
        } elseif ($profile !== null && $profile['party'] !== null && $profile['party']['size'] > 1 && $profile['party']['share'] >= 0.5 && $profile['party']['n'] >= 2 && $trust('seats')) {
            $set('seats', min($maxSeats, (int) $profile['party']['size']), 'history', 'Usually travels as a party of ' . $profile['party']['size'] . ' (' . $profile['party']['n'] . ' of ' . $profile['trips'] . ' trips).', (float) $profile['party']['share']);
        } else {
            $set('seats', 1, 'auto', '', 0.5);
        }

        /* direction */
        [$v, $src] = $pick('direction');
        if ($v !== null && in_array($v, ['toNepal', 'toIndia'], true)) {
            $set('direction', $v, $src, $src === 'text' ? 'Direction read from your line.' : '', 1.0);
        } elseif ($profile !== null && $profile['direction'] !== null && $profile['direction']['share'] >= 0.6 && $profile['direction']['n'] >= 2 && $trust('direction')) {
            $set('direction', $profile['direction']['value'], 'history', 'Usually travels ' . ($profile['direction']['value'] === 'toNepal' ? 'towards Nepal' : 'back to Gujarat') . ' (' . $profile['direction']['n'] . ' of ' . $profile['trips'] . ' trips).', (float) $profile['direction']['share']);
        } elseif ($isStaff && isset($patterns['dirByHour'][$hour]) && $patterns['dirByHour'][$hour]['share'] >= 0.75 && $patterns['dirByHour'][$hour]['n'] >= 5 && $trust('direction')) {
            $d = $patterns['dirByHour'][$hour];
            $set('direction', $d['value'], 'pattern', 'At this hour ' . (int) round($d['share'] * 100) . '% of sales go ' . ($d['value'] === 'toNepal' ? 'towards Nepal' : 'back to Gujarat') . '.', (float) $d['share']);
        } else {
            $set('direction', '', 'auto', '', 0.5);
        }

        /* boarding */
        [$v, $src] = $pick('boarding');
        if ($v !== null && (string) $v !== '') {
            $set('boarding', (string) $v, $src, $src === 'text' ? 'Boarding point read from your line.' : '', 1.0);
        } elseif ($profile !== null && $profile['boarding'] !== null && $profile['boarding']['share'] >= 0.5 && $trust('boarding')) {
            $b = $profile['boarding'];
            $set('boarding', $b['value'], 'history', 'Boarded at ' . $b['name'] . ' on ' . $b['n'] . ' of ' . $profile['trips'] . ' trips.', (float) $b['share']);
        } elseif ($isStaff && isset($patterns['stopBySeller'][(string) $staffId]) && $patterns['stopBySeller'][(string) $staffId]['n'] >= 3 && $trust('boarding')) {
            $b = $patterns['stopBySeller'][(string) $staffId];
            $set('boarding', $b['value'], 'desk', 'This desk usually sells ' . $b['name'] . ' (' . (int) round($b['share'] * 100) . '% of its sales).', (float) $b['share']);
        } elseif ($isStaff && isset($patterns['stopByHour'][$hour]) && $patterns['stopByHour'][$hour]['share'] >= 0.5 && $trust('boarding')) {
            $b = $patterns['stopByHour'][$hour];
            $set('boarding', $b['value'], 'pattern', 'At this hour most passengers board at ' . $b['name'] . ' (' . (int) round($b['share'] * 100) . '%).', (float) $b['share']);
        } else {
            // '' = plan() applies the default: the passengers' yard (quick_ticket_customer_boarding) for a customer, the desk setting for staff.
            $dflt = trim(Settings::getString($isStaff ? 'quick_ticket_default_boarding' : 'quick_ticket_customer_boarding', $isStaff ? '' : 'S Hari Parking'));
            $set('boarding', '', 'auto', $dflt !== '' ? 'Default pickup: ' . $dflt . '.' : 'First pickup still ahead of the clock.', 0.5);
        }

        /* date — explicit only; history is a hint, never applied */
        [$v, $src] = $pick('date');
        if ($v !== null && Security::isValidDate((string) $v)) {
            $set('date', (string) $v, $src, $src === 'text' ? 'Date read from your line.' : '', 1.0);
        } else {
            $set('date', '', 'auto', 'Next catchable departure.', 0.6);
        }
        if ($profile !== null && $profile['weekday'] !== null && $profile['weekday']['share'] >= 0.6 && $profile['trips'] >= 3) {
            $reasons[] = 'Hint: usually travels on a ' . $profile['weekday']['label'] . ' — the date is NOT changed automatically.';
        }

        /* pay (staff only) */
        [$v, $src] = $pick('pay');
        if ($isStaff && $v !== null && in_array($v, ['cash', 'upi', 'esewa', 'bank'], true)) {
            $set('pay', $v, $src, '', 1.0);
        } elseif ($isStaff) {
            $set('pay', Settings::getString('quick_ticket_default_pay', 'cash'), 'auto', '', 0.5);
        }

        /* the live plan on the merged options — the rules decide the rest */
        $opts = [
            'direction' => (string) ($fields['direction']['value'] ?? ''),
            'date'      => (string) ($fields['date']['value'] ?? ''),
            'boarding'  => (string) ($fields['boarding']['value'] ?? ''),
            'seats'     => (int) ($fields['seats']['value'] ?? 1),
            'gender'    => $fields['gender']['value'] ?? null,
            // "Same as last time": the berths to try first when free (QuickTicket::plan `prefer`).
            'prefer'    => array_values(array_filter(array_map(static fn($s): string => strtoupper(trim((string) $s)), (array) ($input['prefer'] ?? [])))),
            'customer'  => !$isStaff,
        ];
        $plan = null; $planError = '';
        try {
            $plan = QuickTicket::plan($opts);
        } catch (RuntimeException $e) {
            $planError = $e->getMessage();
            // A learned pickup / direction that cannot be sold today must not block the desk: fall back to auto once.
            if ($opts['boarding'] !== '' || $opts['direction'] !== '') {
                try {
                    $plan = QuickTicket::plan(['seats' => $opts['seats'], 'gender' => $opts['gender'], 'date' => $opts['date'], 'customer' => $opts['customer'], 'prefer' => $opts['prefer']]);
                    $reasons[] = 'Suggested pickup / direction is not sellable right now — switched to auto.';
                    $set('boarding', '', 'auto', 'Auto (suggestion not sellable).', 0.5);
                    $set('direction', '', 'auto', '', 0.5);
                    $planError = '';
                } catch (RuntimeException $e2) {
                    $planError = $e2->getMessage();
                }
            }
        }
        if ($plan !== null && !empty($opts['boarding']) && empty($plan['matchedDesk'])) {
            $reasons[] = 'The bus does not call at the suggested pickup on this run — first pickup ahead used instead.';
            $fields['boarding']['check'] = true;
        }

        $weights = ['input' => 1.0, 'text' => 0.95, 'history' => null, 'desk' => null, 'pattern' => null, 'auto' => 0.5, 'none' => 0.0];
        $sum = 0.0; $cnt = 0;
        foreach (['name', 'gender', 'seats', 'direction', 'boarding', 'date'] as $f) {
            $c = (float) ($fields[$f]['confidence'] ?? 0);
            if ($fields[$f]['source'] === 'none') {
                continue;
            }
            $sum += $c; $cnt++;
        }
        $confidence = $cnt > 0 ? round($sum / $cnt, 2) : 0.0;
        /* ---- SHG AI BRAIN Phase 2 (10 Sep 2026): the confidence ladder ----
           >= 0.85   proceed — the card IS the answer
           0.60-0.84 proceed, but SAY which facts were auto-filled (`missing`)
           <  0.60   ask ONE question — the highest-priority fact the line did
                     not settle — in the writer's own language, with tappable
                     answers. Only when a line was actually typed: a bare name +
                     mobile is the 10-second flow and must stay one tap. */
        $lang = (string) ($parsed['lang'] ?? 'en');
        if ($lang === 'en' && in_array((string) ($input['lang'] ?? ''), ['hi', 'ne', 'gu'], true)) {
            $lang = (string) $input['lang'];
        }
        $missing = [];
        foreach (['date', 'seats', 'boarding', 'direction'] as $f) {
            if (in_array((string) ($fields[$f]['source'] ?? 'none'), ['auto', 'none'], true)) {
                $missing[] = $f;
            }
        }
        $ladder    = $confidence >= 0.85 ? 'proceed' : ($confidence >= 0.60 ? 'highlight' : 'ask');
        $typedLine = $parsed !== null && trim((string) ($input['text'] ?? '')) !== '';
        $ask       = null;
        if ($typedLine && $ladder === 'ask' && $missing !== []) {
            $ask = self::question($missing[0], $lang, $plan);
        } elseif ($typedLine && !empty($fields['boarding']['check']) && ($fields['boarding']['source'] ?? '') === 'text') {
            // The pickup the line named is not on this run: ask which one, do not guess.
            $ask = self::question('boarding', $lang, $plan);
        }
        /* "Book the same as last time" — one tap for a returning passenger:
           their usual pickup, direction, party size and usual berths (the plan
           takes those berths when they are free and the gender rules allow).
           Memory is only ever shown to the number's owner or the desk — see
           $memoryPhone above; $profile is null for anybody else. */
        $sameAsLast = null;
        if ($profile !== null && (int) $profile['trips'] >= 1) {
            $sameAsLast = [
                'lastTrip'  => (string) $profile['lastTrip'],
                'lastPnr'   => (string) $profile['lastPnr'],
                'seats'     => array_values(array_map('strval', (array) $profile['seats'])),
                'party'     => (int) ($profile['party']['size'] ?? 1),
                'boarding'  => (string) ($profile['boarding']['value'] ?? ''),
                'direction' => (string) ($profile['direction']['value'] ?? ''),
            ];
        }

        $prefill = [
            'name'      => (string) ($fields['name']['value'] ?? ''),
            'phone'     => $phone,
            'country'   => (string) ($fields['country']['value'] ?? ''),
            'gender'    => $fields['gender']['value'] ?? null,
            'seats'     => (int) ($fields['seats']['value'] ?? 1),
            'direction' => (string) ($fields['direction']['value'] ?? ''),
            'boarding'  => (string) ($fields['boarding']['value'] ?? ''),
            'date'      => (string) ($fields['date']['value'] ?? ''),
            'pay'       => (string) ($fields['pay']['value'] ?? ''),
            // Optional agent code — typed in the line ("SHG-027") or in the field; never required.
            'agentCode' => strtoupper(trim((string) ((($parsed['agentCode'] ?? '') !== '') ? $parsed['agentCode'] : ($input['agentCode'] ?? '')))),
        ];

        return [
            'ok'         => $plan !== null,
            'enabled'    => $learn,
            'parsed'     => $parsed,
            'profile'    => $profile,
            // "This passenger already has a ticket for that day" (owner ask,
            // point 10). Advisory, never a refusal: BookingService's duplicate
            // guard is deliberately skipped for desk sales because one office
            // phone legitimately books party after party onto the same coach,
            // so blocking here would break the counter. Showing it lets the
            // operator SEE the clash before confirming, which is what "avoid
            // duplicate passengers/tickets" actually needs at a desk.
            'duplicate'  => self::existingTicketFor($prefill['phone'] ?? '', $plan),
            'fields'     => $fields,
            'prefill'    => $prefill,
            'plan'       => $plan,
            'planError'  => $planError,
            'reasons'    => array_values(array_unique($reasons)),
            'confidence' => $confidence,
            'lang'       => $lang,
            'ladder'     => $ladder,
            'missing'    => $missing,
            'ask'        => $ask,
            'sameAsLast' => $sameAsLast,
            'accuracy'   => $acc,
            'learned'    => [
                'sample'     => (int) ($patterns['sample'] ?? 0),
                'repeat'     => (int) ($patterns['repeat'] ?? 0),
                'windowDays' => (int) ($patterns['windowDays'] ?? 0),
                'computedAt' => (int) ($patterns['computedAt'] ?? 0),
                'topStops'   => array_slice($patterns['stops'] ?? [], 0, 3),
            ],
            'elapsedMs'  => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /** Desk header line: what the bot has learned so far. */
    public static function summary(): array
    {
        $p   = self::patterns();
        $acc = self::accuracy();
        $n   = 0; $hit = 0;
        foreach ($acc as $a) {
            $n += $a['hit'] + $a['miss']; $hit += $a['hit'];
        }

        return [
            'enabled'    => self::enabled(),
            'sample'     => (int) ($p['sample'] ?? 0),
            'repeat'     => (int) ($p['repeat'] ?? 0),
            'windowDays' => (int) ($p['windowDays'] ?? 0),
            'topStops'   => array_slice($p['stops'] ?? [], 0, 3),
            'outcomes'   => $n,
            'hitRate'    => $n > 0 ? round($hit / $n, 2) : null,
            'accuracy'   => $acc,
        ];
    }

    /* =================================================================
     *  Stop catalogue for the parser
     * ================================================================= */

    /**
     * Every pickup of every active route, with the words a clerk might type
     * for it. Built once per request.
     *
     * @return list<array{value: string, name: string, code: string, aliases: list<string>}>
     */
    public static function stopCatalogue(): array
    {
        static $cat = null;
        if ($cat !== null) {
            return $cat;
        }
        $extra = [
            // Roman, then Devanagari (Hindi / Nepali) and Gujarati spellings (SHG AI BRAIN Phase 2, 10 Sep 2026)
            'AMD' => ['nana chiloda', 'chiloda', 'hari parking', 's hari parking', 'shg parking', 'parking', 'ahmedabad', 'amdavad', 'paldi',
                      'अहमदाबाद', 'अमदावाद', 'अहमदावाद', 'नाना चिलोडा', 'चिलोडा', 'हरि पार्किंग', 'हरी पार्किंग', 'पार्किंग', 'पालडी',
                      'અમદાવાદ', 'નાના ચિલોડા', 'ચિલોડા', 'હરિ પાર્કિંગ', 'પાર્કિંગ', 'પાલડી'],
            'MSN' => ['mehsana', 'mahesana', 'silver complex', 'मेहसाणा', 'मेहसाना', 'महेसाणा', 'મહેસાણા', 'મેહસાણા', 'સિલ્વર કોમ્પ્લેક્સ'],
            'STV' => ['surat', 'surat station', 'सूरत', 'सुरत', 'સુરત'],
            'KMJ' => ['kamrej', 'कामरेज', 'કામરેજ'],
            'AKV' => ['ankleshwar', 'anklesvar', 'ankleshvar', 'अंकलेश्वर', 'अङ्कलेश्वर', 'અંકલેશ્વર'],
            'BRH' => ['bharuch', 'broach', 'भरूच', 'भरुच', 'ભરૂચ'],
            'ANA' => ['anand', 'आनंद', 'आनन्द', 'આણંદ'],
            'NAD' => ['nadiad', 'नडियाद', 'नाडियाद', 'નડિયાદ'],
            'BRC' => ['baroda', 'barauda', 'vadodara', 'badoda', 'बड़ौदा', 'बडौदा', 'बरोडा', 'वडोदरा', 'बड़ोदरा', 'વડોદરા', 'બરોડા'],
            'EMB' => ['emli', 'emli bhupal', 'bhupal', 'limbli', 'limli', 'इमली', 'इमली भूपाल', 'एमली', 'एमली भूपाल', 'लिंबली', 'लिम्बली', 'लिम्बली भूपाल', 'લીંબલી', 'લિંબલી', 'એમલી', 'એમલી ભૂપાલ'],
            'RPD' => ['rupaidiha', 'rupaideha', 'rupediha', 'jamunaha', 'border', 'रुपैडिया', 'रूपैडिया', 'रुपैडिहा', 'रुपैदिया', 'बॉर्डर', 'बोर्डर', 'जमुनाहा', 'રૂપૈડિયા', 'રુપૈડિયા', 'બોર્ડર'],
            'NPJ' => ['nepalgunj', 'nepalganj', 'नेपालगंज', 'नेपालगन्ज', 'नेपालगञ्ज', 'નેપાળગંજ', 'નેપાલગંજ'],
        ];
        $seen = [];
        $cat  = [];
        foreach (QuickTicket::routes() as $route) {
            foreach (Boarding::stopsFor((int) $route['id']) as $s) {
                $label = (string) $s['name'];
                $disp  = Boarding::stopDisplay($label);
                $key   = Boarding::townKey($label);
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $aliases = [];
                $lead = mb_strtolower(trim((string) preg_replace('/\s*\[[^\]]*\]\s*$/u', '', (string) preg_replace('/@.*$/u', '', $label))));
                foreach (preg_split('/\s*[—–·,\-]\s*/u', $lead) ?: [] as $part) {
                    $part = trim((string) preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $part));
                    $part = trim((string) preg_replace('/\s+/u', ' ', $part));
                    if (mb_strlen($part) >= 4) {
                        $aliases[] = $part;
                    }
                }
                $aliases[] = mb_strtolower((string) $disp['name']);
                foreach ($extra[(string) $disp['code']] ?? [] as $a) {
                    $aliases[] = $a;
                }
                // Longest alias first so "nana chiloda" beats "chiloda".
                $aliases = array_values(array_unique(array_filter($aliases, static fn(string $a): bool => mb_strlen($a) >= 3)));
                usort($aliases, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
                $cat[] = ['value' => self::stopValue($label), 'name' => (string) $disp['name'], 'code' => (string) $disp['code'], 'aliases' => $aliases];
            }
        }

        return $cat;
    }

    /* =================================================================
     *  Small helpers
     * ================================================================= */

    /** "Mehsana — Silver Complex @ 23:00 [23.5,72.3]" → "Mehsana — Silver Complex" (what plan() matches on). */
    public static function stopValue(string $label): string
    {
        $s = (string) preg_replace('/\s*\[[^\]]*\]\s*$/u', '', trim($label));
        $s = (string) preg_replace('/\s*@.*$/u', '', $s);

        return trim($s);
    }

    /** @param array<int|string, int> $tally @return array{key: int|string, n: int}|null */
    private static function top(array $tally): ?array
    {
        if ($tally === []) {
            return null;
        }
        arsort($tally);
        $key = array_key_first($tally);

        return ['key' => $key, 'n' => (int) $tally[$key]];
    }

    private static function weekdayName(int $w): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$w % 7];
    }

    /**
     * Day-of-month + month → a date in the current year; only when that day
     * is more than 30 days gone does it roll to next year. "5 sep" typed on
     * 6 Sep means yesterday's paper ticket (staff may record it; a customer
     * is refused by plan()), while "5 jan" typed in September means January.
     */
    private static function nextDate(int $d, int $m, string $today): string
    {
        $y   = (int) substr($today, 0, 4);
        $iso = sprintf('%04d-%02d-%02d', $y, $m, $d);
        if (!Security::isValidDate($iso)) {
            return '';
        }
        if ($iso < addDaysISO($today, -30)) {
            $iso2 = sprintf('%04d-%02d-%02d', $y + 1, $m, $d);
            return Security::isValidDate($iso2) ? $iso2 : $iso;
        }

        return $iso;
    }

    /**
     * "the 15th" → the next 15th that has not passed. A day already gone this
     * month rolls to the next month that actually has it (a "31st" asked in
     * February lands on 31 March, never on an invalid date).
     */
    private static function nextDayOfMonth(int $d, string $today): string
    {
        $y = (int) substr($today, 0, 4);
        $m = (int) substr($today, 5, 2);
        for ($i = 0; $i < 13; $i++) {
            $iso = sprintf('%04d-%02d-%02d', $y, $m, $d);
            if (checkdate($m, $d, $y) && Security::isValidDate($iso) && $iso >= $today) {
                return $iso;
            }
            if (++$m > 12) {
                $m = 1;
                $y++;
            }
        }

        return '';
    }

    private static function normField(string $f, mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        $s = trim((string) $v);
        if ($f === 'boarding') {
            // "Mehsana", "Mehsana — Silver Complex @ 23:00" and the desk's
            // "Mehsana Head Office - Silver Complex" are the same pickup:
            // compare by the ticket code when one is known, else the town key.
            $d = Boarding::stopDisplay($s);
            return $d['code'] !== 'SHG' ? 'code:' . $d['code'] : Boarding::townKey($s);
        }
        return match ($f) {
            'name'  => mb_strtolower((string) preg_replace('/\s+/u', ' ', $s)),
            'seats' => (string) max(0, (int) $s),
            default => mb_strtolower($s),
        };
    }

    /** @param list<int> $ids @return array{0: string, 1: array<string, int>} */
    private static function inList(array $ids, string $prefix): array
    {
        $names = []; $params = [];
        foreach (array_values($ids) as $i => $id) {
            $names[]              = ':' . $prefix . $i;
            $params[$prefix . $i] = (int) $id;
        }

        return [implode(',', $names), $params];
    }

    private static function kvGet(string $key): mixed
    {
        try {
            $raw = Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = :k LIMIT 1", ['k' => $key]);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $v = json_decode($raw, true);

        return is_array($v) ? $v : null;
    }

    private static function kvSet(string $key, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        try {
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('global', :k, :v, 'ticketbot')
                 ON DUPLICATE KEY UPDATE kvalue = :v2, updated_by = 'ticketbot'",
                ['k' => $key, 'v' => $json, 'v2' => $json]
            );
        } catch (Throwable $e) {
            Logger::warning('TicketBot cache write failed', ['key' => $key, 'e' => $e->getMessage()]);
        }
    }
}
