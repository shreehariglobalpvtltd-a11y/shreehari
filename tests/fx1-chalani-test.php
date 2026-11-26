<?php
/**
 * =====================================================================
 *  fx1 (26 Nov 2026) - the chalani pass: every output says the same thing
 *  and nothing the crew reads is cut off.
 *
 *   a. COPY TAG   an agent / desk copy writes its OWN picture files; it can
 *                 no longer replace (or sweep) the whole-bus pages a signed
 *                 share link serves. Share links of a desk copy are signed
 *                 with the tag; whole-bus links are signed exactly as before.
 *   b. PNG        the copy's own number + "AGENT COPY" in the head, the
 *                 name on its own line with ticket no. + border ID under it,
 *                 the driver's phone, the whole place list, this page's own
 *                 remarks - all drawn WHOLE; flags by direction; BS date;
 *                 extra seats + lap infants on the seat chart.
 *   c. PDF        a real ticket number and the border ID drawn whole beside
 *                 a 17-letter name; flags / date / copy line; extra seats on
 *                 the chart page; page economy untouched.
 *   d. RULES      ChalaniPng::toNepal / flagFirst / bsDate / copyInfo,
 *                 ChalaniStrip::csvText (CSV formula injection), thermalOn.
 *   e. PARITY     ChalaniPng::sheetFor() names the SAME picture file as
 *                 admin/manifest.php does (whole bus, an NPR desk's copy, a
 *                 rupee desk's copy); download-chalan.php draws a signed page
 *                 on demand; the A4 sheet, the CSV, the 80 mm list and the
 *                 public link's gate - on a small seeded departure whose money
 *                 is known to the rupee (Total = Cash + Online + Due).
 *   f. PINS       the source lines other suites read are still there.
 *
 *  Synthetic rows for a-d (no database writes). Part e writes ONE marked
 *  departure on a date 100+ days out (bookings.pnr 'ZZFX1-…', phones
 *  90000260xx; straight inserts, no sale engine, so no message / ledger /
 *  ticket file) and removes exactly those rows, the schedule row it made
 *  and the picture files drawn for it; it flips three of this slice's own
 *  switches and puts them back. FX1_CH_KEEP=<folder> keeps a copy of every
 *  picture / PDF / page it made, to look at.
 *
 *    php -c .claude/php-dev.ini tests/fx1-chalani-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const FX1_DATE = '2033-05-25';          // years ahead (nobody else draws there), still inside the BS table; "WED, 25 MAY" = the widest letters a date has
const FX1_NO   = 'CH-20330525-ZZFX1';   // the whole-bus chalani number of the synthetic departure
const FX1_IP   = '203.0.113.71';        // the fake client of the child pages (its rate-limit rows are removed)

/** A synthetic departure: [$trip, $rows, $date, $totals, $fmt]. Needs the app booted. */
function fx1_sheet(int $sid, int $n, array $o = []): array
{
    $toNepal = (bool) ($o['toNepal'] ?? true);
    $trip = ['schedule_id' => $sid, 'route_id' => 0, 'route_code' => 'ZZFX1', 'from_city' => $toNepal ? 'Surat' : 'Rupaidiha', 'to_city' => $toNepal ? 'Rupaidiha' : 'Surat',
        'dep_time' => '13:00:00', 'coach_type' => 'sleeper', 'bus_number' => 'GJ-05-ZZ-' . sprintf('%04d', $sid % 10000), 'bus_name' => 'Hari Sleeper',
        'driver_name' => 'Ramesh Bahadur Thapa', 'driver_phone' => '9800000000', 'slot' => 1];
    $layout = Seats::layoutFor('sleeper', 'sharing');
    $single = [];
    foreach ($layout['decks'] as $dk) { foreach ($dk['rows'] as $rw) { foreach ($rw['right'] as $b) { $single[$b] = true; } } }
    $ids   = Seats::seatIds('sleeper', 'sharing');
    $stops = ['Surat', 'Kamrej', 'Ankleshwar', 'Bharuch', 'Vadodara', 'Anand', 'Nadiad', 'Ahmedabad', 'Mehsana', 'Emli Bhupal', 'Rupaidiha Border Bus Park'];
    $names = ['Ram Bahadur Thapa', 'Sita Kumari', 'सीता कुमारी श्रेष्ठ', 'Bartholomew Christopher Montgomery', 'Hari Prasad Sharma', 'Gita Devi Chaudhary'];
    $ex    = (int) ($o['extras'] ?? 0);
    $rows  = [];
    for ($i = 0; $i < $n; $i++) {
        $isEx = $i >= $n - $ex;
        $mode = ['cash', 'online', 'boarding', 'pending'][$i % 4];
        if (!empty($o['markDue']) && $i === 5) { $mode = 'due'; }
        $rows[] = ['pax_id' => $sid * 100 + $i + 1, 'booking_id' => $sid * 100 + $i + 1,
            'seat_no' => $isEx ? 'EX' . ($i - ($n - $ex) + 1) : $ids[$i % count($ids)], 'booking_mode' => 'sharing',
            'full_name' => $names[$i % count($names)],
            'ticket_number' => $i === 0 ? 'TKT-261126-G4YTK7' : ($i === 1 ? 'TKT-261126-WWWWWW' : 'TKT-261126-' . strtoupper(substr(md5((string) $i), 0, 6))),
            'pnr' => sprintf('ZZF-2033-%05d', $i + 1), 'contact_phone' => '98' . sprintf('%08d', 12340000 + $i),
            'boarding_stop' => $stops[$i % (int) ($o['stops'] ?? count($stops))], 'drop_stop' => 'Nepalgunj', 'created_at' => $i === 4 ? FX1_DATE . ' 15:30:00' : '2033-05-01 10:00:00',
            'gender' => $i % 3 === 1 ? 'Female' : ($i === 6 ? '' : 'Male'), 'age' => 30,
            'pay_status' => in_array($mode, ['cash', 'online', 'due'], true) ? 'verified' : ($mode === 'pending' ? 'pending' : 'cod_pending'),
            'pay_method' => $mode === 'online' ? 'upi' : ($mode === 'boarding' ? 'cod' : 'cash'), 'is_cod' => $mode === 'boarding' ? 1 : 0, 'total_amount' => 2200,
            'sold_by_admin_id' => null, 'source' => 'web', 'agent_name' => '', 'agent_user' => '', 'agent_role' => '', 'boarded_at' => null, 'special_need' => null,
            'booking_status' => 'confirmed', 'id_type' => $i % 2 === 0 ? 'Nepali Citizenship' : '', 'id_number' => $i % 2 === 0 ? '12-34-5678901' : '',
            'fx1_mode' => $mode];
    }
    $womanSingle = static fn(array $r): bool => ($r['gender'] ?? '') === 'Female' && isset($single[$r['seat_no']]);
    $placeList = [];
    foreach ($rows as $r) {
        $pk = (string) $r['boarding_stop'];
        $placeList[$pk] ??= ['no' => (int) array_search($pk, $stops, true) + 1, 'name' => $pk, 'n' => 0, 'women' => 0, 'seats' => []];
        $placeList[$pk]['n']++;
    }
    uasort($placeList, static fn(array $a, array $b): int => $a['no'] <=> $b['no']);
    $seatInfo = [];
    foreach ($rows as $i => $r) {
        $seatInfo[$r['seat_no']] = ['inf' => ($i === 0 && !empty($o['infant'])) || ($i === $n - 1 && $ex > 0 && !empty($o['infant'])) ? 1 : 0,
            'name' => (string) $r['full_name'], 'place' => (int) $placeList[(string) $r['boarding_stop']]['no'], 'stop' => (string) $r['boarding_stop'],
            'woman' => ($r['gender'] ?? '') === 'Female', 'ws' => $womanSingle($r), 'pnr' => (string) $r['pnr'], 'paid' => true, 'state' => 'paid'];
    }
    $chalaniNo = FX1_NO;
    $copy = $o['copy'] ?? ChalaniPng::copyInfo(null, '', '', $chalaniNo);
    $fmt = [
        'money' => static function (array $r): array {
            $m = (string) $r['fx1_mode'];
            return ['ticket' => 2200.0, 'extra' => 0.0, 'cash' => in_array($m, ['cash', 'due'], true) ? 2200.0 : 0.0, 'online' => $m === 'online' ? 2200.0 : 0.0];
        },
        'stopShort' => static fn(string $s): string => $s,
        'isLate' => static fn(array $r): bool => (string) $r['created_at'] > FX1_DATE . ' 13:00:00',
        'bookedBy' => static fn(array $r): string => (int) $r['pax_id'] % 5 === 0 ? 'SHG-0007' : 'OFFICE',
        'stopsLine' => 'Surat (१३:००)', 'stopsLineRoman' => 'Surat (13:00)',
        'placeList' => array_values($placeList), 'placeNo' => static fn(string $s): int => (int) ($placeList[$s]['no'] ?? 0),
        'womanSingle' => $womanSingle, 'womenSingleCount' => count(array_filter($rows, $womanSingle)),
        'seatInfo' => $seatInfo, 'layout' => $layout, 'busName' => 'Hari Sleeper ' . $trip['bus_number'],
        'extrasLines' => [], 'penLines' => 2,
        'rowState' => static fn(array $r): string => in_array($r['fx1_mode'], ['cash', 'online'], true) ? 'paid' : 'due',
        'payMark' => static function (array $r): array {
            $m = (string) $r['fx1_mode'];
            return ['mode' => $m, 'amt' => $m === 'boarding' ? 2200.0 : 0.0, 'first' => true,
                'ne' => ChalaniPng::payModeLabel($m, 'ne') . ($m === 'boarding' ? ' ₹2,200' : ''), 'claim' => $m === 'boarding' && (int) $r['pax_id'] % 100 === 3];
        },
        'paxId' => array_key_exists('paxId', $o) ? $o['paxId'] : static fn(array $r): string => PaxId::short((string) $r['id_type'], (string) $r['id_number'], false),
        'infantsOf' => static fn(array $r): int => (!empty($o['infant']) && (int) $r['pax_id'] % 100 === 1) ? 1 : 0,
        'copy' => $copy,
    ];
    $cash = 0.0; $online = 0.0;
    foreach ($rows as $r) { $m = $fmt['money']($r); $cash += $m['cash']; $online += $m['online']; }
    $totals = ['passengers' => $n, 'ticket' => 2200.0 * $n, 'cash' => $cash, 'online' => $online, 'discount' => 0.0, 'net' => $cash + $online,
        'chalaniNo' => (string) $copy['no'], 'extras' => 0.0, 'extrasPaid' => 0.0, 'extrasDue' => 0.0, 'infants' => !empty($o['infant']) ? 1 : 0, 'boardDue' => 0.0, 'boardDueN' => 0];
    return [$trip, $rows, FX1_DATE, $totals, $fmt];
}

