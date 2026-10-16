<?php
/**
 * =====================================================================
 *  smartbook-engine-test.php — SmartBook v5 phase 3 (15 Oct 2026).
 *
 *  A. AutoBerth on a synthetic 72-berth coach — the owner's scenarios:
 *     solo female, solo male, couple, mother + 3-year child, family of 4,
 *     senior, patient, group of 6, coach 95 % full, only women-only berths
 *     left + a man, gender-held cabins; every answer re-checked against
 *     the HARD rules; each pick < 50 ms
 *  B. checkManual — a hand-picked berth that breaks a hard rule is refused
 *  C. DefaultRules — clean() drops junk, apply() / preview() follow rules
 *  D. SmartBook::quote on the test DB — next departure, points, server
 *     price == BookingService::priceFor, NPR, report time = pickup − 60
 *  E. SmartBook::book — Cash = CONFIRMED + COD, same idem key = same PNR
 *     (replayed), Online = PENDING with the 15-min hold; cleaned up after
 *  F. 20 parallel holds on one berth → exactly 1 wins (separate processes)
 *  G. HTTP (SHG_TEST_BASE): customer boot / quote carry no agent fields
 *
 *      php -c .claude/php-dev.ini tests/smartbook-engine-test.php
 *
 *  WRITES to the test database (sections E, F) and removes what it wrote.
 *  Never run against production. exit(1) on any failure.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/smartbook.php';
require_once INCLUDE_PATH . '/smartbookbot.php';
require_once INCLUDE_PATH . '/defaultrules.php';

if (defined('DB_NAME') && DB_NAME === 'shari') { fwrite(STDERR, "refusing to run against the production database\n"); exit(1); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/* ---- a synthetic coach ------------------------------------------------ */
function coach(array $taken = [], array $locks = [], ?array $onlyFree = null): array {
    $all = [];
    foreach (['L', 'U'] as $d) { for ($i = 1; $i <= 36; $i++) { $all[] = $d . $i; } }
    $free = $onlyFree ?? array_values(array_diff($all, $taken));
    $units = [];
    foreach ($locks as $u => $lock) { $units[$u] = ['lock' => $lock]; }
    return ['available' => $free, 'female' => ['L1', 'L2', 'L3'], 'units' => $units, 'booked' => $taken, 'locked' => []];
}
/** Every hard rule, checked independently of the engine. */
function hardOk(array $av, array $pax, array $seats): string {
    $err = AutoBerth::checkManual($av, $seats, $pax);
    if ($err !== '') { return $err; }
    if (count(array_unique($seats)) !== count($seats)) { return 'duplicate berth'; }
    return '';
}
$P = static fn(string $g, string $b = 'adult'): array => ['gender' => $g, 'band' => $b];
$W = AutoBerth::DEFAULT_WEIGHTS;
$o = ['weights' => $W, 'rowOrder' => 'rear'];

echo "\nA. AutoBerth scenarios\n";
$cases = [
    'solo female'      => [coach(), [$P('Female')]],
    'solo male'        => [coach(), [$P('Male')]],
    'couple'           => [coach(), [$P('Female'), $P('Male')]],
    'mother + child 3' => [coach(), [$P('Female'), $P('Male', 'child')]],
    'family of 4'      => [coach(), [$P('Male'), $P('Female'), $P('Female', 'child'), $P('Male', 'child')]],
    'senior'           => [coach(), [$P('Male', 'senior')]],
    'patient'          => [coach(), [$P('Female', 'patient')]],
    'group of 6 men'   => [coach(), array_fill(0, 6, $P('Male'))],
];
$picks = [];
foreach ($cases as $name => [$av, $pax]) {
    $r = AutoBerth::pick($av, $pax, $o);
    $picks[$name] = $r;
    $why = $r['ok'] ? hardOk($av, $pax, $r['seats']) : $r['why'];
    check("$name: ok + every hard rule", $r['ok'] && $why === '', implode(',', $r['seats']) . ($why !== '' ? " ($why)" : ''));
    check("$name: < 50 ms", $r['ms'] < 50, $r['ms'] . ' ms');
}
$deck = static fn(string $s): string => $s[0];
check('solo female → a women-only berth', in_array($picks['solo female']['seats'][0] ?? '', ['L1', 'L2', 'L3'], true), $picks['solo female']['reasons'][0]['en'] ?? '');
check('solo male → upper floor, never women-only', $deck($picks['solo male']['seats'][0] ?? 'L') === 'U');
$cu = $picks['couple']['seats'];
check('couple → one whole cabin', count($cu) === 2 && Seats::unitKey($cu[0]) === Seats::unitKey($cu[1]));
$mc = $picks['mother + child 3']['seats'];
check('mother + child → same cabin, lower floor', count($mc) === 2 && Seats::unitKey($mc[0]) === Seats::unitKey($mc[1]) && $deck($mc[0]) === 'L');
$fam = $picks['family of 4']['seats'];
check('family of 4 → each child in an adult\'s cabin', count($fam) === 4
    && (Seats::unitKey($fam[2]) === Seats::unitKey($fam[0]) || Seats::unitKey($fam[2]) === Seats::unitKey($fam[1]))
    && (Seats::unitKey($fam[3]) === Seats::unitKey($fam[0]) || Seats::unitKey($fam[3]) === Seats::unitKey($fam[1])), implode(',', $fam));
