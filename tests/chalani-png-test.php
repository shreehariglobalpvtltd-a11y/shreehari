<?php
/**
 * Chalani PNG pages + the ticket's AGENT CODE — integration test
 * (owner ask, 10 Sep 2026).
 *
 * Locks in:
 *   • Ticket::issuedBy() is the ONE answer to "who cut this ticket": a
 *     numbered agent by SHG code, an agent without a number still AGENT
 *     (never OFFICE — that would hand the commission to the wrong desk),
 *     company staff OFFICE / COUNTER, a website sale ONLINE;
 *   • the PNG ticket re-renders after the layout bump and carries the code;
 *   • ChalaniPng::pages() splits deterministically, the last page always
 *     leaves room for the summary boxes, and pageCount() agrees with what
 *     render() will actually draw (a "page 3 of 4" link cannot 404);
 *   • every page renders at the A4-landscape size and is cached on the
 *     DATA fingerprint — an unchanged bus is handed back without a redraw,
 *     a changed one gets a new file and the stale page is swept;
 *   • render() refuses a page number that does not exist;
 *   • Settings::company() answers with the live rows and never blanks a
 *     field, so a letterhead can never print empty;
 *   • ChallanPng::roman() and Ticket::roman() are the same rule.
 *
 *   php -c .claude/php-dev.ini tests/chalani-png-test.php
 *
 * Reads the test database; writes only PNG files under uploads/chalani/.
 * CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/challanpng.php';
require_once INCLUDE_PATH . '/chalanipng.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/* =====================================================================
 *  1. Ticket::issuedBy() — the one answer
 * ================================================================= */
echo "\n== who cut the ticket ==\n";

$agentId = (int) Database::scalar(
    "SELECT id FROM admins WHERE role = 'agent' ORDER BY id LIMIT 1", [], 0
);
$agentCode = $agentId > 0 ? AgentWallet::agentCodeLabel($agentId) : '';

$online = Ticket::issuedBy(['sold_by_admin_id' => 0]);
check('a website sale reads ONLINE', $online['kind'] === 'online' && $online['code'] === 'ONLINE', $online['line']);

$office = Ticket::issuedBy(['sold_by_admin_id' => 1, 'agent_name' => 'Desk One', 'agent_role' => 'admin']);
check('office staff read OFFICE', $office['kind'] === 'office' && $office['code'] === 'OFFICE', $office['line']);

$counter = Ticket::issuedBy(['sold_by_admin_id' => 1, 'agent_name' => 'Window 2', 'agent_role' => 'counter']);
check('a company ticket window reads COUNTER', $counter['code'] === 'COUNTER', $counter['line']);

/* The one that used to be wrong: an agent whose SHG number has not been
   issued was reported as an OFFICE sale, which is the wrong desk. */
$bare = Ticket::issuedBy(['sold_by_admin_id' => 999999, 'agent_name' => 'New Agent', 'agent_role' => 'agent']);
check('an agent with no number is still an AGENT', $bare['kind'] === 'agent' && $bare['code'] === 'AGENT', $bare['line']);
check('the seller name survives into the line', str_contains($bare['line'], 'New Agent'), $bare['line']);

if ($agentId > 0 && $agentCode !== '') {
    $withCode = Ticket::issuedBy(['sold_by_admin_id' => $agentId, 'agent_name' => 'Coded', 'agent_role' => 'agent']);
    check('a numbered agent prints its SHG code', $withCode['code'] === $agentCode, $withCode['line']);
} else {
    echo "  SKIP  no numbered agent in this database\n";
}

/* Never blank: the ticket has to say SOMETHING in the code chip. */
$blank = Ticket::issuedBy([]);
check('the code is never empty', $blank['code'] !== '' && $blank['name'] !== '', $blank['code']);

/* =====================================================================
 *  2. The PNG ticket carries it
 * ================================================================= */
echo "\n== the ticket picture ==\n";

