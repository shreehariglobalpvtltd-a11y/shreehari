<?php
/**
 * =====================================================================
 *  chalani-register-test.php — the Tickets register as an Excel-style
 *  chalani (15 Oct 2026, owner: Feature 3 + the Feature 1 / 4 pieces that
 *  live in admin/bookings.php).
 *
 *  Locks in:
 *    - Seats::storageIdsForLabel(): the printed grid label -> stored id,
 *      round-trip exact for every sharing berth and private cabin;
 *    - the register: sort on Ticket / Passenger / Route / Seller / Payment,
 *      the column funnels (f_ticket / f_pax / f_mob / f_seat - the seat as
 *      PRINTED, mode-aware, released seats ignored), the global search
 *      finding a printed seat label, ?mine=1, the month picker, Columns:
 *      All, the phone Table view, the travel-day chips, the payment pill
 *      (b.expires_at selected), Quick Edit (bookings.edit only);
 *    - the cards (passengers, cash pending, online paid, cancelled,
 *      refunded) against a fixture with known money, and the Revenue gate
 *      (reports.view or a scoped viewer only);
 *    - the monthly hisab: totals, the per-seller table ONLY for an unscoped
 *      reports.view holder, money hidden from an unscoped counter / ops, the
 *      CSV behind the export gate;
 *    - export.php: Ticket no + Payment status + Seat ids columns, live seats
 *      only, printed labels, primary-passenger fallback, the =+-@ guard,
 *      the same column filters as the screen, XLSX through _export.php;
 *    - the counter's "Today at my desk" row;
 *    - admin/api/search.php: printed seat labels find the passenger, and
 *      the SHG-code Agent hit needs commissions.view.
 *
 *  Pages are rendered through tests/render-admin.php (a CLI child with a
 *  faked staff session, SHG_RENDER_AS=<username>). Every fixture row is
 *  removed in a finally block and on shutdown.
 *
 *    SHG_DB=lt_f3 php -c .claude/php-dev.ini tests/chalani-register-test.php
 *
 *  CLI only. Refuses to run unless DB_NAME is a test database. Exit 1 on
 *  any failure.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require __DIR__ . '/../includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once dirname(__DIR__) . '/admin/_booking_filters.php';

if (!str_contains((string) DB_NAME, 'test') && !str_starts_with((string) DB_NAME, 'lt_')) {
    fwrite(STDERR, "Refusing: DB_NAME (" . DB_NAME . ") is not a test database\n");
    exit(2);
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/** Run a PHP child with the current ini + SHG_DB; returns out / err / code. */
function child(array $argv, array $env = []): array {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '');
    foreach ($argv as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env + getenv());
    if (!is_resource($p)) { return ['out' => '', 'err' => 'proc_open failed', 'code' => 255]; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['out' => $out, 'err' => $err, 'code' => proc_close($p)];
}
/** Render admin/<page> as <username> with GET args. */
function render(string $page, array $args, string $as): array {
    $a = [__DIR__ . '/render-admin.php', $page];
    foreach ($args as $k => $v) { $a[] = $k . '=' . $v; }
    return child($a, ['SHG_RENDER_AS' => $as]);
}
/** No PHP error text and the page finished. */
function cleanPage(array $r): bool {
    return $r['code'] === 0
        && !preg_match('/Application error|Warning:|Fatal error|Notice:|Deprecated:|Uncaught/i', $r['out'] . $r['err'])
        && str_contains($r['out'], '</html>');
}
/** The booking ids of the register rows, in page order. */
function rowIds(string $html): array {
    preg_match_all('/<tr data-bid="(\d+)">/', $html, $m);
    return array_map('intval', $m[1]);
}
/** One register row's HTML. */
function rowHtml(string $html, int $id): string {
    $s = strpos($html, '<tr data-bid="' . $id . '">');
    if ($s === false) { return ''; }
    $e = strpos($html, '</tr>', $s);
    return substr($html, $s, $e === false ? 4000 : $e - $s);
}
/** A card's value: <div class="k">Label</div> ... <div class="v" ...>VALUE</div>. */
function cardValue(string $html, string $label): ?string {
    return preg_match('#<div class="k">' . preg_quote($label, '#') . '</div>\s*<div class="v"[^>]*>([^<]*)</div>#u', $html, $m) === 1 ? trim($m[1]) : null;
}
/** CSV body -> list of rows (BOM stripped). Fixture values hold no newlines. */
function csvParse(string $body): array {
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
    $out = [];
    foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
        if ($line !== '') { $out[] = str_getcsv($line, ',', '"', '\\'); }
    }
    return $out;
}

/* ---------------------------------------------------------------------
 *  1. Seats::storageIdsForLabel — the inverse of displayLabel
 * ------------------------------------------------------------------- */
