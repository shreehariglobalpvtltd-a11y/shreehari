<?php
/**
 * 19 Nov 2026 (AI-3 + NP-2) — ChalaniPng::departureMoney() is THE chalani
 * money rule, lifted out of admin/manifest.php. This test proves parity:
 *
 *   1. On a seeded far-future departure (synthetic ZZTEST u10 bookings: pay at
 *      boarding due, cash collected, UPI verified, online pending, a cash sale
 *      with desk extras and a discount, an NPJ (NPR) desk sale with a frozen
 *      fx_total) departureMoney()'s totals equal a REFERENCE copy of the old
 *      inline manifest algorithm (kept verbatim below), row by row.
 *   2. Over HTTP the manifest's chalani sheet (format=chalani) prints those
 *      same totals, and the CSV carries the same per-row fares.
 *   3. NP-2: ?desk=NPJ lists only the NPJ sale and prints रू at the frozen rate
 *      with the ₹ beside it; deskMoney() / rateFor(); a bad desk is ignored;
 *      the PNG fingerprint changes with the currency.
 *   4. Before/after: U10_SNAP=<file> writes the CSV + chalani summary block,
 *      U10_SNAP_CMP=<file> compares them to an earlier snapshot (taken from the
 *      pre-refactor manifest) - identical bytes = identical numbers.
 *
 *   SHG_TEST_BASE=http://localhost:8963 php -c <ini> tests/u10-chalani-money-helper-test.php
 *   U10_KEEP=1 leaves the seed (next run removes it).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/opshub.php';
require_once INCLUDE_PATH . '/chalanipng.php';

if (!function_exists('curl_init')) { echo "curl extension not loaded\n"; exit(1); }
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8963'), '/'));
const PHONES  = ['A' => '9000010101', 'B' => '9000010102', 'C' => '9000010103', 'D' => '9000010104', 'E' => '9000010105', 'F' => '9000010106'];
const BOSS    = 'u10_cm_boss';
const BOSS_PW = 'U10ChalaniMoney77';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$JAR = sys_get_temp_dir() . '/shg_u10_cm_' . getmypid() . '.txt';
@unlink($JAR);
function http(string $url, array $opt = []): array {
    global $JAR;
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR, CURLOPT_TIMEOUT => 120]);
    if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'headers' => substr($raw, 0, $hlen)];
}
function csrfFrom(string $h): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? $m[1] : '';
}
function cleanup(): void {
    foreach (PHONES as $p) {
        foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => $p]) as $r) {
            foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
            if (defined('INVOICE_PATH')) { @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf'); }
            foreach (['booking_collections', 'agent_ledger', 'pay_events'] as $t) {
                try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            }
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
    }
    Database::delete('admins', 'username = :a', ['a' => BOSS]);
}

/**
 * REFERENCE: the money block exactly as admin/manifest.php had it inline before
 * 19 Nov 2026 (lines 195-405 + 830-862 at 847e040d), on the same rows.
 */