/**
 * A small real departure for part (e): 6 bookings / 7 passengers on a far date of the
 * first active sleeper route to Rupaidiha, written straight into the tables (no sale
 * engine, so no message, ledger or ticket file is produced). Every row is marked:
 * bookings.pnr 'ZZFX1-…', contact_phone 90000260xx. fx1_unseed() removes exactly these
 * (legs, passengers and payments go with the booking) and the schedule row it made.
 *
 * @return array{sid:int,date:string,routeId:int,made:bool}|null
 */
function fx1_seed(): ?array
{
    fx1_unseed(null);
    $route = Database::fetch("SELECT * FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 AND to_city = 'Rupaidiha' ORDER BY id LIMIT 1");
    if ($route === null) { return null; }
    $rid  = (int) $route['id'];
    $date = '';
    for ($i = 0; $i < 60 && $date === ''; $i++) {
        $d = date('Y-m-d', strtotime(todayISO() . ' +' . (100 + (getmypid() + $i) % 60) . ' days'));
        if (Database::fetch('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d LIMIT 1', ['r' => $rid, 'd' => $d]) === null) { $date = $d; }
    }
    if ($date === '') { return null; }
    $sid = (int) Seats::schedule($rid, $date)['id'];
    $stops = array_values(array_filter(array_map(static fn(array $s): string => trim((string) $s['name']), Boarding::allStopsFor($rid, 'boarding'))));
    $s1 = $stops[0] ?? 'Surat'; $s3 = $stops[2] ?? $s1;
    $ids = Seats::seatIds('sleeper', 'sharing');
    $n = count($ids);
    $has = static fn(string $c): bool => CounterDesk::hasColumn('bookings', $c);
    $rnd = static fn(): string => strtoupper(bin2hex(random_bytes(4)));
    $book = static function (array $b, array $pax, array $pay) use ($sid, $date, $rnd, $has): void {
        $row = ['pnr' => 'ZZFX1-' . $rnd(), 'contact_phone' => (string) $b['phone'], 'status' => 'confirmed', 'source' => (string) ($b['source'] ?? 'web'),
            'is_cod' => (int) ($b['cod'] ?? 0), 'total_amount' => (float) $b['total'], 'base_total' => (float) ($b['base'] ?? $b['total']), 'booking_mode' => 'sharing'];
        foreach (['luggage_pieces', 'luggage_fee', 'infant_count', 'infant_fee', 'fx_currency', 'fx_rate', 'fx_total'] as $c) { if (isset($b[$c]) && $has($c)) { $row[$c] = $b[$c]; } }
        if (isset($b['desk']) && CounterDesk::stampColumn()) { $row['counter_code'] = (string) $b['desk']; }
        $bid = Database::insert('bookings', $row);
        $leg = Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'travel_date' => $date, 'boarding_stop' => (string) $b['stop'], 'drop_stop' => 'Nepalgunj']);
        foreach ($pax as $p) {
            Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $leg, 'passenger_ref' => 'ZZFX1-' . $rnd(), 'seat_no' => (string) $p[0], 'full_name' => (string) $p[1],
                'gender' => $p[2], 'age' => 30, 'id_type' => $p[3] ?? null, 'id_number' => $p[4] ?? null]);
        }
        Database::insert('payments', ['booking_id' => $bid, 'payment_ref' => 'ZZFX1-' . $rnd(), 'amount' => (float) $b['total']] + $pay);
    };
    $book(['phone' => '9000026071', 'stop' => $s1, 'total' => 4900, 'base' => 5000, 'luggage_pieces' => 2, 'luggage_fee' => 200, 'infant_count' => 1, 'infant_fee' => 300],
        [[$ids[$n - 1], 'ZZTEST fx1 Ram Bahadur', 'Male', 'Nepali Citizenship', '12-34-5678901'], [$ids[$n - 2], 'ZZTEST fx1 सीता कुमारी', 'Female']], ['method' => 'cash', 'status' => 'verified']);
    $book(['phone' => '9000026072', 'stop' => $s3, 'total' => 2200, 'cod' => 1], [[$ids[$n - 3], 'ZZTEST fx1 Pay On Bus', 'Male']], ['method' => 'cod', 'status' => 'cod_pending']);
    $book(['phone' => '9000026073', 'stop' => $s1, 'total' => 2200], [[$ids[$n - 4], '=HYPERLINK("http://example.invalid","ZZTEST fx1 Formula")', null]], ['method' => 'upi', 'status' => 'verified']);
    $book(['phone' => '9000026074', 'stop' => $s3, 'total' => 2200], [[$ids[$n - 5], 'ZZTEST fx1 Online Pending', 'Male']], ['method' => 'upi', 'status' => 'pending', 'utr_number' => '612345678901']);
    $book(['phone' => '9000026075', 'stop' => 'ZZ Fx1 Halt', 'total' => 2200, 'source' => 'counter', 'desk' => 'NPJ', 'fx_currency' => 'NPR', 'fx_rate' => 1.6, 'fx_total' => 3520],
        [[$ids[$n - 6], 'ZZTEST fx1 Nepal Desk', 'Male']], ['method' => 'cash', 'status' => 'verified']);
    $book(['phone' => '9000026076', 'stop' => $s1, 'total' => 2200, 'source' => 'counter', 'desk' => 'SRT'], [['EX1', 'ZZTEST fx1 Extra Seat', 'Male']], ['method' => 'cash', 'status' => 'verified']);
    return ['sid' => $sid, 'date' => $date, 'routeId' => $rid, 'made' => true];
}

/** Remove the seed: its bookings (by both marks), its picture files and the schedule row it made. */
function fx1_unseed(?array $seed): void
{
    try {
        foreach (Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE 'ZZFX1-%' AND contact_phone LIKE '90000260%'") as $b) {
            Database::delete('bookings', 'id = :i', ['i' => (int) $b['id']]);
        }
        if ($seed !== null) {
            $dir = rtrim(UPLOAD_PATH, '/\\') . '/chalani/' . $seed['date'];
            foreach ((array) glob($dir . '/*-S' . (int) $seed['sid'] . '-*.png') as $f) { if (is_string($f)) { @unlink($f); } }
            if (is_dir($dir) && count((array) scandir($dir)) <= 2) { @rmdir($dir); }
            Database::run('DELETE FROM schedules WHERE id = :i AND NOT EXISTS (SELECT 1 FROM booking_legs WHERE schedule_id = :j)', ['i' => (int) $seed['sid'], 'j' => (int) $seed['sid']]);
        }
    } catch (Throwable $e) {
        echo '  (cleanup: ' . $e->getMessage() . ")\n";
    }
}