echo "\n=== Seats::storageIdsForLabel (printed label -> stored id) ===\n";
$bad = [];
foreach (['sharing', 'private'] as $mode) {
    foreach (Seats::seatIds('sleeper', $mode) as $id) {
        $lbl  = Seats::displayLabel($id, 'sleeper', $mode);
        $back = Seats::storageIdsForLabel($lbl, 'sleeper', $mode);
        if ($back !== [$id]) { $bad[] = "$mode $id -> $lbl -> " . json_encode($back); }
    }
}
check('every sharing berth and private cabin round-trips exactly (108 ids)', $bad === [], implode('; ', array_slice($bad, 0, 3)));
$cases = [
    ['B5', 'sharing', ['L11']], ['B5', 'private', ['L6']], ['A7', 'sharing', ['U1']], ['F12', 'sharing', ['U36']],
    ['F12', 'private', ['U18']], ['B5-6', 'sharing', ['L11', 'L12']], ['B5-6', 'private', ['L6']],
    [' b5 ', 'sharing', ['L11']], ['A1+B1', 'sharing', ['L1', 'L7']], ['3C', 'sharing', ['3C']], ['EX1', 'private', ['EX1']],
    ['L11', 'sharing', []], ['G1', 'sharing', []], ['Ram', 'sharing', []], ['9876', 'sharing', []], ['', 'sharing', []], ['B6-2', 'sharing', []],
];
foreach ($cases as [$in, $mode, $want]) {
    $got = Seats::storageIdsForLabel($in, 'sleeper', $mode);
    check("'" . $in . "' ($mode) -> " . json_encode($want), $got === $want, 'got ' . json_encode($got));
}
$pp = [];
check('a name is not a seat label (no SQL arm)', shg_seat_label_match('Ram', 'x.seat_no', $pp, 't') === null && $pp === []);
$pp = [];
$arm = shg_seat_label_match('B5', 'x.seat_no', $pp, 't');
check('a label arm is per booking mode, with its own placeholders',
    $arm !== null && str_contains($arm, "b.booking_mode = 'private'") && ($pp['ts0'] ?? '') === 'L11' && ($pp['tp0'] ?? '') === 'L6',
    (string) $arm);

/* ---------------------------------------------------------------------
 *  2. Fixture: May 2021 (a window no other data reaches) + one sale today
 * ------------------------------------------------------------------- */
$tag    = 'CHRX' . random_int(1000, 9999);
$sched  = [];
$admins = [];
$cleanup = static function () use ($tag, &$sched, &$admins): void {
    try {
        Database::query('DELETE FROM bookings WHERE pnr LIKE :p', ['p' => $tag . '-%']);   // cascades legs, seats, pax, payments, tickets
        foreach ($sched as $sid) { Database::query('DELETE FROM schedules WHERE id = :i', ['i' => $sid]); }
        foreach ($admins as $aid) { Database::query('DELETE FROM admins WHERE id = :i', ['i' => $aid]); }
        $sched = [];
        $admins = [];
    } catch (Throwable $e) {
        fwrite(STDERR, 'cleanup failed: ' . $e->getMessage() . "\n");
    }
};
register_shutdown_function($cleanup);