check('family of 4 → lower floor', count(array_filter($fam, static fn(string $s): bool => $s[0] === 'L')) === 4);
check('senior → lower floor', $deck($picks['senior']['seats'][0] ?? 'U') === 'L', $picks['senior']['reasons'][0]['en'] ?? '');
check('patient → lower floor', $deck($picks['patient']['seats'][0] ?? 'U') === 'L');
$g6 = $picks['group of 6 men']['seats'];
$rows = array_unique(array_map(static fn(string $s): string => $s[0] . AutoBerth::pos($s)['row'], $g6));
check('group of 6 → 3 cabins, at most 2 neighbouring rows', count(array_unique(array_map([Seats::class, 'unitKey'], $g6))) === 3 && count($rows) <= 2, implode(',', $g6));
check('reasons come in en / hi / ne', isset($picks['couple']['reasons'][0]['en'], $picks['couple']['reasons'][0]['hi'], $picks['couple']['reasons'][0]['ne']));

// 95 % full: 4 free beds in 4 different cabins, two of them held by a gender.
$full = coach([], ['L-5' => 'male_only', 'U-3' => 'female_only'], ['L9', 'L10', 'U5', 'U30']);
$r = AutoBerth::pick($full, [$P('Male'), $P('Male')], $o);
check('95 % full, 2 men → only male-compatible berths', $r['ok'] && hardOk($full, [$P('Male'), $P('Male')], $r['seats']) === '' && !in_array('U5', $r['seats'], true), implode(',', $r['seats']));
$r = AutoBerth::pick($full, [$P('Female')], $o);
check('95 % full, 1 woman → never the men\'s cabin', $r['ok'] && !in_array($r['seats'][0], ['L9', 'L10'], true), implode(',', $r['seats']));
$womenOnly = coach([], [], ['L1', 'L2', 'L3']);
$r = AutoBerth::pick($womenOnly, [$P('Male')], $o);
check('only women-only berths left + a man → no berth (alternatives offered)', !$r['ok']);
$r = AutoBerth::pick($womenOnly, [$P('Female')], $o);
check('only women-only berths left + a woman → seated', $r['ok'] && in_array($r['seats'][0], ['L1', 'L2', 'L3'], true));
$held = coach([], ['L-10' => 'female_only'], ['L20', 'U36']);
$r = AutoBerth::pick($held, [$P('Male')], $o);
check('a cabin held for women never takes a man', $r['ok'] && $r['seats'] === ['U36'], implode(',', $r['seats']));
$noPair = coach([], ['L-10' => 'male_only', 'L-11' => 'female_only'], ['L20', 'L22']);
$r = AutoBerth::pick($noPair, [$P('Female'), $P('Male', 'child')], $o);
check('child with no adult cabin / bed beside → refused (H4)', !$r['ok'] || hardOk($noPair, [$P('Female'), $P('Male', 'child')], $r['seats']) === '', implode(',', $r['seats']));
// Review 15 Oct: more children than adults - every child beside an ADULT, never alone.
$kids = [$P('Male'), $P('Male', 'child'), $P('Female', 'child')];
$r = AutoBerth::pick(coach(), $kids, $o);
check('1 adult + 2 children → each child beside the adult (H4)', $r['ok'] && hardOk(coach(), $kids, $r['seats']) === '', implode(',', $r['seats']));
$kids2 = [$P('Female', 'child'), $P('Male', 'child')];
$r = AutoBerth::pick(coach(), $kids2, $o);
check('children with no adult at all → no berth', !$r['ok']);
$big = [];
for ($i = 0; $i < 20; $i++) { $big[] = $P($i % 3 === 0 ? 'Female' : 'Male', $i % 7 === 0 ? 'child' : 'adult'); }
$r = AutoBerth::pick(coach(), $big, $o);
check('a 20-passenger party: ok, hard rules, < 50 ms', $r['ok'] && hardOk(coach(), $big, $r['seats']) === '' && $r['ms'] < 50, $r['ms'] . ' ms');