/* ---- child: the PDF of one synthetic sheet (ChalaniPdf::send exits) ---------- */
if (($argv[1] ?? '') === '--pdf') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    require dirname(__DIR__) . '/includes/bootstrap.php';
    require_once INCLUDE_PATH . '/seats.php'; require_once INCLUDE_PATH . '/paxid.php'; require_once INCLUDE_PATH . '/chalanipdf.php';
    $o = (array) ($cfg['o'] ?? []);
    if (!empty($o['agentCopy'])) { $o['copy'] = ChalaniPng::copyInfo('SHG0007', 'Fx One Agent', '', FX1_NO . '-ASHG0007'); }
    [$trip, $rows, $date, $totals, $fmt] = fx1_sheet((int) $cfg['sid'], (int) $cfg['n'], $o);
    register_shutdown_function(static function (): void { fwrite(STDERR, json_encode(ChalaniPdf::$drawn, JSON_UNESCAPED_UNICODE)); });
    ChalaniPdf::send($trip, $rows, $date, $totals, $fmt);
    exit(0);
}

/* ---- child: one real page, as an admin (or nobody), the op5 suite's pattern ---- */
if (($argv[1] ?? '') === '--page') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = FX1_IP;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME']    = (string) $cfg['script'];
    $_SERVER['REQUEST_URI']    = (string) $cfg['script'];
    $_GET = (array) ($cfg['get'] ?? []);
    register_shutdown_function(static function (): void { fwrite(STDERR, "\n<!--HTTP:" . (int) (http_response_code() ?: 200) . "-->\n"); });
    if ((int) ($cfg['admin'] ?? 0) <= 0) {
        require dirname(__DIR__) . (string) $cfg['script'];      // a public page boots the app itself, nobody signed in
        exit(0);
    }
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    if ((int) ($cfg['admin'] ?? 0) > 0) {
        $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) $cfg['admin']]);
        if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
        $_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
            'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(), 'logged_in_at' => time()];
    }
    require dirname(__DIR__) . (string) $cfg['script'];
    exit(0);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/paxid.php';
require_once INCLUDE_PATH . '/opshub.php';
require_once INCLUDE_PATH . '/chalanipng.php';
require_once INCLUDE_PATH . '/chalanipdf.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; } else { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function skip(string $l, string $why): void { echo "  SKIP  $l — $why\n"; }
/** @return array{0:string,1:string} stdout, stderr of a child of this file */
function child(string $mode, array $cfg): array
{
    $ini = php_ini_loaded_file();
    $cmd = array_merge([PHP_BINARY], $ini ? ['-c', $ini] : [], [__FILE__, $mode, base64_encode((string) json_encode($cfg))]);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ['', '']; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return [$out, $err];
}
function httpOf(string $err): int { return preg_match('/<!--HTTP:(\d+)-->/', $err, $m) === 1 ? (int) $m[1] : 0; }

$KEEP = rtrim((string) getenv('FX1_CH_KEEP'), '/\\');
if ($KEEP !== '' && !is_dir($KEEP)) { @mkdir($KEEP, 0775, true); }
$keep = static function (string $name, string $pathOrBytes, bool $isPath = true) use ($KEEP): void {
    if ($KEEP === '') { return; }
    if ($isPath) { @copy($pathOrBytes, $KEEP . '/' . $name); } else { @file_put_contents($KEEP . '/' . $name, $pathOrBytes); }
};

$SID  = 9900000 + (getmypid() % 90000);
$DIR  = rtrim(UPLOAD_PATH, '/\\') . '/chalani/' . FX1_DATE;
$mine = static fn(): array => array_values(array_filter((array) glob($DIR . '/*-S' . $SID . '-*.png'), 'is_string'));
$wipe = static function () use ($mine, $DIR): void {
    foreach ($mine() as $f) { @unlink($f); }
    foreach ((array) glob($DIR . '/*-S99*.png.tmp') as $f) { if (is_string($f)) { @unlink($f); } }
    if (is_dir($DIR) && count((array) scandir($DIR)) <= 2) { @rmdir($DIR); }
};
$wipe();

echo "\n=== fx1 · chalani: copies, nothing cut, direction, BS date, extra seats ===\n\n";