try {
    echo "\n=== Fixture ===\n";
    $routeId = (int) Database::scalar("SELECT id FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);
    if ($routeId === 0) { throw new RuntimeException('no active sleeper route to hang the fixture on'); }
    $taken = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE created_at >= '2021-05-01' AND created_at < '2021-06-01'", [], 0)
           + (int) Database::scalar("SELECT COUNT(*) FROM schedules WHERE route_id = :r AND travel_date IN ('2021-05-20','2021-05-21')", ['r' => $routeId], 0)
           + (int) Database::scalar('SELECT COUNT(*) FROM schedules WHERE route_id = :r AND travel_date = CURDATE() AND slot = 7', ['r' => $routeId], 0);
    check('fixture window (May 2021) is empty before the test', $taken === 0, "$taken pre-existing rows");

    // Staff of every relevant role (usernames carry the tag; removed at the end).
    $mkAdmin = static function (string $u, string $role, string $name) use (&$admins): string {
        $user = strtolower($u);
        $admins[] = (int) Database::insert('admins', [
            'username' => $user, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
            'full_name' => $name, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0,
        ]);
        return $user;
    };
    $acct    = $mkAdmin($tag . '-acct', 'accountant', 'Chr Accounts');
    $ops     = $mkAdmin($tag . '-ops', 'operations', 'Chr Ops');
    $counter = $mkAdmin($tag . '-ctr', 'counter', 'Chr Counter');
    $agent   = $mkAdmin($tag . '-agt', 'agent', 'Chr Agent');
    [$idAcct, $idOps, $idCtr, $idAgt] = $admins;

    $mkSched = static function (string $date, int $slot, ?string $dep = null) use ($routeId, &$sched): int {
        $id = (int) Database::insert('schedules', [
            'route_id' => $routeId, 'travel_date' => $date, 'slot' => $slot, 'status' => 'scheduled',
            'total_seats' => 72, 'dep_time_override' => $dep,
        ]);
        $sched[] = $id;
        return $id;
    };
    $S1 = $mkSched('2021-05-20', 1);
    $S2 = $mkSched('2021-05-21', 1);
    $S3 = $mkSched(todayISO(), 7, '23:59:00');

    /** One booking: [pnr suffix, seller, mode, status, amount, phone, sched, date, seat, released?, pax, primary?, pay method, pay status, extra cols] */
    $mk = static function (string $sfx, ?int $seller, string $mode, string $status, float $amt, string $phone, int $sid, string $date,
                           string $seat, bool $released, string $pax, bool $primary, string $method, string $pst, array $extra = []) use ($tag): int {
        $bid = (int) Database::insert('bookings', $extra + [
            'pnr' => $tag . '-' . $sfx, 'sold_by_admin_id' => $seller, 'trip_type' => 'oneway', 'booking_mode' => $mode,
            'contact_phone' => $phone, 'total_amount' => $amt, 'status' => $status,
            'source' => $seller === null ? 'web' : 'admin', 'created_at' => '2021-05-15 10:00:00',
        ]);
        $leg = (int) Database::insert('booking_legs', [
            'booking_id' => $bid, 'schedule_id' => $sid, 'leg_type' => 'outbound', 'travel_date' => $date,
            'boarding_stop' => 'Surat', 'seat_count' => 1,
        ]);
        Database::insert('booking_seats', [
            'schedule_id' => $sid, 'seat_no' => $seat, 'booking_id' => $bid, 'leg_id' => $leg,
            'released_at' => $released ? '2021-05-16 10:00:00' : null,
        ]);
        Database::insert('booking_passengers', [
            'booking_id' => $bid, 'leg_id' => $leg, 'passenger_ref' => 'PX-' . bin2hex(random_bytes(5)),
            'seat_no' => $seat, 'full_name' => $pax, 'is_primary' => $primary ? 1 : 0,
        ]);
        Database::insert('payments', [
            'booking_id' => $bid, 'payment_ref' => 'PAY-' . bin2hex(random_bytes(6)), 'method' => $method,
            'amount' => $amt, 'currency' => 'INR', 'status' => $pst,
        ]);
        return $bid;
    };
    $B1 = $mk('B1', $idAgt, 'sharing', 'confirmed', 2000, '9811100001', $S1, '2021-05-20', 'L11', false, 'Chr Alpha', true, 'upi', 'verified', ['confirmed_at' => '2021-05-15 10:05:00']);
    $B2 = $mk('B2', $idCtr, 'private', 'confirmed', 3800, '9811100002', $S1, '2021-05-20', 'L9', false, 'Chr Beta', false, 'cod', 'cod_pending', ['is_cod' => 1]);
    $B3 = $mk('B3', null, 'sharing', 'cancelled', 2000, '9811100003', $S1, '2021-05-20', 'U1', true, 'Chr Gamma', true, 'upi', 'verified', ['refund_status' => 'processed', 'refund_amount' => 1500]);
    $B4 = $mk('B4', null, 'sharing', 'pending', 2000, '9811100004', $S1, '2021-05-20', 'L21', false, 'Chr Delta', true, 'upi', 'pending');
    $B5 = $mk('B5', $idAgt, 'sharing', 'cancelled', 2000, '9811100005', $S1, '2021-05-20', 'L12', true, 'Chr Epsilon', true, 'cash', 'verified', ['refund_status' => 'pending', 'refund_amount' => 1000]);
    $B6 = $mk('B6', $idOps, 'sharing', 'confirmed', 2000, '9811100006', $S2, '2021-05-21', 'L9', false, '=Chr Zeta', true, 'esewa', 'verified');
    $B7 = $mk('B7', null, 'sharing', 'pending', 2000, '9811100007', $S1, '2021-05-20', 'L22', false, 'Chr Eta', true, 'upi', 'pending', ['expires_at' => '2030-01-01 00:00:00']);
    // Today: the counter's own cash sale on today's bus (the desk row).
    $B8 = $mk('B8', $idCtr, 'sharing', 'confirmed', 1800, '9811100008', $S3, todayISO(), 'L1', false, 'Chr Theta', true, 'cod', 'cod_pending',
              ['is_cod' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    Database::insert('tickets', ['booking_id' => $B1, 'ticket_number' => $tag . '-TK1', 'qr_payload' => 'test',
                                 'qr_hash' => hash('sha256', $tag . 'tk1'), 'issued_at' => '2021-05-15 10:06:00']);
    $win = ['from' => '2021-05-01', 'to' => '2021-05-31'];
    check('fixture written (8 bookings, 4 staff, 3 departures)', $B8 > 0 && count($admins) === 4 && count($sched) === 3);

    /* -----------------------------------------------------------------
     *  3. Register: cards, money gate, payment pill, seats, Quick Edit
     * --------------------------------------------------------------- */
    echo "\n=== Register cards follow the filter (accounts view) ===\n";
    $r = render('bookings.php', $win, $acct);
    $h = $r['out'];
    check('renders with no PHP error', cleanPage($r), substr(trim($r['err']), 0, 200));
    $ids = rowIds($h);
    sort($ids);
    check('7 tickets in May 2021', $ids === [$B1, $B2, $B3, $B4, $B5, $B6, $B7], json_encode($ids));
    check('Tickets card = 7', cardValue($h, 'Tickets') === '7', (string) cardValue($h, 'Tickets'));
    check('Passengers card = 5 live seats (released + cancelled left out)', cardValue($h, 'Passengers') === '5', (string) cardValue($h, 'Passengers'));
    check('Revenue card = confirmed money ' . inr(7800), cardValue($h, 'Revenue') === inr(7800), (string) cardValue($h, 'Revenue'));
    check('Online paid = verified non-cash ' . inr(4000), cardValue($h, 'Online paid') === inr(4000), (string) cardValue($h, 'Online paid'));
    check('Cash pending = ' . inr(3800) . ' (latest payment cod_pending)', cardValue($h, 'Cash pending') === inr(3800), (string) cardValue($h, 'Cash pending'));
    check('… and links to the cash-due list', str_contains($h, 'pay_status=cod_pending'));
    check('Cancelled = 2', cardValue($h, 'Cancelled') === '2' && str_contains($h, inr(4000) . ' of tickets'), (string) cardValue($h, 'Cancelled'));
    check('Refunded = ' . inr(1500) . ', 1 pending ' . inr(1000) . ' (read-only, links the Refund desk)',
        cardValue($h, 'Refunded') === inr(1500) && str_contains($h, '1 pending · ' . inr(1000)) && str_contains($h, 'href="/admin/refunds.php"'));
    check('the payment pill: proof parked (expires_at NULL) = Verification pending', str_contains(rowHtml($h, $B4), 'Verification pending'));
    check('… an unpaid hold (expires_at set) = Unverified, not Verification pending',
        str_contains(rowHtml($h, $B7), 'Unverified') && !str_contains(rowHtml($h, $B7), 'Verification pending'));
    check('a private cabin prints its grid label (L9 -> C5-6)', str_contains(rowHtml($h, $B2), 'C5-6'));
    check('accounts (no bookings.edit) gets no Quick Edit', !str_contains($h, '#quick'));
    check('the travel-day chips are on the page', str_contains($h, 'Today&#039;s departures') && str_contains($h, 'Tomorrow&#039;s departures'));
    check('quick chips sit outside the folded Dates box (phones see them)',
        ($q1 = strpos($h, 'class="quick-row"')) !== false && ($q2 = strpos($h, 'id="advFilters"')) !== false && $q1 < $q2);
    check('every column head has its funnel', str_contains($h, 'id="fbTicket"') && str_contains($h, 'id="fbPax"') && str_contains($h, 'id="fbSeat"'));

    echo "\n=== Revenue / money gate ===\n";
    $ro = render('bookings.php', $win, $ops);
    check('operations (no reports.view) renders', cleanPage($ro));
    check('… sees no Revenue / Online paid card', cardValue($ro['out'], 'Revenue') === null && cardValue($ro['out'], 'Online paid') === null);
    check('… keeps the counts and the cash to collect', cardValue($ro['out'], 'Tickets') === '7' && cardValue($ro['out'], 'Cash pending') === inr(3800));
    check('… and gets Quick Edit -> booking-view #quick on an open ticket',
        str_contains($ro['out'], 'booking-view.php?pnr=' . $tag . '-B1#quick') && str_contains($ro['out'], 'Quick Edit')
        && !str_contains(rowHtml($ro['out'], $B3), '#quick'));
    $rc = render('bookings.php', $win, $counter);
    check('an unscoped counter sees no Revenue card', cleanPage($rc) && cardValue($rc['out'], 'Revenue') === null);
    $rcm = render('bookings.php', $win + ['mine' => '1'], $counter);
    check('?mine=1 = only my sales (the counter sold B2 in May)', rowIds($rcm['out']) === [$B2], json_encode(rowIds($rcm['out'])));
    check('… and with the list pinned to my sales the Revenue card shows', cardValue($rcm['out'], 'Revenue') === inr(3800), (string) cardValue($rcm['out'], 'Revenue'));
    $ra = render('bookings.php', $win, $agent);
    $aIds = rowIds($ra['out']);
    sort($aIds);
    check('an agent sees only their own sales, with their own Revenue', $aIds === [$B1, $B5] && cardValue($ra['out'], 'Revenue') === inr(2000), json_encode($aIds));

    /* -----------------------------------------------------------------
     *  4. Column funnels + seat labels + the global search
     * --------------------------------------------------------------- */
    echo "\n=== Column filters (the funnel under each head) ===\n";
    $flt = [
        'f_seat=B5 (sharing L11) -> B1 only, not the private L6 cabin'       => [['f_seat' => 'B5'], [$B1]],
        'f_seat=C5 (a bed of the private cabin L9) -> B2'                     => [['f_seat' => 'C5'], [$B2]],
        'f_seat=C5-6 (the cabin as printed) -> B2'                            => [['f_seat' => 'C5-6'], [$B2]],
        'f_seat=B3 (sharing L9) -> B6, not the private L9 (= C5-6)'           => [['f_seat' => 'B3'], [$B6]],
        'f_seat=L9 (the stored id) -> both L9 bookings'                       => [['f_seat' => 'L9'], [$B2, $B6]],
        'f_seat=A7 -> nothing (U1 was released)'                              => [['f_seat' => 'A7'], []],
        'f_pax=Beta -> B2 (no is_primary row still found)'                    => [['f_pax' => 'Beta'], [$B2]],
        'f_mob=98111-00004 -> B4 (digits only)'                               => [['f_mob' => '98111-00004'], [$B4]],
        'f_ticket=TK1 -> the ticket-numbered booking'                         => [['f_ticket' => 'TK1'], [$B1]],
        'f_ticket=<pnr> -> that booking'                                      => [['f_ticket' => $tag . '-B7'], [$B7]],
        'q=D4 (global search, printed seat of L22) -> B7'                     => [['q' => 'D4'], [$B7]],
        'q=c5-6 (lower case cabin label) -> B2'                               => [['q' => 'c5-6'], [$B2]],
        'f_seat + f_pax combine (AND)'                                        => [['f_seat' => 'L9', 'f_pax' => 'Zeta'], [$B6]],
    ];
    foreach ($flt as $label => [$args, $want]) {
        $rr  = render('bookings.php', $win + $args, $acct);
        $got = rowIds($rr['out']);
        sort($got);
        sort($want);
        check($label, cleanPage($rr) && $got === $want, 'got ' . json_encode($got));
    }
    $rr = render('bookings.php', $win + ['f_seat' => 'B5'], $acct);
    check('the export link carries the column filter', str_contains($rr['out'], 'export.php?from=2021-05-01&amp;to=2021-05-31&amp;f_seat=B5'));
    check('… the funnel shows it is on, with a one-tap remove chip', str_contains($rr['out'], 'class="th-f on"') && str_contains($rr['out'], 'Seat: B5 ✕'));
    check('… and the column-filter field is prefilled', str_contains($rr['out'], 'name="f_seat" value="B5"'));
    $rx = render('bookings.php', $win + ['f_seat' => "B5' OR 1=1 --"], $acct);
    check('a hostile seat filter is refused, not run', cleanPage($rx) && count(rowIds($rx['out'])) === 7);
    // The per-traveller register (admin/passengers.php) searches seats the same way.
    $pw = ['from' => '2021-05-01', 'to' => '2021-05-31', 'f' => '1'];
    $rp = render('passengers.php', $pw + ['q' => 'D4'], $acct);
    check('passengers.php q=D4 (printed) finds the L22 traveller only',
        cleanPage($rp) && str_contains($rp['out'], $tag . '-B7') && !str_contains($rp['out'], $tag . '-B1'));
    $rp = render('passengers.php', $pw + ['q' => 'L9'], $acct);
    check('passengers.php q=L9 (stored) finds both L9 travellers',
        str_contains($rp['out'], $tag . '-B2') && str_contains($rp['out'], $tag . '-B6') && !str_contains($rp['out'], $tag . '-B7'));
    $rp = render('passengers.php', $pw + ['q' => 'Alpha'], $acct);
    check('passengers.php name search unchanged', str_contains($rp['out'], $tag . '-B1') && !str_contains($rp['out'], $tag . '-B2'));

    /* -----------------------------------------------------------------
     *  5. Sorting, month picker, all columns, phone table
     * --------------------------------------------------------------- */
    echo "\n=== Sort / month / columns / phone sheet ===\n";
    $pos = static function (string $html, array $ids): array {
        $o = [];
        foreach ($ids as $i) { $o[] = (int) strpos($html, '<tr data-bid="' . $i . '">'); }
        return $o;
    };
    $rs = render('bookings.php', $win + ['sort' => 'pax', 'dir' => 'asc'], $acct);
    $p  = $pos($rs['out'], [$B1, $B2, $B4]);
    check('sort=pax asc: Alpha, Beta, Delta in order', cleanPage($rs) && $p[0] < $p[1] && $p[1] < $p[2], json_encode($p));
    $rs = render('bookings.php', $win + ['sort' => 'pax', 'dir' => 'desc'], $acct);
    $p  = $pos($rs['out'], [$B1, $B2, $B4]);
    check('sort=pax desc flips it', $p[0] > $p[1] && $p[1] > $p[2], json_encode($p));
    $rs = render('bookings.php', $win + ['sort' => 'seller', 'dir' => 'asc'], $acct);
    $p  = $pos($rs['out'], [$B1, $B2, $B6]);
    check('sort=seller asc: Chr Agent, Chr Counter, Chr Ops', $p[0] < $p[1] && $p[1] < $p[2], json_encode($p));
    foreach (['ticket', 'route', 'pay'] as $sk) {
        $rs = render('bookings.php', $win + ['sort' => $sk], $acct);
        check("sort=$sk renders all 7 rows and marks its head", cleanPage($rs) && count(rowIds($rs['out'])) === 7 && str_contains($rs['out'], 'class="on" href="/admin/bookings.php?from=2021-05-01&amp;to=2021-05-31&amp;sort=' . $sk . '&amp;dir=asc"'));
    }
    $rs = render('bookings.php', $win + ['sort' => 'nonsense; DROP'], $acct);
    check('an unknown sort key falls back to newest first', cleanPage($rs) && count(rowIds($rs['out'])) === 7);
    $rm = render('bookings.php', ['month' => '2021-05'], $acct);
    check('?month=2021-05 = the whole month (7 tickets) and the picker shows it',
        count(rowIds($rm['out'])) === 7 && str_contains($rm['out'], 'name="month" value="2021-05"'));
    $rm = render('bookings.php', ['month' => '2021-13'], $acct);
    check('an impossible month is ignored (no date filter)', cleanPage($rm) && !str_contains($rm['out'], 'name="month" value="2021-13"'));
    $rcol = render('bookings.php', $win + ['cols' => 'all'], $acct);
    check('Columns: All adds stops, desk, email, cabin, confirmed at, refund',
        str_contains($rcol['out'], '<th>Confirmed at</th>') && str_contains($rcol['out'], '<th>Refund</th>') && str_contains($rcol['out'], '<th>Desk</th>')
        && str_contains(rowHtml($rcol['out'], $B3), 'Processed · ' . inr(1500)));
    check('… Minimal is the default', !str_contains($h, '<th>Confirmed at</th>'));
    $rt = render('bookings.php', $win + ['view' => 'table'], $acct);
    check('?view=table renders the sideways sheet (no-card) with the Ticket column pinned',
        str_contains($rt['out'], '<table class="no-card tk-sheet">') && str_contains($rt['out'], 'class="tk-first"'));
    check('… cards stay the default', str_contains($h, '<table class="card-table">'));
    check('view / cols / hisab ride on the paging links but never on the export link',
        str_contains($rt['out'], 'name="view" value="table"') && !preg_match('/export\.php\?[^"]*view=table/', $rt['out']));

    /* -----------------------------------------------------------------
     *  6. Monthly hisab
     * --------------------------------------------------------------- */
    echo "\n=== Monthly hisab ===\n";
    $rh = render('bookings.php', $win + ['hisab' => '1'], $acct);
    $hh = $rh['out'];
    check('hisab renders for accounts', cleanPage($rh) && str_contains($hh, 'aria-label="Monthly hisab"'));
    check('… per-seller rows: Online / Agent / Counter / Office',
        str_contains($hh, 'Online (website)') && str_contains($hh, 'Agent · Chr Agent') && str_contains($hh, 'Counter · Chr Counter') && str_contains($hh, 'Office · Chr Ops'));
    check('… totals: 7 tickets, 5 passengers, ' . inr(7800) . ' revenue',
        str_contains($hh, '<span>Tickets</span><b>7</b>') && str_contains($hh, '<span>Passengers</span><b>5</b>') && str_contains($hh, '<span>Revenue</span><b>' . inr(7800) . '</b>'));
    // review fix: an unpaid hold with no proof (B7, expires_at set) is not money to come
    check('… money still to come in: cash 1 · ' . inr(3800) . ', unverified 1 · ' . inr(2000) . ' (the abandoned hold left out)',
        str_contains($hh, '<b>1 · ' . inr(3800) . '</b>') && str_contains($hh, '<b>1 · ' . inr(2000) . '</b>'));
    check('… display only - no refund / cancel control inside it',
        !preg_match('#aria-label="Monthly hisab".*?(<form|<button).*?</section>#s', $hh));
    $rho = render('bookings.php', $win + ['hisab' => '1'], $ops);
    check('operations: hisab totals but NO per-seller table and no money',
        cleanPage($rho) && str_contains($rho['out'], '<span>Tickets</span><b>7</b>') && !str_contains($rho['out'], 'Online (website)')
        && !str_contains($rho['out'], '<span>Revenue</span>') && str_contains($rho['out'], 'Money figures are for the accounts desk'));
    $rha = render('bookings.php', $win + ['hisab' => '1'], $agent);
    check('an agent: own totals only (2 tickets), no per-seller table',
        str_contains($rha['out'], '<span>Tickets</span><b>2</b>') && !str_contains($rha['out'], 'Online (website)'));
    $rhc = render('bookings.php', $win + ['hisab' => '1', 'hx' => 'csv'], $acct);
    $hc  = csvParse($rhc['out']);
    $last = $hc[count($hc) - 1] ?? [];
    check('hisab CSV: header + 4 sellers + TOTAL', count($hc) === 6 && ($hc[0][0] ?? '') === 'Seller', 'rows=' . count($hc));
    check('… TOTAL = 7 tickets, revenue 7800', str_starts_with((string) ($last[0] ?? ''), 'TOTAL') && (int) ($last[1] ?? 0) === 7 && (float) ($last[3] ?? 0) === 7800.0, json_encode($last));
    $rhco = render('bookings.php', $win + ['hisab' => '1', 'hx' => 'csv'], $ops);
    check('hisab CSV needs the export right (operations gets the page, not a file)', str_contains($rhco['out'], '</html>') && !str_starts_with(ltrim($rhco['out'], "\xEF\xBB\xBF"), 'Seller'));

    /* -----------------------------------------------------------------
     *  7. export.php
     * --------------------------------------------------------------- */
    echo "\n=== export.php = the register ===\n";
    $re = render('export.php', $win, $acct);
    $ec = csvParse($re['out']);
    $hd = $ec[0] ?? [];
    $col = static fn(string $n): int => (int) array_search($n, $hd, true);
    check('CSV has 7 data rows (same as the screen)', count($ec) === 8, 'rows=' . (count($ec) - 1));
    check('… with Ticket no, Payment status and Seat ids columns', in_array('Ticket no', $hd, true) && in_array('Payment status', $hd, true) && in_array('Seat ids', $hd, true));
    $byPnr = [];
    foreach (array_slice($ec, 1) as $row) { $byPnr[(string) $row[0]] = $row; }
    $e2 = $byPnr[$tag . '-B2'] ?? [];
    check('B2: printed label C5-6, raw id L9, first passenger, Cash due',
        ($e2[$col('Seats')] ?? '') === 'C5-6' && ($e2[$col('Seat ids')] ?? '') === 'L9'
        && ($e2[$col('Passenger')] ?? '') === 'Chr Beta' && ($e2[$col('Payment status')] ?? '') === 'Cash due', json_encode($e2));
    $e3 = $byPnr[$tag . '-B3'] ?? [];
    check('B3: the released seat is not exported (0 seats)', ($e3[$col('Seats')] ?? 'x') === '' && ($e3[$col('Seat count')] ?? '') === '0', json_encode($e3));
    check('B1: Ticket no exported', (($byPnr[$tag . '-B1'] ?? [])[$col('Ticket no')] ?? '') === $tag . '-TK1');
    check('B4 / B7: Verification pending vs Unverified',
        (($byPnr[$tag . '-B4'] ?? [])[$col('Payment status')] ?? '') === 'Verification pending'
        && (($byPnr[$tag . '-B7'] ?? [])[$col('Payment status')] ?? '') === 'Unverified');
    check('B6: a name typed as a formula is neutralised (=+-@ guard)', (($byPnr[$tag . '-B6'] ?? [])[$col('Passenger')] ?? '') === "'=Chr Zeta");
    foreach (['f_seat' => ['C5-6', 1], 'f_pax' => ['Chr', 7], 'f_mob' => ['100006', 1], 'f_ticket' => ['TK1', 1]] as $fk => [$fv, $n]) {
        $rf = render('export.php', $win + [$fk => $fv], $acct);
        check("export honours $fk=$fv -> $n row(s)", count(csvParse($rf['out'])) - 1 === $n, 'rows=' . (count(csvParse($rf['out'])) - 1));
    }
    $rf = render('export.php', $win + ['mine' => '1'], $acct);
    check('export honours mine=1 (accounts sold nothing)', count(csvParse($rf['out'])) - 1 === 0);
    $rf = render('export.php', $win + ['q' => 'D4'], $acct);
    check('export honours the printed-seat global search (q=D4 -> 1)', count(csvParse($rf['out'])) - 1 === 1);
    $rx = render('export.php', $win + ['format' => 'xlsx'], $acct);
    check('xlsx through the shared writer', str_starts_with($rx['out'], 'PK') || count(csvParse($rx['out'])) === 8);
    $rce = render('export.php', $win, $counter);
    check('an unscoped counter still cannot export (reports.export)', !str_starts_with(ltrim($rce['out'], "\xEF\xBB\xBF"), 'PNR'));
    $rae = render('export.php', $win, $agent);
    check('an agent exports only their own sales', count(csvParse($rae['out'])) - 1 === 2);

    /* -----------------------------------------------------------------
     *  8. Counter landing: Today at my desk
     * --------------------------------------------------------------- */
    echo "\n=== Counter: Today at my desk ===\n";
    $rd = render('bookings.php', [], $counter);
    $dh = $rd['out'];
    check('counter landing renders', cleanPage($rd), substr(trim($rd['err']), 0, 200));
    check('… shows Today at my desk', str_contains($dh, 'Today at my desk'));
    check('… my sales today = 1 ticket', (bool) preg_match('#My sales today</span><span class="v">1 ticket</span>#u', $dh));
    // Oracle: the Cash book's "Pending" rule for today's buses (other test data may already hold some).
    $due = Database::fetch(
        "SELECT COUNT(*) n, COALESCE(SUM(b.total_amount),0) amt FROM bookings b
           JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
          WHERE b.is_cod = 1 AND l.travel_date = CURDATE() AND b.status NOT IN ('cancelled','rejected','expired')
            AND COALESCE((SELECT p.status FROM payments p WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1), '') <> 'verified'"
    ) ?? ['n' => 0, 'amt' => 0];
    check('… cash to collect on today\'s buses = ' . (int) $due['n'] . ' · ' . inr((float) $due['amt']) . ' (incl. the fixture ' . inr(1800) . ')',
        (int) $due['n'] >= 1 && (bool) preg_match('#Cash to collect today</span><span class="v">' . (int) $due['n'] . ' tickets?</span><span class="s">'
            . preg_quote(inr((float) $due['amt']), '#') . ' at boarding#u', $dh));
    check('… links the cash book for today', str_contains($dh, 'cash-book.php?from=' . todayISO() . '&amp;to=' . todayISO() . '&amp;status=pending'));
    check('… has the SmartBook / Cash book / Manifest buttons',
        str_contains($dh, 'href="/admin/smartbook.php"') && str_contains($dh, 'href="/admin/cash-book.php"') && str_contains($dh, 'href="/admin/manifest.php"'));
    check('… and the next bus with its free berths', (bool) preg_match('#Next bus · [0-9:]{5}[^<]*</span><span class="v">\d+ berths free#u', $dh));
    check('… "my sales" links to ?mine=1 for today', str_contains($dh, 'bookings.php?mine=1&amp;from=' . todayISO()));
    check('the desk row is for the counter only', !str_contains(render('bookings.php', [], $acct)['out'], 'Today at my desk'));
    $rdq = render('bookings.php', ['q' => 'x'], $counter);
    check('… and not on a search', !str_contains($rdq['out'], 'Today at my desk'));

    /* -----------------------------------------------------------------
     *  9. admin/api/search.php
     * --------------------------------------------------------------- */
    echo "\n=== Top-bar search (admin/api/search.php) ===\n";
    $runner = sys_get_temp_dir() . '/shg_chr_api_' . getmypid() . '.php';
    file_put_contents($runner, '<?php
declare(strict_types=1);
$_GET = json_decode((string) base64_decode($argv[1]), true) ?: [];
$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["SCRIPT_NAME"] = "/admin/api/search.php";
$_SERVER["REQUEST_URI"] = "/admin/api/search.php"; $_SERVER["HTTP_HOST"] = "localhost";
define("SHG_APP", true);
require_once ' . var_export(dirname(__DIR__) . '/includes/bootstrap.php', true) . ';
$a = Database::fetch("SELECT * FROM admins WHERE is_active = 1 AND username = :u", ["u" => $argv[2]]);
$_SESSION[ADMIN_SESSION_KEY] = ["id" => (int) $a["id"], "username" => (string) $a["username"], "full_name" => (string) $a["full_name"],
    "role" => (string) $a["role"], "permissions" => jsonColumn($a["permissions"] ?? null), "last_seen" => time()];
require ' . var_export(dirname(__DIR__) . '/admin/api/search.php', true) . ';
');
    $api = static function (array $get, string $as) use ($runner): array {
        $r = child([$runner, base64_encode((string) json_encode($get)), $as]);
        $j = json_decode($r['out'], true);
        return is_array($j) ? (array) ($j['results'] ?? []) : ['__raw' => substr($r['out'] . $r['err'], 0, 300)];
    };
    $hits = $api(['q' => 'D4'], $acct);
    $px   = array_values(array_filter($hits, static fn($x) => is_array($x) && ($x['type'] ?? '') === 'passenger' && str_contains((string) ($x['subtitle'] ?? ''), $tag . '-B7')));
    check('q=D4 (printed seat) finds the passenger on L22', $px !== [], json_encode(array_slice($hits, 0, 3)));
    check('… and titles the seat as printed (D4), not L22', $px !== [] && str_contains((string) $px[0]['title'], 'D4') && !str_contains((string) $px[0]['title'], 'L22'));
    $hits = $api(['q' => 'C5-6'], $acct);
    check('q=C5-6 finds the private cabin passenger', array_filter($hits, static fn($x) => is_array($x) && ($x['type'] ?? '') === 'passenger' && str_contains((string) ($x['subtitle'] ?? ''), $tag . '-B2')) !== []);
    $hits = $api(['q' => 'L22'], $acct);
    check('the stored id still works (q=L22)', array_filter($hits, static fn($x) => is_array($x) && ($x['type'] ?? '') === 'passenger' && str_contains((string) ($x['subtitle'] ?? ''), $tag . '-B7')) !== []);
    // SHG code -> Agent hit (agent.php needs commissions.view).
    $codes = Settings::getArray('agent_codes', []);
    $code  = null;
    foreach ($codes as $aid => $num) {
        if (Database::scalar("SELECT COUNT(*) FROM admins WHERE id = :i AND role = 'agent' AND is_active = 1", ['i' => (int) $aid], 0)) { $code = (int) $num; break; }
    }
    if ($code !== null) {
        $hasAgent = static fn(array $hs): bool => array_filter($hs, static fn($x) => is_array($x) && ($x['type'] ?? '') === 'agent') !== [];
        check('SHG-' . $code . ': operations (commissions.view) gets the Agent hit', $hasAgent($api(['q' => 'SHG-' . $code], $ops)));
        check('SHG-' . $code . ': a counter (no commissions.view) does not', !$hasAgent($api(['q' => 'SHG-' . $code], $counter)));
    } else {
        echo "  SKIP  no agent with an SHG code in this database\n";
    }
    @unlink($runner);

    /* -----------------------------------------------------------------
     * 10. Nothing in the register writes money
     * --------------------------------------------------------------- */
    echo "\n=== Read-only ===\n";
    $ref = Database::fetchAll('SELECT pnr, refund_status, refund_amount, status FROM bookings WHERE pnr LIKE :p ORDER BY pnr', ['p' => $tag . '-%']);
    $sig = implode('|', array_map(static fn($x) => $x['pnr'] . ':' . $x['status'] . ':' . $x['refund_status'] . ':' . $x['refund_amount'], $ref));
    check('no booking status / refund field was changed by any render',
        $sig === implode('|', [
            $tag . '-B1:confirmed:none:0.00', $tag . '-B2:confirmed:none:0.00', $tag . '-B3:cancelled:processed:1500.00', $tag . '-B4:pending:none:0.00',
            $tag . '-B5:cancelled:pending:1000.00', $tag . '-B6:confirmed:none:0.00', $tag . '-B7:pending:none:0.00', $tag . '-B8:confirmed:none:0.00',
        ]), $sig);
} catch (Throwable $e) {
    check('suite ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
}

check('fixture removed', (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE pnr LIKE :p', ['p' => $tag . '-%'], 0) === 0);
echo "\n" . ($FAIL === 0
    ? "\033[32m  {$PASS} passed, 0 failed\033[0m\n\n"
    : "\033[31m  {$PASS} passed, {$FAIL} failed\033[0m\n\n");
exit($FAIL === 0 ? 0 : 1);