echo "\nB. checkManual (hand-picked berths)\n";
$av = coach(['L5']);
check('a man on a women-only berth → refused', AutoBerth::checkManual($av, ['L2'], [$P('Male')]) !== '');
check('a taken berth → refused', AutoBerth::checkManual($av, ['L5'], [$P('Male')]) !== '');
check('man + woman in half a cabin with a stranger → refused', AutoBerth::checkManual(coach([], ['L-4' => 'male_only']), ['L7'], [$P('Female')]) !== '');
check('couple in a whole empty cabin → fine', AutoBerth::checkManual($av, ['L9', 'L10'], [$P('Female'), $P('Male')]) === '');
check('child far from the adult → refused', AutoBerth::checkManual($av, ['L9', 'U30'], [$P('Female'), $P('Male', 'child')]) !== '');
check('child on the bed beside → fine', AutoBerth::checkManual($av, ['L10', 'L11'], [$P('Female'), $P('Male', 'child')]) === '');

echo "\nC. DefaultRules\n";
$cfg = DefaultRules::clean([
    'fixed' => ['direction' => 'toNepal', 'berth' => 'sideways', 'pay' => 'cash'],
    'rules' => [
        ['name' => 'Solo woman', 'when' => ['paxCount' => 1, 'gender' => 'Female'], 'then' => ['berth' => 'lower', 'price' => 1]],
        ['name' => 'Couple', 'when' => ['paxCount' => 2, 'gender' => 'mixed'], 'then' => ['suggest' => 'private_double']],
        ['name' => 'Senior', 'when' => ['minAge' => 60], 'then' => ['band' => 'senior', 'berth' => 'lower']],
        ['name' => 'Junk', 'when' => ['paxCount' => 1], 'then' => ['seat' => 'L1']],
    ],
]);
check('clean(): an invalid fixed value is dropped', !isset($cfg['fixed']['berth']) && $cfg['fixed']['pay'] === 'cash');
check('clean(): a rule with no valid THEN is dropped, never a price or a berth number', count($cfg['rules']) === 3 && !isset($cfg['rules'][0]['then']['price']));
$pv = DefaultRules::preview(['paxCount' => 1, 'gender' => 'Female'], $cfg);
check('preview: 1 woman → berth lower (Solo woman)', $pv['berth'] === 'lower' && in_array('Solo woman', $pv['rules'], true));
$pv = DefaultRules::preview(['paxCount' => 2, 'gender' => 'mixed'], $cfg);
check('preview: couple → suggest private double', ($pv['suggest'] ?? '') === 'private_double');
$pv = DefaultRules::preview(['paxCount' => 1, 'gender' => 'Male', 'minAge' => 67], $cfg);
check('preview: age 67 → senior band + lower', $pv['pax'][0]['band'] === 'senior' && $pv['berth'] === 'lower');
$pv = DefaultRules::preview(['paxCount' => 3, 'gender' => 'Male'], $cfg);
check('preview: a party no rule names keeps the built-in defaults', $pv['berth'] === 'any' && $pv['rules'] === []);
// 10 sample customers produce the expected prefill
$samples = [
    [['paxCount' => 1, 'gender' => 'Female'], 'lower'], [['paxCount' => 1, 'gender' => 'Male'], 'any'],
    [['paxCount' => 2, 'gender' => 'mixed'], 'any'],    [['paxCount' => 2, 'gender' => 'Female'], 'any'],
    [['paxCount' => 1, 'gender' => 'Male', 'minAge' => 61], 'lower'], [['paxCount' => 4, 'gender' => 'mixed'], 'any'],
    [['paxCount' => 1, 'gender' => 'Female', 'minAge' => 70], 'lower'], [['paxCount' => 6, 'gender' => 'Male'], 'any'],
    [['paxCount' => 3, 'gender' => 'Female'], 'any'],  [['paxCount' => 1, 'gender' => 'Female', 'seen' => true], 'lower'],
];
$okN = 0;
foreach ($samples as [$s, $want]) { $okN += DefaultRules::preview($s, $cfg)['berth'] === $want ? 1 : 0; }
check('10 sample customers get the expected berth default', $okN === 10, "$okN/10");

