<?php
/**
 * =====================================================================
 *  analytics-range-test.php — admin/analytics.php date window (2 Oct 2026).
 *
 *  The Analytics page gained a date window (?range=today|week|month|30d|
 *  custom&from&to), four KPI tiles, daily bookings/revenue charts, a
 *  boarding-point doughnut, a lower-vs-upper deck fill chart and a CSV
 *  download (?export=csv). This suite renders the page through
 *  tests/render-admin.php (a CLI child process with a faked superadmin
 *  session) and checks:
 *
 *    - every preset and a custom range render with no PHP warning/fatal,
 *      the four KPI labels are present and the window is the right one;
 *    - invalid / reversed / over-long custom dates fall back safely;
 *    - against a FIXTURE written into March 2021 (a window no live or test
 *      data reaches): bookings / revenue (NPR peg) / cancellations count on
 *      the right date column, seats-filled counts PHYSICAL berths (a private
 *      cabin row = 2 berths, released seats and cancelled trips excluded,
 *      denominator = the coach's real berths, not schedules.total_seats), the
 *      deck split is L vs U, and the berth total equals
 *      Seats::occupiedBedsFor() for the same trips;
 *    - the CSV: UTF-8 BOM + header row, rows inside the window present, rows
 *      outside absent, formula-injection guard applied;
 *    - no "analytics: query failed" log line was written by any render (the
 *      page's defensive helpers swallow query errors, so a broken query
 *      would otherwise only show up as a quietly wrong number).
 *
 *  Every fixture row is removed in a finally block (and on shutdown).
 *
 *    php -c .claude/php-dev.ini tests/analytics-range-test.php
 *
 *  CLI only. Exit 1 on any failure.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require __DIR__ . '/../includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/** Run tests/render-admin.php analytics.php <args> in a child PHP; return stdout, stderr, exit code. */
function render(array $args): array {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '')
         . ' ' . escapeshellarg(__DIR__ . '/render-admin.php') . ' analytics.php';
    foreach ($args as $k => $v) { $cmd .= ' ' . escapeshellarg($k . '=' . $v); }
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($p)) { return ['out' => '', 'err' => 'proc_open failed', 'code' => 255]; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['out' => $out, 'err' => $err, 'code' => proc_close($p)];
}

/** The page's inline `var DATA = {...};` payload, decoded. */
function payload(string $html): array {
    if (!preg_match('/var DATA = (\{.*?\});\s*\n/s', $html, $m)) { return []; }
    $d = json_decode($m[1], true);
    return is_array($d) ? $d : [];
}

/** No PHP error text and the page finished (admin_footer ran). */
function cleanRender(array $r): bool {
    return $r['code'] === 0
        && !preg_match('/Application error|Warning:|Fatal error|Notice:|Deprecated:|Uncaught|Something went wrong/i', $r['out'] . $r['err'])
        && str_contains($r['out'], '</html>');
}

$today  = date('Y-m-d');
$logMax = (int) Database::scalar('SELECT COALESCE(MAX(id),0) FROM app_logs', [], 0);

/* ---------------------------------------------------------------------
 *  1. Presets
 * ------------------------------------------------------------------- */
echo "\n=== Presets render cleanly and pick the right window ===\n";
$dow      = (int) date('N');
$expected = [
    'today' => [$today, $today],
    'week'  => [date('Y-m-d', strtotime('-' . ($dow - 1) . ' days')), $today],
    'month' => [date('Y-m-01'), $today],
    '30d'   => [date('Y-m-d', strtotime('-29 days')), $today],
];
foreach ($expected as $key => [$ef, $et]) {
    $r = render(['range' => $key]);
    $d = payload($r['out']);
    check("range=$key renders with no PHP error", cleanRender($r), 'exit ' . $r['code'] . ($r['err'] !== '' ? ' stderr: ' . substr(trim($r['err']), 0, 160) : ''));
    check("range=$key window = $ef .. $et", ($d['window']['key'] ?? '') === $key && ($d['window']['from'] ?? '') === $ef && ($d['window']['to'] ?? '') === $et,
        json_encode($d['window'] ?? null));
    $labels = ['Total bookings', 'Revenue', 'Seats filled', 'Cancellations'];
    $missing = array_values(array_filter($labels, static fn(string $l): bool => !str_contains($r['out'], '<div class="kk">' . $l . '</div>')));
    check("range=$key shows the 4 KPI tiles", $missing === [], $missing === [] ? '' : 'missing: ' . implode(', ', $missing));
    check("range=$key marks its chip active", str_contains($r['out'], 'href="/admin/analytics.php?range=' . $key . '" aria-current="true"'));
}
$r = render([]);
$d = payload($r['out']);
check('no range param → default 30d window', cleanRender($r) && ($d['window']['key'] ?? '') === '30d' && ($d['window']['days'] ?? 0) === 30);
foreach (['bookChart', 'revChart', 'deckChart'] as $cid) {
    check("default view has the $cid canvas", str_contains($r['out'], 'id="' . $cid . '"'));
}
check('CSV link carries the window', str_contains($r['out'], 'href="/admin/analytics.php?range=30d&amp;export=csv"'));