$b = Database::fetch(
    "SELECT b.id, b.pnr FROM bookings b
       JOIN tickets t ON t.booking_id = b.id
       JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
       JOIN schedules s ON s.id = l.schedule_id
       JOIN routes r ON r.id = s.route_id
      WHERE b.status = 'confirmed' ORDER BY b.id DESC LIMIT 1"
);
if ($b === null) {
    echo "  SKIP  no renderable confirmed booking\n";
} else {
    $png = Ticket::pngPath((int) $b['id'], true);
    check('the ticket PNG renders', is_file($png) && filesize($png) > 5000, basename($png));
    $size = @getimagesize($png);
    /* The width is fixed; the height GROWS with the passenger list, so a
       family ticket names everyone instead of "+6 more" (10 Sep 2026). */
    check('it is 1080 wide and at least the original 1620 tall',
        is_array($size) && $size[0] === 1080 && $size[1] >= 1620,
        is_array($size) ? $size[0] . 'x' . $size[1] : 'unreadable');

    /* The layout bump must be in the past, or every cached ticket would be
       treated as stale forever and re-render on every single download. */
    $ref  = new ReflectionClass(Ticket::class);
    $bump = (string) $ref->getConstant('PNG_LAYOUT_CHANGED');
    check('the layout stamp is a past moment', strtotime($bump) !== false && strtotime($bump) <= time(), $bump);
}

/* =====================================================================
 *  3. Pagination — the count and the drawing must agree
 * ================================================================= */
echo "\n== chalani pagination ==\n";

$fake = static fn(int $n): array => array_fill(0, $n, ['seat_no' => 'L1']);

foreach ([0, 1, 7, 25, 26, 33, 34, 40, 72, 100] as $n) {
    $chunks = ChalaniPng::pages($fake($n));
    $count  = ChalaniPng::pageCount($fake($n));
    $sum    = array_sum(array_column($chunks, 1));
    $want   = max(14, $n);
    $ok     = $count === count($chunks) && $sum === $want && $chunks !== [];
    // every page must start where the previous one ended
    $cursor = 0;
    foreach ($chunks as [$start, $len]) {
        if ($start !== $cursor) { $ok = false; break; }
        $cursor += $len;
    }
    check("$n passengers → " . count($chunks) . ' page(s), no row lost or repeated', $ok,
        implode(' + ', array_column($chunks, 1)));
}

/* The last page carries the payment summary and the signature boxes, so it
   must never be filled to the same depth as an ordinary page. */
foreach ([25, 40, 72] as $n) {
    $chunks = ChalaniPng::pages($fake($n));
    $last   = end($chunks);
    check("$n passengers: the summary page keeps room for the boxes", $last[1] <= 14, 'last page holds ' . $last[1]);
}

/* =====================================================================
 *  4. Real pages, real cache
 * ================================================================= */
echo "\n== chalani pages ==\n";

$trip = Database::fetch(
    "SELECT s.id AS schedule_id, s.travel_date, r.id AS route_id, r.route_code, r.from_city, r.to_city,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time, r.coach_type,
            bu.bus_number, bu.bus_name, d.full_name AS driver_name, d.phone AS driver_phone,
            COUNT(bp.id) AS pax
       FROM schedules s
       JOIN routes r ON r.id = s.route_id
       LEFT JOIN buses bu ON bu.id = s.bus_id
       LEFT JOIN drivers d ON d.id = s.driver_id
       JOIN booking_legs bl ON bl.schedule_id = s.id
       JOIN booking_passengers bp ON bp.leg_id = bl.id
       JOIN bookings bk ON bk.id = bp.booking_id AND bk.status IN ('confirmed','pending','completed')
      GROUP BY s.id ORDER BY pax DESC, s.id DESC LIMIT 1"
);