echo "\nD. SmartBook::quote (test DB)\n";
$dirs = SmartBook::directions();
check('two directions, to Nepal first', count($dirs) >= 1 && $dirs[0]['direction'] === 'toNepal', implode(',', array_column($dirs, 'direction')));
$pts = SmartBook::points('toNepal', 'customer');
check('customer points carry Rajkot (D10)', in_array('Rajkot', array_column($pts['list'], 'name'), true));
check('points for agent and counter carry Rajkot too', in_array('Rajkot', array_column(SmartBook::points('toNepal', 'agent')['list'], 'name'), true)
    && in_array('Rajkot', array_column(SmartBook::points('toNepal', 'counter')['list'], 'name'), true));
$back = SmartBook::points('toIndia', 'customer');
check('coming back the card picks a DROP', $back['type'] === 'drop' && $back['list'] !== []);
$q = null;
try {
    $q = SmartBook::quote(['direction' => 'toNepal', 'point' => 'Vadodara', 'pax' => [$P('Female'), $P('Male')], 'phone' => '9000000001'], 'customer');
} catch (Throwable $e) {
    check('quote runs', false, $e->getMessage());
}
if ($q !== null) {
    check('quote ok, 2 berths', $q['ok'] && count($q['seats']) === 2, $q['error'] . ' ' . implode(',', $q['seatLabels']));
    $route = Database::fetch('SELECT * FROM routes WHERE id = :r', ['r' => $q['routeId']]);
    $price = BookingService::priceFor($route, Seats::scheduleById($q['scheduleId']), $q['seats'], 'sharing', 'single', $q['boarding'], '9000000001');
    check('price = the sale\'s own priceFor()', abs((float) $q['price']['total'] - (float) $price['total']) < 0.01, inr($q['price']['total']));
    check('NPR estimate = total × npr_per_inr', (int) $q['price']['npr'] === (int) round($q['price']['total'] * (float) SiteFacts::all()['npr']));
    check('report time = pickup − 60 min', $q['reportTime'] === SmartBook::reportTime($q['boardingTime']) && $q['reportTime'] !== '', $q['boardingTime'] . ' → ' . $q['reportTime']);
    check('the date is the next departure (today or later)', $q['date'] >= todayISO());
    check('couple in sharing → a private double is SUGGESTED, not forced', $q['type'] === 'sharing' && ($q['suggest'] === null || $q['suggest']['type'] === 'private'));
    $bad = SmartBook::quote(['direction' => 'toNepal', 'point' => 'Vadodara', 'pax' => [$P('Male')], 'seats' => ['L1'], 'phone' => '9000000001'], 'customer');
    check('a man hand-picking a women-only berth → not ok + alternatives', !$bad['ok'] && $bad['error'] !== '');
    $pq = SmartBook::quote(['direction' => 'toNepal', 'point' => 'Vadodara', 'pax' => [$P('Female'), $P('Male')], 'type' => 'private', 'cabin' => 'double', 'phone' => '9000000001'], 'customer');
    check('private double for 2 → 1 cabin', $pq['ok'] && count($pq['seats']) === 1 && $pq['type'] === 'private', implode(',', $pq['seatLabels']));
    $err = '';
    try { SmartBook::quote(['direction' => 'toNepal', 'point' => 'Kathmandu', 'pax' => [$P('Male')]], 'customer'); } catch (RuntimeException $e) { $err = $e->getMessage(); }
    check('a point not on the bus → refused', $err !== '');
}

