<?php
/**
 * admin/analytics.php — reporting dashboard (Chart.js).
 *
 * Read-only. Pulls straight from the live tables and renders bookings,
 * revenue, seat-fill, status, cabin, payment-method, boarding-point and
 * route figures. Every figure is real (no seed/demo data) and each chart
 * degrades to an empty-state note when its window has no rows.
 *
 * NPR bookings are normalised to INR with the company peg (1 INR = 1.6 NPR,
 * from the Source-of-Truth) so a single revenue axis stays honest.
 *
 * DATE WINDOW (2 Oct 2026, owner ask). Every KPI, chart, table and the CSV
 * download honour one window chosen at the top of the page:
 *     ?range=today|week|month|30d|custom&from=YYYY-MM-DD&to=YYYY-MM-DD
 * Which date each figure is measured on is fixed and shown on the page:
 *   - bookings, booking status, the daily bookings line and the CSV
 *     → when the booking was PLACED (bookings.created_at);
 *   - revenue and every money/paid split (cabin, payment method, boarding
 *     point, routes) → when it was PAID (bookings.confirmed_at), exactly as
 *     before, so these totals still reconcile with Accounting;
 *   - cancellations → when it was cancelled (bookings.cancelled_at);
 *   - seats filled + lower/upper deck fill → the trip's TRAVEL date.
 *
 * ?export=csv (same range params) streams the bookings placed in the window.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

/* 1 INR = 1.6 NPR — the app already defines this peg in config; only
   fall back if some environment hasn't (avoids a redefinition fatal). */
if (!defined('NPR_PER_INR')) {
    define('NPR_PER_INR', 1.6);
}
/** SQL fragment: total_amount expressed in INR regardless of stored currency. */
/* 3 Oct 2026: the peg from Admin > Settings (npr_per_inr), like the quote,
   the counter, payroll and accounting — not the config constant. */
$__peg = Settings::getFloat('npr_per_inr', (float) NPR_PER_INR);
define('SHG_AN_PEG', $__peg > 0 ? $__peg : (float) NPR_PER_INR);
const REV_INR = "SUM(CASE WHEN currency='NPR' THEN total_amount/" . SHG_AN_PEG . " ELSE total_amount END)";
/** Same peg, for queries that alias the bookings table as b. */
const REV_INR_B = "SUM(CASE WHEN b.currency='NPR' THEN b.total_amount/" . SHG_AN_PEG . " ELSE b.total_amount END)";
/** Rows in one CSV download — the same ceiling admin/export.php uses. */
const AN_CSV_CAP = 10000;

/* Confirmed for money purposes = confirmed OR completed. */
$paidStatuses = "'confirmed','completed'";

/* ---- Defensive query helpers ---------------------------------------
   A missing table or column (an environment that has not run a migration
   yet) must cost one figure, never the whole page: bootstrap turns every
   warning into an exception, so an unguarded query would 500 the report.
   Each failure is logged and reads as 0 / no rows. */
function an_num(string $sql, array $params = []): float
{
    try {
        return (float) Database::scalar($sql, $params, 0);
    } catch (Throwable $e) {
        Logger::warning('analytics: query failed', ['error' => $e->getMessage()]);
        return 0.0;
    }
}

/** @return array<int, array<string, mixed>> */
function an_rows(string $sql, array $params = []): array
{
    try {
        return Database::fetchAll($sql, $params);
    } catch (Throwable $e) {
        Logger::warning('analytics: query failed', ['error' => $e->getMessage()]);
        return [];
    }
}