/* ---------------------------------------------------------------------
 *  2. Bad input falls back safely
 * ------------------------------------------------------------------- */
echo "\n=== Invalid / reversed / over-long custom ranges ===\n";
$r = render(['range' => 'custom', 'from' => '2021-02-30', 'to' => '2021-03-31']);
$d = payload($r['out']);
check('impossible date (30 Feb) → 30d fallback, no error', cleanRender($r) && ($d['window']['key'] ?? '') === '30d');
check('… and the page says so', str_contains($r['out'], 'could not be read'));

$r = render(['range' => 'custom', 'from' => '<script>alert(1)</script>', 'to' => 'x']);
check('garbage dates → 30d fallback, nothing echoed raw', cleanRender($r) && (payload($r['out'])['window']['key'] ?? '') === '30d' && !str_contains($r['out'], '<script>alert(1)'));

$r = render(['range' => 'nonsense']);
check('unknown range key → 30d fallback with a note', cleanRender($r) && (payload($r['out'])['window']['key'] ?? '') === '30d' && str_contains($r['out'], 'Unknown range'));

$r = render(['range' => 'custom', 'from' => '2019-01-01', 'to' => '2021-03-31']);
$d = payload($r['out']);
check('custom range over 366 days is capped to 366', cleanRender($r) && ($d['window']['days'] ?? 0) === 366 && ($d['window']['to'] ?? '') === '2021-03-31'
    && str_contains($r['out'], 'capped at 366 days'), json_encode($d['window'] ?? null));

/* ---------------------------------------------------------------------
 *  3. Fixture in March 2021
 * ------------------------------------------------------------------- */
$tag   = 'ANTEST-' . strtoupper(bin2hex(random_bytes(3)));
$sched = [];
$cleanup = static function () use ($tag, &$sched): void {
    try {
        // bookings cascade to legs, seats, passengers and payments.
        Database::query('DELETE FROM bookings WHERE pnr LIKE :p', ['p' => $tag . '%']);
        foreach ($sched as $sid) {
            Database::query('DELETE FROM schedules WHERE id = :i', ['i' => $sid]);
        }
        $sched = [];
    } catch (Throwable $e) {
        fwrite(STDERR, 'cleanup failed: ' . $e->getMessage() . "\n");
    }
};
register_shutdown_function($cleanup);