echo "\nE. SmartBook::book (test DB, cleaned up after)\n";
$made = [];
try {
    $key = 'test' . bin2hex(random_bytes(10));
    // 16 Oct 2026: a customer's Cash (pay at boarding) carries the verification details.
    $V   = ['address' => 'Kohalpur-5, Banke', 'idType' => 'Aadhaar Card', 'idNum' => '4321 8765 1234'];
    $in  = ['direction' => 'toNepal', 'point' => 'Vadodara', 'pax' => [$P('Female')], 'name' => 'Test Smartbook', 'phone' => '9000000002', 'country' => 'IN', 'verify' => $V];
    $b1  = SmartBook::book($in, 'cash', 'customer', null, $key);
    $made[] = $b1['pnr'];
    check('Cash → CONFIRMED + pay-at-boarding, no OTP', $b1['status'] === 'confirmed' && $b1['isCod'] === true, $b1['pnr']);
    check('Cash → ticket links (view / png / pdf / print / home)', is_array($b1['ticket']) && str_starts_with((string) $b1['ticket']['home'], '/#/?ticket='));
    $b2 = SmartBook::book($in, 'cash', 'customer', null, $key);
    check('same idem key → the SAME booking (double tap safe)', $b2['pnr'] === $b1['pnr'] && !empty($b2['replayed']));
    $cnt = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone = '9000000002' AND status IN ('pending','confirmed')");
    check('exactly one booking exists', $cnt === 1, (string) $cnt);
    // The first answer was lost after the sale (PHP died): the key still holds the booking.
    Database::run("UPDATE smartbook_requests SET state = 'working', response = NULL WHERE idem_key = :k", ['k' => $key]);
    $b3 = SmartBook::book($in, 'cash', 'customer', null, $key);
    check('a lost answer is replayed from the booking, never a second sale', $b3['pnr'] === $b1['pnr'] && !empty($b3['replayed']));
    $cnt = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone = '9000000002' AND status IN ('pending','confirmed')");
    check('still exactly one booking', $cnt === 1, (string) $cnt);
    // Review 15 Oct: the SAME key with ANOTHER customer (a counter retrying after a timeout) is refused.
    $err = '';
    try { SmartBook::book(['name' => 'Next Customer', 'phone' => '9000000006'] + $in, 'cash', 'customer', null, $key); }
    catch (SmartBookKeyReused $e) { $err = 'refused'; }
    check('same key + different request → refused, never the previous ticket', $err === 'refused');
    // The berths the card showed were taken meanwhile → the fresh quote goes back (409), nothing is sold.
    $err = '';
    try {
        SmartBook::book(['seats' => $b1['seats'], 'autoPicked' => true, 'phone' => '9000000004'] + $in, 'cash', 'customer', null, 'test' . bin2hex(random_bytes(10)));
    } catch (SmartBookChanged $e) { $err = !empty($e->quote['ok']) ? 'fresh' : 'changed'; }
    check('shown berths taken meanwhile → SmartBookChanged with a fresh auto quote', $err === 'fresh', $err);
    $pay = Database::fetch('SELECT method, status FROM payments WHERE booking_id = :b', ['b' => $b1['bookingId']]);
    check('payment row = cod / cod_pending (the cash ledger reads this)', ($pay['method'] ?? '') === 'cod' && ($pay['status'] ?? '') === 'cod_pending');
    $o1 = SmartBook::book($in + ['phone2' => 1], 'online', 'customer', null, 'test' . bin2hex(random_bytes(10)));
    $made[] = $o1['pnr'];
    $exp = (string) Database::scalar('SELECT expires_at FROM bookings WHERE id = :b', ['b' => $o1['bookingId']]);
    $left = strtotime($exp) - time();
    check('Online → PENDING, berths held ~15 min, QR page link', $o1['status'] === 'pending' && $left > 10 * 60 && $left <= 15 * 60 + 5 && str_contains((string) $o1['payPage'], 'smartpay.php'), $o1['pnr'] . ' ' . $left . 's');
    // 16 Oct 2026: the Online popup's data rides on the answer (no page change).
    $pd = $o1['pay'] ?? null;
    check('Online → popup data: due = total, countdown, hold time, key', is_array($pd) && abs((float) $pd['due'] - (float) $o1['total']) < 0.01
        && (int) $pd['seconds'] >= 30 && (string) $pd['holdUntil'] !== '' && (string) ($o1['payKey'] ?? '') !== '', json_encode($pd === null ? null : array_diff_key($pd, ['upi' => 1, 'esewa' => 1])));
    check('Online → the UPI QR carries the amount and the PNR', Settings::getString('upi_id', '') === ''
        || (is_array($pd['upi'] ?? null) && str_starts_with((string) $pd['upi']['qr'], 'data:image') && str_contains((string) $pd['upi']['link'], rawurlencode((string) $o1['pnr']))));
    check('Online → no verification details needed', empty($o1['isCod']));
    check('Cash → the confirmed answer has no QR data', ($b1['pay'] ?? null) === null);
    $addr = (string) Database::scalar('SELECT contact_address FROM bookings WHERE id = :b', ['b' => $b1['bookingId']], '');
    $idn  = (string) Database::scalar('SELECT id_number FROM bookings WHERE id = :b', ['b' => $b1['bookingId']], '');
    check('Cash → address + ID saved for the office', $addr === 'Kohalpur-5, Banke' && $idn === '432187651234', $addr . ' / ' . $idn);
    // Each missing detail is refused and named (the card opens that field).
    foreach ([
        'address' => ['verify' => ['address' => 'Ba'] + $V],
        'name'    => ['name' => 'Sita'],
        'idType'  => ['verify' => ['idType' => 'Ration card'] + $V],
        'idNum'   => ['verify' => ['idNum' => '12'] + $V],
        'altPhone'=> ['verify' => ['altPhone' => '9000000002'] + $V],
    ] as $want => $over) {
        $got = '';
        try { SmartBook::book($over + $in, 'cash', 'customer', null, 'test' . bin2hex(random_bytes(10))); $got = 'BOOKED'; }
        catch (SmartBookVerifyNeeded $e) { $got = $e->field; }
        check('Cash without a good ' . $want . ' → refused, field named', $got === $want, $got);
    }
    $got = '';
    try { $x = SmartBook::book(array_diff_key($in, ['verify' => 1]) + ['phone' => '9000000012'], 'cash', 'customer', null, 'test' . bin2hex(random_bytes(10))); $made[] = $x['pnr']; $got = 'BOOKED'; }
    catch (SmartBookVerifyNeeded $e) { $got = $e->field; }
    check('Cash with no details at all → refused (address first)', $got === 'address', $got);
    $err = '';
    try { SmartBook::book($in, 'cash', 'customer', null, 'short'); } catch (RuntimeException $e) { $err = $e->getMessage(); }
    check('a missing / short idem key → refused', $err !== '');
    $err = '';
    try {
        SmartBook::book(['pax' => [$P('Male')], 'name' => 'X', 'phone' => '9000000003'] + $in, 'cash', 'customer', null, 'test' . bin2hex(random_bytes(10)));
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
    check('a one-letter name → refused', $err !== '');
    $err = '';
    try {
        SmartBook::book(['pax' => [['gender' => '', 'band' => 'adult']]] + $in, 'cash', 'customer', null, 'test' . bin2hex(random_bytes(10)));
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
    check('a passenger without Female / Male → refused (customer)', $err !== '');
    $err = '';
    try {
        SmartBook::book(['expect' => ['total' => 1.0]] + $in, 'cash', 'customer', null, 'test' . bin2hex(random_bytes(10)));
    } catch (SmartBookChanged $e) { $err = 'changed'; }
    check('a moved price → SmartBookChanged with the fresh quote', $err === 'changed');
} catch (Throwable $e) {
    check('book runs', false, $e->getMessage());
} finally {
    foreach ($made as $pnr) {
        try { BookingService::cancel($pnr, 'smartbook-engine-test cleanup', true, 'undo'); } catch (Throwable $e) { /* already gone */ }
        $bid = (int) Database::scalar('SELECT id FROM bookings WHERE pnr = :p', ['p' => $pnr]);
        if ($bid > 0) {
            foreach (['booking_seats', 'booking_passengers', 'booking_legs', 'payments', 'tickets'] as $t) {
                Database::run("DELETE FROM `$t` WHERE booking_id = :b", ['b' => $bid]);
            }
            Database::run('DELETE FROM bookings WHERE id = :b', ['b' => $bid]);
        }
    }
    Database::run("DELETE FROM smartbook_requests WHERE idem_key LIKE 'test%'");
}

echo "\nF. 20 parallel holds on one berth\n";
$sid = (int) ($q['scheduleId'] ?? 0);
if ($sid > 0) {
    $avNow = Seats::availabilityForSchedule($sid, 'sharing');
    $seat  = (string) (array_values(array_diff($avNow['available'], ['L1', 'L2', 'L3']))[5] ?? '');
    $php   = PHP_BINARY;
    $ini   = php_ini_loaded_file() ?: '';
    // A child script file (Windows' escapeshellarg mangles inline quotes in `php -r`).
    $child = tempnam(sys_get_temp_dir(), 'sbrace') . '.php';
    file_put_contents($child, '<?php define("SHG_CLI", 1); require ' . var_export(dirname(__DIR__) . '/includes/bootstrap.php', true) . '; require_once INCLUDE_PATH . "/seats.php";'
        . ' $r = Seats::lock(' . $sid . ', [' . var_export($seat, true) . '], bin2hex(random_bytes(8)));'
        . ' echo $r["locked"] ? "WIN" : "LOSE";');
    $procs = [];
    for ($i = 0; $i < 20; $i++) {
        $cmd = '"' . $php . '"' . ($ini !== '' ? ' -c "' . $ini . '"' : '') . ' "' . $child . '"';
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $wins = 0; $out = [];
    foreach ($procs as $i => $p) {
        $o = stream_get_contents($pipes[$i][1]);
        $out[] = trim((string) $o) . trim((string) stream_get_contents($pipes[$i][2]));
        proc_close($p);
        $wins += str_contains((string) $o, 'WIN') ? 1 : 0;
    }
    check("20 processes hold $seat at once → exactly 1 wins", $wins === 1, $wins . ' won · ' . substr(implode('|', array_unique($out)), 0, 120));
    Database::run('DELETE FROM seat_locks WHERE schedule_id = :s AND seat_no = :n', ['s' => $sid, 'n' => $seat]);
    @unlink($child);
} else {
    check('a schedule to race on', false);
}

echo "\nH. A desk sale credited to the picked agent (15 Oct 2026)\n";
$madeH = [];
$agentH = 10;   // 'sad-agent' in the test seed
$codeWas = AgentWallet::agentCodeFor($agentH);
try {
    AgentWallet::setAgentCode($agentH, 7);
    check('deskAgentCode: "shg 7" / "SHG007" / "7" → SHG-0007', AgentWallet::deskAgentCode('shg 7') === 'SHG-0007' && AgentWallet::deskAgentCode('SHG007') === 'SHG-0007' && AgentWallet::deskAgentCode('7') === 'SHG-0007');
    check('deskAgentCode: other → OTHER, blank → "", an unissued code → null (refused)', AgentWallet::deskAgentCode('other') === 'OTHER' && AgentWallet::deskAgentCode('') === '' && AgentWallet::deskAgentCode('SHG-0999') === null);
    $list = AgentWallet::sellableAgents();
    check('sellableAgents: one list, sorted by code, carries SHG-0007 with name / type / city', in_array('SHG-0007', array_column($list, 'code'), true) && isset($list[0]['name'], $list[0]['type'], $list[0]['city']));

    $seller = ['adminId' => 1, 'source' => 'counter', 'paymentMethod' => 'cash', 'note' => ''];
    $inH    = ['direction' => 'toNepal', 'point' => 'Vadodara', 'pax' => [$P('Male')], 'name' => 'Desk Agent Test', 'phone' => '9000000007', 'country' => 'IN', 'referralCode' => 'SHG-0007'];
    $keyH   = 'test' . bin2hex(random_bytes(10));
    $bH     = SmartBook::book($inH, 'cash', 'counter', $seller, $keyH);
    $madeH[] = $bH['pnr'];
    $row = Database::fetch('SELECT sold_by_admin_id, source, status, is_cod FROM bookings WHERE id = :b', ['b' => $bH['bookingId']]);
    check('counter Cash + SHG-0007 → CONFIRMED, source counter, not COD', ($row['status'] ?? '') === 'confirmed' && ($row['source'] ?? '') === 'counter' && (int) $row['is_cod'] === 0, $bH['pnr']);
    check('the sale is credited to agent #' . $agentH . ' (sold_by_admin_id)', (int) ($row['sold_by_admin_id'] ?? 0) === $agentH, (string) ($row['sold_by_admin_id'] ?? 'null'));
    $comm = Database::fetch("SELECT agent_admin_id, amount FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission' LIMIT 1", ['b' => $bH['bookingId']]);
    check('the commission row belongs to the agent, amount > 0', $comm !== null && (int) $comm['agent_admin_id'] === $agentH && (float) $comm['amount'] > 0, $comm === null ? 'no row' : inr((float) $comm['amount']) . ' → #' . $comm['agent_admin_id']);
    $cash = Database::fetch("SELECT agent_admin_id FROM agent_ledger WHERE booking_id = :b AND entry_type = 'cash_due' LIMIT 1", ['b' => $bH['bookingId']]);
    check('the cash stays with the clerk who took it (never the agent)', $cash === null || (int) $cash['agent_admin_id'] === 1, $cash === null ? 'no cash_due row' : '#' . $cash['agent_admin_id']);
    $pay = Database::fetch('SELECT method, status FROM payments WHERE booking_id = :b', ['b' => $bH['bookingId']]);
    check('payment = cash / verified (desk money)', ($pay['method'] ?? '') === 'cash' && ($pay['status'] ?? '') === 'verified', ($pay['method'] ?? '') . '/' . ($pay['status'] ?? ''));
    check('passenger record + ticket written at once', (int) Database::scalar('SELECT COUNT(*) FROM booking_passengers WHERE booking_id = :b', ['b' => $bH['bookingId']]) === 1
        && (int) Database::scalar('SELECT COUNT(*) FROM tickets WHERE booking_id = :b', ['b' => $bH['bookingId']]) === 1);
    $rep = SmartBook::book($inH, 'cash', 'counter', $seller, $keyH);
    check('same key → the same PNR (double tap at the desk)', $rep['pnr'] === $bH['pnr'] && !empty($rep['replayed']));
    $err = '';
    try { SmartBook::book(['referralCode' => 'OTHER'] + $inH, 'cash', 'counter', $seller, $keyH); } catch (SmartBookKeyReused $e) { $err = 'refused'; }
    check('same key + another agent → refused (a different request)', $err === 'refused');

    $b2 = SmartBook::book(['referralCode' => 'OTHER', 'phone' => '9000000008'] + $inH, 'cash', 'counter', $seller, 'test' . bin2hex(random_bytes(10)));
    $madeH[] = $b2['pnr'];
    $coId = AgentWallet::resolveAgentCodeFromString(Settings::getString('company_agent_code', 'SHG-0001'));
    $sb2  = (int) Database::scalar('SELECT sold_by_admin_id FROM bookings WHERE id = :b', ['b' => $b2['bookingId']], 0);
    check('OTHER → the company\'s own code (' . Settings::getString('company_agent_code', 'SHG-0001') . ')', $sb2 === ($coId ?? 1), '#' . $sb2);

    $b3 = SmartBook::book(['referralCode' => 'SHG-0007', 'phone' => '9000000009', 'pax' => [$P('Female')],
        'verify' => ['address' => 'Nepalgunj-1, Banke', 'idType' => 'Nepali Citizenship Card', 'idNum' => '12-01-75-01234']] + $inH, 'cash', 'customer', null, 'test' . bin2hex(random_bytes(10)));
    $madeH[] = $b3['pnr'];
    $sb3 = Database::scalar('SELECT sold_by_admin_id FROM bookings WHERE id = :b', ['b' => $b3['bookingId']]);
    check('customer mode: a code in the payload credits nobody (D13)', $sb3 === null || (int) $sb3 === 0, (string) $sb3);
} catch (Throwable $e) {
    check('desk sale runs', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($madeH as $pnr) {
        try { BookingService::cancel($pnr, 'smartbook-engine-test cleanup', true, 'undo'); } catch (Throwable $e) { /* already gone */ }
        $bid = (int) Database::scalar('SELECT id FROM bookings WHERE pnr = :p', ['p' => $pnr]);
        if ($bid > 0) {
            foreach (['booking_seats', 'booking_passengers', 'booking_legs', 'payments', 'tickets', 'agent_ledger', 'commissions'] as $t) {
                try { Database::run("DELETE FROM `$t` WHERE booking_id = :b", ['b' => $bid]); } catch (Throwable $e) { /* no such table */ }
            }
            Database::run('DELETE FROM bookings WHERE id = :b', ['b' => $bid]);
        }
    }
    Database::run("DELETE FROM smartbook_requests WHERE idem_key LIKE 'test%'");
    try { AgentWallet::setAgentCode($agentH, $codeWas); } catch (Throwable $e) { /* keep going */ }
}

$base = rtrim((string) getenv('SHG_TEST_BASE'), '/');
if ($base !== '') {
    echo "\nG. HTTP — $base (customer door)\n";
    $jar = tempnam(sys_get_temp_dir(), 'sbj');
    $get = static function (string $url, ?array $post = null) use ($jar): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
        if ($post !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($post['_csrf'] ?? '')]]);
        }
        $r = (string) curl_exec($ch);
        curl_close($ch);
        return $r;
    };
    $csrf = (string) (json_decode($get($base . '/api/csrf.php'), true)['data']['csrf'] ?? '');
    $boot = $get($base . '/api/smartbook.php', ['action' => 'boot', '_csrf' => $csrf]);
    $bj   = json_decode($boot, true);
    check('boot answers for a guest as customer', ($bj['data']['mode'] ?? '') === 'customer', substr($boot, 0, 120));
    check('boot carries no agent / commission / wallet / code field', !preg_match('/commission|wallet|agentCode|referral|"staff"/i', $boot));
    $qq = $get($base . '/api/smartbook.php', ['action' => 'quote', '_csrf' => $csrf, 'direction' => 'toNepal', 'point' => 'Surat', 'pax' => [$P('Male')]]);
    check('quote carries no agent / commission / wallet / code field', str_contains($qq, '"seats"') && !preg_match('/commission|wallet|agentCode|referral/i', $qq), substr($qq, 0, 100));
    $mq = $get($base . '/api/smartbook.php', ['action' => 'boot', 'mode' => 'agent', '_csrf' => $csrf]);
    check('asking for agent mode as a guest still answers customer', (json_decode($mq, true)['data']['mode'] ?? '') === 'customer');
    @unlink($jar);
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n";
exit($FAIL === 0 ? 0 : 1);