/** Inclusive day count of a Y-m-d window. */
function an_days(string $from, string $to): int
{
    return (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
}

/** "1 Oct – 2 Oct 2026", "2 Oct 2026", "28 Dec 2025 – 3 Jan 2026". */
function an_window_words(string $from, string $to): string
{
    $f = (int) strtotime($from);
    $t = (int) strtotime($to);
    if ($from === $to) {
        return date('j M Y', $t);
    }
    return date('Y', $f) === date('Y', $t)
        ? date('j M', $f) . ' – ' . date('j M Y', $t)
        : date('j M Y', $f) . ' – ' . date('j M Y', $t);
}

/** Percent for a fill figure: one decimal under 10% so a few sold berths never read as "0%". */
function an_pct(int $part, int $whole): float
{
    if ($whole <= 0) {
        return 0.0;
    }
    $p = $part * 100 / $whole;
    return $p > 0 && $p < 10 ? round($p, 1) : round($p);
}

/** Deck bucket of a PHYSICAL berth id: L1..L36 lower, U1..U36 upper, 1A.. seater. */
function an_deck(string $bed): string
{
    $c = strtoupper(substr($bed, 0, 1));
    return $c === 'L' || $c === 'U' ? $c : 'S';
}

/**
 * Resolve the date window from the query string.
 *
 * DEFAULT = 30d. Before this change the page's KPI cards and its only
 * time-series chart were "last 30 days" (the status / cabin / payment /
 * boarding / route figures were all-time), so 30d keeps the headline numbers
 * where the owner is used to reading them.
 *
 * Presets end TODAY (week = Monday..today, month = 1st..today), so a preset
 * never pads the daily charts with empty future days. A custom pair may sit
 * anywhere (forward-looking seat fill is a real question), is swapped when
 * reversed, and is capped at 366 days so the daily charts and the per-trip
 * seat pass stay bounded. Anything that fails Security::isValidDate() — or an
 * unknown range key — falls back to 30d with a note, never a 500. '7d' is
 * accepted as a quiet alias (last 7 days) for hand-typed links.
 *
 * @param  array<string, mixed> $q
 * @return array{key: string, from: string, to: string, note: string}
 */
function an_window(array $q, string $today): array
{
    $raw  = $q['range'] ?? '';
    $key  = is_string($raw) ? strtolower(trim($raw)) : '';
    $note = '';
    $to   = $today;

    switch ($key) {
        case 'today':
            $from = $today;
            break;
        case 'week':
            $from = addDaysISO($today, -((int) date('N', (int) strtotime($today)) - 1));
            break;
        case 'month':
            $from = substr($today, 0, 8) . '01';
            break;
        case '7d':
            $from = addDaysISO($today, -6);
            break;
        case 'custom':
            $f = is_string($q['from'] ?? null) ? trim((string) $q['from']) : '';
            $t = is_string($q['to'] ?? null) ? trim((string) $q['to']) : '';
            if (Security::isValidDate($f) && Security::isValidDate($t)) {
                if ($f > $t) {
                    [$f, $t] = [$t, $f];
                }
                if (an_days($f, $t) > 366) {
                    $f    = addDaysISO($t, -365);
                    $note = 'Custom ranges are capped at 366 days — showing the 366 days ending ' . date('j M Y', (int) strtotime($t)) . '.';
                }
                return ['key' => 'custom', 'from' => $f, 'to' => $t, 'note' => $note];
            }
            $note = 'Those dates could not be read — showing the last 30 days instead.';
            $key  = '30d';
            $from = addDaysISO($today, -29);
            break;
        case '':
        case '30d':
            $key  = '30d';
            $from = addDaysISO($today, -29);
            break;
        default:
            $note = 'Unknown range — showing the last 30 days instead.';
            $key  = '30d';
            $from = addDaysISO($today, -29);
    }

    return ['key' => $key, 'from' => $from, 'to' => $to, 'note' => $note];
}

$win      = an_window($_GET, todayISO());
$from     = $win['from'];
$to       = $win['to'];
$winStart = $from . ' 00:00:00';
$winEnd   = addDaysISO($to, 1) . ' 00:00:00';   // exclusive upper bound
$winWords = an_window_words($from, $to);
$winDays  = an_days($from, $to);
/* The query string every link on this page carries, so a chip, the CSV
   download and the bookings deep link all describe the same window. */
$winQuery = $win['key'] === 'custom'
    ? ['range' => 'custom', 'from' => $from, 'to' => $to]
    : ['range' => $win['key']];

$payNames = ['upi' => 'UPI', 'esewa' => 'eSewa', 'cash' => 'Cash', 'cod' => 'COD', 'bank' => 'Bank', 'wallet' => 'Wallet'];

/* =====================================================================
 *  CSV export — the bookings PLACED in the window (the same set the
 *  "Total bookings" tile counts, so the file and the tile always agree).
 *  Same gate as admin/export.php: a CSV of PNRs is a bookings list, so it
 *  needs bookings.view on top of this page's dashboard.view, and the agent
 *  scope still binds should an agent ever be granted dashboard.view.
 *  shg_export_csv() writes the UTF-8 BOM (Devanagari names open in Excel)
 *  and the formula-injection guard.
 * ===================================================================== */
if (($_GET['export'] ?? '') === 'csv') {
    admin_boot('bookings.view');
    require_once __DIR__ . '/_export.php';

    $scopeId  = Auth::bookingScopeAdminId();
    $csvParam = ['x0' => $winStart, 'x1' => $winEnd];
    $scopeSql = '';
    if ($scopeId !== null) {
        $scopeSql          = ' AND b.sold_by_admin_id = :scope';
        $csvParam['scope'] = $scopeId;
    }

    /* Seats and passengers are read off the OUTBOUND leg (a round trip
       stores both per leg); a cancelled/expired booking keeps listing the
       berths it had, a live one lists only the berths it still holds. */
    $csvRows = an_rows(
        "SELECT b.id, b.pnr, b.created_at, b.status, b.source, b.total_amount, b.currency,
                b.sold_by_admin_id, ad.full_name AS agent_name, ad.role AS agent_role,
                bl.travel_date, bl.boarding_stop, r.from_city, r.to_city,
                (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
                   FROM booking_seats bs
                  WHERE bs.booking_id = b.id
                    AND (bl.id IS NULL OR bs.leg_id = bl.id)
                    AND (bs.released_at IS NULL OR b.status IN ('cancelled','expired','rejected'))) AS seats,
                (SELECT COUNT(*) FROM booking_passengers bp
                  WHERE bp.booking_id = b.id
                    AND (bl.id IS NULL OR bp.leg_id IS NULL OR bp.leg_id = bl.id)) AS pax,
                (SELECT p.method FROM payments p
                  WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS pay_method
           FROM bookings b
           LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
           LEFT JOIN schedules s     ON s.id = bl.schedule_id
           LEFT JOIN routes r        ON r.id = s.route_id
           LEFT JOIN admins ad       ON ad.id = b.sold_by_admin_id
          WHERE b.created_at >= :x0 AND b.created_at < :x1{$scopeSql}
          ORDER BY b.created_at ASC, b.id ASC
          LIMIT " . AN_CSV_CAP,
        $csvParam
    );

    /* Seller = the one answer the ticket and the chalani print
       (Ticket::issuedBy): agent name + SHG code, or the channel. */
    $sellerMemo = [];
    $sellerOf   = static function (array $b) use (&$sellerMemo): string {
        $k = (int) ($b['sold_by_admin_id'] ?? 0) . '|' . (string) ($b['source'] ?? '');
        if (isset($sellerMemo[$k])) {
            return $sellerMemo[$k];
        }
        try {
            $ib  = Ticket::issuedBy($b);
            $out = trim((string) ($ib['name'] ?? '')) . (($ib['code'] ?? '') !== '' ? ' (' . $ib['code'] . ')' : '');
        } catch (Throwable $e) {
            $out = (int) ($b['sold_by_admin_id'] ?? 0) > 0 ? (string) ($b['agent_name'] ?? '') : 'Online';
        }
        return $sellerMemo[$k] = $out;
    };

    $csvData = [];
    foreach ($csvRows as $b) {
        $route = ($b['from_city'] ?? '') !== '' || ($b['to_city'] ?? '') !== ''
            ? trim((string) $b['from_city']) . ' -> ' . trim((string) $b['to_city'])
            : '';
        $pm = (string) ($b['pay_method'] ?? '');
        $csvData[] = [
            (string) $b['pnr'],
            (string) $b['created_at'],
            (string) ($b['travel_date'] ?? ''),
            $route,
            // Drop the trailing "[lat,lng]" the browser appends to a stop label.
            trim((string) preg_replace('/\s*\[[^\]]*\]\s*$/u', '', (string) ($b['boarding_stop'] ?? ''))),
            (string) ($b['seats'] ?? ''),
            (int) ($b['pax'] ?? 0),
            (string) $b['status'],
            (string) $b['source'],
            $sellerOf($b),
            number_format((float) $b['total_amount'], 2, '.', ''),
            (string) $b['currency'],
            $pm === '' ? '' : ($payNames[$pm] ?? ucfirst($pm)),
        ];
    }

    shg_export_csv(
        'SHG-analytics-' . $from . '_to_' . $to . '.csv',
        ['PNR', 'Created at', 'Travel date', 'Route', 'Boarding point', 'Seats', 'Passengers',
         'Status', 'Source', 'Seller', 'Total amount', 'Currency', 'Payment method'],
        $csvData
    );
}

/* ---- KPI tiles ----------------------------------------------------- */
$bookingsN = (int) an_num(
    "SELECT COUNT(*) FROM bookings WHERE created_at >= :d0 AND created_at < :d1",
    ['d0' => $winStart, 'd1' => $winEnd]
);
$revenue = an_num(
    "SELECT COALESCE(" . REV_INR . ",0) FROM bookings
      WHERE status IN ($paidStatuses) AND confirmed_at >= :d0 AND confirmed_at < :d1",
    ['d0' => $winStart, 'd1' => $winEnd]
);
$confirmedN = (int) an_num(
    "SELECT COUNT(*) FROM bookings
      WHERE status IN ($paidStatuses) AND confirmed_at >= :d0 AND confirmed_at < :d1",
    ['d0' => $winStart, 'd1' => $winEnd]
);
$seatsSold = (int) an_num(
    "SELECT COUNT(*) FROM booking_seats bs
       JOIN bookings b ON b.id = bs.booking_id
      WHERE b.status IN ($paidStatuses) AND bs.released_at IS NULL
        AND b.confirmed_at >= :d0 AND b.confirmed_at < :d1",
    ['d0' => $winStart, 'd1' => $winEnd]
);
$cancelledN = (int) an_num(
    "SELECT COUNT(*) FROM bookings
      WHERE status = 'cancelled' AND cancelled_at >= :d0 AND cancelled_at < :d1",
    ['d0' => $winStart, 'd1' => $winEnd]
);
$avgFare = $confirmedN > 0 ? $revenue / $confirmedN : 0.0;

/* ---- Seats filled + lower / upper deck fill (by TRAVEL date) --------
   Numerator = PHYSICAL berths occupied, exactly as Seats::occupiedBedsFor()
   counts them (unreleased booking_seats rows of any booking, each label
   expanded through the seat_mode_map rule set — a private cabin is ONE row
   over TWO berths). It is re-done here rather than called because the deck
   of each berth is needed too, and this way the whole window costs one
   query instead of one coach lookup per trip; the test suite holds the two
   totals equal.

   Denominator = the berths that physically exist on the coach each trip ran
   (Seats::seatIds(coach, 'sharing') — the same set seatIdsForSchedule()
   validates every sale against), NOT schedules.total_seats: that column is
   bookkeeping (column default 40) and is never a seat cap, so a sleeper
   trip carrying 40 there would read as more than 100% full. Cancelled trips
   are left out; a trip on a since-retired route still counts when it
   carried passengers. */
$deck = ['L' => ['sold' => 0, 'cap' => 0], 'U' => ['sold' => 0, 'cap' => 0], 'S' => ['sold' => 0, 'cap' => 0]];
$tripsN = 0;
try {
    $coachOf  = [];
    $capCoach = [];
    foreach (an_rows(
        "SELECT s.id, COALESCE(s.coach_type_override, r.coach_type) AS coach
           FROM schedules s
           JOIN routes r ON r.id = s.route_id
          WHERE s.travel_date >= :t0 AND s.travel_date <= :t1
            AND s.status <> 'cancelled'
            AND (r.is_active = 1 OR EXISTS (SELECT 1 FROM booking_seats bx
                                             WHERE bx.schedule_id = s.id AND bx.released_at IS NULL))",
        ['t0' => $from, 't1' => $to]
    ) as $s) {
        $coach = (string) ($s['coach'] ?? '') !== '' ? (string) $s['coach'] : 'sleeper';
        $coachOf[(int) $s['id']] = $coach;
        if (!isset($capCoach[$coach])) {
            $capCoach[$coach] = ['L' => 0, 'U' => 0, 'S' => 0];
            foreach (Seats::seatIds($coach, 'sharing') as $berth) {
                $capCoach[$coach][an_deck((string) $berth)]++;
            }
        }
        foreach ($capCoach[$coach] as $dk => $n) {
            $deck[$dk]['cap'] += $n;
        }
    }
    $tripsN = count($coachOf);

    $beds = [];
    foreach (an_rows(
        "SELECT bs.schedule_id, bs.seat_no, b.booking_mode
           FROM booking_seats bs
           JOIN bookings b  ON b.id = bs.booking_id
           JOIN schedules s ON s.id = bs.schedule_id
          WHERE s.travel_date >= :t2 AND s.travel_date <= :t3
            AND bs.released_at IS NULL",
        ['t2' => $from, 't3' => $to]
    ) as $r) {
        $sid = (int) $r['schedule_id'];
        if (!isset($coachOf[$sid])) {
            continue;   // a cancelled trip — not offered, so not counted as filled either
        }
        foreach (Seats::physicalSeats((string) $r['seat_no'], (string) ($r['booking_mode'] ?? 'sharing'), $coachOf[$sid]) as $bed) {
            $beds[$sid][$bed] = true;
        }
    }
    foreach ($beds as $set) {
        foreach (array_keys($set) as $bed) {
            $deck[an_deck((string) $bed)]['sold']++;
        }
    }
} catch (Throwable $e) {
    Logger::warning('analytics: seat fill failed', ['error' => $e->getMessage()]);
}
$bedsSold    = $deck['L']['sold'] + $deck['U']['sold'] + $deck['S']['sold'];
$bedsOffered = $deck['L']['cap'] + $deck['U']['cap'] + $deck['S']['cap'];
$fillPct = $bedsOffered > 0 ? an_pct($bedsSold, $bedsOffered) : null;

$deckNames  = ['L' => 'Lower deck', 'U' => 'Upper deck', 'S' => 'Seater'];
$deckLabels = [];
$deckSold   = [];
$deckFree   = [];
$deckPct    = [];
foreach ($deck as $dk => $v) {
    if ($v['cap'] === 0 && $v['sold'] === 0) {
        continue;
    }
    $pct          = an_pct($v['sold'], $v['cap']);
    $deckLabels[] = $deckNames[$dk] . ' · ' . $pct . '%';
    $deckSold[]   = $v['sold'];
    $deckFree[]   = max(0, $v['cap'] - $v['sold']);
    $deckPct[]    = $pct;
}

/* ---- Daily series: bookings placed (line) + revenue paid (bar) ------ */
$bookByDay = [];
foreach (an_rows(
    "SELECT DATE(created_at) d, COUNT(*) c FROM bookings
      WHERE created_at >= :d0 AND created_at < :d1
      GROUP BY DATE(created_at)",
    ['d0' => $winStart, 'd1' => $winEnd]
) as $r) {
    $bookByDay[(string) $r['d']] = (int) $r['c'];
}
$revByDay = [];
foreach (an_rows(
    "SELECT DATE(confirmed_at) d, " . REV_INR . " rev
       FROM bookings
      WHERE status IN ($paidStatuses) AND confirmed_at >= :d0 AND confirmed_at < :d1
      GROUP BY DATE(confirmed_at)",
    ['d0' => $winStart, 'd1' => $winEnd]
) as $r) {
    $revByDay[(string) $r['d']] = round((float) $r['rev'], 2);
}
$dayLabels = [];
$bookData  = [];
$revData   = [];
for ($day = $from, $guard = 0; $day <= $to && $guard < 400; $day = addDaysISO($day, 1), $guard++) {
    $dayLabels[] = date('j M', (int) strtotime($day));
    $bookData[]  = $bookByDay[$day] ?? 0;
    $revData[]   = $revByDay[$day] ?? 0;
}

/* ---- Booking status breakdown (placed in the window) ---------------- */
$statusLabels = [];
$statusData   = [];
foreach (an_rows(
    "SELECT status, COUNT(*) c FROM bookings
      WHERE created_at >= :d0 AND created_at < :d1
      GROUP BY status ORDER BY c DESC",
    ['d0' => $winStart, 'd1' => $winEnd]
) as $r) {
    $statusLabels[] = ucfirst((string) $r['status']);
    $statusData[]   = (int) $r['c'];
}

/* ---- Cabin / booking mode (paid in the window) ---------------------- */
$modeLabels = [];
$modeData   = [];
foreach (an_rows(
    "SELECT COALESCE(NULLIF(booking_mode,''),'seater') m, COUNT(*) c
       FROM bookings
      WHERE status IN ($paidStatuses) AND confirmed_at >= :d0 AND confirmed_at < :d1
      GROUP BY m ORDER BY c DESC",
    ['d0' => $winStart, 'd1' => $winEnd]
) as $r) {
    $modeLabels[] = ucfirst((string) $r['m']);
    $modeData[]   = (int) $r['c'];
}

/* ---- Payment method split (paid in the window) ---------------------- */
$payLabels = [];
$payData   = [];
foreach (an_rows(
    "SELECT p.method, COUNT(DISTINCT p.booking_id) c
       FROM payments p JOIN bookings b ON b.id = p.booking_id
      WHERE b.status IN ($paidStatuses) AND b.confirmed_at >= :d0 AND b.confirmed_at < :d1
      GROUP BY p.method ORDER BY c DESC",
    ['d0' => $winStart, 'd1' => $winEnd]
) as $r) {
    $m = (string) $r['method'];
    $payLabels[] = $payNames[$m] ?? ucfirst($m);
    $payData[]   = (int) $r['c'];
}

/* ---- Boarding-point breakdown (paid in the window, outbound leg) ----
   A stop is stored as the label the seller's screen sent, so one pickup
   arrives in several spellings ("Rajkot · office pickup (+91…) [22.30,70.80]",
   "Rajkot · office pickup", "Surat @ 13:00"). Folded through
   Boarding::stopDisplay() — the helper the ticket prints the stop with — so
   each pickup is one slice. Top 7 + "Other" keeps the doughnut readable. */
$bpFold = [];
foreach (an_rows(
    "SELECT bl.boarding_stop bp, COUNT(*) c
       FROM booking_legs bl JOIN bookings b ON b.id = bl.booking_id
      WHERE bl.leg_type = 'outbound' AND b.status IN ($paidStatuses)
        AND b.confirmed_at >= :d0 AND b.confirmed_at < :d1
      GROUP BY bl.boarding_stop",
    ['d0' => $winStart, 'd1' => $winEnd]
) as $r) {
    $label = trim((string) ($r['bp'] ?? ''));
    $name  = '';
    if ($label !== '') {
        try {
            $name = trim((string) (Boarding::stopDisplay($label)['name'] ?? ''));
        } catch (Throwable $e) {
            $name = '';
        }
        if ($name === '') {
            $name = trim((string) preg_replace('/\s*\[[^\]]*\]\s*$/u', '', $label));
        }
    }
    $name = $name !== '' ? $name : 'Not recorded';
    $bpFold[$name] = ($bpFold[$name] ?? 0) + (int) $r['c'];
}
arsort($bpFold);
$bpLabels = [];
$bpData   = [];
$bpOther  = 0;
foreach ($bpFold as $name => $c) {
    if (count($bpLabels) < 7) {
        $bpLabels[] = (string) $name;
        $bpData[]   = $c;
    } else {
        $bpOther += $c;
    }
}
if ($bpOther > 0) {
    $bpLabels[] = 'Other';
    $bpData[]   = $bpOther;
}

/* ---- Route-wise earnings (6 Sep 2026) -----------------------------
   Revenue was grouped by day, status, cabin mode, payment method,
   boarding point and agent — never by route. For a company running one
   corridor with several town pairs, "which route earns" is the report the
   owner actually asks for, and it was the one number the panel could not
   answer.

   Joined through the OUTBOUND leg so a round trip counts once, against the
   direction it was sold as, and uses the same REV_INR peg and paid-status
   set as every other figure on this page so the totals reconcile. Paid in
   the window, like the revenue tile. */
$routeRows = an_rows(
    "SELECT r.id, r.route_code, r.from_city, r.to_city,
            COUNT(DISTINCT b.id)            AS tickets,
            COALESCE(SUM(bl.seat_count), 0) AS seats,
            " . REV_INR_B . "               AS rev,
            COUNT(DISTINCT bl.schedule_id)  AS trips
       FROM bookings b
       JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
       JOIN schedules s     ON s.id = bl.schedule_id
       JOIN routes r        ON r.id = s.route_id
      WHERE b.status IN ($paidStatuses) AND b.confirmed_at >= :d0 AND b.confirmed_at < :d1
      GROUP BY r.id, r.route_code, r.from_city, r.to_city
      ORDER BY rev DESC",
    ['d0' => $winStart, 'd1' => $winEnd]
);

/* Capacity is a SEPARATE pass on purpose. Folding it into the query above
   would multiply each schedule's seat count by the number of bookings on
   it and report occupancy far below 100%. Counted over the distinct
   schedules that actually carried a paid sale in the window, and read off
   the bus the schedule ran — never schedules.total_seats, which is
   bookkeeping only and is not a seat cap. */
$capByRoute = [];
foreach (an_rows(
    "SELECT s.route_id, SUM(COALESCE(bus.total_seats, rb.total_seats, 0)) AS cap
       FROM (SELECT DISTINCT bl.schedule_id
               FROM booking_legs bl
               JOIN bookings b ON b.id = bl.booking_id
              WHERE bl.leg_type = 'outbound' AND b.status IN ($paidStatuses)
                AND b.confirmed_at >= :c0 AND b.confirmed_at < :c1) AS sold
       JOIN schedules s    ON s.id = sold.schedule_id
       JOIN routes r2      ON r2.id = s.route_id
       LEFT JOIN buses bus ON bus.id = s.bus_id
       LEFT JOIN buses rb  ON rb.id  = r2.bus_id
      GROUP BY s.route_id",
    ['c0' => $winStart, 'c1' => $winEnd]
) as $c) {
    $capByRoute[(int) $c['route_id']] = (int) $c['cap'];
}

/* Anything the route join cannot attribute. On production today this is
   zero — every paid booking has an outbound leg — but a booking with no
   leg row would otherwise vanish from this report with no hint, and a
   revenue table that silently under-reports is worse than no table. The
   local test database has legacy rows like this, which is how this was
   caught. Shown as its own line rather than folded into a route. */
$unattributed = an_rows(
    "SELECT COUNT(*) AS n, " . REV_INR_B . " AS rev
       FROM bookings b
      WHERE b.status IN ($paidStatuses) AND b.confirmed_at >= :u0 AND b.confirmed_at < :u1
        AND NOT EXISTS (SELECT 1 FROM booking_legs bl
                         WHERE bl.booking_id = b.id AND bl.leg_type = 'outbound')",
    ['u0' => $winStart, 'u1' => $winEnd]
)[0] ?? ['n' => 0, 'rev' => 0];
$unattrN   = (int) ($unattributed['n'] ?? 0);
$unattrRev = (float) ($unattributed['rev'] ?? 0);

$routeTotalRev   = 0.0;
$routeTotalSeats = 0;
$routeLabels     = [];
$routeData       = [];
foreach ($routeRows as $i => $rr) {
    $routeTotalRev   += (float) $rr['rev'];
    $routeTotalSeats += (int) $rr['seats'];
    if ($i < 8) {
        $routeLabels[] = trim((string) $rr['from_city']) . ' → ' . trim((string) $rr['to_city']);
        $routeData[]   = round((float) $rr['rev'], 2);
    }
}

$hasData = $bookingsN > 0 || $confirmedN > 0 || $cancelledN > 0 || $bedsSold > 0;

/* Everything the front-end needs, JSON-safe for inline <script>. The
   window and KPI blocks are read by tests/analytics-range-test.php too. */
$payload = [
    'window' => ['key' => $win['key'], 'from' => $from, 'to' => $to, 'words' => $winWords, 'days' => $winDays],
    'kpi'    => [
        'bookings' => $bookingsN, 'confirmed' => $confirmedN, 'seatsSold' => $seatsSold,
        'revenue'  => round($revenue, 2), 'avgFare' => round($avgFare, 2),
        'bedsSold' => $bedsSold, 'bedsOffered' => $bedsOffered, 'fillPct' => $fillPct,
        'trips'    => $tripsN, 'cancelled' => $cancelledN,
    ],
    'daily'  => ['labels' => $dayLabels, 'bookings' => $bookData, 'revenue' => $revData],
    'status' => ['labels' => $statusLabels, 'data' => $statusData],
    'mode'   => ['labels' => $modeLabels,   'data' => $modeData],
    'pay'    => ['labels' => $payLabels,    'data' => $payData],
    'bp'     => ['labels' => $bpLabels,     'data' => $bpData],
    'deck'   => ['labels' => $deckLabels,   'sold' => $deckSold, 'free' => $deckFree, 'pct' => $deckPct],
    'route'  => ['labels' => $routeLabels,  'data' => $routeData],
];
$payloadJson = json_encode(
    $payload,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

$e      = static fn($v): string => Security::e((string) $v);
$self   = '/admin/analytics.php';
$csvUrl = $self . '?' . http_build_query($winQuery + ['export' => 'csv']);
$canCsv = Auth::can('bookings.view');

admin_header('Analytics', 'analytics');
admin_page_head(
    'Bookings, money and seat fill for ' . $winWords . '.',
    [],
    $canCsv
        ? '<a class="btn ghost an-csv" href="' . $e($csvUrl) . '" download><svg class="a-ic"><use href="#a-download"/></svg>Export CSV</a>'
        : ''
);
?>
<style>
  /* Date window bar */
  .an-range{padding:14px 16px;display:flex;flex-direction:column;gap:12px;overflow:visible}
  .an-chips{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .an-chips .chip{min-height:40px;padding:0 16px;font-size:13.5px;font-family:inherit;cursor:pointer;line-height:1}
  .an-chips .chip:hover{background:var(--hover);color:var(--ink)}
  /* Solid active chip: the shared .chip.on (blue-600 on blue-50) is near-invisible in dark mode. */
  .an-chips .chip.on{background:var(--blue);color:#fff;border-color:var(--blue)}
  .an-chips .chip.on:hover{background:var(--blue-600);color:#fff}
  .an-custom{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
  .an-custom[hidden]{display:none}
  .an-custom label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:var(--mut)}
  .an-custom .inp{min-height:44px}
  .an-custom .btn{min-height:44px}
  .an-window{font-size:13.5px;color:var(--ink-2);display:flex;gap:6px 14px;flex-wrap:wrap;align-items:center}
  .an-window b{color:var(--ink)}
  .an-window .muted{font-size:12.5px}
  /* KPI row: 4 across, 2 across under 1100px */
  .an-kpis{grid-template-columns:repeat(4,minmax(0,1fr))}
  .an-kpis .kv small{font-size:13px;color:var(--mut);font-weight:600}
  /* Charts */
  .an-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px;margin-bottom:22px}
  .an-grid .panel{margin-bottom:0;min-width:0}
  .an-grid .panel.wide{grid-column:1 / -1}
  .an-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
    padding:12px 18px;border-bottom:1px solid var(--line);background:var(--head)}
  /* display:block — the shared .panel h2 is a flex row, which put the subtitle beside the title. */
  .an-head h2{margin:0;padding:0;border:0;background:none;font-size:15px;display:block;flex:1 1 140px;min-width:0}
  .an-head .an-export{flex:0 0 auto}
  .an-head .an-sub{display:block;font-size:12px;font-weight:500;color:var(--mut);margin-top:2px}
  .an-body{padding:16px 18px}
  .an-canvas{position:relative;height:300px}
  .an-canvas.tall{height:340px}
  .an-canvas.short{height:220px}
  .an-note{padding:26px 18px;color:var(--mut);font-size:14px}
  .an-export{background:var(--card);border:1px solid var(--line);color:var(--ink);font-family:inherit;
    padding:5px 10px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;min-height:32px}
  .an-export:hover{background:var(--hover)}
  .an-card{border:1px solid var(--line);border-radius:var(--r);overflow:hidden;margin-top:14px}
  @media(max-width:1100px){.an-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:820px){
    .an-grid{grid-template-columns:minmax(0,1fr);gap:14px}
    .an-chips .chip{min-height:44px;flex:1 1 auto;justify-content:center}
    .an-custom label{flex:1 1 140px}
    .an-custom .inp{width:100%}
    .an-custom .btn{flex:1 1 100%}
    .an-export,.an-csv{min-height:44px}
    .an-export{padding:0 14px}
    .an-canvas{height:260px}
    .an-canvas.tall{height:300px}
    .an-body{padding:12px}
  }
  /* Route table footer as card lines on phones (the body rows already are). */
  @media(max-width:820px){
    .an-route table.card-table,.an-route table.card-table tbody,.an-route table.card-table tfoot{display:block;width:100%}
    .an-route table.card-table tfoot tr{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 14px;padding:10px 12px;border-top:1px solid var(--line)}
    .an-route table.card-table tfoot th{border:0;padding:0;background:none;text-align:left;white-space:normal;min-width:0}
    .an-route table.card-table tfoot th:empty{display:none}
    .an-route table.card-table tfoot th[data-label]::before{content:attr(data-label) " ";font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.06em}
  }
  @media(pointer:coarse){.an-chips .chip,.an-export{min-height:44px}}
  @media(max-width:560px){
    .an-kpis{gap:10px}
    .an-kpis .kpi{flex-direction:column;gap:8px;padding:12px}
    .an-kpis .kpi .ki{width:34px;height:34px;border-radius:10px}
    .an-kpis .kpi .ki .a-ic{width:18px;height:18px}
    .an-kpis .kpi .kv{font-size:20px}
    .an-kpis .kpi .kk{white-space:normal}
  }
</style>

<div class="panel an-range">
  <nav class="an-chips" aria-label="Date range">
    <?php foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', '30d' => 'Last 30 days'] as $k => $lbl): ?>
      <a class="chip<?= $win['key'] === $k ? ' on' : '' ?>" href="<?= $e($self . '?range=' . $k) ?>"<?= $win['key'] === $k ? ' aria-current="true"' : '' ?>><?= $e($lbl) ?></a>
    <?php endforeach; ?>
    <button type="button" class="chip<?= $win['key'] === 'custom' ? ' on' : '' ?>" id="anCustomBtn"
            aria-controls="anCustom" aria-expanded="<?= $win['key'] === 'custom' ? 'true' : 'false' ?>">Custom</button>
  </nav>
  <!-- Visible without JS; the script below folds it away unless Custom is active. -->
  <form class="an-custom" id="anCustom" method="get" action="<?= $e($self) ?>" data-open="<?= $win['key'] === 'custom' ? '1' : '0' ?>">
    <input type="hidden" name="range" value="custom">
    <label for="anFrom">From<input class="inp" type="date" id="anFrom" name="from" value="<?= $e($from) ?>" required></label>
    <label for="anTo">To<input class="inp" type="date" id="anTo" name="to" value="<?= $e($to) ?>" required></label>
    <button class="btn" type="submit">Apply</button>
  </form>
  <div class="an-window">
    <span>Showing <b><?= $e($winWords) ?></b> · <?= $winDays ?> day<?= $winDays === 1 ? '' : 's' ?></span>
    <span class="muted">Bookings &amp; status by date placed · money by date paid · seat fill by travel date</span>
  </div>
  <?php if ($win['note'] !== ''): ?>
    <div class="flash warn" style="margin:0"><svg class="a-ic"><use href="#a-info"/></svg><?= $e($win['note']) ?></div>
  <?php endif; ?>
  <?php if ($canCsv && $bookingsN > AN_CSV_CAP): ?>
    <div class="flash info" style="margin:0"><svg class="a-ic"><use href="#a-info"/></svg>The CSV holds the first <?= number_format(AN_CSV_CAP) ?> of <?= number_format($bookingsN) ?> bookings in this window — narrow the range to download the rest.</div>
  <?php endif; ?>
</div>

<?php if (!$hasData): ?>
  <div class="panel"><div class="an-note">No bookings, cancellations or seat sales in this window — pick a wider range above.</div></div>
<?php endif; ?>

<div class="kpis an-kpis">
  <?= admin_kpi(
      'Total bookings',
      (string) $bookingsN,
      $confirmedN . ' confirmed · ' . $seatsSold . ' seat' . ($seatsSold === 1 ? '' : 's') . ' sold',
      'ticket',
      'blue',
      null,
      $canCsv ? '/admin/bookings.php?' . http_build_query(['from' => $from, 'to' => $to]) : ''
  ) ?>
  <?= admin_kpi(
      'Revenue',
      inr($revenue),
      $confirmedN > 0 ? 'avg ' . inr($avgFare) . ' per booking · NPR @ 1:' . SHG_AN_PEG : 'INR · NPR converted @ 1:' . SHG_AN_PEG,
      'rupee',
      'green'
  ) ?>
  <?= admin_kpi(
      'Seats filled',
      $fillPct === null ? '—' : $fillPct . '%',
      $bedsOffered > 0
          ? $bedsSold . ' of ' . $bedsOffered . ' berths · ' . $tripsN . ' trip' . ($tripsN === 1 ? '' : 's')
          : 'no trips travel in this window',
      'seat',
      'navy'
  ) ?>
  <?= admin_kpi(
      'Cancellations',
      (string) $cancelledN,
      'cancelled in this window',
      'x-circle',
      'red'
  ) ?>
</div>

<div class="an-grid">
  <div class="panel wide">
    <div class="an-head"><h2>Bookings per day<span class="an-sub">every booking placed, any status</span></h2>
      <button class="an-export" type="button" data-export="bookChart" data-name="bookings-per-day">Export PNG</button></div>
    <div class="an-body"><div class="an-canvas"><canvas id="bookChart" aria-label="Bookings per day"></canvas></div></div>
  </div>

  <div class="panel wide">
    <div class="an-head"><h2>Revenue per day<span class="an-sub">₹, paid bookings, NPR converted @ 1:<?= SHG_AN_PEG ?></span></h2>
      <button class="an-export" type="button" data-export="revChart" data-name="revenue-per-day">Export PNG</button></div>
    <div class="an-body"><div class="an-canvas"><canvas id="revChart" aria-label="Revenue per day"></canvas></div></div>
  </div>

  <div class="panel">
    <div class="an-head"><h2>Boarding points<span class="an-sub">paid bookings by pickup</span></h2>
      <?php if ($bpData !== []): ?><button class="an-export" type="button" data-export="bpChart" data-name="boarding-points">Export PNG</button><?php endif; ?></div>
    <?php if ($bpData === []): ?>
      <div class="an-note">No paid bookings with a boarding point in this window.</div>
    <?php else: ?>
      <div class="an-body"><div class="an-canvas tall"><canvas id="bpChart" aria-label="Boarding points"></canvas></div></div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="an-head"><h2>Lower vs upper deck<span class="an-sub">berths filled on trips travelling in this window</span></h2>
      <?php if ($deckLabels !== []): ?><button class="an-export" type="button" data-export="deckChart" data-name="deck-fill">Export PNG</button><?php endif; ?></div>
    <?php if ($deckLabels === []): ?>
      <div class="an-note">No trips travel in this window.</div>
    <?php else: ?>
      <div class="an-body"><div class="an-canvas tall"><canvas id="deckChart" aria-label="Lower vs upper deck fill"></canvas></div></div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="an-head"><h2>Booking status<span class="an-sub">bookings placed in this window</span></h2>
      <?php if ($statusData !== []): ?><button class="an-export" type="button" data-export="statusChart" data-name="status">Export PNG</button><?php endif; ?></div>
    <?php if ($statusData === []): ?>
      <div class="an-note">No bookings placed in this window.</div>
    <?php else: ?>
      <div class="an-body"><div class="an-canvas"><canvas id="statusChart" aria-label="Booking status"></canvas></div></div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="an-head"><h2>Cabin mix<span class="an-sub">paid bookings</span></h2>
      <?php if ($modeData !== []): ?><button class="an-export" type="button" data-export="modeChart" data-name="cabin-mix">Export PNG</button><?php endif; ?></div>
    <?php if ($modeData === []): ?>
      <div class="an-note">No paid bookings in this window.</div>
    <?php else: ?>
      <div class="an-body"><div class="an-canvas"><canvas id="modeChart" aria-label="Cabin mix"></canvas></div></div>
    <?php endif; ?>
  </div>

  <div class="panel wide">
    <div class="an-head"><h2>Payment method<span class="an-sub">paid bookings</span></h2>
      <?php if ($payData !== []): ?><button class="an-export" type="button" data-export="payChart" data-name="payment-method">Export PNG</button><?php endif; ?></div>
    <?php if ($payData === []): ?>
      <div class="an-note">No paid bookings with a payment record in this window.</div>
    <?php else: ?>
      <div class="an-body"><div class="an-canvas short"><canvas id="payChart" aria-label="Payment method"></canvas></div></div>
    <?php endif; ?>
  </div>
</div>

<div class="panel an-route">
  <h2>Route earnings</h2>
  <div class="panel-body">
  <p class="muted" style="margin-top:0">
    Confirmed and completed sales paid in this window, counted against the
    outbound leg so a round trip is not double-counted. NPR converted at the
    same 1&nbsp;:&nbsp;<?= SHG_AN_PEG ?> peg used everywhere else on this page,
    so these totals reconcile with Accounting.
  </p>

  <?php if ($routeRows === [] && $unattrN === 0): ?>
    <p class="muted" style="margin-bottom:0">No confirmed sales in this window.</p>
  <?php else: ?>
  <div class="tbl-scroll">
    <table class="dt card-table">
      <thead>
        <tr>
          <th>Route</th>
          <th data-type="num">Tickets</th>
          <th data-type="num">Seats</th>
          <th data-type="num">Trips run</th>
          <th data-type="num">Occupancy</th>
          <th data-type="num">Avg / seat</th>
          <th data-type="num">Revenue</th>
          <th data-type="num">Share</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($routeRows as $rr):
        $rev   = (float) $rr['rev'];
        $seats = (int) $rr['seats'];
        $cap   = $capByRoute[(int) $rr['id']] ?? 0;
        $occ   = $cap > 0 ? round($seats * 100 / $cap) : null;
        $share = $routeTotalRev > 0 ? round($rev * 100 / $routeTotalRev) : 0;
      ?>
        <tr>
          <td data-label="Route">
            <b><?= Security::e(trim((string) $rr['from_city'])) ?> → <?= Security::e(trim((string) $rr['to_city'])) ?></b>
            <?php if (!empty($rr['route_code'])): ?>
              <br><span class="muted" style="font-size:12px"><?= Security::e((string) $rr['route_code']) ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Tickets" data-sort="<?= (int) $rr['tickets'] ?>"><?= (int) $rr['tickets'] ?></td>
          <td data-label="Seats" data-sort="<?= $seats ?>"><?= $seats ?></td>
          <td data-label="Trips run" data-sort="<?= (int) $rr['trips'] ?>"><?= (int) $rr['trips'] ?></td>
          <td data-label="Occupancy" data-sort="<?= $occ === null ? -1 : $occ ?>">
            <?php if ($occ === null): ?>
              <span class="muted" title="No bus seat count on the schedules that ran">—</span>
            <?php else: ?>
              <?= $occ ?>%<br><span class="muted" style="font-size:11px"><?= $seats ?> of <?= $cap ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Avg / seat" data-sort="<?= $seats > 0 ? (int) round($rev / $seats) : 0 ?>">
            <?= $seats > 0 ? Security::e(inr($rev / $seats)) : '—' ?>
          </td>
          <td data-label="Revenue" data-sort="<?= (int) $rev ?>"><b><?= Security::e(inr($rev)) ?></b></td>
          <td data-label="Share" data-sort="<?= $share ?>"><?= $share ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php if ($unattrN > 0): ?>
        <tr>
          <th style="color:var(--bad,#C53030)">Not attributed to a route</th>
          <th data-label="Tickets"><?= $unattrN ?></th>
          <th colspan="4"><span class="muted" style="font-weight:400;font-size:12px">
            paid bookings with no outbound leg row — they are real money, but
            this report cannot say which route earned it
          </span></th>
          <th data-label="Revenue"><?= Security::e(inr($unattrRev)) ?></th>
          <th></th>
        </tr>
        <?php endif; ?>
        <tr>
          <th>All routes</th>
          <th colspan="1"></th>
          <th data-label="Seats"><?= $routeTotalSeats ?></th>
          <th colspan="3"></th>
          <th data-label="Revenue"><?= Security::e(inr($routeTotalRev)) ?></th>
          <th data-label="Share">100%</th>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php if ($routeLabels !== []): ?>
  <div class="an-card">
    <div class="an-head"><h2>Revenue by route</h2>
      <button class="an-export" type="button" data-export="routeChart" data-name="route-earnings">Export PNG</button></div>
    <div class="an-body"><div class="an-canvas tall"><canvas id="routeChart" aria-label="Revenue by route"></canvas></div></div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script>
(function () {
  var DATA = <?= $payloadJson ?>;

  // Custom range: folded away unless it is the active window (visible without JS).
  var cBtn = document.getElementById('anCustomBtn');
  var cForm = document.getElementById('anCustom');
  if (cBtn && cForm) {
    if (cForm.getAttribute('data-open') !== '1') { cForm.hidden = true; }
    cBtn.addEventListener('click', function () {
      cForm.hidden = !cForm.hidden;
      cBtn.setAttribute('aria-expanded', cForm.hidden ? 'false' : 'true');
      if (!cForm.hidden) { var f = document.getElementById('anFrom'); if (f) { f.focus(); } }
    });
  }

  var NAVY = '#12264E', BLUE = '#2E5FA8', ORANGE = '#F07C1F';
  // Lower / upper deck use the admin seat-floor colours (.floor-L / .floor-U).
  var DECK_COLOURS = { 'Lower deck': '#1E5AA8', 'Upper deck': '#15803D', 'Seater': '#3B2A6E' };
  // Categorical palette (brand-led, then supporting hues) for the doughnuts.
  var PALETTE = ['#2E5FA8', '#F07C1F', '#138808', '#DC143C', '#3B2A6E', '#F5A623', '#0a6b3b', '#9BA8B0'];
  var charts = {};

  function money(v) {
    return '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 });
  }
  function el(id) { return document.getElementById(id); }

  function ready() {
    if (typeof Chart === 'undefined') { return setTimeout(ready, 60); }
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    var css = getComputedStyle(document.documentElement);
    var CARD = (css.getPropertyValue('--card') || '').trim() || '#fff';
    var FREE = dark ? '#2C3D5A' : '#D5DCE8';
    // Navy bars vanish on the dark card; a lighter blue keeps them readable there.
    var BAR = dark ? '#4F86D6' : NAVY;
    Chart.defaults.font.family = "system-ui,-apple-system,'Segoe UI',Roboto,sans-serif";
    // Theme-aware axis/legend text (charts draw once; toggling theme needs a reload).
    Chart.defaults.color = dark ? '#c7d2e5' : '#4a5568';
    Chart.defaults.borderColor = dark ? '#22314A' : '#E4E9F1';
    var oneDay = DATA.daily.labels.length === 1;

    // 1) Bookings per day — line
    if (el('bookChart')) {
      charts.bookChart = new Chart(el('bookChart'), {
        type: 'line',
        data: {
          labels: DATA.daily.labels,
          datasets: [{ label: 'Bookings', data: DATA.daily.bookings, borderColor: BLUE,
            backgroundColor: 'rgba(46,95,168,.12)', fill: true, tension: 0.25,
            pointRadius: oneDay || DATA.daily.labels.length <= 31 ? 3 : 0, pointHoverRadius: 5, borderWidth: 2 }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
            y: { beginAtZero: true, ticks: { precision: 0 } }
          }
        }
      });
    }

    // 2) Revenue per day — bar
    if (el('revChart')) {
      charts.revChart = new Chart(el('revChart'), {
        type: 'bar',
        data: {
          labels: DATA.daily.labels,
          datasets: [{ label: 'Revenue', data: DATA.daily.revenue, backgroundColor: BLUE,
            hoverBackgroundColor: ORANGE, borderRadius: 4, maxBarThickness: 26 }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: function (c) { return money(c.parsed.y); } } }
          },
          scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
            y: { beginAtZero: true, ticks: { callback: function (v) { return money(v); } } }
          }
        }
      });
    }

    // Doughnut helper
    function doughnut(id, block) {
      if (!el(id) || !block.data.length) { return; }
      charts[id] = new Chart(el(id), {
        type: 'doughnut',
        data: {
          labels: block.labels,
          datasets: [{ data: block.data, backgroundColor: PALETTE, borderWidth: 2, borderColor: CARD }]
        },
        options: {
          responsive: true, maintainAspectRatio: false, cutout: '58%',
          plugins: { legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, boxWidth: 8 } } }
        }
      });
    }
    doughnut('bpChart',     DATA.bp);
    doughnut('statusChart', DATA.status);
    doughnut('modeChart',   DATA.mode);

    // Payment method — horizontal bar (few categories, exact counts matter)
    if (el('payChart') && DATA.pay.data.length) {
      charts.payChart = new Chart(el('payChart'), {
        type: 'bar',
        data: {
          labels: DATA.pay.labels,
          datasets: [{ label: 'Bookings', data: DATA.pay.data, backgroundColor: BAR,
            hoverBackgroundColor: ORANGE, borderRadius: 4, maxBarThickness: 24 }]
        },
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { display: false } },
                    y: { grid: { display: false } } }
        }
      });
    }

    // Lower vs upper deck — stacked horizontal bar: filled vs free berths
    if (el('deckChart') && DATA.deck.labels.length) {
      var deckMax = Math.max.apply(null, DATA.deck.sold.map(function (v, i) { return v + DATA.deck.free[i]; }));
      var deckFill = DATA.deck.labels.map(function (l) {
        return DECK_COLOURS[l.split(' · ')[0]] || BAR;
      });
      charts.deckChart = new Chart(el('deckChart'), {
        type: 'bar',
        data: {
          labels: DATA.deck.labels,
          datasets: [
            { label: 'Filled', data: DATA.deck.sold, backgroundColor: deckFill, borderRadius: 4, maxBarThickness: 44 },
            { label: 'Free', data: DATA.deck.free, backgroundColor: FREE, borderRadius: 4, maxBarThickness: 44 }
          ]
        },
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
            tooltip: { callbacks: { label: function (c) {
              return c.dataset.label + ': ' + c.parsed.x + ' berth' + (c.parsed.x === 1 ? '' : 's');
            } } }
          },
          // Axis ends at the berths offered, so a full bar reads as a full bus.
          scales: { x: { stacked: true, beginAtZero: true, max: deckMax, ticks: { precision: 0 }, grid: { display: false } },
                    y: { stacked: true, grid: { display: false } } }
        }
      });
    }

    /* Revenue by route. Only rendered when the panel above actually drew a
       canvas — with no confirmed sales the whole block is absent. */
    if (el('routeChart') && DATA.route && DATA.route.labels.length) {
      charts.routeChart = new Chart(el('routeChart'), {
        type: 'bar',
        data: {
          labels: DATA.route.labels,
          datasets: [{ label: 'Revenue (INR)', data: DATA.route.data, backgroundColor: BAR,
            hoverBackgroundColor: ORANGE, borderRadius: 4, maxBarThickness: 24 }]
        },
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: function (c) { return money(c.parsed.x); } } }
          },
          scales: {
            x: { beginAtZero: true, grid: { display: false }, ticks: { callback: function (v) { return money(v); } } },
            y: { grid: { display: false } }
          }
        }
      });
    }

    // Export PNG buttons
    document.querySelectorAll('.an-export').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var ch = charts[btn.getAttribute('data-export')];
        if (!ch) { return; }
        var a = document.createElement('a');
        a.download = 'SHG-' + btn.getAttribute('data-name') + '-' + DATA.window.from + '_' + DATA.window.to + '.png';
        a.href = ch.toBase64Image('image/png', 1);
        a.click();
      });
    });
  }
  ready();
})();
</script>
<?php
admin_footer();