if ($trip === null) {
    echo "  SKIP  no departure with passengers on it\n";
} else {
    $date = (string) $trip['travel_date'];
    $rows = Database::fetchAll(
        "SELECT bp.seat_no, bp.full_name, bp.boarded_at, bp.special_need,
                b.id AS booking_id, b.pnr, b.contact_phone, b.status AS booking_status,
                b.source, b.total_amount, b.base_total, b.sold_by_admin_id, b.created_at,
                bl.boarding_stop, bl.drop_stop,
                p.status AS pay_status, p.method AS pay_method,
                t.ticket_number,
                ad.full_name AS agent_name, ad.username AS agent_user, ad.role AS agent_role
           FROM booking_passengers bp
           JOIN bookings b ON b.id = bp.booking_id
           JOIN booking_legs bl ON bl.id = bp.leg_id
           LEFT JOIN payments p ON p.id = (SELECT p2.id FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1)
           LEFT JOIN tickets t ON t.booking_id = b.id
           LEFT JOIN admins ad ON ad.id = b.sold_by_admin_id
          WHERE bl.schedule_id = :s AND b.status IN ('confirmed','pending','completed')
          ORDER BY LENGTH(bp.seat_no), bp.seat_no",
        ['s' => (int) $trip['schedule_id']]
    );

    /* The money rule the manifest hands over — mirrored here so the suite
       exercises the same shape the live caller passes. */
    $money = static function (array $r): array {
        $fare   = (float) ($r['total_amount'] ?? 0);
        $paid   = (($r['pay_status'] ?? '') === 'verified');
        $method = strtolower((string) ($r['pay_method'] ?? ''));
        return [
            'ticket' => $fare,
            'cash'   => $paid && in_array($method, ['cash', 'cod'], true) ? $fare : 0.0,
            'online' => $paid && in_array($method, ['upi', 'esewa', 'bank', 'wallet'], true) ? $fare : 0.0,
        ];
    };
    $fmt = [
        'money'     => $money,
        'stopShort' => static fn(string $s): string => trim((string) preg_replace('/\s*@.*$/u', '', $s)),
        'isLate'    => static fn(array $r): bool => false,
        'bookedBy'  => static fn(array $r): string => empty($r['sold_by_admin_id'])
            ? 'ONLINE' : Ticket::issuedBy($r)['code'],
        'stopsLine'      => 'Surat · Mehsana',
        'stopsLineRoman' => 'Surat (13:00) · Mehsana (23:00)',
    ];
    $totals = ['passengers' => count($rows), 'ticket' => 0, 'cash' => 0, 'online' => 0,
               'discount' => 0, 'net' => 0, 'chalaniNo' => ChalaniPng::chalaniNo($trip, $date)];

    $pages = ChalaniPng::pageCount($rows);
    check('the departure paginates', $pages >= 1, $pages . ' page(s), ' . count($rows) . ' passengers');

    $first = ChalaniPng::render($trip, $rows, $date, $totals, $fmt, 1);
    check('page 1 renders', is_file($first['path']) && filesize($first['path']) > 5000, $first['file']);
    $size = @getimagesize($first['path']);
    check('at the A4 landscape size', is_array($size) && $size[0] === ChalaniPng::WIDTH && $size[1] === ChalaniPng::HEIGHT,
        is_array($size) ? $size[0] . 'x' . $size[1] : 'unreadable');
    check('the page reports its place in the set', $first['page'] === 1 && $first['pages'] === $pages,
        'page ' . $first['page'] . ' of ' . $first['pages']);

    /* Every page pageCount() promises must actually draw — a picker link
       that 404s is the failure this guards. */
    $allDrawn = true;
    for ($p = 1; $p <= $pages; $p++) {
        $res = ChalaniPng::render($trip, $rows, $date, $totals, $fmt, $p);
        if (!is_file($res['path'])) { $allDrawn = false; break; }
    }
    check('every promised page exists', $allDrawn, $pages . ' page(s)');

    /* Cache: same data, no redraw. */
    $again = ChalaniPng::render($trip, $rows, $date, $totals, $fmt, 1);
    check('an unchanged bus is not redrawn', $again['fresh'] === false && $again['path'] === $first['path']);

    /* Changed data → a different file, and the stale one is swept away. */
    $moved = $rows;
    if ($moved !== []) {
        $moved[0]['seat_no'] = 'ZZ9';
        $movedRes = ChalaniPng::render($trip, $moved, $date, $totals, $fmt, 1);
        check('a changed coach draws a NEW file', $movedRes['path'] !== $first['path'], basename($movedRes['path']));
        check('the stale page is swept', !is_file($first['path']), basename($first['path']));
        // put the real one back so the desk's next open is warm
        ChalaniPng::render($trip, $rows, $date, $totals, $fmt, 1);
    }

    /* A page number outside the set is refused, not silently clamped. */
    $refused = false;
    try {
        ChalaniPng::render($trip, $rows, $date, $totals, $fmt, $pages + 5);
    } catch (Throwable $e) {
        $refused = true;
    }
    check('a page that does not exist is refused', $refused);
}

/* =====================================================================
 *  5. The letterhead
 * ================================================================= */
echo "\n== the letterhead ==\n";

$co = Settings::company();
foreach (['name', 'legal', 'legalNe', 'address', 'addressNe', 'phone', 'email', 'cin', 'web', 'operator', 'operatorNe', 'counters'] as $k) {
    check("company()['$k'] is never blank", isset($co[$k]) && trim((string) $co[$k]) !== '',
        mb_substr((string) ($co[$k] ?? ''), 0, 42));
}
check('the office number matches Settings::officePhone()', $co['phone'] === Settings::officePhone(), $co['phone']);

