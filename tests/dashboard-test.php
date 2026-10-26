<?php
/**
 * Dashboard (admin/index.php) — 15 Oct 2026 upgrade. CLI only, DB-backed.
 *
 *   SHG_DB=lt_f4 php -c .claude/php-dev.ini tests/dashboard-test.php
 *
 * Locks in:
 *   • MoneySummary::window() splits collected money by the latest payment
 *     method — cash / settled cod = cash, everything else = online — and
 *     collectedCash + collectedOnline == collected, always (day + month);
 *   • "To verify" = Verify Payments' default proof list: the SQL is the
 *     one admin/payments.php runs, the tile, AI Watch and the live poll
 *     all show that number;
 *   • the page renders five sections in priority order, keeps every
 *     data-live-kpi / .live-trip hook and the 15 s poll, folds month
 *     tiles / sparkline / donut into "More figures", lists the last 20
 *     bookings with Edit / Date, the agent-wise settlements, today's
 *     sellers, the cash vs online split, and labels the fleet count;
 *   • "Bookings today" and its passenger line count the same set;
 *   • a ?date= snapshot restates per-trip sold in PHYSICAL berths (a
 *     private cabin = 2, EX extra seats not counted) — same as the poll;
 *   • for every office role, every link on the page opens a page that
 *     role may open (no 403 / no viewer bounce), the viewer never sees a
 *     passenger name, money tiles stay with reports.view;
 *   • a counter is bounced to its register and its phone Home slot is
 *     SmartBook (it was an empty hole);
 *   • admin/api/live-status.php returns toVerify and per-trip pax.
 *
 * Throwaway rows: bookings PNR 'DSHT-…' (+ legs / seats / passengers /
 * payments) and staff 'dash-t-<role>' (left inactive). Refuses to run
 * unless DB_NAME is a test database. Exit 1 on any failure.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: render one admin piece as one staff member ---------- */