function referenceTotals(array $rows): array {
    $mfTicks = OpsHub::collections(array_map(static fn(array $r): int => (int) ($r['booking_id'] ?? 0), $rows));
    $mfState = [];
    foreach ($rows as $r) { $bid = (int) ($r['booking_id'] ?? 0); if (!isset($mfState[$bid])) { $mfState[$bid] = OpsHub::payState($r, $mfTicks[$bid] ?? null); } }
    $paxTotalPerBooking = [];
    $bids = array_values(array_filter(array_unique(array_map(static fn(array $r): int => (int) ($r['booking_id'] ?? 0), $rows)), static fn(int $i): bool => $i > 0));
    if ($bids !== []) {
        foreach (Database::fetchAll('SELECT booking_id, COUNT(*) c FROM booking_passengers WHERE booking_id IN (' . implode(',', $bids) . ') GROUP BY booking_id') as $pr) {
            $paxTotalPerBooking[(int) $pr['booking_id']] = (int) $pr['c'];
        }
    }
    $legsPerBooking = [];
    if ($bids !== []) {
        foreach (Database::fetchAll('SELECT booking_id, COUNT(*) c FROM booking_legs WHERE booking_id IN (' . implode(',', $bids) . ') GROUP BY booking_id') as $lr) {
            $legsPerBooking[(int) $lr['booking_id']] = max(1, (int) $lr['c']);
        }
    }
    $extrasOf = static fn(array $r): float => round(max(0.0, (float) ($r['luggage_fee'] ?? 0)) + max(0.0, (float) ($r['infant_fee'] ?? 0)), 2);
    $xBook = []; $xFirst = [];
    foreach ($rows as $r) {
        $bid = (int) ($r['booking_id'] ?? 0);
        if (!isset($xFirst[$bid])) { $xFirst[$bid] = (int) ($r['pax_id'] ?? 0); }
        $lug = max(0, (int) ($r['luggage_pieces'] ?? 0)); $inf = max(0, (int) ($r['infant_count'] ?? 0));
        if ($lug === 0 && $inf === 0 && $extrasOf($r) <= 0) { continue; }
        if (!isset($xBook[$bid])) {
            $xBook[$bid] = ['inf' => $inf, 'amt' => round($extrasOf($r) / ($legsPerBooking[$bid] ?? 1), 2),
                'state' => $extrasOf($r) > 0 ? ($mfState[$bid] ?? 'due') : 'free'];
        }
    }
    $xInfants = array_sum(array_map(static fn(array $x): int => $x['inf'], $xBook));
    $extraOnRow = static function (array $r) use ($xBook, $xFirst): float {
        $bid = (int) ($r['booking_id'] ?? 0);
        return (isset($xBook[$bid]) && ($xFirst[$bid] ?? -1) === (int) ($r['pax_id'] ?? 0)) ? (float) $xBook[$bid]['amt'] : 0.0;
    };
    $farePer = static function (array $r) use ($paxTotalPerBooking, $extrasOf): float {
        $n = max(1, (int) ($paxTotalPerBooking[(int) ($r['booking_id'] ?? 0)] ?? 1));
        return round(max(0.0, (float) $r['total_amount'] - $extrasOf($r)) / $n);
    };
    $money = static function (array $r) use ($farePer, $extraOnRow, $mfState, $mfTicks): array {
        $fare = (float) $farePer($r); $extra = (float) $extraOnRow($r); $bid = (int) ($r['booking_id'] ?? 0);
        $way = (($r['pay_status'] ?? '') === 'verified') ? ChalaniPng::paidWay($r, $mfTicks[$bid] ?? null) : '';
        $xIn = (($mfState[$bid] ?? 'due') !== 'due') ? $extra : 0.0;
        return ['ticket' => $fare, 'extra' => $extra, 'cash' => $way === 'cash' ? $fare + $xIn : 0.0, 'online' => $way === 'online' ? $fare + $xIn : 0.0];
    };
    $pmBook = []; $pmDue = []; $pmPax = [];
    foreach ($rows as $r) {
        $bid = (int) ($r['booking_id'] ?? 0);
        if (!isset($pmBook[$bid])) { $pmBook[$bid] = ChalaniPng::payMode($r, $mfState[$bid] ?? 'due', $mfTicks[$bid] ?? null); $pmDue[$bid] = 0.0; $pmPax[$bid] = 0; }
        $pmPax[$bid]++;
        if ($pmBook[$bid] === 'boarding') { $pmDue[$bid] += $farePer($r) + $extraOnRow($r); }
    }
    $boardDue = 0.0; $boardDueN = 0;
    foreach ($pmBook as $bid => $pmMode) { if ($pmMode === 'boarding') { $boardDue += $pmDue[$bid]; $boardDueN += $pmPax[$bid]; } }
    $tTicket = 0.0; $tCash = 0.0; $tOnline = 0.0; $tDisc = 0.0; $tExtra = 0.0; $seenB = []; $perRow = [];
    foreach ($rows as $r) {
        $m = $money($r); $perRow[] = $m;
        $tTicket += $m['ticket']; $tCash += $m['cash']; $tOnline += $m['online']; $tExtra += $m['extra'];
        $bid = (int) ($r['booking_id'] ?? 0);
        if ($bid > 0 && !isset($seenB[$bid])) {
            $seenB[$bid] = true;
            $baseAmt = (float) ($r['base_total'] ?? 0);
            if ($baseAmt > 0) { $tDisc += max(0.0, $baseAmt - max(0.0, (float) ($r['total_amount'] ?? 0) - $extrasOf($r))); }
        }
    }
    $xPaid = 0.0; $xDue = 0.0;
    foreach ($xBook as $xb) { if ($xb['state'] === 'due') { $xDue += $xb['amt']; } else { $xPaid += $xb['amt']; } }
    return ['totals' => ['passengers' => count($rows), 'ticket' => $tTicket, 'cash' => $tCash, 'online' => $tOnline, 'discount' => $tDisc,
        'net' => $tCash + $tOnline, 'extras' => $tExtra, 'extrasPaid' => $xPaid, 'extrasDue' => $xDue, 'infants' => $xInfants,
        'boardDue' => round($boardDue, 2), 'boardDueN' => $boardDueN], 'perRow' => $perRow];
}
/** The chalani sheet's payment-summary block, numbers only (for the before/after snapshot). */
function sumBlock(string $html): string {
    $a = strpos($html, 'भुक्तानी सारांश'); $b = strpos($html, 'प्रमाणीकरण');
    return ($a !== false && $b !== false && $b > $a) ? (string) preg_replace('/\s+/', ' ', strip_tags(substr($html, $a, $b - $a))) : '';
}
function sheetVal(string $html, string $label): ?float {
    if (preg_match('/<span[^>]*>' . preg_quote($label, '/') . '<\/span><b[^>]*>(?:₹|रू) ?([0-9,\.]+)/u', $html, $m) !== 1) { return null; }
    return (float) str_replace(',', '', $m[1]);
}