/* ---------------------------------------------------------------------
   The one shaping step neither text engine does: the short-i matra has to
   move in front of its consonant. dev_shape() runs at the DRAWING boundary
   only and is deliberately not idempotent, so these lock the rule itself —
   including the conjunct case it leaves alone on purpose.
   ------------------------------------------------------------------- */
echo "\n== devanagari shaping ==\n";
$cases = [
    ["\u{0938}\u{093F}\u{091F}",          "\u{093F}\u{0938}\u{091F}"],           // si-ta
    ["\u{0939}\u{0930}\u{093F}",          "\u{0939}\u{093F}\u{0930}"],           // ha-ri
    ["\u{091F}\u{093F}\u{0915}\u{091F}",  "\u{093F}\u{091F}\u{0915}\u{091F}"],   // ti-ka-t
    ["\u{0905}\u{0927}\u{093F}\u{0915}",  "\u{0905}\u{093F}\u{0927}\u{0915}"],   // a-dhi-k
];
foreach ($cases as [$in, $want]) {
    check('the i-matra moves in front of its consonant', dev_shape($in) === $want, bin2hex($in) . ' -> ' . bin2hex(dev_shape($in)));
}
/* After a conjunct it is LEFT ALONE — moving it there stops the font
   forming the conjunct, which reads worse than the misplaced hook. */
$conj = "\u{0938}\u{094D}\u{0925}\u{093F}";
check('a matra after a conjunct is left where it is', dev_shape($conj) === $conj);
check('text with no i-matra is returned untouched', dev_shape('Ram Bahadur 9851000000') === 'Ram Bahadur 9851000000');
check('a Devanagari name survives display()',
    preg_match('/\p{Devanagari}/u', Ticket::display("\u{0938}\u{093F}\u{0924}\u{093E}")) === 1);

/* =====================================================================
 *  6. What the 11 Sep 2026 audit found — one check per confirmed defect
 * ================================================================= */
echo "\n== audit fixes ==\n";

/* issuedBy() used to answer ONLINE for any booking with no seller row, while
   the manifest read bookings.source and answered COUNTER for the same row —
   so one sale was credited to two different channels on two documents. */
$srcCounter = Ticket::issuedBy(['sold_by_admin_id' => 0, 'source' => 'counter']);
check('a counter walk-in with no seller row reads COUNTER, not ONLINE',
    $srcCounter['code'] === 'COUNTER' && $srcCounter['kind'] === 'office', $srcCounter['line']);
$srcAdmin = Ticket::issuedBy(['sold_by_admin_id' => 0, 'source' => 'admin']);
check('an office sale with no seller row reads OFFICE', $srcAdmin['code'] === 'OFFICE', $srcAdmin['line']);
$srcWeb = Ticket::issuedBy(['sold_by_admin_id' => 0, 'source' => 'web']);
check('a website sale still reads ONLINE', $srcWeb['code'] === 'ONLINE', $srcWeb['line']);

/* Owner, 11 Sep 2026: "counter bata kateko chha bhane COUNTER, agent bata
   kateko chha bhane AGENT lekhne" — the channel is named on the ticket, not
   left to be inferred from a code. */
foreach ([
    ['AGENT',   ['sold_by_admin_id' => 999999, 'agent_role' => 'agent']],
    ['COUNTER', ['sold_by_admin_id' => 1, 'agent_role' => 'counter']],
    ['OFFICE',  ['sold_by_admin_id' => 1, 'agent_role' => 'admin']],
    ['COUNTER', ['sold_by_admin_id' => 0, 'source' => 'counter']],
    ['ONLINE',  ['sold_by_admin_id' => 0, 'source' => 'web']],
] as [$word, $row]) {
    $lab = Ticket::issuedBy($row)['label'];
    check("the ticket names the channel: $word", str_contains($lab, $word), $lab);
    check("...in Nepali too: $word", preg_match('/\p{Devanagari}/u', $lab) === 1, $lab);
}

/* The PNG ticket's tiles measured through the shaping boundary and drew
   around it, so one image spelled the same Nepali name two ways. */