if (($argv[1] ?? '') === '--child') {
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    if (!str_contains((string) DB_NAME, 'test') && !str_starts_with((string) DB_NAME, 'lt_')) { exit(2); }
    $a = Database::fetch('SELECT * FROM admins WHERE username = :u', ['u' => (string) ($argv[3] ?? '')]);
    if ($a === null) { fwrite(STDERR, "no such admin\n"); exit(1); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    parse_str((string) ($argv[4] ?? ''), $_GET);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST']      = 'localhost';
    if (($argv[2] ?? '') === 'nav') {
        $_SERVER['SCRIPT_NAME'] = $_SERVER['REQUEST_URI'] = '/admin/bookings.php';
        require dirname(__DIR__) . '/admin/_guard.php';
        echo admin_mobile_nav(admin_nav(), '');
        exit(0);
    }
    if (($argv[2] ?? '') === 'live') {
        $_SERVER['SCRIPT_NAME'] = $_SERVER['REQUEST_URI'] = '/admin/api/live-status.php';
        require dirname(__DIR__) . '/admin/api/live-status.php';
        exit(0);
    }
    exit(1);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!str_contains((string) DB_NAME, 'test') && !str_starts_with((string) DB_NAME, 'lt_')) {
    fwrite(STDERR, "Refusing: DB_NAME '" . DB_NAME . "' is not a test database\n");
    exit(2);
}
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/aiwatch.php';
require_once INCLUDE_PATH . '/moneysummary.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function near(float $a, float $b): bool { return abs($a - $b) < 0.011; }
/** Run PHP on a script in this repo (array command: the path holds an apostrophe). */
function php(array $args, array $env = []): string {
    $cmd = array_merge([PHP_BINARY, '-c', (string) php_ini_loaded_file()], $args);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env + getenv());
    if (!is_resource($p)) { return ''; }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
}
function render(string $user, array $query = []): string {
    $args = [dirname(__DIR__) . '/tests/render-admin.php', 'index.php'];
    foreach ($query as $k => $v) { $args[] = $k . '=' . $v; }
    return php($args, ['SHG_RENDER_AS' => $user]);
}
function child(string $what, string $user, string $qs = ''): string {
    return php([__FILE__, '--child', $what, $user, $qs]);
}
/** The page's own content (the chrome — sidebar, phone nav — is gated elsewhere). */
function mainOf(string $html): string {
    $s = strpos($html, '<main class="wrap"><h1>');
    $e = $s === false ? false : strpos($html, '</main>', $s);
    return ($s === false || $e === false) ? '' : substr($html, $s, $e - $s);
}
function cleanup(): void {
    $ids = array_map('intval', array_column(Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE 'DSHT-%'"), 'id'));
    if ($ids !== []) {
        $in = implode(',', $ids);
        foreach (['payment_screenshots', 'payments', 'booking_seats', 'booking_passengers', 'booking_legs', 'agent_ledger'] as $t) {
            try { Database::run("DELETE FROM $t WHERE booking_id IN ($in)"); } catch (Throwable $e) { /* table absent */ }
        }
        Database::run("DELETE FROM bookings WHERE id IN ($in)");
    }
    Database::run("UPDATE admins SET is_active = 0 WHERE username LIKE 'dash-t-%'");
}
function seed(array $b, array $pay = []): int {
    $id = Database::insert('bookings', $b + [
        'pnr' => 'DSHT-' . strtoupper(bin2hex(random_bytes(4))), 'contact_phone' => '9100000779',
        'total_amount' => 1000, 'status' => 'confirmed', 'confirmed_at' => date('Y-m-d H:i:s'),
    ]);
    if ($pay !== []) {
        Database::insert('payments', $pay + ['booking_id' => $id, 'payment_ref' => 'DSHT-' . bin2hex(random_bytes(6)),
            'amount' => (float) ($b['total_amount'] ?? 1000), 'currency' => (string) ($b['currency'] ?? 'INR')]);
    }
    return $id;
}
function staff(string $role): string {
    $u = 'dash-t-' . $role;
    $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
    if ($id === 0) {
        Database::insert('admins', ['username' => $u, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
            'full_name' => 'Dash Test ' . ucfirst($role), 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
    } else {
        Database::run('UPDATE admins SET role = :r, is_active = 1, must_change_pw = 0, permissions = NULL WHERE id = :i', ['r' => $role, 'i' => $id]);
    }
    return $u;
}

$today    = todayISO();
$tomorrow = addDaysISO($today, 1);

try {
    cleanup();

    /* ── 1. Cash vs online (MoneySummary) ─────────────────────────── */
    echo "\n=== 1. Collected money: cash vs online ===\n";
    $m0 = MoneySummary::window($today, $today);
    check('before: collectedCash + collectedOnline == collected', near($m0['collectedCash'] + $m0['collectedOnline'], $m0['collected']),
        inr($m0['collectedCash']) . ' + ' . inr($m0['collectedOnline']) . ' = ' . inr($m0['collected']));
    check('before: the two counts add up to collectedCount', $m0['collectedCashCount'] + $m0['collectedOnlineCount'] === $m0['collectedCount']);
    $peg = $m0['peg'];
    seed(['total_amount' => 1000], ['method' => 'cash', 'status' => 'verified']);
    seed(['total_amount' => 700],  ['method' => 'upi', 'status' => 'verified', 'utr_number' => 'DSHT' . random_int(1000000, 9999999)]);
    seed(['total_amount' => 500, 'is_cod' => 1], ['method' => 'cod', 'status' => 'verified']);          // a settled COD = cash
    seed(['total_amount' => 300, 'is_cod' => 1], ['method' => 'cod', 'status' => 'cod_pending']);       // not in yet
    seed(['total_amount' => 1600, 'currency' => 'NPR'], ['method' => 'esewa', 'status' => 'verified']); // NPR → INR at the peg
    seed(['total_amount' => 200]);                                                                       // no payment row = online
    $m1 = MoneySummary::window($today, $today);
    $dCash = $m1['collectedCash'] - $m0['collectedCash'];
    $dOnl  = $m1['collectedOnline'] - $m0['collectedOnline'];
    check('cash + settled cod land in cash', near($dCash, 1500.0), inr($dCash));
    check('UPI / eSewa (NPR at the peg) / none recorded land in online', near($dOnl, 700 + 1600 / $peg + 200), inr($dOnl));
    check('cash on boarding still to collect is in neither (codPending)', near($m1['codPending'] - $m0['codPending'], 300.0)
        && near($m1['collected'] - $m0['collected'], $dCash + $dOnl));
    check('counts: 2 cash, 3 online', $m1['collectedCashCount'] - $m0['collectedCashCount'] === 2 && $m1['collectedOnlineCount'] - $m0['collectedOnlineCount'] === 3);
    check('after: collectedCash + collectedOnline == collected (day)', near($m1['collectedCash'] + $m1['collectedOnline'], $m1['collected']));
    $mm = MoneySummary::window(date('Y-m-01'), addDaysISO(date('Y-m-01', strtotime('+1 month')), -1));
    check('after: collectedCash + collectedOnline == collected (month)', near($mm['collectedCash'] + $mm['collectedOnline'], $mm['collected']));

    /* ── 2. "To verify" is Verify Payments' own list ─────────────── */
    echo "\n=== 2. To verify = the proof list the tile opens ===\n";
    $pw = AiWatch::proofsWaiting();
    // The exact query admin/payments.php runs for its "Proof received" / "Awaiting payment" split.
    $proofExpr = "(TRIM(COALESCE(p.utr_number,'')) <> '' OR EXISTS (SELECT 1 FROM payment_screenshots ps0 WHERE ps0.booking_id = b.id))";
    $split = Database::fetch(
        "SELECT COALESCE(SUM({$proofExpr}), 0) AS proof, COUNT(*) AS n FROM bookings b
           JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
          WHERE b.status = 'pending'"
    ) ?? ['proof' => 0, 'n' => 0];
    check('proofsWaiting() == payments.php proof count', $pw['n'] === (int) $split['proof'], $pw['n'] . ' vs ' . (int) $split['proof']);
    check('proofsWaiting() await == payments.php "awaiting payment"', $pw['await'] === (int) $split['n'] - (int) $split['proof']);
    $paySrc = (string) file_get_contents(dirname(__DIR__) . '/admin/payments.php');
    check('payments.php still defines the proof rule this count copies', str_contains($paySrc, '$proofExpr = "' . $proofExpr . '"'));

    /* ── 3. The page as the owner sees it ─────────────────────────── */
    echo "\n=== 3. Dashboard render (super-admin, today) ===\n";
    $html = render('superadmin');
    $main = mainOf($html);
    check('renders without a PHP error', $main !== '' && !preg_match('~(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)~', $html), strlen($html) . ' bytes');
    $pos = [];
    foreach (['Today\'s operations', 'Tickets &amp; passengers', 'Financial', 'Agents', 'Bus &amp; route'] as $i => $h) {
        $pos[] = preg_match('~<h2 class="dash-sec"[^>]*><span class="dash-sec__n">' . ($i + 1) . '</span>' . preg_quote($h, '~') . '~', $main, $mm2, PREG_OFFSET_CAPTURE) ? $mm2[0][1] : -1;
    }
    check('five numbered sections', !in_array(-1, $pos, true), implode(',', $pos));
    $sorted = $pos; sort($sorted);
    check('…in priority order (ops → tickets → financial → agents → bus & route)', $pos === $sorted);
    foreach (['todayTrips', 'departingSoon', 'onRoute', 'departed', 'toVerify'] as $k) {
        check("live hook data-live-kpi=\"$k\" kept", str_contains($main, 'data-live-kpi="' . $k . '"'));
    }
    foreach (['data-live-pill', 'data-live-detail', 'data-live-seats', 'data-live-pax', 'id="liveFresh"'] as $hook) {
        check("live hook $hook kept", str_contains($main, $hook));
    }
    check('the 15 s poll of admin/api/live-status.php is wired', str_contains($main, "var URL = '/admin/api/live-status.php';") && str_contains($main, 'setInterval(poll, 15000)'));
    $todayTrips = Database::fetchAll(
        "SELECT s.id FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.travel_date = :t AND r.is_active = 1", ['t' => $today]);
    check('one .live-trip row per departure today', substr_count($main, 'class="live-trip" data-trip-id=') === count($todayTrips), count($todayTrips) . ' trips');
    $beds = Seats::occupiedBedsFor(array_map(static fn(array $r): int => (int) $r['id'], $todayTrips));
    $okSold = true;
    foreach ($beds as $sid => $n) {
        if (!preg_match('~data-trip-id="' . $sid . '".*?data-live-seats><strong>(\d+)</strong>~s', $main, $m3) || (int) $m3[1] !== $n) { $okSold = false; }
    }
    check('each trip shows sold in physical berths (= Seats::occupiedBedsFor)', $okSold, json_encode($beds));

    // "More figures": month tiles, 7-day chart and channel donut, folded on a phone
    preg_match_all('~<details class="dash-more" data-dash-more>(.*?)</details>~s', $main, $det);
    $more = implode('', $det[1] ?? []);
    check('two "More figures" folds', count($det[1] ?? []) === 2);
    check('…holding the month tiles, the 7-day chart and the channel donut',
        str_contains($more, 'Money · ' . date('F Y')) && str_contains($more, 'Revenue — last 7 days') && str_contains($more, 'class="donut-svg"'));
    check('…and nothing of that sits outside them', substr_count($main, 'Revenue — last 7 days') === 1 && substr_count($main, 'class="donut-svg"') === 1);
    check('…folded on a phone, open on a desk (matchMedia 820px)', str_contains($main, "window.matchMedia('(max-width:820px)').matches") && str_contains($main, "d.setAttribute('open', '')"));

    // Today's operations tiles
    $bkN = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE created_at >= :a AND created_at < :b', ['a' => $today, 'b' => $tomorrow]);
    $paxN = (int) Database::scalar(
        "SELECT COUNT(*) FROM booking_passengers bp JOIN bookings b ON b.id = bp.booking_id LEFT JOIN booking_legs bl ON bl.id = bp.leg_id
          WHERE b.created_at >= :a AND b.created_at < :b AND (bp.leg_id IS NULL OR bl.leg_type = 'outbound')", ['a' => $today, 'b' => $tomorrow]);
    check('"Bookings today" = bookings placed today', (bool) preg_match('~Bookings today</div><div class="kv">' . $bkN . '</div>~', $main), (string) $bkN);
    check('…its passenger line counts the passengers of that same set', (bool) preg_match('~<div class="ks">' . $paxN . ' passengers? · \d+ confirmed</div>~', $main), (string) $paxN);
    check('…and opens that list (bookings placed from / to today)', str_contains($main, 'href="/admin/bookings.php?from=' . $today . '&amp;to=' . $today . '"'));
    check('"To verify" shows the proof count and opens the proof list',
        str_contains($main, 'data-live-kpi="toVerify">' . AiWatch::proofsWaiting()['n'] . '<') && str_contains($main, 'href="/admin/payments.php?tab=pending&amp;sub=proof"'));
    check('"Cash to collect" opens the cash-on-boarding tab', str_contains($main, 'href="/admin/payments.php?tab=cod"'));
    check('trip tiles drill to the Trips board', substr_count($main, '<a class="kl" href="/admin/trips.php"') >= 3);
    check('each bus row links its passenger manifest', str_contains($main, '/admin/manifest.php?date=' . $today . '&amp;sid='));

    // Tickets & passengers
    check('find-a-ticket box searches the register', str_contains($main, '<form class="dash-find" method="get" action="/admin/bookings.php"') && str_contains($main, 'name="q"'));
    check('cancel / refund requests are counted with the by-hand note', str_contains($main, 'Cancel requests') && str_contains($main, 'Refund / cancel at Help Desk') && str_contains($main, 'settles every cancel and refund by hand'));
    $tbl = preg_match('~Last 20 bookings.*?<tbody>(.*?)</tbody>~s', $main, $tm) ? $tm[1] : '';
    $totalBk = (int) Database::scalar('SELECT COUNT(*) FROM bookings');
    check('the last 20 bookings (was 8)', substr_count($tbl, '<tr>') === min(20, $totalBk), substr_count($tbl, '<tr>') . ' rows');
    check('…with the lead passenger and the travel date', str_contains($main, '<th>Passenger</th>') && str_contains($tbl, 'travels '));
    check('…and Edit / Date on a live ticket (bookings.edit, like the register)', str_contains($tbl, '#edit" title="Edit') && str_contains($tbl, '/admin/reschedule.php?pnr='));

    // Financial
    check('Collected tile splits cash vs online', str_contains($main, '<b>' . Security::e(inr($m1['collectedCash'])) . ' cash · ' . Security::e(inr($m1['collectedOnline'])) . ' online</b>'));
    check('agent-wise settlements top 5 → Settlements (pending)', str_contains($main, 'Waiting to be settled · top 5') && str_contains($main, 'href="/admin/settlements.php?tab=pending"'));
    check('refund tracker tile → Refunds', str_contains($main, 'Refunds due · now') && str_contains($main, 'href="/admin/refunds.php"'));

    // Agents
    check('today\'s sellers with the today leaderboard link', str_contains($main, 'Selling today') && str_contains($main, '/admin/agent-ranking.php?range=today&amp;by=confirmed'));
    check('payouts pending + commission owed tiles', str_contains($main, 'Payouts pending') && str_contains($main, 'Commission owed · now'));

    // Bus & route
    check('fleet tile says what it counts', str_contains($main, 'Buses running') && str_contains($main, 'in the fleet (marked active)'));
    check('fill by route', str_contains($main, 'Fill by route'));
    check('cancelled-today drill carries the cancel-date window', !str_contains($main, 'Cancelled today</div><div class="kv">0') ? str_contains($main, 'cancelled_from=' . $today) : true);

    /* ── 4. ?date= snapshot: berths, not rows ─────────────────────── */
    echo "\n=== 4. Snapshot restates sold in physical berths ===\n";
    // Own the required test schedule instead of relying on another suite's order.
    $testRoute = (int) Database::scalar('SELECT id FROM routes WHERE is_active=1 ORDER BY id LIMIT 1', [], 0);
    if ($testRoute > 0) { Seats::schedule($testRoute, $tomorrow); }
    $sched = Database::fetch(
        "SELECT s.id FROM schedules s JOIN routes r ON r.id = s.route_id
          WHERE s.travel_date = :t AND r.is_active = 1 ORDER BY s.id LIMIT 1", ['t' => $tomorrow]);
    if ($sched === null) {
        check('a departure tomorrow to test on', false, 'no schedule on ' . $tomorrow);
    } else {
        $sid   = (int) $sched['id'];
        $taken = array_column(Database::fetchAll('SELECT seat_no FROM booking_seats WHERE schedule_id = :s', ['s' => $sid]), 'seat_no');
        $cabin = null;
        foreach (Seats::seatIds('sleeper', 'private') as $c) {
            if (array_intersect(Seats::physicalSeats($c, 'private', 'sleeper'), $taken) === [] && !in_array($c, $taken, true)) { $cabin = $c; break; }
        }
        $ex = array_values(array_diff(['EX18', 'EX19', 'EX20'], $taken));
        $rowsBefore = (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE schedule_id = :s AND released_at IS NULL', ['s' => $sid]);
        $bp = seed(['booking_mode' => 'private', 'total_amount' => 3800]);
        $lp = Database::insert('booking_legs', ['booking_id' => $bp, 'schedule_id' => $sid, 'leg_type' => 'outbound', 'travel_date' => $tomorrow, 'boarding_stop' => 'Test', 'drop_stop' => 'Test']);
        Database::insert('booking_seats', ['schedule_id' => $sid, 'seat_no' => (string) $cabin, 'booking_id' => $bp, 'leg_id' => $lp]);
        Database::insert('booking_passengers', ['booking_id' => $bp, 'leg_id' => $lp, 'passenger_ref' => 'DSHT-' . bin2hex(random_bytes(6)), 'seat_no' => (string) $cabin, 'full_name' => 'Dash Test Cabin', 'is_primary' => 1]);
        $bx = seed(['booking_mode' => 'sharing', 'total_amount' => 1000]);
        $lx = Database::insert('booking_legs', ['booking_id' => $bx, 'schedule_id' => $sid, 'leg_type' => 'outbound', 'travel_date' => $tomorrow, 'boarding_stop' => 'Test', 'drop_stop' => 'Test']);
        foreach (array_slice($ex, 0, 2) as $s) {
            Database::insert('booking_seats', ['schedule_id' => $sid, 'seat_no' => $s, 'booking_id' => $bx, 'leg_id' => $lx]);
        }
        $rows = (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE schedule_id = :s AND released_at IS NULL', ['s' => $sid]);
        $bedN = Seats::occupiedBedsFor([$sid])[$sid] ?? 0;
        check('seeded a private cabin (1 row, 2 berths) and 2 EX seats (rows, no berth)', $cabin !== null && $rows === $rowsBefore + 3 && $rows !== $bedN, "rows $rows, berths $bedN");
        $snap = mainOf(render('superadmin', ['date' => $tomorrow]));
        check('the snapshot is static (no live poll)', str_contains($snap, 'Static snapshot') && str_contains($snap, 'var IS_TODAY = false;'));
        $shown = preg_match('~data-trip-id="' . $sid . '".*?data-live-seats><strong>(\d+)</strong>~s', $snap, $m4) ? (int) $m4[1] : -1;
        check('snapshot shows sold in berths, not booking_seats rows', $shown === $bedN, "shown $shown, berths $bedN, rows $rows");
        $paxTrip = (int) Database::scalar(
            "SELECT COUNT(*) FROM booking_passengers bp
               JOIN booking_legs blp ON blp.booking_id = bp.booking_id AND blp.schedule_id = :s AND (bp.leg_id IS NULL OR bp.leg_id = blp.id)
               JOIN bookings bpb ON bpb.id = bp.booking_id AND bpb.status IN ('confirmed','pending','completed')", ['s' => $sid]);
        check('…and the people on board (the manifest rule)', (bool) preg_match('~data-trip-id="' . $sid . '".*?data-live-pax>' . $paxTrip . ' passengers?<~s', $snap), (string) $paxTrip);
        $liveTomorrow = json_decode(child('live', 'superadmin', 'date=' . $tomorrow), true);
        $lt = array_values(array_filter($liveTomorrow['trips'] ?? [], static fn(array $t): bool => (int) $t['id'] === $sid))[0] ?? [];
        check('…the same berths the live endpoint reports', (int) ($lt['sold'] ?? -1) === $bedN && (int) ($lt['pax'] ?? -1) === $paxTrip);
    }

    /* ── 5. live-status.php ───────────────────────────────────────── */
    echo "\n=== 5. admin/api/live-status.php ===\n";
    $live = json_decode(child('live', 'superadmin'), true);
    check('answers JSON', is_array($live) && isset($live['kpi'], $live['trips']));
    check('KPI keys kept + toVerify', is_array($live) && array_diff(['todayTrips', 'departingSoon', 'departed', 'onRoute', 'toVerify'], array_keys($live['kpi'] ?? [])) === []);
    check('toVerify == the proof list count', (int) ($live['kpi']['toVerify'] ?? -1) === AiWatch::proofsWaiting()['n']);
    check('every trip carries pax', ($live['trips'] ?? []) !== [] && array_filter($live['trips'], static fn(array $t): bool => !is_int($t['pax'] ?? null)) === []);

    /* ── 6. Every link opens for the role that sees it ────────────── */
    echo "\n=== 6. No refused page behind any link (per role) ===\n";
    $GATES = [
        'index.php' => ['dashboard.view'], 'bookings.php' => ['bookings.view'], 'booking-view.php' => ['bookings.view'],
        'reschedule.php' => ['bookings.view'], 'manifest.php' => ['bookings.view'], 'chalan.php' => ['bookings.view'],
        'quick-ticket.php' => ['bookings.view'], 'wa-routing.php' => ['bookings.view'], 'wa-pending.php' => ['bookings.view'],
        // 16 Oct 2026: SmartBook is the one booking screen, and the Book button
        // on the dashboard now reaches it (it was linked without a gate entry).
        'smartbook.php' => ['bookings.view'],
        'team.php' => ['staff.manage'], 'gps-center.php' => ['buses.view'],
        'offline-requests.php' => ['bookings.view'], 'ai-copilot.php' => ['bookings.view'], 'trips.php' => ['schedules.view'],
        'seatmap.php' => ['schedules.view'], 'buses.php' => ['schedules.view'], 'scan.php' => ['tickets.scan'],
        'payments.php' => ['payments.view'], 'refunds.php' => ['refunds.view'], 'support-inbox.php' => ['support.view'],
        'accounting.php' => ['payments.view', 'reports.view'], 'payroll.php' => ['payments.view', 'reports.view'],
        'agents.php' => ['customers.view', 'commissions.view'], 'agent.php' => ['bookings.view', 'commissions.view'],
        'settlements.php' => ['commissions.view'], 'agent-ranking.php' => ['dashboard.view'], 'promo-card.php' => ['dashboard.view'],
        'company.php' => ['dashboard.view'], 'enquiries.php' => ['customers.view'], 'routes.php' => ['routes.view'],
    ];
    $drift = [];
    foreach ($GATES as $page => $perms) {
        $src = (string) @file_get_contents(dirname(__DIR__) . '/admin/' . $page);
        foreach ($perms as $perm) {
            if (preg_match("~(admin_boot|requireAdmin)\([^;]*'" . preg_quote($perm, '~') . "'~", $src) !== 1) { $drift[] = "$page:$perm"; }
        }
    }
    check('the test\'s page-gate map matches each page\'s own gate', $drift === [], implode(', ', $drift));
    $roles = (new ReflectionClass('Auth'))->getReflectionConstant('ROLE_PERMISSIONS')->getValue();
    $byRole = [];
    foreach (['manager', 'accountant', 'operations', 'support', 'viewer'] as $role) {
        $perms = $roles[$role] ?? [];
        $page  = mainOf(render(staff($role)));
        $byRole[$role] = $page;
        preg_match_all('~href="/admin/([a-z0-9_-]+\.php)~', $page, $lm);
        $bad = [];
        foreach (array_unique($lm[1]) as $target) {
            if (!isset($GATES[$target])) { $bad[] = 'unmapped ' . $target; continue; }
            foreach ($GATES[$target] as $need) { if (!in_array($need, $perms, true)) { $bad[] = "$target needs $need"; } }
            if ($role === 'viewer' && !in_array($target, Auth::VIEWER_PAGES, true)) { $bad[] = "$target is not a viewer page"; }
        }
        check("$role: page renders and every link opens for the role", $page !== '' && $bad === [], count(array_unique($lm[1])) . ' pages linked' . ($bad ? ' — ' . implode('; ', $bad) : ''));
    }
    check('manager sees exactly three business management categories', substr_count($byRole['manager'], 'class="command-category"') === 3);
    check('support has no unified team management link', !str_contains($byRole['support'], 'href="/admin/team.php"'));
    check('manager reaches the existing team and fleet screens', str_contains($byRole['manager'], 'href="/admin/team.php"') && str_contains($byRole['manager'], 'href="/admin/gps-center.php"'));
    $v = $byRole['viewer'];
    check('viewer: no passenger name column, no Edit / Date, no sale buttons',
        !str_contains($v, '<th>Passenger</th>') && !str_contains($v, '#edit"') && !str_contains($v, 'QuickBot Ticket</a>') && !str_contains($v, '/index.php?counter=1'));
    check('viewer: still sees the five sections', substr_count($v, '<h2 class="dash-sec"') === 5);
    check('support: no company money tiles, no agent settlements', !str_contains($byRole['support'], '<span class="sg-stat__label">Collected</span>') && !str_contains($byRole['support'], 'Waiting to be settled'));
    check('operations: agent settlements yes, company money no', str_contains($byRole['operations'], 'Waiting to be settled') && !str_contains($byRole['operations'], '<span class="sg-stat__label">Collected</span>'));
    check('accountant: money tiles with the cash / online split', str_contains($byRole['accountant'], ' online</b>'));

    /* ── 7. Counter clerk ─────────────────────────────────────────── */
    echo "\n=== 7. Counter landing ===\n";
    $ctr = staff('counter');
    check('a counter is bounced off the dashboard (no sections rendered)', !str_contains(render($ctr), 'class="dash-sec"'));
    $first = static fn(string $nav): string => preg_match('~<nav class="mnav"[^>]*><a class="([^"]*)" href="([^"]*)"~', $nav, $nm) ? $nm[1] . ' ' . $nm[2] : '';
    $cNav = $first(child('nav', $ctr));
    check('counter: phone Home slot opens SmartBook (was empty)', str_starts_with($cNav, 'mn-home') && str_ends_with($cNav, '/admin/smartbook.php'), $cNav);
    $mNav = $first(child('nav', staff('manager')));
    check('manager: Home slot is still the dashboard', str_ends_with($mNav, '/admin/index.php'), $mNav);
    $agentUser = (string) Database::scalar("SELECT username FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY id LIMIT 1", [], '');
    if ($agentUser !== '') {
        $aNav = $first(child('nav', $agentUser));
        check('agent: Home slot is still the agent panel', str_ends_with($aNav, '/admin/agent.php'), $aNav);
    }
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanup();
}

echo "\n----------------------------------------\n  $PASS passed, $FAIL failed\n----------------------------------------\n\n";
exit($FAIL === 0 ? 0 : 1);