try {
    echo "\n=== Fixture window 1 Mar – 31 Mar 2021 ===\n";
    $route = Database::fetch("SELECT id FROM routes WHERE coach_type = 'sleeper' ORDER BY is_active DESC, id LIMIT 1");
    if ($route === null) {
        throw new RuntimeException('no sleeper route in this database to hang the fixture trip on');
    }
    $routeId = (int) $route['id'];
    $taken = (int) Database::scalar(
        "SELECT COUNT(*) FROM schedules WHERE travel_date BETWEEN '2021-02-01' AND '2021-04-30'", [], 0
    ) + (int) Database::scalar(
        "SELECT COUNT(*) FROM bookings WHERE created_at >= '2021-02-01' AND created_at < '2021-05-01'", [], 0
    );
    check('fixture window is empty before the test', $taken === 0, "$taken pre-existing rows");

    /* S = the trip that ran; total_seats deliberately WRONG (40 on a 72-berth
       sleeper) to prove the page does not divide by schedules.total_seats.
       S2 = a cancelled trip — neither offered nor filled. */
    $mkSched = static function (string $date, string $status) use ($routeId, &$sched): int {
        $id = (int) Database::insert('schedules', [
            'route_id' => $routeId, 'travel_date' => $date, 'slot' => 1,
            'coach_type_override' => 'sleeper', 'total_seats' => 40, 'status' => $status,
        ]);
        $sched[] = $id;
        return $id;
    };
    $S  = $mkSched('2021-03-10', 'scheduled');
    $S2 = $mkSched('2021-03-11', 'cancelled');

    $mkBooking = static function (string $suffix, array $b) use ($tag): int {
        return (int) Database::insert('bookings', $b + [
            'pnr' => $tag . '-' . $suffix, 'contact_phone' => '9000000000', 'trip_type' => 'oneway',
        ]);
    };
    $mkLeg = static function (int $bookingId, int $sid, string $date, string $stop, array $seats, ?string $releasedAt = null): int {
        $legId = (int) Database::insert('booking_legs', [
            'booking_id' => $bookingId, 'schedule_id' => $sid, 'leg_type' => 'outbound',
            'travel_date' => $date, 'boarding_stop' => $stop, 'seat_count' => count($seats),
        ]);
        foreach ($seats as $i => $seat) {
            Database::insert('booking_seats', [
                'schedule_id' => $sid, 'seat_no' => $seat, 'booking_id' => $bookingId,
                'leg_id' => $legId, 'released_at' => $releasedAt,
            ]);
            Database::insert('booking_passengers', [
                'booking_id' => $bookingId, 'leg_id' => $legId, 'passenger_ref' => 'PX-' . bin2hex(random_bytes(4)),
                'seat_no' => $seat, 'full_name' => 'राम Test ' . $i, 'is_primary' => $i === 0 ? 1 : 0,
            ]);
        }
        return $legId;
    };
    $mkPay = static function (int $bookingId, string $method, float $amount, string $cur): void {
        Database::insert('payments', [
            'booking_id' => $bookingId, 'payment_ref' => 'PAY-' . bin2hex(random_bytes(5)),
            'method' => $method, 'amount' => $amount, 'currency' => $cur, 'status' => 'verified',
        ]);
    };

    // A: confirmed sharing, two berths (L4, U7), Rajkot, INR 2200, UPI.
    $A = $mkBooking('A', ['status' => 'confirmed', 'booking_mode' => 'sharing', 'source' => 'web', 'currency' => 'INR',
        'total_amount' => 2200, 'created_at' => '2021-03-05 10:00:00', 'confirmed_at' => '2021-03-05 10:05:00']);
    $mkLeg($A, $S, '2021-03-10', 'Rajkot · Rajkot office pickup (+91 94268 27507) [22.3039,70.8022]', ['L4', 'U7']);
    $mkPay($A, 'upi', 2200, 'INR');

    // B: confirmed PRIVATE cabin L1 = physical L1 + L2, Surat, NPR 3200 (= ₹2000), cash.
    $B = $mkBooking('B', ['status' => 'confirmed', 'booking_mode' => 'private', 'source' => 'counter', 'currency' => 'NPR',
        'total_amount' => 3200, 'created_at' => '2021-03-06 11:00:00', 'confirmed_at' => '2021-03-06 11:00:00']);
    $mkLeg($B, $S, '2021-03-10', 'Surat @ 13:00', ['L1']);
    $mkPay($B, 'cash', 3200, 'NPR');

    // C: cancelled in the window, its berth released; boarding label starts with "=" (CSV guard).
    $C = $mkBooking('C', ['status' => 'cancelled', 'booking_mode' => 'sharing', 'source' => 'admin', 'currency' => 'INR',
        'total_amount' => 2200, 'created_at' => '2021-03-07 09:00:00', 'confirmed_at' => '2021-03-07 09:00:00',
        'cancelled_at' => '2021-03-08 09:00:00']);
    $mkLeg($C, $S, '2021-03-10', '=1+2 Evil stop', ['L5'], '2021-03-08 09:00:00');

    // F: confirmed on the CANCELLED trip — counts as a booking + revenue, never as a filled berth.
    $F = $mkBooking('F', ['status' => 'confirmed', 'booking_mode' => 'sharing', 'source' => 'web', 'currency' => 'INR',
        'total_amount' => 1000, 'created_at' => '2021-03-09 08:00:00', 'confirmed_at' => '2021-03-09 08:00:00']);
    $mkLeg($F, $S2, '2021-03-11', 'Mehsana — Silver Complex', ['U1']);

    // D: placed and paid AFTER the window — must not appear anywhere.
    $mkBooking('D', ['status' => 'confirmed', 'booking_mode' => 'sharing', 'source' => 'web', 'currency' => 'INR',
        'total_amount' => 9999, 'created_at' => '2021-04-15 10:00:00', 'confirmed_at' => '2021-04-15 10:00:00']);

    // E: placed BEFORE the window, cancelled inside it — a cancellation, not a booking of this window.
    $mkBooking('E', ['status' => 'cancelled', 'booking_mode' => 'sharing', 'source' => 'web', 'currency' => 'INR',
        'total_amount' => 2200, 'created_at' => '2021-02-20 10:00:00', 'confirmed_at' => '2021-02-20 10:00:00',
        'cancelled_at' => '2021-03-02 10:00:00']);

    $r = render(['range' => 'custom', 'from' => '2021-03-01', 'to' => '2021-03-31']);
    $d = payload($r['out']);
    $k = $d['kpi'] ?? [];
    check('custom window renders with no PHP error', cleanRender($r), 'exit ' . $r['code']);
    check('window in words: "1 Mar – 31 Mar 2021"', str_contains($r['out'], '<b>1 Mar – 31 Mar 2021</b>'));
    check('Custom chip active, custom form open', str_contains($r['out'], 'id="anCustomBtn"') && str_contains($r['out'], 'data-open="1"'));
    check('Total bookings = 4 (placed in window: A B C F)', ($k['bookings'] ?? null) === 4, json_encode($k['bookings'] ?? null));
    check('confirmed = 3, seat rows sold = 4', ($k['confirmed'] ?? null) === 3 && ($k['seatsSold'] ?? null) === 4,
        json_encode([$k['confirmed'] ?? null, $k['seatsSold'] ?? null]));
    check('Revenue = ₹5,200 (NPR 3200 pegged to ₹2000)', abs((float) ($k['revenue'] ?? -1) - 5200.0) < 0.01, json_encode($k['revenue'] ?? null));
    check('Revenue tile prints ₹5,200', str_contains($r['out'], '<div class="kv">₹5,200</div>'));
    check('Cancellations = 2 (by cancelled_at: C and E)', ($k['cancelled'] ?? null) === 2, json_encode($k['cancelled'] ?? null));
    check('trips = 1 (cancelled trip left out)', ($k['trips'] ?? null) === 1, json_encode($k['trips'] ?? null));
    check('berths offered = 72 (coach geometry, not schedules.total_seats = 40)', ($k['bedsOffered'] ?? null) === 72, json_encode($k['bedsOffered'] ?? null));
    check('berths filled = 4 (private cabin = 2, released berth + cancelled trip excluded)', ($k['bedsSold'] ?? null) === 4, json_encode($k['bedsSold'] ?? null));
    $occ = array_sum(Seats::occupiedBedsFor([$S]));
    check('berths filled == Seats::occupiedBedsFor() for the same trip', ($k['bedsSold'] ?? null) === $occ, "occupiedBedsFor=$occ");
    check('Seats filled = 5.6%', abs((float) ($k['fillPct'] ?? -1) - 5.6) < 0.001 && str_contains($r['out'], '<div class="kv">5.6%</div>'),
        json_encode($k['fillPct'] ?? null));
    $deck = $d['deck'] ?? [];
    check('deck split: lower 3 / upper 1 filled, 33 / 35 free',
        ($deck['sold'] ?? null) === [3, 1] && ($deck['free'] ?? null) === [33, 35]
        && str_starts_with((string) ($deck['labels'][0] ?? ''), 'Lower deck') && str_starts_with((string) ($deck['labels'][1] ?? ''), 'Upper deck'),
        json_encode($deck));
    $daily = $d['daily'] ?? [];
    check('daily series cover all 31 days', count($daily['labels'] ?? []) === 31 && count($daily['bookings'] ?? []) === 31 && count($daily['revenue'] ?? []) === 31);
    check('daily bookings: one each on 5, 6, 7 and 9 Mar', array_sum($daily['bookings'] ?? []) === 4
        && ($daily['bookings'][4] ?? 0) === 1 && ($daily['bookings'][8] ?? 0) === 1);
    check('daily revenue: ₹2,200 on 5 Mar, ₹2,000 on 6 Mar', ($daily['revenue'][4] ?? 0) == 2200 && ($daily['revenue'][5] ?? 0) == 2000);
    $bp = array_combine($d['bp']['labels'] ?? [], $d['bp']['data'] ?? []) ?: [];
    ksort($bp);
    check('boarding points folded to Mehsana / Rajkot / Surat, one each', $bp === ['Mehsana' => 1, 'Rajkot' => 1, 'Surat' => 1], json_encode($bp));
    check('status doughnut = Confirmed 3, Cancelled 1',
        (array_combine($d['status']['labels'] ?? [], $d['status']['data'] ?? []) ?: []) == ['Confirmed' => 3, 'Cancelled' => 1]);
    check('payment split = UPI 1, Cash 1', (array_combine($d['pay']['labels'] ?? [], $d['pay']['data'] ?? []) ?: []) == ['UPI' => 1, 'Cash' => 1]);
    check('bookings tile links to the same placed-between window', str_contains($r['out'], 'href="/admin/bookings.php?from=2021-03-01&amp;to=2021-03-31"'));

    $r = render(['range' => 'custom', 'from' => '2021-03-31', 'to' => '2021-03-01']);
    $d = payload($r['out']);
    check('reversed custom dates are swapped, same figures', cleanRender($r) && ($d['window']['from'] ?? '') === '2021-03-01'
        && ($d['window']['to'] ?? '') === '2021-03-31' && ($d['kpi']['bookings'] ?? null) === 4);

    $r = render(['range' => 'custom', 'from' => '2021-04-01', 'to' => '2021-04-30']);
    $d = payload($r['out']);
    check('April window sees only D (bookings 1, revenue ₹9,999, no trips)', cleanRender($r) && ($d['kpi']['bookings'] ?? null) === 1
        && abs((float) ($d['kpi']['revenue'] ?? 0) - 9999.0) < 0.01 && ($d['kpi']['bedsOffered'] ?? null) === 0
        && str_contains($r['out'], 'no trips travel in this window'));

    /* -----------------------------------------------------------------
     *  4. CSV export
     * --------------------------------------------------------------- */
    echo "\n=== CSV export ===\n";
    $c = render(['range' => 'custom', 'from' => '2021-03-01', 'to' => '2021-03-31', 'export' => 'csv']);
    $body = $c['out'];
    check('CSV exits cleanly', $c['code'] === 0 && !preg_match('/Application error|Warning:|Fatal error/i', $body . $c['err']), 'exit ' . $c['code']);
    check('CSV starts with the UTF-8 BOM', str_starts_with($body, "\xEF\xBB\xBF"));
    $lines = array_values(array_filter(preg_split('/\r?\n/', substr($body, 3)) ?: [], static fn($l) => $l !== ''));
    $rows  = array_map(static fn(string $l): array => str_getcsv($l, ',', '"', '\\'), $lines);
    check('CSV header row', ($rows[0] ?? []) === ['PNR', 'Created at', 'Travel date', 'Route', 'Boarding point', 'Seats', 'Passengers',
        'Status', 'Source', 'Seller', 'Total amount', 'Currency', 'Payment method'], json_encode($rows[0] ?? null));
    $byPnr = [];
    foreach (array_slice($rows, 1) as $row) { $byPnr[(string) ($row[0] ?? '')] = $row; }
    check('CSV has exactly the 4 bookings placed in the window', count($rows) - 1 === 4 && array_keys($byPnr) === [$tag . '-A', $tag . '-B', $tag . '-C', $tag . '-F'],
        json_encode(array_keys($byPnr)));
    check('row placed after the window (D) is absent', !str_contains($body, $tag . '-D'));
    check('row placed before the window (E) is absent', !str_contains($body, $tag . '-E'));
    $a = $byPnr[$tag . '-A'] ?? [];
    check('row A: travel date, seats, passengers, status, amount, currency, UPI',
        ($a[2] ?? '') === '2021-03-10' && ($a[5] ?? '') === 'L4 U7' && ($a[6] ?? '') === '2' && ($a[7] ?? '') === 'confirmed'
        && ($a[10] ?? '') === '2200.00' && ($a[11] ?? '') === 'INR' && ($a[12] ?? '') === 'UPI', json_encode($a, JSON_UNESCAPED_UNICODE));
    check('row A: boarding point without the [lat,lng] tail', ($a[4] ?? '') === 'Rajkot · Rajkot office pickup (+91 94268 27507)', (string) ($a[4] ?? ''));
    check('row A: route filled from the trip', str_contains((string) ($a[3] ?? ''), ' -> '), (string) ($a[3] ?? ''));
    check('row A: seller = ONLINE channel', str_contains((string) ($a[9] ?? ''), '(ONLINE)'), (string) ($a[9] ?? ''));
    $b = $byPnr[$tag . '-B'] ?? [];
    check('row B: NPR amount kept in its own currency', ($b[10] ?? '') === '3200.00' && ($b[11] ?? '') === 'NPR' && ($b[12] ?? '') === 'Cash');
    $cRow = $byPnr[$tag . '-C'] ?? [];
    check('row C: formula-injection guard prefixes "=" with a quote', ($cRow[4] ?? '') === "'=1+2 Evil stop", (string) ($cRow[4] ?? ''));
    check('row C: a cancelled booking still lists the berth it had', ($cRow[5] ?? '') === 'L5' && ($cRow[7] ?? '') === 'cancelled');

    $c2 = render(['range' => 'custom', 'from' => '2021-04-01', 'to' => '2021-04-30', 'export' => 'csv']);
    check('April CSV holds D only', str_contains($c2['out'], $tag . '-D') && !str_contains($c2['out'], $tag . '-A'));

    $c3 = render(['range' => 'custom', 'from' => '2021-02-30', 'to' => 'nope', 'export' => 'csv']);
    check('CSV with invalid dates falls back to 30d, still a valid file', $c3['code'] === 0 && str_starts_with($c3['out'], "\xEF\xBB\xBFPNR,")
        && !str_contains($c3['out'], $tag));
} catch (Throwable $e) {
    check('fixture section ran', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $cleanup();
}

/* ---------------------------------------------------------------------
 *  5. Housekeeping + source-level gates
 * ------------------------------------------------------------------- */
echo "\n=== Cleanup, logs and gates ===\n";
check('fixture rows removed', (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE pnr LIKE :p', ['p' => $tag . '%'], 0) === 0
    && (int) Database::scalar("SELECT COUNT(*) FROM schedules WHERE travel_date BETWEEN '2021-03-10' AND '2021-03-11' AND route_id = :r",
        ['r' => (int) ($routeId ?? 0)], 0) === 0);
$failedLogs = (int) Database::scalar("SELECT COUNT(*) FROM app_logs WHERE id > :m AND message LIKE 'analytics:%'", ['m' => $logMax], 0);
check('no "analytics: query failed" log line during the run', $failedLogs === 0, "$failedLogs logged");

$src = (string) file_get_contents(dirname(__DIR__) . '/admin/analytics.php');
check('page gated on dashboard.view', str_contains($src, "admin_boot('dashboard.view')"));
check('CSV additionally gated on bookings.view (like admin/export.php)', (bool) preg_match("/export'\] \?\? ''\) === 'csv'\) \{\s*admin_boot\('bookings\.view'\);/", $src));
check('CSV honours the agent booking scope', str_contains($src, 'Auth::bookingScopeAdminId()') && str_contains($src, 'b.sold_by_admin_id = :scope'));
check('CSV goes through shg_export_csv (BOM + formula guard)', str_contains($src, 'shg_export_csv('));
check('dates validated with Security::isValidDate()', str_contains($src, 'Security::isValidDate($f) && Security::isValidDate($t)'));

echo "\n" . ($FAIL === 0 ? "\033[32m" : "\033[31m") . "analytics-range-test: $PASS passed, $FAIL failed\033[0m\n";
exit($FAIL ? 1 : 0);