$STATE = sys_get_temp_dir() . '/shg_u10_chalani_money_keep.json';
if (is_file($STATE)) {
    $st = (array) json_decode((string) file_get_contents($STATE), true);
    cleanup();
    if (!empty($st['made']) && (int) ($st['sched'] ?? 0) > 0 && (int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE schedule_id = :s', ['s' => (int) $st['sched']], 0) === 0) {
        Database::delete('schedules', 'id = :i', ['i' => (int) $st['sched']]);
    }
    @unlink($STATE);
    echo "  (an earlier U10_KEEP seed was removed)\n";
}
$KEEP = getenv('U10_KEEP') === '1';
$keepSet = [];
foreach (['whatsapp_notify_customer', 'whatsapp_notify_admin', 'allow_cod', 'email_enabled'] as $k) {
    $keepSet[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
}

echo "\n=== u10 · ChalaniPng::departureMoney parity + desk money (AI-3, NP-2) ===\n\n";
cleanup();
$TD = '';
for ($i = 60; $i >= 40; $i--) {
    $d = date('Y-m-d', strtotime(todayISO() . " +$i days"));
    if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE travel_date = :d', ['d' => $d], 0) === 0) { $TD = $d; break; }
}
$sched = null; $schedMade = false;
try {
    if ($TD === '') { throw new RuntimeException('no free date 40-60 days out'); }
    foreach (['whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'allow_cod' => '1', 'email_enabled' => '0'] as $k => $v) {
        Settings::set($k, $v, 'bool', 'notify', false);
    }
    Settings::flush();
    $route   = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
    $routeId = (int) $route['id'];
    $schedMade = Database::fetch('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 1', ['r' => $routeId, 'd' => $TD]) === null;
    $sched   = Seats::schedule($routeId, $TD);
    Database::update('schedules', ['is_blocked' => 0, 'status' => 'scheduled'], 'id = :i', ['i' => (int) $sched['id']]);
    $sid = (int) $sched['id'];

    $bossId = (int) Database::insert('admins', ['username' => BOSS, 'password_hash' => password_hash(BOSS_PW, PASSWORD_DEFAULT),
        'full_name' => 'ZZTEST u10 Boss', 'role' => 'superadmin', 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
    require_once INCLUDE_PATH . '/quickunlock.php';
    QuickUnlock::set($bossId, 'pin', '583942');
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $bossId, 'username' => BOSS, 'role' => 'superadmin', 'permissions' => [], 'last_seen' => time(), 'logged_in_at' => time()];

    $sell = static function (string $k, array $seats, string $method) use ($routeId, $TD, $bossId): array {
        $pax = [];
        foreach ($seats as $n => $s) { $pax[] = ['name' => 'ZZTEST u10 ' . $k . ($n > 0 ? ' ' . ($n + 1) : ''), 'age' => 30 + $n, 'gender' => 'Male']; }
        $res = BookingService::create(['routeId' => $routeId, 'travelDate' => $TD, 'seats' => $seats, 'passengers' => $pax,
            'contact' => ['phone' => PHONES[$k]], 'bookingMode' => 'sharing', 'paymentMethod' => $method, 'isCod' => false, 'boarding' => ''],
            ['adminId' => $bossId, 'source' => 'admin', 'paymentMethod' => $method]);
        $row = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $res['pnr']]);
        Database::run('DELETE FROM agent_ledger WHERE booking_id = :b', ['b' => (int) $row['id']]);
        return $row;
    };
    $asCod = static function (array $b): void {
        Database::update('bookings', ['is_cod' => 1, 'source' => 'web', 'sold_by_admin_id' => null, 'status' => 'confirmed'], 'id = :i', ['i' => (int) $b['id']]);
        Database::update('payments', ['status' => 'cod_pending', 'method' => 'cod', 'mode' => 'offline', 'verified_by' => null, 'verified_at' => null], 'booking_id = :b', ['b' => (int) $b['id']]);
    };
    $A = $sell('A', ['L20', 'L22'], 'cash'); $asCod($A);
    $B = $sell('B', ['L24'], 'cash'); $asCod($B);
    BookingService::settleCod((int) $B['id'], $bossId, 'u10 test: cash collected');
    $C = $sell('C', ['L26'], 'upi');
    Database::update('bookings', ['source' => 'web', 'sold_by_admin_id' => null], 'id = :i', ['i' => (int) $C['id']]);
    Database::update('payments', ['method' => 'upi', 'status' => 'verified'], 'booking_id = :b', ['b' => (int) $C['id']]);
    $D = $sell('D', ['L28'], 'upi');
    Database::update('bookings', ['source' => 'web', 'sold_by_admin_id' => null, 'status' => 'pending'], 'id = :i', ['i' => (int) $D['id']]);
    Database::update('payments', ['method' => 'upi', 'status' => 'pending', 'verified_by' => null, 'verified_at' => null], 'booking_id = :b', ['b' => (int) $D['id']]);
    /* E: a cash sale at MSA with desk extras (1 bag ₹200) and a ₹300 discount */
    $E = $sell('E', ['L30', 'L32'], 'cash');
    $eTot = (float) $E['total_amount'] + 200;
    Database::update('bookings', ['luggage_pieces' => 1, 'luggage_fee' => 200, 'total_amount' => $eTot, 'base_total' => (float) $E['total_amount'] + 300,
        'counter_code' => 'MSA'], 'id = :i', ['i' => (int) $E['id']]);
    Database::update('payments', ['amount' => $eTot, 'method' => 'cash', 'status' => 'verified'], 'booking_id = :b', ['b' => (int) $E['id']]);
    /* F: an NPJ (Nepal desk, NPR) cash sale with the NPR frozen at 1.6 */
    $F = $sell('F', ['L34'], 'cash');
    $fTot = (float) $F['total_amount'];
    $fNpr = round($fTot * 1.6);
    Database::update('bookings', ['counter_code' => 'NPJ', 'fx_currency' => 'NPR', 'fx_rate' => 1.6, 'fx_total' => $fNpr, 'entity' => 'NP'], 'id = :i', ['i' => (int) $F['id']]);
    Database::update('payments', ['method' => 'cash', 'status' => 'verified', 'local_currency' => 'NPR', 'local_amount' => $fNpr, 'fx_rate' => 1.6], 'booking_id = :b', ['b' => (int) $F['id']]);
    echo "  (date $TD · sid $sid · F ₹$fTot = रू $fNpr)\n";

    /* ---- 1. helper == reference, every order ---- */
    foreach (['group', 'stop', 'seat'] as $ord) {
        $dm  = ChalaniPng::departureMoney($sid, null, $ord);
        $ref = referenceTotals($dm['rows']);
        $diff = [];
        foreach ($ref['totals'] as $k => $v) { if (abs((float) $v - (float) ($dm['totals'][$k] ?? -999)) > 0.001) { $diff[] = "$k " . $dm['totals'][$k] . " vs $v"; } }
        check("order=$ord: totals equal the old inline manifest rule", $diff === [], implode('; ', $diff));
        $rowsSame = true;
        foreach ($dm['rows'] as $i => $r) { if ($dm['money']($r) !== $ref['perRow'][$i]) { $rowsSame = false; break; } }
        check("order=$ord: per-row ticket / extra / cash / online equal", $rowsSame);
    }
    $dm = ChalaniPng::departureMoney($sid);
    $t  = $dm['totals'];
    check('8 passengers on the seed (A2 B C D E2 F)', $t['passengers'] === 8, (string) $t['passengers']);
    check('pay at boarding: A 2 pax due', $t['boardDueN'] === 2 && abs($t['boardDue'] - 2 * round((float) $A['total_amount'] / 2)) < 0.01, json_encode([$t['boardDueN'], $t['boardDue']]));
    check('extras: ₹200 paid, discount ₹300', abs($t['extras'] - 200) < 0.01 && abs($t['extrasPaid'] - 200) < 0.01 && abs($t['discount'] - 300) < 0.01, json_encode([$t['extras'], $t['extrasPaid'], $t['discount']]));
    check('online = C only, net = cash + online', abs($t['online'] - round((float) $C['total_amount'])) < 0.01 && abs($t['net'] - ($t['cash'] + $t['online'])) < 0.001);
    check('no desk → no local money', $dm['cur'] === null && $dm['local'] === null);
    check('departureMoney(0) is an empty structure', ChalaniPng::departureMoney(0)['totals']['passengers'] === 0);

    /* ---- 3. NP-2 desk money ---- */
    check('deskCode: NPJ valid, garbage ignored', ChalaniPng::deskCode('npj') === 'NPJ' && ChalaniPng::deskCode('ZZZ') === '' && ChalaniPng::deskCode("x' OR 1") === '');
    check('deskMoney: frozen NPR beats today\'s rate', ChalaniPng::deskMoney(2800.0, 'NPJ', ['total_amount' => 2800, 'fx_currency' => 'NPR', 'fx_total' => 4480]) === 'रू ' . indianNumber(4480) . ' (' . CounterDesk::format(2800.0, 'INR') . ')',
        ChalaniPng::deskMoney(2800.0, 'NPJ', ['total_amount' => 2800, 'fx_currency' => 'NPR', 'fx_total' => 4480]));
    check('deskMoney: latin "NPR 4,480 (Rs 2,800)"', ChalaniPng::deskMoney(2800.0, 'NPJ', ['total_amount' => 2800, 'fx_currency' => 'NPR', 'fx_total' => 4480], true) === 'NPR 4,480 (Rs 2,800)');
    check('deskMoney: rupee desk prints ₹ only', ChalaniPng::deskMoney(2800.0, 'MSA') === CounterDesk::format(2800.0, 'INR'));
    $rate = CounterDesk::rate('NPJ');
    check('deskMoney: nothing frozen → × the desk rate', ChalaniPng::deskMoney(1000.0, 'NPJ', [], false, false) === CounterDesk::format((float) round(1000 * $rate), 'NPR'));
    $dn = ChalaniPng::departureMoney($sid, null, 'group', 'NPJ');
    check('desk=NPJ lists only the NPJ sale', count($dn['rows']) === 1 && (string) $dn['rows'][0]['pnr'] === (string) $F['pnr']);
    check('desk=NPJ: local totals at the frozen rate', $dn['cur'] !== null && abs((float) $dn['local']['cash'] - $fNpr) < 1.01 && abs((float) $dn['totals']['cash'] - round($fTot)) < 0.01,
        json_encode([$dn['local']['cash'] ?? null, $fNpr]));
    $dmS = ChalaniPng::departureMoney($sid, null, 'group', 'MSA');
    check('desk=MSA lists only E, money stays INR', count($dmS['rows']) === 2 && $dmS['cur'] === null);
    $trip = ['schedule_id' => $sid];
    check('PNG fingerprint differs between INR and NPR', ChalaniPng::fingerprint($trip, $dn['rows'], $TD, $dn['totals'], [])
        !== ChalaniPng::fingerprint($trip, $dn['rows'], $TD, $dn['local'] + ['inr' => $dn['totals']], ['cur' => $dn['cur']]));

    /* review fix: bookings.fx_rate wins over fx_total / total_amount - a seat
       cancelled later halves total_amount while fx_total stays frozen */
    $half = ['total_amount' => 2800, 'fx_currency' => 'NPR', 'fx_rate' => 1.6, 'fx_total' => 8960];
    check('rateFor: fx_rate first (partial cancel keeps 1.6, not 3.2)', abs(ChalaniPng::rateFor($half, 'NPJ') - 1.6) < 0.0001, (string) ChalaniPng::rateFor($half, 'NPJ'));
    check('deskMoney after a partial cancel = Ticket::nprOf', ChalaniPng::deskMoney(2800.0, 'NPJ', $half) === CounterDesk::format(4480.0, 'NPR') . ' (' . CounterDesk::format(2800.0, 'INR') . ')',
        ChalaniPng::deskMoney(2800.0, 'NPJ', $half));
    check('rateFor: no fx_rate → fx_total / total_amount', abs(ChalaniPng::rateFor(['total_amount' => 2800, 'fx_currency' => 'NPR', 'fx_total' => 4480], 'NPJ') - 1.6) < 0.0001);
    check('rateFor: an INR-quoted sale at NPJ → the desk rate', abs(ChalaniPng::rateFor(['total_amount' => 2800, 'fx_currency' => 'INR', 'fx_rate' => 1.0], 'NPJ') - $rate) < 0.0001);
    check('departureMoney rows carry fx_rate', array_key_exists('fx_rate', $dn['rows'][0] ?? []));

    /* spec gap NP-2: no ?desk=, but every row is an NPJ sale (agent copy) → NPR */
    $eSeller = Database::scalar('SELECT sold_by_admin_id FROM bookings WHERE id = :i', ['i' => (int) $E['id']]);
    Database::update('bookings', ['sold_by_admin_id' => null], 'id = :i', ['i' => (int) $E['id']]);
    Database::update('bookings', ['sold_by_admin_id' => $bossId], 'id = :i', ['i' => (int) $F['id']]);
    $dAuto = ChalaniPng::departureMoney($sid, $bossId);
    check('only-NPJ result with no desk → NPR copy (deskAuto)', count($dAuto['rows']) === 1 && $dAuto['cur'] !== null && $dAuto['deskAuto'] === true
        && abs((float) $dAuto['local']['cash'] - $fNpr) < 1.01, json_encode([count($dAuto['rows']), $dAuto['deskAuto'], $dAuto['local']['cash'] ?? null]));
    $hadAuto = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => 'chalani_auto_desk_currency']);
    Settings::set('chalani_auto_desk_currency', '0', 'bool', 'whatsapp', false); Settings::flush();
    check('chalani_auto_desk_currency = 0 → the only-NPJ copy stays ₹', ChalaniPng::departureMoney($sid, $bossId)['cur'] === null);
    if ($hadAuto === null) { Database::delete('settings', 'skey = :k', ['k' => 'chalani_auto_desk_currency']); }
    else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $hadAuto['svalue'], 'k' => 'chalani_auto_desk_currency']); }
    Settings::flush();
    Database::update('bookings', ['sold_by_admin_id' => $bossId], 'id = :i', ['i' => (int) $E['id']]);
    check('mixed-desk agent copy (MSA + NPJ) with no desk stays ₹', ChalaniPng::departureMoney($sid, $bossId)['cur'] === null);
    check('whole bus (mixed desks) with no desk stays ₹', ChalaniPng::departureMoney($sid)['cur'] === null);
    Database::update('bookings', ['sold_by_admin_id' => $eSeller], 'id = :i', ['i' => (int) $E['id']]);
    Database::update('bookings', ['sold_by_admin_id' => $F['sold_by_admin_id']], 'id = :i', ['i' => (int) $F['id']]);

    /* ---- 2. over HTTP ---- */
    try { Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%' OR bucket IN ('code_login_enter','quick_unlock','code_login_try')"); } catch (Throwable $e) {}
    $login = http('/admin/login.php?classic=1&other=1');
    $r = http('/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($login['body']), 'classic' => '1', 'username' => BOSS, 'password' => BOSS_PW]]);
    if ($r['code'] !== 302) {
        /* the one door now asks "who" first, then the password (19 Nov 2026, LG-1) */
        $w = http('/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($r['body'] ?: $login['body']), 'classic' => '1', 'mode' => 'who', 'who' => BOSS, 'cc' => '91']]);
        $r = http('/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($w['body']), 'classic' => '1', 'mode' => 'pw', 'password' => BOSS_PW]]);
    }
    check('superadmin signs in', $r['code'] === 302 && !str_contains($r['headers'], 'login.php'), 'HTTP ' . $r['code']);
    $q = '/admin/manifest.php?date=' . $TD . '&sid=' . $sid;
    $sheet = http($q . '&format=chalani');
    check('chalani sheet renders', $sheet['code'] === 200, 'HTTP ' . $sheet['code']);
    foreach (['जम्मा टिकट रकम' => 'ticket', 'नगद संकलन' => 'cash', 'अनलाइन / UPI प्राप्ति' => 'online', 'छुट / सहुलियत' => 'discount', 'खुद जम्मा संकलन' => 'net'] as $lab => $k) {
        $v = sheetVal($sheet['body'], $lab);
        check("sheet '$lab' = helper $k (" . $t[$k] . ')', $v !== null && abs($v - round($t[$k])) < 0.6, (string) $v);
    }
    $csv = http($q . '&format=csv');
    check('CSV downloads', $csv['code'] === 200 && str_contains($csv['body'], 'To collect on boarding'));
    /* the seed is re-made each run: PNRs, ticket numbers and booking times are
       swapped for stable tokens so two runs (before / after) compare money only */
    $norm = [];
    foreach (['A' => $A, 'B' => $B, 'C' => $C, 'D' => $D, 'E' => $E, 'F' => $F] as $k => $bk) {
        $norm[(string) $bk['pnr']] = 'PNR-' . $k;
        $tn = (string) Database::scalar('SELECT ticket_number FROM tickets WHERE booking_id = :b', ['b' => (int) $bk['id']], '');
        if ($tn !== '') { $norm[$tn] = 'TKT-' . $k; }
    }
    $snap = "CSV\n" . strtr((string) preg_replace('/^\xEF\xBB\xBF/', '', $csv['body']), $norm) . "\nSUM\n" . sumBlock($sheet['body']) . "\n";
    $snap = (string) preg_replace('/\b\d{1,2} [A-Z][a-z]{2} · \d{2}:\d{2}\b|\b\d{2}:\d{2}\b/', 'hh:mm', $snap);
    if (($sp = (string) getenv('U10_SNAP')) !== '') { file_put_contents($sp, $snap); echo "  snapshot written: $sp\n"; }
    if (($cp = (string) getenv('U10_SNAP_CMP')) !== '' && is_file($cp)) {
        $before = (string) file_get_contents($cp);
        check('before/after: CSV + chalani summary byte-identical to the pre-refactor manifest', $before === $snap,
            $before === $snap ? '' : 'diff: ' . substr(json_encode(array_values(array_diff(explode("\n", $snap), explode("\n", $before)))), 0, 400));
    }
    if (is_file(dirname(__DIR__) . '/admin/manifest.php') && str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/manifest.php'), 'departureMoney')) {
        $np = http($q . '&format=chalani&desk=NPJ');
        check('desk=NPJ sheet: रू and only the NPJ passenger', $np['code'] === 200 && str_contains($np['body'], 'रू')
            && str_contains($np['body'], 'ZZTEST u10 F') && !str_contains($np['body'], 'ZZTEST u10 A'));
        $npNet = sheetVal($np['body'], 'खुद जम्मा संकलन');
        check('desk=NPJ sheet net = frozen NPR ' . $fNpr, $npNet !== null && abs($npNet - $fNpr) < 1.01, (string) $npNet);
        $bad = http($q . '&format=chalani&desk=' . rawurlencode('ZZ<script>'));
        check('a bad desk is ignored (whole bus, ₹)', $bad['code'] === 200 && str_contains($bad['body'], 'ZZTEST u10 A') && !str_contains($bad['body'], '<script>'));
        $png = http($q . '&format=chalanipng&page=1&desk=NPJ');
        check('desk=NPJ PNG page draws', $png['code'] === 200 && str_starts_with($png['body'], "\x89PNG"), 'HTTP ' . $png['code']);
        $pdf = http($q . '&format=chalanipdf&desk=NPJ');
        check('desk=NPJ PDF builds', str_starts_with($pdf['body'], '%PDF'));
    }
} catch (Throwable $e) {
    $FAIL++;
    echo "  ERROR  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}
if ($KEEP && $sched !== null) {
    file_put_contents($STATE, (string) json_encode(['sched' => (int) $sched['id'], 'made' => $schedMade]));
    echo "\n  KEPT: " . BASE . '/admin/manifest.php?date=' . $TD . '&sid=' . (int) $sched['id'] . "\n";
} else {
    cleanup();
    if ($sched !== null) {
        $dir = rtrim(UPLOAD_PATH, '/\\') . '/chalani/' . $TD;
        foreach (glob($dir . '/*-S' . (int) $sched['id'] . '-*.png') ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
        if ($schedMade && (int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE schedule_id = :s', ['s' => (int) $sched['id']], 0) === 0) {
            Database::delete('schedules', 'id = :i', ['i' => (int) $sched['id']]);
        }
    }
}
foreach ($keepSet as $k => $row) {
    if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
    else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
}
Settings::flush();
@unlink($JAR);
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