try {
    /* ================================================================== */
    echo "-- (a) copy tag: a copy has its own picture files\n";
    [$trip, $rows, $date, $totals, $fmt] = fx1_sheet($SID, 30, ['infant' => true, 'markDue' => true]);
    $pages = ChalaniPng::pageCount($rows);
    check('30 passengers = 2 sheet pages', $pages === 2, (string) $pages);
    $wb = [];
    for ($p = 1; $p <= $pages; $p++) { $wb[$p] = ChalaniPng::render($trip, $rows, $date, $totals, $fmt, $p); }
    $d1 = ChalaniPng::$drawn;
    $wbChart = ChalaniPng::renderChart($trip, $rows, $date, $totals, $fmt, $pages + 1, $pages + 1);
    foreach ($wb as $p => $r) { $keep('whole-p' . $p . '.png', $r['path']); }
    $keep('whole-chart.png', $wbChart['path']);
    check('whole bus: the file name is the old one (<PLATE>-S<sid>-p1of2-<fp>.png)',
        preg_match('/^GJ-05-ZZ-\d{4}-S' . $SID . '-p1of2-[0-9a-f]{8}\.png$/', $wb[1]['file']) === 1, $wb[1]['file']);
    check('whole bus: the chart file name is the old one', preg_match('/^GJ-05-ZZ-\d{4}-S' . $SID . '-seatchart-[0-9a-f]{8}\.png$/', $wbChart['file']) === 1, $wbChart['file']);

    $agentCopy = ChalaniPng::copyInfo('SHG-0007', 'Fx One Agent', '', $totals['chalaniNo'] . '-ASHG-0007');
    check('copyInfo: agent tag is letters and digits only (ASHG0007), kind agent', $agentCopy['tag'] === 'ASHG0007' && $agentCopy['kind'] === 'agent', json_encode($agentCopy));
    check('copyInfo: the whole bus has no tag', ChalaniPng::copyInfo(null, '', '', 'CH-X')['tag'] === '' && ChalaniPng::copyTag(['copy' => ChalaniPng::copyInfo(null, '', '', 'CH-X')]) === '');
    check('copyTag: no copy entry = whole bus', ChalaniPng::copyTag([]) === '' && ChalaniPng::copyTag(['copy' => ['kind' => '', 'tag' => 'ZZ']]) === '');
    [$tripA, $rowsA, , $totalsA, $fmtA] = fx1_sheet($SID, 3, ['copy' => $agentCopy, 'paxId' => null]);
    $ag = ChalaniPng::render($tripA, $rowsA, $date, $totalsA, $fmtA, 1);
    $dA = ChalaniPng::$drawn;
    $agChart = ChalaniPng::renderChart($tripA, $rowsA, $date, $totalsA, $fmtA, 2, 2);
    $keep('agent-p1.png', $ag['path']);
    check('agent copy: its file carries the tag (-S<sid>-ASHG0007-p1of1-)', str_contains($ag['file'], '-S' . $SID . '-ASHG0007-p1of1-'), $ag['file']);
    check('agent copy: its chart carries the tag', str_contains($agChart['file'], '-S' . $SID . '-ASHG0007-seatchart-'), $agChart['file']);
    check('drawing the agent copy left BOTH whole-bus pages and the chart in place (it used to delete them)',
        is_file($wb[1]['path']) && is_file($wb[2]['path']) && is_file($wbChart['path']));
    check('newestFile: page 1 of the whole bus is the whole-bus file, never the copy (drawn later)', realpath(ChalaniPng::newestFile($SID, 1)) === realpath($wb[1]['path']));
    check('newestFile: page 2 is still there (the copy has only one page - the link used to 404)', realpath(ChalaniPng::newestFile($SID, 2)) === realpath($wb[2]['path']));
    check('newestFile: the chart (page 0)', realpath(ChalaniPng::newestFile($SID, 0)) === realpath($wbChart['path']));
    check('newestFile: the copy by its tag', realpath(ChalaniPng::newestFile($SID, 1, 'ASHG0007')) === realpath($ag['path']));
    check('newestFile: an unknown tag / page finds nothing', ChalaniPng::newestFile($SID, 1, 'ZZZZ') === '' && ChalaniPng::newestFile($SID, 9) === '');
    $oldGlob = array_map('basename', (array) glob($DIR . '/*-S' . $SID . '-p1of*.png'));
    check('the old download glob (*-S<sid>-p1of*.png) matches the whole-bus page only', $oldGlob === [$wb[1]['file']], json_encode($oldGlob));

    /* a change to the whole bus sweeps the whole-bus files, not the copy's; and the other way round */
    $rows2 = $rows; $rows2[0]['full_name'] = 'Changed Name';
    $wb2 = ChalaniPng::render($trip, $rows2, $date, $totals, $fmt, 1);
    check('whole bus changed: a new page, the stale whole-bus page + chart swept', $wb2['path'] !== $wb[1]['path'] && !is_file($wb[1]['path']) && !is_file($wbChart['path']));
    check('  …the agent copy and its chart untouched', is_file($ag['path']) && is_file($agChart['path']));
    $rowsA2 = $rowsA; $rowsA2[0]['full_name'] = 'Changed Agent Pax';
    $ag2 = ChalaniPng::render($tripA, $rowsA2, $date, $totalsA, $fmtA, 1);
    check('agent copy changed: its stale page swept, the whole-bus page untouched', !is_file($ag['path']) && is_file($ag2['path']) && is_file($wb2['path']));
    check('an unchanged page is not redrawn', ChalaniPng::render($trip, $rows2, $date, $totals, $fmt, 1)['fresh'] === false);

    $fpW = ChalaniPng::fingerprint($trip, $rows, $date, $totals, $fmt);
    check('fingerprint: same input, same hash', $fpW === ChalaniPng::fingerprint($trip, $rows, $date, $totals, $fmt));
    check('fingerprint: a copy of the SAME rows hashes differently (tag, kind, label are seeded)',
        $fpW !== ChalaniPng::fingerprint($trip, $rows, $date, $totals, ['copy' => $agentCopy] + $fmt)
        && ChalaniPng::fingerprint($trip, $rows, $date, $totals, ['copy' => $agentCopy] + $fmt)
           !== ChalaniPng::fingerprint($trip, $rows, $date, $totals, ['copy' => ['label' => 'Somebody Else'] + $agentCopy] + $fmt));

    /* share links */
    $lw = ChalaniStrip::shareLinks($SID, 2, 2);
    parse_str((string) parse_url($lw[1], PHP_URL_QUERY), $qw);
    check('share link, whole bus: signed exactly as before, no c', !isset($qw['c'])
        && hash_equals(substr(Security::sign('chalan-dl|' . $SID . '|chalani|1|' . (int) $qw['exp']), 0, 20), (string) $qw['k']));
    $lt = ChalaniStrip::shareLinks($SID, 1, 2, 'd-npj');
    parse_str((string) parse_url($lt[1], PHP_URL_QUERY), $qt);
    check('share link, desk copy: carries c=DNPJ and the tag is part of what is signed', ($qt['c'] ?? '') === 'DNPJ'
        && hash_equals(substr(Security::sign('chalan-dl|' . $SID . '|chalani|1|' . (int) $qt['exp'] . '|DNPJ'), 0, 20), (string) $qt['k'])
        && !hash_equals(substr(Security::sign('chalan-dl|' . $SID . '|chalani|1|' . (int) $qt['exp']), 0, 20), (string) $qt['k']));

    /* ================================================================== */
    echo "\n-- (b) PNG: what is drawn, drawn whole\n";
    check('whole bus: the number is the office number, no copy head', $d1['chalaniNo'] === FX1_NO && $d1['copy'] === '' && $d1['copyHead'] === '', json_encode([$d1['chalaniNo'], $d1['copyHead']]));
    check('whole bus: the summary says WHOLE BUS', str_ends_with((string) $d1['sumHead'], 'WHOLE BUS'), (string) $d1['sumHead']);
    check('agent copy: ITS number (…-ASHG-0007) is printed', $dA['chalaniNo'] === FX1_NO . '-ASHG-0007', (string) $dA['chalaniNo']);
    check('agent copy: the head says AGENT COPY · the agent', $dA['copy'] === 'agent' && str_starts_with((string) $dA['copyHead'], 'AGENT COPY') && str_contains((string) $dA['copyHead'], 'FX ONE AGENT'), (string) $dA['copyHead']);
    check('agent copy: the summary says AGENT COPY, not WHOLE BUS', str_contains((string) $dA['sumHead'], 'AGENT COPY') && !str_contains((string) $dA['sumHead'], 'WHOLE BUS'), (string) $dA['sumHead']);
    check('agent copy: no border ID (the tag is the office copy\'s)', !str_contains(implode(' ', (array) $dA['subs']), 'CTZ'));
    check('agent copy: its longer number still fits its field, whole', ($dA['fields']['CHALANI NO.'] ?? '') === FX1_NO . '-ASHG-0007', json_encode($dA['fields'] ?? null));
    $deskCopy = ['kind' => 'desk', 'tag' => 'DSRT', 'label' => 'Surat Office', 'no' => FX1_NO . '-SRT'];
    [$tripD, $rowsD, , $totalsD, $fmtD] = fx1_sheet($SID, 4, ['copy' => $deskCopy]);
    $dk = ChalaniPng::render($tripD, $rowsD, $date, $totalsD, $fmtD, 1);
    $dD = ChalaniPng::$drawn;
    $keep('desk-p1.png', $dk['path']);
    check('a rupee desk copy: DESK COPY in the head and the summary (it read WHOLE BUS)', str_starts_with((string) $dD['copyHead'], 'DESK COPY')
        && str_contains((string) $dD['sumHead'], 'DESK COPY') && $dD['chalaniNo'] === FX1_NO . '-SRT' && str_contains($dk['file'], '-DSRT-p1of1-'), json_encode([$dD['copyHead'], $dD['sumHead'], $dk['file']]));

    /* page 1 of the whole bus, redrawn alone so $drawn is page 1's */
    [$trip, $rows, $date, $totals, $fmt] = fx1_sheet($SID, 30, ['infant' => true, 'markDue' => true]);
    $drawFresh = static function (int $page) use (&$trip, &$rows, &$date, &$totals, &$fmt): array {
        $r = ChalaniPng::render($trip, $rows, $date, $totals, $fmt, $page);
        if (!$r['fresh']) { @unlink($r['path']); $r = ChalaniPng::render($trip, $rows, $date, $totals, $fmt, $page); }   // a cached page draws nothing: $drawn would be another page's
        return $r;
    };
    $p1 = $drawFresh(1);
    $d  = ChalaniPng::$drawn;
    $keep('whole-p1-final.png', $p1['path']);
    check('name: the 17-letter name is whole on its own line', in_array('Ram Bahadur Thapa +1 infant', (array) $d['names'], true) || in_array('Ram Bahadur Thapa', (array) $d['names'], true), json_encode($d['names'], JSON_UNESCAPED_UNICODE));
    check('name: no name on the page had to be cut', array_filter((array) $d['names'], static fn(string $s): bool => str_contains($s, '..')) === [], json_encode($d['names'], JSON_UNESCAPED_UNICODE));
    check('under it: the real ticket number whole AND the border ID whole', in_array('TKT-261126-G4YTK7 | ID CTZ **8901', (array) $d['subs'], true), json_encode($d['subs']));
    check('  …no ticket number / ID on the page is cut', array_filter((array) $d['subs'], static fn(string $s): bool => str_contains($s, '..')) === []);
    check('driver: name and phone on their own lines, both whole', $d['driver'] === ['Ramesh Bahadur Thapa', '9800000000'], json_encode($d['driver']));
    $pl = implode(' | ', (array) $d['places']);
    $allPlaces = true;
    foreach (['Surat', 'Kamrej', 'Ankleshwar', 'Bharuch', 'Vadodara', 'Anand', 'Nadiad', 'Ahmedabad', 'Mehsana', 'Emli Bhupal', 'Rupaidiha Border Bus Park'] as $st) { $allPlaces = $allPlaces && str_contains($pl, $st); }
    check('place list: all 11 places of the route are on the page (2 lines), none cut', $allPlaces && (int) $d['placesMore'] === 0 && !str_contains($pl, '..') && count((array) $d['places']) === 2, $pl);
    check('boarding: the long place name is whole (two lines), no place cell is cut', in_array('11 · Rupaidiha Border Bus Park', (array) $d['boards'], true)
        && array_filter(array_merge((array) $d['boards'], (array) $d['drops']), static fn(string $s): bool => str_contains($s, '..')) === [], json_encode($d['boards']));
    check('title: bus, head-count, lap infant, women on a single sleeper', str_contains((string) $d['title'], 'BUS HARI SLEEPER') && str_contains((string) $d['title'], '30 PASSENGERS + 1 INFANT (LAP)'), (string) $d['title']);
    $rem = (array) $d['remarks'];
    check('remarks: "Booked after departure" whole', in_array('Booked after departure', $rem, true), json_encode($rem));
    check('remarks: "UTR SENT · CHECKING" whole', in_array('UTR SENT · CHECKING', $rem, true), json_encode($rem));
    $pLast = $drawFresh($pages);
    $dL = ChalaniPng::$drawn;
    $keep('whole-p2-final.png', $pLast['path']);
    $remAll = array_merge($rem, (array) $dL['remarks']);
    check('the seed has a woman on a single sleeper', $fmt['womenSingleCount'] > 0, (string) $fmt['womenSingleCount']);
    check('remarks: "WOMAN · single sleeper" whole', in_array('WOMAN · single sleeper', $remAll, true), json_encode($remAll));
    check('remarks: none of the sheet\'s own words is cut on either page', array_filter($remAll, static fn(string $s): bool => str_contains($s, '..')) === [], json_encode($remAll));
    check('summary (last page): "Of that, office marked DUE" = the row the office marked due (Rs 2,200)', abs((float) ($dL['markedDue'] ?? 0) - 2200.0) < 0.01, json_encode($dL['markedDue'] ?? null));

    /* a row with desk extras AND a woman on a single sleeper: two facts, two lines - neither cut */
    [$tripE, $rowsE, , $totalsE, $fmtE] = fx1_sheet($SID, 14, ['copy' => ['kind' => 'desk', 'tag' => 'DTWO', 'label' => 'Two Lines', 'no' => FX1_NO . '-TWO']]);
    $fmtE['money'] = static fn(array $r): array => ['ticket' => 2200.0, 'extra' => 500.0, 'cash' => 2700.0, 'online' => 0.0];
    $fmtE['rowState'] = static fn(array $r): string => 'paid';
    $two = ChalaniPng::render($tripE, $rowsE, $date, $totalsE, $fmtE, 1);
    $dE = ChalaniPng::$drawn;
    $keep('two-line-remarks.png', $two['path']);
    check('remarks: "Extra Rs 500 PAID · WOMAN single" is whole (two lines), like every other remark on the page', in_array('Extra Rs 500 PAID · WOMAN single', (array) $dE['remarks'], true)
        && array_filter((array) $dE['remarks'], static fn(string $s): bool => str_contains($s, '..')) === [], json_encode(array_values(array_unique((array) $dE['remarks']))));

    /* direction + date */
    check('direction: Surat -> Rupaidiha is TO NEPAL, India flag first', ChalaniPng::toNepal($trip) && ChalaniPng::flagFirst($trip) === 'IN' && $d['flagFirst'] === 'IN');
    [$tripR, $rowsR, , $totalsR, $fmtR] = fx1_sheet($SID, 3, ['toNepal' => false, 'copy' => ['kind' => 'desk', 'tag' => 'DRET', 'label' => 'Return', 'no' => FX1_NO . '-RET']]);
    $rr = ChalaniPng::render($tripR, $rowsR, $date, $totalsR, $fmtR, 1);
    $dR = ChalaniPng::$drawn;
    $keep('return-p1.png', $rr['path']);
    check('direction: Rupaidiha -> Surat is the RETURN run, Nepal flag first', !ChalaniPng::toNepal($tripR) && ChalaniPng::flagFirst($tripR) === 'NP' && $dR['flagFirst'] === 'NP');
    check('direction: other Nepal towns count too (Nepalgunj, Kohalpur), Gujarat towns do not',
        ChalaniPng::toNepal(['to_city' => 'Nepalgunj']) && ChalaniPng::toNepal(['to_city' => 'Kohalpur']) && !ChalaniPng::toNepal(['to_city' => 'Rajkot']) && !ChalaniPng::toNepal(['to_city' => '']));
    require_once INCLUDE_PATH . '/bsdate.php';
    $bs = BsDate::fromAd('2026-11-26');
    check('BS: 2026-11-26 is in the table', is_array($bs), json_encode($bs));
    if (is_array($bs)) {
        check('BS (Roman, the PNG): "BS YYYY-MM-DD"', ChalaniPng::bsDate('2026-11-26', 'ro') === sprintf('BS %04d-%02d-%02d', $bs['y'], $bs['m'], $bs['d']), ChalaniPng::bsDate('2026-11-26', 'ro'));
        check('BS (Nepali, the PDF / sheet): वि.सं. + Nepali digits + the BS month', str_starts_with(ChalaniPng::bsDate('2026-11-26', 'ne'), 'वि.सं. ')
            && str_contains(ChalaniPng::bsDate('2026-11-26', 'ne'), BsDate::MONTH_NAMES[$bs['m'] - 1]), ChalaniPng::bsDate('2026-11-26', 'ne'));
    }
    $bsF = BsDate::fromAd(FX1_DATE);
    check('the page date carries AD' . ($bsF !== null ? ' and BS' : ' (the test date is outside the BS table: AD alone, never a guessed date)'),
        str_starts_with((string) $d['date'], strtoupper(formatDate(FX1_DATE, 'D, j M Y'))) && ($bsF === null ? !str_contains((string) $d['date'], 'BS') : str_contains((string) $d['date'], 'BS ' . $bsF['y'])), (string) $d['date']);
    check('the three head fields (number, two-calendar date, bus) are whole', array_filter((array) ($d['fields'] ?? []), static fn(string $s): bool => str_contains($s, '..')) === []
        && count((array) ($d['fields'] ?? [])) === 3 && ($d['fields']['DATE'] ?? '') === $d['date'], json_encode($d['fields'] ?? null));
    check('BS: a date outside the table gives \'\' (AD alone)', ChalaniPng::bsDate('1900-01-01', 'ro') === '' && ChalaniPng::bsDate('not-a-date', 'ne') === '');
    $bsKeep = Database::fetch('SELECT svalue, stype, sgroup FROM settings WHERE skey = :k', ['k' => 'chalani_bs_date_on']);
    $fpOn = ChalaniPng::fingerprint($trip, $rows, $date, $totals, $fmt);
    Settings::set('chalani_bs_date_on', '0', 'bool', 'booking', false); Settings::flush();
    check('switch chalani_bs_date_on = 0: no BS date, and the cached pages redraw', ChalaniPng::bsDate('2026-11-26', 'ro') === '' && ChalaniPng::fingerprint($trip, $rows, $date, $totals, $fmt) !== $fpOn);
    if ($bsKeep === null) { Database::delete('settings', 'skey = :k', ['k' => 'chalani_bs_date_on']); }
    else { Settings::set('chalani_bs_date_on', (string) $bsKeep['svalue'], (string) $bsKeep['stype'], (string) $bsKeep['sgroup'], false); }
    Settings::flush();
    check('  …default (no row) is ON', ChalaniPng::bsOn() === true || $bsKeep !== null);

    /* seat chart: extra seats + lap infants */
    [$tripX, $rowsX, , $totalsX, $fmtX] = fx1_sheet($SID, 12, ['extras' => 3, 'infant' => true, 'copy' => ['kind' => 'desk', 'tag' => 'DEXT', 'label' => 'Extras', 'no' => FX1_NO . '-EXT']]);
    $cx = ChalaniPng::renderChart($tripX, $rowsX, $date, $totalsX, $fmtX, 2, 2);
    $dX = ChalaniPng::$drawn;
    $keep('chart-extras.png', $cx['path']);
    check('chart: the 3 extra seats (EX1-EX3) are drawn', (int) ($dX['extraSeats'] ?? -1) === 3, json_encode($dX));
    check('chart: every one of the 12 passengers has a cell (9 berths + 3 extra)', (int) ($dX['berths'] ?? -1) === 12, json_encode($dX));
    check('chart: the lap infants are tagged (+1 INF)', (int) ($dX['infantTags'] ?? -1) === 2, json_encode($dX));
    check('chart: the copy\'s own number in the head', $dX['chalaniNo'] === FX1_NO . '-EXT' && $dX['copy'] === 'desk');
    $size = @getimagesize($cx['path']);
    check('chart: still the A4 landscape picture', is_array($size) && $size[0] === ChalaniPng::WIDTH && $size[1] === ChalaniPng::HEIGHT);

    /* ================================================================== */
    echo "\n-- (c) PDF\n";
    $pdfPages = static fn(string $pdf): int => (int) preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf);
    $pdfOf = static function (int $n, array $o) use ($SID): array {
        [$out, $err] = child('--pdf', ['sid' => $SID, 'n' => $n, 'o' => $o]);
        $j = strrpos($err, '{"rows"');
        return [$out, $j !== false ? (array) json_decode(substr($err, $j), true) : ['err' => substr($err, 0, 400)]];
    };
    [$pdf, $pd] = $pdfOf(21, ['infant' => true, 'markDue' => true]);
    $keep('whole.pdf', $pdf, false);
    check('PDF builds', str_starts_with($pdf, '%PDF'), json_encode($pd));
    check('PDF: 21 passengers still ONE sheet page + the seat chart (page economy kept)', $pdfPages($pdf) === 2, 'pages ' . $pdfPages($pdf));
    check('PDF: nothing below the footer rule', (float) ($pd['maxBottom'] ?? 999) <= (float) ($pd['limit'] ?? 0), ($pd['maxBottom'] ?? '?') . ' <= ' . ($pd['limit'] ?? '?'));
    check('PDF: the real ticket number TKT-261126-G4YTK7 is drawn WHOLE (it lost its last letter)', in_array('TKT-261126-G4YTK7', (array) ($pd['tickets'] ?? []), true), json_encode($pd['tickets'] ?? []));
    check('PDF: the widest ticket number (…-WWWWWW) is whole too', in_array('TKT-261126-WWWWWW', (array) ($pd['tickets'] ?? []), true));
    check('PDF: no ticket number is cut', array_filter((array) ($pd['tickets'] ?? []), static fn(string $s): bool => str_contains($s, '..') || str_contains($s, '…')) === [] && count((array) ($pd['tickets'] ?? [])) === 21);
    check('PDF: the border ID "CTZ **8901" is drawn whole', in_array('CTZ **8901', (array) ($pd['ids'] ?? []), true) && array_unique((array) $pd['ids']) === ['CTZ **8901'], json_encode($pd['ids'] ?? []));
    check('PDF: …beside the 17-letter name, whole - also on the row that carries a lap infant too', ((array) ($pd['names'] ?? []))[0] === 'Ram Bahadur Thapa'
        && count(array_keys((array) $pd['names'], 'Ram Bahadur Thapa', true)) === 4, json_encode($pd['names'] ?? [], JSON_UNESCAPED_UNICODE));
    check('PDF: the lap-infant mark is its own piece (a long name no longer pushes it off)', ($pd['infantMarks'] ?? []) === ['+ शिशु १'], json_encode($pd['infantMarks'] ?? [], JSON_UNESCAPED_UNICODE));
    check('PDF: the long place name "Rupaidiha Border Bus Park" is whole', array_filter((array) ($pd['places'] ?? []), static fn(string $s): bool => str_ends_with($s, 'Rupaidiha Border Bus Park')) !== []
        && array_filter((array) ($pd['places'] ?? []), static fn(string $s): bool => str_ends_with($s, '..') || str_ends_with($s, '…')) === [], json_encode(array_values(array_unique((array) ($pd['places'] ?? []))), JSON_UNESCAPED_UNICODE));
    $cutNames = array_values(array_filter((array) ($pd['names'] ?? []), static fn(string $s): bool => str_ends_with($s, '..') || str_ends_with($s, '…')));
    check('PDF: a name that has to shorten ends in ".." (a cut shows; the Latin face has no "…")', array_filter($cutNames, static fn(string $s): bool => str_ends_with($s, '…') && preg_match('/\p{Devanagari}/u', $s) !== 1) === [], json_encode($cutNames, JSON_UNESCAPED_UNICODE));
    check('PDF: flags India first (to Nepal), the date carries AD', ($pd['flagFirst'] ?? '') === 'IN' && str_contains((string) ($pd['date'] ?? ''), '२०३३'), json_encode([$pd['flagFirst'] ?? null, $pd['date'] ?? null], JSON_UNESCAPED_UNICODE));
    check('PDF: whole bus has no copy line', ($pd['copy'] ?? 'x') === '');
    check('PDF: the summary owns up to the office-marked-due money', array_filter((array) ($pd['sum'] ?? []), static fn(string $s): bool => str_contains($s, 'कार्यालयले बाँकी राखेको') && str_contains($s, '2,200')) !== [], json_encode($pd['sum'] ?? [], JSON_UNESCAPED_UNICODE));
    [$pdfR, $pdR] = $pdfOf(5, ['toNepal' => false, 'agentCopy' => true]);
    $keep('agent-return.pdf', $pdfR, false);
    check('PDF (return run, agent copy): Nepal flag first, "एजेन्ट प्रति · AGENT COPY — <agent>"', str_starts_with($pdfR, '%PDF') && ($pdR['flagFirst'] ?? '') === 'NP'
        && str_starts_with((string) ($pdR['copy'] ?? ''), 'एजेन्ट प्रति · AGENT COPY') && str_contains((string) $pdR['copy'], 'Fx One Agent'), json_encode($pdR['copy'] ?? null, JSON_UNESCAPED_UNICODE));
    [$pdfX, $pdX] = $pdfOf(12, ['extras' => 3, 'infant' => true]);
    $keep('extras.pdf', $pdfX, false);
    check('PDF chart: the 3 extra seats are drawn, lap infants tagged', str_starts_with($pdfX, '%PDF') && (int) ($pdX['extraSeats'] ?? -1) === 3 && (int) ($pdX['infantTags'] ?? -1) === 2, json_encode([$pdX['extraSeats'] ?? null, $pdX['infantTags'] ?? null]));
    check('PDF chart: the page count is unchanged (sheet + chart)', $pdfPages($pdfX) === 2, 'pages ' . $pdfPages($pdfX));

    /* ================================================================== */
    echo "\n-- (d) small rules\n";
    check('CSV: "=1+1" becomes text', ChalaniStrip::csvText('=1+1') === "'=1+1");
    check('CSV: + - @ tab CR at the start too', ChalaniStrip::csvText('+cmd') === "'+cmd" && ChalaniStrip::csvText('-2+3') === "'-2+3" && ChalaniStrip::csvText('@SUM(A1)') === "'@SUM(A1)"
        && ChalaniStrip::csvText("\t=1") === "'\t=1" && ChalaniStrip::csvText("\r=1") === "'\r=1");
    check('CSV: a plain name, a number, an empty cell and a Devanagari name are untouched', ChalaniStrip::csvText('Ram') === 'Ram' && ChalaniStrip::csvText('2200') === '2200'
        && ChalaniStrip::csvText('') === '' && ChalaniStrip::csvText(null) === '' && ChalaniStrip::csvText('सीता') === 'सीता' && ChalaniStrip::csvText('Ram =1') === 'Ram =1');
    check('thermal switch: on by default', ChalaniStrip::thermalOn() === true || Database::fetch('SELECT 1 FROM settings WHERE skey = :k', ['k' => 'chalani_thermal_on']) !== null);

    /* ================================================================== */
    echo "\n-- (e) the real pages, on a small departure this suite seeds (marked ZZFX1, removed after)\n";
    $boss = Database::fetch("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1");
    $seed = $boss !== null ? fx1_seed() : null;
    if ($boss === null || $seed === null) {
        skip('sheetFor parity, download-chalan redraw, A4 sheet, 80 mm list, CSV', 'no superadmin, no active sleeper route or no free far date on the test DB');
    } else {
        $rsid = (int) $seed['sid']; $rdate = (string) $seed['date'];
        $rdir = rtrim(UPLOAD_PATH, '/\\') . '/chalani/' . $rdate;
        $mfGet = ['date' => $rdate, 'route' => (int) $seed['routeId'], 'sid' => $rsid];
        $asBoss = static fn(array $get): array => child('--page', ['script' => '/admin/manifest.php', 'admin' => (int) $boss['id'], 'get' => $get]);
        echo "  (departure sid $rsid · $rdate · 7 passengers, 6 bookings)\n";

        /* manifest.php draws page 1 with its OWN inline code ... */
        [$png, $err] = $asBoss($mfGet + ['format' => 'chalanipng', 'page' => 1]);
        $keep('real-whole-p1.png', $png, false);
        check('manifest.php (superadmin) serves chalani PNG page 1', httpOf($err) === 200 && str_starts_with($png, "\x89PNG"), 'HTTP ' . httpOf($err) . ' ' . substr($err, 0, 200));
        /* ... and sheetFor() must name the very same file: whole bus, an NPR desk's copy, a rupee desk's copy */
        $wantOf = static function (int $sid, string $desk): array {
            $sh = ChalaniPng::sheetFor($sid, $desk);
            if ($sh === null) { return ['', null]; }
            return [ChalaniPng::fileStem($sh['trip'], $sh['fmt']) . 'p1of' . $sh['pages'] . '-' . substr(ChalaniPng::fingerprint($sh['trip'], $sh['rows'], $sh['date'], $sh['totals'], $sh['fmt']), 0, 8) . '.png', $sh];
        };
        [$wantW, $sh] = $wantOf($rsid, '');
        check('seed: 7 passengers on the sheet, one page', $sh !== null && count($sh['rows']) === 7 && $sh['pages'] === 1, json_encode([$sh !== null ? count($sh['rows']) : null]));
        check('PARITY (whole bus): ChalaniPng::sheetFor() names the same picture file as manifest.php', $wantW !== '' && is_file($rdir . '/' . $wantW),
            $wantW . ' vs ' . implode(',', array_map('basename', (array) glob($rdir . '/*-S' . $rsid . '-*.png'))));
        check('  …and it is a whole-bus sheet: no tag, the office number, the border ID tag on', $sh !== null && ChalaniPng::copyTag($sh['fmt']) === ''
            && !str_contains((string) $sh['totals']['chalaniNo'], '-NPJ') && (PaxId::enabled() ? is_callable($sh['fmt']['paxId']) : $sh['fmt']['paxId'] === null));
        foreach (['NPJ' => 'an NPR desk (money in NPR)', 'SRT' => 'a rupee desk'] as $dk => $what) {
            if (ChalaniPng::deskCode($dk) === '') { skip("PARITY (desk copy $dk)", 'no such desk on the test DB'); continue; }
            [$pd1, $pdErr] = $asBoss($mfGet + ['format' => 'chalanipng', 'page' => 1, 'desk' => $dk]);
            $keep('real-desk-' . $dk . '-p1.png', $pd1, false);
            [$wantD, $shD] = $wantOf($rsid, $dk);
            check("PARITY (desk copy $dk, $what): the same file, with the copy tag D$dk", httpOf($pdErr) === 200 && $wantD !== '' && str_contains($wantD, '-S' . $rsid . '-D' . $dk . '-p1of1-')
                && is_file($rdir . '/' . $wantD), $wantD . ' vs ' . implode(',', array_map('basename', (array) glob($rdir . '/*-S' . $rsid . '-D' . $dk . '-*.png'))));
            check("  …$dk: one passenger, its own number (…-$dk)" . ($dk === 'NPJ' ? ', NPR totals with the rupees beside' : ''), $shD !== null && count($shD['rows']) === 1
                && str_ends_with((string) $shD['totals']['chalaniNo'], '-' . $dk) && ($dk !== 'NPJ' || (($shD['fmt']['cur']['code'] ?? '') === 'NPR' && isset($shD['totals']['inr']['net'])
                && abs((float) $shD['totals']['net'] - 3520.0) < 0.01 && abs((float) $shD['totals']['inr']['net'] - 2200.0) < 0.01)), json_encode($shD['totals'] ?? null));
        }
        check('THE BUG: after two desk copies were drawn, the whole-bus page is still there (they used to replace it)', is_file($rdir . '/' . $wantW)
            && realpath(ChalaniPng::newestFile($rsid, 1)) === realpath($rdir . '/' . $wantW));
        check('sheetFor: an unknown departure is null', ChalaniPng::sheetFor(0) === null && ChalaniPng::sheetFor(2147483000) === null);

        /* download-chalan.php: a signed link, nobody signed in */
        $lk = ChalaniStrip::shareLinks($rsid, 1, 1)[1];
        parse_str((string) parse_url($lk, PHP_URL_QUERY), $q1);
        foreach ((array) glob($rdir . '/*-S' . $rsid . '-p1of*.png') as $f) { if (is_string($f)) { @unlink($f); } }
        check('(the whole-bus page file is removed, as if nobody had opened the PNG pages)', ChalaniPng::newestFile($rsid, 1) === '');
        [$dl, $dErr] = child('--page', ['script' => '/download-chalan.php', 'admin' => 0, 'get' => $q1]);
        check('download-chalan: a signed link is DRAWN ON DEMAND (it used to answer 404 "not drawn yet")', httpOf($dErr) === 200 && str_starts_with($dl, "\x89PNG")
            && is_file($rdir . '/' . $wantW), 'HTTP ' . httpOf($dErr) . ' ' . substr($dl, 0, 120));
        $exp = time() + 600;
        $sig = static fn(int $page, string $tag = ''): array => ['sid' => $rsid, 'doc' => 'chalani', 'page' => $page, 'exp' => $exp,
            'k' => substr(Security::sign('chalan-dl|' . $rsid . '|chalani|' . $page . '|' . $exp . ($tag !== '' ? '|' . $tag : '')), 0, 20)] + ($tag !== '' ? ['c' => $tag] : []);
        [$dc, $cErr] = child('--page', ['script' => '/download-chalan.php', 'admin' => 0, 'get' => $sig(2)]);
        $keep('real-chart.png', $dc, false);
        check('download-chalan: the page after the last sheet page is the seat chart (the hub offers it)', httpOf($cErr) === 200 && str_starts_with($dc, "\x89PNG"), 'HTTP ' . httpOf($cErr));
        [, $nErr] = child('--page', ['script' => '/download-chalan.php', 'admin' => 0, 'get' => $sig(3)]);
        check('download-chalan: a page past the end is 404, not somebody else\'s picture', httpOf($nErr) === 404, 'HTTP ' . httpOf($nErr));
        if (ChalaniPng::deskCode('NPJ') !== '') {
            [$dd, $ddErr] = child('--page', ['script' => '/download-chalan.php', 'admin' => 0, 'get' => $sig(1, 'DNPJ')]);
            check('download-chalan: a desk copy\'s signed link serves THAT copy', httpOf($ddErr) === 200 && str_starts_with($dd, "\x89PNG") && md5($dd) !== md5($dl), 'HTTP ' . httpOf($ddErr));
        }
        [, $bErr] = child('--page', ['script' => '/download-chalan.php', 'admin' => 0, 'get' => ['c' => 'DNPJ'] + $q1]);
        check('download-chalan: a whole-bus key does not open a copy (the tag is signed)', httpOf($bErr) === 403, 'HTTP ' . httpOf($bErr));
        [, $b2Err] = child('--page', ['script' => '/download-chalan.php', 'admin' => 0, 'get' => ['k' => 'x' . substr((string) $q1['k'], 1)] + $q1]);
        check('download-chalan: a wrong key is refused', httpOf($b2Err) === 403, 'HTTP ' . httpOf($b2Err));
        [, $b3Err] = child('--page', ['script' => '/download-chalan.php', 'admin' => 0, 'get' => ['exp' => time() - 5] + $q1]);
        check('download-chalan: an expired link is refused', httpOf($b3Err) === 403, 'HTTP ' . httpOf($b3Err));
        $rdKeep = Database::fetch('SELECT svalue, stype, sgroup FROM settings WHERE skey = :k', ['k' => 'chalani_dl_redraw_on']);
        Settings::set('chalani_dl_redraw_on', '0', 'bool', 'booking', false); Settings::flush();
        [$dl0, $d0Err] = child('--page', ['script' => '/download-chalan.php', 'admin' => 0, 'get' => $q1]);
        check('switch chalani_dl_redraw_on = 0: the newest drawn file is served, as before', httpOf($d0Err) === 200 && md5($dl0) === md5($dl), 'HTTP ' . httpOf($d0Err));
        foreach ((array) glob($rdir . '/*-S' . $rsid . '-p1of*.png') as $f) { if (is_string($f)) { @unlink($f); } }
        [, $d00Err] = child('--page', ['script' => '/download-chalan.php', 'admin' => 0, 'get' => $q1]);
        check('  …and with no drawn file it answers 404, as before', httpOf($d00Err) === 404, 'HTTP ' . httpOf($d00Err));
        if ($rdKeep === null) { Database::delete('settings', 'skey = :k', ['k' => 'chalani_dl_redraw_on']); }
        else { Settings::set('chalani_dl_redraw_on', (string) $rdKeep['svalue'], (string) $rdKeep['stype'], (string) $rdKeep['sgroup'], false); }
        Settings::flush();

        /* the A4 sheet, the PDF, the CSV and the 80 mm list */
        [$sheet, $sErr] = $asBoss($mfGet + ['format' => 'chalani']);
        $keep('real-sheet.html', $sheet, false);
        check('A4 sheet renders', httpOf($sErr) === 200 && str_contains($sheet, 'बस चलानी · यात्रु विवरण') && str_contains($sheet, '@page { size: A4 landscape'), 'HTTP ' . httpOf($sErr));
        check('A4 sheet: the page footer rule (chalani no. · page / pages)', str_contains($sheet, '@bottom-right') && str_contains($sheet, 'counter(pages)') && str_contains($sheet, (string) $sh['totals']['chalaniNo'] . ' · " counter(page)'));
        check('A4 sheet: name, then the border ID, then the number - each its own piece (the name takes the "…")', (bool) preg_match('#<span class="nmw"><span class="nmn">ZZTEST fx1 Ram Bahadur</span>' . (PaxId::enabled() ? '<small class="pid">🪪 CTZ \*\*8901</small>' : '') . '<small>ZZFX1-[0-9A-F]+</small></span>#u', $sheet)
            && substr_count($sheet, '<td class="c n">') >= 25);
        check('A4 sheet: the lap-infant mark on the booking\'s first row, between the name and the number', (bool) preg_match('#<span class="nmn"><b class="wsb">♀</b> ZZTEST fx1 सीता कुमारी</span><b class="inf">\+ शिशु १</b><small>ZZFX1-#u', $sheet)
            || (bool) preg_match('#<span class="nmn">ZZTEST fx1 सीता कुमारी</span><b class="inf">\+ शिशु १</b><small>ZZFX1-#u', $sheet));
        check('A4 sheet: no GPS line under the mobile number any more (it doubled the row)', !str_contains($sheet, '<br><a class="geo"'));
        check('A4 sheet: corridor words follow the direction (Surat -> Rupaidiha), the border note too', substr_count($sheet, 'गुजरात → रुपैडिहा') >= 3 && !str_contains($sheet, 'गुजरात ⇄ रुपैडिहा')
            && !str_contains($sheet, 'रुपैडिहा → गुजरात') && str_contains($sheet, '(भारत–नेपाल)'));
        $bsReal = ChalaniPng::bsDate($rdate, 'ne');
        check('A4 sheet: the BS date beside the AD date', $bsReal !== '' && str_contains($sheet, htmlspecialchars($bsReal) . ' · '), $bsReal);
        check('A4 sheet: the extra seat and the unknown place (numbered after the route\'s own) are listed', str_contains($sheet, '>EX1<') && str_contains($sheet, 'ZZ Fx1 Halt'));
        $stripS = ChalaniStrip::fromRows($sh['rows'], $sh['fmt']['money'], $sh['fmt']['payMark']);
        check('money: Total = Cash + Online + Due + Other, to the rupee (7 seats: 6 x 2,200 + 2 x 2,200 with 500 extras)', abs($stripS['amount'] - ($stripS['cash'] + $stripS['online'] + $stripS['due'] + $stripS['other'])) < 0.01
            && abs($stripS['amount'] - 15900.0) < 0.01 && abs($stripS['cash'] - 9300.0) < 0.01 && abs($stripS['online'] - 2200.0) < 0.01 && abs($stripS['due'] - 4400.0) < 0.01 && $stripS['dueN'] === 2, json_encode(array_diff_key($stripS, ['rows' => 1])));
        check('A4 sheet prints exactly those figures', str_contains($sheet, 'जम्मा यात्री ७ · ₹ 15,900') && str_contains($sheet, 'नगद ₹ 9,300') && str_contains($sheet, 'अनलाइन ₹ 2,200') && str_contains($sheet, 'ड्यू बाँकी ₹ 4,400'));
        [$rpdf, $pErr] = $asBoss($mfGet + ['format' => 'chalanipdf']);
        $keep('real.pdf', $rpdf, false);
        check('the PDF of the departure builds', str_starts_with($rpdf, '%PDF'), 'HTTP ' . httpOf($pErr) . ' ' . substr($pErr, 0, 200));
        [$csv, $csvErr] = $asBoss($mfGet + ['format' => 'csv']);
        check('CSV: a name typed as a formula is written as text (leading apostrophe), the others untouched', httpOf($csvErr) === 200
            && str_contains($csv, '"\'=HYPERLINK(""http://example.invalid"",""ZZTEST fx1 Formula"")"') && !str_contains($csv, ',"=HYPERLINK') && !str_contains($csv, ",=HYPERLINK")
            && str_contains($csv, '"ZZTEST fx1 Ram Bahadur"'), substr($csv, 0, 300));
        [$t80, $tErr] = $asBoss($mfGet + ['format' => 'chalani80']);
        $keep('real-80mm.html', $t80, false);
        check('80 mm list renders: @page 80mm, the chalani number, BS + AD date, the four figures', httpOf($tErr) === 200 && str_contains($t80, '@page { size: 80mm auto')
            && str_contains($t80, (string) $sh['totals']['chalaniNo']) && str_contains($t80, htmlspecialchars($bsReal)) && str_contains($t80, 'जम्मा यात्री ७') && str_contains($t80, '₹ 15,900')
            && str_contains($t80, 'नगद · Cash') && str_contains($t80, '₹ 9,300') && str_contains($t80, 'अनलाइन · Online') && str_contains($t80, 'ड्यू बाँकी · Due (२ जना)') && str_contains($t80, '₹ 4,400'), 'HTTP ' . httpOf($tErr) . ' ' . substr($tErr, 0, 200));
        $phones = array_values(array_unique(array_map(static fn(array $r): string => (string) $r['contact_phone'], $sh['rows'])));
        $leak = array_filter($phones, static fn(string $p): bool => str_contains($t80, $p));
        check('80 mm list: passenger phones as the last 4 digits only (driver_list_phones is ' . (Settings::getBool('driver_list_phones', false) ? 'ON here: full numbers allowed' : 'off') . ')',
            Settings::getBool('driver_list_phones', false) || ($leak === [] && str_contains($t80, '••••6071')), count($leak) . ' full number(s)');
        check('80 mm list: every passenger is on it, place by place; pay at boarding says बसमा ₹2,200', substr_count($t80, '<td class="st">') === 7 && str_contains($t80, 'ZZ Fx1 Halt') && str_contains($t80, 'बसमा ₹2,200'), substr_count($t80, '<td class="st">') . ' rows');
        $thKeep = Database::fetch('SELECT svalue, stype, sgroup FROM settings WHERE skey = :k', ['k' => 'chalani_thermal_on']);
        Settings::set('chalani_thermal_on', '0', 'bool', 'booking', false); Settings::flush();
        [$t0, $t0Err] = $asBoss($mfGet + ['format' => 'chalani80']);
        [$scr0, ] = $asBoss($mfGet);
        check('switch chalani_thermal_on = 0: the format is not found and the screen has no 80 mm button', httpOf($t0Err) === 404 && !str_contains($t0, '@page') && !str_contains($scr0, 'format=chalani80'), 'HTTP ' . httpOf($t0Err));
        if ($thKeep === null) { Database::delete('settings', 'skey = :k', ['k' => 'chalani_thermal_on']); }
        else { Settings::set('chalani_thermal_on', (string) $thKeep['svalue'], (string) $thKeep['stype'], (string) $thKeep['sgroup'], false); }
        Settings::flush();
        [$scr, $scErr] = $asBoss($mfGet);
        $keep('real-screen.html', $scr, false);
        check('screen renders with the 80 mm button; the pinned column heads are intact', httpOf($scErr) === 200 && str_contains($scr, 'format=chalani80')
            && str_contains($scr, '<th>Seat</th><th>Passenger</th><th>Phone</th><th>Boarding</th>') && str_contains($scr, 'id="mfStrip"'), 'HTTP ' . httpOf($scErr) . ' ' . substr($scErr, 0, 200));
        check('screen: dark-theme rule for the women-single row, 44 px touch rule', str_contains($scr, ':root[data-theme="dark"] tr.mf-ws td') && str_contains($scr, '.wa-mini { width:44px; height:44px;'));

        /* the public live link: this far date is outside its -3 … +30 day window, so only the gate is looked at here */
        $pk = OpsHub::publicKey(false);
        if ($pk === '' || !OpsHub::publicOn()) {
            skip('public live link', 'no public key on the test DB (or the link is switched off) - not created by this suite');
        } else {
            [$pub, $puErr] = child('--page', ['script' => '/chalani-live.php', 'admin' => 0, 'get' => ['k' => $pk]]);
            $keep('real-live.html', $pub, false);
            check('public link renders with the right key', httpOf($puErr) === 200 && str_contains($pub, 'Live Chalani'), 'HTTP ' . httpOf($puErr) . ' ' . substr($puErr, 0, 200));
            check('public link: re-reads only while the tab is visible', str_contains($pub, "document.visibilityState === 'hidden'") && str_contains($pub, "addEventListener('visibilitychange'"));
            check('public link: no money strip, no ID numbers', !str_contains($pub, 'नगद · Cash') && !str_contains($pub, '🪪'));
            [$wk, $wkErr] = child('--page', ['script' => '/chalani-live.php', 'admin' => 0, 'get' => ['k' => 'wrong-key']]);
            check('public link: a wrong key shows nothing (404)', httpOf($wkErr) === 404 && !str_contains($wk, 'Live Chalani ·'), 'HTTP ' . httpOf($wkErr));
        }
    }

    /* ================================================================== */
    echo "\n-- (f) pins other suites read\n";
    $png = (string) file_get_contents(INCLUDE_PATH . '/chalanipng.php');
    $pdf = (string) file_get_contents(INCLUDE_PATH . '/chalanipdf.php');
    $mf  = (string) file_get_contents($ROOT . '/admin/manifest.php');
    $dlS = (string) file_get_contents($ROOT . '/download-chalan.php');
    $lv  = (string) file_get_contents($ROOT . '/chalani-live.php');
    check('u10-chalani-extras pin: the late-remark line', str_contains($png, "if (\$late)      { \$parts[] = \$xAmt > 0 ? 'late' : 'Booked after departure'; }"));
    check('op5 pins: paxId in manifest, PNG, PDF and the PNG seed', str_contains($mf, "'paxId' => \$scopeId === null && PaxId::enabled()") && str_contains($png, "\$fmt['paxId']")
        && str_contains($pdf, "\$fmt['paxId']") && str_contains($png, "\$seed['paxid']"));
    check('u12-G1 pin: Auth::staffActive() !== null exactly once in download-chalan.php', substr_count($dlS, 'Auth::staffActive() !== null') === 1);
    check('manifest-test pins: the screen heads are plain', str_contains($mf, '<th>Seat</th><th>Passenger</th><th>Phone</th><th>Boarding</th>') && str_contains($mf, '<th>Source</th><th>Agent</th><th>Ticket</th>'));
    check('the agent scope still guards the chalani branch (agent copy, no share, chalani.export)', str_contains($mf, "if ((!\$agentCopy && !ChalaniStrip::mayExport()) || (\$agentCopy && \$format === 'chalanishare'))"));
    check('download-chalan still refuses a scoped agent without a key', str_contains($dlS, 'Auth::bookingScopeAdminId() === null'));
    check('public link: the key is checked before the budget is chosen; a wrong key gets 60 an hour', str_contains($lv, "\$keyOk ? 'chalani_live' : 'chalani_live_bad'") && str_contains($lv, '$keyOk ? 6000 : 60'));
    check('public link: phones stay masked, no strip', str_contains($lv, 'OpsHub::maskPhone(') && !str_contains($lv, 'ChalaniStrip::'));
    check('LAYOUT_VERSION bumped once (20)', ChalaniPng::LAYOUT_VERSION === 20);
} catch (Throwable $e) {
    $FAIL++;
    echo "  ERROR  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    $wipe();
    fx1_unseed($seed ?? null);
    try { Database::run('DELETE FROM rate_limits WHERE identifier = :i', ['i' => FX1_IP]); } catch (Throwable $e) {}
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