$tsrc = (string) file_get_contents(dirname(__DIR__) . '/includes/ticket.php');
$tile = (string) (preg_match('/\$tileRow = static function.*?\n        \};/s', $tsrc, $m) ? $m[0] : '');
check('the ticket tiles draw through the shaping boundary',
    $tile !== '' && !str_contains($tile, 'imagettftext') && str_contains($tile, 'self::gdText'),
    $tile === '' ? 'closure not found' : 'no raw imagettftext in $tileRow');

/* The seat-map challan printed the literal words "(Nepali name)". */
$csrc = (string) file_get_contents(dirname(__DIR__) . '/includes/challanpng.php');
check('the challan no longer prints "(Nepali name)" in a berth',
    !str_contains($csrc, "'(Nepali name)'"));
check('the challan shapes at its own drawing boundary',
    substr_count($csrc, 'dev_shape(') >= 2);

/* ReportPdf cut cells on BYTES, which blanks a Devanagari cell outright. */
$rsrc = (string) file_get_contents(dirname(__DIR__) . '/includes/reportpdf.php');
check('the report PDF truncates on characters, not bytes',
    str_contains($rsrc, 'mb_strlen($val)') && str_contains($rsrc, 'mb_substr($val'));
check('the report PDF can reach the Devanagari face', str_contains($rsrc, "registerTTF(\$devFont, 'F7')"));

/* Every seat-changing path re-mints the ticket; the seat map's transfer
   was the one that did not, so the passenger kept a picture of the old
   berth while the chalani showed the new one. */
$ssrc = (string) file_get_contents(dirname(__DIR__) . '/includes/seats.php');
check('a seat transfer re-mints the ticket', str_contains($ssrc, 'Ticket::reissue((int) $row[\'booking_id\'])'));

/* The chalani page cache hashed twelve columns and drew eighteen. */
$fpRows = [[
    'seat_no' => 'L1', 'full_name' => 'Ram', 'contact_phone' => '9800000000',
    'boarding_stop' => 'Surat', 'drop_stop' => 'Rupaidiha', 'pay_status' => 'verified',
    'pay_method' => 'cash', 'total_amount' => 2000, 'sold_by_admin_id' => 0,
    'ticket_number' => 'TKT-1', 'pnr' => 'SHG-1', 'created_at' => '2026-09-01 10:00:00',
    'agent_role' => '', 'agent_name' => '', 'agent_user' => '',
    'boarded_at' => null, 'special_need' => '', 'booking_status' => 'confirmed',
]];
$fpTrip   = ['schedule_id' => 1, 'bus_number' => 'GJ-01', 'coach_type' => 'sleeper'];
$fpTotals = ['passengers' => 1];
$fpBase   = ChalaniPng::fingerprint($fpTrip, $fpRows, '2026-09-01', $fpTotals);
foreach ([['boarded_at', '2026-09-01 13:05:00'], ['special_need', 'wheelchair'],
          ['booking_status', 'pending'], ['agent_name', 'Renamed Agent']] as [$field, $val]) {
    $moved = $fpRows;
    $moved[0][$field] = $val;
    check("the page cache notices a change to $field",
        ChalaniPng::fingerprint($fpTrip, $moved, '2026-09-01', $fpTotals) !== $fpBase);
}
check('the page cache notices a change to the seller code the page prints',
    ChalaniPng::fingerprint($fpTrip, $fpRows, '2026-09-01', $fpTotals, ['bookedBy' => static fn(array $r): string => 'SHG-0001'])
    !== ChalaniPng::fingerprint($fpTrip, $fpRows, '2026-09-01', $fpTotals, ['bookedBy' => static fn(array $r): string => 'OFFICE']));

/* One roman() rule, not two. */
$mixed = "Ram \u{0930}\u{093E}\u{092E} \u{2014} (\u{0928}\u{0947}\u{092A}) SHG-0007";
check('ChallanPng::roman() and Ticket::roman() agree', ChallanPng::roman($mixed) === Ticket::roman($mixed),
    Ticket::roman($mixed));
check('Devanagari is stripped, ASCII survives', str_contains(Ticket::roman($mixed), 'SHG-0007')
    && preg_match('/\p{Devanagari}/u', Ticket::roman($mixed)) !== 1);

echo "\n";
echo $FAIL === 0
    ? "\033[32mALL PASS\033[0m  ($PASS checks)\n\n"
    : "\033[31m$FAIL FAILED\033[0m  ($PASS passed)\n\n";
exit($FAIL === 0 ? 0 : 1);
